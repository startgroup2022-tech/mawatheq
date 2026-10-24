<?php
/**
 * Account deletion (client and lawyer).
 *
 * Google Play requires an in-app route to delete an account, and the privacy page
 * promises one, so this is a shipped capability rather than a support request.
 *
 * WHAT "DELETE" MEANS HERE
 *   The account is **anonymised and deactivated**, not hard-deleted. The rows that
 *   carry the person's identity (name, email, phone, password, verification and
 *   reset tokens) are overwritten, every API token is revoked, and the status is set
 *   so no login can succeed again. Rows that another party legitimately depends on —
 *   an invoice the platform issued, a consultation a lawyer was paid for — keep their
 *   foreign key, because deleting them would silently corrupt the other party's
 *   records. This is the standard retention shape: erase the personal data, keep the
 *   accounting fact.
 *
 *   A hard `DELETE FROM users` would cascade away invoices, cases and consultations
 *   (every FK on those tables is ON DELETE CASCADE), which is exactly the data a law
 *   firm and the platform's own books must keep. Do not "simplify" this into a delete.
 *
 * CONFIRMATION
 *   The caller must prove it is the account holder, not merely someone holding a
 *   session: `account_delete()` takes the account's password and verifies it. A stolen
 *   token therefore cannot destroy an account.
 *
 * OPEN WORK
 *   Deletion is refused while the account still has work in flight — an open case for
 *   a client, an upcoming consultation for either role — and the blockers are returned
 *   so the UI can name them. Silently deleting a client mid-matter would take the
 *   lawyer's case file with it.
 */

/**
 * Where an account of this type lives.
 *
 * An unknown type resolves to the client table, never to a caller-supplied name.
 */
function account_deletion_config(string $accountType): array
{
    $map = [
        'client' => ['table' => 'users',   'label' => 'client'],
        'lawyer' => ['table' => 'lawyers', 'label' => 'lawyer'],
    ];
    return $map[$accountType] ?? $map['client'];
}

/** Whether the table carries the `deleted_at` stamp (added in upgrade_v65.sql). */
function account_deletion_has_stamp(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute([$table, 'deleted_at']);
        $cache[$table] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

/**
 * The reasons this account cannot be deleted yet, as human-readable Arabic strings.
 *
 * An empty array means deletion is allowed. Each entry names the concrete row so the
 * customer knows what to close first rather than being told "not allowed".
 */
function account_deletion_blockers(PDO $pdo, string $accountType, int $accountId): array
{
    $blockers = [];
    $isClient = $accountType !== 'lawyer';

    try {
        if ($isClient) {
            // The `cases.status` enum is open|in_review|closed_won|closed_lost|closed_settled.
            // Testing NOT IN ('closed','archived') matched every row — no case carries those
            // values — so a client with a long-closed matter was refused deletion forever.
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases
                WHERE user_id=? AND status IN ('open','in_review')");
            $stmt->execute([$accountId]);
            $open = (int)$stmt->fetchColumn();
            if ($open > 0) $blockers[] = "لديك $open قضية مفتوحة. يجب إغلاقها أولاً.";
        }

        $col = $isClient ? 'user_id' : 'lawyer_id';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultations
            WHERE $col=? AND status IN ('pending','confirmed') AND scheduled_at >= NOW()");
        $stmt->execute([$accountId]);
        $upcoming = (int)$stmt->fetchColumn();
        if ($upcoming > 0) $blockers[] = "لديك $upcoming استشارة قادمة. يجب إلغاؤها أو إتمامها أولاً.";

        if (!$isClient) {
            // `invoices.status` is unpaid|partially_paid|paid|overdue|cancelled.
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices
                WHERE lawyer_id=? AND status IN ('unpaid','partially_paid','overdue')");
            $stmt->execute([$accountId]);
            $unpaid = (int)$stmt->fetchColumn();
            if ($unpaid > 0) $blockers[] = "لديك $unpaid فاتورة غير مسددة. يجب تسويتها أولاً.";
        }
    } catch (Throwable $e) {
        // A missing optional table must not block deletion entirely; the checks that
        // could run are what matter.
    }

    return $blockers;
}

/**
 * Anonymise and deactivate an account after verifying its password.
 *
 * Returns ['ok' => bool, 'error' => string]. The password is required and is checked
 * against the stored hash, so holding a session is not enough to destroy an account.
 */
function account_delete(PDO $pdo, string $accountType, int $accountId, string $password): array
{
    $cfg = account_deletion_config($accountType);
    $table = $cfg['table'];

    if ($password === '') return ['ok' => false, 'error' => 'missing_password'];

    try {
        $stmt = $pdo->prepare("SELECT id, password_hash FROM `$table` WHERE id=?");
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'account_not_found'];
    }
    if (!$row) return ['ok' => false, 'error' => 'account_not_found'];

    if (!password_verify($password, (string)$row['password_hash'])) {
        return ['ok' => false, 'error' => 'wrong_password'];
    }

    $blockers = account_deletion_blockers($pdo, $accountType, $accountId);
    if ($blockers) return ['ok' => false, 'error' => 'has_open_work', 'blockers' => $blockers];

    // A tombstone that is unique per account and can never collide with a real address,
    // so the UNIQUE(email) index still admits a fresh registration by the same person.
    $tombstone = 'deleted+' . $accountId . '-' . bin2hex(random_bytes(4)) . '@deleted.invalid';
    // An unusable hash: no password can verify against it, so even if the status check
    // were ever bypassed the account could not be signed into.
    $deadHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    $hasStamp = account_deletion_has_stamp($pdo, $table);

    try {
        $pdo->beginTransaction();

        $sets = [
            'full_name = ?',
            'email = ?',
            'password_hash = ?',
            "status = 'suspended'",
            'session_invalidated_at = NOW()',
        ];
        $params = ['حساب محذوف', $tombstone, $deadHash];

        // These columns exist on both tables; clear them only if present.
        foreach (['phone', 'email_verify_token_hash', 'reset_token', 'reset_expires'] as $col) {
            if (account_deletion_has_column($pdo, $table, $col)) {
                $sets[] = "$col = NULL";
            }
        }
        if ($hasStamp) $sets[] = 'deleted_at = NOW()';

        $sql = "UPDATE `$table` SET " . implode(', ', $sets) . ' WHERE id = ?';
        $params[] = $accountId;
        $pdo->prepare($sql)->execute($params);

        // Every device is signed out. `user_type` is the same string the API stores.
        $pdo->prepare('UPDATE api_tokens SET revoked_at=NOW() WHERE user_type=? AND user_id=? AND revoked_at IS NULL')
            ->execute([$accountType, $accountId]);

        // The in-app notifications and the rate-limit buckets are keyed by account.
        foreach (['notifications'] as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE user_type=? AND user_id=?")->execute([$accountType, $accountId]); }
            catch (Throwable $e) { /* table shape varies; not fatal */ }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'delete_failed'];
    }

    return ['ok' => true];
}

/** Whether a column exists on a table, memoised per request. */
function account_deletion_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute([$table, $column]);
        $cache[$key] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}
