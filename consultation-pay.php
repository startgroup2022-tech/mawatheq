<?php
/**
 * Retry the payment for an existing consultation (Execution 63).
 *
 * When a priced booking's payment did not start (or was abandoned), the booking exists but
 * is not confirmed. This endpoint lets the owner start the payment again: it re-checks
 * ownership, starts a fresh gateway order, and redirects to the hosted payment page. It is
 * a POST with a CSRF token because it creates a payment order, and the redirect runs before
 * the shell prints anything.
 */
require_once __DIR__ . '/config/config.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(403); exit('Invalid request');
}
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/consultation_payment.php';

$userId = (int)$_SESSION['user_id'];
$consultationId = (int)($_POST['consultation_id'] ?? 0);

// Ownership is the only authority: a guessed id must not start a payment for another
// customer's consultation.
$stmt = $pdo->prepare('SELECT * FROM consultations WHERE id=? AND user_id=?');
$stmt->execute([$consultationId, $userId]);
$consultation = $stmt->fetch();

if (!$consultation || !consultation_payment_schema_ready($pdo)
    || !consultation_payment_required($consultation)
    || ($consultation['payment_status'] ?? 'unpaid') === 'paid') {
    header('Location: /consultations.php');
    exit;
}

$custStmt = $pdo->prepare('SELECT full_name, email FROM users WHERE id=?');
$custStmt->execute([$userId]);
$customer = $custStmt->fetch() ?: [];

$started = consultation_payment_start($pdo, $consultation, [
    'customer_name' => (string)($customer['full_name'] ?? ''),
    'customer_email' => (string)($customer['email'] ?? ''),
    'language' => current_lang(),
]);

if (!empty($started['ok']) && !empty($started['redirect_url'])) {
    header('Location: ' . $started['redirect_url']);
    exit;
}

// The gateway could not be reached. Return to the list with an honest banner; the booking
// stays pending and unpaid, which is the truth. The structured failure code travels with the
// redirect so the page can show the right sentence — a permanent fault (gateway off, currency
// mismatch, bad credentials) must tell the customer to contact us, not to "try again later",
// which would loop them forever. The correlation id lets support find the exact failure.
$corr = (string)($started['correlation_id'] ?? '');
$code = (string)($started['error'] ?? '');
header('Location: /consultations.php?payment_error=1'
    . ($code !== '' ? '&code=' . urlencode($code) : '')
    . ($corr !== '' ? '&ref=' . urlencode($corr) : ''));
exit;
