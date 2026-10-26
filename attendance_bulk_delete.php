<?php
/**
 * Deletes several ticked rows from the Attendance Report at once.
 *
 * Each ticked item is either
 *   rec:<attendance.id>    a real punch record     -> deleted
 *   abs:<YYYY-MM-DD>       a missed day (absence)  -> "deleted" the same way attendance_edit.php
 *                                                      does it (stored in attendance_excused so it
 *                                                      stops counting; it can be restored later)
 * The first POST (from the report) shows the admin-password screen; the second POST
 * (with admin_password) does the work.
 */
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/auth_role.php';
require_once 'includes/attendance_summary.php';
require_once 'includes/admin_confirm.php';
require_admin();

$back = (string)($_POST['back'] ?? '');
// only ever go back to the report page (no redirecting to other sites)
if (!preg_match('/^attendance_report\.php(\?[^\r\n]*)?$/', $back)) $back = 'attendance_report.php';

function bulk_back(string $type, string $msg, string $back): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: ' . $back);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') bulk_back('error', 'Nothing was selected.', $back);

att_ensure_excused_table($pdo);

$empPk = (int)($_POST['employee'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$empPk]);
$emp = $stmt->fetch();
if (!$emp) bulk_back('error', 'Employee not found.', $back);

// ---------- Sort the ticked items into records / absences ----------
$recIds = [];
$absDates = [];
foreach ((array)($_POST['items'] ?? []) as $item) {
    $item = (string)$item;
    if (preg_match('/^rec:(\d+)$/', $item, $m)) {
        $recIds[] = (int)$m[1];
    } elseif (preg_match('/^abs:(\d{4})-(\d{2})-(\d{2})$/', $item, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        $absDates[] = "{$m[1]}-{$m[2]}-{$m[3]}";
    }
}
$recIds   = array_values(array_unique($recIds));
$absDates = array_values(array_unique($absDates));

// ---------- Keep only what is really deletable ----------
$records = [];
if ($recIds) {
    $in = implode(',', array_fill(0, count($recIds), '?'));
    $st = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND id IN ($in) ORDER BY log_date, shift_order");
    $st->execute(array_merge([$empPk], $recIds));
    $records = $st->fetchAll();
}

$absences = [];
$today = date('Y-m-d');
$hasPunch = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND log_date = ?");
$isExcused = $pdo->prepare("SELECT 1 FROM attendance_excused WHERE employee_id = ? AND log_date = ?");
foreach ($absDates as $d) {
    if ($d >= $today) continue;                                   // only past days
    $hasPunch->execute([$empPk, $d]);
    if ((int)$hasPunch->fetchColumn() > 0) continue;              // it has records now - not an absence
    $isExcused->execute([$empPk, $d]);
    if ($isExcused->fetchColumn()) continue;                      // already removed
    $absences[] = $d;
}
sort($absences);

if (!$records && !$absences) bulk_back('error', 'Nothing could be deleted - the selected items no longer exist.', $back);

$items = [];
foreach ($records as $r) {
    $items[] = date('D, M j, Y', strtotime($r['log_date'])) . ' - ' . ($r['shift_label'] ?: 'Shift ' . $r['shift_order'])
             . ' record (' . $r['status'] . ')';
}
foreach ($absences as $d) {
    $items[] = date('D, M j, Y', strtotime($d)) . ' - Absent day';
}

// ---------- Password step ----------
$error = '';
if (isset($_POST['admin_password'])) {
    $error = check_admin_password($_POST['admin_password']);
    if ($error === null) {
        $pdo->beginTransaction();
        try {
            if ($records) {
                $ids = array_map(fn($r) => (int)$r['id'], $records);
                $in  = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("DELETE FROM attendance WHERE employee_id = ? AND id IN ($in)")
                    ->execute(array_merge([$empPk], $ids));
            }
            $ins = $pdo->prepare("INSERT IGNORE INTO attendance_excused (employee_id, log_date, reason) VALUES (?, ?, NULL)");
            foreach ($absences as $d) $ins->execute([$empPk, $d]);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            bulk_back('error', 'Could not delete the selected items.', $back);
        }

        $parts = [];
        if ($records)  $parts[] = count($records)  . ' attendance record' . (count($records) === 1 ? '' : 's');
        if ($absences) $parts[] = count($absences) . ' absence' . (count($absences) === 1 ? '' : 's');
        bulk_back('success', 'Deleted ' . implode(' and ', $parts) . ' for ' . $emp['name'] . '.', $back);
    }
}

$total = count($records) + count($absences);
$confirm = [
    'title'   => 'Delete ' . $total . ' Selected Item' . ($total === 1 ? '' : 's'),
    'crumb'   => 'Delete Attendance',
    'message' => 'You are about to delete the following for ' . $emp['name'] . ' (ID ' . $emp['employee_id'] . '). '
               . 'If a day has no other record afterwards it will count as absent again. '
               . ($absences ? 'Deleted absences stop counting, and can be restored from the report.' : ''),
    'items'   => $items,
    'confirm_msg' => 'Are you sure you want to delete ' . $total . ' selected item' . ($total === 1 ? '' : 's') . ' for ' . $emp['name'] . '?',
    'hidden'  => [
        'employee' => $empPk,
        'back'     => $back,
        'items'    => array_merge(
            array_map(fn($r) => 'rec:' . (int)$r['id'], $records),
            array_map(fn($d) => 'abs:' . $d, $absences)
        ),
    ],
    'button'  => 'Delete ' . $total . ' Item' . ($total === 1 ? '' : 's'),
    'back'    => $back,
    'error'   => $error,
];
include 'includes/admin_confirm_page.php';
