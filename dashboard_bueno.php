<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/php_errors.log');
error_reporting(E_ALL);

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ===== DATOS DE SESIÓN ===== */
$rol = $_SESSION['user_rol'] ?? 'normal';
$user_id = (int)($_SESSION['user_id'] ?? 0);
$nombre_usuario = $_SESSION['nombre_usuario'] ?? 'Usuario';
$area_usuario = $_SESSION['area'] ?? '';

/* ===== OBTENER ÁREAS DISPONIBLES (Solo Administrador) ===== */
$areas_sistema = [];
if ($rol === 'administrador') {
    try {
        $stmt_areas = $pdo->query("SELECT DISTINCT area FROM usuarios WHERE area IS NOT NULL AND area != '' ORDER BY area ASC");
        $areas_sistema = $stmt_areas->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { $areas_sistema = []; }
}

/* ===== ESTADÍSTICAS (CONTADORES SUPERIORES) ===== */
try {
    // Pendientes de envío: Solo los de su área si es supervisor, solo los suyos si es normal
    if ($rol === 'supervisor') {
        $stmt_p_env = $pdo->prepare("SELECT COUNT(*) FROM oficios o JOIN usuarios u ON o.creado_por = u.id WHERE o.enviado_correo = 0 AND o.estado_validacion = 'APROBADO' AND u.area = ?");
        $stmt_p_env->execute([$area_usuario]);
    } elseif ($rol === 'normal') {
        // CORRECCIÓN: Filtro por el ID del usuario normal para el contador superior
        $stmt_p_env = $pdo->prepare("SELECT COUNT(*) FROM oficios WHERE enviado_correo = 0 AND estado_validacion = 'APROBADO' AND creado_por = ?");
        $stmt_p_env->execute([$user_id]);
    } else {
        // Administrador: Ve el total global
        $stmt_p_env = $pdo->query("SELECT COUNT(*) FROM oficios WHERE enviado_correo = 0 AND estado_validacion = 'APROBADO'");
    }
    $count_pendientes_envio = (int)$stmt_p_env->fetchColumn();

    // Errores de envío: Tomado de la tabla cola_envios
    $stmt_err_env = $pdo->query("SELECT COUNT(*) FROM cola_envios WHERE UPPER(estado) = 'ERROR'");
    $count_errores_envio = (int)$stmt_err_env->fetchColumn();

    // Estadísticas generales para otros usos
    $stats_envios = $pdo->query("SELECT UPPER(estado), COUNT(*) total FROM cola_envios GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $stats_envios = [];
    $count_pendientes_envio = 0;
    $count_errores_envio = 0;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* ===== OBTENER LISTA DE OPERADORES ===== */
$operadores_area = [];
if ($rol === 'supervisor' || $rol === 'administrador') {
    try {
        if ($rol === 'administrador') {
            $stmt_ops = $pdo->query("SELECT id, nombre_completo, area FROM usuarios WHERE rol = 'normal' ORDER BY area ASC, nombre_completo ASC");
            $operadores_area = $stmt_ops->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt_ops = $pdo->prepare("SELECT id, nombre_completo FROM usuarios WHERE area = ? AND rol = 'normal' ORDER BY nombre_completo ASC");
            $stmt_ops->execute([$area_usuario]);
            $operadores_area = $stmt_ops->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) { $operadores_area = []; }
}

/* ===== PAGINACIÓN Y BÚSQUEDA ===== */
$documentos_por_pagina = 8;
$pagina_actual = (int)filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
$busqueda = trim((string)filter_input(INPUT_GET, 'busqueda', FILTER_UNSAFE_RAW));
$filtro_estado = trim((string)filter_input(INPUT_GET, 'estado_filtro', FILTER_UNSAFE_RAW));
$filtro_operador = filter_input(INPUT_GET, 'operador_filtro', FILTER_VALIDATE_INT);
$filtro_tipo = trim((string)filter_input(INPUT_GET, 'tipo_filtro', FILTER_UNSAFE_RAW));
$filtro_envio = trim((string)filter_input(INPUT_GET, 'envio_filtro', FILTER_UNSAFE_RAW)); 
$filtro_area = trim((string)filter_input(INPUT_GET, 'area_filtro', FILTER_UNSAFE_RAW)); // NUEVO FILTRO AREA
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';

// Construcción de condiciones WHERE
$where_of = ["1=1"]; $params_of = [];
$where_co = ["1=1"]; $params_co = [];

if ($busqueda !== '') {
    $like = '%' . $busqueda . '%';
    $where_of[] = "o.nombre_difunto LIKE ?"; $params_of[] = $like;
    $where_co[] = "c.nombre_no_registro LIKE ?"; $params_co[] = $like;
}
if ($filtro_estado !== '') {
    $where_of[] = "o.estado_validacion = ?"; $params_of[] = $filtro_estado;
    $where_co[] = "c.estado_validacion = ?"; $params_co[] = $filtro_estado;
}
if ($filtro_envio === 'PENDIENTE') {
    $where_of[] = "o.enviado_correo = 0 AND o.estado_validacion = 'APROBADO'";
    $where_co[] = "0=1"; 
}
if ($fecha_inicio !== '') {
    $where_of[] = "o.fecha >= ?"; $params_of[] = $fecha_inicio;
    $where_co[] = "c.fecha_emision >= ?"; $params_co[] = $fecha_inicio;
}
if ($fecha_fin !== '') {
    $where_of[] = "o.fecha <= ?"; $params_of[] = $fecha_fin;
    $where_co[] = "c.fecha_emision <= ?"; $params_fin[] = $fecha_fin;
}

// Filtro por Área (Solo Administrador)
if ($filtro_area !== '' && $rol === 'administrador') {
    $where_of[] = "u.area = ?"; $params_of[] = $filtro_area;
    $where_co[] = "u.area = ?"; $params_co[] = $filtro_area;
}

if ($filtro_operador && ($rol === 'supervisor' || $rol === 'administrador')) {
    $where_of[] = "o.creado_por = ?"; $params_of[] = $filtro_operador;
    $where_co[] = "c.creado_por_id = ?"; $params_co[] = $filtro_operador;
}

if ($busqueda === '' && !$filtro_operador) {
    if ($rol === 'supervisor' && $area_usuario !== '') {
        $where_of[] = "u.area = ?"; $params_of[] = $area_usuario;
        $where_co[] = "u.area = ?"; $params_co[] = $area_usuario;
    } elseif ($rol === 'normal') {
        $where_of[] = "o.creado_por = ?"; $params_of[] = $user_id;
        $where_co[] = "c.creado_por_id = ?"; $params_co[] = $user_id;
    }
}

$sql_w_of = " WHERE " . implode(' AND ', $where_of);
$sql_w_co = " WHERE " . implode(' AND ', $where_co);

try {
    /* --- TOTALES DINÁMICOS PARA CARDS --- */
    $st_o = $pdo->prepare("SELECT COUNT(*) FROM oficios o LEFT JOIN usuarios u ON o.creado_por = u.id $sql_w_of");
    $st_o->execute($params_of); $count_o = (int)$st_o->fetchColumn();

    $st_c = $pdo->prepare("SELECT COUNT(*) FROM constancias c LEFT JOIN usuarios u ON c.creado_por_id = u.id $sql_w_co");
    $st_c->execute($params_co); $count_c = (int)$st_c->fetchColumn();

    $st_po = $pdo->prepare("SELECT COUNT(*) FROM oficios o LEFT JOIN usuarios u ON o.creado_por = u.id $sql_w_of AND o.estado_validacion = 'PENDIENTE'");
    $st_po->execute($params_of); $p_of = (int)$st_po->fetchColumn();

    $st_pc = $pdo->prepare("SELECT COUNT(*) FROM constancias c LEFT JOIN usuarios u ON c.creado_por_id = u.id $sql_w_co AND c.estado_validacion = 'PENDIENTE'");
    $st_pc->execute($params_co); $p_co = (int)$st_pc->fetchColumn();
    $count_p = $p_of + $p_co;

    /* --- QUERY DE TABLA PRINCIPAL --- */
    $sql_oficios_base = "SELECT o.id, o.referencia, o.fecha, o.nombre_difunto AS registrado_a, o.municipio_destino_id, o.enviado_correo, o.estado_validacion, u.nombre_completo AS elaborado_por, 'OFICIO' AS tipo_documento, o.creado_por, NULL AS contacto_email, NULL AS ruta_pdf_final FROM oficios o LEFT JOIN usuarios u ON o.creado_por = u.id $sql_w_of";
    $sql_constancias_base = "SELECT c.id, c.numero_constancia AS referencia, c.fecha_emision AS fecha, c.nombre_no_registro AS registrado_a, NULL AS municipio_destino_id, c.enviado_correo, c.estado_validacion, u.nombre_completo AS elaborado_por, 'CONSTANCIA' AS tipo_documento, c.creado_por_id AS creado_por, c.correo_solicitante AS contacto_email, c.ruta_pdf_final FROM constancias c LEFT JOIN usuarios u ON c.creado_por_id = u.id $sql_w_co";

    if ($filtro_tipo === 'OFICIO') {
        $sql_total = "($sql_oficios_base)"; $all_params = $params_of; $total_registros = $count_o;
    } elseif ($filtro_tipo === 'CONSTANCIA') {
        $sql_total = "($sql_constancias_base)"; $all_params = $params_co; $total_registros = $count_c;
    } else {
        $sql_total = "($sql_oficios_base) UNION ALL ($sql_constancias_base)"; $all_params = array_merge($params_of, $params_co); $total_registros = $count_o + $count_c;
    }

    $total_paginas = max(1, (int)ceil($total_registros / $documentos_por_pagina));
    $offset = ($pagina_actual - 1) * $documentos_por_pagina;

    $stmt = $pdo->prepare("$sql_total ORDER BY fecha DESC LIMIT $offset, $documentos_por_pagina");
    $stmt->execute($all_params);
    $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log($e->getMessage());
    $documentos = []; $count_o = $count_c = $count_p = $total_registros = 0; $total_paginas = 1;
}

function e($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Dashboard - Trámites</title>
    <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
    <style>
        body{background:#f8f9fa}
        .container{margin-top:30px}
        .badge-elaborado{display:block; font-size: 0.75rem; color: #666;}
        .card-filter{background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 25px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05);}
        .btn-preview{ background-color: #6f42c1; color: white; border:none; }
        .btn-preview:hover{ background-color: #5a32a3; color: white; }
        .table-hover tbody tr:hover { background-color: rgba(0,123,255,0.05); }
        .stat-card { border: none; border-radius: 8px; transition: transform .2s; }
        .stat-card .card-body { padding: 0.5rem 1rem; }
        .stat-card h2 { font-size: 1.25rem; margin-bottom: 0; font-weight: bold; }
        .stat-card h6 { font-size: 0.7rem; margin-bottom: 2px; text-transform: uppercase; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow mb-4">
    <a class="navbar-brand font-weight-bold" href="dashboard.php">Sistema de Trámites</a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav mr-auto">
            <li class="nav-item"><a class="nav-link" href="crear_oficio.php">Crear Oficio</a></li>
            <li class="nav-item"><a class="nav-link" href="crear_constancia.php">Crear Certificación</a></li>
            <li class="nav-item active"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
            <?php if ($rol === 'administrador'): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navCatalogos" role="button" data-toggle="dropdown">Administración</a>
                    <div class="dropdown-menu shadow">
                        <a class="dropdown-item" href="catalogos/admin_departamentos.php">Departamentos</a>
                        <a class="dropdown-item" href="catalogos/admin_municipios.php">Municipios</a>
                        <a class="dropdown-item" href="catalogos/admin_distritos.php">Distritos</a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="catalogos/admin_tipos_documento.php">Tipos de Documento</a>
                        <a class="dropdown-item" href="catalogos/admin_tipos_constancia.php">Tipos de Constancia</a>
                        <a class="dropdown-item" href="catalogos/admin_soportes.php">Soportes</a>
                        <a class="dropdown-item" href="catalogos/admin_hospitales.php">Hospitales</a>
                        <a class="dropdown-item" href="catalogos/admin_oficiantes.php">Oficiantes</a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="catalogos/admin_usuarios.php">Usuarios</a>
                        <a class="dropdown-item" href="catalogos/admin_solicitantes.php">Solicitantes</a>
                    </div>
                </li>
            <?php endif; ?>
            <?php if (in_array($rol, ['administrador', 'supervisor'])): ?>
                <li class="nav-item"><a class="nav-link" href="panel_envios.php">Panel Envíos <span class="badge badge-danger"><?php echo $stats_envios['ERROR'] ?? 0; ?></span></a></li>
            <?php endif; ?>
        </ul>
        <span class="navbar-text mr-3 text-white">Bienvenido, <strong><?php echo e($nombre_usuario); ?></strong></span>
        <a href="logout.php" class="btn btn-outline-light btn-sm">Cerrar Sesión</a>
    </div>
</nav>

<div class="container">
    <div class="row mb-3 text-center">
        <div class="col-md-2 col-6 mb-2">
            <div class="card stat-card bg-primary text-white shadow-sm">
                <div class="card-body">
                    <h6 class="text-uppercase font-weight-bold">TOTAL FILTRADO</h6>
                    <h2><?php echo ($count_o + $count_c); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-2">
            <div class="card stat-card bg-dark text-white shadow-sm">
                <div class="card-body">
                    <h6 class="text-uppercase font-weight-bold">OFICIOS</h6>
                    <h2><?php echo $count_o; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-2">
            <div class="card stat-card bg-info text-white shadow-sm">
                <div class="card-body">
                    <h6 class="text-uppercase font-weight-bold">CERTIFICACIONES</h6>
                    <h2><?php echo $count_c; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-2">
            <div class="card stat-card bg-warning text-dark shadow-sm">
                <div class="card-body">
                    <h6 class="text-uppercase font-weight-bold">PEND. VALIDAR</h6>
                    <h2><?php echo $count_p; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-2">
            <div class="card stat-card bg-danger text-white shadow-sm">
                <div class="card-body">
                    <h6 class="text-uppercase font-weight-bold">PEND. ENVIO</h6>
                    <h2><?php echo $count_pendientes_envio; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-2">
            <div class="card stat-card bg-secondary text-white shadow-sm">
                <div class="card-body">
                    <h6 class="text-uppercase font-weight-bold">ERR. ENVIO</h6>
                    <h2><?php echo $count_errores_envio; ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="card-filter shadow-sm">
        <form method="GET" id="formFiltros" class="form-row align-items-end">
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold text-muted">Nombre/Ref:</label>
                <input type="text" name="busqueda" class="form-control" placeholder="Buscar..." value="<?php echo e($busqueda); ?>">
            </div>
            <div class="col-md-1 mb-2">
                <label class="small font-weight-bold text-muted">Tipo:</label>
                <select name="tipo_filtro" class="form-control">
                    <option value="">-- Todos --</option>
                    <option value="OFICIO" <?php echo ($filtro_tipo === 'OFICIO') ? 'selected' : ''; ?>>OFICIO</option>
                    <option value="CONSTANCIA" <?php echo ($filtro_tipo === 'CONSTANCIA') ? 'selected' : ''; ?>>CONSTANCIA</option>
                </select>
            </div>
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold text-muted">Desde:</label>
                <input type="date" name="fecha_inicio" class="form-control" value="<?php echo e($fecha_inicio); ?>">
            </div>
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold text-muted">Hasta:</label>
                <input type="date" name="fecha_fin" class="form-control" value="<?php echo e($fecha_fin); ?>">
            </div>
            <div class="col-md-1 mb-2">
                <label class="small font-weight-bold text-muted">Estado:</label>
                <select name="estado_filtro" class="form-control">
                    <option value="">-- Todos --</option>
                    <option value="PENDIENTE" <?php echo ($filtro_estado === 'PENDIENTE') ? 'selected' : ''; ?>>PENDIENTE</option>
                    <option value="APROBADO" <?php echo ($filtro_estado === 'APROBADO') ? 'selected' : ''; ?>>APROBADO</option>
                </select>
            </div>

            <?php if ($rol === 'administrador'): ?>
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold text-muted">Área:</label>
                <select name="area_filtro" class="form-control">
                    <option value="">-- Todas las áreas --</option>
                    <?php foreach ($areas_sistema as $a): ?>
                        <option value="<?php echo e($a); ?>" <?php echo ($filtro_area === $a) ? 'selected' : ''; ?>>
                            <?php echo e($a); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if ($rol === 'supervisor' || $rol === 'administrador'): ?>
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold">Operador:</label>
                <select name="operador_filtro" class="form-control">
                    <option value="">-- Todos --</option>
                    <?php foreach ($operadores_area as $op): ?>
                        <option value="<?php echo $op['id']; ?>" <?php echo ($filtro_operador == $op['id']) ? 'selected' : ''; ?>>
                            <?php echo e($op['nombre_completo']); ?> <?php echo ($rol === 'administrador') ? "(".e($op['area']).")" : ""; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-12 mt-2 d-flex justify-content-between">
                <div>
                    <button type="submit" class="btn btn-primary">Filtrar</button>
                    <a href="dashboard.php" class="btn btn-outline-secondary ml-1">Limpiar</a>
                </div>
                <?php if ($rol !== 'normal'): ?>
                <div>
                    <button type="button" onclick="exportar('excel')" class="btn btn-success mr-1">Excel</button>
                    <button type="button" onclick="exportar('pdf')" class="btn btn-danger">PDF</button>
                </div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="table-responsive bg-white p-3 shadow-sm rounded">
        <table class="table table-hover">
            <thead class="thead-dark">
                <tr>
                    <th>Tipo</th>
                    <th>Documento / Elaborado Por</th>
                    <th>Registrado a Nombre de</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($documentos)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No se encontraron resultados.</td></tr>
                <?php endif; ?>
                <?php foreach ($documentos as $doc): 
                    $id = $doc['id']; $tipo = $doc['tipo_documento']; $estado = $doc['estado_validacion'];
                    $es_oficio = ($tipo === 'OFICIO'); $aprobado = ($estado === 'APROBADO'); $pendiente = ($estado === 'PENDIENTE');
                    $puede_revisar = in_array($rol, ['administrador','supervisor']);
                    $elaborador = $doc['elaborado_por'] ?: 'Sistema / Desconocido';
                    $enviado = (bool)($doc['enviado_correo'] ?? 0);
                    $es_ajeno = ((int)$doc['creado_por'] !== $user_id);
                ?>
                <tr <?php echo ($es_ajeno && $rol === 'normal') ? 'class="table-warning"' : ''; ?>>
                    <td><small class="badge badge-<?php echo ($tipo === 'OFICIO' ? 'dark' : 'info'); ?>"><?php echo $tipo; ?></small></td>
                    <td>
                        <strong><?php echo e($doc['referencia']); ?></strong>
                        <span class="badge-elaborado">Por: <?php echo e($elaborador); ?></span>
                    </td>
                    <td><span class="font-weight-bold"><?php echo e($doc['registrado_a']); ?></span></td>
                    <td><span class="badge badge-<?php echo ($aprobado)?'success':(($pendiente)?'warning':'danger'); ?>"><?php echo $estado; ?></span></td>
                    <td>
                        <div class="btn-group">
                            <?php if ($es_ajeno && $rol === 'normal' && $busqueda === ''): ?>
                                <span class="text-muted small italic">Consulta (Trámite ajeno)</span>
                            <?php else: ?>
                                <?php if ($pendiente): ?>
                                    <?php if ($puede_revisar): ?>
                                        <button class="btn btn-sm btn-primary btn-habilitar" data-id="<?php echo $id; ?>" data-type="<?php echo $tipo; ?>">Habilitar</button>
                                        <a href="view_pdf.php?type=<?php echo $tipo; ?>&id=<?php echo $id; ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Revisar PDF</a>
                                    <?php elseif ($rol === 'normal'): ?>
                                        <a href="preview_pdf.php?id=<?php echo $id; ?>&type=<?php echo $tipo; ?>" target="_blank" class="btn btn-sm btn-preview">Vista Previa</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($aprobado): ?>
                                    <a href="<?php echo $es_oficio ? 'reimprimir_pdf.php?ref='.urlencode($doc['referencia']) : ($doc['ruta_pdf_final'] ?: 'reimprimir_constancia_pdf.php?id='.$id); ?>" target="_blank" class="btn btn-sm btn-success">Imprimir</a>
                                    <?php if ($es_oficio): ?>
                                        <button class="btn btn-sm btn-warning <?php echo $enviado?'disabled':''; ?>" <?php echo !$enviado ? 'data-toggle="modal" data-target="#emailModalOficio"' : ''; ?> data-id="<?php echo $id; ?>" data-referencia="<?php echo e($doc['referencia']); ?>" data-email="<?php echo e($doc['contacto_email']); ?>" data-municipio-id="<?php echo $doc['municipio_destino_id']; ?>">
                                            <?php echo $enviado ? 'Enviado ✅' : 'Enviar'; ?>
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($rol === 'administrador' || $rol === 'supervisor' || (!$enviado && !$aprobado)): ?>
                                    <a href="<?php echo $es_oficio ? 'editar_oficio.php?id=' : 'editar_constancia.php?id='; ?><?php echo $id; ?>" class="btn btn-sm btn-info">Editar</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if($total_paginas > 1): ?>
    <nav class="mt-4"><ul class="pagination justify-content-center">
        <?php 
            $qp = "&busqueda=".urlencode($busqueda)."&estado_filtro=".urlencode($filtro_estado)."&tipo_filtro=".urlencode($filtro_tipo)."&envio_filtro=".urlencode($filtro_envio)."&operador_filtro=".urlencode((string)$filtro_operador)."&fecha_inicio=".urlencode($fecha_inicio)."&fecha_fin=".urlencode($fecha_fin)."&area_filtro=".urlencode($filtro_area);
            $adj = 2;
        ?>
        <li class="page-item <?php echo ($pagina_actual <= 1) ? 'disabled' : ''; ?>"><a class="page-link" href="?pagina=<?php echo ($pagina_actual-1).$qp; ?>">«</a></li>
        <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
            <?php if ($i==1 || $i==$total_paginas || ($i>=$pagina_actual-$adj && $i<=$pagina_actual+$adj)): ?>
                <li class="page-item <?php echo ($i==$pagina_actual)?'active':''; ?>"><a class="page-link" href="?pagina=<?php echo $i.$qp; ?>"><?php echo $i; ?></a></li>
            <?php elseif ($i==$pagina_actual-$adj-1 || $i==$pagina_actual+$adj+1): ?>
                <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
        <?php endfor; ?>
        <li class="page-item <?php echo ($pagina_actual >= $total_paginas) ? 'disabled' : ''; ?>"><a class="page-link" href="?pagina=<?php echo ($pagina_actual+1).$qp; ?>">»</a></li>
    </ul></nav>
    <?php endif; ?>
</div>

<div class="modal fade" id="emailModalOficio" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5>Enviar Oficio</h5><button class="close" data-dismiss="modal">&times;</button></div>
        <div class="modal-body">
            <form id="formEnvioCorreo">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <label>Referencia:</label><input type="text" class="form-control mb-2" id="modalReferencia" name="referencia" readonly>
                <input type="hidden" id="modalMunicipioId" name="municipio_destino_id">
                <label>Correo Destinatario:</label><input type="email" class="form-control" name="email" placeholder="correo@ejemplo.com" readonly>
                <div id="mensajeEnvioOficio" class="mt-2"></div>
            </form>
        </div>
        <div class="modal-footer"><button class="btn btn-primary" id="btnEnviarCorreo">Enviar Ahora</button></div>
    </div></div>
</div>

<script src="bootstrap/js/jquery-3.7.1.min.js"></script>
<script src="bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
function exportar(formato) {
    const form = document.getElementById('formFiltros');
    const formData = new FormData(form);
    const params = new URLSearchParams(formData).toString();
    const url = (formato === 'excel') ? 'generar_excel_reporte.php?' : 'generar_pdf_reporte.php?';
    window.open(url + params, '_blank');
}
$(function(){
    $('.btn-habilitar').click(function(){
        let btn = $(this);
        if(!confirm('¿Habilitar este trámite?')) return;
        btn.prop('disabled',true).text('...');
        $.post(btn.data('type')==='OFICIO'?'habilitar_pdf.php':'habilitar_constancia.php', {id:btn.data('id'), csrf_token:'<?php echo $csrf_token; ?>'}, function(r){
            if(r.success) location.reload(); else { alert(r.message); btn.prop('disabled',false).text('Habilitar'); }
        },'json');
    });
    $('#emailModalOficio').on('show.bs.modal', function(e){
        let b = $(e.relatedTarget);
        $('#modalReferencia').val(b.data('referencia'));
        $('#modalMunicipioId').val(b.data('municipio-id'));
    });
    $('#btnEnviarCorreo').click(function(){
        $('#mensajeEnvioOficio').text('Enviando...');
        $.post('enviar_oficio_correo.php', $('#formEnvioCorreo').serialize(), function(r){
            $('#mensajeEnvioOficio').html(r.success ? '<div class="alert alert-success">Enviado</div>' : '<div class="alert alert-danger">'+r.message+'</div>');
            if(r.success) setTimeout(()=>location.reload(), 1000);
        },'json');
    });
});
</script>
</body>
</html>