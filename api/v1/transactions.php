<?php
/**
 * GET /api/v1/transactions.php — سجل عمليات العميل (يحتاج توكن)
 *
 * The single source for the account screen's transaction history. It merges
 * consultation payments, package purchases, invoices and invoice payments into one
 * list (see includes/transactions.php) so the app does not have to call four
 * endpoints and stitch the results together — which is how the four views drift.
 *
 *   ?type=consultation|package|invoice|invoice_payment|refund|all
 *   ?status=paid|pending|failed|refunded|unpaid|cancelled|all
 *   ?from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * A lawyer token is refused: this endpoint is the customer's own ledger. A lawyer's
 * earnings live on the wallet endpoints.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/transactions.php';

$auth = api_authenticate($pdo);
if ($auth['type'] !== 'client') {
    api_error('هذه الواجهة مخصّصة لحساب العميل.', 403);
}

$filters = [
    'type'   => (string)($_GET['type'] ?? 'all'),
    'status' => (string)($_GET['status'] ?? 'all'),
    'from'   => (string)($_GET['from'] ?? ''),
    'to'     => (string)($_GET['to'] ?? ''),
    'limit'  => (int)($_GET['limit'] ?? 200),
];

$rows = transactions_for_client($pdo, (int)$auth['id'], $filters);

api_json([
    'ok'           => true,
    'transactions' => $rows,
    'summary'      => transactions_summary($pdo, (int)$auth['id']),
    'types'        => transactions_types(),
    'statuses'     => transactions_statuses(),
]);
