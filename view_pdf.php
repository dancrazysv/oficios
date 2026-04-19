<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';

$tipo = strtoupper((string)($_GET['type'] ?? ''));
$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($tipo === '' || $id <= 0) {
    die('Parámetros inválidos.');
}

$rol = $_SESSION['user_rol'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

try {
    // 1. Obtener datos según el tipo
    if ($tipo === 'CONSTANCIA') {
        $stmt = $pdo->prepare("
            SELECT ruta_pdf_final, estado_validacion, creado_por_id AS creador_id
            FROM constancias
            WHERE id = ?
            LIMIT 1
        ");
    } elseif ($tipo === 'OFICIO_INST') {
        $stmt = $pdo->prepare("
            SELECT ruta_pdf_final, estado_validacion, creado_por AS creador_id
            FROM oficios_institucionales
            WHERE id = ?
            LIMIT 1
        ");
    } else {
        // OFICIO
        $stmt = $pdo->prepare("
            SELECT ruta_pdf_final, estado_validacion, creado_por AS creador_id
            FROM oficios
            WHERE id = ?
            LIMIT 1
        ");
    }

    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        die('Documento no encontrado.');
    }

    $ruta_db = $row['ruta_pdf_final'] ?? '';
    $estado = strtoupper((string)($row['estado_validacion'] ?? ''));
    $creador_id = isset($row['creador_id']) ? (int)$row['creador_id'] : null;

    if (empty($ruta_db)) {
        die('El PDF no ha sido generado todavía.');
    }

    // Limpiar ruta de base de datos
    $ruta_limpia = ltrim((string)$ruta_db, '/\\');

    // Construir ruta absoluta
    $path_final = __DIR__ . DIRECTORY_SEPARATOR . $ruta_limpia;

    // Verificación física del archivo
    if (!file_exists($path_final)) {
        $nombre_archivo = basename($ruta_limpia);

        if ($tipo === 'OFICIO_INST') {
            $path_final = __DIR__ . DIRECTORY_SEPARATOR . 'archivos_finales_inst' . DIRECTORY_SEPARATOR . $nombre_archivo;
        } else {
            $path_final = __DIR__ . DIRECTORY_SEPARATOR . 'archivos_finales' . DIRECTORY_SEPARATOR . $nombre_archivo;
        }

        if (!file_exists($path_final)) {
            die('Error: El archivo físico no existe en el servidor: ' . $nombre_archivo);
        }
    }

    // --- PERMISOS ---
    $is_creador = ($creador_id !== null && $creador_id === $user_id);
    $is_supervisor_admin = in_array($rol, ['supervisor', 'administrador'], true);

    // Permitir ver si está aprobado, o si es el creador, o si es supervisor/admin
    if ($estado === 'APROBADO' || $is_creador || $is_supervisor_admin) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($path_final) . '"');
        header('Content-Length: ' . filesize($path_final));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($path_final);
        exit;
    }

    die('No tiene autorización para ver este archivo PENDIENTE.');

} catch (PDOException $e) {
    die('Error de base de datos.');
}