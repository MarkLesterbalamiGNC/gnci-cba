<?php
/**
 * Time In / Time Out alerts for the Dean - ONE tidy digest email instead of one email per scan.
 *
 * How it stays clean (no spam):
 *   1. Every Time In / Time Out is only written to a small waiting list (punch_alert_queue).
 *      Nothing is emailed at scan time, so scanning stays instant.
 *   2. A digest goes out once the oldest waiting scan is older than the chosen interval
 *      (1 min / 15 min / 30 min / 1 hour / 2 hours) - so at most ONE email per interval, holding
 *      every scan from that window in one table. No scans = no email.
 *   3. If sending fails, it waits 10 minutes before trying again (no retry storm), and the
 *      scans stay on the list so nothing is lost.
 *   4. Only today's scans are ever emailed. Anything older is dropped silently.
 *   5. When the Daily Report is emailed for a day, that day's waiting scans are cleared
 *      (the Dean already has them in the report).
 *   6. Two requests can never send the same digest twice (atomic lock).
 *
 * Who triggers a digest:  send_punch_alerts.php (scheduled task)  and the live Scan page
 * (attendance_recent.php) while it is open  and  the "Send now" button on the Daily Report page.
 *
 *   pn_queue()       record one scan (called by device_punch.php and attendance_punch.php)
 *   pn_flush()       send the digest if one is due
 *   pn_settings()    read the on/off + interval + which events
 *   pn_status()      numbers shown on the Daily Report page
 */

require_once __DIR__ . '/daily_report.php';   // dr_setting(), dr_set_setting(), dr_dean(), dr_h(), mailer

const PN_INTERVALS = [1 => 'Every minute (demo)', 15 => 'Every 15 minutes', 30 => 'Every 30 minutes', 60 => 'Every hour', 120 => 'Every 2 hours'];
const PN_EVENTS    = [
    'both' => 'Time In and Time Out',
    'in'   => 'Time In only',
    'out'  => 'Time Out only',
    'late' => 'Late arrivals only',
];

function pn_ensure_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    dr_ensure_tables($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS punch_alert_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        log_date DATE NOT NULL,
        punch_time TIME NOT NULL,
        event VARCHAR(3) NOT NULL,
        employee_name VARCHAR(120) NOT NULL,
        employee_code VARCHAR(60) NOT NULL DEFAULT '',
        course VARCHAR(20) NOT NULL DEFAULT '',
        status VARCHAR(20) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        sent_at DATETIME DEFAULT NULL,
        KEY idx_unsent (sent_at, log_date, created_at)
    ) ENGINE=InnoDB");
}

/** ['enabled' => bool, 'interval' => minutes, 'events' => both|in|out|late] */
function pn_settings(PDO $pdo): array {
    $interval = (int)dr_setting($pdo, 'alert_interval', '30');
    if (!isset(PN_INTERVALS[$interval])) $interval = 30;
    $events = dr_setting($pdo, 'alert_events', 'both');
    if (!isset(PN_EVENTS[$events])) $events = 'both';
    return [
        'enabled'  => dr_setting($pdo, 'alert_enabled', '0') === '1',
        'interval' => $interval,
        'events'   => $events,
    ];
}

/** Alerts are on AND there is somewhere to send them. */
function pn_active(PDO $pdo, ?array $cfg = null): bool {
    $cfg = $cfg ?? pn_settings($pdo);
    if (!$cfg['enabled'] || !bt_mail_configured()) return false;
    $dean = dr_dean($pdo);
    return $dean['email'] !== '' && filter_var($dean['email'], FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Put one scan on the waiting list. Never throws - a problem here must never stop a scan.
 * $event: 'in' or 'out'.  $status: 'Late' / 'On Time' for a time in.
 */
function pn_queue(PDO $pdo, int $empPk, string $event, string $time, ?string $status = null): void {
    try {
        $cfg = pn_settings($pdo);
        if (!pn_active($pdo, $cfg)) return;
        $event = $event === 'out' ? 'out' : 'in';
        $isLate = $event === 'in' && strcasecmp((string)$status, 'Late') === 0;

        if ($cfg['events'] === 'in'   && $event !== 'in')  return;
        if ($cfg['events'] === 'out'  && $event !== 'out') return;
        if ($cfg['events'] === 'late' && !$isLate)         return;

        pn_ensure_table($pdo);
        $st = $pdo->prepare("SELECT name, employee_id, course FROM employees WHERE id = ?");
        $st->execute([$empPk]);
        $e = $st->fetch();
        if (!$e) return;

        $pdo->prepare("INSERT INTO punch_alert_queue (log_date, punch_time, event, employee_name, employee_code, course, status, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([date('Y-m-d'), $time, $event, mb_substr((string)$e['name'], 0, 120), mb_substr((string)$e['employee_id'], 0, 60),
                       mb_substr((string)($e['course'] ?? ''), 0, 20), $isLate ? 'Late' : ($event === 'in' ? 'On time' : null), date('Y-m-d H:i:s')]);
    } catch (Throwable $ex) {
        error_log('BioTrack punch alert queue: ' . $ex->getMessage());
    }
}

/** Waiting-list numbers for the Daily Report page. */
function pn_status(PDO $pdo): array {
    pn_ensure_table($pdo);
    $cfg = pn_settings($pdo);
    $q = $pdo->prepare("SELECT COUNT(*) c, MIN(created_at) oldest FROM punch_alert_queue WHERE sent_at IS NULL AND log_date = ?");
    $q->execute([date('Y-m-d')]);
    $row = $q->fetch();
    $waiting = (int)($row['c'] ?? 0);
    $due = null;
    if ($waiting > 0 && !empty($row['oldest'])) {
        $due = strtotime($row['oldest']) + $cfg['interval'] * 60;
        $retry = (int)dr_setting($pdo, 'alert_next_try', '0');
        if ($retry > $due) $due = $retry;
    }
    return [
        'waiting'   => $waiting,
        'due_at'    => $due,
        'last_sent' => (int)dr_setting($pdo, 'alert_last_sent', '0'),
        'error'     => dr_setting($pdo, 'alert_last_error', ''),
    ];
}

/** Atomic "I am the one sending" lock. Returns true if we got it. */
function pn_lock(PDO $pdo): bool {
    $now = time();
    $pdo->prepare("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('alert_lock', '0')")->execute();
    $st = $pdo->prepare("UPDATE app_settings SET setting_value = ? WHERE setting_key = 'alert_lock' AND CAST(setting_value AS UNSIGNED) < ?");
    $st->execute([(string)$now, $now - 120]);   // a stuck lock frees itself after 2 minutes
    return $st->rowCount() === 1;
}

function pn_unlock(PDO $pdo): void {
    $pdo->prepare("UPDATE app_settings SET setting_value = '0' WHERE setting_key = 'alert_lock'")->execute();
}

/**
 * Send the digest if one is due (or always, with $force, as long as something is waiting).
 * Returns [code, message]. code: 'sent' | 'none' | 'wait' | 'off' | 'busy' | 'error'
 */
function pn_flush(PDO $pdo, bool $force = false): array {
    try {
        $cfg = pn_settings($pdo);
        if (!pn_active($pdo, $cfg)) return ['off', 'Alerts are off, or the Dean email / email setup is missing.'];

        pn_ensure_table($pdo);
        // Old scans (not today) are never emailed - just retire them.
        $pdo->prepare("UPDATE punch_alert_queue SET sent_at = ? WHERE sent_at IS NULL AND log_date < ?")
            ->execute([date('Y-m-d H:i:s'), date('Y-m-d')]);

        $oldest = $pdo->query("SELECT MIN(created_at) FROM punch_alert_queue WHERE sent_at IS NULL")->fetchColumn();
        if (!$oldest) return ['none', 'Nothing is waiting to be sent.'];

        $now = time();
        if (!$force) {
            if ($now < strtotime($oldest) + $cfg['interval'] * 60)                       return ['wait', 'Not due yet.'];
            if ($now < (int)dr_setting($pdo, 'alert_last_sent', '0') + $cfg['interval'] * 60) return ['wait', 'Not due yet.'];
            if ($now < (int)dr_setting($pdo, 'alert_next_try', '0'))                      return ['wait', 'Waiting before retrying.'];
        }

        if (!pn_lock($pdo)) return ['busy', 'Another request is already sending.'];
        try {
            $q = $pdo->prepare("SELECT * FROM punch_alert_queue WHERE sent_at IS NULL AND log_date = ?
                                ORDER BY punch_time, id LIMIT 500");
            $q->execute([date('Y-m-d')]);
            $rows = $q->fetchAll();
            if (!$rows) return ['none', 'Nothing is waiting to be sent.'];

            $dean = dr_dean($pdo);
            [$subject, $html, $text] = pn_build($rows, $dean['name'], $cfg);
            [$ok, $err] = bt_send_mail($dean['email'], $dean['name'], $subject, $html, $text);

            if ($ok) {
                $ids = array_map('intval', array_column($rows, 'id'));
                $pdo->prepare("UPDATE punch_alert_queue SET sent_at = ? WHERE id IN (" . implode(',', $ids) . ")")
                    ->execute([date('Y-m-d H:i:s')]);
                dr_set_setting($pdo, 'alert_last_sent', (string)time());
                dr_set_setting($pdo, 'alert_next_try', '0');
                dr_set_setting($pdo, 'alert_last_error', '');
                return ['sent', count($rows) . ' scan' . (count($rows) === 1 ? '' : 's') . ' emailed to ' . $dean['email'] . '.'];
            }
            // Failed: keep the scans, try again in 10 minutes (not on every request)
            dr_set_setting($pdo, 'alert_next_try', (string)(time() + 600));
            dr_set_setting($pdo, 'alert_last_error', mb_substr($err, 0, 200));
            return ['error', $err];
        } finally {
            pn_unlock($pdo);
        }
    } catch (Throwable $ex) {
        error_log('BioTrack punch alert flush: ' . $ex->getMessage());
        return ['error', 'Something went wrong while sending the alert.'];
    }
}

/** The Dean already got the Daily Report for $date, so its waiting scans are not needed. */
function pn_mark_covered(PDO $pdo, string $date): void {
    try {
        pn_ensure_table($pdo);
        $pdo->prepare("UPDATE punch_alert_queue SET sent_at = ? WHERE sent_at IS NULL AND log_date = ?")
            ->execute([date('Y-m-d H:i:s'), $date]);
    } catch (Throwable $ex) {
        error_log('BioTrack punch alert cleanup: ' . $ex->getMessage());
    }
}

/** Build [subject, html, text] for one digest. */
function pn_build(array $rows, string $deanName, array $cfg): array {
    $ins = $outs = $late = 0;
    foreach ($rows as $r) {
        if ($r['event'] === 'in') { $ins++; if ($r['status'] === 'Late') $late++; } else $outs++;
    }
    $first = date('g:i A', strtotime($rows[0]['punch_time']));
    $last  = date('g:i A', strtotime($rows[count($rows) - 1]['punch_time']));
    $day   = date('M j', strtotime($rows[0]['log_date']));

    $bits = [];
    if ($ins)  $bits[] = $ins  . ' time in'  . ($ins  === 1 ? '' : 's') . ($late ? " ($late late)" : '');
    if ($outs) $bits[] = $outs . ' time out' . ($outs === 1 ? '' : 's');
    $subject = 'Attendance activity ' . $day . ': ' . implode(', ', $bits)
             . ($first === $last ? " at $first" : " \u{00B7} $first - $last");

    $th = 'style="text-align:left;padding:7px 9px;background:#1f2a44;color:#ffffff;font-size:12px;border:1px solid #1f2a44;"';
    $td = 'style="padding:7px 9px;border:1px solid #d9dee8;font-size:13px;"';

    $h  = '<div style="font-family:Arial,Helvetica,sans-serif;color:#1c2333;max-width:640px;margin:0 auto;">'
        . '<div style="font-size:12px;color:#5b6577;letter-spacing:.5px;text-transform:uppercase;">GNCI - College of Business and Accountancy</div>'
        . '<div style="font-size:20px;font-weight:bold;margin:2px 0 4px;">Time in / time out update</div>'
        . '<div style="font-size:14px;color:#3b4558;margin-bottom:12px;">Good day, ' . dr_h($deanName) . '. Here is what was scanned between <b>'
        . dr_h($first) . '</b> and <b>' . dr_h($last) . '</b> on ' . dr_h(date('l, F j, Y', strtotime($rows[0]['log_date']))) . '.</div>'
        . '<table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;"><thead><tr>'
        . "<th $th>Time</th><th $th>Employee</th><th $th>Course</th><th $th>Event</th><th $th>Status</th></tr></thead><tbody>";

    $text = "Time in / time out update - " . date('l, F j, Y', strtotime($rows[0]['log_date'])) . "\n"
          . "Scans from $first to $last:\n\n";

    foreach ($rows as $r) {
        $isIn = $r['event'] === 'in';
        $isLate = $isIn && $r['status'] === 'Late';
        $evLabel = $isIn ? 'Time In' : 'Time Out';
        $stLabel = $isIn ? ($isLate ? 'Late' : 'On time') : '';
        $badge = $isLate ? 'background:#fff4d6;color:#8a5a00;' : ($isIn ? 'background:#e7f6ec;color:#17703a;' : 'background:#eceff4;color:#4a5568;');
        $time = date('g:i A', strtotime($r['punch_time']));
        $h .= "<tr><td $td>" . dr_h($time) . "</td><td $td><b>" . dr_h($r['employee_name']) . '</b><br><span style="color:#7a8497;font-size:11px;">'
            . dr_h($r['employee_code']) . "</span></td><td $td>" . dr_h($r['course'] !== '' ? $r['course'] : '—') . "</td>"
            . "<td $td><span style=\"$badge" . 'padding:2px 8px;border-radius:10px;font-size:12px;">' . $evLabel . '</span></td>'
            . "<td $td>" . dr_h($stLabel !== '' ? $stLabel : '—') . '</td></tr>';
        $text .= sprintf("%-9s %-28s %-8s %s\n", $time, $r['employee_name'], $evLabel, $stLabel);
    }

    $every = PN_INTERVALS[$cfg['interval']] ?? 'Every ' . $cfg['interval'] . ' minutes';
    $note = 'You get one update like this at most ' . strtolower($every) . ', and only when someone scanned. '
          . 'The full report still comes at the end of the day. Change or turn off these alerts under Daily Report in BioTrack.';
    $h .= '</tbody></table><p style="margin-top:16px;font-size:11px;color:#7a8497;border-top:1px solid #d9dee8;padding-top:8px;">'
        . dr_h($note) . '</p></div>';
    $text .= "\n" . $note . "\n";

    return [$subject, $h, $text];
}
