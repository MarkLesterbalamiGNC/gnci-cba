<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/trash_functions.php';
require_once 'includes/admin_confirm.php';
require_admin();

purge_expired_deleted_employees();

$clean = fn($arr) => array_values(array_unique(array_filter(array_map('intval', (array)$arr), fn($v) => $v > 0)));

$error = '';
$purgeIds = isset($_GET['purge']) ? $clean([$_GET['purge']]) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Restore button on a single row
    if (isset($_POST['restore_one'])) {
        $_SESSION['flash'] = restore_employee((int)$_POST['restore_one'])
            ? ['type' => 'success', 'msg' => 'Employee restored. They can sign in again.']
            : ['type' => 'error', 'msg' => 'Employee could not be restored.'];
        header('Location: employee_trash.php');
        exit;
    }

    // "Restore Selected" (ticked checkboxes)
    if ($action === 'restore_selected') {
        $n = 0;
        foreach ($clean($_POST['ids'] ?? []) as $rid) {
            if (restore_employee($rid)) $n++;
        }
        $_SESSION['flash'] = $n
            ? ['type' => 'success', 'msg' => $n . ' employee' . ($n === 1 ? '' : 's') . ' restored. They can sign in again.']
            : ['type' => 'error', 'msg' => 'No employees were restored.'];
        header('Location: employee_trash.php');
        exit;
    }

    // "Delete Selected Permanently" (action = purge_selected) and the password screen (action = purge)
    if ($action === 'purge' || $action === 'purge_selected') {
        $purgeIds = $clean($_POST['ids'] ?? []);
        // Only check the password when it was actually submitted; the first POST
        // from the list just carries the ticked ids.
        if (isset($_POST['admin_password'])) {
            $error = check_admin_password($_POST['admin_password']);
            if ($error === null) {
                $n = 0;
                foreach ($purgeIds as $pid) {
                    permanently_delete_employee($pid);
                    $n++;
                }
                $_SESSION['flash'] = ['type' => 'success', 'msg' => $n === 1 ? 'Employee permanently deleted.' : $n . ' employees permanently deleted.'];
                header('Location: employee_trash.php');
                exit;
            }
        }
    }
}

if ($purgeIds) {
    $in = implode(',', array_fill(0, count($purgeIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id IN ($in) AND deleted_at IS NOT NULL ORDER BY name ASC");
    $stmt->execute($purgeIds);
    $emps = $stmt->fetchAll();

    if ($emps) {
        $single = count($emps) === 1;
        $confirm = [
            'title'   => $single ? 'Delete Permanently' : 'Delete ' . count($emps) . ' Employees Permanently',
            'crumb'   => 'Recently Deleted',
            'message' => $single
                ? 'Permanently delete ' . $emps[0]['name'] . ' (ID ' . $emps[0]['employee_id'] . ')? Their login account and all attendance records will be erased and this cannot be undone.'
                : 'Permanently delete the ' . count($emps) . ' employees below? Their login accounts and all attendance records will be erased and this cannot be undone.',
            'items'   => $single ? [] : array_map(fn($r) => $r['name'] . ' (ID ' . $r['employee_id'] . ')', $emps),
            'confirm_msg' => $single ? 'Are you sure you want to permanently delete ' . $emps[0]['name'] . '? This cannot be undone.' : 'Are you sure you want to permanently delete these ' . count($emps) . ' employees? This cannot be undone.',
            'hidden'  => ['action' => 'purge', 'ids' => array_map(fn($r) => (int)$r['id'], $emps)],
            'button'  => $single ? 'Delete Permanently' : 'Delete ' . count($emps) . ' Permanently',
            'back'    => 'employee_trash.php',
            'error'   => $error,
        ];
        include 'includes/admin_confirm_page.php';
        exit;
    }
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Those employees are no longer in Recently Deleted.'];
}

$secs = (int)TRASH_RETENTION_SECONDS;
$warnSecs = min(7 * 86400, max(10, intdiv($secs, 5))); // pill turns red when this little time is left
$rows = $pdo->query(
    "SELECT *, DATE_ADD(deleted_at, INTERVAL $secs SECOND) AS purge_at,
            GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(deleted_at, INTERVAL $secs SECOND))) AS secs_left
     FROM employees WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC"
)->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recently Deleted | BioTrack</title>
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
                <h1>Recently Deleted Employees</h1>
                <div class="breadcrumb"><a href="dashboard.php">Home</a> &rsaquo; <a href="employee.php">Employee</a> &rsaquo; Recently Deleted</div>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
            <?php endif; ?>

            <div class="panel">
                <p style="color:#8a94a6;font-size:13px;margin-top:0;">
                    Deleted employees are kept here for <?= htmlspecialchars(format_duration_seconds($secs)) ?> and then permanently deleted
                    (including their login account and attendance records). Restore one before then to bring it back.
                </p>

                <form method="post" data-bulk>
                <div class="bulk-bar">
                    <button type="submit" name="action" value="restore_selected" class="btn btn-primary bulk-btn" disabled>&#8630; Restore Selected (<span class="bulk-count">0</span>)</button>
                    <span>Tick the employees you want, then use Restore Selected here or Delete Selected in the Tools column.</span>
                </div>
                <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="col-check"><input type="checkbox" class="bulk-all" title="Select all" aria-label="Select all"></th>
                            <th>#</th><th>Name</th><th>Employee ID</th><th>Department</th><th>Email Address</th>
                            <th>Deleted On</th><th>Permanently Deleted In</th><th><span style="display:inline-flex;align-items:center;gap:8px;">Tools <button type="submit" data-admin-confirm class="btn btn-danger bulk-btn" disabled title="Delete selected" name="action" value="purge_selected" style="padding:6px 12px;font-size:12px;">&times; Delete Selected (<span class="bulk-count">0</span>)</button></span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="9" style="text-align:center;color:#8a94a6;padding:24px;">No recently deleted employees.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $i => $r): ?>
                        <tr>
                            <td class="col-check"><input type="checkbox" class="bulk-check" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Select <?= htmlspecialchars($r['name'], ENT_QUOTES) ?>"></td>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($r['name']) ?></td>
                            <td><?= htmlspecialchars($r['employee_id']) ?></td>
                            <td><?= htmlspecialchars($r['department']) ?></td>
                            <td><?= htmlspecialchars($r['email']) ?></td>
                            <td><?= htmlspecialchars(date('M j, Y g:i:s A', strtotime($r['deleted_at']))) ?></td>
                            <td>
                                <?php $sl = (int)$r['secs_left']; ?>
                                <span class="pill countdown <?= $sl <= $warnSecs ? 'pill-red' : 'pill-green' ?>" data-left="<?= $sl ?>"><?= htmlspecialchars(format_time_left($sl)) ?></span>
                                <div style="color:#8a94a6;font-size:11.5px;margin-top:3px;"><?= htmlspecialchars(date('M j, Y g:i:s A', strtotime($r['purge_at']))) ?></div>
                            </td>
                            <td style="white-space:nowrap;">
                                <button type="submit" name="restore_one" value="<?= (int)$r['id'] ?>" class="btn btn-primary" style="padding:6px 12px;font-size:12px;">Restore</button>
                                <a href="employee_trash.php?purge=<?= (int)$r['id'] ?>" data-admin-confirm class="btn btn-danger" style="padding:6px 12px;font-size:12px;">Delete Permanently</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                </form>
            </div>

<script>
// Live countdown. When a record reaches 0 the page reloads, which runs the purge so the row disappears.
(function () {
    var warn = <?= (int)$warnSecs ?>;
    function fmt(s) {
        var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
        if (d > 0) return d + 'd' + (h ? ' ' + h + 'h' : '');
        if (h > 0) return h + 'h' + (m ? ' ' + m + 'm' : '');
        if (m > 0) return m + 'm' + (x ? ' ' + x + 's' : '');
        return x + 's';
    }
    var pills = document.querySelectorAll('.countdown');
    if (!pills.length) return;
    var reloading = false;
    function reloadSoon() { if (!reloading) { reloading = true; setTimeout(function () { location.reload(); }, 1500); } }
    setInterval(function () {
        pills.forEach(function (p) {
            var left = Math.max(0, parseInt(p.getAttribute('data-left'), 10) - 1);
            p.setAttribute('data-left', left);
            p.textContent = fmt(left);
            p.classList.toggle('pill-red', left <= warn);
            p.classList.toggle('pill-green', left > warn);
            if (left === 0) reloadSoon();
        });
    }, 1000);
    pills.forEach(function (p) { if (parseInt(p.getAttribute('data-left'), 10) === 0) reloadSoon(); });
})();
</script>
<?php include 'includes/footer.php'; ?>
