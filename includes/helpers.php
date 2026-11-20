<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-GB backup archives, SQL dumps and chunked uploads with byte offsets, file locks and atomic renames inside the plugin's own backup folder; WP_Filesystem has no streaming, seek or lock API and may need FTP credentials that a background worker cannot supply.

/**
 * Format file size to human-readable string.
 */
function sitessaver_format_size(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i     = 0;
    $size  = (float) $bytes;

    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }

    return round($size, 2) . ' ' . $units[$i];
}

/**
 * Get the max upload size (respects PHP and WP limits).
 */
function sitessaver_max_upload_size(): int {
    $php_max  = wp_max_upload_size();
    $wp_limit = (int) get_option('sitessaver_max_upload', 0);

    // 0 = unlimited (respect PHP only).
    return $wp_limit > 0 ? min($php_max, $wp_limit) : $php_max;
}

/**
 * Generate a unique backup filename.
 */
function sitessaver_backup_filename(string $ext = 'zip'): string {
    $site = sanitize_file_name(wp_parse_url(home_url(), PHP_URL_HOST) ?? 'site');
    $date = gmdate('Ymd-His');

    // 16 random characters (~95 bits): even where the backup folder can be
    // reached over the web (Nginx ignores .htaccess), a backup's name cannot
    // be guessed from the site name and a rough date.
    return sprintf('%s-%s-%s.%s', $site, $date, wp_generate_password(16, false), $ext);
}

/**
 * Absolute path to the backup storage directory for the CURRENT site.
 *
 * On a single-site install this is identical to SITESSAVER_STORAGE_DIR, so
 * existing installs are byte-for-byte unaffected.
 *
 * On multisite, SITESSAVER_STORAGE_DIR used to be one network-wide folder
 * with no per-site scoping: sitessaver_get_backups() globbed it directly,
 * so any subsite admin could see, download, and DELETE another subsite's
 * backups. This namespaces storage by blog ID so each site only ever sees
 * its own archives. The directory is created (and protected with the same
 * .htaccess/index.php guard the activation hook uses) lazily on first call,
 * since WordPress only runs register_activation_hook() for sites that
 * existed at activation time — a subsite created afterwards would otherwise
 * never get a protected folder.
 */
function sitessaver_storage_dir(): string {
    if (!is_multisite()) {
        return SITESSAVER_STORAGE_DIR;
    }

    $dir = SITESSAVER_STORAGE_DIR . '/site-' . get_current_blog_id();

    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
        sitessaver_protect_directory($dir);
    }

    return $dir;
}

/**
 * Write the dual-syntax .htaccess, a web.config (IIS) and the index.php guard into a directory, if not
 * already present. Idempotent. Shared by the activation hook (network-wide
 * SITESSAVER_STORAGE_DIR) and sitessaver_storage_dir() (per-site subfolders
 * on multisite), so the two never drift out of sync.
 */
function sitessaver_protect_directory(string $dir): void {
    $htaccess = $dir . '/.htaccess';
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

    // IIS ignores .htaccess; web.config is its equivalent deny rule.
    $webconfig = $dir . '/web.config';
    if (!file_exists($webconfig)) {
        file_put_contents(
            $webconfig,
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<configuration>\n"
            . "  <system.webServer>\n"
            . "    <authorization>\n"
            . "      <deny users=\"*\" />\n"
            . "    </authorization>\n"
            . "  </system.webServer>\n"
            . "</configuration>\n"
        );
    }

    $index = $dir . '/index.php';
    if (!file_exists($index)) {
        file_put_contents($index, '<?php // Silence is golden.');
    }
}

/**
 * Capability needed to use SitesSaver at all (pages, AJAX, downloads).
 *
 * Single site: manage_options, i.e. an administrator — unchanged.
 *
 * Multisite: manage_network_options, i.e. a super admin. A restore writes
 * plugins, themes and mu-plugins and replays the backup's SQL; an export
 * packs the network's plugin code. Those are network-level powers. A subsite
 * administrator holds manage_options on their own site but may not install
 * plugins or touch network tables, so with manage_options they could restore
 * a crafted backup (an mu-plugin, or SQL against wp_users / wp_sitemeta) and
 * take over every site on the network.
 */
function sitessaver_capability(): string {
    $cap = is_multisite() ? 'manage_network_options' : 'manage_options';
    return (string) apply_filters('sitessaver_capability', $cap);
}

/** Can the current user use SitesSaver? */
function sitessaver_current_user_can(): bool {
    return current_user_can(sitessaver_capability());
}

/**
 * Name to store an uploaded or downloaded backup under.
 *
 * Backups SitesSaver writes carry a random tail (see
 * sitessaver_backup_filename()) so they cannot be fetched by guessing the
 * URL where the folder is web-readable (Nginx ignores .htaccess). A file
 * that comes in from outside (upload, Google Drive download) has a name
 * someone else chose, so it ALWAYS gets a fresh random tail of its own,
 * even when it already looks like a SitesSaver name.
 *
 * Incremental backups name the other files of their chain in their
 * manifest by the ORIGINAL names. sitessaver_resolve_chain_member() finds a
 * renamed member again from that name: "<original name>-<16 random>.zip".
 */
function sitessaver_safe_stored_name(string $filename): string {
    $filename = sanitize_file_name($filename);
    if ($filename === '' || strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
        $filename = 'imported.zip';
    }
    return pathinfo($filename, PATHINFO_FILENAME) . '-' . wp_generate_password(16, false, false) . '.zip';
}

/**
 * sitessaver_safe_stored_name(), retried until the name is free in the
 * storage folder. Used for every backup that arrives from outside
 * (browser upload, chunked upload, Google Drive download).
 */
function sitessaver_unique_stored_name(string $filename): string {
    $dir  = sitessaver_storage_dir();
    $name = sitessaver_safe_stored_name($filename);
    for ($try = 0; $try < 5 && file_exists($dir . '/' . $name); $try++) {
        $name = sitessaver_safe_stored_name($filename);
    }
    return $name;
}

/**
 * The stored file for a chain member named in a backup's manifest.
 *
 * Exact name first (backups this site made itself). Otherwise a file that
 * was stored under that name plus a random tail by
 * sitessaver_safe_stored_name(). When the same backup was brought in more
 * than once, the copy whose manifest belongs to $chain_id wins, else the
 * newest. Works from the folder alone: no option is involved, so it still
 * works after a reinstall or after a restore replaced the database.
 *
 * @return string|null Stored file name, or null when no such file exists.
 */
function sitessaver_resolve_chain_member(string $member, string $chain_id = ''): ?string {
    $member = sanitize_file_name($member);
    if ($member === '' || strtolower((string) pathinfo($member, PATHINFO_EXTENSION)) !== 'zip') {
        return null;
    }
    $dir = sitessaver_storage_dir();
    if (is_file($dir . '/' . $member)) {
        return $member;
    }

    $stem       = (string) pathinfo($member, PATHINFO_FILENAME);
    $candidates = [];
    $files      = glob($dir . '/*.zip');
    foreach (is_array($files) ? $files : [] as $f) {
        $base = basename($f);
        // One tail per hop: a backup brought in twice carries two.
        if (preg_match('/^' . preg_quote($stem, '/') . '(?:-[A-Za-z0-9]{16})+\.zip$/', $base)) {
            $candidates[$base] = (int) @filemtime($f);
        }
    }
    if ($candidates === []) {
        return null;
    }
    arsort($candidates);
    if (count($candidates) > 1 && $chain_id !== '' && class_exists('ZipArchive')) {
        foreach (array_keys($candidates) as $base) {
            $zip = new ZipArchive();
            if ($zip->open($dir . '/' . $base) !== true) {
                continue;
            }
            $raw = $zip->getFromName('manifest.json');
            $zip->close();
            $m = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($m) && (string) ($m['chain_id'] ?? '') === $chain_id) {
                return $base;
            }
        }
    }
    return (string) array_key_first($candidates);
}

/**
 * SSL check for requests this site sends to itself (background workers and
 * the storage probe). Only those loopbacks use it. Off by default like core's
 * own loopbacks, because many sites have a self-signed or mismatched
 * certificate on their own host name. Same filter core uses.
 */
function sitessaver_loopback_sslverify(): bool {
    return (bool) apply_filters('https_local_ssl_verify', false); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
}

/**
 * Who is emailed when a backup started from the Export page finishes while
 * nobody has the page open. '' = notifications are switched off.
 *
 * Option sitessaver_export_notify: ['enabled' => '1'|'0', 'email' => string]
 * (an empty email means the site admin email, the behaviour before 1.5.4).
 */
function sitessaver_export_notify_email(): string {
    $opt = get_option('sitessaver_export_notify', []);
    $opt = is_array($opt) ? $opt : [];
    if (($opt['enabled'] ?? '1') === '0') {
        return '';
    }
    $email = (string) ($opt['email'] ?? '');
    return is_email($email) ? $email : (string) get_option('admin_email');
}

/**
 * Can anyone on the internet download files from the backup folder?
 *
 * Apache honours the folder's .htaccess "deny"; Nginx, and Nginx-in-front
 * setups such as RunCloud's nginx-rc, serve existing files without asking
 * Apache. A small file with a random body is placed in the folder and
 * requested over HTTP: if the body comes back, the folder is web-readable.
 *
 * The probe is an HTTP request to the site itself, so it never runs while an
 * admin page renders: pages read the cached answer and, when there is none,
 * schedule the probe on WP-Cron (sitessaver_storage_probe).
 *
 * @return array{state: string, server: string, checked: int}|null
 *         state: 'protected' | 'exposed' | 'unknown'; null = not checked yet.
 */
function sitessaver_storage_exposure_info(): ?array {
    $cached = get_transient('sitessaver_storage_exposure');
    if (is_array($cached) && isset($cached['state'])) {
        return $cached;
    }
    if (!wp_next_scheduled('sitessaver_storage_probe')) {
        wp_schedule_single_event(time(), 'sitessaver_storage_probe');
    }
    return null;
}

/** Run the probe now and cache the answer (a day; an hour when unsure). */
function sitessaver_storage_probe(): array {
    $info = ['state' => 'unknown', 'server' => '', 'checked' => time()];

    $dir     = SITESSAVER_STORAGE_DIR;
    $content = wp_normalize_path(WP_CONTENT_DIR);
    if (str_starts_with(wp_normalize_path($dir), $content)) {
        wp_mkdir_p($dir);
        sitessaver_protect_directory($dir);

        $name  = 'ss-probe-' . wp_generate_password(16, false) . '.txt';
        $token = wp_generate_password(24, false);
        if (@file_put_contents($dir . '/' . $name, $token) !== false) {
            $url = content_url(substr(wp_normalize_path($dir), strlen($content))) . '/' . $name;
            $res = wp_remote_get($url, ['timeout' => 8, 'sslverify' => sitessaver_loopback_sslverify(), 'redirection' => 0]);
            @unlink($dir . '/' . $name);

            if (!is_wp_error($res)) {
                $code           = (int) wp_remote_retrieve_response_code($res);
                $info['state']  = ($code === 200 && trim((string) wp_remote_retrieve_body($res)) === $token) ? 'exposed' : 'protected';
                $info['server'] = sitessaver_server_kind((string) wp_remote_retrieve_header($res, 'server'));
            }
        }
    }

    set_transient('sitessaver_storage_exposure', $info, $info['state'] === 'unknown' ? HOUR_IN_SECONDS : DAY_IN_SECONDS);
    if ($info['state'] === 'exposed' && class_exists(\SitesSaver\Log::class)) {
        \SitesSaver\Log::info('storage_exposed', 'The backup folder can be read by direct link (the web server serves files without applying .htaccess).', ['server' => $info['server']]);
    }
    return $info;
}
add_action('sitessaver_storage_probe', 'sitessaver_storage_probe');

/** 'runcloud' | 'nginx' | 'litespeed' | 'apache' | 'other' from a Server header. */
function sitessaver_server_kind(string $header): string {
    $h = strtolower(trim($header));
    if ($h === '') {
        $h = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))) : '';
    }
    if (str_contains($h, 'nginx-rc')) {
        return 'runcloud';
    }
    foreach (['nginx', 'litespeed', 'apache'] as $kind) {
        if (str_contains($h, $kind)) {
            return $kind;
        }
    }
    return 'other';
}

/** Back-compat wrapper: the cached state only, never probes inline. */
function sitessaver_storage_exposure(): string {
    $info = sitessaver_storage_exposure_info();
    return $info['state'] ?? 'unknown';
}

/**
 * Backups named before 1.5.1 end in 6 random characters; those are the only
 * ones a stranger could realistically guess on a folder that is reachable.
 *
 * @param array<int, array<string, mixed>> $backups From sitessaver_get_backups().
 */
function sitessaver_count_short_named_backups(array $backups): int {
    $n = 0;
    foreach ($backups as $b) {
        if (preg_match('/-\d{8}-\d{6}-[A-Za-z0-9]{6}\.zip$/', (string) ($b['file'] ?? ''))) {
            $n++;
        }
    }
    return $n;
}

/** Host part every backup of THIS site starts with (matches the filename). */
function sitessaver_backup_name_prefix(): string {
    return sanitize_file_name(wp_parse_url(home_url(), PHP_URL_HOST) ?? 'site') . '-';
}

/**
 * Get all backup files sorted by date (newest first).
 */
function sitessaver_get_backups(): array {
    $dir = sitessaver_storage_dir();

    if (!is_dir($dir)) {
        return [];
    }

    $files   = glob($dir . '/*.zip');
    $backups = [];

    if ($files === false) {
        return [];
    }

    // Hoist out of the loop — single option read.
    $labels = get_option('sitessaver_backup_labels', []);

    foreach ($files as $file) {
        $basename = basename($file);
        $size     = (int) filesize($file);
        $mtime    = (int) filemtime($file);

        $backups[] = [
            'file'      => $basename,
            'path'      => $file,
            'size'      => $size,
            'size_h'    => sitessaver_format_size($size),
            'created'   => $mtime,
            'created_h' => wp_date('M j, Y g:i A', $mtime),
            'label'     => $labels[$basename] ?? '',
        ];
    }

    // Sort by creation time descending.
    usort($backups, static fn($a, $b) => $b['created'] <=> $a['created']);

    return $backups;
}

/**
 * Clean up temp directory.
 *
 * With no argument: removes only **stale** temp artefacts (orphan chunk dirs
 * older than 6h AND any top-level non-chunk dir/file older than 6h). This is
 * safe to call concurrently with an active export/import — it will NOT wipe
 * another in-flight job's working directory.
 *
 * With a $scope_dir argument: removes exactly that directory and its contents
 * (used for per-import cleanup).
 *
 * @param string|null $scope_dir Absolute path of a single temp subdir to remove.
 *                               Must live under SITESSAVER_TEMP_DIR.
 */
function sitessaver_cleanup_temp(?string $scope_dir = null): void {
    $tmp = SITESSAVER_TEMP_DIR;

    if (!is_dir($tmp)) {
        return;
    }

    // The temp folder holds database dumps and whole uploaded archives while
    // a job runs. Keep its own guard in place for servers that list folders.
    sitessaver_protect_directory($tmp);

    if ($scope_dir !== null) {
        sitessaver_remove_scoped_temp($tmp, $scope_dir);
        return;
    }

    $cutoff = time() - (6 * 3600);

    // Clean orphaned chunk directories (older than 6 hours).
    $chunks_dir = $tmp . '/chunks';
    if (is_dir($chunks_dir)) {
        $dirs = glob($chunks_dir . '/*', GLOB_ONLYDIR);
        if ($dirs) {
            foreach ($dirs as $dir) {
                if (filemtime($dir) < $cutoff) {
                    sitessaver_rm_recursive($dir);
                }
            }
        }
    }

    // Clean stale top-level temp items (not chunks, older than 6h).
    // Do NOT do a deep recursive wipe — that nukes active jobs.
    $top = glob($tmp . '/*');
    if ($top) {
        foreach ($top as $entry) {
            $name = basename($entry);
            if ($name === 'chunks' || $name === 'index.php' || $name === '.htaccess' || $name === 'web.config') {
                continue;
            }
            if (filemtime($entry) >= $cutoff) {
                continue; // Active job — leave alone.
            }
            sitessaver_rm_recursive($entry);
        }
    }
}

/**
 * Remove a specific temp subdirectory if — and only if — it lives under
 * SITESSAVER_TEMP_DIR. Hardens against path traversal.
 */
function sitessaver_remove_scoped_temp(string $tmp, string $scope_dir): void {
    $real_tmp   = realpath($tmp);
    $real_scope = realpath($scope_dir);

    if ($real_tmp === false || $real_scope === false) {
        return;
    }

    $real_tmp   = rtrim($real_tmp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $real_scope = rtrim($real_scope, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    // Refuse to delete anything outside the temp dir, and refuse to nuke
    // the temp dir itself.
    if ($real_scope === $real_tmp || !str_starts_with($real_scope, $real_tmp)) {
        return;
    }

    sitessaver_rm_recursive(rtrim($real_scope, DIRECTORY_SEPARATOR));
}

/**
 * Recursively remove a path (file or directory).
 */
function sitessaver_rm_recursive(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $iterator = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
    $items    = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($path);
}

/**
 * Resolve a user-supplied backup filename to a safe absolute path, or return
 * null if the file is missing or resolves outside SITESSAVER_STORAGE_DIR.
 *
 * Hardens against path-traversal / symlink escape for all handlers that
 * operate on existing backup files (delete, download, upload to GDrive).
 */
function sitessaver_resolve_backup_path(string $file): ?string {
    $file = sanitize_file_name($file);
    // Only backups. Without this the folder's own index.php guard (or any
    // other non-backup file in it) could be downloaded, deleted or sent to
    // Google Drive through these handlers.
    if ($file === '' || strtolower((string) pathinfo($file, PATHINFO_EXTENSION)) !== 'zip') {
        return null;
    }

    $path = sitessaver_storage_dir() . '/' . $file;
    if (!file_exists($path)) {
        return null;
    }

    $real_path = realpath($path);
    $real_dir  = realpath(sitessaver_storage_dir());

    if ($real_path === false || $real_dir === false) {
        return null;
    }

    $real_dir .= DIRECTORY_SEPARATOR;
    if (!str_starts_with($real_path . DIRECTORY_SEPARATOR, $real_dir)) {
        return null;
    }

    return $real_path;
}

/**
 * Verify AJAX request (nonce + capability).
 */
function sitessaver_verify_ajax(): bool {
    if (!check_ajax_referer('sitessaver_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => __('Security check failed.', 'sitessaver')], 403);
        return false;
    }

    if (!sitessaver_current_user_can()) {
        wp_send_json_error(['message' => __('Permission denied.', 'sitessaver')], 403);
        return false;
    }

    return true;
}

