<?php
/**
 * SADAD transport service.
 *
 * A generic, configuration-driven HTTP client. It knows nothing about SADAD's concrete payload
 * shape — the operator supplies the endpoint paths and the JSON field mapping in config, and this
 * service sends/receives exactly that. This is deliberate: the official SADAD merchant API
 * (endpoints, auth scheme, payload schema) is issued per merchant and is not public, so guessing
 * it here would be worse than not shipping it. When the merchant docs arrive, only config changes.
 *
 * Nothing here logs or returns secrets; every error string is a safe, operator-facing message.
 */
require_once __DIR__ . '/config.php';

final class SadadService {
    private array $config;

    public function __construct(array $config) {
        $this->config = $config;
    }

    /** True when the operator supplied every field required to make a call. */
    public function isConfigured(): bool {
        return sadad_missing_keys($this->config) === [];
    }

    public function missingKeys(): array {
        return sadad_missing_keys($this->config);
    }

    public function environment(): string {
        return ($this->config['sadad_environment'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';
    }

    public function isLive(): bool {
        return $this->environment() === 'live';
    }

    /** Whether the merchant contract exposes an official refund operation (endpoint configured). */
    public function supportsRefund(): bool {
        return trim((string)($this->config['sadad_refund_endpoint'] ?? '')) !== '';
    }

    /**
     * Create a payment. Returns the normalised result shape; never throws for expected outcomes.
     */
    public function createPayment(array $intent): array {
        if (!$this->isConfigured()) {
            return $this->fail('sadad_not_configured');
        }
        $amount = (float)($intent['amount'] ?? 0);
        $currency = strtoupper((string)($intent['currency'] ?? ($this->config['sadad_currency'] ?? 'SAR')));
        if (!is_finite($amount) || $amount <= 0) return $this->fail('invalid_amount');
        if (!$this->currencyMatches($currency)) return $this->fail('unsupported_currency');

        $payload = [
            'merchantId' => (string)($this->config['sadad_merchant_id'] ?? ''),
            'terminalId' => (string)($this->config['sadad_terminal_id'] ?? ''),
            'orderReference' => (string)($intent['reference'] ?? ''),
            'amount' => $this->encodeAmount($amount),
            'currency' => $currency,
            'customer' => [
                'name' => (string)($intent['customer']['name'] ?? ''),
                'email' => (string)($intent['customer']['email'] ?? ''),
                'phone' => (string)($intent['customer']['phone'] ?? ''),
            ],
            'returnUrl' => (string)($intent['urls']['return'] ?? ''),
            'cancelUrl' => (string)($intent['urls']['cancel'] ?? ''),
            'callbackUrl' => (string)($intent['urls']['webhook'] ?? sadad_webhook_url()),
            'language' => (string)($intent['language'] ?? 'ar'),
        ];
        if (!empty($intent['description'])) $payload['description'] = (string)$intent['description'];

        $resp = $this->request('POST', (string)$this->config['sadad_create_endpoint'], $payload);
        if (!$resp['ok']) return $this->fail($resp['error']);

        $body = $resp['body'];
        $reference = $this->mapString($body, 'sadad_map_reference') ?: (string)($intent['reference'] ?? '');
        $redirect = $this->mapString($body, 'sadad_map_redirect_url');
        $state = gateway_normalize_state($this->mapString($body, 'sadad_map_state') ?: 'REDIRECTED');

        return [
            'ok' => true,
            'reference' => $reference,
            'redirect_url' => $redirect,
            'state' => $state,
            'transaction_id' => $this->mapString($body, 'sadad_map_transaction_id'),
            'error' => null,
            'raw' => $body,
        ];
    }

    /**
     * Server-side verification of a payment's true state (used on return + reconciliation).
     */
    public function verifyPayment(string $reference): array {
        if (!$this->isConfigured()) return $this->fail('sadad_not_configured');
        $endpoint = trim((string)($this->config['sadad_verify_endpoint'] ?? ''));
        if ($endpoint === '') {
            // No official verify endpoint configured: report pending, never invent one.
            return ['ok' => false, 'state' => 'PENDING', 'amount' => null, 'currency' => null,
                    'reference' => $reference, 'transaction_id' => null, 'error' => 'verify_endpoint_not_configured', 'raw' => []];
        }
        $endpoint = $this->expand($endpoint, ['reference' => rawurlencode($reference)]);
        $resp = $this->request('GET', $endpoint, null);
        if (!$resp['ok']) return $this->fail($resp['error']);
        $body = $resp['body'];
        return [
            'ok' => true,
            'state' => gateway_normalize_state($this->mapString($body, 'sadad_map_state')),
            'amount' => $this->mapAmount($body),
            'currency' => $this->mapString($body, 'sadad_map_currency') ?: ($this->config['sadad_currency'] ?? 'SAR'),
            'reference' => $this->mapString($body, 'sadad_map_reference') ?: $reference,
            'transaction_id' => $this->mapString($body, 'sadad_map_transaction_id'),
            'error' => null,
            'raw' => $body,
        ];
    }

    /** Issue a refund (only when the merchant contract exposes an official endpoint). */
    public function refundPayment(string $reference, ?float $amount = null): array {
        if (!$this->isConfigured()) return $this->fail('sadad_not_configured');
        if (!$this->supportsRefund()) return $this->fail('refund_not_supported');
        $payload = ['merchantId' => (string)($this->config['sadad_merchant_id'] ?? ''), 'orderReference' => $reference];
        if ($amount !== null) $payload['amount'] = $this->encodeAmount((float)$amount);
        $resp = $this->request('POST', (string)$this->config['sadad_refund_endpoint'], $payload);
        if (!$resp['ok']) return $this->fail($resp['error']);
        return [
            'ok' => true,
            'state' => gateway_normalize_state($this->mapString($resp['body'], 'sadad_map_state') ?: 'REFUNDED'),
            'reference' => $this->mapString($resp['body'], 'sadad_map_reference') ?: $reference,
            'error' => null,
            'raw' => $resp['body'],
        ];
    }

    /**
     * Validate an inbound webhook: signature (when configured) + parse the event into the
     * normalised shape. Replay/idempotency is enforced by the caller via the event hash.
     */
    public function parseWebhook(array $headers, string $rawBody): array {
        $secret = (string)($this->config['sadad_webhook_secret'] ?? '');
        $algo = (string)($this->config['sadad_webhook_signature_algo'] ?? 'hmac_sha256');
        $headerName = strtolower((string)($this->config['sadad_webhook_signature_header'] ?? ''));

        if ($secret !== '' && $headerName !== '' && $algo !== 'none') {
            $provided = (string)($headers[$headerName] ?? '');
            if ($provided === '' || !$this->verifySignature($rawBody, $provided, $secret, $algo)) {
                return ['ok' => false, 'error' => 'invalid_signature', 'raw' => []];
            }
        }

        $body = json_decode($rawBody, true);
        if (!is_array($body)) return ['ok' => false, 'error' => 'invalid_payload', 'raw' => []];

        $reference = $this->mapString($body, 'sadad_map_reference');
        if ($reference === '') return ['ok' => false, 'error' => 'missing_reference', 'raw' => $body];

        return [
            'ok' => true,
            'event_id' => $this->mapString($body, 'sadad_map_event_id'),
            'event_type' => $this->mapString($body, 'sadad_map_event_type') ?: 'payment',
            'reference' => $reference,
            'transaction_id' => $this->mapString($body, 'sadad_map_transaction_id'),
            'state' => gateway_normalize_state($this->mapString($body, 'sadad_map_state')),
            'amount' => $this->mapAmount($body),
            'currency' => $this->mapString($body, 'sadad_map_currency') ?: ($this->config['sadad_currency'] ?? 'SAR'),
            'error' => null,
            'raw' => $body,
        ];
    }

    /**
     * Lightweight connectivity probe. If the operator configured a verify endpoint we call it with
     * a non-existent reference (a real, documented read operation — no payment is created). If no
     * verify endpoint exists we only confirm configuration, because inventing a probe endpoint
     * would be unsafe.
     */
    public function healthCheck(): array {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'connected' => false, 'code' => 'configuration_required', 'missing' => $this->missingKeys(), 'checked_at' => date('Y-m-d H:i:s')];
        }
        $verify = trim((string)($this->config['sadad_verify_endpoint'] ?? ''));
        if ($verify === '') {
            return ['ok' => true, 'connected' => false, 'code' => 'configured_no_probe', 'checked_at' => date('Y-m-d H:i:s'),
                    'message' => 'الإعدادات مكتملة؛ لا يوجد endpoint تحقق رسمي لاختبار الاتصال.'];
        }
        $endpoint = $this->expand($verify, ['reference' => '__healthcheck__' . bin2hex(random_bytes(4))]);
        $resp = $this->request('GET', $endpoint, null);
        // Any structured HTTP answer (even 404/400 for the fake reference) proves connectivity.
        return [
            'ok' => $resp['reachable'],
            'connected' => $resp['reachable'],
            'code' => $resp['reachable'] ? 'connected' : 'unreachable',
            'http_code' => $resp['http_code'] ?? null,
            'error' => $resp['reachable'] ? null : ($resp['error'] ?? 'connection_failed'),
            'checked_at' => date('Y-m-d H:i:s'),
        ];
    }

    // ---- internals -------------------------------------------------------

    private function currencyMatches(string $currency): bool {
        return $currency === strtoupper((string)($this->config['sadad_currency'] ?? 'SAR'));
    }

    /** Amount in the unit the merchant contract expects (major by default). */
    private function encodeAmount(float $amount): float {
        if (($this->config['sadad_amount_unit'] ?? 'major') === 'minor') {
            return round($amount * 100, 0);
        }
        return round($amount, 2);
    }

    /** Read a value from the response body using the operator-provided JSON path. */
    private function mapString(array $body, string $pathKey): string {
        $path = (string)($this->config[$pathKey] ?? '');
        if ($path === '') return '';
        $value = $this->dig($body, $path);
        return is_scalar($value) ? (string)$value : '';
    }

    private function mapAmount(array $body): ?float {
        $path = (string)($this->config['sadad_map_amount'] ?? '');
        if ($path === '') return null;
        $value = $this->dig($body, $path);
        if (!is_numeric($value)) return null;
        $amount = (float)$value;
        if (($this->config['sadad_amount_unit'] ?? 'major') === 'minor') $amount = $amount / 100;
        return $amount;
    }

    /** Minimal dotted-path reader, e.g. "data.payment.status" or "amount". */
    private function dig(array $body, string $path) {
        $cursor = $body;
        foreach (explode('.', $path) as $segment) {
            if ($segment === '') continue;
            if (is_array($cursor) && array_key_exists($segment, $cursor)) {
                $cursor = $cursor[$segment];
            } else {
                return null;
            }
        }
        return $cursor;
    }

    /** Substitute {placeholders} in a configured endpoint path. */
    private function expand(string $endpoint, array $vars): string {
        foreach ($vars as $k => $v) $endpoint = str_replace('{' . $k . '}', (string)$v, $endpoint);
        return $endpoint;
    }

    private function verifySignature(string $rawBody, string $provided, string $secret, string $algo): bool {
        $map = ['hmac_sha256' => 'sha256', 'hmac_sha512' => 'sha512'];
        if (!isset($map[$algo])) return false;
        $expected = hash_hmac($map[$algo], $rawBody, $secret);
        return hash_equals($expected, strtolower($provided)) || hash_equals('sha256=' . $expected, strtolower($provided));
    }

    /**
     * Perform the HTTP call. Returns a structured result; never leaks secrets in the error text.
     * @return array{ok:bool, reachable:bool, http_code:?int, body:array, error:?string}
     */
    private function request(string $method, string $endpoint, ?array $payload): array {
        $base = rtrim((string)($this->config['sadad_base_url'] ?? ''), '/');
        $url = preg_match('#^https?://#i', $endpoint) ? $endpoint : $base . '/' . ltrim($endpoint, '/');
        $timeout = max(5, min(60, (int)($this->config['sadad_timeout'] ?? 20)));

        $headers = ['Accept: application/json'];
        $auth = $this->authHeaders();
        $headers = array_merge($headers, $auth['headers']);

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        if (!empty($auth['userpwd'])) $opts[CURLOPT_USERPWD] = $auth['userpwd'];
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'reachable' => false, 'http_code' => null, 'body' => [], 'error' => 'connection_failed: ' . $curlError];
        }
        $decoded = json_decode((string)$response, true);
        $body = is_array($decoded) ? $decoded : [];
        $reachable = $httpCode > 0;
        if ($httpCode >= 300) {
            return ['ok' => false, 'reachable' => $reachable, 'http_code' => $httpCode, 'body' => $body,
                    'error' => 'gateway_http_' . $httpCode];
        }
        return ['ok' => true, 'reachable' => true, 'http_code' => $httpCode, 'body' => $body, 'error' => null];
    }

    /** Build auth headers from the configured auth mode (secrets never logged). */
    private function authHeaders(): array {
        $mode = (string)($this->config['sadad_auth_mode'] ?? 'none');
        $out = ['headers' => [], 'userpwd' => null];
        switch ($mode) {
            case 'bearer':
                $out['headers'][] = 'Authorization: Bearer ' . (string)($this->config['sadad_api_key'] ?? '');
                break;
            case 'api_key':
                $out['headers'][] = 'X-API-Key: ' . (string)($this->config['sadad_api_key'] ?? '');
                break;
            case 'basic':
                $out['userpwd'] = (string)($this->config['sadad_username'] ?? '') . ':' . (string)($this->config['sadad_password'] ?? '');
                break;
            case 'oauth2_client_credentials':
                $token = $this->fetchOAuthToken();
                if ($token !== '') $out['headers'][] = 'Authorization: Bearer ' . $token;
                break;
        }
        return $out;
    }

    private function fetchOAuthToken(): string {
        $endpoint = trim((string)($this->config['sadad_oauth_token_endpoint'] ?? ''));
        if ($endpoint === '') return '';
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => max(5, min(30, (int)($this->config['sadad_timeout'] ?? 20))),
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => (string)($this->config['sadad_api_key'] ?? ''),
                'client_secret' => (string)($this->config['sadad_api_secret'] ?? ''),
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = is_string($response) ? json_decode($response, true) : null;
        return is_array($data) ? (string)($data['access_token'] ?? '') : '';
    }

    private function fail(string $error): array {
        return ['ok' => false, 'reference' => null, 'redirect_url' => null, 'state' => 'FAILED',
                'transaction_id' => null, 'amount' => null, 'currency' => null, 'error' => $error, 'raw' => []];
    }
}
