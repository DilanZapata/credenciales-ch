<?php
use App\Core\Flash;

$map = [
    'success' => ['alert--ok',    'Listo'],
    'error'   => ['alert--error', 'Atencion'],
    'warning' => ['alert--warn',  'Advertencia'],
    'info'    => ['alert--info',  'Informacion'],
];
foreach ($map as $key => [$class, $label]):
    if (!Flash::has($key)) { continue; }
    $message = Flash::get($key);
    if (!is_string($message)) { continue; }
?>
<div class="alert <?= e($class) ?>" role="alert"><strong><?= e($label) ?>:</strong> <span><?= e($message) ?></span></div>
<?php endforeach; ?>

<?php $errors = Flash::get('errors'); if (is_array($errors) && $errors !== []): ?>
<div class="alert alert--error" role="alert">
  <div>
    <strong>Revise los siguientes datos:</strong>
    <ul style="margin:.35rem 0 0;padding-left:1.1rem">
      <?php foreach ($errors as $message): ?><li><?= e(is_string($message) ? $message : '') ?></li><?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<?php $temp = Flash::get('temporary_password'); if (is_string($temp) && $temp !== ''): ?>
<div class="alert alert--warn">
  <div>
    <strong>Contrasena temporal generada.</strong>
    Se muestra una unica vez. Entreguela por un canal seguro; el usuario debera cambiarla al ingresar.
    <div class="secret mt-1"><span class="secret__value mono"><?= e($temp) ?></span></div>
  </div>
</div>
<?php endif; ?>

<?php $codes = Flash::get('backup_codes'); if (is_array($codes) && $codes !== []): ?>
<div class="alert alert--warn">
  <div>
    <strong>Codigos de respaldo de verificacion en dos pasos.</strong>
    Guardelos en un lugar seguro: no volveran a mostrarse y cada uno sirve una sola vez.
    <div class="row mt-1">
      <?php foreach ($codes as $code): ?><span class="badge mono"><?= e(is_string($code) ? $code : '') ?></span><?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>
