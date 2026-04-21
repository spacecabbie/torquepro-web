<?php
declare(strict_types=1);

require_once('creds.php');
require_once('db.php');
require_once('auth_app.php');

// ── Request logger ────────────────────────────────────────────────────────
// Set to true to enable, false to disable without deleting the code.
// Logs go to logs/torque_upload_YYYY-MM-DD.log  (one file per day, auto-rotated).
// Each line is a JSON object for easy parsing.
define('UPLOAD_LOG_ENABLED', true);
define('UPLOAD_LOG_DIR', __DIR__ . '/logs');

function log_torque_request(array $get, string $result, ?string $error = null): void
{
    if (!UPLOAD_LOG_ENABLED) {
        return;
    }

    if (!is_dir(UPLOAD_LOG_DIR)) {
        mkdir(UPLOAD_LOG_DIR, 0750, true);
        // Deny direct web access to the logs folder.
        file_put_contents(UPLOAD_LOG_DIR . '/.htaccess', "Deny from all\n");
    }

    $entry = [
        'ts'      => date('Y-m-d H:i:s'),
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'result'  => $result,                   // 'ok' | 'error' | 'skipped'
        'error'   => $error,
        // Fixed Torque fields
        'eml'     => $get['eml']     ?? null,
        'id'      => $get['id']      ?? null,   // already hashed by Torque
        'session' => $get['session'] ?? null,
        'time'    => $get['time']    ?? null,
        'v'       => $get['v']       ?? null,   // app version
        // Sensor values: collect all k* top-level keys
        'sensors' => (static function (array $g): array {
            $out = [];
            foreach ($g as $k => $v) {
                if (preg_match('/^k/', $k) && is_string($v)) {
                    $out[$k] = $v;
                }
            }
            return $out;
        })($get),
        // Metadata arrays Torque sends about sensors
        'userShortName' => $get['userShortName'] ?? [],
        'userFullName'  => $get['userFullName']  ?? [],
        'userUnit'      => $get['userUnit']      ?? [],
        'defaultUnit'   => $get['defaultUnit']   ?? [],
        'profileName'   => $get['profileName']   ?? null,
        // Raw param count for quick anomaly detection
        'param_count'   => count($get),
    ];

    $logfile = UPLOAD_LOG_DIR . '/torque_upload_' . date('Y-m-d') . '.log';
    file_put_contents($logfile, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
}
// ── End logger ────────────────────────────────────────────────────────────

/**
 * Validate that a column name is safe to use as a SQL identifier.
 * Only letters, digits and underscores; must start with a letter or underscore;
 * max 64 chars (MariaDB identifier limit).
 */
function is_valid_column_name(string $name): bool
{
    return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $name);
}

/**
 * Backtick-quote a validated identifier for safe use in SQL.
 * Always call is_valid_column_name() before this.
 */
function quote_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

$pdo = get_pdo();

/**
 * Insert a row into the upload_requests audit table.
 * Silently swallows any DB error so a logging failure never breaks the upload.
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
$stmt = $pdo->query("SHOW COLUMNS FROM `{$db_table}`");
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
        if (!is_valid_column_name($key)) {
            continue;
        }

        // If the column doesn't exist yet, add it safely (identifier is quoted).
        if (!in_array($key, $dbfields, true)) {
            $quotedKey = quote_identifier($key);
            $comment   = isset($sensor_names[$key])
                ? " COMMENT '" . addslashes($sensor_names[$key]) . "'"
                : '';
            $pdo->exec("ALTER TABLE `{$db_table}` ADD {$quotedKey} VARCHAR(255) NOT NULL DEFAULT '0'{$comment}");
            $dbfields[] = $key;
            $new_columns++;
        } elseif (isset($sensor_names[$key])) {
            // Column exists but may have no comment yet — update it.
            $quotedKey = quote_identifier($key);
            $comment   = addslashes($sensor_names[$key]);
            // Only update if INFORMATION_SCHEMA shows comment is currently empty.
            $chk = $pdo->prepare(
                "SELECT COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA=:s AND TABLE_NAME=:t AND COLUMN_NAME=:c"
            );
            $chk->execute([':s' => $db_name, ':t' => $db_table, ':c' => $key]);
            $existing_comment = (string)($chk->fetchColumn() ?? '');
            if ($existing_comment === '') {
                $pdo->exec("ALTER TABLE `{$db_table}` MODIFY {$quotedKey} VARCHAR(255) NOT NULL DEFAULT '0' COMMENT '{$comment}'");
            }
        }

        $keys[]               = quote_identifier($key);
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
        $sql          = "INSERT INTO `{$db_table}` ({$columns}) VALUES ({$placeholders})";
        $stmt         = $pdo->prepare($sql);
        $stmt->execute($params);
        $sensor_count = count($params);
        log_torque_request($_GET, 'ok');
        audit_torque_request($pdo, $_GET, 'ok', $sensor_count, $new_columns, $sensor_map);
    } else {
        log_torque_request($_GET, 'skipped', 'No valid sensor keys found in request');
        audit_torque_request($pdo, $_GET, 'skipped', 0, 0, [], 'No valid sensor keys found in request');
    }
} else {
    log_torque_request($_GET, 'skipped', 'Empty GET request');
    audit_torque_request($pdo, $_GET, 'skipped', 0, 0, [], 'Empty GET request');
}

} catch (Throwable $e) {
    log_torque_request($_GET, 'error', $e->getMessage());
    audit_torque_request($pdo, $_GET, 'error', 0, 0, [], $e->getMessage());
    // Still return OK so Torque doesn't retry endlessly; error is in the log.
}

// Return the response required by Torque.
echo 'OK!';
