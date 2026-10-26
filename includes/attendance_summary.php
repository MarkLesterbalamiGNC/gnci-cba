<?php
/**
 * Present / Late / Absent helpers shared by the employee dashboard,
 * My Report and the admin Attendance Report.
 *
 * A day only counts as Present / Late once the employee has also timed OUT.
 * ABSENT = a weekday (Mon-Fri) before today with no completed Time In + Time Out,
 * on or after the day the employee's record was created.
 * Today, a Time In with no Time Out yet shows as "Timed In" and is not counted.
 * Nothing is stored in the database - absences are worked out when a page loads.
 */

date_default_timezone_set('Asia/Manila');

/** Every date from $from to $to (inclusive) as 'Y-m-d'. */
function att_date_list(string $from, string $to, int $max = 3700): array {
    $out = [];
    $d = strtotime($from);
    $end = strtotime($to);
    if ($d === false || $end === false) return $out;
    for ($n = 0; $d <= $end && $n < $max; $n++, $d = strtotime('+1 day', $d)) {
        $out[] = date('Y-m-d', $d);
    }
    return $out;
}

/** Which of $candidates were missed. $attended is a set: ['2026-10-05' => true, ...]. */
function att_absent_days(array $candidates, array $attended, ?string $createdAt, array $excused = []): array {
    $start = $createdAt ? date('Y-m-d', strtotime($createdAt)) : '0000-00-00';
    $today = date('Y-m-d');
    $out = [];
    foreach ($candidates as $d) {
        if ($d < $start || $d >= $today) continue;          // before they existed / today / future
        if ((int)date('N', strtotime($d)) >= 6) continue;    // Saturday, Sunday
        if (isset($attended[$d])) continue;                  // they punched in
        if (isset($excused[$d])) continue;                   // admin removed this absence
        $out[] = $d;
    }
    return $out;
}

/** Days that have at least one finished shift (Time In and Time Out): ['2026-10-05' => true, ...]. */
function att_attended_days(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        if (!empty($r['time_in']) && !empty($r['time_out'])) $out[$r['log_date']] = true;
    }
    return $out;
}

/** Shift end time + the shift's grace minutes, as 'H:i:s'. Null when the row has no shift. */
function att_shift_cutoff($shiftId): ?string {
    global $pdo;
    static $cache = [];
    if (!$shiftId) return null;
    $shiftId = (int)$shiftId;
    if (!array_key_exists($shiftId, $cache)) {
        $st = $pdo->prepare("SELECT time_end, grace_minutes FROM schedule_shifts WHERE id = ?");
        $st->execute([$shiftId]);
        $s = $st->fetch();
        $cache[$shiftId] = $s ? date('H:i:s', strtotime($s['time_end']) + ((int)$s['grace_minutes'] * 60)) : null;
    }
    return $cache[$shiftId];
}

/** The status to show for one attendance row. No Time Out by the end of the shift (+ grace) = Absent. */
function att_row_status(array $r): string {
    if (!empty($r['time_out'])) return (string)$r['status'];
    $today = date('Y-m-d');
    if ($r['log_date'] < $today) return 'Absent';
    if ($r['log_date'] === $today) {
        $cutoff = att_shift_cutoff($r['schedule_shift_id'] ?? null);
        if ($cutoff !== null && date('H:i:s') > $cutoff) return 'Absent';
    }
    return 'Timed In';
}

/**
 * Returns [stats, list].
 * stats = total (school days counted), present (on time), late, absent
 * list  = the attendance rows plus one 'Absent' row per missed day
 */
function att_build(array $rows, array $absentDays): array {
    $logged = [];
    $late = [];
    $hasRow = [];
    foreach ($rows as $k => $r) {
        $hasRow[$r['log_date']] = true;
        $rows[$k]['status'] = att_row_status($r);
        if (!empty($r['time_in']) && !empty($r['time_out'])) {
            $logged[$r['log_date']] = true;
            if ($r['status'] === 'Late') $late[$r['log_date']] = true;
        }
    }
    foreach ($rows as $r) {
        if (att_row_status($r) === 'Absent' && !isset($logged[$r['log_date']]) && !in_array($r['log_date'], $absentDays, true)) {
            $absentDays[] = $r['log_date'];
        }
    }
    $stats = [
        'total'   => count($logged) + count($absentDays),
        'present' => count($logged) - count($late),
        'late'    => count($late),
        'absent'  => count($absentDays),
    ];
    $list = $rows;
    foreach ($absentDays as $d) {
        if (isset($hasRow[$d])) continue;
        $list[] = ['log_date' => $d, 'shift_order' => 1, 'shift_label' => '',
                   'time_in' => null, 'time_out' => null, 'status' => 'Absent', 'absent' => true];
    }
    return [$stats, $list];
}

/** Keep only Present / Late / Absent rows ('' = all) and sort by date. */
function att_filter(array $list, string $status, string $dir = 'DESC'): array {
    if (in_array($status, ['Present', 'Late', 'Absent'], true)) {
        $list = array_values(array_filter($list, fn($r) => $r['status'] === $status));
    }
    usort($list, function ($a, $b) use ($dir) {
        $c = strcmp($a['log_date'], $b['log_date']);
        if ($dir === 'DESC') $c = -$c;
        return $c ?: ((int)$a['shift_order'] <=> (int)$b['shift_order']);
    });
    return $list;
}

function att_status_from_get(): string {
    $s = $_GET['status'] ?? '';
    return in_array($s, ['Present', 'Late', 'Absent'], true) ? $s : '';
}

function att_pill(string $status): string {
    return $status === 'Late' ? 'pill-late' : ($status === 'Absent' ? 'pill-absent' : (in_array($status, ['Excused', 'Timed In'], true) ? 'pill-gray' : 'pill-present'));
}

/** The "Show: All / Present / Late / Absent" dropdown. */
function att_status_select(string $current, string $onchange = ''): string {
    $h = '<select name="status" id="status"' . ($onchange ? ' onchange="' . $onchange . '"' : '') . '>';
    foreach (['' => 'All', 'Present' => 'Present', 'Late' => 'Late', 'Absent' => 'Absent'] as $v => $l) {
        $h .= '<option value="' . $v . '"' . ($current === $v ? ' selected' : '') . '>' . $l . '</option>';
    }
    return $h . '</select>';
}

/** Four summary cards: Total School Days, Present, Late, Absent. */
function att_stat_cards(array $s, string $extraClass = '', ?array $custom = null): string {
    require_once __DIR__ . '/icons.php';
    $cards = $custom ?? [
        ['blue',   'calendar',  $s['total'],   'Total School Days'],
        ['present','check',     $s['present'], 'Days Present'],
        ['late',   'hourglass', $s['late'],    'Days Late'],
        ['red',    'xcircle',   $s['absent'],  'Days Absent'],
    ];
    $h = '<div class="stat-grid stat-grid-att' . ($extraClass ? ' ' . $extraClass : '') . '">';
    foreach ($cards as [$cls, $ico, $n, $label]) {
        $h .= '<div class="stat-card ' . $cls . '"><div class="stat-body"><span class="stat-icon">' . bt_icon($ico)
            . '</span><div class="stat-number">' . (int)$n . '</div><div class="stat-label">' . $label . '</div></div></div>';
    }
    return $h . '</div>';
}

/**
 * Donut chart of Present / Late / Absent (percent of all counted days).
 * Pure inline SVG, no JavaScript. Percentages use the largest-remainder
 * method so the three labels always add up to exactly 100%.
 */
function att_donut(array $s, string $title = 'Attendance Breakdown', string $unit = 'day', string $centerLabel = 'Attendance rate'): string {
    $vals  = ['present' => (int)$s['present'], 'late' => (int)$s['late'], 'absent' => (int)$s['absent']];
    $total = array_sum($vals);

    $pct = ['present' => 0, 'late' => 0, 'absent' => 0];
    if ($total > 0) {
        $rem = [];
        $used = 0;
        foreach ($vals as $k => $v) {
            $exact = $v * 100 / $total;
            $pct[$k] = (int)floor($exact);
            $rem[$k] = $exact - $pct[$k];
            $used += $pct[$k];
        }
        arsort($rem);
        foreach (array_keys($rem) as $k) {
            if ($used >= 100) break;
            if ($vals[$k] > 0) { $pct[$k]++; $used++; }
        }
    }
    $rate = $total > 0 ? (int)round(($vals['present'] + $vals['late']) * 100 / $total) : null;

    $meta = [
        'present' => ['Present', 'seg-present'],
        'late'    => ['Late',    'seg-late'],
        'absent'  => ['Absent',  'seg-absent'],
    ];

    $svg = '<svg viewBox="0 0 42 42" role="img" aria-label="Attendance breakdown"><circle class="donut-track" cx="21" cy="21" r="15.915" pathLength="100"/>';
    $offset = 0.0;
    if ($total > 0) {
        foreach ($vals as $k => $v) {
            if ($v <= 0) continue;
            $len = $v * 100 / $total;
            $svg .= '<circle class="donut-seg ' . $meta[$k][1] . '" cx="21" cy="21" r="15.915" pathLength="100"'
                  . ' stroke-dasharray="' . round($len, 3) . ' ' . round(100 - $len, 3) . '"'
                  . ' stroke-dashoffset="' . round(-$offset, 3) . '"/>';
            $offset += $len;
        }
    }
    $svg .= '</svg>';

    $h  = '<div class="panel donut-panel">';
    $h .= '<div class="panel-head"><h2>' . htmlspecialchars($title) . '</h2></div>';
    $h .= '<div class="donut-wrap"><div class="donut">' . $svg;
    $h .= '<div class="donut-center"><b>' . ($rate === null ? '&mdash;' : $rate . '%') . '</b><span>' . htmlspecialchars($centerLabel) . '</span></div></div>';
    $h .= '<div class="donut-legend">';
    foreach ($vals as $k => $v) {
        $h .= '<div class="donut-leg"><span class="dot ' . $meta[$k][1] . '"></span>'
            . '<div><div class="n">' . $meta[$k][0] . '</div><div class="c">' . $v . ' ' . ($v === 1 ? $unit : $unit . 's') . '</div></div>'
            . '<div class="p">' . $pct[$k] . '%</div></div>';
    }
    $h .= '</div></div></div>';
    return $h;
}


/**
 * "Removed" absences. When an admin removes an absence (e.g. the device
 * malfunctioned or the school was closed) the day is stored here and is
 * simply skipped when absences are worked out. The table is created
 * automatically the first time it's needed.
 */
function att_ensure_excused_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_excused (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        log_date DATE NOT NULL,
        reason VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_excused_emp_day (employee_id, log_date),
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
}

/** One employee's removed absences as a set: ['2026-10-05' => true, ...]. */
function att_excused_dates(PDO $pdo, int $empId): array {
    att_ensure_excused_table($pdo);
    $st = $pdo->prepare("SELECT log_date FROM attendance_excused WHERE employee_id = ?");
    $st->execute([$empId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) $out[$d] = true;
    return $out;
}

/** Everyone's removed absences between two dates: [employee_id][date] => true. */
function att_excused_map(PDO $pdo, string $from, string $to): array {
    att_ensure_excused_table($pdo);
    $st = $pdo->prepare("SELECT employee_id, log_date FROM attendance_excused WHERE log_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['employee_id']][$r['log_date']] = true;
    return $out;
}


/**
 * Today's numbers for the admin dashboard.
 * Present / Late = employees who timed in AND out today (Late if any finished shift was late).
 * Employees who timed in but haven't timed out yet are not counted as anything yet.
 * Absent = everyone else on a school day (Mon-Fri) who has no time in yet and
 * wasn't excused. Weekends count nobody as absent.
 * Returns ['total' => registered employees, 'present', 'late', 'absent'].
 */
function att_today_stats(PDO $pdo): array {
    $today = date('Y-m-d');
    $emps = $pdo->query("SELECT id FROM employees WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
    $st = $pdo->prepare("SELECT a.employee_id, a.status, a.time_out, a.log_date, a.schedule_shift_id FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE a.log_date = ? AND e.deleted_at IS NULL");
    $st->execute([$today]);
    $in = [];
    $seen = [];
    foreach ($st->fetchAll() as $r) {
        $id = (int)$r['employee_id'];
        if ($r['time_out']) {
            $seen[$id] = true;
            $in[$id] = ($in[$id] ?? false) || $r['status'] === 'Late';
        } elseif (att_row_status($r) === 'Timed In') {
            $seen[$id] = true;
        }
    }
    $late = count(array_filter($in));
    $present = count($in) - $late;
    $absent = 0;
    if ((int)date('N') < 6) {
        $excused = att_excused_map($pdo, $today, $today);
        foreach ($emps as $id) {
            $id = (int)$id;
            if (!isset($seen[$id]) && !isset($excused[$id][$today])) $absent++;
        }
    }
    return ['total' => count($emps), 'present' => $present, 'late' => $late, 'absent' => $absent];
}


/**
 * Per-day numbers for the last $n school days (Mon-Fri, ending today),
 * for the admin dashboard chart. Same rules as the rest of the system:
 * Late = any late shift that day, Absent = no punch on a school day
 * (and not excused). For today, "absent" means no time in yet.
 */
function att_recent_days(PDO $pdo, int $n = 7): array {
    $days = [];
    $d = strtotime(date('Y-m-d'));
    for ($guard = 0; count($days) < $n && $guard < 40; $guard++, $d = strtotime('-1 day', $d)) {
        if ((int)date('N', $d) < 6) array_unshift($days, date('Y-m-d', $d));
    }
    if (!$days) return [];
    $from = $days[0];
    $to   = end($days);
    $emps = $pdo->query("SELECT id, created_at FROM employees WHERE deleted_at IS NULL")->fetchAll();
    $st = $pdo->prepare("SELECT a.employee_id, a.log_date, a.status, a.time_out, a.schedule_shift_id FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE e.deleted_at IS NULL AND a.log_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $att = [];
    $open = [];
    foreach ($st->fetchAll() as $r) {
        $k = (int)$r['employee_id'];
        if ($r['time_out']) $att[$r['log_date']][$k] = ($att[$r['log_date']][$k] ?? false) || $r['status'] === 'Late';
        elseif (att_row_status($r) === 'Timed In') $open[$r['log_date']][$k] = true;
    }
    $exc = att_excused_map($pdo, $from, $to);
    $out = [];
    foreach ($days as $day) {
        $p = $l = $a = 0;
        foreach ($emps as $e) {
            $id = (int)$e['id'];
            if (date('Y-m-d', strtotime($e['created_at'])) > $day) continue;
            if (isset($att[$day][$id])) { if ($att[$day][$id]) $l++; else $p++; }
            elseif ($day === date('Y-m-d') && isset($open[$day][$id])) continue;
            elseif (!isset($exc[$id][$day])) $a++;
        }
        $out[] = ['date' => $day, 'present' => $p, 'late' => $l, 'absent' => $a];
    }
    return $out;
}

/** Admin KPI cards: label, big number, share of all employees, progress bar. */
function att_kpi_cards(array $s): string {
    require_once __DIR__ . '/icons.php';
    $total = max(0, (int)$s['total']);
    $cards = [
        ['blue',    'users',     $s['total'],   'Total Employees', 'Registered and active', null],
        ['present', 'check',     $s['present'], 'Present Today',   'On time', $total],
        ['late',    'hourglass', $s['late'],    'Late Today',      'Timed in after the grace period', $total],
        ['red',     'xcircle',   $s['absent'],  'Absent Today',    'No time in yet', $total],
    ];
    $h = '<div class="stat-grid kpi-grid">';
    foreach ($cards as [$cls, $ico, $n, $label, $note, $of]) {
        $pct = ($of !== null && $of > 0) ? (int)round($n * 100 / $of) : null;
        $h .= '<div class="stat-card kpi ' . $cls . '"><div class="kpi-top"><span class="kpi-label">' . $label
            . '</span><span class="stat-icon">' . bt_icon($ico) . '</span></div>'
            . '<div class="kpi-num">' . (int)$n . ($pct !== null ? '<small>' . $pct . '%</small>' : '') . '</div>'
            . '<div class="kpi-note">' . $note . '</div>';
        if ($of !== null) $h .= '<div class="kpi-bar"><i style="width:' . (int)($pct ?? 0) . '%"></i></div>';
        $h .= '</div>';
    }
    return $h . '</div>';
}

/** Stacked bar chart (pure HTML/CSS) of the last school days. */
function att_trend_chart(array $days): string {
    $max = 1;
    foreach ($days as $d) $max = max($max, $d['present'] + $d['late'] + $d['absent']);
    $today = date('Y-m-d');
    $h  = '<div class="panel trend-panel"><div class="panel-head"><h2>Last 7 School Days</h2>'
        . '<div class="trend-legend"><span><i class="seg-present"></i>Present</span><span><i class="seg-late"></i>Late</span><span><i class="seg-absent"></i>Absent</span></div></div>';
    $h .= '<div class="trend-chart">';
    foreach ($days as $d) {
        $sum  = $d['present'] + $d['late'] + $d['absent'];
        $rate = $sum > 0 ? (int)round(($d['present'] + $d['late']) * 100 / $sum) : null;
        $ts   = strtotime($d['date']);
        $tip  = date('D, M j', $ts) . ' - Present ' . $d['present'] . ', Late ' . $d['late'] . ', Absent ' . $d['absent'];
        $h .= '<div class="trend-col' . ($d['date'] === $today ? ' is-today' : '') . '" title="' . htmlspecialchars($tip) . '">'
            . '<div class="trend-rate">' . ($rate === null ? '&mdash;' : $rate . '%') . '</div>'
            . '<div class="trend-track"><div class="trend-bar" style="height:' . round($sum * 100 / $max, 2) . '%">';
        foreach (['absent' => 'seg-absent', 'late' => 'seg-late', 'present' => 'seg-present'] as $k => $c) {
            if ($d[$k] > 0) $h .= '<span class="' . $c . '" style="flex:' . $d[$k] . '">' . $d[$k] . '</span>';
        }
        $h .= '</div></div><div class="trend-day"><b>' . date('D', $ts) . '</b><span>' . date('M j', $ts) . '</span></div></div>';
    }
    $h .= '</div><div class="trend-foot">Attendance rate shown above each bar. Today\'s absent count means no time in yet.</div></div>';
    return $h;
}
