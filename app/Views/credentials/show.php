<?php
/** @var array $credential */
$id       = (int) $credential['id'];
$estado   = estadoCredencial((string) $credential['status']);
$rotacion = estadoRotacion((string) $credential['rotation_state']);
?>
<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($credential['name']) ?></h1>
    <p>
      <?= e($credential['system']['name']) ?> ·
      <span class="badge <?= e($estado['class']) ?>"><?= e($estado['label']) ?></span>
      <span class="badge <?= e($rotacion['class']) ?>"><?= e($rotacion['label']) ?></span>
    </p>
  </div>
  <div class="page-actions">
    <?php if ($auth->can('credentials.update')): ?>
      <a class="btn" href="<?= e(url('/credenciales/' . $id . '/editar')) ?>">Editar</a>
    <?php endif; ?>
    <?php if ($auth->can('credentials.rotate')): ?>
      <button type="button" class="btn btn--primary" data-modal-open="modal-rotate">Actualizar contrasena</button>
    <?php endif; ?>
    <?php if ($auth->can('history.view')): ?>
      <a class="btn" href="<?= e(url('/credenciales/' . $id . '/historial')) ?>">Historial</a>
    <?php endif; ?>
    <?php if ($auth->can('credentials.delete')): ?>
      <button type="button" class="btn btn--danger" data-modal-open="modal-delete">Dar de baja</button>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid--sidebar">
  <div>
    <!-- ------------------------- Acceso ------------------------- -->
    <div class="card">
      <div class="card__head"><h2>Datos de acceso</h2></div>
      <div class="card__body stack">
        <dl class="dl">
          <dt>Usuario</dt>
          <dd class="mono"><?= e($credential['username'] ?: '—') ?></dd>
          <dt>Correo electronico</dt>
          <dd class="mono"><?= e($credential['email'] ?: '—') ?></dd>
          <dt>Dominio</dt>
          <dd class="mono"><?= e($credential['domain'] ?: '—') ?></dd>
          <dt>Usuario administrador</dt>
          <dd class="mono"><?= e($credential['admin_username'] ?: '—') ?></dd>
          <dt>Metodo de autenticacion</dt>
          <dd><?= e($credential['auth_method'] ?: '—') ?></dd>
          <dt>Entorno</dt>
          <dd><?= e($credential['environment']) ?></dd>
        </dl>

        <div>
          <div class="text-small text-muted" style="margin-bottom:.25rem">Contrasena</div>
          <?php if ($auth->canAny('credentials.secret.view', 'credentials.secret.copy')): ?>
            <div class="secret" data-credential="<?= $id ?>" data-field="password" data-revealed="0">
              <span class="secret__value is-hidden">••••••••••••</span>
              <span class="secret__timer"></span>
              <?php if ($auth->can('credentials.secret.view')): ?>
                <button type="button" class="btn btn--sm" data-secret-toggle>Mostrar</button>
              <?php endif; ?>
              <?php if ($auth->can('credentials.secret.copy')): ?>
                <button type="button" class="btn btn--sm" data-secret-copy>Copiar</button>
              <?php endif; ?>
            </div>
            <p class="text-small text-muted mt-1 mb-0">
              Mostrar o copiar la contrasena exige confirmar su identidad y queda registrado
              en la auditoria con su usuario, fecha, hora e IP.
            </p>
          <?php else: ?>
            <p class="text-small text-muted mb-0">No tiene autorizacion para visualizar este secreto.</p>
          <?php endif; ?>
        </div>

        <?php if (!empty($credential['has_recovery_info'])): ?>
        <div>
          <div class="text-small text-muted" style="margin-bottom:.25rem">Informacion de recuperacion</div>
          <?php if ($auth->can('credentials.recovery.view')): ?>
            <button type="button" class="btn btn--sm" data-recovery="<?= $id ?>">Ver informacion de recuperacion</button>
            <div id="recovery-info" class="mt-1" hidden></div>
          <?php else: ?>
            <p class="text-small text-muted mb-0">Existe informacion de recuperacion, pero no tiene permiso para verla.</p>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ------------------------ Sistema ------------------------- -->
    <div class="card">
      <div class="card__head"><h2>Sistema y ubicacion</h2></div>
      <div class="card__body">
        <dl class="dl">
          <dt>Sistema</dt>
          <dd>
            <?php if ($auth->can('systems.view')): ?>
              <a href="<?= e(url('/sistemas/' . (int) $credential['system']['id'])) ?>"><?= e($credential['system']['name']) ?></a>
            <?php else: ?><?= e($credential['system']['name']) ?><?php endif; ?>
          </dd>
          <dt>Tipo de recurso</dt><dd><?= e(tipoRecurso($credential['system']['resource_type'])) ?></dd>
          <dt>Categoria</dt><dd><?= e($credential['category']['name'] ?? '—') ?></dd>
          <dt>URL</dt>
          <dd><?php if (!empty($credential['system']['url'])): ?>
            <a href="<?= e($credential['system']['url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= e($credential['system']['url']) ?></a>
          <?php else: ?>—<?php endif; ?></dd>
          <dt>Direccion IP</dt><dd class="mono"><?= e($credential['system']['ip_address'] ?: '—') ?></dd>
          <dt>Puerto</dt><dd class="mono"><?= e($credential['system']['port'] !== null ? (string) $credential['system']['port'] : '—') ?></dd>
          <dt>Servidor</dt><dd class="mono"><?= e($credential['system']['hostname'] ?: '—') ?></dd>
          <dt>Plataforma</dt><dd><?= e($credential['system']['platform'] ?: '—') ?></dd>
          <dt>Proveedor</dt><dd><?= e($credential['system']['provider'] ?: '—') ?></dd>
          <dt>Empresa</dt><dd><?= e($credential['organization']['company'] ?: '—') ?></dd>
          <dt>Sede</dt><dd><?= e($credential['organization']['location'] ?: '—') ?></dd>
          <dt>Departamento</dt><dd><?= e($credential['organization']['department'] ?: '—') ?></dd>
        </dl>
      </div>
    </div>

    <?php if (!empty($credential['observations'])): ?>
    <div class="card">
      <div class="card__head"><h2>Observaciones</h2></div>
      <div class="card__body"><p class="mb-0" style="white-space:pre-wrap"><?= e($credential['observations']) ?></p></div>
    </div>
    <?php endif; ?>

    <!-- ---------------------- Asignaciones ---------------------- -->
    <?php if ($assignments !== [] || $auth->can('credentials.assign')): ?>
    <div class="card">
      <div class="card__head">
        <h2>Usuarios con acceso</h2>
        <span class="spacer"></span>
        <?php if ($auth->can('credentials.assign')): ?>
          <button type="button" class="btn btn--sm btn--primary" data-modal-open="modal-assign">Asignar usuario</button>
        <?php endif; ?>
      </div>
      <div class="card__body card__body--flush">
        <?php if ($assignments === []): ?>
          <div class="empty"><strong>Sin asignaciones</strong>Ningun usuario tiene acceso a esta credencial.</div>
        <?php else: ?>
        <div class="table-wrap">
          <table class="data">
            <thead><tr>
              <th>Usuario</th><th>Cedula</th><th>Permisos</th><th class="nowrap">Otorgado</th><th class="nowrap">Estado</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($assignments as $row): ?>
              <tr>
                <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?>
                    <div class="muted text-small"><?= e($row['username']) ?></div></td>
                <td class="mono text-small"><?= e($row['national_id']) ?></td>
                <td>
                  <?php if ((int) $row['can_view_secret'] === 1): ?><span class="badge info">Ver</span><?php endif; ?>
                  <?php if ((int) $row['can_copy_secret'] === 1): ?><span class="badge info">Copiar</span><?php endif; ?>
                  <?php if ((int) $row['can_view_recovery'] === 1): ?><span class="badge warn">Recuperacion</span><?php endif; ?>
                </td>
                <td class="nowrap text-small"><?= e(fecha($row['granted_at'])) ?><div class="muted"><?= e($row['granted_by_name'] ?: '') ?></div></td>
                <td class="nowrap">
                  <?php if ((int) $row['is_active'] === 1): ?>
                    <span class="badge ok">Activo</span>
                  <?php else: ?>
                    <span class="badge muted">Revocado</span>
                    <div class="muted text-small"><?= e(fecha($row['revoked_at'])) ?></div>
                  <?php endif; ?>
                </td>
                <td class="nowrap">
                  <?php if ((int) $row['is_active'] === 1 && $auth->can('credentials.revoke')): ?>
                  <form method="post" action="<?= e(url('/credenciales/' . $id . '/revocar/' . (int) $row['user_id'])) ?>"
                        data-confirm="Va a revocar el acceso de este usuario. Confirme la operacion.">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="reason" value="Revocacion manual desde la ficha">
                    <button class="btn btn--sm btn--danger" type="submit">Revocar</button>
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
    <?php endif; ?>
  </div>

  <!-- ------------------------- Columna lateral -------------------- -->
  <div>
    <div class="card">
      <div class="card__head"><h3>Ciclo de vida</h3></div>
      <div class="card__body">
        <dl class="dl" style="grid-template-columns:1fr 1fr">
          <dt>Responsable</dt><dd><?= e($credential['owner_name'] ?: '— sin asignar —') ?></dd>
          <dt>Creada</dt><dd><?= e(fecha($credential['created_at'], true)) ?></dd>
          <dt>Ultima actualizacion</dt><dd><?= e(fecha($credential['updated_at'], true)) ?></dd>
          <dt>Ultimo cambio de contrasena</dt><dd><?= e(fecha($credential['password_changed_at'], true)) ?></dd>
          <dt>Proxima rotacion</dt><dd><?= e(fecha($credential['next_rotation_at'])) ?></dd>
          <dt>Vencimiento</dt><dd><?= e(fecha($credential['expires_at'])) ?></dd>
          <dt>Periodo de rotacion</dt><dd><?= $credential['rotation_period_days'] !== null ? (int) $credential['rotation_period_days'] . ' dias' : '—' ?></dd>
          <dt>Versiones de secreto</dt><dd><?= (int) $credential['secret_count'] ?></dd>
          <dt>Creada por</dt><dd><?= e($credential['created_by_username'] ?: '—') ?></dd>
        </dl>
      </div>
    </div>

    <?php if ($history !== []): ?>
    <div class="card">
      <div class="card__head"><h3>Cambios recientes</h3>
        <span class="spacer"></span>
        <a class="btn btn--sm" href="<?= e(url('/credenciales/' . $id . '/historial')) ?>">Ver todo</a>
      </div>
      <div class="card__body stack">
        <?php foreach ($history as $row): ?>
          <div>
            <span class="badge"><?= e($row['action']) ?></span>
            <span class="text-small text-muted"><?= e(fecha($row['performed_at'], true)) ?></span>
            <div class="text-small"><?= e($row['reason'] ?: '—') ?></div>
            <div class="text-small text-muted"><?= e($row['performed_by_name'] ?: 'Sistema') ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($accessTrace !== []): ?>
    <div class="card">
      <div class="card__head"><h3>Quien consulto este secreto</h3></div>
      <div class="card__body card__body--flush">
        <table class="data">
          <tbody>
          <?php foreach ($accessTrace as $row): ?>
            <tr>
              <td>
                <?= e($row['user_name'] ?: '—') ?>
                <div class="muted text-small"><?= e(fecha($row['occurred_at'], true)) ?> · <?= e($row['ip_address']) ?></div>
              </td>
              <td class="nowrap" style="text-align:right">
                <span class="badge <?= $row['result'] === 'denied' ? 'danger' : 'info' ?>">
                  <?= e(match ($row['access_type']) {
                        'view' => 'Vista', 'copy' => 'Copiada', 'export' => 'Exportada',
                        'history_view' => 'Historica', default => (string) $row['access_type'] }) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($exportTrace !== []): ?>
    <div class="card">
      <div class="card__head"><h3>Exportaciones que la incluyeron</h3></div>
      <div class="card__body card__body--flush">
        <table class="data">
          <tbody>
          <?php foreach ($exportTrace as $row): ?>
            <tr>
              <td><?= e($row['user_name'] ?: '—') ?>
                  <div class="muted text-small"><?= e(fecha($row['created_at'], true)) ?></div></td>
              <td class="nowrap" style="text-align:right">
                <span class="badge <?= (int) $row['included_secrets'] === 1 ? 'danger' : 'muted' ?>">
                  <?= (int) $row['included_secrets'] === 1 ? 'Con contrasena' : 'Sin contrasena' ?>
                </span>
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

<!-- ------------------------------ Modales ------------------------- -->
<?php if ($auth->can('credentials.rotate')): ?>
<div class="modal-backdrop" id="modal-rotate" hidden>
  <div class="modal" role="dialog" aria-modal="true">
    <form method="post" action="<?= e(url('/credenciales/' . $id . '/rotar')) ?>">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="modal__head"><h3>Actualizar contrasena</h3></div>
      <div class="modal__body stack">
        <p class="text-small text-muted mb-0">
          La contrasena anterior se conserva cifrada como version historica. El cambio queda auditado.
        </p>
        <div class="field">
          <label for="rotate-password">Nueva contrasena</label>
          <div class="row" style="flex-wrap:nowrap;gap:.35rem">
            <input type="password" id="rotate-password" name="password" required maxlength="1024" autocomplete="new-password">
            <button type="button" class="btn btn--sm" data-toggle-field="rotate-password">Mostrar</button>
          </div>
          <div class="bar" data-strength-for="rotate-password"><span style="width:0"></span></div>
          <span class="hint" data-strength-label></span>
        </div>

        <div data-generator="rotate-password" class="stack" style="background:var(--surface-2);padding:.7rem;border-radius:var(--radius-sm)">
          <div class="row" style="justify-content:space-between">
            <strong class="text-small">Generador seguro</strong>
            <span class="text-small text-muted" data-generator-output></span>
          </div>
          <label class="text-small">Longitud: <span data-generator-length>20</span>
            <input type="range" name="gen_length" min="8" max="64" value="20" style="width:100%">
          </label>
          <div class="row">
            <label class="check"><input type="checkbox" name="gen_upper" checked> Mayusculas</label>
            <label class="check"><input type="checkbox" name="gen_lower" checked> Minusculas</label>
            <label class="check"><input type="checkbox" name="gen_digits" checked> Numeros</label>
            <label class="check"><input type="checkbox" name="gen_symbols" checked> Simbolos</label>
            <label class="check"><input type="checkbox" name="gen_ambiguous"> Excluir ambiguos</label>
          </div>
          <button type="button" class="btn btn--sm" data-generate>Generar contrasena</button>
        </div>

        <div class="field">
          <label for="rotate-reason">Motivo del cambio</label>
          <input type="text" id="rotate-reason" name="reason" maxlength="255" placeholder="Rotacion periodica, incidente, cambio de proveedor…">
        </div>
        <div class="field">
          <label for="rotate-days">Proxima rotacion (dias)</label>
          <input type="number" id="rotate-days" name="rotation_period_days" min="0" max="3650"
                 value="<?= e((string) ($credential['rotation_period_days'] ?? 90)) ?>">
        </div>
      </div>
      <div class="modal__foot">
        <button type="button" class="btn" data-modal-close>Cancelar</button>
        <button type="submit" class="btn btn--primary">Guardar nueva contrasena</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($auth->can('credentials.assign')): ?>
<div class="modal-backdrop" id="modal-assign" hidden>
  <div class="modal" role="dialog" aria-modal="true">
    <form method="post" action="<?= e(url('/credenciales/' . $id . '/asignar')) ?>">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="modal__head"><h3>Asignar acceso</h3></div>
      <div class="modal__body stack">
        <div class="field">
          <label for="assign-user">Usuario</label>
          <select id="assign-user" name="user_id" required>
            <option value="">Seleccione…</option>
            <?php foreach ($usersList as $u): ?>
              <option value="<?= (int) $u['id'] ?>"><?= e($u['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Puede buscar por nombre o numero de cedula.</span>
        </div>
        <label class="check"><input type="checkbox" name="can_view_secret" value="1" checked> Puede ver la contrasena</label>
        <label class="check"><input type="checkbox" name="can_copy_secret" value="1" checked> Puede copiar la contrasena</label>
        <label class="check"><input type="checkbox" name="can_view_recovery" value="1"> Puede ver la informacion de recuperacion</label>
        <div class="field">
          <label for="assign-expires">Vigencia del acceso (opcional)</label>
          <input type="date" id="assign-expires" name="expires_at">
        </div>
        <div class="field">
          <label for="assign-reason">Motivo</label>
          <input type="text" id="assign-reason" name="reason" maxlength="255">
        </div>
      </div>
      <div class="modal__foot">
        <button type="button" class="btn" data-modal-close>Cancelar</button>
        <button type="submit" class="btn btn--primary">Asignar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($auth->can('credentials.delete')): ?>
<div class="modal-backdrop" id="modal-delete" hidden>
  <div class="modal" role="dialog" aria-modal="true">
    <form method="post" action="<?= e(url('/credenciales/' . $id . '/eliminar')) ?>">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="modal__head"><h3>Dar de baja la credencial</h3></div>
      <div class="modal__body stack">
        <div class="alert alert--warn mb-0">
          La credencial se archiva y se revocan todos sus accesos. El historial y la auditoria se conservan.
        </div>
        <div class="field">
          <label for="delete-reason">Motivo</label>
          <input type="text" id="delete-reason" name="reason" required maxlength="255">
        </div>
      </div>
      <div class="modal__foot">
        <button type="button" class="btn" data-modal-close>Cancelar</button>
        <button type="submit" class="btn btn--danger">Dar de baja</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
