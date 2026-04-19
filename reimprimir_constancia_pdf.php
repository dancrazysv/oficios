<?php
// Habilitar la visualización de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Asegura que la sesión esté iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include 'db_config.php';

// Obtener ID del documento de la URL
$id_constancia = $_GET['id'] ?? 0;
$user_rol = $_SESSION['user_rol'] ?? 'normal';

if (empty($id_constancia) || !is_numeric($id_constancia)) {
    die("Error: ID de certificación no válido.");
}

try {
    // Buscar el documento aprobado en la base de datos
    // Solo permitimos reimprimir si está APROBADO
    $stmt = $pdo->prepare("
        SELECT ruta_pdf_final, tipo_constancia 
        FROM constancias 
        WHERE id = ? AND estado_validacion = 'APROBADO'
    ");
    $stmt->execute([$id_constancia]);
    $documento = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$documento) {
        die("Error: El documento solicitado no se encontró o no ha sido aprobado para impresión.");
    }

    $ruta_pdf = __DIR__ . '/' . $documento['ruta_pdf_final'];
    $tipo_constancia = $documento['tipo_constancia'];
    // CRÍTICO: El nombre del archivo debe usar "Certificacion" (Punto 1)
    $nombre_archivo = "Certificacion_{$tipo_constancia}_{$id_constancia}.pdf";

    if (file_exists($ruta_pdf)) {
        // Enviar encabezados para forzar la descarga o visualización en el navegador
        header('Content-Description: File Transfer');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $nombre_archivo . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($ruta_pdf));
        
        // Limpiar el buffer de salida antes de enviar el archivo
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        readfile($ruta_pdf);
        exit;
    } else {
        die("Error: El archivo PDF físico no se encuentra en el servidor.");
    }

} catch (PDOException $e) {
    die("Error de base de datos al buscar el documento: " . $e->getMessage());
}
?>
