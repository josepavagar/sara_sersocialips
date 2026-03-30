<?php
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
requirePerfil('coordinador', 'agente');

$db  = getDB();
$msg = '';
$err = '';
if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);

/* ═══════════════════════════════════════════════════════════
EXPORTAR CSV - AL INICIO (ANTES DE HTML)
════════════════════════════════════════════════════════ */
if (isset($_GET['exportar']) && isset($_GET['lote'])) {
    $lote_sel = $_GET['lote'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="indicadores_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['numero','titulo','descripcion','categoria','aplicacion','prioridad',
        'estado','solicitante','email','agente','fecha_creacion','fecha_cierre','tiempo_resolucion_h'], ';');
    $all = $db->query("SELECT * FROM indicadores_cargue WHERE lote_id='$lote_sel' ORDER BY id ASC");
    while ($r = $all->fetch_assoc())
        fputcsv($out, [$r['numero'],$r['titulo'],$r['descripcion'],$r['categoria'],
            $r['aplicacion'],$r['prioridad'],$r['estado'],$r['solicitante'],
            $r['email'],$r['agente'],$r['fecha_creacion'],$r['fecha_cierre'],
            $r['tiempo_resolucion_h']], ';');
    fclose($out);
    exit;
}

/* ── Descargar plantilla ── */
if (isset($_GET['descargar_plantilla'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="plantilla_indicadores.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['numero','titulo','descripcion','categoria','aplicacion','prioridad',
        'estado','solicitante','email','agente','fecha_creacion','fecha_cierre','tiempo_resolucion_h'], ';');
    fputcsv($out, ['TKT-001','Error en login Siesa','No puede acceder al sistema','Software',
        'Siesa Salud','Alta','Resuelto','María González','maria@empresa.com',
        'Jose David Pava','01/03/2025','03/03/2025','48'], ';');
    fputcsv($out, ['TKT-002','Impresora no imprime','La impresora de urgencias no responde','Hardware',
        '','Media','Abierto','Carlos Ruiz','carlos@empresa.com',
        'Orlando Guette','05/03/2025','',''], ';');
    fclose($out);
    exit;
}

/* ═══════════════════════════════════════════════════════════
AUTO-MIGRACIÓN: tabla indicadores_cargue
════════════════════════════════════════════════════════ */
$db->query("CREATE TABLE IF NOT EXISTS `indicadores_cargue` (
`id` INT AUTO_INCREMENT PRIMARY KEY,
`lote_id` VARCHAR(36) NOT NULL,
`lote_nombre` VARCHAR(120) NOT NULL,
`numero` VARCHAR(20) DEFAULT NULL,
`titulo` VARCHAR(255) NOT NULL,
`descripcion` TEXT DEFAULT NULL,
`categoria` VARCHAR(60) DEFAULT NULL,
`aplicacion` VARCHAR(120) DEFAULT NULL,
`prioridad` VARCHAR(20) DEFAULT NULL,
`estado` VARCHAR(30) DEFAULT NULL,
`solicitante` VARCHAR(120) DEFAULT NULL,
`email` VARCHAR(180) DEFAULT NULL,
`agente` VARCHAR(120) DEFAULT NULL,
`fecha_creacion` DATE DEFAULT NULL,
`fecha_cierre` DATE DEFAULT NULL,
`tiempo_resolucion_h` DECIMAL(10,2) DEFAULT NULL,
`cargado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
`cargado_por` VARCHAR(120) DEFAULT NULL,
INDEX idx_lote (lote_id),
INDEX idx_estado (estado),
INDEX idx_agente (agente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ═══════════════════════════════════════════════════════════
ACCIÓN: subir CSV
════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $lote_nombre = trim($_POST['lote_nombre'] ?? 'Cargue ' . date('d/m/Y H:i'));
    $lote_id     = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
    $cargado_por = htmlspecialchars(currentUser()['nombre'] ?? 'Sistema');
    $file        = $_FILES['csv_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $err = 'Error al subir el archivo. Código: ' . $file['error'];
    } elseif (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['csv','txt'])) {
        $err = 'Solo se permiten archivos .csv';
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);
        $firstLine = fgets($handle);
        rewind($handle);
        if ($bom === "\xEF\xBB\xBF") fread($handle, 3);
        $delim = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
        $headers = fgetcsv($handle, 0, $delim);
        if (!$headers) { $err = 'No se pudo leer el encabezado del CSV.'; }
        else {
            $headers = array_map(fn($h) => strtolower(trim(str_replace([' ','á','é','í','ó','ú','ñ'],
                ['_','a','e','i','o','u','n'], $h))), $headers);
            $insertados = 0;
            $errores    = 0;
            $stmt = $db->prepare("INSERT INTO indicadores_cargue
                (lote_id, lote_nombre, numero, titulo, descripcion, categoria, aplicacion,
                prioridad, estado, solicitante, email, agente,
                fecha_creacion, fecha_cierre, tiempo_resolucion_h, cargado_por)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            while (($row = fgetcsv($handle, 0, $delim)) !== false) {
                if (count(array_filter($row)) === 0) continue;
                $r = [];
                foreach ($headers as $i => $h) $r[$h] = trim($row[$i] ?? '');
                $numero      = $r['numero']      ?? $r['n_ticket']    ?? '';
                $titulo      = $r['titulo']       ?? $r['asunto']      ?? $r['subject'] ?? '(sin título)';
                $descripcion = $r['descripcion']  ?? $r['description'] ?? '';
                $categoria   = $r['categoria']    ?? $r['category']    ?? '';
                $aplicacion  = $r['aplicacion']   ?? $r['app']         ?? '';
                $prioridad   = $r['prioridad']    ?? $r['priority']    ?? '';
                $estado      = $r['estado']       ?? $r['status']      ?? '';
                $solicitante = $r['solicitante']  ?? $r['usuario']     ?? $r['user']    ?? '';
                $email       = $r['email']        ?? $r['correo']      ?? '';
                $agente      = $r['agente']       ?? $r['agent']       ?? $r['responsable'] ?? '';
                $fecha_cre = parseFecha($r['fecha_creacion'] ?? $r['created_at'] ?? $r['fecha'] ?? '');
                $fecha_cie = parseFecha($r['fecha_cierre']   ?? $r['fecha_resolucion'] ?? $r['closed_at'] ?? '');
                $t_res = null;
                if (!empty($r['tiempo_resolucion_h'])) {
                    $t_res = floatval(str_replace(',', '.', $r['tiempo_resolucion_h']));
                } elseif ($fecha_cre && $fecha_cie) {
                    $t_res = round((strtotime($fecha_cie) - strtotime($fecha_cre)) / 3600, 2);
                    if ($t_res < 0) $t_res = null;
                }
                $stmt->bind_param('ssssssssssssssds',
                    $lote_id, $lote_nombre, $numero, $titulo, $descripcion,
                    $categoria, $aplicacion, $prioridad, $estado,
                    $solicitante, $email, $agente,
                    $fecha_cre, $fecha_cie, $t_res, $cargado_por);
                $stmt->execute() ? $insertados++ : $errores++;
            }
            fclose($handle);
            $msg = "✅ Lote «{$lote_nombre}» cargado: <strong>{$insertados}</strong> registros insertados" .
                ($errores ? " · ⚠ {$errores} con error" : '') . '.';
        }
    }
}

/* ACCIÓN: eliminar lote */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_lote'])) {
    $lid = $db->real_escape_string($_POST['eliminar_lote']);
    $db->query("DELETE FROM indicadores_cargue WHERE lote_id='$lid'");
    header('Location: ' . BASE_URL . '/modules/admin/indicadores.php?msg=' . urlencode('Lote eliminado correctamente.'));
    exit;
}

function parseFecha(string $v): ?string {
    if (!$v) return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10);
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $v, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})/', $v, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    return null;
}

/* ═══════════════════════════════════════════════════════════
DATOS: lotes disponibles
════════════════════════════════════════════════════════ */
$lotes = $db->query("SELECT lote_id, lote_nombre, COUNT(*) total,
    MIN(fecha_creacion) desde, MAX(fecha_creacion) hasta,
    MAX(cargado_en) cargado_en, MAX(cargado_por) cargado_por
    FROM indicadores_cargue GROUP BY lote_id, lote_nombre ORDER BY MAX(cargado_en) DESC");

$lote_sel = $_GET['lote'] ?? '';
$lotes_arr = [];
if ($lotes) { while($l=$lotes->fetch_assoc()) $lotes_arr[]=$l; }
if (!$lote_sel && !empty($lotes_arr)) $lote_sel = $lotes_arr[0]['lote_id'];

/* ─── Métricas del lote seleccionado ─── */
$total_reg = 0; $r_estado=[]; $r_prio=[]; $r_cat=[]; $r_app=[]; $r_agente=[];
$r_agente_estados=[]; $r_trend=[]; $avg_h=0;
if ($lote_sel) {
    $ls = $db->real_escape_string($lote_sel);
    $total_reg = (int)$db->query("SELECT COUNT(*) c FROM indicadores_cargue WHERE lote_id='$ls'")->fetch_assoc()['c'];
    $res=$db->query("SELECT estado,COUNT(*) c FROM indicadores_cargue WHERE lote_id='$ls' GROUP BY estado ORDER BY c DESC");
    while($r=$res->fetch_assoc()) $r_estado[$r['estado']]=$r['c'];
    $res=$db->query("SELECT prioridad,COUNT(*) c FROM indicadores_cargue WHERE lote_id='$ls' GROUP BY prioridad ORDER BY c DESC");
    while($r=$res->fetch_assoc()) $r_prio[$r['prioridad']]=$r['c'];
    $res=$db->query("SELECT categoria,COUNT(*) c FROM indicadores_cargue WHERE lote_id='$ls' AND categoria!='' GROUP BY categoria ORDER BY c DESC LIMIT 8");
    while($r=$res->fetch_assoc()) $r_cat[$r['categoria']]=$r['c'];
    $res=$db->query("SELECT aplicacion,COUNT(*) c FROM indicadores_cargue WHERE lote_id='$ls' AND aplicacion!='' GROUP BY aplicacion ORDER BY c DESC LIMIT 10");
    while($r=$res->fetch_assoc()) $r_app[$r['aplicacion']]=$r['c'];
    $res=$db->query("SELECT agente,COUNT(*) c FROM indicadores_cargue WHERE lote_id='$ls' AND agente!='' GROUP BY agente ORDER BY c DESC LIMIT 8");
    while($r=$res->fetch_assoc()) $r_agente[$r['agente']]=$r['c'];
    $res=$db->query("SELECT agente,
        COUNT(*) total,
        SUM(estado='Abierto') abiertos,
        SUM(estado='En Progreso') en_progreso,
        SUM(estado='Resuelto') resueltos,
        SUM(estado='Cerrado') cerrados
        FROM indicadores_cargue WHERE lote_id='$ls' AND agente!=''
        GROUP BY agente ORDER BY total DESC LIMIT 8");
    while($r=$res->fetch_assoc()) $r_agente_estados[]=$r;
    $res=$db->query("SELECT DATE_FORMAT(fecha_creacion,'%Y-%m-%d') d, COUNT(*) c
        FROM indicadores_cargue WHERE lote_id='$ls' AND fecha_creacion IS NOT NULL
        GROUP BY DATE_FORMAT(fecha_creacion,'%Y-%m-%d') ORDER BY d ASC");
    while($r=$res->fetch_assoc()) $r_trend[$r['d']]=$r['c'];
    $ra=$db->query("SELECT AVG(tiempo_resolucion_h) a FROM indicadores_cargue WHERE lote_id='$ls' AND tiempo_resolucion_h IS NOT NULL")->fetch_assoc();
    $avg_h = round($ra['a']??0,1);
}

/* Paginación listado */
$pag_por  = 20;
$pag_act  = max(1,intval($_GET['pag']??1));
$total_pag= $lote_sel ? (int)$db->query("SELECT COUNT(*) c FROM indicadores_cargue WHERE lote_id='$lote_sel'")->fetch_assoc()['c'] : 0;
$pag_data = paginar($total_pag, $pag_por, $pag_act, array_filter(['lote'=>$lote_sel]));
$registros= $lote_sel
    ? $db->query("SELECT * FROM indicadores_cargue WHERE lote_id='$lote_sel' ORDER BY fecha_creacion ASC LIMIT $pag_por OFFSET {$pag_data['offset']}")
    : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Indicadores</title>
<link rel="shortcut icon" type="image/x-icon" href="<?= BASE_URL ?>/favicon.ico">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
.upload-zone {
    border: 2px dashed var(--border);
    border-radius: 12px;
    padding: 32px 24px;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    background: var(--surface);
}
.upload-zone:hover, .upload-zone.drag { border-color: var(--accent); background: rgba(232,104,26,.05); }
.upload-zone input[type=file] { display: none; }
.upload-icon { font-size: 2.5rem; margin-bottom: 10px; }
.upload-label { font-size: .9rem; color: var(--muted); }
.upload-label strong { color: var(--accent); cursor: pointer; }
.lote-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px; border-radius: 8px;
    border: 1px solid var(--border);
    margin-bottom: 6px; cursor: pointer;
    transition: border-color .15s, background .15s;
    text-decoration: none; color: var(--text);
}
.lote-item:hover { border-color: var(--accent2); background: rgba(232,104,26,.05); text-decoration: none; }
.lote-item.active { border-color: var(--accent); background: rgba(232,104,26,.08); }
.lote-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--accent); flex-shrink: 0; }
.lote-info { flex: 1; min-width: 0; }
.lote-nombre { font-weight: 700; font-size: .88rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lote-meta { font-size: .72rem; color: var(--muted); margin-top: 2px; }
.lote-total { font-size: .8rem; font-weight: 700; color: var(--accent); white-space: nowrap; }
.kpi-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; margin-bottom: 20px; }
.kpi { background: var(--card); border: 1px solid var(--border); border-radius: 10px;
    padding: 18px 16px; text-align: center; border-top: 3px solid var(--accent); }
.kpi-num { font-size: 2rem; font-weight: 800; color: var(--accent); }
.kpi-label { font-size: .72rem; color: var(--muted); text-transform: uppercase; letter-spacing: .8px; margin-top: 4px; }
.ind-layout { display: grid; grid-template-columns: 280px 1fr; gap: 20px; align-items: start; }
.charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
.chart-card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; }
.chart-title { font-size: .75rem; color: var(--accent); text-transform: uppercase; letter-spacing: .8px; font-weight: 700; margin-bottom: 14px; }
.chart-full { grid-column: 1 / -1; }
.csv-template { background: var(--bg); border: 1px solid var(--border); border-radius: 8px;
    padding: 12px 14px; font-family: monospace; font-size: .75rem;
    color: var(--muted); overflow-x: auto; white-space: nowrap; margin-top: 10px; }
.del-lote { background: none; border: none; color: var(--muted); cursor: pointer; font-size: .85rem;
    padding: 2px 6px; border-radius: 4px; transition: color .15s; }
.del-lote:hover { color: var(--red); }
.ag-bar-wrap { display:flex;align-items:center;gap:8px; }
.ag-bar-bg { flex:1;background:var(--border);border-radius:4px;height:6px;min-width:40px; }
.ag-bar-fill { height:6px;border-radius:4px;background:var(--accent); }
@media(max-width:1023px) {
    .ind-layout { grid-template-columns: 1fr; }
    .kpi-grid { grid-template-columns: repeat(2,1fr); }
    .charts-grid { grid-template-columns: 1fr; }
    .chart-full { grid-column: 1; }
}
@media(max-width:479px) {
    .kpi-grid { grid-template-columns: repeat(2,1fr); }
    .kpi-num { font-size: 1.6rem; }
}
</style>
</head>
<body>
<?= renderNav(BASE_URL . '/modules/admin/indicadores.php') ?>
<div class="page">
    <div class="page-title">📈 Indicadores — Cargue Masivo</div>
    <?php if ($msg): ?>
        <div class="alert alert-ok"><?= $msg ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert alert-err">⚠ <?= htmlspecialchars($err) ?></div>
    <?php endif; ?>
    
    <div class="ind-layout">
        <div>
            <div class="card" style="margin-bottom:16px;padding:20px;">
                <div style="font-size:.8rem;color:var(--accent);text-transform:uppercase;letter-spacing:.8px;font-weight:700;margin-bottom:14px;">
                    📤 Cargar CSV
                </div>
                <form method="POST" enctype="multipart/form-data" id="uploadForm">
                    <div class="form-group">
                        <label>Nombre del lote</label>
                        <input type="text" name="lote_nombre" placeholder="Ej: Enero 2025 · Q1" required>
                    </div>
                    <div class="upload-zone" id="dropZone" onclick="document.getElementById('csvInput').click()">
                        <input type="file" name="csv_file" id="csvInput" accept=".csv,.txt" onchange="showFileName(this)">
                        <div class="upload-icon">📄</div>
                        <div class="upload-label">
                            <strong>Selecciona tu CSV</strong> o arrastra aquí<br>
                            <span id="fileNameLabel" style="color:var(--accent);font-size:.8rem;"></span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-full" style="margin-top:12px;">
                        ⬆ Cargar datos
                    </button>
                </form>
                <details style="margin-top:16px;">
                    <summary style="cursor:pointer;font-size:.8rem;color:var(--muted);font-weight:600;">
                        📋 Ver estructura del CSV
                    </summary>
                    <div style="margin-top:10px;font-size:.78rem;color:var(--muted);">
                        El archivo debe tener estas columnas (en cualquier orden):<br>
                        Los campos de fecha aceptan <code>dd/mm/yyyy</code> o <code>yyyy-mm-dd</code>.
                    </div>
                    <div class="csv-template">numero;titulo;descripcion;categoria;aplicacion;prioridad;estado;solicitante;email;agente;fecha_creacion;fecha_cierre;tiempo_resolucion_h</div>
                    <a href="indicadores.php?descargar_plantilla=1" class="btn btn-ghost btn-sm" style="margin-top:8px;width:100%;justify-content:center;">
                        ⬇ Descargar plantilla
                    </a>
                </details>
            </div>
            
            <div class="card" style="padding:16px;">
                <div style="font-size:.8rem;color:var(--accent);text-transform:uppercase;letter-spacing:.8px;font-weight:700;margin-bottom:12px;">
                    📂 Lotes cargados (<?= count($lotes_arr) ?>)
                </div>
                <?php if (empty($lotes_arr)): ?>
                    <p style="color:var(--muted);font-size:.83rem;text-align:center;padding:20px 0;">
                        Aún no hay lotes cargados.
                    </p>
                <?php else: ?>
                    <?php foreach ($lotes_arr as $l): ?>
                        <div class="lote-item <?= $lote_sel===$l['lote_id']?'active':'' ?>"
                            onclick="location.href='indicadores.php?lote=<?= urlencode($l['lote_id']) ?>'">
                            <div class="lote-dot"></div>
                            <div class="lote-info">
                                <div class="lote-nombre"><?= htmlspecialchars($l['lote_nombre']) ?></div>
                                <div class="lote-meta">
                                    <?= $l['desde'] ? date('d/m/Y',strtotime($l['desde'])).' – '.date('d/m/Y',strtotime($l['hasta'])) : 'Sin fechas' ?>
                                    · por <?= htmlspecialchars($l['cargado_por']) ?>
                                </div>
                            </div>
                            <div>
                                <div class="lote-total"><?= number_format($l['total']) ?></div>
                                <form method="POST" style="margin-top:4px;"
                                    onclick="event.stopPropagation()"
                                    onsubmit="event.stopPropagation(); return confirm('¿Eliminar el lote «<?= htmlspecialchars(addslashes($l['lote_nombre'])) ?>» y sus <?= number_format($l['total']) ?> registros? Esta acción no se puede deshacer.')">
                                    <input type="hidden" name="eliminar_lote" value="<?= $l['lote_id'] ?>">
                                    <button type="submit" class="del-lote" title="Eliminar lote">🗑</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div>
            <?php if (!$lote_sel || $total_reg === 0): ?>
                <div class="card" style="text-align:center;padding:60px 20px;">
                    <div style="font-size:3rem;margin-bottom:14px;">📊</div>
                    <p style="font-size:1rem;font-weight:600;margin-bottom:6px;">Sin datos para visualizar</p>
                    <p style="color:var(--muted);font-size:.85rem;">Carga un archivo CSV para ver los indicadores.</p>
                </div>
            <?php else: ?>
                <div class="kpi-grid">
                    <div class="kpi">
                        <div class="kpi-num"><?= number_format($total_reg) ?></div>
                        <div class="kpi-label">Total casos</div>
                    </div>
                    <div class="kpi" style="border-top-color:var(--green);">
                        <div class="kpi-num" style="color:var(--green);"><?= $avg_h ?>h</div>
                        <div class="kpi-label">Prom. resolución</div>
                    </div>
                    <div class="kpi" style="border-top-color:var(--accent);">
                        <div class="kpi-num"><?= $r_estado['Abierto'] ?? 0 ?></div>
                        <div class="kpi-label">Abiertos</div>
                    </div>
                    <div class="kpi" style="border-top-color:var(--green);">
                        <div class="kpi-num" style="color:var(--green);">
                            <?= number_format(($total_reg>0?(($r_estado['Resuelto']??0)+($r_estado['Cerrado']??0))/$total_reg*100:0),1) ?>%
                        </div>
                        <div class="kpi-label">Tasa resolución</div>
                    </div>
                </div>
                
                <div class="charts-grid">
                    <div class="chart-card">
                        <div class="chart-title">Por Estado</div>
                        <canvas id="chEstado" height="220"></canvas>
                    </div>
                    <div class="chart-card">
                        <div class="chart-title">Por Prioridad</div>
                        <canvas id="chPrioridad" height="220"></canvas>
                    </div>
                    <?php if (!empty($r_trend)): ?>
                        <div class="chart-card chart-full">
                            <div class="chart-title">Tendencia — Casos por fecha de creación</div>
                            <canvas id="chTrend" height="100"></canvas>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($r_app)): ?>
                        <div class="chart-card">
                            <div class="chart-title">Por Aplicación</div>
                            <canvas id="chApp" height="260"></canvas>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($r_cat)): ?>
                        <div class="chart-card">
                            <div class="chart-title">Por Categoría</div>
                            <canvas id="chCat" height="260"></canvas>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($r_agente_estados)): ?>
                        <div class="chart-card chart-full">
                            <div class="chart-title">Estados por Agente</div>
                            <canvas id="chAgenteEstados" height="120"></canvas>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($r_agente_estados)): ?>
                    <div class="card" style="padding:0;margin-bottom:20px;">
                        <div style="padding:14px 18px;border-bottom:1px solid var(--border);">
                            <span style="font-size:.75rem;color:var(--accent);text-transform:uppercase;letter-spacing:.8px;font-weight:700;">
                                Rendimiento por Agente
                            </span>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Agente</th>
                                        <th style="color:#58a6ff">Abiertos</th>
                                        <th style="color:#f5c040">En Progreso</th>
                                        <th style="color:#3dd68c">Resueltos</th>
                                        <th style="color:#7a9ab8">Cerrados</th>
                                        <th>Total</th>
                                        <th>% Resuelto</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($r_agente_estados as $ag):
                                        $pct   = $ag['total']>0 ? round(($ag['resueltos']+$ag['cerrados'])/$ag['total']*100) : 0;
                                        $pcol  = $pct>=70?'var(--green)':($pct>=40?'var(--yellow)':'var(--red)');
                                    ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($ag['agente']) ?></strong></td>
                                            <td><span class="badge b-abierto"><?= $ag['abiertos'] ?></span></td>
                                            <td><span class="badge b-progreso"><?= $ag['en_progreso'] ?></span></td>
                                            <td><span class="badge b-resuelto"><?= $ag['resueltos'] ?></span></td>
                                            <td><span class="badge b-cerrado"><?= $ag['cerrados'] ?></span></td>
                                            <td><strong><?= $ag['total'] ?></strong></td>
                                            <td>
                                                <div class="ag-bar-wrap">
                                                    <div class="ag-bar-bg">
                                                        <div class="ag-bar-fill" style="width:<?= $pct ?>%;background:<?= $pcol ?>;"></div>
                                                    </div>
                                                    <span style="color:<?= $pcol ?>;font-weight:700;font-size:.8rem;"><?= $pct ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="card" style="padding:0;">
                    <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
                        <span style="font-size:.75rem;color:var(--accent);text-transform:uppercase;letter-spacing:.8px;font-weight:700;">
                            Registros del lote (<?= number_format($total_reg) ?>)
                        </span>
                        <a href="indicadores.php?lote=<?= urlencode($lote_sel) ?>&exportar=1"
                            class="btn btn-ghost btn-sm">⬇ Exportar CSV</a>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>N°</th><th>Título</th><th>Aplicación</th>
                                    <th>Estado</th><th>Prioridad</th>
                                    <th>Agente</th><th>Fecha</th><th>T. Resolución</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($registros && $registros->num_rows > 0):
                                    while ($reg = $registros->fetch_assoc()): ?>
                                        <tr>
                                            <td><code style="color:var(--accent);font-size:.75rem;"><?= htmlspecialchars($reg['numero']?:'—') ?></code></td>
                                            <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                                <?= htmlspecialchars($reg['titulo']) ?>
                                            </td>
                                            <td style="color:var(--muted);font-size:.82rem;"><?= htmlspecialchars($reg['aplicacion']?:'—') ?></td>
                                            <td><?= $reg['estado'] ? badgeEstado($reg['estado']) : '<span style="color:var(--muted)">—</span>' ?></td>
                                            <td><?= $reg['prioridad'] ? badgePrioridad($reg['prioridad']) : '<span style="color:var(--muted)">—</span>' ?></td>
                                            <td style="font-size:.82rem;"><?= htmlspecialchars($reg['agente']?:'—') ?></td>
                                            <td style="color:var(--muted);font-size:.8rem;white-space:nowrap;">
                                                <?= $reg['fecha_creacion'] ? date('d/m/Y',strtotime($reg['fecha_creacion'])) : '—' ?>
                                            </td>
                                            <td style="font-size:.82rem;color:var(--muted);">
                                                <?= $reg['tiempo_resolucion_h'] ? number_format($reg['tiempo_resolucion_h'],1).'h' : '—' ?>
                                            </td>
                                        </tr>
                                    <?php endwhile;
                                else: ?>
                                    <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:30px;">Sin registros</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?= renderPaginacion($pag_data) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const zone = document.getElementById('dropZone');
if (zone) {
    ['dragenter','dragover'].forEach(e => zone.addEventListener(e, ev => { ev.preventDefault(); zone.classList.add('drag'); }));
    ['dragleave','drop'].forEach(e => zone.addEventListener(e, ev => { zone.classList.remove('drag'); }));
    zone.addEventListener('drop', ev => {
        ev.preventDefault();
        const f = ev.dataTransfer.files[0];
        if (f) {
            document.getElementById('csvInput').files = ev.dataTransfer.files;
            document.getElementById('fileNameLabel').textContent = f.name;
        }
    });
}
function showFileName(input) {
    const lbl = document.getElementById('fileNameLabel');
    if (lbl) lbl.textContent = input.files[0]?.name || '';
}

<?php if ($lote_sel && $total_reg > 0): ?>
const dk = { grid: '#1a3f5c', text: '#6b8aa8' };
Chart.defaults.color = dk.text;
Chart.defaults.font.family = "'Outfit','Segoe UI',sans-serif";
Chart.defaults.font.size = 11;

function donut(id, labels, data, colors) {
    const el = document.getElementById(id);
    if (!el || !data.length) return;
    new Chart(el, {
        type: 'doughnut',
        data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 0, hoverOffset: 6 }] },
        options: { plugins: { legend: { position:'bottom', labels:{ padding:12, boxWidth:11 } } }, cutout:'62%', responsive:true }
    });
}

function barH(id, labels, data, color) {
    const el = document.getElementById(id);
    if (!el) return;
    new Chart(el, {
        type: 'bar',
        data: { labels, datasets: [{ data, backgroundColor: color, borderRadius:5 }] },
        options: {
            indexAxis: 'y',
            plugins: { legend:{ display:false } },
            scales: {
                x: { grid:{ color:dk.grid }, ticks:{ stepSize:1, precision:0 } },
                y: { grid:{ color:dk.grid } }
            }
        }
    });
}

donut('chEstado',
    <?= json_encode(array_keys($r_estado)) ?>,
    <?= json_encode(array_values($r_estado)) ?>,
    ['#e8681a','#f5a623','#27a96c','#6b8aa8','#e8402e','#1a6fb5']);

donut('chPrioridad',
    <?= json_encode(array_keys($r_prio)) ?>,
    <?= json_encode(array_values($r_prio)) ?>,
    ['#27a96c','#f5a623','#e8681a','#e8402e']);

<?php if (!empty($r_trend)): ?>
new Chart(document.getElementById('chTrend'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_keys($r_trend)) ?>,
        datasets: [{
            data: <?= json_encode(array_values($r_trend)) ?>,
            borderColor: '#e8681a', backgroundColor: 'rgba(232,104,26,.1)',
            fill: true, tension: .4, pointRadius: 3, pointBackgroundColor: '#e8681a'
        }]
    },
    options: {
        plugins: { legend: { display:false } },
        scales: {
            x: { grid:{ color:dk.grid } },
            y: { grid:{ color:dk.grid }, ticks:{ stepSize:1, precision:0 }, beginAtZero:true }
        }
    }
});
<?php endif; ?>

<?php if (!empty($r_app)): ?>
barH('chApp', <?= json_encode(array_keys($r_app)) ?>, <?= json_encode(array_values($r_app)) ?>, '#1a6fb5');
<?php endif; ?>

<?php if (!empty($r_cat)): ?>
barH('chCat', <?= json_encode(array_keys($r_cat)) ?>, <?= json_encode(array_values($r_cat)) ?>, '#7c5cbf');
<?php endif; ?>

<?php if (!empty($r_agente_estados)): ?>
new Chart(document.getElementById('chAgenteEstados'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($r_agente_estados,'agente')) ?>,
        datasets: [
            { label:'Abierto',     data:<?= json_encode(array_map('intval',array_column($r_agente_estados,'abiertos'))) ?>,     backgroundColor:'#e8681a' },
            { label:'En Progreso', data:<?= json_encode(array_map('intval',array_column($r_agente_estados,'en_progreso'))) ?>,  backgroundColor:'#f5a623' },
            { label:'Resuelto',    data:<?= json_encode(array_map('intval',array_column($r_agente_estados,'resueltos'))) ?>,    backgroundColor:'#27a96c' },
            { label:'Cerrado',     data:<?= json_encode(array_map('intval',array_column($r_agente_estados,'cerrados'))) ?>,     backgroundColor:'#3a5068' },
        ]
    },
    options: {
        plugins: { legend:{ position:'bottom', labels:{ padding:12, boxWidth:11 } } },
        scales: {
            x: { stacked:true, grid:{ color:dk.grid } },
            y: { stacked:true, grid:{ color:dk.grid }, ticks:{ stepSize:1, precision:0 }, beginAtZero:true }
        }
    }
});
<?php endif; ?>
<?php endif; ?>
</script>
</body>
</html>