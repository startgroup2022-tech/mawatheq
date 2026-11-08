<?php
/**
 * سجل العمليات — the customer's transaction history (Execution 65).
 *
 * The account screen needs one list of everything the customer paid for, but the
 * platform records those in four different tables for good reasons:
 *
 *   * consultations      — a booked consultation, paid or free (payment_status)
 *   * package_orders     — a plan/subscription purchase
 *   * invoices           — a lawyer's invoice to the client
 *   * invoice_payments   — a payment made against an invoice
 *
 * Rather than duplicate the rows into a new ledger (which would immediately drift),
 * this module reads the four tables, normalises each into one transaction shape, and
 * returns them merged and sorted. Every read is scoped by the authenticated user id,
 * so the list can only ever contain the caller's own rows.
 *
 * Each transaction is:
 *   type       consultation | package | invoice | invoice_payment | refund
 *   date       ISO-ish string, newest first
 *   amount     numeric (2-decimal string from the currency helpers where available)
 *   currency   ISO code
 *   reference  the gateway/invoice reference when one exists, else a local id
 *   status     paid | pending | failed | refunded | unpaid | cancelled
 *   title      a human label for the row
 *   link       an in-app/web target the client may open (invoice detail, consultation)
 */
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/finance.php';

/** The transaction types the history can be filtered to. */
function transactions_types(): array {
    return ['consultation', 'package', 'invoice', 'invoice_payment', 'refund'];
}

/** The statuses the history can be filtered to. */
function transactions_statuses(): array {
    return ['paid', 'pending', 'failed', 'refunded', 'unpaid', 'cancelled'];
}

/** Normalise a raw status into the small vocabulary the client filters on. */
function transactions_normalize_status(string $raw): string {
    $raw = strtolower(trim($raw));
    return match ($raw) {
        'paid', 'completed', 'succeeded', 'captured', 'active' => 'paid',
        'pending', 'processing', 'unpaid', 'awaiting_payment' => $raw === 'unpaid' ? 'unpaid' : 'pending',
        'failed', 'declined', 'error' => 'failed',
        'refunded', 'reversed' => 'refunded',
        'cancelled', 'canceled' => 'cancelled',
        'partially_paid' => 'unpaid',
        default => $raw === '' ? 'pending' : $raw,
    };
}

/** Format an amount through the currency helper, falling back to a plain string. */
function transactions_amount($value, string $currency): string {
    if ($value === null || $value === '') return '0';
    $numeric = (float)$value;
    if (function_exists('money_text')) {
        try {
            return money_text($numeric, $currency);
        } catch (Throwable $e) {
            // Fall through to a plain format below.
        }
    }
    return number_format($numeric, 2, '.', '');
}

/**
 * The customer's full transaction history, newest first.
 *
 * @param array{type?:string,status?:string,from?:string,to?:string,limit?:int} $filters
 * @return array<int,array<string,mixed>>
 */
function transactions_for_client(PDO $pdo, int $userId, array $filters = []): array {
    if ($userId <= 0) return [];
    $rows = array_merge(
        transactions_from_consultations($pdo, $userId),
        transactions_from_packages($pdo, $userId),
        transactions_from_invoices($pdo, $userId),
        transactions_from_invoice_payments($pdo, $userId),
    );

    $type = (string)($filters['type'] ?? '');
    $status = (string)($filters['status'] ?? '');
    $from = (string)($filters['from'] ?? '');
    $to = (string)($filters['to'] ?? '');

    $rows = array_values(array_filter($rows, function (array $row) use ($type, $status, $from, $to) {
        if ($type !== '' && $type !== 'all') {
            // A refund is a status view as much as a type; a refunded consultation is
            // still a consultation, so the 'refund' filter also matches status=refunded.
            if ($type === 'refund') {
                if ($row['status'] !== 'refunded') return false;
            } elseif ($row['type'] !== $type) {
                return false;
            }
        }
        if ($status !== '' && $status !== 'all' && $row['status'] !== $status) return false;
        if ($from !== '' && $row['date'] !== '' && substr($row['date'], 0, 10) < $from) return false;
        if ($to !== '' && $row['date'] !== '' && substr($row['date'], 0, 10) > $to) return false;
        return true;
    }));

    usort($rows, fn($a, $b) => strcmp((string)$b['date'], (string)$a['date']));

    $limit = (int)($filters['limit'] ?? 200);
    if ($limit > 0 && count($rows) > $limit) $rows = array_slice($rows, 0, $limit);
    return $rows;
}

/** Consultation payments (free consultations have no amount and are skipped). */
function transactions_from_consultations(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT c.id, c.topic, c.price, c.payment_status, c.payment_amount,
            c.payment_currency, c.payment_reference, c.scheduled_at, c.created_at,
            l.full_name AS lawyer_name
        FROM consultations c
        JOIN lawyers l ON l.id = c.lawyer_id
        WHERE c.user_id = ? AND (c.payment_amount IS NOT NULL OR c.payment_reference IS NOT NULL)")
    ;
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $currency = $row['payment_currency'] ?: (function_exists('currency_code') ? currency_code() : 'SAR');
        $amount = $row['payment_amount'] ?? $row['price'];
        $out[] = [
            'type' => 'consultation',
            'id' => (int)$row['id'],
            'date' => (string)($row['created_at'] ?: $row['scheduled_at']),
            'amount' => transactions_amount($amount, $currency),
            'amount_value' => (float)($amount ?? 0),
            'currency' => $currency,
            'reference' => (string)($row['payment_reference'] ?? ''),
            'status' => transactions_normalize_status((string)$row['payment_status']),
            'title' => $row['topic'] ?: 'استشارة',
            'subtitle' => $row['lawyer_name'] ?? '',
            'link' => 'consultation.php?id=' . (int)$row['id'],
        ];
    }
    return $out;
}

/** Package / subscription purchases. */
function transactions_from_packages(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT o.id, o.amount, o.currency, o.status, o.payment_reference,
            o.billing_cycle, o.created_at, o.paid_at, p.name_ar, p.name_en
        FROM package_orders o
        LEFT JOIN plans p ON p.id = o.plan_id
        WHERE o.user_id = ?
        ORDER BY o.created_at DESC");
    $stmt->execute([$userId]);
    $rtl = function_exists('is_rtl') ? is_rtl() : true;
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $currency = $row['currency'] ?: 'SAR';
        $planName = $rtl ? ($row['name_ar'] ?? '') : ($row['name_en'] ?? ($row['name_ar'] ?? ''));
        $cycle = $row['billing_cycle'] === 'yearly' ? 'سنوي' : 'شهري';
        $out[] = [
            'type' => 'package',
            'id' => (int)$row['id'],
            'date' => (string)($row['paid_at'] ?: $row['created_at']),
            'amount' => transactions_amount($row['amount'], $currency),
            'amount_value' => (float)$row['amount'],
            'currency' => $currency,
            'reference' => (string)($row['payment_reference'] ?? ''),
            'status' => transactions_normalize_status((string)$row['status']),
            'title' => trim(($planName !== '' ? $planName : 'باقة') . ' · ' . $cycle),
            'subtitle' => '',
            'link' => '',
        ];
    }
    return $out;
}

/** Invoices raised against the client. */
function transactions_from_invoices(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT i.id, i.invoice_number, i.description, i.amount AS total, i.currency,
            i.status, i.issue_date AS issued_at, i.created_at, i.lawyer_id,
            l.full_name AS lawyer_name
        FROM invoices i
        LEFT JOIN lawyers l ON l.id = i.lawyer_id
        WHERE i.user_id = ? AND i.is_draft = 0
        ORDER BY i.created_at DESC");
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $currency = $row['currency'] ?: 'SAR';
        $display = function_exists('finance_display_status') ? finance_display_status($row) : (string)$row['status'];
        $out[] = [
            'type' => 'invoice',
            'id' => (int)$row['id'],
            'date' => (string)($row['issued_at'] ?: $row['created_at']),
            'amount' => transactions_amount($row['total'], $currency),
            'amount_value' => (float)$row['total'],
            'currency' => $currency,
            'reference' => (string)($row['invoice_number'] ?? ''),
            'status' => transactions_normalize_status((string)$display),
            'title' => $row['description'] ?: ('فاتورة #' . (int)$row['id']),
            'subtitle' => $row['lawyer_name'] ?? '',
            'link' => 'invoices.php',
        ];
    }
    return $out;
}

/** Individual payments recorded against the client's invoices. */
function transactions_from_invoice_payments(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT p.id, p.invoice_id, p.amount, p.currency, p.method, p.paid_at,
            p.status, p.reference, i.invoice_number
        FROM invoice_payments p
        JOIN invoices i ON i.id = p.invoice_id
        WHERE i.user_id = ? AND i.is_draft = 0
        ORDER BY p.paid_at DESC");
    $stmt->execute([$userId]);
    $rtl = function_exists('is_rtl') ? is_rtl() : true;
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $currency = $row['currency'] ?: 'SAR';
        $method = (string)($row['method'] ?? '');
        $out[] = [
            'type' => 'invoice_payment',
            'id' => (int)$row['id'],
            'date' => (string)$row['paid_at'],
            'amount' => transactions_amount($row['amount'], $currency),
            'amount_value' => (float)$row['amount'],
            'currency' => $currency,
            'reference' => (string)($row['reference'] ?? $row['invoice_number'] ?? ''),
            'status' => transactions_normalize_status((string)($row['status'] ?? 'completed')),
            'title' => ($rtl ? 'دفعة على الفاتورة ' : 'Payment on invoice ') . (string)($row['invoice_number'] ?? ('#' . (int)$row['invoice_id'])),
            'subtitle' => $method,
            'link' => 'invoices.php',
        ];
    }
    return $out;
}

/**
 * A single transaction, scoped to its owner, for the detail sheet.
 *
 * Returns null when the id does not belong to the caller, so a guessed id leaks
 * nothing. The type selects which table is read.
 */
function transaction_detail(PDO $pdo, int $userId, string $type, int $id): ?array {
    if ($userId <= 0 || $id <= 0) return null;
    $all = transactions_for_client($pdo, $userId);
    foreach ($all as $row) {
        if ($row['type'] === $type && (int)$row['id'] === $id) return $row;
    }
    return null;
}

/** Aggregate totals for the history header, in the client's own currencies. */
function transactions_summary(PDO $pdo, int $userId): array {
    $rows = transactions_for_client($pdo, $userId);
    $paid = 0.0;
    $pending = 0.0;
    $refunded = 0.0;
    $currency = function_exists('currency_code') ? currency_code() : 'SAR';
    foreach ($rows as $row) {
        if ($row['status'] === 'paid') $paid += $row['amount_value'];
        elseif ($row['status'] === 'refunded') $refunded += $row['amount_value'];
        elseif (in_array($row['status'], ['pending', 'unpaid'], true)) $pending += $row['amount_value'];
    }
    return [
        'count' => count($rows),
        'paid' => transactions_amount($paid, $currency),
        'pending' => transactions_amount($pending, $currency),
        'refunded' => transactions_amount($refunded, $currency),
        'currency' => $currency,
    ];
}
