<div class="page-head">
  <div class="page-head__text">
    <h1>Cambiar contrasena</h1>
    <p>Al cambiarla se cerraran todas sus demas sesiones activas.</p>
  </div>
</div>

<?php if ($forced): ?>
<div class="alert alert--warn">
  <div><strong>Cambio obligatorio.</strong> Debe definir una contrasena propia antes de utilizar el sistema.</div>
</div>
<?php endif; ?>

<div class="card" style="max-width:560px">
  <form method="post" action="<?= e(url('/perfil/contrasena')) ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="card__body stack">
      <div class="field">
        <label for="current_password">Contrasena actual</label>
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
      </div>
      <div class="field">
        <label for="password">Nueva contrasena</label>
        <div class="row" style="flex-wrap:nowrap;gap:.35rem">
          <input type="password" id="password" name="password" required autocomplete="new-password" maxlength="200">
          <button type="button" class="btn btn--sm" data-toggle-field="password">Mostrar</button>
        </div>
        <div class="bar" data-strength-for="password"><span style="width:0"></span></div>
        <span class="hint" data-strength-label></span>
        <span class="hint">Minimo 12 caracteres con mayusculas, minusculas, numeros y un caracter especial.</span>
      </div>
      <div class="field">
        <label for="password_confirmation">Confirmar nueva contrasena</label>
        <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password" maxlength="200">
      </div>
    </div>
    <div class="card__foot row row--end">
      <button class="btn btn--primary" type="submit">Actualizar contrasena</button>
    </div>
  </form>
</div>
