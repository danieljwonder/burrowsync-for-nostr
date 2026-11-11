<?php
/**
 * Publish record: the signed Nostr event for each post, the outcome on
 * every relay, and a short log of publish attempts.
 *
 * Why: success used to mean "any one relay said OK", after which the
 * signed event was thrown away. If that one relay later disappeared the
 * post could never be rebroadcast without re-signing. Now the exact signed
 * event is kept for as long as the post exists, so it can be downloaded or
 * republished verbatim.
 *
 * Storage (all post meta, JSON strings):
 *   _nostr_signed_event   the current signed event, exactly as published
 *   _nostr_relay_status   {"event_id": "...", "relays": {url: {...}}}
 *   _nostr_publish_log    append-only list of attempts, newest last, capped
 *
 * Relay states: accepted | pending | rejected | unreachable.
 *
 * Retries: once at least one relay has accepted the event, every other
 * relay in the site's list that is pending or unreachable gets the same
 * stored event again on a backoff (5 min, 15 min, 1 h, 6 h, then daily)
 * until it accepts, definitively rejects, or 7 days have passed since its
 * first attempt (then it is marked expired). Nostr_Relay_Retry runs it.
 * _nostr_relay_next_retry holds the earliest due time per post so the
 * sweep can find due posts with one indexed meta query.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_Publish_Record {

    const META_EVENT  = '_nostr_signed_event';
    const META_RELAYS = '_nostr_relay_status';
    const META_LOG    = '_nostr_publish_log';

    /** Pre-0.9.x key used by the bunker queue while relays were failing. */
    const LEGACY_META_EVENT = '_nostr_nip46_signed_event';

    /** Earliest relay retry due for the post (unix time), if any. */
    const META_NEXT_RETRY = '_nostr_relay_next_retry';

    const LOG_CAP     = 20;
    const MESSAGE_MAX = 200;

    /** Delay after the 1st, 2nd, 3rd, 4th failed attempt on a relay. */
    const RETRY_SCHEDULE = array(300, 900, 3600, 21600);
    /** Delay after every later failed attempt. */
    const RETRY_EVERY = 86400;
    /** Stop retrying a relay this long after its first attempt. */
    const RETRY_GIVE_UP = 604800; // 7 days

    /**
     * OK=false reasons that are temporary (NIP-01 prefixes). Everything
     * else a relay refuses with ("restricted:", "blocked:", "pow:",
     * "invalid:", "auth-required:", "Pay on ...") is final for this event.
     */
    const TRANSIENT_PREFIXES = array('rate-limited', 'error', 'timeout', 'try again');

    const STATE_ACCEPTED    = 'accepted';
    const STATE_PENDING     = 'pending';
    const STATE_REJECTED    = 'rejected';
    const STATE_UNREACHABLE = 'unreachable';

    /* ---------------------------------------------------------------------
     * Signed event
     * ------------------------------------------------------------------ */

    /**
     * Basic shape check plus a recomputed id. Signature verification is
     * optional because it costs a few ms of pure-PHP curve math; callers
     * that receive events from outside (the browser) should ask for it.
     *
     * @param mixed $event
     * @param bool  $verify_sig
     * @return bool
     */
    public static function is_valid_event($event, $verify_sig = false) {
        if (!is_array($event)) {
            return false;
        }
        foreach (array('id', 'pubkey', 'created_at', 'kind', 'tags', 'content', 'sig') as $field) {
            if (!array_key_exists($field, $event)) {
                return false;
            }
        }
        if (!is_string($event['id']) || !preg_match('/^[0-9a-f]{64}$/', $event['id'])) {
            return false;
        }
        if (!is_string($event['pubkey']) || !preg_match('/^[0-9a-f]{64}$/', $event['pubkey'])) {
            return false;
        }
        if (!is_string($event['sig']) || !preg_match('/^[0-9a-f]{128}$/', $event['sig'])) {
            return false;
        }
        if (!is_array($event['tags']) || !is_string($event['content'])) {
            return false;
        }
        if (Nostr_NIP46_Crypto::event_id($event) !== $event['id']) {
            return false;
        }
        if ($verify_sig && !Nostr_NIP46_Crypto::schnorr_verify($event['id'], $event['sig'], $event['pubkey'])) {
            return false;
        }
        return true;
    }

    /**
     * Keep only the NIP-01 fields, in canonical order.
     */
    private static function canonical_event(array $event) {
        return array(
            'id'         => $event['id'],
            'pubkey'     => $event['pubkey'],
            'created_at' => (int) $event['created_at'],
            'kind'       => (int) $event['kind'],
            'tags'       => $event['tags'],
            'content'    => $event['content'],
            'sig'        => $event['sig'],
        );
    }

    /**
     * Store a signed event as the post's current event. If a different
     * event was current (the post was edited and re-signed, or a backfill
     * re-signed with the original date), the old id goes into the log and
     * relay results start over for the new event.
     *
     * @param int    $post_id
     * @param array  $event   signed event
     * @param string $source  bunker | backfill | nip07 | retry
     * @return bool false if the event is malformed (nothing stored)
     */
    public static function store_event($post_id, $event, $source = 'bunker') {
        if (!self::is_valid_event($event)) {
            return false;
        }
        $event = self::canonical_event($event);
        $current = self::get_event($post_id);

        if ($current && $current['id'] === $event['id']) {
            // Already current. Migrate it off the legacy key if needed.
            if (!is_array(self::get_json($post_id, self::META_EVENT))) {
                self::put_json($post_id, self::META_EVENT, $event);
                delete_post_meta($post_id, self::LEGACY_META_EVENT);
            }
            if (!is_array(self::get_json($post_id, self::META_RELAYS))) {
                self::put_json($post_id, self::META_RELAYS, self::fresh_status($event['id']));
            }
            return true;
        }

        if ($current) {
            self::append_log($post_id, array(
                'event_id' => $current['id'],
                'source'   => $source,
                'note'     => 'replaced',
                'ok'       => self::relays_in_state($post_id, self::STATE_ACCEPTED),
                'failed'   => array(),
            ));
        }

        self::put_json($post_id, self::META_EVENT, $event);
        delete_post_meta($post_id, self::LEGACY_META_EVENT);

        self::put_json($post_id, self::META_RELAYS, self::fresh_status($event['id']));
        delete_post_meta($post_id, self::META_NEXT_RETRY);

        return true;
    }

    /**
     * Relay table for a new event: every relay in the list starts out
     * pending until it has been tried.
     */
    private static function fresh_status($event_id) {
        $relays = array();
        foreach (self::configured_relays() as $url) {
            $relays[$url] = self::new_relay_row();
        }
        return array('event_id' => $event_id, 'relays' => $relays);
    }

    /**
     * The post's current signed event, or null. Falls back to the legacy
     * bunker-queue key so in-flight retries survive the upgrade.
     *
     * @return array|null
     */
    public static function get_event($post_id) {
        $event = self::get_json($post_id, self::META_EVENT);
        if (is_array($event) && !empty($event['id'])) {
            return $event;
        }
        $legacy = self::get_json($post_id, self::LEGACY_META_EVENT);
        if (is_array($legacy) && self::is_valid_event($legacy)) {
            return self::canonical_event($legacy);
        }
        return null;
    }

    /**
     * Exact JSON of the current signed event (for download).
     *
     * @return string|null
     */
    public static function get_event_json($post_id) {
        $event = self::get_event($post_id);
        return $event ? wp_json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    }

    /**
     * Drop the current event only if no relay ever accepted it, keeping
     * its id in the log. Used when a backfill must re-sign with the
     * original publish date. Returns true when something was discarded.
     */
    public static function discard_unaccepted($post_id, $note = 'discarded') {
        $current = self::get_event($post_id);
        if (!$current) {
            return false;
        }
        if (!empty(self::relays_in_state($post_id, self::STATE_ACCEPTED))) {
            return false;
        }
        self::append_log($post_id, array(
            'event_id' => $current['id'],
            'source'   => 'system',
            'note'     => $note,
            'ok'       => array(),
            'failed'   => array_keys(self::get_relay_table($post_id)),
        ));
        delete_post_meta($post_id, self::META_EVENT);
        delete_post_meta($post_id, self::LEGACY_META_EVENT);
        delete_post_meta($post_id, self::META_RELAYS);
        delete_post_meta($post_id, self::META_NEXT_RETRY);
        return true;
    }

    /* ---------------------------------------------------------------------
     * Relay results
     * ------------------------------------------------------------------ */

    /**
     * Map one relay result from Nostr_Client::publish_event() to a state.
     *
     * @param array $result
     * @return array{0: string, 1: string} state, message
     */
    public static function classify_result($result) {
        $result  = is_array($result) ? $result : array();
        $message = '';
        if (!empty($result['message'])) {
            $message = (string) $result['message'];
        } elseif (!empty($result['error'])) {
            $message = (string) $result['error'];
        }

        if (!empty($result['success'])) {
            return array(self::STATE_ACCEPTED, $message);
        }

        // The relay answered OK=false for this event.
        if (!empty($result['ok_received'])) {
            // "duplicate:" means the relay already has it.
            if (stripos($message, 'duplicate') === 0) {
                return array(self::STATE_ACCEPTED, $message);
            }
            if (self::is_transient_refusal($message)) {
                return array(self::STATE_UNREACHABLE, $message);
            }
            return array(self::STATE_REJECTED, $message);
        }

        // Connection failure, HTTP error during handshake, timeout, NOTICE.
        return array(self::STATE_UNREACHABLE, $message);
    }

    /**
     * Whether an OK=false reason is worth retrying (rate limits, relay
     * internal errors) rather than a definitive refusal.
     */
    public static function is_transient_refusal($message) {
        $m = strtolower(ltrim((string) $message));
        foreach (self::TRANSIENT_PREFIXES as $prefix) {
            if (strpos($m, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Record the outcome of one publish attempt of $event and append a
     * log entry. Results for a different event id than the current one
     * are ignored for the relay table (they still get logged).
     *
     * @param int    $post_id
     * @param array  $event
     * @param array  $results relay url => result array
     * @param string $source
     * @param int|null $now   attempt time (tests and the retry sweep)
     * @return array{accepted: int, total: int}
     */
    public static function record_results($post_id, $event, $results, $source = 'bunker', $now = null) {
        $now = $now === null ? time() : (int) $now;
        $ok = array();
        $failed = array();

        $status = self::get_json($post_id, self::META_RELAYS);
        $is_current = is_array($status) && isset($status['event_id']) && $status['event_id'] === $event['id'];
        if (!$is_current) {
            $current = self::get_event($post_id);
            if ($current && $current['id'] === $event['id']) {
                $status = self::fresh_status($event['id']);
                $is_current = true;
            }
        }
        if ($is_current && (!isset($status['relays']) || !is_array($status['relays']))) {
            $status['relays'] = array();
        }

        foreach ((array) $results as $url => $result) {
            $key = self::normalize_url($url);
            list($state, $message) = self::classify_result($result);
            if ($state === self::STATE_ACCEPTED) {
                $ok[] = $key;
            } else {
                $failed[] = $key;
            }
            if (!$is_current) {
                continue;
            }
            $row = isset($status['relays'][$key]) && is_array($status['relays'][$key])
                ? array_merge(self::new_relay_row(), $status['relays'][$key])
                : self::new_relay_row();

            // An accepted relay stays accepted; a later failure to
            // re-confirm doesn't un-publish the event there.
            if ($row['state'] !== self::STATE_ACCEPTED) {
                $row['state'] = $state;
            }
            $row['attempts']     = intval($row['attempts']) + 1;
            $row['last_attempt'] = $now;
            if (empty($row['first_attempt'])) {
                $row['first_attempt'] = $now;
            }
            $row['message']      = self::sanitize_message($message);
            if ($state === self::STATE_ACCEPTED && empty($row['accepted_at'])) {
                $row['accepted_at'] = $now;
            }
            $status['relays'][$key] = $row;
        }

        if ($is_current) {
            $status = self::reschedule($post_id, $status, $now);
            self::put_json($post_id, self::META_RELAYS, $status);
        }

        self::append_log($post_id, array(
            'event_id' => $event['id'],
            'source'   => $source,
            'ok'       => $ok,
            'failed'   => $failed,
        ), $now);

        return self::summary($post_id);
    }

    /**
     * @return array<string, array> relay url => row, for the current event
     */
    public static function get_relay_table($post_id) {
        $status = self::get_json($post_id, self::META_RELAYS);
        if (!is_array($status) || empty($status['relays']) || !is_array($status['relays'])) {
            return array();
        }
        $out = array();
        foreach ($status['relays'] as $url => $row) {
            $out[$url] = array_merge(self::new_relay_row(), is_array($row) ? $row : array());
        }
        return $out;
    }

    /** @return string[] relay urls in the given state */
    public static function relays_in_state($post_id, $state) {
        $out = array();
        foreach (self::get_relay_table($post_id) as $url => $row) {
            if ($row['state'] === $state) {
                $out[] = $url;
            }
        }
        return $out;
    }

    /**
     * @return array{accepted: int, total: int}
     */
    public static function summary($post_id) {
        $table = self::get_relay_table($post_id);
        $accepted = 0;
        foreach ($table as $row) {
            if ($row['state'] === self::STATE_ACCEPTED) {
                $accepted++;
            }
        }
        return array('accepted' => $accepted, 'total' => count($table));
    }

    /* ---------------------------------------------------------------------
     * Retry schedule
     * ------------------------------------------------------------------ */

    /**
     * Seconds to wait after the given number of failed attempts.
     */
    public static function retry_delay($attempts) {
        $attempts = max(1, intval($attempts));
        return isset(self::RETRY_SCHEDULE[$attempts - 1]) ? self::RETRY_SCHEDULE[$attempts - 1] : self::RETRY_EVERY;
    }

    /**
     * Recompute next_retry / expired for every relay row and the post's
     * earliest due time. Only relays still in the site's list are retried,
     * and only once some relay has accepted the event (before that the
     * bunker queue owns retries).
     *
     * @return array the updated status
     */
    public static function reschedule($post_id, array $status, $now = null) {
        $now = $now === null ? time() : (int) $now;
        $configured = array_flip(self::configured_relays());
        $relays = isset($status['relays']) && is_array($status['relays']) ? $status['relays'] : array();

        $any_accepted = false;
        foreach ($relays as $row) {
            if (is_array($row) && isset($row['state']) && $row['state'] === self::STATE_ACCEPTED) {
                $any_accepted = true;
                break;
            }
        }

        $earliest = 0;
        foreach ($relays as $url => $row) {
            $row = array_merge(self::new_relay_row(), is_array($row) ? $row : array());
            $row['next_retry'] = 0;
            $final = in_array($row['state'], array(self::STATE_ACCEPTED, self::STATE_REJECTED), true);

            if (!$final && empty($row['expired']) && isset($configured[$url])) {
                if (intval($row['attempts']) === 0) {
                    $row['next_retry'] = $now; // never tried: due now
                } elseif (!empty($row['first_attempt']) && ($now - intval($row['first_attempt'])) >= self::RETRY_GIVE_UP) {
                    $row['expired'] = true;
                } else {
                    $row['next_retry'] = intval($row['last_attempt']) + self::retry_delay($row['attempts']);
                }
            }

            if ($row['next_retry'] && (!$earliest || $row['next_retry'] < $earliest)) {
                $earliest = $row['next_retry'];
            }
            $relays[$url] = $row;
        }
        $status['relays'] = $relays;

        if ($any_accepted && $earliest) {
            update_post_meta($post_id, self::META_NEXT_RETRY, $earliest);
        } else {
            delete_post_meta($post_id, self::META_NEXT_RETRY);
        }
        return $status;
    }

    /**
     * Relays due for a retry now, in the site's current list.
     *
     * @return string[]
     */
    public static function due_relays($post_id, $now = null) {
        $now = $now === null ? time() : (int) $now;
        $configured = array_flip(self::configured_relays());
        $due = array();
        foreach (self::get_relay_table($post_id) as $url => $row) {
            if (!isset($configured[$url]) || !empty($row['expired'])) {
                continue;
            }
            if (in_array($row['state'], array(self::STATE_ACCEPTED, self::STATE_REJECTED), true)) {
                continue;
            }
            if (intval($row['next_retry']) > 0 && intval($row['next_retry']) <= $now) {
                $due[] = $url;
            } elseif (intval($row['attempts']) === 0) {
                $due[] = $url;
            }
        }
        return $due;
    }

    /**
     * Recompute and save the schedule for a post (e.g. after the relay
     * list changed). Returns the earliest due time or 0.
     */
    public static function refresh_schedule($post_id, $now = null) {
        $status = self::get_json($post_id, self::META_RELAYS);
        if (!is_array($status)) {
            delete_post_meta($post_id, self::META_NEXT_RETRY);
            return 0;
        }
        $status = self::reschedule($post_id, $status, $now);
        self::put_json($post_id, self::META_RELAYS, $status);
        return intval(get_post_meta($post_id, self::META_NEXT_RETRY, true));
    }

    /**
     * Add relays (newly added to the site's list) as pending for this
     * post's current event so the retry sweep sends it there too.
     *
     * @param string[] $relays
     * @return int number of relays added
     */
    public static function adopt_relays($post_id, array $relays, $now = null) {
        $status = self::get_json($post_id, self::META_RELAYS);
        if (!is_array($status) || empty($status['event_id'])) {
            return 0;
        }
        if (!isset($status['relays']) || !is_array($status['relays'])) {
            $status['relays'] = array();
        }
        $added = 0;
        foreach ($relays as $url) {
            $url = self::normalize_url($url);
            if ($url === '' || isset($status['relays'][$url])) {
                continue;
            }
            $status['relays'][$url] = self::new_relay_row();
            $added++;
        }
        if ($added) {
            $status = self::reschedule($post_id, $status, $now);
            self::put_json($post_id, self::META_RELAYS, $status);
        }
        return $added;
    }

    /**
     * Aggregate relay tables into a per-relay health summary.
     *
     * @param array[]  $statuses   decoded _nostr_relay_status values
     * @param string[] $configured relays to always list (may have no data)
     * @return array<string, array> url => counts and last success/reason
     */
    public static function relay_health(array $statuses, array $configured = array()) {
        $blank = array(
            'accepted' => 0, 'pending' => 0, 'rejected' => 0, 'expired' => 0,
            'last_success' => 0, 'last_reason' => '', 'last_reason_at' => 0,
            'reject_reason' => '', 'reject_at' => 0, 'in_list' => false,
        );
        $out = array();
        foreach ($configured as $url) {
            $out[self::normalize_url($url)] = array_merge($blank, array('in_list' => true));
        }
        foreach ($statuses as $status) {
            if (!is_array($status) || empty($status['relays']) || !is_array($status['relays'])) {
                continue;
            }
            foreach ($status['relays'] as $url => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row = array_merge(self::new_relay_row(), $row);
                if (!isset($out[$url])) {
                    $out[$url] = $blank;
                }
                $h =& $out[$url];
                if ($row['state'] === self::STATE_ACCEPTED) {
                    $h['accepted']++;
                    $h['last_success'] = max($h['last_success'], intval($row['accepted_at']));
                } elseif ($row['state'] === self::STATE_REJECTED) {
                    $h['rejected']++;
                    if (intval($row['last_attempt']) >= $h['reject_at']) {
                        $h['reject_reason'] = $row['message'];
                        $h['reject_at'] = intval($row['last_attempt']);
                    }
                } elseif (!empty($row['expired'])) {
                    $h['expired']++;
                } else {
                    $h['pending']++;
                }
                if ($row['state'] !== self::STATE_ACCEPTED && $row['message'] !== ''
                    && intval($row['last_attempt']) >= $h['last_reason_at']) {
                    $h['last_reason'] = $row['message'];
                    $h['last_reason_at'] = intval($row['last_attempt']);
                }
                unset($h);
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Log
     * ------------------------------------------------------------------ */

    /** @return array[] newest last */
    public static function get_log($post_id) {
        $log = self::get_json($post_id, self::META_LOG);
        return is_array($log) ? $log : array();
    }

    private static function append_log($post_id, array $entry, $now = null) {
        $entry = array_merge(array('time' => $now === null ? time() : (int) $now), $entry);
        $log = self::get_log($post_id);
        $log[] = $entry;
        if (count($log) > self::LOG_CAP) {
            $log = array_slice($log, -self::LOG_CAP);
        }
        self::put_json($post_id, self::META_LOG, $log);
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    public static function new_relay_row() {
        return array(
            'state'        => self::STATE_PENDING,
            'attempts'     => 0,
            'last_attempt' => 0,
            'accepted_at'  => 0,
            'message'      => '',
            'first_attempt' => 0,
            'next_retry'   => 0,
            'expired'      => false,
        );
    }

    /**
     * Relay URLs as stored keys: trimmed, lower-case scheme and host, no
     * trailing slash. "wss://nos.lol/" and "wss://nos.lol" are one relay.
     */
    public static function normalize_url($url) {
        $url = trim((string) $url);
        if (preg_match('#^(wss?://)([^/?\#]+)(.*)$#i', $url, $m)) {
            $url = strtolower($m[1]) . strtolower($m[2]) . $m[3];
        }
        return rtrim($url, '/');
    }

    /** @return string[] the site's relay list (normalised, unique) */
    public static function configured_relays() {
        $relays = array();
        if (class_exists('Nostr_Client')) {
            $relays = (array) Nostr_Client::get_instance()->get_user_relays();
        }
        $out = array();
        foreach ($relays as $url) {
            if (is_string($url) && preg_match('#^wss?://#i', trim($url))) {
                $out[self::normalize_url($url)] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Relay replies are untrusted text: strip tags/control characters
     * and cap the length before storing.
     */
    public static function sanitize_message($message) {
        $message = sanitize_text_field((string) $message);
        $message = preg_replace('/[\x00-\x1F\x7F]/u', '', $message);
        if (function_exists('mb_substr')) {
            if (mb_strlen($message) > self::MESSAGE_MAX) {
                $message = mb_substr($message, 0, self::MESSAGE_MAX - 1) . '…';
            }
        } elseif (strlen($message) > self::MESSAGE_MAX) {
            $message = substr($message, 0, self::MESSAGE_MAX - 3) . '...';
        }
        return (string) $message;
    }

    private static function get_json($post_id, $key) {
        $raw = get_post_meta($post_id, $key, true);
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * update_post_meta() runs wp_unslash() on the value, which would strip
     * the backslashes in JSON escapes (\" \n \\) and corrupt the event.
     * wp_slash() first so the stored string is byte-identical.
     */
    private static function put_json($post_id, $key, $data) {
        // Keep non-ASCII escaped (\uXXXX) so the stored string survives any
        // database charset; the event id is computed from the decoded array,
        // so this changes nothing about what is published.
        $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES);
        update_post_meta($post_id, $key, wp_slash($json));
    }
}
