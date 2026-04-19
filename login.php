<?php
// ¡DEBE SER LA PRIMERA LÍNEA!
ob_start();

// Endurecer cookies de sesión antes de iniciar la sesión
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

require_once 'csrf.php';
require_once 'db_config.php';

$mensaje_error = '';

// Redirige si ya está logueado
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $usuario    = trim($_POST['usuario'] ?? '');
    $contrasena = $_POST['contrasena'] ?? '';
    $token      = $_POST['csrf_token'] ?? '';

    // Validación CSRF
    if (!verify_csrf($token)) {

        $mensaje_error = "Sesión no válida, por favor recarga la página e inténtalo de nuevo.";

    } elseif (!empty($usuario) && !empty($contrasena)) {

        try {

            // ⭐ SOLO agregamos el campo area
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    contrasena,
                    rol,
                    nombre_completo,
                    area
                FROM usuarios
                WHERE usuario = ?
                LIMIT 1
            ");

            $stmt->execute([$usuario]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($contrasena, $user['contrasena'])) {

                // Mitigar fijación de sesión
                session_regenerate_id(true);

                $_SESSION['user_id']        = (int)$user['id'];
                $_SESSION['user_rol']       = $user['rol'];
                $_SESSION['nombre_usuario'] = $user['nombre_completo'];

                // ⭐ NECESARIO para el filtro del supervisor
                $_SESSION['area']           = $user['area'] ?? '';

                ob_end_clean();
                header('Location: dashboard.php');
                exit();

            } else {
                $mensaje_error = "Usuario o contraseña incorrectos.";
            }

        } catch (PDOException $e) {

            error_log('DB login error: ' . $e->getMessage());
            $mensaje_error = "Error interno. Intente más tarde o contacte al administrador.";
        }

    } else {
        $mensaje_error = "Por favor, complete todos los campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Iniciar Sesión</title>
    <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 20px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .logo-img {
            display: block;
            margin: 0 auto 20px;
            max-width: 100px;
            height: auto;
        }
    </style>
</head>
<body>

<div class="login-container">
    <img src="img/escudo.png" alt="Escudo Institucional" class="logo-img">

    <h2 class="text-center mb-4">Sistema de Oficios y Certificaciones</h2>

    <?php if (!empty($mensaje_error)): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($mensaje_error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

        <div class="form-group">
            <label for="usuario">Usuario</label>
            <input type="text" class="form-control" id="usuario" name="usuario" required autocomplete="username">
        </div>

        <div class="form-group">
            <label for="contrasena">Contraseña</label>
            <input type="password" class="form-control" id="contrasena" name="contrasena" required autocomplete="current-password">
        </div>

        <button type="submit" class="btn btn-primary btn-block">Ingresar</button>
    </form>
</div>

</body>
</html>
<?php ob_end_flush(); ?>
