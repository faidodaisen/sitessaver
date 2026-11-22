<?php
/**
 * Fires when the plugin is uninstalled (deleted from the Plugins screen).
 *
 * Removes every sitessaver_* option, transient, and user meta row; unschedules
 * cron; and best-effort revokes the Google Drive refresh token through the
 * proxy. Backup files in the storage folder (by default
 * wp-content/sitessaver-backups/) are left intact
 * on purpose, since users may want to keep their backups. They can delete that
 * folder manually if desired.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-GB backup archives, SQL dumps and chunked uploads with byte offsets, file locks and atomic renames inside the plugin's own backup folder; WP_Filesystem has no streaming, seek or lock API and may need FTP credentials that a background worker cannot supply.

global $wpdb;

// 1. The LiteSpeed rule, if the admin added it from the Help page.
if (!function_exists('get_home_path')) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}
$sitessaver_ht = get_home_path() . '.htaccess';
if (is_file($sitessaver_ht) && is_writable($sitessaver_ht)
    && strpos((string) file_get_contents($sitessaver_ht), '# BEGIN SitesSaver') !== false) {
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    insert_with_markers($sitessaver_ht, 'SitesSaver', []);
}

/**
 * Google Drive token (best-effort revoke through the proxy), cron events,
 * options and transients of the current site.
 *
 * Scheduled backups register one event per frequency, with the frequency
 * key passed as a cron argument; wp_clear_scheduled_hook() only removes
 * events whose args match, so each one is cleared by name.
 */
$sitessaver_clean_site = static function () {
    global $wpdb;

    $token = get_option('sitessaver_gdrive_token');
    if (is_array($token) && !empty($token['refresh_token'])) {
        wp_remote_post('https://api.sitessaver.com/v1/gdrive/revoke', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['refresh_token' => $token['refresh_token']]),
            'timeout' => 10,
        ]);
    }

    wp_clear_scheduled_hook('sitessaver_scheduled_backup');
    wp_clear_scheduled_hook('sitessaver_export_watchdog');
    wp_clear_scheduled_hook('sitessaver_storage_probe');
    foreach (['hourly', 'twicedaily', 'daily', 'weekly', 'sitessaver_monthly'] as $frequency) {
        wp_clear_scheduled_hook('sitessaver_scheduled_backup', [$frequency]);
    }

    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE 'sitessaver\\_%'
            OR option_name LIKE '\\_transient\\_sitessaver\\_%'
            OR option_name LIKE '\\_transient\\_timeout\\_sitessaver\\_%'
            OR option_name LIKE '\\_site\\_transient\\_sitessaver\\_%'
            OR option_name LIKE '\\_site\\_transient\\_timeout\\_sitessaver\\_%'"
    );
    wp_cache_delete('alloptions', 'options');
};

// 2. Every site of a network, or this site.
if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $sitessaver_blog_id) {
        switch_to_blog((int) $sitessaver_blog_id);
        $sitessaver_clean_site();
        restore_current_blog();
    }
    $wpdb->query(
        "DELETE FROM {$wpdb->sitemeta}
         WHERE meta_key LIKE 'sitessaver\\_%'
            OR meta_key LIKE '\\_site\\_transient\\_sitessaver\\_%'
            OR meta_key LIKE '\\_site\\_transient\\_timeout\\_sitessaver\\_%'"
    );
} else {
    $sitessaver_clean_site();
}

// 3. User meta the plugin wrote (all users).
delete_metadata('user', 0, 'sitessaver_dismissed_storage_notice', '', true);

// 4. Remove the plugin's own bookkeeping: troubleshooting log files, restore
//    job state and work files of unfinished jobs. These are not backups,
//    nothing a user would want to keep. Multisite subsites keep theirs under
//    site-<id>/.
//    On PHP older than 8.1 SitesSaver never ran, so there is nothing to remove.
$sitessaver_roots = array();
if (version_compare(PHP_VERSION, '8.1', '>=')) {
    require_once plugin_dir_path(__FILE__) . 'includes/helpers.php';
    $sitessaver_roots[] = sitessaver_storage_root();
}
foreach ($sitessaver_roots ? (array) glob($sitessaver_roots[0] . '/site-*', GLOB_ONLYDIR) : array() as $sitessaver_sub_root) {
    if (is_string($sitessaver_sub_root)) {
        $sitessaver_roots[] = $sitessaver_sub_root;
    }
}
foreach ($sitessaver_roots as $sitessaver_root) {
    foreach (['logs', 'jobs'] as $sitessaver_sub) {
        $sitessaver_dir = $sitessaver_root . '/' . $sitessaver_sub;
        if (is_dir($sitessaver_dir)) {
            foreach ((array) @scandir($sitessaver_dir) as $sitessaver_f) {
                if (is_string($sitessaver_f) && is_file($sitessaver_dir . '/' . $sitessaver_f)) {
                    @unlink($sitessaver_dir . '/' . $sitessaver_f);
                }
            }
            @rmdir($sitessaver_dir);
        }
    }
    if (is_dir($sitessaver_root . '/tmp')) {
        sitessaver_rm_recursive($sitessaver_root . '/tmp');
    }
}
