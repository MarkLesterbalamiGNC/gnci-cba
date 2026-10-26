<?php
/**
 * BioTrack security layer.
 *
 * Loaded automatically by includes/auth.php and includes/auth_role.php, so every
 * page gets it without any other edits. It provides:
 *   - secure session cookies, idle + absolute session timeout
 *   - HTTPS redirect (real domains only), HSTS, and browser security headers
 *   - cross-site request (CSRF) protection for every POST
 *   - forced password change for weak / default passwords
 *   - login brute-force throttling helpers, password rules, image-upload checks
 */
if (defined('BT_SECURITY_LOADED')) return;
define('BT_SECURITY_LOADED', true);

if (is_file(__DIR__ . '/../config/security_config.php')) require_once __DIR__ . '/../config/security_config.php';
if (!defined('BT_FORCE_HTTPS'))      define('BT_FORCE_HTTPS', true);
if (!defined('BT_SESSION_IDLE'))     define('BT_SESSION_IDLE', 3600);
if (!defined('BT_SESSION_MAX'))      define('BT_SESSION_MAX', 604800);
if (!defined('BT_TRUSTED_PROXIES'))  define('BT_TRUSTED_PROXIES', []);

/* ------------------------------------------------------------------ PHP hardening */

/** Never show PHP errors to visitors (they leak paths / SQL); write them to logs/ instead. */
function bt_harden_php(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    @ini_set('log_errors', '1');
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    if (is_dir($dir) && is_writable($dir)) @ini_set('error_log', $dir . '/php_error.log');
    @ini_set('expose_php', '0');
    if (!headers_sent()) header_remove('X-Powered-By');
}

/* ------------------------------------------------------------------ request helpers */

function bt_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') return true;
    if (stripos((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"scheme":"https"') !== false) return true;
    return false;
}

/** The visitor's real IP. Forwarded headers are only believed when they come from a trusted proxy. */
function bt_client_ip(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (BT_TRUSTED_PROXIES && in_array($ip, BT_TRUSTED_PROXIES, true)) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $cand = trim(explode(',', (string)$_SERVER[$h])[0]);
                if (filter_var($cand, FILTER_VALIDATE_IP)) return $cand;
            }
        }
    }
    return $ip;
}

function bt_host_only(): string {
    $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    return preg_replace('/:\d+$/', '', $h);
}

/** localhost, 127.x, 192.168.x, 10.x, 172.16-31.x and *.local are "private" - no HTTPS redirect there. */
function bt_is_local_host(): bool {
    $h = bt_host_only();
    if ($h === '' || $h === 'localhost' || str_ends_with($h, '.local') || str_ends_with($h, '.localhost')) return true;
    if (filter_var($h, FILTER_VALIDATE_IP)) {
        return !filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    return false;
}

function bt_wants_json(): bool {
    return stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false
        || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || (($_SERVER['HTTP_X_BT_MODAL'] ?? '') === '1');
}

function bt_deny(int $code, string $msg): void {
    http_response_code($code);
    if (bt_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
    }
    exit;
}

/* ------------------------------------------------------------------ headers */

function bt_send_security_headers(): void {
    if (headers_sent()) return;
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
    // The pages use some inline <script>/<style>, so those stay allowed; everything else is locked to this site.
    header("Content-Security-Policy: default-src 'self'; "
         . "script-src 'self' 'unsafe-inline'; "
         . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
         . "font-src 'self' https://fonts.gstatic.com data:; "
         . "img-src 'self' data: blob:; "
         . "connect-src 'self'; "
         . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    if (bt_is_https()) header('Strict-Transport-Security: max-age=31536000');
}

/* ------------------------------------------------------------------ sessions */

function bt_session_start(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    @ini_set('session.use_strict_mode', '1');      // refuse session IDs the server did not create
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_trans_sid', '0');
    @ini_set('session.cookie_httponly', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => bt_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function bt_destroy_session(string $message = ''): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies') && !headers_sent()) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
                  'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    }
    @session_destroy();
    if ($message !== '') {
        session_start();                       // fresh, empty session just to carry the message
        session_regenerate_id(true);
        $_SESSION['login_error'] = $message;
    }
}

/** Idle / absolute timeout and "same browser" check for signed-in sessions. */
function bt_session_guard(): void {
    if (empty($_SESSION['role'])) return;
    $now = time();
    $ua  = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if (!isset($_SESSION['bt_start'])) { $_SESSION['bt_start'] = $now; $_SESSION['bt_last'] = $now; $_SESSION['bt_ua'] = $ua; }

    $idle = $now - (int)($_SESSION['bt_last'] ?? $now);
    $age  = $now - (int)$_SESSION['bt_start'];

    // The User-Agent is NOT used to end a session: phone simulators / "request desktop site" / browser
    // updates change it mid-session and used to sign people out. (It is trivial to fake anyway; the
    // session cookie, idle timeout and max age below are what actually protect the login.)
    $_SESSION['bt_ua'] = $ua;
    if ($idle > BT_SESSION_IDLE || $age > BT_SESSION_MAX) {
        bt_destroy_session('Your session expired. Please sign in again.');
        return;
    }
    $_SESSION['bt_last'] = $now;
}

/** Weak / default passwords must be changed before anything else can be used. */
function bt_enforce_password_change(): void {
    if (empty($_SESSION['role']) || empty($_SESSION['force_pw_change'])) return;
    $page = basename((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
    if (in_array($page, ['account_settings.php', 'employee_forgot_password.php', 'logout.php', 'login.php', 'index.php'], true)) return;
    if (bt_wants_json()) bt_deny(403, 'Please change your password first.');
    header('Location: ' . (($_SESSION['role'] ?? '') === 'admin' ? 'account_settings.php' : 'employee_forgot_password.php'));
    exit;
}

/* ------------------------------------------------------------------ CSRF */

/**
 * Blocks any POST/PUT/DELETE whose Origin (or Referer) is another website.
 * Together with SameSite session cookies this stops cross-site request forgery
 * without needing to change any form.
 */
function bt_check_origin(): void {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;

    $src = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($src === '' || strtolower($src) === 'null') {
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($src !== '' && $ref === '') bt_deny(403, 'Blocked: cross-site request.');   // Origin "null" and no Referer
        $src = $ref;
    }
    if ($src === '') return;     // some privacy tools strip both headers; SameSite cookies still protect these

    $srcHost = strtolower((string)parse_url($src, PHP_URL_HOST));
    $allowed = [bt_host_only()];
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $allowed[] = strtolower(preg_replace('/:\d+$/', '', trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_HOST'])[0])));
    }
    if ($srcHost === '' || !in_array($srcHost, $allowed, true)) bt_deny(403, 'Blocked: cross-site request.');
}

/* ------------------------------------------------------------------ boot */

function bt_security_boot(): void {
    static $booted = false;
    if ($booted) return;
    $booted = true;

    bt_harden_php();

    // Reject forged Host headers (used in password-reset / cache poisoning attacks)
    $rawHost = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($rawHost !== '' && !preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$|^\[[0-9a-fA-F:]+\](:\d{1,5})?$/', $rawHost)) {
        bt_deny(400, 'Bad request.');
    }

    // Plain http on a real domain -> https (never for localhost / LAN, never for the Arduino endpoint)
    if (PHP_SAPI !== 'cli' && BT_FORCE_HTTPS && !bt_is_https() && !bt_is_local_host()) {
        $host = bt_host_only();
        $uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
        if ($host !== '' && $uri !== '' && $uri[0] === '/') {
            header('Location: https://' . $host . $uri, true, in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) ? 301 : 308);
            exit;
        }
    }

    bt_send_security_headers();
    bt_check_origin();
    bt_session_start();
    bt_session_guard();

    // Signed-in pages must not be kept in the browser cache (back-button after logout on a shared PC)
    if (!empty($_SESSION['role']) && !headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
    bt_enforce_password_change();
}

/* ------------------------------------------------------------------ passwords */

/** Returns an error message when the password is too weak, otherwise null. */
function bt_password_error(string $pw, string $username = ''): ?string {
    if (strlen($pw) < 8) return 'Password must be at least 8 characters.';
    if (strlen($pw) > 200) return 'Password is too long.';
    $l = strtolower($pw);
    if ($username !== '' && $l === strtolower($username)) return 'Password cannot be the same as the username / ID.';
    if (preg_match('/^(.)\1+$/', $pw)) return 'Please choose a less predictable password.';
    $common = ['password', 'password1', 'password123', '12345678', '123456789', '1234567890', '11111111', '00000000',
               'qwertyui', 'qwerty123', 'iloveyou', 'admin123', 'abc12345', 'm4steradm1n', 'biotrack', 'biotrack123', 'letmein1'];
    if (in_array($l, $common, true)) return 'That password is too common. Please choose another.';
    return null;
}

/* ------------------------------------------------------------------ rate limiting (DB backed) */

function bt_rl_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS security_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bucket VARCHAR(20) NOT NULL,
        subject VARCHAR(190) NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_bucket_subject (bucket, subject, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (random_int(1, 50) === 1) $pdo->exec("DELETE FROM security_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    $done = true;
}

/** How many hits this subject has in the last $window seconds. Fails open (0) if the DB call fails. */
function bt_rl_count(PDO $pdo, string $bucket, string $subject, int $window): int {
    try {
        bt_rl_ensure($pdo);
        $st = $pdo->prepare("SELECT COUNT(*) FROM security_attempts WHERE bucket = ? AND subject = ? AND created_at > (NOW() - INTERVAL " . (int)$window . " SECOND)");
        $st->execute([$bucket, mb_substr($subject, 0, 190)]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { error_log('bt_rl_count: ' . $e->getMessage()); return 0; }
}

function bt_rl_hit(PDO $pdo, string $bucket, string $subject): void {
    try {
        bt_rl_ensure($pdo);
        $pdo->prepare("INSERT INTO security_attempts (bucket, subject, created_at) VALUES (?, ?, NOW())")
            ->execute([$bucket, mb_substr($subject, 0, 190)]);
    } catch (Throwable $e) { error_log('bt_rl_hit: ' . $e->getMessage()); }
}

function bt_rl_clear(PDO $pdo, string $bucket, string $subject): void {
    try {
        bt_rl_ensure($pdo);
        $pdo->prepare("DELETE FROM security_attempts WHERE bucket = ? AND subject = ?")->execute([$bucket, mb_substr($subject, 0, 190)]);
    } catch (Throwable $e) { error_log('bt_rl_clear: ' . $e->getMessage()); }
}

/* ------------------------------------------------------------------ uploads */

/**
 * Checks that an uploaded file is REALLY a small JPG/PNG/GIF (not just named like one).
 * Returns an error message, or null when it is fine.
 */
function bt_image_upload_error(array $file, int $maxBytes = 3145728): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return 'The photo could not be uploaded.';
    if (!is_uploaded_file($file['tmp_name'] ?? '')) return 'The photo could not be uploaded.';
    if (($file['size'] ?? 0) > $maxBytes) return 'Photo must be smaller than ' . round($maxBytes / 1048576) . 'MB.';

    $ext  = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $want = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
    if (!isset($want[$ext])) return 'Photo must be a JPG, PNG, or GIF file.';

    $info = @getimagesize($file['tmp_name']);
    if ($info === false || ($info['mime'] ?? '') !== $want[$ext]) return 'That file is not a valid ' . strtoupper($ext) . ' image.';
    if ($info[0] > 8000 || $info[1] > 8000) return 'Photo dimensions are too large (max 8000 x 8000).';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $real = $fi ? finfo_file($fi, $file['tmp_name']) : $want[$ext];
        if ($fi) finfo_close($fi);
        if ($real !== $want[$ext]) return 'That file is not a valid ' . strtoupper($ext) . ' image.';
    }
    return null;
}

/** Unpredictable file name (the old uniqid() names could be guessed). */
function bt_random_name(string $prefix, string $ext): string {
    return $prefix . bin2hex(random_bytes(10)) . '.' . strtolower($ext);
}

/** Stops spreadsheet programs from running "=cmd|..." style formulas hidden in exported text. */
function bt_csv_safe($v): string {
    $v = (string)$v;
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}
