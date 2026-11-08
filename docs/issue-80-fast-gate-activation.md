# Fast Gate v2 — verified activation, HTTP routing and fallback

This document describes the **stacked child PR #83** on top of Draft PR #81.
Public-media caching remains a **separate topic, Issue #82**; this child PR
does not change any `Cache-Control` behavior.

## Scope and user entrypoints

- WordPress administrator: **Settings → Fast Gate**, with actions **Prepare
  ledger**, **Enable verified gate** and **Disable / restore WordPress route**.
  The storage directory is plugin-managed; no path entry form or server rule
  copy/paste is required.
- WP-CLI: `wp esp gate-ledger provision`, `build`, `activate --server=apache`
  (or `--server=litespeed`), `deactivate --server=apache`,
  `status`, `gc`. WordPress CLI does not know the active HTTP server:
  its explicit `--server` **hint is not evidence** of routing; mandatory
  real HTTP checks still prove the deployment.
- Nginx, OpenResty, Tengine and unknown server types: **no Fast Gate support**
  in this PR. The existing WordPress/PHP media route is left unchanged.

**Prepare** requires the existing media index enabled and READY. It provisions
`wp-content/esp-fast-gate-v2` only if absent; on an already-provisioned
installation it checks existing secrets/schema and creates/rebuilds the
catalog only when not READY. `keys.php` is never silently regenerated.
A large catalog may require a WP-CLI build rather than the administrative
request timeout.

## The only Rewrite owner

`ESP_Media_Protection::update_htaccess()` remains the sole writer of the
ESP-managed block in `uploads/.htaccess`. The selection is an internal
route marker `esp_fast_gate_v2_route` (`legacy` or `fast`), guarded against
ordinary WordPress option updates. It is **not** a second independent
`.htaccess` block. Existing safety checks (flock, temp/backup/rename,
third-party Rewrite conflict detection, readback/audit, version guard)
continue to operate.

While fast mode is armed, the managed Rewrite catches **all candidate media
extensions**, including new/unindexed files. It must NOT have a
`RewriteCond %{REQUEST_FILENAME} -f` condition or LiteSpeed's old
allow-through query-key shortcut. The standalone script decides if the
path is present and authorized in SQLite; unknown/ambiguous/fenced entries
are denied.

The gate script performs **no WordPress bootstrap or MySQL query** on its
HTTP media path.

## Transition state machine

**OFF / legacy**: WordPress/PHP media protection active, Fast Gate
`serving=false`, WP Fast Gate active option `0`, route marker `legacy`.

**Prepare**: verify index/schema/keys/catalog. Does not start standalone
serving or change Rewrite.

**Enable** (with an atomic WordPress deployment lock):

1. Verify actual server family, index READY, SQLite schema/catalog READY,
   keys bound to the present uploads root and URL prefix, and that Fast Gate
   is not serving.
2. Arm route marker and use the **existing atomic writer** to replace the
   ESP-managed block with the standalone target. The SQLite reader is still
   **stopped**, so media requests during the transition fail closed (503).
   WordPress metadata/password mutations already require SQLite fences once
   the route is armed, *before* the active option is enabled.
3. Send a fresh nonce/HMAC as an HTTP request header to an uploads media URL.
   Only the standalone PHP handler, with the secret key, can return the
   required 204 + authenticated acknowledgement header. Direct HTTP access
   to the SQLite ledger must return 403 on the same actual web server.
4. Perform a **final full catalog reconciliation while routing is armed and
   serving remains OFF**. If index state changed unexpectedly, abort.
5. Enable the WordPress Fast Gate synchronization/ revocation option, then
   switch the SQLite `serving` marker ON. Only after both steps may
   standalone media requests return image bytes.

Failed checks **never** enable serving. First stop serving, disable the
WP option, and attempt to restore the original WordPress/PHP Rewrite.
If this restoration cannot be verified, retain the **non-serving gate route
(503)** instead of falling back to static files. The operation reports a
failure to the administrator; the operator must resolve Rewrite/permissions.

**Disable**: stop SQLite serving FIRST; restore the legacy managed block
with the same atomic writer and audit; then disable the WP Fast Gate option.
A failed restore retains the non-serving gate rule and reports an error.

Ordinary `update_option('esp_fast_gate_v2_active','1')` does not bypass this
sequence. Media-protection setting OFF is also blocked while Fast Gate
routing is armed; deactivating the plugin attempts a verified fallback before
removing its managed media rule.

## HTTP security probe design

The standalone gate accepts a probe only if the request provides an
`X-ESP-Gate-Route-Probe: <nonce>:<HMAC>` header, where the 128-bit random nonce
and two domain-separated HMACs use the existing site-wide signing secret.
A valid request returns **204 with no file bytes**, plus a signed
acknowledgement header. The header is never itself an authorization cookie.
Without the secret, a fabricated 204 from WordPress/another handler will
not pass the authenticated response check.

The direct SQLite URL check is not a proof that every possible third-party
web-server configuration is safe; it is a strict operational preflight.
It deliberately fails to enable on unavailable loopback/HTTP, disabled
`.htaccess` overrides, unsupported origin/URL schemes or unexpected routing.

## Tests and limits

The child PR extends the existing reproducible real WordPress 7.1.3,
MySQL 8, SQLite3 and Apache Actions lifecycle:

- a deliberately failed route probe and verified rollback to WordPress/PHP;
- successful production-class controller installation and HTTP traffic
  (public, protected, unknown media);
- real WordPress login after activation producing a signed media ticket;
- restore the WordPress/PHP media route and confirm existing login works;
- direct HTTP SQLite denial and the existing security regression suite.

**Before production rollout:** CI must pass on the actual child-branch
code. LiteSpeed requires a real-server check even if its Rewrite syntax
works in Apache tests. Shared-hosting loopback restrictions can prevent
activation; that is preferable to an unverified direct-media bypass.
Administrative setup may time out for very large catalogs (use WP-CLI).
The published release's default public media cache policy is not changed.

References: #80, #81, #82, #83.
