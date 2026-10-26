<?php
/**
 * Daily attendance report for the Dean.
 *
 *   dr_roster()      -> everyone's status (Present / Late / Absent ...) for one day
 *   dr_render()      -> the report as HTML (email body, printable file, or on-screen)
 *   dr_send()        -> emails it to the Dean (with a print-ready file attached) and logs the result
 *
 * Uses the SAME rules as the rest of BioTrack (includes/attendance_summary.php):
 *   Present / Late = timed in AND out.  Absent = school day (Mon-Fri) with no finished time in/out,
 *   unless an admin removed (excused) that absence.  Weekends are never counted as absences.
 * Needs: $pdo (config/db.php) and includes/attendance_summary.php already loaded.
 */

require_once __DIR__ . '/attendance_summary.php';
require_once __DIR__ . '/mailer.php';

date_default_timezone_set('Asia/Manila');

/* ------------------------------------------------------------------ tables + settings */

function dr_ensure_tables(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS daily_report_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_date DATE NOT NULL,
        sent_to VARCHAR(150) NOT NULL,
        ok TINYINT(1) NOT NULL DEFAULT 0,
        message VARCHAR(255) DEFAULT NULL,
        trigger_by VARCHAR(40) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_report_date (report_date, ok)
    ) ENGINE=InnoDB");
}

function dr_setting(PDO $pdo, string $key, string $default = ''): string {
    dr_ensure_tables($pdo);
    $st = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function dr_set_setting(PDO $pdo, string $key, string $value): void {
    dr_ensure_tables($pdo);
    $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $value]);
}

/** ['name' => ..., 'email' => ...] of the Dean who receives the report. */
function dr_dean(PDO $pdo): array {
    return [
        'name'  => dr_setting($pdo, 'dean_name', 'Dean'),
        'email' => dr_setting($pdo, 'dean_email', ''),
    ];
}

/* ------------------------------------------------------------------ the data */

/**
 * Everyone's status on $date.
 * Returns ['date', 'school_day', 'rows' => [...], 'counts' => [...]].
 * Each row: employee_id, name, department, course, status, ins[], outs[]
 * Status is one of: Present, Late, Absent, Timed In, Excused, No record (weekend / before the employee existed).
 */
function dr_roster(PDO $pdo, string $date): array {
    $isSchoolDay = (int)date('N', strtotime($date)) < 6;
    $today = date('Y-m-d');

    $emps = $pdo->query("SELECT id, employee_id, name, department, course, created_at
                         FROM employees WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll();

    $st = $pdo->prepare("SELECT * FROM attendance WHERE log_date = ? ORDER BY employee_id, shift_order");
    $st->execute([$date]);
    $byEmp = [];
    foreach ($st->fetchAll() as $r) $byEmp[(int)$r['employee_id']][] = $r;

    $excused = att_excused_map($pdo, $date, $date);

    $rows = [];
    $counts = ['Present' => 0, 'Late' => 0, 'Absent' => 0, 'Timed In' => 0, 'Excused' => 0, 'No record' => 0];

    foreach ($emps as $e) {
        $id = (int)$e['id'];
        $existed = date('Y-m-d', strtotime($e['created_at'])) <= $date;
        $recs = $byEmp[$id] ?? [];

        $finished = false; $late = false; $open = false;
        $ins = []; $outs = [];
        foreach ($recs as $r) {
            if (!empty($r['time_in']) && !empty($r['time_out'])) {
                $finished = true;
                if ($r['status'] === 'Late') $late = true;
                $ins[]  = dr_time($r['time_in']);
                $outs[] = dr_time($r['time_out']);
            } elseif (!empty($r['time_in'])) {
                if (att_row_status($r) === 'Timed In') $open = true;
                $ins[]  = dr_time($r['time_in']);
                $outs[] = '—';
            }
        }

        if ($finished)                         $status = $late ? 'Late' : 'Present';
        elseif ($open)                         $status = 'Timed In';
        elseif (!$existed || !$isSchoolDay || $date > $today) $status = 'No record';
        elseif (isset($excused[$id][$date]))   $status = 'Excused';
        else                                   $status = 'Absent';

        $counts[$status]++;
        $rows[] = [
            'employee_id' => $e['employee_id'], 'name' => $e['name'],
            'department'  => $e['department'],  'course' => (string)($e['course'] ?? ''),
            'status' => $status, 'ins' => $ins, 'outs' => $outs,
        ];
    }

    return ['date' => $date, 'school_day' => $isSchoolDay, 'rows' => $rows, 'counts' => $counts, 'total' => count($emps)];
}

/* ------------------------------------------------------------------ the look */

/** 07:05:00 -> 7:05 AM (own copy so the cron script doesn't need to start a login session). */
function dr_time($t): string {
    return $t ? date('g:i A', strtotime((string)$t)) : '—';
}

function dr_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Colors per status (kept inline-friendly so the email looks right in Gmail / Outlook). */
function dr_colors(string $status): array {
    switch ($status) {
        case 'Present':  return ['#e7f6ec', '#17703a'];
        case 'Late':     return ['#fff4d6', '#8a5a00'];
        case 'Absent':   return ['#fde8e8', '#a61b1b'];
        default:         return ['#eceff4', '#4a5568'];
    }
}

/**
 * Build the report HTML.
 *   $mode 'email' = compact, inline styles (email body)
 *   $mode 'file'  = full page with print styles + a Print button (attachment the Dean can open and print)
 */
function dr_render(array $rep, string $mode = 'email', string $schoolName = 'GNCI - College of Business and Accountancy'): string {
    $dateLong = date('l, F j, Y', strtotime($rep['date']));
    $c = $rep['counts'];

    $order = ['Absent', 'Late', 'Present', 'Timed In', 'Excused', 'No record'];
    $grouped = array_fill_keys($order, []);
    foreach ($rep['rows'] as $r) $grouped[$r['status']][] = $r;

    $th = 'style="text-align:left;padding:7px 9px;background:#1f2a44;color:#ffffff;font-size:12px;border:1px solid #1f2a44;"';
    $td = 'style="padding:7px 9px;border:1px solid #d9dee8;font-size:13px;vertical-align:top;"';

    $h = '';
    if ($mode === 'file') {
        $h .= '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Daily Attendance Report - ' . dr_h($rep['date']) . '</title>'
            . '<style>body{font-family:Arial,Helvetica,sans-serif;color:#1c2333;margin:0;background:#f3f5f9}'
            . '.sheet{max-width:900px;margin:0 auto;padding:24px;background:#fff}'
            . '.bar{max-width:900px;margin:0 auto;padding:12px 24px;text-align:right}'
            . '.bar button{background:#1f2a44;color:#fff;border:0;border-radius:6px;padding:10px 18px;font-size:14px;cursor:pointer}'
            . 'tr{page-break-inside:avoid}h3{page-break-after:avoid}'
            . '@media print{body{background:#fff}.bar{display:none}.sheet{padding:0;max-width:none}'
            . '*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}'
            . '@page{margin:14mm}</style></head><body>'
            . '<div class="bar"><button onclick="window.print()">&#128424; Print this report</button></div><div class="sheet">';
    } else {
        $h .= '<div style="font-family:Arial,Helvetica,sans-serif;color:#1c2333;max-width:760px;margin:0 auto;">';
    }

    $h .= '<div style="border-bottom:3px solid #1f2a44;padding-bottom:10px;margin-bottom:14px;">'
        . '<div style="font-size:12px;color:#5b6577;letter-spacing:.5px;text-transform:uppercase;">' . dr_h($schoolName) . '</div>'
        . '<div style="font-size:22px;font-weight:bold;margin-top:2px;">Daily Attendance Report</div>'
        . '<div style="font-size:14px;color:#3b4558;margin-top:2px;">' . dr_h($dateLong) . '</div></div>';

    if (!$rep['school_day']) {
        $h .= '<p style="background:#eceff4;padding:10px 12px;border-radius:6px;font-size:13px;">This date is a weekend, so nobody is counted as absent.</p>';
    }

    // Summary boxes
    $boxes = [['Present', $c['Present']], ['Late', $c['Late']], ['Absent', $c['Absent']], ['Total employees', $rep['total']]];
    $h .= '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;margin-bottom:16px;border-collapse:separate;border-spacing:6px 0;"><tr>';
    foreach ($boxes as [$label, $n]) {
        [$bg, $fg] = dr_colors($label);
        $h .= '<td style="background:' . $bg . ';color:' . $fg . ';text-align:center;padding:10px 4px;border-radius:6px;">'
            . '<div style="font-size:24px;font-weight:bold;">' . (int)$n . '</div>'
            . '<div style="font-size:12px;">' . dr_h($label) . '</div></td>';
    }
    $h .= '</tr></table>';

    $titles = ['Absent' => 'Absent', 'Late' => 'Late', 'Present' => 'Present (on time)',
               'Timed In' => 'Timed in, no time out yet', 'Excused' => 'Absence removed by admin (excused)',
               'No record' => 'No record'];

    foreach ($order as $status) {
        $list = $grouped[$status];
        if (!$list) continue;
        if ($status === 'No record') continue;   // weekends / people who joined later: nothing useful to show the Dean
        [$bg, $fg] = dr_colors($status);
        $h .= '<h3 style="margin:18px 0 6px;font-size:15px;"><span style="background:' . $bg . ';color:' . $fg . ';padding:3px 10px;border-radius:12px;">'
            . dr_h($titles[$status]) . ' &ndash; ' . count($list) . '</span></h3>';
        $h .= '<table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;"><thead><tr>'
            . '<th ' . $th . '>#</th><th ' . $th . '>Employee ID</th><th ' . $th . '>Name</th><th ' . $th . '>Course</th>'
            . ($status === 'Absent' ? '' : '<th ' . $th . '>Time In</th><th ' . $th . '>Time Out</th>')
            . '</tr></thead><tbody>';
        foreach ($list as $i => $r) {
            $h .= '<tr><td ' . $td . '>' . ($i + 1) . '</td><td ' . $td . '>' . dr_h($r['employee_id']) . '</td>'
                . '<td ' . $td . '><b>' . dr_h($r['name']) . '</b></td><td ' . $td . '>' . dr_h($r['course'] !== '' ? $r['course'] : '—') . '</td>';
            if ($status !== 'Absent') {
                $h .= '<td ' . $td . '>' . ($r['ins'] ? implode('<br>', array_map('dr_h', $r['ins'])) : '—') . '</td>'
                    . '<td ' . $td . '>' . ($r['outs'] ? implode('<br>', array_map('dr_h', $r['outs'])) : '—') . '</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
    }

    if ($rep['total'] === 0) {
        $h .= '<p style="color:#5b6577;">No employees are registered yet.</p>';
    }

    $h .= '<p style="margin-top:20px;font-size:11px;color:#7a8497;border-top:1px solid #d9dee8;padding-top:8px;">'
        . 'Generated by BioTrack on ' . dr_h(date('F j, Y g:i A')) . '. Present / Late = timed in and out. '
        . 'Absent = school day (Mon&ndash;Fri) without a completed time in and time out.</p>';

    $h .= $mode === 'file' ? '</div></body></html>' : '</div>';
    return $h;
}

/** Short plain-text version (shown by email apps that don't show HTML). */
function dr_text(array $rep): string {
    $c = $rep['counts'];
    $t = 'Daily Attendance Report - ' . date('l, F j, Y', strtotime($rep['date'])) . "\n"
       . 'Present: ' . $c['Present'] . '  Late: ' . $c['Late'] . '  Absent: ' . $c['Absent'] . '  Total: ' . $rep['total'] . "\n\n";
    foreach (['Absent', 'Late', 'Present'] as $s) {
        $names = array_map(fn($r) => $r['name'], array_filter($rep['rows'], fn($r) => $r['status'] === $s));
        $t .= strtoupper($s) . ' (' . count($names) . "):\n" . ($names ? '  ' . implode("\n  ", $names) : '  -') . "\n\n";
    }
    return $t . "Open the attached HTML file in your browser to print the full report.\n";
}

/* ------------------------------------------------------------------ sending */

/**
 * Email the report for $date to the Dean and write the result to daily_report_log.
 * Returns [bool ok, string message].
 */
function dr_send(PDO $pdo, string $date, string $triggeredBy = 'manual'): array {
    dr_ensure_tables($pdo);
    $dean = dr_dean($pdo);
    if ($dean['email'] === '' || !filter_var($dean['email'], FILTER_VALIDATE_EMAIL)) {
        return [false, "The Dean's email address is not set yet."];
    }

    $rep  = dr_roster($pdo, $date);
    $c    = $rep['counts'];
    $subj = 'Daily Attendance Report - ' . date('M j, Y', strtotime($date))
          . ' (Present ' . $c['Present'] . ', Late ' . $c['Late'] . ', Absent ' . $c['Absent'] . ')';

    $intro = '<p style="font-family:Arial,sans-serif;font-size:14px;">Good day, ' . dr_h($dean['name']) . '. Here is the attendance for '
           . dr_h(date('l, F j, Y', strtotime($date))) . '. To print it, open the attached file in your browser and click '
           . '<b>Print this report</b>.</p>';

    [$ok, $err] = bt_send_mail(
        $dean['email'], $dean['name'], $subj,
        $intro . dr_render($rep, 'email'),
        dr_text($rep),
        [['name' => 'Attendance_' . $date . '.html', 'type' => 'text/html; charset=UTF-8', 'data' => dr_render($rep, 'file')]]
    );

    $pdo->prepare("INSERT INTO daily_report_log (report_date, sent_to, ok, message, trigger_by) VALUES (?, ?, ?, ?, ?)")
        ->execute([$date, $dean['email'], $ok ? 1 : 0, $ok ? 'Sent' : mb_substr($err, 0, 250), $triggeredBy]);

    if ($ok && function_exists('pn_mark_covered')) pn_mark_covered($pdo, $date);   // Dean has these scans now

    return $ok ? [true, 'Report for ' . date('M j, Y', strtotime($date)) . ' sent to ' . $dean['email'] . '.'] : [false, $err];
}

/** Has the report for $date already been emailed successfully? */
function dr_already_sent(PDO $pdo, string $date): bool {
    dr_ensure_tables($pdo);
    $st = $pdo->prepare("SELECT COUNT(*) FROM daily_report_log WHERE report_date = ? AND ok = 1");
    $st->execute([$date]);
    return (int)$st->fetchColumn() > 0;
}

// Time in / time out alert digest (uses the helpers above)
require_once __DIR__ . '/punch_alerts.php';
