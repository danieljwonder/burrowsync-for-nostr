<?php
/**
 * Admin UI for the publish record: per-relay results in the post's Nostr
 * box and a "Download signed event (JSON)" action.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_Publish_Record_Admin {

    const DOWNLOAD_ACTION = 'nostr_download_signed_event';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_post_' . self::DOWNLOAD_ACTION, array($this, 'download_signed_event'));
    }

    /**
     * Nonce-protected download URL for a post's signed event.
     */
    public static function download_url($post_id) {
        return wp_nonce_url(
            add_query_arg(
                array('action' => self::DOWNLOAD_ACTION, 'post_id' => intval($post_id)),
                admin_url('admin-post.php')
            ),
            self::DOWNLOAD_ACTION . '_' . intval($post_id)
        );
    }

    /**
     * admin-post handler: send the stored signed event as a JSON file.
     */
    public function download_signed_event() {
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
        check_admin_referer(self::DOWNLOAD_ACTION . '_' . $post_id);

        if (!$post_id || !get_post($post_id) || !current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('You are not allowed to download this event.', 'burrowsync-for-nostr'), 403);
        }

        $json = Nostr_Publish_Record::get_event_json($post_id);
        if (!$json) {
            wp_die(esc_html__('No signed event is stored for this post.', 'burrowsync-for-nostr'), 404);
        }
        $event = json_decode($json, true);
        $filename = 'nostr-event-' . preg_replace('/[^0-9a-f]/', '', substr($event['id'], 0, 16)) . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file download, not HTML.
        exit;
    }

    /**
     * Human label for a relay state.
     */
    public static function state_label($state) {
        switch ($state) {
            case Nostr_Publish_Record::STATE_ACCEPTED:
                return __('accepted', 'burrowsync-for-nostr');
            case Nostr_Publish_Record::STATE_REJECTED:
                return __('rejected', 'burrowsync-for-nostr');
            case Nostr_Publish_Record::STATE_UNREACHABLE:
                return __('unreachable', 'burrowsync-for-nostr');
            default:
                return __('pending', 'burrowsync-for-nostr');
        }
    }

    public static function state_color($state) {
        switch ($state) {
            case Nostr_Publish_Record::STATE_ACCEPTED:
                return 'green';
            case Nostr_Publish_Record::STATE_REJECTED:
                return '#b32d2e';
            case Nostr_Publish_Record::STATE_UNREACHABLE:
                return '#996800';
            default:
                return '#787c82';
        }
    }

    /**
     * Relay results block for the post's Nostr box. Prints nothing when no
     * signed event is stored yet.
     */
    public static function render_post_box($post_id) {
        $event = Nostr_Publish_Record::get_event($post_id);
        if (!$event) {
            return;
        }
        $table   = Nostr_Publish_Record::get_relay_table($post_id);
        $summary = Nostr_Publish_Record::summary($post_id);
        ?>
        <div class="nostr-relay-results">
            <p>
                <strong><?php esc_html_e('Relays:', 'burrowsync-for-nostr'); ?></strong>
                <?php
                echo esc_html(sprintf(
                    /* translators: 1: relays that accepted the event, 2: relays in the list */
                    __('On %1$d of %2$d relays', 'burrowsync-for-nostr'),
                    $summary['accepted'],
                    $summary['total']
                ));
                ?>
            </p>
            <?php if (!empty($table)): ?>
                <ul style="margin:0 0 8px 0;font-size:12px;">
                    <?php foreach ($table as $url => $row): ?>
                        <li style="margin-bottom:4px;word-break:break-all;">
                            <code style="font-size:11px;"><?php echo esc_html($url); ?></code><br>
                            <span style="color:<?php echo esc_attr(self::state_color($row['state'])); ?>;"><?php echo esc_html(self::state_label($row['state'])); ?></span>
                            <?php if (!empty($row['attempts'])): ?>
                                &middot; <?php echo esc_html(sprintf(
                                    /* translators: %d: number of publish attempts */
                                    _n('%d attempt', '%d attempts', intval($row['attempts']), 'burrowsync-for-nostr'),
                                    intval($row['attempts'])
                                )); ?>
                            <?php endif; ?>
                            <?php if (!empty($row['last_attempt'])): ?>
                                &middot; <span title="<?php echo esc_attr(gmdate('Y-m-d H:i:s', intval($row['last_attempt'])) . ' UTC'); ?>"><?php echo esc_html(sprintf(
                                    /* translators: %s: human time difference */
                                    __('%s ago', 'burrowsync-for-nostr'),
                                    human_time_diff(intval($row['last_attempt']), time())
                                )); ?></span>
                            <?php endif; ?>
                            <?php if ($row['message'] !== '' && $row['state'] !== Nostr_Publish_Record::STATE_ACCEPTED): ?>
                                <br><span style="color:#50575e;"><?php echo esc_html($row['message']); ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (current_user_can('edit_post', $post_id)): ?>
                <p><a class="button button-small" href="<?php echo esc_url(self::download_url($post_id)); ?>"><?php esc_html_e('Download signed event (JSON)', 'burrowsync-for-nostr'); ?></a></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
