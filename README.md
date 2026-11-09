# BurrowSync for Nostr

> **Beta.** Pre-1.0. It runs in production, but test it on a staging site before relying on it.

Part of [BurrowSync](https://burrowsync.com): WordPress plugins that sync your site with X, Farcaster and Nostr. Previously released as **Nostr for WP**. Switching over: activate BurrowSync for Nostr, then deactivate Nostr for WP. Settings carry over.

Two-way synchronization between WordPress content and Nostr protocol. Supports standard kind 1 notes and kind 30023 long-form content with NIP-07 browser extension or NIP-46 remote signer (bunker) signing.

## Features

- 🔄 Two-way sync between WordPress and Nostr
- 💬 Supports kind 1 (short notes) as WordPress Custom Post Type
- 📝 Supports kind 30023 (long-form articles) for standard WordPress posts
- 🔁 Automatic background sync of inbound notes
- 🔐 NIP-07 browser extension integration for outbound notes
- 🏰 NIP-46 remote signer (bunker) support — server-side signing for scheduled posts
- 🎨 Gutenberg block for displaying Nostr notes archive
- ⚡ WebSocket relay configuration
- 🪪 NIP-05 domain identity — generate `.well-known/nostr.json` from the admin

## Quick Start

### For Users

1. Install and activate the plugin in WordPress
2. Go to Settings > Nostr to configure and connect your Nostr key.
3. Either use the standard archives, or add the Nostr for WP block to your posts/pages

## Remote signer (NIP-46)

By default outbound events are signed with a NIP-07 browser extension, which means a
human with the extension must be present to publish. Selecting **Remote signer /
bunker (NIP-46)** under Settings > Nostr instead lets the *server* sign outbound
kind 1 and kind 30023 events by talking to a remote signer ("bunker") you run
elsewhere — [nak bunker](https://github.com/fiatjaf/nak), [nsec.app](https://nsec.app),
Amber, or any other NIP-46 signer. This enables scheduled posts and programmatic
publishing, and your nsec never touches the WordPress server: the plugin only holds
a disposable *client* keypair it uses to talk to the bunker, and the bunker does the
actual signing with your key.

### Setup with nak bunker (worked example)

1. On your own machine or home server, start a bunker:

```bash
nak bunker --persist wss://relay.damus.io wss://nos.lol
```

   `nak` prints a connection URI like
   `bunker://<signer-pubkey>?relay=wss%3A%2F%2Frelay.damus.io&secret=<token>`.
   The bunker only makes *outbound* websocket connections to those relays, so it
   works from behind NAT with no open ports.

2. In WordPress, go to **Settings > Nostr**, select **Remote signer / bunker
   (NIP-46)**, paste the `bunker://` URI, click **Save**, then **Connect and test**.
   The status panel shows the user pubkey (hex and npub) the bunker signs with —
   verify it is yours.

3. Publish a post or note as usual (including scheduled posts). The server builds
   the event, sends it to the bunker for signing over the relay, and publishes the
   signed event to your configured relays. No browser extension needed.

### Locked bunker and retries

If the bunker is offline or locked (for example `nak bunker --persist` asks for its
password again after a reboot), the WordPress publish still succeeds; the Nostr
publication is queued and retried via WP-Cron with backoff (5 min, 15 min, 60 min,
then hourly for up to 24 hours). The post list's **Nostr** column and the
**Nostr Remote Signer** box on the edit screen show queued/failed state and offer a
manual **Retry now**. Each post publishes exactly once — the signed event id is
recorded and re-used, never re-signed.

### Security notes

- Your nsec is never asked for, accepted, or stored. Only the plugin's own NIP-46
  client key and the bunker URI (whose `secret` authorises signing) are stored, and
  both are encrypted at rest with a key derived from your `wp-config.php` salts.
- The client keypair persists across sessions so the bunker's authorisation of this
  site sticks. "Reset client key" discards it — the bunker will need to re-authorise.
- Host requirements: the PHP `sodium` extension (bundled with PHP 7.2+) and the
  ability to open **outbound** `wss://` connections. No inbound ports and no
  long-running processes are needed, so it works on typical managed hosting.

### For Developers

```bash
# Clone and setup
git clone https://github.com/danieljwonder/nostr-for-wp.git
cd nostr-for-wp
npm install
npm run build
composer install   # PHP dependencies (NIP-46 secp256k1 support)
```

See **[BUILD.md](BUILD.md)** for complete build documentation.

## Requirements

- WordPress 6.0+
- PHP 7.4+ (with the `sodium` extension for NIP-46 remote signer support; bundled with PHP 7.2+)
- Outbound `wss://` (TCP 443) connectivity for relay access and NIP-46 signing
- Node.js 18+ (for development only)

## Project Structure

The repo root is the plugin root. The distributable zip is built from the
`files` allowlist in `package.json` (`npm run plugin-zip`), so development
directories never ship.

```
nostr-for-wp.php     # Plugin entry point
includes/            # PHP classes (sync, NIP-46 client/crypto, relays)   [shipped]
admin/               # Admin UI (settings, meta boxes)                    [shipped]
public/              # Frontend assets                                    [shipped]
src/blocks/          # Block source files (edit these)
build/               # Compiled blocks (auto-generated)                   [shipped]
block-templates/     # Block templates                                    [shipped]
vendor/              # Composer deps (gitignored; run composer install)   [shipped]
tests/               # Standalone test scripts + vectors (see tests/README.md)
docs/                # Architecture decision records (DECISIONS.md)
```

## Development

The plugin uses `@wordpress/scripts` for modern block development:

- Modern ES6+ JavaScript with JSX
- SCSS preprocessing
- Development watch mode
- Automatic optimization

## Documentation

- **[BUILD.md](BUILD.md)** - Complete build system guide
- **[docs/DECISIONS.md](docs/DECISIONS.md)** - Architecture decision records (crypto trust model, loop guards, queue design)
- **[tests/README.md](tests/README.md)** - How to run the test suite
- **[readme.txt](readme.txt)** - WordPress.org plugin readme

## License

GPL v2 or later

## Credits

- Author: Daniel Wonder
- Built with [@wordpress/scripts](https://www.npmjs.com/package/@wordpress/scripts)
