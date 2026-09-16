<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Format file size to human-readable string.
 */
function sitessaver_format_size(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i     = 0;
    $size  = (float) $bytes;

    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }

    return round($size, 2) . ' ' . $units[$i];
}

/**
 * Get the max upload size (respects PHP and WP limits).
 */
function sitessaver_max_upload_size(): int {
    $php_max  = wp_max_upload_size();
    $wp_limit = (int) get_option('sitessaver_max_upload', 0);

    // 0 = unlimited (respect PHP only).
    return $wp_limit > 0 ? min($php_max, $wp_limit) : $php_max;
}

/**
 * Generate a unique backup filename.
 */
function sitessaver_backup_filename(string $ext = 'zip'): string {
    $site = sanitize_file_name(wp_parse_url(home_url(), PHP_URL_HOST) ?? 'site');
    $date = gmdate('Ymd-His');

    return sprintf('%s-%s-%s.%s', $site, $date, wp_generate_password(6, false), $ext);
}

/**
 * Absolute path to the backup storage directory for the CURRENT site.
 *
 * On a single-site install this is identical to SITESSAVER_STORAGE_DIR, so
 * existing installs are byte-for-byte unaffected.
 *
 * On multisite, SITESSAVER_STORAGE_DIR used to be one network-wide folder
 * with no per-site scoping: sitessaver_get_backups() globbed it directly,
 * so any subsite admin could see, download, and DELETE another subsite's
 * backups. This namespaces storage by blog ID so each site only ever sees
 * its own archives. The directory is created (and protected with the same
 * .htaccess/index.php guard the activation hook uses) lazily on first call,
 * since WordPress only runs register_activation_hook() for sites that
 * existed at activation time — a subsite created afterwards would otherwise
 * never get a protected folder.
 */
function sitessaver_storage_dir(): string {
    if (!is_multisite()) {
        return SITESSAVER_STORAGE_DIR;
    }

    $dir = SITESSAVER_STORAGE_DIR . '/site-' . get_current_blog_id();

    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
        sitessaver_protect_directory($dir);
    }

    return $dir;
}

/**
 * Write the dual-syntax .htaccess + index.php guard into a directory, if not
 * already present. Idempotent. Shared by the activation hook (network-wide
 * SITESSAVER_STORAGE_DIR) and sitessaver_storage_dir() (per-site subfolders
 * on multisite), so the two never drift out of sync.
 */
function sitessaver_protect_directory(string $dir): void {
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents(
            $htaccess,
            "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Order Deny,Allow\n"
            . "    Deny from all\n"
            . "</IfModule>\n"
        );
    }

    $index = $dir . '/index.php';
    if (!file_exists($index)) {
        file_put_contents($index, '<?php // Silence is golden.');
    }
}

/**
 * Get all backup files sorted by date (newest first).
 */
function sitessaver_get_backups(): array {
    $dir = sitessaver_storage_dir();

    if (!is_dir($dir)) {
        return [];
    }

    $files   = glob($dir . '/*.zip');
    $backups = [];

    if ($files === false) {
        return [];
    }

    // Hoist out of the loop — single option read.
    $labels = get_option('sitessaver_backup_labels', []);

    foreach ($files as $file) {
        $basename = basename($file);
        $size     = (int) filesize($file);
        $mtime    = (int) filemtime($file);

        $backups[] = [
            'file'      => $basename,
            'path'      => $file,
            'size'      => $size,
            'size_h'    => sitessaver_format_size($size),
            'created'   => $mtime,
            'created_h' => wp_date('M j, Y g:i A', $mtime),
            'label'     => $labels[$basename] ?? '',
        ];
    }

    // Sort by creation time descending.
    usort($backups, static fn($a, $b) => $b['created'] <=> $a['created']);

    return $backups;
}

/**
 * Clean up temp directory.
 *
 * With no argument: removes only **stale** temp artefacts (orphan chunk dirs
 * older than 6h AND any top-level non-chunk dir/file older than 6h). This is
 * safe to call concurrently with an active export/import — it will NOT wipe
 * another in-flight job's working directory.
 *
 * With a $scope_dir argument: removes exactly that directory and its contents
 * (used for per-import cleanup).
 *
 * @param string|null $scope_dir Absolute path of a single temp subdir to remove.
 *                               Must live under SITESSAVER_TEMP_DIR.
 */
function sitessaver_cleanup_temp(?string $scope_dir = null): void {
    $tmp = SITESSAVER_TEMP_DIR;

    if (!is_dir($tmp)) {
        return;
    }

    if ($scope_dir !== null) {
        sitessaver_remove_scoped_temp($tmp, $scope_dir);
        return;
    }

    $cutoff = time() - (6 * 3600);

    // Clean orphaned chunk directories (older than 6 hours).
    $chunks_dir = $tmp . '/chunks';
    if (is_dir($chunks_dir)) {
        $dirs = glob($chunks_dir . '/*', GLOB_ONLYDIR);
        if ($dirs) {
            foreach ($dirs as $dir) {
                if (filemtime($dir) < $cutoff) {
                    sitessaver_rm_recursive($dir);
                }
            }
        }
    }

    // Clean stale top-level temp items (not chunks, older than 6h).
    // Do NOT do a deep recursive wipe — that nukes active jobs.
    $top = glob($tmp . '/*');
    if ($top) {
        foreach ($top as $entry) {
            $name = basename($entry);
            if ($name === 'chunks') {
                continue;
            }
            if (filemtime($entry) >= $cutoff) {
                continue; // Active job — leave alone.
            }
            sitessaver_rm_recursive($entry);
        }
    }
}

/**
 * Remove a specific temp subdirectory if — and only if — it lives under
 * SITESSAVER_TEMP_DIR. Hardens against path traversal.
 */
function sitessaver_remove_scoped_temp(string $tmp, string $scope_dir): void {
    $real_tmp   = realpath($tmp);
    $real_scope = realpath($scope_dir);

    if ($real_tmp === false || $real_scope === false) {
        return;
    }

    $real_tmp   = rtrim($real_tmp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $real_scope = rtrim($real_scope, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    // Refuse to delete anything outside the temp dir, and refuse to nuke
    // the temp dir itself.
    if ($real_scope === $real_tmp || !str_starts_with($real_scope, $real_tmp)) {
        return;
    }

    sitessaver_rm_recursive(rtrim($real_scope, DIRECTORY_SEPARATOR));
}

/**
 * Recursively remove a path (file or directory).
 */
function sitessaver_rm_recursive(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $iterator = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
    $items    = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($path);
}

/**
 * Resolve a user-supplied backup filename to a safe absolute path, or return
 * null if the file is missing or resolves outside SITESSAVER_STORAGE_DIR.
 *
 * Hardens against path-traversal / symlink escape for all handlers that
 * operate on existing backup files (delete, download, upload to GDrive).
 */
function sitessaver_resolve_backup_path(string $file): ?string {
    $file = sanitize_file_name($file);
    if ($file === '') {
        return null;
    }

    $path = sitessaver_storage_dir() . '/' . $file;
    if (!file_exists($path)) {
        return null;
    }

    $real_path = realpath($path);
    $real_dir  = realpath(sitessaver_storage_dir());

    if ($real_path === false || $real_dir === false) {
        return null;
    }

    $real_dir .= DIRECTORY_SEPARATOR;
    if (!str_starts_with($real_path . DIRECTORY_SEPARATOR, $real_dir)) {
        return null;
    }

    return $real_path;
}

/**
 * Verify AJAX request (nonce + capability).
 */
function sitessaver_verify_ajax(): bool {
    if (!check_ajax_referer('sitessaver_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => __('Security check failed.', 'sitessaver')], 403);
        return false;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'sitessaver')], 403);
        return false;
    }

    return true;
}

