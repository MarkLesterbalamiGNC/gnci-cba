# BioTrack - Security notes (read before going public)

## What was added (nothing was removed)
| Protection | Where |
|---|---|
| Secure session cookies (HttpOnly, Secure on https, SameSite), new session ID at login, 1-hour idle timeout | `includes/security.php` |
| Automatic https redirect on a real domain (never on localhost/LAN, never for the Arduino) + HSTS | `includes/security.php`, `config/security_config.php` |
| Browser security headers (CSP, anti-clickjacking, no MIME sniffing, referrer policy) | `includes/security.php`, `.htaccess` |
| Cross-site request forgery (CSRF) blocking on every POST | `includes/security.php` |
| Login brute-force lockout (5 tries per account+IP, 20 per IP, 15 per account, per 15 min) | `login.php` |
| **Closed an account-takeover hole**: "Forgot password" let anyone reset any employee's password knowing only their email | `employee_forgot_password.php` |
| Forced password change for any password equal to the username / Employee ID (imported employees). The default SuperAdmin password is no longer forced to change | `login.php` + `includes/security.php` |
| Password rules (8+ chars, not the username/ID, not a common password) | `includes/security.php` |
| Uploads must be REAL images, random file names, no script can run from `uploads/` | `includes/security.php`, `uploads/.htaccess` |
| `sql/`, `includes/`, `logs/`, `Arduino INO/`, `*.log`, `*.sql`, `*.md`, `*.txt` blocked from browsers | `.htaccess` files |
| PHP/SQL errors hidden from visitors (written to `logs/php_error.log`) | `config/db.php`, `includes/security.php` |
| Device endpoint: wrong-key guessing is rate limited | `device_punch.php` |
| CSV export protected against spreadsheet formula injection | `employee_export.php` |

## Behaviour changes you will notice
* Employees who are **not signed in** can no longer reset their own password. The admin sets it under **Employee > Edit > New Password**. Signed-in employees can change their own password (current password required).
* Imported employees (password = Employee ID) must change it at first sign-in. The SuperAdmin default password is NOT forced to change - change it yourself in Account Settings before going public.
* New passwords must be 8+ characters.
* Idle for 1 hour = signed out.

## Do these BEFORE publishing (the code cannot do them for you)
1. **Use HTTPS.** Install an SSL certificate (Let's Encrypt / your host's free SSL / Cloudflare).
2. **Change the default admin password** `M4sterAdm1n` (you will be forced to) - and delete or keep private the `sql/` folder on the server.
3. **Do not run MySQL as `root` with no password.** Copy `config/db.local.php.example` to `config/db.local.php` and use a dedicated database user.
4. **Change `DEVICE_API_KEY`** in `config/device_config.php` (and in the Arduino sketch). It is still `change-me-1234`. Also set `$DEVICE_ALLOWED_IPS` to the Arduino's public IP if it has a fixed one.
   The W5100 Ethernet shield cannot do https, so the device key travels in plain text - keep the Arduino on the same network as the server if you can.
5. **Replace your Gemini API key.** The key inside `config/ai_config.php` was shared in the project zip, so treat it as leaked: create a new one at aistudio.google.com, delete the old one, and paste the new one into the private file `config/ai.local.php` (copy `ai.local.php.example`; it is never uploaded to GitHub or overwritten by a deploy). The same goes for `DEVICE_API_KEY`: put it in `config/device.local.php`.
6. Keep PHP and MySQL/MariaDB updated; turn off phpMyAdmin on the public server (or protect it).
7. Make regular database backups, stored somewhere that is NOT inside the website folder.
8. If you use Nginx instead of Apache, `.htaccess` files are ignored - add `location ~ ^/(sql|includes|logs|config|Arduino INO)/ { deny all; }` and `location ~* ^/uploads/.*\.php$ { deny all; }` to your server config.
9. If the site is behind Cloudflare / a proxy, add the proxy IPs to `BT_TRUSTED_PROXIES` in `config/security_config.php` so login lockouts use each visitor's real IP.

## If something looks wrong
* Blank page / 500 error after upload: open `logs/php_error.log`.
* Stuck in a redirect loop on https: set `BT_FORCE_HTTPS` to `false` in `config/security_config.php` and tell me your hosting type.
* Locked yourself out of login: wait 15 minutes, or run `DELETE FROM security_attempts;` in phpMyAdmin.
