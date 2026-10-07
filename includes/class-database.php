<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Database export & import using wpdb.
 */
final class Database {

    /**
     * Source/target table prefixes for the current import (empty = no rewrite).
     * Held statically so execute_statement() can see them without widening
     * its already long signature.
     */
    private static string $prefix_from = '';
    private static string $prefix_to   = '';


    /**
     * Optional liveness callback, called after every chunk of rows written.
     *
     * Signature: fn(string $table, int $rows_done_in_table): void
     *
     * The export step that dumps the database can run for many minutes on a
     * large site. Without a heartbeat from inside that loop, the export's
     * last_update stays frozen for the whole dump and the UI's stall detector
     * (Export::STALL_SECONDS) declares a perfectly healthy export dead.
     *
     * @var callable|null
     */
    private static $progress = null;

    public static function set_progress(?callable $cb): void {
        self::$progress = $cb;
    }

    /** Rows fetched per SELECT while dumping a table. */
    private const CHUNK_ROWS = 250;

    /**
     * Export all tables to a SQL file in one go.
     */
    public static function export(string $output_file): bool {
        $cursor = self::export_resumable($output_file, []);
        return !empty($cursor['done']);
    }

    /** Per-table facts looked up once per request (key column, filters). */
    private static array $table_meta = [];

    /**
     * Export all tables to a SQL file, in slices that survive the request
     * being cut short.
     *
     * Some shared hosts stop every PHP request after ~30 s no matter what the
     * plugin asks for. A dump that has to finish inside one request then dies
     * on the first large table, and a retry starts again from the top and
     * dies in the same place. Here the position (table, last key, bytes
     * written) is handed to $commit after every page; the next request passes
     * it back in and the file is cut back to the last committed size and
     * continued. Rows are never written twice and never skipped.
     *
     * @param array<string, mixed> $cursor     [] to start, or a cursor from $commit.
     * @param callable|null        $should_stop fn(): bool — checked after every page.
     * @param callable|null        $commit      fn(array $cursor, bool $force): void.
     * @return array<string, mixed> Cursor; `done` is true when the dump is complete,
     *                              `error` is set when the file could not be opened.
     */
    public static function export_resumable(string $output_file, array $cursor, ?callable $should_stop = null, ?callable $commit = null): array {
        global $wpdb;

        if (empty($cursor['tables'])) {
            $handle = @fopen($output_file, 'wb');
            if ($handle === false) {
                return ['error' => 'open'];
            }
            self::write_dump_header($wpdb, $handle);
            $cursor = [
                'tables'  => self::tables_for_current_site($wpdb),
                't'       => 0,
                'started' => false,
                'key'     => null,
                'offset'  => 0,
                'rows'    => 0,
                'bytes'   => 0,
            ];
        } else {
            $handle = @fopen($output_file, 'c+b');
            if ($handle === false) {
                return ['error' => 'open'];
            }
            // Drop anything written after the last commit: a request killed
            // mid-page leaves a partial page that the cursor does not cover.
            ftruncate($handle, (int) $cursor['bytes']);
            fseek($handle, 0, SEEK_END);
        }

        $save = static function (bool $force) use (&$cursor, $handle, $commit): void {
            fflush($handle);
            $cursor['bytes'] = (int) ftell($handle);
            if ($commit !== null) {
                $commit($cursor, $force);
            }
        };
        $save(true);

        while ($cursor['t'] < count($cursor['tables'])) {
            $table = (string) $cursor['tables'][$cursor['t']];

            if (empty($cursor['started'])) {
                self::export_table_head($wpdb, $handle, $table);
                $cursor['started'] = true;
                $cursor['key']     = null;
                $cursor['offset']  = 0;
                $cursor['rows']    = 0;
            }

            if (!self::export_table_page($wpdb, $handle, $table, $cursor)) {
                fwrite($handle, "\n");
                $cursor['t']++;
                $cursor['started'] = false;
            }

            $save(false);

            if ($should_stop !== null && $cursor['t'] < count($cursor['tables']) && $should_stop()) {
                $save(true);
                fclose($handle);
                return $cursor;
            }
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
        fflush($handle);
        $cursor['bytes'] = (int) ftell($handle);
        fclose($handle);
        $cursor['done'] = true;

        return $cursor;
    }

    /**
     * @param resource $handle
     */
    private static function write_dump_header(\wpdb $wpdb, $handle): void {
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
        //
        // MULTISITE WARNING (do not revert without re-reading this): a plain
        // `LIKE {$wpdb->prefix}%` is only safe on a single-site install. On
        // multisite the network's MAIN site (blog ID 1) uses the bare base
        // prefix (e.g. `wp_`) with NO numeric suffix — and every subsite's
        // tables (`wp_2_posts`, `wp_3_options`, ...) also start with that
        // same literal string, because the subsite prefix is
        // `{base_prefix}{blog_id}_`. A LIKE match on `wp_%` therefore also
        // matches `wp_2_%`, `wp_3_%`, etc. Exporting the main site with the
        // old query silently vacuumed every OTHER subsite's content and DB
        // credentials-adjacent tables into one archive, and also pulled in
        // the ms-global tables (wp_users, wp_usermeta, wp_blogs, wp_site,
        // wp_sitemeta, wp_signups, wp_blogmeta) — which then got a blind
        // `DROP TABLE IF EXISTS` + reinsert on restore, silently wiping every
        // OTHER site's users/blogs list too. tables_for_current_site() scopes
        // this correctly for both the main site and any subsite.
        // (Applied in export_resumable(), which builds the table list.)
    }

    /**
     * Resolve exactly the tables that belong to the CURRENT site, and no
     * others. Single-site installs get the pre-existing behaviour (every
     * table under $wpdb->prefix — there's nothing else in the DB to
     * confuse it with). Multisite requires real disambiguation — see the
     * warning in export() for why a bare LIKE match is unsafe there.
     *
     * @return list<string>
     */
    private static function tables_for_current_site(\wpdb $wpdb): array {
        if (!is_multisite()) {
            return $wpdb->get_col(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%')
            );
        }

        // ms_global_tables PLUS global_tables (wp_users, wp_usermeta,
        // wp_blogs, wp_site, wp_sitemeta, wp_signups, wp_blogmeta,
        // wp_registration_log, ...) are network-wide and deliberately never
        // included in a per-site export/import — they aren't "this site's"
        // data, and DROP+reinsert on restore would clobber every other
        // site on the network.
        //
        // IMPORTANT: $wpdb->ms_global_tables alone is NOT enough — real WP
        // core keeps `users`/`usermeta` in a SEPARATE `$wpdb->global_tables`
        // property (it exists on single-site too, just unused there), while
        // `ms_global_tables` only holds `blogs`/`blogmeta`/`site`/
        // `sitemeta`/`signups`/`registration_log`. Checking only
        // ms_global_tables misses users/usermeta entirely and lets a
        // "per-site" backup silently capture every account on the network.
        // wpdb::tables('global', false) returns the correct UNION of both,
        // unprefixed — that's what must be used here, not either property
        // read in isolation. A future network-scoped backup mode (out of
        // scope here) would need to opt into them explicitly instead of
        // inheriting this exclusion.
        $global_tables = array_map(
            static fn(string $t): string => $wpdb->base_prefix . $t,
            $wpdb->tables('global', false)
        );

        $blog_id = get_current_blog_id();

        if ($blog_id === 1) {
            // Main site: its own tables sit under the bare base prefix with
            // no numeric suffix. Every OTHER subsite's tables also start
            // with that literal string (`{base_prefix}{blog_id}_...`), so
            // they must be explicitly excluded by pattern, not just by a
            // LIKE match on the prefix.
            $all = $wpdb->get_col(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->base_prefix) . '%')
            );

            $subsite_pattern = '/^' . preg_quote($wpdb->base_prefix, '/') . '\d+_/';

            return array_values(array_filter($all, static function (string $table) use ($global_tables, $subsite_pattern): bool {
                if (in_array($table, $global_tables, true)) {
                    return false;
                }
                return preg_match($subsite_pattern, $table) !== 1;
            }));
        }

        // Any other subsite: $wpdb->prefix is already blog-scoped
        // (`{base_prefix}{blog_id}_`), which cannot collide with the main
        // site's tables or another subsite's tables, and never matches the
        // (unsuffixed) global tables either.
        return $wpdb->get_col(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%')
        );
    }

    /**
     * Guard against restoring a dump onto multisite that carries tables
     * outside this site's own scope (another subsite's tables, or the
     * ms-global tables). Without this, a DROP TABLE IF EXISTS in the dump
     * silently destroys another site's data on the same network the moment
     * an admin imports what looks like an ordinary single-site backup —
     * e.g. a backup taken before this scoping fix existed, or one copied
     * from a different subsite's Backups list.
     *
     * Cheap line scan, not the full tokenizer: the dump format is generated
     * by export_table() above and always emits `-- Table: {name}` directly
     * before each table's statements, so a plain fgets() loop is enough to
     * build the table list without paying tokenizer cost on a multi-GB file.
     *
     * @throws \RuntimeException
     */
    private static function assert_import_scope_is_safe(string $sql_file, \wpdb $wpdb): void {
        $allowed = array_flip(self::tables_for_current_site($wpdb));

        $global_tables = array_flip(array_map(
            static fn(string $t): string => $wpdb->base_prefix . $t,
            $wpdb->tables('global', false)
        ));

        $handle = fopen($sql_file, 'rb');
        if ($handle === false) {
            // Can't scan it; import() will fail moments later on the same
            // fopen() anyway, so let that path report the real error.
            return;
        }

        $offending = [];

        while (($line = fgets($handle)) !== false) {
            if (!str_starts_with($line, '-- Table: ')) {
                continue;
            }

            $table = trim(substr($line, strlen('-- Table: ')));
            if ($table === '' || isset($allowed[$table])) {
                continue;
            }

            $offending[] = $table;

            // Cap how much we collect for the error message — the guard's
            // job is to refuse the import, not enumerate every table in a
            // rogue network-wide dump.
            if (count($offending) >= 10) {
                break;
            }
        }

        fclose($handle);

        if ($offending === []) {
            return;
        }

        $blog_id = get_current_blog_id();
        $kind    = array_intersect_key($global_tables, array_flip($offending)) !== []
            ? 'network-wide'
            : 'another site\'s';

        throw new \RuntimeException(sprintf(
            /* Not user-facing __() text — Import::from_backup() catches this
               and wraps it in a translated, non-technical message. Keeping
               table names here only for debug.log / support diagnosis. */
            'SitesSaver refused to import this backup into site #%d: it contains %s tables (%s). ' .
            'This usually means the backup predates per-site scoping, or was taken on a different ' .
            'subsite. Restoring it here would overwrite that other data. Re-export from the correct ' .
            'site and try again.',
            $blog_id,
            $kind,
            implode(', ', $offending)
        ));
    }

    /**
     * Start a table in the dump: DROP + CREATE.
     *
     * @param resource $handle
     */
    private static function export_table_head(\wpdb $wpdb, $handle, string $table): void {
        $escaped_table = esc_sql($table);

        fwrite($handle, "-- Table: {$table}\n");
        fwrite($handle, "DROP TABLE IF EXISTS `{$escaped_table}`;\n");

        $create = $wpdb->get_row("SHOW CREATE TABLE `{$escaped_table}`", ARRAY_N);
        if ($create && isset($create[1])) {
            fwrite($handle, $create[1] . ";\n\n");
        }
    }

    /**
     * Dump ONE page of a table's rows, continuing from $cursor.
     *
     * @param resource             $handle
     * @param array<string, mixed> $cursor key/offset/rows are advanced in place.
     * @return bool True if the table may have more rows.
     */
    private static function export_table_page(\wpdb $wpdb, $handle, string $table, array &$cursor): bool {
        $escaped_table = esc_sql($table);

        if (!isset(self::$table_meta[$table])) {
            self::$table_meta[$table] = [
                'generated' => self::generated_columns($wpdb, $table),
                'filter'    => self::row_filter_for($wpdb, $table),
                'order'     => self::pagination_key($wpdb, $table),
            ];
        }

        // Generated (STORED/VIRTUAL) columns must be omitted from the column
        // list: MySQL rejects any INSERT that supplies a value for them with
        // "The value specified for generated column ... is not allowed", and
        // the whole row is then lost on restore. WooCommerce lookup tables and
        // several analytics plugins use generated columns, so this silently
        // dropped real customer data.
        $generated = self::$table_meta[$table]['generated'];

        // Data — chunked to avoid memory issues.
        $chunk_size = self::CHUNK_ROWS;

        // The options table gets a WHERE clause that drops this plugin's own
        // in-flight export state. Without it, the transients describing the
        // export that is CREATING this backup are captured inside it, and a
        // restore then resurrects a phantom "export in progress" on the target
        // site (progress modal reappears, cancel button does nothing).
        $filter = self::$table_meta[$table]['filter'];

        // Pagination MUST be ordered. `LIMIT/OFFSET` without `ORDER BY` gives
        // MySQL licence to return rows in any order, and OFFSET counts
        // positions in *that* unspecified order. WordPress writes to
        // wp_options constantly — including this plugin's own progress
        // transient after every export step, plus WP expiring other
        // transients on read — so rows shift between pages mid-dump. A row
        // that moves across a page boundary is emitted twice, and the restore
        // then fails with "Duplicate entry for key PRIMARY" and silently
        // drops whatever row took its place.
        //
        // Observed on a stock WP 7.1 install: 142 INSERTs for 120 distinct
        // option_ids, i.e. 22 duplicated rows and 22 rows lost.
        //
        // Ordering by the primary key makes the sequence total and stable.
        $order_col = self::$table_meta[$table]['order'];

        // KEYSET pagination whenever there is a usable key: continue from the
        // last key seen (`WHERE key > last ORDER BY key LIMIT n`) instead of
        // `LIMIT n OFFSET m`. OFFSET makes MySQL walk and discard m rows on
        // every page, so a table's dump costs O(rows^2): on a 427k-row log
        // table the late pages took seconds each and the whole database step
        // ran for tens of minutes, visibly slowing down as it went. A keyset
        // read is an index range scan — every page costs the same, however
        // deep into the table it is. It is also immune to rows being inserted
        // or deleted mid-dump, which OFFSET is not even when ordered.
        //
        // Tables without a usable single-column key fall back to OFFSET
        // (unordered), which is no worse than before.
        $rows_done = (int) ($cursor['rows'] ?? 0);
        $last_key  = isset($cursor['key']) ? (string) $cursor['key'] : null;
        $offset    = (int) ($cursor['offset'] ?? 0);

        {
            if ($order_col !== null) {
                $col   = '`' . esc_sql($order_col) . '`';
                $conds = [];
                if ($filter !== '') {
                    $conds[] = '(' . $filter . ')';
                }
                if ($last_key !== null) {
                    $conds[] = $wpdb->prepare("{$col} > %s", $last_key);
                }
                $where = $conds !== [] ? 'WHERE ' . implode(' AND ', $conds) : '';
                $rows  = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM `{$escaped_table}` {$where} ORDER BY {$col} LIMIT %d",
                        $chunk_size
                    ),
                    ARRAY_A
                );
            } else {
                $where = $filter !== '' ? 'WHERE ' . $filter : '';
                $rows  = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM `{$escaped_table}` {$where} LIMIT %d OFFSET %d",
                        $chunk_size,
                        $offset
                    ),
                    ARRAY_A
                );
            }

            if (empty($rows)) {
                return false;
            }

            foreach ($rows as $row) {
                if ($order_col !== null && array_key_exists($order_col, $row)) {
                    // Read BEFORE generated columns are stripped — the key is
                    // never generated, but keep the invariant obvious.
                    $last_key = (string) $row[$order_col];
                }
                if ($generated !== []) {
                    $row = array_diff_key($row, array_flip($generated));
                }
                if ($row === []) {
                    continue;
                }

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

            $rows_done += count($rows);
            $offset    += $chunk_size;

            $cursor['rows']   = $rows_done;
            $cursor['key']    = $last_key;
            $cursor['offset'] = $offset;

            if (self::$progress !== null) {
                (self::$progress)($table, $rows_done);
            }

            // A short page means the table is exhausted; skip the extra
            // empty round trip.
            return count($rows) >= $chunk_size;
        }
    }

    /**
     * Per-table row filter applied during export.
     *
     * Only the options table is filtered, and only to exclude SitesSaver's own
     * transient export/import state. Everything else is dumped verbatim.
     *
     * Returns a bare boolean condition, no `WHERE` (or an empty string). The
     * fragment contains no user input — the LIKE patterns are literals — so it
     * is safe to interpolate.
     */
    private static function row_filter_for(\wpdb $wpdb, string $table): string {
        if ($table !== $wpdb->options) {
            return '';
        }

        return "option_name NOT LIKE '\\_transient\\_sitessaver\\_%'"
             . " AND option_name NOT LIKE '\\_transient\\_timeout\\_sitessaver\\_%'"
             . " AND option_name NOT LIKE '\\_site\\_transient\\_sitessaver\\_%'"
             . " AND option_name NOT LIKE '\\_site\\_transient\\_timeout\\_sitessaver\\_%'"
             . " AND option_name <> 'sitessaver_pending_finalize'";
    }

    /**
     * Column to order pagination by: the single-column PRIMARY KEY when there
     * is one, otherwise the first UNIQUE NOT NULL column, otherwise null.
     *
     * Only a deterministic total order makes OFFSET pagination safe on a table
     * that is being written to concurrently. Returning null degrades to the
     * previous (unordered) behaviour rather than failing the export.
     */
    private static function pagination_key(\wpdb $wpdb, string $table): ?string {
        $indexes = $wpdb->get_results("SHOW INDEX FROM `" . esc_sql($table) . "`", ARRAY_A);
        if (!is_array($indexes) || $indexes === []) {
            return null;
        }

        // Group by index name so we can reject composite keys, whose first
        // column alone is not a total order.
        $by_index = [];
        foreach ($indexes as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $by_index[$name][] = $row;
        }

        if (isset($by_index['PRIMARY']) && count($by_index['PRIMARY']) === 1) {
            return (string) $by_index['PRIMARY'][0]['Column_name'];
        }

        foreach ($by_index as $name => $cols) {
            if ($name === 'PRIMARY' || count($cols) !== 1) {
                continue;
            }
            // Non_unique === '0' means UNIQUE; Null === '' means NOT NULL.
            if ((string) ($cols[0]['Non_unique'] ?? '1') === '0'
                && (string) ($cols[0]['Null'] ?? 'YES') === ''
            ) {
                return (string) $cols[0]['Column_name'];
            }
        }

        return null;
    }

    /**
     * Names of generated (STORED or VIRTUAL) columns for a table.
     *
     * Returns an empty array when information_schema is unavailable, which
     * degrades to the previous behaviour rather than aborting the export.
     *
     * @return string[]
     */
    private static function generated_columns(\wpdb $wpdb, string $table): array {
        $cols = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = %s
                    AND TABLE_NAME = %s
                    AND GENERATION_EXPRESSION IS NOT NULL
                    AND GENERATION_EXPRESSION <> ''",
                DB_NAME,
                $table
            )
        );

        return is_array($cols) ? array_map('strval', $cols) : [];
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
     * @throws \RuntimeException If, on multisite, the dump's tables reach
     *                           outside this site's own scope — see
     *                           assert_import_scope_is_safe().
     */
    public static function import(string $sql_file, string $old_url = '', string $new_url = '', string $old_prefix = ''): bool {
        global $wpdb;

        if (!file_exists($sql_file)) {
            return false;
        }

        // Table-prefix migration. The dump carries the SOURCE site's table
        // names (`bzm_posts`), but this site's wp-config.php is not restored,
        // so WordPress keeps reading `$wpdb->prefix` (`wp_posts`). Without a
        // rewrite the restore lands in a parallel set of tables nobody reads:
        // files and the active theme switch over, while content, users and
        // options stay the OLD site's — a half-migrated site.
        self::$prefix_from = '';
        self::$prefix_to   = '';
        if (
            $old_prefix !== ''
            && !is_multisite()
            && $old_prefix !== $wpdb->prefix
            && preg_match('/^[A-Za-z0-9_]+$/', $old_prefix)
            && preg_match('/^[A-Za-z0-9_]+$/', (string) $wpdb->prefix)
        ) {
            self::$prefix_from = $old_prefix;
            self::$prefix_to   = (string) $wpdb->prefix;
        }

        if (is_multisite()) {
            self::assert_import_scope_is_safe($sql_file, $wpdb);
        }

        $handle = fopen($sql_file, 'rb');
        if ($handle === false) {
            return false;
        }

        $do_replace = ($old_url !== '' && $new_url !== '' && $old_url !== $new_url);
        $old_no_scheme = $do_replace ? (string) preg_replace('#^https?://#', '', $old_url) : '';
        $new_no_scheme = $do_replace ? (string) preg_replace('#^https?://#', '', $new_url) : '';

        // Tokenizer state. All of it must persist across fread() boundaries —
        // a 64 KB chunk can end anywhere, including halfway through a quoted
        // string, a comment, or a multi-character delimiter.
        $current   = '';
        $in_string = false;   // inside a quoted literal / identifier
        $quote     = '';      // which quote char opened it: ' " or `
        $escape    = false;   // previous byte was a backslash inside a literal
        $in_line_c = false;   // inside a `--` or `#` comment (ends at newline)
        $in_blk_c  = false;   // inside a /* ... */ comment
        $blk_star  = false;   // previous byte inside the block comment was `*`
        $delimiter = ';';     // current statement delimiter (DELIMITER can change it)

        try {
            while (!feof($handle)) {
                $buffer = fread($handle, self::IMPORT_READ_CHUNK);
                if ($buffer === false || $buffer === '') {
                    break;
                }

                // Liveness for a background restore: a multi-GB dump can run
                // for many minutes, and the job watcher must not mistake that
                // for a dead worker. Throttled by the listener.
                do_action('sitessaver_heartbeat');

                $buf_len = strlen($buffer);
                for ($i = 0; $i < $buf_len; $i++) {
                    $char = $buffer[$i];

                    // --- inside a line comment: swallow until newline --------
                    if ($in_line_c) {
                        if ($char === "\n") {
                            $in_line_c = false;
                            $current  .= $char;
                        }
                        continue;
                    }

                    // --- inside a block comment: discard until `*/` ----------
                    // The comment body is dropped, not appended: keeping it in
                    // $current both bloats the statement and (because the body
                    // can contain `*/`-like bytes) confused the terminator scan.
                    // MySQL conditional-execution comments (`/*!40101 ... */`)
                    // never enter this state — they are executable SQL and are
                    // handled as ordinary statement text below.
                    if ($in_blk_c) {
                        if ($blk_star && $char === '/') {
                            $in_blk_c = false;
                            $blk_star = false;
                        } else {
                            $blk_star = ($char === '*');
                        }
                        continue;
                    }

                    // --- inside a quoted string / identifier -----------------
                    if ($in_string) {
                        $current .= $char;

                        if ($escape) {
                            $escape = false;
                            continue;
                        }
                        // Backslash escaping applies to ' and " but NOT to
                        // backtick identifiers, where MySQL has no backslash
                        // escape at all (`` is the only escape).
                        if ($char === '\\' && $quote !== '`') {
                            $escape = true;
                            continue;
                        }
                        if ($char === $quote) {
                            // A doubled quote ('' or "" or ``) is an escaped
                            // quote, not a terminator. We can't see the next
                            // byte if the chunk ends here, so we close the
                            // string and let the re-open on the next byte
                            // restore the state — which yields the identical
                            // result for the purposes of delimiter detection.
                            $in_string = false;
                            $quote     = '';
                        }
                        continue;
                    }

                    // --- outside any string/comment --------------------------

                    // Comment openers. `--` requires a following whitespace or
                    // end-of-line per MySQL, so `SELECT 5--3` is arithmetic.
                    if ($char === '-' && $current !== '' && substr($current, -1) === '-') {
                        $next = self::peek_byte($buffer, $i + 1, $handle);
                        if ($next === '' || $next === ' ' || $next === "\t" || $next === "\n" || $next === "\r") {
                            $in_line_c = true;
                            $current   = substr($current, 0, -1); // drop the first '-'
                            continue;
                        }
                    }
                    if ($char === '#') {
                        $in_line_c = true;
                        continue;
                    }
                    if ($char === '*' && $current !== '' && substr($current, -1) === '/') {
                        // `/*!` and `/*+` are executable comments — keep them.
                        $next = self::peek_byte($buffer, $i + 1, $handle);
                        if ($next !== '!' && $next !== '+') {
                            $in_blk_c = true;
                            $current  = substr($current, 0, -1); // drop the '/'
                            continue;
                        }
                    }

                    // String / identifier openers.
                    if ($char === "'" || $char === '"' || $char === '`') {
                        $in_string = true;
                        $quote     = $char;
                        $current  .= $char;
                        continue;
                    }

                    // DELIMITER directive — mysqldump emits these around
                    // triggers, procedures, and events. Without honouring it
                    // the `;` inside a trigger body splits the statement and
                    // the restore fails with a syntax error.
                    if (($char === 'd' || $char === 'D') && trim($current) === '') {
                        $word = self::read_delimiter_directive($buffer, $i, $handle);
                        if ($word !== null) {
                            $delimiter = $word['delimiter'];
                            $i         = $word['resume_index'];
                            $current   = '';
                            continue;
                        }
                    }

                    // Statement terminator (may be multi-byte after DELIMITER).
                    if ($char === $delimiter[0]
                        && (strlen($delimiter) === 1
                            || self::matches_delimiter($buffer, $i, $delimiter, $handle))
                    ) {
                        self::execute_statement($wpdb, $current, $do_replace, $old_url, $new_url, $old_no_scheme, $new_no_scheme);
                        $current = '';
                        $i      += strlen($delimiter) - 1;
                        continue;
                    }

                    $current .= $char;
                }
            }

            // Trailing statement without a closing delimiter.
            if (trim($current) !== '') {
                self::execute_statement($wpdb, $current, $do_replace, $old_url, $new_url, $old_no_scheme, $new_no_scheme);
            }
        } finally {
            fclose($handle);
        }

        if (self::$prefix_from !== '') {
            self::rename_prefixed_keys($wpdb, self::$prefix_from, self::$prefix_to);
            self::$prefix_from = '';
            self::$prefix_to   = '';
        }

        return true;
    }

    /**
     * Keys WordPress core builds from the table prefix. After the tables are
     * renamed these still carry the SOURCE prefix, and WordPress then finds no
     * roles (every user loses every capability, admin included) and no
     * per-user settings.
     *
     * Deliberately a fixed list, not "every key that starts with the old
     * prefix": a theme or plugin is free to name its own options with the
     * same letters (a theme called `bzm` storing `bzm_gsheets_auth`), and a
     * blanket rename would silently corrupt those.
     */
    private const PREFIX_BOUND_USERMETA = [
        'capabilities',
        'user_level',
        'user-settings',
        'user-settings-time',
        'dashboard_quick_press_last_post_id',
        'persisted_preferences',
    ];

    private static function rename_prefixed_keys(\wpdb $wpdb, string $from, string $to): void {
        $options  = $to . 'options';
        $usermeta = $to . 'usermeta';

        // wp_options: `{prefix}user_roles` holds the whole role table.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$options}` WHERE option_name = %s",
            $to . 'user_roles'
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$options}` SET option_name = %s WHERE option_name = %s",
            $to . 'user_roles',
            $from . 'user_roles'
        ));

        foreach (self::PREFIX_BOUND_USERMETA as $suffix) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$usermeta}` WHERE meta_key = %s",
                $to . $suffix
            ));
            $wpdb->query($wpdb->prepare(
                "UPDATE `{$usermeta}` SET meta_key = %s WHERE meta_key = %s",
                $to . $suffix,
                $from . $suffix
            ));
        }

        wp_cache_flush();
    }

    /**
     * Rewrite the TABLE NAMES in one DDL/DML statement from the source prefix
     * to this site's prefix. Only identifiers in table position are touched —
     * the backticked name right after DROP/CREATE/ALTER/LOCK TABLE(S), INSERT
     * INTO / REPLACE INTO, and REFERENCES inside a CREATE — never row data,
     * so a post that happens to contain the text `bzm_posts` survives as-is.
     */
    public static function rewrite_table_prefix(string $sql, string $from, string $to): string {
        if ($from === '' || $from === $to) {
            return $sql;
        }
        $q = preg_quote($from, '/');

        // Head of statement.
        $sql = (string) preg_replace(
            '/^(\s*(?:DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|ALTER\s+TABLE|LOCK\s+TABLES|INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO)\s+`)' . $q . '/i',
            '${1}' . $to,
            $sql,
            1
        );

        // Foreign keys inside CREATE TABLE point at other prefixed tables.
        if (preg_match('/^\s*CREATE\s+TABLE/i', $sql)) {
            $create_end = strpos($sql, ') ENGINE');
            $head = $create_end === false ? $sql : substr($sql, 0, $create_end);
            $tail = $create_end === false ? '' : substr($sql, $create_end);
            $head = (string) preg_replace('/(REFERENCES\s+`)' . $q . '/i', '${1}' . $to, $head);
            $sql  = $head . $tail;
        }

        return $sql;
    }

    /**
     * Work out the source prefix of an older backup whose manifest has no
     * db_prefix: every WordPress dump contains exactly one `<prefix>options`
     * table, and it is written near the top of the file.
     */
    public static function detect_dump_prefix(string $sql_file): string {
        $h = @fopen($sql_file, 'rb');
        if ($h === false) {
            return '';
        }
        $found = '';
        $scanned = 0;
        while (!feof($h) && $scanned < 64 * 1024 * 1024) {
            $line = fgets($h, 1024 * 1024);
            if ($line === false) {
                break;
            }
            $scanned += strlen($line);
            if (preg_match('/^DROP TABLE IF EXISTS `([A-Za-z0-9_]*?)options`;/', $line, $m)) {
                $found = $m[1];
                break;
            }
        }
        fclose($h);
        return $found;
    }

    /**
     * Look ahead one byte, transparently crossing a chunk boundary.
     *
     * The tokenizer needs one byte of lookahead to disambiguate `--` (comment
     * only when followed by whitespace) and `/*!` (executable comment). When
     * the byte we need is the first byte of the *next* fread() chunk we peek
     * it from the stream and rewind, so the main loop still sees it.
     */
    private static function peek_byte(string $buffer, int $index, $handle): string {
        if ($index < strlen($buffer)) {
            return $buffer[$index];
        }
        $pos  = ftell($handle);
        $byte = (string) fread($handle, 1);
        if ($pos !== false) {
            fseek($handle, $pos);
        }
        return $byte;
    }

    /**
     * Test whether the bytes at $index match a multi-character delimiter,
     * peeking across a chunk boundary when necessary.
     */
    private static function matches_delimiter(string $buffer, int $index, string $delimiter, $handle): bool {
        $len       = strlen($delimiter);
        $available = substr($buffer, $index, $len);
        if (strlen($available) < $len) {
            $pos = ftell($handle);
            $available .= (string) fread($handle, $len - strlen($available));
            if ($pos !== false) {
                fseek($handle, $pos);
            }
        }
        return $available === $delimiter;
    }

    /**
     * Parse a `DELIMITER <token>` directive starting at $index.
     *
     * Returns the new delimiter plus the buffer index to resume from, or null
     * when the text at $index isn't actually a DELIMITER directive.
     *
     * @return array{delimiter:string,resume_index:int}|null
     */
    private static function read_delimiter_directive(string $buffer, int $index, $handle): ?array {
        // Pull enough bytes to cover "DELIMITER " plus a short token, crossing
        // the chunk boundary if needed.
        $window = substr($buffer, $index, 32);
        if (strlen($window) < 32) {
            $pos     = ftell($handle);
            $window .= (string) fread($handle, 32 - strlen($window));
            if ($pos !== false) {
                fseek($handle, $pos);
            }
        }

        if (!preg_match('/^DELIMITER[ \t]+(\S+)[ \t]*(\r?\n|$)/i', $window, $m)) {
            return null;
        }

        $consumed = strlen($m[0]);
        // Only the portion that lives in this buffer advances the loop index;
        // any remainder is naturally consumed by the following reads.
        return [
            'delimiter'    => $m[1],
            'resume_index' => $index + min($consumed, strlen($buffer) - $index) - 1,
        ];
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

        if (self::$prefix_from !== '') {
            $trimmed = self::rewrite_table_prefix($trimmed, self::$prefix_from, self::$prefix_to);
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
     *
     * MySQL conditional-execution comments (`/*!40101 SET ... *\/` and optimiser
     * hints `/*+ ... *\/`) are NOT stripped — they carry executable SQL that
     * mysqldump-produced files depend on. Treating them as comments silently
     * dropped charset/sql_mode setup and produced mojibake on restore.
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
            if (str_starts_with($s, '#')) {
                $nl = strpos($s, "\n");
                if ($nl === false) {
                    return '';
                }
                $s = ltrim(substr($s, $nl + 1));
                continue;
            }
            // `/*!` and `/*+` are executable, not comments — stop stripping.
            if (str_starts_with($s, '/*') && !str_starts_with($s, '/*!') && !str_starts_with($s, '/*+')) {
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
            $new_content = self::rewrite_string_payload($content, $replace_in);

            if ($new_content === $content) {
                // Unchanged: emit verbatim bytes (preserves original escape form).
                $out .= substr($sql, $i, $token['end_pos'] - $i);
            } else {
                // Length prefix counts LOGICAL bytes; the emitted payload must
                // be re-escaped for the surrounding SQL string literal. Getting
                // this pairing wrong is what corrupted every serialized value
                // that contained a quote, backslash, or newline.
                $new_byte_len = strlen($new_content);
                if ($token['escaped']) {
                    // Legacy form also escapes the double quotes themselves.
                    $out .= 's:' . $new_byte_len . ':\\"' . self::escape_sql_value($new_content) . '\\";';
                } else {
                    $out .= 's:' . $new_byte_len . ':"' . self::escape_sql_value($new_content) . '";';
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
     * Rewrite the payload of one serialized string, recursing when that payload
     * is itself serialized data.
     *
     * Why recursion is required:
     *   WordPress stores an already-serialized string through
     *   `maybe_serialize()`, which serializes it a SECOND time. A meta value
     *   like `serialize(['inner' => 'https://old/x'])` is therefore stored as
     *       s:53:"a:1:{s:5:"inner";s:27:"https://old/x";}";
     *   A single-level walker rewrites the OUTER length (53 → 60) but leaves
     *   the INNER `s:27:` untouched, so the outer string unserializes fine and
     *   the inner one fails — the classic "widget/page-builder settings are
     *   empty after migration" symptom. Recursing means every nesting level
     *   gets its length prefix recomputed.
     *
     * Depth is bounded because each level strictly shrinks the payload.
     *
     * @param callable(string):string $replace_in Plain-text replacement callback.
     */
    private static function rewrite_string_payload(string $content, callable $replace_in, int $depth = 0): string {
        // Nested serialized data: recurse so inner length prefixes are fixed
        // too. The cheap `s:` probe keeps the common (non-nested) case fast.
        if ($depth < 8 && str_contains($content, 's:') && self::looks_serialized($content)) {
            $inner = self::rewrite_serialized_preserving_inner($content, $replace_in, $depth + 1);
            if ($inner !== $content) {
                return $inner;
            }
        }

        return $replace_in($content);
    }

    /**
     * Cheap structural test for "this string is itself serialized PHP data".
     *
     * Deliberately conservative: we only recurse for container types whose
     * payload can hold further `s:N:"..."` tokens. A false negative just means
     * we fall back to plain replacement (the previous behaviour); a false
     * positive is harmless because the inner walker is a no-op when it finds
     * no valid tokens.
     */
    private static function looks_serialized(string $value): bool {
        if (strlen($value) < 4) {
            return false;
        }
        return (bool) preg_match('/^(a:\d+:\{|O:\d+:"|s:\d+:")/', $value);
    }

    /**
     * Inner recursion entry point — same walker, applied to an already-decoded
     * serialized payload (no SQL escaping involved at this level).
     *
     * @param callable(string):string $replace_in
     */
    private static function rewrite_serialized_preserving_inner(string $payload, callable $replace_in, int $depth): string {
        $len        = strlen($payload);
        $out        = '';
        $i          = 0;
        $plain_from = 0;

        while ($i < $len) {
            if ($payload[$i] !== 's' || $i + 3 >= $len || $payload[$i + 1] !== ':') {
                $i++;
                continue;
            }

            $d = $i + 2;
            while ($d < $len && ctype_digit($payload[$d])) {
                $d++;
            }
            if ($d === $i + 2 || $d + 1 >= $len || $payload[$d] !== ':' || $payload[$d + 1] !== '"') {
                $i++;
                continue;
            }

            $declared_len = (int) substr($payload, $i + 2, $d - ($i + 2));

            // The payload here has ALREADY been un-escaped by the outer parser,
            // so lengths are plain byte offsets — no escape decoding needed.
            $content_start = $d + 2;
            $content_end   = $content_start + $declared_len;
            if ($content_end + 1 >= $len || $payload[$content_end] !== '"' || $payload[$content_end + 1] !== ';') {
                $i++;
                continue;
            }
            $token = [
                'content' => substr($payload, $content_start, $declared_len),
                'end_pos' => $content_end + 2,
            ];

            if ($plain_from < $i) {
                $out .= $replace_in(substr($payload, $plain_from, $i - $plain_from));
            }

            $content     = $token['content'];
            $new_content = self::rewrite_string_payload($content, $replace_in, $depth);

            if ($new_content === $content) {
                $out .= substr($payload, $i, $token['end_pos'] - $i);
            } else {
                $out .= 's:' . strlen($new_content) . ':"' . $new_content . '";';
            }

            $i          = $token['end_pos'];
            $plain_from = $i;
        }

        if ($plain_from < $len) {
            $out .= $replace_in(substr($payload, $plain_from));
        }

        return $out;
    }

    /**
     * Parse an unescaped `s:N:"..."` serialized-string token at the given offset.
     *
     * IMPORTANT: `N` counts *logical* bytes (what PHP's unserialize() sees),
     * but the token lives inside a SQL string literal where the exporter has
     * escaped backslash, NUL, newline, CR, ^Z and apostrophe. So a value like
     *     https://old.com/a\b        (19 logical bytes)
     * is written to the dump as
     *     s:19:"https://old.com/a\\b"   (20 literal bytes)
     * Slicing 19 raw bytes lands mid-token, the parse fails, and the walker
     * falls back to plain-text replacement — which changes the payload length
     * WITHOUT updating the `s:19:` prefix. unserialize() then rejects the whole
     * row, which is why page-builder / widget settings came back empty after a
     * migration whenever a value happened to contain a quote, a backslash, or a
     * newline. We therefore decode escapes while counting logical bytes.
     *
     * `raw` carries the original (still-escaped) bytes so an unchanged token
     * can be re-emitted verbatim.
     *
     * @return array{content:string,end_pos:int,escaped:bool,raw:string}|null
     */
    private static function parse_unescaped_sstring(string $sql, int $len, int $content_start, int $declared_len): ?array {
        $pos     = $content_start;
        $content = '';
        $count   = 0;

        while ($pos < $len && $count < $declared_len) {
            if ($sql[$pos] === '\\' && $pos + 1 < $len) {
                $content .= self::decode_sql_escape($sql[$pos + 1]);
                $pos     += 2;
            } else {
                $content .= $sql[$pos];
                $pos++;
            }
            $count++;
        }

        if ($count !== $declared_len) {
            return null;
        }
        if ($pos + 1 >= $len || $sql[$pos] !== '"' || $sql[$pos + 1] !== ';') {
            return null;
        }

        return [
            'content' => $content,
            'end_pos' => $pos + 2,
            'escaped' => false,
            'raw'     => substr($sql, $content_start, $pos - $content_start),
        ];
    }

    /**
     * Translate one byte following a backslash in a SQL string literal back to
     * the byte it represents. Mirrors escape_sql_value().
     */
    private static function decode_sql_escape(string $next): string {
        return match ($next) {
            '0'     => "\0",
            'n'     => "\n",
            'r'     => "\r",
            'Z'     => "\x1a",
            default => $next,
        };
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
     * Test seam: apply the URL rewrite to a SQL fragment.
     *
     * The replacement walker is the riskiest routine in the plugin — it rewrites
     * serialized payloads byte-by-byte and recomputes their length prefixes — so
     * it needs direct regression coverage. Exposing a thin, side-effect-free
     * wrapper is preferable to reaching in with Reflection, whose
     * setAccessible() is deprecated as of PHP 8.5.
     *
     * Not called by the plugin at runtime. See tests/test-sql-tokenizer.php.
     */
    public static function rewrite_for_tests(string $sql, string $old_url, string $new_url): string {
        $old_no_scheme = (string) preg_replace('#^https?://#', '', $old_url);
        $new_no_scheme = (string) preg_replace('#^https?://#', '', $new_url);

        return self::replace_urls_with_aliases($sql, $old_url, $new_url, $old_no_scheme, $new_no_scheme);
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
