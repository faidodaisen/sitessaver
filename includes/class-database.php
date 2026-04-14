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

        // Get all tables with the WP prefix.
        $tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");

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
     * Import a SQL file into the database.
     */
    public static function import(string $sql_file, string $old_url = '', string $new_url = ''): bool {
        global $wpdb;

        if (!file_exists($sql_file)) {
            return false;
        }

        $contents = file_get_contents($sql_file);
        if ($contents === false) {
            return false;
        }

        // URL replacement if migrating between domains.
        if ($old_url !== '' && $new_url !== '' && $old_url !== $new_url) {
            $contents = self::replace_urls($contents, $old_url, $new_url);
        }

        // Split into individual statements.
        $statements = self::split_sql($contents);

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }

            $wpdb->query($statement); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Restoring full SQL dump.
        }

        return true;
    }

    /**
     * Replace old site URL with new site URL in SQL dump.
     * Handles both plain and serialized strings.
     */
    private static function replace_urls(string $sql, string $old_url, string $new_url): string {
        // Plain URL replacement.
        $sql = str_replace($old_url, $new_url, $sql);

        // Handle serialized data — update string lengths.
        // e.g. s:25:"https://old-domain.com/..." → s:25:"https://new-domain.com/..."
        $old_no_scheme = preg_replace('#^https?://#', '', $old_url);
        $new_no_scheme = preg_replace('#^https?://#', '', $new_url);

        if ($old_no_scheme !== $new_no_scheme) {
            $sql = str_replace($old_no_scheme, $new_no_scheme, $sql);
        }

        // Fix serialized string lengths after replacement.
        $sql = preg_replace_callback(
            '/s:(\d+):"(.*?)";/s',
            static function (array $matches): string {
                $actual_length = strlen($matches[2]);
                return "s:{$actual_length}:\"{$matches[2]}\";";
            },
            $sql
        );

        return $sql;
    }

    /**
     * Split SQL dump into individual statements.
     */
    private static function split_sql(string $sql): array {
        $statements = [];
        $current    = '';
        $in_string  = false;
        $escape     = false;
        $len        = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];

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
                $statements[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = $current;
        }

        return $statements;
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
