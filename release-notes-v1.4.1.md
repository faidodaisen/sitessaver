# SitesSaver 1.4.1 — Backups that survive the server's request timeout

Large backups to Google Drive could fail with nothing but *"An error occurred"*. This release
fixes the cause: the backup no longer runs inside the browser's request, so your host's timeout
can no longer cut it short.

## What was going wrong

Most hosts kill an HTTP request after 60–90 seconds. SitesSaver ran each export step inside one
such request, so on a site big enough for the ZIP or the Drive upload to take longer, the host
cut the connection, the browser received an error page instead of a reply, and the export screen
reported a generic failure — often under a "Success!" heading, which was its own bug.

Worse, the failure was cosmetic *and* real at once: PHP usually kept working after the
disconnect, so a multi-gigabyte ZIP finished on disk and then sat there, never uploaded, taking
up space.

## What changed

**Exports run in the background.** Starting an export now hands the work to a background process
on your server. The browser only asks "how's it going?" every couple of seconds, and each of
those questions is answered instantly — so no host timeout can interrupt a backup, no matter how
long it takes or how large your site is.

**Google Drive uploads resume instead of restarting.** The upload is now sent in slices that
carry their position. If anything interrupts it, the next attempt continues from the exact byte
Google last confirmed rather than starting the multi-gigabyte transfer over. No part of the file
is ever sent twice.

**Progress you can trust.** Long stages report in as they work, so the progress bar keeps moving
through a big ZIP or a long upload. If the process genuinely dies, the screen now tells you so
instead of spinning forever.

**Honest error messages.** A server timeout, a dropped connection and a real failure now read
differently, and when the backup is still running in the background the message says so. The
result panel no longer announces "Success!" above a failure.

## Notes

- Nothing to configure — existing schedules, destinations and settings are untouched.
- Scheduled backups were never affected by this bug and their behaviour is unchanged.
- If a backup is interrupted, the Export screen still offers to resume it; resuming now also
  runs in the background.

## Install

Download `SitesSaver-1.4.1.zip` below, then **Plugins → Add New → Upload Plugin**. Existing
installs get the update through the normal Plugins screen.

Requires PHP 8.1+, WordPress 6.0+, and the ZipArchive PHP extension.
