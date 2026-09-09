<div class="page-head">
  <div class="page-head__text">
    <h1>Reportes</h1>
    <p>Genere inventarios y reportes en Excel. Cada exportacion queda registrada en la auditoria.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(url('/reportes/historial')) ?>">Historial de exportaciones</a>
  </div>
</div>

<div class="grid grid--2">
  <!-- ------------------- Reporte de inventario ------------------- -->
  <div class="card">
    <div class="card__head"><h2>Reporte de inventario</h2><span class="badge ok">Sin contrasenas</span></div>
    <form method="post" action="<?= e(url('/reportes/generar')) ?>">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="type" value="inventory">
      <div class="card__body stack">
        <p class="text-small text-muted mb-0">
          Que credenciales existen y su informacion administrativa: sistema, categoria, ubicacion,
          responsable, fechas de rotacion y vencimiento. Nunca incluye secretos.
        </p>
        <div class="form-grid">
          <div class="field">
            <label for="inv_category">Categoria</label>
            <select id="inv_category" name="category_id"><option value="">Todas</option>
              <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="inv_system">Sistema</label>
            <select id="inv_system" name="system_id"><option value="">Todos</option>
              <?php foreach ($systems as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="inv_company">Empresa</label>
            <select id="inv_company" name="company_id"><option value="">Todas</option>
              <?php foreach ($companies as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="inv_location">Sede</label>
            <select id="inv_location" name="location_id"><option value="">Todas</option>
              <?php foreach ($locations as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="inv_department">Departamento</label>
            <select id="inv_department" name="department_id"><option value="">Todos</option>
              <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="inv_user">Asignadas al usuario</label>
            <select id="inv_user" name="assigned_user_id"><option value="">Todos</option>
              <?php foreach ($usersList as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['label']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="row">
          <label class="check"><input type="checkbox" name="expired" value="1"> Solo vencidas o con rotacion pendiente</label>
          <label class="check"><input type="checkbox" name="without_owner" value="1"> Solo sin responsable</label>
          <label class="check"><input type="checkbox" name="without_assignments" value="1"> Solo sin usuarios asignados</label>
        </div>
      </div>
      <div class="card__foot row row--end">
        <button class="btn btn--primary" type="submit">Generar Excel</button>
      </div>
    </form>
  </div>

  <!-- ---------------- Reporte de credenciales completas ---------- -->
  <div class="card">
    <div class="card__head"><h2>Reporte de credenciales completas</h2>
      <?php if ($canExportSecrets): ?><span class="badge danger">Puede incluir contrasenas</span><?php endif; ?>
    </div>
    <form method="post" action="<?= e(url('/reportes/generar')) ?>">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="type" value="full_credentials">
      <div class="card__body stack">
        <p class="text-small text-muted mb-0">
          Incluye usuario, correo, dominio, informacion de recuperacion y toda la ficha.
          Las contrasenas solo se incluyen si lo solicita expresamente y tiene el permiso correspondiente.
        </p>
        <div class="form-grid">
          <div class="field">
            <label for="full_category">Categoria</label>
            <select id="full_category" name="category_id"><option value="">Todas</option>
              <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="full_system">Sistema</label>
            <select id="full_system" name="system_id"><option value="">Todos</option>
              <?php foreach ($systems as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="full_department">Departamento</label>
            <select id="full_department" name="department_id"><option value="">Todos</option>
              <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="full_user">Asignadas al usuario</label>
            <select id="full_user" name="assigned_user_id"><option value="">Todos</option>
              <?php foreach ($usersList as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['label']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>

        <?php if ($canExportSecrets): ?>
        <hr>
        <label class="check">
          <input type="checkbox" id="include_secrets" name="include_secrets" value="1">
          <span><strong>Incluir contrasenas reales en el archivo</strong>
            <div class="hint">Por defecto NO se incluyen. Requiere confirmacion y reautenticacion.</div>
          </span>
        </label>

        <div id="export-warning" hidden>
          <div class="confidential">
            ⚠️ ADVERTENCIA<br><br>
            El archivo que esta generando contendra <strong>informacion confidencial y contrasenas reales</strong>.
            Esta informacion debe almacenarse y compartirse de manera segura.<br><br>
            La operacion quedara registrada en la auditoria con su usuario, fecha, hora, IP y el detalle
            de cada credencial incluida.
          </div>
          <label class="check mt-1">
            <input type="checkbox" id="export-confirm" name="confirm" value="1">
            He leido la advertencia y deseo continuar generando el archivo con contrasenas.
          </label>
        </div>
        <?php else: ?>
        <div class="alert alert--info mb-0">
          <div>No tiene el permiso <span class="mono">export.credentials.secrets</span>, por lo que el archivo se generara sin contrasenas.</div>
        </div>
        <?php endif; ?>
      </div>
      <div class="card__foot row row--end">
        <button class="btn btn--primary" type="submit">Generar Excel</button>
      </div>
    </form>
  </div>

  <!-- --------------------- Reporte de historial ------------------ -->
  <?php if ($canExportHistory): ?>
  <div class="card">
    <div class="card__head"><h2>Reporte de historial</h2><span class="badge ok">Sin contrasenas antiguas</span></div>
    <form method="post" action="<?= e(url('/reportes/generar')) ?>">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="type" value="history">
      <div class="card__body stack">
        <p class="text-small text-muted mb-0">
          Cambios de contrasena, de estado, asignaciones y revocaciones. Las contrasenas historicas
          permanecen ocultas en el reporte.
        </p>
        <div class="form-grid">
          <div class="field">
            <label for="hist_system">Sistema</label>
            <select id="hist_system" name="system_id"><option value="">Todos</option>
              <?php foreach ($systems as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="hist_user">Realizado por</label>
            <select id="hist_user" name="user_id"><option value="">Todos</option>
              <?php foreach ($usersList as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['label']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="hist_from">Desde</label>
            <input type="date" id="hist_from" name="date_from">
          </div>
          <div class="field">
            <label for="hist_to">Hasta</label>
            <input type="date" id="hist_to" name="date_to">
          </div>
          <div class="field">
            <label for="hist_action">Tipo de modificacion</label>
            <select id="hist_action" name="action"><option value="">Todas</option>
              <?php foreach (['created' => 'Creacion', 'updated' => 'Actualizacion', 'password_rotated' => 'Cambio de contrasena',
                              'status_changed' => 'Cambio de estado', 'assigned' => 'Asignacion', 'revoked' => 'Revocacion',
                              'deleted' => 'Baja', 'restored' => 'Reactivacion'] as $k => $label): ?>
                <option value="<?= e($k) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="card__foot row row--end">
        <button class="btn btn--primary" type="submit">Generar Excel</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <!-- --------------------- Reporte de auditoria ------------------ -->
  <?php if ($canExportAudit): ?>
  <div class="card">
    <div class="card__head"><h2>Reporte de auditoria</h2></div>
    <form method="post" action="<?= e(url('/reportes/generar')) ?>">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="type" value="audit">
      <div class="card__body stack">
        <p class="text-small text-muted mb-0">Registro completo de eventos, filtrable por fecha, usuario y resultado.</p>
        <div class="form-grid">
          <div class="field"><label for="aud_from">Desde</label><input type="date" id="aud_from" name="date_from"></div>
          <div class="field"><label for="aud_to">Hasta</label><input type="date" id="aud_to" name="date_to"></div>
          <div class="field">
            <label for="aud_user">Usuario</label>
            <select id="aud_user" name="user_id"><option value="">Todos</option>
              <?php foreach ($usersList as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['label']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="aud_result">Resultado</label>
            <select id="aud_result" name="result"><option value="">Todos</option>
              <option value="success">Exitoso</option><option value="failure">Fallido</option><option value="denied">Denegado</option>
            </select>
          </div>
        </div>
      </div>
      <div class="card__foot row row--end">
        <button class="btn btn--primary" type="submit">Generar Excel</button>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card__head"><h3>Proteccion de los archivos generados</h3></div>
  <div class="card__body">
    <ul class="text-small" style="margin:0;padding-left:1.1rem">
      <li>El archivo se escribe fuera del directorio publico: no existe URL directa que lo exponga.</li>
      <li>Se elimina del servidor en cuanto lo descarga y, en todo caso, al expirar (minutos).</li>
      <li>El nombre del archivo nunca revela contenido sensible.</li>
      <li>Solo puede descargarlo el usuario que lo genero.</li>
      <li>Cada generacion y cada descarga se registran con usuario, fecha, hora, IP y filtros aplicados.</li>
    </ul>
  </div>
</div>
