<?php $id = (int) $credential['id']; ?>
<div class="page-head">
  <div class="page-head__text">
    <h1>Historial · <?= e($credential['name']) ?></h1>
    <p><?= e($credential['system']['name']) ?> · Usuario: <span class="mono"><?= e($credential['username'] ?: '—') ?></span></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(url('/credenciales/' . $id)) ?>">Volver a la ficha</a>
    <?php if ($auth->can('export.history')): ?>
    <form method="post" action="<?= e(url('/reportes/generar')) ?>">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="type" value="history">
      <input type="hidden" name="ids[]" value="<?= $id ?>">
      <button class="btn" type="submit">Exportar historial</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Versiones de la contrasena</h2></div>
  <div class="card__body card__body--flush">
    <?php if ($versions === []): ?>
      <div class="empty">Sin versiones registradas.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th class="nowrap">Version</th><th class="nowrap">Fecha</th><th>Realizado por</th>
          <th>Motivo</th><th class="nowrap">Robustez</th><th class="nowrap">Estado</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($versions as $row): ?>
          <tr>
            <td class="nowrap">#<?= (int) $row['version'] ?></td>
            <td class="nowrap"><?= e(fecha($row['created_at'], true)) ?></td>
            <td><?= e($row['changed_by_name'] ?: 'Sistema') ?>
                <div class="muted text-small"><?= e($row['changed_by_national_id'] ?? '') ?></div></td>
            <td class="muted"><?= e($row['change_reason'] ?: '—') ?></td>
            <td class="nowrap">
              <?php $score = (int) ($row['strength_score'] ?? 0); ?>
              <div class="bar <?= $score >= 70 ? 'ok' : ($score >= 40 ? 'warn' : 'danger') ?>" style="width:70px">
                <span style="width:<?= $score ?>%"></span>
              </div>
            </td>
            <td class="nowrap">
              <?php if ((int) $row['is_current'] === 1): ?>
                <span class="badge ok">Vigente</span>
              <?php else: ?>
                <span class="badge muted">Historica</span>
              <?php endif; ?>
            </td>
            <td class="nowrap">
              <?php if ((int) $row['is_current'] !== 1 && $auth->can('credentials.secret.history')): ?>
                <span class="secret" data-credential="<?= $id ?>" data-field="password" data-version="<?= (int) $row['version'] ?>" data-revealed="0">
                  <span class="secret__value is-hidden">••••••</span>
                  <span class="secret__timer"></span>
                  <button type="button" class="btn btn--sm" data-secret-toggle>Mostrar</button>
                </span>
              <?php elseif ((int) $row['is_current'] !== 1): ?>
                <span class="text-small text-muted">Requiere permiso especifico</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card__foot text-small text-muted">
      Las contrasenas historicas nunca se muestran automaticamente. Revelarlas exige el permiso
      <strong>Ver contrasena historica</strong>, reautenticacion y genera un registro de auditoria.
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Historial de cambios</h2></div>
  <div class="card__body card__body--flush">
    <?php if ($changes === []): ?>
      <div class="empty">Sin cambios registrados.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th class="nowrap">Fecha y hora</th><th>Accion</th><th>Campo</th>
          <th>Anterior</th><th>Nuevo</th><th>Motivo</th><th>Realizado por</th><th class="nowrap">IP</th>
        </tr></thead>
        <tbody>
        <?php foreach ($changes as $row): ?>
          <tr>
            <td class="nowrap"><?= e(fecha($row['performed_at'], true)) ?></td>
            <td><span class="badge"><?= e($row['action']) ?></span></td>
            <td class="muted"><?= e($row['field_changed'] ?: '—') ?></td>
            <td class="muted truncate"><?= e($row['old_value'] ?? ($row['old_status'] ?? '—')) ?></td>
            <td class="truncate"><?= e($row['new_value'] ?? ($row['new_status'] ?? '—')) ?></td>
            <td class="muted"><?= e($row['reason'] ?: '—') ?></td>
            <td><?= e($row['performed_by_name'] ?: 'Sistema') ?>
                <div class="muted text-small"><?= e($row['performed_by_national_id'] ?? '') ?></div></td>
            <td class="nowrap mono text-small"><?= e($row['ip_address'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($access !== []): ?>
<div class="card">
  <div class="card__head"><h2>Accesos al secreto</h2></div>
  <div class="card__body card__body--flush">
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th class="nowrap">Fecha y hora</th><th>Usuario</th><th>Accion</th><th>Resultado</th><th class="nowrap">IP</th></tr></thead>
        <tbody>
        <?php foreach ($access as $row): ?>
          <tr>
            <td class="nowrap"><?= e(fecha($row['occurred_at'], true)) ?></td>
            <td><?= e($row['user_name'] ?: '—') ?><div class="muted text-small"><?= e($row['actor_national_id'] ?? '') ?></div></td>
            <td><span class="badge info"><?= e($row['access_type']) ?></span></td>
            <td><span class="badge <?= $row['result'] === 'denied' ? 'danger' : 'ok' ?>"><?= e($row['result']) ?></span></td>
            <td class="nowrap mono text-small"><?= e($row['ip_address'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>
