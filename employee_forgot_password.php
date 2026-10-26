<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

/*
 * SECURITY NOTE
 * The old version let ANYONE reset an employee's password by typing that
 * employee's email address - no proof of identity - which would let a stranger
 * take over any employee account once the site is public.
 *
 * Now:
 *   - Signed-in employee  -> may change their OWN password (current password required).
 *   - Not signed in       -> self-service reset is closed; the administrator resets it
 *                            from  Employee > Edit  (New Password field).
 */
if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: dashboard.php');
    exit;
}

$accId    = (int)($_SESSION['employee_account_id'] ?? 0);
$signedIn = (($_SESSION['role'] ?? '') === 'employee' && $accId > 0);
$forced   = !empty($_SESSION['force_pw_change']);

$error = '';
$success = '';
$step = $signedIn ? 'reset' : 'closed';

if ($signedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $current         = $_POST['current_password'] ?? '';
    $new_password    = $_POST['new_password'] ?? '';
    $repeat_password = $_POST['repeat_password'] ?? '';

    $stmt = $pdo->prepare("SELECT username, password_hash FROM accounts WHERE id = ? AND role = 'employee'");
    $stmt->execute([$accId]);
    $acc = $stmt->fetch();

    $subject = 'pwchange|' . $accId;
    if (!$acc) {
        $error = 'Your session expired. Please sign in again.';
    } elseif (bt_rl_count($pdo, 'pwchange', $subject, 900) >= 5) {
        $error = 'Too many incorrect attempts. Please try again in 15 minutes.';
    } elseif (!password_verify($current, $acc['password_hash'])) {
        bt_rl_hit($pdo, 'pwchange', $subject);
        $error = 'Your current password is incorrect.';
    } elseif ($pwErr = bt_password_error($new_password, $acc['username'])) {
        $error = $pwErr;
    } elseif ($new_password !== $repeat_password) {
        $error = 'Passwords do not match.';
    } elseif (hash_equals($current, $new_password)) {
        $error = 'Your new password must be different from the current one.';
    } else {
        $upd = $pdo->prepare("UPDATE accounts SET password_hash = ? WHERE id = ? AND role = 'employee'");
        $upd->execute([password_hash($new_password, PASSWORD_DEFAULT), $accId]);
        bt_rl_clear($pdo, 'pwchange', $subject);
        unset($_SESSION['force_pw_change']);
        session_regenerate_id(true);
        $success = 'Your password has been changed.';
        $step = 'done';
        $forced = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Password | BioTrack</title>
<link rel="stylesheet" href="assets/css/base.css?v=<?= @filemtime(__DIR__ . '/assets/css/base.css') ?>">
<link rel="stylesheet" href="assets/css/auth.css?v=<?= @filemtime(__DIR__ . '/assets/css/auth.css') ?>">
<link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>
<body class="auth-page">

    <div class="auth-banner">
        <h1>BioTrack &ndash; A Fingerprint-Based Attendance</h1>
    </div>

    <div class="auth-body">
        <div class="auth-card">
            <h2><span class="icon-circle">&#128274;</span> <?= $step === 'closed' ? 'Forgot Password?' : 'Change Password' ?></h2>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if ($step === 'closed'): ?>
                <p style="color:#5b6577;font-size:14px;line-height:1.5;margin-bottom:16px;">
                    For your account's safety, passwords can't be reset online without proof that it's really you.
                    Please ask your <strong>administrator</strong> to set a new password for you
                    (Employee &rsaquo; Edit &rsaquo; New Password). You can change it again yourself after you sign in.
                </p>
                <a class="btn btn-primary btn-block" href="login.php">Back to Sign in</a>

            <?php elseif ($step === 'reset'): ?>
                <?php if ($forced): ?>
                    <div class="alert alert-error">Your password is a temporary or easy-to-guess one. Please choose a new password to continue.</div>
                <?php endif; ?>
                <p style="color:#8a94a6;font-size:13px;margin-bottom:16px;">
                    Enter your current password, then choose a new one (at least 8 characters).
                </p>
                <form method="post" novalidate>
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <div class="input-wrap">
                            <input type="password" id="current_password" name="current_password" placeholder="Current password" autocomplete="current-password" required autofocus>
                            <button type="button" class="toggle-pass" onclick="togglePass('current_password', this)">&#128065;</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <div class="input-wrap">
                            <input type="password" id="new_password" name="new_password" placeholder="New password" autocomplete="new-password" required>
                            <button type="button" class="toggle-pass" onclick="togglePass('new_password', this)">&#128065;</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="repeat_password">Repeat New Password</label>
                        <div class="input-wrap">
                            <input type="password" id="repeat_password" name="repeat_password" placeholder="Repeat new password" autocomplete="new-password" required>
                            <button type="button" class="toggle-pass" onclick="togglePass('repeat_password', this)">&#128065;</button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Change Password</button>
                </form>

            <?php else: ?>
                <a class="btn btn-primary btn-block" href="dashboard.php">Continue to Dashboard</a>
            <?php endif; ?>

            <?php if ($step !== 'done'): ?>
            <div class="auth-switch">
                <?= $signedIn ? '<a href="dashboard.php">Back to Dashboard</a>' : '<a href="login.php">Back to Sign in</a>' ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

<script src="assets/js/auth.js?v=<?= @filemtime(__DIR__ . '/assets/js/auth.js') ?>"></script>
</body>
</html>
