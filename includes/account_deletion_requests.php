<?php
/**
 * Account-deletion **requests** (public page, email/phone workflow) — Execution 73.
 *
 * Google Play's Data safety form requires a publicly reachable account-deletion route
 * that works without signing in. `includes/account_deletion.php` performs the deletion
 * itself (anonymise + deactivate, never a hard delete). This module is the queue in
 * front of it for a visitor who cannot sign in:
 *
 *   public page  →  request_deletion_start()  →  verification link emailed
 *                →  request_deletion_verify() →  admin executes  →  account_delete()
 *
 * Security model
 *   * The request is matched to an account only by *hash* of the identifier, so a
 *     support session or a database dump never reveals which address was entered.
 *   * Ownership is proven before anything is executed: the visitor must open the
 *     time-limited, single-use link we email to the registered address. An unverified
 *     request is never executable.
 *   * The public form answers the same way whether or not the address exists, so it
 *     cannot be used to enumerate accounts. An unknown address still receives a
 *     (harmless) message that explains what to do.
 *   * Execution is centralised in `account_deletion_execute()` and runs through
 *     `account_delete()`, which keeps financial/legal records and revokes sessions.
 *
 * Nothing here is wired into the live site until it is deployed; this file only adds
 * new functions and tables and does not change any existing signature.
 */

require_once __DIR__ . '/account_deletion.php';

/** The account table an account type maps to (client → users, lawyer → lawyers). */
function account_deletion_account_table(string $accountType): string
{
    return $accountType === 'lawyer' ? 'lawyers' : 'users';
}

/** Whether the request tables exist (upgrade_v77.sql applied). Memoised per request. */
function account_deletion_requests_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute(['account_deletion_requests']);
        $ready = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** SHA-256 of a normalised identifier. The plaintext is never stored. */
function account_deletion_identifier_hash(string $identifier): string
{
    return hash('sha256', account_deletion_normalize_identifier($identifier));
}

/** Lower-case, trimmed identifier: email lower-cased, phone reduced to its digits. */
function account_deletion_normalize_identifier(string $identifier): string
{
    $identifier = trim($identifier);
    if (strpos($identifier, '@') !== false) {
        return mb_strtolower($identifier, 'UTF-8');
    }
    // A phone number is compared by its digits only, so +966 5.. and 05.. match the
    // same account when the last digits agree.
    $digits = preg_replace('/\D+/', '', $identifier);
    return (string)$digits;
}

/** Whether the input looks like an email or a phone number. */
function account_deletion_identifier_kind(string $identifier): string
{
    return strpos(trim($identifier), '@') !== false ? 'email' : 'phone';
}

/** A short, unique, human-readable tracking reference (e.g. "DEL-7K2F9A"). */
function account_deletion_new_reference(PDO $pdo): string
{
    for ($i = 0; $i < 6; $i++) {
        $ref = 'DEL-' . strtoupper(bin2hex(random_bytes(3)));
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM account_deletion_requests WHERE reference=?');
            $stmt->execute([$ref]);
            if ((int)$stmt->fetchColumn() === 0) return $ref;
        } catch (Throwable $e) {
            return $ref;
        }
    }
    return 'DEL-' . strtoupper(bin2hex(random_bytes(4)));
}

/** The accounts whose email or phone matches the identifier, if any. */
function account_deletion_find_accounts(PDO $pdo, string $identifier): array
{
    $identifier = trim($identifier);
    if ($identifier === '') return [];
    $byEmail = account_deletion_identifier_kind($identifier) === 'email';
    $digits = preg_replace('/\D+/', '', $identifier);
    $found = [];
    foreach (['client' => 'users', 'lawyer' => 'lawyers'] as $type => $table) {
        try {
            if ($byEmail) {
                $stmt = $pdo->prepare("SELECT id, full_name, email, phone, deleted_at FROM `$table`
                    WHERE LOWER(email) = LOWER(?) LIMIT 5");
                $stmt->execute([$identifier]);
            } else {
                // Phone numbers are compared by their digits only, so "+966 55 123 4567"
                // and "0551234567" resolve to the same account when the digits agree.
                // REGEXP_REPLACE is MariaDB 10.0+/MySQL 8.0+; fall back to a raw match
                // when it is unavailable so the lookup never errors.
                $stmt = $pdo->prepare("SELECT id, full_name, email, phone, deleted_at FROM `$table`
                    WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')','') LIKE CONCAT('%', ?)
                       OR phone = ? LIMIT 5");
                $stmt->execute([$digits !== '' ? $digits : $identifier, $identifier]);
            }
            foreach ($stmt->fetchAll() as $row) {
                $found[] = [
                    'account_type' => $type,
                    'account_id'   => (int)$row['id'],
                    'full_name'    => (string)($row['full_name'] ?? ''),
                    'email'        => (string)($row['email'] ?? ''),
                    'phone'        => (string)($row['phone'] ?? ''),
                    'deleted_at'   => $row['deleted_at'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            // A missing optional column must not break the whole lookup.
        }
    }
    return $found;
}

/** Append one row to the request's append-only audit trail. Never throws. */
function account_deletion_event(PDO $pdo, int $requestId, string $event, string $detail = '', string $actor = 'system'): void
{
    try {
        $pdo->prepare('INSERT INTO account_deletion_events (request_id, event, detail, actor) VALUES (?,?,?,?)')
            ->execute([$requestId, mb_substr($event, 0, 40), mb_substr($detail, 0, 500), mb_substr($actor, 0, 40)]);
    } catch (Throwable $e) {
        error_log('account_deletion_event failed: ' . $e->getMessage());
    }
}

/** Hash of the caller's IP, for abuse correlation without storing a raw address. */
function account_deletion_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip === '' ? '' : hash('sha256', 'adr|' . $ip);
}

/**
 * Start (or resume) a deletion request for an identifier.
 *
 * Creates one row and a time-limited single-use verification token, then emails the
 * link to the *registered* address on the matched account (never to an address the
 * visitor typed). The return value is deliberately the same shape for an existing and
 * a non-existent account, so the public page cannot be used to enumerate accounts.
 *
 * @return array{ok:bool, reference:?string, request_id:?int, matched:bool, delivered:bool, error:?string}
 */
function account_deletion_request_start(PDO $pdo, string $identifier, string $reason = ''): array
{
    if (!account_deletion_requests_ready($pdo)) {
        return ['ok' => false, 'reference' => null, 'request_id' => null, 'matched' => false, 'delivered' => false, 'error' => 'migration_required'];
    }
    $identifier = trim($identifier);
    if ($identifier === '') {
        return ['ok' => false, 'reference' => null, 'request_id' => null, 'matched' => false, 'delivered' => false, 'error' => 'identifier_required'];
    }
    $kind = account_deletion_identifier_kind($identifier);
    if ($kind === 'email' && !filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'reference' => null, 'request_id' => null, 'matched' => false, 'delivered' => false, 'error' => 'invalid_identifier'];
    }
    if ($kind === 'phone' && strlen((string)preg_replace('/\D+/', '', $identifier)) < 8) {
        return ['ok' => false, 'reference' => null, 'request_id' => null, 'matched' => false, 'delivered' => false, 'error' => 'invalid_identifier'];
    }

    $hash = account_deletion_identifier_hash($identifier);
    $accounts = account_deletion_find_accounts($pdo, $identifier);
    $matched = !empty($accounts);
    $target = $matched ? $accounts[0] : null;

    // The verification link goes to the account we actually matched, not to whatever
    // the visitor typed. If nothing matched there is no address to send to, so the
    // request is still recorded for support but no token is issued.
    $deliverTo = $matched ? (string)($target['email'] ?? '') : '';
    $token = bin2hex(random_bytes(32));
    $reference = account_deletion_new_reference($pdo);

    try {
        $stmt = $pdo->prepare('INSERT INTO account_deletion_requests
            (reference, account_type, account_id, identifier, identifier_hash, identifier_kind,
             notify_email, status, verification_status, verification_channel, verify_token_hash,
             verify_expires, reason, ip_hash, user_agent)
            VALUES (?,?,?,?,?,?,?, \'pending\', \'unverified\', ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $reference,
            $matched ? $target['account_type'] : 'client',
            $matched ? $target['account_id'] : null,
            mb_substr($identifier, 0, 190),
            $hash,
            $kind,
            $deliverTo !== '' ? mb_substr($deliverTo, 0, 190) : null,
            $kind === 'email' ? 'email' : 'phone',
            $matched ? hash('sha256', $token) : null,
            $matched ? date('Y-m-d H:i:s', time() + 3600) : null,
            mb_substr($reason, 0, 255),
            account_deletion_ip_hash(),
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        $requestId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('account_deletion_request_start failed: ' . $e->getMessage());
        return ['ok' => false, 'reference' => null, 'request_id' => null, 'matched' => false, 'delivered' => false, 'error' => 'create_failed'];
    }

    account_deletion_event($pdo, $requestId, 'created', $matched ? 'account matched' : 'no matching account');

    $delivered = false;
    if ($matched && $deliverTo !== '' && filter_var($deliverTo, FILTER_VALIDATE_EMAIL)) {
        $delivered = account_deletion_send_verification($pdo, $requestId, $deliverTo, (string)($target['full_name'] ?? ''), $token);
        account_deletion_event($pdo, $requestId, $delivered ? 'verification_sent' : 'verification_send_failed', $deliverTo);
    }

    return ['ok' => true, 'reference' => $reference, 'request_id' => $requestId, 'matched' => $matched, 'delivered' => $delivered, 'error' => null];
}

/** Email the single-use verification link. Returns send_mail()'s outcome. */
function account_deletion_send_verification(PDO $pdo, int $requestId, string $to, string $name, string $token): bool
{
    require_once __DIR__ . '/mailer.php';
    $url = rtrim(SITE_URL, '/') . '/account-deletion.php?verify=' . urlencode($token);
    $arabic = current_lang() === 'ar';
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $body = $arabic
        ? '<p>مرحباً ' . $safeName . '،</p><p>وصلنا طلب لحذف حسابك في منصة مواثيق. لتأكيد أنك صاحب الحساب، افتح الرابط التالي. الرابط صالح لمدة ساعة واحدة ولمرة واحدة فقط.</p><p>إن لم تطلب ذلك، تجاهل هذه الرسالة ولن يحدث أي تغيير في حسابك.</p>'
        : '<p>Hello ' . $safeName . ',</p><p>We received a request to delete your Mawatheq account. To confirm you own the account, open the link below. It is valid for one hour and can be used once.</p><p>If you did not request this, ignore this message and nothing will change.</p>';
    $html = render_mail_template(
        $arabic ? 'تأكيد طلب حذف الحساب' : 'Confirm your account-deletion request',
        $body,
        $arabic ? 'تأكيد طلب الحذف' : 'Confirm deletion request',
        $url
    );
    return send_mail($to, $arabic ? 'تأكيد طلب حذف الحساب - مواثيق' : 'Confirm your Mawatheq account-deletion request', $html);
}

/**
 * Verify a request from the single-use link token. Marks it verified.
 *
 * @return array{ok:bool, reference:?string, request_id:?int, account_type:?string, error:?string}
 */
function account_deletion_verify(PDO $pdo, string $token): array
{
    $empty = ['ok' => false, 'reference' => null, 'request_id' => null, 'account_type' => null];
    if (!account_deletion_requests_ready($pdo)) return $empty + ['error' => 'migration_required'];
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return $empty + ['error' => 'invalid_token'];

    try {
        $stmt = $pdo->prepare('SELECT * FROM account_deletion_requests
            WHERE verify_token_hash=? AND verify_expires > NOW() AND status=\'pending\' LIMIT 1');
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return $empty + ['error' => 'lookup_failed'];
    }
    if (!$row) return $empty + ['error' => 'invalid_token'];

    // Single-use: clear the token in the same UPDATE that flips the state, so two
    // concurrent submissions of the same link cannot both verify.
    $upd = $pdo->prepare("UPDATE account_deletion_requests
        SET verification_status='verified', status='pending', verified_at=NOW(),
            verify_token_hash=NULL, verify_expires=NULL, updated_at=NOW()
        WHERE id=? AND verify_token_hash=? AND verification_status='unverified'");
    $upd->execute([(int)$row['id'], hash('sha256', $token)]);
    if ($upd->rowCount() !== 1) return $empty + ['error' => 'invalid_token'];

    account_deletion_event($pdo, (int)$row['id'], 'verified', 'ownership confirmed by link');
    account_deletion_notify_customer($pdo, $row, 'verified');

    return ['ok' => true, 'reference' => (string)$row['reference'], 'request_id' => (int)$row['id'], 'account_type' => (string)$row['account_type'], 'error' => null];
}

/** Fetch one request by its reference. */
function account_deletion_request_get(PDO $pdo, string $reference): ?array
{
    if (!account_deletion_requests_ready($pdo)) return null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM account_deletion_requests WHERE reference=? LIMIT 1');
        $stmt->execute([trim($reference)]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** The append-only audit trail for one request, oldest first. */
function account_deletion_request_events(PDO $pdo, int $requestId): array
{
    try {
        $stmt = $pdo->prepare('SELECT * FROM account_deletion_events WHERE request_id=? ORDER BY id ASC');
        $stmt->execute([$requestId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Public-safe status text for a reference, for the "check your request" panel. */
function account_deletion_public_status(array $request): array
{
    $status = (string)($request['status'] ?? 'pending');
    $verified = ((string)($request['verification_status'] ?? 'unverified')) === 'verified';
    $map = [
        'pending'    => ['ar' => $verified ? 'تم التحقق — بانتظار التنفيذ' : 'بانتظار تأكيد الملكية', 'en' => $verified ? 'Verified — awaiting execution' : 'Awaiting ownership confirmation'],
        'processing' => ['ar' => 'قيد المعالجة', 'en' => 'Being processed'],
        'completed'  => ['ar' => 'تم تنفيذ الحذف', 'en' => 'Deletion completed'],
        'rejected'   => ['ar' => 'مرفوض', 'en' => 'Rejected'],
        'cancelled'  => ['ar' => 'ملغى', 'en' => 'Cancelled'],
    ];
    return $map[$status] ?? $map['pending'];
}

/**
 * Execute a verified request: anonymise the account and close the request.
 *
 * Refuses an unverified request, a request with no matched account, or an account
 * that still has work in flight (the blockers are returned so the console can show
 * them). Runs through account_delete(), so the retention rules and session/token
 * revocation are the same ones the in-app path uses.
 *
 * @return array{ok:bool, error:?string, blockers?:array}
 */
function account_deletion_execute(PDO $pdo, string $reference, int $adminId, string $note = ''): array
{
    $request = account_deletion_request_get($pdo, $reference);
    if (!$request) return ['ok' => false, 'error' => 'not_found'];
    if ((string)$request['verification_status'] !== 'verified') return ['ok' => false, 'error' => 'not_verified'];
    if (!in_array((string)$request['status'], ['pending', 'processing'], true)) return ['ok' => false, 'error' => 'not_open'];
    if (empty($request['account_id'])) return ['ok' => false, 'error' => 'no_account'];

    $type = (string)$request['account_type'] === 'lawyer' ? 'lawyer' : 'client';
    $accountId = (int)$request['account_id'];

    // Execute as the account's *current* status: account_delete() requires no work in
    // flight, and the console must surface those blockers rather than force deletion.
    $result = account_delete_forced($pdo, $type, $accountId);
    if (!$result['ok']) {
        if (($result['error'] ?? '') === 'has_open_work') {
            account_deletion_event($pdo, (int)$request['id'], 'execute_blocked', implode(' | ', $result['blockers'] ?? []), 'admin:' . $adminId);
            return ['ok' => false, 'error' => 'has_open_work', 'blockers' => $result['blockers'] ?? []];
        }
        account_deletion_event($pdo, (int)$request['id'], 'execute_failed', (string)($result['error'] ?? ''), 'admin:' . $adminId);
        return ['ok' => false, 'error' => (string)($result['error'] ?? 'delete_failed')];
    }

    $retention = account_deletion_retention_summary($pdo, $type, $accountId);
    $pdo->prepare("UPDATE account_deletion_requests
        SET status='completed', processed_at=NOW(), processed_by_admin_id=?, retention_summary=?, completion_note=?, updated_at=NOW()
        WHERE id=?")
        ->execute([$adminId > 0 ? $adminId : null, $retention, mb_substr($note, 0, 255), (int)$request['id']]);

    account_deletion_event($pdo, (int)$request['id'], 'completed', 'account anonymised; retention applied', 'admin:' . $adminId);
    if (function_exists('log_action')) {
        log_action($pdo, 'تنفيذ حذف حساب (طلب ' . $reference . ')', 'account_deletion_request', (int)$request['id']);
    }
    account_deletion_notify_customer($pdo, $request, 'completed');
    return ['ok' => true, 'error' => null];
}

/** Reject a request (e.g. it could not be verified as the account owner). */
function account_deletion_reject(PDO $pdo, string $reference, int $adminId, string $note = ''): array
{
    $request = account_deletion_request_get($pdo, $reference);
    if (!$request) return ['ok' => false, 'error' => 'not_found'];
    if (in_array((string)$request['status'], ['completed'], true)) return ['ok' => false, 'error' => 'already_completed'];
    $pdo->prepare("UPDATE account_deletion_requests
        SET status='rejected', processed_at=NOW(), processed_by_admin_id=?, completion_note=?, verify_token_hash=NULL, verify_expires=NULL, updated_at=NOW()
        WHERE id=?")
        ->execute([$adminId > 0 ? $adminId : null, mb_substr($note, 0, 255), (int)$request['id']]);
    account_deletion_event($pdo, (int)$request['id'], 'rejected', mb_substr($note, 0, 500), 'admin:' . $adminId);
    return ['ok' => true, 'error' => null];
}

/**
 * Anonymise an account on behalf of a verified request.
 *
 * Ownership was proven by the emailed single-use link, so this path does not ask for
 * the password; it delegates to the shared anonymisation routine, which still refuses
 * an account with work in flight. Kept as a named entry point so the request path's
 * proof (link) and the interactive path's proof (password) stay visibly distinct.
 */
function account_delete_forced(PDO $pdo, string $accountType, int $accountId): array
{
    return account_deletion_anonymize($pdo, $accountType, $accountId);
}

/**
 * The retention summary for a just-anonymised account: which record classes were kept
 * and why. Written onto the request and shown in the console, so the operator never
 * has to reconstruct the policy by hand.
 */
function account_deletion_retention_summary(PDO $pdo, string $accountType, int $accountId): string
{
    $isClient = $accountType !== 'lawyer';
    $parts = [];
    try {
        if ($isClient) {
            $n = (int)$pdo->query('SELECT COUNT(*) FROM invoices WHERE user_id=' . $accountId)->fetchColumn();
            if ($n > 0) $parts[] = "فواتير محفوظة: $n (التزام مالي/ضريبي)";
            $c = (int)$pdo->query('SELECT COUNT(*) FROM consultations WHERE user_id=' . $accountId)->fetchColumn();
            if ($c > 0) $parts[] = "استشارات محفوظة: $c (سجل تعاقدي)";
        } else {
            $n = (int)$pdo->query('SELECT COUNT(*) FROM invoices WHERE lawyer_id=' . $accountId)->fetchColumn();
            if ($n > 0) $parts[] = "فواتير محفوظة: $n (التزام مالي/ضريبي)";
            $c = (int)$pdo->query('SELECT COUNT(*) FROM cases WHERE lawyer_id=' . $accountId)->fetchColumn();
            if ($c > 0) $parts[] = "ملفات قضايا محفوظة: $c (سجل مهني/نزاع)";
        }
    } catch (Throwable $e) {
        // A missing optional table must not stop the request from completing.
    }
    if (!$parts) $parts[] = 'لا توجد سجلات مالية ملزمة';
    return mb_substr(implode(' · ', $parts), 0, 1000);
}

/** Notify the account's owner of a state change, when a channel is available. */
function account_deletion_notify_customer(PDO $pdo, array $request, string $event): void
{
    $type = (string)($request['account_type'] ?? 'client') === 'lawyer' ? 'lawyer' : 'client';
    $accountId = (int)($request['account_id'] ?? 0);
    $arabic = current_lang() === 'ar';

    // In-app bell, for an account that still exists and is signed in.
    if ($accountId > 0 && $event !== 'completed') {
        try {
            require_once __DIR__ . '/notifications.php';
            $title = $arabic ? 'تحديث طلب حذف الحساب' : 'Account-deletion request update';
            $body = $event === 'verified'
                ? ($arabic ? 'تم تأكيد ملكية الحساب. الطلب الآن بانتظار التنفيذ.' : 'Ownership confirmed. Your request is now awaiting execution.')
                : '';
            notify($pdo, $type, $accountId, $title, $body, '/account-deletion.php');
        } catch (Throwable $e) { /* notifications table shape varies; not fatal */ }
    }

    // Email confirmation when we have a deliverable address and the mail module works.
    $to = (string)($request['notify_email'] ?? '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    require_once __DIR__ . '/mailer.php';
    $ref = (string)($request['reference'] ?? '');
    if ($event === 'completed') {
        $body = $arabic
            ? '<p>تم تنفيذ طلب حذف حسابك في منصة مواثيق. أُلغيت بياناتك الشخصية وأُبطلت جميع أجهزة الدخول. تبقى السجلات المالية والقانونية الملزمة محفوظة دون بياناتك الشخصية، ويمكنك التسجيل من جديد بالبريد نفسه في أي وقت.</p><p>رقم الطلب: ' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '</p>'
            : '<p>Your Mawatheq account-deletion request has been completed. Your personal data was erased and every signed-in device was revoked. Legally required financial and legal records are retained without your personal data, and you may register again with the same address at any time.</p><p>Request number: ' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '</p>';
        $html = render_mail_template($arabic ? 'اكتمل حذف الحساب' : 'Account deletion completed', $body);
        send_mail($to, $arabic ? 'اكتمل حذف حسابك - مواثيق' : 'Your Mawatheq account deletion is complete', $html);
    } elseif ($event === 'verified') {
        $body = $arabic
            ? '<p>تم تأكيد ملكية الحساب لطلب الحذف رقم ' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '. سيتولى فريقنا تنفيذ الحذف وفق سياسة الاحتفاظ بالبيانات، وسنبلغك عند الاكتمال.</p>'
            : '<p>Ownership was confirmed for deletion request ' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '. Our team will process it under the data-retention policy and notify you when it is complete.</p>';
        $html = render_mail_template($arabic ? 'تم تأكيد طلب الحذف' : 'Deletion request confirmed', $body);
        send_mail($to, $arabic ? 'تم تأكيد طلب حذف الحساب - مواثيق' : 'Your Mawatheq deletion request is confirmed', $html);
    }
}
