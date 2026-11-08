<?php
/**
 * مركز الدعم — support center (Execution 65).
 *
 * The single backend for every support channel the customer app and the website
 * offer: email, WhatsApp, support appointments, and support tickets. Both surfaces
 * read the same settings and the same ticket store, so the app and the site cannot
 * drift apart about which channels exist or what a ticket's status is.
 *
 * Design rules that matter:
 *
 *   * A channel is shown only when it is BOTH enabled and configured. An empty
 *     support email or WhatsApp number reads as "not configured" and the channel is
 *     omitted — the app never prints a placeholder address or invents a number.
 *   * Ticket bodies, subjects and attachment names are untrusted input. Every read
 *     path scopes by the owner (user_type + user_id) so one customer can never read
 *     another customer's ticket, and the admin path is a separate, explicit query.
 *   * Attachments reuse the hardened sniffing in consultation_attachments.php but
 *     are stored under their own root, so a ticket file can never be fetched through
 *     a consultation route (or vice versa).
 *   * Notification fan-out goes through notify(), the same helper every other
 *     module uses, so the in-app bell shows support replies with no extra plumbing.
 */
require_once __DIR__ . '/consultation_attachments.php';
require_once __DIR__ . '/notifications.php';

/** The support settings keys this module owns, in one place. */
function support_setting_keys(): array {
    return [
        'support_email', 'support_whatsapp',
        'support_email_enabled', 'support_whatsapp_enabled',
        'support_tickets_enabled', 'support_appointments_enabled',
        'support_hours_ar', 'support_hours_en', 'support_hours',
    ];
}

/**
 * Read the support settings in one query.
 *
 * @return array<string,string>
 */
function support_settings(PDO $pdo): array {
    if (isset($GLOBALS['__support_settings_cache'])) {
        return $GLOBALS['__support_settings_cache'];
    }
    $keys = support_setting_keys();
    $in = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($in)");
    $stmt->execute($keys);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['setting_key']] = (string)$row['setting_value'];
    }
    return $GLOBALS['__support_settings_cache'] = $out;
}

/**
 * Drop the per-request settings cache after a write.
 *
 * The admin save path writes site_settings directly, so without this the rest of
 * that request would keep reading the pre-change values.
 */
function support_settings_forget(): void {
    unset($GLOBALS['__support_settings_cache']);
}

/** True when the setting is present and enabled. An empty value means unconfigured. */
function support_channel_enabled(array $settings, string $key): bool {
    $flag = $settings[$key . '_enabled'] ?? '';
    if ($flag === '0' || $flag === '') return false;
    return true;
}

/**
 * The support channels as the client should render them.
 *
 * A channel is included only when it is enabled AND has a real destination, so the
 * UI can simply render what it is given and cannot show a dead button.
 *
 * @return array<int,array<string,mixed>>
 */
function support_channels(PDO $pdo): array {
    $s = support_settings($pdo);
    $rtl = function_exists('is_rtl') ? is_rtl() : true;
    $out = [];

    $email = trim($s['support_email'] ?? '');
    if ($email !== '' && support_channel_enabled($s, 'support_email')) {
        $out[] = ['key' => 'email', 'type' => 'email', 'value' => $email];
    }

    $wa = preg_replace('/\D+/', '', (string)($s['support_whatsapp'] ?? '')) ?? '';
    if ($wa !== '' && support_channel_enabled($s, 'support_whatsapp')) {
        $out[] = ['key' => 'whatsapp', 'type' => 'whatsapp', 'value' => $wa];
    }

    if (support_channel_enabled($s, 'support_tickets')) {
        $out[] = ['key' => 'tickets', 'type' => 'tickets', 'value' => ''];
    }

    if (support_channel_enabled($s, 'support_appointments')) {
        $out[] = ['key' => 'appointments', 'type' => 'appointments', 'value' => ''];
    }

    $hours = $rtl
        ? trim((string)($s['support_hours_ar'] ?? ($s['support_hours'] ?? '')))
        : trim((string)($s['support_hours_en'] ?? ($s['support_hours'] ?? '')));
    if ($hours === '') $hours = trim((string)($s['support_hours'] ?? ''));

    return ['channels' => $out, 'hours' => $hours];
}

/**
 * A WhatsApp deep link from a stored number.
 *
 * The number must already be digits-only (country code included, no leading +). An
 * empty or too-short value returns null rather than a link that opens an empty chat.
 */
function support_whatsapp_url(string $digits, string $text = ''): ?string {
    $digits = preg_replace('/\D+/', '', $digits) ?? '';
    if (strlen($digits) < 8) return null;
    $url = 'https://wa.me/' . $digits;
    if ($text !== '') $url .= '?text=' . rawurlencode($text);
    return $url;
}

// ------------------------------------------------------------------ categories

/** Active ticket categories, ordered for the picker. */
function support_categories(PDO $pdo, bool $activeOnly = true): array {
    $sql = 'SELECT id, name_ar, name_en, is_active, sort_order FROM support_ticket_categories';
    if ($activeOnly) $sql .= ' WHERE is_active = 1';
    $sql .= ' ORDER BY sort_order, id';
    return $pdo->query($sql)->fetchAll();
}

// --------------------------------------------------------------------- tickets

/**
 * Generate a human ticket reference. Collisions are astronomically unlikely, but
 * the UNIQUE key is the real guard: a duplicate would throw, so the caller retries.
 */
function support_ticket_number(PDO $pdo): string {
    for ($i = 0; $i < 5; $i++) {
        $candidate = 'TKT-' . date('Y') . '-' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare('SELECT 1 FROM support_tickets WHERE ticket_number=?');
        $stmt->execute([$candidate]);
        if (!$stmt->fetchColumn()) return $candidate;
    }
    return 'TKT-' . date('Y') . '-' . bin2hex(random_bytes(4));
}

/** Generate a support-appointment reference. */
function support_appointment_reference(PDO $pdo): string {
    for ($i = 0; $i < 5; $i++) {
        $candidate = 'SUP-' . date('Y') . '-' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare('SELECT 1 FROM support_appointments WHERE reference=?');
        $stmt->execute([$candidate]);
        if (!$stmt->fetchColumn()) return $candidate;
    }
    return 'SUP-' . date('Y') . '-' . bin2hex(random_bytes(4));
}

/**
 * Create a ticket and its first message.
 *
 * @return array{ok:bool,error?:string,ticket_id?:int,ticket_number?:string}
 */
function support_ticket_create(PDO $pdo, string $userType, int $userId, array $in): array {
    $subject = trim((string)($in['subject'] ?? ''));
    $body = trim((string)($in['body'] ?? ($in['message'] ?? '')));
    $categoryId = (int)($in['category_id'] ?? 0);

    if ($userType !== 'lawyer') $userType = 'client';
    if ($userId <= 0) return ['ok' => false, 'error' => 'auth'];
    if ($subject === '' || mb_strlen($subject) > 255) return ['ok' => false, 'error' => 'subject'];
    if ($body === '' || mb_strlen($body) > 5000) return ['ok' => false, 'error' => 'body'];

    // A category the customer cannot see must not be attachable, so validate the id
    // against the active list rather than trusting the posted value.
    if ($categoryId > 0) {
        $stmt = $pdo->prepare('SELECT 1 FROM support_ticket_categories WHERE id=? AND is_active=1');
        $stmt->execute([$categoryId]);
        if (!$stmt->fetchColumn()) $categoryId = 0;
    } else {
        $categoryId = 0;
    }

    $number = support_ticket_number($pdo);
    // Normalise the value BEFORE the ternary tests it. `in_array($in['priority'] ?? 'normal', ...)`
    // then `$in['priority']` in the true branch reads a key that was only tested with `??`,
    // so an omitted priority inserted NULL into a NOT NULL column (Execution 32 failure mode).
    $priority = (string)($in['priority'] ?? 'normal');
    if (!in_array($priority, ['low', 'normal', 'high'], true)) $priority = 'normal';
    $stmt = $pdo->prepare('INSERT INTO support_tickets
        (ticket_number, user_type, user_id, category_id, subject, status, priority, unread_for_support, unread_for_customer)
        VALUES (?,?,?,?,?,?,?,1,0)');
    $stmt->execute([
        $number, $userType, $userId, $categoryId ?: null, $subject, 'open', $priority,
    ]);
    $ticketId = (int)$pdo->lastInsertId();

    $name = support_actor_name($pdo, $userType, $userId);
    $pdo->prepare('INSERT INTO support_ticket_messages (ticket_id, author_type, author_id, author_name, body)
        VALUES (?,?,?,?,?)')
        ->execute([$ticketId, 'customer', $userId, $name, $body]);

    return ['ok' => true, 'ticket_id' => $ticketId, 'ticket_number' => $number];
}

/** The customer's display name for a message row, resolved from the right table. */
function support_actor_name(PDO $pdo, string $userType, int $userId): string {
    $table = $userType === 'lawyer' ? 'lawyers' : 'users';
    $stmt = $pdo->prepare("SELECT full_name FROM {$table} WHERE id=?");
    $stmt->execute([$userId]);
    return (string)($stmt->fetchColumn() ?: '');
}

/**
 * One ticket, scoped to its owner.
 *
 * Returns null for a ticket that exists but belongs to somebody else, so a guessed
 * id is indistinguishable from a missing one and no ownership is leaked.
 */
function support_ticket_get(PDO $pdo, int $ticketId, string $userType, int $userId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM support_tickets WHERE id=? AND user_type=? AND user_id=?');
    $stmt->execute([$ticketId, $userType, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** The customer's tickets, newest activity first. */
function support_tickets_for(PDO $pdo, string $userType, int $userId): array {
    $stmt = $pdo->prepare('SELECT t.*, c.name_ar AS category_ar, c.name_en AS category_en
        FROM support_tickets t
        LEFT JOIN support_ticket_categories c ON c.id = t.category_id
        WHERE t.user_type=? AND t.user_id=?
        ORDER BY t.updated_at DESC, t.id DESC');
    $stmt->execute([$userType, $userId]);
    return $stmt->fetchAll();
}

/** The message thread for a ticket, oldest first. */
function support_ticket_messages(PDO $pdo, int $ticketId): array {
    $stmt = $pdo->prepare('SELECT id, author_type, author_id, author_name, body, created_at
        FROM support_ticket_messages WHERE ticket_id=? ORDER BY created_at, id');
    $stmt->execute([$ticketId]);
    return $stmt->fetchAll();
}

/**
 * Append a customer reply. Reopens a closed ticket, because a customer who replies
 * has more to say and should not be talking into a void.
 *
 * @return array{ok:bool,error?:string}
 */
function support_ticket_reply(PDO $pdo, array $ticket, string $userType, int $userId, string $body): array {
    $body = trim($body);
    if ($body === '' || mb_strlen($body) > 5000) return ['ok' => false, 'error' => 'body'];

    $name = support_actor_name($pdo, $userType, $userId);
    $pdo->prepare('INSERT INTO support_ticket_messages (ticket_id, author_type, author_id, author_name, body)
        VALUES (?,?,?,?,?)')
        ->execute([(int)$ticket['id'], 'customer', $userId, $name, $body]);

    $pdo->prepare("UPDATE support_tickets
        SET status = CASE WHEN status='closed' THEN 'open' ELSE status END,
            closed_at = NULL,
            unread_for_support = 1,
            last_reply_at = NOW(),
            last_reply_by = 'customer'
        WHERE id = ?")->execute([(int)$ticket['id']]);

    // Tell the assigned operator (or every support admin when unassigned) so a reply
    // is not missed. The customer is not notified of their own message.
    support_notify_support_admins($pdo, (int)$ticket['id'], (string)$ticket['ticket_number'], (string)$ticket['subject']);

    return ['ok' => true];
}

/** Mark a ticket read for the customer (clears their unread badge). */
function support_ticket_mark_read(PDO $pdo, int $ticketId): void {
    $pdo->prepare('UPDATE support_tickets SET unread_for_customer=0 WHERE id=?')->execute([$ticketId]);
}

/** Close or reopen a ticket, from the customer's side. */
function support_ticket_set_status(PDO $pdo, int $ticketId, string $status): bool {
    if (!in_array($status, ['open', 'closed'], true)) return false;
    $pdo->prepare("UPDATE support_tickets SET status=?, closed_at = CASE WHEN ?='closed' THEN NOW() ELSE NULL END WHERE id=?")
        ->execute([$status, $status, $ticketId]);
    return true;
}

/**
 * Notify support admins about a new or updated ticket.
 *
 * Uses notify() with recipient_type 'admin' so the console bell shows it. The
 * assigned admin is preferred; when unassigned every super_admin/support admin is
 * told, because a ticket nobody owns is a ticket nobody answers.
 */
function support_notify_support_admins(PDO $pdo, int $ticketId, string $number, string $subject): void {
    $assignee = $pdo->prepare('SELECT assigned_admin_id FROM support_tickets WHERE id=?');
    $assignee->execute([$ticketId]);
    $adminId = (int)($assignee->fetchColumn() ?: 0);

    $title = 'تذكرة دعم جديدة: ' . $number;
    $message = mb_substr($subject, 0, 180);
    $link = 'admin/support-ticket.php?id=' . $ticketId;

    if ($adminId > 0) {
        notify($pdo, 'admin', $adminId, $title, $message, $link);
        return;
    }
    $stmt = $pdo->query("SELECT id FROM admins WHERE role IN ('super_admin','support')");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        notify($pdo, 'admin', (int)$id, $title, $message, $link);
    }
}

// -------------------------------------------------------------- appointments

/** Active future support slots with their remaining capacity. */
function support_appointment_slots(PDO $pdo, int $days = 30): array {
    $stmt = $pdo->prepare("SELECT s.id, s.starts_at, s.ends_at, s.capacity, s.note,
            (SELECT COUNT(*) FROM support_appointments a
              WHERE a.slot_id=s.id AND a.status IN ('pending','confirmed')) AS taken
        FROM support_appointment_slots s
        WHERE s.is_active=1 AND s.starts_at >= NOW() AND s.starts_at < DATE_ADD(NOW(), INTERVAL ? DAY)
        ORDER BY s.starts_at");
    $stmt->execute([$days]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['remaining'] = max(0, (int)$row['capacity'] - (int)$row['taken']);
        $out[] = $row;
    }
    return $out;
}

/**
 * Request a support appointment.
 *
 * The UNIQUE(slot_id, user_type, user_id) key is the real double-booking guard: two
 * concurrent requests cannot both insert, and the loser gets a clear refusal rather
 * than a duplicate. Capacity is checked inside the same transaction.
 *
 * @return array{ok:bool,error?:string,reference?:string,id?:int}
 */
function support_appointment_request(PDO $pdo, string $userType, int $userId, array $in): array {
    $slotId = (int)($in['slot_id'] ?? 0);
    $subject = trim((string)($in['subject'] ?? ''));
    $note = trim((string)($in['note'] ?? ''));
    if ($userType !== 'lawyer') $userType = 'client';
    if ($userId <= 0) return ['ok' => false, 'error' => 'auth'];
    if ($slotId <= 0) return ['ok' => false, 'error' => 'slot'];
    if ($subject === '' || mb_strlen($subject) > 255) return ['ok' => false, 'error' => 'subject'];

    $pdo->beginTransaction();
    try {
        // Lock the slot row so two requests for the last seat serialise.
        $slot = $pdo->prepare('SELECT id, capacity, is_active, starts_at FROM support_appointment_slots WHERE id=? FOR UPDATE');
        $slot->execute([$slotId]);
        $row = $slot->fetch();
        if (!$row || !(int)$row['is_active']) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'slot_unavailable'];
        }
        $taken = $pdo->prepare("SELECT COUNT(*) FROM support_appointments WHERE slot_id=? AND status IN ('pending','confirmed')");
        $taken->execute([$slotId]);
        if ((int)$taken->fetchColumn() >= (int)$row['capacity']) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'slot_full'];
        }
        $dupe = $pdo->prepare('SELECT 1 FROM support_appointments WHERE slot_id=? AND user_type=? AND user_id=?');
        $dupe->execute([$slotId, $userType, $userId]);
        if ($dupe->fetchColumn()) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'already_requested'];
        }

        $reference = support_appointment_reference($pdo);
        $pdo->prepare('INSERT INTO support_appointments (reference, slot_id, user_type, user_id, subject, note, status)
            VALUES (?,?,?,?,?,?,?)')
            ->execute([$reference, $slotId, $userType, $userId, $subject, $note !== '' ? mb_substr($note, 0, 2000) : null, 'pending']);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // A unique-key race lands here; report it as the same "already requested".
        if ((int)$e->getCode() === 23000) return ['ok' => false, 'error' => 'already_requested'];
        error_log('support appointment request failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'create_failed'];
    }

    $stmt = $pdo->query("SELECT id FROM admins WHERE role IN ('super_admin','support')");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
        notify($pdo, 'admin', (int)$adminId, 'طلب موعد دعم: ' . $reference, mb_substr($subject, 0, 180), 'admin/support-appointments.php');
    }

    return ['ok' => true, 'reference' => $reference, 'id' => $id];
}

/** The customer's own appointment requests. */
function support_appointments_for(PDO $pdo, string $userType, int $userId): array {
    $stmt = $pdo->prepare('SELECT a.*, s.starts_at, s.ends_at
        FROM support_appointments a
        JOIN support_appointment_slots s ON s.id = a.slot_id
        WHERE a.user_type=? AND a.user_id=?
        ORDER BY s.starts_at DESC');
    $stmt->execute([$userType, $userId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------- attachments

/** Root for ticket files. A sibling of the consultation root, never the same dir. */
function support_attachment_storage_root(): string {
    static $root = null;
    if ($root !== null) return $root;

    $appRoot = dirname(__DIR__);
    $override = getenv('MAWATHEQ_PRIVATE_STORAGE_DIR');
    if (is_string($override) && $override !== '' && str_starts_with($override, '/')) {
        return $root = rtrim($override, '/') . '/support-tickets';
    }
    $outside = dirname($appRoot) . '/mawatheq-private/support-tickets';
    $inside  = $appRoot . '/storage/support-tickets';
    $parent = dirname($outside);
    if (is_dir($outside) || (is_dir($parent) && is_writable($parent)) || @mkdir($parent, 0755, true)) {
        return $root = $outside;
    }
    return $root = $inside;
}

/** Ensure the ticket root exists with a deny-all guard, mirroring the consultation root. */
function support_attachment_ensure_storage(): bool {
    $root = support_attachment_storage_root();
    if (!is_dir($root) && !@mkdir($root, 02770, true) && !is_dir($root)) return false;
    @chmod($root, 02770);
    $guard = $root . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "# Support ticket attachments: never served directly.\nRequire all denied\n");
    }
    return true;
}

/**
 * Store one uploaded ticket attachment.
 *
 * Validation is the consultation module's, so a ticket file is sniffed exactly as
 * strictly as a consultation file: extension in the catalog, finfo MIME must match,
 * and no executable signature. A rejected file returns its reason, never a silent
 * success.
 *
 * @return array{ok:bool,error?:string,id?:int}
 */
function support_attachment_store(PDO $pdo, int $ticketId, array $file, string $uploaderType, int $uploaderId, ?int $messageId = null): array {
    if (!support_attachment_ensure_storage()) {
        return ['ok' => false, 'error' => 'storage'];
    }
    $limits = consultation_attachment_limits($pdo);
    $valid = consultation_attachment_validate($file, $limits);
    if (!$valid['ok']) {
        return ['ok' => false, 'error' => (string)$valid['error']];
    }

    $dir = support_attachment_storage_root() . '/' . $ticketId;
    if (!is_dir($dir) && !@mkdir($dir, 02770, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'storage'];
    }
    $extension = (string)$valid['extension'];
    $stored = bin2hex(random_bytes(20)) . '.' . $extension;
    $absolute = $dir . '/' . $stored;
    if (!@move_uploaded_file($file['tmp_name'], $absolute)) {
        return ['ok' => false, 'error' => 'storage'];
    }
    @chmod($absolute, 0640);

    $rel = 'support-tickets/' . $ticketId . '/' . $stored;
    $pdo->prepare('INSERT INTO support_ticket_attachments
        (ticket_id, message_id, uploaded_by_type, uploaded_by_id, original_name, stored_name, stored_path, mime_type, file_size, checksum)
        VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            $ticketId, $messageId, $uploaderType === 'support' ? 'support' : 'customer', $uploaderId,
            consultation_attachment_safe_name((string)($file['name'] ?? 'file')),
            $stored, $rel, (string)$valid['mime'], (int)$valid['size'],
            hash_file('sha256', $absolute) ?: null,
        ]);
    return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
}

/** Stored, non-deleted attachments for a ticket. */
function support_attachment_list(PDO $pdo, int $ticketId): array {
    $stmt = $pdo->prepare('SELECT id, original_name, mime_type, file_size, created_at
        FROM support_ticket_attachments WHERE ticket_id=? AND deleted_at IS NULL ORDER BY id');
    $stmt->execute([$ticketId]);
    return $stmt->fetchAll();
}

/** Resolve one attachment row to its absolute path, refusing a path outside the root. */
function support_attachment_absolute_path(array $row): ?string {
    $root = realpath(support_attachment_storage_root());
    if ($root === false) return null;
    $path = realpath($root . '/' . (int)$row['ticket_id'] . '/' . $row['stored_name']);
    if ($path === false || !str_starts_with($path, $root . '/')) return null;
    return $path;
}

/** One attachment row, or null. */
function support_attachment_find(PDO $pdo, int $attachmentId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM support_ticket_attachments WHERE id=? AND deleted_at IS NULL');
    $stmt->execute([$attachmentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ---------------------------------------------------------------- admin views

/** Ticket list for the console, with filters. */
function support_admin_tickets(PDO $pdo, array $filters = []): array {
    $where = [];
    $params = [];
    if (!empty($filters['status']) && in_array($filters['status'], ['open', 'pending', 'answered', 'closed'], true)) {
        $where[] = 't.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['category_id'])) {
        $where[] = 't.category_id = ?';
        $params[] = (int)$filters['category_id'];
    }
    if (!empty($filters['assigned_admin_id'])) {
        $where[] = 't.assigned_admin_id = ?';
        $params[] = (int)$filters['assigned_admin_id'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(t.subject LIKE ? OR t.ticket_number LIKE ?)';
        $like = '%' . $filters['q'] . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql = 'SELECT t.*, c.name_ar AS category_ar, c.name_en AS category_en
        FROM support_tickets t
        LEFT JOIN support_ticket_categories c ON c.id = t.category_id';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY t.updated_at DESC, t.id DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** An admin reply. Moves the ticket to 'answered' and flags it unread for the customer. */
function support_admin_reply(PDO $pdo, int $ticketId, int $adminId, string $adminName, string $body): bool {
    $body = trim($body);
    if ($body === '' || mb_strlen($body) > 5000) return false;
    $pdo->prepare('INSERT INTO support_ticket_messages (ticket_id, author_type, author_id, author_name, body)
        VALUES (?,?,?,?,?)')
        ->execute([$ticketId, 'support', $adminId, $adminName, $body]);

    $ticket = $pdo->prepare('SELECT user_type, user_id, ticket_number FROM support_tickets WHERE id=?');
    $ticket->execute([$ticketId]);
    $row = $ticket->fetch();
    if (!$row) return false;

    $pdo->prepare("UPDATE support_tickets
        SET status='answered', unread_for_customer=1, unread_for_support=0,
            last_reply_at=NOW(), last_reply_by='support'
        WHERE id=?")->execute([$ticketId]);

    notify(
        $pdo,
        $row['user_type'] === 'lawyer' ? 'lawyer' : 'client',
        (int)$row['user_id'],
        'ردّ جديد على تذكرة الدعم ' . $row['ticket_number'],
        mb_substr($body, 0, 180),
        'support-ticket.php?id=' . $ticketId
    );
    return true;
}
