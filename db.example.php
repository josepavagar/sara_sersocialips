<?php
// Copia este archivo a db.php y ajusta los valores según tu entorno.
// NUNCA subas db.php al repositorio — ya está en .gitignore.

define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // Usuario MySQL
define('DB_PASS', '');           // Contraseña (vacío por defecto en XAMPP)
define('DB_NAME', 'helpdesk');   // Nombre de la base de datos

function getDB(): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        die(json_encode(['error' => 'Error de conexión a la base de datos.']));
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}
?>
