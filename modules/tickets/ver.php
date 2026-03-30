<?php
require_once __DIR__ . '/../../core/auth.php';
requireLogin();
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';

$db    = getDB();
$id    = intval($_GET['id'] ?? 0);
$user  = currentUser();
$perfil = userPerfil();

if (!$id) {
    header($perfil === 'usuario' ? 'Location: ' . BASE_URL . '/modules/tickets/mis_tickets.php' : 'Location: ' . BASE_URL . '/modules/tickets/index.php');
    exit;
}

$ticket = $db->query("SELECT * FROM tickets WHERE id=$id")->fetch_assoc();
if (!$ticket) {
    header($perfil === 'usuario' ? 'Location: ' . BASE_URL . '/modules/tickets/mis_tickets.php' : 'Location: ' . BASE_URL . '/modules/tickets/index.php');
    exit;
}

// Usuarios solo pueden ver SUS propios tickets
if ($perfil === 'usuario') {
    $uid = intval($user['id']);
    $tid = intval($ticket['usuario_id'] ?? 0);
    if ($tid !== $uid) {
        header('Location: ' . BASE_URL . '/modules/tickets/mis_tickets.php');
        exit;
    }
}

$esUsuario   = ($perfil === 'usuario');
$esAgente    = in_array($perfil, ['agente', 'coordinador']);

$msg = '';
$err = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Comentar — permitido a todos los roles
    if ($action === 'comentar') {
        $autor   = htmlspecialchars($user['nombre']);
        $mensaje = trim($_POST['mensaje'] ?? '');
        if ($mensaje) {
            $stmt = $db->prepare("INSERT INTO comentarios (ticket_id, autor, tipo, mensaje) VALUES (?,?,'Comentario',?)");
            $stmt->bind_param('iss', $id, $autor, $mensaje);
            $stmt->execute();
            $msg = 'Comentario añadido.';
        }
    }

    // Las acciones siguientes son solo para agentes/coordinadores
    if ($esAgente) {

        // Change status
        if ($action === 'estado') {
            $nuevo   = $db->real_escape_string($_POST['nuevo_estado'] ?? '');
            $autor   = trim($_POST['autor_accion'] ?? 'Sistema');
            $validos = ['Abierto','En Progreso','Resuelto','Cerrado'];
            if (in_array($nuevo, $validos)) {
                $viejo = $ticket['estado'];
                $db->query("UPDATE tickets SET estado='$nuevo' WHERE id=$id");
                $db->query("INSERT INTO comentarios (ticket_id, autor, tipo, mensaje) VALUES ($id, '$autor', 'Cambio de Estado', 'Estado cambiado de \"$viejo\" a \"$nuevo\"')");
                $msg = "Estado actualizado a: $nuevo";
                $ticket['estado'] = $nuevo;
            }
        }

        // Assign agent
        if ($action === 'asignar') {
            $agente_id = intval($_POST['agente_id'] ?? 0);
            if ($agente_id > 0) {
                $ag_row = $db->query("SELECT nombre FROM agentes WHERE id=$agente_id")->fetch_assoc();
                $agente_nombre = $db->real_escape_string($ag_row['nombre'] ?? '');
                $db->query("UPDATE tickets SET agente_id=$agente_id, agente='$agente_nombre' WHERE id=$id");
                $db->query("INSERT INTO comentarios (ticket_id, autor, tipo, mensaje) VALUES ($id, 'Sistema', 'Asignacion', 'Ticket asignado a: $agente_nombre')");
                $msg = "Ticket asignado a: $agente_nombre";
                $ticket['agente']    = $agente_nombre;
                $ticket['agente_id'] = $agente_id;
            } else {
                $db->query("UPDATE tickets SET agente_id=NULL, agente=NULL WHERE id=$id");
                $db->query("INSERT INTO comentarios (ticket_id, autor, tipo, mensaje) VALUES ($id, 'Sistema', 'Asignacion', 'Ticket desasignado (sin agente)')");
                $msg = 'Asignación removida.';
                $ticket['agente']    = null;
                $ticket['agente_id'] = null;
            }
        }

        // Change priority
        if ($action === 'prioridad') {
            $nueva   = $db->real_escape_string($_POST['nueva_prioridad'] ?? '');
            $validos = ['Baja','Media','Alta','Critica'];
            if (in_array($nueva, $validos)) {
                $db->query("UPDATE tickets SET prioridad='$nueva' WHERE id=$id");
                $db->query("INSERT INTO comentarios (ticket_id, autor, tipo, mensaje) VALUES ($id, 'Sistema', 'Cambio de Estado', 'Prioridad cambiada a: $nueva')");
                $msg = "Prioridad actualizada a: $nueva";
                $ticket['prioridad'] = $nueva;
            }
        }

    } // fin $esAgente
}

$nuevo_ticket = isset($_GET['nuevo']);
$comentarios  = $db->query("SELECT * FROM comentarios WHERE ticket_id=$id ORDER BY created_at ASC");

// Cargar agentes activos para el dropdown
$agentes_list = $db->query("SELECT id, nombre FROM agentes WHERE activo=1 ORDER BY nombre ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Soporte APP <?= htmlspecialchars($ticket['numero']) ?></title>
<link rel="shortcut icon" type="image/x-icon" href="<?= BASE_URL ?>/favicon.ico">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<?= renderNav(BASE_URL . '/modules/tickets/index.php') ?>
<div class="page">

  <?php if ($nuevo_ticket): ?>
    <div class="alert alert-ok">✅ Ticket creado exitosamente. Número: <strong><?= $ticket['numero'] ?></strong></div>
  <?php endif; ?>
  <?php if ($msg): ?>
    <div class="alert alert-ok">✅ <?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <!-- Header -->
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
    <div>
      <div style="color:var(--muted);font-size:.8rem;margin-bottom:4px;">
        <?php if ($esUsuario): ?>
          <a href="<?= BASE_URL ?>/modules/tickets/mis_tickets.php">← Volver a Mis Tickets</a>
        <?php else: ?>
          <a href="<?= BASE_URL ?>/modules/tickets/index.php">← Volver a Tickets</a>
        <?php endif; ?>
      </div>
      <div class="page-title" style="margin-bottom:6px;"><?= htmlspecialchars($ticket['titulo']) ?></div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <code style="color:var(--accent);font-size:.85rem;"><?= $ticket['numero'] ?></code>
        <?= badgeEstado($ticket['estado']) ?>
        <?php if ($esAgente): ?><?= badgePrioridad($ticket['prioridad']) ?><?php endif; ?>
        <span class="badge" style="background:var(--surface);color:var(--muted);"><?= $ticket['categoria'] ?></span>
        <?php if (!empty($ticket['aplicacion'])): ?>
          <span class="badge" style="background:rgba(26,111,181,.15);color:var(--blue-lt);border:1px solid rgba(26,111,181,.3);">
            💻 <?= htmlspecialchars($ticket['aplicacion']) ?>
          </span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php
  // Layout: 2 columnas para agentes, 1 columna para usuarios
  $grid = $esAgente ? 'grid-template-columns:1fr 300px' : 'grid-template-columns:1fr';
  ?>
  <div style="display:grid;<?= $grid ?>;gap:24px;">
    <!-- Left: description + timeline -->
    <div>
      <!-- Description -->
      <div class="card" style="margin-bottom:20px;">
        <div style="font-size:.8rem;color:var(--muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:.7px;">Descripción</div>
        <div style="line-height:1.7;white-space:pre-wrap;"><?= htmlspecialchars($ticket['descripcion']) ?></div>
        <div style="margin-top:16px;font-size:.82rem;color:var(--muted);">
          Solicitante: <strong><?= htmlspecialchars($ticket['solicitante']) ?></strong>
          <?php if ($ticket['email']): ?>
            &nbsp;·&nbsp; <a href="mailto:<?= htmlspecialchars($ticket['email']) ?>"><?= htmlspecialchars($ticket['email']) ?></a>
          <?php endif; ?>
          &nbsp;·&nbsp; <?= date('d/m/Y H:i', strtotime($ticket['created_at'])) ?>
        </div>
      </div>

      <!-- Timeline -->
      <div class="card">
        <div style="font-size:.8rem;color:var(--muted);margin-bottom:16px;text-transform:uppercase;letter-spacing:.7px;">Historial de actividad</div>
        <div class="timeline">
          <?php while ($c = $comentarios->fetch_assoc()): ?>
            <?php
              $dot = '💬';
              if ($c['tipo'] === 'Cambio de Estado') $dot = '🔄';
              if ($c['tipo'] === 'Asignacion')       $dot = '👤';
            ?>
            <div class="tl-item">
              <div class="tl-dot"><?= $dot ?></div>
              <div class="tl-body">
                <div class="tl-header"><strong><?= htmlspecialchars($c['autor']) ?></strong> · <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></div>
                <div class="tl-text"><?= nl2br(htmlspecialchars($c['mensaje'])) ?></div>
              </div>
            </div>
          <?php endwhile; ?>
        </div>

        <!-- Add comment -->
        <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border);">
          <div style="font-size:.85rem;color:var(--muted);margin-bottom:12px;">
            <?= $esUsuario ? '💬 Agregar información adicional' : '💬 Añadir comentario' ?>
          </div>
          <form method="POST">
            <input type="hidden" name="action" value="comentar">
            <div class="form-group">
              <textarea name="mensaje" placeholder="<?= $esUsuario ? 'Agrega más detalles, capturas de pantalla o consultas sobre tu caso…' : 'Escribe tu comentario o actualización…' ?>"></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">💬 Enviar</button>
          </form>
        </div>
      </div>
    </div>

    <!-- Right: actions (solo agentes/coordinadores) -->
    <?php if ($esAgente): ?>
    <div>
      <!-- Change status -->
      <div class="card" style="margin-bottom:16px;">
        <div style="font-size:.8rem;color:var(--muted);margin-bottom:12px;text-transform:uppercase;letter-spacing:.7px;">Cambiar Estado</div>
        <form method="POST">
          <input type="hidden" name="action" value="estado">
          <div class="form-group">
            <select name="nuevo_estado">
              <?php foreach(['Abierto','En Progreso','Resuelto','Cerrado'] as $e): ?>
                <option <?= $ticket['estado']==$e?'selected':''?>><?= $e ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <input type="text" name="autor_accion" placeholder="Nombre del agente" value="<?= htmlspecialchars($ticket['agente'] ?? 'Agente') ?>">
          </div>
          <button class="btn btn-primary btn-sm" style="width:100%">Actualizar Estado</button>
        </form>
      </div>

      <!-- Change priority -->
      <div class="card" style="margin-bottom:16px;">
        <div style="font-size:.8rem;color:var(--muted);margin-bottom:12px;text-transform:uppercase;letter-spacing:.7px;">Cambiar Prioridad</div>
        <form method="POST">
          <input type="hidden" name="action" value="prioridad">
          <div class="form-group">
            <select name="nueva_prioridad">
              <?php foreach(['Baja','Media','Alta','Critica'] as $p): ?>
                <option <?= $ticket['prioridad']==$p?'selected':''?>><?= $p ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-ghost btn-sm" style="width:100%">Actualizar Prioridad</button>
        </form>
      </div>

      <!-- Assign agent -->
      <div class="card" style="margin-bottom:16px;">
        <div style="font-size:.8rem;color:var(--muted);margin-bottom:12px;text-transform:uppercase;letter-spacing:.7px;">Asignar Agente</div>
        <form method="POST">
          <input type="hidden" name="action" value="asignar">
          <div class="form-group">
            <?php if ($agentes_list && $agentes_list->num_rows > 0): ?>
              <select name="agente_id">
                <option value="">— Sin asignar —</option>
                <?php $agentes_list->data_seek(0); while ($ag = $agentes_list->fetch_assoc()): ?>
                  <option value="<?= $ag['id'] ?>"
                    <?= ($ticket['agente_id'] ?? 0) == $ag['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ag['nombre']) ?>
                  </option>
                <?php endwhile; ?>
              </select>
            <?php else: ?>
              <p style="color:var(--muted);font-size:.83rem;">
                No hay agentes activos.<br>
                <a href="<?= BASE_URL ?>/modules/admin/agentes.php">→ Crear agentes</a>
              </p>
            <?php endif; ?>
          </div>
          <?php if ($agentes_list && $agentes_list->num_rows > 0): ?>
            <button class="btn btn-ghost btn-sm" style="width:100%">👤 Asignar</button>
          <?php endif; ?>
        </form>
      </div>

      <!-- Ticket info -->
      <div class="card">
        <div style="font-size:.8rem;color:var(--accent);margin-bottom:12px;text-transform:uppercase;letter-spacing:.7px;">Información</div>
        <div style="font-size:.85rem;display:flex;flex-direction:column;gap:10px;">
          <div><span style="color:var(--muted)">Categoría:</span><br><strong><?= $ticket['categoria'] ?></strong></div>
          <div><span style="color:var(--muted)">Agente:</span><br><strong><?= $ticket['agente'] ? htmlspecialchars($ticket['agente']) : '—' ?></strong></div>
          <div><span style="color:var(--muted)">Creado:</span><br><strong><?= date('d/m/Y H:i', strtotime($ticket['created_at'])) ?></strong></div>
          <div><span style="color:var(--muted)">Actualizado:</span><br><strong><?= date('d/m/Y H:i', strtotime($ticket['updated_at'])) ?></strong></div>
        </div>
      </div>
    </div><!-- fin right agente -->
    <?php endif; ?>
  </div><!-- fin grid -->
</div><!-- fin page -->

<style>
@media(max-width:768px) {
  .page > div:last-child { grid-template-columns: 1fr !important; }
}
</style>
</body>
</html>
