<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth_role.php';

// Secure session + security headers + CSRF check (see includes/security.php)
bt_security_boot();

/** Redirect to login if not authenticated (admin or employee) */
function require_login() {
    if (empty($_SESSION['role'])) {
        header('Location: login.php');
        exit;
    }
}

/** Redirect to dashboard if already authenticated (admin or employee) */
function redirect_if_logged_in() {
    if (!empty($_SESSION['role'])) {
        header('Location: dashboard.php');
        exit;
    }
}

