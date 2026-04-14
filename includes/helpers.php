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
 * Get all backup files sorted by date (newest first).
 */
function sitessaver_get_backups(): array {
    $dir = SITESSAVER_STORAGE_DIR;

    if (!is_dir($dir)) {
        return [];
    }

    $files   = glob($dir . '/*.zip');
    $backups = [];

    if ($files === false) {
        return [];
    }

    foreach ($files as $file) {
        $basename = basename($file);
        $labels   = get_option('sitessaver_backup_labels', []);

        $backups[] = [
            'file'     => $basename,
            'path'     => $file,
            'size'     => filesize($file),
            'size_h'   => sitessaver_format_size((int) filesize($file)),
            'created'  => filemtime($file),
            'created_h' => wp_date('M j, Y g:i A', filemtime($file)),
            'label'    => $labels[$basename] ?? '',
        ];
    }

    // Sort by creation time descending.
    usort($backups, static fn($a, $b) => $b['created'] <=> $a['created']);

    return $backups;
}

/**
 * Clean up temp directory, including orphaned chunk upload directories.
 *
 * Chunk dirs older than 6 hours are considered orphaned and removed.
 * Non-chunk temp files are always removed.
 */
function sitessaver_cleanup_temp(): void {
    $tmp = SITESSAVER_TEMP_DIR;

    if (!is_dir($tmp)) {
        return;
    }

    // Clean orphaned chunk directories (older than 6 hours).
    $chunks_dir = $tmp . '/chunks';
    if (is_dir($chunks_dir)) {
        $cutoff = time() - (6 * 3600);
        $dirs   = glob($chunks_dir . '/*', GLOB_ONLYDIR);

        if ($dirs) {
            foreach ($dirs as $dir) {
                if (filemtime($dir) < $cutoff) {
                    $chunk_files = glob($dir . '/*');
                    if ($chunk_files) {
                        foreach ($chunk_files as $cf) {
                            @unlink($cf);
                        }
                    }
                    @rmdir($dir);
                }
            }
        }
    }

    // Clean all other temp files and directories.
    $iterator = new RecursiveDirectoryIterator($tmp, RecursiveDirectoryIterator::SKIP_DOTS);
    $files    = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($files as $file) {
        // Skip active chunk directories (not yet expired).
        $path = $file->getPathname();
        if (strpos(str_replace('\\', '/', $path), '/chunks/') !== false) {
            continue;
        }

        if ($file->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
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

