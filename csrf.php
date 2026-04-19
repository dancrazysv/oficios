<?php
// Generación y verificación de token CSRF centralizado
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token(): string {
    return $_SESSION['csrf_token'] ?? '';
}

function verify_csrf(?string $token): bool {
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && is_string($sessionToken) && hash_equals($sessionToken, $token);
}