<?php
/**
 * device_punch.php  -  called directly by the Arduino (W5100 Ethernet shield).
 *
 * This is the same job attendance_punch.php does for the web Scan page, but it needs
 * NO browser and NO login. The Arduino proves who it is with the shared key from
 * config/device_config.php.
 *
 * Request  (POST):  key=<DEVICE_API_KEY>&slot=<fingerprint slot that matched>
 * Reply (one line of plain text, fields separated by "|"):
 *     IN|Juan Dela Cruz|7:05 AM|On time      -> Time In recorded
 *     IN|Juan Dela Cruz|9:20 AM|Late
 *     OUT|Juan Dela Cruz|5:01 PM|            -> Time Out recorded
 *     DUP|Juan Dela Cruz|7:05 AM|            -> scanned again within 60 s, nothing changed
 *     NONE|Juan Dela Cruz|7:05 AM|           -> accepted, but nothing new to record
 *     ERR|KEY | ERR|NOLINK | ERR|BAD | ERR|SERVER
 */
require_once __DIR__ . '/config/device_config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/fingerprint_functions.php';
require_once __DIR__ . '/includes/device_functions.php';   // record_attendance() + schedule matching
require_once __DIR__ . '/includes/security.php';           // rate-limit helpers (no session / redirects here)
require_once __DIR__ . '/includes/punch_alerts.php';       // queues the (batched) Dean alert

date_default_timezone_set('Asia/Manila');

function dp_reply(string $line): void {
    header('Content-Type: text/plain; charset=utf-8');
    echo $line . "\n";
    exit;
}

/** Plain ASCII, no "|" - the Arduino LCD can only show simple characters. */
function dp_clean(string $s, int $max = 24): string {
    $s = preg_replace('/[^\x20-\x7E]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
    $s = trim(str_replace('|', ' ', (string)$s));
    return substr($s, 0, $max);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') dp_reply('ERR|BAD');

// ---- who is calling?
if (!empty($DEVICE_ALLOWED_IPS) && !in_array($_SERVER['REMOTE_ADDR'] ?? '', $DEVICE_ALLOWED_IPS, true)) {
    dp_reply('ERR|KEY');
}
$key = (string)($_POST['key'] ?? '');
$dpIp = bt_client_ip();
// Too many wrong keys from one address -> stop answering for a while (blocks key-guessing)
if (bt_rl_count($pdo, 'device_key', $dpIp, 600) >= 10) dp_reply('ERR|KEY');
if (DEVICE_API_KEY === '' || !hash_equals(DEVICE_API_KEY, $key)) {
    bt_rl_hit($pdo, 'device_key', $dpIp);
    dp_reply('ERR|KEY');
}
if (DEVICE_API_KEY === 'change-me-1234') error_log('BioTrack: device_punch.php is still using the DEFAULT DEVICE_API_KEY - change it in config/device_config.php and in the Arduino sketch.');

$uid = (int)($_POST['slot'] ?? 0);
if ($uid < 1) dp_reply('ERR|BAD');

try {
    fp_ensure_table($pdo);

    // Which employee owns this scanner slot?
    $st = $pdo->prepare(
        "SELECT e.id, e.name
         FROM employee_fingerprints f
         JOIN employees e ON e.id = f.employee_id AND e.deleted_at IS NULL
         WHERE f.scanner_id = ?"
    );
    $st->execute([$uid]);
    $emp = $st->fetch();
    if (!$emp) dp_reply('ERR|NOLINK');

    $empPk = (int)$emp['id'];
    $name  = dp_clean($emp['name']);
    $date  = date('Y-m-d');
    $now   = date('H:i:s');

    $snap = function () use ($pdo, $empPk, $date) {
        $q = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND log_date = ?");
        $q->execute([$empPk, $date]);
        $out = [];
        foreach ($q->fetchAll() as $r) $out[(int)$r['id']] = $r;
        return $out;
    };
    $before = $snap();

    // Same finger again within 60 seconds -> do nothing (same rule as the web scan page)
    $last = null;
    foreach ($before as $r) {
        foreach (['time_in', 'time_out'] as $k) {
            if ($r[$k] && ($last === null || $r[$k] > $last)) $last = $r[$k];
        }
    }
    if ($last !== null && (strtotime($now) - strtotime($last)) < 60) {
        dp_reply('DUP|' . $name . '|' . date('g:i A', strtotime($last)) . '|');
    }

    record_attendance($empPk, $date, $now);

    $after = $snap();
    $result = 'NONE'; $row = null;
    foreach ($after as $id => $r) {
        if (!isset($before[$id])) { $result = 'IN';  $row = $r; break; }
        if ($before[$id]['time_out'] !== $r['time_out']) { $result = 'OUT'; $row = $r; break; }
    }

    if ($result === 'IN' || $result === 'OUT') {
        pn_queue($pdo, $empPk, strtolower($result), $now, $row['status'] ?? null);   // goes out in the next digest, not now
    }

    dp_reply($result . '|' . $name . '|' . date('g:i A', strtotime($now)) . '|' .
             ($result === 'IN' ? (($row['status'] ?? '') === 'Late' ? 'Late' : 'On time') : ''));

} catch (Throwable $e) {
    dp_reply('ERR|SERVER');
}
