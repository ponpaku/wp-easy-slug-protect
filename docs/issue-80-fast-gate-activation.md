# Fast Gate v2 — verified activation, HTTP routing and fallback

This document describes the **stacked child PR #83** on top of Draft PR #81.
Public-media caching remains a **separate topic, Issue #82**; this child PR
does not change any `Cache-Control` behavior.

## Scope and user entrypoints

- WordPress administrator: **Easy Slug Protect → Settings → 高速ゲート（Fast Gate v2）** (the section inside the existing ESP settings page, not a separate WordPress Settings menu), with actions **Prepare
  ledger**, **Enable verified gate**, **Disable / restore WordPress route**,
  and explicit **Recover interrupted transition** (visible when a deployment
  lock remains).
  The storage directory is plugin-managed; no path entry form or server rule
  copy/paste is required.
- WP-CLI: `wp esp gate-ledger provision`, `build`, `activate --server=apache`
  (or `--server=litespeed`), `deactivate --server=apache`,
  `recover --server=apache`, `status`, `gc`. WordPress CLI does not know the active HTTP server:
  its explicit `--server` **hint is not evidence** of routing; mandatory
  real HTTP checks still prove the deployment.
- Nginx, OpenResty, Tengine and unknown server types: **no Fast Gate support**
  in this PR. The existing WordPress/PHP media route is left unchanged.

**Prepare** explicitly opts in to the existing incremental media index,
if READY but not yet enabled. If the index is still being built, it runs a
bounded index batch and reports **index_build_pending**; retry later after
Cron/index progress. It provisions `wp-content/esp-fast-gate-v2` only if
absent; on an already-provisioned installation it checks existing
secrets/schema and creates/rebuilds the catalog only when not READY. `keys.php` is never silently regenerated.
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
3. Create an **actual, non-secret and unique .jpg file** in the uploads
   directory and send a fresh nonce/HMAC HTTP request for that existing
   file. Only the standalone PHP handler, with the site secret, can return
   204 and the signed acknowledgement. The test file is removed on success
   **and** failure. This catches front proxies that serve existing static
   uploads directly but proxy missing files to WordPress/Apache. Direct
   SQLite HTTP access must also return 403 on the real origin.
4. Perform a **final full catalog reconciliation while routing is armed and
   serving remains OFF**. If index state changed unexpectedly, abort.
5. Atomically rotate the **per-deployment ticket-signing nonce** inside
   SQLite while the reader is stopped. The site-wide secret in `keys.php`
   is unchanged. Ticket HMAC keys derive from both values. This invalidates
   all old signed tickets on reactivation, including cookies copied before
   a legacy-only logout while Fast Gate was disabled.
6. Enable the WordPress Fast Gate synchronization/revocation option, then
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

**Interrupted operation:** the controller uses a DB-unique token lock.
Subsequent phases recheck that the same worker still holds the token,
and a late worker cannot delete a newer recovery lock. An explicit
admin/CLI recovery may claim the lock only if it is **at least 15 minutes
old**, after the administrator confirms no earlier PHP worker is still
running. Recovery stops SQLite serving first, restores the existing
legacy Rewrite, then disables Fast Gate synchronization. Failed
restoration retains the non-serving gate and requires operator action.
The lock is never silently stolen by ordinary requests.

## Plugin updates

The plugin must not replace the PHP gate or authorization bridge while the
standalone reader is still serving. On a matching
`upgrader_pre_install` event for Easy Slug Protect, before the existing
`esp_runtime_state=UPDATING` marker and actual filesystem replacement:

- In a verified Apache/LiteSpeed HTTP context, the deployment controller
  first **stops serving and restores the existing legacy WordPress/PHP
  Rewrite**, then permits the normal updater to continue.
- In WP-Cron/WP-CLI or unknown server contexts where the legacy route
  cannot be verified, the installation is **rejected with a WordPress
  error**, leaving the running gate unchanged. The administrator can disable
  the Fast Gate from the supported HTTP settings page and retry the update.
- After the update, Fast Gate remains OFF. The administrator can explicitly
  prepare/enable it again, which also rotates the signing nonce.

This prevents a new-version PHP file swap from silently stranding the
standalone reader with old policy and no WordPress-side revocation updates.
This is a guard for the normal core plugin updater path; bypassing WordPress
with direct filesystem/FTP replacement remains an unsupported operation while
Fast Gate is active.

## HTTP security probe design

The standalone gate accepts a probe only if the request provides an
`X-ESP-Gate-Route-Probe: <nonce>:<HMAC>` header, where the 128-bit random nonce
and two domain-separated HMACs use the existing site-wide signing secret.
The physical upload probe returns **204 with no file bytes** from the gate
script, plus a signed acknowledgement header. The header is never itself an authorization cookie.
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
- a simulated stale lock plus an installed non-serving gate Rewrite,
  followed by explicit recovery to the WordPress/PHP route;
- no HTTP probe-file remnants after success or forced failure;
- a plugin-update pre-install hook that restores the legacy media route
  before code replacement, and rejects an unknown-server update while
  leaving the live reader unaffected;
- successful production-class controller installation and HTTP traffic
  (public, protected, unknown media);
- real WordPress login after activation producing a signed media ticket;
- restore the WordPress/PHP media route and confirm existing login works;
- ordinary legacy-mode logout while Fast Gate is disabled, followed by
  reactivation: a retained old signed ticket returns 403, while a new
  WordPress login gets a usable ticket from the new signing generation;
- direct HTTP SQLite denial and the existing security regression suite.

**Before production rollout:** CI must pass on the actual child-branch
code. LiteSpeed requires a real-server check even if its Rewrite syntax
works in Apache tests. Shared-hosting loopback restrictions can prevent
activation; that is preferable to an unverified direct-media bypass.
Administrative setup may time out for very large catalogs (use WP-CLI).
The published release's default public media cache policy is not changed.

References: #80, #81, #82, #83.
