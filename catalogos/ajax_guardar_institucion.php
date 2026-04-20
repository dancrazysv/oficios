<?php
// catalogos/ajax_guardar_institucion.php
require_once __DIR__ . '/../db_config.php';
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    die(json_encode(['success' => false, 'msg' => 'Error de seguridad.']));
}

$nombre    = mb_strtoupper(trim($_POST['nombre'] ?? ''), 'UTF-8');
$unidad    = mb_strtoupper(trim($_POST['unidad'] ?? 'OFICINA CENTRAL'), 'UTF-8');
$direccion = mb_strtoupper(trim($_POST['direccion'] ?? 'NO ESPECIFICADA'), 'UTF-8');
$email     = trim($_POST['email'] ?? '');

if (empty($nombre) || empty($email)) {
    die(json_encode(['success' => false, 'msg' => 'Nombre y Email son obligatorios.']));
}

try {
    // Se inserta '1' en la columna 'estado' explícitamente
    $stmt = $pdo->prepare("INSERT INTO instituciones (nombre_institucion, unidad_dependencia, ubicacion_sede, email_contacto, estado) VALUES (?, ?, ?, ?, 1)");
    if ($stmt->execute([$nombre, $unidad, $direccion, $email])) {
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    } else {
        echo json_encode(['success' => false, 'msg' => 'No se pudo guardar la institución en la base de datos.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => 'Error: ' . $e->getMessage()]);
}