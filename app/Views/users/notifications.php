<div class="page-head">
  <div class="page-head__text">
    <h1>Notificaciones</h1>
    <p>Alertas del sistema dirigidas a usted o a su rol.</p>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--flush">
    <?php if ($notifications === []): ?>
      <div class="empty"><strong>Sin notificaciones</strong>No hay alertas pendientes.</div>
    <?php else: ?>
    <table class="data">
      <tbody>
      <?php foreach ($notifications as $row): ?>
        <tr>
          <td style="width:110px" class="nowrap">
            <span class="badge <?= e(match ($row['severity']) { 'critical' => 'danger', 'warning' => 'warn', default => 'info' }) ?>">
              <?= e($row['severity']) ?>
            </span>
          </td>
          <td>
            <strong><?= e($row['title']) ?></strong>
            <div class="text-small text-muted"><?= e($row['message'] ?: '') ?></div>
            <div class="text-small text-muted"><?= e(fecha($row['created_at'], true)) ?></div>
          </td>
          <td class="nowrap" style="text-align:right">
            <?php if (!empty($row['link'])): ?>
              <a class="btn btn--sm" href="<?= e(url($row['link'])) ?>">Revisar</a>
            <?php endif; ?>
            <?php if ((int) $row['is_read'] === 0): ?>
              <form method="post" action="<?= e(url('/notificaciones/' . (int) $row['id'] . '/leida')) ?>" style="display:inline">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="btn btn--sm btn--ghost" type="submit">Marcar leida</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
