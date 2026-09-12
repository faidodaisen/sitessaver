# SitesSaver 1.3.1

Removes a settings panel that should never have been a setting, and brings the Help page in line with what the plugin actually does.

## Plugin Updates is no longer configurable

1.3.0 shipped a Plugin Updates panel with fields for the repository, a release channel, and an access token. That was a mistake. Which repository the plugin updates from is a property of the build, not a preference: a site owner has no reason to change it, and every one of those fields was an invitation to point a production install somewhere wrong or to switch on beta releases without knowing what that meant.

The repository is now fixed in the plugin. What remains is a status block showing the version you are running, and a button to check immediately rather than waiting for the automatic check. When an update is available, that block turns into a prompt with a link to the Plugins screen and the release notes.

Nothing about update behaviour changed. Checks still run automatically, updates still appear on the Plugins screen, and the private-repository support is still there for anyone who forks the plugin, now reachable in code instead of through the UI:

```php
add_filter('sitessaver_update_config', function (array $config): array {
    $config['repo']  = 'your-org/your-fork';
    $config['token'] = 'github_pat_...';   // private repos only
    return $config;
});
```

The `sitessaver_update_source` option still works too, for setups that prefer WP-CLI or a mu-plugin.

## Help page rewritten

The manual still described a five-feature plugin. It now covers nine topics, including everything added in 1.2 and 1.3:

- Restoring directly from a backup stored in Google Drive, and why you are signed out after restoring a backup from another domain
- What retention actually deletes, and the fact that it counts manual backups too
- Using "Run backup now" to test a schedule without waiting for cron
- What the notification email tells you, including the difference between a failed backup and a successful backup whose Drive upload failed
- Branding those emails, and why the sender address should be on your own domain
- How updates reach a manually installed plugin, and what survives one
- Where backups live on disk, why that is outside the plugin folder, and what Nginx users need to do about it

## Fixed — two descriptions that were not true

The Schedule page said retention kept "scheduled backups... counted across all frequencies". It actually applies to every backup in the storage folder, manual ones included, so a low retention value could delete a manual backup the user expected to keep. The wording now says so.

The Email Notification field said only "Receive an email after each scheduled backup completion". It now describes what the report contains and links to the branding panel.

## Notes

- `Updater::REPO` is the single source of truth for the release repository. `Updater::config()` reads it, then allows the option and the `sitessaver_update_config` filter to override, with a malformed filter return falling back to defaults rather than breaking update checks.
- The `sitessaver_save_update_source` AJAX endpoint was removed along with the form it served.
- `tests/test-updater.php` grew to 72 assertions, covering the hardcoded default, the option override, the filter override, and a filter returning a non-array. The suite is now 172 assertions across five files.
