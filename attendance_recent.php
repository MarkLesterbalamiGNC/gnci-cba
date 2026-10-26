<?php
/**
 * Used by the live Scan Attendance page (assets/js/attendance_live.js).
 * Returns today's most recent Time In / Time Out events, newest first, as JSON.
 */
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/auth_role.php';
require_once 'includes/punch_alerts.php';

date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SESSION['role'] ?? null) !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Please sign in again.']);
    exit;
}

$st = $pdo->prepare(
    "SELECT a.id, a.time_in, a.time_out, a.status, a.shift_label, a.shift_order, e.name, e.photo
     FROM attendance a
     JOIN employees e ON e.id = a.employee_id
     WHERE a.log_date = CURDATE() AND e.deleted_at IS NULL"
);
$st->execute();

$events = [];
foreach ($st->fetchAll() as $r) {
    $shift = $r['shift_label'] ?: 'Shift ' . $r['shift_order'];
    if ($r['time_in']) {
        $events[] = ['t' => $r['time_in'],  'key' => $r['id'] . 'i' . $r['time_in'],  'type' => 'in',
                     'name' => $r['name'], 'photo' => $r['photo'], 'status' => $r['status'], 'shift' => $shift];
    }
    if ($r['time_out']) {
        $events[] = ['t' => $r['time_out'], 'key' => $r['id'] . 'o' . $r['time_out'], 'type' => 'out',
                     'name' => $r['name'], 'photo' => $r['photo'], 'status' => null, 'shift' => $shift];
    }
}
usort($events, function ($a, $b) { return strcmp($b['t'], $a['t']); });
$events = array_slice($events, 0, 10);
foreach ($events as &$ev) { $ev['time'] = format_time_am_pm($ev['t']); unset($ev['t']); }

echo json_encode(['ok' => true, 'events' => $events]);

// While the Scan page is open, send the batched Dean alert once it is due (cheap check, usually does nothing).
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
ignore_user_abort(true);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // don't hold up the admin's other pages while emailing
pn_flush($pdo);
