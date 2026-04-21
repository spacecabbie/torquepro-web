<?php
declare(strict_types=1);

/**
 * upload_data.php — Torque Pro upload endpoint.
 *
 * Receives sensor data from the Torque Pro Android app via HTTP GET,
 * validates and persists it to the raw_logs table, and responds with "OK!".
 *
 * Auth: Torque-ID based (Auth::checkApp).
 * Logging: PSR-3 FileLogger (daily JSON log).
 * SQL safety: SqlHelper::isValidColumnName() + quoteIdentifier().
 *
 * Origin: upload_data.php (updated for OOP migration — Step 4)
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Auth/Auth.php';
require_once __DIR__ . '/includes/Database/Connection.php';
require_once __DIR__ . '/includes/Helpers/SqlHelper.php';
require_once __DIR__ . '/includes/Logging/AuditLogger.php';
require_once __DIR__ . '/includes/Logging/FileLogger.php';

use TorqueLogs\Auth\Auth;
use TorqueLogs\Database\Connection;
use TorqueLogs\Helpers\SqlHelper;
use TorqueLogs\Logging\AuditLogger;
use TorqueLogs\Logging\FileLogger;

// ── Auth guard (Torque-ID) ─────────────────────────────────────────────────
Auth::checkApp();

// ── Logger ─────────────────────────────────────────────────────────────────
$logger = new FileLogger(
    UPLOAD_LOG_DIR,
    'torque_upload'
);

$pdo = Connection::get();

// ============================================================
// ============================================================
// AuditLogger::record() is provided by includes/Logging/AuditLogger.php
// (origin: upload_data.php → AuditLogger::record())
// ============================================================

try {
// Extract sensor name hints that Torque sends as nested arrays:
//   userShortName[kXXX]=Name  →  $_GET['userShortName']['kXXX']
//   userFullName[kXXX]=Name   →  $_GET['userFullName']['kXXX']
// These are used as column COMMENTs when a new column is first created.
$sensor_names = [];
foreach (['userShortName', 'userFullName'] as $nameKey) {
    if (isset($_GET[$nameKey]) && is_array($_GET[$nameKey])) {
        foreach ($_GET[$nameKey] as $col => $name) {
            if (!isset($sensor_names[$col]) && !empty($name)) {
                $sensor_names[$col] = $name;
            }
        }
    }
}

// Fetch the existing columns from the table once.
$dbfields = [];
$stmt = $pdo->query("SHOW COLUMNS FROM `" . DB_TABLE . "`");
foreach ($stmt->fetchAll() as $row) {
    $dbfields[] = $row['Field'];
}

// Iterate over all the k* _GET arguments and check that a column exists.
if (count($_GET) > 0) {
    $keys        = [];   // validated, backtick-quoted column names for SQL
    $params      = [];   // PDO named placeholder => value
    $new_columns = 0;    // count of ALTER TABLE ADD calls this request
    $sensor_map  = [];   // k* key => raw value for audit log

    foreach ($_GET as $key => $value) {
        $submitval = false;

        // Keep columns starting with k
        if (preg_match('/^k/', $key)) {
            $submitval = true;
        } elseif (in_array($key, ['v', 'eml', 'time', 'id', 'session'], true)) {
            $submitval = true;
        // Skip userUnit*, defaultUnit*, profile* (but keep profileName*)
        } elseif (
            preg_match('/^userUnit/', $key) ||
            preg_match('/^defaultUnit/', $key) ||
            (preg_match('/^profile/', $key) && !preg_match('/^profileName/', $key))
        ) {
            $submitval = false;
        }

        if (!$submitval) {
            continue;
        }

        // Reject any key that is not a safe SQL identifier.
        if (!SqlHelper::isValidColumnName($key)) {
            continue;
        }

        // If the column doesn't exist yet, add it safely (identifier is quoted).
        if (!in_array($key, $dbfields, true)) {
            $quotedKey = SqlHelper::quoteIdentifier($key);
            $comment   = isset($sensor_names[$key])
                ? ' COMMENT ' . $pdo->quote($sensor_names[$key])
                : '';
            $pdo->exec("ALTER TABLE `" . DB_TABLE . "` ADD {$quotedKey} VARCHAR(255) NOT NULL DEFAULT '0'{$comment}");
            $dbfields[] = $key;
            $new_columns++;
        } elseif (isset($sensor_names[$key])) {
            // Column exists but may have no comment yet — update it.
            $quotedKey = SqlHelper::quoteIdentifier($key);
            $comment   = $pdo->quote($sensor_names[$key]);
            // Only update if INFORMATION_SCHEMA shows comment is currently empty.
            $chk = $pdo->prepare(
                "SELECT COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA=:s AND TABLE_NAME=:t AND COLUMN_NAME=:c"
            );
            $chk->execute([':s' => DB_NAME, ':t' => DB_TABLE, ':c' => $key]);
            $existing_comment = (string)($chk->fetchColumn() ?? '');
            if ($existing_comment === '') {
                $pdo->exec("ALTER TABLE `" . DB_TABLE . "` MODIFY {$quotedKey} VARCHAR(255) NOT NULL DEFAULT '0' COMMENT {$comment}");
            }
        }

        $keys[]               = SqlHelper::quoteIdentifier($key);
        $params[':p_' . $key] = $value;
        // Collect k* sensors for the audit log
        if (preg_match('/^k/', $key)) {
            $sensor_map[$key] = $value;
        }
    }

    if (count($keys) > 0 && count($keys) === count($params)) {
        // Build placeholders that match the :p_<key> names above.
        $placeholders = implode(', ', array_keys($params));
        $columns      = implode(', ', $keys);
        $sql          = "INSERT IGNORE INTO `" . DB_TABLE . "` ({$columns}) VALUES ({$placeholders})";
        $stmt         = $pdo->prepare($sql);
        $stmt->execute($params);
        $sensor_count = count($params);
        $logger->info('upload ok', $_GET);
        AuditLogger::record($pdo, $_GET, 'ok', $sensor_count, $new_columns, $sensor_map);
    } else {
        $logger->info('upload skipped: no valid sensor keys', $_GET);
        AuditLogger::record($pdo, $_GET, 'skipped', 0, 0, [], 'No valid sensor keys found in request');
    }
} else {
    $logger->info('upload skipped: empty request', $_GET);
    AuditLogger::record($pdo, $_GET, 'skipped', 0, 0, [], 'Empty GET request');
}

} catch (Throwable $e) {
    $logger->error('upload error: ' . $e->getMessage(), $_GET);
    AuditLogger::record($pdo, $_GET, 'error', 0, 0, [], $e->getMessage());
    // Still return OK so Torque doesn't retry endlessly; error is in the log.
}

// Return the response required by Torque.
echo 'OK!';
