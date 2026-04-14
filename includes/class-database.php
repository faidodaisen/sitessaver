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
                $values = array_map(static function ($val) use ($wpdb): string {
                    if ($val === null) {
                        return 'NULL';
                    }
                    return "'" . $wpdb->_real_escape((string) $val) . "'";
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
        $trimmed = trim($statement);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
            return;
        }

        if ($do_replace) {
            $trimmed = self::replace_urls_with_aliases($trimmed, $old_url, $new_url, $old_no_scheme, $new_no_scheme);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Restoring trusted admin-provided SQL dump.
        $wpdb->query($trimmed);
    }

    /**
     * Replace old site URL with new site URL in a SQL fragment.
     *
     * Security:
     *   - Rejects dumps containing serialized PHP objects (CWE-502 guard).
     * Correctness:
     *   - Recomputes serialized string byte-lengths with mb_strlen(..., '8bit').
     *   - Only rewrites `s:N:"...";` tokens whose content actually changed,
     *     preventing an attacker from exploiting a blanket length-normaliser
     *     to smuggle malformed serialized payloads past WP's unserialize().
     *
     * Works on full dumps or individual statements (streaming-safe).
     *
     * @throws \RuntimeException If serialized PHP objects are detected.
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

        // Security gate: serialized PHP objects have no legitimate place in a
        // WP options/meta dump. Refuse rather than try to "fix" them.
        self::assert_no_serialized_objects($sql);

        return self::rewrite_serialized_preserving($sql, $old_url, $new_url, $old_no_scheme, $new_no_scheme);
    }

    /**
     * Throw if the SQL contains `O:` or `C:` serialized class markers.
     */
    private static function assert_no_serialized_objects(string $sql): void {
        // O:<digits>:"ClassName":<digits>:{   or   C:<digits>:"ClassName":<digits>:{
        if (
            preg_match('/\bO:\d+:"[^"]+":\d+:\{/', $sql) === 1
            || preg_match('/\bC:\d+:"[^"]+":\d+:\{/', $sql) === 1
        ) {
            throw new \RuntimeException(
                'Import rejected: SQL dump contains serialized PHP objects (O:/C: markers). '
                . 'Refusing to import to prevent object injection. Verify backup integrity before retrying.'
            );
        }
    }

    /**
     * Single-pass byte-stream walker that:
     *   1. Replaces URL occurrences in plain text regions.
     *   2. For each `s:<N>:"..."` token, validates the declared byte-length,
     *      replaces URLs inside the payload, and recomputes the length prefix
     *      ONLY if the payload byte-count changed.
     *
     * This avoids the `.*?` + /s regex pitfall (null-byte, ReDoS, cross-token
     * matches) and guarantees lengths are only normalised for touched tokens.
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
            if ($d === $i + 2 || $d + 1 >= $len || $sql[$d] !== ':' || $sql[$d + 1] !== '"') {
                $i++;
                continue;
            }

            $declared_len   = (int) substr($sql, $i + 2, $d - ($i + 2));
            $content_start  = $d + 2;
            $content_end    = $content_start + $declared_len;

            // Validate that declared length lands on a closing `";`.
            if ($content_end + 1 >= $len || $sql[$content_end] !== '"' || $sql[$content_end + 1] !== ';') {
                $i++;
                continue;
            }

            // Flush plain region with URL replacement applied.
            if ($plain_from < $i) {
                $out .= $replace_in(substr($sql, $plain_from, $i - $plain_from));
            }

            // Process the serialized string payload.
            $content     = substr($sql, $content_start, $declared_len);
            $new_content = $replace_in($content);

            if ($new_content === $content) {
                // Unchanged: emit verbatim, preserve original length prefix.
                $out .= 's:' . $declared_len . ':"' . $content . '";';
            } else {
                // Changed: recompute byte-length (not char-length).
                $new_byte_len = strlen($new_content); // strlen is byte-count in PHP.
                $out .= 's:' . $new_byte_len . ':"' . $new_content . '";';
            }

            $i          = $content_end + 2;
            $plain_from = $i;
        }

        // Trailing plain region.
        if ($plain_from < $len) {
            $out .= $replace_in(substr($sql, $plain_from));
        }

        return $out;
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
