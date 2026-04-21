<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/db_config.php';

$rol = $_SESSION['user_rol'] ?? 'normal';
$nombre_usuario = $_SESSION['nombre_usuario'] ?? 'Usuario';
$area_usuario = $_SESSION['area'] ?? '';

try {
    $stmt_stats = $pdo->query("SELECT estado, COUNT(*) as total FROM cola_envios GROUP BY estado");
    $stats_envios = $stmt_stats->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $stats_envios = [];
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

try {
    $stmt_doc = $pdo->query("SELECT id, nombre FROM tipos_documento ORDER BY nombre");
    $tipos_documento = $stmt_doc->fetchAll(PDO::FETCH_ASSOC);

    $stmt_const = $pdo->query("SELECT id, nombre FROM tipos_constancia ORDER BY nombre ASC");
    $tipos_constancia = $stmt_const->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error crítico al cargar catálogos: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Sistema de Constancias | San Salvador Centro</title>
    <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
    <style>
        body{ background:#f8f9fa; font-family:'Segoe UI',Tahoma,Verdana; }
        .main-card{ background:#fff; padding:30px; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,.08); margin-top:30px; }
        .section-title{ border-bottom:2px solid #007bff; padding-bottom:10px; margin-bottom:20px; color:#007bff; font-weight:bold; }
        #numero_documento { font-weight: bold; font-size: 1.1rem; }
    </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow mb-4">
    <a class="navbar-brand font-weight-bold" href="dashboard.php">Sistema de Trámites</a>
    <div class="collapse navbar-collapse">
        <ul class="navbar-nav mr-auto">
            <li class="nav-item"><a class="nav-link" href="crear_oficio.php">Crear Oficio</a></li>
            <li class="nav-item active"><a class="nav-link" href="crear_constancia.php">Crear Certificación</a></li>
            <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
            <?php if (in_array($rol, ['administrador','supervisor'])): ?>
                <li class="nav-item">
                    <a class="nav-link" href="panel_envios.php">Panel Envíos <span class="badge badge-danger"><?php echo $stats_envios['ERROR'] ?? 0; ?></span></a>
                </li>
            <?php endif; ?>
        </ul>
        <span class="navbar-text mr-3 text-white">Bienvenido, <strong><?php echo htmlspecialchars($nombre_usuario); ?></strong></span>
        <a href="logout.php" class="btn btn-outline-light btn-sm">Cerrar Sesión</a>
    </div>
</nav>

<div class="container mb-5">
    <div class="main-card">
        <h3 class="text-center mb-4">Registro del Estado Familiar</h3>
        <form id="formGenerar">
            <input type="hidden" id="csrf_token" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <input type="hidden" name="nac_municipio_nombre" id="nac_municipio_nombre">
            <input type="hidden" name="nac_departamento_nombre" id="nac_departamento_nombre">
            <input type="hidden" name="nac_distrito_nombre" id="nac_distrito_nombre">

            <input type="hidden" name="def_departamento_nombre" id="def_departamento_nombre">
            <input type="hidden" name="def_municipio_nombre" id="def_municipio_nombre">
            <input type="hidden" name="def_distrito_nombre" id="def_distrito_nombre">

            <div class="form-group">
                <label><strong>Tipo de Constancia</strong></label>
                <select class="form-control form-control-lg" id="tipo_constancia" name="tipo_constancia_id" required>
                    <option value="">Seleccione el tipo de trámite...</option>
                    <?php foreach($tipos_constancia as $tc): ?>
                        <option value="<?php echo $tc['id']; ?>"><?php echo htmlspecialchars($tc['nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="p-4 border rounded bg-light mb-4 shadow-sm">
                <h5 class="section-title">Datos del Solicitante</h5>
                <div class="form-row">
                    <div class="col-md-4">
                        <select class="form-control" name="tipo_documento_id" id="tipo_documento_id" required>
                            <option value="">Tipo Documento</option>
                            <?php foreach($tipos_documento as $t): ?>
                                <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <input type="text" class="form-control" name="numero_documento" id="numero_documento" placeholder="Número de Documento" required>
                    </div>
                    <div class="col-md-3">
                        <button type="button" class="btn btn-primary btn-block" id="btnBuscarSolicitante">Buscar</button>
                    </div>
                </div>
                <div class="mt-3">
                    <label>Nombre del Ciudadano</label>
                    <input type="text" class="form-control font-weight-bold" name="nombre_solicitante" id="nombre_solicitante" readonly required>
                    <small id="msg_busqueda"></small>
                    <button type="button" class="btn btn-warning btn-sm mt-2" id="btnRegistrarSolicitante" style="display:none">Registrar Ciudadano</button>
                </div>
            </div>

            <div id="contenedor_constancia"></div>

            <button type="submit" class="btn btn-success btn-block btn-lg mt-4 shadow font-weight-bold" id="btnFinalizar">GENERAR DOCUMENTO PDF</button>
        </form>
    </div>
</div>

<datalist id="lista_hospitales"></datalist>

<script src="bootstrap/js/jquery-3.7.1.min.js"></script>
<script src="bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function(){
    const csrf = $('#csrf_token').val();

    function limitarFechasActuales() {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hoy = `${year}-${month}-${day}`;
        $('input[type="date"]').attr('max', hoy);
    }

    $(document).on('input', '#nombre_solicitante, .text-uppercase', function() {
        this.value = this.value.toUpperCase();
    });

    $('#numero_documento').on('input', function() {
        let textoDoc = $('#tipo_documento_id option:selected').text().toUpperCase();
        if (textoDoc.includes('DUI')) {
            let val = $(this).val().replace(/\D/g, '');
            if (val.length > 8) { val = val.substring(0, 8) + '-' + val.substring(8, 9); }
            $(this).val(val.substring(0, 10));
        }
    });

    // 4. BÚSQUEDA DE SOLICITANTE (MANTENIENDO EL GUION)
    $('#btnBuscarSolicitante').click(function(){
        let tipo = $('#tipo_documento_id').val();
        // CORRECCIÓN: Se eliminó .replace(/-/g, '') para permitir el guion
        let num = $('#numero_documento').val().trim(); 
        if(!tipo || !num){ alert("Complete los datos de búsqueda"); return; }
        $.ajax({
            url: 'get_data_constancia.php',
            method: 'POST',
            dataType: 'json',
            data: { action: 'buscar_solicitante', tipo_documento_id: tipo, numero_documento: num, csrf_token: csrf },
            success: function(r){
                if(r.success){
                    $('#nombre_solicitante').val(r.nombre).prop('readonly', true);
                    $('#msg_busqueda').html('<span class="text-success">✔ Ciudadano encontrado</span>');
                    $('#btnRegistrarSolicitante').hide();
                } else {
                    $('#nombre_solicitante').val('').prop('readonly', false).focus();
                    $('#msg_busqueda').html('<span class="text-danger">Ciudadano no registrado</span>');
                    $('#btnRegistrarSolicitante').show();
                }
            }
        });
    });

    // 5. REGISTRO DE SOLICITANTE (MANTENIENDO EL GUION)
    $('#btnRegistrarSolicitante').click(function(){
        let nombre = $('#nombre_solicitante').val().trim();
        let tipo = $('#tipo_documento_id').val();
        // CORRECCIÓN: Se eliminó .replace(/-/g, '') para permitir el guion
        let num = $('#numero_documento').val().trim();
        if(!nombre){ alert("Debe ingresar el nombre"); return; }
        $.ajax({
            url: 'get_data_constancia.php',
            method: 'POST',
            dataType: 'json',
            data: { action: 'registrar_solicitante', nombre: nombre, tipo_documento_id: tipo, numero_documento: num, csrf_token: csrf },
            success: function(r){
                if(r.success){
                    $('#nombre_solicitante').prop('readonly', true);
                    $('#btnRegistrarSolicitante').hide();
                    $('#msg_busqueda').html('<span class="text-success">✔ Ciudadano registrado con éxito</span>');
                } else { alert(r.message); }
            }
        });
    });

    $('#tipo_constancia').change(function(){
        let tipo = $(this).val();
        if(!tipo){ $('#contenedor_constancia').html(''); return; }
        $('#contenedor_constancia').load('forms_constancia/form_'+tipo.toLowerCase()+'.php', function(){
            limitarFechasActuales();
            $.post('get_data_constancia.php', {action:'get_hospitales', csrf_token:csrf}, function(data){
                $('#lista_hospitales').html(data);
            });
        });
    });

    function isTerritorioExtranjero($select) {
        return $.trim($select.find('option:selected').text()).toLowerCase() === 'territorio extranjero';
    }

    function applyTerritorioRule(prefix) {
        const $dep  = $('#' + prefix + '_departamento_id');
        const $muni = $('#' + prefix + '_municipio_id');
        const $dist = prefix === 'nac' ? $('#nac_distrito_nacimiento_id') : $('#def_distrito_id');
        const $muniNombre = $('#' + prefix + '_municipio_nombre');
        const $distNombre = $('#' + prefix + '_distrito_nombre');
        const territorio = isTerritorioExtranjero($dep);

        if (territorio) {
            $muni.val('').html('<option value="">Seleccione Municipio</option>').prop('disabled', true).prop('required', false);
            $dist.val('').html('<option value="">Seleccione Distrito</option>').prop('disabled', true).prop('required', false);
            $muniNombre.val('');
            $distNombre.val('');
            return true;
        }

        $muni.prop('disabled', false).prop('required', true);
        $dist.prop('required', true);
        return false;
    }

    $(document).on('change','#nac_departamento_id',function(){
        let depId = $(this).val();
        $('#nac_departamento_nombre').val($("#nac_departamento_id option:selected").text());
        if (applyTerritorioRule('nac')) return;
        $.post('get_data_constancia.php',{ action:'get_municipios', depto_id:depId, csrf_token:csrf },function(r){
            if(r.success){
                let h='<option value="">Seleccione Municipio</option>';
                r.municipios.forEach(m=>{ h+=`<option value="${m.id}">${m.nombre}</option>`; });
                $('#nac_municipio_id').html(h).prop('disabled',false);
            }
        },'json');
    });

    $(document).on('change','#nac_municipio_id',function(){
        let muniId = $(this).val();
        $('#nac_municipio_nombre').val($("#nac_municipio_id option:selected").text());
        $.post('get_data_constancia.php',{ action:'get_distritos', municipio_id:muniId, csrf_token:csrf },function(r){
            if(r.success){
                let h='<option value="">Seleccione Distrito</option>';
                r.distritos.forEach(d=>{ h+=`<option value="${d.id}">${d.nombre}</option>`; });
                $('#nac_distrito_nacimiento_id').html(h).prop('disabled',false);
            }
        },'json');
    });

    $(document).on('change', '#nac_distrito_nacimiento_id', function(){ 
        $('#nac_distrito_nombre').val($("#nac_distrito_nacimiento_id option:selected").text()); 
    });

    $(document).on('change', '#def_departamento_id', function() {
        const deptoId = $(this).val();
        $('#def_departamento_nombre').val($("#def_departamento_id option:selected").text());
        if (applyTerritorioRule('def')) return;
        $.post('get_data_constancia.php', { action: 'get_municipios', depto_id: deptoId, csrf_token: csrf }, function(r) {
            if (r.success) {
                let options = '<option value="">Seleccione Municipio</option>';
                r.municipios.forEach(m => { options += `<option value="${m.id}">${m.nombre}</option>`; });
                $('#def_municipio_id').html(options).prop('disabled', false);
            }
        }, 'json');
    });

    $(document).on('change', '#def_municipio_id', function() {
        const muniId = $(this).val();
        $('#def_municipio_nombre').val($("#def_municipio_id option:selected").text());
        $.post('get_data_constancia.php', { action: 'get_distritos', municipio_id: muniId, csrf_token: csrf }, function(r) {
            if (r.success) {
                let options = '<option value="">Seleccione Distrito</option>';
                r.distritos.forEach(d => { options += `<option value="${d.id}">${d.nombre}</option>`; });
                $('#def_distrito_id').html(options).prop('disabled', false);
            }
        }, 'json');
    });

    $(document).on('change', '#def_distrito_id', function() {
        $('#def_distrito_nombre').val($("#def_distrito_id option:selected").text());
    });

    $(document).on('change','#nac_es_exterior', function() {
        applyTerritorioRule('nac');
    });

    $(document).on('change','#def_es_exterior', function() {
        applyTerritorioRule('def');
    });

    $(document).on('change','#def_check_madre', function() { $('#def_contenedor_madre').toggle(this.checked); });
    $(document).on('change','#def_check_padre', function() { $('#def_contenedor_padre').toggle(this.checked); });
    $(document).on('change','#nac_check_padre', function() { $('#nac_contenedor_padre').toggle(this.checked); });

    $(document).on('change','#def_tipo_soporte',function(){
        if(['certificado_hosp','constancia_cert'].includes($(this).val())){ $('#def_contenedor_hospital').fadeIn(); } 
        else { $('#def_contenedor_hospital').fadeOut().find('input').val(''); }
    });

    $(document).on('change','#nac_tipo_soporte',function(){
        if(['constancia_hosp','ficha_medica','certificado_nac','cert_ficha','cert_cert'].includes($(this).val())){ $('#nac_contenedor_hospital').fadeIn(); }
        else { $('#nac_contenedor_hospital').fadeOut().find('input').val(''); }
    });

    $('#formGenerar').on('submit', function(e){
        e.preventDefault();
        const btn = $('#btnFinalizar');
        const disabledFields = $(this).find(':disabled').prop('disabled', false);
        let formDataArray = $(this).serializeArray();
        
        let distDefValue = $('#def_distrito_id').val();
        if (distDefValue) {
            formDataArray.push({ name: 'def_distrito_defuncion_id', value: distDefValue });
        }

        disabledFields.prop('disabled', true);
        const formData = $.param(formDataArray);
        btn.prop('disabled', true).text('Guardando...');

        $.ajax({
            url: 'guardar_constancia.php',
            method: 'POST',
            dataType: 'json',
            data: formData,
            success: function(r){
                if(r.success){
                    btn.text('Generando PDF...');
                    $.post('generar_constancia_pdf.php', formData + '&numero_oficio_generado=' + r.oficio, function() {
                        alert("¡Constancia generada con éxito!");
                        window.location.href = 'dashboard.php';
                    });
                } else {
                    alert("Error: " + r.msg); 
                    btn.prop('disabled', false).text('GENERAR DOCUMENTO PDF');
                }
            },
            error: function() {
                alert("Error de conexión al servidor.");
                btn.prop('disabled', false).text('GENERAR DOCUMENTO PDF');
            }
        });
    });
});
</script>
</body>
</html>
