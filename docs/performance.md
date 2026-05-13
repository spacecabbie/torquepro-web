# Performance Guide

The current workbench is designed for interactive analysis of real Torque sessions, including multi-panel charts and multi-sensor overlays.

---

## Expected Browser Load

Approximate browser memory use depends on the number of panels, sensors, and readings:

| Scenario | Approximate Memory |
|----------|--------------------|
| Single panel, single sensor | 2-4 MB |
| Single panel, 6-sensor overlay | 8-15 MB |
| Typical 3x3 dashboard | 80-150 MB |
| Large session with dense overlays | 200-400 MB |

Large sessions above 100,000 readings should be tested before production use. If the dashboard feels heavy, reduce the grid size, split sensors across fewer panels, or inspect a shorter time range.

---

## Chart Rendering

The dashboard uses uPlot for time-series charts. Long series are downsampled for rendering, while raw data remains available from the API. Large gaps in readings are drawn as breaks instead of connected lines.

Typical interactions should stay responsive:

- Panel add or reload: about 50-150 ms after data is fetched
- Overlay add: about 30-80 ms after data is fetched
- Cursor sync: designed for frame-rate-safe updates
- Zoom and pan: synchronized across visible charts

---

## Network Behavior

Each chart sensor is loaded from:

```text
api/sensor.php?sid=SESSION_ID&key=SENSOR_KEY
```

The endpoint returns compact JSON with label, unit, and `[timestamp_ms, value]` pairs. Responses are browser-cacheable for 60 seconds.

Overlay sensors are fetched in parallel. On browsers with lower connection limits, dashboards with many overlays may take longer to settle.

---

## Database Notes

Common dashboard queries read from `sensor_readings` by `session_id`, `sensor_key`, and timestamp order. For large databases, consider enabling the optional index in `schema_updates.sql`:

```sql
CREATE INDEX idx_sensor_session_ts ON sensor_readings (sensor_key, session_id, timestamp);
```

Apply this only after checking the write/read balance for your deployment.

---

## Monitoring

Watch for:

- Page loads above 2 seconds for normal sessions
- API 500 responses from `api/sensor.php`
- Browser memory rising after repeated grid changes
- PHP errors during upload parsing or reprocessing
- Very large `upload_requests_raw` growth over time

Useful database inspection query:

```sql
SELECT
    s.session_id,
    COUNT(DISTINCT sr.sensor_key) AS sensor_count,
    COUNT(*) AS reading_count,
    TIMESTAMPDIFF(SECOND, s.start_time, s.end_time) AS duration_seconds
FROM sessions s
LEFT JOIN sensor_readings sr ON sr.session_id = s.session_id
GROUP BY s.session_id
ORDER BY reading_count DESC
LIMIT 20;
```

---

## Known Limits

- Mixed-unit overlays are intentionally blocked or hidden.
- Very large sessions can still be expensive even with downsampling.
- Safari should be checked before broad production rollout.
- The sensor API has browser caching but no server-side cache by default.
