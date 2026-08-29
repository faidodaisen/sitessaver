<?php
/**
 * SitesSaver — scheduled-backup logic regression suite.
 *
 * Standalone: no WordPress, no database. Stubs just enough of the WordPress
 * options/cron/HTTP surface to exercise Schedule's real decision-making:
 * frequency normalisation, multi-frequency selection, per-frequency due
 * tracking, cron event synchronisation, and external-trigger authentication.
 *
 * These are the paths where a silent bug means "backups quietly stopped
 * running", which is the worst possible failure for a backup plugin and the
 * hardest to notice by hand.
 *
 * Run with:
 *     php tests/test-schedule.php
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
define('SITESSAVER_STORAGE_DIR', sys_get_temp_dir() . '/ss-sched');
define('SITESSAVER_TEMP_DIR', SITESSAVER_STORAGE_DIR . '/tmp');

define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);

@mkdir(SITESSAVER_TEMP_DIR, 0777, true);

// Diagnostics raised from inside the plugin directory only. A warning from
// production code is a test failure, same policy as the core suite.
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

$GLOBALS['ss_options']    = [];
$GLOBALS['ss_transients'] = [];
$GLOBALS['ss_cron']       = [];   // list of ['timestamp','recurrence','hook','args']
$GLOBALS['ss_filters']    = [];
$GLOBALS['ss_actions']    = [];
$GLOBALS['ss_mail']       = [];

function __($s, $d = '') { return $s; }
function _x($s, $c = '', $d = '') { return $s; }
function esc_html__($s, $d = '') { return $s; }

function get_option($name, $default = false) {
    return $GLOBALS['ss_options'][$name] ?? $default;
}
function update_option($name, $value, $autoload = null) {
    $GLOBALS['ss_options'][$name] = $value;
    return true;
}
function delete_option($name) {
    unset($GLOBALS['ss_options'][$name]);
    return true;
}

function get_transient($k) { return $GLOBALS['ss_transients'][$k] ?? false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['ss_transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['ss_transients'][$k]); return true; }

function add_action($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['ss_actions'][$hook][] = $cb; }
function add_filter($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['ss_filters'][$hook][] = $cb; }

function wp_schedule_event($timestamp, $recurrence, $hook, $args = []) {
    $GLOBALS['ss_cron'][] = [
        'timestamp'  => $timestamp,
        'recurrence' => $recurrence,
        'hook'       => $hook,
        'args'       => $args,
    ];
    return true;
}

function wp_next_scheduled($hook, $args = []) {
    foreach ($GLOBALS['ss_cron'] as $event) {
        if ($event['hook'] === $hook && $event['args'] === $args) {
            return $event['timestamp'];
        }
    }
    return false;
}

function wp_clear_scheduled_hook($hook, $args = []) {
    $GLOBALS['ss_cron'] = array_values(array_filter(
        $GLOBALS['ss_cron'],
        static fn(array $e): bool => !($e['hook'] === $hook && $e['args'] === $args)
    ));
}

function wp_generate_password(int $n = 12, bool $s = true, bool $e = false): string {
    return substr(bin2hex(random_bytes($n)), 0, $n);
}

function home_url($p = '') { return 'https://example.test' . $p; }
function add_query_arg($key, $value, $url) {
    $sep = str_contains($url, '?') ? '&' : '?';
    return $url . $sep . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
}

function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_unslash($s) { return is_string($s) ? stripslashes($s) : $s; }
function is_email($e) { return (bool) filter_var((string) $e, FILTER_VALIDATE_EMAIL); }
function current_time($type) { return date('Y-m-d H:i:s'); }
function get_bloginfo($k = '') { return 'Test'; }
function wp_mail($to, $subject, $body) { $GLOBALS['ss_mail'][] = compact('to', 'subject', 'body'); return true; }
function human_time_diff($from, $to = 0) { return abs($to - $from) . ' seconds'; }
function status_header($code) {}
function nocache_headers() {}
function sitessaver_get_backups() { return []; }

require_once SITESSAVER_PATH . 'includes/class-schedule.php';

use SitesSaver\Schedule;

$pass = 0; $fail = 0;
function t(string $label, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%-62s %s\n", $label, $ok ? 'PASS' : 'FAIL');
}

function reset_state(): void {
    $GLOBALS['ss_options']    = [];
    $GLOBALS['ss_transients'] = [];
    $GLOBALS['ss_cron']       = [];
}

echo "PHP " . PHP_VERSION . "\n\n=== FREQUENCY DEFINITIONS ===\n";

$freqs = Schedule::frequencies();
t('monthly frequency is offered', isset($freqs['sitessaver_monthly']));
t('monthly interval is 30 days', $freqs['sitessaver_monthly']['interval'] === 30 * DAY_IN_SECONDS);
t('all five frequencies present', count($freqs) === 5);

// array_map preserves the string keys, so compare the values as a list.
$intervals = array_values(array_map(static fn(array $f): int => $f['interval'], $freqs));
$sorted = $intervals;
sort($sorted);
t('frequencies listed ascending', $intervals === $sorted);
t('intervals match their documented lengths', $intervals === [
    HOUR_IN_SECONDS, 12 * HOUR_IN_SECONDS, DAY_IN_SECONDS, WEEK_IN_SECONDS, 30 * DAY_IN_SECONDS,
]);

echo "\n=== NORMALISATION ===\n";

t("'monthly' aliases to the namespaced key",
    Schedule::normalize_frequency('monthly') === 'sitessaver_monthly');
t('namespaced key passes through',
    Schedule::normalize_frequency('sitessaver_monthly') === 'sitessaver_monthly');
t('unknown key falls back to daily',
    Schedule::normalize_frequency('every-picosecond') === 'daily');
t('interval_for resolves the alias',
    Schedule::interval_for('monthly') === 30 * DAY_IN_SECONDS);

echo "\n=== MULTI-FREQUENCY SELECTION ===\n";

reset_state();
t('empty settings default to daily',
    Schedule::selected_frequencies([]) === ['daily']);

t('legacy single frequency is honoured',
    Schedule::selected_frequencies(['frequency' => 'weekly']) === ['weekly']);

t('legacy monthly string is migrated',
    Schedule::selected_frequencies(['frequency' => 'monthly']) === ['sitessaver_monthly']);

t('multiple frequencies are all kept',
    Schedule::selected_frequencies(['frequencies' => ['daily', 'sitessaver_monthly']])
        === ['daily', 'sitessaver_monthly']);

t('duplicates collapse',
    Schedule::selected_frequencies(['frequencies' => ['daily', 'daily', 'monthly', 'sitessaver_monthly']])
        === ['daily', 'sitessaver_monthly']);

t('selection is returned in canonical order',
    Schedule::selected_frequencies(['frequencies' => ['sitessaver_monthly', 'hourly', 'weekly']])
        === ['hourly', 'weekly', 'sitessaver_monthly']);

t('frequencies[] wins over the legacy key',
    Schedule::selected_frequencies(['frequency' => 'hourly', 'frequencies' => ['weekly']])
        === ['weekly']);

t('non-string members are ignored',
    Schedule::selected_frequencies(['frequencies' => ['daily', 42, null]]) === ['daily']);

echo "\n=== CRON EVENT SYNC ===\n";

reset_state();
Schedule::sync_cron_events(['daily', 'sitessaver_monthly'], true);
t('one event scheduled per frequency', count($GLOBALS['ss_cron']) === 2);
t('daily event carries its frequency arg',
    wp_next_scheduled('sitessaver_scheduled_backup', ['daily']) !== false);
t('monthly event carries its frequency arg',
    wp_next_scheduled('sitessaver_scheduled_backup', ['sitessaver_monthly']) !== false);

// This is the property that lets several frequencies coexist: distinct args
// mean wp_next_scheduled() sees them as separate events rather than one
// overwriting the other.
$daily_ts   = wp_next_scheduled('sitessaver_scheduled_backup', ['daily']);
$monthly_ts = wp_next_scheduled('sitessaver_scheduled_backup', ['sitessaver_monthly']);
t('events are distinct, not collapsed', $daily_ts !== $monthly_ts);
t('first daily run is one interval out',
    $daily_ts > time() + DAY_IN_SECONDS - 10 && $daily_ts <= time() + DAY_IN_SECONDS + 5);
t('first monthly run is one interval out',
    $monthly_ts > time() + (30 * DAY_IN_SECONDS) - 10);

// Re-syncing a narrower selection must remove the events that are gone.
Schedule::sync_cron_events(['daily'], true);
t('removed frequency is unscheduled',
    wp_next_scheduled('sitessaver_scheduled_backup', ['sitessaver_monthly']) === false);
t('kept frequency stays scheduled',
    wp_next_scheduled('sitessaver_scheduled_backup', ['daily']) !== false);

// Disabling clears everything.
Schedule::sync_cron_events(['daily'], false);
t('disabling clears all events', $GLOBALS['ss_cron'] === []);

// A legacy no-arg event (written by the single-frequency version) must be
// swept too, or it fires forever alongside the new ones.
reset_state();
wp_schedule_event(time() + 60, 'daily', 'sitessaver_scheduled_backup');
Schedule::sync_cron_events(['weekly'], true);
t('legacy no-arg event is cleared',
    wp_next_scheduled('sitessaver_scheduled_backup') === false);
t('only the new event remains', count($GLOBALS['ss_cron']) === 1);

echo "\n=== NEXT SCHEDULED RUN ===\n";

reset_state();
Schedule::sync_cron_events(['hourly', 'sitessaver_monthly'], true);
$next = Schedule::next_scheduled_run(['hourly', 'sitessaver_monthly']);
t('next run is the soonest of the set',
    $next === wp_next_scheduled('sitessaver_scheduled_backup', ['hourly']));

reset_state();
t('no events reports zero', Schedule::next_scheduled_run(['daily']) === 0);

echo "\n=== PER-FREQUENCY DUE TRACKING ===\n";

reset_state();
$settings = ['enabled' => true, 'frequencies' => ['daily', 'sitessaver_monthly']];

t('never-run frequencies are all due',
    Schedule::due_frequencies($settings) === ['daily', 'sitessaver_monthly']);

// Seed both, then age only the daily one past its interval.
Schedule::seed_last_runs(['daily', 'sitessaver_monthly']);
t('freshly seeded frequencies are not due',
    Schedule::due_frequencies($settings) === []);

$runs = get_option(Schedule::LAST_RUN_OPTION);
$runs['daily'] = time() - DAY_IN_SECONDS - 60;
update_option(Schedule::LAST_RUN_OPTION, $runs);

t('only the elapsed frequency is due',
    Schedule::due_frequencies($settings) === ['daily']);

// Age the monthly one too.
$runs['sitessaver_monthly'] = time() - (31 * DAY_IN_SECONDS);
update_option(Schedule::LAST_RUN_OPTION, $runs);
t('both due once both intervals elapse',
    Schedule::due_frequencies($settings) === ['daily', 'sitessaver_monthly']);

// A monthly-only schedule must NOT be due a day after running. This is the
// regression that a single shared last-run timestamp would cause.
reset_state();
$monthly_only = ['enabled' => true, 'frequencies' => ['sitessaver_monthly']];
Schedule::seed_last_runs(['sitessaver_monthly']);
$runs = get_option(Schedule::LAST_RUN_OPTION);
$runs['sitessaver_monthly'] = time() - (2 * DAY_IN_SECONDS);
update_option(Schedule::LAST_RUN_OPTION, $runs);
t('monthly is not due two days in', Schedule::due_frequencies($monthly_only) === []);

echo "\n=== LEGACY LAST-RUN MIGRATION ===\n";

reset_state();
// Pre-multi-frequency installs stored a bare integer here.
update_option(Schedule::LAST_RUN_OPTION, time() - 30);
$runs = Schedule::last_runs();
t('scalar last-run expands to every frequency',
    count($runs) === 5 && $runs['daily'] === $runs['sitessaver_monthly']);
t('legacy timestamp defers the next run',
    Schedule::due_frequencies(['frequencies' => ['daily']]) === []);

echo "\n=== NEXT DUE TIMESTAMP ===\n";

reset_state();
t('never run means due now',
    Schedule::next_due_timestamp(['frequencies' => ['daily']]) === 0);

Schedule::seed_last_runs(['daily', 'sitessaver_monthly']);
$due_at = Schedule::next_due_timestamp(['frequencies' => ['daily', 'sitessaver_monthly']]);
t('next due is the soonest interval',
    $due_at > time() + DAY_IN_SECONDS - 10 && $due_at <= time() + DAY_IN_SECONDS + 5);

echo "\n=== TRIGGER KEY ===\n";

reset_state();
$key = Schedule::trigger_key();
t('key is generated on first use', strlen($key) === 32);
t('key is stable across reads', Schedule::trigger_key() === $key);

$rotated = Schedule::regenerate_trigger_key();
t('regeneration issues a different key', $rotated !== $key);
t('rotated key is persisted', Schedule::trigger_key() === $rotated);

$url = Schedule::trigger_url();
t('trigger URL carries the key', str_contains($url, $rotated));
t('trigger URL uses the documented query var',
    str_contains($url, Schedule::TRIGGER_QUERY_VAR . '='));

echo "\n=== CONCURRENCY LOCK ===\n";

reset_state();
update_option('sitessaver_schedule', ['enabled' => true, 'frequencies' => ['daily']]);
set_transient('sitessaver_schedule_running', 1, 3600);
t('a locked run is skipped, not duplicated',
    Schedule::instance()->run_scheduled_backup('daily') === null);

reset_state();
update_option('sitessaver_schedule', ['enabled' => false]);
t('a disabled schedule does not run',
    Schedule::instance()->run_scheduled_backup('daily') === null);

echo "\n=== PLUGIN DIAGNOSTICS ===\n";
if ($PLUGIN_DIAGS) {
    foreach ($PLUGIN_DIAGS as $d) {
        echo "  $d\n";
    }
}
t('no warnings/notices/deprecations from plugin code', $PLUGIN_DIAGS === []);

echo "\n" . str_repeat('-', 72) . "\n";
printf("PASS: %d   FAIL: %d\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
