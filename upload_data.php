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
require_once __DIR__ . '/includes/Logging/FileLogger.php';

use TorqueLogs\Auth\Auth;
use TorqueLogs\Database\Connection;
use TorqueLogs\Helpers\SqlHelper;
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
// Audit logger  (origin: upload_data.php → audit_torque_request())
// ============================================================

/**
 * Insert a row into the upload_requests audit table.
 * Silently swallows any DB error so a logging failure never breaks the upload.
 *
 * @param  \PDO                $pdo
 * @param  array<string,mixed> $get
 * @param  string              $result       'ok' | 'error' | 'skipped'
 * @param  int                 $sensor_count
 * @param  int                 $new_columns
 * @param  array<string,mixed> $sensor_map
 * @param  string|null         $error
 * @return void
 */
function audit_torque_request(
    PDO $pdo,
    array $get,
    string $result,
    int $sensor_count,
    int $new_columns,
    array $sensor_map = [],
    ?string $error = null
): void {
    try {
        $sensor_json = $sensor_map ? json_encode($sensor_map, JSON_UNESCAPED_UNICODE) : null;
        $stmt = $pdo->prepare(
            "INSERT INTO `upload_requests`
                (ip, torque_id, eml, app_version, session, data_ts,
                 sensor_count, sensor_data, new_columns, profile_name, result, error_msg)
             VALUES
                (:ip, :torque_id, :eml, :app_version, :session, :data_ts,
                 :sensor_count, :sensor_data, :new_columns, :profile_name, :result, :error_msg)"
        );
        $stmt->execute([
            ':ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
            ':torque_id'    => $get['id']          ?? '',
            ':eml'          => $get['eml']         ?? '',
            ':app_version'  => $get['v']           ?? '',
            ':session'      => $get['session']     ?? '',
            ':data_ts'      => isset($get['time']) ? (int)$get['time'] : null,
            ':sensor_count' => $sensor_count,
            ':sensor_data'  => $sensor_json,
            ':new_columns'  => $new_columns,
            ':profile_name' => $get['profileName'] ?? '',
            ':result'       => $result,
            ':error_msg'    => $error,
        ]);
    } catch (Throwable) {
        // Audit failure must never abort the main upload response.
    }
}

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
                ? " COMMENT '" . addslashes($sensor_names[$key]) . "'"
                : '';
            $pdo->exec("ALTER TABLE `" . DB_TABLE . "` ADD {$quotedKey} VARCHAR(255) NOT NULL DEFAULT '0'{$comment}");
            $dbfields[] = $key;
            $new_columns++;
        } elseif (isset($sensor_names[$key])) {
            // Column exists but may have no comment yet — update it.
            $quotedKey = SqlHelper::quoteIdentifier($key);
            $comment   = addslashes($sensor_names[$key]);
            // Only update if INFORMATION_SCHEMA shows comment is currently empty.
            $chk = $pdo->prepare(
                "SELECT COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA=:s AND TABLE_NAME=:t AND COLUMN_NAME=:c"
            );
            $chk->execute([':s' => DB_NAME, ':t' => DB_TABLE, ':c' => $key]);
            $existing_comment = (string)($chk->fetchColumn() ?? '');
            if ($existing_comment === '') {
                $pdo->exec("ALTER TABLE `" . DB_TABLE . "` MODIFY {$quotedKey} VARCHAR(255) NOT NULL DEFAULT '0' COMMENT '{$comment}'");
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
        $sql          = "INSERT INTO `" . DB_TABLE . "` ({$columns}) VALUES ({$placeholders})";
        $stmt         = $pdo->prepare($sql);
        $stmt->execute($params);
        $sensor_count = count($params);
        $logger->info('upload ok', $_GET);
        audit_torque_request($pdo, $_GET, 'ok', $sensor_count, $new_columns, $sensor_map);
    } else {
        $logger->info('upload skipped: no valid sensor keys', $_GET);
        audit_torque_request($pdo, $_GET, 'skipped', 0, 0, [], 'No valid sensor keys found in request');
    }
} else {
    $logger->info('upload skipped: empty request', $_GET);
    audit_torque_request($pdo, $_GET, 'skipped', 0, 0, [], 'Empty GET request');
}

} catch (Throwable $e) {
    $logger->error('upload error: ' . $e->getMessage(), $_GET);
    audit_torque_request($pdo, $_GET, 'error', 0, 0, [], $e->getMessage());
    // Still return OK so Torque doesn't retry endlessly; error is in the log.
}

// Return the response required by Torque.
echo 'OK!';
