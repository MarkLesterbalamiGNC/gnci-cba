<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/csv_helpers.php';
require_once 'includes/schedule_functions.php';
require_once 'includes/admin_confirm.php';
require_once 'includes/fingerprint_functions.php';
require_once 'includes/employee_schema.php';
require_admin();
fp_ensure_table($pdo);
bt_ensure_gender_column($pdo);

$allowedCourses   = ['BSIT', 'BSBA', 'BSA'];
$allowedPositions = ['Teacher', 'Administrator', 'Dean'];
$schedules        = get_all_schedules();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch();
if (!$emp) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Employee not found.'];
    header('Location: employee.php');
    exit;
}

// Existing login account for this employee, if one was ever created
$accStmt = $pdo->prepare("SELECT * FROM accounts WHERE role = 'employee' AND employee_pk = ?");
$accStmt->execute([$id]);
$account = $accStmt->fetch();

$error = '';

$dt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE position = 'Dean' AND deleted_at IS NULL AND id <> ?");
$dt->execute([$id]);
$deanTaken = (int)$dt->fetchColumn() > 0;

// An administrator account already exists, so "Administrator" can't be assigned
// to anyone else. (If this employee is already an Administrator, keep it selectable.)
$adminTaken = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE role = 'admin'")->fetchColumn() > 0
              && ($emp['position'] ?? '') !== 'Administrator';

// Split the stored "+63XXXXXXXXXX" back into just the digits for the form
$mobile_digits = preg_replace('/\D/', '', preg_replace('/^\+63/', '', $emp['mobile_no'] ?? ''));
$course = $emp['course'] ?? '';
$gender = $emp['gender'] ?? '';
$schedule_id = $emp['schedule_id'] ?? '';
$fpSaved = fp_list($pdo, $id);   // fingers already stored for this employee
$fpUids  = $fpSaved;             // what the form shows (changes if the form is re-shown after an error)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id = trim($_POST['employee_id'] ?? '');
    $name        = trim($_POST['name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $course      = trim($_POST['course'] ?? '');
    $gender      = trim($_POST['gender'] ?? '');
    $position    = trim($_POST['position'] ?? '');
    $mobile_digits   = normalize_ph_mobile($_POST['mobile_digits'] ?? '') ?? preg_replace('/\D/', '', $_POST['mobile_digits'] ?? '');
    $fpUids          = fp_clean_uids($_POST['fingerprint_uids'] ?? []);
    $schedule_id     = trim($_POST['schedule_id'] ?? '');

    $new_password        = $_POST['new_password'] ?? '';
    $repeat_password     = $_POST['repeat_password'] ?? '';
    $wantsPasswordChange = $new_password !== '' || $repeat_password !== '';

    $department = 'CBA';

    // Photo must be a real, small image (checked before anything is saved)
    $imgErr = (!empty($_FILES['photo']['name'])) ? bt_image_upload_error($_FILES['photo']) : null;

    if ($employee_id === '' || !ctype_digit($employee_id)) {
        $error = 'Employee ID must contain numbers only.';
    } elseif ($position === 'Dean' && $deanTaken) {
        $error = 'A Dean already exists. There can only be one Dean.';
    } elseif ($position === 'Administrator' && $adminTaken) {
        $error = 'An administrator account already exists. There can only be one Administrator.';
    } elseif (!$name || !$email || !$position || $mobile_digits === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!in_array($gender, BT_GENDERS, true)) {
        $error = 'Please select a gender.';
    } elseif (!in_array($course, $allowedCourses, true)) {
        $error = 'Please select a valid course.';
    } elseif (!in_array($position, $allowedPositions, true)) {
        $error = 'Please select a valid position.';
    } elseif ($schedule_id === '' || !ctype_digit($schedule_id) || !in_array((int)$schedule_id, array_column($schedules, 'id'), true)) {
        $error = 'Please assign a valid schedule so the biometric system can verify clock-in/clock-out times.';
    } elseif (!preg_match('/^9\d{9}$/', $mobile_digits)) {
        $error = 'Mobile number must be 10 digits starting with 9 (e.g. 9XXXXXXXXX).';
    } elseif (count($fpUids) > FP_MAX_PER_EMPLOYEE) {
        $error = 'An employee can have at most ' . FP_MAX_PER_EMPLOYEE . ' fingerprints.';
    } elseif ($imgErr) {
        $error = $imgErr;
    } elseif ($wantsPasswordChange && ($pwErr = bt_password_error($new_password, $employee_id))) {
        $error = $pwErr;
    } elseif ($wantsPasswordChange && $new_password !== $repeat_password) {
        $error = 'New passwords do not match.';
    } elseif ($pwError = check_admin_password($_POST['admin_password'] ?? '')) {
        // Admin must re-enter their own password before any employee data is changed
        $error = $pwError;
    } else {
        $mobile_no = '+63' . $mobile_digits;

        $photoName = $emp['photo'];
        if (!empty($_FILES['photo']['name'])) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                $photoName = bt_random_name('emp_', $ext);
                move_uploaded_file($_FILES['photo']['tmp_name'], __DIR__ . '/uploads/photos/' . $photoName);
            }
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE employees SET
                employee_id=?, name=?, gender=?, department=?, course=?, email=?, position=?, schedule_id=?, mobile_no=?, photo=?
                WHERE id=?");
            $stmt->execute([
                $employee_id, $name, $gender, $department, $course, $email, $position, (int)$schedule_id, $mobile_no, $photoName, $id
            ]);

            // Make the employee's fingers match the list on the form
            // (also updates employees.fingerprint_uid / fingerprint_enrolled)
            fp_set_for_employee($pdo, $id, $fpUids);

            // Keep (or lazily create) the employee's login account in sync
            if ($account) {
                if ($wantsPasswordChange) {
                    $hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $up = $pdo->prepare("UPDATE accounts SET username=?, email=?, display_name=?, password_hash=? WHERE id=?");
                    $up->execute([$employee_id, $email, $name, $hash, $account['id']]);
                } else {
                    $up = $pdo->prepare("UPDATE accounts SET username=?, email=?, display_name=? WHERE id=?");
                    $up->execute([$employee_id, $email, $name, $account['id']]);
                }
            } elseif ($wantsPasswordChange) {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $ins = $pdo->prepare("INSERT INTO accounts (role, username, email, password_hash, display_name, admin_id, employee_pk)
                                      VALUES ('employee', ?, ?, ?, ?, NULL, ?)");
                $ins->execute([$employee_id, $email, $hash, $name, $id]);
            }

            $pdo->commit();

            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Employee updated.'];
            header('Location: employee.php');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = str_contains($e->getMessage(), 'Duplicate')
                ? 'Could not update: that Employee ID, Email, or Fingerprint UID is already in use.'
                : 'Could not update employee.';
        }
    }

    // Keep the form sticky on error
    $emp['employee_id'] = $employee_id;
    $emp['name'] = $name;
    $emp['email'] = $email;
    $emp['position'] = $position;
    $emp['schedule_id'] = $schedule_id;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Employee | BioTrack</title>
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
                <h1>Edit Employee</h1>
                <div class="breadcrumb"><a href="dashboard.php">Home</a> &rsaquo; <a href="employee.php">Employee</a> &rsaquo; Edit</div>
            </div>

            <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <div class="panel">
                <form method="post" enctype="multipart/form-data" novalidate>
                    <div class="form-grid">
                        <div class="field">
                            <label>Employee ID *</label>
                            <input type="text" name="employee_id" inputmode="numeric" pattern="[0-9]*"
                                   value="<?= htmlspecialchars($emp['employee_id']) ?>"
                                   oninput="digitsOnly(this,'employee_id_err')" required>
                            <div id="employee_id_err" class="field-error" style="display:none;">Employee ID must contain numbers only.</div>
                        </div>
                        <div class="field"><label>Full Name *</label><input type="text" name="name" value="<?= htmlspecialchars($emp['name']) ?>" required></div>
                        <div class="field">
                            <label>Gender *</label>
                            <select name="gender" required>
                                <option value="">Select gender</option>
                                <?php foreach (BT_GENDERS as $g): ?>
                                    <option value="<?= $g ?>" <?= $gender === $g ? 'selected' : '' ?>><?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field"><label>Department *</label><input type="text" name="department" value="CBA" readonly></div>
                        <div class="field">
                            <label>Course *</label>
                            <select name="course" required>
                                <option value="">Select course</option>
                                <?php foreach ($allowedCourses as $c): ?>
                                    <option value="<?= $c ?>" <?= $course === $c ? 'selected' : '' ?>><?= $c ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Position *</label>
                            <select name="position" required>
                                <option value="">Select position</option>
                                <?php foreach ($allowedPositions as $p): ?>
                                    <?php $lock = (($p === 'Dean' && $deanTaken) || ($p === 'Administrator' && $adminTaken)); ?>
                                    <option value="<?= $p ?>" <?= $emp['position'] === $p ? 'selected' : '' ?> <?= $lock ? 'disabled' : '' ?>><?= $p ?><?= $lock ? ($p === 'Administrator' ? ' (already created)' : ' (already assigned)') : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Schedule *</label>
                            <select name="schedule_id" required>
                                <option value="">Select schedule</option>
                                <?php foreach ($schedules as $s): ?>
                                    <option value="<?= $s['id'] ?>" <?= (string)$emp['schedule_id'] === (string)$s['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div style="color:#8a94a6;font-size:12px;margin-top:4px;">
                                Determines which shift(s) the biometric system checks this employee's clock-in/out against.
                                <a href="schedule_add.php">Add a new schedule</a>.
                            </div>
                        </div>
                        <div class="field"><label>Email Address *</label><input type="email" name="email" value="<?= htmlspecialchars($emp['email']) ?>" required></div>
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
                        <div class="field"><label>Photo</label><input type="file" name="photo" accept="image/*"></div>

                        <div class="full">
                            <?php $fpPanelUids = $fpUids; $fpPanelSaved = $fpSaved; $fpEmployeePk = $id; include 'includes/fingerprint_panel.php'; ?>
                        </div>

                        <div class="full">
                            <hr style="border:none;border-top:1px solid #e3e7ee;margin:8px 0 20px;">
                            <h3 style="margin:0 0 12px;font-size:15px;color:#2c3444;">Login Account</h3>
                            <p style="color:#8a94a6;font-size:13px;margin-top:-8px;margin-bottom:16px;">
                                <?php if ($account): ?>
                                    Leave the password fields blank to keep the current password.
                                <?php else: ?>
                                    This employee has no login account yet. Set a password below to create one.
                                <?php endif; ?>
                            </p>
                        </div>

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
                    </div>

                    <div class="field" style="max-width:360px;margin-top:20px;">
                        <label>Admin Password * <span style="color:#8a94a6;font-weight:400;">(required to save changes)</span></label>
                        <input type="password" name="admin_password" placeholder="Your admin password" autocomplete="current-password" required>
                    </div>

                    <div style="margin-top:20px;display:flex;gap:10px;">
                        <button type="submit" class="btn btn-primary">Update Employee</button>
                        <a href="employee.php" class="btn" style="background:#e3e7ee;color:#2c3444;">Cancel</a>
                    </div>
                </form>
            </div>

<?php include 'includes/footer.php'; ?>
<script src="assets/js/auth.js?v=<?= @filemtime(__DIR__ . '/assets/js/auth.js') ?>"></script>
<?php include 'includes/fingerprint_enroll.php'; ?>
<script>
function digitsOnly(el, errId, maxLen) {
    var before = el.value;
    var after = before.replace(/\D/g, '');
    if (maxLen) after = after.slice(0, maxLen);
    el.value = after;
    var errEl = document.getElementById(errId);
    if (errEl) errEl.style.display = (before !== after) ? 'block' : 'none';
}
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
