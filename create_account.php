<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
redirect_if_logged_in();

// Sign up is only for first-time setup. Once an admin exists, it's closed.
if ((int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE role = 'admin'")->fetchColumn() > 0) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $repeat   = $_POST['repeat_password'] ?? '';

    if ($username === '' || $email === '' || $password === '') {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($pwErr = bt_password_error($password, $username)) {
        $error = $pwErr;
    } elseif ($password !== $repeat) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM accounts WHERE role = 'admin' AND (username = ? OR email = ?) LIMIT 1");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $error = 'Username or email already in use.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO accounts (role, username, email, password_hash, display_name, admin_id, employee_pk)
                                   VALUES ('admin', ?, ?, ?, ?, NULL, NULL)");
            $stmt->execute([$username, $email, $hash, $username]);
            $success = 'Account created successfully. You may now sign in.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Admin Account | BioTrack</title>
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
            <h2><span class="icon-circle">&#128100;</span> Create Admin Account</h2>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <p style="color:#8a94a6;font-size:13px;margin-bottom:16px;">
                This page creates an <strong>Admin</strong> login only. Employee logins are created
                by an admin from the Add Employee page, with the Employee ID or Email as their username.
            </p>

            <form method="post" novalidate>
                <div class="form-group">
                    <label for="username">Username:</label>
                    <div class="input-wrap">
                        <input type="text" id="username" name="username" placeholder="Username" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email:</label>
                    <div class="input-wrap">
                        <input type="email" id="email" name="email" placeholder="Email" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password:</label>
                    <div class="input-wrap">
                        <input type="password" id="password" name="password" placeholder="Password" required>
                        <button type="button" class="toggle-pass" onclick="togglePass('password', this)">&#128065;</button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="repeat_password">Repeat Password:</label>
                    <div class="input-wrap">
                        <input type="password" id="repeat_password" name="repeat_password" placeholder="Repeat Password" required>
                        <button type="button" class="toggle-pass" onclick="togglePass('repeat_password', this)">&#128065;</button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Create Account</button>
            </form>

            <div class="auth-switch">
                Already have an account? <a href="login.php">Sign in</a>
            </div>
        </div>
    </div>

<script src="assets/js/auth.js?v=<?= @filemtime(__DIR__ . '/assets/js/auth.js') ?>"></script>
</body>
</html>
