# SitesSaver v1.1.7

**Release date:** 2026-04-19
**Type:** Critical bugfix — resolves the "restore finalisation keeps failing" incident

**Install v1.1.7 on the import site BEFORE restoring any backup.** v1.1.5 and v1.1.6 produced backups that silently bundled the plugin's own folder; restoring one of those on top of v1.1.7 would re-introduce the bug v1.1.7 is designed to stop.

---

## The one-line summary

Every restore was silently overwriting the currently-running SitesSaver plugin with the backup's (older) copy of itself. That's why every browser-side fix shipped in v1.1.5 / v1.1.6 "didn't work" — the moment the user ran a restore, those fixes vanished.

---

## What actually went wrong

### The export-side bug

`Export::copy_directory()` used `fnmatch` to check exclusions. The exclude list contained `'sitessaver'` — this matches the literal entry `"sitessaver"` only, not the subtree `sitessaver/anything.php`. So:

- `fnmatch('sitessaver', 'sitessaver')` — `true` (excludes the bare directory entry)
- `fnmatch('sitessaver', 'sitessaver/assets/js/admin.js')` — `false` (file gets copied into the ZIP)

Every backup taken since the plugin shipped contained a `wp-content/plugins/sitessaver/` subtree.

### The import-side bug

`Import::merge_directory()` had no skip list at all. Every file in the extracted ZIP was blindly copied into the live `wp-content/*`. Combined with the export bug, this meant:

1. User runs a restore on feldatravel.test
2. `Import::restore_content()` walks the ZIP's `plugins/` folder
3. Finds `plugins/sitessaver/**/*` (because export didn't really exclude it)
4. Overwrites live `wp-content/plugins/sitessaver/assets/js/admin.js` with the backup's copy
5. Backup was taken on a site running v1.1.5 → admin.js reverts to v1.1.5 behaviour
6. Browser still has v1.1.5 JS in memory anyway; next page load fetches the (reverted) admin.js and confirms the revert
7. User clicks "Finish & log out" → the reverted JS still has the broken AJAX call → alert appears

This is why the symptom never changed despite four separate bugfix attempts in v1.1.5 and v1.1.6. The fixes were correct; the restore simply removed them.

---

## What's fixed in v1.1.7

### Two-layer guard against plugin self-cannibalisation

**Export-side:** the exclude list is now `['sitessaver', 'sitessaver/*']` AND `Export::copy_directory()` uses explicit first-path-segment + string-prefix checks instead of relying on bare `fnmatch`. This correctly skips the entire `sitessaver/**` subtree on any OS.

**Import-side:** `Import::merge_directory()` now accepts a `skip_roots` argument. `Import::restore_content()` passes the running plugin's folder name, so even a legacy ZIP (created by v1.1.5 or v1.1.6 with the broken export) still cannot overwrite the live plugin on v1.1.7+. This is defence in depth against users restoring old backups onto a newly upgraded install.

### Google Drive restore finalize URL

`handle_gdrive_restore` was returning `finalize_token` but NOT `finalize_url` in its success response. The modal read `finalize_url` and, finding it missing, fell back to `/wp-login.php` with no token — so deferred activation silently never ran for Google Drive restore paths. Now fixed alongside the existing token return.

### Object cache correctness in finalize flow

- `Import::build_finalize_redirect_url()` now busts the `alloptions`, `siteurl`, and `home` cache keys before computing `wp_login_url()`. On sites that were migrated between domains this prevents the returned URL from pointing at the PRE-restore host.
- `Import::run_deferred_finalisation()` flushes the object cache BEFORE reading `sitessaver_pending_finalize`, not after. On sites with persistent object caches (Redis, Memcached, WP Super Cache) the read was previously served from the pre-restore cache.

### PHP opcache invalidation

`Import::restore_content()` now calls `opcache_reset()` (where available) after file copy. Prevents the PHP opcode cache from continuing to serve pre-overwrite bytecode for restored PHP files until the next PHP-FPM restart.

---

## Deploy order (CRITICAL — read before upgrading)

1. **Upgrade the destination/import site to 1.1.7 FIRST.**
2. **Re-export from the source site AFTER you've upgraded it to 1.1.7** (so the backup no longer contains the bundled `sitessaver/` folder).
3. Restore the newly-produced 1.1.7 backup on the destination.

If you have an existing 1.1.5 or 1.1.6 backup you're mid-migration with:
- Upgrading the destination to 1.1.7 before restoring is still correct — v1.1.7's import-side guard blocks the bundled `sitessaver/` from overwriting the live plugin even when restoring an older backup.

---

## Rollback plan

If v1.1.7 misbehaves:

1. In WordPress admin, upload the v1.1.6 ZIP via Plugins → Add New → Upload Plugin. WordPress will overwrite v1.1.7 with v1.1.6 on activation.
2. Backups produced by v1.1.6 or earlier remain restorable with either version.
3. Backups produced by v1.1.7 will NOT contain a `sitessaver/` subtree. v1.1.6's restore loop tolerates a missing plugin subdirectory (the loop only acts on directories actually present in the ZIP), so forward/backward compatibility is preserved.
4. If a restore leaves the site stuck with the "save permalinks twice" banner and you've already clicked save twice, clear the finalize state: `wp option delete sitessaver_pending_finalize` and `wp transient delete sitessaver_finalize_token` via WP-CLI, or delete those rows from `wp_options` directly via phpMyAdmin / Adminer.

---

## WebP images showing broken after restore

This release also addresses the "some images are broken after restore" symptom, which turned out to be WebP-specific. Three independent things contributed:

1. **Server MIME gap.** Default Apache / Laragon mime.types doesn't register `.webp` or `.avif`. The files exist after restore, but the server serves them as `application/octet-stream` and the browser refuses to render. JPG / PNG work because they've been in the default MIME map forever.
2. **WordPress `upload_mimes` pruning.** Security plugins commonly strip `image/webp`. Post-restore, `wp_get_attachment_url()` for WebP attachments short-circuits to empty → front-end sees an empty `src`.
3. **Silent `@copy()` failures on Windows.** Long paths (>260 chars) and unusual filenames silently failed to copy during `merge_directory()`. Image-optimizer plugins (Breakdance's included) nest WebP variants under long paths like `cache/breakdance-image-optimizer/2024/01/...` that easily exceed the limit.

### What's fixed

- `wp-content/uploads/.htaccess` now gets a SitesSaver-owned block that declares `AddType image/webp .webp` and `AddType image/avif .avif`. Idempotent — only added once, marker-delimited so editing or removing it later is straightforward.
- `upload_mimes` and `wp_check_filetype_and_ext` filters re-register `image/webp` and `image/avif` at priority 99 on every request.
- `merge_directory()` no longer suppresses copy errors. Any failure is logged to `debug.log` with the full source/destination path and path length, so the admin can see exactly what didn't restore.

## Files changed

- `includes/class-export.php` — `copy_directory()` exclusion logic + `plugins` step exclude list
- `includes/class-import.php` — `restore_content()`, `merge_directory()` (+ error logging), `run_deferred_finalisation()`, `build_finalize_redirect_url()`, `ensure_uploads_mime_htaccess()`
- `includes/class-ajax.php` — `handle_gdrive_restore`
- `includes/class-plugin.php` — `upload_mimes` + `wp_check_filetype_and_ext` filters for webp/avif
- `sitessaver.php` — version bump

No database schema changes. No user data touched.
