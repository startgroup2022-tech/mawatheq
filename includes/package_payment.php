<?php
/**
 * Package (plan) purchasing and payment orchestration (Execution 63).
 *
 * A package is not an invoice and not a consultation, but it must behave the same way:
 *
 *   choose plan → create order → hosted payment page → (webhook / return) →
 *   independent server-side verification → activate the subscription.
 *
 * The one rule that matters, and the reason this file exists rather than a few lines in
 * checkout.php: **a package is never activated because the customer came back to a success
 * URL.** The gateway is re-queried, and only its own answer activates the plan. A failed,
 * cancelled, expired or merely pending payment leaves the order pending and the plan
 * unchanged.
 *
 * Idempotency is layered exactly as the invoice and consultation paths do it:
 *   - package_orders.payment_reference is UNIQUE, so one order activates one purchase;
 *   - payment_transactions.event_hash is UNIQUE, so a replayed webhook is a no-op;
 *   - the activation UPDATE is guarded on the current status, so the return page and the
 *     webhook cannot both activate the same order.
 */
require_once __DIR__ . '/ngenius.php';
require_once __DIR__ . '/gateway/contract.php';
require_once __DIR__ . '/currency.php';

/** The package payment schema is present (upgrade_v70.sql applied). */
function package_payment_schema_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $ready = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'package_orders' LIMIT 1")->fetchColumn();
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** The billing cycles a plan offers, derived from its stored price columns. */
function package_plan_cycles(array $plan): array {
    $hasMonthly = (float)($plan['price_monthly'] ?? 0) > 0;
    $hasYearly  = isset($plan['price_yearly']) && (float)$plan['price_yearly'] > 0;
    $allowed    = in_array($plan['billing_cycle'] ?? 'both', ['monthly', 'yearly', 'both'], true)
        ? $plan['billing_cycle'] : 'both';
    $cycles = [];
    if ($hasMonthly && $allowed !== 'yearly') $cycles[] = 'monthly';
    if ($hasYearly && $allowed !== 'monthly') $cycles[] = 'yearly';
    // A free plan has no positive price; it is still purchasable as a monthly activation so
    // the catalogue can offer it without a special case.
    if (!$cycles && (float)($plan['price_monthly'] ?? 0) === 0.0 && !$hasYearly) $cycles[] = 'monthly';
    return $cycles;
}

/** The price of a plan for a given cycle, or null when that cycle is not offered. */
function package_plan_price(array $plan, string $cycle): ?float {
    if (!in_array($cycle, package_plan_cycles($plan), true)) return null;
    if ($cycle === 'yearly') return (float)($plan['price_yearly'] ?? 0);
    return (float)($plan['price_monthly'] ?? 0);
}

/** A plan that costs nothing is activated immediately; it needs no gateway order. */
function package_plan_is_free(array $plan, string $cycle): bool {
    $price = package_plan_price($plan, $cycle);
    return $price !== null && $price <= 0;
}

/** Stable gateway reference for a package order attempt (unique per attempt). */
function package_payment_reference(int $orderId): string {
    return 'PKG-' . $orderId . '-' . strtoupper(bin2hex(random_bytes(5)));
}

/** The browser return URL for a package payment. */
function package_payment_return_url(int $orderId): string {
    return rtrim(SITE_URL, '/') . '/package-payment-return.php?order_id=' . $orderId;
}

/**
 * Create a pending package order for a user, resolving the amount from the plan itself so
 * the price the customer pays is the price the database holds, not a value from the form.
 *
 * @return array{ok:bool, order_id?:int, order?:array, error?:string}
 */
function package_order_create(PDO $pdo, int $userId, int $planId, string $cycle): array {
    if (!package_payment_schema_ready($pdo)) return ['ok' => false, 'error' => 'migration_required'];
    if (!in_array($cycle, ['monthly', 'yearly'], true)) return ['ok' => false, 'error' => 'invalid_cycle'];

    $stmt = $pdo->prepare("SELECT * FROM plans WHERE id=? AND status='active'");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();
    if (!$plan) return ['ok' => false, 'error' => 'plan_not_available'];

    $price = package_plan_price($plan, $cycle);
    if ($price === null) return ['ok' => false, 'error' => 'cycle_not_offered'];

    $currency = currency_code();
    $pdo->prepare('INSERT INTO package_orders (user_id, plan_id, billing_cycle, amount, currency, status) VALUES (?,?,?,?,?,?)')
        ->execute([$userId, $planId, $cycle, $price, $currency, 'pending']);
    $orderId = (int)$pdo->lastInsertId();

    $fetch = $pdo->prepare('SELECT * FROM package_orders WHERE id=?');
    $fetch->execute([$orderId]);
    return ['ok' => true, 'order_id' => $orderId, 'order' => $fetch->fetch()];
}

/**
 * Activate a free plan immediately: no gateway order, no payment. Used by the catalogue
 * for a plan that costs nothing so the customer is not sent to a payment page for 0.
 *
 * @return array{ok:bool, order_id?:int, subscription_id?:int, error?:string}
 */
function package_activate_free(PDO $pdo, int $userId, int $planId, string $cycle): array {
    if (!package_payment_schema_ready($pdo)) return ['ok' => false, 'error' => 'migration_required'];
    $stmt = $pdo->prepare("SELECT * FROM plans WHERE id=? AND status='active'");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();
    if (!$plan) return ['ok' => false, 'error' => 'plan_not_available'];
    if (!package_plan_is_free($plan, $cycle)) return ['ok' => false, 'error' => 'plan_is_not_free'];

    $created = package_order_create($pdo, $userId, $planId, $cycle);
    if (empty($created['ok'])) return $created;

    $activation = package_payment_activate($pdo, $created['order'], ['amount' => 0, 'currency' => $created['order']['currency']]);
    return ['ok' => !empty($activation['activated']), 'order_id' => $created['order_id'],
        'subscription_id' => $activation['subscription_id'] ?? null, 'error' => $activation['error'] ?? null];
}

/**
 * Start a payment for a package order: create the gateway order and return where to send
 * the customer. The order stays 'pending' — never 'paid' — until the gateway confirms.
 *
 * @return array{ok:bool, redirect_url?:string, reference?:string, gateway?:string, error?:string}
 */
function package_payment_start(PDO $pdo, array $order, array $options = []): array {
    if (!package_payment_schema_ready($pdo)) return ['ok' => false, 'error' => 'migration_required'];

    $settings = ngenius_settings($pdo);
    if (!ngenius_is_enabled($settings)) return ['ok' => false, 'error' => 'no_enabled_gateway'];

    $orderCurrency = strtoupper((string)($order['currency'] ?: currency_code()));
    $currency = ngenius_currency($settings);
    if (!ngenius_supports_currency($settings, $orderCurrency)) {
        return ['ok' => false, 'error' => 'unsupported_currency'];
    }

    $amount = (float)$order['amount'];
    if ($amount <= 0) return ['ok' => false, 'error' => 'zero_amount'];

    $orderId = (int)$order['id'];
    $reference = package_payment_reference($orderId);

    $tokenResult = ngenius_get_access_token(
        $settings['ngenius_environment'], $settings['ngenius_api_key'], $settings['ngenius_realm_name'] ?? ''
    );
    if (empty($tokenResult['ok'])) return ['ok' => false, 'error' => $tokenResult['error']];

    $gatewayOrder = ngenius_create_order(
        $settings['ngenius_environment'],
        $settings['ngenius_outlet_ref'],
        $tokenResult['token'],
        $amount,
        (string)($options['customer_email'] ?? ''),
        (string)($options['customer_name'] ?? ''),
        $options['return_url'] ?? package_payment_return_url($orderId),
        $options['cancel_url'] ?? (rtrim(SITE_URL, '/') . '/packages.php'),
        $reference,
        (string)($options['language'] ?? 'ar'),
        $currency
    );

    try {
        $pdo->prepare('INSERT INTO payment_transactions
            (package_order_id, gateway, gateway_order_ref, event_type, amount, currency, status, environment, response_message)
            VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([
                $orderId, 'ngenius', $gatewayOrder['order_reference'] ?? $reference, 'ORDER_CREATED',
                $amount, $currency, $gatewayOrder['ok'] ? 'INITIATED' : 'FAILED',
                $settings['ngenius_environment'], $gatewayOrder['ok'] ? null : ($gatewayOrder['error'] ?? null),
            ]);
    } catch (Throwable $e) {
        error_log('package payment log failed: ' . $e->getMessage());
    }

    if (empty($gatewayOrder['ok'])) {
        $pdo->prepare("UPDATE package_orders SET status='failed' WHERE id=? AND status='pending'")->execute([$orderId]);
        return ['ok' => false, 'error' => $gatewayOrder['error'] ?? 'gateway_error', 'gateway' => 'ngenius'];
    }

    $pdo->prepare("UPDATE package_orders SET status='pending', payment_reference=?, payment_gateway='ngenius' WHERE id=?")
        ->execute([$gatewayOrder['order_reference'], $orderId]);

    return ['ok' => true, 'redirect_url' => (string)$gatewayOrder['payment_url'],
        'reference' => (string)$gatewayOrder['order_reference'], 'gateway' => 'ngenius'];
}

/**
 * Independently verify a package payment against the gateway and, only when the gateway
 * confirms the capture, activate the subscription.
 *
 * Safe to call from both the return page and the webhook: the activation update is guarded
 * on the current status, so the second caller is a no-op.
 *
 * @return array{ok:bool, state:string, activated:bool, subscription_id?:int, error:?string}
 */
function package_payment_verify_and_confirm(PDO $pdo, int $orderId, ?string $orderRef = null): array {
    if (!package_payment_schema_ready($pdo)) {
        return ['ok' => false, 'state' => 'PENDING', 'activated' => false, 'error' => 'migration_required'];
    }
    $stmt = $pdo->prepare('SELECT * FROM package_orders WHERE id=?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) return ['ok' => false, 'state' => 'PENDING', 'activated' => false, 'error' => 'not_found'];

    $reference = $orderRef ?: (string)($order['payment_reference'] ?? '');
    if ($reference === '') return ['ok' => false, 'state' => 'PENDING', 'activated' => false, 'error' => 'no_reference'];

    $settings = ngenius_settings($pdo);
    if (!ngenius_is_enabled($settings)) {
        return ['ok' => false, 'state' => 'PENDING', 'activated' => false, 'error' => 'gateway_disabled'];
    }
    $tokenResult = ngenius_get_access_token(
        $settings['ngenius_environment'], $settings['ngenius_api_key'], $settings['ngenius_realm_name'] ?? ''
    );
    if (empty($tokenResult['ok'])) {
        return ['ok' => false, 'state' => 'PENDING', 'activated' => false, 'error' => $tokenResult['error']];
    }
    $status = ngenius_get_order_status(
        $settings['ngenius_environment'], $settings['ngenius_outlet_ref'], $tokenResult['token'], $reference
    );
    if (empty($status['ok'])) {
        return ['ok' => false, 'state' => 'PENDING', 'activated' => false, 'error' => $status['error']];
    }

    $state = gateway_normalize_state($status['state']);
    $isPaid = gateway_is_paid_state($state);

    try {
        $pdo->prepare('INSERT INTO payment_transactions
            (package_order_id, gateway, gateway_order_ref, event_type, event_hash, amount, currency, status, environment, verified_at)
            VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $orderId, 'ngenius', $reference, 'PAYMENT_VERIFIED',
                hash('sha256', 'pkgverify|' . $reference . '|' . $state),
                $status['amount'], $status['currency'] ?: ngenius_currency($settings), $state,
                $settings['ngenius_environment'], date('Y-m-d H:i:s'),
            ]);
    } catch (PDOException $e) {
        // duplicate verification event — fine, continue to the activation guard
    }

    if (!$isPaid) {
        if (in_array($state, ['FAILED', 'CANCELLED', 'EXPIRED'], true)) {
            $pdo->prepare("UPDATE package_orders SET status='failed' WHERE id=? AND status='pending'")->execute([$orderId]);
        }
        return ['ok' => true, 'state' => $state, 'activated' => false, 'error' => null];
    }

    return package_payment_activate($pdo, $order, $status);
}

/**
 * Activate the subscription for a paid package order. Guarded so it can run at most once:
 * the UPDATE only matches an order that is not already 'paid'. Returns the subscription id.
 *
 * @return array{ok:bool, state:string, activated:bool, subscription_id?:int, error:?string}
 */
function package_payment_activate(PDO $pdo, array $order, array $status): array {
    $orderId = (int)$order['id'];
    $userId  = (int)$order['user_id'];
    $planId  = (int)$order['plan_id'];
    $cycle   = in_array($order['billing_cycle'] ?? '', ['monthly', 'yearly'], true) ? $order['billing_cycle'] : 'monthly';

    $pdo->prepare("UPDATE package_orders SET status='paid', paid_at=NOW() WHERE id=? AND status<>'paid'")
        ->execute([$orderId]);

    $already = $pdo->prepare('SELECT status, subscription_id FROM package_orders WHERE id=?');
    $already->execute([$orderId]);
    $row = $already->fetch();
    if (!$row || $row['status'] !== 'paid') {
        return ['ok' => false, 'state' => 'FAILED', 'activated' => false, 'error' => 'activation_failed'];
    }
    // A row that already carried a subscription was activated by an earlier callback.
    if (!empty($row['subscription_id'])) {
        return ['ok' => true, 'state' => 'PAID', 'activated' => false, 'subscription_id' => (int)$row['subscription_id']];
    }

    $startsAt = date('Y-m-d');
    $endsAt = date('Y-m-d', strtotime($cycle === 'yearly' ? '+1 year' : '+1 month'));

    $pdo->prepare('INSERT INTO subscriptions (user_id, plan_id, billing_cycle, starts_at, ends_at, status) VALUES (?,?,?,?,?,?)')
        ->execute([$userId, $planId, $cycle, $startsAt, $endsAt, 'active']);
    $subscriptionId = (int)$pdo->lastInsertId();

    // The plan entitlement the rest of the platform reads is users.plan_id. Point it at the
    // purchased plan so every gated feature unlocks immediately.
    $pdo->prepare('UPDATE users SET plan_id=? WHERE id=?')->execute([$planId, $userId]);
    $pdo->prepare('UPDATE package_orders SET subscription_id=? WHERE id=?')->execute([$subscriptionId, $orderId]);

    if (is_file(__DIR__ . '/audit_log.php')) {
        require_once __DIR__ . '/audit_log.php';
        if (function_exists('log_action')) log_action($pdo, 'تفعيل باقة مدفوعة (ngenius)', 'plan', $planId);
    }
    if (is_file(__DIR__ . '/notifications.php')) {
        require_once __DIR__ . '/notifications.php';
        if (function_exists('notify')) {
            notify($pdo, 'client', $userId,
                is_rtl() ? 'تم تفعيل باقتك' : 'Your package is active',
                null, '/subscription.php');
        }
    }

    return ['ok' => true, 'state' => 'PAID', 'activated' => true, 'subscription_id' => $subscriptionId];
}

/**
 * Find a package order by its gateway order reference. Returns null for an unknown
 * reference — a webhook must never guess.
 */
function package_payment_find_by_reference(PDO $pdo, string $orderRef): ?array {
    if (!package_payment_schema_ready($pdo)) return null;
    $stmt = $pdo->prepare('SELECT * FROM package_orders WHERE payment_reference = ? LIMIT 1');
    $stmt->execute([$orderRef]);
    $row = $stmt->fetch();
    return $row ?: null;
}
