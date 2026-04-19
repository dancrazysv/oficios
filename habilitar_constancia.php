<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// Obtener token CSRF (POST, header o JSON)
$input_csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($input_csrf)) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json) && isset($json['csrf_token'])) {
            $input_csrf = (string)$json['csrf_token'];
            if (isset($json['id'])) $_POST['id'] = $json['id'];
        }
    }
}

$session_csrf = $_SESSION['csrf_token'] ?? '';

if (empty($input_csrf) || empty($session_csrf) || !hash_equals($session_csrf, $input_csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token CSRF inválido o ausente']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$user_rol = $_SESSION['user_rol'] ?? 'normal';
if ($user_id === null || !in_array($user_rol, ['administrador', 'supervisor'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/db_config.php';

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Conexión a la BD no disponible.');
    }

    $sql = "UPDATE constancias SET estado_validacion = ?, fecha_aprobacion = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['APROBADO', $id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Documento no encontrado o ya actualizado']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Constancia habilitada']);
} catch (Throwable $e) {
    error_log('habilitar_constancia error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor']);
}
