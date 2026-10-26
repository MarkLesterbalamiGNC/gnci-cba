<?php
$current = basename($_SERVER['PHP_SELF']);
function nav_active($file, $current) {
    return $file === $current ? 'active' : '';
}
$role = $_SESSION['role'] ?? 'admin';
require_once __DIR__ . '/icons.php';
?>
<aside class="sidebar">
    <div class="sidebar-brand">Attendance System</div>

    <div class="sidebar-profile">
        <div class="avatar"><?= bt_avatar() ?></div>
        <div>
            <div class="name"><?= htmlspecialchars($role === 'employee' ? ($_SESSION['employee_name'] ?? 'Employee') : ($_SESSION['admin_username'] ?? 'Admin')) ?></div>
            <div class="status">Online</div>
        </div>
    </div>

    <?php if ($role === 'admin'): ?>
        <div class="sidebar-section-title">MANAGE</div>
        <ul class="sidebar-menu">
            <li><a class="<?= nav_active('dashboard.php', $current) ?>" href="dashboard.php"><span class="ico"><?= bt_icon('dashboard') ?></span> Dashboard</a></li>
            <li><a class="<?= nav_active('employee.php', $current) ?>" href="employee.php"><span class="ico"><?= bt_icon('users') ?></span> Employee</a></li>
            <li><a class="<?= nav_active('schedules.php', $current) ?>" href="schedules.php"><span class="ico"><?= bt_icon('calendar') ?></span> Schedules</a></li>
        </ul>

        <div class="sidebar-section-title">REPORTS</div>
        <ul class="sidebar-menu">
            <li><a class="<?= nav_active('attendance_scan.php', $current) ?>" href="attendance_scan.php"><span class="ico"><?= bt_icon('fingerprint') ?></span> Scan Attendance</a></li>
            <li><a class="<?= nav_active('attendance_list.php', $current) ?>" href="attendance_list.php"><span class="ico"><?= bt_icon('list') ?></span> Attendance List</a></li>
            <li><a class="<?= nav_active('attendance_report.php', $current) ?>" href="attendance_report.php"><span class="ico"><?= bt_icon('report') ?></span> Attendance Report</a></li>
            <li><a class="<?= nav_active('daily_report.php', $current) ?>" href="daily_report.php"><span class="ico"><?= bt_icon('report') ?></span> Daily Report (Dean)</a></li>
        </ul>

        <div class="sidebar-section-title">ARCHIVE</div>
        <ul class="sidebar-menu">
            <li><a class="<?= nav_active('employee_trash.php', $current) ?>" href="employee_trash.php"><span class="ico"><?= bt_icon('trash') ?></span> Recently Deleted</a></li>
            <li><a class="<?= nav_active('past_employee.php', $current) ?>" href="past_employee.php"><span class="ico"><?= bt_icon('archive') ?></span> Past Employee</a></li>
        </ul>
    <?php else: ?>
        <div class="sidebar-section-title">MENU</div>
        <ul class="sidebar-menu">
            <li><a class="<?= nav_active('dashboard.php', $current) ?>" href="dashboard.php"><span class="ico"><?= bt_icon('user') ?></span> My Profile</a></li>
        </ul>

        <div class="sidebar-section-title">REPORTS</div>
        <ul class="sidebar-menu">
            <li><a class="<?= nav_active('employee_report.php', $current) ?>" href="employee_report.php"><span class="ico"><?= bt_icon('report') ?></span> My Report</a></li>
        </ul>
    <?php endif; ?>

</aside>
