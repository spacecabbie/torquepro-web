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

// ── Config flags ────────────────────────────────────────────────────────────
$show_session_length  = defined('SHOW_SESSION_LENGTH')  ? SHOW_SESSION_LENGTH  : false;
$hide_empty_variables = defined('HIDE_EMPTY_VARIABLES') ? HIDE_EMPTY_VARIABLES : false;
$timezone             = defined('TIMEZONE')             ? TIMEZONE             : 'UTC';

// ── Session actions (delete / merge) ────────────────────────────────────────
$manager = new SessionManager($pdo);

// Load all sessions first so we have the full SID list for merge validation.
$sessionRepo = new SessionRepository($pdo);
$sessionData = $sessionRepo->findAll();
$sids        = $sessionData['sids'];
$seshdates   = $sessionData['dates'];
$seshsizes   = $sessionData['sizes'];

if (isset($_POST['action'])) {
    if ($_POST['action'] === 'delete' && isset($_POST['id'])) {
        $sid = preg_replace('/\D/', '', $_POST['id']);
        if ($sid !== '') {
            $manager->delete($sid);
        }
    } elseif ($_POST['action'] === 'merge' && isset($_POST['into'], $_POST['with'])) {
        $into = preg_replace('/\D/', '', $_POST['into']);
        $with = preg_replace('/\D/', '', $_POST['with']);
        if ($into !== '' && $with !== '') {
            $manager->merge($into, $with, $sids);
        }
    }
    // Reload after mutation.
    $sessionData = $sessionRepo->findAll();
    $sids        = $sessionData['sids'];
    $seshdates   = $sessionData['dates'];
    $seshsizes   = $sessionData['sizes'];
}

// ── Active session ───────────────────────────────────────────────────────────
if (isset($_POST['id'])) {
    $session_id = preg_replace('/\D/', '', $_POST['id']);
} elseif (isset($_GET['id'])) {
    $session_id = preg_replace('/\D/', '', $_GET['id']);
}

$hasSession  = isset($session_id) && $session_id !== '';
$imapdata    = GpsRepository::DEFAULT_MAP_DATA;
$geolocs     = [];

// ── Column metadata ──────────────────────────────────────────────────────────
$colRepo      = new ColumnRepository($pdo);
$coldata      = $colRepo->findPlottable();

// ── GPS track ────────────────────────────────────────────────────────────────
if ($hasSession) {
    $idx             = array_search($session_id, $sids, true);
    $session_id_next = ($idx !== false && $idx > 0) ? $sids[$idx - 1] : false;

    $gpsRepo  = new GpsRepository($pdo);
    $geolocs  = $gpsRepo->findTrack($session_id);
    if (!empty($geolocs)) {
        $pts      = array_map(fn($d) => '[' . $d['lat'] . ',' . $d['lon'] . ']', $geolocs);
        $imapdata = '[' . implode(',', $pts) . ']';
    }
}

// ── Plot data (v1 / v2) ──────────────────────────────────────────────────────
$v1 = $_GET['v1'] ?? '';
$v2 = $_GET['v2'] ?? '';

$plotRepo   = new PlotRepository($pdo);
$plotResult = $hasSession
    ? $plotRepo->load($session_id, $sids, $coldata, $v1, $v2)
    : null;

$v1_label  = $plotResult['v1_label']  ?? '';
$v2_label  = $plotResult['v2_label']  ?? '';
$d1        = $plotResult['d1']        ?? [];
$d2        = $plotResult['d2']        ?? [];
$sparkdata1 = $plotResult['sparkdata1'] ?? [];
$sparkdata2 = $plotResult['sparkdata2'] ?? [];
$avg1      = $plotResult['avg1']      ?? null;
$avg2      = $plotResult['avg2']      ?? null;

// ── Empty column filter ──────────────────────────────────────────────────────
$coldataempty = [];
if ($hasSession && $hide_empty_variables) {
    $coldataempty = $colRepo->findEmpty($session_id, $coldata);
}

$_SESSION['recent_session_id'] = !empty($sids) ? strval(max($sids)) : '';

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.8/css/bootstrap.min.css">
    <!-- Leaflet -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <!-- Chosen -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.min.css">
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
        .stat-card { border-radius: 10px; padding: .9rem 1rem; color: #fff; }
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
                    <?php if ($dateid == ($session_id ?? '')) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($datestr);
                          if ($show_session_length) echo $seshsizes[$dateid]; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-primary btn-sm w-100 mt-1" type="submit">Go</button></noscript>
    </form>

    <?php if ($hasSession): ?>
    <div class="section-title">Actions</div>
    <div class="d-grid gap-1">
        <form method="post"
              action="dashboard.php?mergesession=<?php echo $session_id; ?>&mergesessionwith=<?php echo $session_id_next; ?>"
              id="form-merge">
            <button class="btn btn-sm btn-outline-secondary w-100"
                    type="submit" <?php if (!$session_id_next) echo 'disabled'; ?>>
                Merge with next
            </button>
        </form>
        <form method="post" action="dashboard.php?deletesession=<?php echo $session_id; ?>"
              id="form-delete">
            <button class="btn btn-sm btn-outline-danger w-100" type="submit">
                Delete session
            </button>
        </form>
    </div>
    <script>
    document.getElementById('form-merge')?.addEventListener('submit', e => {
        if (!confirm('Merge sessions "<?php echo addslashes($seshdates[$session_id]); ?>" and "<?php echo $session_id_next ? addslashes($seshdates[$session_id_next]) : ''; ?>"?')) e.preventDefault();
    });
    document.getElementById('form-delete')?.addEventListener('submit', e => {
        if (!confirm('Delete session "<?php echo addslashes($seshdates[$session_id]); ?>"?')) e.preventDefault();
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
                    <?php if ($hide_empty_variables && $empty) echo 'hidden'; ?>>
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
                    <?php if ($hide_empty_variables && $empty) echo 'hidden'; ?>>
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

        <?php if ($hasSession && isset($avg1)): ?>
        <div class="col-6 col-lg-3">
            <div class="stat-card bg-speed">
                <div class="stat-label"><?php echo strip_tags(substr($v1_label, 1, -1)); ?></div>
                <div class="stat-value"><?php echo $avg1; ?></div>
                <div class="stat-sub">avg &nbsp;·&nbsp; <?php echo $min1; ?> – <?php echo $max1; ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card bg-temp">
                <div class="stat-label"><?php echo strip_tags(substr($v2_label, 1, -1)); ?></div>
                <div class="stat-value"><?php echo $avg2; ?></div>
                <div class="stat-sub">avg &nbsp;·&nbsp; <?php echo $min2; ?> – <?php echo $max2; ?></div>
            </div>
        </div>
        <?php else: ?>
        <div class="col-6 col-lg-3">
            <div class="stat-card bg-speed">
                <div class="stat-label">Variable 1</div>
                <div class="stat-value">—</div>
                <div class="stat-sub">select a session</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card bg-temp">
                <div class="stat-label">Variable 2</div>
                <div class="stat-value">—</div>
                <div class="stat-sub">select a session</div>
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
                <?php if ($hasSession && isset($avg1)): ?>
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
                            <div class="mt-2">Select a session to see statistics</div>
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
                            <div class="mt-2">Select a session and variables to plot</div>
                        </div>
                    </div>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</main>

<!-- ══════════════════════════════════ SCRIPTS ═══════════════════════════════ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.14.1/jquery-ui.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.8/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
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
    if ("<?php echo $timezone; ?>".length === 0) {
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
