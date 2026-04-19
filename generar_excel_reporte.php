<?php
declare(strict_types=1);
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';

/* ===== CAPTURAR FILTROS DEL DASHBOARD ===== */
$busqueda = trim((string)($_GET['busqueda'] ?? ''));
$filtro_estado = trim((string)($_GET['estado_filtro'] ?? ''));
$filtro_operador = (int)($_GET['operador_filtro'] ?? 0);
$filtro_tipo = trim((string)($_GET['tipo_filtro'] ?? ''));
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';

$rol = $_SESSION['user_rol'] ?? 'normal';
$user_id = (int)($_SESSION['user_id'] ?? 0);
$area_usuario = $_SESSION['area'] ?? '';

$where_oficios = []; $params_oficios = [];
$where_constancias = []; $params_constancias = [];

// Lógica de filtros (Igual a tu dashboard)
if ($busqueda !== '') {
    $like = '%' . $busqueda . '%';
    $where_oficios[] = "o.nombre_difunto LIKE ?"; $params_oficios[] = $like;
    $where_constancias[] = "c.nombre_no_registro LIKE ?"; $params_constancias[] = $like;
}
if ($filtro_estado !== '') {
    $where_oficios[] = "o.estado_validacion = ?"; $params_oficios[] = $filtro_estado;
    $where_constancias[] = "c.estado_validacion = ?"; $params_constancias[] = $filtro_estado;
}
if ($fecha_inicio !== '') {
    $where_oficios[] = "o.fecha >= ?"; $params_oficios[] = $fecha_inicio;
    $where_constancias[] = "c.fecha_emision >= ?"; $params_constancias[] = $fecha_inicio;
}
if ($fecha_fin !== '') {
    $where_oficios[] = "o.fecha <= ?"; $params_oficios[] = $fecha_fin;
    $where_constancias[] = "c.fecha_emision <= ?"; $params_constancias[] = $fecha_fin;
}
if ($filtro_operador > 0) {
    $where_oficios[] = "o.creado_por = ?"; $params_oficios[] = $filtro_operador;
    $where_constancias[] = "c.creado_por_id = ?"; $params_constancias[] = $filtro_operador;
}
if ($rol === 'supervisor') {
    $where_oficios[] = "u.area = ?"; $params_oficios[] = $area_usuario;
    $where_constancias[] = "u.area = ?"; $params_constancias[] = $area_usuario;
} elseif ($rol === 'normal') {
    $where_oficios[] = "o.creado_por = ?"; $params_oficios[] = $user_id;
    $where_constancias[] = "c.creado_por_id = ?"; $params_constancias[] = $user_id;
}

$sql_w_oficios = $where_oficios ? " WHERE " . implode(' AND ', $where_oficios) : "";
$sql_w_constancias = $where_constancias ? " WHERE " . implode(' AND ', $where_constancias) : "";

$sql_oficios = "SELECT 'OFICIO' AS tipo, o.referencia, o.fecha, o.nombre_difunto AS registrado_a, o.estado_validacion, u.nombre_completo AS elaborado_por FROM oficios o LEFT JOIN usuarios u ON o.creado_por = u.id $sql_w_oficios";
$sql_constancias = "SELECT 'CONSTANCIA' AS tipo, c.numero_constancia AS referencia, c.fecha_emision AS fecha, c.nombre_no_registro AS registrado_a, c.estado_validacion, u.nombre_completo AS elaborado_por FROM constancias c LEFT JOIN usuarios u ON c.creado_por_id = u.id $sql_w_constancias";

if ($filtro_tipo === 'OFICIO') {
    $sql_final = "($sql_oficios)"; $all_params = $params_oficios;
} elseif ($filtro_tipo === 'CONSTANCIA') {
    $sql_final = "($sql_constancias)"; $all_params = $params_constancias;
} else {
    $sql_final = "($sql_oficios) UNION ALL ($sql_constancias)"; $all_params = array_merge($params_oficios, $params_constancias);
}

$stmt = $pdo->prepare($sql_final . " ORDER BY fecha DESC");
$stmt->execute($all_params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===== CONFIGURACIÓN DE CABECERAS PARA EXCEL NATIVO ===== */
$filename = "Reporte_Tramites_" . date('Y-m-d') . ".xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=$filename");
header("Pragma: no-cache");
header("Expires: 0");

// Esto asegura que Excel reconozca los caracteres especiales (tildes, Ñ)
echo "\xEF\xBB\xBF"; 
?>
<table border="1">
    <thead>
        <tr style="background-color: #007bff; color: #ffffff; font-weight: bold;">
            <th>TIPO</th>
            <th>REFERENCIA</th>
            <th>FECHA</th>
            <th>A NOMBRE DE</th>
            <th>ESTADO</th>
            <th>ELABORADO POR</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $i): ?>
        <tr>
            <td><?php echo $i['tipo']; ?></td>
            <td><?php echo $i['referencia']; ?></td>
            <td><?php echo $i['fecha']; ?></td>
            <td><?php echo $i['registrado_a']; ?></td>
            <td><?php echo $i['estado_validacion']; ?></td>
            <td><?php echo $i['elaborado_por']; ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>