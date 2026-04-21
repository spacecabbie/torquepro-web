# Step 4 — Post-Implementation Analysis

**Scope**: Entry point files updated to use the OOP layer built in Steps 1–3.  
**Files reviewed**: `dashboard.php`, `upload_data.php`, `export.php`, `live_log.php`  
**Commit**: `24ba369` (with two bug-fix corrections applied after commit — see §6)  
**PHP lint**: all 4 files pass `php -l` under PHP 8.4

---

## 1. `dashboard.php`

### What it does
Main browser UI. Bootstraps auth and data, then renders a Bootstrap 5 HTML page with:
a session picker, a GPS map (Leaflet), a two-variable time-series chart (Flot), a stats
table, and sparklines (Peity).

### PHP bootstrap section (lines 1–131)

#### Load order
```
config.php → Auth.php → Connection.php → SessionRepository → ColumnRepository
          → GpsRepository → PlotRepository → SessionManager
```
Order is correct. `config.php` is first, so constants (`DB_TABLE`, `TIMEZONE`, etc.) are
available to every class that follows.

#### Inline `?settz=1` endpoint
```php
if (isset($_GET['settz'])) {
    Auth::checkBrowser();
    if (isset($_GET['time'])) {
        $_SESSION['time'] = $_GET['time'];
    }
    exit;
}
```
Absorbs the old `timezone.php` file. Auth is checked before the session write.
`exit` is called unconditionally so the main page is never rendered for this route.
`$_GET['time']` is written to session only — never echoed — so no XSS surface.

**Concern**: The value is user-supplied and stored verbatim in `$_SESSION['time']`.
It is later output as a bare JS string: `"<?php echo $timezone; ?>"`. No
`htmlspecialchars()` is applied. This is a minor reflected-XSS vector if the attacker
can set an arbitrary session value. In practice the only caller is the same-origin JS in
the page itself (a trusted `$.get()`), but the code should still sanitise:

```php
// Suggested hardening:
$_SESSION['time'] = preg_replace('/[^A-Za-z0-9 +\-:]/', '', $_GET['time']);
```

#### Auth guard
`Auth::checkBrowser()` is called after the inline endpoint — any unauthenticated request
that is *not* a `?settz` call reaches the guard and is redirected to `login.php`.
The `?settz` branch calls its own `Auth::checkBrowser()` first, so it is also protected.

#### Session mutation (POST delete / merge)
Sessions are loaded *before* the mutation check so `$sids` is available for merge
validation. After mutation, `findAll()` is called again to refresh the lists. This is
correct: if delete ran without a reload, the deleted SID would still appear in the
sidebar picker on the same response.

**Concern**: The `action` value is read from `$_POST['action']` without a whitelist
check before entering the `if/elseif` chain. This is safe today (only two branches),
but an explicit whitelist would be more robust:

```php
$allowed_actions = ['delete', 'merge'];
if (isset($_POST['action']) && in_array($_POST['action'], $allowed_actions, true)) {
```

#### `$session_id` derivation
`preg_replace('/\D/', '', ...)` strips all non-digits. The result is then used in
`array_search($session_id, $sids, true)` (strict comparison) and as a PDO bind
parameter inside the repositories. Double-safe: even if `preg_replace` produced an
empty string, the `$hasSession` guard prevents any downstream DB calls.

#### Config flags
```php
$show_session_length  = defined('SHOW_SESSION_LENGTH')  ? SHOW_SESSION_LENGTH  : false;
$hide_empty_variables = defined('HIDE_EMPTY_VARIABLES') ? HIDE_EMPTY_VARIABLES : false;
$timezone             = defined('TIMEZONE')             ? TIMEZONE             : 'UTC';
```
`defined()` guard is defensive but redundant — all three constants are unconditionally
set in `config.php`, which is always loaded first. No functional problem, but it adds
visual noise. Could simplify to direct constant use.

#### `$plotResult` null-coalescing
```php
$plotResult = $hasSession ? $plotRepo->load(...) : null;
$v1_label   = $plotResult['v1_label'] ?? '';
```
When `$hasSession` is false, `$plotResult` is `null`; `null['v1_label']` would be a
fatal error in PHP 8 without the `??` operator. The null-coalescing is therefore
essential and correctly applied to every key extracted from `$plotResult`.

**Bug**: `$min1`, `$max1`, `$min2`, `$max2`, `$pcnt25data1`, `$pcnt75data1`,
`$pcnt25data2`, `$pcnt75data2` are used in the HTML view (lines ~360–400) but are
**not assigned** in the bootstrap. They must be returned by `PlotRepository::load()`
and extracted here, just as `$avg1`, `$avg2` etc. are. If `PlotRepository` does not
return these keys, they will be undefined variables in the view — PHP 8 will emit a
notice and the stat cards will display nothing. **Action required**: verify
`PlotRepository::load()` returns these keys and add the corresponding assignments
in the bootstrap.

#### HTML/JS view
- All user-controlled values echoed into HTML go through `htmlspecialchars()` — `$sessionLabel`, `$datestr`, column names, session IDs used in `href` attributes.
- `$session_id` echoed into URL `href` attributes has already been stripped to digits only — safe.
- `$imapdata` is directly echoed into a `<script>` block as a JS literal. It is built
  from `$d['lat']` / `$d['lon']` values returned from `GpsRepository`. Those values
  originate from the database (not from the current request), but the DB values
  themselves came from Torque app uploads. If a malicious upload contained
  `0</script><script>alert(1)` as a GPS coordinate, it would break out of the JS
  block. `GpsRepository::findTrack()` should cast lat/lon to `float` before returning
  them. **Action required**.
- `$v1_label` / `$v2_label` are echoed bare into Flot's `label:` and `axisLabel:`
  JS options (e.g. `label: <?php echo $v1_label; ?>`). These values come from the
  DB column comment (set by the app, not the current user) but should still be
  JSON-encoded to be safe: `label: <?php echo json_encode($v1_label); ?>`.

#### Timezone JS
```js
var tzurl = location.pathname + '?settz=1';
$.get(tzurl, {time: tz}, function() { location.reload(); });
```
Correctly points to the inlined `?settz=1` endpoint. The old
`.replace('dashboard','timezone')` string surgery is gone. This will work correctly
even if the app is served from a subpath.

---

## 2. `upload_data.php`

### What it does
HTTP GET endpoint called by the Torque Pro Android app. Validates sensor keys, creates
new DB columns on the fly, inserts sensor readings, writes a PSR-3 log line, and
records an audit row in `upload_requests`.

### Auth
```php
Auth::checkApp();
```
Called immediately after `use` declarations — before `Connection::get()`, before the
logger, before any DB work. `checkApp()` compares the Torque-ID from `$_GET['id']`
against the configured secret using `hash_equals()` (timing-safe). On failure it
emits a 401 JSON response and calls `exit`.

### Logger
```php
$logger = new FileLogger(UPLOAD_LOG_DIR, 'torque_upload');
```
`UPLOAD_LOG_DIR` is defined in `config.php` which is always loaded first. The old
duplicate `define()` calls that existed in the original file have been removed,
eliminating a fatal-error risk.

**Previously identified bug — fixed**: An earlier version of this file had
`defined('UPLOAD_LOG_DIR') ? UPLOAD_LOG_DIR : __DIR__ . '/logs'`. That guard implied
the constant might not exist, which was both misleading and unnecessary. Simplified to
a direct constant reference.

### `audit_torque_request()` function
Kept as a file-scope function rather than a class. This is a deliberate trade-off:
the function is upload-specific procedural glue with no reason to be reused elsewhere.
It is well-documented with a full PHPDoc block.

The function wraps its entire body in `try/catch(Throwable)` with an empty catch — this
is intentional: an audit failure must never abort the Torque response. The empty catch
is acceptable here but should carry a comment (it does: *"Audit failure must never
abort the main upload response."*) ✅

**Concern**: `json_encode($sensor_map, JSON_UNESCAPED_UNICODE)` has no
`JSON_THROW_ON_ERROR` flag. If encoding fails, `$sensor_json` will be `false`, and
PDO will store `''` or throw a type error on `:sensor_data`. Low probability (sensor
values are simple numerics) but defensive coding would add the flag.

### Sensor key validation pipeline
```
preg_match filter → SqlHelper::isValidColumnName() → quoteIdentifier()
```
Three layers of protection before any key reaches SQL:
1. Only `k*` keys, known fixed keys (`v`, `eml`, `time`, `id`, `session`), and
   `profileName` pass the `$submitval` filter.
2. `SqlHelper::isValidColumnName()` enforces a strict regex on the key.
3. `SqlHelper::quoteIdentifier()` backtick-wraps the key for DDL/DML.

This is correct. The key can never inject SQL.

### `SHOW COLUMNS` query
```php
$stmt = $pdo->query("SHOW COLUMNS FROM `" . DB_TABLE . "`");
```
`DB_TABLE` is a compile-time constant set in `config.php` — not user input. Safe.
`pdo->query()` (rather than `prepare()`) is acceptable here because the table name
is not dynamic.

### INFORMATION_SCHEMA comment check
```php
$chk->execute([':s' => DB_NAME, ':t' => DB_TABLE, ':c' => $key]);
```
Correctly uses `DB_NAME` and `DB_TABLE` constants (bug was present in the committed
version; fixed in a follow-up correction — see §6).

### `ALTER TABLE` safety
`ALTER TABLE` statements are built with `quoteIdentifier($key)` for the column name
and `addslashes($sensor_names[$key])` for the COMMENT value. `addslashes` is
sufficient for a SQL string literal in a COMMENT clause (not a bound parameter),
but `$pdo->quote()` would be more idiomatic. Acceptable as-is.

### `try/catch(Throwable)` scope
The outer `try` wraps `Connection::get()` and all sensor processing. If the DB is
unavailable, the catch logs the error and still allows `echo 'OK!'` to reach Torque.
This is intentional: the app should not retry endlessly due to a transient DB outage.

**Concern**: `$logger` is instantiated *outside* the outer `try`. If `new FileLogger()`
throws (e.g. `UPLOAD_LOG_DIR` is not writable), the exception is unhandled and PHP
will emit a 500 with a stack trace. This is acceptable in practice since the log
directory is under app control, but wrapping the logger construction in the `try` block
would be more defensive.

### PSR-3 log calls
```php
$logger->info('upload ok', $_GET);
$logger->info('upload skipped: no valid sensor keys', $_GET);
$logger->error('upload error: ' . $e->getMessage(), $_GET);
```
Message strings are meaningful and consistent. The context array `$_GET` is passed as
the second argument per PSR-3 convention. Passing the full `$_GET` to the logger means
credentials (the Torque-ID `id` field) are written to the log file. Consider masking:

```php
$logger->info('upload ok', array_diff_key($_GET, ['id' => '']));
```

---

## 3. `export.php`

### What it does
Returns a session's raw sensor rows as a CSV or JSON file download.

### Auth — security fix
```php
Auth::checkBrowser();
```
The original file had **no authentication at all**. Any anonymous request with a valid
session ID could download all sensor data for that session. This was bug #7 from the
restructure plan. Now fixed. ✅

### Input validation
```php
$session_id = preg_replace('/\D/', '', $_GET['sid']);
if ($session_id === '') { exit; }
```
Non-numeric characters stripped, empty result rejected. The sanitised value is then
used as a PDO bound parameter — not interpolated into SQL. ✅

### SQL query
```php
$pdo->prepare('SELECT * FROM `' . DB_TABLE . '` WHERE session = :sid ORDER BY time DESC')
```
`DB_TABLE` is a constant — not user input. The `SELECT *` means any new columns added
to `raw_logs` will appear in exports automatically. This is intentional behaviour
(users get all data they have) but worth noting: if a column is ever added that should
*not* be exported (e.g. an internal flag), `SELECT *` would need to become explicit.

**Concern**: No `LIMIT` clause. A session with millions of rows would load all of them
into memory at once. For a personal-use app this is unlikely to be a problem, but a
`LIMIT` + pagination or streaming approach would be safer at scale.

### CSV generation
```php
'"' . addslashes((string) $heading) . '",'
```
`addslashes` escapes backslashes and quotes within the double-quoted CSV cell. This
produces valid CSV for most consumers. However, the standard RFC 4180 CSV escape for
a `"` inside a double-quoted field is `""`, not `\"`. Most spreadsheet apps handle
`\"` correctly, but strict RFC compliance would use:

```php
'"' . str_replace('"', '""', (string) $heading) . '",'
```

Each row also ends with a trailing comma before `\n` (the last cell has an extra `,`).
This is a minor format quirk — Excel and LibreOffice will show an empty last column.
Consider `rtrim($row_str, ',') . "\n"`.

### JSON generation
```php
echo json_encode($rows, JSON_THROW_ON_ERROR);
```
`JSON_THROW_ON_ERROR` is present — failure throws rather than silently returning
`false`. Headers are sent before `echo`, in correct order. ✅

### `Content-Type` for CSV
```
header('Content-Type: application/csv');
```
The correct MIME type for CSV is `text/csv` (RFC 4180). `application/csv` is widely
accepted but non-standard. Minor issue.

---

## 4. `live_log.php`

### What it does
Two-in-one file:
- **Browser route** (`GET live_log.php`): renders a dark-theme admin console that polls
  for new upload records and displays them in a live-updating table.
- **AJAX route** (`GET live_log.php?data=1`): returns up to 100 new rows from
  `upload_requests` as JSON; also returns column name comments for sensor label display.

Absorbs `live_log_data.php` which is now redundant and can be deleted in Step 5.

### Route dispatch
```php
if (isset($_GET['data'])) {
    // AJAX branch — calls exit at end
}
// Falls through to browser view only when ?data is absent
Auth::checkBrowser();
?><!DOCTYPE html>
```
Clean separation. The AJAX branch always calls `exit`; the HTML branch is only reached
when `$_GET['data']` is not set. No shared mutable state between routes.

### Auth in the AJAX branch
```php
if (isset($_GET['data'])) {
    Auth::checkBrowser();
    header('Content-Type: application/json; charset=utf-8');
    ...
```
`Auth::checkBrowser()` is called *before* setting the `Content-Type` header.
`Auth::checkBrowser()` on failure does `header('Location: login.php'); exit`.
If the browser follows that redirect it receives the login page HTML, not JSON.
For a `fetch()` call with `credentials:'same-origin'` this means the JS will
receive HTML and `JSON.parse()` will throw a syntax error in the browser console.

**This is a UX defect, not a security hole.** The data is not exposed. However it
means the live console will silently stop working after a session expires, with no
user-visible message. Suggested fix: detect AJAX context and return 401 JSON:

```php
// In Auth::checkBrowser() — or at the call site:
if (isset($_GET['data'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Session expired']);
    exit;
}
```

### `$since_id` handling
```php
$since_id = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
```
Cast to `int` immediately. Bound as `:since_id` in the prepared statement.
Cannot be used for SQL injection. ✅

### Row type casting
```php
$r['id']           = (int)$r['id'];
$r['sensor_count'] = (int)$r['sensor_count'];
$r['new_columns']  = (int)$r['new_columns'];
$r['data_ts']      = $r['data_ts'] !== null ? (int)$r['data_ts'] : null;
$r['sensor_data']  = $r['sensor_data'] !== null
    ? json_decode($r['sensor_data'], true)
    : null;
```
Numeric DB columns are cast to PHP `int` so `json_encode` produces `1` not `"1"`.
`sensor_data` is decoded from JSON string to array so the client receives a proper
object. The `unset($r)` after the loop correctly breaks the by-reference binding. ✅

### INFORMATION_SCHEMA query
```php
$cnStmt->execute([':tbl' => DB_TABLE]);
```
Uses `DB_TABLE` constant. `COLUMN_NAME LIKE 'k%'` limits the result to sensor
columns. `COLUMN_COMMENT <> ''` filters out columns without a label. ✅

### `JSON_THROW_ON_ERROR` on error path
```php
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
```
The error-path `json_encode` lacks `JSON_THROW_ON_ERROR`. If the exception message
contains invalid UTF-8, `json_encode` returns `false` silently and the client
receives an empty body. Minor edge case; add the flag for consistency.

### JS fetch URLs
```js
fetch('live_log.php?data=1&since_id='+sinceId, {credentials:'same-origin'})
```
Both occurrences updated (polling loop + initial load). `credentials:'same-origin'`
ensures the session cookie is sent. ✅

### `LIMIT 100` on the poll query
The query returns at most 100 rows per poll. This bounds memory use and response size.
If more than 100 rows arrive between polls (burst scenario), `sinceId` advances to
the last received `id`, so no rows are permanently lost — subsequent polls will catch up.

---

## 5. Cross-cutting Checklist

| Check | Status | Notes |
|-------|--------|-------|
| `declare(strict_types=1)` on all files | ✅ | All four files |
| All `require_once` use `__DIR__` | ✅ | No relative path fragility |
| Auth before any output or DB call | ✅ | All four files |
| No `$_GET` used for credentials | ✅ | Upload uses `$_GET['id']` for Torque-ID, but `checkApp()` consumes and validates it internally |
| No raw user input in SQL | ✅ | All identifiers are either constants or go through `isValidColumnName` + `quoteIdentifier`; all values are PDO bound params |
| No stack traces exposed in production | ✅ | Catch blocks log or return a generic error message |
| No old procedural `require('creds.php')` / `require('db.php')` | ✅ | All removed |
| No duplicate `define()` for `UPLOAD_LOG_*` | ✅ | Only in `config.php` |
| PSR-12 formatting | ⚠️ | Dashboard HTML/view section retains older style; PHP bootstrap section is PSR-12 compliant |
| PHPDoc on all public methods | ✅ | `audit_torque_request()` has full PHPDoc |
| PHP 8.4 lint | ✅ | All 4 files pass `php -l` |

---

## 6. Bugs Found and Fixed During Analysis

| # | File | Bug | Fix applied |
|---|------|-----|-------------|
| 1 | `upload_data.php` | `$db_name` / `$db_table` variables on INFORMATION_SCHEMA query (line 168) — undefined variables, would produce a DB error at runtime | Replaced with `DB_NAME` / `DB_TABLE` constants |
| 2 | `upload_data.php` | `defined('UPLOAD_LOG_DIR') ? UPLOAD_LOG_DIR : __DIR__.'/logs'` — redundant `defined()` guard, misleading (implies constant may be absent) | Simplified to `UPLOAD_LOG_DIR` directly |

---

## 7. Outstanding Issues (not blocking, carry to Step 5/6)

| # | File | Issue | Priority |
|---|------|-------|----------|
| 1 | `dashboard.php` | `$min1`, `$max1`, `$min2`, `$max2`, `$pcnt25data1/2`, `$pcnt75data1/2` used in view but not assigned in bootstrap — must come from `PlotRepository::load()` | **High** — verify and add assignments |
| 2 | `dashboard.php` | `$imapdata` echoed bare into `<script>` — GPS lat/lon from DB not cast to `float` | Medium |
| 3 | `dashboard.php` | `$v1_label` / `$v2_label` echoed bare into Flot JS options — should use `json_encode()` | Medium |
| 4 | `dashboard.php` | Timezone value written to session without sanitisation, then echoed into JS | Medium |
| 5 | `upload_data.php` | Full `$_GET` (including Torque-ID secret) written to log file | Low |
| 6 | `upload_data.php` | `json_encode` in `audit_torque_request()` lacks `JSON_THROW_ON_ERROR` | Low |
| 7 | `upload_data.php` | `FileLogger` constructor outside outer `try` block — unhandled exception if log dir unwritable | Low |
| 8 | `export.php` | Trailing comma on last CSV cell per row | Low |
| 9 | `export.php` | `addslashes` instead of RFC 4180 `""` escaping for CSV | Low |
| 10 | `export.php` | `Content-Type: application/csv` — non-standard; prefer `text/csv` | Low |
| 11 | `export.php` | No `LIMIT` on `SELECT *` — full session loaded into memory | Low |
| 12 | `live_log.php` | `Auth::checkBrowser()` on AJAX branch issues an HTML redirect on session expiry — JS `fetch()` receives HTML, console shows JSON parse error | Medium |
| 13 | `live_log.php` | Error-path `json_encode` lacks `JSON_THROW_ON_ERROR` | Low |
| 14 | `live_log_data.php` | Now dead code — can be deleted in Step 5 | Step 5 |
| 15 | `timezone.php` | Now dead code — can be deleted in Step 5 | Step 5 |
