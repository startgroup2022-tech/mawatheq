<?php
/**
 * Custom domains for firm websites (Executive 08).
 *
 * A firm can point its own hostname at its Mawatheq website. The security model is
 * deliberately conservative:
 *
 *   * A `Host` header is never trusted on its own. `firm_domain_resolve()` returns a firm
 *     only when the hostname has a row whose status is `verified` or `active`. An
 *     unverified or unknown host resolves to nothing.
 *   * Ownership is proven before activation: the firm publishes a TXT record containing a
 *     random token that only that firm's session can read, and the platform checks it.
 *   * Activation is a separate, explicit step from verification.
 *   * HTTPS is *not* provisioned automatically. There is no ACME client wired in here, so
 *     `ssl_status` is `manual_required` and the deployment guide documents the manual
 *     certificate step. Claiming automatic SSL without infrastructure to back it would be
 *     a lie, and a false sense of security.
 *
 * Every mutation is firm-scoped and audit-logged.
 */

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/audit_log.php';

if (!function_exists('firm_domain_status_label')) {
    function firm_domain_status_label(string $s, bool $rtl = true): string {
        $ar = ['pending' => 'قيد الإعداد', 'verification_pending' => 'بانتظار التحقق', 'verified' => 'تم التحقق',
               'active' => 'مُفعّل', 'failed' => 'فشل التحقق', 'disabled' => 'معطّل'];
        $en = ['pending' => 'Pending', 'verification_pending' => 'Verification pending', 'verified' => 'Verified',
               'active' => 'Active', 'failed' => 'Verification failed', 'disabled' => 'Disabled'];
        return ($rtl ? $ar : $en)[$s] ?? $s;
    }
}

if (!function_exists('firm_domain_normalize')) {
    /**
     * Normalise a submitted hostname. Lowercases, strips an optional scheme/path/port,
     * a leading www., and any trailing dot. Rejects anything that is not a bare public
     * hostname — this value is used in a UNIQUE key and in Host matching, so it must be
     * canonical.
     */
    function firm_domain_normalize(string $raw): ?string {
        $d = strtolower(trim($raw));
        if ($d === '') return null;
        $d = preg_replace('~^[a-z]+://~', '', $d) ?? $d;   // scheme
        $d = preg_replace('~[/?\#].*$~', '', $d) ?? $d;      // path/query/fragment
        $d = preg_replace('~:\d+$~', '', $d) ?? $d;          // port
        $d = rtrim($d, '.');
        if (str_starts_with($d, 'www.')) $d = substr($d, 4);
        // Hostname grammar: labels of [a-z0-9-], dot separated, at least one dot, valid TLD.
        if (!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $d)) return null;
        if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $d)) return null; // IP literal, not a domain
        return $d;
    }
}

if (!function_exists('firm_domain_add')) {
    /**
     * Register a domain for the current firm and issue a verification token.
     * @return array{ok:bool,error?:string,id?:int,token?:string,domain?:string}
     */
    function firm_domain_add(PDO $pdo, string $rawDomain): array {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return ['ok' => false, 'error' => 'no_tenant'];
        $domain = firm_domain_normalize($rawDomain);
        if ($domain === null) return ['ok' => false, 'error' => 'invalid_domain'];

        // A hostname maps to exactly one firm and cannot be claimed twice.
        $chk = $pdo->prepare('SELECT firm_id FROM firm_domains WHERE domain=? LIMIT 1');
        $chk->execute([$domain]);
        $owner = $chk->fetchColumn();
        if ($owner !== false) {
            return ['ok' => false, 'error' => (int)$owner === $firmId ? 'already_yours' : 'domain_taken'];
        }

        $token = 'mawatheq-verify-' . bin2hex(random_bytes(16));
        $stmt = $pdo->prepare('INSERT INTO firm_domains (firm_id, domain, status, verification_token, verification_method, created_by_lawyer_id)
                               VALUES (?,?,?,?,\'dns_txt\',?)');
        $stmt->execute([$firmId, $domain, 'verification_pending', $token,
            (int)(tenant_current($pdo)['lawyer_id'] ?? 0) ?: null]);
        $id = (int)$pdo->lastInsertId();
        log_action($pdo, 'إضافة نطاق مخصص: ' . $domain, 'firm_domain', $id);
        return ['ok' => true, 'id' => $id, 'token' => $token, 'domain' => $domain];
    }
}

if (!function_exists('firm_domain_list')) {
    function firm_domain_list(PDO $pdo): array {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return [];
        $stmt = $pdo->prepare('SELECT * FROM firm_domains WHERE firm_id=? ORDER BY is_primary DESC, created_at DESC');
        $stmt->execute([$firmId]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('firm_domain_get')) {
    function firm_domain_get(PDO $pdo, int $id): ?array {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return null;
        $stmt = $pdo->prepare('SELECT * FROM firm_domains WHERE id=? AND firm_id=? LIMIT 1');
        $stmt->execute([$id, $firmId]);
        return $stmt->fetch() ?: null;
    }
}

if (!function_exists('firm_domain_expected_record')) {
    /** The DNS record the firm must create. */
    function firm_domain_expected_record(array $domain): array {
        return [
            'type' => 'TXT',
            'host' => '_mawatheq.' . $domain['domain'],
            'value' => $domain['verification_token'],
            'ttl' => 3600,
        ];
    }
}

if (!function_exists('firm_domain_dns_txt_values')) {
    /**
     * Fetch TXT records for a hostname. Uses dns_get_record when the extension is present,
     * else a `host -t txt` shell fallback. Returns [] on any failure — a lookup failure
     * must never read as "verified".
     */
    function firm_domain_dns_txt_values(string $host): array {
        $values = [];
        if (function_exists('dns_get_record')) {
            $recs = @dns_get_record($host, DNS_TXT);
            if (is_array($recs)) {
                foreach ($recs as $r) {
                    if (!empty($r['txt'])) $values[] = (string)$r['txt'];
                    if (!empty($r['entries']) && is_array($r['entries'])) {
                        $values[] = implode('', array_map('strval', $r['entries']));
                    }
                }
            }
        }
        if (!$values && function_exists('shell_exec')) {
            $out = @shell_exec('host -t TXT ' . escapeshellarg($host) . ' 2>/dev/null');
            if (is_string($out) && $out !== '') {
                if (preg_match_all('/"([^"]*)"/', $out, $m)) {
                    foreach ($m[1] as $t) $values[] = $t;
                }
            }
        }
        return array_values(array_unique(array_filter($values, fn($v) => trim((string)$v) !== '')));
    }
}

if (!function_exists('firm_domain_verify')) {
    /**
     * Check the DNS TXT token. On success the domain becomes `verified` (owned) but not
     * yet `active` — activation is the separate consent-to-serve step.
     * @return array{ok:bool,verified:bool,error?:string,found?:array}
     */
    function firm_domain_verify(PDO $pdo, int $id): array {
        $domain = firm_domain_get($pdo, $id);
        if (!$domain) return ['ok' => false, 'verified' => false, 'error' => 'not_found'];
        if (in_array($domain['status'], ['active'], true)) {
            return ['ok' => true, 'verified' => true, 'error' => 'already_active'];
        }
        $expected = firm_domain_expected_record($domain);
        $values = firm_domain_dns_txt_values($expected['host']);
        $matched = false;
        foreach ($values as $v) {
            if (hash_equals($domain['verification_token'], trim((string)$v))) { $matched = true; break; }
        }
        $status = $matched ? 'verified' : (count($values) > 0 ? 'failed' : 'verification_pending');
        $resultText = $matched
            ? 'TXT record matched'
            : (count($values) > 0 ? 'TXT record present but token did not match' : 'No TXT record found');
        $stmt = $pdo->prepare('UPDATE firm_domains SET status=?, verified_at=IF(?=1,NOW(),verified_at),
                               last_checked_at=NOW(), last_check_result=? WHERE id=? AND firm_id=?');
        $stmt->execute([$status, $matched ? 1 : 0, $resultText, $id, (int)$domain['firm_id']]);
        log_action($pdo, 'فحص نطاق مخصص: ' . $domain['domain'] . ' → ' . $status, 'firm_domain', $id);
        return ['ok' => true, 'verified' => $matched, 'status' => $status, 'found' => $values];
    }
}

if (!function_exists('firm_domain_activate')) {
    /**
     * Turn on serving for a verified domain. Refuses unless verified. Records that TLS is
     * still a manual step (no ACME automation is wired in).
     */
    function firm_domain_activate(PDO $pdo, int $id): array {
        $domain = firm_domain_get($pdo, $id);
        if (!$domain) return ['ok' => false, 'error' => 'not_found'];
        if (!in_array($domain['status'], ['verified', 'active'], true)) {
            return ['ok' => false, 'error' => 'not_verified'];
        }
        $stmt = $pdo->prepare("UPDATE firm_domains SET status='active', ssl_status='manual_required' WHERE id=? AND firm_id=?");
        $stmt->execute([$id, (int)$domain['firm_id']]);
        log_action($pdo, 'تفعيل نطاق مخصص: ' . $domain['domain'], 'firm_domain', $id);
        return ['ok' => true, 'ssl_status' => 'manual_required', 'note' => 'ssl_manual'];
    }
}

if (!function_exists('firm_domain_disable')) {
    function firm_domain_disable(PDO $pdo, int $id): bool {
        $domain = firm_domain_get($pdo, $id);
        if (!$domain) return false;
        $stmt = $pdo->prepare("UPDATE firm_domains SET status='disabled', is_primary=0 WHERE id=? AND firm_id=?");
        $stmt->execute([$id, (int)$domain['firm_id']]);
        log_action($pdo, 'تعطيل نطاق مخصص: ' . $domain['domain'], 'firm_domain', $id);
        return true;
    }
}

if (!function_exists('firm_domain_delete')) {
    function firm_domain_delete(PDO $pdo, int $id): bool {
        $domain = firm_domain_get($pdo, $id);
        if (!$domain) return false;
        $pdo->prepare('DELETE FROM firm_domains WHERE id=? AND firm_id=?')->execute([$id, (int)$domain['firm_id']]);
        log_action($pdo, 'حذف نطاق مخصص: ' . $domain['domain'], 'firm_domain', $id);
        return true;
    }
}

if (!function_exists('firm_domain_set_primary')) {
    function firm_domain_set_primary(PDO $pdo, int $id): bool {
        $domain = firm_domain_get($pdo, $id);
        if (!$domain || $domain['status'] !== 'active') return false;
        $firmId = (int)$domain['firm_id'];
        $pdo->prepare('UPDATE firm_domains SET is_primary=0 WHERE firm_id=?')->execute([$firmId]);
        $pdo->prepare('UPDATE firm_domains SET is_primary=1 WHERE id=? AND firm_id=?')->execute([$id, $firmId]);
        return true;
    }
}

if (!function_exists('firm_domain_resolve')) {
    /**
     * Map an incoming Host header to a firm id, or null.
     *
     * SECURITY: this is the only sanctioned way to turn a Host header into a tenant. It
     * requires an `active` row, so an unverified or merely-verified domain never serves
     * content. Callers must fall back to the platform's own routing when this returns null
     * — never to the raw header.
     */
    function firm_domain_resolve(PDO $pdo, ?string $host): ?int {
        if ($host === null || $host === '') return null;
        $domain = firm_domain_normalize($host);
        if ($domain === null) return null;
        try {
            $stmt = $pdo->prepare("SELECT firm_id FROM firm_domains WHERE domain=? AND status='active' LIMIT 1");
            $stmt->execute([$domain]);
            $firmId = $stmt->fetchColumn();
            return $firmId !== false ? (int)$firmId : null;
        } catch (PDOException $e) {
            error_log('Custom domain resolution failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('firm_domain_firm_public_slug')) {
    /** Slug of a firm resolved by custom domain — feeds the existing firm-site renderer. */
    function firm_domain_firm_public_slug(PDO $pdo, int $firmId): ?string {
        $stmt = $pdo->prepare("SELECT public_slug FROM law_firms WHERE id=? AND is_public=1 LIMIT 1");
        $stmt->execute([$firmId]);
        $slug = $stmt->fetchColumn();
        return $slug !== false && $slug !== '' && $slug !== null ? (string)$slug : null;
    }
}
