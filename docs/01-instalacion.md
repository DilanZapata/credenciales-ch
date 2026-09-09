# 1. Instalación

## 1.1 Requisitos

| Componente | Mínimo | Comprobación |
|---|---|---|
| PHP | 8.1 (probado en 8.2) | `php -v` |
| Extensiones | `openssl`, `pdo_mysql`, `mbstring`, `zip`, `json` | `php -m` |
| Cifrado | `aes-256-gcm` disponible en OpenSSL | `php bin/console.php doctor` |
| Base de datos | MySQL 5.7+ / MariaDB 10.3+ | `mysql --version` |
| Servidor web | Apache con `mod_rewrite` y `mod_headers`, o Nginx | |

`php bin/console.php doctor` verifica todo lo anterior de una sola vez.

---

## 1.2 Instalación

### Paso 1 — Colocar el código

El proyecto debe quedar en una ruta donde **sólo `public/` sea accesible por
HTTP**. En XAMPP:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/credencial
```

### Paso 2 — Configuración

```bash
cp .env.example .env
```

Editar `.env` con los datos de la base de datos y la URL de la aplicación:

```ini
APP_URL=http://localhost/credencial/public
APP_BASE_PATH=/credencial/public
DB_DATABASE=credenciales_corp
DB_USERNAME=root
DB_PASSWORD=
# En XAMPP para macOS suele ser necesario el socket:
DB_SOCKET=/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock
```

### Paso 3 — Generar la clave maestra

```bash
php bin/console.php key:generate
```

Genera `APP_MASTER_KEY` (32 bytes aleatorios en base64) y `APP_PEPPER`.

> ### ⚠️ La clave maestra es irremplazable
>
> Todos los secretos del sistema se cifran con claves derivadas de
> `APP_MASTER_KEY`. **Si se pierde, ninguna contraseña almacenada puede
> recuperarse: ni el administrador, ni el proveedor, ni el desarrollador.**
>
> Guarde una copia fuera del servidor (caja fuerte, gestor de secretos
> corporativo, sobre sellado en custodia). El comando no sobrescribe una clave
> existente precisamente para evitar una pérdida accidental.

### Paso 4 — Crear la base de datos y el superadministrador

```bash
php bin/console.php install
```

Crea la base de datos si no existe, aplica `database/schema.sql` (29 tablas) y
`database/seed.sql` (42 permisos, 4 roles, 12 categorías, 18 parámetros), inicia
el llavero de cifrado y solicita los datos del superadministrador.

Al terminar imprime **una única vez** la contraseña temporal. Anótela: deberá
cambiarla en el primer ingreso y configurar la verificación en dos pasos.

### Paso 5 — Verificar

```bash
php bin/console.php doctor
```

---

## 1.3 Permisos de archivos

Éste es el punto que más se descuida y el que más expone el sistema.

```bash
# El .env contiene la clave maestra: sólo el usuario del servidor web debe leerlo
sudo chown www-data:www-data .env      # en XAMPP macOS: daemon:daemon
sudo chmod 400 .env

# storage/ debe ser escribible por el servidor web y por nadie más
sudo chown -R www-data:www-data storage
sudo chmod -R 750 storage
```

> En una instalación XAMPP de escritorio, Apache corre como `daemon` mientras
> los archivos pertenecen a su usuario. Sin `sudo` no es posible dar acceso
> exclusivo, por lo que `doctor` avisará de ello y mostrará el comando exacto a
> ejecutar. **En producción esto no es opcional.**

---

## 1.4 Servidor web

### Apache

El proyecto incluye los `.htaccess` necesarios. Requiere `AllowOverride All`
sobre el directorio del proyecto y los módulos `rewrite` y `headers`.

Configuración recomendada como VirtualHost propio (mejor que un subdirectorio,
porque el código queda fuera del `DocumentRoot`):

```apache
<VirtualHost *:443>
    ServerName credenciales.empresa.local
    DocumentRoot /ruta/al/proyecto/public

    <Directory /ruta/al/proyecto/public>
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile    /ruta/certificado.crt
    SSLCertificateKeyFile /ruta/clave.key

    ErrorLog  /var/log/apache2/credenciales-error.log
    CustomLog /var/log/apache2/credenciales-access.log combined
</VirtualHost>
```

Con VirtualHost propio, en `.env`:

```ini
APP_URL=https://credenciales.empresa.local
APP_BASE_PATH=
SESSION_SECURE=true
```

### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name credenciales.empresa.local;
    root /ruta/al/proyecto/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Nada fuera de public/ debe ser servido
    location ~ /\.(env|git) { deny all; }
}
```

---

## 1.5 Puesta en producción

Lista de verificación antes de abrir el sistema a los usuarios:

- [ ] **HTTPS obligatorio** y `SESSION_SECURE=true` en `.env`
      (sin esto la cookie de sesión viaja en claro).
- [ ] `APP_DEBUG=false` y `APP_ENV=production`.
- [ ] `.env` con propietario del servidor web y permisos `400`.
- [ ] `storage/` escribible sólo por el servidor web.
- [ ] Copia de seguridad de `APP_MASTER_KEY` en custodia, **separada** de las
      copias de la base de datos.
- [ ] Usuario de base de datos dedicado, no `root`, con permisos únicamente
      sobre la base del sistema.
- [ ] MFA activado para todos los perfiles administrativos.
- [ ] Tareas programadas configuradas (siguiente apartado).
- [ ] `php bin/console.php doctor` sin errores.
- [ ] `php tests/run.php` con 209/209.

---

## 1.6 Tareas programadas

```cron
# Alertas: credenciales vencidas, sin responsable, accesos anómalos…
0 7 * * *   cd /ruta/al/proyecto && php bin/console.php alerts:run >> storage/logs/cron.log 2>&1

# Mantenimiento: purga archivos exportados vencidos, sesiones y limitadores
*/10 * * * * cd /ruta/al/proyecto && php bin/console.php maintenance >> storage/logs/cron.log 2>&1
```

`maintenance` es el que garantiza que **ningún Excel con contraseñas quede
abandonado en el servidor**: elimina del disco todo archivo cuya ventana de
vigencia haya expirado.

---

## 1.7 Copias de seguridad

```bash
# Base de datos (contiene los secretos CIFRADOS)
mysqldump --single-transaction --routines credenciales_corp | gzip > backup-$(date +%F).sql.gz
```

Reglas:

1. La copia de la base de datos **no sirve de nada sin `APP_MASTER_KEY`**. Eso
   es deliberado: un respaldo robado no revela credenciales.
2. Por la misma razón, **guarde la clave maestra en un lugar distinto** al de
   los respaldos, o perderá la capacidad de restaurar.
3. Cifre los respaldos y restrinja su acceso igual que a la base de datos viva.

Restauración:

```bash
gunzip < backup-2026-09-08.sql.gz | mysql credenciales_corp
# Restaure el mismo .env (misma APP_MASTER_KEY) o los secretos serán ilegibles.
```

---

## 1.8 Rotación de la clave de cifrado

```bash
php bin/console.php key:rotate
```

Crea una nueva versión en el llavero (`encryption_keys`) y re-cifra todos los
secretos. Las versiones anteriores quedan marcadas como retiradas. Realice una
copia de seguridad **antes** de ejecutarlo.
