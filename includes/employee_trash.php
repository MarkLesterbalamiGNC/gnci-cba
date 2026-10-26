<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/trash_functions.php';
require_once 'includes/admin_confirm.php';
require_admin();

purge_expired_deleted_employees();

$error = '';
$purgeId = (int)($_GET['purge'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'restore') {
        $_SESSION['flash'] = restore_employee($id)
            ? ['type' => 'success', 'msg' => 'Employee restored. They can sign in again.']
            : ['type' => 'error', 'msg' => 'Employee could not be restored.'];
        header('Location: employee_trash.php');
        exit;
    }

    if ($action === 'purge') {
        $error = check_admin_password($_POST['admin_password'] ?? '');
        if ($error === null) {
            permanently_delete_employee($id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Employee permanently deleted.'];
            header('Location: employee_trash.php');
            exit;
        }
        $purgeId = $id; // show the password screen again with the error
    }
}

if ($purgeId) {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? AND deleted_at IS NOT NULL");
    $stmt->execute([$purgeId]);
    if ($emp = $stmt->fetch()) {
        $confirm = [
            'title'   => 'Delete Permanently',
            'crumb'   => 'Recently Deleted',
            'message' => 'Permanently delete ' . $emp['name'] . ' (ID ' . $emp['employee_id'] . ')? Their login account and all attendance records will be erased and this cannot be undone.',
            'hidden'  => ['action' => 'purge', 'id' => $purgeId],
            'button'  => 'Delete Permanently',
            'back'    => 'employee_trash.php',
            'error'   => $error,
        ];
        include 'includes/admin_confirm_page.php';
        exit;
    }
}

$days = (int)TRASH_RETENTION_DAYS;
$rows = $pdo->query(
    "SELECT *, DATE_ADD(deleted_at, INTERVAL $days DAY) AS purge_at,
            GREATEST(0, CEIL(TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(deleted_at, INTERVAL $days DAY)) / 86400)) AS days_left
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
<link rel="stylesheet" href="assets/css/base.css">
<link rel="stylesheet" href="assets/css/style.css">
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
                    Deleted employees are kept here for <?= $days ?> days and then permanently deleted
                    (including their login account and attendance records). Restore one before then to bring it back.
                </p>
                <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th><th>Name</th><th>Employee ID</th><th>Department</th><th>Email Address</th>
                            <th>Deleted On</th><th>Permanently Deleted In</th><th>Tools</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="8" style="text-align:center;color:#8a94a6;padding:24px;">No recently deleted employees.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $i => $r): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($r['name']) ?></td>
                            <td><?= htmlspecialchars($r['employee_id']) ?></td>
                            <td><?= htmlspecialchars($r['department']) ?></td>
                            <td><?= htmlspecialchars($r['email']) ?></td>
                            <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($r['deleted_at']))) ?></td>
                            <td>
                                <?php $dl = (int)$r['days_left']; ?>
                                <span class="pill <?= $dl <= 7 ? 'pill-red' : 'pill-green' ?>"><?= $dl ?> day<?= $dl === 1 ? '' : 's' ?></span>
                                <div style="color:#8a94a6;font-size:11.5px;margin-top:3px;"><?= htmlspecialchars(date('M j, Y', strtotime($r['purge_at']))) ?></div>
                            </td>
                            <td style="white-space:nowrap;">
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="btn btn-primary" style="padding:6px 12px;font-size:12px;">Restore</button>
                                </form>
                                <a href="employee_trash.php?purge=<?= (int)$r['id'] ?>" data-admin-confirm class="btn btn-danger" style="padding:6px 12px;font-size:12px;">Delete Permanently</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

<?php include 'includes/footer.php'; ?>
