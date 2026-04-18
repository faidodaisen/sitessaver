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
