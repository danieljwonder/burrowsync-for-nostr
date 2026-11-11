<?php
/**
 * Admin Meta Boxes
 * 
 * Handles meta boxes for post editing pages
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_Admin_Meta_Boxes {
    
    /**
     * Single instance
     */
    private static $instance = null;
    
    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_box'), 10, 2);
        add_action('wp_ajax_nostr_manual_sync', array($this, 'handle_manual_sync'));
        add_action('wp_ajax_nostr_get_sync_status', array($this, 'get_sync_status'));
    }
    
    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        // Add to posts and notes
        add_meta_box(
            'nostr-sync',
            __('Nostr Sync', 'burrowsync-for-nostr'),
            array($this, 'render_sync_meta_box'),
            array('post', 'note'),
            'side',
            'high'
        );
    }
    
    /**
     * Render sync meta box (clean HTML only)
     */
    public function render_sync_meta_box($post) {
        $sync_enabled = get_post_meta($post->ID, '_nostr_sync_enabled', true);
        $event_id = get_post_meta($post->ID, '_nostr_event_id', true);
        $synced_at = get_post_meta($post->ID, '_nostr_synced_at', true);
        $sync_status = get_post_meta($post->ID, '_nostr_sync_status', true);
        
        // Default to enabled for new posts
        if ($sync_enabled === '') {
            $sync_enabled = '1';
        }
        
        wp_nonce_field('nostr_sync_meta_box', 'nostr_sync_meta_box_nonce');
        ?>
        <div id="nostr-sync-meta-box">
            <p>
                <label>
                    <input type="checkbox" name="nostr_sync_enabled" value="1" <?php checked($sync_enabled, '1'); ?>>
                    <?php _e('Sync with Nostr', 'burrowsync-for-nostr'); ?>
                </label>
            </p>
            
            <?php if ($event_id): ?>
                <p>
                    <strong><?php _e('Event ID:', 'burrowsync-for-nostr'); ?></strong><br>
                    <code style="font-size: 11px; word-break: break-all;"><?php echo esc_html($event_id); ?></code>
                </p>
            <?php endif; ?>
            
            <?php if ($synced_at): ?>
                <p>
                    <strong><?php _e('Last Synced:', 'burrowsync-for-nostr'); ?></strong><br>
                    <?php echo esc_html($synced_at); ?>
                </p>
            <?php endif; ?>

            <?php
            // In bunker mode the "Nostr Remote Signer" box shows the relay
            // results instead, so they aren't printed twice.
            if (class_exists('Nostr_Publish_Record_Admin') && Nostr_NIP46_Settings::get_instance()->get_signing_method() !== 'nip46') {
                Nostr_Publish_Record_Admin::render_post_box($post->ID);
            }
            ?>
            
            <p>
                <strong><?php _e('Status:', 'burrowsync-for-nostr'); ?></strong>
                <span id="nostr-sync-status" class="nostr-sync-status nostr-sync-status-<?php echo esc_attr($sync_status ?: 'pending'); ?>">
                    <?php
                    switch ($sync_status) {
                        case 'synced':
                            echo '<span class="dashicons dashicons-yes-alt" style="color: green;"></span> ' . __('Synced', 'burrowsync-for-nostr');
                            break;
                        case 'failed':
                            echo '<span class="dashicons dashicons-dismiss" style="color: red;"></span> ' . __('Failed', 'burrowsync-for-nostr');
                            break;
                        case 'error':
                            echo '<span class="dashicons dashicons-warning" style="color: orange;"></span> ' . __('Error', 'burrowsync-for-nostr');
                            break;
                        default:
                            echo '<span class="dashicons dashicons-clock" style="color: gray;"></span> ' . __('Pending', 'burrowsync-for-nostr');
                    }
                    ?>
                </span>
            </p>
            
            <p>
                <button type="button" class="button button-primary" id="nostr-manual-sync" data-post-id="<?php echo $post->ID; ?>">
                    <?php _e('Sync Now', 'burrowsync-for-nostr'); ?>
                </button>
                <button type="button" class="button" id="nostr-refresh-status" data-post-id="<?php echo $post->ID; ?>">
                    <?php _e('Refresh Status', 'burrowsync-for-nostr'); ?>
                </button>
            </p>
            
            <div id="nostr-sync-messages"></div>
        </div>
        
        <style>
        .nostr-sync-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .nostr-sync-status .dashicons {
            font-size: 16px;
        }
        
        #nostr-sync-messages {
            margin-top: 10px;
        }
        
        .nostr-message {
            padding: 8px 12px;
            border-radius: 4px;
            margin-bottom: 5px;
        }
        
        .nostr-message.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .nostr-message.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .nostr-message.info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        </style>
        <?php
    }
    
    /**
     * Save meta box data
     */
    public function save_meta_box($post_id, $post) {
        // Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check if user has permission
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Check nonce
        if (!isset($_POST['nostr_sync_meta_box_nonce']) || !wp_verify_nonce($_POST['nostr_sync_meta_box_nonce'], 'nostr_sync_meta_box')) {
            return;
        }
        
        // Save sync enabled status
        if (isset($_POST['nostr_sync_enabled'])) {
            update_post_meta($post_id, '_nostr_sync_enabled', '1');
            update_post_meta($post_id, '_nostr_sync_pending', '1');
            update_post_meta($post_id, '_nostr_sync_status', 'pending');
        } else {
            update_post_meta($post_id, '_nostr_sync_enabled', '0');
            update_post_meta($post_id, '_nostr_sync_pending', '0');
        }
    }
    
    /**
     * Handle manual sync (legacy - now just verifies post exists)
     */
    public function handle_manual_sync() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        $post_id = intval($_POST['post_id']);
        
        if (!$post_id || !get_post($post_id)) {
            wp_send_json_error('Invalid post ID');
        }
        
        wp_send_json_success('Post verified - sync handled by browser');
    }
    
    /**
     * Get sync status
     */
    public function get_sync_status() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        $post_id = intval($_POST['post_id']);
        
        if (!$post_id || !get_post($post_id)) {
            wp_send_json_error('Invalid post ID');
        }
        
        $sync_status = get_post_meta($post_id, '_nostr_sync_status', true);
        $synced_at = get_post_meta($post_id, '_nostr_synced_at', true);
        $event_id = get_post_meta($post_id, '_nostr_event_id', true);
        
        wp_send_json_success(array(
            'status' => $sync_status ?: 'pending',
            'synced_at' => $synced_at,
            'event_id' => $event_id
        ));
    }
}