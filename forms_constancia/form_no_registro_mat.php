<div class="form-section">
    <h5>No Registro de Matrimonio</h5>
    
    <div class="custom-control custom-checkbox mb-3">
        <input type="checkbox" class="custom-control-input" id="mat_es_exterior" name="es_exterior" value="1">
        <label class="custom-control-label" for="mat_es_exterior" style="cursor:pointer">
            <strong>¿Es del Exterior?</strong> <small class="text-muted">(Constancia para persona del exterior)</small>
        </label>
    </div>

    <div class="form-row">
        <div class="form-group col-md-6">
            <label>No aparece registrada partida de matrimonio a nombre de:</label>
            <input type="text" 
                   class="form-control" 
                   name="mat_nombre_no_registro" 
                   placeholder="Nombre del primer contrayente">
        </div>

        <div class="form-group col-md-6">
            <label>Y de (Segundo contrayente - Opcional):</label>
            <input type="text" 
                   class="form-control" 
                   name="mat_nombre_contrayente_dos" 
                   placeholder="Nombre del esposo/a (opcional)">
            <small class="text-muted">Deje vacío si no desea incluir un segundo nombre.</small>
        </div>
    </div>
</div>