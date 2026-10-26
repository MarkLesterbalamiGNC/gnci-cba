<?php require_once __DIR__ . '/icons.php'; ?>
<div class="topbar">
    <div class="title">
        <span class="hamburger" role="button" aria-label="Toggle menu"><?= bt_icon('menu') ?></span>
        <span class="brand-mark"><?= bt_icon('shield') ?></span>
        <span class="brand-name">BioTrack</span>
        <span class="brand-sub">Fingerprint Attendance System</span>
    </div>
    <div class="admin-chip">
        <div class="avatar"><?= bt_avatar() ?></div>
        <?php $role = $_SESSION['role'] ?? 'admin'; ?>
        <span><?= htmlspecialchars($role === 'employee' ? ($_SESSION['employee_name'] ?? 'EMPLOYEE') : ($_SESSION['admin_username'] ?? 'ADMIN')) ?></span>
        <div class="dropdown">
            <?php if ($role === 'admin'): ?>
                <a href="account_settings.php">Account Settings</a>
            <?php else: ?>
                <a href="employee_profile.php">Edit Profile</a>
            <?php endif; ?>
            <a href="logout.php">Logout</a>
        </div>
    </div>
</div>
