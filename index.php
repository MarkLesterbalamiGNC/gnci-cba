<?php
require_once 'includes/auth.php';   // starts the session
require_once 'includes/icons.php';
require_once 'config/db.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Opening the landing page signs out whoever is logged in (admin OR employee),
// so a new tab on index.php lets you sign in as a different role.
if (!empty($_SESSION['role'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cp = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $cp['path'], $cp['domain'], $cp['secure'], $cp['httponly']);
    }
    session_destroy();
}
$loggedIn = false;
$loginError = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
$openSignin = isset($_GET['signin']) || $loginError !== '';
$adminExists = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE role = 'admin'")->fetchColumn() > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BioTrack | Fingerprint-Based Attendance System for GNCI CBA</title>
<link rel="icon" type="image/png" href="assets/img/favicon.png">
<link rel="stylesheet" href="assets/css/base.css?v=<?= @filemtime(__DIR__ . '/assets/css/base.css') ?>">
<link rel="stylesheet" href="assets/css/landing.css?v=<?= @filemtime(__DIR__ . '/assets/css/landing.css') ?>">
</head>
<body class="landing">

<header class="lp-nav">
    <a class="lp-brand" href="index.php">
        <span class="lp-mark"><?= bt_icon('shield') ?></span>
        <span>BioTrack</span>
    </a>
    <div class="lp-links"></div>
    <div class="lp-cta">
        <?php if ($loggedIn): ?>
            <a class="lp-btn lp-btn-solid" href="dashboard.php">Go to Dashboard</a>
        <?php else: ?>
            <?php if (!$adminExists): ?>
            <a class="lp-btn lp-btn-ghost" href="create_account.php">Sign up</a>
            <?php endif; ?>
            <button type="button" class="lp-btn lp-btn-solid" data-open-signin>Sign in</button>
        <?php endif; ?>
        <img class="lp-nav-logo" src="assets/img/gnclogo.png" alt="Guagua National Colleges">
    </div>
</header>

<section class="lp-hero">
    <div class="lp-hero-text">
        <span class="lp-chip">GNCI &middot; College of Business &amp; Accountancy</span>
        <h1>Fingerprint attendance, <em>no more manual logging.</em></h1>
        <p>BioTrack is built to eliminate manual logging of attendance. Teachers and staff simply scan a fingerprint, and it automatically becomes clear Present, Late and Absent reports you can view and print in seconds.</p>
        <div class="lp-hero-btns">
            <?php if ($loggedIn): ?>
            <a class="lp-btn lp-btn-solid lp-btn-lg" href="dashboard.php">Open Dashboard</a>
            <?php else: ?>
            <button type="button" class="lp-btn lp-btn-solid lp-btn-lg" data-open-signin>Sign in to BioTrack</button>
            <?php endif; ?>
        </div>
        <ul class="lp-legend">
            <li><i class="d-green"></i> Present</li>
            <li><i class="d-orange"></i> Late</li>
            <li><i class="d-red"></i> Absent</li>
        </ul>
    </div>

    <div class="lp-hero-art" aria-hidden="true">
        <div class="lp-scan">
            <span class="ring r1"></span><span class="ring r2"></span><span class="ring r3"></span>
            <div class="lp-scan-core"><?= bt_icon('fingerprint') ?><span class="beam"></span></div>
        </div>
        <div class="lp-float f1"><b>Present</b><small>8:00 AM</small></div>
        <div class="lp-float f2 late"><b>Late</b><small>8:19 AM</small></div>
    </div>
</section>

<footer class="lp-foot">Copyright &copy; <?= date('Y') ?> BioTrack &ndash; A Fingerprint-Based Attendance System for GNCI CBA &nbsp;|&nbsp; All rights reserved</footer>

<!-- Sign-in popup -->
<div class="si-overlay<?= $openSignin ? ' open' : '' ?>" id="signinModal" role="dialog" aria-modal="true" aria-labelledby="siTitle">
    <div class="si-card">
        <button type="button" class="si-close" data-close-signin aria-label="Close">&times;</button>
        <div class="si-head">
            <span class="lp-mark lp-logo"><img src="assets/img/gnclogo.png" alt="GNCI"></span>
            <h2 id="siTitle">Sign in</h2>
        </div>

        <?php if ($loginError): ?>
            <div class="si-error"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate>
            <label for="si_user">Username / Email / Employee ID</label>
            <input type="text" id="si_user" name="username_or_email" placeholder="Username, Employee ID or Email" required autocomplete="username">

            <label for="si_pass">Password</label>
            <div class="si-pass">
                <input type="password" id="si_pass" name="password" placeholder="Password" required autocomplete="current-password">
                <button type="button" class="si-eye" id="siEye" aria-label="Show password">&#128065;</button>
            </div>

            <a class="si-forgot" href="employee_forgot_password.php">Forgot password?</a>
            <button type="submit" class="lp-btn lp-btn-solid si-submit">Sign In</button>
        </form>

        <?php if (!$adminExists): ?>
        <div class="si-switch">No admin yet? <a href="create_account.php">Create the admin account</a></div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('signinModal');
    var user  = document.getElementById('si_user');
    var pass  = document.getElementById('si_pass');
    function open()  { modal.classList.add('open');  document.body.classList.add('si-lock'); setTimeout(function () { user.focus(); }, 80); }
    function close() { modal.classList.remove('open'); document.body.classList.remove('si-lock'); }
    document.querySelectorAll('[data-open-signin]').forEach(function (b) { b.addEventListener('click', open); });
    document.querySelectorAll('[data-close-signin]').forEach(function (b) { b.addEventListener('click', close); });
    modal.addEventListener('mousedown', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    document.getElementById('siEye').addEventListener('click', function () {
        var show = pass.type === 'password';
        pass.type = show ? 'text' : 'password';
        this.innerHTML = show ? '&#128064;' : '&#128065;';
    });
    if (modal.classList.contains('open')) open();
})();
</script>

</body>
</html>
