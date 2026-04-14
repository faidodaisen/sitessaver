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

        // Move uploaded file to storage.
        $dest = SITESSAVER_STORAGE_DIR . '/' . sanitize_file_name($file['name']);
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['success' => false, 'message' => __('Failed to save uploaded file.', 'sitessaver')];
        }

        return self::run($dest);
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
