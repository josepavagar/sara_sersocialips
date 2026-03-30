<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Migración – Sistema de Login</title>
<style>
  body { font-family: 'Courier New', monospace; background: #0d1117; color: #58a6ff; padding: 40px; }
  .box { max-width: 680px; margin: auto; background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 32px; }
  h1 { color: #f0f6fc; font-size: 1.3rem; margin-bottom: 24px; }
  .ok  { color: #3fb950; margin: 5px 0; }
  .err { color: #f85149; margin: 5px 0; }
  .inf { color: #58a6ff; margin: 5px 0; }
  .sep { border-top: 1px solid #30363d; margin: 18px 0; }
  table { width:100%; border-collapse:collapse; margin-top:16px; font-size:.85rem; }
  th { color:#7a85a3; text-align:left; padding:6px 10px; border-bottom:1px solid #30363d; }
  td { padding:8px 10px; border-bottom:1px solid #1e2530; }
  .btn { display:inline-block; margin-top:24px; padding:12px 28px; background:#238636; color:#fff; text-decoration:none; border-radius:6px; font-family:inherit; margin-right:10px; }
  .btn2 { background:#1f6feb; }
  code { background:#0d1117; padding:2px 7px; border-radius:3px; }
</style>
</head>
<body>
<div class="box">
<h1>⚙ Migración — Sistema de Login y Perfiles</h1>
<?php
$conn = new mysqli('localhost', 'root', '', 'helpdesk');
if ($conn->connect_error) { echo '<p class="err">❌ ' . $conn->connect_error . '</p>'; exit; }
$conn->set_charset('utf8mb4');
echo '<p class="ok">✔ Conexión a BD helpdesk exitosa</p>';

// 1. Crear tabla usuarios
$sql = "CREATE TABLE IF NOT EXISTS `usuarios` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `nombre`     VARCHAR(120) NOT NULL,
    `usuario`    VARCHAR(60)  NOT NULL UNIQUE,
    `password`   VARCHAR(255) NOT NULL,
    `perfil`     ENUM('usuario','agente','coordinador') NOT NULL DEFAULT 'usuario',
    `agente_id`  INT DEFAULT NULL COMMENT 'FK a agentes si perfil=agente',
    `activo`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($sql) ? print('<p class="ok">✔ Tabla `usuarios` creada/verificada</p>') : print('<p class="err">❌ '.$conn->error.'</p>');

// 2. Columna prioridad_bloqueada en tickets (indica que el usuario no puede elegirla)
// No necesitamos campo extra, la prioridad queda en Media y el agente la cambia.

// 3. Usuarios por defecto
$defaults = [
    // usuario, nombre, contraseña, perfil, agente_id
    ['admin',          'Administrador',           'admin123',    'coordinador', null],
    ['jose.pava',      'Jose David Pava Garcia',  'agente123',   'agente',      1],
    ['orlando.guette', 'Orlando Guette Montalvo', 'agente123',   'agente',      2],
    ['victor.deleon',  'Victor De Leon',          'agente123',   'agente',      4],
    ['deisy.ribon',    'Deisy Ribon',             'agente123',   'agente',      5],
    ['usuario',        'Usuario Final Demo',      'usuario123',  'usuario',     null],
];

$creados = 0;
foreach ($defaults as [$usu, $nom, $pass, $perfil, $ag_id]) {
    $existe = $conn->query("SELECT id FROM usuarios WHERE usuario='$usu'")->num_rows;
    if ($existe) { echo "<p class='inf'>ℹ «$usu» ya existe, omitido</p>"; continue; }
    $hash   = password_hash($pass, PASSWORD_DEFAULT);
    $ag_val = $ag_id ? $ag_id : 'NULL';
    $conn->query("INSERT INTO usuarios (nombre, usuario, password, perfil, agente_id) VALUES ('$nom','$usu','$hash','$perfil',$ag_val)")
        ? $creados++ : print("<p class='err'>❌ Error creando $usu: ".$conn->error."</p>");
}
echo "<p class='ok'>✔ $creados usuario(s) creados</p>";

echo '<div class="sep"></div>';
echo '<p style="color:#f0f6fc;font-weight:700;margin-bottom:8px;">Credenciales de acceso:</p>';
?>
<table>
  <thead><tr><th>Usuario</th><th>Contraseña</th><th>Perfil</th><th>Descripción</th></tr></thead>
  <tbody>
    <tr><td><code>admin</code></td><td><code>admin123</code></td><td>Coordinador</td><td>Ve reportes, agentes, todos los tickets</td></tr>
    <tr><td><code>jose.pava</code></td><td><code>agente123</code></td><td>Agente</td><td>Atiende Software Asistencial y ERP</td></tr>
    <tr><td><code>orlando.guette</code></td><td><code>agente123</code></td><td>Agente</td><td>Atiende Software Asistencial y ERP</td></tr>
    <tr><td><code>victor.deleon</code></td><td><code>agente123</code></td><td>Agente</td><td>Atiende Gestión Riesgo Salud</td></tr>
    <tr><td><code>deisy.ribon</code></td><td><code>agente123</code></td><td>Agente</td><td>Atiende Beneficios Empresariales</td></tr>
    <tr><td><code>usuario</code></td><td><code>usuario123</code></td><td>Usuario</td><td>Solo puede crear tickets</td></tr>
  </tbody>
</table>
<p style="color:#7a85a3;font-size:.82rem;margin-top:12px;">⚠ Cambia las contraseñas desde el panel de Usuarios una vez en producción.</p>
<a class="btn" href="login.php">Ir al Login →</a>
<a class="btn btn2" href="usuarios.php">Gestionar Usuarios</a>
</div>
</body>
</html>
