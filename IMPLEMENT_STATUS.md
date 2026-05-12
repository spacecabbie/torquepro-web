# Dashboard Sync Workbench Status

This file is the live handoff tracker for the dashboard sync workbench implementation. Update it after every meaningful change so another AI or engineer can continue safely after a disconnect.

## Current Status

Step 11 multi-sensor overlay panels completed and review fixes applied. Panels can carry up to six URL-backed sensors, show active removable sensor chips, render multiple joined uPlot series in one panel, and register every visible compatible sensor with the shared inspector. Overlay adding now works from populated panels, mixed-unit overlays are blocked/hidden, partial load failures are surfaced, chart remount listeners are cleaned up, and inspector time offsets are clearly separated from sensor units.

## Last Updated

2026-05-13, after Step 11 review fixes

## Branch / Git State At Start Of This Plan

- Branch: `main`
- Remote tracking: `main...origin/main`
- Last synced commit before these docs: `92842d9`
- Existing uncommitted changes were present before `IMPLEMENT.md` and this status file were added.

Existing modified/untracked files before implementation docs:

- `dashboard.php`
- `includes/Data/ColumnRepository.php`
- `static/css/dashboard.css`
- `adminer.php`
- `includes/Vendor/adminer-standalone.php`

Important: these changes are expected and should not be reverted casually. They include:

- read-only Adminer integration
- always-visible dashboard DB button
- session picker color fix
- session-scoped sensor picker
- unit and reading-count display in sensor picker

## Completed

- [x] Step 0: Create extensive implementation blueprint in `IMPLEMENT.md`.
- [x] Step 0: Create handoff/status tracker in `IMPLEMENT_STATUS.md`.
- [x] Step 1: Add client-side chart registry in `dashboard.php`.
- [x] Step 2: Add top-bar sync cursor toggle.
- [x] Step 3: Add shared value inspector with nearest-reading lookup.
- [x] Step 4: Add synchronized x-axis range and reset.
- [x] Step 5: Add wheel zoom and drag pan.
- [x] Step 6: Add pinned timestamp marker and clear controls.
- [x] Step 7: Add null gap rendering.
- [x] Step 8: Add render downsampling for large sensor arrays.
- [x] Step 9: Add synchronized time range presets.
- [x] Step 10: Add conservative reference line overlays.
- [x] Step 11: Add multi-sensor overlay panels.

## Not Started

- [ ] Step 12: Panel modes.

## Current Recommended Next Action

Implement Step 12 only:

Add panel modes.

Recommended deliverables for Step 12:

- Add explicit panel modes for single, overlay, and future comparison behavior.
- Keep the default mode compatible with the current multi-sensor overlay behavior.
- Consider a normalized mode for mixed units before adding multiple y-axes.
- Preserve existing sync, inspector, pinned timestamp, range sync, wheel zoom, pan, gap, downsampling, time preset, reference overlay, and multi-sensor behavior.
- Update this status file after Step 12.

## Files Relevant To Step 12

- `dashboard.php`
  - top bar controls are near `#grid-presets` and `.topbar-right`.
  - uPlot block starts around the `/* ── uPlot panel charts` comment.
  - `window.DWBCharts` now exposes `registerPanel`, `getVisibleSensors`, `getChart`, `getPanelStates`, `replaceChart`, and `resizePanel`.
  - sync toggle functions currently include `setSyncCursorEnabled()`, `remountChartsForSyncState()`, and `initSyncToggle()`.
  - inspector functions include `scheduleInspectorUpdate()`, `renderInspector()`, `nearestPair()`, and value/time formatters.
  - range functions include `syncTimeRange()`, `resetTimeRange()`, `isUsefulRange()`, and `initRangeReset()`.
  - range interaction functions include `attachRangeInteractions()`, `currentChartRange()`, and `boundedRange()`.
  - pinned inspector functions include `pinInspector()`, `clearPinnedInspector()`, and `initPinnedInspectorControls()`.
  - gap rendering functions include `pairsToGapAwareUplot()` and `calculateGapThresholdMs()`.
  - downsampling functions include `downsamplePairsForRender()`.
  - time preset functions include `applyTimePreset()`, `setActiveRangePreset()`, and `initRangePresets()`.
  - reference overlay functions include `buildReferencePluginsForSensors()`, `referenceRulesForSensor()`, `normalizeUnit()`, and `referenceOverlayPlugin()`.
  - multi-sensor functions include `parseChartKeys()`, `fetchSensorSeries()`, `buildPanelPlotData()`, and `chartMetaForSensors()`.
- `api/sensor.php`
  - already returns `label`, `unit`, and `data`.
- `static/css/dashboard.css`
  - may need styles for the sync button and later inspector.

## Known Constraints

- Bootstrap bundled in this repo is Bootstrap 3, not Bootstrap 5.
- Dashboard currently has inline JavaScript in `dashboard.php`.
- uPlot uses Unix seconds on x-axis; API returns milliseconds.
- Some sensor timestamps may not align exactly.
- Do not sync y-axis across unrelated units.
- Do not modify `parser.php` unless DB evidence proves ingestion is wrong.
- CLI DB connection failed during the previous check with `Database connection failed`; do not assume CLI can access production DB.

## Verification Commands

Use these after every PHP change:

```bash
php -l dashboard.php
php -l api/sensor.php
php -l includes/Data/ColumnRepository.php
git diff --check
```

Before final handoff, run:

```bash
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
git status --short --branch
git diff --check
```

## Manual Browser Verification Checklist

Run after the first runtime feature is implemented:

- [ ] Dashboard loads without a selected session.
- [ ] `DB` button is visible without a selected session.
- [ ] Session selector selected text is readable.
- [ ] Selecting a session populates session-specific sensors.
- [ ] Sensor picker shows units and reading counts.
- [ ] Existing one-sensor chart panels still load.
- [ ] Existing summary table still renders.
- [ ] CSV export still links correctly.
- [ ] Save dashboard modal still opens.
- [ ] Map modal still opens when GPS points exist.

Run after sync features start:

- [ ] Populate at least two panels with different sensors.
- [ ] Cursor sync toggle controls cross-chart cursor movement.
- [ ] Inspector shows timestamp, labels, values, and units.
- [ ] Missing nearest values are clearly shown.
- [ ] Zoom one chart and verify all x-ranges follow.
- [ ] Double-click reset works.

## Implementation Notes

### Current uPlot Runtime Shape

Existing code loads each `.panel-chart-area[data-keys]` or legacy `.panel-chart-area[data-key]` with:

1. Parse up to six sensor keys from the panel.
2. Fetch `api/sensor.php?sid=...&key=...` for each key.
3. Convert each `[[timestamp_ms, value]]` response to raw arrays, downsampled render pairs, and gap-aware uPlot arrays.
4. Join multi-series render arrays with `uPlot.join()`.
5. Mount a single- or multi-series uPlot chart.
6. Register chart instance and every visible sensor in `DWBCharts`.

Step 1 preserved this behavior while changing internal structure only.

### Implemented Step 1 Shape

```js
const DWBCharts = (() => {
  const panels = new Map();

  function registerPanel(panelIdx, sensorStates, uplot, container, plotData, chartMeta) {}
  function getVisibleSensors() {}
  function getChart(panelIdx) {}
  function resizePanel(panelIdx) {}

  return { registerPanel, getVisibleSensors, getChart, resizePanel };
})();
```

Notes:

- Registry is also assigned to `window.DWBCharts` for future feature work and browser debugging.
- Each registered panel can have one to six `sensorState` objects.
- `sensorState` includes `key`, `label`, `unit`, `rawPairs`, `renderPairs`, `xSeconds`, and `yValues`.
- `fullRange` is captured from the sensor's x-array.

### Implemented Step 2 Shape

- Top bar button id: `sync-cursor-toggle`.
- Button text toggles between `Sync On` and `Sync Off`.
- Button state uses `aria-pressed`.
- Sync is enabled by default for selected-session dashboards.
- Toggling remounts registered uPlot charts because uPlot cursor sync is configured during chart construction.
- URL state is not changed by sync toggling.

### Implemented Step 3 Shape

- Inspector container id: `sync-inspector`.
- Inspector lives at the top of `#dwb-canvas`.
- uPlot `setCursor` hook schedules inspector updates with `requestAnimationFrame`.
- `nearestPair()` uses binary search over `sensorState.rawPairs`.
- Default nearest-value tolerance: `1500 ms`.
- Values over `750 ms` from cursor are marked stale.
- Missing values within tolerance are shown as `no value`.
- Labels strip trailing `[unit]` for compact inspector display, while units are shown separately.

### Implemented Step 4 Shape

- Top bar reset button id: `range-reset`.
- Button text: `Full`.
- uPlot `setScale` hook syncs x/time range to every registered chart.
- Guard variable: `applyingSyncedRange`.
- Runtime synced range variable: `currentSyncedRange`.
- `resetTimeRange()` resets every chart to the combined full range across visible panels.
- Double-clicking a chart calls `resetTimeRange()`.
- y-axis scales remain independent.
- Sync toggle remount preserves `currentSyncedRange` when active.

### Implemented Step 5 Shape

- uPlot `ready` hook calls `attachRangeInteractions(u)`.
- Wheel over a chart zooms around pointer time.
- Mouse drag over a chart pans horizontally.
- Both wheel and pan call `syncTimeRange()` so all visible charts follow.
- Interactions are bounded to the combined full range from `DWBCharts.getFullRange()`.
- Chart overlay cursor changes to crosshair/grabbing via `.u-over` styles.

### Implemented Step 6 Shape

- Runtime pinned time variable: `pinnedTimeMs`.
- uPlot `click` hook pins inspector to cursor timestamp.
- While pinned, uPlot `setCursor` hook no longer updates inspector.
- Inspector gains `is-pinned` class and `Pinned` timestamp prefix.
- Clear control id: `pin-clear`.
- Clicking `Clear pin` or pressing `Escape` clears pinned mode.

### Implemented Step 7 Shape

- Minimum gap threshold: `MIN_GAP_THRESHOLD_MS = 3000`.
- Dynamic gap threshold: `max(3000 ms, median interval * 3)`.
- Raw arrays still come from `apiToUplot(json.data)` and are stored in `sensorState`.
- Gaps are rendered by inserting two null y-values around the missing interval.
- `panelState.plotData` now stores render data, not raw data.

### Implemented Step 8 Shape

- Render point cap: `MAX_RENDER_POINTS = 3000`.
- Render pairs come from `downsamplePairsForRender(json.data)`.
- Downsampling uses min/max buckets, not every-N sampling, so sharp local highs/lows are more likely to remain visible.
- Gap threshold is calculated from the raw data before downsampling and passed into `pairsToGapAwareUplot(renderPairs, rawGapThresholdMs)`.
- `sensorState.renderPairs` stores the downsampled render pairs for debugging and future render-aware features.
- Raw arrays and `sensorState.rawPairs` remain unchanged for nearest-value inspection and pinned timestamp lookup.

### Implemented Step 9 Shape

- Top-bar preset buttons: `Full`, `1m`, `5m`, `10m`.
- Preset buttons use `.range-preset`; minute buttons store seconds in `data-range-seconds`.
- `applyTimePreset(seconds)` ranges from the shared full max timestamp back by the requested seconds, bounded by the shared full min timestamp.
- Presets apply through `syncTimeRange()` so every visible chart uses the same x-axis range.
- Manual zoom/pan/range sync clears the active preset state; clicking a preset restores the active class for that preset.
- `Full` continues to call `resetTimeRange()` and marks the full preset active.

### Implemented Step 10 Shape

- Reference overlays are implemented as a contained uPlot plugin returned by `referenceOverlayPlugin()`.
- `buildReferencePluginsForSensors(sensors)` attaches the plugin only when `referenceRulesForSensor()` finds a matching rule.
- Current default rules are intentionally narrow:
  - fuel trim sensors with `%` units get a `0%` reference line.
  - boost/manifold/MAP/pressure sensors with `psi`, `bar`, or `kpa` units get a zero reference line.
- Reference lines redraw during zoom, pan, resize, and chart remounts because they use uPlot draw hooks.
- Additional thresholds should be added only when label/unit matching is specific enough to avoid misleading unrelated sensors.

### Implemented Step 11 Shape

- Panel URL state now uses all values in `p[N][s][]` instead of only the first value.
- `buildUrl()` writes up to six sensors per panel.
- `getCurrentPanelState()` reads sensors from each panel's `data-sensors` JSON.
- Panel headers now include a `+` button that appends the currently selected sensor to that panel.
- Selecting a sensor in an empty panel creates the panel immediately; selecting a sensor in a populated panel stages it for the `+` button.
- Active panel sensors render as removable chips below the header.
- `loadPanel()` fetches each panel sensor with `Promise.allSettled()` and renders any successful series.
- Partial load failures render a warning badge in the chart instead of failing silently.
- Mixed known units are blocked before adding when possible and hidden at render time if they arrive through URL/manual state.
- Multi-series chart data is built with `uPlot.join()` from each sensor's gap-aware render arrays.
- `DWBCharts.registerPanel()` now accepts an array of sensor states and computes full range across all series.
- Shared inspector sees every sensor in multi-sensor panels because `getVisibleSensors()` flattens panel sensor arrays.
- The grid preset click handler now targets `.grid-pill[data-preset]` so sync/range buttons do not accidentally call `DWB.setGrid()`.
- Chart remounts clean up wheel/pan window listeners through the uPlot destroy hook.
- Inspector values show the sensor unit next to the value; millisecond text is only shown as a stale-reading time offset.

## Risk Log

- `dashboard.php` is large and contains mixed PHP/HTML/JS; small edits are safer than broad rewrites.
- Adminer wrapper uses upstream Adminer internals; keep it separate from dashboard sync work.
- The current worktree has uncommitted unrelated-but-requested changes. Be careful when committing or reviewing diffs.
- Exact database verification may not be possible from CLI in this environment.

## Decision Log

- 2026-05-12: Keep uPlot. Do not introduce a new chart library.
- 2026-05-12: Implement in documented steps rather than one large feature burst.
- 2026-05-12: Sync x/time only; y-axis stays per chart.
- 2026-05-12: Use nearest-value matching for inspector because sensor timestamps may differ.
- 2026-05-12: Start with frontend changes; parser/database changes require evidence.
