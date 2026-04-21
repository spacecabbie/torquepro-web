# GitHub Copilot Instructions — Torque Logs

This is a PHP/MariaDB web application for viewing OBD-II data uploaded by the
Torque Pro Android app. All AI suggestions and generated code must follow the
standards defined in this file.

---

## PHP Version Target

- **Runtime**: PHP 8.4+ compatible, linted against **PHP 8.4**
- All files must declare `declare(strict_types=1);` at the top

---

## Coding Standards

### PSR Compliance (mandatory)

All PHP code must conform to the following PHP-FIG standards.

#### Currently active in this project

| PSR | Standard | Key rules |
|-----|----------|-----------|
| **PSR-1** | Basic Coding Standard | UTF-8, `<?php` only (no short tags), files either declare symbols OR produce side-effects, not both |
| **PSR-12** | Extended Coding Style | 4-space indent (no tabs), LF line endings, one blank line after namespace/use blocks, `declare(strict_types=1)` on line 2 |
| **PSR-3** | Logger Interface | Any logger class must implement `Psr\Log\LoggerInterface`. Use standard log levels: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`. The existing file logger in `upload_data.php` must be refactored to comply when touched. |
| **PSR-4** | Autoloading Standard | Class namespace must map to directory structure: `TorqueLogs\Auth\Auth` → `includes/Auth/Auth.php` |

#### Conditionally applicable — follow if the scope arises

If any new code, feature, or refactor introduces functionality that falls under
the scope of the PSRs below, that PSR **must** be followed for that code:

| PSR | Standard | Trigger |
|-----|----------|---------|
| **PSR-6** | Caching Interface | If any caching layer is introduced |
| **PSR-7** | HTTP Message Interface | If HTTP request/response objects are introduced |
| **PSR-11** | Container Interface | If a dependency injection container is introduced |
| **PSR-14** | Event Dispatcher Interface | If an event/hook system is introduced |
| **PSR-15** | HTTP Handlers / Middleware | If a middleware pipeline is introduced |
| **PSR-16** | Simple Cache | If a simple key-value cache is introduced |
| **PSR-17** | HTTP Factories | If PSR-7 HTTP factories are introduced |
| **PSR-18** | HTTP Client | If an outbound HTTP client is introduced |
| **PSR-20** | Clock Interface | If time/clock abstraction is introduced |

> **PSR-2** is deprecated and fully superseded by PSR-12 — do not reference it.
> **PSR-5** (PHPDoc) remains a draft but its conventions are followed — see the
> Documentation section below.

References:
- https://www.php-fig.org/psr/psr-1/
- https://www.php-fig.org/psr/psr-3/
- https://www.php-fig.org/psr/psr-4/
- https://www.php-fig.org/psr/psr-12/
- https://www.php-fig.org/ (full PSR index)

---

## OOP Migration Path

The codebase is being migrated from procedural PHP (~2013 style) to modern OOP.
**All new code must be written as classes.** Existing procedural code is refactored
to OOP when a file is touched as part of a task.

### Class conventions (PSR-1 / PSR-12)

```php
<?php
declare(strict_types=1);

namespace TorqueLogs\Data;

class SessionRepository
{
    public function __construct(private readonly \PDO $pdo) {}

    public function findAll(): array
    {
        // ...
    }
}
```

- **Namespace root**: `TorqueLogs\`
- **Namespace → path**: `TorqueLogs\Auth` → `includes/Auth/`
- **Class names**: `PascalCase`
- **Method names**: `camelCase`
- **Constants**: `UPPER_SNAKE_CASE`
- **Properties**: `camelCase`, typed, no untyped properties
- **One class per file**
- **Opening brace on its own line** for classes and methods (PSR-12)
- Use `readonly` properties and constructor promotion where appropriate (PHP 8.x)

### Method design rules

- Methods must do **one thing** (Single Responsibility)
- Methods must **return values** — never `echo` / output from a non-view class
- Type declarations are **mandatory** on all parameters and return types
- `void` return type must be declared explicitly when nothing is returned

---

## Security Rules (OWASP)

- **Never** accept credentials via `$_GET` — `$_POST` only
- **Always** use `hash_equals()` for credential comparison (timing-safe)
- **Never** output raw user input — always `htmlspecialchars()` with `ENT_QUOTES`
- **Always** use PDO prepared statements — no string interpolation in SQL
- **Always** whitelist column names before using in queries (`is_valid_column_name()`)
- **Never** expose stack traces or DB errors to the browser in production
- Include `includes/.htaccess` that denies direct web access to all include files

---

## File & Directory Structure

```
torque-logs/
├── .github/
│   └── copilot-instructions.md
├── includes/
│   ├── .htaccess               ← Deny all direct web access
│   ├── config.php              ← App configuration (credentials, flags)
│   ├── db.php                  ← PDO factory / database layer
│   ├── auth.php                ← Authentication (browser + AJAX modes)
│   ├── data.php                ← Data loaders (sessions, columns, plot data)
│   ├── actions.php             ← Session actions (delete, merge)
│   └── helpers.php             ← Pure utility functions (stats, CSV, sparklines)
├── dashboard.php               ← Main UI entry point
├── login.php                   ← Dedicated login page
├── live_log.php                ← Live upload monitor (absorbs live_log_data.php)
├── upload_data.php             ← Torque app upload endpoint
└── export.php                  ← CSV / JSON export endpoint
```

- **`includes/`** files are never entry points — no direct browser access
- **Entry point files** (`dashboard.php`, `login.php`, etc.) contain only:
  bootstrap, auth check, data loading calls, and view rendering
- **No business logic in view files** — logic lives in `includes/`

---

## Separation of Concerns

| Layer | Where | Rule |
|-------|-------|------|
| Configuration | `includes/config.php` | No logic, no output |
| Database | `includes/db.php` | No output, returns PDO / results |
| Authentication | `includes/auth.php` | No output — redirects or returns |
| Business logic | `includes/data.php`, `includes/actions.php` | No output, returns data |
| Utilities | `includes/helpers.php` | Pure functions, no side-effects |
| Presentation | `*.php` entry points | Calls includes, then renders HTML |

**Auth functions must never produce output.** On failure:
- Browser pages → `header('Location: login.php')` + `exit`
- AJAX endpoints → `http_response_code(401)` + `json_encode(['error' => ...])` + `exit`

---

## Origin Comments

When code from an old file is merged into a new combined file, mark the
boundary with a section header showing the origin:

```php
// ============================================================
// Session loader  (origin: get_sessions.php)
// ============================================================
```

This preserves traceability without relying solely on `git log`.

---

## Documentation

- All classes and public methods must have a **PHPDoc block**
- PHPDoc must include `@param`, `@return`, and `@throws` where applicable

```php
/**
 * Load all qualifying sessions from the database.
 *
 * @return array<string, array{date: string, size: string}> Keyed by session ID
 * @throws \PDOException on database failure
 */
public function findAll(): array
```

---

## What NOT to do

- Do **not** use `mysql_*` functions (removed in PHP 7)
- Do **not** use short open tags `<?` or `<?=` in logic files
- Do **not** set bare global variables as side-effects from included files
- Do **not** mix HTML output into auth or data files
- Do **not** hardcode credentials anywhere except `includes/config.php`
- Do **not** suppress errors with `@` operator
- Do **not** use `==` for security-sensitive comparisons — use `hash_equals()` or `===`
- Do **not** add new procedural functions — wrap in a class instead
