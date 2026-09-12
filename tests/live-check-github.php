<?php
/**
 * Live smoke test against the real GitHub API. Not part of the regression
 * suite (it needs network); run manually to confirm the configured repo
 * actually resolves to a downloadable package.
 *
 *     php tests/live-check-github.php [owner/repo]
 */
declare(strict_types=1);

$repo = $argv[1] ?? 'faidodaisen/sitessaver';
$installed = '1.2.1';

$ctx = stream_context_create(['http' => [
    'header' => "User-Agent: SitesSaver-live-check\r\nAccept: application/vnd.github+json\r\n",
    'ignore_errors' => true,
]]);

$url  = "https://api.github.com/repos/$repo/releases?per_page=10";
$body = @file_get_contents($url, false, $ctx);
$code = 0;
foreach ($http_response_header ?? [] as $h) {
    if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int) $m[1]; }
}

printf("GET %s -> HTTP %d\n\n", $url, $code);
if ($code !== 200) { fwrite(STDERR, "Lookup failed.\n$body\n"); exit(1); }

$releases = json_decode((string) $body, true);
$best = null;

foreach ($releases as $r) {
    if (!empty($r['draft']) || !empty($r['prerelease'])) { continue; }
    if (!preg_match('/(\d+(?:\.\d+)*(?:-[0-9A-Za-z.]+)?)/', (string) $r['tag_name'], $m)) { continue; }
    $v = $m[1];
    if ($best !== null && version_compare($v, $best['v'], '<=')) { continue; }

    $pkg = ''; $isAsset = false;
    foreach ($r['assets'] ?? [] as $a) {
        if (str_ends_with(strtolower($a['name']), '.zip')) {
            $pkg = $a['browser_download_url']; $isAsset = true; break;
        }
    }
    if ($pkg === '') { $pkg = $r['zipball_url']; }
    $best = ['v' => $v, 'pkg' => $pkg, 'asset' => $isAsset, 'url' => $r['html_url']];
}

if ($best === null) { fwrite(STDERR, "No eligible release found.\n"); exit(1); }

printf("latest eligible : %s\n", $best['v']);
printf("installed       : %s\n", $installed);
printf("update offered  : %s\n", version_compare($best['v'], $installed, '>') ? 'YES' : 'no (up to date)');
printf("package type    : %s\n", $best['asset'] ? 'built release asset' : 'generated zipball');
printf("package URL     : %s\n\n", $best['pkg']);

// Download and inspect the package the same way WordPress would.
$tmp = sys_get_temp_dir() . '/ss-live-' . uniqid() . '.zip';
$data = @file_get_contents($best['pkg'], false, $ctx);
if ($data === false) { fwrite(STDERR, "Package download failed.\n"); exit(1); }
file_put_contents($tmp, $data);

$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::CHECKCONS) !== true) {
    fwrite(STDERR, "Downloaded package is not a valid ZIP.\n"); @unlink($tmp); exit(1);
}

$root = explode('/', (string) $zip->getNameIndex(0))[0];
$hasMain = false;
for ($i = 0; $i < $zip->numFiles; $i++) {
    if ($zip->getNameIndex($i) === $root . '/sitessaver.php') { $hasMain = true; break; }
}
printf("downloaded      : %.1f KB, %d entries\n", strlen($data) / 1024, $zip->numFiles);
printf("root folder     : %s\n", $root);
printf("needs rename    : %s\n", $root === 'sitessaver' ? 'no' : 'YES (fix_source_dir handles this)');
printf("main file found : %s\n", $hasMain ? 'yes' : 'NO — package would not install');
$zip->close();
@unlink($tmp);

exit($hasMain ? 0 : 1);
