<?php
/**
 * Finance core for the legal SaaS (Phase 3).
 *
 * Money model
 *   invoices.amount          — authoritative grand total (kept in sync with items, used by the gateway)
 *   invoices.subtotal        — sum of line items before discount/tax
 *   invoices.discount_total  — sum of per-line discounts
 *   invoices.tax_total       — sum of per-line tax
 *   invoices.amount_paid     — running total of completed invoice_payments
 *   balance                  — amount - amount_paid (never stored; always derived)
 *
 * Lifecycle (invoices.status): unpaid → partially_paid → paid; unpaid/partially_paid → overdue
 * (derived from due_date), cancelled is terminal. The legacy columns
 * (payment_method, payment_note, paid_at, gateway*) are preserved so existing screens,
 * reports and the N-Genius integration keep working unchanged.
 *
 * Every function here is tenant-scoped by the caller's resolved tenant, never by a
 * request parameter. There is no inventory anywhere in this schema.
 */
require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/audit_log.php';

/** Currency invoices are denominated in — follows the admin setting (default SAR). */
function finance_currency(): string { return currency_code(); }

const FINANCE_TAX_RATE_LIMIT = 100.0;

/** Round to the currency the invoice itself is denominated in. */
function finance_round(float $value, ?string $code = null): float {
    return round($value, currency_decimals($code));
}

/** Round using an invoice row's stored currency, for operations on existing invoices. */
function finance_round_for(array $invoice, float $value): float {
    return round($value, currency_decimals($invoice['currency'] ?? null));
}

/** The tenant that owns the invoices of the currently signed-in lawyer. */
function finance_owner_pair(?PDO $pdo = null): array {
    $ctx = tenant_current($pdo);
    return ['firm_id' => $ctx['firm_id'], 'lawyer_id' => $ctx['lawyer_id']];
}

/**
 * Generate a human-readable invoice number scoped to the tenant, e.g. INV-F3-2026-0007.
 * Uses a per-tenant MAX() of the existing sequence so it stays unique without a counter
 * table; the UNIQUE index on invoice_number is the real guarantee and the caller retries.
 */
function finance_next_invoice_number(PDO $pdo, ?int $firmId, int $lawyerId): string {
    $prefix = 'INV-' . ($firmId ? 'F' . $firmId : 'L' . $lawyerId) . '-' . date('Y') . '-';
    $scope = $firmId ? ['firm_id = ?', [$firmId]] : ['lawyer_id = ? AND firm_id IS NULL', [$lawyerId]];
    $stmt = $pdo->prepare("SELECT invoice_number FROM invoices WHERE {$scope[0]} AND invoice_number LIKE ? ORDER BY invoice_number DESC LIMIT 1");
    $stmt->execute(array_merge($scope[1], [$prefix . '%']));
    $last = (string)($stmt->fetchColumn() ?: '');
    $next = $last !== '' ? ((int)substr($last, strlen($prefix)) + 1) : 1;
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/** Normalize a raw item row submitted by a form/API into a validated line item, or null. */
function finance_normalize_item(array $raw): ?array {
    $desc = trim((string)($raw['description'] ?? ''));
    if ($desc === '' || mb_strlen($desc, 'UTF-8') > 255) return null;
    $qty = is_numeric($raw['quantity'] ?? null) ? (float)$raw['quantity'] : 1.0;
    $unit = is_numeric($raw['unit_price'] ?? null) ? (float)$raw['unit_price'] : 0.0;
    $discount = is_numeric($raw['discount_amount'] ?? null) ? (float)$raw['discount_amount'] : 0.0;
    $taxRate = is_numeric($raw['tax_rate'] ?? null) ? (float)$raw['tax_rate'] : 0.0;
    if (!is_finite($qty) || $qty <= 0 || $qty > 100000) return null;
    if (!is_finite($unit) || $unit < 0 || $unit > 99999999.99) return null;
    if (!is_finite($discount) || $discount < 0) return null;
    if (!is_finite($taxRate) || $taxRate < 0 || $taxRate > FINANCE_TAX_RATE_LIMIT) return null;
    $gross = finance_round($qty * $unit);
    if ($discount > $gross) return null;
    $net = finance_round($gross - $discount);
    $lineTotal = finance_round($net + ($net * $taxRate / 100));
    return [
        'description' => $desc, 'quantity' => $qty, 'unit_price' => $unit,
        'discount_amount' => finance_round($discount), 'tax_rate' => $taxRate, 'line_total' => $lineTotal,
    ];
}

/**
 * Recompute invoice subtotal/discount/tax/amount from its stored items.
 * Returns the new totals, or null when the invoice has no items (amount stays as-is).
 */
function finance_recalc_invoice_totals(PDO $pdo, int $invoiceId): ?array {
    $stmt = $pdo->prepare('SELECT quantity, unit_price, discount_amount, tax_rate FROM invoice_items WHERE invoice_id=?');
    $stmt->execute([$invoiceId]);
    $items = $stmt->fetchAll();
    if (!$items) return null;
    $subtotal = 0.0; $discount = 0.0; $tax = 0.0; $total = 0.0;
    foreach ($items as $item) {
        $gross = finance_round((float)$item['quantity'] * (float)$item['unit_price']);
        $disc = finance_round((float)$item['discount_amount']);
        $net = finance_round($gross - $disc);
        $subtotal += $gross; $discount += $disc; $tax += finance_round($net * (float)$item['tax_rate'] / 100);
        $total += finance_round($net + ($net * (float)$item['tax_rate'] / 100));
    }
    $totals = [
        'subtotal' => finance_round($subtotal), 'discount_total' => finance_round($discount),
        'tax_total' => finance_round($tax), 'amount' => finance_round($total),
    ];
    $pdo->prepare('UPDATE invoices SET subtotal=?,discount_total=?,tax_total=?,amount=? WHERE id=?')
        ->execute([$totals['subtotal'], $totals['discount_total'], $totals['tax_total'], $totals['amount'], $invoiceId]);
    return $totals;
}

/** Remaining balance on an invoice row (amount - amount_paid), floored at 0. */
function finance_invoice_balance(array $invoice): float {
    return max(0.0, finance_round((float)$invoice['amount'] - (float)($invoice['amount_paid'] ?? 0)));
}

/**
 * Record a payment against an invoice and roll the invoice status forward.
 *
 * @param array $invoice Invoice row already resolved THROUGH the tenant scope by the caller.
 * @return array{ok:bool, payment_id?:int, error?:string, duplicate?:bool, status?:string, balance?:float}
 *
 * Guarantees: amount must be > 0 and must not exceed the outstanding balance; the row is
 * locked for update so two concurrent calls cannot both pass the balance check; the unique
 * idempotency_key makes webhook retries and double-submits a no-op; the whole thing runs in
 * one transaction and is written to the audit log.
 */
function finance_record_payment(PDO $pdo, int $invoiceId, float $amount, array $options = []): array {
    $method = (string)($options['method'] ?? 'manual');
    $allowedMethods = ['manual','cash','bank_transfer','card','ngenius','other'];
    if (!in_array($method, $allowedMethods, true)) $method = 'manual';
    $reference = isset($options['reference']) ? mb_substr(trim((string)$options['reference']), 0, 120, 'UTF-8') : null;
    $notes = isset($options['notes']) ? mb_substr(trim((string)$options['notes']), 0, 500, 'UTF-8') : null;
    $idempotencyKey = isset($options['idempotency_key']) ? mb_substr(trim((string)$options['idempotency_key']), 0, 80, 'UTF-8') : null;
    if ($idempotencyKey === '') $idempotencyKey = null;
    $recordedByTypeRaw = $options['recorded_by_type'] ?? 'system';
    $recordedByType = in_array($recordedByTypeRaw, ['lawyer','admin','client','system'], true) ? $recordedByTypeRaw : 'system';
    $recordedById = isset($options['recorded_by_id']) ? (int)$options['recorded_by_id'] : null;
    $paidAt = !empty($options['paid_at']) && strtotime((string)$options['paid_at']) ? date('Y-m-d H:i:s', strtotime((string)$options['paid_at'])) : date('Y-m-d H:i:s');

    if (!is_finite($amount) || $amount <= 0) return ['ok' => false, 'error' => 'payment_amount_invalid'];
    $amount = finance_round($amount);

    // Idempotency: a retry of the same event returns the original payment untouched.
    if ($idempotencyKey !== null) {
        $dup = $pdo->prepare('SELECT id, invoice_id FROM invoice_payments WHERE idempotency_key=?');
        $dup->execute([$idempotencyKey]);
        $existing = $dup->fetch();
        if ($existing) {
            return ['ok' => true, 'payment_id' => (int)$existing['id'], 'duplicate' => true, 'invoice_id' => (int)$existing['invoice_id']];
        }
    }

    // Nest-safe transaction: when a caller already opened one (web request or test), use a
    // savepoint so we never commit or roll back work we do not own.
    $ownsTransaction = !$pdo->inTransaction();
    $savepoint = 'finance_pay_' . bin2hex(random_bytes(4));
    if ($ownsTransaction) $pdo->beginTransaction(); else $pdo->exec('SAVEPOINT ' . $savepoint);
    $release = function () use ($pdo, $ownsTransaction, $savepoint) {
        if ($ownsTransaction) { $pdo->commit(); } else { $pdo->exec('RELEASE SAVEPOINT ' . $savepoint); }
    };
    $undo = function () use ($pdo, $ownsTransaction, $savepoint) {
        if ($ownsTransaction) { if ($pdo->inTransaction()) $pdo->rollBack(); }
        else { $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint); }
    };
    try {
        $lock = $pdo->prepare('SELECT id, amount, amount_paid, status, is_draft, firm_id, lawyer_id, user_id, description, currency FROM invoices WHERE id=? FOR UPDATE');
        $lock->execute([$invoiceId]);
        $row = $lock->fetch();
        if (!$row) { $undo(); return ['ok' => false, 'error' => 'invoice_not_found']; }
        // Round to the invoice own currency, not the site default: an old BHD invoice
        // keeps its third decimal even after the site switches to a 2-decimal currency.
        $amount = finance_round_for($row, $amount);
        if ((int)$row['is_draft'] === 1) { $undo(); return ['ok' => false, 'error' => 'invoice_is_draft']; }
        if ($row['status'] === 'cancelled') { $undo(); return ['ok' => false, 'error' => 'invoice_cancelled']; }

        $balance = finance_invoice_balance($row);
        if ($balance <= 0) { $undo(); return ['ok' => false, 'error' => 'invoice_already_settled', 'balance' => 0.0]; }
        if ($amount > $balance + 0.0001) { $undo(); return ['ok' => false, 'error' => 'payment_exceeds_balance', 'balance' => $balance]; }

        $pdo->prepare('INSERT INTO invoice_payments (invoice_id,firm_id,lawyer_id,amount,currency,method,status,reference,idempotency_key,notes,paid_at,recorded_by_type,recorded_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$invoiceId, $row['firm_id'], $row['lawyer_id'], $amount, $row['currency'] ?? finance_currency(), $method, 'completed', $reference, $idempotencyKey, $notes, $paidAt, $recordedByType, $recordedById]);
        $paymentId = (int)$pdo->lastInsertId();

        $newPaid = finance_round((float)$row['amount_paid'] + $amount);
        $newBalance = max(0.0, finance_round((float)$row['amount'] - $newPaid));
        $newStatus = $newBalance <= 0 ? 'paid' : 'partially_paid';
        $pdo->prepare('UPDATE invoices SET amount_paid=?, status=?, paid_at=?, payment_method=?, payment_note=? WHERE id=?')
            ->execute([
                $newPaid, $newStatus, $newBalance <= 0 ? $paidAt : null, $method,
                $reference !== null ? mb_substr($reference, 0, 255, 'UTF-8') : mb_substr((string)$notes, 0, 255, 'UTF-8'),
                $invoiceId,
            ]);
        $release();

        log_action($pdo, 'تسجيل دفعة فاتورة (' . $method . ')', 'invoice_payment', $paymentId);
        return ['ok' => true, 'payment_id' => $paymentId, 'status' => $newStatus, 'balance' => $newBalance, 'invoice_id' => $invoiceId];
    } catch (Throwable $e) {
        $undo();
        // A unique-key collision means a concurrent identical event already landed.
        if ($idempotencyKey !== null && $e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062) {
            $dup = $pdo->prepare('SELECT id FROM invoice_payments WHERE idempotency_key=?');
            $dup->execute([$idempotencyKey]);
            $id = $dup->fetchColumn();
            if ($id) return ['ok' => true, 'payment_id' => (int)$id, 'duplicate' => true];
        }
        error_log('Payment recording failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'payment_failed'];
    }
}

/** Derived display status for an invoice row (adds overdue / draft without touching storage). */
function finance_display_status(array $invoice): string {
    if (!empty($invoice['is_draft'])) return 'draft';
    if (in_array($invoice['status'], ['unpaid', 'partially_paid'], true) && !empty($invoice['due_date']) && strtotime($invoice['due_date']) < time()) return 'overdue';
    return (string)$invoice['status'];
}

/**
 * Tenant financial summary. Caller passes the tenant scope SQL from tenant_owner_where().
 * @return array with invoiced/collected/outstanding/overdue/partially_paid totals.
 */
function finance_summary(PDO $pdo, array $scope): array {
    $sql = "SELECT
        COALESCE(SUM(CASE WHEN status <> 'cancelled' AND is_draft=0 THEN amount ELSE 0 END),0) AS invoiced,
        COALESCE(SUM(CASE WHEN status <> 'cancelled' AND is_draft=0 THEN amount_paid ELSE 0 END),0) AS collected,
        COALESCE(SUM(CASE WHEN status IN ('unpaid','partially_paid','overdue') AND is_draft=0 THEN amount - amount_paid ELSE 0 END),0) AS outstanding,
        COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) AS paid_invoices,
        COALESCE(SUM(CASE WHEN status='partially_paid' THEN amount - amount_paid ELSE 0 END),0) AS partially_paid_outstanding,
        COALESCE(SUM(CASE WHEN status IN ('unpaid','partially_paid','overdue') AND due_date IS NOT NULL AND due_date < CURDATE() AND is_draft=0 THEN amount - amount_paid ELSE 0 END),0) AS overdue
        FROM invoices i WHERE {$scope['sql']}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($scope['params']);
    $row = $stmt->fetch() ?: [];
    return array_map(static fn($v) => finance_round((float)$v), [
        'invoiced' => $row['invoiced'] ?? 0, 'collected' => $row['collected'] ?? 0,
        'outstanding' => $row['outstanding'] ?? 0, 'paid_invoices' => $row['paid_invoices'] ?? 0,
        'partially_paid_outstanding' => $row['partially_paid_outstanding'] ?? 0, 'overdue' => $row['overdue'] ?? 0,
    ]);
}

/** Per-client receivables for the tenant (client, invoiced, paid, outstanding). */
function finance_client_balances(PDO $pdo, array $scope, int $limit = 200): array {
    $sql = "SELECT u.id, u.full_name, COUNT(i.id) AS invoice_count,
        COALESCE(SUM(i.amount),0) AS invoiced, COALESCE(SUM(i.amount_paid),0) AS paid,
        COALESCE(SUM(i.amount - i.amount_paid),0) AS outstanding
        FROM invoices i JOIN users u ON u.id=i.user_id
        WHERE {$scope['sql']} AND i.status <> 'cancelled' AND i.is_draft=0
        GROUP BY u.id, u.full_name ORDER BY outstanding DESC LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($scope['params']);
    return array_map(static function ($r) {
        $r['invoiced'] = finance_round((float)$r['invoiced']);
        $r['paid'] = finance_round((float)$r['paid']);
        $r['outstanding'] = finance_round((float)$r['outstanding']);
        return $r;
    }, $stmt->fetchAll());
}

/** Payment method breakdown for the tenant within an optional date range. */
function finance_payment_methods(PDO $pdo, array $scope, ?string $from = null, ?string $to = null): array {
    $sql = "SELECT p.method, COUNT(*) AS payment_count, COALESCE(SUM(p.amount),0) AS total
        FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id
        WHERE {$scope['sql']} AND p.status='completed'";
    $params = $scope['params'];
    if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $sql .= ' AND p.paid_at >= ?'; $params[] = $from . ' 00:00:00'; }
    if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $sql .= ' AND p.paid_at <= ?'; $params[] = $to . ' 23:59:59'; }
    $sql .= ' GROUP BY p.method ORDER BY total DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map(static fn($r) => ['method' => $r['method'], 'payment_count' => (int)$r['payment_count'], 'total' => finance_round((float)$r['total'])], $stmt->fetchAll());
}

/** Per-case financial summary for the tenant (billed, collected, outstanding). */
function finance_case_summary(PDO $pdo, array $scope, int $limit = 200): array {
    $sql = "SELECT c.id, c.title, u.full_name AS client_name,
        COALESCE(SUM(i.amount),0) AS billed, COALESCE(SUM(i.amount_paid),0) AS collected,
        COALESCE(SUM(i.amount - i.amount_paid),0) AS outstanding
        FROM invoices i JOIN cases c ON c.id=i.case_id JOIN users u ON u.id=i.user_id
        WHERE {$scope['sql']} AND i.status <> 'cancelled' AND i.is_draft=0
        GROUP BY c.id, c.title, u.full_name ORDER BY outstanding DESC LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($scope['params']);
    return array_map(static function ($r) {
        $r['billed'] = finance_round((float)$r['billed']);
        $r['collected'] = finance_round((float)$r['collected']);
        $r['outstanding'] = finance_round((float)$r['outstanding']);
        return $r;
    }, $stmt->fetchAll());
}

/** Itemized statement for a single invoice the caller has already tenant-checked. */
function finance_invoice_items(PDO $pdo, int $invoiceId): array {
    $stmt = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order, id');
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll();
}

/** Completed payments for a single invoice (for the client-facing statement). */
function finance_invoice_payments(PDO $pdo, int $invoiceId): array {
    $stmt = $pdo->prepare("SELECT * FROM invoice_payments WHERE invoice_id=? AND status='completed' ORDER BY paid_at, id");
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll();
}
