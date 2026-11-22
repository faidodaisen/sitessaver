<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-GB backup archives, SQL dumps and chunked uploads with byte offsets, file locks and atomic renames inside the plugin's own backup folder; WP_Filesystem has no streaming, seek or lock API and may need FTP credentials that a background worker cannot supply.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never echoed raw; they are logged or returned through wp_send_json_* and escaped at render time in admin.js (escapeHtml).

/**
 * Import engine: restore a full site from a ZIP backup.
 */
final class Import {

    /** Transient key holding the live restore-phase readout. */
    private const PROGRESS_KEY = 'sitessaver_import_progress';

    /**
     * Ordered restore phases, in the order restore_slice() moves through
     * them. Shared with the admin UI (via Ajax::localize) so the step
     * checklist can never drift from what the backend reports: both sides
     * read this one array.
     *
     * @return array<string, string> phase id => human label
     */
    public static function phases(): array {
        return [
            'extract'  => __('Reading the backup...', 'sitessaver'),
            'manifest' => __('Validating backup...', 'sitessaver'),
            'database' => __('Restoring database...', 'sitessaver'),
            'files'    => __('Restoring files...', 'sitessaver'),
            'finalize' => __('Running final tasks...', 'sitessaver'),
        ];
    }

    /**
     * Report the restore's current phase for the status poll.
     *
     * The restore runs inside one synchronous AJAX request (it cannot be
     * safely backgrounded: the browser must hold the admin session that
     * authorized it) but transient writes are visible to a concurrent
     * request immediately, so a separate polling request can read this
     * while the restore is still running (same trick Export::tick() uses).
     * The transient serves the legacy in-request restore path; a background
     * restore job also gets the phase written to its job FILE, which (unlike
     * the options table) survives the database being swapped out mid-restore.
     */
    private static function tick(string $phase): void {
        $label = self::phases()[$phase] ?? $phase;

        set_transient(self::PROGRESS_KEY, [
            'phase' => $phase,
            'label' => $label,
            'time'  => time(),
        ], 10 * MINUTE_IN_SECONDS);

        Restore_Job::phase($phase, $label);
    }

    /**
     * Fetch the current restore-phase readout for the status poll.
     *
     * @return array{phase?: string, label?: string, time?: int}
     */
    public static function get_progress(): array {
        $progress = get_transient(self::PROGRESS_KEY);
        return is_array($progress) ? $progress : [];
    }

    /**
     * Work out the ordered restore set for a backup in storage.
     *
     * The ZIP's own manifest is the primary source: an incremental is
     * self-describing, so a restore still works after a reinstall, a
     * migration, or a database restore that wiped the chain option. The
     * local chain record is only a fallback for an archive written before
     * the manifest carried members.
     *
     * @return list<string> Filenames, oldest first. Single entry = plain full.
     */
    private static function chain_restore_set(string $zip_path, string $backup_file): array {
        $manifest = self::peek_manifest($zip_path);
        $type     = (string) ($manifest['backup_type'] ?? 'full');

        if ($type !== 'incremental') {
            return [$backup_file];
        }

        $members = $manifest['chain_members'] ?? null;

        if (!is_array($members) || $members === []) {
            $members = Index::restore_set($backup_file);
        }

        // An uploaded or downloaded chain member is stored under its own
        // name plus a random tail; the manifest names the original. Map
        // each original name to the file actually on disk (unchanged when
        // the member was made here and never renamed).
        $chain_id = (string) ($manifest['chain_id'] ?? '');
        $last     = count($members) - 1;

        $set = [];
        foreach (array_values($members) as $i => $member) {
            $name = sanitize_file_name((string) $member);
            if ($name === '') {
                continue;
            }
            // The last listed member is the archive being restored itself.
            $stored = $i === $last ? $backup_file : sitessaver_resolve_chain_member($name, $chain_id);
            $stored = $stored ?? $name;
            $set[]  = $stored;
            // Stop at the backup being restored: a later member is a NEWER
            // state the user did not ask for.
            if ($stored === $backup_file || $name === $backup_file) {
                break;
            }
        }


        return $set === [] ? [$backup_file] : $set;
    }

    /**
     * Read manifest.json out of a ZIP without extracting the archive.
     *
     * @return array<string, mixed>|null
     */
    private static function peek_manifest(string $zip_path): ?array {
        try {
            $zip = Archive::open($zip_path);
        } catch (\RuntimeException $e) {
            return null;
        }

        $raw = $zip->getFromName('manifest.json');
        $zip->close();

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    // ------------------------------------------------------------------
    // Resumable restore (background jobs, any host time limit)
    // ------------------------------------------------------------------

    /**
     * Run the restore in slices.
     *
     * A host that stops every request after ~30 s cannot restore a real site
     * in one request. Each call does as much as $should_stop allows and
     * leaves its place in $c (committed through $commit); the next call
     * continues. Stages: extract (list what every chain member holds and
     * take out database.sql and manifest.json, the two data files the
     * restore reads), then manifest, then database (staged, switched in
     * atomically at the end), then files (each file written straight from
     * the backup archive to its place, large files resumable), then
     * finalize. Site files are never unpacked into a staging folder.
     *
     * @param array<string, mixed> $c Cursor; [] to start.
     * @return bool True when the restore is complete.
     * @throws \RuntimeException On a failure. The caller rolls the database back.
     */
    public static function restore_slice(array &$c, string $backup_file, callable $should_stop, callable $commit): bool {
        $storage = sitessaver_storage_dir();
        $save    = static function (bool $force) use (&$c, $commit): void {
            $commit($c, $force);
        };

        if (empty($c['stage'])) {
            if (!Requirements::can_restore()) {
                throw new \RuntimeException('The PHP zip extension is missing on this server.');
            }
            $file = sanitize_file_name($backup_file);
            if (!file_exists($storage . '/' . $file)) {
                throw new \RuntimeException(__('Backup file not found.', 'sitessaver'));
            }
            $set = self::chain_restore_set($storage . '/' . $file, $file);
            $missing = array_values(array_filter($set, static fn($m) => !is_readable($storage . '/' . $m)));
            if ($missing !== []) {
                throw new \RuntimeException(sprintf(
                    /* translators: %s: comma-separated list of backup filenames. */
                    __('This incremental backup cannot be restored on its own. These earlier backups in its chain are missing: %s', 'sitessaver'),
                    implode(', ', $missing)
                ));
            }
            sitessaver_cleanup_temp();
            $c = [
                'stage' => 'extract',
                'set'   => array_values($set),
                'm'     => 0,
                'x'     => [],
                'temp'  => sitessaver_temp_dir() . '/import-' . wp_generate_password(20, false),
                'label' => basename((string) end($set)),
            ];
            wp_mkdir_p($c['temp']);
            // Before anything is extracted or changed: can this server write
            // (and keep) the files a restore writes? Security software that
            // blocks writes is otherwise only found mid-extract and reported
            // as a damaged backup.
            $blocked = Environment::probe_write((string) $c['temp']);
            if ($blocked !== '') {
                sitessaver_cleanup_temp((string) $c['temp']);
                throw new \RuntimeException($blocked);
            }
            $save(true);
        }

        $temp = (string) $c['temp'];

        // 1. Read the backup: list its files (the newest copy of each, for a
        //    chain) and take out database.sql and manifest.json.
        if ($c['stage'] === 'extract') {
            self::tick('extract');
            if (empty($c['plan'])) {
                $c['plan'] = self::write_plan((array) $c['set'], $storage, $temp . '.plan');
                $c['dbo']  = 0;
                $save(true);
            }
            $set  = (array) $c['set'];
            $plan = (array) $c['plan'];
            if ((int) $plan['manifest'] >= 0 && !is_file($temp . '/manifest.json')) {
                $zip = Archive::open($storage . '/' . (string) $set[(int) $plan['manifest']]);
                try {
                    $raw = $zip->getFromName('manifest.json');
                } finally {
                    $zip->close();
                }
                if (!is_string($raw) || file_put_contents($temp . '/manifest.json', $raw) === false) {
                    throw new \RuntimeException('Failed to extract backup archive: cannot read manifest.json');
                }
            }
            if ((int) $plan['db'] >= 0 && empty($c['dbx'])) {
                $zip = Archive::open($storage . '/' . (string) $set[(int) $plan['db']]);
                try {
                    $off  = (int) ($c['dbo'] ?? 0);
                    $done = Archive::extract_entry($zip, 'database.sql', (int) $plan['db_size'], $temp . '/database.sql', $off, $should_stop);
                    $c['dbo'] = $off;
                } finally {
                    $zip->close();
                }
                if (!$done) {
                    $save(true);
                    return false;
                }
                $c['dbx'] = true;
            }
            $c['stage'] = 'manifest';
            $save(true);
            if ($should_stop()) {
                return false;
            }
        }

        // 2. Manifest.
        if ($c['stage'] === 'manifest') {
            self::tick('manifest');
            $manifest = self::read_manifest($temp);
            if ($manifest === null) {
                throw new \RuntimeException(__('Invalid backup: manifest.json not found.', 'sitessaver'));
            }
            if (!self::is_sitessaver_manifest($manifest)) {
                throw new \RuntimeException(__('This ZIP is not a SitesSaver backup. Please upload a file created by the SitesSaver Export tool.', 'sitessaver'));
            }
            $c['manifest'] = [
                'home_url'       => (string) ($manifest['home_url'] ?? ''),
                'db_prefix'      => (string) ($manifest['db_prefix'] ?? ''),
                'active_plugins' => $manifest['active_plugins'] ?? null,
                'active_theme'   => $manifest['active_theme'] ?? null,
            ];
            $c['new_url'] = home_url();
            $c['stage']   = 'database';
            $save(true);
        }

        $old_url = (string) ($c['manifest']['home_url'] ?? '');
        $new_url = (string) ($c['new_url'] ?? home_url());

        // 3. Database (staged; switched in when complete).
        if ($c['stage'] === 'database') {
            $db_file = $temp . '/database.sql';
            if (file_exists($db_file)) {
                self::tick('database');
                $old_prefix = (string) ($c['manifest']['db_prefix'] ?? '');
                if ($old_prefix === '') {
                    $old_prefix = Database::detect_dump_prefix($db_file);
                }
                // Before the first statement: can the database user do what a
                // restore needs? Missing RENAME/ALTER/DROP otherwise shows up
                // midway, as a failed swap or silently skipped statements.
                if (empty($c['db']) && empty($c['db_probe'])) {
                    $denied = Database::probe_privileges();
                    if ($denied !== '') {
                        throw new \RuntimeException($denied);
                    }
                    $c['db_probe'] = true;
                }
                $opt = ['old_url' => $old_url, 'new_url' => $new_url, 'old_prefix' => $old_prefix, 'stage' => empty($c['db_direct'])];
                $db  = (array) ($c['db'] ?? []);
                try {
                    $db = Database::import_resumable($db_file, $db, $opt, $should_stop, static function (array $st, bool $force) use (&$c, $save): void {
                        $c['db'] = $st;
                        $save($force);
                    });
                } catch (\RuntimeException $e) {
                    if ($e->getCode() !== Database::STAGING_UNSUPPORTED) {
                        $c['db'] = Database::failed_state() ?: ($c['db'] ?? []);
                        throw $e;
                    }
                    Database::discard_staging(Database::failed_state());
                    Log::warning('restore_staging_skipped', $e->getMessage() . ' Restoring directly into the live tables instead.');
                    $c['db']        = [];
                    $c['db_direct'] = true;
                    $save(true);
                    return false;
                }
                $c['db'] = $db;
                if (!empty($db['error'])) {
                    throw new \RuntimeException(__('Failed to import database.', 'sitessaver'));
                }
                if (empty($db['done'])) {
                    return false;
                }
                // The options table is now the backup's. Listeners (the
                // background restore job) re-assert anything the rest of
                // this restore depends on.
                do_action('sitessaver_database_restored');
            }
            $c['stage'] = 'files';
            $save(true);
            if ($should_stop()) {
                return false;
            }
        }

        // 4. wp-content files.
        if ($c['stage'] === 'files') {
            if ((int) ($c['plan']['files'] ?? 0) > 0) {
                self::tick('files');
                if (!self::restore_files_slice($c, $storage, $old_url, $new_url, $should_stop, $save)) {
                    return false;
                }
            }
            self::flush_builder_caches();
            self::ensure_uploads_mime_htaccess();
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            // Last point where a failure still rolls the files back.
            do_action('sitessaver_restore_files_written', (string) ($c['label'] ?? ''));
            $c['stage'] = 'finalize';
            $save(true);
        }

        // The restore can no longer fail in a way that needs the replaced
        // files: drop their archive, a slice at a time.
        if (!empty($c['fl']['rb']) && is_dir((string) $c['fl']['rb'])) {
            if (!self::remove_tree_slice((string) $c['fl']['rb'], $should_stop)) {
                $save(true);
                return false;
            }
        }
        if (!empty($c['fl']['new']) && is_file((string) $c['fl']['new'])) {
            unlink((string) $c['fl']['new']);
        }

        // 5. Post-import tasks + cleanup.
        self::tick('finalize');
        self::post_import((array) ($c['manifest'] ?? []));
        sitessaver_cleanup_temp($temp);
        foreach ([$temp . '.plan', $temp . '.files'] as $list) {
            if (is_file($list)) {
                unlink($list);
            }
        }
        delete_transient(self::PROGRESS_KEY);
        do_action('sitessaver_import_complete', (string) ($c['label'] ?? ''));

        $c['stage'] = 'done';
        return true;
    }

    /** Most originals kept per rollback archive part, by count and by size. */
    private const ROLLBACK_FILES = 400;
    private const ROLLBACK_BYTES = 128 * 1024 * 1024;
    /** Most plan lines looked at for one part. */
    private const BATCH_LINES = 2000;

    /**
     * List what the backup holds, one line per entry under wp-content:
     * "member TAB size TAB name". For a chain, the newest copy of every
     * path wins and each member's deletions are applied in order, so the
     * list is exactly what a full backup of that moment would contain.
     *
     * @param list<string> $set Filenames, oldest first.
     * @return array{db: int, db_size: int, manifest: int, wpe: bool, files: int}
     */
    private static function write_plan(array $set, string $storage, string $plan_file): array {
        $info   = ['db' => -1, 'db_size' => 0, 'manifest' => -1, 'wpe' => false, 'files' => 0];
        $single = count($set) === 1;
        $map    = [];
        $out    = fopen($plan_file, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Server refused the restore: cannot write the restore file list (' . Environment::WRITE_BLOCKED . ')');
        }

        $emit = static function (int $m, int $size, string $name) use ($out, &$info): void {
            if (str_starts_with($name, 'wp-content/mu-plugins/wpengine-common/')) {
                $info['wpe'] = true;
            }
            fwrite($out, $m . "\t" . $size . "\t" . $name . "\n");
            $info['files']++;
        };

        try {
            foreach (array_values($set) as $m => $member) {
                $zip = Archive::open($storage . '/' . $member);
                try {
                    Archive::each_entry($zip, static function (string $name, int $size) use ($m, $single, &$map, &$info, $emit): void {
                        if ($name === 'database.sql') {
                            $info['db']      = $m;
                            $info['db_size'] = $size;
                            return;
                        }
                        if ($name === 'manifest.json') {
                            $info['manifest'] = $m;
                            return;
                        }
                        if (!str_starts_with($name, 'wp-content/') || str_contains($name, "\n") || str_contains($name, "\t") || str_contains($name, "\r")) {
                            return;
                        }
                        if ($single) {
                            $emit($m, $size, $name);
                        } else {
                            $map[$name] = [$m, $size];
                        }
                    });
                    if (!$single) {
                        $raw      = $zip->getFromName('manifest.json');
                        $manifest = is_string($raw) ? json_decode($raw, true) : null;
                        if (is_array($manifest)) {
                            self::drop_deleted($map, $manifest['deleted'] ?? []);
                        }
                    }
                } finally {
                    $zip->close();
                }
            }
            foreach ($map as $name => [$m, $size]) {
                $emit((int) $m, (int) $size, (string) $name);
            }
        } finally {
            fclose($out);
        }

        return $info;
    }

    /**
     * Remove paths a chain member deleted (files, or whole folders) from
     * the list built so far. Paths come from a file the user supplied, so
     * unsafe ones are ignored.
     *
     * @param array<string, array{0: int, 1: int}> $map
     * @param mixed                                $paths
     */
    private static function drop_deleted(array &$map, $paths): void {
        if (!is_array($paths) || $paths === []) {
            return;
        }
        $gone = [];
        foreach ($paths as $p) {
            if (is_string($p) && $p !== '' && !Archive::is_unsafe_relative_path($p)) {
                $gone[rtrim(str_replace('\\', '/', $p), '/')] = true;
            }
        }
        if ($gone === []) {
            return;
        }
        foreach (array_keys($map) as $name) {
            $p = rtrim((string) $name, '/');
            while ($p !== '' && $p !== '.') {
                if (isset($gone[$p])) {
                    unset($map[$name]);
                    break;
                }
                $up = dirname($p);
                if ($up === $p) {
                    break;
                }
                $p = $up;
            }
        }
    }

    /**
     * Where one plan line goes, or null when it is not restored (unknown
     * area, a skipped folder).
     *
     * @param array<string, array{dest: string, skip: list<string>, rewrite: bool}> $dirs
     * @return array{m: int, size: int, name: string, key: string, rel: string, dest: string, dir: bool, rewrite: bool}|null
     */
    private static function plan_target(string $line, array $dirs): ?array {
        $parts = explode("\t", rtrim($line, "\n"), 3);
        if (count($parts) !== 3) {
            return null;
        }
        [$m, $size, $name] = $parts;
        $inner = substr($name, strlen('wp-content/'));
        $area  = (string) strtok($inner, '/');
        if (!isset($dirs[$area])) {
            return null;
        }
        $rel = rtrim(substr($inner, strlen($area) + 1), '/');
        if ($rel === '' || (!empty($dirs[$area]['skip']) && in_array(strtok($rel, '/'), $dirs[$area]['skip'], true))) {
            return null;
        }
        return [
            'm'       => (int) $m,
            'size'    => (int) $size,
            'name'    => $name,
            'key'     => $area . '/' . $rel,
            'rel'     => $rel,
            'dest'    => $dirs[$area]['dest'] . '/' . $rel,
            'dir'     => str_ends_with($name, '/'),
            'rewrite' => $dirs[$area]['rewrite'],
        ];
    }

    /**
     * The files step. Works through the plan in parts: for each part, the
     * site files it is about to replace are first stored in a rollback ZIP
     * (written with Zip_Writer, so it too can stop and continue), then each
     * file of the part is written straight from the backup archive to its
     * place. Files that did not exist yet are listed in `new`. A restore
     * that fails later deletes those and puts the originals back from the
     * rollback ZIPs (rollback_files()). No copy of a site file is ever
     * left loose on disk.
     *
     * @param array<string, mixed> $c
     */
    private static function restore_files_slice(array &$c, string $storage, string $old_url, string $new_url, callable $should_stop, callable $save): bool {
        [$dirs, $pairs] = self::content_targets($old_url, $new_url, !empty($c['plan']['wpe']));
        $plan_file      = (string) $c['temp'] . '.plan';

        if (empty($c['fl'])) {
            self::log_host_skipped($plan_file, $dirs);
            $c['fl'] = [
                'lp'       => 0,
                'failures' => 0,
                'failed'   => [],
                'rb'       => (string) $c['temp'] . '/rollback',
                'new'      => (string) $c['temp'] . '.new',
                'part'     => 0,
                'batch'    => null,
            ];
            wp_mkdir_p($c['fl']['rb'] . '/empty');
            $save(true);
        }

        $fh = fopen($plan_file, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Could not read the restore file list.');
        }
        $zips = [];

        try {
            while (true) {
                if (!is_array($c['fl']['batch'] ?? null)) {
                    $batch = self::plan_batch($fh, $c, $dirs);
                    if ($batch === null) {
                        break;
                    }
                    $c['fl']['batch'] = $batch;
                    $save(true);
                }

                // Keep the originals of this part before any of them changes.
                if ((int) $c['fl']['batch']['keep'] > 0 && empty($c['fl']['batch']['z']['done'])) {
                    $rb    = (string) $c['fl']['rb'];
                    $state = Zip_Writer::build(
                        $rb . '/empty',
                        $rb . '/rollback-' . (int) $c['fl']['part'] . '.zip',
                        [],
                        $rb . '/work',
                        (array) $c['fl']['batch']['z'],
                        $should_stop,
                        static function (array $st, bool $force) use (&$c, $save): void {
                            $c['fl']['batch']['z'] = $st;
                            $save($force);
                        },
                        null,
                        [$rb . '/part.txt']
                    );
                    if (empty($state['done'])) {
                        $c['fl']['batch']['z'] = $state;
                        $save(true);
                        return false;
                    }
                    $c['fl']['batch']['z'] = ['done' => true];
                    $save(true);
                }

                fseek($fh, (int) $c['fl']['batch']['wlp']);
                while ((int) $c['fl']['batch']['wlp'] < (int) $c['fl']['batch']['to'] && ($line = fgets($fh)) !== false) {
                    if (!self::restore_line($line, $dirs, $pairs, $c, $storage, $zips, $should_stop)) {
                        $save(true);
                        return false;
                    }
                    $c['fl']['batch']['wlp'] = (int) ftell($fh);
                    $c['fl']['batch']['off'] = 0;
                    do_action('sitessaver_heartbeat');
                    $save(false);
                    if ($should_stop()) {
                        $save(true);
                        return false;
                    }
                }

                $c['fl']['lp']    = (int) $c['fl']['batch']['to'];
                $c['fl']['part']  = (int) $c['fl']['part'] + 1;
                $c['fl']['batch'] = null;
                $save(true);
                if ($should_stop()) {
                    return false;
                }
            }
        } finally {
            fclose($fh);
            foreach ($zips as $zip) {
                $zip->close();
            }
        }

        if ((int) $c['fl']['failures'] > 0) {
            Log::warning(
                'restore_files_skipped',
                sprintf('%d file(s) could not be copied while restoring.', (int) $c['fl']['failures']),
                ['first_paths' => $c['fl']['failed']]
            );
        }
        return true;
    }

    /**
     * Pick the next part of the plan: list the existing files it replaces
     * (for the rollback ZIP) and note the files it creates.
     *
     * @param resource             $fh
     * @param array<string, mixed> $c
     * @param array<string, array{dest: string, skip: list<string>, rewrite: bool}> $dirs
     * @return array<string, mixed>|null Null when the plan is done.
     */
    private static function plan_batch($fh, array $c, array $dirs): ?array {
        $from = (int) $c['fl']['lp'];
        fseek($fh, $from);
        $keep_list = fopen((string) $c['fl']['rb'] . '/part.txt', 'wb');
        $new_list  = fopen((string) $c['fl']['new'], 'ab');
        if ($keep_list === false || $new_list === false) {
            throw new \RuntimeException('Server refused the restore: cannot write the rollback list (' . Environment::WRITE_BLOCKED . ')');
        }

        $lines = 0;
        $keep  = 0;
        $bytes = 0;
        try {
            while (($line = fgets($fh)) !== false) {
                $lines++;
                $t = self::plan_target($line, $dirs);
                if ($t !== null && !$t['dir']) {
                    if (is_file($t['dest'])) {
                        fwrite($keep_list, $t['key'] . "\0" . $t['dest'] . "\n");
                        $keep++;
                        $bytes += (int) @filesize($t['dest']);
                    } elseif (!file_exists($t['dest'])) {
                        fwrite($new_list, $t['dest'] . "\n");
                    }
                }
                if ($keep >= self::ROLLBACK_FILES || $bytes >= self::ROLLBACK_BYTES || $lines >= self::BATCH_LINES) {
                    break;
                }
            }
            $to = (int) ftell($fh);
        } finally {
            fclose($keep_list);
            fclose($new_list);
        }

        if ($lines === 0) {
            return null;
        }
        return ['to' => $to, 'wlp' => $from, 'keep' => $keep, 'z' => [], 'off' => 0];
    }

    /**
     * Write one plan line to the site. Returns false when stopped part way
     * through a large file (the place is kept in the cursor).
     *
     * @param array<string, array{dest: string, skip: list<string>, rewrite: bool}> $dirs
     * @param array<string, string>     $pairs
     * @param array<string, mixed>      $c
     * @param array<int, \ZipArchive>   $zips Open backup archives, by member.
     */
    private static function restore_line(string $line, array $dirs, array $pairs, array &$c, string $storage, array &$zips, callable $should_stop): bool {
        $t = self::plan_target($line, $dirs);
        if ($t === null) {
            return true;
        }
        if ($t['dir']) {
            if (!is_dir($t['dest'])) {
                wp_mkdir_p($t['dest']);
            }
            return true;
        }

        $parent = dirname($t['dest']);
        if (!is_dir($parent)) {
            wp_mkdir_p($parent);
        }
        if (!isset($zips[$t['m']])) {
            $zips[$t['m']] = Archive::open($storage . '/' . (string) $c['set'][$t['m']]);
        }
        $zip = $zips[$t['m']];

        try {
            if ($t['rewrite'] && $pairs !== [] && self::is_rewritable_text_file($t['rel'], $t['size'])) {
                $data = $zip->getFromName($t['name']);
                if (!is_string($data)) {
                    throw new \RuntimeException('Failed to extract backup archive: cannot read ' . $t['name']);
                }
                if (!self::write_with_url_rewrite($data, $t['dest'], $pairs)) {
                    throw new \RuntimeException('Server refused the restore: could not write ' . $t['name']);
                }
            } else {
                $off  = (int) ($c['fl']['batch']['off'] ?? 0);
                $done = Archive::extract_entry($zip, $t['name'], $t['size'], $t['dest'], $off, $should_stop);
                $c['fl']['batch']['off'] = $off;
                if (!$done) {
                    return false;
                }
            }
        } catch (\RuntimeException $e) {
            // A damaged archive fails the restore (and rolls it back). A
            // single file the server would not let us write is skipped, as
            // before, and the site keeps its own copy.
            if (!str_starts_with($e->getMessage(), 'Server refused the restore')) {
                throw $e;
            }
            $c['fl']['failures'] = (int) $c['fl']['failures'] + 1;
            if (count((array) $c['fl']['failed']) < 10) {
                $c['fl']['failed'][] = $t['key'];
            }
            Log::warning('restore_copy_failed', 'A file could not be restored.', ['file' => (string) $t['key']]);
            self::put_back_original($c, $t);
        }
        return true;
    }

    /**
     * After a failed write, put the site's own copy of that file back from
     * the current rollback part, or remove the partial file when the file
     * is new.
     *
     * @param array<string, mixed> $c
     * @param array<string, mixed> $t
     */
    private static function put_back_original(array $c, array $t): void {
        $part = (string) $c['fl']['rb'] . '/rollback-' . (int) $c['fl']['part'] . '.zip';
        if (is_file($part) && class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($part) === true) {
                $stat = $zip->statName((string) $t['key']);
                if (is_array($stat)) {
                    try {
                        $off = 0;
                        Archive::extract_entry($zip, (string) $t['key'], (int) $stat['size'], (string) $t['dest'], $off, static fn(): bool => false);
                    } catch (\RuntimeException $e) {
                        Log::warning('restore_put_back_failed', 'A replaced file could not be put back.', ['file' => (string) $t['key']]);
                    }
                    $zip->close();
                    return;
                }
                $zip->close();
            }
        }
        if (is_file((string) $t['dest'])) {
            @unlink((string) $t['dest']);
        }
    }

    /**
     * Must-use plugins in the backup that are not restored (they belong to
     * the old host). Logged once, when the files step starts.
     *
     * @param array<string, array{dest: string, skip: list<string>, rewrite: bool}> $dirs
     */
    private static function log_host_skipped(string $plan_file, array $dirs): void {
        $skip = (array) ($dirs['mu-plugins']['skip'] ?? []);
        if ($skip === []) {
            return;
        }
        $found = [];
        $fh    = @fopen($plan_file, 'rb');
        if ($fh === false) {
            return;
        }
        while (($line = fgets($fh)) !== false) {
            $parts = explode("\t", rtrim($line, "\n"), 3);
            if (count($parts) === 3 && str_starts_with($parts[2], 'wp-content/mu-plugins/')) {
                $first = (string) strtok(substr($parts[2], strlen('wp-content/mu-plugins/')), '/');
                if ($first !== '' && in_array($first, $skip, true)) {
                    $found[$first] = true;
                }
            }
        }
        fclose($fh);
        if ($found !== []) {
            Log::warning('restore_host_mu_plugins_skipped', 'Not restored, because they belong to the old hosting company and would break this site: mu-plugins/' . implode(', mu-plugins/', array_keys($found)) . '. Filter sitessaver_host_mu_plugins to change this.');
        }
    }

    /**
     * Undo the files step: delete files the restore created, then write
     * every original back from the rollback ZIPs. Resumable and idempotent:
     * a part is deleted once all of its files are back, and a part that is
     * replayed writes the same bytes again.
     *
     * @param array<string, mixed> $c
     * @return bool True when done.
     */
    public static function rollback_files(array $c, callable $should_stop): bool {
        $fl = is_array($c['fl'] ?? null) ? $c['fl'] : [];
        $rb = (string) ($fl['rb'] ?? '');
        if ($rb === '') {
            return true;
        }

        $new = (string) ($fl['new'] ?? '');
        if ($new !== '' && is_file($new)) {
            $fh = fopen($new, 'rb');
            if ($fh !== false) {
                while (($line = fgets($fh)) !== false) {
                    $path = rtrim($line, "\r\n");
                    if ($path !== '' && is_file($path)) {
                        @unlink($path);
                    }
                }
                fclose($fh);
            }
            unlink($new);
            if ($should_stop()) {
                return false;
            }
        }

        if (!is_dir($rb)) {
            return true;
        }

        [$dirs] = self::content_targets('', '');
        $parts  = glob($rb . '/rollback-*.zip');
        foreach (is_array($parts) ? $parts : [] as $part) {
            try {
                $zip = Archive::open($part);
            } catch (\RuntimeException $e) {
                Log::error('restore_rollback_unreadable', $e->getMessage());
                continue;
            }
            try {
                Archive::each_entry($zip, static function (string $name, int $size) use ($zip, $dirs): void {
                    $area = (string) strtok($name, '/');
                    $rel  = substr($name, strlen($area) + 1);
                    if (!isset($dirs[$area]) || $rel === '' || str_ends_with($name, '/')) {
                        return;
                    }
                    $dest = $dirs[$area]['dest'] . '/' . $rel;
                    if (!is_dir(dirname($dest))) {
                        wp_mkdir_p(dirname($dest));
                    }
                    $off = 0;
                    try {
                        Archive::extract_entry($zip, $name, $size, $dest, $off, static fn(): bool => false);
                    } catch (\RuntimeException $e) {
                        Log::warning('rollback_put_back_failed', 'A file could not be put back: ' . $e->getMessage(), ['file' => $name]);
                    }
                });
            } catch (\RuntimeException $e) {
                Log::error('restore_rollback_unreadable', $e->getMessage());
            } finally {
                $zip->close();
            }
            @unlink($part);
            do_action('sitessaver_heartbeat');
            if ($should_stop()) {
                return false;
            }
        }

        return self::remove_tree_slice($rb, $should_stop);
    }

    /** Delete a directory tree, stopping between files when asked. */
    public static function remove_tree_slice(string $dir, callable $should_stop): bool {
        if (!is_dir($dir)) {
            return true;
        }
        $n  = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            if ((++$n % 300) === 0) {
                do_action('sitessaver_heartbeat');
                if ($should_stop()) {
                    return false;
                }
            }
        }
        @rmdir($dir);
        return true;
    }

    /**
     * Where each wp-content folder of a backup is restored to, and the URL
     * pairs used to rewrite text files. Shared by both restore paths.
     *
     * @return array{0: array<string, array{dest: string, skip: list<string>, rewrite: bool}>, 1: array<string, string>}
     */
    private static function content_targets(string $old_url, string $new_url, bool $backup_has_wpe = false): array {
        // The running SitesSaver must never be overwritten by an older copy
        // inside the backup (pre-1.1.7 backups contain one).
        $running_plugin_dir = sitessaver_own_plugin_folder();
        $uploads_skip = (is_multisite() && get_current_blog_id() === 1) ? ['sites'] : [];
        $areas = sitessaver_site_areas();
        $dirs  = [
            'uploads'    => ['dest' => $areas['uploads'],    'skip' => $uploads_skip, 'rewrite' => false],
            'plugins'    => ['dest' => $areas['plugins'],    'skip' => [$running_plugin_dir], 'rewrite' => true],
            'themes'     => ['dest' => $areas['themes'],     'skip' => [], 'rewrite' => true],
            'mu-plugins' => ['dest' => $areas['mu-plugins'], 'skip' => self::host_mu_plugin_skip($backup_has_wpe), 'rewrite' => true],
        ];
        $pairs = [];
        if ($old_url !== '' && $new_url !== '' && $old_url !== $new_url) {
            $pairs = Database::build_replacement_pairs(
                $old_url,
                $new_url,
                (string) preg_replace('#^https?://#', '', $old_url),
                (string) preg_replace('#^https?://#', '', $new_url)
            );
        }
        return [$dirs, $pairs];
    }

    /**
     * Must-use plugins that belong to a hosting company, not to the site.
     *
     * Hosts drop these into wp-content/mu-plugins (WP Engine, Kinsta,
     * GoDaddy, Bluehost/Endurance, Pantheon, WordPress.com). Copied to a
     * different host they call APIs, constants or files that do not exist
     * there, and the restored site dies with a fatal error on the very
     * first request. Restoring onto the same host is unaffected: an entry
     * the target already has is restored as usual.
     *
     * @return list<string> First path segments under mu-plugins to skip.
     */
    public static function host_mu_plugin_skip(?bool $backup_has_wpe = null): array {
        $known = (array) apply_filters('sitessaver_host_mu_plugins', [
            // WP Engine
            'mu-plugin.php', 'wpengine-common', 'wpe-wp-sign-on-plugin', 'wpe-wp-sign-on-plugin.php',
            'wpengine-security-auditor.php', 'wpe-elasticpress-autosuggest-logger.php', 'wpe-cache-plugin', 'wpe-cache-plugin.php',
            'force-strong-passwords', 'slt-force-strong-passwords.php', 'stop-long-comments.php',
            // Kinsta
            'kinsta-mu-plugins', 'kinsta-mu-plugins.php',
            // GoDaddy Managed WordPress
            'gd-system-plugin', 'gd-system-plugin.php',
            // Bluehost / HostGator (Endurance)
            'endurance-page-cache.php', 'endurance-browser-cache.php', 'endurance-php-edge.php',
            // Pantheon
            'pantheon', 'pantheon.php', 'pantheon-mu-plugin',
            // WordPress.com / Atomic
            'wpcomsh', 'wpcomsh-loader.php',
        ]);
        $skip = [];
        foreach ($known as $entry) {
            $entry = (string) $entry;
            if ($entry !== '' && !file_exists(WPMU_PLUGIN_DIR . '/' . $entry)) {
                $skip[] = $entry;
            }
        }
        // "mu-plugin.php" is a generic name. It is WP Engine's loader only
        // when the backup also carries wpengine-common (which it requires).
        if ($backup_has_wpe === false) {
            $skip = array_values(array_diff($skip, ['mu-plugin.php']));
        }
        return $skip;
    }

    /**
     * Read the manifest.json from extracted backup.
     */
    private static function read_manifest(string $dir): ?array {
        $file = $dir . '/manifest.json';

        if (!file_exists($file)) {
            return null;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Verify a decoded manifest was produced by SitesSaver Export.
     * Guards against random ZIPs whose root happens to contain a manifest.json.
     */
    private static function is_sitessaver_manifest(array $manifest): bool {
        return ($manifest['plugin'] ?? '') === 'SitesSaver'
            && !empty($manifest['version'])
            && !empty($manifest['site_url']);
    }

    /**
     * Ask the cache and page builder plugins on this site to clear their
     * caches, through each plugin's own public function or hook. SitesSaver
     * never deletes another plugin's folders. A plugin that is not active is
     * skipped, and a purge that fails is logged and ignored.
     *
     * @return list<string> Names of the caches that were asked to clear.
     */
    public static function flush_builder_caches(): array {
        $purges = [
            'LiteSpeed Cache' => static function (): bool {
                if (!has_action('litespeed_purge_all')) {
                    return false;
                }
                do_action('litespeed_purge_all'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own purge hook.
                return true;
            },
            'WP Rocket' => static function (): bool {
                if (!function_exists('rocket_clean_domain')) {
                    return false;
                }
                rocket_clean_domain();
                if (function_exists('rocket_clean_minify')) {
                    rocket_clean_minify();
                }
                return true;
            },
            'W3 Total Cache' => static function (): bool {
                if (!function_exists('w3tc_flush_all')) {
                    return false;
                }
                w3tc_flush_all();
                return true;
            },
            'WP Super Cache' => static function (): bool {
                if (!function_exists('wp_cache_clear_cache')) {
                    return false;
                }
                wp_cache_clear_cache();
                return true;
            },
            'WP Fastest Cache' => static function (): bool {
                if (has_action('wpfc_clear_all_cache')) {
                    do_action('wpfc_clear_all_cache', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Fastest Cache's own purge hook.
                    return true;
                }
                if (isset($GLOBALS['wp_fastest_cache']) && is_object($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'deleteCache')) {
                    $GLOBALS['wp_fastest_cache']->deleteCache(true);
                    return true;
                }
                return false;
            },
            'Autoptimize' => static function (): bool {
                if (!class_exists('autoptimizeCache') || !method_exists('autoptimizeCache', 'clearall')) {
                    return false;
                }
                \autoptimizeCache::clearall();
                return true;
            },
            'SiteGround Optimizer' => static function (): bool {
                if (!function_exists('sg_cachepress_purge_cache')) {
                    return false;
                }
                sg_cachepress_purge_cache();
                return true;
            },
            'Elementor' => static function (): bool {
                if (!class_exists('\\Elementor\\Plugin') || !isset(\Elementor\Plugin::$instance->files_manager)
                    || !is_object(\Elementor\Plugin::$instance->files_manager)
                    || !method_exists(\Elementor\Plugin::$instance->files_manager, 'clear_cache')) {
                    return false;
                }
                \Elementor\Plugin::$instance->files_manager->clear_cache();
                return true;
            },
        ];

        $done = [];
        foreach ($purges as $name => $purge) {
            try {
                if ($purge()) {
                    $done[] = $name;
                }
            } catch (\Throwable $e) {
                Log::warning('cache_purge_failed', $name . ': ' . $e->getMessage());
            }
        }

        // The object cache last, so nothing above refills it with old values.
        wp_cache_flush();

        if ($done !== []) {
            Log::info('cache_purged', 'Asked to clear caches after restore: ' . implode(', ', $done) . '.');
        }

        return $done;
    }

    /**
     * Write a minimal .htaccess to wp-content/uploads/ that registers modern
     * image MIME types (webp/avif/svg). Idempotent: if a SitesSaver block
     * is already present we leave the file alone; if the file doesn't exist
     * or doesn't contain our marker we append our block.
     *
     * Harmless on Nginx (Nginx ignores .htaccess). On Apache shared hosts
     * without `AllowOverride FileInfo` this is also harmless: directives
     * are ignored but nothing breaks.
     */
    private static function ensure_uploads_mime_htaccess(): void {
        $upload_dir = wp_upload_dir();
        if (empty($upload_dir['basedir'])) {
            return;
        }

        $htaccess_path = $upload_dir['basedir'] . '/.htaccess';
        $marker        = '# BEGIN SitesSaver-MIME';
        $block         = $marker . "\n"
            . "<IfModule mod_mime.c>\n"
            . "    AddType image/webp .webp\n"
            . "    AddType image/avif .avif\n"
            . "    AddType image/svg+xml .svg .svgz\n"
            . "</IfModule>\n"
            . "# END SitesSaver-MIME\n";

        $existing = file_exists($htaccess_path) ? (string) @file_get_contents($htaccess_path) : '';
        if (str_contains($existing, $marker)) {
            return;
        }

        $new_contents = $existing === '' ? $block : rtrim($existing) . "\n\n" . $block;
        @file_put_contents($htaccess_path, $new_contents);
    }

    /**
     * Decide whether a file is a candidate for URL rewriting.
     *
     * We only touch source-text files inside plugins/themes. Skip:
     *   - binary artefacts (images, fonts, archives, compiled bytecode)
     *   - vendor/node_modules/lock bundles (rewriting here is high-risk and
     *     unnecessary; vendor code never hardcodes the site URL)
     *   - files larger than 5 MB (minified bundles; too costly, low signal)
     *
     * The hardcoded-URL problem this guards against is limited to custom
     * user plugins/themes where the author typed the site URL into a PHP
     * array or a small JSON config. Those files are always <1 MB.
     */
    private static function is_rewritable_text_file(string $relative, int $size): bool {
        $rel_lower = strtolower(str_replace('\\', '/', $relative));

        // Skip package-manager / build-artefact trees. These never contain
        // site-specific URLs and a mis-rewrite here breaks dependencies.
        foreach (['/vendor/', '/node_modules/', '/.git/', '/composer.lock', '/package-lock.json', '/yarn.lock'] as $needle) {
            if (str_contains('/' . $rel_lower, $needle)) {
                return false;
            }
        }

        // Only rewrite text formats we know how to edit safely.
        static $allowed = [
            'php', 'phtml', 'inc',
            'html', 'htm',
            'css', 'scss', 'sass', 'less',
            'js', 'mjs', 'cjs',
            'json',
            'xml', 'svg',
            'txt', 'md', 'yml', 'yaml',
            'env', 'ini', 'conf',
            'po', 'pot',
        ];
        $ext = strtolower((string) pathinfo($rel_lower, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            return false;
        }

        // Hard size ceiling. Minified third-party bundles can be megabytes;
        // user-authored hardcoded URLs live in small config/data files.
        if ($size > 5 * 1024 * 1024) {
            return false;
        }

        return true;
    }

    /**
     * Rewrite URLs in a text file from the backup and write it. strtr()
     * with the same pair map the DB importer uses, so both layers produce
     * consistent output. Contents without the old URL are written as they
     * are (the common case).
     *
     * @param array<string, string> $url_pairs
     */
    private static function write_with_url_rewrite(string $contents, string $dest, array $url_pairs): bool {
        foreach ($url_pairs as $from => $_) {
            if ($from !== '' && str_contains($contents, (string) $from)) {
                $contents = strtr($contents, $url_pairs);
                break;
            }
        }
        return @file_put_contents($dest, $contents) !== false;
    }

    /**
     * Post-import cleanup.
     *
     * Why this is split in two:
     *   During restore we're mid-AJAX with the OLD plugin/theme set loaded in
     *   memory and `init` already fired. Calling switch_theme() /
     *   update_option('active_plugins') / flush_rewrite_rules() here forces
     *   the NEW plugin set to boot inside the same request, which triggers
     *   "textdomain loaded too early" notices (Elementor, Landinghub, etc.)
     *   and can leave rewrite rules half-stale.
     *
     *   Mirroring the All-in-One WP Migration UX: we just queue a flag here,
     *   the UI forces the user to log out + log back in, and the deferred
     *   work runs on the first clean admin request via `admin_init`. The
     *   user is then parked on Settings > Permalinks and asked to save twice
     *   to flush rewrite rules.
     */
    private static function post_import(array $manifest): void {
        global $wpdb;

        // Transients: raw SQL, no hook side effects.
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'");

        // Generate a one-time finalize token. Why: after the database restore,
        // wp_users / auth salts / capabilities come from the BACKUP, but the
        // browser's cookie was issued against the PRE-restore DB. That means
        // the normal nonce + `current_user_can('manage_options')` checks in
        // sitessaver_verify_ajax() can silently fail on the very next AJAX
        // call (finalize_restore): the user is effectively a stranger to the
        // new DB. Instead we hand the client a short-lived random token,
        // persist it alongside the pending state, and accept it in place of
        // the standard nonce for finalize_restore only. Scope is tight: the
        // option is deleted immediately after consumption.
        $finalize_token = wp_generate_password(32, false, false);

        // Queue deferred finalisation. Autoload = no so it doesn't ride on
        // every admin page until it's consumed.
        update_option(
            'sitessaver_pending_finalize',
            [
                'active_plugins' => $manifest['active_plugins'] ?? null,
                'active_theme'   => $manifest['active_theme']   ?? null,
                'queued_at'      => time(),
                'token'          => $finalize_token,
            ],
            false
        );

        // Stash the token in a transient that the JS can read via the
        // localized SitesSaver object on the NEXT admin page load. But the
        // user won't reload. They're looking at the restore-complete modal
        // right now. So we also pass it back through the current AJAX response
        // chain (see Ajax::handle_import return shape).
        set_transient('sitessaver_finalize_token', $finalize_token, HOUR_IN_SECONDS);
    }

    /**
     * Expose the active finalize token so AJAX handlers can return it to the
     * client as part of the import-success payload.
     */
    public static function current_finalize_token(): string {
        $pending = get_option('sitessaver_pending_finalize', null);
        if (is_array($pending) && !empty($pending['token'])) {
            return (string) $pending['token'];
        }
        $fallback = get_transient('sitessaver_finalize_token');
        return is_string($fallback) ? $fallback : '';
    }

    /**
     * Build the finalize redirect target the client should navigate to after
     * a successful restore. The URL points at wp-login.php with a redirect_to
     * that lands on options-permalink.php carrying the one-time token.
     *
     * Why this shape: the browser's existing auth cookie is signed by the
     * PRE-restore DB. It will not validate against the restored wp_users +
     * auth salts, so wp-login.php shows the login prompt. When the user
     * re-auths with the backup's credentials, WP redirects to the
     * redirect_to target, where Admin::handle_post_import_finalisation
     * runs the deferred work under a clean, freshly-authenticated session.
     *
     * Two details below are load-bearing; both were reproduced against a live
     * WP 7.1 install, and getting either wrong dumps the user on the dashboard
     * instead of Settings > Permalinks:
     *
     * 1. `reauth=1`. The premise that a pre-restore cookie "won't validate"
     *    only holds when the backup carries different auth salts. Restoring a
     *    backup of the SAME site keeps the salts, so the old cookie is still
     *    perfectly valid and the user is never actually logged out. We cannot
     *    clear it from JS either: WP sets the auth cookies HttpOnly
     *    (pluggable.php, `setcookie(LOGGED_IN_COOKIE, ..., true)`), so the
     *    client-side cookie sweep silently does nothing on exactly the cookies
     *    that matter. `reauth=1` makes wp-login.php call wp_clear_auth_cookie()
     *    server-side, which is the only thing that reliably forces the prompt.
     *
     * 2. A ROOT-RELATIVE redirect_to. On login wp-login.php passes the target
     *    through wp_safe_redirect() -> wp_validate_redirect(), which drops any
     *    URL whose host is not in `allowed_redirect_hosts` and falls back to
     *    admin_url(). An absolute URL built here is computed from the RESTORED
     *    siteurl, so whenever the backup's domain differs even cosmetically
     *    from the host the browser is on (127.0.0.1 vs localhost, www vs
     *    apex, a staging alias), validation rejects it and the user lands on
     *    the dashboard. Verified: absolute+other-host -> rejected;
     *    root-relative -> accepted. A root-relative path has no host to
     *    disagree about, so it survives on whatever domain the user is on.
     */
    public static function build_finalize_redirect_url(): string {
        $token = self::current_finalize_token();

        // Force fresh reads of siteurl/home. After restore these options
        // contain the CORRECT (restored) domain, but the current request's
        // alloptions cache still holds the pre-restore values. Without this
        // the returned login URL points at the OLD domain, the browser
        // redirects there, and the user never lands on the new site to
        // complete finalisation.
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('siteurl', 'options');
        wp_cache_delete('home', 'options');

        // Root-relative login URL, for the same host-mismatch reason as the
        // redirect target: navigating to the restored siteurl's wp-login.php
        // can bounce the browser to a domain it isn't currently on.
        $login = '/wp-login.php';
        if (($path = wp_parse_url(wp_login_url(), PHP_URL_PATH)) !== null && $path !== '') {
            $login = $path;
        }

        if ($token === '') {
            return add_query_arg('reauth', '1', $login);
        }

        // Do NOT send the browser straight to wp-login.php?reauth=1. Login-
        // hiding plugins (WPS Hide Login, Admin Speedboost, ...) intercept a
        // request for the login slug from an already-authenticated user and
        // bounce it to wp-admin BEFORE wp-login.php runs, so reauth=1 never
        // clears the cookie: the user stays logged in and lands on the
        // dashboard instead of Settings > Permalinks. Reproduced against
        // Admin Speedboost's hide-login module. Instead we hit our own
        // admin-post.php action, which clears the session server-side and
        // then redirects to the login screen (see Admin::handle_finalize_logout).
        // admin-post.php is left alone by those plugins for both logged-in
        // and logged-out visitors.
        $admin_post = '/wp-admin/admin-post.php';
        if (($ppath = wp_parse_url(admin_url('admin-post.php'), PHP_URL_PATH)) !== null && $ppath !== '') {
            $admin_post = $ppath;
        }

        return add_query_arg(
            [
                'action' => 'sitessaver_finalize_logout',
                'token'  => $token,
            ],
            $admin_post
        );
    }

    /**
     * Where the login screen should send the user once they have logged in
     * again: Settings > Permalinks carrying the one-time finalize token.
     * Root-relative for the host-mismatch reason documented above.
     */
    public static function finalize_permalinks_path(string $token): string {
        $permalinks = '/wp-admin/options-permalink.php';
        if (($apath = wp_parse_url(admin_url('options-permalink.php'), PHP_URL_PATH)) !== null && $apath !== '') {
            $permalinks = $apath;
        }
        return add_query_arg('sitessaver_finalize', $token, $permalinks);
    }

    /**
     * Run the deferred finalisation on a fresh admin request.
     *
     * Hooked to `admin_init` (see Plugin::init). Safe to call switch_theme()
     * and update_option('active_plugins') here because `init` hasn't fired
     * yet in this request. When it does, the restored plugin set boots
     * normally without the too-early textdomain warnings.
     */
    public static function run_deferred_finalisation(): void {
        // Flush BEFORE reading the pending option. On sites with persistent
        // object caches (Redis, Memcached, WP Super Cache) the current
        // request's in-memory alloptions set was populated from the PRE-
        // restore DB, so get_option() would hit that cache and miss the
        // freshly-written pending-finalize row. Flushing first forces a
        // fresh DB read on the very next call.
        wp_cache_flush();

        $pending = get_option('sitessaver_pending_finalize', null);
        if (!is_array($pending)) {
            return;
        }
        delete_option('sitessaver_pending_finalize');
        delete_transient('sitessaver_finalize_token');

        if (!empty($pending['active_plugins']) && is_array($pending['active_plugins'])) {
            // Puts back the backup's own active-plugin list, part of the
            // restore the admin started.
            update_option('active_plugins', $pending['active_plugins']);
        }

        // switch_theme() fires after_switch_theme + setup_theme action hooks.
        // A restored theme with a fatal error there would 500 the admin page.
        // Guard so finalisation still completes (permalinks counter gets set,
        // banner shows, user can proceed) even when the restored theme is
        // broken. They can then switch themes from Appearance after the
        // permalinks round-trip. The error is logged for diagnosis.
        if (!empty($pending['active_theme'])) {
            try {
                $theme = wp_get_theme($pending['active_theme']);
                if ($theme->exists()) {
                    switch_theme($pending['active_theme']);
                }
            } catch (\Throwable $e) {
                Log::warning('finalise_theme_failed', 'The theme could not be switched: ' . $e->getMessage());
            }
        }

        wp_cache_flush();

        // Counter starts at 2 (user must save Permalinks twice). Admin
        // decrements it on each ?settings-updated=true round-trip. When it
        // hits zero the rewrite rules are considered flushed and the
        // restore banner disappears.
        set_transient('sitessaver_needs_permalinks_flush', 2, DAY_IN_SECONDS);
    }
}
