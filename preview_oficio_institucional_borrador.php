<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/helpers/oficio_institucional_html.php';

use Dompdf\Dompdf;
use Dompdf\Options;

ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/php_errors.log');
error_reporting(E_ALL);
date_default_timezone_set('America/El_Salvador');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Método no permitido.");
}

function obtenerArrayPost(string $key): array {
    $value = $_POST[$key] ?? [];
    return is_array($value) ? $value : [];
}

function tieneContenido(array $arr): bool {
    foreach ($arr as $v) {
        if (trim((string)$v) !== '') {
            return true;
        }
    }
    return false;
}

try {
    // Construir “registro” falso para el borrador
    $reg = [
        'referencia_salida'   => trim((string)($_POST['referencia_salida'] ?? 'BORRADOR')),
        'nombre_institucion'  => trim((string)($_POST['nombre_institucion'] ?? '')),
        'unidad_dependencia'  => trim((string)($_POST['unidad_dependencia'] ?? '')),
        'ubicacion_sede'      => trim((string)($_POST['ubicacion_sede'] ?? '')),
        'fecha_documento'     => trim((string)($_POST['fecha_documento'] ?? date('Y-m-d'))),
        'fecha'               => trim((string)($_POST['fecha'] ?? date('Y-m-d'))),
    ];

    // Entradas del formulario
    $nums    = obtenerArrayPost('num_oficio_in');
    $refs    = obtenerArrayPost('ref_expediente_in');
    $fechas  = obtenerArrayPost('fecha_doc_in');
    $tipos   = obtenerArrayPost('tipo_partida_solicitada');
    $nombres = obtenerArrayPost('nombre_solicitado');

    $countEntradas = max(count($nums), count($refs), count($fechas), count($tipos), count($nombres));
    $entradas = [];

    for ($i = 0; $i < $countEntradas; $i++) {
        $entradas[] = [
            'num_oficio_in' => $nums[$i] ?? '',
            'ref_expediente_in' => $refs[$i] ?? '',
            'fecha_doc_in' => $fechas[$i] ?? '',
            'tipo_partida_solicitada' => $tipos[$i] ?? '',
            'nombre_solicitado' => $nombres[$i] ?? '',
        ];
    }

    // Detalles del formulario
    $nombres_cons = obtenerArrayPost('nombre_consultado');
    $tipos_tramite = obtenerArrayPost('tipo_tramite');
    $resultados = obtenerArrayPost('resultado');
    $obs = obtenerArrayPost('observaciones');
    $fil1 = obtenerArrayPost('padre_conyuge_1');
    $fil2 = obtenerArrayPost('padre_conyuge_2');
    $partidas = obtenerArrayPost('partida_numero');
    $folios = obtenerArrayPost('partida_folio');
    $libros = obtenerArrayPost('partida_libro');
    $anios = obtenerArrayPost('partida_anio');

    $countDetalles = max(
        count($nombres_cons),
        count($tipos_tramite),
        count($resultados),
        count($obs),
        count($fil1),
        count($fil2),
        count($partidas),
        count($folios),
        count($libros),
        count($anios)
    );

    $detalles = [];
    for ($i = 0; $i < $countDetalles; $i++) {
        $detalles[] = [
            'nombre_consultado' => $nombres_cons[$i] ?? '',
            'tipo_tramite'      => $tipos_tramite[$i] ?? '',
            'resultado'         => $resultados[$i] ?? 'NO_ENCONTRADO',
            'observaciones'     => $obs[$i] ?? '',
            'filiacion_1'       => $fil1[$i] ?? '',
            'filiacion_2'       => $fil2[$i] ?? '',
            'partida_numero'    => $partidas[$i] ?? '',
            'partida_folio'     => $folios[$i] ?? '',
            'partida_libro'     => $libros[$i] ?? '',
            'partida_anio'      => $anios[$i] ?? '',
        ];
    }

    // Validación mínima para evitar preview vacío
    $hayEntradas = tieneContenido($nums) || tieneContenido($refs) || tieneContenido($fechas) || tieneContenido($tipos) || tieneContenido($nombres);
    $hayDetalles = tieneContenido($nombres_cons) || tieneContenido($tipos_tramite) || tieneContenido($resultados) || tieneContenido($obs) || tieneContenido($partidas) || tieneContenido($folios) || tieneContenido($libros) || tieneContenido($anios);

    if (!$hayEntradas && !$hayDetalles) {
        die("No hay datos suficientes para generar la vista previa.");
    }

    $logoPath = __DIR__ . '/img/img_logo.png';
    $firmaPath = __DIR__ . '/img/firma.png';

    $imgLogo = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : '';
    $imgFirma = file_exists($firmaPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($firmaPath)) : '';

    $html = construirHtmlOficioInst($reg, $entradas, $detalles, [
        'preview' => true,
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

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="PREVIEW_BORRADOR_OFICIO_INST.pdf"');
    echo $dompdf->output();
    exit;

} catch (Throwable $e) {
    die("Error al generar el borrador: " . $e->getMessage());
}