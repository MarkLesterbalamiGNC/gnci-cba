<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/auth_role.php';
require_once 'includes/attendance_summary.php';
require_once 'includes/daily_report.php';
require_admin();

$e = fn($v) => htmlspecialchars((string)$v);
dr_ensure_tables($pdo);

$isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
                    && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
$today = date('Y-m-d');

$date = $_POST['date'] ?? $_GET['date'] ?? $today;
if (!$isDate($date) || $date > $today) $date = $today;

// ---------- Actions (POST, then redirect so a refresh doesn't send twice) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_dean') {
        $name  = trim((string)($_POST['dean_name'] ?? ''));
        $email = trim((string)($_POST['dean_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Please enter a valid email address for the Dean.'];
        } else {
            dr_set_setting($pdo, 'dean_name', mb_substr($name !== '' ? $name : 'Dean', 0, 100));
            dr_set_setting($pdo, 'dean_email', mb_substr($email, 0, 150));
            $_SESSION['flash'] = ['type' => 'success', 'msg' => $email === '' ? 'Dean email removed. Reports will not be sent.' : 'Dean details saved.'];
        }
    } elseif ($action === 'save_alerts') {
        $iv = (int)($_POST['alert_interval'] ?? 30);
        $ev = (string)($_POST['alert_events'] ?? 'both');
        dr_set_setting($pdo, 'alert_enabled', !empty($_POST['alert_enabled']) ? '1' : '0');
        dr_set_setting($pdo, 'alert_interval', (string)(isset(PN_INTERVALS[$iv]) ? $iv : 30));
        dr_set_setting($pdo, 'alert_events', isset(PN_EVENTS[$ev]) ? $ev : 'both');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => !empty($_POST['alert_enabled'])
            ? 'Time in / time out alerts are on.' : 'Time in / time out alerts are off.'];
    } elseif ($action === 'alerts_send') {
        if (($_SESSION['pn_last_send'] ?? 0) > time() - 20) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Please wait a few seconds before sending again.'];
        } else {
            $_SESSION['pn_last_send'] = time();
            [$code, $msg] = pn_flush($pdo, true);
            $_SESSION['flash'] = ['type' => $code === 'sent' ? 'success' : ($code === 'error' ? 'error' : 'info'), 'msg' => $code === 'error' ? 'Could not send: ' . $msg : $msg];
        }
    } elseif ($action === 'send') {
        if (!bt_mail_configured()) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Email is not set up yet. Fill in config/mail.local.php first.'];
        } else {
            // Don't let the button be spammed: one send per 20 seconds
            if (($_SESSION['dr_last_send'] ?? 0) > time() - 20) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Please wait a few seconds before sending again.'];
            } else {
                $_SESSION['dr_last_send'] = time();
                [$ok, $msg] = dr_send($pdo, $date, 'admin:' . ($_SESSION['admin_username'] ?? ''));
                $_SESSION['flash'] = ['type' => $ok ? 'success' : 'error', 'msg' => $ok ? $msg : 'Could not send: ' . $msg];
            }
        }
    }
    header('Location: daily_report.php?date=' . urlencode($date));
    exit;
}

$rep   = dr_roster($pdo, $date);
$dean  = dr_dean($pdo);
$mailOk = bt_mail_configured();
$log   = $pdo->query("SELECT * FROM daily_report_log ORDER BY id DESC LIMIT 8")->fetchAll();
$isToday = $date === $today;
$alertCfg = pn_settings($pdo);
$alertSt  = pn_status($pdo);
$alertOn  = pn_active($pdo, $alertCfg);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daily Report | BioTrack</title>
<link rel="stylesheet" href="assets/css/base.css?v=<?= @filemtime(__DIR__ . '/assets/css/base.css') ?>">
<link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>">
<link rel="stylesheet" href="assets/css/reports.css?v=<?= @filemtime(__DIR__ . '/assets/css/reports.css') ?>">
<link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <?php include 'includes/topbar.php'; ?>
        <div class="content">
            <div class="page-head no-print">
                <h1>Daily Report for the Dean</h1>
                <div class="breadcrumb"><a href="dashboard.php">Home</a> &rsaquo; Daily Report</div>
            </div>

            <?php $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); ?>
            <?php if ($flash): ?>
                <div class="alert alert-<?= $e($flash['type']) ?> no-print"><?= $e($flash['msg']) ?></div>
            <?php endif; ?>

            <!-- ============ Controls (not printed) ============ -->
            <div class="panel no-print">
                <form method="get" class="report-filter">
                    <div>
                        <label for="date">Report date</label>
                        <input type="date" id="date" name="date" value="<?= $e($date) ?>" max="<?= $e($today) ?>">
                    </div>
                    <div class="rf-actions">
                        <button class="btn btn-primary" type="submit">Show</button>
                        <button type="button" class="btn btn-dark" onclick="window.print()">&#128424; Print Report</button>
                    </div>
                </form>

                <form method="post" style="margin-top:14px;">
                    <input type="hidden" name="action" value="send">
                    <input type="hidden" name="date" value="<?= $e($date) ?>">
                    <button class="btn btn-primary" type="submit" <?= ($mailOk && $dean['email'] !== '') ? '' : 'disabled' ?>
                            onclick="return confirm(<?= $e(json_encode('Email the report for ' . date('M j, Y', strtotime($date)) . ' to ' . $dean['email'] . ' now?')) ?>);">
                        &#9993; Send to Dean now
                    </button>
                    <span class="report-hint" style="margin-left:8px;">
                        <?= $dean['email'] !== '' ? 'To: ' . $e($dean['name']) . ' &lt;' . $e($dean['email']) . '&gt;' : 'Set the Dean\'s email below first.' ?>
                    </span>
                </form>
                <?php if ($isToday): ?>
                    <p class="report-hint">Today's report is only complete after everyone has timed out. The automatic email goes out at the end of the day.</p>
                <?php endif; ?>
            </div>

            <!-- ============ The report (this is what prints) ============ -->
            <div class="panel dr-sortable">
                <?= dr_render($rep, 'email') ?>
            </div>

            <!-- ============ Dean details ============ -->
            <div class="panel no-print">
                <div class="panel-head"><h2>Who receives the report?</h2></div>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="save_dean">
                    <input type="hidden" name="date" value="<?= $e($date) ?>">
                    <div class="field">
                        <label for="dean_name">Dean's name</label>
                        <input type="text" id="dean_name" name="dean_name" maxlength="100" value="<?= $e($dean['name']) ?>" placeholder="e.g. Dr. Maria Santos">
                    </div>
                    <div class="field">
                        <label for="dean_email">Dean's email address</label>
                        <input type="email" id="dean_email" name="dean_email" maxlength="150" value="<?= $e($dean['email']) ?>" placeholder="dean@example.com">
                    </div>
                    <div class="full">
                        <button class="btn btn-primary" type="submit">Save</button>
                    </div>
                </form>
            </div>

            <!-- ============ Time in / time out alerts ============ -->
            <div class="panel no-print">
                <div class="panel-head"><h2>Time in / time out alerts</h2></div>
                <p class="report-hint" style="margin-top:0;">
                    Get an email when employees time in or out &mdash; but <b>never one email per scan</b>. Scans are collected and sent
                    together in a single tidy email, at most once per interval below, and only when someone actually scanned.
                    The end-of-day report above is not affected.
                </p>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="save_alerts">
                    <input type="hidden" name="date" value="<?= $e($date) ?>">
                    <div class="field full">
                        <label style="display:flex;align-items:center;gap:8px;font-weight:600;">
                            <input type="checkbox" name="alert_enabled" value="1" <?= $alertCfg['enabled'] ? 'checked' : '' ?>>
                            Send me time in / time out alerts
                        </label>
                    </div>
                    <div class="field">
                        <label for="alert_interval">How often (at most)</label>
                        <select id="alert_interval" name="alert_interval">
                            <?php foreach (PN_INTERVALS as $min => $label): ?>
                                <option value="<?= $min ?>" <?= $alertCfg['interval'] === $min ? 'selected' : '' ?>><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="alert_events">What to include</label>
                        <select id="alert_events" name="alert_events">
                            <?php foreach (PN_EVENTS as $key => $label): ?>
                                <option value="<?= $e($key) ?>" <?= $alertCfg['events'] === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="full">
                        <button class="btn btn-primary" type="submit">Save alert settings</button>
                    </div>
                </form>

                <div style="margin-top:14px;display:flex;flex-wrap:wrap;gap:10px 18px;align-items:center;">
                    <?php if ($alertCfg['enabled'] && !$alertOn): ?>
                        <span class="pill pill-red">Not active</span>
                        <span class="report-hint">Alerts are on, but <?= !$mailOk ? 'email is not set up yet (config/mail.local.php)' : 'the Dean\'s email address is missing above' ?>.</span>
                    <?php elseif ($alertOn): ?>
                        <span class="pill pill-green">On</span>
                        <span class="report-hint">
                            <?php if ($alertSt['waiting'] > 0): ?>
                                <?= (int)$alertSt['waiting'] ?> scan<?= $alertSt['waiting'] === 1 ? '' : 's' ?> waiting &middot;
                                next email <?= $alertSt['due_at'] && $alertSt['due_at'] > time() ? 'around ' . $e(date('g:i A', $alertSt['due_at'])) : 'is due now' ?>
                            <?php else: ?>
                                Nothing waiting &mdash; an email is only sent after someone scans.
                            <?php endif; ?>
                            <?php if ($alertSt['last_sent']): ?> &middot; last sent <?= $e(date('M j, g:i A', $alertSt['last_sent'])) ?><?php endif; ?>
                        </span>
                    <?php else: ?>
                        <span class="pill">Off</span>
                    <?php endif; ?>
                    <?php if ($alertSt['error'] !== ''): ?>
                        <span class="report-hint" style="color:#a61b1b;">Last problem: <?= $e($alertSt['error']) ?> (will retry in a few minutes)</span>
                    <?php endif; ?>
                    <?php if ($alertOn && $alertSt['waiting'] > 0): ?>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="alerts_send">
                            <input type="hidden" name="date" value="<?= $e($date) ?>">
                            <button class="btn btn-outline-dark" type="submit">Send waiting scans now</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============ Recent sends ============ -->
            <div class="panel no-print">
                <div class="panel-head"><h2>Recent emails</h2></div>
                <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead><tr><th>Sent on</th><th>Report date</th><th>To</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php if (!$log): ?>
                        <tr><td colspan="4" style="text-align:center;color:#8a94a6;padding:20px;">Nothing has been emailed yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($log as $l): ?>
                        <tr>
                            <td><?= $e(date('M j, Y g:i A', strtotime($l['created_at']))) ?></td>
                            <td><?= $e(date('M j, Y', strtotime($l['report_date']))) ?></td>
                            <td><?= $e($l['sent_to']) ?></td>
                            <td><span class="pill <?= $l['ok'] ? 'pill-present' : 'pill-absent' ?>"><?= $l['ok'] ? 'Sent' : 'Failed' ?></span>
                                <?php if (!$l['ok']): ?><small style="color:#8a94a6;"> <?= $e($l['message']) ?></small><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

<?php include 'includes/footer.php'; ?>
