# Changelog

All notable changes to **SitesSaver** will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.4.9] — 2026-10-07

### Fixed — backup stopped at "Copying uploads..." with "No progress for 301s"

The media step copied each file with a single `copy()` call and only reported that it was alive after a
file finished. One very large file in `wp-content/uploads` (a video, or another plugin's backup archive)
on a slow shared-host disk took longer than the 5-minute stall limit, and the export was reported dead
while it was still working. Excluded folders were also walked file by file without reporting progress.

- Files of 16 MB and more are copied in 8 MB pieces, reporting progress after each piece. The progress
  line names the file and shows how much of it is done.
- Excluded folders are skipped as a whole instead of being walked.
- Other backup plugins' archives inside uploads (`ai1wm-backups`, `updraft`, `backwpup-*`, `wpvividbackups`,
  `*.wpress` and similar) are left out of the backup and listed in the log. A backup of a backup is never
  needed and is usually the file that made the media step too slow.
- A stalled export is restarted automatically up to 3 times. The new worker continues from the step it
  was on and skips files already copied, and the old worker stops if it was only slow.
- A server that kills the export outright (time or memory limit) is now reported with its real reason.
- Stall and failure log entries record the file being copied, its size, how long the export ran, and
  the host's limits (`max_execution_time`, memory, free disk, server software), so a problem can be
  diagnosed from the downloaded log alone.
- New plain-language messages: "The backup got stuck on one large file" (names the file) and "Your
  server stopped the backup". The hints point to Help → Troubleshooting Log.
- A file that cannot be read or written is still left out as before, but now listed in the log; running
  out of disk space stops the backup with a clear reason instead of producing an incomplete archive.

## [1.4.8] — 2026-10-05

### Fixed — uploading a large backup failed at 100% with "An error occurred."

Each uploaded chunk was saved as its own file, and the request carrying the LAST chunk then glued every
chunk back together — copying the whole backup twice inside one request. On a shared host with a
throttled disk (observed: ~4 MB/s on LiteSpeed/CloudLinux) that took minutes; the web server cut the
request off at its 60-second limit, the browser showed a bare "An error occurred." at 100%, and its
cleanup call then deleted the chunks the server was still assembling, so nothing at all was left.

- Each chunk is now appended to a single `.part` file as it arrives. The last chunk appends its own
  2 MB and renames the file into place, so every upload request does the same small amount of work.
- Chunks are idempotent: a retried chunk that already landed is recognised, not appended twice.
- The browser retries a failed chunk up to 5 times with backoff, asking the server first how far the
  upload actually got (`sitessaver_upload_status`). It no longer deletes the upload while retrying.

### Fixed — a large restore could be cut off by the web server's request time limit

The restore ran inside the single request the browser made, so it hit the same 60–120 second web
server limit as the upload. It now runs as a background job, like exports have since 1.4.1.

- `sitessaver_restore_start` returns at once; the work runs in a detached loopback request. The browser
  only polls `sitessaver_restore_status`, so no request it makes lasts long.
- Hosts that block loopback requests fall back to running the restore from the browser, still followed
  through the status poll.
- Job state lives in a protected file, not the options table, because the restore replaces the database
  halfway through. The status poll is authorised by the job's own random token for the same reason: after
  the database swap the browser's login session no longer exists.
- A job that stops reporting progress is reported as stalled instead of spinning forever; a fatal error
  inside the restore is caught on shutdown and reported too.
- The previous endpoints (`sitessaver_import`, `sitessaver_gdrive_restore`) remain for cached older pages.

### Added — a troubleshooting log and plain-language error messages

- Every failure is written to the plugin's own log (`wp-content/sitessaver-backups/logs/`, web access
  denied, no tokens or passwords recorded) with a short reference code.
- Error notices now say what happened, whether anything on the site changed, and what to do next, in
  non-technical words, with the reference code and a collapsed "Technical details" line.
- Failures only the browser can see (dropped connection, gateway timeout page) are reported to the log too.
- **Help → Troubleshooting Log** lists recent entries and offers Download / Clear.

### Fixed — restore errors were invisible on the Backups page

The Backups page's result container was empty, so a failed restore started from there showed nothing.

## [1.4.7] — 2026-10-04

### Fixed — restore failed with "Failed to extract backup archive." when the backup contained a file name with two dots

`Archive::extract()` refused any ZIP entry whose name contained `..` anywhere (`str_contains($entry, '..')`),
meant to stop path traversal. That also matched perfectly legal file names such as
`wp-content/uploads/2021/03/ear-nose-and-throat-conditions..jpg`, and because the extractor is fail-closed one
such file aborted the whole restore. Observed on a real migration (drpuvan.com, 2026-10-04): the ZIP was valid,
the server was fine, two media files tripped the check.

- New `Archive::is_unsafe_relative_path()` treats a path as traversal only when a path SEGMENT is exactly `..`
  (after normalising backslashes). Absolute paths, Windows drive prefixes and NUL bytes are still refused.
- Used by `Archive::extract()` and by the incremental-restore deletion list (`Import::apply_deletions()`), which had
  the same check.
- Zip-slip protection is unchanged (`../x`, `a/../b`, `a\..`, `/etc/passwd`, `C:/x` are all still rejected).

## [1.4.6] — 2026-10-03

### Fixed — large exports failed at 95% with "stopped unexpectedly (no progress for 301s)"

`ZipArchive::close()` does the real read + compress + write for every file `addFile()`'d into the
archive — `addFile()` itself just registers metadata and returns almost instantly. On a multi-
gigabyte `wp-content`, the per-file liveness ticks taken during the (fast) add loop went silent
right as the (slow) `close()` call started, and a backup large enough for `close()` alone to run
past the 300s stall threshold was flagged dead and failed — even though the worker was still alive
and simply finishing the archive.

- `Archive::create()` now ticks a dedicated `zip-finalizing` phase immediately before calling
  `close()`, using a new unthrottled tick that always persists regardless of the normal 10s
  interval.
- The stall check in `Ajax::handle_get_export_status()` gives that specific phase a 1800s grace
  period instead of 300s, since it is a single uninterruptible call with no way to report
  incremental progress from inside it.

### Added — live step checklist in the export and restore progress modals

Both modals previously showed one label and a bar; there was no way to see which phase a backup or
restore was actually in.

- Export: a checklist of all 9 pipeline steps (init → manifest → database → uploads → plugins →
  themes → other files → ZIP → finalize) now sits above the bar, each row showing pending / active
  / done state with an icon — driven by the real `step_index` the server reports, in both the
  normal and background-worker code paths, and on resume.
- Import/restore: the restore still runs inside one blocking request (it cannot be safely
  backgrounded — it holds the admin session that authorized it), but it now ticks a phase transient
  at each real boundary (extract → validate manifest → restore database → restore files → finalize)
  that a concurrent status poll reads while the restore request is still in flight. Wired into all
  three restore entry points: upload-then-restore, restore-an-existing-backup, and
  restore-from-Google-Drive.

## [1.4.5] — 2026-09-29

### Fixed — restoring into a site with a different table prefix left it half-migrated

A backup does not carry `wp-config.php`, so the destination keeps its own `$table_prefix`. A dump
from a `bzm_` site imported into a `wp_` site created a parallel `bzm_*` table set that WordPress
never reads: files and the active theme switched over, while content, users and options stayed the
old site's. Seen on a live migration.

- Table names in `DROP` / `CREATE` / `INSERT` statements (and foreign-key `REFERENCES` inside a `CREATE`) are rewritten to the destination prefix as the dump is replayed.
- Afterwards the keys WordPress core derives from the prefix are renamed — `{prefix}user_roles`, `{prefix}capabilities`, `{prefix}user_level`, `{prefix}user-settings`, … — so every user keeps their role.
- Row data is never touched, and plugin/theme options that merely start with the same letters (e.g. `bzm_gsheets_auth`) are left alone: only a fixed list of core keys is renamed.
- Older backups whose manifest has no `db_prefix` are handled too: the source prefix is read from the dump's `<prefix>options` table.
- A prefix containing anything other than letters, digits or `_` is refused, so a tampered manifest cannot inject SQL.

Verified end-to-end: a real `bzm_` backup (16 posts of a custom type, 8 pages, app passwords) restored into a fresh `wp_` install — 17 `wp_*` tables and no `bzm_*`, the admin keeps `administrator`, every page renders, the application password still authenticates.

## [1.4.4] — 2026-09-29

### Fixed — restore did not log out / skip to Permalinks when login URL is hidden

- After a restore, "Finish & log out" now goes through a SitesSaver `admin-post.php` action that destroys the session server-side, then sends the user to the login screen with Settings → Permalinks as the destination. Previously it relied on `wp-login.php?reauth=1`, which login-hiding plugins (WPS Hide Login, Admin Speedboost, etc.) bounce to wp-admin before WordPress can clear the cookie, so the user stayed logged in and never reached the Permalinks step.
- The action requires the one-time finalize token, so it cannot be used as a drive-by logout link.

## [1.4.3] — 2026-09-29

### Fixed — upload percentage shown twice

While importing a backup, the progress modal read *"Uploading (5%)  5%"*: the label carried the
percentage and the bar's own counter printed it again beside it. The label is now just
*"Uploading"* and the counter is the single source of the number.

---

## [1.4.2] — 2026-09-29

On sites with a large database the export sat on *"Exporting database..."* for a long time and
could then be reported as *"The backup process stopped unexpectedly"* while it was in fact still
running. Seen on a 546 MB database whose biggest table was a 427k-row plugin log.

### Fixed — database dump no longer slows down as it goes

Each table was read in pages with `LIMIT n OFFSET m`. MySQL has to walk past every earlier row to
honour an OFFSET, so each page cost more than the last and a large table's dump grew with the
square of its row count. Pages now continue from the last primary key seen
(`WHERE id > last ORDER BY id LIMIT n`), an index range read that costs the same on every page.
On a 430k-row table the late pages went from ~83x slower than the first to constant, and the
table dumped in about 5 seconds instead of an estimated 20+ minutes. Output is unchanged: every
row once, in primary-key order. Tables without a usable single-column key keep the previous
behaviour.

### Fixed — a long database dump is no longer reported as stalled

The database step sent no heartbeat until it finished, so after 5 minutes the export screen
assumed the worker had died. The dump now reports progress after every chunk of rows, the same
way the file-copy and ZIP steps already do.

---

## [1.4.1] — 2026-09-21

Large backups to Google Drive could fail with nothing but *"An error occurred"*. The cause was
that every export step ran inside a single HTTP request, so on hosts that kill requests after
60–90 seconds the ZIP or the Drive upload was cut off mid-flight. The gateway answered with an
HTML error page, the browser could not parse it as a reply, and the export screen reported a
generic failure — while PHP usually kept running and left a finished multi-gigabyte ZIP on disk
that was never uploaded.

### Changed — exports run in a background worker

Starting an export now dispatches the work to a detached loopback request on the server and
returns immediately. The browser only polls short status requests, so no gateway timeout can
interrupt a backup regardless of site size or transfer duration.

The worker endpoint is authorized by a single-use key stored server-side and deleted on first
use, not by the session: a detached loopback request carries no auth cookie, so it is registered
for logged-out AJAX and cannot be replayed.

If the host blocks loopback requests, the export screen falls back to the previous
browser-driven step runner, which still works for sites small enough to finish inside a request.

### Changed — Google Drive uploads are resumable across requests

The upload is now cut into slices bounded by a wall-clock deadline. Each slice returns the
resumable session URL and the byte offset Google has confirmed; the caller persists that state
and continues in a fresh request. An interrupted upload resumes from the confirmed offset
instead of restarting, and no byte range is ever sent twice.

Google's resumable session URL is pre-authorized, so a long upload can safely outlive the access
token that started it. A session is opened once per backup, not once per slice.

### Added — liveness reporting and stall detection

Long-running stages report progress as they work, so the progress bar keeps moving through a
large ZIP or a long upload. Export status now exposes how long it has been since the worker last
reported in, and the screen reports a process that genuinely died instead of spinning
indefinitely.

### Fixed — error reporting

- A gateway timeout (502/504), a dropped connection and a genuine failure now produce distinct
  messages, and when work continues in the background the message says so rather than claiming
  the backup failed.
- The result panel no longer displays the hardcoded "Success!" heading above a failure; it reads
  "Failed" when the operation failed.
- The Drive upload's progress indicator is no longer discarded when a slice reaches its deadline,
  so the bar no longer freezes between requests.
- Re-entering the finalize stage during a resumed upload no longer re-registers the backup in an
  incremental chain or re-runs temp cleanup.

### Unchanged

Scheduled backups run under WP-Cron, which has no gateway timeout, and were never affected by
this bug. Their behaviour, and all existing settings, schedules and destinations, are unchanged.

---

## [1.4.0] — 2026-09-16

Scheduled backups can now archive only what changed since the previous run. On a site with a
large media library that turns a nightly backup from hours and gigabytes into minutes and
megabytes. It is off by default, free, and the manual Export button is untouched.

### Added — incremental scheduled backups

Schedule now has a **Backup Mode** choice: *Full every time* (what every install does today, and
still the default) or *Incremental*. In incremental mode each scheduled run archives only the
files whose size or modification time differs from the previous backup. The database is always
dumped in full, so a restore never replays database changes — it imports one SQL file, as it
always has.

Every backup carries a complete index of the site's files at the moment it ran — not just the
files inside that ZIP — so the baseline for the next run is a single file read rather than a
merge of every delta since the last full backup.

Two settings sit under the mode:

- **Start a new full backup every N runs** (default 7). A shorter chain restores faster and
  limits the blast radius of one damaged archive; a longer one saves more disk.
- **Change detection**: *Fast* (size and modified date, right for almost every site) or
  *Thorough* (verifies contents with a checksum, for hosts or deploy processes that rewrite
  file timestamps and would otherwise make every file look changed).

SitesSaver also starts a fresh full backup on its own when the incrementals have added up to
more than half the size of the full backup, when the previous backup has gone missing, or when
its index cannot be read. Every one of those degrades to a *larger* backup, never a failed one.

### Added — restoring a chain

Restoring an incremental backup replays its chain automatically: each member is extracted
oldest-first into one merged tree, deletions recorded along the way are applied in order, and
the result is handed to exactly the same restore path a full backup uses. Restore to an earlier
point in a chain and files deleted after it come back, as they should.

A ZIP cannot represent a file that no longer exists, so deletions travel in the manifest. The
manifest also names the full ordered restore set, which means an incremental archive is
self-describing: it still restores after a reinstall, a migration, or a database restore that
wiped the plugin's own options.

If a chain member is missing, the restore is refused **before** anything on the live site is
touched, naming the files it needs. A half-applied restore is worse than no restore.

### Changed — retention now counts restore points, not files

This is the part that would have quietly destroyed backups, so it is worth being plain about.
Retention used to delete the oldest ZIPs by count. With chains that rule would eventually delete
the full backup at the head of a chain and leave a pile of incrementals that restore to nothing,
while the Backups list still showed the promised number of healthy-looking entries.

Retention now counts *restore points*. A restore point is a full backup plus every incremental
descended from it, and those age out together. A chain is treated as being as recent as its
newest member, so a chain still in active use never ages out from underneath you. Keeping 5
restore points can therefore mean more than 5 files in the folder — that is the intended
behaviour, and the Schedule screen now says so.

Deleting a backup by hand removes its cached index and its chain record too, and deleting a full
backup that incrementals depend on now warns exactly how many backups it would strand.

### Changed — the Backups list shows chains

Chain members are labelled *Full* or *Incremental*. An incremental says how many files a restore
needs; a full says how many incremental backups are built on it. Backups that belong to no chain
— every manual export, and everything created before this release — are shown exactly as before.

### Unchanged on purpose

- The **Export** button always produces a full, standalone backup. It builds no index and joins
  no chain, so a backup you take by hand is always a file you can carry anywhere.
- Manifests for untracked backups are byte-for-byte what they were, so an older SitesSaver
  reading one sees nothing new.
- Backups made before 1.4.0 restore through the unchanged full-backup path.

---

## [1.3.1] — 2026-09-12

Removes a settings panel that should never have been a setting, and syncs the Help page with
what the plugin actually does.

### Changed — the update source is no longer a user setting

1.3.0 exposed the repository, release channel, and an access token as fields on the Settings
page. Wrong call: which repo a build updates from is a property of the build, not a user
preference, and those fields only invited someone to point a production install somewhere
arbitrary or enable pre-releases without understanding them.

`Updater::REPO` is now the single source of truth. `Updater::config()` reads it, then lets the
`sitessaver_update_source` option and a new `sitessaver_update_config` filter override — so
forks and private mirrors are still supported, in code rather than in the UI. A filter that
returns a non-array falls back to defaults instead of breaking update checks entirely.

What is left in Settings is a status block: the version you are running, and a button to check
now. When an update exists it becomes a prompt linking to the Plugins screen and the notes.
The `sitessaver_save_update_source` AJAX endpoint was removed with the form it served.

### Fixed — two UI descriptions that were false

The Schedule page described retention as keeping "scheduled backups... counted across all
frequencies". `apply_retention()` operates on `sitessaver_get_backups()`, which is every
archive in the storage directory — so a manual backup could be deleted by a low retention
value, contradicting the text. The wording now matches the behaviour.

The Email Notification field said only that an email would be sent. It now states what the
report contains and links to the branding panel.

### Changed — Help page rewritten

The manual still documented a five-feature plugin. It now covers nine topics: restoring from
Google Drive, why a cross-domain restore signs you out, what retention really deletes, testing
a schedule with "Run backup now", what the notification email reports (including the
successful-backup-but-failed-upload case), email branding and sender-domain advice, how
updates reach a manually installed plugin and what survives one, and where backups live on
disk including the Nginx caveat.

### Tests

`tests/test-updater.php` is up to 72 assertions, adding coverage for the hardcoded default,
the option override, the filter override, and a malformed filter return. Suite total is now
172 assertions across five files.

---
## [1.3.0] — 2026-09-12

Branded backup emails, and automatic updates straight from GitHub.

### Added — automatic updates from GitHub releases

SitesSaver is installed manually, not from wordpress.org, so WordPress had no update source
for it and a new version could sit on GitHub unnoticed indefinitely.

The plugin now declares an `Update URI` and answers for itself. It polls its GitHub releases
(cached six hours) and, when a newer tag exists, the update appears on the Plugins screen like
any other plugin, with a working "View details" modal built from the release notes. A
dismissible dashboard banner covers the case where the owner rarely opens that screen;
dismissal is recorded per version, so the next release surfaces again rather than being
silenced forever by one click.

Settings → Plugin Updates exposes the repository, a stable/pre-release channel toggle, an
optional token for a private repo, and a "Check for updates now" button that bypasses the
cache.

Implementation notes that matter:

- The hook is `update_plugins_github.com`, scoped to our own host, rather than a blanket
  `pre_set_site_transient` filter that could answer for someone else's plugin. The transient
  read is also filtered, because the 5.8 hook alone does not populate the row on every path.
- A release's built `.zip` asset is preferred over GitHub's generated zipball: the asset has
  the correct root folder and excludes `tests/` and `tools/`.
- When only a zipball is available its `owner-repo-sha` folder is renamed to `sitessaver`
  during install. Left alone, WordPress installs into a folder of that name and deactivates
  the existing copy.
- A configured token is attached only to requests whose URL targets the configured repo, so a
  private-repo credential cannot leak into another plugin's GitHub traffic.
- Failures cache for 30 minutes instead of 6 hours, so a brief outage does not mask an update
  for the rest of the day.

### Added — branded HTML notification emails

Backup reports were four lines of plain text. They are now a table-based HTML email (the only
thing Outlook's Word renderer and Gmail's style-stripping both tolerate) carrying a logo,
accent colour, status badge, per-destination storage cards, and action buttons.

Settings → Email Branding controls logo, colour, sender name and address, support link, and
footer note, with a "Send test email" button that saves first and then delivers a real sample
through the same renderer a live report uses.

A plain-text alternative is always generated from the same data structure, so the two formats
cannot drift, and HTML can be switched off entirely.

### Added — the reports actually say something now

Both formats gained: which schedule fired, what the archive contains, whether the local copy
was kept or removed per storage settings, Google Drive upload status with a folder link, an
explicit failure notice with the reason when an upload fails, the retention policy, and the
next scheduled run. Failure emails list the usual causes and confirm that existing backups are
untouched.

### Security

- Sender name is stripped of CR/LF before it reaches a mail header, closing header injection.
- An invalid accent colour falls back to the default rather than being interpolated into every
  inline style in the template.
- `wp_mail_content_type` is added and removed around a single send inside a `finally` block,
  so a throwing `wp_mail` cannot leave every later message on the request formatted as HTML.
- Release notes are escaped before limited Markdown is reintroduced, so a malformed or hostile
  release body cannot inject markup into wp-admin.

### Tests

New `tests/test-updater.php` (66 assertions) covers release selection, draft and pre-release
filtering, package preference, version comparison, caching and error TTLs, transient
injection, token scoping, the source-folder rename, and release-note escaping. The suite is
now 166 assertions across five files. `tests/live-check-github.php` is a manual smoke test
that resolves the real repo and verifies the downloaded package would install.

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
