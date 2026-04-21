<?php
declare(strict_types=1);
require_once('creds.php');
require_once('auth_user.php');
if (!$logged_in) { exit; }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Torque Live Upload Console</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { margin:0; background:#0d1117; color:#c9d1d9; font-family:'Consolas','Menlo','Liberation Mono',monospace; font-size:13px; }
  header { display:flex; align-items:center; gap:1rem; padding:.6rem 1rem; background:#161b22; border-bottom:1px solid #30363d; }
  header h1 { margin:0; font-size:1rem; color:#58a6ff; }
  #status-dot { width:10px; height:10px; border-radius:50%; background:#3fb950; box-shadow:0 0 6px #3fb950; animation:pulse 2s infinite; }
  #status-dot.error  { background:#f85149; box-shadow:0 0 6px #f85149; animation:none; }
  #status-dot.paused { background:#d29922; box-shadow:0 0 6px #d29922; animation:none; }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
  #controls { display:flex; align-items:center; gap:.5rem; margin-left:auto; }
  button { padding:.3rem .8rem; border:1px solid #30363d; border-radius:4px; background:#21262d; color:#c9d1d9; cursor:pointer; font-size:.8rem; }
  button:hover { background:#30363d; }
  button.active { border-color:#58a6ff; color:#58a6ff; }
  #stats-bar { padding:.35rem 1rem; background:#161b22; border-bottom:1px solid #30363d; font-size:.75rem; color:#8b949e; display:flex; gap:2rem; flex-wrap:wrap; }
  #stats-bar span { color:#c9d1d9; }
  #log-wrap { overflow-x:auto; height:calc(100vh - 88px); overflow-y:auto; }
  table { width:100%; border-collapse:collapse; white-space:nowrap; }
  thead th { position:sticky; top:0; z-index:2; background:#161b22; border-bottom:1px solid #30363d; padding:.4rem .7rem; text-align:left; color:#8b949e; font-weight:normal; font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; }
  tbody tr { border-bottom:1px solid #21262d; }
  tbody tr:hover { background:#161b22; }
  tbody td { padding:.35rem .7rem; vertical-align:top; }
  .result-ok { color:#3fb950; } .result-skipped { color:#d29922; } .result-error { color:#f85149; }
  .new-row { animation:flash .8s ease-out; }
  @keyframes flash { 0%{background:#1f3045} 100%{background:transparent} }
  .sensor-btn { cursor:pointer; color:#58a6ff; text-decoration:underline dotted #58a6ff55; background:none; border:none; font:inherit; padding:0; }
  .sensor-btn:hover { color:#79c0ff; }
  .sensor-btn.no-data { color:#8b949e; text-decoration:none; cursor:default; }
  .new-col-badge { color:#d29922; }
  .stored-yes { color:#3fb950; }
  .stored-no  { color:#f85149; }
  .stored-skip{ color:#d29922; }
  .stored-reason { display:block; font-size:.72rem; color:#8b949e; white-space:normal; max-width:320px; word-break:break-word; margin-top:.15rem; }
  .error-msg { color:#f85149; font-size:.75rem; max-width:280px; white-space:normal; word-break:break-word; }
  #empty-msg { padding:2rem; text-align:center; color:#484f58; }
  abbr { cursor:help; text-decoration:underline dotted #484f58; }
  /* Modal */
  #modal-overlay { display:none; position:fixed; inset:0; background:#00000099; z-index:100; align-items:center; justify-content:center; }
  #modal-overlay.open { display:flex; }
  #modal { background:#161b22; border:1px solid #30363d; border-radius:8px; width:min(700px,95vw); max-height:82vh; display:flex; flex-direction:column; box-shadow:0 16px 48px #00000088; }
  #modal-header { display:flex; align-items:flex-start; justify-content:space-between; padding:.75rem 1rem; border-bottom:1px solid #30363d; }
  #modal-title { font-size:.9rem; color:#58a6ff; margin:0; }
  #modal-meta { font-size:.72rem; color:#8b949e; margin:.2rem 0 0; }
  #modal-close { background:none; border:none; color:#8b949e; font-size:1.2rem; cursor:pointer; line-height:1; padding:0 .2rem; margin-top:-.1rem; }
  #modal-close:hover { color:#c9d1d9; }
  #modal-search-wrap { padding:.4rem 1rem; border-bottom:1px solid #21262d; }
  #modal-search { width:100%; background:#0d1117; border:1px solid #30363d; border-radius:4px; color:#c9d1d9; font:inherit; padding:.3rem .6rem; font-size:.8rem; }
  #modal-search:focus { outline:none; border-color:#58a6ff; }
  #modal-body { overflow-y:auto; }
  #sensor-table { width:100%; border-collapse:collapse; }
  #sensor-table th { text-align:left; padding:.3rem 1rem; font-size:.7rem; text-transform:uppercase; letter-spacing:.04em; color:#484f58; border-bottom:1px solid #21262d; }
  #sensor-table td { padding:.3rem 1rem; border-bottom:1px solid #21262d; font-size:.8rem; }
  #sensor-table tr:last-child td { border-bottom:none; }
  #sensor-table tr:hover td { background:#0d1117; }
  .val-zero { color:#484f58; } .val-nonzero { color:#3fb950; }
  #modal-empty { padding:1.5rem; text-align:center; color:#484f58; font-size:.85rem; }
  #modal-summary { padding:.35rem 1rem; font-size:.75rem; color:#8b949e; border-bottom:1px solid #21262d; }
  #modal-summary b { color:#c9d1d9; }
</style>
</head>
<body>
<header>
  <div id="status-dot"></div>
  <h1>⚡ Torque Live Upload Console</h1>
  <div id="controls">
    <button id="btn-pause">⏸ Pause</button>
    <button id="btn-clear">🗑 Clear view</button>
    <button id="btn-autoscroll" class="active">⬇ Auto-scroll</button>
  </div>
</header>
<div id="stats-bar">
  <div>Received: <span id="stat-total">0</span></div>
  <div>✔ Stored: <span id="stat-ok">0</span></div>
  <div>⏭ No data: <span id="stat-skipped">0</span></div>
  <div>✖ Failed: <span id="stat-errors">0</span></div>
  <div>New cols: <span id="stat-newcols">0</span></div>
  <div>Last poll: <span id="stat-lastts">—</span></div>
</div>
<div id="log-wrap">
  <table id="log-table">
    <thead><tr>
      <th>#</th><th>Server time</th><th>IP</th>
      <th>Email</th><th>Session start</th><th>Data ts</th>
      <th>Sensors</th><th>App ver</th><th>Profile</th>
      <th>Stored in DB?</th>
    </tr></thead>
    <tbody id="log-body"></tbody>
  </table>
  <div id="empty-msg">Waiting for uploads… (polling every 2 s)</div>
</div>

<!-- Sensor modal -->
<div id="modal-overlay" role="dialog" aria-modal="true">
  <div id="modal">
    <div id="modal-header">
      <div>
        <h2 id="modal-title">Sensor data</h2>
        <div id="modal-meta"></div>
      </div>
      <button id="modal-close" title="Close (Esc)">✕</button>
    </div>
    <div id="modal-search-wrap">
      <input id="modal-search" type="search" placeholder="Filter by key, name or value…" autocomplete="off">
    </div>
    <div id="modal-summary"></div>
    <div id="modal-body">
      <div id="modal-empty"></div>
      <table id="sensor-table">
        <thead><tr><th>Key</th><th>Sensor name</th><th>Value</th><th>Has data?</th></tr></thead>
        <tbody id="sensor-body"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function(){
'use strict';

const POLL_MS  = 2000;
const MAX_ROWS = 500;

let sinceId    = 0;
let paused     = false;
let autoScroll = true;
let timer      = null;
// Fallback names for well-known Torque PIDs (shown when DB comment is empty)
const KNOWN_SENSORS = {
  'k4':'Engine Load','k5':'Engine Coolant Temp','k6':'ST Fuel Trim B1',
  'k7':'LT Fuel Trim B1','ka':'Fuel Pressure','kb':'Intake Manifold Pressure',
  'kc':'Engine RPM','kd':'Speed (OBD)','ke':'Timing Advance',
  'kf':'Intake Air Temp','k10':'Mass Air Flow','k11':'Throttle Position',
  'k1f':'Run Time Since Start','k21':'Distance MIL On',
  'k22':'Fuel Rail Pressure','k2c':'EGR Commanded','k2d':'EGR Error',
  'k2f':'Fuel Level','k33':'Barometric Pressure','k42':'Control Module Voltage',
  'k43':'Absolute Engine Load','k46':'Ambient Air Temp',
  'k52':'Ethanol %','k5c':'Engine Oil Temp','k5e':'Engine Fuel Rate',
  'kff1001':'Speed (GPS)','kff1005':'GPS Longitude','kff1006':'GPS Latitude',
  'kff1007':'GPS Bearing','kff1008':'GPS Satellites',
  'kff1009':'GPS vs OBD Speed diff','kff1205':'Turbo Boost & Vacuum',
  'kff1210':'Air Fuel Ratio (Measured)',
  'kff1220':'Acceleration Sensor X','kff1221':'Acceleration Sensor Y',
  'kff1222':'Acceleration Sensor Z','kff1223':'Acceleration Sensor Total',
  'kff1224':'Tilt (X)','kff1225':'Tilt (Y)',
};
let colNames = Object.assign({}, KNOWN_SENSORS);
let stats      = {total:0, ok:0, skipped:0, errors:0, newcols:0};

const tbody     = document.getElementById('log-body');
const emptyMsg  = document.getElementById('empty-msg');
const statusDot = document.getElementById('status-dot');
const logWrap   = document.getElementById('log-wrap');

// ── Utils ───────────────────────────────────────────────────────────────
function esc(s){ if(s==null)return''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

const T={hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false};
const DT={year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false};

function fmt24(s){ // "YYYY-MM-DD HH:MM:SS"
  if(!s)return'—';
  const d=new Date(s.replace(' ','T'));
  return isNaN(d)?s:d.toLocaleString([],DT);
}
function fmtMs(ms){
  if(!ms)return'—';
  const d=new Date(parseInt(ms,10));
  return isNaN(d)?String(ms):d.toLocaleString([],DT);
}
function nowTime(){ return new Date().toLocaleTimeString([],T); }

function setOk()    { statusDot.className=''; }
function setErr()   { statusDot.className='error'; }
function setPaused(){ statusDot.className='paused'; }

function updStats(){
  document.getElementById('stat-total').textContent   = stats.total;
  document.getElementById('stat-ok').textContent      = stats.ok;
  document.getElementById('stat-skipped').textContent = stats.skipped;
  document.getElementById('stat-errors').textContent  = stats.errors;
  document.getElementById('stat-newcols').textContent = stats.newcols;
  document.getElementById('stat-lastts').textContent  = nowTime();
}

// ── Modal ────────────────────────────────────────────────────────────────
const overlay    = document.getElementById('modal-overlay');
const modalTitle = document.getElementById('modal-title');
const modalMeta  = document.getElementById('modal-meta');
const modalSumm  = document.getElementById('modal-summary');
const modalClose = document.getElementById('modal-close');
const modalSearch= document.getElementById('modal-search');
const sensorBody = document.getElementById('sensor-body');
const modalEmpty = document.getElementById('modal-empty');
const sensorTbl  = document.getElementById('sensor-table');
let   curRow     = null;

function openModal(r){
  curRow = r;
  modalTitle.textContent = 'Sensor data — request #'+r.id;
  modalMeta.textContent  = fmt24(r.ts)+'  ·  IP '+r.ip+(r.eml?'  ·  '+r.eml:'')+'  ·  session '+fmtMs(r.session);
  modalSearch.value='';
  renderSensors('');
  overlay.classList.add('open');
  modalSearch.focus();
}
function closeModal(){ overlay.classList.remove('open'); curRow=null; }

function renderSensors(filter){
  sensorBody.innerHTML='';
  const sensors = curRow && curRow.sensor_data ? curRow.sensor_data : null;
  if(!sensors || !Object.keys(sensors).length){
    modalEmpty.textContent='No sensor data stored for this request.';
    modalEmpty.style.display='';
    sensorTbl.style.display='none';
    modalSumm.textContent='';
    return;
  }
  const lf=filter.toLowerCase();
  let shown=0, withData=0, total=Object.keys(sensors).length;
  for(const [key,val] of Object.entries(sensors)){
    const name = colNames[key]||'';
    if(lf && !key.toLowerCase().includes(lf) && !name.toLowerCase().includes(lf) && !String(val).includes(lf)) continue;
    const hasData = val!==''&&val!=='0'&&val!==null;
    if(hasData) withData++;
    const tr=document.createElement('tr');
    tr.innerHTML=
      '<td style="color:#8b949e">'+esc(key)+'</td>'+
      '<td>'+esc(name||'—')+'</td>'+
      '<td class="'+(hasData?'val-nonzero':'val-zero')+'">'+esc(String(val))+'</td>'+
      '<td>'+(hasData?'<span style="color:#3fb950">✔ yes</span>':'<span style="color:#484f58">— no</span>')+'</td>';
    sensorBody.appendChild(tr);
    shown++;
  }
  const noMatch = shown===0;
  modalEmpty.style.display  = noMatch?'':'none';
  modalEmpty.textContent    = noMatch?'No sensors match the filter.':'';
  sensorTbl.style.display   = noMatch?'none':'';
  if(!filter){
    modalSumm.innerHTML = '<b>'+withData+'</b> of <b>'+total+'</b> sensors have non-zero data';
  } else {
    modalSumm.innerHTML = 'Showing <b>'+shown+'</b> match'+(shown!==1?'es':'');
  }
}

modalClose.addEventListener('click', closeModal);
overlay.addEventListener('click', function(e){ if(e.target===overlay) closeModal(); });
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeModal(); });
modalSearch.addEventListener('input', function(){ renderSensors(this.value); });

// ── Row builder ──────────────────────────────────────────────────────────
function appendRows(rows){
  if(!rows.length)return;
  emptyMsg.style.display='none';
  const frag=document.createDocumentFragment();
  rows.forEach(function(r){
    stats.total++;
    if(r.result==='ok') stats.ok++;
    else if(r.result==='skipped') stats.skipped++;
    else stats.errors++;
    stats.newcols += r.new_columns||0;
    if(r.id>sinceId) sinceId=r.id;

    const tr=document.createElement('tr');
    tr.className='new-row';
    const hasSensors=r.sensor_data&&Object.keys(r.sensor_data).length>0;
    const sCell=hasSensors
      ?'<button class="sensor-btn">'+r.sensor_count+'</button>'
      :'<button class="sensor-btn no-data" disabled title="No sensor data stored">'+r.sensor_count+'</button>';

    // ── Stored in DB cell ──────────────────────────────────────────────────
    let storedCell;
    if(r.result==='ok'){
      storedCell='<td class="stored-yes">✔ yes</td>';
    } else if(r.result==='skipped'){
      const reason=r.error_msg||'No sensor data in request';
      storedCell='<td class="stored-skip">⏭ no<span class="stored-reason">'+esc(reason)+'</span></td>';
    } else {
      const reason=r.error_msg||'Unknown error';
      storedCell='<td class="stored-no">✖ no<span class="stored-reason">'+esc(reason)+'</span></td>';
    }

    tr.innerHTML=
      '<td style="color:#484f58">'+esc(String(r.id))+'</td>'+
      '<td>'+esc(fmt24(r.ts))+'</td>'+
      '<td><abbr title="Device ID: '+esc(r.torque_id)+'">'+esc(r.ip)+'</abbr></td>'+
      '<td>'+(esc(r.eml)||'<span style="color:#484f58">—</span>')+'</td>'+
      '<td>'+fmtMs(r.session)+'</td>'+
      '<td>'+fmtMs(r.data_ts)+'</td>'+
      '<td style="text-align:right">'+sCell+'</td>'+
      '<td>'+(esc(r.app_version)||'—')+'</td>'+
      '<td>'+(esc(r.profile_name)||'—')+'</td>'+
      storedCell;

    const btn=tr.querySelector('.sensor-btn:not(.no-data)');
    if(btn){ btn.addEventListener('click',function(){ openModal(r); }); }
    frag.appendChild(tr);
  });

  tbody.appendChild(frag);
  while(tbody.rows.length>MAX_ROWS) tbody.deleteRow(0);
  updStats();
  if(autoScroll) logWrap.scrollTop=logWrap.scrollHeight;
}

// ── Polling ──────────────────────────────────────────────────────────────
function poll(){
  if(paused)return;
  fetch('live_log_data.php?since_id='+sinceId,{credentials:'same-origin'})
    .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
    .then(function(d){
      if(d.error) throw new Error(d.error);
      setOk();
      if(d.col_names) Object.assign(colNames,d.col_names);
      if(d.rows&&d.rows.length) appendRows(d.rows);
      else updStats();
    })
    .catch(function(e){
      setErr();
      document.getElementById('stat-lastts').textContent=nowTime()+' ⚠';
      console.error('Poll:',e);
    });
}

function startPolling(){ timer=setInterval(poll,POLL_MS); poll(); }
function stopPolling() { clearInterval(timer); timer=null; }

// ── Controls ─────────────────────────────────────────────────────────────
document.getElementById('btn-pause').addEventListener('click',function(){
  paused=!paused;
  if(paused){ stopPolling(); setPaused(); this.textContent='▶ Resume'; }
  else      { startPolling(); this.textContent='⏸ Pause'; }
});
document.getElementById('btn-clear').addEventListener('click',function(){
  while(tbody.rows.length) tbody.deleteRow(0);
  emptyMsg.style.display='';
  stats={total:0,ok:0,skipped:0,errors:0,newcols:0};
  updStats();
});
document.getElementById('btn-autoscroll').addEventListener('click',function(){
  autoScroll=!autoScroll;
  this.classList.toggle('active',autoScroll);
  if(autoScroll) logWrap.scrollTop=logWrap.scrollHeight;
});
logWrap.addEventListener('scroll',function(){
  const atBottom=logWrap.scrollTop+logWrap.clientHeight>=logWrap.scrollHeight-40;
  if(!atBottom&&autoScroll){
    autoScroll=false;
    document.getElementById('btn-autoscroll').classList.remove('active');
  }
});

// ── Boot ─────────────────────────────────────────────────────────────────
fetch('live_log_data.php?since_id=0',{credentials:'same-origin'})
  .then(function(r){ return r.json(); })
  .then(function(d){
    if(d.col_names) Object.assign(colNames,d.col_names);
    if(d.rows&&d.rows.length) appendRows(d.rows);
    startPolling();
  })
  .catch(function(){ startPolling(); });

})();
</script>
</body>
</html>
