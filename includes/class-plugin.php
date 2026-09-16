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

        // Ensure modern image MIME types are recognised by WordPress. Why:
        // some security plugins and hosting stacks prune `image/webp` and
        // `image/avif` out of upload_mimes. After a restore the uploads
        // folder contains webp files and wp_postmeta rows reference them by
        // attachment ID — but wp_check_filetype() / wp_get_attachment_url()
        // short-circuit to empty if the MIME isn't in the allow list, which
        // looks to users like "some images are broken after restore". This
        // filter runs at priority 99 to override prior restrictions.
        add_filter('upload_mimes', [$this, 'register_modern_image_mimes'], 99);
        add_filter('wp_check_filetype_and_ext', [$this, 'relax_modern_image_filetype_check'], 99, 4);

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

        // Self-hosted updates from GitHub releases. Registered outside the
        // is_admin() guard because WP-Cron runs the update check in a
        // front-end context on many hosts.
        Updater::instance()->init();

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

    /**
     * Re-add modern image MIME types that some stacks strip from upload_mimes.
     *
     * @param array<string,string> $mimes
     * @return array<string,string>
     */
    public function register_modern_image_mimes(array $mimes): array {
        $mimes['webp']        = 'image/webp';
        $mimes['avif']        = 'image/avif';
        // Don't register SVG here — it's a known XSS vector and needs
        // explicit sanitisation that's out of scope for this plugin.
        return $mimes;
    }

    /**
     * Repair WordPress's wp_check_filetype_and_ext() result for webp/avif.
     * Older WP cores return a false-negative on webp under certain fileinfo
     * configurations (libmagic returns 'application/octet-stream'). When the
     * extension is unambiguous we restore the correct MIME so attachment
     * metadata stored with image/webp continues to resolve after restore.
     *
     * @param array $data     { ext, type, proper_filename }
     * @param string $file
     * @param string $filename
     * @param array|null $mimes
     * @return array
     */
    public function relax_modern_image_filetype_check($data, $file, $filename, $mimes): array {
        if (!is_array($data)) {
            $data = ['ext' => false, 'type' => false, 'proper_filename' => false];
        }
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'webp' && empty($data['type'])) {
            $data['ext']  = 'webp';
            $data['type'] = 'image/webp';
        } elseif ($ext === 'avif' && empty($data['type'])) {
            $data['ext']  = 'avif';
            $data['type'] = 'image/avif';
        }
        return $data;
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
