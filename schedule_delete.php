<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/schedule_functions.php';
require_once 'includes/admin_confirm.php';
require_admin();

// One schedule (?id=3) or several ticked on the Schedules list (ids[]=3&ids[]=4)
if (isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = $_POST['ids'];
} else {
    $ids = [$_GET['id'] ?? $_POST['id'] ?? 0];
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));

$schedules = [];
foreach ($ids as $sid) {
    if ($s = get_schedule($sid)) $schedules[] = $s;
}
if (!$schedules) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => isset($_POST['ids']) ? 'No schedules were selected.' : 'Schedule not found.'];
    header('Location: schedules.php');
    exit;
}
$ids = array_map(fn($s) => (int)$s['id'], $schedules);
$in  = implode(',', array_fill(0, count($ids), '?'));

$cnt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE schedule_id IN ($in) AND deleted_at IS NULL");
$cnt->execute($ids);
$assigned = (int)$cnt->fetchColumn();

$error = '';
// Only treat this as the password step when the password box was submitted
// (the first POST from the list just carries the ticked ids).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    $error = check_admin_password($_POST['admin_password']);
    if ($error === null) {
        // employees.schedule_id is ON DELETE SET NULL, so affected employees are
        // simply left with no schedule assigned rather than being deleted.
        $pdo->prepare("DELETE FROM schedules WHERE id IN ($in)")->execute($ids);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => count($ids) === 1 ? 'Schedule deleted.' : count($ids) . ' schedules deleted.'];
        header('Location: schedules.php');
        exit;
    }
}

$single = count($schedules) === 1;
$confirm = [
    'title'   => $single ? 'Delete Schedule' : 'Delete ' . count($schedules) . ' Schedules',
    'crumb'   => 'Delete Schedule',
    'message' => ($single
                    ? 'You are about to permanently delete the schedule "' . $schedules[0]['name'] . '".'
                    : 'You are about to permanently delete the ' . count($schedules) . ' schedules below.')
                 . ($assigned ? " $assigned employee(s) assigned to " . ($single ? 'it' : 'them') . ' will be left without a schedule.' : ''),
    'items'   => $single ? [] : array_map(fn($s) => $s['name'], $schedules),
    'confirm_msg' => $single ? 'Are you sure you want to permanently delete the schedule "' . $schedules[0]['name'] . '"?' : 'Are you sure you want to permanently delete these ' . count($schedules) . ' schedules?',
    'hidden'  => ['ids' => $ids],
    'button'  => $single ? 'Delete Schedule' : 'Delete ' . count($schedules) . ' Schedules',
    'back'    => 'schedules.php',
    'error'   => $error,
];
include 'includes/admin_confirm_page.php';
