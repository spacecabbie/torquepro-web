# Torque Logs — Restructure Plan

## Guiding rules
- PHP 8.4+, `declare(strict_types=1)` everywhere
- PSR-1, PSR-3, PSR-4, PSR-12 — all new code as classes
- No Composer — PSR-3 interface copied manually
- No credentials via `$_GET`, `hash_equals()` for passwords
- Auth functions never output HTML
- Origin comments on every merged section
- PHPDoc on all classes and public methods

---

## Final file tree

```
torque-logs/
├── .github/
│   └── copilot-instructions.md
│
├── includes/
│   ├── .htaccess                            ← Deny from all
│   ├── config.php                           ← App config vars (no class)
│   │
│   ├── Psr/
│   │   └── Log/
│   │       ├── LoggerInterface.php          ← PSR-3 interface (manual copy)
│   │       └── LogLevel.php                 ← PSR-3 log level constants
│   │
│   ├── Database/
│   │   └── Connection.php                   ← TorqueLogs\Database\Connection
│   │
│   ├── Auth/
│   │   └── Auth.php                         ← TorqueLogs\Auth\Auth
│   │
│   ├── Data/
│   │   ├── SessionRepository.php            ← TorqueLogs\Data\SessionRepository
│   │   ├── ColumnRepository.php             ← TorqueLogs\Data\ColumnRepository
│   │   ├── PlotRepository.php               ← TorqueLogs\Data\PlotRepository
│   │   └── GpsRepository.php                ← TorqueLogs\Data\GpsRepository
│   │
│   ├── Session/
│   │   └── SessionManager.php               ← TorqueLogs\Session\SessionManager
│   │
│   ├── Logging/
│   │   └── FileLogger.php                   ← TorqueLogs\Logging\FileLogger
│   │                                           implements Psr\Log\LoggerInterface
│   └── Helpers/
│       ├── DataHelper.php                   ← TorqueLogs\Helpers\DataHelper
│       └── SqlHelper.php                    ← TorqueLogs\Helpers\SqlHelper
│
├── login.php                                ← NEW: dedicated login page (BS5, CSRF)
├── dashboard.php                            ← UPDATED: uses new classes
├── live_log.php                             ← UPDATED: absorbs live_log_data.php
├── upload_data.php                          ← UPDATED: uses new classes
└── export.php                               ← UPDATED: adds auth check
```

---

## What replaces what

| Old file(s) | New file | Notes |
|---|---|---|
| `creds.php` | `includes/config.php` | Config vars only, no class needed |
| `db.php` | `includes/Database/Connection.php` | `TorqueLogs\Database\Connection` |
| `auth_functions.php` + `auth_user.php` + `auth_app.php` | `includes/Auth/Auth.php` | `TorqueLogs\Auth\Auth` |
| `get_sessions.php` | `includes/Data/SessionRepository.php` | `TorqueLogs\Data\SessionRepository` |
| `get_columns.php` | `includes/Data/ColumnRepository.php` | `TorqueLogs\Data\ColumnRepository` |
| `plot.php` | `includes/Data/PlotRepository.php` | `TorqueLogs\Data\PlotRepository` |
| GPS query in `dashboard.php` | `includes/Data/GpsRepository.php` | `TorqueLogs\Data\GpsRepository` |
| `del_session.php` + `merge_sessions.php` | `includes/Session/SessionManager.php` | `TorqueLogs\Session\SessionManager` |
| `log_torque_request()` in `upload_data.php` | `includes/Logging/FileLogger.php` | PSR-3 compliant |
| `parse_functions.php` | `includes/Helpers/DataHelper.php` | `TorqueLogs\Helpers\DataHelper` |
| `is_valid_column_name()` + `quote_identifier()` in `upload_data.php` | `includes/Helpers/SqlHelper.php` | `TorqueLogs\Helpers\SqlHelper` |
| Login form in `auth_user.php` | `login.php` | Clean page, CSRF, BS5 |
| `live_log_data.php` | inlined → `live_log.php` | Early-exit AJAX block |
| `timezone.php` | inlined → `dashboard.php` | Early-exit block |
| `url.php` | **DELETE** | No longer needed |
| `session.php` | **DELETE** (redirect first) | Superseded by `dashboard.php` |
| `backfill_sensor_names.php` | **DELETE** | Already run |

---

## Class responsibilities

### `TorqueLogs\Auth\Auth`
```
requireUser()     → session-based browser auth; on fail redirect to login.php
requireApp()      → Torque ID auth, no session; on fail plain text + exit
requireJson()     → session check for AJAX; on fail JSON 401 + exit
login(user, pass) → validates credentials with hash_equals(), sets session
logout()          → destroys session
```

### `TorqueLogs\Database\Connection`
```
get()             → returns shared PDO instance (static singleton)
```

### `TorqueLogs\Data\SessionRepository`
```
findAll()         → returns [sid => [date, size]] — replaces get_sessions.php side-effects
```

### `TorqueLogs\Data\ColumnRepository`
```
findPlottable()   → returns plottable column metadata — replaces get_columns.php
findEmpty(sid)    → returns which columns are empty for a session
```

### `TorqueLogs\Data\PlotRepository`
```
load(sid, v1, v2) → returns chart data, sparklines, stats — replaces plot.php
```

### `TorqueLogs\Data\GpsRepository`
```
findTrack(sid)    → returns [[lat,lon], ...] — extracted from dashboard.php
```

### `TorqueLogs\Session\SessionManager`
```
delete(sid)       → deletes session rows — replaces del_session.php
merge(sid, with)  → re-keys rows to sid — replaces merge_sessions.php
```

### `TorqueLogs\Logging\FileLogger` *(implements Psr\Log\LoggerInterface)*
```
log(level, msg, context) → writes JSON line to daily log file
+ shorthand: debug(), info(), warning(), error(), etc.
```

### `TorqueLogs\Helpers\DataHelper`
```
csvToJson(file)         → replaces CSVtoJSON()
average(arr)            → replaces average()
calcPercentile(arr, p)  → replaces calc_percentile()
makeSparkData(arr)      → replaces make_spark_data()
substriCount(h, n)      → replaces substri_count()
```

### `TorqueLogs\Helpers\SqlHelper`
```
isValidColumnName(name) → replaces is_valid_column_name()
quoteIdentifier(name)   → replaces quote_identifier()
```

---

## Bugs fixed during restructure

| # | Bug | Fixed in |
|---|---|---|
| 1 | Login form renders inside auth bootstrap file | `login.php` + `Auth::requireUser()` |
| 2 | Login form hardcoded to `session.php` | `login.php` uses `?redirect=` param |
| 3 | Password compared with `==` | `hash_equals()` in `Auth::login()` |
| 4 | Credentials accepted via `$_GET` | `$_POST` only in `Auth` |
| 5 | `auth_user.php` login page uses dead CDN / BS3 | `login.php` uses BS5 |
| 6 | `live_log_data.php` returned HTML on auth fail | `Auth::requireJson()` |
| 7 | `export.php` has no auth check | `Auth::requireUser()` added |
| 8 | GPS query is business logic in `dashboard.php` | `GpsRepository::findTrack()` |

---

## Execution order

```
Step 1 — PSR-3 interfaces (no dependencies)
  includes/Psr/Log/LogLevel.php
  includes/Psr/Log/LoggerInterface.php

Step 2 — Foundation (no dependencies)
  includes/config.php
  includes/Database/Connection.php
  includes/Helpers/DataHelper.php
  includes/Helpers/SqlHelper.php
  includes/Logging/FileLogger.php

Step 3 — Auth (depends on config)
  includes/Auth/Auth.php
  login.php

Step 4 — Data layer (depends on Connection + config)
  includes/Data/SessionRepository.php
  includes/Data/ColumnRepository.php
  includes/Data/GpsRepository.php
  includes/Data/PlotRepository.php
  includes/Session/SessionManager.php

Step 5 — Entry points (depend on all above)
  upload_data.php   (update)
  export.php        (update)
  live_log.php      (update + inline live_log_data.php)
  dashboard.php     (update + inline timezone.php)

Step 6 — Cleanup
  session.php       → redirect to dashboard.php
  Delete: creds.php, db.php, auth_functions.php, auth_user.php,
          auth_app.php, get_sessions.php, get_columns.php, plot.php,
          del_session.php, merge_sessions.php, parse_functions.php,
          live_log_data.php, timezone.php, url.php,
          session.php, backfill_sensor_names.php
  Create: includes/.htaccess
```

---

## Summary

**20 files → 11 files** (9 includes + 4 public entry points + `login.php` + `.htaccess`)
