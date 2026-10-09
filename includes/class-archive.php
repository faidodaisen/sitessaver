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

        // Track add failures. ZipArchive::addFile() returning false means the
        // entry never made it into the archive — previously ignored, so a
        // backup could be missing files and still report success. The user
        // only discovers it during a restore, which is the worst possible
        // moment.
        $failed = 0;
        $added  = 0;

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
                // Files can vanish between the directory scan and the add
                // (active caches, session GC, another job cleaning temp).
                // Skipping a file that no longer exists is correct; failing
                // to add one that DOES exist is data loss and must be loud.
                if (!is_readable($filepath)) {
                    $failed++;
                    error_log('[SitesSaver] Archive: unreadable, skipped — ' . $relative);
                    continue;
                }

                // ZIP64 is supported by ZipArchive on PHP 7.4+ (libzip >= 1.0),
                // so files >2GB are archived correctly without a size guard.
                if (!$zip->addFile($filepath, $relative)) {
                    $failed++;
                    if ($failed <= 50) {
                        error_log('[SitesSaver] Archive: addFile failed — ' . $relative);
                    }
                }

                // Archiving several gigabytes takes minutes. Without a
                // liveness signal the watchdog cannot distinguish this from a
                // worker that died mid-zip, and would restart the export.
                // Guarded: Archive is also used standalone (tests, restore
                // tooling) where the Export engine is not loaded.
                if (class_exists(Export::class)) {
                    Export::tick('zip', ++$added);
                }
            }
        }

        // close() performs the actual write — this is where ZipArchive does
        // the real read+compress+write for every entry addFile()'d above,
        // and it is a single uninterruptible call with no way to tick from
        // inside it. Mark the phase transition explicitly (tick_phase()
        // bypasses the normal throttle and always persists) so the stall
        // watchdog knows to extend its patience — see
        // Export::STALL_SECONDS_FINALIZING's docblock for why.
        if (class_exists(Export::class)) {
            Export::tick_phase('zip-finalizing');
        }

        if (!$zip->close()) {
            error_log('[SitesSaver] Archive: ZipArchive::close() failed for ' . $output_zip);
            return false;
        }

        if ($failed > 0) {
            error_log(sprintf('[SitesSaver] Archive: %d file(s) could not be added to %s.', $failed, basename($output_zip)));
        }

        // Final sanity check — the archive must exist and be openable.
        if (!file_exists($output_zip) || filesize($output_zip) === 0) {
            error_log('[SitesSaver] Archive: output file missing or empty after close — ' . $output_zip);
            return false;
        }

        return true;
    }

    /**
     * Extract a ZIP archive to a directory with path validation.
     * Extracts file-by-file to prevent zip-slip attacks.
     */
    /** Entries at or above this size are written in pieces (and can resume mid-file). */
    public const EXTRACT_STREAM_MIN = 16 * 1024 * 1024;
    private const EXTRACT_CHUNK     = 8 * 1024 * 1024;

    /**
     * extract(), one entry at a time, able to stop and continue later.
     *
     * Same fail-closed checks as extract() (path traversal, symlinks,
     * zip-slip). A large entry is written in pieces; when a request stops
     * mid-entry, the next one re-opens the entry, reads past what is already
     * on disk (decompressing is fast; writing is the slow part on shared
     * hosts) and continues from there.
     *
     * @param array<string, int> $cur ['i' => next entry, 'off' => bytes of it already written]
     * @return bool True when every entry is extracted, false when stopped (cursor committed).
     * @throws \RuntimeException When the archive cannot be opened or an entry is unsafe.
     */
    public static function extract_resumable(string $zip_path, string $dest_dir, array &$cur, callable $should_stop, callable $commit): bool {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('The PHP Zip extension is missing on this server.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            throw new \RuntimeException('Failed to open the backup archive ' . basename($zip_path) . '.');
        }

        if (!is_dir($dest_dir)) {
            wp_mkdir_p($dest_dir);
        }
        $real_dest = realpath($dest_dir);
        if ($real_dest === false) {
            $zip->close();
            throw new \RuntimeException('Could not create the temporary restore folder.');
        }
        $real_dest = rtrim($real_dest, DIRECTORY_SEPARATOR);

        $n   = $zip->numFiles;
        $i   = (int) ($cur['i'] ?? 0);
        $off = (int) ($cur['off'] ?? 0);

        try {
            for (; $i < $n; $i++) {
                $entry = $zip->getNameIndex($i);
                if ($entry === false || $entry === '') {
                    continue;
                }

                do_action('sitessaver_heartbeat');

                $entry = str_replace('\\', '/', $entry);
                if (self::is_unsafe_relative_path($entry)) {
                    throw new \RuntimeException('Failed to extract backup archive: unsafe path ' . $entry);
                }

                $stat = $zip->statIndex($i);
                if (is_array($stat) && isset($stat['external_attr'])) {
                    $unix_mode = ((int) $stat['external_attr']) >> 16;
                    if (($unix_mode & 0xF000) === 0xA000) {
                        throw new \RuntimeException('Failed to extract backup archive: symbolic link ' . $entry);
                    }
                }

                $target = $real_dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    wp_mkdir_p($parent);
                }
                $parent_real = realpath($parent);
                if ($parent_real === false
                    || !str_starts_with($parent_real . DIRECTORY_SEPARATOR, $real_dest . DIRECTORY_SEPARATOR)
                ) {
                    throw new \RuntimeException('Failed to extract backup archive: path outside the restore folder ' . $entry);
                }

                if (substr($entry, -1) === '/') {
                    wp_mkdir_p($target);
                    continue;
                }

                $size   = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
                $source = $zip->getStream($entry);
                if ($source === false) {
                    throw new \RuntimeException('Failed to extract backup archive: cannot read ' . $entry);
                }

                if ($size < self::EXTRACT_STREAM_MIN) {
                    $dest_file = fopen($target, 'w');
                    if ($dest_file === false) {
                        fclose($source);
                        throw new \RuntimeException('Failed to extract backup archive: cannot write ' . $entry);
                    }
                    stream_copy_to_stream($source, $dest_file);
                    fclose($source);
                    fclose($dest_file);
                } else {
                    $done = self::extract_large($source, $target, $entry, $size, $off, $should_stop);
                    if (!$done) {
                        $cur = ['i' => $i, 'off' => $off];
                        $commit(true);
                        return false;
                    }
                }

                $off = 0;
                $cur = ['i' => $i + 1, 'off' => 0];
                $commit(false);
                if ($i + 1 < $n && $should_stop()) {
                    $commit(true);
                    return false;
                }
            }
        } finally {
            $zip->close();
        }

        $cur = ['i' => $n, 'off' => 0];
        return true;
    }

    /**
     * Write one large entry from byte $off on. Returns false when stopped.
     *
     * @param resource $source
     */
    private static function extract_large($source, string $target, string $entry, int $size, int &$off, callable $should_stop): bool {
        // Re-read (and drop) what an earlier request already wrote.
        $skip = $off;
        while ($skip > 0) {
            $part = fread($source, (int) min(1024 * 1024, $skip));
            if ($part === false || $part === '') {
                fclose($source);
                throw new \RuntimeException('Failed to extract backup archive: ' . $entry . ' ended early.');
            }
            $skip -= strlen($part);
        }

        $out = @fopen($target, 'c');
        if ($out === false) {
            fclose($source);
            throw new \RuntimeException('Failed to extract backup archive: cannot write ' . $entry);
        }
        ftruncate($out, $off);
        fseek($out, $off);

        try {
            while ($off < $size) {
                $want  = (int) min(self::EXTRACT_CHUNK, $size - $off);
                $chunk = '';
                while (strlen($chunk) < $want) {
                    $part = fread($source, $want - strlen($chunk));
                    if ($part === false || $part === '') {
                        break;
                    }
                    $chunk .= $part;
                }
                if ($chunk === '') {
                    throw new \RuntimeException('Failed to extract backup archive: ' . $entry . ' ended early.');
                }
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Failed to extract backup archive: could not write ' . $entry . ' (disk full?).');
                }
                $off += strlen($chunk);
                do_action('sitessaver_heartbeat');

                if ($off < $size && $should_stop()) {
                    return false;
                }
            }
        } finally {
            fclose($out);
            fclose($source);
        }
        return true;
    }

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

            // Liveness for a background restore (throttled by the listener).
            do_action('sitessaver_heartbeat');

            // Normalize entry path (forward slashes, no backslashes).
            $entry = str_replace('\\', '/', $entry);

            // Reject path traversal attempts.
            if (self::is_unsafe_relative_path($entry)) {
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
     * True when a path taken from a backup (ZIP entry name, manifest "deleted" list)
     * could point outside the directory it is resolved against.
     *
     * Only a path SEGMENT equal to ".." is traversal. A file NAME that merely contains
     * two dots ("photo..jpg", "my..report.pdf") is legal on every filesystem and does
     * occur in real uploads; rejecting those used to abort a whole restore with
     * "Failed to extract backup archive.". Absolute paths, Windows drive prefixes and
     * NUL bytes are still refused.
     */
    public static function is_unsafe_relative_path(string $path): bool {
        $p = str_replace('\\', '/', $path);
        if ($p === '' || str_contains($p, "\0")) {
            return true;
        }
        if ($p[0] === '/' || preg_match('#^[A-Za-z]:#', $p) === 1) {
            return true;
        }
        foreach (explode('/', $p) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }
        return false;
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
