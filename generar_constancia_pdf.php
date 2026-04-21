<?php
session_start();
require_once __DIR__.'/db_config.php';
require_once __DIR__.'/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;

// Configuración de zona horaria y codificación para El Salvador
date_default_timezone_set('America/El_Salvador');
setlocale(LC_TIME, 'es_ES.UTF-8', 'es_SV', 'spanish');

// Validación de seguridad CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) exit;

/* ================= FUNCIONES DE APOYO ================= */

/**
 * Obtiene el nombre oficial del documento desde la BD y construye la frase del hospital
 */
function obtenerSoporteOficial($pdo, $slug, $hospitalNombre = '') {
    $stmt = $pdo->prepare("SELECT nombre_oficial, requiere_hospital FROM catalogo_soportes WHERE codigo_slug = ?");
    $stmt->execute([$slug]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$res) return "documento de respaldo";
    
    $nombre_base = $res['nombre_oficial'];

    // Si requiere hospital y se proporcionó uno, unimos las piezas con tildes correctas
    if ($res['requiere_hospital'] == 1 && !empty($hospitalNombre)) {
        return $nombre_base . " emitido por <strong>" . mb_strtoupper($hospitalNombre, 'UTF-8') . "</strong>";
    }
    
    return $nombre_base;
}

function to_b64($p) { 
    if (!file_exists($p)) return '';
    $type = pathinfo($p, PATHINFO_EXTENSION);
    return 'data:image/' . $type . ';base64,' . base64_encode(file_get_contents($p)); 
}


/* ================= DATOS GENERALES DEL SOLICITANTE ================= */
$tipo_constancia = $_POST['tipo_constancia_id'] ?? '';
$solicitante = mb_strtoupper(trim($_POST['nombre_solicitante'] ?? ''), 'UTF-8');
$dui_solicitante = trim($_POST['numero_documento'] ?? '');

// Capturar el nombre del tipo de documento (DUI, Pasaporte, etc.)
$tipo_doc_nombre = "DOCUMENTO"; 
if (!empty($_POST['tipo_documento_id'])) {
    $stmt_td = $pdo->prepare("SELECT nombre FROM tipos_documento WHERE id = ?");
    $stmt_td->execute([$_POST['tipo_documento_id']]);
    $raw_td_nombre = $stmt_td->fetchColumn() ?: "DOCUMENTO";
    $tipo_doc_nombre = mb_strtoupper($raw_td_nombre, 'UTF-8');
}

$fecha_legal = date('d') . " de " . strftime('%B') . " del presente año";

// Variables iniciales para la plantilla
$parrafo_principal = "";
$leyenda_divorcio = "";
$titulo_constancia = "";
$descripcion_tramite = ""; // Se llenará dinámicamente según el caso
$persona_certificada = ""; // Se usará para el nombre del archivo final
$cargo_default = "DE SAN SALVADOR CENTRO";
$cargo_especifico = null;

/* ================= 1. NO REGISTRO NACIMIENTO (CON ASIENTOS) ================= */
if ($tipo_constancia === 'NO_REGISTRO_NAC') {
    $titulo_constancia = "CERTIFICACIÓN DE NO REGISTRO DE ASIENTO DE NACIMIENTO";
    $descripcion_tramite = "Certificación de no Registro de Asiento de Nacimiento";
    $persona_certificada = mb_strtoupper(trim($_POST['nac_nombre_no_registro'] ?? ''), 'UTF-8');
    
    $es_exterior = (($_POST['es_exterior'] ?? '0') === '1');

    // Variables de ubicación
    $municipio = trim((string)($_POST['nac_municipio_nombre'] ?? 'San Salvador Centro'));
    $distrito  = trim((string)($_POST['nac_distrito_nombre']  ?? 'San Salvador'));
    $depto      = trim((string)($_POST['nac_departamento_nombre'] ?? 'San Salvador')); 

    $hospital  = $_POST['nac_nombre_hospital'] ?? '';
    $madre     = mb_strtoupper(trim($_POST['nac_nombre_madre'] ?? ''), 'UTF-8');
    $padre     = mb_strtoupper(trim($_POST['nac_nombre_padre'] ?? ''), 'UTF-8');
    $madre_dui = mb_strtoupper(trim($_POST['nac_nombre_madre_dui'] ?? ''), 'UTF-8');
    
    // Obtener el soporte oficial (Tipo de documento + Hospital)
    $soporte_final = obtenerSoporteOficial($pdo, $_POST['nac_tipo_soporte'] ?? '', $hospital);

    // Formateo de Hora: "a las nueve horas y cincuenta minutos," o vacío
    $txt_hora = !empty($_POST['nac_hora_nacimiento']) 
        ? ", a las <strong>" . $_POST['nac_hora_nacimiento'] . "</strong>," 
        : ",";

    // Formateo de Filiación
    $txt_filiacion = "siendo hijo (a) de <strong>$madre</strong>";
    if (!empty($madre_dui)) { 
        $txt_filiacion .= " y según Documento Único de Identidad <strong>$madre_dui</strong>"; 
    }
    if (!empty($padre)) { 
        $txt_filiacion .= " y de <strong>$padre</strong>"; 
    }

    // Fecha de nacimiento
    $f_nac = !empty($_POST['nac_fecha_nacimiento']) 
        ? date('d/m/Y', strtotime($_POST['nac_fecha_nacimiento'])) 
        : '';

    // Lugar de nacimiento: exterior u ordinario
    if ($es_exterior) {
        $txt_lugar = "nació en <strong>Territorio Extranjero</strong>";
    } else {
        $txt_lugar = "nació en el Distrito de <strong>$distrito</strong>, Municipio de <strong>$municipio</strong>, Departamento de <strong>$depto</strong>";
    }

    // CONSTRUCCIÓN DEL PÁRRAFO FINAL
    $parrafo_principal = "<p class='content indent'>
        Habiéndose efectuado la búsqueda hasta el día <strong>$fecha_legal</strong>, en los registros de nuestra base de datos que corresponde únicamente al Distrito San Salvador, 
        <strong>NO aparece registrada ningún asiento de NACIMIENTO</strong> a nombre de: <strong>$persona_certificada</strong>, según $soporte_final, 
        {$txt_lugar}{$txt_hora} 
        el día <strong>$f_nac</strong>, {$txt_filiacion}.
    </p>";
}
/* ================= 2. NO REGISTRO DEFUNCIÓN ================= */
elseif ($tipo_constancia === 'NO_REGISTRO_DEF') {
    $titulo_constancia = "CERTIFICACIÓN DE NO REGISTRO DE ASIENTO DE DEFUNCIÓN";
    $descripcion_tramite = "Certificación de no Registro de Asiento de Defunción";
    
    $es_exterior = (($_POST['es_exterior'] ?? '0') === '1');

    $persona_certificada = mb_strtoupper(trim($_POST['def_nombre_no_registro'] ?? ''), 'UTF-8');
    $nombre_segun_doc = mb_strtoupper(trim($_POST['def_nombre_segun_doc'] ?? ''), 'UTF-8');
    $tipo_doc_segun_id = $_POST['def_tipo_doc_segun_id'] ?? '';

    $txt_nombre_extra = "";
    if (!empty($nombre_segun_doc)) {
        $nombre_doc_especifico = "DOCUMENTO";
        if (!empty($tipo_doc_segun_id)) {
            $st = $pdo->prepare("SELECT nombre FROM tipos_documento WHERE id = ?");
            $st->execute([$tipo_doc_segun_id]);
            $nombre_doc_especifico = mb_strtoupper($st->fetchColumn() ?: "DOCUMENTO", 'UTF-8');
        }
        $txt_nombre_extra = " y <strong>$nombre_segun_doc</strong> según $nombre_doc_especifico";
    }

    $hospital = $_POST['def_nombre_hospital'] ?? '';
    $madre = mb_strtoupper(trim($_POST['def_nombre_madre'] ?? ''), 'UTF-8');
    $padre = mb_strtoupper(trim($_POST['def_nombre_padre'] ?? ''), 'UTF-8');

    $txt_hora = !empty($_POST['def_hora_defuncion']) ? " a las <strong>" . $_POST['def_hora_defuncion'] . "</strong>," : "";

    /* --- CORRECCIÓN DE FILIACIÓN --- */
    $txt_filiacion = "";
    if (!empty($madre) && !empty($padre)) {
        $txt_filiacion = ", siendo hijo (a) de <strong>$madre</strong> y de <strong>$padre</strong>";
    } elseif (!empty($madre)) {
        $txt_filiacion = ", siendo hijo (a) de <strong>$madre</strong>";
    } elseif (!empty($padre)) {
        $txt_filiacion = ", siendo hijo (a) de <strong>$padre</strong>";
    }
    /* ------------------------------- */

    $soporte_final = obtenerSoporteOficial($pdo, $_POST['def_tipo_soporte'] ?? '', $hospital);
    $f_def = !empty($_POST['def_fecha_defuncion']) ? date('d/m/Y', strtotime($_POST['def_fecha_defuncion'])) : '';

    $municipio = trim((string)($_POST['def_municipio_nombre'] ?? 'San Salvador'));
    $distrito  = trim((string)($_POST['def_distrito_nombre']  ?? 'San Salvador'));
    $depto     = trim((string)($_POST['def_departamento_nombre'] ?? 'San Salvador'));

    // Lugar de fallecimiento: exterior u ordinario
    if ($es_exterior) {
        $txt_lugar_def = "falleció en <strong>Territorio Extranjero</strong>";
    } else {
        $txt_lugar_def = "falleció en Distrito de $distrito, Municipio de $municipio, Departamento de $depto,";
    }

    // Se quitó la coma manual antes de {$txt_filiacion} porque ahora el texto corregido ya la incluye si es necesario
    $parrafo_principal = "<p class='content indent'>Habiéndose efectuado la respectiva búsqueda en los registros de nuestra base de datos, que corresponde únicamente al Distrito San Salvador, hasta el día <strong>$fecha_legal</strong>, <strong>NO aparece registrada ningún ASIENTO DE DEFUNCIÓN</strong> a nombre de: <strong>$persona_certificada</strong>, según $soporte_final{$txt_nombre_extra}, {$txt_lugar_def}{$txt_hora} el día <strong>$f_def</strong>{$txt_filiacion}.</p>";
}

/* ================= 3. SOLTERÍA / ESTADO FAMILIAR (CORREGIDO) ================= */
elseif ($tipo_constancia === 'SOLTERIA' || $tipo_constancia === 'SOLTERIA_DIV') {
    $titulo_constancia = ($tipo_constancia === 'SOLTERIA_DIV') ? "CERTIFICACIÓN DE ESTADO FAMILIAR" : "CERTIFICACIÓN DE NO REGISTRO DE MATRIMONIO";
    $descripcion_tramite = ($tipo_constancia === 'SOLTERIA_DIV') ? "Certificación de Estado Familiar" : "Certificación de no Registro de Matrimonio";
    
    $persona_certificada = mb_strtoupper(trim($_POST['sol_div_nombre_inscrito'] ?? ''), 'UTF-8');
    
    // CAPTURA CON LOS NOMBRES EXACTOS DE TU FORMULARIO:
    $partida = $_POST['sol_div_numero_partida'] ?? '';
    $folio   = $_POST['sol_div_folio'] ?? '';
    $libro   = $_POST['sol_div_libro'] ?? '';
    $anio    = $_POST['sol_div_anio'] ?? '';

    if ($tipo_constancia === 'SOLTERIA_DIV') {
        // Párrafo 2 para Soltería por Divorcio
        $parrafo_principal = "<p class='content indent'>
            Habiéndose efectuado la respectiva búsqueda en los registros de nuestra base de datos que corresponde únicamente al Distrito de San Salvador, hasta el día <strong>$fecha_legal</strong>. Se encontró Asiento de Nacimiento marginada por <strong>MATRIMONIO y DIVORCIO</strong> a nombre de: <strong>$persona_certificada</strong>, inscrita con el número <strong>$partida</strong>, folio <strong>$folio</strong>, del Libro <strong>$libro</strong>, del año <strong>$anio</strong>, del Registro del Estado Familiar del Distrito de San Salvador, San Salvador Centro.
        </p>";
        
        $leyenda_divorcio = "<p class='content'><strong>DE CONFORMIDAD</strong> al Decreto Legislativo número 605 que reforma al artículo 186 del Código de Familia, referente al estado familiar de una persona, se expresa en su ordinal tercero que es “soltera o soltero, quien no ha contraído matrimonio o cuyo matrimonio ha sido anulado o disuelto por divorcio” disposición que entró en vigencia el 1 de marzo del año 2017. <strong>POR TANTO:</strong> El Estado Familiar del(la) inscrito(a) es <strong>“SOLTERO(A)”</strong>.</p>";
    } else {
        // Párrafo 2 para Soltería Simple
        $parrafo_principal = "<p class='content indent'>
            Habiéndose efectuado la respectiva búsqueda en los registros de nuestra base de datos que corresponde únicamente al Distrito de San Salvador, hasta el día <strong>$fecha_legal</strong>. <strong>NO aparece registrada ningún asiento de MATRIMONIO ni MARGINACIÓN</strong> inscrita en el asiento de <strong>NACIMIENTO</strong> a nombre de: <strong>$persona_certificada</strong>, según asiento de nacimiento número <strong>$partida</strong>, folio <strong>$folio</strong>, del libro <strong>$libro</strong>, del año <strong>$anio</strong> del Registro del Estado Familiar de este Distrito.
        </p>";
        $leyenda_divorcio = "";
    }
}
/* ================= 4. NO REGISTRO CÉDULA (TEXTO ORIGINAL) ================= */
elseif ($tipo_constancia === 'NO_REGISTRO_CED') {
    $titulo_constancia = "CERTIFICACIÓN NO REGISTRO DE CÉDULA DE IDENTIDAD PERSONAL";
    $descripcion_tramite = "Certificación de no Registro de Cédula de Identidad Personal";
    $persona_certificada = mb_strtoupper(trim($_POST['ced_nombre_no_registro'] ?? ''), 'UTF-8');

    $parrafo_principal = "<p class='content indent'>Habiéndose efectuado la respectiva búsqueda de los registros de nuestra base de datos y archivos que corresponden únicamente al Distrito de San Salvador, en fecha comprendida a partir del 28 de agosto de 1978 hasta el día 31 de octubre de 2002, período en que se extendió la Cédula de Identidad Personal. <strong>NO se ha encontrado registro</strong> de Cédula de Identidad Personal a nombre de: <strong>$persona_certificada</strong>.</p>";
}


/* ================= 5. NO REGISTRO MATRIMONIO (ACTUALIZADO CON 2DO CONTRAYENTE) ================= */
elseif ($tipo_constancia === 'NO_REGISTRO_MAT') {
    $titulo_constancia = "CERTIFICACIÓN DE NO REGISTRO DE ASIENTO DE MATRIMONIO";
    $descripcion_tramite = "Certificación de no Registro de Asiento de Matrimonio";
    
    // Contrayente 1 (Principal)
    $persona_certificada = mb_strtoupper(trim($_POST['mat_nombre_no_registro'] ?? ''), 'UTF-8');
    
    // Contrayente 2 (Opcional) - Sin pasar a mayúsculas forzosamente, solo limpieza UTF-8
    $contrayente_dos = trim((string)($_POST['mat_nombre_contrayente_dos'] ?? ''));
    
    $cargo_especifico = "DE SAN SALVADOR CENTRO";

    // Lógica de redacción: Si existe el segundo contrayente, se añade con "Y"
    if (!empty($contrayente_dos)) {
        // Pasamos a mayúsculas para mantener la estética de los nombres en estas constancias
        $nombre_dos_upper = mb_strtoupper($contrayente_dos, 'UTF-8');
        $texto_nombres = "<strong>$persona_certificada</strong> y <strong>$nombre_dos_upper</strong>";
        
        // Actualizamos la variable para el nombre del archivo PDF
        $persona_certificada .= "_Y_" . preg_replace('/[^A-Za-z0-9\_]/', '', str_replace(' ', '_', $nombre_dos_upper));
    } else {
        $texto_nombres = "<strong>$persona_certificada</strong>";
    }

    // Párrafo con la redacción ajustada
    $parrafo_principal = "<p class='content indent'>
        Habiéndose efectuado la respectiva búsqueda en los registros de nuestra base de datos que corresponde únicamente al Distrito de San Salvador, hasta el día <strong>$fecha_legal</strong>. <strong>NO aparece registrada ningún Asiento de MATRIMONIO</strong> a nombre de: $texto_nombres.
    </p>";
}

/* ================= RENDERIZADO Y GUARDADO ================= */
$background_style = "background-image: url('".to_b64(__DIR__.'/img/fondo_oficio.png')."'); background-size: 100% 100%;";
$qr = Builder::create()->data("https://validar.gob.sv/".hash('sha256', (string)random_bytes(8)))->size(110)->build();

$html_template = file_get_contents(__DIR__.'/templates/plantilla_constancia.html');

// Definimos la leyenda de validez
$leyenda_validez = "Nota: La presente certificación es válida por 120 días después de su fecha de emisión.";

$tags = [
    '{{background_style}}' => $background_style,
    '{{titulo_constancia}}' => $titulo_constancia,
    '{{nombre_solicitante}}' => $solicitante,
    '{{tipo_documento_nombre}}' => $tipo_doc_nombre,
    '{{numero_documento}}' => $dui_solicitante,
    '{{descripcion_tramite}}' => $descripcion_tramite,
    '{{parrafo_principal_texto}}' => $parrafo_principal,
    '{{leyenda_divorcio}}' => $leyenda_divorcio,
    '{{fecha_emision_texto}}' => date('d') . " de " . strftime('%B del año %Y'),
    // Ajustamos el estilo de la firma aquí para que sea el contenedor el que mande
    '{{imagen_firma}}' => '<img src="'.to_b64(__DIR__.'/img/firmablanco.png').'" style="width:380px; display:block; margin: 0 auto;">',
    '{{codigo_qr}}' => '<img src="'.$qr->getDataUri().'" style="width:100px;">',
    '{{escudo_img}}' => '<img src="'.to_b64(__DIR__.'/img/img_logo.png').'" style="width:300px;">',
    '{{cargo_pie_firma}}' => mb_strtoupper((string)($cargo_especifico ?? $cargo_default), 'UTF-8'),
    '{{leyenda_validez}}' => $leyenda_validez
];

$output = str_replace(array_keys($tags), array_values($tags), $html_template);

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans'); 
$dompdf = new Dompdf($options);
$dompdf->loadHtml($output);
$dompdf->setPaper('Letter', 'portrait');
$dompdf->render();

/* El resto del código de guardado permanece igual */
/* ================= MANEJO ÚNICO DE ARCHIVOS PARA CERTIFICACIONES ================= */
// Al final del archivo:
$num_oficio = $_POST['numero_oficio_generado'] ?? date('YmdHis'); // Usa el enviado o un timestamp si falla

// CORRECCIÓN Ñ: Reemplazamos Ñ por N y eliminamos tildes para el nombre del archivo
$search  = array('Ñ', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'á', 'é', 'í', 'ó', 'ú');
$replace = array('N', 'n', 'A', 'E', 'I', 'O', 'U', 'a', 'e', 'i', 'o', 'u');
$persona_limpia = str_replace($search, $replace, $persona_certificada);

$persona_slug = preg_replace('/[^A-Za-z0-9\_]/', '', str_replace(' ', '_', $persona_limpia));

// Construye el nombre exacto
$nombre_final_pdf = "CERT_" . $tipo_constancia . "_" . $persona_slug . "_" . $num_oficio . ".pdf";
$ruta_completa = __DIR__ . "/archivos_finales/" . $nombre_final_pdf;

// Guarda y sobrescribe el archivo físico
file_put_contents($ruta_completa, $dompdf->output());

// No olvides el header para visualización
header("Content-Type: application/pdf");
echo $dompdf->output();
exit;