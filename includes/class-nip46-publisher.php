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

    /** Backfill: one post per tick, spaced to spare the bunker and relays. */
    const BACKFILL_HOOK         = 'nostr_nip46_backfill_tick';
    const BACKFILL_META         = '_nostr_nip46_backfill';
    const BACKFILL_TRIES_META   = '_nostr_nip46_backfill_tries';
    const BACKFILL_STATE_OPTION = 'nostr_nip46_backfill_state';
    const BACKFILL_INTERVAL     = 30;
    const BACKFILL_MAX_TRIES    = 3;

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

        // Backfill of posts missed while the bunker was unreachable.
        add_action(self::BACKFILL_HOOK, array($this, 'backfill_tick'));
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
        // Live publishes stamp the signing time, matching the NIP-07 flow.
        // Backfilled posts keep their original publish time so they slot
        // into history instead of flooding followers' feeds as new.
        $event['created_at'] = get_post_meta($post->ID, self::BACKFILL_META, true)
            ? (int) get_post_time('U', true, $post)
            : time();

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
     * Backfill (keeps original dates)
     * ------------------------------------------------------------------ */

    /**
     * Published posts and notes since $since (GMT timestamp) that never
     * reached Nostr. Excludes Nostr-origin content, posts with sync turned
     * off, and anything already carrying a Nostr event.
     *
     * @param int $since
     * @return int[] post IDs, oldest first
     */
    public function backfill_candidates($since) {
        $ids = get_posts(array(
            'post_type'        => array('post', 'note'),
            'post_status'      => 'publish',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'orderby'          => 'date',
            'order'            => 'ASC',
            'suppress_filters' => true,
            'date_query'       => array(array(
                'column'    => 'post_date_gmt',
                'after'     => gmdate('Y-m-d H:i:s', (int) $since),
                'inclusive' => true,
            )),
        ));
        $out = array();
        foreach ($ids as $id) {
            if (get_post_meta($id, '_nostr_origin', true)) {
                continue;
            }
            if (get_post_meta($id, '_nostr_sync_enabled', true) === '0') {
                continue;
            }
            if (get_post_meta($id, '_nostr_event_id', true) !== '' || $this->is_already_published($id)) {
                continue;
            }
            $out[] = (int) $id;
        }
        return $out;
    }

    /**
     * Publish time (GMT timestamp) of the newest local post or note that
     * reached Nostr, ignoring content imported from Nostr. The default
     * backfill start point. 0 when nothing has been published yet.
     *
     * @return int
     */
    public function last_published_time() {
        $ids = get_posts(array(
            'post_type'        => array('post', 'note'),
            'post_status'      => 'publish',
            'posts_per_page'   => 50,
            'fields'           => 'ids',
            'orderby'          => 'date',
            'order'            => 'DESC',
            'suppress_filters' => true,
            'meta_query'       => array(
                array('key' => '_nostr_event_id', 'value' => '', 'compare' => '!='),
            ),
        ));
        foreach ($ids as $id) {
            if (!get_post_meta($id, '_nostr_origin', true)) {
                return (int) get_post_time('U', true, $id);
            }
        }
        return 0;
    }

    /**
     * Mark candidates for backfill and start the paced queue.
     *
     * @param int $since GMT timestamp
     * @return int number of posts queued
     */
    public function start_backfill($since) {
        $ids = $this->backfill_candidates($since);
        foreach ($ids as $id) {
            update_post_meta($id, self::BACKFILL_META, '1');
            delete_post_meta($id, self::BACKFILL_TRIES_META);
            // Hand the post to the backfill queue: drop any regular retry so
            // nothing races it, and reset the give-up window.
            wp_clear_scheduled_hook(self::CRON_HOOK, array($id));
            delete_post_meta($id, '_nostr_nip46_first_failure');
            delete_post_meta($id, '_nostr_nip46_attempts');
            delete_post_meta($id, '_nostr_nip46_next_retry');
            // A cached signature from a failed live attempt carries "now" as
            // its date; re-sign with the original date instead.
            delete_post_meta($id, '_nostr_nip46_signed_event');
            update_post_meta($id, '_nostr_sync_status', 'queued');
        }
        update_option(self::BACKFILL_STATE_OPTION, array(
            'started'   => time(),
            'since'     => (int) $since,
            'total'     => count($ids),
            'published' => 0,
            'failed'    => 0,
            'running'   => !empty($ids),
        ), false);
        wp_clear_scheduled_hook(self::BACKFILL_HOOK);
        if (!empty($ids)) {
            wp_schedule_single_event(time(), self::BACKFILL_HOOK);
            if (!defined('DOING_CRON') || !DOING_CRON) {
                spawn_cron();
            }
        }
        return count($ids);
    }

    /** Stop the backfill. Posts already published stay published. */
    public function cancel_backfill() {
        wp_clear_scheduled_hook(self::BACKFILL_HOOK);
        foreach ($this->backfill_remaining_ids() as $id) {
            delete_post_meta($id, self::BACKFILL_META);
            delete_post_meta($id, self::BACKFILL_TRIES_META);
            update_post_meta($id, '_nostr_sync_status', 'failed');
        }
        $state = get_option(self::BACKFILL_STATE_OPTION, array());
        if (is_array($state)) {
            $state['running'] = false;
            $state['cancelled'] = time();
            update_option(self::BACKFILL_STATE_OPTION, $state, false);
        }
    }

    /** @return int[] posts still marked for backfill, oldest first */
    public function backfill_remaining_ids() {
        return array_map('intval', get_posts(array(
            'post_type'        => array('post', 'note'),
            'post_status'      => 'any',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'orderby'          => 'date',
            'order'            => 'ASC',
            'suppress_filters' => true,
            'meta_key'         => self::BACKFILL_META,
            'meta_value'       => '1',
        )));
    }

    /** @return array backfill state plus remaining count */
    public function backfill_state() {
        $state = get_option(self::BACKFILL_STATE_OPTION, array());
        $state = is_array($state) ? $state : array();
        $state['remaining'] = count($this->backfill_remaining_ids());
        return $state;
    }

    /**
     * Cron: publish the oldest backfill post, then schedule the next tick.
     */
    public function backfill_tick() {
        // Cron and the admin page pump can both call this; one at a time.
        $lock = 'nostr_nip46_backfill_lock';
        if (get_transient($lock)) {
            return;
        }
        set_transient($lock, 1, 2 * Nostr_NIP46_Client::RPC_TIMEOUT + 60);
        try {
            $this->backfill_tick_unlocked();
        } finally {
            delete_transient($lock);
        }
    }

    /**
     * Run the next backfill step now if it is due. Called by the admin
     * progress poll so the queue keeps moving on hosts where WP-Cron only
     * runs every few minutes (e.g. server cron with DISABLE_WP_CRON).
     */
    public function backfill_pump() {
        $state = get_option(self::BACKFILL_STATE_OPTION, array());
        if (!is_array($state) || empty($state['running'])) {
            return;
        }
        $next = wp_next_scheduled(self::BACKFILL_HOOK);
        if ($next && $next > time()) {
            return;
        }
        wp_clear_scheduled_hook(self::BACKFILL_HOOK);
        $this->backfill_tick();
        // If another runner held the lock, make sure a tick stays scheduled.
        $state = get_option(self::BACKFILL_STATE_OPTION, array());
        if (is_array($state) && !empty($state['running']) && !wp_next_scheduled(self::BACKFILL_HOOK)) {
            wp_schedule_single_event(time() + self::BACKFILL_INTERVAL, self::BACKFILL_HOOK);
        }
    }

    private function backfill_tick_unlocked() {
        $remaining = $this->backfill_remaining_ids();
        $state = get_option(self::BACKFILL_STATE_OPTION, array());
        $state = is_array($state) ? $state : array();

        if (empty($remaining)) {
            $state['running'] = false;
            $state['finished'] = time();
            update_option(self::BACKFILL_STATE_OPTION, $state, false);
            return;
        }

        $id = $remaining[0];
        $post = get_post($id);
        if (!$post || $post->post_status !== 'publish' || get_post_meta($id, '_nostr_origin', true)) {
            delete_post_meta($id, self::BACKFILL_META);
        } else {
            $result = $this->process_post($id);
            // process_post schedules its own backoff on failure; the backfill
            // queue owns retries for these posts, so drop that.
            wp_clear_scheduled_hook(self::CRON_HOOK, array($id));

            if (!empty($result['success'])) {
                delete_post_meta($id, self::BACKFILL_META);
                delete_post_meta($id, self::BACKFILL_TRIES_META);
                $state['published'] = intval($state['published'] ?? 0) + 1;
            } else {
                $tries = intval(get_post_meta($id, self::BACKFILL_TRIES_META, true)) + 1;
                update_post_meta($id, self::BACKFILL_TRIES_META, $tries);
                if ($tries >= self::BACKFILL_MAX_TRIES) {
                    delete_post_meta($id, self::BACKFILL_META);
                    delete_post_meta($id, self::BACKFILL_TRIES_META);
                    update_post_meta($id, '_nostr_sync_status', 'failed');
                    $state['failed'] = intval($state['failed'] ?? 0) + 1;
                }
                $state['last_error'] = isset($result['message']) ? sanitize_text_field($result['message']) : '';
            }
        }

        $state['running'] = !empty($this->backfill_remaining_ids());
        if (!$state['running']) {
            $state['finished'] = time();
        }
        update_option(self::BACKFILL_STATE_OPTION, $state, false);

        if ($state['running']) {
            wp_clear_scheduled_hook(self::BACKFILL_HOOK);
            wp_schedule_single_event(time() + self::BACKFILL_INTERVAL, self::BACKFILL_HOOK);
        }
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
