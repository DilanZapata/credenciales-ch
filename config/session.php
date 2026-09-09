<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'cookie_name'     => 'scgca_session',
    // Secure debe activarse en cuanto el sistema se sirva por HTTPS.
    'cookie_secure'   => (bool) Env::get('SESSION_SECURE', false),
    'cookie_samesite' => Env::get('SESSION_SAMESITE', 'Strict'),
    'cookie_httponly' => true,
];
