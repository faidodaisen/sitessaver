<?php
/**
 * SitesSaver — core engine regression suite.
 *
 * Standalone: no WordPress, no database. Exercises Database::import() and the
 * Archive create/extract paths against a stub wpdb, and asserts that the
 * plugin's own files emit ZERO diagnostics (warnings, notices, deprecations).
 * That assertion is how PHP 8.4 / 8.5 compatibility is verified.
 *
 * Deliberately uses no Reflection, so the harness cannot pollute the result:
 * ReflectionMethod::setAccessible() is itself deprecated as of PHP 8.5.
 *
 * Run with:
 *     php tests/test-core-standalone.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '0');

define('ABSPATH', __DIR__ . '/');
define('SITESSAVER_VERSION', '0.0.0-test');
define('SITESSAVER_FILE', __DIR__ . '/sitessaver.php');
define('SITESSAVER_PATH', dirname(__DIR__) . '/');
define('SITESSAVER_URL', 'http://example.test/');
define('SITESSAVER_STORAGE_DIR', sys_get_temp_dir() . '/ss85');
define('SITESSAVER_TEMP_DIR', SITESSAVER_STORAGE_DIR . '/tmp');
define('DB_NAME', 'test');
define('HOUR_IN_SECONDS', 3600);
@mkdir(SITESSAVER_TEMP_DIR, 0777, true);

// Diagnostics raised from inside the plugin directory only.
$PLUGIN_DIAGS = [];
set_error_handler(static function (int $no, string $str, string $file, int $line) use (&$PLUGIN_DIAGS): bool {
    $norm = str_replace('\\', '/', $file);
    if (str_contains($norm, '/sitessaver/includes/')) {
        $names = [E_WARNING => 'WARNING', E_NOTICE => 'NOTICE', E_DEPRECATED => 'DEPRECATED',
                  E_USER_DEPRECATED => 'USER_DEPRECATED', E_USER_WARNING => 'USER_WARNING'];
        $PLUGIN_DIAGS[] = ($names[$no] ?? (string) $no) . ': ' . $str . ' @ ' . basename($file) . ':' . $line;
    }
    return true;
});

// --- Minimal WordPress surface --------------------------------------------
class wpdb {
    public string $last_error = '';
    public string $options = 'wp_options';
    public string $prefix  = 'wp_';
    public array  $log     = [];
    public function query($q) { $this->log[] = (string) $q; return 1; }
    public function prepare($q, ...$a) { return $q; }
    public function get_col($q) { return []; }
    public function get_row($q, $t = null) { return null; }
    public function get_results($q, $t = null) { return []; }
    public function get_var($q) { return null; }
    public function esc_like($s) { return $s; }
}
$GLOBALS['wpdb'] = new wpdb();

function __($s, $d = '') { return $s; }
function esc_sql($s) { return $s; }
function wp_generate_password(int $n = 12, bool $s = true, bool $e = false): string {
    return substr(str_repeat('abcdefghijklmnop', 4), 0, $n);
}
function get_bloginfo($k = '') { return 'Test'; }
function wp_mkdir_p($dir) { return is_dir($dir) || @mkdir($dir, 0777, true); }
function trailingslashit($s) { return rtrim((string) $s, "/\\") . '/'; }
function wp_convert_hr_to_bytes($v) { return (int) $v; }
function home_url($p = '') { return 'https://example.test' . $p; }
function site_url($p = '') { return 'https://example.test' . $p; }
function gmdate_stub() {}

require_once SITESSAVER_PATH . 'includes/class-database.php';
require_once SITESSAVER_PATH . 'includes/class-archive.php';

use SitesSaver\Database;
use SitesSaver\Archive;

$pass = 0; $fail = 0;
function t(string $label, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%-56s %s\n", $label, $ok ? 'PASS' : 'FAIL');
}

echo "PHP " . PHP_VERSION . "\n\n=== EXERCISE PLUGIN CODE PATHS ===\n";

// 1. Import a dump containing every construct the tokenizer handles.
$sql = <<<SQL
-- SitesSaver Database Export
-- Table: wp_posts
/*!40101 SET NAMES utf8mb4 */;
DROP TABLE IF EXISTS `wp_posts`;
CREATE TABLE `wp_posts` (id INT, `we;ird` TEXT);
INSERT INTO `wp_posts` VALUES ('a;b', "c;d", 'it\\'s', 'x''y');
# hash comment with ; inside
/* block ; comment */
DELIMITER ;;
CREATE TRIGGER t BEGIN INSERT INTO a VALUES (1); END;;
DELIMITER ;
INSERT INTO `wp_options` VALUES ('a:1:{s:3:"url";s:23:"https://example.test/a\\\\b";}');
SELECT 5--3;
SQL;
$f = tempnam(sys_get_temp_dir(), 'ss85');
file_put_contents($f, $sql);
$ok = Database::import($f, 'https://example.test', 'https://new.test');
unlink($f);
t('Database::import() completes', $ok === true);
t('statements executed (>= 7)', count($GLOBALS['wpdb']->log) >= 7);

// 2. Archive round-trip with a real ZIP.
$src = sys_get_temp_dir() . '/ss85src';
@mkdir($src . '/sub', 0777, true);
file_put_contents($src . '/a.txt', 'hello');
file_put_contents($src . '/sub/b.bin', random_bytes(1024));
$zip = sys_get_temp_dir() . '/ss85.zip';
@unlink($zip);
t('Archive::create() succeeds', Archive::create($src, $zip, ['*.log']) === true);
t('archive file is non-empty', file_exists($zip) && filesize($zip) > 0);

$dest = sys_get_temp_dir() . '/ss85out';
if (is_dir($dest)) { foreach (glob($dest . '/*') as $g) { @unlink($g); } }
@mkdir($dest, 0777, true);
t('Archive::extract() succeeds', Archive::extract($zip, $dest) === true);
t('extracted content matches', @file_get_contents($dest . '/a.txt') === 'hello');

// 3. Reject a zip-slip archive.
$evil = sys_get_temp_dir() . '/ss85evil.zip';
@unlink($evil);
$z = new ZipArchive();
$z->open($evil, ZipArchive::CREATE);
$z->addFromString('../escaped.txt', 'pwned');
$z->close();
t('Archive::extract() rejects zip-slip', Archive::extract($evil, $dest) === false);
t('no file escaped the destination', !file_exists(dirname($dest) . '/escaped.txt'));

// 4. build_replacement_pairs is a pure function — exercise its edges.
$pairs = Database::build_replacement_pairs('https://old.com', 'https://new.com', 'old.com', 'new.com');
t('replacement pairs built', is_array($pairs) && $pairs !== []);
t('pairs cover json-escaped form', isset($pairs['https:\\/\\/old.com']));
t('identical urls produce no pairs',
    Database::build_replacement_pairs('https://a.com', 'https://a.com', 'a.com', 'a.com') === []);
t('bare "localhost" is not rewritten (no dot)',
    !isset(Database::build_replacement_pairs('http://localhost', 'http://x.test', 'localhost', 'x.test')['localhost']));

// cleanup
@unlink($zip); @unlink($evil);
foreach (glob($src . '/sub/*') as $g) { @unlink($g); }
foreach (glob($src . '/*') as $g) { @is_file($g) && @unlink($g); }
@rmdir($src . '/sub'); @rmdir($src);
foreach (glob($dest . '/*') as $g) { @is_file($g) && @unlink($g); }
@rmdir($dest);

echo "\n=== DIAGNOSTICS RAISED FROM PLUGIN CODE ===\n";
if ($PLUGIN_DIAGS === []) {
    echo "(none)\n";
} else {
    foreach (array_unique($PLUGIN_DIAGS) as $d) { echo "  {$d}\n"; }
}
t('plugin code raises zero diagnostics on PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $PLUGIN_DIAGS === []);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
