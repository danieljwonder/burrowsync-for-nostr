<?php
/**
 * Relay retry sweep (Nostr_Relay_Retry): every relay in the list that has
 * not accepted gets the SAME stored event on a backoff until it accepts,
 * definitively rejects, or 7 days pass. Runs the REAL publisher and sweep
 * with a local signing key and scripted relay replies. Offline.
 */

require __DIR__ . '/offline-publish-stubs.php';
require NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-publish-record.php';
if (file_exists(NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-relay-retry.php')) {
    require NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-relay-retry.php';
}
require NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nip46-publisher.php';

$ACCEPT  = array('success' => true, 'ok_received' => true, 'message' => '');
$PAID    = array('success' => false, 'ok_received' => true, 'error' => 'restricted: Pay on https://nostr.land for access.');
$DOWN    = array('success' => false, 'error' => 'Failed to connect to relay: WebSocket handshake failed: HTTP/1.1 503 Service Unavailable');
$SILENT  = array('success' => false, 'error' => 'Timeout waiting for relay response');
$LIMITED = array('success' => false, 'ok_received' => true, 'error' => 'rate-limited: slow down');

/* ---- 1. backoff schedule ---- */
$delays = array();
for ($i = 1; $i <= 7 && method_exists('Nostr_Publish_Record', 'retry_delay'); $i++) {
    $delays[] = Nostr_Publish_Record::retry_delay($i);
}
check('backoff 5m, 15m, 1h, 6h, then daily', $delays === array(300, 900, 3600, 21600, 86400, 86400, 86400));

/* ---- 2. classification ---- */
$cls = function ($r) { return Nostr_Publish_Record::classify_result($r)[0]; };
$okfalse = function ($why) { return array('success' => false, 'ok_received' => true, 'error' => $why); };
check('restricted -> rejected', $cls($PAID) === 'rejected');
check('blocked -> rejected', $cls($okfalse('blocked: you are banned')) === 'rejected');
check('auth-required -> rejected', $cls($okfalse('auth-required: NIP-42 auth needed')) === 'rejected');
check('invalid -> rejected', $cls($okfalse('invalid: bad signature')) === 'rejected');
check('pow -> rejected', $cls($okfalse('pow: difficulty 30 required')) === 'rejected');
check('rate-limited -> unreachable (retry)', $cls($LIMITED) === 'unreachable');
check('error: -> unreachable (retry)', $cls($okfalse('error: database busy')) === 'unreachable');
check('503 handshake -> unreachable', $cls($DOWN) === 'unreachable');
check('timeout -> unreachable', $cls($SILENT) === 'unreachable');
check('duplicate -> accepted', $cls($okfalse('duplicate: have it')) === 'accepted');

// The rest needs the sweep class.
if (!class_exists('Nostr_Relay_Retry')) {
    check('Nostr_Relay_Retry exists', false);
    finish('RELAY-RETRY');
}

/* ---- 3. first publish: one relay accepts, post is synced ---- */
$A = 'wss://a.example'; $B = 'wss://paid.example'; $C = 'wss://down.example'; $D = 'wss://busy.example';
Nostr_Client::$relays = array($A, $B, $C, $D);
Nostr_Client::$replies = array($A => $ACCEPT, $B => $PAID, $C => $DOWN, $D => $LIMITED);

$publisher = Nostr_NIP46_Publisher::get_instance();
$retry = Nostr_Relay_Retry::get_instance();
$t0 = time();
$GLOBALS['__posts'][1] = (object) array('ID' => 1, 'post_type' => 'note', 'post_status' => 'publish', 'post_date_gmt' => gmdate('Y-m-d H:i:s', $t0));
$publisher->handle_post_publish('publish', 'draft', $GLOBALS['__posts'][1]);
$r = $publisher->process_post(1);
$event_id = get_post_meta(1, '_nostr_event_id', true);
$signs = Nostr_NIP46_Client::$sign_calls;
check('post synced after one relay accepted', $r['success'] && get_post_meta(1, '_nostr_sync_status', true) === 'synced');
$next = (int) get_post_meta(1, '_nostr_relay_next_retry', true);
check('retry scheduled ~5 min out', $next >= $t0 + 300 && $next <= time() + 300);
$t = Nostr_Publish_Record::get_relay_table(1);
check('paid relay rejected, not scheduled', $t[$B]['state'] === 'rejected' && $t[$B]['next_retry'] === 0);
check('rejection reason surfaced', strpos($t[$B]['message'], 'restricted:') === 0);

/* ---- 4. sweep before due does nothing ---- */

Nostr_Client::$published = array();
$s = $retry->sweep($t0 + 100);
check('nothing due at +100s', $s['attempts'] === 0 && count(Nostr_Client::$published) === 0);

/* ---- 5. sweep when due: only the unreachable relays, same event ---- */
$now = $next + 1;
$s = $retry->sweep($now);
check('sweep retried 2 relays', $s['attempts'] === 2);
// Retries go out one relay per publish_event() call so the time budget is
// checked between relays; gather every relay this sweep contacted.
$sent = array(Nostr_Client::$published[0][0], array());
foreach (Nostr_Client::$published as $call) {
    $sent[1] = array_merge($sent[1], $call[1]);
}
sort($sent[1]);
check('sweep picked exactly the down + rate-limited relays', $sent[1] === array($D, $C) || $sent[1] === array($C, $D));
check('accepted and rejected relays not retried', !in_array($A, $sent[1], true) && !in_array($B, $sent[1], true));
check('same event id republished (no re-sign)', $sent[0] === $event_id);
check('bunker not asked to sign again', Nostr_NIP46_Client::$sign_calls === $signs);
$t = Nostr_Publish_Record::get_relay_table(1);
check('second failure -> next try +15 min', $t[$C]['attempts'] === 2 && $t[$C]['next_retry'] === $now + 900);

/* ---- 6. busy relay accepts on a later pass; down relay walks the backoff ---- */
Nostr_Client::$replies[$D] = $ACCEPT;
$expect = array(3 => 3600, 4 => 21600, 5 => 86400, 6 => 86400);
foreach ($expect as $attempt => $delay) {
    $now = (int) get_post_meta(1, '_nostr_relay_next_retry', true);
    $retry->sweep($now);
    $t = Nostr_Publish_Record::get_relay_table(1);
    check("attempt $attempt on down relay -> next +{$delay}s", $t[$C]['attempts'] === $attempt && $t[$C]['next_retry'] === $now + $delay);
}
check('busy relay now accepted and no longer retried', $t[$D]['state'] === 'accepted' && $t[$D]['next_retry'] === 0);
check('summary now 2 of 4', Nostr_Publish_Record::summary(1) === array('accepted' => 2, 'total' => 4));
$ids = array_unique(array_map(function ($p) { return $p[0]; }, Nostr_Client::$published));
check('every retry used the original event id', $ids === array($event_id));

/* ---- 7. give up 7 days after the first attempt ---- */
$first = $t[$C]['first_attempt'];
$guard = 0;
while (get_post_meta(1, '_nostr_relay_next_retry', true) !== '' && $guard++ < 20) {
    $retry->sweep((int) get_post_meta(1, '_nostr_relay_next_retry', true));
}
$t = Nostr_Publish_Record::get_relay_table(1);
check('down relay expired after 7 days', !empty($t[$C]['expired']) && $t[$C]['last_attempt'] - $first < 7 * 86400 + 86400);
check('no more retries scheduled for the post', get_post_meta(1, '_nostr_relay_next_retry', true) === '');
check('expired relay keeps its state (shown as given up)', $t[$C]['state'] === 'unreachable');
Nostr_Client::$published = array();
$retry->sweep(time() + 30 * 86400);
check('nothing republished after giving up', count(Nostr_Client::$published) === 0);

/* ---- 8. posts no relay accepted are left to the bunker queue ---- */
Nostr_Client::$replies = array($A => $DOWN, $B => $PAID, $C => $DOWN, $D => $DOWN);
$GLOBALS['__posts'][2] = (object) array('ID' => 2, 'post_type' => 'note', 'post_status' => 'publish', 'post_date_gmt' => gmdate('Y-m-d H:i:s'));
$publisher->handle_post_publish('publish', 'draft', $GLOBALS['__posts'][2]);
$publisher->process_post(2);
check('unpublished post not in the relay sweep', get_post_meta(2, '_nostr_relay_next_retry', true) === '');

/* ---- 9. batch limits ---- */
Nostr_Client::$replies = array($A => $ACCEPT, $B => $DOWN, $C => $DOWN, $D => $SILENT);
for ($id = 10; $id < 30; $id++) {
    $GLOBALS['__posts'][$id] = (object) array('ID' => $id, 'post_type' => 'note', 'post_status' => 'publish', 'post_date_gmt' => gmdate('Y-m-d H:i:s'));
    $publisher->handle_post_publish('publish', 'draft', $GLOBALS['__posts'][$id]);
    $publisher->process_post($id);
}
Nostr_Client::$published = array();
$s = $retry->sweep(time() + 3600);
$tried = 0;
foreach (Nostr_Client::$published as $p) { $tried += count($p[1]); }
check('one sweep makes at most MAX_RELAY_ATTEMPTS attempts', $tried === Nostr_Relay_Retry::MAX_RELAY_ATTEMPTS && $s['attempts'] === $tried);
check('earliest-due posts first', Nostr_Client::$published[0][0] === Nostr_Publish_Record::get_event(10)['id']);

/* ---- 10. lock guard ---- */
set_transient(Nostr_Relay_Retry::LOCK, 1);
Nostr_Client::$published = array();
$s = $retry->sweep(time() + 7200);
check('locked sweep does nothing', !empty($s['locked']) && count(Nostr_Client::$published) === 0);
delete_transient(Nostr_Relay_Retry::LOCK);

/* ---- 11. relay removed from the list is not retried ---- */
Nostr_Client::$relays = array($A, $C, $D); // $B removed
Nostr_Client::$published = array();
for ($i = 0; $i < 10; $i++) { $retry->sweep(time() + 7200 + $i); }
$hit_b = false;
foreach (Nostr_Client::$published as $p) { if (in_array($B, $p[1], true)) $hit_b = true; }
check('removed relay never retried', !$hit_b && count(Nostr_Client::$published) > 0);

/* ---- 12. relay added later: recent posts with a stored event pick it up ---- */
$E = 'wss://new.example';
$GLOBALS['__posts'][99] = (object) array('ID' => 99, 'post_type' => 'note', 'post_status' => 'publish', 'post_date_gmt' => gmdate('Y-m-d H:i:s', time() - 40 * 86400));
Nostr_Client::$relays = array($A);
Nostr_Client::$replies = array($A => $ACCEPT, $E => $ACCEPT);
$publisher->handle_post_publish('publish', 'draft', $GLOBALS['__posts'][99]);
$publisher->process_post(99);
$old_opts = array('relays' => array($A, $C, $D));
$new_opts = array('relays' => array($A, $C, $D, $E . '/'));
Nostr_Client::$relays = array($A, $C, $D, $E);
$retry->on_options_updated($old_opts, $new_opts);
check('added relay queued for adoption', get_option(Nostr_Relay_Retry::ADOPT_OPTION)['relays'] === array($E));
Nostr_Client::$published = array();
$s = $retry->sweep(time() + 8000);
check('adoption touched recent posts', $s['adopted'] >= 20);
check('post older than 30 days not adopted', !isset(Nostr_Publish_Record::get_relay_table(99)[$E]));
check('new relay pending on a recent post', isset(Nostr_Publish_Record::get_relay_table(1)[$E]));
$guard = 0;
while (get_post_meta(1, '_nostr_relay_next_retry', true) !== '' && $guard++ < 30) {
    $retry->sweep(time() + 9000 + $guard);
}
$t = Nostr_Publish_Record::get_relay_table(1);
check('new relay got the original event and accepted it', $t[$E]['state'] === 'accepted');
check('still the original event id', Nostr_Publish_Record::get_event(1)['id'] === $event_id);
check('adoption queue drained', get_option(Nostr_Relay_Retry::ADOPT_OPTION, 'gone') === 'gone');

/* ---- 13. relay health aggregation ---- */
$statuses = array();
foreach (array(1, 10, 11) as $id) {
    $statuses[] = json_decode(get_post_meta($id, '_nostr_relay_status', true), true);
}
$h = Nostr_Publish_Record::relay_health($statuses, array($A, 'wss://unused.example'));
check('health: A accepted on all 3', $h[$A]['accepted'] === 3 && $h[$A]['last_success'] > 0);
check('health: paid relay rejected with reason', $h[$B]['rejected'] === 1 && strpos($h[$B]['reject_reason'], 'restricted:') === 0);
check('health: last problem is the most recent one', strpos($h[$B]['last_reason'], 'Failed to connect') === 0);
check('health: expired counted', $h[$C]['expired'] === 1);
check('health: configured relay with no data listed', isset($h['wss://unused.example']) && $h['wss://unused.example']['in_list']);
check('health: relay out of the list flagged', $h[$B]['in_list'] === false);

/* ---- 13b. forget a dropped relay ---- */
check('forget: still-configured relay refused', Nostr_Publish_Record::forget_relay($A) === false);
$before = Nostr_Publish_Record::get_event(1)['id'];
$n = Nostr_Publish_Record::forget_relay($B);
check('forget: dropped relay removed from posts', $n >= 1 && !isset(Nostr_Publish_Record::get_relay_table(1)[$B]));
check('forget: other relays and the signed event untouched', isset(Nostr_Publish_Record::get_relay_table(1)[$A]) && Nostr_Publish_Record::get_event(1)['id'] === $before);
check('forget: idempotent', Nostr_Publish_Record::forget_relay($B) === 0);

/* ---- 14. called the way WP-Cron calls it: do_action(hook) passes '' ---- */
Nostr_Client::$relays = array($A, $C);
Nostr_Client::$replies = array($A => $ACCEPT, $C => $DOWN);
$GLOBALS['__posts'][200] = (object) array('ID' => 200, 'post_type' => 'note', 'post_status' => 'publish', 'post_date_gmt' => gmdate('Y-m-d H:i:s'));
$publisher->handle_post_publish('publish', 'draft', $GLOBALS['__posts'][200]);
$publisher->process_post(200);
$ev200 = Nostr_Publish_Record::get_event(200);
// Pretend that attempt was 20 minutes ago so the down relay is due now.
foreach (array_keys($GLOBALS['__meta']) as $pid) {
    if ($pid !== 200) delete_post_meta($pid, '_nostr_relay_next_retry');
}
Nostr_Publish_Record::record_results(200, $ev200, array($C => $DOWN), 'bunker', time() - 1200);
Nostr_Client::$published = array();
$s = $retry->sweep('');
check("sweep('') from cron retries what is due now", $s['attempts'] === 1 && Nostr_Client::$published[0][1] === array($C));

/* ---- 15. never re-signed anywhere above ---- */
$expected_signs = 1 /* post 1 */ + 1 /* post 2 */ + 20 /* batch posts */ + 1 /* post 99 */ + 1 /* post 200 */;
check('bunker signed once per post, never for retries', Nostr_NIP46_Client::$sign_calls === $expected_signs);

/* ---- time budget is enforced between relays, not just between posts ---- */
$GLOBALS['__meta'] = array(); $GLOBALS['__posts'] = array(); $GLOBALS['__cron'] = array(); $GLOBALS['__transients'] = array();
Nostr_Client::$published = array();
Nostr_Client::$relays = array($A, $B, $C, $D);
$GLOBALS['__posts'][70] = (object) array('ID' => 70, 'post_type' => 'note', 'post_status' => 'publish', 'post_date_gmt' => gmdate('Y-m-d H:i:s', $t0));
Nostr_Client::$replies = array($A => $ACCEPT, $B => $DOWN, $C => $DOWN, $D => $DOWN);
$publisher->handle_post_publish('publish', 'draft', $GLOBALS['__posts'][70]);
$publisher->process_post(70);
Nostr_Client::$published = array();
// Every call to the relay client burns the whole budget.
$retry = Nostr_Relay_Retry::get_instance();
$ref = new ReflectionProperty('Nostr_Relay_Retry', 'sweep_started');
$ref->setAccessible(true);
$ref->setValue($retry, microtime(true) - Nostr_Relay_Retry::TIME_BUDGET - 1); // budget already gone
$tried = $retry->retry_post(70, $t0 + 400, 6);
$ref->setValue($retry, 0.0);
check('exhausted budget still sends to the first due relay', $tried === 1 && count(Nostr_Client::$published) === 1);
check('but stops before the second relay', count(Nostr_Client::$published[0][1]) === 1);
Nostr_Client::$on_publish = null;

finish('RELAY-RETRY');
