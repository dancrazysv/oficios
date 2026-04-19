<?php
require_once 'db_config.php';
session_start();

header('Content-Type: application/json');

$referencia = $_POST['referencia'] ?? '';

if (!$referencia) {
    echo json_encode(['success'=>false,'message'=>'Referencia inválida']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, municipio_destino_id 
    FROM oficios 
    WHERE referencia=? 
    LIMIT 1
");
$stmt->execute([$referencia]);
$oficio = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$oficio) {
    echo json_encode(['success'=>false,'message'=>'Oficio no encontrado']);
    exit;
}

if (!$oficio['municipio_destino_id']) {
    echo json_encode(['success'=>false,'message'=>'El oficio no tiene municipio destino asignado']);
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO cola_envios (oficio_id, municipio_destino_id)
    VALUES (?,?)
");
$stmt->execute([$oficio['id'],$oficio['municipio_destino_id']]);

echo json_encode([
    'success'=>true,
    'message'=>'Envío programado correctamente'
]);
