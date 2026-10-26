<?php
/**
 * send_punch_alerts.php  -  emails the Dean ONE digest of the latest Time In / Time Out scans.
 * Run it every 10-15 minutes with your computer's / hosting's scheduler. It is cheap, and it only
 * sends when a digest is due (see includes/punch_alerts.php), so running it often never spams.
 *
 *   Command line (XAMPP on Windows, or hosting cron):
 *       php C:\xampp\htdocs\BioTrack_Device\send_punch_alerts.php
 *       php send_punch_alerts.php --force        (send whatever is waiting right now)
 *
 *   Web link (only if your host's cron can only open URLs): uses the same MAIL_CRON_TOKEN as
 *   send_daily_report.php
 *       https://yourdomain/send_punch_alerts.php?token=YOUR_TOKEN
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/punch_alerts.php';

$isCli = PHP_SAPI === 'cli';

function out(string $msg, int $code = 0): void {
    global $isCli;
    if (!$isCli) {
        http_response_code($code === 403 ? 403 : 200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo $msg . "\n";
    exit($isCli ? $code : 0);
}

if ($isCli) {
    $force = in_array('--force', array_slice($argv ?? [], 1), true);
} else {
    $ip = bt_client_ip();
    if (MAIL_CRON_TOKEN === '' || bt_rl_count($pdo, 'cron_token', $ip, 600) >= 10) out('Forbidden.', 403);
    if (!hash_equals(MAIL_CRON_TOKEN, (string)($_GET['token'] ?? ''))) { bt_rl_hit($pdo, 'cron_token', $ip); out('Forbidden.', 403); }
    $force = false;   // the web link never forces
}

[$code, $msg] = pn_flush($pdo, $force);
out(strtoupper($code) . ': ' . $msg, $code === 'error' ? 1 : 0);
