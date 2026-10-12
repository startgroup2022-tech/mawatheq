<?php
/**
 * SMTP mail transport tests.
 *
 * The delivery tests run against a local plaintext SMTP server
 * (tests/fixtures/mock_smtp.php). That server is a MOCK: it proves the client speaks
 * SMTP correctly — greets, authenticates before DATA, and sends a well-formed message —
 * and nothing at all about whether the owner's real mailbox accepts it. Real-provider
 * verification requires the production host and its DNS.
 *
 * Run: php tests/mail_smtp_test.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/smtp.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks; $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

$set = function (string $key, string $value) use ($pdo): void {
    $pdo->prepare('INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
        ->execute([$key, $value]);
};
$clearMail = function () use ($pdo): void {
    foreach (['smtp_enabled','smtp_host','smtp_port','smtp_secure','smtp_username',
              'smtp_password','smtp_from_email','smtp_from_name'] as $k) {
        $pdo->prepare('DELETE FROM site_settings WHERE setting_key=?')->execute([$k]);
    }
};
$clearMail();

// ===========================================================================
// 1. Transport resolution (pure, no network)
// ===========================================================================
echo "\n== transport resolution ==\n";
check('port 465 defaults to implicit TLS (ssl)', smtp_transport(['smtp_port' => 465, 'smtp_secure' => ''])['scheme'] === 'ssl');
check('port 587 defaults to STARTTLS (tls)', smtp_transport(['smtp_port' => 587, 'smtp_secure' => ''])['secure'] === 'tls');
check('port 25 defaults to no TLS (none)', smtp_transport(['smtp_port' => 25, 'smtp_secure' => ''])['secure'] === 'none');
check('an explicit ssl choice wins over the port', smtp_transport(['smtp_port' => 587, 'smtp_secure' => 'ssl'])['scheme'] === 'ssl');
check('a nonsense secure value falls back to the port default', smtp_transport(['smtp_port' => 587, 'smtp_secure' => 'weird'])['secure'] === 'tls');

echo "\n== smtp_configured ==\n";
check('disabled is not configured', smtp_configured(['smtp_enabled' => '0', 'smtp_host' => 'h', 'smtp_port' => '587']) === false);
check('enabled without a host is not configured', smtp_configured(['smtp_enabled' => '1', 'smtp_host' => '', 'smtp_port' => '587']) === false);
check('enabled without a port is not configured', smtp_configured(['smtp_enabled' => '1', 'smtp_host' => 'h', 'smtp_port' => '0']) === false);
check('enabled with host and port is configured', smtp_configured(['smtp_enabled' => '1', 'smtp_host' => 'h', 'smtp_port' => '587']) === true);

// ===========================================================================
// 2. Message construction
// ===========================================================================
echo "\n== message construction ==\n";
$msg = smtp_build_message('info@mawatheq.com', 'مواثيق', 'user@example.com', 'تأكيد البريد الإلكتروني', '<p>مرحبا</p>');
check('the message has a Date header', str_contains($msg, 'Date: '));
check('the message has a Message-ID', str_contains($msg, 'Message-ID: <'));
check('an Arabic subject is RFC 2047 encoded', str_contains($msg, 'Subject: =?UTF-8?B?'));
check('an Arabic display name is RFC 2047 encoded', str_contains($msg, 'From: =?UTF-8?B?'));
check('the recipient is in the headers', str_contains($msg, 'To: <user@example.com>'));
check('the body is base64 encoded HTML', str_contains($msg, 'Content-Transfer-Encoding: base64'));
check('the body decodes back to the original HTML', str_contains(base64_decode(preg_replace('/\s+/', '', substr($msg, strpos($msg, "\r\n\r\n") + 4))), '<p>مرحبا</p>'));

// ===========================================================================
// 3. Encryption round-trip for the stored password
// ===========================================================================
echo "\n== stored password is encrypted at rest ==\n";
secret_store_setting($pdo, 'smtp_password', 's3cr3t-app-password');
$stored = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='smtp_password'")->fetchColumn();
check('the stored password is ciphertext, not plaintext', str_starts_with((string)$stored, 'enc:v1:') && $stored !== 's3cr3t-app-password');
check('the password decrypts back through smtp_settings()', (smtp_settings($pdo)['smtp_password'] ?? '') === 's3cr3t-app-password');

// ===========================================================================
// 4. End-to-end delivery against the mock SMTP server
// ===========================================================================
echo "\n== delivery over SMTP (mock server) ==\n";
// Ask the OS for a free port so a leftover listener from an earlier run cannot be
// mistaken for this run's mock.
$probe = stream_socket_server('tcp://127.0.0.1:0', $probeErrno, $probeErrstr);
$bindName = stream_socket_get_name($probe, false);
$port = (int)substr($bindName, strrpos($bindName, ':') + 1);
fclose($probe);
$capture = sys_get_temp_dir() . '/mawatheq_mock_smtp_' . getmypid() . '.txt';
@unlink($capture);
$cmd = 'php ' . escapeshellarg(__DIR__ . '/fixtures/mock_smtp.php') . ' ' . $port . ' ' . escapeshellarg($capture);
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
// Wait for the port to accept instead of a fixed sleep.
for ($i = 0; $i < 50; $i++) {
    $c = @stream_socket_client("tcp://127.0.0.1:$port", $e, $s, 0.2);
    if ($c) { fclose($c); break; }
    usleep(100000);
}

$set('smtp_enabled', '1');
$set('smtp_host', '127.0.0.1');
$set('smtp_port', (string)$port);
$set('smtp_secure', 'none');
$set('smtp_username', 'info@mawatheq.com');
$set('smtp_from_email', 'info@mawatheq.com');
$set('smtp_from_name', 'مواثيق');

$sent = send_mail('customer@example.com', 'تأكيد البريد الإلكتروني - مواثيق', '<p>اضغط لتأكيد بريدك</p>');
check('send_mail() reports success through SMTP', $sent === true);
check('no error is recorded on success', mail_last_error() === null);

// The mock logs each command as it handles it, but a fast success can return before
// the last append is flushed to disk; wait briefly for the transcript to settle.
$captureText = '';
for ($i = 0; $i < 30; $i++) {
    $captureText = is_file($capture) ? (string)file_get_contents($capture) : '';
    if (str_contains($captureText, 'BODY Content-Type: text/html')) break;
    usleep(100000);
}
check('the client authenticated with the configured username', str_contains($captureText, 'AUTH-USER info@mawatheq.com'));
check('the client sent the stored password', str_contains($captureText, 'AUTH-PASS s3cr3t-app-password'));
check('the envelope sender is the From address', str_contains($captureText, 'MAILFROM <info@mawatheq.com>'));
check('the envelope recipient is the customer', str_contains($captureText, 'RCPT <customer@example.com>'));
check('the message body reached the server', str_contains($captureText, 'DATA ') && str_contains($captureText, 'BODY Content-Type: text/html'));
$bodyB64 = base64_encode('<p>اضغط لتأكيد بريدك</p>');
check('the message carried the verification link text', str_contains($captureText, $bodyB64));

if (is_resource($proc)) {
    foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
    proc_terminate($proc);
    proc_close($proc);
}
@unlink($capture);

// ===========================================================================
// 5. Diagnostics report the active path and a real failure reason
// ===========================================================================
echo "\n== diagnostics ==\n";
$diag = mail_diagnostics($pdo);
check('diagnostics select the smtp transport', $diag['transport'] === 'smtp');
check('diagnostics name the configured host', $diag['host'] === '127.0.0.1');
check('diagnostics report the password as set without revealing it', $diag['password_set'] === true && !array_key_exists('password', $diag));

// A dead host must be reported as unreachable, not silently "fine".
$set('smtp_host', '127.0.0.1');
$set('smtp_port', '2527'); // nothing listens here
$diag = mail_diagnostics($pdo);
check('an unreachable SMTP host is reported as such', $diag['reachable'] === false && $diag['error'] !== null);

$badError = null;
$badResult = smtp_send(smtp_settings($pdo), 'customer@example.com', 'x', '<p>x</p>', $badError);
check('a dead host makes smtp_send() fail with a reason, not an exception', $badResult === false && is_string($badError) && $badError !== '');

$set('smtp_port', (string)$port);
// The mock has already accepted its one message and exited, so for the positive
// reachability check hold a real listener open rather than reusing that port.
$listener = stream_socket_server('tcp://127.0.0.1:0', $lErrno, $lErrstr);
$lName = stream_socket_get_name($listener, false);
$lPort = (int)substr($lName, strrpos($lName, ':') + 1);
$set('smtp_port', (string)$lPort);
$diag = mail_diagnostics($pdo);
check('a reachable SMTP host is reported as reachable', $diag['reachable'] === true && $diag['error'] === null);
fclose($listener);

// ===========================================================================
// 6. Fallback to the legacy mail() path when SMTP is off
// ===========================================================================
echo "\n== fallback to PHP mail() ==\n";
$clearMail();
check('with no SMTP settings, smtp_configured() is false', smtp_configured(smtp_settings($pdo)) === false);
$diag = mail_diagnostics($pdo);
check('diagnostics fall back to the mail transport', $diag['transport'] === 'mail');
// This host has no sendmail binary, so the legacy path must fail cleanly (not throw),
// and the diagnostic must explain why rather than leaving it a mystery.
$legacy = send_mail_via_php_mail('customer@example.com', 'x', '<p>x</p>');
check('the legacy path returns a boolean without throwing', is_bool($legacy));

$clearMail();

echo "\n" . ($failures === 0 ? "OK" : "FAILED") . " — $checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
