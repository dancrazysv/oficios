<?php
// enviar_certificacion_correo.php
// Endpoint para enviar una constancia por correo usando SMTP (Gmail) con PHPMailer.
// Devuelve JSON { success: bool, message: string }

// Requisitos:
// 1) Instalar dependencias con Composer:
//    composer require phpmailer/phpmailer
// Opcional (si prefieres .env):
//    composer require vlucas/phpdotenv
//
// 2) Configurar variables de entorno en el servidor:
//    SMTP_HOST (por defecto smtp.gmail.com)
//    SMTP_PORT (por defecto 587)
//    SMTP_USER (ej: hdezd2499@gmail.com)
//    SMTP_PASSWORD (tu contraseña o app password)
//    MAIL_FROM (opcional, por defecto SMTP_USER)
//    MAIL_FROM_NAME (opcional)
//
// IMPORTANTE: No guardes credenciales en el repo. Usa variables de entorno o un .env fuera del control de versiones.

header('Content-Type: application/json');

require 'check_session.php';
require 'db_config.php';

// Cargar autoload de Composer
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Si usas phpdotenv (opcional), carga .env si existe
if (file_exists(__DIR__ . '/.env')) {
    // solo si instalaste vlucas/phpdotenv
    if (class_exists('Dotenv\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->safeLoad();
    }
}

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// Verificar sesión
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autenticado']);
    exit;
}

// Obtener datos POST
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$email_input = trim($_POST['email'] ?? '');

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

try {
    // Obtener información de la constancia
    $stmt = $pdo->prepare("SELECT c.id, c.nombre_solicitante, c.solicitante_email, c.ruta_pdf_final FROM constancias c WHERE c.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Constancia no encontrada']);
        exit;
    }

    // Destinatario: email del modal si se proporcionó, si no usar el email almacenado
    $destino = $email_input !== '' ? $email_input : ($row['solicitante_email'] ?? '');

    if (empty($destino)) {
        echo json_encode(['success' => false, 'message' => 'No se encontró correo del destinatario.']);
        exit;
    }

    // Cargar configuración SMTP desde variables de entorno
    $smtpHost = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $smtpPort = getenv('SMTP_PORT') ?: 587;
    $smtpUser = getenv('SMTP_USER') ?: 'hdezd2499@gmail.com';
    $smtpPass = getenv('SMTP_PASSWORD') ?: 'oeqt ripp ktjg pixo';
    $mailFrom = getenv('MAIL_FROM') ?: $smtpUser;
    $mailFromName = getenv('MAIL_FROM_NAME') ?: 'Registro del Estado Familiar';

    if (empty($smtpUser) || empty($smtpPass)) {
        echo json_encode(['success' => false, 'message' => 'Configuración SMTP incompleta en variables de entorno.']);
        exit;
    }

    // Construir asunto y cuerpo
    $subject = "Envío de Certificación - ID {$row['id']}";
    $pdfUrlText = $row['ruta_pdf_final'] ?? '';
    $bodyText = "Estimado(a) {$row['nombre_solicitante']},\n\nAdjuntamos su certificación. Si no ve el adjunto, descargue el documento desde: \n" . ($pdfUrlText ?: '(PDF no disponible)') . "\n\nAtentamente,\nRegistro del Estado Familiar";

    // Inicializar PHPMailer
    $mail = new PHPMailer(true);

    try {
        // Configuración SMTP
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        // Gmail: TLS en 587 o SSL en 465. Aquí usamos TLS/STARTTLS por defecto.
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int)$smtpPort;

        // Remitente y destinatario
        $mail->setFrom($mailFrom, $mailFromName);
        $mail->addAddress($destino, $row['nombre_solicitante']);

        // Si tienes copia administrativa
        $adminCopy = getenv('MAIL_ADMIN_COPY') ?: '';
        if (!empty($adminCopy)) {
            $mail->addBCC($adminCopy);
        }

        // Adjuntar PDF si existe ruta y archivo disponible localmente
        $attached = false;
        if (!empty($row['ruta_pdf_final'])) {
            $pdfPath = $row['ruta_pdf_final'];
            // Si es una URL pública, no podemos adjuntar por archivo local; en ese caso dejar el link en el cuerpo.
            if (filter_var($pdfPath, FILTER_VALIDATE_URL)) {
                // incluir enlace en el cuerpo
                $body = nl2br(htmlspecialchars($bodyText)) . "<p>Descargar: <a href=\"" . htmlspecialchars($pdfPath) . "\">PDF</a></p>";
                $mail->isHTML(true);
            } else {
                // ruta de archivo en servidor
                if (file_exists($pdfPath) && is_readable($pdfPath)) {
                    $mail->addAttachment($pdfPath);
                    $attached = true;
                    $mail->isHTML(false);
                    $body = $bodyText . "\n\n(Adjunto incluido)";
                } else {
                    // archivo no encontrado, usar link si corresponde
                    $body = $bodyText . "\n\n(Archivo PDF no disponible para adjuntar)";
                    $mail->isHTML(false);
                }
            }
        } else {
            $body = $bodyText;
            $mail->isHTML(false);
        }

        // Contenido
        $mail->Subject = $subject;
        $mail->Body = $body;

        // Enviar
        if ($mail->send()) {
            // Marcar en BD que se envió correo
            $update = $pdo->prepare("UPDATE constancias SET enviado_correo = 1 WHERE id = ?");
            $update->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Correo enviado correctamente.']);
            exit;
        } else {
            // PHPMailer returns false but rarely reaches here due to exceptions
            error_log("PHPMailer send() returned false for constancia {$id}");
            echo json_encode(['success' => false, 'message' => 'Fallo al enviar correo (PHPMailer). Revisa logs.']);
            exit;
        }
    } catch (Exception $e) {
        // Error SMTP / PHPMailer
        error_log("PHPMailer Exception: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error al enviar correo: ' . $e->getMessage()]);
        exit;
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
    exit;
}