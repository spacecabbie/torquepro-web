# Build Progress — Automotive Sensor Analysis Workbench

## Overview
Rebuilding `dashboard.php` from a sidebar-based 2-sensor chart viewer into a
flexible Grafana-inspired panel workbench. Full concept documented in conversation
history. Key decisions recorded below.

---

## Key Decisions (reference for any new session)

- **Charts**: uPlot (replaces Flot entirely)
- **Grid**: CSS Grid, `minmax(200px, 1fr)`, colspan/rowspan via panel ⋮ menu
- **State**: URL query string (`?sid=X&grid=2x3&p[0][s][]=kd&p[0][cs]=2`)
- **Saved dashboards**: `saved_dashboards` DB table, slug-based pretty URLs (`d/slug`)
- **Auth model**: Viewing = open (no login). Destructive actions = auth-gated.
- **Identity**: `device_id` treated as a secret/password, stored as SHA-256 hash in `saved_dashboards.owner_device_hash`
- **No users table**: Identity = `eml` + `device_id` already in `sessions` table
- **GDPR**: Erasure by `eml` + `device_id` across all tables — no extra infra needed
- **Multi-sensor per panel**: URL model supports `p[N][s][]` array from day one; UI allows 1 sensor per panel in v1
- **Panel expiry**: `saved_dashboards.expires_at NULL` = never; future admin panel sets TTL

---

## Steps

| # | Description | Status | Commit |
|---|-------------|--------|--------|
| 1 | DB: `saved_dashboards` table migration | ✅ Done | feat: step 1 |
| 2 | Backend: `api/sensor.php` AJAX endpoint | 🔄 In progress | — |
| 3 | Backend: `SummaryRepository.php` all-sensor stats | ⬜ Not started | — |
| 4 | Frontend: Download uPlot, remove Flot references | ⬜ Not started | — |
| 5 | Dashboard: Top bar replaces sidebar | ⬜ Not started | — |
| 6 | Dashboard: CSS Grid panel shell + URL state | ⬜ Not started | — |
| 7 | Dashboard: Wire uPlot per panel + sync cursors | ⬜ Not started | — |
| 8 | Dashboard: Full paginated data summary table | ⬜ Not started | — |
| 9 | Map: Refactor to lazy modal | ⬜ Not started | — |
| 10 | Saved dashboards: Save button + slug resolver | ⬜ Not started | — |

---

## Step Detail Log

### Step 1 — DB: `saved_dashboards` table
**Status**: 🔄 In progress  
**File**: `migrate_saved_dashboards.php` (run once, then delete or keep)  
**Action**: Run migration script, verify table exists, commit.
