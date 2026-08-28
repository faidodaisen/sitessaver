<?php
/**
 * SitesSaver — SQL tokenizer & serialized-data regression suite.
 *
 * Standalone: no WordPress, no database. Run with:
 *     php tests/test-sql-tokenizer.php
 *
 * Every case here corresponds to a real defect found during the 1.1.9 audit.
 * A failure means a restore would corrupt or silently drop data.
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('ABSPATH', __DIR__);
define('DB_NAME', 'test');

// --- Minimal wpdb that just records the statements handed to it ------------
class wpdb {
    public string $last_error = '';
    public string $options = 'wp_options';
    public string $prefix  = 'wp_';
    /** @var string[] */
    public array $log = [];
    public function query($q) { $this->log[] = (string) $q; return 1; }
    public function prepare($q, ...$a) { return $q; }
    public function get_col($q) { return []; }
    public function get_row($q, $t = null) { return null; }
    public function get_results($q, $t = null) { return []; }
    public function get_var($q) { return null; }
    public function esc_like($s) { return $s; }
}
$GLOBALS['wpdb'] = new wpdb();

if (!function_exists('__')) { function __($s, $d = '') { return $s; } }
if (!function_exists('esc_sql')) { function esc_sql($s) { return $s; } }

require_once dirname(__DIR__) . '/includes/class-database.php';

use SitesSaver\Database;

$pass = 0;
$fail = 0;

function check(string $name, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) {
        $pass++;
        echo "PASS  {$name}\n";
        return;
    }
    $fail++;
    echo "FAIL  {$name}\n";
    echo "  want: " . var_export($want, true) . "\n";
    echo "  got : " . var_export($got, true) . "\n";
}

/** Feed SQL through the real streaming importer, return the statements executed. */
function tokenize(string $sql): array {
    global $wpdb;
    $wpdb->log = [];
    $f = tempnam(sys_get_temp_dir(), 'sstok');
    file_put_contents($f, $sql);
    Database::import($f, '', '');
    unlink($f);
    return $wpdb->log;
}

/** Escape a value exactly as the exporter does. */
function esc_value(string $v): string {
    return strtr($v, [
        '\\' => '\\\\', "\0" => '\\0', "\n" => '\\n',
        "\r" => '\\r', "\x1a" => '\\Z', "'" => "\\'",
    ]);
}

/** Decode a SQL string literal exactly as MySQL would. */
function decode_literal(string $s): string {
    $out = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        if ($s[$i] === '\\' && $i + 1 < $n) {
            $c = $s[++$i];
            $out .= match ($c) { '0' => "\0", 'n' => "\n", 'r' => "\r", 'Z' => "\x1a", default => $c };
            continue;
        }
        $out .= $s[$i];
    }
    return $out;
}

/**
 * Full fidelity check: export a value, run the URL rewrite, decode as MySQL
 * would, and confirm PHP can still unserialize it with the URL swapped.
 *
 * $expected is compared loosely for objects (=== on objects tests identity,
 * not value) and the caller may pass a callable for bespoke assertions.
 */
function roundtrip(string $label, $value, string $old, string $new, $expected): void {
    global $pass, $fail;

    $stored = serialize($value);
    $stmt   = "INSERT INTO `wp_options` (`option_value`) VALUES ('" . esc_value($stored) . "');";

    $rw = Database::rewrite_for_tests($stmt, $old, $new);

    preg_match("/VALUES \('(.*)'\);$/s", $rw, $m);
    $decoded = decode_literal($m[1] ?? '');
    $read    = @unserialize($decoded, ['allowed_classes' => ['stdClass']]);

    if (is_callable($expected)) {
        $ok = (bool) $expected($read);
    } elseif (is_object($expected)) {
        // == compares object property values; === would compare identity.
        $ok = is_object($read) && $read == $expected;
    } else {
        $ok = $read === $expected;
    }

    if ($ok) {
        $pass++;
        echo "PASS  {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL  {$label}\n";
    echo "  want: " . var_export($expected, true) . "\n";
    echo "  got : " . var_export($read, true) . "\n";
}

$CH = 65536; // must match Database::IMPORT_READ_CHUNK

echo "=== SQL TOKENIZER ===\n";

check('semicolon inside a string literal',
    tokenize("INSERT INTO t VALUES ('a;b');"),
    ["INSERT INTO t VALUES ('a;b')"]);

check('backslash-escaped quote',
    tokenize("INSERT INTO t VALUES ('it\\'s; fine');"),
    ["INSERT INTO t VALUES ('it\\'s; fine')"]);

check('doubled single quote',
    tokenize("INSERT INTO t VALUES ('it''s; fine');"),
    ["INSERT INTO t VALUES ('it''s; fine')"]);

check('-- comment containing a semicolon',
    tokenize("-- watch out; here\nDROP TABLE x;"),
    ["DROP TABLE x"]);

check('# comment containing a semicolon',
    tokenize("# watch out; here\nDROP TABLE x;"),
    ["DROP TABLE x"]);

check('/* */ comment containing a semicolon',
    tokenize("/* a ; b */ DROP TABLE x;"),
    ["DROP TABLE x"]);

check('trailing statement with no semicolon',
    tokenize("DROP TABLE x"),
    ["DROP TABLE x"]);

check('DELIMITER block keeps the trigger body intact',
    tokenize("DELIMITER ;;\nCREATE TRIGGER t BEGIN INSERT INTO a VALUES (1); END;;\nDELIMITER ;\nDROP TABLE x;"),
    ["CREATE TRIGGER t BEGIN INSERT INTO a VALUES (1); END", "DROP TABLE x"]);

check('double-quoted string containing a semicolon',
    tokenize('INSERT INTO t VALUES ("a;b");'),
    ['INSERT INTO t VALUES ("a;b")']);

check('backtick identifier containing a semicolon',
    tokenize("DROP TABLE `we;ird`;"),
    ["DROP TABLE `we;ird`"]);

check('mysqldump conditional comment is executed, not stripped',
    tokenize("/*!40101 SET NAMES utf8 */;\nDROP TABLE x;"),
    ["/*!40101 SET NAMES utf8 */", "DROP TABLE x"]);

check('-- without trailing space is arithmetic, not a comment',
    tokenize("SELECT 5--3;"),
    ["SELECT 5--3"]);

check('leading dump header does not swallow the DDL',
    tokenize("-- SitesSaver Database Export\n-- Table: wp_users\nDROP TABLE IF EXISTS `wp_users`;"),
    ["DROP TABLE IF EXISTS `wp_users`"]);

// --- chunk-boundary cases (the fread window is 64 KiB) ---------------------
$pad = static fn(int $target): string => str_repeat(' ', $target);

check('backslash escape split across a chunk boundary',
    tokenize($pad($CH - 22) . "INSERT INTO t VALUES ('a\\'b;c');"),
    ["INSERT INTO t VALUES ('a\\'b;c')"]);

check('block-comment opener split across a chunk boundary',
    tokenize($pad($CH - 1) . "/* x ; y */ DROP TABLE x;"),
    ["DROP TABLE x"]);

check('block-comment closer split across a chunk boundary',
    tokenize("/* " . str_repeat('z', $CH - 4) . "*/ DROP TABLE x;"),
    ["DROP TABLE x"]);

check('line-comment marker split across a chunk boundary',
    tokenize($pad($CH - 1) . "-- a ; b\nDROP TABLE x;"),
    ["DROP TABLE x"]);

check('doubled quote split across a chunk boundary',
    tokenize($pad($CH - 25) . "INSERT INTO t VALUES ('a''b;c');"),
    ["INSERT INTO t VALUES ('a''b;c')"]);

$big = str_repeat('x', $CH * 3);
check('single statement larger than the read chunk',
    tokenize("INSERT INTO t VALUES ('{$big}');"),
    ["INSERT INTO t VALUES ('{$big}')"]);

$mb = str_repeat('é', 10) . '漢';
check('multibyte character split across a chunk boundary',
    tokenize($pad($CH - 24) . "INSERT INTO t VALUES ('{$mb}');"),
    ["INSERT INTO t VALUES ('{$mb}')"]);

echo "\n=== SERIALIZED-DATA FIDELITY (export format -> rewrite -> unserialize) ===\n";

$OLD = 'https://old.com';
$NEW = 'https://new-domain.example';

roundtrip('plain serialized array', ['u' => $OLD . '/x'], $OLD, $NEW,
    ['u' => $NEW . '/x']);

roundtrip('value containing a backslash', ['u' => $OLD . '/a\\b'], $OLD, $NEW,
    ['u' => $NEW . '/a\\b']);

roundtrip('value containing an apostrophe', ['u' => $OLD . "/it's"], $OLD, $NEW,
    ['u' => $NEW . "/it's"]);

roundtrip('value containing a newline', ['u' => "line1\n" . $OLD . '/x'], $OLD, $NEW,
    ['u' => "line1\n" . $NEW . '/x']);

roundtrip('value containing a double quote', ['u' => $OLD . '/say-"hi"'], $OLD, $NEW,
    ['u' => $NEW . '/say-"hi"']);

roundtrip('value containing a NUL byte', ['u' => $OLD . "/a\0b"], $OLD, $NEW,
    ['u' => $NEW . "/a\0b"]);

roundtrip('JSON-escaped slashes (page builders)', ['u' => 'https:\\/\\/old.com/x'], $OLD, $NEW,
    ['u' => 'https:\\/\\/new-domain.example/x']);

roundtrip('multibyte payload', ['u' => $OLD . '/漢字'], $OLD, $NEW,
    ['u' => $NEW . '/漢字']);

$obj = new stdClass();
$obj->url = $OLD . '/obj';
$expected_obj = new stdClass();
$expected_obj->url = $NEW . '/obj';
roundtrip('serialized object', $obj, $OLD, $NEW, $expected_obj);

// Nested: WordPress re-serializes an already-serialized string, so the value
// PHP reads back is itself a serialized string that must ALSO unserialize.
roundtrip('nested serialized string (maybe_serialize)', serialize(['inner' => $OLD . '/x']),
    $OLD, $NEW, static function ($read) use ($NEW): bool {
        if (!is_string($read)) { return false; }
        $inner = @unserialize($read, ['allowed_classes' => false]);
        return $inner === ['inner' => $NEW . '/x'];
    });

// Shrinking replacement must also fix the length prefix.
roundtrip('shrinking replacement', ['u' => 'https://a-very-long-old-domain.example/x'],
    'https://a-very-long-old-domain.example', 'https://n.co', ['u' => 'https://n.co/x']);

echo "\n=== REPLACEMENT MAP ===\n";
$pairs = Database::build_replacement_pairs('https://old.com', 'https://new.com', 'old.com', 'new.com');
check('json-escaped variant present', isset($pairs['https:\\/\\/old.com']), true);
check('protocol-relative variant present', isset($pairs['//old.com']), true);
check('identical URLs yield no replacements',
    Database::build_replacement_pairs('https://a.com', 'https://a.com', 'a.com', 'a.com'), []);
check('dotless host (localhost) is never substring-replaced',
    isset(Database::build_replacement_pairs('http://localhost', 'http://x.test', 'localhost', 'x.test')['localhost']),
    false);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
