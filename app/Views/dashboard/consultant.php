<div class="page-head">
  <div class="page-head__text">
    <h1>Mis accesos</h1>
    <p>Estas son las credenciales que le fueron autorizadas. Cada consulta queda registrada.</p>
  </div>
</div>

<div class="card">
  <div class="card__body">
    <form method="get" action="<?= e(url('/mis-accesos')) ?>" class="row">
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Buscar entre mis accesos…" style="max-width:320px">
      <button class="btn" type="submit">Buscar</button>
      <?php if ($search !== ''): ?><a class="btn btn--ghost" href="<?= e(url('/mis-accesos')) ?>">Limpiar</a><?php endif; ?>
    </form>
  </div>
</div>

<?php if ($items === []): ?>
  <div class="card"><div class="empty">
    <strong>Sin accesos asignados</strong>
    Solicite a su administrador la asignacion de las credenciales que necesita.
  </div></div>
<?php else: ?>
<div class="grid grid--2">
  <?php foreach ($items as $row): ?>
    <?php $credentialId = (int) $row['credential_id']; ?>
    <div class="card">
      <div class="card__head">
        <div style="flex:1;min-width:0">
          <h3 style="margin:0"><?= e($row['system_name']) ?></h3>
          <div class="text-small text-muted"><?= e($row['credential_name']) ?></div>
        </div>
        <?php if (!empty($row['category_name'])): ?>
          <span class="badge" style="background:<?= e(preg_match('/^#[0-9a-f]{6}$/i', (string) $row['category_color']) ? $row['category_color'] . '22' : 'var(--surface-3)') ?>">
            <?= e($row['category_name']) ?>
          </span>
        <?php endif; ?>
      </div>
      <div class="card__body stack">
        <?php if (!empty($row['url'])): ?>
          <div class="row" style="gap:.35rem">
            <span class="text-small text-muted">URL:</span>
            <a class="text-small truncate" href="<?= e($row['url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= e($row['url']) ?></a>
          </div>
        <?php endif; ?>

        <div class="row" style="gap:.35rem">
          <span class="text-small text-muted">Usuario:</span>
          <span class="mono text-small"><?= e($row['username'] ?: '—') ?></span>
        </div>

        <?php if ((int) $row['can_view_secret'] === 1 || (int) $row['can_copy_secret'] === 1): ?>
        <div class="secret" data-credential="<?= $credentialId ?>" data-field="password" data-revealed="0">
          <span class="secret__value is-hidden">••••••••••••</span>
          <span class="secret__timer"></span>
          <?php if ((int) $row['can_view_secret'] === 1 && $auth->can('credentials.secret.view')): ?>
            <button type="button" class="btn btn--sm" data-secret-toggle>Mostrar</button>
          <?php endif; ?>
          <?php if ((int) $row['can_copy_secret'] === 1 && $auth->can('credentials.secret.copy')): ?>
            <button type="button" class="btn btn--sm" data-secret-copy>Copiar</button>
          <?php endif; ?>
        </div>
        <?php else: ?>
          <p class="text-small text-muted mb-0">Su asignacion no incluye la visualizacion de la contrasena.</p>
        <?php endif; ?>

        <div class="row" style="justify-content:space-between">
          <a class="btn btn--sm btn--ghost" href="<?= e(url('/credenciales/' . $credentialId)) ?>">Ver ficha</a>
          <?php if (!empty($row['expires_at'])): ?>
            <span class="text-small text-muted">Acceso hasta <?= e(fecha($row['expires_at'])) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
