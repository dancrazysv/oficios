<?php
// Incluye la configuración de la base de datos
include 'db_config.php';

// El parámetro que se espera es 'ref' (la referencia)
$referencia = $_GET['ref'] ?? null;

if (!$referencia) {
    die("Referencia de documento no proporcionada.");
}

try {
    // 1. Cargar la RUTA del PDF final guardado
    $stmt = $pdo->prepare("SELECT ruta_pdf_final FROM oficios WHERE referencia = ?");
    $stmt->execute([$referencia]);
    $ruta_relativa = $stmt->fetchColumn();

    if (empty($ruta_relativa)) {
        // Esto ocurrirá si el oficio se creó antes de implementar la función de guardado
        die("Error: El archivo PDF final no existe en el servidor para esta referencia. Por favor, asegúrese de que el documento haya sido generado y guardado.");
    }
    
    // 2. Construir la ruta absoluta y verificar el archivo
    $ruta_absoluta = __DIR__ . '/' . $ruta_relativa; 
    
    if (!file_exists($ruta_absoluta)) {
        // Error si el archivo se eliminó del disco
        die("Error: El archivo PDF guardado no se encuentra en la ruta: " . $ruta_absoluta);
    }
    
    // 3. Enviar el archivo guardado al navegador
    $filename = basename($ruta_absoluta);
    
    // Limpieza de buffering de salida para prevenir conflictos de encabezado
    if (ob_get_level() > 0) {
        ob_end_clean(); 
    }
    
    // Configurar los encabezados para enviar el PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($ruta_absoluta));
    
    // 4. Leer y enviar el contenido del archivo
    readfile($ruta_absoluta);
    exit;

} catch (PDOException $e) {
    die("Error de base de datos al cargar el oficio: " . $e->getMessage());
} catch (Exception $e) {
    die("Error al procesar el archivo: " . $e->getMessage());
}