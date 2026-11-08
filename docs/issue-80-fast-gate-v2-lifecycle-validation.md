# Issue #80 / PR #81 — v0.7.41 → Fast Gate v2 validation record

**Status: CI PASS, not cleared for production activation.**  
**Verified commit:** [f8fe25ad1a8dc56c06c902e2ebe3770173d75941](https://github.com/ponpaku/wp-easy-slug-protect/commit/f8fe25ad1a8dc56c06c902e2ebe3770173d75941)  
**Latest execution:** 2026-11-08 04:47 UTC (2026-11-08 13:47 JST).  
**Latest CI:** https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/42448161466  
**Prior independent passing runs:** https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/42444437752 and https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/42426598832  
**Evidence:** Artifact `esp-fast-gate-v2-lifecycle-actual` (raw HTTP samples, comparison.json, stages.log, timings.csv, Apache logs; 14-day artifact retention).

> This is a reproducible baseline for a **Draft**, not a release approval. A prior passing run recorded one invalid **-448 ms** `gate_build_cli` interval because the CI runner wall clock stepped backward. The latest successful run replaced it with Python `time.monotonic_ns` for CLI operation timing, confirmed a positive **460 ms** catalog build duration and also exercised standalone `wp esp gate-ledger gc`. The HTTP test uses the separately monotonic `time.perf_counter_ns`. The earlier invalid interval was never used in speed comparisons.

## Environment and inputs

- Ubuntu GitHub Actions disposable runner; WordPress **7.1.3** (downloaded during CI), PHP **8.3**, Apache **2.4**, MySQL **8.0**, SQLite3; the entire test is isolated from production and uses disposable MySQL database `esp_fg_lifecycle`.
- Source: **actual published v0.7.41 release ZIP**, downloaded and verified against its published SHA-256. Install and activate through WP-CLI.
- Create **245 media attachments** in the released plugin and retain an existing protected path setting and one active login/session DB row. Upgrade using WordPress's ZIP install with `--force`, because the Draft still has the **same 0.7.41 plugin version header**. This is a real file-replacement update test, **not** a test of a future incremented-version updater.
- Validate existing WordPress options, attachment IDs, count, session token hash, MySQL plugin tables and DB version before and after the update. Confirm that update does not accidentally provision/activate Fast Gate.
- The HTTP fixture uses a **218-byte JPEG**; each of the four measured paths receives 5 warm-up requests and 60 successful GET samples. It is a small-file, sequential loopback latency comparison, **not** real-user page loads or throughput measurement.

## Release-to-PR and operational lifecycle

| Operation | Observed outcome in Actions run 42444437752 |
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
| `wp cron event list` after Cron execution; `wp esp gate-ledger gc` | PASS; recurring index Cron still registered; standalone CLI GC succeeds, unconfirmed revocation remains | 
| Restore the public fixture after the Cron mutation | PASS; both WordPress index and SQLite again classify the test file as public |
| Test standalone HTTP in temporary Apache VirtualHost routing | PASS; protected/authorized and public files HTTP 200 and byte-identical to legacy; anonymous protected file 403; HEAD and Range 206 checked |
| Test direct SQLite URL and standalone gate `serving=false` | PASS; direct SQLite URL blocked, and stopping standalone serving returns 503 |
| Production activation flag | Always OFF; no shipped automatic Rewrite switch |

### Representative construction and maintenance timings

On the above shared runner (operation elapsed time, including WP-CLI startup, **except** API time):

| Phase | Observed in latest run |
|---|---:|
| Published release ZIP install | 2,039 ms |
| Create 245 attachments on v0.7.41 | 2,230 ms |
| Replace released ZIP with PR ZIP | 1,236 ms |
| Check upgrade preserved state | 305 ms |
| First index Cron batch | 1,287 ms |
| Resume index via WP-CLI | 606 ms |
| Provision SQLite keys/ledger via WP-CLI | 353 ms |
| Initial catalog build via WP-CLI | **460 ms** |
| Manual catalog rebuild **PHP-internal** | 102.2 ms |
| Manual rebuild process total, including startup | 356 ms |
| Resume interrupted catalog build via WP-CLI | 434 ms |
| Cron reconcile + revocation maintenance | 655 ms |
| Dedicated `wp esp gate-ledger gc` | 345 ms |
| Standalone HTTP test preparation | 263 ms |

These timings use a monotonic counter and include WP-CLI/process startup unless specified. The initial manual 245-entry catalog rebuild takes 102.2 ms within PHP, while the end-to-end CLI build takes 460 ms. Both are for a small dataset on a shared CI runner, not production capacity estimates. The prior run's invalid `-448 ms` build interval is excluded; neither latency benchmark uses it.

## Real HTTP benchmark

**Each median/p95 is in milliseconds; 60 GETs per mode and file, 5 warm-ups, Apache PHP 8.3, sequential loopback.**

| File / routing | Legacy median | Standalone median | Median improvement | Legacy p95 | Standalone p95 | p95 improvement |
|---|---:|---:|---:|---:|---:|---:|
| Protected, valid test credential | 16.8803 | 1.5691 | **10.758×** | 18.1138 | 1.6179 | 11.196× |
| Public | 16.1013 | 1.4163 | **11.369×** | 17.4362 | 1.4860 | 11.734× |

Independent prior run [42426598832](https://github.com/ponpaku/wp-easy-slug-protect/actions/runs/42426598832) also passed with 60 GETs per path: protected 10.5295 → 1.0241 ms (**10.282×**) and public 10.0149 → 0.9472 ms (**10.573×**) median. Absolute latencies vary substantially with the runner; the observed speedups across these two disposable runs were approximately **10.3–11.4×**, not a claim about production speed.

The test was not accepted until the standalone PHP entrypoint was **included in the installed PR ZIP**, temporary Apache VirtualHost Rewrite truly routed to it, and standalone-only cache/header behavior was seen. The earlier apparently similar legacy/fast timings were **discarded** because the test had silently fallen back to the legacy handler. The test also checks identical SHA-256 for the 218-byte response body, unauthorized protected 403, HTTP HEAD, Range 206, and 503 with `serving=0`.

**Concrete defect fixed during validation:** `tools/build-release.py` previously omitted the `gate/` directory, so the distributed plugin ZIP lacked the standalone Fast Gate entrypoint. The builder now includes `gate/` and mandates both standalone PHP files, and the lifecycle test asserts the files exist after ZIP upgrade.

## Real HTTP authentication lifecycle and concurrent access (run 42448161466)

**PASS:** 9/9 PHP security workflow jobs; in particular, the full
`fast-gate-upgrade-lifecycle` job passed with a fresh actual WordPress
7.1.3 installation, MySQL 8, Apache/PHP 8.3 and SQLite. Test-only plugin
`tests/fixtures/fast-gate-http-auth-mu.php` is copied solely into the
disposable WordPress fixture; it is gated by an explicit Docker environment
variable and the exact disposable DB name, and is **excluded from the
distribution ZIP**. It calls existing `ESP_Auth::process_login()`,
`ESP_Auth::is_logged_in()` and `ESP_Logout` methods, while the client uses
real HTTP `Set-Cookie` and subsequent `Cookie` headers. Fast Gate routing
remains a disposable Apache VirtualHost rule, **not a product feature**.

The real WordPress HTTP scenarios passed **57 assertions**:

- Normal session login issues both WordPress's session cookie and signed gate
  cookie; protected GET returns identical image bytes.
- Remember Me login issues the identity/token and signed gate cookies,
  refreshes via real WordPress/MySQL, and continues authorizing media.
- Session logout and Remember Me logout revoke the corresponding credentials
  before removing MySQL rows; a **retained copy of the old cookie header**
  returns HTTP 403. A refresh after revoked session logout cannot reissue.
- With session and Remember cookies simultaneously present, one logout denies
  **both** previously issued login kinds.
- WordPress option update increments the password generation from **1 to 2**
  while the test-only gate is active; a previously valid ticket is rejected,
  and a fresh WordPress password login obtains a new usable ticket.
- A forged HMAC, altered login token, missing signed ticket, signed ticket
  without its original login cookie, anonymous requests, unsupported POST and
  malformed Range requests are denied. HEAD and a valid single byte Range
  operate correctly.

The **8-worker mixed concurrency check** made 128 actual standalone HTTP GETs
(64 authenticated and 64 anonymous) against the same protected file. Each
authenticated request returned HTTP 200 with the expected body digest; each
anonymous request returned HTTP 403. Local-runner latency observations:
authenticated median **6.0817 ms**, p95 **8.1745 ms**; anonymous median
**5.6955 ms**, p95 **8.4564 ms**. The 128-request burst is not a sustained
throughput or max-worker benchmark.

Sequential small-file benchmark on the same latest run (60 successful GETs
per mode and file; median/p95 in ms):

| Mode / file | Legacy median | Fast Gate median | Median speedup | Legacy p95 | Fast Gate p95 |
|---|---:|---:|---:|---:|---:|
| Protected, authorized | 18.0826 | 1.5654 | 11.551× | 19.8066 | 1.6449 |
| Public | 16.9135 | 1.4580 | 11.600× | 21.1004 | 1.5238 |

Each HTTP result is checked for the distinct standalone response headers,
matching file digest, no unauthorized disclosure, and fail-closed behavior.
All metrics are loopback results from one shared Actions runner, not
production estimates. Raw test output is archived in
`auth-lifecycle-http.json` and the other lifecycle artifacts.

## Remaining release blockers / evidence limits

1. **Not production-enabled.** The Apache route in this experiment is installed **only inside disposable CI**. Automatic activation, rollback and Rewrite bypass prevention remain separate engineering gates. LiteSpeed and Nginx Fast Gate routing were not benchmarked.
2. **Browser UI / failure injection remain separate.** HTTP requests now exercise real WordPress password authentication, Remember Me refresh, session+Remember logout and password generation changes (with real MySQL and saved/returned cookies). However, the test uses a disposable test-only HTTP endpoint rather than the shipped shortcodes, browser UI, real nonce submission/redirects, and does not inject an actual logout SQL deletion failure. Those remain acceptance cases.
3. **No hard-kill / power-loss guarantee.** The SQLite WAL test exits a PHP process with a pending transaction; it does not simulate SIGKILL during fsync, a disk crash, corruption, external backup restore, or multi-host storage.
4. **WP-Cron was explicitly executed via WP-CLI.** It verifies registered events and real Cron callbacks, not unattended triggering under low site traffic, cron daemon scheduling, or long-term orphan maintenance. The scheduled event's continuity and dedicated CLI GC both passed in the latest run.
5. **No sustained load/scale conclusion:** 245 attachments, one protected path, tiny JPEG; an 8-worker/128-request parallel authorization test now passes, but larger files, >=100k attachments, higher parallelism, CPU/memory/I/O, p95/p99 under saturation and a runtime proof that the hot path boots neither WordPress nor MySQL remain to be assessed.
6. **No future-version migration test.** The upgrade uses the same plugin version `0.7.41` under `--force`; test a version-incremented ZIP, upgrade notices/hooks and preservation of real-world settings before any stable release.
7. **No external-backup rollback detection.** Consistent with Issue #80 R03 scope: backup restoration is an operator procedure, not auto-detected. Document reset/key rotation steps before shipping.

### Reproduction

Run `fast-gate-upgrade-lifecycle` in `.github/workflows/php-security.yml`. It invokes `.github/scripts/test-fast-gate-lifecycle.sh` and generates `esp-fast-gate-v2-lifecycle-actual`. Inspect `stages.log`, `timings.csv`, `comparison.json`, `auth-lifecycle-http.json`, `legacy-http.json`, `fast-http.json` and `apache.log`. The HTTP auth scenario is implemented in `tests/fast-gate-http-auth-lifecycle.py` and its disposable-only MU-plugin fixture `tests/fixtures/fast-gate-http-auth-mu.php`. The test refuses to run without `CI=true` and `ESP_ALLOW_DISPOSABLE_DB=1` and verifies the disposable database name.

Related: [Issue #80](https://github.com/ponpaku/wp-easy-slug-protect/issues/80), [PR #81](https://github.com/ponpaku/wp-easy-slug-protect/pull/81), [implementation notes](issue-80-fast-gate-v2-implementation.md).
