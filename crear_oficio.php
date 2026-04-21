<?php
declare(strict_types=1);
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== VALIDAR OPERADOR =====
$creado_por = (int)($_SESSION['user_id'] ?? 0);
if ($creado_por <= 0) {
    die('No se pudo identificar al operador. Inicie sesión nuevamente.');
}

// ===== DATOS PARA EL MENÚ (NAVBAR) =====
$rol = $_SESSION['user_rol'] ?? 'normal';
$nombre_usuario = $_SESSION['nombre_usuario'] ?? 'Usuario';

try {
    $stats_envios = $pdo->query("SELECT estado, COUNT(*) total FROM cola_envios GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $stats_envios = [];
}

date_default_timezone_set('America/El_Salvador');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

// Generar referencia correlativa automática
$anio_actual = date('Y');
$stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(referencia, '-', -1) AS UNSIGNED)) FROM oficios WHERE YEAR(fecha) = ?");
$stmt->execute([$anio_actual]);
$ultimo = (int)$stmt->fetchColumn();
$nuevo_numero = $ultimo + 1;
$referencia_correlativa = "REFSSC-" . $anio_actual . "-" . str_pad((string)$nuevo_numero, 4, '0', STR_PAD_LEFT);

$fecha_actual = date('d/m/Y');

$stmt_depto = $pdo->query("SELECT id, nombre FROM departamentos ORDER BY nombre");
$departamentos = $stmt_depto->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Crear Oficio - Registro del Estado Familiar</title>
    <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .container { max-width: 850px; margin-top: 40px; margin-bottom: 40px; padding: 35px; background-color: #fff; border-radius: 12px; box-shadow: 0 8px 15px rgba(0,0,0,0.1); }
        .section-title { border-bottom: 2px solid #007bff; margin-bottom: 25px; padding-bottom: 10px; color: #007bff; font-weight: bold; }
        .form-control[readonly] { background-color: #e9ecef; opacity: 1; }
        .btn-success { padding: 12px; font-weight: bold; font-size: 1.1rem; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm mb-4">
    <a class="navbar-brand font-weight-bold" href="dashboard.php">Registro de Trámites</a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
    </button>
    
    <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav mr-auto">
            <li class="nav-item active"><a class="nav-link" href="crear_oficio.php">Crear Oficio</a></li>
            <li class="nav-item"><a class="nav-link" href="crear_constancia.php">Crear Certificación</a></li>
            <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
            <?php if (in_array($rol, ['administrador','supervisor'])): ?>
                <li class="nav-item">
                    <a class="nav-link" href="panel_envios.php">
                        Panel Envíos 
                        <?php if (($stats_envios['ERROR'] ?? 0) > 0): ?>
                            <span class="badge badge-danger"><?php echo (int)$stats_envios['ERROR']; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
        <span class="navbar-text mr-3 text-white">Bienvenido, <strong><?php echo htmlspecialchars($nombre_usuario); ?></strong></span>
        <a href="logout.php" class="btn btn-outline-light btn-sm">Cerrar Sesión</a>
    </div>
</nav>

<div class="container">
    <h2 class="text-center mb-4 section-title">Crear Nuevo Oficio</h2>

    <form id="oficioForm" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="creado_por" value="<?php echo $creado_por; ?>">
        
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Referencia:</label>
                <input type="text" class="form-control" name="referencia" value="<?php echo $referencia_correlativa; ?>" readonly>
            </div>
            <div class="form-group col-md-6">
                <label>Fecha de Emisión:</label>
                <input type="text" class="form-control" value="<?php echo $fecha_actual; ?>" readonly>
            </div>
        </div>

        <h4 class="mt-4 text-secondary">1. Datos del Destinatario</h4>
        <div class="form-group">
            <label>Departamento Destino:</label>
            <select class="form-control" id="departamento_destino" name="departamento_destino" required>
                <option value="">Seleccione un departamento...</option>
                <?php foreach ($departamentos as $d): ?>
                    <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['nombre']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Municipio Destino:</label>
            <select class="form-control" id="municipio_destino" name="municipio_destino" disabled required>
                <option value="">Seleccione departamento primero...</option>
            </select>
        </div>
        <div class="form-group">
            <label>Distrito Destino:</label>
            <select class="form-control" id="distrito_destino" name="distrito_destino" disabled required>
                <option value="">Seleccione municipio primero...</option>
            </select>
        </div>
        
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Nombre del Registrador (Licenciado/a):</label>
                <input type="text" class="form-control" id="nombre_licenciado" name="nombre_licenciado" readonly>
            </div>
            <div class="form-group col-md-6">
                <label>Cargo del Destinatario:</label>
                <input type="text" class="form-control" id="cargo_licenciado" name="cargo_licenciado" readonly>
            </div>
        </div>

        <h4 class="mt-4 text-secondary">2. Datos del Difunto e Inscripción</h4>
        <div class="form-group">
            <label>Nombre Completo del Difunto:</label>
            <input type="text" class="form-control" name="nombre_difunto" required style="text-transform: uppercase;">
        </div>
        <div class="form-row">
            <div class="form-group col-md-4">
                <label>Número de Partida:</label>
                <input type="text" class="form-control" name="numero_partida" required>
            </div>
            <div class="form-group col-md-4">
                <label>Folio:</label>
                <input type="text" class="form-control" name="folio" required>
            </div>
            <div class="form-group col-md-4">
                <label>Libro:</label>
                <input type="text" class="form-control" name="libro" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Distrito de Inscripción:</label>
                <select class="form-control" id="distrito_inscripcion" name="distrito_inscripcion" required>
                    <option value="">Seleccione un distrito...</option>
                </select>
            </div>
            <div class="form-group col-md-6">
                <label>Año de Inscripción:</label>
                <input type="number" class="form-control" name="anio_inscripcion" value="<?php echo date('Y'); ?>" required>
            </div>
        </div>

        <h4 class="mt-4 text-secondary">3. Lugar de Origen del Difunto</h4>
        <div class="form-group">
            <label>Departamento de Origen:</label>
            <select class="form-control" id="departamento_origen" name="departamento_origen" required>
                <option value="">Seleccione un departamento...</option>
                <?php foreach ($departamentos as $d): ?>
                    <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['nombre']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Municipio de Origen:</label>
                <select class="form-control" id="municipio_origen" name="municipio_origen" disabled required>
                    <option value="">Seleccione departamento primero...</option>
                </select>
            </div>
            <div class="form-group col-md-6">
                <label>Distrito de Origen:</label>
                <select class="form-control" id="distrito_origen" name="distrito_origen" disabled required>
                    <option value="">Seleccione municipio primero...</option>
                </select>
            </div>
        </div>

        <h4 class="mt-4 text-secondary">4. Documentación Adicional</h4>
        <div class="form-group bg-light p-3 border rounded">
            <label>Anexar archivo PDF (Opcional):</label>
            <input type="file" class="form-control-file" name="archivo_anexo" accept="application/pdf">
        </div>

        <button type="submit" class="btn btn-success btn-block shadow-sm">Generar Oficio y Guardar</button>
    </form>
</div>

<script src="bootstrap/js/jquery-3.7.1.min.js"></script>
<script src="bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function () {
    // 1. Cargar Distritos de Inscripción (Sin duplicados)
    $.getJSON('get_distritos_inscripcion.php', function(data) {
        let select = $('#distrito_inscripcion');
        select.html('<option value="">Seleccione un distrito...</option>');
        data.forEach(item => select.append($('<option>', { value: item, text: item })));
    });

    // 2. Función maestra de carga (Limpia antes de cargar)
    function loadMunicipios(depId, targetMuni, targetDist) {
        // Limpiamos los selectores y los desactivamos
        targetMuni.html('<option value="">Cargando municipios...</option>').prop('disabled', true);
        targetDist.html('<option value="">Seleccione municipio primero...</option>').prop('disabled', true);
        
        if (!depId) {
            targetMuni.html('<option value="">Seleccione departamento primero...</option>');
            return;
        }

        $.post('get_data.php', { action: 'get_municipios', departamento_id: depId }, function (data) {
            // Reemplazamos el HTML por completo para evitar duplicados
            targetMuni.html(data).prop('disabled', false);
        });
    }

    function loadDistritos(muniId, targetDist) {
        targetDist.html('<option value="">Cargando distritos...</option>').prop('disabled', true);
        
        if (!muniId) {
            targetDist.html('<option value="">Seleccione municipio primero...</option>');
            return;
        }

        $.post('get_data.php', { action: 'get_distritos', municipio_id: muniId }, function (data) {
            targetDist.html(data).prop('disabled', false);
        });
    }

    // 3. Eventos Destinatario
    $('#departamento_destino').change(function() { 
        loadMunicipios($(this).val(), $('#municipio_destino'), $('#distrito_destino')); 
        $('#nombre_licenciado, #cargo_licenciado').val(''); // Limpiamos campos automáticos
    });

    $('#municipio_destino').change(function() {
        const muniId = $(this).val();
        loadDistritos(muniId, $('#distrito_destino'));
        if (muniId) {
            $.post('get_data.php', { action: 'get_oficiante', municipio_id: muniId }, function(res) {
                if (res.success && res.oficiante) {
                    $('#nombre_licenciado').val(res.oficiante.nombre);
                    $('#cargo_licenciado').val(res.oficiante.cargo);
                } else {
                    $('#nombre_licenciado, #cargo_licenciado').val('NO ASIGNADO');
                }
            }, 'json');
        }
    });

    // 4. Eventos Origen
    $('#departamento_origen').change(function() { 
        loadMunicipios($(this).val(), $('#municipio_origen'), $('#distrito_origen')); 
    });

    $('#municipio_origen').change(function() { 
        loadDistritos($(this).val(), $('#distrito_origen')); 
    });

    // 5. Envío AJAX
    $('#oficioForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('button');
        btn.prop('disabled', true).text('Guardando...');

        $.ajax({
            url: 'generar_pdf.php',
            type: 'POST',
            data: new FormData(this),
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    alert('Oficio generado con éxito.');
                    window.location.href = 'dashboard.php';
                } else {
                    alert('Error: ' + res.message);
                    btn.prop('disabled', false).text('Generar Oficio y Guardar');
                }
            },
            error: function () {
                alert('Error de conexión con el servidor.');
                btn.prop('disabled', false).text('Generar Oficio y Guardar');
            }
        });
    });
});
</script>
</body>
</html>