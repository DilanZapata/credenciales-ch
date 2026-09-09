<?php
declare(strict_types=1);

use App\Core\Env;

return [
    /**
     * Cabeceras de seguridad aplicadas a toda respuesta HTML.
     * La CSP prohibe scripts en linea sin nonce y bloquea marcos externos.
     */
    'headers' => [
        'X-Content-Type-Options'  => 'nosniff',
        'X-Frame-Options'         => 'DENY',
        'Referrer-Policy'         => 'no-referrer',
        'X-Permitted-Cross-Domain-Policies' => 'none',
        'Cross-Origin-Opener-Policy'   => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'Permissions-Policy'      => 'geolocation=(), camera=(), microphone=(), payment=(), usb=(), interest-cohort=()',
    ],
    'hsts' => 'max-age=31536000; includeSubDomains',

    /**
     * CORS: por defecto NINGUN origen externo puede consumir la API.
     * El sistema es de uso interno y comparte origen con su frontend.
     */
    'cors' => [
        'enabled'          => (bool) Env::get('CORS_ENABLED', false),
        'allowed_origins'  => array_filter(array_map('trim', explode(',', (string) Env::get('CORS_ORIGINS', '')))),
        'allowed_headers'  => ['Content-Type', 'X-CSRF-Token', 'Accept'],
        'allowed_methods'  => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'max_age'          => 600,
    ],

    /** Rutas exentas de verificacion CSRF (ninguna por defecto). */
    'csrf_exempt' => [],

    /** Limitador global de peticiones por sesion/IP. */
    'rate_limits' => [
        'global'  => ['max' => 600, 'window' => 60],
        'api'     => ['max' => 300, 'window' => 60],
        'search'  => ['max' => 120, 'window' => 60],
    ],
];
