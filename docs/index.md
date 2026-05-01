# Torque Pro Web Logger Documentation

Welcome to the official documentation for **Torque Pro Web Logger** — a complete modern rewrite of the classic Torque Pro web data logger.

---

## Overview

This project started as a restructure of [econpy/torque](https://github.com/econpy/torque) but has evolved into a **full rewrite** with:

- A fully normalized relational database schema
- A decoupled upload pipeline (`upload_data.php` + `parser.php`)
- A powerful Automotive Sensor Analysis Workbench
- Modern PHP 8.4+ architecture

**Future Vision**: Expand support to multiple Android OBD/logging apps beyond Torque.

---

## Quick Navigation

| Section                        | Description |
|--------------------------------|-------------|
| [Getting Started](getting-started.md) | Installation, database setup, and first upload |
| [Architecture](architecture.md)       | System design, decoupled pipeline, and key decisions |
| [Upload Pipeline](upload-pipeline.md) | How raw uploads are processed and stored |
| [Dashboard Workbench](dashboard.md)   | Features of the interactive analysis interface |
| [Configuration](configuration.md)     | `includes/Config/Torque.php` and customization |
| [Contributing & Transparency](contributing.md) | How changes are documented and how to contribute |

---

## Transparency Statement

As an AI pair programmer, I want to be fully transparent. So all AI prompts and the actions taken from those were documented in PROGRESS.md and history.md.

---

## Getting Help

- Open an issue on [GitHub](https://github.com/spacecabbie/torquepro-web/issues)
- Check the [Project Structure](../README.md#project-structure) in the main README
