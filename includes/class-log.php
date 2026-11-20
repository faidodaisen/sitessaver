<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-GB backup archives, SQL dumps and chunked uploads with byte offsets, file locks and atomic renames inside the plugin's own backup folder; WP_Filesystem has no streaming, seek or lock API and may need FTP credentials that a background worker cannot supply.

/**
 * The plugin's own troubleshooting log.
 *
 * Why not just error_log(): on shared hosting the PHP error log is usually
 * either off, unreadable from wp-admin, or buried under thousands of lines
 * from other plugins. When a backup or restore fails, the person who has to
 * fix it needs one place that says what SitesSaver was doing, when, and why
 * it stopped — readable from the Help screen without server access.
 *
 * Storage: one JSON object per line in a plain .log data file under
 * wp-content/sitessaver-backups/logs/. The folder carries the deny-all
 * .htaccess, a web.config and an empty index.php, and the file name has a
 * random part (created once, found again by listing the folder), so the log
 * cannot be fetched by guessing its URL on servers that ignore .htaccess.
 * Before 1.6.2 the log was a .php file with an exit guard; it is moved into
 * the new file the first time the log is used.
 *
 * Each entry gets a short reference code. The same code is shown to the user
 * in the error notice, so "it says SS-4F2A9C" is enough to find the entry.
 */
final class Log {

    /** Rotate at this size; one previous file is kept. */
    private const MAX_BYTES = 524288;

    /** First line of the pre-1.6.2 .php log files. */
    private const LEGACY_GUARD = "<?php exit; ?>\n";

    /** Resolved log file for this request. */
    private static ?string $file = null;

    /** Context keys whose values must never reach the log. */
    private const SECRET_KEYS = [
        'token', 'key', 'nonce', 'password', 'pass', 'secret', 'authorization',
        'finalize_token', 'job_token', 'worker_key', 'access_token', 'refresh_token',
    ];

    public static function dir(): string {
        return SITESSAVER_STORAGE_DIR . '/logs';
    }

    /**
     * The current log file: sitessaver-log-<random>.log.
     *
     * The random part is not stored in the database on purpose: a restore
     * replaces the database, and the log written during that restore must
     * still be found afterwards. It is found by listing the folder instead.
     */
    public static function file(): string {
        if (self::$file !== null) {
            return self::$file;
        }
        $dir   = self::dir();
        $found = self::existing($dir);
        if ($found !== null) {
            return self::$file = $found;
        }
        // random_bytes(): available even before pluggable.php is loaded.
        $new = $dir . '/sitessaver-log-' . bin2hex(random_bytes(10)) . '.log';
        $fh  = @fopen($new, 'xb');
        if ($fh) {
            fclose($fh);
        }
        // Two requests can create a file at the same moment: both then use
        // the first one by name, and the other empty file is dropped.
        $first = self::existing($dir) ?? $new;
        if ($first !== $new && is_file($new) && (int) @filesize($new) === 0) {
            @unlink($new);
        }
        return self::$file = $first;
    }

    /** First log file in the folder by name, or null. */
    private static function existing(string $dir): ?string {
        $found = glob($dir . '/sitessaver-log-*.log');
        $found = is_array($found) ? $found : [];
        sort($found);
        foreach ($found as $f) {
            // Also matches the rotated "<name>.1.log", which may be the only
            // file left right after a rotation.
            if (preg_match('/^sitessaver-log-([A-Za-z0-9]{20})(?:\.1)?\.log$/', basename($f), $m)) {
                return $dir . '/sitessaver-log-' . $m[1] . '.log';
            }
        }
        return null;
    }

    private static function rotated_file(): string {
        return (string) preg_replace('/\.log$/', '.1.log', self::file());
    }

    /**
     * Move a pre-1.6.2 log (sitessaver-log.php / sitessaver-log.1.php, both
     * behind an exit guard) into the current .log files, then delete it.
     */
    private static function migrate_legacy(): void {
        $dir = self::dir();
        foreach (['sitessaver-log.1.php' => self::rotated_file(), 'sitessaver-log.php' => self::file()] as $old => $new) {
            $legacy = $dir . '/' . $old;
            if (!is_file($legacy)) {
                continue;
            }
            $raw = (string) @file_get_contents($legacy);
            if (str_starts_with($raw, self::LEGACY_GUARD)) {
                $raw = substr($raw, strlen(self::LEGACY_GUARD));
            }
            $lines = '';
            foreach (explode("\n", $raw) as $line) {
                $line = trim($line);
                if ($line !== '' && $line[0] === '{') {
                    $lines .= $line . "\n";
                }
            }
            if ($lines === '' || @file_put_contents($new, $lines, FILE_APPEND | LOCK_EX) !== false) {
                @unlink($legacy);
            }
        }
    }

    public static function error(string $code, string $message, array $context = [], ?string $ref = null): string {
        return self::write('error', $code, $message, $context, $ref);
    }

    public static function warning(string $code, string $message, array $context = [], ?string $ref = null): string {
        return self::write('warning', $code, $message, $context, $ref);
    }

    public static function info(string $code, string $message, array $context = [], ?string $ref = null): string {
        return self::write('info', $code, $message, $context, $ref);
    }

    /**
     * A fresh reference code, e.g. "4F2A9C".
     */
    public static function new_ref(): string {
        try {
            return strtoupper(bin2hex(random_bytes(3)));
        } catch (\Throwable $e) {
            return strtoupper(substr(md5(uniqid('', true)), 0, 6));
        }
    }

    /**
     * Append one entry. Never throws: a logging failure must not turn into a
     * second failure on top of the one being reported.
     *
     * @return string The entry's reference code.
     */
    public static function write(string $level, string $code, string $message, array $context = [], ?string $ref = null): string {
        $ref = self::clean_ref($ref) ?? self::new_ref();

        $entry = [
            'ref'     => $ref,
            'time'    => time(),
            'level'   => in_array($level, ['error', 'warning', 'info'], true) ? $level : 'info',
            'code'    => self::clean_code($code),
            'message' => self::truncate($message, 2000),
            'context' => self::redact($context),
            'version' => defined('SITESSAVER_VERSION') ? SITESSAVER_VERSION : '',
        ];

        if (is_multisite()) {
            $entry['site'] = get_current_blog_id();
        }

        try {
            $dir = self::dir();
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            if (function_exists('sitessaver_protect_directory')) {
                sitessaver_protect_directory($dir);
            }

            self::migrate_legacy();

            $file = self::file();
            clearstatcache(true, $file);
            if (is_file($file) && filesize($file) > self::MAX_BYTES) {
                @rename($file, self::rotated_file());
            }

            $line = wp_json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($line)) {
                @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
            }
        } catch (\Throwable $e) {
            // Fall through to error_log below.
        }

        if ($entry['level'] === 'error') {
            error_log(sprintf('[SitesSaver] %s (%s): %s', $entry['code'], $ref, $entry['message']));
        }

        return $ref;
    }

    /**
     * Newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function entries(int $limit = 200): array {
        $entries = [];
        $legacy  = [self::dir() . '/sitessaver-log.1.php', self::dir() . '/sitessaver-log.php'];
        foreach (array_merge($legacy, [self::rotated_file(), self::file()]) as $file) {
            if (!is_readable($file)) {
                continue;
            }
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '' || $line[0] !== '{') {
                    continue;
                }
                $row = json_decode($line, true);
                if (is_array($row)) {
                    $entries[] = $row;
                }
            }
            fclose($handle);
        }

        $entries = array_reverse($entries);

        return array_slice($entries, 0, max(1, $limit));
    }

    /**
     * Plain-text export for the "Download log" button, oldest first.
     */
    public static function as_text(): string {
        $lines = [];
        foreach (array_reverse(self::entries(5000)) as $e) {
            $lines[] = sprintf(
                '%s  %-7s  %-28s  ref %s  %s%s',
                gmdate('Y-m-d H:i:s', (int) ($e['time'] ?? 0)) . ' UTC',
                strtoupper((string) ($e['level'] ?? '')),
                (string) ($e['code'] ?? ''),
                (string) ($e['ref'] ?? ''),
                (string) ($e['message'] ?? ''),
                empty($e['context']) ? '' : '  ' . wp_json_encode($e['context'], JSON_UNESCAPED_SLASHES)
            );
        }

        $header = sprintf(
            "SitesSaver troubleshooting log\nSite: %s\nPlugin: %s | WordPress: %s | PHP: %s\nGenerated: %s UTC\n\n",
            home_url(),
            defined('SITESSAVER_VERSION') ? SITESSAVER_VERSION : '?',
            get_bloginfo('version'),
            PHP_VERSION,
            gmdate('Y-m-d H:i:s')
        );

        return $header . implode("\n", $lines) . "\n";
    }

    public static function clear(): void {
        @unlink(self::file());
        @unlink(self::rotated_file());
        @unlink(self::dir() . '/sitessaver-log.php');
        @unlink(self::dir() . '/sitessaver-log.1.php');
    }

    /**
     * Recursively strip secrets and cap sizes so a context array can never
     * leak a token or blow the log up.
     */
    private static function redact(array $context, int $depth = 0): array {
        $out = [];
        $n   = 0;
        foreach ($context as $k => $v) {
            if (++$n > 40) {
                $out['…'] = 'truncated';
                break;
            }
            $lk = strtolower((string) $k);
            if (in_array($lk, self::SECRET_KEYS, true)) {
                $out[$k] = '[redacted]';
                continue;
            }
            if (is_array($v)) {
                $out[$k] = $depth >= 3 ? '[nested]' : self::redact($v, $depth + 1);
            } elseif (is_string($v)) {
                $out[$k] = self::truncate($v, 1000);
            } elseif (is_scalar($v) || $v === null) {
                $out[$k] = $v;
            } else {
                $out[$k] = '[' . gettype($v) . ']';
            }
        }
        return $out;
    }

    private static function truncate(string $s, int $max): string {
        return strlen($s) > $max ? substr($s, 0, $max) . '…' : $s;
    }

    private static function clean_code(string $code): string {
        $code = strtolower(preg_replace('/[^a-z0-9_]/i', '', $code) ?? '');
        return $code === '' ? 'event' : substr($code, 0, 48);
    }

    private static function clean_ref(?string $ref): ?string {
        if ($ref === null) {
            return null;
        }
        $ref = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $ref) ?? '');
        return strlen($ref) === 6 ? $ref : null;
    }
}
