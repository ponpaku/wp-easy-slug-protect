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
