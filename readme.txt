=== BurrowSync for Nostr ===
Contributors: danielwonder
Tags: nostr, sync, social, notes, burrowsync
Requires at least: 5.9
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 0.9.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Two-way synchronization between WordPress content and Nostr protocol. Supports kind 1 notes and kind 30023 long-form content with NIP-07 browser extension or NIP-46 remote signer (bunker) signing.

== Description ==

**Beta.** BurrowSync for Nostr is pre-1.0. It runs in production, but test it on a staging site before relying on it.

BurrowSync for Nostr syncs your WordPress site with Nostr, both ways. Publish on your site and it goes out to Nostr. Publish from any Nostr client and it lands on your site. It is part of the [BurrowSync](https://burrowsync.com) family, alongside BurrowSync for X and BurrowSync for Farcaster. Each plugin works on its own and they share the same `note` post type.

Previously released as Nostr for WP. See the Upgrade Notice for switching over.

**Key Features:**

* **Two-Way Sync**: Automatically sync content between WordPress and Nostr
* **NIP-07 Integration**: Use your browser extension to sign events securely
* **NIP-46 Remote Signer (Bunker)**: Let the server sign outbound events via a remote signer you control — enables scheduled posts with no browser present, while your private key never touches the server
* **Custom Post Types**: Dedicated "Notes" post type for kind 1 events
* **Long-Form Content**: Sync standard WordPress posts as kind 30023 events
* **NIP-05 Identity**: Create and manage a `.well-known/nostr.json` file for domain-based identity verification
* **Relay Management**: Configure multiple Nostr relays for redundancy
* **Conflict Resolution**: Smart timestamp-based conflict resolution
* **Opt-in Sync**: Choose which content to sync with Nostr
* **Real-time Updates**: Automatic inbound sync on content changes
* **Custom Frontend Display**: Theme-agnostic display for notes with hidden titles and clean styling
* **Block Templates**: Automatic block templates for block themes
* **Gutenberg Blocks**: Display notes anywhere using custom blocks
* **Shortcodes**: Embed notes in posts, pages, or widgets using shortcodes

**Content Types:**

* **Kind 1 (Notes)**: Short text notes using the custom "Notes" post type
* **Kind 30023 (Long-form)**: Standard WordPress posts with full content, metadata, and tags

**Roadmap:**

* Follow Button to Grow Your Nostr Audience on Your Site
* Zap support for bitcoin donations over Lightning
* NIP-01 syncing Nostr Profile Data with WordPress profiles
* Deeper integration with NIP 65 for relay management
* NIP-51 Support for Link List Pages
* Integrated Nostr Profile Statistics and Analytics


**Requirements:**

* WordPress 5.0 or higher
* PHP 7.4 or higher
* Nostr relay access (outbound wss:// / TCP 443 connectivity from the server)
* For NIP-07 signing: a compatible browser extension (e.g., Alby, nos2x, etc.)
* For NIP-46 remote signer (bunker) signing: the PHP sodium extension (bundled with PHP 7.2+, used to encrypt connection secrets at rest) and a NIP-46 signer you control (e.g. nak bunker, nsec.app, Amber)

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/nostr-for-wp` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Settings > Nostr to configure your Nostr connection
4. Install a NIP-07 compatible browser extension
5. Connect your Nostr account in the settings page
6. Configure your preferred relays
7. Enable sync for individual posts and notes

== Remote signer (NIP-46) ==

By default, outbound events are signed by a NIP-07 browser extension, so someone with the extension must be present to publish. Selecting **Remote signer / bunker (NIP-46)** under Settings > Nostr lets the server sign outbound kind 1 and kind 30023 events by talking to a remote signer ("bunker") over Nostr relays. This enables scheduled posts and publishing without a browser, and your nsec never touches the WordPress server — the plugin only keeps its own client key for talking to the bunker.

**Setup with nak bunker (example):**

1. On a machine you control, run: `nak bunker --persist wss://relay.damus.io wss://nos.lol`
2. Copy the printed `bunker://...` URI (it contains the signer pubkey, relays, and a one-time secret).
3. In Settings > Nostr, select "Remote signer / bunker (NIP-46)", paste the URI, click Save, then "Connect and test". The panel shows the user pubkey (hex and npub) the bunker signs with.
4. Publish or schedule posts normally — the server signs them via the bunker.

All connections are outbound websockets: neither the bunker nor the WordPress host needs open inbound ports. The bunker URI (its secret authorises signing) and the plugin's client key are encrypted at rest using a key derived from your wp-config.php salts.

**Locked or offline bunker:** publishing in WordPress never fails because of Nostr. If the bunker is unreachable or locked (e.g. `nak bunker --persist` requires a password unlock after a reboot), the Nostr publication is queued and retried via WP-Cron with backoff (5 min, 15 min, 60 min, then hourly for up to 24 hours). Queued/failed state is shown per post in the post list's "Nostr" column and in the "Nostr Remote Signer" box on the edit screen, with a manual "Retry now" action. Each post is published exactly once: the signed event is stored and reused on retry, never re-signed.

== Usage ==

**Displaying Notes:**

* **Shortcodes:**
  * `[nostr_note id="123"]` - Display a single note by WordPress ID
  * `[nostr_notes limit="10" orderby="date" order="DESC"]` - Display multiple notes with customizable options

* **Gutenberg Blocks:**
  * **Nostr Notes Block** - Add from the block inserter (Widgets category). Configure number of notes, sort order, and display options.

* **Automatic Display:**
  * Notes automatically use custom templates for clean card-based styling
  * Works with both classic and block themes
  * Block templates are automatically applied for block themes

== Frequently Asked Questions ==

= Do I need a Nostr account? =

Yes, you need a Nostr account. For signing you can use either a NIP-07 compatible browser extension (Alby, nos2x, ...) or a NIP-46 remote signer / bunker (nak bunker, nsec.app, Amber, ...).

= What content gets synced? =

By default, all new notes posted to Nostr sync with WordPress. For any content created within the WordPress dashboard, you can use the browser signer to sync with your relays. You can disable sync for individual posts using the checkbox in the post editor.

= How does conflict resolution work? =

When content is edited in both WordPress and Nostr, the version with the most recent timestamp wins. This ensures the latest changes are always preserved.

= Can I use multiple relays? =

Yes, you can configure multiple Nostr relays for redundancy. The plugin will attempt to publish to all configured relays.

= Is my private key stored on the server? =

No, your private key is never stored on the server in either mode. With NIP-07, signing happens in your browser extension. With NIP-46, signing happens in the remote bunker you control; the server only stores its own client key and the bunker connection URI, both encrypted at rest.

= Can scheduled posts publish to Nostr automatically? =

Yes — with the NIP-46 remote signer configured, scheduled posts are signed and published to Nostr at their scheduled time via WP-Cron, with no browser session open. In NIP-07 mode this is not possible because the browser extension must sign.

= What happens if a relay is down? =

The plugin will attempt to publish to all configured relays. If some relays are down, the content will still be published to available relays.

= How are notes displayed on the frontend? =

Notes automatically use custom display templates that hide titles and show only the content with clean metadata. The plugin provides block templates for block themes and works seamlessly with modern or classic themes. You can also use shortcodes or Gutenberg blocks to display notes anywhere on your site.

= Can I customize how notes look? =

The plugin includes CSS styling that provides a clean, card-based design for notes. This styling is theme-agnostic and works across different WordPress themes. For advanced customization, you can override the CSS or modify the templates in the plugin's `templates/` and `block-templates/` directories.

== Screenshots ==

1. Nostr settings page in WordPress Dashboard
2. Post editor with Nostr sync options

== Changelog ==

= 0.9.1 =
* First release under the BurrowSync name
* Fixed: a relay replying OK=false (for example a paid relay refusing the event) was counted as a successful publish
* Fixed: stored signed events could be corrupted on save and silently re-signed on retry
* The exact signed event is now kept for every published post and note, with each relay's result and a short attempt log; download it from the post's Nostr box
* Every relay in your list is retried in the background until it accepts the event or refuses it outright (5 min, 15 min, 1 h, 6 h, then daily for 7 days); relays added later receive posts from the last 30 days
* New Relay health card on the settings page
* Browser (NIP-07) publishing now hands the signed event to WordPress so it is kept too

= 0.9.0 =
* Renamed from Nostr for WP to BurrowSync for Nostr and joined the BurrowSync family
* Versioning restarts below 1.0 while the family is in public beta. Features match Nostr for WP 1.4.0
* Stands down with an admin notice if Nostr for WP is still active, so the two never run at once
* Settings, notes, sync history and NIP-46 bunker connections carry over unchanged
* Release zip now bundles the Composer dependencies NIP-46 needs
* Fixed a fatal error on notes containing a plain-text embeddable URL (e.g. an X post link)
* New: Backfill to Nostr (bunker mode). Publishes posts and notes that never reached Nostr, keeping their original dates, one every 30 seconds

= 1.4.0 =
* Added NIP-46 remote signer (bunker) support as a second signing method — the server can now sign outbound kind 1 and kind 30023 events via a bunker (nak bunker, nsec.app, Amber, ...)
* Scheduled posts now publish to Nostr automatically in bunker mode, no browser required
* Publish queue with WP-Cron retries and backoff (5/15/60 min, then hourly up to 24h) for when the bunker is offline or locked; per-post status column and "Retry now" action
* NIP-44 payload encryption with NIP-04 fallback for older signers, detected automatically
* Client key and bunker URI are encrypted at rest using a key derived from WordPress salts
* New host requirements for bunker mode: PHP sodium extension and outbound wss:// connectivity (see Requirements)
* The NIP-07 browser extension flow is unchanged and remains the default

= 1.3.0 =
* Added NIP-05 identity support: create and manage `.well-known/nostr.json` directly from the settings page
* NIP-05 admin UI includes dynamic name-to-pubkey mapping, writable status indicator, and a JSON file preview
* "Use Connected Site Key" shortcut pre-fills the site's Nostr public key with `_` as the root domain identifier
* Configured relays are automatically included in the `relays` field of `nostr.json`

= 1.2.0 =
* Added custom frontend display for notes with theme-agnostic styling
* Implemented block templates for block themes (archive-note.html)
* Added Gutenberg block: Nostr Notes block
* Added shortcodes: [nostr_note] and [nostr_notes] for arbitrary note display
* Enhanced note display to hide duplicated titles and show clean metadata
* Improved CSS targeting for better theme compatibility
* Notes now display with full event IDs on separate lines

= 1.1.0 =
* Minor bug fixes. 

= 1.0.0 =
* Initial release
* Two-way sync between WordPress and Nostr
* NIP-07 browser extension integration
* Custom "Notes" post type for kind 1 events
* Long-form content sync (kind 30023)
* Relay management and configuration
* Conflict resolution with timestamp comparison
* Opt-in sync for individual posts
* Real-time sync on content changes
* Background polling for Nostr updates
* Admin interface for settings and management

== Upgrade Notice ==

= 0.9.1 =
Safe update from 0.9.0 and from Nostr for WP 1.4.0. Posts published before this version have no stored signed event, so they cannot be rebroadcast by the new retry sweep; use Backfill to Nostr for any that never reached a relay.

= 0.9.0 =
Switching from Nostr for WP: install and activate BurrowSync for Nostr, then deactivate Nostr for WP. Your settings carry over. Deleting Nostr for WP afterwards is safe; it does not remove any data.

= 1.4.0 =
Adds NIP-46 remote signer (bunker) support for server-side signing and scheduled posts. Fully optional and off by default — existing NIP-07 setups are unaffected.

= 1.3.0 =
Adds NIP-05 domain identity support. Go to Settings > Nostr and use the new NIP-05 Identity section to generate your `.well-known/nostr.json` file.

= 1.2.0 =
This version adds frontend display customization, Gutenberg blocks, and shortcodes for displaying notes. Notes will now automatically use the new clean display format. No action required, but you can now use shortcodes and blocks to display notes anywhere on your site.

= 1.1.0 =
Minor bug fixes.

= 1.0.0 =
Initial release of Nostr for WordPress. Install and configure your Nostr connection to start syncing content.

== Technical Details ==

**Architecture:**

* **Nostr Client**: Handles WebSocket connections and event publishing
* **Sync Manager**: Orchestrates bidirectional synchronization
* **Content Mapper**: Transforms content between WordPress and Nostr formats
* **NIP-07 Handler**: Integrates with browser extensions for signing
* **NIP-46 Client**: Server-side remote signer (bunker) client with queued publishing and retries
* **Cron Handler**: Manages background polling for updates
* **Frontend Display**: Customizes note display with theme-agnostic templates and styling
* **Shortcode Handler**: Provides shortcodes for arbitrary note display
* **Block Registration**: Registers Gutenberg blocks for note display

**Security:**

* Your Nostr private key is never stored server-side in either signing mode
* NIP-07: all signing done client-side in the browser extension
* NIP-46: signing done by the remote bunker; the plugin's client key and the bunker URI are encrypted at rest with a key derived from wp-config.php salts
* Secure AJAX endpoints with nonce verification
* User permission checks for all operations

**Performance:**

* Efficient background polling with configurable intervals
* Minimal database queries
* Caching of connection status
* Optimized content transformations

**Compatibility:**

* WordPress 5.0+
* PHP 7.4+
* Modern browsers with NIP-07 support
* Standard Nostr relays

== Support ==

For support, feature requests, or bug reports, please visit the [GitHub repository](https://github.com/danieljwonder/nostr-for-wp).

== Privacy Policy ==

This plugin does not collect or store personal data. All Nostr interactions are handled through your configured relays and browser extension. 

== Credits ==

Built for the Nostr community to bridge the gap between traditional web publishing and decentralized social protocols.
