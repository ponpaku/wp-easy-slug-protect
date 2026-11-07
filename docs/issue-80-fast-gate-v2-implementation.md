# Issue #80 — Fast Gate v2 implementation review (first safety slice)

**Status: review-only foundation; NOT production-ready and NOT activated.**
This document records actual code behavior, not a claim that Issue #80 is complete.

## Implemented

- WP-independent HMAC-SHA-256 gate ticket with strict scope and a 24-hour maximum age.
- Gate ticket is *bound to the existing WP session/Remember token* via an
  opaque HMAC session reference. The token, password, and MySQL token hash
  are never copied into the local ledger.
- Site ID, protected path ID, auth kind, password version, issued/expiry times
  and revocation reference are signed. Failure of any check denies access.
- SQLite ledger with explicit `public` / `protected` / `deny` media policy,
  protected-path password versions and blocked states, and revocation entries.
  Missing media rows are **not** public.
- Two-step logout: persist all present session/Remember revocations in
  SQLite before MySQL deletions; retain unconfirmed deletions; never issue a
  ticket for a revoked reference; only garbage collect confirmed, expired
  revocations.
- Protected-path password changes/removals: pre-save block, then publish
  updated versions on successful WordPress option update.
- Standalone authorizer and PHP streaming entrypoint without WP/MySQL,
  with uploads root checks, strict URL decoding, no descendant symlinks,
  GET/HEAD and single-range support, and no-store responses.
- CLI: `wp esp gate-ledger provision`, `status`, `build`, `gc`.
  Initial `build` requires a ready strict media index and cross-checks each
  indexed path via live `ESP_Media_Index::resolve()`. No activation.
- Token, revocation persistence/GC, path safety and fail-closed unit tests.

## Security and operations

- `ESP_GATE_PRIVATE_DIR` must be configured in `wp-config.php` as an
  **existing private directory outside ABSPATH and WP_CONTENT_DIR**. The
  standalone gate receives it via trusted web-server configuration (the
  `ESP_GATE_PRIVATE_DIR` environment variable). Both use the generated
  `gate-keys.json` and `gate-ledger.sqlite`.
- `provision` creates keys once; never overwrites them.
- SQLite3 is optional for the WordPress plugin. No SQLite3 extension, no
  private directory, invalid ledger/keys/state: no gate ticket is issued
  and the standalone entrypoint fails closed.
- Existing WP media delivery / Apache .htaccess / Nginx rules stay unchanged.
  The `esp_fast_gate_v2_active` option **must remain OFF** until a
  follow-up implementation completes activation requirements.
- No external backup rollback detection. Operator restoration of obsolete
  ledger+keys requires key rotation and gate reinitialization (Issue #80).

## Outstanding blockers — DO NOT ENABLE

1. **Incremental media changes** (R01): maintain exact path ownership,
   deletions, ambiguous owners and changing authorization as media index
   advances. An initial catalog build is insufficient for live service.
2. **HTTP routing**: verify Apache/LiteSpeed/Nginx rewrites and direct URL
   bypasses, public media availability, WordPress capability exceptions,
   cookie scope, direct image access and safe PHP fallback.
3. **Real auth lifecycle tests**: WordPress/MySQL concurrent logout,
   session+Remember coexistence, failed DB deletion, password update failure,
   UI redirect and refresh. No HTTP integration proof has yet been obtained.
4. **Catalog concurrency**: initial build cross-checks live DB but is not
   atomic across MySQL+SQLite. Changes during build need replay/reverification.
5. **Crash/fault injection**: SQLite WAL/fsync, key rotation on internal
   ledger reset, restart, file permissions, DB loss.
6. **WP-Cron/index coupling**: incremental reconciliation, revocation GC
   and administrator failure diagnostics.
7. **Performance**: end-to-end HTTP p95, CPU, memory, concurrency and
   demonstrably zero MySQL queries per media request.
8. **Issues #67/#79**: existing media index correctness issues are unresolved.

## Review request

Review protocol correctness, cookie forgery/expiry, key isolation, accidental
activation, logout partial failures, SQLite read/write behavior and gate URL
checks. This PR must **not** be merged as a production gate without resolving
the above blockers.

References: [Issue #80](https://github.com/ponpaku/wp-easy-slug-protect/issues/80),
[Issue #37](https://github.com/ponpaku/wp-easy-slug-protect/issues/37).
