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
}
