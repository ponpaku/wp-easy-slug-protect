# Issue #84 — read-only Rewrite audit

## Observation and scope

The 2026-11-16 LiteSpeed clone used an external server Cron invoking raw
`php -f wp-cron.php`. Its daily result reported `rewrite_mismatch` while
the admin HTTP inspection matched; WP Crontrol HTTP execution cleared
the warning. The previous boolean conflated failed inspection and actual
file mismatch. This change is confined to audit evidence and its UI.

## Contract

- Known HTTP Apache/LiteSpeed: compare the exact generated rules.
- CLI/phpdbg: do not claim knowledge of the web server, even if an
  environment variable names it. Compare the common Fast Gate rule or
  both legacy templates. An exact legacy match is partial evidence.
- Unknown legacy deployment without an ESP block: unverified, not mismatch
  or success (the site could use external rules).
- Fast Gate uses a common managed file; missing/invalid rules are a mismatch.
- Nginx HTTP: external, not verified; Fast Gate on Nginx is unsupported.
- Retain existing strict comparisons of writer version, key, route,
  exact managed block and foreign-rule priority. A file check is NOT
  a live HTTP routing, authorization, or server configuration proof.
- No new keys, Rewrite writes, state transitions, HTTP probes, added Cron
  hooks, regular page or media traffic I/O. Existing strict boolean
  checks, activation and authorization retain their behavior.

## Persistence and UI

The existing non-autoload option `esp_integrity_last_audit` retains
`checked_at` and `errors` and adds schema 2, version, source,
last_daily_hook_at, structured rewrite evidence, and retained prior HTTP
failure evidence. The daily cleanup callback and authorized manual POST
share the inspector. Manual recheck updates only audit evidence, not
cleanup or runtime verification; it preserves the last daily-hook time.

A separate advisory lock serializes audit publishers. A prior HTTP
mismatch (or a legacy mismatch of unknown provenance) survives incomplete
CLI checks until an HTTP managed-file match. A resolved CLI-only file error
can clear on the next matching file check. Failed publication does not
claim success. The panel shows saved time in the site's timezone, context,
result, machine-readable reason, daily-hook timing and stale/overdue
indicators. The snapshot is not a transactional attestation across
concurrent deployment or settings changes. No rule contents, credentials
or absolute paths are displayed. Existing old-format diagnostics still
read checked_at and errors; partial and external evidence need the new
rewrite field to distinguish from fully matched managed rules.

## Test coverage

Unit tests validate both legacy dialects, conflicting foreign rules,
unknown external policy, read-only key handling, retained Web failures,
unverified results, lock contention and stale notices. The disposable
WordPress/Apache lifecycle extends its authenticated Chromium run to
schedule and execute raw PHP wp-cron.php with SERVER_SOFTWARE absent in
both legacy and active Fast Gate modes, use the real administrator form,
reject unauthenticated and bad-nonce requests, and compare protection/
Rewrite fingerprints before and after. Actual LiteSpeed HTTP behavior
still requires the clone test. No production operations are performed.
