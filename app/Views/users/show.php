<?php
$id = (int) $user['id'];
$activeAssignments = array_filter($assignments, static fn (array $a): bool => (int) $a['is_active'] === 1);
?>
<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($user['first_name'] . ' ' . $user['last_name']) ?></h1>
    <p>
      Cedula <span class="mono"><?= e($user['national_id']) ?></span> ·
      <?= e($user['position'] ?: 'Sin cargo') ?> ·
      <span class="badge <?= e(match ($user['status']) { 'active' => 'ok', 'inactive' => 'muted', default => 'danger' }) ?>">
        <?= e(match ($user['status']) { 'active' => 'Activo', 'inactive' => 'Inactivo', 'locked' => 'Bloqueado', default => 'Suspendido' }) ?>
      </span>
    </p>
  </div>
  <div class="page-actions">
    <?php if ($auth->can('users.update')): ?>
      <a class="btn" href="<?= e(url('/usuarios/' . $id . '/editar')) ?>">Editar</a>
    <?php endif; ?>
    <?php if ($auth->can('users.reset_password')): ?>
      <form method="post" action="<?= e(url('/usuarios/' . $id . '/restablecer')) ?>"
            data-confirm="Se generara una contrasena temporal y se cerraran sus sesiones. Confirme.">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="btn" type="submit">Restablecer contrasena</button>
      </form>
    <?php endif; ?>
    <?php if ($auth->can('users.deactivate')): ?>
      <?php if ($user['status'] === 'active'): ?>
        <button type="button" class="btn btn--danger" data-modal-open="modal-deactivate">Desactivar</button>
      <?php else: ?>
        <form method="post" action="<?= e(url('/usuarios/' . $id . '/reactivar')) ?>">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <button class="btn btn--primary" type="submit">Reactivar</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($auth->can('sessions.revoke')): ?>
      <form method="post" action="<?= e(url('/sesiones/usuario/' . $id . '/cerrar')) ?>"
            data-confirm="Se cerraran todas las sesiones activas de este usuario.">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="btn" type="submit">Cerrar sesiones</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($user['status'] !== 'active' && $activeAssignments !== []): ?>
<div class="alert alert--error">
  <div><strong>Atencion:</strong> el usuario esta inactivo pero conserva <?= count($activeAssignments) ?> asignacion(es) activa(s). Revoquelas o reasignelas.</div>
</div>
<?php endif; ?>

<div class="grid grid--sidebar">
  <div>
    <div class="card">
      <div class="card__head"><h2>Credenciales asignadas</h2><span class="spacer"></span>
        <span class="badge muted"><?= count($activeAssignments) ?> activas de <?= count($assignments) ?></span>
      </div>
      <div class="card__body card__body--flush">
        <?php if ($assignments === []): ?>
          <div class="empty"><strong>Sin asignaciones</strong>Este usuario no tiene credenciales autorizadas.</div>
        <?php else: ?>
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>Sistema</th><th>Credencial</th><th>Permisos</th><th class="nowrap">Otorgado</th><th class="nowrap">Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($assignments as $row): ?>
              <tr>
                <td><?= e($row['system_name']) ?></td>
                <td><a href="<?= e(url('/credenciales/' . (int) $row['credential_id'])) ?>"><?= e($row['credential_name']) ?></a>
                    <div class="muted text-small mono"><?= e($row['username'] ?: '') ?></div></td>
                <td>
                  <?php if ((int) $row['can_view_secret'] === 1): ?><span class="badge info">Ver</span><?php endif; ?>
                  <?php if ((int) $row['can_copy_secret'] === 1): ?><span class="badge info">Copiar</span><?php endif; ?>
                  <?php if ((int) $row['can_view_recovery'] === 1): ?><span class="badge warn">Recuperacion</span><?php endif; ?>
                </td>
                <td class="nowrap text-small"><?= e(fecha($row['granted_at'])) ?></td>
                <td class="nowrap">
                  <span class="badge <?= (int) $row['is_active'] === 1 ? 'ok' : 'muted' ?>">
                    <?= (int) $row['is_active'] === 1 ? 'Activo' : 'Revocado' ?>
                  </span>
                </td>
                <td class="nowrap">
                  <?php if ((int) $row['is_active'] === 1 && $auth->can('credentials.revoke')): ?>
                  <form method="post" action="<?= e(url('/credenciales/' . (int) $row['credential_id'] . '/revocar/' . $id)) ?>"
                        data-confirm="Revocar este acceso.">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="reason" value="Revocado desde la ficha del usuario">
                    <button class="btn btn--sm btn--danger" type="submit">Revocar</button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($auth->can('users.assign_roles') && $allPerms !== []): ?>
    <div class="card">
      <div class="card__head"><h2>Excepciones de permisos</h2></div>
      <form method="post" action="<?= e(url('/usuarios/' . $id . '/permisos')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="card__body">
          <p class="text-small text-muted">
            Ademas de los permisos que otorgan sus roles, puede conceder o denegar permisos concretos.
            Una <strong>denegacion siempre prevalece</strong> sobre cualquier concesion.
          </p>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>Permiso</th><th>Grupo</th><th class="nowrap">Por rol</th><th class="nowrap">Conceder</th><th class="nowrap">Denegar</th></tr></thead>
              <tbody>
              <?php foreach ($allPerms as $perm): ?>
                <?php
                  $code    = (string) $perm['code'];
                  $current = $overrides[$code] ?? null;
                  $byRole  = in_array($code, $permissions, true) && $current === null;
                ?>
                <tr>
                  <td><?= e($perm['name']) ?>
                      <div class="muted text-small mono"><?= e($code) ?></div>
                      <?php if ((int) $perm['is_sensitive'] === 1): ?><span class="badge danger">Sensible</span><?php endif; ?></td>
                  <td class="muted text-small"><?= e($perm['group_name']) ?></td>
                  <td class="nowrap"><?= $byRole ? '<span class="badge ok">Si</span>' : '<span class="muted">—</span>' ?></td>
                  <td class="nowrap"><input type="checkbox" name="allow[]" value="<?= e($code) ?>" <?= $current === 'allow' ? 'checked' : '' ?>></td>
                  <td class="nowrap"><input type="checkbox" name="deny[]" value="<?= e($code) ?>" <?= $current === 'deny' ? 'checked' : '' ?>></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="card__foot row row--end">
          <button class="btn btn--primary" type="submit">Guardar excepciones</button>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="card">
      <div class="card__head"><h3>Datos del usuario</h3></div>
      <div class="card__body">
        <dl class="dl" style="grid-template-columns:1fr 1fr">
          <dt>Usuario</dt><dd class="mono"><?= e($user['username']) ?></dd>
          <dt>Correo</dt><dd class="text-small"><?= e($user['email']) ?></dd>
          <dt>Codigo de empleado</dt><dd><?= e($user['employee_code'] ?: '—') ?></dd>
          <dt>Telefono</dt><dd><?= e($user['phone'] ?: '—') ?></dd>
          <dt>Empresa</dt><dd><?= e($user['company_name'] ?? '—') ?></dd>
          <dt>Sede</dt><dd><?= e($user['location_name'] ?? '—') ?></dd>
          <dt>Departamento</dt><dd><?= e($user['department_name'] ?? '—') ?></dd>
          <dt>MFA</dt><dd><?= (int) $user['mfa_enabled'] === 1 ? 'Activado' : 'No activado' ?><?= (int) $user['mfa_enforced'] === 1 ? ' (obligatorio)' : '' ?></dd>
          <dt>Ultimo ingreso</dt><dd><?= e(fecha($user['last_login_at'], true)) ?></dd>
          <dt>Ultima IP</dt><dd class="mono text-small"><?= e($user['last_login_ip'] ?: '—') ?></dd>
          <dt>Contrasena cambiada</dt><dd><?= e(fecha($user['password_changed_at'], true)) ?></dd>
          <dt>Creado</dt><dd><?= e(fecha($user['created_at'])) ?></dd>
          <?php if ($user['deactivated_at']): ?>
            <dt>Desactivado</dt><dd><?= e(fecha($user['deactivated_at'], true)) ?></dd>
            <dt>Motivo</dt><dd><?= e($user['deactivation_reason'] ?: '—') ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h3>Roles</h3></div>
      <div class="card__body">
        <?php if ($roles === []): ?><p class="text-muted mb-0">Sin roles asignados.</p><?php endif; ?>
        <?php foreach ($roles as $role): ?>
          <div class="row" style="justify-content:space-between">
            <span><strong><?= e($role['name']) ?></strong></span>
            <span class="badge muted">nivel <?= (int) $role['level'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h3>Permisos efectivos (<?= count($permissions) ?>)</h3></div>
      <div class="card__body">
        <div class="row">
          <?php foreach ($permissions as $code): ?>
            <span class="badge muted mono text-small"><?= e($code) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <?php if ($auth->can('audit.view')): ?>
    <div class="card">
      <div class="card__body">
        <a class="btn btn--block btn--sm" href="<?= e(url('/auditoria?user_id=' . $id)) ?>">Ver auditoria de este usuario</a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($auth->can('users.deactivate') && $user['status'] === 'active'): ?>
<div class="modal-backdrop" id="modal-deactivate" hidden>
  <div class="modal" role="dialog" aria-modal="true">
    <form method="post" action="<?= e(url('/usuarios/' . $id . '/desactivar')) ?>">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="modal__head"><h3>Desactivar usuario</h3></div>
      <div class="modal__body stack">
        <div class="alert alert--warn mb-0">
          Se bloquea el ingreso, se revocan todos sus accesos y se cierran sus sesiones.
          El historial y la auditoria <strong>se conservan intactos</strong>.
        </div>
        <div class="field">
          <label for="deactivate-reason">Motivo *</label>
          <input type="text" id="deactivate-reason" name="reason" required maxlength="255"
                 placeholder="Retiro del empleado, cambio de area, incidente…">
        </div>
        <div class="field">
          <label for="reassign_to">Reasignar sus accesos a (opcional)</label>
          <select id="reassign_to" name="reassign_to">
            <option value="">No reasignar</option>
            <?php foreach ($usersList as $u): ?>
              <?php if ((int) $u['id'] === $id) { continue; } ?>
              <option value="<?= (int) $u['id'] ?>"><?= e($u['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Se copian sus asignaciones al usuario indicado antes de revocar las suyas.</span>
        </div>
      </div>
      <div class="modal__foot">
        <button type="button" class="btn" data-modal-close>Cancelar</button>
        <button type="submit" class="btn btn--danger">Desactivar usuario</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
