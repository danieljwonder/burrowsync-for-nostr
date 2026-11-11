<?php
/**
 * Behavioural test of Nostr_NIP46_Publisher: queueing, retry backoff,
 * exactly-once signing, idempotency. Uses WP function stubs plus the mock
 * bunker (tests/mock-bunker.php) for real NIP-46 signing. Not shipped.
 *
 * Run with the mock bunker already LISTENING (bunker-out.txt present).
 */

require __DIR__ . '/wp-stubs.php';

/* ---- extra WordPress stubs needed by the publisher ---- */
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);

$GLOBALS['__meta'] = array();
$GLOBALS['__cron'] = array();  // key => timestamp
$GLOBALS['__transients'] = array();
$GLOBALS['__posts'] = array();

function get_post_meta($post_id, $key, $single = false) {
    return isset($GLOBALS['__meta'][$post_id][$key]) ? $GLOBALS['__meta'][$post_id][$key] : '';
}
function update_post_meta($post_id, $key, $value) {
    // WordPress runs wp_unslash() on meta values.
    $GLOBALS['__meta'][$post_id][$key] = is_string($value) ? stripslashes($value) : $value;
    return true;
}
function delete_post_meta($post_id, $key) {
    unset($GLOBALS['__meta'][$post_id][$key]);
    return true;
}
function get_post($id) {
    return isset($GLOBALS['__posts'][$id]) ? $GLOBALS['__posts'][$id] : null;
}
function wp_is_post_autosave($id) { return false; }
function wp_is_post_revision($id) { return false; }
function wp_schedule_single_event($ts, $hook, $args = array()) {
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
function check_ajax_referer() { return true; }
function current_user_can() { return true; }
function wp_send_json_error($d) { throw new Exception('json_error: ' . json_encode($d)); }
function wp_send_json_success($d) { throw new Exception('json_success'); }
function __($s, $d = null) { return $s; }
function wp_slash($v) { return is_string($v) ? addslashes($v) : $v; }

/* Content mapper stub producing the same shape as the real one */
class Nostr_Content_Mapper {
    private static $instance = null;
    public static function get_instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    public function note_to_nostr_event($id) {
        return array('kind' => 1, 'content' => 'queue-test note ' . $id, 'tags' => array(), 'created_at' => time());
    }
    public function post_to_nostr_event($id) {
        return array('kind' => 30023, 'content' => '# post ' . $id, 'tags' => array(array('d', 'p' . $id)), 'created_at' => time());
    }
}

/* Relay publisher stub with a controllable outcome */
class Nostr_Client {
    public static $accept = true;
    public static $published = array();
    private static $instance = null;
    public static function get_instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    public function get_user_relays() { return array('wss://stub'); }
    public function publish_event($event) {
        if (self::$accept) {
            self::$published[] = $event;
            return array('wss://stub' => array('success' => true));
        }
        return array('wss://stub' => array('success' => false, 'error' => 'stub down'));
    }
}

require NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-publish-record.php';
require NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nip46-publisher.php';

$fail = 0;
function check($label, $cond) {
    global $fail;
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) $fail++;
}

/* ---- configure settings against the running mock bunker ---- */
$out = file_get_contents(__DIR__ . '/bunker-out.txt');
preg_match('/^BUNKER_URI=(\S+)/m', $out, $m);
$settings = Nostr_NIP46_Settings::get_instance();
$settings->set_signing_method('nip46');
$settings->save_bunker_uri($m[1]);
$client = new Nostr_NIP46_Client($settings);
$r = $client->connect_and_test();
check('bunker handshake', preg_match('/^[0-9a-f]{64}$/', $r['user_pubkey']) === 1);
sleep(5);

$publisher = Nostr_NIP46_Publisher::get_instance();

/* ---- scenario 1: publish transition queues + processes successfully ---- */
$GLOBALS['__posts'][1] = (object) array('ID' => 1, 'post_type' => 'note', 'post_status' => 'publish');
$publisher->handle_post_publish('publish', 'draft', $GLOBALS['__posts'][1]);
check('queued on publish', get_post_meta(1, '_nostr_sync_status', true) === 'queued');
check('cron attempt scheduled', wp_next_scheduled(Nostr_NIP46_Publisher::CRON_HOOK, array(1)) !== false);

$result = $publisher->process_post(1);
check('process succeeds (bunker up)', $result['success']);
check('status synced', get_post_meta(1, '_nostr_sync_status', true) === 'synced');
$event_id_1 = get_post_meta(1, '_nostr_event_id', true);
check('event id recorded', preg_match('/^[0-9a-f]{64}$/', $event_id_1) === 1);
check('published exactly once', count(Nostr_Client::$published) === 1);
check('cron cleared after success', wp_next_scheduled(Nostr_NIP46_Publisher::CRON_HOOK, array(1)) === false);

/* idempotency: re-processing and re-transitioning must not re-publish */
$result = $publisher->process_post(1);
check('re-process is a no-op', $result['success'] && count(Nostr_Client::$published) === 1);
$publisher->handle_post_publish('publish', 'draft', $GLOBALS['__posts'][1]);
check('re-transition does not requeue', get_post_meta(1, '_nostr_sync_status', true) === 'synced');

/* ---- scenario 2: signing ok but relays reject -> signed event reused ---- */
sleep(5);
$GLOBALS['__posts'][2] = (object) array('ID' => 2, 'post_type' => 'post', 'post_status' => 'publish');
$publisher->handle_post_publish('publish', 'future', $GLOBALS['__posts'][2]); // scheduled-post path
Nostr_Client::$accept = false;
$result = $publisher->process_post(2);
check('relay-down attempt fails', !$result['success']);
check('status back to queued', get_post_meta(2, '_nostr_sync_status', true) === 'queued');
check('attempt counted', get_post_meta(2, '_nostr_nip46_attempts', true) === 1);
$next = wp_next_scheduled(Nostr_NIP46_Publisher::CRON_HOOK, array(2));
check('first retry ~5 min out', abs($next - time() - 300) < 10);
$stored = get_post_meta(2, '_nostr_signed_event', true);
check('signed event stored for reuse', $stored !== '');
$stored_id = json_decode($stored, true)['id'];

Nostr_Client::$accept = true;
$result = $publisher->process_post(2);
check('retry succeeds', $result['success']);
check('same signed event reused (no re-sign)', get_post_meta(2, '_nostr_event_id', true) === $stored_id);
check('signed event kept after success', json_decode(get_post_meta(2, '_nostr_signed_event', true), true)['id'] === $stored_id);

/* ---- scenario 3: bunker unreachable -> backoff schedule ---- */
$settings->save_bunker_uri('bunker://' . str_repeat('a', 64) . '?relay=wss://127.0.0.1:1/&secret=x');
// bunker relays unreachable; also mark connection state so is_bunker_active stays true
$settings->save_connection_state($r['user_pubkey'], 'nip44');

$GLOBALS['__posts'][3] = (object) array('ID' => 3, 'post_type' => 'note', 'post_status' => 'publish');
$publisher->handle_post_publish('publish', 'draft', $GLOBALS['__posts'][3]);

$expected_delays = array(300, 900, 3600, 3600);
foreach ($expected_delays as $i => $delay) {
    $result = $publisher->process_post(3);
    $attempt = $i + 1;
    check("attempt $attempt fails while bunker down", !$result['success']);
    $next = wp_next_scheduled(Nostr_NIP46_Publisher::CRON_HOOK, array(3));
    check("attempt $attempt schedules retry +{$delay}s", $next !== false && abs($next - time() - $delay) < 15);
    check("attempt $attempt keeps status queued", get_post_meta(3, '_nostr_sync_status', true) === 'queued');
}
check('error recorded without secrets', strpos(get_post_meta(3, '_nostr_nip46_last_error', true), 'secret') === false);

/* give-up after 24h window */
update_post_meta(3, '_nostr_nip46_first_failure', time() - DAY_IN_SECONDS - 60);
$result = $publisher->process_post(3);
check('gives up after 24h window', get_post_meta(3, '_nostr_sync_status', true) === 'failed');
check('no more retries scheduled', wp_next_scheduled(Nostr_NIP46_Publisher::CRON_HOOK, array(3)) === false);

/* WordPress publish itself never failed anywhere above (no exception escaped) */

echo $fail === 0 ? "\nALL PUBLISHER CHECKS PASSED\n" : "\n$fail CHECKS FAILED\n";
exit($fail > 0 ? 1 : 0);
