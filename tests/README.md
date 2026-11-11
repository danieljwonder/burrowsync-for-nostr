# Tests

Standalone PHP test scripts — no PHPUnit and no WordPress install needed.
Each script defines the WordPress function stubs it requires (see
`wp-stubs.php`) and exits non-zero on failure. Not shipped with the plugin
(excluded via the `package.json` `files` allowlist and `.distignore`).

Run `composer install` in the repo root first (the crypto tests need
`simplito/elliptic-php`).

## Offline (no network)

| Script | Covers |
|---|---|
| `test-crypto.php` | NIP-44 v2 against official vectors (incl. invalid cases), BIP-340 against official verification vectors + sign roundtrips, NIP-04, npub encoding |
| `test-settings.php` | bunker URI parsing, secret encryption at rest, keypair persistence and reset |
| `test-inbound-guard.php` | regression: posts created by inbound sync are **never** queued for outbound NIP-46 publishing (reproduces `wp_insert_post()` hook ordering; see ADR-6 in `docs/DECISIONS.md`) |
| `test-publish-record.php` | the signed event is kept after a successful publish (byte-identical through `update_post_meta` unslashing), per-relay states (accepted / rejected / unreachable), attempt log and its cap, edit re-sign keeps the old id in the log, legacy retry key migration. Real publisher, local signing key, scripted relay replies (`offline-publish-stubs.php`) |

```bash
php tests/test-crypto.php
php tests/test-settings.php
php tests/test-inbound-guard.php
php tests/test-publish-record.php
```

Without native PHP: `/opt/data/tools/php-wasm/run-plugin-tests.sh <plugin-dir>` runs every
`tests/test-*.php` under php-wasm. It prints `[exit] file: last line`; php-wasm can exit 0 on a
fatal, so read the last line. `test-publisher.php` and `test-client-e2e.php` need a running bunker
and cannot pass there.

## Network (live relay)

| Script | Covers |
|---|---|
| `fetch-event.php` | validates `event_id()` and `schnorr_verify()` against real events fetched from a public relay |
| `mock-bunker.php` | a minimal NIP-46 signer used as the counterparty for the e2e tests; writes its `bunker://` URI to `bunker-out.txt` |
| `test-client-e2e.php` | full NIP-46 client round trip (connect, get_public_key, sign_event, ping) against a running bunker |
| `test-publisher.php` | publisher behaviour: queueing, retry backoff schedule, exactly-once signing, idempotency — against the mock bunker |

```bash
php tests/fetch-event.php

# e2e: start the mock bunker first (or use a real `nak bunker` URI)
php tests/mock-bunker.php wss://nos.lol 120 > tests/bunker-out.txt 2> tests/bunker-err.txt &
sleep 3
php tests/test-client-e2e.php "$(grep -o 'bunker://[^ ]*' tests/bunker-out.txt | head -1)"
php tests/test-publisher.php
```

Public relays rate-limit: if e2e calls time out, wait a minute or switch
relay. The e2e scripts space their RPC calls deliberately.
