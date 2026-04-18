<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Database export & import using wpdb.
 */
final class Database {

    /**
     * Export all tables to a SQL file.
     */
    public static function export(string $output_file): bool {
        global $wpdb;

        $handle = fopen($output_file, 'w');
        if ($handle === false) {
            return false;
        }

        // Header.
        fwrite($handle, "-- SitesSaver Database Export\n");
        fwrite($handle, "-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n");
        fwrite($handle, "-- WordPress: " . get_bloginfo('version') . "\n");
        fwrite($handle, "-- Site URL: " . home_url() . "\n\n");
        fwrite($handle, "SET NAMES utf8mb4;\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n");
        fwrite($handle, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        // Get all tables with the WP prefix (escaped LIKE to avoid `_` wildcard
        // accidentally matching neighbouring-prefix tables).
        $tables = $wpdb->get_col(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%')
        );

        foreach ($tables as $table) {
            self::export_table($wpdb, $handle, $table);
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($handle);

        return true;
    }

    /**
     * Export a single table: DROP + CREATE + INSERT.
     */
    private static function export_table(\wpdb $wpdb, $handle, string $table): void {
        $escaped_table = esc_sql($table);

        // DROP + CREATE.
        fwrite($handle, "-- Table: {$table}\n");
        fwrite($handle, "DROP TABLE IF EXISTS `{$escaped_table}`;\n");

        $create = $wpdb->get_row("SHOW CREATE TABLE `{$escaped_table}`", ARRAY_N);
        if ($create && isset($create[1])) {
            fwrite($handle, $create[1] . ";\n\n");
        }

        // Data — chunked to avoid memory issues.
        $chunk_size = 100;
        $offset     = 0;

        while (true) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$escaped_table}` LIMIT %d OFFSET %d",
                    $chunk_size,
                    $offset
                ),
                ARRAY_A
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $values = array_map(static function ($val): string {
                    if ($val === null) {
                        return 'NULL';
                    }
                    return "'" . self::escape_sql_value((string) $val) . "'";
                }, $row);

                $columns = implode('`, `', array_keys($row));
                $vals    = implode(', ', $values);

                fwrite($handle, "INSERT INTO `{$escaped_table}` (`{$columns}`) VALUES ({$vals});\n");
            }

            $offset += $chunk_size;
        }

        fwrite($handle, "\n");
    }

    /**
     * Escape a value for a single-quoted SQL string literal.
     *
     * Mirrors mysqli_real_escape_string EXCEPT it does NOT escape `"`.
     * Leaving `"` unescaped preserves `s:N:"..."` serialized-string markers
     * in the dump so the import-time walker can recompute byte-lengths after
     * URL replacement. Since values are wrapped in single quotes, `"` does
     * not need escaping for SQL validity.
     */
    private static function escape_sql_value(string $val): string {
        return strtr($val, [
            '\\'   => '\\\\',
            "\0"   => '\\0',
            "\n"   => '\\n',
            "\r"   => '\\r',
            "\x1a" => '\\Z',
            "'"    => "\\'",
        ]);
    }

    /**
     * Read buffer size for streaming SQL import (bytes).
     * Small enough to stay within memory; large enough for throughput.
     */
    private const IMPORT_READ_CHUNK = 65536;

    /**
     * Import a SQL file into the database using a streaming tokenizer.
     *
     * Memory is O(largest single statement), not O(file size), so multi-GB
     * dumps import without OOM. URL replacement is applied per-statement.
     *
     * @throws \RuntimeException If the dump contains serialized PHP objects
     *                           (security guardrail against object injection).
     */
    public static function import(string $sql_file, string $old_url = '', string $new_url = ''): bool {
        global $wpdb;

        if (!file_exists($sql_file)) {
            return false;
        }

        $handle = fopen($sql_file, 'rb');
        if ($handle === false) {
            return false;
        }

        $do_replace = ($old_url !== '' && $new_url !== '' && $old_url !== $new_url);
        $old_no_scheme = $do_replace ? (string) preg_replace('#^https?://#', '', $old_url) : '';
        $new_no_scheme = $do_replace ? (string) preg_replace('#^https?://#', '', $new_url) : '';

        $current    = '';
        $in_string  = false;
        $escape     = false;

        try {
            while (!feof($handle)) {
                $buffer = fread($handle, self::IMPORT_READ_CHUNK);
                if ($buffer === false || $buffer === '') {
                    break;
                }

                $buf_len = strlen($buffer);
                for ($i = 0; $i < $buf_len; $i++) {
                    $char = $buffer[$i];

                    if ($escape) {
                        $current .= $char;
                        $escape = false;
                        continue;
                    }

                    if ($char === '\\') {
                        $current .= $char;
                        $escape = true;
                        continue;
                    }

                    if ($char === "'") {
                        $in_string = !$in_string;
                        $current .= $char;
                        continue;
                    }

                    if (!$in_string && $char === ';') {
                        self::execute_statement($wpdb, $current, $do_replace, $old_url, $new_url, $old_no_scheme, $new_no_scheme);
                        $current = '';
                        continue;
                    }

                    $current .= $char;
                }
            }

            // Trailing statement without closing `;`.
            if (trim($current) !== '') {
                self::execute_statement($wpdb, $current, $do_replace, $old_url, $new_url, $old_no_scheme, $new_no_scheme);
            }
        } finally {
            fclose($handle);
        }

        return true;
    }

    /**
     * Normalise, URL-replace, and execute a single SQL statement.
     *
     * Why strip-leading-comments matters:
     *   The tokenizer splits on top-level `;`, so a statement can legitimately
     *   look like `-- Table: wp_users\nDROP TABLE IF EXISTS wp_users;` — the
     *   leading comment block is attached to the real DDL. A naive
     *   `str_starts_with('--')` skip would silently drop the DROP TABLE and
     *   cause "Table already exists" + duplicate-PK failures on restore.
     */
    private static function execute_statement(
        \wpdb $wpdb,
        string $statement,
        bool $do_replace,
        string $old_url,
        string $new_url,
        string $old_no_scheme,
        string $new_no_scheme
    ): void {
        $trimmed = self::strip_leading_comments($statement);
        if ($trimmed === '') {
            return;
        }

        if ($do_replace) {
            $trimmed = self::replace_urls_with_aliases($trimmed, $old_url, $new_url, $old_no_scheme, $new_no_scheme);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Restoring trusted admin-provided SQL dump.
        $result = $wpdb->query($trimmed);

        // Log failures so silent row-loss is visible in debug.log rather than
        // surfacing only as "data missing after restore". wpdb returns false on
        // DDL/DML errors; last_error carries the MySQL message.
        if ($result === false && $wpdb->last_error !== '') {
            $preview = substr($trimmed, 0, 160);
            error_log('[SitesSaver] Import SQL failed: ' . $wpdb->last_error . ' | stmt: ' . $preview);
        }
    }

    /**
     * Strip leading `--` and `/* *\/` comment lines from a statement, then trim.
     *
     * Leaves trailing/inline comments intact (MySQL accepts them). Returns
     * empty string if the statement was nothing but comments/whitespace.
     */
    private static function strip_leading_comments(string $statement): string {
        $s = ltrim($statement);
        while ($s !== '') {
            if (str_starts_with($s, '--')) {
                $nl = strpos($s, "\n");
                if ($nl === false) {
                    return '';
                }
                $s = ltrim(substr($s, $nl + 1));
                continue;
            }
            if (str_starts_with($s, '/*')) {
                $end = strpos($s, '*/');
                if ($end === false) {
                    return '';
                }
                $s = ltrim(substr($s, $end + 2));
                continue;
            }
            break;
        }
        return $s;
    }

    /**
     * Replace old site URL with new site URL in a SQL fragment.
     *
     * Correctness:
     *   - Recomputes serialized string byte-lengths with strlen() (byte count).
     *   - Only rewrites `s:N:"...";` tokens whose content actually changed.
     *
     * Note on serialized objects: legitimate WordPress data routinely contains
     * serialized objects (stdClass widgets, cron hooks, transient payloads),
     * so a blanket reject produces false positives on real backups. Import is
     * already gated by `manage_options` + nonce + manifest signature, which
     * puts it inside the trusted-admin boundary. We log detected objects for
     * auditability but do not abort the restore.
     *
     * Works on full dumps or individual statements (streaming-safe).
     */
    private static function replace_urls_with_aliases(
        string $sql,
        string $old_url,
        string $new_url,
        string $old_no_scheme,
        string $new_no_scheme
    ): string {
        // Build a comprehensive replacement map covering every scheme +
        // escape variant we've encountered in the wild. Why this matters:
        //
        // Page builders (Breakdance, Elementor, Oxygen, Bricks) store their
        // tree as JSON-inside-postmeta, and inside that JSON URLs are stored
        // with backslash-escaped slashes: `https:\/\/old.com\/...`. Further,
        // sites frequently mix schemes — a site running on HTTPS exports
        // with `home_url = https://old.com` but the backup's DB can
        // legitimately contain HTTP variants (hardcoded assets, CDN
        // fallbacks, plugins that force-regenerate URLs). When migrating
        // to a local dev site on HTTP (e.g. Laragon's feldatravel.test),
        // the replacer must swap BOTH `https://old.com` AND `http://old.com`
        // to the destination's canonical URL — otherwise Breakdance renders
        // `<img src="https://feldatravel.test/...">` on a server that only
        // answers HTTP, and the browser shows a broken image.
        //
        // We derive bare hosts from both old_url and new_url, then build
        // the cross-product of schemes × escape-forms so strtr() can do a
        // single-pass replacement with array_combine(). This mirrors how
        // All-in-One WP Migration handles the problem.
        $pairs = self::build_replacement_pairs($old_url, $new_url, $old_no_scheme, $new_no_scheme);
        if (empty($pairs)) {
            return $sql;
        }

        // Fast-path: only rewrite statements that actually contain one of
        // our source patterns. Checking str_contains against each `from`
        // is cheap compared to the serialize-preserving walker.
        $hit = false;
        foreach ($pairs as $from => $_) {
            if ($from !== '' && str_contains($sql, $from)) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            return $sql;
        }

        // Audit-only: note presence of serialized objects without blocking.
        self::log_serialized_object_markers($sql);

        return self::rewrite_serialized_preserving($sql, $pairs);
    }

    /**
     * Build the full replacement map. Returns [from => to, ...] with:
     *   - scheme variants: https://, http://, and protocol-relative //
     *   - bare host variant (feldatravel.fidodesign.dev → feldatravel.test)
     *   - JSON-escaped variants (forward slashes as \/ )
     *   - trailing-slash variants dropped (str_replace is substring-based)
     *
     * All entries map TO the new canonical URL (with its new scheme), so
     * cross-scheme migrations (https → http or vice-versa) flatten to the
     * destination's canonical form.
     *
     * Order matters: longest patterns come first so strtr() (which is
     * greedy on longest match) rewrites the most specific variants before
     * the shorter bare-host one touches a substring of a URL.
     *
     * @return array<string,string>
     */
    public static function build_replacement_pairs(
        string $old_url,
        string $new_url,
        string $old_no_scheme,
        string $new_no_scheme
    ): array {
        if ($old_url === '' || $new_url === '') {
            return [];
        }

        // Strip trailing slashes so substitutions don't double-slash the
        // following path component.
        $old_url       = rtrim($old_url, '/');
        $new_url       = rtrim($new_url, '/');
        $old_no_scheme = rtrim($old_no_scheme, '/');
        $new_no_scheme = rtrim($new_no_scheme, '/');

        // Idempotency guard — if old === new in every respect, skip the
        // whole replacement pass. Running this twice with the same map on
        // already-replaced content is a no-op, but short-circuiting saves
        // the whole byte-walker run on multi-GB dumps.
        if ($old_url === $new_url && $old_no_scheme === $new_no_scheme) {
            return [];
        }

        // Short-pattern safety — refuse to rewrite a bare hostname that
        // contains no dot (e.g. `localhost`). Substring-matching against
        // `localhost` would catch it inside any longer word and produce
        // destructive collisions (`localhost.example.com`, or the string
        // `localhost` mentioned in prose). A real WordPress site URL always
        // has either a TLD dot or an explicit port — the latter case users
        // should keep in the source/dest URL as http://localhost:8080.
        $has_dot = str_contains($old_no_scheme, '.') && str_contains($new_no_scheme, '.');

        $new_scheme = str_starts_with($new_url, 'https://') ? 'https' : 'http';

        // www ↔ non-www variants — WordPress sites commonly migrate between
        // www.example.com and example.com. The canonical pair above handles
        // the configured URLs; these extras catch the other variant that
        // might linger in post_content or widget data from earlier moves.
        $old_hosts = [$old_no_scheme];
        $new_hosts = [$new_no_scheme];
        if (str_starts_with($old_no_scheme, 'www.') && !str_starts_with($new_no_scheme, 'www.')) {
            $old_hosts[] = substr($old_no_scheme, 4);
            $new_hosts[] = $new_no_scheme;
        } elseif (!str_starts_with($old_no_scheme, 'www.') && str_starts_with($new_no_scheme, 'www.')) {
            $old_hosts[] = 'www.' . $old_no_scheme;
            $new_hosts[] = $new_no_scheme;
        }

        $variants = [];
        foreach ($old_hosts as $idx => $old_host) {
            $new_host = $new_hosts[$idx] ?? $new_no_scheme;

            // Scheme-explicit plain.
            $variants['https://' . $old_host] = $new_scheme . '://' . $new_host;
            $variants['http://'  . $old_host] = $new_scheme . '://' . $new_host;

            // JSON-escaped (`https:\/\/old.com`) — page builders store their
            // trees as JSON inside postmeta; json_encode() escapes forward
            // slashes by default.
            $variants['https:\\/\\/' . $old_host] = $new_scheme . ':\\/\\/' . $new_host;
            $variants['http:\\/\\/'  . $old_host] = $new_scheme . ':\\/\\/' . $new_host;

            // Protocol-relative (used in CSS/HTML for retina + CDN patterns).
            $variants['//' . $old_host]     = '//' . $new_host;
            $variants['\\/\\/' . $old_host] = '\\/\\/' . $new_host;

            // Bare host fallback (relative URLs, dangling hosts, references
            // in plain prose). Only emit when safe (has dot) and hosts differ.
            if ($has_dot && $old_host !== $new_host) {
                $variants[$old_host] = $new_host;
            }
        }

        // Drop any self-mappings (no-op) and empty keys.
        foreach ($variants as $from => $to) {
            if ($from === '' || $from === $to) {
                unset($variants[$from]);
            }
        }

        return $variants;
    }

    /**
     * Remove leading http:// or https:// from a string, returning the
     * protocol-relative form. Preserves the rest of the URL byte-for-byte.
     */
    private static function strip_scheme_prefix(string $url): string {
        return (string) preg_replace('#^https?:#i', '', $url);
    }

    /**
     * Log (once per import) whether the SQL contains `O:` or `C:` serialized
     * class markers. Non-blocking — legitimate WordPress data contains these.
     *
     * Detects both unescaped (`O:8:"X":...`) and SQL-escaped (`O:8:\"X\":...`)
     * forms so legacy backups (mysqli_real_escape_string era) are also covered.
     */
    private static function log_serialized_object_markers(string $sql): void {
        static $already_logged = false;
        if ($already_logged) {
            return;
        }
        $patterns = [
            '/\bO:\d+:"[^"]+":\d+:\{/',
            '/\bC:\d+:"[^"]+":\d+:\{/',
            '/\bO:\d+:\\\\"[^"\\\\]+\\\\":\d+:\{/',
            '/\bC:\d+:\\\\"[^"\\\\]+\\\\":\d+:\{/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                error_log('[SitesSaver] Notice: imported dump contains serialized PHP objects (O:/C: markers). This is normal for widgets/cron/transients.');
                $already_logged = true;
                return;
            }
        }
    }

    /**
     * Single-pass byte-stream walker that:
     *   1. Replaces URL occurrences in plain text regions.
     *   2. For each `s:<N>:"..."` serialized-string token (unescaped form) or
     *      `s:<N>:\"...\"` (SQL-escaped form used by legacy dumps), validates
     *      the declared byte-length, replaces URLs inside the logical payload,
     *      and recomputes the length prefix ONLY if the payload changed.
     *
     * Handling both forms is required because older SitesSaver exports used
     * mysqli_real_escape_string (which escapes `"`), and those backups must
     * still import correctly when the site URL changes.
     */
    /**
     * @param array<string,string> $pairs Replacement map built by build_replacement_pairs().
     */
    private static function rewrite_serialized_preserving(string $sql, array $pairs): string {
        $len        = strlen($sql);
        $out        = '';
        $i          = 0;
        $plain_from = 0;

        // Longest-first so more-specific variants (scheme+host) rewrite
        // before the bare-host pattern could touch the same substring.
        uksort($pairs, static fn($a, $b): int => strlen($b) <=> strlen($a));
        $from = array_keys($pairs);
        $to   = array_values($pairs);

        $replace_in = static function (string $s) use ($from, $to): string {
            // strtr with an associative array is the canonical multi-pattern
            // single-pass replacer — same approach All-in-One WP Migration
            // uses. It's O(n·m) in worst case but much faster in practice
            // than looping str_replace().
            return strtr($s, array_combine($from, $to));
        };

        while ($i < $len) {
            // Look for the next `s:` that could start a serialized string token.
            if ($sql[$i] !== 's' || $i + 3 >= $len || $sql[$i + 1] !== ':') {
                $i++;
                continue;
            }

            // Read digit run after `s:`.
            $d = $i + 2;
            while ($d < $len && ctype_digit($sql[$d])) {
                $d++;
            }
            if ($d === $i + 2 || $d + 1 >= $len || $sql[$d] !== ':') {
                $i++;
                continue;
            }

            $declared_len = (int) substr($sql, $i + 2, $d - ($i + 2));

            // Detect token form:
            //   unescaped:   s:N:"..."
            //   SQL-escaped: s:N:\"...\"
            $token = null;
            if ($sql[$d + 1] === '"') {
                $token = self::parse_unescaped_sstring($sql, $len, $d + 2, $declared_len);
            } elseif ($d + 2 < $len && $sql[$d + 1] === '\\' && $sql[$d + 2] === '"') {
                $token = self::parse_escaped_sstring($sql, $len, $d + 3, $declared_len);
            }

            if ($token === null) {
                $i++;
                continue;
            }

            // Flush plain region with URL replacement applied.
            if ($plain_from < $i) {
                $out .= $replace_in(substr($sql, $plain_from, $i - $plain_from));
            }

            $content     = $token['content'];
            $new_content = $replace_in($content);

            if ($new_content === $content) {
                // Unchanged: emit verbatim bytes (preserves original escape form).
                $out .= substr($sql, $i, $token['end_pos'] - $i);
            } else {
                $new_byte_len = strlen($new_content);
                if ($token['escaped']) {
                    // Re-emit in SQL-escaped form so the surrounding SQL literal stays valid.
                    $out .= 's:' . $new_byte_len . ':\\"' . self::escape_sql_value($new_content) . '\\";';
                } else {
                    $out .= 's:' . $new_byte_len . ':"' . $new_content . '";';
                }
            }

            $i          = $token['end_pos'];
            $plain_from = $i;
        }

        // Trailing plain region.
        if ($plain_from < $len) {
            $out .= $replace_in(substr($sql, $plain_from));
        }

        return $out;
    }

    /**
     * Parse an unescaped `s:N:"..."` serialized-string token at the given offset.
     *
     * @return array{content:string,end_pos:int,escaped:bool}|null
     */
    private static function parse_unescaped_sstring(string $sql, int $len, int $content_start, int $declared_len): ?array {
        $content_end = $content_start + $declared_len;
        if ($content_end + 1 >= $len || $sql[$content_end] !== '"' || $sql[$content_end + 1] !== ';') {
            return null;
        }
        return [
            'content' => substr($sql, $content_start, $declared_len),
            'end_pos' => $content_end + 2,
            'escaped' => false,
        ];
    }

    /**
     * Parse a SQL-escaped `s:N:\"...\"` serialized-string token.
     *
     * Decodes backslash escapes while counting logical (unescaped) bytes to
     * find the closing `\";`. Returns the decoded content so URL replacement
     * operates on the same byte sequence the length prefix refers to.
     *
     * @return array{content:string,end_pos:int,escaped:bool}|null
     */
    private static function parse_escaped_sstring(string $sql, int $len, int $content_start, int $declared_len): ?array {
        $pos     = $content_start;
        $content = '';
        $count   = 0;

        while ($pos < $len && $count < $declared_len) {
            if ($sql[$pos] === '\\' && $pos + 1 < $len) {
                $next = $sql[$pos + 1];
                switch ($next) {
                    case '0':  $content .= "\0";    break;
                    case 'n':  $content .= "\n";    break;
                    case 'r':  $content .= "\r";    break;
                    case 'Z':  $content .= "\x1a";  break;
                    case '\\': $content .= '\\';    break;
                    case "'":  $content .= "'";     break;
                    case '"':  $content .= '"';     break;
                    default:   $content .= $next;
                }
                $pos += 2;
            } else {
                $content .= $sql[$pos];
                $pos++;
            }
            $count++;
        }

        if ($count !== $declared_len) {
            return null;
        }
        // Expect closing `\";`
        if ($pos + 2 >= $len || $sql[$pos] !== '\\' || $sql[$pos + 1] !== '"' || $sql[$pos + 2] !== ';') {
            return null;
        }

        return [
            'content' => $content,
            'end_pos' => $pos + 3,
            'escaped' => true,
        ];
    }

    /**
     * Get total database size in bytes.
     */
    public static function get_size(): int {
        global $wpdb;

        $size = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(data_length + index_length)
                 FROM information_schema.TABLES
                 WHERE table_schema = %s
                 AND table_name LIKE %s",
                DB_NAME,
                $wpdb->prefix . '%'
            )
        );

        return (int) ($size ?? 0);
    }
}
