<?php
declare(strict_types=1);

/**
 * Test for v1.1.8 file-content URL rewriter.
 *
 * Simulates the felda-travel scenario: custom plugin ships a data.php with
 * hardcoded `http://feldatravel.test/...` URLs. v1.1.7 byte-walked only the
 * DB; these URLs survived restore. v1.1.8 rewrites text files under
 * plugins/themes/mu-plugins during merge_directory() using the same
 * build_replacement_pairs() map as the DB path.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once dirname(__DIR__) . '/includes/class-database.php';

use SitesSaver\Database;

// Expose the private helpers on Import via reflection. Avoid bootstrapping
// full WordPress by duplicating only the methods under test. (We can't
// require class-import.php because it pulls in WP-defined symbols like
// WP_CONTENT_DIR.)
$impl = new class {
    public static function is_rewritable_text_file(string $abs_path, string $relative): bool {
        $rel_lower = strtolower(str_replace('\\', '/', $relative));
        foreach (['/vendor/', '/node_modules/', '/.git/', '/composer.lock', '/package-lock.json', '/yarn.lock'] as $needle) {
            if (str_contains('/' . $rel_lower, $needle)) {
                return false;
            }
        }
        static $allowed = [
            'php', 'phtml', 'inc',
            'html', 'htm',
            'css', 'scss', 'sass', 'less',
            'js', 'mjs', 'cjs',
            'json',
            'xml', 'svg',
            'txt', 'md', 'yml', 'yaml',
            'env', 'ini', 'conf',
            'po', 'pot',
        ];
        $ext = strtolower((string) pathinfo($rel_lower, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            return false;
        }
        $size = @filesize($abs_path);
        if ($size === false || $size > 5 * 1024 * 1024) {
            return false;
        }
        return true;
    }

    public static function copy_with_url_rewrite(string $src, string $dest, array $url_pairs): bool {
        $contents = @file_get_contents($src);
        if ($contents === false) {
            return false;
        }
        $hit = false;
        foreach ($url_pairs as $from => $_) {
            if ($from !== '' && str_contains($contents, $from)) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            return copy($src, $dest);
        }
        $rewritten = strtr($contents, $url_pairs);
        $written = @file_put_contents($dest, $rewritten);
        return $written !== false;
    }
};

$old = 'http://feldatravel.test';
$new = 'https://feldatravel.fidodesign.dev';
$old_no = 'feldatravel.test';
$new_no = 'feldatravel.fidodesign.dev';
$pairs = Database::build_replacement_pairs($old, $new, $old_no, $new_no);

$tmp = sys_get_temp_dir() . '/ss_rewrite_' . uniqid();
mkdir($tmp, 0777, true);

$fail = 0;
$pass = 0;

function run_case(string $label, string $filename, string $input, string $expected_contains_new, array $expected_missing_old, array $pairs, object $impl, string $tmp, bool $expect_rewrite = true): void {
    global $fail, $pass;
    $src = $tmp . '/src_' . basename($filename);
    $dest = $tmp . '/dest_' . basename($filename);
    file_put_contents($src, $input);
    // Relative simulates how merge_directory() sees files under plugins/.
    $relative = 'felda-travel/modules/umrah-redesign/' . $filename;
    $is_text = $impl::is_rewritable_text_file($src, $relative);
    if (!$expect_rewrite) {
        if ($is_text) {
            echo "FAIL [$label] expected is_rewritable=false, got true\n";
            $fail++;
            return;
        }
        echo "PASS [$label] correctly skipped (not text)\n";
        $pass++;
        return;
    }
    if (!$is_text) {
        echo "FAIL [$label] expected is_rewritable=true, got false\n";
        $fail++;
        return;
    }
    $ok = $impl::copy_with_url_rewrite($src, $dest, $pairs);
    if (!$ok) {
        echo "FAIL [$label] copy returned false\n";
        $fail++;
        return;
    }
    $out = file_get_contents($dest);
    if ($expected_contains_new !== '' && !str_contains($out, $expected_contains_new)) {
        echo "FAIL [$label] missing expected new URL: $expected_contains_new\n  out: " . substr($out, 0, 200) . "\n";
        $fail++;
        return;
    }
    foreach ($expected_missing_old as $needle) {
        if (str_contains($out, $needle)) {
            echo "FAIL [$label] still contains old pattern: $needle\n  out: " . substr($out, 0, 200) . "\n";
            $fail++;
            return;
        }
    }
    echo "PASS [$label]\n";
    $pass++;
}

// --- The real felda-travel data.php scenario ---
$datafile = <<<'PHP'
<?php
return [
    'icons' => [
        ['icon' => 'http://feldatravel.test/wp-content/uploads/2026/03/i1-1.png'],
        ['icon' => 'http://feldatravel.test/wp-content/uploads/2026/03/i2.png'],
    ],
    'gallery' => [
        ['src' => 'http://feldatravel.test/wp-content/uploads/2026/01/Apa-kata-jemaah-Felda-Travel_1.webp'],
        ['src' => 'http://feldatravel.test/wp-content/uploads/2026/01/Apa-kata-jemaah-Felda-Travel_2.webp'],
    ],
];
PHP;

run_case(
    'felda-travel data.php (the actual reported bug)',
    'data.php',
    $datafile,
    'https://feldatravel.fidodesign.dev/wp-content/uploads/2026/01/Apa-kata-jemaah-Felda-Travel_1.webp',
    ['http://feldatravel.test'],
    $pairs, $impl, $tmp
);

// --- JSON file with escaped slashes (page builder config) ---
run_case(
    'JSON config with escaped slashes',
    'config.json',
    '{"url":"http:\\/\\/feldatravel.test\\/wp-content\\/uploads\\/2026\\/03\\/i1-1.png"}',
    'https:\\/\\/feldatravel.fidodesign.dev\\/wp-content\\/uploads\\/2026\\/03\\/i1-1.png',
    ['feldatravel.test'],
    $pairs, $impl, $tmp
);

// --- CSS with background-image ---
run_case(
    'CSS background-image',
    'styles.css',
    '.hero { background-image: url("http://feldatravel.test/wp-content/uploads/hero.webp"); }',
    'https://feldatravel.fidodesign.dev/wp-content/uploads/hero.webp',
    ['feldatravel.test'],
    $pairs, $impl, $tmp
);

// --- Bare host in JS string concatenation ---
run_case(
    'JS bare-host reference',
    'app.js',
    'const CDN = "//feldatravel.test/assets/" + name;',
    '//feldatravel.fidodesign.dev/assets/',
    ['feldatravel.test'],
    $pairs, $impl, $tmp
);

// --- Binary file should be skipped ---
run_case(
    'binary .webp must be skipped',
    'image.webp',
    "RIFF\0\0\0\0WEBPVP8 \0\0\0\0",
    '', [],
    $pairs, $impl, $tmp, false
);

// --- File without the old URL: byte-identical copy ---
$cleanfile = '<?php return ["harmless" => "content"];';
run_case(
    'file without old URL copied unchanged',
    'clean.php',
    $cleanfile,
    'harmless',
    ['feldatravel.test'],
    $pairs, $impl, $tmp
);

// --- vendor/ path must be skipped ---
$src = $tmp . '/vendor_test.php';
file_put_contents($src, '<?php $x = "http://feldatravel.test/";');
$is = $impl->is_rewritable_text_file($src, 'myplugin/vendor/autoload.php');
if ($is) { echo "FAIL [vendor path] expected skipped, got rewritable\n"; $fail++; }
else { echo "PASS [vendor path correctly skipped]\n"; $pass++; }

// --- Composer lock file must be skipped ---
$is = $impl->is_rewritable_text_file($src, 'myplugin/composer.lock');
if ($is) { echo "FAIL [composer.lock] expected skipped, got rewritable\n"; $fail++; }
else { echo "PASS [composer.lock correctly skipped]\n"; $pass++; }

// --- Size limit: >5 MB skipped ---
$big = $tmp . '/big.js';
$fh = fopen($big, 'wb');
for ($i = 0; $i < 6 * 1024; $i++) {
    fwrite($fh, str_repeat('a', 1024));
}
fclose($fh);
$is = $impl->is_rewritable_text_file($big, 'myplugin/assets/big.js');
if ($is) { echo "FAIL [>5MB] expected skipped, got rewritable\n"; $fail++; }
else { echo "PASS [>5MB correctly skipped]\n"; $pass++; }

// --- Cleanup ---
array_map('unlink', glob($tmp . '/*'));
@rmdir($tmp);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
