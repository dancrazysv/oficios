<?php
// Utiliza la función header() para enviar una cabecera HTTP de redirección.
// La cabecera 'Location' le dice al navegador que navegue a una nueva URL.
header('Location: login.php');

// La función exit() es crucial para detener la ejecución del script
// inmediatamente después de enviar la cabecera. Esto previene que
// se ejecute cualquier código adicional o se envíe contenido HTML
// que podría interferir con la redirección.
exit();
?>