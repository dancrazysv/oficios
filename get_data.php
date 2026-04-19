<?php
// Limpiar cualquier salida previa
ob_start();

// Desactivar errores visibles que rompan el JSON
error_reporting(0);
ini_set('display_errors', 0);

include 'db_config.php';

$action = $_POST['action'] ?? '';

// --- MUNICIPIOS ---
if ($action === 'get_municipios') {
    $departamento_id = $_POST['departamento_id'] ?? '';
    if (!empty($departamento_id)) {
        try {
            $stmt = $pdo->prepare("SELECT id, nombre FROM municipios WHERE departamento_id = ? ORDER BY nombre");
            $stmt->execute([$departamento_id]);
            $options = '<option value="">Seleccione un municipio</option>';
            while ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $options .= "<option value=\"{$m['id']}\">" . htmlspecialchars($m['nombre']) . "</option>";
            }
            ob_clean(); // Borrar cualquier espacio accidental
            echo $options;
        } catch (PDOException $e) { echo ''; }
    }
    exit;
} 

// --- DISTRITOS ---
elseif ($action === 'get_distritos') {
    $municipio_id = $_POST['municipio_id'] ?? '';
    if (!empty($municipio_id)) {
        try {
            $stmt = $pdo->prepare("SELECT id, nombre FROM distritos WHERE municipio_id = ? ORDER BY nombre");
            $stmt->execute([$municipio_id]);
            $options = '<option value="">Seleccione un distrito</option>';
            while ($d = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $options .= "<option value=\"{$d['id']}\">" . htmlspecialchars($d['nombre']) . "</option>";
            }
            ob_clean();
            echo $options;
        } catch (PDOException $e) { echo ''; }
    }
    exit;
} 

// --- OFICIANTE (EL REGISTRADOR) ---
elseif ($action === 'get_oficiante') {
    // Forzamos JSON
    header('Content-Type: application/json; charset=utf-8');
    ob_clean(); // Limpieza crítica

    $municipio_id = $_POST['municipio_id'] ?? ''; 

    if (!empty($municipio_id)) {
        try {
            // Buscamos en la tabla oficiantes
            $stmt = $pdo->prepare("SELECT nombre, cargo FROM oficiantes WHERE municipio_id = ? LIMIT 1");
            $stmt->execute([$municipio_id]);
            $oficiante = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($oficiante) {
                echo json_encode(['success' => true, 'oficiante' => $oficiante]);
            } else {
                // Si no hay registrado nadie, devolvemos vacío para no dar error
                echo json_encode(['success' => true, 'oficiante' => ['nombre' => 'NO ASIGNADO', 'cargo' => '']]);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'No muni ID']);
    }
    exit;
}