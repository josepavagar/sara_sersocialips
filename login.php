<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// Si ya está logueado, redirigir según perfil
if (isLoggedIn()) {
    $p = userPerfil();
    if ($p === 'usuario')      { header('Location: nuevo_ticket.php'); exit; }
    if ($p === 'agente')       { header('Location: index.php'); exit; }
    if ($p === 'coordinador')  { header('Location: index.php'); exit; }
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usu  = trim($_POST['usuario']  ?? '');
    $pass = trim($_POST['password'] ?? '');

    if (!$usu || !$pass) {
        $err = 'Ingresa usuario y contraseña.';
    } else {
        $db   = getDB();
        $usuS = $db->real_escape_string($usu);
        $row  = $db->query("SELECT * FROM usuarios WHERE usuario='$usuS' AND activo=1")->fetch_assoc();

        if ($row && password_verify($pass, $row['password'])) {
            $_SESSION['hd_user'] = [
                'id'        => $row['id'],
                'nombre'    => $row['nombre'],
                'usuario'   => $row['usuario'],
                'perfil'    => $row['perfil'],
                'agente_id' => $row['agente_id'],
            ];
            // Redirigir según perfil
            if ($row['perfil'] === 'usuario')     { header('Location: nuevo_ticket.php'); exit; }
            header('Location: index.php'); exit;
        } else {
            $err = 'Usuario o contraseña incorrectos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SARA</title>
<link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
<link rel="stylesheet" href="style.css">
<style>
body {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    background: var(--bg);
    background-image:
        radial-gradient(ellipse at 15% 60%, rgba(26,111,181,.12) 0%, transparent 55%),
        radial-gradient(ellipse at 85% 15%, rgba(232,104,26,.08) 0%, transparent 50%),
        linear-gradient(160deg, #071828 0%, #0a1f34 100%);
}
.login-outer {
    width: 100%;
    max-width: 420px;
    padding: 24px 20px;
}

/* ── Logo Sersocial ── */
.login-logo {
    text-align: center;
    margin-bottom: 16px;
    height: 256px;
}
.logo-icon {
    width: 64px; height: 64px;
    background: linear-gradient(135deg, var(--blue) 0%, var(--accent) 100%);
    border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.8rem;
    margin: 0 auto 14px;
    box-shadow: 0 6px 24px rgba(232,104,26,.35);
}
.brand-name {
    font-size: 1.7rem;
    font-weight: 800;
    letter-spacing: -.5px;
    color: #fff;
}
.brand-name span { color: var(--accent); }
.brand-sub {
    color: var(--muted);
    font-size: .82rem;
    margin-top: 4px;
    letter-spacing: .3px;
}
.brand-divider {
    width: 40px; height: 3px;
    background: linear-gradient(90deg, var(--blue), var(--accent));
    border-radius: 2px;
    margin: 10px auto 0;
}

/* ── Card login ── */
.login-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-top: 3px solid var(--accent);
    border-radius: 14px;
    padding: 32px;
    box-shadow: 0 12px 48px rgba(0,0,0,.55);
}
.login-card h2 {
    font-size: 1.1rem;
    font-weight: 700;
    margin-bottom: 22px;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
}
.login-card h2::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
    margin-left: 8px;
}
.input-icon-wrap { position: relative; display: block; width: 100%; }
.input-icon-wrap .icon {
    position: absolute; left: 12px; top: 50%;
    transform: translateY(-50%); pointer-events: none; font-size: .95rem;
}
.input-icon-wrap input { padding-left: 38px; width: 100%; box-sizing: border-box; }

.login-btn {
    width: 100%;
    padding: 13px;
    border: none;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--accent) 0%, #c9551a 100%);
    color: #fff;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    transition: opacity .2s, box-shadow .2s;
    margin-top: 4px;
    font-family: inherit;
    letter-spacing: .3px;
    box-shadow: 0 4px 16px rgba(232,104,26,.4);
}
.login-btn:hover {
    opacity: .92;
    box-shadow: 0 6px 24px rgba(232,104,26,.55);
}

/* ── Perfil hints ── */
.perfil-hints {
    margin-top: 16px;
    padding: 14px 16px;
    background: var(--surface);
    border-radius: 10px;
    border: 1px solid var(--border);
    font-size: .77rem;
    color: var(--muted);
}
.perfil-hints strong {
    color: var(--text);
    font-size: .8rem;
    display: block;
    margin-bottom: 8px;
}
.ph-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 4px 0;
    border-bottom: 1px solid rgba(255,255,255,.04);
}
.ph-row:last-child { border-bottom: none; }
.ph-row code {
    background: rgba(232,104,26,.1);
    color: var(--accent2);
    padding: 1px 7px;
    border-radius: 4px;
    font-size: .75rem;
}

/* ── Footer ── */
.login-footer {
    text-align: center;
    margin-top: 14px;
    font-size: .72rem;
    color: var(--border);
}
</style>
</head>
<body>
<div class="login-outer">
  <!-- Logo -->
  <div class="login-logo">
   <img src="sara_logo.png" alt="SARA APP Support"
     style="height:350px;max-width:350px;object-fit:contain;margin:0 auto 8px;display:block;filter:drop-shadow(0 4px 0px rgb(255, 255, 255));">
    <div class="brand-divider"></div>
  </div>

  <!-- Card -->
  <div class="login-card">
    <h2>Iniciar Sesión</h2>

    <?php if ($err): ?>
      <div class="alert alert-err" style="margin-bottom:16px;">⚠ <?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label>Usuario</label>
        <div class="input-icon-wrap">
          <span class="icon">👤</span>
          <input type="text" name="usuario" placeholder="Ingresa tu usuario"
                 value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>"
                 autocomplete="username" required autofocus>
        </div>
      </div>
      <div class="form-group" style="margin-bottom:22px;">
        <label>Contraseña</label>
        <div class="input-icon-wrap">
          <span class="icon">🔒</span>
          <input type="password" name="password" placeholder="••••••••"
                 autocomplete="current-password" required>
        </div>
      </div>
      <button type="submit" class="login-btn">Entrar</button>
    </form>
  </div>
  <div class="login-footer">© Fundacion Sersocial IPS · Soporte Aplicaciones</div>
</div>
</body>

</html>
