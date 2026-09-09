<div class="card">
  <div class="card__body" style="text-align:center;padding:2.5rem 1.5rem">
    <div style="font-size:2.6rem;font-weight:700;color:var(--text-3);line-height:1"><?= e((string) ($status ?? 500)) ?></div>
    <h1 style="margin-top:.6rem"><?= e($title ?? 'Error') ?></h1>
    <p class="text-muted"><?= e($message ?? 'Ocurrio un error inesperado.') ?></p>
    <div class="row" style="justify-content:center;margin-top:1rem">
      <a class="btn btn--primary" href="<?= e(url('/')) ?>">Volver al inicio</a>
      <a class="btn" href="<?= e(url('/entrar')) ?>">Iniciar sesion</a>
    </div>
  </div>
</div>
