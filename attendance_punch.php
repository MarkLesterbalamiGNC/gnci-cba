<?php
/**
 * Called by assets/js/attendance_scan.js every time the Arduino recognises a finger.
 * POST: scanner_id = the sensor slot that matched (employee_fingerprints.scanner_id)
 * Answers with JSON:
 *   {"ok":true,  "result":"in"|"out"|"duplicate"|"ignored", "name":..., "employee_id":..., "photo":...,
 *                 "time":"7:05 AM", "status":"On time"|"Late", "shift":"Morning"}
 *   {"ok":false, "error":"..."}
 */
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/fingerprint_functions.php';
require_once 'includes/device_functions.php';      // record_attendance() + schedule matching
require_once 'includes/auth_role.php';             // format_time_am_pm()
require_once 'includes/punch_alerts.php';          // queues the (batched) Dean alert

date_default_timezone_set('Asia/Manila');           // punch time must be local time
header('Content-Type: application/json; charset=utf-8');

function punch_reply(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (($_SESSION['role'] ?? null) !== 'admin') punch_reply(['ok' => false, 'error' => 'Please sign in again.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')   punch_reply(['ok' => false, 'error' => 'Invalid request.'], 405);

$uid = (int)($_POST['scanner_id'] ?? 0);
if ($uid < 1) punch_reply(['ok' => false, 'error' => 'Invalid fingerprint.'], 400);

fp_ensure_table($pdo);

// Which employee owns this scanner slot?
$st = $pdo->prepare(
    "SELECT e.id, e.name, e.employee_id, e.photo
     FROM employee_fingerprints f
     JOIN employees e ON e.id = f.employee_id AND e.deleted_at IS NULL
     WHERE f.scanner_id = ?"
);
$st->execute([$uid]);
$emp = $st->fetch();
if (!$emp) {
    punch_reply(['ok' => false, 'error' => 'This fingerprint (slot ' . $uid . ') is not linked to any employee.']);
}

$empPk = (int)$emp['id'];
$date  = date('Y-m-d');
$now   = date('H:i:s');

// Snapshot of today's rows, so we can tell what this punch changed
$snap = function () use ($pdo, $empPk, $date) {
    $q = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND log_date = ?");
    $q->execute([$empPk, $date]);
    $out = [];
    foreach ($q->fetchAll() as $r) $out[(int)$r['id']] = $r;
    return $out;
};
$before = $snap();

// Same finger touched again within 60 seconds -> do nothing (stops accidental double scans
// from turning a Time In into a Time Out a few seconds later).
$last = null;
foreach ($before as $r) {
    foreach (['time_in', 'time_out'] as $k) {
        if ($r[$k] && ($last === null || $r[$k] > $last)) $last = $r[$k];
    }
}
if ($last !== null && (strtotime($now) - strtotime($last)) < 60) {
    punch_reply([
        'ok' => true, 'result' => 'duplicate',
        'name' => $emp['name'], 'employee_id' => $emp['employee_id'], 'photo' => $emp['photo'],
        'time' => format_time_am_pm($last),
    ]);
}

record_attendance($empPk, $date, $now);

$after = $snap();
$result = 'ignored'; $row = null;
foreach ($after as $id => $r) {
    if (!isset($before[$id])) { $result = 'in'; $row = $r; break; }                      // new row = Time In
    if ($before[$id]['time_out'] !== $r['time_out']) { $result = 'out'; $row = $r; break; } // Time Out updated
}

if ($result === 'in' || $result === 'out') {
    pn_queue($pdo, $empPk, $result, $now, $row['status'] ?? null);   // goes out in the next digest, not now
}

punch_reply([
    'ok'          => true,
    'result'      => $result,
    'name'        => $emp['name'],
    'employee_id' => $emp['employee_id'],
    'photo'       => $emp['photo'],
    'time'        => format_time_am_pm($now),
    'status'      => $result === 'in' ? (($row['status'] ?? '') === 'Late' ? 'Late' : 'On time') : null,
    'shift'       => $row ? ($row['shift_label'] ?: 'Shift ' . $row['shift_order']) : null,
]);
