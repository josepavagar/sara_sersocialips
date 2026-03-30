<?php
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
requireLogin();

$db = getDB();

/* Auto-migración columna aplicacion */
if ($db->query("SHOW COLUMNS FROM tickets LIKE 'aplicacion'")->num_rows === 0)
    $db->query("ALTER TABLE tickets ADD COLUMN `aplicacion` VARCHAR(120) DEFAULT NULL AFTER `categoria`");

/* Auto-migración columna usuario_id */
if ($db->query("SHOW COLUMNS FROM tickets LIKE 'usuario_id'")->num_rows === 0) {
    $db->query("ALTER TABLE tickets ADD COLUMN `usuario_id` INT DEFAULT NULL AFTER `id`");
    $db->query("ALTER TABLE tickets ADD INDEX idx_usuario_id (usuario_id)");
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo      = trim($_POST['titulo']      ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $categoria   = $_POST['categoria']        ?? 'Software';
    $aplicacion  = trim($_POST['aplicacion']  ?? '');
    $solicitante = trim($_POST['solicitante'] ?? '');
    $email       = trim($_POST['email']       ?? '');
    $prioridad   = 'Media'; // siempre Media por defecto; el agente la cambia después

    if ($aplicacion === '— Sin aplicación específica —') $aplicacion = '';

    if (!$titulo || !$descripcion || !$solicitante) {
        $err = 'Por favor completa todos los campos obligatorios.';
    } else {
        $numero  = generateNumero();
        $uid     = intval(currentUser()['id'] ?? 0);
        $stmt    = $db->prepare("INSERT INTO tickets
            (usuario_id, numero, titulo, descripcion, categoria, aplicacion, prioridad, solicitante, email)
            VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('issssssss', $uid, $numero, $titulo, $descripcion, $categoria,
                          $aplicacion, $prioridad, $solicitante, $email);
        if ($stmt->execute()) {
            $id = $stmt->insert_id;
            $sol = $db->real_escape_string($solicitante);
            $db->query("INSERT INTO comentarios (ticket_id, autor, tipo, mensaje)
                VALUES ($id, '$sol', 'Cambio de Estado', 'Ticket creado con estado Abierto')");

            // Auto-asignación por regla de aplicación
            autoAsignar($db, $id, $aplicacion);

            // Redirigir según perfil
            $perfil = userPerfil();
            if ($perfil === 'usuario') {
                header("Location: " . BASE_URL . "/modules/tickets/mis_tickets.php");
            } else {
                header("Location: " . BASE_URL . "/modules/tickets/ver.php?id=$id&nuevo=1");
            }
            exit;
        } else {
            $err = 'Error al crear el ticket: ' . $stmt->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Nuevo Ticket</title>
<link rel="shortcut icon" type="image/x-icon" href="<?= BASE_URL ?>/favicon.ico">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<style>
.app-badge {
    display: none; margin-top: 7px; padding: 6px 14px;
    background: rgba(79,142,247,.1); border: 1px solid var(--accent);
    border-radius: 6px; font-size: .82rem; color: var(--accent);
    align-items: center; gap: 7px;
}
.app-badge.visible { display: inline-flex; }
.regla-hint {
    margin-top: 6px; font-size: .75rem; color: var(--muted);
    display: none; gap: 5px; align-items: center;
}
.regla-hint.visible { display: flex; }
</style>
</head>
<body>
<?= renderNav(BASE_URL . '/modules/tickets/nuevo.php') ?>
<div class="page" style="max-width:760px;">
  <div class="page-title">➕ Crear Nuevo Ticket</div>

  <?php if ($err): ?>
    <div class="alert alert-err">⚠ <?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="POST">

      <div class="form-group">
        <label>Título del problema *</label>
        <input type="text" name="titulo"
               placeholder="Ej: No abre el sistema al iniciar sesión"
               value="<?= htmlspecialchars($_POST['titulo'] ?? '') ?>" required>
      </div>

      <div class="form-group">
        <label>Descripción detallada *</label>
        <textarea name="descripcion"
          placeholder="Describe el problema con el mayor detalle posible: pasos para reproducirlo, mensajes de error, desde cuándo ocurre, etc."
          required><?= htmlspecialchars($_POST['descripcion'] ?? '') ?></textarea>
      </div>

      <!-- Aplicación -->
      <div class="form-group">
        <label>💻 Aplicación afectada</label>
        <select name="aplicacion" id="appSelect" onchange="updateBadge(this)">
          <option value="">— Sin aplicación específica —</option>
          <optgroup label="📁 Software Asistencial">
            <?php foreach(['Siesa Salud','Siesa Laboratorios','Global Health','Sagicc','Zagilad','Api Siesa','LimeSurvey'] as $a): ?>
              <option <?= (($_POST['aplicacion']??'')===$a)?'selected':''?>><?= $a ?></option>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="🏢 ERP / Gestión empresarial">
            <?php foreach(['Zeus Contabilidad','Zeus Nomina','Zeus Nomina WEB','Zeus Inventario','Zeus Activo Fijos','Zeus Excel'] as $a): ?>
              <option <?= (($_POST['aplicacion']??'')===$a)?'selected':''?>><?= $a ?></option>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="🤝 Gestión del Riesgo en Salud">
            <?php foreach(['SerAgil','Sibacom'] as $a): ?>
              <option <?= (($_POST['aplicacion']??'')===$a)?'selected':''?>><?= $a ?></option>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="🌐 Beneficios Empresariales">
            <?php foreach(['PEC','Sifood','TugoFood'] as $a): ?>
              <option <?= (($_POST['aplicacion']??'')===$a)?'selected':''?>><?= $a ?></option>
            <?php endforeach; ?>
          </optgroup>
        </select>
        <div class="app-badge" id="appBadge"><span>🖥</span><span id="appBadgeText"></span></div>
        <div class="regla-hint" id="reglaHint"><span>🤖</span><span id="reglaText"></span></div>
      </div>

      <!-- Categoría -->
      <div class="form-group" style="max-width:340px;">
        <label>Categoría</label>
        <select name="categoria">
          <?php
          $cats  = ['Software'=>'💾'];
          foreach ($cats as $c => $ico): ?>
            <option value="<?= $c ?>" <?= (($_POST['categoria']??'Software')===$c)?'selected':''?>>
              <?= $ico ?> <?= $c ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Solicitante + Email -->
      <div class="grid-2">
        <div class="form-group">
          <label>Nombre del solicitante *</label>
          <input type="text" name="solicitante" placeholder="Nombre completo"
                 value="<?= htmlspecialchars($_POST['solicitante'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label>Correo electrónico</label>
          <input type="email" name="email" placeholder="correo@empresa.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
      </div>

      <div style="background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:10px 14px;font-size:.82rem;color:var(--muted);margin-bottom:18px;">
        ℹ La prioridad será asignada por el agente de soporte una vez revisado el ticket.
      </div>

      <div style="display:flex;gap:12px;">
        <button type="submit" class="btn btn-primary">Crear Ticket</button>
        <?php if (canAny('agente','coordinador')): ?>
          <a href="<?= BASE_URL ?>/modules/tickets/index.php" class="btn btn-ghost">Cancelar</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<script>
const reglas = {
  asistencial: ['Siesa Salud','Siesa Laboratorios','Global Health','Sagicc','Zagilad','Api Siesa','LimeSurvey'],
  erp:         ['Zeus Contabilidad','Zeus Nomina','Zeus Nomina WEB','Zeus Inventario','Zeus Activo Fijos','Zeus Excel'],
  riesgo:      ['SerAgil','Sibacom'],
  beneficios:  ['PEC','Sifood','TugoFood'],
};

function updateBadge(sel) {
    const val   = sel.value;
    const badge = document.getElementById('appBadge');
    const hint  = document.getElementById('reglaHint');
    const htxt  = document.getElementById('reglaText');

    if (val) {
        document.getElementById('appBadgeText').textContent = val;
        badge.classList.add('visible');

        let msg = '';
        if ([...reglas.asistencial, ...reglas.erp].includes(val))
            msg = 'Se asignará automáticamente a Jose David Pava o Orlando Guette (balanceo de carga)';
        else if (reglas.riesgo.includes(val))
            msg = 'Se asignará automáticamente a Victor De Leon';
        else if (reglas.beneficios.includes(val))
            msg = 'Se asignará automáticamente a Deisy Ribon';

        if (msg) { htxt.textContent = msg; hint.classList.add('visible'); }
        else      { hint.classList.remove('visible'); }
    } else {
        badge.classList.remove('visible');
        hint.classList.remove('visible');
    }
}
updateBadge(document.getElementById('appSelect'));
</script>
</body>
</html>
