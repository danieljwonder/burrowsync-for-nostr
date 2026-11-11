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

    const LOG_CAP     = 20;
    const MESSAGE_MAX = 200;

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
            return array(self::STATE_REJECTED, $message);
        }

        // Connection failure, HTTP error during handshake, timeout, NOTICE.
        return array(self::STATE_UNREACHABLE, $message);
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
     * @return array{accepted: int, total: int}
     */
    public static function record_results($post_id, $event, $results, $source = 'bunker') {
        $now = time();
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
            $row['message']      = self::sanitize_message($message);
            if ($state === self::STATE_ACCEPTED && empty($row['accepted_at'])) {
                $row['accepted_at'] = $now;
            }
            $status['relays'][$key] = $row;
        }

        if ($is_current) {
            self::put_json($post_id, self::META_RELAYS, $status);
        }

        self::append_log($post_id, array(
            'event_id' => $event['id'],
            'source'   => $source,
            'ok'       => $ok,
            'failed'   => $failed,
        ));

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
     * Log
     * ------------------------------------------------------------------ */

    /** @return array[] newest last */
    public static function get_log($post_id) {
        $log = self::get_json($post_id, self::META_LOG);
        return is_array($log) ? $log : array();
    }

    private static function append_log($post_id, array $entry) {
        $entry = array_merge(array('time' => time()), $entry);
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
        $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        update_post_meta($post_id, $key, wp_slash($json));
    }
}
