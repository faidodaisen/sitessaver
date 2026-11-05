<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * What the hosting environment is doing to us.
 *
 * Shared hosts run security software that watches what PHP writes and can
 * refuse or kill it (Imunify360 Proactive Defense, real-time malware
 * scanners). From inside PHP that looks exactly like a damaged backup or a
 * permissions problem, so restores used to be reported as "backup damaged"
 * and users re-downloaded a perfectly good file over and over.
 *
 * This class gives the rest of the plugin three things:
 *   - the operating system's own reason when a write fails;
 *   - a cheap check for known security layers, for the log and the hint;
 *   - a write probe run before a restore touches anything.
 */
final class Environment {

    /** Marker the error classifier keys on. Keep in sync with Errors::classify_restore(). */
    public const WRITE_BLOCKED = 'write blocked';

    /**
     * The last PHP warning's message (e.g. "Permission denied"), or ''.
     * Call right after a failed @fopen()/@mkdir()/@rename().
     */
    public static function last_error(): string {
        $err = error_get_last();
        if (!is_array($err) || empty($err['message'])) {
            return '';
        }
        $msg = (string) $err['message'];
        // "fopen(/home/x/.../database.sql): Failed to open stream: Permission denied"
        // → keep the reason, drop the absolute path (the log must not leak paths).
        $pos = strrpos($msg, ': ');
        $msg = $pos !== false ? substr($msg, $pos + 2) : $msg;
        return trim(substr($msg, 0, 160));
    }

    /**
     * Known security layers that can block or kill backup/restore work.
     *
     * Only cheap, side-effect-free checks; every filesystem check is
     * suppressed because open_basedir usually forbids looking outside home.
     *
     * @return list<string> e.g. ['imunify360', 'cloudlinux']
     */
    public static function security_layers(): array {
        static $found = null;
        if ($found !== null) {
            return $found;
        }
        $found = [];

        // Imunify360 Proactive Defense is a PHP extension; phpinfo() shows an "i360" section.
        $i360 = extension_loaded('i360');
        if (!$i360 && function_exists('ini_get_all')) {
            foreach (array_keys((array) @ini_get_all(null, false)) as $key) {
                if (str_starts_with((string) $key, 'i360') || stripos((string) $key, 'imunify') !== false) {
                    $i360 = true;
                    break;
                }
            }
        }
        if (!$i360) {
            $i360 = @is_dir('/var/imunify360') || @is_dir('/usr/share/i360-php-opts') || @file_exists('/etc/sysconfig/imunify360');
        }
        if ($i360) {
            $found[] = 'imunify360';
        } elseif (@is_dir('/var/imunify') || @file_exists('/etc/sysconfig/imunify-antivirus')) {
            $found[] = 'imunifyav';
        }

        if (@is_dir('/proc/lve') || @file_exists('/etc/cloudlinux-release') || stripos(php_uname('r'), 'lve') !== false) {
            $found[] = 'cloudlinux';
        }
        if (@is_dir('/etc/bitninja') || @is_dir('/opt/bitninja')) {
            $found[] = 'bitninja';
        }
        if (defined('WORDFENCE_VERSION')) {
            $found[] = 'wordfence';
        }
        $prepend = (string) ini_get('auto_prepend_file');
        if ($prepend !== '' && stripos($prepend, 'wordfence-waf') !== false && !in_array('wordfence', $found, true)) {
            $found[] = 'wordfence-waf';
        }
        return $found;
    }

    /**
     * Context for the troubleshooting log.
     *
     * @return array<string, string>
     */
    public static function diagnostics(): array {
        $layers = self::security_layers();
        $dir    = function_exists('sitessaver_storage_dir') ? sitessaver_storage_dir() : (defined('SITESSAVER_STORAGE_DIR') ? SITESSAVER_STORAGE_DIR : WP_CONTENT_DIR);
        $free   = @disk_free_space($dir);
        return [
            'security'      => $layers !== [] ? implode(', ', $layers) : 'none detected',
            'disk_free'     => $free !== false ? (function_exists('size_format') ? size_format((int) $free) : (string) $free) : 'unknown',
            'open_basedir'  => ini_get('open_basedir') !== '' && ini_get('open_basedir') !== false ? 'on' : 'off',
            'max_exec'      => (string) ini_get('max_execution_time'),
            'memory_limit'  => (string) ini_get('memory_limit'),
        ];
    }

    /**
     * Likely reasons the site cannot call itself (background workers), for
     * the log. Only settings visible from PHP; '' when none is found.
     */
    public static function loopback_hint(): string {
        $hints = [];
        if (defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL) {
            $host    = (string) parse_url((string) home_url(), PHP_URL_HOST);
            $allowed = defined('WP_ACCESSIBLE_HOSTS') ? (string) WP_ACCESSIBLE_HOSTS : '';
            if ($host !== 'localhost' && !in_array($host, array_map('trim', explode(',', $allowed)), true)) {
                $hints[] = 'WP_HTTP_BLOCK_EXTERNAL is on in wp-config.php and this site\'s own host is not in WP_ACCESSIBLE_HOSTS';
            }
        }
        if (!empty($_SERVER['PHP_AUTH_USER']) || !empty($_SERVER['REMOTE_USER'])) {
            $hints[] = 'the site sits behind a password prompt (HTTP basic auth), which background requests cannot pass';
        }
        $host = (string) parse_url((string) home_url(), PHP_URL_HOST);
        if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false && gethostbyname($host) === $host) {
            $hints[] = 'this server cannot look up its own domain (' . $host . ') in DNS';
        }
        if (in_array('imunify360', self::security_layers(), true)) {
            $hints[] = 'Imunify360 can block or end requests a site makes to itself';
        }
        return implode('; ', $hints);
    }

    /**
     * Can this server write the kind of files a restore writes, and keep them?
     *
     * Writes a small SQL-looking file and a plain file into $dir, closes
     * them, waits briefly (real-time scanners act a moment after close),
     * then checks they are still there with the right size. Everything is
     * removed afterwards.
     *
     * @return string '' when writing works, otherwise the technical reason
     *                (always containing self::WRITE_BLOCKED).
     */
    public static function probe_write(string $dir): string {
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return self::WRITE_BLOCKED . ': could not create the restore folder (' . (self::last_error() ?: 'no reason given') . ')';
        }

        $payload = [
            'probe-database.sql' => "-- SitesSaver write check\nCREATE TABLE IF NOT EXISTS `ss_probe` (`id` int NOT NULL);\nINSERT INTO `ss_probe` VALUES (1);\n",
            'probe-file.txt'     => str_repeat('SitesSaver write check. ', 512),
        ];

        $written = [];
        $failure = '';
        foreach ($payload as $name => $body) {
            $path = $dir . '/' . $name;
            $h    = @fopen($path, 'c');
            if ($h === false) {
                $failure = sprintf('%s: cannot create %s (%s)', self::WRITE_BLOCKED, $name, self::last_error() ?: 'no reason given');
                break;
            }
            $ok = @fwrite($h, $body) === strlen($body);
            fclose($h);
            $written[$path] = strlen($body);
            if (!$ok) {
                $failure = sprintf('%s: cannot write into %s (%s)', self::WRITE_BLOCKED, $name, self::last_error() ?: 'disk full or quota reached?');
                break;
            }
        }

        if ($failure === '') {
            usleep(800000);
            clearstatcache();
            foreach ($written as $path => $len) {
                if (!is_file($path)) {
                    $failure = sprintf('%s: %s was removed right after it was written (a security scanner on the server?)', self::WRITE_BLOCKED, basename($path));
                    break;
                }
                if ((int) @filesize($path) !== $len) {
                    $failure = sprintf('%s: %s was changed right after it was written (a security scanner on the server?)', self::WRITE_BLOCKED, basename($path));
                    break;
                }
            }
        }

        foreach (array_keys($written) as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        return $failure;
    }
}
