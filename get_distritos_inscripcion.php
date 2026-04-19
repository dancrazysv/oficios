<?php
include 'db_config.php';

header('Content-Type: application/json');

try {
    // Definimos los nombres de los distritos (antiguos municipios)
    // del nuevo distrito de San Salvador Centro
    $distritos_array = [
        'San Salvador',
        'Mejicanos',
        'Ayutuxtepeque',
        'Cuscatancingo',
        'Ciudad Delgado'
    ];

    $distritos_json = json_encode($distritos_array);

    if ($distritos_json === false) {
        throw new Exception('Error al codificar a JSON: ' . json_last_error_msg());
    }

    echo $distritos_json;

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de conexión a la base de datos: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>