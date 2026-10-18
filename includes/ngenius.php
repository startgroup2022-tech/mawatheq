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
    return $environment === 'production'
        ? 'https://api-gateway.ksa.ngenius-payments.com'
        : 'https://api-gateway.sandbox.ksa.ngenius-payments.com';
}

/**
 * الخطوة 1: طلب رمز دخول (صالح 5 دقائق فقط) باستخدام مفتاح حساب الخدمة.
 * @return array{ok:bool, token:?string, error:?string}
 */
function ngenius_get_access_token(string $environment, string $apiKey, string $realmName): array {
    $ch = curl_init(ngenius_base_url($environment) . '/identity/auth/access-token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.ni-identity.v1+json',
            'Authorization: Basic ' . $apiKey,
            'Content-Type: application/vnd.ni-identity.v1+json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['realmName' => $realmName]),
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
        return ['ok' => false, 'token' => null, 'error' => 'فشل الحصول على رمز دخول من بوابة الدفع (HTTP ' . $httpCode . '). تحقق من مفتاح API واسم Realm.'];
    }
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

    $ch = curl_init(ngenius_base_url($environment) . '/transactions/outlets/' . $outletRef . '/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 25,
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
        $msg = $data['error']['message'] ?? ('فشل إنشاء طلب الدفع (HTTP ' . $httpCode . ').');
        return ['ok' => false, 'order_reference' => null, 'payment_url' => null, 'error' => $msg];
    }
    return ['ok' => true, 'order_reference' => $data['reference'], 'payment_url' => $data['_links']['payment']['href'], 'error' => null];
}

/**
 * الخطوة 3: الاستعلام عن حالة طلب دفع حقيقياً من البوابة (بدل الثقة بمعاملات الرابط فقط).
 * @return array{ok:bool, state:?string, error:?string}
 */
function ngenius_get_order_status(string $environment, string $outletRef, string $accessToken, string $orderRef): array {
    $ch = curl_init(ngenius_base_url($environment) . '/transactions/outlets/' . $outletRef . '/orders/' . $orderRef);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return ['ok' => false, 'state' => null, 'error' => 'تعذّر الاستعلام عن حالة الطلب (HTTP ' . $httpCode . ').'];
    }
    $data = json_decode($response, true);
    $state = $data['_embedded']['payment'][0]['state'] ?? null;
    return ['ok' => true, 'state' => $state, 'error' => null];
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
