<?php
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
requirePerfil('coordinador', 'agente');

$db   = getDB();
$user = currentUser();
$msg  = '';
$err  = '';

/* ══════════════════════════════════════════════════════════
   MAPA ESTADO → AVANCE
   ══════════════════════════════════════════════════════ */
$AVANCE_MAP = ['Pendiente'=>0, 'En Progreso'=>50, 'Completada'=>100, 'Cancelada'=>0];

/* ══════════════════════════════════════════════════════════
   AUTO-MIGRACIÓN
   ══════════════════════════════════════════════════════ */
$db->query("CREATE TABLE IF NOT EXISTS `tareas` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `nombre`        VARCHAR(200) NOT NULL,
    `descripcion`   TEXT DEFAULT NULL,
    `prioridad`     ENUM('Baja','Media','Alta','Critica') NOT NULL DEFAULT 'Media',
    `estado`        ENUM('Pendiente','En Progreso','Completada','Cancelada') NOT NULL DEFAULT 'Pendiente',
    `agente_id`     INT DEFAULT NULL,
    `agente_nombre` VARCHAR(120) DEFAULT NULL,
    `fecha_inicio`  DATE NOT NULL,
    `fecha_fin`     DATE NOT NULL,
    `avance`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `creado_por`    VARCHAR(120) DEFAULT NULL,
    `creado_en`     DATETIME DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en`DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_estado    (estado),
    INDEX idx_agente    (agente_id),
    INDEX idx_fecha_fin (fecha_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS `tareas_comentarios` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `tarea_id`   INT NOT NULL,
    `autor`      VARCHAR(120) NOT NULL,
    `mensaje`    TEXT NOT NULL,
    `estado_nuevo` VARCHAR(40) DEFAULT NULL,
    `avance`     TINYINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tarea_id) REFERENCES tareas(id) ON DELETE CASCADE,
    INDEX idx_tarea (tarea_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ══════════════════════════════════════════════════════════
   ACCIONES POST
   ══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ── CREAR TAREA — siempre Pendiente 0% ── */
    if ($action === 'crear') {
        $nombre      = trim($_POST['nombre']      ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $prioridad   = $_POST['prioridad']        ?? 'Media';
        $agente_id   = intval($_POST['agente_id'] ?? 0);
        $fecha_ini   = $_POST['fecha_inicio']     ?? '';
        $fecha_fin   = $_POST['fecha_fin']        ?? '';

        if (!$nombre || !$fecha_ini || !$fecha_fin) {
            $err = 'Nombre, fecha inicio y fecha fin son obligatorios.';
        } elseif ($fecha_fin < $fecha_ini) {
            $err = 'La fecha final no puede ser anterior a la fecha inicial.';
        } else {
            $agente_nombre = '';
            if ($agente_id) {
                $ag = $db->query("SELECT nombre FROM agentes WHERE id=$agente_id")->fetch_assoc();
                $agente_nombre = $ag['nombre'] ?? '';
            }
            $creado_por = $user['nombre'];
            $estado     = 'Pendiente';
            $avance     = 0;
            $stmt = $db->prepare("INSERT INTO tareas
                (nombre,descripcion,prioridad,estado,agente_id,agente_nombre,fecha_inicio,fecha_fin,avance,creado_por)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('ssssisssis',
                $nombre,$descripcion,$prioridad,$estado,
                $agente_id,$agente_nombre,$fecha_ini,$fecha_fin,$avance,$creado_por);
            if ($stmt->execute()) {
                $tid    = $stmt->insert_id;
                $autor  = $db->real_escape_string($creado_por);
                $db->query("INSERT INTO tareas_comentarios (tarea_id,autor,mensaje,estado_nuevo,avance)
                    VALUES ($tid,'$autor','Tarea creada. Estado inicial: Pendiente (0%).','Pendiente',0)");
                header("Location: " . BASE_URL . "/modules/admin/tareas.php?msg=" . urlencode("Tarea «{$nombre}» creada correctamente."));
                exit;
            } else {
                $err = 'Error: ' . $stmt->error;
            }
        }
    }

    /* ── EDITAR TAREA (solo nombre, desc, prioridad, agente, fechas) ── */
    if ($action === 'editar') {
        $tid         = intval($_POST['id']);
        $nombre      = trim($_POST['nombre']      ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $prioridad   = $_POST['prioridad']        ?? 'Media';
        $agente_id   = intval($_POST['agente_id'] ?? 0);
        $fecha_ini   = $_POST['fecha_inicio']     ?? '';
        $fecha_fin   = $_POST['fecha_fin']        ?? '';

        if (!$nombre || !$fecha_ini || !$fecha_fin) {
            $err = 'Nombre, fecha inicio y fecha fin son obligatorios.';
        } else {
            $agente_nombre = '';
            if ($agente_id) {
                $ag = $db->query("SELECT nombre FROM agentes WHERE id=$agente_id")->fetch_assoc();
                $agente_nombre = $ag['nombre'] ?? '';
            }
            $stmt = $db->prepare("UPDATE tareas SET
                nombre=?,descripcion=?,prioridad=?,agente_id=?,agente_nombre=?,fecha_inicio=?,fecha_fin=?
                WHERE id=?");
            $stmt->bind_param('sssississi',
                $nombre,$descripcion,$prioridad,$agente_id,$agente_nombre,$fecha_ini,$fecha_fin,$tid);
            if ($stmt->execute()) {
                header("Location: " . BASE_URL . "/modules/admin/tareas.php?msg=" . urlencode('Tarea actualizada correctamente.'));
                exit;
            } else {
                $err = 'Error: ' . $stmt->error;
            }
        }
    }

    /* ── CAMBIAR ESTADO — avance automático ── */
    if ($action === 'cambiar_estado') {
        $tid        = intval($_POST['tarea_id']);
        $nuevo_est  = $_POST['nuevo_estado'] ?? '';
        $validos    = ['Pendiente','En Progreso','Completada','Cancelada'];
        $mensaje    = trim($_POST['mensaje'] ?? '');

        if (!in_array($nuevo_est, $validos)) {
            $err = 'Estado inválido.';
        } else {
            global $AVANCE_MAP;
            $avance = $AVANCE_MAP[$nuevo_est];
            $db->query("UPDATE tareas SET estado='$nuevo_est', avance=$avance WHERE id=$tid");
            $autor  = $db->real_escape_string($user['nombre']);
            $ne     = $db->real_escape_string($nuevo_est);
            $note   = $mensaje ?: "Estado actualizado a «$nuevo_est» · Avance automático: $avance%";
            $note   = $db->real_escape_string($note);
            $db->query("INSERT INTO tareas_comentarios (tarea_id,autor,mensaje,estado_nuevo,avance)
                VALUES ($tid,'$autor','$note','$ne',$avance)");
            $msg = "Estado actualizado a «$nuevo_est» ($avance%).";
            header("Location: tareas.php?ver=$tid&msg=" . urlencode($msg));
            exit;
        }
    }

    /* ── COMENTARIO ── */
    if ($action === 'comentar') {
        $tid     = intval($_POST['tarea_id']);
        $mensaje = trim($_POST['mensaje'] ?? '');
        $autor   = $db->real_escape_string($user['nombre']);
        if ($mensaje) {
            $msj = $db->real_escape_string($mensaje);
            $db->query("INSERT INTO tareas_comentarios (tarea_id,autor,mensaje)
                VALUES ($tid,'$autor','$msj')");
            $msg = 'Comentario registrado.';
        }
        header("Location: tareas.php?ver=$tid&msg=" . urlencode($msg));
        exit;
    }

    /* ── ELIMINAR ── */
    if ($action === 'eliminar') {
        $tid = intval($_POST['id']);
        $db->query("DELETE FROM tareas WHERE id=$tid");
        header("Location: " . BASE_URL . "/modules/admin/tareas.php?msg=" . urlencode('Tarea eliminada.'));
        exit;
    }
}

/* ══════════════════════════════════════════════════════════
   HELPERS
   ══════════════════════════════════════════════════════ */
function diasRestantes(string $fecha_fin): int {
    return (int)ceil((strtotime($fecha_fin) - strtotime(date('Y-m-d'))) / 86400);
}
function colorAvance(int $pct): string {
    if ($pct >= 100) return '#27a96c';
    if ($pct >= 50)  return '#f5a623';
    return '#e8681a';
}
function estadoConfig(string $e): array {
    return match($e) {
        'Completada'  => ['bg'=>'rgba(39,169,108,.18)',  'txt'=>'#3dd68c', 'icon'=>'✅'],
        'En Progreso' => ['bg'=>'rgba(245,166,35,.15)',  'txt'=>'#f5c040', 'icon'=>'⚙'],
        'Cancelada'   => ['bg'=>'rgba(107,138,168,.12)', 'txt'=>'#7a9ab8', 'icon'=>'⛔'],
        default       => ['bg'=>'rgba(232,104,26,.12)',  'txt'=>'#f0944a', 'icon'=>'🕐'],
    };
}

/* ══════════════════════════════════════════════════════════
   DATOS
   ══════════════════════════════════════════════════════ */
$ver_id = intval($_GET['ver'] ?? 0);
if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);
if ($ver_id) {
    $tarea_det       = $db->query("SELECT * FROM tareas WHERE id=$ver_id")->fetch_assoc();
    $comentarios_res = $db->query("SELECT * FROM tareas_comentarios WHERE tarea_id=$ver_id ORDER BY created_at ASC");
}

$editar_id  = intval($_GET['editar'] ?? 0);
$tarea_edit = $editar_id ? $db->query("SELECT * FROM tareas WHERE id=$editar_id")->fetch_assoc() : null;

/* KPIs — 4 estados */
$kpi_estados = ['Pendiente','En Progreso','Completada','Cancelada'];
$stats = [];
foreach ($kpi_estados as $s) {
    $r = $db->query("SELECT COUNT(*) c FROM tareas WHERE estado='$s'");
    $stats[$s] = (int)$r->fetch_assoc()['c'];
}

/* Alertas */
$hoy       = date('Y-m-d');
$en3       = date('Y-m-d', strtotime('+3 days'));
$vencidas  = $db->query("SELECT * FROM tareas WHERE fecha_fin<'$hoy' AND estado NOT IN('Completada','Cancelada') ORDER BY fecha_fin ASC");
$porVencer = $db->query("SELECT * FROM tareas WHERE fecha_fin BETWEEN '$hoy' AND '$en3' AND estado NOT IN('Completada','Cancelada') ORDER BY fecha_fin ASC");

/* Filtros */
$f_estado    = $_GET['f_estado']    ?? '';
$f_agente    = $_GET['f_agente']    ?? '';
$f_prioridad = $_GET['f_prioridad'] ?? '';
$w = [];
if ($f_estado)    $w[] = "estado='"    . $db->real_escape_string($f_estado)    . "'";
if ($f_agente)    $w[] = "agente_id="  . intval($f_agente);
if ($f_prioridad) $w[] = "prioridad='" . $db->real_escape_string($f_prioridad) . "'";
$wq = $w ? 'WHERE '.implode(' AND ',$w) : '';

$ORDER = "ORDER BY FIELD(estado,'En Progreso','Pendiente','Completada','Cancelada'),
    FIELD(prioridad,'Critica','Alta','Media','Baja'), fecha_fin ASC";

/* Paginación — 4 tarjetas por página */
$POR_PAG    = 4;
$pag_actual = max(1, intval($_GET['pag'] ?? 1));
$total_tar  = (int)$db->query("SELECT COUNT(*) c FROM tareas $wq")->fetch_assoc()['c'];
$pag_tareas = paginar($total_tar, $POR_PAG, $pag_actual, array_filter([
    'f_estado'    => $f_estado,
    'f_agente'    => $f_agente,
    'f_prioridad' => $f_prioridad,
]));
$tareas = $db->query("SELECT * FROM tareas $wq $ORDER LIMIT $POR_PAG OFFSET {$pag_tareas['offset']}");

/* Agentes */
$agentes_q = $db->query("SELECT id,nombre FROM agentes WHERE activo=1 ORDER BY nombre");
$agentes_list = [];
while ($a = $agentes_q->fetch_assoc()) $agentes_list[] = $a;

$pcolors = ['Baja'=>'b-baja','Media'=>'b-media','Alta'=>'b-alta','Critica'=>'b-critica'];
$picons  = ['Baja'=>'🟢','Media'=>'🟡','Alta'=>'🟠','Critica'=>'🔴'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tareas</title>
<link rel="shortcut icon" type="image/x-icon" href="<?= BASE_URL ?>/favicon.ico">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<style>
/* ── KPIs ── */
.kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:22px; }
.kpi-box { background:var(--card); border:1px solid var(--border); border-radius:12px;
           padding:20px 14px; text-align:center; border-top:3px solid transparent;
           transition:transform .15s, box-shadow .15s; }
.kpi-box:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(0,0,0,.35); }
.kpi-icon { font-size:1.6rem; margin-bottom:6px; }
.kpi-n    { font-size:2rem; font-weight:800; line-height:1; }
.kpi-l    { font-size:.68rem; color:var(--muted); text-transform:uppercase; letter-spacing:.9px; margin-top:5px; }

/* ── Alertas ── */
.alert-banner { border-radius:9px; padding:12px 16px; margin-bottom:10px;
                display:flex; align-items:flex-start; gap:10px; font-size:.84rem; }
.alert-danger { background:rgba(232,64,46,.1);  border-left:3px solid var(--red);    color:#f08070; }
.alert-warn   { background:rgba(245,166,35,.1); border-left:3px solid var(--yellow); color:#f5c040; }
.alert-banner ul { margin:5px 0 0 16px; }
.alert-banner li { margin-bottom:3px; }

/* ── Layout principal ── */
.tareas-layout { display:grid; grid-template-columns:320px 1fr; gap:20px; align-items:start; }

/* ── Formulario ── */
.form-tarea { background:var(--card); border:1px solid var(--accent);
              border-radius:12px; padding:22px; position:sticky; top:76px; }
.form-tarea h3 { font-size:.95rem; font-weight:700; color:var(--accent);
                 margin-bottom:18px; display:flex; align-items:center; gap:8px; }

/* ── Grid de tarjetas 2 columnas ── */
.tareas-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; }

/* ── Tarjeta de tarea ── */
.tarea-card {
    background:var(--card); border:1px solid var(--border); border-radius:12px;
    padding:20px; display:flex; flex-direction:column; gap:0;
    transition:border-color .2s, box-shadow .2s, transform .15s;
}
.tarea-card:hover { border-color:var(--accent); box-shadow:0 6px 24px rgba(0,0,0,.35); transform:translateY(-2px); }
.tarea-card.completada { opacity:.7; }
.tarea-card.vencida    { border-left:3px solid var(--red); }

.tc-top { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; margin-bottom:10px; }
.tc-estado-icon { font-size:1.3rem; flex-shrink:0; }
.tc-title { font-size:.95rem; font-weight:700; color:var(--text); flex:1;
            line-height:1.35; margin-bottom:0; }
.tc-title a { color:var(--text); text-decoration:none; }
.tc-title a:hover { color:var(--accent2); }
.tc-actions-top { display:flex; gap:4px; flex-shrink:0; }

.tc-badges { display:flex; flex-wrap:wrap; gap:5px; margin-bottom:12px; }
.tc-badge { display:inline-block; padding:2px 9px; border-radius:12px; font-size:.68rem; font-weight:700; }

/* ── Barra de progreso ── */
.prog-wrap { margin-bottom:12px; }
.prog-header { display:flex; justify-content:space-between; align-items:center;
               font-size:.74rem; color:var(--muted); margin-bottom:5px; }
.prog-pct  { font-weight:800; font-size:.9rem; }
.prog-track { height:10px; background:var(--border); border-radius:5px; overflow:hidden; }
.prog-fill  { height:100%; border-radius:5px; transition:width .5s cubic-bezier(.4,0,.2,1); }

/* Stepper de flujo */
.estado-stepper { display:flex; align-items:center; gap:0; margin-bottom:12px; }
.step { display:flex; flex-direction:column; align-items:center; flex:1; }
.step-dot { width:22px; height:22px; border-radius:50%; border:2px solid var(--border);
            background:var(--surface); display:flex; align-items:center; justify-content:center;
            font-size:.65rem; font-weight:700; transition:all .2s; position:relative; z-index:1; }
.step-dot.done  { background:var(--accent); border-color:var(--accent); color:#fff; }
.step-dot.active{ background:var(--yellow); border-color:var(--yellow); color:#fff;
                  box-shadow:0 0 0 3px rgba(245,166,35,.25); }
.step-dot.comp  { background:var(--green); border-color:var(--green); color:#fff; }
.step-lbl { font-size:.58rem; color:var(--muted); margin-top:4px; text-align:center; white-space:nowrap; }
.step-line { height:2px; flex:1; background:var(--border); margin-top:-11px; position:relative; z-index:0; }
.step-line.done { background:var(--accent); }
.step-line.comp { background:var(--green); }

/* Fechas */
.tc-meta { display:flex; flex-wrap:wrap; gap:8px; font-size:.74rem; color:var(--muted);
           margin-bottom:12px; }
.dias-badge { padding:2px 8px; border-radius:10px; font-weight:700; font-size:.7rem; display:inline-block; }
.dias-ok   { background:rgba(39,169,108,.15);  color:#3dd68c; }
.dias-warn { background:rgba(245,166,35,.15);  color:#f5c040; }
.dias-late { background:rgba(232,64,46,.15);   color:#f07060; }
.dias-done { background:rgba(107,138,168,.1);  color:#7a9ab8; }

/* Botones estado inline */
.tc-footer { margin-top:auto; padding-top:12px; border-top:1px solid var(--border);
             display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
.btn-estado { padding:5px 12px; border-radius:7px; font-size:.75rem; font-weight:700;
              cursor:pointer; border:none; transition:all .15s; flex:1; text-align:center; }

/* ── Vista Detalle ── */
.det-layout { display:grid; grid-template-columns:1fr 300px; gap:20px; align-items:start; }
.prog-grande { height:16px; background:var(--border); border-radius:8px; overflow:hidden; margin:10px 0; }
.prog-grande-fill { height:100%; border-radius:8px; transition:width .6s cubic-bezier(.4,0,.2,1); }
.estado-stepper-big { display:flex; align-items:center; gap:0; margin:16px 0; }
.step-big { display:flex; flex-direction:column; align-items:center; flex:1; }
.step-big-dot { width:36px; height:36px; border-radius:50%; border:2px solid var(--border);
                background:var(--surface); display:flex; align-items:center; justify-content:center;
                font-size:1rem; transition:all .3s; position:relative; z-index:1; }
.step-big-dot.done   { background:var(--accent); border-color:var(--accent); }
.step-big-dot.active { background:var(--yellow); border-color:var(--yellow);
                       box-shadow:0 0 0 5px rgba(245,166,35,.2); animation:pulse 2s infinite; }
.step-big-dot.comp   { background:var(--green); border-color:var(--green); }
.step-big-lbl { font-size:.72rem; color:var(--muted); margin-top:6px; font-weight:600; }
.step-big-pct { font-size:.65rem; color:var(--muted); }
.step-big-line { height:3px; flex:1; background:var(--border); margin-top:-18px; z-index:0; border-radius:2px; }
.step-big-line.done { background:var(--accent); }
.step-big-line.comp { background:var(--green); }
@keyframes pulse { 0%,100%{ box-shadow:0 0 0 4px rgba(245,166,35,.2); } 50%{ box-shadow:0 0 0 8px rgba(245,166,35,.05); } }

/* Filtros */
.filtros-bar { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; align-items:flex-end; }
.filtros-bar select { padding:7px 10px; font-size:.82rem; flex:1; min-width:120px; max-width:170px; }

/* Botones rápidos de estado en el detalle */
.btn-flujo { padding:10px 18px; border-radius:8px; font-size:.85rem; font-weight:700;
             cursor:pointer; border:none; transition:all .2s; flex:1; }

/* Responsive */
@media(max-width:1400px) { .tareas-grid { grid-template-columns:repeat(3,1fr); } }
@media(max-width:1200px) { .tareas-layout { grid-template-columns:280px 1fr; } }
@media(max-width:1023px) {
    .tareas-layout  { grid-template-columns:1fr; }
    .form-tarea     { position:static; }
    .kpi-row        { grid-template-columns:repeat(2,1fr); }
    .tareas-grid    { grid-template-columns:repeat(2,1fr); }
    .det-layout     { grid-template-columns:1fr; }
}
@media(max-width:600px) {
    .kpi-row    { grid-template-columns:repeat(2,1fr); }
    .tareas-grid{ grid-template-columns:1fr; }
}
</style>
</head>
<body>
<?= renderNav(BASE_URL . '/modules/admin/tareas.php') ?>
<div class="page">

<?php /* ══════ VISTA DETALLE ══════════════════════════════════════════ */
if ($ver_id && $tarea_det): ?>

  <div style="color:var(--muted);font-size:.82rem;margin-bottom:10px;">
    <a href="<?= BASE_URL ?>/modules/admin/tareas.php">← Volver a Tareas</a>
  </div>

  <?php if ($msg): ?><div class="alert alert-ok" style="margin-bottom:14px;">✅ <?= $msg ?></div><?php endif; ?>

  <div class="det-layout">

    <!-- ── Columna principal ── -->
    <div>
      <div class="card" style="margin-bottom:16px;">
        <?php
        $ec  = estadoConfig($tarea_det['estado']);
        $pct = (int)$tarea_det['avance'];
        $pcol= colorAvance($pct);
        $dias= diasRestantes($tarea_det['fecha_fin']);
        ?>
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
          <div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
              <span style="font-size:1.6rem;"><?= $ec['icon'] ?></span>
              <h2 style="font-size:1.2rem;font-weight:800;"><?= htmlspecialchars($tarea_det['nombre']) ?></h2>
            </div>
            <div style="display:flex;gap:7px;flex-wrap:wrap;">
              <span class="tc-badge" style="background:<?= $ec['bg'] ?>;color:<?= $ec['txt'] ?>;border:1px solid <?= $ec['txt'] ?>33;font-size:.75rem;">
                <?= $tarea_det['estado'] ?>
              </span>
              <span class="badge <?= $pcolors[$tarea_det['prioridad']] ?>"><?= $tarea_det['prioridad'] ?></span>
              <?php if ($tarea_det['estado']==='Completada'): ?>
                <span class="dias-badge dias-done">✅ Completada</span>
              <?php elseif ($tarea_det['estado']==='Cancelada'): ?>
                <span class="dias-badge dias-done">⛔ Cancelada</span>
              <?php elseif ($dias < 0): ?>
                <span class="dias-badge dias-late">⚠ Vencida hace <?= abs($dias) ?>d</span>
              <?php elseif ($dias <= 3): ?>
                <span class="dias-badge dias-warn">⏰ Vence en <?= $dias ?>d</span>
              <?php else: ?>
                <span class="dias-badge dias-ok">📅 <?= $dias ?> días restantes</span>
              <?php endif; ?>
            </div>
          </div>
          <a href="tareas.php?editar=<?= $ver_id ?>" class="btn btn-ghost btn-sm">✏ Editar</a>
        </div>

        <?php if ($tarea_det['descripcion']): ?>
          <p style="color:var(--muted);font-size:.88rem;line-height:1.65;margin-bottom:16px;padding:12px 14px;background:var(--surface);border-radius:8px;border:1px solid var(--border);">
            <?= nl2br(htmlspecialchars($tarea_det['descripcion'])) ?>
          </p>
        <?php endif; ?>

        <!-- Stepper grande -->
        <?php
        $estActual = $tarea_det['estado'];
        $flujo = ['Pendiente'=>['🕐',0], 'En Progreso'=>['⚙',50], 'Completada'=>['✅',100]];
        $orden = array_keys($flujo);
        $idxAct= array_search($estActual, $orden);
        ?>
        <div class="estado-stepper-big">
          <?php foreach ($flujo as $est => [$ico, $pctEst]): ?>
            <?php
            $idx  = array_search($est, $orden);
            $cls  = $idxAct > $idx ? 'comp' : ($idxAct === $idx ? 'active' : '');
            $lcls = $idxAct > $idx ? 'comp' : ($idxAct > $idx-1 ? 'done' : '');
            ?>
            <?php if ($idx > 0): ?>
              <div class="step-big-line <?= $idxAct >= $idx ? ($est==='Completada'&&$idxAct===$idx?'comp':'done') : '' ?>"></div>
            <?php endif; ?>
            <div class="step-big">
              <div class="step-big-dot <?= $cls ?>" style="<?= $cls==='' ? '' : '' ?>"><?= $ico ?></div>
              <div class="step-big-lbl"><?= $est ?></div>
              <div class="step-big-pct"><?= $pctEst ?>%</div>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Barra grande -->
        <div>
          <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:6px;">
            <span style="color:var(--muted);">Progreso de la tarea</span>
            <span style="font-weight:800;color:<?= $pcol ?>;font-size:1.1rem;"><?= $pct ?>%</span>
          </div>
          <div class="prog-grande">
            <div class="prog-grande-fill" style="width:<?= $pct ?>%;background:<?= $pcol ?>;"></div>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:.74rem;color:var(--muted);">
            <span>📅 <?= date('d/m/Y',strtotime($tarea_det['fecha_inicio'])) ?></span>
            <span>🏁 <?= date('d/m/Y',strtotime($tarea_det['fecha_fin'])) ?></span>
          </div>
        </div>
      </div>

      <!-- Timeline -->
      <div class="card">
        <div style="font-size:.75rem;color:var(--accent);text-transform:uppercase;letter-spacing:.8px;font-weight:700;margin-bottom:16px;">📝 Historial de actividad</div>
        <div class="timeline">
          <?php if ($comentarios_res->num_rows === 0): ?>
            <p style="color:var(--muted);font-size:.85rem;">Sin actividad registrada aún.</p>
          <?php else:
            while ($c = $comentarios_res->fetch_assoc()):
              $dot = $c['estado_nuevo'] ? '🔄' : '💬';
          ?>
          <div class="tl-item">
            <div class="tl-dot"><?= $dot ?></div>
            <div class="tl-body">
              <div class="tl-header">
                <strong><?= htmlspecialchars($c['autor']) ?></strong>
                · <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?>
                <?php if ($c['estado_nuevo']): ?>
                  <?php $ce = estadoConfig($c['estado_nuevo']); ?>
                  <span style="margin-left:8px;padding:1px 7px;border-radius:8px;font-size:.68rem;font-weight:700;background:<?= $ce['bg'] ?>;color:<?= $ce['txt'] ?>;">
                    → <?= $c['estado_nuevo'] ?> (<?= $c['avance'] ?? '—' ?>%)
                  </span>
                <?php endif; ?>
              </div>
              <div class="tl-text"><?= nl2br(htmlspecialchars($c['mensaje'])) ?></div>
            </div>
          </div>
          <?php endwhile; endif; ?>
        </div>

        <!-- Agregar comentario -->
        <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);">
          <div style="font-size:.8rem;color:var(--muted);margin-bottom:10px;">💬 Agregar comentario</div>
          <form method="POST">
            <input type="hidden" name="action"   value="comentar">
            <input type="hidden" name="tarea_id" value="<?= $ver_id ?>">
            <div class="form-group">
              <textarea name="mensaje" rows="3" placeholder="Describe un avance, un obstáculo o una nota importante…" required></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">💬 Registrar</button>
          </form>
        </div>
      </div>
    </div>

    <!-- ── Columna lateral ── -->
    <div>
      <!-- Cambiar estado -->
      <?php if (!in_array($estActual,['Completada','Cancelada'])): ?>
      <div class="card" style="margin-bottom:14px;">
        <div style="font-size:.75rem;color:var(--accent);text-transform:uppercase;letter-spacing:.8px;font-weight:700;margin-bottom:14px;">🔄 Actualizar estado</div>
        <?php
        $flujo_btns = [];
        if ($estActual==='Pendiente')    $flujo_btns = ['En Progreso'=>['#f5c040','⚙ Iniciar']];
        if ($estActual==='En Progreso')  $flujo_btns = ['Completada'=>['#27a96c','✅ Completar'], 'Cancelada'=>['#e8402e','⛔ Cancelar']];
        ?>
        <form method="POST">
          <input type="hidden" name="action"   value="cambiar_estado">
          <input type="hidden" name="tarea_id" value="<?= $ver_id ?>">
          <div class="form-group">
            <label>Comentario (opcional)</label>
            <textarea name="mensaje" rows="2" placeholder="Agrega una nota sobre este cambio…"></textarea>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px;">
            <?php foreach ($flujo_btns as $est=>[$color,$label]): ?>
              <button type="submit" name="nuevo_estado" value="<?= $est ?>"
                      class="btn-flujo" style="background:<?= $color ?>;color:#fff;">
                <?= $label ?>
              </button>
            <?php endforeach; ?>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <!-- Info -->
      <div class="card">
        <div style="font-size:.75rem;color:var(--accent);text-transform:uppercase;letter-spacing:.8px;font-weight:700;margin-bottom:14px;">ℹ Información</div>
        <div style="display:flex;flex-direction:column;gap:11px;font-size:.84rem;">
          <div>
            <div style="color:var(--muted);font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Agente asignado</div>
            <strong><?= $tarea_det['agente_nombre'] ? '👤 '.htmlspecialchars($tarea_det['agente_nombre']) : '<span style="color:var(--muted)">Sin asignar</span>' ?></strong>
          </div>
          <div>
            <div style="color:var(--muted);font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Creado por</div>
            <span><?= htmlspecialchars($tarea_det['creado_por']??'—') ?></span>
          </div>
          <div>
            <div style="color:var(--muted);font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Fechas</div>
            <span><?= date('d/m/Y',strtotime($tarea_det['fecha_inicio'])) ?> → <?= date('d/m/Y',strtotime($tarea_det['fecha_fin'])) ?></span>
          </div>
          <div>
            <div style="color:var(--muted);font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Última actualización</div>
            <span><?= date('d/m/Y H:i', strtotime($tarea_det['actualizado_en'])) ?></span>
          </div>
        </div>
      </div>
    </div>
  </div>

<?php /* ══════ PANEL PRINCIPAL ═════════════════════════════════════════ */
else: ?>

  <div class="page-title">✅ Gestión de Tareas</div>

  <?php if ($msg): ?><div class="alert alert-ok">✅ <?= $msg ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-err">⚠ <?= $err ?></div><?php endif; ?>

  <!-- ── KPIs 4 ── -->
  <div class="kpi-row">
    <?php
    $kpi_conf = [
        'Pendiente'  =>['var(--accent)', '🕐'],
        'En Progreso'=>['var(--yellow)', '⚙'],
        'Completada' =>['var(--green)',  '✅'],
        'Cancelada'  =>['var(--muted)',  '⛔'],
    ];
    foreach ($kpi_conf as $est=>[$color,$ico]): ?>
    <div class="kpi-box" style="border-top-color:<?= $color ?>;">
      <div class="kpi-icon"><?= $ico ?></div>
      <div class="kpi-n" style="color:<?= $color ?>;"><?= $stats[$est] ?></div>
      <div class="kpi-l"><?= $est ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Alertas ── -->
  <?php if ($vencidas->num_rows > 0): ?>
  <div class="alert-banner alert-danger">
    <div>🚨 <strong><?= $vencidas->num_rows ?> tarea(s) vencida(s):</strong>
      <ul>
        <?php while ($t = $vencidas->fetch_assoc()): ?>
          <li><a href="tareas.php?ver=<?= $t['id'] ?>" style="color:#f08070;"><?= htmlspecialchars($t['nombre']) ?></a>
          — venció el <?= date('d/m/Y',strtotime($t['fecha_fin'])) ?>
          <?= $t['agente_nombre'] ? '· '.htmlspecialchars($t['agente_nombre']) : '' ?></li>
        <?php endwhile; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($porVencer->num_rows > 0): ?>
  <div class="alert-banner alert-warn">
    <div>⚠ <strong><?= $porVencer->num_rows ?> tarea(s) vencen en 3 días:</strong>
      <ul>
        <?php while ($t = $porVencer->fetch_assoc()):
          $d = diasRestantes($t['fecha_fin']); ?>
          <li><a href="tareas.php?ver=<?= $t['id'] ?>" style="color:#f5c040;"><?= htmlspecialchars($t['nombre']) ?></a>
          — <?= $d===0?'vence hoy':"vence en $d día(s)" ?></li>
        <?php endwhile; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>

  <div class="tareas-layout">

    <!-- ── Formulario lateral ── -->
    <div>
      <div class="form-tarea">
        <h3><?= $tarea_edit ? '✏ Editar Tarea' : '➕ Nueva Tarea' ?></h3>
        <form method="POST">
          <input type="hidden" name="action" value="<?= $tarea_edit ? 'editar' : 'crear' ?>">
          <?php if ($tarea_edit): ?>
            <input type="hidden" name="id" value="<?= $tarea_edit['id'] ?>">
          <?php endif; ?>

          <div class="form-group">
            <label>Nombre *</label>
            <input type="text" name="nombre" required placeholder="Ej: Migración Zeus a nuevo servidor"
                   value="<?= htmlspecialchars($tarea_edit['nombre'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Descripción</label>
            <textarea name="descripcion" rows="3" placeholder="Objetivos, entregables, criterios de éxito…"><?= htmlspecialchars($tarea_edit['descripcion'] ?? '') ?></textarea>
          </div>
          <div class="form-group">
            <label>Prioridad</label>
            <select name="prioridad">
              <?php foreach(['Baja'=>'🟢','Media'=>'🟡','Alta'=>'🟠','Critica'=>'🔴'] as $p=>$ico): ?>
                <option value="<?= $p ?>" <?= ($tarea_edit['prioridad']??'Media')===$p?'selected':''?>>
                  <?= $ico ?> <?= $p ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Agente asignado</label>
            <select name="agente_id">
              <option value="0">— Sin asignar —</option>
              <?php foreach ($agentes_list as $ag): ?>
                <option value="<?= $ag['id'] ?>" <?= ($tarea_edit['agente_id']??0)==$ag['id']?'selected':''?>>
                  <?= htmlspecialchars($ag['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div class="form-group">
              <label>Fecha inicio *</label>
              <input type="date" name="fecha_inicio" required
                     value="<?= $tarea_edit['fecha_inicio'] ?? date('Y-m-d') ?>">
            </div>
            <div class="form-group">
              <label>Fecha fin *</label>
              <input type="date" name="fecha_fin" required
                     value="<?= $tarea_edit['fecha_fin'] ?? '' ?>">
            </div>
          </div>

          <?php if (!$tarea_edit): ?>
            <div style="background:rgba(39,169,108,.1);border:1px solid rgba(39,169,108,.3);border-radius:8px;
                        padding:10px 14px;font-size:.8rem;color:#3dd68c;margin-bottom:14px;">
              🤖 La tarea inicia en estado <strong>Pendiente (0%)</strong>.<br>
              El avance se actualiza automáticamente al cambiar el estado.
            </div>
          <?php endif; ?>

          <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">
              <?= $tarea_edit ? '💾 Guardar' : '✅ Crear Tarea' ?>
            </button>
            <?php if ($tarea_edit): ?>
              <a href="<?= BASE_URL ?>/modules/admin/tareas.php" class="btn btn-ghost">Cancelar</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- ── Grid de tarjetas 2×N ── -->
    <div>
      <!-- Filtros -->
      <form method="GET" style="margin-bottom:14px;">
        <div class="filtros-bar">
          <select name="f_estado">
            <option value="">Todos los estados</option>
            <?php foreach(['Pendiente','En Progreso','Completada','Cancelada'] as $s): ?>
              <option <?= $f_estado===$s?'selected':''?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
          <select name="f_prioridad">
            <option value="">Toda prioridad</option>
            <?php foreach(['Baja','Media','Alta','Critica'] as $p): ?>
              <option <?= $f_prioridad===$p?'selected':''?>><?= $p ?></option>
            <?php endforeach; ?>
          </select>
          <select name="f_agente">
            <option value="">Todos los agentes</option>
            <?php foreach ($agentes_list as $ag): ?>
              <option value="<?= $ag['id'] ?>" <?= $f_agente==$ag['id']?'selected':''?>>
                <?= htmlspecialchars($ag['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
          <a href="<?= BASE_URL ?>/modules/admin/tareas.php" class="btn btn-ghost btn-sm">Limpiar</a>
        </div>
      </form>

      <?php if ($tareas->num_rows === 0): ?>
        <div class="card" style="text-align:center;padding:48px 20px;grid-column:1/-1;">
          <div style="font-size:2.5rem;margin-bottom:12px;">📋</div>
          <p style="font-weight:600;margin-bottom:6px;">No hay tareas<?= ($f_estado||$f_agente||$f_prioridad)?' con estos filtros':'' ?></p>
          <p style="color:var(--muted);font-size:.85rem;">Crea tu primera tarea en el formulario.</p>
        </div>
      <?php else: ?>
      <div class="tareas-grid">
        <?php while ($t = $tareas->fetch_assoc()):
          $ec    = estadoConfig($t['estado']);
          $pct   = (int)$t['avance'];
          $pcol  = colorAvance($pct);
          $dias  = diasRestantes($t['fecha_fin']);
          $venc  = ($dias < 0 && !in_array($t['estado'],['Completada','Cancelada']));
          $cls   = $t['estado']==='Completada' ? ' completada' : '';
          $cls  .= $venc ? ' vencida' : '';

          // Stepper mini
          $orden_est = ['Pendiente','En Progreso','Completada'];
          $idx_act   = array_search($t['estado'], $orden_est);
          if ($idx_act === false) $idx_act = -1;
        ?>
        <div class="tarea-card<?= $cls ?>">

          <!-- Top: icono + título + acciones -->
          <div class="tc-top">
            <div style="display:flex;gap:10px;align-items:flex-start;flex:1;min-width:0;">
              <span class="tc-estado-icon"><?= $ec['icon'] ?></span>
              <div class="tc-title">
                <a href="tareas.php?ver=<?= $t['id'] ?>"><?= htmlspecialchars($t['nombre']) ?></a>
              </div>
            </div>
            <div class="tc-actions-top">
              <a href="tareas.php?editar=<?= $t['id'] ?>" class="btn btn-ghost btn-sm" style="padding:4px 8px;" title="Editar">✏</a>
              <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar?')">
                <input type="hidden" name="action" value="eliminar">
                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                <button class="btn btn-danger btn-sm" style="padding:4px 8px;" title="Eliminar">🗑</button>
              </form>
            </div>
          </div>

          <!-- Badges -->
          <div class="tc-badges">
            <span class="tc-badge" style="background:<?= $ec['bg'] ?>;color:<?= $ec['txt'] ?>;border:1px solid <?= $ec['txt'] ?>33;">
              <?= $t['estado'] ?>
            </span>
            <span class="badge <?= $pcolors[$t['prioridad']] ?>"><?= $picons[$t['prioridad']] ?> <?= $t['prioridad'] ?></span>
            <?php if ($t['agente_nombre']): ?>
              <span class="tc-badge" style="background:var(--surface);color:var(--muted);border:1px solid var(--border);">
                👤 <?= htmlspecialchars($t['agente_nombre']) ?>
              </span>
            <?php endif; ?>
          </div>

          <!-- Stepper mini -->
          <?php if ($t['estado'] !== 'Cancelada'): ?>
          <div class="estado-stepper" style="margin-bottom:10px;">
            <?php foreach ($orden_est as $idx=>$est):
              $dot_cls = $idx_act > $idx ? 'comp' : ($idx_act===$idx ? ($est==='Completada'?'comp':'active') : '');
              $line_cls= $idx_act >= $idx ? ($est==='Completada'?'comp':'done') : '';
            ?>
              <?php if ($idx > 0): ?>
                <div class="step-line <?= $idx_act >= $idx ? ($t['estado']==='Completada'?'comp':'done') : '' ?>"></div>
              <?php endif; ?>
              <div class="step">
                <div class="step-dot <?= $dot_cls ?>"><?= ['🕐','⚙','✅'][$idx] ?></div>
                <div class="step-lbl"><?= ['Pend.','Prog.','Hecho'][$idx] ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <!-- Barra de progreso -->
          <div class="prog-wrap">
            <div class="prog-header">
              <span>Avance</span>
              <span class="prog-pct" style="color:<?= $pcol ?>;"><?= $pct ?>%</span>
            </div>
            <div class="prog-track">
              <div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $pcol ?>;"></div>
            </div>
          </div>

          <!-- Fechas y días -->
          <div class="tc-meta">
            <span>📅 <?= date('d/m/Y',strtotime($t['fecha_inicio'])) ?> → <?= date('d/m/Y',strtotime($t['fecha_fin'])) ?></span>
            <?php if (in_array($t['estado'],['Completada','Cancelada'])): ?>
              <span class="dias-badge dias-done"><?= $t['estado'] ?></span>
            <?php elseif ($dias<0): ?>
              <span class="dias-badge dias-late">⚠ Vencida <?= abs($dias) ?>d</span>
            <?php elseif ($dias<=3): ?>
              <span class="dias-badge dias-warn">⏰ <?= $dias ?>d</span>
            <?php else: ?>
              <span class="dias-badge dias-ok">✓ <?= $dias ?>d</span>
            <?php endif; ?>
          </div>

          <!-- Footer con botones de cambio rápido de estado -->
          <div class="tc-footer">
            <a href="tareas.php?ver=<?= $t['id'] ?>" class="btn btn-ghost btn-sm" style="flex:1;text-align:center;">👁 Ver</a>
            <?php if ($t['estado']==='Pendiente'): ?>
              <form method="POST" style="flex:1;margin:0;">
                <input type="hidden" name="action"      value="cambiar_estado">
                <input type="hidden" name="tarea_id"    value="<?= $t['id'] ?>">
                <input type="hidden" name="nuevo_estado"value="En Progreso">
                <input type="hidden" name="mensaje"     value="Estado actualizado a En Progreso desde el panel.">
                <button class="btn-estado" style="background:rgba(245,166,35,.2);color:#f5c040;border:1px solid rgba(245,166,35,.3);">⚙ Iniciar</button>
              </form>
            <?php elseif ($t['estado']==='En Progreso'): ?>
              <form method="POST" style="flex:1;margin:0;">
                <input type="hidden" name="action"      value="cambiar_estado">
                <input type="hidden" name="tarea_id"    value="<?= $t['id'] ?>">
                <input type="hidden" name="nuevo_estado"value="Completada">
                <input type="hidden" name="mensaje"     value="Tarea completada desde el panel.">
                <button class="btn-estado" style="background:rgba(39,169,108,.2);color:#3dd68c;border:1px solid rgba(39,169,108,.3);">✅ Completar</button>
              </form>
            <?php endif; ?>
          </div>

        </div>
        <?php endwhile; ?>
      </div><!-- cierre tareas-grid -->
      <?php endif; ?>

      <!-- Paginación tareas -->
      <?php if ($pag_tareas['total_paginas'] > 1): ?>
        <div style="margin-top:16px;">
          <?= renderPaginacion($pag_tareas) ?>
        </div>
      <?php endif; ?>

    </div><!-- cierre col derecha -->
  </div><!-- cierre tareas-layout -->

<?php endif; ?>
</div>
</body>
</html>
