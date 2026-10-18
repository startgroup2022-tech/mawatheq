<?php
/**
 * SADAD gateway adapter — implements the PaymentGateway contract over SadadService.
 *
 * The adapter is bound to one tenant's configuration. Resolve the correct instance for an
 * invoice with gateway_for_invoice() so credentials are never shared across tenants.
 */
require_once __DIR__ . '/service.php';
require_once __DIR__ . '/../contract.php';

final class SadadGateway implements PaymentGateway {
    private array $config;
    private SadadService $service;

    public function __construct(array $config) {
        $this->config = $config;
        $this->service = new SadadService($config);
    }

    public function id(): string { return 'sadad'; }
    public function label(): string { return 'SADAD'; }

    public function isEnabled(): bool { return sadad_is_enabled($this->config); }

    public function supportsCurrency(?string $currency): bool {
        return sadad_supports_currency($this->config, $currency);
    }

    public function environment(): string { return $this->service->environment(); }

    /** Expose the config for the admin UI (callers must not print secret keys). */
    public function config(): array { return $this->config; }

    public function createPayment(array $intent): array {
        $intent['urls']['webhook'] = $intent['urls']['webhook'] ?? sadad_webhook_url();
        $result = $this->service->createPayment($intent);
        if (!empty($result['ok']) && empty($result['state'])) $result['state'] = 'REDIRECTED';
        return $result;
    }

    public function verifyPayment(array $payment): array {
        return $this->service->verifyPayment((string)($payment['reference'] ?? ''));
    }

    public function handleWebhook(array $headers, string $rawBody): array {
        $parsed = $this->service->parseWebhook($headers, $rawBody);
        if (empty($parsed['ok'])) {
            return ['ok' => false, 'event_id' => null, 'event_type' => null, 'reference' => null,
                    'state' => 'PENDING', 'amount' => null, 'currency' => null, 'duplicate' => false,
                    'error' => $parsed['error'] ?? 'invalid', 'raw' => $parsed['raw'] ?? []];
        }
        $parsed['duplicate'] = false;
        return $parsed;
    }

    public function supportsRefund(): bool { return $this->service->supportsRefund(); }

    public function refundPayment(array $payment, ?float $amount = null): array {
        return $this->service->refundPayment((string)($payment['reference'] ?? ''), $amount);
    }

    public function healthCheck(): array { return $this->service->healthCheck(); }
}
