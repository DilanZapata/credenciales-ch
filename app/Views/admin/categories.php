<div class="page-head">
  <div class="page-head__text">
    <h1>Categorias</h1>
    <p>Clasificacion de los sistemas y credenciales del inventario.</p>
  </div>
  <div class="page-actions"><button class="btn btn--primary" data-modal-open="modal-category" type="button">Nueva categoria</button></div>
</div>

<div class="card">
  <div class="card__body card__body--flush">
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Categoria</th><th>Descripcion</th><th class="nowrap">Sistemas</th><th class="nowrap">Orden</th><th class="nowrap">Estado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($categories as $row): ?>
          <tr>
            <td>
              <span class="dot" style="background:<?= e(preg_match('/^#[0-9a-f]{6}$/i', (string) $row['color']) ? $row['color'] : '#64748b') ?>"></span>
              <strong><?= e($row['name']) ?></strong>
              <div class="muted text-small mono"><?= e($row['slug']) ?></div>
            </td>
            <td class="muted text-small"><?= e($row['description'] ?: '—') ?></td>
            <td class="nowrap"><span class="badge muted"><?= (int) $row['system_count'] ?></span></td>
            <td class="nowrap"><?= (int) $row['sort_order'] ?></td>
            <td class="nowrap"><span class="badge <?= (int) $row['is_active'] === 1 ? 'ok' : 'muted' ?>"><?= (int) $row['is_active'] === 1 ? 'Activa' : 'Inactiva' ?></span></td>
            <td class="nowrap">
              <form method="post" action="<?= e(url('/admin/categorias')) ?>" class="row" style="gap:.25rem">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="name" value="<?= e($row['name']) ?>">
                <input type="hidden" name="description" value="<?= e($row['description'] ?? '') ?>">
                <input type="hidden" name="color" value="<?= e($row['color']) ?>">
                <input type="hidden" name="icon" value="<?= e($row['icon']) ?>">
                <input type="hidden" name="sort_order" value="<?= (int) $row['sort_order'] ?>">
                <input type="hidden" name="is_active" value="<?= (int) $row['is_active'] === 1 ? '0' : '1' ?>">
                <button class="btn btn--sm" type="submit"><?= (int) $row['is_active'] === 1 ? 'Desactivar' : 'Activar' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="modal-category" hidden>
  <div class="modal" role="dialog" aria-modal="true">
    <form method="post" action="<?= e(url('/admin/categorias')) ?>">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="modal__head"><h3>Nueva categoria</h3></div>
      <div class="modal__body stack">
        <div class="field"><label for="cat-name">Nombre *</label>
          <input type="text" id="cat-name" name="name" required maxlength="120"></div>
        <div class="field"><label for="cat-desc">Descripcion</label>
          <input type="text" id="cat-desc" name="description" maxlength="255"></div>
        <div class="field"><label for="cat-color">Color</label>
          <input type="text" id="cat-color" name="color" value="#64748b" maxlength="7" placeholder="#2563eb"></div>
        <div class="field"><label for="cat-order">Orden</label>
          <input type="number" id="cat-order" name="sort_order" value="0" min="0" max="999"></div>
        <input type="hidden" name="is_active" value="1">
      </div>
      <div class="modal__foot">
        <button type="button" class="btn" data-modal-close>Cancelar</button>
        <button type="submit" class="btn btn--primary">Crear categoria</button>
      </div>
    </form>
  </div>
</div>
