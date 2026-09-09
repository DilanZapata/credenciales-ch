# =====================================================================
#  Sistema Corporativo de Gestion de Credenciales
#  Imagen de produccion: PHP 8.2 + Apache
# =====================================================================
FROM php:8.2-apache

# --- Extensiones de PHP requeridas -----------------------------------
#  pdo_mysql : acceso a la base de datos
#  zip       : generacion de los archivos .xlsx
#  opcache   : rendimiento en produccion
#  NO se purga libzip-dev despues de compilar: al hacerlo con --auto-remove
#  se elimina tambien libzip en tiempo de ejecucion y la extension queda
#  compilada pero imposible de cargar (libzip.so: cannot open shared object
#  file), con lo que la exportacion a Excel deja de funcionar en silencio.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        default-mysql-client \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql zip opcache \
    && rm -rf /var/lib/apt/lists/*

# --- Apache ----------------------------------------------------------
#  El DocumentRoot apunta a public/: el resto del proyecto queda fuera
#  del alcance del servidor web.
RUN a2enmod rewrite headers \
    && a2dismod -f autoindex \
    && sed -i 's/ServerTokens OS/ServerTokens Prod/' /etc/apache2/conf-available/security.conf \
    && sed -i 's/ServerSignature On/ServerSignature Off/' /etc/apache2/conf-available/security.conf
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-scgca.ini

WORKDIR /var/www/html

# --- Codigo de la aplicacion -----------------------------------------
COPY --chown=www-data:www-data . /var/www/html

# storage/ debe ser escribible por el servidor web y por nadie mas.
# El .env se inyecta como variables de entorno, no se copia a la imagen.
RUN rm -f .env \
    && mkdir -p storage/logs storage/tmp storage/exports \
    && chown -R www-data:www-data storage \
    && chmod -R 750 storage \
    && find /var/www/html -type f -name '*.php' -exec chmod 640 {} \; \
    && find /var/www/html -type d -exec chmod 750 {} \; \
    && chmod 755 /var/www/html/public \
    && chmod 644 /var/www/html/public/.htaccess 2>/dev/null || true

#  Verificacion en tiempo de construccion. Si falta una extension critica
#  la imagen NO se publica: vale mas romper aqui que descubrirlo en
#  produccion cuando alguien intente generar un reporte.
RUN php docker/check-extensions.php

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/entrar") === false ? 1 : 0);'

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
