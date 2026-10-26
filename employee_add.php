<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/csv_helpers.php';
require_once 'includes/schedule_functions.php';
require_once 'includes/fingerprint_functions.php';
require_once 'includes/employee_schema.php';
require_admin();
fp_ensure_table($pdo);
bt_ensure_gender_column($pdo);

$error = '';

$allowedCourses   = ['BSIT', 'BSBA', 'BSA'];
$allowedPositions = ['Teacher', 'Administrator', 'Dean'];
$schedules        = get_all_schedules();

$deanTaken = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE position = 'Dean' AND deleted_at IS NULL")->fetchColumn() > 0;
$adminLimitReached = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE role = 'admin'")->fetchColumn() >= 2;
$username = '';

// Sticky values so the form re-populates on error
$employee_id = $name = $email = $course = $position = $gender = '';
$fpUids = []; // scanner IDs of the fingers scanned for this employee
$mobile_digits = '';
$schedule_id = '';
$password = $repeat_password = '';
$tmpPhoto = ''; // photo uploaded earlier in this form, waiting in uploads/tmp/

$tmpDir = __DIR__ . '/uploads/tmp';

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

    $password        = $_POST['password'] ?? '';
    $repeat_password = $_POST['repeat_password'] ?? '';

    $username = trim($_POST['username'] ?? '');

    if ($position === 'Administrator') {
        if ($adminLimitReached) {
            $error = 'An administrator account was already created.';
        } elseif ($email === '' || $username === '' || $password === '') {
            $error = 'Please fill in Email, Username and Password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            $error = 'Username must be 3-50 characters (letters, numbers, . _ -).';
        } elseif ($pwErr = bt_password_error($password, $username)) {
            $error = $pwErr;
        } elseif ($password !== $repeat_password) {
            $error = 'Passwords do not match.';
        } else {
            $dup = $pdo->prepare("SELECT id FROM accounts WHERE username = ? OR email = ? LIMIT 1");
            $dup->execute([$username, $email]);
            if ($dup->fetch()) {
                $error = 'That username or email is already in use.';
            } else {
                $ins = $pdo->prepare("INSERT INTO accounts (role, username, email, password_hash, display_name, admin_id, employee_pk)
                                      VALUES ('admin', ?, ?, ?, ?, NULL, NULL)");
                $ins->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $username]);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Administrator account created. They can sign in with their username or email.'];
                header('Location: employee.php');
                exit;
            }
        }
    }

    $department = 'CBA';

    // ---- Photo: keep it between failed submits ----
    // Browsers never re-fill <input type="file">, so a valid upload is parked
    // in uploads/tmp/ and remembered through a hidden field until Save succeeds.
    $photoError = '';
    $prev = basename($_POST['tmp_photo'] ?? '');
    if ($prev !== '' && preg_match('/^emp_[a-z0-9]+\.(jpg|jpeg|png|gif)$/', $prev) && is_file("$tmpDir/$prev")) {
        $tmpPhoto = $prev;
    }

    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if ($imgErr = bt_image_upload_error($_FILES['photo'])) {
            $photoError = $imgErr;      // wrong type / too big / not a real image
        } else {
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
            $newTmp = bt_random_name('emp_', $ext);
            if (move_uploaded_file($_FILES['photo']['tmp_name'], "$tmpDir/$newTmp")) {
                if ($tmpPhoto !== '' && is_file("$tmpDir/$tmpPhoto")) @unlink("$tmpDir/$tmpPhoto"); // replace old one
                $tmpPhoto = $newTmp;
            }
        }
    }

    if ($error !== '') {
        // administrator form error is already set
    } elseif ($photoError) {
        $error = $photoError;
    } elseif ($employee_id === '' || !ctype_digit($employee_id)) {
        $error = 'Employee ID must contain numbers only.';
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
    } elseif ($position === 'Dean' && $deanTaken) {
        $error = 'A Dean already exists. There can only be one Dean.';
    } elseif ($schedule_id === '' || !ctype_digit($schedule_id) || !in_array((int)$schedule_id, array_column($schedules, 'id'), true)) {
        $error = 'Please assign a valid schedule so the biometric system can verify clock-in/clock-out times.';
    } elseif (!preg_match('/^9\d{9}$/', $mobile_digits)) {
        $error = 'Mobile number must be 10 digits starting with 9 (e.g. 9XXXXXXXXX).';
    } elseif (count($fpUids) > FP_MAX_PER_EMPLOYEE) {
        $error = 'An employee can have at most ' . FP_MAX_PER_EMPLOYEE . ' fingerprints.';
    } elseif ($password === '' || bt_password_error($password, $employee_id)) {
        $error = 'Please choose a stronger password: at least 8 characters, and not the Employee ID or a common password.';
    } elseif ($password !== $repeat_password) {
        $error = 'Passwords do not match.';
    } else {
        $mobile_no = '+63' . $mobile_digits;

        // Make sure this Employee ID / Email isn't already tied to a login account
        $dup = $pdo->prepare("SELECT id FROM accounts WHERE role = 'employee' AND (username = ? OR email = ?)");
        $dup->execute([$employee_id, $email]);

        if ($dup->fetch()) {
            $error = 'That Employee ID or Email is already linked to an employee account (it may belong to someone in Recently Deleted).';
        } else {
            try {
                $pdo->beginTransaction();

                $photoName = $tmpPhoto !== '' ? $tmpPhoto : null;

                $stmt = $pdo->prepare("INSERT INTO employees
                    (employee_id, name, gender, department, course, email, position, schedule_id, mobile_no, photo, fingerprint_enrolled)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
                $stmt->execute([
                    $employee_id, $name, $gender, $department, $course, $email, $position, (int)$schedule_id, $mobile_no, $photoName
                ]);
                $newEmployeePk = (int)$pdo->lastInsertId();

                // Save every scanned finger (also fills employees.fingerprint_uid / fingerprint_enrolled)
                fp_set_for_employee($pdo, $newEmployeePk, $fpUids);

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $acc = $pdo->prepare("INSERT INTO accounts (role, username, email, password_hash, display_name, admin_id, employee_pk)
                                      VALUES ('employee', ?, ?, ?, ?, NULL, ?)");
                $acc->execute([$employee_id, $email, $hash, $name, $newEmployeePk]);

                $pdo->commit();

                // Saved - now move the parked photo into its permanent folder
                if ($photoName) {
                    @rename("$tmpDir/$photoName", __DIR__ . '/uploads/photos/' . $photoName);
                }

                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Employee added successfully. They can sign in with their Employee ID or Email.'];
                header('Location: employee.php');
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = str_contains($e->getMessage(), 'Duplicate')
                    ? 'Employee ID, Email, or Fingerprint UID already exists.'
                    : 'Could not save employee.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Employee | BioTrack</title>
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
                <h1>Add New Employee</h1>
                <div class="breadcrumb"><a href="dashboard.php">Home</a> &rsaquo; <a href="employee.php">Employee</a> &rsaquo; Add</div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="panel">
                <form method="post" enctype="multipart/form-data" novalidate>
                    <div class="form-grid">
                        <div class="field emp-only">
                            <label>Employee ID *</label>
                            <input type="text" name="employee_id" inputmode="numeric" pattern="[0-9]*"
                                   value="<?= htmlspecialchars($employee_id) ?>"
                                   oninput="digitsOnly(this,'employee_id_err')" required>
                            <div id="employee_id_err" class="field-error" style="display:none;">Employee ID must contain numbers only.</div>
                        </div>
                        <div class="field emp-only">
                            <label>Full Name *</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($name) ?>" required>
                        </div>
                        <div class="field emp-only">
                            <label>Gender *</label>
                            <select name="gender" required>
                                <option value="">Select gender</option>
                                <?php foreach (BT_GENDERS as $g): ?>
                                    <option value="<?= $g ?>" <?= $gender === $g ? 'selected' : '' ?>><?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field emp-only">
                            <label>Department *</label>
                            <input type="text" name="department" value="CBA" readonly>
                        </div>
                        <div class="field emp-only">
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
                            <select name="position" id="position" required>
                                <option value="">Select position</option>
                                <?php foreach ($allowedPositions as $p): ?>
                                    <?php $lock = (($p === 'Administrator' && $adminLimitReached) || ($p === 'Dean' && $deanTaken)); ?>
                                    <option value="<?= $p ?>" <?= $position === $p ? 'selected' : '' ?> <?= $lock ? 'disabled' : '' ?>><?= $p ?><?= $lock ? ($p === 'Dean' ? ' (already assigned)' : ' (already created)') : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field emp-only">
                            <label>Schedule *</label>
                            <select name="schedule_id" required>
                                <option value="">Select schedule</option>
                                <?php foreach ($schedules as $s): ?>
                                    <option value="<?= $s['id'] ?>" <?= (string)$schedule_id === (string)$s['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div style="color:#8a94a6;font-size:12px;margin-top:4px;">
                                Determines which shift(s) the biometric system checks this employee's clock-in/out against.
                                <a href="schedule_add.php">Add a new schedule</a>.
                            </div>
                        </div>
                        <div class="field">
                            <label>Email Address *</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
                        </div>
                        <div class="field admin-only">
                            <label>Username *</label>
                            <input type="text" name="username" value="<?= htmlspecialchars($username) ?>" autocomplete="off">
                        </div>
                        <div class="field emp-only">
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
                        <div class="field emp-only">
                            <label>Photo</label>
                            <?php if ($tmpPhoto): ?>
                                <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                                    <span class="photo-thumb" style="width:56px;height:56px;">
                                        <img src="uploads/tmp/<?= htmlspecialchars($tmpPhoto) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                                    </span>
                                    <span style="color:#8a94a6;font-size:12px;">Photo kept. Choose a new file below only if you want to replace it.</span>
                                </div>
                                <input type="hidden" name="tmp_photo" value="<?= htmlspecialchars($tmpPhoto) ?>">
                            <?php endif; ?>
                            <input type="file" name="photo" accept="image/*">
                        </div>

                        <div class="full">
                            <hr style="border:none;border-top:1px solid #e3e7ee;margin:8px 0 20px;">
                            <h3 style="margin:0 0 12px;font-size:15px;color:#2c3444;">Login Account</h3>
                            <p style="color:#8a94a6;font-size:13px;margin-top:-8px;margin-bottom:16px;">
                                Set a password so this employee can sign in right away using their Employee ID or Email.
                            </p>
                        </div>

                        <div class="field">
                            <label>Password *</label>
                            <div class="input-wrap">
                                <input type="password" id="password" name="password" placeholder="Password"
                                       value="<?= htmlspecialchars($password) ?>" required>
                                <button type="button" class="toggle-pass" onclick="togglePass('password', this)">&#128065;</button>
                            </div>
                        </div>
                        <div class="field">
                            <label>Repeat Password *</label>
                            <div class="input-wrap">
                                <input type="password" id="repeat_password" name="repeat_password" placeholder="Repeat password"
                                       value="<?= htmlspecialchars($repeat_password) ?>" required>
                                <button type="button" class="toggle-pass" onclick="togglePass('repeat_password', this)">&#128065;</button>
                            </div>
                        </div>

                        <div class="full emp-only">
                            <?php $fpPanelUids = $fpUids; $fpPanelSaved = []; $fpEmployeePk = 0; include 'includes/fingerprint_panel.php'; ?>
                        </div>
                    </div>

                    <div style="margin-top:20px;display:flex;gap:10px;">
                        <button type="submit" class="btn btn-primary">Save Employee</button>
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
<style>
.field-error{color:#c0392b;font-size:12px;margin-top:4px;}
.is-off{opacity:.4;filter:grayscale(1);pointer-events:none;user-select:none;}
</style>
<script>
(function () {
    var pos = document.getElementById('position');
    function apply() {
        var admin = pos.value === 'Administrator';
        document.querySelectorAll('.emp-only').forEach(function (el) {
            el.classList.toggle('is-off', admin);
            if (admin) el.setAttribute('inert', ''); else el.removeAttribute('inert');
        });
        document.querySelectorAll('.admin-only').forEach(function (el) {
            el.classList.toggle('is-off', !admin);
            if (!admin) el.setAttribute('inert', ''); else el.removeAttribute('inert');
        });
    }
    pos.addEventListener('change', apply);
    apply();
})();
</script>
