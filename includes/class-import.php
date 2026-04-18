<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Import engine — restore full site from ZIP backup.
 */
final class Import {

    /**
     * Import from a backup file in storage.
     *
     * @param string $backup_file Backup filename (in storage dir).
     * @return array{success: bool, message: string}
     */
    public static function from_backup(string $backup_file): array {
        $zip_path = SITESSAVER_STORAGE_DIR . '/' . sanitize_file_name($backup_file);

        if (!file_exists($zip_path)) {
            return ['success' => false, 'message' => __('Backup file not found.', 'sitessaver')];
        }

        return self::run($zip_path);
    }

    /**
     * Import from an uploaded file.
     *
     * @param array $file $_FILES array element.
     * @return array{success: bool, message: string}
     */
    public static function from_upload(array $file): array {
        if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => __('Upload failed.', 'sitessaver')];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            return ['success' => false, 'message' => __('Only ZIP files are accepted.', 'sitessaver')];
        }

        // Verify ZIP magic bytes before touching disk — blocks .zip-renamed
        // executables / random binaries that passed the extension check.
        if (!self::is_zip_file($file['tmp_name'])) {
            return ['success' => false, 'message' => __('The uploaded file is not a valid ZIP archive.', 'sitessaver')];
        }

        // Stage into temp (NOT storage) so a failed import doesn't litter the
        // backups directory or overwrite an existing backup of the same name.
        if (!is_dir(SITESSAVER_TEMP_DIR)) {
            wp_mkdir_p(SITESSAVER_TEMP_DIR);
        }
        $staged = SITESSAVER_TEMP_DIR . '/incoming-' . wp_generate_password(8, false, false) . '.zip';
        if (!move_uploaded_file($file['tmp_name'], $staged)) {
            return ['success' => false, 'message' => __('Failed to save uploaded file.', 'sitessaver')];
        }

        try {
            $result = self::run($staged);

            // On success, archive a copy into storage with a unique filename
            // so the imported backup shows up in the Backups list. Do this
            // BEFORE cleanup so the staged file still exists.
            if (!empty($result['success'])) {
                $final_name = self::unique_backup_filename(sanitize_file_name($file['name']));
                $final_path = SITESSAVER_STORAGE_DIR . '/' . $final_name;
                @copy($staged, $final_path);
            }
        } finally {
            if (file_exists($staged)) {
                @unlink($staged);
            }
        }

        return $result;
    }

    /**
     * Return a storage-dir filename that doesn't clash with an existing backup.
     */
    private static function unique_backup_filename(string $filename): string {
        if ($filename === '' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
            $filename = 'imported-' . wp_generate_password(6, false, false) . '.zip';
        }

        $dest = SITESSAVER_STORAGE_DIR . '/' . $filename;
        if (!file_exists($dest)) {
            return $filename;
        }

        $base = pathinfo($filename, PATHINFO_FILENAME);
        return $base . '-' . wp_generate_password(4, false, false) . '.zip';
    }

    /**
     * Check the first 4 bytes for the ZIP local-file-header (PK\x03\x04) or
     * empty-archive (PK\x05\x06) magic.
     */
    private static function is_zip_file(string $path): bool {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = fread($handle, 4);
        fclose($handle);

        return $header === "PK\x03\x04" || $header === "PK\x05\x06";
    }

    /**
     * Run the import process.
     */
    private static function run(string $zip_path): array {
        $temp_dir = SITESSAVER_TEMP_DIR . '/import-' . wp_generate_password(8, false);

        try {
            // Opportunistic stale-only sweep (won't touch active export temps).
            sitessaver_cleanup_temp();
            wp_mkdir_p($temp_dir);

            // 1. Extract archive.
            if (!Archive::extract($zip_path, $temp_dir)) {
                throw new \RuntimeException(__('Failed to extract backup archive.', 'sitessaver'));
            }

            // 2. Read and validate manifest.
            $manifest = self::read_manifest($temp_dir);
            if ($manifest === null) {
                throw new \RuntimeException(__('Invalid backup: manifest.json not found.', 'sitessaver'));
            }
            if (!self::is_sitessaver_manifest($manifest)) {
                throw new \RuntimeException(__('This ZIP is not a SitesSaver backup. Please upload a file created by the SitesSaver Export tool.', 'sitessaver'));
            }

            // 3. Restore database.
            $db_file = $temp_dir . '/database.sql';
            if (file_exists($db_file)) {
                $old_url = $manifest['home_url'] ?? '';
                $new_url = home_url();

                if (!Database::import($db_file, $old_url, $new_url)) {
                    throw new \RuntimeException(__('Failed to import database.', 'sitessaver'));
                }
            }

            // 4. Restore wp-content files.
            $content_src = $temp_dir . '/wp-content';
            if (is_dir($content_src)) {
                self::restore_content($content_src);
            }

            // 5. Post-import tasks.
            self::post_import($manifest);

            // 6. Cleanup — scoped to THIS import only.
            sitessaver_cleanup_temp($temp_dir);

            do_action('sitessaver_import_complete', basename($zip_path));

            return [
                'success' => true,
                'message' => __('Site restored successfully. Please log in again.', 'sitessaver'),
            ];

        } catch (\Throwable $e) {
            sitessaver_cleanup_temp($temp_dir);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Read the manifest.json from extracted backup.
     */
    private static function read_manifest(string $dir): ?array {
        $file = $dir . '/manifest.json';

        if (!file_exists($file)) {
            return null;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Verify a decoded manifest was produced by SitesSaver Export.
     * Guards against random ZIPs whose root happens to contain a manifest.json.
     */
    private static function is_sitessaver_manifest(array $manifest): bool {
        return ($manifest['plugin'] ?? '') === 'SitesSaver'
            && !empty($manifest['version'])
            && !empty($manifest['site_url']);
    }

    /**
     * Restore wp-content directories from extracted backup.
     */
    private static function restore_content(string $content_src): void {
        // Per-destination skip lists. The critical one is `plugins`: we MUST
        // NOT overwrite the currently-running SitesSaver plugin files. Prior
        // to 1.1.7 the export filter was broken (fnmatch didn't recurse) so
        // old backups contain a `sitessaver/` folder. Without this guard,
        // restore copies those older files over the live plugin — which is
        // why every attempted bugfix in 1.1.5 / 1.1.6 appeared to revert
        // immediately after the user tested a restore. We block both the
        // running plugin's own slug and any well-known alternate casing.
        $running_plugin_dir = basename(dirname(SITESSAVER_FILE));
        $dirs = [
            'uploads'    => ['dest' => WP_CONTENT_DIR . '/uploads', 'skip' => []],
            'plugins'    => ['dest' => WP_PLUGIN_DIR,               'skip' => [$running_plugin_dir]],
            'themes'     => ['dest' => get_theme_root(),            'skip' => []],
            'mu-plugins' => ['dest' => WPMU_PLUGIN_DIR,             'skip' => []],
        ];

        foreach ($dirs as $name => $cfg) {
            $src = $content_src . '/' . $name;

            if (!is_dir($src)) {
                continue;
            }

            // Merge files (overwrite existing, keep extras). Skip list is
            // matched against the FIRST path segment so the entire subtree
            // is safely ignored.
            self::merge_directory($src, $cfg['dest'], $cfg['skip']);
        }

        // Flush page-builder CSS caches. Why: Breakdance, Elementor, Oxygen,
        // and Bricks all compile per-post CSS files to `wp-content/uploads/<builder>/css/`
        // with ABSOLUTE URLs embedded for background-image, font-face, etc.
        // After a cross-domain or cross-scheme restore, those compiled CSS
        // files still reference the old domain — so images in the builder's
        // own stylesheet output render broken even if the DB is correct.
        // Deleting the cache forces each builder to regenerate on first page
        // view using the newly-restored URLs. Full-page caches under
        // `wp-content/cache/` are also wiped (W3TC, WP Rocket, LiteSpeed).
        self::flush_builder_caches();

        // Ensure modern image MIME types are served correctly from uploads.
        //
        // Why this matters: on stock Apache (especially Laragon / shared hosts
        // with old mime.types) `.webp` / `.avif` / `.svg` are not in the
        // server's default MIME map. The file exists after restore but Apache
        // serves it with `application/octet-stream`, and browsers refuse to
        // render it as an image. Users see "some images broken" where WebP
        // variants fail while JPG/PNG render (both have first-class MIME
        // registrations since the Apache dawn of time). We drop a minimal
        // .htaccess into the uploads root that declares the modern types.
        self::ensure_uploads_mime_htaccess();

        // Best-effort opcache invalidation. If PHP overwrote any PHP files
        // in this request, the opcode cache still holds the pre-overwrite
        // bytecode until a PHP-FPM restart. A mid-request opcache_reset()
        // forces a fresh load on the very next request.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * Flush compiled CSS caches from known page builders + full-page caches.
     *
     * Builders embed absolute URLs in their compiled stylesheets. After a
     * cross-domain or cross-scheme migration those CSS files are stale
     * (still reference the source domain), so images, background URLs, and
     * icon fonts break until every affected post is re-saved in the builder
     * or the cache is manually cleared. Clearing here runs once at restore
     * finalisation — each builder regenerates on first front-end view.
     *
     * Full-page caches (W3 Total Cache, WP Rocket, LiteSpeed) cache HTML
     * that embeds absolute URLs too, so those get wiped alongside.
     *
     * Best-effort: missing dirs are ignored, unlink failures are logged but
     * don't block finalisation.
     */
    private static function flush_builder_caches(): void {
        $upload_dir = wp_upload_dir();
        $base       = $upload_dir['basedir'] ?? '';

        $candidates = [];
        if ($base !== '') {
            $candidates = array_merge($candidates, [
                // Breakdance compiled CSS.
                $base . '/breakdance/css',
                // Elementor compiled CSS + Google Fonts cache.
                $base . '/elementor/css',
                // Oxygen builder CSS cache.
                $base . '/oxygen/css-cache',
                // Bricks builder CSS.
                $base . '/bricks/css',
                // WP Rocket min/css/js (handled by plugin normally but can linger).
                $base . '/cache/min',
            ]);
        }

        // Top-level full-page cache dirs (most cache plugins drop here).
        $candidates[] = WP_CONTENT_DIR . '/cache';
        // LiteSpeed + WP Fastest Cache common location.
        $candidates[] = WP_CONTENT_DIR . '/litespeed';

        $total_deleted = 0;
        foreach ($candidates as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $total_deleted += self::delete_dir_contents($dir);
        }

        if ($total_deleted > 0) {
            error_log('[SitesSaver] Flushed ' . $total_deleted . ' cached file(s) from builder / page caches after restore.');
        }
    }

    /**
     * Delete every file (and empty subdirs) under $dir, leaving $dir itself
     * in place. Returns the count of files removed.
     */
    private static function delete_dir_contents(string $dir): int {
        $count = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } elseif (@unlink($file->getPathname())) {
                    $count++;
                }
            }
        } catch (\Throwable $e) {
            error_log('[SitesSaver] delete_dir_contents failed on ' . $dir . ': ' . $e->getMessage());
        }
        return $count;
    }

    /**
     * Write a minimal .htaccess to wp-content/uploads/ that registers modern
     * image MIME types (webp/avif/svg). Idempotent — if a SitesSaver block
     * is already present we leave the file alone; if the file doesn't exist
     * or doesn't contain our marker we append our block.
     *
     * Harmless on Nginx (Nginx ignores .htaccess). On Apache shared hosts
     * without `AllowOverride FileInfo` this is also harmless — directives
     * are ignored but nothing breaks.
     */
    private static function ensure_uploads_mime_htaccess(): void {
        $upload_dir = wp_upload_dir();
        if (empty($upload_dir['basedir'])) {
            return;
        }

        $htaccess_path = $upload_dir['basedir'] . '/.htaccess';
        $marker        = '# BEGIN SitesSaver-MIME';
        $block         = $marker . "\n"
            . "<IfModule mod_mime.c>\n"
            . "    AddType image/webp .webp\n"
            . "    AddType image/avif .avif\n"
            . "    AddType image/svg+xml .svg .svgz\n"
            . "</IfModule>\n"
            . "# END SitesSaver-MIME\n";

        $existing = file_exists($htaccess_path) ? (string) @file_get_contents($htaccess_path) : '';
        if (str_contains($existing, $marker)) {
            return;
        }

        $new_contents = $existing === '' ? $block : rtrim($existing) . "\n\n" . $block;
        @file_put_contents($htaccess_path, $new_contents);
    }

    /**
     * Recursively merge source into destination (overwrite conflicts), with
     * an optional first-path-segment skip list.
     *
     * @param string[] $skip_roots First-segment names to skip (e.g. ['sitessaver']).
     */
    private static function merge_directory(string $source, string $dest, array $skip_roots = []): void {
        if (!is_dir($dest)) {
            wp_mkdir_p($dest);
        }

        $iterator = new \RecursiveDirectoryIterator(
            $source,
            \RecursiveDirectoryIterator::SKIP_DOTS
        );

        $files = new \RecursiveIteratorIterator(
            $iterator,
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $failures = 0;
        foreach ($files as $file) {
            $relative  = str_replace($source, '', $file->getPathname());
            $relative  = ltrim(str_replace('\\', '/', $relative), '/');
            $dest_path = $dest . '/' . $relative;

            // First-segment skip — blocks the entire subtree.
            if (!empty($skip_roots)) {
                $first = strtok($relative, '/');
                if (in_array($first, $skip_roots, true)) {
                    continue;
                }
            }

            if ($file->isDir()) {
                if (!is_dir($dest_path)) {
                    wp_mkdir_p($dest_path);
                }
            } else {
                $parent = dirname($dest_path);
                if (!is_dir($parent)) {
                    wp_mkdir_p($parent);
                }

                // No error-suppression. Silent @copy() failures were masking
                // partial restores — on Windows, long paths (>260 chars),
                // reserved filenames (CON, PRN, NUL…), and unusual UTF-8 byte
                // sequences cause copy() to return false. Users previously
                // saw "some images broken" (typically WebP variants from
                // image-optimizer plugins that nest deep paths like
                // cache/breakdance-image-optimizer/2024/01/long-name.jpg.webp)
                // because the copy silently failed and the referencing row
                // in the DB was fine but pointed at a missing file. We now
                // track failures and log a summary so the admin can see
                // exactly which files couldn't be restored.
                if (!copy($file->getPathname(), $dest_path)) {
                    $failures++;
                    if ($failures <= 50) {
                        error_log(sprintf(
                            '[SitesSaver] merge_directory: copy failed for %s (path length %d) — source: %s',
                            $relative,
                            strlen($dest_path),
                            $file->getPathname()
                        ));
                    }
                }
            }
        }

        if ($failures > 0) {
            error_log(sprintf(
                '[SitesSaver] merge_directory: %d file(s) failed to copy into %s. See earlier log lines for paths.',
                $failures,
                $dest
            ));
        }
    }

    /**
     * Post-import cleanup.
     *
     * Why this is split in two:
     *   During restore we're mid-AJAX with the OLD plugin/theme set loaded in
     *   memory and `init` already fired. Calling switch_theme() /
     *   update_option('active_plugins') / flush_rewrite_rules() here forces
     *   the NEW plugin set to boot inside the same request, which triggers
     *   "textdomain loaded too early" notices (Elementor, Landinghub, etc.)
     *   and can leave rewrite rules half-stale.
     *
     *   Mirroring the All-in-One WP Migration UX: we just queue a flag here,
     *   the UI forces the user to log out + log back in, and the deferred
     *   work runs on the first clean admin request via `admin_init`. The
     *   user is then parked on Settings > Permalinks and asked to save twice
     *   to flush rewrite rules.
     */
    private static function post_import(array $manifest): void {
        global $wpdb;

        // Transients: raw SQL, no hook side effects.
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'");

        // Generate a one-time finalize token. Why: after the database restore,
        // wp_users / auth salts / capabilities come from the BACKUP, but the
        // browser's cookie was issued against the PRE-restore DB. That means
        // the normal nonce + `current_user_can('manage_options')` checks in
        // sitessaver_verify_ajax() can silently fail on the very next AJAX
        // call (finalize_restore) — the user is effectively a stranger to the
        // new DB. Instead we hand the client a short-lived random token,
        // persist it alongside the pending state, and accept it in place of
        // the standard nonce for finalize_restore only. Scope is tight: the
        // option is deleted immediately after consumption.
        $finalize_token = wp_generate_password(32, false, false);

        // Queue deferred finalisation. Autoload = no so it doesn't ride on
        // every admin page until it's consumed.
        update_option(
            'sitessaver_pending_finalize',
            [
                'active_plugins' => $manifest['active_plugins'] ?? null,
                'active_theme'   => $manifest['active_theme']   ?? null,
                'queued_at'      => time(),
                'token'          => $finalize_token,
            ],
            false
        );

        // Stash the token in a transient that the JS can read via the
        // localized SitesSaver object on the NEXT admin page load. But the
        // user won't reload — they're looking at the restore-complete modal
        // right now. So we also pass it back through the current AJAX response
        // chain (see Ajax::handle_import return shape).
        set_transient('sitessaver_finalize_token', $finalize_token, HOUR_IN_SECONDS);
    }

    /**
     * Expose the active finalize token so AJAX handlers can return it to the
     * client as part of the import-success payload.
     */
    public static function current_finalize_token(): string {
        $pending = get_option('sitessaver_pending_finalize', null);
        if (is_array($pending) && !empty($pending['token'])) {
            return (string) $pending['token'];
        }
        $fallback = get_transient('sitessaver_finalize_token');
        return is_string($fallback) ? $fallback : '';
    }

    /**
     * Build the finalize redirect target the client should navigate to after
     * a successful restore. The URL points at wp-login.php with a redirect_to
     * that lands on options-permalink.php carrying the one-time token.
     *
     * Why this shape: the browser's existing auth cookie is signed by the
     * PRE-restore DB. It will not validate against the restored wp_users +
     * auth salts, so wp-login.php shows the login prompt. When the user
     * re-auths with the backup's credentials, WP redirects to the
     * redirect_to target — where Admin::handle_post_import_finalisation
     * runs the deferred work under a clean, freshly-authenticated session.
     */
    public static function build_finalize_redirect_url(): string {
        $token = self::current_finalize_token();

        // Force fresh reads of siteurl/home. After restore these options
        // contain the CORRECT (restored) domain, but the current request's
        // alloptions cache still holds the pre-restore values. Without this
        // the returned login URL points at the OLD domain, the browser
        // redirects there, and the user never lands on the new site to
        // complete finalisation.
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('siteurl', 'options');
        wp_cache_delete('home', 'options');

        if ($token === '') {
            return wp_login_url();
        }

        $permalinks = add_query_arg(
            'sitessaver_finalize',
            $token,
            admin_url('options-permalink.php')
        );

        return wp_login_url($permalinks);
    }

    /**
     * Run the deferred finalisation on a fresh admin request.
     *
     * Hooked to `admin_init` (see Plugin::init). Safe to call switch_theme()
     * and update_option('active_plugins') here because `init` hasn't fired
     * yet in this request — when it does, the restored plugin set boots
     * normally without the too-early textdomain warnings.
     */
    public static function run_deferred_finalisation(): void {
        // Flush BEFORE reading the pending option. On sites with persistent
        // object caches (Redis, Memcached, WP Super Cache) the current
        // request's in-memory alloptions set was populated from the PRE-
        // restore DB — get_option() would hit that cache and miss the
        // freshly-written pending-finalize row. Flushing first forces a
        // fresh DB read on the very next call.
        wp_cache_flush();

        $pending = get_option('sitessaver_pending_finalize', null);
        if (!is_array($pending)) {
            return;
        }
        delete_option('sitessaver_pending_finalize');
        delete_transient('sitessaver_finalize_token');

        if (!empty($pending['active_plugins']) && is_array($pending['active_plugins'])) {
            update_option('active_plugins', $pending['active_plugins']);
        }

        // switch_theme() fires after_switch_theme + setup_theme action hooks.
        // A restored theme with a fatal error there would 500 the admin page.
        // Guard so finalisation still completes (permalinks counter gets set,
        // banner shows, user can proceed) even when the restored theme is
        // broken — they can then switch themes from Appearance after the
        // permalinks round-trip. The error is logged for diagnosis.
        if (!empty($pending['active_theme'])) {
            try {
                $theme = wp_get_theme($pending['active_theme']);
                if ($theme->exists()) {
                    switch_theme($pending['active_theme']);
                }
            } catch (\Throwable $e) {
                error_log('[SitesSaver] switch_theme failed during finalisation: ' . $e->getMessage());
            }
        }

        wp_cache_flush();

        // Counter — starts at 2 (user must save Permalinks twice). Admin
        // decrements it on each ?settings-updated=true round-trip. When it
        // hits zero the rewrite rules are considered flushed and the
        // restore banner disappears.
        set_transient('sitessaver_needs_permalinks_flush', 2, DAY_IN_SECONDS);
    }
}
