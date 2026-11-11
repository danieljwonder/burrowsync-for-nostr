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
    public static function fetch_current($relays = null) { return self::$current; }
}
$signed_current = Nostr_NIP46_Crypto::finalize_event(10002, '', array(array('r', 'wss://a.example'), array('r', 'wss://c.example', 'read')), $priv);
RL_Test::$current = $signed_current;

/* no bunker: publish should hand back an unsigned event for the browser */
$r = RL_Test::publish(array('wss://a.example', 'wss://x.example'), $signed_current['id']);
check('matching expected id passes the stale check', empty($r['stale']));
check('without bunker: returns unsigned event for NIP-07', !empty($r['needs_browser_signer']) && $r['unsigned']['kind'] === 10002);
check('unsigned keeps read-only relay', in_array(array('r', 'wss://c.example', 'read'), $r['unsigned']['tags'], true));

$r = RL_Test::publish(array('wss://a.example'), 'someotherid');
check('changed-elsewhere is refused as stale', !empty($r['stale']) && $r['ok'] === false);
check('stale result carries a diff', isset($r['diff']['added']) && isset($r['diff']['removed']));

$r = RL_Test::publish(array('wss://a.example'), null);
check('no expected id = no stale check', empty($r['stale']));

/* bunker mode: signs via the (stub) bunker and broadcasts */
Nostr_NIP46_Settings::$active = true; Nostr_NIP46_Settings::$user_pubkey = $pub;
Nostr_Client::$relays = array('wss://a.example');
Nostr_Client::$replies = array('wss://a.example' => array('success' => true, 'ok_received' => true, 'message' => ''));
Nostr_Client::$published = array();
$signs = Nostr_NIP46_Client::$sign_calls;
$r = RL_Test::publish(array('wss://a.example', 'wss://x.example'), $signed_current['id']);
check('bunker mode: signed once and published', $r['ok'] === true && Nostr_NIP46_Client::$sign_calls === $signs + 1);
check('published event is kind 10002 by the site key', Nostr_Client::$published[0][0] !== '' && count(Nostr_Client::$published) === 1);
Nostr_NIP46_Settings::$active = false;

/* ---- broadcast: verifies, sends to WP relays + discovery, records ---- */
Nostr_Client::$relays = array('wss://a.example');
$A = 'wss://a.example'; $D1 = 'wss://purplepag.es';
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

finish('RELAY-LIST');
