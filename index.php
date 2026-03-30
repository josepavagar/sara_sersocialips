<?php
require_once __DIR__ . '/auth.php';
requireLogin();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$db = getDB();

// Stats
$stats = [];
foreach (['Abierto','En Progreso','Resuelto','Cerrado'] as $s) {
    $r = $db->query("SELECT COUNT(*) c FROM tickets WHERE estado='$s'");
    $stats[$s] = $r->fetch_assoc()['c'];
}
$total = array_sum($stats);

// Filters
$where = [];
$estado   = $_GET['estado']   ?? '';
$prioridad= $_GET['prioridad']?? '';
$categoria= $_GET['categoria']?? '';
$agente   = $_GET['agente']   ?? '';
$buscar   = $_GET['buscar']   ?? '';

if ($estado)    $where[] = "estado = '" . $db->real_escape_string($estado) . "'";
if ($prioridad) $where[] = "prioridad = '" . $db->real_escape_string($prioridad) . "'";
if ($categoria) $where[] = "categoria = '" . $db->real_escape_string($categoria) . "'";
if ($agente !== '') {
    if ($agente === '0') $where[] = "(agente_id IS NULL OR agente_id = 0)";
    else                 $where[] = "agente_id = " . intval($agente);
}
if ($buscar)    $where[] = "(titulo LIKE '%" . $db->real_escape_string($buscar) . "%' OR numero LIKE '%" . $db->real_escape_string($buscar) . "%' OR solicitante LIKE '%" . $db->real_escape_string($buscar) . "%')";

// Lista de agentes para el filtro
$agentes_filtro = [];
$res_ag = $db->query("SELECT id, nombre FROM agentes WHERE activo=1 ORDER BY nombre ASC");
while ($ag_row = $res_ag->fetch_assoc()) $agentes_filtro[] = $ag_row;

$sql = "SELECT * FROM tickets" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY FIELD(prioridad,'Critica','Alta','Media','Baja'), created_at DESC";

$POR_PAGINA   = 10;
$paginaActual = max(1, intval($_GET['pag'] ?? 1));
$totalFiltrado = $db->query("SELECT COUNT(*) c FROM tickets" . ($where ? ' WHERE ' . implode(' AND ', $where) : ''))->fetch_assoc()['c'];
$pag = paginar($totalFiltrado, $POR_PAGINA, $paginaActual, array_filter([
    'buscar'   => $buscar,
    'estado'   => $estado,
    'prioridad'=> $prioridad,
    'categoria'=> $categoria,
    'agente'   => $agente,
]));
$result = $db->query($sql . " LIMIT $POR_PAGINA OFFSET {$pag['offset']}");
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SARA</title>
<link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
<link rel="stylesheet" href="style.css">
</head>
<body>
<?= renderNav('index.php') ?>
<div class="page">
  <div class="page-title">🎫 Panel de Tickets</div>

  <!-- Stats -->
  <div class="stats">
    <div class="stat total"><div class="stat-num"><?= $total ?></div><div class="stat-label">Total</div></div>
    <div class="stat abierto"><div class="stat-num"><?= $stats['Abierto'] ?></div><div class="stat-label">Abiertos</div></div>
    <div class="stat progreso"><div class="stat-num"><?= $stats['En Progreso'] ?></div><div class="stat-label">En Progreso</div></div>
    <div class="stat resuelto"><div class="stat-num"><?= $stats['Resuelto'] ?></div><div class="stat-label">Resueltos</div></div>
    <div class="stat cerrado"><div class="stat-num"><?= $stats['Cerrado'] ?></div><div class="stat-label">Cerrados</div></div>
  </div>

  <!-- Filters -->
  <form method="GET" style="margin-bottom:20px;">
    <div class="filters">
      <input type="text" name="buscar" placeholder="🔍 Buscar por título, número, solicitante…" value="<?= htmlspecialchars($buscar) ?>">
      <select name="estado">
        <option value="">Todos los estados</option>
        <?php foreach(['Abierto','En Progreso','Resuelto','Cerrado'] as $e): ?>
          <option <?= $estado==$e?'selected':''?>><?= $e ?></option>
        <?php endforeach; ?>
      </select>
      <select name="prioridad">
        <option value="">Toda prioridad</option>
        <?php foreach(['Baja','Media','Alta','Critica'] as $p): ?>
          <option <?= $prioridad==$p?'selected':''?>><?= $p ?></option>
        <?php endforeach; ?>
      </select>
      <select name="categoria">
        <option value="">Toda categoría</option>
        <?php foreach(['Software'] as $c): ?>
          <option <?= $categoria==$c?'selected':''?>><?= $c ?></option>
        <?php endforeach; ?>
      </select>
      <select name="agente">
        <option value="">Todos los agentes</option>
        <option value="0" <?= $agente==='0'?'selected':''?>>— Sin asignar</option>
        <?php foreach($agentes_filtro as $ag_row): ?>
          <option value="<?= $ag_row['id'] ?>" <?= $agente==$ag_row['id']?'selected':''?>>
            👤 <?= htmlspecialchars($ag_row['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
      <a href="index.php" class="btn btn-ghost btn-sm">Limpiar</a>
      <a href="nuevo_ticket.php" class="btn btn-success btn-sm" style="margin-left:auto;">➕ Nuevo Ticket</a>
    </div>
  </form>

  <!-- Table (desktop/tablet) -->
  <div class="card" style="padding:0;">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>N° Ticket</th>
            <th>Título</th>
            <th class="hide-mobile">Categoría</th>
            <th>Prioridad</th>
            <th>Estado</th>
            <th class="hide-mobile">Solicitante</th>
            <th class="hide-mobile">Agente</th>
            <th class="hide-mobile">Creado</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($result->num_rows === 0): ?>
          <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:40px;">No se encontraron tickets</td></tr>
        <?php else: ?>
          <?php
          $rows = [];
          while ($t = $result->fetch_assoc()) $rows[] = $t;
          foreach ($rows as $t):
          ?>
          <tr>
            <td><code style="color:var(--accent);font-size:.78rem;"><?= $t['numero'] ?></code></td>
            <td><a href="ver_ticket.php?id=<?= $t['id'] ?>"><?= htmlspecialchars($t['titulo']) ?></a></td>
            <td class="hide-mobile"><span style="color:var(--muted)"><?= $t['categoria'] ?></span></td>
            <td><?= badgePrioridad($t['prioridad']) ?></td>
            <td><?= badgeEstado($t['estado']) ?></td>
            <td class="hide-mobile"><?= htmlspecialchars($t['solicitante']) ?></td>
            <td class="hide-mobile"><?= $t['agente'] ? htmlspecialchars($t['agente']) : '<span style="color:var(--muted)">—</span>' ?></td>
            <td class="hide-mobile" style="color:var(--muted);white-space:nowrap;"><?= timeAgo($t['created_at']) ?></td>
            <td><a href="ver_ticket.php?id=<?= $t['id'] ?>" class="btn btn-ghost btn-sm">Ver</a></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Tarjetas móvil (visible solo en < 768px) -->
    <div class="table-card-mode" style="padding:12px;">
      <?php if (empty($rows)): ?>
        <div style="text-align:center;color:var(--muted);padding:32px;">No se encontraron tickets</div>
      <?php else: ?>
        <?php foreach ($rows as $t): ?>
        <div class="t-card">
          <div class="t-card-header">
            <div>
              <div class="t-card-num"><?= $t['numero'] ?></div>
              <div class="t-card-title">
                <a href="ver_ticket.php?id=<?= $t['id'] ?>" style="color:var(--text);">
                  <?= htmlspecialchars($t['titulo']) ?>
                </a>
              </div>
            </div>
            <?= badgePrioridad($t['prioridad']) ?>
          </div>
          <div class="t-card-badges">
            <?= badgeEstado($t['estado']) ?>
            <span class="badge" style="background:var(--surface);color:var(--muted);border:1px solid var(--border);"><?= $t['categoria'] ?></span>
          </div>
          <div class="t-card-meta">
            <span>👤 <?= htmlspecialchars($t['solicitante']) ?></span>
            <span>🔧 <?= $t['agente'] ? htmlspecialchars($t['agente']) : 'Sin asignar' ?></span>
            <span>🕐 <?= timeAgo($t['created_at']) ?></span>
          </div>
          <div class="t-card-action">
            <a href="ver_ticket.php?id=<?= $t['id'] ?>" class="btn btn-ghost btn-sm btn-full">Ver ticket →</a>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?= renderPaginacion($pag) ?>
  </div>
</div>
</body>
</html>
