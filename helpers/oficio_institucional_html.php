<?php
declare(strict_types=1);

/**
 * Helper compartido: construye el HTML del oficio institucional para generación PDF.
 *
 * Soporta tres casos:
 *   Caso 1 – un oficio de entrada con una sola petición.
 *   Caso 2 – un oficio de entrada con múltiples peticiones.
 *   Caso 3 – múltiples oficios de entrada, cada uno con una o más peticiones.
 *
 * @param array $reg      Fila de oficios_institucionales + datos de instituciones (JOIN).
 * @param array $entradas Filas de oficios_institucionales_entradas.
 * @param array $detalles Filas de oficios_institucionales_detalle.
 * @param array $opts     Opciones opcionales:
 *   - 'preview'       bool   Si true añade aviso visual de borrador. Default false.
 *   - 'img_logo'      string URI base64 del logo. Si vacío se carga desde img/.
 *   - 'img_firma'     string URI base64 de la firma. Si vacío se carga desde img/.
 *   - 'mostrar_firma' bool   Si false omite la imagen de firma. Default true.
 * @return string HTML listo para pasar a Dompdf.
 */
function construirHtmlOficioInst(array $reg, array $entradas, array $detalles, array $opts = []): string
{
    $preview      = (bool)($opts['preview'] ?? false);
    $mostrarFirma = (bool)($opts['mostrar_firma'] ?? true);

    /* --- imágenes --- */
    $imgLogo  = $opts['img_logo'] ?? '';
    $imgFirma = $opts['img_firma'] ?? '';

    $imgDir = __DIR__ . '/../img/';

    if ($imgLogo === '' && file_exists($imgDir . 'img_logo.png')) {
        $imgLogo = 'data:image/png;base64,' . base64_encode((string)file_get_contents($imgDir . 'img_logo.png'));
    }

    if ($imgFirma === '' && $mostrarFirma && file_exists($imgDir . 'firma.png')) {
        $imgFirma = 'data:image/png;base64,' . base64_encode((string)file_get_contents($imgDir . 'firma.png'));
    }

    /* --- fondo --- */
    $bgStyle = '';
    if (file_exists($imgDir . 'fondo_oficio.png')) {
        $bgStyle = "background-image:url('data:image/png;base64,"
            . base64_encode((string)file_get_contents($imgDir . 'fondo_oficio.png'))
            . "');background-size:100% 100%;background-repeat:no-repeat;";
    }

    /* --- fecha del documento en letras --- */
    $fechaDoc    = $reg['fecha_documento'] ?? ($reg['fecha'] ?? null);
    $fechaLetras = _fechaEnLetrasInst(is_string($fechaDoc) ? $fechaDoc : null);

    /* --- agrupar entradas por oficio (num|ref|fecha) --- */
    $agrupados = [];
    foreach ($entradas as $ent) {
        $key = trim((string)$ent['num_oficio_in'])
             . '|' . trim((string)($ent['ref_expediente_in'] ?? ''))
             . '|' . trim((string)($ent['fecha_doc_in'] ?? ''));
        if (!isset($agrupados[$key])) {
            $agrupados[$key] = [
                'num'  => $ent['num_oficio_in'],
                'ref'  => (!empty($ent['ref_expediente_in']) ? $ent['ref_expediente_in'] : 'S/N'),
                'fec'  => $ent['fecha_doc_in'] ?? null,
                'pets' => [],
            ];
        }
        $agrupados[$key]['pets'][] = [
            'tipo'   => $ent['tipo_partida_solicitada'],
            'nombre' => $ent['nombre_solicitado'],
        ];
    }

    /* Casos 1 y 2 → un solo grupo → "su Oficio número:"
       Caso 3       → varios grupos → "sus Oficios números:" */
    $esSingular = (count($agrupados) <= 1);

    /* --- CSS --- */
    $watermarkCss = $preview
        ? '.watermark-notice{color:#cc0000;font-weight:bold;text-align:center;font-size:14pt;'
          . 'border:2px solid #cc0000;padding:5px 10px;margin-bottom:15px;}'
        : '';

    $css = "
        @page{size:letter portrait;margin:0;}
        html,body{width:100%;height:100%;margin:0;padding:0;}
        body{font-family:Arial,sans-serif;font-size:11pt;line-height:1.5;color:#000;{$bgStyle}}
        .page-content{padding:60px 75px 30px 75px;box-sizing:border-box;}
        .header{text-align:center;margin-bottom:10px;}
        .header img{width:80px;display:block;margin:0 auto;}
        .inst-header{text-align:center;font-weight:bold;font-size:12pt;
            text-transform:uppercase;margin-bottom:18px;line-height:1.3;}
        .oficio-ref{font-weight:bold;margin-top:15px;margin-bottom:12px;}
        .inst-dest{line-height:1.4;margin:0;padding:0;}
        .presente{margin-bottom:15px;}
        .intro-line{margin-bottom:4px;}
        .bullet-item{margin:4px 0 4px 22px;text-align:justify;}
        .habiendose{margin-top:10px;margin-bottom:4px;text-align:justify;}
        .parrafo{margin-top:15px;margin-bottom:10px;text-align:justify;}
        .bold{font-weight:bold;}
        .upper{text-transform:uppercase;}
        .sig-block{margin-top:50px;text-align:center;}
        .sig-img{width:280px;margin-bottom:-40px;display:block;margin-left:auto;margin-right:auto;}
        {$watermarkCss}
    ";

    $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    $html .= '<style>' . $css . '</style></head><body>';
    $html .= '<div class="page-content">';

    if ($preview) {
        $html .= '<div class="watermark-notice">⚠ BORRADOR – VISTA PREVIA – NO VÁLIDO COMO DOCUMENTO OFICIAL ⚠</div>';
    }

    /* logo */
    if ($imgLogo !== '') {
        $html .= '<div class="header"><img src="' . $imgLogo . '" alt="Logo"></div>';
    }

    /* encabezado de la oficina */
    $html .= '<div class="inst-header">REGISTRO DEL ESTADO FAMILIAR<br>DISTRITO SAN SALVADOR SEDE</div>';

    /* número de oficio */
    $html .= '<div class="oficio-ref">OFICIO No. ' . _eInst($reg['referencia_salida'] ?? 'S/N') . '</div>';

    /* bloque destinatario */
    $html .= '<div class="inst-dest bold upper">' . _eInst($reg['nombre_institucion'] ?? '') . '</div>';
    if (!empty($reg['unidad_dependencia'])) {
        $html .= '<div class="inst-dest">' . _eInst($reg['unidad_dependencia']) . '</div>';
    }
    if (!empty($reg['ubicacion_sede'])) {
        $html .= '<div class="inst-dest">' . _eInst($reg['ubicacion_sede']) . '</div>';
    }
    $html .= '<div class="inst-dest presente">Presente.</div>';

    /* párrafo de introducción con viñetas por oficio de entrada */
    if (!empty($agrupados)) {
        $introLabel = $esSingular
            ? 'En atención a su Oficio número:'
            : 'En atención a sus Oficios números:';
        $html .= '<div class="intro-line">' . $introLabel . '</div>';

        foreach ($agrupados as $g) {
            $certs = [];
            foreach ($g['pets'] as $pet) {
                $certs[] = 'CERTIFICACIÓN DE PARTIDA DE ' . _eInst(_tipoPartidaLabel($pet['tipo']))
                         . ' a nombre de: <span class="bold upper">' . _eInst($pet['nombre']) . '</span>';
            }
            $certStr = implode(', ', $certs);
            $html .= '<div class="bullet-item">&#8226;&nbsp;'
                   . _eInst($g['num']) . ', con Referencia ' . _eInst($g['ref'])
                   . ', de fecha ' . _fechaEnLetrasInst($g['fec'])
                   . ', en el cual solicita se remita ' . $certStr . '.'
                   . '</div>';
        }
    } else {
        $html .= '<div class="intro-line">En atención a su solicitud institucional, se procede a informar lo siguiente.</div>';
    }

    /* párrafo "Habiéndose efectuado…" */
    $html .= '<div class="habiendose">Habiéndose efectuado la respectiva búsqueda en los registros de nuestra base de datos '
           . 'que corresponde únicamente al Distrito de San Salvador Sede,</div>';

    /* resultados como viñetas */
    foreach ($detalles as $det) {
        $resultado = strtoupper((string)($det['resultado'] ?? 'NO_ENCONTRADO'));
        $tipoLower = strtolower(_tipoPartidaLabel((string)($det['tipo_tramite'] ?? 'NACIMIENTO')));

        if ($resultado === 'ENCONTRADO') {
            $linea = '<span class="bold upper">SE ENCONTRÓ</span> registro de '
                   . _eInst($tipoLower) . ' a nombre de: '
                   . '<span class="bold upper">' . _eInst($det['nombre_consultado']) . '</span>'
                   . ', asentada bajo el número <span class="bold">' . _eInst($det['partida_numero'] ?? '') . '</span>'
                   . ', folio <span class="bold">' . _eInst($det['partida_folio'] ?? '') . '</span>'
                   . ', libro <span class="bold">' . _eInst($det['partida_libro'] ?? '') . '</span>'
                   . ' del año <span class="bold">' . _eInst($det['partida_anio'] ?? '') . '</span>.';
        } else {
            $linea = '<span class="bold upper">NO SE ENCONTRÓ</span> registro de '
                   . _eInst($tipoLower) . ' a nombre de '
                   . '<span class="bold upper">' . _eInst($det['nombre_consultado']) . '</span>.';
        }

        if (!empty($det['observaciones'])) {
            $linea .= ' <em>' . _eInst($det['observaciones']) . '</em>';
        }

        $html .= '<div class="bullet-item">&#8226;&nbsp;' . $linea . '</div>';
    }

    /* párrafo de cierre */
    $html .= '<div class="parrafo">'
           . 'Se extiende la presente en Distrito de San Salvador Sede, San Salvador Centro, San Salvador, '
           . 'el día <span class="bold">' . $fechaLetras . '</span>. '
           . 'Se advierte que este Registro del Estado Familiar no es responsable por la inexactitud o falsedad '
           . 'de los datos proporcionados en la presente. '
           . '<span class="bold upper">CUALQUIER ALTERACIÓN ANULA EL PRESENTE DOCUMENTO.</span>'
           . '</div>';

    /* firma */
    $html .= '<div class="sig-block">';
    if ($mostrarFirma && $imgFirma !== '') {
        $html .= '<img src="' . $imgFirma . '" class="sig-img" alt="Firma"><br>';
    }
    $html .= '<span class="bold upper">Licda. Karla Mariela Olivares Martínez</span><br>';
    $html .= 'REGISTRADORA DEL ESTADO FAMILIAR<br>DE SAN SALVADOR CENTRO';
    $html .= '</div>';

    $html .= '</div></body></html>';

    return $html;
}

/* ── helpers internos ─────────────────────────────────────────────────────── */

function _eInst($t): string
{
    return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
}

/**
 * Devuelve el nombre correcto (con tildes) del tipo de partida.
 */
function _tipoPartidaLabel(string $tipo): string
{
    static $map = [
        'DEFUNCION' => 'DEFUNCIÓN',
        'CEDULA'    => 'CÉDULA',
        'CARNET'    => 'CARNET MINORIDAD',
    ];
    $upper = strtoupper(trim($tipo));
    return $map[$upper] ?? $upper;
}

/**
 * Convierte un entero a su representación en letras en español.
 * Cubre días (1-31), años (1000-9999) y cualquier valor razonable.
 */
function _numEnLetrasEs(int $n): string
{
    static $unidades = [
        '', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
        'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete',
        'dieciocho', 'diecinueve', 'veinte', 'veintiuno', 'veintidós', 'veintitrés',
        'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve',
    ];
    static $decenas = [
        '', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta',
        'sesenta', 'setenta', 'ochenta', 'noventa',
    ];
    static $centenas = [
        '', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos',
        'seiscientos', 'setecientos', 'ochocientos', 'novecientos',
    ];

    if ($n < 0) {
        return 'menos ' . _numEnLetrasEs(-$n);
    }
    if ($n === 0) {
        return 'cero';
    }
    if ($n < 30) {
        return $unidades[$n];
    }
    if ($n === 100) {
        return 'cien';
    }
    if ($n < 100) {
        $d = intdiv($n, 10);
        $u = $n % 10;
        return $u === 0 ? $decenas[$d] : $decenas[$d] . ' y ' . $unidades[$u];
    }
    if ($n < 1000) {
        $c = intdiv($n, 100);
        $r = $n % 100;
        return $r === 0 ? $centenas[$c] : $centenas[$c] . ' ' . _numEnLetrasEs($r);
    }
    if ($n < 2000) {
        $r = $n % 1000;
        return $r === 0 ? 'mil' : 'mil ' . _numEnLetrasEs($r);
    }
    /* 2000 en adelante */
    $miles  = intdiv($n, 1000);
    $r      = $n % 1000;
    $prefix = _numEnLetrasEs($miles) . ' mil';
    return $r === 0 ? $prefix : $prefix . ' ' . _numEnLetrasEs($r);
}

/**
 * Devuelve la fecha en letras: "veinticinco de marzo del año dos mil veintiséis".
 */
function _fechaEnLetrasInst(?string $fecha): string
{
    if ($fecha === null || $fecha === '') {
        return '---';
    }
    static $meses = [
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];
    $ts = strtotime($fecha);
    if ($ts === false) {
        return '---';
    }
    $dia  = _numEnLetrasEs((int)date('j', $ts));
    $mes  = $meses[(int)date('n', $ts) - 1];
    $anio = _numEnLetrasEs((int)date('Y', $ts));
    return $dia . ' de ' . $mes . ' del año ' . $anio;
}
