<?php
// cambiar_password.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json');

// Validación de seguridad CSRF y método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'msg' => 'Error de seguridad: Token inválido']);
    exit;
}

$id = $_SESSION['user_id'] ?? null;
$actual = $_POST['pass_actual'] ?? '';
$nueva = $_POST['pass_nueva'] ?? '';
$conf = $_POST['pass_confirmar'] ?? '';

if (!$id) {
    echo json_encode(['success' => false, 'msg' => 'Sesión expirada']);
    exit;
}

if (empty($actual) || empty($nueva)) {
    echo json_encode(['success' => false, 'msg' => 'Complete todos los campos']);
    exit;
}

if ($nueva !== $conf) {
    echo json_encode(['success' => false, 'msg' => 'Las nuevas contraseñas no coinciden']);
    exit;
}

try {
    // SE CORRIGIÓ EL NOMBRE DE LA COLUMNA A 'contrasena'
    $stmt = $pdo->prepare("SELECT contrasena FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $db_pass = $user['contrasena'];
        $valido = false;

        // Verificar si la pass de la DB es un HASH o Texto Plano
        if (strpos($db_pass, '$2y$') === 0) {
            $valido = password_verify($actual, $db_pass);
        } else {
            $valido = ($actual === $db_pass);
        }

        if ($valido) {
            $nuevo_hash = password_hash($nueva, PASSWORD_DEFAULT);
            
            // SE CORRIGIÓ EL NOMBRE DE LA COLUMNA A 'contrasena'
            $update = $pdo->prepare("UPDATE usuarios SET contrasena = ? WHERE id = ?");
            
            if ($update->execute([$nuevo_hash, $id])) {
                echo json_encode(['success' => true, 'msg' => 'Contraseña actualizada con éxito']);
            } else {
                echo json_encode(['success' => false, 'msg' => 'Error al guardar en la base de datos']);
            }
        } else {
            echo json_encode(['success' => false, 'msg' => 'La contraseña actual no es correcta']);
        }
    } else {
        echo json_encode(['success' => false, 'msg' => 'Usuario no encontrado']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => 'Error en el servidor: ' . $e->getMessage()]);
}
exit;