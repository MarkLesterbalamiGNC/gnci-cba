<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/schedule_functions.php';
require_admin();

$schedules = $pdo->query(
    "SELECT s.*,
            (SELECT COUNT(*) FROM schedule_shifts ss WHERE ss.schedule_id = s.id) AS shift_count,
            (SELECT COUNT(*) FROM employees e WHERE e.schedule_id = s.id AND e.deleted_at IS NULL) AS employee_count
     FROM schedules s
     ORDER BY s.name"
)->fetchAll();

// Preload shifts per schedule for the inline preview
$shiftsBySchedule = [];
foreach ($pdo->query("SELECT * FROM schedule_shifts ORDER BY schedule_id, shift_order")->fetchAll() as $s) {
    $shiftsBySchedule[$s['schedule_id']][] = $s;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Schedules | BioTrack</title>
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
                <h1>Schedules</h1>
                <div class="breadcrumb"><a href="dashboard.php">Home</a> &rsaquo; Schedules</div>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
            <?php endif; ?>

            <div class="panel">
                <div class="panel-head">
                    <h2></h2>
                    <a href="schedule_add.php" class="btn btn-primary">+ Add New Schedule</a>
                </div>

                <form method="post" action="schedule_delete.php" data-bulk>
                <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="col-check"><input type="checkbox" class="bulk-all" title="Select all" aria-label="Select all"></th>
                            <th>#</th>
                            <th>Name</th>
                            <th>Shifts</th>
                            <th>Employees Assigned</th>
                            <th>Status</th>
                            <th><span style="display:inline-flex;align-items:center;gap:8px;">Tools <button type="submit" data-admin-confirm class="btn btn-danger bulk-btn" disabled title="Delete selected" style="padding:6px 12px;font-size:12px;">&times; Delete Selected (<span class="bulk-count">0</span>)</button></span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$schedules): ?>
                        <tr><td colspan="7" style="text-align:center;color:#8a94a6;padding:24px;">
                            No schedules yet. Create one so new employees have a shift pattern the biometric system can check their punches against.
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($schedules as $i => $s): ?>
                        <tr>
                            <td class="col-check"><input type="checkbox" class="bulk-check" name="ids[]" value="<?= (int)$s['id'] ?>" aria-label="Select <?= htmlspecialchars($s['name'], ENT_QUOTES) ?>"></td>
                            <td><?= $i+1 ?></td>
                            <td>
                                <div style="font-weight:600;"><?= htmlspecialchars($s['name']) ?></div>
                                <?php if ($s['description']): ?>
                                    <div style="color:#8a94a6;font-size:12px;"><?= htmlspecialchars($s['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php foreach (($shiftsBySchedule[$s['id']] ?? []) as $sh): ?>
                                    <div style="white-space:nowrap;">
                                        <strong><?= htmlspecialchars($sh['label']) ?>:</strong>
                                        <?= htmlspecialchars(format_time_am_pm($sh['time_start'])) ?> &ndash;
                                        <?= htmlspecialchars(format_time_am_pm($sh['time_end'])) ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($shiftsBySchedule[$s['id']])): ?>
                                    <span style="color:#8a94a6;">No shifts defined</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$s['employee_count'] ?></td>
                            <td>
                                <?php if ($s['status']): ?>
                                    <span class="pill pill-green">Active</span>
                                <?php else: ?>
                                    <span class="pill pill-red">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="tool-btn tool-edit" title="Edit" href="schedule_edit.php?id=<?= $s['id'] ?>">&#9998;</a>
                                <!-- Goes straight to schedule_delete.php, which asks for the admin password -->
                                <a class="tool-btn tool-delete" data-admin-confirm title="Delete" href="schedule_delete.php?id=<?= (int)$s['id'] ?>">&times;</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                </form>
            </div>

<?php include 'includes/footer.php'; ?>
