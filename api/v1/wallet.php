<?php
/**
 * ============================================================
 * /api/v1/wallet.php — محفظة المحامي (Execution 57)
 * MAWATHEQ — the lawyer's wallet, for the mobile app
 *
 * GET  ?action=summary     → balances, lifetime totals, payout details (masked)
 * GET  ?action=statement&from=&to=   → the ledger for a period
 * GET  ?action=transactions&limit=&offset=  → the raw ledger, newest first
 * GET  ?action=withdrawals → the withdrawal history and its states
 * GET  ?action=payout      → the payout account on file (masked)
 * POST action=request-withdrawal { amount, method?, note? }
 * POST action=save-payout { method, holder?, details... }
 *
 * Every figure comes from `includes/wallet.php`, the same ledger the web console
 * reads, so the app cannot show a balance the console disagrees with. A withdrawal
 * is a request: it debits nothing until an admin approves it, which is the same
 * two-step rule the console enforces through `wallet_withdrawal_transition()`.
 * ============================================================
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/wallet.php';

$auth = api_authenticate($pdo);
if ($auth['type'] !== 'lawyer') api_error('this endpoint is for lawyers', 403);

$lawyerId = (int)$auth['id'];
$wallet = wallet_ensure($pdo, $lawyerId);
$currency = (string)$wallet['currency'];

$method = $_SERVER['REQUEST_METHOD'];
$in = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    $in = is_array($json) ? $json : $_POST;
}
$action = (string)($_GET['action'] ?? ($in['action'] ?? ''));

if ($method === 'GET') {
    switch ($action) {
        case '':
        case 'summary':
            $balances = wallet_balances($pdo, (int)$wallet['id'], $currency);
            $totals = wallet_totals($pdo, (int)$wallet['id']);
            // The reserved amount is the sum of pending requests — money the lawyer has
            // asked for but which is not yet approved. Showing it separately is what
            // makes "available" honest: it is already net of nothing, so the app can
            // show available + reserved as the true position.
            $reservedStmt = $pdo->prepare("SELECT COALESCE(SUM(amount_minor),0) FROM wallet_withdrawals WHERE wallet_id=? AND status='pending'");
            $reservedStmt->execute([(int)$wallet['id']]);
            $reserved = (int)$reservedStmt->fetchColumn();
            api_json([
                'ok' => true,
                'currency' => $currency,
                'status' => $wallet['status'],
                'available_minor' => $balances['available_minor'],
                'pending_minor' => $balances['pending_minor'],
                'reserved_minor' => $reserved,
                'available' => wallet_from_minor($balances['available_minor'], $currency),
                'pending' => wallet_from_minor($balances['pending_minor'], $currency),
                'reserved' => wallet_from_minor($reserved, $currency),
                'gross_earned' => wallet_from_minor($totals['gross_earned_minor'], $currency),
                'net_earned' => wallet_from_minor($totals['net_earned_minor'], $currency),
                'commission' => wallet_from_minor($totals['commission_minor'], $currency),
                'withdrawn' => wallet_from_minor($totals['withdrawn_minor'], $currency),
                'payout_details' => wallet_mask_payout_details(wallet_payout_details($pdo, $wallet)),
            ]);
            // no break

        case 'statement':
            $from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['from']) ? (string)$_GET['from'] : null;
            $to = isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['to']) ? (string)$_GET['to'] : null;
            $rows = wallet_statement($pdo, (int)$wallet['id'], $from, $to);
            api_json(['ok' => true, 'currency' => $currency, 'statement' => $rows, 'from' => $from, 'to' => $to]);
            // no break

        case 'transactions':
            $limit = (int)($_GET['limit'] ?? 50);
            $offset = (int)($_GET['offset'] ?? 0);
            api_json(['ok' => true, 'currency' => $currency, 'transactions' => wallet_transactions($pdo, (int)$wallet['id'], $limit, $offset)]);
            // no break

        case 'withdrawals':
            api_json(['ok' => true, 'currency' => $currency, 'withdrawals' => wallet_withdrawals($pdo, (int)$wallet['id'])]);
            // no break

        case 'payout':
            api_json(['ok' => true, 'payout' => wallet_payout_details($pdo, $wallet), 'masked' => wallet_mask_payout_details(wallet_payout_details($pdo, $wallet))]);
            // no break

        default:
            api_error('unknown action', 400);
    }
}

if ($method === 'POST') {
    switch ($action) {
        case 'request-withdrawal': {
            $amount = (string)($in['amount'] ?? '');
            // The amount arrives as a human string ("1500.50") and is converted with the
            // currency's own precision. Parsing it as a float here would reintroduce the
            // rounding the ledger exists to avoid.
            if (!preg_match('/^\d+(\.\d{1,3})?$/', $amount)) {
                api_error('المبلغ غير صالح.');
            }
            $minor = wallet_to_minor($amount, $currency);
            if ($minor <= 0) api_error('المبلغ يجب أن يكون أكبر من صفر.');

            $options = [];
            if (isset($in['method'])) $options['method'] = (string)$in['method'];
            if (isset($in['note'])) $options['note'] = (string)$in['note'];

            $result = wallet_request_withdrawal($pdo, $lawyerId, $minor, $options);
            if (!$result['ok']) {
                $map = [
                    'amount_invalid' => ['المبلغ غير صالح.', 422],
                    'wallet_not_active' => ['المحفظة غير مفعّلة.', 409],
                    'insufficient_balance' => ['الرصيد المتاح غير كافٍ.', 409],
                    'wallet_not_found' => ['المحفظة غير موجودة.', 404],
                ];
                [$msg, $code] = $map[$result['error']] ?? ['تعذّر إنشاء الطلب.', 400];
                api_json(['ok' => false, 'error' => $msg, 'code' => $result['error'], 'available_minor' => $result['available_minor'] ?? null], $code);
            }
            api_json(['ok' => true, 'withdrawal_id' => $result['id'], 'available_minor' => $result['available_minor']], 201);
        }

        case 'save-payout': {
            $methodName = (string)($in['method'] ?? '');
            if (!in_array($methodName, ['bank_transfer', 'cash', 'other'], true)) api_error('طريقة التحويل غير صالحة.');
            $details = is_array($in['details'] ?? null) ? $in['details'] : [];
            // Only these keys are stored; anything else a client sends is dropped rather
            // than written into the encrypted blob.
            $allowed = ['bank_name', 'account_name', 'iban', 'account_number', 'swift', 'notes'];
            $clean = [];
            foreach ($allowed as $k) {
                if (isset($details[$k]) && is_scalar($details[$k])) {
                    $clean[$k] = mb_substr(trim((string)$details[$k]), 0, 120, 'UTF-8');
                }
            }
            $holder = isset($in['holder']) && is_scalar($in['holder']) ? mb_substr(trim((string)$in['holder']), 0, 120, 'UTF-8') : null;
            $ok = wallet_store_payout_details($pdo, (int)$wallet['id'], $clean, $methodName, $holder);
            if (!$ok) api_error('تعذّر حفظ بيانات الحساب.', 500);
            api_json(['ok' => true, 'masked' => wallet_mask_payout_details(wallet_payout_details($pdo, wallet_ensure($pdo, $lawyerId)))]);
        }

        default:
            api_error('unknown action', 400);
    }
}

api_error('method not allowed', 405);
