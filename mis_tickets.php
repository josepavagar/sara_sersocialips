<?php
require_once __DIR__ . '/auth.php';
requirePerfil('usuario');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$db   = getDB();
$user = currentUser();
$uid  = intval($user['id']);

/* Auto-migración: columna usuario_id en tickets */
if ($db->query("SHOW COLUMNS FROM tickets LIKE 'usuario_id'")->num_rows === 0) {
    $db->query("ALTER TABLE tickets ADD COLUMN `usuario_id` INT DEFAULT NULL AFTER `id`");
    $db->query("ALTER TABLE tickets ADD INDEX idx_usuario_id (usuario_id)");
}

/* Auto-repair: vincular tickets sin usuario_id cuyo solicitante coincida con el nombre del usuario */
$nombre_escaped = $db->real_escape_string($user['nombre']);
$db->query("UPDATE tickets SET usuario_id = $uid
    WHERE usuario_id IS NULL
    AND LOWER(solicitante) = LOWER('$nombre_escaped')");

/* Filtros */
$estado = isset($_GET['estado']) ? trim($_GET['estado']) : '';
$where  = "WHERE usuario_id = $uid";
if ($estado !== '') $where .= " AND estado = '" . $db->real_escape_string($estado) . "'";

$tickets = $db->query("SELECT * FROM tickets $where ORDER BY created_at DESC");

/* Conteos rápidos */
$counts = [];
$res = $db->query("SELECT estado, COUNT(*) c FROM tickets WHERE usuario_id=$uid GROUP BY estado");
while ($r = $res->fetch_assoc()) $counts[$r['estado']] = (int)$r['c'];
$total = array_sum($counts);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mis Tickets</title>
<link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
<link rel="stylesheet" href="style.css">
<style>
.ticket-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px 20px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 16px;
    transition: border-color .2s, transform .15s;
    text-decoration: none;
    color: inherit;
}
.ticket-card:hover {
    border-color: var(--accent);
    transform: translateX(3px);
    text-decoration: none;
    color: inherit;
}
.ticket-card-icon {
    width: 44px; height: 44px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; flex-shrink: 0;
}
.tc-abierto  { background: rgba(232,104,26,.15); }
.tc-progreso { background: rgba(245,166,35,.15); }
.tc-resuelto { background: rgba(39,169,108,.15); }
.tc-cerrado  { background: rgba(107,138,168,.1); }

.ticket-card-body { flex: 1; min-width: 0; }
.tc-num  { font-size: .75rem; color: var(--accent); font-weight: 700; font-family: monospace; }
.tc-title{ font-weight: 600; font-size: .95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tc-meta { font-size: .78rem; color: var(--muted); margin-top: 3px; display: flex; gap: 12px; flex-wrap: wrap; }
.tc-arrow{ color: var(--muted); font-size: 1.1rem; }

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--muted);
}
.empty-state .icon { font-size: 3rem; margin-bottom: 14px; }

.filter-bar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
.filter-btn {
    padding: 6px 14px; border-radius: 20px; font-size: .8rem; font-weight: 600;
    border: 1px solid var(--border); background: transparent; color: var(--muted);
    cursor: pointer; text-decoration: none; transition: all .15s;
}
.filter-btn:hover, .filter-btn.active {
    background: var(--accent); color: #fff; border-color: var(--accent);
    text-decoration: none;
}
</style>
</head>
<body>
<?= renderNav('mis_tickets.php') ?>
<div class="page" style="max-width:820px;">

  <div class="page-title">📋 Mis Tickets</div>

  <!-- KPIs del usuario -->
  <div class="stats" style="margin-bottom:24px;">
    <div class="stat total">
      <div class="stat-num"><?= $total ?></div>
      <div class="stat-label">Total</div>
    </div>
    <div class="stat abierto">
      <div class="stat-num"><?= $counts['Abierto'] ?? 0 ?></div>
      <div class="stat-label">Abiertos</div>
    </div>
    <div class="stat progreso">
      <div class="stat-num"><?= $counts['En Progreso'] ?? 0 ?></div>
      <div class="stat-label">En Progreso</div>
    </div>
    <div class="stat resuelto">
      <div class="stat-num"><?= $counts['Resuelto'] ?? 0 ?></div>
      <div class="stat-label">Resueltos</div>
    </div>
  </div>

  <!-- Filtros por estado -->
  <div class="filter-bar">
    <a href="mis_tickets.php" class="filter-btn <?= !$estado ? 'active' : '' ?>">Todos</a>
    <?php foreach(['Abierto','En Progreso','Resuelto','Cerrado'] as $e): ?>
      <a href="?estado=<?= urlencode($e) ?>"
         class="filter-btn <?= $estado === $e ? 'active' : '' ?>">
        <?= $e ?>
        <?php if (isset($counts[$e])): ?><span style="opacity:.7">(<?= $counts[$e] ?>)</span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Lista de tickets -->
  <?php if ($tickets->num_rows === 0): ?>
    <div class="empty-state">
      <div class="icon">🎫</div>
      <p style="font-size:1rem;font-weight:600;margin-bottom:6px;">
        <?php if ($estado !== ''): ?>
          No tienes tickets con estado «<?= htmlspecialchars($estado) ?>»
        <?php else: ?>
          Aún no has creado ningún ticket
        <?php endif; ?>
      </p>
      <p style="font-size:.85rem;margin-bottom:20px;">
        <?= $estado === '' ? 'Cuando necesites soporte, crea tu primer ticket.' : '' ?>
      </p>
      <a href="nuevo_ticket.php" class="btn btn-primary">➕ Crear Ticket</a>
    </div>

  <?php else: ?>
    <?php while ($t = $tickets->fetch_assoc()):
      $estadoSlug = strtolower(str_replace(' ', '', $t['estado']));
      $icons = ['Abierto'=>'🔵','En Progreso'=>'🟡','Resuelto'=>'🟢','Cerrado'=>'⚫'];
      $icon  = $icons[$t['estado']] ?? '🎫';
    ?>
      <a href="ver_ticket.php?id=<?= $t['id'] ?>" class="ticket-card">
        <div class="ticket-card-icon tc-<?= $estadoSlug ?>">
          <?= $icon ?>
        </div>
        <div class="ticket-card-body">
          <div class="tc-num"><?= $t['numero'] ?></div>
          <div class="tc-title"><?= htmlspecialchars($t['titulo']) ?></div>
          <div class="tc-meta">
            <?= badgeEstado($t['estado']) ?>
            <?php if ($t['aplicacion']): ?>
              <span>💻 <?= htmlspecialchars($t['aplicacion']) ?></span>
            <?php endif; ?>
            <?php if ($t['agente']): ?>
              <span>👤 <?= htmlspecialchars($t['agente']) ?></span>
            <?php else: ?>
              <span>👤 Sin asignar</span>
            <?php endif; ?>
            <span>📅 <?= date('d/m/Y H:i', strtotime($t['created_at'])) ?></span>
          </div>
        </div>
        <div class="tc-arrow">›</div>
      </a>
    <?php endwhile; ?>

    <div style="margin-top:20px;">
      <a href="nuevo_ticket.php" class="btn btn-primary">➕ Crear Nuevo Ticket</a>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
