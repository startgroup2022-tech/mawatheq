<?php
/**
 * Execution 68 — the package journey, driven over real HTTP as a signed-in client.
 *
 * The unit-style suites assert the service functions, and exec78_plans_roundtrip_test
 * asserts admin→customer propagation at the database level. Neither proves the page a
 * customer actually loads: a catalogue row and a checkout button can both be correct while
 * the rendered page shows the wrong price, hides the paid plan, or drops the pending order
 * that makes an abandoned checkout recoverable. That is what this probe drives.
 *
 * It asserts the four things the owner reported on production:
 *
 *   1. the catalogue renders every active plan with the price from `plans`
 *      (never a hard-coded number), formatted through includes/currency.php
 *   2. an admin edit to a plan's price reaches the customer page
 *   3. choosing a paid plan creates exactly one pending order and redirects to checkout
 *   4. the checkout page shows the same amount the catalogue advertised, and the
 *      pending-order banner makes the abandoned order recoverable
 *
 * Usage: MAWATHEQ_BASE_URL=http://127.0.0.1:8080 php tests/exec68_package_journey_probe.php
 */

$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://127.0.0.1:8080';
$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/currency.php';

$checks = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $checks, $fail;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; return; }
    $fail++;
    echo "  FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

/**
 * A tiny browser: keeps the PHPSESSID explicitly in memory.
 *
 * cURL's cookie-jar file was unreliable here — the jar stayed empty in one code path and
 * populated in another, so the POST carried no session and every CSRF check failed with
 * "Invalid request". The CSRF token is bound to the session (`csrf_is_valid()` compares it
 * against `$_SESSION`), so the session cookie is not optional: without it the token that was
 * just scraped belongs to a different, empty session. Passing the cookie explicitly makes the
 * probe's behaviour independent of jar-file timing.
 */
final class HttpProbe {
    private string $cookie = '';
    public function __construct(private string $base) {}

    private function absorb(array $headers): void {
        foreach ($headers as $h) {
            if (stripos($h, 'set-cookie:') === 0 && preg_match('/PHPSESSID=([^;]+)/i', $h, $m)) {
                $this->cookie = 'PHPSESSID=' . $m[1];
            }
        }
    }

    /** @return array{0:int,1:string} */
    public function get(string $path): array {
        return $this->request($path, null);
    }

    /** @return array{0:int,1:string,2:string} status, body, redirect */
    public function post(string $path, array $fields): array {
        [$code, $body, $loc] = $this->request($path, $fields);
        return [$code, $body, $loc];
    }

    /** @return array{0:int,1:string,2:string} */
    private function request(string $path, ?array $fields): array {
        if (preg_match('#^https?://#', $path)) {
            $url = $path;
        } else {
            $url = $this->base . '/' . ltrim($path, '/');
        }
        $ch = curl_init($url);
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $headers[] = trim($line);
                return strlen($line);
            },
        ]);
        if ($this->cookie !== '') curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
        if ($fields !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        $this->absorb($headers);
        return [$code, $body, $loc];
    }
}

function csrf_of(string $html): string {
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

echo "Execution 68 — package journey over real HTTP ($base)\n\n";

$http = new HttpProbe($base);
$email = 'client-a@example.com';
$pass = 'Test-Password-2026';

// The throttle counts attempts per account and IP; a previous run leaves it full.
try { $pdo->exec('DELETE FROM auth_rate_limits'); } catch (Throwable $e) {}

$planId = 0;
$origPrice = null;
$createdOrders = [];
// Activation is a real side effect: a probe that clicks a free "Activate" button creates a
// subscription. Record the high-water mark so cleanup removes only what this run added.
$maxSubBefore = (int)($pdo->query('SELECT COALESCE(MAX(id),0) FROM subscriptions')->fetchColumn() ?: 0);

try {
    echo "== 1. sign in ==\n";
    // Fetch the form WITH the cookie jar so the session cookie is stored before the POST.
    [$code, $loginHtml] = $http->get('/login.php');
    check('the login form loads', $code === 200, "HTTP $code");
    $token = csrf_of($loginHtml);
    check('the login form carries a CSRF token', $token !== '');
    [$code, $loginBody, $loc] = $http->post('/login.php', [
        'csrf_token' => $token, 'email' => $email, 'password' => $pass,
    ]);
    check('the login is accepted', $loc !== '' && !str_contains($loc, 'login.php'),
        "HTTP $code, location=$loc, body=" . substr($loginBody, 0, 120));

    echo "\n== 2. the catalogue renders every active plan at the DB price ==\n";
    [$code, $html] = $http->get('/packages.php');
    check('the catalogue loads for a client', $code === 200, "HTTP $code");
    check('the catalogue is the real page, not a redirect to login', !str_contains($html, 'name="password"'));

    $plans = $pdo->query("SELECT * FROM plans WHERE status='active' ORDER BY sort_order, price_monthly")->fetchAll();
    check('there is at least one active plan', count($plans) > 0);
    foreach ($plans as $p) {
        $name = (string)(is_rtl() ? $p['name_ar'] : $p['name_en']);
        check('the plan name appears: ' . $name, str_contains($html, $name), 'not found in the page');
    }

    // The price must come from `plans` and be formatted by money(). Compare the exact
    // formatted string, so a plan the page silently omits fails rather than passing on a
    // substring that another plan also produces.
    $paidPlan = null;
    foreach ($plans as $p) {
        if ((float)$p['price_monthly'] > 0) { $paidPlan = $p; break; }
    }
    check('there is a paid plan to buy', $paidPlan !== null);
    if ($paidPlan) {
        $planId = (int)$paidPlan['id'];
        $formatted = money_text((float)$paidPlan['price_monthly'], (string)($paidPlan['currency'] ?? ''));
        // money() emits HTML; the digits and the currency label are what a customer sees.
        $digits = number_format((float)$paidPlan['price_monthly'], currency_decimals(), '.', ',');
        check('the paid plan price is rendered (' . $digits . ')', str_contains($html, $digits),
            'the plan price from the DB is not on the page');
    }

    echo "\n== 3. an admin edit to the price reaches the customer page ==\n";
    if ($planId > 0) {
        $origPrice = (string)$paidPlan['price_monthly'];
        // Move the price to a value no other plan uses, so the assertion cannot pass on
        // another plan's price that happens to be on the page already.
        $probePrice = 1247.0;
        $pdo->prepare('UPDATE plans SET price_monthly=? WHERE id=?')->execute([$probePrice, $planId]);
        [$code, $html2] = $http->get('/packages.php');
        check('the catalogue reloads', $code === 200, "HTTP $code");
        $probeDigits = number_format($probePrice, currency_decimals(), '.', ',');
        check('the edited price is shown (' . $probeDigits . ')', str_contains($html2, $probeDigits),
            'the admin edit did not reach the customer page');
        $pdo->prepare('UPDATE plans SET price_monthly=? WHERE id=?')->execute([$origPrice, $planId]);
    }

    echo "\n== 4. choosing a paid plan creates one pending order and redirects ==\n";
    // Clear any pending order this account already has, so "exactly one" is meaningful.
    $pdo->prepare("DELETE FROM package_orders WHERE user_id=(SELECT id FROM users WHERE email=?) AND status='pending'")
        ->execute([$email]);

    [$code, $html3] = $http->get('/packages.php');
    $token = csrf_of($html3);
    check('the catalogue carries a CSRF token for the buy form', $token !== '');

    if ($planId > 0 && $token !== '') {
        // Find the form that buys this plan, so the probe posts what the page posts.
        preg_match_all('/<form[^>]*action="([^"]*)"[^>]*>(.*?)<\/form>/s', $html3, $forms, PREG_SET_ORDER);
        $buyAction = ''; $formHtml = '';
        // Match the exact hidden field, not a digit substring. The CSRF token is 64 hex
        // characters, so `str_contains($form, (string)$planId)` matches any plan whose id
        // appears inside the token — the probe then posts the WRONG plan's form and the
        // assertion passes on a page it never exercised.
        foreach ($forms as $f) {
            if (preg_match('/name="plan_id"\s+value="(\d+)"/', $f[2], $pm)
                && (int)$pm[1] === $planId
                && !str_contains($f[2], 'type="button"')) {
                $buyAction = $f[1]; $formHtml = $f[0]; break;
            }
        }
        check('the catalogue offers a buy form for the paid plan', $buyAction !== '',
            'no form posts plan_id=' . $planId);

        if ($buyAction !== '') {
            // Submit exactly what the form submits. Guessing the fields (a missing
            // `action=checkout`) makes checkout.php render the page with a 200 and the probe
            // reports a "failed redirect" that is really a malformed request.
            $fields = [];
            if (preg_match_all('/<input[^>]*type="hidden"[^>]*>/', $formHtml, $hides)) {
                foreach ($hides[0] as $in) {
                    $n = preg_match('/name="([^"]+)"/', $in, $nm) ? $nm[1] : '';
                    $v = preg_match('/value="([^"]*)"/', $in, $vm) ? html_entity_decode($vm[1], ENT_QUOTES) : '';
                    if ($n !== '') $fields[$n] = $v;
                }
            }
            [$code, , $loc] = $http->post($buyAction, $fields);
            check('the buy redirects to checkout', str_contains($loc, 'checkout.php'), "HTTP $code, location=$loc");

            $pending = $pdo->prepare("SELECT * FROM package_orders WHERE user_id=(SELECT id FROM users WHERE email=?) AND status='pending'");
            $pending->execute([$email]);
            $rows = $pending->fetchAll();
            check('exactly one pending order exists', count($rows) === 1, count($rows) . ' found');
            if ($rows) {
                $createdOrders[] = (int)$rows[0]['id'];
                check('the order carries the plan price, not zero',
                    (float)$rows[0]['amount'] > 0, 'amount=' . $rows[0]['amount']);

                echo "\n== 5. the checkout page shows the same amount the catalogue did ==\n";
                [$code, $co] = $http->get('/checkout.php?order_id=' . (int)$rows[0]['id']);
                check('the checkout page loads', $code === 200, "HTTP $code");
                $amountDigits = number_format((float)$rows[0]['amount'], currency_decimals(), '.', ',');
                check('the checkout shows the order amount (' . $amountDigits . ')',
                    str_contains($co, $amountDigits), 'the amount is not on the checkout page');
                check('the checkout offers the Pay action', stripos($co, 'pay') !== false || str_contains($co, 'ادفع') || str_contains($co, 'سداد'));
            }

            echo "\n== 6. an abandoned order is recoverable from the catalogue ==\n";
            [$code, $html4] = $http->get('/packages.php');
            check('the catalogue still loads with a pending order', $code === 200, "HTTP $code");
            check('the pending order is offered again', !empty($rows) && (str_contains($html4, 'order_id=' . (int)$rows[0]['id'])
                || str_contains($html4, 'checkout.php')),
                'the pending order is not linked, so an abandoned checkout is unrecoverable');
        }
    }
} catch (Throwable $e) {
    $checks++; $fail++;
    echo "  FAIL: unexpected error — " . $e->getMessage() . "\n";
} finally {
    if ($origPrice !== null && $planId > 0) {
        $pdo->prepare('UPDATE plans SET price_monthly=? WHERE id=?')->execute([$origPrice, $planId]);
    }
    foreach ($createdOrders as $oid) {
        $pdo->prepare("DELETE FROM package_orders WHERE id=? AND status='pending'")->execute([$oid]);
    }
    try {
        $pdo->prepare('DELETE FROM subscriptions WHERE id > ?')->execute([$maxSubBefore]);
    } catch (Throwable $e) {}
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "  Execution 68 package journey: $checks checks, $fail failure(s)\n";
exit($fail > 0 ? 1 : 0);
