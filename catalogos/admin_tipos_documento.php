<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../check_session.php';
require_once __DIR__ . '/../db_config.php';

$rol = $_SESSION['user_rol'] ?? 'normal';
$nombre_usuario = $_SESSION['nombre_usuario'] ?? 'Usuario';

if (!in_array($rol, ['administrador', 'supervisor'])) {
    header("Location: ../dashboard.php");
    exit;
}

$msg = "";
/* ===== PROCESAR FORMULARIO ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    // SE ELIMINÓ strtoupper: Ahora guarda tal cual se escribe en el input
    $nombre = trim($_POST['nombre'] ?? '');

    try {
        if ($action === 'nuevo') {
            $pdo->prepare("INSERT INTO tipos_documento (nombre) VALUES (?)")->execute([$nombre]);
        } else if ($action === 'editar') {
            $pdo->prepare("UPDATE tipos_documento SET nombre = ? WHERE id = ?")->execute([$nombre, $id]);
        } else if ($action === 'eliminar') {
            $pdo->prepare("DELETE FROM tipos_documento WHERE id = ?")->execute([$id]);
        }
        $msg = "<div class='alert alert-success shadow-sm'>Operación realizada con éxito.</div>";
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger shadow-sm'>Error: " . $e->getMessage() . "</div>";
    }
}

/* ===== FILTROS Y BÚSQUEDA ===== */
$busqueda = trim((string)filter_input(INPUT_GET, 'q', FILTER_UNSAFE_RAW));
$where = "";
$params = [];

if ($busqueda !== '') {
    $where = " WHERE nombre LIKE ? ";
    $params = ["%$busqueda%"];
}

/* ===== PAGINACIÓN ===== */
$por_pagina = 10;
$pagina = (int)($_GET['p'] ?? 1);
if ($pagina < 1) $pagina = 1;
$offset = ($pagina - 1) * $por_pagina;

$sql_count = "SELECT COUNT(*) FROM tipos_documento" . $where;
$stmt_count = $pdo->prepare($sql_count);
$stmt_count->execute($params);
$total_registros = (int)$stmt_count->fetchColumn();
$total_paginas = (int)ceil($total_registros / $por_pagina);

/* ===== CARGA DE DATOS ===== */
$sql_items = "SELECT * FROM tipos_documento $where ORDER BY nombre ASC LIMIT $por_pagina OFFSET $offset";
$stmt_items = $pdo->prepare($sql_items);
$stmt_items->execute($params);
$items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

/* ===== ESTADÍSTICAS COLA (Para el Badge del Nav) ===== */
try {
    $stats_envios = $pdo->query("SELECT estado, COUNT(*) total FROM cola_envios GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { $stats_envios = []; }

function e($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Admin - Tipos de Documento</title>
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
            
            <?php if (in_array($rol, ['administrador', 'supervisor'])): ?>
                <li class="nav-item dropdown active">
                    <a class="nav-link dropdown-toggle" href="#" id="navAdmin" role="button" data-toggle="dropdown">Administración</a>
                    <div class="dropdown-menu shadow border-0">
                        <h6 class="dropdown-header">Estructura Geográfica</h6>
                        <a class="dropdown-item" href="admin_departamentos.php">Departamentos</a>
                        <a class="dropdown-item" href="admin_municipios.php">Municipios</a>
                        <a class="dropdown-item" href="admin_distritos.php">Distritos</a>
                        <div class="dropdown-divider"></div>
                        <h6 class="dropdown-header">Catálogos de Sistema</h6>
                        <a class="dropdown-item" href="admin_tipos_documento.php">Tipos de Documento</a>
                        <a class="dropdown-item" href="admin_tipos_constancia.php">Tipos de Constancia</a>
                        <a class="dropdown-item" href="admin_soportes.php">Soportes</a>
                        <a class="dropdown-item" href="admin_hospitales.php">Hospitales</a>
                        <a class="dropdown-item" href="admin_oficiantes.php">Oficiantes</a>
                        <div class="dropdown-divider"></div>
                        <h6 class="dropdown-header">Gestión de Personas</h6>
                        <a class="dropdown-item" href="admin_usuarios.php">Usuarios del Sistema</a>
                        <a class="dropdown-item" href="admin_solicitantes.php">Ciudadanos (Solicitantes)</a>
                    </div>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../panel_envios.php">Envíos 
                        <span class="badge badge-danger"><?php echo $stats_envios['ERROR'] ?? 0; ?></span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
        <span class="navbar-text mr-3 text-white">Bienvenido, <strong><?php echo htmlspecialchars($nombre_usuario); ?></strong></span>
        <a href="../logout.php" class="btn btn-outline-light btn-sm">Cerrar Sesión</a>
    </div>
</nav>

<div class="container mb-5">
    <div class="main-card">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4">
            <h4 class="text-primary font-weight-bold mb-3 mb-md-0">Tipos de Documento</h4>
            
            <div class="search-box">
                <form method="GET" class="input-group">
                    <input type="text" name="q" class="form-control" placeholder="Buscar documento..." value="<?php echo e($busqueda); ?>">
                    <div class="input-group-append">
                        <button class="btn btn-primary" type="submit">Buscar</button>
                    </div>
                </form>
            </div>

            <button class="btn btn-success font-weight-bold" onclick="abrirModal()">+ AGREGAR NUEVO</button>
        </div>

        <?php echo $msg; ?>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="bg-light">
                    <tr>
                        <th width="100">ID</th>
                        <th>Descripción del Documento</th>
                        <th width="180" class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($items)): ?>
                        <tr><td colspan="3" class="text-center text-muted p-4">No se encontraron resultados.</td></tr>
                    <?php endif; ?>
                    <?php foreach($items as $i): ?>
                    <tr>
                        <td class="text-muted"><?php echo $i['id']; ?></td>
                        <td class="font-weight-bold"><?php echo e($i['nombre']); ?></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary mr-1" onclick='abrirModal(<?php echo json_encode($i); ?>)'>Editar</button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
                                <input type="hidden" name="action" value="eliminar">
                                <input type="hidden" name="id" value="<?php echo $i['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Borrar</button>
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

<div class="modal fade" id="modalTD" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title font-weight-bold" id="mTitle">Nuevo Tipo de Documento</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" id="action">
                <input type="hidden" name="id" id="id">
                <div class="form-group">
                    <label class="small font-weight-bold">Nombre del Documento:</label>
                    <input type="text" name="nombre" id="nombre" class="form-control" placeholder="Ej: DUI, Pasaporte, etc." required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                <button type="submit" class="btn btn-primary font-weight-bold">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<script src="../bootstrap/js/jquery-3.7.1.min.js"></script>
<script src="../bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    function abrirModal(d=null){
        $('#action').val(d?'editar':'nuevo');
        $('#mTitle').text(d?'Editar Tipo de Documento':'Nuevo Tipo de Documento');
        $('#id').val(d?d.id:'');
        $('#nombre').val(d?d.nombre:'');
        $('#modalTD').modal('show');
    }
</script>
</body>
</html>