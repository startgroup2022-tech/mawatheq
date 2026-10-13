<?php
/**
 * Phase 3 finance test — exercises the real finance core (includes/finance.php) against the
 * configured database. Covers tenant isolation of financial data, itemization/totals, partial
 * payments, the balance invariant, idempotent payment recording, and the payment lifecycle.
 *
 * Run: php tests/phase3_finance_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/finance.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(4));
    $pwd = password_hash('Test-Password-123', PASSWORD_DEFAULT);

    $mkLawyer = function (string $name, string $email) use ($pdo, $pwd): int {
        $pdo->prepare('INSERT INTO lawyers (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,\'approved\',1)')->execute([$name, $email, $pwd]);
        return (int)$pdo->lastInsertId();
    };
    $mkClient = function (string $name, string $email) use ($pdo, $pwd): int {
        $pdo->prepare('INSERT INTO users (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,\'active\',1)')->execute([$name, $email, $pwd]);
        return (int)$pdo->lastInsertId();
    };

    $ownerA = $mkLawyer("Fin Owner A $suffix", "fin-owner-a-$suffix@test.local");
    $lawyerA2 = $mkLawyer("Fin Lawyer A2 $suffix", "fin-a2-$suffix@test.local");
    $ownerB = $mkLawyer("Fin Owner B $suffix", "fin-owner-b-$suffix@test.local");
    $solo = $mkLawyer("Fin Solo $suffix", "fin-solo-$suffix@test.local");
    $clientA = $mkClient("Fin Client A $suffix", "fin-client-a-$suffix@test.local");
    $clientB = $mkClient("Fin Client B $suffix", "fin-client-b-$suffix@test.local");

    $pdo->prepare('INSERT INTO law_firms (name,owner_lawyer_id) VALUES (?,?)')->execute(["Fin Firm A $suffix", $ownerA]); $firmA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO law_firms (name,owner_lawyer_id) VALUES (?,?)')->execute(["Fin Firm B $suffix", $ownerB]); $firmB = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role) VALUES (?,?,'owner')")->execute([$firmA, $ownerA]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role) VALUES (?,?,'lawyer')")->execute([$firmA, $lawyerA2]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role) VALUES (?,?,'owner')")->execute([$firmB, $ownerB]);

    echo "== invoice numbering is unique per tenant ==\n";
    $n1 = finance_next_invoice_number($pdo, $firmA, $ownerA);
    $n2 = finance_next_invoice_number($pdo, $firmB, $ownerB);
    $nSolo = finance_next_invoice_number($pdo, null, $solo);
    check('firm A prefix is tenant-scoped', strpos($n1, 'INV-F' . $firmA . '-') === 0);
    check('firm B prefix differs from firm A', strpos($n2, 'INV-F' . $firmB . '-') === 0 && $n2 !== $n1);
    check('solo prefix uses lawyer id', strpos($nSolo, 'INV-L' . $solo . '-') === 0);

    echo "== itemization and totals ==\n";
    $pdo->prepare('INSERT INTO invoices (invoice_number,lawyer_id,created_by_lawyer_id,firm_id,user_id,description,amount,currency,status,is_draft,issue_date) VALUES (?,?,?,?,?,?,?,?,?,0,CURDATE())')
        ->execute([$n1, $ownerA, $ownerA, $firmA, $clientA, 'Itemized invoice', 0, 'SAR', 'unpaid']); $invA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO invoice_items (invoice_id,firm_id,description,quantity,unit_price,discount_amount,tax_rate,line_total,sort_order) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$invA, $firmA, 'Consultation', 2, 100.000, 50.000, 10.000, 165.000, 1]);
    $pdo->prepare('INSERT INTO invoice_items (invoice_id,firm_id,description,quantity,unit_price,discount_amount,tax_rate,line_total,sort_order) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$invA, $firmA, 'Drafting', 1, 200.000, 0, 0, 200.000, 2]);
    $totals = finance_recalc_invoice_totals($pdo, $invA);
    check('subtotal sums line gross', abs($totals['subtotal'] - 400.0) < 0.001);
    check('discount total sums line discounts', abs($totals['discount_total'] - 50.0) < 0.001);
    check('tax total applies per-line tax', abs($totals['tax_total'] - 15.0) < 0.001);
    check('grand total equals sum of line totals', abs($totals['amount'] - 365.0) < 0.001);

    echo "== partial then full payment rolls the lifecycle ==\n";
    $invRow = $pdo->query("SELECT * FROM invoices WHERE id=$invA")->fetch();
    check('new invoice starts unpaid with zero paid', $invRow['status'] === 'unpaid' && (float)$invRow['amount_paid'] === 0.0);
    check('balance equals amount before payments', abs(finance_invoice_balance($invRow) - 365.0) < 0.001);

    $p1 = finance_record_payment($pdo, $invA, 165.0, ['method' => 'cash', 'recorded_by_type' => 'lawyer', 'recorded_by_id' => $ownerA]);
    check('partial payment is recorded', $p1['ok'] === true);
    $invRow = $pdo->query("SELECT * FROM invoices WHERE id=$invA")->fetch();
    check('status becomes partially_paid', $invRow['status'] === 'partially_paid');
    check('amount_paid accumulates', abs((float)$invRow['amount_paid'] - 165.0) < 0.001);
    check('balance reflects the remainder', abs(finance_invoice_balance($invRow) - 200.0) < 0.001);

    $overpay = finance_record_payment($pdo, $invA, 500.0, ['method' => 'cash']);
    check('overpayment is rejected', $overpay['ok'] === false && $overpay['error'] === 'payment_exceeds_balance');

    $p2 = finance_record_payment($pdo, $invA, 200.0, ['method' => 'bank_transfer']);
    check('settling payment is recorded', $p2['ok'] === true);
    $invRow = $pdo->query("SELECT * FROM invoices WHERE id=$invA")->fetch();
    check('status becomes paid when balance reaches zero', $invRow['status'] === 'paid');
    check('paid_at is set on settlement', !empty($invRow['paid_at']));
    check('balance is zero after settlement', finance_invoice_balance($invRow) === 0.0);

    $extra = finance_record_payment($pdo, $invA, 10.0, ['method' => 'cash']);
    check('payment after settlement is rejected', $extra['ok'] === false);

    echo "== idempotency key makes retries a no-op ==\n";
    $pdo->prepare('INSERT INTO invoices (invoice_number,lawyer_id,created_by_lawyer_id,firm_id,user_id,description,amount,currency,status,is_draft,issue_date) VALUES (?,?,?,?,?,?,?,?,?,0,CURDATE())')
        ->execute([$n2, $ownerB, $ownerB, $firmB, $clientB, 'Webhook invoice', 100.0, 'SAR', 'unpaid']); $invB = (int)$pdo->lastInsertId();
    $r1 = finance_record_payment($pdo, $invB, 100.0, ['method' => 'ngenius', 'idempotency_key' => 'ngenius:TEST-' . $suffix, 'reference' => 'TEST-' . $suffix]);
    $r2 = finance_record_payment($pdo, $invB, 100.0, ['method' => 'ngenius', 'idempotency_key' => 'ngenius:TEST-' . $suffix, 'reference' => 'TEST-' . $suffix]);
    check('first webhook payment recorded', $r1['ok'] === true && empty($r1['duplicate']));
    check('second webhook payment is a duplicate no-op', $r2['ok'] === true && !empty($r2['duplicate']));
    check('only one payment row exists for the idempotency key', (int)$pdo->query("SELECT COUNT(*) FROM invoice_payments WHERE idempotency_key='ngenius:TEST-$suffix'")->fetchColumn() === 1);
    $invBRow = $pdo->query("SELECT * FROM invoices WHERE id=$invB")->fetch();
    check('duplicate did not double-count amount_paid', abs((float)$invBRow['amount_paid'] - 100.0) < 0.001);

    echo "== tenant isolation of financial data ==\n";
    $scopeA = tenant_owner_where_for_lawyer($pdo, $ownerA, 'i');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices i WHERE ' . $scopeA['sql']);
    $stmt->execute($scopeA['params']);
    $firmACount = (int)$stmt->fetchColumn();
    $scopeB = tenant_owner_where_for_lawyer($pdo, $ownerB, 'i');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices i WHERE ' . $scopeB['sql']);
    $stmt->execute($scopeB['params']);
    $firmBCount = (int)$stmt->fetchColumn();
    check('firm A scope sees the firm A invoice', $firmACount === 1);
    check('firm B scope sees only the firm B invoice', $firmBCount === 1);
    $cross = $pdo->prepare("SELECT COUNT(*) FROM invoices i WHERE i.id=$invB AND " . $scopeA['sql']);
    $cross->execute($scopeA['params']);
    check('firm A cannot see firm B invoice id', (int)$cross->fetchColumn() === 0);

    $summaryA = finance_summary($pdo, $scopeA);
    check('firm A summary invoiced total is 365', abs($summaryA['invoiced'] - 365.0) < 0.001);
    check('firm A summary collected total is 365', abs($summaryA['collected'] - 365.0) < 0.001);
    check('firm A outstanding is zero', abs($summaryA['outstanding']) < 0.001);
    $summaryB = finance_summary($pdo, $scopeB);
    check('firm B summary is independent', abs($summaryB['invoiced'] - 100.0) < 0.001);

    echo "== solo tenant is its own scope ==\n";
    $soloScope = tenant_owner_where_for_lawyer($pdo, $solo, 'i');
    check('solo scope keys on lawyer_id', strpos($soloScope['sql'], 'lawyer_id') !== false && $soloScope['params'] === [$solo]);

    echo "== receivables and method breakdown ==\n";
    $pdo->prepare('INSERT INTO invoices (invoice_number,lawyer_id,created_by_lawyer_id,firm_id,user_id,description,amount,currency,status,is_draft,issue_date,due_date) VALUES (?,?,?,?,?,?,?,?,?,0,CURDATE(),DATE_SUB(CURDATE(),INTERVAL 30 DAY))')
        ->execute([finance_next_invoice_number($pdo, $firmA, $ownerA), $ownerA, $ownerA, $firmA, $clientA, 'Overdue invoice', 50.0, 'SAR', 'unpaid']); $invOverdue = (int)$pdo->lastInsertId();
    $receivables = finance_client_balances($pdo, $scopeA);
    $found = null; foreach ($receivables as $r) if ((int)$r['id'] === $clientA) $found = $r;
    check('client appears in receivables', $found !== null);
    check('receivables outstanding includes the overdue invoice', $found && abs($found['outstanding'] - 50.0) < 0.001);
    $summaryA2 = finance_summary($pdo, $scopeA);
    check('overdue total is reported separately', abs($summaryA2['overdue'] - 50.0) < 0.001);
    $methods = finance_payment_methods($pdo, $scopeA);
    check('payment method breakdown lists recorded methods', count($methods) >= 2);

    echo "== invoice items and payments read back ==\n";
    $items = finance_invoice_items($pdo, $invA);
    check('two line items stored', count($items) === 2);
    $payments = finance_invoice_payments($pdo, $invA);
    check('two completed payments stored', count($payments) === 2);

    echo "== validation guards ==\n";
    check('rejects zero-quantity item', finance_normalize_item(['description' => 'x', 'quantity' => 0, 'unit_price' => 10]) === null);
    check('rejects discount above gross', finance_normalize_item(['description' => 'x', 'quantity' => 1, 'unit_price' => 10, 'discount_amount' => 20]) === null);
    check('rejects tax above 100%', finance_normalize_item(['description' => 'x', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 150]) === null);
    $valid = finance_normalize_item(['description' => 'ok', 'quantity' => 3, 'unit_price' => 100, 'discount_amount' => 50, 'tax_rate' => 5]);
    check('normalizes a valid item to the expected line total', $valid !== null && abs($valid['line_total'] - 262.5) < 0.001);
    check('display status derives overdue', finance_display_status(['status' => 'unpaid', 'is_draft' => 0, 'due_date' => date('Y-m-d', time() - 86400)]) === 'overdue');
    check('display status keeps drafts', finance_display_status(['status' => 'unpaid', 'is_draft' => 1, 'due_date' => null]) === 'draft');
} finally {
    $pdo->rollBack();
}

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
