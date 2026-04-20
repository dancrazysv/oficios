<?php
// Aseguramos la conexión a la base de datos
require_once __DIR__ . '/../db_config.php'; 
?>

<div class="form-section shadow-sm p-3 mb-5 bg-white rounded border">
    <h5 class="text-primary mb-4 border-bottom pb-2">Datos de Defunción</h5>

   <div class="form-group">
    <label><strong>No aparece registrada partida de defunción a nombre de:</strong></label>
    <input type="text" class="form-control text-uppercase" name="def_nombre_no_registro" required placeholder="Nombre del fallecido">
</div>



    <div class="form-group">
        <label><strong>Según: (Documento de Defunción)</strong></label>
        <select class="form-control" name="def_tipo_soporte" id="def_tipo_soporte" required>
            <option value="">Seleccione el documento de soporte...</option>
            <?php
            // Cargamos dinámicamente desde la tabla catalogo_soportes filtrando por DEFUNCION o AMBOS
            $stmt_sop = $pdo->query("SELECT codigo_slug, nombre_oficial FROM catalogo_soportes WHERE categoria IN ('DEFUNCION', 'AMBOS') ORDER BY id ASC");
            while($s = $stmt_sop->fetch(PDO::FETCH_ASSOC)){
                echo "<option value='{$s['codigo_slug']}'>".htmlspecialchars($s['nombre_oficial'])."</option>";
            }
            ?>
        </select>
    </div>

    <div id="def_contenedor_hospital" class="form-group" style="display:none">
        <label><strong>Hospital / Institución</strong></label>
        <input class="form-control border-danger" list="lista_hospitales" name="def_nombre_hospital" id="def_nombre_hospital" placeholder="Seleccione o escriba el hospital">
    </div>

    <div class="form-row">
        <div class="col-md-6">
            <label><strong>Fecha de defunción</strong></label>
            <input type="date" class="form-control" name="def_fecha_defuncion" required>
        </div>
        <div class="col-md-6">
            <label><strong>Hora (opcional)</strong></label>
            <input type="time" class="form-control" name="def_hora_defuncion">
        </div>
    </div>

    <h6 class="mt-4 text-muted">Lugar de Fallecimiento</h6>
    <div class="form-row">
        <div class="form-group col-md-4">
            <label><small>Departamento</small></label>
            <select class="form-control" name="def_departamento_id" id="def_departamento_id" required>
                <option value="">Seleccione Departamento</option>
                <?php
                $stmt = $pdo->query("SELECT id, nombre FROM departamentos ORDER BY nombre");
                while($d = $stmt->fetch(PDO::FETCH_ASSOC)){
                    echo "<option value='{$d['id']}'>".htmlspecialchars($d['nombre'])."</option>";
                }
                ?>
            </select>
        </div>

        <div class="form-group col-md-4">
            <label><small>Municipio</small></label>
            <select class="form-control" name="def_municipio_id" id="def_municipio_id" disabled required>
                <option value="">Seleccione Municipio</option>
            </select>
        </div>

        <div class="form-group col-md-4">
            <label><small>Distrito</small></label>
            <select class="form-control" name="def_distrito_id" id="def_distrito_id" disabled required>
                <option value="">Seleccione Distrito</option>
            </select>
        </div>
    </div>

    <h6 class="mt-4 text-secondary border-top pt-3">Filiación</h6>

    <div class="custom-control custom-checkbox mb-2">
        <input type="checkbox" class="custom-control-input" id="def_check_madre" name="def_incluir_madre">
        <label class="custom-control-label" for="def_check_madre" style="cursor:pointer">Incluir nombre de la madre</label>
    </div>

    <div id="def_contenedor_madre" style="display:none" class="mt-2 p-3 border rounded bg-light">
        <div class="form-group">
            <label>Nombre de la madre</label>
            <input type="text" class="form-control text-uppercase" name="def_nombre_madre" placeholder="Nombre completo">
        </div>
        <div class="form-group mb-0">
            <label>Nombre de la madre según DUI (Opcional)</label>
            <input type="text" class="form-control text-uppercase" name="def_nombre_madre_dui" placeholder="Nombre según DUI">
        </div>
    </div>

    <div class="custom-control custom-checkbox mt-3 mb-2">
        <input type="checkbox" class="custom-control-input" id="def_check_padre" name="def_incluir_padre">
        <label class="custom-control-label" for="def_check_padre" style="cursor:pointer">Incluir nombre del padre</label>
    </div>

    <div id="def_contenedor_padre" style="display:none" class="mt-2 p-3 border rounded bg-light">
        <label>Nombre del padre</label>
        <input type="text" class="form-control text-uppercase" name="def_nombre_padre" placeholder="Nombre completo del padre">
    </div>
</div>