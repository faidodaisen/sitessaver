<?php
/**
 * End-to-end: the real Updater class against the real GitHub API, pretending
 * to be an installed 1.2.1 site. Confirms the exact payload WordPress would
 * put on the Plugins screen. Manual test — needs network.
 */
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','1');

define('ABSPATH', __DIR__ . '/');
define('SITESSAVER_VERSION', '1.2.1');           // pretend older install
define('SITESSAVER_FILE', dirname(__DIR__) . '/sitessaver.php');
define('SITESSAVER_PATH', dirname(__DIR__) . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);

$OPTIONS = []; $TRANSIENTS = [];
function __($s,$d=''){return $s;}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_attr($s){return esc_html($s);} function esc_url($s){return esc_html($s);}
function esc_html__($s,$d=''){return esc_html($s);}
function wpautop($s){return '<p>'.$s.'</p>';} function wp_json_encode($v){return json_encode($v);}
function home_url(){return 'https://example.test';}
function admin_url($p=''){return 'https://example.test/wp-admin/'.$p;}
function get_bloginfo($x=''){return $x==='version'?'6.7':'Example Site';}
function plugin_basename($f){return 'sitessaver/sitessaver.php';}
function current_user_can($c){return true;} function get_current_user_id(){return 1;}
function wp_create_nonce($a){return 'n';} function get_current_screen(){return null;}
function trailingslashit($s){return rtrim((string)$s,'/\\').'/';}
function untrailingslashit($s){return rtrim((string)$s,'/\\');}
function get_option($k,$d=null){global $OPTIONS; return $OPTIONS[$k]??$d;}
function update_option($k,$v,$a=null){global $OPTIONS; $OPTIONS[$k]=$v; return true;}
function get_transient($k){global $TRANSIENTS; return $TRANSIENTS[$k]??false;}
function set_transient($k,$v,$t=0){global $TRANSIENTS; $TRANSIENTS[$k]=$v; return true;}
function delete_transient($k){global $TRANSIENTS; unset($TRANSIENTS[$k]); return true;}
function get_user_meta($u,$k,$s=false){return '';}
function add_filter(){} function add_action(){}
function is_wp_error($t){return $t instanceof WP_Error;}
function wp_remote_retrieve_response_code($r){return $r['response']['code']??0;}
function wp_remote_retrieve_body($r){return $r['body']??'';}
class WP_Error{ public function __construct(private string $c='',private string $m=''){} public function get_error_message(){return $this->m;} }

/** Real network call through the same shape wp_remote_get returns. */
function wp_remote_get($url, $args = []) {
    $hdr = '';
    foreach ($args['headers'] ?? [] as $k => $v) { $hdr .= "$k: $v\r\n"; }
    $ctx = stream_context_create(['http' => ['header' => $hdr, 'ignore_errors' => true, 'timeout' => 20]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int) $m[1]; }
    }
    if ($body === false) { return new WP_Error('http', 'request failed'); }
    return ['response' => ['code' => $code], 'body' => $body];
}

require_once dirname(__DIR__) . '/includes/class-updater.php';
use SitesSaver\Updater;

$fail = 0;
function check(string $label, bool $cond, string $detail = ''): void {
    global $fail;
    printf("%-52s %s%s\n", $label, $cond ? 'PASS' : 'FAIL', $detail !== '' ? "  $detail" : '');
    if (!$cond) { $fail++; }
}

echo "Installed version (simulated): " . SITESSAVER_VERSION . "\n\n";

$r = Updater::latest_release(true);
check('live lookup returned a release', $r !== null);
if ($r === null) { echo "\nError: " . Updater::last_error() . "\n"; exit(1); }

check('version resolved', $r['version'] !== '', $r['version']);
check('update is offered to a 1.2.1 site', Updater::is_newer($r['version']));
check('package is the built asset, not a zipball', $r['asset'] === true);
check('package URL points at a release download', str_contains($r['package'], 'releases/download'));
check('release notes are non-empty', strlen($r['notes']) > 100, strlen($r['notes']) . ' chars');

$t = Updater::instance()->inject_update((object) ['response' => [], 'no_update' => []]);
$e = $t->response['sitessaver/sitessaver.php'] ?? null;
check('Plugins screen entry is created', $e !== null);
check('  new_version', $e && $e->new_version === $r['version'], (string) ($e->new_version ?? '-'));
check('  package', $e && $e->package === $r['package']);
check('  slug', $e && $e->slug === 'sitessaver');

$info = Updater::instance()->plugin_info(false, 'plugin_information', (object) ['slug' => 'sitessaver']);
check('View details modal builds', is_object($info));
check('  changelog rendered from release body', isset($info->sections['changelog']) && strlen($info->sections['changelog']) > 100);
check('  download link present', !empty($info->download_link));

echo "\nPackage: {$r['package']}\n";
echo $fail === 0 ? "\nALL LIVE CHECKS PASSED\n" : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
