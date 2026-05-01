# Build Progress — Automotive Sensor Analysis Workbench

**Maintenance Instructions**: This file contains ONLY the last three changes to the project (or logs the current step of any large ongoing process). Latest updates appear at the top (reverse chronological order). When a change is completed and older than the last three, it is moved to history.md. Full history and all older information is preserved in history.md. Both files use uniform structure, consistent Markdown formatting, and date format YYYY-MM-DD. No information is ever lost.

**Build completed**: April 22–29, 2026 (10-step feature build)  
**Final commit**: `2e3aba3` (fix: guard htmlspecialchars null errors)  
**Repository**: `spacecabbie/torquepro-web` on GitHub  

---

## Recent Updates (Last 3 Changes)

### 2026-05-01 — Post-Build Refactor: Upload Processing Architecture & Reprocess Web UI
- Split `upload_data.php` into upload handling (`upload_data.php`) and parsing logic (`parser.php`).
- `parser.php` contains reusable `parseTorqueData()` function for improved separation of concerns.
- Converted `reprocess.php` from CLI-only to authenticated web page (`Auth::checkBrowser()` required).
- Added database reset function (`?resetdb`) that empties processed tables (sessions, sensors, sensor_readings, gps_points, upload_requests_processed) while keeping raw audit (`upload_requests_raw`).
- Added reprocess function (`?reprocess`) with preview (first 25 rows) and batched full processing.
- Throttled processing to prevent timeouts on large datasets; authenticated access only.
- **Next steps**: Integrate reset/reprocess buttons into dashboard UI or admin panel.

### 2026-05-01 — Dashboard Stabilization, CSS Centralization & Sensor/Parser Fixes
- Stabilized session selection in `dashboard.php` so changing sensors now preserves the current session and panel state.
- Updated dashboard JS builders to read live DOM panel configuration before rebuilding URLs.
- Removed embedded dashboard CSS from `dashboard.php` and centralized styles in `static/css/dashboard.css`.
- Added backend sensor label fallback support from `data/torque_keys.csv` for missing database `short_name` / `full_name` values in `ColumnRepository`, `SummaryRepository`, and `api/sensor.php`.
- Fixed parser metadata handling so `userShortName*` and `userFullName*` now update existing sensor labels when new values arrive.
- Added support for `defaultUnit*` fallback when `userUnit*` is absent.
- Fixed upload parser metadata handling so `userUnit*` is now parsed and sensor units are stored in `sensors.unit_id`.
- Extended `gps_points` insertion to include bearing, accuracy, and satellites from Torque kff GPS readings.
- Populated `sessions.duration_seconds` on every upload update.

### 2026-04-29 — Hotfix: Guard htmlspecialchars null errors
- Fixed TypeError in `dashboard.php` when selecting sessions (dropdown, summary table, panel options).
- Root cause: null values from `ColumnRepository::findPlottable()` or array access for sensor keys/labels.
- Applied `(string) ($var ?? '')` guard to all ~25 `htmlspecialchars()` calls.
- Testing: Session picker, summary table, and sensor dropdowns now render correctly.

---

**Note**: The full 10-step feature build (April 22–29, 2026), architecture details, technical stack, PSR compliance, security model, file structure, git history, testing checklist, SavedDashboardRepository implementation, api/dashboard_save.php, d.php resolver, and all other historical information have been moved to `history.md` to keep this progress log focused and concise.

**Last updated**: May 1, 2026 (reprocessed per maintenance instructions)
