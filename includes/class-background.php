<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Keeps a manual export moving when nobody is watching it.
 *
 * An export is a chain of short slices, each one starting the next through a
 * loopback request. Three things can break that chain on shared hosting, and
 * each has its own remedy here:
 *
 * 1. LiteSpeed stops a PHP request as soon as its client disconnects, and a
 *    loopback worker's client disconnects at once by design. LiteSpeed's
 *    documented fix is the `noabort` environment variable, set from
 *    .htaccess. We add that rule — scoped to SitesSaver's own worker
 *    requests only — between our own markers.
 * 2. When a worker dies anyway, a WP-Cron watchdog that runs every minute
 *    while an export is open notices the silence and runs the next slice.
 *    WP-Cron needs site traffic to fire.
 * 3. The server-cron trigger URL (Schedule screen) does the same on every
 *    call, without needing traffic.
 *
 * The open Export tab remains one more way to move the export, never the
 * only one. Whoever is not watching gets an email when it ends.
 */
final class Background {

    /** Query flag carried by every loopback worker request. */
    public const WORKER_FLAG = 'sitessaver_bg';

    public const WATCHDOG_HOOK = 'sitessaver_export_watchdog';

    /** Silence after which a watchdog/cron call runs a slice itself. */
    public const SILENT_SECONDS = 45;

    /** A tab that polled this recently is driving the export; leave it be. */
    private const WATCHED_SECONDS = 20;

    private const HTACCESS_MARKER = 'SitesSaver';
    private const HTACCESS_OPTION = 'sitessaver_htaccess_rules';
    private const HTACCESS_VERSION = '1';

    public static function init(): void {
        add_action(self::WATCHDOG_HOOK, [self::class, 'run_watchdog']);
        add_filter('cron_schedules', [self::class, 'add_schedule']);
        add_action('admin_init', [self::class, 'maybe_install_rules']);
    }

    /** URL every loopback worker posts to. */
    public static function worker_url(): string {
        return add_query_arg(self::WORKER_FLAG, '1', admin_url('admin-ajax.php'));
    }

    // ------------------------------------------------------------------
    // Watchdog
    // ------------------------------------------------------------------

    /** @param array<string, array<string, mixed>> $schedules */
    public static function add_schedule($schedules): array {
        $schedules = is_array($schedules) ? $schedules : [];
        $schedules['sitessaver_minute'] = [
            'interval' => MINUTE_IN_SECONDS,
            'display'  => __('Every minute (SitesSaver, while a backup runs)', 'sitessaver'),
        ];
        return $schedules;
    }

    /** Arm the watchdog for the export that just started. */
    public static function arm_watchdog(): void {
        if (!wp_next_scheduled(self::WATCHDOG_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'sitessaver_minute', self::WATCHDOG_HOOK);
        }
    }

    public static function disarm_watchdog(): void {
        wp_clear_scheduled_hook(self::WATCHDOG_HOOK);
    }

    public static function run_watchdog(): void {
        $result = self::continue_stalled('wp-cron');
        if ($result === 'idle') {
            self::disarm_watchdog();
        }
    }

    /** The browser tab is polling; called from the status endpoint. */
    public static function mark_watched(string $uid): void {
        set_transient('sitessaver_export_seen_' . $uid, time(), HOUR_IN_SECONDS);
    }

    public static function is_watched(string $uid, int $within = self::WATCHED_SECONDS): bool {
        $seen = (int) get_transient('sitessaver_export_seen_' . $uid);
        return $seen > 0 && time() - $seen <= $within;
    }

    /**
     * Run the next slice of the open export if nothing else is moving it.
     *
     * @param string $source 'wp-cron' | 'server-cron' (for the log).
     * @return string 'idle' (no export), 'busy' (it is moving), 'ran', 'failed'.
     */
    public static function continue_stalled(string $source): string {
        $uid = (string) (get_transient('sitessaver_active_export_id') ?: '');
        $status = $uid !== '' ? Export::get_status($uid) : [];
        if (empty($status) || ($status['status'] ?? '') !== 'running') {
            return 'idle';
        }

        $since = time() - (int) ($status['last_update'] ?? 0);
        if ($since < self::SILENT_SECONDS) {
            return 'busy';
        }

        // An open tab is running the slices itself and the export is only
        // between two of them.
        if (($status['driver'] ?? '') === 'browser' && self::is_watched($uid)) {
            return 'busy';
        }

        // Only one rescuer at a time: a minute-cron and the trigger URL can
        // fire together.
        if (get_transient('sitessaver_export_rescue_' . $uid)) {
            return 'busy';
        }
        set_transient('sitessaver_export_rescue_' . $uid, 1, self::SILENT_SECONDS);

        $status['driver'] = 'worker';
        $status['rescues'] = (int) ($status['rescues'] ?? 0) + 1;
        Export::save_status_public($uid, $status);

        Log::info('export_continued', sprintf('The backup had no progress for %ds; %s continued it.', $since, $source === 'server-cron' ? 'the server cron' : 'WP-Cron'), [
            'uid'  => $uid,
            'step' => $status['step_index'] ?? null,
        ]);

        @ignore_user_abort(true);
        try {
            $result = Export::work($uid);
        } finally {
            delete_transient('sitessaver_export_rescue_' . $uid);
        }

        if (!empty($result['continue'])) {
            Export::spawn_worker($uid);
        }

        return !empty($result['success']) ? 'ran' : 'failed';
    }

    // ------------------------------------------------------------------
    // Email when nobody is watching
    // ------------------------------------------------------------------

    /**
     * Tell the site admin how a backup ended, when no Export tab saw it.
     *
     * @param array<string, mixed> $status
     */
    public static function notify(array $status, bool $ok, string $detail = ''): void {
        $uid = (string) ($status['uid'] ?? '');
        if ($uid === '' || self::is_watched($uid, 90)) {
            return;
        }
        // Scheduled backups have their own notification settings.
        if (!empty($status['options']['track_chain'])) {
            return;
        }

        $to = (string) apply_filters('sitessaver_export_notify_email', get_option('admin_email'), $status, $ok);
        if ($to === '' || !is_email($to)) {
            return;
        }

        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $link = admin_url('admin.php?page=sitessaver');

        if ($ok) {
            $result  = is_array($status['result'] ?? null) ? $status['result'] : [];
            $subject = sprintf(__('[%s] Your backup is ready', 'sitessaver'), $site);
            $body    = sprintf(
                __("The backup you started on %1\$s has finished.\n\nFile: %2\$s\nSize: %3\$s\n\nDownload it from SitesSaver → Backups:\n%4\$s\n\n— SitesSaver", 'sitessaver'),
                home_url(),
                (string) ($result['file'] ?? $status['backup_name'] ?? ''),
                (string) ($result['size'] ?? ''),
                $link
            );
        } else {
            $subject = sprintf(__('[%s] Your backup did not finish', 'sitessaver'), $site);
            $body    = sprintf(
                __("The backup you started on %1\$s stopped before it finished. Your site itself is fine — nothing was changed.\n\nReference: %2\$s\nDetails: %3\$s\n\nTry again from SitesSaver → Export. If it stops again, open Help → Troubleshooting Log, download the log and send it to SitesSaver support:\n%4\$s\n\n— SitesSaver", 'sitessaver'),
                home_url(),
                !empty($status['ref']) ? 'SS-' . $status['ref'] : '—',
                $detail !== '' ? $detail : (string) ($status['message'] ?? ''),
                admin_url('admin.php?page=sitessaver-help')
            );
        }

        wp_mail($to, $subject, $body);
    }

    // ------------------------------------------------------------------
    // LiteSpeed rule
    // ------------------------------------------------------------------

    /** @return array<int, string> */
    public static function htaccess_lines(): array {
        return [
            '# Lets SitesSaver background backup workers finish on LiteSpeed.',
            '# Applies only to requests carrying ' . self::WORKER_FLAG . '=1. Removed with the plugin.',
            '<IfModule LiteSpeed>',
            'RewriteEngine On',
            'RewriteCond %{QUERY_STRING} (^|&)' . self::WORKER_FLAG . '=1(&|$) [OR]',
            'RewriteCond %{REQUEST_URI} wp-cron\.php$',
            'RewriteRule .* - [E=noabort:1,E=noconntimeout:1]',
            '</IfModule>',
        ];
    }

    public static function is_litespeed(): bool {
        $soft = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
        return stripos($soft, 'litespeed') !== false || defined('LSCWP_V') || isset($_SERVER['LSWS_EDITION']);
    }

    private static function htaccess_path(): string {
        return (function_exists('get_home_path') ? get_home_path() : ABSPATH) . '.htaccess';
    }

    /**
     * Add the rule on LiteSpeed (once per rule version). Never creates an
     * .htaccess that does not exist and never touches one it cannot write.
     */
    public static function maybe_install_rules(): void {
        if (!current_user_can('manage_options') || wp_doing_ajax()) {
            return;
        }
        if (get_option(self::HTACCESS_OPTION) === self::HTACCESS_VERSION . ':' . (self::is_litespeed() ? 'ls' : 'other')) {
            return;
        }
        $result = self::is_litespeed() ? self::install_rules() : 'skipped';
        update_option(self::HTACCESS_OPTION, self::HTACCESS_VERSION . ':' . (self::is_litespeed() ? 'ls' : 'other'), false);
        if ($result !== 'skipped') {
            Log::info('htaccess_rule', $result === 'ok'
                ? 'Added the LiteSpeed rule that lets background backup workers finish.'
                : 'Could not add the LiteSpeed background-worker rule (.htaccess missing or not writable).');
        }
    }

    /** @return string 'ok' | 'failed' | 'skipped' */
    public static function install_rules(): string {
        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file = self::htaccess_path();
        if (!is_file($file) || !is_writable($file)) {
            return 'failed';
        }
        return self::write_block($file, self::htaccess_lines()) ? 'ok' : 'failed';
    }

    public static function remove_rules(): void {
        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file = self::htaccess_path();
        if (is_file($file) && is_writable($file)) {
            $text = (string) file_get_contents($file);
            if (str_contains($text, '# BEGIN ' . self::HTACCESS_MARKER)) {
                $clean = preg_replace('/\R?# BEGIN ' . self::HTACCESS_MARKER . '\R.*?# END ' . self::HTACCESS_MARKER . '\R?/s', "\n", $text);
                if (is_string($clean)) {
                    file_put_contents($file, ltrim($clean, "\r\n"), LOCK_EX);
                }
            }
        }
        delete_option(self::HTACCESS_OPTION);
    }

    /**
     * Put our block at the TOP of .htaccess. insert_with_markers() appends a
     * new block at the end, after `# END WordPress`, which works too, but a
     * caching plugin's own [L] rules above could otherwise end processing
     * before our line is reached.
     *
     * @param array<int, string> $lines
     */
    private static function write_block(string $file, array $lines): bool {
        $text  = (string) file_get_contents($file);
        $begin = '# BEGIN ' . self::HTACCESS_MARKER;
        $end   = '# END ' . self::HTACCESS_MARKER;

        if (str_contains($text, $begin)) {
            return insert_with_markers($file, self::HTACCESS_MARKER, $lines);
        }

        $block = $begin . "\n" . implode("\n", $lines) . "\n" . $end . "\n\n";
        return file_put_contents($file, $block . $text, LOCK_EX) !== false;
    }
}
