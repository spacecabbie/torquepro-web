<?php
declare(strict_types=1);

/**
 * dashboard.php — Main UI entry point.
 *
 * Bootstraps auth, loads data via repository classes, then renders the HTML view.
 * All business logic lives in includes/; this file only wires dependencies together.
 *
 * Origin: dashboard.php (updated for OOP migration — Step 4)
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Auth/Auth.php';
require_once __DIR__ . '/includes/Database/Connection.php';
require_once __DIR__ . '/includes/Helpers/DataHelper.php';
require_once __DIR__ . '/includes/Data/SessionRepository.php';
require_once __DIR__ . '/includes/Data/ColumnRepository.php';
require_once __DIR__ . '/includes/Data/GpsRepository.php';
require_once __DIR__ . '/includes/Data/PlotRepository.php';
require_once __DIR__ . '/includes/Session/SessionManager.php';

use TorqueLogs\Auth\Auth;
use TorqueLogs\Database\Connection;
use TorqueLogs\Data\SessionRepository;
use TorqueLogs\Data\ColumnRepository;
use TorqueLogs\Data\GpsRepository;
use TorqueLogs\Data\PlotRepository;
use TorqueLogs\Session\SessionManager;

// ── Inline endpoint: set timezone preference ────────────────────────────────
if (isset($_GET['settz'])) {
    Auth::checkBrowser();
    if (isset($_GET['time'])) {
        $_SESSION['time'] = $_GET['time'];
    }
    exit;
}

// ── Auth guard ──────────────────────────────────────────────────────────────
Auth::checkBrowser();

$pdo = Connection::get();

// ── Timezone from session ──────────────────────────────────────────────────
$timezone = $_SESSION['time'] ?? '';

// ── Session list ───────────────────────────────────────────────────────────
$sessionRepo = new SessionRepository($pdo);
$sessionData = $sessionRepo->findAll();
$sids        = $sessionData['sids'];
$seshdates   = $sessionData['dates'];
$seshsizes   = $sessionData['sizes'];

// ── Resolve requested session ID ───────────────────────────────────────────
$session_id = '';
if (isset($_POST['id'])) {
    $session_id = preg_replace('/\D/', '', $_POST['id']) ?? '';
} elseif (isset($_GET['id'])) {
    $session_id = preg_replace('/\D/', '', $_GET['id']) ?? '';
}
$hasSession = $session_id !== '';

// ── Delete action  (origin: del_session.php) ───────────────────────────────
// Forms pass the session ID as a POST hidden input.
$manager  = new SessionManager($pdo);
$deleteId = '';
if (isset($_POST['deletesession'])) {
    $deleteId = preg_replace('/\D/', '', $_POST['deletesession']) ?? '';
}
if ($deleteId !== '') {
    $manager->delete($deleteId);
    $sessionData = $sessionRepo->findAll();
    $sids        = $sessionData['sids'];
    $seshdates   = $sessionData['dates'];
    $seshsizes   = $sessionData['sizes'];
    $session_id  = '';
    $hasSession  = false;
}

// ── Merge action  (origin: merge_sessions.php) ─────────────────────────────
$mergeId     = '';
$mergeWithId = '';
if (isset($_POST['mergesession'])) {
    $mergeId = preg_replace('/\D/', '', $_POST['mergesession']) ?? '';
}
if (isset($_POST['mergesessionwith'])) {
    $mergeWithId = preg_replace('/\D/', '', $_POST['mergesessionwith']) ?? '';
}
if ($mergeId !== '' && $mergeWithId !== '') {
    $mergedId = $manager->merge($mergeId, $mergeWithId, $sids);
    if ($mergedId !== null) {
        $sessionData = $sessionRepo->findAll();
        $sids        = $sessionData['sids'];
        $seshdates   = $sessionData['dates'];
        $seshsizes   = $sessionData['sizes'];
        $session_id  = $mergedId;
        $hasSession  = true;
    }
}

// ── Column metadata ────────────────────────────────────────────────────────
$colRepo      = new ColumnRepository($pdo);
$coldata      = $colRepo->findPlottable();
$coldataempty = $hasSession ? $colRepo->findEmpty($session_id, $coldata) : [];

// ── GPS track ──────────────────────────────────────────────────────────────
$gpsRepo = new GpsRepository($pdo);
$gpsData = $hasSession
    ? $gpsRepo->findTrack($session_id)
    : ['points' => [], 'mapdata' => GpsRepository::DEFAULT_MAP_DATA];

$geolocs  = $gpsData['points'];
$imapdata = $gpsData['mapdata'];

// ── Chart data + statistics ────────────────────────────────────────────────
$plotRepo = new PlotRepository($pdo);
$plotData = $hasSession
    ? $plotRepo->load(
        $session_id,
        $sids,
        $coldata,
        $_GET['s1'] ?? null,
        $_GET['s2'] ?? null
    )
    : null;

$hasPlotData = ($plotData !== null && !($plotData['no_data'] ?? false));

// Flatten plot variables for the view (preserving original variable names).
$v1          = $plotData['v1']        ?? 'kd';
$v2          = $plotData['v2']        ?? 'kf';
$v1_label    = $plotData['v1Label']   ?? '"Variable 1"';
$v2_label    = $plotData['v2Label']   ?? '"Variable 2"';
$d1          = $plotData['d1']        ?? [];
$d2          = $plotData['d2']        ?? [];
$sparkdata1  = $plotData['sparkdata1'] ?? '';
$sparkdata2  = $plotData['sparkdata2'] ?? '';
$avg1        = $plotData['avg1']       ?? 0;
$avg2        = $plotData['avg2']       ?? 0;
$min1        = $plotData['min1']       ?? 0;
$min2        = $plotData['min2']       ?? 0;
$max1        = $plotData['max1']       ?? 0;
$max2        = $plotData['max2']       ?? 0;
$pcnt25data1 = $plotData['pcnt25_1']  ?? 0;
$pcnt25data2 = $plotData['pcnt25_2']  ?? 0;
$pcnt75data1 = $plotData['pcnt75_1']  ?? 0;
$pcnt75data2 = $plotData['pcnt75_2']  ?? 0;

// ── Merge helper: find adjacent (younger) session ──────────────────────────
$session_id_next = false;
if ($hasSession) {
    $idx = array_search($session_id, $sids, true);
    $session_id_next = ($idx !== false && $idx > 0) ? $sids[$idx - 1] : false;
}

// ── Session label for the page header ──────────────────────────────────────
$sessionLabel = ($hasSession && isset($seshdates[$session_id]))
    ? $seshdates[$session_id]
    : 'No session selected';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Torque Dashboard</title>

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.8/css/bootstrap.min.css" integrity="sha512-2bBQCjcnw658Lho4nlXJcc6WkV/UxpE/sAokbXPxQNGqmNdQrWqtw26Ns9kFF/yG792pKR1Sx8/Y1Lf1XN4GKA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <!-- Leaflet -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" integrity="sha512-h9FcoyWjHcOcmEVkxOfTLnmZFWIH0iZhZT1H2TbOq55xssQGEJHEaIm+PgoUaZbRvQTNTluNOEfb1ZRy6D3BOw==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <!-- Chosen -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.min.css" integrity="sha512-yVvxUQV0QESBt1SyZbNJMAwyKvFTLMyXSyBHDO4BG5t7k/Lw34tyqlSDlKIrIENIzCl+RVUNjmCPG+V/GMesRw==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap">

    <style>
        :root {
            --sidebar-width: 260px;
            --navbar-h: 54px;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f5;
            color: #212529;
        }

        /* ── Navbar ── */
        .navbar { height: var(--navbar-h); background: #1a1a2e !important; }
        .navbar-brand { font-weight: 600; letter-spacing: .03em; }
        .navbar-brand span { color: #e94560; }

        /* ── Sidebar ── */
        #sidebar {
            position: fixed;
            top: var(--navbar-h);
            left: 0;
            width: var(--sidebar-width);
            height: calc(100vh - var(--navbar-h));
            background: #fff;
            border-right: 1px solid #dee2e6;
            overflow-y: auto;
            padding: 1.25rem 1rem;
            z-index: 100;
        }
        #sidebar .section-title {
            font-size: .7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #6c757d;
            margin: 1.2rem 0 .4rem;
        }
        #sidebar .nav-link {
            color: #343a40;
            padding: .35rem .5rem;
            border-radius: 6px;
            font-size: .875rem;
        }
        #sidebar .nav-link:hover { background: #f0f2f5; }
        #sidebar .nav-link.active { background: #e8eaf6; color: #3949ab; font-weight: 600; }

        /* ── Main ── */
        #main {
            margin-left: var(--sidebar-width);
            padding: calc(var(--navbar-h) + 1.5rem) 1.5rem 2rem;
            min-height: 100vh;
        }

        /* ── Cards ── */
        .card { border: none; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .card-header {
            background: transparent;
            border-bottom: 1px solid #f0f0f0;
            font-weight: 600;
            font-size: .875rem;
            padding: .75rem 1rem;
        }
        .card-body { padding: 1rem; }

        /* ── Stat cards ── */
        .stat-card { border-radius: 10px; padding: .9rem 1rem; color: #fff; height: 100%; }
        .stat-card .stat-label { font-size: .72rem; opacity: .85; font-weight: 500; text-transform: uppercase; letter-spacing: .05em; }
        .stat-card .stat-value { font-size: 1.5rem; font-weight: 700; line-height: 1.2; }
        .stat-card .stat-sub   { font-size: .78rem; opacity: .8; }
        .bg-speed   { background: linear-gradient(135deg, #1a73e8, #0d47a1); }
        .bg-temp    { background: linear-gradient(135deg, #e94560, #b71c1c); }
        .bg-session { background: linear-gradient(135deg, #0f9d58, #1b5e20); }
        .bg-gps     { background: linear-gradient(135deg, #f4b400, #e65100); }

        /* ── Map ── */
        #map-canvas { height: 300px; border-radius: 8px; }

        /* ── Chart ── */
        #placeholder { height: 280px; }

        /* ── Sparkline ── */
        span.line { font-size: .01px; }

        /* ── Variable selector ── */
        .chosen-container { width: 100% !important; }

        /* ── Responsive ── */
        @media (max-width: 767px) {
            #sidebar { display: none; }
            #main    { margin-left: 0; }
        }
    </style>
</head>
<body>

<!-- ═══════════════════════════════════ NAVBAR ═══════════════════════════════ -->
<nav class="navbar navbar-dark fixed-top px-3 d-flex align-items-center justify-content-between">
    <a class="navbar-brand mb-0 h1" href="dashboard.php">
        <span>Torque</span> Dashboard
    </a>
    <div class="d-flex gap-2">
        <a href="live_log.php" class="btn btn-sm btn-outline-light">⚡ Live Monitor</a>
    </div>
</nav>

<!-- ══════════════════════════════════ SIDEBAR ═══════════════════════════════ -->
<aside id="sidebar">

    <div class="section-title">Session</div>
    <form method="post" action="dashboard.php" id="session-form">
        <select id="seshidtag" name="id" class="chosen-select form-select form-select-sm"
                onchange="this.form.submit()"
                data-placeholder="Pick a session…">
            <option value=""></option>
            <?php foreach ($seshdates as $dateid => $datestr): ?>
                <option value="<?php echo $dateid; ?>"
                    <?php if ($dateid === ($session_id ?? '')) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($datestr);
                          if (SHOW_SESSION_LENGTH) echo $seshsizes[$dateid]; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-primary btn-sm w-100 mt-1" type="submit">Go</button></noscript>
    </form>

    <?php if ($hasSession): ?>
    <div class="section-title">Actions</div>
    <div class="d-grid gap-1">
        <form method="post"
              action="dashboard.php"
              id="form-merge">
            <input type="hidden" name="mergesession" value="<?php echo htmlspecialchars($session_id, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="mergesessionwith" value="<?php echo htmlspecialchars((string)$session_id_next, ENT_QUOTES, 'UTF-8'); ?>">
            <button class="btn btn-sm btn-outline-secondary w-100"
                    type="submit" <?php if (!$session_id_next) echo 'disabled'; ?>>
                Merge with next
            </button>
        </form>
        <form method="post" action="dashboard.php"
              id="form-delete">
            <input type="hidden" name="deletesession" value="<?php echo htmlspecialchars($session_id, ENT_QUOTES, 'UTF-8'); ?>">
            <button class="btn btn-sm btn-outline-danger w-100" type="submit">
                Delete session
            </button>
        </form>
    </div>
    <script>
    document.getElementById('form-merge')?.addEventListener('submit', e => {
        if (!confirm(<?php echo json_encode('Merge sessions "' . ($seshdates[$session_id] ?? '') . '" and "' . ($session_id_next ? ($seshdates[$session_id_next] ?? '') : '') . '"?'); ?>)) e.preventDefault();
    });
    document.getElementById('form-delete')?.addEventListener('submit', e => {
        if (!confirm(<?php echo json_encode('Delete session "' . ($seshdates[$session_id] ?? '') . '"?'); ?>)) e.preventDefault();
    });
    </script>
    <?php endif; ?>

    <?php if ($hasSession): ?>
    <div class="section-title">Plot Variables</div>
    <form method="get" action="dashboard.php" id="form-plot">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($session_id); ?>">
        <select name="s1" class="form-select form-select-sm mb-1" title="Variable 1">
            <?php foreach ($coldata as $xcol):
                $empty = ($coldataempty[$xcol['colname']] ?? 0) == 1; ?>
                <option value="<?php echo $xcol['colname']; ?>"
                    <?php if (($xcol['colname'] === ($v1 ?? 'kd'))) echo 'selected'; ?>
                    <?php if (HIDE_EMPTY_VARIABLES && $empty) echo 'hidden'; ?>>
                    <?php echo htmlspecialchars($xcol['colcomment'] ?: $xcol['colname']); ?>
                    <?php if ($empty) echo ' [empty]'; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="s2" class="form-select form-select-sm mb-2" title="Variable 2">
            <?php foreach ($coldata as $xcol):
                $empty = ($coldataempty[$xcol['colname']] ?? 0) == 1; ?>
                <option value="<?php echo $xcol['colname']; ?>"
                    <?php if (($xcol['colname'] === ($v2 ?? 'kf'))) echo 'selected'; ?>
                    <?php if (HIDE_EMPTY_VARIABLES && $empty) echo 'hidden'; ?>>
                    <?php echo htmlspecialchars($xcol['colcomment'] ?: $xcol['colname']); ?>
                    <?php if ($empty) echo ' [empty]'; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary btn-sm w-100" type="submit">Update Chart</button>
    </form>

    <div class="section-title">Export</div>
    <div class="d-grid gap-1">
        <a class="btn btn-sm btn-outline-secondary"
           href="./export.php?sid=<?php echo $session_id; ?>&filetype=csv">CSV</a>
        <a class="btn btn-sm btn-outline-secondary"
           href="./export.php?sid=<?php echo $session_id; ?>&filetype=json">JSON</a>
    </div>
    <?php endif; ?>

</aside>

<!-- ═══════════════════════════════════ MAIN ════════════════════════════════ -->
<main id="main">

    <!-- Page title row -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h5 class="mb-0 fw-semibold">Session Overview</h5>
            <small class="text-muted"><?php echo htmlspecialchars($sessionLabel); ?></small>
        </div>
    </div>

    <!-- ── Stat cards ── -->
    <div class="row g-3 mb-3">

        <?php if ($hasPlotData): ?>
        <div class="col-6 col-lg-3">
            <div class="stat-card bg-speed">
                <div class="stat-label"><?php echo htmlspecialchars(substr($v1_label, 1, -1), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="stat-value"><?php echo $avg1; ?></div>
                <div class="stat-sub">avg &nbsp;·&nbsp; <?php echo $min1; ?> – <?php echo $max1; ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card bg-temp">
                <div class="stat-label"><?php echo htmlspecialchars(substr($v2_label, 1, -1), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="stat-value"><?php echo $avg2; ?></div>
                <div class="stat-sub">avg &nbsp;·&nbsp; <?php echo $min2; ?> – <?php echo $max2; ?></div>
            </div>
        </div>
        <?php else: ?>
        <div class="col-6 col-lg-3">
            <div class="stat-card bg-speed">
                <div class="stat-label">Variable 1</div>
                <div class="stat-value">—</div>
                <div class="stat-sub"><?php echo ($hasSession && ($plotData['no_data'] ?? false)) ? 'no data' : 'select a session'; ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card bg-temp">
                <div class="stat-label">Variable 2</div>
                <div class="stat-value">—</div>
                <div class="stat-sub"><?php echo ($hasSession && ($plotData['no_data'] ?? false)) ? 'no data' : 'select a session'; ?></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-6 col-lg-3">
            <div class="stat-card bg-session">
                <div class="stat-label">GPS Points</div>
                <div class="stat-value"><?php echo count($geolocs); ?></div>
                <div class="stat-sub">recorded in session</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card bg-gps">
                <div class="stat-label">Total Sessions</div>
                <div class="stat-value"><?php echo count($sids); ?></div>
                <div class="stat-sub">in database</div>
            </div>
        </div>
    </div>

    <!-- ── Map + Data Summary row ── -->
    <div class="row g-3 mb-3">

        <!-- Map -->
        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header">GPS Track</div>
                <div class="card-body p-2">
                    <div id="map-canvas"></div>
                </div>
            </div>
        </div>

        <!-- Data summary -->
        <div class="col-12 col-lg-7">
            <div class="card h-100">
                <div class="card-header">Data Summary</div>
                <div class="card-body">
                <?php if ($hasPlotData): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Variable</th>
                                    <th>Min</th>
                                    <th>Max</th>
                                    <th>P25</th>
                                    <th>P75</th>
                                    <th>Mean</th>
                                    <th>Sparkline</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars(substr($v1_label, 1, -1)); ?></td>
                                    <td><?php echo $min1; ?></td>
                                    <td><?php echo $max1; ?></td>
                                    <td><?php echo $pcnt25data1; ?></td>
                                    <td><?php echo $pcnt75data1; ?></td>
                                    <td><?php echo $avg1; ?></td>
                                    <td><span class="line"><?php echo $sparkdata1; ?></span></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars(substr($v2_label, 1, -1)); ?></td>
                                    <td><?php echo $min2; ?></td>
                                    <td><?php echo $max2; ?></td>
                                    <td><?php echo $pcnt25data2; ?></td>
                                    <td><?php echo $pcnt75data2; ?></td>
                                    <td><?php echo $avg2; ?></td>
                                    <td><span class="line"><?php echo $sparkdata2; ?></span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="d-flex align-items-center justify-content-center h-100 text-muted" style="min-height:180px">
                        <div class="text-center">
                            <div style="font-size:2rem">📊</div>
                            <div class="mt-2"><?php echo ($hasSession && ($plotData['no_data'] ?? false)) ? 'No sensor data for selected variables' : 'Select a session to see statistics'; ?></div>
                        </div>
                    </div>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Chart ── -->
    <div class="row g-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Chart</span>
                    <?php if ($hasSession && isset($v1_label, $v2_label)): ?>
                    <span class="text-muted fw-normal" style="font-size:.8rem">
                        <?php echo htmlspecialchars(substr($v1_label, 1, -1)); ?>
                        &nbsp;vs&nbsp;
                        <?php echo htmlspecialchars(substr($v2_label, 1, -1)); ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                <?php if ($hasSession && !empty($d1) && !empty($d2)): ?>
                    <div id="placeholder" class="w-100"></div>
                <?php else: ?>
                    <div class="d-flex align-items-center justify-content-center text-muted" style="height:280px">
                        <div class="text-center">
                            <div style="font-size:2rem">📈</div>
                            <div class="mt-2"><?php echo ($hasSession && ($plotData['no_data'] ?? false)) ? 'No sensor data for selected variables' : 'Select a session and variables to plot'; ?></div>
                        </div>
                    </div>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</main>

<!-- ══════════════════════════════════ SCRIPTS ═══════════════════════════════ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.14.1/jquery-ui.min.js" integrity="sha512-MSOo1aY+3pXCOCdGAYoBZ6YGI0aragoQsg1mKKBHXCYPIWxamwOE7Drh+N5CPgGI5SA9IEKJiPjdfqWFWmZtRA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.8/js/bootstrap.bundle.min.js" integrity="sha512-HvOjJrdwNpDbkGJIG2ZNqDlVqMo77qbs4Me4cah0HoDrfhrbA+8SBlZn1KrvAQw7cILLPFJvdwIgphzQmMm+Pw==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.jquery.min.js" integrity="sha512-rMGGF4wg1R73ehtnxXBt5mbUfN9JUJwbk21KMlnLZDJh7BkPmeovBuddZCENJddHYYMkCh9hPFnPmS9sspki8g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js" integrity="sha512-puJW3E/qXDqYp9IfhAI54BJEaWIfloJ7JWs7OeD5i6ruC9JZL1gERT1wjtwXFlh7CjE7ZJ+/vcRZRkIYIb6p4g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="static/js/jquery.peity.min.js"></script>

<?php if ($hasSession && !empty($d1) && !empty($d2)): ?>
<script src="static/js/jquery.flot.js"></script>
<script src="static/js/jquery.flot.time.js"></script>
<script src="static/js/jquery.flot.axislabels.js"></script>
<script src="static/js/jquery.flot.tooltip.min.js"></script>
<script src="static/js/jquery.flot.resize.min.js"></script>
<script src="static/js/jquery.flot.selection.js"></script>
<script src="static/js/torquehelpers.js"></script>
<?php endif; ?>

<!-- Timezone detection (runs once if timezone not set) -->
<script>
$(function() {
    if (<?php echo json_encode($timezone); ?>.length === 0) {
        var tz   = "GMT " + -(new Date().getTimezoneOffset() / 60);
        var tzurl = location.pathname + '?settz=1';
        $.get(tzurl, {time: tz}, function() { location.reload(); });
    }
});
</script>

<!-- Chosen: session picker -->
<script>
$(function() { $('.chosen-select').chosen({ width: '100%', disable_search_threshold: 5 }); });
</script>

<!-- Leaflet map -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var path = <?php echo $imapdata; ?>;
    var map  = L.map('map-canvas');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19
    }).addTo(map);

    <?php if ($hasSession && !empty($geolocs)): ?>
    var poly = L.polyline(path, { color: '#e94560', opacity: 0.85, weight: 4 }).addTo(map);
    map.fitBounds(poly.getBounds());
    <?php else: ?>
    map.setView(path[0], 16);
    L.marker(path[0]).addTo(map)
        .bindPopup('<div style="text-align:center">Select a session<br>to see a GPS track.</div>')
        .openPopup();
    <?php endif; ?>
});
</script>

<!-- Flot chart -->
<?php if ($hasSession && !empty($d1) && !empty($d2)): ?>
<script>
$(function () {
    var s1 = [<?php foreach ($d1 as $b) { echo '[' . $b[0] . ',' . $b[1] . '],'; } ?>];
    var s2 = [<?php foreach ($d2 as $d) { echo '[' . $d[0] . ',' . $d[1] . '],'; } ?>];

    $.plot('#placeholder', [
        { data: s1, label: <?php echo $v1_label; ?> },
        { data: s2, label: <?php echo $v2_label; ?>, yaxis: 2 }
    ], {
        xaxes: [{
            mode: 'time',
            timezone: 'browser',
            axisLabel: 'Time',
            timeformat: '%H:%M',
            twelveHourClock: false
        }],
        yaxes: [
            { axisLabel: <?php echo $v1_label; ?> },
            { alignTicksWithAxis: 1, position: 'right', axisLabel: <?php echo $v2_label; ?> }
        ],
        legend: { position: 'nw' },
        grid: {
            borderWidth: 0,
            hoverable: true,
            clickable: true
        },
        tooltip: true,
        tooltipOpts: {
            content:    '%s: %y',
            xDateFormat: '%H:%M:%S',
            onHover: function (flotItem, $tooltipEl) { $tooltipEl.css('font-size', '12px'); }
        }
    });
});
</script>
<?php endif; ?>

<!-- Sparklines -->
<?php if ($hasSession && isset($sparkdata1, $sparkdata2)): ?>
<script>
$(function() { $('span.line').peity('line', { width: 80, height: 24 }); });
</script>
<?php endif; ?>

</body>
</html>
