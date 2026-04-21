<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$log_file = __DIR__ . '/logs/php_errors.log';
$log_dir = dirname($log_file);
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0777, true);
}

file_put_contents($log_file, "\n[" . date('Y-m-d H:i:s') . "] === INICIO PROCESAMIENTO OFICIO INST ===\n", FILE_APPEND | LOCK_EX);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Método no permitido.");
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    die("Error de validación de seguridad (CSRF).");
}

$rol = $_SESSION['user_rol'] ?? 'normal';
$user_id = (int)($_SESSION['user_id'] ?? 0);
$estado_inicial = in_array($rol, ['administrador', 'supervisor'], true) ? 'APROBADO' : 'PENDIENTE';

$id_oficio = filter_input(INPUT_POST, 'id_oficio', FILTER_VALIDATE_INT);
$es_actualizacion = ($id_oficio !== false && $id_oficio !== null && $id_oficio > 0);

try {
    $pdo->beginTransaction();

    /* =========================================================
       1. OBTENER / GENERAR REFERENCIA
    ========================================================= */
    if ($es_actualizacion) {
        $stmt_ref = $pdo->prepare("SELECT referencia_salida FROM oficios_institucionales WHERE id = ?");
        $stmt_ref->execute([$id_oficio]);
        $referencia_salida = (string)$stmt_ref->fetchColumn();

        if ($referencia_salida === '') {
            throw new Exception("No se encontró el oficio para actualizar.");
        }
    } else {
        $anio_actual = date('Y');
        $stmt_corr = $pdo->prepare("SELECT MAX(correlativo_anual) FROM oficios_institucionales WHERE YEAR(fecha) = ?");
        $stmt_corr->execute([$anio_actual]);
        $ultimo = (int)$stmt_corr->fetchColumn();

        $nuevo_correlativo = max($ultimo, 509) + 1;
        $referencia_salida = "REFSSCINS-" . $anio_actual . "-" . str_pad((string)$nuevo_correlativo, 4, '0', STR_PAD_LEFT);
    }

    $carpeta_anexos = __DIR__ . '/anexos_institucionales/' . $referencia_salida . '/';

    /* =========================================================
       2. CABECERA
    ========================================================= */
    $id_institucion = (int)($_POST['id_institucion'] ?? 0);
    $fecha_documento = $_POST['fecha_documento'] ?? date('Y-m-d');
    $email_envio = trim((string)($_POST['email_envio'] ?? ''));

    if ($id_institucion <= 0) {
        throw new Exception("Debe seleccionar una institución válida.");
    }

    if ($email_envio !== '' && !filter_var($email_envio, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("El correo electrónico proporcionado no es válido.");
    }

    $stmt_inst = $pdo->prepare("SELECT id FROM instituciones WHERE id = ? AND estado = 1");
    $stmt_inst->execute([$id_institucion]);
    if (!$stmt_inst->fetchColumn()) {
        throw new Exception("La institución seleccionada no es válida o no está activa.");
    }

    if ($es_actualizacion) {
        $stmt_up = $pdo->prepare("
            UPDATE oficios_institucionales
            SET id_institucion = ?, fecha_documento = ?, email_envio = ?
            WHERE id = ?
        ");
        $stmt_up->execute([$id_institucion, $fecha_documento, ($email_envio !== '' ? $email_envio : null), $id_oficio]);
    } else {
        $stmt_ins = $pdo->prepare("
            INSERT INTO oficios_institucionales
            (referencia_salida, correlativo_anual, fecha, creado_por, estado_validacion, ruta_pdf_final, id_institucion, email_envio, fecha_documento)
            VALUES (?, ?, NOW(), ?, ?, NULL, ?, ?, ?)
        ");
        $correlativo = (int)substr($referencia_salida, -4);

        $stmt_ins->execute([
            $referencia_salida,
            $correlativo,
            $user_id,
            $estado_inicial,
            $id_institucion,
            ($email_envio !== '' ? $email_envio : null),
            $fecha_documento
        ]);

        $id_oficio = (int)$pdo->lastInsertId();
    }

    /* =========================================================
       3. ENTRADAS
    ========================================================= */
    $ids_entradas_post = $_POST['entrada_id'] ?? [];
    $nums = $_POST['num_oficio_in'] ?? [];
    $refs = $_POST['ref_expediente_in'] ?? [];
    $fechas = $_POST['fecha_doc_in'] ?? [];
    $tipos = $_POST['tipo_partida_solicitada'] ?? [];
    $nombres_sol = $_POST['nombre_segun_oficio'] ?? [];

    $ids_entradas_procesados = [];

    foreach ($nombres_sol as $idx => $nombre_solicitado) {
        $nombre_solicitado = trim((string)$nombre_solicitado);
        if ($nombre_solicitado === '') {
            continue;
        }

        $num_oficio = trim((string)($nums[$idx] ?? ''));
        $ref_exp = trim((string)($refs[$idx] ?? 'S/N'));
        $fec_doc = $fechas[$idx] ?? null;
        $tipo_partida = (string)($tipos[$idx] ?? 'NACIMIENTO');

        $id_entrada = $ids_entradas_post[$idx] ?? null;

        if ($id_entrada !== null && $id_entrada !== '' && is_numeric($id_entrada)) {
            $stmt = $pdo->prepare("
                UPDATE oficios_institucionales_entradas
                SET num_oficio_in = ?, ref_expediente_in = ?, fecha_doc_in = ?, tipo_partida_solicitada = ?, nombre_solicitado = ?
                WHERE id = ? AND id_oficio_inst = ?
            ");
            $stmt->execute([
                $num_oficio,
                $ref_exp,
                $fec_doc,
                $tipo_partida,
                $nombre_solicitado,
                (int)$id_entrada,
                $id_oficio
            ]);
            $ids_entradas_procesados[] = (int)$id_entrada;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO oficios_institucionales_entradas
                (id_oficio_inst, num_oficio_in, ref_expediente_in, fecha_doc_in, tipo_partida_solicitada, nombre_solicitado)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $id_oficio,
                $num_oficio,
                $ref_exp,
                $fec_doc,
                $tipo_partida,
                $nombre_solicitado
            ]);
        }
    }

    if ($es_actualizacion) {
        if (!empty($ids_entradas_procesados)) {
            $placeholders = implode(',', array_fill(0, count($ids_entradas_procesados), '?'));
            $sql_del = "DELETE FROM oficios_institucionales_entradas WHERE id_oficio_inst = ? AND id NOT IN ($placeholders)";
            $stmt_del = $pdo->prepare($sql_del);
            $stmt_del->execute(array_merge([$id_oficio], $ids_entradas_procesados));
        } else {
            $stmt_del = $pdo->prepare("DELETE FROM oficios_institucionales_entradas WHERE id_oficio_inst = ?");
            $stmt_del->execute([$id_oficio]);
        }
    }

    /* =========================================================
       4. DETALLES
    ========================================================= */
    $ids_detalles_post = $_POST['detalle_id'] ?? [];
    $nombres_cons = $_POST['nombre_consultado'] ?? [];
    $tipos_tramite = $_POST['tipo_tramite'] ?? [];
    $resultados = $_POST['resultado'] ?? [];
    $obs = $_POST['observaciones'] ?? [];
    $fil1 = $_POST['padre_conyuge_1'] ?? [];
    $fil2 = $_POST['padre_conyuge_2'] ?? [];
    $partidas = $_POST['partida'] ?? [];
    $folios = $_POST['folio'] ?? [];
    $libros = $_POST['libro'] ?? [];
    $anios = $_POST['anio'] ?? [];
    $fechas_evento = $_POST['fecha_evento'] ?? [];

    $ids_detalles_procesados = [];

    foreach ($nombres_cons as $idx => $nombre) {
        $nombre = trim((string)$nombre);
        if ($nombre === '') {
            continue;
        }

        $id_det = $ids_detalles_post[$idx] ?? null;
        $resultado = strtoupper((string)($resultados[$idx] ?? 'NO_ENCONTRADO'));

        $partida_num = ($resultado === 'ENCONTRADO') ? trim((string)($partidas[$idx] ?? '')) : null;
        $folio = ($resultado === 'ENCONTRADO') ? trim((string)($folios[$idx] ?? '')) : null;
        $libro = ($resultado === 'ENCONTRADO') ? trim((string)($libros[$idx] ?? '')) : null;
        $anio = ($resultado === 'ENCONTRADO' && !empty($anios[$idx])) ? (int)$anios[$idx] : null;
        $fecha_evento = !empty($fechas_evento[$idx]) ? $fechas_evento[$idx] : null;

        $datos = [
            mb_strtoupper($nombre, 'UTF-8'),
            mb_strtoupper((string)($fil1[$idx] ?? ''), 'UTF-8'),
            mb_strtoupper((string)($fil2[$idx] ?? ''), 'UTF-8'),
            (string)($tipos_tramite[$idx] ?? 'NACIMIENTO'),
            $resultado,
            $partida_num,
            $folio,
            $libro,
            $anio,
            $fecha_evento,
            trim((string)($obs[$idx] ?? '')),
        ];

        if ($id_det !== null && $id_det !== '' && is_numeric($id_det)) {
            $stmt = $pdo->prepare("
                UPDATE oficios_institucionales_detalle
                SET nombre_consultado = ?, filiacion_1 = ?, filiacion_2 = ?, tipo_tramite = ?, resultado = ?, partida_numero = ?, partida_folio = ?, partida_libro = ?, partida_anio = ?, fecha_evento = ?, observaciones = ?
                WHERE id = ? AND id_oficio_inst = ?
            ");
            $stmt->execute(array_merge($datos, [(int)$id_det, $id_oficio]));
            $ids_detalles_procesados[] = (int)$id_det;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO oficios_institucionales_detalle
                (id_oficio_inst, nombre_consultado, filiacion_1, filiacion_2, tipo_tramite, resultado, partida_numero, partida_folio, partida_libro, partida_anio, fecha_evento, observaciones)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute(array_merge([$id_oficio], $datos));
        }
    }

    if ($es_actualizacion) {
        if (!empty($ids_detalles_procesados)) {
            $placeholders = implode(',', array_fill(0, count($ids_detalles_procesados), '?'));
            $sql_del = "DELETE FROM oficios_institucionales_detalle WHERE id_oficio_inst = ? AND id NOT IN ($placeholders)";
            $stmt_del = $pdo->prepare($sql_del);
            $stmt_del->execute(array_merge([$id_oficio], $ids_detalles_procesados));
        } else {
            $stmt_del = $pdo->prepare("DELETE FROM oficios_institucionales_detalle WHERE id_oficio_inst = ?");
            $stmt_del->execute([$id_oficio]);
        }
    }

    /* =========================================================
       5. ARCHIVOS ADJUNTOS
    ========================================================= */
    if (!is_dir($carpeta_anexos)) {
        mkdir($carpeta_anexos, 0755, true);
    }

    if (!empty($_POST['eliminar_adjunto']) && is_array($_POST['eliminar_adjunto'])) {
        foreach ($_POST['eliminar_adjunto'] as $archivo) {
            $ruta = $carpeta_anexos . basename((string)$archivo);
            if (is_file($ruta)) {
                unlink($ruta);
            }
        }
    }

    if (isset($_FILES['archivos_adjuntos']) && is_array($_FILES['archivos_adjuntos']['name'])) {
        foreach ($_FILES['archivos_adjuntos']['name'] as $idx => $nombre_archivo) {
            if (($_FILES['archivos_adjuntos']['error'][$idx] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES['archivos_adjuntos']['tmp_name'][$idx];
                $tipo_archivo = $_FILES['archivos_adjuntos']['type'][$idx] ?? '';

                if ($tipo_archivo !== 'application/pdf' && strtolower(pathinfo($nombre_archivo, PATHINFO_EXTENSION)) !== 'pdf') {
                    throw new Exception("El archivo '{$nombre_archivo}' no es un PDF válido.");
                }

                $nombre_limpio = preg_replace('/[^a-zA-Z0-9._-]/', '_', $nombre_archivo);
                $nombre_limpio = preg_replace('/_{2,}/', '_', $nombre_limpio);

                $ruta_destino = $carpeta_anexos . $nombre_limpio;
                if (!move_uploaded_file($tmp_name, $ruta_destino)) {
                    throw new Exception("No se pudo guardar el archivo adjunto: {$nombre_limpio}");
                }
            }
        }
    }

    $pdo->commit();

    if ($rol === 'normal') {
        header("Location: dashboard.php");
    } else {
        header("Location: ver_oficio_institucional.php?id=" . $id_oficio . "&msg=creado");
    }
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error procesar_oficio_institucional: " . $e->getMessage());
    die("Error crítico al procesar el oficio: " . $e->getMessage());
}