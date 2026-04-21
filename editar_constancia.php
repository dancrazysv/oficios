<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$rol            = $_SESSION['user_rol']      ?? 'normal';
$nombre_usuario = $_SESSION['nombre_usuario'] ?? 'Usuario';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    die("ID de constancia inválido.");
}

try {
    /* ── Constancia principal ─────────────────────────────────────── */
    $stmt = $pdo->prepare("SELECT * FROM constancias WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$c) {
        die("Constancia no encontrada.");
    }

    /* ── Catálogos generales ──────────────────────────────────────── */
    $tipos_documento = $pdo->query("SELECT id, nombre FROM tipos_documento ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
    $departamentos   = $pdo->query("SELECT id, nombre FROM departamentos ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

    /* ── Soportes NAC / DEF ───────────────────────────────────────── */
    $soportes_nac = $pdo->query("SELECT codigo_slug, nombre_oficial FROM catalogo_soportes WHERE categoria IN ('NACIMIENTO','AMBOS') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $soportes_def = $pdo->query("SELECT codigo_slug, nombre_oficial FROM catalogo_soportes WHERE categoria IN ('DEFUNCION','AMBOS') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    /* ── Resolución de ubicación NAC (distrito → municipio → depto) ─ */
    $nac_dep_id = 0; $nac_muni_id = 0; $nac_dist_id = 0;
    $nac_dep_nombre = ''; $nac_muni_nombre = ''; $nac_dist_nombre = '';
    if (!empty($c['distrito_nacimiento_id'])) {
        $sq = $pdo->prepare("
            SELECT d.id AS dist_id, d.nombre AS dist_nombre,
                   m.id AS muni_id, m.nombre AS muni_nombre,
                   dep.id AS dep_id, dep.nombre AS dep_nombre
            FROM distritos d
            JOIN municipios m ON d.municipio_id = m.id
            JOIN departamentos dep ON m.departamento_id = dep.id
            WHERE d.id = ? LIMIT 1");
        $sq->execute([$c['distrito_nacimiento_id']]);
        $row = $sq->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $nac_dep_id    = (int)$row['dep_id'];   $nac_dep_nombre  = $row['dep_nombre'];
            $nac_muni_id   = (int)$row['muni_id'];  $nac_muni_nombre = $row['muni_nombre'];
            $nac_dist_id   = (int)$row['dist_id'];  $nac_dist_nombre = $row['dist_nombre'];
        }
    }

    /* ── Resolución de ubicación DEF ─────────────────────────────── */
    $def_dep_nombre = ''; $def_muni_nombre = ''; $def_dist_nombre = '';
    if (!empty($c['def_departamento_id'])) {
        $sq = $pdo->prepare("SELECT nombre FROM departamentos WHERE id = ? LIMIT 1");
        $sq->execute([$c['def_departamento_id']]);
        $def_dep_nombre = (string)($sq->fetchColumn() ?: '');
    }
    if (!empty($c['def_municipio_id'])) {
        $sq = $pdo->prepare("SELECT nombre FROM municipios WHERE id = ? LIMIT 1");
        $sq->execute([$c['def_municipio_id']]);
        $def_muni_nombre = (string)($sq->fetchColumn() ?: '');
    }
    if (!empty($c['def_distrito_id'])) {
        $sq = $pdo->prepare("SELECT nombre FROM distritos WHERE id = ? LIMIT 1");
        $sq->execute([$c['def_distrito_id']]);
        $def_dist_nombre = (string)($sq->fetchColumn() ?: '');
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrf_token = $_SESSION['csrf_token'];

} catch (Throwable $e) {
    error_log("Error editar_constancia: " . $e->getMessage());
    die("Error cargando datos: " . $e->getMessage());
}

function e(mixed $t): string { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editar Certificación | <?php echo e($c['numero_constancia']); ?></title>
    <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
    <style>
        body { background:#f8f9fa; font-family:'Segoe UI',Tahoma,Verdana; }
        .main-card { background:#fff; padding:30px; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,.08); margin-top:30px; }
        .section-title { border-bottom:2px solid #007bff; padding-bottom:8px; margin-bottom:16px; color:#007bff; font-weight:bold; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow mb-4">
    <a class="navbar-brand font-weight-bold" href="dashboard.php">Sistema de Trámites</a>
    <div class="collapse navbar-collapse">
        <ul class="navbar-nav mr-auto">
            <li class="nav-item"><a class="nav-link" href="crear_constancia.php">Crear Certificación</a></li>
            <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
        </ul>
        <span class="navbar-text mr-3 text-white">Bienvenido, <strong><?php echo e($nombre_usuario); ?></strong></span>
        <a href="logout.php" class="btn btn-outline-light btn-sm">Cerrar Sesión</a>
    </div>
</nav>

<div class="container mb-5">
    <div class="main-card">
        <h4 class="mb-1">✏️ Editar Certificación</h4>
        <p class="text-muted mb-4">Referencia: <strong><?php echo e($c['numero_constancia']); ?></strong> &mdash; Tipo: <strong><?php echo e($c['tipo_constancia']); ?></strong></p>

        <?php if ($c['estado_validacion'] !== 'PENDIENTE'): ?>
        <div class="alert alert-warning">
            ⚠️ Esta constancia tiene estado <strong><?php echo e($c['estado_validacion']); ?></strong>. La edición regenerará el PDF.
        </div>
        <?php endif; ?>

        <form id="formEditarConstancia">
            <input type="hidden" name="csrf_token"       value="<?php echo e($csrf_token); ?>">
            <input type="hidden" name="id_constancia"    value="<?php echo (int)$c['id']; ?>">
            <input type="hidden" name="tipo_constancia_id" value="<?php echo e($c['tipo_constancia']); ?>">

            <!-- Hidden location name fields (updated by JS cascade) -->
            <input type="hidden" name="nac_municipio_nombre"   id="nac_municipio_nombre"   value="<?php echo e($nac_muni_nombre); ?>">
            <input type="hidden" name="nac_departamento_nombre" id="nac_departamento_nombre" value="<?php echo e($nac_dep_nombre); ?>">
            <input type="hidden" name="nac_distrito_nombre"    id="nac_distrito_nombre"    value="<?php echo e($nac_dist_nombre); ?>">
            <input type="hidden" name="def_departamento_nombre" id="def_departamento_nombre" value="<?php echo e($def_dep_nombre); ?>">
            <input type="hidden" name="def_municipio_nombre"   id="def_municipio_nombre"   value="<?php echo e($def_muni_nombre); ?>">
            <input type="hidden" name="def_distrito_nombre"    id="def_distrito_nombre"    value="<?php echo e($def_dist_nombre); ?>">

            <!-- ══ DATOS DEL SOLICITANTE ══════════════════════════════════════ -->
            <div class="p-4 border rounded bg-light mb-4 shadow-sm">
                <h5 class="section-title">Datos del Solicitante</h5>
                <div class="form-row">
                    <div class="col-md-4">
                        <select class="form-control" name="tipo_documento_id" id="tipo_documento_id">
                            <option value="">Tipo Documento</option>
                            <?php foreach ($tipos_documento as $td): ?>
                                <option value="<?php echo (int)$td['id']; ?>" <?php echo ((int)$td['id'] === (int)$c['tipo_documento_id']) ? 'selected' : ''; ?>>
                                    <?php echo e($td['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <input type="text" class="form-control" name="numero_documento" value="<?php echo e($c['numero_documento']); ?>" placeholder="Número de Documento">
                    </div>
                </div>
                <div class="mt-3">
                    <label>Nombre del Ciudadano</label>
                    <input type="text" class="form-control font-weight-bold text-uppercase" name="nombre_solicitante" value="<?php echo e($c['nombre_solicitante']); ?>">
                </div>
            </div>

            <!-- ══ SECCIÓN ESPECÍFICA POR TIPO ════════════════════════════════ -->

            <?php if ($c['tipo_constancia'] === 'NO_REGISTRO_NAC'): ?>
            <!-- ── NAC ─────────────────────────────────────────────────────── -->
            <div class="form-section shadow-sm p-3 mb-4 bg-white rounded border">
                <h5 class="text-primary mb-4 border-bottom pb-2">Datos de Nacimiento</h5>

                <div class="form-group">
                    <label><strong>No aparece registrada partida de nacimiento a nombre de:</strong></label>
                    <input type="text" class="form-control text-uppercase" name="nac_nombre_no_registro" value="<?php echo e($c['nombre_no_registro']); ?>" required>
                </div>

                <div class="form-group">
                    <label><strong>Según: (Documento de Nacimiento)</strong></label>
                    <select class="form-control" name="nac_tipo_soporte" id="nac_tipo_soporte">
                        <option value="">Seleccione el documento de soporte...</option>
                        <?php foreach ($soportes_nac as $s): ?>
                            <option value="<?php echo e($s['codigo_slug']); ?>" <?php echo ($s['codigo_slug'] === $c['tipo_soporte']) ? 'selected' : ''; ?>>
                                <?php echo e($s['nombre_oficial']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="nac_contenedor_hospital" class="form-group" <?php echo empty($c['nombre_hospital']) ? 'style="display:none"' : ''; ?>>
                    <label><strong>Hospital / Institución</strong></label>
                    <input type="text" class="form-control border-primary" name="nac_nombre_hospital" value="<?php echo e($c['nombre_hospital'] ?? ''); ?>" list="lista_hospitales">
                </div>

                <div class="form-row mt-3">
                    <div class="col-md-6">
                        <label><strong>Fecha de nacimiento</strong></label>
                        <input type="date" class="form-control" name="nac_fecha_nacimiento" value="<?php echo e($c['fecha_nacimiento'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label><strong>Hora de nacimiento (Opcional)</strong></label>
                        <input type="time" class="form-control" name="nac_hora_nacimiento" value="<?php echo e($c['hora_nacimiento'] ?? ''); ?>">
                    </div>
                </div>

                <div class="custom-control custom-checkbox mb-3 mt-3">
                    <input type="checkbox" class="custom-control-input" id="nac_es_exterior" name="es_exterior" value="1" <?php echo (!empty($c['es_exterior'])) ? 'checked' : ''; ?>>
                    <label class="custom-control-label" for="nac_es_exterior" style="cursor:pointer">
                        <strong>¿Es del Exterior?</strong> <small class="text-muted">(El documento omitirá Distrito, Municipio y Departamento)</small>
                    </label>
                </div>

                <div id="nac_ubicacion_section" <?php echo (!empty($c['es_exterior'])) ? 'style="display:none"' : ''; ?>>
                    <h6 class="mt-3 text-muted">Ubicación de Nacimiento</h6>
                    <div class="form-row">
                        <div class="col-md-4">
                            <label><small>Departamento</small></label>
                            <select class="form-control" id="nac_departamento_id" name="nac_departamento_id" <?php echo empty($c['es_exterior']) ? 'required' : ''; ?>>
                                <option value="">Seleccione</option>
                                <?php foreach ($departamentos as $d): ?>
                                    <option value="<?php echo (int)$d['id']; ?>" <?php echo ((int)$d['id'] === $nac_dep_id) ? 'selected' : ''; ?>>
                                        <?php echo e($d['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label><small>Municipio</small></label>
                            <select class="form-control" id="nac_municipio_id" name="nac_municipio_id" <?php echo empty($c['es_exterior']) ? 'required' : ''; ?>>
                                <option value="">Seleccione</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label><small>Distrito</small></label>
                            <select class="form-control" id="nac_distrito_nacimiento_id" name="nac_distrito_nacimiento_id" <?php echo empty($c['es_exterior']) ? 'required' : ''; ?>>
                                <option value="">Seleccione</option>
                            </select>
                        </div>
                    </div>
                </div>

                <h6 class="mt-4 text-secondary border-top pt-3">Filiación</h6>
                <div class="form-group">
                    <label><strong>Nombre de la madre</strong></label>
                    <input type="text" class="form-control text-uppercase" name="nac_nombre_madre" value="<?php echo e($c['nombre_madre'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label><strong>Nombre de la madre según DUI (Opcional)</strong></label>
                    <input type="text" class="form-control text-uppercase" name="nac_nombre_madre_dui" value="<?php echo e($c['nombre_madre_dui'] ?? ''); ?>">
                </div>
                <div class="custom-control custom-checkbox mb-2">
                    <input type="checkbox" class="custom-control-input" id="nac_check_padre" <?php echo !empty($c['nombre_padre']) ? 'checked' : ''; ?>>
                    <label class="custom-control-label" for="nac_check_padre" style="cursor:pointer">Incluir nombre del padre</label>
                </div>
                <div id="nac_contenedor_padre" class="form-group" <?php echo empty($c['nombre_padre']) ? 'style="display:none"' : ''; ?>>
                    <label><strong>Nombre del padre</strong></label>
                    <input type="text" class="form-control text-uppercase" name="nac_nombre_padre" value="<?php echo e($c['nombre_padre'] ?? ''); ?>">
                </div>
            </div>

            <?php elseif ($c['tipo_constancia'] === 'NO_REGISTRO_DEF'): ?>
            <!-- ── DEF ─────────────────────────────────────────────────────── -->
            <div class="form-section shadow-sm p-3 mb-4 bg-white rounded border">
                <h5 class="text-primary mb-4 border-bottom pb-2">Datos de Defunción</h5>

                <div class="form-group">
                    <label><strong>No aparece registrada partida de defunción a nombre de:</strong></label>
                    <input type="text" class="form-control text-uppercase" name="def_nombre_no_registro" value="<?php echo e($c['nombre_no_registro']); ?>" required>
                </div>

                <div class="form-group">
                    <label><strong>Según: (Documento de Defunción)</strong></label>
                    <select class="form-control" name="def_tipo_soporte" id="def_tipo_soporte">
                        <option value="">Seleccione el documento de soporte...</option>
                        <?php foreach ($soportes_def as $s): ?>
                            <option value="<?php echo e($s['codigo_slug']); ?>" <?php echo ($s['codigo_slug'] === $c['tipo_soporte']) ? 'selected' : ''; ?>>
                                <?php echo e($s['nombre_oficial']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="def_contenedor_hospital" class="form-group" <?php echo empty($c['nombre_hospital']) ? 'style="display:none"' : ''; ?>>
                    <label><strong>Hospital / Institución</strong></label>
                    <input class="form-control border-danger" list="lista_hospitales" name="def_nombre_hospital" value="<?php echo e($c['nombre_hospital'] ?? ''); ?>">
                </div>

                <div class="form-row">
                    <div class="col-md-6">
                        <label><strong>Fecha de defunción</strong></label>
                        <input type="date" class="form-control" name="def_fecha_defuncion" value="<?php echo e($c['fecha_defuncion'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label><strong>Hora (opcional)</strong></label>
                        <input type="time" class="form-control" name="def_hora_defuncion" value="<?php echo e($c['hora_defuncion'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group mt-3">
                    <label><strong>Nombre según otro documento (Opcional)</strong></label>
                    <input type="text" class="form-control text-uppercase" name="def_nombre_segun_doc" value="<?php echo e($c['def_nombre_segun_doc'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label><strong>Tipo de ese documento</strong></label>
                    <select class="form-control" name="def_tipo_doc_segun_id">
                        <option value="">Seleccione (opcional)</option>
                        <?php foreach ($tipos_documento as $td): ?>
                            <option value="<?php echo (int)$td['id']; ?>" <?php echo ((int)$td['id'] === (int)($c['def_tipo_doc_segun_id'] ?? 0)) ? 'selected' : ''; ?>>
                                <?php echo e($td['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="custom-control custom-checkbox mb-3 mt-3">
                    <input type="checkbox" class="custom-control-input" id="def_es_exterior" name="es_exterior" value="1" <?php echo (!empty($c['es_exterior'])) ? 'checked' : ''; ?>>
                    <label class="custom-control-label" for="def_es_exterior" style="cursor:pointer">
                        <strong>¿Es del Exterior?</strong> <small class="text-muted">(El documento omitirá Distrito, Municipio y Departamento)</small>
                    </label>
                </div>

                <div id="def_ubicacion_section" <?php echo (!empty($c['es_exterior'])) ? 'style="display:none"' : ''; ?>>
                    <h6 class="mt-3 text-muted">Lugar de Fallecimiento</h6>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label><small>Departamento</small></label>
                            <select class="form-control" name="def_departamento_id" id="def_departamento_id" <?php echo empty($c['es_exterior']) ? 'required' : ''; ?>>
                                <option value="">Seleccione Departamento</option>
                                <?php foreach ($departamentos as $d): ?>
                                    <option value="<?php echo (int)$d['id']; ?>" <?php echo ((int)$d['id'] === (int)($c['def_departamento_id'] ?? 0)) ? 'selected' : ''; ?>>
                                        <?php echo e($d['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label><small>Municipio</small></label>
                            <select class="form-control" name="def_municipio_id" id="def_municipio_id" <?php echo empty($c['es_exterior']) ? 'required' : ''; ?>>
                                <option value="">Seleccione Municipio</option>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label><small>Distrito</small></label>
                            <select class="form-control" name="def_distrito_id" id="def_distrito_id" <?php echo empty($c['es_exterior']) ? 'required' : ''; ?>>
                                <option value="">Seleccione Distrito</option>
                            </select>
                        </div>
                    </div>
                </div>

                <h6 class="mt-4 text-secondary border-top pt-3">Filiación</h6>
                <div class="custom-control custom-checkbox mb-2">
                    <input type="checkbox" class="custom-control-input" id="def_check_madre" <?php echo !empty($c['nombre_madre']) ? 'checked' : ''; ?>>
                    <label class="custom-control-label" for="def_check_madre" style="cursor:pointer">Incluir nombre de la madre</label>
                </div>
                <div id="def_contenedor_madre" <?php echo empty($c['nombre_madre']) ? 'style="display:none"' : ''; ?> class="mt-2 p-3 border rounded bg-light">
                    <div class="form-group">
                        <label>Nombre de la madre</label>
                        <input type="text" class="form-control text-uppercase" name="def_nombre_madre" value="<?php echo e($c['nombre_madre'] ?? ''); ?>">
                    </div>
                    <div class="form-group mb-0">
                        <label>Nombre de la madre según DUI (Opcional)</label>
                        <input type="text" class="form-control text-uppercase" name="def_nombre_madre_dui" value="<?php echo e($c['nombre_madre_dui'] ?? ''); ?>">
                    </div>
                </div>
                <div class="custom-control custom-checkbox mt-3 mb-2">
                    <input type="checkbox" class="custom-control-input" id="def_check_padre" <?php echo !empty($c['nombre_padre']) ? 'checked' : ''; ?>>
                    <label class="custom-control-label" for="def_check_padre" style="cursor:pointer">Incluir nombre del padre</label>
                </div>
                <div id="def_contenedor_padre" <?php echo empty($c['nombre_padre']) ? 'style="display:none"' : ''; ?> class="mt-2 p-3 border rounded bg-light">
                    <label>Nombre del padre</label>
                    <input type="text" class="form-control text-uppercase" name="def_nombre_padre" value="<?php echo e($c['nombre_padre'] ?? ''); ?>">
                </div>
            </div>

            <?php elseif ($c['tipo_constancia'] === 'NO_REGISTRO_MAT'): ?>
            <!-- ── MAT ─────────────────────────────────────────────────────── -->
            <div class="form-section shadow-sm p-3 mb-4 bg-white rounded border">
                <h5 class="text-primary mb-4 border-bottom pb-2">No Registro de Matrimonio</h5>

                <div class="custom-control custom-checkbox mb-3">
                    <input type="checkbox" class="custom-control-input" id="mat_es_exterior" name="es_exterior" value="1" <?php echo (!empty($c['es_exterior'])) ? 'checked' : ''; ?>>
                    <label class="custom-control-label" for="mat_es_exterior" style="cursor:pointer">
                        <strong>¿Es del Exterior?</strong>
                    </label>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>No aparece registrada partida de matrimonio a nombre de:</label>
                        <input type="text" class="form-control text-uppercase" name="mat_nombre_no_registro" value="<?php echo e($c['mat_contrayente_1'] ?? $c['nombre_no_registro']); ?>">
                    </div>
                    <div class="form-group col-md-6">
                        <label>Y de (Segundo contrayente - Opcional):</label>
                        <input type="text" class="form-control text-uppercase" name="mat_nombre_contrayente_dos" value="<?php echo e($c['mat_contrayente_2'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <?php elseif (in_array($c['tipo_constancia'], ['SOLTERIA', 'SOLTERIA_DIV'], true)): ?>
            <!-- ── SOLTERÍA ────────────────────────────────────────────────── -->
            <div class="form-section shadow-sm p-3 mb-4 bg-white rounded border">
                <h5 class="text-primary mb-4 border-bottom pb-2">
                    <?php echo $c['tipo_constancia'] === 'SOLTERIA_DIV' ? 'Estado Familiar (Soltería por Divorcio)' : 'No Registro de Matrimonio (Soltería Simple)'; ?>
                </h5>
                <div class="form-group">
                    <label><strong>Nombre del inscrito</strong></label>
                    <input type="text" class="form-control text-uppercase" name="sol_div_nombre_inscrito" value="<?php echo e($c['nombre_no_registro']); ?>">
                </div>
                <div class="form-row">
                    <div class="col-md-3">
                        <label><small>N° Partida</small></label>
                        <input type="text" class="form-control" name="sol_div_numero_partida" value="<?php echo e($c['partida_n'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label><small>Folio</small></label>
                        <input type="text" class="form-control" name="sol_div_folio" value="<?php echo e($c['folio_n'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label><small>Libro</small></label>
                        <input type="text" class="form-control" name="sol_div_libro" value="<?php echo e($c['libro_n'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label><small>Año</small></label>
                        <input type="number" class="form-control" name="sol_div_anio" value="<?php echo e($c['anio_n'] ?? ''); ?>" min="1900" max="<?php echo date('Y'); ?>">
                    </div>
                </div>
            </div>

            <?php elseif ($c['tipo_constancia'] === 'NO_REGISTRO_CED'): ?>
            <!-- ── CÉDULA ──────────────────────────────────────────────────── -->
            <div class="form-section shadow-sm p-3 mb-4 bg-white rounded border">
                <h5 class="text-primary mb-4 border-bottom pb-2">No Registro de Cédula</h5>
                <div class="form-group">
                    <label><strong>No aparece registrada Cédula de Identidad a nombre de:</strong></label>
                    <input type="text" class="form-control text-uppercase" name="ced_nombre_no_registro" value="<?php echo e($c['nombre_no_registro']); ?>">
                </div>
            </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between mt-3">
                <a href="dashboard.php" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary btn-lg shadow font-weight-bold" id="btnGuardar">💾 Guardar y Regenerar PDF</button>
            </div>
        </form>
    </div>
</div>

<datalist id="lista_hospitales"></datalist>

<script src="bootstrap/js/jquery-3.7.1.min.js"></script>
<script src="bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function () {
    const csrf   = <?php echo json_encode($csrf_token); ?>;
    const tipo   = <?php echo json_encode($c['tipo_constancia']); ?>;

    /* Pre-fill data passed from PHP */
    const pre = {
        nac_dep_id:   <?php echo (int)$nac_dep_id; ?>,
        nac_muni_id:  <?php echo (int)$nac_muni_id; ?>,
        nac_dist_id:  <?php echo (int)$nac_dist_id; ?>,
        def_dep_id:   <?php echo (int)($c['def_departamento_id'] ?? 0); ?>,
        def_muni_id:  <?php echo (int)($c['def_municipio_id'] ?? 0); ?>,
        def_dist_id:  <?php echo (int)($c['def_distrito_id'] ?? 0); ?>
    };

    /* ── Load hospitals list ──────────────────────────────── */
    $.post('get_data_constancia.php', {action:'get_hospitales', csrf_token:csrf}, function(data){
        $('#lista_hospitales').html(data);
    });

    /* ── auto-uppercase inputs ────────────────────────────── */
    $(document).on('input', '.text-uppercase', function(){ this.value = this.value.toUpperCase(); });

    /* ── Exterior toggles ────────────────────────────────── */
    $('#nac_es_exterior').on('change', function(){
        var ext = this.checked;
        $('#nac_ubicacion_section').toggle(!ext);
        $('#nac_departamento_id, #nac_municipio_id, #nac_distrito_nacimiento_id').prop('required', !ext);
    });
    $('#def_es_exterior').on('change', function(){
        var ext = this.checked;
        $('#def_ubicacion_section').toggle(!ext);
        $('#def_departamento_id, #def_municipio_id, #def_distrito_id').prop('required', !ext);
    });

    /* ── NAC Padre toggle ────────────────────────────────── */
    $('#nac_check_padre').on('change', function(){ $('#nac_contenedor_padre').toggle(this.checked); });

    /* ── DEF Madre/Padre toggles ────────────────────────── */
    $('#def_check_madre').on('change', function(){ $('#def_contenedor_madre').toggle(this.checked); });
    $('#def_check_padre').on('change', function(){ $('#def_contenedor_padre').toggle(this.checked); });

    /* ── NAC soporte → hospital ──────────────────────────── */
    $('#nac_tipo_soporte').on('change', function(){
        if(['constancia_hosp','ficha_medica','certificado_nac','cert_ficha','cert_cert'].includes($(this).val())){
            $('#nac_contenedor_hospital').fadeIn();
        } else {
            $('#nac_contenedor_hospital').fadeOut().find('input').val('');
        }
    });

    /* ── DEF soporte → hospital ──────────────────────────── */
    $('#def_tipo_soporte').on('change', function(){
        if(['certificado_hosp','constancia_cert'].includes($(this).val())){
            $('#def_contenedor_hospital').fadeIn();
        } else {
            $('#def_contenedor_hospital').fadeOut().find('input').val('');
        }
    });

    /* ── Generic cascade helpers ─────────────────────────── */
    function loadMunicipios(depId, $muniSel, $distSel, onDone) {
        if (!depId) return;
        $.post('get_data_constancia.php', {action:'get_municipios', depto_id:depId, csrf_token:csrf}, function(r){
            if (!r.success) return;
            var h = '<option value="">Seleccione Municipio</option>';
            r.municipios.forEach(function(m){ h += '<option value="'+m.id+'">'+m.nombre+'</option>'; });
            $muniSel.html(h).prop('disabled', false);
            $distSel.html('<option value="">Seleccione Distrito</option>').prop('disabled', true);
            if (onDone) onDone();
        }, 'json');
    }

    function loadDistritos(muniId, $distSel, onDone) {
        if (!muniId) return;
        $.post('get_data_constancia.php', {action:'get_distritos', municipio_id:muniId, csrf_token:csrf}, function(r){
            if (!r.success) return;
            var h = '<option value="">Seleccione Distrito</option>';
            r.distritos.forEach(function(d){ h += '<option value="'+d.id+'">'+d.nombre+'</option>'; });
            $distSel.html(h).prop('disabled', false);
            if (onDone) onDone();
        }, 'json');
    }

    /* ── NAC cascade ─────────────────────────────────────── */
    if (tipo === 'NO_REGISTRO_NAC' && pre.nac_dep_id) {
        loadMunicipios(pre.nac_dep_id, $('#nac_municipio_id'), $('#nac_distrito_nacimiento_id'), function(){
            $('#nac_municipio_id').val(pre.nac_muni_id);
            $('#nac_municipio_nombre').val($("#nac_municipio_id option:selected").text());
            loadDistritos(pre.nac_muni_id, $('#nac_distrito_nacimiento_id'), function(){
                $('#nac_distrito_nacimiento_id').val(pre.nac_dist_id);
                $('#nac_distrito_nombre').val($("#nac_distrito_nacimiento_id option:selected").text());
            });
        });
    }

    $('#nac_departamento_id').on('change', function(){
        var depId = $(this).val();
        $('#nac_departamento_nombre').val($("#nac_departamento_id option:selected").text());
        loadMunicipios(depId, $('#nac_municipio_id'), $('#nac_distrito_nacimiento_id'), null);
    });
    $('#nac_municipio_id').on('change', function(){
        var muniId = $(this).val();
        $('#nac_municipio_nombre').val($("#nac_municipio_id option:selected").text());
        loadDistritos(muniId, $('#nac_distrito_nacimiento_id'), null);
    });
    $('#nac_distrito_nacimiento_id').on('change', function(){
        $('#nac_distrito_nombre').val($("#nac_distrito_nacimiento_id option:selected").text());
    });

    /* ── DEF cascade ─────────────────────────────────────── */
    if (tipo === 'NO_REGISTRO_DEF' && pre.def_dep_id) {
        loadMunicipios(pre.def_dep_id, $('#def_municipio_id'), $('#def_distrito_id'), function(){
            $('#def_municipio_id').val(pre.def_muni_id);
            $('#def_municipio_nombre').val($("#def_municipio_id option:selected").text());
            loadDistritos(pre.def_muni_id, $('#def_distrito_id'), function(){
                $('#def_distrito_id').val(pre.def_dist_id);
                $('#def_distrito_nombre').val($("#def_distrito_id option:selected").text());
            });
        });
    }

    $('#def_departamento_id').on('change', function(){
        var depId = $(this).val();
        $('#def_departamento_nombre').val($("#def_departamento_id option:selected").text());
        loadMunicipios(depId, $('#def_municipio_id'), $('#def_distrito_id'), null);
    });
    $('#def_municipio_id').on('change', function(){
        var muniId = $(this).val();
        $('#def_municipio_nombre').val($("#def_municipio_id option:selected").text());
        loadDistritos(muniId, $('#def_distrito_id'), null);
    });
    $('#def_distrito_id').on('change', function(){
        $('#def_distrito_nombre').val($("#def_distrito_id option:selected").text());
    });

    /* ── Form submit ─────────────────────────────────────── */
    $('#formEditarConstancia').on('submit', function(e){
        e.preventDefault();
        var btn = $('#btnGuardar');
        var disabledFields = $(this).find(':disabled').prop('disabled', false);
        var formData = $(this).serialize();
        disabledFields.prop('disabled', true);

        btn.prop('disabled', true).text('Guardando...');

        $.ajax({
            url: 'actualizar_constancia.php',
            method: 'POST',
            dataType: 'json',
            data: formData,
            success: function(r){
                if (r.success) {
                    btn.text('Generando PDF...');
                    $.post('generar_constancia_pdf.php', formData + '&numero_oficio_generado=' + encodeURIComponent(r.oficio), function(){
                        alert('✅ Certificación actualizada correctamente.');
                        window.location.href = 'dashboard.php';
                    }).fail(function(){
                        alert('✅ Datos guardados, pero hubo un problema regenerando el PDF.');
                        window.location.href = 'dashboard.php';
                    });
                } else {
                    alert('Error: ' + (r.msg || 'Error desconocido'));
                    btn.prop('disabled', false).text('💾 Guardar y Regenerar PDF');
                }
            },
            error: function(){
                alert('Error de conexión al servidor.');
                btn.prop('disabled', false).text('💾 Guardar y Regenerar PDF');
            }
        });
    });
});
</script>
</body>
</html>
