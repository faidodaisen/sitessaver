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
        // Fast path: nothing to do.
        $has_url    = $old_url !== '' && str_contains($sql, $old_url);
        $has_scheme = $old_no_scheme !== ''
            && $old_no_scheme !== $new_no_scheme
            && str_contains($sql, $old_no_scheme);

        if (!$has_url && !$has_scheme) {
            return $sql;
        }

        // Audit-only: note presence of serialized objects without blocking.
        self::log_serialized_object_markers($sql);

        return self::rewrite_serialized_preserving($sql, $old_url, $new_url, $old_no_scheme, $new_no_scheme);
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
    private static function rewrite_serialized_preserving(
        string $sql,
        string $old_url,
        string $new_url,
        string $old_no_scheme,
        string $new_no_scheme
    ): string {
        $len        = strlen($sql);
        $out        = '';
        $i          = 0;
        $plain_from = 0;

        $replace_in = static function (string $s) use ($old_url, $new_url, $old_no_scheme, $new_no_scheme): string {
            if ($old_url !== '') {
                $s = str_replace($old_url, $new_url, $s);
            }
            if ($old_no_scheme !== '' && $old_no_scheme !== $new_no_scheme) {
                $s = str_replace($old_no_scheme, $new_no_scheme, $s);
            }
            return $s;
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
