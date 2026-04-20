<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db_config.php';
header('Content-Type: application/json');

/* Autenticación */
$user_id  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($user_id <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => 'Sesión expirada.']);
    exit;
}

/* CSRF */
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => 'Error de seguridad: Token inválido']);
    exit;
}

try {
    if (!$pdo->inTransaction()) $pdo->beginTransaction();

    $id = (int)($_POST['id_constancia'] ?? 0);
    if ($id <= 0) throw new Exception("ID de constancia no válido.");
    
    // 1. Obtener datos actuales
    $stmt_old = $pdo->prepare("SELECT * FROM constancias WHERE id = ?");
    $stmt_old->execute([$id]);
    $old_data = $stmt_old->fetch(PDO::FETCH_ASSOC);

    if (!$old_data) throw new Exception("Registro no encontrado.");

    /* Control de acceso: normal solo puede editar su propia constancia PENDIENTE */
    $user_rol = (string)($_SESSION['user_rol'] ?? '');
    if (!in_array($user_rol, ['administrador', 'supervisor'], true)) {
        $creado_por = (int)($old_data['creado_por_id'] ?? 0);
        if ($creado_por !== $user_id || ($old_data['estado_validacion'] ?? '') !== 'PENDIENTE') {
            throw new Exception("Acceso denegado.");
        }
    }

    $tipo = $_POST['tipo_constancia_id'] ?? $old_data['tipo_constancia'];
    
    // 2. Inicialización de valores
    $nombre_nr = $old_data['nombre_no_registro'];
    $soporte   = $old_data['tipo_soporte'];
    $hosp      = $old_data['nombre_hospital'];
    $dist_nac_id = $old_data['distrito_nacimiento_id'];
    $f_nac       = $old_data['fecha_nacimiento'];
    $h_nac       = $old_data['hora_nacimiento'];
    $f_def       = $old_data['fecha_defuncion'];
    $h_def       = $old_data['hora_defuncion'];
    $def_depto   = $old_data['def_departamento_id'];
    $def_muni    = $old_data['def_municipio_id'];
    $def_dist    = $old_data['def_distrito_id'];
    $def_nom_doc = $old_data['def_nombre_segun_doc'];
    $def_tipo_id = $old_data['def_tipo_doc_segun_id'];
    $madre       = $old_data['nombre_madre'];
    $madre_dui   = $old_data['nombre_madre_dui'];
    $padre       = $old_data['nombre_padre'];
    $partida = $old_data['partida_n']; 
    $folio   = $old_data['folio_n']; 
    $libro   = $old_data['libro_n']; 
    $anio    = $old_data['anio_n'];
    $mat_c1 = $old_data['mat_contrayente_1'];
    $mat_c2 = $old_data['mat_contrayente_2'];

    // 3. Mapeo por tipo de trámite
    if ($tipo === 'NO_REGISTRO_NAC') {
        $nombre_nr   = strtoupper($_POST['nac_nombre_no_registro'] ?? $nombre_nr);
        $soporte     = $_POST['nac_tipo_soporte'] ?? $soporte;
        $hosp        = strtoupper($_POST['nac_nombre_hospital'] ?? $hosp);
        $dist_nac_id = !empty($_POST['nac_distrito_nacimiento_id']) ? $_POST['nac_distrito_nacimiento_id'] : $dist_nac_id;
        $f_nac       = !empty($_POST['nac_fecha_nacimiento']) ? $_POST['nac_fecha_nacimiento'] : $f_nac;
        $h_nac       = !empty($_POST['nac_hora_nacimiento']) ? $_POST['nac_hora_nacimiento'] : $h_nac;
        $madre       = strtoupper($_POST['nac_nombre_madre'] ?? $madre);
        $madre_dui   = strtoupper($_POST['nac_nombre_madre_dui'] ?? $madre_dui);
        $padre       = strtoupper($_POST['nac_nombre_padre'] ?? $padre);
    } 
    elseif ($tipo === 'NO_REGISTRO_DEF') {
        $nombre_nr = strtoupper($_POST['def_nombre_no_registro'] ?? $nombre_nr);
        $soporte   = $_POST['def_tipo_soporte'] ?? $soporte;
        $hosp      = strtoupper($_POST['def_nombre_hospital'] ?? $hosp);
        $f_def     = !empty($_POST['def_fecha_defuncion']) ? $_POST['def_fecha_defuncion'] : $f_def;
        $h_def     = !empty($_POST['def_hora_defuncion']) ? $_POST['def_hora_defuncion'] : $h_def;
        $madre     = strtoupper($_POST['def_nombre_madre'] ?? $madre); 
        $madre_dui = strtoupper($_POST['def_nombre_madre_dui'] ?? $madre_dui);
        $padre     = strtoupper($_POST['def_nombre_padre'] ?? $padre);
        $def_nom_doc = strtoupper($_POST['def_nombre_segun_doc'] ?? $def_nom_doc);
        $def_tipo_id = !empty($_POST['def_tipo_doc_segun_id']) ? $_POST['def_tipo_doc_segun_id'] : $def_tipo_id;
        $def_depto = !empty($_POST['def_departamento_id']) ? $_POST['def_departamento_id'] : $def_depto;
        $def_muni  = !empty($_POST['def_municipio_id']) ? $_POST['def_municipio_id'] : $def_muni;
        $def_dist  = !empty($_POST['def_distrito_id']) ? $_POST['def_distrito_id'] : $def_dist;
    }
    elseif ($tipo === 'NO_REGISTRO_MAT') {
        $mat_c1 = strtoupper(trim((string)($_POST['mat_nombre_no_registro'] ?? $mat_c1)));
        $mat_c2 = strtoupper(trim((string)($_POST['mat_nombre_contrayente_dos'] ?? $mat_c2)));
        $nombre_nr = $mat_c1; 
        $soporte   = $_POST['mat_tipo_soporte'] ?? $soporte;
    }
    elseif (in_array($tipo, ['SOLTERIA', 'SOLTERIA_DIV'])) {
        $nombre_nr = strtoupper(trim((string)($_POST['sol_div_nombre_inscrito'] ?? $nombre_nr)));
        $partida   = $_POST['sol_div_numero_partida'] ?? $partida;
        $folio     = $_POST['sol_div_folio'] ?? $folio;
        $libro     = $_POST['sol_div_libro'] ?? $libro;
        $anio      = $_POST['sol_div_anio'] ?? $anio;
    }
    elseif ($tipo === 'NO_REGISTRO_CED') {
        $nombre_nr = strtoupper($_POST['ced_nombre_no_registro'] ?? $nombre_nr);
        $soporte   = 'CEDULA_IDENTIDAD';
    }

    // 4. Procesamiento de ruta PDF (AJUSTADO PARA COINCIDIR EXACTAMENTE)
    // Cambiamos la lógica: No reemplazamos todo por guion bajo, solo espacios.
    function cleanForFilename($text) {
        // Reemplaza espacios por guiones bajos, pero mantiene guiones medios si existen
        $text = str_replace(' ', '_', (string)$text);
        // Quitamos caracteres especiales pero preservamos guion medio y bajo
        $text = preg_replace('/[^A-Za-z0-9_\-]/', '', $text);
        return trim($text, '_');
    }

    if ($tipo === 'NO_REGISTRO_MAT') {
        $n1_slug = cleanForFilename($mat_c1);
        $n2_slug = cleanForFilename($mat_c2);
        // El formato resultante debe ser: NOMBRE1_Y_NOMBRE2
        $persona_slug = $n1_slug . "_Y_" . $n2_slug;
    } else {
        $persona_slug = cleanForFilename($nombre_nr);
    }

    // Construimos el nombre final. 
    // Usamos guion bajo para separar el SLUG del NÚMERO DE CONSTANCIA si es necesario, 
    // pero respetamos el formato de tu archivo físico.
    $nuevo_nombre_pdf = "CERT_" . $tipo . "_" . $persona_slug . "_" . $old_data['numero_constancia'] . ".pdf";
    $nueva_ruta = "archivos_finales/" . $nuevo_nombre_pdf;

    // 5. UPDATE INTEGRAL
    $sql = "UPDATE constancias SET 
            nombre_solicitante = :nom_s, 
            tipo_documento_id = :tipo_d,
            numero_documento = :num_d,
            nombre_no_registro = :nom_nr,
            ruta_pdf_final = :ruta,
            tipo_soporte = :soporte,
            nombre_hospital = :hosp,
            distrito_nacimiento_id = :dist_nac,
            fecha_nacimiento = :f_nac,
            hora_nacimiento = :h_nac,
            fecha_defuncion = :f_def,
            hora_defuncion = :h_def,
            def_departamento_id = :d_depto,
            def_municipio_id = :d_muni,
            def_distrito_id = :d_dist,
            def_nombre_segun_doc = :d_nom_doc,
            def_tipo_doc_segun_id = :d_tipo_id,
            nombre_madre = :madre,
            nombre_madre_dui = :madre_dui,
            nombre_padre = :padre,
            partida_n = :part, folio_n = :folio, libro_n = :libro, anio_n = :anio,
            mat_contrayente_1 = :m_c1, 
            mat_contrayente_2 = :m_c2
            WHERE id = :id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':nom_s'     => strtoupper(trim((string)($_POST['nombre_solicitante'] ?? $old_data['nombre_solicitante']))),
        ':tipo_d'    => $_POST['tipo_documento_id'] ?? $old_data['tipo_documento_id'],
        ':num_d'     => trim((string)($_POST['numero_documento'] ?? $old_data['numero_documento'])),
        ':nom_nr'    => $nombre_nr,
        ':ruta'      => $nueva_ruta,
        ':soporte'   => $soporte,
        ':hosp'      => $hosp,
        ':dist_nac'  => $dist_nac_id,
        ':f_nac'     => $f_nac,
        ':h_nac'     => $h_nac,
        ':f_def'     => $f_def,
        ':h_def'     => $h_def,
        ':d_depto'   => $def_depto,
        ':d_muni'    => $def_muni,
        ':d_dist'    => $def_dist,
        ':d_nom_doc' => $def_nom_doc,
        ':d_tipo_id' => $def_tipo_id,
        ':madre'     => $madre,
        ':madre_dui' => $madre_dui,
        ':padre'     => $padre,
        ':part'      => $partida,
        ':folio'     => $folio,
        ':libro'     => $libro,
        ':anio'      => $anio,
        ':m_c1'      => $mat_c1,
        ':m_c2'      => $mat_c2,
        ':id'        => $id
    ]);

    $pdo->commit();
    
    echo json_encode([
        'success' => true, 
        'oficio' => $old_data['numero_constancia'],
        'msg' => 'Certificación actualizada correctamente'
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'msg' => 'Error al actualizar: ' . $e->getMessage()]);
}