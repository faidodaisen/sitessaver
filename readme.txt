=== SitesSaver ===
Contributors: fidodesign
Tags: backup, restore, migration, google drive, schedule
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.6.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backup, restore and migration of your database and wp-content, with schedules and Google Drive. No file size limit and no paid version.

== Description ==

SitesSaver puts the content of your WordPress site, the database and the wp-content folder, into one ZIP. You can download it, keep it on the server, send it to your own Google Drive, and restore it on the same site or on a new domain.

Everything in the plugin is free. There is no file size limit, no license key and no locked feature.

**What it does**

* Backup: the database and the wp-content folder (uploads, plugins, themes and other files there) go into one ZIP. WordPress core files and wp-config.php are not included; the site you restore onto has its own. Cache folders, logs and SitesSaver's own folder are left out.
* Restore: drag a backup ZIP onto the Import screen, or restore one that is already on the server or in Google Drive. If a restore fails, the previous database and files are put back.
* Migration: restore onto a new domain and the URLs are rewritten everywhere, including inside serialized data. A different table prefix on the new site is handled too.
* Scheduled backups: hourly to monthly, with several frequencies running at once. Optional incremental mode, retention, and a private trigger URL for real server cron.
* Google Drive: send backups to your own Drive and restore straight from it. Add-on plugins can register more cloud storage through the `sitessaver_destinations` filter.
* Strict hosts: backups and restores run in short background slices, so a host that stops every request after 30 seconds cannot cut them short. You can close the page while a backup runs and get an email when it is done.
* Email reports: a report after each scheduled run, with your own logo, colours and sender.

**Good to know**

* Backups are stored in `wp-content/sitessaver-backups/`, outside the plugin folder, so updating or removing the plugin does not delete them.
* On Nginx, block web access to that folder in the server config. Help > Where are my backups stored? shows whether it is reachable and gives the exact rule.
* WP-Cron only runs when someone visits the site. For dependable schedules, use the Server Cron panel on the Schedule page.
* On multisite, the plugin works per site: each site has its own SitesSaver menu and its own backup folder (`sitessaver-backups/site-<id>/`), and there is no network admin screen. Only a super admin can use it; the `sitessaver_capability` filter changes this.
* Restore needs a MySQL or MariaDB database. On a site that uses SQLite (such as WordPress Playground) backups work, and restore is switched off with a notice.

Full documentation: [sitessaver.com/docs.html](https://sitessaver.com/docs.html)

== Installation ==

1. In wp-admin, go to Plugins > Add New, search for SitesSaver, then click Install Now and Activate. You can also upload the plugin ZIP from Plugins > Add New > Upload Plugin.
2. Open the SitesSaver menu. It has Backups, Export, Import, Schedule, Settings and Help.
3. Click Export to make your first backup. To move a site, install SitesSaver on the new site and drop the backup ZIP onto its Import screen.

Requirements: PHP 8.1 or newer, WordPress 6.0 or newer. To restore, the ZipArchive PHP extension (enabled on most hosts) and a MySQL or MariaDB database.

== Frequently Asked Questions ==

= Is there a file size limit? =

No. Large sites are backed up and restored in short background slices, so the size is limited only by your disk space and your host.

= Is there a paid version? =

Not today. Everything the plugin does is free and unrestricted. Separate add-on plugins for other storage services may be offered later. Nothing that works for free now will be moved behind a payment.

= Where are my backups stored? =

In `wp-content/sitessaver-backups/` on your own server, and in your own Google Drive if you connect it. Backups are never sent to the plugin author.

The folder has an `.htaccess` and a `web.config` that block web access on Apache, LiteSpeed and IIS. On Nginx, add this to your server block so backups cannot be downloaded by URL:

`location ^~ /wp-content/sitessaver-backups/ { deny all; }`

= Do I need Google Drive? =

No. Google Drive is optional. Local backups, restores and migration work without it and never contact any outside service.

= My host stops long requests. Will backups still finish? =

Yes. Backups and restores run as a chain of short background requests to your own site. If your host blocks those requests, the plugin tells you so and falls back to running in the open browser tab.

= What happens if a restore fails halfway? =

The previous database is kept until the restore has finished, and the files it replaced are put back. Your site goes back to how it was before the restore started. A damaged backup (a file that does not match its checksum) or a database file with statements a restore never runs is refused the same way.

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

**PayPal donation link**

The Help page has an optional Donate link to PayPal. Nothing is sent to PayPal and nothing is loaded from it unless you click the link, which opens PayPal in a new tab. PayPal terms: https://www.paypal.com/legalhub/home and privacy: https://www.paypal.com/myaccount/privacy/privacyhub

== Third-party resources ==

* Remix Icon 3.5.0 (icon font, a subset with only the icons the plugin uses: `assets/fonts/remixicon.woff2`, `assets/css/icons.css`). Copyright Remix Design. License: Apache License 2.0, see `assets/fonts/remixicon-LICENSE.txt`. Source: https://github.com/Remix-Design/RemixIcon

== Changelog ==

= 1.6.3 =
* Fixed: the plugin always activates. A server without the PHP zip extension gets a notice, and only restore is switched off.
* Changed: higher PHP time and memory limits apply only while a backup, upload or restore step runs, and the previous limits are put back afterwards.
* Changed: a restore writes each file straight from the backup to its place. Files it replaces are kept in a rollback ZIP and put back from it if the restore fails.
* Changed: after a restore, cache plugins are asked to clear their caches through their own functions. SitesSaver no longer deletes other plugins' cache folders.
* Changed: the storage folder is set in one place and can be changed with the sitessaver_storage_dir filter.
* Changed: admin styles only apply inside SitesSaver's own screens.

= 1.6.2 =
* Changed: plugin, theme and media files now go straight into the backup ZIP. No loose copies are made on disk while a backup runs.
* Changed: the activity log and restore progress are stored as plain data files with random names instead of PHP files.
* Changed: every backup you upload or download from Google Drive is stored under a name with a random part. Incremental chains still restore.
* Changed: if SitesSaver is installed twice, the extra copy is no longer deactivated for you. A notice lets you deactivate it with one click.
* Changed: SitesSaver notices only show on SitesSaver screens and, where needed, the Plugins and Permalinks screens.
* Fixed: input from the browser and server is sanitized everywhere.
* Changed: on LiteSpeed, the .htaccess rule for background backups is no longer added automatically. Add or remove it from Help > Background backups on LiteSpeed.
* Changed: SitesSaver no longer changes which image types WordPress accepts for upload.
* Fixed: an incremental backup brought in more than once is found again when its chain is restored.
* Fixed: a large file that changes while a backup runs is skipped and logged instead of stopping the backup.
* Fixed: listing a large media library is split across requests on hosts with short time limits.
* Fixed: uninstall also removes SitesSaver's data from every site of a network.

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

= 1.6.3 =
Activates on every server, keeps rollback files inside a ZIP and no longer touches other plugins' folders. Recommended for all users.

= 1.6.2 =
Backups are written straight into the ZIP and work files are no longer PHP files. Recommended for all users.

= 1.6.1 =
A restore blocked by host security software is reported as blocked, not as a damaged backup. Several database fixes for moves between servers.
