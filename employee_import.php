<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/csv_helpers.php';
require_once 'includes/schedule_functions.php';
require_once 'includes/admin_confirm.php';
require_admin();

// ---- Downloadable starter template ----
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="biotrack_employee_import_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Employee ID', 'Full Name', 'Email', 'Course', 'Position', 'Mobile Number', 'Schedule']);
    fputcsv($out, [excel_text('9123456789'), 'Juan Dela Cruz', 'juan.delacruz@example.com', 'BSIT', 'Teacher', excel_text('9171234567'), 'Regular (8AM-5PM)']);
    fclose($out);
    exit;
}

$allowedCourses   = ['BSIT', 'BSBA', 'BSA'];
$allowedPositions = ['Teacher', 'Administrator', 'Dean'];
$schedules        = get_all_schedules();
$scheduleByName   = [];
$scheduleNameById = [];
foreach ($schedules as $s) {
    $scheduleByName[strtolower(trim($s['name']))] = (int)$s['id'];
    $scheduleNameById[(int)$s['id']] = $s['name'];
}
$scheduleIds = array_column($schedules, 'id');

$results = null;

// =====================================================================
// STEP 2: the admin answered the "overwrite existing employees?" question
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['overwrite_action'])) {
    $pendingList = $_SESSION['import_pending'] ?? [];

    // Overwriting existing employees needs the admin's password.
    // On a wrong password the pending list is kept so they can retry.
    $pwError = ($_POST['overwrite_action'] === 'overwrite' && $pendingList)
        ? check_admin_password($_POST['admin_password'] ?? '')
        : null;
    if ($pwError === null) unset($_SESSION['import_pending']);

    $updated = 0;
    $skipped = 0;
    $errors  = [];

    if ($pwError !== null) {
        $errors[] = $pwError;
    } elseif ($_POST['overwrite_action'] === 'overwrite' && $pendingList) {
        $selected = array_map('intval', (array)($_POST['apply'] ?? []));

        foreach ($pendingList as $idx => $p) {
            if (!in_array($idx, $selected, true)) { $skipped++; continue; }

            try {
                $pdo->beginTransaction();

                $n = $p['new'];
                $up = $pdo->prepare("UPDATE employees
                                     SET name = ?, email = ?, course = ?, position = ?, schedule_id = ?, mobile_no = ?
                                     WHERE id = ? AND deleted_at IS NULL");
                $up->execute([$n['name'], $n['email'], $n['course'], $n['position'], $n['schedule_id'], $n['mobile_no'], $p['pk']]);

                // Keep the login account in sync (password is left untouched)
                $acc = $pdo->prepare("UPDATE accounts SET username = ?, email = ?, display_name = ?
                                      WHERE role = 'employee' AND employee_pk = ?");
                $acc->execute([$p['employee_id'], $n['email'], $n['name'], $p['pk']]);

                $pdo->commit();
                $updated++;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = 'Row ' . $p['row'] . ' (' . $p['employee_id'] . '): Could not update ('
                          . (str_contains($e->getMessage(), 'Duplicate') ? 'that email is already in use' : 'database error') . ').';
            }
        }
    } else {
        $skipped = count($pendingList);
    }

    $results = ['success' => 0, 'updated' => $updated, 'skipped' => $skipped, 'unchanged' => 0, 'errors' => $errors];
}

// =====================================================================
// STEP 1: a CSV was uploaded
// =====================================================================
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    unset($_SESSION['import_pending']);
    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        $results = ['success' => 0, 'skipped' => 0, 'errors' => ['Could not read the uploaded file. Please try again.']];
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $results = ['success' => 0, 'skipped' => 0, 'errors' => ['Please upload a .csv file (you can start from the template above).']];
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        $header = $handle ? fgetcsv($handle) : false;

        if (!$header) {
            $results = ['success' => 0, 'skipped' => 0, 'errors' => ['The file appears to be empty.']];
        } else {
            // Flexible column matching: header names are normalized so
            // "Mobile No.", "Mobile Number" and "mobile" all match the
            // same field, regardless of column order.
            $colIndex = [];
            foreach ($header as $i => $col) {
                $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $col));
                $colIndex[$key] = $i;
            }
            $get = function (array $row, array $keys) use ($colIndex) {
                foreach ($keys as $k) {
                    if (isset($colIndex[$k]) && isset($row[$colIndex[$k]])) {
                        return clean_excel_text($row[$colIndex[$k]]);
                    }
                }
                return '';
            };

            $successCount   = 0;
            $skippedCount   = 0;
            $unchangedCount = 0;
            $errors         = [];
            $pendingUpdates = [];
            $rowNum         = 1;
            $deanInFile     = false;
            $deanCheck      = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE position = 'Dean' AND deleted_at IS NULL AND employee_id <> ?");

            $fieldLabels = [
                'name'        => 'Full Name',
                'email'       => 'Email',
                'course'      => 'Course',
                'position'    => 'Position',
                'mobile_no'   => 'Mobile No.',
                'schedule_id' => 'Schedule',
            ];

            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
                    $skippedCount++;
                    continue; // blank row
                }

                $employee_id = $get($row, ['employeeid', 'id']);
                $name        = $get($row, ['fullname', 'name']);
                $email       = $get($row, ['email', 'emailaddress']);
                $course      = strtoupper($get($row, ['course']));
                $position    = $get($row, ['position']);
                $mobileRaw   = $get($row, ['mobilenumber', 'mobileno', 'mobile', 'phone', 'phonenumber']);
                $scheduleTxt = $get($row, ['schedule', 'scheduleid', 'schedulename']);

                // Accepts 9XXXXXXXXX, 09XXXXXXXXX, 639XXXXXXXXX or +639XXXXXXXXX
                $mobileDigits = normalize_ph_mobile($mobileRaw) ?? '';

                $rowError   = null;
                $scheduleId = null;
                if ($employee_id === '' || !ctype_digit($employee_id)) {
                    $rowError = looks_like_sci_notation($employee_id)
                        ? 'Employee ID was turned into scientific notation by Excel (' . $employee_id . '). Format the Employee ID column as Text and re-enter it.'
                        : 'Employee ID must contain numbers only.';
                } elseif ($name === '' || $email === '' || $position === '') {
                    $rowError = 'Missing a required field (name, email, or position).';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $rowError = 'Invalid email address.';
                } elseif (!in_array($course, $allowedCourses, true)) {
                    $rowError = 'Invalid course "' . ($course ?: '(blank)') . '" - must be one of: ' . implode(', ', $allowedCourses) . '.';
                } elseif (!in_array($position, $allowedPositions, true)) {
                    $rowError = 'Invalid position "' . $position . '" - must be one of: ' . implode(', ', $allowedPositions) . '.';
                } elseif ($position === 'Dean' && ($deanInFile || ($deanCheck->execute([$employee_id]) && (int)$deanCheck->fetchColumn() > 0))) {
                    $rowError = 'A Dean already exists. There can only be one Dean.';
                } elseif (!preg_match('/^9\d{9}$/', $mobileDigits)) {
                    $rowError = looks_like_sci_notation($mobileRaw)
                        ? 'Mobile number was turned into scientific notation by Excel (' . $mobileRaw . '). Format the Mobile Number column as Text and re-enter it.'
                        : 'Mobile number must be 10 digits starting with 9 (e.g. 9171234567).';
                } else {
                    if ($scheduleTxt !== '' && ctype_digit($scheduleTxt)) {
                        $scheduleId = (int)$scheduleTxt;
                    } elseif ($scheduleTxt !== '') {
                        $scheduleId = $scheduleByName[strtolower($scheduleTxt)] ?? null;
                    }
                    if (!$scheduleId || !in_array($scheduleId, $scheduleIds, true)) {
                        $rowError = 'Schedule "' . $scheduleTxt . '" was not found. Check the exact schedule name.';
                    }
                }

                if (!$rowError && $position === 'Dean') $deanInFile = true;

                if ($rowError) {
                    $errors[] = "Row $rowNum ($employee_id " . ($name ?: '') . "): $rowError";
                    continue;
                }

                $mobile_no = '+63' . $mobileDigits;

                // ---- Does this Employee ID already exist? Then it's an update, not a new employee ----
                $exStmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ? LIMIT 1");
                $exStmt->execute([$employee_id]);
                $ex = $exStmt->fetch();

                if ($ex) {
                    if ($ex['deleted_at'] !== null) {
                        $errors[] = "Row $rowNum ($employee_id $name): This employee is in Recently Deleted - restore or permanently delete them first.";
                        continue;
                    }

                    // The new email must not belong to a different employee's account
                    $emStmt = $pdo->prepare("SELECT 1 FROM accounts WHERE role = 'employee' AND email = ? AND (employee_pk IS NULL OR employee_pk <> ?) LIMIT 1");
                    $emStmt->execute([$email, $ex['id']]);
                    if ($emStmt->fetchColumn()) {
                        $errors[] = "Row $rowNum ($employee_id $name): The email $email is already used by another employee - skipped.";
                        continue;
                    }

                    $new = [
                        'name' => $name, 'email' => $email, 'course' => $course, 'position' => $position,
                        'mobile_no' => $mobile_no, 'schedule_id' => $scheduleId,
                    ];
                    $changes = [];
                    foreach ($new as $field => $newVal) {
                        $oldVal = $ex[$field];
                        if ((string)$oldVal === (string)$newVal) continue;
                        if ($field === 'schedule_id') {
                            $oldShow = $oldVal ? ($scheduleNameById[(int)$oldVal] ?? ('#' . $oldVal)) : '(none)';
                            $newShow = $scheduleNameById[(int)$newVal] ?? ('#' . $newVal);
                        } else {
                            $oldShow = ($oldVal === null || $oldVal === '') ? '(blank)' : (string)$oldVal;
                            $newShow = (string)$newVal;
                        }
                        $changes[] = ['label' => $fieldLabels[$field], 'old' => $oldShow, 'new' => $newShow];
                    }

                    if (!$changes) {
                        $unchangedCount++;
                        continue;
                    }

                    $pendingUpdates[] = [
                        'row'         => $rowNum,
                        'pk'          => (int)$ex['id'],
                        'employee_id' => $employee_id,
                        'name'        => $ex['name'],
                        'new'         => $new,
                        'changes'     => $changes,
                    ];
                    continue;
                }

                // ---- Brand-new Employee ID: make sure the email isn't taken ----
                $dup = $pdo->prepare("SELECT id FROM accounts WHERE role = 'employee' AND (username = ? OR email = ?)");
                $dup->execute([$employee_id, $email]);
                if ($dup->fetch()) {
                    $errors[] = "Row $rowNum: Email $email is already used by another employee - skipped.";
                    continue;
                }

                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("INSERT INTO employees
                        (employee_id, name, department, course, email, position, schedule_id, mobile_no, fingerprint_enrolled)
                        VALUES (?, ?, 'CBA', ?, ?, ?, ?, ?, 0)");
                    $stmt->execute([$employee_id, $name, $course, $email, $position, $scheduleId, $mobile_no]);
                    $newPk = (int)$pdo->lastInsertId();

                    // Temporary password = Employee ID. login.php detects this and FORCES the
                    // employee to choose a new password on their first sign-in.
                    $hash = password_hash($employee_id, PASSWORD_DEFAULT);
                    $acc = $pdo->prepare("INSERT INTO accounts (role, username, email, password_hash, display_name, admin_id, employee_pk)
                                          VALUES ('employee', ?, ?, ?, ?, NULL, ?)");
                    $acc->execute([$employee_id, $email, $hash, $name, $newPk]);

                    $pdo->commit();
                    $successCount++;
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $errors[] = "Row $rowNum: Could not save (" . (str_contains($e->getMessage(), 'Duplicate') ? 'duplicate value' : 'database error') . ').';
                }
            }
            fclose($handle);

            // Hold the changed rows until the admin says yes or no
            if ($pendingUpdates) $_SESSION['import_pending'] = $pendingUpdates;

            $results = [
                'success'   => $successCount,
                'updated'   => 0,
                'skipped'   => $skippedCount,
                'unchanged' => $unchangedCount,
                'errors'    => $errors,
            ];
        }
    }
}

$pending = $_SESSION['import_pending'] ?? null;

$rUpdated   = (int)($results['updated'] ?? 0);
$rUnchanged = (int)($results['unchanged'] ?? 0);
$rSkipped   = (int)($results['skipped'] ?? 0);

$errorBlock = '';
if ($results && $results['errors']) {
    $errorBlock = '<div class="import-results"><h3 style="margin:0 0 8px;font-size:14px;color:#2c3444;">Rows that need attention</h3><ul>';
    foreach ($results['errors'] as $err) $errorBlock .= '<li>' . htmlspecialchars($err) . '</li>';
    $errorBlock .= '</ul></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import Employees | BioTrack</title>
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
                <h1>Import Employees</h1>
                <div class="breadcrumb"><a href="dashboard.php">Home</a> &rsaquo; <a href="employee.php">Employee</a> &rsaquo; Import</div>
            </div>

            <?php if ($results): ?>
                <?php if ($results['success'] > 0): ?>
                    <div class="alert alert-success">
                        <?= (int)$results['success'] ?> new employee(s) imported successfully.
                        Their temporary password is their Employee ID &mdash; they will be asked to choose a
                        new password the first time they sign in. (If someone is locked out, set a new password for them under Employee &rsaquo; Edit.)
                    </div>
                <?php endif; ?>
                <?php if ($rUpdated > 0): ?>
                    <div class="alert alert-success"><?= $rUpdated ?> existing employee(s) updated. Their passwords were not changed.</div>
                <?php endif; ?>
                <?php if ($rUnchanged > 0): ?>
                    <div class="alert alert-success" style="background:#f3f5f9;color:#2c3444;border-color:#e3e7ee;">
                        <?= $rUnchanged ?> row(s) matched an existing employee exactly, so there was nothing to change.
                    </div>
                <?php endif; ?>
                <?php if ($rUpdated === 0 && $rSkipped > 0 && isset($_POST['overwrite_action'])): ?>
                    <div class="alert alert-success" style="background:#f3f5f9;color:#2c3444;border-color:#e3e7ee;">
                        <?= $rSkipped ?> existing employee(s) were left as they were.
                    </div>
                <?php endif; ?>
                <?php if ($results['errors']): ?>
                    <div class="alert alert-error">
                        <?= count($results['errors']) ?> row(s) could not be imported. See details below.
                    </div>
                <?php endif; ?>
                <?php if (!$results['success'] && !$rUpdated && !$rUnchanged && !$results['errors'] && !$pending && !isset($_POST['overwrite_action'])): ?>
                    <div class="alert alert-error">No valid rows were found in that file.</div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($pending): ?>
            <div class="panel" style="border-top:3px solid #ffcc00;">
                <div class="panel-head"><h2>Overwrite existing employees?</h2></div>
                <p style="color:#8a94a6;font-size:13px;margin-top:0;">
                    <?= count($pending) ?> row(s) in your file match employees that already exist (same Employee ID) but have different details.
                    Tick the ones you want to overwrite. Passwords, photos and fingerprints are never changed.
                </p>

                <form method="post">
                    <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width:40px;"><input type="checkbox" id="check_all" checked title="Select / unselect all"></th>
                                <th>Row</th>
                                <th>Employee</th>
                                <th>What will change</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pending as $idx => $p): ?>
                            <tr>
                                <td><input type="checkbox" class="apply-box bulk-check" name="apply[]" value="<?= (int)$idx ?>" checked></td>
                                <td><?= (int)$p['row'] ?></td>
                                <td>
                                    <div style="font-weight:600;"><?= htmlspecialchars($p['name']) ?></div>
                                    <div style="color:#8a94a6;font-size:12px;">ID <?= htmlspecialchars($p['employee_id']) ?></div>
                                </td>
                                <td>
                                    <?php foreach ($p['changes'] as $c): ?>
                                        <div style="margin-bottom:3px;">
                                            <strong><?= htmlspecialchars($c['label']) ?>:</strong>
                                            <span style="color:#b3261e;text-decoration:line-through;"><?= htmlspecialchars($c['old']) ?></span>
                                            &rarr;
                                            <span style="color:#1e7a3c;font-weight:600;"><?= htmlspecialchars($c['new']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <div class="field" style="max-width:360px;margin-top:16px;">
                        <label>Admin Password * <span style="color:#8a94a6;font-weight:400;">(needed only to overwrite)</span></label>
                        <input type="password" name="admin_password" placeholder="Your admin password" autocomplete="current-password" required>
                    </div>

                    <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
                        <button type="submit" name="overwrite_action" value="overwrite" class="btn btn-primary"
                                data-confirm-title="Overwrite Employees?"
                                data-confirm="Are you sure you want to overwrite the details of the {n} selected employee{s}? The old details will be replaced and this cannot be undone."
                                data-confirm-btn="Yes, Overwrite">Overwrite selected</button>
                        <button type="submit" name="overwrite_action" value="skip" formnovalidate class="btn" style="background:#e3e7ee;color:#2c3444;">Skip &ndash; keep existing</button>
                    </div>
                </form>
                <?= $errorBlock ?>
            </div>
            <?php endif; ?>

            <?php if (!$pending): ?>
            <div class="panel">
                <div class="panel-head"><h2>Bulk Import from CSV</h2></div>

                <p style="color:#8a94a6;font-size:13px;margin-top:0;margin-bottom:16px;">
                    Add many teachers/employees at once instead of typing each one into the Add Employee form.
                    A file exported from this system (all employees or just one) can be imported back as-is. Start from the template below, fill in one row per person, then upload it here.
                    If a row has an Employee ID that already exists, you'll be asked whether to overwrite that employee's details.
                    Course must be one of <strong><?= implode(', ', $allowedCourses) ?></strong>,
                    Position one of <strong><?= implode(', ', $allowedPositions) ?></strong>,
                    Mobile Number is the 10 digits after +63, starting with <strong>9</strong> (09&hellip; and +639&hellip; are accepted too), and Schedule must match an existing
                    schedule name exactly (see <a href="schedules.php">Schedules</a>).
                </p>

                <div style="margin-bottom:20px;">
                    <a href="employee_import.php?template=1" class="btn" style="background:#e3e7ee;color:#2c3444;">&#8681; Download CSV Template</a>
                </div>

                <form method="post" enctype="multipart/form-data">
                    <div class="file-dropzone" id="dropzone">
                        <span class="fd-icon">&#128193;</span>
                        <div style="margin-bottom:10px;">Drag & drop your CSV file here, or</div>
                        <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                    </div>

                    <div style="margin-top:20px;display:flex;gap:10px;">
                        <button type="submit" class="btn btn-primary">Import Employees</button>
                        <a href="employee.php" class="btn" style="background:#e3e7ee;color:#2c3444;">Cancel</a>
                    </div>
                </form>

                <?= $errorBlock ?>
            </div>
            <?php endif; ?>

<?php include 'includes/footer.php'; ?>
<script>
(function () {
    var zone = document.getElementById('dropzone');
    var input = document.getElementById('csv_file');
    if (zone && input) {
        ['dragenter', 'dragover'].forEach(function (evt) {
            zone.addEventListener(evt, function (e) {
                e.preventDefault();
                zone.classList.add('dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (evt) {
            zone.addEventListener(evt, function (e) {
                e.preventDefault();
                zone.classList.remove('dragover');
            });
        });
        zone.addEventListener('drop', function (e) {
            if (e.dataTransfer.files.length) input.files = e.dataTransfer.files;
        });
    }

    // Select / unselect all rows in the overwrite table
    var all = document.getElementById('check_all');
    if (all) {
        all.addEventListener('change', function () {
            document.querySelectorAll('.apply-box').forEach(function (b) { b.checked = all.checked; });
        });
    }
})();
</script>
