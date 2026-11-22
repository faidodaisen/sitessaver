<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-GB backup archives, SQL dumps and chunked uploads with byte offsets, file locks and atomic renames inside the plugin's own backup folder; WP_Filesystem has no streaming, seek or lock API and may need FTP credentials that a background worker cannot supply.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never echoed raw; they are logged or returned through wp_send_json_* and escaped at render time in admin.js (escapeHtml).

/**
 * ZIP archive handler: create backups and read them back during a restore.
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
        // entry never made it into the archive. Ignoring that would let a
        // backup miss files and still report success, and the user would
        // only find out during a restore.
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
                    error_log('[SitesSaver] Archive: unreadable, skipped: ' . $relative);
                    continue;
                }

                // ZIP64 is supported by ZipArchive on PHP 7.4+ (libzip >= 1.0),
                // so files >2GB are archived correctly without a size guard.
                if (!$zip->addFile($filepath, $relative)) {
                    $failed++;
                    if ($failed <= 50) {
                        error_log('[SitesSaver] Archive: addFile failed: ' . $relative);
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

        // close() performs the actual write. This is where ZipArchive does
        // the real read+compress+write for every entry addFile()'d above,
        // and it is a single uninterruptible call with no way to tick from
        // inside it. Mark the phase transition explicitly (tick_phase()
        // bypasses the normal throttle and always persists) so the stall
        // watchdog knows to wait longer. See
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

        // Final check: the archive must exist and be openable.
        if (!file_exists($output_zip) || filesize($output_zip) === 0) {
            error_log('[SitesSaver] Archive: output file missing or empty after close: ' . $output_zip);
            return false;
        }

        return true;
    }

    /** Entries at or above this size are written in pieces (and can resume mid-file). */
    public const EXTRACT_STREAM_MIN = 16 * 1024 * 1024;
    private const EXTRACT_CHUNK     = 8 * 1024 * 1024;

    /**
     * Open a backup archive for reading. Reading archives needs ZipArchive;
     * callers check Requirements::can_restore() first, this is the last guard.
     *
     * @throws \RuntimeException When ZipArchive is missing or the file is not a readable ZIP.
     */
    public static function open(string $zip_path): \ZipArchive {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('The PHP zip extension is missing on this server.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            throw new \RuntimeException('Failed to open the backup archive ' . basename($zip_path) . '.');
        }
        return $zip;
    }

    /**
     * Call $each for every entry, after checking it. Fail-closed: one entry
     * that could reach outside the folder it is restored into (path
     * traversal, absolute path, symbolic link) rejects the whole archive,
     * so a tampered backup is refused before anything is written.
     *
     * @param callable $each fn(string $name, int $size): void
     * @throws \RuntimeException On an unsafe entry.
     */
    public static function each_entry(\ZipArchive $zip, callable $each): void {
        $n = $zip->numFiles;
        for ($i = 0; $i < $n; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat) || !isset($stat['name']) || (string) $stat['name'] === '') {
                continue;
            }
            $name = str_replace('\\', '/', (string) $stat['name']);
            if (self::is_unsafe_relative_path($name)) {
                throw new \RuntimeException('Failed to extract backup archive: unsafe path ' . $name);
            }
            $opsys = 0;
            $attr  = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && ((($attr >> 16) & 0xF000) === 0xA000)) {
                throw new \RuntimeException('Failed to extract backup archive: symbolic link ' . $name);
            }
            if (($i % 500) === 0) {
                do_action('sitessaver_heartbeat');
            }
            $each($name, (int) ($stat['size'] ?? 0));
        }
    }

    /**
     * Write one entry to $target. A large entry is written in pieces; when
     * a request stops mid-entry, the next one re-opens the entry, reads past
     * what is already on disk and continues from $off.
     *
     * @return bool True when written, false when stopped ($off holds the place).
     * @throws \RuntimeException When the entry cannot be read or the target cannot be written.
     */
    public static function extract_entry(\ZipArchive $zip, string $name, int $size, string $target, int &$off, callable $should_stop): bool {
        $source = $zip->getStream($name);
        if ($source === false) {
            throw new \RuntimeException('Failed to extract backup archive: cannot read ' . $name);
        }

        if ($size < self::EXTRACT_STREAM_MIN) {
            $out = @fopen($target, 'wb');
            if ($out === false) {
                fclose($source);
                throw new \RuntimeException(self::write_failure('cannot write', $name));
            }
            $copied = stream_copy_to_stream($source, $out);
            fclose($source);
            $closed = fclose($out);
            if ($copied === false || ($size > 0 && $copied !== $size) || !$closed) {
                throw new \RuntimeException(self::write_failure('could not write', $name, 'disk full or quota reached?'));
            }
            $off = 0;
            return true;
        }

        if (self::extract_large($source, $target, $name, $size, $off, $should_stop)) {
            $off = 0;
            return true;
        }
        return false;
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
            throw new \RuntimeException(self::write_failure('cannot write', $entry));
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
                    throw new \RuntimeException(self::write_failure('could not write', $entry, 'disk full or quota reached?'));
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

    /**
     * Technical message for a write the server refused.
     *
     * Not phrased as "failed to extract": the archive is fine, the server
     * would not let us write. Carries the OS reason and any detected
     * security layer so the log says why, not just what.
     */
    private static function write_failure(string $what, string $entry, string $fallback = 'no reason given'): string {
        $reason = Environment::last_error();
        $layers = Environment::security_layers();
        return sprintf(
            'Server refused the restore: %s %s (%s; %s)%s',
            $what,
            $entry,
            $reason !== '' ? $reason : $fallback,
            Environment::WRITE_BLOCKED,
            $layers !== [] ? ' [security: ' . implode(', ', $layers) . ']' : ''
        );
    }

    /**
     * True when a path taken from a backup (ZIP entry name, manifest "deleted" list)
     * could point outside the directory it is resolved against.
     *
     * Only a path SEGMENT equal to ".." is traversal. A file NAME that merely contains
     * two dots ("photo..jpg", "my..report.pdf") is legal on every filesystem and does
     * occur in real uploads; rejecting those would abort a whole restore with
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
