<?php
/**
 * Execution 78 — the deployment preflight runs without notices.
 *
 * `database/repair/00_preflight_check.php` is the tool an operator is told to run after
 * every deploy, and its exit code decides whether a release ships. It printed
 * "PHP Warning: Undefined array key \"data_only\"" on every run, because a seed migration
 * that declares no schema object has its own state that the tally array did not know about.
 *
 * A warning on stdout is far worse here than elsewhere: the operator reads a wall of output,
 * the exit code was still 0, and a real problem could scroll past unnoticed. This suite fails
 * if the script emits any PHP notice, warning or deprecation.
 *
 * Usage: php tests/exec78_preflight_clean_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/config/config.php';

$checks = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $checks, $fail;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; return; }
    $fail++;
    echo "  FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

echo "Execution 78 — deployment preflight is warning-free\n\n";

// --database= takes the name explicitly; a secrets.php DB_NAME define otherwise wins over
// the environment and the tool would check the wrong schema.
$db = getenv('MAWATHEQ_TEST_DB') ?: 'mawatheq_test';
$script = $root . '/database/repair/00_preflight_check.php';
check('the preflight script exists', is_file($script));

$cmd = 'php ' . escapeshellarg($script) . ' --database=' . escapeshellarg($db) . ' 2>&1';
$out = [];
exec($cmd, $out, $rc);
$text = implode("\n", $out);

echo "[1] no PHP diagnostics on the operator's screen\n";
foreach (['Undefined array key', 'Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Parse error'] as $needle) {
    check("output has no '{$needle}'", !str_contains($text, $needle),
        trim((string)preg_replace('/.*' . preg_quote($needle, '/') . '.*/u', '$0', $text)));
}

echo "[2] the tally is internally consistent\n";
// 82 migration files at this commit; the exact number moves, so assert the parts add up
// instead of pinning a count that will be stale next execution.
if (preg_match('/verified:\s*(\d+)\s*\|\s*data_only:\s*(\d+)\s*\|\s*partial:\s*(\d+)\s*\|\s*missing:\s*(\d+)\s*\|\s*unknown:\s*(\d+)/', $text, $m)) {
    $sum = (int)$m[1] + (int)$m[2] + (int)$m[3] + (int)$m[4] + (int)$m[5];
    $onDisk = count(glob($root . '/database/upgrade_v*.sql') ?: []);
    check('the summary line names data_only', true);
    check('every migration file is accounted for', $sum === $onDisk, "sum=$sum onDisk=$onDisk");
    // Drift on a shared development database is a fact about that database, not about this
    // script — the tool's job is to *report* it, and this suite's job is to prove it reports
    // cleanly. Asserting "nothing is partial" here made the suite fail for a database state
    // an earlier suite left behind, which is the phantom-failure pattern AGENTS.md warns
    // about. The state must be in the tally and the exit code must agree with it instead.
    $drift = (int)$m[3] + (int)$m[4];
    check('drift is reported in the same line it is counted in', true, "partial={$m[3]} missing={$m[4]}");
} else {
    check('the summary line is present and parseable', false, $text);
}

echo "[3] the exit code still means something\n";
// The exit code must agree with what the tool reported: 0 only when nothing is blocking.
// A tool that prints blockers and exits 0 is the failure mode this assertion exists for.
check('the exit code agrees with the reported state', ($drift === 0) ? ($rc === 0) : ($rc !== 0),
    "rc=$rc drift=$drift");

echo "\n{$checks} checks, {$fail} failure(s)\n";
exit($fail === 0 ? 0 : 1);
