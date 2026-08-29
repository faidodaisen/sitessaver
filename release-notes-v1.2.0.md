# SitesSaver v1.2.0

Scheduling is the focus of this release: **Monthly** backups, **several frequencies running at
once**, and a **server-cron trigger** for sites where WP-Cron cannot be relied on. Browser
dialogs are replaced with a custom notification system, and two progress-bar bugs are fixed.

---

## Monthly, and more than one frequency at a time

Frequency used to be a single dropdown. It is now a set of cards you can tick, so a common
setup like **Daily + Monthly** — a rolling week of recent restore points alongside a long-term
archive — is finally possible.

Each selected frequency:

- is scheduled as its **own cron event**, and
- tracks its **own last-run time**.

That second point matters. With a single shared timestamp, running the daily backup would mark
the monthly one as "just done" too, and the monthly archive would never be created. Each
frequency now becomes due strictly on its own interval.

Monthly means every 30 days from that frequency's last run.

**Upgrading is automatic.** A schedule saved by an earlier version stores a single `frequency`
string and a scalar last-run timestamp; both are read and migrated on the fly, with the old
timestamp applied to every frequency (the conservative reading — it delays the next backup
rather than firing a surprise one the moment you update).

---

## Server cron: scheduled backups that actually run

WordPress fires WP-Cron only when somebody loads a page. On a quiet site that means backups run
hours or days late, and if `DISABLE_WP_CRON` is set in `wp-config.php` they never run at all —
with nothing in the UI to say so.

The Schedule screen now has a **Server Cron** panel with a private, key-authenticated trigger
URL:

```
0 * * * * wget -q -O - "https://example.com/?sitessaver_run_backup=YOUR_KEY" >/dev/null 2>&1
```

Copy-paste lines are provided for `wget`, `curl`, and WP-CLI, along with instructions for
uptime-monitor services (UptimeRobot, cron-job.org, and similar) when you have no shell access.
The panel shows a red **"WP-Cron disabled — required"** badge when `DISABLE_WP_CRON` is set.

**The URL is safe to call more often than your chosen frequency.** SitesSaver checks each
frequency's own last-run time and replies `not due yet` until one is genuinely owed, so an
hourly cron with Daily selected still produces exactly one backup a day. Calling it more often
simply makes runs more punctual.

```
$ curl "https://example.com/?sitessaver_run_backup=KEY"
sitessaver: backup completed for [daily] (example.com-20260829-134849-3vmaxp.zip, 13.17 MB)

$ curl "https://example.com/?sitessaver_run_backup=KEY"
sitessaver: not due yet, next run in 1 day

$ curl "https://example.com/?sitessaver_run_backup=KEY&force=1"
sitessaver: backup completed for [daily] (example.com-20260829-134855-TrB3qW.zip, 13.17 MB)

$ curl "https://example.com/?sitessaver_run_backup=WRONG"
sitessaver: invalid key          # HTTP 403
```

Responses are plain text and correctly status-coded, so cron mailers and uptime monitors get
one greppable line rather than a rendered page. Keys are compared in constant time, an unset
key rejects rather than matching an empty parameter, and the URL can be rotated at any time
from the same panel. A transient lock prevents a WP-Cron firing and a server cron from
exporting concurrently into the same temp directory.

A **"Run backup now"** button lets you prove the whole setup works without waiting for the next
scheduled window.

---

## Custom notifications, no more browser dialogs

All 19 `alert()` / `confirm()` / `prompt()` calls are gone. Native dialogs were a poor fit:
they block the JavaScript thread (freezing any progress modal mid-export), cannot be styled or
translated through WordPress's i18n pipeline, and Chrome suppresses them entirely inside
cross-origin iframes — which silently swallowed confirmations for anyone embedding wp-admin.

In their place:

- **Toasts** in four variants with a hover-pausable auto-dismiss bar. Confirmations that
  trigger a page reload survive it, rather than being destroyed along with the document.
- **Dialogs** for confirm/prompt, with focus trapping, Escape-to-cancel, focus restored to
  whatever opened them, and — on destructive actions — default focus on the *safe* button, so
  Enter never deletes anything.

Destructive prompts also name what they are about to affect ("The backup file `x.zip` will be
permanently removed") instead of a generic "Are you sure?".

---

## Progress bar fixes

**The step was printed twice.** The current step appeared inside the progress bar *and* again
on a separate line below it, so every export read "Copying plugins..." on two consecutive
lines. The duplicate is gone.

**The bar sat at 100% during the entire Google Drive upload.** The upload runs inside the
`finalize` step, which was declared as 100% — so the bar filled and froze for what is usually
the slowest part of the whole export, giving no indication of progress or of whether anything
was still happening.

When a backup is bound for Drive, the step weights now change: local work occupies the first
**60%**, and the upload owns the remaining **40%**, filled from the real byte progress the
server was already recording per chunk. The label shows the live figure
("Uploading to Google Drive... (42%)"), and the bar never moves backwards when Drive
re-reports a retried chunk.

---

## Other fixes

- `--ss-primary-rgb` and `--ss-text` were used in CSS but never defined, so several rules
  silently fell back to transparent or inherited colours. `.ss-modal-open` was referenced by
  JavaScript but had no CSS rule at all, so modal scroll-lock never actually worked.
- Closing the progress modal released the scroll lock unconditionally, unlocking the page while
  the restore-complete modal was still open.
- **Retention was stored without clamping** — a crafted request could set it to `0`, which
  would delete every backup on the next scheduled run. It is now clamped to 1–100 server-side.
- The Google Drive upload status poller kept running after the upload finished.
- Uninstall and deactivation only cleared the old argument-less cron event, stranding every
  per-frequency event in the cron array.
- Resuming an interrupted Drive export rebuilt the step table with local weights, mislabelling
  the remaining steps.
- `tools/build-icons.php` read icon codepoints from a stylesheet that its own final step
  deletes, so any second run reported all 41 icons as missing and wrote an empty stylesheet. It
  now reads a map extracted from the bundled font, refuses to emit a stylesheet with unresolved
  glyphs, and no longer hardcodes a developer's local path.

---

## Verification

Checked against a real WordPress 7.1 install over HTTP, not only in unit tests:

- Trigger URL returns **403** on a bad key and produced a real 13 MB backup on a good one.
- Rate limiting and `force=1` behave as documented.
- With Daily + Weekly + Monthly selected and only Daily aged past its interval, **only Daily
  ran**; the other two timestamps were untouched.
- Three cron events exist at +1 day, +7 days, and +30 days, each carrying its frequency.
- Retention of 3 held exactly 3 backups.
- Label prompt, delete confirmation, cancel-does-not-delete, all four toast variants,
  auto-dismiss, Escape-to-close, and scroll-lock release confirmed in-browser.
- Drive upload progress verified against a mocked Google HTTP layer: the server published
  0 → 42 → 83 → 100% across chunks, which maps to 60 → 100% on the bar.

`tests/test-schedule.php` is new and adds **48 assertions** covering frequency normalisation,
multi-select, per-frequency due tracking, cron synchronisation, legacy migration, and
trigger-key handling. All existing suites still pass (13 + 35 + 4).

---

## Upgrade notes

No action required. Existing schedules keep their frequency and continue running.

If your site sets `DISABLE_WP_CRON`, or gets little traffic, open **SitesSaver → Schedule** and
set up the server cron shown in the new panel — that is the case this release exists to fix.
