<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Self-hosted updates from GitHub releases.
 *
 * This plugin is not on wordpress.org, so WordPress has no update source for
 * it. The three hooks below supply one:
 *
 *   1. `update_plugins_<host>` — WordPress 5.8+ asks plugins that declare an
 *      `Update URI` to resolve their own updates. This is the correct modern
 *      hook: it is scoped to OUR host, so we cannot accidentally answer for
 *      another plugin the way a broad `pre_set_site_transient` filter can.
 *   2. `site_transient_update_plugins` — the 5.8 hook alone does not populate
 *      the Plugins-screen row on every code path (notably when the transient
 *      is served from cache), so we also inject our entry when reading it.
 *   3. `plugins_api` — powers the "View details" modal, which otherwise 404s
 *      against wordpress.org for a plugin that does not live there.
 *
 * Release selection: the newest NON-draft release whose tag parses as a
 * version. Pre-releases are skipped unless the site opts in. A release is
 * only offered when its version is strictly greater than the installed one,
 * so re-tagging the same version never produces a phantom update.
 *
 * Package selection: a `.zip` asset is preferred because it is the built
 * artefact (correct folder name, no tests/tools). GitHub's generated
 * `zipball_url` is the fallback; its top folder is `user-repo-sha`, which
 * would rename the plugin directory on install, so upgrader_source_selection()
 * renames it back.
 */
final class Updater {

    /** Where releases are published. Hardcoded on purpose — see config(). */
    public const REPO = 'faidodaisen/sitessaver';

    /** Filter-only overrides, for a fork or a private mirror. */
    public const OPTION = 'sitessaver_update_source';

    /** Cached API response. */
    private const CACHE_KEY = 'sitessaver_update_check';

    /** How long a successful lookup is reused. */
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    /** Shorter TTL for failures so a transient outage is not cached all day. */
    private const ERROR_TTL = 30 * MINUTE_IN_SECONDS;

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function init(): void {
        // Scoped to our own Update URI host — see the class docblock.
        add_filter('update_plugins_github.com', [$this, 'check_for_update'], 10, 3);

        add_filter('site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_source_dir'], 10, 4);

        // A private repo needs the token on the download request too.
        add_filter('http_request_args', [$this, 'authorize_download'], 10, 2);

        // Clear the cache after an update so the "new version" row does not
        // linger for up to six hours after the user has already updated.
        add_action('upgrader_process_complete', [$this, 'clear_cache'], 10, 0);

        add_action('admin_notices', [$this, 'render_notice']);
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    /**
     * Where to look for releases, and how.
     *
     * Deliberately NOT a user-facing setting. Which repository the plugin
     * updates from is a property of the build, not a preference: exposing it
     * only invites a site owner to point their production install somewhere
     * arbitrary, or to switch on pre-releases without knowing what that means.
     *
     * Developers forking or mirroring the plugin have two escape hatches that
     * do not clutter the UI:
     *   - the `sitessaver_update_source` option, set in code or WP-CLI
     *   - the `sitessaver_update_config` filter, for a wp-config/mu-plugin override
     *
     * @return array{repo: string, token: string, prereleases: bool, enabled: bool}
     */
    public static function config(): array {
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];

        $config = [
            'repo'        => (string) ($saved['repo'] ?? self::REPO),
            'token'       => (string) ($saved['token'] ?? ''),
            'prereleases' => !empty($saved['prereleases']),
            'enabled'     => !isset($saved['enabled']) || !empty($saved['enabled']),
        ];

        /**
         * Filter the update source.
         *
         * @param array{repo: string, token: string, prereleases: bool, enabled: bool} $config
         */
        $filtered = apply_filters('sitessaver_update_config', $config);

        // A filter returning something malformed must not break update checks
        // entirely, so fall back to the unfiltered values key by key.
        if (!is_array($filtered)) {
            return $config;
        }

        return [
            'repo'        => (string) ($filtered['repo'] ?? $config['repo']),
            'token'       => (string) ($filtered['token'] ?? $config['token']),
            'prereleases' => !empty($filtered['prereleases']),
            'enabled'     => !isset($filtered['enabled']) || !empty($filtered['enabled']),
        ];
    }

    public static function plugin_basename(): string {
        return plugin_basename(SITESSAVER_FILE);
    }

    public static function plugin_slug(): string {
        return dirname(self::plugin_basename());
    }

    // ------------------------------------------------------------------
    // Remote lookup
    // ------------------------------------------------------------------

    /**
     * Newest eligible release, or null.
     *
     * @param bool $force Bypass the cache (used by the manual "Check now").
     * @return array{version: string, package: string, url: string, notes: string, published: string, asset: bool}|null
     */
    public static function latest_release(bool $force = false): ?array {
        $config = self::config();

        if (!$config['enabled'] || $config['repo'] === '') {
            return null;
        }

        if (!$force) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                // A cached failure is stored as an explicit marker rather than
                // as `false`, so it is distinguishable from "never checked".
                return empty($cached['release']) ? null : $cached['release'];
            }
        }

        // Ask for a handful: `/releases/latest` hides pre-releases entirely
        // and returns 404 on a repo whose only releases are drafts, which
        // reads as "no update" when the truth is "wrong endpoint".
        $url = sprintf(
            'https://api.github.com/repos/%s/releases?per_page=10',
            $config['repo']
        );

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => self::api_headers($config['token']),
        ]);

        if (is_wp_error($response)) {
            self::cache_failure($response->get_error_message());
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            self::cache_failure(self::http_error_message($code, $config['token'] !== ''));
            return null;
        }

        $releases = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($releases)) {
            self::cache_failure(__('GitHub returned an unreadable response.', 'sitessaver'));
            return null;
        }

        $best = null;

        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft'])) {
                continue;
            }
            if (!empty($release['prerelease']) && !$config['prereleases']) {
                continue;
            }

            $version = self::normalize_version((string) ($release['tag_name'] ?? ''));
            if ($version === '') {
                continue;
            }

            if ($best !== null && version_compare($version, $best['version'], '<=')) {
                continue;
            }

            // Prefer the built artefact over GitHub's generated zipball.
            $package   = '';
            $has_asset = false;
            foreach ($release['assets'] ?? [] as $asset) {
                if (!is_array($asset) || !str_ends_with(strtolower((string) ($asset['name'] ?? '')), '.zip')) {
                    continue;
                }
                // For a private repo the browser URL is not downloadable with
                // a token; the API asset URL is, with the right Accept header.
                $package   = $config['token'] !== ''
                    ? (string) ($asset['url'] ?? '')
                    : (string) ($asset['browser_download_url'] ?? '');
                $has_asset = $package !== '';
                break;
            }

            if ($package === '') {
                $package = (string) ($release['zipball_url'] ?? '');
            }
            if ($package === '') {
                continue;
            }

            $best = [
                'version'   => $version,
                'package'   => $package,
                'url'       => (string) ($release['html_url'] ?? ''),
                'notes'     => (string) ($release['body'] ?? ''),
                'published' => (string) ($release['published_at'] ?? ''),
                'asset'     => $has_asset,
            ];
        }

        set_transient(self::CACHE_KEY, ['release' => $best, 'error' => ''], self::CACHE_TTL);

        return $best;
    }

    /**
     * @return array<string, string>
     */
    private static function api_headers(string $token, bool $download = false): array {
        $headers = [
            // GitHub rejects API requests with no User-Agent outright.
            'User-Agent' => 'SitesSaver/' . SITESSAVER_VERSION . '; ' . home_url(),
            'Accept'     => $download ? 'application/octet-stream' : 'application/vnd.github+json',
        ];

        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    private static function http_error_message(int $code, bool $has_token): string {
        if ($code === 404) {
            return $has_token
                ? __('Repository not found, or the token cannot see it. Check the repo name and that the token has "Contents: read" access.', 'sitessaver')
                : __('Repository not found. If it is private, add a personal access token below.', 'sitessaver');
        }
        if ($code === 401 || $code === 403) {
            return __('GitHub refused the request. The token may be invalid, expired, or you have hit the hourly rate limit.', 'sitessaver');
        }

        /* translators: %d: HTTP status code */
        return sprintf(__('GitHub returned HTTP %d.', 'sitessaver'), $code);
    }

    private static function cache_failure(string $message): void {
        set_transient(self::CACHE_KEY, ['release' => null, 'error' => $message], self::ERROR_TTL);
    }

    /**
     * Last error from a lookup, if the cached result was a failure.
     */
    public static function last_error(): string {
        $cached = get_transient(self::CACHE_KEY);

        return is_array($cached) ? (string) ($cached['error'] ?? '') : '';
    }

    public function clear_cache(): void {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Strip a leading `v` and anything that is not part of the version.
     * `v1.2.3` and `release-1.2.3` both yield `1.2.3`.
     */
    private static function normalize_version(string $tag): string {
        if (!preg_match('/(\d+(?:\.\d+)*(?:-[0-9A-Za-z.]+)?)/', $tag, $m)) {
            return '';
        }

        return $m[1];
    }

    /**
     * Is a remote version newer than what is installed?
     */
    public static function is_newer(string $remote): bool {
        return version_compare($remote, SITESSAVER_VERSION, '>');
    }

    // ------------------------------------------------------------------
    // WordPress update plumbing
    // ------------------------------------------------------------------

    /**
     * Build the response array WordPress expects for one plugin.
     *
     * @param array<string, mixed> $release
     * @return array<string, mixed>
     */
    private function update_payload(array $release): array {
        return [
            'id'            => 'github.com/' . self::config()['repo'],
            'slug'          => self::plugin_slug(),
            'plugin'        => self::plugin_basename(),
            'new_version'   => $release['version'],
            'url'           => $release['url'],
            'package'       => $release['package'],
            'icons'         => [],
            'banners'       => [],
            'banners_rtl'   => [],
            'tested'        => get_bloginfo('version'),
            'requires_php'  => '8.1',
            'compatibility' => new \stdClass(),
        ];
    }

    /**
     * WP 5.8+ `update_plugins_{$hostname}` handler.
     *
     * @param array|false $update
     * @param array<string, string> $plugin_data
     * @param string $plugin_file
     * @return array|false
     */
    public function check_for_update($update, array $plugin_data, string $plugin_file) {
        if ($plugin_file !== self::plugin_basename()) {
            return $update;
        }

        $release = self::latest_release();
        if ($release === null || !self::is_newer($release['version'])) {
            return $update;
        }

        return $this->update_payload($release);
    }

    /**
     * Inject our entry when the update transient is read.
     *
     * @param mixed $transient
     * @return mixed
     */
    public function inject_update($transient) {
        if (!is_object($transient)) {
            return $transient;
        }

        $basename = self::plugin_basename();
        $release  = self::latest_release();

        if ($release === null || !self::is_newer($release['version'])) {
            // Make sure a stale entry (from before the user updated) is not
            // left behind advertising an update that no longer exists.
            unset($transient->response[$basename]);
            return $transient;
        }

        $payload = (object) $this->update_payload($release);

        $transient->response ??= [];
        $transient->response[$basename] = $payload;
        unset($transient->no_update[$basename]);

        return $transient;
    }

    /**
     * Supply the "View details" modal content.
     *
     * @param mixed $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public function plugin_info($result, string $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (!isset($args->slug) || $args->slug !== self::plugin_slug()) {
            return $result;
        }

        $release = self::latest_release();
        if ($release === null) {
            return $result;
        }

        $config = self::config();

        return (object) [
            'name'              => 'SitesSaver',
            'slug'              => self::plugin_slug(),
            'version'           => $release['version'],
            'author'            => '<a href="https://github.com/' . esc_attr($config['repo']) . '">SitesSaver</a>',
            'homepage'          => 'https://github.com/' . $config['repo'],
            'requires'          => '6.0',
            'requires_php'      => '8.1',
            'tested'            => get_bloginfo('version'),
            'last_updated'      => $release['published'],
            'download_link'     => $release['package'],
            'trunk'             => $release['package'],
            'sections'          => [
                'description' => wpautop(esc_html__('Full site backup and migration — export, import, schedule, Google Drive.', 'sitessaver')),
                'changelog'   => self::render_notes($release['notes'], $release['url']),
            ],
        ];
    }

    /**
     * Release notes are Markdown. Rather than pull in a parser, convert the
     * few constructs a release body actually uses and escape everything else,
     * so a malformed note can never inject markup into wp-admin.
     */
    private static function render_notes(string $body, string $url): string {
        $body = trim($body);

        if ($body === '') {
            return '<p>' . sprintf(
                /* translators: %s: link to the release page */
                esc_html__('No release notes provided. See %s.', 'sitessaver'),
                '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">GitHub</a>'
            ) . '</p>';
        }

        $out   = '';
        $list  = false;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $trimmed = trim($line);

            if (preg_match('/^[-*]\s+(.*)$/', $trimmed, $m)) {
                if (!$list) {
                    $out  .= '<ul>';
                    $list  = true;
                }
                $out .= '<li>' . self::inline_markdown($m[1]) . '</li>';
                continue;
            }

            if ($list) {
                $out  .= '</ul>';
                $list  = false;
            }

            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m)) {
                $level = min(6, max(3, strlen($m[1]) + 2)); // Never h1/h2 inside the modal.
                $out  .= "<h{$level}>" . self::inline_markdown($m[2]) . "</h{$level}>";
                continue;
            }

            $out .= '<p>' . self::inline_markdown($trimmed) . '</p>';
        }

        if ($list) {
            $out .= '</ul>';
        }

        $out .= '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener">'
            . esc_html__('View this release on GitHub', 'sitessaver')
            . '</a></p>';

        return $out;
    }

    /**
     * Escape first, then re-introduce only `code` and `strong`. Doing it in
     * this order means raw HTML in a release note is displayed, not executed.
     */
    private static function inline_markdown(string $text): string {
        $text = esc_html($text);
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;

        return $text;
    }

    // ------------------------------------------------------------------
    // Install-time fixes
    // ------------------------------------------------------------------

    /**
     * Add the token (and the octet-stream Accept header) to the package
     * download so private-repo assets are fetched rather than 404ing.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function authorize_download(array $args, string $url): array {
        $config = self::config();

        if ($config['token'] === '' || !str_contains($url, 'api.github.com')) {
            return $args;
        }

        // Only decorate requests aimed at OUR repo — never leak the token to
        // another plugin's GitHub traffic.
        if (!str_contains($url, '/repos/' . $config['repo'] . '/')) {
            return $args;
        }

        $args['headers'] = array_merge(
            is_array($args['headers'] ?? null) ? $args['headers'] : [],
            self::api_headers($config['token'], str_contains($url, '/assets/'))
        );

        return $args;
    }

    /**
     * GitHub's generated zipball unpacks to `owner-repo-<sha>/`. Left alone,
     * WordPress would install the plugin into a folder of that name, orphaning
     * the existing installation and deactivating it. Rename to the real slug.
     *
     * @param string|\WP_Error $source
     * @param string $remote_source
     * @param mixed $upgrader
     * @param array<string, mixed> $args
     * @return string|\WP_Error
     */
    public function fix_source_dir($source, string $remote_source, $upgrader = null, array $args = []) {
        if (is_wp_error($source)) {
            return $source;
        }

        // Only touch OUR update. `$args['plugin']` is set for plugin upgrades.
        if (($args['plugin'] ?? '') !== self::plugin_basename()) {
            return $source;
        }

        $slug    = self::plugin_slug();
        $current = basename(untrailingslashit($source));

        if ($current === $slug) {
            return $source;
        }

        $target = trailingslashit(dirname(untrailingslashit($source))) . $slug;

        global $wp_filesystem;
        if (!$wp_filesystem) {
            return $source;
        }

        // A leftover target from a failed run would make move() fail.
        if ($wp_filesystem->exists($target)) {
            $wp_filesystem->delete($target, true);
        }

        if (!$wp_filesystem->move(untrailingslashit($source), $target)) {
            return new \WP_Error(
                'sitessaver_rename_failed',
                __('Could not rename the downloaded package folder. The update was not installed.', 'sitessaver')
            );
        }

        return trailingslashit($target);
    }

    // ------------------------------------------------------------------
    // Admin notice
    // ------------------------------------------------------------------

    /**
     * A dashboard-wide banner, because the Plugins-screen row is easy to miss
     * on a site whose owner rarely opens that page.
     */
    public function render_notice(): void {
        if (!current_user_can('update_plugins')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $on_plugins_screen = $screen && ($screen->id === 'plugins' || $screen->id === 'update-core');

        // The Plugins screen already shows a row; a second banner there is noise.
        if ($on_plugins_screen) {
            return;
        }

        if (get_user_meta(get_current_user_id(), 'sitessaver_dismissed_update', true) === self::dismiss_stamp()) {
            return;
        }

        $release = self::latest_release();
        if ($release === null || !self::is_newer($release['version'])) {
            return;
        }

        printf(
            '<div class="notice notice-info is-dismissible sitessaver-update-notice" data-version="%1$s"><p><strong>SitesSaver %2$s</strong> %3$s <a href="%4$s">%5$s</a> &nbsp;<a href="%6$s" target="_blank" rel="noopener">%7$s</a></p></div>',
            esc_attr($release['version']),
            esc_html($release['version']),
            esc_html__('is available. You are running', 'sitessaver') . ' ' . esc_html(SITESSAVER_VERSION) . '.',
            esc_url(admin_url('plugins.php?s=sitessaver&plugin_status=all')),
            esc_html__('Update now', 'sitessaver'),
            esc_url($release['url']),
            esc_html__('View release notes', 'sitessaver')
        );

        // The banner shows on every admin screen, but the plugin's admin.js is
        // only enqueued on SitesSaver pages — so the dismissal must carry its
        // own handler, or clicking the X would silently fail to persist and
        // the notice would reappear on the next page load.
        printf(
            '<script>(function(){var n=document.querySelector(".sitessaver-update-notice");if(!n)return;n.addEventListener("click",function(e){if(!e.target.classList.contains("notice-dismiss"))return;var d=new FormData();d.append("action","sitessaver_dismiss_update_notice");d.append("nonce",%1$s);d.append("version",%2$s);fetch(%3$s,{method:"POST",body:d,credentials:"same-origin"});});})();</script>',
            wp_json_encode(wp_create_nonce('sitessaver_nonce')),
            wp_json_encode($release['version']),
            wp_json_encode(admin_url('admin-ajax.php'))
        );
    }

    /**
     * Dismissal is per installed version, so the notice returns for the NEXT
     * release instead of being silenced forever by one click.
     */
    public static function dismiss_stamp(): string {
        $release = self::latest_release();

        return $release === null ? '' : (string) $release['version'];
    }
}
