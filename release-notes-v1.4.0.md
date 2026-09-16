# SitesSaver 1.4.0 — Incremental scheduled backups

Scheduled backups can now archive only the files that changed since the previous run. On a site
with a large media library that turns a nightly backup from hours and gigabytes into minutes and
megabytes.

It is **off by default**, **free**, and the manual Export button is untouched.

## Incremental mode

Schedule has a new **Backup Mode** choice: *Full every time* (what your site does today, and
still the default) or *Incremental*.

In incremental mode each scheduled run archives only the files whose size or modification date
differs from the previous backup. The database is always dumped in full, so a restore never
replays database changes — it imports one SQL file, exactly as it always has.

Two settings sit under the mode:

- **Start a new full backup every N runs** (default 7). A shorter chain restores faster and
  limits how much one damaged archive can cost you; a longer one saves more disk.
- **Change detection** — *Fast* (size and modified date; right for almost every site) or
  *Thorough* (verifies contents with a checksum). Choose Thorough if your host or deploy process
  rewrites file timestamps, which would otherwise make unchanged files look changed and inflate
  every backup.

SitesSaver also starts a fresh full backup on its own when the incrementals have added up to more
than half the size of the full backup, when the previous backup has gone missing, or when its
index cannot be read. Every one of those degrades to a *larger* backup, never a failed one.

## Restoring

Restoring an incremental backup replays its chain for you: each backup is applied oldest-first,
deletions recorded along the way are applied in order, and the result goes through exactly the
same restore path a full backup uses. Restore to an earlier point in a chain and files that were
deleted after it come back, as they should.

If a backup the chain needs is missing, the restore is refused **before** anything on your live
site is touched, and tells you which files it needs. A half-applied restore is worse than none.

Incremental archives are self-describing — the restore set travels inside the ZIP — so they still
restore after a reinstall, a migration, or a database restore that wiped the plugin's settings.

## Retention now counts restore points

Worth reading if you use incremental mode.

Retention used to delete the oldest backup files by count. With chains that rule would eventually
delete the full backup holding a chain up and leave a pile of incrementals that restore to
nothing — while the Backups list still showed the number of backups you asked for.

Retention now counts **restore points**. A restore point is a full backup plus everything built
on it, and those are deleted together. A chain counts as being as recent as its newest backup, so
a chain still in use never ages out from underneath you. Keeping 5 restore points can therefore
mean more than 5 files in the folder — that is intended, and the Schedule screen says so.

Deleting a full backup that incrementals depend on now warns you exactly how many backups it
would leave unrestorable.

## Backups list

Chain members are labelled *Full* or *Incremental*. An incremental tells you how many files a
restore needs; a full tells you how many incrementals are built on it. Backups belonging to no
chain — every manual export, and everything created before this release — look exactly as before.

## Unchanged on purpose

- The **Export** button always produces a full, standalone backup. It builds no index and joins
  no chain, so a backup you take by hand is always a file you can carry anywhere.
- Backups made before 1.4.0 restore through the unchanged full-backup path.

## Also in this release

Multisite fixes. On a network, every subsite previously shared one storage directory, one table
list, and one uploads root — so a subsite backup swept up the whole network and a subsite restore
could overwrite its siblings. Storage is now per site, the table list excludes network-global and
other subsites' tables, and uploads are scoped to the site being backed up.

## Install

Download `SitesSaver-1.4.0.zip` below, then **Plugins → Add New → Upload Plugin**. Existing
installs get the update through the normal Plugins screen.

Requires PHP 8.1+, WordPress 6.0+, and the ZipArchive PHP extension.
