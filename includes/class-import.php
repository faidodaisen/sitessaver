<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Import engine — restore full site from ZIP backup.
 */
final class Import {

    /**
     * Import from a backup file in storage.
     *
     * @param string $backup_file Backup filename (in storage dir).
     * @return array{success: bool, message: string}
     */
    public static function from_backup(string $backup_file): array {
        $zip_path = SITESSAVER_STORAGE_DIR . '/' . sanitize_file_name($backup_file);

        if (!file_exists($zip_path)) {
            return ['success' => false, 'message' => __('Backup file not found.', 'sitessaver')];
        }

        return self::run($zip_path);
    }

    /**
     * Import from an uploaded file.
     *
     * @param array $file $_FILES array element.
     * @return array{success: bool, message: string}
     */
    public static function from_upload(array $file): array {
        if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => __('Upload failed.', 'sitessaver')];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            return ['success' => false, 'message' => __('Only ZIP files are accepted.', 'sitessaver')];
        }

        // Verify ZIP magic bytes before touching disk — blocks .zip-renamed
        // executables / random binaries that passed the extension check.
        if (!self::is_zip_file($file['tmp_name'])) {
            return ['success' => false, 'message' => __('The uploaded file is not a valid ZIP archive.', 'sitessaver')];
        }

        // Stage into temp (NOT storage) so a failed import doesn't litter the
        // backups directory or overwrite an existing backup of the same name.
        if (!is_dir(SITESSAVER_TEMP_DIR)) {
            wp_mkdir_p(SITESSAVER_TEMP_DIR);
        }
        $staged = SITESSAVER_TEMP_DIR . '/incoming-' . wp_generate_password(8, false, false) . '.zip';
        if (!move_uploaded_file($file['tmp_name'], $staged)) {
            return ['success' => false, 'message' => __('Failed to save uploaded file.', 'sitessaver')];
        }

        try {
            $result = self::run($staged);

            // On success, archive a copy into storage with a unique filename
            // so the imported backup shows up in the Backups list. Do this
            // BEFORE cleanup so the staged file still exists.
            if (!empty($result['success'])) {
                $final_name = self::unique_backup_filename(sanitize_file_name($file['name']));
                $final_path = SITESSAVER_STORAGE_DIR . '/' . $final_name;
                @copy($staged, $final_path);
            }
        } finally {
            if (file_exists($staged)) {
                @unlink($staged);
            }
        }

        return $result;
    }

    /**
     * Return a storage-dir filename that doesn't clash with an existing backup.
     */
    private static function unique_backup_filename(string $filename): string {
        if ($filename === '' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
            $filename = 'imported-' . wp_generate_password(6, false, false) . '.zip';
        }

        $dest = SITESSAVER_STORAGE_DIR . '/' . $filename;
        if (!file_exists($dest)) {
            return $filename;
        }

        $base = pathinfo($filename, PATHINFO_FILENAME);
        return $base . '-' . wp_generate_password(4, false, false) . '.zip';
    }

    /**
     * Check the first 4 bytes for the ZIP local-file-header (PK\x03\x04) or
     * empty-archive (PK\x05\x06) magic.
     */
    private static function is_zip_file(string $path): bool {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = fread($handle, 4);
        fclose($handle);

        return $header === "PK\x03\x04" || $header === "PK\x05\x06";
    }

    /**
     * Run the import process.
     */
    private static function run(string $zip_path): array {
        $temp_dir = SITESSAVER_TEMP_DIR . '/import-' . wp_generate_password(8, false);

        try {
            // Opportunistic stale-only sweep (won't touch active export temps).
            sitessaver_cleanup_temp();
            wp_mkdir_p($temp_dir);

            // 1. Extract archive.
            if (!Archive::extract($zip_path, $temp_dir)) {
                throw new \RuntimeException(__('Failed to extract backup archive.', 'sitessaver'));
            }

            // 2. Read and validate manifest.
            $manifest = self::read_manifest($temp_dir);
            if ($manifest === null) {
                throw new \RuntimeException(__('Invalid backup: manifest.json not found.', 'sitessaver'));
            }
            if (!self::is_sitessaver_manifest($manifest)) {
                throw new \RuntimeException(__('This ZIP is not a SitesSaver backup. Please upload a file created by the SitesSaver Export tool.', 'sitessaver'));
            }

            // 3. Restore database.
            $db_file = $temp_dir . '/database.sql';
            if (file_exists($db_file)) {
                $old_url = $manifest['home_url'] ?? '';
                $new_url = home_url();

                if (!Database::import($db_file, $old_url, $new_url)) {
                    throw new \RuntimeException(__('Failed to import database.', 'sitessaver'));
                }
            }

            // 4. Restore wp-content files.
            $content_src = $temp_dir . '/wp-content';
            if (is_dir($content_src)) {
                self::restore_content($content_src);
            }

            // 5. Post-import tasks.
            self::post_import($manifest);

            // 6. Cleanup — scoped to THIS import only.
            sitessaver_cleanup_temp($temp_dir);

            do_action('sitessaver_import_complete', basename($zip_path));

            return [
                'success' => true,
                'message' => __('Site restored successfully. Please log in again.', 'sitessaver'),
            ];

        } catch (\Throwable $e) {
            sitessaver_cleanup_temp($temp_dir);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Read the manifest.json from extracted backup.
     */
    private static function read_manifest(string $dir): ?array {
        $file = $dir . '/manifest.json';

        if (!file_exists($file)) {
            return null;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Verify a decoded manifest was produced by SitesSaver Export.
     * Guards against random ZIPs whose root happens to contain a manifest.json.
     */
    private static function is_sitessaver_manifest(array $manifest): bool {
        return ($manifest['plugin'] ?? '') === 'SitesSaver'
            && !empty($manifest['version'])
            && !empty($manifest['site_url']);
    }

    /**
     * Restore wp-content directories from extracted backup.
     */
    private static function restore_content(string $content_src): void {
        $dirs = [
            'uploads'    => WP_CONTENT_DIR . '/uploads',
            'plugins'    => WP_PLUGIN_DIR,
            'themes'     => get_theme_root(),
            'mu-plugins' => WPMU_PLUGIN_DIR,
        ];

        foreach ($dirs as $name => $dest) {
            $src = $content_src . '/' . $name;

            if (!is_dir($src)) {
                continue;
            }

            // Merge files (overwrite existing, keep extras).
            self::merge_directory($src, $dest);
        }
    }

    /**
     * Recursively merge source into destination (overwrite conflicts).
     */
    private static function merge_directory(string $source, string $dest): void {
        if (!is_dir($dest)) {
            wp_mkdir_p($dest);
        }

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
            $relative  = ltrim(str_replace('\\', '/', $relative), '/');
            $dest_path = $dest . '/' . $relative;

            if ($file->isDir()) {
                if (!is_dir($dest_path)) {
                    wp_mkdir_p($dest_path);
                }
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
     * Post-import cleanup: flush caches, rewrite rules, etc.
     */
    private static function post_import(array $manifest): void {
        // Flush rewrite rules.
        flush_rewrite_rules();

        // Clear object cache.
        wp_cache_flush();

        // Update active plugins from manifest if available.
        if (!empty($manifest['active_plugins'])) {
            update_option('active_plugins', $manifest['active_plugins']);
        }

        // Re-activate the theme if it is present on the target install.
        // Switching to a missing slug corrupts `stylesheet`/`template` options
        // and leaves the site unbootable — validate before switching.
        if (!empty($manifest['active_theme'])) {
            $theme = wp_get_theme($manifest['active_theme']);
            if ($theme->exists()) {
                switch_theme($manifest['active_theme']);
            }
        }

        // Clear any transients.
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'");
    }
}
