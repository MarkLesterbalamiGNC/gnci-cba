<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_admin();
require_once 'includes/trash_functions.php';
require_once 'includes/fingerprint_functions.php';
fp_ensure_table($pdo);
purge_expired_deleted_employees();

$search = trim($_GET['q'] ?? '');
$perPage = (int)($_GET['show'] ?? 10);
if (!in_array($perPage, [10, 25, 50, 100])) $perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Sorting (whitelisted - never put raw input into ORDER BY).
// Click a column header to sort; click again to flip ascending / descending.
$sortColumns = [
    'name'     => ['Name',          'name'],
    'id'       => ['Employee ID',   'employee_id'],
    'course'   => ['Course',        'course'],
    'email'    => ['Email Address', 'email'],
    'position' => ['Position',      'position'],
    'mobile'   => ['Mobile No.',    'mobile_no'],
];
$sort = $_GET['sort'] ?? 'newest';
$dir  = strtolower($_GET['dir'] ?? 'asc');
// Backwards-compatible with old links
if ($sort === 'course_asc')  { $sort = 'course'; $dir = 'asc'; }
if ($sort === 'course_desc') { $sort = 'course'; $dir = 'desc'; }
if ($sort === 'name_asc')    { $sort = 'name';   $dir = 'asc'; }
if ($sort !== 'newest' && !isset($sortColumns[$sort])) $sort = 'newest';
if ($dir !== 'desc') $dir = 'asc';
$dirSql = strtoupper($dir);

if ($sort === 'newest') {
    $orderBy = 'id DESC';
} else {
    $col = $sortColumns[$sort][1];
    // Blank values always go last, whichever way you sort
    $orderBy = "($col IS NULL OR $col = ''), $col $dirSql, name ASC";
}

/** Header cell that sorts on click and shows an arrow on the active column. */
function sort_th(string $key, string $label, string $sort, string $dir): string {
    $qs = $_GET; unset($qs['page']);
    $qs['sort'] = $key;
    $qs['dir']  = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = $sort === $key ? ($dir === 'asc' ? ' &#9650;' : ' &#9660;') : '';
    $cls   = $sort === $key ? ' class="th-sorted"' : '';
    return '<th' . $cls . '><a class="th-sort" href="employee.php?' . htmlspecialchars(http_build_query($qs))
         . '" title="Sort by ' . htmlspecialchars($label) . '">' . htmlspecialchars($label) . $arrow . '</a></th>';
}

$where = 'WHERE deleted_at IS NULL';
$params = [];

// Course filter (CBA courses only; anything else is ignored)
$allowedCourses = ['BSIT', 'BSBA', 'BSA'];
$courseFilter = $_GET['course'] ?? '';
if (!in_array($courseFilter, $allowedCourses, true)) $courseFilter = '';
if ($courseFilter !== '') {
    $where .= ' AND course = ?';
    $params[] = $courseFilter;
}
if ($search !== '') {
    $where .= " AND (name LIKE ? OR employee_id LIKE ? OR department LIKE ? OR course LIKE ? OR email LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like, $like);
}

$total = $pdo->prepare("SELECT COUNT(*) c FROM employees $where");
$total->execute($params);
$totalRows = (int)$total->fetch()['c'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$sql = "SELECT employees.*, (SELECT COUNT(*) FROM employee_fingerprints f WHERE f.employee_id = employees.id) AS finger_count
        FROM employees $where ORDER BY $orderBy LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee List | BioTrack</title>
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
                <h1>Employee List</h1>
                <div class="breadcrumb"><a href="dashboard.php">Home</a> &rsaquo; Employee</div>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
            <?php endif; ?>

            <div class="panel">
                <div class="panel-head">
                    <h2></h2>
                    <div class="toolbar-actions">
                        <a href="employee_export.php" class="btn btn-outline-dark" title="Download all employees as a CSV file">&#8681; Export All</a>
                        <a href="employee_import.php" class="btn btn-dark" title="Bulk-add employees from a CSV file">&#8679; Import</a>
                        <a href="employee_add.php" class="btn btn-primary">+ Add New Employee</a>
                    </div>
                </div>

                <form method="get" class="table-toolbar">
                    <div>
                        Show
                        <select name="show" onchange="this.form.submit()">
                            <?php foreach ([10,25,50,100] as $n): ?>
                                <option value="<?= $n ?>" <?= $perPage==$n?'selected':'' ?>><?= $n ?></option>
                            <?php endforeach; ?>
                        </select>
                        entries
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
                        <input type="text" name="q" placeholder="Search" value="<?= htmlspecialchars($search) ?>">
                    </div>
                </form>

                <form method="post" action="employee_delete.php" data-bulk>
                <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="col-check"><input type="checkbox" class="bulk-all" title="Select all on this page" aria-label="Select all"></th>
                            <th>#</th>
                            <?= sort_th('name', 'Name', $sort, $dir) ?>
                            <?= sort_th('id', 'Employee ID', $sort, $dir) ?>
                            <th>Department</th>
                            <?= sort_th('course', 'Course', $sort, $dir) ?>
                            <?= sort_th('email', 'Email Address', $sort, $dir) ?>
                            <?= sort_th('position', 'Position', $sort, $dir) ?>
                            <?= sort_th('mobile', 'Mobile No.', $sort, $dir) ?>
                            <th>Photo</th>
                            <th>Fingerprint</th>
                            <th><span style="display:inline-flex;align-items:center;gap:8px;">Tools <button type="submit" data-admin-confirm class="btn btn-danger bulk-btn" disabled title="Delete selected" style="padding:6px 12px;font-size:12px;">&times; Delete Selected (<span class="bulk-count">0</span>)</button></span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$employees): ?>
                        <tr><td colspan="12" style="text-align:center;color:#8a94a6;padding:24px;">No employees found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($employees as $i => $emp): ?>
                        <tr>
                            <td class="col-check"><input type="checkbox" class="bulk-check" name="ids[]" value="<?= (int)$emp['id'] ?>" aria-label="Select <?= htmlspecialchars($emp['name'], ENT_QUOTES) ?>"></td>
                            <td><?= $offset + $i + 1 ?></td>
                            <td><?= htmlspecialchars($emp['name']) ?></td>
                            <td><?= htmlspecialchars($emp['employee_id']) ?></td>
                            <td><?= htmlspecialchars($emp['department']) ?></td>
                            <td><?= htmlspecialchars($emp['course'] ?? '') ?></td>
                            <td><?= htmlspecialchars($emp['email']) ?></td>
                            <td><?= htmlspecialchars($emp['position']) ?></td>
                            <td><?= htmlspecialchars($emp['mobile_no']) ?></td>
                            <td>
                                <span class="photo-thumb">
                                    <?php if ($emp['photo']): ?>
                                        <img src="uploads/photos/<?= htmlspecialchars($emp['photo']) ?>" alt="">
                                    <?php else: ?>
                                        <?= bt_icon('user') ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($emp['fingerprint_enrolled']): ?>
                                    <span class="pill pill-green">Enrolled<?= (int)$emp['finger_count'] > 1 ? ' (' . (int)$emp['finger_count'] . ' fingers)' : '' ?></span>
                                <?php else: ?>
                                    <span class="pill pill-red">Not Set</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="tool-btn tool-view" title="Export this employee" href="employee_export.php?id=<?= $emp['id'] ?>">&#8681;</a>
                                <a class="tool-btn tool-edit" title="Edit" href="employee_edit.php?id=<?= $emp['id'] ?>">&#9998;</a>
                                <a class="tool-btn tool-delete" data-admin-confirm title="Delete" href="employee_delete.php?id=<?= (int)$emp['id'] ?>">&times;</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                </form>

                <div class="table-footer">
                    <div>Showing <?= $employees ? $offset+1 : 0 ?> to <?= min($offset+$perPage, $totalRows) ?> of <?= $totalRows ?> entries</div>
                    <div class="pagination">
                        <?php
                        $qs = $_GET; unset($qs['page']);
                        $base = 'employee.php?' . http_build_query($qs) . '&page=';
                        ?>
                        <a href="<?= $base . max(1,$page-1) ?>">Previous</a>
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <?php if ($p == $page): ?>
                                <span class="active"><?= $p ?></span>
                            <?php else: ?>
                                <a href="<?= $base . $p ?>"><?= $p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <a href="<?= $base . min($totalPages,$page+1) ?>">Next</a>
                    </div>
                </div>
            </div>

<?php include 'includes/footer.php'; ?>
