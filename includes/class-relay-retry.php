<?php
/**
 * Relay retry sweep.
 *
 * A post counts as published once one relay accepts its event (unchanged).
 * This sweep keeps sending the SAME stored signed event to every other
 * relay in the site's list until each accepts, definitively rejects, or
 * gives up after 7 days. It never signs anything.
 *
 * One recurring WP-Cron event every 5 minutes, guarded by a lock and
 * bounded per run (relay attempts and wall time) so a site with hundreds
 * of notes can't time out. The per-relay backoff lives in
 * Nostr_Publish_Record::reschedule().
 *
 * Relays added to the list later: on save, the new relays are queued for
 * posts whose event was published in the last 30 days and is still
 * stored. The sweep adds them as "pending" in small batches.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_Relay_Retry {

    const CRON_HOOK     = 'nostr_relay_retry_sweep';
    const SCHEDULE      = 'nostr_every_five_minutes';
    const LOCK          = 'nostr_relay_retry_lock';
    const ADOPT_OPTION  = 'nostr_relay_adopt_queue';

    /** Most relay publish attempts per sweep (each can take ~20 s). */
    const MAX_RELAY_ATTEMPTS = 6;
    /** Don't start another post after this many seconds. */
    const TIME_BUDGET = 25;
    /** Posts examined per sweep. */
    const BATCH_POSTS = 10;
    /** Posts handled per adoption batch when relays are added. */
    const ADOPT_BATCH = 50;
    /** Only events this recent get newly added relays. */
    const ADOPT_WINDOW = 2592000; // 30 days

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('cron_schedules', array($this, 'add_schedule'));
        add_action(self::CRON_HOOK, array($this, 'sweep'));
        add_action('update_option_nostr_for_wp_options', array($this, 'on_options_updated'), 10, 2);
        $this->maybe_schedule();
    }

    public function add_schedule($schedules) {
        $schedules[self::SCHEDULE] = array(
            'interval' => 300,
            'display'  => __('Every 5 minutes', 'burrowsync-for-nostr'),
        );
        return $schedules;
    }

    public function maybe_schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::SCHEDULE, self::CRON_HOOK);
        }
    }

    /**
     * Run one bounded pass. Safe to call from cron or manually.
     *
     * @param int|null $now for tests
     * @return array{posts: int, attempts: int, adopted: int, locked?: bool}
     */
    /** Wall-clock start of the current sweep (microtime), for the budget. */
    private $sweep_started = 0.0;

    /** Seconds left in this sweep's time budget, or the full budget outside a sweep. */
    private function budget_left() {
        if (!$this->sweep_started) {
            return (float) self::TIME_BUDGET;
        }
        return self::TIME_BUDGET - (microtime(true) - $this->sweep_started);
    }

    public function sweep($now = null) {
        // WP-Cron's do_action() passes '' when the event has no args.
        $now = is_numeric($now) && (int) $now > 0 ? (int) $now : time();
        $stats = array('posts' => 0, 'attempts' => 0, 'adopted' => 0);

        if (get_transient(self::LOCK)) {
            $stats['locked'] = true;
            return $stats;
        }
        set_transient(self::LOCK, 1, 10 * MINUTE_IN_SECONDS);

        try {
            $stats['adopted'] = $this->adopt_batch($now);

            $this->sweep_started = microtime(true);
            $budget = self::MAX_RELAY_ATTEMPTS;

            foreach ($this->due_post_ids($now) as $post_id) {
                if ($budget <= 0 || $this->budget_left() <= 0) {
                    break;
                }
                $stats['posts']++;
                $tried = $this->retry_post($post_id, $now, $budget);
                $budget -= $tried;
                $stats['attempts'] += $tried;
            }
        } finally {
            $this->sweep_started = 0.0;
            delete_transient(self::LOCK);
        }
        return $stats;
    }

    /**
     * Republish one post's stored event to its due relays.
     *
     * @return int relay attempts made
     */
    public function retry_post($post_id, $now, $max_relays) {
        $post = get_post($post_id);
        $event = Nostr_Publish_Record::get_event($post_id);

        // Only the event that is live for this post, and only verbatim.
        if (!$post || $post->post_status !== 'publish' || !$event
            || get_post_meta($post_id, '_nostr_event_id', true) !== $event['id']
            || !Nostr_Publish_Record::is_valid_event($event, true)) {
            delete_post_meta($post_id, Nostr_Publish_Record::META_NEXT_RETRY);
            return 0;
        }

        $due = array_slice(Nostr_Publish_Record::due_relays($post_id, $now), 0, max(0, (int) $max_relays));
        if (empty($due)) {
            Nostr_Publish_Record::refresh_schedule($post_id, $now);
            return 0;
        }

        // One relay at a time so the time budget is checked between relays
        // too: a single post with several slow relays can't run a sweep past
        // a host's request limit (each attempt may take ~20 s).
        $results = array();
        foreach ($due as $i => $url) {
            if ($i > 0 && $this->budget_left() <= 0) {
                break;
            }
            $results += Nostr_Client::get_instance()->publish_event($event, null, array($url));
        }
        if ($results) {
            Nostr_Publish_Record::record_results($post_id, $event, $results, 'retry', $now);
        }
        return count($results);
    }

    /**
     * Posts with a relay retry due, earliest first.
     *
     * @return int[]
     */
    public function due_post_ids($now) {
        return array_map('intval', get_posts(array(
            'post_type'        => array('post', 'note'),
            'post_status'      => 'publish',
            'posts_per_page'   => self::BATCH_POSTS,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
            'meta_key'         => Nostr_Publish_Record::META_NEXT_RETRY,
            'orderby'          => 'meta_value_num',
            'order'            => 'ASC',
            'meta_query'       => array(array(
                'key'     => Nostr_Publish_Record::META_NEXT_RETRY,
                'value'   => (int) $now,
                'compare' => '<=',
                'type'    => 'NUMERIC',
            )),
        )));
    }

    /* ---------------------------------------------------------------------
     * Relays added to the list
     * ------------------------------------------------------------------ */

    /**
     * update_option_nostr_for_wp_options: queue relays that were added.
     */
    public function on_options_updated($old, $new) {
        $added = array_diff(self::relays_from_options($new), self::relays_from_options($old));
        if (empty($added)) {
            return;
        }
        $queue = get_option(self::ADOPT_OPTION, array());
        $queue = is_array($queue) ? $queue : array();
        $relays = isset($queue['relays']) && is_array($queue['relays']) ? $queue['relays'] : array();
        update_option(self::ADOPT_OPTION, array(
            'relays' => array_values(array_unique(array_merge($relays, $added))),
            'since'  => time() - self::ADOPT_WINDOW,
            'page'   => 1,
        ), false);
    }

    /** Same precedence as Nostr_Client::get_user_relays(). */
    public static function relays_from_options($options) {
        $options = is_array($options) ? $options : array();
        $list = array();
        if (!empty($options['relays']) && is_array($options['relays'])) {
            $list = $options['relays'];
        } elseif (!empty($options['default_relays']) && is_array($options['default_relays'])) {
            $list = $options['default_relays'];
        }
        $out = array();
        foreach ($list as $url) {
            if (is_string($url) && preg_match('#^wss?://#i', trim($url))) {
                $out[] = Nostr_Publish_Record::normalize_url($url);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Add queued relays to one page of recent posts with a stored event.
     *
     * @return int posts that gained relays
     */
    public function adopt_batch($now) {
        $queue = get_option(self::ADOPT_OPTION, array());
        if (!is_array($queue) || empty($queue['relays'])) {
            return 0;
        }
        $page = max(1, intval(isset($queue['page']) ? $queue['page'] : 1));
        $ids = get_posts(array(
            'post_type'        => array('post', 'note'),
            'post_status'      => 'publish',
            'posts_per_page'   => self::ADOPT_BATCH,
            'paged'            => $page,
            'fields'           => 'ids',
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'no_found_rows'    => true,
            'suppress_filters' => true,
            'meta_key'         => Nostr_Publish_Record::META_EVENT,
            'meta_compare'     => 'EXISTS',
            'date_query'       => array(array(
                'column'    => 'post_date_gmt',
                'after'     => gmdate('Y-m-d H:i:s', intval($queue['since'])),
                'inclusive' => true,
            )),
        ));

        $touched = 0;
        foreach ($ids as $id) {
            $event = Nostr_Publish_Record::get_event($id);
            if (!$event || get_post_meta($id, '_nostr_event_id', true) !== $event['id']) {
                continue;
            }
            if (Nostr_Publish_Record::adopt_relays($id, $queue['relays'], $now)) {
                $touched++;
            }
        }

        if (count($ids) < self::ADOPT_BATCH) {
            delete_option(self::ADOPT_OPTION);
        } else {
            $queue['page'] = $page + 1;
            update_option(self::ADOPT_OPTION, $queue, false);
        }
        return $touched;
    }

    /* ---------------------------------------------------------------------
     * Health
     * ------------------------------------------------------------------ */

    /**
     * Per-relay health across the most recent relay tables.
     *
     * @param int $limit posts to look at
     * @return array<string, array>
     */
    public static function relay_health($limit = 1000) {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id DESC LIMIT %d",
            Nostr_Publish_Record::META_RELAYS,
            (int) $limit
        ));
        $statuses = array();
        foreach ((array) $rows as $raw) {
            $data = json_decode((string) $raw, true);
            if (is_array($data)) {
                $statuses[] = $data;
            }
        }
        return Nostr_Publish_Record::relay_health($statuses, Nostr_Publish_Record::configured_relays());
    }
}
