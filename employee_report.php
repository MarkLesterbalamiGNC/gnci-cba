<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/auth_role.php';
require_employee();

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

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');

require_once 'includes/attendance_summary.php';
require_once 'includes/schedule_functions.php';
$statusFilter = att_status_from_get();

// Schedule (shifts) assigned to this employee
$schedule = !empty($emp['schedule_id']) ? get_schedule((int)$emp['schedule_id']) : null;
$shifts   = $schedule ? get_schedule_shifts((int)$schedule['id']) : [];

$stmt = $pdo->prepare(
    "SELECT * FROM attendance
     WHERE employee_id = ? AND log_date BETWEEN ? AND ?"
);
$stmt->execute([$empId, $from, $to]);
$logRows = $stmt->fetchAll();

$attended = att_attended_days($logRows);
$absentDays = att_absent_days(att_date_list($from, $to), $attended, $emp['created_at'], att_excused_dates($pdo, $empId));
[$stats, $allRows] = att_build($logRows, $absentDays);
$rows = att_filter($allRows, $statusFilter);

// Hours worked per row + total, and attendance rate for the chosen range
$fmtHours = function (int $min): string {
    return intdiv($min, 60) . 'h ' . str_pad((string)($min % 60), 2, '0', STR_PAD_LEFT) . 'm';
};
$totalMinutes = 0;
foreach ($rows as $k => $r) {
    $rows[$k]['minutes'] = null;
    if (empty($r['absent']) && $r['time_in'] && $r['time_out']) {
        $m = (int)round((strtotime($r['time_out']) - strtotime($r['time_in'])) / 60);
        if ($m > 0) { $rows[$k]['minutes'] = $m; $totalMinutes += $m; }
    }
}
$attendedDays = $stats['present'] + $stats['late'];
$attendanceRate = $stats['total'] > 0 ? round($attendedDays / $stats['total'] * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Report | BioTrack</title>
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
            <div class="page-head">
                <h1>My Report</h1>
                <div class="breadcrumb"><a href="dashboard.php">My Profile</a> &rsaquo; My Report</div>
            </div>

            <div class="panel">
                <div class="print-only">
                    <h2 style="margin:0 0 4px;"><?= htmlspecialchars($emp['name']) ?> &ndash; Attendance Report</h2>
                    <p style="margin:0 0 16px;color:#555;">
                        <?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?><?= $statusFilter ? ' &middot; ' . htmlspecialchars($statusFilter) . ' only' : '' ?>
                    </p>
                </div>

                <form method="get" class="report-filter no-print">
                    <div>
                        <label for="from">From</label>
                        <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>">
                    </div>
                    <div>
                        <label for="to">To</label>
                        <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>">
                    </div>
                    <div>
                        <label for="status">Show</label>
                        <?= att_status_select($statusFilter) ?>
                    </div>
                    <style>
                    .rf-actions.rf-four { display:grid; grid-template-columns:repeat(4,auto); gap:10px; }
                    @media (max-width:600px) { .rf-actions.rf-four { grid-template-columns:1fr 1fr; } }
                    </style>
                    <div class="rf-actions rf-inline rf-four">
                        <button class="btn btn-primary" type="submit">Filter</button>
                        <button type="button" class="btn btn-dark" onclick="window.print()">&#128424; Print</button>
                        <button type="submit" class="btn btn-dark" formaction="employee_report_download.php" name="format" value="pdf">&#11015; PDF</button>
                        <button type="submit" class="btn btn-dark" formaction="employee_report_download.php" name="format" value="word">&#11015; Word</button>
                    </div>
                </form>
                <p class="report-hint no-print">
                    Print prints exactly what is shown below. PDF and Word save it as a file. After changing the dates, click Filter first for Print; the PDF and Word buttons use the dates shown above right away.
                </p>
            </div>

            <div class="panel">
                <div class="panel-head"><h2>Employee Details</h2></div>
                <style>
                .rep-details { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px 24px; padding:0 20px 20px; }
                .rep-details .lbl { font-size:11.5px; text-transform:uppercase; letter-spacing:.4px; color:#8a94a6; margin-bottom:3px; }
                .rep-details .val { font-size:14px; font-weight:600; color:#2c3444; word-break:break-word; }
                .rep-shift { font-weight:400; font-size:13px; white-space:nowrap; }
                </style>
                <div class="rep-details">
                    <div><div class="lbl">Full Name</div><div class="val"><?= htmlspecialchars($emp['name']) ?></div></div>
                    <div><div class="lbl">Employee ID</div><div class="val"><?= htmlspecialchars($emp['employee_id']) ?></div></div>
                    <div><div class="lbl">Department</div><div class="val"><?= htmlspecialchars($emp['department']) ?></div></div>
                    <div><div class="lbl">Course</div><div class="val"><?= $emp['course'] ? htmlspecialchars($emp['course']) : '&mdash;' ?></div></div>
                    <div><div class="lbl">Position</div><div class="val"><?= htmlspecialchars($emp['position']) ?></div></div>
                    <div><div class="lbl">Email</div><div class="val"><?= htmlspecialchars($emp['email']) ?></div></div>
                    <div><div class="lbl">Mobile No.</div><div class="val"><?= htmlspecialchars($emp['mobile_no']) ?></div></div>
                    <div>
                        <div class="lbl">Work Schedule</div>
                        <div class="val">
                            <?php if ($schedule): ?>
                                <?= htmlspecialchars($schedule['name']) ?>
                                <?php foreach ($shifts as $sh): ?>
                                    <div class="rep-shift"><?= htmlspecialchars($sh['label']) ?>:
                                        <?= htmlspecialchars(format_time_am_pm($sh['time_start'])) ?> &ndash; <?= htmlspecialchars(format_time_am_pm($sh['time_end'])) ?>
                                        <span style="color:#8a94a6;">(<?= (int)$sh['grace_minutes'] ?> min grace)</span></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span style="font-weight:400;color:#8a94a6;">No schedule assigned</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?= att_stat_cards($stats, 'no-print') ?>

            <div class="panel">
                <div class="panel-head">
                    <h2>Attendance Details</h2>
                    <div style="font-size:13px;color:#555;">
                        Attendance rate: <strong><?= (int)$attendanceRate ?>%</strong>
                        &nbsp;&middot;&nbsp; Total hours: <strong><?= htmlspecialchars($fmtHours($totalMinutes)) ?></strong>
                    </div>
                </div>
                <div style="overflow-x:auto;">
                <table class="data-table att-table cols4">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Shift</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Hours</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" style="text-align:center;color:#8a94a6;padding:24px;"><?= $statusFilter ? 'No ' . htmlspecialchars(strtolower($statusFilter)) . ' records in this range.' : 'No attendance records for this range.' ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $i => $r): ?>
                        <tr class="st-<?= htmlspecialchars(strtolower($r['status'])) ?><?= !empty($r['absent']) ? ' is-absent' : '' ?>">
                            <td class="c-num"><?= $i+1 ?></td>
                            <td class="c-date"><?= htmlspecialchars($r['log_date']) ?></td>
                            <td class="c-shift" data-label="Shift"><?= !empty($r['absent']) ? '&mdash;' : htmlspecialchars($r['shift_label'] ?: 'Shift ' . $r['shift_order']) ?></td>
                            <td class="c-in t-time" data-label="Time In"><?= $r['time_in'] ? htmlspecialchars(format_time_am_pm($r['time_in'])) : '&mdash;' ?></td>
                            <td class="c-out t-time" data-label="Time Out"><?= $r['time_out'] ? htmlspecialchars(format_time_am_pm($r['time_out'])) : '&mdash;' ?></td>
                            <td class="c-hrs" data-label="Hours"><?= $r['minutes'] ? htmlspecialchars($fmtHours($r['minutes'])) : '&mdash;' ?></td>
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

<?php include 'includes/footer.php'; ?>
