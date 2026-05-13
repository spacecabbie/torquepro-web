# Dashboard Workbench

The Dashboard Workbench is the main user interface for exploring imported Torque sessions. Open `dashboard.php`, choose a session, and use the grid panels to compare sensors over time.

---

## Top Bar

The top bar contains the main workflow controls:

| Control | Purpose |
|---------|---------|
| Session picker | Choose a recorded session. Sessions with fewer than 2 data points are hidden. |
| Grid presets | Switch between layouts such as `1x1`, `2x2`, `2x3`, `3x3`, and `3x4`. |
| Sync On | Toggle synchronized cursor inspection across charts. |
| Full / 1m / 5m / 10m | Reset or apply global time ranges across all visible panels. |
| DB | Open the local Adminer database tool. |
| Map | Show the GPS route for sessions with GPS points. |
| Save | Save the current layout and get a shareable link. |
| CSV | Export the selected session as CSV. |
| Session actions | Delete the session or merge it with the previous session. |

---

## Panels

Each panel can display one or more sensors:

1. Select a sensor from the panel dropdown.
2. Use the `+` button to add the selected sensor as an overlay.
3. Use the sensor chips below the header to remove individual overlays.
4. Use the panel menu to make a panel wider, narrower, taller, shorter, or clear it.

Panel state is stored in the URL. A layout can be reopened or shared with parameters like:

```text
dashboard.php?id=123&grid=2x3&p[0][s][]=kd&p[0][s][]=kf&p[0][cs]=2
```

---

## Multi-Sensor Overlays

Panels can show up to **6 sensors** at once. Each overlay is drawn as a separate uPlot series with its own color.

To prevent misleading charts, overlays are limited to compatible units. For example, speed sensors can be overlaid with other speed sensors, but RPM and speed should use separate panels. If a saved or hand-edited URL contains incompatible sensors, the chart loads the compatible series and shows a warning for the hidden mixed-unit sensors.

Partial failures are handled gracefully. If one overlay sensor has no data, the rest of the panel can still render.

---

## Synchronized Inspector

When sync is enabled, moving across a chart updates the shared inspector above the grid. The inspector shows the nearest value for every visible sensor at the cursor timestamp.

Click a chart to pin the inspector at a timestamp. Use **Clear pin** to return to live cursor inspection.

Double-click a chart to reset the shared time range.

---

## Summary Table

The session summary table lists all sensors that have readings in the selected session. It includes:

- Sensor label and unit
- Sample count
- Minimum, maximum, and average values
- P25 and P75 percentile estimates
- Trend sparkline

Use the `+` button beside a sensor to add it to the next empty panel.

---

## Saved Dashboards

Use **Save** to store the current session, grid, panel spans, and selected sensors. A custom slug is optional; if omitted, the app generates one.

Saved dashboards open through:

```text
d.php?s=your-slug
```

The resolver redirects to `dashboard.php` with the equivalent state in the query string. If you provide a Torque device ID while saving, the raw device ID is not stored; it is hashed and used only to allow the same device to update the same slug later.

---

## GPS Map

When a session has GPS points, the **Map** button opens a route view with:

- OpenStreetMap tiles loaded through Leaflet
- A route polyline
- Start and end markers
- Automatic bounds fitting

Leaflet is loaded lazily only when the map modal opens.
