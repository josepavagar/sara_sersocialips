<?php
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
requirePerfil('coordinador');

$db  = getDB();
$msg = '';
$err = '';

/* Auto-migración */
$db->query("CREATE TABLE IF NOT EXISTS `usuarios` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `nombre`     VARCHAR(120) NOT NULL,
    `usuario`    VARCHAR(60)  NOT NULL UNIQUE,
    `password`   VARCHAR(255) NOT NULL,
    `perfil`     ENUM('usuario','agente','coordinador') NOT NULL DEFAULT 'usuario',
    `agente_id`  INT DEFAULT NULL,
    `activo`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ─── ACCIONES ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'crear') {
        $nombre   = trim($_POST['nombre']   ?? '');
        $usuario  = trim($_POST['usuario']  ?? '');
        $pass     = trim($_POST['password'] ?? '');
        $perfil   = $_POST['perfil']        ?? 'usuario';
        $agente_id= intval($_POST['agente_id'] ?? 0);

        if (!$nombre || !$usuario || !$pass) {
            $err = 'Nombre, usuario y contraseña son obligatorios.';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $ag   = $agente_id ?: 'NULL';
            $u    = $db->real_escape_string($usuario);
            $n    = $db->real_escape_string($nombre);
            $p    = $db->real_escape_string($perfil);
            if ($db->query("INSERT INTO usuarios (nombre, usuario, password, perfil, agente_id) VALUES ('$n','$u','$hash','$p',$ag)"))
                $msg = "Usuario «$usuario» creado.";
            else
                $err = 'Error: ' . $db->error . ' (¿usuario ya existe?)';
        }
    }

    if ($action === 'toggle') {
        $id     = intval($_POST['id']);
        $actual = (int)$db->query("SELECT activo FROM usuarios WHERE id=$id")->fetch_assoc()['activo'];
        $nuevo  = $actual ? 0 : 1;
        $db->query("UPDATE usuarios SET activo=$nuevo WHERE id=$id");
        $msg = $nuevo ? 'Usuario activado.' : 'Usuario desactivado.';
    }

    if ($action === 'reset_pass') {
        $id      = intval($_POST['id']);
        $newpass = trim($_POST['nueva_pass'] ?? '');
        if (strlen($newpass) < 6) {
            $err = 'La contraseña debe tener al menos 6 caracteres.';
        } else {
            $hash = password_hash($newpass, PASSWORD_DEFAULT);
            $db->query("UPDATE usuarios SET password='$hash' WHERE id=$id");
            $msg = 'Contraseña actualizada.';
        }
    }

    if ($action === 'eliminar') {
        $id = intval($_POST['id']);
        $me = currentUser()['id'];
        if ($id === $me) { $err = 'No puedes eliminarte a ti mismo.'; }
        else { $db->query("DELETE FROM usuarios WHERE id=$id"); $msg = 'Usuario eliminado.'; }
    }
}

$usuarios = $db->query("SELECT u.*, a.nombre AS agente_nombre FROM usuarios u LEFT JOIN agentes a ON a.id=u.agente_id ORDER BY u.perfil, u.nombre");
$agentes_list = $db->query("SELECT id, nombre FROM agentes WHERE activo=1 ORDER BY nombre");
$tot = $db->query("SELECT COUNT(*) t, SUM(activo) a, perfil FROM usuarios GROUP BY perfil");
$counts = [];
while ($r = $tot->fetch_assoc()) $counts[$r['perfil']] = $r;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Usuarios</title>
<link rel="shortcut icon" type="image/x-icon" href="<?= BASE_URL ?>/favicon.ico">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<style>
.avatar-sm { width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.78rem;flex-shrink:0; }
.av-usuario     { background:rgba(45,190,108,.2); color:var(--green); }
.av-agente      { background:rgba(79,142,247,.2); color:var(--accent); }
.av-coordinador { background:rgba(124,92,191,.2); color:var(--accent2); }
.perfil-tag { display:inline-block;padding:2px 10px;border-radius:12px;font-size:.73rem;font-weight:700; }
.pt-usuario     { background:rgba(45,190,108,.15); color:var(--green); }
.pt-agente      { background:rgba(79,142,247,.15); color:var(--accent); }
.pt-coordinador { background:rgba(124,92,191,.15); color:var(--accent2); }
.dot { width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:4px; }
.dot-on { background:var(--green); } .dot-off { background:var(--muted); }
.form-panel { background:var(--card);border:1px solid var(--accent);border-radius:var(--radius);padding:24px;margin-bottom:24px; }
.form-panel h3 { color:var(--accent);font-size:1rem;margin-bottom:18px; }
</style>
</head>
<body>
<?= renderNav(BASE_URL . '/modules/admin/usuarios.php') ?>
<div class="page">
  <div class="page-title">🔑 Gestión de Usuarios</div>

  <?php if ($msg): ?><div class="alert alert-ok">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-err">⚠ <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <!-- Stats -->
  <div class="stats" style="margin-bottom:24px;">
    <div class="stat total"><div class="stat-num"><?= array_sum(array_column($counts,'t')) ?></div><div class="stat-label">Total</div></div>
    <div class="stat resuelto"><div class="stat-num" style="color:var(--green)"><?= $counts['usuario']['t'] ?? 0 ?></div><div class="stat-label">Usuarios</div></div>
    <div class="stat abierto"><div class="stat-num"><?= $counts['agente']['t'] ?? 0 ?></div><div class="stat-label">Agentes</div></div>
    <div class="stat" ><div class="stat-num" style="color:var(--accent2)"><?= $counts['coordinador']['t'] ?? 0 ?></div><div class="stat-label">Coordinadores</div></div>
  </div>

  <!-- Crear usuario -->
  <div class="form-panel">
    <h3>➕ Crear Nuevo Usuario</h3>
    <form method="POST">
      <input type="hidden" name="action" value="crear">
      <div class="grid-2">
        <div class="form-group">
          <label>Nombre completo *</label>
          <input type="text" name="nombre" placeholder="Nombre del usuario" required>
        </div>
        <div class="form-group">
          <label>Usuario (login) *</label>
          <input type="text" name="usuario" placeholder="Ej: jose.pava" autocomplete="off" required>
        </div>
        <div class="form-group">
          <label>Contraseña *</label>
          <input type="password" name="password" placeholder="Mínimo 6 caracteres" autocomplete="new-password" required>
        </div>
        <div class="form-group">
          <label>Perfil</label>
          <select name="perfil" id="perfilSelect" onchange="toggleAgente(this.value)">
            <option value="usuario">👤 Usuario final</option>
            <option value="agente">🔧 Agente</option>
            <option value="coordinador">📊 Coordinador</option>
          </select>
        </div>
        <div class="form-group" id="agenteGrp" style="display:none;">
          <label>Vincular con Agente</label>
          <select name="agente_id">
            <option value="">— No vincular —</option>
            <?php $agentes_list->data_seek(0); while ($ag = $agentes_list->fetch_assoc()): ?>
              <option value="<?= $ag['id'] ?>"><?= htmlspecialchars($ag['nombre']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
      </div>
      <button type="submit" class="btn btn-success">💾 Crear Usuario</button>
    </form>
  </div>

  <!-- Tabla -->
  <div class="card" style="padding:0;">
    <div style="padding:14px 20px;border-bottom:1px solid var(--border);font-size:.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;">
      Lista de usuarios
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th></th><th>Nombre</th><th>Usuario</th><th>Perfil</th><th>Agente vinculado</th><th>Estado</th><th>Alta</th><th>Acciones</th></tr>
        </thead>
        <tbody>
        <?php while ($u = $usuarios->fetch_assoc()):
            $ini = implode('', array_map(fn($w)=>strtoupper($w[0]), array_slice(explode(' ',$u['nombre']),0,2)));
            $me  = (currentUser()['id'] == $u['id']);
        ?>
        <tr style="<?= !$u['activo']?'opacity:.5':'' ?>">
          <td><div class="avatar-sm av-<?= $u['perfil'] ?>"><?= htmlspecialchars($ini) ?></div></td>
          <td><strong><?= htmlspecialchars($u['nombre']) ?></strong><?= $me?' <span style="color:var(--muted);font-size:.75rem;">(tú)</span>':'' ?></td>
          <td><code style="color:var(--accent)"><?= htmlspecialchars($u['usuario']) ?></code></td>
          <td><span class="perfil-tag pt-<?= $u['perfil'] ?>"><?= ucfirst($u['perfil']) ?></span></td>
          <td style="color:var(--muted);font-size:.82rem;"><?= $u['agente_nombre'] ? htmlspecialchars($u['agente_nombre']) : '—' ?></td>
          <td><span class="dot <?= $u['activo']?'dot-on':'dot-off' ?>"></span><?= $u['activo']?'Activo':'Inactivo' ?></td>
          <td style="color:var(--muted);font-size:.8rem;white-space:nowrap;"><?= date('d/m/Y',strtotime($u['created_at'])) ?></td>
          <td>
            <div style="display:flex;gap:5px;flex-wrap:wrap;">
              <!-- Reset contraseña -->
              <button onclick="openReset(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['nombre'])) ?>')"
                class="btn btn-ghost btn-sm">🔑</button>
              <!-- Toggle activo -->
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button class="btn btn-sm" style="background:var(--border);color:var(--muted);">
                  <?= $u['activo']?'⏸':'▶' ?>
                </button>
              </form>
              <!-- Eliminar -->
              <?php if (!$me): ?>
              <form method="POST" style="display:inline"
                onsubmit="return confirm('¿Eliminar usuario «<?= htmlspecialchars(addslashes($u['nombre'])) ?>»?')">
                <input type="hidden" name="action" value="eliminar">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button class="btn btn-danger btn-sm">🗑</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal reset contraseña -->
<div id="resetModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:200;align-items:center;justify-content:center;">
  <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:28px;width:340px;max-width:90vw;">
    <h3 style="margin-bottom:4px;font-size:1rem;">🔑 Cambiar contraseña</h3>
    <p id="resetNombre" style="color:var(--muted);font-size:.85rem;margin-bottom:18px;"></p>
    <form method="POST">
      <input type="hidden" name="action" value="reset_pass">
      <input type="hidden" name="id" id="resetId">
      <div class="form-group">
        <label>Nueva contraseña</label>
        <input type="password" name="nueva_pass" placeholder="Mínimo 6 caracteres" required autocomplete="new-password">
      </div>
      <div style="display:flex;gap:10px;margin-top:4px;">
        <button type="submit" class="btn btn-primary btn-sm">Guardar</button>
        <button type="button" onclick="closeReset()" class="btn btn-ghost btn-sm">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleAgente(val) {
    document.getElementById('agenteGrp').style.display = val === 'agente' ? 'block' : 'none';
}
function openReset(id, nombre) {
    document.getElementById('resetId').value    = id;
    document.getElementById('resetNombre').textContent = nombre;
    document.getElementById('resetModal').style.display = 'flex';
}
function closeReset() {
    document.getElementById('resetModal').style.display = 'none';
}
</script>
</body>
</html>
