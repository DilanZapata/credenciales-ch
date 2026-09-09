# 2. Configuración

Hay dos niveles de configuración, deliberadamente separados:

| Nivel | Dónde | Quién lo cambia | Requiere |
|---|---|---|---|
| **Infraestructura** | `.env` y `config/*.php` | Administrador de sistemas | Acceso al servidor |
| **Políticas** | Tabla `settings`, pantalla *Configuración* | Superadministrador | Permiso `settings.manage` + reautenticación |

---

## 2.1 Variables de entorno (`.env`)

### Aplicación

| Variable | Valor por defecto | Descripción |
|---|---|---|
| `APP_NAME` | Sistema Corporativo… | Título visible |
| `APP_SHORT_NAME` | Credenciales | Nombre corto (pestaña, emisor TOTP) |
| `APP_ORGANIZATION` | Mi Empresa | Nombre de la organización |
| `APP_ENV` | production | Entorno |
| `APP_DEBUG` | false | **Nunca `true` en producción**: expone mensajes internos |
| `APP_URL` | http://localhost/credencial/public | URL base; se usa para validar el `Origin` |
| `APP_BASE_PATH` | /credencial/public | Prefijo de las rutas; vacío si hay VirtualHost propio |
| `APP_TIMEZONE` | America/Bogota | Zona horaria de fechas y auditoría |
| `APP_TRUST_PROXY` | false | Sólo `true` detrás de un proxy inverso de confianza |

> `APP_TRUST_PROXY=true` hace que el sistema crea en la cabecera
> `X-Forwarded-For`. Si se activa sin un proxy que la sobrescriba, **cualquiera
> puede falsificar su IP** en la auditoría y evadir el limitador por IP.

### Criptografía

| Variable | Descripción |
|---|---|
| `APP_MASTER_KEY` | 32 bytes en base64. Raíz de todo el cifrado de secretos. |
| `APP_PEPPER` | Pimienta del lado del servidor para el hash de las contraseñas de acceso. |

Ambas se generan con `php bin/console.php key:generate`.

La pimienta implica que un volcado de la tabla `users` **no basta** para atacar
los hashes por fuerza bruta: hace falta además el `.env`.

### Base de datos

| Variable | Descripción |
|---|---|
| `DB_HOST`, `DB_PORT` | Servidor MySQL/MariaDB |
| `DB_DATABASE` | Nombre de la base |
| `DB_USERNAME`, `DB_PASSWORD` | Usuario dedicado (no `root` en producción) |
| `DB_SOCKET` | Socket Unix; en XAMPP macOS suele ser necesario |

Permisos mínimos recomendados para el usuario de base de datos:

```sql
CREATE USER 'credenciales'@'localhost' IDENTIFIED BY '<clave larga y aleatoria>';
GRANT SELECT, INSERT, UPDATE, DELETE ON credenciales_corp.* TO 'credenciales'@'localhost';
-- Sólo durante la instalación o las migraciones:
-- GRANT CREATE, ALTER, INDEX, REFERENCES ON credenciales_corp.* TO 'credenciales'@'localhost';
```

### Sesiones

| Variable | Por defecto | Descripción |
|---|---|---|
| `SESSION_SECURE` | false | **`true` obligatorio con HTTPS**: marca la cookie como `Secure` |
| `SESSION_SAMESITE` | Strict | `Strict` protege frente a CSRF por navegación cruzada |

### CORS

| Variable | Por defecto | Descripción |
|---|---|---|
| `CORS_ENABLED` | false | La API se consume desde el mismo origen; manténgalo desactivado |
| `CORS_ORIGINS` | (vacío) | Lista blanca separada por comas si necesita habilitarlo |

Nunca se responde con comodín `*` junto a credenciales: sólo se aceptan
orígenes de la lista blanca exacta.

---

## 2.2 Políticas administrables (pantalla *Configuración*)

### Grupo `sesiones`

| Clave | Por defecto | Efecto |
|---|---|---|
| `security.session_idle_minutes` | 30 | Cierre por inactividad |
| `security.session_absolute_hours` | 8 | Vida máxima de la sesión, aunque haya actividad |
| `security.reauth_minutes` | 10 | Cuánto dura una reautenticación (step-up) |

### Grupo `acceso`

| Clave | Por defecto | Efecto |
|---|---|---|
| `security.max_login_attempts` | 5 | Intentos fallidos antes de bloquear la cuenta |
| `security.lockout_minutes` | 15 | Duración del bloqueo temporal |
| `security.mfa_required_admins` | 1 | Exige MFA a los roles marcados con `requires_mfa` |

### Grupo `politica`

| Clave | Por defecto | Efecto |
|---|---|---|
| `security.password_min_length` | 12 | Longitud mínima de la contraseña **de acceso al sistema** |
| `security.password_expiry_days` | 90 | Vigencia sugerida |
| `security.reauth_for_secret` | 1 | Reautenticar antes de revelar/copiar un secreto |
| `security.reauth_for_export` | 1 | Reautenticar antes de exportar con contraseñas |
| `credentials.default_rotation_days` | 90 | Periodo de rotación por defecto de las credenciales |

### Grupo `alertas`

| Clave | Por defecto | Efecto |
|---|---|---|
| `alerts.expiry_warning_days` | 15 | Antelación del aviso de vencimiento |
| `alerts.failed_login_threshold` | 10 | Intentos fallidos/hora que disparan alerta |

### Grupo `reportes`

| Clave | Por defecto | Efecto |
|---|---|---|
| `exports.retention_minutes` | 15 | Vida del archivo generado antes de purgarse |
| `exports.max_records` | 5000 | Tope de registros por exportación |

### Grupo `alertas` (correo)

| Clave | Por defecto | Efecto |
|---|---|---|
| `mail.enabled` | 0 | Habilita el envío de notificaciones por correo |
| `mail.from` | no-reply@empresa.local | Remitente |

> El correo **nunca transporta contraseñas**. `MailService` incluye un
> cortafuegos que descarta cualquier mensaje cuyo cuerpo tenga aspecto de
> contener un secreto, y la recuperación de cuenta envía siempre un enlace de
> un solo uso, jamás la contraseña actual.

Todo cambio en esta pantalla exige reautenticación y queda registrado en la
auditoría como evento de severidad **crítica**, con el detalle exacto de qué
claves cambiaron.

---

## 2.3 Cabeceras de seguridad (`config/security.php`)

Aplicadas a toda respuesta HTML:

```
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-…';
    style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none';
    base-uri 'self'; form-action 'self'; frame-ancestors 'none'
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: no-referrer
Cross-Origin-Opener-Policy: same-origin
Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=()
Cache-Control: no-store, no-cache, must-revalidate, private
Strict-Transport-Security: max-age=31536000; includeSubDomains   (sólo bajo HTTPS)
```

`script-src` usa **nonce por petición y no admite `unsafe-inline`**: aunque se
lograra inyectar HTML, el navegador no ejecutaría el script. Para las hojas de
estilo sí se admite el atributo `style` en línea, necesario para valores
calculados en servidor (barras de progreso, colores de categoría); la inyección
de CSS tiene un impacto mucho menor y no permite ejecutar código.

`Cache-Control: no-store` evita que una ficha con datos sensibles quede en la
caché del navegador tras cerrar sesión.
