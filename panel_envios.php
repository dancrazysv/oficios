<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$rol = strtolower(trim($_SESSION['user_rol'] ?? 'normal'));
$area_usuario = $_SESSION['area'] ?? '';

if (!in_array($rol, ['administrador','supervisor'], true)) {
    http_response_code(403);
    exit('Acceso restringido.');
}

/* ============================================================
   FILTROS
============================================================ */
$referencia = trim($_GET['referencia'] ?? '');
$estado      = trim($_GET['estado'] ?? '');
$area_filtro = trim($_GET['area'] ?? '');

$where = [];
$params = [];

/* Supervisor solo ve su área */
if ($rol === 'supervisor') {
    $where[] = "u.area = ?";
    $params[] = $area_usuario;
}

/* Filtro área (solo admin) */
if ($rol === 'administrador' && $area_filtro !== '') {
    $where[] = "u.area = ?";
    $params[] = $area_filtro;
}

/* Filtro referencia */
if ($referencia !== '') {
    $where[] = "o.referencia LIKE ?";
    $params[] = "%$referencia%";
}

/* Filtro estado */
if ($estado !== '') {
    $where[] = "c.estado = ?";
    $params[] = $estado;
}

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

/* ============================================================
   PAGINACIÓN
============================================================ */
$por_pagina = 15;
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($pagina - 1) * $por_pagina;

/* ============================================================
   TOTAL
============================================================ */
$stmtCount = $pdo->prepare("
    SELECT COUNT(*)
    FROM cola_envios c
    JOIN oficios o ON o.id = c.oficio_id
    JOIN usuarios u ON u.id = o.creado_por
    $where_sql
");
$stmtCount->execute($params);
$total = (int)$stmtCount->fetchColumn();
$total_paginas = max(1, (int)ceil($total / $por_pagina));

/* ============================================================
   LISTADO
============================================================ */
$stmt = $pdo->prepare("
    SELECT 
        c.id,
        o.referencia,
        c.estado,
        c.intentos,
        c.fecha_creacion,
        c.fecha_proceso,
        u.area
    FROM cola_envios c
    JOIN oficios o ON o.id = c.oficio_id
    JOIN usuarios u ON u.id = o.creado_por
    $where_sql
    ORDER BY c.fecha_creacion DESC
    LIMIT ?, ?
");

// Añadir parámetros de paginación para el execute
$params_lista = array_merge($params, [$offset, $por_pagina]);

$stmt->execute($params_lista);
$envios = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   ESTADÍSTICAS
============================================================ */
$stmtStats = $pdo->prepare("
    SELECT c.estado, COUNT(*) total
    FROM cola_envios c
    JOIN oficios o ON o.id = c.oficio_id
    JOIN usuarios u ON u.id = o.creado_por
    $where_sql
    GROUP BY c.estado
");
$stmtStats->execute($params);
$stats = $stmtStats->fetchAll(PDO::FETCH_KEY_PAIR);

/* ============================================================
   LISTA DE ÁREAS (solo admin)
============================================================ */
$areas = [];
if ($rol === 'administrador') {
    $stmtAreas = $pdo->query("
        SELECT DISTINCT area
        FROM usuarios
        WHERE area IS NOT NULL AND area <> ''
        ORDER BY area
    ");
    $areas = $stmtAreas->fetchAll(PDO::FETCH_COLUMN);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel de Envíos</title>
<link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
<style>
body { background:#f4f6f8; }
.card-box {
    padding:15px;
    border-radius:8px;
    color:white;
    text-align:center;
    font-weight:bold;
}
.PENDIENTE { background:#f1c40f; }
.PROCESANDO { background:#3498db; }
.COMPLETADO { background:#2ecc71; }
.ERROR { background:#e74c3c; }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <a class="navbar-brand" href="dashboard.php">Registro de Trámites</a>
    <div class="collapse navbar-collapse">
        <ul class="navbar-nav mr-auto">
            <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
            <li class="nav-item"><a class="nav-link" href="crear_oficio.php">Crear Oficio</a></li>
            <li class="nav-item"><a class="nav-link" href="crear_constancia.php">Crear Certificación</a></li>
            <li class="nav-item active"><a class="nav-link" href="panel_envios.php">Panel Envíos</a></li>
        </ul>
        <a href="logout.php" class="btn btn-outline-light">Cerrar Sesión</a>
    </div>
</nav>

<div class="container mt-4">

<h3 class="mb-3">📊 Monitoreo de Cola de Envíos</h3>

<div class="row mb-4">
    <?php foreach (['PENDIENTE','PROCESANDO','COMPLETADO','ERROR'] as $estadoCard): ?>
    <div class="col-md-3">
        <div class="card-box <?= $estadoCard ?>">
            <?= $estadoCard ?><br>
            <?= $stats[$estadoCard] ?? 0 ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<form class="form-row mb-4">
    <div class="col-md-3">
        <input type="text" name="referencia" class="form-control"
               placeholder="Referencia"
               value="<?= htmlspecialchars($referencia) ?>">
    </div>
    <div class="col-md-2">
        <select name="estado" class="form-control">
            <option value="">Todos Estados</option>
            <?php foreach (['PENDIENTE','PROCESANDO','COMPLETADO','ERROR'] as $e): ?>
                <option value="<?= $e ?>" <?= $estado === $e ? 'selected' : '' ?>>
                    <?= $e ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($rol === 'administrador'): ?>
    <div class="col-md-3">
        <select name="area" class="form-control">
            <option value="">Todas las áreas</option>
            <?php foreach ($areas as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>"
                    <?= $area_filtro === $a ? 'selected' : '' ?>>
                    <?= htmlspecialchars($a) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="col-md-2">
        <button class="btn btn-primary btn-block">Filtrar</button>
    </div>
    <div class="col-md-2">
        <a href="panel_envios.php" class="btn btn-secondary btn-block">Limpiar</a>
    </div>
</form>

<table class="table table-bordered table-striped">
<thead class="thead-dark">
<tr>
    <th>ID</th>
    <th>Referencia</th>
    <th>Área</th>
    <th>Estado</th>
    <th>Intentos</th>
    <th>Creado</th>
    <th>Procesado</th>
    <th>Acción</th>
</tr>
</thead>
<tbody>
<?php if (!$envios): ?>
<tr><td colspan="8" class="text-center">Sin registros</td></tr>
<?php endif; ?>
<?php foreach($envios as $e): ?>
<tr>
    <td><?= $e['id'] ?></td>
    <td><?= htmlspecialchars($e['referencia']) ?></td>
    <td><?= htmlspecialchars($e['area']) ?></td>
    <td><?= $e['estado'] ?></td>
    <td><?= $e['intentos'] ?></td>
    <td><?= $e['fecha_creacion'] ?></td>
    <td><?= $e['fecha_proceso'] ?? '-' ?></td>
    <td>
        <?php if($e['estado'] === 'ERROR'): ?>
            <form method="post" action="reintentar_envio.php">
                <input type="hidden" name="cola_id" value="<?= $e['id'] ?>">
                <button class="btn btn-warning btn-sm">Reintentar</button>
            </form>
        <?php else: ?>
            —
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<nav>
<ul class="pagination justify-content-center">
    <?php 
    $qp = "&referencia=".urlencode($referencia)."&estado=".urlencode($estado)."&area=".urlencode($area_filtro);
    $adjacentes = 2;
    ?>
    
    <li class="page-item <?= ($pagina <= 1) ? 'disabled' : '' ?>">
        <a class="page-link" href="?pagina=<?= $pagina - 1 . $qp ?>">«</a>
    </li>

    <?php 
    for ($i = 1; $i <= $total_paginas; $i++):
        // Mostrar siempre la primera, la última y las cercanas a la actual
        if ($i == 1 || $i == $total_paginas || ($i >= $pagina - $adjacentes && $i <= $pagina + $adjacentes)): ?>
            <li class="page-item <?= $i == $pagina ? 'active' : '' ?>">
                <a class="page-link" href="?pagina=<?= $i . $qp ?>"><?= $i ?></a>
            </li>
        <?php 
        // Mostrar puntos suspensivos
        elseif (($i == $pagina - $adjacentes - 1) || ($i == $pagina + $adjacentes + 1)): ?>
            <li class="page-item disabled"><span class="page-link">...</span></li>
        <?php 
        endif;
    endfor; 
    ?>

    <li class="page-item <?= ($pagina >= $total_paginas) ? 'disabled' : '' ?>">
        <a class="page-link" href="?pagina=<?= $pagina + 1 . $qp ?>">»</a>
    </li>
</ul>
</nav>

</div>
</body>
</html>