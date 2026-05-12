# Dashboard Sync Workbench Implementation Plan

## Purpose

Turn `dashboard.php` from a collection of independent sensor charts into a diagnostic workbench where multiple sensors can be inspected together on a shared timeline.

The goal is to let a user select several sensors, enable synchronization, and accurately compare what happened in the car at the same second or millisecond across RPM, speed, boost, temperature, throttle, GPS, voltage, and other readings.

This document is intentionally detailed so another engineer or AI agent can continue the work after a disconnect.

## Current Baseline

### Application

- PHP application with browser auth handled by `TorqueLogs\Auth\Auth`.
- Database access goes through `TorqueLogs\Database\Connection`.
- Main UI is `dashboard.php`.
- Sensor time-series data is served by `api/sensor.php`.
- Dashboard state is URL-driven:
  - `id=SESSION_ID`
  - `grid=RxC`
  - `p[N][s][]=SENSOR`
  - `p[N][cs]=INT`
  - `p[N][rs]=INT`

### Charting

- The dashboard uses `uPlot` from `static/js/uplot.min.js`.
- Each panel currently renders one sensor.
- `api/sensor.php` returns:

```json
{
  "label": "Speed [km/h]",
  "unit": "km/h",
  "data": [[timestamp_ms, value]]
}
```

- `dashboard.php` already contains:

```js
const cursorSync = uPlot.sync('dwb');
```

That means uPlot cursor synchronization is already partially wired, but it is always implicit and has no user-facing controls or shared inspector.

### Recent Dashboard Cleanup

Recent uncommitted work before this implementation plan:

- `dashboard.php`
  - `DB` button made always visible.
  - sensor picker changed to use session-scoped sensors.
  - sensor picker displays units and reading counts.
- `includes/Data/ColumnRepository.php`
  - added `findForSession()`.
  - joined unit metadata from `unit_types`.
- `static/css/dashboard.css`
  - fixed Chosen selected text color.
- `adminer.php` and `includes/Vendor/adminer-standalone.php`
  - read-only Adminer wrapper and downloaded upstream Adminer.

Do not overwrite or revert these changes unless explicitly asked.

## Product Goal

When a user selects a session and populates multiple panels, they should be able to:

- enable or disable synchronized chart inspection
- hover one chart and see the same timestamp across all charts
- see a shared inspector with nearest values for all visible sensors
- zoom into a time window and apply that same time window to every chart
- pan and reset time range
- pin an exact timestamp marker
- optionally overlay related sensors in one panel
- see unit-aware labels, values, and thresholds
- avoid misleading connections over large data gaps
- handle long sessions without a sluggish browser

## Feature List

### 1. Sync Cursor Toggle

Add a top-bar toggle, likely near grid presets:

- `Sync Off`
- `Sync Cursor`

When enabled:

- uPlot cursor movement in one panel drives the cursor in all visible panel charts.
- The user can compare all sensors at the same timestamp.

Current uPlot sync support should be reused.

### 2. Shared Value Inspector

Add a compact inspector band, probably below the top bar or above the grid.

It should show:

- current cursor timestamp
- one row/chip per visible sensor
- sensor label
- nearest value
- unit
- time delta from cursor if the reading is not exact

Example:

```text
14:03:22.418
RPM: 2184 rpm
Boost: 1.12 bar
Speed: 63 km/h
Coolant: 87 °C
Throttle: 42 %
```

Nearest-point matching is required because Torque uploads may not align every sensor on identical timestamps.

Recommended tolerance:

- default `1500 ms`
- visually mark stale values when delta is over `750 ms`
- show `no nearby value` when no reading is within tolerance

### 3. Sync Zoom / Shared Time Range

When enabled, zooming one chart updates the x-scale of all other visible charts.

Important behavior:

- all charts keep their own y-scale
- only x/time scale is shared
- reset returns all charts to their full data extent or selected preset

Avoid syncing y-axis across different units.

### 4. Wheel Zoom

Add optional wheel zoom over a chart:

- wheel up/down zooms in/out around cursor time
- hold `Shift` or use horizontal wheel to pan if practical
- prevent page scroll only when pointer is over a chart and the feature is enabled

This should be implemented as a uPlot plugin or contained helper, not scattered listeners.

### 5. Drag Pan

After zooming, drag the plot area to pan left/right.

Recommended behavior:

- left mouse drag pans when not selecting
- `Alt`/`Space` modifier can be considered if drag conflicts with uPlot selection zoom
- keep panning bounded to full data extent

### 6. Double-Click Reset

Double-click a chart to reset all synchronized charts to the current full/session range.

If a time preset is active, double-click can reset to full range and clear the preset.

### 7. Hover Legend Values

Each panel header should show the active value while hovering:

```text
Speed [km/h]  63.4
```

This is secondary to the shared inspector, but useful when the user focuses on one panel.

### 8. Min/Max/Reference Bands

Support horizontal reference lines or shaded bands.

Examples:

- coolant warning threshold
- battery voltage low/high range
- boost target range
- AFR lean/rich band
- throttle percent 0-100

Implementation should be data-driven and optional. Do not hardcode many car-specific thresholds into chart rendering.

Suggested shape:

```js
const SENSOR_THRESHOLDS = {
  k5: [{ label: 'Hot', y: 105, color: '#f85149' }],
  k42: [
    { label: 'Low voltage', yMin: 0, yMax: 12.0, color: 'rgba(248,81,73,.10)' }
  ]
};
```

Longer term, thresholds could live in config or DB metadata, but first pass can use a small JS map.

### 9. Downsampling

uPlot is fast, but very long sessions can still be heavy if every panel receives every point.

Implementation options:

1. Frontend downsampling after fetch.
2. API-level downsampling with a query parameter like `?max_points=1500`.
3. Database aggregation by pixel/time bucket.

Recommended first step:

- frontend Largest-Triangle-Three-Buckets or min/max bucket downsampling only for rendering
- preserve raw arrays for nearest-value inspector if memory allows

Recommended later step:

- add `max_points` support to `api/sensor.php`
- keep exact data available for export

### 10. Null Gaps

Do not draw continuous lines across large time gaps.

Approach:

- detect gaps between adjacent readings over a threshold
- insert `null` y-values or split series
- default threshold could be `max(3000 ms, 3x median interval)`

This helps reveal logger pauses, sensor dropout, app backgrounding, or GPS loss.

### 11. Time Range Presets

Add quick buttons:

- `Full`
- `30s`
- `1m`
- `5m`
- `10m`
- `Around Cursor`

Behavior:

- `Full`: entire session range for visible charts.
- Fixed durations: if cursor exists, center on cursor; otherwise use session start or current zoom center.
- `Around Cursor`: probably a menu or repeated click using selected duration.

### 12. Pinned Timestamp Marker

Click a chart to pin a timestamp.

When pinned:

- all charts show a vertical marker
- inspector locks to pinned time
- moving cursor may show live hover separately, or live hover is disabled until unpin
- user can clear marker with a button or `Esc`

### 13. Multi-Sensor Overlay Panels

The URL state already uses `p[N][s][]`, but current rendering uses only the first sensor.

Add true multi-sensor support per panel.

Recommended constraints:

- first pass: allow multiple sensors only when units match
- second pass: support multiple y-axes or normalized view
- show a legend with one color per sensor
- inspector should list all overlayed sensors individually

Good overlay use cases:

- OBD speed + GPS speed
- boost target + boost actual
- accelerator pedal + throttle position
- coolant temp + intake temp
- voltage sensors

Avoid overlaying unrelated units by default, e.g. RPM and °C, unless normalized mode exists.

### 14. Panel Modes

Each panel could support modes:

- `Chart`
- `Stats`
- `Raw`

This is useful but lower priority than sync and inspection.

Possible URL state:

```text
p[N][mode]=chart|stats|raw
```

## Proposed Implementation Order

### Step 0: Documentation Foundation

Create:

- `IMPLEMENT.md`
- `IMPLEMENT_STATUS.md`

No runtime behavior changes in this step unless already completed earlier.

### Step 1: Chart State Refactor

Before adding features, make chart state easier to maintain.

Create a small client-side chart registry:

```js
const DWBCharts = {
  charts: new Map(),
  series: new Map(),
  fullRange: null,
  syncEnabled: false,
  pinnedTime: null
};
```

Track for each panel:

- panel index
- sensor keys
- labels
- units
- raw data pairs
- uPlot instance
- x min/max

Keep this inside `dashboard.php` at first. Extract later only if the script becomes too large.

### Step 2: Sync Cursor Toggle

Add top-bar toggle.

Implementation:

- default can be on or off; recommended default: on once user has 2+ plotted panels.
- store in URL only if needed; initially local runtime state is fine.
- use uPlot sync key only when sync is enabled, or ignore sync events when disabled.

Acceptance:

- moving cursor in one panel shows cursor in all visible panels
- disabling sync stops cross-panel cursor movement

### Step 3: Shared Inspector

Add inspector container:

```html
<div id="sync-inspector" hidden></div>
```

Implementation:

- update on uPlot cursor movement
- use nearest value for each visible sensor
- format timestamp with milliseconds where useful
- format value based on sensor unit

Acceptance:

- hover any chart and inspector updates
- values show units
- missing/stale values are clear

### Step 4: Sync Zoom and Reset

Use uPlot `setScale('x', { min, max })`.

Guard against recursive scale updates:

```js
let applyingSyncRange = false;
```

Acceptance:

- selecting/zooming one chart updates all others
- double-click resets all charts
- y-axes remain independent

### Step 5: Wheel Zoom and Drag Pan

Add contained uPlot plugin/helper.

Acceptance:

- wheel zoom works over chart area
- panning works after zoom
- interaction does not break normal page scroll outside chart

### Step 6: Pinned Timestamp

Add click-to-pin marker and clear action.

Acceptance:

- click pins timestamp across all charts
- inspector locks to pinned timestamp
- clear returns inspector to hover mode

### Step 7: Null Gaps

Transform render data to include gaps.

Keep raw data separate:

- raw for inspector
- render arrays for uPlot

Acceptance:

- large telemetry gaps are visible
- inspector still finds nearest raw value

### Step 8: Downsampling

Start frontend-only.

Acceptance:

- long sessions remain responsive
- inspector still has acceptable precision

### Step 9: Time Range Presets

Add compact top-bar controls.

Acceptance:

- full/reset works
- fixed windows can center around cursor/pinned time

### Step 10: Reference Lines/Bands

Add lightweight config and uPlot draw hook.

Acceptance:

- threshold bands render without obscuring data
- thresholds are unit-aware enough not to mislead

### Step 11: Multi-Sensor Overlay

Extend panel selector UI and URL handling.

Acceptance:

- one panel can plot multiple compatible sensors
- URL round-trips the selection
- inspector includes each overlay series

### Step 12: Panel Modes

Add modes only after chart sync features are stable.

## Client-Side Design Notes

### Data Structures

Recommended per sensor:

```js
{
  key: 'kd',
  label: 'Speed [km/h]',
  unit: 'km/h',
  rawPairs: [[tsMs, value]],
  xSeconds: Float64Array,
  yValues: Float64Array,
  renderData: [Float64Array, Array<number|null>]
}
```

Recommended per chart:

```js
{
  panelIdx: 0,
  sensors: [sensorState],
  uplot: u,
  fullRange: { min: 1770000000, max: 1770000600 }
}
```

### Nearest Value Lookup

Use binary search by timestamp.

Do not scan arrays linearly on every cursor move.

Pseudo-code:

```js
function nearestPair(rawPairs, targetMs, toleranceMs) {
  const idx = lowerBound(rawPairs, targetMs);
  const before = rawPairs[idx - 1];
  const after = rawPairs[idx];
  const nearest = closer(before, after, targetMs);
  return Math.abs(nearest[0] - targetMs) <= toleranceMs ? nearest : null;
}
```

### Formatting Values

Use units:

- `%`: 1 decimal
- `rpm`: 0 decimals
- `km/h`, `mph`: 1 decimal
- `°C`, `°F`: 1 decimal
- pressure: 2 decimals for `bar`, 1 decimal for `psi`, 0-1 for `kPa`
- default: adaptive precision

### URL State

Do not add every transient interaction to the URL.

Good URL candidates:

- grid
- sensors per panel
- panel spans
- panel mode
- maybe persistent time range if user explicitly saves dashboard

Not necessary in URL initially:

- live cursor position
- pinned timestamp
- sync toggle
- hover inspector state

### Accessibility

- Buttons must have visible text or clear `title`/`aria-label`.
- Sync state should be reflected in button text and `aria-pressed`.
- Inspector should be readable without relying only on color.
- Keyboard reset/clear marker should be available if practical.

### Performance

- Avoid rebuilding all uPlot instances on hover.
- Throttle inspector updates with `requestAnimationFrame`.
- Keep chart registry stable.
- Avoid `innerHTML` for frequently updated large blocks where possible.
- Use event hooks from uPlot instead of global mousemove listeners.

## Server/API Notes

### Existing API

`api/sensor.php` is sufficient for initial work.

Potential later API additions:

- `max_points`
- `from`
- `to`
- `gap_threshold`
- `multi[]=sensor_key`

Do not change API shape until frontend needs are clear.

### Parser

Do not touch `parser.php` for sync work unless evidence shows data is missing from `sensor_readings`.

The earlier "empty sensors" issue was caused by the dashboard listing global sensors, not a proven parser problem.

## Testing Plan

### Static Checks

Run after every step:

```bash
php -l dashboard.php
php -l api/sensor.php
php -l includes/Data/ColumnRepository.php
git diff --check
```

Run full PHP lint before final handoff:

```bash
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
```

### Browser Checks

Manual browser checks:

- dashboard loads with no session
- DB button visible without session
- session selector readable
- select session
- sensor picker shows only session sensors
- unit and reading count visible
- add 2-6 sensors to panels
- sync cursor toggle works
- inspector values match chart hover
- zoom one chart and verify other charts follow
- reset works
- map modal still opens when GPS exists
- save dashboard still works
- export CSV still works

### Data Checks

If DB is available:

```sql
SELECT session_id, COUNT(*) readings, COUNT(DISTINCT sensor_key) sensors
FROM sensor_readings
GROUP BY session_id
ORDER BY MAX(timestamp) DESC
LIMIT 10;
```

For one session:

```sql
SELECT r.sensor_key, COALESCE(s.short_name, r.sensor_key) label, u.symbol unit,
       COUNT(*) readings, COUNT(DISTINCT r.value) distinct_values
FROM sensor_readings r
LEFT JOIN sensors s ON s.sensor_key = r.sensor_key
LEFT JOIN unit_types u ON u.id = s.unit_id
WHERE r.session_id = ?
GROUP BY r.sensor_key, s.short_name, u.symbol
ORDER BY readings DESC;
```

### Visual Regression Risks

- Top bar has limited width.
- Adding more controls may overflow on mobile.
- Inspector must not hide charts.
- uPlot canvas resizing must still work after adding inspector.
- Bootstrap 3 is bundled, not Bootstrap 5.

## Best Practices For This Codebase

- Keep edits scoped.
- Preserve URL-driven dashboard state.
- Use existing PHP repositories where possible.
- Avoid adding dependencies unless uPlot cannot support the feature.
- Prefer small JS helpers over a large rewrite.
- Do not move Adminer outside `includes/Vendor`.
- Do not expose raw DB credentials in UI.
- Do not weaken `Auth::checkBrowser()`.
- Do not add destructive database actions to Adminer wrapper.
- Keep frontend controls dense and utilitarian.

## Open Questions

- Should sync be enabled by default once multiple panels have charts?
- Should pinned timestamp be saved in dashboard slug state?
- Should multi-sensor overlays be limited to same-unit sensors in v1?
- Should threshold bands be global, per-dashboard, or per-device?
- Should downsampling happen client-side first or API-side immediately?

Recommended answers for first implementation:

- Sync default: on for 2+ charts.
- Pinned timestamp: runtime only.
- Overlays: same-unit only in v1.
- Thresholds: small frontend config first.
- Downsampling: client-side first, API later if needed.
