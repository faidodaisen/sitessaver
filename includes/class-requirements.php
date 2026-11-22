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

    /** Restore reads backup archives, which needs ZipArchive. */
    public static function can_restore(): bool {
        return class_exists('ZipArchive');
    }

    /**
     * Missing features, each with a plain explanation.
     *
     * @return array<string, string>
     */
    public static function missing(): array {
        $missing = [];
        if (!self::can_restore()) {
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
