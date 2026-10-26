<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/auth_role.php';
require_once 'includes/attendance_summary.php';
require_admin();

$date = $_GET['date'] ?? date('Y-m-d');
$search = trim($_GET['q'] ?? '');

$allowedCourses = ['BSIT', 'BSBA', 'BSA'];
$courseFilter = $_GET['course'] ?? '';
if (!in_array($courseFilter, $allowedCourses, true)) $courseFilter = '';

// Sorting (whitelisted - never put raw input into ORDER BY).
// Click a column header to sort; click it again to flip ascending / descending.
// Blank courses always go last.
$sortColumns = [
    'id'     => ['Employee ID', 'e.employee_id'],
    'name'   => ['Name',        'e.name'],
    'course' => ['Course',      'e.course'],
    'shift'  => ['Shift',       'a.shift_order'],
    'in'     => ['Time In',     'a.time_in'],
    'out'    => ['Time Out',    'a.time_out'],
    'status' => ['Status',      'a.status'],
];
$sort = $_GET['sort'] ?? 'name';
$dir  = strtolower($_GET['dir'] ?? 'asc');
// Backwards-compatible with old links (course_asc / course_desc)
if ($sort === 'course_asc')  { $sort = 'course'; $dir = 'asc'; }
if ($sort === 'course_desc') { $sort = 'course'; $dir = 'desc'; }
if (!isset($sortColumns[$sort])) $sort = 'name';
if ($dir !== 'desc') $dir = 'asc';
$dirSql = strtoupper($dir);

$col = $sortColumns[$sort][1];
if ($sort === 'course') {
    $orderBy = "(e.course IS NULL OR e.course = ''), e.course $dirSql, e.name ASC";
} elseif (in_array($sort, ['in', 'out'], true)) {
    // Records with no time (NULL) always go last, whichever way you sort
    $orderBy = "($col IS NULL), $col $dirSql, e.name ASC";
} else {
    $orderBy = "$col $dirSql, e.name ASC";
}

/** Header cell that sorts on click and shows an arrow on the active column. */
function sort_th(string $key, string $label, string $sort, string $dir): string {
    $qs = $_GET;
    $qs['sort'] = $key;
    $qs['dir']  = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = $sort === $key ? ($dir === 'asc' ? ' &#9650;' : ' &#9660;') : '';
    $cls   = $sort === $key ? ' class="th-sorted"' : '';
    return '<th' . $cls . '><a class="th-sort" href="attendance_list.php?' . htmlspecialchars(http_build_query($qs))
         . '" title="Sort by ' . htmlspecialchars($label) . '">' . htmlspecialchars($label) . $arrow . '</a></th>';
}

$where = "WHERE a.log_date = ?";
$params = [$date];

if ($courseFilter !== '') {
    $where .= " AND e.course = ?";
    $params[] = $courseFilter;
}

if ($search !== '') {
    $where .= " AND (e.name LIKE ? OR e.employee_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql = "SELECT a.*, e.name, e.employee_id, e.department, e.course, e.photo
        FROM attendance a
        JOIN employees e ON e.id = a.employee_id AND e.deleted_at IS NULL
        $where
        ORDER BY $orderBy, a.shift_order ASC, a.time_in DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

function h_time($t) {
    if (!$t) return null;
    $am = format_time_am_pm($t);
    return $am;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance List | BioTrack</title>
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
                <h1>Attendance List</h1>
                <div class="breadcrumb"><a href="dashboard.php">Home</a> &rsaquo; Attendance List</div>
            </div>

            <div class="panel">
                <form method="get" class="table-toolbar">
                    <div>
                        <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" onchange="this.form.submit()">
                    </div>
                    <div>
                        Course
                        <select name="course" onchange="this.form.submit()">
                            <option value="">All courses</option>
                            <?php foreach ($allowedCourses as $c): ?>
                                <option value="<?= $c ?>" <?= $courseFilter === $c ? 'selected' : '' ?>><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
                    <div>
                        <input type="text" name="q" placeholder="Search employee" value="<?= htmlspecialchars($search) ?>">
                    </div>
                </form>

                <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Photo</th>
                            <?= sort_th('id', 'Employee ID', $sort, $dir) ?>
                            <?= sort_th('name', 'Name', $sort, $dir) ?>
                            <th>Department</th>
                            <?= sort_th('course', 'Course', $sort, $dir) ?>
                            <?= sort_th('shift', 'Shift', $sort, $dir) ?>
                            <?= sort_th('in', 'Time In', $sort, $dir) ?>
                            <?= sort_th('out', 'Time Out', $sort, $dir) ?>
                            <?= sort_th('status', 'Status', $sort, $dir) ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="10" style="text-align:center;color:#8a94a6;padding:24px;">No attendance records for this date.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $i => $r): ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td>
                                <span class="photo-thumb">
                                    <?php if ($r['photo']): ?><img src="uploads/photos/<?= htmlspecialchars($r['photo']) ?>"><?php else: ?><?= bt_icon('user') ?><?php endif; ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($r['employee_id']) ?></td>
                            <td><?= htmlspecialchars($r['name']) ?></td>
                            <td><?= htmlspecialchars($r['department']) ?></td>
                            <td><?= htmlspecialchars($r['course'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['shift_label'] ?: 'Shift ' . $r['shift_order']) ?></td>
                            <td class="t-time"><?= $r['time_in'] ? htmlspecialchars(h_time($r['time_in'])) : '&mdash;' ?></td>
                            <td class="t-time"><?= $r['time_out'] ? htmlspecialchars(h_time($r['time_out'])) : '&mdash;' ?></td>

                            <td>
                                <?php $st = att_row_status($r); $cls = att_pill($st); ?>
                                <span class="pill <?= $cls ?>"><?= htmlspecialchars($st) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

<?php include 'includes/footer.php'; ?>
