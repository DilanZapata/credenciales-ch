<?php
declare(strict_types=1);

/**
 * Punto de entrada unico de la aplicacion.
 *
 * Solo este directorio (public/) debe ser accesible por el servidor web.
 * El codigo, la configuracion, el .env y los archivos exportados viven
 * fuera del webroot.
 */

use App\Core\Flash;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Router;

/** @var \App\Core\Container $container */
$container = require dirname(__DIR__) . '/app/bootstrap.php';
/** @var Router $router */
$router = require dirname(__DIR__) . '/app/routes.php';

$request = Request::capture();
Flash::load($request);

$kernel   = new Kernel($container, $router);
$response = $kernel->handle($request);

Flash::applyTo($response)->send();
