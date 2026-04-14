<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * ZIP archive handler — create and extract site backups.
 */
final class Archive {

    /**
     * Create a ZIP backup from a directory.
     *
     * @param string $source_dir Directory to archive.
     * @param string $output_zip Path to output ZIP file.
     * @param array  $exclude    Patterns to exclude.
     */
    public static function create(string $source_dir, string $output_zip, array $exclude = []): bool {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $zip = new \ZipArchive();
        $res = $zip->open($output_zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if ($res !== true) {
            return false;
        }

        $source_dir = rtrim(realpath($source_dir), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $iterator = new \RecursiveDirectoryIterator(
            $source_dir,
            \RecursiveDirectoryIterator::SKIP_DOTS
        );

        $files = new \RecursiveIteratorIterator(
            $iterator,
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $filepath     = $file->getPathname();
            $relative     = str_replace($source_dir, '', $filepath);
            $relative     = str_replace('\\', '/', $relative);

            // Skip excluded patterns.
            if (self::is_excluded($relative, $exclude)) {
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                // Skip files > 2GB (ZIP32 limit).
                if ($file->getSize() > 2147483647) {
                    continue;
                }
                $zip->addFile($filepath, $relative);
            }
        }

        return $zip->close();
    }

    /**
     * Extract a ZIP archive to a directory with path validation.
     * Extracts file-by-file to prevent zip-slip attacks.
     */
    public static function extract(string $zip_path, string $dest_dir): bool {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $zip = new \ZipArchive();
        $res = $zip->open($zip_path);

        if ($res !== true) {
            return false;
        }

        // Resolve and normalize destination directory.
        $real_dest = realpath($dest_dir);
        if ($real_dest === false) {
            wp_mkdir_p($dest_dir);
            $real_dest = realpath($dest_dir);
        }

        if ($real_dest === false) {
            $zip->close();
            return false;
        }

        $real_dest = rtrim($real_dest, DIRECTORY_SEPARATOR);

        // Extract each file with path validation.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);

            if ($entry === false || $entry === '') {
                continue;
            }

            // Normalize entry path (forward slashes, no backslashes).
            $entry = str_replace('\\', '/', $entry);

            // Prevent path traversal attempts.
            if (str_contains($entry, '..') || str_starts_with($entry, '/')) {
                continue;
            }

            // Resolve full target path.
            $target = $real_dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
            $target_real = realpath(dirname($target));

            // Ensure target is within destination (zip-slip prevention).
            if ($target_real === false || !str_starts_with($target_real . DIRECTORY_SEPARATOR, $real_dest . DIRECTORY_SEPARATOR)) {
                continue;
            }

            // Handle directories.
            if (substr($entry, -1) === '/') {
                wp_mkdir_p($target);
                continue;
            }

            // Extract file.
            wp_mkdir_p(dirname($target));
            $source = $zip->getStream($entry);

            if ($source === false) {
                continue;
            }

            $dest_file = fopen($target, 'w');
            if ($dest_file === false) {
                fclose($source);
                continue;
            }

            stream_copy_to_stream($source, $dest_file);
            fclose($source);
            fclose($dest_file);
        }

        $zip->close();
        return true;
    }

    /**
     * Check if a relative path matches any exclude pattern.
     */
    private static function is_excluded(string $path, array $patterns): bool {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $path) || fnmatch($pattern, basename($path))) {
                return true;
            }
        }
        return false;
    }
}
