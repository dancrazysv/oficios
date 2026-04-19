<?php
require_once 'db_config.php';
session_start();

$cola_id = (int)($_POST['cola_id'] ?? 0);

if (!$cola_id) {
    header("Location: panel_envios.php");
    exit;
}

$stmt = $pdo->prepare("
    UPDATE cola_envios
    SET estado='PENDIENTE'
    WHERE id=? AND estado='ERROR'
");

$stmt->execute([$cola_id]);

header("Location: panel_envios.php");
exit;
