# Configuration

This page explains how to configure the Torque Pro Web Logger.

---

## Main Configuration File

**Location**: `includes/config.php`

This file contains:
- Database connection settings
- Browser login credentials
- Optional Torque device restrictions
- Unit conversion flags
- Display flags

**Important**: Never commit real credentials to git. Keep deployment credentials private and rotate them if they are exposed.

Common settings:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');
define('DB_NAME', 'your_database');

define('AUTH_USER', 'your-email@example.com');
define('AUTH_PASS', 'choose-a-strong-password');
```

Upload restriction settings:

```php
define('TORQUE_ID', '');
define('TORQUE_ID_HASH', '');
```

Use `TORQUE_ID` for one plain device ID or an array of allowed device IDs. Use `TORQUE_ID_HASH` for a pre-hashed MD5 value. Leave both empty only for open upload endpoints.

---

## Torque-Specific Configuration

**Location**: `includes/Config/Torque.php`

This file centralizes all Torque-related settings:

```php
return [
    'gps_keys' => [...],
    'calculated_prefixes' => [...],
    'obd_pid_map' => [...],
];
```

You can extend this file when adding support for new logging apps.

---

## Unit And Display Flags

`includes/config.php` also contains unit conversion and display flags:

```php
define('SOURCE_IS_FAHRENHEIT', false);
define('USE_FAHRENHEIT', false);
define('SOURCE_IS_MILES', false);
define('USE_MILES', false);
define('HIDE_EMPTY_VARIABLES', false);
define('SHOW_SESSION_LENGTH', true);
```

Set the source flags to match what Torque sends. Set the display flags to match what users should see in the dashboard and exports.

---

## Saved Dashboards

Saved dashboard layouts are stored in the `saved_dashboards` table, which is created by:

```bash
php migrate_saved_dashboards.php
```

A saved layout stores the session ID, grid preset, panel spans, and selected sensor keys. If a device ID is provided while saving, only its SHA-256 hash is stored.

Saved links resolve through:

```text
d.php?s=your-slug
```

---

## Recommended Customizations

1. **Extend `obd_pid_map`** when you encounter new zero-padded OBD PIDs.
2. **Add GPS keys** if Torque releases new `kff*` GPS fields.
3. **Add calculated prefixes** for derived Torque values you want classified as calculated sensors.
4. **Enable the optional sensor-reading index** in `schema_updates.sql` if large dashboard sessions are slow.

---

## Environment-Specific Settings

For production, consider moving sensitive values to environment variables:

```php
define('DB_PASS', getenv('DB_PASSWORD'));
```

This is especially important if you plan to open-source your deployment configuration.
