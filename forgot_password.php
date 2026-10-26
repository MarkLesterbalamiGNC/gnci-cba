<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
redirect_if_logged_in();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // No email/reset system exists, so nothing is looked up or sent. The same neutral
    // message is always shown (never reveals whether an email is registered).
    $message = 'If that email exists in our system, a reset link has been sent.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Forgot Password | BioTrack</title>
<link rel="stylesheet" href="assets/css/base.css?v=<?= @filemtime(__DIR__ . '/assets/css/base.css') ?>">
<link rel="stylesheet" href="assets/css/auth.css?v=<?= @filemtime(__DIR__ . '/assets/css/auth.css') ?>">
<link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>
<body class="auth-page">
    <div class="auth-banner"><h1>BioTrack &ndash; A Fingerprint-Based Attendance</h1></div>
    <div class="auth-body">
        <div class="auth-card">
            <h2><span class="icon-circle">&#128231;</span> Reset Password</h2>
            <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <form method="post">
                <div class="form-group">
                    <label>Email</label>
                    <div class="input-wrap"><input type="email" name="email" placeholder="Email" required></div>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
            </form>
            <div class="auth-switch"><a href="login.php">Back to Sign in</a></div>
        </div>
    </div>
</body>
</html>
