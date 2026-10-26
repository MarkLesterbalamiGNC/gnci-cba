<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_login();

$error = '';
$success = '';

// Load current admin info.
// NOTE: admin accounts live in the `accounts` table (role = 'admin'),
// identified by accounts.id (stored in $_SESSION['admin_id'] at login).
// The legacy `admins` table is not used by accounts created through
// create_account.php, which is why this used to always fail and log
// the admin back out - fixed by reading/writing `accounts` instead.
$stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = ? AND role = 'admin'");
$stmt->execute([$_SESSION['admin_id']]);
$admin = $stmt->fetch();

if (!$admin) {
    // Session points to an admin account that no longer exists
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

// Forced to change a default / easy-to-guess password before using anything else
$forcedChange = !empty($_SESSION['force_pw_change']);
if ($forcedChange && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $error = 'Your password is the default or an easy-to-guess one. Please set a new password below (fill in Current Password and New Password) before using the system.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = '';
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $remove_photo = isset($_POST['remove_photo']);

    $current_password  = $_POST['current_password'] ?? '';
    $new_password       = $_POST['new_password'] ?? '';
    $repeat_password     = $_POST['repeat_password'] ?? '';
    $wantsPasswordChange = $current_password !== '' || $new_password !== '' || $repeat_password !== '';

    if ($username === '' || $email === '') {
        $error = 'Username and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Check username/email aren't taken by a DIFFERENT admin account
        $dupStmt = $pdo->prepare("SELECT id FROM accounts WHERE role = 'admin' AND (username = ? OR email = ?) AND id != ?");
        $dupStmt->execute([$username, $email, $admin['id']]);

        // Validate the uploaded photo (if any) before touching the DB
        $photoError = '';
        $newPhotoName = null;
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));

            // Checks the extension, size AND that the file really is an image
            if ($imgErr = bt_image_upload_error($_FILES['photo'])) {
                $photoError = $imgErr;
            } else {
                $newPhotoName = bt_random_name('admin_', $ext);
            }
        }

        if ($dupStmt->fetch()) {
            $error = 'That username or email is already in use by another account.';
        } elseif ($photoError) {
            $error = $photoError;
        } elseif ($wantsPasswordChange && !password_verify($current_password, $admin['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif ($wantsPasswordChange && ($pwErr = bt_password_error($new_password, $username))) {
            $error = $pwErr;
        } elseif ($wantsPasswordChange && $new_password !== $repeat_password) {
            $error = 'New passwords do not match.';
        } else {
            // Work out the final photo filename to store
            $photoName = $admin['photo'] ?? null;
            $oldPhotoToDelete = null;

            if ($newPhotoName) {
                move_uploaded_file($_FILES['photo']['tmp_name'], __DIR__ . '/uploads/photos/' . $newPhotoName);
                if ($photoName) $oldPhotoToDelete = $photoName;
                $photoName = $newPhotoName;
            } elseif ($remove_photo && $photoName) {
                $oldPhotoToDelete = $photoName;
                $photoName = null;
            }

            if ($wantsPasswordChange) {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE accounts SET username = ?, email = ?, photo = ?, display_name = ?, password_hash = ? WHERE id = ?");
                $upd->execute([$username, $email, $photoName, $username, $hash, $admin['id']]);
            } else {
                $upd = $pdo->prepare("UPDATE accounts SET username = ?, email = ?, photo = ?, display_name = ? WHERE id = ?");
                $upd->execute([$username, $email, $photoName, $username, $admin['id']]);
            }

            // Clean up the old file only after the DB update succeeds
            if ($oldPhotoToDelete && is_file(__DIR__ . '/uploads/photos/' . $oldPhotoToDelete)) {
                @unlink(__DIR__ . '/uploads/photos/' . $oldPhotoToDelete);
            }

            $_SESSION['admin_username'] = $username;
            $success = 'Your account information has been updated.';
            if ($wantsPasswordChange) {
                unset($_SESSION['force_pw_change']);   // weak/default password has now been replaced
                session_regenerate_id(true);
            }
            if (!empty($_SESSION['force_pw_change'])) {
                $success = '';
                $error = 'You must change your password (Current Password + New Password) before continuing.';
            }

            // Refresh local copy so the form shows the latest values
            $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = ? AND role = 'admin'");
            $stmt->execute([$admin['id']]);
            $admin = $stmt->fetch();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Account Settings | BioTrack</title>
<link rel="stylesheet" href="assets/css/base.css?v=<?= @filemtime(__DIR__ . '/assets/css/base.css') ?>">
<link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>">
<link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <?php include 'includes/topbar.php'; ?>
        <div class="content">
            <div class="page-head">
                <h1>Account Settings</h1>
                <div class="breadcrumb"><a href="dashboard.php">Home</a> &rsaquo; Account Settings</div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <div class="panel">
                <div class="panel-head"><h2>Profile Information</h2></div>
                <form method="post" enctype="multipart/form-data" class="form-grid">

                    <div class="full" style="display:flex;align-items:center;gap:20px;margin-bottom:8px;">
                        <span class="photo-thumb is-icon" style="width:72px;height:72px;">
                            <?php if (!empty($admin['photo'])): ?>
                                <img src="uploads/photos/<?= htmlspecialchars($admin['photo']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                            <?php else: ?>
                                <?= bt_icon('user') ?>
                            <?php endif; ?>
                        </span>
                        <div>
                            <label style="display:block;margin-bottom:6px;">Profile Picture</label>
                            <input type="file" name="photo" accept="image/*">
                            <?php if (!empty($admin['photo'])): ?>
                                <label style="display:block;margin-top:6px;font-weight:normal;font-size:13px;color:#8a94a6;">
                                    <input type="checkbox" name="remove_photo" value="1"> Remove current photo
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="field">
                        <label>Username *</label>
                        <input type="text" name="username" value="<?= htmlspecialchars($admin['username']) ?>" required>
                    </div>
                    <div class="field">
                        <label>Email Address *</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($admin['email']) ?>" required>
                    </div>

                    <div class="full">
                        <hr style="border:none;border-top:1px solid #e3e7ee;margin:8px 0 20px;">
                        <h3 style="margin:0 0 12px;font-size:15px;color:#2c3444;">Change Password</h3>
                        <p style="color:#8a94a6;font-size:13px;margin-top:-8px;margin-bottom:16px;">
                            Leave these blank if you don't want to change your password.
                        </p>
                    </div>

                    <div class="field">
                        <label>Current Password</label>
                        <div class="input-wrap">
                            <input type="password" id="current_password" name="current_password" placeholder="Current password">
                            <button type="button" class="toggle-pass" onclick="togglePass('current_password', this)">&#128065;</button>
                        </div>
                    </div>
                    <div class="field"></div>
                    <div class="field">
                        <label>New Password</label>
                        <div class="input-wrap">
                            <input type="password" id="new_password" name="new_password" placeholder="New password">
                            <button type="button" class="toggle-pass" onclick="togglePass('new_password', this)">&#128065;</button>
                        </div>
                    </div>
                    <div class="field">
                        <label>Repeat New Password</label>
                        <div class="input-wrap">
                            <input type="password" id="repeat_password" name="repeat_password" placeholder="Repeat new password">
                            <button type="button" class="toggle-pass" onclick="togglePass('repeat_password', this)">&#128065;</button>
                        </div>
                    </div>

                    <div class="full" style="margin-top:10px;display:flex;gap:10px;">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="dashboard.php" class="btn" style="background:#e3e7ee;color:#2c3444;">Cancel</a>
                    </div>
                </form>
            </div>

<?php include 'includes/footer.php'; ?>
<script src="assets/js/auth.js?v=<?= @filemtime(__DIR__ . '/assets/js/auth.js') ?>"></script>
