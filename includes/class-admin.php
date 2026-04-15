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
        add_action('admin_notices', [$this, 'render_restore_finalisation_notice']);
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
        Import::run_deferred_finalisation();

        if (!current_user_can('manage_options')) {
            return;
        }

        global $pagenow;
        if ($pagenow !== 'options-permalink.php') {
            return;
        }

        // WP redirects to ?settings-updated=true after saving the permalinks
        // form. That's our cue the user just clicked "Save Changes".
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
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
    }

    /**
     * Banner on Settings > Permalinks during restore finalisation.
     */
    public function render_restore_finalisation_notice(): void {
        global $pagenow;

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
            // Gently nudge from other admin pages.
            $url = esc_url(admin_url('options-permalink.php?sitessaver_finalize=1'));
            echo '<div class="notice notice-warning"><p><strong>SitesSaver:</strong> ' .
                esc_html__('Site restore is almost done — finish by saving permalinks.', 'sitessaver') .
                ' <a href="' . $url . '" class="button button-primary" style="margin-left:8px;">' .
                esc_html__('Go to Permalinks', 'sitessaver') . '</a></p></div>';
            return;
        }

        $clicks = $remaining === 2
            ? __('Click "Save Changes" below — twice — to flush rewrite rules and finalise the restore.', 'sitessaver')
            : __('Click "Save Changes" one more time to finalise the restore.', 'sitessaver');

        echo '<div class="notice notice-warning"><p><strong>SitesSaver:</strong> ' .
            esc_html($clicks) . '</p></div>';
    }

    public function register_menu(): void {
        add_menu_page(
            __('SitesSaver', 'sitessaver'),
            __('SitesSaver', 'sitessaver'),
            'manage_options',
            'sitessaver',
            [$this, 'render_page'],
            'dashicons-backup',
            80
        );

        add_submenu_page('sitessaver', __('Backups', 'sitessaver'), __('Backups', 'sitessaver'), 'manage_options', 'sitessaver', [$this, 'render_page']);
        add_submenu_page('sitessaver', __('Export', 'sitessaver'), __('Export', 'sitessaver'), 'manage_options', 'sitessaver-export', [$this, 'render_export_page']);
        add_submenu_page('sitessaver', __('Import', 'sitessaver'), __('Import', 'sitessaver'), 'manage_options', 'sitessaver-import', [$this, 'render_import_page']);
        add_submenu_page('sitessaver', __('Schedule', 'sitessaver'), __('Schedule', 'sitessaver'), 'manage_options', 'sitessaver-schedule', [$this, 'render_schedule_page']);
        add_submenu_page('sitessaver', __('Settings', 'sitessaver'), __('Settings', 'sitessaver'), 'manage_options', 'sitessaver-settings', [$this, 'render_settings_page']);
        add_submenu_page('sitessaver', __('Help & Manual', 'sitessaver'), __('Help', 'sitessaver'), 'manage_options', 'sitessaver-help', [$this, 'render_help_page']);
    }

    public function enqueue_assets(string $hook): void {
        if (!str_contains($hook, 'sitessaver')) {
            return;
        }

        wp_enqueue_style(
            'remixicon',
            'https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css',
            [],
            '3.5.0'
        );

        wp_enqueue_style(
            'sitessaver-admin',
            SITESSAVER_URL . 'assets/css/admin.css',
            [],
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
