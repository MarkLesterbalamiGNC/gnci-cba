<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/schedule_functions.php';
require_once 'includes/admin_confirm.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$schedule = get_schedule($id);
if (!$schedule) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Schedule not found.'];
    header('Location: schedules.php');
    exit;
}

$error = '';
$name = $schedule['name'];
$description = $schedule['description'] ?? '';
$status = (int)$schedule['status'];

$existingShifts = get_schedule_shifts($id);
$shifts = $existingShifts ?: [['label' => 'Morning', 'time_start' => '', 'time_end' => '', 'grace_minutes' => 10]];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status      = (int)($_POST['status'] ?? 1) === 1 ? 1 : 0;

    $labels = $_POST['shift_label'] ?? [];
    $starts = $_POST['shift_start'] ?? [];
    $ends   = $_POST['shift_end'] ?? [];
    $graces = $_POST['shift_grace'] ?? [];

    $shifts = [];
    for ($i = 0; $i < count($labels); $i++) {
        $label = trim($labels[$i] ?? '');
        $start = trim($starts[$i] ?? '');
        $end   = trim($ends[$i] ?? '');
        $grace = (int)($graces[$i] ?? 0);
        if ($label === '' && $start === '' && $end === '') continue;
        $shifts[] = ['label' => $label ?: 'Shift', 'time_start' => $start, 'time_end' => $end, 'grace_minutes' => max(0, $grace)];
    }

    if ($name === '') {
        $error = 'Schedule name is required.';
    } elseif ($pwError = check_admin_password($_POST['admin_password'] ?? '')) {
        $error = $pwError;
    } elseif (!$shifts) {
        $error = 'Add at least one shift (e.g. Morning 7:00 AM - 10:00 AM).';
    } else {
        usort($shifts, fn($a, $b) => strcmp($a['time_start'], $b['time_start']));

        foreach ($shifts as $i => $s) {
            if ($s['time_start'] === '' || $s['time_end'] === '') {
                $error = 'Every shift needs both a start and an end time.';
                break;
            }
            if ($s['time_end'] <= $s['time_start']) {
                $error = "The \"{$s['label']}\" shift's end time must be after its start time.";
                break;
            }
            if ($i > 0 && $s['time_start'] < $shifts[$i - 1]['time_end']) {
                $error = "The \"{$s['label']}\" shift overlaps with the \"{$shifts[$i - 1]['label']}\" shift.";
                break;
            }
        }
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE schedules SET name = ?, description = ?, status = ? WHERE id = ?");
            $stmt->execute([$name, $description ?: null, $status, $id]);

            // Simplest reliable approach: replace all shifts for this schedule.
            // Existing attendance rows keep their schedule_shift_id snapshot
            // (attendance.schedule_shift_id is ON DELETE SET NULL), so past
            // records aren't lost - only future punches use the new shifts.
            $pdo->prepare("DELETE FROM schedule_shifts WHERE schedule_id = ?")->execute([$id]);

            $ins = $pdo->prepare(
                "INSERT INTO schedule_shifts (schedule_id, shift_order, label, time_start, time_end, grace_minutes)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($shifts as $i => $s) {
                $ins->execute([$id, $i + 1, $s['label'], $s['time_start'] . ':00', $s['time_end'] . ':00', $s['grace_minutes']]);
            }

            $pdo->commit();

            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Schedule updated.'];
            header('Location: schedules.php');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = str_contains($e->getMessage(), 'Duplicate')
                ? 'A schedule with that name already exists.'
                : 'Could not update schedule.';
        }
    }
}

// Normalize time values (strip seconds) for the <input type="time"> fields
foreach ($shifts as &$s) {
    $s['time_start'] = substr($s['time_start'], 0, 5);
    $s['time_end']   = substr($s['time_end'], 0, 5);
}
unset($s);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Schedule | BioTrack</title>
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
                <h1>Edit Schedule</h1>
                <div class="breadcrumb"><a href="dashboard.php">Home</a> &rsaquo; <a href="schedules.php">Schedules</a> &rsaquo; Edit</div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="panel">
                <form method="post" novalidate>
                    <div class="form-grid">
                        <div class="field">
                            <label>Schedule Name *</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($name) ?>" required>
                        </div>
                        <div class="field">
                            <label>Description</label>
                            <input type="text" name="description" value="<?= htmlspecialchars($description) ?>" placeholder="Optional notes">
                        </div>
                        <div class="field">
                            <label>Status</label>
                            <select name="status_select" onchange="document.getElementById('status_input').value=this.value;">
                                <option value="1" <?= $status ? 'selected' : '' ?>>Active</option>
                                <option value="0" <?= !$status ? 'selected' : '' ?>>Inactive</option>
                            </select>
                            <input type="hidden" id="status_input" name="status" value="<?= $status ? 1 : 0 ?>">
                        </div>

                        <div class="full">
                            <hr style="border:none;border-top:1px solid #e3e7ee;margin:8px 0 20px;">
                            <h3 style="margin:0 0 4px;font-size:15px;color:#2c3444;">Shifts</h3>
                            <p style="color:#8a94a6;font-size:13px;margin-top:0;margin-bottom:16px;">
                                Add one row per shift in the day (e.g. Morning 7:00 AM&ndash;10:00 AM, then Afternoon 4:00 PM&ndash;6:00 PM).
                                The biometric system will match each clock-in/clock-out punch to the closest shift automatically.
                                "Grace" is how many minutes late an employee can clock in before that shift is marked Late.
                            </p>

                            <div id="shift-rows">
                                <?php foreach ($shifts as $s): ?>
                                <div class="shift-row">
                                    <input type="text" name="shift_label[]" placeholder="Label (e.g. Morning)" value="<?= htmlspecialchars($s['label']) ?>">
                                    <input type="time" name="shift_start[]" value="<?= htmlspecialchars($s['time_start']) ?>" required>
                                    <span>to</span>
                                    <input type="time" name="shift_end[]" value="<?= htmlspecialchars($s['time_end']) ?>" required>
                                    <input type="number" name="shift_grace[]" min="0" max="180" value="<?= (int)$s['grace_minutes'] ?>" title="Grace period (minutes)">
                                    <span style="color:#8a94a6;font-size:12px;">min grace</span>
                                    <button type="button" class="tool-btn tool-delete remove-shift" title="Remove shift">&times;</button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" id="add-shift" class="btn" style="background:#e3e7ee;color:#2c3444;margin-top:8px;">+ Add Shift</button>
                        </div>
                    </div>

                    <div class="field" style="max-width:360px;margin-top:20px;">
                        <label>Admin Password * <span style="color:#8a94a6;font-weight:400;">(required to save changes)</span></label>
                        <input type="password" name="admin_password" placeholder="Your admin password" autocomplete="current-password" required>
                    </div>

                    <div style="margin-top:20px;display:flex;gap:10px;">
                        <button type="submit" class="btn btn-primary">Update Schedule</button>
                        <a href="schedules.php" class="btn" style="background:#e3e7ee;color:#2c3444;">Cancel</a>
                    </div>
                </form>
            </div>

<?php include 'includes/footer.php'; ?>
<template id="shift-row-template">
    <div class="shift-row">
        <input type="text" name="shift_label[]" placeholder="Label (e.g. Afternoon)">
        <input type="time" name="shift_start[]" required>
        <span>to</span>
        <input type="time" name="shift_end[]" required>
        <input type="number" name="shift_grace[]" min="0" max="180" value="10" title="Grace period (minutes)">
        <span style="color:#8a94a6;font-size:12px;">min grace</span>
        <button type="button" class="tool-btn tool-delete remove-shift" title="Remove shift">&times;</button>
    </div>
</template>
<style>
.shift-row { display:flex; align-items:center; gap:8px; margin-bottom:10px; flex-wrap:wrap; }
.shift-row input[type=text] { width:160px; }
.shift-row input[type=time] { width:130px; }
.shift-row input[type=number] { width:70px; }
</style>
<script>
document.getElementById('add-shift').addEventListener('click', function() {
    var tpl = document.getElementById('shift-row-template');
    var clone = tpl.content.cloneNode(true);
    document.getElementById('shift-rows').appendChild(clone);
});
document.getElementById('shift-rows').addEventListener('click', function(e) {
    if (e.target.classList.contains('remove-shift')) {
        var rows = document.querySelectorAll('.shift-row');
        if (rows.length > 1) {
            e.target.closest('.shift-row').remove();
        } else {
            alert('A schedule needs at least one shift.');
        }
    }
});
</script>
