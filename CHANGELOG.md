# Changelog

All notable changes to **SitesSaver** will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
