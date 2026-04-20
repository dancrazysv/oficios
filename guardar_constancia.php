<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db_config.php';

$creado_por_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

if ($creado_por_id <= 0) {
    header('Content-Type: application/json');
    die(json_encode(['success' => false, 'msg' => 'Error: Sesión expirada']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    header('Content-Type: application/json');
    die(json_encode(['success' => false, 'msg' => 'Error de seguridad: Token inválido']));
}

try {
    $pdo->beginTransaction();

    $tipo_constancia = $_POST['tipo_constancia_id'] ?? '';
    
    // CORRECCIÓN Ñ: Usamos mb_strtoupper con UTF-8
    $nombre_solicitante = mb_strtoupper(trim($_POST['nombre_solicitante'] ?? ''), 'UTF-8');
    
    $tipo_doc_id = !empty($_POST['tipo_documento_id']) ? $_POST['tipo_documento_id'] : null;
    $num_doc = trim($_POST['numero_documento'] ?? '');
    
    // 1. Lógica de Prefijo
    $prefijo = "CERT";
    if ($tipo_constancia === 'NO_REGISTRO_NAC') $prefijo = "NAC";
    elseif ($tipo_constancia === 'NO_REGISTRO_DEF') $prefijo = "DEF";
    elseif (in_array($tipo_constancia, ['SOLTERIA', 'SOLTERIA_DIV'])) $prefijo = "SOL";
    elseif ($tipo_constancia === 'NO_REGISTRO_CED') $prefijo = "CED";
    elseif ($tipo_constancia === 'NO_REGISTRO_MAT') $prefijo = "MAT";

    // 2. Correlativo Anual DINÁMICO
    $stmt_corr = $pdo->prepare("SELECT MAX(correlativo_anual) FROM constancias WHERE YEAR(fecha) = YEAR(CURDATE())");
    $stmt_corr->execute();
    $ultimo_valor = (int)$stmt_corr->fetchColumn();
    
    $nuevo_correlativo = $ultimo_valor + 1; 
    
    $numero_referencia = $prefijo . "-" . date('Y') . "-" . str_pad((string)$nuevo_correlativo, 4, "0", STR_PAD_LEFT);

    // 3. Inicialización Universal de variables
    $nombre_no_registro = 'NO ESPECIFICADO';
    $tipo_soporte = 'N/A';
    $nombre_hospital = null; 
    $fecha_nac = null; $hora_nac = null;
    $fecha_def = null; $hora_def = null;
    $madre = null; $madre_dui = null; $padre = null;
    $partida = null; $folio = null; $libro = null; $anio = null;
    $def_nombre_segun_doc = null; $def_tipo_doc_segun_id = null;
    $mat_c1 = null; $mat_c2 = null; 
    
    $distrito_id = null; 
    $def_depto_id = null; 
    $def_muni_id = null;
    $def_dist_id = null;

    // 4. Mapeo Específico por trámite
    if ($tipo_constancia === 'NO_REGISTRO_NAC') {
        $nombre_no_registro = mb_strtoupper(trim($_POST['nac_nombre_no_registro'] ?? ''), 'UTF-8');
        $tipo_soporte = $_POST['nac_tipo_soporte'] ?? 'N/A';
        $nombre_hospital = mb_strtoupper($_POST['nac_nombre_hospital'] ?? '', 'UTF-8');
        $fecha_nac = !empty($_POST['nac_fecha_nacimiento']) ? $_POST['nac_fecha_nacimiento'] : null;
        $hora_nac = !empty($_POST['nac_hora_nacimiento']) ? $_POST['nac_hora_nacimiento'] : null;
        $madre = mb_strtoupper($_POST['nac_nombre_madre'] ?? '', 'UTF-8');
        $madre_dui = mb_strtoupper($_POST['nac_nombre_madre_dui'] ?? '', 'UTF-8');
        $padre = mb_strtoupper($_POST['nac_nombre_padre'] ?? '', 'UTF-8');
        $distrito_id = !empty($_POST['nac_distrito_nacimiento_id']) ? $_POST['nac_distrito_nacimiento_id'] : null;
    } 
    elseif ($tipo_constancia === 'NO_REGISTRO_DEF') {
        $nombre_no_registro = mb_strtoupper(trim($_POST['def_nombre_no_registro'] ?? ''), 'UTF-8');
        $tipo_soporte = $_POST['def_tipo_soporte'] ?? 'N/A';
        $nombre_hospital = mb_strtoupper($_POST['def_nombre_hospital'] ?? '', 'UTF-8');
        $fecha_def = !empty($_POST['def_fecha_defuncion']) ? $_POST['def_fecha_defuncion'] : null;
        $hora_def = !empty($_POST['def_hora_defuncion']) ? $_POST['def_hora_defuncion'] : null;
        $madre = mb_strtoupper($_POST['def_nombre_madre'] ?? '', 'UTF-8');
        $madre_dui = mb_strtoupper($_POST['def_nombre_madre_dui'] ?? '', 'UTF-8');
        $padre = mb_strtoupper($_POST['def_nombre_padre'] ?? '', 'UTF-8');
        $def_nombre_segun_doc = mb_strtoupper($_POST['def_nombre_segun_doc'] ?? '', 'UTF-8');
        $def_tipo_doc_segun_id = !empty($_POST['def_tipo_doc_segun_id']) ? $_POST['def_tipo_doc_segun_id'] : null;
        $def_depto_id = !empty($_POST['def_departamento_id']) ? $_POST['def_departamento_id'] : null;
        $def_muni_id = !empty($_POST['def_municipio_id']) ? $_POST['def_municipio_id'] : null;
        $def_dist_id = !empty($_POST['def_distrito_id']) ? $_POST['def_distrito_id'] : null;
    }
    elseif (in_array($tipo_constancia, ['SOLTERIA', 'SOLTERIA_DIV'])) {
        $nombre_no_registro = mb_strtoupper(trim($_POST['sol_div_nombre_inscrito'] ?? $nombre_solicitante), 'UTF-8');
        $partida = $_POST['sol_div_numero_partida'] ?? null;
        $folio   = $_POST['sol_div_folio'] ?? null;
        $libro   = $_POST['sol_div_libro'] ?? null;
        $anio    = $_POST['sol_div_anio'] ?? null;
    }
    elseif ($tipo_constancia === 'NO_REGISTRO_CED') {
        $nombre_no_registro = mb_strtoupper(trim($_POST['ced_nombre_no_registro'] ?? ''), 'UTF-8');
        $tipo_soporte = 'CEDULA_IDENTIDAD';
    }
    elseif ($tipo_constancia === 'NO_REGISTRO_MAT') {
        $mat_c1 = mb_strtoupper(trim($_POST['mat_nombre_no_registro'] ?? ''), 'UTF-8');
        $mat_c2 = mb_strtoupper(trim($_POST['mat_nombre_contrayente_dos'] ?? ''), 'UTF-8');
        $tipo_soporte = $_POST['mat_tipo_soporte'] ?? 'N/A';
        $nombre_no_registro = $mat_c1; 
    }

/* ===================== SOLUCIÓN MEJORADA AL NOMBRE DE ARCHIVO ===================== */

// Función interna para asegurar la conversión limpia de Ñ y tildes a ASCII
$limpiar_acentos = function($cadena) {
    $busqueda  = array('Ñ', 'ñ', 'á', 'é', 'í', 'ó', 'ú', 'Á', 'É', 'Í', 'Ó', 'Ú');
    $reemplazo = array('N', 'n', 'a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U');
    return str_replace($busqueda, $reemplazo, $cadena);
};

// 1. Limpiamos Ñ y tildes primero
$nombre_ascii = $limpiar_acentos($nombre_no_registro);

// 2. Reemplazamos cualquier cosa que no sea letras o números por un guion bajo
$persona_slug = preg_replace('/[^A-Za-z0-9]/', '_', $nombre_ascii);

// 3. Quitamos guiones bajos duplicados y los de los extremos
$persona_slug = trim(preg_replace('/_+/', '_', $persona_slug), '_');

// 4. Definimos la ruta final (Usamos guiones bajos para que la URL sea segura)
$ruta_almacenamiento = "archivos_finales/CERT_" . $tipo_constancia . "_" . $persona_slug . "_" . $numero_referencia . ".pdf";

/* ==================================================================================== */

    // 5. INSERCIÓN
    $sql = "INSERT INTO constancias (
        numero_constancia, correlativo_anual, fecha, tipo_constancia, 
        nombre_solicitante, tipo_documento_id, numero_documento,
        nombre_no_registro, fecha_nacimiento, hora_nacimiento, 
        fecha_defuncion, hora_defuncion,
        tipo_soporte, nombre_hospital, distrito_nacimiento_id,
        def_departamento_id, def_municipio_id, def_distrito_id,
        nombre_madre, nombre_madre_dui, nombre_padre,
        partida_n, folio_n, libro_n, anio_n,
        def_nombre_segun_doc, def_tipo_doc_segun_id,
        mat_contrayente_1, mat_contrayente_2,
        unique_hash, qr_token, creado_por_id, 
        estado_validacion, ruta_pdf_final
    ) VALUES (
        :num, :corr, NOW(), :tipo_c,
        :nom_s, :tipo_d, :num_d,
        :nom_nr, :f_nac, :h_nac,
        :f_def, :h_def,
        :soporte, :hosp, :dist,
        :d_depto, :d_muni, :d_dist,
        :madre, :madre_dui, :padre,
        :part, :folio, :libro, :anio,
        :def_nom_doc, :def_tipo_doc,
        :m_c1, :m_c2,
        :hash, :token, :user_id, 
        'PENDIENTE', :ruta_pdf
    )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':num'           => $numero_referencia,
        ':corr'          => $nuevo_correlativo, 
        ':tipo_c'        => $tipo_constancia,
        ':nom_s'         => $nombre_solicitante,
        ':tipo_d'        => $tipo_doc_id,
        ':num_d'         => $num_doc,
        ':nom_nr'        => $nombre_no_registro,
        ':f_nac'         => $fecha_nac,
        ':h_nac'         => $hora_nac,
        ':f_def'         => $fecha_def,
        ':h_def'         => $hora_def,
        ':soporte'       => $tipo_soporte,
        ':hosp'          => $nombre_hospital,
        ':dist'          => $distrito_id,
        ':d_depto'       => $def_depto_id,
        ':d_muni'        => $def_muni_id,
        ':d_dist'        => $def_dist_id,
        ':madre'         => $madre,
        ':madre_dui'     => $madre_dui,
        ':padre'         => $padre,
        ':part'          => $partida,
        ':folio'         => $folio,
        ':libro'         => $libro,
        ':anio'          => $anio,
        ':def_nom_doc'   => $def_nombre_segun_doc,
        ':def_tipo_doc'  => $def_tipo_doc_segun_id,
        ':m_c1'          => $mat_c1,
        ':m_c2'          => $mat_c2,
        ':hash'          => bin2hex(random_bytes(16)),
        ':token'         => bin2hex(random_bytes(20)),
        ':user_id'       => $creado_por_id,
        ':ruta_pdf'      => $ruta_almacenamiento
    ]);

    $lastId = $pdo->lastInsertId();
    $pdo->commit();

    echo json_encode(['success' => true, 'id' => $lastId, 'oficio' => $numero_referencia]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'msg' => 'Error en servidor: ' . $e->getMessage()]);
}