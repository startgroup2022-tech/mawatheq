<?php
/**
 * GET  /api/v1/consultations.php   → قائمة استشاراتي (عميل أو محامٍ حسب الرمز)
 * POST /api/v1/consultations.php   → حجز استشارة جديدة (عميل فقط)
 *   body: { lawyer_id, consultation_type: text|voice|video, date: "YYYY-MM-DD", time: "HH:MM", topic }
 *         optional multimodal fields (Execution 35):
 *           description      alias for `topic`; the written part of the consultation
 *           draft_token      the token the client staged audio/documents against
 *           idempotency_key  a key the client keeps for the whole submission attempt, so a
 *                            retried request returns the consultation it already created
 *                            instead of booking a second one
 *
 * A consultation is accepted when it carries a written description OR at least one staged
 * attachment. "Audio only" is a valid submission, which is what the brief asks for.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/availability.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/tenant.php';
require_once __DIR__ . '/../../includes/consultation_attachments.php';

$auth = api_authenticate($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // attachment_count and transcription_ready are additive derived columns. The client's
    // tracking screen needs them and the client already has the right to read the
    // attachments themselves, so no new information is exposed by counting them.
    $attachmentStats = "(SELECT COUNT(*) FROM consultation_attachments ca
                          WHERE ca.consultation_id=c.id AND ca.status='stored') AS attachment_count,
                        (SELECT COUNT(*) FROM consultation_attachments ca
                          WHERE ca.consultation_id=c.id AND ca.status='stored'
                            AND ca.transcription_status='ready') AS transcription_ready";

    if ($auth['type'] === 'client') {
        $stmt = $pdo->prepare('SELECT c.*, ' . $attachmentStats . ', l.full_name AS lawyer_name, l.photo_path AS lawyer_photo FROM consultations c JOIN lawyers l ON l.id=c.lawyer_id WHERE c.user_id=? ORDER BY c.scheduled_at DESC');
        $stmt->execute([$auth['id']]);
    } else {
        $scope = tenant_owner_where_for_lawyer($pdo, $auth['id'], 'c');
        $stmt = $pdo->prepare('SELECT c.*, ' . $attachmentStats . ', u.full_name AS client_name, u.phone AS client_phone FROM consultations c JOIN users u ON u.id=c.user_id WHERE ' . $scope['sql'] . ' ORDER BY c.scheduled_at DESC');
        $stmt->execute($scope['params']);
    }
    api_json(['ok' => true, 'consultations' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($auth['type'] !== 'client') api_error('only clients can book consultations', 403);

    $in = api_input();
    $lawyerId = (int)($in['lawyer_id'] ?? 0);
    $type = in_array($in['consultation_type'] ?? '', ['text','voice','video'], true) ? $in['consultation_type'] : 'text';
    $date = $in['date'] ?? '';
    $time = $in['time'] ?? '';
    // `description` is the multimodal form's name for the written part; `topic` is the
    // pre-existing field. Both are accepted so an older client build keeps working.
    $topic = trim((string)($in['topic'] ?? ($in['description'] ?? '')));
    $draftToken = trim((string)($in['draft_token'] ?? ''));
    $idempotencyKey = trim((string)($in['idempotency_key'] ?? ''));

    if ($idempotencyKey !== '' && !preg_match('/^[A-Za-z0-9._-]{8,64}$/', $idempotencyKey)) {
        api_error('idempotency_key must be 8-64 characters of A-Z a-z 0-9 . _ -');
    }
    if ($draftToken !== '' && !preg_match('/^[a-f0-9]{64}$/', $draftToken)) {
        api_error('invalid draft_token');
    }

    // Replay: the client retried a submission that already succeeded. Answer with the
    // consultation that exists rather than creating another one. Checked before any slot
    // validation, because the slot may legitimately be taken by the client's own booking.
    if ($idempotencyKey !== '') {
        $existing = $pdo->prepare('SELECT id FROM consultations WHERE user_id=? AND idempotency_key=? LIMIT 1');
        $existing->execute([$auth['id'], $idempotencyKey]);
        $existingId = (int)$existing->fetchColumn();
        if ($existingId > 0) {
            api_json([
                'ok' => true,
                'consultation_id' => $existingId,
                'reused' => true,
                'attachments' => count(consultation_attachment_list($pdo, $existingId)),
            ]);
        }
    }

    if (!$lawyerId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        api_error('lawyer_id, date (YYYY-MM-DD) and time (HH:MM) are required');
    }

    // A submission must carry substance: a written description, or at least one staged
    // recording/document. Checking the staged rows BEFORE the insert means an empty
    // submission never creates a consultation row that then has to be cleaned up.
    $stagedRows = $draftToken !== ''
        ? consultation_attachment_staged($pdo, $draftToken, (int)$auth['id'])
        : [];
    if ($topic === '' && !$stagedRows) {
        api_error('a consultation needs a written description or at least one attached recording or document');
    }

    $lawyerStmt = $pdo->prepare("SELECT hourly_rate FROM lawyers WHERE id=? AND status='approved'");
    $lawyerStmt->execute([$lawyerId]);
    $lawyer = $lawyerStmt->fetch();
    if (!$lawyer) api_error('lawyer not found', 404);

    $availCount = $pdo->prepare('SELECT COUNT(*) c FROM lawyer_availability WHERE lawyer_id=?');
    $availCount->execute([$lawyerId]);
    $hasAvailability = (int)$availCount->fetch()['c'] > 0;

    if ($hasAvailability) {
        $validSlots = get_available_slots($pdo, $lawyerId, $date);
        if (!in_array($time, $validSlots, true)) {
            api_error('this time slot is no longer available', 409);
        }
    }

    $scheduledAt = $date . ' ' . $time . ':00';

    // Tag the booking with the lawyer's firm. A firm-scoped lawyer is authorized by
    // consultations.firm_id, and upgrade_v42.sql only backfilled the rows that existed when
    // it ran — so without this every later booking stays NULL and is invisible to every
    // member of the firm, including the lawyer who owns it.
    $firmId = tenant_context_for_lawyer($pdo, $lawyerId)['firm_id'];

    try {
        $pdo->prepare('INSERT INTO consultations (user_id, lawyer_id, firm_id, consultation_type, scheduled_at, topic, price, idempotency_key) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$auth['id'], $lawyerId, $firmId, $type, $scheduledAt, $topic, $lawyer['hourly_rate'], $idempotencyKey !== '' ? $idempotencyKey : null]);
        $consultationId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // Two retries raced and the unique key rejected the second. That is exactly the
        // duplicate this key exists to prevent, so the winner's consultation is returned
        // instead of reporting an error the client cannot act on.
        if ((string)$e->getCode() === '23000' && $idempotencyKey !== '') {
            $existing = $pdo->prepare('SELECT id FROM consultations WHERE user_id=? AND idempotency_key=? LIMIT 1');
            $existing->execute([$auth['id'], $idempotencyKey]);
            $existingId = (int)$existing->fetchColumn();
            if ($existingId > 0) {
                api_json(['ok' => true, 'consultation_id' => $existingId, 'reused' => true]);
            }
        }
        throw $e;
    }

    // Bind the recordings and documents the client staged while filling the form. A failure
    // here must not lose the booking — the consultation exists and the lawyer is notified —
    // so the count is reported rather than thrown, and unclaimed files stay staged for the
    // sweeper instead of being attached to the wrong place.
    $claimed = 0;
    if ($draftToken !== '') {
        $claimed = consultation_attachment_claim($pdo, $draftToken, $consultationId, $firmId, (int)$auth['id']);
    }

    require_once __DIR__ . '/../../includes/audit_log.php';
    log_action($pdo, $claimed > 0 ? ('حجز استشارة مع ' . $claimed . ' مرفق (API)') : 'حجز استشارة (API)', 'consultation', $consultationId);

    notify($pdo, 'lawyer', $lawyerId, 'طلب حجز استشارة جديد', ($topic ?: '') . ' — ' . $scheduledAt, '/lawyer/consultations.php');

    api_json([
        'ok' => true,
        'consultation_id' => $consultationId,
        'reused' => false,
        'attachments' => $claimed,
    ], 201);
}

api_error('method not allowed', 405);
