<?php
/**
 * Cron Handler Class
 * 
 * Handles background polling for Nostr updates
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_Cron_Handler {
    
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
        // Register custom cron interval
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        
        // Register cron job - with debugging
        add_action('nostr_poll_updates', array($this, 'poll_nostr_updates'));
        
        // Track when our option is updated (to see if something else is updating it)
        add_action('update_option_nostr_last_cron_sync', array($this, 'track_last_sync_update'), 10, 2);
        
        // Track all WordPress cron activity
        add_action('wp_loaded', array($this, 'track_cron_activity'));
        
        // Hook into settings update to reschedule cron when settings change
        add_action('update_option_nostr_for_wp_options', array($this, 'handle_settings_update'), 10, 2);
    }
    
    /**
     * Track when last_cron_sync option is updated (debugging)
     */
    public function track_last_sync_update($old_value, $value) {
        $log_file = WP_CONTENT_DIR . '/nostr-debug.log';
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $caller = '';
        if (isset($backtrace[2])) {
            $caller = $backtrace[2]['function'] . ' in ' . $backtrace[2]['file'] . ':' . $backtrace[2]['line'];
        }
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - OPTION UPDATED: nostr_last_cron_sync changed from '{$old_value}' to '{$value}' by: {$caller}\n", FILE_APPEND | LOCK_EX);
        error_log('Nostr: Option nostr_last_cron_sync updated from ' . $old_value . ' to ' . $value . ' by: ' . $caller);
    }
    
    /**
     * Track WordPress cron activity (debugging)
     */
    public function track_cron_activity() {
        if (defined('DOING_CRON') && DOING_CRON) {
            $log_file = WP_CONTENT_DIR . '/nostr-debug.log';
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - WordPress cron is running (DOING_CRON=true)\n", FILE_APPEND | LOCK_EX);
            
            // Check what cron jobs are scheduled
            $crons = _get_cron_array();
            if ($crons) {
                foreach ($crons as $timestamp => $cron) {
                    if (isset($cron['nostr_poll_updates'])) {
                        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Found nostr_poll_updates scheduled for: " . date('Y-m-d H:i:s', $timestamp) . "\n", FILE_APPEND | LOCK_EX);
                    }
                }
            }
        }
    }
    
    /**
     * Add custom cron interval
     */
    public function add_cron_interval($schedules) {
        $options = get_option('nostr_for_wp_options', array());
        $interval = isset($options['sync_interval']) ? intval($options['sync_interval']) : 300; // Default 5 minutes
        
        $schedules['nostr_poll_interval'] = array(
            'interval' => $interval,
            'display' => sprintf(__('Every %d seconds', 'burrowsync-for-nostr'), $interval)
        );
        
        return $schedules;
    }
    
    /**
     * Schedule cron job
     */
    public function schedule_cron() {
        // Check if auto sync is enabled
        $options = get_option('nostr_for_wp_options', array());
        $auto_sync_enabled = isset($options['auto_sync_enabled']) ? (bool) $options['auto_sync_enabled'] : true; // Default to true
        
        // Only schedule if auto sync is enabled
        if (!$auto_sync_enabled) {
            // If auto sync is disabled, unschedule any existing cron
            $this->unschedule_cron();
            return;
        }
        
        // Check if already scheduled
        if (wp_next_scheduled('nostr_poll_updates')) {
            // If already scheduled but interval might have changed, reschedule
            $current_schedule = wp_get_schedule('nostr_poll_updates');
            if ($current_schedule !== 'nostr_poll_interval') {
                $this->unschedule_cron();
            } else {
                return; // Already scheduled with correct interval
            }
        }
        
        // Schedule the cron with the custom interval
        $scheduled = wp_schedule_event(time(), 'nostr_poll_interval', 'nostr_poll_updates');
        
        if ($scheduled === false) {
            error_log('Nostr: Failed to schedule cron job');
        }
    }
    
    /**
     * Unschedule cron job
     */
    public function unschedule_cron() {
        $timestamp = wp_next_scheduled('nostr_poll_updates');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'nostr_poll_updates');
        }
    }
    
    /**
     * Poll Nostr for updates
     */
    public function poll_nostr_updates() {
        $log_file = WP_CONTENT_DIR . '/nostr-debug.log';
        
        try {
            nostr_for_wp_debug_log("===== poll_nostr_updates() CALLED =====");
            nostr_for_wp_debug_log("Step 1: After first file write");
            
            // Determine context for logging
            $is_cron = (defined('DOING_CRON') && DOING_CRON) ? 'CRON' : 'MANUAL/AJAX';
            nostr_for_wp_debug_log("Step 2: Context determined: {$is_cron}");
            
            $pid = function_exists('getmypid') ? getmypid() : 'unknown';
            $context = $is_cron . ' | PID: ' . $pid . ' | REQUEST_URI: ' . ($_SERVER['REQUEST_URI'] ?? 'none');
            nostr_for_wp_debug_log("Step 3: Context: {$context}");
            
            nostr_for_wp_debug_log("Step 4: Getting Nostr_Client instance");
            
            // Check if site has Nostr key connected (single site identity)
            $nostr_client = Nostr_Client::get_instance();
            
            nostr_for_wp_debug_log("Step 5: Got Nostr_Client instance, getting public key");
            
            $public_key = $nostr_client->get_public_key();
            
            nostr_for_wp_debug_log("Step 6: Got public key: " . ($public_key ? 'YES (' . substr($public_key, 0, 16) . '...)' : 'NO'));
        
            if (!$public_key) {
                nostr_for_wp_debug_log("Step 7: SKIPPED - No public key configured");
                return;
            }
            
            nostr_for_wp_debug_log("Step 8: Public key found, getting Sync_Manager instance");
            
            $sync_manager = Nostr_Sync_Manager::get_instance();
            
            nostr_for_wp_debug_log("Step 9: Got Sync_Manager, calling sync_from_nostr(1)");
            
            try {
                // Use user 1 as default author (or could make this configurable)
                $result = $sync_manager->sync_from_nostr(1);
                nostr_for_wp_debug_log("Step 10: sync_from_nostr completed. Result: " . json_encode($result));
            } catch (Exception $e) {
                // Always log errors, even if verbose debug is off
                $log_msg = date('Y-m-d H:i:s') . " - Step 10 ERROR: EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
                file_put_contents($log_file, $log_msg, FILE_APPEND | LOCK_EX);
                error_log('Nostr: Sync error: ' . $e->getMessage());
            } catch (Error $e) {
                // Always log fatal errors
                $log_msg = date('Y-m-d H:i:s') . " - Step 10 FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
                file_put_contents($log_file, $log_msg, FILE_APPEND | LOCK_EX);
                error_log('Nostr: Fatal sync error: ' . $e->getMessage());
            }
            
            // Update last sync time
            nostr_for_wp_debug_log("Step 11: Updating last_cron_sync option");
            
            $sync_time = current_time('mysql');
            update_option('nostr_last_cron_sync', $sync_time);
            
            nostr_for_wp_debug_log("Step 12: Option updated to: {$sync_time}");
            
            nostr_for_wp_debug_log("===== Sync COMPLETED =====");
            
        } catch (Exception $e) {
            $log_msg = date('Y-m-d H:i:s') . " - OUTER EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            file_put_contents($log_file, $log_msg, FILE_APPEND | LOCK_EX);
        } catch (Error $e) {
            $log_msg = date('Y-m-d H:i:s') . " - OUTER FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            file_put_contents($log_file, $log_msg, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            $log_msg = date('Y-m-d H:i:s') . " - OUTER THROWABLE: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            file_put_contents($log_file, $log_msg, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Debug cron status (for troubleshooting)
     */
    public function debug_cron_status() {
        error_log('Nostr Cron Debug Info:');
        error_log('  - DISABLE_WP_CRON: ' . (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'YES (cron disabled!)' : 'NO'));
        error_log('  - Next scheduled: ' . (wp_next_scheduled('nostr_poll_updates') ? date('Y-m-d H:i:s', wp_next_scheduled('nostr_poll_updates')) : 'NOT SCHEDULED'));
        error_log('  - Schedule type: ' . (wp_get_schedule('nostr_poll_updates') ?: 'none'));
        $options = get_option('nostr_for_wp_options', array());
        error_log('  - Auto sync enabled: ' . (isset($options['auto_sync_enabled']) ? ($options['auto_sync_enabled'] ? 'YES' : 'NO') : 'not set'));
        error_log('  - Sync interval: ' . (isset($options['sync_interval']) ? $options['sync_interval'] : 'not set'));
        error_log('  - Last cron sync: ' . (get_option('nostr_last_cron_sync') ?: 'never'));
        
        $nostr_client = Nostr_Client::get_instance();
        $public_key = $nostr_client->get_public_key();
        error_log('  - Public key configured: ' . ($public_key ? 'YES (' . substr($public_key, 0, 16) . '...)' : 'NO'));
    }
    
    /**
     * Manual sync trigger
     */
    public function trigger_manual_sync($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return false;
        }
        
        $sync_manager = Nostr_Sync_Manager::get_instance();
        return $sync_manager->sync_from_nostr($user_id);
    }
    
    /**
     * Get cron status
     */
    public function get_cron_status() {
        $next_run = wp_next_scheduled('nostr_poll_updates');
        $last_sync = get_option('nostr_last_cron_sync');
        
        return array(
            'scheduled' => $next_run !== false,
            'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : null,
            'last_sync' => $last_sync,
            'interval' => wp_get_schedule('nostr_poll_updates')
        );
    }
    
    /**
     * Force immediate sync
     */
    public function force_sync() {
        $this->poll_nostr_updates();
    }
    
    /**
     * Get sync statistics
     */
    public function get_sync_stats() {
        $stats = array(
            'connected_users' => 0,
            'total_events_processed' => 0,
            'last_sync' => null,
            'cron_status' => $this->get_cron_status()
        );
        
        // Check if site has Nostr connected (single site identity)
        $nostr_client = Nostr_Client::get_instance();
        $public_key = $nostr_client->get_public_key();
        $stats['connected_users'] = $public_key ? 1 : 0;
        
        // Get last sync time
        $stats['last_sync'] = get_option('nostr_last_cron_sync');
        
        // Count total events processed (this would need to be tracked separately)
        $stats['total_events_processed'] = get_option('nostr_events_processed', 0);
        
        return $stats;
    }
    
    /**
     * Handle settings update - reschedule cron if needed
     */
    public function handle_settings_update($old_value, $new_value) {
        // Check if auto_sync_enabled or sync_interval changed
        $old_auto_sync = isset($old_value['auto_sync_enabled']) ? (bool) $old_value['auto_sync_enabled'] : true;
        $new_auto_sync = isset($new_value['auto_sync_enabled']) ? (bool) $new_value['auto_sync_enabled'] : true;
        
        $old_interval = isset($old_value['sync_interval']) ? intval($old_value['sync_interval']) : 300;
        $new_interval = isset($new_value['sync_interval']) ? intval($new_value['sync_interval']) : 300;
        
        // If auto sync was disabled or interval changed, reschedule
        if ($old_auto_sync !== $new_auto_sync || $old_interval !== $new_interval) {
            $this->unschedule_cron();
            $this->schedule_cron();
        }
    }
    
    /**
     * Update sync interval
     */
    public function update_sync_interval($interval) {
        // Validate interval (minimum 60 seconds)
        $interval = max(60, intval($interval));
        
        // Update options
        $options = get_option('nostr_for_wp_options', array());
        $options['sync_interval'] = $interval;
        update_option('nostr_for_wp_options', $options);
        
        // Reschedule cron with new interval (handle_settings_update will handle this)
        // But we can also call it directly here for immediate effect
        $this->unschedule_cron();
        $this->schedule_cron();
        
        return true;
    }
    
    /**
     * Test relay connections for site (single site identity)
     */
    public function test_all_relays() {
        $nip07_handler = Nostr_NIP07_Handler::get_instance();
        $relays = $nip07_handler->get_user_relays();
        $results = array();
        
        foreach ($relays as $relay) {
            $results[$relay] = $nip07_handler->test_relay($relay);
        }
        
        return $results;
    }
    
    /**
     * Clean up old sync data
     */
    public function cleanup_old_data($days = 30) {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        
        // Clean up old sync status entries
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->postmeta} 
            WHERE meta_key = '_nostr_sync_status' 
            AND meta_value = 'failed' 
            AND post_id IN (
                SELECT ID FROM {$wpdb->posts} 
                WHERE post_modified < %s
            )
        ", $cutoff_date));
        
        return true;
    }
    
    /**
     * Get sync logs
     */
    public function get_sync_logs($limit = 50) {
        global $wpdb;
        
        $logs = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, p.post_type, pm.meta_value as sync_status, pm2.meta_value as synced_at
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_nostr_sync_status'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_nostr_synced_at'
            WHERE p.post_status = 'publish'
            AND (pm.meta_value IS NOT NULL OR pm2.meta_value IS NOT NULL)
            ORDER BY pm2.meta_value DESC
            LIMIT %d
        ", $limit));
        
        return $logs;
    }
}
