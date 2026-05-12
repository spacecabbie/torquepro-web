<?php
declare(strict_types=1);

/**
 * dashboard.php — Automotive Sensor Analysis Workbench.
 *
 * Steps 5+6: Top bar replaces sidebar; CSS Grid panel shell with per-panel
 * sensor selection, colspan/rowspan, and URL-encoded state.
 * Charts are wired in Step 7. Data summary table uses SummaryRepository.
 *
 * State model: everything lives in the URL query string.
 *   ?id=SESSION_ID
 *   &grid=RxC           (e.g. 2x3 = 2 rows, 3 cols)
 *   &p[N][s][]=SENSOR   (panel N, sensor key — array supports future multi-sensor)
 *   &p[N][cs]=INT       (colspan, default 1)
 *   &p[N][rs]=INT       (rowspan, default 1)
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Auth/Auth.php';
require_once __DIR__ . '/includes/Database/Connection.php';
require_once __DIR__ . '/includes/Helpers/DataHelper.php';
require_once __DIR__ . '/includes/Data/SessionRepository.php';
require_once __DIR__ . '/includes/Data/ColumnRepository.php';
require_once __DIR__ . '/includes/Data/GpsRepository.php';
require_once __DIR__ . '/includes/Data/SummaryRepository.php';
require_once __DIR__ . '/includes/Session/SessionManager.php';

use TorqueLogs\Auth\Auth;
use TorqueLogs\Database\Connection;
use TorqueLogs\Data\SessionRepository;
use TorqueLogs\Data\ColumnRepository;
use TorqueLogs\Data\GpsRepository;
use TorqueLogs\Data\SummaryRepository;
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

$pdo      = Connection::get();
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

// ── Delete action ──────────────────────────────────────────────────────────
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

// ── Merge action ───────────────────────────────────────────────────────────
$mergeId     = '';
$mergeWithId = '';
if (isset($_POST['mergesession']))     { $mergeId     = preg_replace('/\D/', '', $_POST['mergesession'])     ?? ''; }
if (isset($_POST['mergesessionwith'])) { $mergeWithId = preg_replace('/\D/', '', $_POST['mergesessionwith']) ?? ''; }
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

// ── Column / sensor metadata ───────────────────────────────────────────────
$colRepo = new ColumnRepository($pdo);
$coldata = $hasSession
    ? $colRepo->findForSession($session_id)
    : $colRepo->findPlottable();

// ── GPS track ──────────────────────────────────────────────────────────────
$gpsRepo = new GpsRepository($pdo);
$gpsData = $hasSession
    ? $gpsRepo->findTrack($session_id)
    : ['points' => [], 'mapdata' => GpsRepository::DEFAULT_MAP_DATA];
$geolocs  = $gpsData['points'];
$imapdata = $gpsData['mapdata'];

// ── Grid config from URL ───────────────────────────────────────────────────
$gridParam = $_GET['grid'] ?? '2x3';
if (!preg_match('/^([1-6])x([1-6])$/', $gridParam, $gm)) {
    $gridParam = '2x3';
    $gm        = [null, '2', '3'];
}
$gridRows = (int) $gm[1];
$gridCols = (int) $gm[2];

// ── Panel config from URL ──────────────────────────────────────────────────
$panelsRaw  = (isset($_GET['p']) && is_array($_GET['p'])) ? $_GET['p'] : [];
$panelCount = $gridRows * $gridCols;
$panels     = [];
for ($i = 0; $i < $panelCount; $i++) {
    $raw     = (isset($panelsRaw[$i]) && is_array($panelsRaw[$i])) ? $panelsRaw[$i] : [];
    $rawKeys = (isset($raw['s']) && is_array($raw['s'])) ? array_map('strval', $raw['s']) : [];
    $keys    = array_values(array_filter(
        $rawKeys,
        static fn(string $k): bool => (bool) preg_match('/^[a-zA-Z0-9_]{1,40}$/', $k)
    ));
    $cs = max(1, min($gridCols, (int) ($raw['cs'] ?? 1)));
    $rs = max(1, min($gridRows, (int) ($raw['rs'] ?? 1)));
    $panels[] = [
        'sensor'  => $keys[0] ?? '',
        'sensors' => $keys,
        'cs'      => $cs,
        'rs'      => $rs,
    ];
}

// ── Summary data ───────────────────────────────────────────────────────────
$summaryRepo = new SummaryRepository($pdo);
$summaryRows = $hasSession ? $summaryRepo->findForSession($session_id) : [];

// ── Merge helper ───────────────────────────────────────────────────────────
$session_id_next = false;
if ($hasSession) {
    $sidx            = array_search($session_id, $sids, true);
    $session_id_next = ($sidx !== false && $sidx > 0) ? $sids[$sidx - 1] : false;
}

// ── Session label ──────────────────────────────────────────────────────────
$sessionLabel = ($hasSession && isset($seshdates[$session_id]))
    ? $seshdates[$session_id]
    : null;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Torque Logs — Workbench</title>
<link rel="stylesheet" href="static/css/bootstrap.min.css">
<link rel="stylesheet" href="static/css/chosen.min.css">
<link rel="stylesheet" href="static/css/uplot.min.css">

<link rel="stylesheet" href="static/css/dashboard.css">

</head>
<body>

<!-- ═══════════════════════════════════════════════════════════════ TOP BAR -->
<nav id="dwb-topbar">
    <span class="brand">⚙ Torque Logs</span>

    <!-- Session picker -->
    <div class="session-wrap">
        <select id="session-picker" name="id" data-placeholder="— Choose a session —">
            <option value=""></option>
            <?php foreach ($sids as $sid): ?>
                <option value="<?= htmlspecialchars((string) $sid, ENT_QUOTES) ?>"
                <?= ((string) $sid === (string) $session_id) ? 'selected' : '' ?>>
                <?= htmlspecialchars((string) ($seshdates[$sid] ?? $sid), ENT_QUOTES) ?>
                &nbsp;(<?= htmlspecialchars((string) ($seshsizes[$sid] ?? ''), ENT_QUOTES) ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Grid presets -->
    <div id="grid-presets">
        <?php foreach (['1x1','2x2','2x3','3x3','3x4'] as $preset): ?>
        <button class="grid-pill <?= ($gridParam === $preset) ? 'active' : '' ?>"
                data-preset="<?= $preset ?>">
            <?= htmlspecialchars($preset, ENT_QUOTES) ?>
        </button>
        <?php endforeach; ?>
    </div>

    <?php if ($hasSession): ?>
    <button id="sync-cursor-toggle"
            class="grid-pill sync-pill active"
            type="button"
            aria-pressed="true"
            title="Synchronize chart cursors across visible panels">
        Sync On
    </button>
    <button id="range-reset"
            class="grid-pill range-preset active"
            type="button"
            data-range-preset="full"
            title="Reset all chart time ranges">
        Full
    </button>
    <button class="grid-pill range-preset"
            type="button"
            data-range-seconds="60"
            title="Show the last minute across all charts">
        1m
    </button>
    <button class="grid-pill range-preset"
            type="button"
            data-range-seconds="300"
            title="Show the last five minutes across all charts">
        5m
    </button>
    <button class="grid-pill range-preset"
            type="button"
            data-range-seconds="600"
            title="Show the last ten minutes across all charts">
        10m
    </button>
    <?php endif; ?>

    <!-- Right actions -->
    <div class="topbar-right">
        <a href="adminer.php"
           class="btn btn-sm btn-outline-secondary"
           target="_blank"
           rel="noopener">
            DB
        </a>

        <?php if ($hasSession && count($geolocs) > 0): ?>
        <button id="btn-map"
                class="btn btn-sm btn-outline-secondary"
                data-toggle="modal" data-target="#mapModal">
            🗺 Map
        </button>
        <?php endif; ?>

        <?php if ($hasSession): ?>
        <button class="btn btn-sm btn-outline-warning"
                data-toggle="modal" data-target="#saveModal"
                title="Save this dashboard layout">
            ⭐ Save
        </button>
        <a href="export.php?sid=<?= urlencode($session_id) ?>&filetype=csv"
           class="btn btn-sm btn-outline-secondary">
            ⬇ CSV
        </a>
        <button class="btn btn-sm btn-outline-secondary"
                data-toggle="modal" data-target="#actionsModal">
            ⋮
        </button>
        <?php endif; ?>
    </div>
</nav>

<!-- ════════════════════════════════════════════════════════════ MAIN CANVAS -->
<div id="dwb-canvas">

    <?php if ($hasSession): ?>
    <div id="sync-inspector" class="sync-inspector is-empty" hidden>
        <div class="sync-inspector-time">Move across a chart to inspect synced sensor values.</div>
        <div class="sync-inspector-values"></div>
        <button id="pin-clear"
                class="sync-inspector-clear"
                type="button"
                hidden>
            Clear pin
        </button>
    </div>
    <?php endif; ?>

    <!-- Panel grid -->
    <div id="panel-grid"
         style="--grid-cols:<?= $gridCols ?>;"
         data-grid-cols="<?= $gridCols ?>"
         data-grid-rows="<?= $gridRows ?>">

        <?php for ($i = 0; $i < $panelCount; $i++):
            $p          = $panels[$i];
            $sensorKey  = $p['sensor'];
            $sensorKeys = array_values(array_slice($p['sensors'] ?? [], 0, 6));
            $cs         = $p['cs'];
            $rs         = $p['rs'];
            $hasPlot    = ($hasSession && count($sensorKeys) > 0);
            $colStyle   = ($cs > 1) ? "grid-column:span {$cs};" : '';
            $rowStyle   = ($rs > 1) ? "grid-row:span {$rs};"    : '';
        ?>
        <div class="dwb-panel" id="panel-<?= $i ?>"
             style="<?= $colStyle . $rowStyle ?>"
             data-panel-idx="<?= $i ?>"
             data-sensors="<?= htmlspecialchars(json_encode($sensorKeys), ENT_QUOTES) ?>"
             data-cs="<?= $cs ?>"
             data-rs="<?= $rs ?>">

            <!-- Panel header -->
            <div class="panel-header">
                <select class="panel-sensor-select" data-panel-idx="<?= $i ?>">
                    <option value="">— sensor —</option>
                    <?php foreach ($coldata as $col): ?>
                    <option value="<?= htmlspecialchars((string) ($col['key'] ?? ''), ENT_QUOTES) ?>"
                        data-unit="<?= htmlspecialchars((string) ($col['unit'] ?? ''), ENT_QUOTES) ?>"
                        <?= ((string) ($col['key'] ?? '') === (string) $sensorKey) ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) ($col['label'] ?? ''), ENT_QUOTES) ?>
                        <?php if (!empty($col['unit'])): ?>
                            [<?= htmlspecialchars((string) ($col['unit'] ?? ''), ENT_QUOTES) ?>]
                        <?php endif; ?>
                        <?php if (isset($col['sample_count'])): ?>
                            (<?= number_format((int) $col['sample_count']) ?> readings)
                        <?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button class="panel-add-sensor"
                        type="button"
                        data-panel-idx="<?= $i ?>"
                        title="Add selected sensor to this panel">
                    +
                </button>

                <!-- ⋮ panel menu -->
                <div class="dropdown">
                    <button class="panel-menu-btn"
                            data-toggle="dropdown" aria-expanded="false"
                            title="Panel options">⋮</button>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
                        <li><button class="dropdown-item"
                                    onclick="DWB.setPanelSpan(<?= $i ?>, 1, 0)">
                            ⟶ Wider
                        </button></li>
                        <li><button class="dropdown-item"
                                    onclick="DWB.setPanelSpan(<?= $i ?>, -1, 0)">
                            ⟵ Narrower
                        </button></li>
                        <li><button class="dropdown-item"
                                    onclick="DWB.setPanelSpan(<?= $i ?>, 0, 1)">
                            ⬇ Taller
                        </button></li>
                        <li><button class="dropdown-item"
                                    onclick="DWB.setPanelSpan(<?= $i ?>, 0, -1)">
                            ⬆ Shorter
                        </button></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><button class="dropdown-item text-danger"
                                    onclick="DWB.clearPanel(<?= $i ?>)">
                            ✕ Clear panel
                        </button></li>
                    </ul>
                </div>
            </div>

            <?php if (count($sensorKeys) > 0): ?>
            <div class="panel-sensor-chips">
                <?php foreach ($sensorKeys as $activeKey):
                    $activeMeta = null;
                    foreach ($coldata as $col) {
                        if ((string) ($col['key'] ?? '') === (string) $activeKey) {
                            $activeMeta = $col;
                            break;
                        }
                    }
                    $chipLabel = (string) ($activeMeta['label'] ?? $activeKey);
                    $chipUnit  = (string) ($activeMeta['unit'] ?? '');
                ?>
                <button class="panel-sensor-chip panel-remove-sensor"
                        type="button"
                        data-panel-idx="<?= $i ?>"
                        data-sensor-key="<?= htmlspecialchars((string) $activeKey, ENT_QUOTES) ?>"
                        data-sensor-unit="<?= htmlspecialchars($chipUnit, ENT_QUOTES) ?>"
                        title="Remove this sensor">
                    <span><?= htmlspecialchars($chipLabel, ENT_QUOTES) ?></span>
                    <?php if ($chipUnit !== ''): ?>
                    <small><?= htmlspecialchars($chipUnit, ENT_QUOTES) ?></small>
                    <?php endif; ?>
                    <b>×</b>
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Panel body -->
            <div class="panel-body">
                <?php if (!$hasSession): ?>
                <div class="panel-empty">
                    <div class="empty-icon">📂</div>
                    <p>Select a session</p>
                </div>
                <?php elseif ($sensorKey === ''): ?>
                <div class="panel-empty">
                    <div class="empty-icon">📊</div>
                    <p>Choose a sensor above</p>
                </div>
                <?php else: ?>
             <div class="panel-chart-area"
                 id="chart-<?= $i ?>"
                 data-sid="<?= htmlspecialchars((string) ($session_id ?? ''), ENT_QUOTES) ?>"
                 data-key="<?= htmlspecialchars((string) ($sensorKey ?? ''), ENT_QUOTES) ?>"
                 data-keys="<?= htmlspecialchars(json_encode($sensorKeys), ENT_QUOTES) ?>">
                    <div class="panel-spinner">
                        <div class="spinner-border spinner-border-sm text-secondary"></div>
                        <span>Loading…</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endfor; ?>
    </div>

    <!-- Summary table -->
    <?php if ($hasSession && count($summaryRows) > 0): ?>
    <div id="summary-section">
    <h6>Session summary — <?= htmlspecialchars((string) ($sessionLabel ?? $session_id ?? ''), ENT_QUOTES) ?></h6>
        <div id="summary-table-wrap">
            <table id="summary-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Sensor</th>
                        <th>Unit</th>
                        <th>Samples</th>
                        <th>Min</th>
                        <th>Max</th>
                        <th>Avg</th>
                        <th>P25</th>
                        <th>P75</th>
                        <th>Trend</th>
                    </tr>
                </thead>
                <tbody id="summary-tbody">
                    <?php foreach ($summaryRows as $idx => $row): ?>
                    <tr class="summary-row" data-row="<?= $idx ?>">
                        <td>
                            <button class="btn-add-panel"
                                    data-sensor-key="<?= htmlspecialchars((string) ($row['sensor_key'] ?? ''), ENT_QUOTES) ?>"
                                    title="Add to next empty panel">＋</button>
                        </td>
                        <td><?= htmlspecialchars((string) ($row['label'] ?? $row['sensor_key'] ?? ''), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars((string) ($row['unit'] ?? ''), ENT_QUOTES) ?></td>
                        <td><?= number_format((int) ($row['cnt'] ?? 0)) ?></td>
                        <td><?= isset($row['min']) ? round((float)$row['min'], 2) : '—' ?></td>
                        <td><?= isset($row['max']) ? round((float)$row['max'], 2) : '—' ?></td>
                        <td><?= isset($row['avg']) ? round((float)$row['avg'], 2) : '—' ?></td>
                        <td><?= isset($row['p25']) ? round((float)$row['p25'], 2) : '—' ?></td>
                        <td><?= isset($row['p75']) ? round((float)$row['p75'], 2) : '—' ?></td>
                        <td class="spark-cell">
                            <span class="sparkline"
                                data-values="<?= htmlspecialchars((string) ($row['sparkline'] ?? ''), ENT_QUOTES) ?>">
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="summary-pagination">
            <button id="pg-prev" disabled>‹ Prev</button>
            <span id="pg-info"></span>
            <button id="pg-next">Next ›</button>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /#dwb-canvas -->

<!-- ═══════════════════════════════════════════════════════ MAP MODAL -->
<?php if ($hasSession && count($geolocs) > 0): ?>
<div class="modal fade" id="mapModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">GPS Track</h5>
                <button type="button" class="close" data-dismiss="modal"
                        aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="map"></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════ SAVE MODAL -->
<?php if ($hasSession): ?>
<div class="modal fade" id="saveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">⭐ Save dashboard</h5>
                <button type="button" class="close" data-dismiss="modal"
                        aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="save-title">Title (optional)</label>
                    <input type="text" class="form-control form-control-sm"
                           id="save-title" maxlength="120"
                           placeholder="e.g. Cold start — 22 Apr 2026">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="save-slug">
                        Custom slug (optional)
                        <span class="text-muted-dwb">— letters, digits, hyphens, 3–80 chars</span>
                    </label>
                    <input type="text" class="form-control form-control-sm"
                           id="save-slug" maxlength="80"
                           placeholder="auto-generated if left blank">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="save-device-id">
                        Device ID
                        <span class="text-muted-dwb">— needed to update or delete this save later</span>
                    </label>
                    <input type="password" class="form-control form-control-sm"
                           id="save-device-id" maxlength="255"
                           placeholder="Torque device ID (optional)">
                </div>
                <div id="save-result-box" class="hidden"></div>
                <div id="save-error" class="text-danger mt-2 hidden"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm"
                        data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning btn-sm" id="btn-save-dashboard">
                    Save &amp; get link
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════ SESSION ACTIONS MODAL -->
<?php if ($hasSession): ?>
<div class="modal fade" id="actionsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Session actions</h5>
                <button type="button" class="close" data-dismiss="modal"
                        aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <!-- Delete -->
                <form method="post" id="delete-form">
              <input type="hidden" name="deletesession"
                  value="<?= htmlspecialchars((string) ($session_id ?? ''), ENT_QUOTES) ?>">
                    <button type="submit" class="btn btn-danger btn-sm w-100"
                            id="btn-delete-session">
                        🗑 Delete session <?= htmlspecialchars((string) ($sessionLabel ?? $session_id ?? ''), ENT_QUOTES) ?>
                    </button>
                </form>

                <?php if ($session_id_next !== false): ?>
                <hr>
                <p class="form-label">Merge with previous session</p>
                <form method="post">
              <input type="hidden" name="mergesession"
                  value="<?= htmlspecialchars((string) ($session_id ?? ''), ENT_QUOTES) ?>">
              <input type="hidden" name="mergesessionwith"
                  value="<?= htmlspecialchars((string) ($session_id_next ?? ''), ENT_QUOTES) ?>">
                    <button type="submit" class="btn btn-warning btn-sm w-100">
                        ⇌ Merge with
                        <?= htmlspecialchars((string) ($seshdates[$session_id_next] ?? $session_id_next ?? ''), ENT_QUOTES) ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════ SCRIPTS -->
<script src="static/js/jquery.min.js"></script>
<script src="static/js/bootstrap.min.js"></script>
<script src="static/js/chosen.jquery.min.js"></script>
<script src="static/js/jquery.peity.min.js"></script>
<script src="static/js/uplot.min.js"></script>

<script>
/* ── Inline state ──────────────────────────────────────────────────────── */
const SESSION_ID   = <?= $hasSession ? json_encode($session_id) : 'null' ?>;
const GRID_ROWS    = <?= $gridRows ?>;
const GRID_COLS    = <?= $gridCols ?>;
const PANEL_COUNT  = <?= $panelCount ?>;
const GRID_PARAM   = <?= json_encode($gridParam) ?>;
const GEO_POINTS   = <?= json_encode($geolocs) ?>;
const IMAP_DATA    = <?= json_encode($imapdata) ?>;

/* Panel state from PHP */
const PANELS_INIT  = <?php
    $out = [];
    foreach ($panels as $p) {
        $out[] = [
            'sensor' => $p['sensor'],
            'cs'     => $p['cs'],
            'rs'     => $p['rs'],
        ];
    }
    echo json_encode($out);
?>;

/* ── DWB — Dashboard Workbench ──────────────────────────────────────────── */
const DWB = (() => {
    'use strict';

    /** Build a URL for the current state */
    function buildUrl(sid, grid, panelArr) {
        const u = new URL(window.location.href.split('?')[0], window.location.origin);
        if (sid)  u.searchParams.set('id',   sid);
        if (grid) u.searchParams.set('grid', grid);
        panelArr.forEach((p, i) => {
            const sensors = Array.isArray(p.sensors)
                ? p.sensors
                : (p.sensor ? [p.sensor] : []);
            sensors.slice(0, 6).forEach(sensor => {
                if (sensor) u.searchParams.append(`p[${i}][s][]`, sensor);
            });
            if (p.cs > 1) u.searchParams.set(`p[${i}][cs]`, p.cs);
            if (p.rs > 1) u.searchParams.set(`p[${i}][rs]`, p.rs);
        });
        return u.toString();
    }

    /** Navigate to new session, preserving current grid + panels (FIXED) */
    function setSession(sid) {
        if (!sid) {
            window.location = window.location.pathname;
            return;
        }

        const currentPanels = getCurrentPanelState();
        window.location = buildUrl(sid, GRID_PARAM, currentPanels);
    }

    /** Change grid preset, keep sensors that fit */
    function setGrid(preset) {
        const [r, c] = preset.split('x').map(Number);
        const cap    = r * c;
        const keep   = getCurrentPanelState().slice(0, cap);
        while (keep.length < cap) keep.push({ sensor: '', cs: 1, rs: 1 });
        window.location = buildUrl(SESSION_ID, preset, keep);
    }

    /** Read current panel state from the DOM */
    function getCurrentPanelState() {
        const panels = [];
        document.querySelectorAll('.dwb-panel').forEach(panel => {
            const idx  = Number(panel.dataset.panelIdx);
            const sensors = parsePanelSensors(panel);
            panels[idx] = {
                sensor: sensors[0] || '',
                sensors,
                cs: Number(panel.dataset.cs || 1),
                rs: Number(panel.dataset.rs || 1),
            };
        });
        return panels;
    }

    function parsePanelSensors(panel) {
        if (!panel) return [];
        try {
            const sensors = JSON.parse(panel.dataset.sensors || '[]');
            return Array.isArray(sensors) ? sensors.filter(Boolean).slice(0, 6) : [];
        } catch (e) {
            return [];
        }
    }

    function normalizeUnitForCompare(unit) {
        return String(unit || '').trim().toLowerCase();
    }

    function panelKnownUnits(idx) {
        return Array.from(document.querySelectorAll(`.panel-remove-sensor[data-panel-idx="${idx}"]`))
            .map(btn => normalizeUnitForCompare(btn.dataset.sensorUnit))
            .filter(Boolean);
    }

    /** Change sensor for one panel */
    function setPanelSensor(idx, key) {
        const arr = getCurrentPanelState();
        arr[idx].sensor = key;
        arr[idx].sensors = key ? [key] : [];
        window.location = buildUrl(SESSION_ID, GRID_PARAM, arr);
    }

    /** Add one sensor to an existing panel overlay */
    function addPanelSensor(idx, key, unit = '') {
        if (!key) return;
        const arr = getCurrentPanelState();
        const sensors = arr[idx].sensors || [];
        if (sensors.includes(key)) return;
        if (sensors.length >= 6) {
            alert('A panel can show up to six sensors.');
            return;
        }

        const nextUnit = normalizeUnitForCompare(unit);
        const existingUnits = Array.from(new Set(panelKnownUnits(idx)));
        if (nextUnit && existingUnits.length > 0 && !existingUnits.includes(nextUnit)) {
            alert('Overlay panels can only combine sensors with the same unit. Clear the panel or use another panel for this sensor.');
            return;
        }

        arr[idx].sensors = sensors.concat(key);
        arr[idx].sensor = arr[idx].sensors[0] || '';
        window.location = buildUrl(SESSION_ID, GRID_PARAM, arr);
    }

    /** Remove one sensor from an overlay panel */
    function removePanelSensor(idx, key) {
        const arr = getCurrentPanelState();
        arr[idx].sensors = (arr[idx].sensors || []).filter(sensor => sensor !== key);
        arr[idx].sensor = arr[idx].sensors[0] || '';
        window.location = buildUrl(SESSION_ID, GRID_PARAM, arr);
    }

    /** Adjust colspan / rowspan of a panel by delta */
    function setPanelSpan(idx, dcs, drs) {
        const arr = getCurrentPanelState();
        arr[idx].cs = Math.max(1, Math.min(GRID_COLS, arr[idx].cs + dcs));
        arr[idx].rs = Math.max(1, Math.min(GRID_ROWS, arr[idx].rs + drs));
        window.location = buildUrl(SESSION_ID, GRID_PARAM, arr);
    }

    /** Clear sensor from a panel */
    function clearPanel(idx) {
        const arr = getCurrentPanelState();
        arr[idx].sensor = '';
        arr[idx].sensors = [];
        window.location = buildUrl(SESSION_ID, GRID_PARAM, arr);
    }

    /** Add sensor to next empty panel */
    function addSensorToNextPanel(key) {
        const arr = getCurrentPanelState();
        const free = arr.findIndex(p => !p.sensor);
        if (free === -1) {
            alert('All panels are occupied. Clear a panel first.');
            return;
        }
        arr[free].sensor = key;
        arr[free].sensors = [key];
        window.location = buildUrl(SESSION_ID, GRID_PARAM, arr);
    }

    return {
        buildUrl,
        setSession,
        setGrid,
        setPanelSensor,
        addPanelSensor,
        removePanelSensor,
        setPanelSpan,
        clearPanel,
        addSensorToNextPanel,
    };
})();

/* ── Session picker ─────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    // Chosen
    if (typeof jQuery !== 'undefined' && typeof jQuery.fn.chosen !== 'undefined') {
        jQuery('#session-picker').chosen({
            width: '100%',
            search_contains: true,
            no_results_text: 'No sessions found'
        }).on('change', function () {
            DWB.setSession(this.value);
        });
    }

    // Grid preset pills
    document.querySelectorAll('.grid-pill[data-preset]').forEach(btn => {
        btn.addEventListener('click', () => DWB.setGrid(btn.dataset.preset));
    });

    // Per-panel sensor selects. Populated panels keep the selection staged for the + button.
    document.querySelectorAll('.panel-sensor-select').forEach(sel => {
        sel.addEventListener('change', () => {
            const panel = sel.closest('.dwb-panel');
            let sensors = [];
            try {
                sensors = panel ? JSON.parse(panel.dataset.sensors || '[]') : [];
            } catch (e) {
                sensors = [];
            }
            if (!Array.isArray(sensors) || sensors.length === 0) {
                DWB.setPanelSensor(Number(sel.dataset.panelIdx), sel.value);
            }
        });
    });

    document.querySelectorAll('.panel-add-sensor').forEach(btn => {
        btn.addEventListener('click', () => {
            const panel = btn.closest('.dwb-panel');
            const sel = panel?.querySelector('.panel-sensor-select');
            if (!sel?.value) return;
            DWB.addPanelSensor(
                Number(btn.dataset.panelIdx),
                sel.value,
                sel.selectedOptions[0]?.dataset.unit || ''
            );
        });
    });

    document.querySelectorAll('.panel-remove-sensor').forEach(btn => {
        btn.addEventListener('click', () => {
            DWB.removePanelSensor(Number(btn.dataset.panelIdx), btn.dataset.sensorKey);
        });
    });

    // Add-to-panel buttons in summary table
    document.querySelectorAll('.btn-add-panel').forEach(btn => {
        btn.addEventListener('click', () => {
            DWB.addSensorToNextPanel(btn.dataset.sensorKey);
        });
    });

    // Delete session confirm
    const delForm = document.getElementById('delete-form');
    if (delForm) {
        delForm.addEventListener('submit', e => {
            if (!confirm('Delete this session? This cannot be undone.')) {
                e.preventDefault();
            }
        });
    }

    // Sparklines
    document.querySelectorAll('span.sparkline').forEach(el => {
        const vals = el.dataset.values;
        if (vals && typeof jQuery !== 'undefined') {
            el.textContent = vals;
            jQuery(el).peity('line', { width: 80, height: 22, stroke: '#4e9af1', fill: 'rgba(78,154,241,.15)' });
        }
    });

    // Summary table pagination
    initSummaryPagination();
});

/* ── Summary pagination ─────────────────────────────────────────────────── */
function initSummaryPagination() {
    const tbody    = document.getElementById('summary-tbody');
    const pgPrev   = document.getElementById('pg-prev');
    const pgNext   = document.getElementById('pg-next');
    const pgInfo   = document.getElementById('pg-info');
    if (!tbody || !pgPrev) return;

    const PER_PAGE = 15;
    const rows     = Array.from(tbody.querySelectorAll('tr.summary-row'));
    const total    = rows.length;
    if (total <= PER_PAGE) {
        document.getElementById('summary-pagination').style.display = 'none';
        return;
    }

    let page = 0;
    const maxPage = Math.ceil(total / PER_PAGE) - 1;

    function render() {
        rows.forEach((r, i) => {
            r.style.display = (i >= page * PER_PAGE && i < (page + 1) * PER_PAGE) ? '' : 'none';
        });
        pgPrev.disabled = page === 0;
        pgNext.disabled = page === maxPage;
        pgInfo.textContent = `Page ${page + 1} / ${maxPage + 1}`;
    }

    pgPrev.addEventListener('click', () => { if (page > 0) { page--; render(); } });
    pgNext.addEventListener('click', () => { if (page < maxPage) { page++; render(); } });
    render();
}

/* ── Lazy Leaflet map ───────────────────────────────────────────────────── */
<?php if ($hasSession && count($geolocs) > 0): ?>
let leafletLoaded  = false;
let leafletMap     = null;

function ensureLeafletLoaded() {
    if (leafletLoaded) {
        refreshLeafletMap();
        return;
    }
    leafletLoaded = true;
    const cssL = document.createElement('link');
    cssL.rel   = 'stylesheet';
    cssL.href  = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    document.head.appendChild(cssL);

    const jsL    = document.createElement('script');
    jsL.src      = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    jsL.onload   = () => initLeaflet();
    jsL.onerror  = () => showMapError('Map library could not be loaded.');
    document.head.appendChild(jsL);
}

if (typeof jQuery !== 'undefined') {
    jQuery('#mapModal')
        .on('shown.bs.modal', ensureLeafletLoaded)
        .on('hide.bs.modal', function () {
            if (this.contains(document.activeElement)) {
                document.activeElement.blur();
            }
        })
        .on('hidden.bs.modal', function () {
            document.getElementById('btn-map')?.focus();
        });
}

function initLeaflet() {
    const pts = GEO_POINTS.map(p => [p.lat, p.lon]);
    if (pts.length === 0) {
        showMapError('No GPS points available for this session.');
        return;
    }

    leafletMap = L.map('map');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(leafletMap);

    const poly = L.polyline(pts, { color: '#4e9af1', weight: 3 }).addTo(leafletMap);
    L.marker(pts[0], { title: 'Start' }).addTo(leafletMap);
    L.marker(pts[pts.length - 1], { title: 'End' }).addTo(leafletMap);
    leafletMap.fitBounds(poly.getBounds(), { padding: [20, 20] });
    refreshLeafletMap();
}

function refreshLeafletMap() {
    if (!leafletMap) return;
    window.setTimeout(() => {
        leafletMap.invalidateSize();
        const pts = GEO_POINTS.map(p => [p.lat, p.lon]);
        if (pts.length > 0) {
            leafletMap.fitBounds(L.latLngBounds(pts), { padding: [20, 20] });
        }
    }, 80);
}

function showMapError(message) {
    const map = document.getElementById('map');
    if (!map) return;
    map.innerHTML = `<div class="panel-empty"><div class="empty-icon">⚠</div><p>${message}</p></div>`;
}
<?php endif; ?>

/* ── uPlot panel charts — Step 7 ───────────────────────────────────────── */
(function () {
    'use strict';

    if (!SESSION_ID) return;          // no session → nothing to chart

    /* Shared cursor-sync so all visible panels cross-hair together */
    const cursorSync = uPlot.sync('dwb');
    let syncCursorEnabled = true;
    let applyingSyncedRange = false;
    let currentSyncedRange = null;
    let pinnedTimeMs = null;

    /* uPlot colour palette */
    const LINE_COLOR   = '#4e9af1';
    const FILL_COLOR   = 'rgba(78,154,241,0.08)';
    const GRID_COLOR   = 'rgba(255,255,255,0.06)';
    const TICK_COLOR   = 'rgba(255,255,255,0.20)';
    const LABEL_COLOR  = '#8b97a8';
    const NEAREST_VALUE_TOLERANCE_MS = 1500;
    const STALE_VALUE_MS = 750;
    const MIN_GAP_THRESHOLD_MS = 3000;
    const MAX_RENDER_POINTS = 3000;
    const REFERENCE_LINE_COLOR = 'rgba(247,196,83,0.58)';
    const REFERENCE_LABEL_COLOR = 'rgba(247,196,83,0.78)';
    const SERIES_COLORS = [
        '#4e9af1',
        '#f7c453',
        '#45d19a',
        '#f06f8f',
        '#9b8cff',
        '#f28f45',
    ];

    /*
     * Runtime chart registry.
     *
     * Step 1 keeps existing behaviour intact while giving later sync,
     * inspector, zoom and pinned-marker work a single place to find visible
     * charts and their raw sensor data.
     */
    const DWBCharts = (() => {
        const panels = new Map();

        function registerPanel(panelIdx, sensorStates, uplot, container, plotData, chartMeta) {
            const sensors = Array.isArray(sensorStates) ? sensorStates : [sensorStates];
            const fullRange = sensors.reduce((range, sensor) => {
                if (!sensor.xSeconds || sensor.xSeconds.length === 0) return range;
                const min = sensor.xSeconds[0];
                const max = sensor.xSeconds[sensor.xSeconds.length - 1];
                return {
                    min: range ? Math.min(range.min, min) : min,
                    max: range ? Math.max(range.max, max) : max,
                };
            }, null);

            panels.set(panelIdx, {
                panelIdx,
                sensors,
                uplot,
                container,
                plotData,
                chartMeta,
                fullRange,
            });
        }

        function getChart(panelIdx) {
            return panels.get(panelIdx)?.uplot ?? null;
        }

        function getPanelStates() {
            return Array.from(panels.values());
        }

        function getVisibleSensors() {
            return Array.from(panels.values()).flatMap(panel => panel.sensors);
        }

        function replaceChart(panelIdx, uplot) {
            const panelState = panels.get(panelIdx);
            if (!panelState) return;
            panelState.uplot = uplot;
        }

        function getFullRange() {
            let min = null;
            let max = null;

            panels.forEach(panel => {
                if (!panel.fullRange) return;
                min = min === null ? panel.fullRange.min : Math.min(min, panel.fullRange.min);
                max = max === null ? panel.fullRange.max : Math.max(max, panel.fullRange.max);
            });

            return min !== null && max !== null ? { min, max } : null;
        }

        function resizePanel(panelIdx) {
            const panelState = panels.get(panelIdx);
            if (!panelState) return;

            const panel = document.querySelector(`.dwb-panel[data-panel-idx="${panelIdx}"]`);
            const area = panel?.querySelector('.panel-chart-area');
            if (!area) return;

            const w = area.clientWidth  || 400;
            const h = area.clientHeight || 200;
            panelState.uplot.setSize({ width: w, height: h });
        }

        return {
            registerPanel,
            getChart,
            getPanelStates,
            getVisibleSensors,
            replaceChart,
            getFullRange,
            resizePanel,
        };
    })();

    window.DWBCharts = DWBCharts;

    /* Build a minimal uPlot opts object for a panel chart */
    function buildOpts(label, unit, width, height, sensors = []) {
        const opts = {
            title:  '',
            width:  width,
            height: height,
            cursor: {},
            hooks: {
                setCursor: [
                    u => {
                        if (pinnedTimeMs !== null) return;
                        if (u.cursor.left == null) return;
                        const seconds = u.posToVal(u.cursor.left, 'x');
                        if (Number.isFinite(seconds)) {
                            scheduleInspectorUpdate(seconds * 1000);
                        }
                    },
                ],
                ready: [
                    u => {
                        u._dwbCleanupRangeInteractions = attachRangeInteractions(u);
                    },
                ],
                destroy: [
                    u => {
                        if (typeof u._dwbCleanupRangeInteractions === 'function') {
                            u._dwbCleanupRangeInteractions();
                            u._dwbCleanupRangeInteractions = null;
                        }
                    },
                ],
                click: [
                    u => {
                        if (u.cursor.left == null) return;
                        const seconds = u.posToVal(u.cursor.left, 'x');
                        if (Number.isFinite(seconds)) {
                            pinInspector(seconds * 1000);
                        }
                    },
                ],
                setScale: [
                    (u, scaleKey) => {
                        if (scaleKey !== 'x' || applyingSyncedRange) return;
                        const xScale = u.scales.x;
                        if (!Number.isFinite(xScale.min) || !Number.isFinite(xScale.max)) return;
                        syncTimeRange({ min: xScale.min, max: xScale.max }, u);
                    },
                ],
                dblClick: [
                    () => {
                        resetTimeRange();
                    },
                ],
            },
            plugins: buildReferencePluginsForSensors(
                sensors.length > 0 ? sensors : [{ label, unit }]
            ),
            legend: { show: false },
            scales: {
                x: { time: true },
                y: { auto: true },
            },
            axes: [
                {
                    stroke:   LABEL_COLOR,
                    grid:     { stroke: GRID_COLOR, width: 1 },
                    ticks:    { stroke: TICK_COLOR },
                    values:   (u, vals) => vals.map(v => {
                        if (v == null) return '';
                        const d = new Date(v * 1e3);
                        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                    }),
                },
                {
                    stroke:  LABEL_COLOR,
                    grid:    { stroke: GRID_COLOR, width: 1 },
                    ticks:   { stroke: TICK_COLOR },
                    label:   unit || '',
                    labelSize: 14,
                },
            ],
            series: buildSeriesOptions(label, sensors),
        };

        if (syncCursorEnabled) {
            opts.cursor.sync = { key: cursorSync.key };
        }

        return opts;
    }

    function buildSeriesOptions(label, sensors) {
        const seriesSensors = sensors.length > 0 ? sensors : [{ label }];
        return [
            {},
            ...seriesSensors.map((sensor, index) => ({
                label: displayLabel(sensor.label || label),
                stroke: SERIES_COLORS[index % SERIES_COLORS.length],
                fill: seriesSensors.length === 1 ? FILL_COLOR : 'transparent',
                width: 1.5,
                points: { show: false },
            })),
        ];
    }

    function buildReferencePluginsForSensors(sensors) {
        const references = sensors.flatMap(sensor => referenceRulesForSensor(sensor.label, sensor.unit));
        return references.length > 0 ? [referenceOverlayPlugin(references)] : [];
    }

    function referenceRulesForSensor(label, unit) {
        const normalizedLabel = String(label || '').toLowerCase();
        const normalizedUnit = normalizeUnit(unit);
        const references = [];

        if (normalizedUnit === '%' && /fuel\s*trim|trim/.test(normalizedLabel)) {
            references.push({
                value: 0,
                label: '0%',
                stroke: REFERENCE_LINE_COLOR,
                fill: REFERENCE_LABEL_COLOR,
            });
        }

        if (
            ['psi', 'bar', 'kpa'].includes(normalizedUnit)
            && /boost|manifold|map|pressure/.test(normalizedLabel)
        ) {
            references.push({
                value: 0,
                label: `0 ${unit || ''}`.trim(),
                stroke: REFERENCE_LINE_COLOR,
                fill: REFERENCE_LABEL_COLOR,
            });
        }

        return references;
    }

    function normalizeUnit(unit) {
        return String(unit || '')
            .trim()
            .toLowerCase()
            .replace('℃', '°c')
            .replace('℉', '°f');
    }

    function referenceOverlayPlugin(references) {
        return {
            hooks: {
                draw: [
                    u => {
                        const bbox = u.bbox;
                        if (!bbox || !Number.isFinite(u.scales.y.min) || !Number.isFinite(u.scales.y.max)) {
                            return;
                        }

                        const ctx = u.ctx;
                        const left = bbox.left;
                        const right = bbox.left + bbox.width;
                        const top = bbox.top;
                        const bottom = bbox.top + bbox.height;

                        ctx.save();
                        references.forEach(reference => {
                            if (
                                reference.value < u.scales.y.min
                                || reference.value > u.scales.y.max
                            ) {
                                return;
                            }

                            const y = Math.round(u.valToPos(reference.value, 'y', true)) + 0.5;
                            if (y < top || y > bottom) return;

                            ctx.beginPath();
                            ctx.setLineDash([4, 4]);
                            ctx.lineWidth = 1;
                            ctx.strokeStyle = reference.stroke;
                            ctx.moveTo(left, y);
                            ctx.lineTo(right, y);
                            ctx.stroke();

                            if (reference.label) {
                                ctx.setLineDash([]);
                                ctx.font = '11px system-ui, -apple-system, "Segoe UI", sans-serif';
                                ctx.textAlign = 'right';
                                ctx.textBaseline = 'bottom';
                                ctx.fillStyle = reference.fill;
                                ctx.fillText(reference.label, right - 4, y - 3);
                            }
                        });
                        ctx.restore();
                    },
                ],
            },
        };
    }

    /* Convert [{ts_ms, value}] API response to uPlot data arrays */
    function apiToUplot(pairs) {
        const xs = new Float64Array(pairs.length);
        const ys = new Float64Array(pairs.length);
        for (let i = 0; i < pairs.length; i++) {
            xs[i] = pairs[i][0] / 1000;   // ms → s (uPlot uses Unix seconds)
            ys[i] = pairs[i][1];
        }
        return [xs, ys];
    }

    function pairsToGapAwareUplot(pairs, gapThresholdMs = calculateGapThresholdMs(pairs)) {
        const xs = [];
        const ys = [];

        for (let i = 0; i < pairs.length; i++) {
            if (i > 0 && pairs[i][0] - pairs[i - 1][0] > gapThresholdMs) {
                xs.push((pairs[i - 1][0] + 1) / 1000, (pairs[i][0] - 1) / 1000);
                ys.push(null, null);
            }

            xs.push(pairs[i][0] / 1000);
            ys.push(pairs[i][1]);
        }

        return [xs, ys];
    }

    function calculateGapThresholdMs(pairs) {
        if (!pairs || pairs.length < 3) return MIN_GAP_THRESHOLD_MS;

        const deltas = [];
        for (let i = 1; i < pairs.length; i++) {
            const delta = pairs[i][0] - pairs[i - 1][0];
            if (delta > 0) deltas.push(delta);
        }

        if (deltas.length === 0) return MIN_GAP_THRESHOLD_MS;

        deltas.sort((a, b) => a - b);
        const median = deltas[Math.floor(deltas.length / 2)];
        return Math.max(MIN_GAP_THRESHOLD_MS, median * 3);
    }

    function downsamplePairsForRender(pairs, maxPoints = MAX_RENDER_POINTS) {
        if (!pairs || pairs.length <= maxPoints || maxPoints < 4) return pairs;

        const renderPairs = [pairs[0]];
        const bucketCount = Math.max(1, Math.floor((maxPoints - 2) / 2));
        const bucketSize = (pairs.length - 2) / bucketCount;

        for (let bucket = 0; bucket < bucketCount; bucket++) {
            const start = 1 + Math.floor(bucket * bucketSize);
            const end = Math.min(
                pairs.length - 1,
                1 + Math.floor((bucket + 1) * bucketSize)
            );

            if (start >= end) continue;

            let minIdx = start;
            let maxIdx = start;

            for (let i = start + 1; i < end; i++) {
                if (pairs[i][1] < pairs[minIdx][1]) minIdx = i;
                if (pairs[i][1] > pairs[maxIdx][1]) maxIdx = i;
            }

            if (minIdx === maxIdx) {
                renderPairs.push(pairs[minIdx]);
            } else if (minIdx < maxIdx) {
                renderPairs.push(pairs[minIdx], pairs[maxIdx]);
            } else {
                renderPairs.push(pairs[maxIdx], pairs[minIdx]);
            }
        }

        renderPairs.push(pairs[pairs.length - 1]);
        return renderPairs;
    }

    /* Replace the spinner inside a chart-area div with a uPlot instance */
    function mountChart(container, label, unit, data, sensors = []) {
        container.innerHTML = '';
        const w = container.clientWidth  || 400;
        const h = container.clientHeight || 200;
        const opts = buildOpts(label, unit, w, h, sensors);
        const u    = new uPlot(opts, data, container);
        return u;
    }

    let inspectorFrame = null;
    let pendingInspectorTimeMs = null;

    function scheduleInspectorUpdate(timeMs) {
        pendingInspectorTimeMs = timeMs;
        if (inspectorFrame !== null) return;

        inspectorFrame = window.requestAnimationFrame(() => {
            inspectorFrame = null;
            renderInspector(pendingInspectorTimeMs);
        });
    }

    function renderInspector(timeMs) {
        const inspector = document.getElementById('sync-inspector');
        if (!inspector || timeMs == null) return;

        const timeEl = inspector.querySelector('.sync-inspector-time');
        const valuesEl = inspector.querySelector('.sync-inspector-values');
        const clearBtn = inspector.querySelector('#pin-clear');
        if (!timeEl || !valuesEl) return;

        const sensors = DWBCharts.getVisibleSensors();
        if (sensors.length === 0) return;

        inspector.hidden = false;
        inspector.classList.remove('is-empty');
        inspector.classList.toggle('is-pinned', pinnedTimeMs !== null);
        if (clearBtn) clearBtn.hidden = pinnedTimeMs === null;
        timeEl.textContent = `${pinnedTimeMs !== null ? 'Pinned ' : ''}${formatInspectorTime(timeMs)}`;
        valuesEl.replaceChildren();

        sensors.forEach(sensor => {
            valuesEl.appendChild(buildInspectorChip(sensor, timeMs));
        });
    }

    function pinInspector(timeMs) {
        pinnedTimeMs = timeMs;
        renderInspector(timeMs);
    }

    function clearPinnedInspector() {
        pinnedTimeMs = null;

        const inspector = document.getElementById('sync-inspector');
        if (!inspector) return;

        inspector.classList.remove('is-pinned');
        const clearBtn = inspector.querySelector('#pin-clear');
        if (clearBtn) clearBtn.hidden = true;

        if (pendingInspectorTimeMs !== null) {
            renderInspector(pendingInspectorTimeMs);
        }
    }

    function buildInspectorChip(sensor, timeMs) {
        const nearest = nearestPair(sensor.rawPairs, timeMs, NEAREST_VALUE_TOLERANCE_MS);
        const chip = document.createElement('span');
        chip.className = 'sync-inspector-chip';

        const label = document.createElement('span');
        label.className = 'sync-inspector-label';
        label.textContent = displayLabel(sensor.label);
        chip.appendChild(label);

        const value = document.createElement('span');
        value.className = 'sync-inspector-value';

        if (!nearest) {
            chip.classList.add('is-missing');
            value.textContent = 'no value';
            chip.appendChild(value);
            return chip;
        }

        const deltaMs = Math.round(nearest[0] - timeMs);
        if (Math.abs(deltaMs) > STALE_VALUE_MS) {
            chip.classList.add('is-stale');
        }

        value.textContent = formatSensorValue(nearest[1], sensor.unit);
        chip.appendChild(value);

        if (sensor.unit) {
            const unit = document.createElement('span');
            unit.className = 'sync-inspector-unit';
            unit.textContent = sensor.unit;
            chip.appendChild(unit);
        }

        if (Math.abs(deltaMs) > STALE_VALUE_MS) {
            const delta = document.createElement('span');
            delta.className = 'sync-inspector-delta';
            delta.title = 'Time offset from cursor';
            delta.textContent = formatDelta(deltaMs);
            chip.appendChild(delta);
        }

        return chip;
    }

    function nearestPair(pairs, targetMs, toleranceMs) {
        if (!pairs || pairs.length === 0) return null;

        let lo = 0;
        let hi = pairs.length;
        while (lo < hi) {
            const mid = (lo + hi) >> 1;
            if (pairs[mid][0] < targetMs) lo = mid + 1;
            else hi = mid;
        }

        const before = pairs[lo - 1] ?? null;
        const after = pairs[lo] ?? null;
        const nearest = chooseNearest(before, after, targetMs);

        return nearest && Math.abs(nearest[0] - targetMs) <= toleranceMs
            ? nearest
            : null;
    }

    function chooseNearest(before, after, targetMs) {
        if (!before) return after;
        if (!after) return before;

        return Math.abs(before[0] - targetMs) <= Math.abs(after[0] - targetMs)
            ? before
            : after;
    }

    function formatInspectorTime(timeMs) {
        const d = new Date(timeMs);
        const base = d.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
        return `${base}.${String(d.getMilliseconds()).padStart(3, '0')}`;
    }

    function displayLabel(label) {
        return String(label || '').replace(/\s*\[[^\]]+\]\s*$/, '');
    }

    function formatSensorValue(value, unit) {
        const n = Number(value);
        if (!Number.isFinite(n)) return String(value);

        const normalizedUnit = String(unit || '').toLowerCase();
        if (normalizedUnit === 'rpm') return n.toFixed(0);
        if (normalizedUnit === '%' || normalizedUnit === 'km/h' || normalizedUnit === 'mph') return n.toFixed(1);
        if (normalizedUnit === 'bar') return n.toFixed(2);
        if (normalizedUnit === 'psi' || normalizedUnit === 'kpa') return n.toFixed(1);
        if (normalizedUnit === '°c' || normalizedUnit === '°f') return n.toFixed(1);
        if (Math.abs(n) >= 100) return n.toFixed(0);
        if (Math.abs(n) >= 10) return n.toFixed(1);
        return n.toFixed(2);
    }

    function formatDelta(deltaMs) {
        const sign = deltaMs > 0 ? '+' : '-';
        return `time ${sign}${Math.abs(deltaMs)} ms`;
    }

    function setSyncCursorEnabled(enabled) {
        syncCursorEnabled = enabled;
        document.querySelectorAll('.uplot').forEach(el => {
            el.classList.toggle('sync-disabled', !enabled);
        });
    }

    function remountChartsForSyncState() {
        DWBCharts.getPanelStates().forEach(panelState => {
            if (typeof panelState.uplot.destroy === 'function') {
                panelState.uplot.destroy();
            }

            const u = mountChart(
                panelState.container,
                panelState.chartMeta.label,
                panelState.chartMeta.unit,
                panelState.plotData,
                panelState.sensors
            );
            DWBCharts.replaceChart(panelState.panelIdx, u);

            if (currentSyncedRange) {
                u.setScale('x', currentSyncedRange);
            }
        });
    }

    function syncTimeRange(range, sourceChart = null) {
        if (!isUsefulRange(range)) return;

        setActiveRangePreset(null);
        currentSyncedRange = { min: range.min, max: range.max };
        applyingSyncedRange = true;
        try {
            DWBCharts.getPanelStates().forEach(panelState => {
                const u = panelState.uplot;
                if (!u || u === sourceChart) return;
                u.setScale('x', currentSyncedRange);
            });
        } finally {
            applyingSyncedRange = false;
        }
    }

    function resetTimeRange() {
        const fullRange = DWBCharts.getFullRange();
        if (!fullRange || !isUsefulRange(fullRange)) return;

        currentSyncedRange = null;
        setActiveRangePreset('full');
        applyingSyncedRange = true;
        try {
            DWBCharts.getPanelStates().forEach(panelState => {
                panelState.uplot.setScale('x', fullRange);
            });
        } finally {
            applyingSyncedRange = false;
        }
    }

    function isUsefulRange(range) {
        return range
            && Number.isFinite(range.min)
            && Number.isFinite(range.max)
            && range.max > range.min;
    }

    function attachRangeInteractions(u) {
        const over = u.over;
        if (!over) return null;

        let panStart = null;

        const onWheel = event => {
            if (!u.scales?.x) return;
            event.preventDefault();

            const rect = over.getBoundingClientRect();
            const pointerX = Math.max(0, Math.min(rect.width, event.clientX - rect.left));
            const center = u.posToVal(pointerX, 'x');
            const range = currentChartRange(u);
            if (!Number.isFinite(center) || !isUsefulRange(range)) return;

            const zoomFactor = event.deltaY < 0 ? 0.85 : 1.18;
            const next = boundedRange({
                min: center - (center - range.min) * zoomFactor,
                max: center + (range.max - center) * zoomFactor,
            });
            syncTimeRange(next, null);
        };

        const onMouseDown = event => {
            if (event.button !== 0 || !u.scales?.x) return;

            panStart = {
                x: event.clientX,
                range: currentChartRange(u),
            };

            over.classList.add('is-panning');
            event.preventDefault();
        };

        const onMouseMove = event => {
            if (!panStart || !isUsefulRange(panStart.range)) return;

            const dx = event.clientX - panStart.x;
            const secondsPerPixel = (panStart.range.max - panStart.range.min) / Math.max(1, u.bbox.width);
            const shift = dx * secondsPerPixel;
            const next = boundedRange({
                min: panStart.range.min - shift,
                max: panStart.range.max - shift,
            });

            syncTimeRange(next, null);
        };

        const onMouseUp = () => {
            if (!panStart) return;
            panStart = null;
            over.classList.remove('is-panning');
        };

        over.addEventListener('wheel', onWheel, { passive: false });
        over.addEventListener('mousedown', onMouseDown);
        window.addEventListener('mousemove', onMouseMove);
        window.addEventListener('mouseup', onMouseUp);

        return () => {
            over.removeEventListener('wheel', onWheel, { passive: false });
            over.removeEventListener('mousedown', onMouseDown);
            window.removeEventListener('mousemove', onMouseMove);
            window.removeEventListener('mouseup', onMouseUp);
        };
    }

    function currentChartRange(u) {
        return {
            min: u.scales.x.min,
            max: u.scales.x.max,
        };
    }

    function boundedRange(range) {
        const full = DWBCharts.getFullRange();
        if (!full || !isUsefulRange(range)) return range;

        const width = range.max - range.min;
        const fullWidth = full.max - full.min;
        if (width >= fullWidth) return full;

        if (range.min < full.min) {
            return { min: full.min, max: full.min + width };
        }

        if (range.max > full.max) {
            return { min: full.max - width, max: full.max };
        }

        return range;
    }

    function applyTimePreset(seconds) {
        const fullRange = DWBCharts.getFullRange();
        if (!fullRange || !Number.isFinite(seconds) || seconds <= 0) return;

        const range = {
            min: Math.max(fullRange.min, fullRange.max - seconds),
            max: fullRange.max,
        };

        if (!isUsefulRange(range)) return;
        syncTimeRange(range, null);
        setActiveRangePreset(String(seconds));
    }

    function setActiveRangePreset(value) {
        document.querySelectorAll('.range-preset').forEach(btn => {
            const btnValue = btn.dataset.rangeSeconds ?? btn.dataset.rangePreset ?? null;
            btn.classList.toggle('active', value !== null && btnValue === value);
        });
    }

    function initSyncToggle() {
        const btn = document.getElementById('sync-cursor-toggle');
        if (!btn) return;

        btn.addEventListener('click', () => {
            const enabled = btn.getAttribute('aria-pressed') !== 'true';
            btn.setAttribute('aria-pressed', enabled ? 'true' : 'false');
            btn.classList.toggle('active', enabled);
            btn.textContent = enabled ? 'Sync On' : 'Sync Off';

            setSyncCursorEnabled(enabled);
            remountChartsForSyncState();
        });
    }

    function initRangeReset() {
        const btn = document.getElementById('range-reset');
        if (!btn) return;
        btn.addEventListener('click', resetTimeRange);
    }

    function initRangePresets() {
        document.querySelectorAll('.range-preset[data-range-seconds]').forEach(btn => {
            btn.addEventListener('click', () => {
                applyTimePreset(Number(btn.dataset.rangeSeconds));
            });
        });
    }

    function initPinnedInspectorControls() {
        document.getElementById('pin-clear')?.addEventListener('click', clearPinnedInspector);
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && pinnedTimeMs !== null) {
                clearPinnedInspector();
            }
        });
    }

    /* Show an error state in a panel */
    function showError(container, msg) {
        container.innerHTML =
            `<div class="panel-empty"><div class="empty-icon">⚠</div><p>${msg}</p></div>`;
    }

    function parseChartKeys(container) {
        try {
            const keys = JSON.parse(container.dataset.keys || '[]');
            if (Array.isArray(keys)) return keys.filter(Boolean).slice(0, 6);
        } catch (e) {
            // Fall back to the legacy single-key attribute below.
        }

        return container.dataset.key ? [container.dataset.key] : [];
    }

    async function fetchSensorSeries(sid, key) {
        const resp = await fetch(`api/sensor.php?sid=${encodeURIComponent(sid)}&key=${encodeURIComponent(key)}`);
        if (!resp.ok) {
            const err = await resp.json().catch(() => ({ error: `HTTP ${resp.status}` }));
            throw new Error(err.error ?? 'Failed to load');
        }

        const json = await resp.json();
        if (!json.data || json.data.length === 0) {
            throw new Error('No data');
        }

        const rawData = apiToUplot(json.data);
        const gapThresholdMs = calculateGapThresholdMs(json.data);
        const renderPairs = downsamplePairsForRender(json.data);
        const renderData = pairsToGapAwareUplot(renderPairs, gapThresholdMs);

        return {
            key,
            label: json.label,
            unit: json.unit || '',
            rawPairs: json.data,
            renderPairs,
            plotData: renderData,
            xSeconds: rawData[0],
            yValues: rawData[1],
        };
    }

    function buildPanelPlotData(sensors) {
        if (sensors.length === 1) return sensors[0].plotData;
        return uPlot.join(sensors.map(sensor => sensor.plotData));
    }

    function splitCompatibleSensors(sensors) {
        const targetUnit = sensors
            .map(sensor => normalizeUnit(sensor.unit))
            .find(unit => unit !== '') || '';

        if (!targetUnit) {
            return { compatible: sensors, incompatible: [] };
        }

        const compatible = [];
        const incompatible = [];

        sensors.forEach(sensor => {
            const unit = normalizeUnit(sensor.unit);
            if (unit === '' || unit === targetUnit) {
                compatible.push(sensor);
            } else {
                incompatible.push(sensor);
            }
        });

        return { compatible, incompatible };
    }

    function chartMetaForSensors(sensors) {
        const units = Array.from(new Set(sensors.map(sensor => sensor.unit || '').filter(Boolean)));
        return {
            label: sensors.map(sensor => displayLabel(sensor.label)).join(' + '),
            unit: units.length === 1 ? units[0] : '',
        };
    }

    function showPanelWarning(container, message) {
        const warning = document.createElement('div');
        warning.className = 'panel-chart-warning';
        warning.textContent = message;
        container.appendChild(warning);
    }

    /* Fetch + render one panel */
    async function loadPanel(container) {
        const sid = container.dataset.sid;
        const keys = parseChartKeys(container);
        const idx = Number(container.closest('.dwb-panel')?.dataset.panelIdx ?? -1);

        try {
            if (keys.length === 0) {
                showError(container, 'No data');
                return;
            }

            const results = await Promise.allSettled(keys.map(key => fetchSensorSeries(sid, key)));
            const loadedSensors = results
                .filter(result => result.status === 'fulfilled')
                .map(result => result.value);
            const failedCount = results.filter(result => result.status === 'rejected').length;
            const { compatible: sensors, incompatible } = splitCompatibleSensors(loadedSensors);

            if (sensors.length === 0) {
                const firstError = results.find(result => result.status === 'rejected');
                showError(container, firstError?.reason?.message || 'No compatible data');
                return;
            }

            const chartMeta = chartMetaForSensors(sensors);
            const plotData = buildPanelPlotData(sensors);
            const u = mountChart(container, chartMeta.label, chartMeta.unit, plotData, sensors);

            const warnings = [];
            if (failedCount > 0) {
                warnings.push(`${failedCount} sensor${failedCount === 1 ? '' : 's'} failed to load`);
            }
            if (incompatible.length > 0) {
                warnings.push(`${incompatible.length} mixed-unit sensor${incompatible.length === 1 ? '' : 's'} hidden`);
            }
            if (warnings.length > 0) {
                showPanelWarning(container, warnings.join('; '));
            }

            if (idx >= 0) {
                DWBCharts.registerPanel(idx, sensors, u, container, plotData, chartMeta);
            }
        } catch (e) {
            showError(container, 'Network error');
        }
    }

    /* Kick off all panels that have data-key/data-keys set */
    function initAllPanels() {
        document.querySelectorAll('.panel-chart-area[data-keys], .panel-chart-area[data-key]').forEach(el => {
            if (parseChartKeys(el).length > 0) loadPanel(el);
        });
    }

    /* ResizeObserver — redraw each chart when its container changes size */
    if (typeof ResizeObserver !== 'undefined') {
        const ro = new ResizeObserver(entries => {
            for (const entry of entries) {
                const panel = entry.target.closest('.dwb-panel');
                if (!panel) continue;
                const idx = Number(panel.dataset.panelIdx);
                DWBCharts.resizePanel(idx);
            }
        });
        document.querySelectorAll('.panel-chart-area').forEach(el => ro.observe(el));
    }

    /* Run after DOM + scripts ready */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initSyncToggle();
            initRangeReset();
            initRangePresets();
            initPinnedInspectorControls();
            initAllPanels();
        });
    } else {
        initSyncToggle();
        initRangeReset();
        initRangePresets();
        initPinnedInspectorControls();
        initAllPanels();
    }
})();

/* ── Save dashboard ─────────────────────────────────────────────────────── */
<?php if ($hasSession): ?>
(function () {
    'use strict';

    const btn       = document.getElementById('btn-save-dashboard');
    const resultBox = document.getElementById('save-result-box');
    const errBox    = document.getElementById('save-error');

    if (!btn) return;

    btn.addEventListener('click', async () => {
        resultBox.style.display = 'none';
        errBox.style.display    = 'none';
        btn.disabled            = true;
        btn.textContent         = 'Saving…';

        // Build state object from current URL / JS constants.
        const state = {
            id:   SESSION_ID,
            grid: GRID_PARAM,
            p:    PANELS_INIT,
        };

        const payload = {
            state,
            title:     document.getElementById('save-title')?.value.trim()     || '',
            slug:      document.getElementById('save-slug')?.value.trim()      || '',
            device_id: document.getElementById('save-device-id')?.value.trim() || '',
        };

        try {
            const resp = await fetch('api/dashboard_save.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload),
            });

            const json = await resp.json();

            if (!resp.ok) {
                throw new Error(json.error ?? `HTTP ${resp.status}`);
            }

            // Build an absolute shareable URL from the returned relative path.
            const abs = new URL(json.url, window.location.origin).toString();

            resultBox.innerHTML =
                `✅ Dashboard saved!<br>
                 <strong>Slug:</strong> <code>${json.slug}</code><br>
                 <strong>Link:</strong> <a href="${abs}" target="_blank">${abs}</a>
                 <button type="button" class="copy-button"
                         onclick="navigator.clipboard.writeText('${abs}')
                                  .then(()=>this.textContent='Copied!')
                                  .catch(()=>{})">
                     Copy
                 </button>`;
            resultBox.style.display = 'block';
        } catch (e) {
            errBox.textContent    = e.message || 'Unknown error.';
            errBox.style.display  = 'block';
        } finally {
            btn.disabled    = false;
            btn.textContent = 'Save & get link';
        }
    });

    // Reset form each time the modal opens.
    document.getElementById('saveModal')?.addEventListener('show.bs.modal', () => {
        resultBox.style.display  = 'none';
        errBox.style.display     = 'none';
        document.getElementById('save-title').value     = '';
        document.getElementById('save-slug').value      = '';
        document.getElementById('save-device-id').value = '';
    });
})();
<?php endif; ?>

/* ── Timezone detection ─────────────────────────────────────────────────── */
<?php if ($timezone === ''): ?>
(function () {
    try {
        const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if (tz) fetch(`?settz=1&time=${encodeURIComponent(tz)}`);
    } catch (e) {}
})();
<?php endif; ?>
</script>
</body>
</html>
