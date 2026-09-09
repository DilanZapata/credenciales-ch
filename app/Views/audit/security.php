<div class="page-head">
  <div class="page-head__text">
    <h1>Eventos de seguridad</h1>
    <p>Anomalias detectadas automaticamente por el sistema.</p>
  </div>
</div>

<div class="card">
  <div class="card__body">
    <form method="get" action="<?= e(url('/seguridad/eventos')) ?>" class="filters">
      <div class="field">
        <label for="status">Estado</label>
        <select id="status" name="status">
          <option value="">Todos</option>
          <?php foreach (['open' => 'Abiertos', 'acknowledged' => 'Reconocidos', 'resolved' => 'Resueltos'] as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="severity">Severidad</label>
        <select id="severity" name="severity">
          <option value="">Todas</option>
          <?php foreach (['critical' => 'Critica', 'high' => 'Alta', 'medium' => 'Media', 'low' => 'Baja'] as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= ($filters['severity'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>&nbsp;</label>
        <div class="row">
          <button class="btn btn--primary" type="submit">Filtrar</button>
          <a class="btn btn--ghost" href="<?= e(url('/seguridad/eventos')) ?>">Limpiar</a>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--flush">
    <?php if ($events === []): ?>
      <div class="empty"><strong>Sin eventos</strong>No hay anomalias registradas.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th class="nowrap">Fecha</th><th class="nowrap">Severidad</th><th>Evento</th><th>Usuario</th><th class="nowrap">IP</th><th class="nowrap">Estado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($events as $row): ?>
          <tr>
            <td class="nowrap text-small"><?= e(fecha($row['created_at'], true)) ?></td>
            <td class="nowrap">
              <span class="badge <?= e(match ($row['severity']) { 'critical', 'high' => 'danger', 'medium' => 'warn', default => 'muted' }) ?>">
                <?= e(match ($row['severity']) { 'critical' => 'Critica', 'high' => 'Alta', 'medium' => 'Media', default => 'Baja' }) ?>
              </span>
            </td>
            <td><strong><?= e($row['title']) ?></strong>
              <div class="text-small text-muted"><?= e($row['message'] ?: '') ?></div>
              <span class="badge muted mono text-small"><?= e($row['type']) ?></span></td>
            <td class="text-small"><?= e($row['user_name'] ?: '—') ?></td>
            <td class="nowrap mono text-small"><?= e($row['ip_address'] ?: '—') ?></td>
            <td class="nowrap">
              <span class="badge <?= $row['status'] === 'resolved' ? 'ok' : 'warn' ?>">
                <?= e(match ($row['status']) { 'open' => 'Abierto', 'acknowledged' => 'Reconocido', default => 'Resuelto' }) ?>
              </span>
            </td>
            <td class="nowrap">
              <?php if ($row['status'] !== 'resolved'): ?>
              <form method="post" action="<?= e(url('/seguridad/eventos/' . (int) $row['id'] . '/resolver')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="btn btn--sm" type="submit">Marcar resuelto</button>
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
