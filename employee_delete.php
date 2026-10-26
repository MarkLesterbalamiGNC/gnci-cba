<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/trash_functions.php';
require_once 'includes/admin_confirm.php';
require_admin();

// One employee (?id=5) or several ticked on the Employee list (ids[]=5&ids[]=7)
if (isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = $_POST['ids'];
} else {
    $ids = [$_GET['id'] ?? $_POST['id'] ?? 0];
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));

$emps = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id IN ($in) AND deleted_at IS NULL ORDER BY name ASC");
    $stmt->execute($ids);
    $emps = $stmt->fetchAll();
}

if (!$emps) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => count($ids) > 1 || isset($_POST['ids']) ? 'No employees were selected.' : 'Employee not found.'];
    header('Location: employee.php');
    exit;
}
$ids = array_map(fn($r) => (int)$r['id'], $emps);

$error = '';
// The password box is only processed when it was actually submitted
// (the first POST from the list just carries the ticked ids).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    $error = check_admin_password($_POST['admin_password']);
    if ($error === null) {
        $done = 0;
        foreach ($ids as $id) {
            if (soft_delete_employee($id)) $done++;
        }
        $who = count($emps) === 1 ? $emps[0]['name'] : $done . ' employees';
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $who . ' ' . ($done === 1 ? 'was' : 'were') . ' moved to Recently Deleted. They will be permanently deleted after ' . format_duration_seconds(TRASH_RETENTION_SECONDS) . ' unless restored.'];
        header('Location: employee.php');
        exit;
    }
}

$single = count($emps) === 1;
$confirm = [
    'title'   => $single ? 'Delete Employee' : 'Delete ' . count($emps) . ' Employees',
    'crumb'   => 'Delete Employee',
    'message' => $single
        ? 'You are about to delete ' . $emps[0]['name'] . ' (ID ' . $emps[0]['employee_id'] . '). They will be moved to Recently Deleted and can be restored within ' . format_duration_seconds(TRASH_RETENTION_SECONDS) . '. They will not be able to sign in meanwhile.'
        : 'You are about to delete the ' . count($emps) . ' employees below. They will be moved to Recently Deleted and can be restored within ' . format_duration_seconds(TRASH_RETENTION_SECONDS) . '. They will not be able to sign in meanwhile.',
    'items'   => $single ? [] : array_map(fn($r) => $r['name'] . ' (ID ' . $r['employee_id'] . ')', $emps),
    'confirm_msg' => $single ? 'Are you sure you want to delete ' . $emps[0]['name'] . '? They will be moved to Recently Deleted.' : 'Are you sure you want to delete these ' . count($emps) . ' employees? They will be moved to Recently Deleted.',
    'hidden'  => ['ids' => $ids],
    'button'  => $single ? 'Delete Employee' : 'Delete ' . count($emps) . ' Employees',
    'back'    => 'employee.php',
    'error'   => $error,
];
include 'includes/admin_confirm_page.php';
