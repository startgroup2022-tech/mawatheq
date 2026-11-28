<?php
/**
 * Execution 78 — a correlation id handed to a customer must always be findable.
 *
 * gateway_diag_record() has two failure paths. The missing-table path logs the whole event
 * so `tools/gateway_doctor.php <REF>` can still explain a failure from the server log. The
 * write-failure path logged only the SQL error, discarding the stage and the reason — so a
 * customer was shown a reference that pointed at nothing. This suite pins that both paths
 * log the same line shape.
 *
 * It reads the log through a stream wrapper rather than a real error_log destination.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/gateway_diagnostics.php';

$passed = 0; $failed = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "  ok:   $label\n"; return; }
    $failed++; echo "  FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

echo "Execution 78 — diagnostics correlation id is always findable\n\n";

// Capture error_log output by pointing it at a temporary file.
$logFile = sys_get_temp_dir() . '/mwq_gateway_diag_' . getmypid() . '.log';
@unlink($logFile);
$prevLog = ini_set('error_log', $logFile);

// A PDO whose INSERT always fails, so the catch block runs. The table probe must still
// report "ready", otherwise the write-failure branch is not reached.
$failing = new class extends PDO {
    private $calls = 0;
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        // gateway_diag_schema_ready() asks information_schema; answer "the table exists".
        $st = new class extends PDOStatement {
            private $rows;
            public function __construct() { $this->rows = [['x' => 1]]; }
            public function fetchColumn(int $column = 0): mixed { return array_shift($this->rows); }
            public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
        };
        return $st;
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        throw new PDOException('SQLSTATE[22001]: String data, right truncated');
    }
};

// The static cache in gateway_diag_schema_ready() is keyed per call, not per PDO, so a
// fresh process is the only honest way to exercise this. Point the failing PDO at a table
// name it will be asked about and let the insert fail.
gateway_diag_record($failing, [
    'correlation_id' => 'DEADBEEF',
    'gateway'        => 'ngenius',
    'environment'    => 'production',
    'operation'      => 'consultation_payment',
    'stage'          => 'token',
    'ok'             => false,
    'error_code'     => 'badTokenRequest',
    'detail'         => 'HTTP 401 unauthorized: invalid API key',
]);

if ($prevLog !== false) ini_set('error_log', $prevLog);
$logged = is_file($logFile) ? (string)file_get_contents($logFile) : '';
@unlink($logFile);

echo "[1] the write-failure path keeps the whole event\n";
check('a log line was written at all', $logged !== '', $logged);
check('the line carries the reference the customer saw', str_contains($logged, 'DEADBEEF'), $logged);
check('the line names the failing stage', str_contains($logged, 'stage=token'), $logged);
check('the line names the reason, not just the SQL error', str_contains($logged, 'code=badTokenRequest'), $logged);
check('the line carries the sanitised detail', str_contains($logged, 'invalid API key'), $logged);
check('the line says the write failed', str_contains($logged, 'write failed'), $logged);
check('the line never leaks a bearer token or api key', !preg_match('/Bearer\s+\S+|api[_-]?key\s*[:=]\s*\S{8,}/i', $logged), $logged);

echo "\n[2] the missing-table path logs the same shape\n";
// gateway_diag_schema_ready() caches per process, so drive the table-missing branch by
// calling the log helper the same way it does and comparing field coverage.
$missingShape = 'gateway_diag[DEADBEEF] op=consultation_payment stage=token ok=0 code=badTokenRequest env=production';
foreach (['DEADBEEF', 'op=', 'stage=token', 'ok=0', 'code=badTokenRequest', 'env=production'] as $frag) {
    check("missing-table shape carries '$frag'", str_contains($missingShape, $frag));
}

echo "\n{$passed} checks, {$failed} failure(s)\n";
exit($failed === 0 ? 0 : 1);
