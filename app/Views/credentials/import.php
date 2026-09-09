<div class="page-head">
  <div class="page-head__text">
    <h1>Importar credenciales</h1>
    <p>Carga masiva desde CSV con validacion, previsualizacion y deteccion de duplicados.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(url('/importar/plantilla')) ?>">Descargar plantilla CSV</a>
  </div>
</div>

<div class="alert alert--warn">
  <div>
    <strong>El archivo contiene contrasenas en claro.</strong>
    No se guarda en el servidor: se procesa en memoria y el temporal se elimina de inmediato.
    Borre tambien su copia local en cuanto termine la importacion.
  </div>
</div>

<?php if ($preview === null): ?>
<div class="card">
  <div class="card__head"><h2>1. Cargar archivo</h2></div>
  <form method="post" action="<?= e(url('/importar/previa')) ?>" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="card__body stack">
      <div class="field">
        <label for="archivo">Archivo CSV (maximo 2 MB, 2000 filas)</label>
        <input type="file" id="archivo" name="archivo" accept=".csv,text/csv" required>
      </div>
      <div>
        <div class="text-small text-muted" style="margin-bottom:.3rem">Columnas reconocidas (obligatorias: sistema, credencial, contrasena):</div>
        <div class="row">
          <?php foreach ($columns as $column): ?>
            <span class="badge <?= in_array($column, ['sistema', 'credencial', 'contrasena'], true) ? 'brand' : 'muted' ?>"><?= e($column) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="card__foot row row--end">
      <button class="btn btn--primary" type="submit">Analizar archivo</button>
    </div>
  </form>
</div>

<?php else: ?>

<div class="grid grid--4 mb-1">
  <div class="stat stat--ok"><div class="stat__label">Listas para importar</div><div class="stat__value"><?= (int) $preview['valid'] ?></div></div>
  <div class="stat stat--warn"><div class="stat__label">Duplicadas</div><div class="stat__value"><?= (int) $preview['duplicates'] ?></div></div>
  <div class="stat stat--danger"><div class="stat__label">Con errores</div><div class="stat__value"><?= (int) $preview['invalid'] ?></div></div>
  <div class="stat"><div class="stat__label">Total de filas</div><div class="stat__value"><?= count($preview['rows']) ?></div></div>
</div>

<?php if ($preview['errors'] !== []): ?>
<div class="card">
  <div class="card__head"><h3>Errores detectados</h3></div>
  <div class="card__body">
    <ul class="text-small" style="margin:0;padding-left:1.1rem">
      <?php foreach (array_slice($preview['errors'], 0, 60) as $message): ?><li><?= e($message) ?></li><?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><h2>2. Previsualizacion</h2></div>
  <div class="card__body card__body--flush">
    <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th class="nowrap">Fila</th><th class="nowrap">Estado</th><th>Sistema</th><th>Credencial</th>
          <th>Usuario</th><th>Correo</th><th class="nowrap">Contrasena</th><th>Observaciones</th>
        </tr></thead>
        <tbody>
        <?php foreach (array_slice($preview['rows'], 0, 300) as $row): ?>
          <tr>
            <td class="nowrap"><?= (int) $row['_line'] ?></td>
            <td class="nowrap">
              <span class="badge <?= $row['_status'] === 'ok' ? 'ok' : ($row['_status'] === 'duplicate' ? 'warn' : 'danger') ?>">
                <?= e(match ($row['_status']) { 'ok' => 'Valida', 'duplicate' => 'Duplicada', default => 'Error' }) ?>
              </span>
            </td>
            <td><?= e($row['sistema'] ?? '') ?></td>
            <td><?= e($row['credencial'] ?? '') ?></td>
            <td class="mono text-small"><?= e($row['usuario'] ?? '') ?></td>
            <td class="mono text-small"><?= e($row['correo'] ?? '') ?></td>
            <td class="nowrap mono text-small"><?= e($row['contrasena'] ?? '') ?> <span class="muted">(<?= (int) $row['_password_length'] ?>)</span></td>
            <td class="text-small text-danger"><?= e(implode(' ', $row['_errors'] ?? [])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card__foot row row--end">
    <a class="btn" href="<?= e(url('/importar')) ?>">Cancelar</a>
    <?php if ((int) $preview['valid'] > 0): ?>
    <form method="post" action="<?= e(url('/importar/ejecutar')) ?>"
          data-confirm="Se importaran unicamente las filas validas. Confirme la operacion.">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="payload" value="<?= e($payload ?? '') ?>">
      <button class="btn btn--primary" type="submit">3. Confirmar e importar <?= (int) $preview['valid'] ?> credencial(es)</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
