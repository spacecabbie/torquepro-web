<?php
declare(strict_types=1);

/**
 * api/dashboard_save.php — Upsert a saved dashboard layout.
 *
 * Method: POST (application/json body)
 *
 * Request body (JSON):
 * {
 *   "title":     "My track — cold start",      // optional, default ""
 *   "slug":      "cold-start",                 // optional; auto-generated when omitted
 *   "state":     {                             // required
 *     "id":    "SESSION_ID",
 *     "grid":  "2x3",
 *     "p": [
 *       {"s": ["kd"], "cs": 1, "rs": 1},
 *       {"s": ["kf"], "cs": 2, "rs": 1}
 *     ]
 *   },
 *   "owner_email":  "me@example.com",          // optional, informational
 *   "device_id":    "TORQUE_DEVICE_ID"         // optional, used for ownership hash
 * }
 *
 * Success (200):
 * { "slug": "cold-start", "url": "/torque-logs/d.php?s=cold-start" }
 *
 * Errors:
 * HTTP 400 + { "error": "..." } for bad input
 * HTTP 409 + { "error": "..." } if slug is taken by another owner
 * HTTP 500 + { "error": "..." } on unexpected DB failure
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Database/Connection.php';
require_once __DIR__ . '/../includes/Data/SavedDashboardRepository.php';

use TorqueLogs\Database\Connection;
use TorqueLogs\Data\SavedDashboardRepository;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Only accept POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// ── Parse JSON body ───────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '', associative: true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Request body must be valid JSON.']);
    exit;
}

// ── Validate state ────────────────────────────────────────────────────────────
$state = $body['state'] ?? null;
if (!is_array($state) || !isset($state['id']) || !preg_match('/^\d{1,20}$/', (string) $state['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid state.id (session ID).']);
    exit;
}

if (!isset($state['grid']) || !preg_match('/^[1-6]x[1-6]$/', (string) $state['grid'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid state.grid (e.g. "2x3").']);
    exit;
}

if (!isset($state['p']) || !is_array($state['p'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing state.p (panel array).']);
    exit;
}

// Encode only the canonical fields — no extra cruft stored.
$stateJson = json_encode(
    [
        'id'   => (string) $state['id'],
        'grid' => (string) $state['grid'],
        'p'    => array_map(static function (mixed $panel): array {
            $sensors = (is_array($panel['s'] ?? null)) ? array_map('strval', $panel['s']) : [];
            $sensors = array_values(array_filter(
                $sensors,
                static fn(string $k): bool => (bool) preg_match('/^[a-zA-Z0-9_]{1,40}$/', $k)
            ));
            return [
                's'  => $sensors,
                'cs' => max(1, min(6, (int) ($panel['cs'] ?? 1))),
                'rs' => max(1, min(6, (int) ($panel['rs'] ?? 1))),
            ];
        }, $state['p']),
    ],
    JSON_THROW_ON_ERROR
);

// ── Optional fields ───────────────────────────────────────────────────────────
$title    = mb_substr(trim((string) ($body['title']    ?? '')), 0, 120);
$deviceId = trim((string) ($body['device_id']          ?? ''));
$email    = trim((string) ($body['owner_email']        ?? ''));
$rawSlug  = trim((string) ($body['slug']               ?? ''));

// Validate optional email.
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid owner_email.']);
    exit;
}

// ── Resolve slug ──────────────────────────────────────────────────────────────
$repo = new SavedDashboardRepository(Connection::get());

if ($rawSlug !== '') {
    $slug = SavedDashboardRepository::sanitiseSlug($rawSlug);
    if ($slug === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Slug must be 3–80 chars, letters/digits/hyphens only.']);
        exit;
    }
} else {
    // Auto-generate a unique slug.
    $slug     = SavedDashboardRepository::generateSlug(8);
    $attempts = 0;
    while ($repo->slugExists($slug) && $attempts < 10) {
        $slug = SavedDashboardRepository::generateSlug(8);
        $attempts++;
    }
    if ($repo->slugExists($slug)) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not generate unique slug. Please try again.']);
        exit;
    }
}

// ── Upsert ────────────────────────────────────────────────────────────────────
try {
    $stored = $repo->upsert(
        slug:       $slug,
        title:      $title,
        stateJson:  $stateJson,
        ownerEmail: $email !== '' ? $email : null,
        deviceId:   $deviceId !== '' ? $deviceId : null,
    );
} catch (\RuntimeException $e) {
    http_response_code(409);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error.']);
    exit;
}

// ── Return shareable URL ──────────────────────────────────────────────────────
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$url  = $base . '/../d.php?s=' . urlencode($stored);

echo json_encode(['slug' => $stored, 'url' => $url], JSON_UNESCAPED_UNICODE);
