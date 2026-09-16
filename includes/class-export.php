<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Export engine — creates full site backup (DB + files → ZIP).
 */
final class Export {

    /**
     * Get the defined steps for the export pipeline.
     *
     * The percentages are a rough map of how long each stage takes, and the
     * LAST step must land on 100. When the backup is also going to Google
     * Drive, the upload happens inside the finalize step and is usually the
     * slowest part of the whole export — so finalize cannot own 100 or the
     * bar sits full and motionless for the entire upload. In that case the
     * local work is compressed into the first 60% and the upload is given a
     * step of its own, which the client fills from real byte progress
     * reported by GDrive::upload().
     *
     * @param string $destination local|gdrive|both
     * @return list<array{id: string, label: string, pct: int, poll?: string}>
     */
    public static function get_steps(string $destination = 'local'): array {
        $uploads_to_drive = in_array($destination, ['gdrive', 'both'], true);

        if (!$uploads_to_drive) {
            return [
                ['id' => 'init',      'label' => __('Initializing...', 'sitessaver'), 'pct' => 5],
                ['id' => 'manifest',  'label' => __('Creating manifest...', 'sitessaver'), 'pct' => 10],
                ['id' => 'db',        'label' => __('Exporting database...', 'sitessaver'), 'pct' => 25],
                ['id' => 'uploads',   'label' => __('Copying uploads...', 'sitessaver'), 'pct' => 45],
                ['id' => 'plugins',   'label' => __('Copying plugins...', 'sitessaver'), 'pct' => 60],
                ['id' => 'themes',    'label' => __('Copying themes...', 'sitessaver'), 'pct' => 75],
                ['id' => 'other',     'label' => __('Copying other files...', 'sitessaver'), 'pct' => 80],
                ['id' => 'zip',       'label' => __('Creating ZIP archive...', 'sitessaver'), 'pct' => 95],
                ['id' => 'finalize',  'label' => __('Finalizing...', 'sitessaver'), 'pct' => 100],
            ];
        }

        return [
            ['id' => 'init',      'label' => __('Initializing...', 'sitessaver'), 'pct' => 3],
            ['id' => 'manifest',  'label' => __('Creating manifest...', 'sitessaver'), 'pct' => 6],
            ['id' => 'db',        'label' => __('Exporting database...', 'sitessaver'), 'pct' => 15],
            ['id' => 'uploads',   'label' => __('Copying uploads...', 'sitessaver'), 'pct' => 28],
            ['id' => 'plugins',   'label' => __('Copying plugins...', 'sitessaver'), 'pct' => 38],
            ['id' => 'themes',    'label' => __('Copying themes...', 'sitessaver'), 'pct' => 46],
            ['id' => 'other',     'label' => __('Copying other files...', 'sitessaver'), 'pct' => 50],
            ['id' => 'zip',       'label' => __('Creating ZIP archive...', 'sitessaver'), 'pct' => 60],
            [
                'id'    => 'finalize',
                'label' => __('Uploading to Google Drive...', 'sitessaver'),
                'pct'   => 100,
                // Tells the client to poll this job for real upload progress
                // and to animate the bar from 'from' to 'pct' as bytes land.
                'poll'  => 'gdrive',
                'from'  => 60,
            ],
        ];
    }

    /**
     * The progress-job id used for the Drive upload of a given export.
     *
     * Shared by the server (which writes progress under this key) and the
     * client (which polls it), so the two cannot drift apart.
     */
    public static function gdrive_job_id(string $uid): string {
        return 'exp_gdrive_' . $uid;
    }

    /**
     * Start a new export process.
     */
    public static function start(array $options = []): array {
        $uid         = 'exp_' . wp_generate_password(8, false, false);
        $backup_name = sitessaver_backup_filename();
        $temp_dir    = SITESSAVER_TEMP_DIR . '/' . $uid;

        // Ensure isolated temp directory exists.
        wp_mkdir_p($temp_dir);

        // Chain tracking is opt-in, and only the scheduler opts in. A manual
        // export from the Export screen therefore behaves EXACTLY as it did
        // before incremental backups existed: full contents, no index built,
        // no chain touched. Someone reaching for the Export button wants a
        // file they can restore on its own.
        $plan = !empty($options['track_chain'])
            ? Index::plan($options)
            : ['type' => 'full', 'chain_id' => '', 'seq' => 0, 'parent' => '', 'full' => '', 'reason' => 'untracked'];

        $status = [
            'uid'         => $uid,
            'status'      => 'running',
            'step_index'  => 0,
            'backup_name' => $backup_name,
            'temp_dir'    => $temp_dir,
            'options'     => $options,
            'plan'        => $plan,
            'start_time'  => time(),
            'last_update' => time(),
        ];

        self::save_status($uid, $status);
        set_transient('sitessaver_active_export_id', $uid, HOUR_IN_SECONDS);

        return $status;
    }

    /**
     * Persist export status using transients (auto-expire, no option-table bloat).
     */
    private static function save_status(string $uid, array $status): void {
        set_transient("sitessaver_export_{$uid}", $status, HOUR_IN_SECONDS);
    }

    /**
     * Fetch export status from transient store.
     */
    public static function get_status(string $uid): array {
        $status = get_transient("sitessaver_export_{$uid}");
        return is_array($status) ? $status : [];
    }

    /**
     * Run a specific step of the export process.
     */
    public static function run_step(string $uid, int $index): array {
        $status = self::get_status($uid);

        if (empty($status) || $status['status'] !== 'running') {
            return ['success' => false, 'message' => __('No active export found for this ID.', 'sitessaver')];
        }

        // Build the table for THIS export's destination — the Drive variant
        // has different weights, so a default table could index a different
        // step than the client is showing.
        $steps = self::get_steps((string) ($status['options']['export_destination'] ?? 'local'));

        if (!isset($steps[$index])) {
            return ['success' => false, 'message' => __('Invalid step index.', 'sitessaver')];
        }

        $step     = $steps[$index];
        $options  = $status['options'];
        $temp_dir = $status['temp_dir'];
        $plan     = is_array($status['plan'] ?? null) ? $status['plan'] : ['type' => 'full'];

        try {
            switch ($step['id']) {
                case 'init':
                    // Already handled by start().
                    break;

                case 'manifest':
                    self::write_manifest($temp_dir, $options, $plan, [], []);
                    break;

                case 'db':
                    if (!empty($options['include_db'])) {
                        $db_file = $temp_dir . '/database.sql';
                        if (!Database::export($db_file)) {
                            throw new \RuntimeException(__('Failed to export database.', 'sitessaver'));
                        }
                    }
                    break;

                case 'uploads':
                    if (!empty($options['include_media'])) {
                        // wp_upload_dir()['basedir'], not a hardcoded
                        // WP_CONTENT_DIR . '/uploads'. On single-site the two
                        // are identical. On MULTISITE they are NOT: WordPress
                        // core keeps every subsite's media under a shared
                        // parent (uploads/sites/{blog_id}/...), so copying
                        // the bare uploads root would pull every OTHER
                        // subsite's media into THIS site's backup — a
                        // content-layer version of the same leak A2 fixed
                        // for the database. wp_upload_dir() resolves to the
                        // current site's own subtree automatically (WP core
                        // switches the basedir per blog) for every subsite —
                        // but the MAIN site (blog ID 1) is the one case
                        // where basedir is NOT scoped: it IS the literal
                        // parent of uploads/sites/, so a plain copy still
                        // vacuums up every subsite's media. Exclude that
                        // subfolder explicitly when this is the main site
                        // on a multisite network. Verified against a real
                        // WP multisite install (see PLAN-multisite-
                        // premium-addon.md §7 A3 test notes) — this is not
                        // a documented wp_upload_dir() edge case, it was
                        // found by testing, not by reading core source.
                        $uploads_exclude = (is_multisite() && get_current_blog_id() === 1)
                            ? ['sites', 'sites/*']
                            : [];
                        self::copy_area('uploads', wp_upload_dir()['basedir'], $temp_dir, $uploads_exclude, $plan);
                    }
                    break;

                case 'plugins':
                    if (!empty($options['include_plugins'])) {
                        // Exclude BOTH the top-level folder AND every file inside it.
                        // Pre-1.1.7 the exclude was `['sitessaver']` — fnmatch does not
                        // treat that as a directory prefix, so every file under
                        // `sitessaver/*` was silently included in the backup. Restores
                        // then overwrote the live plugin with the backup's (older) copy,
                        // silently reverting whatever bugfixes the running plugin had.
                        // We now pass an explicit path-prefix pattern AND rely on the
                        // prefix-aware check added to copy_directory() below.
                        self::copy_area(
                            'plugins',
                            WP_PLUGIN_DIR,
                            $temp_dir,
                            ['sitessaver', 'sitessaver/*'],
                            $plan
                        );
                    }
                    break;

                case 'themes':
                    if (!empty($options['include_themes'])) {
                        self::copy_area('themes', get_theme_root(), $temp_dir, [], $plan);
                    }
                    break;

                case 'other':
                    if (is_dir(WPMU_PLUGIN_DIR)) {
                        self::copy_area('mu-plugins', WPMU_PLUGIN_DIR, $temp_dir, [], $plan);
                    }
                    break;

                case 'zip':
                    // Seal the index: merge the per-area shards, work out what
                    // was deleted since the parent, and rewrite the manifest
                    // now that both are known. Must happen BEFORE the archive
                    // is built so manifest.json and fileindex.json.gz go into
                    // the ZIP.
                    self::seal_index($temp_dir, (string) $status['backup_name'], $options, $plan);

                    $zip_path = sitessaver_storage_dir() . '/' . $status['backup_name'];
                    $exclude  = [
                        'sitessaver-backups',
                        'cache',
                        'upgrade',
                        '*.log',
                        '.DS_Store',
                        'Thumbs.db',
                    ];
                    if (!Archive::create($temp_dir, $zip_path, $exclude)) {
                        throw new \RuntimeException(__('Failed to create ZIP archive.', 'sitessaver'));
                    }
                    break;

                case 'finalize':
                    $zip_path    = sitessaver_storage_dir() . '/' . $status['backup_name'];
                    $destination = $options['export_destination'] ?? 'local';
                    $zip_size    = (int) @filesize($zip_path);

                    // Promote this run's index from temp into the local cache
                    // BEFORE the temp dir is removed, and register the backup
                    // as a chain member. The cache copy deliberately stays on
                    // local disk even for a gdrive-only destination: it is
                    // kilobytes, and without it the next run has no baseline
                    // to diff against and silently degrades to a full backup.
                    if (!empty($options['track_chain'])) {
                        $sealed = $temp_dir . '/' . Index::ARCHIVE_ENTRY;
                        if (is_readable($sealed)) {
                            $payload = file_get_contents($sealed);
                            $index   = is_string($payload) ? Index::decode($payload) : null;
                            if (is_array($index)) {
                                Index::save($status['backup_name'], $index);
                            }
                        }
                        Index::record($plan, $status['backup_name'], $zip_size);
                    }

                    // Isolated cleanup — ONLY delete this export's temp dir.
                    self::remove_directory($temp_dir);

                    $result = [
                        'success'     => true,
                        'file'        => $status['backup_name'],
                        'path'        => $zip_path,
                        'size'        => sitessaver_format_size($zip_size),
                        'destination' => $destination,
                        'backup_type' => (string) ($plan['type'] ?? 'full'),
                        'chain_seq'   => (int) ($plan['seq'] ?? 0),
                    ];

                    // Upload to Google Drive if requested.
                    if (in_array($destination, ['gdrive', 'both'], true)) {
                        $job_id        = self::gdrive_job_id($uid);
                        $gdrive_result = GDrive::upload($zip_path, $status['backup_name'], $job_id);
                        $result['gdrive'] = $gdrive_result;

                        // If destination is gdrive-only and upload failed, surface the error.
                        if ($destination === 'gdrive' && empty($gdrive_result['success'])) {
                            throw new \RuntimeException($gdrive_result['message'] ?? __('Failed to upload to Google Drive.', 'sitessaver'));
                        }

                        if (!empty($gdrive_result['success'])) {
                            $result['gdrive_folder_url'] = GDrive::get_folder_url();
                        }

                        // gdrive-only: remove local copy after successful upload.
                        if ($destination === 'gdrive' && !empty($gdrive_result['success'])) {
                            @unlink($zip_path);
                            $result['file'] = '';
                            $result['path'] = '';
                        }
                    }

                    do_action('sitessaver_export_complete', $status['backup_name'], $zip_path);

                    $status['status'] = 'completed';
                    $status['result'] = $result;
                    self::save_status($uid, $status);
                    delete_transient('sitessaver_active_export_id');

                    return $result;
            }

            $status['step_index'] = $index + 1;
            $status['last_update'] = time();
            self::save_status($uid, $status);

            return ['success' => true, 'step' => $step['id']];

        } catch (\Throwable $e) {
            $status['status']  = 'error';
            $status['message'] = $e->getMessage();
            self::save_status($uid, $status);
            delete_transient('sitessaver_active_export_id');
            self::remove_directory($temp_dir);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Run a full site export (Legacy/Cron wrapper).
     */
    public static function run(array $options = []): array {
        $status = self::start($options);
        $steps  = self::get_steps((string) ($options['export_destination'] ?? 'local'));
        $uid    = $status['uid'];

        foreach (array_keys($steps) as $i) {
            $res = self::run_step($uid, $i);
            if (!$res['success']) {
                return $res;
            }
        }

        $status = self::get_status($uid);
        return $status['status'] === 'completed' ? $status['result'] : ['success' => false, 'message' => $status['message'] ?? 'Unknown error'];
    }


    /**
     * Write package manifest with site metadata.
     *
     * Called twice: once early (so a crashed run still leaves something
     * readable) and once from seal_index() with the chain facts filled in.
     *
     * @param array<string, mixed> $plan
     * @param list<string>         $deleted
     * @param list<string>         $members
     */
    private static function write_manifest(string $dir, array $options, array $plan = [], array $deleted = [], array $members = []): void {
        $manifest = [
            'plugin'        => 'SitesSaver',
            'version'       => SITESSAVER_VERSION,
            'wp_version'    => get_bloginfo('version'),
            'php_version'   => PHP_VERSION,
            'site_url'      => site_url(),
            'home_url'      => home_url(),
            'multisite'     => is_multisite(),
            'db_prefix'     => $GLOBALS['wpdb']->prefix,
            'created_at'    => gmdate('Y-m-d H:i:s'),
            'charset'       => get_bloginfo('charset'),
            'active_theme'  => get_stylesheet(),
            'active_plugins'=> get_option('active_plugins', []),
            'options'       => $options,
        ];

        // Chain facts are added ONLY for a chain-tracked run. A manual export
        // therefore writes byte-for-byte the manifest it always did, and an
        // older SitesSaver reading it sees nothing new. Readers default
        // backup_type to 'full', so an absent block means "restores alone".
        if (!empty($options['track_chain'])) {
            $manifest['backup_type']  = (string) ($plan['type'] ?? 'full');
            $manifest['chain_id']     = (string) ($plan['chain_id'] ?? '');
            $manifest['chain_seq']    = (int) ($plan['seq'] ?? 0);
            $manifest['chain_parent'] = (string) ($plan['parent'] ?? '');
            $manifest['chain_full']   = (string) ($plan['full'] ?? '');

            // The ordered restore set, written INTO the archive so an
            // incremental is self-describing. Restore must not depend on a
            // local option that a reinstall, a migration, or a database
            // restore could have wiped.
            $manifest['chain_members'] = $members;

            // Paths that existed in the parent backup and are gone now. A
            // ZIP cannot represent absence, so deletions travel here.
            $manifest['deleted'] = $deleted;
        }

        file_put_contents(
            $dir . '/manifest.json',
            wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Copy one backup area, indexing it and — for an incremental run —
     * skipping files that have not changed since the parent backup.
     *
     * Each area writes its own index shard to temp rather than accumulating
     * into a static or an option. Steps run as separate HTTP requests, so
     * anything held in memory between them is gone; and a shard per area
     * means a failed run leaves no half-merged global state behind.
     *
     * @param array<string, mixed> $plan
     */
    private static function copy_area(string $area, string $source, string $temp_dir, array $exclude, array $plan): void {
        $prefixes = Index::area_prefixes();
        $prefix   = $prefixes[$area] ?? ('wp-content/' . $area . '/');
        $dest     = $temp_dir . '/' . rtrim($prefix, '/');

        $incremental = ($plan['type'] ?? 'full') === 'incremental';
        $detection   = ($plan['detection'] ?? 'fast') === 'thorough' ? 'thorough' : 'fast';

        $parent_index = [];
        if ($incremental) {
            $loaded = Index::load((string) ($plan['parent'] ?? ''));
            if (!is_array($loaded)) {
                // plan() already verified the parent index exists. If it has
                // vanished between then and now, copying everything is the
                // safe answer: a bigger backup, not a broken one.
                $incremental = false;
            } else {
                $parent_index = $loaded;
            }
        }

        $index = [];

        self::copy_directory(
            $source,
            $dest,
            $exclude,
            static function (string $relative, string $abs_path) use ($prefix, $incremental, $parent_index, $detection, &$index): bool {
                $key         = $prefix . $relative;
                $entry       = Index::entry_for($abs_path, $detection);
                $index[$key] = $entry;

                if (!$incremental) {
                    return true;
                }

                return Index::is_changed($key, $entry, $parent_index, $detection);
            },
            !$incremental
        );

        self::write_index_shard($temp_dir, $area, $index);
    }

    /**
     * Persist one area's index shard into the export temp directory.
     *
     * @param array<string, mixed> $index
     */
    private static function write_index_shard(string $temp_dir, string $area, array $index): void {
        $dir = $temp_dir . '/.ssindex';

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $json = wp_json_encode($index, JSON_UNESCAPED_SLASHES);

        if (is_string($json)) {
            file_put_contents($dir . '/' . $area . '.json', $json);
        }
    }

    /**
     * Merge the per-area shards into the final index, compute deletions,
     * and rewrite the manifest with the complete chain picture.
     *
     * @param array<string, mixed> $plan
     */
    private static function seal_index(string $temp_dir, string $backup_name, array $options, array $plan): void {
        if (empty($options['track_chain'])) {
            // No chain tracking: leave the shards out of the archive and keep
            // the manifest exactly as a pre-1.4.0 full backup's.
            self::remove_directory($temp_dir . '/.ssindex');
            return;
        }

        $index    = [];
        $prefixes = [];
        $areas    = Index::area_prefixes();

        foreach ($areas as $area => $prefix) {
            $shard = $temp_dir . '/.ssindex/' . $area . '.json';

            if (!is_readable($shard)) {
                // Area was switched off this run. It contributes no index
                // entries AND no deletions — an area nobody looked at cannot
                // have lost files.
                continue;
            }

            $raw    = file_get_contents($shard);
            $parsed = is_string($raw) ? json_decode($raw, true) : null;

            if (is_array($parsed)) {
                $index      = $index + $parsed;
                $prefixes[] = $prefix;
            }
        }

        $deleted = [];
        if (($plan['type'] ?? 'full') === 'incremental') {
            $parent = Index::load((string) ($plan['parent'] ?? ''));
            if (is_array($parent)) {
                $deleted = Index::deleted_paths($parent, $index, $prefixes);
            }
        }

        // The restore set as it will stand once this backup is recorded:
        // the chain so far, plus this backup at the end.
        $members = [];
        if (($plan['type'] ?? 'full') === 'incremental') {
            $chain   = Index::chain((string) ($plan['chain_id'] ?? ''));
            $members = is_array($chain['members'] ?? null) ? array_values($chain['members']) : [];
        }
        $members[] = $backup_name;

        self::write_manifest($temp_dir, $options, $plan, $deleted, $members);

        // The index also travels inside the archive, so a backup carried to
        // another server can still serve as a baseline there.
        $json = wp_json_encode($index, JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            $gz = gzencode($json, 6);
            if ($gz !== false) {
                file_put_contents($temp_dir . '/' . Index::ARCHIVE_ENTRY, $gz);
            }
        }

        // Shards are scaffolding, not payload.
        self::remove_directory($temp_dir . '/.ssindex');
    }

    /**
     * Recursively copy a directory, respecting exclude patterns.
     *
     * @param callable(string, string): bool|null $filter Receives the path
     *        relative to $source and its absolute path; returns false to skip
     *        copying the file. Called for every non-excluded FILE, including
     *        ones it then skips, so an incremental run still indexes the
     *        files it chose not to archive.
     * @param bool $mirror_dirs Recreate every source directory in the
     *        destination, empty ones included. False for incremental runs,
     *        where only the folders holding archived files are wanted.
     */
    private static function copy_directory(string $source, string $dest, array $exclude = [], ?callable $filter = null, bool $mirror_dirs = true): void {
        if (!is_dir($source)) {
            return;
        }

        // Always exclude our own backups and temp files.
        $exclude = array_merge($exclude, [
            'sitessaver-backups',
            'cache',
            'upgrade',
            '*.log',
            '.DS_Store',
            'Thumbs.db',
        ]);

        wp_mkdir_p($dest);

        $iterator = new \RecursiveDirectoryIterator(
            $source,
            \RecursiveDirectoryIterator::SKIP_DOTS
        );

        $files = new \RecursiveIteratorIterator(
            $iterator,
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $relative  = str_replace($source, '', $file->getPathname());
            $relative  = ltrim(str_replace(['\\', '/'], '/', $relative), '/');
            $dest_path = $dest . '/' . $relative;

            // Check exclusions.
            //
            // The historic implementation only compared patterns against the
            // full relative path and its basename via fnmatch, which is NOT
            // recursive — `fnmatch('sitessaver', 'sitessaver/foo.php')` is
            // false. That let the plugin's own folder slip into backups and
            // subsequently self-cannibalise on restore. We now also:
            //
            //   - Treat `pattern/*` (or bare `pattern` when it's a top-level
            //     directory name) as a directory-prefix match.
            //   - Split the relative path and check if the FIRST segment
            //     matches the pattern — this covers the common case of
            //     excluding a whole top-level directory by name.
            $skip = false;
            $first_segment = strtok($relative, '/');
            foreach ($exclude as $pattern) {
                $base_pattern = rtrim($pattern, '/*');
                if (fnmatch($pattern, $relative)
                    || fnmatch($pattern, basename($relative))
                    || $first_segment === $base_pattern
                    || str_starts_with($relative, $base_pattern . '/')
                ) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            if ($file->isDir()) {
                // On an incremental run the destination tree is built on
                // demand around the files actually archived. Mirroring every
                // directory up front would write a full skeleton of empty
                // folders into a ZIP that is meant to hold only what changed.
                // A full backup still mirrors them, so an intentionally empty
                // directory survives a restore exactly as it always has.
                if ($mirror_dirs) {
                    wp_mkdir_p($dest_path);
                }
            } else {
                // The filter both indexes the file and decides whether it
                // needs archiving. It is called for every file that survived
                // the exclude list, INCLUDING ones it then declines — an
                // incremental backup must still index a file it skipped, or
                // the next run would see it as deleted.
                if ($filter !== null && !$filter($relative, $file->getPathname())) {
                    continue;
                }

                $parent = dirname($dest_path);
                if (!is_dir($parent)) {
                    wp_mkdir_p($parent);
                }
                @copy($file->getPathname(), $dest_path);
            }
        }
    }

    /**
     * Recursively remove a directory.
     */
    private static function remove_directory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files    = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($dir);
    }
}


