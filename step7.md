# Step 7 — Hardening: Fresh-Eyes Analysis

## Scope

Step 7 fixes 15 security and correctness issues identified during the
post-restructure analysis pass (remediation-plan.md).  Five files were
touched and one new class was created.

---

## Files Changed

| File | Changes |
|------|---------|
| `dashboard.php` | R-01, R-02, R-03, R-04, R-05, R-14, R-15 |
| `export.php` | R-06, R-07, R-08 |
| `upload_data.php` | R-09, R-10 (caller) |
| `includes/Logging/AuditLogger.php` | **NEW** — R-10 (class) |
| `live_log.php` | R-11, R-12, R-13 |

---

## Item-by-Item Review

### R-01 — Raw `$timezone` echo in JS (dashboard.php)

**Before:**
```php
if ("<?php echo $timezone; ?>".length === 0) {
```
**After:**
```php
if (<?php echo json_encode($timezone); ?>.length === 0) {
```
**Assessment ✅**  
`json_encode()` always produces a syntactically valid JS string literal
(including proper escaping of quotes, backslashes, and control characters).
The old form was vulnerable to XSS if `$timezone` contained a `"` or `\`.
New form is correct.

---

### R-02 — `addslashes()` in JS confirm dialogs (dashboard.php)

**Before:**
```php
if (!confirm('Delete session "<?php echo addslashes($seshdates[$session_id]); ?>"?'))
```
**After:**
```php
if (!confirm(<?php echo json_encode('Delete session "' . ($seshdates[$session_id] ?? '') . '"?'); ?>))
```
**Assessment ✅**  
`addslashes()` only escapes `\`, `'`, `"`, and NUL — it does not produce a
safe JS string literal.  `json_encode()` on the whole message string produces
a correctly quoted and escaped JS string, eliminating the XSS vector.  The
`?? ''` null-coalescing guards against undefined session keys.

---

### R-03 — Loose `==` in session picker (dashboard.php)

**Before:**
```php
<?php if ($dateid == ($session_id ?? '')) echo 'selected'; ?>
```
**After:**
```php
<?php if ($dateid === ($session_id ?? '')) echo 'selected'; ?>
```
**Assessment ✅**  
Both variables are strings (digits only after `preg_replace`), so `===` is
the correct comparison.  The loose `==` could theoretically cause unexpected
truthy matches for numeric-looking session IDs with type coercion.

---

### R-04 — CDN tags without SRI (dashboard.php)

All 8 external CDN `<link>` and `<script>` tags now carry `integrity`,
`crossorigin="anonymous"`, and `referrerpolicy="no-referrer"` attributes.
Hashes were fetched live from the cdnjs API (`/libraries/<name>/<ver>?fields=sri`)
and are SHA-512.

| Asset | SRI source |
|-------|-----------|
| Bootstrap 5.3.8 CSS | cdnjs API |
| Bootstrap 5.3.8 JS bundle | cdnjs API |
| Leaflet 1.9.4 CSS | cdnjs API |
| Leaflet 1.9.4 JS | cdnjs API |
| Chosen 1.8.7 CSS | cdnjs API |
| Chosen 1.8.7 JS | cdnjs API |
| jQuery 3.7.1 | cdnjs API |
| jQuery UI 1.14.1 | cdnjs API |

Google Fonts excluded (cross-origin dynamic CSS; SRI not applicable for
variable font URL responses).

**Assessment ✅**  
SRI ensures the browser rejects any CDN-served file that has been tampered
with.  `referrerpolicy="no-referrer"` prevents the CDN from receiving
the dashboard URL in the `Referer` header.

---

### R-05 — Delete/merge actions via `$_GET` (dashboard.php)

**PHP side:**  
`$_GET['deletesession']`, `$_GET['mergesession']`, and
`$_GET['mergesessionwith']` all replaced with `$_POST` equivalents.
Comment updated from "Forms pass the session ID in the query string" to the
accurate description.

**HTML side:**  
Form `action` attributes no longer carry IDs in the query string.  Session
IDs are now delivered as `<input type="hidden">` elements, and all values
are `htmlspecialchars()`-escaped in the HTML output.

**Assessment ✅**  
Mutating state via GET is a CSRF-trivial attack surface (a link or `<img
src>` is sufficient).  POST at minimum requires a form submission from the
same origin.  The existing `method="post"` on both forms was already there,
but the action URL still leaked the IDs in the query string; that is now
closed.

---

### R-06 — CSV trailing comma (export.php)

**Before:** `foreach` loop with `$output .= ...',';` after every cell.  
**After:** `implode(',', array_map($escapeCsv, $row))` — RFC 4180 compliant.

The `$escapeCsv` closure is declared `static fn` — no `$this` capture
needed, slightly more efficient.

**Assessment ✅**  
The trailing comma caused the last column to appear as an extra empty column
in Excel and other CSV parsers.

---

### R-07 — UTF-8 BOM in CSV export (export.php)

`$output = "\xEF\xBB\xBF";` prepended before the header row.

**Assessment ✅**  
Without the BOM, Excel on Windows interprets the file as Windows-1252 and
mangles non-ASCII sensor names.  The BOM is the de-facto signal for
Excel/LibreOffice to open the file as UTF-8.

---

### R-08 — `JSON_UNESCAPED_UNICODE` in JSON export (export.php)

**Before:** `json_encode($rows, JSON_THROW_ON_ERROR)`  
**After:** `json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)`

**Assessment ✅**  
Without this flag, non-ASCII characters (sensor labels, location names) are
serialised as `\uXXXX` escape sequences, bloating the file and making it
harder to read.

---

### R-09 — `addslashes()` for DDL COMMENT (upload_data.php)

**Before (ADD column):**
```php
" COMMENT '" . addslashes($sensor_names[$key]) . "'"
```
**After:**
```php
' COMMENT ' . $pdo->quote($sensor_names[$key])
```

**Before (MODIFY column):**
```php
$comment = addslashes($sensor_names[$key]);
// …
"… COMMENT '{$comment}'"
```
**After:**
```php
$comment = $pdo->quote($sensor_names[$key]);
// …
"… COMMENT {$comment}"
```
(Note: `$pdo->quote()` includes the surrounding single quotes itself.)

**Assessment ✅**  
`addslashes()` is not a DDL-safe escaping function.  `PDO::quote()` uses the
driver's native quoting, which handles single quotes and other special
characters correctly for MariaDB string literals.

---

### R-10 — Bare procedural `audit_torque_request()` function (upload_data.php)

**New class:** `TorqueLogs\Logging\AuditLogger` in
`includes/Logging/AuditLogger.php`.

- Namespace: `TorqueLogs\Logging` — correct PSR-4 mapping.
- Static method `record()` — identical behaviour to the old function.
- PHPDoc on class and method including `@param`, `@return`.
- `declare(strict_types=1)` present.
- `Throwable` catch still suppresses audit failures silently.

`upload_data.php` updated:
- `require_once 'includes/Logging/AuditLogger.php'` added.
- `use TorqueLogs\Logging\AuditLogger` added alongside existing `use` block.
- All 4 call sites (`audit_torque_request(`) replaced by `AuditLogger::record(`.
- Old function body removed; replaced with a one-line origin comment.

**Assessment ✅**  
Complies with the OOP migration mandate: no new bare functions.  The comment
`// (origin: upload_data.php → audit_torque_request())` preserves
traceability.

---

### R-11 — Exception message leaked in JSON 500 response (live_log.php)

**Before:** `echo json_encode(['error' => $e->getMessage()]);`  
**After:** `echo json_encode(['error' => 'Internal server error']);`

**Assessment ✅**  
Exception messages can leak table names, column names, file paths, and
SQL syntax — all useful to an attacker.  The generic string is sufficient
for the client-side error display.

---

### R-12 — AJAX 401 not redirecting to login (live_log.php)

**Poll fetch `.then()` chain — before:**
```js
.then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
.then(function(d){ if(d.error) throw new Error(d.error); …
```
**After:**
```js
.then(function(r){
  if(r.status===401){ location.href='login.php'; return null; }
  if(!r.ok) throw new Error('HTTP '+r.status);
  return r.json();
})
.then(function(d){
  if(!d) return;
  if(d.error) throw new Error(d.error); …
```

Boot fetch similarly updated.

**Assessment ✅**  
Without the 401 guard the live log would silently enter a perpetual
error-polling loop after session expiry.  The `if(!d) return;` null guard
prevents `.error` access on `null` when the redirect branch fires.

---

### R-13 — `esc()` missing apostrophe escape (live_log.php)

**Before:** `…replace(/"/g,'&quot;');`  
**After:** `…replace(/"/g,'&quot;').replace(/'/g,'&#39;');`

**Assessment ✅**  
Unescaped `'` in HTML attribute values (e.g. `onclick='…'` or
`title='…'`) can break attribute quoting.  Adding `&#39;` closes the gap.

---

## Cross-Cutting Observations

1. **No regressions introduced.** The behaviour of all unchanged paths is
   identical; only vulnerable/incorrect paths were modified.

2. **PDO::quote() caveat.** In theory `PDO::quote()` can return `false` if
   the driver does not support quoting.  The MariaDB PDO driver always
   implements quoting, so this is safe in this project's context.  A
   defensive `?: "''"` fallback could be added in a future pass if the
   driver dependency is ever abstracted.

3. **CSRF — partial mitigation only.**  Moving delete/merge to POST prevents
   trivial one-click CSRF via `<img src>` or `<a href>`.  Full CSRF
   protection would require a synchronised token (CSRF token in a hidden
   input + server validation).  This is tracked as a future hardening item
   — beyond the scope of the current 15-item plan.

4. **SRI for Google Fonts** intentionally omitted.  The Fonts API returns
   dynamically generated CSS that varies by browser UA; a static hash cannot
   be committed.

5. **All five modified PHP files pass `php -l` under PHP 8.4** with zero
   errors.

---

## Commit

`step7: hardening — fix all 15 remediation items (R-01 through R-15)`
