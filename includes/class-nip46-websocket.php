<?php
/**
 * WebSocket client used by the NIP-46 remote signer connection.
 *
 * Why not a Composer WebSocket library? The well-known options were
 * evaluated: textalk/websocket is abandoned, and phrity/websocket >= 2.x
 * requires PHP 8.1 while this plugin supports PHP 7.4. Since the plugin
 * already ships a dependency-free WebSocket client for relay publishing,
 * NIP-46 uses this hardened variant instead of pulling in a large
 * dependency. Compared to Nostr_WebSocket_Client it adds the pieces a
 * longer-lived request/response wait requires:
 *
 *  - TLS certificate verification (the bunker secret travels over this)
 *  - Sec-WebSocket-Accept validation on the handshake
 *  - exact-length buffered reads (TLS streams return partial frames)
 *  - PING/PONG handling so relays don't drop us while we wait for the
 *    signer to respond (signers can take seconds if approval is manual)
 *  - CLOSE frame handling and message fragmentation reassembly
 *
 * It is intentionally client-side-only and text-frame-only, which is all
 * the Nostr relay protocol needs. Everything is outbound: the WordPress
 * host only needs to be able to open outgoing wss:// (TCP 443) connections.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_NIP46_WebSocket {

    /**
     * @var resource|null
     */
    private $socket = null;

    /**
     * @var string
     */
    private $url;

    /**
     * @var bool
     */
    private $connected = false;

    /**
     * Buffered bytes read from the stream but not yet consumed as frames.
     *
     * @var string
     */
    private $buffer = '';

    /**
     * @param string $url wss:// or ws:// relay URL
     */
    public function __construct($url) {
        $this->url = $url;
    }

    /**
     * Connect and perform the WebSocket handshake.
     *
     * @param int $timeout seconds
     * @return void
     * @throws Exception on any connection/handshake failure
     */
    public function connect($timeout = 10) {
        $parsed = wp_parse_url($this->url);
        if (!$parsed || empty($parsed['host'])) {
            throw new Exception('Invalid relay URL');
        }

        $secure = (isset($parsed['scheme']) && $parsed['scheme'] === 'wss');
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? intval($parsed['port']) : ($secure ? 443 : 80);
        $path = isset($parsed['path']) ? $parsed['path'] : '/';
        if (!empty($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }

        $context = stream_context_create(array(
            'ssl' => array(
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'SNI_enabled'      => true,
                'peer_name'        => $host,
            ),
        ));

        $remote = ($secure ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$this->socket) {
            throw new Exception(sprintf('Could not connect to relay %s (%s)', $host, $errstr ?: 'error ' . $errno));
        }

        stream_set_timeout($this->socket, $timeout);

        $key = base64_encode(random_bytes(16));
        $request = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "User-Agent: burrowsync-for-nostr/" . NOSTR_FOR_WP_VERSION . "\r\n"
            . "\r\n";

        if (fwrite($this->socket, $request) === false) {
            $this->close();
            throw new Exception('Failed to send WebSocket handshake');
        }

        $response = '';
        $deadline = microtime(true) + $timeout;
        while (strpos($response, "\r\n\r\n") === false) {
            if (microtime(true) > $deadline) {
                $this->close();
                throw new Exception('WebSocket handshake timed out');
            }
            $line = fgets($this->socket, 2048);
            if ($line === false) {
                $this->close();
                throw new Exception('WebSocket handshake failed (connection closed)');
            }
            $response .= $line;
        }

        if (!preg_match('#^HTTP/1\.[01] 101#', $response)) {
            $this->close();
            throw new Exception('Relay refused WebSocket upgrade');
        }

        // Validate Sec-WebSocket-Accept per RFC 6455 section 4.2.2.
        $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!preg_match('/Sec-WebSocket-Accept:\s*(\S+)/i', $response, $m) || trim($m[1]) !== $expected) {
            $this->close();
            throw new Exception('Invalid WebSocket handshake response');
        }

        $this->connected = true;
    }

    /**
     * Send a text frame (client frames are always masked per RFC 6455).
     *
     * @param string $payload
     * @return void
     * @throws Exception
     */
    public function send_text($payload) {
        if (!$this->connected) {
            throw new Exception('Not connected');
        }

        $length = strlen($payload);
        $frame = chr(0x81); // FIN + text opcode

        if ($length < 126) {
            $frame .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $length);
        }

        $mask = random_bytes(4);
        $frame .= $mask;
        for ($i = 0; $i < $length; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        $written = 0;
        $total = strlen($frame);
        while ($written < $total) {
            $n = fwrite($this->socket, substr($frame, $written));
            if ($n === false || $n === 0) {
                $this->connected = false;
                throw new Exception('Failed to write to relay socket');
            }
            $written += $n;
        }
    }

    /**
     * Wait for the next complete text message.
     *
     * Control frames (ping/pong/close) and fragmentation are handled
     * transparently. Returns null when the timeout elapses without a
     * complete message, or when the peer closes the connection.
     *
     * @param float $timeout seconds to wait
     * @return string|null
     */
    public function receive($timeout = 1.0) {
        if (!$this->connected) {
            return null;
        }

        $deadline = microtime(true) + $timeout;
        $message = '';
        $fragmented = false;

        while (microtime(true) <= $deadline) {
            $header = $this->read_exact(2, $deadline);
            if ($header === null) {
                return null;
            }

            $b0 = ord($header[0]);
            $b1 = ord($header[1]);
            $fin = ($b0 & 0x80) !== 0;
            $opcode = $b0 & 0x0F;
            $masked = ($b1 & 0x80) !== 0;
            $len = $b1 & 0x7F;

            if ($len === 126) {
                $ext = $this->read_exact(2, $deadline);
                if ($ext === null) {
                    return null;
                }
                $len = unpack('n', $ext)[1];
            } elseif ($len === 127) {
                $ext = $this->read_exact(8, $deadline);
                if ($ext === null) {
                    return null;
                }
                $len = unpack('J', $ext)[1];
            }

            // Relays should not send absurdly large frames; guard memory.
            if ($len > 4194304) {
                $this->close();
                return null;
            }

            $mask = '';
            if ($masked) {
                $mask = $this->read_exact(4, $deadline);
                if ($mask === null) {
                    return null;
                }
            }

            $payload = ($len > 0) ? $this->read_exact($len, $deadline) : '';
            if ($payload === null) {
                return null;
            }
            if ($masked && $mask !== '') {
                for ($i = 0; $i < $len; $i++) {
                    $payload[$i] = $payload[$i] ^ $mask[$i % 4];
                }
            }

            switch ($opcode) {
                case 0x1: // text
                    if ($fin) {
                        return $payload;
                    }
                    $message = $payload;
                    $fragmented = true;
                    break;

                case 0x0: // continuation
                    if ($fragmented) {
                        $message .= $payload;
                        if ($fin) {
                            return $message;
                        }
                    }
                    break;

                case 0x9: // ping -> answer with pong carrying same payload
                    $this->send_control(0xA, $payload);
                    break;

                case 0xA: // pong -> ignore
                    break;

                case 0x8: // close
                    $this->send_control(0x8, '');
                    $this->close();
                    return null;

                default: // binary and reserved opcodes are not used by relays
                    break;
            }
        }

        return null;
    }

    /**
     * Read exactly $n bytes from the stream, buffering as needed.
     *
     * @param int $n
     * @param float $deadline microtime(true) deadline
     * @return string|null null on timeout or closed connection
     */
    private function read_exact($n, $deadline) {
        while (strlen($this->buffer) < $n) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0 || !$this->socket) {
                return null;
            }

            $read = array($this->socket);
            $write = null;
            $except = null;
            $sec = (int) floor(min($remaining, 1.0));
            $usec = (int) ((min($remaining, 1.0) - $sec) * 1000000);
            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false) {
                $this->connected = false;
                return null;
            }
            if ($ready === 0) {
                // No data yet; loop until overall deadline. TLS streams may
                // hold decrypted bytes internally, so also try a read when
                // the buffer is empty but the stream reports pending data.
                continue;
            }

            $chunk = fread($this->socket, max($n - strlen($this->buffer), 8192));
            if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                $this->connected = false;
                return null;
            }
            $this->buffer .= $chunk;
        }

        $out = substr($this->buffer, 0, $n);
        $this->buffer = substr($this->buffer, $n);
        return $out;
    }

    /**
     * Send a masked control frame (ping/pong/close).
     */
    private function send_control($opcode, $payload) {
        if (!$this->connected || strlen($payload) > 125) {
            return;
        }
        $frame = chr(0x80 | $opcode) . chr(0x80 | strlen($payload));
        $mask = random_bytes(4);
        $frame .= $mask;
        $len = strlen($payload);
        for ($i = 0; $i < $len; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }
        @fwrite($this->socket, $frame);
    }

    /**
     * @return bool
     */
    public function is_connected() {
        return $this->connected && $this->socket;
    }

    /**
     * Close the connection.
     */
    public function close() {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
        $this->buffer = '';
    }

    public function __destruct() {
        $this->close();
    }
}
