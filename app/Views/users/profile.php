<div class="page-head">
  <div class="page-head__text">
    <h1>Mi perfil</h1>
    <p><?= e($user['email']) ?> · Cedula <span class="mono"><?= e($user['national_id']) ?></span></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(url('/perfil/contrasena')) ?>">Cambiar contrasena</a>
    <a class="btn btn--primary" href="<?= e(url('/perfil/mfa')) ?>">Verificacion en dos pasos</a>
  </div>
</div>

<div class="grid grid--sidebar">
  <div>
    <div class="card">
      <div class="card__head"><h2>Mis sesiones activas</h2></div>
      <div class="card__body card__body--flush">
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>Dispositivo</th><th class="nowrap">IP</th><th class="nowrap">Inicio</th><th class="nowrap">Ultima actividad</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($sessions as $row): ?>
              <tr>
                <td><?= e($row['device'] ?: 'Desconocido') ?>
                  <?php if ($row['id'] === $currentSession): ?><span class="badge ok">Esta sesion</span><?php endif; ?>
                </td>
                <td class="nowrap mono text-small"><?= e($row['ip_address']) ?></td>
                <td class="nowrap text-small"><?= e(fecha($row['created_at'], true)) ?></td>
                <td class="nowrap text-small"><?= e(desde($row['last_activity_at'])) ?></td>
                <td class="nowrap">
                  <?php if ($row['id'] !== $currentSession && $auth->can('sessions.revoke')): ?>
                    <form method="post" action="<?= e(url('/sesiones/' . e($row['id']) . '/cerrar')) ?>">
                      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                      <button class="btn btn--sm btn--danger" type="submit">Cerrar</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>Mis credenciales asignadas (<?= count($assignments) ?>)</h2></div>
      <div class="card__body card__body--flush">
        <?php if ($assignments === []): ?>
          <div class="empty">Sin credenciales asignadas.</div>
        <?php else: ?>
        <table class="data">
          <thead><tr><th>Sistema</th><th>Credencial</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($assignments as $row): ?>
            <tr>
              <td><?= e($row['system_name']) ?></td>
              <td><?= e($row['credential_name']) ?></td>
              <td class="nowrap"><a class="btn btn--sm" href="<?= e(url('/credenciales/' . (int) $row['credential_id'])) ?>">Abrir</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card__head"><h3>Seguridad de mi cuenta</h3></div>
      <div class="card__body">
        <dl class="dl" style="grid-template-columns:1fr 1fr">
          <dt>Verificacion en dos pasos</dt>
          <dd><?= (int) $user['mfa_enabled'] === 1 ? '<span class="badge ok">Activada</span>' : '<span class="badge warn">No activada</span>' ?></dd>
          <dt>Codigos de respaldo</dt><dd><?= (int) $backupCodes ?> disponibles</dd>
          <dt>Contrasena cambiada</dt><dd><?= e(fecha($user['password_changed_at'], true)) ?></dd>
          <dt>Ultimo ingreso</dt><dd><?= e(fecha($user['last_login_at'], true)) ?></dd>
          <dt>Ultima IP</dt><dd class="mono text-small"><?= e($user['last_login_ip'] ?: '—') ?></dd>
        </dl>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h3>Mis roles y permisos</h3></div>
      <div class="card__body">
        <div class="row mb-1">
          <?php foreach ($roles as $role): ?><span class="badge brand"><?= e($role['name']) ?></span><?php endforeach; ?>
        </div>
        <div class="row">
          <?php foreach ($permissions as $code): ?><span class="badge muted mono text-small"><?= e($code) ?></span><?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>
