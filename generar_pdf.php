<?php
include 'check_session.php';
include 'db_config.php';

ob_start();
header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('America/El_Salvador');
setlocale(LC_TIME, 'es_ES.UTF-8', 'es_ES', 'es_SV', 'spanish');

require 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use setasign\Fpdi\Tcpdf\Fpdi;

function getNombreById($pdo, $tabla, $id) {
    if (empty($id)) return '';
    $stmt = $pdo->prepare("SELECT nombre FROM $tabla WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetchColumn() ?? '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

try {
    /* ===================== DATOS DEL FORMULARIO ===================== */
    $nombre_difunto = mb_strtoupper(trim($_POST['nombre_difunto']), 'UTF-8'); 
    $numero_partida = (int)($_POST['numero_partida'] ?? 0);
    $folio = (int)($_POST['folio'] ?? 0);
    $libro = trim($_POST['libro'] ?? '');
    $distrito_inscripcion_nombre = $_POST['distrito_inscripcion'] ?? '';
    $anio_inscripcion = (int)($_POST['anio_inscripcion'] ?? 0);

    $nombre_licenciado = mb_strtoupper(trim($_POST['nombre_licenciado'] ?? ''));
    $cargo_licenciado = trim($_POST['cargo_licenciado'] ?? '');

    $departamento_destino_id = (int)($_POST['departamento_destino'] ?? 0);
    $municipio_destino_id    = (int)($_POST['municipio_destino'] ?? 0);
    $distrito_destino_id     = (int)($_POST['distrito_destino'] ?? 0);

    $departamento_origen_id = (int)($_POST['departamento_origen'] ?? 0);
    $municipio_origen_id    = (int)($_POST['municipio_origen'] ?? 0);
    $distrito_origen_id     = (int)($_POST['distrito_origen'] ?? 0);

    $user_rol = $_SESSION['user_rol'] ?? 'normal';
    $creado_por = (int)($_SESSION['user_id'] ?? 0);

    /* ===================== VALIDACIÓN DE DUPLICIDAD (NUEVO) ===================== */
    // Verificamos si ya existe este difunto con esa partida y año para evitar doble envío
    $stmt_check = $pdo->prepare("SELECT referencia FROM oficios WHERE nombre_difunto = ? AND numero_partida = ? AND anio_inscripcion = ? LIMIT 1");
    $stmt_check->execute([$nombre_difunto, $numero_partida, $anio_inscripcion]);
    $existe = $stmt_check->fetch();

    if ($existe) {
        ob_clean();
        echo json_encode([
            'success' => false, 
            'message' => 'Error: Ya existe un oficio registrado para este difunto con el mismo número de partida y año.',
            'referencia' => $existe['referencia']
        ]);
        exit;
    }

    /* ===================== TRANSACCIÓN Y CORRELATIVO ===================== */
    $pdo->beginTransaction(); // Iniciamos transacción para bloquear el cálculo del correlativo

    $anio_actual = date('Y');
    
    // Buscamos el máximo asegurándonos de que sea un año actual
    $stmt_corr = $pdo->prepare("SELECT MAX(correlativo_anual) FROM oficios WHERE YEAR(fecha) = ?");
    $stmt_corr->execute([$anio_actual]);
    $ultimo_valor_bd = (int)$stmt_corr->fetchColumn();

    $punto_partida_manual = 509; 
    
    if ($ultimo_valor_bd > $punto_partida_manual) {
        $nuevo_correlativo = $ultimo_valor_bd + 1;
    } else {
        $nuevo_correlativo = $punto_partida_manual + 1;
    }

    $referencia = "REFSSC-" . $anio_actual . "-" . str_pad((string)$nuevo_correlativo, 4, "0", STR_PAD_LEFT);

    /* ===================== OBTENER NOMBRES PARA EL PDF ===================== */
    $depto_destino_nom = getNombreById($pdo, 'departamentos', $departamento_destino_id);
    $muni_destino_nom  = getNombreById($pdo, 'municipios', $municipio_destino_id);
    $dist_destino_nom  = getNombreById($pdo, 'distritos', $distrito_destino_id);

    $depto_origen_nom  = getNombreById($pdo, 'departamentos', $departamento_origen_id);
    $muni_origen_nom   = getNombreById($pdo, 'municipios', $municipio_origen_id);
    $dist_origen_nom   = getNombreById($pdo, 'distritos', $distrito_origen_id);

    $fecha_creacion = date('Y-m-d H:i:s');
    $fecha_display  = strftime('%d de %B de %Y', strtotime($fecha_creacion));
    $estado_inicial = ($user_rol === 'normal') ? 'PENDIENTE' : 'APROBADO';

    /* ===================== GUARDAR EN BASE DE DATOS ===================== */
    $sql_insert = "INSERT INTO oficios (
        referencia, correlativo_anual, fecha, nombre_licenciado, cargo_licenciado, 
        distrito_destino, municipio_destino, departamento_destino, municipio_destino_id, 
        nombre_difunto, numero_partida, folio, libro, distrito_inscripcion, anio_inscripcion, 
        departamento_origen, municipio_origen, distrito_origen, creado_por, estado_validacion
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

    $pdo->prepare($sql_insert)->execute([
        $referencia, $nuevo_correlativo, $fecha_creacion, $nombre_licenciado, $cargo_licenciado, 
        $dist_destino_nom, $muni_destino_nom, $depto_destino_nom, $municipio_destino_id, 
        $nombre_difunto, $numero_partida, $folio, $libro, $distrito_inscripcion_nombre, $anio_inscripcion, 
        $depto_origen_nom, $muni_origen_nom, $dist_origen_nom, $creado_por, $estado_inicial
    ]);

    $pdo->commit(); // Si llegamos aquí, los datos están seguros y no duplicados

    /* ===================== PROCESAMIENTO DE IMÁGENES Y PDF ===================== */
    $fondo_path = 'img/fondo_oficio.png';
    $background_style = file_exists($fondo_path) ? "background-image: url('data:image/png;base64,".base64_encode(file_get_contents($fondo_path))."'); background-size: 100% 100%;" : "";

    $logo_path = 'img/img_logo.png';
    $logo_html = file_exists($logo_path) ? '<img src="data:image/png;base64,'.base64_encode(file_get_contents($logo_path)).'" style="width: 300px;">' : '';

    $firma_path = 'img/firma.png';
    $firma_html = file_exists($firma_path) ? '<img src="data:image/png;base64,'.base64_encode(file_get_contents($firma_path)).'" style="width: 400px;">' : $nombre_licenciado;

    $qr = Builder::create()->data("https://amssmarginaciones.sansalvador.gob.sv/validar.php?ref=".$referencia)->writer(new PngWriter())->size(150)->build();

    $html = file_get_contents('plantilla_oficio.html');
    $replacements = [
        '{{referencia}}' => $referencia, '{{fecha}}' => $fecha_display, '{{nombre_licenciado}}' => $nombre_licenciado,
        '{{cargo_licenciado}}' => $cargo_licenciado, '{{nombre_difunto}}' => $nombre_difunto, '{{numero_partida}}' => $numero_partida,
        '{{folio}}' => $folio, '{{libro}}' => $libro, '{{anio_inscripcion}}' => $anio_inscripcion,
        '{{distrito_destino}}' => $dist_destino_nom, '{{municipio_destino}}' => $muni_destino_nom, '{{departamento_destino}}' => $depto_destino_nom,
        '{{distrito_inscripcion}}' => $distrito_inscripcion_nombre, '{{distrito_origen}}' => $dist_origen_nom,
        '{{municipio_origen}}' => $muni_origen_nom, '{{departamento_origen}}' => $depto_origen_nom,
        '{{logo_img}}' => $logo_html, '{{imagen_firma}}' => $firma_html, '{{qr_code}}' => '<img src="'.$qr->getDataUri().'" style="width:120px;">',
        '{{background_style}}' => $background_style
    ];
    $html = str_replace(array_keys($replacements), array_values($replacements), $html);

    $dompdf = new Dompdf((new Options())->set('isRemoteEnabled', true));
    $dompdf->loadHtml($html);
    $dompdf->setPaper('Letter', 'portrait');
    $dompdf->render();
    $oficio_pdf_raw = $dompdf->output();

    /* ===================== GUARDADO FÍSICO ===================== */
    $nombre_archivo = 'Oficio_' . str_replace('-', '_', $referencia) . '.pdf';
    $ruta_archivo_final = __DIR__ . '/archivos_finales/' . $nombre_archivo;
    if (!is_dir('archivos_finales')) { mkdir('archivos_finales', 0777, true); }

    if (isset($_FILES['archivo_anexo']) && $_FILES['archivo_anexo']['error'] === UPLOAD_ERR_OK) {
        $pdf_fusion = new Fpdi();
        $tmp_file = sys_get_temp_dir() . '/' . uniqid() . '.pdf';
        file_put_contents($tmp_file, $oficio_pdf_raw);
        $pdf_fusion->setPrintHeader(false);
        $pdf_fusion->setSourceFile($tmp_file);
        $pdf_fusion->AddPage(); 
        $pdf_fusion->useTemplate($pdf_fusion->importPage(1));
        
        $count = $pdf_fusion->setSourceFile($_FILES['archivo_anexo']['tmp_name']);
        for ($i = 1; $i <= $count; $i++) {
            $pdf_fusion->AddPage();
            $tpl = $pdf_fusion->importPage($i);
            $size = $pdf_fusion->getTemplateSize($tpl);
            $pdf_fusion->useTemplate($tpl, 0, 0, $size['width'], $size['height']);
        }
        $pdf_fusion->Output($ruta_archivo_final, 'F');
        unlink($tmp_file);
    } else {
        file_put_contents($ruta_archivo_final, $oficio_pdf_raw);
    }

    $pdo->prepare("UPDATE oficios SET ruta_pdf_final = ? WHERE referencia = ?")->execute(['archivos_finales/' . $nombre_archivo, $referencia]);

    ob_clean();
    echo json_encode(['success' => true, 'referencia' => $referencia]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}