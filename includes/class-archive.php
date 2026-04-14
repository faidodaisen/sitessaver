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
                // ZIP64 is supported by ZipArchive on PHP 7.4+ (libzip >= 1.0),
                // so files >2GB are archived correctly without a size guard.
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

        // Extract each file with path validation. Fail-closed: if any entry
        // looks malicious we close the archive and return false rather than
        // silently skipping it — that way a tampered backup is REJECTED
        // wholesale, not half-restored.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);

            if ($entry === false || $entry === '') {
                continue;
            }

            // Normalize entry path (forward slashes, no backslashes).
            $entry = str_replace('\\', '/', $entry);

            // Reject path traversal attempts.
            if (str_contains($entry, '..') || str_starts_with($entry, '/')) {
                $zip->close();
                return false;
            }

            // Reject symlink entries — a ZIP stores symlinks via the upper
            // 16 bits of the external attribute (Unix mode). S_IFLNK = 0xA000.
            $stat = $zip->statIndex($i);
            if (is_array($stat) && isset($stat['external_attr'])) {
                $unix_mode = ((int) $stat['external_attr']) >> 16;
                if (($unix_mode & 0xF000) === 0xA000) {
                    $zip->close();
                    return false;
                }
            }

            // Resolve full target path.
            $target      = $real_dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
            $parent      = dirname($target);
            if (!is_dir($parent)) {
                wp_mkdir_p($parent);
            }
            $parent_real = realpath($parent);

            // Ensure target is within destination (zip-slip prevention).
            if ($parent_real === false
                || !str_starts_with($parent_real . DIRECTORY_SEPARATOR, $real_dest . DIRECTORY_SEPARATOR)
            ) {
                $zip->close();
                return false;
            }

            // Handle directories.
            if (substr($entry, -1) === '/') {
                wp_mkdir_p($target);
                continue;
            }

            // Extract file.
            $source = $zip->getStream($entry);

            if ($source === false) {
                $zip->close();
                return false;
            }

            $dest_file = fopen($target, 'w');
            if ($dest_file === false) {
                fclose($source);
                $zip->close();
                return false;
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
