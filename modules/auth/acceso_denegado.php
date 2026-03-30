<?php
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/helpers.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Acceso denegado</title>
<link rel="shortcut icon" type="image/x-icon" href="<?= BASE_URL ?>/favicon.ico">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<?= renderNav('') ?>
<div class="page" style="max-width:500px;text-align:center;padding-top:80px;">
  <div style="font-size:3.5rem;margin-bottom:16px;">🚫</div>
  <h2 style="font-size:1.4rem;margin-bottom:10px;">Acceso denegado</h2>
  <p style="color:var(--muted);margin-bottom:24px;">No tienes permiso para ver esta página con tu perfil actual.</p>
  <a href="javascript:history.back()" class="btn btn-ghost">← Volver</a>
</div>
</body>
</html>
