<?php
/**
 * Regression: export pagination must be stable.
 *
 * `LIMIT/OFFSET` with no `ORDER BY` lets MySQL return rows in any order, and
 * OFFSET counts positions in that unspecified order. WordPress writes to
 * wp_options constantly, so rows shift between pages mid-dump: a row that moves
 * across a page boundary is emitted twice and the row that took its place is
 * never emitted at all. The restore then dies on "Duplicate entry for key
 * PRIMARY" and silently loses data.
 *
 * Observed on a stock WP 7.1 install before the fix: 142 INSERT statements for
 * 120 distinct option_ids — 22 rows duplicated, 22 rows lost.
 *
 * Run with:
 *     php tests/test-export-pagination.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('ABSPATH', __DIR__);
define('DB_NAME', 'test');

// wpdb result-format constants the exporter passes through.
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('ARRAY_N')) { define('ARRAY_N', 'ARRAY_N'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }

/**
 * A wpdb stub whose paged reads are NOT in a stable order, exactly like a real
 * MySQL table read without an ORDER BY while other sessions are writing to it.
 * Rows are stored keyed by id; an unordered read rotates the sequence a little
 * further on each call, which is the minimum faithful model of "the row order
 * shifted between page 1 and page 2".
 */
class wpdb {
    public string $last_error = '';
    public string $options = 'wp_options';
    public string $prefix  = 'wp_';
    /** @var array<int,array<string,string>> */
    public array $rows = [];
    /** @var string[] */
    public array $written = [];
    public int $reads = 0;

    public function query($q) { return 1; }

    public function prepare($q, ...$a) {
        foreach ($a as $v) {
            $q = preg_replace('/%d/', (string) (int) $v, (string) $q, 1);
            $q = preg_replace('/%s/', "'" . $v . "'", (string) $q, 1);
        }
        return $q;
    }

    public function esc_like($s) { return $s; }
    public function get_var($q) { return null; }

    public function get_col($q) {
        $q = (string) $q;
        // generated_columns() -> none.
        if (str_contains($q, 'GENERATION_EXPRESSION')) { return []; }
        // export() -> the table list.
        if (str_starts_with($q, 'SHOW TABLES')) { return ['wp_options']; }
        return [];
    }

    public function get_row($q, $t = null) {
        if (str_starts_with((string) $q, 'SHOW CREATE TABLE')) {
            return [0 => 'wp_options', 1 => 'CREATE TABLE `wp_options` (`option_id` bigint, `option_name` varchar(191), PRIMARY KEY (`option_id`))'];
        }
        return null;
    }

    public function get_results($q, $t = null) {
        $q = (string) $q;

        // pagination_key() probe.
        if (str_starts_with($q, 'SHOW INDEX')) {
            return [[
                'Key_name'    => 'PRIMARY',
                'Column_name' => 'option_id',
                'Non_unique'  => '0',
                'Null'        => '',
                'Seq_in_index'=> '1',
            ]];
        }

        if (!str_contains($q, 'SELECT * FROM')) { return []; }

        preg_match('/LIMIT (\d+) OFFSET (\d+)/', $q, $m);
        $limit  = (int) ($m[1] ?? 100);
        $offset = (int) ($m[2] ?? 0);

        $this->reads++;

        $rows = array_values($this->rows);

        if (str_contains($q, 'ORDER BY')) {
            // The fix: a total order over the primary key. Stable across
            // mutations, so no row can move between pages.
            usort($rows, static fn($a, $b) => (int) $a['option_id'] <=> (int) $b['option_id']);
        } else {
            // No ORDER BY: MySQL may return rows in any order, and that order
            // can differ between the two queries that make up a paged read
            // (different access path, buffer pool state, or concurrent writes).
            // Modelled here as a rotation that advances with each read — the
            // minimum faithful model of "the sequence shifted underneath us".
            // A row that rotates backwards across a page boundary is emitted
            // twice; the row it displaces is never emitted at all.
            $shift = ($this->reads - 1) * 3;
            if ($shift > 0 && $rows !== []) {
                $shift = $shift % count($rows);
                $rows  = array_merge(array_slice($rows, $shift), array_slice($rows, 0, $shift));
            }
        }

        return array_slice($rows, $offset, $limit);
    }
}

$GLOBALS['wpdb'] = new wpdb();

if (!function_exists('__')) { function __($s, $d = '') { return $s; } }
if (!function_exists('esc_sql')) { function esc_sql($s) { return $s; } }
if (!function_exists('get_bloginfo')) { function get_bloginfo($k = '') { return '7.1'; } }
if (!function_exists('home_url')) { function home_url($p = '') { return 'https://example.test' . $p; } }

require_once dirname(__DIR__) . '/includes/class-database.php';

use SitesSaver\Database;

$pass = 0;
$fail = 0;
function t(string $label, bool $ok, string $note = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%-56s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $note !== '' ? '  ' . $note : '');
}

// Build 250 rows so the 100-row page size produces three pages.
$db = $GLOBALS['wpdb'];
for ($i = 1; $i <= 250; $i++) {
    $db->rows[$i] = ['option_id' => (string) $i, 'option_name' => 'opt_' . $i];
}
$expected_ids = array_keys($db->rows);

// Run the real exporter.
$out = tempnam(sys_get_temp_dir(), 'sspag');
Database::export($out);
$sql = (string) file_get_contents($out);
unlink($out);

preg_match_all("/INSERT INTO `wp_options` \([^)]*\) VALUES \('(\d+)'/", $sql, $m);
$emitted = array_map('intval', $m[1]);
$counts  = array_count_values($emitted);
$dupes   = array_filter($counts, static fn($n) => $n > 1);

echo "rows in table at start: " . count($expected_ids) . "\n";
echo "INSERT statements emitted: " . count($emitted) . "\n";
echo "distinct ids emitted: " . count($counts) . "\n";
echo "duplicated ids: " . count($dupes) . "\n\n";

t('no row is emitted twice', $dupes === [],
    $dupes === [] ? '' : 'ids: ' . implode(',', array_slice(array_keys($dupes), 0, 5)));

// Nothing is deleted in this model, so a correct exporter must emit every row
// exactly once. Any shortfall means rows were silently dropped from the backup.
$missing = array_diff($expected_ids, $emitted);
t('no row is lost', count($missing) === 0,
    'missing: ' . count($missing));

t('emitted ids are unique', count($emitted) === count($counts));

// The query must actually carry an ORDER BY.
$db2 = new wpdb();
$db2->rows = [1 => ['option_id' => '1', 'option_name' => 'a']];
$GLOBALS['wpdb'] = $db2;
$seen_order_by = false;
$probe = new class extends wpdb {
    public bool $saw_order_by = false;
    public function get_results($q, $t = null) {
        if (str_contains((string) $q, 'SELECT * FROM') && str_contains((string) $q, 'ORDER BY')) {
            $this->saw_order_by = true;
        }
        return parent::get_results($q, $t);
    }
};
$probe->rows = [1 => ['option_id' => '1', 'option_name' => 'a']];
$GLOBALS['wpdb'] = $probe;
$out2 = tempnam(sys_get_temp_dir(), 'sspag2');
Database::export($out2);
unlink($out2);
t('paged SELECT carries an ORDER BY', $probe->saw_order_by);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
