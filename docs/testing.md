# Testing Guide

Use this checklist before staging or production deployment, especially after dashboard or parser changes.

---

## Basic Smoke Test

- Open `dashboard.php` and sign in.
- Select a recent session.
- Confirm the session summary table appears.
- Add a sensor to a panel and verify the chart renders.
- Export the session with the CSV button.
- Open the map for a session with GPS points.

---

## Dashboard Overlay Checks

1. Add a sensor to a panel.
2. Select a compatible second sensor and press `+`.
3. Confirm both series render with separate colors.
4. Add sensors until the panel reaches the 6-sensor limit.
5. Confirm a 7th sensor is blocked.
6. Remove one overlay with its chip and confirm the chart reloads cleanly.

---

## Mixed-Unit Checks

- Try to overlay incompatible units, such as RPM and speed.
- Confirm the UI blocks the add action.
- If testing a manually edited URL, confirm the incompatible series is hidden and a warning is shown.

---

## Inspector And Time Range

- Move the cursor across one chart and verify all visible sensor values update in the shared inspector.
- Click a chart to pin the inspector.
- Use **Clear pin** and confirm live inspection resumes.
- Use **1m**, **5m**, **10m**, and **Full** and confirm all charts follow the selected range.
- Double-click a chart and confirm the full range returns.

---

## Saved Dashboard Links

- Save a dashboard with an auto-generated slug.
- Open the returned `d.php?s=...` link.
- Confirm it redirects to the same session, grid, panel spans, and overlays.
- Save again with a custom slug.
- If a device ID is used, confirm the same device ID can update that slug.

---

## Parser And Upload Checks

- Configure Torque Pro to upload to `upload_data.php`.
- Trigger a test upload from Torque.
- Confirm a row appears in `upload_requests_raw`.
- Confirm processed data appears in `sessions`, `sensors`, `sensor_readings`, and `gps_points` where applicable.
- Use `reprocess.php?dry=1` to preview pending reprocessing without writing changes.

---

## Recommended Pre-Production Checks

- Run PHP syntax checks for changed PHP files.
- Test with at least one large session with more than 50,000 readings.
- Test Chrome, Firefox, Safari, and mobile/tablet widths if users rely on them.
- Watch the browser console for JavaScript errors.
- Check PHP error logs after uploads and dashboard loads.
