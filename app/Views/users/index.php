<?php $items = $result['items']; ?>
<div class="page-head">
  <div class="page-head__text">
    <h1>Usuarios</h1>
    <p><?= (int) $result['total'] ?> usuario(s) registrados.</p>
  </div>
  <div class="page-actions">
    <?php if ($auth->can('users.create')): ?>
      <a class="btn btn--primary" href="<?= e(url('/usuarios/nuevo')) ?>">Nuevo usuario</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__body">
    <form method="get" action="<?= e(url('/usuarios')) ?>" class="filters">
      <div class="field">
        <label for="q">Busqueda</label>
        <input type="search" id="q" name="q" value="<?= e($filters['search'] ?? '') ?>" placeholder="Nombre, cedula, usuario o correo…">
      </div>
      <div class="field">
        <label for="status">Estado</label>
        <select id="status" name="status">
          <option value="">Todos</option>
          <?php foreach (['active' => 'Activo', 'inactive' => 'Inactivo', 'locked' => 'Bloqueado', 'suspended' => 'Suspendido'] as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="role_id">Rol</label>
        <select id="role_id" name="role_id">
          <option value="">Todos</option>
          <?php foreach ($roles as $role): ?>
            <option value="<?= (int) $role['id'] ?>" <?= (int) ($filters['role_id'] ?? 0) === (int) $role['id'] ? 'selected' : '' ?>><?= e($role['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>&nbsp;</label>
        <div class="row">
          <button class="btn btn--primary" type="submit">Filtrar</button>
          <a class="btn btn--ghost" href="<?= e(url('/usuarios')) ?>">Limpiar</a>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--flush">
    <?php if ($items === []): ?>
      <div class="empty"><strong>Sin usuarios</strong>Ajuste los filtros o cree el primer usuario.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th>Usuario</th><th>Cedula</th><th>Correo</th><th>Roles</th><th>Area</th>
          <th class="nowrap">Accesos</th><th class="nowrap">MFA</th><th class="nowrap">Estado</th><th class="nowrap">Ultimo ingreso</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $row): ?>
          <tr>
            <td>
              <div class="row" style="gap:.5rem;flex-wrap:nowrap">
                <span class="avatar"><?= e(iniciales($row['first_name'], $row['last_name'])) ?></span>
                <div>
                  <a href="<?= e(url('/usuarios/' . (int) $row['id'])) ?>"><strong><?= e($row['first_name'] . ' ' . $row['last_name']) ?></strong></a>
                  <div class="muted text-small"><?= e($row['username']) ?></div>
                </div>
              </div>
            </td>
            <td class="mono text-small"><?= e($row['national_id']) ?></td>
            <td class="text-small truncate"><?= e($row['email']) ?></td>
            <td class="text-small"><?= e($row['role_names'] ?: '—') ?></td>
            <td class="muted text-small"><?= e($row['department_name'] ?: ($row['company_name'] ?: '—')) ?></td>
            <td class="nowrap"><span class="badge muted"><?= (int) $row['assigned_credentials'] ?></span></td>
            <td class="nowrap"><span class="badge <?= (int) $row['mfa_enabled'] === 1 ? 'ok' : 'muted' ?>"><?= (int) $row['mfa_enabled'] === 1 ? 'Si' : 'No' ?></span></td>
            <td class="nowrap">
              <span class="badge <?= e(match ($row['status']) { 'active' => 'ok', 'inactive' => 'muted', default => 'danger' }) ?>">
                <?= e(match ($row['status']) { 'active' => 'Activo', 'inactive' => 'Inactivo', 'locked' => 'Bloqueado', default => 'Suspendido' }) ?>
              </span>
            </td>
            <td class="nowrap text-small muted"><?= e(desde($row['last_login_at'])) ?></td>
            <td class="nowrap"><a class="btn btn--sm" href="<?= e(url('/usuarios/' . (int) $row['id'])) ?>">Abrir</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php if ((int) $result['pages'] > 1): ?>
  <div class="card__foot">
    <?= \App\Core\View::render('partials/pagination', [
        'page' => $result['page'], 'pages' => $result['pages'],
        'filters' => array_merge($filters, ['q' => $filters['search'] ?? null]),
        'basePath' => url('/usuarios')]) ?>
  </div>
  <?php endif; ?>
</div>
