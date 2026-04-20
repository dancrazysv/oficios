<?php
require_once __DIR__ . '/../db_config.php'; 
?>

<div class="form-section shadow-sm p-3 mb-5 bg-white rounded border">
    <h5 class="text-primary mb-4 border-bottom pb-2">Datos de Nacimiento</h5>

    <div class="form-group">
        <label><strong>No aparece registrada partida de nacimiento a nombre de:</strong></label>
        <input type="text" class="form-control text-uppercase" name="nac_nombre_no_registro" required placeholder="Nombre del ciudadano">
    </div>

    <div class="form-group">
        <label><strong>Según: (Documento de Nacimiento)</strong></label>
        <select class="form-control" name="nac_tipo_soporte" id="nac_tipo_soporte" required>
            <option value="">Seleccione el documento de soporte...</option>
            <?php
            // Cargamos solo los soportes que pertenecen a NACIMIENTO o AMBOS
            $stmt_sop = $pdo->query("SELECT codigo_slug, nombre_oficial FROM catalogo_soportes WHERE categoria IN ('NACIMIENTO', 'AMBOS') ORDER BY id ASC");
            while($s = $stmt_sop->fetch(PDO::FETCH_ASSOC)){
                echo "<option value='{$s['codigo_slug']}'>".htmlspecialchars($s['nombre_oficial'])."</option>";
            }
            ?>
        </select>
    </div>

    <div id="nac_contenedor_hospital" class="form-group" style="display:none">
        <label><strong>Hospital / Institución</strong></label>
        <input type="text" class="form-control border-primary" name="nac_nombre_hospital" list="lista_hospitales" placeholder="Escriba o seleccione hospital">
        <small class="text-muted">Este nombre se unirá automáticamente al tipo de documento en el PDF.</small>
    </div>

    <div class="form-row mt-3">
        <div class="col-md-6">
            <label><strong>Fecha de nacimiento</strong></label>
            <input type="date" class="form-control" name="nac_fecha_nacimiento" required>
        </div>
        <div class="col-md-6">
            <label><strong>Hora de nacimiento (Opcional)</strong></label>
            <input type="time" class="form-control" name="nac_hora_nacimiento">
        </div>
    </div>

    <h6 class="mt-4 text-muted">Ubicación de Nacimiento</h6>
    <div class="form-row">
        <div class="col-md-4">
            <label><small>Departamento</small></label>
            <select class="form-control" id="nac_departamento_id" name="nac_departamento_id" required>
                <option value="">Seleccione</option>
                <?php
                $stmt = $pdo->query("SELECT id, nombre FROM departamentos ORDER BY nombre");
                while($d = $stmt->fetch(PDO::FETCH_ASSOC)){
                    echo "<option value='{$d['id']}'>".htmlspecialchars($d['nombre'])."</option>";
                }
                ?>
            </select>
        </div>
        <div class="col-md-4">
            <label><small>Municipio</small></label>
            <select class="form-control" id="nac_municipio_id" name="nac_municipio_id" disabled required>
                <option value="">Seleccione</option>
            </select>
        </div>
        <div class="col-md-4">
            <label><small>Distrito</small></label>
            <select class="form-control" id="nac_distrito_nacimiento_id" name="nac_distrito_nacimiento_id" disabled required>
                <option value="">Seleccione</option>
            </select>
        </div>
    </div>

    <h6 class="mt-4 text-secondary border-top pt-3">Filiación</h6>
    <div class="form-group">
        <label><strong>Nombre de la madre</strong></label>
        <input type="text" class="form-control text-uppercase" name="nac_nombre_madre" placeholder="Nombre completo de la madre" required>
    </div>

    <div class="form-group">
        <label><strong>Nombre de la madre según DUI (Opcional)</strong></label>
        <input type="text" name="nac_nombre_madre_dui" class="form-control text-uppercase" placeholder="Nombre tal cual aparece en su DUI">
    </div>

    <div class="custom-control custom-checkbox mb-2">
        <input type="checkbox" class="custom-control-input" id="nac_check_padre">
        <label class="custom-control-label" for="nac_check_padre" style="cursor:pointer">Incluir nombre del padre</label>
    </div>

    <div id="nac_contenedor_padre" class="form-group" style="display:none">
        <label><strong>Nombre del padre</strong></label>
        <input type="text" class="form-control text-uppercase" name="nac_nombre_padre" placeholder="Escriba el nombre completo del padre">
    </div>
</div>