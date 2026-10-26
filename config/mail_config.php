<?php
/**
 * Email settings (used to send the daily attendance report to the Dean).
 *
 * DON'T type your real email password in THIS file if the project goes on GitHub.
 * Instead edit  config/mail.local.php  and put
 * the real values there (that file is ignored by git). Values found there win over
 * the defaults below. You can also use environment variables BIOTRACK_MAIL_HOST,
 * _PORT, _SECURE, _USER, _PASS, _FROM, _FROM_NAME.
 *
 * GMAIL:   host smtp.gmail.com, port 587, secure 'tls', user = your Gmail address,
 *          password = a Google "App Password" (Google Account > Security > 2-Step
 *          Verification must be ON > App passwords). Your normal password will NOT work.
 * DOMAIN MAILBOX (e.g. reports@yourschool.edu.ph from your hosting): use the SMTP
 *          host/port your hosting shows for that mailbox (often port 465 + 'ssl').
 */
if (is_file(__DIR__ . '/mail.local.php')) require_once __DIR__ . '/mail.local.php';

$__env = fn($k, $d) => (getenv($k) !== false && getenv($k) !== '') ? getenv($k) : $d;

defined('MAIL_DRIVER')     || define('MAIL_DRIVER',     'smtp');                                  // 'smtp' (recommended) or 'mail'
defined('MAIL_HOST')       || define('MAIL_HOST',       $__env('BIOTRACK_MAIL_HOST', 'smtp.gmail.com'));
defined('MAIL_PORT')       || define('MAIL_PORT',       (int)$__env('BIOTRACK_MAIL_PORT', 587));
defined('MAIL_SECURE')     || define('MAIL_SECURE',     $__env('BIOTRACK_MAIL_SECURE', 'tls'));   // 'tls' (587), 'ssl' (465) or ''
defined('MAIL_USERNAME')   || define('MAIL_USERNAME',   $__env('BIOTRACK_MAIL_USER', ''));
defined('MAIL_PASSWORD')   || define('MAIL_PASSWORD',   $__env('BIOTRACK_MAIL_PASS', ''));
defined('MAIL_FROM_EMAIL') || define('MAIL_FROM_EMAIL', $__env('BIOTRACK_MAIL_FROM', MAIL_USERNAME));
defined('MAIL_FROM_NAME')  || define('MAIL_FROM_NAME',  $__env('BIOTRACK_MAIL_FROM_NAME', 'BioTrack Attendance'));

// Secret word that lets your hosting's scheduled task (cron) call send_daily_report.php over the
// web. Leave EMPTY if you run it from the command line instead (recommended on XAMPP / Windows).
defined('MAIL_CRON_TOKEN') || define('MAIL_CRON_TOKEN', $__env('BIOTRACK_MAIL_CRON_TOKEN', ''));
unset($__env);
