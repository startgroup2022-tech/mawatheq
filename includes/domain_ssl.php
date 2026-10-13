<?php
/**
 * TLS provisioning jobs for a firm's custom domains.
 *
 * The platform does not pretend to be a certificate authority. This records the intent to
 * serve HTTPS, probes whether a valid certificate is already being served for the hostname,
 * and otherwise leaves the job as `manual_required` with a plain explanation. Nothing is ever
 * marked `issued` unless a TLS handshake actually presented a certificate valid for that host.
 */

require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/firm_domains.php';

if (!function_exists('domain_ssl_queue')) {
    /**
     * Queue (or re-queue) a provisioning job for a domain. Only a domain that has passed
     * ownership verification is eligible; anything else is refused rather than queued, so a job
     * in the table always corresponds to a hostname the firm has proven it controls.
     */
    function domain_ssl_queue(PDO $pdo, int $domainId): array {
        $domain = firm_domain_get($pdo, $domainId);
        if (!$domain) return ['ok' => false, 'error' => 'not_found'];
        if (!in_array($domain['status'], ['verified', 'active'], true)) {
            return ['ok' => false, 'error' => 'not_verified'];
        }

        $existing = domain_ssl_latest($pdo, $domainId);
        if ($existing && in_array($existing['status'], ['queued', 'pending_dns'], true)) {
            return ['ok' => true, 'job_id' => (int)$existing['id'], 'status' => $existing['status'], 'reused' => true];
        }

        $provider = defined('ACME_ENABLED') && ACME_ENABLED ? 'acme' : 'manual';
        $pdo->prepare('INSERT INTO domain_ssl_jobs (firm_id, domain_id, provider, status, detail) VALUES (?,?,?,?,?)')
            ->execute([
                (int)$domain['firm_id'],
                $domainId,
                $provider,
                'queued',
                $provider === 'acme' ? 'queued for automatic issuance' : 'no ACME account configured; operator step',
            ]);
        $jobId = (int)$pdo->lastInsertId();
        log_action($pdo, 'طلب شهادة SSL للنطاق: ' . $domain['domain'], 'firm_domain', $domainId);
        return ['ok' => true, 'job_id' => $jobId, 'status' => 'queued'];
    }
}

if (!function_exists('domain_ssl_latest')) {
    function domain_ssl_latest(PDO $pdo, int $domainId): ?array {
        $st = $pdo->prepare('SELECT * FROM domain_ssl_jobs WHERE domain_id=? ORDER BY id DESC LIMIT 1');
        $st->execute([$domainId]);
        return $st->fetch() ?: null;
    }
}

if (!function_exists('domain_ssl_probe')) {
    /**
     * Open a TLS connection to the hostname and report whether the certificate presented is
     * valid for it. Returns the peer certificate subject/SAN summary on success. A connection
     * failure is reported as such, never as success.
     */
    function domain_ssl_probe(string $host, int $timeout = 6): array {
        $host = strtolower(trim($host));
        if ($host === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) {
            return ['ok' => false, 'reason' => 'invalid_host'];
        }
        $ctx = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $errno = 0; $errstr = '';
        $sock = @stream_socket_client('ssl://' . $host . ':443', $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            return ['ok' => false, 'reason' => 'connect_failed', 'detail' => $errstr ?: ('errno ' . $errno)];
        }
        $params = stream_context_get_params($sock);
        fclose($sock);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (!$cert) {
            return ['ok' => false, 'reason' => 'no_certificate'];
        }
        $parsed = openssl_x509_parse($cert);
        $validTo = isset($parsed['validTo_time_t']) ? (int)$parsed['validTo_time_t'] : null;
        return [
            'ok' => true,
            'subject' => (string)($parsed['subject']['CN'] ?? ''),
            'expires_at' => $validTo ? date('Y-m-d H:i:s', $validTo) : null,
        ];
    }
}

if (!function_exists('domain_ssl_run')) {
    /**
     * Process queued SSL jobs. For each job it probes the live hostname:
     *   - a valid certificate is already served  -> issued
     *   - the hostname resolves/serves TLS but is not yet ready -> pending_dns (retryable)
     *   - the deployment has no ACME account     -> manual_required (an operator must act)
     * A job is never silently dropped or marked issued without evidence.
     */
    function domain_ssl_run(PDO $pdo, int $limit = 50): array {
        $jobs = $pdo->query("SELECT j.*, d.domain FROM domain_ssl_jobs j
            JOIN firm_domains d ON d.id=j.domain_id
            WHERE j.status IN ('queued','pending_dns')
            ORDER BY j.id ASC LIMIT " . max(1, $limit))->fetchAll();

        $acme = defined('ACME_ENABLED') && ACME_ENABLED;
        $issued = 0; $pending = 0; $manual = 0; $failed = 0;

        foreach ($jobs as $job) {
            $probe = domain_ssl_probe((string)$job['domain']);
            try {
                if (!empty($probe['ok'])) {
                    $pdo->prepare("UPDATE domain_ssl_jobs SET status='issued', attempts=attempts+1, last_attempt_at=NOW(), issued_at=NOW(), expires_at=?, detail=? WHERE id=?")
                        ->execute([$probe['expires_at'], 'certificate valid, expires ' . (string)$probe['expires_at'], (int)$job['id']]);
                    $pdo->prepare("UPDATE firm_domains SET ssl_status='ready' WHERE id=?")->execute([(int)$job['domain_id']]);
                    $issued++;
                } elseif (!$acme) {
                    $pdo->prepare("UPDATE domain_ssl_jobs SET status='manual_required', attempts=attempts+1, last_attempt_at=NOW(), detail=? WHERE id=?")
                        ->execute(['no ACME account configured; install the certificate manually', (int)$job['id']]);
                    $pdo->prepare("UPDATE firm_domains SET ssl_status='manual_required' WHERE id=?")->execute([(int)$job['domain_id']]);
                    $manual++;
                } elseif ((int)$job['attempts'] >= 8) {
                    $pdo->prepare("UPDATE domain_ssl_jobs SET status='failed', attempts=attempts+1, last_attempt_at=NOW(), detail=? WHERE id=?")
                        ->execute(['gave up after 8 attempts: ' . (string)($probe['reason'] ?? 'unknown'), (int)$job['id']]);
                    $failed++;
                } else {
                    $pdo->prepare("UPDATE domain_ssl_jobs SET status='pending_dns', attempts=attempts+1, last_attempt_at=NOW(), detail=? WHERE id=?")
                        ->execute(['waiting for DNS/TLS: ' . (string)($probe['reason'] ?? 'unknown'), (int)$job['id']]);
                    $pending++;
                }
            } catch (Throwable $e) {
                error_log('SSL job ' . (int)$job['id'] . ' failed: ' . $e->getMessage());
                $failed++;
            }
        }
        return ['seen' => count($jobs), 'issued' => $issued, 'pending' => $pending, 'manual' => $manual, 'failed' => $failed];
    }
}
