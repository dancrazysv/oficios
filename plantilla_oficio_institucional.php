<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 2.5cm; }
        body { font-family: 'Arial', sans-serif; line-height: 1.6; font-size: 12pt; color: #333; }
        .header { text-align: center; margin-bottom: 30px; }
        .logo { width: 80px; height: auto; }
        .institucion-name { color: #999; font-size: 14pt; text-transform: uppercase; margin-top: 10px; }
        
        .destinatario { font-weight: bold; margin-bottom: 20px; text-transform: uppercase; }
        .referencia { font-weight: bold; margin-bottom: 30px; }
        
        .cuerpo { text-align: justify; }
        .parrafo { margin-bottom: 20px; text-indent: 1cm; }
        
        .footer { margin-top: 60px; text-align: center; }
        .firma { font-weight: bold; text-transform: uppercase; margin-top: 10px; border-top: 0px; }
        
        .negrita { font-weight: bold; }
        .uppercase { text-transform: uppercase; }
    </style>
</head>
<body>

    <div class="header">
        <img src="img/logo_alcaldia.png" class="logo"> <div class="institucion-name">
            REGISTRO DEL ESTADO FAMILIAR<br>
            DISTRITO SAN SALVADOR SEDE
        </div>
    </div>

    <div class="destinatario">A QUIEN CORRESPONDA:</div>

    <div class="referencia">OFICIO No. <?= htmlspecialchars((string)($referencia_salida ?? ''), ENT_QUOTES, 'UTF-8') ?></div>

    <div class="cuerpo">
        <div class="parrafo">
            En atención a su(s) Oficio(s) número(s) 
            <span class="negrita"><?= htmlspecialchars((string)($numeros_oficios_in ?? ''), ENT_QUOTES, 'UTF-8') ?></span>, 
            con Referencia(s) <span class="negrita"><?= htmlspecialchars((string)($referencias_exp_in ?? ''), ENT_QUOTES, 'UTF-8') ?></span>, 
            de <span class="negrita"><?= htmlspecialchars((string)($nombre_institucion ?? ''), ENT_QUOTES, 'UTF-8') ?></span>; 
            de fecha(s) <span class="negrita"><?= htmlspecialchars((string)($fechas_docs_in ?? ''), ENT_QUOTES, 'UTF-8') ?></span>, 
            en el cual solicita se remita <span class="negrita"><?= htmlspecialchars((string)($tipos_tramite_solicitados ?? ''), ENT_QUOTES, 'UTF-8') ?></span> 
            a nombre de: <span class="negrita uppercase"><?= htmlspecialchars((string)($nombres_solicitados_oficio ?? ''), ENT_QUOTES, 'UTF-8') ?></span>.
        </div>

        <div class="parrafo">
            Habiéndose efectuado la respectiva búsqueda en los registros de nuestra base de datos que corresponde únicamente al Distrito de San Salvador Sede, se informa lo siguiente:
            <br><br>
            <?php foreach($detalles as $det): ?>
                • <span class="negrita uppercase"><?= htmlspecialchars((string)($det['resultado'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span> registro de <?= htmlspecialchars(strtolower((string)($det['tipo_tramite'] ?? '')), ENT_QUOTES, 'UTF-8') ?> a nombre de <span class="negrita uppercase"><?= htmlspecialchars((string)($det['nombre_consultado'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>, 
                <?php if($det['resultado'] == 'ENCONTRADO'): ?>
                    asentada bajo el número <span class="negrita"><?= htmlspecialchars((string)($det['partida_numero'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>, 
                    folio <span class="negrita"><?= htmlspecialchars((string)($det['partida_folio'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>, 
                    libro <span class="negrita"><?= htmlspecialchars((string)($det['partida_libro'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>, 
                    del año <span class="negrita"><?= htmlspecialchars((string)($det['partida_anio'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>.
                <?php else: ?>
                    no encontrándose registro alguno en los libros cronológicos y auxiliares de este registro.
                <?php endif; ?>
                <br>
            <?php endforeach; ?>
        </div>

        <div class="parrafo">
            Se extiende la presente en Distrito de San Salvador Sede, San Salvador Centro, San Salvador, el día <span class="negrita"><?= htmlspecialchars((string)($fecha_letras ?? ''), ENT_QUOTES, 'UTF-8') ?></span>. 
            Se advierte que este Registro del Estado Familiar no es responsable por la inexactitud o falsedad de los datos proporcionados en la presente. 
            <span class="negrita">CUALQUIER ALTERACIÓN ANULA EL PRESENTE DOCUMENTO.</span>
        </div>
    </div>

    <div class="footer">
        <div class="firma">
            LICDA. <?= htmlspecialchars((string)($nombre_registrador ?? ''), ENT_QUOTES, 'UTF-8') ?><br>
            REGISTRADOR DEL ESTADO FAMILIAR<br>
            DE SAN SALVADOR CENTRO
        </div>
    </div>

</body>
</html>