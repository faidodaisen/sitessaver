# SitesSaver 1.1.10 — Google Drive restore hangs on large backups

A single-issue patch release. Restoring a backup from Google Drive would stall
and eventually time out with **zero bytes received**, on any backup large enough
for Drive to decline to scan it. That is most real full-site backups.

---

## Fixed — Drive download stalled forever, then timed out

Google runs a malware scan over Drive content. Any file it cannot scan — which
includes virtually every full-site backup ZIP, because of size — gets flagged.
For a flagged file the Drive v3 API does **not** return an error for
`alt=media`. It completes the TLS handshake, accepts the request, and then never
sends a response body. cURL waits until it hits its own timeout and reports
`Operation timed out ... with 0 bytes received`.

Measured against a real 74.78 MB backup on a live site:

| Request | Result |
| --- | --- |
| without `acknowledgeAbuse` | 0 bytes after 7+ minutes, then timeout |
| with `acknowledgeAbuse` | full 78,417,557 bytes in **1.44s** |

The download now sends `acknowledgeAbuse=true`. The flag only asserts that we
accept the risk of downloading a file Drive could not scan; it is a no-op for
unflagged files, so it is sent unconditionally. The file in question is the
user's own backup, which this same plugin uploaded.

`supportsAllDrives=true` is sent alongside it, so backups stored in a shared
drive resolve as well.

## Added — fail fast instead of hanging

A stall guard is attached to the transfer: if it delivers less than 1 byte per
second for 30 seconds straight, the request is aborted with a clear message
rather than sitting idle for the full 300s timeout and letting PHP-FPM kill the
request first — which produced no error message at all.

Timeout errors are now rewritten into an actionable message pointing at the
server's outbound connection to `googleapis.com`.

## Added — truncation is caught at download time

The response's byte count is compared against the size Drive reports in the file
metadata. A short read is rejected immediately, with both sizes named, instead
of being written to disk and failing later during restore as a confusing
corrupt-archive error.

---

## Upgrading

Drop-in. No database changes, no settings changes, no re-authorisation with
Google Drive. Backups made with any earlier version restore unchanged.
