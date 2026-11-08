# Issue #80 / PR #81 — v0.7.41 → Fast Gate v2 validation record

**Status: CI PASS, not cleared for production activation.**  
**Verified commit:** [e3672987d7e72b51dc9aed6adf4a369ed6ff5cf7](https://github.com/ponpaku/wp-easy-slug-protect/commit/e3672987d7e72b51dc9aed6adf4a369ed6ff5cf7)  
**Executed:** 2026-11-07 23:45 UTC (2026-11-08 08:45 JST).  
**Full CI:** https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/42426598832  
**Evidence:** Artifact `esp-fast-gate-v2-lifecycle-actual` (raw HTTP samples, comparison.json, stages.log, timings.csv, Apache logs; 14-day artifact retention).

> This is a reproducible baseline for a draft implementation. The previous green run measured elapsed operations with a wall-clock counter, which produced a **negative `gate_build_cli` interval** on a shared runner; that single timing is invalid. The next commit changes the test harness to a monotonic counter and adds a standalone CLI GC check. Other positive operation times and HTTP benchmarks below are directly observed, not inferred. HTTP benchmark uses Python `time.perf_counter_ns`, already monotonic.

## Environment and inputs

- Ubuntu GitHub Actions disposable runner; WordPress **7.1.3** (downloaded during CI), PHP **8.3**, Apache **2.4**, MySQL **8.0**, SQLite3; the entire test is isolated from production and uses disposable MySQL database `esp_fg_lifecycle`.
- Source: **actual published v0.7.41 release ZIP**, downloaded and verified against its published SHA-256. Install and activate through WP-CLI.
- Create **245 media attachments** in the released plugin and retain an existing protected path setting and one active login/session DB row. Upgrade using WordPress's ZIP install with `--force`, because the Draft still has the **same 0.7.41 plugin version header**. This is a real file-replacement update test, **not** a test of a future incremented-version updater.
- Validate existing WordPress options, attachment IDs, count, session token hash, MySQL plugin tables and DB version before and after the update. Confirm that update does not accidentally provision/activate Fast Gate.
- The HTTP fixture uses a **218-byte JPEG**; each of the four measured paths receives 5 warm-up requests and 60 successful GET samples. It is a small-file, sequential loopback latency comparison, **not** real-user page loads or throughput measurement.

## Release-to-PR and operational lifecycle

| Operation | Observed outcome in Actions run 42426598832 |
|---|---|
| Download published release ZIP and compare its SHA-256 | PASS |
| Install and activate v0.7.41, create 245 attachments and one authoritative session | PASS |
| Update with PR build through WP-CLI `plugin install --force` | PASS; existing options, session, attachments and MySQL DB version preserved |
| Plugin database tables | Existing four login/remember/attempt/limit tables checked before/after; same counts (one session row) |
| Media index initial Cron batch | PASS, cursor advances to 203 while `ready=false` |
| Resume media index via `wp esp media-index build --max-seconds=120` | PASS, `ready=true` |
| Provision SQLite ledger + keys via `wp esp gate-ledger provision` | PASS, `ready=false`, `serving=false` |
| `wp esp gate-ledger status` and `build` | PASS; `media=245`, `paths=1`, `ready=true`, `serving=false` |
| Manually rebuild via PHP catalog API | PASS, 245 matching entries and public/protected policies |
| Abort builder process after `ready=0` and before the MySQL media SELECT | PASS; restarted CLI `build` restores ready catalog |
| Exit SQLite worker with an uncommitted WAL transaction | PASS; SQLite `PRAGMA integrity_check` returns `ok`; uncommitted row absent |
| Insert pending protected-file fence, run real indexed Cron event | PASS; pending owner is repaired and resulting protected policy is reflected in SQLite |
| Insert two expired revoked refs, one confirmed and one unconfirmed; run Cron maintenance | PASS; only confirmed expired ref GC'd, unconfirmed ref retained |
| Restore the public fixture after the Cron mutation | PASS; both WordPress index and SQLite again classify the test file as public |
| Test standalone HTTP in temporary Apache VirtualHost routing | PASS; protected/authorized and public files HTTP 200 and byte-identical to legacy; anonymous protected file 403; HEAD and Range 206 checked |
| Test direct SQLite URL and standalone gate `serving=false` | PASS; direct SQLite URL blocked, and stopping standalone serving returns 503 |
| Production activation flag | Always OFF; no shipped automatic Rewrite switch |

### Representative construction and maintenance timings

On the above shared runner (operation elapsed time, including WP-CLI startup, **except** API time):

| Phase | Observed |
|---|---:|
| Published release ZIP install | 1,717 ms |
| Create 245 attachments on v0.7.41 | 1,602 ms |
| Replace released ZIP with PR ZIP | 1,295 ms |
| Check upgrade preserved state | 280 ms |
| First index Cron batch | 844 ms |
| Resume index via WP-CLI | 402 ms |
| Provision SQLite keys/ledger via WP-CLI | 208 ms |
| Manual catalog rebuild **PHP-internal** | 49.8 ms |
| Manual rebuild process total, including startup | 201 ms |
| Resume interrupted catalog build via WP-CLI | 258 ms |
| Cron reconcile + revocation maintenance | 334 ms |
| Standalone HTTP test preparation | 158 ms |

**Invalid measurement excluded:** the earlier `gate_build_cli` CSV value was **-448 ms**, resulting from a non-monotonic wall clock, and cannot support a performance conclusion. Re-test after switching the harness to a monotonic timer. These timings are illustrative for the small CI dataset, not production sizing estimates.

## Real HTTP benchmark

**Each median/p95 is in milliseconds; 60 GETs per mode and file, 5 warm-ups, Apache PHP 8.3, sequential loopback.**

| File / routing | Legacy median | Standalone median | Median improvement | Legacy p95 | Standalone p95 | p95 improvement |
|---|---:|---:|---:|---:|---:|---:|
| Protected, valid test credential | 10.5295 | 1.0241 | 10.282× | 11.9267 | 1.0978 | 10.864× |
| Public | 10.0149 | 0.9472 | 10.573× | 10.9502 | 0.9792 | 11.183× |

The test was not accepted until the standalone PHP entrypoint was **included in the installed PR ZIP**, temporary Apache VirtualHost Rewrite truly routed to it, and standalone-only cache/header behavior was seen. The earlier apparently similar legacy/fast timings were **discarded** because the test had silently fallen back to the legacy handler. The test also checks identical SHA-256 for the 218-byte response body, unauthorized protected 403, HTTP HEAD, Range 206, and 503 with `serving=0`.

**Concrete defect fixed during validation:** `tools/build-release.py` previously omitted the `gate/` directory, so the distributed plugin ZIP lacked the standalone Fast Gate entrypoint. The builder now includes `gate/` and mandates both standalone PHP files, and the lifecycle test asserts the files exist after ZIP upgrade.

## Remaining release blockers / evidence limits

1. **Not production-enabled.** The Apache route in this experiment is installed **only inside disposable CI**. Automatic activation, rollback and Rewrite bypass prevention remain separate engineering gates. LiteSpeed and Nginx Fast Gate routing were not benchmarked.
2. **Not a real user login/logout E2E.** The benchmark issues a valid signed ticket from known disposable credentials; it does not prove the full browser sign-in, Remember Me refresh, password changes, unsuccessful SQL deletion, UI redirects and multi-session lifecycle.
3. **No hard-kill / power-loss guarantee.** The SQLite WAL test exits a PHP process with a pending transaction; it does not simulate SIGKILL during fsync, a disk crash, corruption, external backup restore, or multi-host storage.
4. **WP-Cron was explicitly executed via WP-CLI.** It verifies registered events and real Cron callbacks, not unattended triggering under low site traffic, cron daemon scheduling, or long-term orphan maintenance. The scheduled event's continuity and dedicated CLI GC are additionally checked in the next harness revision.
5. **No load/scale conclusion:** 245 attachments, one protected path, tiny JPEG, sequential requests only. Need larger file sizes, >=100k attachments, parallel requests, CPU, memory, disk I/O, p95/p99 under saturation, and demonstrably no WordPress/MySQL bootstrap on the media hot path.
6. **No future-version migration test.** The upgrade uses the same plugin version `0.7.41` under `--force`; test a version-incremented ZIP, upgrade notices/hooks and preservation of real-world settings before any stable release.
7. **No external-backup rollback detection.** Consistent with Issue #80 R03 scope: backup restoration is an operator procedure, not auto-detected. Document reset/key rotation steps before shipping.

### Reproduction

Run `fast-gate-upgrade-lifecycle` in `.github/workflows/php-security.yml`. It invokes `.github/scripts/test-fast-gate-lifecycle.sh` and generates `esp-fast-gate-v2-lifecycle-actual`. Inspect `stages.log`, `timings.csv`, `comparison.json`, `legacy-http.json`, `fast-http.json` and `apache.log`. The test refuses to run without `CI=true` and `ESP_ALLOW_DISPOSABLE_DB=1` and verifies the disposable database name.

Related: [Issue #80](https://github.com/ponpaku/wp-easy-slug-protect/issues/80), [PR #81](https://github.com/ponpaku/wp-easy-slug-protect/pull/81), [implementation notes](issue-80-fast-gate-v2-implementation.md).
