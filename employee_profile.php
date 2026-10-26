<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/csv_helpers.php';
require_once 'includes/auth_role.php';
require_once 'includes/icons.php';
require_employee();

$empId = (int)($_SESSION['employee_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$empId]);
$emp = $stmt->fetch();

if (!$emp) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

$allowedCourses   = ['BSIT', 'BSBA', 'BSA'];
$allowedPositions = ['Teacher', 'Administrator', 'Dean'];

$mobile_digits = preg_replace('/\D/', '', preg_replace('/^\+63/', '', $emp['mobile_no'] ?? ''));
$course   = $emp['course'] ?? '';
$position = $emp['position'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Course and Position are managed by the admin only (see employee_edit.php) -
    // this form no longer accepts changes to them, so they're read back from
    // the database rather than trusted from $_POST.
    $mobile_digits = normalize_ph_mobile($_POST['mobile_digits'] ?? '') ?? preg_replace('/\D/', '', $_POST['mobile_digits'] ?? '');

    if ($mobile_digits === '') {
        $error = 'Mobile number is required.';
    } elseif (!preg_match('/^9\d{9}$/', $mobile_digits)) {
        $error = 'Mobile number must be 10 digits starting with 9.';
    } else {
        // Own photo upload - the employee can set/change this themselves.
        $photoError = '';
        $newPhotoName = null;
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));

            // Checks the extension, size AND that the file really is an image
            if ($imgErr = bt_image_upload_error($_FILES['photo'])) {
                $photoError = $imgErr;
            } else {
                $newPhotoName = bt_random_name('emp_', $ext);
            }
        }

        if ($photoError) {
            $error = $photoError;
        } else {
            $mobile_no = '+63' . $mobile_digits;
            $photoName = $emp['photo'];
            $oldPhotoToDelete = null;

            if ($newPhotoName) {
                move_uploaded_file($_FILES['photo']['tmp_name'], __DIR__ . '/uploads/photos/' . $newPhotoName);
                if ($photoName) $oldPhotoToDelete = $photoName;
                $photoName = $newPhotoName;
            }

            $upd = $pdo->prepare("UPDATE employees SET mobile_no = ?, photo = ? WHERE id = ?");
            $upd->execute([$mobile_no, $photoName, $emp['id']]);

            if ($oldPhotoToDelete && is_file(__DIR__ . '/uploads/photos/' . $oldPhotoToDelete)) {
                @unlink(__DIR__ . '/uploads/photos/' . $oldPhotoToDelete);
            }

            $success = 'Your profile has been updated.';

            $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
            $stmt->execute([$emp['id']]);
            $emp = $stmt->fetch();
            $course   = $emp['course'];
            $position = $emp['position'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Profile | BioTrack</title>
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
                <h1>Edit Profile</h1>
                <div class="breadcrumb"><a href="dashboard.php">My Profile</a> &rsaquo; Edit</div>
            </div>

            <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <div class="panel">
                <div class="panel-head"><h2>Employee Information</h2></div>

                <form method="post" enctype="multipart/form-data" class="form-grid">

                    <div class="full" style="display:flex;align-items:center;gap:20px;margin-bottom:8px;">
                        <span class="photo-thumb is-icon" style="width:72px;height:72px;">
                            <?php if (!empty($emp['photo'])): ?>
                                <img src="uploads/photos/<?= htmlspecialchars($emp['photo']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                            <?php else: ?>
                                <?= bt_icon('user') ?>
                            <?php endif; ?>
                        </span>
                        <div>
                            <div style="font-weight:600;font-size:16px;"><?= htmlspecialchars($emp['name']) ?></div>
                            <div style="color:#8a94a6;font-size:13px;">
                                ID <?= htmlspecialchars($emp['employee_id']) ?> &middot;
                                <?= htmlspecialchars($emp['department']) ?>
                            </div>
                            <label style="display:block;margin-top:8px;">
                                <span style="display:block;font-size:12.5px;font-weight:600;margin-bottom:5px;">Change Photo</span>
                                <input type="file" name="photo" accept="image/*">
                            </label>
                            <div style="color:#8a94a6;font-size:12px;margin-top:4px;">
                                Name, ID, and department are managed by your admin.
                            </div>
                        </div>
                    </div>

                    <div class="field">
                        <label>Course</label>
                        <select name="course" disabled>
                            <option value="">Select course</option>
                            <?php foreach ($allowedCourses as $c): ?>
                                <option value="<?= $c ?>" <?= $course === $c ? 'selected' : '' ?>><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-hint">Only your admin can change your course.</div>
                    </div>
                    <div class="field">
                        <label>Position</label>
                        <select name="position" disabled>
                            <option value="">Select position</option>
                            <?php foreach ($allowedPositions as $p): ?>
                                <option value="<?= $p ?>" <?= $position === $p ? 'selected' : '' ?>><?= $p ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-hint">Only your admin can change your position.</div>
                    </div>
                    <div class="field">
                        <label>Mobile No. *</label>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <span class="phone-prefix">+63</span>
                            <input type="text" name="mobile_digits" inputmode="numeric" pattern="[0-9]*"
                                   maxlength="10" style="flex:1;"
                                   value="<?= htmlspecialchars($mobile_digits) ?>"
                                   oninput="mobileDigitsOnly(this,'mobile_err')" placeholder="9XXXXXXXXX" required>
                        </div>
                        <div id="mobile_err" class="field-error" style="display:none;">Mobile number must be 10 digits starting with 9.</div>
                    </div>

                    <div class="full" style="margin-top:10px;display:flex;gap:10px;">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="dashboard.php" class="btn" style="background:#e3e7ee;color:#2c3444;">Cancel</a>
                    </div>
                </form>

                <div class="full" style="margin-top:24px;">
                    <hr style="border:none;border-top:1px solid #e3e7ee;margin:8px 0 20px;">
                    <h3 style="margin:0 0 8px;font-size:15px;color:#2c3444;">Password</h3>
                    <p style="color:#8a94a6;font-size:13px;">
                        For your security, your email address and password can't be changed from this page.
                        To change your password, use the button below &mdash;
                        you'll be asked for your current password first.
                    </p>
                    <a href="employee_forgot_password.php" class="btn" style="background:#e3e7ee;color:#2c3444;">Change Password</a>
                </div>
            </div>

<?php include 'includes/footer.php'; ?>
<script src="assets/js/auth.js?v=<?= @filemtime(__DIR__ . '/assets/js/auth.js') ?>"></script>
<script>
function mobileDigitsOnly(el, errId) {
    var before = el.value;
    var digits = before.replace(/\D/g, '');
    if (digits.length > 10 && digits.indexOf('63') === 0) digits = digits.slice(2); // pasted +63...
    if (digits.charAt(0) === '0') digits = digits.slice(1);                         // pasted 09...
    digits = digits.slice(0, 10);
    if (digits.length >= 1 && digits.charAt(0) !== '9') digits = '';
    el.value = digits;
    var errEl = document.getElementById(errId);
    if (errEl) errEl.style.display = (before !== digits) ? 'block' : 'none';
}
</script>
<style>.field-error{color:#c0392b;font-size:12px;margin-top:4px;}</style>
