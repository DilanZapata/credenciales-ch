<div class="card">
  <div class="card__body">
    <h1 style="margin-bottom:.15rem">Verificacion en dos pasos</h1>
    <p class="text-muted text-small">
      Introduzca el codigo de 6 digitos de su aplicacion autenticadora.
      Tambien puede usar uno de sus codigos de respaldo.
    </p>

    <form method="post" action="<?= e(url('/mfa/verificar')) ?>" class="stack">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="field">
        <label for="code">Codigo de verificacion</label>
        <input type="text" id="code" name="code" required autofocus inputmode="numeric"
               autocomplete="one-time-code" maxlength="12" spellcheck="false"
               style="font-family:var(--mono);font-size:1.15rem;letter-spacing:.2em;text-align:center">
      </div>
      <button class="btn btn--primary btn--block" type="submit">Verificar</button>
    </form>

    <form method="post" action="<?= e(url('/salir')) ?>" class="mt-1">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <button class="btn btn--ghost btn--block btn--sm" type="submit">Cancelar y salir</button>
    </form>
  </div>
</div>
