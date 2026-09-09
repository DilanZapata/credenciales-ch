<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'name'        => Env::get('APP_NAME', 'Sistema Corporativo de Gestion de Credenciales'),
    'short_name'  => Env::get('APP_SHORT_NAME', 'Credenciales'),
    'env'         => Env::get('APP_ENV', 'production'),
    // En produccion los errores nunca se muestran al usuario final.
    'debug'       => (bool) Env::get('APP_DEBUG', false),
    'url'         => rtrim((string) Env::get('APP_URL', 'http://localhost/credencial/public'), '/'),
    'base_path'   => rtrim((string) Env::get('APP_BASE_PATH', '/credencial/public'), '/'),
    'timezone'    => Env::get('APP_TIMEZONE', 'America/Bogota'),
    'locale'      => 'es',
    'trust_proxy' => (bool) Env::get('APP_TRUST_PROXY', false),
    'organization'=> Env::get('APP_ORGANIZATION', 'Mi Empresa'),
];
