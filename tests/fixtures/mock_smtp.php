<?php
/**
 * Minimal plaintext SMTP server for the mail tests.
 *
 * It accepts a connection, offers AUTH LOGIN, records the envelope and message, and
 * answers with real SMTP reply codes. It deliberately does NOT offer STARTTLS, so the
 * tests exercise the unencrypted "none" transport; the handshake/crypto path is
 * exercised only in production. Usage: php tests/fixtures/mock_smtp.php <port> <captureFile>
 */
$port = (int)($argv[1] ?? 2525);
$capture = $argv[2] ?? '/tmp/mock_smtp_capture.txt';

$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "mock smtp: cannot bind: $errstr\n");
    exit(1);
}
fwrite(STDOUT, "mock smtp listening on $port\n");
fflush(STDOUT);

file_put_contents($capture, '');

$conn = @stream_socket_accept($server, 30);
if (!$conn) {
    fwrite(STDERR, "mock smtp: no connection within timeout\n");
    exit(1);
}

$log = function (string $line) use ($capture): void {
    file_put_contents($capture, $line . "\n", FILE_APPEND);
};

// Serve connections until one completes a real message, then exit. A readiness probe
// connects and closes without sending; that must not stop the server prematurely, and
// the server must not linger after the test it belongs to has ended.
while ($conn) {
    if (handle_connection($conn, $log)) {
        @fclose($conn);
        break;
    }
    @fclose($conn);
    $conn = @stream_socket_accept($server, 15);
}

fclose($server);
$log('DONE');
exit(0);

/** Returns true once a full message has been accepted, so the caller can exit. */
function handle_connection($conn, callable $log): bool
{
    fwrite($conn, "220 mock.local ESMTP\r\n");
    $authed = false;
    $inData = false;
    $dataLines = [];
    $accepted = false;

    while (($line = fgets($conn, 4096)) !== false) {
        $trimmed = rtrim($line, "\r\n");
        if ($inData) {
            if ($trimmed === '.') {
                $inData = false;
                $log('DATA ' . count($dataLines) . ' lines');
                foreach ($dataLines as $dl) $log('BODY ' . $dl);
                $dataLines = [];
                fwrite($conn, "250 2.0.0 Ok: queued as MOCK123\r\n");
                $accepted = true;
                break;
            }
            $dataLines[] = $trimmed;
            continue;
        }
        $upper = strtoupper($trimmed);
        if (str_starts_with($upper, 'EHLO') || str_starts_with($upper, 'HELO')) {
            // No STARTTLS advertised: forces the "none" transport for the test.
            fwrite($conn, "250-mock.local\r\n250-AUTH LOGIN PLAIN\r\n250 SIZE 10485760\r\n");
        } elseif (str_starts_with($upper, 'AUTH LOGIN')) {
            $log('AUTH LOGIN');
            fwrite($conn, "334 VXNlcm5hbWU6\r\n");
            $u = rtrim((string)fgets($conn, 4096), "\r\n");
            $log('AUTH-USER ' . base64_decode($u));
            fwrite($conn, "334 UGFzc3dvcmQ6\r\n");
            $p = rtrim((string)fgets($conn, 4096), "\r\n");
            $log('AUTH-PASS ' . base64_decode($p));
            $authed = true;
            fwrite($conn, "235 2.7.0 Authentication successful\r\n");
        } elseif (str_starts_with($upper, 'MAIL FROM:')) {
            $log('MAILFROM ' . substr($trimmed, 10));
            fwrite($conn, "250 2.1.0 Ok\r\n");
        } elseif (str_starts_with($upper, 'RCPT TO:')) {
            $log('RCPT ' . substr($trimmed, 8));
            fwrite($conn, "250 2.1.5 Ok\r\n");
        } elseif ($upper === 'DATA') {
            if (!$authed) {
                // Mirror a relay that refuses unauthenticated mail, so the test proves
                // the client authenticated before DATA.
                fwrite($conn, "530 5.7.0 Authentication required\r\n");
                continue;
            }
            $inData = true;
            fwrite($conn, "354 End data with <CR><LF>.<CR><LF>\r\n");
        } elseif ($upper === 'QUIT') {
            fwrite($conn, "221 2.0.0 Bye\r\n");
            break;
        } elseif ($upper === 'STARTTLS') {
            fwrite($conn, "502 STARTTLS not implemented\r\n");
        } else {
            fwrite($conn, "502 Command not implemented\r\n");
        }
    }
    return $accepted;
}
