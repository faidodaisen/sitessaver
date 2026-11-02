# SitesSaver

Free WordPress plugin for full-site backup, restore and migration. No file size limit, and nothing is held back for a paid version.

Website and documentation: https://sitessaver.com

## Features

- **Full backup.** The database and every file under your WordPress install go into one ZIP.
- **Restore.** Drag a backup ZIP onto the Import screen, or restore one that is already on the server or in Google Drive. If a restore fails, the previous database and files are put back.
- **Migration.** Restore onto a new domain and the URLs are rewritten everywhere, including inside serialized data. A different table prefix on the new site is handled too.
- **Scheduled backups.** Hourly to monthly, with several frequencies running at once. Optional incremental mode, retention, and a private trigger URL for real server cron.
- **Google Drive.** Send backups to your own Drive and restore straight from it. Add-on plugins can register more cloud storage through the `sitessaver_destinations` filter.
- **Works on strict hosts.** Backups and restores run in short background slices, so a host that stops every request after 30 seconds cannot cut them short. You can close the page while a backup runs and get an email when it is done.
- **Email reports.** A report after each scheduled run, with your own logo, colours and sender.
- **Automatic updates** from GitHub releases, checked against the published SHA-256 checksum.

## Requirements

- PHP 8.1 or newer
- WordPress 6.0 or newer
- The ZipArchive PHP extension, needed to restore (enabled on most hosts)

## Installation

1. Download the latest `SitesSaver-x.y.z.zip` from [Releases](https://github.com/faidodaisen/sitessaver/releases).
2. In wp-admin, open **Plugins > Add New > Upload Plugin**, choose the ZIP and activate.

You can also copy this repository into `wp-content/plugins/sitessaver/`. There is no build step.

After activation, the **SitesSaver** menu has Backups, Export, Import, Schedule, Settings and Help. Updates then appear on the Plugins screen like any other plugin.

## Good to know

- Backups are stored in `wp-content/sitessaver-backups/`, outside the plugin folder, so updating or removing the plugin does not delete them. On Nginx, block web access to that folder in the server config. Help > Where are my backups stored? shows whether it is reachable and gives the exact rule.
- WP-Cron only runs when someone visits the site. For dependable schedules, use the Server Cron panel on the Schedule page.
- Retention only removes backups made by the schedule. Manual exports and uploaded ZIPs stay until you delete them.
- On multisite, only a super admin can use the plugin. The `sitessaver_capability` filter changes this.

Full documentation: https://sitessaver.com/docs.html

Changes per version: [CHANGELOG.md](CHANGELOG.md)

## Support

Open an issue on [GitHub](https://github.com/faidodaisen/sitessaver/issues) or email fidodesign.net@gmail.com. Include the reference code from the error message and the log from Help > Troubleshooting Log.

## License

GPL-2.0-or-later
