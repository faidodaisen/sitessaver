# SitesSaver

A free WordPress plugin for full-site backup and migration. No restrictions, no file size limits.

## What It Does

- **One-Click Backup** — Creates a complete backup of your entire WordPress site (database + all files) as a single ZIP file
- **Easy Restore** — Drag and drop a backup ZIP to restore your site, or pick from existing backups
- **Scheduled Backups** — Set it and forget it — daily, weekly, or monthly automatic backups with email notifications
- **Google Drive** — Automatically upload backups to your Google Drive for safe cloud storage
- **Backup Manager** — Label, download, and manage all your backups from one dashboard
- **Site Migration** — Move your WordPress site to a new domain — URLs are automatically updated everywhere, including in serialized data

## Requirements

- PHP 8.1+
- WordPress 6.0+
- ZipArchive PHP extension (enabled on most hosts)

## Installation

**Option 1 — Download:**
1. Download the [latest release](https://github.com/faidodaisen/sitessaver/releases)
2. In WordPress admin, go to **Plugins > Add New > Upload Plugin**
3. Upload the ZIP and activate

**Option 2 — Manual:**
1. Clone or copy this repo into `wp-content/plugins/sitessaver/`
2. Activate via **Plugins > Installed Plugins**

No build step needed — just upload and activate.

## Usage

After activation, find **SitesSaver** in your WordPress admin sidebar:

- **Export** — Click to create a new backup
- **Import** — Upload or select a backup to restore
- **Backups** — View, label, download, or delete existing backups
- **Schedule** — Configure automatic backups
- **Settings** — Connect Google Drive and adjust preferences

## Google Drive Integration

To enable automatic cloud backups:

1. Go to **SitesSaver > Settings**
2. Click **Connect Google Drive**
3. Authorize with your Google account
4. SitesSaver will automatically create its own backup folder in your Drive.
5. In **SitesSaver > Schedule**, you can choose to store backups locally, on Google Drive, or both.

## FAQ

**Where are backups stored?**
In `wp-content/sitessaver-backups/` on your server. Google Drive is optional for cloud copies.

**Will it slow down my site?**
Backups run in the background. Scheduled backups use WP Cron so they don't affect page loads.

**Can I migrate to a new domain?**
Yes. Export from the old site, import on the new one — all URLs (including serialized data) are updated automatically.

**Is there a file size limit?**
No. The plugin handles large sites with chunked processing.

## License

GPL-2.0-or-later
