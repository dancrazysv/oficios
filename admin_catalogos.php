<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';

$rol = $_SESSION['user_rol'] ?? 'normal';
$nombre_usuario = $_SESSION['nombre_usuario'] ?? 'Usuario';
if (!in_array($rol, ['administrador', 'supervisor'])) { header("Location: dashboard.php"); exit; }

$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $id = (int)($_POST['id'] ?? 0);
    $nombre = strtoupper(trim($_POST['nombre_solicitante']));
    $tipo_doc = (int)$_POST['tipo_documento_id'];
    $num_doc = trim($_POST['numero_documento']);

    try {
        if ($action === 'nuevo') {
            $stmt = $pdo->prepare("INSERT INTO solicitantes (nombre_solicitante, tipo_documento_id, numero_documento) VALUES (?, ?, ?)");
            $stmt->execute([$nombre, $tipo_doc, $num_doc]);
        } else if ($action === 'editar') {
            $stmt = $pdo->prepare("UPDATE solicitantes SET nombre_solicitante = ?, tipo_documento_id = ?, numero_documento = ? WHERE id = ?");
            $stmt->execute([$nombre, $tipo_doc, $num_doc, $id]);
        } else if ($action === 'eliminar') {
            $pdo->prepare("DELETE FROM solicitantes WHERE id = ?")->execute([$id]);
        }
        $msg = "<div class='alert alert-success'>Operación exitosa</div>";
    } catch (Exception $e) { $msg = "<div class='alert alert-danger'>Error: ".$e->getMessage()."</div>"; }
}

$pagina = (int)($_GET['p'] ?? 1); $offset = ($pagina - 1) * 15;
$items = $pdo->query("SELECT * FROM solicitantes ORDER BY nombre_solicitante ASC LIMIT 15 OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
$total_paginas = (int)ceil($pdo->query("SELECT COUNT(*) FROM solicitantes")->fetchColumn() / 15);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>Admin Ciudadanos</title>
    <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
</head>
<body class="bg-light">
    <div class="container mt-4">
        <div class="card shadow">
            <div class="card-header bg-primary text-white d-flex justify-content-between">
                <h5 class="mb-0">Gestión de Ciudadanos (Solicitantes)</h5>
                <button class="btn btn-light btn-sm" onclick="abrirModal()">+ Nuevo Ciudadano</button>
            </div>
            <div class="card-body">
                <?php echo $msg; ?>
                <table class="table table-hover">
                    <thead><tr><th>ID</th><th>Nombre</th><th>Documento</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php foreach($items as $i): ?>
                        <tr>
                            <td><?php echo $i['id']; ?></td>
                            <td class="font-weight-bold"><?php echo $i['nombre_solicitante']; ?></td>
                            <td><?php echo $i['numero_documento']; ?></td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick='abrirModal(<?php echo json_encode($i); ?>)'>Editar</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalS" tabindex="-1">
        <div class="modal-dialog"><form method="POST" class="modal-content">
            <div class="modal-header"><h5>Ciudadano</h5></div>
            <div class="modal-body">
                <input type="hidden" name="action" id="action"><input type="hidden" name="id" id="id">
                <div class="form-group"><label>Nombre Completo</label><input type="text" name="nombre_solicitante" id="nombre" class="form-control text-uppercase" required></div>
                <div class="form-group"><label>Tipo Doc (1:DUI, 2:PAS)</label><input type="number" name="tipo_documento_id" id="tipo" class="form-control" required></div>
                <div class="form-group"><label>Número Documento</label><input type="text" name="numero_documento" id="num" class="form-control" required></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Guardar</button></div>
        </form></div>
    </div>

    <script src="bootstrap/js/jquery-3.7.1.min.js"></script>
    <script src="bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
    function abrirModal(d=null){
        $('#action').val(d?'editar':'nuevo');
        $('#id').val(d?d.id:'');
        $('#nombre').val(d?d.nombre_solicitante:'');
        $('#tipo').val(d?d.tipo_documento_id:'');
        $('#num').val(d?d.numero_documento:'');
        $('#modalS').modal('show');
    }
    </script>
</body>
</html>