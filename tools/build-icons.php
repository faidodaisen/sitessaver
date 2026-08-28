<?php
/**
 * Build a minimal, self-hosted icon stylesheet containing ONLY the glyphs the
 * plugin actually uses. Shipping the full 123 KB remixicon.css (which declares
 * ~2800 icons) is wasteful; more importantly, loading it from a CDN breaks the
 * admin UI on offline installs and violates the WordPress.org plugin
 * guidelines on bundling external resources.
 */
$root = 'C:/laragon/www/sitessaver';

// 1. Collect every ri-* class actually referenced in templates + JS + CSS.
$used = [];
$files = array_merge(
    glob($root . '/templates/*.php'),
    glob($root . '/assets/js/*.js'),
    [$root . '/assets/css/admin.css'],
    glob($root . '/includes/*.php')
);
foreach ($files as $f) {
    if (preg_match_all('/\bri-[a-z0-9-]+/', (string) file_get_contents($f), $m)) {
        foreach ($m[0] as $cls) { $used[$cls] = true; }
    }
}
unset($used['ri-spin']); // animation helper, not a glyph
ksort($used);
echo 'icons referenced: ' . count($used) . PHP_EOL;

// 2. Pull each glyph's content codepoint out of the upstream stylesheet.
$css = (string) file_get_contents($root . '/assets/css/remixicon.css');

$rules = [];
$missing = [];
foreach (array_keys($used) as $cls) {
    // Upstream form: .ri-add-circle-line:before { content: "\ea6d"; }
    if (preg_match('/\.' . preg_quote($cls, '/') . ':before\s*\{\s*content:\s*"(\\\\[0-9a-fA-F]+)"/', $css, $m)) {
        $rules[$cls] = $m[1];
    } else {
        $missing[] = $cls;
    }
}
echo 'resolved: ' . count($rules) . ', unresolved: ' . count($missing) . PHP_EOL;
if ($missing) { echo '  MISSING: ' . implode(', ', $missing) . PHP_EOL; }

// 3. Emit the subset stylesheet.
$out  = "/*!\n";
$out .= " * SitesSaver icon subset — derived from RemixIcon 3.5.0 (Apache-2.0).\n";
$out .= " * https://github.com/Remix-Design/RemixIcon\n";
$out .= " *\n";
$out .= " * Only the " . count($rules) . " glyphs this plugin uses are declared, and the font is\n";
$out .= " * served locally. Loading the upstream CSS from a CDN broke the admin UI on\n";
$out .= " * offline installs and is disallowed by the WordPress.org plugin guidelines.\n";
$out .= " * Regenerate with tools/build-icons.php after adding a new icon.\n";
$out .= " */\n";
$out .= "@font-face {\n";
$out .= "    font-family: 'remixicon';\n";
$out .= "    src: url('../fonts/remixicon.woff2') format('woff2');\n";
$out .= "    font-display: swap;\n";
$out .= "    font-weight: normal;\n";
$out .= "    font-style: normal;\n";
$out .= "}\n\n";
$out .= "[class^=\"ri-\"], [class*=\" ri-\"] {\n";
$out .= "    font-family: 'remixicon' !important;\n";
$out .= "    font-style: normal;\n";
$out .= "    font-weight: normal;\n";
$out .= "    font-variant: normal;\n";
$out .= "    line-height: 1;\n";
$out .= "    text-transform: none;\n";
$out .= "    speak: never;\n";
$out .= "    display: inline-block;\n";
$out .= "    vertical-align: middle;\n";
$out .= "    -webkit-font-smoothing: antialiased;\n";
$out .= "    -moz-osx-font-smoothing: grayscale;\n";
$out .= "}\n\n";
foreach ($rules as $cls => $code) {
    $out .= ".{$cls}:before { content: \"{$code}\"; }\n";
}
$out .= "\n@keyframes ri-spin { to { transform: rotate(360deg); } }\n";
$out .= ".ri-spin { animation: ri-spin 1s linear infinite; }\n";

file_put_contents($root . '/assets/css/icons.css', $out);
echo 'wrote assets/css/icons.css (' . round(strlen($out) / 1024, 1) . " KB)\n";

// 4. Drop the full upstream stylesheet — the subset replaces it.
@unlink($root . '/assets/css/remixicon.css');
echo "removed vendored remixicon.css\n";
