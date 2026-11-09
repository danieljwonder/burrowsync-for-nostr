<?php
/**
 * NIP-46 Remote Signer admin UI
 *
 * Renders the "Signing Method" card on Settings > Nostr, the per-post
 * Nostr status column, and the bunker meta box. All state-changing
 * endpoints require manage_options and the shared admin nonce. The bunker
 * URI secret is never echoed back to the browser after saving.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_NIP46_Admin {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * @var Nostr_NIP46_Settings
     */
    private $settings;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = Nostr_NIP46_Settings::get_instance();
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('nostr_for_wp_settings_cards', array($this, 'render_signing_method_card'));

        add_action('wp_ajax_nostr_nip46_save_settings', array($this, 'save_settings_ajax'));
        add_action('wp_ajax_nostr_nip46_connect_test', array($this, 'connect_test_ajax'));
        add_action('wp_ajax_nostr_nip46_reset_client_key', array($this, 'reset_client_key_ajax'));
        add_action('wp_ajax_nostr_nip46_clear_bunker', array($this, 'clear_bunker_ajax'));

        // Per-post status surfacing (only meaningful in bunker mode).
        if ($this->settings->get_signing_method() === 'nip46') {
            add_filter('manage_post_posts_columns', array($this, 'add_status_column'));
            add_filter('manage_note_posts_columns', array($this, 'add_status_column'));
            add_action('manage_post_posts_custom_column', array($this, 'render_status_column'), 10, 2);
            add_action('manage_note_posts_custom_column', array($this, 'render_status_column'), 10, 2);
            add_action('add_meta_boxes', array($this, 'add_meta_box'));
            add_action('admin_footer-edit.php', array($this, 'print_retry_script'));
        }
    }

    /* ---------------------------------------------------------------------
     * Settings card
     * ------------------------------------------------------------------ */

    public function render_signing_method_card() {
        $method = $this->settings->get_signing_method();
        $bunker = $this->settings->get_bunker_display_info();
        $user_pubkey = $this->settings->get_user_pubkey();
        $client_pubkey = $this->settings->get_client_pubkey();
        $connected_at = $this->settings->get_connected_at();
        $has_uri = ($this->settings->get_bunker_uri() !== null);
        $can_encrypt = $this->settings->can_encrypt();
        $npub = '';
        if ($user_pubkey) {
            try {
                $npub = Nostr_NIP46_Crypto::npub_encode($user_pubkey);
            } catch (Exception $e) {
                $npub = '';
            }
        }

        // Surface the client identity BEFORE a successful connect so the
        // bunker operator can authorise this pubkey (nak bunker etc.). The
        // keypair is generated once and reused, so showing it early does not
        // change it.
        $client_npub = '';
        if ($method === 'nip46' && $can_encrypt) {
            if (!$client_pubkey) {
                try {
                    $keypair = $this->settings->get_client_keypair();
                    $client_pubkey = $keypair['pubkey'];
                } catch (Exception $e) {
                    $client_pubkey = null;
                }
            }
            if ($client_pubkey) {
                try {
                    $client_npub = Nostr_NIP46_Crypto::npub_encode($client_pubkey);
                } catch (Exception $e) {
                    $client_npub = '';
                }
            }
        }

        // Whether the saved bunker URI actually carries a secret. A missing
        // secret is a common, silent cause of connect timeouts: nak bunker
        // ignores connect requests it cannot authorise.
        $has_secret = false;
        if ($has_uri) {
            try {
                $parsed_uri = Nostr_NIP46_Settings::parse_bunker_uri($this->settings->get_bunker_uri());
                $has_secret = ($parsed_uri['secret'] !== '');
            } catch (Exception $e) {
                $has_secret = false;
            }
        }
        ?>
        <div class="nostr-card nostr-card-full" id="nostr-nip46-card">
            <h2><?php _e('Signing Method', 'burrowsync-for-nostr'); ?></h2>
            <p class="description">
                <?php _e('Choose how outbound events (posts and notes) are signed. The browser extension requires you to be present; a remote signer (bunker) lets the server sign automatically — enabling scheduled posts — while your private key stays in the bunker.', 'burrowsync-for-nostr'); ?>
            </p>

            <?php if (!$can_encrypt): ?>
                <div class="notice notice-error inline"><p>
                    <?php _e('Remote signer support requires the PHP sodium extension and AUTH_KEY defined in wp-config.php (used to encrypt the connection secrets at rest). Ask your host to enable sodium.', 'burrowsync-for-nostr'); ?>
                </p></div>
            <?php endif; ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Sign outbound events with', 'burrowsync-for-nostr'); ?></th>
                    <td>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="radio" name="nostr_signing_method" value="nip07" <?php checked($method, 'nip07'); ?>>
                            <?php _e('Browser extension (NIP-07)', 'burrowsync-for-nostr'); ?>
                            <span class="description"><?php _e('— default; signing happens in your browser', 'burrowsync-for-nostr'); ?></span>
                        </label>
                        <label style="display:block;">
                            <input type="radio" name="nostr_signing_method" value="nip46" <?php checked($method, 'nip46'); ?> <?php disabled(!$can_encrypt); ?>>
                            <?php _e('Remote signer / bunker (NIP-46)', 'burrowsync-for-nostr'); ?>
                            <span class="description"><?php _e('— server-side signing via your bunker (e.g. nak bunker, nsec.app, Amber)', 'burrowsync-for-nostr'); ?></span>
                        </label>
                    </td>
                </tr>
            </table>

            <div id="nostr-nip46-config" style="<?php echo $method === 'nip46' ? '' : 'display:none;'; ?>">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Bunker connection URI', 'burrowsync-for-nostr'); ?></th>
                        <td>
                            <?php if ($has_uri && $bunker['remote_signer_pubkey']): ?>
                                <p style="margin-top:0;">
                                    <span class="dashicons dashicons-lock" style="color:#2271b1;"></span>
                                    <strong><?php _e('Saved:', 'burrowsync-for-nostr'); ?></strong>
                                    <code>bunker://<?php echo esc_html(substr($bunker['remote_signer_pubkey'], 0, 16)); ?>&hellip;?relay=<?php echo esc_html(implode(',', array_map('esc_url', $bunker['relays']))); ?>&amp;secret=<?php echo $has_secret ? '&#9679;&#9679;&#9679;&#9679;&#9679;&#9679;' : '<span style="color:#b32d2e;">(none)</span>'; ?></code>
                                </p>
                                <?php if (!$has_secret): ?>
                                    <div class="notice notice-warning inline" style="margin:6px 0;"><p>
                                        <?php _e('The saved bunker URI has no <code>secret</code> parameter. Most signers (including nak bunker) silently ignore connect requests without a valid secret, which shows up as a connection timeout. Paste the full URI including <code>&amp;secret=…</code>.', 'burrowsync-for-nostr'); ?>
                                    </p></div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <input type="password" id="nostr-nip46-uri" class="large-text" autocomplete="off"
                                   placeholder="<?php echo esc_attr($has_uri ? __('Paste a new bunker:// URI to replace the saved one', 'burrowsync-for-nostr') : 'bunker://<pubkey>?relay=wss://...&secret=...'); ?>">
                            <p class="description">
                                <?php _e('Printed by your remote signer, e.g. by <code>nak bunker --persist</code>. Contains a secret — it is encrypted before being stored and never shown again.', 'burrowsync-for-nostr'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" class="button button-primary" id="nostr-nip46-save"><?php _e('Save', 'burrowsync-for-nostr'); ?></button>
                    <button type="button" class="button" id="nostr-nip46-test" <?php disabled(!$has_uri); ?>><?php _e('Connect and test', 'burrowsync-for-nostr'); ?></button>
                    <?php if ($has_uri): ?>
                        <button type="button" class="button" id="nostr-nip46-clear"><?php _e('Remove bunker', 'burrowsync-for-nostr'); ?></button>
                    <?php endif; ?>
                    <span id="nostr-nip46-spinner" class="spinner" style="float:none;margin:0 4px;"></span>
                </p>

                <div id="nostr-nip46-message"></div>

                <div id="nostr-nip46-status-panel" style="background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:12px 15px;margin-top:10px;">
                    <strong><?php _e('Connection status', 'burrowsync-for-nostr'); ?></strong>
                    <table style="margin-top:8px;font-size:13px;">
                        <tr>
                            <td style="padding:2px 12px 2px 0;"><?php _e('State:', 'burrowsync-for-nostr'); ?></td>
                            <td id="nostr-nip46-state">
                                <?php if ($user_pubkey): ?>
                                    <span style="color:green;">&#9679;</span> <?php printf(__('Connected (last verified %s)', 'burrowsync-for-nostr'), esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $connected_at))); ?>
                                <?php elseif ($has_uri): ?>
                                    <span style="color:orange;">&#9679;</span> <?php _e('Saved, not tested yet', 'burrowsync-for-nostr'); ?>
                                <?php else: ?>
                                    <span style="color:#999;">&#9679;</span> <?php _e('Not configured', 'burrowsync-for-nostr'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:2px 12px 2px 0;"><?php _e('User pubkey (hex):', 'burrowsync-for-nostr'); ?></td>
                            <td><code id="nostr-nip46-pubkey-hex" style="font-size:11px;"><?php echo $user_pubkey ? esc_html($user_pubkey) : '&mdash;'; ?></code></td>
                        </tr>
                        <tr>
                            <td style="padding:2px 12px 2px 0;"><?php _e('User pubkey (npub):', 'burrowsync-for-nostr'); ?></td>
                            <td><code id="nostr-nip46-pubkey-npub" style="font-size:11px;"><?php echo $npub ? esc_html($npub) : '&mdash;'; ?></code></td>
                        </tr>
                        <tr>
                            <td style="padding:2px 12px 2px 0;vertical-align:top;"><?php _e('Plugin client pubkey (hex):', 'burrowsync-for-nostr'); ?></td>
                            <td><code id="nostr-nip46-client-hex" style="font-size:11px;"><?php echo $client_pubkey ? esc_html($client_pubkey) : __('generated on first connect', 'burrowsync-for-nostr'); ?></code></td>
                        </tr>
                        <tr>
                            <td style="padding:2px 12px 2px 0;vertical-align:top;"><?php _e('Plugin client pubkey (npub):', 'burrowsync-for-nostr'); ?></td>
                            <td><code id="nostr-nip46-client-npub" style="font-size:11px;"><?php echo $client_npub ? esc_html($client_npub) : '&mdash;'; ?></code></td>
                        </tr>
                    </table>
                    <?php if ($client_pubkey): ?>
                        <p class="description" style="margin:10px 0 0;">
                            <?php _e('This is the identity the bunker sees for this site. If your signer requires clients to be authorised by pubkey, authorise the key above. Otherwise, make sure the saved bunker URI carries a valid <code>secret</code> — that is what authorises this client on first connect.', 'burrowsync-for-nostr'); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <p style="margin-top:15px;">
                    <button type="button" class="button button-link-delete" id="nostr-nip46-reset-key"><?php _e('Reset client key', 'burrowsync-for-nostr'); ?></button>
                    <span class="description"><?php _e('Generates a new client keypair. The bunker will no longer recognise this site and you will need a fresh bunker:// URI (new secret) to re-authorise.', 'burrowsync-for-nostr'); ?></span>
                </p>
            </div>
        </div>

        <script>
        (function($) {
            var nonce = <?php echo wp_json_encode(wp_create_nonce('nostr_for_wp_admin_nonce')); ?>;

            function msg(text, ok) {
                $('#nostr-nip46-message').html(
                    $('<div>').addClass('nostr-message ' + (ok ? 'success' : 'error')).text(text)
                );
            }
            function msgWithDiag(text, diagnostics) {
                var box = $('<div>').addClass('nostr-message error').text(text || '');
                if (diagnostics && diagnostics.length) {
                    var details = $('<details>').css('margin-top', '8px');
                    details.append($('<summary>').text(<?php echo wp_json_encode(__('Connection trace (what happened, step by step)', 'burrowsync-for-nostr')); ?>).css('cursor', 'pointer'));
                    var pre = $('<pre>').css({'white-space': 'pre-wrap', 'margin': '6px 0 0', 'font-size': '12px'});
                    pre.text(diagnostics.join('\n'));
                    details.append(pre);
                    box.append(details);
                }
                $('#nostr-nip46-message').html(box);
            }
            function busy(on) {
                $('#nostr-nip46-spinner').toggleClass('is-active', on);
                $('#nostr-nip46-save, #nostr-nip46-test, #nostr-nip46-clear, #nostr-nip46-reset-key').prop('disabled', on);
            }

            $('input[name="nostr_signing_method"]').on('change', function() {
                var method = $('input[name="nostr_signing_method"]:checked').val();
                $('#nostr-nip46-config').toggle(method === 'nip46');
                busy(true);
                $.post(ajaxurl, {
                    action: 'nostr_nip46_save_settings',
                    nonce: nonce,
                    signing_method: method
                }).always(function() { busy(false); });
            });

            $('#nostr-nip46-save').on('click', function() {
                var uri = $('#nostr-nip46-uri').val().trim();
                busy(true);
                $.post(ajaxurl, {
                    action: 'nostr_nip46_save_settings',
                    nonce: nonce,
                    signing_method: 'nip46',
                    bunker_uri: uri
                }).done(function(r) {
                    if (r.success) {
                        msg(r.data.message, true);
                        if (uri) { setTimeout(function() { location.reload(); }, 900); }
                    } else {
                        msg(r.data || <?php echo wp_json_encode(__('Save failed', 'burrowsync-for-nostr')); ?>, false);
                    }
                }).fail(function() {
                    msg(<?php echo wp_json_encode(__('Request failed', 'burrowsync-for-nostr')); ?>, false);
                }).always(function() { busy(false); });
            });

            $('#nostr-nip46-test').on('click', function() {
                busy(true);
                msg(<?php echo wp_json_encode(__('Contacting bunker via relay…', 'burrowsync-for-nostr')); ?>, true);
                $.post(ajaxurl, {
                    action: 'nostr_nip46_connect_test',
                    nonce: nonce
                }).done(function(r) {
                    if (r.success) {
                        msg(r.data.message, true);
                        $('#nostr-nip46-pubkey-hex').text(r.data.user_pubkey);
                        $('#nostr-nip46-pubkey-npub').text(r.data.npub);
                        $('#nostr-nip46-state').html('<span style="color:green;">&#9679;</span> ' + r.data.state);
                    } else {
                        if (r.data && typeof r.data === 'object') {
                            msgWithDiag(r.data.message, r.data.diagnostics);
                        } else {
                            msg(r.data, false);
                        }
                    }
                }).fail(function() {
                    msg(<?php echo wp_json_encode(__('Request failed', 'burrowsync-for-nostr')); ?>, false);
                }).always(function() { busy(false); });
            });

            $('#nostr-nip46-clear').on('click', function() {
                if (!confirm(<?php echo wp_json_encode(__('Remove the saved bunker connection? Server-side signing will stop working until you configure a new one.', 'burrowsync-for-nostr')); ?>)) {
                    return;
                }
                busy(true);
                $.post(ajaxurl, { action: 'nostr_nip46_clear_bunker', nonce: nonce })
                    .done(function() { location.reload(); })
                    .always(function() { busy(false); });
            });

            $('#nostr-nip46-reset-key').on('click', function() {
                if (!confirm(<?php echo wp_json_encode(__('Reset the NIP-46 client key?\n\nThe bunker authorised THIS key. After resetting, the bunker will refuse this site until you obtain a new bunker:// URI (with a fresh secret) and connect again.\n\nThis cannot be undone.', 'burrowsync-for-nostr')); ?>)) {
                    return;
                }
                busy(true);
                $.post(ajaxurl, { action: 'nostr_nip46_reset_client_key', nonce: nonce })
                    .done(function() { location.reload(); })
                    .always(function() { busy(false); });
            });
        })(jQuery);
        </script>
        <?php
    }

    /* ---------------------------------------------------------------------
     * AJAX endpoints
     * ------------------------------------------------------------------ */

    /**
     * Save the signing method and (optionally) a new bunker URI.
     */
    public function save_settings_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'burrowsync-for-nostr'));
        }

        $method = isset($_POST['signing_method']) ? sanitize_text_field(wp_unslash($_POST['signing_method'])) : 'nip07';
        $this->settings->set_signing_method($method);

        // The URI is deliberately NOT run through sanitize_text_field: the
        // secret may contain characters that would be mangled. It is
        // strictly validated by parse_bunker_uri() instead and never
        // echoed back or logged.
        $uri = isset($_POST['bunker_uri']) ? trim(wp_unslash($_POST['bunker_uri'])) : '';

        if ($uri !== '') {
            if (!$this->settings->can_encrypt()) {
                wp_send_json_error(__('Cannot store the bunker URI securely: the PHP sodium extension or AUTH_KEY is missing.', 'burrowsync-for-nostr'));
            }
            try {
                Nostr_NIP46_Settings::parse_bunker_uri($uri);
                $this->settings->save_bunker_uri($uri);
            } catch (Exception $e) {
                wp_send_json_error($e->getMessage());
            }
            wp_send_json_success(array('message' => __('Bunker URI saved. Now click "Connect and test".', 'burrowsync-for-nostr')));
        }

        wp_send_json_success(array('message' => __('Settings saved.', 'burrowsync-for-nostr')));
    }

    /**
     * "Connect and test": run the NIP-46 handshake and report the user
     * pubkey the bunker signs with.
     */
    public function connect_test_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'burrowsync-for-nostr'));
        }

        try {
            $client = new Nostr_NIP46_Client($this->settings);
            $result = $client->connect_and_test();

            // Adopt the bunker identity as the site pubkey if none is set
            // (inbound sync and event display rely on it).
            $options = get_option('nostr_for_wp_options', array());
            $extra = '';
            if (empty($options['public_key'])) {
                $options['public_key'] = $result['user_pubkey'];
                update_option('nostr_for_wp_options', $options);
            } elseif (strtolower($options['public_key']) !== $result['user_pubkey']) {
                $extra = ' ' . __('Warning: this differs from the site public key configured via NIP-07.', 'burrowsync-for-nostr');
            }

            wp_send_json_success(array(
                'message'     => sprintf(
                    /* translators: 1: encryption scheme */
                    __('Connected! The bunker signs with the key below (payload encryption: %s).', 'burrowsync-for-nostr'),
                    strtoupper($result['encryption'])
                ) . $extra,
                'user_pubkey' => $result['user_pubkey'],
                'npub'        => $result['npub'],
                'state'       => __('Connected (just now)', 'burrowsync-for-nostr'),
            ));
        } catch (Nostr_NIP46_Auth_Required_Exception $e) {
            wp_send_json_error(array(
                'message'     => sprintf(
                    /* translators: 1: authorisation URL */
                    __('The bunker requires authorisation. Open %s, approve this connection, then click "Connect and test" again.', 'burrowsync-for-nostr'),
                    $e->auth_url
                ),
                'diagnostics' => isset($client) ? $client->get_diagnostics() : array(),
            ));
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message'     => $e->getMessage(),
                'diagnostics' => isset($client) ? $client->get_diagnostics() : array(),
            ));
        }
    }

    /**
     * Explicit, warned reset of the client keypair.
     */
    public function reset_client_key_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'burrowsync-for-nostr'));
        }
        $this->settings->reset_client_keypair();
        wp_send_json_success(array('message' => __('Client key reset. Configure a new bunker URI to reconnect.', 'burrowsync-for-nostr')));
    }

    /**
     * Remove the stored bunker connection (keeps the client keypair).
     */
    public function clear_bunker_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'burrowsync-for-nostr'));
        }
        $this->settings->clear_bunker();
        wp_send_json_success(array('message' => __('Bunker connection removed.', 'burrowsync-for-nostr')));
    }

    /* ---------------------------------------------------------------------
     * Post list column
     * ------------------------------------------------------------------ */

    public function add_status_column($columns) {
        $columns['nostr_status'] = __('Nostr', 'burrowsync-for-nostr');
        return $columns;
    }

    public function render_status_column($column, $post_id) {
        if ($column !== 'nostr_status') {
            return;
        }

        $publisher = Nostr_NIP46_Publisher::get_instance();
        $info = $publisher->get_post_status($post_id);

        switch ($info['status']) {
            case 'synced':
                echo '<span style="color:green;" title="' . esc_attr($info['event_id']) . '">&#10003; ' . esc_html__('Published', 'burrowsync-for-nostr') . '</span>';
                break;

            case 'queued':
                echo '<span style="color:#996800;">&#8987; ' . esc_html__('Queued', 'burrowsync-for-nostr') . '</span>';
                if ($info['next_retry']) {
                    echo '<br><small>' . esc_html(sprintf(
                        /* translators: human-readable time diff */
                        __('retry in %s', 'burrowsync-for-nostr'),
                        human_time_diff(time(), max(time() + 1, $info['next_retry']))
                    )) . '</small>';
                }
                $this->print_retry_link($post_id);
                break;

            case 'failed':
                echo '<span style="color:#b32d2e;" title="' . esc_attr($info['last_error'] ?: '') . '">&#10007; ' . esc_html__('Failed', 'burrowsync-for-nostr') . '</span>';
                $this->print_retry_link($post_id);
                break;

            default:
                echo '<span style="color:#999;">&mdash;</span>';
        }
    }

    private function print_retry_link($post_id) {
        echo '<br><a href="#" class="nostr-nip46-retry" data-post-id="' . intval($post_id) . '">' . esc_html__('Retry now', 'burrowsync-for-nostr') . '</a>';
    }

    /**
     * Small handler for the "Retry now" links on post list screens.
     */
    public function print_retry_script() {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, array('post', 'note'), true)) {
            return;
        }
        ?>
        <script>
        (function($) {
            var nonce = <?php echo wp_json_encode(wp_create_nonce('nostr_for_wp_admin_nonce')); ?>;
            $(document).on('click', '.nostr-nip46-retry', function(e) {
                e.preventDefault();
                var link = $(this);
                link.text(<?php echo wp_json_encode(__('Retrying…', 'burrowsync-for-nostr')); ?>);
                $.post(ajaxurl, {
                    action: 'nostr_nip46_retry_post',
                    post_id: link.data('post-id'),
                    nonce: nonce
                }).done(function(r) {
                    if (r.success) {
                        link.replaceWith('<span style="color:green;">&#10003; ' + <?php echo wp_json_encode(__('Published', 'burrowsync-for-nostr')); ?> + '</span>');
                    } else {
                        alert(r.data);
                        link.text(<?php echo wp_json_encode(__('Retry now', 'burrowsync-for-nostr')); ?>);
                    }
                }).fail(function() {
                    link.text(<?php echo wp_json_encode(__('Retry now', 'burrowsync-for-nostr')); ?>);
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Meta box (bunker mode only; the NIP-07 meta box is untouched)
     * ------------------------------------------------------------------ */

    public function add_meta_box() {
        add_meta_box(
            'nostr-nip46-status',
            __('Nostr Remote Signer', 'burrowsync-for-nostr'),
            array($this, 'render_meta_box'),
            array('post', 'note'),
            'side',
            'default'
        );
    }

    public function render_meta_box($post) {
        $publisher = Nostr_NIP46_Publisher::get_instance();
        $info = $publisher->get_post_status($post->ID);
        ?>
        <div id="nostr-nip46-meta-box">
            <p>
                <strong><?php _e('Status:', 'burrowsync-for-nostr'); ?></strong>
                <?php
                switch ($info['status']) {
                    case 'synced':
                        echo '<span style="color:green;">' . esc_html__('Published to Nostr', 'burrowsync-for-nostr') . '</span>';
                        break;
                    case 'queued':
                        echo '<span style="color:#996800;">' . esc_html__('Queued for publishing', 'burrowsync-for-nostr') . '</span>';
                        break;
                    case 'failed':
                        echo '<span style="color:#b32d2e;">' . esc_html__('Failed', 'burrowsync-for-nostr') . '</span>';
                        break;
                    default:
                        echo '<span style="color:#999;">' . esc_html__('Not published', 'burrowsync-for-nostr') . '</span>';
                }
                ?>
            </p>

            <?php if ($info['event_id']): ?>
                <p><strong><?php _e('Event ID:', 'burrowsync-for-nostr'); ?></strong><br>
                <code style="font-size:11px;word-break:break-all;"><?php echo esc_html($info['event_id']); ?></code></p>
            <?php endif; ?>

            <?php if ($info['last_error']): ?>
                <p><strong><?php _e('Last error:', 'burrowsync-for-nostr'); ?></strong><br>
                <span style="font-size:12px;color:#b32d2e;"><?php echo esc_html($info['last_error']); ?></span></p>
            <?php endif; ?>

            <?php if ($info['next_retry'] && $info['status'] === 'queued'): ?>
                <p><strong><?php _e('Next retry:', 'burrowsync-for-nostr'); ?></strong>
                <?php echo esc_html(human_time_diff(time(), max(time() + 1, $info['next_retry']))); ?></p>
            <?php endif; ?>

            <?php if ($post->post_status === 'publish' && $info['status'] !== 'synced'): ?>
                <p>
                    <button type="button" class="button button-primary" id="nostr-nip46-retry-meta" data-post-id="<?php echo intval($post->ID); ?>">
                        <?php echo $info['status'] === 'none' ? esc_html__('Publish to Nostr now', 'burrowsync-for-nostr') : esc_html__('Retry now', 'burrowsync-for-nostr'); ?>
                    </button>
                </p>
            <?php endif; ?>

            <div id="nostr-nip46-meta-message"></div>
        </div>
        <script>
        (function($) {
            $('#nostr-nip46-retry-meta').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text(<?php echo wp_json_encode(__('Publishing…', 'burrowsync-for-nostr')); ?>);
                $.post(ajaxurl, {
                    action: 'nostr_nip46_retry_post',
                    post_id: btn.data('post-id'),
                    nonce: nostrForWPAdmin.nonce
                }).done(function(r) {
                    var ok = r.success;
                    $('#nostr-nip46-meta-message').html(
                        $('<div>').addClass('nostr-message ' + (ok ? 'success' : 'error'))
                                  .text(ok ? r.data.message : r.data)
                    );
                    if (ok) { setTimeout(function() { location.reload(); }, 1200); }
                }).always(function() {
                    btn.prop('disabled', false).text(<?php echo wp_json_encode(__('Retry now', 'burrowsync-for-nostr')); ?>);
                });
            });
        })(jQuery);
        </script>
        <?php
    }
}
