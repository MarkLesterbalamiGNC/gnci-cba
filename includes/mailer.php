<?php
/**
 * Tiny email sender for BioTrack (no Composer / no extra downloads needed).
 *
 *   require_once 'includes/mailer.php';
 *   [$ok, $error] = bt_send_mail('dean@school.edu', 'Dean Name', 'Subject', '<p>HTML body</p>',
 *                                'Plain text body', [['name' => 'report.html', 'type' => 'text/html', 'data' => $html]]);
 *
 * Settings live in config/mail_config.php (SMTP host, port, login, "from" name).
 * Works with Gmail (use an App Password), a domain mailbox from your hosting,
 * or any other SMTP server. If MAIL_DRIVER is 'mail', PHP's built-in mail() is used instead.
 */

require_once __DIR__ . '/../config/mail_config.php';

/** True once the SMTP login / from-address has been filled in. */
function bt_mail_configured(): bool {
    if (MAIL_DRIVER === 'mail') return MAIL_FROM_EMAIL !== '';
    return MAIL_HOST !== '' && MAIL_USERNAME !== '' && MAIL_PASSWORD !== '' && MAIL_FROM_EMAIL !== '';
}

/** Remove line breaks so a value can never inject extra email headers. */
function bt_mail_clean_header(string $s): string {
    return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
}

/** "Name" <address> with the name encoded for non-English letters. */
function bt_mail_address(string $email, string $name = ''): string {
    $email = bt_mail_clean_header($email);
    $name  = bt_mail_clean_header($name);
    if ($name === '') return '<' . $email . '>';
    if (preg_match('/[^\x20-\x7E]/', $name)) $name = '=?UTF-8?B?' . base64_encode($name) . '?=';
    else $name = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';
    return $name . ' <' . $email . '>';
}

function bt_mail_subject(string $s): string {
    $s = bt_mail_clean_header($s);
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/**
 * Build the full message (headers + body). Returns [headersString, bodyString].
 * $attachments = [['name' => 'file.html', 'type' => 'text/html', 'data' => '...raw bytes...'], ...]
 */
function bt_mail_build(string $toEmail, string $toName, string $subject, string $html, string $text, array $attachments): array {
    $host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST) ?: 'localhost';
    $msgId = '<' . bin2hex(random_bytes(12)) . '@' . preg_replace('/[^A-Za-z0-9.\-]/', '', $host) . '>';

    $mixed = 'bt-m-' . bin2hex(random_bytes(8));
    $alt   = 'bt-a-' . bin2hex(random_bytes(8));

    $h  = 'Date: ' . date('r') . "\r\n";
    $h .= 'From: ' . bt_mail_address(MAIL_FROM_EMAIL, MAIL_FROM_NAME) . "\r\n";
    $h .= 'To: ' . bt_mail_address($toEmail, $toName) . "\r\n";
    $h .= 'Subject: ' . bt_mail_subject($subject) . "\r\n";
    $h .= 'Message-ID: ' . $msgId . "\r\n";
    $h .= "MIME-Version: 1.0\r\n";
    $h .= 'Content-Type: multipart/mixed; boundary="' . $mixed . "\"\r\n";

    $b  = '--' . $mixed . "\r\n";
    $b .= 'Content-Type: multipart/alternative; boundary="' . $alt . "\"\r\n\r\n";

    $b .= '--' . $alt . "\r\n";
    $b .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $b .= chunk_split(base64_encode($text !== '' ? $text : strip_tags($html)), 76, "\r\n");

    $b .= '--' . $alt . "\r\n";
    $b .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $b .= chunk_split(base64_encode($html), 76, "\r\n");
    $b .= '--' . $alt . "--\r\n";

    foreach ($attachments as $a) {
        $fname = preg_replace('/[^A-Za-z0-9._\- ]/', '_', (string)($a['name'] ?? 'file'));
        $b .= '--' . $mixed . "\r\n";
        $b .= 'Content-Type: ' . bt_mail_clean_header((string)($a['type'] ?? 'application/octet-stream')) . '; name="' . $fname . "\"\r\n";
        $b .= "Content-Transfer-Encoding: base64\r\n";
        $b .= 'Content-Disposition: attachment; filename="' . $fname . "\"\r\n\r\n";
        $b .= chunk_split(base64_encode((string)$a['data']), 76, "\r\n");
    }
    $b .= '--' . $mixed . "--\r\n";
    return [$h, $b];
}

/** Read one (possibly multi-line) SMTP reply. Returns [code, text]. */
function bt_smtp_read($fp): array {
    $text = '';
    $code = 0;
    while (($line = fgets($fp, 1024)) !== false) {
        $text .= $line;
        $code = (int)substr($line, 0, 3);
        if (strlen($line) < 4 || $line[3] !== '-') break;   // last line of the reply
    }
    return [$code, trim($text)];
}

/** Send one command and check the reply code. Throws on a bad reply. */
function bt_smtp_cmd($fp, string $cmd, array $okCodes, string $label = ''): string {
    if ($cmd !== '') fwrite($fp, $cmd . "\r\n");
    [$code, $text] = bt_smtp_read($fp);
    if (!in_array($code, $okCodes, true)) {
        throw new RuntimeException(($label !== '' ? $label : 'SMTP') . ' failed (' . $code . '): ' . $text);
    }
    return $text;
}

/**
 * Send an email. Returns [true, ''] on success or [false, 'what went wrong'].
 */
function bt_send_mail(string $toEmail, string $toName, string $subject, string $html, string $text = '', array $attachments = []): array {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return [false, 'The recipient email address is not valid.'];
    if (!bt_mail_configured()) return [false, 'Email is not set up yet. Fill in config/mail_config.php first.'];

    try {
        [$headers, $body] = bt_mail_build($toEmail, $toName, $subject, $html, $text, $attachments);

        if (MAIL_DRIVER === 'mail') {
            $ok = @mail(bt_mail_clean_header($toEmail), bt_mail_subject($subject), $body,
                        preg_replace('/^(To|Subject): .*\r\n/mi', '', $headers));
            return $ok ? [true, ''] : [false, "PHP's mail() could not send the message. Use SMTP (see config/mail_config.php)."];
        }

        $secure = strtolower(MAIL_SECURE);   // 'ssl' (port 465), 'tls' (port 587, STARTTLS) or '' (none)
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . MAIL_HOST . ':' . (int)MAIL_PORT;
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'peer_name' => MAIL_HOST,
        ]]);
        $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new RuntimeException("Could not connect to the mail server ($errstr). Check MAIL_HOST / MAIL_PORT and that the server allows outgoing mail.");
        stream_set_timeout($fp, 30);

        try {
            bt_smtp_cmd($fp, '', [220], 'Greeting');
            $me = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
            bt_smtp_cmd($fp, 'EHLO ' . $me, [250], 'EHLO');

            if ($secure === 'tls') {
                bt_smtp_cmd($fp, 'STARTTLS', [220], 'STARTTLS');
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Could not start a secure (TLS) connection with the mail server.');
                }
                bt_smtp_cmd($fp, 'EHLO ' . $me, [250], 'EHLO');
            }

            if (MAIL_USERNAME !== '') {
                bt_smtp_cmd($fp, 'AUTH LOGIN', [334], 'Login');
                bt_smtp_cmd($fp, base64_encode(MAIL_USERNAME), [334], 'Username');
                bt_smtp_cmd($fp, base64_encode(MAIL_PASSWORD), [235], 'Password (for Gmail use an App Password, not your normal password)');
            }

            bt_smtp_cmd($fp, 'MAIL FROM:<' . bt_mail_clean_header(MAIL_FROM_EMAIL) . '>', [250], 'Sender');
            bt_smtp_cmd($fp, 'RCPT TO:<' . bt_mail_clean_header($toEmail) . '>', [250, 251], 'Recipient');
            bt_smtp_cmd($fp, 'DATA', [354], 'DATA');

            // Dot-stuffing: a line that starts with "." must be doubled.
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $headers . "\r\n" . $body));
            $data = str_replace("\n", "\r\n", $data);
            fwrite($fp, $data . "\r\n.\r\n");
            [$code, $reply] = bt_smtp_read($fp);
            if ($code !== 250) throw new RuntimeException('The mail server did not accept the message (' . $code . '): ' . $reply);

            @fwrite($fp, "QUIT\r\n");
        } finally {
            @fclose($fp);
        }
        return [true, ''];
    } catch (Throwable $e) {
        error_log('BioTrack mail error: ' . $e->getMessage());
        return [false, $e->getMessage()];
    }
}
