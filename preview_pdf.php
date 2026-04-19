<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/vendor/autoload.php';

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

        $stmt_in = $pdo->prepare("SELECT * FROM oficios_institucionales_entradas WHERE id_oficio_inst = ? ORDER BY id ASC");
        $stmt_in->execute([$id]);
        $entradas = $stmt_in->fetchAll(PDO::FETCH_ASSOC);

        $stmt_det = $pdo->prepare("SELECT * FROM oficios_institucionales_detalle WHERE id_oficio_inst = ? ORDER BY id ASC");
        $stmt_det->execute([$id]);
        $detalles = $stmt_det->fetchAll(PDO::FETCH_ASSOC);

        $logoPath = __DIR__ . '/img/img_logo.png';
        $firmaPath = __DIR__ . '/img/firma.png';

        if (!file_exists($logoPath)) {
            die('No se encontró el logo.');
        }

        $img_logo = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        $img_firma = file_exists($firmaPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($firmaPath)) : '';

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
        </style></head><body>';

        $html .= '<div class="header"><img src="'.$img_logo.'" alt="Logo"></div>';
        $html .= '<div class="institucion-header">REGISTRO DEL ESTADO FAMILIAR<br>DISTRITO SAN SALVADOR SEDE</div>';
        $html .= '<div class="bold">A QUIEN CORRESPONDA:</div>';
        $html .= '<div class="bold" style="margin-top:15px;">OFICIO No. ' . e($reg['referencia_salida']) . '</div>';

        $html .= '<div class="parrafo" style="margin-top:15px;">';
        if (!empty($entradas)) {
            $agrupados = [];
            foreach ($entradas as $ent) {
                $key = trim((string)$ent['num_oficio_in']) . '|' . trim((string)$ent['ref_expediente_in']) . '|' . trim((string)$ent['fecha_doc_in']);
                if (!isset($agrupados[$key])) {
                    $agrupados[$key] = [
                        'num' => $ent['num_oficio_in'],
                        'ref' => $ent['ref_expediente_in'],
                        'fec' => $ent['fecha_doc_in'],
                        'pets' => []
                    ];
                }
                $agrupados[$key]['pets'][] = 'CERTIFICACIÓN DE PARTIDA DE ' . e($ent['tipo_partida_solicitada']) . ' a nombre de: <span class="bold uppercase">' . e($ent['nombre_solicitado']) . '</span>';
            }

            $partes = [];
            foreach ($agrupados as $g) {
                $partes[] = '<span class="bold">' . e($g['num']) . '</span>, con Referencia <span class="bold">' . e($g['ref'] ?: 'S/N') . '</span>, de fecha ' . fechaEnLetras($g['fec']) . ', en el cual solicita se remita ' . implode(', ', $g['pets']) . '.';
            }

            $html .= 'En atención a su(s) Oficio(s) número(s): ' . implode(' ', $partes);
        } else {
            $html .= 'En atención a su solicitud institucional, se procede a informar lo siguiente.';
        }
        $html .= '</div>';

        $html .= '<div class="parrafo">Habiéndose efectuado la respectiva búsqueda en los registros de nuestra base de datos que corresponde únicamente al Distrito de San Salvador Sede, se informa lo siguiente:</div>';

        foreach ($detalles as $det) {
            $html .= '<div class="parrafo">';
            if (($det['resultado'] ?? '') === 'ENCONTRADO') {
                $html .= '<span class="bold uppercase">SE ENCONTRÓ</span> registro de ' . e(strtolower((string)$det['tipo_tramite'])) . ' a nombre de ';
                $html .= '<span class="bold uppercase">' . e($det['nombre_consultado']) . '</span>';
                $html .= ', asentada bajo el número <span class="bold">' . e($det['partida_numero'] ?? '') . '</span>, ';
                $html .= 'folio <span class="bold">' . e($det['partida_folio'] ?? '') . '</span>, ';
                $html .= 'libro <span class="bold">' . e($det['partida_libro'] ?? '') . '</span> ';
                $html .= 'del año <span class="bold">' . e($det['partida_anio'] ?? '') . '</span>.';
            } else {
                $html .= '<span class="bold uppercase">NO SE ENCONTRÓ</span> registro de ' . e(strtolower((string)$det['tipo_tramite'])) . ' a nombre de ';
                $html .= '<span class="bold uppercase">' . e($det['nombre_consultado']) . '</span>.';
            }
            if (!empty($det['observaciones'])) {
                $html .= ' <em>' . e($det['observaciones']) . '</em>';
            }
            $html .= '</div>';
        }

        $html .= '<div class="parrafo" style="margin-top:20px;">';
        $html .= 'Se extiende la presente en Distrito de San Salvador Sede, San Salvador Centro, San Salvador, el día <span class="bold">' . fechaEnLetras($reg['fecha_documento'] ?? $reg['fecha']) . '</span>. ';
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

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        ob_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="PREVIEW_OFICIO_INST.pdf"');
        echo $dompdf->output();
        exit;
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