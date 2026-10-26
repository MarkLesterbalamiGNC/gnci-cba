<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_login();
require_once 'includes/auth_role.php';
require_once 'includes/icons.php';

$role = $_SESSION['role'] ?? 'admin';

// ============================================================
// EMPLOYEE VIEW: My Profile + full attendance history only
// ============================================================
if ($role === 'employee') {
    $empId = (int)($_SESSION['employee_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$empId]);
    $emp = $stmt->fetch();

    if (!$emp) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }

    require_once 'includes/attendance_summary.php';
    $statusFilter = att_status_from_get();

    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ?");
    $stmt->execute([$empId]);
    $logRows = $stmt->fetchAll();

    $attended = att_attended_days($logRows);
    $absentDays = att_absent_days(att_date_list(date('Y-m-d', strtotime($emp['created_at'])), date('Y-m-d')), $attended, $emp['created_at'], att_excused_dates($pdo, $empId));
    [$stats, $allRows] = att_build($logRows, $absentDays);
    $rows = att_filter($allRows, $statusFilter);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile | BioTrack</title>
<link rel="stylesheet" href="assets/css/base.css?v=<?= @filemtime(__DIR__ . '/assets/css/base.css') ?>">
<link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>">
<link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main">
        <?php include 'includes/topbar.php'; ?>

        <div class="content">
            <div class="page-head">
                <h1>My Profile</h1>
                <div class="breadcrumb">My Profile</div>
            </div>

            <div class="panel no-print">
                <div class="panel-head"><h2>Employee Information</h2></div>
                <div class="full" style="display:flex;align-items:center;gap:20px;padding:0 0 4px;">
                    <span class="photo-thumb is-icon" style="width:72px;height:72px;">
                        <?php if (!empty($emp['photo'])): ?>
                            <img src="uploads/photos/<?= htmlspecialchars($emp['photo']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                        <?php else: ?>
                            <?= bt_icon('user') ?>
                        <?php endif; ?>
                    </span>
                    <div>
                        <div class="emp-name"><?= htmlspecialchars($emp['name']) ?></div>
                        <div class="emp-meta">
                            ID <?= htmlspecialchars($emp['employee_id']) ?> &middot;
                            <?= htmlspecialchars($emp['department']) ?><?= $emp['course'] ? ' - ' . htmlspecialchars($emp['course']) : '' ?> &middot;
                            <?= htmlspecialchars($emp['position']) ?>
                        </div>
                        <div class="emp-note">
                            Need to update your course, position, mobile number, or password?
                            Use the <strong>Edit Profile</strong> option in the top right menu.
                        </div>
                    </div>
                </div>
            </div>

            <?= att_stat_cards($stats, 'no-print') ?>

            <?= att_donut($stats) ?>

            <div class="panel">
                <div class="print-only">
                    <h2 style="margin:0 0 4px;"><?= htmlspecialchars($emp['name']) ?> &ndash; Attendance Record</h2>
                    <p style="margin:0 0 16px;color:#555;">
                        ID <?= htmlspecialchars($emp['employee_id']) ?> &middot; <?= htmlspecialchars($emp['department']) ?>
                        &middot; Printed <?= date('M j, Y') ?><?= $statusFilter ? ' &middot; ' . htmlspecialchars($statusFilter) . ' only' : '' ?>
                    </p>
                </div>
                <div class="panel-head att-head no-print">
                    <button type="button" class="btn btn-dark att-print" onclick="window.print()">&#128424; Print Record</button>
                    <h2>My Attendance</h2>
                    <form method="get" class="table-toolbar" style="margin:0;">
                        <div>Show <?= att_status_select($statusFilter, 'this.form.submit()') ?></div>
                    </form>
                </div>
                <div style="overflow-x:auto;">
                <table class="data-table att-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Shift</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="6" style="text-align:center;color:#8a94a6;padding:24px;"><?= $statusFilter ? 'No ' . htmlspecialchars(strtolower($statusFilter)) . ' records.' : 'No attendance records yet.' ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $i => $r): ?>
                        <tr class="st-<?= htmlspecialchars(strtolower($r['status'])) ?><?= !empty($r['absent']) ? ' is-absent' : '' ?>">
                            <td class="c-num"><?= $i+1 ?></td>
                            <td class="c-date"><?= htmlspecialchars($r['log_date']) ?></td>
                            <td class="c-shift" data-label="Shift"><?= !empty($r['absent']) ? '&mdash;' : htmlspecialchars($r['shift_label'] ?: 'Shift ' . $r['shift_order']) ?></td>
                            <td class="c-in t-time" data-label="Time In"><?= $r['time_in'] ? htmlspecialchars(format_time_am_pm($r['time_in'])) : '&mdash;' ?></td>
                            <td class="c-out t-time" data-label="Time Out"><?= $r['time_out'] ? htmlspecialchars(format_time_am_pm($r['time_out'])) : '&mdash;' ?></td>
                            <td class="c-status">
                                <?php $cls = att_pill($r['status']); ?>
                                <span class="pill <?= $cls ?>"><?= htmlspecialchars($r['status']) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

<?php
    include 'includes/footer.php';
    exit;
}

// ============================================================
// ADMIN VIEW: original dashboard
// ============================================================
require_once 'includes/trash_functions.php';
purge_expired_deleted_employees();
$regEmployees = (int)$pdo->query("SELECT COUNT(*) c FROM employees WHERE deleted_at IS NULL")->fetch()['c'];
$enrolled     = (int)$pdo->query("SELECT COUNT(*) c FROM employees WHERE deleted_at IS NULL AND fingerprint_enrolled = 1")->fetch()['c'];
$bubbleRows   = $pdo->query("SELECT name, employee_id, position, photo, fingerprint_enrolled FROM employees WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll();
$bubbleAll = $bubbleEnr = [];
foreach ($bubbleRows as $b) {
    $item = [
        'name'  => $b['name'],
        'meta'  => trim($b['position'] . ' · ID ' . $b['employee_id']),
        'photo' => (!empty($b['photo']) && is_file(__DIR__ . '/uploads/photos/' . $b['photo'])) ? 'uploads/photos/' . rawurlencode($b['photo']) : '',
    ];
    $bubbleAll[] = $item;
    if ((int)$b['fingerprint_enrolled'] === 1) $bubbleEnr[] = $item;
}
require_once 'includes/attendance_summary.php';
$todayStats   = att_today_stats($pdo);
$todayAtt     = (int)$pdo->query("SELECT COUNT(DISTINCT a.employee_id) c FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE a.log_date = CURDATE() AND e.deleted_at IS NULL")->fetch()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | BioTrack</title>
<link rel="stylesheet" href="assets/css/base.css?v=<?= @filemtime(__DIR__ . '/assets/css/base.css') ?>">
<link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>">
<link rel="stylesheet" href="assets/css/bubbles.css?v=<?= @filemtime(__DIR__ . '/assets/css/bubbles.css') ?>">
<link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main">
        <?php include 'includes/topbar.php'; ?>

        <div class="content">
            <div class="page-head">
                <h1>Dashboard</h1>
                <div class="breadcrumb"><a href="dashboard.php">Home</a> &rsaquo; Dashboard</div>
            </div>

            <div class="stat-grid">
                <div class="stat-card blue">
                    <div class="stat-body">
                        <span class="stat-icon"><?= bt_icon('users') ?></span>
                        <div class="stat-number"><?= $regEmployees ?></div>
                        <div class="stat-label">No. of Registered Employee</div>
                    <div class="bubble-deck" data-bubbles='<?= htmlspecialchars(json_encode($bubbleAll, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS), ENT_QUOTES) ?>' data-empty="No registered employees yet" aria-label="Swipe to see profiles"></div>
                    </div>
                    <div class="stat-footer">
                        <a href="employee.php">More Info <?= bt_icon('arrow') ?></a>
                    </div>
                </div>

                <div class="stat-card green">
                    <div class="stat-body">
                        <span class="stat-icon"><?= bt_icon('usercheck') ?></span>
                        <div class="stat-number"><?= $enrolled ?></div>
                        <div class="stat-label">No. of Enrolled Employee</div>
                    <div class="bubble-deck" data-bubbles='<?= htmlspecialchars(json_encode($bubbleEnr, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS), ENT_QUOTES) ?>' data-empty="No enrolled employees yet" aria-label="Swipe to see profiles"></div>
                    </div>
                    <div class="stat-footer">
                        <a href="employee.php?filter=enrolled">More Info <?= bt_icon('arrow') ?></a>
                    </div>
                </div>

                <div class="stat-card orange">
                    <div class="stat-body">
                        <span class="stat-icon"><?= bt_icon('clock') ?></span>
                        <div class="stat-number"><?= $todayAtt ?></div>
                        <div class="stat-label">No. of Attendance Today</div>
                    </div>
                    <div class="stat-footer">
                        <a href="attendance_list.php">More Info <?= bt_icon('arrow') ?></a>
                    </div>
                </div>
            </div>

            <?= att_kpi_cards($todayStats) ?>

            <div class="adm-grid">
                <?= att_donut($todayStats, "Today's Attendance - " . date('F j, Y'), 'employee', 'Attendance rate') ?>
                <?= att_trend_chart(att_recent_days($pdo, 7)) ?>
            </div>

<script src="assets/js/bubbles.js?v=<?= @filemtime(__DIR__ . '/assets/js/bubbles.js') ?>"></script>
<?php include 'includes/footer.php'; ?>
