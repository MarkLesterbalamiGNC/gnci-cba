<?php
/**
 * schedule_functions.php
 *
 * Everything needed to assign a work schedule (one or more shifts per
 * day) to an employee, and to match a raw biometric punch against that
 * schedule so attendance is recorded against the correct shift.
 *
 * Example: an employee has a Morning shift (7:00 AM-10:00 AM) and an
 * Afternoon shift (4:00 PM-6:00 PM). Without a schedule, the system has
 * no way to know that a 5:45 PM punch is an Afternoon clock-out and not,
 * say, a very late Morning clock-out - it would just overwrite whatever
 * was in the single time_out column. With a schedule assigned, every
 * punch is matched to the shift it actually belongs to, so each shift
 * gets its own accurate Time In / Time Out pair.
 */

require_once __DIR__ . '/../config/db.php';

/** All schedules, most recently created first. */
function get_all_schedules(bool $activeOnly = false): array {
    global $pdo;
    $sql = "SELECT * FROM schedules";
    if ($activeOnly) $sql .= " WHERE status = 1";
    $sql .= " ORDER BY name";
    return $pdo->query($sql)->fetchAll();
}

/** Fetch a single schedule by id, or null. */
function get_schedule(int $scheduleId): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM schedules WHERE id = ?");
    $stmt->execute([$scheduleId]);
    return $stmt->fetch() ?: null;
}

/** Shifts for a schedule, in order (shift_order ASC). */
function get_schedule_shifts(int $scheduleId): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM schedule_shifts WHERE schedule_id = ? ORDER BY shift_order ASC");
    $stmt->execute([$scheduleId]);
    return $stmt->fetchAll();
}

/**
 * Turn a list of shifts (ordered by shift_order) into non-overlapping
 * "catchment windows" that cover the entire day. Each window's boundary
 * sits at the midpoint between one shift's end and the next shift's
 * start, so a punch always lands in exactly one window:
 *
 *   00:00 --- (midpoint) --- Morning[07:00-10:00] --- (midpoint) --- Afternoon[16:00-18:00] --- 23:59:59
 *
 * A punch at 10:40 AM is much closer to the Morning shift's end than to
 * the Afternoon shift's start, so it falls in the Morning window and is
 * recorded as that shift's (late) Time Out - not a bogus Afternoon
 * Time In.
 */
function build_shift_windows(array $shifts): array {
    $n = count($shifts);
    $windows = [];
    for ($i = 0; $i < $n; $i++) {
        $shift = $shifts[$i];
        $windowStart = ($i === 0)
            ? '00:00:00'
            : midpoint_time($shifts[$i - 1]['time_end'], $shift['time_start']);
        $windowEnd = ($i === $n - 1)
            ? '23:59:59'
            : midpoint_time($shift['time_end'], $shifts[$i + 1]['time_start']);
        $windows[] = [
            'shift'        => $shift,
            'window_start' => $windowStart,
            'window_end'   => $windowEnd,
        ];
    }
    return $windows;
}

/** Midpoint between two TIME strings ('HH:MM:SS'), same-day arithmetic. */
function midpoint_time(string $t1, string $t2): string {
    $s1 = strtotime("1970-01-01 {$t1} UTC");
    $s2 = strtotime("1970-01-01 {$t2} UTC");
    // If the second shift wraps past midnight relative to the first, just
    // split the difference without going negative.
    if ($s2 < $s1) $s2 += 86400;
    $mid = intdiv($s1 + $s2, 2) % 86400;
    return gmdate('H:i:s', $mid);
}

/**
 * Given an employee's ordered shifts and a punch time ('HH:MM:SS'),
 * return the matching shift row, or null if the employee has no shifts
 * defined (no schedule assigned / empty schedule).
 */
function resolve_shift_for_punch(array $shifts, string $time): ?array {
    if (!$shifts) return null;
    $windows = build_shift_windows($shifts);
    foreach ($windows as $w) {
        if ($time >= $w['window_start'] && $time <= $w['window_end']) {
            return $w['shift'];
        }
    }
    // Shouldn't happen (windows cover the full day), but fall back to
    // the closest shift by start time just in case.
    return $shifts[0];
}

/** Present vs Late for a shift, based on its start time + grace period. */
function compute_shift_status(string $time, array $shift): string {
    $cutoff = date('H:i:s', strtotime($shift['time_start']) + ((int)$shift['grace_minutes'] * 60));
    return ($time > $cutoff) ? 'Late' : 'Present';
}

/**
 * Fold a raw punch into the attendance table, matched against the
 * employee's assigned schedule (if any).
 *
 * - No schedule assigned: falls back to the original behaviour (one
 *   row per day, first punch = Time In, latest punch = Time Out),
 *   using shift_order = 1 as a single catch-all "shift".
 * - Schedule assigned: the punch is matched to a shift via
 *   resolve_shift_for_punch(). The first punch recorded for that
 *   employee/day/shift becomes Time In (status computed against the
 *   shift's start + grace period); a later punch within the same
 *   employee/day/shift updates Time Out.
 */
function record_attendance_for_schedule(int $employeeId, ?int $scheduleId, string $date, string $time): void {
    global $pdo;

    $shifts = $scheduleId ? get_schedule_shifts($scheduleId) : [];
    $shift  = $shifts ? resolve_shift_for_punch($shifts, $time) : null;

    $shiftOrder = $shift ? (int)$shift['shift_order'] : 1;
    $shiftId    = $shift ? (int)$shift['id'] : null;
    $shiftLabel = $shift ? $shift['label'] : null;

    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND log_date = ? AND shift_order = ?");
    $stmt->execute([$employeeId, $date, $shiftOrder]);
    $existing = $stmt->fetch();

    if (!$existing) {
        // No schedule/shift matched (employee has no schedule assigned yet) -
        // fall back to the original default 9:00 AM cutoff.
        $status = $shift ? compute_shift_status($time, $shift) : (($time > '09:00:00') ? 'Late' : 'Present');
        $stmt = $pdo->prepare(
            "INSERT INTO attendance (employee_id, schedule_shift_id, shift_order, shift_label, log_date, time_in, status, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'device')"
        );
        $stmt->execute([$employeeId, $shiftId, $shiftOrder, $shiftLabel, $date, $time, $status]);
    } elseif (!$existing['time_out'] || $time > $existing['time_out']) {
        // Don't let an earlier stray punch (e.g. re-scan) overwrite time_in
        if ($time <= $existing['time_in']) return;
        $stmt = $pdo->prepare("UPDATE attendance SET time_out = ?, schedule_shift_id = ?, shift_label = ? WHERE id = ?");
        $stmt->execute([$time, $shiftId, $shiftLabel, $existing['id']]);
    }
}
