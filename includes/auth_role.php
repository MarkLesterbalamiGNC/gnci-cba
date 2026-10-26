<?php
/**
 * Role-aware authentication helpers.
 *
 * Session variables:
 * - $_SESSION['role'] = 'admin' | 'employee'
 * - Admin auth:
 *      $_SESSION['admin_id']
 *      $_SESSION['admin_username']
 * - Employee auth:
 *      $_SESSION['employee_account_id'] (accounts.id)
 *      $_SESSION['employee_id'] (employees.id)
 *      $_SESSION['employee_name']
 */

require_once __DIR__ . '/security.php';
bt_security_boot();   // starts the session securely (safe to call more than once)

function require_login_role() {
    $role = $_SESSION['role'] ?? null;
    if (!$role) {
        header('Location: login.php');
        exit;
    }
}

function require_admin() {
    require_login_role();
    if (($_SESSION['role'] ?? null) !== 'admin') {
        header('Location: dashboard.php');
        exit;
    }
}

function require_employee() {
    require_login_role();
    if (($_SESSION['role'] ?? null) !== 'employee') {
        header('Location: dashboard.php');
        exit;
    }
}

function redirect_if_logged_in_any() {
    if (!empty($_SESSION['role'])) {
        header('Location: dashboard.php');
        exit;
    }
}

function get_current_role() {
    return $_SESSION['role'] ?? null;
}

function format_time_am_pm($timeStr) {
    if (!$timeStr) return null;
    // Accepts formats like '07:05:00' or '07:05'
    try {
        $dt = new DateTime($timeStr);
        return $dt->format('g:i A');
    } catch (Throwable $e) {
        return $timeStr;
    }
}

