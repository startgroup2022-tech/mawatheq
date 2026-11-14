<?php
/**
 * مواثيق — تصفية المكالمات التي لم يُرد عليها (Execution 57)
 * Expire calls that rang past the timeout and record them as missed.
 *
 * The call endpoints also sweep opportunistically, so this cron is a backstop for a
 * deployment where nobody opens the app: without it a ringing call would sit in
 * `ringing` forever and the client would show a stale incoming-call screen on the
 * next launch.
 *
 * Run every minute:
 *   * * * * * /usr/bin/php /path/to/cron/calls.php >> /path/to/storage/logs/calls.log 2>&1
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/calls.php';

$missed = call_sweep_stale($pdo);
echo date('c') . " — expired $missed ringing call(s)\n";
