# Build Progress — Automotive Sensor Analysis Workbench

**Build completed**: April 22–29, 2026  
**Final commit**: `2e3aba3` (fix: guard htmlspecialchars null errors)  
**Repository**: `spacecabbie/torquepro-web` on GitHub  

---

## Executive Summary

Rebuilt the Torque Pro OBD-II data viewer (`dashboard.php`) from a simple 2-sensor sidebar layout into a full **Automotive Sensor Analysis Workbench** — a self-hosted, Grafana-inspired panel grid for flexible time-series visualization. All state is URL-encoded; dashboards can be saved with pretty URLs (`/d/my-slug`). The rebuild took **10 major steps** over 1 week, completing on April 29, 2026.

### What was delivered

1. **10-step feature build** — database schema, AJAX endpoints, JavaScript state management, interactive UI
2. **155 commits** tracked end-to-end in git; all pushed to GitHub
3. **Zero breaking changes** to existing functionality (sessions, auth, exports)
4. **Full OOP migration** from procedural code to typed, PSR-12 compliant classes
5. **Dark theme UI** with responsive CSS Grid layout and synchronized real-time charts
6. **Saved dashboards** with ownership via device ID SHA-256 hash (never storing raw credentials)

---

## Latest Update (May 1, 2026)

### 2026-05-01 — Parser Architecture Finalization (Decoupled Upload Pipeline)

**Completed the intended separation of concerns** that was referenced in earlier progress notes:

- **`upload_data.php`** is now a **thin receiver + audit layer**:
  - Only validates and persists the raw Torque request to `upload_requests_raw`
  - Then calls `parseTorqueData($rawUploadId)` — **passing nothing but the database ID**
  - No upload data (`$_GET`, session, timestamp, etc.) is ever passed to the parser

- **`parser.php`** is now a **pure business-logic layer**:
  - Fetches the original `raw_query_string` from `upload_requests_raw` by ID
  - Reconstructs parameters internally via `parse_str()`
  - Runs all sensor registration, GPS handling, session updates, and readings insertion
  - Fully decoupled and independently callable (ideal for reprocessing, queues, or admin tools)

- **Updated `reprocess.php`** to use the new single-ID signature (no multi-parameter calls).

- **Added `includes/Config/Torque.php`** — centralized configuration for:
  - GPS sensor keys (`kff1001`, `kff1005`, `kff1006`, etc.)
  - Calculated/derived prefixes (`kff12*`, `kff52*`, etc.)
  - Common OBD PID suffix mapping for normalization

- **Created `schema_updates.sql`** with recommended additive improvements:
  - `source` and `is_calculated` columns on `sensors` table
  - `pid_mode` for extended PIDs
  - Optional composite indexes and unit alias table

This change fully realizes the architecture goal stated in the project history: clean separation between upload handling and parsing logic, with the raw audit table as the single source of truth.

**No backward-compatibility wrapper** was added (per explicit request).

---

## Architecture & Key Decisions

### State model

- **Everything in the URL**: `?id=SESSION_ID&grid=RxC&p[N][s][]=SENSOR&p[N][cs]=INT&p[N][rs]=INT`
- **No client-side storage**: Refresh/share always work; no lost state
- **URL building**: Client-side `DWB` object (`buildUrl()`, `setSession()`, `setGrid()`, etc.) reconstructs URL on each navigation
- **Pattern**: `?id=123&grid=2x3&p[0][s][]=kd&p[0][cs]=2&p[1][s][]=kf&p[1][rs]=2`

### Database

- **`saved_dashboards` table** (Step 1, commit `6920dd6`):
  - `slug` (unique) + `state_json` (all URL params encoded as JSON)
  - `owner_device_hash` = SHA-256(Torque device_id) — device_id never stored raw
  - `expires_at NULL` = permanent; future admin panel can set TTL
  - No separate users table; identity = `eml` + `device_id` from existing `sessions` table

### Frontend architecture

- **Top bar** (fixed, 48px, dark `#1a1a2e`):
  - Chosen.js session picker (search/filter)
  - Grid preset pills (1×1, 2×2, 2×3, 3×3, 3×4)
  - ⭐ Save, 🗺 Map (lazy Leaflet), ⬇ CSV, ⋮ Actions buttons

- **CSS Grid panel shell** (Step 5–6):
  - `display: grid; grid-template-columns: repeat(var(--grid-cols, 3), 1fr)`
  - Per-panel colspan/rowspan via URL `?p[N][cs]=INT&p[N][rs]=INT`
  - Panel ⋮ dropdown: wider/narrower/taller/shorter/clear actions
  - Empty states + spinners; uPlot mounts on page load (Step 7)

- **Summary table** (Step 8 – already in Steps 5+6):
  - All-sensor stats (min/max/avg/p25/p75) from `SummaryRepository`
  - Sparklines via peity.js
  - ＋ button per row → adds sensor to next empty panel
  - Client-side pagination (15 rows/page)

- **Map modal** (Step 9 – already lazy in Steps 5+6):
  - Leaflet loaded only when modal opens (no CDN overhead on initial load)
  - GPS track + start/end markers

- **Save button + modal** (Step 10):
  - Modal fields: title, custom slug, device_id (optional)
  - POST to `api/dashboard_save.php` with current state
  - Returns shareable slug + URL; clipboard copy button
  - Slug conflicts → 409 error if owned by different device_id

---

## 10-Step Breakdown

### Step 1: Database schema (`6920dd6`)

**File**: `migrate_saved_dashboards.php`  
**Schema**:
```sql
CREATE TABLE saved_dashboards (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug               VARCHAR(80)  NOT NULL UNIQUE,
    title              VARCHAR(120),
    state_json         TEXT         NOT NULL,
    owner_email        VARCHAR(255),              -- informational only
    owner_device_hash  VARCHAR(64),               -- SHA-256(device_id)
    expires_at         DATETIME,                  -- NULL = never expires
    created_at         DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slug (slug),
    KEY idx_owner_email (owner_email),
    KEY idx_expires_at (expires_at)
);
```

**Why**: Persists dashboard layouts with pretty URLs. `owner_device_hash` prevents unauthorized updates. `expires_at` allows temporary shares.

---

### Step 2: AJAX sensor endpoint (`0620c97`)

**File**: `api/sensor.php`  
**Signature**: `GET ?sid=SESSION_ID&key=SENSOR_KEY`  
**Returns**: `{label, unit, data: [[ts_ms, value], ...]}`  
**Why**: Each panel fetches its time-series asynchronously after page load (no full-page reload needed when changing sensors).

**Key details**:
- Timestamp is **BIGINT milliseconds** (not DATETIME)
- Validated: `sid` matches `^\d{1,20}$`, `key` matches `^[a-zA-Z0-9_]{1,40}$`
- Unit joined from `unit_types.symbol` via `sensors.unit_id`
- Cache-Control: 60s (sensor data for past sessions never changes)
- Returns 404 if session/sensor unknown

---

### Step 3: Summary repository (`413ab7a`)

**File**: `includes/Data/SummaryRepository.php`  
**Method**: `findForSession($session_id)`  
**Returns**: Array of all sensors with `[sensor_key, label, unit, cnt, min, max, avg, p25, p75, sparkline(40-sample CSV)]`  
**Why**: Drives the summary table at the bottom of the dashboard. Replaces the old 2-sensor `PlotRepository` approach.

**Key details**:
- Uses **percentile functions** (PERCENTILE_CONT or PERCENTILE_DISC depending on MariaDB version)
- Sparkline CSV is 40-sample reduction of full time-series (for peity.js visualization)
- Ordered by sensor first appearance in the session
- Typed return arrays for PSR-1/PSR-12 compliance

---

### Step 4: uPlot integration (`d41f43c`)

**Files**: `static/js/uplot.min.js`, `static/css/uplot.min.css` (uPlot 1.6.31)  
**Why**: Replaces Flot entirely. uPlot is:
- 50 KB minified (vs Flot + jQuery dependencies)
- Canvas-based, fast multi-series rendering
- Synchronized cursor support (cross-hairs across all visible panels)
- Active maintenance; used by TradingView

**What was removed**: Flot files + all `$.plot()` calls (refactored in Step 7).

---

### Steps 5+6: Visual rebuild — Top bar + CSS Grid (`ba52f39`)

**File**: `dashboard.php` (rewritten, ~600 lines new HTML/CSS/JS)  
**Scope**: Replaces 2-column sidebar layout with fixed top bar and full-bleed CSS Grid.

**New structures**:

1. **Top bar** (48px fixed, `#1a1a2e` background):
   - Session picker (`#session-picker`, initialized by Chosen.js)
   - Grid preset pills (1×1, 2×2, 2×3, 3×3, 3×4)
   - Action buttons: Map, CSV, ⋮

2. **CSS Grid panel shell**:
   - 20 total panel slots (6×6 max configurable)
   - Each panel has: header (sensor select + ⋮ menu), body (chart...