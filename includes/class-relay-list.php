<?php
/**
 * NIP-65 relay list (kind 10002) in both directions.
 *
 * - Import: fetch the site's current relay list from Nostr and offer it as
 *   the WordPress relay configuration.
 * - Publish: when the WordPress relay list is saved with "also update my
 *   Nostr relay list" ticked, sign a new kind 10002 and broadcast it.
 *
 * Guard rails:
 * - Before publishing, the current list on Nostr is fetched again and
 *   compared with the one the admin page loaded. If it changed in the
 *   meantime (edited in another client), the save is refused with a diff
 *   so nothing is overwritten silently.
 * - Read-only relays in the Nostr list are kept as they are; only the
 *   write set is replaced by the WordPress list.
 * - Nothing is published unless a signer is available (bunker, or the
 *   browser extension via the existing NIP-07 flow).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_Relay_List {

    const KIND = 10002;

    /** Where to look for the list when the site's own list is empty or stale. */
    const DISCOVERY_RELAYS = array(
        'wss://purplepag.es',
        'wss://relay.damus.io',
        'wss://relay.primal.net',
        'wss://nos.lol',
        'wss://relay.nostr.band',
    );

    const QUERY_TIMEOUT = 3;
    const HISTORY_OPTION = 'nostr_relay_list_history';
    const HISTORY_MAX = 10;

    /** Details of the most recent fetch_current(): asked/answered/silent/complete. */
    public static $last_lookup = array('asked' => array(), 'answered' => array(), 'silent' => array(), 'complete' => false);

    /**
     * Hex pubkey the site publishes as: the bunker's user pubkey in bunker
     * mode, otherwise the stored NIP-07 pubkey.
     *
     * @return string|null
     */
    public static function site_pubkey() {
        if (class_exists('Nostr_NIP46_Settings')) {
            $s = Nostr_NIP46_Settings::get_instance();
            if ($s->is_bunker_active() && $s->get_user_pubkey()) {
                return strtolower($s->get_user_pubkey());
            }
        }
        $options = get_option('nostr_for_wp_options', array());
        $pk = isset($options['public_key']) ? strtolower(trim((string) $options['public_key'])) : '';
        return preg_match('/^[0-9a-f]{64}$/', $pk) ? $pk : null;
    }

    /**
     * Fetch the newest kind 10002 for the site's pubkey.
     *
     * @param string[]|null $relays relays to ask; defaults to the site list + discovery relays
     * @return array|null the event, or null when none was found
     */
    public static function fetch_current($relays = null) {
        $pubkey = self::site_pubkey();
        if (!$pubkey) {
            return null;
        }
        if ($relays === null) {
            $relays = array_merge(Nostr_Publish_Record::configured_relays(), self::DISCOVERY_RELAYS);
        }
        $seen = array();
        $newest = null;
        $lookup = array('asked' => array(), 'answered' => array(), 'silent' => array(), 'complete' => true);
        $deadline = microtime(true) + 18;
        foreach ($relays as $url) {
            $url = Nostr_Publish_Record::normalize_url($url);
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $lookup['asked'][] = $url;
            if (microtime(true) >= $deadline) {
                $lookup['complete'] = false;
                $lookup['silent'][] = $url;
                continue;
            }
            try {
                $events = static::query($url, array('kinds' => array(self::KIND), 'authors' => array($pubkey), 'limit' => 1));
                $lookup['answered'][] = $url;
            } catch (Exception $e) {
                $lookup['silent'][] = $url;
                continue;
            }
            foreach ($events as $event) {
                if (!is_array($event) || empty($event['id']) || (int) $event['kind'] !== self::KIND) {
                    continue;
                }
                if (!self::verify($event, $pubkey)) {
                    continue;
                }
                if ($newest === null || (int) $event['created_at'] > (int) $newest['created_at']
                    || ((int) $event['created_at'] === (int) $newest['created_at'] && strcmp($event['id'], $newest['id']) < 0)) {
                    $newest = $event;
                }
            }
        }
        self::$last_lookup = $lookup;
        if (!$lookup['answered']) {
            throw new RuntimeException(__('No relay completed the relay-list lookup. Nothing will be published; try again later.', 'burrowsync-for-nostr'));
        }
        return $newest;
    }

    /**
     * Whether the last lookup is solid enough to publish on top of: it
     * finished in time and at least one of the site's own relays answered.
     * Import may proceed on a partial lookup (with a warning); replacing
     * the public list may not.
     *
     * @return string '' when fine, otherwise the reason
     */
    public static function lookup_gap() {
        $l = self::$last_lookup;
        $own = array_map(array('Nostr_Publish_Record', 'normalize_url'), Nostr_Publish_Record::configured_relays());
        $own_answered = array_intersect($own, $l['answered']);
        if (!$l['complete']) {
            return __('The relay-list lookup ran out of time before every relay answered.', 'burrowsync-for-nostr');
        }
        if ($own && !$own_answered) {
            return sprintf(
                /* translators: %s: relay list */
                __('None of your configured relays answered the lookup (%s).', 'burrowsync-for-nostr'),
                implode(', ', array_intersect($own, $l['silent']))
            );
        }
        return '';
    }

    /**
     * Parse a kind 10002 into read/write sets.
     *
     * @return array{write: string[], read: string[], both: string[]}
     */
    public static function parse($event) {
        $write = array();
        $read = array();
        $both = array();
        if (!is_array($event) || empty($event['tags']) || !is_array($event['tags'])) {
            return array('write' => array(), 'read' => array(), 'both' => array());
        }
        foreach ($event['tags'] as $tag) {
            if (!is_array($tag) || count($tag) < 2 || $tag[0] !== 'r' || !is_string($tag[1])) {
                continue;
            }
            $url = Nostr_Publish_Record::normalize_url($tag[1]);
            if ($url === '' || !preg_match('#^wss?://#i', $url)) {
                continue;
            }
            $marker = isset($tag[2]) && is_string($tag[2]) ? strtolower($tag[2]) : '';
            if ($marker === 'read') {
                $read[] = $url;
            } elseif ($marker === 'write') {
                $write[] = $url;
            } elseif ($marker === '') {
                $both[] = $url;
            }
        }
        return array(
            'write' => array_values(array_unique(array_merge($both, $write))),
            'read'  => array_values(array_unique(array_merge($both, $read))),
            'both'  => array_values(array_unique($both)),
        );
    }

    /**
     * Build the unsigned kind 10002 that represents the WordPress list.
     * WordPress relays become read+write. Read-only relays from the current
     * Nostr list that are not in the WordPress list are kept as read-only.
     *
     * @param string[]   $wp_relays the WordPress list
     * @param array|null $current   the current kind 10002 on Nostr, if any
     * @return array unsigned event
     */
    public static function build_event(array $wp_relays, $current = null) {
        $tags = array();
        $wp = array();
        foreach ($wp_relays as $url) {
            $url = Nostr_Publish_Record::normalize_url($url);
            if ($url !== '' && preg_match('#^wss?://#i', $url) && !isset($wp[$url])) {
                $wp[$url] = true;
                $tags[] = array('r', $url);
            }
        }
        if ($current) {
            $parsed = self::parse($current);
            foreach ($parsed['read'] as $url) {
                if (!isset($wp[$url]) && !in_array($url, $parsed['write'], true)) {
                    $tags[] = array('r', $url, 'read');
                }
            }
        }
        // Relays keep the kind 10002 with the newest created_at, so a
        // replacement must be strictly newer than what is there.
        $created = time();
        if ($current && isset($current['created_at']) && (int) $current['created_at'] >= $created) {
            $created = (int) $current['created_at'] + 1;
        }
        return array(
            'kind'       => self::KIND,
            'content'    => '',
            'tags'       => $tags,
            'created_at' => $created,
        );
    }

    /**
     * Compare two lists for the "changed elsewhere" check.
     *
     * @return array{added: string[], removed: string[]}
     */
    public static function diff(array $before, array $after) {
        $before = array_map(array('Nostr_Publish_Record', 'normalize_url'), $before);
        $after = array_map(array('Nostr_Publish_Record', 'normalize_url'), $after);
        return array(
            'added'   => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
        );
    }

    /**
     * Sign and broadcast a kind 10002 for the WordPress list, with the
     * stale-check against what the admin page loaded.
     *
     * @param string[]    $wp_relays
     * @param string|null $expected_id id of the kind 10002 the page loaded ('' = none)
     * @return array{ok: bool, message: string, diff?: array, event_id?: string, relays?: array}
     */
    public static function publish(array $wp_relays, $expected_id = null) {
        if ($expected_id === null || ($expected_id !== '' && !preg_match('/^[0-9a-f]{64}$/', $expected_id))) {
            return array('ok' => false, 'message' => __('Load your Nostr relay list before publishing.', 'burrowsync-for-nostr'));
        }
        try {
            $current = static::fetch_current();
        } catch (Exception $e) {
            return array('ok' => false, 'message' => $e->getMessage());
        }
        $current_id = $current ? $current['id'] : '';
        if ($expected_id !== null && $expected_id !== $current_id) {
            $now = $current ? self::parse($current) : array('write' => array());
            return array(
                'ok'      => false,
                'stale'   => true,
                'message' => __('Your relay list on Nostr changed since this page loaded. Reload the page to see the current list before publishing.', 'burrowsync-for-nostr'),
                'diff'    => self::diff($wp_relays, $now['write']),
            );
        }

        $gap = static::lookup_gap();
        if ($gap !== '') {
            return array('ok' => false, 'message' => $gap . ' ' . __('Not publishing, so a newer list on a silent relay cannot be overwritten.', 'burrowsync-for-nostr'));
        }

        $unsigned = self::build_event($wp_relays, $current);
        return self::sign_and_broadcast($unsigned, $current, $expected_id);
    }

    /**
     * Shared tail of publish() and revert(): sign via the bunker (or hand
     * back for the browser), make sure the signer returned exactly what was
     * asked, recheck Nostr after the signing round trip, then broadcast.
     */
    private static function sign_and_broadcast(array $unsigned, $current, $expected_id) {
        if (!class_exists('Nostr_NIP46_Settings') || !Nostr_NIP46_Settings::get_instance()->is_bunker_active()) {
            // Browser signing: hand the unsigned event back for NIP-07.
            return array('ok' => false, 'needs_browser_signer' => true, 'unsigned' => $unsigned, 'message' => '');
        }

        try {
            $client = new Nostr_NIP46_Client(Nostr_NIP46_Settings::get_instance());
            $signed = $client->sign_event($unsigned);
        } catch (Exception $e) {
            return array('ok' => false, 'message' => sanitize_text_field($e->getMessage()));
        }
        if (!self::signed_matches($signed, $unsigned)) {
            return array('ok' => false, 'message' => __('The signer returned a different relay list than was requested. Nothing was published.', 'burrowsync-for-nostr'));
        }

        // The bunker round trip can take a while; make sure nothing landed meanwhile.
        try {
            $again = static::fetch_current();
        } catch (Exception $e) {
            return array('ok' => false, 'message' => $e->getMessage());
        }
        if (($again ? $again['id'] : '') !== $expected_id) {
            return array('ok' => false, 'stale' => true, 'message' => __('Your relay list on Nostr changed while the signer was working. Reload the page and try again.', 'burrowsync-for-nostr'), 'diff' => array('added' => array(), 'removed' => array()));
        }
        self::remember($current, 'nostr');
        return self::broadcast($signed);
    }

    /** The signed event must carry exactly the requested kind, tags, content and time. */
    public static function signed_matches(array $signed, array $unsigned) {
        return isset($signed['kind'], $signed['tags'], $signed['content'], $signed['created_at'])
            && (int) $signed['kind'] === (int) $unsigned['kind']
            && $signed['tags'] === $unsigned['tags']
            && $signed['content'] === $unsigned['content']
            && (int) $signed['created_at'] === (int) $unsigned['created_at'];
    }

    /* ------------------------------------------------------------------ */
    /* History: the last few relay lists, so a bad publish can be undone.  */

    /**
     * @return array[] newest first: {id, created_at, tags, source, saved_at}
     */
    public static function history() {
        $h = get_option(self::HISTORY_OPTION, array());
        return is_array($h) ? $h : array();
    }

    /** Keep a signed kind 10002 (ours or one fetched from Nostr). */
    public static function remember($event, $source) {
        if (!is_array($event) || empty($event['id']) || !isset($event['tags'])) {
            return;
        }
        $h = self::history();
        foreach ($h as $row) {
            if ($row['id'] === $event['id']) {
                return;
            }
        }
        array_unshift($h, array(
            'id'         => $event['id'],
            'created_at' => (int) $event['created_at'],
            'tags'       => $event['tags'],
            'source'     => $source === 'wordpress' ? 'wordpress' : 'nostr',
            'saved_at'   => time(),
        ));
        update_option(self::HISTORY_OPTION, array_slice($h, 0, self::HISTORY_MAX), false);
    }

    /**
     * Republish a remembered relay list (as a new, newer event) and set the
     * WordPress relays to its write set. Same guards as publish().
     */
    public static function revert($history_id, $expected_id) {
        $entry = null;
        foreach (self::history() as $row) {
            if ($row['id'] === $history_id) {
                $entry = $row;
                break;
            }
        }
        if (!$entry) {
            return array('ok' => false, 'message' => __('That relay list is no longer in the history.', 'burrowsync-for-nostr'));
        }
        if ($expected_id === null || ($expected_id !== '' && !preg_match('/^[0-9a-f]{64}$/', $expected_id))) {
            return array('ok' => false, 'message' => __('Load your Nostr relay list before reverting.', 'burrowsync-for-nostr'));
        }
        try {
            $current = static::fetch_current();
        } catch (Exception $e) {
            return array('ok' => false, 'message' => $e->getMessage());
        }
        if (($current ? $current['id'] : '') !== $expected_id) {
            return array('ok' => false, 'stale' => true, 'message' => __('Your relay list on Nostr changed since this page loaded. Reload the page before reverting.', 'burrowsync-for-nostr'), 'diff' => array('added' => array(), 'removed' => array()));
        }
        $gap = static::lookup_gap();
        if ($gap !== '') {
            return array('ok' => false, 'message' => $gap);
        }
        $unsigned = self::build_event(array(), $current);
        $unsigned['tags'] = $entry['tags']; // exactly the remembered list
        $result = self::sign_and_broadcast($unsigned, $current, $expected_id);
        if (!empty($result['ok'])) {
            $parsed = self::parse(array('tags' => $entry['tags']));
            self::set_wp_relays($parsed['write']);
            $result['wp_relays'] = $parsed['write'];
        }
        return $result;
    }

    /** Store the WordPress relay list (same option the settings card saves). */
    private static function set_wp_relays(array $relays) {
        $options = get_option('nostr_for_wp_options', array());
        $options['relays'] = array_values($relays);
        update_option('nostr_for_wp_options', $options);
    }

    /** Recheck after browser approval, which may take several minutes. */
    public static function broadcast_browser(array $signed, $expected_id) {
        if ($expected_id === null || ($expected_id !== '' && !preg_match('/^[0-9a-f]{64}$/', $expected_id))) {
            return array('ok' => false, 'message' => __('Load the relay list first.', 'burrowsync-for-nostr'));
        }
        try {
            $current = static::fetch_current();
        } catch (Exception $e) {
            return array('ok' => false, 'message' => $e->getMessage());
        }
        if (($current ? $current['id'] : '') !== $expected_id) {
            return array('ok' => false, 'message' => __('The Nostr relay list changed while waiting for your signer. Load it again before publishing.', 'burrowsync-for-nostr'));
        }
        $gap = static::lookup_gap();
        if ($gap !== '') {
            return array('ok' => false, 'message' => $gap);
        }
        $proposed = self::build_event(Nostr_Publish_Record::configured_relays(), $current);
        $remembered = null;
        foreach (self::history() as $row) {
            if (isset($signed['tags']) && $row['tags'] === $signed['tags']) {
                $remembered = $row;
            }
        }
        $tags_ok = isset($signed['tags']) && ($signed['tags'] === $proposed['tags'] || $remembered !== null);
        $newer = isset($signed['created_at']) && (!$current || (int) $signed['created_at'] > (int) $current['created_at']);
        if (!$tags_ok || !isset($signed['content'], $signed['created_at']) || $signed['content'] !== ''
            || !$newer || abs(time() - (int) $signed['created_at']) > 600) {
            return array('ok' => false, 'message' => __('The signed relay list no longer matches the saved configuration. Save again.', 'burrowsync-for-nostr'));
        }
        self::remember($current, 'nostr');
        $result = self::broadcast($signed);
        if (!empty($result['ok']) && $remembered !== null && $signed['tags'] !== $proposed['tags']) {
            $parsed = self::parse(array('tags' => $remembered['tags']));
            self::set_wp_relays($parsed['write']);
            $result['wp_relays'] = $parsed['write'];
        }
        return $result;
    }

    /**
     * Broadcast a signed kind 10002 to the WordPress relays plus the
     * discovery relays (so other clients find it), and record the result.
     */
    public static function broadcast(array $signed) {
        if (!Nostr_Publish_Record::is_valid_event($signed, true) || (int) $signed['kind'] !== self::KIND
            || $signed['pubkey'] !== self::site_pubkey()) {
            return array('ok' => false, 'message' => __('The signed relay list did not verify.', 'burrowsync-for-nostr'));
        }
        $targets = array_values(array_unique(array_merge(
            Nostr_Publish_Record::configured_relays(),
            array_map(array('Nostr_Publish_Record', 'normalize_url'), self::DISCOVERY_RELAYS)
        )));
        $results = Nostr_Client::get_instance()->publish_event($signed, null, $targets);
        $ok = array();
        $failed = array();
        foreach ($results as $url => $r) {
            list($state, $msg) = Nostr_Publish_Record::classify_result($r);
            if ($state === Nostr_Publish_Record::STATE_ACCEPTED) {
                $ok[] = $url;
            } else {
                $failed[$url] = $msg;
            }
        }
        if (!empty($ok)) {
            self::remember($signed, 'wordpress');
        }
        update_option('nostr_relay_list_last_publish', array(
            'time'     => time(),
            'event_id' => $signed['id'],
            'ok'       => $ok,
            'failed'   => $failed,
        ), false);
        if (empty($ok)) {
            return array('ok' => false, 'message' => __('No relay accepted the relay list.', 'burrowsync-for-nostr'), 'relays' => array('ok' => $ok, 'failed' => $failed));
        }
        return array(
            'ok'       => true,
            'event_id' => $signed['id'],
            'message'  => sprintf(
                /* translators: 1: relays that accepted, 2: relays tried */
                __('Relay list published to %1$d of %2$d relays.', 'burrowsync-for-nostr'),
                count($ok),
                count($results)
            ),
            'relays'   => array('ok' => $ok, 'failed' => $failed),
        );
    }

    /**
     * Real relay check: WebSocket handshake plus a REQ, not just TCP.
     *
     * @return array{ok: bool, message: string}
     */
    public static function check_relay($url) {
        $url = Nostr_Publish_Record::normalize_url($url);
        if (!preg_match('#^wss?://#i', $url)) {
            return array('ok' => false, 'message' => __('Not a relay URL.', 'burrowsync-for-nostr'));
        }
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-websocket-client.php';
        $ws = new Nostr_WebSocket_Client($url, 2);
        if (!$ws->connect()) {
            $why = method_exists($ws, 'get_last_error') ? $ws->get_last_error() : '';
            return array('ok' => false, 'message' => $why !== '' ? $why : __('Connection failed.', 'burrowsync-for-nostr'));
        }
        $sub = 'chk_' . substr(md5((string) microtime(true)), 0, 8);
        $ws->send_message(json_encode(array('REQ', $sub, array('kinds' => array(1), 'limit' => 1))));
        $start = time();
        while ((time() - $start) < self::QUERY_TIMEOUT) {
            $msg = $ws->read_message();
            if ($msg) {
                $d = json_decode($msg, true);
                if (is_array($d) && isset($d[0])) {
                    if (($d[0] === 'EVENT' || $d[0] === 'EOSE') && isset($d[1]) && $d[1] === $sub) {
                        $ws->send_message(json_encode(array('CLOSE', $sub)));
                        $ws->close();
                        return array('ok' => true, 'message' => __('Relay answered.', 'burrowsync-for-nostr'));
                    }
                    if ($d[0] === 'CLOSED' || $d[0] === 'NOTICE') {
                        $ws->close();
                        return array('ok' => false, 'message' => isset($d[2]) && is_string($d[2]) ? $d[2] : (isset($d[1]) && is_string($d[1]) ? $d[1] : 'refused'));
                    }
                }
            }
            usleep(100000);
        }
        $ws->close();
        return array('ok' => false, 'message' => __('Connected but no reply to a request.', 'burrowsync-for-nostr'));
    }

    /* ------------------------------------------------------------------ */

    /** Query one relay with a short timeout; returns events. */
    protected static function query($url, array $filter) {
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-websocket-client.php';
        $events = array();
        $ws = new Nostr_WebSocket_Client($url, 2);
        if (!$ws->connect()) {
            throw new RuntimeException('Relay connection failed');
        }
        $sub = 'rl_' . substr(md5((string) microtime(true)), 0, 8);
        if (!$ws->send_message(json_encode(array('REQ', $sub, $filter)))) {
            $ws->close();
            throw new RuntimeException('Relay request failed');
        }
        $completed = false;
        $start = time();
        while ((time() - $start) < self::QUERY_TIMEOUT) {
            $msg = $ws->read_message();
            if ($msg) {
                $d = json_decode($msg, true);
                if (is_array($d) && isset($d[0], $d[1]) && $d[1] === $sub) {
                    if ($d[0] === 'EVENT' && isset($d[2])) {
                        $events[] = $d[2];
                    } elseif ($d[0] === 'EOSE') {
                        $completed = true;
                        break;
                    } elseif ($d[0] === 'CLOSED') {
                        break;
                    }
                }
            }
            usleep(100000);
        }
        $ws->send_message(json_encode(array('CLOSE', $sub)));
        $ws->close();
        if (!$completed) {
            throw new RuntimeException('Relay did not complete the query');
        }
        return $events;
    }

    /** id + signature + author check. */
    private static function verify(array $event, $pubkey) {
        return isset($event['pubkey']) && strtolower($event['pubkey']) === $pubkey
            && Nostr_Publish_Record::is_valid_event($event, true);
    }
}
