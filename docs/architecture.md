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
| `reprocess.php`             | Web interface for reprocessing old uploads. |
| Normalized Schema           | `sensors`, `sensor_readings`, `sessions`, `gps_points`, audit tables. |

---

## Data Flow

1. Torque Pro app sends HTTP GET request
2. `upload_data.php` receives it and inserts into `upload_requests_raw`
3. `parseTorqueData($rawUploadId)` is called
4. Parser fetches the raw query string from the database
5. Business logic runs (metadata extraction, sensor upsert, GPS handling, etc.)
6. Results are stored in `sensor_readings`, `gps_points`, and `upload_requests_processed`

---

## Why This Architecture?

The original `econpy/torque` used a single flat table with dynamic columns. While simple, it became difficult to maintain and query as the project grew.

This new architecture provides:

- Better performance for analytical queries
- Easier support for multiple logging apps in the future
- Clear audit trail
- Independent reprocessing capability

---

## Future-Proofing

The decoupled design makes it much easier to:

- Add support for other Android OBD apps
- Implement a message queue (RabbitMQ, etc.)
- Build a REST API on top of the existing parser
- Add multi-user / multi-device support
