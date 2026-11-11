<?php
/**
 * Signed events are kept after a successful publish, with per-relay
 * results and an attempt log (Nostr_Publish_Record). Runs the REAL
 * Nostr_NIP46_Publisher with a local signing key and scripted relay
 * replies. Offline: no bunker, no relays.
 */

require __DIR__ . '/offline-publish-stubs.php';

if (file_exists(NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-publish-record.php')) {
    require NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-publish-record.php';
}
require NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nip46-publisher.php';
$has_record = class_exists('Nostr_Publish_Record');

$A = 'wss://relay-a.example';
$B = 'wss://paid.example';
$C = 'wss://down.example';
Nostr_Client::$relays = array($A, $B, $C . '/');  // trailing slash on purpose
Nostr_Client::$replies = array(
    $A        => array('success' => true, 'ok_received' => true, 'message' => ''),
    $B        => array('success' => false, 'ok_received' => true, 'error' => 'restricted: Pay on https://nostr.land for access.'),
    $C . '/'  => array('success' => false, 'error' => 'Failed to connect to relay wss://down.example/: WebSocket handshake failed: HTTP/1.1 503 Service Unavailable'),
);

$publisher = Nostr_NIP46_Publisher::get_instance();

/* ---- 1. successful publish keeps the exact signed event ---- */
$tricky = "Quote \"this\", back\\slash, <b>tag</b>\nnew line, emoji \u{1F407}";
Nostr_Content_Mapper::$content[1] = $tricky;
$GLOBALS['__posts'][1] = (object) array('ID' => 1, 'post_type' => 'note', 'post_status' => 'publish');
$publisher->handle_post_publish('publish', 'draft', $GLOBALS['__posts'][1]);
$r = $publisher->process_post(1);
check('publish succeeds when one relay accepts', $r['success'] === true);
check('status synced', get_post_meta(1, '_nostr_sync_status', true) === 'synced');
$event_id = get_post_meta(1, '_nostr_event_id', true);

$stored = meta_json(1, '_nostr_signed_event');
check('signed event survives a successful publish', is_array($stored));
check('stored event id = published event id', is_array($stored) && $stored['id'] === $event_id);
check('stored content byte-identical (quotes, backslash, newline, tags, emoji)', is_array($stored) && $stored['content'] === $tricky);
check('stored event still verifies (id + signature)', is_array($stored)
    && Nostr_NIP46_Crypto::event_id($stored) === $stored['id']
    && Nostr_NIP46_Crypto::schnorr_verify($stored['id'], $stored['sig'], $stored['pubkey']));
check('legacy retry key not left behind', get_post_meta(1, '_nostr_nip46_signed_event', true) === '');

/* ---- 2. per-relay states ---- */
$status = meta_json(1, '_nostr_relay_status');
check('relay status stored for this event', is_array($status) && $status['event_id'] === $event_id);
$rel = is_array($status) ? $status['relays'] : array();
check('relay A accepted', isset($rel[$A]) && $rel[$A]['state'] === 'accepted');
check('paid relay rejected (OK=false)', isset($rel[$B]) && $rel[$B]['state'] === 'rejected');
check('rejection reason kept', isset($rel[$B]) && strpos($rel[$B]['message'], 'restricted: Pay on') === 0);
check('503 relay unreachable (url normalised)', isset($rel[$C]) && $rel[$C]['state'] === 'unreachable');
check('attempts counted', isset($rel[$A]) && $rel[$A]['attempts'] === 1 && $rel[$C]['attempts'] === 1);
check('last_attempt recorded (UTC epoch)', isset($rel[$A]) && abs($rel[$A]['last_attempt'] - time()) < 60);
check('accepted_at set only for accepted', isset($rel[$A]) && $rel[$A]['accepted_at'] > 0 && $rel[$B]['accepted_at'] === 0);

if ($has_record) {
    $sum = Nostr_Publish_Record::summary(1);
    check('summary: on 1 of 3 relays', $sum['accepted'] === 1 && $sum['total'] === 3);
}

/* ---- 3. attempt log ---- */
$log = meta_json(1, '_nostr_publish_log');
check('one log entry', is_array($log) && count($log) === 1);
check('log entry lists ok/failed relays', is_array($log) && $log[0]['event_id'] === $event_id
    && $log[0]['ok'] === array($A) && count($log[0]['failed']) === 2);

if ($has_record) {
    /* ---- 4. edit re-sign: new event current, old id in the log ---- */
    $signed2 = Nostr_NIP46_Crypto::finalize_event(1, 'edited note', array(), Nostr_NIP46_Client::$priv);
    check('store_event accepts new event', Nostr_Publish_Record::store_event(1, $signed2, 'nip07'));
    check('new event is current', Nostr_Publish_Record::get_event(1)['id'] === $signed2['id']);
    $log = Nostr_Publish_Record::get_log(1);
    $last = end($log);
    check('previous event id kept in log', $last['event_id'] === $event_id && $last['note'] === 'replaced');
    $sum = Nostr_Publish_Record::summary(1);
    check('relay table reset to pending for the new event', $sum['accepted'] === 0 && $sum['total'] === 3);

    /* ---- 5. invalid events are refused ---- */
    $bad = $signed2;
    $bad['content'] = 'tampered';
    check('tampered event refused', Nostr_Publish_Record::store_event(1, $bad) === false);
    check('tampered event fails signature-aware validation', !Nostr_Publish_Record::is_valid_event($bad, true));
    $badsig = $signed2;
    $badsig['sig'] = str_repeat('0', 128);
    check('bad signature caught with verify', !Nostr_Publish_Record::is_valid_event($badsig, true));

    /* ---- 6. log capped at 20 ---- */
    for ($i = 0; $i < 30; $i++) {
        Nostr_Publish_Record::record_results(1, $signed2, array($C => array('success' => false, 'error' => 'x')), 'retry');
    }
    check('log capped at 20 entries', count(Nostr_Publish_Record::get_log(1)) === 20);
    $row = Nostr_Publish_Record::get_relay_table(1)[$C];
    check('attempts keep counting past the log cap', $row['attempts'] === 30);

    /* ---- 7. relay messages sanitised and truncated ---- */
    $long = '<script>alert(1)</script>' . str_repeat('spam ', 100);
    Nostr_Publish_Record::record_results(1, $signed2, array($B => array('success' => false, 'ok_received' => true, 'error' => $long)), 'retry');
    $msg = Nostr_Publish_Record::get_relay_table(1)[$B]['message'];
    check('message has no markup', strpos($msg, '<') === false);
    check('message truncated', mb_strlen($msg) <= Nostr_Publish_Record::MESSAGE_MAX);

    /* ---- 8. accepted stays accepted ---- */
    Nostr_Publish_Record::record_results(1, $signed2, array($A => array('success' => true, 'ok_received' => true)), 'retry');
    Nostr_Publish_Record::record_results(1, $signed2, array($A => array('success' => false, 'error' => 'timeout')), 'retry');
    check('later timeout does not un-accept a relay', Nostr_Publish_Record::get_relay_table(1)[$A]['state'] === 'accepted');
    check('discard_unaccepted refuses once a relay accepted', Nostr_Publish_Record::discard_unaccepted(1) === false);
    check('"duplicate:" OK=false counts as accepted',
        Nostr_Publish_Record::classify_result(array('success' => false, 'ok_received' => true, 'error' => 'duplicate: already have it'))[0] === 'accepted');
}

/* ---- 9. relays all fail: event stored and reused, never re-signed ---- */
Nostr_Client::$replies[$A] = array('success' => false, 'error' => 'Timeout waiting for relay response');
$GLOBALS['__posts'][2] = (object) array('ID' => 2, 'post_type' => 'post', 'post_status' => 'publish');
$publisher->handle_post_publish('publish', 'future', $GLOBALS['__posts'][2]);
$signs_before = Nostr_NIP46_Client::$sign_calls;
$r = $publisher->process_post(2);
check('no relay accepted -> failure', $r['success'] === false);
$first = meta_json(2, '_nostr_signed_event');
check('event stored even though nothing accepted', is_array($first));
Nostr_Client::$replies[$A] = array('success' => true, 'ok_received' => true);
$r = $publisher->process_post(2);
check('retry succeeds', $r['success'] === true);
check('signed exactly once across retries', Nostr_NIP46_Client::$sign_calls - $signs_before === 1);
check('same event id published on retry', is_array($first) && get_post_meta(2, '_nostr_event_id', true) === $first['id']);
check('event kept after retry success', is_array(meta_json(2, '_nostr_signed_event')));
$log = meta_json(2, '_nostr_publish_log');
check('two attempts logged', is_array($log) && count($log) === 2);

/* ---- 10. legacy in-flight key migrates without re-signing ---- */
$legacy = Nostr_NIP46_Crypto::finalize_event(1, 'legacy note', array(), Nostr_NIP46_Client::$priv);
$GLOBALS['__posts'][3] = (object) array('ID' => 3, 'post_type' => 'note', 'post_status' => 'publish');
update_post_meta(3, '_nostr_nip46_signed_event', wp_slash(json_encode($legacy)));
update_post_meta(3, '_nostr_sync_status', 'queued');
$signs_before = Nostr_NIP46_Client::$sign_calls;
$r = $publisher->process_post(3);
check('legacy event published', $r['success'] === true && get_post_meta(3, '_nostr_event_id', true) === $legacy['id']);
check('legacy event not re-signed', Nostr_NIP46_Client::$sign_calls === $signs_before);
$m = meta_json(3, '_nostr_signed_event');
check('legacy event moved to the new key', is_array($m) && $m['id'] === $legacy['id']);

finish('PUBLISH-RECORD');
