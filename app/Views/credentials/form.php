<?php
/** @var array|null $credential */
$isEdit = $credential !== null;
$action = $isEdit ? url('/credenciales/' . (int) $credential['id']) : url('/credenciales');
$v = static fn (string $key, $default = '') => $credential[$key] ?? $default;
?>
<div class="page-head">
  <div class="page-head__text">
    <h1><?= $isEdit ? 'Editar credencial' : 'Nueva credencial' ?></h1>
    <p>Los datos generales se guardan en claro; la contrasena se cifra con AES-256-GCM antes de tocar la base de datos.</p>
  </div>
</div>

<form method="post" action="<?= e($action) ?>">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

  <div class="card">
    <div class="card__head"><h2>Informacion general</h2></div>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label for="system_id">Sistema *</label>
          <select id="system_id" name="system_id" required>
            <option value="">Seleccione…</option>
            <?php foreach ($systems as $system): ?>
              <option value="<?= (int) $system['id'] ?>" <?= (int) $v('system_id', 0) === (int) $system['id'] ? 'selected' : '' ?>>
                <?= e($system['name']) ?><?= $system['category_name'] ? ' — ' . e($system['category_name']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="hint">La categoria, empresa, sede, URL e IP se heredan del sistema.</span>
        </div>
        <div class="field">
          <label for="name">Nombre descriptivo de la credencial *</label>
          <input type="text" id="name" name="name" required maxlength="180" value="<?= e($v('name')) ?>"
                 placeholder="Ej.: Usuario de contabilidad">
        </div>
        <div class="field">
          <label for="environment">Entorno</label>
          <select id="environment" name="environment">
            <?php foreach (['production' => 'Produccion', 'staging' => 'Pruebas', 'development' => 'Desarrollo', 'other' => 'Otro'] as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= (string) $v('environment', 'production') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="owner_user_id">Responsable</label>
          <select id="owner_user_id" name="owner_user_id">
            <option value="">Sin responsable</option>
            <?php foreach ($usersList as $u): ?>
              <option value="<?= (int) $u['id'] ?>" <?= (int) $v('owner_user_id', 0) === (int) $u['id'] ? 'selected' : '' ?>>
                <?= e($u['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($isEdit): ?>
        <div class="field">
          <label for="status">Estado</label>
          <select id="status" name="status">
            <?php foreach (['active' => 'Activa', 'inactive' => 'Inactiva', 'expired' => 'Vencida', 'revoked' => 'Revocada', 'archived' => 'Archivada'] as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= (string) $v('status', 'active') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2>Informacion de acceso</h2></div>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label for="username">Usuario</label>
          <input type="text" id="username" name="username" maxlength="190" value="<?= e($v('username')) ?>" autocomplete="off">
        </div>
        <div class="field">
          <label for="email">Correo electronico</label>
          <input type="email" id="email" name="email" maxlength="190" value="<?= e($v('email')) ?>" autocomplete="off">
        </div>

        <?php if (!$isEdit): ?>
        <div class="field full">
          <label for="password">Contrasena *</label>
          <div class="row" style="flex-wrap:nowrap;gap:.35rem">
            <input type="password" id="password" name="password" required maxlength="1024" autocomplete="new-password">
            <button type="button" class="btn btn--sm" data-toggle-field="password">Mostrar</button>
          </div>
          <div class="bar" data-strength-for="password"><span style="width:0"></span></div>
          <span class="hint" data-strength-label></span>
        </div>

        <div class="field full" data-generator="password" style="background:var(--surface-2);padding:.75rem;border-radius:var(--radius-sm)">
          <div class="row" style="justify-content:space-between">
            <strong class="text-small">Generador criptografico de contrasenas</strong>
            <span class="text-small text-muted" data-generator-output></span>
          </div>
          <label class="text-small">Longitud: <span data-generator-length>20</span>
            <input type="range" name="gen_length" min="8" max="64" value="20" style="width:100%">
          </label>
          <div class="row">
            <label class="check"><input type="checkbox" name="gen_upper" checked> Mayusculas</label>
            <label class="check"><input type="checkbox" name="gen_lower" checked> Minusculas</label>
            <label class="check"><input type="checkbox" name="gen_digits" checked> Numeros</label>
            <label class="check"><input type="checkbox" name="gen_symbols" checked> Caracteres especiales</label>
            <label class="check"><input type="checkbox" name="gen_ambiguous"> Excluir ambiguos (l, 1, O, 0…)</label>
          </div>
          <button type="button" class="btn btn--sm" data-generate>Generar</button>
        </div>
        <?php else: ?>
        <div class="field full">
          <div class="alert alert--info mb-0">
            La contrasena no se edita desde este formulario. Use <strong>Actualizar contrasena</strong>
            en la ficha para registrar la rotacion con su motivo y conservar el historial.
          </div>
        </div>
        <?php endif; ?>

        <div class="field">
          <label for="domain">Dominio</label>
          <input type="text" id="domain" name="domain" maxlength="190" value="<?= e($v('domain')) ?>">
        </div>
        <div class="field">
          <label for="admin_username">Usuario administrador</label>
          <input type="text" id="admin_username" name="admin_username" maxlength="190" value="<?= e($v('admin_username')) ?>">
        </div>
        <div class="field">
          <label for="auth_method">Metodo de autenticacion</label>
          <input type="text" id="auth_method" name="auth_method" maxlength="80" value="<?= e($v('auth_method')) ?>"
                 placeholder="Contrasena, MFA, SSO, certificado, llave SSH…">
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2>Informacion de recuperacion</h2></div>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label for="recovery_email">Correo de recuperacion</label>
          <input type="email" id="recovery_email" name="recovery_email" maxlength="190" value="<?= e($v('recovery_email')) ?>">
        </div>
        <div class="field">
          <label for="recovery_phone">Telefono de recuperacion</label>
          <input type="tel" id="recovery_phone" name="recovery_phone" maxlength="40" value="<?= e($v('recovery_phone')) ?>">
        </div>
        <div class="field">
          <label for="recovery_username">Usuario de recuperacion</label>
          <input type="text" id="recovery_username" name="recovery_username" maxlength="190" value="<?= e($v('recovery_username')) ?>">
        </div>
        <div class="field">
          <label class="check" style="margin-top:1.6rem">
            <input type="checkbox" name="has_security_questions" value="1" <?= (int) $v('has_security_questions', 0) === 1 ? 'checked' : '' ?>>
            Existen preguntas de seguridad registradas
          </label>
        </div>
        <div class="field full">
          <label for="recovery_notes">Informacion adicional de recuperacion</label>
          <textarea id="recovery_notes" name="recovery_notes" maxlength="3000"><?= e($v('recovery_notes')) ?></textarea>
          <span class="hint">Estos datos solo son visibles con el permiso especifico y su consulta queda auditada.</span>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2>Vigencia y observaciones</h2></div>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label for="rotation_period_days">Periodo de rotacion (dias)</label>
          <input type="number" id="rotation_period_days" name="rotation_period_days" min="0" max="3650"
                 value="<?= e((string) ($credential['rotation_period_days'] ?? $defaultRotation)) ?>">
          <span class="hint">0 = sin rotacion programada.</span>
        </div>
        <div class="field">
          <label for="expires_at">Fecha de vencimiento</label>
          <input type="date" id="expires_at" name="expires_at" value="<?= e($v('expires_at')) ?>">
        </div>
        <div class="field full">
          <label for="observations">Observaciones</label>
          <textarea id="observations" name="observations" maxlength="3000"><?= e($v('observations')) ?></textarea>
        </div>
        <?php if ($isEdit): ?>
        <div class="field full">
          <label for="change_reason">Motivo del cambio (queda en el historial)</label>
          <input type="text" id="change_reason" name="change_reason" maxlength="255">
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="card__foot row row--end">
      <a class="btn" href="<?= e($isEdit ? url('/credenciales/' . (int) $credential['id']) : url('/credenciales')) ?>">Cancelar</a>
      <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Guardar cambios' : 'Registrar credencial' ?></button>
    </div>
  </div>
</form>
