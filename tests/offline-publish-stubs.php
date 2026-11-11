<?php
/**
 * Shared offline stubs for the publish-record tests: WordPress functions,
 * a fake bunker client that signs locally with a throwaway key, and a
 * relay client whose per-relay replies the test controls. No network.
 * Not shipped with the plugin. Not a test file itself (no "test-" prefix).
 */

define('ABSPATH', __DIR__ . '/');
define('NOSTR_FOR_WP_PLUGIN_DIR', dirname(__DIR__) . '/');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);

require NOSTR_FOR_WP_PLUGIN_DIR . 'vendor/autoload.php';
require NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nip46-crypto.php';

$GLOBALS['__meta'] = array();
$GLOBALS['__cron'] = array();
$GLOBALS['__transients'] = array();
$GLOBALS['__posts'] = array();
$GLOBALS['__options'] = array();
$GLOBALS['__now'] = null;

function get_option($key, $default = false) {
    return isset($GLOBALS['__options'][$key]) ? $GLOBALS['__options'][$key] : $default;
}
function update_option($key, $value, $autoload = null) {
    $GLOBALS['__options'][$key] = $value;
    return true;
}
function delete_option($key) {
    unset($GLOBALS['__options'][$key]);
    return true;
}
function get_post_meta($post_id, $key, $single = false) {
    return isset($GLOBALS['__meta'][$post_id][$key]) ? $GLOBALS['__meta'][$post_id][$key] : '';
}
function update_post_meta($post_id, $key, $value) {
    // Same as WordPress: update_metadata() runs wp_unslash() on the value.
    $GLOBALS['__meta'][$post_id][$key] = is_string($value) ? stripslashes($value) : $value;
    return true;
}
function delete_post_meta($post_id, $key) {
    unset($GLOBALS['__meta'][$post_id][$key]);
    return true;
}
function wp_slash($v) { return is_string($v) ? addslashes($v) : $v; }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function sanitize_text_field($str) {
    return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $str)));
}
/*
 * get_posts() covering the queries the publisher and retry sweep make:
 * post_type/status, meta_query (<=, NUMERIC), meta_key + EXISTS,
 * date_query after (post_date_gmt), orderby meta_value_num / ID, paging.
 */
function get_posts($args) {
    $types = isset($args['post_type']) ? (array) $args['post_type'] : array('post');
    $ids = array();
    foreach ($GLOBALS['__posts'] as $id => $p) {
        if (!in_array($p->post_type, $types, true)) continue;
        if (isset($args['post_status']) && $args['post_status'] !== 'any' && $p->post_status !== $args['post_status']) continue;
        if (!empty($args['meta_query'])) {
            $ok = true;
            foreach ($args['meta_query'] as $q) {
                $v = get_post_meta($id, $q['key'], true);
                if ($v === '') { $ok = false; break; }
                if (isset($q['compare']) && $q['compare'] === '<=' && !((float) $v <= (float) $q['value'])) { $ok = false; break; }
            }
            if (!$ok) continue;
        }
        if (isset($args['meta_compare']) && $args['meta_compare'] === 'EXISTS' && get_post_meta($id, $args['meta_key'], true) === '') continue;
        if (isset($args['meta_value']) && get_post_meta($id, $args['meta_key'], true) !== $args['meta_value']) continue;
        if (!empty($args['date_query'][0]['after'])) {
            $gmt = isset($p->post_date_gmt) ? $p->post_date_gmt : gmdate('Y-m-d H:i:s');
            if (strcmp($gmt, $args['date_query'][0]['after']) < 0) continue;
        }
        $ids[] = $id;
    }
    if (isset($args['orderby']) && $args['orderby'] === 'meta_value_num') {
        usort($ids, function ($a, $b) use ($args) {
            return (float) get_post_meta($a, $args['meta_key'], true) <=> (float) get_post_meta($b, $args['meta_key'], true);
        });
    } else {
        sort($ids);
    }
    $per = isset($args['posts_per_page']) ? (int) $args['posts_per_page'] : 10;
    if ($per > 0) {
        $page = isset($args['paged']) ? max(1, (int) $args['paged']) : 1;
        $ids = array_slice($ids, ($page - 1) * $per, $per);
    }
    return $ids;
}
function get_post($id) { return isset($GLOBALS['__posts'][$id]) ? $GLOBALS['__posts'][$id] : null; }
function get_post_time($fmt, $gmt, $post) { return 1700000000; }
function wp_is_post_autosave($id) { return false; }
function wp_is_post_revision($id) { return false; }
function wp_schedule_single_event($ts, $hook, $args = array()) {
    $GLOBALS['__cron'][$hook . ':' . json_encode($args)] = $ts;
    return true;
}
function wp_schedule_event($ts, $recurrence, $hook, $args = array()) {
    $GLOBALS['__cron'][$hook . ':' . json_encode($args)] = $ts;
    return true;
}
function wp_clear_scheduled_hook($hook, $args = array()) {
    unset($GLOBALS['__cron'][$hook . ':' . json_encode($args)]);
}
function wp_next_scheduled($hook, $args = array()) {
    $k = $hook . ':' . json_encode($args);
    return isset($GLOBALS['__cron'][$k]) ? $GLOBALS['__cron'][$k] : false;
}
function get_transient($k) { return isset($GLOBALS['__transients'][$k]) ? $GLOBALS['__transients'][$k] : false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['__transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['__transients'][$k]); return true; }
function spawn_cron() {}
function add_action() {}
function add_filter() {}
function current_time($t) { return date('Y-m-d H:i:s'); }
function __($s, $d = null) { return $s; }

/* Settings stub: bunker mode on. */
class Nostr_NIP46_Settings {
    private static $instance = null;
    public static function get_instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    public static $active = true;
    public static $user_pubkey = null;
    public function is_bunker_active() { return self::$active; }
    public function get_signing_method() { return self::$active ? 'nip46' : 'nip07'; }
    public function get_user_pubkey() { return self::$user_pubkey; }
}

/* Bunker client stub: signs locally with a throwaway key, counts calls. */
class Nostr_NIP46_Client {
    const RPC_TIMEOUT = 30;
    public static $sign_calls = 0;
    public static $priv = null;
    public function __construct($settings) {}
    public function sign_event($event) {
        self::$sign_calls++;
        if (is_callable(self::$tamper)) { $event = call_user_func(self::$tamper, $event); }
        $event['pubkey'] = Nostr_NIP46_Crypto::pubkey_from_privkey(self::$priv);
        $event['id'] = Nostr_NIP46_Crypto::event_id($event);
        $event['sig'] = Nostr_NIP46_Crypto::schnorr_sign($event['id'], self::$priv);
        return $event;
    }
    public static $tamper = null;
}
Nostr_NIP46_Client::$priv = hash('sha256', 'burrowsync offline test key');

/* Content mapper stub; content exercises JSON escaping on purpose. */
class Nostr_Content_Mapper {
    public static $content = array();
    private static $instance = null;
    public static function get_instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    public function note_to_nostr_event($id) {
        $c = isset(self::$content[$id]) ? self::$content[$id] : 'note ' . $id;
        return array('kind' => 1, 'content' => $c, 'tags' => array(), 'created_at' => time());
    }
    public function post_to_nostr_event($id) {
        $c = isset(self::$content[$id]) ? self::$content[$id] : '# post ' . $id;
        return array('kind' => 30023, 'content' => $c, 'tags' => array(array('d', 'p' . $id)), 'created_at' => time());
    }
}

/*
 * Relay client stub. $replies maps relay url => reply shape, the same
 * shapes Nostr_Client::publish_event() returns:
 *   accepted:    array('success' => true, 'ok_received' => true, 'message' => '')
 *   OK=false:    array('success' => false, 'ok_received' => true, 'error' => 'restricted: ...')
 *   unreachable: array('success' => false, 'error' => 'Failed to connect ...')
 */
class Nostr_Client {
    public static $relays = array();
    public static $replies = array();
    public static $published = array(); // list of [event_id, relays tried]
    private static $instance = null;
    public static function get_instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    public function get_user_relays($user_id = null) { return self::$relays; }
    public static $on_publish = null;
    public function publish_event($event, $user_id = null, $only_relays = null) {
        if (is_callable(self::$on_publish)) { call_user_func(self::$on_publish); }
        $targets = $only_relays === null ? self::$relays : $only_relays;
        $out = array();
        foreach ($targets as $url) {
            $out[$url] = isset(self::$replies[$url]) ? self::$replies[$url] : array('success' => false, 'error' => 'no reply configured');
        }
        self::$published[] = array($event['id'], array_values($targets));
        return $out;
    }
}

$GLOBALS['__fail'] = 0;
function check($label, $cond) {
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) $GLOBALS['__fail']++;
}
function finish($name) {
    $fail = $GLOBALS['__fail'];
    echo $fail === 0 ? "\nALL $name CHECKS PASSED\n" : "\n$fail $name CHECKS FAILED\n";
    exit($fail > 0 ? 1 : 0);
}
function meta_json($post_id, $key) {
    $raw = get_post_meta($post_id, $key, true);
    return is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
}
