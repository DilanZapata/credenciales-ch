<?php
declare(strict_types=1);

/**
 * Verificacion del material criptografico antes de arrancar.
 *
 * No basta con que las variables existan: deben ser 32 bytes en base64.
 * Un valor con comillas, con espacios o recortado deja la aplicacion
 * arrancada pero incapaz de cifrar, y el fallo solo aparece —como un 500
 * opaco— la primera vez que alguien intenta guardar o leer un secreto.
 * Es preferible que el contenedor no arranque y diga por que.
 */

$errores = [];

foreach (['APP_MASTER_KEY', 'APP_PEPPER'] as $nombre) {
    $valor = (string) getenv($nombre);

    if ($valor === '') {
        $errores[] = "{$nombre} no esta definida.";
        continue;
    }
    if (trim($valor) !== $valor) {
        $errores[] = "{$nombre} tiene espacios sobrantes al principio o al final.";
        continue;
    }
    if (preg_match('/["\']/', $valor) === 1) {
        $errores[] = "{$nombre} lleva comillas. Peguela sin comillas.";
        continue;
    }
    if (str_contains($valor, $nombre)) {
        $errores[] = "{$nombre} incluye su propio nombre. Pegue solo el valor, no 'NOMBRE=valor'.";
        continue;
    }

    $bytes = base64_decode($valor, true);
    if ($bytes === false) {
        $errores[] = "{$nombre} no es base64 valido (recibidos " . strlen($valor) . " caracteres).";
        continue;
    }
    if (strlen($bytes) !== 32) {
        $errores[] = "{$nombre} decodifica a " . strlen($bytes) . " bytes; se esperan 32 "
                   . "(el valor correcto son 44 caracteres terminados en '=').";
    }
}

if ($errores !== []) {
    fwrite(STDERR, PHP_EOL . 'MATERIAL CRIPTOGRAFICO INVALIDO' . PHP_EOL . PHP_EOL);
    foreach ($errores as $error) {
        fwrite(STDERR, '  - ' . $error . PHP_EOL);
    }
    fwrite(STDERR, PHP_EOL . '  Genere cada clave con:' . PHP_EOL);
    fwrite(STDERR, '    php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"' . PHP_EOL . PHP_EOL);
    fwrite(STDERR, '  Y peguela en Dokploy tal cual, sin comillas:' . PHP_EOL);
    fwrite(STDERR, '    APP_MASTER_KEY=Cu66............................................=' . PHP_EOL . PHP_EOL);
    exit(1);
}

echo 'Material criptografico correcto: APP_MASTER_KEY y APP_PEPPER de 32 bytes.' . PHP_EOL;
exit(0);
