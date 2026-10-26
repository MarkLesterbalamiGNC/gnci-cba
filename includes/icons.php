<?php
/**
 * BioTrack - inline SVG icon set (Lucide-style outline, 24x24, 1.75 stroke).
 * Usage:  <?= bt_icon('users') ?>   or   <?= bt_icon('check', 'my-class') ?>
 * Colour comes from CSS (stroke: currentColor), so icons follow the text colour.
 */
if (!function_exists('bt_icon')) {
    function bt_icon(string $name, string $class = 'ico-svg'): string {
        static $paths = [
            'dashboard'   => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
            'users'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'user'        => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'usercheck'   => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>',
            'calendar'    => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'fingerprint' => '<path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/><path d="M14 13.12c0 2.38 0 6.38-1 8.88"/><path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/><path d="M2 12a10 10 0 0 1 18-6"/><path d="M2 16h.01"/><path d="M21.8 16c.2-2 .131-5.354 0-6"/><path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/><path d="M8.65 22c.21-.66.45-1.32.57-2"/><path d="M9 6.8a6 6 0 0 1 9 5.2v2"/>',
            'list'        => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/>',
            'report'      => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><path d="M14 2v4a2 2 0 0 0 2 2h4M10 9H8M16 13H8M16 17H8"/>',
            'trash'       => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><path d="M10 11v6M14 11v6"/>',
            'archive'     => '<rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
            'clock'       => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'hourglass'   => '<path d="M5 22h14M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/>',
            'check'       => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
            'xcircle'     => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
            'arrow'       => '<path d="M5 12h14M12 5l7 7-7 7"/>',
            'menu'        => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'shield'      => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.5 3.8 17 5 19 5a1 1 0 0 1 1 1z"/><path d="M9.5 15.5c.2-1 .3-2 .3-3.3a2.2 2.2 0 0 1 4.4 0c0 1.6-.1 3-.5 4.3"/><path d="M12 15.5c0 .8-.1 1.6-.3 2.3"/>',
        ];
        if (!isset($paths[$name])) return '';
        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
    }
}

/**
 * Photo filename of whoever is logged in (admin -> accounts.photo,
 * employee -> employees.photo), or null when there is none / the file is gone.
 * Looked up once per request, AFTER any profile update has been saved, so the
 * sidebar and top bar always show the newest picture.
 */
if (!function_exists('bt_current_user_photo')) {
    function bt_current_user_photo(): ?string {
        static $done = false, $photo = null;
        if ($done) return $photo;
        $done = true;
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof PDO)) return null;
        try {
            $role = $_SESSION['role'] ?? '';
            if ($role === 'admin' && !empty($_SESSION['admin_id'])) {
                $st = $pdo->prepare("SELECT photo FROM accounts WHERE id = ? AND role = 'admin'");
                $st->execute([(int)$_SESSION['admin_id']]);
            } elseif ($role === 'employee' && !empty($_SESSION['employee_id'])) {
                $st = $pdo->prepare("SELECT photo FROM employees WHERE id = ?");
                $st->execute([(int)$_SESSION['employee_id']]);
            } else {
                return null;
            }
            $name = (string)$st->fetchColumn();
            if ($name !== '') {
                $name = basename($name);
                if (is_file(dirname(__DIR__) . '/uploads/photos/' . $name)) $photo = $name;
            }
        } catch (Throwable $e) {
            $photo = null;
        }
        return $photo;
    }
}

/** <img> of the logged-in user's photo, or the user icon when they have none. */
if (!function_exists('bt_avatar')) {
    function bt_avatar(): string {
        $p = bt_current_user_photo();
        if ($p === null) return bt_icon('user');
        $v = @filemtime(dirname(__DIR__) . '/uploads/photos/' . $p) ?: 0;
        return '<img src="uploads/photos/' . htmlspecialchars(rawurlencode($p), ENT_QUOTES) . '?v=' . (int)$v . '" alt="">';
    }
}

