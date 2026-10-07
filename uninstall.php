<?php
/**
 * Fires when the plugin is uninstalled (deleted from the Plugins screen).
 *
 * Removes every sitessaver_* option, transient, and user meta row; unschedules
 * cron; and best-effort revokes the Google Drive refresh token through the
 * proxy. Backup files on disk (wp-content/sitessaver-backups/) are left intact
 * by design — users may want to keep their backups. They can delete that
 * folder manually if desired.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// 1. Best-effort revoke of the Google Drive refresh token via the proxy.
$token_data = get_option('sitessaver_gdrive_token');
if (is_array($token_data) && !empty($token_data['refresh_token'])) {
    wp_remote_post('https://api.sitessaver.com/v1/gdrive/revoke', [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode(['refresh_token' => $token_data['refresh_token']]),
        'timeout' => 10,
    ]);
}

// 2. Clear any scheduled cron events.
//
// Scheduled backups register one event per frequency, with the frequency key
// passed as a cron argument. wp_clear_scheduled_hook() only removes events
// whose args match, so the no-arg call alone would strand every per-frequency
// event in the cron array and leave WordPress trying to fire a hook that no
// longer has a listener.
wp_clear_scheduled_hook('sitessaver_scheduled_backup');
wp_clear_scheduled_hook('sitessaver_export_watchdog');

// The LiteSpeed background-worker rule SitesSaver added to .htaccess.
$sitessaver_ht = (function_exists('get_home_path') ? get_home_path() : ABSPATH) . '.htaccess';
if (is_file($sitessaver_ht) && is_writable($sitessaver_ht)) {
    $sitessaver_text = (string) file_get_contents($sitessaver_ht);
    if (str_contains($sitessaver_text, '# BEGIN SitesSaver')) {
        $sitessaver_clean = preg_replace('/\R?# BEGIN SitesSaver\R.*?# END SitesSaver\R?/s', "\n", $sitessaver_text);
        if (is_string($sitessaver_clean)) {
            file_put_contents($sitessaver_ht, ltrim($sitessaver_clean, "\r\n"), LOCK_EX);
        }
    }
}
foreach (['hourly', 'twicedaily', 'daily', 'weekly', 'sitessaver_monthly'] as $sitessaver_frequency) {
    wp_clear_scheduled_hook('sitessaver_scheduled_backup', [$sitessaver_frequency]);
}

// 3. Delete every sitessaver_* option and transient in one sweep.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE 'sitessaver\\_%'
        OR option_name LIKE '\\_transient\\_sitessaver\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_sitessaver\\_%'
        OR option_name LIKE '\\_site\\_transient\\_sitessaver\\_%'
        OR option_name LIKE '\\_site\\_transient\\_timeout\\_sitessaver\\_%'"
);

// 4. Flush the alloptions cache so the removed rows disappear immediately.
wp_cache_delete('alloptions', 'options');

// 5. Remove the plugin's own bookkeeping (troubleshooting log, restore job
//    records). These are not backups — nothing a user would want to keep.
$sitessaver_storage = WP_CONTENT_DIR . '/sitessaver-backups';
foreach (['logs', 'jobs'] as $sitessaver_sub) {
    $sitessaver_dir = $sitessaver_storage . '/' . $sitessaver_sub;
    if (is_dir($sitessaver_dir)) {
        foreach ((array) @scandir($sitessaver_dir) as $sitessaver_f) {
            if (is_string($sitessaver_f) && is_file($sitessaver_dir . '/' . $sitessaver_f)) {
                @unlink($sitessaver_dir . '/' . $sitessaver_f);
            }
        }
        @rmdir($sitessaver_dir);
    }
}
