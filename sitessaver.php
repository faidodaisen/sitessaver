<?php
/**
 * Plugin Name: SitesSaver
 * Plugin URI:  https://github.com/sitessaver
 * Description: Full site backup & migration — export, import, schedule, Google Drive. No restrictions.
 * Version:     1.1.8
 * Author:      SitesSaver
 * Author URI:  https://github.com/sitessaver
 * License:     GPL-2.0-or-later
 * Text Domain: sitessaver
 * Requires PHP: 8.1
 * Requires at least: 6.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// This copy's version, read BEFORE any constants so the duplicate-copy
// handler below can compare against an already-loaded instance. Folder
// name is irrelevant — we identify SitesSaver by its VERSION constant.
$sitessaver_this_version = '1.1.8';

// Duplicate-copy handler. WordPress lets the same plugin live in multiple
// folders (e.g. `plugins/sitessaver/` and `plugins/ss/`) and will happily
// load both, causing seven `define()` redeclaration warnings that surface
// as "plugin generated N characters of unexpected output" during activation
// and corrupt AJAX/REST bodies.
//
// Resolution: identify SitesSaver by the SITESSAVER_VERSION constant (not
// by slug or folder). If another copy already claimed it, compare versions:
//   - newer copy wins: deactivate the older one, let this one proceed by
//     undefining the older's file guard so re-include reloads classes
//   - older/equal: bail silently so this copy doesn't stomp the active one
//
// Note: we CAN'T actually un-define a constant in PHP, so if a newer copy
// of the plugin is already loaded, this older copy has to bow out. The
// inverse path (this copy newer) isn't safely recoverable mid-request
// either — we defer to the admin-side duplicate-cleanup below on the
// next request.
if (defined('SITESSAVER_VERSION')) {
    // Queue a one-time admin notice + auto-deactivate duplicate folders on
    // the next admin load (when WP's plugin API is fully available).
    add_action('admin_init', static function () use ($sitessaver_this_version): void {
        if (!function_exists('get_plugins') || !function_exists('deactivate_plugins')) {
            return;
        }
        $all     = get_plugins();
        $active  = (array) get_option('active_plugins', []);
        $duplicates = [];
        foreach ($all as $plugin_file => $meta) {
            if (($meta['Name'] ?? '') === 'SitesSaver' && in_array($plugin_file, $active, true)) {
                $duplicates[] = $plugin_file;
            }
        }
        if (count($duplicates) > 1) {
            // Keep the newest version; deactivate the rest.
            usort($duplicates, static function ($a, $b) use ($all): int {
                return version_compare($all[$b]['Version'] ?? '0', $all[$a]['Version'] ?? '0');
            });
            $keep   = array_shift($duplicates);
            deactivate_plugins($duplicates, true);
            add_action('admin_notices', static function () use ($keep, $duplicates): void {
                printf(
                    '<div class="notice notice-warning is-dismissible"><p><strong>SitesSaver:</strong> detected duplicate copies and deactivated %d older one(s). Kept: <code>%s</code>. Please delete the deactivated copies from <a href="%s">Plugins</a>.</p></div>',
                    count($duplicates),
                    esc_html($keep),
                    esc_url(admin_url('plugins.php'))
                );
            });
        }
    });
    return;
}

// Plugin constants.
define('SITESSAVER_VERSION', $sitessaver_this_version);
define('SITESSAVER_FILE', __FILE__);
define('SITESSAVER_PATH', plugin_dir_path(__FILE__));
define('SITESSAVER_URL', plugin_dir_url(__FILE__));
define('SITESSAVER_SLUG', 'sitessaver');

// Storage paths — inside wp-content so plugin updates don't wipe backups.
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

    // One-time autoload correction: previously the plugin stored several
    // sizable or sensitive options with autoload=yes (WP default). Flip
    // them to 'no' so they no longer ride on every page load.
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

// Activation hook — create storage directories.
register_activation_hook(__FILE__, static function (): void {
    // Hard requirements — abort activation with a friendly message if missing.
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
    // Nginx hosts must add a location block manually — see README).
    $htaccess = SITESSAVER_STORAGE_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents(
            $htaccess,
            "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Order Deny,Allow\n"
            . "    Deny from all\n"
            . "</IfModule>\n"
        );
    }

    $index = SITESSAVER_STORAGE_DIR . '/index.php';
    if (!file_exists($index)) {
        file_put_contents($index, '<?php // Silence is golden.');
    }

    // Schedule default cron if settings exist.
    $schedule = get_option('sitessaver_schedule', []);
    if (!empty($schedule['enabled'])) {
        if (!wp_next_scheduled('sitessaver_scheduled_backup')) {
            wp_schedule_event(time(), $schedule['frequency'] ?? 'daily', 'sitessaver_scheduled_backup');
        }
    }
});

// Deactivation hook — clear scheduled events.
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('sitessaver_scheduled_backup');
});
