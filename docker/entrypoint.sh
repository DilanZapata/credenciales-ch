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

echo "==> Verificando el material criptografico"
if ! php docker/check-crypto.php; then
    exit 1
fi

echo "==> Verificando extensiones de PHP"
if ! php docker/check-extensions.php; then
    echo "!!! La imagen se construyo de forma incompleta."
    echo "    Vuelva a desplegar con la cache limpia (Clean Cache)."
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

# Las ordenes anteriores corren como root y pueden haber creado archivos de
# log de su propiedad. Se devuelve storage/ a www-data para que Apache pueda
# seguir escribiendo.
chown -R www-data:www-data storage
chmod -R 750 storage

echo "==> Listo. Iniciando Apache."
exec "$@"
