<?php
$grouped = [];
foreach ($permissions as $perm) { $grouped[(string) $perm['group_name']][] = $perm; }
?>
<div class="page-head">
  <div class="page-head__text">
    <h1>Roles y permisos</h1>
    <p>Control de acceso granular. Un rol es un conjunto de permisos; cada permiso habilita una accion concreta.</p>
  </div>
  <?php if ($canManage): ?>
  <div class="page-actions"><button class="btn btn--primary" data-modal-open="modal-role" type="button">Nuevo rol</button></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card__body card__body--flush">
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Rol</th><th>Descripcion</th><th class="nowrap">Nivel</th><th class="nowrap">Usuarios</th><th class="nowrap">Permisos</th><th class="nowrap">MFA</th></tr></thead>
        <tbody>
        <?php foreach ($roles as $role): ?>
          <tr>
            <td><strong><?= e($role['name']) ?></strong><div class="muted text-small mono"><?= e($role['code']) ?></div></td>
            <td class="muted text-small"><?= e($role['description'] ?: '—') ?></td>
            <td class="nowrap"><span class="badge muted"><?= (int) $role['level'] ?></span></td>
            <td class="nowrap"><?= (int) $role['user_count'] ?></td>
            <td class="nowrap"><?= (int) $role['permission_count'] ?></td>
            <td class="nowrap"><span class="badge <?= (int) $role['requires_mfa'] === 1 ? 'ok' : 'muted' ?>"><?= (int) $role['requires_mfa'] === 1 ? 'Obligatorio' : 'Opcional' ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php foreach ($roles as $role): ?>
  <?php $codes = $matrix[(int) $role['id']] ?? []; ?>
  <div class="card">
    <div class="card__head">
      <h2><?= e($role['name']) ?></h2>
      <span class="badge muted mono"><?= e($role['code']) ?></span>
      <span class="spacer"></span>
      <span class="text-small text-muted"><?= count($codes) ?> permiso(s)</span>
    </div>
    <form method="post" action="<?= e(url('/admin/roles/' . (int) $role['id'] . '/permisos')) ?>">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="card__body">
        <?php foreach ($grouped as $group => $perms): ?>
          <h3 style="margin-top:.9rem;text-transform:capitalize"><?= e($group) ?></h3>
          <div class="form-grid form-grid--3">
            <?php foreach ($perms as $perm): ?>
              <label class="check">
                <input type="checkbox" name="permissions[]" value="<?= e($perm['code']) ?>"
                       <?= in_array($perm['code'], $codes, true) ? 'checked' : '' ?>
                       <?= $canManage ? '' : 'disabled' ?>>
                <span>
                  <?= e($perm['name']) ?>
                  <?php if ((int) $perm['is_sensitive'] === 1): ?><span class="badge danger">Sensible</span><?php endif; ?>
                  <div class="hint mono"><?= e($perm['code']) ?></div>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($canManage): ?>
      <div class="card__foot row row--end">
        <button class="btn btn--primary" type="submit">Guardar permisos de <?= e($role['name']) ?></button>
      </div>
      <?php endif; ?>
    </form>
  </div>
<?php endforeach; ?>

<?php if ($canManage): ?>
<div class="modal-backdrop" id="modal-role" hidden>
  <div class="modal"><form method="post" action="<?= e(url('/admin/roles')) ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="is_active" value="1">
    <div class="modal__head"><h3>Nuevo rol</h3></div>
    <div class="modal__body stack">
      <div class="field"><label for="role-code">Codigo *</label>
        <input type="text" id="role-code" name="code" required maxlength="40" placeholder="SUPERVISOR">
        <span class="hint">Solo letras, numeros y guion bajo. No se puede cambiar despues.</span></div>
      <div class="field"><label for="role-name">Nombre *</label><input type="text" id="role-name" name="name" required maxlength="80"></div>
      <div class="field"><label for="role-desc">Descripcion</label><input type="text" id="role-desc" name="description" maxlength="255"></div>
      <div class="field"><label for="role-level">Nivel de privilegio *</label>
        <input type="number" id="role-level" name="level" required min="1" max="99" value="20">
        <span class="hint">Mayor numero, mas privilegio. No puede superar ni igualar su propio nivel.</span></div>
      <label class="check"><input type="checkbox" name="requires_mfa" value="1"> Exigir verificacion en dos pasos a este rol</label>
    </div>
    <div class="modal__foot"><button type="button" class="btn" data-modal-close>Cancelar</button>
      <button type="submit" class="btn btn--primary">Crear rol</button></div>
  </form></div>
</div>
<?php endif; ?>
