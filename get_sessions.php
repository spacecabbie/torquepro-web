<?php
declare(strict_types=1);

require_once("./creds.php");
require_once("./db.php");

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 0, 'path' => dirname($_SERVER['SCRIPT_NAME'])]);
    session_start();
}
$timezone = $_SESSION['time'] ?? '';

$pdo = get_pdo();

// Get list of unique session IDs with size and time range.
$sessionqry = $pdo->query(
    "SELECT COUNT(*) AS `Session Size`,
            MIN(time)  AS `MinTime`,
            MAX(time)  AS `MaxTime`,
            session
     FROM `{$db_table}`
     GROUP BY session
     ORDER BY time DESC"
);

$sids      = [];
$seshdates = [];
$seshsizes = [];

foreach ($sessionqry->fetchAll() as $row) {
    $session_size = (int) $row['Session Size'];
    $session_duration     = (int) $row['MaxTime'] - (int) $row['MinTime'];
    $session_duration_str = gmdate('H:i:s', (int) ($session_duration / 1000));

    // Drop sessions smaller than 2 data points (removes single-ping noise).
    if ($session_size >= 2) {
        $sid         = $row['session'];
        $sids[]      = preg_replace('/\D/', '', (string) $sid);
        $seshdates[$sid] = date('F d, Y  H:i', (int) substr((string) $sid, 0, -3));
        $seshsizes[$sid] = ' (Length ' . $session_duration_str . ')';
    }
}

