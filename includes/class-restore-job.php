<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * A restore that runs in a background request instead of the browser's.
 *
 * Why: the restore used to run inside the single AJAX request that the
 * browser made. Shared hosts cap a request at 60–120 seconds at the web
 * server (LiteSpeed, nginx, Cloudflare), regardless of PHP's own
 * set_time_limit(0). Extracting and importing a large site takes minutes,
 * so the browser got a 500/504 back mid-restore and showed "An error
 * occurred." while PHP was still busy rewriting the site.
 *
 * Now the browser only starts a job and then polls for its state. Every
 * request it makes is short. The work runs in a detached loopback request
 * (same mechanism the export worker has used since 1.4.1); on hosts that
 * block loopbacks, the browser runs it in-request instead, and still reads
 * progress through polling, so even a gateway timeout no longer hides the
 * outcome.
 *
 * Job state lives in a FILE, not the options table, on purpose: the restore
 * replaces the database halfway through, which would wipe any option or
 * transient tracking it. The job file also carries its own access token for
 * the status poll, because after the database swap the browser's login
 * cookie and nonce no longer validate.
 */
final class Restore_Job {

    /** Running job with no sign of life for this long is presumed dead. */
    public const STALL_SECONDS = 900;

    /** A queued job the worker never picked up after this long → run inline. */
    public const START_GRACE_SECONDS = 25;

    /** Persist a heartbeat at most this often. */
    private const BEAT_INTERVAL = 5;

    /**
     * A running job silent this long with nobody holding its lock: the
     * host stopped the worker. Start a new one (it continues from the
     * cursor), and after AUTO_RESUMES the open browser tab runs the slices.
     */
    public const RESPAWN_SECONDS = 60;
    public const AUTO_RESUMES    = 2;

    /** Persist the cursor at most this often (forced commits always write). */
    private const CURSOR_INTERVAL = 2;

    /** Open lock handle while this request owns a job (flock, see claim()). */
    private static $lock_handle = null;

    /** True once this request's slice ended normally. */
    private static bool $slice_clean = true;

    private static int $last_cursor_save = 0;

    private const GUARD = "<?php exit; ?>\n";

    /** Job id running in THIS process, if any. */
    private static ?string $current = null;

    private static int $last_beat = 0;

    private static bool $hooks_added = false;

    // ------------------------------------------------------------------
    // Storage
    // ------------------------------------------------------------------

    public static function dir(): string {
        return SITESSAVER_STORAGE_DIR . '/jobs';
    }

    private static function path(string $id): string {
        return self::dir() . '/restore-' . $id . '.php';
    }

    private static function lock_path(string $id): string {
        return self::dir() . '/restore-' . $id . '.lock';
    }

    public static function valid_id(string $id): bool {
        return (bool) preg_match('/^[a-z0-9]{16}$/', $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $id): ?array {
        if (!self::valid_id($id)) {
            return null;
        }
        $path = self::path($id);
        clearstatcache(true, $path);
        if (!is_readable($path)) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        $raw = str_starts_with($raw, self::GUARD) ? substr($raw, strlen(self::GUARD)) : $raw;
        $job = json_decode($raw, true);
        return is_array($job) ? $job : null;
    }

    /**
     * Atomic write (temp file + rename) so a poll never reads half a file.
     *
     * @param array<string, mixed> $job
     */
    private static function save(array $job): void {
        $dir = self::dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        sitessaver_protect_directory($dir);

        $path = self::path((string) $job['id']);
        $tmp  = $path . '.' . getmypid() . '.tmp';
        $json = wp_json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }
        if (@file_put_contents($tmp, self::GUARD . $json, LOCK_EX) !== false) {
            // Atomic on POSIX. On Windows the rename fails while another
            // request (the status poll) has the file open; retry briefly,
            // then write in place. Never delete first: a delete that lands
            // while the file is open leaves NO job file behind.
            for ($try = 0; $try < 20; $try++) {
                if (@rename($tmp, $path)) {
                    return;
                }
                usleep(25000);
            }
            @file_put_contents($path, self::GUARD . $json, LOCK_EX);
            @unlink($tmp);
        }
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $fn
     */
    private static function update(string $id, callable $fn): ?array {
        $job = self::get($id);
        if ($job === null) {
            return null;
        }
        $job = $fn($job);
        $job['updated'] = time();
        self::save($job);
        return $job;
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    /**
     * Create a queued job.
     *
     * @param string $source_type 'file' (backup already in storage) or 'gdrive'.
     * @param string $source      Backup filename or Drive file id.
     * @return array{id: string, token: string, worker_key: string}
     */
    public static function create(string $source_type, string $source): array {
        self::sweep();

        $id         = strtolower(wp_generate_password(16, false, false));
        $token      = wp_generate_password(40, false, false);
        $worker_key = wp_generate_password(40, false, false);

        self::save([
            'id'          => $id,
            'token_hash'  => hash('sha256', $token),
            'worker_hash' => hash('sha256', $worker_key),
            'status'      => 'queued',
            'phase'       => $source_type === 'gdrive' ? 'download' : 'queued',
            'label'       => $source_type === 'gdrive'
                ? __('Downloading from Google Drive...', 'sitessaver')
                : __('Preparing restore...', 'sitessaver'),
            'mode'        => null,
            'source_type' => $source_type,
            'source'      => $source,
            'blog_id'     => get_current_blog_id(),
            'user_id'     => get_current_user_id(),
            'created'     => time(),
            'updated'     => time(),
            'result'      => null,
            'error'       => null,
        ]);

        Log::info('restore_queued', 'Restore queued.', ['job' => $id, 'source_type' => $source_type, 'source' => $source]);

        return ['id' => $id, 'token' => $token, 'worker_key' => $worker_key];
    }

    /**
     * Fire the detached loopback worker. Non-blocking: returns in
     * milliseconds whatever the restore's length. False only when the
     * request could not even be sent; a silently-dropped loopback is caught
     * later by the client noticing the job never left `queued`.
     */
    public static function spawn(string $id, string $worker_key): bool {
        $response = wp_remote_post(Background::worker_url(), [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
            'body'      => [
                'action' => 'sitessaver_restore_worker',
                'job'    => $id,
                'key'    => $worker_key,
            ],
        ]);

        if (is_wp_error($response)) {
            Log::warning('restore_spawn_failed', 'Could not start the background restore; the browser will run it instead.', [
                'job'   => $id,
                'error' => $response->get_error_message(),
            ]);
            return false;
        }
        return true;
    }

    public static function verify_token(array $job, string $token): bool {
        return $token !== '' && hash_equals((string) ($job['token_hash'] ?? ''), hash('sha256', $token));
    }

    public static function verify_worker_key(array $job, string $key): bool {
        return $key !== '' && !empty($job['worker_hash']) && hash_equals((string) $job['worker_hash'], hash('sha256', $key));
    }

    /**
     * Claim the job for this process. Exactly one caller wins — the
     * background worker and the in-browser fallback can both try, and the
     * restore must never run twice at once.
     */
    public static function claim(string $id): bool {
        // An OS file lock, held for the life of this request. The OS drops
        // it when the request ends in any way — finished, fatal error, or
        // the host killing PHP — so a dead worker can never wedge the job,
        // and a slow-but-alive one can never be raced by a second runner.
        $dir = self::dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $h = @fopen(self::lock_path($id), 'c');
        if ($h === false) {
            return false;
        }
        if (!flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);
            return false;
        }
        self::$lock_handle = $h;
        return true;
    }

    public static function release(): void {
        if (self::$lock_handle !== null) {
            flock(self::$lock_handle, LOCK_UN);
            fclose(self::$lock_handle);
            self::$lock_handle = null;
        }
    }

    /** Is some request working on this job right now? */
    public static function is_busy(string $id): bool {
        if (self::$lock_handle !== null && self::$current === $id) {
            return true;
        }
        $h = @fopen(self::lock_path($id), 'c');
        if ($h === false) {
            return false;
        }
        $free = flock($h, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($h, LOCK_UN);
        }
        fclose($h);
        return !$free;
    }

    /** The open browser tab runs this job's slices from now on. */
    public static function mark_browser_driven(string $id): void {
        self::update($id, static function (array $j): array {
            $j['driver'] = 'browser';
            return $j;
        });
    }

    /** Hand the next slice to a fresh background request (new single-use key). */
    public static function spawn_next(string $id): bool {
        $key = wp_generate_password(40, false, false);
        self::update($id, static function (array $j) use ($key): array {
            $j['worker_hash'] = hash('sha256', $key);
            return $j;
        });
        return self::spawn($id, $key);
    }

    /**
     * Save the restore position into the job file.
     *
     * @param array<string, mixed> $cursor
     */
    private static function save_cursor(string $id, array $cursor, bool $force): void {
        $now = time();
        if (!$force && $now - self::$last_cursor_save < self::CURSOR_INTERVAL) {
            return;
        }
        self::$last_cursor_save = $now;
        self::$last_beat        = $now;
        self::update($id, static function (array $j) use ($cursor): array {
            $j['cursor'] = $cursor;
            return $j;
        });
    }

    /**
     * Run ONE slice of the claimed job in this request.
     *
     * The restore is a chain of slices (see Import::restore_slice()): each
     * request works until its time budget is used, saves its place in the
     * job file, and — in a background worker — starts the next request. In
     * the browser fallback ('inline') the open tab asks for the next slice.
     *
     * @param string $mode 'background' or 'inline' — recorded for diagnosis.
     */
    public static function run(string $id, string $mode): void {
        $job = self::get($id);
        if ($job === null || !in_array($job['status'] ?? '', ['queued', 'running'], true)) {
            return;
        }

        @ignore_user_abort(true);
        @set_time_limit(0);
        wp_raise_memory_limit('admin');

        // Load every class the rest of this request may need NOW, while the
        // plugin's own files are known-good.
        foreach ([Log::class, Errors::class, Import::class, Database::class, Archive::class, Index::class, GDrive::class, Export::class] as $class) {
            class_exists($class);
        }

        self::$current          = $id;
        self::$last_beat        = time();
        self::$last_cursor_save = time();
        self::$slice_clean      = false;
        self::add_hooks();

        $budget = $mode === 'background'
            ? Export::slice_budget(['slice' => (int) ($job['slice'] ?? 0)])
            : Export::browser_budget();
        Export::begin_slice($budget);

        $first = ($job['status'] ?? '') === 'queued';
        self::update($id, static function (array $j) use ($mode): array {
            $j['status']      = 'running';
            $j['mode']        = $mode;
            $j['started']     = $j['started'] ?? time();
            $j['worker_hash'] = null; // single use
            $j['slices']      = (int) ($j['slices'] ?? 0) + 1;
            return $j;
        });

        if ($first) {
            Log::info('restore_started', 'Restore started.', ['job' => $id, 'mode' => $mode, 'source' => $job['source'] ?? '', 'slice_seconds' => $budget]);
        }

        // A fatal error skips the catch below. PHP's own time limit is not a
        // failure: everything up to the last saved position is kept, so the
        // next slice simply gets less time. Anything else is reported.
        register_shutdown_function(static function () use ($id, $mode, $budget): void {
            if (self::$slice_clean) {
                return;
            }
            $job = self::get($id);
            if ($job === null || ($job['status'] ?? '') !== 'running') {
                return;
            }
            $err = error_get_last();
            $fatal = $err && in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
            if (!$fatal) {
                return; // ended without an error (killed/exit): the poll restarts it
            }
            $tech = sprintf('%s in %s:%d', $err['message'], $err['file'], $err['line']);
            if (stripos((string) $err['message'], 'Maximum execution time') !== false && (int) ($job['timeouts'] ?? 0) < 6) {
                self::update($id, static function (array $j) use ($budget): array {
                    $j['timeouts'] = (int) ($j['timeouts'] ?? 0) + 1;
                    $j['slice']    = max(5, intdiv($budget, 2));
                    return $j;
                });
                self::release();
                Log::warning('restore_slice_timeout', $tech . ' — continuing with a shorter slice.', ['job' => $id]);
                if ($mode === 'background') {
                    self::spawn_next($id);
                }
                return;
            }
            self::fail($id, $tech, (string) ($job['phase'] ?? ''));
        });

        try {
            $cursor = is_array($job['cursor'] ?? null) ? $job['cursor'] : [];
            $file   = (string) ($cursor['file'] ?? $job['source'] ?? '');

            if (($job['source_type'] ?? '') === 'gdrive' && empty($cursor['file'])) {
                self::phase('download', __('Downloading from Google Drive...', 'sitessaver'));
                $dl = GDrive::download((string) ($job['source'] ?? ''));
                if (empty($dl['success']) || empty($dl['file'])) {
                    $tech = (string) ($dl['message'] ?? 'Google Drive download failed.');
                    self::$slice_clean = true;
                    self::fail($id, $tech, 'download', 'gdrive_download_failed');
                    return;
                }
                $file = (string) $dl['file'];
                $cursor['file'] = $file;
                self::save_cursor($id, $cursor, true);
            }

            $done = Import::restore_slice($cursor, $file, [Export::class, 'out_of_time'], static function (array $c, bool $force) use ($id, $file): void {
                $c['file'] = $file;
                self::save_cursor($id, $c, $force);
            });
            $cursor['file'] = $file;

            if ($done) {
                Database::drop_previous_tables((array) ($cursor['db'] ?? []));
                self::$slice_clean = true;
                self::complete($id, [
                    'message'        => __('Site restored successfully. Please log in again.', 'sitessaver'),
                    'finalize_token' => Import::current_finalize_token(),
                    'finalize_url'   => Import::build_finalize_redirect_url(),
                ]);
                return;
            }

            self::save_cursor($id, $cursor, true);
            self::$slice_clean = true;
            self::release();
            if ($mode === 'background') {
                self::spawn_next($id);
            }
        } catch (\Throwable $e) {
            self::$slice_clean = true;
            $now = self::get($id);
            self::fail($id, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), (string) ($now['phase'] ?? ''));
        } finally {
            self::$slice_clean = true;
            Export::end_slice();
            self::$current = null;
            self::release();
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function complete(string $id, array $result): void {
        $job = self::update($id, static function (array $j) use ($result): array {
            $j['status'] = 'completed';
            $j['phase']  = 'done';
            $j['label']  = __('Restore complete.', 'sitessaver');
            $j['result'] = $result;
            return $j;
        });

        Log::info('restore_completed', 'Restore completed.', [
            'job'      => $id,
            'mode'     => $job['mode'] ?? '',
            'duration' => isset($job['started']) ? time() - (int) $job['started'] . 's' : '',
        ]);
    }

    /**
     * Mark failed, log once, and store the plain-language payload.
     */
    public static function fail(string $id, string $technical, string $phase = '', ?string $code = null): void {
        $job = self::get($id);
        if ($job === null || in_array($job['status'] ?? '', ['failed', 'completed'], true)) {
            return;
        }

        // The restored database may already be switched in while the rest
        // of the restore stopped. Put the previous one back.
        $db = $job['cursor']['db'] ?? null;
        if (is_array($db) && !empty($db['stage'])) {
            if (Database::rollback_swap($db) && !empty($db['swapped'])) {
                $technical .= ' ' . __('The previous database was put back.', 'sitessaver');
                $code       = $code ?? 'restore_rolled_back';
            } elseif (empty($db['swapped']) && $phase === 'database') {
                // Staged and never switched in: the live site was not touched.
                $code = $code ?? (Errors::classify_restore($technical, 'manifest'));
            }
        }

        $code    = $code ?? Errors::classify_restore($technical, $phase);
        $payload = Errors::report($code, $technical, [
            'job'      => $id,
            'phase'    => $phase,
            'mode'     => $job['mode'] ?? '',
            'source'   => $job['source'] ?? '',
            'elapsed'  => isset($job['started']) ? time() - (int) $job['started'] . 's' : '',
            'memory'   => size_format(memory_get_peak_usage(true)),
            'php'      => PHP_VERSION,
            'server'   => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '',
        ]);

        self::update($id, static function (array $j) use ($payload): array {
            $j['status'] = 'failed';
            $j['error']  = $payload;
            return $j;
        });

        $temp = $job['cursor']['temp'] ?? '';
        if (is_string($temp) && $temp !== '') {
            sitessaver_cleanup_temp($temp);
        }
    }

    // ------------------------------------------------------------------
    // Progress reporting from inside the restore
    // ------------------------------------------------------------------

    /**
     * Record the phase the restore just entered. No-op outside a job.
     */
    public static function phase(string $phase, string $label): void {
        if (self::$current === null) {
            return;
        }
        self::$last_beat = time();
        self::update(self::$current, static function (array $j) use ($phase, $label): array {
            $j['phase'] = $phase;
            $j['label'] = $label;
            return $j;
        });
    }

    /**
     * Liveness ping from the long loops (extract, SQL replay, file copy).
     * Throttled; costs one small file write every few seconds.
     */
    public static function heartbeat(): void {
        if (self::$current === null) {
            return;
        }
        $now = time();
        if ($now - self::$last_beat < self::BEAT_INTERVAL) {
            return;
        }
        self::$last_beat = $now;
        self::update(self::$current, static fn(array $j): array => $j);
    }

    private static function add_hooks(): void {
        if (self::$hooks_added) {
            return;
        }
        self::$hooks_added = true;
        add_action('sitessaver_heartbeat', [self::class, 'heartbeat']);
        add_action('sitessaver_database_restored', [self::class, 'keep_plugin_active']);
    }

    /**
     * After the database swap, `active_plugins` is the BACKUP's list, which
     * may name SitesSaver under a different folder (or not at all). The
     * browser's status poll goes through admin-ajax and needs this plugin
     * loaded to get an answer, so make sure the running copy is active.
     * Deferred finalisation sets the backup's own list afterwards, exactly
     * as before.
     */
    public static function keep_plugin_active(): void {
        if (self::$current === null || !defined('SITESSAVER_FILE')) {
            return;
        }
        $basename = plugin_basename(SITESSAVER_FILE);
        if (is_multisite()) {
            $network = (array) get_site_option('active_sitewide_plugins', []);
            if (isset($network[$basename])) {
                return;
            }
        }
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('active_plugins', 'options');
        $active = (array) get_option('active_plugins', []);
        if (!in_array($basename, $active, true)) {
            $active[] = $basename;
            update_option('active_plugins', array_values($active));
        }
    }

    // ------------------------------------------------------------------
    // What the browser sees
    // ------------------------------------------------------------------

    /**
     * Public view of a job for the status poll. Detects stalls and workers
     * that never started, so the client never waits forever.
     *
     * @return array<string, mixed>
     */
    public static function public_view(array $job): array {
        $status = (string) ($job['status'] ?? '');
        $now    = time();

        $since = $now - (int) ($job['updated'] ?? $now);
        $busy  = $status === 'running' && self::is_busy((string) $job['id']);

        // Silent and nobody working on it: the host stopped the worker.
        if ($status === 'running' && !$busy && $since > self::RESPAWN_SECONDS && ($job['driver'] ?? '') !== 'browser') {
            if (($job['mode'] ?? '') === 'background' && (int) ($job['resumes'] ?? 0) < self::AUTO_RESUMES) {
                $job = self::update((string) $job['id'], static function (array $j): array {
                    $j['resumes'] = (int) ($j['resumes'] ?? 0) + 1;
                    $j['slice']   = max(5, intdiv(Export::slice_budget(['slice' => (int) ($j['slice'] ?? 0)]), 2));
                    return $j;
                }) ?? $job;
                Log::warning('restore_auto_resumed', sprintf('No progress for %ds during phase "%s"; started a new worker.', $since, (string) ($job['phase'] ?? '')), ['job' => $job['id']]);
                self::spawn_next((string) $job['id']);
            } else {
                $job = self::update((string) $job['id'], static function (array $j): array {
                    $j['driver'] = 'browser';
                    return $j;
                }) ?? $job;
                Log::warning('restore_browser_takeover', 'Background restore requests keep stopping on this host; the browser continues the restore.', ['job' => $job['id']]);
            }
            $since = 0;
        }

        if ($status === 'running' && !$busy && $now - (int) ($job['updated'] ?? $now) > self::STALL_SECONDS) {
            self::fail(
                (string) $job['id'],
                sprintf('No progress for %ds during phase "%s" — the server stopped the restore request.', $now - (int) $job['updated'], (string) ($job['phase'] ?? '')),
                (string) ($job['phase'] ?? ''),
                'restore_stalled'
            );
            $job    = self::get((string) $job['id']) ?? $job;
            $status = (string) ($job['status'] ?? '');
        }

        return [
            'status'          => $status,
            'phase'           => (string) ($job['phase'] ?? ''),
            'label'           => (string) ($job['label'] ?? ''),
            'mode'            => $job['mode'] ?? null,
            'seconds_since'   => $now - (int) ($job['updated'] ?? $now),
            'not_started'     => $status === 'queued' && $now - (int) ($job['created'] ?? $now) > self::START_GRACE_SECONDS,
            'drive'           => $status === 'running' && ($job['driver'] ?? '') === 'browser',
            'result'          => $status === 'completed' ? $job['result'] : null,
            'error'           => $status === 'failed' ? $job['error'] : null,
        ];
    }

    /**
     * Drop job files older than a day. Cheap; runs when a job is created.
     */
    private static function sweep(): void {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return;
        }
        $cutoff = time() - DAY_IN_SECONDS;
        foreach ((array) glob($dir . '/restore-*.php') as $f) {
            if (!is_string($f) || @filemtime($f) >= $cutoff) {
                continue;
            }
            // An abandoned job may have left staged tables (never switched
            // in) or the previous tables (switched in, never finished).
            // A day later the switched-in state is the site; keep it.
            $id  = (string) preg_replace('/^restore-(.*)\.php$/', '$1', basename($f));
            $job = self::get($id);
            $db  = is_array($job['cursor']['db'] ?? null) ? $job['cursor']['db'] : [];
            if (!empty($db['stage'])) {
                Database::discard_staging($db);
                Database::drop_previous_tables($db);
            }
            @unlink($f);
            @unlink(self::lock_path($id));
        }
    }
}
