<div class="page-head">
  <div class="page-head__text">
    <h1>Estructura organizacional</h1>
    <p>Empresas, sedes y departamentos utilizados para clasificar sistemas, credenciales y usuarios.</p>
  </div>
</div>

<div class="grid grid--3">
  <!-- Empresas -->
  <div class="card">
    <div class="card__head"><h2>Empresas</h2><span class="spacer"></span>
      <button class="btn btn--sm btn--primary" data-modal-open="modal-company" type="button">Nueva</button></div>
    <div class="card__body card__body--flush">
      <table class="data">
        <tbody>
        <?php foreach ($companies as $row): ?>
          <tr>
            <td><strong><?= e($row['name']) ?></strong>
              <div class="muted text-small"><?= e($row['tax_id'] ?: '') ?></div></td>
            <td class="nowrap" style="text-align:right">
              <span class="badge <?= (int) $row['is_active'] === 1 ? 'ok' : 'muted' ?>"><?= (int) $row['is_active'] === 1 ? 'Activa' : 'Inactiva' ?></span>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($companies === []): ?><tr><td class="muted">Sin empresas registradas.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Sedes -->
  <div class="card">
    <div class="card__head"><h2>Sedes</h2><span class="spacer"></span>
      <button class="btn btn--sm btn--primary" data-modal-open="modal-location" type="button">Nueva</button></div>
    <div class="card__body card__body--flush">
      <table class="data">
        <tbody>
        <?php foreach ($locations as $row): ?>
          <tr>
            <td><strong><?= e($row['name']) ?></strong>
              <div class="muted text-small"><?= e($row['company_name']) ?><?= $row['city'] ? ' · ' . e($row['city']) : '' ?></div></td>
            <td class="nowrap" style="text-align:right">
              <span class="badge <?= (int) $row['is_active'] === 1 ? 'ok' : 'muted' ?>"><?= (int) $row['is_active'] === 1 ? 'Activa' : 'Inactiva' ?></span>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($locations === []): ?><tr><td class="muted">Sin sedes registradas.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Departamentos -->
  <div class="card">
    <div class="card__head"><h2>Departamentos</h2><span class="spacer"></span>
      <button class="btn btn--sm btn--primary" data-modal-open="modal-department" type="button">Nuevo</button></div>
    <div class="card__body card__body--flush">
      <table class="data">
        <tbody>
        <?php foreach ($departments as $row): ?>
          <tr>
            <td><strong><?= e($row['name']) ?></strong>
              <div class="muted text-small"><?= e($row['company_name']) ?><?= $row['location_name'] ? ' · ' . e($row['location_name']) : '' ?></div></td>
            <td class="nowrap" style="text-align:right">
              <span class="badge <?= (int) $row['is_active'] === 1 ? 'ok' : 'muted' ?>"><?= (int) $row['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></span>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($departments === []): ?><tr><td class="muted">Sin departamentos registrados.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="modal-company" hidden>
  <div class="modal"><form method="post" action="<?= e(url('/admin/empresas')) ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="is_active" value="1">
    <div class="modal__head"><h3>Nueva empresa</h3></div>
    <div class="modal__body stack">
      <div class="field"><label for="co-name">Nombre *</label><input type="text" id="co-name" name="name" required maxlength="150"></div>
      <div class="field"><label for="co-legal">Razon social</label><input type="text" id="co-legal" name="legal_name" maxlength="200"></div>
      <div class="field"><label for="co-tax">NIT / identificacion</label><input type="text" id="co-tax" name="tax_id" maxlength="50"></div>
    </div>
    <div class="modal__foot"><button type="button" class="btn" data-modal-close>Cancelar</button>
      <button type="submit" class="btn btn--primary">Crear</button></div>
  </form></div>
</div>

<div class="modal-backdrop" id="modal-location" hidden>
  <div class="modal"><form method="post" action="<?= e(url('/admin/sedes')) ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="is_active" value="1">
    <div class="modal__head"><h3>Nueva sede</h3></div>
    <div class="modal__body stack">
      <div class="field"><label for="lo-company">Empresa *</label>
        <select id="lo-company" name="company_id" required>
          <option value="">Seleccione…</option>
          <?php foreach ($companies as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label for="lo-name">Nombre *</label><input type="text" id="lo-name" name="name" required maxlength="150"></div>
      <div class="field"><label for="lo-city">Ciudad</label><input type="text" id="lo-city" name="city" maxlength="100"></div>
      <div class="field"><label for="lo-address">Direccion</label><input type="text" id="lo-address" name="address" maxlength="255"></div>
    </div>
    <div class="modal__foot"><button type="button" class="btn" data-modal-close>Cancelar</button>
      <button type="submit" class="btn btn--primary">Crear</button></div>
  </form></div>
</div>

<div class="modal-backdrop" id="modal-department" hidden>
  <div class="modal"><form method="post" action="<?= e(url('/admin/departamentos')) ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="is_active" value="1">
    <div class="modal__head"><h3>Nuevo departamento</h3></div>
    <div class="modal__body stack">
      <div class="field"><label for="de-company">Empresa *</label>
        <select id="de-company" name="company_id" required>
          <option value="">Seleccione…</option>
          <?php foreach ($companies as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label for="de-location">Sede</label>
        <select id="de-location" name="location_id"><option value="">—</option>
          <?php foreach ($locations as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label for="de-name">Nombre *</label><input type="text" id="de-name" name="name" required maxlength="150"></div>
      <div class="field"><label for="de-code">Codigo</label><input type="text" id="de-code" name="code" maxlength="40"></div>
    </div>
    <div class="modal__foot"><button type="button" class="btn" data-modal-close>Cancelar</button>
      <button type="submit" class="btn btn--primary">Crear</button></div>
  </form></div>
</div>
