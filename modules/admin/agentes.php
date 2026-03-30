<?php
require_once __DIR__ . '/../../core/auth.php';
requirePerfil('coordinador');
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';

$db  = getDB();
$msg = '';
$err = '';

/* ─── ACCIONES POST ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* CREAR */
    if ($action === 'crear') {
        $nombre       = trim($_POST['nombre']       ?? '');
        $email        = trim($_POST['email']        ?? '');
        $telefono     = trim($_POST['telefono']     ?? '');
        $departamento = trim($_POST['departamento'] ?? '');

        if (!$nombre) {
            $err = 'El nombre del agente es obligatorio.';
        } else {
            $stmt = $db->prepare("INSERT INTO agentes (nombre, email, telefono, departamento) VALUES (?,?,?,?)");
            $stmt->bind_param('ssss', $nombre, $email, $telefono, $departamento);
            $stmt->execute() ? $msg = "Agente «$nombre» creado correctamente." : $err = $stmt->error;
        }
    }

    /* EDITAR */
    if ($action === 'editar') {
        $id           = intval($_POST['id']);
        $nombre       = trim($_POST['nombre']       ?? '');
        $email        = trim($_POST['email']        ?? '');
        $telefono     = trim($_POST['telefono']     ?? '');
        $departamento = trim($_POST['departamento'] ?? '');
        $activo       = intval($_POST['activo']     ?? 1);

        if (!$nombre) {
            $err = 'El nombre no puede estar vacío.';
        } else {
            $stmt = $db->prepare("UPDATE agentes SET nombre=?, email=?, telefono=?, departamento=?, activo=? WHERE id=?");
            $stmt->bind_param('ssssii', $nombre, $email, $telefono, $departamento, $activo, $id);
            $stmt->execute() ? $msg = "Agente actualizado correctamente." : $err = $stmt->error;
            // Sync nombre en tickets
            $n = $db->real_escape_string($nombre);
            $db->query("UPDATE tickets SET agente='$n' WHERE agente_id=$id");
        }
    }

    /* TOGGLE ACTIVO */
    if ($action === 'toggle') {
        $id     = intval($_POST['id']);
        $actual = intval($db->query("SELECT activo FROM agentes WHERE id=$id")->fetch_assoc()['activo'] ?? 1);
        $nuevo  = $actual ? 0 : 1;
        $db->query("UPDATE agentes SET activo=$nuevo WHERE id=$id");
        $msg = $nuevo ? 'Agente activado.' : 'Agente desactivado.';
    }

    /* ELIMINAR */
    if ($action === 'eliminar') {
        $id     = intval($_POST['id']);
        $usados = intval($db->query("SELECT COUNT(*) c FROM tickets WHERE agente_id=$id")->fetch_assoc()['c']);
        if ($usados > 0) {
            $err = "No se puede eliminar: este agente tiene $usados ticket(s) asignado(s). Desactívalo en su lugar.";
        } else {
            $db->query("DELETE FROM agentes WHERE id=$id");
            $msg = 'Agente eliminado.';
        }
    }
}

/* ─── DATOS ─────────────────────────────────────────────────── */
// Agentes con estadísticas de tickets
$agentes = $db->query("
    SELECT a.*,
        COUNT(t.id)                                     AS total,
        SUM(t.estado = 'Abierto')                       AS abiertos,
        SUM(t.estado = 'En Progreso')                   AS en_progreso,
        SUM(t.estado = 'Resuelto' OR t.estado='Cerrado') AS resueltos
    FROM agentes a
    LEFT JOIN tickets t ON t.agente_id = a.id
    GROUP BY a.id
    ORDER BY a.activo DESC, a.nombre ASC
");

// Para edición inline (si se pasa ?editar=ID)
$editando = null;
if (isset($_GET['editar'])) {
    $eid     = intval($_GET['editar']);
    $editando = $db->query("SELECT * FROM agentes WHERE id=$eid")->fetch_assoc();
}

// Totales resumen
$tot = $db->query("SELECT COUNT(*) t, SUM(activo) a FROM agentes")->fetch_assoc();
$total_agentes  = $tot['t'] ?? 0;
$activos_cnt    = $tot['a'] ?? 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Agentes</title>
<link rel="shortcut icon" type="image/x-icon" href="<?= BASE_URL ?>/favicon.ico">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<style>
/* Avatar circle */
.avatar {
    width: 38px; height: 38px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: .95rem; flex-shrink: 0;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    color: #fff;
}
.avatar.inactivo { background: var(--border); color: var(--muted); }

/* Agente card (mobile) */
.agent-row td { vertical-align: middle; }
.agent-row.inactivo td { opacity: .5; }

/* Mini stat pills */
.mini-stats { display: flex; gap: 6px; flex-wrap: wrap; }
.ms { font-size: .74rem; font-weight: 600; padding: 2px 8px; border-radius: 12px; }
.ms-open  { background:#1a3a5c; color:#58a6ff; }
.ms-prog  { background:#3d2e00; color:#f5a623; }
.ms-done  { background:#0d2e1a; color:#2dbe6c; }
.ms-total { background:var(--surface); color:var(--muted); border:1px solid var(--border); }

/* Dept badge */
.dept { background: var(--surface); border: 1px solid var(--border); border-radius: 20px;
        padding: 2px 10px; font-size: .75rem; color: var(--muted); white-space: nowrap; }

/* Form panel */
.form-panel {
    background: var(--card);
    border: 1px solid var(--accent);
    border-radius: var(--radius);
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 0 0 1px rgba(79,142,247,.15), var(--shadow);
}
.form-panel h3 { font-size: 1rem; margin-bottom: 18px; color: var(--accent); }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media(max-width:600px) { .form-grid { grid-template-columns: 1fr; } }

/* Status dot */
.dot { width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:5px; }
.dot-on  { background:var(--green); box-shadow:0 0 6px var(--green); }
.dot-off { background:var(--muted); }
</style>
</head>
<body>
<?= renderNav(BASE_URL . '/modules/admin/agentes.php') ?>

<div class="page">
  <div class="page-title">👥 Gestión de Agentes</div>

  <?php if ($msg): ?>
    <div class="alert alert-ok">✅ <?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert alert-err">⚠ <?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <!-- KPIs rápidos -->
  <div class="stats" style="margin-bottom:24px;">
    <div class="stat total">
      <div class="stat-num"><?= $total_agentes ?></div>
      <div class="stat-label">Total Agentes</div>
    </div>
    <div class="stat resuelto">
      <div class="stat-num"><?= $activos_cnt ?></div>
      <div class="stat-label">Activos</div>
    </div>
    <div class="stat cerrado">
      <div class="stat-num"><?= $total_agentes - $activos_cnt ?></div>
      <div class="stat-label">Inactivos</div>
    </div>
  </div>

  <!-- ═══ FORMULARIO CREAR / EDITAR ══════════════════════════ -->
  <?php if ($editando): ?>
  <!-- MODO EDICIÓN -->
  <div class="form-panel">
    <h3>✏ Editar Agente — <?= htmlspecialchars($editando['nombre']) ?></h3>
    <form method="POST">
      <input type="hidden" name="action" value="editar">
      <input type="hidden" name="id" value="<?= $editando['id'] ?>">
      <div class="form-grid">
        <div class="form-group">
          <label>Nombre completo *</label>
          <input type="text" name="nombre" value="<?= htmlspecialchars($editando['nombre']) ?>" required>
        </div>
        <div class="form-group">
          <label>Correo electrónico</label>
          <input type="email" name="email" value="<?= htmlspecialchars($editando['email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Teléfono / Extensión</label>
          <input type="text" name="telefono" value="<?= htmlspecialchars($editando['telefono'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Departamento</label>
          <input type="text" name="departamento" placeholder="Ej: TI, Redes, Soporte N2…" value="<?= htmlspecialchars($editando['departamento'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Estado</label>
          <select name="activo">
            <option value="1" <?= $editando['activo'] ? 'selected' : '' ?>>✅ Activo</option>
            <option value="0" <?= !$editando['activo'] ? 'selected' : '' ?>>⏸ Inactivo</option>
          </select>
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:6px;">
        <button type="submit" class="btn btn-primary">💾 Guardar Cambios</button>
        <a href="<?= BASE_URL ?>/modules/admin/agentes.php" class="btn btn-ghost">Cancelar</a>
      </div>
    </form>
  </div>

  <?php else: ?>
  <!-- MODO CREACIÓN -->
  <div class="form-panel">
    <h3>➕ Nuevo Agente</h3>
    <form method="POST">
      <input type="hidden" name="action" value="crear">
      <div class="form-grid">
        <div class="form-group">
          <label>Nombre completo *</label>
          <input type="text" name="nombre" placeholder="Ej: Juan García" required>
        </div>
        <div class="form-group">
          <label>Correo electrónico</label>
          <input type="email" name="email" placeholder="agente@empresa.com">
        </div>
        <div class="form-group">
          <label>Teléfono / Extensión</label>
          <input type="text" name="telefono" placeholder="Ext. 1234">
        </div>
        <div class="form-group">
          <label>Departamento</label>
          <input type="text" name="departamento" placeholder="Ej: Soporte TI, Redes…">
        </div>
      </div>
      <button type="submit" class="btn btn-success" style="margin-top:4px;">👤 Crear Agente</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- ═══ TABLA DE AGENTES ═══════════════════════════════════ -->
  <div class="card" style="padding:0;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
      <span style="font-size:.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;">
        Lista de agentes (<?= $total_agentes ?>)
      </span>
    </div>

    <?php if ($total_agentes == 0): ?>
      <div style="text-align:center;padding:48px;color:var(--muted);">
        <div style="font-size:2.5rem;margin-bottom:12px;">👤</div>
        <p>No hay agentes registrados aún.</p>
        <p style="font-size:.85rem;margin-top:6px;">Usa el formulario de arriba para crear el primero.</p>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:44px;"></th>
            <th>Agente</th>
            <th>Contacto</th>
            <th>Departamento</th>
            <th>Estado</th>
            <th>Tickets asignados</th>
            <th>Alta</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php $agentes->data_seek(0); while ($ag = $agentes->fetch_assoc()): ?>
          <?php
            $iniciales = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', $ag['nombre']), 0, 2)));
            $clsRow    = !$ag['activo'] ? ' class="agent-row inactivo"' : ' class="agent-row"';
          ?>
          <tr<?= $clsRow ?>>
            <!-- Avatar -->
            <td>
              <div class="avatar <?= !$ag['activo'] ? 'inactivo' : '' ?>">
                <?= htmlspecialchars($iniciales) ?>
              </div>
            </td>

            <!-- Nombre -->
            <td>
              <strong><?= htmlspecialchars($ag['nombre']) ?></strong>
            </td>

            <!-- Contacto -->
            <td style="font-size:.82rem;color:var(--muted);">
              <?php if ($ag['email']): ?>
                <div><a href="mailto:<?= htmlspecialchars($ag['email']) ?>" style="color:var(--accent);">
                  <?= htmlspecialchars($ag['email']) ?></a></div>
              <?php endif; ?>
              <?php if ($ag['telefono']): ?>
                <div>📞 <?= htmlspecialchars($ag['telefono']) ?></div>
              <?php endif; ?>
              <?php if (!$ag['email'] && !$ag['telefono']): ?>
                <span style="color:var(--border)">—</span>
              <?php endif; ?>
            </td>

            <!-- Departamento -->
            <td>
              <?php if ($ag['departamento']): ?>
                <span class="dept"><?= htmlspecialchars($ag['departamento']) ?></span>
              <?php else: ?>
                <span style="color:var(--border)">—</span>
              <?php endif; ?>
            </td>

            <!-- Estado -->
            <td>
              <?php if ($ag['activo']): ?>
                <span><span class="dot dot-on"></span>Activo</span>
              <?php else: ?>
                <span><span class="dot dot-off"></span>Inactivo</span>
              <?php endif; ?>
            </td>

            <!-- Mini stats tickets -->
            <td>
              <div class="mini-stats">
                <span class="ms ms-total" title="Total"><?= $ag['total'] ?> total</span>
                <?php if ($ag['abiertos'] > 0): ?>
                  <span class="ms ms-open"><?= $ag['abiertos'] ?> abierto<?= $ag['abiertos']>1?'s':'' ?></span>
                <?php endif; ?>
                <?php if ($ag['en_progreso'] > 0): ?>
                  <span class="ms ms-prog"><?= $ag['en_progreso'] ?> en progreso</span>
                <?php endif; ?>
                <?php if ($ag['resueltos'] > 0): ?>
                  <span class="ms ms-done"><?= $ag['resueltos'] ?> resuelto<?= $ag['resueltos']>1?'s':'' ?></span>
                <?php endif; ?>
                <?php if ($ag['total'] == 0): ?>
                  <span class="ms ms-total">Sin tickets</span>
                <?php endif; ?>
              </div>
            </td>

            <!-- Fecha alta -->
            <td style="color:var(--muted);font-size:.8rem;white-space:nowrap;">
              <?= date('d/m/Y', strtotime($ag['created_at'])) ?>
            </td>

            <!-- Acciones -->
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <!-- Editar -->
                <a href="<?= BASE_URL ?>/modules/admin/agentes.php?editar=<?= $ag['id'] ?>" class="btn btn-ghost btn-sm">✏ Editar</a>

                <!-- Toggle activo -->
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= $ag['id'] ?>">
                  <button type="submit" class="btn btn-sm"
                    style="background:<?= $ag['activo'] ? 'var(--border)' : '#0d2e1a' ?>;
                           color:<?= $ag['activo'] ? 'var(--muted)' : 'var(--green)' ?>;">
                    <?= $ag['activo'] ? '⏸ Desactivar' : '▶ Activar' ?>
                  </button>
                </form>

                <!-- Eliminar -->
                <form method="POST" style="display:inline;"
                  onsubmit="return confirm('¿Seguro que deseas eliminar al agente «<?= htmlspecialchars(addslashes($ag['nombre'])) ?>»?\nSolo es posible si no tiene tickets asignados.');">
                  <input type="hidden" name="action" value="eliminar">
                  <input type="hidden" name="id" value="<?= $ag['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">🗑</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Nota de migración -->
  <p style="margin-top:16px;font-size:.78rem;color:var(--muted);">
    ¿Primera vez usando este módulo? Asegúrate de haber ejecutado
    <a href="<?= BASE_URL ?>/setup/migracion_agentes.php">migracion_agentes.php</a> para crear la tabla en la base de datos.
  </p>
</div>
</body>
</html>
