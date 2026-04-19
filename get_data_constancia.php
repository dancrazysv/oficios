<?php
declare(strict_types=1);

session_start();
require_once __DIR__.'/db_config.php';

/* ================= CONFIGURACIÓN ERRORES ================= */
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log',__DIR__.'/logs/php_errors.log');
error_reporting(E_ALL);

/* ================= RESPUESTA JSON ================= */
function json_response($data, $status = 200){
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ================= RESPUESTA HTML ================= */
function html_response($html, $status = 200){
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

/* ================= VALIDAR CSRF ================= */
function csrf_valid(){
    if(!isset($_SESSION['csrf_token'])) return false;
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals($_SESSION['csrf_token'], $token);
}

$action = $_POST['action'] ?? '';

if(!$action){
    json_response(['success' => false, 'message' => 'Acción no especificada'], 400);
}

/* ================= HOSPITALES ================= */
if($action === 'get_hospitales'){
    if(!csrf_valid()) html_response('<option value="">CSRF inválido</option>', 403);
    try {
        $stmt = $pdo->query("SELECT nombre FROM hospitales ORDER BY nombre");
        $options = '';
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $h){
            $nombre = htmlspecialchars($h['nombre'], ENT_QUOTES, 'UTF-8');
            $options .= "<option value=\"$nombre\">";
        }
        html_response($options);
    } catch(PDOException $e) {
        error_log($e->getMessage());
        html_response('<option value="">Error cargando hospitales</option>', 200);
    }
}

/* ================= DEPARTAMENTOS ================= */
if($action === 'get_departamentos'){
    if(!csrf_valid()) json_response(['success' => false, 'message' => 'CSRF inválido'], 403);
    try {
        $stmt = $pdo->query("SELECT id, nombre FROM departamentos ORDER BY nombre");
        json_response(['success' => true, 'departamentos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(PDOException $e) {
        error_log($e->getMessage());
        json_response(['success' => false, 'message' => 'Error en DB'], 200);
    }
}

/* ================= MUNICIPIOS ================= */
if($action === 'get_municipios'){
    if(!csrf_valid()) json_response(['success' => false, 'message' => 'CSRF inválido'], 403);
    $depto_id = intval($_POST['depto_id'] ?? 0);
    if(!$depto_id) json_response(['success' => false, 'municipios' => []], 200);
    try {
        $stmt = $pdo->prepare("SELECT id, nombre FROM municipios WHERE departamento_id = ? ORDER BY nombre ASC");
        $stmt->execute([$depto_id]);
        json_response(['success' => true, 'municipios' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(PDOException $e) {
        json_response(['success' => false, 'municipios' => []], 200);
    }
}

/* ================= DISTRITOS ================= */
if($action === 'get_distritos'){
    if(!csrf_valid()) json_response(['success' => false, 'message' => 'CSRF inválido'], 403);
    $municipio_id = intval($_POST['municipio_id'] ?? 0);
    if(!$municipio_id) json_response(['success' => false, 'distritos' => []], 200);
    try {
        $stmt = $pdo->prepare("SELECT id, nombre FROM distritos WHERE municipio_id = ? ORDER BY nombre ASC");
        $stmt->execute([$municipio_id]);
        json_response(['success' => true, 'distritos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(PDOException $e) {
        json_response(['success' => false, 'distritos' => []], 200);
    }
}

/* ================= BUSCAR SOLICITANTE ================= */
if($action === 'buscar_solicitante'){
    if(!csrf_valid()) json_response(['success' => false, 'message' => 'CSRF inválido'], 403);

    // Eliminamos solo espacios, mantenemos el guion tal cual viene del cliente
    $num_busqueda = trim((string)($_POST['numero_documento'] ?? ''));

    if(!$num_busqueda){
        json_response(['success' => false, 'message' => 'Ingrese un documento'], 200);
    }

    try {
        $stmt = $pdo->prepare("SELECT nombre_completo, correo_electronico, telefono 
                               FROM solicitantes 
                               WHERE numero_documento_limpio = ? 
                               LIMIT 1");
        
        $stmt->execute([$num_busqueda]);
        $sol = $stmt->fetch(PDO::FETCH_ASSOC);

        if($sol){
            json_response([
                'success' => true,
                'registrado' => true,
                'nombre' => $sol['nombre_completo'],
                'correo' => $sol['correo_electronico'] ?? '',
                'telefono' => $sol['telefono'] ?? ''
            ]);
        }

        json_response(['success' => false, 'registrado' => false, 'message' => 'No encontrado']);
        
    } catch(PDOException $e) {
        json_response(['success' => false, 'message' => 'Error en base de datos'], 200);
    }
}

/* ================= REGISTRAR SOLICITANTE ================= */
if($action === 'registrar_solicitante'){
    if(!csrf_valid()) json_response(['success' => false, 'message' => 'CSRF inválido'], 403);
    if(empty($_SESSION['user_id'])) json_response(['success' => false, 'message' => 'No autorizado'], 403);

    $nombre = strtoupper(trim((string)($_POST['nombre'] ?? '')));
    $tipo_documento_id = intval($_POST['tipo_documento_id'] ?? 0);
    
    // CORRECCIÓN RADICAL: Tomamos el número tal cual viene, sin expresiones regulares que borren nada.
    // Solo quitamos espacios adicionales al inicio/final.
    $numero_documento = trim((string)($_POST['numero_documento'] ?? ''));

    if(!$nombre || !$tipo_documento_id || !$numero_documento){
        json_response(['success' => false, 'message' => 'Datos incompletos'], 200);
    }

    try {
        $check = $pdo->prepare("SELECT id FROM solicitantes WHERE numero_documento_limpio = ? LIMIT 1");
        $check->execute([$numero_documento]);
        if($check->fetch()){
            json_response(['success' => false, 'message' => 'Este ciudadano ya se encuentra registrado.'], 200);
        }

        $stmt = $pdo->prepare("INSERT INTO solicitantes (nombre_completo, tipo_documento_id, numero_documento_limpio) VALUES (?, ?, ?)");
        $stmt->execute([$nombre, $tipo_documento_id, $numero_documento]);

        json_response(['success' => true, 'nombre' => $nombre]);
    } catch(PDOException $e) {
        error_log($e->getMessage());
        json_response(['success' => false, 'message' => 'Error al guardar el ciudadano'], 200);
    }
}

json_response(['success' => false, 'message' => 'Acción no reconocida'], 200);