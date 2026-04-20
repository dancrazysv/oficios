<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/helpers/oficio_institucional_html.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Tcpdf\Fpdi;

ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/php_errors.log');
error_reporting(E_ALL);

date_default_timezone_set('America/El_Salvador');
ob_start();

function e($t): string
{
    return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
}

function fechaEnLetras(?string $fecha): string
{
    if (!$fecha) {
        return '---';
    }

    $meses = [
        'enero','febrero','marzo','abril','mayo','junio',
        'julio','agosto','septiembre','octubre','noviembre','diciembre'
    ];

    $ts = strtotime($fecha);
    if ($ts === false) {
        return '---';
    }

    return date('d', $ts) . ' de ' . $meses[(int)date('n', $ts) - 1] . ' del ' . date('Y', $ts);
}

function outputPdfWithWatermark(string $rutaPdf, string $nombreSalida): void
{
    if (!file_exists($rutaPdf)) {
        die('Error: El archivo PDF no existe.');
    }

    $pdf = new Fpdi();
    $pageCount = $pdf->setSourceFile($rutaPdf);

    for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
        $tpl = $pdf->importPage($pageNo);
        $size = $pdf->getTemplateSize($tpl);
        $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
        $pdf->AddPage($orientation, [$size['width'], $size['height']]);
        $pdf->useTemplate($tpl);

        $pdf->SetFont('Helvetica', 'B', 45);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->SetAlpha(0.3);

        $centerX = $size['width'] / 2;
        $centerY = $size['height'] / 2;

        $pdf->StartTransform();
        $pdf->Rotate(45, $centerX, $centerY);
        $pdf->Text($centerX - 85, $centerY, 'BORRADOR - VISTA PREVIA');
        $pdf->StopTransform();

        $pdf->SetAlpha(1);
    }

    ob_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($nombreSalida) . '"');
    $pdf->Output();
    exit;
}

function outputPdfPlain(string $rutaPdf, string $nombreSalida): void
{
    if (!file_exists($rutaPdf)) {
        die('Error: El archivo PDF no existe.');
    }

    $pdf = new Fpdi();
    $pageCount = $pdf->setSourceFile($rutaPdf);

    for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
        $tpl = $pdf->importPage($pageNo);
        $size = $pdf->getTemplateSize($tpl);
        $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
        $pdf->AddPage($orientation, [$size['width'], $size['height']]);
        $pdf->useTemplate($tpl);
    }

    ob_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($nombreSalida) . '"');
    $pdf->Output();
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$tipo = strtoupper((string)($_GET['type'] ?? ''));

if (!$id || $tipo === '') {
    die('Parámetros inválidos.');
}

$rol = $_SESSION['user_rol'] ?? 'normal';
$user_id = (int)($_SESSION['user_id'] ?? 0);
$isSupervisor = in_array($rol, ['administrador', 'supervisor'], true);

try {
    if ($tipo === 'OFICIO_INST') {
        $stmt = $pdo->prepare("
            SELECT oi.*, i.nombre_institucion, i.unidad_dependencia, i.ubicacion_sede
            FROM oficios_institucionales oi
            JOIN instituciones i ON oi.id_institucion = i.id
            WHERE oi.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reg) {
            die('Registro no encontrado.');
        }

        if ($rol === 'normal' && (int)$reg['creado_por'] !== $user_id) {
            die('Acceso denegado.');
        }

        $estado = $reg['estado_validacion'] ?? 'PENDIENTE';

        /* ── Si está APROBADO y tiene PDF final, servir ese archivo ── */
        if ($estado === 'APROBADO' && !empty($reg['ruta_pdf_final'])) {
            $rutaFinal = __DIR__ . '/' . ltrim((string)$reg['ruta_pdf_final'], '/\\');
            if (file_exists($rutaFinal)) {
                outputPdfPlain($rutaFinal, basename($rutaFinal));
            }
        }

        /* ── Vista previa: generar PDF con el helper ── */
        $logoPath  = __DIR__ . '/img/img_logo.png';
        $firmaPath = __DIR__ . '/img/firma.png';

        if (!file_exists($logoPath)) {
            die('No se encontró el logo.');
        }

        $imgLogo  = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        $imgFirma = file_exists($firmaPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($firmaPath)) : '';

        $stmt_in = $pdo->prepare("SELECT * FROM oficios_institucionales_entradas WHERE id_oficio_inst = ? ORDER BY id ASC");
        $stmt_in->execute([$id]);
        $entradas = $stmt_in->fetchAll(PDO::FETCH_ASSOC);

        $stmt_det = $pdo->prepare("SELECT * FROM oficios_institucionales_detalle WHERE id_oficio_inst = ? ORDER BY id ASC");
        $stmt_det->execute([$id]);
        $detalles = $stmt_det->fetchAll(PDO::FETCH_ASSOC);

        $html = construirHtmlOficioInst($reg, $entradas, $detalles, [
            'preview'      => !$isSupervisor,
            'img_logo'     => $imgLogo,
            'img_firma'    => $imgFirma,
            'mostrar_firma' => false,
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Arial');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        $tmpFile = sys_get_temp_dir() . '/preview_inst_' . uniqid('', true) . '.pdf';
        file_put_contents($tmpFile, $dompdf->output());

        /* ── Merge annexes if any ── */
        $carpetaAnexos = __DIR__ . '/anexos_institucionales/' . ($reg['referencia_salida'] ?? '') . '/';
        $anexos = [];
        if (is_dir($carpetaAnexos)) {
            $anexos = array_merge(
                glob($carpetaAnexos . '*.pdf') ?: [],
                glob($carpetaAnexos . '*.PDF') ?: []
            );
            sort($anexos);
        }

        if (!empty($anexos)) {
            $tmpMerged = sys_get_temp_dir() . '/preview_merged_' . uniqid('', true) . '.pdf';
            $pdfMerge = new Fpdi();
            $pageCount = $pdfMerge->setSourceFile($tmpFile);
            for ($p = 1; $p <= $pageCount; $p++) {
                $tpl = $pdfMerge->importPage($p);
                $sz  = $pdfMerge->getTemplateSize($tpl);
                $pdfMerge->AddPage(($sz['width'] > $sz['height']) ? 'L' : 'P', [$sz['width'], $sz['height']]);
                $pdfMerge->useTemplate($tpl);
            }
            foreach ($anexos as $anexo) {
                $cnt = $pdfMerge->setSourceFile($anexo);
                for ($p = 1; $p <= $cnt; $p++) {
                    $tpl = $pdfMerge->importPage($p);
                    $sz  = $pdfMerge->getTemplateSize($tpl);
                    $pdfMerge->AddPage(($sz['width'] > $sz['height']) ? 'L' : 'P', [$sz['width'], $sz['height']]);
                    $pdfMerge->useTemplate($tpl);
                }
            }
            $pdfMerge->Output($tmpMerged, 'F');

            register_shutdown_function(function () use ($tmpFile, $tmpMerged) {
                if (file_exists($tmpFile))   @unlink($tmpFile);
                if (file_exists($tmpMerged)) @unlink($tmpMerged);
            });

            if ($isSupervisor) {
                outputPdfPlain($tmpMerged, 'PREVIEW_OFICIO_INST.pdf');
            }
            outputPdfWithWatermark($tmpMerged, 'PREVIEW_OFICIO_INST.pdf');
        }

        register_shutdown_function(function () use ($tmpFile) {
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        });

        if ($isSupervisor) {
            outputPdfPlain($tmpFile, 'PREVIEW_OFICIO_INST.pdf');
        }

        outputPdfWithWatermark($tmpFile, 'PREVIEW_OFICIO_INST.pdf');
    }

    // OFICIO y CONSTANCIA
    if ($tipo === 'OFICIO') {
        $tabla = 'oficios';
        $campo_ref = 'referencia';
    } elseif ($tipo === 'CONSTANCIA') {
        $tabla = 'constancias';
        $campo_ref = 'numero_constancia';
    } else {
        die('Tipo de documento no válido.');
    }

    $stmt = $pdo->prepare("SELECT * FROM $tabla WHERE id = ?");
    $stmt->execute([$id]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reg) {
        die('Registro no encontrado.');
    }

    if ($rol === 'normal') {
        $creado_por = (int)($reg['creado_por'] ?? $reg['creado_por_id'] ?? 0);
        if ($creado_por !== $user_id) {
            die('Acceso denegado.');
        }
    }

    $ruta_final = '';
    if (!empty($reg['ruta_pdf_final'])) {
        $ruta_candidata = __DIR__ . '/' . ltrim((string)$reg['ruta_pdf_final'], '/\\');
        if (file_exists($ruta_candidata)) {
            $ruta_final = $ruta_candidata;
        }
    }

    if ($ruta_final === '') {
        $directorio = __DIR__ . '/archivos_finales/';
        if (is_dir($directorio)) {
            foreach (scandir($directorio) as $archivo) {
                if ($archivo === '.' || $archivo === '..') {
                    continue;
                }
                if (strpos($archivo, (string)($reg[$campo_ref] ?? '')) !== false && strtolower(pathinfo($archivo, PATHINFO_EXTENSION)) === 'pdf') {
                    $ruta_final = $directorio . $archivo;
                    break;
                }
            }
        }
    }

    if ($ruta_final === '' || !file_exists($ruta_final)) {
        die('Error: El archivo PDF no ha sido generado o no existe en la ruta esperada.');
    }

    if ($isSupervisor) {
        outputPdfPlain($ruta_final, basename($ruta_final));
    }

    outputPdfWithWatermark($ruta_final, basename($ruta_final));

} catch (Throwable $e) {
    error_log('Error preview_pdf: ' . $e->getMessage());
    die('Error al generar la vista previa.');
}