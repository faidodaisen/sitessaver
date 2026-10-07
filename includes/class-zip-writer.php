<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Append-only ZIP writer that can be stopped and continued across requests.
 *
 * ZipArchive does all of its real work inside one close() call that cannot
 * be interrupted or resumed. On a host that ends every PHP request after
 * ~30 s, a backup of any real size therefore never finishes. This writer
 * produces a standard ZIP (deflate or stored, data descriptors, ZIP64 when
 * needed) a few files — or a few megabytes of one large file — at a time,
 * and reports a small state array after every unit of work. Handing that
 * state back continues exactly where the last request stopped: the output
 * is cut back to the last committed size, so a request killed mid-write
 * never leaves a half entry behind.
 *
 * Large files are compressed in independent pieces (each piece ends with a
 * full flush, the last with finish). The result is one ordinary deflate
 * stream that any unzip tool reads, while no compressor state ever has to
 * survive between requests.
 */
final class Zip_Writer {

    /** Files at or above this size are written in CHUNK pieces. */
    public const STREAM_MIN = 16 * 1024 * 1024;
    public const CHUNK      = 8 * 1024 * 1024;

    /** Sizes/offsets at or above this switch an entry/archive to ZIP64. */
    public static int $zip64_at = 0xFFFF0000;

    /** Already-compressed formats: deflating them burns time for nothing. */
    private const STORE_EXT = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi',
        'mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'flac', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar',
        'woff', 'woff2', 'pdf', 'wpress', 'jar', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'apk',
    ];

    private const LEVEL = 6;

    /**
     * Build (or continue building) $zip_path from the contents of $source_dir.
     *
     * The archive is written to "$zip_path.part" and renamed into place only
     * when complete, so an unfinished backup never appears as a real one.
     *
     * @param array<int, string>   $exclude     Patterns, matched like Archive::create().
     * @param string               $work_dir    Scratch dir OUTSIDE $source_dir.
     * @param array<string, mixed> $state       [] to start, or the last state committed.
     * @param callable             $should_stop fn(): bool, checked after each unit of work.
     * @param callable             $commit      fn(array $state, bool $force): void.
     * @param callable|null        $progress    fn(int $entries_done, array $current): void.
     * @return array<string, mixed> State; `done` is true when the ZIP is complete.
     */
    public static function build(
        string $source_dir,
        string $zip_path,
        array $exclude,
        string $work_dir,
        array $state,
        callable $should_stop,
        callable $commit,
        ?callable $progress = null
    ): array {
        $source_dir = rtrim(str_replace('\\', '/', $source_dir), '/') . '/';
        $list_file  = $work_dir . '/zip-list.txt';
        $cd_file    = $work_dir . '/zip-cd.bin';
        $part       = $zip_path . '.part';

        if (!is_dir($work_dir)) {
            wp_mkdir_p($work_dir);
        }

        // A request that finished the archive can be stopped before anyone
        // records that it did. The finished file is only ever moved into
        // place after its last byte is written, so its presence is proof.
        if (!empty($state['listed']) && !is_file($part) && is_file($zip_path)) {
            $state['done'] = true;
            return $state;
        }

        if (empty($state['listed'])) {
            $count = self::write_list($source_dir, $exclude, $list_file);
            foreach ([$part, $cd_file] as $stale) {
                if (is_file($stale)) {
                    unlink($stale);
                }
            }
            $state = [
                'listed'  => true,
                'count'   => $count,
                'i'       => 0,
                'lp'      => 0,
                'zip'     => 0,
                'cd'      => 0,
                'entries' => 0,
                'cur'     => null,
            ];
        }

        $out  = @fopen($part, 'c+b');
        $cdh  = @fopen($cd_file, 'c+b');
        $list = @fopen($list_file, 'rb');
        if (!$out || !$cdh || !$list) {
            throw new \RuntimeException('Could not open the ZIP work files in ' . dirname($part) . '.');
        }

        ftruncate($out, (int) $state['zip']);
        fseek($out, 0, SEEK_END);
        ftruncate($cdh, (int) $state['cd']);
        fseek($cdh, 0, SEEK_END);
        fseek($list, (int) $state['lp']);

        $save = static function (bool $force) use (&$state, $out, $cdh, $commit): void {
            fflush($out);
            fflush($cdh);
            $state['zip'] = (int) ftell($out);
            $state['cd']  = (int) ftell($cdh);
            $commit($state, $force);
        };

        try {
            while (true) {
                if (is_array($state['cur'])) {
                    $finished = self::continue_large($source_dir, $out, $cdh, $state, $save, $should_stop);
                    if (!$finished) {
                        return $state; // stopped mid-file; already committed
                    }
                } else {
                    $line = fgets($list);
                    if ($line === false) {
                        break;
                    }
                    $state['lp'] = (int) ftell($list);
                    $relative    = rtrim($line, "\n");
                    if ($relative === '') {
                        continue;
                    }
                    $state['i']++;

                    if (str_ends_with($relative, '/')) {
                        self::add_dir($out, $cdh, $relative, $source_dir, $state);
                    } else {
                        $abs  = $source_dir . $relative;
                        $size = is_file($abs) ? (int) @filesize($abs) : -1;
                        if ($size < 0 || !is_readable($abs)) {
                            // Vanished or unreadable: same as Archive::create()
                            // — skip it, but say so.
                            $state['skipped'] = (int) ($state['skipped'] ?? 0) + 1;
                            error_log('[SitesSaver] Zip: unreadable, skipped — ' . $relative);
                        } elseif ($size >= self::STREAM_MIN) {
                            self::start_large($out, $relative, $abs, $size, $state);
                            $save(false);
                            continue;
                        } else {
                            self::add_small($out, $cdh, $relative, $abs, $size, $state);
                        }
                    }
                }

                $save(false);
                if ($progress !== null) {
                    $progress((int) $state['entries'], []);
                }
                if ($should_stop()) {
                    $save(true);
                    return $state;
                }
            }

            self::finish($out, $cdh, $state);
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
            if (is_resource($cdh)) {
                fclose($cdh);
            }
            if (is_resource($list)) {
                fclose($list);
            }
        }

        if (!@rename($part, $zip_path)) {
            throw new \RuntimeException('Could not move the finished ZIP into place: ' . basename($zip_path));
        }
        foreach ([$list_file, $cd_file] as $done_file) {
            if (is_file($done_file)) {
                unlink($done_file);
            }
        }

        $state['done'] = true;
        return $state;
    }

    /**
     * List every entry to archive, one relative path per line (directories
     * end in "/"). Same selection rules as Archive::create().
     */
    private static function write_list(string $source_dir, array $exclude, string $list_file): int {
        $fh = @fopen($list_file, 'wb');
        if (!$fh) {
            throw new \RuntimeException('Could not write the ZIP file list.');
        }

        $count = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr(str_replace('\\', '/', $file->getPathname()), strlen($source_dir)));
            if ($relative === '' || str_contains($relative, "\n") || str_contains($relative, "\r")) {
                continue;
            }

            $skip = false;
            foreach ($exclude as $pattern) {
                if (fnmatch($pattern, $relative) || fnmatch($pattern, basename($relative))) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            fwrite($fh, $relative . ($file->isDir() ? '/' : '') . "\n");
            $count++;

            if ($count % 500 === 0 && class_exists(Export::class)) {
                Export::tick('zip-list', $count);
            }
        }

        fclose($fh);
        return $count;
    }

    // ---------------------------------------------------------------
    // Entries
    // ---------------------------------------------------------------

    private static function add_dir($out, $cdh, string $relative, string $source_dir, array &$state): void {
        [$time, $date] = self::dos_time((int) @filemtime($source_dir . rtrim($relative, '/')));
        $offset = (int) ftell($out);

        fwrite($out, pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $time, $date, 0, 0, 0, strlen($relative), 0) . $relative);

        self::central($cdh, [
            'name' => $relative, 'method' => 0, 'flags' => 0x0800, 'time' => $time, 'date' => $date,
            'crc' => 0, 'csize' => 0, 'size' => 0, 'offset' => $offset, 'dir' => true, 'z64' => false,
        ]);
        $state['entries']++;
    }

    private static function add_small($out, $cdh, string $relative, string $abs, int $size, array &$state): void {
        $data = $size > 0 ? @file_get_contents($abs) : '';
        if (!is_string($data)) {
            $state['skipped'] = (int) ($state['skipped'] ?? 0) + 1;
            error_log('[SitesSaver] Zip: unreadable, skipped — ' . $relative);
            return;
        }
        $size = strlen($data);

        $crc    = $size > 0 ? (int) hexdec(hash('crc32b', $data)) : 0;
        $method = 0;
        $body   = $data;

        if ($size > 0 && self::should_deflate($relative)) {
            $packed = gzdeflate($data, self::LEVEL);
            if (is_string($packed) && strlen($packed) < $size) {
                $method = 8;
                $body   = $packed;
            }
        }
        unset($data);

        [$time, $date] = self::dos_time((int) @filemtime($abs));
        $offset = (int) ftell($out);
        $flags  = 0x0800;

        fwrite($out, pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, $time, $date, $crc, strlen($body), $size, strlen($relative), 0) . $relative);
        if (fwrite($out, $body) !== strlen($body)) {
            throw new \RuntimeException('Could not write to the ZIP file (disk full?) while adding ' . $relative . '.');
        }

        self::central($cdh, [
            'name' => $relative, 'method' => $method, 'flags' => $flags, 'time' => $time, 'date' => $date,
            'crc' => $crc, 'csize' => strlen($body), 'size' => $size, 'offset' => $offset, 'dir' => false, 'z64' => false,
        ]);
        $state['entries']++;
    }

    private static function start_large($out, string $relative, string $abs, int $size, array &$state): void {
        [$time, $date] = self::dos_time((int) @filemtime($abs));
        $method = self::should_deflate($relative) ? 8 : 0;
        $z64    = $size >= self::$zip64_at;
        $offset = (int) ftell($out);
        $flags  = 0x0808; // UTF-8 names + sizes in a data descriptor

        $extra = $z64 ? pack('vvPP', 0x0001, 16, 0, 0) : '';
        $lsize = $z64 ? 0xFFFFFFFF : 0;
        fwrite($out, pack('VvvvvvVVVvv', 0x04034b50, $z64 ? 45 : 20, $flags, $method, $time, $date, 0, $lsize, $lsize, strlen($relative), strlen($extra)) . $relative . $extra);

        $state['cur'] = [
            'name'   => $relative,
            'size'   => $size,
            'src'    => 0,
            'csize'  => 0,
            'method' => $method,
            'flags'  => $flags,
            'time'   => $time,
            'date'   => $date,
            'offset' => $offset,
            'z64'    => $z64,
            'crc'    => base64_encode(serialize(hash_init('crc32b'))),
        ];
    }

    /**
     * Write pieces of the current large file until it is done or it is time
     * to stop. Returns true when the entry is complete.
     */
    private static function continue_large(string $source_dir, $out, $cdh, array &$state, callable $save, callable $should_stop): bool {
        $cur = &$state['cur'];
        $in  = @fopen($source_dir . $cur['name'], 'rb');
        if (!$in) {
            throw new \RuntimeException('Could not read ' . $cur['name'] . ' while building the ZIP.');
        }
        fseek($in, (int) $cur['src']);

        $ctx = unserialize(base64_decode((string) $cur['crc']), ['allowed_classes' => [\HashContext::class]]);
        if (!$ctx instanceof \HashContext) {
            fclose($in);
            throw new \RuntimeException('Lost the checksum state for ' . $cur['name'] . '.');
        }

        try {
            while ($cur['src'] < $cur['size']) {
                $want  = (int) min(self::CHUNK, $cur['size'] - $cur['src']);
                $chunk = fread($in, $want);
                if (!is_string($chunk) || strlen($chunk) !== $want) {
                    // Some streams return short reads; top up before giving up.
                    while (is_string($chunk) && strlen($chunk) < $want && !feof($in)) {
                        $more = fread($in, $want - strlen($chunk));
                        if (!is_string($more) || $more === '') {
                            break;
                        }
                        $chunk .= $more;
                    }
                    if (!is_string($chunk) || strlen($chunk) !== $want) {
                        throw new \RuntimeException('Could not read ' . $cur['name'] . ' (it may have changed while the backup ran).');
                    }
                }

                hash_update($ctx, $chunk);
                $last = ($cur['src'] + $want) >= $cur['size'];

                if ((int) $cur['method'] === 8) {
                    // A fresh compressor per piece, ending in a full flush:
                    // the next piece (maybe in the next request) needs none
                    // of this one's state.
                    $z    = deflate_init(ZLIB_ENCODING_RAW, ['level' => self::LEVEL]);
                    $body = deflate_add($z, $chunk, $last ? ZLIB_FINISH : ZLIB_FULL_FLUSH);
                } else {
                    $body = $chunk;
                }
                unset($chunk);

                if (fwrite($out, $body) !== strlen($body)) {
                    throw new \RuntimeException('Could not write to the ZIP file (disk full?) while adding ' . $cur['name'] . '.');
                }

                $cur['src']   += $want;
                $cur['csize'] += strlen($body);
                $cur['crc']    = base64_encode(serialize($ctx));

                if (class_exists(Export::class)) {
                    Export::tick('zip-large', (int) $cur['src']);
                }

                if (!$last) {
                    $save(false);
                    if ($should_stop()) {
                        $save(true);
                        return false;
                    }
                }
            }
        } finally {
            fclose($in);
        }

        $crc = (int) hexdec(hash_final($ctx));
        $z64 = (bool) $cur['z64'];

        // Data descriptor (signature form, as every modern reader expects).
        fwrite($out, $z64
            ? pack('VVPP', 0x08074b50, $crc, $cur['csize'], $cur['size'])
            : pack('VVVV', 0x08074b50, $crc, $cur['csize'], $cur['size']));

        self::central($cdh, [
            'name' => $cur['name'], 'method' => (int) $cur['method'], 'flags' => (int) $cur['flags'],
            'time' => (int) $cur['time'], 'date' => (int) $cur['date'], 'crc' => $crc,
            'csize' => (int) $cur['csize'], 'size' => (int) $cur['size'], 'offset' => (int) $cur['offset'],
            'dir' => false, 'z64' => $z64,
        ]);

        unset($cur);
        $state['cur'] = null;
        $state['entries']++;
        return true;
    }

    // ---------------------------------------------------------------
    // Records
    // ---------------------------------------------------------------

    /**
     * Append one central-directory record to the side file.
     *
     * @param array<string, mixed> $e
     */
    private static function central($cdh, array $e): void {
        $big   = self::$zip64_at;
        $z_u   = $e['z64'] || $e['size'] >= $big;
        $z_c   = $e['z64'] || $e['csize'] >= $big;
        $z_o   = $e['offset'] >= $big;
        $extra = '';
        if ($z_u) {
            $extra .= pack('P', $e['size']);
        }
        if ($z_c) {
            $extra .= pack('P', $e['csize']);
        }
        if ($z_o) {
            $extra .= pack('P', $e['offset']);
        }
        if ($extra !== '') {
            $extra = pack('vv', 0x0001, strlen($extra)) . $extra;
        }

        $need = $extra !== '' ? 45 : 20;
        $attr = $e['dir'] ? ((0040755 << 16) | 0x10) : (0100644 << 16);

        fwrite($cdh, pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            (3 << 8) | 45,
            $need,
            $e['flags'],
            $e['method'],
            $e['time'],
            $e['date'],
            $e['crc'],
            $z_c ? 0xFFFFFFFF : $e['csize'],
            $z_u ? 0xFFFFFFFF : $e['size'],
            strlen($e['name']),
            strlen($extra),
            0,
            0,
            0,
            $attr,
            $z_o ? 0xFFFFFFFF : $e['offset']
        ) . $e['name'] . $extra);
    }

    /**
     * Append the central directory and the end records.
     *
     * @param array<string, mixed> $state
     */
    private static function finish($out, $cdh, array $state): void {
        fflush($cdh);
        $cd_offset = (int) ftell($out);
        rewind($cdh);
        while (!feof($cdh)) {
            $buf = fread($cdh, 1024 * 1024);
            if ($buf === false || $buf === '') {
                break;
            }
            fwrite($out, $buf);
        }
        $cd_size = (int) ftell($out) - $cd_offset;
        $n       = (int) $state['entries'];

        if ($n >= 0xFFFF || $cd_offset >= self::$zip64_at || $cd_size >= self::$zip64_at) {
            $z64_offset = (int) ftell($out);
            fwrite($out, pack('VPvvVVPPPP', 0x06064b50, 44, (3 << 8) | 45, 45, 0, 0, $n, $n, $cd_size, $cd_offset));
            fwrite($out, pack('VVPV', 0x07064b50, 0, $z64_offset, 1));
        }

        fwrite($out, pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            min($n, 0xFFFF),
            min($n, 0xFFFF),
            min($cd_size, 0xFFFFFFFF),
            min($cd_offset, 0xFFFFFFFF),
            0
        ));
        fflush($out);
    }

    private static function should_deflate(string $relative): bool {
        if (!function_exists('gzdeflate') || !function_exists('deflate_init')) {
            return false;
        }
        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        return !in_array($ext, self::STORE_EXT, true);
    }

    /** @return array{0: int, 1: int} DOS time, DOS date */
    private static function dos_time(int $ts): array {
        if ($ts <= 0) {
            $ts = time();
        }
        $d = getdate($ts);
        if ($d['year'] < 1980) {
            return [0, (0 << 9) | (1 << 5) | 1];
        }
        return [
            ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
            (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'],
        ];
    }
}
