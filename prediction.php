<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
include("../config/db.php");

/* =========================================================
   AI PREDICTION SERVICE
========================================================= */

function getPredictedHotspots()
{
    global $conn;
    $url = "http://127.0.0.1:5000/predict";
    $sql = "
        SELECT latitude AS lat, longitude AS lng, incident_date
        FROM incidents
        WHERE latitude IS NOT NULL AND longitude IS NOT NULL
    ";
    $res = $conn->query($sql);
    if (!$res || $res->num_rows === 0) return [];
    $data = [];
    while ($row = $res->fetch_assoc()) {
        $data[] = [
            "lat"       => (float)$row['lat'],
            "lng"       => (float)$row['lng'],
            "timestamp" => !empty($row['incident_date']) ? strtotime($row['incident_date']) : time()
        ];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(["incidents" => $data]),
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
        CURLOPT_TIMEOUT        => 5
    ]);
    $response = curl_exec($ch);
    $error    = curl_errno($ch);
    curl_close($ch);
    if ($error) return [];
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : [];
}

/* =========================================================
   FETCH PATROL ASSIGNMENTS
========================================================= */

function getPatrolAssignments()
{
    global $conn;
    $sql = "
        SELECT id, unit_name, target_barangay, status,
               assigned_at, updated_at, lat, lng, risk_level
        FROM patrol_assignments
        ORDER BY updated_at DESC
        LIMIT 20
    ";
    $res = $conn->query($sql);
    if (!$res) return [];
    $assignments = [];
    while ($row = $res->fetch_assoc()) $assignments[] = $row;
    return $assignments;
}

/* =========================================================
   FETCH & AGGREGATE INCIDENT DATA
========================================================= */

$sql = "
    SELECT ROUND(latitude,4)  AS lat,
           ROUND(longitude,4) AS lng,
           COUNT(*)           AS freq,
           MAX(incident_date) AS last_seen,
           type,
           TRIM(barangay)     AS barangay
    FROM incidents
    WHERE latitude  IS NOT NULL
    AND   longitude IS NOT NULL
    GROUP BY lat, lng, type, TRIM(barangay)
";

$res = $conn->query($sql);

$typeWeight = [
    "Murder"   => 1.0,
    "Robbery"  => 0.9,
    "Drugs"    => 0.8,
    "Accident" => 0.6,
    "Theft"    => 0.7
];

$points          = [];
$barangaySummary = [];

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $daysAgo    = !empty($row['last_seen']) ? (time() - strtotime($row['last_seen'])) / 86400 : 999;
        $timeFactor = max(0.2, 1 - ($daysAgo / 60));
        $weight     = $typeWeight[$row['type']] ?? 0.5;
        $risk       = min(100, ($row['freq'] * 12 * $weight) * $timeFactor);
        $level      = ($risk >= 70) ? "high" : (($risk >= 40) ? "medium" : "low");

        $reasons = [];
        if ($row['freq'] >= 5)  $reasons[] = "High frequency ({$row['freq']} incidents)";
        if ($daysAgo <= 7)      $reasons[] = "Recent activity (last 7 days)";
        elseif ($daysAgo <= 30) $reasons[] = "Moderate recent activity (last 30 days)";
        else                    $reasons[] = "Old but recurring pattern";
        if ($weight >= 0.8)     $reasons[] = "High-impact crime type weight";
        elseif ($weight >= 0.6) $reasons[] = "Moderate crime severity weight";
        else                    $reasons[] = "Low severity category";
        if ($risk >= 70)        $reasons[] = "Matches high-risk threshold model";

        $point = [
            "lat"            => (float)$row['lat'],
            "lng"            => (float)$row['lng'],
            "risk"           => (float)$risk,
            "level"          => $level,
            "type"           => $row['type'],
            "barangay"       => trim($row['barangay']),
            "last_seen"      => $row['last_seen'],
            "ai_explanation" => implode(" + ", $reasons)
        ];

        $points[]  = $point;
        $barangay  = trim($row['barangay']);

        if (!isset($barangaySummary[$barangay])) {
            $barangaySummary[$barangay] = [
                "barangay" => $barangay,
                "lat"      => $point['lat'],
                "lng"      => $point['lng'],
                "risk_sum" => 0,
                "count"    => 0
            ];
        }
        $barangaySummary[$barangay]["risk_sum"] += $risk;
        $barangaySummary[$barangay]["count"]++;
    }
}

/* =========================================================
   BARANGAY SUMMARY
========================================================= */

$barangayPoints = [];
foreach ($barangaySummary as $b) {
    $avgRisk          = $b["risk_sum"] / max(1, $b["count"]);
    $barangayPoints[] = [
        "barangay" => $b["barangay"],
        "lat"      => $b["lat"],
        "lng"      => $b["lng"],
        "risk"     => $avgRisk,
        "level"    => ($avgRisk >= 70) ? "high" : (($avgRisk >= 40) ? "medium" : "low")
    ];
}

/* =========================================================
   AI PREDICTIONS
========================================================= */

$predicted = getPredictedHotspots();

/* =========================================================
   TIME-SERIES FORECASTING
========================================================= */

$forecastSql = "
    SELECT TRIM(barangay) AS barangay, DATE(incident_date) AS day, COUNT(*) AS incidents
    FROM incidents
    WHERE incident_date IS NOT NULL AND incident_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY TRIM(barangay), day
    ORDER BY TRIM(barangay), day
";

$forecastRes        = $conn->query($forecastSql);
$barangayTimeSeries = [];

if ($forecastRes) {
    while ($row = $forecastRes->fetch_assoc()) {
        $b = trim($row['barangay']);
        if (!isset($barangayTimeSeries[$b])) $barangayTimeSeries[$b] = [];
        $barangayTimeSeries[$b][] = ["day" => $row['day'], "count" => (int)$row['incidents']];
    }
}

$forecast = [];
foreach ($barangayTimeSeries as $barangay => $series) {
    if (count($series) < 3) continue;
    $values      = array_column($series, 'count');
    $recent      = array_slice($values, -7);
    $previous    = array_slice($values, -14, 7);
    $recentAvg   = count($recent)   ? array_sum($recent)   / count($recent)   : 0;
    $prevAvg     = count($previous) ? array_sum($previous) / count($previous) : 0;
    $trend       = ($recentAvg - $prevAvg);
    $tomorrow    = max(0, $recentAvg + ($trend * 0.2));
    $weekForecast = max(0, $recentAvg + ($trend * 1.2));
    $riskScore   = min(100, $weekForecast * 10);
    $direction   = "stable";
    if ($trend > 0.5)      $direction = "rising";
    elseif ($trend < -0.5) $direction = "declining";
    $forecast[] = [
        "barangay"      => $barangay,
        "tomorrow_risk" => round($tomorrow * 10, 2),
        "week_risk"     => round($riskScore, 2),
        "trend"         => $direction
    ];
}

/* =========================================================
   PATROL ASSIGNMENTS
========================================================= */

$patrolAssignments = getPatrolAssignments();

/* =========================================================
   GOOGLE MAPS API KEY (shared from existing usage)
========================================================= */
$gmKey = "AIzaSyASORp7VucTts2Rvp99-m6MmoME1mluaw8";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#0a0f1e">
<title>CRIMESYNC | AI COMMAND</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">

<style>
/* =========================================================
   CSS VARIABLES
========================================================= */
:root {
    --bg:           #0a0f1e;
    --surface:      rgba(12,20,40,0.94);
    --surface2:     rgba(20,32,60,0.88);
    --border:       rgba(56,189,248,0.15);
    --border2:      rgba(56,189,248,0.08);
    --accent:       #38bdf8;
    --accent2:      #0ea5e9;
    --purple:       #c084fc;
    --danger:       #ef4444;
    --warning:      #fbbf24;
    --success:      #22c55e;
    --orange:       #fb923c;
    --enroute:      #f59e0b;
    --onsite:       #059669;
    --text:         #e2e8f0;
    --text2:        #94a3b8;
    --text3:        #64748b;

    --nav-h:        56px;
    --header-h:     52px;
    --safe-bottom:  env(safe-area-inset-bottom, 0px);
    --safe-top:     env(safe-area-inset-top, 0px);

    --font-main:    'Rajdhani', sans-serif;
    --font-mono:    'Share Tech Mono', monospace;
}

/* =========================================================
   RESET & BASE
========================================================= */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
    width: 100%; height: 100%;
    overflow: hidden;
    background: var(--bg);
    color: var(--text);
    font-family: var(--font-main);
    -webkit-tap-highlight-color: transparent;
    -webkit-font-smoothing: antialiased;
}

/* =========================================================
   APP SHELL
========================================================= */
#app {
    display: flex;
    flex-direction: column;
    width: 100%;
    height: 100%;
    padding-top: var(--safe-top);
    padding-bottom: var(--safe-bottom);
}

/* =========================================================
   HEADER
========================================================= */
#header {
    flex-shrink: 0;
    height: var(--header-h);
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 14px;
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    z-index: 200;
    position: relative;
}

.header-brand    { display: flex; align-items: center; gap: 8px; }
.header-logo {
    width: 28px; height: 28px; border-radius: 6px;
    background: linear-gradient(135deg, var(--accent2), var(--purple));
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700; color: #fff; font-family: var(--font-mono);
}
.header-title {
    font-size: 16px; font-weight: 700; letter-spacing: 2px;
    color: var(--accent); font-family: var(--font-main); text-transform: uppercase;
}
.header-title span { color: var(--text2); font-weight: 500; }
.header-right { display: flex; align-items: center; gap: 10px; }

.live-pill {
    display: flex; align-items: center; gap: 5px;
    background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.3);
    border-radius: 20px; padding: 4px 10px;
    font-size: 10px; font-weight: 600; letter-spacing: 1px;
    color: var(--success); text-transform: uppercase; font-family: var(--font-mono);
}
.live-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--success); animation: blink 1.2s infinite; }
@keyframes blink { 0%,100%{opacity:1;} 50%{opacity:0.2;} }

.header-menu-btn {
    width: 32px; height: 32px; border-radius: 8px;
    background: var(--surface2); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: var(--text2); font-size: 18px; transition: background 0.2s;
}
.header-menu-btn:active { background: var(--border); }

/* =========================================================
   MAIN CONTENT AREA
========================================================= */
#main { flex: 1; position: relative; overflow: hidden; display: flex; flex-direction: column; }

/* =========================================================
   MAP
========================================================= */
#map { position: absolute; inset: 0; width: 100%; height: 100%; z-index: 1; }

/* =========================================================
   STATS BAR
========================================================= */
#stats-bar {
    position: absolute; top: 10px; left: 10px; right: 10px; z-index: 100;
    display: flex; gap: 6px; overflow-x: auto;
    -webkit-overflow-scrolling: touch; scrollbar-width: none; pointer-events: none;
}
#stats-bar::-webkit-scrollbar { display: none; }

.stat-chip {
    flex-shrink: 0; display: flex; flex-direction: column; align-items: center;
    background: var(--surface); border: 1px solid var(--border); border-radius: 10px;
    padding: 6px 10px; min-width: 64px;
    backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); pointer-events: auto;
}
.stat-chip .val  { font-size: 18px; font-weight: 700; line-height: 1; font-family: var(--font-mono); }
.stat-chip .lbl  { font-size: 8px; letter-spacing: 0.5px; text-transform: uppercase; color: var(--text3); margin-top: 3px; text-align: center; font-family: var(--font-main); font-weight: 500; }
.stat-chip.accent  .val { color: var(--accent); }
.stat-chip.danger  .val { color: var(--danger); }
.stat-chip.purple  .val { color: var(--purple); }
.stat-chip.orange  .val { color: var(--orange); }
.stat-chip.success .val { color: var(--success); }

/* =========================================================
   FAB BUTTONS
========================================================= */
#fab-group {
    position: absolute; right: 12px; bottom: 80px; z-index: 100;
    display: flex; flex-direction: column; gap: 10px;
}
.fab {
    width: 44px; height: 44px; border-radius: 12px; border: 1px solid var(--border);
    background: var(--surface); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    display: flex; align-items: center; justify-content: center; cursor: pointer;
    font-size: 18px; transition: transform 0.15s, background 0.2s;
    box-shadow: 0 4px 12px rgba(0,0,0,0.4); color: var(--text2);
    -webkit-tap-highlight-color: transparent;
}
.fab:active { transform: scale(0.92); background: var(--border); }
.fab.fab-accent  { border-color: rgba(56,189,248,0.4);  color: var(--accent); }
.fab.fab-purple  { border-color: rgba(192,132,252,0.4); color: var(--purple); }
.fab.fab-success { border-color: rgba(34,197,94,0.4);   color: var(--success); }

/* =========================================================
   BOTTOM NAVIGATION
========================================================= */
#bottom-nav {
    position: absolute; bottom: 0; left: 0; right: 0; z-index: 200;
    height: var(--nav-h); background: var(--surface); border-top: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-around;
    backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
}
.nav-item {
    flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 3px; cursor: pointer; padding: 6px 4px; border-radius: 10px;
    transition: background 0.2s; -webkit-tap-highlight-color: transparent;
}
.nav-item:active { background: var(--border2); }
.nav-item .nav-icon { font-size: 20px; line-height: 1; transition: transform 0.2s; }
.nav-item .nav-lbl  { font-size: 9px; letter-spacing: 0.5px; text-transform: uppercase; color: var(--text3); font-weight: 600; font-family: var(--font-main); }
.nav-item.active .nav-icon { transform: scale(1.15); }
.nav-item.active .nav-lbl  { color: var(--accent); }

/* =========================================================
   BOTTOM SHEET
========================================================= */
.sheet-backdrop {
    position: fixed; inset: 0; z-index: 300;
    background: rgba(0,0,0,0.5); opacity: 0; pointer-events: none;
    transition: opacity 0.3s; backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
}
.sheet-backdrop.open { opacity: 1; pointer-events: auto; }

.bottom-sheet {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 400;
    background: var(--surface); border-top: 1px solid var(--border);
    border-radius: 20px 20px 0 0;
    transform: translateY(100%); transition: transform 0.35s cubic-bezier(0.32,0.72,0,1);
    max-height: 88vh; display: flex; flex-direction: column;
    padding-bottom: var(--safe-bottom);
}
.bottom-sheet.open { transform: translateY(0); }

.sheet-handle { flex-shrink: 0; display: flex; justify-content: center; padding: 10px 0 4px; }
.sheet-handle-bar { width: 40px; height: 4px; border-radius: 2px; background: var(--text3); }

.sheet-header {
    flex-shrink: 0; display: flex; align-items: center;
    justify-content: space-between; padding: 8px 18px 14px;
    border-bottom: 1px solid var(--border2);
}
.sheet-title { font-size: 15px; font-weight: 700; letter-spacing: 1.5px; color: var(--accent); text-transform: uppercase; font-family: var(--font-main); }
.sheet-close { width: 28px; height: 28px; border-radius: 50%; background: var(--surface2); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 14px; color: var(--text2); cursor: pointer; }

.sheet-body { flex: 1; overflow-y: auto; -webkit-overflow-scrolling: touch; padding: 14px 18px; }

/* =========================================================
   CONTROLS SHEET
========================================================= */
.ctrl-section { margin-bottom: 18px; }
.ctrl-section-title { font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--text3); margin-bottom: 10px; font-family: var(--font-main); }
.toggle-row { display: flex; align-items: center; justify-content: space-between; padding: 11px 0; border-bottom: 1px solid var(--border2); }
.toggle-row:last-child { border-bottom: none; }
.toggle-label { font-size: 14px; font-weight: 600; color: var(--text); font-family: var(--font-main); }

.ios-toggle { position: relative; width: 44px; height: 26px; flex-shrink: 0; }
.ios-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
.ios-track { position: absolute; inset: 0; border-radius: 13px; background: var(--text3); transition: background 0.25s; cursor: pointer; }
.ios-track::after { content: ''; position: absolute; top: 3px; left: 3px; width: 20px; height: 20px; border-radius: 50%; background: #fff; transition: transform 0.25s; box-shadow: 0 1px 4px rgba(0,0,0,0.3); }
.ios-toggle input:checked + .ios-track { background: var(--accent); }
.ios-toggle input:checked + .ios-track::after { transform: translateX(18px); }

.ctrl-select { width: 100%; padding: 11px 14px; border-radius: 10px; border: 1px solid var(--border); background: var(--surface2); color: var(--text); font-size: 13px; font-family: var(--font-main); font-weight: 600; margin-bottom: 10px; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; }

.radius-row { display: flex; align-items: center; gap: 10px; padding: 6px 0; }
.radius-row label { font-size: 12px; color: var(--text2); font-weight: 600; white-space: nowrap; font-family: var(--font-main); }
.radius-row input[type=range] { flex: 1; accent-color: var(--orange); height: 4px; }
.radius-val { font-size: 13px; font-weight: 700; color: var(--orange); min-width: 48px; text-align: right; font-family: var(--font-mono); }

.action-btn { width: 100%; padding: 13px; border-radius: 12px; border: 1px solid var(--border); background: var(--surface2); color: var(--text); font-size: 13px; font-weight: 700; font-family: var(--font-main); letter-spacing: 0.5px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: background 0.2s, transform 0.15s; margin-bottom: 8px; -webkit-tap-highlight-color: transparent; }
.action-btn:active { transform: scale(0.97); }
.action-btn.btn-accent  { background: rgba(56,189,248,0.12);  border-color: rgba(56,189,248,0.35);  color: var(--accent); }
.action-btn.btn-purple  { background: rgba(192,132,252,0.12); border-color: rgba(192,132,252,0.35); color: var(--purple); }
.action-btn.btn-green   { background: rgba(34,197,94,0.15);   border-color: rgba(34,197,94,0.4);    color: var(--success); }

/* =========================================================
   DISPATCH SHEET
========================================================= */
.dispatch-card { background: var(--surface2); border-left: 3px solid var(--accent); border-radius: 10px; padding: 12px; margin-bottom: 10px; font-family: var(--font-main); animation: slideUp 0.3s ease; position: relative; }
@keyframes slideUp { from{opacity:0;transform:translateY(12px);} to{opacity:1;transform:translateY(0);} }
.dispatch-card.status-assigned { border-left-color: var(--success); }
.dispatch-card.status-enroute  { border-left-color: var(--enroute); }
.dispatch-card.status-onsite   { border-left-color: var(--onsite); }
.dispatch-card.status-complete { border-left-color: var(--text3); opacity: 0.65; }
.dc-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 6px; }
.dc-unit { font-size: 15px; font-weight: 700; color: var(--text); }
.dc-risk { font-size: 13px; font-weight: 700; color: var(--danger); font-family: var(--font-mono); }
.dc-barangay { font-size: 12px; color: var(--text2); margin-bottom: 8px; }
.status-badge { display: inline-block; padding: 3px 8px; border-radius: 20px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; font-family: var(--font-mono); }
.status-assigned .status-badge { background: rgba(34,197,94,0.18); color: var(--success); }
.status-enroute  .status-badge { background: rgba(245,158,11,0.18); color: var(--enroute); }
.status-onsite   .status-badge { background: rgba(5,150,105,0.18);  color: var(--onsite); }
.status-complete .status-badge { background: rgba(100,116,139,0.2); color: var(--text3); }
.dc-buttons { display: grid; grid-template-columns: repeat(4,1fr); gap: 5px; margin-top: 8px; }
.dc-btn { padding: 7px 4px; border-radius: 7px; border: 1px solid transparent; font-size: 10px; font-weight: 700; font-family: var(--font-main); cursor: pointer; text-align: center; transition: transform 0.15s; -webkit-tap-highlight-color: transparent; }
.dc-btn:active { transform: scale(0.93); }
.dc-btn.assigned { background: rgba(34,197,94,0.15);   border-color: rgba(34,197,94,0.4);   color: var(--success); }
.dc-btn.enroute  { background: rgba(245,158,11,0.15);  border-color: rgba(245,158,11,0.4);  color: var(--enroute); }
.dc-btn.onsite   { background: rgba(5,150,105,0.15);   border-color: rgba(5,150,105,0.4);   color: var(--onsite); }
.dc-btn.complete { background: rgba(100,116,139,0.12); border-color: rgba(100,116,139,0.3); color: var(--text3); }

/* =========================================================
   LEGEND SHEET
========================================================= */
.legend-group-title { font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--text3); margin: 14px 0 8px; font-family: var(--font-main); }
.legend-row { display: flex; align-items: center; gap: 10px; padding: 7px 0; border-bottom: 1px solid var(--border2); font-size: 13px; font-weight: 600; color: var(--text); font-family: var(--font-main); }
.legend-row:last-child { border-bottom: none; }
.legend-dot    { width: 13px; height: 13px; border-radius: 50%; flex-shrink: 0; }
.legend-circle { width: 18px; height: 18px; border-radius: 50%; border: 2.5px solid; flex-shrink: 0; }

/* =========================================================
   TOAST
========================================================= */
#toast {
    position: fixed; top: calc(var(--safe-top) + var(--header-h) + 10px); left: 50%;
    transform: translateX(-50%) translateY(-20px); z-index: 9999;
    background: rgba(22,38,66,0.96); border: 1px solid var(--border);
    border-radius: 20px; padding: 8px 18px; font-size: 12px; font-weight: 600;
    font-family: var(--font-mono); color: var(--text); white-space: nowrap;
    opacity: 0; transition: opacity 0.25s, transform 0.25s; pointer-events: none;
    backdrop-filter: blur(16px);
}
#toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* =========================================================
   SATELLITE TOGGLE STATE
========================================================= */
#fabSatellite.satellite-on { border-color: rgba(251,191,36,0.5); color: var(--warning); }

/* =========================================================
   SCROLLBAR
========================================================= */
.sheet-body::-webkit-scrollbar { width: 3px; }
.sheet-body::-webkit-scrollbar-track { background: transparent; }
.sheet-body::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }

/* =========================================================
   DIVIDER / EMPTY STATE
========================================================= */
.divider { height: 1px; background: var(--border2); margin: 14px 0; }
.empty-state { text-align: center; padding: 30px 20px; color: var(--text3); font-size: 13px; font-family: var(--font-main); font-weight: 600; }
.empty-state .empty-icon { font-size: 30px; margin-bottom: 10px; }

/* =========================================================
   ★ LOCATION DETAIL SHEET  (NEW)
========================================================= */

/* Sheet-level header badge */
.loc-risk-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 700; letter-spacing: 0.5px;
    text-transform: uppercase; font-family: var(--font-mono);
}
.loc-risk-high   { background: rgba(239,68,68,0.18);  color: #fca5a5; border: 1px solid rgba(239,68,68,0.4); }
.loc-risk-medium { background: rgba(251,191,36,0.18); color: #fde68a; border: 1px solid rgba(251,191,36,0.4); }
.loc-risk-low    { background: rgba(34,197,94,0.18);  color: #86efac; border: 1px solid rgba(34,197,94,0.4); }

/* Image sections */
.loc-img-section { margin-bottom: 14px; }
.loc-img-label {
    font-size: 9px; font-weight: 700; letter-spacing: 1.5px;
    text-transform: uppercase; color: var(--text3);
    font-family: var(--font-main); margin-bottom: 6px;
    display: flex; align-items: center; gap: 6px;
}
.loc-img-label span { color: var(--accent); }

/* Street View image */
.loc-sv-wrap {
    position: relative; border-radius: 12px; overflow: hidden;
    border: 1px solid var(--border); background: var(--surface2);
    height: 180px;
}
.loc-sv-img {
    width: 100%; height: 100%; object-fit: cover; display: block;
    transition: opacity 0.4s;
}
.loc-sv-overlay {
    position: absolute; inset: 0; display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: 6px;
    background: var(--surface2); font-family: var(--font-main);
}
.loc-sv-overlay.hidden { display: none; }
.loc-sv-spinner {
    width: 28px; height: 28px; border-radius: 50%;
    border: 3px solid rgba(56,189,248,0.2);
    border-top-color: var(--accent);
    animation: spin 0.9s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.loc-sv-text { font-size: 11px; color: var(--text3); font-weight: 600; }

/* Street View badge */
.sv-badge {
    position: absolute; bottom: 8px; left: 10px;
    background: rgba(10,15,30,0.85); backdrop-filter: blur(8px);
    border: 1px solid var(--border); border-radius: 8px;
    padding: 3px 9px; font-size: 9px; font-weight: 700;
    letter-spacing: 1px; color: var(--accent); font-family: var(--font-mono);
    text-transform: uppercase;
}

/* Static map with pin */
.loc-map-wrap {
    border-radius: 12px; overflow: hidden;
    border: 1px solid var(--border); height: 150px; position: relative;
}
.loc-map-img { width: 100%; height: 100%; object-fit: cover; display: block; }

/* Pin indicator overlay */
.loc-pin-badge {
    position: absolute; top: 8px; left: 10px;
    background: rgba(10,15,30,0.85); backdrop-filter: blur(8px);
    border: 1px solid rgba(239,68,68,0.4); border-radius: 8px;
    padding: 3px 9px; font-size: 9px; font-weight: 700;
    letter-spacing: 1px; color: #fca5a5; font-family: var(--font-mono);
    text-transform: uppercase;
}

/* Coordinates row */
.loc-coords {
    display: flex; align-items: center; gap: 8px;
    background: rgba(56,189,248,0.06); border: 1px solid var(--border2);
    border-radius: 10px; padding: 10px 12px; margin-bottom: 14px;
    font-family: var(--font-mono); font-size: 12px; color: var(--accent);
    letter-spacing: 0.3px;
}
.loc-coords .coord-icon { font-size: 16px; flex-shrink: 0; }
.loc-coords .coord-text { flex: 1; }
.loc-coords .coord-text strong { display: block; color: var(--text); font-size: 13px; }

/* Incidents list inside location sheet */
.loc-section-title {
    font-size: 10px; font-weight: 700; letter-spacing: 1.5px;
    text-transform: uppercase; color: var(--text3);
    font-family: var(--font-main); margin-bottom: 10px;
    margin-top: 14px;
}

.loc-incident-card {
    border-radius: 10px; padding: 11px 12px; margin-bottom: 8px;
    background: var(--surface2); border-left: 3px solid;
    animation: slideUp 0.25s ease;
}
.loc-incident-card.level-high   { border-left-color: var(--danger); }
.loc-incident-card.level-medium { border-left-color: var(--warning); }
.loc-incident-card.level-low    { border-left-color: var(--success); }

.lic-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; }
.lic-type { font-size: 14px; font-weight: 700; color: var(--text); font-family: var(--font-main); }
.lic-risk { font-size: 13px; font-weight: 700; font-family: var(--font-mono); }
.lic-risk.high   { color: var(--danger); }
.lic-risk.medium { color: var(--warning); }
.lic-risk.low    { color: var(--success); }

.lic-meta { font-size: 11px; color: var(--text2); line-height: 1.7; font-family: var(--font-main); }

/* AI explanation */
.lic-ai {
    margin-top: 6px; font-size: 10px; color: var(--text3);
    background: rgba(192,132,252,0.06); border: 1px solid rgba(192,132,252,0.15);
    border-radius: 7px; padding: 6px 8px; line-height: 1.5;
    font-family: var(--font-main);
}
.lic-ai strong { color: var(--purple); }

/* Overlap warning */
.loc-overlap-warn {
    display: flex; align-items: center; gap: 8px;
    background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.3);
    border-radius: 10px; padding: 10px 12px; margin-bottom: 14px;
    font-size: 12px; font-weight: 600; color: #fca5a5;
    font-family: var(--font-main);
}

/* View on map button */
.loc-view-btn {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; padding: 14px; border-radius: 12px; border: none; cursor: pointer;
    background: linear-gradient(135deg, rgba(56,189,248,0.25), rgba(14,165,233,0.15));
    border: 1px solid rgba(56,189,248,0.4); color: var(--accent);
    font-size: 14px; font-weight: 700; letter-spacing: 0.5px;
    font-family: var(--font-main); transition: transform 0.15s, background 0.2s;
    margin-top: 4px; -webkit-tap-highlight-color: transparent;
}
.loc-view-btn:active { transform: scale(0.97); }
</style>
</head>
<body>

<div id="app">

    <!-- ====================================================
         HEADER
    ==================================================== -->
    <div id="header">
        <div class="header-brand">
            <div class="header-logo">CS</div>
            <div class="header-title">Crime<span>Sync</span></div>
        </div>
        <div class="header-right">
            <div class="live-pill"><div class="live-dot"></div>Live</div>
            <div class="header-menu-btn" onclick="openSheet('legend-sheet')">≡</div>
        </div>
    </div>

    <!-- ====================================================
         MAIN AREA (MAP + OVERLAYS)
    ==================================================== -->
    <div id="main">

        <!-- Map -->
        <div id="map"></div>

        <!-- Stats bar -->
        <div id="stats-bar">
            <div class="stat-chip accent"><div class="val" id="total">0</div><div class="lbl">Incidents</div></div>
            <div class="stat-chip danger"><div class="val" id="high">0</div><div class="lbl">High Risk</div></div>
            <div class="stat-chip purple"><div class="val" id="pred">0</div><div class="lbl">AI Proj.</div></div>
            <div class="stat-chip orange"><div class="val" id="forecastCount">0</div><div class="lbl">Forecast</div></div>
            <div class="stat-chip orange"><div class="val" id="bufferCount">0</div><div class="lbl">Buffers</div></div>
            <div class="stat-chip success"><div class="val" id="activePatrols">0</div><div class="lbl">Patrols</div></div>
            <div class="stat-chip danger"><div class="val" id="overlapCount">0</div><div class="lbl">Overlap</div></div>
        </div>

        <!-- FAB Buttons -->
        <div id="fab-group">
            <div class="fab fab-accent" id="fabSatellite" onclick="toggleSatellite()" title="Satellite">🛰</div>
            <div class="fab fab-purple" onclick="suggestPatrolRoute()" title="Patrol Route">🗺</div>
            <div class="fab" onclick="resetMapView()" title="Reset View">⌖</div>
        </div>

        <!-- Bottom Navigation -->
        <div id="bottom-nav">
            <div class="nav-item active" id="nav-map" onclick="navTab('map')">
                <div class="nav-icon">🗺</div><div class="nav-lbl">Map</div>
            </div>
            <div class="nav-item" id="nav-layers" onclick="openSheet('ctrl-sheet')">
                <div class="nav-icon">⚙</div><div class="nav-lbl">Layers</div>
            </div>
            <div class="nav-item" id="nav-patrol" onclick="openSheet('dispatch-sheet'); autoAssignPatrol()">
                <div class="nav-icon">🚓</div><div class="nav-lbl">Patrols</div>
            </div>
            <div class="nav-item" id="nav-legend" onclick="openSheet('legend-sheet')">
                <div class="nav-icon">📋</div><div class="nav-lbl">Legend</div>
            </div>
        </div>

    </div><!-- #main -->
</div><!-- #app -->

<!-- ======================================================
     BACKDROP
====================================================== -->
<div class="sheet-backdrop" id="backdrop" onclick="closeAllSheets()"></div>

<!-- ======================================================
     CONTROLS SHEET
====================================================== -->
<div class="bottom-sheet" id="ctrl-sheet">
    <div class="sheet-handle"><div class="sheet-handle-bar"></div></div>
    <div class="sheet-header">
        <div class="sheet-title">Intelligence Layers</div>
        <div class="sheet-close" onclick="closeAllSheets()">✕</div>
    </div>
    <div class="sheet-body">
        <div class="ctrl-section">
            <div class="ctrl-section-title">Map Layers</div>
            <div class="toggle-row"><div class="toggle-label">Density Heatmap</div><label class="ios-toggle"><input type="checkbox" id="toggleHeat" checked onchange="drawMap()"><span class="ios-track"></span></label></div>
            <div class="toggle-row"><div class="toggle-label">Predictive Modeling</div><label class="ios-toggle"><input type="checkbox" id="togglePred" checked onchange="drawMap()"><span class="ios-track"></span></label></div>
            <div class="toggle-row"><div class="toggle-label">Buffer / Danger Radius</div><label class="ios-toggle"><input type="checkbox" id="toggleBuffer" checked onchange="drawMap()"><span class="ios-track"></span></label></div>
            <div class="toggle-row"><div class="toggle-label">Overlap Highlighting</div><label class="ios-toggle"><input type="checkbox" id="toggleOverlap" checked onchange="drawMap()"><span class="ios-track"></span></label></div>
        </div>
        <div class="ctrl-section">
            <div class="ctrl-section-title">Buffer Radius</div>
            <div class="radius-row">
                <label>Radius</label>
                <input type="range" id="bufferRadius" min="100" max="1000" step="50" value="200" oninput="onRadiusChange(this.value)">
                <div class="radius-val" id="radiusLabel">200m</div>
            </div>
        </div>
        <div class="ctrl-section">
            <div class="ctrl-section-title">Filters</div>
            <select class="ctrl-select" id="filterType"><option value="all">All Incident Types</option></select>
            <select class="ctrl-select" id="barangayFilter"><option value="all">All Barangays</option></select>
        </div>
        <div class="ctrl-section">
            <div class="ctrl-section-title">Actions</div>
            <button class="action-btn btn-accent" onclick="toggleSatellite(); showToast('Satellite view toggled')">🛰 Satellite Imagery</button>
            <button class="action-btn btn-purple" onclick="suggestPatrolRoute(); closeAllSheets()">🗺 Optimize Patrol Route</button>
            <button class="action-btn btn-green" onclick="openSheet('dispatch-sheet'); autoAssignPatrol()">🚓 Generate Dispatch Plan</button>
        </div>
    </div>
</div>

<!-- ======================================================
     DISPATCH SHEET
====================================================== -->
<div class="bottom-sheet" id="dispatch-sheet">
    <div class="sheet-handle"><div class="sheet-handle-bar"></div></div>
    <div class="sheet-header">
        <div class="sheet-title">🚓 Real-Time Patrols</div>
        <div class="sheet-close" onclick="closeAllSheets()">✕</div>
    </div>
    <div class="sheet-body">
        <div id="dispatchBoard">
            <div class="empty-state"><div class="empty-icon">🚓</div>Awaiting patrol assignments...</div>
        </div>
    </div>
</div>

<!-- ======================================================
     LEGEND SHEET
====================================================== -->
<div class="bottom-sheet" id="legend-sheet">
    <div class="sheet-handle"><div class="sheet-handle-bar"></div></div>
    <div class="sheet-header">
        <div class="sheet-title">Map Legend</div>
        <div class="sheet-close" onclick="closeAllSheets()">✕</div>
    </div>
    <div class="sheet-body">
        <div class="legend-group-title">Incident Markers</div>
        <div class="legend-row"><div class="legend-dot" style="background:#ef4444;"></div><span>High Risk Incident</span></div>
        <div class="legend-row"><div class="legend-dot" style="background:#fbbf24;"></div><span>Medium Risk Incident</span></div>
        <div class="legend-row"><div class="legend-dot" style="background:#22c55e;"></div><span>Low Risk Incident</span></div>
        <div class="legend-row"><div class="legend-dot" style="background:#c084fc;clip-path:polygon(50% 0%,100% 100%,0% 100%);border-radius:2px;"></div><span>AI Predicted Hotspot</span></div>
        <div class="legend-group-title">Patrol Status</div>
        <div class="legend-row"><div class="legend-dot" style="background:#22c55e;"></div><span>Assigned</span></div>
        <div class="legend-row"><div class="legend-dot" style="background:#f59e0b;"></div><span>En Route</span></div>
        <div class="legend-row"><div class="legend-dot" style="background:#059669;"></div><span>On-Site</span></div>
        <div class="legend-group-title">Danger Radius Zones</div>
        <div class="legend-row"><div class="legend-circle" style="border-color:#ef4444;background:rgba(239,68,68,0.08);"></div><span>High Risk Buffer</span></div>
        <div class="legend-row"><div class="legend-circle" style="border-color:#fbbf24;background:rgba(251,191,36,0.08);"></div><span>Medium Risk Buffer</span></div>
        <div class="legend-row"><div class="legend-circle" style="border-color:#22c55e;background:rgba(34,197,94,0.08);"></div><span>Low Risk Buffer</span></div>
        <div class="legend-row"><div class="legend-circle" style="border-color:#fb923c;background:rgba(251,146,60,0.06);"></div><span>Overlap Zone</span></div>
    </div>
</div>

<!-- ======================================================
     ★ LOCATION DETAIL SHEET  (NEW)
====================================================== -->
<div class="bottom-sheet" id="location-detail-sheet">
    <div class="sheet-handle"><div class="sheet-handle-bar"></div></div>

    <!-- header with dynamic barangay name + risk badge -->
    <div class="sheet-header">
        <div style="display:flex;align-items:center;gap:10px;overflow:hidden;">
            <div class="sheet-title" id="loc-sheet-title" style="letter-spacing:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
            <div class="loc-risk-badge" id="loc-risk-badge"></div>
        </div>
        <div class="sheet-close" onclick="closeAllSheets()">✕</div>
    </div>

    <div class="sheet-body">

        <!-- Coordinates -->
        <div class="loc-coords" id="loc-coords">
            <div class="coord-icon">📍</div>
            <div class="coord-text">
                <strong id="loc-coord-val">—</strong>
                Exact GPS Coordinates
            </div>
        </div>

        <!-- Street View Image -->
        <div class="loc-img-section">
            <div class="loc-img-label">📸 Street View &mdash; <span>Exact Location Photo</span></div>
            <div class="loc-sv-wrap">
                <!-- Spinner shown while image loads -->
                <div class="loc-sv-overlay" id="loc-sv-overlay">
                    <div class="loc-sv-spinner"></div>
                    <div class="loc-sv-text">Loading street view…</div>
                </div>
                <img id="loc-sv-img" class="loc-sv-img" src="" alt="Street View" style="opacity:0;"
                     onload="onSvLoad()" onerror="onSvError()">
                <div class="sv-badge">📸 Street View</div>
            </div>
        </div>

        <!-- Static Map with Pinpoint -->
        <div class="loc-img-section">
            <div class="loc-img-label">🗺 Pinpoint Map &mdash; <span>Exact Incident Location</span></div>
            <div class="loc-map-wrap">
                <img id="loc-map-img" class="loc-map-img" src="" alt="Location Map">
                <div class="loc-pin-badge">📍 Pinpoint</div>
            </div>
        </div>

        <!-- Overlap warning (shown only when relevant) -->
        <div class="loc-overlap-warn" id="loc-overlap-warn" style="display:none;">
            <span style="font-size:18px;">⚡</span>
            <span id="loc-overlap-text"></span>
        </div>

        <!-- Incidents list -->
        <div class="loc-section-title" id="loc-inc-label">Incidents in this area</div>
        <div id="loc-incidents-list"></div>

        <!-- View on Map button -->
        <button class="loc-view-btn" id="loc-view-btn" onclick="focusLocationOnMap()">
            🔍 View & Zoom on Map
        </button>

    </div>
</div>

<!-- ======================================================
     TOAST
====================================================== -->
<div id="toast"></div>

<!-- ======================================================
     JAVASCRIPT
====================================================== -->
<script>

/* =========================================================
   PHP DATA
========================================================= */
const points            = <?= json_encode($points) ?>;
const predicted         = <?= json_encode($predicted) ?>;
const barangayPoints    = <?= json_encode($barangayPoints) ?>;
const forecast          = <?= json_encode($forecast) ?>;
const patrolAssignments = <?= json_encode($patrolAssignments) ?>;
const GM_KEY            = <?= json_encode($gmKey) ?>;

/* =========================================================
   STATE
========================================================= */
let map, heatmap = null, patrolLine = null, infoWindow = null, mapClickListener = null;
let markers = [], predMarkers = [], bufferCircles = [], overlapCircles = [], patrolMarkers = [];
let bufferRadius = 200;
let satelliteOn  = false;

/* ★ NEW state for location detail */
let selectedPin   = null;   // special large marker for the clicked point
let focusLat      = 0;
let focusLng      = 0;
let focusZoom     = 18;

const defaultCenter = { lat: 10.2745, lng: 122.8480 };
const defaultZoom   = 13;

/* =========================================================
   SHEET SYSTEM
========================================================= */
function openSheet(id) {
    closeAllSheets(false);
    document.getElementById(id).classList.add('open');
    document.getElementById('backdrop').classList.add('open');
}
function closeAllSheets(closeBackdrop = true) {
    document.querySelectorAll('.bottom-sheet').forEach(s => s.classList.remove('open'));
    if (closeBackdrop) document.getElementById('backdrop').classList.remove('open');
}

/* =========================================================
   TOAST
========================================================= */
function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2500);
}

/* =========================================================
   NAV TABS
========================================================= */
function navTab(tab) {
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    document.getElementById('nav-' + tab).classList.add('active');
}

/* =========================================================
   RADIUS
========================================================= */
function onRadiusChange(val) {
    bufferRadius = parseInt(val);
    document.getElementById('radiusLabel').innerText = val + 'm';
    drawMap();
}

/* =========================================================
   STATUS COLOR
========================================================= */
function getStatusColor(status) {
    return status === 'enroute'  ? '#f59e0b'
         : status === 'onsite'   ? '#059669'
         : status === 'complete' ? '#6b7280'
         :                        '#22c55e';
}

/* =========================================================
   LEVEL HELPERS
========================================================= */
function levelColor(level) {
    return level === 'high'   ? '#ef4444'
         : level === 'medium' ? '#fbbf24'
         :                      '#22c55e';
}
function levelFill(level) {
    return level === 'high'   ? 'rgba(239,68,68,0.10)'
         : level === 'medium' ? 'rgba(251,191,36,0.10)'
         :                      'rgba(34,197,94,0.10)';
}

/* =========================================================
   CLEAR MARKERS
========================================================= */
function clearAllMarkers() {
    markers.forEach(m => m.setMap(null));
    predMarkers.forEach(m => m.setMap(null));
    patrolMarkers.forEach(m => m.setMap(null));
    markers = []; predMarkers = []; patrolMarkers = [];
}
function clearBufferCircles() {
    bufferCircles.forEach(c => c.setMap(null));
    overlapCircles.forEach(c => c.setMap(null));
    bufferCircles = []; overlapCircles = [];
}

/* =========================================================
   HAVERSINE
========================================================= */
function haversineMetres(lat1, lng1, lat2, lng2) {
    const R  = 6371000;
    const φ1 = lat1 * Math.PI / 180;
    const φ2 = lat2 * Math.PI / 180;
    const Δφ = (lat2 - lat1) * Math.PI / 180;
    const Δλ = (lng2 - lng1) * Math.PI / 180;
    const a  = Math.sin(Δφ/2)**2 + Math.cos(φ1)*Math.cos(φ2)*Math.sin(Δλ/2)**2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

/* =========================================================
   DRAW PATROL MARKERS
========================================================= */
function drawPatrolMarkers() {
    patrolMarkers.forEach(m => m.setMap(null));
    patrolMarkers = [];
    patrolAssignments.forEach(assignment => {
        let lat = parseFloat(assignment.lat);
        let lng = parseFloat(assignment.lng);
        if (!assignment.lat || !assignment.lng) {
            const bgy = barangayPoints.find(b => b.barangay === assignment.target_barangay);
            if (!bgy) return;
            lat = bgy.lat; lng = bgy.lng;
        }
        const sc = getStatusColor(assignment.status);
        const marker = new google.maps.Marker({
            position: { lat, lng }, map,
            icon: { path: assignment.status === 'onsite' ? google.maps.SymbolPath.CIRCLE : 'M 0,-4 4,0 0,4 -4,0 z', fillColor: sc, fillOpacity: 1, strokeColor: '#ffffff', strokeWeight: 2, scale: assignment.status === 'onsite' ? 11 : 9 },
            title: `${assignment.unit_name} — ${assignment.status.toUpperCase()}`, optimized: false
        });
        marker.addListener('click', () => {
            infoWindow.setContent(`
                <div style="width:260px;padding:14px;font-family:Arial;">
                    <div style="font-size:16px;font-weight:bold;color:${sc};margin-bottom:8px;">${assignment.unit_name}</div>
                    <div style="font-size:12px;color:#475569;line-height:1.8;">
                        <b>Status:</b> <span style="color:${sc};font-weight:bold;">${assignment.status.toUpperCase()}</span><br>
                        <b>Target:</b> ${assignment.target_barangay}<br>
                        <b>Assigned:</b> ${new Date(assignment.assigned_at).toLocaleString()}<br>
                        <b>Updated:</b> ${new Date(assignment.updated_at).toLocaleString()}<br>
                        <b>GPS:</b> ${lat.toFixed(5)}, ${lng.toFixed(5)}
                    </div>
                </div>
            `);
            infoWindow.open(map, marker);
        });
        patrolMarkers.push(marker);
    });
}

/* =========================================================
   DRAW BUFFER ZONES
========================================================= */
function drawBufferZones(filtered) {
    clearBufferCircles();
    const showBuffer  = document.getElementById('toggleBuffer').checked;
    const showOverlap = document.getElementById('toggleOverlap').checked;
    if (!showBuffer) return;
    const zones = [];
    filtered.forEach(p => { zones.push({ lat: parseFloat(p.lat), lng: parseFloat(p.lng), level: p.level, type: p.type, label: p.barangay }); });
    if (document.getElementById('togglePred').checked) {
        predicted.forEach(p => { if (p.lat && p.lng) zones.push({ lat: parseFloat(p.lat), lng: parseFloat(p.lng), level: 'predicted', type: 'AI Prediction', label: 'AI Hotspot' }); });
    }
    zones.forEach(z => {
        const strokeColor = z.level === 'predicted' ? '#c084fc' : levelColor(z.level);
        const fillColor   = z.level === 'predicted' ? 'rgba(192,132,252,0.08)' : levelFill(z.level);
        const circle = new google.maps.Circle({ map, center: { lat: z.lat, lng: z.lng }, radius: bufferRadius, strokeColor, strokeOpacity: 0.9, strokeWeight: z.level === 'high' ? 2.5 : 1.8, fillColor, fillOpacity: 1, clickable: true, zIndex: 1 });
        circle.addListener('click', e => {
            const riskLabel = z.level === 'predicted' ? 'AI Predicted' : z.level.toUpperCase();
            const borderCol = z.level === 'predicted' ? '#c084fc' : levelColor(z.level);
            infoWindow.setPosition(e.latLng);
            infoWindow.setContent(`<div style="width:240px;padding:12px;font-family:Arial;"><div style="font-size:15px;font-weight:bold;color:${borderCol};margin-bottom:8px;">⚠ Danger Radius Zone</div><div style="font-size:12px;color:#475569;line-height:1.8;"><b>Type:</b> ${z.type}<br><b>Barangay:</b> ${z.label}<br><b>Risk Level:</b> ${riskLabel}<br><b>Radius:</b> ${bufferRadius} m<br><b>Centre:</b> ${z.lat.toFixed(5)}, ${z.lng.toFixed(5)}</div></div>`);
            infoWindow.open(map);
        });
        bufferCircles.push(circle);
    });
    if (showOverlap) {
        const overlapSet = new Set();
        for (let i = 0; i < zones.length; i++) {
            for (let j = i + 1; j < zones.length; j++) {
                if (haversineMetres(zones[i].lat, zones[i].lng, zones[j].lat, zones[j].lng) < bufferRadius * 2) { overlapSet.add(i); overlapSet.add(j); }
            }
        }
        overlapSet.forEach(idx => {
            const z  = zones[idx];
            const oc = new google.maps.Circle({ map, center: { lat: z.lat, lng: z.lng }, radius: bufferRadius * 1.12, strokeColor: '#fb923c', strokeOpacity: 0.9, strokeWeight: 2, fillColor: 'rgba(251,146,60,0.06)', fillOpacity: 1, clickable: true, zIndex: 2 });
            oc.addListener('click', e => {
                infoWindow.setPosition(e.latLng);
                infoWindow.setContent(`<div style="width:240px;padding:12px;font-family:Arial;"><div style="font-size:15px;font-weight:bold;color:#fb923c;margin-bottom:8px;">⚡ Overlapping Risk Zone</div><div style="font-size:12px;color:#475569;line-height:1.8;"><b>Location:</b> ${z.label}<br><b>Status:</b> Multiple danger zones converge here.<br><b>Radius:</b> ${bufferRadius} m<br><b>Action:</b> Deploy additional patrol units immediately.</div></div>`);
                infoWindow.open(map);
            });
            overlapCircles.push(oc);
        });
        document.getElementById('overlapCount').innerText = overlapSet.size;
    } else {
        document.getElementById('overlapCount').innerText = 0;
    }
    document.getElementById('bufferCount').innerText = bufferCircles.length;
}

/* =========================================================
   ★ STREET VIEW & MAP LOAD CALLBACKS
========================================================= */
function onSvLoad() {
    document.getElementById('loc-sv-img').style.opacity = '1';
    document.getElementById('loc-sv-overlay').classList.add('hidden');
}
function onSvError() {
    const overlay = document.getElementById('loc-sv-overlay');
    overlay.classList.remove('hidden');
    overlay.innerHTML = `
        <div style="font-size:28px;margin-bottom:6px;">📷</div>
        <div class="loc-sv-text" style="color:#64748b;">No street view available</div>
        <div class="loc-sv-text" style="font-size:10px;margin-top:3px;">for this exact coordinate</div>
    `;
}

/* =========================================================
   ★ OPEN LOCATION DETAIL SHEET
========================================================= */
function openLocationDetail(p, incidents, lat, lng) {
    // Save focus state
    focusLat  = lat;
    focusLng  = lng;
    focusZoom = 18;

    // Title & risk badge
    document.getElementById('loc-sheet-title').textContent = p.barangay || 'Unknown Location';
    const badge = document.getElementById('loc-risk-badge');
    const riskClass = p.level === 'high' ? 'loc-risk-high' : p.level === 'medium' ? 'loc-risk-medium' : 'loc-risk-low';
    badge.className = 'loc-risk-badge ' + riskClass;
    badge.textContent = (p.level || 'low').toUpperCase() + ' RISK';

    // Coordinates
    document.getElementById('loc-coord-val').textContent = lat.toFixed(6) + ', ' + lng.toFixed(6);

    // ── Street View Static Image ──────────────────────────
    const svOverlay = document.getElementById('loc-sv-overlay');
    svOverlay.className = 'loc-sv-overlay';   // reset (remove 'hidden')
    svOverlay.innerHTML = '<div class="loc-sv-spinner"></div><div class="loc-sv-text">Loading street view…</div>';
    const svImg = document.getElementById('loc-sv-img');
    svImg.style.opacity = '0';
    svImg.src = '';   // reset first

    // Delay assignment so spinner is visible
    setTimeout(() => {
        svImg.src = 'https://maps.googleapis.com/maps/api/streetview'
            + '?size=400x200'
            + '&location=' + lat + ',' + lng
            + '&fov=90&pitch=0&source=outdoor'
            + '&key=' + GM_KEY;
    }, 80);

    // ── Static Map with Red Pinpoint ──────────────────────
    // Dark-style static map matching the app theme
    const mapStyle = [
        'style=feature:all|element:geometry|color:0x1e293b',
        'style=feature:road|element:geometry|color:0x334155',
        'style=feature:water|element:geometry|color:0x0f172a',
        'style=feature:poi|visibility:off',
        'style=feature:transit|visibility:off',
        'style=feature:all|element:labels.text.fill|color:0x94a3b8',
        'style=feature:all|element:labels.text.stroke|color:0x1e293b'
    ].join('&');

    const markerColor = p.level === 'high' ? 'red' : p.level === 'medium' ? 'yellow' : 'green';

    document.getElementById('loc-map-img').src =
        'https://maps.googleapis.com/maps/api/staticmap'
        + '?center=' + lat + ',' + lng
        + '&zoom=17'
        + '&size=400x200'
        + '&scale=2'
        + '&maptype=roadmap'
        + '&markers=color:' + markerColor + '%7Clabel:!%7C' + lat + ',' + lng
        + '&' + mapStyle
        + '&key=' + GM_KEY;

    // ── Overlap Warning ───────────────────────────────────
    let overlapCount = 0;
    for (let i = 0; i < incidents.length; i++) {
        for (let j = i + 1; j < incidents.length; j++) {
            if (haversineMetres(incidents[i].lat, incidents[i].lng, incidents[j].lat, incidents[j].lng) < bufferRadius * 2) overlapCount++;
        }
    }
    const overlapEl = document.getElementById('loc-overlap-warn');
    if (overlapCount > 0) {
        overlapEl.style.display = 'flex';
        document.getElementById('loc-overlap-text').textContent =
            overlapCount + ' overlapping buffer zone(s) detected — elevated priority area';
    } else {
        overlapEl.style.display = 'none';
    }

    // ── Incidents List ────────────────────────────────────
    document.getElementById('loc-inc-label').textContent =
        incidents.length + ' Incident' + (incidents.length !== 1 ? 's' : '') + ' in ' + p.barangay;

    const listEl = document.getElementById('loc-incidents-list');
    if (incidents.length === 0) {
        listEl.innerHTML = '<div class="empty-state"><div class="empty-icon">✅</div>No incidents found</div>';
    } else {
        listEl.innerHTML = incidents.map((inc, idx) => {
            const riskVal = parseFloat(inc.risk).toFixed(1);
            const dateStr = inc.last_seen
                ? new Date(inc.last_seen).toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' })
                : 'No date';
            return `
            <div class="loc-incident-card level-${inc.level}">
                <div class="lic-top">
                    <div class="lic-type">#${idx+1} — ${inc.type}</div>
                    <div class="lic-risk ${inc.level}">${riskVal}%</div>
                </div>
                <div class="lic-meta">
                    <b>Level:</b> ${inc.level.toUpperCase()} &nbsp;|&nbsp;
                    <b>Date:</b> ${dateStr}<br>
                    <b>Coords:</b> ${parseFloat(inc.lat).toFixed(5)}, ${parseFloat(inc.lng).toFixed(5)}
                </div>
                ${inc.ai_explanation ? `<div class="lic-ai"><strong>🤖 AI:</strong> ${inc.ai_explanation}</div>` : ''}
            </div>`;
        }).join('');
    }

    // Open the sheet
    openSheet('location-detail-sheet');
}

/* =========================================================
   ★ FOCUS LOCATION ON MAP (button inside detail sheet)
========================================================= */
function focusLocationOnMap() {
    closeAllSheets();
    map.panTo({ lat: focusLat, lng: focusLng });
    map.setZoom(focusZoom);

    // Place / animate the selected pin
    if (selectedPin) {
        selectedPin.setMap(null);
        selectedPin = null;
    }
    selectedPin = new google.maps.Marker({
        position: { lat: focusLat, lng: focusLng },
        map,
        animation: google.maps.Animation.BOUNCE,
        icon: {
            path: 'M 0,-16 C -6,-16 -10,-10 -10,-6 C -10,0 0,12 0,12 C 0,12 10,0 10,-6 C 10,-10 6,-16 0,-16 Z M 0,-10 C -2,-10 -4,-8 -4,-6 C -4,-4 -2,-2 0,-2 C 2,-2 4,-4 4,-6 C 4,-8 2,-10 0,-10 Z',
            fillColor:   '#38bdf8',
            fillOpacity: 1,
            strokeColor: '#ffffff',
            strokeWeight: 2,
            scale:        1.4,
            anchor:       new google.maps.Point(0, 12)
        },
        zIndex: 9999
    });
    setTimeout(() => { if (selectedPin) selectedPin.setAnimation(null); }, 2200);
    showToast('📍 Pinpointing exact location…');
}

/* =========================================================
   DRAW MAP
========================================================= */
function drawMap() {
    clearAllMarkers();
    if (heatmap) { heatmap.setMap(null); heatmap = null; }
    if (mapClickListener) { google.maps.event.removeListener(mapClickListener); mapClickListener = null; }

    const typeF    = document.getElementById('filterType').value;
    const bgyF     = document.getElementById('barangayFilter').value;
    const filtered = points.filter(p => {
        if (typeF !== 'all' && p.type     !== typeF) return false;
        if (bgyF  !== 'all' && p.barangay !== bgyF)  return false;
        return true;
    });

    drawBufferZones(filtered);
    drawPatrolMarkers();

    if (document.getElementById('toggleHeat').checked && filtered.length > 0) {
        heatmap = new google.maps.visualization.HeatmapLayer({
            data:    filtered.map(p => ({ location: new google.maps.LatLng(parseFloat(p.lat), parseFloat(p.lng)), weight: parseFloat(p.risk) })),
            map, radius: 45, opacity: 0.7
        });
    }

    filtered.forEach((p, index) => {
        const offset = 0.00004 * index;
        const lat    = parseFloat(p.lat) + offset;
        const lng    = parseFloat(p.lng) + offset;
        const color  = levelColor(p.level);

        const marker = new google.maps.Marker({
            position:  { lat, lng },
            map,
            animation: google.maps.Animation.DROP,
            icon: {
                path:        google.maps.SymbolPath.CIRCLE,
                fillColor:   color,
                fillOpacity: 0.95,
                strokeColor: '#ffffff',
                strokeWeight: 2,
                scale:       9
            }
        });

        markers.push(marker);

        marker.addListener('click', () => {
            // ── original behaviour ───────────────────────
            zoomToBarangay(p.barangay);
            map.panTo({ lat, lng });
            map.setZoom(18);
            marker.setAnimation(google.maps.Animation.BOUNCE);
            setTimeout(() => marker.setAnimation(null), 1500);

            const incidents = points.filter(x => x.barangay === p.barangay);

            // ★ Open the new location detail sheet
            openLocationDetail(p, incidents, lat, lng);
        });
    });

    if (document.getElementById('togglePred').checked && predicted.length > 0) {
        predicted.forEach(p => {
            if (!p.lat || !p.lng) return;
            const pm = new google.maps.Marker({
                position: { lat: parseFloat(p.lat), lng: parseFloat(p.lng) }, map,
                icon: { path: 'M 0,-2 2,2 -2,2 z', fillColor: '#c084fc', fillOpacity: 1, strokeColor: '#ffffff', strokeWeight: 1, scale: 8 },
                title: 'AI Predicted Hotspot'
            });
            pm.addListener('click', () => {
                infoWindow.setContent(`
                    <div style="width:240px;padding:10px;font-family:Arial;">
                        <div style="font-size:15px;font-weight:bold;color:#7c3aed;margin-bottom:8px;">AI Predicted Hotspot</div>
                        <div style="font-size:12px;color:#475569;">
                            Latitude: ${parseFloat(p.lat).toFixed(5)}<br>
                            Longitude: ${parseFloat(p.lng).toFixed(5)}<br>
                            Buffer Radius: ${bufferRadius} m
                        </div>
                    </div>
                `);
                infoWindow.open(map, pm);
            });
            predMarkers.push(pm);
        });
    }

    document.getElementById('forecastCount').innerText = forecast.length;
    updateStats();
}

/* =========================================================
   UPDATE STATS
========================================================= */
function updateStats() {
    document.getElementById('total').innerText = points.length;
    document.getElementById('high').innerText  = points.filter(p => p.level === 'high').length;
    document.getElementById('pred').innerText  = predicted.length;
    const activePatrols = patrolAssignments.filter(p => !['complete','cancelled'].includes(p.status)).length;
    document.getElementById('activePatrols').innerText = activePatrols;
}

/* =========================================================
   SATELLITE
========================================================= */
function toggleSatellite() {
    satelliteOn = !satelliteOn;
    map.setMapTypeId(satelliteOn ? 'satellite' : 'roadmap');
    document.getElementById('fabSatellite').classList.toggle('satellite-on', satelliteOn);
    showToast(satelliteOn ? '🛰 Satellite ON' : '🗺 Road Map ON');
}

/* =========================================================
   ZOOM TO BARANGAY
========================================================= */
function zoomToBarangay(barangayName) {
    const related = points.filter(x => x.barangay === barangayName);
    if (!related.length) return;
    const bounds = new google.maps.LatLngBounds();
    related.forEach(loc => bounds.extend(new google.maps.LatLng(parseFloat(loc.lat), parseFloat(loc.lng))));
    map.fitBounds(bounds);
    google.maps.event.addListenerOnce(map, 'bounds_changed', () => { if (map.getZoom() > 18) map.setZoom(18); });
}

/* =========================================================
   RESET MAP VIEW
========================================================= */
function resetMapView() {
    map.panTo(defaultCenter);
    map.setZoom(defaultZoom);
    if (patrolLine) patrolLine.setMap(null);
    if (infoWindow) infoWindow.close();
    if (selectedPin) { selectedPin.setMap(null); selectedPin = null; }
    showToast('↺ Map reset to default view');
}

/* =========================================================
   POPULATE FILTERS
========================================================= */
function populateFilters() {
    const types     = [...new Set(points.map(p => (p.type     || '').trim()).filter(Boolean))];
    const barangays = [...new Set(points.map(p => (p.barangay || '').trim()).filter(Boolean))].sort();
    const typeSelect = document.getElementById('filterType');
    const bgySelect  = document.getElementById('barangayFilter');
    while (typeSelect.options.length > 1) typeSelect.remove(1);
    while (bgySelect.options.length  > 1) bgySelect.remove(1);
    types.forEach(t => { typeSelect.add(new Option(t, t)); });
    barangays.forEach(b => { bgySelect.add(new Option(b, b)); });
    typeSelect.addEventListener('change', drawMap);
    bgySelect.addEventListener('change', () => {
        drawMap();
        const sel = document.getElementById('barangayFilter').value;
        if (sel !== 'all') zoomToBarangay(sel);
    });
}

/* =========================================================
   PATROL ROUTE
========================================================= */
function suggestPatrolRoute() {
    const highRisk = barangayPoints.filter(b => b.level === 'high').map(p => ({ lat: p.lat, lng: p.lng }));
    if (highRisk.length < 2) { showToast('⚠ Need more high-risk data points'); return; }
    if (patrolLine) patrolLine.setMap(null);
    patrolLine = new google.maps.Polyline({
        path: highRisk, map, strokeWeight: 4, strokeColor: '#38bdf8', strokeOpacity: 0.9,
        icons: [{ icon: { path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW }, offset: '100%', repeat: '50px' }]
    });
    const bounds = new google.maps.LatLngBounds();
    highRisk.forEach(p => bounds.extend(p));
    map.fitBounds(bounds);
    showToast('🗺 Patrol route optimized');
}

/* =========================================================
   AUTO DISPATCH
========================================================= */
function autoAssignPatrol() {
    const highRisk = barangayPoints.filter(b => b.level === 'high').sort((a, b) => b.risk - a.risk);
    let html = '';
    highRisk.slice(0, 6).forEach((b, i) => {
        const existing = patrolAssignments.find(p => p.target_barangay === b.barangay && !['complete','cancelled'].includes(p.status));
        const unitName = existing ? existing.unit_name : ['RAPID-01','SIGMA-09','DELTA-K9','ECHO-22','BRAVO-15','TANGO-03'][i % 6];
        const status   = existing ? existing.status : 'assigned';
        html += createDispatchCard(unitName, b.barangay, b.risk, status, i);
    });
    patrolAssignments.slice(0, 4).forEach((patrol, i) => {
        if (!highRisk.find(b => b.barangay === patrol.target_barangay)) {
            html += createDispatchCard(patrol.unit_name, patrol.target_barangay, parseFloat(patrol.risk_level || 0), patrol.status, i + 6);
        }
    });
    document.getElementById('dispatchBoard').innerHTML = html ||
        '<div class="empty-state"><div class="empty-icon">✅</div>All sectors stable. Patrols completed.</div>';
}

/* =========================================================
   CREATE DISPATCH CARD
========================================================= */
function createDispatchCard(unitName, barangay, risk, status, id) {
    const stText = status.charAt(0).toUpperCase() + status.slice(1);
    return `
        <div class="dispatch-card status-${status}" data-patrol-id="${id}">
            <div class="dc-top"><div class="dc-unit">${unitName}</div><div class="dc-risk">${risk.toFixed(0)}%</div></div>
            <div class="dc-barangay">${barangay}</div>
            <span class="status-badge">${stText}</span>
            <div class="dc-buttons">
                <div class="dc-btn assigned" onclick="updatePatrolStatus(${id},'assigned')">Assign</div>
                <div class="dc-btn enroute"  onclick="updatePatrolStatus(${id},'enroute')">En Route</div>
                <div class="dc-btn onsite"   onclick="updatePatrolStatus(${id},'onsite')">On-Site</div>
                <div class="dc-btn complete" onclick="updatePatrolStatus(${id},'complete')">Done</div>
            </div>
        </div>
    `;
}

/* =========================================================
   UPDATE PATROL STATUS
========================================================= */
function updatePatrolStatus(patrolId, newStatus) {
    const card = document.querySelector(`[data-patrol-id="${patrolId}"]`);
    if (card) {
        card.className = `dispatch-card status-${newStatus}`;
        card.querySelector('.status-badge').textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
        updateStats();
        drawMap();
    }
    showToast(`🚓 Unit updated → ${newStatus.toUpperCase()}`);
}

/* =========================================================
   MAP STYLE
========================================================= */
const mapStyle = [
    { elementType: 'geometry',           stylers: [{ color: '#1e293b' }] },
    { elementType: 'labels.text.fill',   stylers: [{ color: '#94a3b8' }] },
    { elementType: 'labels.text.stroke', stylers: [{ color: '#1e293b' }] },
    { featureType: 'road',  elementType: 'geometry', stylers: [{ color: '#334155' }] },
    { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#0f172a' }] },
    { featureType: 'poi',   stylers: [{ visibility: 'off' }] },
    { featureType: 'transit', stylers: [{ visibility: 'off' }] }
];

/* =========================================================
   INIT MAP
========================================================= */
function initMap() {
    map = new google.maps.Map(document.getElementById('map'), {
        center:            defaultCenter,
        zoom:              defaultZoom,
        minZoom:           11,
        maxZoom:           20,
        styles:            mapStyle,
        disableDefaultUI:  true,
        zoomControl:       false,
        mapTypeControl:    false,
        streetViewControl: false,
        fullscreenControl: false,
        gestureHandling:   'greedy'
    });

    infoWindow = new google.maps.InfoWindow();
    google.maps.event.addListener(infoWindow, 'closeclick', resetMapView);
    map.addListener('rightclick', resetMapView);

    populateFilters();
    updateStats();
    drawMap();
    autoAssignPatrol();
}

function gm_authFailure() { showToast('⚠ Google Maps API error — check key & billing'); }
window.initMap = initMap;

</script>

<!-- ======================================================
     GOOGLE MAPS API
====================================================== -->
<script
    async
    defer
    src="https://maps.googleapis.com/maps/api/js?key=AIzaSyASORp7VucTts2Rvp99-m6MmoME1mluaw8&libraries=visualization&callback=initMap">
</script>

</body>
</html>
