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
    } catch (Exception $e) {
        $operadores_area = [];
    }
}

/* ===== ESTADÍSTICAS ===== */
$stats_envios = [];
$count_errores_envio = 0;
try {
    $stmt_err_env = $pdo->query("SELECT COUNT(*) FROM cola_envios WHERE UPPER(estado) = 'ERROR'");
    $count_errores_envio = (int)$stmt_err_env->fetchColumn();
    $stats_envios = $pdo->query("SELECT UPPER(estado) as estado_env, COUNT(*) total FROM cola_envios GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {}

try {
    $stmt_inst = $pdo->query("SELECT id, nombre_institucion, unidad_dependencia, email_contacto, ubicacion_sede FROM instituciones WHERE estado = 1 ORDER BY nombre_institucion ASC");
    $instituciones = $stmt_inst->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $instituciones = [];
}

function e($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Nuevo Oficio Institucional - REVFA</title>
    <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css" rel="stylesheet" />
    <style>
        body { background:#f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .container { margin-top:30px; max-width: 1100px; }
        .card { border: none; border-radius: 12px; }
        .persona-row { background: #fff; padding: 20px; border-radius: 10px; border-left: 5px solid #007bff; position: relative; transition: all 0.3s ease; margin-bottom: 15px; }
        .panel-data { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 10px; border: 1px solid #dee2e6; }
        .btn-remove { position: absolute; top: 10px; right: 15px; font-size: 1.5rem; color: #dc3545; cursor: pointer; }
        .section-title { font-size: 0.9rem; font-weight: bold; color: #495057; text-transform: uppercase; margin-bottom: 10px; border-bottom: 1px solid #dee2e6; margin-top: 15px; }
        .oficio-entrada-row { background: #e9ecef; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #6c757d; }
        .oficio-header { font-weight: bold; color: #495057; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
        .peticion-item { background: #fff; padding: 12px; border-radius: 6px; margin-bottom: 10px; border: 1px solid #dee2e6; }
        .peticion-header { font-size: 0.85rem; color: #6c757d; margin-bottom: 8px; }
        .file-drop-area { border: 2px dashed #ccc; padding: 20px; border-radius: 8px; text-align: center; background: #fff; cursor: pointer; }
        #panel-nueva-institucion { background: #fff3cd; border: 1px solid #ffeeba; padding: 15px; border-radius: 8px; margin-top: 10px; border-left: 5px solid #ffc107; }
        .select2-container--bootstrap4 .select2-selection--single { height: calc(2.25rem + 2px) !important; }
        .btn-add-peticion { font-size: 0.8rem; padding: 0.25rem 0.5rem; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow mb-4">
    <a class="navbar-brand font-weight-bold" href="dashboard.php">Sistema de Trámites</a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav mr-auto">
            <li class="nav-item"><a class="nav-link" href="crear_oficio.php">Crear Oficio</a></li>
            <li class="nav-item"><a class="nav-link" href="crear_constancia.php">Crear Certificación</a></li>
            <li class="nav-item active"><a class="nav-link" href="crear_oficio_institucional.php">Crear Oficio Inst.</a></li>
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
            <?php if (in_array($rol, ['administrador', 'supervisor'])): ?>
            <li class="nav-item"><a class="nav-link" href="panel_envios.php">Panel Envíos <span class="badge badge-danger"><?php echo $stats_envios['ERROR'] ?? 0; ?></span></a></li>
            <?php endif; ?>
            <?php if (in_array($rol, ['normal', 'supervisor'])): ?>
            <li class="nav-item"><a class="nav-link" href="#" data-toggle="modal" data-target="#modalPassword">Mi Cuenta</a></li>
            <?php endif; ?>
        </ul>
        <span class="navbar-text mr-3 text-white">Bienvenido, <strong><?php echo e($nombre_usuario); ?></strong></span>
        <a href="logout.php" class="btn btn-outline-light btn-sm">Cerrar Sesión</a>
    </div>
</nav>

<div class="container mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3>📄 Nuevo Oficio Institucional</h3>
        <span class="badge badge-info p-2">Módulo Técnico</span>
    </div>

    <div class="alert alert-info mb-4">
        <strong>💡 Tip:</strong> Si un oficio de entrada solicita múltiples partidas, agrega una petición por cada persona/partida.
    </div>

    <form id="formOficioInst" method="POST" action="procesar_oficio_institucional.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white font-weight-bold">I. INFORMACIÓN DE LA SOLICITUD RECIBIDA</div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-md-12">
                        <label class="font-weight-bold">Institución Solicitante:</label>
                        <select name="id_institucion" id="id_institucion" class="form-control" required style="width: 100%;">
                            <option value="">-- Seleccione o escriba la institución --</option>
                            <?php foreach($instituciones as $ins):
                                $label = e($ins['nombre_institucion']);
                                if(!empty($ins['unidad_dependencia'])) $label .= ' - ' . e($ins['unidad_dependencia']);
                                if(!empty($ins['ubicacion_sede'])) $label .= ' - ' . e($ins['ubicacion_sede']);
                            ?>
                                <option value="<?php echo (int)$ins['id']; ?>" data-email="<?php echo e($ins['email_contacto'] ?? ''); ?>">
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="email_institucional_existente" id="email_institucional_existente">
                    </div>
                </div>

                <div class="form-row mt-2">
                    <div class="form-group col-md-6">
                        <label class="small font-weight-bold">Email de Envío:</label>
                        <input type="email" name="email_envio" id="email_envio" class="form-control" placeholder="Opcional — se auto-completa con el de la institución">
                    </div>
                    <div class="form-group col-md-3">
                        <label class="small font-weight-bold text-danger">Fecha del Documento <span class="text-danger">*</span>:</label>
                        <input type="date" name="fecha_documento" id="fecha_documento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <div id="panel-nueva-institucion" style="display:none;" class="mb-3">
                    <p class="font-weight-bold text-dark">⚠️ Institución no registrada. Complete los datos (opcionales) para activarla:</p>
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label class="small font-weight-bold">Unidad o Dependencia:</label>
                            <input type="text" id="nueva_unidad" class="form-control form-control-sm" placeholder="Opcional">
                        </div>
                        <div class="form-group col-md-3">
                            <label class="small font-weight-bold">Ubicación / Sede:</label>
                            <input type="text" id="nueva_ubicacion" class="form-control form-control-sm" placeholder="Opcional">
                        </div>
                        <div class="form-group col-md-3">
                            <label class="small font-weight-bold text-danger">Email de Contacto:</label>
                            <input type="email" id="nuevo_email" class="form-control form-control-sm" placeholder="Opcional">
                        </div>
                        <div class="form-group col-md-3 d-flex align-items-end">
                            <button type="button" id="btn_save_new_inst" class="btn btn-warning btn-sm btn-block font-weight-bold shadow-sm">
                                💾 GUARDAR E INSERTAR
                            </button>
                        </div>
                    </div>
                </div>

                <div class="section-title">Oficios de Entrada y Peticiones</div>
                <div id="contenedor_oficios_entrada">
                    <div class="oficio-entrada-row mb-3 shadow-sm" data-oficio-index="0">
                        <div class="oficio-header">
                            <span>📬 Oficio de Entrada #1</span>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-oficio">Eliminar Oficio</button>
                        </div>

                        <div class="form-row mb-3">
                            <div class="form-group col-md-3">
                                <label class="small font-weight-bold">N° Oficio Entrada:</label>
                                <input type="text" name="num_oficio_in[]" class="form-control form-control-sm oficio-num" required>
                            </div>
                            <div class="form-group col-md-3">
                                <label class="small font-weight-bold">Referencia:</label>
                                <input type="text" name="ref_expediente_in[]" class="form-control form-control-sm oficio-ref">
                            </div>
                            <div class="form-group col-md-3">
                                <label class="small font-weight-bold">Fecha Documento:</label>
                                <input type="date" name="fecha_doc_in[]" class="form-control form-control-sm oficio-fecha" required>
                            </div>
                            <div class="form-group col-md-3 d-flex align-items-end">
                                <button type="button" class="btn btn-sm btn-outline-primary btn-add-peticion" data-oficio-index="0">+ Agregar Petición</button>
                            </div>
                        </div>

                        <div class="peticiones-container" data-oficio-index="0">
                            <div class="peticion-item" data-peticion-id="1">
                                <div class="peticion-header">Petición #1</div>
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
                </div>

                <button type="button" id="btn_add_oficio" class="btn btn-outline-secondary btn-sm">+ Agregar Otro Oficio de Entrada</button>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span class="font-weight-bold">II. RESULTADOS DE BÚSQUEDA (SEGÚN NUESTROS REGISTROS)</span>
                <button type="button" id="btn_add_persona" class="btn btn-success btn-sm font-weight-bold">+ Agregar Registro</button>
            </div>
            <div class="card-body bg-light">
                <div id="contenedor_personas"></div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-secondary text-white font-weight-bold">III. DOCUMENTACIÓN ADJUNTA (PDF)</div>
            <div class="card-body text-center">
                <div class="file-drop-area">
                    <span class="choose-file-button btn btn-outline-primary btn-sm">Seleccionar archivos PDF</span>
                    <input class="file-input" type="file" name="archivos_adjuntos[]" multiple accept="application/pdf" style="display:none;">
                    <div class="file-message mt-2 small text-secondary">Los archivos se unirán al oficio final</div>
                </div>
                <div id="lista_archivos" class="mt-2 text-left"></div>
            </div>
        </div>

        <div class="text-right pb-5">
            <a href="dashboard.php" class="btn btn-secondary mr-2">Cancelar</a>
            <button type="submit" id="btnSubmit" class="btn btn-primary btn-lg shadow">Guardar y Generar PDF</button>
        </div>
    </form>
</div>

<div class="modal fade" id="modalPassword" tabindex="-1" role="dialog">
<div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5>Cambiar mi contraseña</h5><button class="close" data-dismiss="modal">&times;</button></div>
<form id="formCambiarPass">
<div class="modal-body">
<input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
<label class="small font-weight-bold">Contraseña Actual:</label>
<div class="input-group mb-2">
<input type="password" name="pass_actual" class="form-control" required>
<div class="input-group-append">
<button class="btn btn-outline-secondary btn-toggle-pass" type="button"><i class="eye-icon">👁️</i></button>
</div>
</div>
<label class="small font-weight-bold">Nueva Contraseña:</label>
<div class="input-group mb-2">
<input type="password" name="pass_nueva" class="form-control" required>
<div class="input-group-append">
<button class="btn btn-outline-secondary btn-toggle-pass" type="button"><i class="eye-icon">👁️</i></button>
</div>
</div>
<label class="small font-weight-bold">Confirmar Nueva Contraseña:</label>
<div class="input-group mb-2">
<input type="password" name="pass_confirmar" class="form-control" required>
<div class="input-group-append">
<button class="btn btn-outline-secondary btn-toggle-pass" type="button"><i class="eye-icon">👁️</i></button>
</div>
</div>
<div id="msgPassword" class="mt-2"></div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-primary">Actualizar Contraseña</button>
</div>
</form>
</div></div>
</div>

<template id="template_persona">
    <div class="persona-row mb-4 shadow-sm">
        <span class="btn-remove">&times;</span>
        <div class="form-row">
            <div class="form-group col-md-4">
                <label class="small font-weight-bold">Nombre en NUESTROS REGISTROS:</label>
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

<template id="template_peticion">
    <div class="peticion-item">
        <div class="peticion-header">Petición #<span class="peticion-num">2</span></div>
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
</template>

<template id="template_oficio">
    <div class="oficio-entrada-row mb-3 shadow-sm" data-oficio-index="1">
        <div class="oficio-header">
            <span>📬 Oficio de Entrada #<span class="oficio-num-display">2</span></span>
            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-oficio">Eliminar Oficio</button>
        </div>
        <div class="form-row mb-3">
            <div class="form-group col-md-3">
                <label class="small font-weight-bold">N° Oficio Entrada:</label>
                <input type="text" name="num_oficio_in[]" class="form-control form-control-sm oficio-num" required>
            </div>
            <div class="form-group col-md-3">
                <label class="small font-weight-bold">Referencia:</label>
                <input type="text" name="ref_expediente_in[]" class="form-control form-control-sm oficio-ref">
            </div>
            <div class="form-group col-md-3">
                <label class="small font-weight-bold">Fecha Documento:</label>
                <input type="date" name="fecha_doc_in[]" class="form-control form-control-sm oficio-fecha" required>
            </div>
            <div class="form-group col-md-3 d-flex align-items-end">
                <button type="button" class="btn btn-sm btn-outline-primary btn-add-peticion" data-oficio-index="1">+ Agregar Petición</button>
            </div>
        </div>
        <div class="peticiones-container" data-oficio-index="1">
            <div class="peticion-item">
                <div class="peticion-header">Petición #1</div>
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
    $(document).on('click', '.btn-toggle-pass', function() {
        const btn = $(this);
        const input = btn.closest('.input-group').find('input');
        const icon = btn.find('.eye-icon');
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.text('🔒');
        } else {
            input.attr('type', 'password');
            icon.text('👁️');
        }
    });

    $('#formCambiarPass').submit(function(e){
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).text('Procesando...');
        $.post('cambiar_password.php', $(this).serialize(), function(r){
            $('#msgPassword').html(r.success ? '<div class="alert alert-success">'+r.msg+'</div>' : '<div class="alert alert-danger">'+r.msg+'</div>');
            if(r.success) {
                setTimeout(()=>location.reload(), 1500);
            } else {
                btn.prop('disabled', false).text('Actualizar Contraseña');
            }
        },'json');
    });

    const $selectInst = $('#id_institucion').select2({
        theme: 'bootstrap4',
        tags: true,
        placeholder: "Escriba para buscar o agregar...",
        allowClear: true
    });

    $selectInst.on('select2:select', function(e) {
        const data = e.params.data;
        if (data.element === undefined) {
            $('#panel-nueva-institucion').slideDown();
            $('#nuevo_email, #nueva_unidad, #nueva_ubicacion').val('');
            $('#email_institucional_existente').val('');
        } else {
            $('#panel-nueva-institucion').slideUp();
            const email = $(data.element).data('email');
            $('#email_institucional_existente').val(email);
            if (email && !$('#email_envio').val()) {
                $('#email_envio').val(email);
            }
        }
    });

    $('#btn_save_new_inst').click(function() {
        const nombre = $('#id_institucion').val().toUpperCase();
        const unidad = $('#nueva_unidad').val();
        const ubicacion = $('#nueva_ubicacion').val();
        const email = $('#nuevo_email').val();

        if(!nombre) {
            alert("El nombre de la institución es obligatorio.");
            return;
        }

        const btn = $(this);
        btn.prop('disabled', true).text('Guardando...');

        $.post('catalogos/ajax_guardar_institucion.php', {
            nombre: nombre,
            unidad: unidad,
            direccion: ubicacion,
            email: email,
            csrf_token: '<?php echo $csrf_token; ?>'
        }, function(r) {
            if(r.success) {
                alert("Institución guardada correctamente.");
                const fullLabel = nombre + (unidad ? " - " + unidad : "") + (ubicacion ? " - " + ubicacion : "");
                const newOption = new Option(fullLabel, r.id, true, true);
                $(newOption).data('email', email);
                $('#id_institucion').append(newOption).trigger('change');
                $('#email_institucional_existente').val(email);
                $('#panel-nueva-institucion').hide();
            } else {
                alert("Error: " + r.msg);
            }
            btn.prop('disabled', false).text('💾 GUARDAR E INSERTAR');
        }, 'json');
    });

    let oficioCounter = 1;
    let peticionIdCounter = 1;

    /* Crea una fila en Sección II; si se pasa peticionId la enlaza a la petición de Sección I */
    function addPersonaRow(peticionId, tipo, nombre) {
        const $wrapper = $('<div>').append(document.getElementById('template_persona').content.cloneNode(true));
        const $row = $wrapper.find('.persona-row');
        if (peticionId) $row.attr('data-peticion-id', peticionId);
        if (tipo) {
            $row.find('.sel-tipo-tramite').val(tipo);
            const isFamily = (tipo === 'MATRIMONIO' || tipo === 'DIVORCIO');
            $row.find('.lbl-filiacion-1').text(isFamily ? 'Nombre del Cónyuge 1:' : 'Nombre de la Madre:');
            $row.find('.lbl-filiacion-2').text(isFamily ? 'Nombre del Cónyuge 2:' : 'Nombre del Padre:');
        }
        if (nombre) $row.find('input[name="nombre_consultado[]"]').val(nombre.toUpperCase());
        $('#contenedor_personas').append($row);
    }

    /* Fila inicial de Sección II enlazada a la petición #1 */
    addPersonaRow(1, 'NACIMIENTO', '');
    $('#btn_add_persona').click(function() { addPersonaRow(null, null, null); });

    $('#btn_add_oficio').click(function() {
        oficioCounter++;
        peticionIdCounter++;
        const currentId = peticionIdCounter;
        const template = $('#template_oficio').html();
        const $nuevoOficio = $(template);
        $nuevoOficio.find('[data-oficio-index]').attr('data-oficio-index', oficioCounter);
        $nuevoOficio.find('.btn-add-peticion').attr('data-oficio-index', oficioCounter);
        $nuevoOficio.find('.oficio-num-display').text(oficioCounter);
        $nuevoOficio.find('.peticion-item').attr('data-peticion-id', currentId);
        $('#contenedor_oficios_entrada').append($nuevoOficio);
        addPersonaRow(currentId, 'NACIMIENTO', '');
    });

    $(document).on('click', '.btn-remove-oficio', function() {
        if(confirm('¿Eliminar este oficio de entrada y todas sus peticiones?')) {
            const $oficio = $(this).closest('.oficio-entrada-row');
            $oficio.find('.peticion-item[data-peticion-id]').each(function() {
                const pid = $(this).attr('data-peticion-id');
                $('#contenedor_personas .persona-row[data-peticion-id="' + pid + '"]').remove();
            });
            $oficio.remove();
        }
    });

    $(document).on('click', '.btn-add-peticion', function() {
        peticionIdCounter++;
        const currentId = peticionIdCounter;
        const oficioBloque = $(this).closest('.oficio-entrada-row');
        const container = oficioBloque.find('.peticiones-container');
        const peticionCount = container.find('.peticion-item').length + 1;
        const template = $('#template_peticion').html();
        const $nuevaPeticion = $(template);
        $nuevaPeticion.find('.peticion-num').text(peticionCount);
        $nuevaPeticion.attr('data-peticion-id', currentId);
        container.append($nuevaPeticion);
        addPersonaRow(currentId, 'NACIMIENTO', '');
    });

    $(document).on('click', '.btn-remove-peticion', function() {
        const container = $(this).closest('.peticiones-container');
        if(container.find('.peticion-item').length > 1) {
            const $peticion = $(this).closest('.peticion-item');
            const peticionId = $peticion.attr('data-peticion-id');
            $peticion.remove();
            if (peticionId) {
                $('#contenedor_personas .persona-row[data-peticion-id="' + peticionId + '"]').remove();
            }
            container.find('.peticion-item').each(function(index) {
                $(this).find('.peticion-num').text(index + 1);
            });
        } else {
            alert('Cada oficio debe tener al menos una petición.');
        }
    });

    /* Sincronizar tipo de partida → tipo de trámite en Sección II */
    $(document).on('change', 'select[name="tipo_partida_solicitada[]"]', function() {
        const peticionId = $(this).closest('.peticion-item').attr('data-peticion-id');
        if (!peticionId) return;
        const tipo = $(this).val();
        const $row = $('#contenedor_personas .persona-row[data-peticion-id="' + peticionId + '"]');
        if ($row.length) $row.find('.sel-tipo-tramite').val(tipo).trigger('change');
    });

    /* Sincronizar nombre del oficio → nombre en Sección II */
    $(document).on('input', 'input[name="nombre_segun_oficio[]"]', function() {
        const peticionId = $(this).closest('.peticion-item').attr('data-peticion-id');
        if (!peticionId) return;
        const nombre = $(this).val();
        const $row = $('#contenedor_personas .persona-row[data-peticion-id="' + peticionId + '"]');
        if ($row.length) $row.find('input[name="nombre_consultado[]"]').val(nombre.toUpperCase());
    });

    $(document).on('click', '.btn-remove', function() {
        if($('.persona-row').length > 1) $(this).closest('.persona-row').remove();
    });

    $(document).on('change', '.sel-tipo-tramite', function() {
        const row = $(this).closest('.persona-row');
        const isFamily = ($(this).val() === 'MATRIMONIO' || $(this).val() === 'DIVORCIO');
        row.find('.lbl-filiacion-1').text(isFamily ? 'Nombre del Cónyuge 1:' : 'Nombre de la Madre:');
        row.find('.lbl-filiacion-2').text(isFamily ? 'Nombre del Cónyuge 2:' : 'Nombre del Padre:');
    });

    $(document).on('change', '.sel-resultado', function() {
        const row = $(this).closest('.persona-row');
        if($(this).val() === 'ENCONTRADO') {
            row.find('.panel-partida').removeClass('d-none');
            row.css('border-left', '5px solid #28a745');
        } else {
            row.find('.panel-partida').addClass('d-none');
            row.css('border-left', '5px solid #ffc107');
        }
    });

    $('.choose-file-button').click(function() { $('.file-input').click(); });
    $('.file-input').change(function() {
        if (this.files.length > 0) {
            $('#lista_archivos').html('<ul class="list-group">' + Array.from(this.files).map(f => `<li class="list-group-item py-1 small">📎 ${f.name}</li>`).join('') + '</ul>');
        }
    });

    $('#formOficioInst').on('submit', function() {
        $('#btnSubmit').prop('disabled', true).html('Procesando...');
    });
});
</script>
</body>
</html>