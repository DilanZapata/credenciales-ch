<?php
declare(strict_types=1);

$root = dirname(__DIR__);

return [
    'root'    => $root,
    'app'     => $root . '/app',
    'views'   => $root . '/app/Views',
    'storage' => $root . '/storage',
    'logs'    => $root . '/storage/logs',
    'tmp'     => $root . '/storage/tmp',
    // Los archivos exportados viven FUERA del webroot: nunca son
    // accesibles por URL directa.
    'exports' => $root . '/storage/exports',
];
