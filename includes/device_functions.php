<?php
/**
 * device_functions.php
 * Shared helpers for the ZKTeco ADMS bridge (K14 and other ADMS-ready models).
 *
 * The device itself talks to three URLs on your server:
 *   GET/POST  /iclock/cdata        -> handshake + pushes attendance/user/fingerprint data
 *   GET       /iclock/getrequest   -> device polls "any commands for me?"
 *   POST      /iclock/devicecmd    -> device reports back whether a command succeeded
 *
 * All three require this file, which in turn loads your existing config/db.php
 * so $pdo is available exactly like the rest of the app.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/schedule_functions.php';

/** Look up + "touch" a device by its serial number. Returns null if not registered. */
function device_authenticate(string $sn): ?array {
    global $pdo;
    $sn = trim($sn);
    if ($sn === '') return null;

    $stmt = $pdo->prepare("SELECT * FROM devices WHERE UPPER(TRIM(device_sn)) = UPPER(?)");
    $stmt->execute([$sn]);
    $device = $stmt->fetch();

    if ($device) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $upd = $pdo->prepare("UPDATE devices SET last_seen = NOW(), last_ip = ? WHERE id = ?");
        $upd->execute([$ip, $device['id']]);
    }
    return $device ?: null;
}

/** Audit trail of every raw line the device sends, regardless of what we did with it. */
function device_log_raw(string $sn, string $uid, string $payload): void {
    global $pdo;
    $stmt = $pdo->prepare(
        "INSERT INTO device_logs (device_sn, fingerprint_uid, punch_time, raw_payload) VALUES (?, ?, NOW(), ?)"
    );
    $stmt->execute([$sn, $uid, $payload]);
}

/** Simple Late/Present rule based on time-in. Adjust the cutoff to your office hours. */
function compute_status(string $timeIn, string $cutoff = '09:00:00'): string {
    return ($timeIn > $cutoff) ? 'Late' : 'Present';
}

/**
 * Fold a raw punch into the attendance table.
 *
 * If the employee has a schedule assigned (one or more shifts, e.g. a
 * Morning shift + an Afternoon shift), the punch is matched against
 * that schedule so it's recorded against the correct shift - see
 * schedule_functions.php::record_attendance_for_schedule(). Employees
 * with no schedule assigned keep the original single Time In / Time
 * Out per day behaviour.
 */
function record_attendance(int $employeeId, string $date, string $time): void {
    global $pdo;

    $stmt = $pdo->prepare("SELECT schedule_id, deleted_at FROM employees WHERE id = ?");
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch();
    // Ignore punches from employees that don't exist or are in Recently Deleted
    if (!$emp || $emp['deleted_at'] !== null) return;
    $scheduleId = $emp['schedule_id'];

    record_attendance_for_schedule($employeeId, $scheduleId ?: null, $date, $time);
}

/** Queue a raw ADMS command string for a specific device; it's picked up next poll. */
function enqueue_device_command(string $deviceSn, string $command): int {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO device_commands (device_sn, command, status) VALUES (?, ?, 'pending')");
    $stmt->execute([$deviceSn, $command]);
    return (int)$pdo->lastInsertId();
}

/**
 * Called right after an employee is added/edited in your site.
 * Pushes a "create/update this PIN+Name" command to every registered device,
 * so staff only need to walk up and press their finger to finish enrollment
 * (no need to type them in on the device's tiny keypad).
 */
function push_employee_to_devices(array $employee): void {
    global $pdo;
    $devices = $pdo->query("SELECT device_sn FROM devices")->fetchAll();
    if (!$devices) return;

    $pin  = $employee['employee_id'];
    $name = str_replace("\t", ' ', $employee['name']);
    $cmd  = "DATA UPDATE USERINFO PIN={$pin}\tName={$name}\tPri=0\tPasswd=\tCard=\tGrp=1\tTZ=0000000000000000";

    foreach ($devices as $d) {
        enqueue_device_command($d['device_sn'], $cmd);
    }
}
