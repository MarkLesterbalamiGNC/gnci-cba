<?php
/**
 * Security settings. Safe defaults are already built in - you normally don't
 * need to touch this file. (It is blocked from browsers by config/.htaccess.)
 */

// Send visitors to https:// automatically when the site is opened over plain http
// on a real domain. localhost / 127.0.0.1 / 192.168.x.x are never redirected, so
// XAMPP at school keeps working. The Arduino (device_punch.php) is never redirected.
// Set to false ONLY if your hosting has no SSL certificate yet.
define('BT_FORCE_HTTPS', true);

// Automatically sign people out after this many seconds with no activity.
define('BT_SESSION_IDLE', 60 * 60);          // 1 hour

// ...and never keep anyone signed in longer than this, even if they stay active.
// (7 days, so a Scan Attendance screen left open all week isn't signed out; lower it if you prefer.)
define('BT_SESSION_MAX', 7 * 24 * 60 * 60);  // 7 days

// If the site sits behind Cloudflare or another reverse proxy, put that proxy's
// IP address(es) here so login throttling sees each visitor's REAL IP instead of
// the proxy's. Leave empty if you are not using a proxy.
// Example: define('BT_TRUSTED_PROXIES', ['173.245.48.1']);
define('BT_TRUSTED_PROXIES', []);
