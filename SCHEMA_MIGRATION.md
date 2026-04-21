# Normalized Schema Deployment — Complete

## Date: 2026-01-15

## Summary

The Torque Logs application has been successfully migrated from a **wide denormalized schema** (one table with dynamic k* columns) to a **fully normalized PSR-compliant schema** with proper sensor taxonomy, unit conversion support, and partitioned audit logging.

---

## Schema Changes

### Old Schema (removed)
- **`raw_logs`**: Single wide table with 100+ dynamic VARCHAR columns (k*, kff*, etc.)
- **`upload_requests`**: Audit log with JSON sensor data

### New Schema (deployed)

| Table | Rows | Purpose |
|-------|------|---------|
| **sensor_categories** | 10 | Taxonomy (pressure, temperature, speed, fuel, etc.) |
| **unit_types** | 19 | Unit definitions with SI conversion factors (bar↔PSI, °C↔°F) |
| **sensors** | 0 | Master registry of all k* sensors discovered from uploads |
| **sessions** | 0 | One row per session with metadata and upload count |
| **sensor_readings** | 0 | Narrow time-series table (session_id, timestamp, sensor_key, value) |
| **gps_points** | 0 | GPS track points (latitude, longitude) |
| **upload_requests_raw** | 0 | **Partitioned by date** for easy archival (raw query strings) |
| **upload_requests_processed** | 0 | Summary of processed uploads (sensor counts, errors) |

---

## Code Changes

### Files Completely Rewritten

#### `upload_data.php` (Torque upload endpoint)
- ❌ **Old**: Dynamic `ALTER TABLE` for every new sensor
- ✅ **New**: 
  - UPSERT into `sessions` table
  - Auto-register sensors in `sensors` table with names
  - INSERT into narrow `sensor_readings` table
  - Extract GPS → `gps_points` table
  - Full audit trail in `upload_requests_raw` (partitioned)
  - Transaction-based with proper rollback on error

### Repository Classes Updated

All repository classes now query the normalized schema:

| File | Old Behavior | New Behavior |
|------|-------------|--------------|
| **SessionRepository.php** | `GROUP BY session` on `raw_logs` | Query `sessions` table with pre-calculated duration |
| **ColumnRepository.php** | Query `INFORMATION_SCHEMA.COLUMNS` | Query `sensors` table |
| **PlotRepository.php** | `SELECT k*, time FROM raw_logs` | PIVOT-like query on `sensor_readings` |
| **GpsRepository.php** | `SELECT kff1005, kff1006 FROM raw_logs` | `SELECT latitude, longitude FROM gps_points` |
| **SessionManager.php** | `DELETE FROM raw_logs WHERE session=?` | `DELETE FROM sessions WHERE session_id=?` (cascades via FK) |

---

## Features Enabled

### 1. No More Dynamic Schema Changes
- Old: Every new sensor triggered `ALTER TABLE ADD COLUMN`
- New: Sensors registered in `sensors` table, data stored in narrow `sensor_readings`

### 2. Sensor Taxonomy
- 10 pre-defined categories (pressure, temperature, speed, etc.)
- Future capability: Filter/group sensors by category

### 3. Unit Conversion Support
- 19 pre-populated unit types with SI conversion factors
- Future capability: Convert bar↔PSI, °C↔°F, km/h↔mph in queries

### 4. Efficient Archival
- `upload_requests_raw` partitioned by date (monthly partitions)
- Easy cleanup: `ALTER TABLE upload_requests_raw DROP PARTITION p_2025_06`

### 5. Proper Foreign Key Constraints
- `sessions` → `sensor_readings` (ON DELETE CASCADE)
- `sessions` → `gps_points` (ON DELETE CASCADE)
- Deleting a session automatically cleans up all related data

---

## Migration Notes

### Data Loss
✅ **Accepted by user**: Clean deployment, no data migration

All old data from the `raw_logs` table has been discarded. The database was completely dropped and recreated with the new schema.

### Backup Files
- `upload_data.php.old` — Original procedural version with dynamic columns
- `upload_data.php.bak` — Intermediate backup

---

## Testing Checklist

Before marking this complete, test the following:

### Upload Functionality
- [ ] Torque Pro app can upload sensor data
- [ ] New sensors are auto-registered in `sensors` table
- [ ] Sensor names from `userShortName*` are stored correctly
- [ ] Data appears in `sensor_readings` table
- [ ] GPS coordinates appear in `gps_points` table
- [ ] Audit log populates `upload_requests_raw` and `upload_requests_processed`

### Dashboard Functionality
- [ ] Session list displays correctly
- [ ] Plot/chart data loads from `sensor_readings`
- [ ] GPS map displays track points
- [ ] Sensor labels show database names (not k-codes)

### Session Management
- [ ] Delete session removes all related data (cascade)
- [ ] Merge sessions combines data correctly

---

## Performance Considerations

### Indexes
All critical columns have indexes:
- `sensor_readings.session_id` + `sensor_readings.sensor_key` (composite)
- `gps_points.session_id`
- `sessions.session_id` (PRIMARY KEY)
- `upload_requests_raw.upload_date` (partition key)

### Query Patterns
- **Session list**: Direct query on `sessions` table (no GROUP BY needed)
- **Plot data**: PIVOT query on `sensor_readings` (indexed by session_id + sensor_key)
- **GPS track**: Direct query on `gps_points` (indexed by session_id)

---

## Future Enhancements

Now that the schema is normalized, these features are much easier to implement:

1. **Multi-sensor charts**: Show all pressure sensors on one chart
2. **Unit conversion**: Toggle between bar/PSI, °C/°F in real-time
3. **Sensor statistics**: Which sensors are most common across all sessions?
4. **Advanced filtering**: Show only sessions with GPS data, or sessions > 1 hour
5. **Data export**: CSV export now easier (JOIN sensors for labels)
6. **Automated archival**: Cron job to drop old partitions from `upload_requests_raw`

---

## Git Commits

```
9f6b5b8 feat: update all repository classes for normalized schema
9b829aa feat: deploy normalized schema and rewrite upload_data.php
a5d7f58 feat: add complete normalized database schema
```

---

## Status

✅ **Schema deployed**  
✅ **upload_data.php rewritten**  
✅ **All repository classes updated**  
⏳ **Testing required** (no live data yet)

---

## Next Steps

1. Test with Torque Pro app upload
2. Verify dashboard displays data correctly
3. Test session delete/merge functionality
4. Monitor `upload_requests_raw` for errors
5. Consider adding admin panel for sensor categorization

---

*Document last updated: 2026-01-15*
