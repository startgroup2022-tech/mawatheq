<?php
/**
 * SADAD gateway configuration (configuration-driven).
 *
 * IMPORTANT: the official SADAD merchant API is issued per-merchant and is not present in this
 * repository. Nothing about the transport is guessed here: every endpoint, credential name and
 * response field mapping is a setting the operator enters from the admin console. Until those
 * values are supplied the gateway reports "configuration required" and performs no network call.
 *
 * Placeholders documented for operators (see docs/payment-gateways/sadad.md):
 *   SADAD_API_BASE_URL, SADAD_PAYMENT_ENDPOINT, SADAD_VERIFY_ENDPOINT, SADAD_WEBHOOK_ENDPOINT
 *
 * Storage: per-tenant rows in `tenant_gateway_settings` (tenant_type/tenant_id/gateway) so one
 * firm's credentials can never be used to launch another firm's payment. Secrets are encrypted
 * at rest with the same AES-256-GCM helper used by the AI/N-Genius keys.
 */
require_once __DIR__ . '/../../secret_crypto.php';

/** Keys that must never be echoed back to the browser or written to logs. */
function sadad_secret_keys(): array {
    return ['sadad_api_key', 'sadad_api_secret', 'sadad_password', 'sadad_webhook_secret'];
}

/** Endpoint/credential fields that must be present before a live call is allowed. */
function sadad_required_keys(): array {
    return ['sadad_base_url', 'sadad_create_endpoint'];
}

/**
 * The full default configuration shape. Endpoints/credentials are intentionally blank: the
 * operator fills them from Admin → Settings → Payments → SADAD. Amounts are sent in major units
 * by default; set sadad_amount_unit=minor if the merchant contract expects the smallest unit.
 */
function sadad_default_template(): array {
    return [
        'sadad_enabled' => '0',
        'sadad_environment' => 'sandbox',            // sandbox | live
        'sadad_currency' => 'SAR',
        'sadad_base_url' => '',                      // e.g. https://<sandbox-host>  (SADAD_API_BASE_URL)
        'sadad_create_endpoint' => '',               // SADAD_PAYMENT_ENDPOINT
        'sadad_verify_endpoint' => '',               // SADAD_VERIFY_ENDPOINT
        'sadad_refund_endpoint' => '',               // only if the merchant contract supports refunds
        'sadad_auth_mode' => 'none',                 // none | basic | bearer | api_key | oauth2_client_credentials
        'sadad_oauth_token_endpoint' => '',          // required only for oauth2_client_credentials
        'sadad_merchant_id' => '',
        'sadad_terminal_id' => '',
        'sadad_username' => '',
        'sadad_webhook_signature_header' => '',      // header name carrying the signature, if any
        'sadad_webhook_signature_algo' => 'hmac_sha256', // hmac_sha256 | hmac_sha512 | none
        'sadad_timeout' => '20',
        'sadad_amount_unit' => 'major',              // major | minor
        'sadad_map_reference' => 'reference',
        'sadad_map_state' => 'status',
        'sadad_map_amount' => 'amount',
        'sadad_map_currency' => 'currency',
        'sadad_map_redirect_url' => 'payment_url',
        'sadad_map_transaction_id' => 'transaction_id',
        'sadad_map_event_id' => 'event_id',
        'sadad_map_event_type' => 'event_type',
        // non-secret runtime state (written by the admin test endpoints)
        'sadad_last_connection_ok' => '',
        'sadad_last_connection_at' => '',
        'sadad_last_success_at' => '',
        // environment the stored secrets belong to; guards Sandbox/Live credential mixing
        'sadad_credentials_env' => '',
        // secrets (encrypted at rest)
        'sadad_api_key' => '',
        'sadad_api_secret' => '',
        'sadad_password' => '',
        'sadad_webhook_secret' => '',
    ];
}

/** Mask a secret for display: never reveal more than a short tail. */
function sadad_mask(?string $secret): string {
    $s = (string)$secret;
    if ($s === '') return '';
    $tail = mb_substr($s, -4, null, 'UTF-8');
    return '••••••••' . $tail;
}

/**
 * Load the merged SADAD config for a tenant. Tenant precedence: exact tenant row, then the
 * platform row, then built-in defaults. Secrets are decrypted for use (never for display).
 */
function sadad_settings(PDO $pdo, string $tenantType = 'platform', int $tenantId = 0): array {
    $config = sadad_default_template();
    $row = sadad_load_row($pdo, $tenantType, $tenantId);
    if ($row === null && !($tenantType === 'platform' && $tenantId === 0)) {
        $row = sadad_load_row($pdo, 'platform', 0);
    }
    if (is_array($row)) {
        foreach ($row as $k => $v) {
            if (array_key_exists($k, $config)) $config[$k] = (string)$v;
        }
    }
    foreach (sadad_secret_keys() as $sk) {
        $config[$sk] = secret_decrypt_value($config[$sk]);
    }
    return $config;
}

/** Raw (still-encrypted) stored row as a flat key/value array, or null when absent. */
function sadad_load_row(PDO $pdo, string $tenantType, int $tenantId): ?array {
    try {
        $stmt = $pdo->prepare("SELECT settings_json FROM tenant_gateway_settings WHERE tenant_type=? AND tenant_id=? AND gateway='sadad' LIMIT 1");
        $stmt->execute([$tenantType, $tenantId]);
        $json = $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('SADAD settings load failed: ' . $e->getMessage());
        return null;
    }
    if ($json === false || $json === null || $json === '') return null;
    $decoded = json_decode((string)$json, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Persist SADAD config for a tenant. Secret fields already encrypted are stored as-is; a blank
 * secret field keeps the previously stored value (so "leave blank to keep" works in the UI).
 *
 * Environment safety: stored secrets are tagged with the environment they were entered for. If the
 * operator switches Sandbox ↔ Live WITHOUT supplying new secrets, the stale secrets are cleared —
 * Sandbox keys can never be silently used against Live (or vice versa). Supplying a new secret
 * alongside the switch tags it for the new environment.
 *
 * Returns an error-code string on failure, or true on success.
 */
function sadad_save_settings(PDO $pdo, array $input, string $tenantType = 'platform', int $tenantId = 0) {
    $existing = sadad_load_row($pdo, $tenantType, $tenantId) ?? [];
    $newEnv = in_array($input['sadad_environment'] ?? '', ['sandbox', 'live'], true) ? $input['sadad_environment'] : 'sandbox';
    $storedEnv = (string)($existing['sadad_credentials_env'] ?? '');
    $envChanged = $storedEnv !== '' && $storedEnv !== $newEnv;

    $stored = [];
    foreach (sadad_default_template() as $key => $default) {
        if (in_array($key, sadad_secret_keys(), true)) continue;
        if (array_key_exists($key, $input)) $stored[$key] = (string)$input[$key];
    }

    $providedAnySecret = false;
    foreach (sadad_secret_keys() as $sk) {
        if (trim((string)($input[$sk] ?? '')) !== '') { $providedAnySecret = true; break; }
    }

    foreach (sadad_secret_keys() as $sk) {
        $incoming = isset($input[$sk]) ? (string)$input[$sk] : '';
        if ($incoming !== '') {
            $enc = secret_encrypt_value($incoming);
            if ($enc === null) return 'encryption_unavailable';
            $stored[$sk] = $enc;
        } elseif (isset($existing[$sk]) && !$envChanged) {
            $stored[$sk] = (string)$existing[$sk]; // keep the previously stored secret for the same env
        } elseif ($envChanged && !$providedAnySecret) {
            // Environment switched with no new secrets: drop stale credentials rather than risk
            // sending Sandbox keys to Live. The operator must re-enter the new environment's secrets.
            $stored[$sk] = '';
        } elseif (isset($existing[$sk])) {
            $stored[$sk] = (string)$existing[$sk];
        }
    }

    // Record which environment the currently-stored secrets belong to.
    if ($providedAnySecret || !$envChanged) {
        $stored['sadad_credentials_env'] = $newEnv;
    } else {
        $stored['sadad_credentials_env'] = '';
    }

    $json = json_encode($stored, JSON_UNESCAPED_UNICODE);
    try {
        $pdo->prepare("INSERT INTO tenant_gateway_settings (tenant_type, tenant_id, gateway, settings_json) VALUES (?,?, 'sadad', ?)
                       ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json)")
            ->execute([$tenantType, $tenantId, $json]);
    } catch (PDOException $e) {
        error_log('SADAD settings save failed: ' . $e->getMessage());
        return 'save_failed';
    }
    return true;
}

/**
 * Whether the stored credentials were entered for the currently selected environment.
 * False means the operator switched environments and must re-enter that environment's secrets.
 */
function sadad_credentials_match_env(array $config): bool {
    $env = ($config['sadad_environment'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';
    $tagged = (string)($config['sadad_credentials_env'] ?? '');
    return $tagged === $env;
}

/** Config keys still missing before SADAD can be called for this tenant. */
function sadad_missing_keys(array $config): array {
    $missing = [];
    foreach (sadad_required_keys() as $k) {
        if (trim((string)($config[$k] ?? '')) === '') $missing[] = $k;
    }
    if (($config['sadad_auth_mode'] ?? 'none') !== 'none') {
        if (trim((string)($config['sadad_api_key'] ?? '')) === '' && trim((string)($config['sadad_username'] ?? '')) === '') {
            $missing[] = 'sadad_api_key';
        }
    }
    if (($config['sadad_auth_mode'] ?? '') === 'oauth2_client_credentials' && trim((string)($config['sadad_oauth_token_endpoint'] ?? '')) === '') {
        $missing[] = 'sadad_oauth_token_endpoint';
    }
    return $missing;
}

/** Resolve the tenant that owns an invoice row, for gateway config lookup. */
function sadad_tenant_for_invoice(array $invoice): array {
    if (!empty($invoice['firm_id'])) return ['firm', (int)$invoice['firm_id']];
    if (!empty($invoice['lawyer_id'])) return ['lawyer', (int)$invoice['lawyer_id']];
    return ['platform', 0];
}

/** SADAD config for the tenant that owns an invoice. */
function sadad_settings_for_invoice(PDO $pdo, array $invoice): array {
    [$type, $id] = sadad_tenant_for_invoice($invoice);
    return sadad_settings($pdo, $type, $id);
}

/** Whether SADAD is switched on AND fully configured for this tenant in the selected environment. */
function sadad_is_enabled(array $config): bool {
    return ($config['sadad_enabled'] ?? '0') === '1'
        && sadad_missing_keys($config) === []
        && sadad_credentials_match_env($config);
}

/** True when the tenant can charge an invoice in the given currency. */
function sadad_supports_currency(array $config, ?string $currency): bool {
    return strtoupper(trim((string)$currency)) === strtoupper(trim((string)($config['sadad_currency'] ?? 'SAR')));
}

/** Absolute URL used for the SADAD webhook, shown in the admin UI. */
function sadad_webhook_url(): string {
    return rtrim(SITE_URL, '/') . '/sadad-webhook.php';
}

/** Record the timestamp of the last successful SADAD transaction for the tenant. */
function sadad_touch_last_success(PDO $pdo, array $config, string $tenantType, int $tenantId): void {
    $config['sadad_last_success_at'] = date('Y-m-d H:i:s');
    sadad_save_settings($pdo, $config, $tenantType, $tenantId);
}

/** Return/cancel URLs handed to the gateway, shown in the admin UI. */
function sadad_return_url(int $invoiceId = 0): string {
    return rtrim(SITE_URL, '/') . '/sadad-return.php' . ($invoiceId > 0 ? '?invoice_id=' . $invoiceId : '');
}

function sadad_cancel_url(): string {
    return rtrim(SITE_URL, '/') . '/invoices.php';
}
