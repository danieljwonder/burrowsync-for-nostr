<?php
/**
 * Nostr Client Class
 * 
 * Handles WebSocket relay connections and Nostr protocol communication
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_Client {
    
    /**
     * Single instance
     */
    private static $instance = null;
    
    /**
     * WebSocket connections
     */
    private $connections = array();
    
    /**
     * User's public key
     */
    private $public_key = null;
    
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
        // Initialize any required hooks
    }
    
    /**
     * Get site's configured relays (single site identity)
     */
    public function get_user_relays($user_id = null) {
        $options = get_option('nostr_for_wp_options', array());
        
        // Check for site-level relays
        if (isset($options['relays']) && is_array($options['relays']) && !empty($options['relays'])) {
            return $options['relays'];
        }
        
        // Fall back to default relays
        if (isset($options['default_relays']) && is_array($options['default_relays'])) {
            return $options['default_relays'];
        }
        
        // Return hardcoded defaults
        return array(
            'wss://relay.damus.io',
            'wss://relay.snort.social',
            'wss://nos.lol'
        );
    }
    
    /**
     * Set user's public key
     */
    public function set_public_key($public_key) {
        $this->public_key = $public_key;
    }
    
    /**
     * Get site's public key (single site identity)
     */
    public function get_public_key() {
        if (!$this->public_key) {
            $options = get_option('nostr_for_wp_options', array());
            $this->public_key = isset($options['public_key']) ? $options['public_key'] : null;
        }
        return $this->public_key;
    }
    
    /**
     * Create a Nostr event
     */
    public function create_event($kind, $content, $tags = array()) {
        $event = array(
            'kind' => $kind,
            'content' => $content,
            'tags' => $tags,
            'created_at' => time()
        );
        
        return $event;
    }
    
    /**
     * Sign an event (requires NIP-07 browser extension)
     */
    public function sign_event($event) {
        // This will be handled by JavaScript via NIP-07
        // The actual signing happens in the browser
        return $event;
    }
    
    /**
     * Publish an event to relays
     *
     * @param array      $event       signed event
     * @param int|null   $user_id     unused (single site identity)
     * @param array|null $only_relays publish to just these relays instead
     *                                of the whole list (used by retries)
     */
    public function publish_event($event, $user_id = null, $only_relays = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $relays = is_array($only_relays) ? $only_relays : $this->get_user_relays($user_id);
        $results = array();
        
        foreach ($relays as $relay_url) {
            try {
                $result = $this->send_to_relay($relay_url, $event);
                $results[$relay_url] = $result;
            } catch (Exception $e) {
                $results[$relay_url] = array(
                    'success' => false,
                    'error' => $e->getMessage()
                );
            }
        }
        
        return $results;
    }
    
    /**
     * Send event to a specific relay via WebSocket
     */
    private function send_to_relay($relay_url, $event) {
        $websocket_file = NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-websocket-client.php';
        
        if (!file_exists($websocket_file)) {
            throw new Exception('WebSocket client file not found: ' . $websocket_file);
        }
        
        require_once $websocket_file;
        
        $websocket = new Nostr_WebSocket_Client($relay_url);
        
        try {
            // Connect to relay
            error_log('Nostr: Attempting to connect to relay ' . $relay_url);
            if (!$websocket->connect()) {
                $why = method_exists($websocket, 'get_last_error') ? $websocket->get_last_error() : '';
                throw new Exception('Failed to connect to relay ' . $relay_url . ($why !== '' ? ': ' . $why : ''));
            }
            error_log('Nostr: Successfully connected to relay ' . $relay_url);
            
            // Create Nostr EVENT message
            $message = json_encode(array('EVENT', $event));
            
            // Send message
            if (!$websocket->send_message($message)) {
                throw new Exception('Failed to send message to relay');
            }
            
            // Read response (with timeout)
            $start_time = time();
            $response = null;
            
            while ((time() - $start_time) < 10) { // 10 second timeout
                try {
                    $response = $websocket->read_message();
                    if ($response) {
                        $data = json_decode($response, true);
                        if (is_array($data) && count($data) >= 2) {
                            if ($data[0] === 'OK' && $data[1] === $event['id']) {
                                // NIP-01: ["OK", <id>, <true|false>, <message>].
                                // OK=false is a refusal (e.g. "restricted: pay
                                // for access"), not a success.
                                $websocket->close();
                                $accepted = isset($data[2]) && $data[2] === true;
                                $reason = isset($data[3]) && is_string($data[3]) ? $data[3] : '';
                                return array(
                                    'success'     => $accepted,
                                    'ok_received' => true,
                                    'message'     => $reason,
                                    'error'       => $accepted ? '' : ($reason !== '' ? $reason : 'Relay refused the event'),
                                    'response'    => $data
                                );
                            } elseif ($data[0] === 'NOTICE') {
                                $websocket->close();
                                return array(
                                    'success' => false,
                                    'error' => $data[1]
                                );
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log('Nostr: WebSocket read error: ' . $e->getMessage());
                    break;
                }
                usleep(100000); // 100ms delay
            }
            
            $websocket->close();
            return array(
                'success' => false,
                'error' => 'Timeout waiting for relay response'
            );
            
        } catch (Exception $e) {
            $websocket->close();
            throw $e;
        }
    }
    
    /**
     * Subscribe to events from relays (single site identity)
     */
    public function subscribe_to_events($filters = array(), $user_id = null) {
        // Get site's relays (user_id parameter kept for backward compatibility but not used)
        $relays = $this->get_user_relays();
        $events = array();
        
        foreach ($relays as $relay_url) {
            try {
                $relay_events = $this->query_relay($relay_url, $filters);
                $events = array_merge($events, $relay_events);
            } catch (Exception $e) {
                error_log('Nostr relay query failed for ' . $relay_url . ': ' . $e->getMessage());
                // Continue with other relays even if one fails
            }
        }
        
        return $events;
    }
    
    /**
     * Query a specific relay for events via WebSocket
     */
    private function query_relay($relay_url, $filters) {
        $websocket_file = NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-websocket-client.php';
        
        if (!file_exists($websocket_file)) {
            throw new Exception('WebSocket client file not found: ' . $websocket_file);
        }
        
        require_once $websocket_file;
        
        $websocket = new Nostr_WebSocket_Client($relay_url);
        $events = array();
        
        try {
            // Connect to relay
            error_log('Nostr: Attempting to query relay ' . $relay_url);
            if (!$websocket->connect()) {
                throw new Exception('Failed to connect to relay ' . $relay_url);
            }
            error_log('Nostr: Successfully connected to relay for query ' . $relay_url);
            
            // Generate subscription ID
            $subscription_id = 'sub_' . uniqid();
            
            // Create REQ message
            $req_message = array('REQ', $subscription_id, $filters);
            $message = json_encode($req_message);
            
            // Send REQ message
            error_log('Nostr: Sending REQ message: ' . $message);
            if (!$websocket->send_message($message)) {
                throw new Exception('Failed to send REQ message to relay');
            }
            
            // Read events (with timeout)
            $start_time = time();
            $events_received = 0;
            $total_responses = 0;
            
            while ((time() - $start_time) < 30) { // 30 second timeout
                $response = $websocket->read_message();
                if ($response) {
                    $total_responses++;
                    error_log('Nostr: Received from relay: ' . $response);
                    $data = json_decode($response, true);
                    if (is_array($data) && count($data) >= 2) {
                        if ($data[0] === 'EVENT' && $data[1] === $subscription_id) {
                            // This is an event
                            if (isset($data[2])) {
                                $events[] = $data[2];
                                $events_received++;
                                error_log('Nostr: Received event: ' . json_encode($data[2]));
                            }
                        } elseif ($data[0] === 'EOSE' && $data[1] === $subscription_id) {
                            // End of stored events
                            error_log('Nostr: Received EOSE from relay');
                            break;
                        } elseif ($data[0] === 'NOTICE') {
                            // Relay notice
                            error_log('Relay notice: ' . $data[1]);
                        }
                    }
                }
                usleep(100000); // 100ms delay
            }
            
            // Send CLOSE message
            $close_message = json_encode(array('CLOSE', $subscription_id));
            $websocket->send_message($close_message);
            
            $websocket->close();
            
            return $events;
            
        } catch (Exception $e) {
            $websocket->close();
            throw $e;
        }
    }
    
    /**
     * Get events by kind
     */
    public function get_events_by_kind($kind, $user_id = null) {
        $filters = array(
            'kinds' => array($kind)
        );
        
        return $this->subscribe_to_events($filters, $user_id);
    }
    
    /**
     * Get events by author
     */
    public function get_events_by_author($author_pubkey, $user_id = null) {
        $filters = array(
            'authors' => array($author_pubkey)
        );
        
        return $this->subscribe_to_events($filters, $user_id);
    }
    
    /**
     * Get events by tag
     */
    public function get_events_by_tag($tag_name, $tag_value, $user_id = null) {
        $filters = array(
            '#' . $tag_name => array($tag_value)
        );
        
        return $this->subscribe_to_events($filters, $user_id);
    }
    
    /**
     * Validate event signature
     */
    public function validate_event($event) {
        // Basic validation - in production you'd want proper cryptographic validation
        $required_fields = array('id', 'pubkey', 'created_at', 'kind', 'content', 'sig');
        
        foreach ($required_fields as $field) {
            if (!isset($event[$field])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Generate event ID
     */
    public function generate_event_id($event) {
        $serialized = json_encode(array(
            $event['pubkey'],
            $event['created_at'],
            $event['kind'],
            $event['tags'],
            $event['content']
        ));
        
        return hash('sha256', $serialized);
    }
    
    /**
     * Test relay connection via WebSocket
     */
    public function test_relay_connection($relay_url) {
        // First try a simple TCP connection test
        $parsed_url = parse_url($relay_url);
        if (!$parsed_url || !isset($parsed_url['host'])) {
            return false;
        }
        
        $host = $parsed_url['host'];
        $port = isset($parsed_url['port']) ? $parsed_url['port'] : (strpos($relay_url, 'wss://') === 0 ? 443 : 80);
        
        // Quick TCP connectivity test first
        $connection = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$connection) {
            return false;
        }
        fclose($connection);
        
        // If TCP works, the relay is reachable
        // For now, just return true if TCP works
        // WebSocket testing can be added later when needed
        return true;
    }
}

