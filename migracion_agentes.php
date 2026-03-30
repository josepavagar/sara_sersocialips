<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Migración – Módulo Agentes</title>
<style>
  body { font-family: 'Courier New', monospace; background: #0d1117; color: #58a6ff; padding: 40px; }
  .box { max-width: 640px; margin: auto; background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 32px; }
  h1 { color: #f0f6fc; font-size: 1.3rem; margin-bottom: 24px; }
  .ok  { color: #3fb950; margin: 6px 0; }
  .err { color: #f85149; margin: 6px 0; }
  .info{ color: #58a6ff; margin: 6px 0; }
  .btn { display:inline-block; margin-top:24px; padding:12px 28px; background:#238636; color:#fff; text-decoration:none; border-radius:6px; font-family:inherit; margin-right:10px; }
  .btn2{ background:#1f6feb; }
</style>
</head>
<body>
<div class="box">
<h1>⚙ Migración — Módulo de Agentes</h1>
<?php
$conn = new mysqli('localhost', 'root', '', 'helpdesk');
if ($conn->connect_error) {
    echo '<p class="err">❌ No se pudo conectar: ' . $conn->connect_error . '</p>';
    exit;
}
$conn->set_charset('utf8mb4');
echo '<p class="ok">✔ Conexión exitosa a BD helpdesk</p>';

$sqls = [
    // Tabla agentes
    "CREATE TABLE IF NOT EXISTS `agentes` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `nombre`       VARCHAR(120) NOT NULL,
        `email`        VARCHAR(180) DEFAULT NULL,
        `telefono`     VARCHAR(30)  DEFAULT NULL,
        `departamento` VARCHAR(100) DEFAULT NULL,
        `activo`       TINYINT(1)   NOT NULL DEFAULT 1,
        `created_at`   DATETIME     DEFAULT CURRENT_TIMESTAMP,
        `updated_at`   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",

    // Columna agente_id en tickets (FK opcional hacia agentes)
    "ALTER TABLE `tickets` ADD COLUMN IF NOT EXISTS `agente_id` INT DEFAULT NULL",

    // FK suave (no bloquea si el agente fue eliminado)
    "ALTER TABLE `tickets` ADD CONSTRAINT IF NOT EXISTS fk_ticket_agente
        FOREIGN KEY (`agente_id`) REFERENCES `agentes`(`id`) ON DELETE SET NULL",
];

$labels = [
    'Crear tabla `agentes`',
    'Agregar columna `agente_id` a tickets',
    'Agregar FK ticket → agente',
];

foreach ($sqls as $i => $sql) {
    $label = $labels[$i];
    if ($conn->query($sql)) {
        echo "<p class='ok'>✔ $label</p>";
    } else {
        // IF NOT EXISTS hace que algunas versiones de MariaDB devuelvan warning, no error
        if (str_contains($conn->error ?? '', 'Duplicate') || str_contains($conn->error ?? '', 'already')) {
            echo "<p class='info'>ℹ $label — ya existía, omitido</p>";
        } else {
            echo "<p class='err'>❌ $label: " . $conn->error . "</p>";
        }
    }
}

// Sincronizar agentes desde texto libre existente en tickets
$r = $conn->query("SELECT DISTINCT agente FROM tickets WHERE agente IS NOT NULL AND agente != '' AND agente_id IS NULL");
$sync = 0;
while ($row = $r->fetch_assoc()) {
    $nombre = $conn->real_escape_string($row['agente']);
    // Insert solo si no existe
    $conn->query("INSERT IGNORE INTO agentes (nombre) VALUES ('$nombre')");
    $ag = $conn->query("SELECT id FROM agentes WHERE nombre='$nombre' LIMIT 1")->fetch_assoc();
    if ($ag) {
        $conn->query("UPDATE tickets SET agente_id={$ag['id']} WHERE agente='$nombre'");
        $sync++;
    }
}
if ($sync > 0)
    echo "<p class='ok'>✔ $sync agente(s) importados desde tickets existentes</p>";
else
    echo "<p class='info'>ℹ Sin agentes previos que importar</p>";

echo '<p class="ok" style="margin-top:20px;font-size:1.1rem;">✅ Migración completada</p>';
?>
<a class="btn" href="agentes.php">Ir a Agentes →</a>
<a class="btn btn2" href="index.php">Panel principal</a>
</div>
</body>
</html>
