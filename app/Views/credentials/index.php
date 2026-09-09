<?php
/** @var array $result @var array $filters */
$items = $result['items'];
?>
<div class="page-head">
  <div class="page-head__text">
    <h1>Credenciales</h1>
    <p><?= (int) $result['total'] ?> credencial(es) dentro de su alcance de consulta.</p>
  </div>
  <div class="page-actions">
    <?php if ($auth->can('credentials.create')): ?>
      <a class="btn btn--primary" href="<?= e(url('/credenciales/nueva')) ?>">Nueva credencial</a>
    <?php endif; ?>
    <?php if ($auth->can('reports.view')): ?>
      <a class="btn" href="<?= e(url('/reportes')) ?>">Exportar</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__body">
    <form method="get" action="<?= e(url('/credenciales')) ?>" class="filters">
      <div class="field">
        <label for="q">Busqueda</label>
        <input type="search" id="q" name="q" value="<?= e($filters['search'] ?? '') ?>"
               placeholder="Nombre, usuario, correo, URL, sistema…">
      </div>
      <div class="field">
        <label for="category_id">Categoria</label>
        <select id="category_id" name="category_id">
          <option value="">Todas</option>
          <?php foreach ($categories as $category): ?>
            <option value="<?= (int) $category['id'] ?>" <?= (int) ($filters['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>>
              <?= e($category['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="system_id">Sistema</label>
        <select id="system_id" name="system_id">
          <option value="">Todos</option>
          <?php foreach ($systems as $system): ?>
            <option value="<?= (int) $system['id'] ?>" <?= (int) ($filters['system_id'] ?? 0) === (int) $system['id'] ? 'selected' : '' ?>>
              <?= e($system['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($auth->can('credentials.view_all')): ?>
      <div class="field">
        <label for="company_id">Empresa</label>
        <select id="company_id" name="company_id">
          <option value="">Todas</option>
          <?php foreach ($companies as $company): ?>
            <option value="<?= (int) $company['id'] ?>" <?= (int) ($filters['company_id'] ?? 0) === (int) $company['id'] ? 'selected' : '' ?>>
              <?= e($company['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="department_id">Departamento</label>
        <select id="department_id" name="department_id">
          <option value="">Todos</option>
          <?php foreach ($departments as $department): ?>
            <option value="<?= (int) $department['id'] ?>" <?= (int) ($filters['department_id'] ?? 0) === (int) $department['id'] ? 'selected' : '' ?>>
              <?= e($department['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="status">Estado</label>
        <select id="status" name="status">
          <option value="">Todos</option>
          <?php foreach (['active' => 'Activa', 'inactive' => 'Inactiva', 'expired' => 'Vencida', 'revoked' => 'Revocada', 'archived' => 'Archivada'] as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="field">
        <label>&nbsp;</label>
        <div class="row">
          <button class="btn btn--primary" type="submit">Filtrar</button>
          <a class="btn btn--ghost" href="<?= e(url('/credenciales')) ?>">Limpiar</a>
        </div>
      </div>
    </form>

    <?php if ($auth->can('credentials.view_all')): ?>
    <div class="row mt-1" style="gap:.4rem">
      <a class="btn btn--sm <?= !empty($filters['expired']) ? 'btn--primary' : '' ?>" href="<?= e(url('/credenciales?expired=1')) ?>">Vencidas / rotacion pendiente</a>
      <a class="btn btn--sm <?= !empty($filters['never_rotated']) ? 'btn--primary' : '' ?>" href="<?= e(url('/credenciales?never_rotated=1')) ?>">Nunca actualizadas</a>
      <a class="btn btn--sm <?= !empty($filters['without_owner']) ? 'btn--primary' : '' ?>" href="<?= e(url('/credenciales?without_owner=1')) ?>">Sin responsable</a>
      <a class="btn btn--sm <?= !empty($filters['without_assignments']) ? 'btn--primary' : '' ?>" href="<?= e(url('/credenciales?without_assignments=1')) ?>">Sin usuarios asignados</a>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--flush">
    <?php if ($items === []): ?>
      <div class="empty"><strong>Sin resultados</strong>Ajuste los filtros o registre una nueva credencial.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>Credencial</th>
            <th>Sistema</th>
            <th>Categoria</th>
            <th>Usuario</th>
            <th>Responsable</th>
            <th class="nowrap">Rotacion</th>
            <th class="nowrap">Estado</th>
            <th class="nowrap">Accesos</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $row): ?>
          <?php
            $estado   = estadoCredencial((string) $row['status']);
            $rotacion = estadoRotacion((string) $row['rotation_state']);
          ?>
          <tr>
            <td>
              <a href="<?= e(url('/credenciales/' . $row['id'])) ?>"><strong><?= e($row['name']) ?></strong></a>
              <?php if (!empty($row['url'])): ?>
                <div class="muted text-small truncate"><?= e($row['url']) ?></div>
              <?php endif; ?>
            </td>
            <td class="muted"><?= e($row['system_name']) ?><div class="text-small"><?= e(tipoRecurso($row['resource_type'])) ?></div></td>
            <td>
              <?php if (!empty($row['category_name'])): ?>
                <span class="badge"><?= e($row['category_name']) ?></span>
              <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td class="mono text-small"><?= e($row['username'] ?: ($row['email'] ?: '—')) ?></td>
            <td class="muted text-small"><?= e($row['owner_name'] ?: '—') ?></td>
            <td class="nowrap"><span class="badge <?= e($rotacion['class']) ?>"><?= e($rotacion['label']) ?></span></td>
            <td class="nowrap"><span class="badge <?= e($estado['class']) ?>"><?= e($estado['label']) ?></span></td>
            <td class="nowrap"><span class="badge muted"><?= (int) $row['assignment_count'] ?></span></td>
            <td class="nowrap">
              <a class="btn btn--sm" href="<?= e(url('/credenciales/' . $row['id'])) ?>">Abrir</a>
            </td>
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
        'basePath' => url('/credenciales'),
    ]) ?>
  </div>
  <?php endif; ?>
</div>
