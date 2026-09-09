<?php $items = $result['items']; ?>
<div class="page-head">
  <div class="page-head__text">
    <h1>Auditoria</h1>
    <p><?= (int) $result['total'] ?> evento(s) registrados. Cada accion critica del sistema deja rastro.</p>
  </div>
  <div class="page-actions">
    <?php if ($auth->can('audit.export')): ?>
    <form method="post" action="<?= e(url('/reportes/generar')) ?>">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="type" value="audit">
      <?php foreach ($filters as $key => $value): ?>
        <input type="hidden" name="<?= e($key) ?>" value="<?= e(is_scalar($value) ? (string) $value : '') ?>">
      <?php endforeach; ?>
      <button class="btn" type="submit">Exportar a Excel</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__body">
    <form method="get" action="<?= e(url('/auditoria')) ?>" class="filters">
      <div class="field">
        <label for="q">Busqueda</label>
        <input type="search" id="q" name="q" value="<?= e($filters['search'] ?? '') ?>" placeholder="Recurso, usuario, accion…">
      </div>
      <div class="field">
        <label for="national_id">Cedula</label>
        <input type="text" id="national_id" name="national_id" value="<?= e($filters['national_id'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="user_id">Usuario</label>
        <select id="user_id" name="user_id">
          <option value="">Todos</option>
          <?php foreach ($usersList as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= (int) ($filters['user_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="action">Tipo de accion</label>
        <select id="action" name="action">
          <option value="">Todas</option>
          <?php foreach ($actions as $act): ?>
            <option value="<?= e($act) ?>" <?= ($filters['action'] ?? '') === $act ? 'selected' : '' ?>><?= e(accionAuditoria($act)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="result">Resultado</label>
        <select id="result" name="result">
          <option value="">Todos</option>
          <?php foreach (['success' => 'Exitoso', 'failure' => 'Fallido', 'denied' => 'Denegado'] as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= ($filters['result'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="date_from">Desde</label>
        <input type="date" id="date_from" name="date_from" value="<?= e($filters['date_from'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="date_to">Hasta</label>
        <input type="date" id="date_to" name="date_to" value="<?= e($filters['date_to'] ?? '') ?>">
      </div>
      <div class="field">
        <label>&nbsp;</label>
        <div class="row">
          <button class="btn btn--primary" type="submit">Filtrar</button>
          <a class="btn btn--ghost" href="<?= e(url('/auditoria')) ?>">Limpiar</a>
        </div>
      </div>
    </form>

    <div class="row mt-1">
      <a class="btn btn--sm" href="<?= e(url('/auditoria?action_group=secret')) ?>">Accesos a contrasenas</a>
      <a class="btn btn--sm" href="<?= e(url('/auditoria?action_group=export')) ?>">Exportaciones</a>
      <a class="btn btn--sm" href="<?= e(url('/auditoria?action_group=auth')) ?>">Autenticacion</a>
      <a class="btn btn--sm" href="<?= e(url('/auditoria?result=denied')) ?>">Accesos denegados</a>
    </div>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--flush">
    <?php if ($items === []): ?>
      <div class="empty"><strong>Sin eventos</strong>No hay registros que coincidan con los filtros.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th class="nowrap">Fecha y hora</th><th>Usuario</th><th>Cedula</th><th>Accion</th>
          <th>Recurso</th><th class="nowrap">Resultado</th><th class="nowrap">IP</th><th>Dispositivo</th><th>Detalle</th>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $row): ?>
          <tr>
            <td class="nowrap text-small"><?= e(fecha($row['occurred_at'], true)) ?></td>
            <td class="text-small"><?= e($row['actor_name'] ?: 'Sistema') ?></td>
            <td class="mono text-small"><?= e($row['actor_national_id'] ?: '—') ?></td>
            <td class="text-small"><?= e(accionAuditoria((string) $row['action'])) ?></td>
            <td class="text-small truncate"><?= e($row['entity_label'] ?: ($row['entity_type'] ? $row['entity_type'] . ' #' . $row['entity_id'] : '—')) ?></td>
            <td class="nowrap">
              <span class="badge <?= e(match ($row['result']) { 'success' => 'ok', 'denied' => 'danger', default => 'warn' }) ?>">
                <?= e(match ($row['result']) { 'success' => 'Exitoso', 'denied' => 'Denegado', default => 'Fallido' }) ?>
              </span>
            </td>
            <td class="nowrap mono text-small"><?= e($row['ip_address'] ?: '—') ?></td>
            <td class="text-small muted truncate"><?= e($row['device'] ?: '—') ?></td>
            <td class="text-small muted truncate" title="<?= e((string) ($row['details'] ?? '')) ?>"><?= e(mb_substr((string) ($row['details'] ?? '—'), 0, 60)) ?></td>
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
        'basePath' => url('/auditoria')]) ?>
  </div>
  <?php endif; ?>
</div>
