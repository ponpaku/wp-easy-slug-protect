# Media protection rollout and release gates

PR #45 introduces a WordPress-standard uploads ownership resolver (#42) and a
conservative access decision (#43). **These changes must not be treated as
production-certified protection until the checks below are completed.**

## What the PHP policy guarantees

- A uniquely verified attachment owner may be evaluated against its current ESP
  protected-path metadata and the requesting user's ESP authentication.
- JPEG/PNG/WebP/AVIF thumbnails and some converted, edited and scaled files are
  resolved only when the attachment's unfiltered WordPress metadata verifies
  the exact requested filename.
- Unknown, ambiguous, or failed ownership is denied while any ESP-protected
  media record exists or the database cannot confirm the state.
- When a successful database query proves there are **zero** ESP-protected media
  records, unmanaged public uploads may be served. A stale settings default is
  not used as proof.
- These are **PHP-level** guarantees only. A cache, CDN, image converter, or
  web server that bypasses PHP may still expose files.

## Compatibility impact: mandatory pre-merge audit

On sites with any protected attachment, files uploaded outside the WordPress
attachment library may return HTTP 403, because they have no provable owner.
Some legacy thumbnails and third-party generated variants may also be denied
until their format is supported.

Before activating this policy on a live site:

1. On a staging copy, inventory uploads not mapped to current attachment
   metadata. Do **not** record private URLs or authentication tokens in public
   issues or logs.
2. Compare a representative selection of unprotected and protected originals,
   thumbnails, resized/edited images, PDF previews, and conversions.
3. Verify the expected behavior of non-library assets used by themes and other
   plugins. Do not replace denied responses with a blanket public fallback.
4. Design narrowly scoped, manually reviewed exceptions if required. Such an
   exception mechanism is **not yet implemented** in PR #45; implementing it
   remains part of #43.
5. Roll out only after restoration and rollback procedures have been exercised
   without removing protections on existing private media.

## HTTP acceptance matrix (not yet run)

Use an isolated site with the same LiteSpeed/Apache version, WordPress version,
PHP version, cache plugins, and proxy/CDN configuration as production.

| Scenario | Required behavior |
| --- | --- |
| Unauthenticated direct request for protected original JPG/PNG/WebP/AVIF | No file bytes delivered |
| Unauthenticated request for protected -150x150 and converted derivatives | No file bytes delivered |
| Authorized user requests the same files | Correct content, type, cache headers and Range responses |
| Non-protected standard attachment | Normal successful delivery |
| Unknown/ambiguous ownership or injected database error | No file bytes delivered |
| Transition 0 to 1 protected attachment | Rules already active; no direct-access window |
| Temporarily unwritable uploads/.htaccess | Failure visible to admin; no false assurance that static URLs are protected |
| uploads symlink root | Correct attachment resolution and confinement |
| Nested symlinks or traversal attempts | Denied |
| CDN / LSCache and logged-out session | No shared cached copy of protected content |
| CFM-generated alternate file paths (#40) | Verified separately; standard resolver alone is insufficient |

Test both HTTP status **and response body**, including redirect chains and
conditional/Range requests. Verify the actual active Rewrite configuration,
not only the generated string or presence of an .htaccess file.

## Performance release gate

Because media protection routes supported extensions through PHP even when
there are no protected attachments, measure:

- Public image p50/p95 response latency before/after PR #45.
- PHP worker saturation, CPU and peak concurrency.
- Per-image database query count and total DB time with 10k/100k attachments
  where feasible.
- LiteSpeed and CDN cache behavior, and protected-media cache isolation.

A significant regression is a release blocker. Do not restore the old
COUNT-based Rewrite removal, which permits a zero-to-one exposure race.

## Work still outside this PR

- Actual Apache/LiteSpeed/WordPress integration certification (#31).
- CFM and other optimizer-specific routing and path formats (#40).
- Safe opt-in exception and migration diagnostics for unmanaged legacy files (#43).
- Full WordPress 7.1.3 upload lifecycle, sideload/finalize, extended HEIC/GIF
  metadata and large-library benchmarks (#42).
- GitHub Releases updater and resumable migrations (#44).

Keep PR #45 open until these security and availability gates have been reviewed.
