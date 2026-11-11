<?php
/**
 * WebSocket Client Class
 * 
 * Simplified WebSocket client for Nostr relay connections
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_WebSocket_Client {
    
    /**
     * WebSocket connection resource
     */
    private $socket = null;
    
    /**
     * Relay URL
     */
    private $relay_url = '';
    
    /**
     * Connection status
     */
    private $connected = false;

    /**
     * Why the last connect() failed (short, for status displays).
     */
    private $last_error = '';
    
    /**
     * Constructor
     */
    public function __construct($relay_url) {
        $this->relay_url = $relay_url;
    }
    
    /**
     * Connect to WebSocket relay
     */
    public function connect() {
        try {
            // Parse WebSocket URL
            $parsed = parse_url($this->relay_url);
            if (!$parsed || !isset($parsed['host'])) {
                throw new Exception('Invalid WebSocket URL');
            }
            
            $host = $parsed['host'];
            $port = isset($parsed['port']) ? $parsed['port'] : (strpos($this->relay_url, 'wss://') === 0 ? 443 : 80);
            $path = isset($parsed['path']) ? $parsed['path'] : '/';
            
            // Create socket connection with timeout
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ]
            ]);
            
            if (strpos($this->relay_url, 'wss://') === 0) {
                $socket_url = "ssl://{$host}:{$port}";
            } else {
                $socket_url = "tcp://{$host}:{$port}";
            }
            
            $this->socket = @stream_socket_client($socket_url, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
            
            if (!$this->socket) {
                throw new Exception("Failed to connect to {$host}:{$port} - {$errstr} ({$errno})");
            }
            
            // Set socket timeout
            stream_set_timeout($this->socket, 10);
            
            // Perform WebSocket handshake
            $this->perform_handshake($host, $path);
            
            $this->connected = true;
            return true;
            
        } catch (Exception $e) {
            error_log('WebSocket connection failed: ' . $e->getMessage());
            // Keep the first line only (e.g. "HTTP/1.1 503 Service Unavailable").
            $first = strtok(str_replace("\r", '', $e->getMessage()), "\n");
            $this->last_error = substr((string) $first, 0, 200);
            $this->connected = false;
            return false;
        }
    }
    
    /**
     * @return string reason the last connect() failed, or ''
     */
    public function get_last_error() {
        return $this->last_error;
    }

    /**
     * Perform WebSocket handshake
     */
    private function perform_handshake($host, $path) {
        $key = base64_encode(random_bytes(16));
        
        // Some relays (nostr.wine for one) answer 403 to a bare handshake and
        // expect a client to identify itself, so send the site as the origin.
        $site = function_exists('home_url') ? home_url() : '';
        $version = defined('NOSTR_FOR_WP_VERSION') ? NOSTR_FOR_WP_VERSION : 'dev';
        $headers = [
            "GET {$path} HTTP/1.1",
            "Host: {$host}",
            "Upgrade: websocket",
            "Connection: Upgrade",
            "Sec-WebSocket-Key: {$key}",
            "Sec-WebSocket-Version: 13",
            "User-Agent: Mozilla/5.0 (compatible; BurrowSync for Nostr/{$version}; +https://burrowsync.com)",
        ];
        if ($site !== '') {
            $headers[] = "Origin: {$site}";
        }
        
        $request = implode("\r\n", $headers) . "\r\n\r\n";
        
        if (fwrite($this->socket, $request) === false) {
            throw new Exception('Failed to send handshake request');
        }
        
        // Read handshake response
        $response = '';
        while (($line = fgets($this->socket)) !== false) {
            $response .= $line;
            if (strpos($response, "\r\n\r\n") !== false) {
                break;
            }
        }
        
        if (strpos($response, '101 Switching Protocols') === false && strpos($response, 'HTTP/1.1 101') === false) {
            throw new Exception('WebSocket handshake failed: ' . $response);
        }
    }
    
    /**
     * Send message to WebSocket
     */
    public function send_message($message) {
        if (!$this->connected || !$this->socket) {
            return false;
        }
        
        try {
            $frame = $this->create_frame($message);
            return fwrite($this->socket, $frame) !== false;
        } catch (Exception $e) {
            error_log('WebSocket send failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Read message from WebSocket
     */
    public function read_message() {
        if (!$this->connected || !$this->socket) {
            return false;
        }
        
        try {
            // Set a short timeout for reading
            stream_set_timeout($this->socket, 1);
            
            // Read frame header (2 bytes minimum)
            $header = fread($this->socket, 2);
            if (strlen($header) < 2) {
                return false;
            }
            
            // Check if this is a valid WebSocket frame
            if (ord($header[0]) === 0 && ord($header[1]) === 0) {
                // Empty frame, skip
                return false;
            }
            
            $first_byte = ord($header[0]);
            $second_byte = ord($header[1]);
            
            $fin = ($first_byte & 0x80) >> 7;
            $opcode = $first_byte & 0x0F;
            $masked = ($second_byte & 0x80) >> 7;
            $payload_length = $second_byte & 0x7F;
            
            // Handle extended payload length
            if ($payload_length === 126) {
                $length_bytes = fread($this->socket, 2);
                $payload_length = unpack('n', $length_bytes)[1];
            } elseif ($payload_length === 127) {
                $length_bytes = fread($this->socket, 8);
                $payload_length = unpack('J', $length_bytes)[1];
            }
            
            // Read masking key if present
            $masking_key = '';
            if ($masked) {
                $masking_key = fread($this->socket, 4);
            }
            
            // Read payload (only if there's actually a payload)
            $payload = '';
            if ($payload_length > 0) {
                $payload = fread($this->socket, $payload_length);
                
                // Unmask payload if necessary
                if ($masked && $masking_key) {
                    for ($i = 0; $i < $payload_length; $i++) {
                        $payload[$i] = $payload[$i] ^ $masking_key[$i % 4];
                    }
                }
            }
            
            return $payload;
            
        } catch (Exception $e) {
            error_log('WebSocket read failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create WebSocket frame
     */
    private function create_frame($message) {
        $length = strlen($message);
        
        // First byte: FIN=1, opcode=1 (text)
        $first_byte = 0x81;
        
        // Second byte: MASK=1 (client must mask), payload length
        if ($length < 126) {
            $second_byte = 0x80 | $length;
        } elseif ($length < 65536) {
            $second_byte = 0x80 | 126;
        } else {
            $second_byte = 0x80 | 127;
        }
        
        $frame = chr($first_byte) . chr($second_byte);
        
        // Add extended length if needed
        if ($length >= 126 && $length < 65536) {
            $frame .= pack('n', $length);
        } elseif ($length >= 65536) {
            $frame .= pack('J', $length);
        }
        
        // Generate masking key
        $masking_key = random_bytes(4);
        $frame .= $masking_key;
        
        // Mask the payload
        $masked_payload = '';
        for ($i = 0; $i < $length; $i++) {
            $masked_payload .= $message[$i] ^ $masking_key[$i % 4];
        }
        
        $frame .= $masked_payload;
        
        return $frame;
    }
    
    /**
     * Close WebSocket connection
     */
    public function close() {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
    }
    
    /**
     * Check if connected
     */
    public function is_connected() {
        return $this->connected && $this->socket;
    }
    
    /**
     * Destructor
     */
    public function __destruct() {
        $this->close();
    }
}