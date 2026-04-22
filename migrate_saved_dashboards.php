<?php
declare(strict_types=1);

/**
 * migrate_saved_dashboards.php — One-shot CLI migration script.
 *
 * Creates the `saved_dashboards` table used for pretty-URL dashboard slugs.
 * Run once from the command line:
 *
 *   php migrate_saved_dashboards.php
 *
 * Safe to re-run — uses CREATE TABLE IF NOT EXISTS.
 * Delete or keep this file after running; it makes no changes if the table exists.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database/Connection.php';

use TorqueLogs\Database\Connection;

$pdo = Connection::get();

$sql = "
CREATE TABLE IF NOT EXISTS saved_dashboards (
    id                 INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    slug               VARCHAR(80)      NOT NULL,
    title              VARCHAR(120)     NULL,
    state_json         TEXT             NOT NULL,
    owner_email        VARCHAR(255)     NULL     COMMENT 'Informational only — matches sessions.eml',
    owner_device_hash  VARCHAR(64)      NULL     COMMENT 'SHA-256 of Torque device_id — never store raw',
    expires_at         DATETIME         NULL     COMMENT 'NULL = never expires',
    created_at         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_slug (slug),
    KEY idx_owner_email (owner_email),
    KEY idx_expires_at (expires_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Saved dashboard layouts with optional pretty-URL slugs';
";

try {
    $pdo->exec($sql);
    echo "✅  Table `saved_dashboards` created (or already exists).\n";

    // Verify the table is accessible
    $count = (int) $pdo->query("SELECT COUNT(*) FROM saved_dashboards")->fetchColumn();
    echo "✅  Table verified — {$count} row(s) currently stored.\n";
} catch (\PDOException $e) {
    echo "❌  Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone. You may delete this file or keep it — it is safe to re-run.\n";
