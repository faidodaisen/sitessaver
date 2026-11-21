<?php
/**
 * Plugin Name: SitesSaver
 * Plugin URI:  https://sitessaver.com
 * Description: Full site backup and migration: export, import, schedule, and Google Drive storage. No restrictions.
 * Version:     1.6.2
 * Author:      Fido Design
 * Author URI:  https://fidodesign.net
 * License:     GPL-2.0-or-later
 * Text Domain: sitessaver
 * Requires PHP: 8.1
 * Requires at least: 6.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// This copy's version, read BEFORE any constants so the duplicate-copy
// handler below can name it. Folder name is irrelevant: SitesSaver is
// identified by its VERSION constant.
$sitessaver_this_version = '1.6.2';

// Duplicate-copy handler. WordPress lets the same plugin live in more than
// one folder (e.g. `plugins/sitessaver/` and `plugins/sitessaver-copy/`) and
// loads every active copy. A second copy must not run: its classes and
// constants would redeclare the first copy's and stop the site.
//
// So the copy that loads second does nothing except tell the admin, on the
// Plugins screen and SitesSaver's own screens, and offer a button that
// deactivates it. Nothing is deactivated unless the admin clicks it.
if (defined('SITESSAVER_VERSION')) {
    $sitessaver_copy = plugin_basename(__FILE__);

    // A network-active copy can only be handled from Network Admin.
    $sitessaver_copy_network = static function () use ($sitessaver_copy): bool {
        if (!is_multisite()) {
            return false;
        }
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active_for_network($sitessaver_copy);
    };

    $sitessaver_copy_notice = static function () use ($sitessaver_copy, $sitessaver_this_version, $sitessaver_copy_network): void {
        $network = $sitessaver_copy_network();
        if ($network ? (!is_network_admin() || !current_user_can('manage_network_plugins')) : !current_user_can('activate_plugins')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id     = $screen ? (string) $screen->id : '';
        if (!in_array($id, ['plugins', 'plugins-network'], true) && !str_contains($id, 'sitessaver')) {
            return;
        }
        $url = wp_nonce_url(
            add_query_arg(
                ['action' => 'sitessaver_deactivate_copy', 'plugin' => $sitessaver_copy],
                $network ? network_admin_url('edit.php') : admin_url('admin-post.php')
            ),
            'sitessaver_deactivate_copy_' . $sitessaver_copy
        );
        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
            sprintf(
                /* translators: 1: plugin file of the extra copy, 2: its version, 3: version of the copy that is running. */
                esc_html__('SitesSaver is installed twice. The copy in %1$s (version %2$s) is active but not running, because SitesSaver %3$s from another folder is already loaded. Deactivate the extra copy, then delete it from Plugins.', 'sitessaver'),
                '<code>' . esc_html($sitessaver_copy) . '</code>',
                esc_html($sitessaver_this_version),
                esc_html((string) SITESSAVER_VERSION)
            ),
            esc_url($url),
            esc_html__('Deactivate the other copy', 'sitessaver')
        );
    };
    add_action('admin_notices', $sitessaver_copy_notice);
    add_action('network_admin_notices', $sitessaver_copy_notice);

    $sitessaver_copy_deactivate = static function () use ($sitessaver_copy, $sitessaver_copy_network): void {
        $requested = isset($_GET['plugin']) ? sanitize_text_field(wp_unslash($_GET['plugin'])) : '';
        if ($requested !== $sitessaver_copy) {
            return; // Another copy's request; that copy handles it.
        }
        $network = $sitessaver_copy_network();
        $allowed = $network
            ? is_network_admin() && current_user_can('manage_network_plugins')
            : current_user_can('activate_plugins');
        if (!$allowed) {
            wp_die(esc_html__('You do not have permission to deactivate plugins.', 'sitessaver'), '', ['response' => 403]);
        }
        check_admin_referer('sitessaver_deactivate_copy_' . $sitessaver_copy);
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        deactivate_plugins($sitessaver_copy, false, $network);
        wp_safe_redirect(($network ? network_admin_url('plugins.php') : admin_url('plugins.php')) . '?deactivate=true');
        exit;
    };
    add_action('admin_post_sitessaver_deactivate_copy', $sitessaver_copy_deactivate);
    add_action('network_admin_edit_sitessaver_deactivate_copy', $sitessaver_copy_deactivate);
    return;
}

// Plugin constants.
define('SITESSAVER_VERSION', $sitessaver_this_version);
define('SITESSAVER_FILE', __FILE__);
define('SITESSAVER_PATH', plugin_dir_path(__FILE__));
define('SITESSAVER_URL', plugin_dir_url(__FILE__));
define('SITESSAVER_SLUG', 'sitessaver');

// Storage paths, inside wp-content so plugin updates don't wipe backups.
define('SITESSAVER_STORAGE_DIR', WP_CONTENT_DIR . '/sitessaver-backups');
define('SITESSAVER_TEMP_DIR', SITESSAVER_STORAGE_DIR . '/tmp');

// Load helpers.
require_once SITESSAVER_PATH . 'includes/helpers.php';

// Autoloader.
spl_autoload_register(static function (string $class): void {
    $prefix = 'SitesSaver\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = SITESSAVER_PATH . 'includes/class-' . strtolower(str_replace(['\\', '_'], ['-', '-'], $relative)) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

add_action('plugins_loaded', static function (): void {
    SitesSaver\Plugin::instance()->init();

    // One-time cleanup: prior versions persisted export state as options.
    // Sweep any lingering rows now that state lives in transients.
    if (get_option('sitessaver_cleaned_export_options') !== '1') {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE 'sitessaver\\_export\\_%'
                OR option_name = 'sitessaver_active_export_id'"
        );
        update_option('sitessaver_cleaned_export_options', '1', false);
    }

    // One-time autoload correction: older versions stored several sizable
    // or sensitive options with autoload=yes (WP default). Flip them to
    // 'no' so they don't load on every page.
    if (get_option('sitessaver_autoload_migrated') !== '1') {
        global $wpdb;
        $targets = [
            'sitessaver_gdrive_token',
            'sitessaver_schedule_log',
            'sitessaver_backup_labels',
            'sitessaver_settings',
            'sitessaver_schedule',
        ];
        foreach ($targets as $name) {
            $wpdb->update(
                $wpdb->options,
                ['autoload' => 'no'],
                ['option_name' => $name]
            );
        }
        wp_cache_delete('alloptions', 'options');
        update_option('sitessaver_autoload_migrated', '1', false);
    }
});

// Activation hook: create storage directories.
register_activation_hook(__FILE__, static function (): void {
    // Hard requirements. Abort activation with a readable message if missing.
    if (version_compare(PHP_VERSION, '8.1', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html(sprintf(
                /* translators: %s: current PHP version */
                __('SitesSaver requires PHP 8.1 or newer. You are running PHP %s.', 'sitessaver'),
                PHP_VERSION
            )),
            esc_html__('Plugin Activation Failed', 'sitessaver'),
            ['back_link' => true]
        );
    }

    if (!class_exists('ZipArchive')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('SitesSaver requires the PHP ZipArchive extension. Ask your host to enable ext-zip and try again.', 'sitessaver'),
            esc_html__('Plugin Activation Failed', 'sitessaver'),
            ['back_link' => true]
        );
    }

    $dirs = [SITESSAVER_STORAGE_DIR, SITESSAVER_TEMP_DIR];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
    }

    // Protect backup directory from direct access (Apache 2.4 and 2.2 syntax;
    // Nginx hosts must add a location block manually, see README). Shared
    // with sitessaver_storage_dir()'s per-site subfolders on multisite so
    // the guard logic lives in exactly one place.
    sitessaver_protect_directory(SITESSAVER_STORAGE_DIR);
    sitessaver_protect_directory(SITESSAVER_TEMP_DIR);

    // Re-arm scheduled backups. Deactivation clears the cron events, so a
    // deactivate/reactivate cycle (which is what a plugin update does) would
    // otherwise leave the schedule enabled in the settings but never firing.
    //
    // sync_cron_events() rebuilds one event per selected frequency and clears
    // any stale ones first, so this is safe to run unconditionally.
    $schedule = get_option('sitessaver_schedule', []);
    if (is_array($schedule) && !empty($schedule['enabled'])) {
        \SitesSaver\Schedule::sync_cron_events(
            \SitesSaver\Schedule::selected_frequencies($schedule),
            true
        );
    }
});

// Deactivation hook: clear scheduled events.
register_deactivation_hook(__FILE__, static function (): void {
    if (class_exists(\SitesSaver\Background::class)) {
        \SitesSaver\Background::disarm_watchdog();
        \SitesSaver\Background::remove_rules();
    }

    // Each frequency is its own event (the frequency is passed as a cron
    // argument), and wp_clear_scheduled_hook() only clears events whose args
    // match. Passing an empty selection makes sync_cron_events() clear every
    // one of them, including the legacy no-arg event.
    if (class_exists(\SitesSaver\Schedule::class)) {
        \SitesSaver\Schedule::sync_cron_events([], false);
        return;
    }

    wp_clear_scheduled_hook('sitessaver_scheduled_backup');
});
