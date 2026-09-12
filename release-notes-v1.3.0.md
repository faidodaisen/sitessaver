# SitesSaver 1.3.0

Branded backup emails, and automatic updates straight from GitHub.

## Automatic updates without the WordPress plugin directory

SitesSaver is installed manually, so WordPress previously had no way to tell you a new version existed. It does now.

The plugin checks its GitHub releases roughly every six hours and, when a newer version is tagged, shows an update on your **Plugins** screen exactly like any other plugin. One click to update, and your backups, schedule, and Google Drive connection are all preserved. A dismissible banner also appears on the dashboard, so an update is not missed by someone who rarely visits the Plugins page.

**Settings → Plugin Updates** controls all of it:

| Setting | What it does |
|---|---|
| Automatic Checks | Turn the GitHub lookup on or off |
| Repository | Which repo to track, in `owner/name` form. A full GitHub URL is accepted and trimmed |
| Release Channel | Opt in to pre-releases. Off by default, stable only |
| Access Token | Only needed for a private repository. A fine-grained token with Contents: read |
| Check for updates now | Forces an immediate lookup instead of waiting for the next cycle |

Details worth knowing:

- The built release asset (`SitesSaver-x.y.z.zip`) is preferred over GitHub's generated source zipball, because the asset already has the correct folder name and excludes tests and build tools.
- If only a zipball is available, its `owner-repo-sha` folder is renamed back to `sitessaver` during install. Without that step WordPress would install into a new folder and deactivate your existing copy.
- Drafts are never offered, and a re-tagged identical version does not produce a phantom update.
- A token is only ever attached to requests aimed at your own configured repository.
- Failed lookups are cached for 30 minutes rather than 6 hours, so a brief GitHub outage does not hide an update for the rest of the day.

## Branded HTML notification emails

Scheduled backup reports were a few lines of plain text. They are now a designed HTML email carrying your branding.

Each report shows the status as a coloured badge, a table of backup details, and a **Where this backup is stored** section with one card per destination, including a direct link to your Google Drive folder. Buttons link to your Backups and Schedule screens.

**Settings → Email Branding** controls the appearance: logo (picked from the Media Library or pasted as a URL), accent colour, sender name and address, an optional support link, and a footer note. **Send test email** saves your changes and delivers a real sample so you can see the result before the next backup runs.

Turning HTML off falls back to plain text. A plain-text alternative is generated from the same data either way, so text-only clients and spam filters always see real content.

## Richer report content

Both formats now include information the old email omitted:

- Which schedule triggered the run, and what the archive contains
- Whether the local copy was kept or removed per your storage settings
- Google Drive upload status with the folder link, or an explicit failure notice with the reason when an upload did not succeed
- Your retention policy and the time of the next scheduled backup

A failed backup email now lists the common causes (disk space, PHP limits, an expired Drive connection) and reassures you that previous backups are untouched.

## Notes

- Email header injection is blocked: newlines are stripped from the sender name, and an invalid accent colour falls back to the default rather than corrupting the template.
- The `wp_mail_content_type` filter is added and removed around a single send inside a `finally` block, so SitesSaver never changes the format of mail sent by other plugins.
- Release notes rendered in the "View details" modal are escaped before limited Markdown is reintroduced, so a malformed note cannot inject markup into wp-admin.
- New regression suite `tests/test-updater.php` covers release selection, package preference, caching, token scoping, and the folder rename: 66 assertions. The full suite is now 166 assertions across five files.
- `tests/live-check-github.php` is a manual smoke test that resolves the configured repo against the real GitHub API and verifies the downloaded package would actually install.
