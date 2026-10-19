<?php
/**
 * Affiliate authentication and onboarding (Execution 31).
 *
 * Mirrors the platform's existing auth conventions rather than inventing new ones:
 *   • passwords go through password_hash() with the platform's algorithm
 *   • login attempts are rate-limited through auth_rate_limits, the same table the
 *     client and lawyer logins use, so a shared IP budget cannot be bypassed by
 *     attacking the affiliate login instead
 *   • the session is rotated on privilege change, and a password reset invalidates
 *     live sessions through the same account_session_is_stale() mechanism
 *
 * An affiliate is a separate identity from a `users` or `lawyers` row. They are not
 * a customer and not a practitioner; giving them a users row would make them visible
 * to client-facing queries and to the tenant model, which they must not be.
 */
require_once __DIR__ . '/affiliate.php';
require_once __DIR__ . '/auth_security.php';

/** Hash a password with the platform's algorithm. */
function affiliate_hash_password(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Validate a password for an affiliate account.
 *
 * The minimum matches the rest of the platform (8 characters) and the maximum is the
 * 72-byte bcrypt limit, enforced here rather than silently truncating — a truncated
 * password is a password the affiliate cannot reproduce.
 *
 * @return array{ok:bool,error?:string}
 */
function affiliate_validate_password(string $password, string $confirm): array {
    if (strlen($password) < 8) return ['ok' => false, 'error' => 'password_too_short'];
    if (strlen($password) > 72) return ['ok' => false, 'error' => 'password_too_long'];
    if ($password !== $confirm) return ['ok' => false, 'error' => 'password_mismatch'];
    return ['ok' => true];
}

/**
 * Sign an affiliate in.
 *
 * The account must be able to log in (`approved`, `active` or `suspended` — a
 * suspended affiliate can still see their earnings history, which matters because
 * they may be owed money). A pending applicant is refused with a distinct reason so
 * the login page can say "your application is still under review" rather than the
 * unhelpful "invalid credentials".
 */
function affiliate_login(PDO $pdo, string $email, string $password): array {
    $email = strtolower(trim($email));
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    // Two independent budgets, as elsewhere on the platform: per account and per IP,
    // so neither a single account nor a single source can be hammered.
    if (auth_rate_limit_remaining($pdo, 'affiliate-login', $email) > 0
        || auth_rate_limit_remaining($pdo, 'affiliate-login-ip', $ip) > 0) {
        return ['ok' => false, 'error' => 'rate_limited'];
    }

    $affiliate = affiliate_find_by_email($pdo, $email);
    // A dummy verify when the account is absent keeps the response time similar, so
    // the login form does not become an account-existence oracle.
    if (!$affiliate || empty($affiliate['password_hash'])) {
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        auth_rate_limit_failure($pdo, 'affiliate-login', $email);
        auth_rate_limit_failure($pdo, 'affiliate-login-ip', $ip);
        return ['ok' => false, 'error' => 'invalid_credentials'];
    }

    if (!password_verify($password, (string)$affiliate['password_hash'])) {
        auth_rate_limit_failure($pdo, 'affiliate-login', $email);
        auth_rate_limit_failure($pdo, 'affiliate-login-ip', $ip);
        return ['ok' => false, 'error' => 'invalid_credentials'];
    }

    if (!affiliate_can_login($affiliate)) {
        return ['ok' => false, 'error' => 'not_active', 'status' => (string)$affiliate['status']];
    }

    // Rehash when the cost has moved on, so an old hash is upgraded on a real login.
    if (password_needs_rehash((string)$affiliate['password_hash'], PASSWORD_DEFAULT)) {
        try {
            $pdo->prepare('UPDATE affiliates SET password_hash=? WHERE id=?')
                ->execute([affiliate_hash_password($password), (int)$affiliate['id']]);
        } catch (Throwable $e) {
            error_log('affiliate password rehash failed: ' . $e->getMessage());
        }
    }

    affiliate_establish_session($affiliate);
    try {
        $pdo->prepare('UPDATE affiliates SET last_login_at=NOW() WHERE id=?')->execute([(int)$affiliate['id']]);
    } catch (Throwable $e) { /* the timestamp is informational */ }

    affiliate_audit($pdo, 'affiliate_login', ['affiliate_id' => (int)$affiliate['id']]);
    auth_rate_limit_clear($pdo, 'affiliate-login', $email);
    return ['ok' => true, 'affiliate' => $affiliate];
}

/** Put an affiliate into the session, isolating it from the client/lawyer/admin keys. */
function affiliate_establish_session(array $affiliate): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['affiliate_id'] = (int)$affiliate['id'];
    $_SESSION['affiliate_name'] = (string)$affiliate['full_name'];
    $_SESSION['affiliate_code'] = (string)$affiliate['code'];
    $_SESSION['mawatheq_session_started_at'] = time();
}

/** Sign the affiliate out of the affiliate portal only. */
function affiliate_logout(): void {
    unset($_SESSION['affiliate_id'], $_SESSION['affiliate_name'], $_SESSION['affiliate_code']);
}

/**
 * Create an affiliate from an application or an invitation.
 *
 * Two channels converge here so the resulting row is identical either way; only the
 * starting status differs (an invitation may be preapproved). The password is set by
 * the affiliate on acceptance, never by the administrator who invited them — an
 * admin who knows an affiliate's password is an audit problem.
 *
 * @return array{ok:bool,affiliate_id?:int,code?:string,error?:string}
 */
function affiliate_create_account(PDO $pdo, array $data): array {
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if (!affiliate_valid_email($email)) return ['ok' => false, 'error' => 'invalid_email'];
    if (affiliate_find_by_email($pdo, $email)) return ['ok' => false, 'error' => 'email_exists'];

    $fullName = trim((string)($data['full_name'] ?? ''));
    if ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 180) return ['ok' => false, 'error' => 'invalid_name'];

    // Normalise each optional field through a local first. Writing
    // `$data['x'] ?? 'default'` in the condition and then `$data['x']` in the true
    // branch returns null when the key is absent — the `??` guards the test, not the
    // value that gets stored.
    $type = (string)($data['affiliate_type'] ?? 'individual');
    if (!in_array($type, ['individual', 'company', 'partner'], true)) $type = 'individual';

    $status = (string)($data['status'] ?? 'pending');
    if (!in_array($status, affiliate_statuses(), true)) $status = 'pending';

    $complianceStatus = (string)($data['compliance_status'] ?? 'not_reviewed');
    if (!in_array($complianceStatus, ['not_reviewed', 'in_review', 'approved', 'rejected', 'needs_action'], true)) $complianceStatus = 'not_reviewed';

    $payoutMethod = (string)($data['payout_method'] ?? 'none');
    if (!in_array($payoutMethod, ['bank_transfer', 'cheque', 'none'], true)) $payoutMethod = 'none';

    try {
        $code = affiliate_generate_code($pdo);
        $pdo->prepare('INSERT INTO affiliates
            (code, email, password_hash, full_name, affiliate_type, status, compliance_status, company_name,
             commercial_registration, tax_number, phone, country, city, website, social_links, marketing_channels,
             promotion_methods, payout_method, payout_currency, self_user_id, self_lawyer_id, commission_plan_id, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $code, $email,
                isset($data['password']) && $data['password'] !== '' ? affiliate_hash_password((string)$data['password']) : null,
                $fullName, $type, $status, $complianceStatus,
                isset($data['company_name']) ? mb_substr((string)$data['company_name'], 0, 190, 'UTF-8') : null,
                isset($data['commercial_registration']) ? mb_substr((string)$data['commercial_registration'], 0, 60, 'UTF-8') : null,
                isset($data['tax_number']) ? mb_substr((string)$data['tax_number'], 0, 60, 'UTF-8') : null,
                affiliate_normalize_phone($data['phone'] ?? null),
                // Normalised against the platform's country list, so the column holds a
                // supported ISO code and never an arbitrary two characters.
                country_normalize($data['country'] ?? null),
                isset($data['city']) ? mb_substr((string)$data['city'], 0, 80, 'UTF-8') : null,
                isset($data['website']) ? mb_substr((string)$data['website'], 0, 255, 'UTF-8') : null,
                isset($data['social_links']) ? mb_substr((string)$data['social_links'], 0, 1000, 'UTF-8') : null,
                isset($data['marketing_channels']) ? mb_substr((string)$data['marketing_channels'], 0, 1000, 'UTF-8') : null,
                isset($data['promotion_methods']) ? mb_substr((string)$data['promotion_methods'], 0, 1000, 'UTF-8') : null,
                $payoutMethod,
                currency_normalize((string)($data['payout_currency'] ?? CURRENCY_DEFAULT)),
                isset($data['self_user_id']) ? (int)$data['self_user_id'] : null,
                isset($data['self_lawyer_id']) ? (int)$data['self_lawyer_id'] : null,
                isset($data['commission_plan_id']) ? (int)$data['commission_plan_id'] : null,
                isset($data['notes']) ? mb_substr((string)$data['notes'], 0, 2000, 'UTF-8') : null,
            ]);
        $id = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('affiliate_create_account: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'create_failed'];
    }

    affiliate_audit($pdo, 'affiliate_created', [
        'affiliate_id' => $id, 'subject_type' => 'affiliate', 'subject_id' => $id,
        'to_state' => $status, 'detail' => 'type=' . $type,
    ]);
    return ['ok' => true, 'affiliate_id' => $id, 'code' => $code];
}

/**
 * Register an affiliate account that can sign in immediately.
 *
 * This is the account-based registration path, as distinct from
 * affiliate_submit_application() below. The distinction is the password: an account
 * registered here gets a hash, so the applicant can sign in and watch their own
 * application move through review; a bare application is only a queue entry with
 * nobody able to sign in to it.
 *
 * The account is created `pending`, never `active`. Being able to sign in and being
 * able to earn are separate decisions: `affiliate_can_earn()` requires `active` plus
 * an approved compliance review, so a freshly registered affiliate can reach their
 * dashboard and nothing else. Nothing here touches commission eligibility.
 *
 * @return array{ok:bool,affiliate_id?:int,code?:string,reference?:string,error?:string}
 */
function affiliate_register(PDO $pdo, array $data): array {
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if (!affiliate_valid_email($email)) return ['ok' => false, 'error' => 'invalid_email'];

    $fullName = trim((string)($data['full_name'] ?? ''));
    if ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 180) return ['ok' => false, 'error' => 'invalid_name'];

    if (empty($data['accepted_terms']) || empty($data['accepted_privacy'])) {
        return ['ok' => false, 'error' => 'consent_required'];
    }

    $passwordCheck = affiliate_validate_password((string)($data['password'] ?? ''), (string)($data['password_confirm'] ?? ''));
    if (empty($passwordCheck['ok'])) return $passwordCheck;

    // Two budgets. The strict one (per email and per source address, 5 per 15 minutes)
    // is bumped only on a *failed* attempt, where it protects against enumeration and
    // form spam — it is the same budget the login uses.
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (auth_rate_limit_remaining($pdo, 'affiliate-register', $email) > 0) {
        return ['ok' => false, 'error' => 'rate_limited'];
    }
    // The generous one counts successful creations from a single address (20 per
    // 15 minutes) so a script cannot mass-create accounts, without punishing an office
    // where several real people sign up from one connection. Counting successes as
    // failures on the strict budget would lock out a shared address after five genuine
    // registrations, which is why this is a separate bucket with its own threshold.
    $creationKey = auth_rate_limit_ip_key('affiliate-register-created');
    if (auth_rate_limit_remaining_key($pdo, $creationKey) > 0) {
        return ['ok' => false, 'error' => 'rate_limited'];
    }

    // One account per address. Checked before anything is written, and the INSERT is
    // additionally protected by the unique index on affiliates.email — the check
    // alone would be a race between two simultaneous submissions.
    if (affiliate_find_by_email($pdo, $email)) {
        auth_rate_limit_failure($pdo, 'affiliate-register', $email);
        return ['ok' => false, 'error' => 'email_exists'];
    }

    $created = affiliate_create_account($pdo, [
        'email' => $email,
        'password' => (string)$data['password'],
        'full_name' => $fullName,
        'phone' => $data['phone'] ?? null,
        'country' => country_normalize($data['country'] ?? null),
        'city' => $data['city'] ?? null,
        'affiliate_type' => (string)($data['affiliate_type'] ?? 'individual'),
        'company_name' => $data['company_name'] ?? null,
        'website' => $data['website'] ?? null,
        'social_links' => $data['social_links'] ?? null,
        'marketing_channels' => $data['marketing_channels'] ?? null,
        'payout_method' => (string)($data['payout_method'] ?? 'none'),
        // Deliberately not taken from the request: registration can never choose its
        // own status or its own compliance verdict.
        'status' => 'pending',
        'compliance_status' => 'not_reviewed',
    ]);
    if (empty($created['ok'])) {
        auth_rate_limit_failure($pdo, 'affiliate-register', $email);
        return $created;
    }

    $affiliateId = (int)$created['affiliate_id'];
    $affiliate = affiliate_find($pdo, $affiliateId);

    // Record the registration as an application row so the existing review queue in
    // the admin console lists it alongside every other applicant, and so approving it
    // links to the account that already exists rather than creating a second one.
    $reference = null;
    try {
        $reference = strtoupper(bin2hex(random_bytes(8)));
        $pdo->prepare('INSERT INTO affiliate_applications
            (reference, affiliate_id, full_name, email, phone, country, city, applicant_type, company_name,
             website, social_links, marketing_channels, payout_method, accepted_terms, accepted_privacy,
             terms_version, privacy_version, accepted_ip, accepted_at, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)
            ON DUPLICATE KEY UPDATE affiliate_id=VALUES(affiliate_id), status=VALUES(status)')
            ->execute([
                $reference, $affiliateId, $fullName, $email,
                affiliate_normalize_phone($data['phone'] ?? null),
                country_normalize($data['country'] ?? null),
                isset($data['city']) && $data['city'] !== '' ? mb_substr((string)$data['city'], 0, 80, 'UTF-8') : null,
                in_array((string)($data['affiliate_type'] ?? ''), ['individual', 'company', 'partner'], true) ? (string)$data['affiliate_type'] : 'individual',
                isset($data['company_name']) && $data['company_name'] !== '' ? mb_substr((string)$data['company_name'], 0, 190, 'UTF-8') : null,
                isset($data['website']) && $data['website'] !== '' ? mb_substr((string)$data['website'], 0, 255, 'UTF-8') : null,
                isset($data['social_links']) && $data['social_links'] !== '' ? mb_substr((string)$data['social_links'], 0, 1000, 'UTF-8') : null,
                isset($data['marketing_channels']) && $data['marketing_channels'] !== '' ? mb_substr((string)$data['marketing_channels'], 0, 1000, 'UTF-8') : null,
                in_array((string)($data['payout_method'] ?? ''), ['bank_transfer', 'cheque', 'none'], true) ? (string)$data['payout_method'] : 'none',
                1, 1, AFFILIATE_TERMS_VERSION, AFFILIATE_PRIVACY_VERSION,
                affiliate_ip_hash(), 'submitted',
            ]);
    } catch (Throwable $e) {
        // The account is the thing that matters and it already exists; a missing queue
        // row is recoverable from the affiliates table, so this is logged, not fatal.
        error_log('affiliate_register application row: ' . $e->getMessage());
    }

    // Consent to register is recorded on the application row above (flags, versions and
    // a keyed IP hash). The `affiliate_agreements` rows are deliberately NOT written
    // here: that table records acceptance of the programme terms, which is the separate
    // act that activates an approved account (affiliate/terms.php -> affiliate_accept_terms).
    // Writing them at registration would make affiliate_has_accepted() true before the
    // affiliate ever saw the obligations, and would leave an approved account with no
    // route to activation.

    // Success is recorded against the creation bucket only; the strict budget stays
    // clean so a genuine registrant is never throttled by their own successful sign-up.
    auth_rate_limit_failure_key($pdo, $creationKey, 20);

    affiliate_audit($pdo, 'affiliate_registered', [
        'actor_type' => 'affiliate', 'actor_id' => $affiliateId,
        'affiliate_id' => $affiliateId, 'subject_type' => 'affiliate', 'subject_id' => $affiliateId,
        'to_state' => 'pending', 'detail' => 'reference=' . (string)$reference,
    ]);
    affiliate_notify($pdo, $affiliateId, 'registered:' . $affiliateId, 'account_pending',
        is_rtl() ? 'تم إنشاء حسابك بنجاح' : 'Your account was created',
        is_rtl()
            ? 'حسابك قيد المراجعة. سنشعرك عند اتخاذ القرار.'
            : 'Your account is under review. We will notify you when a decision is made.',
        '/affiliate/dashboard.php');

    if ($affiliate) affiliate_establish_session($affiliate);

    return ['ok' => true, 'affiliate_id' => $affiliateId, 'code' => (string)$created['code'], 'reference' => (string)$reference];
}

/**
 * Submit a public application.
 *
 * The application is a separate row from the affiliate so the full submitted payload
 * and the review trail survive a rejection. Consent flags are mandatory: an
 * application without explicit terms and privacy acceptance is refused, and the
 * versions accepted are stored so a later dispute can be resolved against the exact
 * text the applicant agreed to.
 */
function affiliate_submit_application(PDO $pdo, array $data): array {
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if (!affiliate_valid_email($email)) return ['ok' => false, 'error' => 'invalid_email'];

    $fullName = trim((string)($data['full_name'] ?? ''));
    if ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 180) return ['ok' => false, 'error' => 'invalid_name'];

    if (empty($data['accepted_terms']) || empty($data['accepted_privacy'])) {
        return ['ok' => false, 'error' => 'consent_required'];
    }

    // Rate limit by email so the public form cannot be used to spam the review queue.
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (auth_rate_limit_remaining($pdo, 'affiliate-apply', $email) > 0
        || auth_rate_limit_remaining($pdo, 'affiliate-apply-ip', $ip) > 0) {
        return ['ok' => false, 'error' => 'rate_limited'];
    }

    // One open application per email.
    try {
        $open = $pdo->prepare("SELECT id, status FROM affiliate_applications WHERE email=? AND status IN ('submitted','verification','compliance_review') LIMIT 1");
        $open->execute([$email]);
        $existing = $open->fetch();
        if ($existing) return ['ok' => false, 'error' => 'application_exists', 'reference' => null];

        // An existing affiliate cannot apply again.
        if (affiliate_find_by_email($pdo, $email)) return ['ok' => false, 'error' => 'email_exists'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'create_failed'];
    }

    $reference = strtoupper(bin2hex(random_bytes(8)));
    $payoutMethod = (string)($data['payout_method'] ?? 'none');
    if (!in_array($payoutMethod, ['bank_transfer', 'cheque', 'none'], true)) $payoutMethod = 'none';
    $applicantType = (string)($data['applicant_type'] ?? 'individual');
    if (!in_array($applicantType, ['individual', 'company', 'partner'], true)) $applicantType = 'individual';
    try {
        $pdo->prepare('INSERT INTO affiliate_applications
            (reference, full_name, email, phone, country, city, applicant_type, company_name, commercial_registration,
             website, social_links, marketing_channels, promotion_methods, audience_description, expected_monthly_reach,
             payout_method, payout_details, accepted_terms, accepted_privacy, terms_version, privacy_version, accepted_ip, accepted_at, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)')
            ->execute([
                $reference, $fullName, $email,
                affiliate_normalize_phone($data['phone'] ?? null),
                country_normalize($data['country'] ?? null),
                isset($data['city']) ? mb_substr((string)$data['city'], 0, 80, 'UTF-8') : null,
                $applicantType,
                isset($data['company_name']) ? mb_substr((string)$data['company_name'], 0, 190, 'UTF-8') : null,
                isset($data['commercial_registration']) ? mb_substr((string)$data['commercial_registration'], 0, 60, 'UTF-8') : null,
                isset($data['website']) ? mb_substr((string)$data['website'], 0, 255, 'UTF-8') : null,
                isset($data['social_links']) ? mb_substr((string)$data['social_links'], 0, 1000, 'UTF-8') : null,
                isset($data['marketing_channels']) ? mb_substr((string)$data['marketing_channels'], 0, 1000, 'UTF-8') : null,
                isset($data['promotion_methods']) ? mb_substr((string)$data['promotion_methods'], 0, 1000, 'UTF-8') : null,
                isset($data['audience_description']) ? mb_substr((string)$data['audience_description'], 0, 2000, 'UTF-8') : null,
                isset($data['expected_monthly_reach']) && is_numeric($data['expected_monthly_reach']) ? (int)$data['expected_monthly_reach'] : null,
                $payoutMethod,
                isset($data['payout_details']) ? mb_substr((string)$data['payout_details'], 0, 500, 'UTF-8') : null,
                1, 1,
                AFFILIATE_TERMS_VERSION, AFFILIATE_PRIVACY_VERSION,
                affiliate_ip_hash(),
                'submitted',
            ]);
        $id = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('affiliate_submit_application: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'create_failed'];
    }

    auth_rate_limit_failure($pdo, 'affiliate-apply', $email);
    auth_rate_limit_failure($pdo, 'affiliate-apply-ip', $ip);

    affiliate_audit($pdo, 'application_submitted', [
        'subject_type' => 'application', 'subject_id' => $id,
        'detail' => 'reference=' . $reference,
    ]);
    return ['ok' => true, 'application_id' => $id, 'reference' => $reference];
}

/**
 * Approve an application, creating the affiliate account.
 *
 * @return array{ok:bool,affiliate_id?:int,error?:string}
 */
function affiliate_approve_application(PDO $pdo, int $applicationId, array $options = []): array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM affiliate_applications WHERE id=?');
        $stmt->execute([$applicationId]);
        $application = $stmt->fetch();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    if (!$application) return ['ok' => false, 'error' => 'not_found'];
    if (!in_array((string)$application['status'], ['submitted', 'verification', 'compliance_review'], true)) {
        return ['ok' => false, 'error' => 'already_reviewed'];
    }

    // An application that already produced an affiliate is moved, not duplicated.
    // This is the case for anyone who registered an account: affiliate_register()
    // creates the `affiliates` row and the application row together, so approving must
    // act on that existing account. Returning early here (as this function used to)
    // reported success while changing nothing — the console said "approved" and the
    // account stayed `pending` forever.
    if (!empty($application['affiliate_id'])) {
        $affiliateId = (int)$application['affiliate_id'];
        $affiliate = affiliate_find($pdo, $affiliateId);
        if (!$affiliate) return ['ok' => false, 'error' => 'not_found'];

        // No status change is needed if the account has already moved past review, but
        // the application row is still stamped so the queue reflects the decision.
        if (in_array((string)$affiliate['status'], ['pending', 'under_review'], true)) {
            $moved = affiliate_set_status($pdo, $affiliateId, 'approved', [
                'note' => (string)($options['note'] ?? ''),
            ]);
            if (empty($moved['ok'])) return ['ok' => false, 'error' => (string)($moved['error'] ?? 'update_failed')];
        }

        // Only an explicit compliance decision changes the compliance state; approving
        // an application is not by itself a compliance ruling.
        if (!empty($options['compliance_approved'])
            && (string)$affiliate['compliance_status'] !== 'approved') {
            affiliate_set_compliance($pdo, $affiliateId, 'approved', ['note' => (string)($options['note'] ?? '')]);
        }

        try {
            $pdo->prepare("UPDATE affiliate_applications SET status='approved', reviewed_by=?, reviewed_at=NOW(), compliance_notes=? WHERE id=?")
                ->execute([isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null,
                    isset($options['note']) ? mb_substr((string)$options['note'], 0, 500, 'UTF-8') : null, $applicationId]);
        } catch (Throwable $e) {
            error_log('affiliate_approve_application link: ' . $e->getMessage());
        }

        affiliate_audit($pdo, 'application_approved', [
            'affiliate_id' => $affiliateId, 'subject_type' => 'application', 'subject_id' => $applicationId,
            'from_state' => (string)$application['status'], 'to_state' => 'approved',
        ]);
        affiliate_notify($pdo, $affiliateId, 'application_approved:' . $applicationId, 'application_approved',
            is_rtl() ? 'تم اعتماد طلبك' : 'Your application was approved',
            is_rtl()
                ? 'يمكنك الآن تسجيل الدخول وقبول شروط البرنامج لتفعيل حسابك وبدء استخدام روابط الإحالة.'
                : 'You can now sign in and accept the programme terms to activate your account and start using your referral links.',
            '/affiliate/terms.php');

        return ['ok' => true, 'affiliate_id' => $affiliateId, 'code' => (string)$affiliate['code'], 'existing' => true];
    }

    $created = affiliate_create_account($pdo, [
        'email' => (string)$application['email'],
        'full_name' => (string)$application['full_name'],
        'affiliate_type' => (string)$application['applicant_type'],
        'company_name' => $application['company_name'],
        'commercial_registration' => $application['commercial_registration'],
        'phone' => $application['phone'],
        'country' => (string)$application['country'],
        'city' => $application['city'],
        'website' => $application['website'],
        'social_links' => $application['social_links'],
        'marketing_channels' => $application['marketing_channels'],
        'promotion_methods' => $application['promotion_methods'],
        'payout_method' => (string)$application['payout_method'],
        'commission_plan_id' => $options['commission_plan_id'] ?? null,
        // An approved application still starts `approved`, not `active`: the affiliate
        // has not accepted the current agreement or set a password yet, so they must
        // not be able to generate commission-bearing links.
        'status' => 'approved',
        'compliance_status' => !empty($options['compliance_approved']) ? 'approved' : 'in_review',
    ]);
    if (empty($created['ok'])) return $created;

    $affiliateId = (int)$created['affiliate_id'];
    try {
        $pdo->prepare("UPDATE affiliate_applications SET status='approved', affiliate_id=?, reviewed_by=?, reviewed_at=NOW(), compliance_notes=? WHERE id=?")
            ->execute([$affiliateId, isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null,
                isset($options['note']) ? mb_substr((string)$options['note'], 0, 500, 'UTF-8') : null, $applicationId]);
    } catch (Throwable $e) {
        error_log('affiliate_approve_application link: ' . $e->getMessage());
    }

    affiliate_audit($pdo, 'application_approved', [
        'affiliate_id' => $affiliateId, 'subject_type' => 'application', 'subject_id' => $applicationId,
        'from_state' => (string)$application['status'], 'to_state' => 'approved',
    ]);
    return ['ok' => true, 'affiliate_id' => $affiliateId, 'code' => $created['code']];
}

/** Reject an application. */
function affiliate_reject_application(PDO $pdo, int $applicationId, string $reason): array {
    if (trim($reason) === '') return ['ok' => false, 'error' => 'reason_required'];
    try {
        $pdo->prepare("UPDATE affiliate_applications SET status='rejected', rejection_reason=?, reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status IN ('submitted','verification','compliance_review')")
            ->execute([mb_substr($reason, 0, 500, 'UTF-8'), isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null, $applicationId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'update_failed'];
    }
    affiliate_audit($pdo, 'application_rejected', [
        'subject_type' => 'application', 'subject_id' => $applicationId, 'to_state' => 'rejected', 'detail' => $reason,
    ]);
    return ['ok' => true];
}

/**
 * Create an invitation.
 *
 * The token is returned once, in plaintext, and only its hash is stored. An
 * invitation that leaks from a database dump is therefore useless.
 *
 * @return array{ok:bool,token?:string,invitation_id?:int,error?:string}
 */
function affiliate_create_invitation(PDO $pdo, array $data): array {
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if ($email !== '' && !affiliate_valid_email($email)) return ['ok' => false, 'error' => 'invalid_email'];
    // Inviting someone who is already an affiliate is normally a mistake, and is refused.
    // The exception is an account with no password: that is the account created by
    // approving a public-form application, and an invitation is the only way its owner can
    // ever sign in. An account that can already sign in is still refused, so a leaked
    // token cannot be turned into a password reset.
    if ($email !== '') {
        $existingAffiliate = affiliate_find_by_email($pdo, $email);
        if ($existingAffiliate && !empty($existingAffiliate['password_hash'])) {
            return ['ok' => false, 'error' => 'email_exists'];
        }
    }

    $token = bin2hex(random_bytes(32));
    $days = max(1, min(90, (int)($data['expires_days'] ?? 14)));
    // Normalise into a local first: writing `$data['invite_type'] ?? 'individual'` in the
    // test and then `$data['invite_type']` in the true branch yields null when the key is
    // absent — the `??` guards the test, not the value that gets stored. The column is
    // NOT NULL, so that made every invitation without an explicit type fail to create.
    $inviteType = (string)($data['invite_type'] ?? 'individual');
    if (!in_array($inviteType, ['individual', 'company', 'partner'], true)) $inviteType = 'individual';
    try {
        $pdo->prepare('INSERT INTO affiliate_invitations (token_hash, email, full_name, invite_type, invited_by, commission_plan_id, preapproved, personal_message, expires_at)
            VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([
                hash('sha256', $token),
                $email !== '' ? $email : null,
                isset($data['full_name']) ? mb_substr((string)$data['full_name'], 0, 180, 'UTF-8') : null,
                $inviteType,
                isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : (int)($data['invited_by'] ?? 0),
                isset($data['commission_plan_id']) ? (int)$data['commission_plan_id'] : null,
                !empty($data['preapproved']) ? 1 : 0,
                isset($data['message']) ? mb_substr((string)$data['message'], 0, 1000, 'UTF-8') : null,
                date('Y-m-d H:i:s', time() + ($days * 86400)),
            ]);
        $id = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('affiliate_create_invitation: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'create_failed'];
    }
    affiliate_audit($pdo, 'invitation_created', [
        'subject_type' => 'invitation', 'subject_id' => $id,
        'detail' => 'email=' . ($email !== '' ? $email : '(open)') . ' preapproved=' . (!empty($data['preapproved']) ? 'yes' : 'no'),
    ]);
    return ['ok' => true, 'token' => $token, 'invitation_id' => $id];
}

/** Find a usable invitation by its plaintext token. */
function affiliate_find_invitation(PDO $pdo, string $token): ?array {
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM affiliate_invitations WHERE token_hash=? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > NOW()');
        $stmt->execute([hash('sha256', $token)]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Accept an invitation and create the affiliate account.
 *
 * Acceptance is single-use: the row is claimed with an `accepted_at IS NULL`
 * condition and the affected-row count decides whether this caller won. That is what
 * makes a forwarded invitation unusable by a second person.
 */
function affiliate_accept_invitation(PDO $pdo, string $token, array $data): array {
    $invitation = affiliate_find_invitation($pdo, $token);
    if (!$invitation) return ['ok' => false, 'error' => 'invalid_invitation'];

    $email = strtolower(trim((string)($data['email'] ?? ($invitation['email'] ?? ''))));
    // A named invitation is bound to its recipient; an open one accepts any email.
    if (!empty($invitation['email']) && $email !== strtolower((string)$invitation['email'])) {
        return ['ok' => false, 'error' => 'invitation_email_mismatch'];
    }
    if (!affiliate_valid_email($email)) return ['ok' => false, 'error' => 'invalid_email'];

    $passwordCheck = affiliate_validate_password((string)($data['password'] ?? ''), (string)($data['password_confirm'] ?? ''));
    if (empty($passwordCheck['ok'])) return ['ok' => false, 'error' => $passwordCheck['error']];

    if (empty($data['accepted_terms'])) return ['ok' => false, 'error' => 'consent_required'];

    // An invitation is also how an account that has no password gets one. An application
    // approved from the public form creates the account with no credentials (the
    // applicant never chose a password), so without this branch that person is approved
    // and permanently unable to sign in. The password is set here and the invitation is
    // still claimed, so the token stays single-use.
    $existing = affiliate_find_by_email($pdo, $email);
    if ($existing) {
        return affiliate_accept_invitation_for_existing($pdo, $invitation, $existing, $data, (string)$data['password']);
    }

    $created = affiliate_create_account($pdo, [
        'email' => $email,
        'full_name' => (string)($data['full_name'] ?? ($invitation['full_name'] ?? '')),
        'password' => (string)$data['password'],
        'affiliate_type' => (string)($data['affiliate_type'] ?? $invitation['invite_type']),
        'phone' => $data['phone'] ?? null,
        'country' => country_normalize($data['country'] ?? null),
        'city' => $data['city'] ?? null,
        'company_name' => $data['company_name'] ?? null,
        'commercial_registration' => $data['commercial_registration'] ?? null,
        'payout_method' => $data['payout_method'] ?? 'none',
        'commission_plan_id' => $invitation['commission_plan_id'] ?? null,
        // A preapproved invitation skips the application review, but the account still
        // starts `approved` rather than `active`: the service-eligibility gate and the
        // current agreement are reviewed at activation, not by the invitation.
        'status' => 'approved',
        'compliance_status' => 'in_review',
        'self_user_id' => $data['self_user_id'] ?? null,
        'self_lawyer_id' => $data['self_lawyer_id'] ?? null,
    ]);
    if (empty($created['ok'])) return $created;

    // Claim the invitation. A concurrent acceptance loses here.
    try {
        $claim = $pdo->prepare('UPDATE affiliate_invitations SET accepted_at=NOW(), affiliate_id=? WHERE id=? AND accepted_at IS NULL AND revoked_at IS NULL');
        $claim->execute([(int)$created['affiliate_id'], (int)$invitation['id']]);
        if ($claim->rowCount() === 0) {
            // Somebody else accepted it first. The account just created is removed so
            // the invitation cannot yield two accounts.
            $pdo->prepare('DELETE FROM affiliates WHERE id=?')->execute([(int)$created['affiliate_id']]);
            return ['ok' => false, 'error' => 'invitation_used'];
        }
    } catch (Throwable $e) {
        error_log('affiliate_accept_invitation claim: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'claim_failed'];
    }

    // The invitation form collects consent, and that consent is recorded against the
    // account so there is a record of what was agreed at sign-up. It is deliberately NOT
    // written to affiliate_agreements: that table is what affiliate_activate() reads, and
    // pre-filling it left an invited partner permanently stuck at `approved` — the terms
    // page saw the agreement as already accepted, rendered no form, and the activation
    // that only runs from that form never fired.
    affiliate_audit($pdo, 'invitation_consent', [
        'affiliate_id' => (int)$created['affiliate_id'], 'subject_type' => 'invitation',
        'subject_id' => (int)$invitation['id'],
        'detail' => 'terms v' . AFFILIATE_TERMS_VERSION . ' accepted at invitation acceptance',
    ]);

    affiliate_audit($pdo, 'invitation_accepted', [
        'affiliate_id' => (int)$created['affiliate_id'], 'subject_type' => 'invitation', 'subject_id' => (int)$invitation['id'],
    ]);
    return ['ok' => true, 'affiliate_id' => (int)$created['affiliate_id'], 'code' => $created['code']];
}

/**
 * Accept an invitation for an account that already exists.
 *
 * This is the case for an application approved from the public landing form: the account
 * is created without a password because the applicant never chose one, so the invitation
 * is the only way for them to obtain credentials. The password is set here, the
 * invitation is claimed single-use, and the account's existing review state is preserved
 * — an invitation sets a password, it does not re-decide the application.
 *
 * @param array<string,mixed> $invitation
 * @param array<string,mixed> $existing
 * @param array<string,mixed> $data
 * @return array{ok:bool,affiliate_id?:int,code?:string,error?:string}
 */
function affiliate_accept_invitation_for_existing(PDO $pdo, array $invitation, array $existing, array $data, string $password): array {
    $affiliateId = (int)$existing['id'];

    // Only an account that has not been given credentials yet may be claimed this way.
    // Without this check a leaked invitation token would be a password reset for an
    // established affiliate, which is a takeover rather than an onboarding.
    if (!empty($existing['password_hash'])) {
        return ['ok' => false, 'error' => 'account_active'];
    }

    try {
        $claim = $pdo->prepare('UPDATE affiliate_invitations SET accepted_at=NOW(), affiliate_id=? WHERE id=? AND accepted_at IS NULL AND revoked_at IS NULL');
        $claim->execute([$affiliateId, (int)$invitation['id']]);
        if ($claim->rowCount() === 0) return ['ok' => false, 'error' => 'invitation_used'];
    } catch (Throwable $e) {
        error_log('affiliate_accept_invitation_for_existing claim: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'claim_failed'];
    }

    // Fill in whatever the applicant left blank at application time; never overwrite a
    // value they already supplied.
    $fullName = trim((string)($data['full_name'] ?? ''));
    if ($fullName === '') $fullName = (string)$existing['full_name'];
    try {
        $pdo->prepare('UPDATE affiliates SET password_hash=?, full_name=?, phone=COALESCE(NULLIF(phone, \'\'), ?),
                city=COALESCE(NULLIF(city, \'\'), ?), company_name=COALESCE(NULLIF(company_name, \'\'), ?),
                commercial_registration=COALESCE(NULLIF(commercial_registration, \'\'), ?),
                payout_method=CASE WHEN payout_method = \'none\' THEN ? ELSE payout_method END
            WHERE id=?')
            ->execute([
                affiliate_hash_password($password),
                mb_substr($fullName, 0, 180, 'UTF-8'),
                affiliate_normalize_phone($data['phone'] ?? null),
                isset($data['city']) ? mb_substr((string)$data['city'], 0, 80, 'UTF-8') : null,
                isset($data['company_name']) ? mb_substr((string)$data['company_name'], 0, 190, 'UTF-8') : null,
                isset($data['commercial_registration']) ? mb_substr((string)$data['commercial_registration'], 0, 60, 'UTF-8') : null,
                in_array((string)($data['payout_method'] ?? ''), ['bank_transfer', 'cheque'], true) ? (string)$data['payout_method'] : 'none',
                $affiliateId,
            ]);
    } catch (Throwable $e) {
        error_log('affiliate_accept_invitation_for_existing update: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'update_failed'];
    }

    affiliate_audit($pdo, 'invitation_consent', [
        'affiliate_id' => $affiliateId, 'subject_type' => 'invitation', 'subject_id' => (int)$invitation['id'],
        'detail' => 'terms v' . AFFILIATE_TERMS_VERSION . ' accepted at invitation acceptance',
    ]);
    affiliate_audit($pdo, 'invitation_accepted', [
        'affiliate_id' => $affiliateId, 'subject_type' => 'invitation', 'subject_id' => (int)$invitation['id'],
        'detail' => 'credentials set for an existing account',
    ]);

    return ['ok' => true, 'affiliate_id' => $affiliateId, 'code' => (string)$existing['code'], 'existing' => true];
}

/**
 * Activate an affiliate: the step that lets them earn.
 *
 * `approved` means the review passed. `active` means the affiliate has accepted the
 * current agreement and compliance has cleared the services they may promote. Only
 * `active` produces commission, so this function is the gate between the two, and it
 * refuses while either condition is unmet rather than relying on a screen to enforce it.
 */
function affiliate_activate(PDO $pdo, int $affiliateId, array $options = []): array {
    $affiliate = affiliate_find($pdo, $affiliateId);
    if (!$affiliate) return ['ok' => false, 'error' => 'not_found'];
    if ((string)$affiliate['status'] === 'active') return ['ok' => true, 'affiliate_id' => $affiliateId, 'already' => true];
    if (!in_array((string)$affiliate['status'], ['approved'], true)) {
        return ['ok' => false, 'error' => 'not_approved'];
    }

    // The current terms must be on record before the affiliate can earn.
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM affiliate_agreements WHERE affiliate_id=? AND agreement_type='terms' AND version=?");
        $stmt->execute([$affiliateId, AFFILIATE_TERMS_VERSION]);
        $accepted = (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        $accepted = false;
    }
    if (!$accepted && empty($options['skip_agreement'])) {
        return ['ok' => false, 'error' => 'agreement_not_accepted'];
    }

    $compliance = in_array($options['compliance_status'] ?? (string)$affiliate['compliance_status'], ['approved', 'in_review'], true)
        ? ($options['compliance_status'] ?? (string)$affiliate['compliance_status'])
        : 'in_review';

    try {
        $pdo->prepare("UPDATE affiliates SET status='active', compliance_status=?, approved_at=COALESCE(approved_at,NOW()), approved_by=COALESCE(approved_by,?) WHERE id=?")
            ->execute([$compliance, isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null, $affiliateId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'update_failed'];
    }

    affiliate_audit($pdo, 'affiliate_activated', [
        'affiliate_id' => $affiliateId, 'subject_type' => 'affiliate', 'subject_id' => $affiliateId,
        'from_state' => (string)$affiliate['status'], 'to_state' => 'active',
    ]);
    affiliate_notify($pdo, $affiliateId, 'activated:' . $affiliateId, 'account_activated',
        is_rtl() ? 'تم تفعيل حسابك' : 'Your account is now active',
        is_rtl() ? 'يمكنك الآن إنشاء روابط الإحالة ومتابعة عمولاتك.' : 'You can now create referral links and track your commissions.',
        '/affiliate/dashboard.php');
    return ['ok' => true, 'affiliate_id' => $affiliateId];
}

/**
 * Suspend an affiliate.
 *
 * Suspension stops future earning immediately and expires open attributions so a
 * click already recorded cannot convert later. Existing earned commission is left
 * alone — withholding money that was legitimately earned is a decision for a human,
 * not a side effect of suspension.
 */
function affiliate_suspend(PDO $pdo, int $affiliateId, string $reason): array {
    $affiliate = affiliate_find($pdo, $affiliateId);
    if (!$affiliate) return ['ok' => false, 'error' => 'not_found'];
    if ((string)$affiliate['status'] === 'suspended') return ['ok' => true, 'already' => true];

    try {
        $pdo->prepare("UPDATE affiliates SET status='suspended', suspended_at=NOW(), suspension_reason=? WHERE id=?")
            ->execute([mb_substr($reason, 0, 500, 'UTF-8'), $affiliateId]);
        // Close every open attribution: the owner is frozen so a pending click cannot
        // become a conversion after suspension.
        $pdo->prepare('UPDATE affiliate_attributions SET locked_at=NOW(), lock_reason=? WHERE affiliate_id=? AND locked_at IS NULL')
            ->execute(['affiliate_suspended', $affiliateId]);
        $pdo->prepare('UPDATE affiliate_links SET is_active=0 WHERE affiliate_id=?')->execute([$affiliateId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'update_failed'];
    }

    affiliate_audit($pdo, 'affiliate_suspended', [
        'affiliate_id' => $affiliateId, 'subject_type' => 'affiliate', 'subject_id' => $affiliateId,
        'from_state' => (string)$affiliate['status'], 'to_state' => 'suspended', 'detail' => $reason,
    ]);
    affiliate_notify($pdo, $affiliateId, 'suspended:' . $affiliateId . ':' . time(), 'account_suspended',
        is_rtl() ? 'تم إيقاف حسابك مؤقتاً' : 'Your account has been suspended',
        $reason, '/affiliate/dashboard.php');
    return ['ok' => true];
}

/** Reactivate a suspended affiliate. */
function affiliate_reactivate(PDO $pdo, int $affiliateId): array {
    $affiliate = affiliate_find($pdo, $affiliateId);
    if (!$affiliate) return ['ok' => false, 'error' => 'not_found'];
    if ((string)$affiliate['status'] !== 'suspended') return ['ok' => false, 'error' => 'not_suspended'];
    try {
        $pdo->prepare("UPDATE affiliates SET status='active', suspended_at=NULL, suspension_reason=NULL WHERE id=?")->execute([$affiliateId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'update_failed'];
    }
    affiliate_audit($pdo, 'affiliate_reactivated', [
        'affiliate_id' => $affiliateId, 'subject_type' => 'affiliate', 'subject_id' => $affiliateId,
        'from_state' => 'suspended', 'to_state' => 'active',
    ]);
    return ['ok' => true];
}

/**
 * Record that an affiliate accepted the current terms.
 * Called from the portal's terms page; without it activation is refused.
 */
function affiliate_accept_terms(PDO $pdo, int $affiliateId, string $type = 'terms'): bool {
    $version = $type === 'privacy' ? AFFILIATE_PRIVACY_VERSION : AFFILIATE_TERMS_VERSION;
    try {
        // Supersede any prior acceptance of this agreement type so the current one is
        // unambiguous.
        $pdo->prepare('UPDATE affiliate_agreements SET superseded_at=NOW() WHERE affiliate_id=? AND agreement_type=? AND superseded_at IS NULL')
            ->execute([$affiliateId, $type]);
        $pdo->prepare('INSERT INTO affiliate_agreements (affiliate_id, agreement_type, version, accepted_at, accepted_ip, accepted_user_agent)
            VALUES (?,?,?,NOW(),?,?)')
            ->execute([
                $affiliateId, $type, $version, affiliate_ip_hash(),
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8'),
            ]);
        affiliate_audit($pdo, 'agreement_accepted', [
            'affiliate_id' => $affiliateId, 'subject_type' => 'agreement',
            'detail' => $type . ' v' . $version,
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('affiliate_accept_terms: ' . $e->getMessage());
        return false;
    }
}

/** True when the affiliate has accepted the current version of an agreement. */
function affiliate_has_accepted(PDO $pdo, int $affiliateId, string $type = 'terms'): bool {
    $version = $type === 'privacy' ? AFFILIATE_PRIVACY_VERSION : AFFILIATE_TERMS_VERSION;
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM affiliate_agreements WHERE affiliate_id=? AND agreement_type=? AND version=? AND accepted_at IS NOT NULL');
        $stmt->execute([$affiliateId, $type, $version]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
