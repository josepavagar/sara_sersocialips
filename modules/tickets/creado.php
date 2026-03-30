<?php
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
requireLogin();

$numero = htmlspecialchars($_GET['numero'] ?? '');
$titulo = htmlspecialchars($_GET['titulo']  ?? '');
$agente = htmlspecialchars($_GET['agente']  ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Soporte APP</title>
<link rel="shortcut icon" type="image/x-icon" href="<?= BASE_URL ?>/favicon.ico">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<style>
.success-card {
    max-width: 520px;
    margin: 80px auto;
    text-align: center;
    padding: 48px 40px;
}
.check-circle {
    width: 72px; height: 72px;
    border-radius: 50%;
    background: rgba(45,190,108,.15);
    border: 2px solid var(--green);
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem;
    margin: 0 auto 24px;
    animation: pop .4s ease;
}
@keyframes pop { from { transform: scale(.6); opacity:0; } to { transform: scale(1); opacity:1; } }
.ticket-num {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--accent);
    letter-spacing: 2px;
    margin: 12px 0;
    font-family: 'Courier New', monospace;
}
.info-box {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 16px 20px;
    margin: 20px 0;
    font-size: .88rem;
    color: var(--muted);
    text-align: left;
}
.info-box strong { color: var(--text); }
</style>
</head>
<body>
<div class="page">
  <div class="card success-card">
    <div class="check-circle">✅</div>
    <h2 style="font-size:1.3rem;margin-bottom:6px;">¡Ticket creado exitosamente!</h2>
    <p style="color:var(--muted);font-size:.9rem;">Tu solicitud ha sido registrada. Un agente de soporte la atenderá pronto.</p>

    <div class="ticket-num"><?= $numero ?></div>

    <div class="info-box">
      <?php if ($titulo): ?>
        <div style="margin-bottom:8px;"><strong>Asunto:</strong> <?= $titulo ?></div>
      <?php endif; ?>
      <?php if ($agente): ?>
        <div><strong>Asignado a:</strong> <?= $agente ?></div>
      <?php else: ?>
        <div><strong>Estado:</strong> Pendiente de asignación</div>
      <?php endif; ?>
    </div>

    <p style="color:var(--muted);font-size:.82rem;margin-bottom:24px;">
      Guarda tu número de ticket para hacer seguimiento.<br>
      Si tienes dudas, contacta a soporte con este código.
    </p>

    <a href="<?= BASE_URL ?>/modules/tickets/nuevo.php" class="btn btn-primary" style="width:100%;justify-content:center;">
      ➕ Crear otro ticket
    </a>
  </div>
</div>
</body>
</html>
