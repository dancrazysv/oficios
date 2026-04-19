<?php
// check_session.php
// Centraliza el inicio de sesión y helpers de autorización.
// Reemplaza cualquier session_start() directo en otros archivos por incluir este fichero.

declare(strict_types=1);

// Sólo iniciar sesión si no existe ya
if (session_status() === PHP_SESSION_NONE) {
    // Ajusta según tu entorno: secure=true en HTTPS
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    // Configura cookie params ANTES de session_start
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax' // o 'Strict' si tu flujo lo permite
    ]);

    // Iniciar sesión
    session_start();
}

// Helper: ¿usuario autenticado?
function is_authenticated(): bool {
    return !empty($_SESSION['user_id']);
}

// Helper: en páginas HTML, redirige al login si no autenticado
function require_auth_redirect(): void {
    if (!is_authenticated()) {
        // Ajusta la ruta de login si tu proyecto la ubica en otro sitio
        header('Location: ../login.php');
        exit;
    }
}

// Helper: en endpoints AJAX JSON, devolver error JSON si no autenticado
function require_auth_json(): void {
    if (!is_authenticated()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No autenticado']);
        exit;
    }
}

// Opcional: regenerar id de sesión en login por seguridad (no aquí)