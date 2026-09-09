<div class="page-head">
  <div class="page-head__text">
    <h1>Sesiones</h1>
    <p>Sesiones abiertas en el sistema. Puede cerrarlas remotamente cuando sea necesario.</p>
  </div>
</div>

<div class="card">
  <div class="card__body">
    <form method="get" action="<?= e(url('/sesiones')) ?>" class="filters">
      <div class="field">
        <label for="q">Busqueda</label>
        <input type="search" id="q" name="q" value="<?= e($filters['search'] ?? '') ?>" placeholder="Usuario, cedula o IP…">
      </div>
      <div class="field">
        <label for="status">Estado</label>
        <select id="status" name="status">
          <?php foreach (['active' => 'Activas', 'expired' => 'Expiradas', 'revoked' => 'Revocadas', '' => 'Todas'] as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= ($filters['status'] ?? 'active') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>&nbsp;</label>
        <button class="btn btn--primary" type="submit">Filtrar</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--flush">
    <?php if ($sessions === []): ?>
      <div class="empty"><strong>Sin sesiones</strong>No hay sesiones que coincidan con el filtro.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th>Usuario</th><th>Cedula</th><th class="nowrap">IP</th><th>Dispositivo</th>
          <th class="nowrap">Inicio</th><th class="nowrap">Ultima actividad</th><th class="nowrap">MFA</th><th class="nowrap">Estado</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($sessions as $row): ?>
          <tr>
            <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?>
              <div class="muted text-small"><?= e($row['username']) ?></div>
              <?php if ($row['id'] === $currentId): ?><span class="badge ok">Esta sesion</span><?php endif; ?>
            </td>
            <td class="mono text-small"><?= e($row['national_id']) ?></td>
            <td class="nowrap mono text-small"><?= e($row['ip_address'] ?: '—') ?></td>
            <td class="text-small"><?= e($row['device'] ?: 'Desconocido') ?></td>
            <td class="nowrap text-small"><?= e(fecha($row['created_at'], true)) ?></td>
            <td class="nowrap text-small"><?= e(desde($row['last_activity_at'])) ?></td>
            <td class="nowrap"><span class="badge <?= (int) $row['mfa_verified'] === 1 ? 'ok' : 'muted' ?>"><?= (int) $row['mfa_verified'] === 1 ? 'Si' : 'No' ?></span></td>
            <td class="nowrap">
              <span class="badge <?= e(match ($row['status']) { 'active' => 'ok', 'revoked' => 'danger', default => 'muted' }) ?>">
                <?= e(match ($row['status']) { 'active' => 'Activa', 'revoked' => 'Revocada', default => 'Expirada' }) ?>
              </span>
            </td>
            <td class="nowrap">
              <?php if ($row['status'] === 'active' && $row['id'] !== $currentId && $auth->can('sessions.revoke')): ?>
              <form method="post" action="<?= e(url('/sesiones/' . $row['id'] . '/cerrar')) ?>"
                    data-confirm="Se cerrara la sesion de este usuario de inmediato.">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="reason" value="Cierre remoto desde el panel de sesiones">
                <button class="btn btn--sm btn--danger" type="submit">Cerrar</button>
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
