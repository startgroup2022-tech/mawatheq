<?php
/**
 * GET /api/v1/finance.php → ملخص مالي للمحامي/المكتب ضمن نطاق المستأجر الحالي فقط.
 * response: { ok, summary, receivables, by_case, by_method }
 *
 * The scope is resolved from the authenticated token's lawyer tenant — never from a
 * request parameter — so one tenant can never read another tenant's numbers.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/tenant.php';
require_once __DIR__ . '/../../includes/finance.php';

$auth = api_authenticate($pdo);
if ($auth['type'] !== 'lawyer') api_error('only lawyers can read the finance summary', 403);

$scope = tenant_owner_where_for_lawyer($pdo, $auth['id'], 'i');

$from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['from']) ? (string)$_GET['from'] : null;
$to = isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['to']) ? (string)$_GET['to'] : null;

api_json([
    'ok' => true,
    'summary' => finance_summary($pdo, $scope),
    'receivables' => finance_client_balances($pdo, $scope, 100),
    'by_case' => finance_case_summary($pdo, $scope, 100),
    'by_method' => finance_payment_methods($pdo, $scope, $from, $to),
]);
