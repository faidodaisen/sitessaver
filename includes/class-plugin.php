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

        // Keeps a manual export moving with no tab open (watchdog, LiteSpeed
        // rule, email). Outside is_admin(): WP-Cron fires on the front end.
        Background::init();

        // A4 (multisite addon hook point — see PLAN-multisite-premium-addon.md
        // §7 Track A/B). Fires once, synchronously, right here at the end of
        // Plugin::init() — which is itself hooked on 'plugins_loaded' at the
        // default priority (10) by the bootstrap in sitessaver.php.
        //
        // TIMING CONTRACT for anything hooking this (the multisite addon is
        // the only consumer today, but any future addon follows the same
        // contract):
        //   1. The addon's main plugin file must call
        //      add_action('sitessaver_loaded', ...) UNCONDITIONALLY at its
        //      top level — NOT deferred behind the addon's own
        //      'plugins_loaded' callback. Plugin files are include()'d by
        //      WordPress before 'plugins_loaded' fires at all, so a
        //      top-level add_action() call is guaranteed to be registered
        //      before this do_action() runs, regardless of plugin load
        //      order between this plugin and the addon.
        //   2. Do the class_exists('SitesSaver\Plugin') + version_compare()
        //      dependency guard INSIDE the 'sitessaver_loaded' callback
        //      itself, not as a precondition for registering it. If you
        //      gate registration behind a separate 'plugins_loaded'
        //      priority check instead, you can lose the race: this
        //      do_action() fires from WITHIN priority 10 of 'plugins_loaded'
        //      (this plugin's own bootstrap callback), so any addon
        //      callback registered at a later 'plugins_loaded' priority
        //      (e.g. 20) has not been added yet when this fires, and the
        //      addon's own 'sitessaver_loaded' hook never runs. Hooking
        //      'sitessaver_loaded' directly at the addon's top level sidesteps
        //      this entirely.
        //   3. admin_menu ordering: Admin::instance()->init() above (which
        //      registers THIS plugin's top-level 'sitessaver' menu) always
        //      runs before this do_action(), so an addon that registers its
        //      own add_action('admin_menu', ...) inside its 'sitessaver_loaded'
        //      callback is guaranteed to be added to the 'admin_menu' hook
        //      AFTER this plugin's callback, at the same default priority
        //      (10) — WordPress runs same-priority callbacks in registration
        //      order, so the top-level menu is always registered before any
        //      addon submenu attaches to it. No manual priority bump needed.
        do_action('sitessaver_loaded', $this);
    }
}
