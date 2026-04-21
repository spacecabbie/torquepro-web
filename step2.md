# Step 2 — Post-Implementation Analysis

Performed after all Step 2 files were created and linted.  
Both PHP files pass `php -l` under **PHP 8.4**.

---

## File-by-file Review

### `includes/Auth/Auth.php`

**Structure**

✅ PSR-4 mapping: `TorqueLogs\Auth\Auth` → `includes/Auth/Auth.php`.  
✅ `declare(strict_types=1)` on line 2.  
✅ All-static class — appropriate because there is no per-instance state; the session is global to the request. Constructor deliberately omitted (not needed).  
✅ Full PHPDoc on every public and private method.

**`checkBrowser()`**

✅ Calls `startSession()` before touching `$_SESSION` — safe order.  
✅ Redirects to `login.php` and immediately calls `exit` — no code runs after auth failure.  
✅ No output before the `header()` call — no whitespace/BOM risk.

**`checkApp()`**

✅ Returns HTTP 401 with a plain-text message — correct for a machine-to-machine endpoint.  
✅ Delegates all ID logic to `validateTorqueId()` — no logic in the guard itself.

**`login()`**

✅ Reads credentials from `$_POST` **only** — `$_GET` is never used. Satisfies OWASP requirement.  
✅ Calls `session_regenerate_id(true)` after successful login — prevents session fixation attacks.  
✅ `hash_equals()` used inside `validateUserPass()` — timing-safe comparison.

**`logout()`**

✅ Clears `$_SESSION`, removes the session cookie, then destroys the session — correct
three-step teardown. Matches PHP manual best practice.

**`isLoggedIn()`**

✅ Public read-only check — useful for `login.php` redirect-if-already-authenticated. No side-effects
beyond starting the session.

**`startSession()`**

✅ Guards with `session_status() === PHP_SESSION_NONE` — idempotent, safe to call multiple times.  
✅ Uses `dirname($_SERVER['SCRIPT_NAME'] ?? '/')` for the cookie path — scopes the session cookie to
the application subdirectory, matching original `session_set_cookie_params` behaviour.

**`validateUserPass()`**

✅ Open-access mode (`AUTH_USER === '' && AUTH_PASS === ''`) preserved from original.  
✅ Uses `defined()` guards before reading constants — safe even if `config.php` is not loaded.  
✅ Both `hash_equals()` calls must pass — an attacker cannot exploit a short-circuit on the username alone.

**`extractTorqueId()`**

✅ Anchored regex `^[0-9a-f]{32}$` — exact 32-char hex, no partial matches.  
✅ Returns `''` on failure — `validateTorqueId('')` correctly rejects it in all cases.

**`validateTorqueId()`**

✅ `TORQUE_ID` takes precedence over `TORQUE_ID_HASH` — preserves original priority logic.  
✅ `hash_equals()` inside the loop — timing-safe, no early exit on partial match.  
✅ Supports arrays of hashes (multiple authorised devices).  
✅ Open-access mode when both constants are empty.

⚠️ **Note (document in config)**: `(array) $configId` works correctly for a string (single device)
or an array (multiple devices). If a user sets `TORQUE_ID` to a comma-separated string it will be
treated as one device ID. Add a comment to `config.php` clarifying that multiple IDs require a PHP
array constant: `define('TORQUE_ID', ['id1', 'id2'])`.

---

### `login.php`

**Bootstrap / security**

✅ `require_once __DIR__ . '/includes/config.php'` — absolute `__DIR__` path.  
✅ `require_once __DIR__ . '/includes/Auth/Auth.php'` — absolute `__DIR__` path.  
✅ `use TorqueLogs\Auth\Auth` declared — PSR-4 namespace import present.  
✅ `declare(strict_types=1)` on line 2.

**Already-logged-in redirect**

✅ Calls `Auth::isLoggedIn()` before any output — if already authenticated the user is
immediately sent to `dashboard.php`.

**POST handling**

✅ Checks `$_SERVER['REQUEST_METHOD'] === 'POST'` before calling `Auth::login()` — no
credentials are processed on a GET request.  
✅ On success: `header('Location: dashboard.php')` + `exit` — no HTML rendered to an authenticated user.  
✅ On failure: `$error` set to a **generic** message — does not reveal whether username or
password was wrong (avoids user-enumeration).

**Output**

✅ `$error` passed through `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` — XSS-safe.  
✅ No user-supplied input echoed anywhere else on the page.  
✅ Form `action="login.php"` — POSTs back to itself, not to a third-party.  
✅ `autocomplete="username"` and `autocomplete="current-password"` — password managers work correctly.  
✅ `novalidate` on the form — prevents browser validation tooltip text from leaking auth info.

**HTML / UX**

✅ Dark theme consistent with `dashboard.php`.  
✅ Bootstrap 5.3.3 from CDN with SRI integrity hash — tampering-resistant.  
✅ No JavaScript — fully functional without JS.  
✅ Responsive (`max-width: 380px`, centred with flexbox).

⚠️ **Note**: No CSRF token on the POST form. Low risk for a single-user personal tool, but should
be added if the app is ever publicly hosted. Track for a future hardening pass.

⚠️ **Note**: No rate-limiting or login-attempt lockout. Acceptable for a personal tool; note for
future hardening.

---

## Summary Table

| File | Status | Action taken |
|------|--------|--------------|
| `includes/Auth/Auth.php` | ✅ Clean | Note: document multi-device array syntax in `config.php` |
| `login.php` | ✅ Clean | Note: CSRF and rate-limiting absent — acceptable for personal use |

**0 bugs found. 2/2 files pass PHP 8.4 lint.**
