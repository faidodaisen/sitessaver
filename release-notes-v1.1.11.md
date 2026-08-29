# SitesSaver 1.1.11 — Post-restore finalisation actually logs you out

A follow-up to 1.1.10. The restore itself worked, but the "Finish & log out"
step at the end did not do either of the two things it promised: it did not log
you out, and clicking through landed you on the **Dashboard** instead of
**Settings → Permalinks**.

Both causes were reproduced against a live WordPress 7.1 install before being
fixed, and the whole flow was re-walked over real HTTP afterwards.

---

## Fixed — the logout never happened

The modal said "you will be logged out". The old code tried to achieve that by
deleting WordPress's auth cookies from JavaScript, on the assumption that the
pre-restore cookie would no longer validate against the restored database.

Both halves of that were wrong:

- WordPress sets its auth cookies **HttpOnly**. `document.cookie` cannot touch
  them, so the client-side cookie sweep silently did nothing to the only cookies
  that mattered.
- The cookie only stops validating if the backup carries **different auth
  salts**. Restoring a backup of the same site keeps the salts, so the session
  stayed perfectly valid and the user was never logged out at all.

The logout is now forced server-side: the finalize URL carries `reauth=1`, which
makes `wp-login.php` call `wp_clear_auth_cookie()` and present the login prompt
regardless of whether the existing session was still good.

## Fixed — clicking through landed on the Dashboard, not Permalinks

After login, `wp-login.php` passes `redirect_to` through
`wp_safe_redirect()` → `wp_validate_redirect()`, which discards any URL whose
host is not in `allowed_redirect_hosts` and silently falls back to `admin_url()`.

The finalize URL was built as an absolute URL from the **restored** `siteurl`.
Whenever that host differed even cosmetically from the one the browser was on —
`127.0.0.1` vs `localhost`, `www` vs apex, a staging alias, or any real
migration to a new domain — validation rejected it and dropped the user on the
Dashboard. The permalinks page was never reached, so the rewrite-rules flush the
modal asked for never happened.

Verified directly:

| `redirect_to` | `wp_validate_redirect()` |
| --- | --- |
| absolute, different host | **rejected** → Dashboard |
| root-relative | accepted → Permalinks |

Both the login URL and the redirect target are now **root-relative**, so there
is no host for WordPress to disagree about and the flow works on whatever domain
the user is actually on.

## Verified end-to-end

Walked over real HTTP against a live WP 7.1 site, starting from a still-valid
logged-in session (the exact condition that triggered the bug):

1. Clicking *Finish & log out* → login prompt shown, session cookie cleared.
2. Logging back in → lands on `options-permalink.php` carrying the token.
3. Permalinks page shows *"Click Save Changes below — twice"*.
4. First save → *"Click Save Changes one more time"*.
5. Second save → *"Restore complete."*, counter at zero, one-time token consumed.

Plugin test suites: 52/52 pass.

---

## Upgrading

Drop-in. No database changes, no settings changes, no re-authorisation with
Google Drive.
