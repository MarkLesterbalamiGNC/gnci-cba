<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/csv_helpers.php';
require_admin();

// ?id=<employees.id> exports just that one employee; no id exports everyone.
$id = (int)($_GET['id'] ?? 0);

$sql = "SELECT e.employee_id, e.name, e.department, e.course, e.email, e.position, e.mobile_no,
               s.name AS schedule_name,
               CASE WHEN e.fingerprint_enrolled THEN 'Yes' ELSE 'No' END AS enrolled
        FROM employees e
        LEFT JOIN schedules s ON s.id = e.schedule_id
        WHERE e.deleted_at IS NULL" . ($id ? " AND e.id = ?" : "") . "
        ORDER BY e.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($id ? [$id] : []);
$rows = $stmt->fetchAll();

if ($id && !$rows) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Employee not found.'];
    header('Location: employee.php');
    exit;
}

$fileTag = $id ? 'employee_' . preg_replace('/\D/', '', $rows[0]['employee_id']) : 'employees';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="biotrack_' . $fileTag . '_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads names correctly
fputcsv($out, ['Employee ID', 'Full Name', 'Department', 'Course', 'Email', 'Position', 'Mobile Number', 'Schedule', 'Fingerprint Enrolled']);
foreach ($rows as $r) {
    fputcsv($out, [
        excel_text($r['employee_id']),   // text, so Excel won't show 2.35E+12
        bt_csv_safe($r['name']), $r['department'], $r['course'], bt_csv_safe($r['email']), $r['position'],   // bt_csv_safe: blocks spreadsheet formula injection
        excel_text($r['mobile_no']),     // text, so "+639..." isn't turned into 6.39E+11
        bt_csv_safe($r['schedule_name'] ?? ''), $r['enrolled'],
    ]);
}
fclose($out);
exit;
