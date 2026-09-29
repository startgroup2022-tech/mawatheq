<?php
/**
 * Payment gateway contract + registry.
 *
 * Each provider is an independent adapter behind this interface. The invoice/finance core
 * never talks to a provider directly: it resolves an adapter from the registry and calls the
 * contract. That keeps SADAD logic out of the invoice code and lets N-Genius keep its exact
 * existing behaviour behind a thin adapter.
 *
 * Result arrays (never exceptions for expected gateway outcomes) use a shared shape:
 *   createPayment() : ['ok'=>bool, 'reference'=>?string, 'redirect_url'=>?string, 'state'=>string, 'error'=>?string, 'raw'=>array]
 *   verifyPayment() : ['ok'=>bool, 'state'=>string, 'amount'=>?float, 'currency'=>?string, 'reference'=>?string, 'error'=>?string, 'raw'=>array]
 *   handleWebhook() : ['ok'=>bool, 'event_id'=>?string, 'event_type'=>?string, 'reference'=>?string,
 *                      'state'=>string, 'amount'=>?float, 'currency'=>?string, 'duplicate'=>bool, 'error'=>?string, 'raw'=>array]
 *   refundPayment() : ['ok'=>bool, 'state'=>string, 'reference'=>?string, 'error'=>?string, 'raw'=>array]
 *
 * States are normalised to the platform vocabulary (see gateway_states()).
 */

/** Canonical payment states shared by every gateway. */
function gateway_states(): array {
    return ['INITIATED','PENDING','REDIRECTED','AUTHORIZED','PAID','FAILED','CANCELLED','EXPIRED','REFUNDED'];
}

/** Map an arbitrary provider state string onto the canonical vocabulary. */
function gateway_normalize_state(?string $state): string {
    $s = strtoupper(trim((string)$state));
    if ($s === '') return 'PENDING';
    if (in_array($s, gateway_states(), true)) return $s;
    $aliases = [
        'CAPTURED' => 'PAID', 'PURCHASED' => 'PAID', 'SUCCESS' => 'PAID', 'SUCCEEDED' => 'PAID',
        'COMPLETED' => 'PAID', 'APPROVED' => 'PAID',
        'AUTHORISED' => 'AUTHORIZED', 'AUTHORIZATION' => 'AUTHORIZED',
        'DECLINED' => 'FAILED', 'FAILURE' => 'FAILED', 'ERROR' => 'FAILED',
        'CANCELED' => 'CANCELLED', 'VOID' => 'CANCELLED', 'REVERSED' => 'CANCELLED',
        'TIMEOUT' => 'EXPIRED',
        'REFUND' => 'REFUNDED',
    ];
    return $aliases[$s] ?? 'PENDING';
}

/** True only for the states that mean money has actually settled. */
function gateway_is_paid_state(?string $state): bool {
    return gateway_normalize_state($state) === 'PAID';
}

interface PaymentGateway {
    /** Stable machine id, e.g. 'sadad' — used as payment_transactions.gateway. */
    public function id(): string;

    /** Human label for the admin UI. */
    public function label(): string;

    /** True when the gateway is switched on and has the credentials it needs. */
    public function isEnabled(): bool;

    /** True when this gateway can charge an invoice denominated in $currency. */
    public function supportsCurrency(?string $currency): bool;

    /**
     * Create a payment for an invoice and return where to send the customer.
     * @param array $intent amount, currency, invoice_id, reference, customer, urls, environment
     */
    public function createPayment(array $intent): array;

    /**
     * Server-side verification of a payment's true state (never trust the return URL alone).
     * @param array $payment ['reference'=>string, 'invoice_id'=>?int, 'amount'=>?float, 'currency'=>?string]
     */
    public function verifyPayment(array $payment): array;

    /**
     * Validate + parse an inbound webhook/callback.
     * @param array $headers normalised (lower-case keys) request headers
     */
    public function handleWebhook(array $headers, string $rawBody): array;

    /** Whether this gateway exposes an official refund operation. */
    public function supportsRefund(): bool;

    /** Issue a refund where the provider officially supports it. */
    public function refundPayment(array $payment, ?float $amount = null): array;

    /** Lightweight, side-effect-free connectivity/config probe for the admin UI. */
    public function healthCheck(): array;
}

/** Process-wide registry of available gateway adapters. */
final class GatewayRegistry {
    /** @var array<string, callable> */
    private static array $factories = [];
    /** @var array<string, PaymentGateway> */
    private static array $instances = [];

    public static function register(string $id, callable $factory): void {
        self::$factories[strtolower($id)] = $factory;
        unset(self::$instances[strtolower($id)]);
    }

    public static function has(string $id): bool {
        return isset(self::$factories[strtolower($id)]);
    }

    /** Resolve an adapter, or null when the id is unknown. */
    public static function get(string $id): ?PaymentGateway {
        $id = strtolower($id);
        if (isset(self::$instances[$id])) return self::$instances[$id];
        if (!isset(self::$factories[$id])) return null;
        $instance = (self::$factories[$id])();
        if (!$instance instanceof PaymentGateway) return null;
        return self::$instances[$id] = $instance;
    }

    /** All registered adapters, keyed by id. */
    public static function all(): array {
        $out = [];
        foreach (array_keys(self::$factories) as $id) {
            $g = self::get($id);
            if ($g) $out[$id] = $g;
        }
        return $out;
    }

    /** Test seam: drop cached instances so config changes are picked up. */
    public static function reset(): void {
        self::$instances = [];
    }
}
