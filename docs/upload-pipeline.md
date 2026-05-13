# Upload Pipeline

This document explains how Torque Pro uploads are processed in detail.

---

## Request Types

Torque Pro sends different types of requests:

| Type | Description | Contains |
|------|-------------|----------|
| **Type A** | Metadata-only | `userShortName*`, `userFullName*`, `userUnit*` |
| **Type B** | Trip-start notice | `lat=`, `lon=`, `notice=` |
| **Type C** | Sensor data | `k*` / `kff*` readings |
| **Type D** | Mixed | Metadata + sensor data |

---

## Processing Flow

1. **Raw Persistence** (`upload_data.php`)
   - Request is validated
   - Full query string is saved to `upload_requests_raw`
   - Audit record is created with IP, timestamp, and status

2. **Parser Invocation**
   - `parseTorqueData($rawUploadId)` is called
   - Parser fetches `raw_query_string` from the database
   - Parameters are reconstructed using `parse_str()`

3. **Business Logic** (`parser.php`)
   - Metadata extraction (`userShortName*`, units, etc.)
   - Sensor registration / update in `sensors` table
   - Sensor source classification using GPS keys and calculated prefixes
   - GPS point extraction and validation
   - Session upsert (`sessions` table)
   - Time-series insertion (`sensor_readings`)
   - GPS track point insertion (`gps_points`)
   - Processing audit (`upload_requests_processed`)

---

## Key Design Benefits

- **Auditability**: Every upload is permanently recorded
- **Reprocessability**: Any upload can be re-parsed by ID
- **Decoupling**: Upload receiver and parser can evolve independently
- **Future Extensibility**: Easy to add support for other logging apps

---

## Error Handling

- All errors are caught and logged to `upload_requests_raw.result = 'error'`
- The parser rolls back the transaction on failure
- Processing time is recorded in `processing_time_ms`
- Reprocessing updates the processed-upload record by `raw_upload_id` when the recommended schema update has been applied

---

## Reprocessing Existing Uploads

Use `reprocess.php` to process pending raw uploads that were saved successfully but do not yet have a processed audit row.

Preview a batch without writing changes:

```text
reprocess.php?dry=1
```

Run the actual batch:

```text
reprocess.php
```

The current batch size is 25 uploads.

---

## Related Dashboard API

The dashboard reads parsed data from normalized tables through:

```text
api/sensor.php?sid=SESSION_ID&key=SENSOR_KEY
```

The endpoint validates the session ID and sensor key, then returns label, unit, and timestamp/value pairs for chart rendering.
