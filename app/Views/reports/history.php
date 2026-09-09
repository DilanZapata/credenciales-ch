<?php $items = $result['items']; ?>
<div class="page-head">
  <div class="page-head__text">
    <h1>Historial de exportaciones</h1>
    <p>Trazabilidad completa: quien exporto, cuando, cuantos registros y si incluyo contrasenas.</p>
  </div>
  <div class="page-actions"><a class="btn" href="<?= e(url('/reportes')) ?>">Volver a reportes</a></div>
</div>

<div class="card">
  <div class="card__body">
    <form method="get" action="<?= e(url('/reportes/historial')) ?>" class="filters">
      <div class="field">
        <label for="user_id">Usuario</label>
        <select id="user_id" name="user_id"><option value="">Todos</option>
          <?php foreach ($usersList as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= (int) ($filters['user_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="report_type">Tipo</label>
        <select id="report_type" name="report_type"><option value="">Todos</option>
          <?php foreach (['inventory' => 'Inventario', 'full_credentials' => 'Credenciales completas',
                          'history' => 'Historial', 'audit' => 'Auditoria'] as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= ($filters['report_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="included_secrets">Contrasenas</label>
        <select id="included_secrets" name="included_secrets">
          <option value="">Todos</option>
          <option value="1" <?= ($filters['included_secrets'] ?? '') === '1' ? 'selected' : '' ?>>Con contrasenas</option>
          <option value="0" <?= ($filters['included_secrets'] ?? '') === '0' ? 'selected' : '' ?>>Sin contrasenas</option>
        </select>
      </div>
      <div class="field"><label for="date_from">Desde</label><input type="date" id="date_from" name="date_from" value="<?= e($filters['date_from'] ?? '') ?>"></div>
      <div class="field"><label for="date_to">Hasta</label><input type="date" id="date_to" name="date_to" value="<?= e($filters['date_to'] ?? '') ?>"></div>
      <div class="field"><label>&nbsp;</label>
        <div class="row"><button class="btn btn--primary" type="submit">Filtrar</button>
        <a class="btn btn--ghost" href="<?= e(url('/reportes/historial')) ?>">Limpiar</a></div>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--flush">
    <?php if ($items === []): ?>
      <div class="empty"><strong>Sin exportaciones</strong>Todavia no se ha generado ningun reporte.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th class="nowrap">Fecha y hora</th><th>Usuario</th><th>Cedula</th><th>Tipo</th>
          <th class="nowrap">Registros</th><th class="nowrap">Contrasenas</th><th>Filtros</th>
          <th class="nowrap">IP</th><th class="nowrap">Estado</th><th>Identificador</th>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $row): ?>
          <tr>
            <td class="nowrap text-small"><?= e(fecha($row['created_at'], true)) ?></td>
            <td class="text-small"><?= e($row['user_name'] ?: '—') ?></td>
            <td class="mono text-small"><?= e($row['actor_national_id'] ?: '—') ?></td>
            <td class="text-small"><?= e(match ($row['report_type']) {
                  'inventory' => 'Inventario', 'full_credentials' => 'Credenciales completas',
                  'history' => 'Historial', 'audit' => 'Auditoria', default => (string) $row['report_type'] }) ?></td>
            <td class="nowrap"><span class="badge muted"><?= (int) $row['record_count'] ?></span></td>
            <td class="nowrap">
              <span class="badge <?= (int) $row['included_secrets'] === 1 ? 'danger' : 'ok' ?>">
                <?= (int) $row['included_secrets'] === 1 ? 'SI' : 'NO' ?>
              </span>
            </td>
            <td class="text-small muted truncate"><?= e((string) ($row['filters'] ?? '')) ?></td>
            <td class="nowrap mono text-small"><?= e($row['ip_address'] ?: '—') ?></td>
            <td class="nowrap">
              <span class="badge <?= e(match ($row['status']) { 'downloaded' => 'ok', 'failed' => 'danger', 'ready' => 'info', default => 'muted' }) ?>">
                <?= e(match ($row['status']) { 'generating' => 'Generando', 'ready' => 'Disponible',
                      'downloaded' => 'Descargado', 'expired' => 'Expirado', 'purged' => 'Eliminado', default => 'Fallido' }) ?>
              </span>
            </td>
            <td class="mono text-small truncate"><?= e($row['uuid']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($pages > 1): ?>
  <div class="card__foot">
    <?= \App\Core\View::render('partials/pagination', ['page' => $page, 'pages' => $pages, 'filters' => $filters, 'basePath' => url('/reportes/historial')]) ?>
  </div>
  <?php endif; ?>
</div>
