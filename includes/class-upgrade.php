<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Reads the first bytes of the plugin's own bookkeeping files inside its storage folder to confirm they are files SitesSaver wrote before deleting them.

/**
 * One-time housekeeping after the plugin is updated.
 *
 * Versions up to 1.6.1 kept their bookkeeping in PHP files inside the
 * storage folder: an `index.php` guard in each folder, the troubleshooting
 * log `logs/sitessaver-log.php` (and `.1.php`) and restore job state
 * `jobs/restore-<id>.php`, each behind an exit guard. 1.6.2 and later write
 * index.html guards, `.log` files and `.json` job state instead and no
 * longer read the old files. This removes the old files once, and only
 * when their content is exactly what SitesSaver wrote. Backups are never
 * touched. A restore that was running across the update is not resumed.
 */
final class Upgrade {

    /** Network-wide (the storage folder is shared by every site). */
    public const OPTION = 'sitessaver_version';

    /** First line of the pre-1.6.2 log and job files. */
    private const LEGACY_GUARD = "<?php exit; ?>\n";

    /** The whole pre-1.6.2 index.php guard. */
    private const LEGACY_INDEX = '<?php // Silence is golden.';

    public static function maybe_run(): void {
        $stored = (string) get_site_option(self::OPTION, '');
        if ($stored === SITESSAVER_VERSION) {
            return;
        }

        $removed = self::cleanup_legacy_files();

        update_site_option(self::OPTION, SITESSAVER_VERSION);

        if ($removed > 0) {
            Log::info('upgrade_cleanup', sprintf('Removed %d files left by an older SitesSaver version.', $removed), [
                'from' => $stored === '' ? 'before 1.6.2' : $stored,
                'to'   => SITESSAVER_VERSION,
            ]);
        }
    }

    /**
     * Delete the pre-1.6.2 PHP files SitesSaver wrote. Returns how many.
     */
    public static function cleanup_legacy_files(): int {
        $roots = [];
        foreach ([sitessaver_storage_root(), WP_CONTENT_DIR . '/sitessaver-backups'] as $root) {
            $root = untrailingslashit(wp_normalize_path((string) $root));
            if ($root !== '' && is_dir($root) && !in_array($root, $roots, true)) {
                $roots[] = $root;
            }
        }
        // Multisite subsites keep their own folders under site-<id>/.
        foreach ($roots as $root) {
            foreach ((array) glob($root . '/site-*', GLOB_ONLYDIR) as $sub) {
                if (is_string($sub)) {
                    $roots[] = wp_normalize_path($sub);
                }
            }
        }

        $removed = 0;
        foreach ($roots as $root) {
            // index.php guards: the root and each folder one level down
            // (tmp, logs, jobs, the index cache), plus tmp/chunks.
            $dirs = [$root, $root . '/tmp/chunks'];
            foreach ((array) glob($root . '/*', GLOB_ONLYDIR) as $sub) {
                if (is_string($sub)) {
                    $dirs[] = wp_normalize_path($sub);
                }
            }
            foreach ($dirs as $dir) {
                $index = $dir . '/index.php';
                if (is_file($index) && trim(self::head($index, 64)) === self::LEGACY_INDEX) {
                    wp_delete_file($index);
                    if (!is_file($index)) {
                        $removed++;
                        // Put the current guard (index.html, .htaccess, web.config) in its place.
                        sitessaver_protect_directory($dir);
                    }
                }
            }

            foreach (['sitessaver-log.php', 'sitessaver-log.1.php'] as $name) {
                $removed += self::delete_if_guarded($root . '/logs/' . $name, false);
            }

            foreach ((array) glob($root . '/jobs/restore-*.php') as $job) {
                if (is_string($job)) {
                    $removed += self::delete_if_guarded($job, true);
                }
            }
        }

        return $removed;
    }

    /**
     * Delete a pre-1.6.2 log or job file if it starts with the exit guard
     * and holds only what SitesSaver wrote (JSON lines, or one JSON job).
     */
    private static function delete_if_guarded(string $file, bool $job): int {
        if (!is_file($file)) {
            return 0;
        }
        $raw = (string) file_get_contents($file);
        if (!str_starts_with(str_replace("\r\n", "\n", $raw), self::LEGACY_GUARD)) {
            return 0;
        }
        $body = substr(str_replace("\r\n", "\n", $raw), strlen(self::LEGACY_GUARD));

        if ($job) {
            $data = json_decode($body, true);
            if (!is_array($data) || !isset($data['id'])) {
                return 0;
            }
        } else {
            foreach (explode("\n", $body) as $line) {
                $line = trim($line);
                if ($line !== '' && !is_array(json_decode($line, true))) {
                    return 0;
                }
            }
        }

        wp_delete_file($file);
        return is_file($file) ? 0 : 1;
    }

    private static function head(string $file, int $bytes): string {
        $h = fopen($file, 'rb');
        if ($h === false) {
            return '';
        }
        $data = (string) fread($h, $bytes + 1);
        fclose($h);
        // A longer file is not the guard SitesSaver wrote.
        return strlen($data) > $bytes ? '' : $data;
    }
}
