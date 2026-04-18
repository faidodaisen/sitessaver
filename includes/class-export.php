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
     */
    public static function get_steps(): array {
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

    /**
     * Start a new export process.
     */
    public static function start(array $options = []): array {
        $uid         = 'exp_' . wp_generate_password(8, false, false);
        $backup_name = sitessaver_backup_filename();
        $temp_dir    = SITESSAVER_TEMP_DIR . '/' . $uid;

        // Ensure isolated temp directory exists.
        wp_mkdir_p($temp_dir);

        $status = [
            'uid'         => $uid,
            'status'      => 'running',
            'step_index'  => 0,
            'backup_name' => $backup_name,
            'temp_dir'    => $temp_dir,
            'options'     => $options,
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
        $steps  = self::get_steps();

        if (empty($status) || $status['status'] !== 'running') {
            return ['success' => false, 'message' => __('No active export found for this ID.', 'sitessaver')];
        }

        if (!isset($steps[$index])) {
            return ['success' => false, 'message' => __('Invalid step index.', 'sitessaver')];
        }

        $step     = $steps[$index];
        $options  = $status['options'];
        $temp_dir = $status['temp_dir'];

        try {
            switch ($step['id']) {
                case 'init':
                    // Already handled by start().
                    break;

                case 'manifest':
                    self::write_manifest($temp_dir, $options);
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
                        self::copy_directory(WP_CONTENT_DIR . '/uploads', $temp_dir . '/wp-content/uploads');
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
                        self::copy_directory(
                            WP_PLUGIN_DIR,
                            $temp_dir . '/wp-content/plugins',
                            ['sitessaver', 'sitessaver/*']
                        );
                    }
                    break;

                case 'themes':
                    if (!empty($options['include_themes'])) {
                        self::copy_directory(get_theme_root(), $temp_dir . '/wp-content/themes');
                    }
                    break;

                case 'other':
                    if (is_dir(WPMU_PLUGIN_DIR)) {
                        self::copy_directory(WPMU_PLUGIN_DIR, $temp_dir . '/wp-content/mu-plugins');
                    }
                    break;

                case 'zip':
                    $zip_path = SITESSAVER_STORAGE_DIR . '/' . $status['backup_name'];
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
                    // Isolated cleanup — ONLY delete this export's temp dir.
                    self::remove_directory($temp_dir);

                    $zip_path    = SITESSAVER_STORAGE_DIR . '/' . $status['backup_name'];
                    $destination = $options['export_destination'] ?? 'local';

                    $result = [
                        'success'     => true,
                        'file'        => $status['backup_name'],
                        'path'        => $zip_path,
                        'size'        => sitessaver_format_size((int) filesize($zip_path)),
                        'destination' => $destination,
                    ];

                    // Upload to Google Drive if requested.
                    if (in_array($destination, ['gdrive', 'both'], true)) {
                        $job_id       = 'exp_gdrive_' . $uid;
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
        $steps  = self::get_steps();
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
     */
    private static function write_manifest(string $dir, array $options): void {
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

        file_put_contents(
            $dir . '/manifest.json',
            wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Recursively copy a directory, respecting exclude patterns.
     */
    private static function copy_directory(string $source, string $dest, array $exclude = []): void {
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
                wp_mkdir_p($dest_path);
            } else {
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


