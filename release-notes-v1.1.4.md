## SitesSaver 1.1.4 — Restore Reliability Fix

**Upgrade strongly recommended.** Fixes two bugs that together could make a restore silently fail with a generic "An error occurred" message and leave the target site half-imported.

### Fixed
- **Restore actually drops existing tables now.** The SQL import tokenizer was skipping any statement whose leading characters were `--` or `/*`. In practice that meant every `-- Table: foo\nDROP TABLE IF EXISTS foo;` block produced by Export was being silently discarded. The follow-up `CREATE TABLE` then failed with *Table already exists*, every `INSERT` failed with a duplicate primary key, and the restore ended with the target site in a half-restored state (existing rows untouched, new rows rejected). Leading comment lines are now stripped before the empty-statement check, so the DROP runs as intended.
- **"An error occurred" on sites with chatty plugins is gone.** The import AJAX handlers now open their own output buffer so stray PHP notices (e.g. WP 6.7's "textdomain loaded too early" from Elementor / Landinghub / Rank Math / etc.) can no longer corrupt the JSON response. Captured noise is routed to `debug.log` so you can still diagnose it.

### Changed
- `Database::execute_statement()` now delegates to a small `strip_leading_comments()` helper so the rule is explicit and auditable. Trailing/inline comments are left untouched — MySQL handles those on its own.

### Compatibility
- No breaking API changes.
- Backups produced by any earlier SitesSaver version (1.0.x → 1.1.3) restore correctly under 1.1.4 — often for the first time, since the dropped-DROP bug affected every prior release.
- Tested on PHP 8.1 / 8.3 and WordPress 6.0+.
