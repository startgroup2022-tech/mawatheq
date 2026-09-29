<?php
function csrf_token(): string {
    if (empty($_SESSION['_csrf_token'])) $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf_token'];
}
function csrf_is_valid($provided): bool {
    return is_string($provided) && !empty($_SESSION['_csrf_token']) && hash_equals($_SESSION['_csrf_token'], $provided);
}
