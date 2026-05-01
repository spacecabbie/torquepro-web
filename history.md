# Torque Logs Build History

**Maintenance Instructions**: This file is the **complete permanent archive** of ALL code changes, restructuring, schema migrations, 10-step feature build, security fixes, and project history for the Torque Logs project. Latest updates appear at the top (reverse chronological order); oldest at the bottom. All finished information from PROGRESS.md is merged here (no information lost). PROGRESS.md maintains only the last three changes or current large process steps. Uniform structure and Markdown formatting used throughout.

**Project Overview**  
**Build Duration**: April 22–29, 2026 (7 days)  
**Total Commits**: 155 with full git history  
**Repository**: `spacecabbie/torquepro-web` on GitHub  
**Final build commit**: `2e3aba3` (fix: guard htmlspecialchars null errors)

---

## Latest Updates

### 2026-05-01 — Parser Architecture Finalization (Decoupled Upload Pipeline)

**Major architectural improvement** — completed the intended separation of concerns:

- **`upload_data.php`** is now a **thin receiver + audit layer**:
  - Only validates the incoming Torque GET request
  - Persists the full raw payload to `upload_requests_raw` (with full audit trail)
  - Then calls `parseTorqueData($rawUploadId)` — **passing nothing but the database ID**
  - No business data (`$_GET`, session, timestamp, sensor values, etc.) is ever passed to the parser

- **`parser.php`** is now a **pure, self-contained business-logic layer**:
  - Fetches the original `raw_query_string` from `upload_requests_raw` by ID
  - Reconstructs the original parameters internally using `parse_str()`
  - Runs the complete parsing pipeline (metadata extraction, sensor upsert, GPS handling, session updates, readings insertion, gps_points, processed audit)
  - Fully decoupled — can be called independently from reprocess.php, future queue workers, or admin tools

- **Updated `reprocess.php`** to use the new single-ID signature (`parseTorqueData($rawId)`). Removed old multi-parameter calls.

- **Added `includes/Config/Torque.php`** — centralized, maintainable configuration for:
  - GPS sensor keys (`kff1001`, `kff1005`, `kff1006`, `kff1010`, etc.)
  - Calculated/derived prefixes (`kff12*`, `kff52*`, `kff125*`, etc.)
  - Common OBD PID suffix map for zero-padding normalization

- **Created `schema_updates.sql`** with recommended additive improvements:
  - `source` and `is_calculated` columns on `sensors` table (for better classification)
  - `pid_mode` column for extended PIDs (Mode 22, etc.)
  - Optional composite indexes and unit alias table

- **No backward-compatibility wrapper** added (per explicit request) — clean break to the new decoupled design.

This change fully realizes the long-stated architecture goal: clean separation between upload handling and parsing logic, with the raw audit table as the single source of truth between the two layers.

**Files changed**:
- `upload_data.php` (major simplification)
- `parser.php` (new input model + DB fetch)
- `reprocess.php` (updated call site)
- `includes/Config/Torque.php` (new)
- `schema_updates.sql` (new)

---

### 2026-05-01 — Post-Build Refactor: Upload Processing Architecture & Reprocess Web UI
- Split `upload_data.php` into upload handling (`upload_data.php`) and parsing logic (`parser.php`).
- `parser.php` contains reusable `parseTorqueData()` function for improved separation of concerns. No functional changes.
- Converted `reprocess.php` from CLI-only to authenticated web page with `Auth::checkBrowser()`.
- Added `?resetdb` function: empties processed tables except raw audit.
- Added `?reprocess` function: preview (first 25) + batched full processing.
- Throttled for large datasets; authenticated access only.
- Next steps: Integrate reset/reprocess buttons into dashboard UI.

### 2026-05-01 — Dashboard Stabilization, CSS Centralization & Sensor/Parser Fixes
- Stabilized session selection in `dashboard.php` so changing sensors preserves current session and panel state.
- Updated dashboard JS builders to read live DOM panel configuration before rebuilding URLs.
- Removed embedded dashboard CSS from `dashboard.php`; centralized in `static/css/dashboard.css`.
- Added backend sensor label fallback support from `data/torque_keys.csv` for missing `short_name`/`full_name` in repositories and API.
- Fixed parser metadata handling so `userShortName*`/`userFullName*` update existing labels; `defaultUnit*` fallback when `userUnit*` absent.
- Fixed upload parser metadata so `userUnit*` stored in `sensors.unit_id`.
- Extended `gps_points` insertion to include bearing, accuracy, satellites from Torque kff GPS.
- Populated `sessions.duration_seconds` on every upload update.
- Hotfix `2e3aba3`: Guarded `htmlspecialchars()` calls against null values (25+ fixes).

### 2026-04-29 — Hotfix: Guard htmlspecialchars null errors (2e3aba3)
- Fixed TypeError when selecting session; dropdown fails to render.
- Root cause: `ColumnRepository::findPlottable()` or array access `$col['key']` / `$col['label']` can return null values.
- Fix: Guard all `htmlspecialchars()` calls with `(string) ($var ?? '')`.
- Files affected: `dashboard.php` (25 fixes across panel options, session labels, summary rows, data attributes).
- Testing: Selector now renders options without error; summary table displays correctly.

---

# Torque Logs Build History

**Complete documentation archive of the Torque Logs restructuring and feature build projects.**

---

## Table of Contents

1. [Project Overview](#project-overview)
2. [File Traceability (Original → New)](#file-traceability)
3. [Restructuring Plan & Execution](#restructuring-plan)
4. [Schema Migration](#schema-migration)
5. [Code Quality Analysis](#code-quality-analysis)
6. [Remediation & Hardening](#remediation--hardening)
7. [Build Timeline: 10-Step Feature Build](#build-timeline)
8. [Technical Decisions](#technical-decisions)

---

## Project Overview

**Build Duration**: April 22–29, 2026 (7 days)  
**Total Commits**: 155 with full git history  
**Repository**: `spacecabbie/torquepro-web` on GitHub

### What Was Built

A complete modernization of a 2013-era procedural PHP application into:
1. Full OOP architecture with PSR-1, PSR-4, PSR-12 compliance
2. Normalized relational database schema
3. Automotive Sensor Analysis Workbench with CSS Grid, uPlot charts, and saved dashboards
4. Dark-theme responsive UI with real-time multi-sensor visualization

### Guiding Principles

- **PHP 8.4+** target with strict types everywhere
- **No Composer dependencies** — manually vendored PSR-3 interface only
- **Security-first** — timing-safe password comparison, prepared statements, SRI hashes, output encoding
- **Zero breaking changes** — all existing features preserved and upgraded
- **Traceable** — origin comments on every refactored section, full git commit history

---

## File Traceability

Complete mapping of every file from the old procedural structure to the new OOP classes.

### Authentication Files Consolidation

#### `auth_functions.php` → `includes/Auth/Auth.php`
| Code | Destination |
|------|-------------|
| `get_user()` | Removed (credentials read inline in `Auth::login()`) |
| `get_pass()` | Removed (`$_POST` only, no `$_GET`) |
| `get_id()` | `Auth::requireApp()` method |
| `auth_user()` | `Auth::login()` with `hash_equals()` |
| `auth_id()` | `Auth::requireApp()` validation |

#### `auth_user.php` → `includes/Auth/Auth.php` + `login.php`
| Code | Destination |
|------|-------------|
| Session start + `$_SESSION['torque_logged_in']` | `Auth::requireUser()` |
| User login flow | `Auth::login()` with timing-safe comparison |
| HTML login form (BS3 → BS5) | `login.php` with CSRF prep |

#### `auth_app.php` → `includes/Auth/Auth.php`
| Code | Destination |
|------|-------------|
| Torque ID auth flow | `Auth::requireApp()` |
| Plain-text error response | `Auth::requireApp()` |

### Database & Configuration

#### `creds.php` → `includes/config.php`
All credentials, database config, and feature flags consolidated into one config file.

#### `db.php` → `includes/Database/Connection.php`
PDO singleton factory with safe error handling and no credential exposure.

### Data Access Layer

| Old File | New Class | Location |
|----------|-----------|----------|
| `get_sessions.php` | `SessionRepository` | `includes/Data/SessionRepository.php` |
| `get_columns.php` | `ColumnRepository` | `includes/Data/ColumnRepository.php` |
| `plot.php` | `PlotRepository` | `includes/Data/PlotRepository.php` |
| (new extraction) | `GpsRepository` | `includes/Data/GpsRepository.php` |
| (new extraction) | `SummaryRepository` | `includes/Data/SummaryRepository.php` |
| (new extraction) | `SavedDashboardRepository` | `includes/Data/SavedDashboardRepository.php` |

### Session Management

| Old File | New Class |
|----------|-----------|
| `del_session.php` | `SessionManager::delete()` |
| `merge_sessions.php` | `SessionManager::merge()` |

### Utilities & Logging

| Old File | New Class |
|----------|-----------|
| `parse_functions.php` | `DataHelper` (static utility class) |
| (inline helpers) | `SqlHelper` (SQL identifier safety) |
| (inline logging) | `FileLogger` (PSR-3 compliant) |

### Entry Points (Updated)

| File | Changes |
|------|---------|
| `upload_data.php` | Uses new classes, normalized schema inserts |
| `export.php` | Added auth check, fixed CSV/JSON bugs |
| `live_log.php` | Absorbs `live_log_data.php`, proper AJAX 401 response |
| `dashboard.php` | Completely rewritten as workbench, absorbs `timezone.php` |
| `session.php` | 301 redirect to `dashboard.php` for backwards compatibility |

### Deleted Files

These files are superseded and no longer referenced:
- `auth_functions.php`, `auth_user.php`, `auth_app.php`
- `db.php`, `creds.php`, `creds-sample.php` (reference only)
- `get_sessions.php`, `get_columns.php`, `plot.php`
- `del_session.php`, `merge_sessions.php`, `parse_functions.php`
- `live_log_data.php`, `timezone.php`, `...