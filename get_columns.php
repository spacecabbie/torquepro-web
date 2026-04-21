<?php
declare(strict_types=1);

require_once("./creds.php");
require_once("./db.php");

$pdo = get_pdo();

// Initialise to empty array so count($coldata) never crashes on PHP 8.
$coldata = [];

// Fetch column name, comment and data type from INFORMATION_SCHEMA using
// prepared statement to avoid any injection risk from config values.
$colqry = $pdo->prepare(
    "SELECT COLUMN_NAME, COLUMN_COMMENT, DATA_TYPE
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = :schema
       AND TABLE_NAME   = :table"
);
$colqry->execute([':schema' => $db_name, ':table' => $db_table]);

// Accept float AND varchar k* columns — Torque stores all sensor readings
// (including custom PIDs) as varchar but the values are always numeric.
$plottable_types = ['float', 'varchar', 'double', 'decimal', 'int', 'bigint'];

foreach ($colqry->fetchAll() as $x) {
    if (
        substr($x['COLUMN_NAME'], 0, 1) === 'k' &&
        in_array($x['DATA_TYPE'], $plottable_types, true)
    ) {
        $coldata[] = ['colname' => $x['COLUMN_NAME'], 'colcomment' => $x['COLUMN_COMMENT']];
    }
}

$numcols = strval(count($coldata) + 1);

// Resolve session id from request.
if (isset($_POST['id'])) {
    $session_id = preg_replace('/\D/', '', $_POST['id']);
} elseif (isset($_GET['id'])) {
    $session_id = preg_replace('/\D/', '', $_GET['id']);
}

// If we have a session, check which columns contain no useful data at all.
$coldataempty = [];
if (isset($session_id) && $session_id !== '' && count($coldata) > 0) {
    foreach ($coldata as $col) {
        $colname    = $col['colname'];
        $quotedCol  = '`' . str_replace('`', '``', $colname) . '`';
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT {$quotedCol}) < 2 AS is_empty
             FROM `{$db_table}`
             WHERE session = :sid"
        );
        $stmt->execute([':sid' => $session_id]);
        $row = $stmt->fetch();
        $coldataempty[$colname] = (bool) $row['is_empty'];
    }
}

