# Getting Started

This guide will help you set up the Torque Pro Web Logger on your server.

---

## Prerequisites

- PHP 8.4 or higher
- MySQL 8.0+ or MariaDB 10.5+
- Web server (Apache 2.4+ or Nginx)
- Composer (optional, for future dependencies)

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

**Optional** — Apply recommended improvements:

```bash
mysql -u yourusername -p yourdatabase < schema_updates.sql
```

### 3. Configure Database Connection

Edit `includes/config.php` and set your database credentials:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'torque_logs');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');
```

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

## Next Steps

- [Explore the Architecture](architecture.md)
- [Understand the Upload Pipeline](upload-pipeline.md)
- [Set up the Dashboard Workbench](dashboard.md)
