# SitesSaver 1.1.9 — Correctness & Compatibility Audit

A full audit against **WordPress 7.1** on **PHP 8.3** and **PHP 8.5**, driven by a
live test site rather than code reading. Every defect below was reproduced
first, then fixed, then re-verified against the same evidence.

The headline: **restores were silently corrupting data**. If any serialized
value in your database contained a backslash, an apostrophe, a newline, or a
double quote — which is normal for page builders, widgets, and plugin settings —
that row failed to unserialize after a migration and came back empty.

---

## Critical — data loss on restore

**1. Serialized values containing escaped characters were corrupted**

The importer read a serialized string's declared length (`s:19:"..."`) as a
count of *raw* bytes in the SQL file. But the exporter escapes backslash, NUL,
newline, CR, ^Z and apostrophe, so the literal is longer than the declared
length. The parse landed mid-token and failed, and the walker then fell back to
plain text replacement — changing the payload length **without** updating the
`s:N:` prefix. `unserialize()` rejected the row.

Symptom: page-builder layouts, widget settings, and plugin options coming back
blank after a migration. The parser now decodes escapes while counting logical
bytes, and re-escapes on output.

**2. Nested serialized data was corrupted**

WordPress stores an already-serialized string by serializing it a second time
(`maybe_serialize`). The walker fixed only the outer length prefix, leaving the
inner one stale. The walker is now recursive, depth-bounded at 8.

**3. SQL tokenizer split statements incorrectly**

The tokenizer only understood single quotes. It did not recognise:

| Construct | Consequence |
|---|---|
| `--`, `#`, `/* */` comments | a `;` inside a comment split the statement |
| double-quoted strings | `"a;b"` split mid-value |
| backtick identifiers | `` `we;ird` `` split mid-identifier |
| `DELIMITER` directives | triggers and procedures were shredded |
| `/*!40101 ... */` | mysqldump charset/sql_mode setup was silently dropped |

All are now handled, including when the construct straddles a 64 KB read
boundary. 20 tokenizer cases in `tests/test-sql-tokenizer.php`.

**4. Rows in tables with generated columns were lost**

The export emitted `INSERT` statements that included `STORED`/`VIRTUAL`
generated columns. MySQL rejects those outright, so **every row** of such a
table was dropped on restore. WooCommerce lookup tables and several analytics
plugins use generated columns. They are now excluded from the column list.

**5. Backups contained duplicated and missing rows**

Export paginated with `LIMIT/OFFSET` and no `ORDER BY`. MySQL is then free to return rows
in any sequence, and `OFFSET` counts positions in that sequence. WordPress writes to
`wp_options` constantly — including this plugin's own progress transient after every export
step — so rows shift between pages mid-dump. A row that crosses a page boundary is written
twice; the row that displaced it is never written.

Measured on a stock WP 7.1 install: **142 INSERT statements for 120 distinct option_ids** —
22 rows duplicated, 22 rows silently missing from the backup. On restore the duplicates
raise `Duplicate entry for key PRIMARY` and the missing rows are just gone.

Pagination now orders by the primary key.
**6. Google Drive downloads could destroy a local backup**

`wp_remote_get(..., ['stream' => true])` writes the response body to disk
regardless of HTTP status, and the destination was the final backup path. A 404
or 401 therefore (a) wrote a JSON error blob as a `.zip`, (b) reported success,
and (c) overwrote an existing backup of the same name. Downloads now stage into
the temp directory, verify the status code and ZIP magic bytes, and publish
under a non-clashing name.

**7. ZIP write failures were ignored**

`ZipArchive::addFile()` and `close()` return values were discarded, so a backup
missing files still reported success. The user would only discover it during a
restore. Both are now checked, failures are logged, and the archive is verified
to be non-empty before the export is called complete.

---

## Security

**8. `finalize_restore` leaked the admin finalize token**

The endpoint had no authorisation check at all and returned a URL embedding the
one-time token that authorises restore finalisation (activating the backup's
plugin set, switching its theme). Verified: a plain subscriber could read it.

It now requires either a `manage_options` session or possession of the token
itself. The token path is retained deliberately, because after a restore the
browser's cookie was minted against the pre-restore database and the legitimate
user can fail a capability check through no fault of their own.

---

## Compatibility

**9. PHP 8.4+ deprecation in the Google Drive client**

`ensure_folder_exists(string $token = null)` is an implicitly-nullable
parameter, deprecated in 8.4. On an AJAX endpoint the resulting notice can
corrupt the JSON body. Now `?string`.

**10. External CDN dependency removed**

The admin UI loaded RemixIcon from jsdelivr. That breaks on offline and
intranet installs, behind strict CSPs, and violates the WordPress.org guideline
against loading external resources. The 41 glyphs actually used are now bundled
locally: **123 KB of CSS reduced to 3 KB**, font served from the plugin
directory. Regenerate with `php tools/build-icons.php`.

---

## Reliability

**11. Google Drive calls with no timeout.** The duplicate-file lookup, the
folder lookup/creation, and the file listing all used WordPress's 5-second
default. A slow response silently skipped de-duplication, so every scheduled
backup added another copy to Drive. All calls now set explicit timeouts.

**12. Upload could spin forever.** A session repeatedly answering `308` without
advancing produced an infinite loop. Now bounded at 5 stalled rounds.

**13. Upload chunk size could exhaust memory.** The 5 MB chunk is held in memory
and copied by the HTTP layer. On a shared host with a small `memory_limit` this
could fatal mid-upload. The chunk now scales to the available budget, never
below Google's 256 KiB minimum and always on a 256 KiB boundary.

**14. Drive listing errors looked like "no backups".** A non-2xx response
returned an empty file list with no error, so an expired token presented as an
empty Drive tab. The error is now surfaced.

**15. The plugin polluted its own backups.** Export-state transients were dumped
into every archive and restored onto the target, resurrecting a phantom "export
in progress". They are now excluded.

---

## Verification

Everything below was executed, not inferred.

| Check | Result |
|---|---|
| `tests/test-sql-tokenizer.php` (35 cases) | 35/35 on PHP 8.3 **and** 8.5 |
| `tests/test-export-pagination.php` (4 cases) | 4/4 on PHP 8.3 **and** 8.5 |
| `tests/test-core-standalone.php` (13 cases) | 13/13 on PHP 8.3 **and** 8.5 |
| All 21 AJAX endpoints | 0 warnings, 0 fatals, all JSON well-formed |
| Full export → archive inspection | manifest valid, no self-inclusion, no generated columns, **no duplicated rows** |
| Restore into a scratch database | **0 failed statements** (was 1), 29/29 integrity checks |
| Cross-domain migration | URLs correct, **0 stale rows**, nested serialized data intact |
| Google Drive paths (mocked HTTP) | 6/6 |
| Deactivate / reactivate / uninstall | 15/15; options wiped, **backup files preserved** |
| PHP 8.5 deprecation sweep | 0 diagnostics from plugin code |
| Real browser, WP 7.1 | all 6 pages render, export completes, icons load locally, site alive after restore |

Before the fixes, the same restore suite reported a failed statement, a lost
table's worth of rows, a corrupt nested payload, and a corrupt escape-hazard
option. Those are the exact checks that now pass.

## Upgrading

No action required. Backups taken with earlier versions still restore — the
importer continues to accept the legacy escaped-quote token form.

One caveat worth stating plainly: a backup **taken** with 1.1.8 or earlier from
a table with generated columns is already missing those rows. Take a fresh
backup after upgrading.
