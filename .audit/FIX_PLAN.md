# SitesSaver Fix Plan — Phased Execution

Total findings: 29 (3 CRIT, 6 HIGH-correctness, 3 HIGH-security, 6 MED, 11 misc)

## Execution Rules
- **2 agents max per phase**, running in parallel
- Each agent: (1) trace full dependency chain, (2) propose fix, (3) apply fix, (4) run `gitnexus_detect_changes`
- **NO blind edits** — every modified symbol must be preceded by `gitnexus_impact` upstream
- User reviews each phase before next phase starts
- After each phase: `npx gitnexus analyze` to refresh index

---

## Phase 1 — CRITICAL correctness (data loss risks)
**Agent A:** SQL import streaming (`Database::import` OOM)
**Agent B:** Serialized-data regex rewrite (`Database::replace_urls` — multibyte + selective length recalc + reject O:/C: markers)

Rationale: Both live in `class-database.php`, but in different methods. Splittable cleanly. Agent B change subsumes security finding #2 (object-injection on restore).

## Phase 2 — CRITICAL + HIGH correctness
**Agent A:** Remove 2GB file-skip guard in `Archive::create` + add ZIP64 sanity check
**Agent B:** Export state → transients (orphan `sitessaver_export_*` option rows) + fix pre-import `cleanup_temp` race

## Phase 3 — HIGH security (OAuth + token storage)
**Agent A:** OAuth `state` nonce on GDrive connect + callback verify (class-gdrive + templates/settings)
**Agent B:** Autoload=false for `sitessaver_gdrive_token` + `_schedule_log` + `_backup_labels` + `_settings` (audit every `update_option` call)

## Phase 4 — HIGH correctness (GDrive reliability + N+1 + textdomain)
**Agent A:** GDrive resumable upload — 5xx retry + session-resume via `Content-Range: bytes */total`
**Agent B:** `load_plugin_textdomain` + `sitessaver_get_backups` N+1 fix + activation ZipArchive guard

## Phase 5 — MED security + hygiene
**Agent A:** Drive `q` param injection (escape single quotes in folder_id/filename) + zip-slip fail-closed + symlink reject
**Agent B:** `uninstall.php` (delete options + cron + revoke refresh token via proxy) + `SHOW TABLES LIKE` param with esc_like

## Phase 6 — LOW + polish
**Agent A:** RemixIcon local bundle + WP-Cron staleness notice + `switch_theme` existence check
**Agent B:** Dual-syntax .htaccess + centralized `sitessaver_resolve_backup_path` helper + `is_admin()` guard on Admin::init + assemble_chunks cleanup on all fail paths

---

## Between-phase checklist (mandatory)
- [ ] `gitnexus_detect_changes({scope: "staged"})` shows only expected files
- [ ] Manual smoke test in Laragon WP
- [ ] `npx gitnexus analyze` to refresh index
- [ ] User signs off before next phase

## Skipped (by design, for now)
- `StorageDriver` interface refactor (Agent B suggestion #2) — architecture change, defer to separate work
- Convert static classes to instantiable services — same reason
- Consolidate copy/merge/remove directory utils — nice-to-have, defer
