## SitesSaver 1.1.2 — Import Guard Fix

**Upgrade strongly recommended if you hit "Import rejected: SQL dump contains serialized PHP objects" on v1.1.1.**

### Fixed
- **Import no longer rejects legitimate backups.** The `O:`/`C:` object-marker guard was throwing on every real WordPress dump — widgets, cron events, transients and many plugin options legitimately serialize `stdClass` and other objects. Import is already admin-gated (`manage_options` + nonce + manifest signature), so the hard reject was pure false-positive. The check is now audit-only: it logs a single notice to `error_log` when object markers are seen and lets the restore proceed.

### Compatibility
- No breaking API changes.
- Tested on PHP 8.1 / 8.3 and WordPress 6.0+.
