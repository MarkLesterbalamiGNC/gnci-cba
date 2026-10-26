<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/auth_role.php';
require_once 'includes/attendance_summary.php';
require_admin();

$e = fn($v) => htmlspecialchars((string)$v);

// ---------- Inputs ----------
$mode  = $_GET['mode'] ?? 'range';                       // range | month | days
if (!in_array($mode, ['range', 'month', 'days'], true)) $mode = 'range';
$empPk = (int)($_GET['employee'] ?? 0);                  // 0 = all employees

$allowedCourses = ['BSIT', 'BSBA', 'BSA'];
$courseFilter = $_GET['course'] ?? '';
if (!in_array($courseFilter, $allowedCourses, true)) $courseFilter = '';
$statusFilter = att_status_from_get();   // '' = all, or Present / Late / Absent

// Sorting (whitelisted). Blank courses always go last.
$sortOptions = [
    'name'        => ['Name (A-Z)',   'e.name'],
    'course_asc'  => ['Course (A-Z)', "(e.course IS NULL OR e.course = ''), e.course ASC, e.name"],
    'course_desc' => ['Course (Z-A)', "(e.course IS NULL OR e.course = ''), e.course DESC, e.name"],
];
$sort = $_GET['sort'] ?? 'name';
if (!isset($sortOptions[$sort])) $sort = 'name';

$isDate  = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
                     && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
$isMonth = fn($m) => is_string($m) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m);

$from      = $_GET['from'] ?? date('Y-m-01');
$to        = $_GET['to'] ?? date('Y-m-d');
$monthFrom = $_GET['month_from'] ?? date('Y-m');
$monthTo   = $_GET['month_to'] ?? $monthFrom;
$days      = array_values(array_unique(array_filter((array)($_GET['days'] ?? []), $isDate)));
sort($days);

// ---------- Work out the period ----------
$dateSql = '';
$dateParams = [];
$periodLabel = '';

if ($mode === 'month') {
    if (!$isMonth($monthFrom)) $monthFrom = date('Y-m');
    if (!$isMonth($monthTo))   $monthTo = $monthFrom;
    if ($monthTo < $monthFrom) [$monthFrom, $monthTo] = [$monthTo, $monthFrom];

    $rangeStart = $monthFrom . '-01';
    $rangeEnd   = date('Y-m-t', strtotime($monthTo . '-01'));
    $dateSql    = 'a.log_date BETWEEN ? AND ?';
    $dateParams = [$rangeStart, $rangeEnd];
    $periodDates = att_date_list($rangeStart, $rangeEnd);
    $periodLabel = ($monthFrom === $monthTo)
        ? date('F Y', strtotime($rangeStart))
        : date('F Y', strtotime($rangeStart)) . ' to ' . date('F Y', strtotime($rangeEnd));
} elseif ($mode === 'days') {
    if (!$days) $days = [date('Y-m-d')];
    $dateSql    = 'a.log_date IN (' . implode(',', array_fill(0, count($days), '?')) . ')';
    $dateParams = $days;
    $periodDates = $days;
    $periodLabel = implode(', ', array_map(fn($d) => date('M j, Y', strtotime($d)), $days));
} else {
    if (!$isDate($from)) $from = date('Y-m-01');
    if (!$isDate($to))   $to = date('Y-m-d');
    if ($to < $from) [$from, $to] = [$to, $from];

    $dateSql    = 'a.log_date BETWEEN ? AND ?';
    $dateParams = [$from, $to];
    $periodDates = att_date_list($from, $to);
    $periodLabel = date('M j, Y', strtotime($from)) . ' to ' . date('M j, Y', strtotime($to));
}

// ---------- Summary (one row per employee) ----------
// Present = days on time, Late = days with a late punch, Absent = weekdays
// with no punch at all (see includes/attendance_summary.php).
$sql = "SELECT e.id, e.employee_id, e.name, e.department, e.course, e.created_at
        FROM employees e
        WHERE e.deleted_at IS NULL"
        . ($courseFilter !== '' ? " AND e.course = ?" : "")
        . ($empPk ? " AND e.id = ?" : "") . "
        ORDER BY {$sortOptions[$sort][1]}";

$params = [];
if ($courseFilter !== '') $params[] = $courseFilter;
if ($empPk)       $params[] = $empPk;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$baseRows = $stmt->fetchAll();

$logStmt = $pdo->prepare(
    "SELECT a.employee_id, a.log_date, MAX(a.status = 'Late') AS is_late
     FROM attendance a WHERE ($dateSql) AND a.time_in IS NOT NULL AND a.time_out IS NOT NULL GROUP BY a.employee_id, a.log_date"
);
$logStmt->execute($dateParams);
$attendedBy = [];
$lateBy = [];
foreach ($logStmt->fetchAll() as $l) {
    $attendedBy[$l['employee_id']][$l['log_date']] = true;
    if ((int)$l['is_late']) $lateBy[$l['employee_id']] = ($lateBy[$l['employee_id']] ?? 0) + 1;
}

$excusedBy = $periodDates ? att_excused_map($pdo, min($periodDates), max($periodDates)) : [];

$rows = [];
foreach ($baseRows as $r) {
    $att = $attendedBy[$r['id']] ?? [];
    $abs = att_absent_days($periodDates, $att, $r['created_at'], $excusedBy[$r['id']] ?? []);
    $r['days_late']     = $lateBy[$r['id']] ?? 0;
    $r['days_present']  = count($att) - $r['days_late'];
    $r['days_absent']   = count($abs);
    $r['absent_dates']  = $abs;
    // When a status is chosen, only list employees who have at least one such day
    if ($statusFilter === 'Present' && $r['days_present'] < 1) continue;
    if ($statusFilter === 'Late'    && $r['days_late']    < 1) continue;
    if ($statusFilter === 'Absent'  && $r['days_absent']  < 1) continue;
    $rows[] = $r;
}

// ---------- Dropdown data ----------
$employees   = $pdo->query("SELECT id, employee_id, name FROM employees WHERE deleted_at IS NULL ORDER BY name")->fetchAll();

$selectedEmp = null;
foreach ($employees as $emp) {
    if ((int)$emp['id'] === $empPk) { $selectedEmp = $emp; break; }
}
if (!$selectedEmp) $empPk = 0;

// ---------- Day-by-day log (only when a single employee is chosen) ----------
$details = [];
if ($empPk) {
    $dstmt = $pdo->prepare(
        "SELECT a.* FROM attendance a
         WHERE a.employee_id = ? AND $dateSql
         ORDER BY a.log_date ASC, a.shift_order ASC"
    );
    $dstmt->execute(array_merge([$empPk], $dateParams));
    $details = $dstmt->fetchAll();
}

// Add the missed days as "Absent" rows, then apply the Present / Late / Absent filter
if ($empPk) {
    $myAbsent = [];
    foreach ($rows as $r) {
        if ((int)$r['id'] === $empPk) { $myAbsent = $r['absent_dates']; break; }
    }
    $detailList = att_build($details, $myAbsent)[1];
    // Removed absences stay visible here (so the admin can restore them) but never count anywhere else
    foreach (array_keys($excusedBy[$empPk] ?? []) as $d) {
        if (in_array($d, $periodDates, true)) {
            $detailList[] = ['log_date' => $d, 'shift_order' => 1, 'shift_label' => '', 'time_in' => null,
                             'time_out' => null, 'status' => 'Excused', 'absent' => true, 'excused' => true];
        }
    }
    $details = att_filter($detailList, $statusFilter, 'ASC');
}

$backUrl = 'attendance_report.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');

$employeeLabel = $selectedEmp ? $selectedEmp['name'] . ' (ID ' . $selectedEmp['employee_id'] . ')' : 'All employees';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance Report | BioTrack</title>
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
                <h1>Attendance Report</h1>
                <div class="breadcrumb"><a href="dashboard.php">Home</a> &rsaquo; Attendance Report</div>
            </div>

            <?php $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); ?>
            <?php if ($flash): ?>
                <div class="alert alert-<?= $e($flash['type']) ?> no-print"><?= $e($flash['msg']) ?></div>
            <?php endif; ?>

            <!-- ============ Filters (not printed) ============ -->
            <div class="panel no-print">
                <form method="get" class="report-filter" id="reportForm">
                    <div>
                        <label for="employee">Employee</label>
                        <select name="employee" id="employee">
                            <option value="0">All employees</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= (int)$emp['id'] ?>" <?= $empPk === (int)$emp['id'] ? 'selected' : '' ?>>
                                    <?= $e($emp['name']) ?> (<?= $e($emp['employee_id']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="course">Course</label>
                        <select name="course" id="course">
                            <option value="">All courses</option>
                            <?php foreach ($allowedCourses as $c): ?>
                                <option value="<?= $c ?>" <?= $courseFilter === $c ? 'selected' : '' ?>><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="sort">Sort by</label>
                        <select name="sort" id="sort">
                            <?php foreach ($sortOptions as $key => $opt): ?>
                                <option value="<?= $key ?>" <?= $sort === $key ? 'selected' : '' ?>><?= $opt[0] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="status">Show</label>
                        <?= att_status_select($statusFilter) ?>
                    </div>

                    <div>
                        <label for="mode">Period</label>
                        <select name="mode" id="mode">
                            <option value="range" <?= $mode === 'range' ? 'selected' : '' ?>>Date range</option>
                            <option value="month" <?= $mode === 'month' ? 'selected' : '' ?>>By month</option>
                            <option value="days"  <?= $mode === 'days'  ? 'selected' : '' ?>>Specific days</option>
                        </select>
                    </div>

                    <!-- Date range -->
                    <div class="rf-group" data-mode="range">
                        <div>
                            <label for="from">From</label>
                            <input type="date" id="from" name="from" value="<?= $e($mode === 'range' ? $from : date('Y-m-01')) ?>">
                        </div>
                        <div>
                            <label for="to">To</label>
                            <input type="date" id="to" name="to" value="<?= $e($mode === 'range' ? $to : date('Y-m-d')) ?>">
                        </div>
                    </div>

                    <!-- Whole month(s) -->
                    <div class="rf-group" data-mode="month">
                        <div>
                            <label for="month_from">From month</label>
                            <input type="month" id="month_from" name="month_from" placeholder="YYYY-MM" value="<?= $e($isMonth($monthFrom) ? $monthFrom : date('Y-m')) ?>">
                        </div>
                        <div>
                            <label for="month_to">To month</label>
                            <input type="month" id="month_to" name="month_to" placeholder="YYYY-MM" value="<?= $e($isMonth($monthTo) ? $monthTo : date('Y-m')) ?>">
                        </div>
                    </div>

                    <!-- Specific days -->
                    <div class="rf-group" data-mode="days">
                        <div class="rf-add">
                            <div>
                                <label for="dayPicker">Pick a day</label>
                                <input type="date" id="dayPicker">
                            </div>
                            <button type="button" class="btn btn-dark" id="addDay">+ Add day</button>
                        </div>
                        <div class="rf-chips" id="dayChips">
                            <?php if ($mode === 'days'): foreach ($days as $d): ?>
                                <span class="chip" data-d="<?= $e($d) ?>">
                                    <span><?= $e(date('M j, Y', strtotime($d))) ?></span>
                                    <input type="hidden" name="days[]" value="<?= $e($d) ?>">
                                    <button type="button" aria-label="Remove day">&times;</button>
                                </span>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <div class="rf-actions">
                        <button class="btn btn-primary" type="submit">Filter</button>
                        <button type="button" class="btn btn-dark" onclick="window.print()">&#128424; Print Report</button>
                    </div>
                </form>
                <p class="report-hint">
                    Print Report prints exactly what is shown below. After changing the options, click Filter first.
                </p>
            </div>

            <!-- ============ Summary ============ -->
            <div class="panel">
                <div class="print-only">
                    <h2 style="margin:0 0 4px;">Attendance Report</h2>
                    <p style="margin:0 0 16px;color:#555;">
                        <?= $e($employeeLabel) ?><br>
                        <?= $e($periodLabel) ?><?= $courseFilter !== '' ? ' &middot; ' . $e($courseFilter) : '' ?><?= $statusFilter ? ' &middot; ' . $e($statusFilter) . ' only' : '' ?>
                    </p>
                </div>

                <p class="report-meta no-print">
                    Showing <strong><?= $e($employeeLabel) ?></strong> for <strong><?= $e($periodLabel) ?></strong><?= $courseFilter !== '' ? ' &ndash; course <strong>' . $e($courseFilter) . '</strong>' : '' ?><?= $statusFilter ? ' &ndash; <strong>' . $e($statusFilter) . '</strong> only' : '' ?>.
                </p>

                <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Employee ID</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Course</th>
                            <th>Days Present</th>
                            <th>Days Late</th>
                            <th>Days Absent</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" style="text-align:center;color:#8a94a6;padding:24px;">No employees match these filters.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= $e($r['employee_id']) ?></td>
                            <td><?= $e($r['name']) ?></td>
                            <td><?= $e($r['department']) ?></td>
                            <td><?= $e($r['course'] ?? '') ?></td>
                            <td><?= (int)$r['days_present'] ?></td>
                            <td><?= (int)$r['days_late'] ?></td>
                            <td><?= (int)$r['days_absent'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <?php if ($selectedEmp): ?>
            <!-- ============ Day-by-day log for the chosen employee ============ -->
            <div class="panel">
                <div class="panel-head"><h2>Attendance Details &ndash; <?= $e($selectedEmp['name']) ?></h2></div>
                <form method="post" action="attendance_bulk_delete.php" data-bulk>
                <input type="hidden" name="employee" value="<?= (int)$empPk ?>">
                <input type="hidden" name="back" value="<?= $e($backUrl) ?>">
                <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="col-check no-print"><input type="checkbox" class="bulk-all" title="Select all" aria-label="Select all"></th>
                            <th>#</th>
                            <th>Date</th>
                            <th>Shift</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Status</th>
                            <th class="no-print"><span style="display:inline-flex;align-items:center;gap:8px;">Tools <button type="submit" data-admin-confirm class="btn btn-danger bulk-btn" disabled title="Delete selected" style="padding:6px 12px;font-size:12px;">&times; Delete Selected (<span class="bulk-count">0</span>)</button></span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$details): ?>
                        <tr><td colspan="8" style="text-align:center;color:#8a94a6;padding:24px;"><?= $statusFilter ? 'No ' . $e(strtolower($statusFilter)) . ' records for this period.' : 'No attendance records for this period.' ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($details as $i => $r): ?>
                        <?php
                        // What the checkbox stands for: a real punch record, or a missed day (absence).
                        // Removed (excused) absences can't be ticked - they are restored with the arrow instead.
                        $pick = !empty($r['excused']) ? '' : (!empty($r['absent']) ? 'abs:' . $r['log_date'] : 'rec:' . (int)$r['id']);
                        ?>
                        <tr>
                            <td class="col-check no-print"><?php if ($pick !== ''): ?><input type="checkbox" class="bulk-check" name="items[]" value="<?= $e($pick) ?>" aria-label="Select <?= $e($r['log_date']) ?>"><?php endif; ?></td>
                            <td><?= $i + 1 ?></td>
                            <td><?= $e(date('D, M j, Y', strtotime($r['log_date']))) ?></td>
                            <td><?= !empty($r['absent']) ? '&mdash;' : $e($r['shift_label'] ?: 'Shift ' . $r['shift_order']) ?></td>
                            <td class="t-time"><?= $r['time_in'] ? $e(format_time_am_pm($r['time_in'])) : '&mdash;' ?></td>
                            <td class="t-time"><?= $r['time_out'] ? $e(format_time_am_pm($r['time_out'])) : '&mdash;' ?></td>
                            <td>
                                <span class="pill <?= att_pill($r['status']) ?>"><?= $e($r['status']) ?></span>
                            </td>
                            <td class="no-print" style="white-space:nowrap;">
                                <?php $q = $e(http_build_query(['employee' => $empPk, 'date' => $r['log_date'], 'back' => $backUrl])); ?>
                                <?php if (!empty($r['excused'])): ?>
                                    <a class="tool-btn tool-view" title="Restore this absence" href="attendance_edit.php?<?= $q ?>&amp;excused=1">&#8630;</a>
                                <?php elseif (!empty($r['absent'])): ?>
                                    <a class="tool-btn tool-edit" title="Edit - mark present/late or delete this absence" href="attendance_edit.php?<?= $q ?>">&#9998;</a>
                                <?php else: ?>
                                    <a class="tool-btn tool-edit" title="Edit or delete this record" href="attendance_edit.php?<?= $q ?>&amp;id=<?= (int)$r['id'] ?>">&#9998;</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                </form>
            </div>
            <?php endif; ?>

<?php include 'includes/footer.php'; ?>
<script>
(function () {
    var form = document.getElementById('reportForm');
    if (!form) return;

    var modeSel  = document.getElementById('mode');
    var groups   = form.querySelectorAll('.rf-group');
    var dayInput = document.getElementById('dayPicker');
    var chips    = document.getElementById('dayChips');

    // Show only the fields for the chosen period type; disable the rest so
    // they aren't sent in the URL.
    function applyMode() {
        groups.forEach(function (g) {
            var on = g.getAttribute('data-mode') === modeSel.value;
            g.hidden = !on;
            g.querySelectorAll('input').forEach(function (i) { i.disabled = !on; });
        });
    }
    modeSel.addEventListener('change', applyMode);
    applyMode();

    function fmt(v) {
        var p = v.split('-');
        return new Date(+p[0], +p[1] - 1, +p[2])
            .toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function addDay(v) {
        if (!v || chips.querySelector('[data-d="' + v + '"]')) return;
        var chip = document.createElement('span');
        chip.className = 'chip';
        chip.setAttribute('data-d', v);

        var label = document.createElement('span');
        label.textContent = fmt(v);

        var hid = document.createElement('input');
        hid.type = 'hidden';
        hid.name = 'days[]';
        hid.value = v;

        var x = document.createElement('button');
        x.type = 'button';
        x.setAttribute('aria-label', 'Remove day');
        x.innerHTML = '&times;';

        chip.appendChild(label);
        chip.appendChild(hid);
        chip.appendChild(x);
        chips.appendChild(chip);
    }

    document.getElementById('addDay').addEventListener('click', function () {
        addDay(dayInput.value);
        dayInput.value = '';
    });

    chips.addEventListener('click', function (ev) {
        if (ev.target.tagName === 'BUTTON') ev.target.closest('.chip').remove();
    });

    // If a day was picked but "Add day" wasn't pressed, include it anyway.
    form.addEventListener('submit', function () {
        if (modeSel.value === 'days' && dayInput.value) {
            addDay(dayInput.value);
            dayInput.value = '';
        }
    });
})();
</script>
