/**
 * Nostr Admin JavaScript
 * 
 * Handles NIP-07 integration and admin interface interactions
 */

(function($) {
    'use strict';
    
    // NIP-07 Integration
    window.nostrForWP = {
        // Check if NIP-07 extension is available
        isExtensionAvailable: function() {
            return typeof window.nostr !== 'undefined';
        },
        
        // Get public key from extension
        getPublicKey: function() {
            if (!this.isExtensionAvailable()) {
                throw new Error('NIP-07 extension not available');
            }
            return window.nostr.getPublicKey();
        },
        
        // Sign event with extension
        signEvent: function(event) {
            if (!this.isExtensionAvailable()) {
                throw new Error('NIP-07 extension not available');
            }
            return window.nostr.signEvent(event);
        },
        
        // Connect user (get public key and save it)
        connect: function() {
            var self = this;
            var deferred = $.Deferred();
            
            this.getPublicKey().then(function(publicKey) {
                $.post(ajaxurl, {
                    action: 'nostr_save_public_key',
                    public_key: publicKey,
                    nonce: nostrForWPAdmin.nonce
                }).done(function(response) {
                    deferred.resolve(response);
                }).fail(function(xhr, status, error) {
                    deferred.reject(xhr, status, error);
                });
            }).catch(function(error) {
                deferred.reject(null, 'error', error);
            });
            
            return deferred.promise();
        },
        
        // Disconnect user
        disconnect: function() {
            return $.post(ajaxurl, {
                action: 'nostr_disconnect',
                nonce: nostrForWPAdmin.nonce
            });
        },
        
        // Test relay connection
        testRelay: function(relayUrl) {
            return $.post(ajaxurl, {
                action: 'nostr_test_relay',
                relay: relayUrl,
                nonce: nostrForWPAdmin.nonce
            });
        },
        
        // Save relay configuration
        saveRelays: function(relays) {
            return $.post(ajaxurl, {
                action: 'nostr_save_relays',
                relays: relays,
                nonce: nostrForWPAdmin.nonce
            });
        },

        // NIP-65 relay list: fetch from Nostr, publish via bunker, broadcast browser-signed
        fetchRelayList: function() {
            return $.post(ajaxurl, { action: 'nostr_relay_list_fetch', nonce: nostrForWPAdmin.nonce });
        },
        publishRelayList: function(expectedId) {
            return $.post(ajaxurl, { action: 'nostr_relay_list_publish', expected_id: expectedId, nonce: nostrForWPAdmin.nonce });
        },
        broadcastRelayList: function(signedEvent) {
            return $.post(ajaxurl, { action: 'nostr_relay_list_broadcast', event: JSON.stringify(signedEvent), nonce: nostrForWPAdmin.nonce });
        },
        
        // Force sync - handles both outbound (WordPress → Nostr) and inbound (Nostr → WordPress)
        forceSync: function() {
            var self = this;
            var deferred = $.Deferred();
            
            console.log('Nostr: Starting force sync...');
            
            // First, handle outbound sync (WordPress → Nostr) if NIP-07 is available
            if (this.isExtensionAvailable()) {
                console.log('Nostr: NIP-07 available, checking for outbound posts...');
                $.post(ajaxurl, {
                    action: 'nostr_get_pending_posts',
                    nonce: nostrForWPAdmin.nonce
                }).done(function(response) {
                    console.log('Nostr: Pending posts response:', response);
                    if (response.success && response.data.posts.length > 0) {
                        console.log('Nostr: Found pending posts, signing and publishing...');
                        // Sign and publish each post
                        self.signAndPublishPosts(response.data.posts).then(function(results) {
                            // After outbound sync, do inbound sync
                            self.performInboundSync().then(function() {
                                deferred.resolve(results);
                            }).catch(function(error) {
                                deferred.reject(error);
                            });
                        }).catch(function(error) {
                            deferred.reject(error);
                        });
                    } else {
                        console.log('Nostr: No pending outbound posts, doing inbound sync only...');
                        // No pending posts, just do inbound sync
                        self.performInboundSync().then(function() {
                            deferred.resolve({
                                message: 'No pending outbound posts. Inbound sync completed.'
                            });
                        }).catch(function(error) {
                            deferred.reject(error);
                        });
                    }
                }).fail(function(xhr, status, error) {
                    console.error('Nostr: Failed to get pending posts:', xhr, status, error);
                    deferred.reject(xhr, status, error);
                });
            } else {
                console.log('Nostr: NIP-07 not available, doing inbound sync only...');
                // No NIP-07, just do inbound sync
                self.performInboundSync().then(function() {
                    deferred.resolve({
                        message: 'NIP-07 extension not available. Inbound sync completed.'
                    });
                }).catch(function(error) {
                    deferred.reject(error);
                });
            }
            
            return deferred.promise();
        },
        
        // Perform inbound sync (Nostr → WordPress)
        performInboundSync: function() {
            console.log('Nostr: Starting inbound sync...');
            return $.post(ajaxurl, {
                action: 'nostr_force_sync',
                nonce: nostrForWPAdmin.nonce
            });
        },
        
        
        // Get pending posts from server
        getPendingPosts: function() {
            return $.post(ajaxurl, {
                action: 'nostr_get_pending_posts',
                nonce: nostrForWPAdmin.nonce
            });
        },
        
        // Sign and publish posts using NIP-07
        signAndPublishPosts: function(posts) {
            console.log('Nostr: signAndPublishPosts called with', posts.length, 'posts');
            var self = this;
            var deferred = $.Deferred();
            var results = [];
            var currentIndex = 0;
            
            function processNextPost() {
                if (currentIndex >= posts.length) {
                    deferred.resolve({success: true, results: results});
                    return;
                }
                
                var post = posts[currentIndex];
                currentIndex++;
                
                // Get the Nostr event data from server
                $.post(ajaxurl, {
                    action: 'nostr_get_post_event',
                    post_id: post.ID,
                    nonce: nostrForWPAdmin.nonce
                }).done(function(response) {
                    if (response.success && response.data.event) {
                        var event = response.data.event;
                        
                        var signedEvent = null;
                        // Sign the event using NIP-07
                        self.signEvent(event).then(function(signed) {
                            signedEvent = signed;
                            // Publish the signed event (post id lets the server keep it)
                            return self.publishEvent(signedEvent, post.ID);
                        }).then(function(publishResult) {
                            results.push({
                                post_id: post.ID,
                                success: publishResult.success,
                                event_id: signedEvent.id
                            });
                            
                            // Mark post as synced
                            $.post(ajaxurl, {
                                action: 'nostr_mark_post_synced',
                                post_id: post.ID,
                                event_id: signedEvent.id,
                                nonce: nostrForWPAdmin.nonce
                            });
                            
                            processNextPost();
                        }).catch(function(error) {
                            results.push({
                                post_id: post.ID,
                                success: false,
                                error: error.message
                            });
                            processNextPost();
                        });
                    } else {
                        results.push({
                            post_id: post.ID,
                            success: false,
                            error: 'Failed to get event data'
                        });
                        processNextPost();
                    }
                }).fail(function() {
                    results.push({
                        post_id: post.ID,
                        success: false,
                        error: 'Failed to get event data'
                    });
                    processNextPost();
                });
            }
            
            processNextPost();
            return deferred.promise();
        },
        
        // Publish signed event to relays
        publishEvent: function(signedEvent, postId) {
            var deferred = $.Deferred();
            
            $.post(ajaxurl, {
                action: 'nostr_publish_event',
                event: JSON.stringify(signedEvent),
                post_id: postId || 0,
                nonce: nostrForWPAdmin.nonce
            }).done(function(response) {
                deferred.resolve(response);
            }).fail(function(xhr, status, error) {
                deferred.reject(xhr, status, error);
            });
            
            return deferred.promise();
        },
        
        // Manual sync for specific post
        manualSync: function(postId) {
            return $.post(ajaxurl, {
                action: 'nostr_manual_sync',
                post_id: postId,
                nonce: nostrForWPAdmin.nonce
            });
        },
        
        // Get sync status for post
        getSyncStatus: function(postId) {
            return $.post(ajaxurl, {
                action: 'nostr_get_sync_status',
                post_id: postId,
                nonce: nostrForWPAdmin.nonce
            });
        }
    };
    
    // Utility functions
    function showMessage(message, type, container) {
        type = type || 'info';
        container = container || $('#nostr-admin-messages');
        
        var messageClass = 'nostr-message ' + type;
        var messageDiv = $('<div class="' + messageClass + '">' + message + '</div>');
        
        container.html(messageDiv);
        
        // Auto-hide success and info messages
        if (type === 'success' || type === 'info') {
            setTimeout(function() {
                messageDiv.fadeOut();
            }, 3000);
        }
    }
    
    function formatPublicKey(publicKey) {
        if (!publicKey) return '';
        return publicKey.substring(0, 16) + '...';
    }
    
    function formatEventId(eventId) {
        if (!eventId) return '';
        return eventId.substring(0, 16) + '...';
    }
    
    // Connection status management
    function updateConnectionStatus() {
        if (nostrForWP.isExtensionAvailable()) {
            $('.nostr-extension-status').html('<span class="dashicons dashicons-yes-alt" style="color: green;"></span> ' + nostrForWPAdmin.strings.extensionAvailable);
        } else {
            $('.nostr-extension-status').html('<span class="dashicons dashicons-warning" style="color: orange;"></span> ' + nostrForWPAdmin.strings.extensionNotAvailable);
        }
    }
    
    // Relay management
    function addRelayItem(relayUrl) {
        var relayItem = $('<div class="nostr-relay-item">' +
            '<input type="url" name="relays[]" class="regular-text" value="' + (relayUrl || '') + '" placeholder="wss://relay.example.com">' +
            '<button type="button" class="button nostr-test-relay">' + (nostrForWPAdmin.strings.test || 'Test') + '</button>' +
            '<button type="button" class="button nostr-remove-relay">' + (nostrForWPAdmin.strings.remove || 'Remove') + '</button>' +
            '<span class="nostr-relay-status"></span>' +
            '</div>');
        return relayItem;
    }
    
    function testRelayConnection(relayUrl, statusSpan) {
        console.log('Testing relay:', relayUrl);
        
        nostrForWP.testRelay(relayUrl).done(function(response) {
            console.log('Relay test response:', response);
            var msg = (response && response.data && response.data.message) ? String(response.data.message) : '';
            if (response.success && response.data.success) {
                statusSpan.html('<span class="dashicons dashicons-yes-alt" style="color: green;"></span>').attr('title', msg || 'Relay answered');
            } else {
                statusSpan.html('<span class="dashicons dashicons-dismiss" style="color: #b32d2e;"></span> <span style="color:#b32d2e;font-size:12px;"></span>');
                statusSpan.find('span:last').text(msg || 'Relay did not answer');
            }
        }).fail(function(xhr, status, error) {
            console.log('Relay test failed:', xhr, status, error);
            statusSpan.html('<span style="color: red;" title="TCP connection test failed - network error"></span>');
        });
    }
    
    // Document ready
    $(document).ready(function() {
        console.log('=== NOSTR PLUGIN DEBUG ===');
        console.log('Nostr admin JavaScript loaded');
        console.log('jQuery version:', $.fn.jquery);
        console.log('nostrForWPAdmin:', nostrForWPAdmin);
        
        // Test if we can find the force sync button
        console.log('Force sync button found:', $('#nostr-force-sync').length);
        console.log('Force sync button element:', $('#nostr-force-sync')[0]);
        
        // Test if we can find the buttons
        console.log('Add relay button found:', $('#nostr-add-relay').length);
        console.log('Save relays button found:', $('#nostr-save-relays').length);
        console.log('Relays list found:', $('#nostr-relays-list').length);
        
        // Check if nostrForWPAdmin is available
        if (typeof nostrForWPAdmin === 'undefined') {
            console.error('Nostr plugin: JavaScript loaded but nostrForWPAdmin is undefined');
        } else {
            console.log('nostrForWPAdmin.ajaxUrl:', nostrForWPAdmin.ajaxUrl);
            console.log('nostrForWPAdmin.nonce:', nostrForWPAdmin.nonce);
        }
        
        // Update connection status on page load
        updateConnectionStatus();
        
        
        // NIP-07 Connection handlers
        $('#nostr-connect').on('click', function() {
            var button = $(this);
            
            if (!nostrForWP.isExtensionAvailable()) {
                alert(nostrForWPAdmin.strings.nip07NotAvailable);
                return;
            }
            
            button.prop('disabled', true).text(nostrForWPAdmin.strings.connecting);
            
            nostrForWP.connect().done(function(response) {
                if (response.success) {
                    showMessage(nostrForWPAdmin.strings.connectionSuccess, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showMessage(nostrForWPAdmin.strings.connectionFailed, 'error');
                }
            }).fail(function() {
                showMessage(nostrForWPAdmin.strings.connectionFailed, 'error');
            }).always(function() {
                button.prop('disabled', false).text(nostrForWPAdmin.strings.connect);
            });
        });
        
        $('#nostr-disconnect').on('click', function() {
            if (confirm(nostrForWPAdmin.strings.disconnectConfirm)) {
                nostrForWP.disconnect().done(function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        showMessage(nostrForWPAdmin.strings.disconnectFailed, 'error');
                    }
                });
            }
        });
        
        // Relay management
        $('#nostr-add-relay').on('click', function() {
            console.log('Add relay button clicked');
            var relayItem = addRelayItem();
            $('#nostr-relays-list').append(relayItem);
        });
        
        $(document).on('click', '.nostr-remove-relay', function() {
            $(this).closest('.nostr-relay-item').remove();
        });
        
        $(document).on('click', '.nostr-test-relay', function() {
            console.log('Test relay button clicked');
            var button = $(this);
            var relay = button.siblings('input').val();
            var statusSpan = button.siblings('.nostr-relay-status');
            
            console.log('Testing relay:', relay);
            
            if (!relay) {
                showMessage(nostrForWPAdmin.strings.relayUrlRequired, 'error');
                return;
            }
            
            button.prop('disabled', true).text(nostrForWPAdmin.strings.testing);
            statusSpan.html('<span class="dashicons dashicons-update" style="animation: spin 1s linear infinite;"></span>');
            
            testRelayConnection(relay, statusSpan);
            
            button.prop('disabled', false).text(nostrForWPAdmin.strings.test);
        });
        
        // NIP-65: remember the relay list event the page loaded, for the stale check
        var relayListLoadedId = null;
        function relayListStatus(text, ok) {
            $('#nostr-relay-list-status').text(text).css('color', ok === false ? '#b32d2e' : (ok ? 'green' : ''));
        }
        $('#nostr-relay-list-import').on('click', function() {
            var button = $(this).prop('disabled', true);
            relayListStatus('Looking up your relay list on Nostr...');
            nostrForWP.fetchRelayList().done(function(r) {
                if (!r.success) { relayListStatus(r.data || 'Lookup failed', false); return; }
                if (!r.data.found) { relayListStatus('No relay list (kind 10002) found for your key yet. Save with the checkbox ticked to publish one.', false); return; }
                relayListLoadedId = r.data.event_id;
                var list = r.data.write.slice();
                var readOnly = r.data.read || [];
                if (!confirm('Replace the relays below with the ' + list.length + ' write relays from your Nostr relay list?\n\n' + list.join('\n') + (readOnly.length ? '\n\nRead-only (kept on Nostr, not added here):\n' + readOnly.join('\n') : ''))) { relayListStatus(''); return; }
                $('#nostr-relays-list').empty();
                list.forEach(function(u) { $('#nostr-relays-list').append(addRelayItem(u)); });
                relayListStatus('Loaded ' + list.length + ' relays from your Nostr relay list (published ' + new Date(r.data.created_at * 1000).toLocaleString() + '). Click Save Relays to keep them.', true);
            }).fail(function() { relayListStatus('Lookup failed', false); }).always(function() { button.prop('disabled', false); });
        });

        function publishRelayListAfterSave() {
            relayListStatus('Publishing your relay list to Nostr...');
            var expected = relayListLoadedId === null ? '' : relayListLoadedId;
            return nostrForWP.publishRelayList(expected).done(function(r) {
                if (r.success) { relayListStatus(r.data.message, true); return; }
                var d = r.data || {};
                if (d.needs_browser_signer && d.unsigned) {
                    if (!nostrForWP.isExtensionAvailable()) { relayListStatus('Saved here, but no signer available to publish the relay list (no bunker, no browser extension).', false); return; }
                    return window.nostr.signEvent(d.unsigned).then(function(signed) {
                        return nostrForWP.broadcastRelayList(signed).done(function(b) {
                            relayListStatus(b.success ? b.data.message : ((b.data && b.data.message) || 'Broadcast failed'), !!b.success);
                        });
                    }).catch(function(e) { relayListStatus('Signing failed: ' + e, false); });
                }
                if (d.stale) {
                    var diff = d.diff || {added: [], removed: []};
                    relayListStatus(d.message + (diff.added.length ? ' Added elsewhere: ' + diff.added.join(', ') + '.' : '') + (diff.removed.length ? ' Removed elsewhere: ' + diff.removed.join(', ') + '.' : ''), false);
                    return;
                }
                relayListStatus(d.message || 'Publishing the relay list failed', false);
            }).fail(function() { relayListStatus('Publishing the relay list failed', false); });
        }

        $('#nostr-save-relays').on('click', function() {
            var button = $(this);
            var relays = [];
            
            console.log('Save relays clicked');
            
            $('#nostr-relays-list input[type="url"]').each(function() {
                var relay = $(this).val().trim();
                console.log('Found relay:', relay);
                if (relay) {
                    relays.push(relay);
                }
            });
            
            console.log('Relays to save:', relays);
            
            if (relays.length === 0) {
                showMessage(nostrForWPAdmin.strings.relayUrlRequired || 'Please add at least one relay URL', 'error');
                return;
            }
            
            button.prop('disabled', true).text(nostrForWPAdmin.strings.saving || 'Saving...');
            
            console.log('Sending AJAX request...');
            nostrForWP.saveRelays(relays).done(function(response) {
                console.log('Relay save response:', response);
                if (response.success) {
                    showMessage(nostrForWPAdmin.strings.relaysSaved || 'Relays saved successfully', 'success');
                    var after = function() { setTimeout(function() { location.reload(); }, 2500); };
                    if ($('#nostr-relay-list-publish').is(':checked')) {
                        // Stale check: if the page never loaded the Nostr list, fetch it now
                        // so we know what we would be overwriting.
                        var go = function() { $.when(publishRelayListAfterSave()).always(after); };
                        if (relayListLoadedId === null) {
                            nostrForWP.fetchRelayList().done(function(r) {
                                relayListLoadedId = (r.success && r.data.found) ? r.data.event_id : '';
                                go();
                            }).fail(go);
                        } else { go(); }
                    } else {
                        after();
                    }
                } else {
                    showMessage((nostrForWPAdmin.strings.relaysSaveFailed || 'Failed to save relays') + ': ' + (response.data || nostrForWPAdmin.strings.unknownError || 'Unknown error'), 'error');
                }
            }).fail(function(xhr, status, error) {
                console.log('Relay save error:', xhr, status, error);
                console.log('Response text:', xhr.responseText);
                showMessage('Save request failed: ' + error, 'error');
            }).always(function() {
                button.prop('disabled', false).text(nostrForWPAdmin.strings.saveRelays || 'Save Relays');
            });
        });
        
        // Force sync (inbound only - from last sync timestamp)
        $('#nostr-force-sync').on('click', function() {
            var button = $(this);
            button.prop('disabled', true).text('Syncing...');
            
            $.post(ajaxurl, {
                action: 'nostr_force_sync',
                nonce: nostrForWPAdmin.nonce
            }).done(function(response) {
                if (response.success) {
                    showMessage(response.data || 'Sync completed', 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showMessage(response.data || 'Sync failed', 'error');
                }
            }).fail(function() {
                showMessage('Sync request failed', 'error');
            }).always(function() {
                button.prop('disabled', false).text('Sync Latest Notes');
            });
        });
        
        // Force full resync (inbound only - all events)
        $('#nostr-force-full-resync').on('click', function() {
            if (!confirm('Are you sure you want to sync all notes? This will re-process all events from Nostr, which may take a while.')) {
                return;
            }
            
            var button = $(this);
            button.prop('disabled', true).text('Syncing All Notes...');
            
            $.post(ajaxurl, {
                action: 'nostr_force_sync',
                full_resync: 'true',
                nonce: nostrForWPAdmin.nonce
            }).done(function(response) {
                if (response.success) {
                    showMessage(response.data || 'Sync all notes completed', 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showMessage(response.data || 'Sync all notes failed', 'error');
                }
            }).fail(function() {
                showMessage('Sync all notes request failed', 'error');
            }).always(function() {
                button.prop('disabled', false).text('Sync All Notes');
            });
        });
        
        // Test all relays
        $('#nostr-test-all-relays').on('click', function() {
            var button = $(this);
            button.prop('disabled', true).text(nostrForWPAdmin.strings.testingAll);
            
            // Test each relay
            var testPromises = [];
            $('#nostr-relays-list input[type="url"]').each(function() {
                var relay = $(this).val().trim();
                if (relay) {
                    var statusSpan = $(this).siblings('.nostr-relay-status');
                    testPromises.push(testRelayConnection(relay, statusSpan));
                }
            });
            
            Promise.all(testPromises).finally(function() {
                button.prop('disabled', false).text(nostrForWPAdmin.strings.testAllRelays);
            });
        });
        
        // Manual sync for posts
        $('#nostr-manual-sync').on('click', function() {
            var button = $(this);
            var postId = button.data('post-id');
            
            if (!postId) {
                showMessage(nostrForWPAdmin.strings.invalidPostId, 'error');
                return;
            }
            
            button.prop('disabled', true).text(nostrForWPAdmin.strings.syncing);
            
            nostrForWP.manualSync(postId).done(function(response) {
                if (response.success) {
                    showMessage(nostrForWPAdmin.strings.syncSuccess, 'success');
                    // Refresh the page to show updated status
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showMessage(nostrForWPAdmin.strings.syncError + ': ' + (response.data || nostrForWPAdmin.strings.unknownError), 'error');
                }
            }).fail(function() {
                showMessage(nostrForWPAdmin.strings.syncRequestFailed, 'error');
            }).always(function() {
                button.prop('disabled', false).text(nostrForWPAdmin.strings.syncNow);
            });
        });
        
        // Refresh status
        $('#nostr-refresh-status').on('click', function() {
            var button = $(this);
            var postId = button.data('post-id');
            
            if (!postId) {
                showMessage(nostrForWPAdmin.strings.invalidPostId, 'error');
                return;
            }
            
            button.prop('disabled', true).text(nostrForWPAdmin.strings.refreshing);
            
            nostrForWP.getSyncStatus(postId).done(function(response) {
                if (response.success) {
                    var data = response.data;
                    
                    // Update sync status display
                    var statusSpan = $('.nostr-sync-status');
                    if (data.sync_status) {
                        var statusText = '';
                        var statusIcon = '';
                        
                        switch (data.sync_status) {
                            case 'synced':
                                statusIcon = '<span class="dashicons dashicons-yes-alt" style="color: green;"></span>';
                                statusText = nostrForWPAdmin.strings.synced;
                                break;
                            case 'failed':
                                statusIcon = '<span class="dashicons dashicons-dismiss" style="color: red;"></span>';
                                statusText = nostrForWPAdmin.strings.failed;
                                break;
                            case 'error':
                                statusIcon = '<span class="dashicons dashicons-warning" style="color: orange;"></span>';
                                statusText = nostrForWPAdmin.strings.error;
                                break;
                            default:
                                statusIcon = '<span class="dashicons dashicons-clock" style="color: gray;"></span>';
                                statusText = nostrForWPAdmin.strings.pending;
                        }
                        
                        statusSpan.html(statusIcon + ' ' + statusText);
                    }
                    
                    showMessage(nostrForWPAdmin.strings.statusRefreshed, 'info');
                } else {
                    showMessage(nostrForWPAdmin.strings.statusRefreshFailed, 'error');
                }
            }).fail(function() {
                showMessage(nostrForWPAdmin.strings.statusRefreshRequestFailed, 'error');
            }).always(function() {
                button.prop('disabled', false).text(nostrForWPAdmin.strings.refreshStatus);
            });
        });
    });
    
    // Add CSS for animations
    $('<style>')
        .prop('type', 'text/css')
        .html('@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }')
        .appendTo('head');
    
    // Shared WebSocket publishing function
    window.nostrForWP.publishToRelays = function(signedEvent, postId, options) {
        options = options || {};
        const onSuccess = options.onSuccess || function() {};
        const onError = options.onError || function() {};
        const onComplete = options.onComplete || function() {};
        
        // Get relays from WordPress settings
        const relays = nostrForWPAdmin.relays || [];
        
        if (relays.length === 0) {
            onError('No relays configured');
            return;
        }
        
        let successCount = 0;
        let completedCount = 0;
        
        relays.forEach(relay => {
            try {
                const ws = new WebSocket(relay);
                
                ws.onopen = function() {
                    console.log('Connected to ' + relay);
                    // Send EVENT message
                    ws.send(JSON.stringify(['EVENT', signedEvent]));
                };
                
                ws.onmessage = function(event) {
                    try {
                        const data = JSON.parse(event.data);
                        if (data[0] === 'OK' && data[2] === true) {
                            successCount++;
                            console.log('Published to ' + relay + ' successfully!');
                        } else if (data[0] === 'OK' && data[2] === false) {
                            console.log('Relay ' + relay + ' rejected event: ' + data[3]);
                        }
                    } catch (e) {
                        console.log('Received non-JSON message from ' + relay + ': ' + event.data);
                    }
                    
                    completedCount++;
                    ws.close();
                    
                    // Check if all relays have responded
                    if (completedCount === relays.length) {
                        if (successCount > 0) {
                            onSuccess(successCount, relays.length);
                        } else {
                            onError('Failed to publish to any relays');
                        }
                        onComplete();
                    }
                };
                
                ws.onerror = function(error) {
                    console.log('WebSocket error for ' + relay + ':', error);
                    completedCount++;
                    if (completedCount === relays.length) {
                        onError('WebSocket connection failed for all relays');
                        onComplete();
                    }
                };
                
                ws.onclose = function() {
                    console.log('Disconnected from ' + relay);
                };
                
                // Timeout after 10 seconds
                setTimeout(() => {
                    if (ws.readyState === WebSocket.OPEN) {
                        ws.close();
                    }
                }, 10000);
                
            } catch (error) {
                console.log('Failed to connect to ' + relay + ':', error);
                completedCount++;
                if (completedCount === relays.length) {
                    onError('Failed to connect to any relays');
                    onComplete();
                }
            }
        });
    };
        
})(jQuery);
