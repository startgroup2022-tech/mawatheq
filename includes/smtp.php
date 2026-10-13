<?php
/**
 * SMTP transport for outbound system mail (account verification, password reset,
 * notifications).
 *
 * PHP's built-in mail() hands the message to a local MTA, usually sendmail/Exim on
 * the same host. On shared cPanel hosting that hand-off is unreliable and opaque:
 * mail() can return true while nothing is ever delivered, and it offers no
 * authentication — a host whose sending IP lacks reverse-DNS / SPF / DKIM alignment
 * has its mail rejected or spam-foldered with no visible error in the app.
 *
 * This module lets the owner deliver through the mailbox provider that actually owns
 * the domain (the one whose SPF/DKIM records are published), over an authenticated
 * connection with STARTTLS. It speaks SMTP itself, so it needs no extension beyond
 * OpenSSL, and it reports the real server response instead of a bare boolean.
 */

require_once __DIR__ . '/secret_crypto.php';

/** Load the mail settings from site_settings, decrypting the stored password. */
function smtp_settings(PDO $pdo): array {
    $keys = "'smtp_enabled','smtp_host','smtp_port','smtp_secure','smtp_username',"
        . "'smtp_password','smtp_from_email','smtp_from_name'";
    $settings = [];
    foreach ($pdo->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ($keys)")->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    if (isset($settings['smtp_password'])) {
        $stored = $settings['smtp_password'];
        $settings['smtp_password'] = secret_decrypt_value($stored);
        // Upgrade a legacy plaintext value to encrypted storage, as the AI/payment keys do.
        if ($settings['smtp_password'] !== '' && strpos((string)$stored, 'enc:v1:') !== 0 && secret_encryption_key()) {
            secret_store_setting($pdo, 'smtp_password', $settings['smtp_password']);
        }
    }
    return $settings;
}

/** Whether the owner has supplied enough to attempt an SMTP delivery. */
function smtp_configured(array $settings): bool {
    return ($settings['smtp_enabled'] ?? '') === '1'
        && trim((string)($settings['smtp_host'] ?? '')) !== ''
        && (int)($settings['smtp_port'] ?? 0) > 0;
}

/**
 * Resolve the connection scheme and TLS mode from the port and the explicit choice.
 *
 * 'ssl' means the TLS handshake happens before SMTP (implicit TLS, port 465);
 * 'tls' means STARTTLS upgrades the plain connection (port 587/25); 'none' is an
 * unencrypted relay, acceptable only for a local or intra-network mail server.
 */
function smtp_transport(array $settings): array {
    $port = (int)($settings['smtp_port'] ?? 0);
    $secure = strtolower(trim((string)($settings['smtp_secure'] ?? '')));
    if (!in_array($secure, ['ssl', 'tls', 'none'], true)) {
        $secure = $port === 465 ? 'ssl' : ($port === 25 ? 'none' : 'tls');
    }
    return ['scheme' => $secure === 'ssl' ? 'ssl' : 'tcp', 'secure' => $secure, 'port' => $port];
}

/** Read one SMTP reply, tolerating continuation lines ("250-..." then "250 ..."). */
function smtp_read_reply($fp, array $expect, ?string &$error, string $context): bool {
    $line = '';
    do {
        $line = fgets($fp, 516);
        if ($line === false) {
            $error = "no response from server during $context";
            return false;
        }
    } while (isset($line[3]) && $line[3] === '-');
    $code = (int)substr(trim($line), 0, 3);
    if (!in_array($code, $expect, true)) {
        $error = "$context: " . trim($line);
        return false;
    }
    return true;
}

/** Send one command and read its reply. */
function smtp_command($fp, string $command, array $expect, ?string &$error, string $context): bool {
    fwrite($fp, $command . "\r\n");
    return smtp_read_reply($fp, $expect, $error, $context);
}

/** The envelope sender used for From:, Reply-To:, MAIL FROM and Message-ID. */
function smtp_resolve_identity(array $settings): array {
    $email = trim((string)($settings['smtp_from_email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = defined('MAIL_FROM_EMAIL') ? trim((string)MAIL_FROM_EMAIL) : '';
    }
    $name = trim((string)($settings['smtp_from_name'] ?? ''));
    if ($name === '') $name = defined('MAIL_FROM_NAME') ? trim((string)MAIL_FROM_NAME) : 'Mawatheq';
    return [$email, preg_replace('/[\r\n]+/', ' ', $name)];
}

/** Encode a header value as UTF-8 when it holds non-ASCII (Arabic subjects/names). */
function smtp_encode_word(string $value): string {
    return preg_match('/[\x80-\xff]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
}

/** Build the RFC 5322 message: headers, a blank line, then the dot-stuffed body. */
function smtp_build_message(string $fromEmail, string $fromName, string $to, string $subject, string $htmlBody): string {
    $headers = [
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (parse_url(SITE_URL, PHP_URL_HOST) ?: 'localhost') . '>',
        'From: ' . smtp_encode_word($fromName) . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'To: <' . $to . '>',
        'Subject: ' . smtp_encode_word($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: Mawatheq',
    ];
    $body = chunk_split(base64_encode($htmlBody), 76, "\r\n");
    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

/**
 * Deliver one HTML message over SMTP.
 *
 * Returns true only when the server accepted the message; on any failure $error holds
 * the server's own response so the cause is visible instead of guessed at.
 */
function smtp_send(array $settings, string $to, string $subject, string $htmlBody, ?string &$error = null): bool {
    [$fromEmail, $fromName] = smtp_resolve_identity($settings);
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $fromEmail)) {
        $error = 'sender address is invalid';
        return false;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) {
        $error = 'recipient address is invalid';
        return false;
    }

    $transport = smtp_transport($settings);
    $host = trim((string)$settings['smtp_host']);
    $timeout = 15;
    $sslOptions = ['verify_peer' => true, 'verify_peer_name' => true];
    // A host with its own private CA (or a self-hosted relay) can point at a CA bundle;
    // production normally leaves this undefined and uses the system trust store.
    if (defined('MAWATHEQ_SMTP_CA_FILE') && is_file((string)MAWATHEQ_SMTP_CA_FILE)) {
        $sslOptions['cafile'] = (string)MAWATHEQ_SMTP_CA_FILE;
    }
    $context = stream_context_create(['ssl' => $sslOptions]);
    $fp = @stream_socket_client(
        $transport['scheme'] . '://' . $host . ':' . $transport['port'],
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if (!$fp) {
        $error = "cannot connect to $host:{$transport['port']} ($errstr)";
        return false;
    }
    stream_set_timeout($fp, $timeout);

    $fail = function (string $message) use ($fp, &$error): bool {
        $error = $message;
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return false;
    };

    if (!smtp_read_reply($fp, [220], $error, 'greeting')) return $fail($error);

    $hostname = parse_url(SITE_URL, PHP_URL_HOST) ?: 'localhost';
    if (!smtp_command($fp, 'EHLO ' . $hostname, [250], $error, 'EHLO')) return $fail($error);

    if ($transport['secure'] === 'tls') {
        if (!smtp_command($fp, 'STARTTLS', [220], $error, 'STARTTLS')) return $fail($error);
        $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
            return $fail('STARTTLS handshake failed');
        }
        if (!smtp_command($fp, 'EHLO ' . $hostname, [250], $error, 'EHLO after STARTTLS')) return $fail($error);
    }

    $username = trim((string)($settings['smtp_username'] ?? ''));
    $password = (string)($settings['smtp_password'] ?? '');
    if ($username !== '') {
        if (!smtp_command($fp, 'AUTH LOGIN', [334], $error, 'AUTH LOGIN')) return $fail($error);
        if (!smtp_command($fp, base64_encode($username), [334], $error, 'AUTH username')) return $fail($error);
        if (!smtp_command($fp, base64_encode($password), [235], $error, 'AUTH password')) return $fail($error);
    }

    if (!smtp_command($fp, 'MAIL FROM:<' . $fromEmail . '>', [250], $error, 'MAIL FROM')) return $fail($error);
    if (!smtp_command($fp, 'RCPT TO:<' . $to . '>', [250, 251], $error, 'RCPT TO')) return $fail($error);
    if (!smtp_command($fp, 'DATA', [354], $error, 'DATA')) return $fail($error);

    fwrite($fp, smtp_build_message($fromEmail, $fromName, $to, $subject, $htmlBody) . "\r\n.\r\n");
    if (!smtp_read_reply($fp, [250], $error, 'message body')) return $fail($error);

    smtp_command($fp, 'QUIT', [221], $error, 'QUIT');
    @fclose($fp);
    $error = null;
    return true;
}

/**
 * Read-only diagnostic for the admin page: reports why mail will or will not leave
 * the host. Never returns the SMTP password.
 */
function mail_diagnostics(PDO $pdo): array {
    $settings = smtp_settings($pdo);
    $smtpOn = smtp_configured($settings);
    [$fromEmail, $fromName] = smtp_resolve_identity($settings);
    $fromEmail = trim((string)($settings['smtp_from_email'] ?? ''));
    $effectiveFrom = filter_var($fromEmail, FILTER_VALIDATE_EMAIL)
        ? $fromEmail
        : (defined('MAIL_FROM_EMAIL') ? (string)MAIL_FROM_EMAIL : '');
    $transport = smtp_transport($settings);

    $lastError = null;
    $smtpError = null;
    if ($smtpOn) {
        // Fail fast on a bad host/port rather than leaving the owner to guess.
        $probe = @stream_socket_client(
            $transport['scheme'] . '://' . trim((string)$settings['smtp_host']) . ':' . $transport['port'],
            $errno,
            $errstr,
            5
        );
        if ($probe) {
            @fclose($probe);
        } else {
            $smtpError = "cannot reach the SMTP host ($errstr)";
        }
    } else {
        $sendmailPath = (string)ini_get('sendmail_path');
        $binary = trim(strtok($sendmailPath, ' ') ?: '');
        if ($binary === '' || !is_file($binary)) {
            $lastError = 'PHP mail() has no local sendmail binary to hand off to';
        }
    }

    return [
        'transport' => $smtpOn ? 'smtp' : 'mail',
        'smtp_enabled' => $smtpOn,
        'host' => (string)($settings['smtp_host'] ?? ''),
        'port' => $transport['port'],
        'secure' => $transport['secure'],
        'username_set' => trim((string)($settings['smtp_username'] ?? '')) !== '',
        'password_set' => (string)($settings['smtp_password'] ?? '') !== '',
        'from_email' => $effectiveFrom,
        'from_name' => $fromName,
        'reachable' => $smtpOn ? $smtpError === null : null,
        'error' => $smtpOn ? $smtpError : $lastError,
    ];
}
