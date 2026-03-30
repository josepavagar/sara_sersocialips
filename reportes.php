<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$db = getDB();

// Date range filter
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');

$cond  = "DATE(created_at) BETWEEN '$desde' AND '$hasta'";
$where = "WHERE $cond";

// By status
$r_estado = [];
$res = $db->query("SELECT estado, COUNT(*) c FROM tickets $where GROUP BY estado");
while ($row = $res->fetch_assoc()) $r_estado[$row['estado']] = $row['c'];

// By priority
$r_prio = [];
$res = $db->query("SELECT prioridad, COUNT(*) c FROM tickets $where GROUP BY prioridad");
while ($row = $res->fetch_assoc()) $r_prio[$row['prioridad']] = $row['c'];

// By category
$r_cat = [];
$res = $db->query("SELECT categoria, COUNT(*) c FROM tickets $where GROUP BY categoria ORDER BY c DESC");
while ($row = $res->fetch_assoc()) $r_cat[$row['categoria']] = $row['c'];

// By day (last 30 days trend)
$r_trend = [];
$res = $db->query("SELECT DATE(created_at) d, COUNT(*) c FROM tickets WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY d ASC");
while ($row = $res->fetch_assoc()) $r_trend[$row['d']] = $row['c'];

// Top agents
$r_agents = [];
$res = $db->query("SELECT agente, COUNT(*) c FROM tickets WHERE agente IS NOT NULL AND agente != '' AND $cond GROUP BY agente ORDER BY c DESC LIMIT 5");
while ($row = $res->fetch_assoc()) $r_agents[] = $row;

// Agentes con desglose por estado (para torta y barras apiladas)
$r_agents_estados = [];
$res2 = $db->query("
    SELECT
        agente,
        COUNT(*) AS total,
        SUM(estado = 'Abierto')     AS abiertos,
        SUM(estado = 'En Progreso') AS en_progreso,
        SUM(estado = 'Resuelto')    AS resueltos,
        SUM(estado = 'Cerrado')     AS cerrados
    FROM tickets
    WHERE agente IS NOT NULL AND agente != '' AND $cond
    GROUP BY agente
    ORDER BY total DESC
    LIMIT 8
");
while ($row = $res2->fetch_assoc()) $r_agents_estados[] = $row;

// Avg resolution time (Resuelto/Cerrado)
$r_avg = $db->query("SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) avg_h FROM tickets WHERE estado IN ('Resuelto','Cerrado') AND $cond")->fetch_assoc();
$avg_h = round($r_avg['avg_h'] ?? 0, 1);

// Total this period
$total = $db->query("SELECT COUNT(*) c FROM tickets $where")->fetch_assoc()['c'];

// Paginación del listado detallado
$RPT_POR_PAG   = 20;
$rpt_pag_actual= max(1, intval($_GET['pag'] ?? 1));
$rpt_pag = paginar($total, $RPT_POR_PAG, $rpt_pag_actual, array_filter([
    'desde' => $desde,
    'hasta' => $hasta,
]));

// Tickets list for export (paginado)
$tickets_all = $db->query("SELECT * FROM tickets $where ORDER BY created_at DESC LIMIT $RPT_POR_PAG OFFSET {$rpt_pag['offset']}");
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reportes</title>
<link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
<link rel="stylesheet" href="style.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
</head>
<body>
<?= renderNav('reportes.php') ?>
<div class="page">
  <div class="page-title">📊 Reportes y Estadísticas</div>

  <!-- Date filter -->
  <form method="GET" style="margin-bottom:24px;">
    <div class="filters">
      <label style="color:var(--muted);margin:0;align-self:center;">Período:</label>
      <input type="date" name="desde" value="<?= $desde ?>" style="max-width:160px;">
      <span style="color:var(--muted);align-self:center;">hasta</span>
      <input type="date" name="hasta" value="<?= $hasta ?>" style="max-width:160px;">
      <button type="submit" class="btn btn-primary btn-sm">Aplicar</button>
      <a href="reportes.php" class="btn btn-ghost btn-sm">Este mes</a>
      <a href="?desde=<?= date('Y-m-d', strtotime('-7 days')) ?>&hasta=<?= date('Y-m-d') ?>" class="btn btn-ghost btn-sm">Últimos 7 días</a>
      <button onclick="window.print()" type="button" class="btn btn-ghost btn-sm" style="margin-left:auto;">🖨 Imprimir</button>
    </div>
  </form>

  <!-- KPIs -->
  <div class="stats" style="margin-bottom:28px;">
    <div class="stat total"><div class="stat-num"><?= $total ?></div><div class="stat-label">Tickets del período</div></div>
    <div class="stat abierto"><div class="stat-num"><?= $r_estado['Abierto'] ?? 0 ?></div><div class="stat-label">Abiertos</div></div>
    <div class="stat progreso"><div class="stat-num"><?= $r_estado['En Progreso'] ?? 0 ?></div><div class="stat-label">En Progreso</div></div>
    <div class="stat resuelto"><div class="stat-num"><?= $r_estado['Resuelto'] ?? 0 ?></div><div class="stat-label">Resueltos</div></div>
    <div class="stat" style=""><div class="stat-num" style="color:var(--yellow)"><?= $avg_h ?>h</div><div class="stat-label">Tiempo prom. resolución</div></div>
  </div>
  <!-- ═══ GRÁFICAS DE AGENTES ═══════════════════════════════ -->
  <?php if (!empty($r_agents_estados)): ?>
  <div class="grid-2" style="margin-bottom:20px;">

    <!-- Torta: tickets por agente -->
    <div class="card">
      <div style="font-size:.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:16px;">
        🥧 Distribución de tickets por agente
      </div>
      <canvas id="chartAgentesTorta" height="240"></canvas>
    </div>

    <!-- Barras apiladas: estados por agente -->
    <div class="card">
      <div style="font-size:.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:16px;">
        📊 Estados de tickets por agente
      </div>
      <canvas id="chartAgentesEstados" height="240"></canvas>
    </div>

  </div>

  <!-- Tabla resumen agentes + estados -->
  <div class="card" style="padding:0;margin-bottom:20px;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);font-size:.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;">
      Detalle de agentes por estado
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Agente</th>
            <th style="color:#58a6ff;">Abiertos</th>
            <th style="color:#f5a623;">En Progreso</th>
            <th style="color:#2dbe6c;">Resueltos</th>
            <th style="color:#7a85a3;">Cerrados</th>
            <th>Total</th>
            <th>% Resuelto</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($r_agents_estados as $ag): ?>
          <?php
            $pct = $ag['total'] > 0 ? round(($ag['resueltos'] + $ag['cerrados']) / $ag['total'] * 100) : 0;
            $pct_color = $pct >= 70 ? '#2dbe6c' : ($pct >= 40 ? '#f5a623' : '#e84040');
          ?>
          <tr>
            <td><strong><?= htmlspecialchars($ag['agente']) ?></strong></td>
            <td><span class="badge b-abierto"><?= $ag['abiertos'] ?></span></td>
            <td><span class="badge b-progreso"><?= $ag['en_progreso'] ?></span></td>
            <td><span class="badge b-resuelto"><?= $ag['resueltos'] ?></span></td>
            <td><span class="badge b-cerrado"><?= $ag['cerrados'] ?></span></td>
            <td><strong><?= $ag['total'] ?></strong></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px;">
                <div style="flex:1;background:var(--border);border-radius:4px;height:6px;min-width:60px;">
                  <div style="width:<?= $pct ?>%;background:<?= $pct_color ?>;height:6px;border-radius:4px;transition:width .4s;"></div>
                </div>
                <span style="color:<?= $pct_color ?>;font-weight:700;font-size:.82rem;white-space:nowrap;"><?= $pct ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php else: ?>
  <div class="card" style="margin-bottom:20px;text-align:center;padding:32px;color:var(--muted);">
    <div style="font-size:2rem;margin-bottom:8px;">👥</div>
    <p>Sin datos de agentes para el período seleccionado.</p>
  </div>
  <?php endif; ?>

  <!-- Detail table -->
  <div class="card" style="padding:0;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);font-size:.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;">
      Listado detallado del período
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>N°</th><th>Título</th><th>Categoría</th><th>Prioridad</th><th>Estado</th><th>Solicitante</th><th>Agente</th><th>Creado</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $ctr = 0;
        while ($t = $tickets_all->fetch_assoc()):
            $ctr++;
        ?>
          <tr>
            <td><code style="color:var(--accent);font-size:.78rem;"><?= $t['numero'] ?></code></td>
            <td><a href="ver_ticket.php?id=<?= $t['id'] ?>"><?= htmlspecialchars($t['titulo']) ?></a></td>
            <td style="color:var(--muted)"><?= $t['categoria'] ?></td>
            <td><?= badgePrioridad($t['prioridad']) ?></td>
            <td><?= badgeEstado($t['estado']) ?></td>
            <td><?= htmlspecialchars($t['solicitante']) ?></td>
            <td><?= $t['agente'] ? htmlspecialchars($t['agente']) : '—' ?></td>
            <td style="color:var(--muted);white-space:nowrap;font-size:.8rem;"><?= date('d/m/Y', strtotime($t['created_at'])) ?></td>
          </tr>
        <?php endwhile; ?>
        <?php if ($ctr === 0): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:30px;">Sin tickets en este período</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?= renderPaginacion($rpt_pag) ?>
  </div>
</div>

  <!-- Charts row 1 -->
  <div class="grid-2" style="margin-bottom:20px;">
    <div class="card">
      <div style="font-size:.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin: bottom 16px;px;">Por Estado</div>
      <canvas id="chartEstado" height="200"></canvas>
    </div>
    <div class="card">
      <div style="font-size:.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:16px;">Por Prioridad</div>
      <canvas id="chartPrioridad" height="200"></canvas>
    </div>
  </div>

  <!-- Trend chart -->
  <div class="card" style="margin-bottom:20px;">
    <div style="font-size:.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:16px;">Tendencia — Tickets creados (últimos 30 días)</div>
    <canvas id="chartTrend" height="100"></canvas>
  </div>

  <!-- Category + Agents table -->
  <div class="grid-2" style="margin-bottom:20px;">
    <div class="card">
      <div style="font-size:.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:16px;">Por Categoría</div>
      <canvas id="chartCategoria" height="220"></canvas>
    </div>
    <div class="card">
      <div style="font-size:.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:16px;">Top Agentes (período)</div>
      <?php if (empty($r_agents)): ?>
        <p style="color:var(--muted);font-size:.85rem;">Sin datos de agentes para este período.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>Agente</th><th>Tickets</th></tr></thead>
          <tbody>
            <?php foreach ($r_agents as $a): ?>
              <tr>
                <td><?= htmlspecialchars($a['agente']) ?></td>
                <td>
                  <div style="display:flex;align-items:center;gap:10px;">
                    <div style="background:var(--accent);height:8px;border-radius:4px;width:<?= min(100, $a['c'] * 20) ?>px;"></div>
                    <strong><?= $a['c'] ?></strong>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  
<script>
const dark = { grid: '#2a3048', text: '#7a85a3' };
Chart.defaults.color = dark.text;
Chart.defaults.font.family = "'Inter','Segoe UI',sans-serif";
Chart.defaults.font.size = 12;

function donut(id, labels, data, colors) {
  new Chart(document.getElementById(id), {
    type: 'doughnut',
    data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 0, hoverOffset: 6 }] },
    options: { plugins: { legend: { position: 'bottom', labels: { padding: 14, boxWidth: 12 } } }, cutout: '62%' }
  });
}

function bar(id, labels, data, color) {
  new Chart(document.getElementById(id), {
    type: 'bar',
    data: { labels, datasets: [{ data, backgroundColor: color, borderRadius: 5, borderSkipped: false }] },
    options: {
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { color: dark.grid } },
        y: { grid: { color: dark.grid }, ticks: { stepSize: 1, precision: 0 } }
      }
    }
  });
}

// Estado
const estadoData  = <?= json_encode(array_values($r_estado)) ?>;
const estadoLabel = <?= json_encode(array_keys($r_estado)) ?>;
donut('chartEstado', estadoLabel, estadoData, ['#4f8ef7','#f5a623','#2dbe6c','#4a5268']);

// Prioridad
const prioData  = <?= json_encode(array_values($r_prio)) ?>;
const prioLabel = <?= json_encode(array_keys($r_prio)) ?>;
donut('chartPrioridad', prioLabel, prioData, ['#6abf69','#e8c84a','#f0884a','#f05454']);

// Trend
const trendLabels = <?= json_encode(array_keys($r_trend)) ?>;
const trendData   = <?= json_encode(array_values($r_trend)) ?>;
new Chart(document.getElementById('chartTrend'), {
  type: 'line',
  data: {
    labels: trendLabels,
    datasets: [{
      data: trendData,
      borderColor: '#4f8ef7',
      backgroundColor: 'rgba(79,142,247,.1)',
      fill: true,
      tension: .4,
      pointRadius: 3,
      pointBackgroundColor: '#4f8ef7'
    }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { color: dark.grid } },
      y: { grid: { color: dark.grid }, ticks: { stepSize: 1, precision: 0 }, beginAtZero: true }
    }
  }
});

// Category
const catLabels = <?= json_encode(array_keys($r_cat)) ?>;
const catData   = <?= json_encode(array_values($r_cat)) ?>;
bar('chartCategoria', catLabels, catData, '#7c5cbf');

<?php if (!empty($r_agents_estados)): ?>
// ── Agentes: datos desde PHP ──
const agNames      = <?= json_encode(array_column($r_agents_estados, 'agente')) ?>;
const agTotals     = <?= json_encode(array_map('intval', array_column($r_agents_estados, 'total'))) ?>;
const agAbiertos   = <?= json_encode(array_map('intval', array_column($r_agents_estados, 'abiertos'))) ?>;
const agProgreso   = <?= json_encode(array_map('intval', array_column($r_agents_estados, 'en_progreso'))) ?>;
const agResueltos  = <?= json_encode(array_map('intval', array_column($r_agents_estados, 'resueltos'))) ?>;
const agCerrados   = <?= json_encode(array_map('intval', array_column($r_agents_estados, 'cerrados'))) ?>;

// Paleta dinámica para la torta de agentes
const paletaAgentes = [
  '#4f8ef7','#7c5cbf','#2dbe6c','#f5a623','#e84040',
  '#00c9b1','#e8c84a','#f0884a'
];

// Torta: total de tickets por agente
donut('chartAgentesTorta', agNames, agTotals, paletaAgentes);

// Barras apiladas: estados por agente
new Chart(document.getElementById('chartAgentesEstados'), {
  type: 'bar',
  data: {
    labels: agNames,
    datasets: [
      { label: 'Abierto',      data: agAbiertos,  backgroundColor: '#4f8ef7' },
      { label: 'En Progreso',  data: agProgreso,  backgroundColor: '#f5a623' },
      { label: 'Resuelto',     data: agResueltos, backgroundColor: '#2dbe6c' },
      { label: 'Cerrado',      data: agCerrados,  backgroundColor: '#4a5268' },
    ]
  },
  options: {
    plugins: {
      legend: {
        position: 'bottom',
        labels: { padding: 14, boxWidth: 12 }
      }
    },
    responsive: true,
    scales: {
      x: { stacked: true, grid: { color: dark.grid } },
      y: { stacked: true, grid: { color: dark.grid }, ticks: { stepSize: 1, precision: 0 }, beginAtZero: true }
    }
  }
});
<?php endif; ?>
</script>

<style>
@media print {
  nav, form { display: none !important; }
  body { background: white; color: black; }
  .card { border: 1px solid #ccc; }
}
</style>
</body>
</html>
