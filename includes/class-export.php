<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Export engine — creates full site backup (DB + files → ZIP).
 */
final class Export {

    /**
     * Get the defined steps for the export pipeline.
     *
     * The percentages are a rough map of how long each stage takes, and the
     * LAST step must land on 100. When the backup is also going to Google
     * Drive, the upload happens inside the finalize step and is usually the
     * slowest part of the whole export — so finalize cannot own 100 or the
     * bar sits full and motionless for the entire upload. In that case the
     * local work is compressed into the first 60% and the upload is given a
     * step of its own, which the client fills from real byte progress
     * reported by GDrive::upload().
     *
     * @param string $destination local|gdrive|both
     * @return list<array{id: string, label: string, pct: int, poll?: string}>
     */
    public static function get_steps(string $destination = 'local'): array {
        $uploads_to_drive = in_array($destination, ['gdrive', 'both'], true);

        if (!$uploads_to_drive) {
            return [
                ['id' => 'init',      'label' => __('Initializing...', 'sitessaver'), 'pct' => 5],
                ['id' => 'manifest',  'label' => __('Creating manifest...', 'sitessaver'), 'pct' => 10],
                ['id' => 'db',        'label' => __('Exporting database...', 'sitessaver'), 'pct' => 25],
                ['id' => 'uploads',   'label' => __('Copying uploads...', 'sitessaver'), 'pct' => 45],
                ['id' => 'plugins',   'label' => __('Copying plugins...', 'sitessaver'), 'pct' => 60],
                ['id' => 'themes',    'label' => __('Copying themes...', 'sitessaver'), 'pct' => 75],
                ['id' => 'other',     'label' => __('Copying other files...', 'sitessaver'), 'pct' => 80],
                ['id' => 'zip',       'label' => __('Creating ZIP archive...', 'sitessaver'), 'pct' => 95],
                ['id' => 'finalize',  'label' => __('Finalizing...', 'sitessaver'), 'pct' => 100],
            ];
        }

        return [
            ['id' => 'init',      'label' => __('Initializing...', 'sitessaver'), 'pct' => 3],
            ['id' => 'manifest',  'label' => __('Creating manifest...', 'sitessaver'), 'pct' => 6],
            ['id' => 'db',        'label' => __('Exporting database...', 'sitessaver'), 'pct' => 15],
            ['id' => 'uploads',   'label' => __('Copying uploads...', 'sitessaver'), 'pct' => 28],
            ['id' => 'plugins',   'label' => __('Copying plugins...', 'sitessaver'), 'pct' => 38],
            ['id' => 'themes',    'label' => __('Copying themes...', 'sitessaver'), 'pct' => 46],
            ['id' => 'other',     'label' => __('Copying other files...', 'sitessaver'), 'pct' => 50],
            ['id' => 'zip',       'label' => __('Creating ZIP archive...', 'sitessaver'), 'pct' => 60],
            [
                'id'    => 'finalize',
                'label' => __('Uploading to Google Drive...', 'sitessaver'),
                'pct'   => 100,
                // Tells the client to poll this job for real upload progress
                // and to animate the bar from 'from' to 'pct' as bytes land.
                'poll'  => 'gdrive',
                'from'  => 60,
            ],
        ];
    }

    /**
     * The progress-job id used for the Drive upload of a given export.
     *
     * Shared by the server (which writes progress under this key) and the
     * client (which polls it), so the two cannot drift apart.
     */
    public static function gdrive_job_id(string $uid): string {
        return 'exp_gdrive_' . $uid;
    }

    // ---------------------------------------------------------------
    // Heartbeat
    //
    // A single step (copying 35k uploads, zipping 5 GB) runs for minutes
    // inside one PHP process. Nothing observing it — the browser poll, the
    // stale-worker watchdog — can tell "still working" from "died" unless the
    // step says so while it runs. These ticks refresh the status transient's
    // last_update from inside the long loops. Throttled, so a tick in a tight
    // per-file loop costs effectively nothing.
    // ---------------------------------------------------------------

    /** Seconds of silence after which a running export is presumed dead. */
    public const STALL_SECONDS = 300;

    /**
     * Extended grace period for phases that are a single, uninterruptible
     * blocking call with no incremental progress API — currently only
     * ZipArchive::close(). PHP's ZipArchive defers the actual read+compress
     * +write work for every addFile()'d entry to this one call (addFile()
     * itself just registers metadata and returns almost instantly), so the
     * per-file tick() calls during the add loop cover the FAST phase and
     * go silent right when the SLOW phase starts. A multi-gigabyte
     * wp-content easily takes longer than STALL_SECONDS to compress here,
     * and there is no way to tick from inside it — so instead of lying
     * with fake periodic ticks, the phase ticks once on entry with a
     * distinguishable note and the watchdog is told to extend its patience
     * for exactly that note.
     */
    public const STALL_SECONDS_FINALIZING = 1800;

    /** Export uid currently being worked on, for tick() to address. */
    private static string $tick_uid = '';

    /** Unix time of the last persisted tick. */
    private static int $tick_last = 0;

    /** Seconds between persisted ticks. */
    private const TICK_INTERVAL = 10;

    /**
     * Files at or above this size are copied in COPY_CHUNK pieces with a
     * liveness tick between pieces. A single copy() of a multi-gigabyte file
     * on a throttled shared-host disk (~4 MB/s) runs for minutes without any
     * chance to report progress, and the stall detector then declares a
     * healthy export dead ("No progress for 301s during Copying uploads").
     */
    public const COPY_STREAM_MIN = 16 * 1024 * 1024;
    public const COPY_CHUNK      = 8 * 1024 * 1024;

    /**
     * Folders other backup plugins keep inside wp-content/uploads. Their
     * archives are often larger than the site itself, a backup of a backup
     * is never useful, and copying them is the usual reason a media step
     * takes longer than the host allows. Matched at the top of the uploads
     * folder only; logged when skipped.
     */
    public const FOREIGN_BACKUP_DIRS = [
        'ai1wm-backups',
        'backwpup-*',
        'backupbuddy_backups',
        'pb_backupbuddy',
        'updraft',
        'wp-clone',
        'wpvividbackups',
        'wp-staging',
        'backups-dup-lite',
        'backups-dup-pro',
    ];

    /** Archive type another backup plugin produces; skipped inside uploads. */
    public const FOREIGN_BACKUP_FILES = ['*.wpress'];

    /**
     * Identifies the worker request that currently owns this export. A
     * resumed export gets a new worker; the old one, if it was only slow and
     * not dead, notices on its next tick and stops instead of racing it.
     */
    private static string $worker_id = '';

    /**
     * Times a silent background worker is replaced by a new one before the
     * browser takes the export over and runs the slices itself.
     */
    public const AUTO_RESUMES = 2;

    /**
     * Seconds without any sign of life before a background worker is
     * presumed stopped by the host. A healthy worker checkpoints every few
     * seconds and hands over to the next slice within a second.
     */
    public const RESPAWN_SECONDS = 60;

    /** Exception code used to unwind a superseded worker quietly. */
    public const SUPERSEDED = 7301;

    /** Exception code used when a slice runs out of time (work is saved). */
    public const SLICE = 7302;

    /**
     * When the current request must hand over (unix time, float), or null to
     * run a step to the end. Every step checks it between units of work and
     * leaves a cursor behind, so an export is a chain of short requests and
     * no single request ever needs more than ~20-60 s. This is what makes a
     * backup possible on hosts that stop every PHP request after 30 s.
     */
    private static ?float $deadline = null;

    /** Last time a cursor was persisted (checkpoint throttle). */
    private static float $checkpoint_last = 0.0;

    /** Seconds between persisted checkpoints inside a slice. */
    private const CHECKPOINT_INTERVAL = 2.0;

    public static function begin_slice(?int $seconds): void {
        self::$deadline        = $seconds === null ? null : microtime(true) + max(1, $seconds);
        self::$checkpoint_last = 0.0;
    }

    public static function end_slice(): void {
        self::$deadline = null;
    }

    public static function out_of_time(): bool {
        return self::$deadline !== null && microtime(true) >= self::$deadline;
    }

    /** Unwind to run_step(), which reports the step as pending. */
    private static function slice_expired(): never {
        throw new \RuntimeException('Slice time used up; continuing in the next request.', self::SLICE);
    }

    /**
     * Seconds a background worker may work before handing over.
     *
     * Read AFTER set_time_limit(0): a host that honours it reports 0, one
     * that does not still shows its real limit. A worker that went silent
     * halves the slice for the next one (see the status poll), so the chain
     * settles below whatever the host actually enforces.
     *
     * @param array<string, mixed> $status
     */
    public static function slice_budget(array $status): int {
        if (!empty($status['slice'])) {
            return max(5, (int) $status['slice']);
        }
        $limit  = (int) ini_get('max_execution_time');
        $budget = $limit > 0 ? (int) floor($limit * 0.6) : 60;
        return (int) apply_filters('sitessaver_export_slice_seconds', max(8, min(120, $budget)));
    }

    /**
     * Seconds a browser-driven step request may work: inside PHP's limit and
     * the usual gateway timeout, so the response always gets back.
     */
    public static function browser_budget(): int {
        $limit  = (int) ini_get('max_execution_time');
        $budget = $limit > 0 ? max(8, min(40, (int) floor($limit * 0.6))) : 40;
        return (int) apply_filters('sitessaver_export_browser_slice_seconds', $budget);
    }

    /**
     * The saved position of a resumable step.
     *
     * @return array<string, mixed>
     */
    private static function cursor(string $uid, string $key): array {
        $status = self::get_status($uid);
        $c      = $status['cursor'][$key] ?? [];
        return is_array($c) ? $c : [];
    }

    /**
     * Persist a step's position (throttled unless forced) and prove liveness.
     *
     * @param array<string, mixed> $data
     */
    private static function checkpoint(string $uid, string $key, array $data, bool $force): void {
        $now = microtime(true);
        if (!$force && $now - self::$checkpoint_last < self::CHECKPOINT_INTERVAL) {
            return;
        }
        self::$checkpoint_last = $now;

        $status = self::get_status($uid);
        if (empty($status)) {
            return;
        }
        self::assert_owner($status);

        $status['cursor']        = is_array($status['cursor'] ?? null) ? $status['cursor'] : [];
        $status['cursor'][$key]  = $data;
        $status['last_update']   = time();
        self::$tick_last         = time();
        self::save_status($uid, $status);
    }

    /** Forget a finished step's cursor. */
    private static function clear_cursor(array &$status, string $key): void {
        if (isset($status['cursor'][$key])) {
            unset($status['cursor'][$key]);
        }
    }

    /**
     * The browser is about to run a slice itself: make it the owner, so a
     * background worker that wakes up late stands aside instead of racing.
     */
    public static function claim_for_browser(string $uid): void {
        $status = self::get_status($uid);
        if (empty($status) || ($status['status'] ?? '') !== 'running') {
            return;
        }
        $status['worker']      = 'browser';
        $status['driver']      = 'browser';
        $status['last_update'] = time();
        self::save_status($uid, $status);
    }

    /**
     * Fire a non-blocking loopback request that runs the next export slice.
     *
     * `blocking => false` makes WP write the request and return without
     * reading the response, so this costs milliseconds. The worker
     * authenticates with a single-use key, because the detached request
     * carries no session.
     */
    public static function spawn_worker(string $uid): bool {
        $key = wp_generate_password(32, false, false);
        set_transient('sitessaver_worker_' . $uid, $key, HOUR_IN_SECONDS);

        $response = wp_remote_post(Background::worker_url(), [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
            'body'      => [
                'action' => 'sitessaver_export_work',
                'uid'    => $uid,
                'key'    => $key,
            ],
        ]);

        return !is_wp_error($response);
    }

    /** Open lock handle while this request works on an export. */
    private static $lock_handle = null;

    /**
     * Only one request may work on an export at a time.
     *
     * Background worker, rescuer (WP-Cron / server cron) and the browser can
     * all try to run the next slice. Two of them appending to the same SQL or
     * ZIP file would corrupt it silently, even with the ownership check,
     * because a slow worker only notices it was replaced at its next
     * checkpoint. An OS file lock closes that window; the OS releases it
     * when the request ends in any way, including the host killing PHP.
     */
    public static function lock(string $uid): bool {
        if (self::$lock_handle !== null) {
            return true;
        }
        if (!is_dir(SITESSAVER_TEMP_DIR)) {
            wp_mkdir_p(SITESSAVER_TEMP_DIR);
        }
        $h = @fopen(SITESSAVER_TEMP_DIR . '/' . sanitize_file_name($uid) . '.lock', 'c');
        if ($h === false) {
            return true; // cannot lock on this filesystem: behave as before
        }
        if (!flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);
            return false;
        }
        self::$lock_handle = $h;
        return true;
    }

    public static function unlock(): void {
        if (self::$lock_handle !== null) {
            flock(self::$lock_handle, LOCK_UN);
            fclose(self::$lock_handle);
            self::$lock_handle = null;
        }
    }

    /**
     * Start a scheduled backup and run as much of it as this request may.
     *
     * Scheduled backups used to run start-to-finish in one request, which a
     * host that stops requests after ~30 s never allows. The rest now runs as
     * the same chain of slices as a manual export (worker, watchdog, server
     * cron). Drive upload, retention and the email happen when it finishes
     * (action `sitessaver_scheduled_export_finished`).
     *
     * @return array<string, mixed> The result when finished in this request,
     *                              else ['success' => true, 'pending' => true].
     */
    public static function run_bounded(array $options): array {
        $status = self::start($options);
        $uid    = (string) $status['uid'];
        $res    = self::work($uid);

        $now = self::get_status($uid);
        if (($now['status'] ?? '') === 'completed') {
            return (array) $now['result'];
        }
        if (($now['status'] ?? '') === 'error' || empty($res['success'])) {
            return ['success' => false, 'message' => (string) ($now['message'] ?? $res['message'] ?? 'Unknown error')];
        }

        self::spawn_worker($uid);
        return [
            'success' => true,
            'pending' => true,
            'uid'     => $uid,
            'message' => __('Backup started; it continues in the background.', 'sitessaver'),
        ];
    }

    /** Scratch dir for the ZIP builder — beside, never inside, temp_dir. */
    private static function zip_work_dir(string $temp_dir): string {
        return rtrim($temp_dir, '/\\') . '-zip';
    }

    /**
     * Remove everything an unfinished export left behind.
     *
     * @param array<string, mixed> $status
     */
    public static function discard_work(array $status): void {
        if (!empty($status['temp_dir'])) {
            self::remove_directory((string) $status['temp_dir']);
            self::remove_directory(self::zip_work_dir((string) $status['temp_dir']));
        }
        if (!empty($status['backup_name'])) {
            @unlink(sitessaver_storage_dir() . '/' . $status['backup_name'] . '.part');
        }
    }

    /** File currently being copied, for the stall report. */
    private static array $current_item = [];

    public static function begin_ticks(string $uid): void {
        self::$tick_uid  = $uid;
        self::$tick_last = 0;
    }

    public static function end_ticks(): void {
        self::$tick_uid  = '';
        self::$tick_last = 0;
    }

    /**
     * Report liveness unconditionally, bypassing the TICK_INTERVAL throttle.
     * For one-off phase transitions (not a loop) — e.g. "about to enter a
     * single long blocking call with no internal progress hook" — where the
     * normal throttle could suppress the one tick that actually matters
     * (it fires regardless of how recently a previous tick landed).
     *
     * @param string $note Short label, e.g. 'zip-finalizing'.
     */
    public static function tick_phase(string $note = ''): void {
        if (self::$tick_uid === '') {
            return;
        }

        self::$tick_last = time();

        $status = self::get_status(self::$tick_uid);
        if (empty($status)) {
            return;
        }

        self::assert_owner($status);

        $status['last_update'] = self::$tick_last;
        $status['detail']      = ['note' => $note, 'done' => 0];
        self::save_status(self::$tick_uid, $status);
    }

    /**
     * Stop this worker if a newer one has taken the export over.
     *
     * @param array<string, mixed> $status
     */
    private static function assert_owner(array $status): void {
        if (self::$worker_id !== '' && ($status['worker'] ?? '') !== self::$worker_id) {
            throw new \RuntimeException('This export was taken over by a newer worker.', self::SUPERSEDED);
        }
    }

    /**
     * Report liveness from inside a long-running step.
     *
     * @param string $note  Short label, e.g. 'zip'.
     * @param int    $done  Items processed so far (files copied/added).
     */
    public static function tick(string $note = '', int $done = 0): void {
        if (self::$tick_uid === '') {
            return;
        }

        $now = time();
        if ($now - self::$tick_last < self::TICK_INTERVAL) {
            return;
        }
        self::$tick_last = $now;

        $status = self::get_status(self::$tick_uid);
        if (empty($status)) {
            return;
        }

        self::assert_owner($status);

        $status['last_update'] = $now;
        $status['detail']      = ['note' => $note, 'done' => $done] + self::$current_item;
        self::save_status(self::$tick_uid, $status);
    }

    /**
     * Run an export to completion in the CURRENT request.
     *
     * Called by the background worker (see Ajax::handle_export_work). The
     * browser is not waiting on this request, so a step may take as long as it
     * needs; progress reaches the UI through the status transient instead of
     * through this response. If the process is killed part-way, the persisted
     * step_index (and, inside finalize, the Drive byte offset) let a later
     * worker pick up where this one stopped.
     *
     * @return array{success: bool, message?: string}
     */
    public static function work(string $uid): array {
        @set_time_limit(0);
        @ignore_user_abort(true);
        wp_raise_memory_limit('admin');

        $status = self::get_status($uid);
        if (empty($status)) {
            return ['success' => false, 'message' => __('No active export found for this ID.', 'sitessaver')];
        }

        $steps = self::get_steps((string) ($status['options']['export_destination'] ?? 'local'));

        if (!self::lock($uid)) {
            return ['success' => true, 'busy' => true];
        }

        // Claim the export. A previous worker that is still alive sees the
        // new id on its next tick and steps aside.
        self::$worker_id       = wp_generate_password(12, false, false);
        $status['worker']      = self::$worker_id;
        $status['workers']     = (int) ($status['workers'] ?? 0) + 1;
        $status['last_update'] = time();
        self::save_status($uid, $status);

        // A host that kills PHP outright (a max_execution_time it will not
        // let us lift, the memory limit) skips every catch block. Record the
        // real reason on the way out, so the user gets "the server stopped it
        // because ..." instead of a stall guess five minutes later.
        $worker = self::$worker_id;
        register_shutdown_function(static function () use ($uid, $worker): void {
            $err = error_get_last();
            if (!$err || !in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }
            $st = self::get_status($uid);
            if (empty($st) || ($st['status'] ?? '') !== 'running' || ($st['worker'] ?? '') !== $worker) {
                return;
            }
            $tech = sprintf('%s in %s:%d', $err['message'], basename((string) $err['file']), (int) $err['line']);

            // PHP's own time limit ran out mid-slice. That is not a failure
            // of the backup: everything up to the last checkpoint is saved.
            // Shorten the slice and hand over to a fresh worker right away.
            if (stripos((string) $err['message'], 'Maximum execution time') !== false
                && (int) ($st['timeouts'] ?? 0) < 6) {
                $st['timeouts']    = (int) ($st['timeouts'] ?? 0) + 1;
                $st['slice']       = max(5, intdiv(self::slice_budget($st), 2));
                $st['last_update'] = time();
                self::save_status($uid, $st);
                Log::warning('export_slice_timeout', $tech . sprintf(' — continuing with a %ds slice.', $st['slice']), self::diagnostics($st));
                self::spawn_worker($uid);
                return;
            }

            $ref  = Log::error('export_killed', $tech, self::diagnostics($st));
            $st['status']     = 'error';
            $st['message']    = $tech;
            $st['ref']        = $ref;
            $st['error_code'] = 'export_killed';
            self::save_status($uid, $st);
            delete_transient('sitessaver_active_export_id');
            Background::notify($st, false);
            if (!empty($st['options']['schedule'])) {
                do_action('sitessaver_scheduled_export_finished', $st, false);
            }
        });

        // Nothing is waiting on this response, so a Drive upload need not
        // slice itself against a gateway timeout. Everything else works in a
        // time slice: the host may stop this request at any moment.
        self::set_unbounded(true);
        self::begin_ticks($uid);
        self::begin_slice(self::slice_budget($status));

        try {
            while (true) {
                if (self::out_of_time()) {
                    return ['success' => true, 'continue' => true];
                }

                $status = self::get_status($uid);

                if (empty($status) || ($status['status'] ?? '') !== 'running') {
                    // Completed, cancelled, or errored — either way we are done.
                    return ['success' => true];
                }

                $index = (int) ($status['step_index'] ?? 0);
                if (!isset($steps[$index])) {
                    return ['success' => true];
                }

                if (($status['worker'] ?? '') !== self::$worker_id) {
                    return ['success' => true, 'superseded' => true];
                }

                $res = self::run_step($uid, $index);
                if (empty($res['success'])) {
                    return ['success' => false, 'message' => $res['message'] ?? ''];
                }
                if (!empty($res['pending']) && ($res['step'] ?? '') !== 'finalize') {
                    // Slice used up; the next worker continues from the cursor.
                    return ['success' => true, 'continue' => true];
                }
            }
        } catch (\RuntimeException $e) {
            if ($e->getCode() === self::SUPERSEDED) {
                return ['success' => true, 'superseded' => true];
            }
            throw $e;
        } finally {
            self::set_unbounded(false);
            self::end_ticks();
            self::end_slice();
            self::$worker_id    = '';
            self::$current_item = [];
            self::unlock();
        }
    }

    /**
     * Everything useful for diagnosing a stopped export without access to
     * the site: what it was doing, for how long, and the host's limits.
     *
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    public static function diagnostics(array $status): array {
        $detail = is_array($status['detail'] ?? null) ? $status['detail'] : [];
        $free   = @disk_free_space(sitessaver_storage_dir());

        return [
            'uid'                => $status['uid'] ?? '',
            'step_index'         => $status['step_index'] ?? null,
            'note'               => $detail['note'] ?? '',
            'done'               => $detail['done'] ?? null,
            'file'               => $detail['file'] ?? null,
            'file_size'          => isset($detail['size']) ? size_format((int) $detail['size']) : null,
            'file_copied'        => isset($detail['copied']) ? size_format((int) $detail['copied']) : null,
            'running_for'        => isset($status['start_time']) ? (time() - (int) $status['start_time']) . 's' : null,
            'workers'            => $status['workers'] ?? null,
            'resumes'            => $status['resumes'] ?? 0,
            'slices'             => $status['slices'] ?? 0,
            'slice_seconds'      => $status['slice'] ?? null,
            'driver'             => $status['driver'] ?? 'worker',
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit'       => ini_get('memory_limit'),
            'peak_memory'        => size_format(memory_get_peak_usage(true)),
            'disk_free'          => $free !== false ? size_format((int) $free) : 'unknown',
            'server'             => isset($_SERVER['SERVER_SOFTWARE']) ? substr((string) $_SERVER['SERVER_SOFTWARE'], 0, 60) : '',
            'php'                => PHP_VERSION,
        ];
    }

    /**
     * Start a new export process.
     */
    public static function start(array $options = []): array {
        $uid         = 'exp_' . wp_generate_password(8, false, false);
        $backup_name = sitessaver_backup_filename();
        $temp_dir    = SITESSAVER_TEMP_DIR . '/' . $uid;

        // Ensure isolated temp directory exists.
        wp_mkdir_p($temp_dir);

        // Chain tracking is opt-in, and only the scheduler opts in. A manual
        // export from the Export screen therefore behaves EXACTLY as it did
        // before incremental backups existed: full contents, no index built,
        // no chain touched. Someone reaching for the Export button wants a
        // file they can restore on its own.
        $plan = !empty($options['track_chain'])
            ? Index::plan($options)
            : ['type' => 'full', 'chain_id' => '', 'seq' => 0, 'parent' => '', 'full' => '', 'reason' => 'untracked'];

        $status = [
            'uid'         => $uid,
            'status'      => 'running',
            'step_index'  => 0,
            'backup_name' => $backup_name,
            'temp_dir'    => $temp_dir,
            'options'     => $options,
            'plan'        => $plan,
            'start_time'  => time(),
            'last_update' => time(),
        ];

        self::save_status($uid, $status);
        set_transient('sitessaver_active_export_id', $uid, HOUR_IN_SECONDS);
        Background::arm_watchdog();

        return $status;
    }

    /**
     * Upload the finished archive to Drive, resuming across requests.
     *
     * The Drive transfer state (resumable session URL + confirmed byte offset)
     * lives in the export status, so it survives the request that started it.
     * When a deadline is in force and the slice expires, this returns
     * `pending` and the caller re-enters finalize later; with no deadline (the
     * background worker) it runs the upload to completion in one go.
     *
     * @param array<string, mixed> $status
     * @return array{success?: bool, message?: string, pending?: bool, progress?: int}
     */
    private static function upload_to_drive(string $uid, array $status, string $zip_path, string $job_id): array {
        $state    = is_array($status['gdrive_state'] ?? null) ? $status['gdrive_state'] : [];
        $deadline = self::request_deadline();

        $res = GDrive::upload_step($zip_path, (string) $status['backup_name'], $job_id, $state, $deadline);

        if ($res['status'] === 'incomplete') {
            // Persist the offset so the next request continues instead of
            // restarting a multi-gigabyte upload from byte zero.
            $fresh = self::get_status($uid);
            if (!empty($fresh)) {
                $fresh['gdrive_state'] = $res['state'];
                $fresh['last_update']  = time();
                self::save_status($uid, $fresh);
            }

            return ['pending' => true, 'progress' => (int) $res['progress']];
        }

        // Terminal outcome — drop the carried state.
        $fresh = self::get_status($uid);
        if (!empty($fresh) && !empty($fresh['gdrive_state'])) {
            unset($fresh['gdrive_state']);
            self::save_status($uid, $fresh);
        }

        if ($res['status'] === 'complete') {
            return ['success' => true, 'message' => $res['message']];
        }

        return ['success' => false, 'message' => $res['message']];
    }

    /** Set while a caller (the background worker) may run without a deadline. */
    private static bool $unbounded = false;

    public static function set_unbounded(bool $on): void {
        self::$unbounded = $on;
    }

    /**
     * Wall-clock time at which the current request must stop working.
     *
     * Null when nothing is timing us out (WP-Cron, WP-CLI, or the background
     * worker, which has already been told the client is gone). Otherwise a
     * timestamp comfortably inside both PHP's max_execution_time and the
     * typical nginx/Apache gateway timeout, so we return a real JSON response
     * instead of letting the gateway replace it with a 504 HTML page.
     */
    private static function request_deadline(): ?int {
        // Inside a time slice the slice decides, for the background worker
        // too: a host that stops requests after ~30 s stops an "unbounded"
        // Drive upload just the same. At least a few seconds, so every slice
        // moves the upload forward by one chunk.
        if (self::$deadline !== null) {
            return max(time() + 5, (int) floor(self::$deadline));
        }

        if (self::$unbounded) {
            return null;
        }

        if ((defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) {
            return null;
        }

        $limit = (int) ini_get('max_execution_time');

        // 0 / -1 means "no PHP limit", but the GATEWAY still has one and it is
        // the one that produces the 504. Assume a conservative budget.
        $budget = ($limit > 0) ? (int) floor($limit * 0.7) : 45;
        $budget = max(20, min(45, $budget));

        return time() + $budget;
    }

    /**
     * Persist export status using transients (auto-expire, no option-table bloat).
     */
    private static function save_status(string $uid, array $status): void {
        set_transient("sitessaver_export_{$uid}", $status, HOUR_IN_SECONDS);

        // A backup left to run on its own can take longer than an hour on a
        // slow host; keep the pointer to it alive while it is moving.
        if (($status['status'] ?? '') === 'running' && get_transient('sitessaver_active_export_id') === $uid) {
            set_transient('sitessaver_active_export_id', $uid, HOUR_IN_SECONDS);
            // A scheduled backup holds the schedule lock while it moves.
            if (!empty($status['options']['schedule'])) {
                set_transient('sitessaver_schedule_running', 1, 2 * HOUR_IN_SECONDS);
            }
        }
    }

    /** save_status() for the AJAX layer (auto-resume bookkeeping). */
    public static function save_status_public(string $uid, array $status): void {
        self::save_status($uid, $status);
    }

    /**
     * Fetch export status from transient store.
     */
    public static function get_status(string $uid): array {
        // Another request (the browser poll, a newer worker) may have changed
        // the status since this request last read it. Without an external
        // object cache WordPress would answer from its per-request copy, and
        // a worker would never notice it had been replaced.
        if (function_exists('wp_using_ext_object_cache') && !wp_using_ext_object_cache()) {
            wp_cache_delete("_transient_sitessaver_export_{$uid}", 'options');
            wp_cache_delete("_transient_timeout_sitessaver_export_{$uid}", 'options');
        }
        $status = get_transient("sitessaver_export_{$uid}");
        return is_array($status) ? $status : [];
    }

    /**
     * Run a specific step of the export process.
     */
    public static function run_step(string $uid, int $index): array {
        $status = self::get_status($uid);

        if (empty($status) || $status['status'] !== 'running') {
            return ['success' => false, 'message' => __('No active export found for this ID.', 'sitessaver')];
        }

        // Build the table for THIS export's destination — the Drive variant
        // has different weights, so a default table could index a different
        // step than the client is showing.
        $steps = self::get_steps((string) ($status['options']['export_destination'] ?? 'local'));

        if (!isset($steps[$index])) {
            return ['success' => false, 'message' => __('Invalid step index.', 'sitessaver')];
        }

        $step     = $steps[$index];
        $options  = $status['options'];
        $temp_dir = $status['temp_dir'];
        $plan     = is_array($status['plan'] ?? null) ? $status['plan'] : ['type' => 'full'];

        // Long loops inside this step report liveness through tick().
        self::begin_ticks($uid);

        try {
            switch ($step['id']) {
                case 'init':
                    // Already handled by start().
                    break;

                case 'manifest':
                    self::write_manifest($temp_dir, $options, $plan, [], []);
                    break;

                case 'db':
                    if (!empty($options['include_db'])) {
                        $db_file = $temp_dir . '/database.sql';
                        // Heartbeat from inside the dump: a large database can
                        // take minutes, and without this the stall detector
                        // (STALL_SECONDS) reports a healthy export as dead.
                        Database::set_progress(static function (string $table, int $done): void {
                            self::tick('db:' . $table, $done);
                        });
                        try {
                            $cursor = Database::export_resumable(
                                $db_file,
                                self::cursor($uid, 'db'),
                                [self::class, 'out_of_time'],
                                static function (array $c, bool $force) use ($uid): void {
                                    self::checkpoint($uid, 'db', $c, $force);
                                }
                            );
                        } finally {
                            Database::set_progress(null);
                        }
                        if (!empty($cursor['error'])) {
                            throw new \RuntimeException(__('Failed to export database.', 'sitessaver'));
                        }
                        if (empty($cursor['done'])) {
                            self::slice_expired();
                        }
                    }
                    break;

                case 'uploads':
                    if (!empty($options['include_media'])) {
                        // wp_upload_dir()['basedir'], not a hardcoded
                        // WP_CONTENT_DIR . '/uploads'. On single-site the two
                        // are identical. On MULTISITE they are NOT: WordPress
                        // core keeps every subsite's media under a shared
                        // parent (uploads/sites/{blog_id}/...), so copying
                        // the bare uploads root would pull every OTHER
                        // subsite's media into THIS site's backup — a
                        // content-layer version of the same leak A2 fixed
                        // for the database. wp_upload_dir() resolves to the
                        // current site's own subtree automatically (WP core
                        // switches the basedir per blog) for every subsite —
                        // but the MAIN site (blog ID 1) is the one case
                        // where basedir is NOT scoped: it IS the literal
                        // parent of uploads/sites/, so a plain copy still
                        // vacuums up every subsite's media. Exclude that
                        // subfolder explicitly when this is the main site
                        // on a multisite network. Verified against a real
                        // WP multisite install (see PLAN-multisite-
                        // premium-addon.md §7 A3 test notes) — this is not
                        // a documented wp_upload_dir() edge case, it was
                        // found by testing, not by reading core source.
                        $uploads_exclude = (is_multisite() && get_current_blog_id() === 1)
                            ? ['sites', 'sites/*']
                            : [];
                        $uploads_dir = wp_upload_dir()['basedir'];
                        $foreign     = self::foreign_backups_in($uploads_dir);
                        if ($foreign) {
                            Log::info('export_skipped_foreign_backups', 'Left other backup plugins\' archives out of the backup.', ['items' => $foreign]);
                        }
                        $uploads_exclude = array_merge($uploads_exclude, self::FOREIGN_BACKUP_DIRS, self::FOREIGN_BACKUP_FILES);
                        self::copy_area('uploads', $uploads_dir, $temp_dir, $uploads_exclude, $plan);
                    }
                    break;

                case 'plugins':
                    if (!empty($options['include_plugins'])) {
                        // Exclude BOTH the top-level folder AND every file inside it.
                        // Pre-1.1.7 the exclude was `['sitessaver']` — fnmatch does not
                        // treat that as a directory prefix, so every file under
                        // `sitessaver/*` was silently included in the backup. Restores
                        // then overwrote the live plugin with the backup's (older) copy,
                        // silently reverting whatever bugfixes the running plugin had.
                        // We now pass an explicit path-prefix pattern AND rely on the
                        // prefix-aware check added to copy_directory() below.
                        self::copy_area(
                            'plugins',
                            WP_PLUGIN_DIR,
                            $temp_dir,
                            ['sitessaver', 'sitessaver/*'],
                            $plan
                        );
                    }
                    break;

                case 'themes':
                    if (!empty($options['include_themes'])) {
                        self::copy_area('themes', get_theme_root(), $temp_dir, [], $plan);
                    }
                    break;

                case 'other':
                    if (is_dir(WPMU_PLUGIN_DIR)) {
                        self::copy_area('mu-plugins', WPMU_PLUGIN_DIR, $temp_dir, [], $plan);
                    }
                    break;

                case 'zip':
                    // Seal the index: merge the per-area shards, work out what
                    // was deleted since the parent, and rewrite the manifest
                    // now that both are known. Must happen BEFORE the archive
                    // is built so manifest.json and fileindex.json.gz go into
                    // the ZIP.
                    $zcur = self::cursor($uid, 'zip');
                    if (empty($zcur['sealed'])) {
                        self::seal_index($temp_dir, (string) $status['backup_name'], $options, $plan);
                        $zcur = ['sealed' => true, 'w' => []];
                        self::checkpoint($uid, 'zip', $zcur, true);
                    }

                    $zip_path = sitessaver_storage_dir() . '/' . $status['backup_name'];
                    $exclude  = [
                        'sitessaver-backups',
                        'cache',
                        'upgrade',
                        '*.log',
                        '.DS_Store',
                        'Thumbs.db',
                    ];
                    // Built a slice at a time (Zip_Writer), not with
                    // ZipArchive: its close() does all the work in one call
                    // that a 30-second host limit kills every time.
                    $w = Zip_Writer::build(
                        $temp_dir,
                        $zip_path,
                        $exclude,
                        self::zip_work_dir($temp_dir),
                        is_array($zcur['w'] ?? null) ? $zcur['w'] : [],
                        [self::class, 'out_of_time'],
                        static function (array $ws, bool $force) use ($uid): void {
                            self::checkpoint($uid, 'zip', ['sealed' => true, 'w' => $ws], $force);
                        },
                        static function (int $n): void {
                            self::tick('zip', $n);
                        }
                    );
                    if (empty($w['done'])) {
                        self::slice_expired();
                    }
                    if (!empty($w['skipped'])) {
                        Log::warning('export_zip_skipped', sprintf('%d file(s) could not be read and were left out of the ZIP.', (int) $w['skipped']));
                    }
                    self::remove_directory(self::zip_work_dir($temp_dir));
                    break;

                case 'finalize':
                    $zip_path    = sitessaver_storage_dir() . '/' . $status['backup_name'];
                    $destination = $options['export_destination'] ?? 'local';
                    $zip_size    = (int) @filesize($zip_path);

                    // Promote this run's index from temp into the local cache
                    // BEFORE the temp dir is removed, and register the backup
                    // as a chain member. The cache copy deliberately stays on
                    // local disk even for a gdrive-only destination: it is
                    // kilobytes, and without it the next run has no baseline
                    // to diff against and silently degrades to a full backup.
                    //
                    // Guarded by `finalized`: a Drive upload that needs more
                    // than one request re-enters finalize, and registering the
                    // same backup in the chain on every re-entry would corrupt
                    // the chain with duplicate members.
                    if (!empty($options['track_chain']) && empty($status['finalized']) && empty($status['indexed'])) {
                        $sealed = $temp_dir . '/' . Index::ARCHIVE_ENTRY;
                        if (is_readable($sealed)) {
                            $payload = file_get_contents($sealed);
                            $index   = is_string($payload) ? Index::decode($payload) : null;
                            if (is_array($index)) {
                                Index::save($status['backup_name'], $index);
                            }
                        }
                        Index::record($plan, $status['backup_name'], $zip_size);
                        $status['indexed'] = true;
                        self::save_status($uid, $status);
                    }

                    if (empty($status['finalized'])) {
                        // Isolated cleanup — ONLY delete this export's temp dir.
                        // Sliced: tens of thousands of files can take longer
                        // to delete than a request is allowed to live.
                        if (!self::remove_directory($temp_dir, true)) {
                            self::slice_expired();
                        }

                        $status['finalized'] = true;
                        self::save_status($uid, $status);
                    }

                    $result = [
                        'success'     => true,
                        'file'        => $status['backup_name'],
                        'path'        => $zip_path,
                        'size'        => sitessaver_format_size($zip_size),
                        'destination' => $destination,
                        'backup_type' => (string) ($plan['type'] ?? 'full'),
                        'chain_seq'   => (int) ($plan['seq'] ?? 0),
                    ];

                    // Upload to Google Drive if requested.
                    if (in_array($destination, ['gdrive', 'both'], true)) {
                        $job_id        = self::gdrive_job_id($uid);
                        $gdrive_result = self::upload_to_drive($uid, $status, $zip_path, $job_id);

                        // The slice ran out of time: the export is NOT finished
                        // and MUST NOT be marked completed. Report progress and
                        // leave step_index on finalize so the next request
                        // resumes this same upload from its byte offset.
                        if (($gdrive_result['pending'] ?? false) === true) {
                            return [
                                'success'  => true,
                                'step'     => 'finalize',
                                'pending'  => true,
                                'progress' => (int) ($gdrive_result['progress'] ?? 0),
                            ];
                        }

                        $result['gdrive'] = $gdrive_result;

                        // If destination is gdrive-only and upload failed, surface the error.
                        if ($destination === 'gdrive' && empty($gdrive_result['success'])) {
                            throw new \RuntimeException($gdrive_result['message'] ?? __('Failed to upload to Google Drive.', 'sitessaver'));
                        }

                        if (!empty($gdrive_result['success'])) {
                            $result['gdrive_folder_url'] = GDrive::get_folder_url();
                        }

                        // gdrive-only: remove local copy after successful upload.
                        if ($destination === 'gdrive' && !empty($gdrive_result['success'])) {
                            @unlink($zip_path);
                            $result['file'] = '';
                            $result['path'] = '';
                        }
                    }

                    do_action('sitessaver_export_complete', $status['backup_name'], $zip_path);

                    $status['status'] = 'completed';
                    $status['result'] = $result;
                    self::save_status($uid, $status);
                    delete_transient('sitessaver_active_export_id');
                    Background::notify($status, true);
                    if (!empty($status['options']['schedule'])) {
                        do_action('sitessaver_scheduled_export_finished', $status, true);
                    }

                    return $result;
            }

            // Re-read: checkpoints wrote cursors meanwhile. An empty status
            // means the export was cancelled while this step ran — do not
            // bring it back to life by saving it.
            $status = self::get_status($uid);
            if (empty($status)) {
                return ['success' => false, 'message' => __('Export cancelled.', 'sitessaver')];
            }
            self::clear_cursor($status, (string) $step['id']);
            $status['step_index']  = $index + 1;
            $status['last_update'] = time();
            self::save_status($uid, $status);

            return ['success' => true, 'step' => $step['id']];

        } catch (\Throwable $e) {
            if ($e->getCode() === self::SUPERSEDED) {
                throw $e;
            }

            if ($e->getCode() === self::SLICE) {
                $fresh = self::get_status($uid);
                if (!empty($fresh)) {
                    $fresh['slices']      = (int) ($fresh['slices'] ?? 0) + 1;
                    $fresh['last_update'] = time();
                    self::save_status($uid, $fresh);
                }
                return ['success' => true, 'step' => $step['id'] ?? '', 'pending' => true];
            }

            $ref = Log::error('export_failed', $e->getMessage(), [
                'step'   => $step['id'] ?? $index,
                'at'     => basename($e->getFile()) . ':' . $e->getLine(),
                'memory' => size_format(memory_get_peak_usage(true)),
            ]);

            $status['status']  = 'error';
            $status['message'] = $e->getMessage();
            $status['ref']     = $ref;
            self::save_status($uid, $status);
            delete_transient('sitessaver_active_export_id');
            self::discard_work($status);
            Background::notify($status, false);
            if (!empty($status['options']['schedule'])) {
                do_action('sitessaver_scheduled_export_finished', $status, false);
            }

            return ['success' => false, 'message' => $e->getMessage(), 'ref' => $ref, 'error' => Errors::payload('export_failed', $e->getMessage(), $ref)];
        }
    }

    /**
     * Run a full site export (Legacy/Cron wrapper).
     */
    public static function run(array $options = []): array {
        $status = self::start($options);
        $steps  = self::get_steps((string) ($options['export_destination'] ?? 'local'));
        $uid    = $status['uid'];

        foreach (array_keys($steps) as $i) {
            do {
                $res = self::run_step($uid, $i);
                if (!$res['success']) {
                    return $res;
                }
            } while (!empty($res['pending']));
        }

        $status = self::get_status($uid);
        return $status['status'] === 'completed' ? $status['result'] : ['success' => false, 'message' => $status['message'] ?? 'Unknown error'];
    }


    /**
     * Write package manifest with site metadata.
     *
     * Called twice: once early (so a crashed run still leaves something
     * readable) and once from seal_index() with the chain facts filled in.
     *
     * @param array<string, mixed> $plan
     * @param list<string>         $deleted
     * @param list<string>         $members
     */
    private static function write_manifest(string $dir, array $options, array $plan = [], array $deleted = [], array $members = []): void {
        $manifest = [
            'plugin'        => 'SitesSaver',
            'version'       => SITESSAVER_VERSION,
            'wp_version'    => get_bloginfo('version'),
            'php_version'   => PHP_VERSION,
            'site_url'      => site_url(),
            'home_url'      => home_url(),
            'multisite'     => is_multisite(),
            'db_prefix'     => $GLOBALS['wpdb']->prefix,
            'created_at'    => gmdate('Y-m-d H:i:s'),
            'charset'       => get_bloginfo('charset'),
            'active_theme'  => get_stylesheet(),
            'active_plugins'=> get_option('active_plugins', []),
            'options'       => $options,
        ];

        // Chain facts are added ONLY for a chain-tracked run. A manual export
        // therefore writes byte-for-byte the manifest it always did, and an
        // older SitesSaver reading it sees nothing new. Readers default
        // backup_type to 'full', so an absent block means "restores alone".
        if (!empty($options['track_chain'])) {
            $manifest['backup_type']  = (string) ($plan['type'] ?? 'full');
            $manifest['chain_id']     = (string) ($plan['chain_id'] ?? '');
            $manifest['chain_seq']    = (int) ($plan['seq'] ?? 0);
            $manifest['chain_parent'] = (string) ($plan['parent'] ?? '');
            $manifest['chain_full']   = (string) ($plan['full'] ?? '');

            // The ordered restore set, written INTO the archive so an
            // incremental is self-describing. Restore must not depend on a
            // local option that a reinstall, a migration, or a database
            // restore could have wiped.
            $manifest['chain_members'] = $members;

            // Paths that existed in the parent backup and are gone now. A
            // ZIP cannot represent absence, so deletions travel here.
            $manifest['deleted'] = $deleted;
        }

        file_put_contents(
            $dir . '/manifest.json',
            wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Copy one backup area, indexing it and — for an incremental run —
     * skipping files that have not changed since the parent backup.
     *
     * Each area writes its own index shard to temp rather than accumulating
     * into a static or an option. Steps run as separate HTTP requests, so
     * anything held in memory between them is gone; and a shard per area
     * means a failed run leaves no half-merged global state behind.
     *
     * @param array<string, mixed> $plan
     */
    private static function copy_area(string $area, string $source, string $temp_dir, array $exclude, array $plan): void {
        $prefixes = Index::area_prefixes();
        $prefix   = $prefixes[$area] ?? ('wp-content/' . $area . '/');
        $dest     = $temp_dir . '/' . rtrim($prefix, '/');

        $incremental = ($plan['type'] ?? 'full') === 'incremental';
        $detection   = ($plan['detection'] ?? 'fast') === 'thorough' ? 'thorough' : 'fast';

        $parent_index = [];
        if ($incremental) {
            $loaded = Index::load((string) ($plan['parent'] ?? ''));
            if (!is_array($loaded)) {
                // plan() already verified the parent index exists. If it has
                // vanished between then and now, copying everything is the
                // safe answer: a bigger backup, not a broken one.
                $incremental = false;
            } else {
                $parent_index = $loaded;
            }
        }

        $index = [];

        self::copy_directory(
            $source,
            $dest,
            $exclude,
            static function (string $relative, string $abs_path) use ($prefix, $incremental, $parent_index, $detection, &$index): bool {
                $key         = $prefix . $relative;
                $entry       = Index::entry_for($abs_path, $detection);
                $index[$key] = $entry;

                if (!$incremental) {
                    return true;
                }

                return Index::is_changed($key, $entry, $parent_index, $detection);
            },
            !$incremental
        );

        self::write_index_shard($temp_dir, $area, $index);
    }

    /**
     * Persist one area's index shard into the export temp directory.
     *
     * @param array<string, mixed> $index
     */
    private static function write_index_shard(string $temp_dir, string $area, array $index): void {
        $dir = $temp_dir . '/.ssindex';

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $json = wp_json_encode($index, JSON_UNESCAPED_SLASHES);

        if (is_string($json)) {
            file_put_contents($dir . '/' . $area . '.json', $json);
        }
    }

    /**
     * Merge the per-area shards into the final index, compute deletions,
     * and rewrite the manifest with the complete chain picture.
     *
     * @param array<string, mixed> $plan
     */
    private static function seal_index(string $temp_dir, string $backup_name, array $options, array $plan): void {
        if (empty($options['track_chain'])) {
            // No chain tracking: leave the shards out of the archive and keep
            // the manifest exactly as a pre-1.4.0 full backup's.
            self::remove_directory($temp_dir . '/.ssindex');
            return;
        }

        $index    = [];
        $prefixes = [];
        $areas    = Index::area_prefixes();

        foreach ($areas as $area => $prefix) {
            $shard = $temp_dir . '/.ssindex/' . $area . '.json';

            if (!is_readable($shard)) {
                // Area was switched off this run. It contributes no index
                // entries AND no deletions — an area nobody looked at cannot
                // have lost files.
                continue;
            }

            $raw    = file_get_contents($shard);
            $parsed = is_string($raw) ? json_decode($raw, true) : null;

            if (is_array($parsed)) {
                $index      = $index + $parsed;
                $prefixes[] = $prefix;
            }
        }

        $deleted = [];
        if (($plan['type'] ?? 'full') === 'incremental') {
            $parent = Index::load((string) ($plan['parent'] ?? ''));
            if (is_array($parent)) {
                $deleted = Index::deleted_paths($parent, $index, $prefixes);
            }
        }

        // The restore set as it will stand once this backup is recorded:
        // the chain so far, plus this backup at the end.
        $members = [];
        if (($plan['type'] ?? 'full') === 'incremental') {
            $chain   = Index::chain((string) ($plan['chain_id'] ?? ''));
            $members = is_array($chain['members'] ?? null) ? array_values($chain['members']) : [];
        }
        $members[] = $backup_name;

        self::write_manifest($temp_dir, $options, $plan, $deleted, $members);

        // The index also travels inside the archive, so a backup carried to
        // another server can still serve as a baseline there.
        $json = wp_json_encode($index, JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            $gz = gzencode($json, 6);
            if ($gz !== false) {
                file_put_contents($temp_dir . '/' . Index::ARCHIVE_ENTRY, $gz);
            }
        }

        // Shards are scaffolding, not payload.
        self::remove_directory($temp_dir . '/.ssindex');
    }

    /**
     * Recursively copy a directory, respecting exclude patterns.
     *
     * @param callable(string, string): bool|null $filter Receives the path
     *        relative to $source and its absolute path; returns false to skip
     *        copying the file. Called for every non-excluded FILE, including
     *        ones it then skips, so an incremental run still indexes the
     *        files it chose not to archive.
     * @param bool $mirror_dirs Recreate every source directory in the
     *        destination, empty ones included. False for incremental runs,
     *        where only the folders holding archived files are wanted.
     */
    private static function copy_directory(string $source, string $dest, array $exclude = [], ?callable $filter = null, bool $mirror_dirs = true): void {
        if (!is_dir($source)) {
            return;
        }

        // Always exclude our own backups and temp files.
        $exclude = array_merge($exclude, [
            'sitessaver-backups',
            'cache',
            'upgrade',
            '*.log',
            '.DS_Store',
            'Thumbs.db',
        ]);

        wp_mkdir_p($dest);

        // Decide once per entry whether the exclude list skips it, and whether
        // a skipped DIRECTORY can be pruned (not descended into at all).
        // Pruning only happens when every descendant would also be skipped
        // under the historic rules (first-segment / prefix match, or a
        // trailing-* pattern that matches the folder itself); a folder that
        // is merely skipped by basename (e.g. a nested `cache` dir inside a
        // plugin) is still walked so its files keep being copied exactly as
        // before. Before pruning, a huge excluded tree was walked file by file
        // with no liveness tick, which alone could trip the stall detector.
        $match = static function (string $relative) use ($exclude): array {
            $first_segment = strtok($relative, '/');
            foreach ($exclude as $pattern) {
                $base_pattern = rtrim($pattern, '/*');
                $by_prefix    = $first_segment === $base_pattern || str_starts_with($relative, $base_pattern . '/');
                $by_glob      = fnmatch($pattern, $relative);
                if ($by_prefix || $by_glob || fnmatch($pattern, basename($relative))) {
                    return [true, $by_prefix || ($by_glob && str_ends_with($pattern, '*'))];
                }
            }
            return [false, false];
        };

        $source_norm = str_replace('\\', '/', $source);
        $relative_of = static function (\SplFileInfo $file) use ($source_norm): string {
            $relative = str_replace('\\', '/', $file->getPathname());
            if (str_starts_with($relative, $source_norm)) {
                $relative = substr($relative, strlen($source_norm));
            }
            return ltrim($relative, '/');
        };

        $copied   = 0;
        $iterator = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            static function (\SplFileInfo $file) use ($match, $relative_of, &$copied): bool {
                // Liveness while walking, including long runs of entries
                // that are skipped and never reach the copy below.
                self::tick('copy', $copied);
                if (!$file->isDir()) {
                    return true;
                }
                [, $prune] = $match($relative_of($file));
                return !$prune;
            }
        );

        $files = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::SELF_FIRST);

        $skipped = [];

        foreach ($files as $file) {
            $relative  = $relative_of($file);
            $dest_path = $dest . '/' . $relative;

            [$skip] = $match($relative);
            if ($skip) {
                continue;
            }

            if ($file->isDir()) {
                // On an incremental run the destination tree is built on
                // demand around the files actually archived. Mirroring every
                // directory up front would write a full skeleton of empty
                // folders into a ZIP that is meant to hold only what changed.
                // A full backup still mirrors them, so an intentionally empty
                // directory survives a restore exactly as it always has.
                if ($mirror_dirs) {
                    wp_mkdir_p($dest_path);
                }
                continue;
            }

            // The filter both indexes the file and decides whether it
            // needs archiving. It is called for every file that survived
            // the exclude list, INCLUDING ones it then declines — an
            // incremental backup must still index a file it skipped, or
            // the next run would see it as deleted.
            if ($filter !== null && !$filter($relative, $file->getPathname())) {
                continue;
            }

            $parent = dirname($dest_path);
            if (!is_dir($parent)) {
                wp_mkdir_p($parent);
            }

            $size = (int) $file->getSize();

            // A resumed export reuses its temp folder: a file already copied
            // completely by the previous worker is not copied again. Partial
            // copies never sit under the final name (see copy_file()).
            if (is_file($dest_path) && (int) @filesize($dest_path) === $size) {
                self::tick('copy', ++$copied);
                continue;
            }

            $result = self::copy_file($file->getPathname(), $dest_path, $relative, $size);
            if ($result === 'paused') {
                self::slice_expired();
            }
            if ($result === 'unreadable') {
                // Same as before: a file PHP cannot read is left out rather
                // than failing the whole backup — but it is now on record.
                if (count($skipped) < 20) {
                    $skipped[] = $relative;
                }
            } elseif ($result === 'write_failed') {
                // Running out of disk is fatal: every following file would
                // fail too and the backup would be silently incomplete. Any
                // other write failure (odd file name, permissions) is left
                // out and logged, as copy() failures always were.
                $free = @disk_free_space($dest);
                if ($free !== false && (float) $free < max(64 * 1024 * 1024, $size)) {
                    throw new \RuntimeException(sprintf(
                        'No space left on device while copying %s (%s). Free disk space: %s.',
                        $relative,
                        size_format($size),
                        size_format((int) $free)
                    ));
                }
                if (count($skipped) < 20) {
                    $skipped[] = $relative . ' (write failed)';
                }
            }

            self::tick('copy', ++$copied);

            // Hand over between files. Files finished in this slice are
            // skipped by size on the next pass, so nothing is copied twice.
            if (self::out_of_time()) {
                if ($skipped) {
                    Log::warning('export_unreadable_files', 'Some files could not be read and were left out of the backup.', ['files' => $skipped]);
                }
                self::slice_expired();
            }
        }

        if ($skipped) {
            Log::warning('export_unreadable_files', 'Some files could not be read and were left out of the backup.', ['files' => $skipped]);
        }
    }

    /**
     * Copy one file, in pieces with liveness ticks when it is large.
     *
     * Large files go to `<dest>.part` and are renamed when complete, so a
     * worker killed mid-file never leaves a truncated file under the real
     * name for a resumed run to mistake as done.
     *
     * @return string 'ok' | 'unreadable' | 'write_failed' | 'paused' (slice over; .part kept)
     */
    public static function copy_file(string $src, string $dst, string $relative = '', int $size = -1): string {
        if ($size < 0) {
            $size = (int) @filesize($src);
        }

        if ($size < self::COPY_STREAM_MIN) {
            if (@copy($src, $dst)) {
                return 'ok';
            }
            return is_readable($src) ? 'write_failed' : 'unreadable';
        }

        $in = @fopen($src, 'rb');
        if (!$in) {
            return 'unreadable';
        }

        // A piece already copied by an earlier slice is kept: continue from
        // its end instead of starting the file again.
        $part   = $dst . '.part';
        $have   = is_file($part) ? (int) @filesize($part) : 0;
        $have   = $have <= $size ? $have : 0;
        $out    = @fopen($part, $have > 0 ? 'ab' : 'wb');
        if (!$out) {
            fclose($in);
            return 'write_failed';
        }
        if ($have > 0 && fseek($in, $have) !== 0) {
            fclose($in);
            fclose($out);
            @unlink($part);
            return 'write_failed';
        }

        self::$current_item = ['file' => $relative, 'size' => $size, 'copied' => $have];
        $copied = $have;
        $ok     = true;
        $paused = false;

        try {
            // Loop on the known size, not feof(): at end of file PHP's
            // stream_copy_to_stream() returns false before feof() turns true.
            while ($copied < $size) {
                $n = @stream_copy_to_stream($in, $out, min(self::COPY_CHUNK, $size - $copied));
                if ($n === false || $n === 0) {
                    $ok = false;
                    break;
                }
                $copied += $n;
                self::$current_item['copied'] = $copied;
                self::tick('copy-large', $copied);

                if ($copied < $size && self::out_of_time()) {
                    $paused = true;
                    break;
                }
            }
        } finally {
            fclose($in);
            fclose($out);
            self::$current_item = [];
        }

        if ($paused) {
            return 'paused';
        }

        if (!$ok || $copied !== $size || !@rename($part, $dst)) {
            @unlink($part);
            return 'write_failed';
        }

        return 'ok';
    }

    /**
     * Which known foreign-backup folders/archives sit at the top of uploads.
     *
     * @return array<int, string> e.g. ["ai1wm-backups (2.1 GB)"]
     */
    private static function foreign_backups_in(string $uploads_dir): array {
        $found = [];
        $items = @scandir($uploads_dir);
        if (!is_array($items)) {
            return $found;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            foreach (array_merge(self::FOREIGN_BACKUP_DIRS, self::FOREIGN_BACKUP_FILES) as $pattern) {
                if (fnmatch($pattern, $item)) {
                    $path    = $uploads_dir . '/' . $item;
                    $found[] = is_file($path) ? $item . ' (' . size_format((int) @filesize($path)) . ')' : $item . '/';
                    break;
                }
            }
        }
        return $found;
    }

    /**
     * Recursively remove a directory.
     */
    private static function remove_directory(string $dir, bool $sliced = false): bool {
        if (!is_dir($dir)) {
            return true;
        }

        $iterator = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files    = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::CHILD_FIRST);

        $n = 0;
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
            if ($sliced && (++$n % 200) === 0) {
                self::tick('cleanup', $n);
                if (self::out_of_time()) {
                    return false;
                }
            }
        }

        @rmdir($dir);
        return true;
    }
}


