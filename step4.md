# Step 4 Analysis — Entry Point Migration

Fresh-eyes review of all four entry points after OOP migration.
All PHP 8.4 lint passes.

---

## dashboard.php

### Role
Main UI entry point. Bootstraps auth, wires repository classes, renders HTML.

### Structure
```
1–30   File header, requires, use statements
31–38  Inline endpoint: ?settz=1 (timezone preference)
40–41  Auth guard
43–160 Data loading (sessions, delete, merge, columns, GPS, plot)
161+   HTML view
```

### What's good
- Single `Auth::checkBrowser()` guard before any data access. ✅
- Inline `?settz=1` endpoint at the top — still guards with `Auth::checkBrowser()` before touching `$_SESSION`. ✅
- Repository pattern cleanly separates data access from view. ✅
- `Connection::get()` called once, `$pdo` passed to all repositories. ✅
- Delete and merge flows reload `$sessionData` after mutation so the view is always consistent. ✅
- `ColumnRepository::findEmpty()` only called when `$hasSession` — avoids unnecessary N+1 queries on page load. ✅
- `$plotData !== null` used as the gate for stat cards and data summary (correct — was `isset($avg1)` before, which was always true because the flatten block assigns defaults). ✅
- `HIDE_EMPTY_VARIABLES` and `SHOW_SESSION_LENGTH` constants used correctly throughout view. ✅
- GPS default view uses `GpsRepository::DEFAULT_MAP_DATA` constant, not a hardcoded coordinate. ✅
- `htmlspecialchars()` applied on all user-derived values rendered into HTML. ✅
- `preg_replace('/\D/', '', ...)` strips non-digits from all session ID inputs before use. ✅
- `array_search(..., true)` (strict) for the next-session adjacency check. ✅
- `max($sids)` guarded by `!empty($sids)`. ✅

### Issues found & fixed
| # | Severity | Description | Fix applied |
|---|----------|-------------|-------------|
| 1 | Bug | `$hide_empty_variables` referenced variable that no longer exists; should be constant `HIDE_EMPTY_VARIABLES` | Fixed |
| 2 | Bug | `isset($avg1)` always `true` because flatten block assigns defaults to all plot vars; condition never reflected "no plot data" | Fixed — replaced with `$plotData !== null` |

### Remaining minor concerns (pre-existing / low risk)
- **JS `confirm()` uses `addslashes()`**: Should use `json_encode()` to safely encode session labels into JS string literals (`\n`, `</script>` not escaped). Low risk on an auth-gated page.
- **Sparkline script always loads when `$hasSession`**: `isset($sparkdata1, $sparkdata2)` is always true (defaults to `''`). Harmless — peity on empty string renders nothing.
- **Chart header `isset($v1_label, $v2_label)`**: These are always set to defaults, so condition is always true when `$hasSession`. Cosmetic.

---

## export.php

### Role
CSV / JSON download endpoint. Auth-gated; exports all sensor rows for one session.

### Structure
```
1–20  Header, requires, use, auth check
22–30 Input validation (sid, filetype)
32–38 PDO query
40–63 CSV branch
64–70 JSON branch
```

### What's good
- `Auth::checkBrowser()` is the first executable line after bootstrap. ✅
- `preg_replace('/\D/', '', $_GET['sid'])` sanitises session ID before query. ✅
- Prepared statement with named placeholder `:sid`. ✅
- `DB_TABLE` constant — no user input in table name. ✅
- `JSON_THROW_ON_ERROR` on `json_encode`. ✅
- Falls through to `exit` for unknown `$filetype` — no unintended output. ✅

### Issues found & fixed
| # | Severity | Description | Fix applied |
|---|----------|-------------|-------------|
| 1 | **Bug** | `$stmt->fetchAll()` uses PDO default `FETCH_BOTH` — every row has both numeric and string keys. `array_keys($rows[0])` for CSV headers includes `0, 1, 2, …` numeric indices alongside column names, doubling every column. JSON export also contained duplicate keys per row. | Fixed — `fetchAll(PDO::FETCH_ASSOC)` |
| 2 | **Bug** | `addslashes()` used for CSV cell/header quoting. RFC 4180 requires doubling quotes (`""`), not backslash-escaping. `addslashes('"value"')` produces `\"value\"` which many CSV parsers reject. | Fixed — `str_replace('"', '""', ...)` |

---

## upload_data.php

### Role
Torque Pro upload endpoint. Receives sensor data via GET, validates keys, inserts into `raw_logs`, logs to file and audit table.

### Structure
```
1–27   Header, requires, use statements
29–31  Auth guard (Torque-ID)
33–37  Logger instantiation
39–101 audit_torque_request() standalone function
103+   Main logic wrapped in try/catch(Throwable)
```

### What's good
- `Auth::checkApp()` before any PDO or file I/O. ✅
- `SqlHelper::isValidColumnName()` + `quoteIdentifier()` for every dynamic column. ✅
- PDO prepared statements for all INSERT and SELECT operations. ✅
- `ALTER TABLE ADD` uses backtick-quoted, whitelisted column names only. ✅
- `INFORMATION_SCHEMA` check before updating COMMENT — avoids unnecessary DDL. ✅
- `audit_torque_request()` inner `try/catch(Throwable)` — audit failure never aborts the upload response. ✅
- Outer `try/catch(Throwable)` ensures `echo 'OK!'` always fires (prevents Torque retries). ✅
- `count($keys) > 0 && count($keys) === count($params)` guards against empty insert. ✅

### Issues (no fix applied — pre-existing / low risk)
- **`audit_torque_request()` is a standalone function**, not a class method. Acceptable for now; candidate for `AuditRepository` in a future step.
- **`addslashes()` for SQL COMMENT string**: COMMENT value is interpolated into DDL after `addslashes()`. `$pdo->quote()` would be safer. Risk is low — source is Torque app's `userShortName` field.

---

## live_log.php

### Role
Real-time upload console. Two routes in one file:
- `GET ?data=1` — AJAX JSON feed (polling)
- `GET /` — Full HTML console page

### Structure
```
1–17   Header, requires, use statements
19–86  AJAX data feed (origin: live_log_data.php)
87+    Browser HTML view + JavaScript polling client
```

### What's good
- Clean dual-route separation with early exit on AJAX path. ✅
- `PDO::FETCH_ASSOC` on all queries. ✅
- Type coercion on all int fields (`id`, `sensor_count`, `new_columns`, `data_ts`). ✅
- `LIMIT 100` prevents unbounded result sets. ✅
- `json_decode($r['sensor_data'], true)` — null-safe passthrough if malformed. ✅
- `JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR` on encode. ✅
- `(int)$_GET['since_id']` — safe integer cast. ✅
- JS `esc()` function escapes all server data before innerHTML insertion. ✅
- `MAX_ROWS = 500` cap prevents memory growth. ✅
- `KNOWN_SENSORS` fallback table for well-known PIDs when DB comment is empty. ✅

### Issues found & fixed
| # | Severity | Description | Fix applied |
|---|----------|-------------|-------------|
| 1 | **Bug** | AJAX branch called `Auth::checkBrowser()` which on failure redirects to `login.php` (HTML 302). An AJAX `fetch()` client receives an HTML page, not a JSON error. Polling silently breaks. | Fixed — replaced with `Auth::startSession()` + `Auth::isLoggedIn()` check returning HTTP 401 + JSON `{"error":"Unauthorized"}` |

### Remaining minor concerns
- **`colNames` merge**: `Object.assign(colNames, d.col_names)` on every poll — names only grow. If a column comment is updated in DB it won't refresh until page reload. Acceptable.

---

## Cross-cutting observations

### Consistent across all four files ✅
- `declare(strict_types=1)` on line 2
- `require_once __DIR__ . '/includes/...'` with absolute paths
- `use` statements for every referenced class
- PHPDoc file-level docblocks with `Origin:` traceability annotation
- `Connection::get()` singleton — one PDO instance per request

### Inconsistencies / watch items
- `upload_data.php` still has one procedural function (`audit_torque_request()`). All other entry points are free of procedural code.
- Require lists are appropriately minimal per file — `export.php` and `upload_data.php` do not pull in unneeded repositories.

---

## Bugs fixed in this step

| File | Bug | Fix |
|------|-----|-----|
| `dashboard.php` | `$hide_empty_variables` undefined | → `HIDE_EMPTY_VARIABLES` constant |
| `dashboard.php` | `isset($avg1)` always true | → `$plotData !== null` |
| `export.php` | `PDO::FETCH_BOTH` duplicates column keys | → `PDO::FETCH_ASSOC` |
| `export.php` | `addslashes()` invalid for CSV quoting | → `str_replace('"', '""', ...)` |
| `live_log.php` | AJAX auth redirects to HTML login page | → JSON 401 response |
