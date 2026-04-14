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
        // Admin pages & assets.
        Admin::instance()->init();

        // AJAX handlers.
        Ajax::instance()->init();

        // Scheduled backups.
        Schedule::instance()->init();
    }
}
