<?php
/** @var array $credentialStats @var array $userStats @var array $systemStats @var array $alerts */
$maxSeries = 1;
foreach ($accessSeries as $point) { $maxSeries = max($maxSeries, (int) $point['total']); }
?>
<div class="page-head">
  <div class="page-head__text">
    <h1>Panel de control</h1>
    <p>Estado del inventario de credenciales, accesos y seguridad.</p>
  </div>
  <div class="page-actions">
    <?php if ($auth->can('credentials.create')): ?>
      <a class="btn btn--primary" href="<?= e(url('/credenciales/nueva')) ?>">Nueva credencial</a>
    <?php endif; ?>
    <?php if ($auth->can('reports.view')): ?>
      <a class="btn" href="<?= e(url('/reportes')) ?>">Generar reporte</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($alerts !== []): ?>
<div class="card">
  <div class="card__head"><h2>Alertas</h2><span class="badge warn"><?= count($alerts) ?></span></div>
  <div class="card__body stack">
    <?php foreach ($alerts as $alert): ?>
      <?php $cls = $alert['severity'] === 'critical' ? 'alert--error' : ($alert['severity'] === 'warning' ? 'alert--warn' : 'alert--info'); ?>
      <div class="alert <?= e($cls) ?> mb-0">
        <div style="flex:1">
          <strong><?= e($alert['title']) ?></strong> — <?= e($alert['message']) ?>
        </div>
        <?php if (!empty($alert['link'])): ?>
          <a class="btn btn--sm" href="<?= e(url($alert['link'])) ?>">Revisar</a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="grid grid--4 mb-1">
  <div class="stat">
    <div class="stat__label">Credenciales</div>
    <div class="stat__value"><?= (int) $credentialStats['total'] ?></div>
    <div class="stat__hint"><?= (int) $credentialStats['active'] ?> activas · <?= (int) $credentialStats['inactive'] ?> inactivas</div>
  </div>
  <div class="stat <?= $credentialStats['expired'] > 0 ? 'stat--danger' : '' ?>">
    <div class="stat__label">Vencidas</div>
    <div class="stat__value"><?= (int) $credentialStats['expired'] ?></div>
    <div class="stat__hint"><?= (int) $credentialStats['expiring_soon'] ?> proximas a vencer</div>
  </div>
  <div class="stat <?= $credentialStats['rotation_due'] > 0 ? 'stat--warn' : '' ?>">
    <div class="stat__label">Rotacion pendiente</div>
    <div class="stat__value"><?= (int) $credentialStats['rotation_due'] ?></div>
    <div class="stat__hint"><?= (int) $credentialStats['never_rotated'] ?> nunca actualizadas</div>
  </div>
  <div class="stat">
    <div class="stat__label">Usuarios</div>
    <div class="stat__value"><?= (int) $userStats['total'] ?></div>
    <div class="stat__hint"><?= (int) $userStats['active'] ?> activos · <?= (int) $userStats['with_mfa'] ?> con MFA</div>
  </div>
</div>

<div class="grid grid--4 mb-1">
  <div class="stat <?= $credentialStats['without_owner'] > 0 ? 'stat--warn' : '' ?>">
    <div class="stat__label">Sin responsable</div>
    <div class="stat__value"><?= (int) $credentialStats['without_owner'] ?></div>
    <div class="stat__hint"><?= (int) $systemStats['without_owner'] ?> sistemas sin responsable</div>
  </div>
  <div class="stat">
    <div class="stat__label">Sin usuarios asignados</div>
    <div class="stat__value"><?= (int) $credentialStats['without_assignments'] ?></div>
    <div class="stat__hint"><?= (int) $systemStats['without_credentials'] ?> sistemas sin credenciales</div>
  </div>
  <div class="stat">
    <div class="stat__label">Sesiones activas</div>
    <div class="stat__value"><?= (int) $activeSessions ?></div>
    <div class="stat__hint"><?= (int) $userStats['locked'] ?> cuentas bloqueadas</div>
  </div>
  <div class="stat <?= $failedLogins24h > 10 ? 'stat--danger' : '' ?>">
    <div class="stat__label">Accesos fallidos (24 h)</div>
    <div class="stat__value"><?= (int) $failedLogins24h ?></div>
    <div class="stat__hint">Intentos de inicio de sesion</div>
  </div>
</div>

<div class="grid grid--sidebar">
  <div>
    <div class="card">
      <div class="card__head">
        <h2>Proximas a vencer o rotar</h2>
        <span class="spacer"></span>
        <a class="btn btn--sm" href="<?= e(url('/credenciales?expired=1')) ?>">Ver todas</a>
      </div>
      <div class="card__body card__body--flush">
        <?php if ($expiring === []): ?>
          <div class="empty"><strong>Todo al dia</strong>No hay credenciales proximas a vencer.</div>
        <?php else: ?>
        <div class="table-wrap">
          <table class="data">
            <thead><tr>
              <th>Credencial</th><th>Sistema</th><th>Categoria</th><th class="nowrap">Vence / rota</th><th class="nowrap">Dias</th>
            </tr></thead>
            <tbody>
            <?php foreach ($expiring as $row): ?>
              <?php $days = $row['days_left'] === null ? null : (int) $row['days_left']; ?>
              <tr>
                <td><a href="<?= e(url('/credenciales/' . (int) $row['id'])) ?>"><?= e($row['name']) ?></a></td>
                <td class="muted"><?= e($row['system_name']) ?></td>
                <td class="muted"><?= e($row['category_name'] ?? '—') ?></td>
                <td class="nowrap"><?= e(fecha($row['expires_at'] ?? $row['next_rotation_at'])) ?></td>
                <td class="nowrap">
                  <span class="badge <?= $days !== null && $days < 0 ? 'danger' : ($days !== null && $days <= 7 ? 'warn' : 'muted') ?>">
                    <?= $days === null ? '—' : ($days < 0 ? abs($days) . ' vencida' : $days . ' d') ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($recentSecrets !== []): ?>
    <div class="card">
      <div class="card__head"><h2>Ultimas consultas de contrasenas</h2>
        <span class="spacer"></span>
        <a class="btn btn--sm" href="<?= e(url('/auditoria?action_group=secret')) ?>">Auditoria</a>
      </div>
      <div class="card__body card__body--flush">
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>Fecha</th><th>Usuario</th><th>Credencial</th><th>Accion</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($recentSecrets as $row): ?>
              <tr>
                <td class="nowrap"><?= e(fecha($row['occurred_at'], true)) ?></td>
                <td><?= e($row['user_name'] ?: '—') ?><br><span class="muted text-small"><?= e($row['national_id'] ?? '') ?></span></td>
                <td><?= e($row['credential_name']) ?><br><span class="muted text-small"><?= e($row['system_name']) ?></span></td>
                <td><span class="badge <?= $row['access_type'] === 'export' ? 'danger' : 'info' ?>">
                  <?= e(match ($row['access_type']) {
                        'view' => 'Visualizada', 'copy' => 'Copiada', 'export' => 'Exportada',
                        'history_view' => 'Historica', default => (string) $row['access_type'] }) ?>
                </span></td>
                <td class="muted mono text-small"><?= e($row['ip_address']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($recentChanges !== []): ?>
    <div class="card">
      <div class="card__head"><h2>Ultimos cambios</h2></div>
      <div class="card__body card__body--flush">
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>Fecha</th><th>Credencial</th><th>Accion</th><th>Motivo</th><th>Responsable</th></tr></thead>
            <tbody>
            <?php foreach ($recentChanges as $row): ?>
              <tr>
                <td class="nowrap"><?= e(fecha($row['performed_at'], true)) ?></td>
                <td><?= e($row['credential_name']) ?><br><span class="muted text-small"><?= e($row['system_name']) ?></span></td>
                <td><span class="badge"><?= e($row['action']) ?></span></td>
                <td class="muted"><?= e($row['reason'] ?: '—') ?></td>
                <td class="muted"><?= e($row['performed_by_name'] ?: 'Sistema') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="card">
      <div class="card__head"><h3>Accesos a secretos (14 dias)</h3></div>
      <div class="card__body">
        <div class="chart">
          <?php foreach ($accessSeries as $point): ?>
            <div title="<?= e($point['day'] . ': ' . $point['total']) ?>"
                 style="height:<?= (int) max(4, ((int) $point['total'] / $maxSeries) * 100) ?>%"></div>
          <?php endforeach; ?>
          <?php if ($accessSeries === []): ?><div style="height:4%"></div><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h3>Credenciales por categoria</h3></div>
      <div class="card__body stack">
        <?php
          $totalCat = 0;
          foreach ($byCategory as $row) { $totalCat += (int) $row['total']; }
          $totalCat = max(1, $totalCat);
        ?>
        <?php foreach ($byCategory as $row): ?>
          <div>
            <div class="row" style="justify-content:space-between">
              <span class="text-small"><?= e($row['name']) ?></span>
              <span class="text-small text-muted"><?= (int) $row['total'] ?></span>
            </div>
            <div class="bar"><span style="width:<?= (int) (((int) $row['total'] / $totalCat) * 100) ?>%;background:<?= e(preg_match('/^#[0-9a-f]{6}$/i', (string) $row['color']) ? $row['color'] : '#64748b') ?>"></span></div>
          </div>
        <?php endforeach; ?>
        <?php if ($byCategory === []): ?><p class="text-muted mb-0">Sin datos.</p><?php endif; ?>
      </div>
    </div>

    <?php if ($securityEvents !== []): ?>
    <div class="card">
      <div class="card__head"><h3>Eventos de seguridad</h3></div>
      <div class="card__body stack">
        <?php foreach ($securityEvents as $event): ?>
          <div>
            <span class="badge <?= e(match ($event['severity']) { 'critical','high' => 'danger', 'medium' => 'warn', default => 'muted' }) ?>">
              <?= e($event['severity']) ?>
            </span>
            <strong class="text-small"><?= e($event['title']) ?></strong>
            <div class="text-small text-muted"><?= e($event['message'] ?: '') ?></div>
            <div class="text-small text-muted"><?= e(desde($event['created_at'])) ?></div>
          </div>
        <?php endforeach; ?>
        <a class="btn btn--sm btn--block" href="<?= e(url('/seguridad/eventos')) ?>">Ver todos</a>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($topUsers !== []): ?>
    <div class="card">
      <div class="card__head"><h3>Usuarios con mas accesos</h3></div>
      <div class="card__body card__body--flush">
        <table class="data">
          <tbody>
          <?php foreach ($topUsers as $row): ?>
            <tr>
              <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?><br>
                  <span class="muted text-small"><?= e($row['national_id']) ?></span></td>
              <td class="nowrap" style="text-align:right"><span class="badge brand"><?= (int) $row['total'] ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($recentExports !== []): ?>
    <div class="card">
      <div class="card__head"><h3>Ultimas exportaciones</h3></div>
      <div class="card__body card__body--flush">
        <table class="data">
          <tbody>
          <?php foreach ($recentExports as $row): ?>
            <tr>
              <td>
                <?= e($row['user_name'] ?: '—') ?>
                <br><span class="muted text-small"><?= e(fecha($row['created_at'], true)) ?> · <?= (int) $row['record_count'] ?> reg.</span>
              </td>
              <td class="nowrap" style="text-align:right">
                <?php if ((int) $row['included_secrets'] === 1): ?>
                  <span class="badge danger">Con contrasenas</span>
                <?php else: ?>
                  <span class="badge muted">Sin contrasenas</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
