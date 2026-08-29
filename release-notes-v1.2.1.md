# SitesSaver 1.2.1

Fixes the **Server Cron** setup instructions introduced in 1.2.0. They produced a cron job
that saved without complaint and then never ran. If you set up a server cron using 1.2.0,
please redo it with the values this version shows.

---

## The trigger URL was not shell-quoted

The generated command embedded the URL bare:

```
wget -q -O - https://example.com/?sitessaver_run_backup=KEY&force=1 >/dev/null 2>&1
```

A URL with more than one query parameter contains `&`, and the shell reads that as *"run
everything to the left of this in the background"*. Everything from `&` onward was
discarded, so the documented `&force=1` form lost its meaning — and any panel that appended
its own parameters silently dropped the key.

Now quoted:

```
wget -q -O - "https://example.com/?sitessaver_run_backup=KEY&force=1" >/dev/null 2>&1
```

Double quotes rather than single, deliberately: the command is itself wrapped in single
quotes for panels that run it through `bash -c '...'`, and nesting single quotes inside that
requires the `'\''` escape — valid POSIX, but unreadable in a field you are meant to check
before pasting.

---

## Schedule and command are no longer mashed together

Hosting panels — RunCloud, cPanel, Plesk, CyberPanel — collect the schedule in their own
fields and the command in another. The page only ever offered one combined crontab line, so
pasting it into a panel's *Command* box produced:

```
* * * * * /bin/bash 0 * * * * wget ...
```

The panel supplies the leading `* * * * *`, and bash then tries to execute a file named `0`.
The job saves, shows up in the list, and does nothing.

The Server Cron panel now has two tabs:

- **Hosting panel** — vendor binary, command, and the five schedule values (Minute, Hour, Day
  of Month, Month, Day of Week) as separate copyable fields, laid out the way those forms
  are. Click any schedule box to copy it.
- **crontab -e** — the single combined line, clearly labelled for editing a crontab directly.

The panel command is wrapped as `-c '...'`, which is required whenever the panel runs it
through `/bin/bash`; without it bash treats `wget` as a script filename. The plain unwrapped
form is shown as well, for panels with no separate binary field.

---

## Also fixed

The five schedule boxes inherited the flex default of `stretch` and rendered as full-height
columns instead of small inputs.

---

## Verification

Checked by execution, not by reading the code:

- All three generated forms (wget, curl, `bash -c`) pass `bash -n`.
- The URL survives a real shell round-trip byte for byte, with and without `&force=1`.
- The old unquoted form demonstrably truncates at `&`, confirming the bug was real rather
  than theoretical.
- The generated command, run through `bash -c` exactly as RunCloud would invoke it,
  completed a real backup against a live WordPress 7.1 install.
- Both tabs, the copy buttons, and the corrected field height confirmed in the browser.

Test suites: 48 + 13 + 35 + 4, all passing.

---

## Upgrade notes

No data or settings change. If you configured a server cron with 1.2.0, open
**SitesSaver → Schedule**, use the **Hosting panel** tab, and copy the values across again —
the old command was almost certainly not running.
