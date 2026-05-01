# Torque Pro Web Logger

**A complete modern rewrite of the classic Torque Pro web data logger.**

Originally inspired by [econpy/torque](https://github.com/econpy/torque), this project has evolved into a **full rewrite** with a normalized relational database schema, decoupled architecture, and a powerful Automotive Sensor Analysis Workbench.

---

## Key Features

- **Normalized Database Schema** — Clean separation of sessions, sensors, readings, GPS points, and audit logs (no more dynamic `ALTER TABLE` flat tables)
- **Decoupled Upload Pipeline** — `upload_data.php` only handles raw persistence and audit; `parser.php` performs all business logic by reading from the database
- **Full Metadata Support** — Automatic extraction and management of `userShortName*`, `userFullName*`, `userUnit*`, and `defaultUnit*` from Torque uploads
- **Advanced GPS Handling** — Priority logic (`kff*` > `lat`/`lon`), validation, and extended fields (bearing, accuracy, satellites)
- **Comprehensive Audit Trail** — Every upload is logged to `upload_requests_raw` with processing status and error tracking
- **Automotive Sensor Analysis Workbench** — Interactive dashboards with CSS Grid, uPlot charts, saved views with pretty URLs, and reprocessing tools
- **Modern PHP 8.4+** — Strict types, PSR-12 compliance, prepared statements, and clean OOP architecture
- **Future-Proof Design** — Built with the intention to eventually support other Android OBD/logging apps beyond Torque

---

## Relationship to the Original Project

This is a **complete rework / total rewrite** of the original [econpy/torque](https://github.com/econpy/torque) repository (2012).

While the original project served as valuable inspiration and a starting point, the current codebase differs fundamentally in:

- Data model (fully normalized vs. single flat table)
- Architecture (decoupled parser vs. monolithic script)
- Code quality (modern PHP 8.4+ with strict types vs. deprecated `mysql_*` functions)
- Features (full analysis workbench vs. basic logging)

**Transparency note**: As an AI pair programmer, I document every significant change extensively in `PROGRESS.md` and `history.md`. The goal is complete traceability and honesty about the project's evolution.

---

## Future Vision

The long-term intention is to **expand beyond the Torque scope** and support multiple Android logging / OBD apps. The decoupled architecture and normalized schema are designed to make this transition as smooth as possible.

---

## Architecture Overview

### Core Components

| Component              | Responsibility |
|------------------------|----------------|
| `upload_data.php`      | Thin receiver — validates request and persists raw data to `upload_requests_raw` |
| `parser.php`           | Business logic layer — fetches raw data from DB and processes sensors, GPS, sessions, etc. |
| `includes/Config/Torque.php` | Centralized configuration for GPS keys, calculated prefixes, and OBD PID mappings |
| Normalized Schema      | `sensors`, `sensor_readings`, `sessions`, `gps_points`, `upload_requests_raw`, `upload_requests_processed` |

### Key Design Decisions

- **Single source of truth**: The `upload_requests_raw` table is the authoritative record of every upload
- **Parser independence**: `parser.php` can be called with just a `rawUploadId` — ideal for reprocessing, queues, or future integrations
- **Extensive auditability**: Every change and processing step is tracked

---

## Documentation & Transparency

This project prioritizes **maximum transparency**:

- **[PROGRESS.md](PROGRESS.md)** — High-level build progress and current status
- **[history.md](history.md)** — Complete chronological archive of all changes, decisions, and refactoring steps
- **Extensive inline comments** in all major files explaining design choices

---

## Getting Started

### Prerequisites

- PHP 8.4+
- MySQL / MariaDB
- Web server (Apache / Nginx)

### Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/spacecabbie/torquepro-web.git
   cd torquepro-web
   ```

2. Import the database schema:
   ```bash
   mysql -u youruser -p yourdatabase < schema.sql
   ```

3. (Optional) Apply recommended improvements:
   ```bash
   mysql -u youruser -p yourdatabase < schema_updates.sql
   ```

4. Configure your database credentials in `includes/config.php`

5. Point your Torque Pro app to:
   ```
   https://yourdomain.com/upload_data.php
   ```

---

## Project Structure

```
.
├── upload_data.php          # Thin upload receiver + audit
├── parser.php               # Core business logic (DB-driven)
├── reprocess.php            # Web UI for reprocessing uploads
├── dashboard.php            # Automotive Sensor Analysis Workbench
├── includes/
│   ├── Auth/                # Authentication layer
│   ├── Database/            # PDO connection & helpers
│   ├── Data/                # Repositories (Session, Summary, etc.)
│   ├── Helpers/             # DataHelper, normalizeUnitKey(), etc.
│   └── Config/
│       └── Torque.php       # Centralized Torque-specific configuration
├── static/                  # CSS, JS (uPlot, Leaflet, etc.)
├── schema.sql               # Main database schema
├── schema_updates.sql       # Recommended additive improvements
├── PROGRESS.md              # Current build status
└── history.md               # Complete project history
```

---

## Contributing

Contributions are welcome! Please open an issue first to discuss major changes.

When contributing, please:
- Follow PSR-12 coding standards
- Add clear comments explaining non-obvious design decisions
- Update `history.md` with your changes

---

## Acknowledgments

- Original inspiration: [econpy/torque](https://github.com/econpy/torque) (2012)
- Community resources: [torque-bhp.com](https://torque-bhp.com) forum and the excellent [codes-table.md](https://github.com/briancline/torque-satellite/blob/master/doc/codes-table.md) by briancline

---

## License

This project is open source. Please check the license file for details.

---

**Transparency Statement**  
As an AI pair programmer, every major architectural decision and code change has been documented extensively. The goal is full traceability and honest representation of the project's evolution from its original inspiration to its current form as a modern, extensible platform.
