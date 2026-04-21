<?php
declare(strict_types=1);
// 1. Limpieza estricta de salida para evitar "Error: undefined"
ob_start();

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== VALIDAR OPERADOR =====
$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    ob_clean();
    die(json_encode(['success' => false, 'message' => 'Sesión expirada']));
}

// Configuración de tiempo
date_default_timezone_set('America/El_Salvador');

require_once __DIR__ . '/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use setasign\Fpdi\Tcpdf\Fpdi;

// === FUNCIONES DE UTILIDAD ===
function getIdByName($pdo, $table, $name) {
    if (empty($name)) return null;
    $stmt = $pdo->prepare("SELECT id FROM $table WHERE nombre = ? LIMIT 1");
    $stmt->execute([$name]);
    return $stmt->fetchColumn(); 
}

function getNombreById($pdo, $table, $id) {
    if (empty($id)) return '';
    $stmt = $pdo->prepare("SELECT nombre FROM $table WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetchColumn() ?? '';
}

function getSelectOptions($pdo, $table, $fk_col, $fk_id, $selected_name) {
    $options = '<option value="">Seleccione...</option>';
    if ($fk_id) {
        $stmt = $pdo->prepare("SELECT id, nombre FROM $table WHERE $fk_col = ? ORDER BY nombre");
        $stmt->execute([$fk_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as $item) {
            $selected = ($item['nombre'] == $selected_name) ? 'selected' : '';
            $options .= "<option value=\"{$item['id']}\" {$selected}>" . htmlspecialchars($item['nombre']) . "</option>";
        }
    }
    return $options;
}

// Función para formatear fecha en español sin usar strftime (depreciado)
function fechaEspañol($fecha) {
    $dias = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"];
    $meses = ["", "enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
    $timestamp = strtotime($fecha);
    return date('d', $timestamp) . " de " . $meses[date('n', $timestamp)] . " de " . date('Y', $timestamp);
}

// === CARGA DE DATOS DEL OFICIO (GET) ===
$oficio_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$oficio_id) {
    ob_clean();
    die(json_encode(['success' => false, 'message' => 'ID de oficio no proporcionado o inválido.']));
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

// === PROCESAR GUARDADO (POST) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_cambios'])) {
    try {
        // Verificar CSRF
        if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Error de validación de seguridad (CSRF).']);
            exit;
        }
        // Limpiar cualquier salida accidental
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        $depto_dest_nom = getNombreById($pdo, 'departamentos', $_POST['departamento_destino']);
        $muni_dest_nom  = getNombreById($pdo, 'municipios', $_POST['municipio_destino']);
        $dist_dest_nom  = getNombreById($pdo, 'distritos', $_POST['distrito_destino']);
        
        $depto_orig_nom = getNombreById($pdo, 'departamentos', $_POST['departamento_origen']);
        $muni_orig_nom  = getNombreById($pdo, 'municipios', $_POST['municipio_origen']);
        $dist_orig_nom  = getNombreById($pdo, 'distritos', $_POST['distrito_origen']);

        $nombre_difunto = mb_strtoupper(trim($_POST['nombre_difunto']), 'UTF-8');
        $nombre_lic     = mb_strtoupper(trim($_POST['nombre_licenciado']), 'UTF-8');

        $sql_update = "UPDATE oficios SET 
            nombre_licenciado = ?, cargo_licenciado = ?, distrito_destino = ?, municipio_destino = ?, 
            departamento_destino = ?, nombre_difunto = ?, numero_partida = ?, folio = ?, libro = ?, 
            distrito_inscripcion = ?, anio_inscripcion = ?, departamento_origen = ?, 
            municipio_origen = ?, distrito_origen = ?, municipio_destino_id = ?
            WHERE id = ?";
        
        $pdo->prepare($sql_update)->execute([
            $nombre_lic, $_POST['cargo_licenciado'], $dist_dest_nom, $muni_dest_nom, 
            $depto_dest_nom, $nombre_difunto, $_POST['numero_partida'], $_POST['folio'], 
            $_POST['libro'], $_POST['distrito_inscripcion'], $_POST['anio_inscripcion'], 
            $depto_orig_nom, $muni_orig_nom, $dist_orig_nom, $_POST['municipio_destino'], $oficio_id
        ]);

        $stmt = $pdo->prepare("SELECT referencia, fecha FROM oficios WHERE id = ?");
        $stmt->execute([$oficio_id]);
        $oficio_data = $stmt->fetch();

        // Generación de PDF
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);

        $fondo_path = 'img/fondo_oficio.png';
        $background_style = file_exists($fondo_path) ? "background-image: url('data:image/png;base64,".base64_encode(file_get_contents($fondo_path))."'); background-size: 100% 100%;" : "";
        $logo_html = file_exists('img/img_logo.png') ? '<img src="data:image/png;base64,'.base64_encode(file_get_contents('img/img_logo.png')).'" style="width:250px;">' : '';
        $firma_html = file_exists('img/firma.png') ? '<img src="data:image/png;base64,'.base64_encode(file_get_contents('img/firma.png')).'" style="width:400px;">' : $nombre_lic;

        $qr = Builder::create()
            ->data("https://amssmarginaciones.sansalvador.gob.sv/validar.php?ref=".$oficio_data['referencia'])
            ->writer(new PngWriter())->size(150)->build();

        $html = file_get_contents('plantilla_oficio.html');
        $search = [
            '{{referencia}}', '{{fecha}}', '{{nombre_licenciado}}', '{{cargo_licenciado}}',
            '{{distrito_destino}}', '{{municipio_destino}}', '{{departamento_destino}}',
            '{{nombre_difunto}}', '{{numero_partida}}', '{{folio}}', '{{libro}}',
            '{{distrito_inscripcion}}', '{{anio_inscripcion}}', '{{distrito_origen}}',
            '{{municipio_origen}}', '{{departamento_origen}}', '{{logo_img}}',
            '{{qr_code}}', '{{imagen_firma}}', '{{background_style}}'
        ];
        $replace = [
            $oficio_data['referencia'], fechaEspañol($oficio_data['fecha']), 
            $nombre_lic, $_POST['cargo_licenciado'], $dist_dest_nom, $muni_dest_nom, $depto_dest_nom,
            $nombre_difunto, $_POST['numero_partida'], $_POST['folio'], $_POST['libro'],
            $_POST['distrito_inscripcion'], $_POST['anio_inscripcion'], $dist_orig_nom, $muni_orig_nom, $depto_orig_nom,
            $logo_html, '<img src="'.$qr->getDataUri().'" style="width:120px;">', $firma_html, $background_style
        ];
        $html = str_replace($search, $replace, $html);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('Letter', 'portrait');
        $dompdf->render();
        $pdf_content = $dompdf->output();

        $filename = 'Oficio_' . str_replace(['/', '-'], '_', $oficio_data['referencia']) . '.pdf';
        $final_path = __DIR__.'/archivos_finales/'.$filename;

        if (isset($_FILES['archivo_anexo']) && $_FILES['archivo_anexo']['error'] === 0) {
            $fpdi = new Fpdi();
            $tmp = sys_get_temp_dir().'/'.uniqid().'.pdf';
            file_put_contents($tmp, $pdf_content);
            $fpdi->setPrintHeader(false);
            $fpdi->setSourceFile($tmp); 
            $fpdi->AddPage(); 
            $fpdi->useTemplate($fpdi->importPage(1));
            
            $count = $fpdi->setSourceFile($_FILES['archivo_anexo']['tmp_name']);
            for($i=1; $i<=$count; $i++){ 
                $fpdi->AddPage(); 
                $tpl = $fpdi->importPage($i); 
                $s = $fpdi->getTemplateSize($tpl); 
                $fpdi->useTemplate($tpl, 0, 0, $s['width'], $s['height']); 
            }
            $fpdi->Output($final_path, 'F');
            unlink($tmp);
        } else {
            file_put_contents($final_path, $pdf_content);
        }

        $rel_path = 'archivos_finales/' . $filename;
        $pdo->prepare("UPDATE oficios SET ruta_pdf_final = ? WHERE id = ?")->execute([$rel_path, $oficio_id]);

        echo json_encode(['success' => true]);
        exit;

    } catch (Exception $e) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// === CARGA DE VISTA (GET) ===
// (El resto del código HTML permanece igual...)
try {
    $stmt = $pdo->prepare("SELECT * FROM oficios WHERE id = ?");
    $stmt->execute([$oficio_id]);
    $oficio = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt_depto = $pdo->query("SELECT id, nombre FROM departamentos ORDER BY nombre");
    $departamentos = $stmt_depto->fetchAll();

    $depto_destino_id = getIdByName($pdo, 'departamentos', $oficio['departamento_destino']);
    $muni_destino_id = getIdByName($pdo, 'municipios', $oficio['municipio_destino']);
    $depto_origen_id = getIdByName($pdo, 'departamentos', $oficio['departamento_origen']);
    $muni_origen_id = getIdByName($pdo, 'municipios', $oficio['municipio_origen']);

    $muni_destino_options = getSelectOptions($pdo, 'municipios', 'departamento_id', $depto_destino_id, $oficio['municipio_destino']);
    $dist_destino_options = getSelectOptions($pdo, 'distritos', 'municipio_id', $muni_destino_id, $oficio['distrito_destino']);
    $muni_origen_options = getSelectOptions($pdo, 'municipios', 'departamento_id', $depto_origen_id, $oficio['municipio_origen']);
    $dist_origen_options = getSelectOptions($pdo, 'distritos', 'municipio_id', $muni_origen_id, $oficio['distrito_origen']);

} catch (PDOException $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Oficio - <?php echo htmlspecialchars($oficio['referencia']); ?></title>
    <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .container { max-width: 850px; margin-top: 40px; margin-bottom: 40px; padding: 35px; background-color: #fff; border-radius: 12px; box-shadow: 0 8px 15px rgba(0,0,0,0.1); }
        .section-title { border-bottom: 2px solid #007bff; margin-bottom: 25px; padding-bottom: 10px; color: #007bff; font-weight: bold; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container-fluid"><a class="navbar-brand font-weight-bold" href="dashboard.php">Sistema de Trámites</a></div>
</nav>

<div class="container">
    <h2 class="text-center mb-4 section-title">Editar Oficio: <?php echo htmlspecialchars($oficio['referencia']); ?></h2>

    <form id="editForm" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$oficio_id; ?>">
        <input type="hidden" name="guardar_cambios" value="1">

        <div class="form-row">
            <div class="form-group col-md-6">
                <label class="font-weight-bold">Referencia:</label>
                <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($oficio['referencia']); ?>" readonly>
            </div>
            <div class="form-group col-md-6">
                <label class="font-weight-bold">Fecha Original:</label>
                <input type="text" class="form-control bg-light" value="<?php echo date('d/m/Y', strtotime($oficio['fecha'])); ?>" readonly>
            </div>
        </div>

        <h4 class="mt-4 text-secondary">1. Datos del Destinatario</h4>
        <div class="form-row">
            <div class="form-group col-md-4">
                <label>Departamento:</label>
                <select class="form-control" id="departamento_destino" name="departamento_destino" required>
                    <?php foreach ($departamentos as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo ($d['id'] == $depto_destino_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-md-4">
                <label>Municipio:</label>
                <select class="form-control" id="municipio_destino" name="municipio_destino" required><?php echo $muni_destino_options; ?></select>
            </div>
            <div class="form-group col-md-4">
                <label>Distrito:</label>
                <select class="form-control" id="distrito_destino" name="distrito_destino" required><?php echo $dist_destino_options; ?></select>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Nombre del Registrador:</label>
                <input type="text" class="form-control bg-light" id="nombre_licenciado" name="nombre_licenciado" value="<?php echo htmlspecialchars($oficio['nombre_licenciado']); ?>" readonly>
            </div>
            <div class="form-group col-md-6">
                <label>Cargo del Destinatario:</label>
                <input type="text" class="form-control bg-light" id="cargo_licenciado" name="cargo_licenciado" value="<?php echo htmlspecialchars($oficio['cargo_licenciado']); ?>" readonly>
            </div>
        </div>

        <h4 class="mt-4 text-secondary">2. Datos del Difunto e Inscripción</h4>
        <div class="form-group">
            <label>Nombre Completo del Difunto:</label>
            <input type="text" class="form-control" name="nombre_difunto" value="<?php echo htmlspecialchars($oficio['nombre_difunto']); ?>" required style="text-transform: uppercase;">
        </div>
        <div class="form-row">
            <div class="form-group col-md-4">
                <label>Número de Partida:</label>
                <input type="text" class="form-control" name="numero_partida" value="<?php echo htmlspecialchars($oficio['numero_partida']); ?>" required>
            </div>
            <div class="form-group col-md-4">
                <label>Folio:</label>
                <input type="text" class="form-control" name="folio" value="<?php echo htmlspecialchars($oficio['folio']); ?>" required>
            </div>
            <div class="form-group col-md-4">
                <label>Libro:</label>
                <input type="text" class="form-control" name="libro" value="<?php echo htmlspecialchars($oficio['libro']); ?>" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Distrito de Inscripción:</label>
                <select class="form-control" id="distrito_inscripcion" name="distrito_inscripcion" required></select>
            </div>
            <div class="form-group col-md-6">
                <label>Año de Inscripción:</label>
                <input type="number" class="form-control" name="anio_inscripcion" value="<?php echo htmlspecialchars($oficio['anio_inscripcion']); ?>" required>
            </div>
        </div>

        <h4 class="mt-4 text-secondary">3. Lugar de Origen del Difunto</h4>
        <div class="form-row">
            <div class="form-group col-md-4">
                <label>Departamento:</label>
                <select class="form-control" id="departamento_origen" name="departamento_origen" required>
                    <?php foreach ($departamentos as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo ($d['id'] == $depto_origen_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-md-4">
                <label>Municipio:</label>
                <select class="form-control" id="municipio_origen" name="municipio_origen" required><?php echo $muni_origen_options; ?></select>
            </div>
            <div class="form-group col-md-4">
                <label>Distrito:</label>
                <select class="form-control" id="distrito_origen" name="distrito_origen" required><?php echo $dist_origen_options; ?></select>
            </div>
        </div>

        <h4 class="mt-4 text-secondary">4. Documentación</h4>
        <div class="form-group bg-light p-3 border rounded">
            <label>Anexar nuevo PDF (Opcional - Reemplazará al anexo anterior):</label>
            <input type="file" class="form-control-file" name="archivo_anexo" accept="application/pdf">
        </div>

        <button type="submit" id="btnSubmit" class="btn btn-primary btn-block btn-lg shadow mt-4">Guardar Cambios y Actualizar PDF</button>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-block mt-2">Cancelar y Volver</a>
    </form>
</div>

<script src="bootstrap/js/jquery-3.7.1.min.js"></script>
<script src="bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function () {
    // Carga de distritos inscripción
    fetch('get_distritos_inscripcion.php').then(r => r.json()).then(data => {
        const select = $('#distrito_inscripcion');
        const current = <?php echo json_encode($oficio['distrito_inscripcion'] ?? ''); ?>;
        data.forEach(item => select.append($('<option>', { value: item, text: item, selected: (item == current) })));
    });

    function loadMuni(depId, targetMuni) {
        $.post('get_data.php', { action: 'get_municipios', departamento_id: depId }, function (data) {
            targetMuni.html('<option value="">Seleccione...</option>' + data);
        });
    }

    function loadDist(muniId, targetDist) {
        $.post('get_data.php', { action: 'get_distritos', municipio_id: muniId }, function (data) {
            targetDist.html('<option value="">Seleccione...</option>' + data);
        });
    }

    $('#departamento_destino').change(function () { loadMuni($(this).val(), $('#municipio_destino')); });
    $('#municipio_destino').change(function () {
        const muniId = $(this).val();
        loadDist(muniId, $('#distrito_destino'));
        if (muniId) {
            $.post('get_data.php', { action: 'get_oficiante', municipio_id: muniId }, function (res) {
                if (res.success && res.oficiante) {
                    $('#nombre_licenciado').val(res.oficiante.nombre);
                    $('#cargo_licenciado').val(res.oficiante.cargo);
                }
            }, 'json');
        }
    });

    $('#departamento_origen').change(function () { loadMuni($(this).val(), $('#municipio_origen')); });
    $('#municipio_origen').change(function () { loadDist($(this).val(), $('#distrito_origen')); });

    $('#editForm').on('submit', function (e) {
        e.preventDefault();
        $('#btnSubmit').prop('disabled', true).text('Procesando Actualización...');
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: new FormData(this),
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (res) {
                if(res.success) {
                    alert('¡Registro y PDF actualizados con éxito!');
                    window.location.href = 'dashboard.php';
                } else {
                    alert('Error: ' + res.message);
                    $('#btnSubmit').prop('disabled', false).text('Guardar Cambios y Actualizar PDF');
                }
            },
            error: function (xhr) {
                console.error(xhr.responseText);
                alert('Error crítico: El servidor devolvió una respuesta inválida.');
                $('#btnSubmit').prop('disabled', false).text('Guardar Cambios y Actualizar PDF');
            }
        });
    });
});
</script>
</body>
</html>