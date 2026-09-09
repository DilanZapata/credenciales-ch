<div class="page-head">
  <div class="page-head__text">
    <h1>Verificacion en dos pasos</h1>
    <p>Anade un segundo factor temporal (TOTP) al inicio de sesion y a las operaciones sensibles.</p>
  </div>
</div>

<?php if ($enabled): ?>
<div class="card" style="max-width:620px">
  <div class="card__body stack">
    <div class="alert alert--ok mb-0"><div><strong>Activada.</strong> Su cuenta exige un codigo temporal al ingresar.</div></div>
    <p class="mb-0">Codigos de respaldo disponibles: <strong><?= (int) $backupCodes ?></strong></p>
    <?php if (!$required): ?>
    <form method="post" action="<?= e(url('/perfil/mfa/desactivar')) ?>" class="stack"
          data-confirm="Va a desactivar la verificacion en dos pasos. Confirme.">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="field">
        <label for="password">Confirme su contrasena para desactivarla</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
      </div>
      <button class="btn btn--danger" type="submit">Desactivar verificacion en dos pasos</button>
    </form>
    <?php else: ?>
      <div class="alert alert--info mb-0">
        <div>Su rol exige verificacion en dos pasos, por lo que no puede desactivarla.</div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($enrollment !== null): ?>
<div class="card" style="max-width:620px">
  <div class="card__head"><h2>Configure su aplicacion autenticadora</h2></div>
  <div class="card__body stack">
    <ol class="text-small" style="padding-left:1.1rem">
      <li>Abra Google Authenticator, Microsoft Authenticator, Authy, 1Password o similar.</li>
      <li>Anada una cuenta nueva e introduzca esta clave manualmente.</li>
      <li>Escriba el codigo de 6 digitos que aparezca para confirmar.</li>
    </ol>

    <div class="field">
      <label>Clave secreta</label>
      <div class="secret">
        <span class="secret__value mono"><?= e(chunk_split($enrollment['secret'], 4, ' ')) ?></span>
      </div>
      <span class="hint">Guardela solo el tiempo necesario para configurar la aplicacion.</span>
    </div>

    <details>
      <summary class="text-small text-muted">Ver URI otpauth (para pegar en su gestor)</summary>
      <p class="mono text-small" style="word-break:break-all;margin-top:.4rem"><?= e($enrollment['uri']) ?></p>
    </details>

    <form method="post" action="<?= e(url('/perfil/mfa/confirmar')) ?>" class="stack">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="field">
        <label for="code">Codigo de verificacion</label>
        <input type="text" id="code" name="code" required inputmode="numeric" maxlength="8"
               autocomplete="one-time-code" style="font-family:var(--mono);letter-spacing:.2em">
      </div>
      <button class="btn btn--primary" type="submit">Activar verificacion en dos pasos</button>
    </form>
  </div>
</div>

<?php else: ?>
<div class="card" style="max-width:620px">
  <div class="card__body stack">
    <?php if ($required): ?>
      <div class="alert alert--warn mb-0"><div><strong>Obligatorio para su rol.</strong> Configure el segundo factor para continuar.</div></div>
    <?php endif; ?>
    <p class="mb-0">
      La verificacion en dos pasos protege el sistema aunque su contrasena quede comprometida.
      Es especialmente importante para los perfiles administrativos, que pueden ver todas las credenciales.
    </p>
    <form method="post" action="<?= e(url('/perfil/mfa/iniciar')) ?>">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <button class="btn btn--primary" type="submit">Comenzar configuracion</button>
    </form>
  </div>
</div>
<?php endif; ?>
