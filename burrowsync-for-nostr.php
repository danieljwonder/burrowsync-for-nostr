<?php
/**
 * Plugin Name: BurrowSync for Nostr
 * Plugin URI: https://burrowsync.com
 * Description: Part of the BurrowSync family. Two-way synchronization between WordPress content and Nostr protocol. Supports kind 1 notes and kind 30023 long-form content with NIP-07 browser extension or NIP-46 remote signer (bunker) signing, and NIP-05 domain identity verification.
 * Version: 0.9.0
 * Author: Daniel Wonder
 * Author URI: https://burrowsync.com
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * Text Domain: burrowsync-for-nostr
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/*
 * BurrowSync for Nostr replaces the Nostr for WP plugin and shares its data
 * (options, post meta, the note post type). Both define the same classes, so
 * they must never load together. If Nostr for WP is active, or already loaded,
 * stand down and ask the admin to deactivate it. Settings carry over unchanged.
 */
if (
    defined('NOSTR_FOR_WP_VERSION')
    || in_array('nostr-for-wp/nostr-for-wp.php', (array) get_option('active_plugins', array()), true)
    || (is_multisite() && array_key_exists('nostr-for-wp/nostr-for-wp.php', (array) get_site_option('active_sitewide_plugins', array())))
) {
    add_action('admin_notices', function () {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>BurrowSync for Nostr</strong> replaces Nostr for WP. Deactivate Nostr for WP to finish switching. Your settings, notes and sync history carry over.</p></div>';
    });
    return;
}

// Define plugin constants
define('NOSTR_FOR_WP_VERSION', '0.9.0');
define('NOSTR_FOR_WP_PLUGIN_FILE', __FILE__);
define('NOSTR_FOR_WP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NOSTR_FOR_WP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NOSTR_FOR_WP_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/plugin.php';
