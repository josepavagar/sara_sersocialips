<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Instalación – HelpDesk</title>
<style>
  body { font-family: 'Courier New', monospace; background: #0d1117; color: #58a6ff; padding: 40px; }
  .box { max-width: 600px; margin: auto; background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 32px; }
  h1 { color: #f0f6fc; font-size: 1.4rem; margin-bottom: 24px; }
  .ok { color: #3fb950; }
  .err { color: #f85149; }
  .btn { display:inline-block; margin-top:24px; padding:12px 28px; background:#238636; color:#fff; text-decoration:none; border-radius:6px; font-family:inherit; }
  pre { background:#0d1117; padding:12px; border-radius:4px; font-size:.85rem; overflow:auto; }
</style>
</head>
<body>
<div class="box">
<h1>⚙ Instalador HelpDesk</h1>
<?php
$conn = new mysqli('localhost', 'root', '', '');
if ($conn->connect_error) {
    echo '<p class="err">❌ No se pudo conectar a MySQL: ' . $conn->connect_error . '</p>';
    echo '<p>Asegúrate de que XAMPP MySQL esté activo.</p>';
    exit;
}
echo '<p class="ok">✔ Conexión a MySQL exitosa</p>';

$sqls = [
    "CREATE DATABASE IF NOT EXISTS `helpdesk` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
    "USE `helpdesk`",
    "CREATE TABLE IF NOT EXISTS `tickets` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `numero` VARCHAR(12) NOT NULL UNIQUE,
        `titulo` VARCHAR(255) NOT NULL,
        `descripcion` TEXT NOT NULL,
        `categoria` ENUM('Hardware','Software','Red','Acceso','Correo','Otro') NOT NULL DEFAULT 'Otro',
        `prioridad` ENUM('Baja','Media','Alta','Critica') NOT NULL DEFAULT 'Media',
        `estado` ENUM('Abierto','En Progreso','Resuelto','Cerrado') NOT NULL DEFAULT 'Abierto',
        `solicitante` VARCHAR(120) NOT NULL,
        `email` VARCHAR(180),
        `agente` VARCHAR(120) DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS `comentarios` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `ticket_id` INT NOT NULL,
        `autor` VARCHAR(120) NOT NULL,
        `tipo` ENUM('Comentario','Cambio de Estado','Asignacion') NOT NULL DEFAULT 'Comentario',
        `mensaje` TEXT NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB"
];

foreach ($sqls as $sql) {
    if ($conn->query($sql)) {
        echo '<p class="ok">✔ ' . htmlspecialchars(substr($sql, 0, 60)) . '…</p>';
    } else {
        echo '<p class="err">❌ Error: ' . $conn->error . '</p>';
    }
}
echo '<p class="ok" style="margin-top:16px;font-size:1.1rem;">✅ Instalación completada</p>';
echo '<a class="btn" href="index.php">Ir al sistema →</a>';
?>
</div>
</body>
</html>
