<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * PHP features SitesSaver uses that a server may not have.
 *
 * Nothing here stops the plugin from loading or activating. A missing
 * feature switches off only what needs it, and the admin is told once,
 * on SitesSaver's own screens (and on the Plugins screen right after
 * activation), what is missing and what still works.
 */
final class Requirements {

    /** Set on activation, read once on the Plugins screen. */
    private const ACTIVATED_FLAG = 'sitessaver_activated_check';

    public static function init(): void {
        add_action('admin_notices', [self::class, 'render_notice']);
        add_action('network_admin_notices', [self::class, 'render_notice']);
    }

    /** Restore reads backup archives (ZipArchive) and swaps MySQL tables. */
    public static function can_restore(): bool {
        return self::restore_blocker() === '';
    }

    /** Why restore is off: 'zip', 'sqlite', or '' when it works. */
    public static function restore_blocker(): string {
        if (!class_exists('ZipArchive')) {
            return 'zip';
        }
        if (self::is_sqlite()) {
            return 'sqlite';
        }
        return '';
    }

    /**
     * The site runs on SQLite (the SQLite Database Integration drop-in, as
     * in WordPress Playground). A restore renames and swaps MySQL tables,
     * which SQLite does not support.
     */
    public static function is_sqlite(): bool {
        global $wpdb;
        if (defined('DB_ENGINE') && strtolower((string) constant('DB_ENGINE')) === 'sqlite') {
            return true;
        }
        if (defined('SQLITE_DB_DROPIN_VERSION')) {
            return true;
        }
        return is_object($wpdb) && is_a($wpdb, 'WP_SQLite_DB');
    }

    /**
     * Missing features, each with a plain explanation.
     *
     * @return array<string, string>
     */
    public static function missing(): array {
        $missing = [];
        if (self::is_sqlite()) {
            $missing['sqlite'] = __('This site stores its database in SQLite, so backups cannot be restored here: a restore swaps MySQL tables. Making, downloading and uploading backups still works.', 'sitessaver');
        }
        if (!class_exists('ZipArchive')) {
            $missing['zip'] = __('The PHP zip extension (ZipArchive) is not available on this server, so backups cannot be restored here. Making, downloading and uploading backups still works. Ask your host to enable the PHP zip extension.', 'sitessaver');
        }
        if (!function_exists('gzencode') || !function_exists('deflate_init')) {
            $missing['zlib'] = __('The PHP zlib extension is not available on this server, so backups are stored uncompressed and take more disk space. Ask your host to enable zlib.', 'sitessaver');
        }
        return $missing;
    }

    /** Called from the activation hook: no output, no checks that can fail. */
    public static function flag_activation(): void {
        set_site_transient(self::ACTIVATED_FLAG, 1, 10 * MINUTE_IN_SECONDS);
    }

    public static function render_notice(): void {
        if (!function_exists('get_current_screen') || !sitessaver_current_user_can()) {
            return;
        }
        $screen = get_current_screen();
        $id     = $screen !== null ? (string) $screen->id : '';
        $own    = str_contains($id, 'sitessaver');
        $plugins_screen = in_array($id, ['plugins', 'plugins-network'], true);

        if ($plugins_screen) {
            // Only once, right after activation.
            if (!get_site_transient(self::ACTIVATED_FLAG)) {
                return;
            }
            delete_site_transient(self::ACTIVATED_FLAG);
        } elseif (!$own) {
            return;
        }

        $missing = self::missing();
        if ($missing === []) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p><strong>'
            . esc_html__('SitesSaver: some features are not available on this server.', 'sitessaver')
            . '</strong></p>';
        foreach ($missing as $text) {
            echo '<p>' . esc_html($text) . '</p>';
        }
        echo '</div>';
    }
}
