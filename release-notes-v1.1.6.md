# SitesSaver v1.1.6

**Release date:** 2026-04-19
**Type:** Critical bugfix — restore finalisation + page-builder migrations

Upgrade strongly recommended for anyone using SitesSaver to **migrate sites built with Breakdance, Elementor, Oxygen, or Bricks**, or who has hit the "An error occurred" message on the post-restore "Finish & log out" step.

---

## What was broken

### 1. "Finish & log out" modal failed with a generic error

On a freshly restored site, clicking **Finish & log out** in the completion modal showed an error popup instead of logging the user out. The documented workaround (close the modal with X, manually visit Settings → Permalinks, save twice) worked — but it meant the plugin's primary finalisation path was silently broken on most real-world sites.

**Root cause.** The `sitessaver_finalize_restore` AJAX handler called `wp_logout()` **before** `wp_send_json_success()`. The `wp_logout` WordPress action is a common integration point for security, membership, and SSO plugins, many of which issue `wp_redirect() + exit` or `wp_die()` inside the hook. When they did, the PHP request terminated before the JSON response body was flushed — the browser saw an empty or redirect response, and the modal's JS error branch fired.

### 2. Admin navigation between restore and logout silently consumed the pending state

If the user navigated to any WordPress admin page between a successful restore and clicking the finalise button (e.g. to glance at the dashboard), the plugin consumed the deferred finalisation option too early. Plugin and theme activation then ran in the wrong request context — which is exactly the WP 6.7 "textdomain loaded too early" scenario that the 1.1.3 refactor was supposed to prevent.

### 3. Breakdance / Elementor / Oxygen / Bricks images broke after migration

After migrating a site built with Breakdance (and likely other major builders), some images rendered as broken. The source of truth for a builder's layout — `_breakdance_data` in `wp_postmeta` for Breakdance, similar keys for the others — is stored as a JSON blob **inside** a serialized PHP string. Inside that JSON, URLs are backslash-escaped:

```
https:\/\/old-site.com\/wp-content\/uploads\/hero.jpg
```

The plugin's serialize-aware URL replacer only matched the plain form `https://old-site.com`, so JSON-escaped URLs slipped through untouched. After migration, image `src` attributes continued pointing at the source domain — hence the broken images.

---

## What's fixed in 1.1.6

### Finalisation flow rebuilt (architectural)

The whole "click Finish & log out → AJAX → logout → land on Permalinks" path has been replaced with a direct client-side redirect to `wp-login.php`. Why the change:

The AJAX-based approach was fragile in three independent ways, and **any one of them would produce the same generic "An error occurred" popup with no diagnostic in the UI:**

1. **Auth drift.** The browser's cookie was signed by the pre-restore DB; the restored `wp_users` + auth salts don't recognise it. `check_ajax_referer` and `current_user_can` silently failed.
2. **`wp_logout` hook interference.** Security / membership / SSO plugins commonly `wp_redirect() + exit` inside the `wp_logout` action, killing the PHP request before the JSON response flushed.
3. **Middleware corruption.** Cloudflare and similar WAFs sometimes wrap slow AJAX responses in HTML interstitials (challenge pages, 5xx rebrandings), breaking the client's JSON parse.

The new flow:

1. On restore success the server returns a one-time token **and** a pre-built `wp-login.php?redirect_to=<permalinks?sitessaver_finalize=TOKEN>` URL.
2. The modal's "Finish & log out" button clears WordPress cookies on the client, then navigates directly to that URL — **no AJAX round-trip**.
3. Because the auth cookie is gone (and wouldn't have validated anyway), `wp-login.php` shows the login prompt. The user signs in with the restored credentials.
4. WordPress redirects to the permalinks page carrying the token. `admin_init` validates it (`hash_equals`), runs the deferred activation + `switch_theme()` under the fresh session, and drops the two-save banner.

This sidesteps all three failure modes.

### Additional resilience

- **`switch_theme()` guarded.** A restored theme with a fatal in `after_switch_theme` or `setup_theme` used to 500 the permalinks page mid-finalise. Now it's wrapped in `try/catch` — the error is logged to `debug.log`, finalisation completes, and the user can pick a different theme from Appearance after the round-trip.

### Still included (from earlier 1.1.6 work)

- **Correct post-migration login URL.** The redirect URL is computed from the restored `siteurl`, so users migrating between domains land on the right login page.
- **Deferred-finalisation gate.** The deferred work runs exactly once, at the `options-permalink.php?sitessaver_finalize=TOKEN` landing, not on any admin page load that happens to coincide with an in-flight restore.
- **JSON-escaped URL replacement.** The serialize-aware replacer now recognises `\/`-escaped URL variants alongside plain ones. Serialized-string byte-length prefixes are recomputed correctly for either form. **This fixes broken images in Breakdance, Elementor, Oxygen, and Bricks sites after migration.**

---

## Upgrade notes

Drop-in replacement. No schema changes. Existing backups created by 1.1.5 or earlier restore correctly with 1.1.6 — the JSON-escape fix is applied at import time, so any future restore will benefit even from an older backup.

If you still have a site mid-restore on 1.1.5 (stuck on the error modal), upgrading to 1.1.6 and clicking **Finish & log out** again will complete the flow.

---

## Known limitations (will revisit)

- **Filesystem absolute paths inside `_wp_attachment_metadata`** are not yet rewritten. In practice WordPress regenerates these on next `wp_get_attachment_metadata()` call, but if a host uses a non-standard uploads path, a `wp media regenerate` run may be required after migration. Planned for 1.2.0.
- **Custom non-prefixed tables** (e.g. some page builders use their own table namespaces) are still skipped by the export. If your stack depends on one, raise an issue with the table prefix and we'll widen the export filter.
