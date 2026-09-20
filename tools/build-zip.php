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

$root = dirname(__DIR__);
$slug = 'sitessaver';

// Read the shipping version FIRST: it names the artefact and the build is
// self-verifying, so a header/constant mismatch aborts before anything is
// written.
$main = (string) file_get_contents($root . '/sitessaver.php');
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

// Everything the distributable must NOT contain. Matched by CLASS, not by
// today's tool list: naming individual editors or utilities means the next
// one needs another build change, and leaks its name into a public artefact.
$skip_dirs = [
    '.git', 'node_modules', 'storage', 'tests', 'tools', 'css-analysis',
];
$skip_files = [
    'CHANGELOG.md', 'desktop.ini', 'Thumbs.db',
];

// Remove ZIPs from previous builds so the repo never carries two versions of
// the artefact (any `*.zip` is skipped during packaging regardless).
foreach (glob($root . '/SitesSaver*.zip') ?: [] as $stale) {
    @unlink($stale);
}

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

    // Dotfiles and dot-directories are tooling and local state by definition:
    // config, caches, editor and local metadata. None of it belongs in a
    // distributed plugin, and matching the class means new tools need no
    // build change and never leak their names into a public artefact.
    //
    // Tested on EVERY path segment, not just the leaf: basename() of a nested
    // path under a dot-directory returns the child's name, so a leaf-only
    // test silently packaged the whole tree.
    $dotted = false;
    foreach (explode('/', $rel) as $segment) {
        if ($segment !== '' && str_starts_with($segment, '.')) { $dotted = true; break; }
    }
    if ($dotted) { continue; }

    // Markdown is documentation for the repo, not payload for the install.
    if (str_ends_with(strtolower($base), '.md')) { continue; }
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
