<?php
declare(strict_types=1);

/**
 * Helper compartido: construye el HTML del oficio institucional para generación PDF.
 *
 * @param array $reg      Fila de oficios_institucionales + datos de instituciones (JOIN).
 * @param array $entradas Filas de oficios_institucionales_entradas.
 * @param array $detalles Filas de oficios_institucionales_detalle.
 * @param array $opts     Opciones opcionales:
 *   - 'preview'      bool   Si true añade aviso visual de borrador. Default false.
 *   - 'img_logo'     string URI base64 del logo. Si vacío se carga desde img/.
 *   - 'img_firma'    string URI base64 de la firma. Si vacío se carga desde img/.
 *   - 'mostrar_firma' bool  Si false omite la imagen de firma. Default true.
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
    $fechaDoc = $reg['fecha_documento'] ?? ($reg['fecha'] ?? null);
    $fechaLetras = _fechaEnLetrasInst(is_string($fechaDoc) ? $fechaDoc : null);

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
        .bold{font-weight:bold;}
        .upper{text-transform:uppercase;}
        .parrafo{text-indent:25px;margin-bottom:10px;text-align:justify;}
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

    /* encabezado institucional */
    $html .= '<div class="inst-header">REGISTRO DEL ESTADO FAMILIAR<br>DISTRITO SAN SALVADOR SEDE</div>';
    $html .= '<div class="bold">A QUIEN CORRESPONDA:</div>';
    $html .= '<div class="bold" style="margin-top:15px;">OFICIO No. ' . _eInst($reg['referencia_salida'] ?? 'S/N') . '</div>';

    /* párrafo de introducción: agrupa por clave de oficio de entrada */
    $html .= '<div class="parrafo" style="margin-top:15px;">';
    if (!empty($entradas)) {
        $agrupados = [];
        foreach ($entradas as $ent) {
            $key = trim((string)$ent['num_oficio_in'])
                 . '|' . trim((string)($ent['ref_expediente_in'] ?? ''))
                 . '|' . trim((string)($ent['fecha_doc_in'] ?? ''));
            if (!isset($agrupados[$key])) {
                $agrupados[$key] = [
                    'num'  => $ent['num_oficio_in'],
                    'ref'  => $ent['ref_expediente_in'] ?? 'S/N',
                    'fec'  => $ent['fecha_doc_in'] ?? null,
                    'inst' => $reg['nombre_institucion'] ?? '',
                    'pets' => [],
                ];
            }
            $agrupados[$key]['pets'][] =
                'CERTIFICACIÓN DE PARTIDA DE ' . _eInst($ent['tipo_partida_solicitada'])
                . ' a nombre de: <span class="bold upper">' . _eInst($ent['nombre_solicitado']) . '</span>';
        }

        $partes = [];
        foreach ($agrupados as $g) {
            $t = 'Oficio número <span class="bold">' . _eInst($g['num']) . '</span>';
            if (!empty($g['ref']) && $g['ref'] !== 'S/N') {
                $t .= ', con Referencia <span class="bold">' . _eInst($g['ref']) . '</span>';
            }
            if (!empty($g['inst'])) {
                $t .= ' de <span class="bold">' . _eInst($g['inst']) . '</span>';
            }
            $t .= ', de fecha <span class="bold">' . _fechaEnLetrasInst($g['fec']) . '</span>';
            $t .= ', en el cual solicita se remita ' . implode(', ', $g['pets']);
            $partes[] = $t;
        }

        $html .= 'En atención a su(s) ' . implode('; y ', $partes) . '.';
    } else {
        $html .= 'En atención a su solicitud institucional, se procede a informar lo siguiente.';
    }
    $html .= '</div>';

    $html .= '<div class="parrafo">Habiéndose efectuado la respectiva búsqueda en los registros de nuestra base de datos '
           . 'que corresponde únicamente al Distrito de San Salvador Sede, se informa lo siguiente:</div>';

    /* resultados */
    foreach ($detalles as $det) {
        $html .= '<div class="parrafo">';
        $resultado   = strtoupper((string)($det['resultado'] ?? 'NO_ENCONTRADO'));
        $tipoLower   = strtolower((string)($det['tipo_tramite'] ?? 'nacimiento'));

        if ($resultado === 'ENCONTRADO') {
            $html .= '<span class="bold upper">SE ENCONTRÓ</span> registro de '
                   . _eInst($tipoLower) . ' a nombre de '
                   . '<span class="bold upper">' . _eInst($det['nombre_consultado']) . '</span>'
                   . ', asentada bajo el número <span class="bold">' . _eInst($det['partida_numero'] ?? '') . '</span>, '
                   . 'folio <span class="bold">' . _eInst($det['partida_folio'] ?? '') . '</span>, '
                   . 'libro <span class="bold">' . _eInst($det['partida_libro'] ?? '') . '</span> '
                   . 'del año <span class="bold">' . _eInst($det['partida_anio'] ?? '') . '</span>.';
        } else {
            $html .= '<span class="bold upper">NO SE ENCONTRÓ</span> registro de '
                   . _eInst($tipoLower) . ' a nombre de '
                   . '<span class="bold upper">' . _eInst($det['nombre_consultado']) . '</span>'
                   . ', no encontrándose registro alguno en los libros cronológicos y auxiliares de este registro.';
        }

        if (!empty($det['observaciones'])) {
            $html .= ' <em>' . _eInst($det['observaciones']) . '</em>';
        }
        $html .= '</div>';
    }

    /* párrafo de cierre */
    $html .= '<div class="parrafo" style="margin-top:20px;">';
    $html .= 'Se extiende la presente en Distrito de San Salvador Sede, San Salvador Centro, San Salvador, '
           . 'el día <span class="bold">' . $fechaLetras . '</span>. '
           . 'Se advierte que este Registro del Estado Familiar no es responsable por la inexactitud o falsedad '
           . 'de los datos proporcionados en la presente. '
           . '<span class="bold upper">CUALQUIER ALTERACIÓN ANULA EL PRESENTE DOCUMENTO.</span>';
    $html .= '</div>';

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

function _fechaEnLetrasInst(?string $fecha): string
{
    if ($fecha === null || $fecha === '') {
        return '---';
    }
    static $meses = [
        'enero','febrero','marzo','abril','mayo','junio',
        'julio','agosto','septiembre','octubre','noviembre','diciembre',
    ];
    $ts = strtotime($fecha);
    if ($ts === false) {
        return '---';
    }
    return date('d', $ts) . ' de ' . $meses[(int)date('n', $ts) - 1] . ' del ' . date('Y', $ts);
}
