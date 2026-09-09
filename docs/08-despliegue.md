# 8. Despliegue en Dokploy

## 8.1 Respuesta corta a las tres preguntas

| Pregunta | Respuesta |
|---|---|
| ¿MySQL o PostgreSQL? | **MySQL o MariaDB.** El sistema está escrito sobre MySQL y no es intercambiable sin reescribir la capa de datos. |
| ¿Migración o importar el `.sql` a mano? | **Migración.** `php bin/console.php migrate` aplica lo pendiente. No hay que importar nada manualmente; en Docker se ejecuta solo al arrancar. |
| ¿Qué monto en el servidor? | Un servicio **Compose** en Dokploy con tres contenedores: `app` (PHP-Apache), `db` (MariaDB) y `cron` (tareas programadas). |

---

## 8.2 Por qué MySQL y no PostgreSQL

No es una preferencia: el código usa sintaxis específica de MySQL en unos 20 puntos.

- `ENGINE=InnoDB`, `AUTO_INCREMENT`, comillas invertidas
- `INSERT … ON DUPLICATE KEY UPDATE` (catálogos y asignaciones)
- `REPLACE INTO` (limitador de frecuencia, secretos MFA)
- `INSERT IGNORE`
- `FULLTEXT KEY` en `systems` y `credentials`
- `DATE_ADD` / `DATE_SUB` / `DATEDIFF` / `CURDATE`
- `GROUP_CONCAT`, `FIELD()`, `IF()`
- `VARBINARY` para criptogramas, nonces y claves envueltas
- `SET FOREIGN_KEY_CHECKS`

Migrar a PostgreSQL exigiría reescribir las dos migraciones y revisar los nueve repositorios: es trabajo real, no un cambio de cadena de conexión. Si en algún momento hace falta, dígamelo y se plantea como tarea propia.

**Versión recomendada:** MariaDB 11.4 (la que trae el `docker-compose.yml`) o MySQL 8.0.

---

## 8.3 Cómo funcionan las migraciones

```
database/migrations/
├── 0001_esquema_inicial.sql        29 tablas
└── 0002_datos_de_referencia.sql    41 permisos, 4 roles, 12 categorías, 18 parámetros
```

Cada archivo se aplica **una sola vez**, en orden numérico, y queda anotado en la
tabla `schema_migrations` con su suma de verificación SHA-256.

```bash
php bin/console.php migrate          # aplica lo pendiente
php bin/console.php migrate:status   # qué está aplicado y qué falta
```

Es **idempotente**: ejecutarlo dos veces no hace nada la segunda vez. Por eso el
contenedor puede lanzarlo en cada arranque sin riesgo.

### Para un cambio de esquema futuro

Nunca edite una migración ya aplicada — `migrate:status` lo detectaría y avisaría
de que los entornos han divergido. Cree una nueva:

```bash
cat > database/migrations/0003_agrega_campo_x.sql <<'SQL'
ALTER TABLE credentials ADD COLUMN campo_x VARCHAR(100) NULL AFTER observations;
SQL
```

Al desplegar, el contenedor la aplicará solo.

---

## 8.4 Preparación previa (una vez)

### 1. Generar las claves criptográficas

En su equipo, **no en el servidor**:

```bash
php -r 'echo "APP_MASTER_KEY=", base64_encode(random_bytes(32)), PHP_EOL;'
php -r 'echo "APP_PEPPER=",     base64_encode(random_bytes(32)), PHP_EOL;'
```

> **La `APP_MASTER_KEY` es irremplazable.** Con ella se descifran todos los
> secretos. Si la pierde, ninguna contraseña almacenada se puede recuperar.
> Guárdela en un gestor de secretos o en custodia física, **separada de los
> respaldos de la base de datos** — juntas anulan la protección.

### 2. Apuntar el dominio

Un registro `A` de `credenciales.suempresa.com` hacia la IP del servidor Dokploy.

---

## 8.5 Montaje en Dokploy

### Paso 1 — Crear el proyecto

*Projects → Create Project* → nombre, por ejemplo `credenciales`.

### Paso 2 — Crear el servicio Compose

*Create Service → **Compose***

| Campo | Valor |
|---|---|
| Provider | GitHub |
| Repository | `DilanZapata/credenciales-ch` |
| Branch | `main` |
| Compose Path | `./docker-compose.yml` |

### Paso 3 — Variables de entorno

En *Environment*, pegue esto sustituyendo los valores:

```ini
APP_URL=https://credenciales.suempresa.com
APP_ORGANIZATION=Mi Empresa
APP_TIMEZONE=America/Bogota

APP_MASTER_KEY=<la que generó en el paso 1>
APP_PEPPER=<la que generó en el paso 1>

DB_DATABASE=credenciales_corp
DB_USERNAME=credenciales
DB_PASSWORD=<contraseña larga y aleatoria>
DB_ROOT_PASSWORD=<otra contraseña larga y aleatoria>
```

### Paso 4 — Dominio

En *Domains*, sobre el servicio `app`:

| Campo | Valor |
|---|---|
| Host | `credenciales.suempresa.com` |
| Container Port | **`80`** — no deje el 3000 que trae por defecto |
| HTTPS | **activado** |
| Certificate | Let's Encrypt |

> HTTPS no es opcional. Sin él la cookie de sesión viaja en claro y el sistema
> deja de ser seguro. `SESSION_SECURE=true` ya viene fijado en el compose.

### Paso 5 — Desplegar

*Deploy*. En los registros verá:

```
==> Verificando la clave maestra
==> Esperando a la base de datos (db:3306)
    Base de datos disponible.
==> Aplicando migraciones
Migraciones pendientes: 2
✔ 0001_esquema_inicial.sql  (32 sentencias)
✔ 0002_datos_de_referencia.sql  (9 sentencias)
==> Diagnostico
==> Listo. Iniciando Apache.
```

### Paso 6 — Crear el superadministrador

Una sola vez, desde *Terminal* en el servicio `app` (o por SSH):

```bash
php bin/console.php install
```

Detecta que las migraciones ya están aplicadas y solo pide los datos del
superadministrador. **Anote la contraseña temporal: se muestra una única vez.**

### Paso 7 — Verificar

```bash
php bin/console.php doctor
php bin/console.php migrate:status
```

Entre al dominio, cambie la contraseña y configure la verificación en dos pasos.

---

## 8.6 Qué hace cada contenedor

| Servicio | Función | Expuesto |
|---|---|---|
| `app` | PHP 8.2 + Apache, `DocumentRoot` en `public/` | Sí, vía Traefik |
| `db` | MariaDB 11.4 | **No.** Solo la alcanza `app` por la red interna |
| `cron` | `maintenance` cada 10 min y `alerts:run` a las 07:00 | No |

El contenedor `cron` es el que garantiza que **ningún Excel con contraseñas quede
abandonado**: barre `storage/exports/` por antigüedad.

### Volúmenes

| Volumen | Contenido | Respaldar |
|---|---|---|
| `scgca_db` | Base de datos (secretos cifrados, auditoría) | **Sí, a diario** |
| `scgca_storage` | Logs y exportaciones temporales | No es crítico |

---

## 8.7 Endurecimiento aplicado en la imagen

- `DocumentRoot` en `public/`; el resto del proyecto está denegado en Apache.
- El `.env` **se borra de la imagen**: la configuración entra por variables de entorno.
- `storage/` con propietario `www-data` y permisos `750`.
- Archivos PHP en `640`, directorios en `750`.
- `expose_php=Off`, `display_errors=Off`, `ServerTokens Prod`, `ServerSignature Off`.
- `mod_autoindex` deshabilitado.
- OPcache con `validate_timestamps=0` (rendimiento; requiere redesplegar para que un cambio de código surta efecto, que es justo lo que se quiere en producción).
- El puerto de la base de datos no se publica.

---

## 8.8 Respaldos

```bash
# Desde el servidor, dentro del contenedor db
docker exec <contenedor-db> mariadb-dump -u root -p"$DB_ROOT_PASSWORD" \
  --single-transaction credenciales_corp | gzip > backup-$(date +%F).sql.gz
```

Tres reglas:

1. El respaldo **no sirve sin `APP_MASTER_KEY`**. Es deliberado: un volcado robado no revela contraseñas.
2. Por lo mismo, **guarde la clave en otro sitio** o no podrá restaurar.
3. Cifre el respaldo y restrinja su acceso igual que a la base viva.

---

## 8.9 Actualizar el sistema

```bash
git add -A && git commit -m "descripción del cambio" && git push
```

Dokploy redespliega. El contenedor aplica solas las migraciones nuevas. Si añadió
una migración, compruebe después:

```bash
php bin/console.php migrate:status
```

---

## 8.10 Problemas frecuentes

| Síntoma | Causa | Solución |
|---|---|---|
| `APP_MASTER_KEY no esta definida` y el contenedor no arranca | Falta la variable en Dokploy | Añádala en *Environment* y redespliegue |
| Entra pero cierra sesión al instante | Falta HTTPS con `SESSION_SECURE=true` | Active el dominio con TLS en Dokploy |
| `419 La sesion del formulario expiro` | `APP_URL` no coincide con el dominio real | Corrija `APP_URL` |
| Los enlaces apuntan a `/credencial/public/...` | `APP_BASE_PATH` con valor | Debe ir **vacío** con dominio propio |
| `No fue posible conectar con la base de datos` | Contraseña distinta entre `app` y `db` | `DB_PASSWORD` debe ser la misma para ambos |
| Se ve la IP real como `172.x` en la auditoría | Falta confiar en el proxy | `APP_TRUST_PROXY=true` (ya viene en el compose) |
| **`404 page not found` con certificado «TRAEFIK DEFAULT CERT»** | Traefik no tiene ninguna ruta para ese host: o el servicio `app` no está en la red `dokploy-network`, o el contenedor no llegó a arrancar | El compose ya declara `dokploy-network`. Compruebe en *Containers* que `app` está **running** y revise los *Logs* |
| **502 Bad Gateway** | El puerto del dominio no coincide con el del contenedor | *Domains* → Container Port = **80** |
| El contenedor arranca y se apaga solo | Falta `APP_MASTER_KEY`, o falta una extensión de PHP | El entrypoint aborta a propósito. Lea el motivo en *Logs* |
| `Unable to load dynamic library 'zip'` | Imagen construida con caché de una versión defectuosa | *Deploy* con **Clean Cache** activado |
| `Could not open input file: bin/console.php` | La terminal abre en `/` | `cd /var/www/html` antes del comando |
