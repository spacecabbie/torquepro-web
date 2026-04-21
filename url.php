<?php
declare(strict_types=1);

session_set_cookie_params(['lifetime' => 0, 'path' => dirname($_SERVER['SCRIPT_NAME'])]);
session_start();

// Derive the base URL (everything up to and including the directory of this script).
$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir      = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$baselink = $scheme . '://' . $host . $dir . '/session.php';

if (isset($_GET['makechart'])) {
    if (isset($_GET['seshid'])) {
        // Digits-only; no mysql_escape_string needed.
        $seshid = preg_replace('/\D/', '', $_GET['seshid']);
        if (isset($_POST['plotdata']) && is_array($_POST['plotdata'])) {
            $plotdataarray = $_POST['plotdata'];
            $s1data = $plotdataarray[0] ?? '';
            $s2data = $plotdataarray[1] ?? '';
            $outurl = $baselink . '?id=' . $seshid . '&s1=' . urlencode($s1data) . '&s2=' . urlencode($s2data);
        } else {
            $seshid = $_SESSION['recent_session_id'] ?? '';
            $outurl = $baselink . '?id=' . $seshid;
        }
    } else {
        $seshid = $_SESSION['recent_session_id'] ?? '';
        $outurl = $baselink . '?id=' . $seshid;
    }
} else {
    if (isset($_POST['seshidtag'])) {
        $seshid = preg_replace('/\D/', '', $_POST['seshidtag']);
        $outurl = $baselink . '?id=' . $seshid;
    } else {
        $seshid = $_SESSION['recent_session_id'] ?? '';
        $outurl = $baselink . '?id=' . $seshid;
    }
}

header('Location: ' . $outurl);
exit;

?>
