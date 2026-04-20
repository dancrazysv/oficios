<?php
declare(strict_types=1);
session_start();

// Verificación de sesión y rol de administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'administrador') {
    header("Location: ../login.php"); 
    exit;
}

// CORRECCIÓN DE RUTA: Subimos un nivel para encontrar el config
require_once __DIR__ . '/../db_config.php';

$rol_sesion = $_SESSION['user_rol'] ?? 'normal';
$nombre_usuario_sesion = $_SESSION['nombre_usuario'] ?? 'Usuario';

$success = "";
$error = "";

/* ===== PROCESAR FORMULARIO ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user = trim($_POST['usuario'] ?? '');
    $rol = $_POST['rol'] ?? 'normal';
    
    // SE ELIMINÓ strtoupper: Ahora guarda tal cual se recibe
    $area = trim($_POST['area'] ?? '');
    $nombre = trim($_POST['nombre_completo'] ?? '');
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    try {
        if ($action === 'nuevo') {
            $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO usuarios (usuario, contrasena, rol, area, nombre_completo) VALUES (?,?,?,?,?)");
            $stmt->execute([$user, $pass, $rol, $area, $nombre]);
            $success = "Usuario creado correctamente.";
        } elseif ($action === 'editar') {
            if (!empty($_POST['password'])) {
                $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE usuarios SET usuario=?, contrasena=?, rol=?, area=?, nombre_completo=? WHERE id=?")
                    ->execute([$user, $pass, $rol, $area, $nombre, $id]);
            } else {
                $pdo->prepare("UPDATE usuarios SET usuario=?, rol=?, area=?, nombre_completo=? WHERE id=?")
                    ->execute([$user, $rol, $area, $nombre, $id]);
            }
            $success = "Usuario actualizado correctamente.";
        } elseif ($action === 'eliminar') {
            if ($id === (int)$_SESSION['user_id']) {
                $error = "No puedes eliminar tu propia cuenta.";
            } else {
                $pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
                $success = "Usuario eliminado.";
            }
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

/* ===== FILTROS Y BÚSQUEDA ===== */
$busqueda = trim((string)filter_input(INPUT_GET, 'q', FILTER_UNSAFE_RAW));
$where = "";
$params = [];

if ($busqueda !== '') {
    $where = " WHERE nombre_completo LIKE ? OR usuario LIKE ? OR area LIKE ? ";
    $params = ["%$busqueda%", "%$busqueda%", "%$busqueda%"];
}

/* ===== PAGINACIÓN ===== */
$por_pagina = 10;
$pagina = (int)($_GET['p'] ?? 1);
if ($pagina < 1) $pagina = 1;
$offset = ($pagina - 1) * $por_pagina;

$sql_count = "SELECT COUNT(*) FROM usuarios" . $where;
$stmt_count = $pdo->prepare($sql_count);
$stmt_count->execute($params);
$total_registros = (int)$stmt_count->fetchColumn();
$total_paginas = (int)ceil($total_registros / $por_pagina);

/* ===== CARGA DE DATOS ===== */
$sql_items = "SELECT * FROM usuarios $where ORDER BY nombre_completo ASC LIMIT $por_pagina OFFSET $offset";
$stmt_items = $pdo->prepare($sql_items);
$stmt_items->execute($params);
$usuarios = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

/* ===== ESTADÍSTICAS COLA ===== */
try {
    $stats_envios = $pdo->query("SELECT estado, COUNT(*) total FROM cola_envios GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { $stats_envios = []; }

function e($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Usuarios</title>
    <link rel="stylesheet" href="../bootstrap/css/bootstrap.min.css">
    <style>
        body{background:#f8f9fa; font-family:'Segoe UI',Tahoma,Verdana;}
        .main-card{background:#fff;padding:25px;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.08)}
        .navbar { z-index: 1050; }
        .search-box { max-width: 400px; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow mb-4">
    <a class="navbar-brand font-weight-bold" href="../dashboard.php">Sistema de Trámites</a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav mr-auto">
            <li class="nav-item"><a class="nav-link" href="../crear_oficio.php">Crear Oficio</a></li>
            <li class="nav-item"><a class="nav-link" href="../crear_constancia.php">Crear Certificación</a></li>
            <li class="nav-item"><a class="nav-link" href="../dashboard.php">Dashboard</a></li>
            
            <li class="nav-item dropdown active">
                <a class="nav-link dropdown-toggle" href="#" id="navAdmin" role="button" data-toggle="dropdown">Administración</a>
                <div class="dropdown-menu shadow border-0">
                    <h6 class="dropdown-header">Estructura Geográfica</h6>
                    <a class="dropdown-item" href="admin_departamentos.php">Departamentos</a>
                    <a class="dropdown-item" href="admin_municipios.php">Municipios</a>
                    <a class="dropdown-item" href="admin_distritos.php">Distritos</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="admin_tipos_documento.php">Tipos de Documento</a>
                    <a class="dropdown-item" href="admin_tipos_constancia.php">Tipos de Constancia</a>
                    <a class="dropdown-item" href="admin_soportes.php">Soportes</a>
                    <a class="dropdown-item" href="admin_hospitales.php">Hospitales</a>
                    <a class="dropdown-item" href="admin_oficiantes.php">Oficiantes</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item active" href="admin_usuarios.php">Usuarios del Sistema</a>
                    <a class="dropdown-item" href="admin_solicitantes.php">Ciudadanos (Solicitantes)</a>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="../panel_envios.php">Envíos 
                    <span class="badge badge-danger"><?php echo $stats_envios['ERROR'] ?? 0; ?></span>
                </a>
            </li>
        </ul>
        <span class="navbar-text mr-3 text-white">Bienvenido, <strong><?php echo e($nombre_usuario_sesion); ?></strong></span>
        <a href="../logout.php" class="btn btn-outline-light btn-sm">Cerrar Sesión</a>
    </div>
</nav>

<div class="container mb-5">
    <div class="main-card">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4">
            <h4 class="text-primary font-weight-bold mb-3 mb-md-0">Gestión de Usuarios</h4>
            
            <div class="search-box">
                <form method="GET" class="input-group">
                    <input type="text" name="q" class="form-control" placeholder="Buscar por nombre, usuario o área..." value="<?php echo e($busqueda); ?>">
                    <div class="input-group-append">
                        <button class="btn btn-primary" type="submit">Buscar</button>
                    </div>
                </form>
            </div>

            <button class="btn btn-success font-weight-bold" onclick="modalUser()">+ NUEVO USUARIO</button>
        </div>

        <?php if($success): ?> <div class="alert alert-success shadow-sm"><?php echo $success; ?></div> <?php endif; ?>
        <?php if($error): ?> <div class="alert alert-danger shadow-sm"><?php echo $error; ?></div> <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="bg-light">
                    <tr>
                        <th>Nombre Completo</th>
                        <th>Usuario</th>
                        <th>Rol</th>
                        <th>Área</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($usuarios as $u): ?>
                    <tr>
                        <td class="font-weight-bold"><?php echo e($u['nombre_completo']); ?></td>
                        <td><code><?php echo e($u['usuario']); ?></code></td>
                        <td><span class="badge badge-info text-uppercase"><?php echo e($u['rol']); ?></span></td>
                        <td><?php echo e($u['area']); ?></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary" onclick='modalUser(<?php echo json_encode($u); ?>)'>Editar</button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
                                <input type="hidden" name="action" value="eliminar">
                                <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger ml-1">Borrar</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if($total_paginas > 1): ?>
        <nav class="mt-4"><ul class="pagination justify-content-center">
            <?php $params_p = ($busqueda !== '') ? "&q=".urlencode($busqueda) : ""; ?>
            <li class="page-item <?php echo ($pagina <= 1) ? 'disabled' : ''; ?>"><a class="page-link" href="?p=<?php echo ($pagina-1).$params_p; ?>">Anterior</a></li>
            <?php for($x=1; $x<=$total_paginas; $x++): ?>
                <li class="page-item <?php echo ($x === $pagina) ? 'active' : ''; ?>"><a class="page-link" href="?p=<?php echo $x.$params_p; ?>"><?php echo $x; ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?php echo ($pagina >= $total_paginas) ? 'disabled' : ''; ?>"><a class="page-link" href="?p=<?php echo ($pagina+1).$params_p; ?>">Siguiente</a></li>
        </ul></nav>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="modalUsuario" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title font-weight-bold" id="mTitle">Nuevo Usuario</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" id="mAction">
                <input type="hidden" name="id" id="mId">
                <div class="form-group mb-3">
                    <label class="small font-weight-bold">Nombre Completo:</label>
                    <input type="text" name="nombre_completo" id="mNombre" class="form-control" placeholder="Nombre completo" required>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="small font-weight-bold">Usuario (Login):</label>
                        <input type="text" name="usuario" id="mUser" class="form-control" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="small font-weight-bold">Rol:</label>
                        <select name="rol" id="mRol" class="form-control">
                            <option value="normal">Normal (Operador)</option>
                            <option value="supervisor">Supervisor</option>
                            <option value="administrador">Administrador</option>
                        </select>
                    </div>
                </div>
                <div class="form-group mb-3">
                    <label class="small font-weight-bold">Área / Departamento:</label>
                    <input type="text" name="area" id="mArea" class="form-control" placeholder="Ej: Registro Familiar">
                </div>
                <div class="form-group mb-3">
                    <label class="small font-weight-bold">Contraseña:</label>
                    <input type="password" name="password" id="mPass" class="form-control" placeholder="Dejar en blanco para no cambiar">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                <button type="submit" class="btn btn-primary font-weight-bold">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script src="../bootstrap/js/jquery-3.7.1.min.js"></script>
<script src="../bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    function modalUser(u=null){
        if(u){
            $('#mTitle').text('Editar Usuario');
            $('#mAction').val('editar');
            $('#mId').val(u.id);
            $('#mNombre').val(u.nombre_completo);
            $('#mUser').val(u.usuario);
            $('#mRol').val(u.rol);
            $('#mArea').val(u.area);
            $('#mPass').attr('required', false);
        } else {
            $('#mTitle').text('Nuevo Usuario');
            $('#mAction').val('nuevo');
            $('#mId').val('');
            $('#mNombre').val('');
            $('#mUser').val('');
            $('#mRol').val('normal');
            $('#mArea').val('');
            $('#mPass').val('').attr('required', true);
        }
        $('#modalUsuario').modal('show');
    }
</script>
</body>
</html>