<?php
$isEdit = $user !== null;
$action = $isEdit ? url('/usuarios/' . (int) $user['id']) : url('/usuarios');
$v = static fn (string $k, $d = '') => $user[$k] ?? $d;
?>
<div class="page-head">
  <div class="page-head__text">
    <h1><?= $isEdit ? 'Editar usuario' : 'Nuevo usuario' ?></h1>
    <p>La cedula identifica al empleado en el sistema y en la auditoria, pero nunca autentica por si sola.</p>
  </div>
</div>

<form method="post" action="<?= e($action) ?>">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

  <div class="card">
    <div class="card__head"><h2>Datos personales</h2></div>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label for="national_id">Cedula / documento *</label>
          <input type="text" id="national_id" name="national_id" required maxlength="30" value="<?= e($v('national_id')) ?>">
        </div>
        <div class="field">
          <label for="employee_code">Codigo de empleado</label>
          <input type="text" id="employee_code" name="employee_code" maxlength="40" value="<?= e($v('employee_code')) ?>">
        </div>
        <div class="field">
          <label for="first_name">Nombres *</label>
          <input type="text" id="first_name" name="first_name" required maxlength="80" value="<?= e($v('first_name')) ?>">
        </div>
        <div class="field">
          <label for="last_name">Apellidos *</label>
          <input type="text" id="last_name" name="last_name" required maxlength="80" value="<?= e($v('last_name')) ?>">
        </div>
        <div class="field">
          <label for="email">Correo corporativo *</label>
          <input type="email" id="email" name="email" required maxlength="190" value="<?= e($v('email')) ?>">
        </div>
        <div class="field">
          <label for="phone">Telefono</label>
          <input type="tel" id="phone" name="phone" maxlength="40" value="<?= e($v('phone')) ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2>Acceso y organizacion</h2></div>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label for="username">Nombre de usuario *</label>
          <input type="text" id="username" name="username" required maxlength="60" value="<?= e($v('username')) ?>"
                 autocomplete="off" spellcheck="false">
          <span class="hint">Entre 4 y 60 caracteres: letras, numeros, punto, guion y guion bajo.</span>
        </div>
        <div class="field">
          <label for="position">Cargo</label>
          <input type="text" id="position" name="position" maxlength="120" value="<?= e($v('position')) ?>">
        </div>
        <div class="field">
          <label for="company_id">Empresa</label>
          <select id="company_id" name="company_id"><option value="">—</option>
            <?php foreach ($companies as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= (int) $v('company_id', 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="location_id">Sede</label>
          <select id="location_id" name="location_id"><option value="">—</option>
            <?php foreach ($locations as $l): ?>
              <option value="<?= (int) $l['id'] ?>" <?= (int) $v('location_id', 0) === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="department_id">Departamento</label>
          <select id="department_id" name="department_id"><option value="">—</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= (int) $d['id'] ?>" <?= (int) $v('department_id', 0) === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($isEdit): ?>
        <div class="field">
          <label for="status">Estado</label>
          <select id="status" name="status">
            <?php foreach (['active' => 'Activo', 'inactive' => 'Inactivo', 'locked' => 'Bloqueado', 'suspended' => 'Suspendido'] as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= (string) $v('status', 'active') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="field full">
          <label class="check">
            <input type="checkbox" name="mfa_enforced" value="1" <?= (int) $v('mfa_enforced', 0) === 1 ? 'checked' : '' ?>>
            Exigir verificacion en dos pasos a este usuario
          </label>
        </div>
        <div class="field full">
          <label for="notes">Notas internas</label>
          <textarea id="notes" name="notes" maxlength="500"><?= e($v('notes')) ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2>Roles</h2></div>
    <div class="card__body">
      <?php if (!$auth->can('users.assign_roles')): ?>
        <p class="text-muted mb-0">No tiene permiso para modificar los roles.</p>
      <?php else: ?>
      <div class="form-grid">
        <?php foreach ($roles as $role): ?>
          <label class="check">
            <input type="checkbox" name="roles[]" value="<?= (int) $role['id'] ?>"
                   <?= in_array((int) $role['id'], $userRoles, true) ? 'checked' : '' ?>>
            <span>
              <strong><?= e($role['name']) ?></strong> <span class="badge muted">nivel <?= (int) $role['level'] ?></span>
              <div class="hint"><?= e($role['description'] ?: '') ?></div>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="card__foot row row--end">
      <a class="btn" href="<?= e($isEdit ? url('/usuarios/' . (int) $user['id']) : url('/usuarios')) ?>">Cancelar</a>
      <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Guardar cambios' : 'Crear usuario' ?></button>
    </div>
  </div>

  <?php if (!$isEdit): ?>
  <div class="alert alert--info">
    Al crear el usuario se genera una contrasena temporal robusta que se muestra <strong>una sola vez</strong>.
    El usuario debera cambiarla en su primer ingreso.
  </div>
  <?php endif; ?>
</form>
