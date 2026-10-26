<?php
/**
 * send_daily_report.php  -  emails the day's attendance to the Dean.
 * Run it ONCE A DAY, after closing time, with your computer's / hosting's scheduler:
 *
 *   Command line (XAMPP on Windows, or hosting cron):
 *       php C:\xampp\htdocs\BioTrack_Device\send_daily_report.php
 *       php send_daily_report.php 2026-10-26          (a specific day)
 *       php send_daily_report.php --force             (send again / on a weekend)
 *
 *   Web link (only if your host's cron can only open URLs): set MAIL_CRON_TOKEN in
 *   config/mail.local.php, then call
 *       https://yourdomain/send_daily_report.php?token=YOUR_TOKEN
 *       (&date=2026-10-26  and/or  &force=1  are optional)
 *
 * Safe to run more than once a day: a report already sent for that date is skipped.
 * Weekends are skipped (nobody counts as absent), unless you add --force.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/attendance_summary.php';
require_once __DIR__ . '/includes/daily_report.php';

$isCli = PHP_SAPI === 'cli';

function out(string $msg, int $code = 0): void {
    global $isCli;
    if (!$isCli) {
        http_response_code($code === 0 ? 200 : ($code === 403 ? 403 : 500));
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo $msg . "\n";
    exit($isCli ? $code : 0);
}

if ($isCli) {
    $args  = array_slice($argv ?? [], 1);
    $force = in_array('--force', $args, true);
    $date  = null;
    foreach ($args as $a) if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $a)) $date = $a;
    $by = 'scheduled (command line)';
} else {
    // Web access only works when a secret token has been set, and wrong guesses are rate limited
    $ip = bt_client_ip();
    if (MAIL_CRON_TOKEN === '' || bt_rl_count($pdo, 'cron_token', $ip, 600) >= 10) out('Forbidden.', 403);
    $given = (string)($_GET['token'] ?? '');
    if (!hash_equals(MAIL_CRON_TOKEN, $given)) { bt_rl_hit($pdo, 'cron_token', $ip); out('Forbidden.', 403); }
    $force = ($_GET['force'] ?? '') === '1';
    $date  = (string)($_GET['date'] ?? '');
    $by = 'scheduled (web link)';
}

$today = date('Y-m-d');
if ($date === null || $date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
    || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4)) || $date > $today) {
    $date = $today;
}

dr_ensure_tables($pdo);

if (!$force && (int)date('N', strtotime($date)) >= 6) out("Skipped: $date is a weekend (use --force to send anyway).");
if (!$force && dr_already_sent($pdo, $date))            out("Skipped: the report for $date was already sent.");
if (!bt_mail_configured())                              out('Email is not set up. Create config/mail.local.php.', 1);

[$ok, $msg] = dr_send($pdo, $date, $by);
out(($ok ? 'OK: ' : 'FAILED: ') . $msg, $ok ? 0 : 1);
