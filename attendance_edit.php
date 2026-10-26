<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/auth_role.php';
require_once 'includes/attendance_summary.php';
require_once 'includes/schedule_functions.php';
require_once 'includes/admin_confirm.php';
require_admin();

$e = fn($v) => htmlspecialchars((string)$v);

// ---------- Inputs ----------
$empPk  = (int)($_GET['employee'] ?? $_POST['employee'] ?? 0);
$date   = (string)($_GET['date'] ?? $_POST['date'] ?? '');
$attId  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isExcusedView = !empty($_GET['excused']) || !empty($_POST['excused']);
$back   = (string)($_GET['back'] ?? $_POST['back'] ?? '');
// only ever go back to the report page (no redirecting to other sites)
if (!preg_match('/^attendance_report\.php(\?[^\r\n]*)?$/', $back)) $back = 'attendance_report.php';

function go_back_with(string $type, string $msg, string $back): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: ' . $back);
    exit;
}

att_ensure_excused_table($pdo);

$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$empPk]);
$emp = $stmt->fetch();
if (!$emp) go_back_with('error', 'Employee not found.', $back);

// ---------- Which screen is this? ----------
$row = null;
if ($attId) {
    $st = $pdo->prepare("SELECT * FROM attendance WHERE id = ? AND employee_id = ?");
    $st->execute([$attId, $empPk]);
    $row = $st->fetch();
    if (!$row) go_back_with('error', 'That attendance record no longer exists.', $back);
    $date = $row['log_date'];
    $mode = 'edit';
} else {
    $okDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
              && checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4));
    if (!$okDate) go_back_with('error', 'Invalid date.', $back);

    $st = $pdo->prepare("SELECT 1 FROM attendance_excused WHERE employee_id = ? AND log_date = ?");
    $st->execute([$empPk, $date]);
    $isExcused = (bool)$st->fetchColumn();

    if ($isExcusedView) {
        if (!$isExcused) go_back_with('error', 'That absence was not removed.', $back);
        $mode = 'excused';
    } else {
        $st = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND log_date = ?");
        $st->execute([$empPk, $date]);
        if ((int)$st->fetchColumn() > 0) go_back_with('error', 'That day already has attendance records - edit those instead.', $back);
        if ($isExcused) go_back_with('error', 'That absence was already removed.', $back);
        if ($date >= date('Y-m-d')) go_back_with('error', 'Only past days can be marked or removed.', $back);
        $mode = 'absent';
    }
}

// Shifts of the employee's schedule (for the "mark present" form)
$shifts = (!empty($emp['schedule_id'])) ? get_schedule_shifts((int)$emp['schedule_id']) : [];

// ---------- Form values ----------
$status  = $row['status'] ?? 'Present';
if (!in_array($status, ['Present', 'Late'], true)) $status = 'Present';
$timeIn  = !empty($row['time_in'])  ? substr($row['time_in'], 0, 5)  : '';
$timeOut = !empty($row['time_out']) ? substr($row['time_out'], 0, 5) : '';
$shiftId = $shifts ? (int)$shifts[0]['id'] : 0;
$reason  = '';
$error   = '';

$cleanTime = function ($t) {
    $t = trim((string)$t);
    if ($t === '') return '';
    return preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t) ? substr($t, 0, 5) : false;
};

// ---------- Save ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do      = $_POST['do'] ?? '';
    $status  = in_array($_POST['status'] ?? '', ['Present', 'Late'], true) ? $_POST['status'] : 'Present';
    $inRaw   = $cleanTime($_POST['time_in'] ?? '');
    $outRaw  = $cleanTime($_POST['time_out'] ?? '');
    $timeIn  = $inRaw === false ? '' : $inRaw;
    $timeOut = $outRaw === false ? '' : $outRaw;
    $shiftId = (int)($_POST['shift_id'] ?? 0);
    $reason  = trim($_POST['reason'] ?? '');

    $pw = check_admin_password($_POST['admin_password'] ?? '');
    if ($pw !== null) {
        $error = $pw;
    } elseif (in_array($do, ['mark', 'save'], true) && ($inRaw === false || $outRaw === false)) {
        $error = 'Please enter valid times.';
    } elseif (in_array($do, ['mark', 'save'], true) && $timeIn !== '' && $timeOut !== '' && $timeOut <= $timeIn) {
        $error = 'Time Out must be later than Time In.';
    } else {
        $tIn  = $timeIn  !== '' ? $timeIn  . ':00' : null;
        $tOut = $timeOut !== '' ? $timeOut . ':00' : null;

        if ($do === 'mark' && $mode === 'absent') {
            $shiftRow = null;
            foreach ($shifts as $sh) if ((int)$sh['id'] === $shiftId) $shiftRow = $sh;
            if (!$shiftRow && $shifts) $shiftRow = $shifts[0];

            $ins = $pdo->prepare(
                "INSERT INTO attendance (employee_id, schedule_shift_id, shift_order, shift_label, log_date, time_in, time_out, status, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual')"
            );
            $ins->execute([
                $empPk,
                $shiftRow ? (int)$shiftRow['id'] : null,
                $shiftRow ? (int)$shiftRow['shift_order'] : 1,
                $shiftRow ? $shiftRow['label'] : null,
                $date, $tIn, $tOut, $status,
            ]);
            go_back_with('success', $emp['name'] . ' was marked ' . $status . ' for ' . date('M j, Y', strtotime($date)) . '.', $back);
        }

        if ($do === 'excuse' && $mode === 'absent') {
            $pdo->prepare("INSERT IGNORE INTO attendance_excused (employee_id, log_date, reason) VALUES (?, ?, ?)")
                ->execute([$empPk, $date, $reason !== '' ? mb_substr($reason, 0, 255) : null]);
            go_back_with('success', 'The absence on ' . date('M j, Y', strtotime($date)) . ' was removed for ' . $emp['name'] . '.', $back);
        }

        if ($do === 'save' && $mode === 'edit') {
            $pdo->prepare("UPDATE attendance SET status = ?, time_in = ?, time_out = ?, source = 'manual' WHERE id = ? AND employee_id = ?")
                ->execute([$status, $tIn, $tOut, $attId, $empPk]);
            go_back_with('success', 'Attendance record updated.', $back);
        }

        if ($do === 'delete' && $mode === 'edit') {
            $pdo->prepare("DELETE FROM attendance WHERE id = ? AND employee_id = ?")->execute([$attId, $empPk]);
            go_back_with('success', 'Attendance record deleted. If no other record exists that day, it now counts as absent.', $back);
        }

        if ($do === 'unexcuse' && $mode === 'excused') {
            $pdo->prepare("DELETE FROM attendance_excused WHERE employee_id = ? AND log_date = ?")->execute([$empPk, $date]);
            go_back_with('success', 'The absence on ' . date('M j, Y', strtotime($date)) . ' was restored.', $back);
        }

        $error = 'Nothing was changed.';
    }
}

$titles = ['absent' => 'Absent Day', 'edit' => 'Edit Attendance Record', 'excused' => 'Removed Absence'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $e($titles[$mode]) ?> | BioTrack</title>
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
                <h1><?= $e($titles[$mode]) ?></h1>
                <div class="breadcrumb"><a href="dashboard.php">Home</a> &rsaquo; <a href="<?= $e($back) ?>">Attendance Report</a> &rsaquo; <?= $e($titles[$mode]) ?></div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= $e($error) ?></div>
            <?php endif; ?>

            <div class="panel" style="max-width:560px;">
                <p style="margin-top:0;line-height:1.6;">
                    <strong><?= $e($emp['name']) ?></strong> (ID <?= $e($emp['employee_id']) ?>)<br>
                    <?= $e(date('l, F j, Y', strtotime($date))) ?>
                </p>

                <?php if ($mode === 'absent'): ?>
                    <p style="color:#8a94a6;font-size:13px;">
                        No punch was recorded this day, so it counts as <strong>Absent</strong>. If the device
                        malfunctioned, mark the employee present/late. If the day shouldn't count against them at all,
                        delete the absence.
                    </p>
                <?php elseif ($mode === 'edit'): ?>
                    <p style="color:#8a94a6;font-size:13px;">
                        Shift: <strong><?= $e($row['shift_label'] ?: 'Shift ' . $row['shift_order']) ?></strong>.
                        Deleting the record turns that day back into an absence (if there is no other record).
                    </p>
                <?php else: ?>
                    <p style="color:#8a94a6;font-size:13px;">
                        This absence was removed and no longer counts. Restore it to count it as an absence again.
                    </p>
                <?php endif; ?>

                <form method="post" action="attendance_edit.php">
                    <input type="hidden" name="employee" value="<?= (int)$empPk ?>">
                    <input type="hidden" name="date" value="<?= $e($date) ?>">
                    <input type="hidden" name="id" value="<?= (int)$attId ?>">
                    <input type="hidden" name="excused" value="<?= $mode === 'excused' ? 1 : 0 ?>">
                    <input type="hidden" name="back" value="<?= $e($back) ?>">

                    <?php if ($mode === 'absent' || $mode === 'edit'): ?>
                        <?php if ($mode === 'absent' && $shifts): ?>
                        <div class="field" style="margin-bottom:12px;">
                            <label>Shift</label>
                            <select name="shift_id">
                                <?php foreach ($shifts as $sh): ?>
                                    <option value="<?= (int)$sh['id'] ?>" <?= $shiftId === (int)$sh['id'] ? 'selected' : '' ?>>
                                        <?= $e($sh['label']) ?> (<?= $e(format_time_am_pm($sh['time_start'])) ?> &ndash; <?= $e(format_time_am_pm($sh['time_end'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="field" style="margin-bottom:12px;">
                            <label>Status</label>
                            <select name="status">
                                <option value="Present" <?= $status === 'Present' ? 'selected' : '' ?>>Present</option>
                                <option value="Late" <?= $status === 'Late' ? 'selected' : '' ?>>Late</option>
                            </select>
                        </div>
                        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
                            <div class="field" style="flex:1;min-width:140px;">
                                <label>Time In <span style="color:#8a94a6;font-weight:400;">(optional)</span></label>
                                <input type="time" name="time_in" value="<?= $e($timeIn) ?>">
                            </div>
                            <div class="field" style="flex:1;min-width:140px;">
                                <label>Time Out <span style="color:#8a94a6;font-weight:400;">(optional)</span></label>
                                <input type="time" name="time_out" value="<?= $e($timeOut) ?>">
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($mode === 'absent'): ?>
                        <div class="field" style="margin-bottom:12px;">
                            <label>Reason for deleting the absence <span style="color:#8a94a6;font-weight:400;">(optional)</span></label>
                            <input type="text" name="reason" maxlength="255" value="<?= $e($reason) ?>" placeholder="e.g. Device malfunction, school closed">
                        </div>
                    <?php endif; ?>

                    <div class="field">
                        <label>Admin Password *</label>
                        <input type="password" name="admin_password" placeholder="Your admin password" autocomplete="current-password" required autofocus>
                    </div>

                    <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
                        <?php if ($mode === 'absent'): ?>
                            <button type="submit" name="do" value="mark" class="btn btn-primary">Mark as Present / Late</button>
                            <button type="submit" name="do" value="excuse" class="btn btn-danger"
                                    data-confirm-title="Delete Absence?"
                                    data-confirm="Are you sure you want to delete this absence for <?= $e($emp['name']) ?> on <?= $e(date('M j, Y', strtotime($date))) ?>? It will no longer count as an absence (you can restore it later from the report)."
                                    data-confirm-btn="Yes, Delete">Delete Absence</button>
                        <?php elseif ($mode === 'edit'): ?>
                            <button type="submit" name="do" value="save" class="btn btn-primary">Save Changes</button>
                            <button type="submit" name="do" value="delete" class="btn btn-danger"
                                    data-confirm-title="Delete Record?"
                                    data-confirm="Are you sure you want to delete this attendance record for <?= $e($emp['name']) ?> on <?= $e(date('M j, Y', strtotime($date))) ?>? This cannot be undone."
                                    data-confirm-btn="Yes, Delete">Delete Record</button>
                        <?php else: ?>
                            <button type="submit" name="do" value="unexcuse" class="btn btn-primary">Restore Absence</button>
                        <?php endif; ?>
                        <a href="<?= $e($back) ?>" class="btn" style="background:#e3e7ee;color:#2c3444;">Cancel</a>
                    </div>
                </form>
            </div>

<?php include 'includes/footer.php'; ?>
