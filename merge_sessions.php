<?php
declare(strict_types=1);

require_once("./creds.php");
require_once("./db.php");
require_once("./get_sessions.php");

// session_start() is already called inside get_sessions.php.

if (isset($_POST['mergesession'])) {
    $mergesession = preg_replace('/\D/', '', $_POST['mergesession']);
} elseif (isset($_GET['mergesession'])) {
    $mergesession = preg_replace('/\D/', '', $_GET['mergesession']);
}

if (isset($_POST['mergesessionwith'])) {
    $mergesessionwith = preg_replace('/\D/', '', $_POST['mergesessionwith']);
} elseif (isset($_GET['mergesessionwith'])) {
    $mergesessionwith = preg_replace('/\D/', '', $_GET['mergesessionwith']);
}

if (
    isset($mergesession) && $mergesession !== '' &&
    isset($mergesessionwith) && $mergesessionwith !== ''
) {
    // Sessions to be merged must be direct neighbours.
    // 'With' must be younger (lower array index in $sids).
    $idx1 = array_search($mergesession, $sids, true);
    $idx2 = array_search($mergesessionwith, $sids, true);

    if ($idx1 === false || $idx2 === false || $idx1 !== ($idx2 + 1)) {
        die('Invalid sessions to be merged. Aborted.');
    }

    $pdo  = get_pdo();
    $stmt = $pdo->prepare(
        "UPDATE `{$db_table}` SET session = :sid WHERE session = :with"
    );
    $stmt->execute([':sid' => $mergesession, ':with' => $mergesessionwith]);

    // Show merged session.
    $session_id = $mergesession;
}

