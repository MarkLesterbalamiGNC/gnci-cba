<?php
/**
 * Re-checks the logged-in admin's password before a destructive action
 * (deleting an employee or a schedule, permanently purging a record).
 * Returns null when the password is correct, otherwise an error message.
 * Locks out for 5 minutes after 5 wrong tries in a row.
 */
require_once __DIR__ . '/../config/db.php';

// When the confirmation is shown as a floating pop-up, the page is fetched in the
// background (request header X-BT-Modal). A redirect would be followed silently and
// swallow the flash message, so it is reported in X-BT-Redirect instead and the
// script does the navigation itself.
if (($_SERVER['HTTP_X_BT_MODAL'] ?? '') === '1') {
    header_register_callback(function () {
        foreach (headers_list() as $h) {
            if (stripos($h, 'Location:') === 0) {
                $loc = trim(substr($h, 9));
                header_remove('Location');
                http_response_code(200);
                header('X-BT-Redirect: ' . $loc);
                return;
            }
        }
    });
}

function check_admin_password(string $password): ?string {
    global $pdo;

    if (($_SESSION['pw_lock_until'] ?? 0) > time()) {
        return 'Too many incorrect attempts. Please try again in a few minutes.';
    }

    $stmt = $pdo->prepare("SELECT password_hash FROM accounts WHERE id = ? AND role = 'admin'");
    $stmt->execute([(int)($_SESSION['admin_id'] ?? 0)]);
    $hash = $stmt->fetchColumn();

    if ($password !== '' && $hash && password_verify($password, $hash)) {
        unset($_SESSION['pw_fails'], $_SESSION['pw_lock_until']);
        return null;
    }

    $_SESSION['pw_fails'] = ($_SESSION['pw_fails'] ?? 0) + 1;
    if ($_SESSION['pw_fails'] >= 5) {
        $_SESSION['pw_lock_until'] = time() + 300;
        $_SESSION['pw_fails'] = 0;
    }
    return 'Incorrect admin password.';
}
