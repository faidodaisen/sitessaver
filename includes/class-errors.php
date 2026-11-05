<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Plain-language error messages.
 *
 * The people who see these are site owners, not developers. A notice that
 * says "An error occurred" or "Failed to extract backup archive." leaves them
 * guessing whether their site is now broken. Every message here answers the
 * three questions they actually have, in order:
 *
 *   title   — what happened, in a few words;
 *   message — whether anything on the site changed (the first worry);
 *   hint    — what to do next.
 *
 * The technical detail is never thrown away: it goes to the troubleshooting
 * log under a reference code, and the notice shows that code plus a
 * collapsed "Technical details" line for whoever ends up fixing it.
 *
 * The same catalogue is handed to the admin JS, so failures that only the
 * browser can see (a dropped connection, a gateway timeout) read the same
 * way as failures the server reports.
 */
final class Errors {

    /**
     * @return array<string, array{title: string, message: string, hint: string, log?: bool}>
     */
    public static function catalogue(): array {
        $nothing_changed = __('Nothing on your site was changed.', 'sitessaver');

        return [
            // ---- Upload -------------------------------------------------
            'upload_interrupted' => [
                'title'   => __('The upload didn’t finish', 'sitessaver'),
                'message' => __('The connection to your server dropped partway through the upload.', 'sitessaver') . ' ' . $nothing_changed,
                'hint'    => __('Try again. If it keeps stopping, upload the backup file into wp-content/sitessaver-backups/ with your hosting File Manager or FTP, then restore it from the Backups page.', 'sitessaver'),
            ],
            'upload_page_outdated' => [
                'title'   => __('This page needs a refresh', 'sitessaver'),
                'message' => __('SitesSaver was updated while this page was open, so the upload couldn’t continue.', 'sitessaver') . ' ' . $nothing_changed,
                'hint'    => __('Reload the page and upload the file again.', 'sitessaver'),
            ],
            'upload_not_zip' => [
                'title'   => __('This isn’t a SitesSaver backup', 'sitessaver'),
                'message' => __('The file you chose isn’t a ZIP archive, so it can’t be restored.', 'sitessaver') . ' ' . $nothing_changed,
                'hint'    => __('Choose the .zip file SitesSaver created when the backup was made.', 'sitessaver'),
            ],
            'upload_disk_full' => [
                'title'   => __('Your server is out of space', 'sitessaver'),
                'message' => __('There isn’t enough free disk space on your hosting account to store this backup.', 'sitessaver') . ' ' . $nothing_changed,
                'hint'    => __('Delete old backups on the Backups page, or ask your host for more space, then try again.', 'sitessaver'),
            ],
            'upload_failed' => [
                'title'   => __('The upload didn’t finish', 'sitessaver'),
                'message' => __('Your server couldn’t save the uploaded file.', 'sitessaver') . ' ' . $nothing_changed,
                'hint'    => __('Try again. If it happens again, share the reference code below with whoever looks after your site.', 'sitessaver'),
            ],
            'import_cancelled' => [
                'title'   => __('Import cancelled', 'sitessaver'),
                'message' => __('You stopped the upload.', 'sitessaver') . ' ' . $nothing_changed,
                'hint'    => '',
                'log'     => false,
            ],

            // ---- Restore ------------------------------------------------
            'restore_file_missing' => [
                'title'   => __('We couldn’t find that backup', 'sitessaver'),
                'message' => __('The backup file is no longer on the server.', 'sitessaver') . ' ' . $nothing_changed,
                'hint'    => __('Refresh the Backups page and choose the backup again.', 'sitessaver'),
            ],
            'restore_damaged' => [
                'title'   => __('This backup can’t be opened', 'sitessaver'),
                'message' => __('The backup file seems damaged or incomplete, so the restore stopped before changing anything.', 'sitessaver'),
                'hint'    => __('Download the backup again from the site it was made on and upload that copy.', 'sitessaver'),
            ],
            'restore_chain_incomplete' => [
                'title'   => __('Part of this backup is missing', 'sitessaver'),
                'message' => __('This backup only holds the changes since an earlier backup, and that earlier backup is no longer on the server.', 'sitessaver') . ' ' . $nothing_changed,
                'hint'    => __('Restore a full backup instead, or put the missing backups back into the Backups folder.', 'sitessaver'),
            ],
            'restore_wrong_site' => [
                'title'   => __('This backup belongs to another site', 'sitessaver'),
                'message' => __('It was made on a different site in your network, so it can’t be restored here.', 'sitessaver') . ' ' . $nothing_changed,
                'hint'    => __('Restore it from the site it was made on.', 'sitessaver'),
            ],
            'restore_write_blocked' => [
                'title'   => __('Your server blocked the restore', 'sitessaver'),
                'message' => __('Your backup file is fine. The server would not let SitesSaver write the files it needs, so the restore stopped before changing anything.', 'sitessaver'),
                'hint'    => __('This is almost always hosting security software. If your cPanel has Imunify360, open it → Proactive Defense and set the mode to “Log only” (or add wp-content/plugins/sitessaver/ to its Ignore list), run the restore, then switch it back. No such option? Send your host the reference code below and ask them to allow SitesSaver to write to wp-content for a few minutes.', 'sitessaver'),
            ],
            'export_write_blocked' => [
                'title'   => __('Your server blocked the backup', 'sitessaver'),
                'message' => __('The server would not let SitesSaver write the backup file. Your site itself is fine — nothing was changed.', 'sitessaver'),
                'hint'    => __('This is usually hosting security software or folder permissions. If your cPanel has Imunify360, set Proactive Defense to “Log only” while the backup runs, or ask your host to allow writes to wp-content/sitessaver-backups/. The reference code below shows the exact reason.', 'sitessaver'),
            ],
            'restore_db_privileges' => [
                'title'   => __('Your database user lacks a permission', 'sitessaver'),
                'message' => __('The database account your site uses is not allowed to create, change, rename or remove tables, which a restore needs. The restore stopped before changing anything.', 'sitessaver'),
                'hint'    => __('In cPanel → MySQL Databases, give the database user named in wp-config.php ALL PRIVILEGES on its database (or ask your host to), then run the restore again. The exact permission is named under Technical details.', 'sitessaver'),
            ],
            'restore_db_packet' => [
                'title'   => __('Your database server refused a large row', 'sitessaver'),
                'message' => __('One row in the backup is bigger than this database server accepts in one go. The restore stopped before changing anything, so no data was lost.', 'sitessaver'),
                'hint'    => __('Ask your host to raise the MySQL max_allowed_packet setting (64 MB is common), then run the restore again. The table and the sizes are under Technical details.', 'sitessaver'),
            ],
            'restore_disk_full' => [
                'title'   => __('Your server ran out of space', 'sitessaver'),
                'message' => __('There wasn’t enough free disk space to unpack the backup, so the restore stopped.', 'sitessaver'),
                'hint'    => __('Free up space (old backups on the Backups page are a good start) and run the restore again.', 'sitessaver'),
            ],
            'restore_stalled' => [
                'title'   => __('The restore stopped partway', 'sitessaver'),
                'message' => __('Your server stopped the restore before it finished, so the site may be only partly restored.', 'sitessaver'),
                'hint'    => __('Your backup file is still safe. Run the restore again from the Backups page — it starts over cleanly. If it stops again, ask your host to allow longer-running background tasks. On hosts with Imunify360, its Proactive Defense can end the request without a message: set it to “Log only” while you restore.', 'sitessaver'),
            ],
            'restore_failed_early' => [
                'title'   => __('The restore couldn’t start', 'sitessaver'),
                'message' => __('Something went wrong while preparing the backup.', 'sitessaver') . ' ' . $nothing_changed,
                'hint'    => __('Try again. If it happens again, share the reference code below with whoever looks after your site.', 'sitessaver'),
            ],
            'restore_rolled_back' => [
                'title'   => __('The restore didn’t finish — your site was put back', 'sitessaver'),
                'message' => __('Something went wrong part-way through, so SitesSaver switched your previous database back in and returned the files it had replaced. Your posts, pages, users, settings, images, plugins and themes are as they were before the restore.', 'sitessaver'),
                'hint'    => __('Run the restore again. If it fails a second time, download the log from Help → Troubleshooting Log and send it to SitesSaver support.', 'sitessaver'),
            ],
            'restore_failed' => [
                'title'   => __('The restore didn’t finish', 'sitessaver'),
                'message' => __('Something went wrong while putting your site back, so it may be only partly restored.', 'sitessaver'),
                'hint'    => __('Your backup file is still safe. Run the restore again. If it fails a second time, share the reference code below with whoever looks after your site.', 'sitessaver'),
            ],
            'remote_download_failed' => [
                'title'   => __('We couldn’t download the backup from cloud storage', 'sitessaver'),
                'message' => __('The file didn’t come through from your cloud storage.', 'sitessaver') . ' ' . $nothing_changed,
                'hint'    => __('Check that the storage account is still connected in Settings, then try again.', 'sitessaver'),
            ],
            'gdrive_download_failed' => [
                'title'   => __('We couldn’t download the backup from Google Drive', 'sitessaver'),
                'message' => __('The file didn’t come through from Google Drive.', 'sitessaver') . ' ' . $nothing_changed,
                'hint'    => __('Check that Google Drive is still connected in Settings, then try again.', 'sitessaver'),
            ],

            // ---- Backup -------------------------------------------------
            'export_stalled' => [
                'title'   => __('The backup stopped partway', 'sitessaver'),
                'message' => __('Your server stopped the backup before it finished, even after SitesSaver restarted it a few times. Your site itself is fine — nothing was changed.', 'sitessaver'),
                'hint'    => __('Try again. If it stops again, open Help → Troubleshooting Log, download the log and send it to SitesSaver support — it shows exactly where it stopped.', 'sitessaver'),
            ],
            'export_stalled_file' => [
                'title'   => __('The backup got stuck on one large file', 'sitessaver'),
                'message' => __('Copying one very large file took longer than your server allows. Your site itself is fine — nothing was changed.', 'sitessaver'),
                'hint'    => __('The file is named under Technical details. If it is an old backup, video or archive you don’t need in the backup, move it out of the uploads folder and try again. Otherwise, download the log from Help → Troubleshooting Log and send it to SitesSaver support.', 'sitessaver'),
            ],
            'export_killed' => [
                'title'   => __('Your server stopped the backup', 'sitessaver'),
                'message' => __('The server ended the backup because it hit one of its limits (time or memory). Your site itself is fine — nothing was changed.', 'sitessaver'),
                'hint'    => __('Try again. If it happens again, download the log from Help → Troubleshooting Log and send it to SitesSaver support — it names the limit that was hit.', 'sitessaver'),
            ],
            'export_failed' => [
                'title'   => __('The backup didn’t finish', 'sitessaver'),
                'message' => __('Something went wrong while creating the backup. Your site itself is fine — nothing was changed.', 'sitessaver'),
                'hint'    => __('Try again. If it happens again, share the reference code below with whoever looks after your site.', 'sitessaver'),
            ],
            'export_cancelled' => [
                'title'   => __('Backup cancelled', 'sitessaver'),
                'message' => __('You stopped the backup. Your site itself is unchanged.', 'sitessaver'),
                'hint'    => '',
                'log'     => false,
            ],

            // ---- Connection / session ----------------------------------
            'session_expired' => [
                'title'   => __('You’ve been signed out', 'sitessaver'),
                'message' => __('Your login session ended, so SitesSaver couldn’t continue.', 'sitessaver'),
                'hint'    => __('Reload the page, sign in again, and retry.', 'sitessaver'),
            ],
            'server_timeout' => [
                'title'   => __('The server took too long to answer', 'sitessaver'),
                'message' => __('Your server didn’t reply in time. The job may still be running in the background.', 'sitessaver'),
                'hint'    => __('Wait a minute, then reload this page to see where things stand.', 'sitessaver'),
            ],
            'server_error' => [
                'title'   => __('The server ran into a problem', 'sitessaver'),
                'message' => __('Your server replied with an error instead of an answer.', 'sitessaver'),
                'hint'    => __('Try again. If it keeps happening, share the reference code below with whoever looks after your site.', 'sitessaver'),
            ],
            'request_blocked' => [
                'title'   => __('A security filter on your server blocked the request', 'sitessaver'),
                'message' => __('Your server’s firewall (for example ModSecurity, Imunify360 or Cloudflare) refused a SitesSaver request before WordPress saw it.', 'sitessaver'),
                'hint'    => __('Ask your host to whitelist requests to wp-admin/admin-ajax.php for this site (the blocked rule is often named under Technical details), or pause the firewall while you back up or restore.', 'sitessaver'),
            ],
            'request_too_large' => [
                'title'   => __('Your server refused a request as too large', 'sitessaver'),
                'message' => __('The web server or a proxy in front of it limits how much data one request may carry, and a SitesSaver request went over it.', 'sitessaver'),
                'hint'    => __('Ask your host to raise the request body limit (LimitRequestBody, client_max_body_size, or the Cloudflare plan limit) to at least 10 MB. Uploading the backup into wp-content/sitessaver-backups/ with FTP also works.', 'sitessaver'),
            ],
            'connection_lost' => [
                'title'   => __('Connection lost', 'sitessaver'),
                'message' => __('Your browser lost contact with the server.', 'sitessaver'),
                'hint'    => __('Check your internet connection, then reload this page.', 'sitessaver'),
            ],
            'generic' => [
                'title'   => __('Something went wrong', 'sitessaver'),
                'message' => __('SitesSaver couldn’t finish that.', 'sitessaver'),
                'hint'    => __('Try again. If it happens again, share the reference code below with whoever looks after your site.', 'sitessaver'),
            ],
        ];
    }

    /**
     * @return array{title: string, message: string, hint: string, log?: bool}
     */
    public static function get(string $code): array {
        $all = self::catalogue();
        return $all[$code] ?? $all['generic'];
    }

    /**
     * The JSON shape every SitesSaver error response uses. `message` is the
     * human sentence, so older JS that only reads `.message` still shows
     * something a site owner understands.
     *
     * @return array<string, mixed>
     */
    public static function payload(string $code, string $technical = '', ?string $ref = null): array {
        $e = self::get($code);
        return [
            'code'    => isset(self::catalogue()[$code]) ? $code : 'generic',
            'title'   => $e['title'],
            'message' => $e['message'],
            'hint'    => $e['hint'],
            'detail'  => $technical,
            'ref'     => $ref,
            'logged'  => $ref !== null,
        ];
    }

    /**
     * Log a failure and build its payload in one step.
     *
     * @return array<string, mixed>
     */
    public static function report(string $code, string $technical, array $context = []): array {
        $e   = self::get($code);
        $ref = ($e['log'] ?? true) ? Log::error($code, $technical !== '' ? $technical : $e['title'], $context) : null;
        return self::payload($code, $technical, $ref);
    }

    /**
     * Map a restore failure's technical message onto a catalogue code.
     *
     * @param string $phase The restore phase that was running when it failed;
     *                      before files or the database are touched, the user
     *                      can be told nothing changed.
     */
    public static function classify_restore(string $technical, string $phase = ''): string {
        $t = strtolower($technical);

        if (str_contains($t, 'no space left') || str_contains($t, 'disk full') || str_contains($t, 'disk quota')) {
            return 'restore_disk_full';
        }
        // Before "failed to extract": a write the server refused is not a
        // damaged backup. Calling it one sent users re-downloading a good file.
        if (str_contains($t, Environment::WRITE_BLOCKED) || str_contains($t, 'permission denied')
            || str_contains($t, 'operation not permitted') || str_contains($t, 'read-only file system')) {
            return 'restore_write_blocked';
        }
        if (str_contains($t, '[database packet limit]')) {
            return 'restore_db_packet';
        }
        if (str_contains($t, '[database privileges]') || str_contains($t, 'command denied to user')) {
            return 'restore_db_privileges';
        }
        if (str_contains($t, 'backup file not found')) {
            return 'restore_file_missing';
        }
        if (str_contains($t, 'cannot be restored on its own') || str_contains($t, 'chain are missing')) {
            return 'restore_chain_incomplete';
        }
        if (str_contains($t, 'failed to extract') || str_contains($t, 'manifest.json') || str_contains($t, 'invalid backup')
            || str_contains($t, 'not a valid') || str_contains($t, 'corrupt')) {
            return 'restore_damaged';
        }
        if (str_contains($t, 'another site') || str_contains($t, 'different site') || str_contains($t, 'refused to import') || str_contains($t, 'refusing to import')) {
            return 'restore_wrong_site';
        }
        if (str_contains($t, 'google drive')) {
            return 'gdrive_download_failed';
        }

        return in_array($phase, ['', 'queued', 'download', 'extract', 'manifest'], true)
            ? 'restore_failed_early'
            : 'restore_failed';
    }

    /**
     * Map a backup failure's technical message onto a catalogue code.
     */
    public static function classify_export(string $technical): string {
        $t = strtolower($technical);
        if (str_contains($t, 'no space left') || str_contains($t, 'disk quota')) {
            return 'upload_disk_full';
        }
        if (str_contains($t, Environment::WRITE_BLOCKED) || str_contains($t, 'permission denied')
            || str_contains($t, 'operation not permitted') || str_contains($t, 'read-only file system')) {
            return 'export_write_blocked';
        }
        return 'export_failed';
    }

    /**
     * Catalogue for the admin JS (titles/messages/hints only).
     *
     * @return array<string, array{title: string, message: string, hint: string, log: bool}>
     */
    public static function for_js(): array {
        $out = [];
        foreach (self::catalogue() as $code => $e) {
            $out[$code] = [
                'title'   => $e['title'],
                'message' => $e['message'],
                'hint'    => $e['hint'],
                'log'     => $e['log'] ?? true,
            ];
        }
        return $out;
    }
}
