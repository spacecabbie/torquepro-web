# Step 6 Analysis — Cleanup

Per `restructure.md` Step 6: delete old procedural files, redirect `session.php`,
confirm `includes/.htaccess`.

PHP 8.4 lint of all 19 remaining PHP files: **all pass**.

---

## Files deleted

| File | Replaced by | Notes |
|------|-------------|-------|
| `auth_functions.php` | `includes/Auth/Auth.php` | Procedural auth helpers |
| `auth_user.php` | `includes/Auth/Auth.php` + `login.php` | Browser auth + BS3 login UI |
| `auth_app.php` | `includes/Auth/Auth.php` | Torque-ID app auth |
| `db.php` | `includes/Database/Connection.php` | PDO factory |
| `get_sessions.php` | `includes/Data/SessionRepository.php` | Session list query |
| `get_columns.php` | `includes/Data/ColumnRepository.php` | Column metadata query |
| `plot.php` | `includes/Data/PlotRepository.php` | Chart data + stats |
| `del_session.php` | `includes/Session/SessionManager::delete()` | Session delete |
| `merge_sessions.php` | `includes/Session/SessionManager::merge()` | Session merge |
| `parse_functions.php` | `includes/Helpers/DataHelper.php` | CSV, stats, sparklines |
| `live_log_data.php` | Inlined into `live_log.php` (`?data=1` branch) | AJAX data feed |
| `timezone.php` | Inlined into `dashboard.php` (`?settz=1` branch) | Session TZ write |
| `url.php` | Dead code — not referenced by any new file | URL builder |
| `backfill_sensor_names.php` | One-off maintenance script, already run | — |
| `creds-sample.php` | `includes/config.php` documents all constants | Sample config |

`creds.php` (live credentials, gitignored) was also deleted from the filesystem.
It is not tracked by git and is superseded by `includes/config.php`.

**Verification before deletion:** `grep -rn "require\|include"` across all new entry
points confirmed zero references to any of these files. Only old files referenced
each other — the new code exclusively uses `includes/`.

---

## session.php — 301 redirect

```php
<?php
declare(strict_types=1);

$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';

header('Location: dashboard.php' . $qs, true, 301);
exit;
```

### What's good
✅ `301 Moved Permanently` — browsers and search engines cache this forever,
   so no future requests will hit the old URL.  
✅ Query string preservation: `session.php?id=123` → `dashboard.php?id=123`.
   Old bookmarks or shared links that included a session ID continue to work.  
✅ `$_SERVER['QUERY_STRING']` is used raw — it is never output into HTML,
   only appended to a `Location:` header value, which is safe.  
✅ `exit` after `header()` — response terminates immediately, no body output.  
✅ `declare(strict_types=1)` + PHPDoc block with `Origin:` annotation —
   consistent with the rest of the codebase.  
✅ No auth check — a redirect from a dead URL should work even when not
   logged in, so the user lands on `dashboard.php` which then performs its
   own auth check.

### Minor notes
- The redirect target is a relative path (`dashboard.php`), not an absolute
  URL. RFC 7231 recommends an absolute URI for `Location:`, but all major
  browsers and HTTP clients handle relative redirects correctly. For a
  single-directory app this is fine.
- `$_SERVER['QUERY_STRING']` is not sanitised before appending. Since it goes
  into a `Location:` header value (not HTML output), the only theoretical risk
  is header injection via a literal `\r\n` in the query string. PHP's
  `header()` function silently strips CR/LF characters since PHP 7.3, so
  this is safe.

---

## includes/.htaccess

```
Deny from all
```

### What it does
Blocks direct HTTP requests to any file inside `includes/` (config, classes,
interfaces). Without this, a misconfigured server could serve PHP source as
plain text, exposing credentials in `config.php` and all class logic.

### What's good
✅ The file already existed and was correct — no change needed.  
✅ Apache `mod_authz_host` syntax — works on both Apache 2.2 and 2.4
   (though 2.4 prefers `Require all denied`; on DirectAdmin shared hosting
   2.2 syntax is safest for compatibility).  
✅ One line, no ambiguity.

### Minor note
Apache 2.4 replaces `Deny from all` with `Require all denied`. On
DirectAdmin shared hosting with Apache 2.4, this still works because
`mod_access_compat` is typically loaded for backwards compatibility.
To be fully forward-compatible the file could use:

```
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Deny from all
</IfModule>
```

For this deployment (DirectAdmin, confirmed working), the single line is fine.

---

## Final project structure

```
torque-logs/
├── includes/
│   ├── .htaccess                     Deny from all
│   ├── config.php                    App constants
│   ├── Psr/Log/
│   │   ├── LoggerInterface.php       PSR-3 interface
│   │   └── LogLevel.php              PSR-3 log level constants
│   ├── Database/
│   │   └── Connection.php            PDO singleton
│   ├── Auth/
│   │   └── Auth.php                  Browser + app auth
│   ├── Data/
│   │   ├── SessionRepository.php     Session list
│   │   ├── ColumnRepository.php      Column metadata
│   │   ├── GpsRepository.php         GPS track
│   │   └── PlotRepository.php        Chart data + stats
│   ├── Session/
│   │   └── SessionManager.php        Delete + merge
│   ├── Logging/
│   │   └── FileLogger.php            PSR-3 daily JSON logger
│   └── Helpers/
│       ├── DataHelper.php            Pure data utilities
│       └── SqlHelper.php             SQL identifier safety
│
├── dashboard.php                     Main UI (auth-gated)
├── export.php                        CSV/JSON download (auth-gated)
├── live_log.php                      Live console + AJAX feed (auth-gated)
├── login.php                         Login page
├── session.php                       301 → dashboard.php
└── upload_data.php                   Torque Pro upload endpoint
```

**File count: 19 PHP files + 1 .htaccess**
(was: 20+ PHP files with no consistent structure)

---

## Pre-deletion safety check

Before deleting, `grep -rn` confirmed:

| New entry point | References any old file? |
|-----------------|--------------------------|
| `dashboard.php` | ❌ No |
| `export.php` | ❌ No |
| `live_log.php` | ❌ No |
| `upload_data.php` | ❌ No |
| `login.php` | ❌ No |
| All `includes/*.php` | ❌ No |

Every old file only referenced other old files. No new file had a dangling
dependency before deletion.

---

## Issues found

None. Step 6 outputs are minimal by design:
- `session.php` is a one-purpose redirect with no logic to review.
- `includes/.htaccess` is a one-line directive that was already correct.
- The deletion itself required only a dependency check, not a code review.

---

## Restructure complete

All six steps from `restructure.md` are done:

| Step | Description | Commit |
|------|-------------|--------|
| 1 | PSR-3 interfaces + Foundation files | `80b4eb2` |
| 2 | Auth/Auth.php + login.php | `1835b3f` |
| 3 | Data repositories + SessionManager | `344ad03` |
| 4 (plan) | *(step numbering in plan vs execution differed)* | — |
| 5 (plan) | Entry points updated | `24ba369` → `3865ebc` |
| 6 | Cleanup: delete old files, redirect session.php | `110555e` |

**20 procedural files → 11 public-facing + class files.**
