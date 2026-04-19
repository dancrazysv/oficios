<?php
declare(strict_types=1);

/* ================= CONFIGURACIÓN ================= */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/worker_errors.log');
error_reporting(E_ALL);

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ================= PARÁMETROS ================= */

$MAX_INTENTOS = 3;
$LIMITE_PROCESO = 5;
$fechaActual = date('Y-m-d H:i:s');

/* ================= OBTENER ENVÍOS PENDIENTES ================= */

$stmt = $pdo->prepare("
    SELECT 
        c.id AS cola_id,
        c.oficio_id,
        c.municipio_destino_id,
        c.intentos,
        o.referencia,
        o.ruta_pdf_final
    FROM cola_envios c
    INNER JOIN oficios o ON o.id = c.oficio_id
    WHERE c.estado IN ('PENDIENTE','ERROR')
      AND c.intentos < ?
    ORDER BY c.fecha_creacion ASC
    LIMIT {$LIMITE_PROCESO}
");

$stmt->execute([$MAX_INTENTOS]);
$pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$pendientes) {
    exit; // Nada que procesar
}

/* ================= PROCESAR CADA ENVÍO ================= */

foreach ($pendientes as $envio) {

    $cola_id   = (int)$envio['cola_id'];
    $oficio_id = (int)$envio['oficio_id'];
    $referencia = $envio['referencia'];
    $email = '';

    try {

        /* ===== Marcar como PROCESANDO ===== */
        $pdo->prepare("
            UPDATE cola_envios
            SET estado='PROCESANDO'
            WHERE id=?
        ")->execute([$cola_id]);

        /* ===== Obtener correo del oficiante ===== */
       $stmtEmail = $pdo->prepare("
    SELECT email 
    FROM oficiantes 
    WHERE municipio_id = ?  -- <--- Cambio aquí: buscar por el campo correcto
    LIMIT 1
");
$stmtEmail->execute([$envio['municipio_destino_id']]);
$email = $stmtEmail->fetchColumn();

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Correo destino inválido o inexistente.");
        }

        /* ===== Validar PDF ===== */
        $pdfPath = realpath(__DIR__ . '/' . ltrim($envio['ruta_pdf_final'], "/\\"));

        if (!$pdfPath || !is_file($pdfPath) || !is_readable($pdfPath)) {
            throw new Exception("Archivo PDF no encontrado o no accesible.");
        }

        /* ===== Configurar PHPMailer ===== */
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'notificaciones.refamssc@gmail.com';
        $mail->Password   = 'eihr tqex vbjt naqf';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('notificaciones.refamssc@gmail.com', 'Registro del Estado Familiar');
        $mail->addAddress($email);

        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);

        /* ===== Imágenes embebidas ===== */
        $mail->addEmbeddedImage(__DIR__ . '/img/logo_correo.png', 'logo_correo');
        $mail->addEmbeddedImage(__DIR__ . '/img/firma_correo.png', 'firma_correo');

        /* ===== Asunto ===== */
        $mail->Subject = 'Envío de Oficio y Partida de Defunción – Ref. ' . $referencia;

        /* ===== Cuerpo HTML institucional ===== */
        $mail->Body = '
        <div style="background-color:#f4f6f8; padding:40px 0; font-family:Arial, Helvetica, sans-serif;">
          <div style="max-width:620px; margin:0 auto; background-color:#ffffff; padding:50px 40px; text-align:center; border-radius:6px;">

            <img src="cid:logo_correo" style="max-width:120px; margin-bottom:25px;">

            <h2 style="margin:30px 0 15px; font-weight:normal; color:#000;">
              Confirmación de Envío de Documentos
            </h2>

            <hr style="width:60px; border:none; border-top:2px solid #e0e0e0; margin:25px auto;">

            <p style="font-size:15px; color:#333; line-height:1.6;">
              Por este medio, se remite adjunto el <strong>Oficio</strong> con referencia
              <strong>' . htmlspecialchars($referencia) . '</strong>,
              junto con la correspondiente <strong>Partida de Defunción</strong>.
            </p>

            <p style="font-size:15px; color:#333; line-height:1.6;">
              Se solicita proceder con la cancelación de la Partida de Nacimiento respectiva.
            </p>

            <p style="margin-top:35px; font-size:15px; color:#333;">
              Atentamente,
            </p>

            <img src="cid:firma_correo" style="max-width:500px; margin:10px 0 5px;">

          </div>

          <p style="text-align:center; font-size:12px; color:#888; margin-top:20px;">
            © Alcaldía de San Salvador Centro
          </p>
        </div>
        ';

        /* ===== Versión texto ===== */
        $mail->AltBody = "Estimado(a) Registrador(a) del Estado Familiar:

Por este medio, se remite adjunto el Oficio con referencia {$referencia}, 
así como la Partida de Defunción, para proceder con la cancelación 
de la correspondiente Partida de Nacimiento.

Registro del Estado Familiar
Alcaldía Municipal de San Salvador Centro";

        $mail->addAttachment($pdfPath, "Oficio_{$referencia}.pdf");

        $mail->send();

        /* ===== Registrar historial ===== */
        $pdo->prepare("
            INSERT INTO oficio_envios
            (oficio_id, email_destino, estado)
            VALUES (?, ?, 'ENVIADO')
        ")->execute([$oficio_id, $email]);

        /* ===== Actualizar cola ===== */
        $pdo->prepare("
            UPDATE cola_envios
            SET estado='COMPLETADO',
                fecha_proceso=?
            WHERE id=?
        ")->execute([$fechaActual, $cola_id]);

        /* ===== Actualizar oficio ===== */
        $pdo->prepare("
            UPDATE oficios
            SET enviado_correo=1,
                fecha_envio=?,
                intentos_envio=intentos_envio+1
            WHERE id=?
        ")->execute([$fechaActual, $oficio_id]);

    } catch (Throwable $e) {

        error_log("Error worker ref={$referencia}: " . $e->getMessage());

        /* ===== Registrar error ===== */
        $pdo->prepare("
            INSERT INTO oficio_envios
            (oficio_id, email_destino, estado, mensaje_error)
            VALUES (?, ?, 'ERROR', ?)
        ")->execute([$oficio_id, $email, $e->getMessage()]);

        /* ===== Actualizar cola ===== */
        $pdo->prepare("
            UPDATE cola_envios
            SET estado='ERROR',
                intentos=intentos+1,
                fecha_proceso=?
            WHERE id=?
        ")->execute([$fechaActual, $cola_id]);
    }
}

exit;
