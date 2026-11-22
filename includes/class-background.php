<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-GB backup archives, SQL dumps and chunked uploads with byte offsets, file locks and atomic renames inside the plugin's own backup folder; WP_Filesystem has no streaming, seek or lock API and may need FTP credentials that a background worker cannot supply.

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
 *    .htaccess. We add that rule, scoped to SitesSaver's own worker
 *    requests only, between our own markers.
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

    /** admin-post action behind the Help page's LiteSpeed rule buttons. */
    public const RULE_ACTION = 'sitessaver_litespeed_rule';

    public static function init(): void {
        add_action(self::WATCHDOG_HOOK, [self::class, 'run_watchdog']);
        add_filter('cron_schedules', [self::class, 'add_schedule']);
        add_action('admin_post_' . self::RULE_ACTION, [self::class, 'handle_rule_action']);
        add_action('admin_notices', [self::class, 'rule_notice']);
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
        if (!empty($status['options']['track_chain']) || !empty($status['options']['schedule'])) {
            return;
        }

        $to = (string) apply_filters('sitessaver_export_notify_email', sitessaver_export_notify_email(), $status, $ok);
        if ($to === '' || !is_email($to)) {
            return;
        }

        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $link = admin_url('admin.php?page=sitessaver');

        if ($ok) {
            $result  = is_array($status['result'] ?? null) ? $status['result'] : [];
            /* translators: %s: site name. */
            $subject = sprintf(__('[%s] Your backup is ready', 'sitessaver'), $site);
            $body    = sprintf(
                /* translators: 1: site URL, 2: backup file name, 3: backup size, 4: link to the Backups page. */
                __("The backup you started on %1\$s has finished.\n\nFile: %2\$s\nSize: %3\$s\n\nDownload it from SitesSaver > Backups:\n%4\$s\n\nSitesSaver", 'sitessaver'),
                home_url(),
                (string) ($result['file'] ?? $status['backup_name'] ?? ''),
                (string) ($result['size'] ?? ''),
                $link
            );
        } else {
            /* translators: %s: site name. */
            $subject = sprintf(__('[%s] Your backup did not finish', 'sitessaver'), $site);
            $body    = sprintf(
                /* translators: 1: site URL, 2: error reference code, 3: error details, 4: link to the Help page. */
                __("The backup you started on %1\$s stopped before it finished. Your site itself is fine. Nothing was changed.\n\nReference: %2\$s\nDetails: %3\$s\n\nTry again from SitesSaver > Export. If it stops again, open Help > Troubleshooting Log, download the log and send it to SitesSaver support:\n%4\$s\n\nSitesSaver", 'sitessaver'),
                home_url(),
                !empty($status['ref']) ? 'SS-' . $status['ref'] : 'none',
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
        $soft = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '';
        $is   = stripos($soft, 'litespeed') !== false || defined('LSCWP_V') || isset($_SERVER['LSWS_EDITION']);
        return (bool) apply_filters('sitessaver_is_litespeed', $is);
    }

    private static function htaccess_path(): string {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        return get_home_path() . '.htaccess';
    }

    /** Is our rule block in the site's .htaccess? */
    public static function rules_installed(): bool {
        $file = self::htaccess_path();
        if (!is_file($file) || !is_readable($file)) {
            return false;
        }
        return str_contains((string) file_get_contents($file), self::WORKER_FLAG . '=1');
    }

    /**
     * Add the rule, only when the admin asks for it from the Help page.
     * Never creates an .htaccess that does not exist.
     *
     * @return string 'ok' | 'failed'
     */
    public static function install_rules(): string {
        $file = self::htaccess_path();
        if (!is_file($file) || !is_writable($file)) {
            return 'failed';
        }
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        return insert_with_markers($file, self::HTACCESS_MARKER, self::htaccess_lines()) ? 'ok' : 'failed';
    }

    /** Take the rule out again (Help page button, deactivation, uninstall). */
    public static function remove_rules(): bool {
        delete_option('sitessaver_htaccess_rules');
        $file = self::htaccess_path();
        if (!is_file($file) || !is_writable($file)) {
            return false;
        }
        if (!str_contains((string) file_get_contents($file), '# BEGIN ' . self::HTACCESS_MARKER)) {
            return true;
        }
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        return insert_with_markers($file, self::HTACCESS_MARKER, []);
    }

    /** Add / remove buttons on the Help page. */
    public static function handle_rule_action(): void {
        if (!sitessaver_current_user_can()) {
            wp_die(esc_html__('You do not have permission to do this.', 'sitessaver'), '', ['response' => 403]);
        }
        check_admin_referer(self::RULE_ACTION);

        $op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
        if ($op === 'add') {
            $ok = self::install_rules() === 'ok';
            Log::info('htaccess_rule', $ok
                ? 'Added the LiteSpeed rule that lets background backup workers finish (admin request).'
                : 'Could not add the LiteSpeed background-worker rule (.htaccess missing or not writable).');
            $result = $ok ? 'added' : 'failed';
        } elseif ($op === 'remove') {
            $ok = self::remove_rules();
            if ($ok) {
                Log::info('htaccess_rule', 'Removed the LiteSpeed background-worker rule (admin request).');
            }
            $result = $ok ? 'removed' : 'failed';
        } else {
            $result = 'failed';
        }

        wp_safe_redirect(add_query_arg(
            ['page' => 'sitessaver-help', 'sitessaver_rule' => $result, 'sitessaver_rule_nonce' => wp_create_nonce('sitessaver_rule')],
            admin_url('admin.php')
        ) . '#sitessaver-litespeed');
        exit;
    }

    /** On LiteSpeed without the rule, point to the Help page. SitesSaver screens only. */
    public static function rule_notice(): void {
        if (!sitessaver_current_user_can() || !self::is_litespeed()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id     = $screen ? (string) $screen->id : '';
        if (!str_contains($id, 'sitessaver') || str_contains($id, 'sitessaver-help') || self::rules_installed()) {
            return;
        }
        printf(
            '<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('This site runs on LiteSpeed. Background backups finish more reliably with a small .htaccess rule, which you can add from the Help page.', 'sitessaver'),
            esc_url(admin_url('admin.php?page=sitessaver-help#sitessaver-litespeed')),
            esc_html__('Open Help', 'sitessaver')
        );
    }
}
