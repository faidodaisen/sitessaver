<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Admin pages, menus, and asset enqueuing.
 */
final class Admin {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function init(): void {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Run deferred post-import finalisation on the first clean admin
        // request after a restore, then track the permalinks-save cycle.
        add_action('admin_init', [$this, 'handle_post_import_finalisation'], 1);

        // Storage OAuth return: store the token, then drop it from the URL.
        add_action('admin_init', [$this, 'handle_remote_callback'], 2);
        add_action('admin_notices', [$this, 'render_restore_finalisation_notice']);

        // Server-side logout for the post-restore hand-off. Both hooks: the
        // session may or may not still be valid after the restore.
        add_action('admin_post_sitessaver_finalize_logout', [$this, 'handle_finalize_logout']);
        add_action('admin_post_nopriv_sitessaver_finalize_logout', [$this, 'handle_finalize_logout']);
    }

    /**
     * First-request-after-restore hook.
     *
     *  1. Runs the deferred work (activates plugins, theme, clears cache).
     *  2. If we're now on Settings > Permalinks and a save just happened,
     *     decrement the save-counter; when it hits zero the restore is
     *     fully finalised and the banner disappears.
     */
    public function handle_post_import_finalisation(): void {
        // Finalisation is driven by a signed token in the URL, not by an
        // AJAX call. An AJAX call would run switch_theme() +
        // update_option('active_plugins') + wp_logout() inside a request
        // whose auth cookie was signed by the PRE-restore DB, which breaks
        // in three ways (nonce drift, wp_logout action hooks from other
        // plugins, Cloudflare-style middleware corrupting the JSON body).
        //
        //   The flow: after a successful restore the client redirects
        //   straight to wp-login.php with reauth=1 and a root-relative
        //   redirect_to=<permalinks?sitessaver_finalize=TOKEN>.
        //
        //   `reauth=1` is what forces the logout. The pre-restore cookie
        //   only stops validating when the backup carries different auth
        //   salts. Restoring a backup of the SAME site keeps the salts, so
        //   the session stays valid and, without reauth, wp-login.php would
        //   show a form the user could ignore. With reauth=1, wp-login.php
        //   calls wp_clear_auth_cookie()
        //   server-side, so the prompt is unavoidable either way.
        //
        //   The redirect_to is root-relative because wp-login.php runs it
        //   through wp_validate_redirect(), which rejects any host not in
        //   allowed_redirect_hosts and falls back to the dashboard. An
        //   absolute URL built from the restored siteurl breaks whenever that
        //   domain differs from the one the browser is on.
        //
        //   On successful login WP bounces to the permalinks page carrying
        //   our token. This hook runs under the freshly authenticated
        //   session, so current_user_can() + nonce checks all work normally.
        global $pagenow;

        // Only a logged-in user with the SitesSaver capability. The finalize
        // URL is reached after the post-restore login, so the admin is here.
        if (!is_user_logged_in() || !sitessaver_current_user_can()) {
            return;
        }

        // No nonce here by design: the restore replaced the database, so a
        // nonce issued before it cannot verify. The single-use finalize token
        // is the CSRF guard (hash_equals below).
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $token = isset($_GET['sitessaver_finalize'])
            ? sanitize_text_field(wp_unslash($_GET['sitessaver_finalize']))
            : '';

        // Accept the finalize token on ANY admin page (WP may strip query
        // params on certain redirects but permalinks is the configured
        // destination). The heavy work should run once per restore.
        if ($token !== '') {
            $pending = get_option('sitessaver_pending_finalize', null);
            $expected = is_array($pending) ? (string) ($pending['token'] ?? '') : '';
            if ($expected !== '' && hash_equals($expected, $token)) {
                Import::run_deferred_finalisation();
            }
        }

        if ($pagenow !== 'options-permalink.php') {
            return;
        }

        // WP redirects to ?settings-updated=true after saving the permalinks
        // form. That's our cue the user just clicked "Save Changes".
        if (isset($_GET['settings-updated']) && sanitize_key(wp_unslash($_GET['settings-updated'])) === 'true') {
            $remaining = (int) get_transient('sitessaver_needs_permalinks_flush');
            if ($remaining > 0) {
                $remaining--;
                if ($remaining <= 0) {
                    delete_transient('sitessaver_needs_permalinks_flush');
                    set_transient('sitessaver_restore_complete', 1, HOUR_IN_SECONDS);
                } else {
                    set_transient('sitessaver_needs_permalinks_flush', $remaining, DAY_IN_SECONDS);
                }
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * The relay sends the browser back to Settings with the refresh token in
     * the query string. Handle it here, before any output, and redirect to a
     * clean Settings URL so the token does not stay in the address bar or
     * browser history. The one-time `state` check is in handle_callback().
     */
    public function handle_remote_callback(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- An OAuth return cannot carry a nonce; the single-use state value is checked instead.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'sitessaver-settings') {
            return;
        }

        $callback = null;
        if (isset($_GET['sitessaver_gdrive_token'])) {
            $callback = [GDrive::class, 'handle_callback'];
        } else {
            foreach (Destinations::extra() as $dest) {
                if (isset($_GET[$dest->callback_param()])) {
                    $callback = [$dest, 'handle_callback'];
                    break;
                }
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ($callback === null) {
            return;
        }

        $clean = admin_url('admin.php?page=sitessaver-settings');
        if (!sitessaver_current_user_can()) {
            wp_safe_redirect($clean);
            exit;
        }

        $result = (array) call_user_func($callback);
        set_transient('sitessaver_auth_notice_' . get_current_user_id(), [
            'message' => (string) ($result['message'] ?? ''),
            'ok'      => !empty($result['success']),
        ], 5 * MINUTE_IN_SECONDS);

        nocache_headers();
        wp_safe_redirect(add_query_arg('sitessaver_auth', '1', $clean));
        exit;
    }

    /**
     * End the current session server-side and send the user to the login
     * screen, headed for Settings > Permalinks afterwards.
     *
     * Why not wp-login.php?reauth=1: see Import::build_finalize_redirect_url().
     * The finalize token is required so this cannot be used as a drive-by
     * logout link; it is NOT consumed here (the permalinks request does that).
     */
    public function handle_finalize_logout(): void {
        // The finalize token is the guard (a pre-restore nonce cannot verify).
        $supplied = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $expected = Import::current_finalize_token();

        if ($expected === '' || $supplied === '' || !hash_equals($expected, $supplied)) {
            wp_safe_redirect(wp_login_url());
            exit;
        }

        if (is_user_logged_in()) {
            wp_destroy_current_session();
        }
        wp_clear_auth_cookie();
        wp_set_current_user(0);

        wp_safe_redirect(add_query_arg(
            'redirect_to',
            rawurlencode(Import::finalize_permalinks_path($expected)),
            wp_login_url()
        ));
        exit;
    }

    /**
     * Banner during restore finalisation. Shown only where it is needed:
     * Settings > Permalinks (the step the restore asks for) and SitesSaver's
     * own screens. Never anywhere else in wp-admin.
     */
    public function render_restore_finalisation_notice(): void {
        global $pagenow;

        if (!sitessaver_current_user_can()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $on_own = $screen !== null && str_contains((string) $screen->id, 'sitessaver');
        if ($pagenow !== 'options-permalink.php' && !$on_own) {
            return;
        }

        if (get_transient('sitessaver_restore_complete')) {
            delete_transient('sitessaver_restore_complete');
            echo '<div class="notice notice-success is-dismissible"><p><strong>SitesSaver:</strong> ' .
                esc_html__('Restore complete. Your site is now running on the restored backup.', 'sitessaver') .
                '</p></div>';
            return;
        }

        $remaining = (int) get_transient('sitessaver_needs_permalinks_flush');
        if ($remaining <= 0) {
            return;
        }

        if ($pagenow !== 'options-permalink.php') {
            // On SitesSaver's own screens, point to the last step.
            $url = admin_url('options-permalink.php?sitessaver_finalize=1');
            echo '<div class="notice notice-warning is-dismissible"><p><strong>SitesSaver:</strong> ' .
                esc_html__('Site restore is almost done. Finish by saving permalinks.', 'sitessaver') .
                ' <a href="' . esc_url($url) . '" class="button button-primary" style="margin-left:8px;">' .
                esc_html__('Go to Permalinks', 'sitessaver') . '</a></p></div>';
            return;
        }

        $clicks = $remaining === 2
            ? __('Click "Save Changes" below twice to flush rewrite rules and finalise the restore.', 'sitessaver')
            : __('Click "Save Changes" one more time to finalise the restore.', 'sitessaver');

        echo '<div class="notice notice-warning is-dismissible"><p><strong>SitesSaver:</strong> ' .
            esc_html($clicks) . '</p></div>';
    }

    public function register_menu(): void {
        add_menu_page(
            __('SitesSaver', 'sitessaver'),
            __('SitesSaver', 'sitessaver'),
            sitessaver_capability(),
            'sitessaver',
            [$this, 'render_page'],
            'dashicons-backup',
            80
        );

        add_submenu_page('sitessaver', __('Backups', 'sitessaver'), __('Backups', 'sitessaver'), sitessaver_capability(), 'sitessaver', [$this, 'render_page']);
        add_submenu_page('sitessaver', __('Export', 'sitessaver'), __('Export', 'sitessaver'), sitessaver_capability(), 'sitessaver-export', [$this, 'render_export_page']);
        add_submenu_page('sitessaver', __('Import', 'sitessaver'), __('Import', 'sitessaver'), sitessaver_capability(), 'sitessaver-import', [$this, 'render_import_page']);
        add_submenu_page('sitessaver', __('Schedule', 'sitessaver'), __('Schedule', 'sitessaver'), sitessaver_capability(), 'sitessaver-schedule', [$this, 'render_schedule_page']);
        add_submenu_page('sitessaver', __('Settings', 'sitessaver'), __('Settings', 'sitessaver'), sitessaver_capability(), 'sitessaver-settings', [$this, 'render_settings_page']);
        add_submenu_page('sitessaver', __('Help & Manual', 'sitessaver'), __('Help', 'sitessaver'), sitessaver_capability(), 'sitessaver-help', [$this, 'render_help_page']);
    }

    public function enqueue_assets(string $hook): void {
        if (!str_contains($hook, 'sitessaver')) {
            return;
        }

        // The Settings page offers a media picker for the email logo. Loading
        // wp.media only there keeps its ~200KB off every other plugin screen.
        if (str_contains($hook, 'sitessaver-settings')) {
            wp_enqueue_media();
        }

        // Icons are bundled locally (a subset of RemixIcon containing only the
        // glyphs this plugin uses), so the admin UI stays styled on
        // offline/intranet installs and behind strict CSPs, and nothing is
        // loaded from a CDN.
        wp_enqueue_style(
            'sitessaver-icons',
            SITESSAVER_URL . 'assets/css/icons.css',
            [],
            SITESSAVER_VERSION
        );

        wp_enqueue_style(
            'sitessaver-admin',
            SITESSAVER_URL . 'assets/css/admin.css',
            ['sitessaver-icons'],
            SITESSAVER_VERSION
        );


        wp_enqueue_script(
            'sitessaver-admin',
            SITESSAVER_URL . 'assets/js/admin.js',
            ['jquery'],
            SITESSAVER_VERSION,
            true
        );

        wp_localize_script('sitessaver-admin', 'SitesSaver', [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('sitessaver_nonce'),
            'downloadNonce' => wp_create_nonce('sitessaver_download'),
            'maxUploadSize' => sitessaver_max_upload_size(),
            'importPhases'  => array_values(array_map(
                static fn($id, $label) => ['id' => $id, 'label' => $label],
                array_keys(\SitesSaver\Import::phases()),
                \SitesSaver\Import::phases()
            )),
            'errors'        => \SitesSaver\Errors::for_js(),
            'logUrl'        => admin_url('admin.php?page=sitessaver-help#sitessaver-log'),
            'adminEmail'    => sitessaver_export_notify_email(), // '' = notifications off
            'strings'       => [
                'confirmDelete'  => __('Delete this backup? This cannot be undone.', 'sitessaver'),
                'confirmRestore' => __('Restore this backup? Your current site will be overwritten.', 'sitessaver'),
                'exporting'      => __('Exporting...', 'sitessaver'),
                'importing'      => __('Importing...', 'sitessaver'),
                'done'           => __('Done!', 'sitessaver'),
                'error'          => __('An error occurred.', 'sitessaver'),
            ],
        ]);
    }

    public function render_page(): void {
        include SITESSAVER_PATH . 'templates/backups.php';
    }

    public function render_export_page(): void {
        include SITESSAVER_PATH . 'templates/export.php';
    }

    public function render_import_page(): void {
        include SITESSAVER_PATH . 'templates/import.php';
    }

    public function render_schedule_page(): void {
        include SITESSAVER_PATH . 'templates/schedule.php';
    }

    public function render_settings_page(): void {
        include SITESSAVER_PATH . 'templates/settings.php';
    }

    public function render_help_page(): void {
        include SITESSAVER_PATH . 'templates/help.php';
    }
}
