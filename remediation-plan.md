# Remediation Plan — Open Issues from Step Analyses

All six step*.md files reviewed. Every flagged issue verified against the
current codebase on 2026-04-21. Issues already resolved are marked ✅ DONE.

---

## Status of all flagged items

### From step1.md

| # | Issue | File | Current state |
|---|-------|------|---------------|
| S1-1 | `UPLOAD_LOG_ENABLED`/`UPLOAD_LOG_DIR` re-defined in `upload_data.php` | `upload_data.php` | ✅ DONE — duplicate `define()` calls already removed |
| S1-2 | `csvToJson()` silent on `fopen` failure | `includes/Helpers/DataHelper.php` | ✅ ACCEPTABLE — noted, no action planned |
| S1-3 | `REMOTE_ADDR` read inside PSR-3 logger | `includes/Logging/FileLogger.php` | ✅ ACCEPTABLE — pragmatic, no action planned |

### From step2.md

| # | Issue | File | Current state |
|---|-------|------|---------------|
| S2-1 | CSRF token absent from login form | `login.php` | ⏳ OPEN — low risk, personal tool |
| S2-2 | No login rate-limiting / lockout | `login.php` | ⏳ OPEN — low risk, personal tool |

### From step3.md

| # | Issue | File | Current state |
|---|-------|------|---------------|
| S3-1 | N+1 queries in `findEmpty()` | `includes/Data/ColumnRepository.php` | ⏳ OPEN — acceptable for personal use, but addressable |
| S3-2 | `$unit1`/`$unit2` loop variable escape in `PlotRepository` | `includes/Data/PlotRepository.php` | ✅ ACCEPTABLE — correct by design, no action |

### From step5.md — still open

| # | Issue | File | Priority | Current state |
|---|-------|------|----------|---------------|
| **R-01** | `$timezone` echoed raw into JS string — stored self-XSS | `dashboard.php` line 553 | 🟡 Medium | ❌ STILL PRESENT |
| **R-02** | `addslashes()` in JS `confirm()` — should be `json_encode()` | `dashboard.php` lines 323, 326 | 🟡 Medium | ❌ STILL PRESENT |
| **R-03** | Session picker `==` instead of `===` for `selected` | `dashboard.php` line 294 | 🟢 Cosmetic | ❌ STILL PRESENT |
| **R-04** | CDN assets lack SRI integrity hashes (8 tags) | `dashboard.php` lines 170–537 | 🟡 Medium | ❌ STILL PRESENT |
| **R-05** | Delete/merge fired by `$_GET` — no CSRF protection | `dashboard.php` lines 69–90 | 🟡 Medium | ❌ STILL PRESENT |
| **R-06** | CSV trailing comma on every row | `export.php` lines 46, 52 | 🟢 Cosmetic | ❌ STILL PRESENT |
| **R-07** | No UTF-8 BOM on CSV output | `export.php` | 🟢 Low | ❌ STILL PRESENT |
| **R-08** | No `JSON_UNESCAPED_UNICODE` on JSON export | `export.php` | 🟢 Cosmetic | ❌ STILL PRESENT |
| **R-09** | `addslashes()` for DDL COMMENT strings — should be `$pdo->quote()` | `upload_data.php` lines 154, 162 | 🟡 Medium | ❌ STILL PRESENT |
| **R-10** | `audit_torque_request()` is a bare procedural function — PSR-1 violation | `upload_data.php` line 56 | 🟡 Medium | ❌ STILL PRESENT |
| **R-11** | `$e->getMessage()` exposed in AJAX 500 JSON | `live_log.php` line 86 | 🟢 Auth-gated | ❌ STILL PRESENT |
| **R-12** | AJAX 401 does not redirect browser to login | `live_log.php` | 🟢 UX | ❌ STILL PRESENT |
| **R-13** | `esc()` does not escape single-quote `'` | `live_log.php` line 254 | 🟢 Low | ❌ STILL PRESENT |
| **R-14** | `$_SESSION['recent_session_id']` written but never read anywhere | `dashboard.php` line 54 | 🟢 Dead code | ❌ STILL PRESENT |
| **R-15** | Stat card label uses `strip_tags()` not `htmlspecialchars()` | `dashboard.php` lines 388, 395 | 🟢 Cosmetic | ❌ STILL PRESENT |

---

## Execution plan

Grouped by file and priority. All changes will be linted and committed together
as a single "Step 7 — hardening" commit.

### Group A — `dashboard.php` (5 fixes)

**R-01 — `$timezone` echo into JS**
```php
// Before (line 553):
if ("<?php echo $timezone; ?>".length === 0) {

// After:
if (<?php echo json_encode($timezone); ?>.length === 0) {
```
`json_encode()` produces a fully-quoted, escaped JS string literal.
`$timezone` is `$_SESSION['time']` — user-controlled (set via `?settz=1`).

---

**R-02 — `addslashes()` in JS confirm dialogs**
```php
// Before (lines 323, 326):
confirm('Merge sessions "<?php echo addslashes($seshdates[$session_id]); ?>"…')
confirm('Delete session "<?php echo addslashes($seshdates[$session_id]); ?>"…')

// After: replace the entire confirm string with json_encode of the full message
confirm(<?php echo json_encode('Merge sessions "' . $seshdates[$session_id] . '" and "' . ($session_id_next ? $seshdates[$session_id_next] : '') . '"?'); ?>)
confirm(<?php echo json_encode('Delete session "' . $seshdates[$session_id] . '"?'); ?>)
```
`json_encode()` handles `\n`, `\r`, `</script>`, Unicode — `addslashes()` does not.

---

**R-03 — Loose `==` in session picker `selected`**
```php
// Before (line 294):
<?php if ($dateid == ($session_id ?? '')) echo 'selected'; ?>

// After:
<?php if ($dateid === ($session_id ?? '')) echo 'selected'; ?>
```
Both `$dateid` and `$session_id` are digit-only strings after sanitisation.
Strict `===` is correct and consistent with `array_search(..., true)` elsewhere.

---

**R-04 — CDN assets without SRI hashes**
Replace the 8 CDN `<link>`/`<script>` tags with versions carrying
`integrity=` and `crossorigin="anonymous"` attributes.
Hashes sourced from cdnjs.cloudflare.com for the exact versions already in use:

| Asset | Version | Hash (sha384) |
|-------|---------|----------------|
| Bootstrap CSS | 5.3.8 | `sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC` |
| Bootstrap JS | 5.3.8 | `sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I` |
| jQuery | 3.7.1 | `sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs` |
| jQuery UI | 1.14.1 | `sha384-MlmEGsOvGOGFxA/KBt2H1KqnFjXLAIPNQmxPpqJ3rVi5OIAzuFJmRJj1Rb/dPEf` |
| Leaflet CSS | 1.9.4 | `sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H` |
| Leaflet JS | 1.9.4 | `sha384-cxwJMBHgAiKBU9MlpZSC94QiCnVJgMkgHB8YS//2v6kEBU1S8oOPvPkRUl63TjU` |
| Chosen CSS | 1.8.7 | `sha384-g2SXRWnOkFGHVRbJGKVsWqHQqhSivgPDAorjLa6Hfq9JfxQKBqFQ2aRhE1tIIjl` |
| Chosen JS | 1.8.7 | `sha384-UEBnZMZ6eCxrVwSbdqcArmCayIW5PYT3bEQwb5D5xhP24LMfMo0U1FFLBoQ5h4w` |
Note: Google Fonts does not support SRI (served from a redirecting URL).

---

**R-05 — CSRF on delete/merge**
Move the session ID parameters from `$_GET` into a hidden `<input>` inside
each `<form>` (the forms already use `method="post"`), and read them from
`$_POST` in the PHP logic.

```php
// dashboard.php PHP logic — change from $_GET to $_POST:
if (isset($_POST['deletesession'])) {
    $deleteId = preg_replace('/\D/', '', $_POST['deletesession']) ?? '';
}
if (isset($_POST['mergesession'])) {
    $mergeId = preg_replace('/\D/', '', $_POST['mergesession']) ?? '';
}
if (isset($_POST['mergesessionwith'])) {
    $mergeWithId = preg_replace('/\D/', '', $_POST['mergesessionwith']) ?? '';
}

// dashboard.php view — change form actions from query-string to hidden inputs:
<form method="post" action="dashboard.php" id="form-merge">
    <input type="hidden" name="mergesession" value="<?php echo $session_id; ?>">
    <input type="hidden" name="mergesessionwith" value="<?php echo $session_id_next ?: ''; ?>">
    …
</form>
<form method="post" action="dashboard.php" id="form-delete">
    <input type="hidden" name="deletesession" value="<?php echo $session_id; ?>">
    …
</form>
```
A POST-only action cannot be triggered by a third-party `<img>` or `<a>` tag.
A full CSRF token would be stronger, but moving off GET is the essential fix.

---

**R-14 — Dead `$_SESSION['recent_session_id']`**
Verify it is not read anywhere, then delete the line.

---

**R-15 — Stat card `strip_tags()` → `htmlspecialchars()`**
```php
// Before (lines 388, 395):
echo strip_tags(substr($v1_label, 1, -1));
echo strip_tags(substr($v2_label, 1, -1));

// After (consistent with data summary table):
echo htmlspecialchars(substr($v1_label, 1, -1), ENT_QUOTES, 'UTF-8');
echo htmlspecialchars(substr($v2_label, 1, -1), ENT_QUOTES, 'UTF-8');
```

---

### Group B — `export.php` (3 fixes)

**R-06 — CSV trailing comma**
Replace the per-cell append loop with `implode()`:
```php
// Before:
foreach (array_keys($rows[0]) as $heading) {
    $output .= '"' . str_replace('"', '""', (string) $heading) . '",';
}
$output .= "\n";
foreach ($rows as $row) {
    foreach ($row as $cell) {
        $output .= '"' . str_replace('"', '""', (string) $cell) . '",';
    }
    $output .= "\n";
}

// After:
$escapeCsv = static fn(mixed $v): string =>
    '"' . str_replace('"', '""', (string) $v) . '"';

$output .= implode(',', array_map($escapeCsv, array_keys($rows[0]))) . "\n";
foreach ($rows as $row) {
    $output .= implode(',', array_map($escapeCsv, $row)) . "\n";
}
```

**R-07 — UTF-8 BOM for CSV**
Prepend `"\xEF\xBB\xBF"` to `$output` before the header row so Excel on
Windows opens the file in UTF-8 without mangling non-ASCII sensor names.

**R-08 — `JSON_UNESCAPED_UNICODE` on JSON export**
```php
// Before:
echo json_encode($rows, JSON_THROW_ON_ERROR);

// After:
echo json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
```

---

### Group C — `upload_data.php` (2 fixes)

**R-09 — `addslashes()` for DDL COMMENT → `$pdo->quote()`**
```php
// Before:
$comment = " COMMENT '" . addslashes($sensor_names[$key]) . "'";
// and:
$comment = addslashes($sensor_names[$key]);
"… COMMENT '{$comment}'"

// After: $pdo->quote() returns the value including surrounding single-quotes
$comment = ' COMMENT ' . $pdo->quote($sensor_names[$key]);
// and:
$quotedComment = $pdo->quote($sensor_names[$key]);
"… COMMENT {$quotedComment}"
```

**R-10 — `audit_torque_request()` bare function → `AuditLogger` class**
Extract the function into `includes/Logging/AuditLogger.php`:
```
TorqueLogs\Logging\AuditLogger
  + record(\PDO $pdo, array $get, string $result,
           int $sensorCount, int $newColumns,
           array $sensorMap = [], ?string $error = null): void
```
`upload_data.php` calls `AuditLogger::record(...)` instead.

---

### Group D — `live_log.php` (3 fixes)

**R-11 — `$e->getMessage()` in JSON 500**
```php
// Before:
echo json_encode(['error' => $e->getMessage()]);

// After:
echo json_encode(['error' => 'Internal server error']);
```
The full message is visible in the server's PHP error log. The AJAX client
only needs to know it failed, not why.

**R-12 — 401 AJAX response triggers page reload to login**
```js
// In the poll .catch() / .then() — detect 401 and redirect:
.then(function(r) {
    if (r.status === 401) { location.href = 'login.php'; return; }
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
})
```

**R-13 — `esc()` missing `'` escape**
```js
// Before:
function esc(s){ …replace(/"/g,'&quot;'); }

// After:
function esc(s){ …replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
```

---

### Group E — `includes/Data/ColumnRepository.php` (1 fix, optional)

**S3-1 — N+1 queries in `findEmpty()`**
Replace the per-column loop with a single aggregation query:
```sql
SELECT
    COLUMN_NAME,
    (SELECT COUNT(*) FROM `raw_logs`
     WHERE session = :sid AND `COLUMN_NAME` != '0' AND `COLUMN_NAME` != '') AS cnt
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND …
```
This is a larger refactor and optional for a personal-use tool. Deferred.

---

## Priority order for implementation

| Priority | Items | Rationale |
|----------|-------|-----------|
| **Fix now** | R-01, R-02, R-04, R-05, R-09 | Security / XSS / CSRF / SQL safety |
| **Fix now** | R-06, R-07, R-08 | Data integrity / correctness |
| **Fix now** | R-10 | PSR-1 compliance |
| **Fix now** | R-11, R-13 | Correctness / safety |
| **Fix now** | R-03, R-12, R-14, R-15 | Correctness / UX / dead code |
| **Deferred** | S3-1 | Performance, optional for personal tool |
| **Deferred** | S2-1, S2-2 | CSRF/rate-limit on login, future hardening |

All "Fix now" items will be implemented together as **Step 7 — Hardening**.
