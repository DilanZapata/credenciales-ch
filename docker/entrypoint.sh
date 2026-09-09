#!/bin/bash
# =====================================================================
#  Arranque del contenedor
#   1. Espera a que la base de datos responda
#   2. Aplica las migraciones pendientes
#   3. Verifica la instalacion
#   4. Cede el control a Apache
# =====================================================================
set -euo pipefail

cd /var/www/html

echo "==> Verificando la clave maestra"
if [ -z "${APP_MASTER_KEY:-}" ]; then
    echo "!!! APP_MASTER_KEY no esta definida."
    echo "    Genere una con:  php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'"
    echo "    y cargela como variable de entorno en Dokploy."
    exit 1
fi

echo "==> Esperando a la base de datos (${DB_HOST:-db}:${DB_PORT:-3306})"
intentos=0
until php -r '
    $h = getenv("DB_HOST") ?: "db";
    $p = (int) (getenv("DB_PORT") ?: 3306);
    $d = getenv("DB_DATABASE") ?: "credenciales_corp";
    $u = getenv("DB_USERNAME") ?: "root";
    $w = getenv("DB_PASSWORD") ?: "";
    try { new PDO("mysql:host=$h;port=$p;dbname=$d", $u, $w); exit(0); }
    catch (Throwable $e) { exit(1); }
' 2>/dev/null; do
    intentos=$((intentos + 1))
    if [ "$intentos" -ge 60 ]; then
        echo "!!! La base de datos no respondio tras 60 intentos."
        exit 1
    fi
    sleep 2
done
echo "    Base de datos disponible."

echo "==> Preparando directorios de trabajo"
mkdir -p storage/logs storage/tmp storage/exports
chown -R www-data:www-data storage
chmod -R 750 storage

echo "==> Aplicando migraciones"
php bin/console.php migrate

echo "==> Diagnostico"
php bin/console.php doctor || true

echo "==> Listo. Iniciando Apache."
exec "$@"
