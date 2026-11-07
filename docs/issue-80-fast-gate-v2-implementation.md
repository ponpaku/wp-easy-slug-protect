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
- Protected-path password changes/removals: atomic multi-path pre-save
  block, then publish updated versions on successful WordPress option update.
- Standalone authorizer and PHP streaming entrypoint without WP/MySQL,
  with uploads root checks, strict URL decoding, no descendant symlinks,
  GET/HEAD and single-range support, and no-store responses.
- Per-attachment SQLite deny fences are written before indexed WordPress
  metadata mutation, including newly claimed paths; the index's successful
  publication callback refreshes only the affected file paths. Fences are
  epoch-tagged to stop stale workers from re-authorizing newer changes.
  The shared media-index Cron retries bounded unresolved owner fences.
- Catalog `ready` is separate from `serving`; builds never activate
  standalone delivery, and disabling the gate first stops serving.
- CLI: `wp esp gate-ledger provision`, `status`, `build`, `gc`.
  Initial `build` requires a ready strict media index and cross-checks
  each indexed path via live `ESP_Media_Index::resolve()`. No activation.
- Token, revocation persistence/GC, path safety and fail-closed unit tests.

## Security and operations

- No path-setting UI or `wp-config.php` constant. Provisioning creates
  `wp-content/esp-fast-gate-v2/` in the standard WordPress plugin layout.
  `keys.php` contains PHP `return`-only key data; standalone and WordPress
  use it via `require`. SQLite `gate-ledger.sqlite`, `-wal` and `-shm`
  are separate files. The dedicated `.htaccess` denies direct HTTP access
  for Apache/LiteSpeed; normal direct PHP execution prints no key values.
  Disabling PHP execution or `.htaccess` handling is an unsupported or
  insecure server configuration and must be caught by activation HTTP checks.
- `provision` creates keys once; never overwrites them.
- SQLite3 is optional for the WordPress plugin. No SQLite3 extension, no
  provisioned directory, invalid ledger/keys/state: no gate ticket is issued
  and the standalone entrypoint fails closed.
- Existing media delivery and server rewrites remain unchanged. Fast Gate v2
  initially targets **Apache and LiteSpeed only**. Nginx retains its
  existing WordPress/PHP media-protection behavior; no Fast Gate route.
  The `esp_fast_gate_v2_active` option **must remain OFF**, and SQLite
  `serving=0`, until a follow-up activation implementation passes HTTP
  safety checks. No CLI or UI enable command is provided yet.
- No external backup rollback detection. Operator restoration of obsolete
  ledger+keys requires key rotation and gate reinitialization (Issue #80).

## Outstanding blockers — DO NOT ENABLE

1. **Incremental media correctness** (R01): deny fences and owner refresh
   are implemented, but WordPress/MySQL fault-injection and concurrent owner,
   collision, deletion, post-type and direct-update E2E tests are still required.
   Current code does not guarantee every external mutation path is fenced.
2. **HTTP routing**: implement and verify only Apache/LiteSpeed Fast Gate
   routing and direct URL bypasses, public media availability, WordPress
   capability exceptions, cookie scope, direct image access, and safe PHP
   fallback. Nginx Fast Gate is explicitly deferred.
3. **Real auth lifecycle tests**: WordPress/MySQL concurrent logout,
   session+Remember coexistence, failed DB deletion, password update failure,
   UI redirect and refresh. No HTTP integration proof has yet been obtained.
4. **Catalog concurrency**: initial build cross-checks live DB but is not
   atomic across MySQL+SQLite. It now cannot activate serving by itself.
   Before activation, reconcile any changes occurring during build and
   verify the final catalog; manual CLI build is operator-driven.
5. **Crash/fault injection**: SQLite WAL/fsync, key rotation on internal
   ledger reset, restart, file permissions, DB loss.
6. **WP-Cron/index coupling**: bounded fence retries and revocation GC are
   connected to the existing index Cron; add drift audits, orphan recovery
   validation and administrator failure diagnostics.
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
