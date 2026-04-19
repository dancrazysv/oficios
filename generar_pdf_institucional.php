<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/vendor/autoload.php';

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

    $logoPath = __DIR__ . '/img/img_logo.png';
    $firmaPath = __DIR__ . '/img/firma.png';

    if (!file_exists($logoPath)) {
        die('No se encontró el logo.');
    }

    $img_logo = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
    $img_firma = (file_exists($firmaPath) && $oficio['estado_validacion'] === 'APROBADO')
        ? 'data:image/png;base64,' . base64_encode(file_get_contents($firmaPath))
        : '';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        @page { margin: 2.5cm 2cm; }
        body { font-family: Arial, sans-serif; font-size: 11pt; line-height: 1.5; color: #000; text-align: justify; }
        .header { text-align: center; margin-bottom: 20px; }
        .header img { width: 80px; display:block; margin:0 auto; }
        .institucion-header { text-align:center; font-weight:bold; font-size:12pt; text-transform:uppercase; margin-bottom:20px; line-height:1.3; }
        .bold { font-weight: bold; }
        .uppercase { text-transform: uppercase; }
        .signature-block { margin-top: 50px; text-align: center; }
        .signature-img { width: 280px; margin-bottom: -40px; display:block; margin-left:auto; margin-right:auto; }
        .parrafo { text-indent: 25px; margin-bottom: 10px; }
        .lista { margin: 10px 0 10px 20px; }
        .lista li { margin-bottom: 5px; }
    </style></head><body>';

    $html .= '<div class="header"><img src="'.$img_logo.'" alt="Logo"></div>';
    $html .= '<div class="institucion-header">REGISTRO DEL ESTADO FAMILIAR<br>DISTRITO SAN SALVADOR SEDE</div>';
    $html .= '<div class="bold">A QUIEN CORRESPONDA:</div>';
    $html .= '<div class="bold" style="margin-top:15px;">OFICIO No. ' . e($oficio['referencia_salida']) . '</div>';

    $html .= '<div class="parrafo" style="margin-top:15px;">';

    if (!empty($entradas)) {
        $agrupados = [];
        foreach ($entradas as $e) {
            $key = trim((string)$e['num_oficio_in']) . '|' . trim((string)$e['ref_expediente_in']) . '|' . trim((string)$e['fecha_doc_in']);
            if (!isset($agrupados[$key])) {
                $agrupados[$key] = [
                    'num' => $e['num_oficio_in'],
                    'ref' => $e['ref_expediente_in'],
                    'fec' => $e['fecha_doc_in'],
                    'pets' => []
                ];
            }
            $agrupados[$key]['pets'][] = 'CERTIFICACIÓN DE PARTIDA DE ' . e($e['tipo_partida_solicitada']) . ' a nombre de: <span class="bold uppercase">' . e($e['nombre_solicitado']) . '</span>';
        }

        $html .= 'En atención a su(s) Oficio(s) número(s) <span class="bold">' . implode(', ', array_map(fn($x) => e($x['num']), $agrupados)) . '</span>, ';
        $html .= 'con Referencia(s) <span class="bold">' . implode(', ', array_map(fn($x) => e($x['ref']), $agrupados)) . '</span>, ';
        $html .= 'de fecha(s) <span class="bold">' . implode(', ', array_map(fn($x) => fechaEnLetras($x['fec']), $agrupados)) . '</span>, ';
        $html .= 'en el cual solicita se remita información a nombre de: ';
        $nombres = [];
        foreach ($agrupados as $g) {
            foreach ($g['pets'] as $pet) {
                $nombres[] = $pet;
            }
        }
        $html .= implode('; ', $nombres) . '.';
    } else {
        $html .= 'En atención a su solicitud institucional, se procede a informar lo siguiente.';
    }

    $html .= '</div>';
    $html .= '<div class="parrafo">Habiéndose efectuado la respectiva búsqueda en los registros de nuestra base de datos que corresponde únicamente al Distrito de San Salvador Sede, se informa lo siguiente:</div>';

    foreach ($detalles as $d) {
        $html .= '<div class="parrafo">';
        if (($d['resultado'] ?? '') === 'ENCONTRADO') {
            $html .= '<span class="bold uppercase">SE ENCONTRÓ</span> registro de ' . e(strtolower((string)$d['tipo_tramite'])) . ' a nombre de ';
            $html .= '<span class="bold uppercase">' . e($d['nombre_consultado']) . '</span>';
            $html .= ', asentada bajo el número <span class="bold">' . e($d['partida_numero'] ?? '') . '</span>, ';
            $html .= 'folio <span class="bold">' . e($d['partida_folio'] ?? '') . '</span>, ';
            $html .= 'libro <span class="bold">' . e($d['partida_libro'] ?? '') . '</span> ';
            $html .= 'del año <span class="bold">' . e($d['partida_anio'] ?? '') . '</span>.';
        } else {
            $html .= '<span class="bold uppercase">NO SE ENCONTRÓ</span> registro de ' . e(strtolower((string)$d['tipo_tramite'])) . ' a nombre de ';
            $html .= '<span class="bold uppercase">' . e($d['nombre_consultado']) . '</span>.';
        }
        if (!empty($d['observaciones'])) {
            $html .= ' <em>' . e($d['observaciones']) . '</em>';
        }
        $html .= '</div>';
    }

    $html .= '<div class="parrafo" style="margin-top:20px;">';
    $html .= 'Se extiende la presente en Distrito de San Salvador Sede, San Salvador Centro, San Salvador, el día <span class="bold">' . fechaEnLetras($oficio['fecha_documento'] ?? $oficio['fecha']) . '</span>. ';
    $html .= 'Se advierte que este Registro del Estado Familiar no es responsable por la inexactitud o falsedad de los datos proporcionados en la presente. ';
    $html .= '<span class="bold uppercase">CUALQUIER ALTERACIÓN ANULA EL PRESENTE DOCUMENTO.</span>';
    $html .= '</div>';

    $html .= '<div class="signature-block">';
    if ($img_firma !== '') {
        $html .= '<img src="'.$img_firma.'" class="signature-img" alt="Firma"><br>';
    }
    $html .= '<span class="bold uppercase">Licda. Karla Mariela Olivares Martinez</span><br>';
    $html .= 'REGISTRADORA DEL ESTADO FAMILIAR<br>DE SAN SALVADOR CENTRO';
    $html .= '</div>';

    $html .= '</body></html>';

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