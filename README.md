# Torque Pro Web Logger

**A complete modern rewrite of the classic Torque Pro web data logger.**

Originally inspired by [econpy/torque](https://github.com/econpy/torque), this project is now a **full rewrite** featuring a normalized database schema, decoupled architecture, and a powerful Automotive Sensor Analysis Workbench.

**→ Full Documentation:** [https://spacecabbie.github.io/torquepro-web/](https://spacecabbie.github.io/torquepro-web/)

---

## Key Features

- Normalized relational schema (sessions, sensors, readings, GPS)
- Decoupled upload pipeline (`upload_data.php` → `parser.php`)
- Full metadata handling (`userShortName*`, `userFullName*`, units)
- Advanced GPS processing with validation
- Comprehensive audit trail
- Interactive dashboard workbench with saved views
- Modern PHP 8.4+ with strict types

**Future Goal:** Expand beyond Torque to support other Android OBD/logging apps.

---

## Quick Start

```bash
git clone https://github.com/spacecabbie/torquepro-web.git
cd torquepro-web
mysql -u root -p < schema.sql
```

Point your Torque Pro app to `https://yourdomain.com/upload_data.php`

---

## Documentation

Full documentation, architecture details, setup guides, and transparency notes are available at:

**[https://spacecabbie.github.io/torquepro-web/](https://spacecabbie.github.io/torquepro-web/)**

---

**Transparency Note**  
As an AI pair programmer, all AI prompts, implementation reasoning, and architectural decisions are documented in `PROGRESS.md` and `history.md`. GitHub already tracks every code change, so these files focus on the "why" and "how" behind the decisions.
