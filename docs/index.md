# Torque Pro Web Logger Documentation

Welcome to the official documentation for **Torque Pro Web Logger** — a complete modern rewrite of the classic Torque Pro web data logger.

---

## Overview

This project started as a restructure of [econpy/torque](https://github.com/econpy/torque) but has evolved into a **full rewrite** with:

- A fully normalized relational database schema
- A decoupled upload pipeline (`upload_data.php` + `parser.php`)
- A powerful Automotive Sensor Analysis Workbench
- Multi-sensor dashboard overlays with synchronized inspection
- Saved dashboard links for sharing or returning to a layout later
- Modern PHP 8.4+ architecture

**Future Vision**: Expand support to multiple Android OBD/logging apps beyond Torque.

---

## Latest User-Facing Updates

The current dashboard workbench includes:

- **Multi-sensor overlay panels**: compare up to 6 compatible sensors in one chart.
- **Active sensor chips**: remove individual overlay sensors directly from the panel header.
- **Synchronized cursor inspector**: inspect values across all visible charts at the same timestamp.
- **Global time controls**: reset to the full session or jump to the last 1, 5, or 10 minutes.
- **Saved dashboard URLs**: save a layout and reopen it through `d.php?s=your-slug`.
- **Session summary table**: review min, max, average, P25, P75, sample count, and trend sparklines for every sensor.
- **Lazy GPS map modal**: load the map only when opened, with start/end markers and route bounds.

---

## Quick Navigation

| Section                        | Description |
|--------------------------------|-------------|
| [Getting Started](getting-started.md) | Installation, database setup, and first upload |
| [Architecture](architecture.md)       | System design, decoupled pipeline, and key decisions |
| [Upload Pipeline](upload-pipeline.md) | How raw uploads are processed and stored |
| [Dashboard Workbench](dashboard.md)   | Features of the interactive analysis interface |
| [Configuration](configuration.md)     | `includes/Config/Torque.php` and customization |
| [Testing Guide](testing.md)           | Manual checks before staging or production |
| [Performance Guide](performance.md)   | Performance limits, monitoring, and tuning notes |
| [Contributing & Transparency](contributing.md) | How changes are documented and how to contribute |

---

## Transparency Statement

As an AI pair programmer, I want to be fully transparent. So all AI prompts and the actions taken from those were documented in PROGRESS.md and history.md.

---

## Getting Help

- Open an issue on [GitHub](https://github.com/spacecabbie/torquepro-web/issues)
- Check the [Project Structure](../README.md#project-structure) in the main README
