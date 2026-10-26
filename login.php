<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

// Never cache this page, and never skip the form just because someone is
// already logged in. Opening the login page signs out the current user
// (admin or employee) so a different role can sign in.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (!empty($_SESSION['role'])) {
    $_SESSION = [];
    session_regenerate_id(true);
}

$error = '';

// The sign-in form now lives in a popup on the landing page (index.php).
// Visiting login.php directly just opens that popup.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?signin=1');
    exit;
}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameOrEmail = trim($_POST['username_or_email'] ?? '');
    $password = $_POST['password'] ?? '';

    // ---- Brute-force protection: too many wrong tries from one visitor / on one account -> wait ----
    $ip     = bt_client_ip();
    $ident  = strtolower(mb_substr($usernameOrEmail, 0, 100));
    $pair   = $ip . '|' . $ident;
    $window = 15 * 60;
    $locked = $ident !== '' && (
        bt_rl_count($pdo, 'login_pair', $pair,  $window) >= 5  ||   // same person, same account
        bt_rl_count($pdo, 'login_ip',   $ip,    $window) >= 20 ||   // one computer trying many accounts
        bt_rl_count($pdo, 'login_id',   $ident, $window) >= 15      // many computers, one account
    );

    if ($usernameOrEmail === '' || $password === '' || strlen($password) > 200) {
        $error = 'Please enter your username/email and password.';
    } elseif ($locked) {
        $error = 'Too many failed sign-in attempts. Please wait 15 minutes and try again.';
    } else {
        // One login for everyone: look the account up across BOTH roles
        // (admin first), and the account's own role decides where they land.
        $stmt = $pdo->prepare("SELECT * FROM accounts WHERE (username = ? OR email = ?) ORDER BY (role = 'admin') DESC, id ASC");
        $stmt->execute([$usernameOrEmail, $usernameOrEmail]);
        $matches = $stmt->fetchAll();

        $acc = false;
        $checked = false;
        foreach ($matches as $cand) {
            // Employees in "Recently Deleted" (or whose record is gone) can't sign in
            if ($cand['role'] === 'employee') {
                $chk = $pdo->prepare("SELECT 1 FROM employees WHERE id = ? AND deleted_at IS NULL");
                $chk->execute([(int)$cand['employee_pk']]);
                if (!$chk->fetchColumn()) continue;
            }
            $checked = true;
            if (password_verify($password, $cand['password_hash'])) {
                $acc = $cand;
                break;
            }
        }
        // Same response time whether or not the account exists (stops "does this user exist?" probing)
        if (!$checked) password_hash($password, PASSWORD_DEFAULT);

        if ($acc) {
            bt_rl_clear($pdo, 'login_pair', $pair);

            // Quietly upgrade old password hashes to the current algorithm / cost
            if (password_needs_rehash($acc['password_hash'], PASSWORD_DEFAULT)) {
                $pdo->prepare("UPDATE accounts SET password_hash = ? WHERE id = ?")
                    ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$acc['id']]);
            }

            $roleKey = $acc['role'];
            session_regenerate_id(true);      // new session ID on every sign-in (stops session fixation)
            $_SESSION['role'] = $roleKey;
            $_SESSION['bt_start'] = $_SESSION['bt_last'] = time();
            $_SESSION['bt_ua'] = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

            if ($roleKey === 'admin') {
                // accounts.id is the canonical identifier for the admin's own
                // account row (accounts.admin_id is a legacy column).
                $_SESSION['admin_id'] = (int)$acc['id'];
                $_SESSION['admin_username'] = $acc['username'];
            } else {
                $_SESSION['employee_account_id'] = (int)$acc['id'];
                $_SESSION['employee_id'] = (int)$acc['employee_pk'];
                $_SESSION['employee_name'] = $acc['display_name'];
            }

            // A password that is just the username / Employee ID (e.g. imported employees) must be
            // replaced before the account can be used. The shipped SuperAdmin password (from the SQL
            // file) is allowed to stay, so signing in as SuperAdmin no longer forces a change.
            if (hash_equals(strtolower((string)$acc['username']), strtolower($password))) {
                $_SESSION['force_pw_change'] = 1;
            }

            header('Location: dashboard.php');
            exit;
        } else {
            bt_rl_hit($pdo, 'login_pair', $pair);
            bt_rl_hit($pdo, 'login_ip',   $ip);
            bt_rl_hit($pdo, 'login_id',   $ident);
            usleep(random_int(150000, 350000));   // small delay slows automated guessing
            $error = 'Invalid username/email or password.';
        }
    }
}

// Failed sign-in: go back to the landing page and reopen the popup with the message.
$_SESSION['login_error'] = $error;
header('Location: index.php?signin=1');
exit;
