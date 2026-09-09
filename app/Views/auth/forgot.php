<div class="card">
  <div class="card__body">
    <h1 style="margin-bottom:.15rem">Recuperar acceso</h1>
    <p class="text-muted text-small">
      Le enviaremos un enlace de un solo uso a su correo corporativo.
      Por seguridad, el sistema nunca envia contrasenas por correo.
    </p>

    <form method="post" action="<?= e(url('/recuperar')) ?>" class="stack">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="field">
        <label for="identifier">Usuario, correo o cedula</label>
        <input type="text" id="identifier" name="identifier" required autofocus maxlength="190">
      </div>
      <button class="btn btn--primary btn--block" type="submit">Enviar instrucciones</button>
      <a class="btn btn--ghost btn--block btn--sm" href="<?= e(url('/entrar')) ?>">Volver</a>
    </form>
  </div>
</div>
