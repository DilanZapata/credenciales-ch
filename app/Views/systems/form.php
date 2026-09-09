<?php
$isEdit = $system !== null;
$action = $isEdit ? url('/sistemas/' . (int) $system['id']) : url('/sistemas');
$v = static fn (string $k, $d = '') => $system[$k] ?? $d;
?>
<div class="page-head">
  <div class="page-head__text">
    <h1><?= $isEdit ? 'Editar sistema' : 'Nuevo sistema' ?></h1>
    <p>Un sistema agrupa las credenciales de un mismo recurso (aplicacion, servidor, correo, red…).</p>
  </div>
</div>

<form method="post" action="<?= e($action) ?>">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

  <div class="card">
    <div class="card__head"><h2>Identificacion</h2></div>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label for="name">Nombre del sistema *</label>
          <input type="text" id="name" name="name" required maxlength="180" value="<?= e($v('name')) ?>">
        </div>
        <div class="field">
          <label for="display_name">Nombre descriptivo</label>
          <input type="text" id="display_name" name="display_name" maxlength="180" value="<?= e($v('display_name')) ?>">
        </div>
        <div class="field">
          <label for="code">Codigo interno</label>
          <input type="text" id="code" name="code" maxlength="60" value="<?= e($v('code')) ?>">
        </div>
        <div class="field">
          <label for="category_id">Categoria</label>
          <select id="category_id" name="category_id">
            <option value="">Sin categoria</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= (int) $v('category_id', 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="resource_type">Tipo de recurso</label>
          <select id="resource_type" name="resource_type">
            <?php foreach ($types as $type): ?>
              <option value="<?= e($type) ?>" <?= (string) $v('resource_type', 'other') === $type ? 'selected' : '' ?>><?= e(tipoRecurso($type)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="criticality">Criticidad</label>
          <select id="criticality" name="criticality">
            <?php foreach (['low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta', 'critical' => 'Critica'] as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= (string) $v('criticality', 'medium') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field full">
          <label for="description">Descripcion</label>
          <textarea id="description" name="description" maxlength="3000"><?= e($v('description')) ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2>Datos tecnicos</h2></div>
    <div class="card__body">
      <div class="form-grid--3 form-grid">
        <div class="field"><label for="url">URL</label>
          <input type="url" id="url" name="url" maxlength="500" value="<?= e($v('url')) ?>" placeholder="https://…"></div>
        <div class="field"><label for="ip_address">Direccion IP</label>
          <input type="text" id="ip_address" name="ip_address" maxlength="45" value="<?= e($v('ip_address')) ?>"></div>
        <div class="field"><label for="port">Puerto</label>
          <input type="number" id="port" name="port" min="1" max="65535" value="<?= e($v('port')) ?>"></div>
        <div class="field"><label for="hostname">Nombre del servidor</label>
          <input type="text" id="hostname" name="hostname" maxlength="180" value="<?= e($v('hostname')) ?>"></div>
        <div class="field"><label for="platform">Plataforma</label>
          <input type="text" id="platform" name="platform" maxlength="120" value="<?= e($v('platform')) ?>"></div>
        <div class="field"><label for="provider">Proveedor</label>
          <input type="text" id="provider" name="provider" maxlength="120" value="<?= e($v('provider')) ?>"></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2>Ubicacion y responsabilidad</h2></div>
    <div class="card__body">
      <div class="form-grid">
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
              <option value="<?= (int) $l['id'] ?>" <?= (int) $v('location_id', 0) === (int) $l['id'] ? 'selected' : '' ?>>
                <?= e($l['name']) ?> (<?= e($l['company_name']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="department_id">Departamento</label>
          <select id="department_id" name="department_id"><option value="">—</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= (int) $d['id'] ?>" <?= (int) $v('department_id', 0) === (int) $d['id'] ? 'selected' : '' ?>>
                <?= e($d['name']) ?> (<?= e($d['company_name']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="owner_user_id">Responsable</label>
          <select id="owner_user_id" name="owner_user_id"><option value="">Sin responsable</option>
            <?php foreach ($usersList as $u): ?>
              <option value="<?= (int) $u['id'] ?>" <?= (int) $v('owner_user_id', 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($isEdit): ?>
        <div class="field">
          <label for="status">Estado</label>
          <select id="status" name="status">
            <?php foreach (['active' => 'Activo', 'inactive' => 'Inactivo', 'archived' => 'Archivado'] as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= (string) $v('status', 'active') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="card__foot row row--end">
      <a class="btn" href="<?= e(url('/sistemas')) ?>">Cancelar</a>
      <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Guardar cambios' : 'Registrar sistema' ?></button>
    </div>
  </div>
</form>
