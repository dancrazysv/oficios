<?php
declare(strict_types=1);
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';
// Asegúrate de que la ruta a tcpdf sea correcta
// Cambia la línea 6 por esta:
require_once __DIR__ . '/vendor/autoload.php';

/* ===== CAPTURAR FILTROS (Misma lógica que arriba) ===== */
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

/* ===== GENERAR PDF ===== */
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Sistema Trámites');
$pdf->SetTitle('Reporte General');
$pdf->SetMargins(15, 20, 15);
$pdf->AddPage();

$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'REPORTE GENERAL DE TRÁMITES', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 5, 'Fecha de generación: ' . date('d/m/Y H:i'), 0, 1, 'C');
$pdf->Ln(5);

$html = '
<table border="1" cellpadding="5">
    <tr style="background-color: #007bff; color: #ffffff; font-weight: bold;">
        <th width="12%">Tipo</th>
        <th width="15%">Referencia</th>
        <th width="12%">Fecha</th>
        <th width="31%">Nombre Registrado</th>
        <th width="15%">Estado</th>
        <th width="15%">Usuario</th>
    </tr>';

foreach ($items as $i) {
    $html .= '<tr>
        <td>' . $i['tipo'] . '</td>
        <td>' . $i['referencia'] . '</td>
        <td>' . $i['fecha'] . '</td>
        <td>' . $i['registrado_a'] . '</td>
        <td>' . $i['estado_validacion'] . '</td>
        <td>' . $i['elaborado_por'] . '</td>
    </tr>';
}
$html .= '</table>';

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output('Reporte_Tramites.pdf', 'I');