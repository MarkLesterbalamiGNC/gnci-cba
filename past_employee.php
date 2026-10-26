<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_admin();

$search  = trim($_GET['q'] ?? '');
$perPage = (int)($_GET['show'] ?? 10);
if (!in_array($perPage, [10, 25, 50, 100])) $perPage = 10;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where  = '';
$params = [];
if ($search !== '') {
    $where  = "WHERE name LIKE ? OR employee_id LIKE ? OR department LIKE ? OR email LIKE ?";
    $like   = "%$search%";
    $params = [$like, $like, $like, $like];
}

// Sorting (whitelisted - never put raw input into ORDER BY). Click a header to sort, again to flip.
$sortColumns = [
    'name'     => ['Name',                    'name'],
    'id'       => ['Employee ID',             'employee_id'],
    'course'   => ['Course',                  'course'],
    'position' => ['Position',                'position'],
    'email'    => ['Email Address',           'email'],
    'mobile'   => ['Mobile No.',              'mobile_no'],
    'records'  => ['Attendance Records',      'attendance_count'],
    'deleted'  => ['Deleted On',              'deleted_at'],
    'purged'   => ['Permanently Deleted On',  'archived_at'],
    'method'   => ['Method',                  'purge_type'],
];
$sort = $_GET['sort'] ?? 'purged';
$dir  = strtolower($_GET['dir'] ?? 'desc');
if (!isset($sortColumns[$sort])) $sort = 'purged';
if ($dir !== 'asc') $dir = 'desc';
$dirSql = strtoupper($dir);
$col = $sortColumns[$sort][1];
// Blank values always go last, whichever way you sort
$orderBy = in_array($sort, ['records'], true)
    ? "$col $dirSql, id DESC"
    : "($col IS NULL OR $col = ''), $col $dirSql, id DESC";

/** Header cell that sorts on click and shows an arrow on the active column. */
function sort_th(string $key, string $label, string $sort, string $dir): string {
    $qs = $_GET; unset($qs['page']);
    $qs['sort'] = $key;
    $qs['dir']  = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = $sort === $key ? ($dir === 'asc' ? ' &#9650;' : ' &#9660;') : '';
    $cls   = $sort === $key ? ' class="th-sorted"' : '';
    return '<th' . $cls . '><a class="th-sort" href="past_employee.php?' . htmlspecialchars(http_build_query($qs))
         . '" title="Sort by ' . htmlspecialchars($label) . '">' . htmlspecialchars($label) . $arrow . '</a></th>';
}

$total = $pdo->prepare("SELECT COUNT(*) FROM past_employee $where");
$total->execute($params);
$totalRows  = (int)$total->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$stmt = $pdo->prepare("SELECT * FROM past_employee $where ORDER BY $orderBy LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Permanently Deleted | BioTrack</title>
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
                <h1>Past Employee</h1>
                <div class="breadcrumb"><a href="dashboard.php">Home</a> &rsaquo; Archive &rsaquo; Past Employee</div>
            </div>

            <div class="panel">
                <p style="color:#8a94a6;font-size:13px;margin-top:0;">
                    A record of employees who were permanently deleted. Their login account, photo and attendance
                    records are gone, but their details are kept here for reference. Archived entries are read-only.
                </p>

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
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
                    <div>
                        <input type="text" name="q" placeholder="Search" value="<?= htmlspecialchars($search) ?>">
                    </div>
                </form>

                <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <?= sort_th('name', 'Name', $sort, $dir) ?>
                            <?= sort_th('id', 'Employee ID', $sort, $dir) ?>
                            <th>Department</th>
                            <?= sort_th('course', 'Course', $sort, $dir) ?>
                            <?= sort_th('position', 'Position', $sort, $dir) ?>
                            <?= sort_th('email', 'Email Address', $sort, $dir) ?>
                            <?= sort_th('mobile', 'Mobile No.', $sort, $dir) ?>
                            <?= sort_th('records', 'Attendance Records', $sort, $dir) ?>
                            <?= sort_th('deleted', 'Deleted On', $sort, $dir) ?>
                            <?= sort_th('purged', 'Permanently Deleted On', $sort, $dir) ?>
                            <?= sort_th('method', 'Method', $sort, $dir) ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="12" style="text-align:center;color:#8a94a6;padding:24px;">No archived employees.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $i => $r): ?>
                        <tr>
                            <td><?= $offset + $i + 1 ?></td>
                            <td><?= htmlspecialchars($r['name']) ?></td>
                            <td><?= htmlspecialchars($r['employee_id']) ?></td>
                            <td><?= htmlspecialchars($r['department'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['course'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['position'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['email'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['mobile_no'] ?? '') ?></td>
                            <td><?= (int)$r['attendance_count'] ?></td>
                            <td><?= $r['deleted_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($r['deleted_at']))) : '&mdash;' ?></td>
                            <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($r['archived_at']))) ?></td>
                            <td>
                                <?php if ($r['purge_type'] === 'auto'): ?>
                                    <span class="pill pill-red" style="white-space:nowrap;">&#9201; Auto Deleted</span>
                                <?php else: ?>
                                    <span class="pill pill-green" style="white-space:nowrap;">&#9998; Manually Deleted</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <div class="table-footer">
                    <div>Showing <?= $rows ? $offset+1 : 0 ?> to <?= min($offset+$perPage, $totalRows) ?> of <?= $totalRows ?> entries</div>
                    <div class="pagination">
                        <?php
                        $qs = $_GET; unset($qs['page']);
                        $base = 'past_employee.php?' . http_build_query($qs) . '&page=';
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
