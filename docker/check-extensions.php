<?php
declare(strict_types=1);

/**
 * Verificacion de las extensiones de PHP requeridas.
 *
 * Se ejecuta DOS veces:
 *   - al construir la imagen: si algo falta, la imagen no se publica;
 *   - al arrancar el contenedor: ultima red de seguridad.
 *
 * El motivo de que exista: una extension puede quedar compilada pero sin
 * su biblioteca en tiempo de ejecucion, y entonces falla en silencio. Eso
 * ocurrio con `zip`, y la exportacion a Excel habria dejado de funcionar
 * sin que nadie se enterase hasta intentar generar un reporte.
 */

$errores = [];

// Nombre real de cada extension segun lo registra PHP. Ojo con OPcache:
// `php -m` la lista como "Zend OPcache", no como "opcache".
// Sin estas el sistema NO puede funcionar correctamente: son fatales.
$requeridas = [
    'pdo_mysql' => 'acceso a la base de datos',
    'zip'       => 'generacion de archivos .xlsx',
    'openssl'   => 'cifrado de los secretos',
    'mbstring'  => 'manejo de texto UTF-8',
    'json'      => 'API y auditoria',
];

// Estas mejoran el rendimiento pero no afectan a la correccion: solo avisan.
$recomendadas = [
    'Zend OPcache' => 'rendimiento en produccion',
];

$avisos = [];

foreach ($requeridas as $extension => $para) {
    if (!extension_loaded($extension)) {
        $errores[] = sprintf('Falta la extension "%s" (%s).', $extension, $para);
    }
}

foreach ($recomendadas as $extension => $para) {
    if (!extension_loaded($extension)) {
        $avisos[] = sprintf('No esta activa la extension "%s" (%s).', $extension, $para);
    }
}

// No basta con que la extension cargue: debe poder usarse.
if (!class_exists('ZipArchive')) {
    $errores[] = 'La extension zip esta cargada pero ZipArchive no existe.';
}

if (!in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
    $errores[] = 'OpenSSL no ofrece aes-256-gcm: el sistema no puede cifrar secretos.';
}

if (!function_exists('hash_hkdf')) {
    $errores[] = 'hash_hkdf no esta disponible: no se pueden derivar las claves.';
}

if (!function_exists('random_bytes')) {
    $errores[] = 'random_bytes no esta disponible: no hay fuente criptografica segura.';
}

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    $errores[] = 'Se requiere PHP 8.1 o superior; hay ' . PHP_VERSION . '.';
}

if ($errores !== []) {
    fwrite(STDERR, PHP_EOL . 'VERIFICACION FALLIDA' . PHP_EOL);
    foreach ($errores as $error) {
        fwrite(STDERR, '  - ' . $error . PHP_EOL);
    }
    fwrite(STDERR, PHP_EOL);
    exit(1);
}

foreach ($avisos as $aviso) {
    echo 'Aviso: ' . $aviso . PHP_EOL;
}

echo 'Extensiones verificadas: PHP ' . PHP_VERSION . ', '
   . implode(', ', array_keys($requeridas)) . '.' . PHP_EOL;
exit(0);
