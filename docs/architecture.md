# Architecture Overview

The Torque Pro Web Logger is built with a **clean, decoupled architecture** designed for maintainability and future expansion.

---

## Core Design Principles

- **Separation of Concerns**: Upload handling is separated from business logic.
- **Single Source of Truth**: `upload_requests_raw` is the authoritative record of every upload.
- **Parser Independence**: `parser.php` can be triggered with just a `rawUploadId`.
- **Normalized Data Model**: Clean relational tables instead of dynamic flat tables.
- **Transparency First**: Every change is documented in `PROGRESS.md` and `history.md`.

---

## System Components

| Component                    | Role |
|-----------------------------|------|
| `upload_data.php`           | Thin receiver. Validates request, writes to `upload_requests_raw`, then calls parser. |
| `parser.php`                | Business logic layer. Fetches raw data from DB and processes everything. |
| `includes/Config/Torque.php`| Centralized configuration (GPS keys, calculated prefixes, OBD mappings). |
| `dashboard.php`             | Interactive workbench for session analysis, overlays, maps, CSV export, and saved layouts. |
| `api/sensor.php`            | Read-only JSON endpoint for one session/sensor time series. |
| `api/dashboard_save.php`    | Saves dashboard layout state and returns a reusable slug link. |
| `d.php`                     | Resolves saved dashboard slugs and redirects to equivalent dashboard query strings. |
| `reprocess.php`             | Web interface for reprocessing pending raw uploads. |
| Normalized Schema           | `sensors`, `sensor_readings`, `sessions`, `gps_points`, audit tables, and optional `saved_dashboards`. |

---

## Data Flow

1. Torque Pro app sends HTTP GET request
2. `upload_data.php` receives it and inserts into `upload_requests_raw`
3. `parseTorqueData($rawUploadId)` is called
4. Parser fetches the raw query string from the database
5. Business logic runs (metadata extraction, sensor upsert, GPS handling, etc.)
6. Results are stored in `sensor_readings`, `gps_points`, and `upload_requests_processed`
7. Dashboard pages read normalized data through repository classes and per-sensor JSON endpoints

---

## Dashboard State

The dashboard is intentionally URL-driven. Session, grid, panel spans, and selected sensors are encoded in the query string:

```text
dashboard.php?id=123&grid=2x3&p[0][s][]=kd&p[0][s][]=kf&p[0][cs]=2
```

Saved dashboards store the same minimal state as JSON in `saved_dashboards`. The `d.php` resolver converts a slug back into the canonical dashboard URL.

---

## Why This Architecture?

The original `econpy/torque` used a single flat table with dynamic columns. While simple, it became difficult to maintain and query as the project grew.

This new architecture provides:

- Better performance for analytical queries
- Easier support for multiple logging apps in the future
- Clear audit trail
- Independent reprocessing capability
- Shareable dashboard state without duplicating chart data

---

## Future-Proofing

The decoupled design makes it much easier to:

- Add support for other Android OBD apps
- Implement a message queue (RabbitMQ, etc.)
- Build a REST API on top of the existing parser
- Add multi-user / multi-device support
