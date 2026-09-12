<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Scheduled backups.
 *
 * Two ways in:
 *
 *   1. WP-Cron — one `sitessaver_scheduled_backup` event per selected
 *      frequency. Fine on sites with steady traffic, since WP-Cron only
 *      fires when someone loads a page.
 *
 *   2. An external trigger URL — for hosts where WP-Cron is disabled
 *      (`DISABLE_WP_CRON`) or where traffic is too low to fire it reliably.
 *      A real server cron (or an uptime pinger) hits the URL and any backup
 *      that is due runs inside that request. See handle_external_trigger().
 *
 * Both paths converge on run_scheduled_backup(), so behaviour, retention, and
 * notifications stay identical no matter what started the run.
 *
 * Several frequencies can be active at once — Daily plus Monthly, say, to keep
 * a rolling week of recent restore points alongside a long-term archive. Each
 * frequency is scheduled and rate-limited independently, so they never
 * collapse into one another.
 */
final class Schedule {

    private static ?self $instance = null;

    /** Option holding the shared secret for the external trigger URL. */
    public const TRIGGER_KEY_OPTION = 'sitessaver_cron_key';

    /** Query var that carries the secret on the external trigger URL. */
    public const TRIGGER_QUERY_VAR = 'sitessaver_run_backup';

    /** Map of frequency key => unix timestamp of the last run start. */
    public const LAST_RUN_OPTION = 'sitessaver_schedule_last_run';

    /** Guards against two overlapping backups. */
    private const LOCK_TRANSIENT = 'sitessaver_schedule_running';

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function init(): void {
        // The frequency that triggered the run arrives as the first cron arg.
        // Older installs scheduled this hook with no args, so the parameter
        // has to stay optional or those pending events fatal on fire.
        add_action('sitessaver_scheduled_backup', [$this, 'run_scheduled_backup'], 10, 1);
        add_filter('cron_schedules', [$this, 'add_custom_schedules']);

        // Must run early and on every request type — a server cron hitting the
        // trigger URL is an anonymous front-end request, not an admin one.
        add_action('init', [$this, 'handle_external_trigger'], 5);
    }

    /**
     * The frequencies SitesSaver offers, in ascending order.
     *
     * Single source of truth: the cron_schedules filter, the AJAX validator,
     * the settings form, and the staleness check all read this. They used to
     * keep separate copies of the interval table, so adding a frequency meant
     * editing four places and any miss produced a schedule that saved but
     * never fired.
     *
     * @return array<string, array{label: string, interval: int, description: string}>
     */
    public static function frequencies(): array {
        return [
            'hourly' => [
                'label'       => __('Every Hour', 'sitessaver'),
                'interval'    => HOUR_IN_SECONDS,
                'description' => __('Heavy on disk and CPU. Best for busy stores.', 'sitessaver'),
            ],
            'twicedaily' => [
                'label'       => __('Twice Daily', 'sitessaver'),
                'interval'    => 12 * HOUR_IN_SECONDS,
                'description' => __('Every 12 hours.', 'sitessaver'),
            ],
            'daily' => [
                'label'       => __('Daily', 'sitessaver'),
                'interval'    => DAY_IN_SECONDS,
                'description' => __('A good default for most sites.', 'sitessaver'),
            ],
            'weekly' => [
                'label'       => __('Weekly', 'sitessaver'),
                'interval'    => WEEK_IN_SECONDS,
                'description' => __('Every 7 days.', 'sitessaver'),
            ],
            'sitessaver_monthly' => [
                'label'       => __('Monthly', 'sitessaver'),
                'interval'    => 30 * DAY_IN_SECONDS,
                'description' => __('Every 30 days. Good as a long-term archive.', 'sitessaver'),
            ],
        ];
    }

    /**
     * Resolve a stored frequency key to one we can actually schedule.
     *
     * Accepts 'monthly' as an alias for 'sitessaver_monthly'. Other plugins
     * (and some WordPress builds) register their own 'monthly' schedule with a
     * different interval; using a namespaced key means whichever registers
     * first cannot silently change our cadence.
     */
    public static function normalize_frequency(string $frequency): string {
        if ($frequency === 'monthly') {
            return 'sitessaver_monthly';
        }

        return isset(self::frequencies()[$frequency]) ? $frequency : 'daily';
    }

    /**
     * Seconds between runs for a given frequency.
     */
    public static function interval_for(string $frequency): int {
        $frequency = self::normalize_frequency($frequency);

        return self::frequencies()[$frequency]['interval'] ?? DAY_IN_SECONDS;
    }

    /**
     * The selected frequencies, normalised, de-duplicated and ordered.
     *
     * Reads the current `frequencies` array and falls back to the legacy
     * single `frequency` string so schedules saved before multi-select keep
     * working across the upgrade without a migration step.
     *
     * @param array<string, mixed>|null $settings
     * @return list<string>
     */
    public static function selected_frequencies(?array $settings = null): array {
        $settings ??= get_option('sitessaver_schedule', []);
        $settings   = is_array($settings) ? $settings : [];

        $raw = $settings['frequencies'] ?? null;

        if (!is_array($raw) || $raw === []) {
            // Legacy single-value form.
            $legacy = $settings['frequency'] ?? null;
            $raw    = is_string($legacy) && $legacy !== '' ? [$legacy] : [];
        }

        $out = [];
        foreach ($raw as $key) {
            if (!is_string($key)) {
                continue;
            }
            $normalized = self::normalize_frequency($key);
            $out[$normalized] = true;
        }

        if ($out === []) {
            $out = ['daily' => true];
        }

        // Return in the canonical order the UI lists them, not the order they
        // happened to be stored in.
        return array_values(array_intersect(array_keys(self::frequencies()), array_keys($out)));
    }

    /**
     * Register the schedules WordPress does not ship with.
     *
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public function add_custom_schedules(array $schedules): array {
        foreach (self::frequencies() as $key => $meta) {
            // Never clobber a core schedule (hourly, twicedaily, daily) or one
            // another plugin registered first — overriding those would change
            // the cadence of unrelated events.
            if (isset($schedules[$key])) {
                continue;
            }

            $schedules[$key] = [
                'interval' => $meta['interval'],
                'display'  => $meta['label'],
            ];
        }

        return $schedules;
    }

    // ------------------------------------------------------------------
    // Cron event bookkeeping
    // ------------------------------------------------------------------

    /**
     * Rebuild the WP-Cron events so they match the saved selection exactly.
     *
     * Every event carries its frequency as an argument, which is what lets
     * several of them coexist under one hook — wp_next_scheduled() and
     * wp_unschedule_event() both key on the args, so 'daily' and
     * 'sitessaver_monthly' are distinct events rather than one overwriting
     * the other.
     *
     * @param list<string> $frequencies
     */
    public static function sync_cron_events(array $frequencies, bool $enabled): void {
        // Clear everything first, including any legacy no-arg event left by a
        // version that only supported a single frequency.
        wp_clear_scheduled_hook('sitessaver_scheduled_backup');
        foreach (array_keys(self::frequencies()) as $key) {
            wp_clear_scheduled_hook('sitessaver_scheduled_backup', [$key]);
        }

        if (!$enabled) {
            return;
        }

        foreach ($frequencies as $frequency) {
            // Delay the first run by one full interval so saving the schedule
            // does NOT immediately trigger a backup on the next page view.
            $first_run = time() + self::interval_for($frequency);

            wp_schedule_event($first_run, $frequency, 'sitessaver_scheduled_backup', [$frequency]);
        }
    }

    /**
     * Soonest upcoming run across all active frequencies, or 0 if none.
     *
     * @param list<string>|null $frequencies
     */
    public static function next_scheduled_run(?array $frequencies = null): int {
        $frequencies ??= self::selected_frequencies();

        $times = [];
        foreach ($frequencies as $frequency) {
            $ts = wp_next_scheduled('sitessaver_scheduled_backup', [$frequency]);
            if ($ts) {
                $times[] = (int) $ts;
            }
        }

        // Fall back to a legacy no-arg event so the "next run" badge does not
        // vanish for a site that has not re-saved its schedule yet.
        $legacy = wp_next_scheduled('sitessaver_scheduled_backup');
        if ($legacy) {
            $times[] = (int) $legacy;
        }

        return $times === [] ? 0 : min($times);
    }

    // ------------------------------------------------------------------
    // Last-run tracking (per frequency)
    // ------------------------------------------------------------------

    /**
     * @return array<string, int>
     */
    public static function last_runs(): array {
        $stored = get_option(self::LAST_RUN_OPTION, []);

        // Before multi-frequency this option held a bare timestamp. Treat that
        // as the last run of every frequency, which is the conservative
        // reading: it delays the next backup rather than firing a surprise one
        // the moment the plugin updates.
        if (is_numeric($stored)) {
            $ts = (int) $stored;
            return array_fill_keys(array_keys(self::frequencies()), $ts);
        }

        return is_array($stored) ? array_map('intval', $stored) : [];
    }

    public static function last_run_for(string $frequency): int {
        return self::last_runs()[self::normalize_frequency($frequency)] ?? 0;
    }

    private static function record_run(string $frequency, int $timestamp): void {
        $runs = self::last_runs();
        $runs[self::normalize_frequency($frequency)] = $timestamp;

        update_option(self::LAST_RUN_OPTION, $runs, false);
    }

    /**
     * Seed every selected frequency's last-run marker.
     *
     * Called when the schedule is saved so the external trigger endpoint
     * agrees with WP-Cron about when the first backup is owed — otherwise
     * "never run before" reads as due and a server cron fires a backup the
     * instant the settings are saved.
     *
     * @param list<string> $frequencies
     */
    public static function seed_last_runs(array $frequencies): void {
        $runs = self::last_runs();
        $now  = time();

        foreach ($frequencies as $frequency) {
            $runs[$frequency] = $now;
        }

        update_option(self::LAST_RUN_OPTION, $runs, false);
    }

    /**
     * Which selected frequencies are currently owed a backup?
     *
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    public static function due_frequencies(array $settings): array {
        $now = time();
        $due = [];

        foreach (self::selected_frequencies($settings) as $frequency) {
            $last = self::last_run_for($frequency);

            if ($last <= 0 || $now >= $last + self::interval_for($frequency)) {
                $due[] = $frequency;
            }
        }

        return $due;
    }

    /**
     * Soonest moment any selected frequency becomes due.
     *
     * @param array<string, mixed> $settings
     */
    public static function next_due_timestamp(array $settings): int {
        $candidates = [];

        foreach (self::selected_frequencies($settings) as $frequency) {
            $last = self::last_run_for($frequency);

            if ($last <= 0) {
                return 0; // Never run — due right now.
            }

            $candidates[] = $last + self::interval_for($frequency);
        }

        return $candidates === [] ? 0 : min($candidates);
    }

    // ------------------------------------------------------------------
    // External trigger (server cron / uptime pinger)
    // ------------------------------------------------------------------

    /**
     * Secret key for the trigger URL, generated on first use.
     */
    public static function trigger_key(): string {
        $key = (string) get_option(self::TRIGGER_KEY_OPTION, '');

        if ($key === '') {
            $key = wp_generate_password(32, false);
            update_option(self::TRIGGER_KEY_OPTION, $key, false);
        }

        return $key;
    }

    /**
     * Throw away the current key and issue a new one, invalidating any URL
     * that leaked (e.g. pasted into a support ticket).
     */
    public static function regenerate_trigger_key(): string {
        delete_option(self::TRIGGER_KEY_OPTION);

        return self::trigger_key();
    }

    /**
     * The URL a server cron should request.
     */
    public static function trigger_url(): string {
        return add_query_arg(
            self::TRIGGER_QUERY_VAR,
            self::trigger_key(),
            home_url('/')
        );
    }

    /**
     * Handle an external trigger request.
     *
     * Responds in plain text and exits, so cron mailers and uptime monitors
     * get a short, greppable line instead of a rendered page.
     */
    public function handle_external_trigger(): void {
        if (!isset($_GET[self::TRIGGER_QUERY_VAR])) {
            return;
        }

        $supplied = sanitize_text_field(wp_unslash((string) $_GET[self::TRIGGER_QUERY_VAR]));
        $expected = (string) get_option(self::TRIGGER_KEY_OPTION, '');

        // Compare in constant time, and refuse when no key has been generated
        // yet so an empty option can never be matched by an empty parameter.
        if ($expected === '' || !hash_equals($expected, $supplied)) {
            self::respond(403, 'sitessaver: invalid key');
        }

        $settings = get_option('sitessaver_schedule', []);
        $settings = is_array($settings) ? $settings : [];

        if (empty($settings['enabled'])) {
            self::respond(200, 'sitessaver: scheduled backups are disabled, nothing to do');
        }

        // `force=1` runs regardless of when the last backup happened. Without
        // it the endpoint is safe to call more often than the chosen
        // frequency — a cron running every 5 minutes will still only produce
        // one daily backup, which is exactly what you want when the server
        // cron interval and the plugin frequency are configured separately.
        $force = isset($_GET['force']) && $_GET['force'] === '1';

        if ($force) {
            // Attribute a forced run to the shortest selected frequency, so it
            // resets the cadence that would have come due soonest.
            $selected = self::selected_frequencies($settings);
            $due      = $selected === [] ? [] : [reset($selected)];
        } else {
            $due = self::due_frequencies($settings);
        }

        if ($due === []) {
            $next = self::next_due_timestamp($settings);
            self::respond(200, sprintf(
                'sitessaver: not due yet, next run in %s',
                human_time_diff(time(), $next)
            ));
        }

        // Long-running work; the caller is a cron, not a browser.
        @set_time_limit(0);
        ignore_user_abort(true);

        // Even when several frequencies come due in the same request, produce
        // ONE backup and credit it to all of them. Two identical archives
        // written seconds apart would waste disk and push older restore points
        // out of the retention window for no benefit.
        $result = $this->run_scheduled_backup($due[0], $due);

        if ($result === null) {
            self::respond(409, 'sitessaver: another backup is already running');
        }

        $label = implode(',', $due);

        if (!empty($result['success'])) {
            self::respond(200, sprintf(
                'sitessaver: backup completed for [%s] (%s, %s)',
                $label,
                (string) ($result['file'] ?? 'unknown'),
                (string) ($result['size'] ?? 'unknown size')
            ));
        }

        self::respond(500, sprintf(
            'sitessaver: backup failed for [%s] — %s',
            $label,
            (string) ($result['message'] ?? 'unknown error')
        ));
    }

    /**
     * Emit a plain-text response and stop.
     */
    private static function respond(int $status, string $message): void {
        if (!headers_sent()) {
            status_header($status);
            header('Content-Type: text/plain; charset=utf-8');
            // Never let a proxy or CDN cache a trigger response.
            nocache_headers();
        }

        echo $message . "\n";
        exit;
    }

    // ------------------------------------------------------------------
    // The backup run itself
    // ------------------------------------------------------------------

    /**
     * Execute a scheduled backup.
     *
     * @param string            $frequency The frequency that triggered this run.
     * @param list<string>|null $credit    Frequencies whose last-run marker this
     *                                     run should reset. Defaults to just
     *                                     $frequency.
     *
     * @return array<string, mixed>|null The export result, or null when the
     *                                   run was skipped (disabled or locked).
     */
    public function run_scheduled_backup(string $frequency = '', ?array $credit = null): ?array {
        $settings = get_option('sitessaver_schedule', []);
        $settings = is_array($settings) ? $settings : [];

        if (empty($settings['enabled'])) {
            return null;
        }

        $frequency = $frequency !== ''
            ? self::normalize_frequency($frequency)
            : (self::selected_frequencies($settings)[0] ?? 'daily');

        $credit ??= [$frequency];

        // A WP-Cron firing and a server cron hitting the trigger URL can
        // collide, and two concurrent exports would fight over the same temp
        // directory. The lock expires on its own so a fatal error mid-backup
        // cannot wedge the schedule permanently.
        if (get_transient(self::LOCK_TRANSIENT)) {
            return null;
        }
        set_transient(self::LOCK_TRANSIENT, 1, 2 * HOUR_IN_SECONDS);

        // Record the start, not the finish: a long backup should not shorten
        // the gap before the next one is considered due.
        $started = time();
        foreach ($credit as $key) {
            self::record_run($key, $started);
        }

        try {
            $result = $this->perform_backup($settings, $frequency);
        } finally {
            delete_transient(self::LOCK_TRANSIENT);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function perform_backup(array $settings, string $frequency): array {
        // 1. Run Export.
        $result = Export::run([
            'include_db'      => $settings['include_db'] ?? true,
            'include_media'   => $settings['include_media'] ?? true,
            'include_plugins' => $settings['include_plugins'] ?? true,
            'include_themes'  => $settings['include_themes'] ?? true,
        ]);

        if (!$result['success']) {
            $this->finalize_backup($result, $settings, $frequency);
            return $result;
        }

        $file_uploaded = false;

        // 2. Handle Google Drive Storage.
        if (!empty($settings['storage_gdrive'])) {
            $up_res = GDrive::upload($result['path'], $result['file']);
            if ($up_res['success']) {
                $file_uploaded = true;
                $result['gdrive_uploaded']   = true;
                $result['gdrive_folder_url'] = GDrive::get_folder_url();
            } else {
                $result['gdrive_uploaded'] = false;
                $result['gdrive_error']    = $up_res['message'];
                $result['message'] .= ' (GDrive Upload Failed: ' . $up_res['message'] . ')';
            }
        }

        // 3. Handle Local Storage.
        if (empty($settings['storage_local']) && $file_uploaded) {
            // User only wants GDrive and it succeeded — delete local file.
            @unlink($result['path']);
            $result['local_kept'] = false;
            $result['message'] .= ' ' . __('(Local copy removed as per settings)', 'sitessaver');
        } else {
            $result['local_kept'] = true;
            // Apply retention policy for local backups.
            $retention = (int) ($settings['retention'] ?? 5);
            if ($retention > 0) {
                self::apply_retention($retention);
            }
        }

        $this->finalize_backup($result, $settings, $frequency);

        return $result;
    }

    /**
     * Finalize backup: notify and log.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $settings
     */
    private function finalize_backup(array $result, array $settings, string $frequency = ''): void {
        // Send notification email.
        $email = $settings['notify_email'] ?? '';
        if (!empty($email) && is_email($email)) {
            self::send_notification($email, $result, $settings, $frequency);
        }

        // Log result.
        $log = get_option('sitessaver_schedule_log', []);
        if (!is_array($log)) {
            $log = [];
        }
        $log[] = [
            'time'      => current_time('mysql'),
            'success'   => $result['success'],
            'file'      => $result['file'] ?? '',
            'message'   => $result['message'] ?? '',
            'frequency' => $frequency,
        ];

        // Keep last 50 log entries.
        $log = array_slice($log, -50);
        update_option('sitessaver_schedule_log', $log, false);
    }

    /**
     * Delete old backups beyond retention count.
     */
    private static function apply_retention(int $keep): void {
        $backups = sitessaver_get_backups();

        if (count($backups) <= $keep) {
            return;
        }

        $to_delete = array_slice($backups, $keep);

        // Hoist label option outside loop; single read, single write.
        $labels          = get_option('sitessaver_backup_labels', []);
        $labels_changed  = false;

        foreach ($to_delete as $backup) {
            if (file_exists($backup['path'])) {
                @unlink($backup['path']);
            }

            if (isset($labels[$backup['file']])) {
                unset($labels[$backup['file']]);
                $labels_changed = true;
            }
        }

        if ($labels_changed) {
            update_option('sitessaver_backup_labels', $labels, false);
        }
    }

    /**
     * Send a branded notification email about the backup result.
     *
     * The body is assembled as data (rows, storage cards, actions) and then
     * rendered twice: once as branded HTML and once as plain text. Both come
     * from the same array so they can never drift apart.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $settings
     */
    private static function send_notification(
        string $email,
        array $result,
        array $settings = [],
        string $frequency = ''
    ): void {
        $site_name = get_bloginfo('name');
        $now       = current_time('mysql');

        $rows    = [];
        $storage = [];
        $notes   = [];
        $actions = [];

        if ($result['success']) {
            $subject = sprintf(
                /* translators: %s: site name */
                __('[%s] Scheduled backup completed', 'sitessaver'),
                $site_name
            );
            $title = __('Backup completed', 'sitessaver');
            $intro = sprintf(
                /* translators: %s: site name */
                __('A scheduled backup of %s finished successfully. Details are below.', 'sitessaver'),
                $site_name
            );

            $rows[] = [__('Site', 'sitessaver'), $site_name];
            $rows[] = [
                __('File', 'sitessaver'),
                $result['file'] !== '' ? (string) $result['file'] : __('Not kept on this server', 'sitessaver'),
            ];
            $rows[] = [__('Size', 'sitessaver'), (string) ($result['size'] ?? 'N/A')];
            $rows[] = [__('Completed', 'sitessaver'), $now];

            $label = self::frequencies()[self::normalize_frequency($frequency)]['label'] ?? '';
            if ($frequency !== '' && $label !== '') {
                $rows[] = [__('Schedule', 'sitessaver'), $label];
            }

            $contents = self::contents_summary($settings);
            if ($contents !== '') {
                $rows[] = [__('Includes', 'sitessaver'), $contents];
            }

            $next = self::next_scheduled_run();
            if ($next > 0) {
                $rows[] = [__('Next backup', 'sitessaver'), wp_date('j M Y, g:i a', $next)];
            }

            // --- Storage cards: where the archive actually is right now.
            if (!empty($result['local_kept']) && !empty($result['file'])) {
                $storage[] = [
                    'state'  => 'ok',
                    'label'  => __('This server', 'sitessaver'),
                    'detail' => __('Stored in the SitesSaver backups folder on your hosting account.', 'sitessaver'),
                ];
            } else {
                $storage[] = [
                    'state'  => 'muted',
                    'label'  => __('This server', 'sitessaver'),
                    'detail' => __('Local copy removed after upload, as configured in your schedule settings.', 'sitessaver'),
                ];
            }

            if (array_key_exists('gdrive_uploaded', $result)) {
                if (!empty($result['gdrive_uploaded'])) {
                    $storage[] = [
                        'state'  => 'ok',
                        'label'  => __('Google Drive', 'sitessaver'),
                        'detail' => sprintf(
                            /* translators: %s: site name */
                            __('Uploaded to the "SitesSaver Backups (%s)" folder in your Drive.', 'sitessaver'),
                            $site_name
                        ),
                        'url'    => (string) ($result['gdrive_folder_url'] ?? ''),
                    ];
                } else {
                    $storage[] = [
                        'state'  => 'warn',
                        'label'  => __('Google Drive — upload failed', 'sitessaver'),
                        'detail' => sprintf(
                            /* translators: %s: error message from Google Drive */
                            __('%s The archive is still on the server. Retry the upload from the Backups page.', 'sitessaver'),
                            (string) ($result['gdrive_error'] ?? __('Unknown error.', 'sitessaver'))
                        ),
                    ];
                }
            }

            $retention = (int) ($settings['retention'] ?? 0);
            if ($retention > 0) {
                $notes[] = sprintf(
                    /* translators: %d: number of backups retained */
                    _n(
                        'Retention: only the newest %d local backup is kept. Older ones are deleted automatically.',
                        'Retention: only the newest %d local backups are kept. Older ones are deleted automatically.',
                        $retention,
                        'sitessaver'
                    ),
                    $retention
                );
            }

            $notes[] = __('Keep at least one copy away from this server. A backup that only lives on the same host is lost with the host.', 'sitessaver');

            $actions[] = [
                'label'   => __('View backups', 'sitessaver'),
                'url'     => admin_url('admin.php?page=sitessaver'),
                'primary' => true,
            ];
            $actions[] = [
                'label' => __('Schedule settings', 'sitessaver'),
                'url'   => admin_url('admin.php?page=sitessaver-schedule'),
            ];
        } else {
            $subject = sprintf(
                /* translators: %s: site name */
                __('[%s] Scheduled backup FAILED', 'sitessaver'),
                $site_name
            );
            $title = __('Backup failed', 'sitessaver');
            $intro = sprintf(
                /* translators: %s: site name */
                __('The scheduled backup of %s did not complete. No new restore point was created.', 'sitessaver'),
                $site_name
            );

            $rows[] = [__('Site', 'sitessaver'), $site_name];
            $rows[] = [__('Error', 'sitessaver'), (string) ($result['message'] ?? __('Unknown error', 'sitessaver'))];
            $rows[] = [__('Time', 'sitessaver'), $now];

            $notes[] = __('Common causes: the server ran out of disk space, the PHP memory limit or execution time was too low, or the Google Drive connection expired.', 'sitessaver');
            $notes[] = __('Your previous backups are untouched and can still be restored.', 'sitessaver');

            $actions[] = [
                'label'   => __('Run a test backup', 'sitessaver'),
                'url'     => admin_url('admin.php?page=sitessaver-schedule'),
                'primary' => true,
            ];
            $actions[] = [
                'label' => __('View backups', 'sitessaver'),
                'url'   => admin_url('admin.php?page=sitessaver'),
            ];
        }

        $data = [
            'success' => (bool) $result['success'],
            'title'   => $title,
            'intro'   => $intro,
            'rows'    => $rows,
            'storage' => $storage,
            'notes'   => $notes,
            'actions' => $actions,
        ];

        Mailer::send(
            $email,
            $subject,
            Mailer::render_html($data),
            Mailer::render_text($data)
        );
    }

    /**
     * Human-readable list of what the archive contains.
     *
     * @param array<string, mixed> $settings
     */
    private static function contents_summary(array $settings): string {
        if ($settings === []) {
            return '';
        }

        $map = [
            'include_db'      => __('database', 'sitessaver'),
            'include_media'   => __('media', 'sitessaver'),
            'include_plugins' => __('plugins', 'sitessaver'),
            'include_themes'  => __('themes', 'sitessaver'),
        ];

        $parts = [];
        foreach ($map as $key => $label) {
            // Defaults match perform_backup(): absent means included.
            if (!array_key_exists($key, $settings) || !empty($settings[$key])) {
                $parts[] = $label;
            }
        }

        return implode(', ', $parts);
    }
}
