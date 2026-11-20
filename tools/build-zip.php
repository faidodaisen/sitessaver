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
 * Usage: php tools/build-zip.php [git-ref]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$slug = 'sitessaver';

// Package committed files only: the blobs of HEAD (or the ref given as the
// first argument), never the working tree. Untracked or modified local files
// cannot end up in the archive.
$ref  = $argv[1] ?? 'HEAD';
$ship = ['assets', 'includes', 'templates', 'LICENSE', 'readme.txt', 'sitessaver.php', 'uninstall.php'];

$git = static function (array $args) use ($root): string {
    $cmd = 'git -C ' . escapeshellarg($root);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $out  = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "git failed: {$cmd}\n" . implode("\n", $out) . "\n");
        exit(1);
    }
    return implode("\n", $out);
};
// proc_open pipes are binary; shell_exec() stops at 0x1A on Windows.
$blob = static function (string $path) use ($root, $ref): string {
    $proc = proc_open(
        ['git', '-C', $root, 'cat-file', 'blob', $ref . ':' . $path],
        [1 => ['pipe', 'wb'], 2 => ['pipe', 'wb']],
        $pipes
    );
    if (!is_resource($proc)) {
        fwrite(STDERR, "git cat-file failed: {$path}\n");
        exit(1);
    }
    $data = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0 || !is_string($data)) {
        fwrite(STDERR, "git cat-file failed: {$path}\n");
        exit(1);
    }
    return $data;
};

// Read the shipping version FIRST: it names the artefact and the build is
// self-verifying, so a header/constant mismatch aborts before anything is
// written.
$main = $blob('sitessaver.php');
preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', $main, $hv);
preg_match("/\\\$sitessaver_this_version\s*=\s*'([0-9.]+)'/", $main, $cv);
$header   = $hv[1] ?? '';
$constant = $cv[1] ?? '';

if ($header === '' || $header !== $constant) {
    fwrite(STDERR, "Version mismatch: header='{$header}' constant='{$constant}'\n");
    exit(1);
}

// Version-stamped filename. Users routinely keep several releases in a
// downloads folder, and an unversioned `SitesSaver.zip` is impossible to tell
// apart once it has been downloaded twice.
$out = $root . '/SitesSaver-' . $header . '.zip';
if (is_file($out)) {
    unlink($out);
}

$files = array_values(array_filter(explode("\n", $git(array_merge(['ls-tree', '-r', '--name-only', $ref, '--'], $ship)))));
if ($files === []) {
    fwrite(STDERR, "No files to package at {$ref}\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create {$out}\n");
    exit(1);
}

$added = 0;
foreach ($files as $rel) {
    // Entry names MUST use forward slashes (git already uses them).
    if (!$zip->addFromString($slug . '/' . $rel, $blob($rel))) {
        fwrite(STDERR, "addFromString failed: {$rel}\n");
        $zip->close();
        exit(1);
    }
    $added++;
}

if (!$zip->close()) {
    fwrite(STDERR, "close() failed, archive may be corrupt\n");
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

    // Nothing in a public artefact may name the maintainer's local tooling,
    // and no dev docs ride along. Asserted on the BUILT archive, because the
    // skip rules above are intent and this is the fact. Every segment is
    // checked: a leaf-only test passes a nested dot-directory's children.
    foreach (explode('/', rtrim($name, '/')) as $segment) {
        if ($segment !== '' && str_starts_with($segment, '.')) {
            $problems[] = "dotfile in archive: {$name}";
            break;
        }
    }
    if (str_ends_with(strtolower($name), '.md')) { $problems[] = "markdown in archive: {$name}"; }
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
