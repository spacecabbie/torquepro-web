<?php
declare(strict_types=1);
/**
 * live_log_data.php – JSON feed for the live upload console.
 *
 * Returns up to 100 rows from upload_requests that are newer than
 * the `since_id` query parameter (default 0).
 *
 * Access is restricted to logged-in web users.
 * Auth is checked via session only — no HTML output on failure.
 */

// Start (or resume) the session quietly before any output.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Always respond with JSON, even for auth failures.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['torque_logged_in'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once('creds.php');
require_once('db.php');

$since_id = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;

try {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare(
        "SELECT
            id,
            ts,
            ip,
            torque_id,
            eml,
            app_version,
            session,
            data_ts,
            sensor_count,
            sensor_data,
            new_columns,
            profile_name,
            result,
            error_msg
         FROM upload_requests
         WHERE id > :since_id
         ORDER BY id ASC
         LIMIT 100"
    );
    $stmt->execute([':since_id' => $since_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast numeric fields so JSON types are correct.
    foreach ($rows as &$r) {
        $r['id']           = (int)$r['id'];
        $r['sensor_count'] = (int)$r['sensor_count'];
        $r['new_columns']  = (int)$r['new_columns'];
        $r['data_ts']      = $r['data_ts'] !== null ? (int)$r['data_ts'] : null;
        // Decode stored JSON so the client gets a proper object, not a string
        $r['sensor_data']  = $r['sensor_data'] !== null
            ? json_decode($r['sensor_data'], true)
            : null;
    }
    unset($r);

    // Fetch column comments from INFORMATION_SCHEMA so the UI can show
    // friendly sensor names alongside the k* keys.
    $colNames = [];
    $cnStmt = $pdo->prepare(
        "SELECT COLUMN_NAME, COLUMN_COMMENT
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = :tbl
           AND COLUMN_NAME  LIKE 'k%'
           AND COLUMN_COMMENT <> ''"
    );
    $cnStmt->execute([':tbl' => $db_table]);
    foreach ($cnStmt->fetchAll(PDO::FETCH_ASSOC) as $cn) {
        $colNames[$cn['COLUMN_NAME']] = $cn['COLUMN_COMMENT'];
    }

    echo json_encode(
        ['rows' => $rows, 'col_names' => $colNames, 'ts' => time()],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
