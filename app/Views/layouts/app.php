<?php
/**
 * @var string $content
 * @var \App\Services\AuthContext $auth
 * @var string $csrf
 * @var string $currentPath
 */
$appName  = \App\Core\Config::get('app.name');
$shortName= \App\Core\Config::get('app.short_name');
$nonce    = $cspNonce ?? '';
$path     = $currentPath ?? '/';
$user     = $auth->user() ?? [];
$isAdmin  = $auth->can('credentials.view_all');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<title><?= e(($pageTitle ?? 'Panel') . ' · ' . $shortName) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><text y='13' font-size='13'>&#128274;</text></svg>">
</head>
<body>
<div class="app">

  <aside class="sidebar" id="sidebar">
    <div class="sidebar__brand">
      <strong><?= e($shortName) ?></strong>
      <span>Gestion de accesos</span>
    </div>

    <nav class="sidebar__nav">
      <div class="sidebar__group">
        <?php if ($isAdmin): ?>
          <a class="nav-item <?= active('/', $path) ?>" href="<?= e(url('/')) ?>"><span class="ico">▦</span> Panel</a>
        <?php endif; ?>
        <a class="nav-item <?= active('/mis-accesos', $path) ?>" href="<?= e(url('/mis-accesos')) ?>"><span class="ico">★</span> Mis accesos</a>
      </div>

      <?php if ($auth->canAny('credentials.view', 'systems.view')): ?>
      <div class="sidebar__group">
        <div class="sidebar__label">Inventario</div>
        <?php if ($auth->can('credentials.view')): ?>
          <a class="nav-item <?= active('/credenciales', $path) ?>" href="<?= e(url('/credenciales')) ?>"><span class="ico">🔑</span> Credenciales</a>
        <?php endif; ?>
        <?php if ($auth->can('systems.view')): ?>
          <a class="nav-item <?= active('/sistemas', $path) ?>" href="<?= e(url('/sistemas')) ?>"><span class="ico">🖥</span> Sistemas</a>
        <?php endif; ?>
        <?php if ($auth->can('import.credentials')): ?>
          <a class="nav-item <?= active('/importar', $path) ?>" href="<?= e(url('/importar')) ?>"><span class="ico">⇪</span> Importar</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($auth->canAny('users.view', 'roles.view')): ?>
      <div class="sidebar__group">
        <div class="sidebar__label">Personas</div>
        <?php if ($auth->can('users.view')): ?>
          <a class="nav-item <?= active('/usuarios', $path) ?>" href="<?= e(url('/usuarios')) ?>"><span class="ico">👥</span> Usuarios</a>
        <?php endif; ?>
        <?php if ($auth->can('roles.view')): ?>
          <a class="nav-item <?= active('/admin/roles', $path) ?>" href="<?= e(url('/admin/roles')) ?>"><span class="ico">⚖</span> Roles y permisos</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($auth->canAny('audit.view', 'sessions.view', 'security.events.view', 'reports.view')): ?>
      <div class="sidebar__group">
        <div class="sidebar__label">Control</div>
        <?php if ($auth->can('audit.view')): ?>
          <a class="nav-item <?= active('/auditoria', $path) ?>" href="<?= e(url('/auditoria')) ?>"><span class="ico">📋</span> Auditoria</a>
        <?php endif; ?>
        <?php if ($auth->can('security.events.view')): ?>
          <a class="nav-item <?= active('/seguridad', $path) ?>" href="<?= e(url('/seguridad/eventos')) ?>"><span class="ico">⚠</span> Eventos de seguridad</a>
        <?php endif; ?>
        <?php if ($auth->can('sessions.view')): ?>
          <a class="nav-item <?= active('/sesiones', $path) ?>" href="<?= e(url('/sesiones')) ?>"><span class="ico">🖧</span> Sesiones</a>
        <?php endif; ?>
        <?php if ($auth->can('reports.view')): ?>
          <a class="nav-item <?= active('/reportes', $path) ?>" href="<?= e(url('/reportes')) ?>"><span class="ico">📊</span> Reportes</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($auth->canAny('categories.manage', 'org.manage', 'settings.manage')): ?>
      <div class="sidebar__group">
        <div class="sidebar__label">Administracion</div>
        <?php if ($auth->can('categories.manage')): ?>
          <a class="nav-item <?= active('/admin/categorias', $path) ?>" href="<?= e(url('/admin/categorias')) ?>"><span class="ico">🏷</span> Categorias</a>
        <?php endif; ?>
        <?php if ($auth->can('org.manage')): ?>
          <a class="nav-item <?= active('/admin/organizacion', $path) ?>" href="<?= e(url('/admin/organizacion')) ?>"><span class="ico">🏢</span> Organizacion</a>
        <?php endif; ?>
        <?php if ($auth->can('settings.manage')): ?>
          <a class="nav-item <?= active('/admin/configuracion', $path) ?>" href="<?= e(url('/admin/configuracion')) ?>"><span class="ico">⚙</span> Configuracion</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </nav>

    <div class="sidebar__footer">
      <?= e($auth->fullName()) ?><br>
      <span class="text-small"><?= e(implode(', ', array_map(static fn ($r) => $r['name'], $auth->roles()))) ?></span>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="btn btn--ghost menu-toggle" data-menu-toggle type="button" aria-label="Menu">☰</button>

      <?php if ($auth->canAny('credentials.view', 'credentials.view_all')): ?>
      <div class="topbar__search">
        <input type="search" id="global-search" placeholder="Buscar credencial, sistema, usuario, correo…"
               autocomplete="off" aria-label="Busqueda global">
        <div class="search-results" id="global-search-results" hidden></div>
      </div>
      <?php endif; ?>

      <div class="topbar__actions">
        <?php if ($auth->can('notifications.view')): ?>
          <a class="btn btn--ghost btn--sm" href="<?= e(url('/notificaciones')) ?>" title="Notificaciones">🔔</a>
        <?php endif; ?>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('/perfil')) ?>" title="Mi perfil">
          <span class="avatar"><?= e(iniciales($user['first_name'] ?? '', $user['last_name'] ?? '')) ?></span>
        </a>
        <form method="post" action="<?= e(url('/salir')) ?>" data-no-busy="1">
          <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
          <button class="btn btn--sm" type="submit">Salir</button>
        </form>
      </div>
    </header>

    <main class="content">
      <div id="toasts" aria-live="polite"></div>
      <?= \App\Core\View::render('partials/flash') ?>
      <?= $content ?>
    </main>
  </div>
</div>

<!-- Modal de reautenticacion (step-up) para operaciones sensibles -->
<div class="modal-backdrop" id="reauth-modal" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="reauth-title">
    <div class="modal__head"><h3 id="reauth-title">Confirme su identidad</h3></div>
    <div class="modal__body stack">
      <p id="reauth-message" class="text-muted mb-0">
        Por seguridad, confirme su contrasena para continuar con esta operacion.
      </p>
      <div class="field">
        <label for="reauth-password">Contrasena</label>
        <input type="password" id="reauth-password" autocomplete="current-password">
      </div>
      <?php if (!empty($user['mfa_enabled'])): ?>
      <div class="field">
        <label for="reauth-code">Codigo de verificacion (opcional)</label>
        <input type="text" id="reauth-code" inputmode="numeric" autocomplete="one-time-code" maxlength="8">
      </div>
      <?php endif; ?>
      <p class="err" id="reauth-error" role="alert"></p>
    </div>
    <div class="modal__foot">
      <button type="button" class="btn" id="reauth-cancel">Cancelar</button>
      <button type="button" class="btn btn--primary" id="reauth-confirm">Confirmar</button>
    </div>
  </div>
</div>

<script nonce="<?= e($nonce) ?>">
  window.SCGCA = {
    basePath: <?= json_encode(\App\Core\Config::get('app.base_path', ''), JSON_UNESCAPED_SLASHES) ?>,
    csrf: <?= json_encode($csrf ?? '') ?>,
    authenticated: true
  };
</script>
<script nonce="<?= e($nonce) ?>" src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
