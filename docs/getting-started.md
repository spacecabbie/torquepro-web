# Getting Started

This guide will help you set up the Torque Pro Web Logger on your server.

---

## Prerequisites

- PHP 8.4 or higher
- MySQL 8.0+ or MariaDB 10.5+
- Web server (Apache 2.4+ or Nginx)
- PHP PDO MySQL extension
- A writable PHP session directory for browser login sessions

---

## Installation Steps

### 1. Clone the Repository

```bash
git clone https://github.com/spacecabbie/torquepro-web.git
cd torquepro-web
```

### 2. Import the Database Schema

```bash
mysql -u yourusername -p yourdatabase < schema.sql
```

Apply the recommended additive schema updates:

```bash
mysql -u yourusername -p yourdatabase < schema_updates.sql
```

These updates add sensor source metadata, safer reprocessing behavior, and optional unit aliases. The optional dashboard index inside `schema_updates.sql` is commented out; enable it only if large sessions make dashboard queries slow.

Create the saved-dashboard table if you want the **Save** button and `d.php?s=...` links:

```bash
php migrate_saved_dashboards.php
```

The migration is safe to re-run.

### 3. Configure Database Connection

Edit `includes/config.php` and set your database credentials:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'torque_logs');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');
```

Also set browser login credentials:

```php
define('AUTH_USER', 'your-email@example.com');
define('AUTH_PASS', 'choose-a-strong-password');
```

To restrict uploads to one or more known Torque devices, set `TORQUE_ID` or `TORQUE_ID_HASH`. Leave both empty only if any Torque client may upload to this endpoint.

### 4. Set Up Web Server

Point your domain or subdomain to the project root.

**Recommended**: Use HTTPS (Let's Encrypt is free and easy).

### 5. Configure Torque Pro App

In the Torque Pro app, go to:

**Settings → Data Logging & Upload → Web Server URL**

Enter:

```
https://yourdomain.com/upload_data.php
```

Enable **"Upload to web server"** and test the connection.

---

## First Dashboard Check

After your first upload:

1. Open `dashboard.php`.
2. Sign in with `AUTH_USER` and `AUTH_PASS`.
3. Select the new session from the picker.
4. Choose a grid preset and add sensors to panels.
5. Use **Save** if you want a reusable dashboard link.

If a session does not appear, it may have fewer than 2 data points or the upload may not have parsed successfully. Check `upload_requests_raw` and `upload_requests_processed`.

---

## Next Steps

- [Explore the Architecture](architecture.md)
- [Understand the Upload Pipeline](upload-pipeline.md)
- [Set up the Dashboard Workbench](dashboard.md)
- [Run the Testing Guide](testing.md)
