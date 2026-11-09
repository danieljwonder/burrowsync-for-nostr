<?php
/**
 * BurrowSync for Nostr: plugin body.
 *
 * Loaded by burrowsync-for-nostr.php only after its conflict guard passes.
 * Kept in a separate file because PHP binds top-level functions and classes
 * at compile time, before any early return in the same file can run, so the
 * guard could not stop them clashing with Nostr for WP's declarations.
 */

if (!defined('ABSPATH') || !defined('NOSTR_FOR_WP_PLUGIN_FILE')) {
    exit;
}

// Toggle verbose debug logging (set to true to enable detailed logging, false to disable)
if (!defined('NOSTR_FOR_WP_VERBOSE_DEBUG')) {
    define('NOSTR_FOR_WP_VERBOSE_DEBUG', false);
}

/**
 * Helper function to log debug messages (only if verbose debugging is enabled)
 * 
 * @param string $message The message to log
 */
function nostr_for_wp_debug_log($message) {
    if (!NOSTR_FOR_WP_VERBOSE_DEBUG) {
        return;
    }
    
    $log_file = WP_CONTENT_DIR . '/nostr-debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "{$timestamp} - {$message}\n", FILE_APPEND | LOCK_EX);
}

/**
 * Main plugin class
 */
class Nostr_For_WP {
    
    /**
     * Single instance of the plugin
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
        $this->load_dependencies();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        register_activation_hook(NOSTR_FOR_WP_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(NOSTR_FOR_WP_PLUGIN_FILE, array($this, 'deactivate'));
        
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'maybe_finish_setup'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Load core classes
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-websocket-client.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nostr-client.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-sync-manager.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-content-mapper.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nip07-handler.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-cron-handler.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-frontend-display.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'public/class-note-embeds.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-note-shortcode.php';
        
        // NIP-46 remote signer (bunker) support
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nip46-crypto.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nip46-websocket.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nip46-settings.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nip46-client.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nip46-publisher.php';
        
        // Load admin classes
        if (is_admin()) {
            require_once NOSTR_FOR_WP_PLUGIN_DIR . 'admin/settings-page.php';
            require_once NOSTR_FOR_WP_PLUGIN_DIR . 'admin/meta-boxes.php';
            require_once NOSTR_FOR_WP_PLUGIN_DIR . 'admin/nip46-settings.php';
        }
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('burrowsync-for-nostr', false, dirname(NOSTR_FOR_WP_PLUGIN_BASENAME) . '/languages');
        
        // Register custom post type
        $this->register_post_types();
        
        // Initialize core classes
        Nostr_Client::get_instance();
        Nostr_Sync_Manager::get_instance();
        Nostr_Content_Mapper::get_instance();
        Nostr_Cron_Handler::get_instance();
        Nostr_Frontend_Display::get_instance();
        Nostr_Note_Embeds::get_instance();
        Nostr_Note_Shortcode::get_instance();
        Nostr_NIP46_Publisher::get_instance();
        
        // Register blocks
        add_action('init', array($this, 'register_blocks'), 20);
        
        if (is_admin()) {
            Nostr_Admin_Settings::get_instance();
            Nostr_Admin_Meta_Boxes::get_instance();
            Nostr_NIP46_Admin::get_instance();
        }
    }
    
    /**
     * Admin initialization
     */
    public function admin_init() {
        // Register settings
        register_setting('nostr_for_wp_settings', 'nostr_for_wp_options');
    }
    
    /**
     * Add admin menu
     */
    public function admin_menu() {
        add_options_page(
            __('Nostr Settings', 'burrowsync-for-nostr'),
            __('BurrowSync for Nostr', 'burrowsync-for-nostr'),
            'manage_options',
            'nostr-for-wp',
            array('Nostr_Admin_Settings', 'render_settings_page')
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function admin_enqueue_scripts($hook) {
        // Load settings page JavaScript
        if (strpos($hook, 'nostr-for-wp') !== false || strpos($hook, 'options-general') !== false) {
            wp_enqueue_script(
                'nostr-for-wp-admin',
                NOSTR_FOR_WP_PLUGIN_URL . 'admin/js/nostr-admin.js',
                array('jquery'),
                NOSTR_FOR_WP_VERSION,
                true
            );
            
            // Localize script for settings page
            wp_localize_script('nostr-for-wp-admin', 'nostrForWPAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('nostr_for_wp_admin_nonce'),
                'relays' => get_user_meta(get_current_user_id(), 'nostr_relays', true) ?: array(),
                'strings' => array(
                    'nip07NotAvailable' => __('NIP-07 browser extension not detected', 'burrowsync-for-nostr'),
                    'syncSuccess' => __('Sync completed successfully', 'burrowsync-for-nostr'),
                    'syncError' => __('Sync failed', 'burrowsync-for-nostr'),
                    'connecting' => __('Connecting...', 'burrowsync-for-nostr'),
                    'test' => __('Test', 'burrowsync-for-nostr'),
                    'remove' => __('Remove', 'burrowsync-for-nostr'),
                    'addRelay' => __('Add Relay', 'burrowsync-for-nostr'),
                    'saveRelays' => __('Save Relays', 'burrowsync-for-nostr'),
                    'testing' => __('Testing...', 'burrowsync-for-nostr'),
                    'saving' => __('Saving...', 'burrowsync-for-nostr'),
                    'relaysSaved' => __('Relays saved successfully', 'burrowsync-for-nostr'),
                    'relaysSaveFailed' => __('Failed to save relays', 'burrowsync-for-nostr'),
                    'relayUrlRequired' => __('Please enter a relay URL', 'burrowsync-for-nostr'),
                    'extensionAvailable' => __('NIP-07 extension detected', 'burrowsync-for-nostr'),
                    'extensionNotAvailable' => __('NIP-07 extension not detected', 'burrowsync-for-nostr'),
                    'connectionSuccess' => __('Connected successfully', 'burrowsync-for-nostr'),
                    'connectionFailed' => __('Connection failed', 'burrowsync-for-nostr'),
                    'disconnectConfirm' => __('Are you sure you want to disconnect?', 'burrowsync-for-nostr'),
                    'disconnectFailed' => __('Failed to disconnect', 'burrowsync-for-nostr'),
                    'connect' => __('Connect with NIP-07', 'burrowsync-for-nostr'),
                    'disconnect' => __('Disconnect', 'burrowsync-for-nostr'),
                    'forceSync' => __('Sync Latest Notes', 'burrowsync-for-nostr'),
                    'testAllRelays' => __('Test All Relays', 'burrowsync-for-nostr'),
                    'syncing' => __('Syncing...', 'burrowsync-for-nostr'),
                    'syncNow' => __('Sync Now', 'burrowsync-for-nostr'),
                    'refreshStatus' => __('Refresh Status', 'burrowsync-for-nostr'),
                    'refreshing' => __('Refreshing...', 'burrowsync-for-nostr'),
                    'synced' => __('Synced', 'burrowsync-for-nostr'),
                    'failed' => __('Failed', 'burrowsync-for-nostr'),
                    'error' => __('Error', 'burrowsync-for-nostr'),
                    'pending' => __('Pending', 'burrowsync-for-nostr'),
                    'statusRefreshed' => __('Status refreshed', 'burrowsync-for-nostr'),
                    'statusRefreshFailed' => __('Failed to refresh status', 'burrowsync-for-nostr'),
                    'statusRefreshRequestFailed' => __('Status refresh request failed', 'burrowsync-for-nostr'),
                    'invalidPostId' => __('Invalid post ID', 'burrowsync-for-nostr'),
                    'syncRequestFailed' => __('Sync request failed', 'burrowsync-for-nostr'),
                    'unknownError' => __('Unknown error', 'burrowsync-for-nostr'),
                    'syncSuccess' => __('Sync completed successfully', 'burrowsync-for-nostr'),
                    'syncError' => __('Sync failed', 'burrowsync-for-nostr')
                )
            ));
        }
        
        // Load post edit page JavaScript
        if (in_array($hook, array('post.php', 'post-new.php'))) {
            wp_enqueue_script(
                'nostr-for-wp-post-sync',
                NOSTR_FOR_WP_PLUGIN_URL . 'admin/js/nostr-post-sync.js',
                array('jquery'),
                NOSTR_FOR_WP_VERSION,
                true
            );
            
            // Localize script for post pages
            wp_localize_script('nostr-for-wp-post-sync', 'nostrForWPAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('nostr_for_wp_admin_nonce'),
                'relays' => get_user_meta(get_current_user_id(), 'nostr_relays', true) ?: array(),
                'strings' => array(
                    'syncing' => __('Syncing...', 'burrowsync-for-nostr'),
                    'syncNow' => __('Sync Now', 'burrowsync-for-nostr'),
                    'refreshing' => __('Refreshing...', 'burrowsync-for-nostr'),
                    'refreshStatus' => __('Refresh Status', 'burrowsync-for-nostr'),
                    'synced' => __('Synced', 'burrowsync-for-nostr'),
                    'failed' => __('Failed', 'burrowsync-for-nostr'),
                    'pending' => __('Pending', 'burrowsync-for-nostr'),
                    'statusRefreshed' => __('Status refreshed', 'burrowsync-for-nostr'),
                    'statusRefreshFailed' => __('Failed to refresh status', 'burrowsync-for-nostr'),
                    'statusRefreshRequestFailed' => __('Status refresh request failed', 'burrowsync-for-nostr')
                )
            ));
        }
        
        // Load admin CSS for both settings and post pages
        if (strpos($hook, 'nostr-for-wp') !== false || strpos($hook, 'options-general') !== false || in_array($hook, array('post.php', 'post-new.php'))) {
            wp_enqueue_style(
                'nostr-for-wp-admin',
                NOSTR_FOR_WP_PLUGIN_URL . 'admin/css/nostr-admin.css',
                array(),
                NOSTR_FOR_WP_VERSION
            );
        }
    }
    
    /**
     * Register Gutenberg blocks
     */
    public function register_blocks() {
        // Only register if block editor is available
        if (!function_exists('register_block_type')) {
            return;
        }
        
        // Register Nostr Notes block (from build directory)
        register_block_type(NOSTR_FOR_WP_PLUGIN_DIR . 'build/blocks/nostr-notes', array(
            'render_callback' => array($this, 'render_nostr_notes_block'),
        ));
    }
    
    /**
     * Render Nostr Notes block
     */
    public function render_nostr_notes_block($attributes) {
        $attributes = wp_parse_args($attributes, array(
            'limit' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        
        return do_shortcode(sprintf(
            '[nostr_notes limit="%d" orderby="%s" order="%s"]',
            intval($attributes['limit']),
            esc_attr($attributes['orderby']),
            esc_attr($attributes['order'])
        ));
    }
    
    /**
     * Register custom post types
     */
    private function register_post_types() {
        // Register Notes post type for kind 1 events
        register_post_type('note', array(
            'labels' => array(
                'name' => __('Notes', 'burrowsync-for-nostr'),
                'singular_name' => __('Note', 'burrowsync-for-nostr'),
                'menu_name' => __('Notes', 'burrowsync-for-nostr'),
                'add_new' => __('Add New Note', 'burrowsync-for-nostr'),
                'add_new_item' => __('Add New Note', 'burrowsync-for-nostr'),
                'edit_item' => __('Edit Note', 'burrowsync-for-nostr'),
                'new_item' => __('New Note', 'burrowsync-for-nostr'),
                'view_item' => __('View Note', 'burrowsync-for-nostr'),
                'search_items' => __('Search Notes', 'burrowsync-for-nostr'),
                'not_found' => __('No notes found', 'burrowsync-for-nostr'),
                'not_found_in_trash' => __('No notes found in trash', 'burrowsync-for-nostr')
            ),
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'note'),
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => 20,
            'menu_icon' => 'dashicons-format-chat',
            'supports' => array('title', 'editor', 'author', 'custom-fields', 'revisions'),
            'show_in_nav_menus' => true
        ));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Load cron handler directly since dependencies might not be loaded yet
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-cron-handler.php';
        
        // Flush rewrite rules
        $this->register_post_types();
        flush_rewrite_rules();
        
        // Set default options
        $default_options = array(
            'default_relays' => array(
                'wss://relay.damus.io',
                'wss://relay.snort.social',
                'wss://nos.lol'
            ),
            'sync_interval' => 300, // 5 minutes
            'auto_sync_enabled' => true,
            'embed_inline_urls' => true,
            'show_note_provenance' => true,
        );
        
        add_option('nostr_for_wp_options', $default_options);
        
        // Schedule cron job using the cron handler (which will check auto_sync_enabled)
        $cron_handler = Nostr_Cron_Handler::get_instance();
        $cron_handler->schedule_cron();

        update_option('burrowsync_for_nostr_setup_version', NOSTR_FOR_WP_VERSION);
    }
    
    /**
     * Run activation setup once per version if it never ran, e.g. when this
     * plugin was activated while Nostr for WP was still active and stood down.
     */
    public function maybe_finish_setup() {
        if (get_option('burrowsync_for_nostr_setup_version') === NOSTR_FOR_WP_VERSION) {
            return;
        }
        $this->activate();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Load cron handler directly since dependencies might not be loaded yet
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-cron-handler.php';
        
        // Unschedule cron job using the cron handler
        $cron_handler = Nostr_Cron_Handler::get_instance();
        $cron_handler->unschedule_cron();
        
        // Clear any pending NIP-46 publish retries
        if (function_exists('wp_unschedule_hook')) {
            wp_unschedule_hook('nostr_nip46_process_post');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
}

/**
 * Initialize the plugin
 */
function nostr_for_wp() {
    return Nostr_For_WP::get_instance();
}

// Start the plugin
nostr_for_wp();
