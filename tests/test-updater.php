<?php
/**
 * SitesSaver — GitHub updater regression suite.
 *
 * Standalone: no WordPress. Stubs the options/transient/HTTP surface so the
 * real decision-making is exercised — release selection, version comparison,
 * package preference, the zipball folder rename, and the token-scoping rule
 * that stops a private-repo credential leaking to another plugin's traffic.
 *
 * These paths matter because the failure modes are silent: an updater that
 * quietly offers nothing looks identical to "you are up to date", and one
 * that renames the wrong folder deactivates the plugin mid-upgrade.
 *
 * Run with:
 *     php tests/test-updater.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '0');

define('ABSPATH', __DIR__ . '/');
define('SITESSAVER_VERSION', '1.2.1');
define('SITESSAVER_FILE', dirname(__DIR__) . '/sitessaver.php');
define('SITESSAVER_PATH', dirname(__DIR__) . '/');

define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);

// --- Diagnostics from plugin code are failures, same policy as the other suites.
$PLUGIN_DIAGS = [];
set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0) use (&$PLUGIN_DIAGS): bool {
    if (str_contains(str_replace('\\', '/', $file), '/includes/')) {
        $PLUGIN_DIAGS[] = "$str in $file:$line";
    }
    return true;
});

// ---------------------------------------------------------------- WP stubs
$OPTIONS    = [];
$TRANSIENTS = [];
$USERMETA   = [];
$HTTP       = ['response' => null, 'requests' => []];
$FILTERS    = [];
$ACTIONS    = [];

function __($s, $d = '') { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_html__($s, $d = '') { return esc_html($s); }
function wpautop($s) { return '<p>' . $s . '</p>'; }
function wp_json_encode($v) { return json_encode($v); }
function home_url() { return 'https://example.test'; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function get_bloginfo($x = '') { return $x === 'version' ? '6.7' : 'Example Site'; }
function plugin_basename($f) { return 'sitessaver/sitessaver.php'; }
function current_user_can($c) { return true; }
function get_current_user_id() { return 1; }
function wp_create_nonce($a) { return 'nonce'; }
function trailingslashit($s) { return rtrim((string) $s, '/\\') . '/'; }
function untrailingslashit($s) { return rtrim((string) $s, '/\\'); }
function get_current_screen() { return null; }

function get_option($k, $d = null) { global $OPTIONS; return $OPTIONS[$k] ?? $d; }
function update_option($k, $v, $a = null) { global $OPTIONS; $OPTIONS[$k] = $v; return true; }
function get_transient($k) { global $TRANSIENTS; return $TRANSIENTS[$k] ?? false; }
function set_transient($k, $v, $t = 0) { global $TRANSIENTS; $TRANSIENTS[$k] = $v; return true; }
function delete_transient($k) { global $TRANSIENTS; unset($TRANSIENTS[$k]); return true; }
function delete_site_transient($k) { return true; }
function get_user_meta($u, $k, $single = false) { global $USERMETA; return $USERMETA[$k] ?? ''; }
function update_user_meta($u, $k, $v) { global $USERMETA; $USERMETA[$k] = $v; return true; }

function add_filter($t, $f, $p = 10, $a = 1) { global $FILTERS; $FILTERS[$t][] = $f; }
function add_action($t, $f, $p = 10, $a = 1) { global $ACTIONS; $ACTIONS[$t][] = $f; }

/** Runs whatever add_filter() registered, so filter overrides are testable. */
function apply_filters($tag, $value, ...$rest) {
    global $FILTERS;
    foreach ($FILTERS[$tag] ?? [] as $cb) { $value = $cb($value, ...$rest); }
    return $value;
}

function wp_remote_get($url, $args = []) {
    global $HTTP;
    $HTTP['requests'][] = ['url' => $url, 'args' => $args];
    return $HTTP['response'];
}
function is_wp_error($t) { return $t instanceof WP_Error; }
function wp_remote_retrieve_response_code($r) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }

class WP_Error {
    public function __construct(private string $code = '', private string $msg = '') {}
    public function get_error_message() { return $this->msg; }
}

require_once dirname(__DIR__) . '/includes/class-updater.php';

use SitesSaver\Updater;

// ---------------------------------------------------------------- harness
$PASS = 0;
$FAIL = 0;
function ok(string $label, bool $cond): void {
    global $PASS, $FAIL;
    printf("%-62s %s\n", $label, $cond ? 'PASS' : 'FAIL');
    $cond ? $PASS++ : $FAIL++;
}
function section(string $t): void { echo "\n=== $t ===\n"; }

function set_releases(array $releases, int $code = 200): void {
    global $HTTP;
    $HTTP['response'] = ['response' => ['code' => $code], 'body' => json_encode($releases)];
}
function reset_state(array $config = []): void {
    global $OPTIONS, $TRANSIENTS, $HTTP;
    $TRANSIENTS = [];
    $HTTP['requests'] = [];
    $OPTIONS[Updater::OPTION] = array_merge(
        ['repo' => 'faidodaisen/sitessaver', 'token' => '', 'prereleases' => false, 'enabled' => true],
        $config
    );
}
function release(string $tag, array $over = []): array {
    return array_merge([
        'tag_name'     => $tag,
        'draft'        => false,
        'prerelease'   => false,
        'html_url'     => 'https://github.com/faidodaisen/sitessaver/releases/tag/' . $tag,
        'body'         => 'Notes for ' . $tag,
        'published_at' => '2026-09-01T00:00:00Z',
        'zipball_url'  => 'https://api.github.com/repos/faidodaisen/sitessaver/zipball/' . $tag,
        'assets'       => [],
    ], $over);
}
function asset(string $name): array {
    return [
        'name'                 => $name,
        'url'                  => 'https://api.github.com/repos/faidodaisen/sitessaver/releases/assets/1',
        'browser_download_url' => 'https://github.com/faidodaisen/sitessaver/releases/download/x/' . $name,
    ];
}

// ---------------------------------------------------------------- tests
section('RELEASE SELECTION');

reset_state();
set_releases([release('v1.3.0'), release('v1.2.1')]);
$r = Updater::latest_release(true);
ok('newest release is selected', $r !== null && $r['version'] === '1.3.0');
ok('leading v is stripped from the tag', $r !== null && $r['version'] === '1.3.0');

reset_state();
set_releases([release('v1.2.1'), release('v1.3.0')]);
$r = Updater::latest_release(true);
ok('order in the API response does not matter', $r !== null && $r['version'] === '1.3.0');

reset_state();
set_releases([release('v1.4.0', ['draft' => true]), release('v1.3.0')]);
$r = Updater::latest_release(true);
ok('drafts are never offered', $r !== null && $r['version'] === '1.3.0');

reset_state();
set_releases([release('v1.4.0-beta1', ['prerelease' => true]), release('v1.3.0')]);
$r = Updater::latest_release(true);
ok('pre-releases are skipped by default', $r !== null && $r['version'] === '1.3.0');

reset_state(['prereleases' => true]);
set_releases([release('v1.4.0-beta1', ['prerelease' => true]), release('v1.3.0')]);
$r = Updater::latest_release(true);
ok('pre-releases are offered when opted in', $r !== null && $r['version'] === '1.4.0-beta1');

reset_state();
set_releases([release('nightly'), release('v1.3.0')]);
$r = Updater::latest_release(true);
ok('a tag with no version is ignored', $r !== null && $r['version'] === '1.3.0');

reset_state();
set_releases([]);
ok('an empty release list yields nothing', Updater::latest_release(true) === null);

section('PACKAGE SELECTION');

reset_state();
set_releases([release('v1.3.0', ['assets' => [asset('SitesSaver-1.3.0.zip')]])]);
$r = Updater::latest_release(true);
ok('a zip asset is preferred over the zipball', $r !== null && str_contains($r['package'], 'releases/download'));
ok('asset flag records that a built artefact was used', $r !== null && $r['asset'] === true);

reset_state();
set_releases([release('v1.3.0', ['assets' => [asset('notes.txt')]])]);
$r = Updater::latest_release(true);
ok('a non-zip asset is not mistaken for the package', $r !== null && str_contains($r['package'], 'zipball'));
ok('asset flag is false when falling back to the zipball', $r !== null && $r['asset'] === false);

reset_state(['token' => 'ghp_secret']);
set_releases([release('v1.3.0', ['assets' => [asset('SitesSaver-1.3.0.zip')]])]);
$r = Updater::latest_release(true);
ok('a private repo uses the API asset URL, not the browser one', $r !== null && str_contains($r['package'], 'api.github.com'));

reset_state();
set_releases([release('v1.3.0', ['zipball_url' => '', 'assets' => []])]);
ok('a release with no downloadable package is skipped', Updater::latest_release(true) === null);

section('VERSION COMPARISON');

ok('a higher version is newer', Updater::is_newer('1.3.0'));
ok('the installed version is not newer', !Updater::is_newer('1.2.1'));
ok('a lower version is not newer', !Updater::is_newer('1.2.0'));
ok('a patch bump is detected', Updater::is_newer('1.2.2'));
ok('a re-tagged identical version offers no update', !Updater::is_newer(SITESSAVER_VERSION));

section('TRANSPORT & CACHING');

reset_state();
set_releases([release('v1.3.0')]);
Updater::latest_release(true);
$req = $GLOBALS['HTTP']['requests'][0];
ok('request carries a User-Agent (GitHub rejects requests without one)', !empty($req['args']['headers']['User-Agent']));
ok('request asks for the GitHub JSON media type', ($req['args']['headers']['Accept'] ?? '') === 'application/vnd.github+json');
ok('no Authorization header is sent for a public repo', !isset($req['args']['headers']['Authorization']));

reset_state(['token' => 'ghp_secret']);
set_releases([release('v1.3.0')]);
Updater::latest_release(true);
$req = end($GLOBALS['HTTP']['requests']);
ok('token is sent as a Bearer header when configured', ($req['args']['headers']['Authorization'] ?? '') === 'Bearer ghp_secret');

reset_state();
set_releases([release('v1.3.0')]);
Updater::latest_release(true);
$before = count($GLOBALS['HTTP']['requests']);
Updater::latest_release();
ok('a second lookup is served from cache', count($GLOBALS['HTTP']['requests']) === $before);
Updater::latest_release(true);
ok('force bypasses the cache', count($GLOBALS['HTTP']['requests']) === $before + 1);

reset_state();
set_releases([], 404);
ok('a 404 yields no release', Updater::latest_release(true) === null);
ok('a 404 explains that the repo may be private', str_contains(Updater::last_error(), 'private'));

reset_state(['token' => 'bad']);
set_releases([], 404);
Updater::latest_release(true);
ok('a 404 with a token blames the token, not privacy', str_contains(Updater::last_error(), 'token'));

reset_state();
set_releases([], 403);
Updater::latest_release(true);
ok('a 403 mentions the rate limit', str_contains(Updater::last_error(), 'rate limit'));

reset_state();
$GLOBALS['HTTP']['response'] = new WP_Error('http', 'Connection timed out');
Updater::latest_release(true);
ok('a network error is captured verbatim', Updater::last_error() === 'Connection timed out');
ok('a failed lookup reports no release', Updater::latest_release() === null);

reset_state(['enabled' => false]);
set_releases([release('v1.3.0')]);
$before = count($GLOBALS['HTTP']['requests']);
ok('disabling checks makes no request at all', Updater::latest_release(true) === null && count($GLOBALS['HTTP']['requests']) === $before);

reset_state(['repo' => '']);
ok('an empty repo makes no request', Updater::latest_release(true) === null);

section('UPDATE TRANSIENT');

reset_state();
set_releases([release('v1.3.0', ['assets' => [asset('SitesSaver-1.3.0.zip')]])]);
$t = Updater::instance()->inject_update((object) ['response' => [], 'no_update' => []]);
$entry = $t->response['sitessaver/sitessaver.php'] ?? null;
ok('an available update is injected into the transient', $entry !== null);
ok('the entry carries the new version', $entry && $entry->new_version === '1.3.0');
ok('the entry carries a package URL', $entry && $entry->package !== '');
ok('the entry uses the plugin basename', $entry && $entry->plugin === 'sitessaver/sitessaver.php');
ok('the entry declares the PHP requirement', $entry && $entry->requires_php === '8.1');

reset_state();
set_releases([release('v1.2.1')]);
$stale = (object) ['response' => ['sitessaver/sitessaver.php' => (object) ['new_version' => '1.2.1']], 'no_update' => []];
$t = Updater::instance()->inject_update($stale);
ok('a stale entry is removed once installed is current', !isset($t->response['sitessaver/sitessaver.php']));

reset_state();
set_releases([release('v1.3.0')]);
$out = Updater::instance()->check_for_update(false, [], 'other-plugin/other.php');
ok('another plugin\'s update is never answered for', $out === false);
$out = Updater::instance()->check_for_update(false, [], 'sitessaver/sitessaver.php');
ok('our own plugin file is answered', is_array($out) && $out['new_version'] === '1.3.0');

section('TOKEN SCOPING');

reset_state(['token' => 'ghp_secret']);
$args = Updater::instance()->authorize_download([], 'https://api.github.com/repos/faidodaisen/sitessaver/releases/assets/1');
ok('our own asset download carries the token', ($args['headers']['Authorization'] ?? '') === 'Bearer ghp_secret');
ok('asset download asks for octet-stream, not JSON', ($args['headers']['Accept'] ?? '') === 'application/octet-stream');

$args = Updater::instance()->authorize_download([], 'https://api.github.com/repos/someone/else/releases/assets/9');
ok('another repo\'s request never receives our token', !isset($args['headers']['Authorization']));

$args = Updater::instance()->authorize_download([], 'https://example.com/whatever.zip');
ok('a non-GitHub request is left untouched', !isset($args['headers']['Authorization']));

reset_state(['token' => '']);
$args = Updater::instance()->authorize_download([], 'https://api.github.com/repos/faidodaisen/sitessaver/releases/assets/1');
ok('no token configured means no header added', !isset($args['headers']['Authorization']));

section('SOURCE FOLDER RENAME');

$tmp = sys_get_temp_dir() . '/ss-upd-' . uniqid();
@mkdir($tmp . '/faidodaisen-sitessaver-abc1234', 0777, true);

// Minimal WP_Filesystem double: only move/exists/delete are used.
class FS_Stub {
    public array $moved = [];
    public function exists($p) { return file_exists($p); }
    public function delete($p, $r = false) { return true; }
    public function move($from, $to) { $this->moved[] = [$from, $to]; return @rename($from, $to); }
}
$GLOBALS['wp_filesystem'] = new FS_Stub();

$src = $tmp . '/faidodaisen-sitessaver-abc1234/';
$out = Updater::instance()->fix_source_dir($src, $tmp, null, ['plugin' => 'sitessaver/sitessaver.php']);
ok('the zipball folder is renamed to the plugin slug', $out === $tmp . '/sitessaver/');
ok('the renamed folder exists on disk', is_dir($tmp . '/sitessaver'));

$already = $tmp . '/sitessaver/';
$out = Updater::instance()->fix_source_dir($already, $tmp, null, ['plugin' => 'sitessaver/sitessaver.php']);
ok('a correctly named folder is left alone', $out === $already);

$other = $tmp . '/someone-else-999/';
@mkdir($other, 0777, true);
$out = Updater::instance()->fix_source_dir($other, $tmp, null, ['plugin' => 'other/other.php']);
ok('another plugin\'s install is never renamed', $out === $other);

$err = new WP_Error('x', 'boom');
ok('an upstream error passes straight through', Updater::instance()->fix_source_dir($err, $tmp) === $err);

// Cleanup.
foreach ([$tmp . '/sitessaver', $other, $tmp] as $d) { @rmdir($d); }

section('RELEASE NOTES RENDERING');

reset_state();
set_releases([release('v1.3.0', ['body' => "## What's new\n- Added **branded** emails\n- Fixed `wp_mail` bug\n\nPlain paragraph."])]);
$info = Updater::instance()->plugin_info(false, 'plugin_information', (object) ['slug' => 'sitessaver']);
$log  = $info->sections['changelog'];
ok('markdown list items become list markup', str_contains($log, '<li>Added'));
ok('bold markdown is converted', str_contains($log, '<strong>branded</strong>'));
ok('inline code is converted', str_contains($log, '<code>wp_mail</code>'));
ok('headings never emit h1 or h2 inside the modal', !str_contains($log, '<h1') && !str_contains($log, '<h2'));
ok('a link back to the release is appended', str_contains($log, 'View this release on GitHub'));

reset_state();
set_releases([release('v1.3.0', ['body' => '<script>alert(1)</script> and <img src=x onerror=y>'])]);
$info = Updater::instance()->plugin_info(false, 'plugin_information', (object) ['slug' => 'sitessaver']);
$log  = $info->sections['changelog'];
ok('raw HTML in notes is escaped, not executed', !str_contains($log, '<script>') && str_contains($log, '&lt;script&gt;'));
ok('an onerror attribute cannot survive escaping', !str_contains($log, '<img src=x'));

reset_state();
set_releases([release('v1.3.0', ['body' => ''])]);
$info = Updater::instance()->plugin_info(false, 'plugin_information', (object) ['slug' => 'sitessaver']);
ok('empty notes still produce a usable changelog', str_contains($info->sections['changelog'], 'No release notes'));

$other = Updater::instance()->plugin_info(false, 'plugin_information', (object) ['slug' => 'akismet']);
ok('another plugin\'s info request is not hijacked', $other === false);
$na = Updater::instance()->plugin_info(false, 'query_plugins', (object) ['slug' => 'sitessaver']);
ok('a non-information action is ignored', $na === false);

section('CONFIG DEFAULTS');

global $OPTIONS, $FILTERS;
unset($OPTIONS[Updater::OPTION]);
$FILTERS = [];
$c = Updater::config();
ok('repo is hardcoded, not a user setting', $c['repo'] === Updater::REPO);
ok('the constant names the real repo', Updater::REPO === 'faidodaisen/sitessaver');
ok('checks are on by default', $c['enabled'] === true);
ok('pre-releases are off by default', $c['prereleases'] === false);
ok('no token by default', $c['token'] === '');

// The UI no longer writes this option, but a fork or WP-CLI still can.
$OPTIONS[Updater::OPTION] = ['repo' => 'someone/fork', 'prereleases' => true];
$c = Updater::config();
ok('the option can still override the repo in code', $c['repo'] === 'someone/fork');
ok('the option can still opt into pre-releases', $c['prereleases'] === true);
unset($OPTIONS[Updater::OPTION]);

add_filter('sitessaver_update_config', static function (array $cfg): array {
    $cfg['repo'] = 'filtered/repo';
    return $cfg;
});
$c = Updater::config();
ok('a filter can redirect the update source', $c['repo'] === 'filtered/repo');
ok('unfiltered keys keep their defaults', $c['enabled'] === true && $c['token'] === '');

$FILTERS = [];
add_filter('sitessaver_update_config', static fn() => 'not an array');
$c = Updater::config();
ok('a malformed filter return cannot break updates', $c['repo'] === Updater::REPO);
$FILTERS = [];

section('PLUGIN DIAGNOSTICS');
ok('no warnings/notices/deprecations from plugin code', $PLUGIN_DIAGS === []);
foreach ($PLUGIN_DIAGS as $d) { echo "    $d\n"; }

echo "\n" . str_repeat('-', 72) . "\n";
printf("PASS: %d   FAIL: %d\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);
