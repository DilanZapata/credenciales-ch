<div class="card">
  <div class="card__body">
    <h1 style="margin-bottom:.15rem">Iniciar sesion</h1>
    <p class="text-muted text-small">Acceda con su usuario, correo o numero de cedula.</p>

    <form method="post" action="<?= e(url('/entrar')) ?>" class="stack" autocomplete="on">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="redirect" value="<?= e($redirect ?? '') ?>">

      <div class="field">
        <label for="identifier">Usuario, correo o cedula</label>
        <input type="text" id="identifier" name="identifier" required autofocus
               autocomplete="username" maxlength="190" spellcheck="false">
        <span class="hint">La cedula identifica al empleado, pero nunca sustituye a la contrasena.</span>
      </div>

      <div class="field">
        <label for="password">Contrasena</label>
        <div class="row" style="flex-wrap:nowrap;gap:.35rem">
          <input type="password" id="password" name="password" required autocomplete="current-password" maxlength="200">
          <button type="button" class="btn btn--sm" data-toggle-field="password">Mostrar</button>
        </div>
      </div>

      <button class="btn btn--primary btn--block" type="submit">Entrar</button>

      <div class="row" style="justify-content:space-between">
        <a class="text-small" href="<?= e(url('/recuperar')) ?>">Olvide mi contrasena</a>
        <span class="text-small text-muted">Acceso auditado</span>
      </div>
    </form>
  </div>
</div>
