<?php
/**
 * Plugin Name: SitesSaver
 * Plugin URI:  https://github.com/sitessaver
 * Description: Full site backup & migration — export, import, schedule, Google Drive. No restrictions.
 * Version:     1.0.8
 * Author:      SitesSaver
 * Author URI:  https://github.com/sitessaver
 * License:     GPL-2.0-or-later
 * Text Domain: sitessaver
 * Requires PHP: 8.1
 * Requires at least: 6.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Plugin constants.
define('SITESSAVER_VERSION', '1.0.8');
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
});

// Activation hook — create storage directories.
register_activation_hook(__FILE__, static function (): void {
    $dirs = [SITESSAVER_STORAGE_DIR, SITESSAVER_TEMP_DIR];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
    }

    // Protect backup directory from direct access.
    $htaccess = SITESSAVER_STORAGE_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Order Deny,Allow\nDeny from all\n");
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
