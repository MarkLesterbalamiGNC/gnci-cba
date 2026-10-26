<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/auth_role.php';
require_admin();

$usbMode = isset($_GET['usb']);          // ?usb=1 = old mode: scan through the browser + USB cable
$jsFile = __DIR__ . '/assets/js/' . ($usbMode ? 'attendance_scan.js' : 'attendance_live.js');
$ver = is_file($jsFile) ? filemtime($jsFile) : 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Scan Attendance | BioTrack</title>
<link rel="stylesheet" href="assets/css/base.css?v=<?= @filemtime(__DIR__ . '/assets/css/base.css') ?>">
<link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>">
<style>
[hidden] { display:none !important; }
.scan-wrap { max-width: 640px; margin: 0 auto; text-align: center; }
.scan-conn { display:inline-flex; align-items:center; gap:6px; font-size:12.5px; color:var(--text-muted); }
.scan-conn i { width:9px; height:9px; border-radius:50%; background:#c3c9d4; display:inline-block; }
.scan-conn.on i { background:var(--success); }
.scan-clock { font-size:44px; font-weight:700; margin:14px 0 2px; color:var(--text-dark); }
.scan-date  { color:var(--text-muted); font-size:14px; margin-bottom:10px; }
.scan-orb { width:140px; height:140px; margin:18px auto; border-radius:50%; background:#f4f4f6; border:4px solid #e2e2e7; box-shadow:0 10px 28px rgba(0,0,0,.10);
            display:flex; align-items:center; justify-content:center; font-size:64px; transition:all .2s; }
.scan-orb.ok  { background:#e9f7ee; border-color:var(--success); }
.scan-orb.late{ background:#fff3e0; border-color:#e08a00; }
.scan-orb.err { background:#fdecea; border-color:var(--danger); }
.scan-msg { font-size:20px; font-weight:700; margin:0 0 4px; min-height:28px; }
.scan-sub { font-size:14px; color:var(--text-muted); margin:0; min-height:20px; }
.scan-photo { width:84px; height:84px; border-radius:50%; object-fit:cover; margin:0 auto 10px; display:block; }
.scan-log { list-style:none; margin:26px 0 0; padding:0; text-align:left; font-size:20px; }
.scan-log li { display:flex; justify-content:space-between; align-items:center; gap:14px; padding:14px 8px; border-bottom:1px solid var(--border-light); font-weight:700; color:var(--text-dark); }
.scan-log li > span:last-child { font-size:20px; font-weight:700; color:var(--text-dark) !important; white-space:nowrap; }
@media (max-width: 520px) { .scan-log { font-size:17px; } .scan-log li > span:last-child { font-size:17px; } }
</style>
<link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <?php include 'includes/topbar.php'; ?>
        <div class="content">
            <div class="page-head">
                <h1>Scan Attendance</h1>
                <div class="breadcrumb"><a href="dashboard.php">Home</a> &rsaquo; Scan Attendance</div>
            </div>

            <?php if (!$usbMode): ?>
            <div class="panel">
                <div class="scan-wrap">
                    <span class="scan-conn" id="scan-conn"><i></i><span id="scan-conn-text">Connecting...</span></span>
                    <div class="scan-clock" id="scan-clock">--:--:--</div>
                    <div class="scan-date" id="scan-date"></div>

                    <div id="scan-photo-slot"></div>
                    <div class="scan-orb" id="scan-orb"><span id="scan-icon"><?= bt_icon('fingerprint') ?></span></div>
                    <p class="scan-msg" id="scan-msg">Waiting for fingerprint scans...</p>
                    <p class="scan-sub" id="scan-sub">Place your finger on the scanner device.</p>

                    <p class="scan-sub" style="margin-top:14px;font-size:12.5px;">
                        The scanner device records Time In / Time Out by itself - you do not need to keep this page open.
                        This screen just shows the latest scans.
                    </p>

                    <ul class="scan-log" id="scan-log"></ul>

                    <p class="scan-sub" style="margin-top:14px;font-size:12px;">
                        <a href="attendance_scan.php?usb=1">Use USB mode instead</a> (scan through this browser with the USB cable)
                    </p>
                </div>
            </div>
            <?php else: ?>
            <div class="panel">
                <div class="scan-wrap">
                    <span class="scan-conn" id="scan-conn"><i></i><span id="scan-conn-text">Scanner not connected</span></span>
                    <div class="scan-clock" id="scan-clock">--:--:--</div>
                    <div class="scan-date" id="scan-date"></div>

                    <div id="scan-photo-slot"></div>
                    <div class="scan-orb" id="scan-orb"><span id="scan-icon"><?= bt_icon('fingerprint') ?></span></div>
                    <p class="scan-msg" id="scan-msg">Connect the scanner to start.</p>
                    <p class="scan-sub" id="scan-sub">Use Google Chrome or Microsoft Edge. Close the Arduino Serial Monitor first.</p>

                    <div style="margin-top:18px;">
                        <button type="button" class="btn btn-primary" id="scan-connect">Connect Scanner</button>
                        <button type="button" class="btn" id="scan-stop" style="background:#e3e7ee;color:#2c3444;" hidden>Stop</button>
                    </div>
                    <div style="margin-top:12px;">
                        <button type="button" class="btn" id="scan-popout" style="background:#e3e7ee;color:#2c3444;font-size:12.5px;">&#10064; Open in a separate window</button>
                    </div>
                    <p class="scan-sub" style="margin-top:10px;font-size:12.5px;">
                        The scanner only works while this page is open. Open it in its own window and leave it there,
                        so you can keep using the rest of the system.
                    </p>

                    <ul class="scan-log" id="scan-log"></ul>
                </div>
            </div>

            <p class="scan-sub" style="text-align:center;font-size:12px;"><a href="attendance_scan.php">&larr; Back to live screen</a></p>
            <?php endif; ?>

<?php include 'includes/footer.php'; ?>
<script src="assets/js/<?= $usbMode ? 'attendance_scan.js' : 'attendance_live.js' ?>?v=<?= (int)$ver ?>"></script>
