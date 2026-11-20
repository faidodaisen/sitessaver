=== SitesSaver ===
Contributors: fidodesign
Tags: backup, restore, migration, google drive, schedule
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.6.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Full site backup, restore and migration with schedules and Google Drive. No file size limit and no paid version holding features back.

== Description ==

SitesSaver puts your whole WordPress site, the database and every file, into one ZIP. You can download it, keep it on the server, send it to your own Google Drive, and restore it on the same site or on a new domain.

Everything in the plugin is free. There is no file size limit, no license key and no locked feature.

**What it does**

* **Full backup.** The database and every file under your WordPress install go into one ZIP.
* **Restore.** Drag a backup ZIP onto the Import screen, or restore one that is already on the server or in Google Drive. If a restore fails, the previous database and files are put back.
* **Migration.** Restore onto a new domain and the URLs are rewritten everywhere, including inside serialized data. A different table prefix on the new site is handled too.
* **Scheduled backups.** Hourly to monthly, with several frequencies running at once. Optional incremental mode, retention, and a private trigger URL for real server cron.
* **Google Drive.** Send backups to your own Drive and restore straight from it. Add-on plugins can register more cloud storage through the `sitessaver_destinations` filter.
* **Works on strict hosts.** Backups and restores run in short background slices, so a host that stops every request after 30 seconds cannot cut them short. You can close the page while a backup runs and get an email when it is done.
* **Email reports.** A report after each scheduled run, with your own logo, colours and sender.

**Good to know**

* Backups are stored in `wp-content/sitessaver-backups/`, outside the plugin folder, so updating or removing the plugin does not delete them.
* On Nginx, block web access to that folder in the server config. Help > Where are my backups stored? shows whether it is reachable and gives the exact rule.
* WP-Cron only runs when someone visits the site. For dependable schedules, use the Server Cron panel on the Schedule page.
* On multisite, only a super admin can use the plugin. The `sitessaver_capability` filter changes this.

Full documentation: [sitessaver.com/docs.html](https://sitessaver.com/docs.html)

== Installation ==

1. In wp-admin, go to Plugins > Add New, search for SitesSaver, then click Install Now and Activate. You can also upload the plugin ZIP from Plugins > Add New > Upload Plugin.
2. Open the SitesSaver menu. It has Backups, Export, Import, Schedule, Settings and Help.
3. Click Export to make your first backup. To move a site, install SitesSaver on the new site and drop the backup ZIP onto its Import screen.

Requirements: PHP 8.1 or newer, WordPress 6.0 or newer, and the ZipArchive PHP extension to restore (enabled on most hosts).

== Frequently Asked Questions ==

= Is there a file size limit? =

No. Large sites are backed up and restored in short background slices, so the size of the site is limited only by your disk space and your host.

= Is there a paid version? =

Not today. Everything the plugin does is free and unrestricted. Separate add-on plugins for other storage services may be offered later. Nothing that works for free now will be moved behind a payment.

= Where are my backups stored? =

In `wp-content/sitessaver-backups/` on your own server, and in your own Google Drive if you connect it. Backups are never sent to the plugin author.

= Do I need Google Drive? =

No. Google Drive is optional. Local backups, restores and migration work without it and never contact any outside service.

= My host stops long requests. Will backups still finish? =

Yes. Backups and restores run as a chain of short background requests to your own site. If your host blocks those requests, the plugin tells you so and falls back to running in the open browser tab.

= What happens if a restore fails halfway? =

The previous database is kept until the restore has finished, and the files it replaced are put back. Your site goes back to how it was before the restore started.

= Where do I get help? =

Include the reference code from the error message and the log from Help > Troubleshooting Log when you ask for help on the support forum.

== External services ==

SitesSaver works without contacting any outside service. Local backups, restores and migration stay on your server. The plugin only connects to outside services when you choose to connect Google Drive. The services involved are listed below.

**Google Drive API (Google LLC)**

Used to store backups in your own Google Drive and to restore from it. It is only used after you click Connect Google Drive in SitesSaver > Settings and approve access at Google.

* What is sent: your backup ZIP files (on upload), the backup file names, the name of the backup folder the plugin creates in your Drive, and a short-lived access token. When you list, download or delete Drive backups, the plugin sends the file IDs it is working with.
* When: when a manual or scheduled backup is set to upload to Google Drive, when you open the Backups page with Drive connected, and when you restore, download or delete a Drive backup.
* Endpoints: `https://www.googleapis.com/drive/v3` and `https://www.googleapis.com/upload/drive/v3`.
* Google Terms of Service: https://policies.google.com/terms
* Google Privacy Policy: https://policies.google.com/privacy

**SitesSaver authorisation relay (api.sitessaver.com)**

Google requires a registered app with a client secret for Drive access. A secret cannot be kept private inside a plugin anyone can download, so the sign-in exchange runs through a small relay run by the plugin author at `https://api.sitessaver.com`. Your backups and your site content never go through it.

* When you click Connect Google Drive: your browser is sent to the relay with the address of your SitesSaver Settings page (so you can be sent back) and a single-use random value used to verify the response.
* When an access token expires (during Drive uploads, downloads and listings): your server sends your Google refresh token to the relay and gets back a short-lived access token.
* When you click Disconnect, or delete the plugin while Drive is connected: your server sends your refresh token to the relay so the access can be revoked at Google.
* SitesSaver Terms of Use: https://sitessaver.com/terms.html
* SitesSaver Privacy Policy: https://sitessaver.com/privacy.html

**Requests to your own site**

To run long backups and restores in the background, and to check once a day whether the backup folder can be reached from the web, the plugin sends requests to your own site address. These do not go to any third party.

**PayPal donation button**

The Help page has an optional "Buy Me A Coffee" donation button. Nothing is sent to PayPal unless you click it, which opens PayPal in a new tab. PayPal terms: https://www.paypal.com/legalhub/home and privacy: https://www.paypal.com/myaccount/privacy/privacyhub

== Changelog ==

= 1.6.2 =
* Changed: plugin, theme and media files now go straight into the backup ZIP. No loose copies are made on disk while a backup runs.
* Changed: the activity log and restore progress are stored as plain data files with random names instead of PHP files.
* Changed: every backup you upload or download from Google Drive is stored under a name with a random part. Incremental chains still restore.
* Changed: if SitesSaver is installed twice, the extra copy is no longer deactivated for you. A notice lets you deactivate it with one click.
* Changed: SitesSaver notices only show on SitesSaver screens and, where needed, the Plugins and Permalinks screens.
* Fixed: input from the browser and server is sanitized everywhere.

= 1.6.1 =
* Fixed: restores on hosts with security software were sometimes reported as a damaged backup. The plugin now says when the server blocked the restore.
* Fixed: database problems that could silently lose data or break a move between servers.
* Fixed: moving a site away from a managed host.
* Improved: clearer messages when the server gets in the way.

= 1.6.0 =
* New: cloud storage add-ons can plug into SitesSaver through the `sitessaver_destinations` filter.
* Security: hardening.

= 1.5.5 =
* Security: on multisite, only a super admin can use SitesSaver.
* Security: hardening.

= 1.5.4 =
* New: choose who gets the "backup is ready" email, or turn it off.
* Several smaller fixes.

= 1.5.3 =
* The Backups page counts Google Drive backups.
* Calmer backup folder check.

= 1.5.2 =
* Google Drive upload progress shows one percentage.

= 1.5.1 =
* A failed restore now puts your files back too.
* Scheduled backups upload to Google Drive in slices.
* Warning when the backup folder is reachable from the web.

== Upgrade Notice ==

= 1.6.2 =
Backups are written straight into the ZIP and work files are no longer PHP files. Recommended for all users.

= 1.6.1 =
Restores on hosts with security software are reported honestly, and several database fixes make moves between servers safer.
