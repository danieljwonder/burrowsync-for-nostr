<?php
/**
 * NIP-46 Publisher
 *
 * Server-side outbound publishing pipeline used when the signing method is
 * "Remote signer / bunker (NIP-46)". Purely additive: when the signing
 * method is NIP-07 (the default) none of these hooks change behaviour.
 *
 * Flow:
 *   publish / scheduled publish (WP-Cron)
 *     -> queue_post(): mark post queued, schedule immediate cron attempt
 *     -> process_post(): build event (same Content Mapper the NIP-07 path
 *        uses) -> bunker signs it (Nostr_NIP46_Client) -> publish signed
 *        event to the configured relays -> record event id
 *
 * Failure handling: a WordPress publish NEVER fails because of Nostr. If
 * the bunker is unreachable or locked (common after a reboot until the
 * operator unlocks it) the post stays queued and WP-Cron retries with
 * backoff: 5 min, 15 min, 60 min, then hourly, giving up 24h after the
 * first failure. A manual "Retry now" action is available per post.
 *
 * Idempotency: the signed event id is recorded per post and a post whose
 * status is already "synced" is never re-signed or re-published. If
 * signing succeeded but no relay accepted the event, the signed event is
 * kept and re-published verbatim on retry, so a post can never produce
 * two different signed events from this pipeline.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_NIP46_Publisher {

    const CRON_HOOK = 'nostr_nip46_process_post';

    /**
     * Retry delays in seconds after the 1st, 2nd, 3rd... failed attempt.
     * Beyond the list, retries continue hourly until MAX_RETRY_WINDOW.
     */
    const RETRY_SCHEDULE = array(300, 900, 3600);

    /**
     * Stop automatic retries this long after the first failure (seconds).
     */
    const MAX_RETRY_WINDOW = DAY_IN_SECONDS;

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * @var Nostr_NIP46_Settings
     */
    private $settings;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = Nostr_NIP46_Settings::get_instance();
        $this->init_hooks();
    }

    private function init_hooks() {
        // Covers both interactive publishes and scheduled posts: WP-Cron
        // transitions future -> publish through the same hook.
        add_action('transition_post_status', array($this, 'handle_post_publish'), 20, 3);

        // The legacy NIP-07 flow marks every saved post 'pending' for the
        // browser signer on save_post (priority 10). In bunker mode that
        // would clobber our queue state and re-expose posts to the browser
        // flow, so re-assert the NIP-46 state afterwards (priority 30).
        add_action('save_post', array($this, 'reassert_queue_meta'), 30, 2);

        // Queue processor (single events, one per post).
        add_action(self::CRON_HOOK, array($this, 'process_post'));

        // Manual "Retry now" from the admin.
        add_action('wp_ajax_nostr_nip46_retry_post', array($this, 'retry_post_ajax'));
    }

    /* ---------------------------------------------------------------------
     * Queueing
     * ------------------------------------------------------------------ */

    /**
     * transition_post_status handler.
     */
    public function handle_post_publish($new_status, $old_status, $post) {
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }
        if (!in_array($post->post_type, array('post', 'note'), true)) {
            return;
        }
        if (wp_is_post_autosave($post->ID) || wp_is_post_revision($post->ID)) {
            return;
        }
        if (!$this->settings->is_bunker_active()) {
            return; // NIP-07 mode: leave the existing flow untouched.
        }

        // Respect the per-post sync toggle. On a brand-new post the meta
        // may not be saved yet (sync defaults to enabled for new posts).
        $sync_enabled = get_post_meta($post->ID, '_nostr_sync_enabled', true);
        if ($sync_enabled === '0') {
            return;
        }

        // Never echo back content that came FROM Nostr.
        if (get_post_meta($post->ID, '_nostr_origin', true)) {
            return;
        }

        // Idempotency: already published to Nostr.
        if ($this->is_already_published($post->ID)) {
            return;
        }

        $this->queue_post($post->ID);
    }

    /**
     * save_post (late): keep NIP-46 queue state authoritative in bunker
     * mode. No-op when the signing method is NIP-07.
     */
    public function reassert_queue_meta($post_id, $post) {
        if (!$this->settings->is_bunker_active()) {
            return;
        }
        if (!in_array($post->post_type, array('post', 'note'), true)) {
            return;
        }
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        if (wp_next_scheduled(self::CRON_HOOK, array($post_id))) {
            update_post_meta($post_id, '_nostr_sync_status', 'queued');
            update_post_meta($post_id, '_nostr_sync_pending', '0');
        } elseif (get_post_meta($post_id, '_nostr_event_id', true)) {
            // Already published to Nostr; don't let an edit re-queue it
            // for the browser signer (idempotency).
            update_post_meta($post_id, '_nostr_sync_status', 'synced');
            update_post_meta($post_id, '_nostr_sync_pending', '0');
        }
    }

    /**
     * Mark a post queued and schedule an immediate processing attempt.
     */
    public function queue_post($post_id) {
        update_post_meta($post_id, '_nostr_sync_status', 'queued');
        update_post_meta($post_id, '_nostr_sync_pending', '0'); // not for the browser flow
        delete_post_meta($post_id, '_nostr_nip46_last_error');

        $this->schedule_attempt($post_id, time());

        // Nudge WP-Cron so interactive publishes go out within seconds
        // instead of waiting for the next page load.
        if (!defined('DOING_CRON') || !DOING_CRON) {
            spawn_cron();
        }
    }

    /**
     * Schedule (or reschedule) the cron attempt for a post.
     */
    private function schedule_attempt($post_id, $timestamp) {
        wp_clear_scheduled_hook(self::CRON_HOOK, array($post_id));
        wp_schedule_single_event($timestamp, self::CRON_HOOK, array($post_id));
        update_post_meta($post_id, '_nostr_nip46_next_retry', $timestamp);
    }

    /* ---------------------------------------------------------------------
     * Processing
     * ------------------------------------------------------------------ */

    /**
     * Attempt to sign + publish one queued post. Cron callback and the
     * workhorse behind "Retry now".
     *
     * @param int $post_id
     * @return array{success: bool, message: string}
     */
    public function process_post($post_id) {
        $post_id = intval($post_id);
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return array('success' => false, 'message' => __('Post is not published.', 'burrowsync-for-nostr'));
        }

        if ($this->is_already_published($post_id)) {
            return array('success' => true, 'message' => __('Already published to Nostr.', 'burrowsync-for-nostr'));
        }

        if (!$this->settings->is_bunker_active()) {
            return array('success' => false, 'message' => __('Bunker signing is not configured.', 'burrowsync-for-nostr'));
        }

        // Concurrency guard: cron and a manual retry could race.
        $lock_key = 'nostr_nip46_lock_' . $post_id;
        if (get_transient($lock_key)) {
            return array('success' => false, 'message' => __('A publish attempt is already in progress.', 'burrowsync-for-nostr'));
        }
        set_transient($lock_key, 1, 2 * Nostr_NIP46_Client::RPC_TIMEOUT + 30);

        try {
            $signed = $this->get_or_create_signed_event($post);
            $results = Nostr_Client::get_instance()->publish_event($signed);

            $accepted = false;
            foreach ($results as $result) {
                if (!empty($result['success'])) {
                    $accepted = true;
                    break;
                }
            }

            if (!$accepted) {
                throw new Exception(__('No relay accepted the event.', 'burrowsync-for-nostr'));
            }

            $this->mark_published($post_id, $signed['id']);
            return array('success' => true, 'message' => __('Published to Nostr.', 'burrowsync-for-nostr'));

        } catch (Nostr_NIP46_Auth_Required_Exception $e) {
            $message = $e->getMessage() . ' ' . $e->auth_url;
            $this->record_failure($post_id, $message);
            return array('success' => false, 'message' => $message);

        } catch (Exception $e) {
            // Exception messages from the NIP-46 stack never contain the
            // bunker secret or client key; still sanitise before storing.
            $message = sanitize_text_field($e->getMessage());
            $this->record_failure($post_id, $message);
            return array('success' => false, 'message' => $message);

        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * Build the event and have the bunker sign it — or reuse a previously
     * signed event whose relay publication failed (exactly-once signing).
     *
     * @param WP_Post $post
     * @return array signed event
     * @throws Exception
     */
    private function get_or_create_signed_event($post) {
        $stored = get_post_meta($post->ID, '_nostr_nip46_signed_event', true);
        if ($stored) {
            $signed = json_decode($stored, true);
            if (is_array($signed) && !empty($signed['id']) && !empty($signed['sig']) &&
                Nostr_NIP46_Crypto::event_id($signed) === $signed['id'] &&
                Nostr_NIP46_Crypto::schnorr_verify($signed['id'], $signed['sig'], $signed['pubkey'])) {
                return $signed;
            }
            delete_post_meta($post->ID, '_nostr_nip46_signed_event');
        }

        // Build the event exactly as the NIP-07 path does.
        $content_mapper = Nostr_Content_Mapper::get_instance();
        if ($post->post_type === 'note') {
            $event = $content_mapper->note_to_nostr_event($post->ID);
        } else {
            $event = $content_mapper->post_to_nostr_event($post->ID);
        }
        if (!$event) {
            throw new Exception(__('Could not build a Nostr event for this post (is sync enabled?).', 'burrowsync-for-nostr'));
        }
        // Match the NIP-07 signing flow, which stamps the signing time.
        $event['created_at'] = time();

        $client = new Nostr_NIP46_Client($this->settings);
        $signed = $client->sign_event($event);

        // Persist so a relay outage cannot lead to signing a second,
        // different event for the same post.
        update_post_meta($post->ID, '_nostr_nip46_signed_event', wp_json_encode($signed));

        return $signed;
    }

    /**
     * @return bool whether the post already has a published Nostr event
     */
    private function is_already_published($post_id) {
        return get_post_meta($post_id, '_nostr_sync_status', true) === 'synced'
            && get_post_meta($post_id, '_nostr_event_id', true) !== '';
    }

    private function mark_published($post_id, $event_id) {
        update_post_meta($post_id, '_nostr_event_id', $event_id);
        update_post_meta($post_id, '_nostr_sync_status', 'synced');
        update_post_meta($post_id, '_nostr_synced_at', current_time('mysql'));
        update_post_meta($post_id, '_nostr_sync_pending', '0');
        delete_post_meta($post_id, '_nostr_nip46_signed_event');
        delete_post_meta($post_id, '_nostr_nip46_attempts');
        delete_post_meta($post_id, '_nostr_nip46_first_failure');
        delete_post_meta($post_id, '_nostr_nip46_next_retry');
        delete_post_meta($post_id, '_nostr_nip46_last_error');
        wp_clear_scheduled_hook(self::CRON_HOOK, array($post_id));
    }

    /**
     * Record a failed attempt and schedule the next retry with backoff.
     */
    private function record_failure($post_id, $message) {
        $attempts = intval(get_post_meta($post_id, '_nostr_nip46_attempts', true)) + 1;
        update_post_meta($post_id, '_nostr_nip46_attempts', $attempts);
        update_post_meta($post_id, '_nostr_nip46_last_error', $message);

        $first_failure = intval(get_post_meta($post_id, '_nostr_nip46_first_failure', true));
        if (!$first_failure) {
            $first_failure = time();
            update_post_meta($post_id, '_nostr_nip46_first_failure', $first_failure);
        }

        if ((time() - $first_failure) >= self::MAX_RETRY_WINDOW) {
            // Give up on automatic retries; manual "Retry now" still works.
            update_post_meta($post_id, '_nostr_sync_status', 'failed');
            delete_post_meta($post_id, '_nostr_nip46_next_retry');
            wp_clear_scheduled_hook(self::CRON_HOOK, array($post_id));
            return;
        }

        update_post_meta($post_id, '_nostr_sync_status', 'queued');
        $delay = isset(self::RETRY_SCHEDULE[$attempts - 1]) ? self::RETRY_SCHEDULE[$attempts - 1] : HOUR_IN_SECONDS;
        $this->schedule_attempt($post_id, time() + $delay);
    }

    /* ---------------------------------------------------------------------
     * Admin: manual retry + status
     * ------------------------------------------------------------------ */

    /**
     * AJAX: "Retry now" for a queued/failed post.
     */
    public function retry_post_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'burrowsync-for-nostr'));
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id || !get_post($post_id)) {
            wp_send_json_error(__('Invalid post ID', 'burrowsync-for-nostr'));
        }

        // Manual retry resets the give-up window.
        delete_post_meta($post_id, '_nostr_nip46_first_failure');
        delete_post_meta($post_id, '_nostr_nip46_attempts');

        $result = $this->process_post($post_id);
        if ($result['success']) {
            wp_send_json_success(array(
                'message'  => $result['message'],
                'event_id' => get_post_meta($post_id, '_nostr_event_id', true),
            ));
        }
        wp_send_json_error($result['message']);
    }

    /**
     * Per-post queue status for admin display.
     *
     * @return array{status: string, attempts: int, next_retry: ?int, last_error: ?string, event_id: ?string}
     */
    public function get_post_status($post_id) {
        $status = get_post_meta($post_id, '_nostr_sync_status', true) ?: 'none';
        $event_id = get_post_meta($post_id, '_nostr_event_id', true) ?: null;

        // A later edit resets _nostr_sync_status to 'pending' (legacy
        // NIP-07 bookkeeping); if an event was already published, keep
        // reporting it as published rather than "not published".
        if ($event_id && !in_array($status, array('queued', 'failed'), true)) {
            $status = 'synced';
        }

        return array(
            'status'     => $status,
            'attempts'   => intval(get_post_meta($post_id, '_nostr_nip46_attempts', true)),
            'next_retry' => intval(get_post_meta($post_id, '_nostr_nip46_next_retry', true)) ?: null,
            'last_error' => get_post_meta($post_id, '_nostr_nip46_last_error', true) ?: null,
            'event_id'   => $event_id,
        );
    }
}
