<?php
// Runs in a child process after the test renames includes/smtp.php away.
// Proves send_mail() still works (no fatal) when the optional SMTP module is absent.
require __DIR__ . '/../../config/config.php';
require __DIR__ . '/../../includes/mailer.php';
$GLOBALS['pdo'] = $pdo;
$result = send_mail('probe@example.com', 'probe', '<p>probe</p>');
echo var_export($result, true);
