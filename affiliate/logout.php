<?php
/**
 * Affiliate sign-out (Execution 31).
 * Clears the affiliate session keys only, so signing out of the partner portal does
 * not also sign the person out of a client or lawyer session they may hold.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/affiliate.php';
require_once __DIR__ . '/../includes/affiliate_auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
}

affiliate_logout();
header('Location: /affiliate/login.php');
exit;
