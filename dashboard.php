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
$coldata = $colRepo->findPlottable();

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
<style>
/* ── Colour tokens ─────────────────────────────────────── */
:root {
    --dwb-bg:      #0d0d1a;
    --dwb-surface: #1a1a2e;
    --dwb-border:  #2e2e4a;
    --dwb-accent:  #4e9af1;
    --dwb-text:    #c9d1d9;
    --dwb-muted:   #6e7681;
    --dwb-danger:  #f85149;
}

/* ── Reset / base ──────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }
body {
    margin: 0;
    font-family: "Segoe UI", system-ui, sans-serif;
    font-size: 13px;
    background: var(--dwb-bg);
    color: var(--dwb-text);
    overflow-x: hidden;
}

/* ── Top bar ───────────────────────────────────────────── */
#dwb-topbar {
    position: fixed;
    top: 0; left: 0; right: 0;
    height: 48px;
    background: var(--dwb-surface);
    border-bottom: 1px solid var(--dwb-border);
    display: flex;
    align-items: center;
    padding: 0 12px;
    gap: 10px;
    z-index: 1040;
}

#dwb-topbar .brand {
    font-size: 14px;
    font-weight: 700;
    color: var(--dwb-accent);
    white-space: nowrap;
    letter-spacing: .5px;
}

/* Session picker (Chosen) */
#dwb-topbar .session-wrap {
    flex: 0 0 260px;
    position: relative;
}
#dwb-topbar .chosen-container { width: 100% !important; }
#dwb-topbar .chosen-single {
    background: #111128 !important;
    border: 1px solid var(--dwb-border) !important;
    color: var(--dwb-text) !important;
    border-radius: 6px;
    height: 30px !important;
    line-height: 30px !important;
    padding: 0 8px !important;
    box-shadow: none !important;
}
#dwb-topbar .chosen-drop {
    background: #111128;
    border: 1px solid var(--dwb-border);
    border-top: none;
    color: var(--dwb-text);
    box-shadow: 0 4px 12px rgba(0,0,0,.6);
}
#dwb-topbar .chosen-results li { color: var(--dwb-text); }
#dwb-topbar .chosen-results li.highlighted { background: var(--dwb-accent); color: #fff; }

/* Grid preset pills */
#grid-presets { display: flex; gap: 4px; flex-shrink: 0; }
.grid-pill {
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 20px;
    border: 1px solid var(--dwb-border);
    background: transparent;
    color: var(--dwb-muted);
    cursor: pointer;
    transition: background .15s, color .15s;
    white-space: nowrap;
}
.grid-pill:hover, .grid-pill.active {
    background: var(--dwb-accent);
    border-color: var(--dwb-accent);
    color: #fff;
}

/* Right side actions */
#dwb-topbar .topbar-right {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

/* ── Main canvas ───────────────────────────────────────── */
#dwb-canvas {
    margin-top: 48px;
    padding: 12px;
}

/* ── Panel grid ────────────────────────────────────────── */
#panel-grid {
    display: grid;
    grid-template-columns: repeat(var(--grid-cols, 3), 1fr);
    gap: 10px;
}

.dwb-panel {
    background: var(--dwb-surface);
    border: 1px solid var(--dwb-border);
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    min-height: 220px;
    overflow: hidden;
}

.panel-header {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-bottom: 1px solid var(--dwb-border);
    background: rgba(0,0,0,.15);
    flex-shrink: 0;
}

.panel-sensor-select {
    flex: 1;
    font-size: 12px;
    background: transparent;
    border: none;
    color: var(--dwb-text);
    cursor: pointer;
    outline: none;
    min-width: 0;
}
.panel-sensor-select option { background: #1a1a2e; }

.panel-menu-btn {
    flex-shrink: 0;
    background: none;
    border: none;
    color: var(--dwb-muted);
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    padding: 0 2px;
    border-radius: 4px;
}
.panel-menu-btn:hover { color: var(--dwb-text); background: rgba(255,255,255,.07); }

.panel-body {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    min-height: 180px;
    padding: 8px;
    overflow: hidden;
}

.panel-empty {
    text-align: center;
    color: var(--dwb-muted);
}
.panel-empty .empty-icon { font-size: 28px; margin-bottom: 6px; }
.panel-empty p { font-size: 11px; margin: 0; }

.panel-chart-area {
    width: 100%;
    height: 100%;
    min-height: 160px;
    overflow: hidden;
}

/* uPlot overrides — blend into dark theme */
.u-wrap { background: transparent !important; }
.u-title { display: none; }
.u-cursor-x, .u-cursor-y { border-color: rgba(255,255,255,0.25) !important; }
.u-cursor-pt { border-radius: 50%; }
.u-legend { color: #8b97a8; font-size: 11px; }


/* Panel spinner */
.panel-spinner {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    color: var(--dwb-muted);
    font-size: 11px;
}

/* ── Summary table section ─────────────────────────────── */
#summary-section {
    margin-top: 14px;
}
#summary-section h6 {
    font-size: 12px;
    color: var(--dwb-muted);
    text-transform: uppercase;
    letter-spacing: .6px;
    margin-bottom: 8px;
}
#summary-table-wrap {
    overflow-x: auto;
}
#summary-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
#summary-table th, #summary-table td {
    padding: 5px 10px;
    border-bottom: 1px solid var(--dwb-border);
    white-space: nowrap;
}
#summary-table th {
    color: var(--dwb-muted);
    font-weight: 500;
    text-align: left;
    background: rgba(0,0,0,.2);
}
#summary-table tr:hover td { background: rgba(255,255,255,.03); }
#summary-table td:nth-child(n+3) { text-align: right; font-variant-numeric: tabular-nums; }
.spark-cell canvas { vertical-align: middle; }

/* Add-to-panel button */
.btn-add-panel {
    padding: 1px 6px;
    font-size: 11px;
    border-radius: 4px;
    border: 1px solid var(--dwb-border);
    background: transparent;
    color: var(--dwb-muted);
    cursor: pointer;
    transition: background .15s, color .15s;
}
.btn-add-panel:hover {
    background: var(--dwb-accent);
    border-color: var(--dwb-accent);
    color: #fff;
}

/* Summary pagination */
#summary-pagination {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
    font-size: 12px;
    color: var(--dwb-muted);
}
#summary-pagination button {
    background: var(--dwb-surface);
    border: 1px solid var(--dwb-border);
    color: var(--dwb-text);
    border-radius: 4px;
    padding: 2px 8px;
    cursor: pointer;
    font-size: 12px;
}
#summary-pagination button:disabled { opacity: .35; cursor: default; }

/* ── Map modal ─────────────────────────────────────────── */
#mapModal .modal-content {
    background: var(--dwb-surface);
    border: 1px solid var(--dwb-border);
}
#mapModal .modal-header {
    border-bottom: 1px solid var(--dwb-border);
}
#map { height: 420px; background: #111; border-radius: 4px; }

/* ── Session actions modal ─────────────────────────────── */
#actionsModal .modal-content {
    background: var(--dwb-surface);
    border: 1px solid var(--dwb-border);
}
#actionsModal .modal-header { border-bottom: 1px solid var(--dwb-border); }
#actionsModal .form-label { font-size: 12px; color: var(--dwb-muted); }

/* ── Export modal ──────────────────────────────────────── */
#exportModal .modal-content {
    background: var(--dwb-surface);
    border: 1px solid var(--dwb-border);
}
#exportModal .modal-header { border-bottom: 1px solid var(--dwb-border); }

/* ── Save modal ────────────────────────────────────────────── */
#saveModal .modal-content {
    background: var(--dwb-surface);
    border: 1px solid var(--dwb-border);
}
#saveModal .modal-header { border-bottom: 1px solid var(--dwb-border); }
#saveModal .form-label { font-size: 12px; color: var(--dwb-muted); }
#saveModal .form-control, #saveModal .form-control:focus {
    background: #111128;
    border-color: var(--dwb-border);
    color: var(--dwb-text);
    box-shadow: none;
}
#save-result-box {
    background: rgba(78,154,241,.1);
    border: 1px solid var(--dwb-accent);
    border-radius: 6px;
    padding: 10px 12px;
    font-size: 12px;
    word-break: break-all;
}
#save-result-box a { color: var(--dwb-accent); }

/* ── Utilities ─────────────────────────────────────────── */
.text-muted-dwb { color: var(--dwb-muted) !important; }
</style>
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

    <!-- Right actions -->
    <div class="topbar-right">
        <?php if ($hasSession && count($geolocs) > 0): ?>
        <button class="btn btn-sm btn-outline-secondary"
                data-bs-toggle="modal" data-bs-target="#mapModal">
            🗺 Map
        </button>
        <?php endif; ?>

        <?php if ($hasSession): ?>
        <button class="btn btn-sm btn-outline-warning"
                data-bs-toggle="modal" data-bs-target="#saveModal"
                title="Save this dashboard layout">
            ⭐ Save
        </button>
        <a href="export.php?id=<?= urlencode($session_id) ?>&format=csv"
           class="btn btn-sm btn-outline-secondary">
            ⬇ CSV
        </a>
        <button class="btn btn-sm btn-outline-secondary"
                data-bs-toggle="modal" data-bs-target="#actionsModal">
            ⋮
        </button>
        <?php endif; ?>
    </div>
</nav>

<!-- ════════════════════════════════════════════════════════════ MAIN CANVAS -->
<div id="dwb-canvas">

    <!-- Panel grid -->
    <div id="panel-grid"
         style="--grid-cols:<?= $gridCols ?>;"
         data-grid-cols="<?= $gridCols ?>"
         data-grid-rows="<?= $gridRows ?>">

        <?php for ($i = 0; $i < $panelCount; $i++):
            $p          = $panels[$i];
            $sensorKey  = $p['sensor'];
            $cs         = $p['cs'];
            $rs         = $p['rs'];
            $hasPlot    = ($hasSession && $sensorKey !== '');
            $colStyle   = ($cs > 1) ? "grid-column:span {$cs};" : '';
            $rowStyle   = ($rs > 1) ? "grid-row:span {$rs};"    : '';
        ?>
        <div class="dwb-panel" id="panel-<?= $i ?>"
             style="<?= $colStyle . $rowStyle ?>"
             data-panel-idx="<?= $i ?>"
             data-cs="<?= $cs ?>"
             data-rs="<?= $rs ?>">

            <!-- Panel header -->
            <div class="panel-header">
                <select class="panel-sensor-select" data-panel-idx="<?= $i ?>">
                    <option value="">— sensor —</option>
                    <?php foreach ($coldata as $col): ?>
                    <option value="<?= htmlspecialchars((string) ($col['key'] ?? ''), ENT_QUOTES) ?>"
                        <?= ((string) ($col['key'] ?? '') === (string) $sensorKey) ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) ($col['label'] ?? ''), ENT_QUOTES) ?>
                        <?php if (!empty($col['unit'])): ?>
                            (<?= htmlspecialchars((string) ($col['unit'] ?? ''), ENT_QUOTES) ?>)
                        <?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <!-- ⋮ panel menu -->
                <div class="dropdown">
                    <button class="panel-menu-btn"
                            data-bs-toggle="dropdown" aria-expanded="false"
                            title="Panel options">⋮</button>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark"
                        style="font-size:12px;min-width:160px;">
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
                 data-key="<?= htmlspecialchars((string) ($sensorKey ?? ''), ENT_QUOTES) ?>">
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
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">GPS Track</h5>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal"></button>
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
        <div class="modal-content" id="saveModal">
            <div class="modal-header">
                <h5 class="modal-title">⭐ Save dashboard</h5>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal"></button>
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
                <div id="save-result-box" style="display:none;"></div>
                <div id="save-error" class="text-danger mt-2" style="display:none;font-size:12px;"></div>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--dwb-border);">
                <button type="button" class="btn btn-secondary btn-sm"
                        data-bs-dismiss="modal">Cancel</button>
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
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal"></button>
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
<script src="static/js/bootstrap.bundle.min.js"></script>
<script src="static/js/chosen.jquery.min.js"></script>
<script src="static/js/jquery.min.js"></script>
<script src="static/js/peity.min.js"></script>
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
            if (p.sensor) u.searchParams.append(`p[${i}][s][]`, p.sensor);
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

        // Re-read current panel state from live DOM (this fixes the reset bug)
        const currentPanels = [];
        document.querySelectorAll('.dwb-panel').forEach(panel => {
            const idx = Number(panel.dataset.panelIdx);
            const sel = panel.querySelector('.panel-sensor-select');
            currentPanels[idx] = {
                sensor: sel ? sel.value : '',
                cs: Number(panel.dataset.cs || 1),
                rs: Number(panel.dataset.rs || 1)
            };
        });

        window.location = buildUrl(sid, GRID_PARAM, currentPanels);
    }

    /** Change grid preset, keep sensors that fit */
    function setGrid(preset) {
        const [r, c] = preset.split('x').map(Number);
        const cap    = r * c;
        const keep   = PANELS_INIT.slice(0, cap);
        while (keep.length < cap) keep.push({ sensor: '', cs: 1, rs: 1 });
        window.location = buildUrl(SESSION_ID, preset, keep);
    }

    /** Change sensor for one panel */
    function setPanelSensor(idx, key) {
        const arr = PANELS_INIT.map(p => Object.assign({}, p));
        arr[idx].sensor = key;
        window.location = buildUrl(SESSION_ID, GRID_PARAM, arr);
    }

    /** Adjust colspan / rowspan of a panel by delta */
    function setPanelSpan(idx, dcs, drs) {
        const arr = PANELS_INIT.map(p => Object.assign({}, p));
        arr[idx].cs = Math.max(1, Math.min(GRID_COLS, arr[idx].cs + dcs));
        arr[idx].rs = Math.max(1, Math.min(GRID_ROWS, arr[idx].rs + drs));
        window.location = buildUrl(SESSION_ID, GRID_PARAM, arr);
    }

    /** Clear sensor from a panel */
    function clearPanel(idx) {
        const arr = PANELS_INIT.map(p => Object.assign({}, p));
        arr[idx].sensor = '';
        window.location = buildUrl(SESSION_ID, GRID_PARAM, arr);
    }

    /** Add sensor to next empty panel */
    function addSensorToNextPanel(key) {
        const arr = PANELS_INIT.map(p => Object.assign({}, p));
        const free = arr.findIndex(p => !p.sensor);
        if (free === -1) {
            alert('All panels are occupied. Clear a panel first.');
            return;
        }
        arr[free].sensor = key;
        window.location = buildUrl(SESSION_ID, GRID_PARAM, arr);
    }

    return { buildUrl, setSession, setGrid, setPanelSensor, setPanelSpan, clearPanel, addSensorToNextPanel };
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
    document.querySelectorAll('.grid-pill').forEach(btn => {
        btn.addEventListener('click', () => DWB.setGrid(btn.dataset.preset));
    });

    // Per-panel sensor selects
    document.querySelectorAll('.panel-sensor-select').forEach(sel => {
        sel.addEventListener('change', () => {
            DWB.setPanelSensor(Number(sel.dataset.panelIdx), sel.value);
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

// Ensure charts only init when we have a valid session + small delay after Chosen.js (FIXED)
if (SESSION_ID) {
    setTimeout(initAllPanels, 200);
}

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

document.getElementById('mapModal')?.addEventListener('shown.bs.modal', () => {
    if (leafletLoaded) return;
    leafletLoaded = true;

    const cssL = document.createElement('link');
    cssL.rel   = 'stylesheet';
    cssL.href  = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    document.head.appendChild(cssL);

    const jsL    = document.createElement('script');
    jsL.src      = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    jsL.onload   = () => initLeaflet();
    document.head.appendChild(jsL);
});

function initLeaflet() {
    const pts = GEO_POINTS.map(p => [p.lat, p.lng]);
    if (pts.length === 0) return;

    leafletMap = L.map('map');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(leafletMap);

    const poly = L.polyline(pts, { color: '#4e9af1', weight: 3 }).addTo(leafletMap);
    L.marker(pts[0], { title: 'Start' }).addTo(leafletMap);
    L.marker(pts[pts.length - 1], { title: 'End' }).addTo(leafletMap);
    leafletMap.fitBounds(poly.getBounds(), { padding: [20, 20] });
}
<?php endif; ?>

/* ── uPlot panel charts — Step 7 ───────────────────────────────────────── */
(function () {
    'use strict';

    if (!SESSION_ID) return;          // no session → nothing to chart

    /* Shared cursor-sync so all visible panels cross-hair together */
    const cursorSync = uPlot.sync('dwb');

    /* uPlot colour palette */
    const LINE_COLOR   = '#4e9af1';
    const FILL_COLOR   = 'rgba(78,154,241,0.08)';
    const GRID_COLOR   = 'rgba(255,255,255,0.06)';
    const TICK_COLOR   = 'rgba(255,255,255,0.20)';
    const LABEL_COLOR  = '#8b97a8';

    /* Map of panelIdx → uPlot instance (for resize observer) */
    const charts = new Map();

    /* Build a minimal uPlot opts object for a single-series panel */
    function buildOpts(label, unit, width, height) {
        return {
            title:  '',
            width:  width,
            height: height,
            cursor: {
                sync: { key: cursorSync.key },
            },
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
            series: [
                {},
                {
                    label:  label,
                    stroke: LINE_COLOR,
                    fill:   FILL_COLOR,
                    width:  1.5,
                    points: { show: false },
                },
            ],
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

    /* Replace the spinner inside a chart-area div with a uPlot instance */
    function mountChart(container, label, unit, data) {
        container.innerHTML = '';
        const w = container.clientWidth  || 400;
        const h = container.clientHeight || 200;
        const opts = buildOpts(label, unit, w, h);
        const u    = new uPlot(opts, data, container);
        return u;
    }

    /* Show an error state in a panel */
    function showError(container, msg) {
        container.innerHTML =
            `<div class="panel-empty"><div class="empty-icon">⚠</div><p>${msg}</p></div>`;
    }

    /* Fetch + render one panel */
    async function loadPanel(container) {
        const sid = container.dataset.sid;
        const key = container.dataset.key;
        const idx = Number(container.closest('.dwb-panel')?.dataset.panelIdx ?? -1);

        try {
            const resp = await fetch(`api/sensor.php?sid=${encodeURIComponent(sid)}&key=${encodeURIComponent(key)}`);
            if (!resp.ok) {
                const err = await resp.json().catch(() => ({ error: `HTTP ${resp.status}` }));
                showError(container, err.error ?? 'Failed to load');
                return;
            }
            const json = await resp.json();
            if (!json.data || json.data.length === 0) {
                showError(container, 'No data');
                return;
            }
            const udata = apiToUplot(json.data);
            const u     = mountChart(container, json.label, json.unit, udata);
            if (idx >= 0) charts.set(idx, u);
        } catch (e) {
            showError(container, 'Network error');
        }
    }

    /* Kick off all panels that have data-key set */
    function initAllPanels() {
        document.querySelectorAll('.panel-chart-area[data-key]').forEach(el => {
            if (el.dataset.key) loadPanel(el);
        });
    }

    /* ResizeObserver — redraw each chart when its container changes size */
    if (typeof ResizeObserver !== 'undefined') {
        const ro = new ResizeObserver(entries => {
            for (const entry of entries) {
                const panel = entry.target.closest('.dwb-panel');
                if (!panel) continue;
                const idx = Number(panel.dataset.panelIdx);
                const u   = charts.get(idx);
                if (!u) continue;
                const area = panel.querySelector('.panel-chart-area');
                if (!area) continue;
                const w = area.clientWidth  || 400;
                const h = area.clientHeight || 200;
                u.setSize({ width: w, height: h });
            }
        });
        document.querySelectorAll('.panel-chart-area').forEach(el => ro.observe(el));
    }

    /* Run after DOM + scripts ready */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAllPanels);
    } else {
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
                 <button type="button" style="margin-left:8px;font-size:11px;
                         padding:1px 6px;border-radius:4px;border:1px solid #4e9af1;
                         background:transparent;color:#4e9af1;cursor:pointer;"
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