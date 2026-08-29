# Changelog

All notable changes to **SitesSaver** will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.2.1] — 2026-08-29

Fixes the server-cron setup instructions, which handed out a command that could not run.

### Fixed — the trigger URL was not shell-quoted

The generated command embedded the URL bare. A URL carrying more than one query parameter
contains `&`, which the shell reads as "run everything before this in the background" — so
`...&force=1` was silently truncated and the request lost its key. Confirmed against real
bash: the old form dropped everything from `&` onward, the new form round-trips the URL
byte for byte.

### Fixed — the setup box mixed the schedule and the command together

Hosting panels (RunCloud, cPanel, Plesk, CyberPanel) ask for the schedule and the command
in separate fields. The page only ever offered one combined crontab line, so pasting it
into a panel's command box produced a doubled schedule such as
`* * * * * /bin/bash 0 * * * * wget ...`, where bash then tried to execute a file named
`0`. The job saved without complaint and never ran.

The Server Cron panel now has two tabs:

- **Hosting panel** — vendor binary, command, and the five schedule values as separate
  copyable fields, matching how those forms are laid out.
- **crontab -e** — the single combined line, for editing a crontab directly.

The panel command is wrapped as `-c '...'` because a panel that runs it through
`/bin/bash` would otherwise treat `wget` as a script filename.

Verified by running the generated command through `bash -c` exactly as a panel would: it
completed a real backup.

### Fixed — schedule fields rendered full-height

The five one-character boxes inherited the flex default of `stretch`, so each became as
tall as the whole row.

---

## [1.2.0] — 2026-08-29

Scheduling gains a Monthly option and the ability to run several frequencies at once, plus a
server-cron trigger for sites where WP-Cron is disabled. Browser dialogs are replaced with a
custom notification system. See [release-notes-v1.2.0.md](release-notes-v1.2.0.md).

### Added — Monthly backups, and more than one frequency at a time

Frequency is now a set of checkboxes rather than a single dropdown, so Daily (recent restore
points) and Monthly (long-term archive) can run side by side. Each selected frequency is
scheduled as its own cron event and tracks its own last-run time, so a monthly backup is not
considered "already done" just because the daily one ran.

Schedules saved by earlier versions keep working: the old single `frequency` string and the
old scalar last-run timestamp are both read and migrated automatically.

### Added — server cron trigger (works with `DISABLE_WP_CRON`)

WP-Cron only fires when someone loads a page, so on a low-traffic site scheduled backups run
late, and under `DISABLE_WP_CRON` they never run at all. The Schedule screen now shows a
private, key-authenticated trigger URL with copy-paste crontab, curl, and WP-CLI lines, plus
instructions for uptime-monitor services when there is no shell access.

The endpoint is safe to call more often than the configured frequency — it answers "not due
yet" until an interval has actually elapsed — so an hourly cron with Daily selected still
produces exactly one backup a day. `&force=1` bypasses the check. A "Run backup now" button
lets you confirm the setup without waiting for the next window.

### Added — custom notification system

Every `alert()`, `confirm()`, and `prompt()` is gone (19 call sites). Native dialogs block the
JS thread — freezing any in-flight progress modal — cannot be styled or translated, and are
suppressed outright by Chrome inside cross-origin iframes, which silently swallowed
confirmations for anyone embedding wp-admin.

Replaced with toasts (four variants, hover-pausable auto-dismiss) and modal dialogs with focus
trapping, Escape-to-cancel, focus restore, and safe-button default focus on destructive
actions.

### Fixed — progress modal printed the current step twice

The step label was rendered inside the progress bar *and* again in a separate line below it,
so every export read "Copying plugins..." on two consecutive lines.

### Fixed — progress bar sat at 100% for the whole Google Drive upload

The Drive upload runs inside the `finalize` step, which was declared as 100% — so the bar
filled completely and froze there for what is usually the slowest part of the export. When a
backup is bound for Drive, local work now occupies the first 60% and the upload owns the
remaining 40%, filled from the real byte progress the server already records. The label shows
the live percentage, and the bar never moves backwards when Drive re-reports a retried chunk.

### Fixed — assorted

- Two CSS custom properties (`--ss-primary-rgb`, `--ss-text`) were used but never defined, so
  several rules silently fell back to transparent/inherit. `.ss-modal-open` was referenced by
  JS but had no CSS rule, so modal scroll-lock never worked.
- Closing the progress modal released the scroll lock unconditionally, unlocking the page
  while the restore-complete modal was still open.
- Retention was stored without clamping; a crafted request could set it to 0 and delete every
  backup on the next scheduled run.
- The Drive upload status poller kept running after the upload finished.
- Uninstall and deactivation only cleared the old argument-less cron event, stranding the
  per-frequency ones.
- `tools/build-icons.php` read codepoints from a stylesheet its own final step deletes, so a
  second run reported every icon as missing and wrote an empty stylesheet.

---

## [1.1.11] — 2026-08-29

Fixes the post-restore finalisation step, which did neither of the two things its modal
promised. See [release-notes-v1.1.11.md](release-notes-v1.1.11.md).

### Fixed — "Finish & log out" never actually logged you out

The old code cleared WordPress's auth cookies from JavaScript, assuming the pre-restore
cookie would no longer validate against the restored DB. Both halves were wrong: WP sets
those cookies **HttpOnly**, so `document.cookie` cannot touch them, and the cookie only stops
validating when the backup carries different auth salts — restoring a backup of the same site
keeps the salts, so the session stayed valid and the user was never logged out.

The logout is now forced server-side via `reauth=1`, which makes `wp-login.php` call
`wp_clear_auth_cookie()` and show the prompt regardless of session state.

### Fixed — the flow landed on the Dashboard instead of Settings → Permalinks

`wp-login.php` passes `redirect_to` through `wp_validate_redirect()`, which discards any URL
whose host is not in `allowed_redirect_hosts` and falls back to `admin_url()`. The finalize
URL was absolute, built from the restored `siteurl`, so any host difference (`127.0.0.1` vs
`localhost`, `www` vs apex, a staging alias, or a genuine migration to a new domain) got it
rejected — and the rewrite-rules flush the modal asked for never happened.

The login URL and redirect target are now root-relative, leaving no host to disagree about.

Verified end-to-end over real HTTP on WP 7.1 from a still-valid session: logout prompt shown,
login lands on the permalinks page with the token, the two saves decrement correctly, and the
"Restore complete" notice appears. Test suites 52/52.

---

## [1.1.10] — 2026-08-29

Patch release for a single high-impact bug: **restoring a backup from Google Drive hung and
timed out** with zero bytes received, on any backup large enough that Drive declined to scan
it — which is most full-site backups. See
[release-notes-v1.1.10.md](release-notes-v1.1.10.md).

### Fixed — Google Drive download stalled forever, then timed out

Google flags any Drive file its malware scanner cannot process, and large archives always
qualify. For a flagged file the v3 API does not return an error for `alt=media`: it accepts
the request and then never sends a response body, so cURL waits out its own timeout and
reports `Operation timed out ... with 0 bytes received`. Measured on a live 74.78 MB backup:
0 bytes after 7+ minutes without the flag, versus the full 78,417,557 bytes in 1.44s with it.

Downloads now send `acknowledgeAbuse=true` (a no-op for unflagged files, so it is sent
unconditionally) plus `supportsAllDrives=true` so backups in a shared drive resolve too.

### Added — stall guard and clearer timeout error

The transfer aborts if it delivers under 1 byte/s for 30 seconds straight, instead of idling
for the full 300s timeout and letting PHP-FPM kill the request with no message at all.
Timeout errors are rewritten to point at the server's outbound connection to `googleapis.com`.

### Added — truncated downloads rejected at download time

The bytes received are checked against the size reported in Drive's file metadata. A short
read now fails immediately with both sizes named, rather than being written to disk and
surfacing later as a confusing corrupt-archive error during restore.

---

## [1.1.9] — 2026-08-29

Full correctness audit against **WordPress 7.1** on **PHP 8.3 and 8.5**, driven by a live
test site. Every item below was reproduced before it was fixed, and re-verified after.
See [release-notes-v1.1.9.md](release-notes-v1.1.9.md) for the detailed write-up.

### Fixed — Serialized data corrupted on restore (critical)

The importer treated a serialized string's declared length (`s:19:"..."`) as a count of raw
bytes in the SQL file. The exporter escapes backslash, NUL, newline, CR, ^Z and apostrophe,
so the literal is longer than the declared length: the parse landed mid-token and failed, and
the walker fell back to plain-text replacement — changing the payload length **without**
updating the `s:N:` prefix. `unserialize()` then rejected the row.

This is why page-builder layouts, widget settings, and plugin options came back empty after a
migration whenever a value happened to contain a quote, a backslash, or a newline. The parser
now decodes escapes while counting logical bytes, and re-escapes on output.

### Fixed — Nested serialized data corrupted on restore (critical)

WordPress stores an already-serialized string by serializing it a second time
(`maybe_serialize`). The walker fixed only the outer length prefix and left the inner one
stale, so the outer value unserialized and the inner one did not. The walker is now
recursive, depth-bounded at 8.

### Fixed — SQL tokenizer split statements incorrectly (critical)

The streaming tokenizer only understood single quotes. It did not recognise `--`, `#` or
`/* */` comments (a `;` inside one split the statement), double-quoted strings, backtick
identifiers, `DELIMITER` directives (triggers and procedures were shredded), or mysqldump
conditional comments `/*!40101 ... */` (charset and sql_mode setup was silently dropped).
All are now handled, including when the construct straddles a 64 KB read boundary.

### Fixed — Backups contained duplicated and missing rows (critical)

Export paginated each table with `LIMIT/OFFSET` and **no `ORDER BY`**. Without an ordering,
MySQL may return rows in any sequence, and `OFFSET` counts positions in that unspecified
sequence. WordPress writes to `wp_options` continuously — including this plugin's own
progress transient after every export step — so rows shift between pages mid-dump. A row
that moves across a page boundary is written to the dump **twice**, and the row that took
its place is **never written at all**.

Measured on a stock WordPress 7.1 install: 142 `INSERT` statements covering only 120
distinct `option_id` values — 22 rows duplicated and 22 rows silently lost from the backup.
On restore the duplicates fail with `Duplicate entry for key PRIMARY`, and the missing rows
are simply gone.

Pagination now orders by the table's single-column primary key (falling back to a unique
NOT NULL column, then to the previous behaviour when neither exists).
### Fixed — Rows lost from tables with generated columns (critical)

Export emitted `INSERT` statements listing `STORED`/`VIRTUAL` generated columns. MySQL
rejects those outright, so **every row** of such a table was dropped on restore. WooCommerce
lookup tables and several analytics plugins use generated columns. They are now omitted from
the column list.

### Fixed — Google Drive download could destroy a local backup (critical)

`wp_remote_get(..., ['stream' => true])` writes the response body to disk regardless of HTTP
status, and the destination was the final backup path. A 404 or 401 wrote a JSON error blob
as a `.zip`, reported success, and overwrote an existing backup of the same name. Downloads
now stage into the temp directory, verify the status code and ZIP magic bytes, and publish
under a non-clashing name.

### Fixed — ZIP write failures ignored (critical)

`ZipArchive::addFile()` and `close()` return values were discarded, so a backup missing files
still reported success and the user only found out during a restore. Both are now checked,
failures are logged, and the archive is verified non-empty before the export completes.

### Security — `finalize_restore` leaked the admin finalize token

The endpoint had no authorisation check and returned a URL embedding the one-time token that
authorises restore finalisation (activating the backup's plugin set, switching its theme).
Verified with a subscriber session: any logged-in user could read it. It now requires either
a `manage_options` session or possession of the token itself. The token path is kept
deliberately, because after a restore the browser's cookie was minted against the pre-restore
database and the legitimate user can fail a capability check through no fault of their own.

### Fixed — PHP 8.4+ deprecation in the Google Drive client

`ensure_folder_exists(string $token = null)` is an implicitly-nullable parameter, deprecated
in PHP 8.4. On an AJAX endpoint the resulting notice can corrupt the JSON body. Now `?string`.

### Changed — Icons bundled locally, CDN dependency removed

The admin UI loaded RemixIcon from jsdelivr, which breaks on offline and intranet installs,
behind strict CSPs, and violates the WordPress.org guideline against loading external
resources. The 41 glyphs actually used are now bundled: **123 KB of CSS reduced to 3 KB**,
with the font served from the plugin directory. Regenerate with `php tools/build-icons.php`.

### Fixed — Google Drive reliability

- **Missing timeouts.** The duplicate-file lookup, folder lookup/creation, and file listing
  used WordPress's 5-second default. A slow response silently skipped de-duplication, so every
  scheduled backup added another copy to Drive. All calls now set explicit timeouts.
- **Infinite upload loop.** A session repeatedly answering `308` without advancing spun
  forever. Now bounded at 5 stalled rounds.
- **Chunk size could exhaust memory.** The 5 MB chunk is held in memory and copied by the HTTP
  layer, which could fatal mid-upload on a small `memory_limit`. It now scales to the available
  budget, never below Google's 256 KiB minimum and always on a 256 KiB boundary.
- **Listing errors looked like "no backups".** A non-2xx response returned an empty list with
  no error, so an expired token presented as an empty Drive tab. The error is now surfaced.

### Fixed — Plugin polluted its own backups

Export-state transients were dumped into every archive and restored onto the target,
resurrecting a phantom "export in progress". They are now excluded from the dump.

### Added — Regression test suites

Two standalone suites (no WordPress, no database) covering every defect above:

- `tests/test-sql-tokenizer.php` — 35 cases: tokenizer constructs, chunk-boundary splits, and
  full export → rewrite → `unserialize()` fidelity for escape-hazard payloads.
- `tests/test-export-pagination.php` — 4 cases: proves paged export emits every row exactly
  once when the underlying row order shifts mid-dump.
- `tests/test-core-standalone.php` — 13 cases: import, archive create/extract, zip-slip
  rejection, and replacement-map edges.

All pass (35/35, 4/4, 13/13) on PHP 8.3 and PHP 8.5, with zero deprecations raised from plugin code.

---
## [1.1.8] — 2026-04-20

### Fixed — "Plugin generated N characters of unexpected output during activation"

When a user had two copies of SitesSaver installed (e.g. `plugins/sitessaver/` + `plugins/ss/`) and activated both, WordPress loaded them sequentially and the second copy re-`define()`d seven constants. Each triggered a PHP warning that got echoed during activation, leaving the user with:

> *"The plugin generated 1154 characters of unexpected output during activation. If you notice headers already sent messages, problems with syndication feeds or other issues, try deactivating or removing this plugin."*

Worse, the same redeclaration warnings could corrupt AJAX/REST response bodies.

**Fix:** the bootstrap in [sitessaver.php](sitessaver.php) now checks `defined('SITESSAVER_VERSION')` before defining anything. If another copy is already loaded, the second copy:

1. Silently returns (no redeclaration, no warning text).
2. Registers an `admin_init` handler that detects all active plugins with `Name: SitesSaver`, keeps only the newest version, auto-deactivates the rest, and surfaces an admin notice pointing to **Plugins** for cleanup.

Identity is matched by the `SITESSAVER_VERSION` constant (and the `Plugin Name: SitesSaver` header), not by folder slug — so the guard works regardless of whether the user renamed a copy to `ss/`, `sitessaver-backup/`, or anything else.

### Fixed — Hardcoded URLs in plugin/theme source files

Restoring a backup to a different domain left images broken on pages rendered by **custom plugins** (and some themes) that hardcoded the source site's `http://old-domain/...` path inside PHP arrays, JSON configs, or CSS `background-image:` declarations. v1.1.7 rewrote URLs in the database only — when the rendering code lives in a PHP file, the DB replacement never reaches it, and the page emits `<img src="http://old-domain/...">` on the new host.

Real-world reproduction: a custom plugin at `wp-content/plugins/felda-travel/modules/umrah-redesign/data.php` hardcoded 13 image URLs. After restore to an HTTPS destination, all 13 rendered as dead links even though the database was fully rewritten.

### Added — File-content URL rewriter during restore

`Import::restore_content()` now passes source/destination URLs into `merge_directory()`, which calls a new `copy_with_url_rewrite()` helper for each file under `wp-content/plugins/`, `wp-content/themes/`, and `wp-content/mu-plugins/`. The same `Database::build_replacement_pairs()` map is used, so file-embedded URLs get the same cross-scheme/escape-variant coverage the DB layer gets.

Guardrails to prevent corrupting third-party code:

- **Extension allowlist.** Only text formats are touched: `php`, `html`, `css`, `scss`, `js`, `mjs`, `json`, `xml`, `svg`, `txt`, `md`, `yml`, `ini`, `conf`, `po`. Binary assets (images, fonts, archives) get a byte-identical `copy()`.
- **Tree skip list.** `vendor/`, `node_modules/`, `.git/`, `composer.lock`, `package-lock.json`, `yarn.lock` are never rewritten — third-party dependencies don't hardcode the site URL and mis-rewriting a dependency lock breaks installs.
- **Size ceiling.** Files over 5 MB are passed through unchanged (minified bundles: too costly, low signal; user-authored hardcoded URLs live in small config/data files).
- **Uploads untouched.** `wp-content/uploads/` keeps the byte-identical copy path — binary image/PDF/SVG content may contain URL-like byte sequences where substitution would corrupt the file.
- **Fast-path.** Files that don't contain any source pattern skip the rewrite entirely — normal `copy()` is used. Minimal overhead on large plugin trees that don't reference the source domain.

### Test coverage

`storage/ss_file_rewrite_test.php` — 9-case harness covering: the exact felda-travel `data.php` reproduction, JSON with escaped slashes, CSS `background-image`, JS protocol-relative strings, binary WebP skip, clean-file byte-identical copy, `vendor/` skip, `composer.lock` skip, and >5 MB size-ceiling skip. All pass.

---

## [1.1.7] — 2026-04-19

### Added — Migration coverage hardening (scope: WordPress enthusiast tier)

After the feldatravel-specific cross-scheme fix, the replacer was reviewed for other real-world migration patterns that would silently break the same way:

- **www ↔ non-www handling.** When migrating between `https://www.example.com` and `https://example.com`, the replacer now builds BOTH the configured pair AND the opposite-www variant. Content that legitimately contained both forms pre-migration (common after previous moves) gets unified to the destination's canonical host.
- **`localhost` / short-host safety guard.** `build_replacement_pairs()` now refuses to emit a bare-host pattern when either URL's host contains no dot (e.g. `http://localhost:8080`). Without this, substring-matching `localhost` would mangle unrelated prose and script variable names. Scheme-explicit variants are still emitted, so local dev migrations still work.
- **Idempotency short-circuit.** `build_replacement_pairs()` returns an empty map when `$old_url === $new_url`, skipping the entire byte-walker pass. Running the import twice on an already-migrated dump is now a guaranteed no-op.
- **Builder CSS cache flush on restore.** `Import::flush_builder_caches()` runs at the tail of `restore_content()` and wipes compiled CSS from Breakdance, Elementor, Oxygen, Bricks, plus full-page caches under `wp-content/cache/` and `wp-content/litespeed/`. Builders embed absolute URLs in their compiled stylesheets — without flushing, a cross-domain restore leaves the old domain's URLs in generated CSS even when the DB is correct. Each builder regenerates on first front-end page view.

Verified against an 11-scenario test harness (16 assertions, all green) covering: plain domain change, cross-scheme, both www directions, protocol-relative, Breakdance JSON-in-JSON, serialized PHP metadata with byte-length delta, mixed http/https in one row, short-pattern safety, idempotency, and www fallback emission.

### Fixed (cross-scheme migration — root cause of "WebP broken after restore")

- **URL replacement now handles `https://` ↔ `http://` scheme changes correctly.** Previously the replacer built only two patterns — `https://old.com` and `old.com` (bare host) — and kept the destination URL's scheme only for the full-URL form. When migrating a site from `https://source.com` to `http://dest.com` (common when moving production → local dev like Laragon which doesn't serve HTTPS by default), any occurrence of `http://source.com` in the backup stayed as-is, and any scheme-mismatched occurrence was left unreplaced. Breakdance stores its entire tree as JSON-in-postmeta with URLs like `https:\/\/source.com\/wp-content\/uploads\/hero.webp` — these rewrote only the domain and kept the `https:` prefix, so the destination Apache (serving HTTP only) 404'd every image. The pattern was invisible because JPG images on the page happened to use different URL sources and rendered fine, making it look WebP-specific. It wasn't — it was every image referenced via the `https://` form on an HTTP-only destination.
- **New `Database::build_replacement_pairs()`** constructs the full cross-product: `https://old → new`, `http://old → new`, JSON-escaped `\/` variants of both, protocol-relative `//old → //new`, and bare-host fallback. All mapped to the destination's canonical URL. Replacement runs via `strtr(array_combine(...))` — same one-shot technique All-in-One WP Migration uses.
- **`rewrite_serialized_preserving()` now takes the pair map as its sole replacement input.** Serialized-string byte-length prefixes are recomputed correctly whether the replacement shortens or lengthens the content (https→http is a 1-byte delta × N occurrences).

### Fixed (WebP images broken after restore)

- **`wp-content/uploads/.htaccess` now registers `image/webp` and `image/avif` MIME types.** On stock Apache / Laragon / older mime.types, `.webp` files are served with `application/octet-stream`, so browsers refuse to render them — JPG and PNG work because they've always been in the default MIME map, but WebP variants (common when sites use Imagify, ShortPixel, EWWW, WebP Converter for Media, or Breakdance's image optimizer) show as broken images. A minimal `AddType` block is now written idempotently into `uploads/.htaccess` at the end of restore.
- **`upload_mimes` + `wp_check_filetype_and_ext` filters now re-register webp/avif at runtime.** Some security plugins and hosting stacks strip `image/webp` from `upload_mimes`. After a restore, `_wp_attachment_metadata` rows reference WebP attachments by ID, but `wp_get_attachment_url()` short-circuits to empty when the MIME isn't in the allow list — another pathway to "broken image" on display. The plugin now force-registers these MIMEs at priority 99.
- **Silent `@copy()` failures eliminated.** `merge_directory()` previously used `@copy()` which swallowed errors. On Windows (Laragon), long paths (>260 chars) and unusual UTF-8 byte sequences cause `copy()` to return false — particularly for WebP variants from image-optimizer plugins that nest deep paths like `cache/breakdance-image-optimizer/2024/01/long-name.jpg.webp`. Copy failures are now counted and logged to `debug.log` with the full path, so broken restores are visible instead of invisible.

### Fixed (CRITICAL — the "fixes keep reverting" incident)

- **Restore no longer overwrites the currently-running SitesSaver plugin with older files from the backup.** This was the root cause behind the `1.1.5` / `1.1.6` flow "keep failing after I deploy". Every restore was silently reverting the plugin to whatever version was in the backup ZIP, so any browser-side fix shipped in a later build got clobbered the instant the user ran a restore.
  - `Export::copy_directory()` exclusion used `['sitessaver']` as an `fnmatch` pattern. `fnmatch('sitessaver', 'sitessaver/foo.php')` returns `false` — it's not a prefix match. So the plugin's own directory WAS being bundled into every backup despite the exclude intent.
  - `Import::merge_directory()` had NO skip list at all — it copied every file from the backup unconditionally, including `sitessaver/` if present in the archive.
- **Two-layer guard now in place.** Export: exclude list extended to `['sitessaver', 'sitessaver/*']` AND `copy_directory()` now does explicit first-path-segment and prefix matching instead of relying on bare `fnmatch`. Import: `merge_directory()` accepts a `skip_roots` list; `restore_content()` passes the running plugin's folder name so any legacy backup ZIPs (produced before this fix) still can't overwrite the live plugin.
- **`handle_gdrive_restore` was missing `finalize_url` in its response.** Google Drive restores never received the server-built login URL, so the modal's "Finish & log out" button fell back to `/wp-login.php` without the finalize token — deferred activation silently never ran. Added alongside the existing `finalize_token` return.
- **Post-restore `siteurl` / `home` read from stale object cache.** `build_finalize_redirect_url()` now explicitly busts the `alloptions` / `siteurl` / `home` cache keys before calling `wp_login_url()`, so the redirect target carries the RESTORED domain, not the pre-restore one.
- **`run_deferred_finalisation()` flushed the object cache AFTER reading the pending-finalize option.** On sites with Redis / Memcached / WP Super Cache the read was served from the pre-restore cache and the deferred work silently returned. Flush now happens first.
- **Best-effort `opcache_reset()` after `restore_content()`.** Prevents the PHP opcode cache from continuing to execute pre-overwrite bytecode for restored PHP files until the next PHP-FPM restart.

### Rollback

If 1.1.7 misbehaves in production, downgrade by reinstalling 1.1.6. Backups created by 1.1.6 are forward-compatible with 1.1.7. Backups created by 1.1.7 (which NO LONGER contain a `sitessaver/` folder) are also backward-compatible with 1.1.6 because 1.1.6's restore loop tolerates missing subdirs.

---

## [1.1.6] — 2026-04-19

### Changed (ARCHITECTURAL)
- **Restore finalisation is now driven entirely by a client-side redirect to `wp-login.php`, not by an AJAX call.** The previous flow (AJAX → run deferred work → `wp_logout()` → return redirect JSON) was fragile in at least three independent ways — all of which produced the same user-visible "An error occurred" popup with no diagnostic: (a) the browser's auth cookie was signed by the PRE-restore DB, so `check_ajax_referer()` and `current_user_can()` silently failed; (b) `wp_logout()` fires the `wp_logout` action hook and third-party plugins (security, membership, SSO) often `wp_redirect() + exit` inside it, killing the request before the JSON body flushed; (c) middleware like Cloudflare sometimes wrapped slow AJAX responses in HTML interstitials that broke the client-side JSON parse. The architecture now sidesteps all three: `post_import()` mints a one-time token and `Import::build_finalize_redirect_url()` returns `wp-login.php?redirect_to=<permalinks?sitessaver_finalize=TOKEN>`. The JS clears WP cookies client-side and navigates straight there. The user re-auths with the restored credentials and lands on Permalinks where `admin_init` validates the token (`hash_equals`) and runs the deferred activation/theme-switch under a clean, freshly-authenticated session.
- `handle_finalize_restore` AJAX endpoint remains registered as a backward-compat stub — any browser tab still running cached 1.1.5 JS gets the new redirect URL in the response and navigates through the same flow.
- `run_deferred_finalisation()` now wraps `switch_theme()` in `try/catch`. A restored theme with a fatal in `after_switch_theme`/`setup_theme` no longer 500's the permalinks page; the error is logged and finalisation continues so the user sees the banner and can proceed.
- **Output buffer added to `handle_finalize_restore`.** Parity with the 1.1.4 fix applied to `handle_import`. Stray PHP notices emitted by `switch_theme()` or plugin boot during `run_deferred_finalisation()` can no longer corrupt the JSON response.
- **Post-migration login URL now points to the new domain.** The redirect URL is computed from the restored `siteurl`/`home_url`, so users migrating between domains are sent to the correct login page after logout.
- **`admin_init` no longer consumes the pending-finalize option prematurely.** Previously `run_deferred_finalisation()` was invoked on every admin page load, meaning any admin navigation between restore-complete and the modal's "Finish & log out" click would activate plugins and switch theme in the wrong request context (the exact scenario the 1.1.3 refactor was built to prevent). It's now gated to `options-permalink.php?sitessaver_finalize=1` — the intended consumption point after the logout/login round-trip.
- **Breakdance / page-builder images no longer break after migration.** The serialize-aware URL replacer only rewrote the plain `https://old.com` form. Page builders that store their tree as JSON inside a serialized postmeta row (Breakdance `_breakdance_data`, Elementor, Oxygen, Bricks) write URLs in JSON-escaped form — `https:\/\/old.com\/wp-content\/uploads\/...` — which never matched the plain replacement. Image `src` attributes kept pointing at the source domain and showed as broken on the destination host. The replacer now recognises both `\/`-escaped and plain variants, with serialized-string byte-length prefixes recomputed correctly for either form.

### Changed
- `Database::rewrite_serialized_preserving()` signature extended with optional JSON-escaped URL variants. Older call-sites that pass only four URL arguments continue to work.
- `Database::json_slash_encode()` helper added — single source of truth for the `\/`-escape transform.

---

## [1.1.4] — 2026-04-15

### Fixed
- **Restore no longer fails with "An error occurred" on sites with chatty plugins.** The import AJAX handlers now open their own output buffer so stray PHP notices (e.g. WP 6.7's "textdomain loaded too early" from Elementor / Landinghub) can't corrupt the JSON response. Any captured noise is written to `debug.log` instead of reaching the browser.
- **Restore actually drops existing tables now.** The SQL tokenizer was skipping any statement whose leading characters were `--` or `/*`, which meant the `-- Table: foo\nDROP TABLE IF EXISTS foo;` block produced by Export was silently discarded. The subsequent `CREATE TABLE` then failed with "Table already exists" and every `INSERT` failed with a duplicate-primary-key error, leaving the target site in a half-restored state. Leading comment lines are now stripped before the empty-statement check, so the DROP runs as intended.

### Changed
- `Database::execute_statement()` now delegates to a small `strip_leading_comments()` helper so the rule is explicit and auditable. Trailing/inline comments are left untouched — MySQL handles those on its own.

---

## [1.1.3] — 2026-04-15

### Fixed
- **No more "textdomain loaded too early" notices after restore.** Elementor, Landinghub, and any plugin that declares a textdomain was firing the WP 6.7 warning because we were activating the restored plugin set and calling `switch_theme()` mid-AJAX, after `init` had already fired. The restored plugins would boot inside the wrong request and call `load_plugin_textdomain()` too early.

### Changed
- **Two-step restore finalisation (All-in-One WP Migration style).**
  - `post_import()` no longer touches `active_plugins`, `switch_theme()`, `wp_cache_flush()`, or `flush_rewrite_rules()` during the AJAX restore. Only transients are cleared inline.
  - A new modal appears after a successful restore explaining the three steps: log out, log in, save Permalinks twice.
  - A new `sitessaver_finalize_restore` AJAX endpoint runs the deferred activation, logs the user out, and returns a login URL that redirects to Settings > Permalinks after re-auth.
  - On the Permalinks page the plugin shows a banner and counts two saves before declaring the restore complete — this is what flushes rewrite rules cleanly.
- Works for all three restore paths: upload, restore-from-local-backup, and restore-from-Google-Drive.

---

## [1.1.2] — 2026-04-15

### Fixed
- **Import no longer rejects legitimate backups** — the object-marker guard (`O:`/`C:` in serialized data) was throwing on every real-world WordPress dump, because widgets, cron events, transients and many plugin options legitimately serialize `stdClass` and other objects. Import is already gated by `manage_options` + nonce + manifest signature, which puts it firmly inside the trusted-admin boundary, so the hard reject was producing false positives. The check is now audit-only: it writes a single notice to `error_log` when object markers are seen and lets the restore proceed.

---

## [1.1.1] — 2026-04-15

Critical data-integrity fix for restore-with-URL-change.

### Fixed
- **Serialized data no longer corrupted on restore** — SQL dumps produced by `Database::export()` escaped double-quotes with `mysqli_real_escape_string`, but the import-time URL-replacement walker was looking for unescaped `s:N:"..."` tokens. When the target site URL differed from the source, serialized options (widgets, theme mods, plugin settings) fell through to a plain `str_replace` path that rewrote the URL without updating the byte-length prefix — producing length-mismatched serialized data that WordPress silently read back as empty. After restore, "lots of data missing" was the user-visible symptom.
- **Export escaping rewritten** — new dumps use a custom escaper that mirrors `mysqli_real_escape_string` except it does not escape `"` (single-quoted SQL literals don't require it), which keeps serialized-string markers intact for the walker.
- **Walker now accepts both token forms** — `s:N:"..."` (new dumps) and `s:N:\"...\"` (legacy v1.1.0 dumps), so existing backups also restore cleanly with URL replacement applied.
- **`assert_no_serialized_objects()` detects both forms** — the object-injection guard no longer bypasses legacy-escaped dumps.
- **INSERT failures no longer silent** — `execute_statement()` now logs `$wpdb->last_error` to the error log when a statement fails, so future row-level issues surface instead of appearing as "data missing".

---

## [1.1.0] — 2026-04-14

Major security + reliability release. Upgrade recommended for all installations.

### Added
- **Restore from Google Drive** — one-click restore button on Drive backups list; downloads the archive and restores the site in a single flow.
- **Animated progress bar** — scrolling zebra stripes so users can see activity even during slow long-running operations (respects `prefers-reduced-motion`).
- **Stricter import validation** — uploaded ZIPs now verified by magic bytes (`PK\x03\x04` / `PK\x05\x06`) before being staged, and the manifest is rejected unless it identifies as a SitesSaver export (`plugin === 'SitesSaver'` + `version` + `site_url`). Random ZIPs no longer litter the Backups directory.
- **`uninstall.php`** — revokes the Google Drive refresh token via the proxy, clears cron, and deletes every `sitessaver_*` option + transient. Backup files on disk are left intact.
- **Activation guards** — plugin refuses to activate without PHP 8.1+ and the `ZipArchive` extension; the user sees a friendly `wp_die()` message instead of a silent failure at runtime.
- **WP-Cron health indicators** — Schedule page now shows "Scheduled backup missed" warning if the last run is older than 2× the interval, a blue notice when `DISABLE_WP_CRON` is defined, and a "Next run in X" badge when a run is queued.
- **Dual-syntax `.htaccess`** — backup directory now protected by both Apache 2.4 (`Require all denied`) and 2.2 (`Deny from all`) directives.

### Changed / Improved
- **Streaming SQL import** — `Database::import()` is now a streaming tokenizer (`fread` 64 KB chunks) with constant memory per statement. Multi-GB database dumps no longer OOM the process.
- **ZIP64 support** — removed the 2 GB file-size skip in archive creation; individual files larger than 2 GB are now backed up correctly on PHP 8.1+.
- **Resumable Google Drive upload** — transient server errors (408/429/5xx) trigger up to 3 retries with exponential backoff (1 s / 2 s / 4 s). On network drops, the upload session is probed via `Content-Range: bytes */total` and resumes from the last confirmed byte — a 10 GB upload that fails at chunk 47/50 no longer restarts from zero.
- **Export state → transients** — export progress is stored with a 1-hour TTL instead of persistent `wp_options` rows, eliminating orphan option-table bloat from abandoned exports.
- **Autoload audit** — `sitessaver_gdrive_token`, `_schedule_log`, `_backup_labels`, `_settings`, `_schedule` now all stored with `autoload = no`; a one-time migration flips existing rows. The Google Drive refresh token no longer rides in `alloptions` on every request.
- **N+1 fixes** — `sitessaver_get_backups()` and retention cleanup now read the labels option once, not per entry. `filesize()` / `filemtime()` cached per row.
- **Translations wired** — `load_plugin_textdomain()` is now called on `init`; all existing `__()` strings become translatable (add a `/languages/` directory with `.mo` files to translate).
- **Theme re-activation safeguard** — during import, `switch_theme()` is only called after `wp_get_theme()->exists()` confirms the theme is present on the target install. Prevents corrupted `stylesheet`/`template` options on migration to installs that don't have the same theme.
- **Scoped temp cleanup** — `sitessaver_cleanup_temp()` now stale-only by default (6-hour cutoff) and accepts an optional scoped directory argument. A concurrent manual export and scheduled cron can no longer nuke each other's temp files.
- **Admin loader guard** — `Admin::init()` only registers hooks when `is_admin()` is true; no admin overhead on frontend page loads.

### Security (CRITICAL / HIGH)
- **Object-injection blocked (CWE-502)** — `Database::replace_urls()` now rejects any imported SQL dump that contains serialized PHP object markers (`O:` / `C:`). Serialized string-length prefixes are recomputed with `strlen()` byte-count and only for strings that actually changed during URL replacement, closing a gadget-chain RCE vector on restore.
- **OAuth CSRF protection (CWE-352)** — Google Drive connection flow now generates a single-use 32-char `state` token (per-user transient, 10-minute TTL) and verifies it on callback with `hash_equals`. Requires proxy relay to pass `state` through — confirmed compatible.
- **Zip-slip fail-closed** — `Archive::extract()` now aborts on any traversal entry (`..`, absolute path, realpath containment mismatch) and on entries with a symlink external attribute (`S_IFLNK`). Previously such entries were silently skipped while the rest of the archive extracted.
- **Google Drive query injection (CWE-74)** — single quotes in folder IDs and filenames are now escaped per Drive v3 `q=` query syntax before interpolation.
- **Refresh-token hardening** — GDrive refresh token stored with `autoload = no`, never loaded into every-request `alloptions`.
- **`SHOW TABLES LIKE` parameterised** — table dump query now uses `$wpdb->prepare()` with `esc_like()`; prefix wildcards can no longer accidentally match neighbouring installations sharing the database.
- **Centralised backup path resolver** — `sitessaver_resolve_backup_path()` enforces sanitisation + realpath containment at every handler that operates on backup files (delete, download, upload to Drive).

### Upgrade Notes
- **Existing Google Drive connections keep working** — the `state` param is only required on new connections made after this release. No action needed for already-connected sites.
- **Restore flow change** — manually uploaded ZIPs that aren't SitesSaver exports will now be rejected with a clear error message instead of partially extracting.
- **Options table will shrink** — the one-time autoload migration flips legacy rows to `autoload = no`, which reduces memory pressure on every page load. No user action required.

### Compatibility
- No breaking API changes.
- Tested on PHP 8.1 / 8.3 and WordPress 6.0+.

---

## [1.0.9] — 2026-04-14

- Added help manual tab and auto-hide Google Drive progress bar.

## [1.0.8] — Earlier

- Full security audit, performance optimisation, fix Google Drive progress bar.

## [1.0.7] — Earlier

- Auto-create Google Drive folder on connect, storage options on Schedule page, upload bug fix.
