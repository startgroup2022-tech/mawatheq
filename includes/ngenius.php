<?php
require_once __DIR__ . '/secret_crypto.php';
require_once __DIR__ . '/currency.php';
/**
 * ============================================================
 * تكامل حقيقي مع بوابة الدفع السعودية N-Genius (Network International)
 * مبني حسب التوثيق الرسمي: https://docs.ksa.ngenius-payments.com
 * يدعم: مدى، فيزا، ماستركارد عبر صفحة الدفع المستضافة (Hosted Payment Page)
 * ============================================================
 */

function ngenius_base_url(string $environment): string {
    // Test seam: the automated suite points the gateway at a local fixture so the REAL curl
    // path is exercised without charging a card. It is opt-in via the environment and never
    // set on a normal deployment, so production still resolves the documented hostnames.
    $override = getenv('MAWATHEQ_NGENIUS_BASE_URL');
    if ($override !== false && $override !== '') return rtrim($override, '/');
    return $environment === 'production'
        ? 'https://api-gateway.ksa.ngenius-payments.com'
        : 'https://api-gateway.sandbox.ksa.ngenius-payments.com';
}

/**
 * الخطوة 1: طلب رمز دخول (صالح 5 دقائق فقط) باستخدام مفتاح حساب الخدمة.
 *
 * IMPORTANT — the realmName trap (verified against the live KSA gateway):
 *   The Service Account API Key is itself Base64("<realm-uuid>:<other-uuid>"), so the
 *   gateway derives the realm from the key. Sending a realmName body — including the
 *   historical default 'ni' — is rejected with HTTP 404 `realmNameNotAvailable` and no
 *   token is issued, which silently disables every payment path. The working call posts
 *   an EMPTY body. We therefore send no realmName unless a non-legacy value is set.
 *
 * Tokens live 300 seconds, so one is cached per (environment, key) and reused until it is
 * within 30s of expiry; a single booking request otherwise spends two round trips on auth.
 *
 * @return array{ok:bool, token:?string, error:?string}
 */
function ngenius_token_cache_file(string $environment, string $apiKey): string {
    $dir = dirname(__DIR__) . '/storage/cache';
    return $dir . '/ngenius-token-' . substr(hash('sha256', $environment . '|' . $apiKey), 0, 32) . '.json';
}

function ngenius_get_access_token(string $environment, string $apiKey, string $realmName = ''): array {
    // Serve a still-valid cached token without another network round trip.
    $cacheFile = ngenius_token_cache_file($environment, $apiKey);
    $cached = @file_get_contents($cacheFile);
    if ($cached !== false) {
        $data = json_decode($cached, true);
        if (is_array($data) && !empty($data['token']) && ($data['expires_at'] ?? 0) > time() + 30) {
            return ['ok' => true, 'token' => (string)$data['token'], 'error' => null];
        }
    }

    // A realm is only sent when it is an explicit, non-legacy value. 'ni' is the old
    // hard-coded default that the live gateway rejects, so it means "not configured".
    $realm = trim($realmName);
    $body = ($realm === '' || $realm === 'ni') ? [] : ['realmName' => $realm];
    // json_encode([]) emits a JSON *array* ('[]'), which the live KSA gateway rejects with a
    // bare HTTP 400 before it even looks at the API key — so every payment path fails with no
    // token and no explanation. A JSON *object* ('{}') is required. (object) cast keeps the
    // realmName variant identical. This is invisible to a fixture that does not validate shape.
    $payload = json_encode((object)$body, JSON_UNESCAPED_SLASHES);
    if ($payload === false) $payload = '{}';

    $ch = curl_init(ngenius_base_url($environment) . '/identity/auth/access-token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.ni-identity.v1+json',
            'Authorization: Basic ' . $apiKey,
            'Content-Type: application/vnd.ni-identity.v1+json',
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'token' => null, 'error' => 'تعذّر الاتصال ببوابة الدفع: ' . $curlError];
    }
    $data = json_decode($response, true);
    if ($httpCode !== 200 || empty($data['access_token'])) {
        // Surface the gateway's own reason (errorCode) so an operator can tell a rejected key
        // from a rejected body shape without guessing. Never include the key itself.
        $detail = $data['errors'][0]['errorCode'] ?? null;
        $hint = '';
        if ($detail === 'realmNameNotAvailable') {
            $hint = ' لا تُرسل قيمة realmName — البوابة تستخرجها من مفتاح الحساب.';
        } elseif ($detail === 'badTokenRequest') {
            $hint = ' تحقق من صحة Service Account API Key ومن أنه من نفس البيئة (تجريبية/حقيقية).';
        }
        error_log('ngenius token failed: HTTP ' . $httpCode . ' detail=' . (string)$detail
            . ' env=' . $environment . ' body=' . substr((string)$response, 0, 300));
        return ['ok' => false, 'token' => null,
            'error' => 'فشل الحصول على رمز دخول من بوابة الدفع (HTTP ' . $httpCode . ').'
                . ($detail ? ' [' . $detail . ']' : '') . $hint];
    }

    // Cache the token; a failure to write the cache is not fatal (the token is still returned).
    $expiresIn = (int)($data['expires_in'] ?? 300);
    if (!is_dir(dirname($cacheFile))) @mkdir(dirname($cacheFile), 0700, true);
    @file_put_contents($cacheFile, json_encode([
        'token' => $data['access_token'],
        'expires_at' => time() + max(60, $expiresIn),
    ]), LOCK_EX);
    @chmod($cacheFile, 0600);

    return ['ok' => true, 'token' => $data['access_token'], 'error' => null];
}

/** Default settlement currency for the N-Genius outlet (this integration uses the Saudi gateway). */
const NGENIUS_DEFAULT_CURRENCY = 'SAR';

/**
 * Settlement currency configured for the outlet. The merchant account decides which currency
 * it can accept, so this mirrors that choice rather than hard-coding one.
 */
function ngenius_currency(array $settings): string {
    $code = strtoupper(trim((string)($settings['ngenius_currency'] ?? '')));
    return $code !== '' ? $code : NGENIUS_DEFAULT_CURRENCY;
}

/** Minor units per major unit for the gateway's integer amount (100 for SAR, 1000 for 3-decimal). */
function ngenius_minor_units(string $currency): int {
    return currency_decimals($currency) === 3 ? 1000 : 100;
}

/** Whether an invoice's currency matches the outlet's configured settlement currency. */
function ngenius_supports_currency(array $settings, ?string $currency): bool {
    return strtoupper(trim((string)$currency)) === ngenius_currency($settings);
}

/**
 * الخطوة 2: إنشاء طلب دفع (Order) وإرجاع رابط صفحة الدفع المستضافة للعميل.
 * $amount بعملة الـoutlet (افتراضياً الريال السعودي) — يُحوَّل إلى أصغر وحدة نقدية (هللة = 100).
 * @return array{ok:bool, order_reference:?string, payment_url:?string, error:?string}
 */
function ngenius_create_order(
    string $environment, string $outletRef, string $accessToken,
    float $amount, string $customerEmail, string $customerName,
    string $redirectUrl, string $cancelUrl, string $merchantOrderReference, string $language = 'ar',
    string $currency = NGENIUS_DEFAULT_CURRENCY, string $countryCode = 'SA'
): array {
    $nameParts = explode(' ', trim($customerName), 2);
    $firstName = $nameParts[0] ?: 'Customer';
    $lastName = $nameParts[1] ?? $firstName;

    $body = [
        'action' => 'PURCHASE',
        'amount' => ['currencyCode' => $currency, 'value' => (int) round($amount * ngenius_minor_units($currency))],
        'emailAddress' => $customerEmail,
        'language' => $language,
        'merchantOrderReference' => preg_replace('/[^A-Za-z0-9-]/', '-', $merchantOrderReference),
        'merchantAttributes' => [
            'redirectUrl' => $redirectUrl,
            'cancelUrl' => $cancelUrl,
            'skipConfirmationPage' => true,
        ],
        'billingAddress' => [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'countryCode' => $countryCode,
        ],
    ];

    $ch = curl_init(ngenius_base_url($environment) . '/transactions/outlets/' . rawurlencode($outletRef) . '/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/vnd.ni-payment.v2+json',
            'Accept: application/vnd.ni-payment.v2+json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'order_reference' => null, 'payment_url' => null, 'error' => 'تعذّر الاتصال ببوابة الدفع: ' . $curlError];
    }
    $data = json_decode($response, true);
    if ($httpCode >= 300 || empty($data['reference']) || empty($data['_links']['payment']['href'])) {
        // The gateway reports the reason under errors[0].message/errorCode (array); older
        // responses used a single error.message object. Accept both so the real cause is not
        // replaced by a generic message, and log it (never the token).
        $detail = $data['errors'][0]['errorCode'] ?? $data['errors'][0]['message'] ?? null;
        $msg = $detail ?? ($data['error']['message'] ?? null)
            ?? ('فشل إنشاء طلب الدفع (HTTP ' . $httpCode . ').');
        error_log('ngenius create order failed: HTTP ' . $httpCode . ' detail=' . (string)$detail
            . ' env=' . $environment . ' outlet=' . $outletRef . ' body=' . substr((string)$response, 0, 300));
        return ['ok' => false, 'order_reference' => null, 'payment_url' => null, 'error' => $msg];
    }
    return ['ok' => true, 'order_reference' => $data['reference'], 'payment_url' => $data['_links']['payment']['href'], 'error' => null];
}

/**
 * الخطوة 3: الاستعلام عن حالة طلب دفع حقيقياً من البوابة (بدل الثقة بمعاملات الرابط فقط).
 * @return array{ok:bool, state:?string, amount:?float, currency:?string, payment_ref:?string, error:?string}
 */
function ngenius_get_order_status(string $environment, string $outletRef, string $accessToken, string $orderRef): array {
    $ch = curl_init(ngenius_base_url($environment) . '/transactions/outlets/' . rawurlencode($outletRef) . '/orders/' . rawurlencode($orderRef));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Accept: application/vnd.ni-payment.v2+json'],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return ['ok' => false, 'state' => null, 'amount' => null, 'currency' => null, 'payment_ref' => null, 'error' => 'تعذّر الاستعلام عن حالة الطلب (HTTP ' . $httpCode . ').'];
    }
    $data = json_decode($response, true);
    $payment = $data['_embedded']['payment'][0] ?? null;
    $state = $payment['state'] ?? null;

    // The gateway reports the captured amount in minor units; return it in major units so the
    // caller can reconcile against the invoice balance without knowing the currency factor.
    $currency = $payment['amount']['currencyCode'] ?? ($data['amount']['currencyCode'] ?? null);
    $amount = null;
    if (isset($payment['amount']['value'])) {
        $amount = (float)$payment['amount']['value'] / ngenius_minor_units($currency ?: NGENIUS_DEFAULT_CURRENCY);
    }
    return ['ok' => true, 'state' => $state, 'amount' => $amount, 'currency' => $currency, 'payment_ref' => $payment['reference'] ?? null, 'error' => null];
}

/**
 * استرداد مبلغ (كلي أو جزئي) لطلب مدفوع، عبر رابط cnp:refund الذي تُعلنه البوابة.
 * N-Genius requires a currency + integer minor-unit amount; a full refund is the order amount.
 * The refund is only accepted for a settled order, and the gateway rejects a partial amount
 * that exceeds the captured total, so callers must not invent one.
 *
 * @return array{ok:bool, state:string, refund_ref:?string, amount:?float, error:?string}
 */
function ngenius_refund_order(string $environment, string $outletRef, string $accessToken, string $orderRef, float $amount, string $currency = NGENIUS_DEFAULT_CURRENCY): array {
    if ($amount <= 0) {
        return ['ok' => false, 'state' => 'FAILED', 'refund_ref' => null, 'amount' => null, 'error' => 'invalid_refund_amount'];
    }
    $body = ['amount' => ['currencyCode' => strtoupper($currency), 'value' => (int)round($amount * ngenius_minor_units($currency))]];
    $ch = curl_init(ngenius_base_url($environment) . '/transactions/outlets/' . rawurlencode($outletRef) . '/orders/' . rawurlencode($orderRef) . '/refunds');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/vnd.ni-payment.v2+json',
            'Accept: application/vnd.ni-payment.v2+json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'state' => 'FAILED', 'refund_ref' => null, 'amount' => null, 'error' => 'connection_failed: ' . $curlError];
    }
    $data = json_decode($response, true);
    if ($httpCode >= 300) {
        $msg = $data['errors'][0]['message'] ?? $data['message'] ?? ('refund_failed_http_' . $httpCode);
        return ['ok' => false, 'state' => 'FAILED', 'refund_ref' => null, 'amount' => null, 'error' => (string)$msg];
    }
    return [
        'ok' => true,
        'state' => gateway_normalize_state($data['state'] ?? 'REFUNDED'),
        'refund_ref' => $data['reference'] ?? $orderRef,
        'amount' => $amount,
        'error' => null,
    ];
}

/** الحالات التي تُعتبر دفعاً ناجحاً فعلياً حسب توثيق N-Genius. */
function ngenius_is_paid_state(?string $state): bool {
    return in_array($state, ['CAPTURED', 'PURCHASED'], true);
}

/** يجلب إعدادات بوابة الدفع المحفوظة من site_settings كمصفوفة جاهزة. */
function ngenius_settings(PDO $pdo): array {
    $settings = [];
    $keys = ['ngenius_enabled','ngenius_environment','ngenius_outlet_ref','ngenius_api_key','ngenius_realm_name','ngenius_webhook_secret','ngenius_currency'];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($keys);
    foreach ($stmt->fetchAll() as $row) { $settings[$row['setting_key']] = $row['setting_value']; }
    foreach (['ngenius_api_key','ngenius_webhook_secret'] as $secretKey) {
        if (!isset($settings[$secretKey])) continue;
        $raw=$settings[$secretKey]; $settings[$secretKey]=secret_decrypt_value($raw);
        if ($settings[$secretKey]!=='' && strpos((string)$raw,'enc:v1:')!==0 && secret_encryption_key()) secret_store_setting($pdo,$secretKey,$settings[$secretKey]);
    }
    return $settings;
}

function ngenius_is_enabled(array $settings): bool {
    return ($settings['ngenius_enabled'] ?? '0') === '1' && !empty($settings['ngenius_outlet_ref']) && !empty($settings['ngenius_api_key']);
}
