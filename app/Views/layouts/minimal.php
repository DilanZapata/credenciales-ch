<?php $nonce = $cspNonce ?? ''; ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($title ?? 'Aviso') . ' · ' . \App\Core\Config::get('app.short_name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<main class="auth-main" style="min-height:100vh">
  <div class="auth-card"><?= $content ?></div>
</main>
</body>
</html>
