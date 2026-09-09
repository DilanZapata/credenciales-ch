<div class="card">
  <div class="card__body">
    <h1 style="margin-bottom:.15rem">Nueva contrasena</h1>
    <p class="text-muted text-small">
      Debe combinar mayusculas, minusculas, numeros y un caracter especial.
    </p>

    <form method="post" action="<?= e(url('/restablecer')) ?>" class="stack">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="token" value="<?= e($token) ?>">

      <div class="field">
        <label for="password">Nueva contrasena</label>
        <input type="password" id="password" name="password" required autocomplete="new-password" maxlength="200">
        <div class="bar" data-strength-for="password"><span style="width:0"></span></div>
        <span class="hint" data-strength-label></span>
      </div>

      <div class="field">
        <label for="password_confirmation">Confirmar contrasena</label>
        <input type="password" id="password_confirmation" name="password_confirmation" required
               autocomplete="new-password" maxlength="200">
      </div>

      <button class="btn btn--primary btn--block" type="submit">Guardar contrasena</button>
    </form>
  </div>
</div>
