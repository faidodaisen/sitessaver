# SitesSaver v1.1.8 — Hardcoded URLs in custom plugins/themes

## What's fixed

After a cross-domain restore, images rendered by **custom plugins or themes** that hardcoded the source site URL in their source files (PHP arrays, JSON config, CSS `background-image:`) showed as broken. The database was fully rewritten, but text files under `wp-content/plugins/`, `wp-content/themes/`, and `wp-content/mu-plugins/` kept the old URL.

Example real-world case:

```php
// wp-content/plugins/felda-travel/modules/umrah-redesign/data.php
return [
    'gallery' => [
        ['src' => 'http://feldatravel.test/wp-content/uploads/2026/01/img.webp'],
        // ... 12 more hardcoded URLs
    ],
];
```

v1.1.7 rewrote every URL in `wp_postmeta`, `wp_posts`, etc. but this file was never touched. The page rendered `<img src="http://feldatravel.test/...">` on the HTTPS destination → broken image.

## What's new

Restore now applies the same URL-replacement map to **text files** under plugin/theme/mu-plugin trees:

- Same `build_replacement_pairs()` map as the DB layer → cross-scheme, www↔non-www, JSON-escaped slashes, protocol-relative variants all covered
- Text-extension allowlist (`.php`, `.html`, `.css`, `.js`, `.json`, `.xml`, `.svg`, `.txt`, `.md`, `.yml`, `.ini`, `.conf`, `.po`, …)
- Skips `vendor/`, `node_modules/`, `.git/`, `composer.lock`, `package-lock.json`, `yarn.lock`
- 5 MB size ceiling (minified bundles passed through unchanged)
- `wp-content/uploads/` unchanged — binary image/PDF content is never rewritten
- Files without any source pattern skip rewrite entirely → normal `copy()` cost only

## Upgrade

Drop-in replacement. Deploy v1.1.8 to source AND destination, re-export from source, re-import on destination. URL rewrites now cover both the database and the source files in one pass.

No config, no UI changes.

## Tests

9-case harness in `storage/ss_file_rewrite_test.php` covering the felda-travel reproduction, JSON escaping, CSS, JS, binary-skip, vendor-skip, size-limit-skip. All pass.
