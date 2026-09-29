<?php
// Run daily from cPanel Cron Jobs, e.g. at 03:00:
//   php /home/CPANEL_USER/public_html/cron/backup.php
// Produces a timestamped, gzip-compressed SQL dump plus a tar.gz of the uploads/ directory,
// stored under storage/backups/ (outside the web-servable document root is preferred — see
// DEPLOY_CPANEL_AR.md for moving BACKUP_DIR under home/ once configured). Old backups beyond
// BACKUP_RETENTION_DAYS are pruned automatically. This does not replace host-level (cPanel)
// backups — keep both, since a compromised server can lose locally-stored backups too.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';

define('BACKUP_DIR', getenv('MAWATHEQ_BACKUP_DIR') ?: (__DIR__ . '/../storage/backups'));
define('BACKUP_RETENTION_DAYS', (int)(getenv('MAWATHEQ_BACKUP_RETENTION_DAYS') ?: 14));

if (!is_dir(BACKUP_DIR) && !mkdir(BACKUP_DIR, 0750, true) && !is_dir(BACKUP_DIR)) {
    error_log('[backup] failed to create backup directory: ' . BACKUP_DIR);
    exit(1);
}
// Deny direct web access even if this ends up under a public path by mistake.
$htaccessGuard = BACKUP_DIR . '/.htaccess';
if (!is_file($htaccessGuard)) {
    @file_put_contents($htaccessGuard, "Require all denied\n");
}

$stamp = date('Y-m-d_His');
$exitCode = 0;

// --- Database dump -------------------------------------------------------
$sqlPath = BACKUP_DIR . "/db_{$stamp}.sql.gz";
$mysqldump = trim((string)shell_exec('command -v mysqldump 2>/dev/null'));

if ($mysqldump !== '') {
    // Prefer mysqldump when the hosting account allows shell_exec; credentials are passed via a
    // temporary defaults file so they never appear in the process list (visible to other users
    // on shared hosting via `ps`).
    $defaultsFile = tempnam(sys_get_temp_dir(), 'mwq_');
    file_put_contents($defaultsFile, "[client]\nhost=" . DB_HOST . "\nuser=" . DB_USER . "\npassword=" . DB_PASS . "\n");
    chmod($defaultsFile, 0600);
    $cmd = sprintf(
        '%s --defaults-extra-file=%s --single-transaction --quick --routines --triggers %s | gzip -9 > %s',
        escapeshellarg($mysqldump),
        escapeshellarg($defaultsFile),
        escapeshellarg(DB_NAME),
        escapeshellarg($sqlPath)
    );
    exec($cmd, $out, $code);
    @unlink($defaultsFile);
    if ($code !== 0 || !is_file($sqlPath) || filesize($sqlPath) < 100) {
        error_log('[backup] mysqldump failed, falling back to PDO export');
        @unlink($sqlPath);
        $mysqldump = '';
    }
}

if ($mysqldump === '') {
    // Fallback for hosts without shell_exec/mysqldump: export via PDO. Slower and does not
    // include routines/triggers/views, but covers table data and structure for restore.
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $gz = gzopen($sqlPath, 'w9');
    gzwrite($gz, "-- Mawatheq PDO fallback backup — {$stamp}\nSET FOREIGN_KEY_CHECKS=0;\n");
    foreach ($tables as $table) {
        $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch();
        gzwrite($gz, "\nDROP TABLE IF EXISTS `{$table}`;\n" . $create['Create Table'] . ";\n");
        $stmt = $pdo->query('SELECT * FROM `' . $table . '`');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols = array_map(fn($c) => '`' . $c . '`', array_keys($row));
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
            gzwrite($gz, "INSERT INTO `{$table}` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n");
        }
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
}

// --- Uploaded files --------------------------------------------------------
$uploadsPath = realpath(__DIR__ . '/../uploads');
$filesArchive = BACKUP_DIR . "/uploads_{$stamp}.tar.gz";
if ($uploadsPath && is_dir($uploadsPath)) {
    $tar = trim((string)shell_exec('command -v tar 2>/dev/null'));
    if ($tar !== '') {
        exec(sprintf('tar -czf %s -C %s .', escapeshellarg($filesArchive), escapeshellarg($uploadsPath)), $o, $c);
        if ($c !== 0) { error_log('[backup] tar of uploads/ failed'); $exitCode = 1; }
    } else {
        error_log('[backup] tar not available; skipped uploads/ archive — rely on host-level file backups');
    }
}

// --- Retention / pruning ----------------------------------------------------
$cutoff = time() - BACKUP_RETENTION_DAYS * 86400;
foreach (glob(BACKUP_DIR . '/*.{sql.gz,tar.gz}', GLOB_BRACE) ?: [] as $file) {
    if (filemtime($file) < $cutoff) @unlink($file);
}

if (is_file($sqlPath)) {
    echo "[backup] OK: {$sqlPath} (" . round(filesize($sqlPath) / 1024, 1) . " KB)\n";
} else {
    echo "[backup] FAILED — check error log\n";
    $exitCode = 1;
}
exit($exitCode);
