<?php
declare(strict_types=1);

/**
 * d.php — Pretty-URL slug resolver for saved dashboards.
 *
 * Usage:
 *   /torque-logs/d.php?s=my-slug
 *
 * Looks up the slug in `saved_dashboards`, decodes the stored
 * state_json, and issues a 302 redirect to dashboard.php with
 * the equivalent query string:
 *
 *   dashboard.php?id=SID&grid=2x3&p[0][s][]=kd&p[1][s][]=kf&p[1][cs]=2
 *
 * Returns a plain 404 page if the slug is unknown or expired.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database/Connection.php';
require_once __DIR__ . '/includes/Data/SavedDashboardRepository.php';

use TorqueLogs\Database\Connection;
use TorqueLogs\Data\SavedDashboardRepository;

// ── Validate the slug input ───────────────────────────────────────────────────
$rawSlug = trim((string) ($_GET['s'] ?? ''));
$slug    = SavedDashboardRepository::sanitiseSlug($rawSlug);

if ($slug === null) {
    sendNotFound('Invalid or missing dashboard slug.');
}

// ── Look up in DB ─────────────────────────────────────────────────────────────
$repo = new SavedDashboardRepository(Connection::get());
$row  = $repo->findBySlug($slug);

if ($row === null) {
    sendNotFound('Dashboard not found or has expired.');
}

// ── Decode state_json ─────────────────────────────────────────────────────────
try {
    $state = json_decode($row['state_json'], associative: true, flags: JSON_THROW_ON_ERROR);
} catch (\JsonException) {
    sendNotFound('Saved dashboard data is corrupt.');
}

// ── Build redirect URL ────────────────────────────────────────────────────────
$params = [];

// Session ID
$sid = preg_replace('/\D/', '', (string) ($state['id'] ?? ''));
if ($sid === '') {
    sendNotFound('Saved dashboard references an invalid session.');
}
$params['id'] = $sid;

// Grid
$grid = (string) ($state['grid'] ?? '2x3');
if (!preg_match('/^[1-6]x[1-6]$/', $grid)) {
    $grid = '2x3';
}
$params['grid'] = $grid;

// Panels — PHP will encode p[N][s][]=x as repeated keys, so build manually.
$panelParts = [];
if (isset($state['p']) && is_array($state['p'])) {
    foreach ($state['p'] as $i => $panel) {
        $sensors = (is_array($panel['s'] ?? null)) ? $panel['s'] : [];
        foreach ($sensors as $key) {
            if (preg_match('/^[a-zA-Z0-9_]{1,40}$/', (string) $key)) {
                $panelParts[] = rawurlencode("p[{$i}][s][]") . '=' . rawurlencode($key);
            }
        }
        $cs = max(1, min(6, (int) ($panel['cs'] ?? 1)));
        $rs = max(1, min(6, (int) ($panel['rs'] ?? 1)));
        if ($cs > 1) $panelParts[] = rawurlencode("p[{$i}][cs]") . '=' . $cs;
        if ($rs > 1) $panelParts[] = rawurlencode("p[{$i}][rs]") . '=' . $rs;
    }
}

$qs  = http_build_query($params);
if ($panelParts !== []) {
    $qs .= '&' . implode('&', $panelParts);
}

$target = 'dashboard.php?' . $qs;

header('Location: ' . $target, replace: true, response_code: 302);
exit;

// ── 404 helper ────────────────────────────────────────────────────────────────
function sendNotFound(string $message): never
{
    http_response_code(404);
    // Minimal dark-themed 404 page — no external assets required.
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Dashboard Not Found — Torque Logs</title>
        <style>
            body { font-family: system-ui, sans-serif; background:#0d0d1a; color:#c9d1d9;
                   display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
            .box { text-align:center; }
            h1   { font-size:3rem; color:#f85149; margin:0 0 .5rem; }
            p    { color:#6e7681; }
            a    { color:#4e9af1; text-decoration:none; }
        </style>
    </head>
    <body>
        <div class="box">
            <h1>404</h1>
            <p><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
            <a href="dashboard.php">← Back to dashboard</a>
        </div>
    </body>
    </html>
    HTML;
    exit;
}
