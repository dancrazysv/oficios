<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_config.php';

$rol = $_SESSION['user_rol'] ?? 'normal';
$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Método no permitido.");
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    die("Error de validación de seguridad (CSRF).");
}

$id_oficio = filter_input(INPUT_POST, 'id_oficio', FILTER_VALIDATE_INT);
if (!$id_oficio) {
    die("ID de oficio inválido.");
}

/* Verificar permisos: admin/supervisor siempre; normal solo si es dueño y está PENDIENTE */
if (!in_array($rol, ['administrador', 'supervisor'], true)) {
    $stmt_perm = $pdo->prepare("SELECT creado_por, estado_validacion FROM oficios_institucionales WHERE id = ?");
    $stmt_perm->execute([$id_oficio]);
    $perm_row = $stmt_perm->fetch(PDO::FETCH_ASSOC);
    if (!$perm_row
        || (int)$perm_row['creado_por'] !== $user_id
        || $perm_row['estado_validacion'] !== 'PENDIENTE') {
        die("Acceso denegado. No tienes permisos para editar este registro.");
    }
}

try {
    $pdo->beginTransaction();

    // Obtener referencia
    $stmt_ref = $pdo->prepare("SELECT referencia_salida FROM oficios_institucionales WHERE id = ?");
    $stmt_ref->execute([$id_oficio]);
    $referencia_salida = (string)$stmt_ref->fetchColumn();

    if ($referencia_salida === '') {
        throw new Exception("Oficio no encontrado para actualizar.");
    }

    $carpeta_anexos = __DIR__ . '/anexos_institucionales/' . $referencia_salida . '/';
    if (!is_dir($carpeta_anexos)) {
        mkdir($carpeta_anexos, 0755, true);
    }

    // Cabecera
    $id_institucion = (int)($_POST['id_institucion'] ?? 0);
    $fecha_documento = $_POST['fecha_documento'] ?? date('Y-m-d');
    $email_envio = trim((string)($_POST['email_envio'] ?? ''));

    if ($email_envio !== '' && !filter_var($email_envio, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("El correo electrónico proporcionado no es válido.");
    }

    $stmt_header = $pdo->prepare("
        UPDATE oficios_institucionales
        SET id_institucion = ?, fecha_documento = ?, email_envio = ?
        WHERE id = ?
    ");
    $stmt_header->execute([
        $id_institucion,
        $fecha_documento,
        ($email_envio !== '' ? $email_envio : null),
        $id_oficio
    ]);

    // Entradas
    $entradas_ids_post = $_POST['entrada_id'] ?? [];
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

        $id_entrada = $entradas_ids_post[$idx] ?? null;

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

    // Entradas: limpiar las que ya no existan en el formulario
    if (!empty($entradas_ids_post)) {
        $ids_validos = array_filter($entradas_ids_post, fn($v) => is_numeric($v));
        if (!empty($ids_validos)) {
            $placeholders = implode(',', array_fill(0, count($ids_validos), '?'));
            $stmt_del = $pdo->prepare("DELETE FROM oficios_institucionales_entradas WHERE id_oficio_inst = ? AND id NOT IN ($placeholders)");
            $stmt_del->execute(array_merge([$id_oficio], array_map('intval', $ids_validos)));
        }
    } else {
        $stmt_del = $pdo->prepare("DELETE FROM oficios_institucionales_entradas WHERE id_oficio_inst = ?");
        $stmt_del->execute([$id_oficio]);
    }

    // Detalles
    $detalles_ids_post = $_POST['detalle_id'] ?? [];
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

    foreach ($nombres_cons as $idx => $nombre) {
        $nombre = trim((string)$nombre);
        if ($nombre === '') {
            continue;
        }

        $id_det = $detalles_ids_post[$idx] ?? null;
        $resultado = strtoupper((string)($resultados[$idx] ?? 'NO_ENCONTRADO'));

        $partida_num = ($resultado === 'ENCONTRADO') ? trim((string)($partidas[$idx] ?? '')) : null;
        $folio = ($resultado === 'ENCONTRADO') ? trim((string)($folios[$idx] ?? '')) : null;
        $libro = ($resultado === 'ENCONTRADO') ? trim((string)($libros[$idx] ?? '')) : null;
        $anio = ($resultado === 'ENCONTRADO' && !empty($anios[$idx])) ? (int)$anios[$idx] : null;
        $fecha_evento = !empty($fechas_evento[$idx]) ? $fechas_evento[$idx] : null;

        if ($id_det !== null && $id_det !== '' && is_numeric($id_det)) {
            $stmt = $pdo->prepare("
                UPDATE oficios_institucionales_detalle
                SET nombre_consultado = ?, filiacion_1 = ?, filiacion_2 = ?, tipo_tramite = ?, resultado = ?, partida_numero = ?, partida_folio = ?, partida_libro = ?, partida_anio = ?, fecha_evento = ?, observaciones = ?
                WHERE id = ? AND id_oficio_inst = ?
            ");
            $stmt->execute([
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
                (int)$id_det,
                $id_oficio
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO oficios_institucionales_detalle
                (id_oficio_inst, nombre_consultado, filiacion_1, filiacion_2, tipo_tramite, resultado, partida_numero, partida_folio, partida_libro, partida_anio, fecha_evento, observaciones)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $id_oficio,
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
                trim((string)($obs[$idx] ?? ''))
            ]);
        }
    }

    // Detalles: limpiar los que ya no existan en el formulario
    if (!empty($detalles_ids_post)) {
        $ids_validos = array_filter($detalles_ids_post, fn($v) => is_numeric($v));
        if (!empty($ids_validos)) {
            $placeholders = implode(',', array_fill(0, count($ids_validos), '?'));
            $stmt_del = $pdo->prepare("DELETE FROM oficios_institucionales_detalle WHERE id_oficio_inst = ? AND id NOT IN ($placeholders)");
            $stmt_del->execute(array_merge([$id_oficio], array_map('intval', $ids_validos)));
        }
    } else {
        $stmt_del = $pdo->prepare("DELETE FROM oficios_institucionales_detalle WHERE id_oficio_inst = ?");
        $stmt_del->execute([$id_oficio]);
    }

    // Eliminar adjuntos marcados
    if (!empty($_POST['eliminar_adjunto']) && is_array($_POST['eliminar_adjunto'])) {
        foreach ($_POST['eliminar_adjunto'] as $archivo) {
            $ruta = $carpeta_anexos . basename((string)$archivo);
            if (is_file($ruta)) {
                unlink($ruta);
            }
        }
    }

    // Subir nuevos adjuntos
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

    header("Location: ver_oficio_institucional.php?id=" . $id_oficio . "&msg=actualizado");
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error actualizar_oficio_institucional: " . $e->getMessage());
    header("Location: editar_oficio_institucional.php?id=" . $id_oficio . "&msg=error&error=" . urlencode($e->getMessage()));
    exit;
}