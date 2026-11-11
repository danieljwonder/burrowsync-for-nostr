<?php
/**
 * NIP-65 relay list (kind 10002): parsing, building, read-only
 * preservation, diffing, the stale check and broadcasting. Offline: relay
 * queries and publishes are scripted through the shared stubs.
 */
require __DIR__ . '/offline-publish-stubs.php';
require NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-publish-record.php';
require NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-relay-list.php';

if (!function_exists('home_url')) { function home_url() { return 'https://example.test'; } }

$priv = Nostr_NIP46_Client::$priv; // the stub bunker's key
$pub  = Nostr_NIP46_Crypto::pubkey_from_privkey($priv);
Nostr_NIP46_Settings::$active = false; // start in browser-signing mode
$A = 'wss://a.example'; $D1 = 'wss://purplepag.es';

/* ---- parse ---- */
$ev = array('kind' => 10002, 'tags' => array(
    array('r', 'wss://a.example/'),
    array('r', 'wss://B.example', 'write'),
    array('r', 'wss://c.example', 'read'),
    array('r', 'not-a-url'),
    array('e', 'wss://ignored.example'),
), 'content' => '');
$p = Nostr_Relay_List::parse($ev);
check('plain r tag is read+write (normalised)', in_array('wss://a.example', $p['write'], true) && in_array('wss://a.example', $p['read'], true));
check('write marker only in write', in_array('wss://b.example', $p['write'], true) && !in_array('wss://b.example', $p['read'], true));
check('read marker only in read', in_array('wss://c.example', $p['read'], true) && !in_array('wss://c.example', $p['write'], true));
check('non-url and non-r tags ignored', count($p['write']) === 2 && count($p['read']) === 2);

/* ---- build: WP list becomes read+write, read-only relays kept ---- */
$built = Nostr_Relay_List::build_event(array('wss://x.example', 'wss://X.example/', 'wss://a.example'), $ev);
$tags = $built['tags'];
check('kind 10002, empty content', $built['kind'] === 10002 && $built['content'] === '');
check('WP relays deduped and unmarked (read+write)', count(array_filter($tags, function ($t) { return count($t) === 2; })) === 2);
check('read-only relay from Nostr kept as read', in_array(array('r', 'wss://c.example', 'read'), $tags, true));
check('old write-only relay not in WP list is dropped', !in_array(array('r', 'wss://b.example'), $tags, true) && !in_array(array('r', 'wss://b.example', 'write'), $tags, true));
check('WP relay that was read-only on Nostr becomes read+write', in_array(array('r', 'wss://a.example'), $tags, true) && !in_array(array('r', 'wss://a.example', 'read'), $tags, true));
$built2 = Nostr_Relay_List::build_event(array('wss://x.example'), null);
check('no current list: just the WP relays', $built2['tags'] === array(array('r', 'wss://x.example')));

/* ---- diff ---- */
$d = Nostr_Relay_List::diff(array('wss://a.example', 'wss://b.example'), array('wss://b.example/', 'wss://c.example'));
check('diff: added and removed', $d['added'] === array('wss://c.example') && $d['removed'] === array('wss://a.example'));

/* ---- site pubkey: bunker first, then NIP-07 option ---- */
$GLOBALS['__options']['nostr_for_wp_options'] = array('public_key' => strtoupper($pub), 'relays' => array('wss://a.example'));
check('site pubkey from option, lowercased', Nostr_Relay_List::site_pubkey() === $pub);
$GLOBALS['__options']['nostr_for_wp_options']['public_key'] = 'nope';
check('invalid pubkey -> null', Nostr_Relay_List::site_pubkey() === null);
Nostr_NIP46_Settings::$active = true; Nostr_NIP46_Settings::$user_pubkey = strtoupper($pub);
check('bunker pubkey wins when bunker active', Nostr_Relay_List::site_pubkey() === $pub);
Nostr_NIP46_Settings::$active = false;
$GLOBALS['__options']['nostr_for_wp_options']['public_key'] = $pub;

/* ---- stale check uses fetch_current; stub the query via a subclass ---- */
class RL_Test extends Nostr_Relay_List {
    public static $current = null;
    public static $lookup_failed = false;
    public static $gap = '';
    public static $after_sign = null; // callable run on the post-signing re-fetch
    public static $fetches = 0;
    public static function fetch_current($relays = null) {
        if (self::$lookup_failed) { throw new RuntimeException('Lookup failed'); }
        self::$fetches++;
        if (self::$fetches > 1 && is_callable(self::$after_sign)) { call_user_func(self::$after_sign); }
        return self::$current;
    }
    public static function lookup_gap() { return self::$gap; }
}
$signed_current = Nostr_NIP46_Crypto::finalize_event(10002, '', array(array('r', 'wss://a.example'), array('r', 'wss://c.example', 'read')), $priv);
RL_Test::$current = $signed_current;

/* no bunker: publish should hand back an unsigned event for the browser */
$r = RL_Test::publish(array('wss://a.example', 'wss://x.example'), $signed_current['id']);
check('matching expected id passes the stale check', empty($r['stale']));
check('without bunker: returns unsigned event for NIP-07', !empty($r['needs_browser_signer']) && $r['unsigned']['kind'] === 10002);
check('unsigned keeps read-only relay', in_array(array('r', 'wss://c.example', 'read'), $r['unsigned']['tags'], true));

$r = RL_Test::publish(array('wss://a.example'), str_repeat('0', 64));
check('changed-elsewhere is refused as stale', !empty($r['stale']) && $r['ok'] === false);
check('stale result carries a diff', isset($r['diff']['added']) && isset($r['diff']['removed']));

$r = RL_Test::publish(array('wss://a.example'), null);
check('missing baseline refuses publishing', $r['ok'] === false && empty($r['needs_browser_signer']));

/* bunker mode: signs via the (stub) bunker and broadcasts */
Nostr_NIP46_Settings::$active = true; Nostr_NIP46_Settings::$user_pubkey = $pub;
Nostr_Client::$relays = array('wss://a.example');
Nostr_Client::$replies = array('wss://a.example' => array('success' => true, 'ok_received' => true, 'message' => ''));
Nostr_Client::$published = array();
$signs = Nostr_NIP46_Client::$sign_calls;
RL_Test::$fetches = 0;
$r = RL_Test::publish(array('wss://a.example', 'wss://x.example'), $signed_current['id']);
check('bunker mode: signed once and published', $r['ok'] === true && Nostr_NIP46_Client::$sign_calls === $signs + 1);
check('bunker mode: Nostr rechecked after signing', RL_Test::$fetches === 2);
check('published event is kind 10002 by the site key', Nostr_Client::$published[0][0] === $r['event_id'] && count(Nostr_Client::$published) === 1);

/* replacement must be newer than the current list even if clocks disagree */
$future = $signed_current; $future['created_at'] = time() + 500;
$future['id'] = Nostr_NIP46_Crypto::event_id($future); $future['sig'] = Nostr_NIP46_Crypto::schnorr_sign($future['id'], $priv);
$b = Nostr_Relay_List::build_event(array($A), $future);
check('replacement created_at beats a current list dated in the future', $b['created_at'] === $future['created_at'] + 1);

/* signer returning something other than what was asked is refused */
Nostr_NIP46_Client::$tamper = function ($e) { $e['tags'][] = array('r', 'wss://evil.example'); return $e; };
$count = count(Nostr_Client::$published);
$r = RL_Test::publish(array($A), $signed_current['id']);
check('bunker output not matching the request is refused', $r['ok'] === false && count(Nostr_Client::$published) === $count);
Nostr_NIP46_Client::$tamper = null;

/* list changing during the bunker round trip is refused */
RL_Test::$after_sign = function () { RL_Test::$current = null; };
$r = RL_Test::publish(array($A), $signed_current['id']);
check('change during bunker signing is refused as stale', !empty($r['stale']) && count(Nostr_Client::$published) === $count);
RL_Test::$after_sign = null; RL_Test::$current = $signed_current;

/* incomplete lookup blocks publishing */
RL_Test::$gap = 'lookup incomplete';
$r = RL_Test::publish(array($A), $signed_current['id']);
check('lookup gap blocks publishing', $r['ok'] === false && strpos($r['message'], 'lookup incomplete') === 0 && count(Nostr_Client::$published) === $count);
RL_Test::$gap = '';

/* ---- history and revert ---- */
$h = Nostr_Relay_List::history();
check('history keeps the pre-publish Nostr list and the published list', count($h) >= 2 && $h[0]['source'] === 'wordpress' && in_array($signed_current['id'], array_column($h, 'id'), true));
$old = null; foreach ($h as $row) { if ($row['id'] === $signed_current['id']) { $old = $row; } }
$r = RL_Test::revert($signed_current['id'], $signed_current['id']);
check('revert republishes the remembered tags as a newer event', $r['ok'] === true && end(Nostr_Client::$published)[0] === $r['event_id']);
check('revert sets the WordPress relays to the remembered write set', get_option('nostr_for_wp_options')['relays'] === array($A) && $r['wp_relays'] === array($A));
$r = RL_Test::revert(str_repeat('f', 64), $signed_current['id']);
check('revert of unknown history entry refused', $r['ok'] === false);
for ($i = 0; $i < 15; $i++) { Nostr_Relay_List::remember(Nostr_NIP46_Crypto::finalize_event(10002, '', array(array('r', 'wss://h' . $i . '.example')), $priv), 'nostr'); }
check('history is capped', count(Nostr_Relay_List::history()) === Nostr_Relay_List::HISTORY_MAX);
Nostr_NIP46_Settings::$active = false;

/* ---- broadcast: verifies, sends to WP relays + discovery, records ---- */
Nostr_Client::$relays = array('wss://a.example');
Nostr_Client::$replies = array($A => array('success' => true, 'ok_received' => true, 'message' => ''), $D1 => array('success' => false, 'ok_received' => true, 'message' => 'restricted: no'));
Nostr_Client::$published = array();
$b = Nostr_Relay_List::broadcast($signed_current);
check('broadcast ok when one relay accepts', $b['ok'] === true && $b['event_id'] === $signed_current['id']);
$targets = Nostr_Client::$published[0][1];
check('broadcast goes to WP relays and discovery relays', in_array($A, $targets, true) && in_array($D1, $targets, true) && in_array('wss://relay.damus.io', $targets, true));
check('result lists ok and failed relays', $b['relays']['ok'] === array($A) && isset($b['relays']['failed'][$D1]));
check('last publish recorded', get_option('nostr_relay_list_last_publish')['event_id'] === $signed_current['id']);

$tampered = $signed_current; $tampered['content'] = 'x';
$b = Nostr_Relay_List::broadcast($tampered);
check('tampered relay list refused', $b['ok'] === false);
$wrongkind = Nostr_NIP46_Crypto::finalize_event(1, 'hi', array(), $priv);
$b = Nostr_Relay_List::broadcast($wrongkind);
check('wrong kind refused', $b['ok'] === false);

/* ---- fail closed when the lookup is unavailable ---- */
RL_Test::$lookup_failed = true;
$signs = Nostr_NIP46_Client::$sign_calls;
$count = count(Nostr_Client::$published);
$r = RL_Test::publish(array($A), $signed_current['id']);
check('lookup failure blocks signing and broadcast', $r['ok'] === false && Nostr_NIP46_Client::$sign_calls === $signs && count(Nostr_Client::$published) === $count);
RL_Test::$lookup_failed = false;

/* ---- browser approval can be delayed: recheck baseline and payload ---- */
$proposed = Nostr_Relay_List::build_event(array($A), $signed_current);
$browser = Nostr_NIP46_Crypto::finalize_event(10002, '', $proposed['tags'], $priv);
$b = RL_Test::broadcast_browser($browser, $signed_current['id']);
check('matching browser-signed proposal accepted', $b['ok'] === true);
$count = count(Nostr_Client::$published);
$b = RL_Test::broadcast_browser($browser, str_repeat('0', 64));
check('change during browser approval blocks broadcast', $b['ok'] === false && count(Nostr_Client::$published) === $count);
$b = RL_Test::broadcast_browser($browser, null);
check('browser path requires a baseline', $b['ok'] === false);
$altered = Nostr_NIP46_Crypto::finalize_event(10002, '', array(array('r', 'wss://different.example')), $priv);
$b = RL_Test::broadcast_browser($altered, $signed_current['id']);
check('browser payload must match saved relays and preserved read-only entries', $b['ok'] === false);
$other = Nostr_NIP46_Crypto::finalize_event(10002, '', $proposed['tags'], str_repeat('22', 32));
$b = Nostr_Relay_List::broadcast($other);
check('valid signature from a different identity is rejected', $b['ok'] === false);
Nostr_Client::$replies = array();
$b = Nostr_Relay_List::broadcast($browser);
check('no accepting relay is not reported as success', $b['ok'] === false);
$unknown = Nostr_Relay_List::parse(array('tags' => array(array('r', $A, 'unknown'))));
check('unknown NIP-65 markers are ignored', $unknown['read'] === array() && $unknown['write'] === array());

/* ---- actual lookup aggregation, with only the transport stubbed ---- */
class RL_QueryTest extends Nostr_Relay_List {
    public static $answers = array();
    protected static function query($url, array $filter) {
        if (!array_key_exists($url, self::$answers)) { throw new RuntimeException('No response'); }
        return self::$answers[$url];
    }
}
$offline = array('wss://one.example', 'wss://two.example');
RL_QueryTest::$answers = array();
$threw = false;
try { RL_QueryTest::fetch_current($offline); } catch (RuntimeException $e) { $threw = true; }
check('all unreachable relays raise lookup error, not empty profile', $threw);
RL_QueryTest::$answers = array('wss://one.example' => array());
check('completed empty query may return no relay list', RL_QueryTest::fetch_current($offline) === null);
$first = Nostr_NIP46_Crypto::finalize_event(10002, '', array(array('r', $A)), $priv);
$second = Nostr_NIP46_Crypto::finalize_event(10002, '', array(array('r', $D1)), $priv);
RL_QueryTest::$answers = array('wss://one.example' => array($first), 'wss://two.example' => array($second));
$selected = RL_QueryTest::fetch_current($offline);
$expected = $first['created_at'] > $second['created_at'] ? $first : ($first['created_at'] < $second['created_at'] ? $second : (strcmp($first['id'], $second['id']) < 0 ? $first : $second));
check('replaceable event selection honors timestamp and lower-id tiebreak', $selected['id'] === $expected['id']);
RL_QueryTest::$answers = array('wss://one.example' => array($other, $tampered, $wrongkind));
check('lookup ignores another author, tampered events and wrong kind', RL_QueryTest::fetch_current($offline) === null);

finish('RELAY-LIST');
