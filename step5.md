# Step 5 Analysis — Entry Points

Per `restructure.md` Step 5: update all four public entry points to use
the new class hierarchy built in Steps 1–4.

Files reviewed: `dashboard.php`, `export.php`, `upload_data.php`, `live_log.php`
PHP 8.4 lint: all pass.

---

## 1. dashboard.php

### Purpose
Main UI. Boots auth, calls repositories, renders the full HTML view.
Absorbs `timezone.php` (inline `?settz=1` endpoint).

### Bootstrap block (lines 1–29)
```php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Auth/Auth.php';
require_once __DIR__ . '/includes/Database/Connection.php';
require_once __DIR__ . '/includes/Data/SessionRepository.php';
require_once __DIR__ . '/includes/Data/ColumnRepository.php';
require_once __DIR__ . '/includes/Data/GpsRepository.php';
require_once __DIR__ . '/includes/Data/PlotRepository.php';
require_once __DIR__ . '/includes/Session/SessionManager.php';
```
✅ All paths use `__DIR__` — immune to `include_path` manipulation.  
✅ Every `require_once` is for a file that is actually used.  
✅ All seven `use` statements match their `require_once` counterparts exactly.

### Inline timezone endpoint (lines 31–38)
```php
if (isset($_GET['settz'])) {
    Auth::checkBrowser();
    if (isset($_GET['time'])) {
        $_SESSION['time'] = $_GET['time'];
    }
    exit;
}
```
✅ Auth guard fires *before* writing to `$_SESSION['time']`.  
✅ No sanitisation needed — timezone string is only ever echoed back into a
   JS string literal, never into SQL or HTML.  
⚠️ **Minor**: the timezone string IS echoed verbatim into a JS string literal
   later (`"<?php echo $timezone; ?>"`). If a user crafts a session cookie with
   `</script>` in it they could inject script. Should be
   `json_encode($timezone)`. Risk is low (attacker must already be authenticated
   and control their own session), but it is technically a stored XSS vector.

### Auth guard (line 40)
```php
Auth::checkBrowser();
```
✅ Fires before any data access or output.

### Data loading (lines 42–161)

#### Connection (line 42)
```php
$pdo = Connection::get();
```
✅ Singleton — one PDO for the whole request. All five repositories receive
the same instance; no redundant reconnections.

#### Session list (lines 44–55)
```php
$sessionRepo = new SessionRepository($pdo);
$sessionData = $sessionRepo->findAll();
$sids        = $sessionData['sids'];
$seshdates   = $sessionData['dates'];
$seshsizes   = $sessionData['sizes'];
$_SESSION['recent_session_id'] = !empty($sids) ? strval(max($sids)) : '';
```
✅ `max($sids)` correctly guarded by `!empty($sids)`.  
⚠️ **Minor**: `$_SESSION['recent_session_id']` is written on every page load
   but never read in this file. If nothing else reads it, it is dead code.
   Worth confirming it is consumed somewhere (e.g. live_log.php) before removing.

#### Session ID resolution (lines 57–62)
```php
if (isset($_POST['id'])) {
    $session_id = preg_replace('/\D/', '', $_POST['id']) ?? '';
} elseif (isset($_GET['id'])) {
    $session_id = preg_replace('/\D/', '', $_GET['id']) ?? '';
}
```
✅ `preg_replace('/\D/', '')` strips all non-digits — safe for use in SQL
   (though parameterised queries are still used downstream, so this is
   defence-in-depth).  
✅ `?? ''` fallback makes `$session_id` always a string, never null.

#### Delete action (lines 64–79)
```php
if (isset($_GET['deletesession'])) {
    $deleteId = preg_replace('/\D/', '', $_GET['deletesession']) ?? '';
}
if ($deleteId !== '') {
    $manager->delete($deleteId);
    // reload $sessionData …
    $session_id = '';
    $hasSession = false;
}
```
✅ Non-digit-stripped ID before use.  
✅ `$sessionData` reloaded after delete so the view reflects the new state.  
✅ `$session_id` / `$hasSession` reset — no dangling reference to a deleted
   session.  
⚠️ **Minor CSRF**: delete and merge are triggered by `$_GET` parameters.
   A link or `<img src="dashboard.php?deletesession=X">` on any page the
   logged-in user visits would silently delete their session data. These
   actions should require a POST or include a CSRF token. Currently `$_POST`
   is used for the form `method="post"`, but the `?deletesession=` parameter
   is read from `$_GET`, not `$_POST`, so the POST body provides no protection.

#### Merge action (lines 81–99)
Same CSRF concern as delete. Logic is correct:  
✅ Both IDs non-digit-stripped before passing to `SessionManager::merge()`.  
✅ `$mergedId !== null` checked before reloading and updating `$session_id`.

#### Column metadata (lines 101–104)
```php
$coldataempty = $hasSession ? $colRepo->findEmpty($session_id, $coldata) : [];
```
✅ `findEmpty()` (N+1 queries) only called when a session is actually selected.
   On the landing page (no session) this is correctly skipped.

#### GPS track (lines 106–113)
```php
$gpsData = $hasSession
    ? $gpsRepo->findTrack($session_id)
    : ['points' => [], 'mapdata' => GpsRepository::DEFAULT_MAP_DATA];
```
✅ Uses `GpsRepository::DEFAULT_MAP_DATA` constant (Area 51 fallback coord) —
   no magic string in the view.  
✅ Struct unpacked into `$geolocs` / `$imapdata` with clear names.

#### Plot data (lines 115–130)
```php
$plotData = $hasSession
    ? $plotRepo->load(
        $session_id, $sids, $coldata,
        $_GET['s1'] ?? null, $_GET['s2'] ?? null,
        __DIR__ . '/data/torque_keys.csv'
    )
    : null;
```
✅ `$_GET['s1']`/`$_GET['s2']` passed as nullable strings — `PlotRepository`
   is responsible for sanitising/whitelisting them.  
✅ CSV path uses `__DIR__` — absolute, not relative to cwd.

#### Plot flatten block (lines 132–151)
All 16 plot variables extracted with `?? default` fallbacks so the view
always has typed, defined variables regardless of whether plot data loaded.  
✅ This is the correct pattern — avoids `isset()` guards scattered through
   the view.  
✅ `$plotData !== null` is used as the gate condition in the view (not
   `isset($avg1)` or similar), so the two states (no session / session but
   no data) are handled correctly.

#### Next-session adjacency (lines 153–157)
```php
$idx = array_search($session_id, $sids, true);
$session_id_next = ($idx !== false && $idx > 0) ? $sids[$idx - 1] : false;
```
✅ Strict `true` comparison in `array_search`.  
✅ Correctly returns `false` (not null/0) so `if (!$session_id_next)` in the
   view correctly disables the Merge button when there is no adjacent session.

### View (lines 163–636)

#### Session picker
```php
<?php if ($dateid == ($session_id ?? '')) echo 'selected'; ?>
```
⚠️ Uses loose `==` for comparing `$dateid` (string) against `$session_id`
   (string). Should be `===`. With loose comparison `'0' == false` and similar
   edge cases could cause wrong `selected` state. Low risk in practice as both
   sides are digit-only strings, but `===` is the correct operator here.

#### JS confirm dialogs
```php
confirm('Delete session "<?php echo addslashes($seshdates[$session_id]); ?>"?')
```
⚠️ `addslashes()` is used to escape session date strings into JS string
   literals. This does not escape `\n`, `\r`, `</script>`, or Unicode
   escapes. `json_encode($seshdates[$session_id])` (which produces a
   quoted, fully-escaped JS string) would be correct here.

#### CDN assets — no SRI hashes
Bootstrap, jQuery, Leaflet, and Chosen are loaded from cdnjs without
Subresource Integrity (`integrity=` / `crossorigin=`) attributes.
`login.php` (Step 3) does use SRI. For consistency and defence-in-depth,
dashboard CDN tags should also carry SRI hashes.

#### Script loading — conditional Flot block
```php
<?php if ($hasSession && !empty($d1) && !empty($d2)): ?>
<script src="static/js/jquery.flot.js"></script>
…
<?php endif; ?>
```
✅ Flot and its plugins (7 files) are only loaded when chart data exists.
   Good for page weight on the landing/no-data state.

#### Sparkline guard
```php
<?php if ($hasSession && isset($sparkdata1, $sparkdata2)): ?>
```
`$sparkdata1` and `$sparkdata2` are always defined (flatten block assigns
`''` as default), so `isset()` is always true when `$hasSession`. The
condition is harmless but the `isset()` check is redundant — could be
`$hasSession && $plotData !== null` for consistency.

#### Chart label in header
```php
<?php if ($hasSession && isset($v1_label, $v2_label)): ?>
```
Same issue — `$v1_label`/`$v2_label` always set to defaults. Redundant
`isset()`, cosmetic issue only.

#### Stat card values — unescaped output
```php
<div class="stat-value"><?php echo $avg1; ?></div>
```
`$avg1`, `$min1`, `$max1`, etc. come from `PlotRepository` which computes
them as floats/ints from DB numeric columns, then `round()`s them.
They are numeric — `htmlspecialchars()` is technically unnecessary but
omitting it means if `PlotRepository` ever returns a string with characters
like `<` the view would break. Low risk; noted for completeness.

#### Stat card label
```php
<div class="stat-label"><?php echo strip_tags(substr($v1_label, 1, -1)); ?></div>
```
`$v1_label` is a JS-quoted string like `"Speed (OBD)"`. `substr(..., 1, -1)`
strips the surrounding `"` characters. `strip_tags()` removes any HTML tags.
✅ Safe — HTML special chars in the resulting string could still produce
   `&amp;` etc., but not script injection.  
In the data summary table, `htmlspecialchars(substr($v1_label, 1, -1))` is
used instead — more correct. The stat card should match.

---

## 2. export.php

### Purpose
Auth-gated CSV/JSON download for one session.

### Structure
```
1–19  Bootstrap + auth
20    Auth::checkBrowser()
22–29 Input validation
31–37 PDO query
39–62 CSV output
63–69 JSON output
70    Fallback exit
```

### Security
✅ `Auth::checkBrowser()` before any parameter inspection.  
✅ `preg_replace('/\D/', '', $_GET['sid'])` — session ID sanitised to digits only.  
✅ Prepared statement with `:sid` — no string interpolation of user input.  
✅ `DB_TABLE` is a hardcoded constant — not derived from user input.  
✅ `PDO::FETCH_ASSOC` — no duplicate numeric keys in output.  
✅ RFC 4180 CSV quoting: `str_replace('"', '""', ...)` — correct.  
✅ `JSON_THROW_ON_ERROR` — encode errors surface as exceptions rather than
   silent `false`.  
✅ Unreachable `else { exit; }` — no accidental output for unknown filetypes.

### Minor issues

**`$session_id` could be empty string after strip but `exit` is separate:**
```php
$session_id = preg_replace('/\D/', '', $_GET['sid']);
if ($session_id === '') { exit; }
```
✅ Correctly handled — empty string exits cleanly.

**`preg_replace` return type:**
`preg_replace()` returns `string|null`. In PHP 8.x with a non-null subject
it always returns string, but strictly the `=== ''` check should also
handle `null` (it does — `null === ''` is false, so it wouldn't exit on
null). Assigning `(string)preg_replace(...)` would be cleaner.

**CSV: trailing comma on every row:**
```php
$output .= '"' . str_replace('"', '""', (string) $cell) . '",';
```
Every row ends with a trailing comma (`col1,col2,col3,`). Strictly this
produces an extra empty column in some parsers. Standard practice is to
`implode(',', $cells)` instead of appending `,` per cell. Not a breakage
for most tools (Excel, LibreOffice silently ignore trailing commas) but
worth fixing for correctness.

**CSV: no BOM, no line ending standardisation:**
No UTF-8 BOM is emitted. Some Windows CSV consumers (especially Excel
when double-clicked) open UTF-8 CSV without BOM as Windows-1252, corrupting
non-ASCII sensor names. Emitting `\xEF\xBB\xBF` before the header row
would fix this. Low priority for a local tool.

**JSON: no `JSON_UNESCAPED_UNICODE`:**
`json_encode($rows, JSON_THROW_ON_ERROR)` — non-ASCII characters in sensor
values will be `\uXXXX`-escaped. `JSON_UNESCAPED_UNICODE` would produce
more readable output. Cosmetic.

---

## 3. upload_data.php

### Purpose
Torque Pro upload endpoint. Receives sensor data via HTTP GET, validates
column names, inserts into `raw_logs`, writes to PSR-3 log, audits to
`upload_requests`.

### Structure
```
1–27    Bootstrap + use statements
29–31   Auth guard (Torque-ID)
33–37   FileLogger instantiation
39–101  audit_torque_request() standalone function
103+    Main logic: try/catch(Throwable) wrapper
```

### Security
✅ `Auth::checkApp()` is the first executable statement — no PDO or file I/O
   before auth passes.  
✅ `SqlHelper::isValidColumnName()` whitelists every column name before it
   touches SQL.  
✅ `SqlHelper::quoteIdentifier()` backtick-quotes every column before DDL.  
✅ All INSERT/SELECT use PDO prepared statements with named placeholders.  
✅ `DB_TABLE` constant — not user-controlled.  
✅ `audit_torque_request()` catches its own `Throwable` — audit failure
   cannot abort the upload.  
✅ Outer `catch(Throwable)` ensures `echo 'OK!'` fires regardless, preventing
   Torque's retry loop.

### Architecture note: standalone function
`audit_torque_request()` is a PSR-1 violation (a bare function in a file that
also has side-effects). This is intentional carry-over from the migration —
it is documented in both code comments and `restructure.md` as a candidate for
a future `AuditRepository` class. No action required this step.

### Minor issues

**`addslashes()` for SQL COMMENT strings (DDL interpolation):**
```php
$comment = " COMMENT '" . addslashes($sensor_names[$key]) . "'";
$pdo->exec("ALTER TABLE … ADD {$quotedKey} VARCHAR(255)…{$comment}");
```
`addslashes()` escapes `\` and `'`. MariaDB DDL strings also need to handle
`\0`, `\n`, `\r`, `\Z`. `$pdo->quote($sensor_names[$key])` would be fully
safe (it includes the surrounding quotes, so the string becomes
`COMMENT ` . $pdo->quote(...)`). The risk is low — sensor names come from
the Torque app's `userShortName` field — but it is technically unsafe for
arbitrary input.

**Sensor name update MODIFY column:**
```php
$pdo->exec("ALTER TABLE … MODIFY {$quotedKey} VARCHAR(255)… COMMENT '{$comment}'");
```
Same `addslashes()` concern. Also note that `MODIFY` re-states the full
column definition. If the column was ever changed to a different type or
default outside this code path, the MODIFY would silently revert it to
`VARCHAR(255) NOT NULL DEFAULT '0'`. This is a known risk of DDL-in-application
code and is acceptable given the controlled schema.

**`count($keys) === count($params)` guard:**
```php
if (count($keys) > 0 && count($keys) === count($params)) {
```
This check is technically redundant — `$keys` and `$params` are always
built in lockstep (one push to each per valid key). The condition can never
be false unless the code above it changes. Harmless, but could be simplified
to `count($keys) > 0`.

---

## 4. live_log.php

### Purpose
Dual-route file:
- `GET ?data=1` → AJAX JSON polling feed (origin: `live_log_data.php`)
- `GET /` → full HTML real-time console

### AJAX branch (lines 25–93)

#### Auth
```php
Auth::startSession();
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
```
✅ Returns JSON 401 — correct for an AJAX endpoint.  
✅ Does NOT redirect to login.php — a JS `fetch()` caller handles the 401.

#### Query
```php
$stmt = $pdo->prepare(
    'SELECT id, ts, ip, torque_id, eml, app_version,
            session, data_ts, sensor_count, sensor_data,
            new_columns, profile_name, result, error_msg
     FROM upload_requests
     WHERE id > :since_id
     ORDER BY id ASC
     LIMIT 100'
);
```
✅ Explicit column list — no `SELECT *`, so schema additions don't
   accidentally expose new fields.  
✅ `LIMIT 100` prevents runaway result sets.  
✅ `since_id` is `(int)$_GET['since_id']` — safe integer cast, no injection
   risk.

#### Type coercion
```php
$r['id']           = (int)$r['id'];
$r['sensor_count'] = (int)$r['sensor_count'];
$r['new_columns']  = (int)$r['new_columns'];
$r['data_ts']      = $r['data_ts'] !== null ? (int)$r['data_ts'] : null;
$r['sensor_data']  = $r['sensor_data'] !== null
    ? json_decode($r['sensor_data'], true) : null;
```
✅ All numeric DB strings cast to int before JSON encoding — JS receives
   native number types, not string-numbers.  
✅ `json_decode(..., true)` — returns assoc array or null; never throws.
   Malformed JSON in the column is silently null (acceptable — the JS
   handles null gracefully).

#### Column name lookup
```php
$cnStmt->execute([':tbl' => DB_TABLE]);
```
✅ DB_TABLE constant passed as a parameter value — note this is a WHERE
   value, not an identifier, so parameterisation is correct here.

#### Error handling
```php
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
```
⚠️ `$e->getMessage()` may expose internal DB structure or file paths in
   production. Should be a generic message in production; full message only
   in dev/debug mode. Mitigation: this is an auth-gated endpoint, so only
   logged-in users see it.

### Browser branch (lines 94–465)

#### Auth
```php
Auth::checkBrowser();
```
✅ Fires before any HTML output.

#### HTML/CSS/JS quality
The entire console UI is self-contained inline CSS + JS — no external
dependencies beyond the browser's native `fetch()`. This is intentional
(the live console must work even if CDN is down).

**`esc()` helper:**
```js
function esc(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
```
✅ Applied to every server value before `innerHTML` insertion.  
✅ Covers the four critical HTML special chars.  
⚠️ Single-quote `'` is not escaped (`&#39;`). `esc()` values are only ever
   placed inside double-quoted HTML attributes or as text content, so this
   is safe in current usage, but omitting `'` is a gap if usage ever changes.

**`MAX_ROWS = 500` cap:**
```js
while(tbody.rows.length > MAX_ROWS) tbody.deleteRow(0);
```
✅ Prevents unbounded DOM growth during long monitoring sessions.

**`KNOWN_SENSORS` fallback table:**
Hardcoded JS object with ~35 well-known Torque PID keys mapped to human
names. Used when the DB column comment is empty.  
✅ Correct approach — covers first-time uploads before sensor names are
   stored.

**Sensor modal:**
Full-featured: filter by key/name/value, zero/nonzero indicator, summary
line. All built with DOM methods + `esc()`. Clean and safe.

**Polling architecture:**
```js
fetch('live_log.php?data=1&since_id=' + sinceId, {credentials: 'same-origin'})
```
✅ `credentials: 'same-origin'` — session cookie is sent.  
✅ `since_id` is always an integer (starts at 0, then set from `r.id` which
   is coerced to int server-side).  
✅ Pause/resume correctly stops/starts `setInterval`.  
⚠️ On `fetch()` error, the poll silently continues. If the server returns 401
   (session expired), the JS logs to console and shows a `⚠` next to the
   timestamp, but does not redirect the user to the login page. For a
   monitoring console this is acceptable (user will notice the warning dot),
   but a `location.reload()` on 401 would give a better UX.

---

## Cross-cutting findings across all four files

| Check | dashboard | export | upload | live_log |
|-------|-----------|--------|--------|----------|
| `declare(strict_types=1)` | ✅ | ✅ | ✅ | ✅ |
| `__DIR__` paths | ✅ | ✅ | ✅ | ✅ |
| Auth guard first | ✅ | ✅ | ✅ | ✅ |
| Prepared statements | ✅ | ✅ | ✅ | ✅ |
| `PDO::FETCH_ASSOC` | n/a | ✅ | ✅ | ✅ |
| No user input in table name | ✅ | ✅ | ✅ | ✅ |
| `htmlspecialchars()` on HTML output | ✅ | n/a | n/a | (JS esc()) |
| PHPDoc file block | ✅ | ✅ | ✅ | ✅ |
| Origin comment | ✅ | ✅ | ✅ | ✅ |
| No CDN SRI hashes | ⚠️ | n/a | n/a | ✅ (no CDN) |

---

## Bugs fixed during this step's review

| File | Bug | Severity | Fix |
|------|-----|----------|-----|
| `dashboard.php` | `$hide_empty_variables` undefined var | 🔴 Fatal at runtime | → `HIDE_EMPTY_VARIABLES` constant |
| `dashboard.php` | `isset($avg1)` always true (defaults set) — "no plot data" state never shown | 🟡 Logic error | → `$plotData !== null` |
| `export.php` | `fetchAll()` default `FETCH_BOTH` — doubled keys in CSV/JSON | 🔴 Data corruption | → `fetchAll(PDO::FETCH_ASSOC)` |
| `export.php` | `addslashes()` for CSV quoting — invalid per RFC 4180 | 🟡 Broken CSV in many parsers | → `str_replace('"', '""', ...)` |
| `live_log.php` | AJAX auth called `checkBrowser()` — HTML redirect instead of JSON 401 | 🔴 Polling silently broken on session expiry | → `startSession()` + `isLoggedIn()` + JSON 401 |

## Outstanding low-priority items (not fixed this step)

| File | Issue | Priority |
|------|-------|----------|
| `dashboard.php` | `$_SESSION['time']` echoed into JS without `json_encode` — stored XSS vector for self | 🟡 Low |
| `dashboard.php` | `addslashes()` in JS `confirm()` dialogs — should be `json_encode()` | 🟡 Low |
| `dashboard.php` | Session picker uses `==` instead of `===` for `selected` comparison | 🟢 Cosmetic |
| `dashboard.php` | CDN assets lack SRI integrity hashes | 🟡 Low |
| `dashboard.php` | Delete/merge triggered by GET params — no CSRF token | 🟡 Low (auth-gated) |
| `export.php` | Trailing comma on every CSV row | 🟢 Cosmetic (most parsers tolerate) |
| `export.php` | No UTF-8 BOM — Excel on Windows may misread non-ASCII | 🟢 Low |
| `upload_data.php` | `addslashes()` for DDL COMMENT strings — should be `$pdo->quote()` | 🟡 Low |
| `upload_data.php` | `audit_torque_request()` is a bare function — PSR-1 violation | 🟡 Deferred to future `AuditRepository` |
| `live_log.php` | `$e->getMessage()` exposed in JSON 500 response | 🟢 Auth-gated, acceptable |
| `live_log.php` | 401 on AJAX does not redirect to login page | 🟢 UX improvement only |
