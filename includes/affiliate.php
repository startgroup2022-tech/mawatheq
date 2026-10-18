<?php
/**
 * Affiliate programme core (Execution 31).
 *
 * Identity, settings, money arithmetic, the commission state machine, audit and
 * notification plumbing. Everything else in the module builds on this file.
 *
 * MONEY
 *   Ledger arithmetic never touches a float. Amounts are converted to integer
 *   minor units (fils for a 3-decimal currency, halalas for a 2-decimal one) on
 *   the way in, summed as integers, and converted back only when written to a
 *   DECIMAL column. 0.1 + 0.2 is not 0.3 in binary floating point, and a ledger
 *   that drifts by a fil per thousand entries is worse than one that refuses to
 *   start. currency_decimals() supplies the exponent so BHD keeps its third
 *   decimal while SAR does not.
 *
 * STATUS
 *   `affiliate_status` is the lifecycle gate. `affiliate_can_earn()` is the single
 *   place that answers "may this affiliate generate commission-bearing value
 *   right now", so a suspended or pending affiliate cannot be enabled by an
 *   oversight in one of the dozen screens that would otherwise each need to know.
 */
require_once __DIR__ . '/currency.php';

const AFFILIATE_TERMS_VERSION = '1.0';
const AFFILIATE_PRIVACY_VERSION = '1.0';

/** The affiliate lifecycle values, in order. */
function affiliate_statuses(): array {
    return ['pending', 'under_review', 'approved', 'active', 'suspended', 'rejected', 'closed'];
}

/**
 * Commission / conversion state machine.
 *
 * Transitions are declared, not implied. `affiliate_state_can_transition()` is the
 * only place that decides whether a move is legal, so the web routes, the API and
 * the payment hook cannot disagree about the lifecycle.
 */
function affiliate_states(): array {
    return ['tracked', 'qualified', 'pending_payment', 'paid_eligible', 'holding', 'approved', 'payable', 'paid_out'];
}

/** Terminal or off-path states. Reached from anywhere, left only by an explicit correction. */
function affiliate_offpath_states(): array {
    return ['rejected', 'cancelled', 'refunded', 'reversed', 'disputed', 'suspended'];
}

/**
 * Legal transitions.
 *
 * The forward path is the one in the brief. The interesting entries are the
 * backward ones: a refund moves a `paid_out` commission to `reversed` rather than
 * deleting it, because the ledger must be able to show that money was paid and
 * then clawed back. `holding` is reachable from `paid_eligible` because the hold
 * period starts when the payment lands, not when the commission is first written.
 */
function affiliate_state_transitions(): array {
    return [
        'tracked' => ['qualified', 'rejected', 'cancelled', 'suspended'],
        'qualified' => ['pending_payment', 'tracked', 'rejected', 'cancelled', 'refunded', 'reversed', 'disputed', 'suspended'],
        'pending_payment' => ['paid_eligible', 'holding', 'qualified', 'rejected', 'cancelled', 'refunded', 'reversed', 'disputed', 'suspended'],
        'paid_eligible' => ['holding', 'approved', 'pending_payment', 'refunded', 'reversed', 'disputed', 'suspended'],
        'holding' => ['approved', 'refunded', 'reversed', 'disputed', 'suspended', 'cancelled'],
        'approved' => ['payable', 'holding', 'refunded', 'reversed', 'disputed', 'suspended'],
        'payable' => ['paid_out', 'approved', 'reversed', 'disputed', 'suspended'],
        'paid_out' => ['reversed', 'disputed'],
        'rejected' => ['tracked'],
        'cancelled' => ['tracked'],
        'refunded' => [],
        'reversed' => [],
        'disputed' => ['approved', 'reversed', 'rejected'],
        'suspended' => ['qualified', 'pending_payment', 'approved', 'rejected', 'cancelled'],
    ];
}

/** True when a transition from $from to $to is declared legal. */
function affiliate_state_can_transition(string $from, string $to): bool {
    if ($from === $to) return true; // idempotent re-application of the same state
    $map = affiliate_state_transitions();
    return in_array($to, $map[$from] ?? [], true);
}

/** States that count as money the affiliate has actually earned and may eventually be paid. */
function affiliate_states_earning(): array {
    return ['paid_eligible', 'holding', 'approved', 'payable', 'paid_out'];
}

/** States that still count toward a pending balance shown in the dashboard. */
function affiliate_states_pending(): array {
    return ['tracked', 'qualified', 'pending_payment'];
}

/** States excluded from every payable figure. */
function affiliate_states_excluded(): array {
    return ['rejected', 'cancelled', 'refunded', 'reversed', 'disputed', 'suspended'];
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

/**
 * Affiliate settings, read once per request from site_settings with defaults that
 * are safe on a database that has not run upgrade_v57.sql yet.
 *
 * Cached in a global rather than a function static so affiliate_settings_reload()
 * can actually clear it — PHP offers no way to reset another function's static,
 * and an admin saving a new attribution window expects the next read to see it.
 */
function affiliate_settings(?PDO $pdo = null): array {
    if (isset($GLOBALS['__affiliate_settings']) && empty($GLOBALS['__affiliate_settings_reset'])) {
        return $GLOBALS['__affiliate_settings'];
    }

    $defaults = [
        'affiliate_program_enabled' => '0',
        'affiliate_attribution_days' => '30',
        'affiliate_attribution_model' => 'first_touch',
        'affiliate_cookie_days' => '90',
        'affiliate_require_consent' => '1',
        'affiliate_min_payout' => '50.000',
        'affiliate_payout_currency' => 'BHD',
        'affiliate_dual_approval_threshold' => '500.000',
        'affiliate_default_hold_days' => '30',
        'affiliate_default_commission_percent' => '10.000',
    ];

    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
    $out = $defaults;
    if ($pdo instanceof PDO) {
        try {
            $keys = array_keys($defaults);
            $in = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($in)");
            $stmt->execute($keys);
            foreach ($stmt->fetchAll() as $row) {
                $out[$row['setting_key']] = (string)$row['setting_value'];
            }
        } catch (Throwable $e) {
            // Pre-v57 database: defaults are already in $out, and the programme is off.
            error_log('affiliate_settings: ' . $e->getMessage());
        }
    }
    $out['attribution_days'] = max(1, min(730, (int)$out['affiliate_attribution_days']));
    $out['cookie_days'] = max(1, min(730, (int)$out['affiliate_cookie_days']));
    $out['require_consent'] = $out['affiliate_require_consent'] === '1';
    $out['program_enabled'] = $out['affiliate_program_enabled'] === '1';
    $out['attribution_model'] = in_array($out['affiliate_attribution_model'], ['first_touch', 'last_touch'], true)
        ? $out['affiliate_attribution_model'] : 'first_touch';
    $out['hold_days'] = max(0, min(365, (int)$out['affiliate_default_hold_days']));
    $out['payout_currency'] = currency_normalize($out['affiliate_payout_currency']);
    $out['min_payout'] = (string)$out['affiliate_min_payout'];
    $out['dual_approval_threshold'] = (string)$out['affiliate_dual_approval_threshold'];
    $GLOBALS['__affiliate_settings_reset'] = false;
    return $GLOBALS['__affiliate_settings'] = $out;
}

/**
 * Re-read settings on the next call.
 *
 * The cache lives in a global precisely so this can clear it; a function static
 * could not be reached from here. The admin settings form calls this after saving,
 * and the test suite calls it between cases that change a value.
 */
function affiliate_settings_reload(): void
{
    $GLOBALS['__affiliate_settings_reset'] = true;
    unset($GLOBALS['__affiliate_settings']);
}

/** Alias kept for call sites that read as "forget the cached settings". */
function affiliate_settings_forget(): void
{
    affiliate_settings_reload();
}

// ---------------------------------------------------------------------------
// Money
// ---------------------------------------------------------------------------

/** Minor-unit factor for a currency: 100 for 2 decimals, 1000 for 3. */
function affiliate_minor_factor(?string $currency = null): int {
    return (int)pow(10, currency_decimals($currency));
}

/** Integer power of ten, without going through pow()'s float return. */
function affiliate_pow10(int $exponent): int {
    $out = 1;
    for ($i = 0; $i < $exponent; $i++) $out *= 10;
    return $out;
}

/**
 * Convert a decimal amount (string or number) to integer minor units.
 *
 * Deliberately string-based: the value normally arrives from a DECIMAL column as a
 * string, and running it through (float) first would reintroduce exactly the
 * rounding this exists to avoid. The fraction is padded or truncated to the
 * currency's own exponent.
 */
function affiliate_to_minor($amount, ?string $currency = null): int {
    $decimals = currency_decimals($currency);
    $s = trim((string)$amount);
    if ($s === '') return 0;
    $neg = false;
    if ($s[0] === '-') { $neg = true; $s = substr($s, 1); }
    elseif ($s[0] === '+') { $s = substr($s, 1); }
    if (!preg_match('/^(\d*)(?:\.(\d*))?$/', $s, $m)) {
        // Anything unparseable is treated as zero rather than guessed at. A caller
        // that feeds this a formatted string ("1,200.50 BHD") has a bug upstream,
        // and silently interpreting it would hide the bug and corrupt the ledger.
        return 0;
    }
    $int = $m[1] === '' ? '0' : $m[1];
    $frac = $m[2] ?? '';
    $frac = str_pad($frac, $decimals + 1, '0');           // one guard digit
    $guard = (int)substr($frac, $decimals, 1);
    $frac = substr($frac, 0, $decimals);
    $minor = ((int)$int) * affiliate_pow10($decimals) + (int)($frac === '' ? '0' : $frac);
    if ($guard >= 5) $minor++;                             // half-up on the guard digit
    return $neg ? -$minor : $minor;
}

/** Render integer minor units back to a DECIMAL-column string. */
function affiliate_from_minor(int $minor, ?string $currency = null): string {
    $decimals = currency_decimals($currency);
    $factor = affiliate_pow10($decimals);
    $neg = $minor < 0;
    $minor = abs($minor);
    $int = intdiv($minor, $factor);
    $frac = $minor % $factor;
    return ($neg ? '-' : '') . $int . ($decimals > 0 ? '.' . str_pad((string)$frac, $decimals, '0', STR_PAD_LEFT) : '');
}

/** Round a decimal amount to the currency's precision, returning a DECIMAL string. */
function affiliate_round($amount, ?string $currency = null): string {
    return affiliate_from_minor(affiliate_to_minor($amount, $currency), $currency);
}

/**
 * Apply a percentage to integer minor units, half-up, using integer arithmetic.
 *
 * The rate is carried in thousandths of a percent (10.5% is 10500) so a fractional
 * rate is representable exactly. The rate string is parsed digit-by-digit rather
 * than through (float), because the whole point of this module is that no financial
 * value ever round-trips through binary floating point — and a commission rate is a
 * financial value.
 *
 * The division rounds half-up explicitly: intdiv() truncates, and a truncated
 * commission is a silent short-pay that nobody would ever notice.
 */
function affiliate_apply_percent(int $minor, $percent): int {
    $rateThousandths = affiliate_percent_to_thousandths($percent);
    if ($rateThousandths === 0 || $minor === 0) return 0;
    $product = $minor * $rateThousandths;
    $divisor = 100 * 1000;
    $negative = ($product < 0) !== ($divisor < 0);
    $product = abs($product);
    $quotient = intdiv($product, $divisor);
    $remainder = $product - $quotient * $divisor;
    if ($remainder * 2 >= $divisor) $quotient++;
    return $negative ? -$quotient : $quotient;
}

/**
 * Parse a percentage into thousandths of a percent without a float.
 * '10' → 10000, '10.5' → 10500, '2.25' → 2250.
 */
function affiliate_percent_to_thousandths($percent): int {
    $s = trim((string)$percent);
    if ($s === '') return 0;
    $neg = false;
    if ($s[0] === '-') { $neg = true; $s = substr($s, 1); }
    elseif ($s[0] === '+') { $s = substr($s, 1); }
    if (!preg_match('/^(\d*)(?:\.(\d*))?$/', $s, $m)) return 0;
    $int = $m[1] === '' ? '0' : $m[1];
    // Three decimal places of a percent is the precision the DECIMAL(6,3) column
    // stores, so the guard digit beyond it decides the rounding.
    $frac = str_pad($m[2] ?? '', 4, '0');
    $guard = (int)substr($frac, 3, 1);
    $thousandths = ((int)$int) * 1000 + (int)substr($frac, 0, 3);
    if ($guard >= 5) $thousandths++;
    return $neg ? -$thousandths : $thousandths;
}

/** Format minor units for display with the currency symbol. */
function affiliate_format_money($amount, ?string $currency = null): string {
    $code = currency_normalize($currency);
    $options = currency_options();
    $symbol = is_rtl() ? ($options[$code]['symbol_ar'] ?? $code) : ($options[$code]['symbol_en'] ?? $code);
    $decimals = currency_decimals($code);
    return number_format((float)$amount, $decimals) . ' ' . $symbol;
}

// ---------------------------------------------------------------------------
// Identity and authorization
// ---------------------------------------------------------------------------

/** Find an affiliate by id. Returns null when absent. */
function affiliate_find(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    $stmt = $pdo->prepare('SELECT * FROM affiliates WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Find an affiliate by referral code (case-insensitive). */
function affiliate_find_by_code(PDO $pdo, string $code): ?array {
    $code = strtoupper(trim($code));
    if ($code === '') return null;
    $stmt = $pdo->prepare('SELECT * FROM affiliates WHERE code=?');
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

/** Find an affiliate by email (case-insensitive). */
function affiliate_find_by_email(PDO $pdo, string $email): ?array {
    $email = strtolower(trim($email));
    if ($email === '') return null;
    $stmt = $pdo->prepare('SELECT * FROM affiliates WHERE email=?');
    $stmt->execute([$email]);
    return $stmt->fetch() ?: null;
}

/**
 * The affiliate signed in to the affiliate portal, from the session.
 * Mirrors auth_guard.php's contract: the row is re-read so a suspension takes
 * effect on the next request rather than at the next login.
 */
function affiliate_current(?PDO $pdo = null): ?array {
    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
    $id = (int)($_SESSION['affiliate_id'] ?? 0);
    if ($id <= 0 || !$pdo instanceof PDO) return null;
    return affiliate_find($pdo, $id);
}

/**
 * May this affiliate generate commission-bearing value right now?
 *
 * `active` is the only state that qualifies. `approved` means the review passed but
 * the affiliate has not accepted the current agreement yet — they can see the
 * dashboard and their terms, but not earn. A pending applicant cannot even reach
 * the link builder.
 */
function affiliate_can_earn(?array $affiliate): bool {
    if (!$affiliate) return false;
    return ($affiliate['status'] ?? '') === 'active'
        && ($affiliate['compliance_status'] ?? '') === 'approved';
}

/** May this affiliate sign in and use the portal at all? */
function affiliate_can_login(?array $affiliate): bool {
    if (!$affiliate) return false;
    return in_array((string)($affiliate['status'] ?? ''), ['approved', 'active', 'suspended'], true);
}

/** Human label for a status, in the active language. */
function affiliate_status_label(string $status): string {
    $map = [
        'pending' => ['قيد المراجعة', 'Pending'],
        'under_review' => ['تحت المراجعة', 'Under review'],
        'approved' => ['معتمد', 'Approved'],
        'active' => ['نشط', 'Active'],
        'suspended' => ['موقوف', 'Suspended'],
        'rejected' => ['مرفوض', 'Rejected'],
        'closed' => ['مغلق', 'Closed'],
    ];
    $row = $map[$status] ?? [$status, $status];
    return is_rtl() ? $row[0] : $row[1];
}

/** CSS class suffix for a status pill, reusing the existing pill classes. */
function affiliate_status_class(string $status): string {
    return match ($status) {
        'active', 'approved', 'paid_out', 'paid', 'payable' => 'status-confirmed',
        'pending', 'under_review', 'holding', 'requested', 'processing', 'under_review_payout' => 'status-pending',
        'suspended', 'rejected', 'failed', 'reversed', 'refunded', 'cancelled', 'disputed' => 'status-cancelled',
        default => 'status-pending',
    };
}

/** Generate a unique referral code. Ambiguous glyphs (I, O, 0, 1) are excluded. */
function affiliate_generate_code(PDO $pdo, string $prefix = 'AFF'): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $code = $prefix;
        for ($i = 0; $i < 5; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM affiliates WHERE code=?');
        $stmt->execute([$code]);
        if ((int)$stmt->fetchColumn() === 0) return $code;
    }
    // A collision 40 times in a row means something is wrong with the source of
    // randomness; failing loudly beats handing out a duplicate code.
    throw new RuntimeException('Unable to allocate a unique affiliate code');
}

// ---------------------------------------------------------------------------
// Audit and notifications
// ---------------------------------------------------------------------------

/**
 * Write to the affiliate module's own audit trail.
 *
 * Separate from log_action() (which is keyed to an admin/lawyer/client actor and
 * cannot express an affiliate actor or a state transition) but the same principle:
 * an audit failure must never turn a successful business action into an error.
 */
function affiliate_audit(PDO $pdo, string $action, array $options = []): void {
    $actorType = (string)($options['actor_type'] ?? 'system');
    if (!in_array($actorType, ['admin', 'affiliate', 'system'], true)) $actorType = 'system';
    $actorId = isset($options['actor_id']) ? (int)$options['actor_id'] : null;
    $actorName = isset($options['actor_name']) ? mb_substr((string)$options['actor_name'], 0, 150, 'UTF-8') : null;

    // Resolve the actor from the session when the caller did not say, so the trail
    // records who acted even from a route that forgot to pass it.
    if (!isset($options['actor_type'])) {
        if (!empty($_SESSION['admin_id'])) { $actorType = 'admin'; $actorId = (int)$_SESSION['admin_id']; $actorName = $_SESSION['admin_name'] ?? null; }
        elseif (!empty($_SESSION['affiliate_id'])) { $actorType = 'affiliate'; $actorId = (int)$_SESSION['affiliate_id']; $actorName = $_SESSION['affiliate_name'] ?? null; }
    }

    try {
        $pdo->prepare('INSERT INTO affiliate_audit_logs (actor_type, actor_id, actor_name, affiliate_id, action, subject_type, subject_id, from_state, to_state, detail, ip_hash)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $actorType, $actorId, $actorName,
                isset($options['affiliate_id']) ? (int)$options['affiliate_id'] : null,
                mb_substr($action, 0, 100, 'UTF-8'),
                isset($options['subject_type']) ? mb_substr((string)$options['subject_type'], 0, 40, 'UTF-8') : null,
                isset($options['subject_id']) ? (int)$options['subject_id'] : null,
                isset($options['from_state']) ? mb_substr((string)$options['from_state'], 0, 40, 'UTF-8') : null,
                isset($options['to_state']) ? mb_substr((string)$options['to_state'], 0, 40, 'UTF-8') : null,
                isset($options['detail']) ? mb_substr((string)$options['detail'], 0, 1000, 'UTF-8') : null,
                $options['ip_hash'] ?? affiliate_ip_hash(),
            ]);
    } catch (Throwable $e) {
        error_log('affiliate_audit failed: ' . $e->getMessage());
    }
}

/** A salted hash of the client IP. Never the raw address. */
function affiliate_ip_hash(): ?string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') return null;
    $salt = defined('MAWATHEQ_ENCRYPTION_KEY') ? (string)MAWATHEQ_ENCRYPTION_KEY : 'mawatheq-affiliate';
    return hash('sha256', $salt . '|' . $ip);
}

/**
 * Notify an affiliate, at most once per event.
 *
 * The unique key on (event_key, channel) makes a retried webhook or a re-run of an
 * approval step unable to send the same notice twice, which is what stops the
 * "your commission was approved" message arriving five times for one commission.
 * Delivery is in-app only: there is no email transport wired to this module, and
 * claiming otherwise in the UI would be a lie.
 */
function affiliate_notify(PDO $pdo, int $affiliateId, string $eventKey, string $type, string $title, ?string $body = null, ?string $link = null): bool {
    if ($affiliateId <= 0) return false;
    try {
        $pdo->prepare('INSERT INTO affiliate_notification_log (affiliate_id, event_key, notification_type, title, body, link, channel, status)
            VALUES (?,?,?,?,?,?,?,?)')
            ->execute([
                $affiliateId, mb_substr($eventKey, 0, 80, 'UTF-8'), mb_substr($type, 0, 60, 'UTF-8'),
                mb_substr($title, 0, 255, 'UTF-8'),
                $body !== null ? mb_substr($body, 0, 1000, 'UTF-8') : null,
                $link !== null ? mb_substr($link, 0, 255, 'UTF-8') : null,
                'in_app', 'sent',
            ]);
        return true;
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) return false; // already sent — correct
        error_log('affiliate_notify failed: ' . $e->getMessage());
        return false;
    }
}

/** Recent notifications for an affiliate. */
function affiliate_notifications(PDO $pdo, int $affiliateId, int $limit = 30): array {
    $limit = max(1, min(200, $limit));
    $stmt = $pdo->prepare('SELECT * FROM affiliate_notification_log WHERE affiliate_id=? ORDER BY id DESC LIMIT ' . $limit);
    $stmt->execute([$affiliateId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Validation helpers shared by the public form, the invitation flow and the API
// ---------------------------------------------------------------------------

/**
 * Validate an email for affiliate use.
 *
 * Same rules as the rest of the platform: filter_var plus a length cap. The
 * affiliate email becomes a login identifier, so it is normalised to lower case
 * here rather than at each call site.
 */
function affiliate_valid_email(string $email): bool {
    $email = trim($email);
    if ($email === '' || strlen($email) > 190) return false;
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

/** Normalise a phone number to a compact international form, or null when unusable. */
function affiliate_normalize_phone(?string $phone): ?string {
    $phone = trim((string)$phone);
    if ($phone === '') return null;
    $digits = preg_replace('/[^0-9+]/', '', $phone);
    if ($digits === null || strlen($digits) < 7 || strlen($digits) > 20) return null;
    return $digits;
}

/**
 * Store payout details, encrypted at rest where the deployment has a key.
 *
 * secret_encrypt_value() returns null when MAWATHEQ_ENCRYPTION_KEY is absent or
 * too short. In that case the plaintext is NOT written: an unencrypted bank
 * account in the database is worse than a payout the operator has to re-request.
 * The caller gets false and the interface says why.
 */
function affiliate_store_payout_details(PDO $pdo, int $payoutRequestId, array $details): bool {
    require_once __DIR__ . '/secret_crypto.php';
    $json = json_encode($details, JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    $encrypted = secret_encrypt_value($json);
    if ($encrypted === null || $encrypted === '') return false;
    $pdo->prepare('UPDATE affiliate_payout_requests SET payout_details_enc=?, payout_details_mask=? WHERE id=?')
        ->execute([$encrypted, affiliate_mask_payout_details($details), $payoutRequestId]);
    return true;
}

/** A display-safe summary of payout details: last four digits, never the whole number. */
function affiliate_mask_payout_details(array $details): string {
    $iban = preg_replace('/\s+/', '', (string)($details['iban'] ?? ''));
    $account = preg_replace('/\s+/', '', (string)($details['account_number'] ?? ''));
    $bank = mb_substr(trim((string)($details['bank_name'] ?? '')), 0, 60, 'UTF-8');
    $tail = '';
    if ($iban !== '' && strlen($iban) >= 4) $tail = '••••' . substr($iban, -4);
    elseif ($account !== '' && strlen($account) >= 4) $tail = '••••' . substr($account, -4);
    $parts = array_filter([$bank, $tail]);
    return $parts ? implode(' · ', $parts) : '—';
}

/** Read payout details back, or an empty array when unavailable. */
function affiliate_read_payout_details(?string $encrypted): array {
    if (!$encrypted) return [];
    require_once __DIR__ . '/secret_crypto.php';
    $plain = secret_decrypt_value($encrypted);
    if ($plain === '') return [];
    $decoded = json_decode($plain, true);
    return is_array($decoded) ? $decoded : [];
}
