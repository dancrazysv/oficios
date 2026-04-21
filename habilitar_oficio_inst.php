<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/helpers/oficio_institucional_html.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Tcpdf\Fpdi;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Error de validación de seguridad (CSRF).']);
    exit;
}

$rol = $_SESSION['user_rol'] ?? 'normal';
if (!in_array($rol, ['administrador', 'supervisor'], true)) {
    echo json_encode(['success' => false, 'message' => 'Acceso denegado.']);
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID de oficio inválido.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT oi.*, i.nombre_institucion, i.unidad_dependencia, i.ubicacion_sede
        FROM oficios_institucionales oi
        INNER JOIN instituciones i ON oi.id_institucion = i.id
        WHERE oi.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $oficio = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$oficio) {
        echo json_encode(['success' => false, 'message' => 'Oficio no encontrado.']);
        exit;
    }

    if (($oficio['estado_validacion'] ?? '') !== 'PENDIENTE') {
        echo json_encode(['success' => false, 'message' => 'El oficio ya ha sido procesado.']);
        exit;
    }

    $referenciaOriginal = trim((string)($oficio['referencia_salida'] ?? ''));
    if ($referenciaOriginal === '') {
        echo json_encode(['success' => false, 'message' => 'La referencia de salida está vacía.']);
        exit;
    }

    $refPdf = preg_replace('/[^A-Za-z0-9_\-]/', '_', $referenciaOriginal);

    $stmt_entradas = $pdo->prepare("
        SELECT *
        FROM oficios_institucionales_entradas
        WHERE id_oficio_inst = ?
        ORDER BY id ASC
    ");
    $stmt_entradas->execute([$id]);
    $entradas = $stmt_entradas->fetchAll(PDO::FETCH_ASSOC);

    $stmt_detalles = $pdo->prepare("
        SELECT *
        FROM oficios_institucionales_detalle
        WHERE id_oficio_inst = ?
        ORDER BY id ASC
    ");
    $stmt_detalles->execute([$id]);
    $detalles = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

    $logoPath = __DIR__ . '/img/img_logo.png';
    $firmaPath = __DIR__ . '/img/firma.png';

    if (!file_exists($logoPath)) {
        throw new Exception("No se encontró el logo.");
    }

    $imgLogo = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
    $imgFirma = file_exists($firmaPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($firmaPath)) : '';

    $html = construirHtmlOficioInst($oficio, $entradas, $detalles, [
        'preview' => false,
        'img_logo' => $imgLogo,
        'img_firma' => $imgFirma,
        'mostrar_firma' => true
    ]);

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Arial');
    $options->set('chroot', __DIR__);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    if (!is_dir(__DIR__ . '/archivos_finales_inst')) {
        mkdir(__DIR__ . '/archivos_finales_inst', 0755, true);
    }

    $pdfBasePath = __DIR__ . '/archivos_finales_inst/' . $refPdf . '_base.pdf';
    $pdfFinalPath = __DIR__ . '/archivos_finales_inst/' . $refPdf . '.pdf';

    file_put_contents($pdfBasePath, $dompdf->output());

    $pdf = new Fpdi();

    $pageCount = $pdf->setSourceFile($pdfBasePath);
    for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
        $tplIdx = $pdf->importPage($pageNo);
        $size = $pdf->getTemplateSize($tplIdx);
        $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
        $pdf->AddPage($orientation, [$size['width'], $size['height']]);
        $pdf->useTemplate($tplIdx);
    }

    $carpetaAnexos = __DIR__ . '/anexos_institucionales/' . $referenciaOriginal . '/';
    if (is_dir($carpetaAnexos)) {
        $anexos = array_merge(
            glob($carpetaAnexos . '*.pdf') ?: [],
            glob($carpetaAnexos . '*.PDF') ?: []
        );
        sort($anexos);

        foreach ($anexos as $anexoPath) {
            $pageCountAnexo = $pdf->setSourceFile($anexoPath);
            for ($pageNo = 1; $pageNo <= $pageCountAnexo; $pageNo++) {
                $tplIdx = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($tplIdx);
                $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($tplIdx);
            }
        }
    }

    $pdf->Output($pdfFinalPath, 'F');

    if (!is_file($pdfFinalPath)) {
        echo json_encode(['success' => false, 'message' => 'No se pudo generar el PDF final.']);
        exit;
    }

    $aprobado_por = (int)($_SESSION['user_id'] ?? 0);
    $stmt_upd = $pdo->prepare("
        UPDATE oficios_institucionales
        SET ruta_pdf_final = ?, estado_validacion = 'APROBADO',
            fecha_aprobacion = NOW(), aprobado_por = ?
        WHERE id = ?
    ");
    $stmt_upd->execute(['archivos_finales_inst/' . basename($pdfFinalPath), $aprobado_por, $id]);

    if (is_file($pdfBasePath)) {
        unlink($pdfBasePath);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Oficio habilitado y PDF final generado correctamente.',
        'pdf' => 'archivos_finales_inst/' . basename($pdfFinalPath)
    ]);
    exit;

} catch (Throwable $e) {
    error_log("Error habilitar_oficio_inst: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al procesar: ' . $e->getMessage()]);
    exit;
}