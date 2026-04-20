<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';

$rol = $_SESSION['user_rol'] ?? 'normal';
$user_id = (int)($_SESSION['user_id'] ?? 0);
$nombre_usuario = $_SESSION['nombre_usuario'] ?? 'Usuario';
$area_usuario = $_SESSION['area'] ?? '';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    die("ID de oficio inválido.");
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fechaFormateada(?string $fecha): string {
    if (!$fecha) return 'N/A';
    $meses = ["enero","febrero","marzo","abril","mayo","junio","julio","agosto","septiembre","octubre","noviembre","diciembre"];
    $timestamp = strtotime($fecha);
    if (!$timestamp) return 'N/A';
    return date('d', $timestamp) . ' de ' . $meses[(int)date('n', $timestamp) - 1] . ' del ' . date('Y', $timestamp);
}

function tipoPartidaTexto(string $tipo): string {
    $map = [
        'NACIMIENTO' => 'NACIMIENTO',
        'DEFUNCION'  => 'DEFUNCIÓN',
        'MATRIMONIO' => 'MATRIMONIO',
        'DIVORCIO'   => 'DIVORCIO',
        'CEDULA'     => 'CÉDULA',
        'CARNET'     => 'CARNET MINORIDAD',
    ];
    $key = strtoupper(trim($tipo));
    return $map[$key] ?? $tipo;
}

try {
    $stmt = $pdo->prepare("
        SELECT oi.*, i.nombre_institucion, i.unidad_dependencia, i.ubicacion_sede, i.email_contacto,
               u.nombre_completo AS creado_por_nombre, u.area AS creado_por_area,
               ua.nombre_completo AS aprobado_por_nombre
        FROM oficios_institucionales oi
        INNER JOIN instituciones i ON oi.id_institucion = i.id
        LEFT JOIN usuarios u ON oi.creado_por = u.id
        LEFT JOIN usuarios ua ON oi.aprobado_por = ua.id
        WHERE oi.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $oficio = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$oficio) {
        die("Oficio no encontrado.");
    }

    if ($rol === 'normal' && (int)$oficio['creado_por'] !== $user_id) {
        die("Acceso denegado. No puedes ver este oficio.");
    }

    if ($rol === 'supervisor' && $area_usuario !== '' && (($oficio['creado_por_area'] ?? '') !== $area_usuario)) {
        die("Acceso denegado. No puedes ver oficios de otras áreas.");
    }

    $stmt_in = $pdo->prepare("
        SELECT *
        FROM oficios_institucionales_entradas
        WHERE id_oficio_inst = ?
        ORDER BY id ASC
    ");
    $stmt_in->execute([$id]);
    $entradas = $stmt_in->fetchAll(PDO::FETCH_ASSOC);

    $stmt_det = $pdo->prepare("
        SELECT *
        FROM oficios_institucionales_detalle
        WHERE id_oficio_inst = ?
        ORDER BY id ASC
    ");
    $stmt_det->execute([$id]);
    $detalles = $stmt_det->fetchAll(PDO::FETCH_ASSOC);

    $carpeta_anexos = __DIR__ . '/anexos_institucionales/' . $oficio['referencia_salida'] . '/';
    $archivos_adjuntos = [];
    if (is_dir($carpeta_anexos)) {
        $archivos_adjuntos = array_values(array_filter(scandir($carpeta_anexos), function($f) use ($carpeta_anexos) {
            return $f !== '.' && $f !== '..' && strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'pdf' && is_file($carpeta_anexos . $f);
        }));
        sort($archivos_adjuntos);
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrf_token = $_SESSION['csrf_token'];

} catch (Throwable $e) {
    error_log("Error ver_oficio_institucional: " . $e->getMessage());
    die("Error al cargar la información del oficio.");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Detalle Oficio Institucional</title>
<link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
<style>
body{background:#f8f9fa}
.container{margin-top:10px}
.info-label{font-size:0.8rem; color:#6c757d; margin-bottom:2px; text-transform:uppercase;}
.info-value{font-weight:600; font-size:1rem;}
.table thead th{background-color:#343a40; color:#fff; font-size:0.85rem;}
.table tbody td{font-size:0.9rem; vertical-align: middle;}
.status-badge{font-size:0.9rem; padding:6px 12px;}
.adjunto-item { display:inline-block; margin:5px; padding:8px 12px; background:#e9ecef; border-radius:4px; font-size:0.85rem; }
.adjunto-item a { margin-left:8px; }
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
<li class="nav-item"><a class="nav-link" href="crear_oficio_institucional.php">Crear Oficio Inst.</a></li>
<li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
<?php if ($rol === 'administrador'): ?>
<li class="nav-item dropdown">
<a class="nav-link dropdown-toggle" href="#" id="navCatalogos" role="button" data-toggle="dropdown">Administración</a>
<div class="dropdown-menu shadow">
<a class="dropdown-item" href="catalogos/admin_departamentos.php">Departamentos</a>
<a class="dropdown-item" href="catalogos/admin_municipios.php">Municipios</a>
<a class="dropdown-item" href="catalogos/admin_distritos.php">Distritos</a>
<a class="dropdown-item" href="catalogos/admin_instituciones.php">Instituciones</a>
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
<?php if (in_array($rol, ['administrador', 'supervisor'], true)): ?>
<li class="nav-item"><a class="nav-link" href="panel_envios.php">Panel Envíos</a></li>
<?php endif; ?>
<?php if (in_array($rol, ['normal', 'supervisor'], true)): ?>
<li class="nav-item"><a class="nav-link" href="#" data-toggle="modal" data-target="#modalPassword">Mi Cuenta</a></li>
<?php endif; ?>
</ul>
<span class="navbar-text mr-3 text-white">Bienvenido, <strong><?php echo e($nombre_usuario); ?></strong></span>
<a href="logout.php" class="btn btn-outline-light btn-sm">Cerrar Sesión</a>
</div>
</nav>
<div class="container mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">📄 Detalle de Oficio Institucional</h4>
            <span class="text-muted">Referencia: <strong><?php echo e($oficio['referencia_salida']); ?></strong></span>
        </div>
        <span class="badge badge-<?php echo ($oficio['estado_validacion'] === 'APROBADO') ? 'success' : (($oficio['estado_validacion'] === 'PENDIENTE') ? 'warning' : 'danger'); ?> status-badge">
            <?php echo e($oficio['estado_validacion']); ?>
        </span>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white font-weight-bold">I. INFORMACIÓN GENERAL</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <div class="info-label">Institución Solicitante</div>
                    <div class="info-value"><?php echo e($oficio['nombre_institucion']); ?></div>
                    <?php if (!empty($oficio['unidad_dependencia'])): ?>
                        <small class="text-muted"><?php echo e($oficio['unidad_dependencia']); ?> - <?php echo e($oficio['ubicacion_sede']); ?></small>
                    <?php endif; ?>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="info-label">Elaborado Por</div>
                    <div class="info-value"><?php echo e($oficio['creado_por_nombre'] ?: 'Sistema'); ?></div>
                    <small class="text-muted"><?php echo e($oficio['creado_por_area'] ?: 'N/A'); ?></small>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="info-label">Fecha del Documento</div>
                    <div class="info-value"><?php echo fechaFormateada($oficio['fecha_documento']); ?></div>
                    <small class="text-muted">Registro: <?php echo fechaFormateada($oficio['fecha']); ?></small>
                </div>
            </div>
            <?php if (!empty($oficio['email_envio'])): ?>
            <div class="row">
                <div class="col-12">
                    <div class="info-label">Correo Institucional</div>
                    <div class="info-value"><a href="mailto:<?php echo e($oficio['email_envio']); ?>"><?php echo e($oficio['email_envio']); ?></a></div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($oficio['estado_validacion'] === 'APROBADO' && !empty($oficio['aprobado_por_nombre'])): ?>
            <div class="row mt-2">
                <div class="col-md-6">
                    <div class="info-label">Aprobado Por</div>
                    <div class="info-value"><?php echo e($oficio['aprobado_por_nombre']); ?></div>
                </div>
                <div class="col-md-6">
                    <div class="info-label">Fecha de Aprobación</div>
                    <div class="info-value"><?php echo fechaFormateada($oficio['fecha_aprobacion'] ?? null); ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white font-weight-bold">II. OFICIOS DE ENTRADA SOLICITADOS</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead>
                        <tr>
                            <th>N° Oficio Entrada</th>
                            <th>Referencia Expediente</th>
                            <th>Fecha Documento</th>
                            <th>Partida Solicitada</th>
                            <th>Nombre según Oficio</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($entradas)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No se registraron oficios de entrada.</td></tr>
                        <?php else: ?>
                            <?php foreach ($entradas as $ent): ?>
                            <tr>
                                <td><?php echo e($ent['num_oficio_in']); ?></td>
                                <td><?php echo e($ent['ref_expediente_in']); ?></td>
                                <td><?php echo fechaFormateada($ent['fecha_doc_in']); ?></td>
                                <td><span class="badge badge-light"><?php echo e(tipoPartidaTexto((string)$ent['tipo_partida_solicitada'])); ?></span></td>
                                <td><?php echo e($ent['nombre_solicitado']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white font-weight-bold">III. RESULTADOS DE BÚSQUEDA</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead>
                        <tr>
                            <th>Nombre Consultado</th>
                            <th>Nombre según Oficio</th>
                            <th>Tipo Trámite</th>
                            <th>Resultado</th>
                            <th>Partida / Folio / Libro / Año</th>
                            <th>Observaciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($detalles)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">No se registraron búsquedas.</td></tr>
                        <?php else: ?>
                            <?php foreach ($detalles as $det): ?>
                            <tr>
                                <td>
                                    <strong><?php echo e($det['nombre_consultado']); ?></strong>
                                    <?php if (!empty($det['filiacion_1']) || !empty($det['filiacion_2'])): ?>
                                        <br><small class="text-muted">Hijo/a de: <?php echo e(trim(($det['filiacion_1'] ?? '') . (($det['filiacion_1'] && $det['filiacion_2']) ? ' y ' : '') . ($det['filiacion_2'] ?? ''))); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo e($det['nombre_segun_oficio'] ?? ''); ?></td>
                                <td><?php echo e(tipoPartidaTexto((string)$det['tipo_tramite'])); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo ($det['resultado'] === 'ENCONTRADO') ? 'success' : 'danger'; ?>">
                                        <?php echo e($det['resultado']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($det['partida_numero'])): ?>
                                        <?php echo e($det['partida_numero']); ?> / <?php echo e($det['partida_folio']); ?> / <?php echo e($det['partida_libro']); ?> / <?php echo e($det['partida_anio']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?php echo e($det['observaciones']); ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if (!empty($archivos_adjuntos)): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-info text-white font-weight-bold">IV. ARCHIVOS ADJUNTOS</div>
        <div class="card-body">
            <?php foreach ($archivos_adjuntos as $archivo): ?>
                <div class="adjunto-item">
                    📎 <?php echo e($archivo); ?>
                    <a href="<?php echo e('anexos_institucionales/' . $oficio['referencia_salida'] . '/' . $archivo); ?>" target="_blank" class="btn btn-sm btn-outline-primary">Abrir</a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<script src="bootstrap/js/jquery-3.7.1.min.js"></script>
<script src="bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>