<?php
declare(strict_types=1);

require_once("./creds.php");
require_once("./db.php");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_POST['deletesession'])) {
    $deletesession = preg_replace('/\D/', '', $_POST['deletesession']);
} elseif (isset($_GET['deletesession'])) {
    $deletesession = preg_replace('/\D/', '', $_GET['deletesession']);
}

if (isset($deletesession) && $deletesession !== '') {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare("DELETE FROM `{$db_table}` WHERE session = :sid");
    $stmt->execute([':sid' => $deletesession]);
}

