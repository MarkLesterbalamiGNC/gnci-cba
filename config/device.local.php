<?php
/**
 * Private device settings for the Arduino time-in / time-out terminal.
 * DEVICE_API_KEY must be EXACTLY the same text as API_KEY in the Arduino sketch
 * (Arduino INO/biotrack_enroll/biotrack_enroll.ino). Letters and numbers only, max 24 characters.
 * CHANGE the default below before using the system for real, then re-upload the sketch.
 * This file is deployed to the server with the rest of the site.
 */
define('DEVICE_API_KEY', 'change-me-1234');

// Optional: only accept punches from these IP addresses (the Arduino's IP). Leave empty for any.
// $DEVICE_ALLOWED_IPS = ['192.168.1.177'];
