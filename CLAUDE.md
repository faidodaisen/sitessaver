# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**SitesSaver** — WordPress plugin for full-site backup/migration (DB + files → single ZIP), with scheduled backups and Google Drive upload. Requires PHP 8.1+, WP 6.0+, `ZipArchive`. No build step — edit PHP/CSS/JS and reload.

Entry point: [sitessaver.php](sitessaver.php). Root constants define paths; an SPL autoloader maps `SitesSaver\Foo` → `includes/class-foo.php`.

## Architecture

Bootstrapping happens in [sitessaver.php](sitessaver.php) → `Plugin::init()` wires three singletons:

- **`Admin`** ([includes/class-admin.php](includes/class-admin.php)) — registers menu pages under `SitesSaver` and enqueues assets. Templates live in [templates/](templates/) (`export.php`, `import.php`, `backups.php`, `schedule.php`, `settings.php`, `help.php`).
- **`Ajax`** ([includes/class-ajax.php](includes/class-ajax.php)) — all `wp_ajax_sitessaver_*` handlers. Frontend calls here for chunked export/import, backup actions, GDrive connect, etc.
- **`Schedule`** ([includes/class-schedule.php](includes/class-schedule.php)) — WP-Cron `sitessaver_scheduled_backup` event. Reads `sitessaver_schedule` option to decide frequency + storage target (local / GDrive / both).

### Backup flow (export)
`Ajax` → `Export` ([includes/class-export.php](includes/class-export.php)) → `Database::dump()` ([includes/class-database.php](includes/class-database.php)) writes SQL, then `Archive::create()` ([includes/class-archive.php](includes/class-archive.php)) zips `wp-content/` + SQL into `SITESSAVER_STORAGE_DIR` (= `wp-content/sitessaver-backups/`). Large sites use chunked processing driven by AJAX progress polling.

### Restore flow (import)
`Ajax` → `Import` ([includes/class-import.php](includes/class-import.php)) → `Archive::extract()` unpacks ZIP into temp dir (with **zip-slip protection** — file-by-file path validation; do NOT reintroduce `extractTo()` on the whole archive), then `Database` imports SQL with URL search-replace (serialized-safe) for migration.

### Google Drive
[includes/class-gdrive.php](includes/class-gdrive.php) handles OAuth + uploads, but auth goes through an **external OAuth proxy** (the plugin doesn't ship client secrets). Proxy lives at `api.sitessaver.com` (separate repo at `c:\laragon\www\sitessaver-proxy\`). Connection state = presence of `refresh_token` in the `sitessaver_gdrive_token` option — **not** a `gdrive_client_id` option (that doesn't exist; checking for it was a past bug).

### Storage & options
- Backups: `wp-content/sitessaver-backups/` (protected via `.htaccess` + `index.php`, created on activation).
- Options: `sitessaver_schedule`, `sitessaver_gdrive_token`, `sitessaver_settings`, backup metadata rows.

## Common Commands

```bash
# Refresh the GitNexus index after any edits
npx gitnexus analyze

# Laragon hosts the WP site — no build/test/lint suite is committed.
# There is no composer.json, package.json, or phpunit config in this repo.
```

No PHPUnit, Pest, or JS build tooling is configured. Testing is manual in a WordPress install (Laragon at `c:\laragon\www\sitessaver`).

## Conventions

- `declare(strict_types=1);` + namespaced classes under `SitesSaver\`.
- Singletons everywhere (`::instance()`). Don't `new` them.
- Always `defined('ABSPATH') || exit;` at top of PHP files.
- Use helpers from [includes/helpers.php](includes/helpers.php) rather than duplicating path/option logic.
- AJAX handlers must `check_ajax_referer()` and `current_user_can('manage_options')`.
- When touching archive extraction, preserve per-entry path validation (zip-slip fix).
- When touching scheduled-backup GDrive checks, verify against `sitessaver_gdrive_token['refresh_token']`.

<!-- gitnexus:start -->
# GitNexus — Code Intelligence

This project is indexed by GitNexus as **sitessaver** (237 symbols, 615 relationships, 20 execution flows). Use the GitNexus MCP tools to understand code, assess impact, and navigate safely.

> If any GitNexus tool warns the index is stale, run `npx gitnexus analyze` in terminal first.

## Always Do

- **MUST run impact analysis before editing any symbol.** Before modifying a function, class, or method, run `gitnexus_impact({target: "symbolName", direction: "upstream"})` and report the blast radius (direct callers, affected processes, risk level) to the user.
- **MUST run `gitnexus_detect_changes()` before committing** to verify your changes only affect expected symbols and execution flows.
- **MUST warn the user** if impact analysis returns HIGH or CRITICAL risk before proceeding with edits.
- When exploring unfamiliar code, use `gitnexus_query({query: "concept"})` to find execution flows instead of grepping. It returns process-grouped results ranked by relevance.
- When you need full context on a specific symbol — callers, callees, which execution flows it participates in — use `gitnexus_context({name: "symbolName"})`.

## When Debugging

1. `gitnexus_query({query: "<error or symptom>"})` — find execution flows related to the issue
2. `gitnexus_context({name: "<suspect function>"})` — see all callers, callees, and process participation
3. `READ gitnexus://repo/sitessaver/process/{processName}` — trace the full execution flow step by step
4. For regressions: `gitnexus_detect_changes({scope: "compare", base_ref: "main"})` — see what your branch changed

## When Refactoring

- **Renaming**: MUST use `gitnexus_rename({symbol_name: "old", new_name: "new", dry_run: true})` first. Review the preview — graph edits are safe, text_search edits need manual review. Then run with `dry_run: false`.
- **Extracting/Splitting**: MUST run `gitnexus_context({name: "target"})` to see all incoming/outgoing refs, then `gitnexus_impact({target: "target", direction: "upstream"})` to find all external callers before moving code.
- After any refactor: run `gitnexus_detect_changes({scope: "all"})` to verify only expected files changed.

## Never Do

- NEVER edit a function, class, or method without first running `gitnexus_impact` on it.
- NEVER ignore HIGH or CRITICAL risk warnings from impact analysis.
- NEVER rename symbols with find-and-replace — use `gitnexus_rename` which understands the call graph.
- NEVER commit changes without running `gitnexus_detect_changes()` to check affected scope.

## Tools Quick Reference

| Tool | When to use | Command |
|------|-------------|---------|
| `query` | Find code by concept | `gitnexus_query({query: "auth validation"})` |
| `context` | 360-degree view of one symbol | `gitnexus_context({name: "validateUser"})` |
| `impact` | Blast radius before editing | `gitnexus_impact({target: "X", direction: "upstream"})` |
| `detect_changes` | Pre-commit scope check | `gitnexus_detect_changes({scope: "staged"})` |
| `rename` | Safe multi-file rename | `gitnexus_rename({symbol_name: "old", new_name: "new", dry_run: true})` |
| `cypher` | Custom graph queries | `gitnexus_cypher({query: "MATCH ..."})` |

## Impact Risk Levels

| Depth | Meaning | Action |
|-------|---------|--------|
| d=1 | WILL BREAK — direct callers/importers | MUST update these |
| d=2 | LIKELY AFFECTED — indirect deps | Should test |
| d=3 | MAY NEED TESTING — transitive | Test if critical path |

## Resources

| Resource | Use for |
|----------|---------|
| `gitnexus://repo/sitessaver/context` | Codebase overview, check index freshness |
| `gitnexus://repo/sitessaver/clusters` | All functional areas |
| `gitnexus://repo/sitessaver/processes` | All execution flows |
| `gitnexus://repo/sitessaver/process/{name}` | Step-by-step execution trace |

## Self-Check Before Finishing

Before completing any code modification task, verify:
1. `gitnexus_impact` was run for all modified symbols
2. No HIGH/CRITICAL risk warnings were ignored
3. `gitnexus_detect_changes()` confirms changes match expected scope
4. All d=1 (WILL BREAK) dependents were updated

## Keeping the Index Fresh

After committing code changes, the GitNexus index becomes stale. Re-run analyze to update it:

```bash
npx gitnexus analyze
```

If the index previously included embeddings, preserve them by adding `--embeddings`:

```bash
npx gitnexus analyze --embeddings
```

To check whether embeddings exist, inspect `.gitnexus/meta.json` — the `stats.embeddings` field shows the count (0 means no embeddings). **Running analyze without `--embeddings` will delete any previously generated embeddings.**

> Claude Code users: A PostToolUse hook handles this automatically after `git commit` and `git merge`.

## CLI

| Task | Read this skill file |
|------|---------------------|
| Understand architecture / "How does X work?" | `.claude/skills/gitnexus/gitnexus-exploring/SKILL.md` |
| Blast radius / "What breaks if I change X?" | `.claude/skills/gitnexus/gitnexus-impact-analysis/SKILL.md` |
| Trace bugs / "Why is X failing?" | `.claude/skills/gitnexus/gitnexus-debugging/SKILL.md` |
| Rename / extract / split / refactor | `.claude/skills/gitnexus/gitnexus-refactoring/SKILL.md` |
| Tools, resources, schema reference | `.claude/skills/gitnexus/gitnexus-guide/SKILL.md` |
| Index, status, clean, wiki CLI commands | `.claude/skills/gitnexus/gitnexus-cli/SKILL.md` |

<!-- gitnexus:end -->
