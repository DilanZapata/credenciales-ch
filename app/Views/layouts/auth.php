<?php
$appName = \App\Core\Config::get('app.name');
$nonce   = $cspNonce ?? '';
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($pageTitle ?? 'Acceso') . ' · ' . \App\Core\Config::get('app.short_name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<div class="auth-wrap">
  <aside class="auth-aside">
    <h1><?= e($appName) ?></h1>
    <p style="color:#9fb4e0;max-width:42ch">
      Inventario centralizado, cifrado y auditable de las credenciales de la empresa.
    </p>
    <ul>
      <li>Contrasenas cifradas con AES-256-GCM y claves envueltas.</li>
      <li>Cada consulta de una contrasena queda registrada: quien, cuando y desde donde.</li>
      <li>Acceso limitado a lo estrictamente autorizado para cada empleado.</li>
      <li>Verificacion en dos pasos para los perfiles administrativos.</li>
    </ul>
  </aside>
  <main class="auth-main">
    <div class="auth-card">
      <div id="toasts"></div>
      <?= \App\Core\View::render('partials/flash') ?>
      <?= $content ?>
      <p class="text-small text-muted" style="text-align:center;margin-top:1rem">
        Uso exclusivo del personal autorizado. Toda actividad es registrada.
      </p>
    </div>
  </main>
</div>
<script nonce="<?= e($nonce) ?>">window.SCGCA = { basePath: <?= json_encode(\App\Core\Config::get('app.base_path',''), JSON_UNESCAPED_SLASHES) ?>, csrf: <?= json_encode($csrf ?? '') ?>, authenticated: false };</script>
<script nonce="<?= e($nonce) ?>" src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
