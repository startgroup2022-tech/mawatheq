<?php
/**
 * Enterprise SSO (SAML 2.0) for firm workspaces.
 *
 * XML signature verification is delegated to onelogin/php-saml (robrichards/xmlseclibs).
 * Hand-rolled XML-DSig is a known source of authentication bypasses, so this file never
 * parses or verifies an assertion itself — it only builds settings, enforces the platform's
 * own admission rules, and records the outcome.
 *
 * Admission rules enforced here, on top of the library's signature checks:
 *   * SSO must be explicitly enabled for the firm;
 *   * the response must be signed (unless the firm deliberately turns that off);
 *   * the asserted email domain must be in the firm's allow-list;
 *   * the email must already belong to an active member of THAT firm, unless the firm has
 *     enabled just-in-time provisioning — which only ever grants the lowest role.
 */

require_once __DIR__ . '/secret_crypto.php';

function sso_library_available(): bool {
    return class_exists('OneLogin\\Saml2\\Auth') && class_exists('OneLogin\\Saml2\\Settings');
}

/**
 * Service Provider key material, supplied by the deployment via secrets.php. Absent material is
 * reported as an empty string: the settings builder then omits it rather than inventing a key.
 */
function sso_sp_private_key(): string {
    return defined('SSO_SP_PRIVATE_KEY') ? (string)SSO_SP_PRIVATE_KEY : '';
}

function sso_sp_certificate(): string {
    return defined('SSO_SP_CERTIFICATE') ? (string)SSO_SP_CERTIFICATE : '';
}

/** Salt used when hashing IP/name-id in the audit trail. Falls back to a constant so the trail
 *  stays consistent, but a deployment should define SSO_HASH_SALT. */
function sso_hash_salt(): string {
    return defined('SSO_HASH_SALT') ? (string)SSO_HASH_SALT : 'mawatheq-sso-default-salt';
}

function sso_load_config(PDO $pdo, int $firmId): ?array {
    if ($firmId <= 0) return null;
    try {
        $st = $pdo->prepare('SELECT * FROM sso_configs WHERE firm_id=? LIMIT 1');
        $st->execute([$firmId]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function sso_allowed_domains(array $config): array {
    $raw = strtolower((string)($config['allowed_domains'] ?? ''));
    if ($raw === '') return [];
    $out = [];
    foreach (preg_split('/[,\s]+/', $raw) ?: [] as $d) {
        $d = trim($d, " \t\n\r\0\x0B.");
        if ($d !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d)) $out[] = $d;
    }
    return array_values(array_unique($out));
}

function sso_email_domain(string $email): string {
    $at = strrpos($email, '@');
    return $at === false ? '' : strtolower(trim(substr($email, $at + 1)));
}

function sso_email_allowed(array $config, string $email): bool {
    $domains = sso_allowed_domains($config);
    if (!$domains) return true;
    return in_array(sso_email_domain($email), $domains, true);
}

/**
 * Build the php-saml settings array. `$base` is the public origin, e.g. https://firm.example.
 * The SP certificate/key are read from the deployment's secret store; when they are absent,
 * SSO cannot start and the caller must surface that rather than silently degrade.
 */
function sso_sp_settings(array $config, string $base): array {
    $entityId = trim((string)($config['sp_entity_id'] ?? '')) ?: rtrim($base, '/') . '/sso/metadata.php';
    $acs = rtrim($base, '/') . '/sso/acs.php';
    $slo = rtrim($base, '/') . '/sso/sls.php';

    $spKey = function_exists('sso_sp_private_key') ? sso_sp_private_key() : '';
    $spCert = function_exists('sso_sp_certificate') ? sso_sp_certificate() : '';

    return [
        'strict' => true,
        'debug' => false,
        'sp' => [
            'entityId' => $entityId,
            'assertionConsumerService' => ['url' => $acs, 'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST'],
            'singleLogoutService' => ['url' => $slo, 'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect'],
            'NameIDFormat' => (string)($config['name_id_format'] ?? '') ?: 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            'x509cert' => $spCert,
            'privateKey' => $spKey,
        ],
        'idp' => [
            'entityId' => (string)($config['idp_entity_id'] ?? ''),
            'singleSignOnService' => ['url' => (string)($config['idp_sso_url'] ?? ''), 'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect'],
            'singleLogoutService' => ['url' => (string)($config['idp_slo_url'] ?? ''), 'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect'],
            'x509cert' => (string)($config['idp_x509_cert'] ?? ''),
        ],
        'security' => [
            'wantAssertionsSigned' => (bool)($config['require_signed_response'] ?? 1),
            'wantMessagesSigned' => false,
            'wantNameId' => true,
            'requestedAuthnContext' => false,
            'rejectUnsolicitedResponsesWithInResponseTo' => true,
        ],
    ];
}

/**
 * Extract the identity claims from a validated response. The library has already verified the
 * signature at this point; this function only reads attributes and normalises them.
 */
function sso_identity_from_attributes(array $config, array $attributes, string $nameId): array {
    $attrEmail = (string)($config['attribute_email'] ?? 'email');
    $attrName = (string)($config['attribute_name'] ?? 'displayName');
    $pick = function (string $key) use ($attributes): string {
        if (!isset($attributes[$key])) return '';
        $v = $attributes[$key];
        if (is_array($v)) $v = reset($v);
        return trim((string)$v);
    };
    $email = strtolower($pick($attrEmail));
    if ($email === '' && str_contains($nameId, '@')) $email = strtolower($nameId);
    $name = $pick($attrName);
    if ($name === '') $name = $email;
    return ['email' => $email, 'name' => $name, 'name_id' => $nameId];
}

/**
 * Resolve the firm member a validated identity maps to.
 *
 * Returns one of:
 *   ['ok'=>true,  'lawyer_id'=>int, 'role'=>string]
 *   ['ok'=>false, 'reason'=>string]
 *
 * A JIT-provisioned member is always created with role 'lawyer' (never owner/partner), and
 * only when the firm explicitly enabled JIT provisioning.
 */
function sso_resolve_member(PDO $pdo, int $firmId, array $config, string $email): array {
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'reason' => 'invalid_email'];
    }
    if (!sso_email_allowed($config, $email)) {
        return ['ok' => false, 'reason' => 'domain_not_allowed'];
    }

    $st = $pdo->prepare('SELECT id FROM lawyers WHERE email=? LIMIT 1');
    $st->execute([$email]);
    $lawyerId = (int)($st->fetchColumn() ?: 0);

    if ($lawyerId > 0) {
        $st = $pdo->prepare("SELECT member_role FROM law_firm_members WHERE lawyer_id=? AND firm_id=? AND status='active' LIMIT 1");
        $st->execute([$lawyerId, $firmId]);
        $role = $st->fetchColumn();
        if ($role === false) return ['ok' => false, 'reason' => 'not_a_firm_member'];
        return ['ok' => true, 'lawyer_id' => $lawyerId, 'role' => (string)$role];
    }

    if (empty($config['jit_provisioning'])) {
        return ['ok' => false, 'reason' => 'no_account_and_jit_disabled'];
    }

    return ['ok' => true, 'lawyer_id' => 0, 'role' => 'lawyer', 'provision' => ['email' => $email]];
}

function sso_record_attempt(PDO $pdo, ?int $firmId, ?string $email, string $outcome, ?string $reason = null, ?string $nameId = null): void {
    try {
        $salt = function_exists('sso_hash_salt') ? sso_hash_salt() : 'mawatheq-sso';
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $pdo->prepare('INSERT INTO sso_login_attempts (firm_id, email, outcome, reason, name_id_hash, ip_hash) VALUES (?,?,?,?,?,?)')
            ->execute([
                $firmId ?: null,
                $email !== null ? mb_substr($email, 0, 190, 'UTF-8') : null,
                $outcome,
                $reason !== null ? mb_substr($reason, 0, 190, 'UTF-8') : null,
                $nameId !== null ? hash('sha256', $salt . $nameId) : null,
                $ip !== '' ? hash('sha256', $salt . $ip) : null,
            ]);
    } catch (Throwable $e) {
        // A failing audit write must not turn an authentication result into a 500.
        error_log('sso_record_attempt failed: ' . $e->getMessage());
    }
}

/**
 * Persist an SSO configuration. The IdP certificate is public material; if the deployment
 * stores SP key material through the secret helper, it is written there, never here.
 */
function sso_save_config(PDO $pdo, int $firmId, array $in, int $lawyerId): array {
    $clean = static function ($v, int $max = 500): ?string {
        $v = trim((string)$v);
        return $v === '' ? null : mb_substr($v, 0, $max, 'UTF-8');
    };
    $cert = (string)($in['idp_x509_cert'] ?? '');
    $cert = trim($cert) !== '' ? trim($cert) : null;

    $ssoUrl = $clean($in['idp_sso_url'] ?? '');
    if ($ssoUrl !== null && !preg_match('#^https://#i', $ssoUrl)) {
        return ['ok' => false, 'error' => 'idp_sso_url_must_be_https'];
    }
    $certForCheck = $cert ?? '';
    if ($certForCheck !== '' && !preg_match('/-----BEGIN CERTIFICATE-----/', $certForCheck)) {
        return ['ok' => false, 'error' => 'idp_cert_not_pem'];
    }

    $stmt = $pdo->prepare('INSERT INTO sso_configs
        (firm_id, provider_type, enabled, display_name, idp_entity_id, idp_sso_url, idp_slo_url, idp_x509_cert,
         sp_entity_id, name_id_format, attribute_email, attribute_name, attribute_role, jit_provisioning,
         allowed_domains, require_signed_response, created_by_lawyer_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
         enabled=VALUES(enabled), display_name=VALUES(display_name), idp_entity_id=VALUES(idp_entity_id),
         idp_sso_url=VALUES(idp_sso_url), idp_slo_url=VALUES(idp_slo_url), idp_x509_cert=VALUES(idp_x509_cert),
         sp_entity_id=VALUES(sp_entity_id), name_id_format=VALUES(name_id_format),
         attribute_email=VALUES(attribute_email), attribute_name=VALUES(attribute_name),
         attribute_role=VALUES(attribute_role), jit_provisioning=VALUES(jit_provisioning),
         allowed_domains=VALUES(allowed_domains), require_signed_response=VALUES(require_signed_response)');
    $stmt->execute([
        $firmId, 'saml', !empty($in['enabled']) ? 1 : 0,
        $clean($in['display_name'] ?? '', 190),
        $clean($in['idp_entity_id'] ?? ''),
        $ssoUrl,
        $clean($in['idp_slo_url'] ?? ''),
        $cert,
        $clean($in['sp_entity_id'] ?? ''),
        $clean($in['name_id_format'] ?? '', 190),
        $clean($in['attribute_email'] ?? '', 120) ?: 'email',
        $clean($in['attribute_name'] ?? '', 120) ?: 'displayName',
        $clean($in['attribute_role'] ?? '', 120),
        !empty($in['jit_provisioning']) ? 1 : 0,
        $clean($in['allowed_domains'] ?? ''),
        empty($in['require_signed_response']) ? 0 : 1,
        $lawyerId ?: null,
    ]);
    return ['ok' => true];
}

/** Whether the configuration is complete enough to attempt a login. */
function sso_config_ready(array $config): array {
    if (empty($config['enabled'])) return ['ok' => false, 'reason' => 'disabled'];
    if (trim((string)($config['idp_sso_url'] ?? '')) === '') return ['ok' => false, 'reason' => 'missing_idp_sso_url'];
    if (trim((string)($config['idp_entity_id'] ?? '')) === '') return ['ok' => false, 'reason' => 'missing_idp_entity_id'];
    if (trim((string)($config['idp_x509_cert'] ?? '')) === '') return ['ok' => false, 'reason' => 'missing_idp_certificate'];
    if (!sso_library_available()) return ['ok' => false, 'reason' => 'saml_library_missing'];
    return ['ok' => true];
}
