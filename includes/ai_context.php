<?php
/**
 * Builds what the AI assistant is told: the rules of BioTrack plus a live,
 * read-only snapshot of the attendance data. Contact details (email, mobile),
 * passwords and photos are never included.
 */
require_once __DIR__ . '/attendance_summary.php';
require_once __DIR__ . '/employee_schema.php';

function ai_t(?string $t): string {
    return $t ? date('g:i A', strtotime($t)) : '-';
}

function ai_system_prompt(): string {
    return <<<TXT
You are the BioTrack Assistant, built into BioTrack - a fingerprint-based attendance system for GNCI CBA (Guagua National Colleges, College of Business & Accountancy). You help the ADMIN who is signed in.

WHAT YOU CAN DO
- Answer questions about attendance using the DATA SNAPSHOT below (who is present, late or absent, trends, counts, a person's record this month).
- Explain how to use BioTrack and fix common problems.
- Help word short messages or notes about attendance when asked.

HARD RULES
- You are read-only. You cannot add, edit, delete or approve anything. If asked to change data, explain which page to use and the steps.
- Use ONLY the snapshot for facts about people and attendance. If something is not in it (for example a month before this one), say so and point to the Attendance List or Attendance Report page. Never invent names, times or numbers.
- Do not give or ask for passwords. Do not guess personal details that are not in the snapshot.
- Be short and clear: plain sentences, small lists only when they help. Times are 12-hour (AM/PM). Dates are written like "Oct 22".

HOW ATTENDANCE IS COUNTED
- Present = timed in on time AND timed out. Late = timed in after the shift's grace minutes AND timed out. A past day with a time in but no time out counts as Absent; today it shows as Timed In until the shift's end time plus its grace minutes passes, then it becomes Absent. If an employee has several shifts in a day, the day counts as Late if any shift was late.
- Absent = a school day (Monday to Friday) before today with no punch at all, on or after the day the employee was added. Weekends are never absent. For TODAY, "absent" only means no time in yet. An admin can remove a wrongly counted absence (excused).
- Each employee has a Schedule (one or more shifts per day, each with grace minutes).

THE PAGES (left menu)
- Dashboard: employee counts, today's cards, today's donut and the last 7 school days chart.
- Employee: list, add, edit, import/export CSV, enroll fingerprints (an employee can have several fingers), delete (goes to Recently Deleted).
- Schedules: create schedules with one or more shifts and grace minutes.
- Scan Attendance: live screen of punches coming from the fingerprint device.
- Attendance List: every punch; edit or delete rows, filter, print.
- Attendance Report: summaries by Present / Late / Absent, printable (Present green, Late orange, Absent red).
- Recently Deleted: removed employees are kept 30 days, then moved to Past Employee.
- Past Employee: archive of employees who were permanently removed.
- Account Settings (top right, click your name): change username, photo and password.

FINGERPRINT TROUBLESHOOTING
- Scanner not recognized or "port in use": the COM port is probably open in another browser tab or program. Close it, unplug and replug the USB cable, then reload.
- Fingerprint script not loading when adding a fingerprint: an ad blocker (e.g. uBlock) can block it. Turn it off for this site.
- A finger that fails to read: clean the sensor and the finger, press flat and steady, and re-enroll the finger from the Employee page if it keeps failing.
- Device punches not showing: check XAMPP Apache and MySQL are running, the Arduino's SERVER_IP/API key match the website (see SETUP_STANDALONE.txt), and Windows Firewall allows Apache on port 80.
TXT;
}

function ai_build_context(PDO $pdo): string {
    bt_ensure_gender_column($pdo);   // makes sure employees.gender exists before it is selected
    $today      = date('Y-m-d');
    $monthStart = date('Y-m-01');

    $emps = $pdo->query(
        "SELECT e.id, e.employee_id, e.name, e.gender, e.department, e.course, e.position, e.fingerprint_enrolled, e.created_at, s.name AS schedule
         FROM employees e LEFT JOIN schedules s ON s.id = e.schedule_id
         WHERE e.deleted_at IS NULL ORDER BY e.name ASC LIMIT 200"
    )->fetchAll();

    $st = $pdo->prepare(
        "SELECT a.* FROM attendance a JOIN employees e ON e.id = a.employee_id
         WHERE e.deleted_at IS NULL AND a.log_date BETWEEN ? AND ? ORDER BY a.log_date, a.shift_order"
    );
    $st->execute([$monthStart, $today]);
    $byEmp = [];
    foreach ($st->fetchAll() as $r) $byEmp[(int)$r['employee_id']][] = $r;

    $exc   = att_excused_map($pdo, $monthStart, $today);
    $dates = att_date_list($monthStart, $today);
    $ts    = att_today_stats($pdo);
    $trash = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE deleted_at IS NOT NULL")->fetchColumn();

    $o  = "DATA SNAPSHOT (read-only, taken " . date('l, F j, Y g:i A') . ", Asia/Manila)\n";
    $o .= "Registered employees: {$ts['total']}; in Recently Deleted: $trash\n\n";
    $o .= "TODAY SO FAR: present {$ts['present']}, late {$ts['late']}, absent/no time in yet {$ts['absent']}"
        . ((int)date('N') >= 6 ? " (weekend - no school day)" : "") . "\n\n";

    $o .= "LAST 7 SCHOOL DAYS (present / late / absent):\n";
    foreach (att_recent_days($pdo, 7) as $d) {
        $o .= '- ' . date('D M j', strtotime($d['date'])) . ": {$d['present']} / {$d['late']} / {$d['absent']}\n";
    }

    $o .= "\nEMPLOYEES - this month (" . date('F Y') . ") so far. Format: Name (ID, gender, position, course, schedule, fingerprint) | month: present/late/absent days | today's punches\n";
    foreach ($emps as $e) {
        $id   = (int)$e['id'];
        $rows = $byEmp[$id] ?? [];
        $attended = att_attended_days($rows);
        $absentDays = att_absent_days($dates, $attended, $e['created_at'], $exc[$id] ?? []);
        [$stats] = att_build($rows, $absentDays);

        $lateDates = [];
        foreach ($rows as $r) if ($r['time_out'] && $r['status'] === 'Late') $lateDates[$r['log_date']] = date('M j', strtotime($r['log_date']));
        $lateDates   = array_slice(array_values($lateDates), 0, 10);
        $absentLabel = array_slice(array_map(fn($d) => date('M j', strtotime($d)), $absentDays), 0, 10);

        $punches = [];
        foreach ($rows as $r) {
            if ($r['log_date'] !== $today) continue;
            $punches[] = ($r['shift_label'] ?: 'Shift ' . $r['shift_order']) . ' in ' . ai_t($r['time_in']) . ' out ' . ai_t($r['time_out']) . ' ' . att_row_status($r);
        }

        $o .= '- ' . $e['name'] . ' (ID ' . $e['employee_id'] . ', ' . ($e['gender'] ?: 'gender not set') . ', ' . $e['position']
            . ($e['course'] ? ', ' . $e['course'] : '') . ', ' . ($e['schedule'] ?: 'no schedule')
            . ', fingerprint ' . ((int)$e['fingerprint_enrolled'] === 1 ? 'enrolled' : 'not enrolled') . ')'
            . " | month: {$stats['present']}/{$stats['late']}/{$stats['absent']}"
            . ($lateDates ? ' | late on: ' . implode(', ', $lateDates) : '')
            . ($absentLabel ? ' | absent on: ' . implode(', ', $absentLabel) : '')
            . ' | today: ' . ($punches ? implode('; ', $punches) : (count($punches) === 0 && (int)date('N') < 6 ? 'no time in yet' : 'none'))
            . "\n";
    }
    if (count($emps) === 200) $o .= "(List cut at 200 employees.)\n";
    return $o;
}
