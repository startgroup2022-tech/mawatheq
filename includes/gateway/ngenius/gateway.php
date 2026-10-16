<?php
/**
 * N-Genius gateway adapter.
 *
 * A thin wrapper over the existing, unchanged `ngenius_*` functions in includes/ngenius.php.
 * Its only job is to present N-Genius through the shared PaymentGateway contract so both
 * gateways are interchangeable at the call sites. Behaviour is identical to before: the same
 * access-token call, the same order creation, the same state vocabulary.
 */
require_once __DIR__ . '/../contract.php';
require_once __DIR__ . '/../../ngenius.php';

final class NGeniusGateway implements PaymentGateway {
    private PDO $pdo;
    private array $settings;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->settings = ngenius_settings($pdo);
    }

    public function id(): string { return 'ngenius'; }
    public function label(): string { return 'N-Genius'; }

    public function isEnabled(): bool { return ngenius_is_enabled($this->settings); }

    public function supportsCurrency(?string $currency): bool {
        return ngenius_supports_currency($this->settings, $currency);
    }

    public function environment(): string {
        return ($this->settings['ngenius_environment'] ?? 'sandbox') === 'production' ? 'live' : 'sandbox';
    }

    public function settings(): array { return $this->settings; }

    public function createPayment(array $intent): array {
        if (!ngenius_is_enabled($this->settings)) {
            return ['ok' => false, 'reference' => null, 'redirect_url' => null, 'state' => 'FAILED', 'error' => 'ngenius_not_enabled', 'raw' => []];
        }
        $currency = strtoupper((string)($intent['currency'] ?? ngenius_currency($this->settings)));
        if (!ngenius_supports_currency($this->settings, $currency)) {
            return ['ok' => false, 'reference' => null, 'redirect_url' => null, 'state' => 'FAILED', 'error' => 'unsupported_currency', 'raw' => []];
        }
        $tokenResult = ngenius_get_access_token(
            $this->settings['ngenius_environment'],
            $this->settings['ngenius_api_key'],
            $this->settings['ngenius_realm_name'] ?: 'ni'
        );
        if (empty($tokenResult['ok'])) {
            return ['ok' => false, 'reference' => null, 'redirect_url' => null, 'state' => 'FAILED', 'error' => $tokenResult['error'], 'raw' => []];
        }
        $order = ngenius_create_order(
            $this->settings['ngenius_environment'],
            $this->settings['ngenius_outlet_ref'],
            $tokenResult['token'],
            (float)($intent['amount'] ?? 0),
            (string)($intent['customer']['email'] ?? ''),
            (string)($intent['customer']['name'] ?? ''),
            (string)($intent['urls']['return'] ?? ''),
            (string)($intent['urls']['cancel'] ?? ''),
            (string)($intent['reference'] ?? ''),
            (string)($intent['language'] ?? 'ar'),
            $currency
        );
        if (empty($order['ok'])) {
            return ['ok' => false, 'reference' => null, 'redirect_url' => null, 'state' => 'FAILED', 'error' => $order['error'], 'raw' => []];
        }
        return [
            'ok' => true, 'reference' => $order['order_reference'], 'redirect_url' => $order['payment_url'],
            'state' => 'REDIRECTED', 'transaction_id' => $order['order_reference'], 'error' => null, 'raw' => [],
        ];
    }

    public function verifyPayment(array $payment): array {
        $reference = (string)($payment['reference'] ?? '');
        $tokenResult = ngenius_get_access_token(
            $this->settings['ngenius_environment'],
            $this->settings['ngenius_api_key'],
            $this->settings['ngenius_realm_name'] ?: 'ni'
        );
        if (empty($tokenResult['ok'])) {
            return ['ok' => false, 'state' => 'PENDING', 'amount' => null, 'currency' => null, 'reference' => $reference, 'error' => $tokenResult['error'], 'raw' => []];
        }
        $status = ngenius_get_order_status(
            $this->settings['ngenius_environment'],
            $this->settings['ngenius_outlet_ref'],
            $tokenResult['token'],
            $reference
        );
        if (empty($status['ok'])) {
            return ['ok' => false, 'state' => 'PENDING', 'amount' => null, 'currency' => null, 'reference' => $reference, 'error' => $status['error'], 'raw' => []];
        }
        return [
            'ok' => true,
            'state' => gateway_normalize_state($status['state']),
            'amount' => isset($payment['amount']) ? (float)$payment['amount'] : null,
            'currency' => $payment['currency'] ?? ngenius_currency($this->settings),
            'reference' => $reference, 'error' => null, 'raw' => [],
        ];
    }

    /**
     * N-Genius webhooks are handled by the existing ngenius-webhook.php endpoint (shared-secret
     * auth). This adapter method reports the contract shape for callers that iterate gateways.
     */
    public function handleWebhook(array $headers, string $rawBody): array {
        return ['ok' => false, 'event_id' => null, 'event_type' => null, 'reference' => null,
                'state' => 'PENDING', 'amount' => null, 'currency' => null, 'duplicate' => false,
                'error' => 'use_ngenius_webhook_endpoint', 'raw' => []];
    }

    public function supportsRefund(): bool { return false; }
    public function refundPayment(array $payment, ?float $amount = null): array {
        return ['ok' => false, 'state' => 'FAILED', 'reference' => null, 'error' => 'refund_not_supported', 'raw' => []];
    }

    public function healthCheck(): array {
        if (!ngenius_is_enabled($this->settings)) {
            return ['ok' => false, 'connected' => false, 'code' => 'configuration_required', 'checked_at' => date('Y-m-d H:i:s')];
        }
        $tokenResult = ngenius_get_access_token(
            $this->settings['ngenius_environment'],
            $this->settings['ngenius_api_key'],
            $this->settings['ngenius_realm_name'] ?: 'ni'
        );
        return ['ok' => !empty($tokenResult['ok']), 'connected' => !empty($tokenResult['ok']),
                'code' => !empty($tokenResult['ok']) ? 'connected' : 'unreachable',
                'error' => $tokenResult['ok'] ? null : ($tokenResult['error'] ?? 'connection_failed'),
                'checked_at' => date('Y-m-d H:i:s')];
    }
}
