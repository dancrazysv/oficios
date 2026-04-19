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

    <div class="referencia">OFICIO No. <?= $referencia_salida ?></div>

    <div class="cuerpo">
        <div class="parrafo">
            En atención a su(s) Oficio(s) número(s) 
            <span class="negrita"><?= $numeros_oficios_in ?></span>, 
            con Referencia(s) <span class="negrita"><?= $referencias_exp_in ?></span>, 
            de <span class="negrita"><?= $nombre_institucion ?></span>; 
            de fecha(s) <span class="negrita"><?= $fechas_docs_in ?></span>, 
            en el cual solicita se remita <span class="negrita"><?= $tipos_tramite_solicitados ?></span> 
            a nombre de: <span class="negrita uppercase"><?= $nombres_solicitados_oficio ?></span>.
        </div>

        <div class="parrafo">
            Habiéndose efectuado la respectiva búsqueda en los registros de nuestra base de datos que corresponde únicamente al Distrito de San Salvador Sede, se informa lo siguiente:
            <br><br>
            <?php foreach($detalles as $det): ?>
                • <span class="negrita uppercase"><?= $det['resultado'] ?></span> registro de <?= strtolower($det['tipo_tramite']) ?> a nombre de <span class="negrita uppercase"><?= $det['nombre_consultado'] ?></span>, 
                <?php if($det['resultado'] == 'ENCONTRADO'): ?>
                    asentada bajo el número <span class="negrita"><?= $det['partida_numero'] ?></span>, 
                    folio <span class="negrita"><?= $det['partida_folio'] ?></span>, 
                    libro <span class="negrita"><?= $det['partida_libro'] ?></span>, 
                    del año <span class="negrita"><?= $det['partida_anio'] ?></span>.
                <?php else: ?>
                    no encontrándose registro alguno en los libros cronológicos y auxiliares de este registro.
                <?php endif; ?>
                <br>
            <?php endforeach; ?>
        </div>

        <div class="parrafo">
            Se extiende la presente en Distrito de San Salvador Sede, San Salvador Centro, San Salvador, el día <span class="negrita"><?= $fecha_letras ?></span>. 
            Se advierte que este Registro del Estado Familiar no es responsable por la inexactitud o falsedad de los datos proporcionados en la presente. 
            <span class="negrita">CUALQUIER ALTERACIÓN ANULA EL PRESENTE DOCUMENTO.</span>
        </div>
    </div>

    <div class="footer">
        <div class="firma">
            LICDA. <?= $nombre_registrador ?><br>
            REGISTRADOR DEL ESTADO FAMILIAR<br>
            DE SAN SALVADOR CENTRO
        </div>
    </div>

</body>
</html>