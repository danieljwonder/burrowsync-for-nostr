<?php
/**
 * Admin Settings Page
 * 
 * Handles the Nostr settings page in WordPress admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_Admin_Settings {
    
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
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_nostr_save_public_key', array($this, 'save_public_key_ajax'));
        add_action('wp_ajax_nostr_disconnect', array($this, 'disconnect_ajax'));
        add_action('wp_ajax_nostr_test_relay', array($this, 'test_relay_ajax'));
        add_action('wp_ajax_nostr_save_relays', array($this, 'save_relays_ajax'));
        add_action('wp_ajax_nostr_force_sync', array($this, 'force_sync_ajax'));
        add_action('wp_ajax_nostr_get_pending_posts', array($this, 'get_pending_posts_ajax'));
        add_action('wp_ajax_nostr_get_post_event', array($this, 'get_post_event_ajax'));
        add_action('wp_ajax_nostr_publish_event', array($this, 'publish_event_ajax'));
        add_action('wp_ajax_nostr_mark_post_synced', array($this, 'mark_post_synced_ajax'));
        add_action('wp_ajax_nostr_save_nip05', array($this, 'save_nip05_ajax'));
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('nostr_for_wp_settings', 'nostr_for_wp_options', array(
            'sanitize_callback' => array($this, 'sanitize_options')
        ));
    }
    
    /**
     * Sanitize options
     */
    public function sanitize_options($options) {
        // Get existing options to preserve fields not in the form (like public_key, relays)
        $existing_options = get_option('nostr_for_wp_options', array());
        
        // Merge new options with existing to preserve non-form fields
        $options = array_merge($existing_options, $options);
        
        if (isset($options['default_relays'])) {
            $options['default_relays'] = array_filter($options['default_relays'], function($relay) {
                return filter_var($relay, FILTER_VALIDATE_URL) && 
                       (strpos($relay, 'wss://') === 0 || strpos($relay, 'ws://') === 0);
            });
        }
        
        if (isset($options['sync_interval'])) {
            $options['sync_interval'] = max(60, intval($options['sync_interval']));
        }
        
        // Explicitly handle unchecked checkbox - if not present, set to false
        // But only if it was actually submitted (check $_POST directly)
        if (isset($_POST['nostr_for_wp_options']['auto_sync_enabled'])) {
            $options['auto_sync_enabled'] = (bool) $_POST['nostr_for_wp_options']['auto_sync_enabled'];
        } elseif (!isset($options['auto_sync_enabled'])) {
            // If not submitted and doesn't exist, default to true
            $options['auto_sync_enabled'] = true;
        }
        // Otherwise keep existing value

        if (isset($_POST['nostr_for_wp_display_settings'])) {
            $options['embed_inline_urls'] = !empty($_POST['nostr_for_wp_options']['embed_inline_urls']);
            $options['show_note_provenance'] = !empty($_POST['nostr_for_wp_options']['show_note_provenance']);
        }
        
        return $options;
    }
    
    /**
     * Render settings page
     */
    public static function render_settings_page() {
        $nip07_handler = Nostr_NIP07_Handler::get_instance();
        $cron_handler = Nostr_Cron_Handler::get_instance();
        
        $connection_status = $nip07_handler->get_connection_status();
        $cron_status = $cron_handler->get_cron_status();
        $sync_stats = $cron_handler->get_sync_stats();
        
        ?>
        <div class="wrap">
            <h1><?php _e('Nostr Settings', 'burrowsync-for-nostr'); ?></h1>
            
            <div id="nostr-admin-messages"></div>
            
            <div class="nostr-admin-container">
                <!-- Connection Status -->
                <div class="nostr-card">
                    <h2><?php _e('Nostr Connection', 'burrowsync-for-nostr'); ?></h2>
                    
                    <div id="nostr-connection-status">
                        <?php if ($connection_status['connected']): ?>
                            <div class="nostr-status connected">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <strong><?php _e('Connected', 'burrowsync-for-nostr'); ?></strong>
                                <p><?php printf(__('Site Public Key: %s', 'burrowsync-for-nostr'), substr($connection_status['public_key'], 0, 16) . '...'); ?></p>
                                <p class="description"><?php _e('This is your site\'s Nostr identity. All content synced to and from Nostr uses this key.', 'burrowsync-for-nostr'); ?></p>
                                <button type="button" class="button" id="nostr-disconnect"><?php _e('Disconnect', 'burrowsync-for-nostr'); ?></button>
                            </div>
                        <?php else: ?>
                            <div class="nostr-status disconnected">
                                <span class="dashicons dashicons-warning"></span>
                                <strong><?php _e('Not Connected', 'burrowsync-for-nostr'); ?></strong>
                                <p><?php _e('Connect your Nostr account to enable synchronization.', 'burrowsync-for-nostr'); ?></p>
                                <p class="description"><?php _e('You need a NIP-07 compatible browser extension (like Alby or nos2x) to connect.', 'burrowsync-for-nostr'); ?></p>
                                <button type="button" class="button button-primary" id="nostr-connect"><?php _e('Connect with NIP-07', 'burrowsync-for-nostr'); ?></button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php
                // Additional cards (e.g. the NIP-46 remote signer card)
                do_action('nostr_for_wp_settings_cards');
                ?>
                
                <!-- Relay Configuration -->
                <div class="nostr-card">
                    <h2><?php _e('Relay Configuration', 'burrowsync-for-nostr'); ?></h2>
                    <p class="description"><?php _e('Configure your Nostr relays. The test button checks basic connectivity to the relay host/port.', 'burrowsync-for-nostr'); ?></p>
                    
                    <form id="nostr-relays-form">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Relays', 'burrowsync-for-nostr'); ?></th>
                                <td>
                                    <div id="nostr-relays-list">
                                        <?php foreach ($connection_status['relays'] as $index => $relay): ?>
                                            <div class="nostr-relay-item">
                                                <input type="url" name="relays[]" value="<?php echo esc_attr($relay); ?>" class="regular-text" placeholder="wss://relay.example.com">
                                                <button type="button" class="button nostr-test-relay" data-relay="<?php echo esc_attr($relay); ?>"><?php _e('Test', 'burrowsync-for-nostr'); ?></button>
                                                <button type="button" class="button nostr-remove-relay"><?php _e('Remove', 'burrowsync-for-nostr'); ?></button>
                                                <span class="nostr-relay-status" data-relay="<?php echo esc_attr($relay); ?>">
                                                    <?php if (isset($connection_status['relay_status'][$relay]) && $connection_status['relay_status'][$relay]): ?>
                                                        <span class="dashicons dashicons-yes-alt" style="color: green;" title="Relay is working"></span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="button" id="nostr-add-relay"><?php _e('Add Relay', 'burrowsync-for-nostr'); ?></button>
                                    <button type="button" class="button button-primary" id="nostr-save-relays"><?php _e('Save Relays', 'burrowsync-for-nostr'); ?></button>
                                    <p class="description" style="margin-top: 10px; font-style: italic;">
                                        <?php _e('Note: The test checks basic TCP connectivity to the relay host/port. Real WebSocket connections will be tested during actual sync operations.', 'burrowsync-for-nostr'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </form>
                </div>
                
                <!-- Sync Settings -->
                <div class="nostr-card">
                    <h2><?php _e('Sync Settings', 'burrowsync-for-nostr'); ?></h2>
                    
                    <form method="post" action="options.php">
                        <?php settings_fields('nostr_for_wp_settings'); ?>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Sync Interval', 'burrowsync-for-nostr'); ?></th>
                                <td>
                                    <input type="number" name="nostr_for_wp_options[sync_interval]" value="<?php echo esc_attr(get_option('nostr_for_wp_options')['sync_interval'] ?? 300); ?>" min="60" max="3600" step="60">
                                    <p class="description"><?php _e('How often to check for updates from Nostr (in seconds). Minimum: 60 seconds.', 'burrowsync-for-nostr'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Auto Sync', 'burrowsync-for-nostr'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="nostr_for_wp_options[auto_sync_enabled]" value="1" <?php checked(get_option('nostr_for_wp_options')['auto_sync_enabled'] ?? true); ?>>
                                        <?php _e('Enable automatic synchronization', 'burrowsync-for-nostr'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                        
                        <?php submit_button(); ?>
                    </form>
                </div>

                <!-- Display Settings -->
                <div class="nostr-card">
                    <h2><?php _e('Display Settings', 'burrowsync-for-nostr'); ?></h2>
                    <p class="description"><?php _e('Control how synced notes render on the public site.', 'burrowsync-for-nostr'); ?></p>

                    <form method="post" action="options.php">
                        <?php settings_fields('nostr_for_wp_settings'); ?>
                        <input type="hidden" name="nostr_for_wp_display_settings" value="1">

                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Inline URL embeds', 'burrowsync-for-nostr'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="nostr_for_wp_options[embed_inline_urls]" value="1" <?php checked(get_option('nostr_for_wp_options')['embed_inline_urls'] ?? true); ?>>
                                        <?php _e('Append embed cards for inline URLs in notes (Twitter/X, YouTube, and other oEmbed providers)', 'burrowsync-for-nostr'); ?>
                                    </label>
                                    <p class="description"><?php _e('Stored note content is never modified; embeds are added at render time only.', 'burrowsync-for-nostr'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Note provenance', 'burrowsync-for-nostr'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="nostr_for_wp_options[show_note_provenance]" value="1" <?php checked(get_option('nostr_for_wp_options')['show_note_provenance'] ?? true); ?>>
                                        <?php _e('Show timestamp and Nostr event ID beneath note content on single note pages', 'burrowsync-for-nostr'); ?>
                                    </label>
                                    <p class="description"><?php _e('Event metadata remains available via REST and post meta when disabled.', 'burrowsync-for-nostr'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(); ?>
                    </form>
                </div>
                
                <!-- NIP-05 Identity -->
                <?php
                $nip05_data    = self::get_nip05_data();
                $nip05_file    = ABSPATH . '.well-known/nostr.json';
                $nip05_dir     = ABSPATH . '.well-known';
                $nip05_exists  = file_exists($nip05_file);
                $nip05_writable = $nip05_exists ? is_writable($nip05_file) : is_writable($nip05_dir) || !file_exists($nip05_dir);
                $site_pubkey   = $connection_status['connected'] ? $connection_status['public_key'] : '';
                ?>
                <div class="nostr-card nostr-card-full">
                    <h2><?php _e('NIP-05 Identity', 'burrowsync-for-nostr'); ?></h2>
                    <p class="description">
                        <?php _e('NIP-05 lets people verify your Nostr identity via your domain (e.g. <code>you@yourdomain.com</code>). This creates a real <code>.well-known/nostr.json</code> file on your server.', 'burrowsync-for-nostr'); ?>
                    </p>

                    <div class="nostr-nip05-status" style="margin-bottom:15px;">
                        <?php if ($nip05_exists): ?>
                            <span class="dashicons dashicons-yes-alt" style="color:green;vertical-align:middle;"></span>
                            <strong><?php _e('File exists:', 'burrowsync-for-nostr'); ?></strong>
                            <code><?php echo esc_html(str_replace(ABSPATH, '/', $nip05_file)); ?></code>
                        <?php else: ?>
                            <span class="dashicons dashicons-minus" style="color:#999;vertical-align:middle;"></span>
                            <strong><?php _e('File does not exist yet.', 'burrowsync-for-nostr'); ?></strong>
                        <?php endif; ?>
                        &nbsp;
                        <?php if ($nip05_writable): ?>
                            <span style="color:green;"><?php _e('(writable)', 'burrowsync-for-nostr'); ?></span>
                        <?php else: ?>
                            <span style="color:red;"><?php _e('(not writable — check server permissions)', 'burrowsync-for-nostr'); ?></span>
                        <?php endif; ?>
                    </div>

                    <table class="form-table" style="margin-bottom:0;">
                        <tr>
                            <th scope="row"><?php _e('Identifiers', 'burrowsync-for-nostr'); ?></th>
                            <td>
                                <div id="nip05-names-list">
                                    <?php if (!empty($nip05_data['names'])): ?>
                                        <?php foreach ($nip05_data['names'] as $name => $pubkey): ?>
                                        <div class="nip05-row">
                                            <input type="text"  class="nip05-name"   value="<?php echo esc_attr($name); ?>"   placeholder="<?php esc_attr_e('name (e.g. _ or alice)', 'burrowsync-for-nostr'); ?>" style="width:160px;">
                                            <span style="padding:0 6px;line-height:30px;">→</span>
                                            <input type="text"  class="nip05-pubkey" value="<?php echo esc_attr($pubkey); ?>" placeholder="<?php esc_attr_e('64-char hex pubkey', 'burrowsync-for-nostr'); ?>" style="width:480px;font-family:monospace;">
                                            <?php if ($site_pubkey && $pubkey !== $site_pubkey): ?>
                                            <?php endif; ?>
                                            <button type="button" class="button nip05-remove-row"><?php _e('Remove', 'burrowsync-for-nostr'); ?></button>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="nip05-row">
                                            <input type="text"  class="nip05-name"   value="" placeholder="<?php esc_attr_e('name (e.g. _ or alice)', 'burrowsync-for-nostr'); ?>" style="width:160px;">
                                            <span style="padding:0 6px;line-height:30px;">→</span>
                                            <input type="text"  class="nip05-pubkey" value="" placeholder="<?php esc_attr_e('64-char hex pubkey', 'burrowsync-for-nostr'); ?>" style="width:480px;font-family:monospace;">
                                            <button type="button" class="button nip05-remove-row"><?php _e('Remove', 'burrowsync-for-nostr'); ?></button>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <p style="margin-top:10px;">
                                    <button type="button" class="button" id="nip05-add-row"><?php _e('+ Add Identifier', 'burrowsync-for-nostr'); ?></button>
                                    <?php if ($site_pubkey): ?>
                                    <button type="button" class="button" id="nip05-use-site-key" data-pubkey="<?php echo esc_attr($site_pubkey); ?>"><?php _e('Use Connected Site Key', 'burrowsync-for-nostr'); ?></button>
                                    <?php endif; ?>
                                </p>

                                <p class="description">
                                    <?php _e('Use <code>_</code> as the name to make your identity <code>you@yourdomain.com</code> (root domain identifier).', 'burrowsync-for-nostr'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p style="margin-top:15px;">
                        <button type="button" class="button button-primary" id="nip05-save"><?php _e('Save nostr.json', 'burrowsync-for-nostr'); ?></button>
                        <span id="nip05-save-status" style="margin-left:10px;"></span>
                    </p>

                    <?php if ($nip05_exists && !empty($nip05_data['names'])): ?>
                    <div style="margin-top:15px;">
                        <strong><?php _e('Current file preview:', 'burrowsync-for-nostr'); ?></strong>
                        <pre style="background:#f6f7f7;border:1px solid #ddd;padding:10px;margin-top:5px;overflow:auto;font-size:12px;"><?php echo esc_html(json_encode(array('names' => $nip05_data['names']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Sync Status -->
                <div class="nostr-card">
                    <h2><?php _e('Sync Status', 'burrowsync-for-nostr'); ?></h2>
                    
                    <div class="nostr-sync-stats">
                        <p><strong><?php _e('Connected Users:', 'burrowsync-for-nostr'); ?></strong> <?php echo $sync_stats['connected_users']; ?></p>
                        <p><strong><?php _e('Last Sync:', 'burrowsync-for-nostr'); ?></strong> <?php echo $sync_stats['last_sync'] ? date('Y-m-d H:i:s', strtotime($sync_stats['last_sync'])) : __('Never', 'burrowsync-for-nostr'); ?></p>
                        <p><strong><?php _e('Next Sync:', 'burrowsync-for-nostr'); ?></strong> <?php echo $cron_status['next_run'] ? date('Y-m-d H:i:s', strtotime($cron_status['next_run'])) : __('Not scheduled', 'burrowsync-for-nostr'); ?></p>
                        <p><strong><?php _e('Cron Status:', 'burrowsync-for-nostr'); ?></strong> 
                            <?php if ($cron_status['scheduled']): ?>
                                <span style="color: green;"><?php _e('Active', 'burrowsync-for-nostr'); ?></span>
                            <?php else: ?>
                                <span style="color: red;"><?php _e('Inactive', 'burrowsync-for-nostr'); ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <p>
                        <button type="button" class="button" id="nostr-force-sync"><?php _e('Sync Latest Notes', 'burrowsync-for-nostr'); ?></button>
                        <button type="button" class="button button-secondary" id="nostr-force-full-resync"><?php _e('Sync All Notes', 'burrowsync-for-nostr'); ?></button>
                        <button type="button" class="button" id="nostr-test-all-relays"><?php _e('Test All Relays', 'burrowsync-for-nostr'); ?></button>
                    </p>
                    
                </div>
            </div>
        </div>
        
        <style>
        .nostr-admin-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        
        .nostr-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        
        .nostr-card h2 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .nostr-status {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        
        .nostr-status.connected {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .nostr-status.disconnected {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .nostr-relay-item {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            gap: 10px;
        }
        
        .nostr-relay-item input {
            flex: 1;
        }
        
        .nostr-sync-stats p {
            margin: 10px 0;
        }

        .nostr-card-full {
            grid-column: 1 / -1;
        }

        .nip05-row {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
            gap: 6px;
        }
        </style>

        <script>
        (function($) {
            var nonce = <?php echo json_encode(wp_create_nonce('nostr_for_wp_admin_nonce')); ?>;

            function addNip05Row(name, pubkey) {
                var row = $('<div class="nip05-row">' +
                    '<input type="text" class="nip05-name" placeholder="<?php esc_attr_e('name (e.g. _ or alice)', 'burrowsync-for-nostr'); ?>" style="width:160px;">' +
                    '<span style="padding:0 6px;line-height:30px;">→</span>' +
                    '<input type="text" class="nip05-pubkey" placeholder="<?php esc_attr_e('64-char hex pubkey', 'burrowsync-for-nostr'); ?>" style="width:480px;font-family:monospace;">' +
                    '<button type="button" class="button nip05-remove-row"><?php esc_html_e('Remove', 'burrowsync-for-nostr'); ?></button>' +
                    '</div>');
                if (name)   row.find('.nip05-name').val(name);
                if (pubkey) row.find('.nip05-pubkey').val(pubkey);
                $('#nip05-names-list').append(row);
            }

            $(document).on('click', '.nip05-remove-row', function() {
                var $list = $('#nip05-names-list');
                if ($list.find('.nip05-row').length > 1) {
                    $(this).closest('.nip05-row').remove();
                } else {
                    $(this).closest('.nip05-row').find('input').val('');
                }
            });

            $('#nip05-add-row').on('click', function() {
                addNip05Row('', '');
            });

            $('#nip05-use-site-key').on('click', function() {
                var pubkey = $(this).data('pubkey');
                // Find first empty pubkey row or add a new one
                var $empty = $('#nip05-names-list .nip05-row').filter(function() {
                    return $(this).find('.nip05-pubkey').val() === '';
                }).first();
                if ($empty.length) {
                    $empty.find('.nip05-pubkey').val(pubkey);
                    if ($empty.find('.nip05-name').val() === '') {
                        $empty.find('.nip05-name').val('_');
                    }
                } else {
                    addNip05Row('_', pubkey);
                }
            });

            $('#nip05-save').on('click', function() {
                var $btn    = $(this);
                var $status = $('#nip05-save-status');
                var names   = [];
                var pubkeys = [];

                $('#nip05-names-list .nip05-row').each(function() {
                    names.push($(this).find('.nip05-name').val().trim());
                    pubkeys.push($(this).find('.nip05-pubkey').val().trim());
                });

                $btn.prop('disabled', true);
                $status.text('<?php esc_html_e('Saving...', 'burrowsync-for-nostr'); ?>').css('color', '');

                $.post(ajaxurl, {
                    action:       'nostr_save_nip05',
                    nonce:        nonce,
                    nip05_names:  names,
                    nip05_pubkeys: pubkeys,
                }, function(response) {
                    if (response.success) {
                        $status.text(response.data.message).css('color', 'green');
                        // Refresh after a moment so the preview updates
                        setTimeout(function() { location.reload(); }, 1200);
                    } else {
                        $status.text(response.data).css('color', 'red');
                    }
                }).fail(function() {
                    $status.text('<?php esc_html_e('Request failed. Please try again.', 'burrowsync-for-nostr'); ?>').css('color', 'red');
                }).always(function() {
                    $btn.prop('disabled', false);
                });
            });
        }(jQuery));
        </script>
        
        <?php
    }
    
    /**
     * Save public key via AJAX
     */
    public function save_public_key_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $public_key = sanitize_text_field($_POST['public_key']);
        
        $nip07_handler = Nostr_NIP07_Handler::get_instance();
        $success = $nip07_handler->save_public_key($public_key);
        
        if ($success) {
            wp_send_json_success('Public key saved');
        } else {
            wp_send_json_error('Invalid public key');
        }
    }
    
    /**
     * Disconnect via AJAX
     */
    public function disconnect_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $nip07_handler = Nostr_NIP07_Handler::get_instance();
        $success = $nip07_handler->disconnect_user();
        
        if ($success) {
            wp_send_json_success('Disconnected');
        } else {
            wp_send_json_error('Failed to disconnect');
        }
    }
    
    /**
     * Test relay via AJAX
     */
    public function test_relay_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $relay = sanitize_text_field($_POST['relay']);
        
        // Validate relay URL
        if (empty($relay) || (!filter_var($relay, FILTER_VALIDATE_URL) && !preg_match('/^wss?:\/\//', $relay))) {
            wp_send_json_error(array('message' => 'Invalid relay URL'));
        }
        
        // Log the test attempt
        error_log('Nostr: Testing relay: ' . $relay);
        
        $nip07_handler = Nostr_NIP07_Handler::get_instance();
        $success = $nip07_handler->test_relay($relay);
        
        error_log('Nostr: Relay test result for ' . $relay . ': ' . ($success ? 'success' : 'failed'));
        
        wp_send_json_success(array('success' => $success));
    }
    
    /**
     * Save relays via AJAX
     */
    public function save_relays_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        // Get relays from POST data
        $relays = isset($_POST['relays']) ? $_POST['relays'] : array();
        
        // Validate and sanitize relay URLs
        $valid_relays = array();
        foreach ($relays as $relay) {
            $relay = trim($relay);
            if (!empty($relay)) {
                // Basic URL validation for WebSocket URLs
                if (filter_var($relay, FILTER_VALIDATE_URL) && 
                    (strpos($relay, 'wss://') === 0 || strpos($relay, 'ws://') === 0)) {
                    // Don't use esc_url_raw for WebSocket URLs as it strips them
                    $valid_relays[] = sanitize_text_field($relay);
                }
            }
        }
        
        $nip07_handler = Nostr_NIP07_Handler::get_instance();
        $success = $nip07_handler->save_user_relays($valid_relays);
        
        if ($success) {
            wp_send_json_success(array(
                'message' => 'Relays saved successfully',
                'relays' => $valid_relays
            ));
        } else {
            wp_send_json_error('Failed to save relays');
        }
    }
    
    /**
     * Force sync via AJAX (inbound only - Nostr → WordPress)
     */
    public function force_sync_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        // Check if this is a full resync request
        $force_full_resync = isset($_POST['full_resync']) && $_POST['full_resync'] === 'true';
        
        $sync_manager = Nostr_Sync_Manager::get_instance();
        $result = $sync_manager->sync_from_nostr(1, $force_full_resync);
        
        error_log('Nostr: Force sync completed' . ($force_full_resync ? ' (full resync)' : ''));
        
        if ($result !== false) {
            $message = $force_full_resync 
                ? sprintf('Sync all notes completed: %d processed, %d skipped', $result['processed'], $result['skipped'])
                : sprintf('Sync completed: %d processed, %d skipped', $result['processed'], $result['skipped']);
            wp_send_json_success($message);
        } else {
            wp_send_json_error('Sync failed');
        }
    }
    
    /**
     * Get pending posts for NIP-07 signing
     */
    public function get_pending_posts_ajax() {
        error_log('Nostr: get_pending_posts_ajax called');
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $pending_posts = get_posts(array(
            'meta_query' => array(
                array(
                    'key' => '_nostr_sync_pending',
                    'value' => '1',
                    'compare' => '='
                )
            ),
            'post_status' => 'publish',
            'numberposts' => 10
        ));
        
        error_log('Nostr: Found ' . count($pending_posts) . ' pending posts');
        wp_send_json_success(array('posts' => $pending_posts));
    }
    
    /**
     * Get Nostr event data for a post
     */
    public function get_post_event_ajax() {
        error_log('Nostr: get_post_event_ajax called');
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $post_id = intval($_POST['post_id']);
        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
        }
        
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error('Post not found');
        }
        
        $content_mapper = Nostr_Content_Mapper::get_instance();
        $nostr_client = Nostr_Client::get_instance();
        
        // Create the Nostr event (unsigned)
        if ($post->post_type === 'note') {
            $event = $content_mapper->note_to_nostr_event($post_id);
        } else {
            $event = $content_mapper->post_to_nostr_event($post_id);
        }
        
        if (!$event) {
            wp_send_json_error('Failed to create Nostr event');
        }
        
        // Add public key and timestamp
        $public_key = $nostr_client->get_public_key();
        if (!$public_key) {
            wp_send_json_error('No public key found');
        }
        
        $event['pubkey'] = $public_key;
        $event['created_at'] = time();
        
        // Debug timestamp
        error_log('Nostr: Current timestamp: ' . time() . ' (' . date('Y-m-d H:i:s', time()) . ')');
        
        // Ensure all required fields for NIP-07 signing
        if (!isset($event['kind'])) {
            $event['kind'] = ($post->post_type === 'note') ? 1 : 30023;
        }
        if (!isset($event['content'])) {
            $event['content'] = $post->post_content;
        }
        if (!isset($event['tags'])) {
            $event['tags'] = array();
        }
        
        // Remove any fields that shouldn't be in the unsigned event
        unset($event['id']);
        unset($event['sig']);
        
        error_log('Nostr: Event data for signing: ' . json_encode($event));
        
        wp_send_json_success(array('event' => $event));
    }
    
    /**
     * Publish signed event to relays
     */
    public function publish_event_ajax() {
        error_log('Nostr: publish_event_ajax called');
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        // Not sanitize_text_field(): that strips tags and whitespace inside
        // the content and so changes the signed event. The JSON is decoded
        // and every field checked below instead.
        $event_json = isset($_POST['event']) ? wp_unslash($_POST['event']) : '';
        $event = is_string($event_json) ? json_decode($event_json, true) : null;
        
        error_log('Nostr: Received signed event: ' . json_encode($event));
        
        if (!$event || !isset($event['sig'])) {
            wp_send_json_error('Invalid signed event - missing signature');
        }
        
        // The event should have: kind, content, tags, created_at, pubkey, sig
        $required_fields = ['kind', 'content', 'tags', 'created_at', 'pubkey', 'sig'];
        foreach ($required_fields as $field) {
            if (!isset($event[$field])) {
                wp_send_json_error('Invalid signed event - missing field: ' . $field);
            }
        }
        
        $nostr_client = Nostr_Client::get_instance();
        $results = $nostr_client->publish_event($event);
        
        error_log('Nostr: Publish results: ' . json_encode($results));

        // Keep the browser-signed event and the per-relay results when the
        // caller says which post it belongs to and the event checks out.
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if ($post_id && get_post($post_id) && current_user_can('edit_post', $post_id)
            && Nostr_Publish_Record::is_valid_event($event, true)) {
            Nostr_Publish_Record::store_event($post_id, $event, 'nip07');
            Nostr_Publish_Record::record_results($post_id, $event, $results, 'nip07');
        }
        
        $success = false;
        foreach ($results as $result) {
            if (isset($result['success']) && $result['success']) {
                $success = true;
                break;
            }
        }
        
        if ($success) {
            wp_send_json_success(array('success' => true, 'results' => $results));
        } else {
            wp_send_json_error('No relays accepted the event');
        }
    }
    
    /**
     * Read current .well-known/nostr.json data
     */
    private static function get_nip05_data() {
        $file = ABSPATH . '.well-known/nostr.json';
        if (!file_exists($file)) {
            return array('names' => array(), 'relays' => array());
        }
        $json = json_decode(file_get_contents($file), true);
        if (!is_array($json)) {
            return array('names' => array(), 'relays' => array());
        }
        return array(
            'names'  => isset($json['names'])  && is_array($json['names'])  ? $json['names']  : array(),
            'relays' => isset($json['relays']) && is_array($json['relays']) ? $json['relays'] : array(),
        );
    }

    /**
     * Save .well-known/nostr.json via AJAX
     */
    public function save_nip05_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $dir  = ABSPATH . '.well-known';
        $file = $dir . '/nostr.json';

        // Ensure directory exists
        if (!file_exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                wp_send_json_error('Could not create .well-known directory. Check server permissions.');
            }
        }

        if (file_exists($file) && !is_writable($file)) {
            wp_send_json_error('nostr.json exists but is not writable. Check file permissions.');
        }

        if (!file_exists($file) && !is_writable($dir)) {
            wp_send_json_error('.well-known directory is not writable. Check server permissions.');
        }

        // Build names map — validate each pubkey is a 64-char hex string
        $names = array();
        $raw_names  = isset($_POST['nip05_names'])  ? (array) $_POST['nip05_names']  : array();
        $raw_pubkeys = isset($_POST['nip05_pubkeys']) ? (array) $_POST['nip05_pubkeys'] : array();

        foreach ($raw_names as $i => $name) {
            $name   = sanitize_text_field($name);
            $pubkey = isset($raw_pubkeys[$i]) ? sanitize_text_field($raw_pubkeys[$i]) : '';

            if ($name === '' || $pubkey === '') {
                continue;
            }
            if (!preg_match('/^[0-9a-f]{64}$/i', $pubkey)) {
                wp_send_json_error('Invalid public key for "' . esc_html($name) . '". Must be a 64-character hex string.');
            }
            $names[$name] = strtolower($pubkey);
        }

        // Build optional relays map from the site's configured relays
        $relays = array();
        $options = get_option('nostr_for_wp_options', array());
        $site_relays = !empty($options['relays']) ? $options['relays'] : (!empty($options['default_relays']) ? $options['default_relays'] : array());

        foreach (array_keys($names) as $pubkey) {
            if (!empty($site_relays)) {
                $relays[$pubkey] = array_values($site_relays);
            }
        }

        $payload = array('names' => $names);
        if (!empty($relays)) {
            $payload['relays'] = $relays;
        }

        $written = file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($written === false) {
            wp_send_json_error('Failed to write nostr.json. Check server permissions.');
        }

        wp_send_json_success(array(
            'message' => 'nostr.json saved successfully.',
            'path'    => str_replace(ABSPATH, '/', $file),
            'names'   => $names,
        ));
    }

    /**
     * Mark post as synced
     */
    public function mark_post_synced_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $post_id = intval($_POST['post_id']);
        $event_id = isset($_POST['event_id']) ? sanitize_text_field($_POST['event_id']) : '';
        
        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
        }
        
        // Post editor flow: the browser published straight to the relays and
        // sends the signed event plus what each relay said, so it can be kept.
        $event = isset($_POST['event']) && is_string($_POST['event']) ? json_decode(wp_unslash($_POST['event']), true) : null;
        if ($event && current_user_can('edit_post', $post_id) && Nostr_Publish_Record::is_valid_event($event, true)
            && ($event_id === '' || $event_id === $event['id'])) {
            $event_id = $event['id'];
            Nostr_Publish_Record::store_event($post_id, $event, 'nip07');
            $raw = isset($_POST['relay_results']) && is_string($_POST['relay_results']) ? json_decode(wp_unslash($_POST['relay_results']), true) : null;
            if (is_array($raw)) {
                $results = array();
                foreach (array_slice($raw, 0, 50, true) as $url => $r) {
                    if (!is_string($url) || !preg_match('#^wss?://#i', $url) || !is_array($r)) {
                        continue;
                    }
                    $ok_received = isset($r['ok']) && is_bool($r['ok']);
                    $results[$url] = array(
                        'success'     => $ok_received && $r['ok'] === true,
                        'ok_received' => $ok_received,
                        'error'       => isset($r['message']) && is_string($r['message']) ? $r['message'] : '',
                    );
                }
                if ($results) {
                    Nostr_Publish_Record::record_results($post_id, $event, $results, 'nip07');
                }
            }
        }

        update_post_meta($post_id, '_nostr_sync_pending', '0');
        update_post_meta($post_id, '_nostr_sync_status', 'synced');
        update_post_meta($post_id, '_nostr_synced_at', current_time('mysql'));
        update_post_meta($post_id, '_nostr_event_id', $event_id);
        
        wp_send_json_success('Post marked as synced');
    }
}
