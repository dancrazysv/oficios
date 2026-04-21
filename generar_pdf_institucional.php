<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/helpers/oficio_institucional_html.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Tcpdf\Fpdi;

function fechaEnLetras(?string $fecha): string
{
    if (!$fecha) {
        return '---';
    }

    $meses = [
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'
    ];

    $ts = strtotime($fecha);
    if ($ts === false) {
        return '---';
    }

    return date('d', $ts) . ' de ' . $meses[(int)date('n', $ts) - 1] . ' del año ' . date('Y', $ts);
}

function e($t): string
{
    return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
}

$referencia = trim((string)($_GET['ref'] ?? ''));
if ($referencia === '') {
    die('Referencia no proporcionada.');
}

try {
    $stmt = $pdo->prepare("
        SELECT oi.*, i.nombre_institucion, i.unidad_dependencia, i.ubicacion_sede, i.email_contacto
        FROM oficios_institucionales oi
        JOIN instituciones i ON oi.id_institucion = i.id
        WHERE oi.referencia_salida = ?
        LIMIT 1
    ");
    $stmt->execute([$referencia]);
    $oficio = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$oficio) {
        die('Oficio no encontrado.');
    }

    $stmt_in = $pdo->prepare("
        SELECT *
        FROM oficios_institucionales_entradas
        WHERE id_oficio_inst = ?
        ORDER BY id ASC
    ");
    $stmt_in->execute([$oficio['id']]);
    $entradas = $stmt_in->fetchAll(PDO::FETCH_ASSOC);

    $stmt_det = $pdo->prepare("
        SELECT *
        FROM oficios_institucionales_detalle
        WHERE id_oficio_inst = ?
        ORDER BY id ASC
    ");
    $stmt_det->execute([$oficio['id']]);
    $detalles = $stmt_det->fetchAll(PDO::FETCH_ASSOC);

    $logoPath  = __DIR__ . '/img/img_logo.png';
    $firmaPath = __DIR__ . '/img/firma.png';

    if (!file_exists($logoPath)) {
        die('No se encontró el logo.');
    }

    $img_logo  = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
    $img_firma = (file_exists($firmaPath) && $oficio['estado_validacion'] === 'APROBADO')
        ? 'data:image/png;base64,' . base64_encode(file_get_contents($firmaPath))
        : '';

    $html = construirHtmlOficioInst($oficio, $entradas, $detalles, [
        'preview'       => false,
        'img_logo'      => $img_logo,
        'img_firma'     => $img_firma,
        'mostrar_firma' => ($img_firma !== ''),
    ]);

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Arial');
    $options->set('chroot', __DIR__);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    $pdf_base = $dompdf->output();
    if ($pdf_base === '') {
        die('Error: No se pudo generar el PDF.');
    }

    if (!is_dir(__DIR__ . '/archivos_finales_inst')) {
        mkdir(__DIR__ . '/archivos_finales_inst', 0755, true);
    }

    $nombre_archivo = 'Oficio_Inst_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $oficio['referencia_salida']) . '.pdf';
    $ruta_final = __DIR__ . '/archivos_finales_inst/' . $nombre_archivo;

    $carpeta_anexos = __DIR__ . '/anexos_institucionales/' . $oficio['referencia_salida'] . '/';
    $anexos = [];
    if (is_dir($carpeta_anexos)) {
        $anexos = array_merge(
            glob($carpeta_anexos . '*.pdf') ?: [],
            glob($carpeta_anexos . '*.PDF') ?: []
        );
        sort($anexos);
    }

    if (!empty($anexos)) {
        $tmp_file = sys_get_temp_dir() . '/ofi_inst_' . uniqid('', true) . '.pdf';
        file_put_contents($tmp_file, $pdf_base);

        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($tmp_file);

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $tpl = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($tpl);
            $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);
        }

        foreach ($anexos as $anexo) {
            $count = $pdf->setSourceFile($anexo);
            for ($i = 1; $i <= $count; $i++) {
                $tpl = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);
                $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
            }
        }

        $pdf->Output($ruta_final, 'F');
        if (is_file($tmp_file)) {
            unlink($tmp_file);
        }
    } else {
        file_put_contents($ruta_final, $pdf_base);
    }

    $stmt_upd = $pdo->prepare("
        UPDATE oficios_institucionales
        SET ruta_pdf_final = ?
        WHERE id = ?
    ");
    $stmt_upd->execute(['archivos_finales_inst/' . $nombre_archivo, $oficio['id']]);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $nombre_archivo . '"');
    readfile($ruta_final);
    exit;

} catch (Throwable $e) {
    error_log('Error generar_pdf_institucional: ' . $e->getMessage());
    die('Error al generar el PDF institucional.');
}