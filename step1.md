# Step 1 — Post-Implementation Analysis

Performed after all Step 1 files were created and linted.  
All 7 PHP files pass `php -l` under **PHP 8.4**.

---

## File-by-file Review

### `includes/.htaccess`

✅ Correct. `Deny from all` blocks all HTTP access to the `includes/` directory.  
No issues.

---

### `includes/Psr/Log/LogLevel.php`

✅ Namespace `Psr\Log`, class `LogLevel`, 8 string constants.  
Manually vendored MIT-licensed PSR file — appropriate since Composer is not used.  
No issues.

---

### `includes/Psr/Log/LoggerInterface.php`

✅ All 9 PSR-3 methods declared.  
PHP 8.0 union type `string|\Stringable` used correctly.  
`log()` uses `mixed $level` as required by PSR-3 (level can be any string or LogLevel constant).  
No issues.

---

### `includes/config.php`

✅ All constants defined with `define()` — no global variable side-effects (PSR-1 compliant).

⚠️ **Watch-out for Step 4**: `UPLOAD_LOG_ENABLED` and `UPLOAD_LOG_DIR` are still re-defined in
`upload_data.php` (lines 11–12). When `upload_data.php` is updated in Step 4 those two `define()`
calls **must** be removed — otherwise PHP will throw a fatal "constant already defined" error at
runtime.

Note: file is in `.gitignore` — credentials are never committed.

---

### `includes/Database/Connection.php`

✅ PSR-4 mapping correct: `TorqueLogs\Database\Connection` → `includes/Database/Connection.php`.  
✅ Singleton pattern with `private static ?\PDO $instance`.  
✅ `private function __construct()` prevents `new Connection()`.  
✅ `reset()` method supports testing / forced reconnection.  
✅ Safe error handling — credentials are never exposed in the 500 output.  
✅ `utf8mb4` charset in DSN (MariaDB best practice).

⚠️ **Note**: `config.php` is **not** `require_once`'d inside this file. The class relies on the
constants being defined by the entry point before `Connection::get()` is first called. The existing
`defined()` guard catches the missing-constants case and exits with a safe 500, so there is no
security risk — but every entry point must `require_once` `config.php` before any database call.
Document this as an entry-point contract in Step 4.

---

### `includes/Helpers/DataHelper.php`

✅ `final class`, `private __construct()`, all methods `static`.

| Method | Notes |
|--------|-------|
| `csvToJson()` | Uses `finally` to guarantee `fclose()` even on exception — improvement over the original `CSVtoJSON()`. |
| `average()` | Replaced the original `for` loop with `array_sum()` — cleaner and equivalent. |
| `calcPercentile()` | Returns `null` (typed) instead of the original `""` for invalid input — more type-safe. Boundary conditions (`$percentile === 0` or `=== 1`) correctly return `null`. |
| `makeSparkData()` | Semantically identical to `make_spark_data()`, correctly renamed to camelCase. |
| `substriCount()` | Identical logic to `substri_count()`. |

⚠️ **Minor**: `csvToJson()` returns `'{}'` silently when `fopen` fails (file missing/unreadable).
If callers need to distinguish "file missing" from "empty file" a logged warning would help.
Acceptable for now; revisit if observability becomes a requirement.

---

### `includes/Helpers/SqlHelper.php`

✅ `final class`, `private __construct()`, both methods `static`.  
✅ `isValidColumnName()` adds an explicit `strlen` guard before the regex — the original lacked this.  
✅ `quoteIdentifier()` backtick-escapes via `str_replace('`', '``', ...)` — correct SQL standard.

⚠️ **Theoretical note**: `MAX_IDENTIFIER_LENGTH = 64` is measured in bytes, but MariaDB measures
identifier length in characters for multi-byte charsets. Since all Torque column names are ASCII
(`kXXXX` keys) this is not a real-world risk. No action required.

---

### `includes/Logging/FileLogger.php`

✅ Implements `Psr\Log\LoggerInterface` — fully PSR-3 compliant.  
✅ `require_once` for both PSR files uses `__DIR__`-relative paths.  
✅ Constructor promotion with `readonly` — PHP 8.1+ style.  
✅ `ensureLogDir()` creates the directory + `.htaccess` deny rule automatically on first write.  
✅ `interpolate()` correctly skips non-stringable context values instead of throwing.

🐛 **Bug found and fixed**: `log()` originally used `array_merge($structuredFields, $context)`,
which allowed `$context` keys such as `ts`, `level`, `message`, or `ip` to silently overwrite the
structured log fields. Fixed by switching to the `+` operator, which gives the left-hand
(structured) fields priority:

```php
// Before (bug):
$entry = array_merge(['ts' => ..., 'level' => ..., ...], $context);

// After (fix):
$entry = ['ts' => ..., 'level' => ..., ...] + $context;
```

⚠️ **Design note**: `$_SERVER['REMOTE_ADDR']` is read directly inside `log()`. A strictly pure
PSR-3 logger would not know about HTTP — the IP could be injected via `$context['ip']` instead.
For this application's specific use-case (Torque upload logging) the current approach is pragmatic
and acceptable. No action required.

---

## Summary Table

| File | Status | Action taken |
|------|--------|--------------|
| `includes/.htaccess` | ✅ Clean | — |
| `Psr/Log/LogLevel.php` | ✅ Clean | — |
| `Psr/Log/LoggerInterface.php` | ✅ Clean | — |
| `includes/config.php` | ✅ Clean | Note for Step 4: remove duplicate `define()` calls from `upload_data.php` |
| `Database/Connection.php` | ✅ Clean | Note for Step 4: document entry-point `require_once` contract |
| `Helpers/DataHelper.php` | ✅ Clean | — |
| `Helpers/SqlHelper.php` | ✅ Clean | — |
| `Logging/FileLogger.php` | 🐛 → ✅ Fixed | `array_merge` order inverted to `+` operator |

**1 bug fixed. 7/7 files pass PHP 8.4 lint after fix.**
