# SitesSaver 1.4.4 — Restore log-out fix

Fixes the post-restore step on sites that hide or rename the login URL (WPS Hide Login, Admin Speedboost, similar).
After a restore, **Finish & log out** left you logged in and dropped you on the dashboard instead of
Settings → Permalinks. It now ends the session server-side, then sends you to the login screen with
Permalinks as the destination.

## Install

Download `SitesSaver-1.4.4.zip` below, then **Plugins → Add New → Upload Plugin**. Existing
installs get the update through the normal Plugins screen.

Requires PHP 8.1+, WordPress 6.0+, and the ZipArchive PHP extension.
