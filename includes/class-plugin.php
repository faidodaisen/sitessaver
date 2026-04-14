<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Main plugin class — singleton that bootstraps all components.
 */
final class Plugin {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function init(): void {
        // Load translations on init (before any __() call resolves).
        add_action('init', [$this, 'load_textdomain']);

        // Admin pages & assets — only needed in admin requests.
        if (is_admin()) {
            Admin::instance()->init();
        }

        // AJAX handlers — admin-ajax.php runs under is_admin() so safe to wire
        // alongside the admin boot, but keeping it unconditional guards against
        // REST/CLI contexts that may reuse the ajax endpoints.
        Ajax::instance()->init();

        // Scheduled backups (cron may fire outside admin).
        Schedule::instance()->init();
    }

    /**
     * Load plugin translations from /languages/ (e.g. sitessaver-ms_MY.mo).
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'sitessaver',
            false,
            dirname(plugin_basename(SITESSAVER_FILE)) . '/languages'
        );
    }
}
