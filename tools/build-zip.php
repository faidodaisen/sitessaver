<?php
/**
 * Build the distributable plugin ZIP.
 *
 * Why not Compress-Archive / Explorer: PowerShell's Compress-Archive writes
 * entry names with BACKSLASH separators on Windows. The ZIP spec (APPNOTE
 * 4.4.17.1) requires forward slashes, and WordPress's unzip_file() therefore
 * fails with "Could not copy file" and refuses to install the plugin. This
 * script writes spec-compliant entries via ZipArchive.
 *
 * Usage: php tools/build-zip.php
 */
declare(strict_types=1);

$root  = dirname(__DIR__);
$slug  = 'sitessaver';
$out   = $root . '/SitesSaver.zip';

// Everything the distributable must NOT contain.
$skip_dirs = [
    '.git', '.gitnexus', '.claude', '.raw', '.idea', '.vscode', '.idx',
    '.local-metadata', '.audit', 'node_modules', 'storage', 'tests', 'tools',
    'css-analysis',
];
$skip_files = [
    '.gitignore', 'CLAUDE.md', 'AGENTS.md', 'CHANGELOG.md', 'desktop.ini',
    'Thumbs.db', '.DS_Store', 'SitesSaver.zip',
];

// Read the shipping version so the build is self-verifying.
$main = (string) file_get_contents($root . '/sitessaver.php');
preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', $main, $hv);
preg_match("/\\\$sitessaver_this_version\s*=\s*'([0-9.]+)'/", $main, $cv);
$header   = $hv[1] ?? '';
$constant = $cv[1] ?? '';

if ($header === '' || $header !== $constant) {
    fwrite(STDERR, "Version mismatch: header='{$header}' constant='{$constant}'\n");
    exit(1);
}

@unlink($out);
$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create {$out}\n");
    exit(1);
}

$added = 0;
$iter  = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iter as $path => $info) {
    $rel = str_replace('\\', '/', substr((string) $path, strlen($root) + 1));

    // Skip whole trees.
    $first = explode('/', $rel)[0];
    if (in_array($first, $skip_dirs, true)) { continue; }

    $base = basename($rel);
    if (in_array($base, $skip_files, true)) { continue; }
    if (str_starts_with($base, 'release-notes-') && str_ends_with($base, '.md')) { continue; }
    if (str_ends_with($base, '.zip')) { continue; }

    // Entry names MUST use forward slashes.
    $entry = $slug . '/' . $rel;

    if ($info->isDir()) {
        $zip->addEmptyDir($entry);
        continue;
    }
    if (!$zip->addFile((string) $path, $entry)) {
        fwrite(STDERR, "addFile failed: {$rel}\n");
        $zip->close();
        exit(1);
    }
    $added++;
}

if (!$zip->close()) {
    fwrite(STDERR, "close() failed — archive may be corrupt\n");
    exit(1);
}

// --- Self-check: reopen and validate the structure -------------------------
$verify = new ZipArchive();
if ($verify->open($out, ZipArchive::CHECKCONS) !== true) {
    fwrite(STDERR, "Built archive failed consistency check\n");
    exit(1);
}

$problems = [];
$has_main = false;
for ($i = 0; $i < $verify->numFiles; $i++) {
    $name = (string) $verify->getNameIndex($i);
    if (str_contains($name, '\\')) { $problems[] = "backslash in entry: {$name}"; }
    if (!str_starts_with($name, $slug . '/')) { $problems[] = "entry outside plugin dir: {$name}"; }
    if ($name === $slug . '/sitessaver.php') { $has_main = true; }
}
if (!$has_main) { $problems[] = 'main plugin file missing'; }
$verify->close();

if ($problems) {
    foreach ($problems as $p) { fwrite(STDERR, "  {$p}\n"); }
    exit(1);
}

printf(
    "built %s  v%s  %d files  %.1f KB\n",
    basename($out),
    $header,
    $added,
    filesize($out) / 1024
);
