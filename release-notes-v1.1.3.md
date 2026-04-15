## SitesSaver 1.1.3 — AIO-style Restore Finalisation

**Upgrade recommended.** Fixes the "textdomain loaded too early" notices users were seeing for Elementor / Landinghub / etc. after restore, and gives the restore flow the proper two-step finalisation used by All-in-One WP Migration.

### Fixed
- **No more `_load_textdomain_just_in_time was called incorrectly` notices after restore.** We were activating the restored plugin set and calling `switch_theme()` mid-AJAX, after `init` had already fired in the current request. Restored plugins booted in the wrong lifecycle and called `load_plugin_textdomain()` too early. Fixed by deferring all post-import writes to a fresh admin request.

### Changed
- **Two-step restore finalisation.**
  - After a successful restore, a modal appears walking the user through three steps: log out → log in → save Permalinks twice.
  - A new AJAX endpoint runs the deferred plugin/theme activation, logs the user out, and redirects to Settings > Permalinks after re-authentication.
  - On the Permalinks page a banner counts two `Save Changes` clicks before declaring the restore complete — that's what actually flushes rewrite rules cleanly.
- Works for all three restore paths: ZIP upload, restore-from-local-backup, and restore-from-Google-Drive.

### Compatibility
- No breaking API changes.
- Tested on PHP 8.1 / 8.3 and WordPress 6.0+.
