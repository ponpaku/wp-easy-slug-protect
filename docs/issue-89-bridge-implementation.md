# Issue #89 — Fast Gate v2 WordPress authentication bridge

**Status:** draft implementation; **not production accepted**. Base: PR #83 at `150a04f5a14baa570c305b1e8df371356e86831a`.

## Intent and invariants

* Normal public/signed protected GETs continue using the standalone Fast Gate and SQLite. No WP bootstrap.
* Missing or invalid signed Gate ticket + plausible old ESP cookie triggers a one-time origin-relative 307 to the WP frontend. Cookie presence itself conveys no authorization.
* Direct image top-level navigation without an ESP cookie can open the configured login page, through a separately typed `login` handoff. Embedded `<img>` remains 403 rather than receiving login HTML. Request fetch-metadata is **UX-only**, not an authorization factor.
* A handoff uses AES-256-GCM encrypted payload plus a purpose-separated HMAC keyed from the **current activation's ticket key**. It is valid for 60 seconds, binds site, ESP path, canonical uploads-relative filename, GET/HEAD and purpose (`auth|login`); no raw credential or plaintext protected filename appears in the handoff URL.
* The WordPress frontend receives a handoff only at the configured `home_url('/')` path, checks the current MySQL-backed attachment index and protected-path setting, validates each live session/Remember credential in MySQL, and checks a **single SQLite read snapshot** (`serving`, media and fence, path generation/blocked, all present valid credential refs) immediately before emitting cookie/media bytes.
* A revoked credential or unknown SQLite read result cannot be turned into a permitted request. A valid MySQL session and an issued handoff are individually insufficient for media permission.
* The bridge creates a new Gate ticket only within an already-verified MySQL session expiration. It **does not extend Remember Me** (deliberate security tradeoff until cross-store renewal/logout races have their own contract).
* The initial bridge image is delivered by WP/PHP, not redirected to Fast Gate a second time. Future requests use the newly minted `esp_gate_{path_id}` cookie and run directly through the Gate.
* Legacy `ESP_Media_Protection::handle_media_access()` is not called by the bridge, so its `is_admin()/upload_files` shortcut cannot admit ESP-unauthenticated traffic. File ownership is resolved independently via `ESP_Media_Index::resolve()`.
* Gate and WordPress PHP fallback share the strict one-range parser; HEAD ignores Range. Protected bridge content is `no-store` and avoids LiteSpeed internal file delegation on its first response.

## Acceptance / remaining work

* `php tests/fast-gate-bridge-contract-test.php` exercises Gate classification, known/unknown revocations, handoff tampering, signature epoch change, encoded traversal, anonymous direct-vs-embedded, and shared Range contract.
* Full PHP syntax lint is expected to pass. **Local environment lacks SQLite3 PHP extension**, so this is not SQLite transactional integration validation. MySQL-backed WordPress authentication, browser Set-Cookie, and LiteSpeed E2E were not executed locally.
* On Apache CI and an isolated LiteSpeed clone, run actual old-login-before-enable → first-image 200 via bridge → subsequent-image 200 via Gate; logout/failed DB deletion; re-enable; Remember; simultaneous session + Remember; invalid cookies; symlinks; media ownership changes; 20/100 parallel images; HEAD/Range 206/416; final protected 200/206/302 headers; browser Cookie path/domain/Strict handling. Confirm private ledger HTTP denial and route probe regression.
* Only same scheme/hostname/port home/uploads origins with supported simple home paths are enabled. Re-enabling Fast Gate saves the frontend bridge path into its private SQLite metadata. Unsupported origins fail activation explicitly.
* The readonly SQLite snapshot is the authorization linearization point: revocations committed **before** snapshot are denied. Already in-progress responses may complete after a later revocation. Cross-store MySQL/SQLite global transactions are not promised.
* The handoff uses OpenSSL AES-256-GCM; installation without this PHP capability is refused at enable. The historical ticket key compatibility method remains for old isolated fixtures, but live Gate and WP issuance now require a persisted activation nonce through `ticket_signing_key_strict()`; missing generation fails closed.
* Existing `ESP_Media_Deriver` internal send modes and product/WordPress semantics require regression tests. `setcookie()` `true` only means PHP queued an HTTP header, not that a browser accepted it.
* Direct URL top-level login navigation depends on `Sec-Fetch-Mode: navigate` and `Sec-Fetch-Dest: document`. Clients without these headers retain safe 403; do not claim full legacy 302 parity without browser coverage.
* Default user-facing behavior must not silently update live production sites. Keep PR #83 draft until required real HTTP and fault-injection tests pass.

## Additional product-managed HTTP acceptance regression

The deployment E2E now drives the actual installed uploads Rewrite after
successful activation (not the earlier temporary VirtualHost benchmark):
1/20/100 simultaneously dispatched old-session requests, each receiving
exactly one origin-local 307, WordPress 200 with identical protected bytes,
no-store/no-referrer and one HttpOnly/SameSite=Strict Gate cookie; then each
client repeats the original media GET with no redirect or cookie issuance.
A 30-second per-request liveness guard applies in disposable CI, with p50,
p95, p99, max and wall time recorded. This is not a production throughput SLA
or a claim of 100 distinct physical image files: the same disposable JPEG is
used and client jars are separate to model already-in-flight requests.

The same suite simulates rejecting the Gate cookie while retaining a valid
source cookie (each request may bridge once, never loop), checks Remember-only
recovery including rotation on disable/re-enable, and rejects tampered,
handmade, method-mismatched, extra-query and credential-free handoffs.
No cookie or handoff value is written to the test result. The real browsers'
HTTPS, actual Cookie scope/SameSite enforcement and server access-log
redaction are separate matters; the HTTP client cookie policy is not a browser
test. Default server logs may include encrypted handoff query strings; they
contain no raw ESP cookie but must not be assumed redacted by the plugin.

See CI for actual results. Remains separate from SQLite/write fault injection,
expired-but-live source credential tests, updater integration at a changed
version, LiteSpeed host capacity and other open acceptance criteria.

## Follow-up: full login return target on subdirectory sites

Static review and local execution of the actual previous release-candidate
ZIP exposed a shared login-form bug: for home
`https://example.test/blog`, a media return URL
`https://example.test/blog/wp-content/uploads/example.jpg` was reduced only
to `/blog/wp-content/uploads/example.jpg`; completing the form reapplied
`home_url()`, incorrectly producing `/blog/blog/wp-content/uploads/example.jpg`.
This is not a Fast Gate-specific second redirect builder defect.

The shared `ESP_Core::sanitize_path()` now removes the exact current home
URL prefix once from same-home absolute URLs. Home-relative values posted
by the form remain relative and are not stripped again. The shared legacy
media redirect still emits its existing encoded absolute URL; the Gate,
MySQL and SQLite authorization code are unchanged. The regression explicitly
tests both form and POST normalization, root and subdirectory cases, a
real nested same-name slug, path prefix boundaries, and local-only handling
of external-origin input. This is a CPU-only PHP contract test of the
return target; a real subdirectory WordPress deployment is still untested.

The actual Chromium admin E2E additionally opens the protected JPEG in a
fresh non-admin browser context, follows the 307/302/login form, submits
the real ESP password form, checks return 200/no-store and accepted cookies,
removes only the Gate cookie, verifies browser-driven 307/200 and subsequent
direct 200, and logs out. The disposable login-page option is restored.
CI uses HTTP root-layout Apache, not a claim of HTTPS/LiteSpeed compatibility.

## Additional real HTTP fault-injection acceptance

The product-managed Apache route is exercised with a cryptographically valid
but expired Gate proof and handoff (real signer, backdated issuance, no clock
change), including a live Remember source that can recover and a separate
MySQL expiry update that cannot. The fixture transmits the expired proof in a
still-live client Cookie; direct SQL is not an instantaneous supported
revocation mechanism and valid previously issued proofs are not tested as
if they were expired.

The real MySQL session and Remember credential SELECTs are each replaced by
a query to a nonexistent table in the disposable fixture; a response marker
proves injection was reached. The expected bridge response is 503 with no
new ticket or image bytes. A deliberately committed response tests the real
headers_sent() branch of cookie emission; its already-committed HTTP 200
cannot be changed, so only the non-media marker may appear, without a new
Cookie or redirect. This does not separately force a native setcookie()
false result when headers remain writable; client Cookie rejection was
tested earlier.

The disposable Docker fixture temporarily moves keys.php or the complete
SQLite file family, including WAL/SHM, inside the denied private directory.
Missing key, missing ledger and corrupt ledger must reject protected/public
standalone HTTP and the existing WP handoff (503), issue no Gate Cookie and
return no media bytes. A finally block restores the fixture; the previously
valid proof and a new bridge must work again. No production files or time
settings are touched. Fault report contains labels/statuses only. This
covers read-side availability faults, not every SQLite write/commit or
power-loss failure and not host-specific filesystem/OPcache behavior.

### First fault-run result and independent Cron observation

At `ac980d8e`, all 15 reported bridge fault cases passed; the deployment
HTTP stage reached 104 checks. The subsequent real browser login sequence
also passed, but the last admin enable confirmation failed. The Apache
log shows HTTP 204 signed route probes and admin success redirect followed
by wp-cron.php and `ESP: runtime verification failed`; the next settings
page was not the expected full page. No causal diagnosis is established.
See failed lifecycle job 133050772675 / run 43791511808. This run is NOT
reported as a green full suite.

To remove nondeterministic automatic Cron spawning during intentional
physical SQLite/key faults, the disposable HTTP stage disables automatic
spawning only after the earlier genuine index Cron phases. The HTTP suite
schedules the genuine updater retry and explicitly invokes wp-cron.php
after fault restoration, observes its before/after runtime, update and
migration status fields, and checks protected media and bridge recovery.
This is a stable-state test; it does not itself settle the observed Cron
versus deployment transition race or claim a production fix.

### Shared reference-validation inconsistency

Static inspection of the first failing lifecycle identified a concrete
inconsistency: `cron-check` in `tests/wordpress-fast-gate-lifecycle-ci.php`
restores a formerly protected JPEG by storing an exact empty protection-meta
value. The live index resolves it as public and both normal and standalone
media permission use the empty public sentinel. However the shared
`ESP_Migrator::verify_references()` rejects every ID absent from the
configured path map, including that empty value. A later updater retry can
therefore reject an otherwise correctly functioning installation. The
first failure log alone does not establish the stored migration reason.

The shared validator now accepts **only** the exact empty string as the
existing public sentinel, without altering rows or any authorization
decision. Byte-exact SQL deduplication preserves invalid nonempty values
(including space and case variants) that a site collation could otherwise
collapse. Real MySQL migration tests keep the empty and a valid protected
reference, reject whitespace/case/unknown IDs, and confirm recovery.
The lifecycle fixture deliberately retains its empty metadata value;
the explicit actual updater Cron retry must pass against it, rather than
changing the fixture to delete the metadata and concealing the inconsistency.

## Actual Core version-change upgrade while serving

The disposable HTTP lifecycle adds a synthetic next-patch ZIP derived from
the exact built candidate; only its version header and constant change.
No real tag or release is created. The real Core Plugin_Upgrader bulk API
runs in the Apache request, with only the external release/API download
transport substituted. It observes the actual pre-install hooks after they
stop serving and restore legacy Rewrite, before file replacement. It checks
old PHP cannot certify new code, observes the real shutdown callback, runs
wp-cron through HTTP, then verifies the new code's READY/version/update
markers, regenerated Rewrite writer version, settings and authentication
rows, legacy HTTP policy, explicit re-enable, original session/Remember
recovery, stale revoked proof rejection and logout. The test is of Core's
bulk updater, not a browser click or host-specific automatic update.
