<?php
/**
 * A paid package must stop when its paid term ends.
 *
 * `package_payment_activate()` grants the plan by writing `users.plan_id`; nothing ever read
 * `subscriptions.ends_at`, so a monthly subscription stayed in force forever and every paid
 * tier was effectively permanent. This suite proves the sweep ends the due terms, reverts the
 * entitlement, and leaves the cases it must not touch alone.
 *
 * Run: php tests/exec69_subscription_expiry_test.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/plan_limits.php';
require_once __DIR__ . '/../includes/package_payment.php';

$checks = 0; $fails = 0;
function check(string $label, bool $ok, string $extra = ''): void {
    global $checks, $fails;
    $checks++;
    if ($ok) echo "  ok:   $label\n";
    else { $fails++; echo "  FAIL: $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$planIds = $pdo->query("SELECT id FROM plans WHERE status='active' ORDER BY price_monthly ASC")->fetchAll(PDO::FETCH_COLUMN);
if (count($planIds) < 2) { echo "  SKIP: needs at least two active plans\n"; exit(0); }
$freePlan = (int)$planIds[0];
$paidPlan = (int)end($planIds);

$pdo->beginTransaction();
try {
    $mkUser = function (string $tag) use ($pdo, $paidPlan): array {
        $email = 'exec69-expiry-' . $tag . '-' . bin2hex(random_bytes(3)) . '@example.com';
        $pdo->prepare("INSERT INTO users (full_name, email, password_hash, plan_id, status) VALUES (?,?,?,?,'active')")
            ->execute(['Expiry ' . $tag, $email, password_hash('x', PASSWORD_DEFAULT), $paidPlan]);
        return ['id' => (int)$pdo->lastInsertId(), 'email' => $email];
    };
    $mkSub = function (int $userId, int $planId, string $endsAt, string $status = 'active') use ($pdo): int {
        $pdo->prepare("INSERT INTO subscriptions (user_id, plan_id, billing_cycle, starts_at, ends_at, status) VALUES (?,?,'monthly',?,?,'" . $status . "')")
            ->execute([$userId, $planId, date('Y-m-d', strtotime('-2 months')), $endsAt]);
        return (int)$pdo->lastInsertId();
    };
    $planOf = function (int $userId) use ($pdo): ?int {
        $s = $pdo->prepare('SELECT plan_id FROM users WHERE id=?'); $s->execute([$userId]);
        $v = $s->fetchColumn();
        return $v === null || $v === false ? null : (int)$v;
    };
    $statusOf = function (int $subId) use ($pdo): string {
        $s = $pdo->prepare('SELECT status FROM subscriptions WHERE id=?'); $s->execute([$subId]);
        return (string)$s->fetchColumn();
    };

    echo "\n== 1. an expired term ends and reverts the entitlement ==\n";
    $a = $mkUser('due');
    $subA = $mkSub($a['id'], $paidPlan, date('Y-m-d', strtotime('-1 day')));
    $res = subscriptions_expire_due($pdo);
    check('the subscription is marked expired', $statusOf($subA) === 'expired', 'got ' . $statusOf($subA));
    check('the user is reverted to the base plan', $planOf($a['id']) === $freePlan, 'got ' . (string)$planOf($a['id']));
    check('the sweep counts it', $res['expired'] >= 1 && $res['downgraded'] >= 1);

    echo "\n== 2. a term ending today is still in force ==\n";
    $b = $mkUser('today');
    $subB = $mkSub($b['id'], $paidPlan, date('Y-m-d'));
    subscriptions_expire_due($pdo);
    check('today is not expired (a paid day is a paid day)', $statusOf($subB) === 'active');
    check('the plan is untouched', $planOf($b['id']) === $paidPlan);

    echo "\n== 3. a future term is untouched ==\n";
    $c = $mkUser('future');
    $subC = $mkSub($c['id'], $paidPlan, date('Y-m-d', strtotime('+1 month')));
    subscriptions_expire_due($pdo);
    check('the future subscription stays active', $statusOf($subC) === 'active');
    check('its plan stays in force', $planOf($c['id']) === $paidPlan);

    echo "\n== 4. a user who upgraded keeps the higher plan ==\n";
    // The old row expires after the user already bought something better. Reverting blindly
    // would silently downgrade a customer who paid for more.
    $d = $mkUser('upgraded');
    $subD = $mkSub($d['id'], $paidPlan, date('Y-m-d', strtotime('-1 day')));
    $pdo->prepare('UPDATE users SET plan_id=? WHERE id=?')->execute([$paidPlan + 1000, $d['id']]);
    subscriptions_expire_due($pdo);
    check('the old subscription still ends', $statusOf($subD) === 'expired');
    check('the higher plan the user now holds is preserved', $planOf($d['id']) === $paidPlan + 1000, 'got ' . (string)$planOf($d['id']));

    echo "\n== 5. a cancelled subscription is not re-expired ==\n";
    $e = $mkUser('cancelled');
    $subE = $mkSub($e['id'], $paidPlan, date('Y-m-d', strtotime('-5 days')), 'cancelled');
    subscriptions_expire_due($pdo);
    check('the cancelled row keeps its own status', $statusOf($subE) === 'cancelled');

    echo "\n== 6. the sweep is idempotent ==\n";
    $second = subscriptions_expire_due($pdo);
    check('a second run expires nothing already expired', $second['expired'] === 0, 'expired=' . $second['expired']);

    echo "\n== 7. the cron entry point exists and is guarded ==\n";
    $cron = __DIR__ . '/../cron/subscription-expiry.php';
    check('cron/subscription-expiry.php is shipped', is_file($cron));
    $src = is_file($cron) ? file_get_contents($cron) : '';
    check('it refuses to run over HTTP', strpos($src, "PHP_SAPI !== 'cli'") !== false);
    check('it calls the sweep', strpos($src, 'subscriptions_expire_due') !== false);

    $pdo->rollBack();
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "  FAIL: threw " . get_class($ex) . ': ' . $ex->getMessage() . "\n";
    $fails++;
}

echo "\n{$checks} checks, {$fails} failure(s)\n";
exit($fails === 0 ? 0 : 1);
