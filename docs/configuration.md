# Configuration

This page explains how to configure the Torque Pro Web Logger.

---

## Main Configuration File

**Location**: `includes/config.php`

This file contains:
- Database connection settings
- Authentication secrets
- Feature flags
- Logging level

**Important**: Never commit real credentials to git. Use environment variables or a separate `config.local.php` that is gitignored.

---

## Torque-Specific Configuration

**Location**: `includes/Config/Torque.php`

This file centralizes all Torque-related settings:

```php
return [
    'gps_sensor_keys' => [...],
    'calculated_prefixes' => [...],
    'obd_pid_map' => [...],
    'default_category_id' => 10,
];
```

You can extend this file when adding support for new logging apps.

---

## Recommended Customizations

1. **Change the default category ID** if you want new sensors to appear in a different category.
2. **Extend `obd_pid_map`** when you encounter new zero-padded PIDs.
3. **Add new GPS keys** if Torque releases new `kff*` fields in the future.

---

## Environment-Specific Settings

For production, consider moving sensitive values to environment variables:

```php
define('DB_PASS', getenv('DB_PASSWORD'));
```

This is especially important if you plan to open-source your deployment configuration.
