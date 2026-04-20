<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$rol = $_SESSION['user_rol'] ?? 'normal';
$nombre_usuario = $_SESSION['nombre_usuario'] ?? 'Usuario';
$area_usuario = $_SESSION['area'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

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

try {
    $stmt = $pdo->prepare("
        SELECT oi.*, i.nombre_institucion, i.unidad_dependencia, i.email_contacto, i.ubicacion_sede
        FROM oficios_institucionales oi
        INNER JOIN instituciones i ON oi.id_institucion = i.id
        WHERE oi.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $oficio = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$oficio) {
        die("Oficio no encontrado.");
    }

    $puede_editar = in_array($rol, ['administrador', 'supervisor'], true)
        || ($rol === 'normal'
            && (int)$oficio['creado_por'] === $user_id
            && $oficio['estado_validacion'] === 'PENDIENTE');

    if (!$puede_editar) {
        die("Acceso denegado. No tienes permisos para editar este registro.");
    }

    $stmt_ent = $pdo->prepare("
        SELECT *
        FROM oficios_institucionales_entradas
        WHERE id_oficio_inst = ?
        ORDER BY id ASC
    ");
    $stmt_ent->execute([$id]);
    $entradas_raw = $stmt_ent->fetchAll(PDO::FETCH_ASSOC);

    $stmt_det = $pdo->prepare("
        SELECT *
        FROM oficios_institucionales_detalle
        WHERE id_oficio_inst = ?
        ORDER BY id ASC
    ");
    $stmt_det->execute([$id]);
    $detalles = $stmt_det->fetchAll(PDO::FETCH_ASSOC);

    $stmt_inst = $pdo->query("
        SELECT id, nombre_institucion, unidad_dependencia, email_contacto, ubicacion_sede
        FROM instituciones
        WHERE estado = 1
        ORDER BY nombre_institucion ASC
    ");
    $instituciones = $stmt_inst->fetchAll(PDO::FETCH_ASSOC);

    $carpeta_anexos = __DIR__ . '/anexos_institucionales/' . $oficio['referencia_salida'] . '/';
    $archivos_adjuntos = [];
    if (is_dir($carpeta_anexos)) {
        $archivos_adjuntos = array_values(array_filter(scandir($carpeta_anexos), function($f) use ($carpeta_anexos) {
            return pathinfo($f, PATHINFO_EXTENSION) === 'pdf' && is_file($carpeta_anexos . $f);
        }));
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrf_token = $_SESSION['csrf_token'];

} catch (Throwable $e) {
    error_log("Error editar_oficio_institucional: " . $e->getMessage());
    die("Error cargando datos: " . $e->getMessage());
}

// Agrupar entradas existentes por oficio
$entradas_agrupadas = [];
foreach ($entradas_raw as $ent) {
    $key = trim((string)$ent['num_oficio_in']) . '|' . trim((string)$ent['ref_expediente_in']) . '|' . trim((string)$ent['fecha_doc_in']);
    if (!isset($entradas_agrupadas[$key])) {
        $entradas_agrupadas[$key] = [
            'num_oficio_in' => $ent['num_oficio_in'],
            'ref_expediente_in' => $ent['ref_expediente_in'],
            'fecha_doc_in' => $ent['fecha_doc_in'],
            'peticiones' => []
        ];
    }
    $entradas_agrupadas[$key]['peticiones'][] = $ent;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editar Oficio Institucional</title>
    <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css" rel="stylesheet" />
    <style>
        body { background:#f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .container { margin-top:30px; max-width: 1200px; }
        .card { border: none; border-radius: 12px; }
        .oficio-bloque { background: #e9ecef; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #6c757d; }
        .oficio-header { font-weight: bold; color: #495057; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; }
        .peticion-item { background: #fff; padding: 12px; border-radius: 6px; margin-bottom: 10px; border: 1px solid #dee2e6; }
        .peticion-header { font-size: 0.85rem; color: #6c757d; margin-bottom: 8px; }
        .select2-container { width: 100% !important; }
        .peticiones-container { margin-top: 10px; }
        .cantidad-peticiones { max-width: 140px; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow mb-4">
    <a class="navbar-brand font-weight-bold" href="dashboard.php">Sistema de Trámites</a>
    <div class="collapse navbar-collapse">
        <ul class="navbar-nav mr-auto">
            <li class="nav-item"><a class="nav-link" href="crear_oficio.php">Crear Oficio</a></li>
            <li class="nav-item"><a class="nav-link" href="crear_constancia.php">Crear Certificación</a></li>
            <li class="nav-item"><a class="nav-link" href="crear_oficio_institucional.php">Crear Oficio Inst.</a></li>
            <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
        </ul>
        <span class="navbar-text mr-3 text-white">Editando: <strong><?php echo e($oficio['referencia_salida']); ?></strong></span>
        <a href="logout.php" class="btn btn-outline-light btn-sm">Cerrar Sesión</a>
    </div>
</nav>

<div class="container mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3>✏️ Editar Oficio Institucional</h3>
        <span class="badge badge-warning p-2">Modo Edición</span>
    </div>

    <form id="formEditarOficioInst" method="POST" action="actualizar_oficio_institucional.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
        <input type="hidden" name="id_oficio" value="<?php echo (int)$oficio['id']; ?>">

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white font-weight-bold">I. INFORMACIÓN GENERAL</div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-md-12">
                        <label class="font-weight-bold">Institución Solicitante:</label>
                        <select name="id_institucion" id="id_institucion" class="form-control" required>
                            <option value="">-- Seleccione --</option>
                            <?php foreach ($instituciones as $ins): ?>
                                <option value="<?php echo (int)$ins['id']; ?>" <?php echo ((int)$ins['id'] === (int)$oficio['id_institucion']) ? 'selected' : ''; ?>>
                                    <?php echo e($ins['nombre_institucion']); ?>
                                    <?php if (!empty($ins['unidad_dependencia'])) echo ' - ' . e($ins['unidad_dependencia']); ?>
                                    <?php if (!empty($ins['ubicacion_sede'])) echo ' - ' . e($ins['ubicacion_sede']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label class="small font-weight-bold">Email de Envío:</label>
                        <input type="email" name="email_envio" class="form-control" value="<?php echo e($oficio['email_envio'] ?? ''); ?>" placeholder="opcional">
                    </div>
                    <div class="form-group col-md-3">
                        <label class="small font-weight-bold">Estado Actual:</label>
                        <input type="text" class="form-control" value="<?php echo e($oficio['estado_validacion']); ?>" disabled>
                    </div>
                    <div class="form-group col-md-3">
                        <label class="small font-weight-bold">Fecha del Documento:</label>
                        <input type="date" name="fecha_documento" class="form-control" value="<?php echo e($oficio['fecha_documento']); ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span class="font-weight-bold">II. OFICIOS DE ENTRADA</span>
                <button type="button" id="btn_add_oficio_bloque" class="btn btn-success btn-sm font-weight-bold">+ Agregar Oficio</button>
            </div>
            <div class="card-body" id="contenedor_oficios_bloques">
                <?php foreach ($entradas_agrupadas as $idx => $grupo): ?>
                    <div class="oficio-bloque">
                        <div class="oficio-header">
                            <span>📬 Oficio de Entrada #<?php echo $idx + 1; ?></span>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-oficio-bloque">Eliminar Oficio</button>
                        </div>
                        <div class="form-row mb-3">
                            <div class="form-group col-md-3">
                                <label class="small font-weight-bold">N° Oficio Entrada:</label>
                                <input type="text" class="form-control form-control-sm oficio-num" value="<?php echo e($grupo['num_oficio_in']); ?>" required>
                            </div>
                            <div class="form-group col-md-3">
                                <label class="small font-weight-bold">Referencia:</label>
                                <input type="text" class="form-control form-control-sm oficio-ref" value="<?php echo e($grupo['ref_expediente_in']); ?>">
                            </div>
                            <div class="form-group col-md-3">
                                <label class="small font-weight-bold">Fecha Documento:</label>
                                <input type="date" class="form-control form-control-sm oficio-fecha" value="<?php echo e($grupo['fecha_doc_in']); ?>" required>
                            </div>
                            <div class="form-group col-md-3">
                                <label class="small font-weight-bold">Cantidad de Peticiones:</label>
                                <input type="number" min="1" value="<?php echo count($grupo['peticiones']); ?>" class="form-control form-control-sm cantidad-peticiones">
                            </div>
                        </div>
                        <div class="peticiones-container">
                            <?php foreach ($grupo['peticiones'] as $peticion): ?>
                                <div class="peticion-item">
                                    <div class="peticion-header">Petición</div>
                                    <input type="hidden" name="num_oficio_in[]" class="hid-oficio-num" value="<?php echo e($grupo['num_oficio_in']); ?>">
                                    <input type="hidden" name="ref_expediente_in[]" class="hid-oficio-ref" value="<?php echo e($grupo['ref_expediente_in']); ?>">
                                    <input type="hidden" name="fecha_doc_in[]" class="hid-oficio-fecha" value="<?php echo e($grupo['fecha_doc_in']); ?>">
                                    <div class="form-row">
                                        <div class="form-group col-md-4">
                                            <label class="small font-weight-bold text-danger">Partida Solicitada:</label>
                                            <select name="tipo_partida_solicitada[]" class="form-control form-control-sm" required>
                                                <option value="NACIMIENTO" <?php echo (($peticion['tipo_partida_solicitada'] ?? '') === 'NACIMIENTO') ? 'selected' : ''; ?>>NACIMIENTO</option>
                                                <option value="DEFUNCION" <?php echo (($peticion['tipo_partida_solicitada'] ?? '') === 'DEFUNCION') ? 'selected' : ''; ?>>DEFUNCIÓN</option>
                                                <option value="MATRIMONIO" <?php echo (($peticion['tipo_partida_solicitada'] ?? '') === 'MATRIMONIO') ? 'selected' : ''; ?>>MATRIMONIO</option>
                                                <option value="DIVORCIO" <?php echo (($peticion['tipo_partida_solicitada'] ?? '') === 'DIVORCIO') ? 'selected' : ''; ?>>DIVORCIO</option>
                                                <option value="CEDULA" <?php echo (($peticion['tipo_partida_solicitada'] ?? '') === 'CEDULA') ? 'selected' : ''; ?>>CÉDULA</option>
                                                <option value="CARNET" <?php echo (($peticion['tipo_partida_solicitada'] ?? '') === 'CARNET') ? 'selected' : ''; ?>>CARNET MINORIDAD</option>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-7">
                                            <label class="small font-weight-bold text-primary">Nombre según Oficio:</label>
                                            <input type="text" name="nombre_segun_oficio[]" class="form-control form-control-sm text-uppercase" value="<?php echo e($peticion['nombre_solicitado']); ?>" required>
                                        </div>
                                        <div class="col-md-1 d-flex align-items-end">
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-peticion">🗑️</button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                <span class="font-weight-bold">III. RESULTADOS DE BÚSQUEDA</span>
                <button type="button" id="btn_add_persona" class="btn btn-success btn-sm font-weight-bold">+ Agregar Registro</button>
            </div>
            <div class="card-body bg-light" id="contenedor_personas">
                <?php if (empty($detalles)): ?>
                    <p class="text-muted small">No hay resultados registrados aún. Use el botón para agregar.</p>
                <?php else: ?>
                    <?php foreach ($detalles as $det):
                        $is_found  = ($det['resultado'] === 'ENCONTRADO');
                        $is_family = in_array($det['tipo_tramite'], ['MATRIMONIO', 'DIVORCIO'], true);
                    ?>
                        <div class="persona-row mb-4 shadow-sm">
                            <span class="btn-remove">&times;</span>
                            <input type="hidden" name="detalle_id[]" value="<?php echo (int)$det['id']; ?>">
                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label class="small font-weight-bold">Nombre en Nuestros Registros:</label>
                                    <input type="text" name="nombre_consultado[]" class="form-control text-uppercase form-control-sm" value="<?php echo e($det['nombre_consultado']); ?>" required>
                                </div>
                                <div class="form-group col-md-2">
                                    <label class="small font-weight-bold">Tipo Trámite:</label>
                                    <select name="tipo_tramite[]" class="form-control form-control-sm sel-tipo-tramite">
                                        <option value="NACIMIENTO" <?php echo (($det['tipo_tramite'] ?? '') === 'NACIMIENTO') ? 'selected' : ''; ?>>NACIMIENTO</option>
                                        <option value="DEFUNCION"  <?php echo (($det['tipo_tramite'] ?? '') === 'DEFUNCION')  ? 'selected' : ''; ?>>DEFUNCIÓN</option>
                                        <option value="MATRIMONIO" <?php echo (($det['tipo_tramite'] ?? '') === 'MATRIMONIO') ? 'selected' : ''; ?>>MATRIMONIO</option>
                                        <option value="DIVORCIO"   <?php echo (($det['tipo_tramite'] ?? '') === 'DIVORCIO')   ? 'selected' : ''; ?>>DIVORCIO</option>
                                        <option value="CEDULA"     <?php echo (($det['tipo_tramite'] ?? '') === 'CEDULA')     ? 'selected' : ''; ?>>CÉDULA</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-2">
                                    <label class="small font-weight-bold">Resultado:</label>
                                    <select name="resultado[]" class="form-control form-control-sm sel-resultado" required>
                                        <option value="ENCONTRADO"    <?php echo (($det['resultado'] ?? '') === 'ENCONTRADO')    ? 'selected' : ''; ?>>ENCONTRADO</option>
                                        <option value="NO_ENCONTRADO" <?php echo (($det['resultado'] ?? '') === 'NO_ENCONTRADO') ? 'selected' : ''; ?>>NO ENCONTRADO</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="small font-weight-bold">Observaciones:</label>
                                    <input type="text" name="observaciones[]" class="form-control form-control-sm" value="<?php echo e($det['observaciones']); ?>">
                                </div>
                            </div>
                            <div class="panel-data">
                                <div class="form-row mb-3">
                                    <div class="col-md-6">
                                        <label class="small font-weight-bold lbl-filiacion-1"><?php echo $is_family ? 'Cónyuge 1:' : 'Madre:'; ?></label>
                                        <input type="text" name="padre_conyuge_1[]" class="form-control form-control-sm text-uppercase" value="<?php echo e($det['filiacion_1']); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small font-weight-bold lbl-filiacion-2"><?php echo $is_family ? 'Cónyuge 2:' : 'Padre:'; ?></label>
                                        <input type="text" name="padre_conyuge_2[]" class="form-control form-control-sm text-uppercase" value="<?php echo e($det['filiacion_2']); ?>">
                                    </div>
                                </div>
                                <div class="form-row panel-partida <?php echo $is_found ? '' : 'd-none'; ?>">
                                    <div class="col-md-3"><label class="small">Partida N°:</label><input type="text" name="partida[]" class="form-control form-control-sm" value="<?php echo e($det['partida_numero']); ?>"></div>
                                    <div class="col-md-3"><label class="small">Folio:</label><input type="text" name="folio[]" class="form-control form-control-sm" value="<?php echo e($det['partida_folio']); ?>"></div>
                                    <div class="col-md-3"><label class="small">Libro:</label><input type="text" name="libro[]" class="form-control form-control-sm" value="<?php echo e($det['partida_libro']); ?>"></div>
                                    <div class="col-md-3"><label class="small">Año:</label><input type="number" name="anio[]" class="form-control form-control-sm" value="<?php echo e($det['partida_anio']); ?>" min="1900" max="<?php echo date('Y'); ?>"></div>
                                </div>
                                <div class="form-row panel-fecha mt-2">
                                    <div class="col-md-4"><label class="small font-weight-bold">Fecha del Evento:</label><input type="date" name="fecha_evento[]" class="form-control form-control-sm" value="<?php echo e($det['fecha_evento']); ?>"></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-info text-white font-weight-bold">IV. ARCHIVOS ADJUNTOS</div>
            <div class="card-body">
                <?php if (!empty($archivos_adjuntos)): ?>
                    <p class="small text-muted mb-2">Archivos actuales (marque para eliminar):</p>
                    <?php foreach ($archivos_adjuntos as $archivo): ?>
                        <div class="mb-1">
                            📎 <?php echo e($archivo); ?>
                            <label class="ml-2">
                                <input type="checkbox" name="eliminar_adjunto[]" value="<?php echo e($archivo); ?>" style="vertical-align:middle;"> Eliminar
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <hr>
                <?php endif; ?>
                <div class="form-group">
                    <label class="small font-weight-bold">Agregar nuevos archivos PDF:</label>
                    <input type="file" name="archivos_adjuntos[]" id="archivos_adjuntos" class="form-control-file" multiple accept="application/pdf">
                    <small class="form-text text-muted">Solo archivos PDF. Máximo 10 MB por archivo.</small>
                    <div id="adjunto_error" class="text-danger small mt-1"></div>
                </div>
            </div>
        </div>

        <div class="text-right pb-5">
            <a href="ver_oficio_institucional.php?id=<?php echo (int)$oficio['id']; ?>" class="btn btn-secondary mr-2">Cancelar</a>
            <button type="submit" id="btnSubmit" class="btn btn-primary btn-lg shadow">💾 Guardar Cambios</button>
        </div>
    </form>
</div>

<template id="template_persona">
    <div class="persona-row mb-4 shadow-sm">
        <span class="btn-remove">&times;</span>
        <div class="form-row">
            <div class="form-group col-md-4">
                <label class="small font-weight-bold">Nombre en Nuestros Registros:</label>
                <input type="text" name="nombre_consultado[]" class="form-control text-uppercase form-control-sm" required>
            </div>
            <div class="form-group col-md-2">
                <label class="small font-weight-bold">Tipo Trámite:</label>
                <select name="tipo_tramite[]" class="form-control form-control-sm sel-tipo-tramite">
                    <option value="NACIMIENTO">NACIMIENTO</option>
                    <option value="DEFUNCION">DEFUNCIÓN</option>
                    <option value="MATRIMONIO">MATRIMONIO</option>
                    <option value="DIVORCIO">DIVORCIO</option>
                    <option value="CEDULA">CÉDULA</option>
                </select>
            </div>
            <div class="form-group col-md-2">
                <label class="small font-weight-bold">Resultado:</label>
                <select name="resultado[]" class="form-control form-control-sm sel-resultado" required>
                    <option value="NO_ENCONTRADO">NO ENCONTRADO</option>
                    <option value="ENCONTRADO">ENCONTRADO</option>
                </select>
            </div>
            <div class="form-group col-md-4">
                <label class="small font-weight-bold">Observaciones:</label>
                <input type="text" name="observaciones[]" class="form-control form-control-sm">
            </div>
        </div>
        <div class="panel-data">
            <div class="form-row mb-3">
                <div class="col-md-6"><label class="small font-weight-bold lbl-filiacion-1">Nombre de la Madre:</label><input type="text" name="padre_conyuge_1[]" class="form-control form-control-sm text-uppercase"></div>
                <div class="col-md-6"><label class="small font-weight-bold lbl-filiacion-2">Nombre del Padre:</label><input type="text" name="padre_conyuge_2[]" class="form-control form-control-sm text-uppercase"></div>
            </div>
            <div class="form-row panel-partida d-none">
                <div class="col-md-3"><label class="small">Partida N°:</label><input type="text" name="partida[]" class="form-control form-control-sm"></div>
                <div class="col-md-3"><label class="small">Folio:</label><input type="text" name="folio[]" class="form-control form-control-sm"></div>
                <div class="col-md-3"><label class="small">Libro:</label><input type="text" name="libro[]" class="form-control form-control-sm"></div>
                <div class="col-md-3"><label class="small">Año:</label><input type="number" name="anio[]" class="form-control form-control-sm"></div>
            </div>
            <div class="form-row panel-fecha mt-2">
                <div class="col-md-4"><label class="small font-weight-bold">Fecha del Evento:</label><input type="date" name="fecha_evento[]" class="form-control form-control-sm"></div>
            </div>
        </div>
    </div>
</template>

<template id="template_oficio_bloque">
    <div class="oficio-bloque">
        <div class="oficio-header">
            <span>📬 Oficio de Entrada #<span class="oficio-num-display">1</span></span>
            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-oficio-bloque">Eliminar Oficio</button>
        </div>
        <div class="form-row mb-3">
            <div class="form-group col-md-3">
                <label class="small font-weight-bold">N° Oficio Entrada:</label>
                <input type="text" class="form-control form-control-sm oficio-num" required>
            </div>
            <div class="form-group col-md-3">
                <label class="small font-weight-bold">Referencia:</label>
                <input type="text" class="form-control form-control-sm oficio-ref">
            </div>
            <div class="form-group col-md-3">
                <label class="small font-weight-bold">Fecha Documento:</label>
                <input type="date" class="form-control form-control-sm oficio-fecha" required>
            </div>
            <div class="form-group col-md-3">
                <label class="small font-weight-bold">Cantidad de Peticiones:</label>
                <input type="number" min="1" value="1" class="form-control form-control-sm cantidad-peticiones">
            </div>
        </div>
        <div class="peticiones-container">
            <div class="peticion-item">
                <div class="peticion-header">Petición</div>
                <input type="hidden" name="num_oficio_in[]" class="hid-oficio-num">
                <input type="hidden" name="ref_expediente_in[]" class="hid-oficio-ref">
                <input type="hidden" name="fecha_doc_in[]" class="hid-oficio-fecha">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label class="small font-weight-bold text-danger">Partida Solicitada:</label>
                        <select name="tipo_partida_solicitada[]" class="form-control form-control-sm" required>
                            <option value="NACIMIENTO">NACIMIENTO</option>
                            <option value="DEFUNCION">DEFUNCIÓN</option>
                            <option value="MATRIMONIO">MATRIMONIO</option>
                            <option value="DIVORCIO">DIVORCIO</option>
                            <option value="CEDULA">CÉDULA</option>
                            <option value="CARNET">CARNET MINORIDAD</option>
                        </select>
                    </div>
                    <div class="form-group col-md-7">
                        <label class="small font-weight-bold text-primary">Nombre según Oficio:</label>
                        <input type="text" name="nombre_segun_oficio[]" class="form-control form-control-sm text-uppercase" required>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-peticion">🗑️</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script src="bootstrap/js/jquery-3.7.1.min.js"></script>
<script src="bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('#id_institucion').select2({ theme: 'bootstrap4' });

    $(document).on('change', '.cantidad-peticiones', function() {
        let cantidad = parseInt($(this).val(), 10);
        if (isNaN(cantidad) || cantidad < 1) cantidad = 1;
        $(this).val(cantidad);

        const container = $(this).closest('.oficio-bloque').find('.peticiones-container');
        const actual = container.find('.peticion-item').length;

        if (cantidad < actual) {
            container.find('.peticion-item').slice(cantidad).remove();
        } else if (cantidad > actual) {
            for (let i = actual; i < cantidad; i++) {
                container.append($('#template_oficio_bloque .peticion-item').length ? '' : '');
                const nuevo = `
                <div class="peticion-item">
                    <div class="peticion-header">Petición</div>
                    <input type="hidden" name="num_oficio_in[]" class="hid-oficio-num">
                    <input type="hidden" name="ref_expediente_in[]" class="hid-oficio-ref">
                    <input type="hidden" name="fecha_doc_in[]" class="hid-oficio-fecha">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label class="small font-weight-bold text-danger">Partida Solicitada:</label>
                            <select name="tipo_partida_solicitada[]" class="form-control form-control-sm" required>
                                <option value="NACIMIENTO">NACIMIENTO</option>
                                <option value="DEFUNCION">DEFUNCIÓN</option>
                                <option value="MATRIMONIO">MATRIMONIO</option>
                                <option value="DIVORCIO">DIVORCIO</option>
                                <option value="CEDULA">CÉDULA</option>
                                <option value="CARNET">CARNET MINORIDAD</option>
                            </select>
                        </div>
                        <div class="form-group col-md-7">
                            <label class="small font-weight-bold text-primary">Nombre según Oficio:</label>
                            <input type="text" name="nombre_segun_oficio[]" class="form-control form-control-sm text-uppercase" required>
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-peticion">🗑️</button>
                        </div>
                    </div>
                </div>`;
                container.append(nuevo);
            }
            syncOficioHiddens($(this).closest('.oficio-bloque'));
        }
    });

    $(document).on('click', '.btn-remove-peticion', function() {
        const container = $(this).closest('.peticiones-container');
        if (container.find('.peticion-item').length > 1) {
            $(this).closest('.peticion-item').remove();
            $(this).closest('.oficio-bloque').find('.cantidad-peticiones').val(container.find('.peticion-item').length);
        } else {
            alert('Cada oficio debe tener al menos una petición.');
        }
    });

    $('#btn_add_oficio_bloque').click(function() {
        const nuevoBloque = $($('#template_oficio_bloque').html());
        $('#contenedor_oficios_bloques').append(nuevoBloque);
        nuevoBloque.find('.cantidad-peticiones').trigger('change');
    });

    $(document).on('click', '.btn-remove-oficio-bloque', function() {
        if ($('.oficio-bloque').length > 1) {
            $(this).closest('.oficio-bloque').remove();
        } else {
            alert('Debe existir al menos un oficio de entrada.');
        }
    });

    /* ── Personas / Resultados ── */
    function addPersonaRow() {
        $('#contenedor_personas').append(document.getElementById('template_persona').content.cloneNode(true));
        $('.sel-tipo-tramite').last().trigger('change');
        $('.sel-resultado').last().trigger('change');
    }
    if ($('#contenedor_personas .persona-row').length === 0) {
        addPersonaRow();
    }
    $('#btn_add_persona').click(addPersonaRow);

    $(document).on('click', '.btn-remove', function() {
        if ($('.persona-row').length > 1) {
            $(this).closest('.persona-row').remove();
        }
    });

    $(document).on('change', '.sel-tipo-tramite', function() {
        const row = $(this).closest('.persona-row');
        const isFamily = ($(this).val() === 'MATRIMONIO' || $(this).val() === 'DIVORCIO');
        row.find('.lbl-filiacion-1').text(isFamily ? 'Nombre del Cónyuge 1:' : 'Nombre de la Madre:');
        row.find('.lbl-filiacion-2').text(isFamily ? 'Nombre del Cónyuge 2:' : 'Nombre del Padre:');
    });

    $(document).on('change', '.sel-resultado', function() {
        const row = $(this).closest('.persona-row');
        if ($(this).val() === 'ENCONTRADO') {
            row.find('.panel-partida').removeClass('d-none');
            row.css('border-left', '5px solid #28a745');
        } else {
            row.find('.panel-partida').addClass('d-none');
            row.css('border-left', '5px solid #ffc107');
        }
    });

    $('.sel-tipo-tramite').trigger('change');
    $('.sel-resultado').trigger('change');

    /* ── Adjuntos ── */
    $('#archivos_adjuntos').on('change', function() {
        const $error = $('#adjunto_error');
        $error.text('');
        for (const file of this.files) {
            if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
                $error.text('❌ Solo se permiten archivos PDF.');
                this.value = '';
                return;
            }
            if (file.size > 10 * 1024 * 1024) {
                $error.text('❌ El archivo "' + file.name + '" excede 10 MB.');
                this.value = '';
                return;
            }
        }
    });

    /* ── Sync oficio-level visible inputs → hidden inputs per petición ── */
    function syncOficioHiddens($bloque) {
        const num = $bloque.find('.oficio-num').val() || '';
        const ref = $bloque.find('.oficio-ref').val() || '';
        const fec = $bloque.find('.oficio-fecha').val() || '';
        $bloque.find('.peticiones-container .peticion-item').each(function() {
            $(this).find('.hid-oficio-num').val(num);
            $(this).find('.hid-oficio-ref').val(ref);
            $(this).find('.hid-oficio-fecha').val(fec);
        });
    }

    $(document).on('input change', '.oficio-num, .oficio-ref, .oficio-fecha', function() {
        syncOficioHiddens($(this).closest('.oficio-bloque'));
    });

    $('#formEditarOficioInst').on('submit', function() {
        $('#contenedor_oficios_bloques .oficio-bloque').each(function() {
            syncOficioHiddens($(this));
        });
        $('#btnSubmit').prop('disabled', true).html('Guardando...');
    });
});
</script>
</body>
</html>