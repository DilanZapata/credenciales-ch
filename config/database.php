<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'host'     => Env::get('DB_HOST', '127.0.0.1'),
    'port'     => (int) Env::get('DB_PORT', 3306),
    'database' => Env::get('DB_DATABASE', 'credenciales_corp'),
    'username' => Env::get('DB_USERNAME', 'root'),
    'password' => (string) Env::get('DB_PASSWORD', ''),
    'charset'  => 'utf8mb4',
    'socket'   => Env::get('DB_SOCKET', ''),
];
