<?php
/**
 * /api/v1/support.php — مركز الدعم (يحتاج توكن، عدا `settings`)
 *
 * One endpoint for every support channel, so the app has a single surface to talk
 * to and the website can call the same code. All ticket and appointment actions are
 * scoped to the authenticated account; the settings action is public because the
 * contact channels are public information and the app needs them before sign-in.
 *
 *   GET  ?action=settings       → the configured channels + working hours (public)
 *   GET  ?action=categories     → active ticket categories
 *   GET  ?action=tickets        → my tickets
 *   GET  ?action=ticket&id=N    → one of my tickets + its thread + attachments
 *   GET  ?action=slots          → available support appointment slots
 *   GET  ?action=appointments   → my appointment requests
 *
 *   POST action=create-ticket   { category_id, subject, body, priority? }
 *   POST action=reply-ticket    { id, body }
 *   POST action=close-ticket    { id } / action=reopen-ticket { id }
 *   POST action=book-appointment{ slot_id, subject, note? }
 *   POST action=cancel-appointment { id }
 *   POST action=upload-attachment (multipart: ticket_id, file)
 *
 * Auth is enforced by api_authenticate() for everything but `settings`. A ticket id
 * that is not the caller's returns 404, never another customer's ticket.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/support.php';

$action = (string)($_GET['action'] ?? 'settings');

// The channels are public: the app shows the support email/WhatsApp on the public
// contact screen too, and it must not need a token to do that.
if ($action === 'settings' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $data = support_channels($pdo);
    $waDigits = '';
    foreach ($data['channels'] as $ch) {
        if (($ch['key'] ?? '') === 'whatsapp') { $waDigits = (string)$ch['value']; break; }
    }
    api_json([
        'ok'           => true,
        'channels'     => $data['channels'],
        'hours'        => $data['hours'],
        'whatsapp_url' => support_whatsapp_url($waDigits),
    ]);
}

$auth = api_authenticate($pdo);
if ($auth['type'] === 'integration') api_error('لا تملك صلاحية الوصول إلى هذا العنصر.', 403);
$userType = $auth['type'] === 'lawyer' ? 'lawyer' : 'client';
$userId = (int)$auth['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    switch ($action) {
        case 'categories':
            api_json(['ok' => true, 'categories' => support_categories($pdo)]);

        case 'tickets':
            api_json(['ok' => true, 'tickets' => support_tickets_for($pdo, $userType, $userId)]);

        case 'ticket':
            $id = (int)($_GET['id'] ?? 0);
            $ticket = support_ticket_get($pdo, $id, $userType, $userId);
            if (!$ticket) api_error('التذكرة غير موجودة.', 404);
            support_ticket_mark_read($pdo, $id);
            api_json([
                'ok'          => true,
                'ticket'      => $ticket,
                'messages'    => support_ticket_messages($pdo, $id),
                'attachments' => support_attachment_list($pdo, $id),
            ]);

        case 'slots':
            api_json(['ok' => true, 'slots' => support_appointment_slots($pdo)]);

        case 'appointments':
            api_json(['ok' => true, 'appointments' => support_appointments_for($pdo, $userType, $userId)]);

        default:
            api_error('unknown action', 400);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Multipart uploads arrive in $_POST/$_FILES, not the JSON body.
    $isMultipart = str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data');
    $in = $isMultipart ? $_POST : api_input();
    $action = (string)($in['action'] ?? $action);

    switch ($action) {
        case 'create-ticket':
            $result = support_ticket_create($pdo, $userType, $userId, $in);
            if (!$result['ok']) {
                $msg = match ($result['error']) {
                    'subject' => 'يرجى إدخال عنوان للتذكرة (حتى 255 حرفاً).',
                    'body'    => 'يرجى إدخال نص التذكرة (حتى 5000 حرف).',
                    'auth'    => 'الجلسة مطلوبة.',
                    default   => 'تعذّر إنشاء التذكرة.',
                };
                api_error($msg, 400);
            }
            // Optional first attachment on the opening message.
            if (!empty($_FILES['file']['name'])) {
                $up = support_attachment_store($pdo, (int)$result['ticket_id'], $_FILES['file'], 'customer', $userId);
                if (!$up['ok']) {
                    api_json(['ok' => true, 'ticket_id' => $result['ticket_id'],
                        'ticket_number' => $result['ticket_number'],
                        'attachment_error' => $up['error']]);
                }
            }
            api_json(['ok' => true, 'ticket_id' => $result['ticket_id'], 'ticket_number' => $result['ticket_number']]);

        case 'reply-ticket':
            $id = (int)($in['id'] ?? 0);
            $ticket = support_ticket_get($pdo, $id, $userType, $userId);
            if (!$ticket) api_error('التذكرة غير موجودة.', 404);
            $result = support_ticket_reply($pdo, $ticket, $userType, $userId, (string)($in['body'] ?? ''));
            if (!$result['ok']) api_error('يرجى إدخال نص الرد (حتى 5000 حرف).', 400);
            if (!empty($_FILES['file']['name'])) {
                support_attachment_store($pdo, $id, $_FILES['file'], 'customer', $userId);
            }
            api_json(['ok' => true]);

        case 'close-ticket':
        case 'reopen-ticket':
            $id = (int)($in['id'] ?? 0);
            $ticket = support_ticket_get($pdo, $id, $userType, $userId);
            if (!$ticket) api_error('التذكرة غير موجودة.', 404);
            support_ticket_set_status($pdo, $id, $action === 'close-ticket' ? 'closed' : 'open');
            api_json(['ok' => true]);

        case 'book-appointment':
            $result = support_appointment_request($pdo, $userType, $userId, $in);
            if (!$result['ok']) {
                $msg = match ($result['error']) {
                    'slot'              => 'يرجى اختيار موعد.',
                    'slot_unavailable'  => 'هذا الموعد لم يعد متاحاً.',
                    'slot_full'         => 'اكتمل هذا الموعد. يرجى اختيار موعد آخر.',
                    'already_requested' => 'لديك طلب على هذا الموعد بالفعل.',
                    'subject'           => 'يرجى إدخال موضوع الطلب.',
                    default             => 'تعذّر إرسال طلب الموعد.',
                };
                api_error($msg, $result['error'] === 'already_requested' ? 409 : 400);
            }
            api_json(['ok' => true, 'reference' => $result['reference'], 'id' => $result['id']]);

        case 'cancel-appointment':
            $id = (int)($in['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id FROM support_appointments WHERE id=? AND user_type=? AND user_id=? AND status IN ('pending','confirmed')");
            $stmt->execute([$id, $userType, $userId]);
            if (!$stmt->fetchColumn()) api_error('الطلب غير موجود.', 404);
            $pdo->prepare("UPDATE support_appointments SET status='cancelled' WHERE id=?")->execute([$id]);
            api_json(['ok' => true]);

        case 'upload-attachment':
            $ticketId = (int)($in['ticket_id'] ?? 0);
            $ticket = support_ticket_get($pdo, $ticketId, $userType, $userId);
            if (!$ticket) api_error('التذكرة غير موجودة.', 404);
            if (empty($_FILES['file']['name'])) api_error('يرجى اختيار ملف.', 400);
            $result = support_attachment_store($pdo, $ticketId, $_FILES['file'], 'customer', $userId);
            if (!$result['ok']) api_error((string)$result['error'], 400);
            api_json(['ok' => true, 'id' => $result['id']]);

        default:
            api_error('unknown action', 400);
    }
}

api_error('method not allowed', 405);
