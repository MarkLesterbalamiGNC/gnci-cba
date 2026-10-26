<?php
/**
 * Settings for the Arduino time-in / time-out terminal (device_punch.php).
 *
 * DEVICE_API_KEY must be EXACTLY the same text as API_KEY in the Arduino sketch
 * (biotrack_enroll.ino). Use letters and numbers only, max 24 characters.
 * Change it from the default!
 */
// A private  config/device.local.php  (deployed with the site) can define DEVICE_API_KEY
// and $DEVICE_ALLOWED_IPS instead - use it on the web server.
if (is_file(__DIR__ . '/device.local.php')) require_once __DIR__ . '/device.local.php';
defined('DEVICE_API_KEY') || define('DEVICE_API_KEY', '');

// Optional: only accept punches from these IP addresses (the Arduino's IP).
// Leave the list empty to accept any computer on your network that knows the key.
// Example: $DEVICE_ALLOWED_IPS = ['192.168.1.177'];
if (!isset($DEVICE_ALLOWED_IPS)) $DEVICE_ALLOWED_IPS = [];
