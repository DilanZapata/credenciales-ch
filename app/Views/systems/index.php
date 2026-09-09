<?php $items = $result['items']; ?>
<div class="page-head">
  <div class="page-head__text">
    <h1>Sistemas</h1>
    <p><?= (int) $result['total'] ?> recurso(s) registrados que requieren autenticacion.</p>
  </div>
  <div class="page-actions">
    <?php if ($auth->can('systems.create')): ?>
      <a class="btn btn--primary" href="<?= e(url('/sistemas/nuevo')) ?>">Nuevo sistema</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__body">
    <form method="get" action="<?= e(url('/sistemas')) ?>" class="filters">
      <div class="field">
        <label for="q">Busqueda</label>
        <input type="search" id="q" name="q" value="<?= e($filters['search'] ?? '') ?>" placeholder="Nombre, servidor, URL, IP…">
      </div>
      <div class="field">
        <label for="category_id">Categoria</label>
        <select id="category_id" name="category_id">
          <option value="">Todas</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) ($filters['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="resource_type">Tipo</label>
        <select id="resource_type" name="resource_type">
          <option value="">Todos</option>
          <?php foreach ($types as $type): ?>
            <option value="<?= e($type) ?>" <?= ($filters['resource_type'] ?? '') === $type ? 'selected' : '' ?>><?= e(tipoRecurso($type)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="company_id">Empresa</label>
        <select id="company_id" name="company_id">
          <option value="">Todas</option>
          <?php foreach ($companies as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) ($filters['company_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>&nbsp;</label>
        <div class="row">
          <button class="btn btn--primary" type="submit">Filtrar</button>
          <a class="btn btn--ghost" href="<?= e(url('/sistemas')) ?>">Limpiar</a>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--flush">
    <?php if ($items === []): ?>
      <div class="empty"><strong>Sin sistemas</strong>Registre el primer recurso del inventario.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th>Sistema</th><th>Categoria</th><th>Tipo</th><th>Ubicacion</th>
          <th>Responsable</th><th class="nowrap">Credenciales</th><th class="nowrap">Criticidad</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $row): ?>
          <tr>
            <td><a href="<?= e(url('/sistemas/' . (int) $row['id'])) ?>"><strong><?= e($row['name']) ?></strong></a>
              <?php if (!empty($row['hostname']) || !empty($row['ip_address'])): ?>
                <div class="muted text-small mono"><?= e($row['hostname'] ?: $row['ip_address']) ?></div>
              <?php endif; ?>
            </td>
            <td><?php if ($row['category_name']): ?><span class="badge"><?= e($row['category_name']) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td class="muted"><?= e(tipoRecurso($row['resource_type'])) ?></td>
            <td class="muted text-small"><?= e(trim(($row['company_name'] ?? '') . ' · ' . ($row['location_name'] ?? ''), ' ·') ?: '—') ?></td>
            <td class="text-small"><?= e($row['owner_name'] ?: '—') ?></td>
            <td class="nowrap"><span class="badge muted"><?= (int) $row['credential_count'] ?></span></td>
            <td class="nowrap">
              <span class="badge <?= e(match ($row['criticality']) { 'critical' => 'danger', 'high' => 'warn', default => 'muted' }) ?>">
                <?= e(match ($row['criticality']) { 'critical' => 'Critica', 'high' => 'Alta', 'medium' => 'Media', default => 'Baja' }) ?>
              </span>
            </td>
            <td class="nowrap"><a class="btn btn--sm" href="<?= e(url('/sistemas/' . (int) $row['id'])) ?>">Abrir</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($pages > 1): ?>
  <div class="card__foot">
    <?= \App\Core\View::render('partials/pagination', [
        'page' => $page, 'pages' => $pages,
        'filters' => array_merge($filters, ['q' => $filters['search'] ?? null]),
        'basePath' => url('/sistemas')]) ?>
  </div>
  <?php endif; ?>
</div>
