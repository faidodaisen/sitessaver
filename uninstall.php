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
wp_clear_scheduled_hook('sitessaver_scheduled_backup');

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
