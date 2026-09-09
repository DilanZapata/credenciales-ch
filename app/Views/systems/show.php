<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($system['name']) ?></h1>
    <p><?= e(tipoRecurso($system['resource_type'])) ?> · <?= e($system['category_name'] ?? 'Sin categoria') ?></p>
  </div>
  <div class="page-actions">
    <?php if ($auth->can('systems.update')): ?>
      <a class="btn" href="<?= e(url('/sistemas/' . (int) $system['id'] . '/editar')) ?>">Editar</a>
    <?php endif; ?>
    <?php if ($auth->can('credentials.view')): ?>
      <a class="btn btn--primary" href="<?= e(url('/credenciales?system_id=' . (int) $system['id'])) ?>">
        Ver credenciales (<?= (int) $system['credential_count'] ?>)
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid--2">
  <div class="card">
    <div class="card__head"><h2>Datos tecnicos</h2></div>
    <div class="card__body">
      <dl class="dl">
        <dt>Nombre descriptivo</dt><dd><?= e($system['display_name'] ?: '—') ?></dd>
        <dt>Codigo</dt><dd class="mono"><?= e($system['code'] ?: '—') ?></dd>
        <dt>URL</dt><dd><?php if ($system['url']): ?><a href="<?= e($system['url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= e($system['url']) ?></a><?php else: ?>—<?php endif; ?></dd>
        <dt>Direccion IP</dt><dd class="mono"><?= e($system['ip_address'] ?: '—') ?></dd>
        <dt>Puerto</dt><dd class="mono"><?= e($system['port'] !== null ? (string) $system['port'] : '—') ?></dd>
        <dt>Servidor</dt><dd class="mono"><?= e($system['hostname'] ?: '—') ?></dd>
        <dt>Plataforma</dt><dd><?= e($system['platform'] ?: '—') ?></dd>
        <dt>Proveedor</dt><dd><?= e($system['provider'] ?: '—') ?></dd>
        <dt>Criticidad</dt><dd><?= e(match ($system['criticality']) { 'critical' => 'Critica', 'high' => 'Alta', 'medium' => 'Media', default => 'Baja' }) ?></dd>
        <dt>Estado</dt><dd><?= e($system['status']) ?></dd>
      </dl>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2>Ubicacion y responsabilidad</h2></div>
    <div class="card__body">
      <dl class="dl">
        <dt>Empresa</dt><dd><?= e($system['company_name'] ?: '—') ?></dd>
        <dt>Sede</dt><dd><?= e($system['location_name'] ?: '—') ?></dd>
        <dt>Departamento</dt><dd><?= e($system['department_name'] ?: '—') ?></dd>
        <dt>Responsable</dt>
        <dd>
          <?php if ($system['owner_name']): ?>
            <?= e($system['owner_name']) ?> <span class="muted text-small">(<?= e($system['owner_national_id'] ?? '') ?>)</span>
          <?php else: ?>
            <span class="badge warn">Sin responsable</span>
          <?php endif; ?>
        </dd>
        <dt>Creado</dt><dd><?= e(fecha($system['created_at'], true)) ?></dd>
        <dt>Actualizado</dt><dd><?= e(fecha($system['updated_at'], true)) ?></dd>
      </dl>
      <?php if (!empty($system['description'])): ?>
        <hr><p class="mb-0" style="white-space:pre-wrap"><?= e($system['description']) ?></p>
      <?php endif; ?>
    </div>
    <?php if ($auth->can('systems.delete') && $system['status'] !== 'archived'): ?>
    <div class="card__foot row row--end">
      <form method="post" action="<?= e(url('/sistemas/' . (int) $system['id'] . '/archivar')) ?>"
            data-confirm="Al archivar el sistema dejara de aparecer en los listados activos. Confirme.">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="reason" value="Archivado desde la ficha del sistema">
        <button class="btn btn--danger btn--sm" type="submit">Archivar sistema</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
