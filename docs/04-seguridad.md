# 4. Seguridad: políticas, controles y auditoría realizada

Este documento describe el modelo de seguridad del sistema, los controles
implementados, la auditoría de seguridad efectuada antes de la entrega y las
limitaciones conocidas.

---

## 4.1 Principio rector

> **La información de una credencial y el secreto de una credencial son cosas
> distintas y merecen controles distintos.**

Los metadatos (sistema, usuario, URL, responsable, fechas) viven en la tabla
`credentials` y se consultan con los controles de acceso habituales.

Los secretos viven **exclusivamente** en `credential_secrets`, cifrados, y sólo
se obtienen a través de una operación específica que atraviesa cinco barreras.

Consecuencia práctica: `GET /api/v1/credenciales` no devuelve contraseñas
**nunca**, ni siquiera para el superadministrador. No es una omisión de la
interfaz: es la arquitectura.

---

## 4.2 Arquitectura criptográfica

### Cifrado de sobre en tres niveles

```
APP_MASTER_KEY  (32 bytes aleatorios, vive sólo en .env, fuera del webroot)
      │
      │  HKDF-SHA256(master, salt = encryption_keys.salt,
      │              info = "SCGCA:KEK:v{versión}")
      ▼
KEK   (clave de cifrado de claves, por versión del llavero)
      │  se deriva en memoria; JAMÁS se persiste
      │
      │  AES-256-GCM(KEK) ──► wrapped_dek
      ▼
DEK   (clave de datos, ÚNICA POR SECRETO, 32 bytes aleatorios)
      │
      │  AES-256-GCM(DEK, nonce, AAD)
      ▼
CIPHERTEXT  ──►  tabla credential_secrets
```

| Elemento | Algoritmo / tamaño |
|---|---|
| Cifrado de secretos | AES-256-GCM (cifrado autenticado) |
| Nonce | 96 bits, aleatorio y único por operación |
| Etiqueta de autenticación | 128 bits |
| Derivación de claves | HKDF-SHA256 con sal por versión |
| Huella de secreto | HMAC-SHA256 con clave derivada |
| Contraseñas de acceso | bcrypt (coste 12) sobre prehash HMAC-SHA256 + pimienta; Argon2id si está disponible |
| Tokens de sesión | 256 bits del CSPRNG del sistema; en la base sólo su SHA-256 |

### Qué garantiza este diseño

1. **Un volcado de la base de datos no revela ninguna contraseña.** Sin el
   `.env` el material es indescifrable. Es lo que convierte un robo de respaldo
   en un incidente mucho menor.
2. **Una DEK por secreto.** Comprometer una clave de datos no compromete las
   demás, y la reutilización de nonce es imposible en la práctica.
3. **El AAD ata cada criptograma a su ubicación lógica**
   (`SCGCA|credential|{id}|{campo}|v{versión}`). Mover una fila de una
   credencial a otra —o cambiarle la versión— invalida la autenticación GCM y
   el descifrado falla. La integridad no es opcional: es parte del algoritmo.
4. **Rotación posible sin reinstalar.** `key:rotate` crea una versión nueva del
   llavero y re-cifra los secretos; las anteriores quedan marcadas como
   retiradas.

### Por qué cifrado reversible y no hash

El sistema debe poder **mostrar** la contraseña a un usuario autorizado, luego
un hash irreversible no sirve. Las contraseñas **de acceso al propio sistema**,
en cambio, sí se guardan como hash irreversible: nunca hay que recuperarlas.

La pimienta (`APP_PEPPER`) implica que la tabla `users` por sí sola no basta
para atacar los hashes por fuerza bruta.

---

## 4.3 Autenticación

| Control | Implementación |
|---|---|
| Identificador | Usuario, correo **o cédula**; la cédula identifica pero **nunca autentica sola** |
| Contraseña | Obligatoria siempre |
| Segundo factor | TOTP RFC 6238 (SHA-1, 6 dígitos, 30 s, ventana ±1) + 10 códigos de respaldo de un solo uso |
| MFA obligatorio | Por rol (`requires_mfa`) o por usuario; mientras no se configure, la sesión sólo alcanza la pantalla de alta |
| Enumeración de cuentas | Mensaje idéntico para usuario inexistente y contraseña incorrecta; se ejecuta un hash señuelo para igualar los tiempos |
| Fuerza bruta | Limitador por IP (20/5 min) y por identificador (10/5 min) **más** bloqueo de cuenta a los 5 intentos durante 15 min |
| Fijación de sesión | El identificador de sesión se regenera al superar el MFA |
| Recuperación | Enlace de un solo uso, 30 min de vigencia, hash del token en base de datos; **nunca se envía la contraseña actual** |
| Re-hash oportunista | Si cambian los parámetros de coste, el hash se actualiza en el siguiente acceso correcto |

---

## 4.4 Autorización

Control de acceso basado en roles **con permisos granulares**: 42 permisos
agrupados en 9 áreas, combinables en roles arbitrarios.

```
permisos efectivos = (permisos de sus roles) ∪ (concesiones individuales)
                     − (denegaciones individuales)
```

La denegación individual siempre gana. Es el principio de mínimo privilegio
aplicado literalmente.

### Alcance de datos

Un usuario sin `credentials.view_all` (el rol Consultor) sólo ve lo que tiene
asignado. **El filtro se aplica en la consulta SQL**, no en PHP:

```sql
JOIN credential_assignments ca
  ON ca.credential_id = c.id
 AND ca.user_id = :scope_user
 AND ca.is_active = 1
 AND ca.revoked_at IS NULL
 AND (ca.expires_at IS NULL OR ca.expires_at > NOW())
```

Un intento de IDOR (`/credenciales/999`) devuelve **404**, no 403: no se revela
siquiera que el recurso exista. El intento queda auditado.

### Barreras contra la escalada de privilegios

1. Nadie puede asignar un rol de nivel **igual o superior** al suyo.
2. Nadie puede conceder un permiso que **él mismo no posee**.
3. Un administrador no puede desactivarse ni restablecerse a sí mismo.
4. Cambiar roles o permisos **cierra todas las sesiones** del usuario afectado.

### Defensa en profundidad

Cada operación se autoriza **dos veces**: en el middleware de ruta y de nuevo
en el servicio. Ninguna capa confía en la anterior. Si mañana alguien añade una
ruta y olvida el `perm:`, el servicio sigue negando.

---

## 4.5 Acceso a un secreto: las cinco barreras

`POST /api/v1/credenciales/{id}/secreto` atraviesa, en orden:

1. **Permiso funcional** (`credentials.secret.view` / `.copy` / `.history`).
2. **Alcance de datos**: la credencial debe existir dentro de su ámbito.
3. **Permisos finos de la asignación**: un consultor puede tener permiso de
   ver pero no de copiar sobre una credencial concreta.
4. **Limitador de extracción masiva**: 60 revelados por usuario cada 5 minutos;
   superarlo genera un evento de seguridad de severidad alta.
5. **Reautenticación reciente (step-up)**: la contraseña debe haberse
   confirmado en los últimos 10 minutos (configurable). Sin ella la respuesta
   es `423 Locked` y el cliente muestra el diálogo de confirmación.

Y sólo entonces se descifra, se registra en `secret_access_log` y en
`audit_logs`, y se devuelve.

En la interfaz, el secreto revelado **se oculta solo a los 30 segundos**, y
también al cambiar de pestaña. El portapapeles se limpia a los 45 segundos.

---

## 4.6 Controles frente al OWASP Top 10

| Riesgo | Control |
|---|---|
| **A01 Broken Access Control** | Autorización doble (middleware + servicio), alcance en SQL, 404 ante IDOR, límites anti-escalada, auditoría de cada denegación |
| **A02 Cryptographic Failures** | AES-256-GCM con envelope encryption, HKDF, bcrypt+pimienta, HTTPS + HSTS, cookies `Secure`/`HttpOnly`/`SameSite=Strict` |
| **A03 Injection** | 100 % sentencias preparadas sin emulación; identificadores dinámicos (ORDER BY) contra lista blanca; escape de comodines en `LIKE`; neutralización de fórmulas en el Excel generado |
| **A04 Insecure Design** | Separación información/secreto, step-up, mínimo privilegio, baja lógica, trazabilidad completa |
| **A05 Security Misconfiguration** | Sólo `public/` expuesto; `.htaccess` de denegación en cada directorio interno; cabeceras de seguridad; `display_errors` off; `doctor` verifica la instalación |
| **A06 Componentes vulnerables** | **Cero dependencias de terceros.** Sin Composer en tiempo de ejecución: no hay árbol de dependencias que parchear |
| **A07 Identification & Auth Failures** | MFA, bloqueo, limitador, rotación de sesión, doble caducidad, cierre remoto |
| **A08 Software & Data Integrity** | GCM autentica cada criptograma; el AAD lo ata a su ubicación; CSP con nonce |
| **A09 Logging & Monitoring** | Auditoría de todo evento crítico + registro dedicado de acceso a secretos + eventos de seguridad + alertas |
| **A10 SSRF** | El sistema no realiza peticiones salientes a URLs suministradas por el usuario |

---

## 4.7 XSS y CSRF

### XSS

- Toda salida pasa por `e()` (`htmlspecialchars` con `ENT_QUOTES`).
- CSP con **nonce por petición** y sin `unsafe-inline` en `script-src`: aunque
  se lograra inyectar HTML, el navegador no ejecutaría el script.
- El JavaScript del cliente no usa `innerHTML` con datos del servidor: construye
  nodos y asigna `textContent`.
- `X-Content-Type-Options: nosniff` y `X-Frame-Options: DENY`.

### CSRF — tres capas

1. **Token sincronizador** ligado a la fila de la sesión, comparado con
   `hash_equals` (tiempo constante). En formularios públicos (acceso,
   recuperación) se usa el patrón *double submit cookie*.
2. **Verificación de `Origin`/`Referer`** contra el host propio. Si están
   ausentes o son opacos (`null`, que envían los contextos aislados), se acepta
   apoyándose únicamente en el token, según la recomendación de OWASP: la
   comprobación de origen es defensa en profundidad, no el control primario.
3. **Cookie `SameSite=Strict`**, que impide que el navegador la envíe en
   peticiones originadas en otro sitio.

Todo fallo de CSRF se audita como evento **crítico**.

---

## 4.8 Gestión de sesiones

Sesiones propias respaldadas en base de datos, no las nativas de PHP. Razones:

- se necesita **listar y cerrar remotamente** sesiones de otros usuarios;
- se requiere caducidad doble (inactividad + absoluta) y marca de step-up por
  sesión;
- los archivos de sesión de PHP en `/tmp` son un activo sensible adicional que
  se prefiere no tener.

| Control | Valor |
|---|---|
| Cookie | `HttpOnly`, `Secure` (con HTTPS), `SameSite=Strict`, sin expiración de disco |
| Almacenamiento | En la tabla sólo el **SHA-256** del token; robar la base no permite suplantar |
| Caducidad por inactividad | 30 min (configurable) |
| Caducidad absoluta | 8 h (configurable) |
| Rotación | Al superar el MFA |
| Revocación | Manual (remota), por cambio de contraseña, por cambio de roles, por baja del usuario |
| Verificación por petición | Se comprueba en cada petición que el usuario siga activo |

---

## 4.9 Protección de los archivos exportados

El Excel puede contener contraseñas reales; se trata como un activo de primer
nivel.

- Se escribe en `storage/exports/`, **fuera del webroot**: no existe URL que lo
  alcance.
- Nombre en disco aleatorio (UUID); el nombre visible **nunca revela contenido
  sensible**.
- Permisos `0600`.
- **Sólo el usuario que lo generó puede descargarlo**; el intento ajeno responde
  404, se audita y genera un evento de seguridad.
- **Se elimina del servidor inmediatamente tras la descarga.**
- Si nadie lo descarga, `maintenance` lo purga al expirar (15 min por defecto).
- **Barrido de huérfanos**: `maintenance` elimina además cualquier `.xlsx` del
  directorio cuya antigüedad supere la ventana de retención, exista o no un
  registro que lo respalde. Cubre el caso de una restauración de base de datos
  o de una generación interrumpida: ningún archivo con contraseñas queda
  abandonado en el servidor.
- Máximo 5 exportaciones con secretos por hora y por usuario.
- Se registra qué credenciales concretas viajaron en cada archivo, con una
  entrada individual en `secret_access_log` por cada secreto descifrado.
- El propio archivo incluye una hoja de portada con la trazabilidad y el aviso
  de confidencialidad.
- Las celdas que empiezan por `= + - @` se neutralizan con un apóstrofo para
  impedir la inyección de fórmulas al abrir el archivo.

---

## 4.10 Los secretos jamás salen por un canal indebido

Garantía explícita, verificada por pruebas automáticas:

| Canal | Control |
|---|---|
| **Logs técnicos** | `Logger::redact()` elimina recursivamente toda clave sensible (23 patrones) antes de serializar; los binarios y las cadenas largas se truncan |
| **Auditoría** | El contexto pasa por el mismo redactor antes de guardarse |
| **Historial** | Sólo almacena metadatos: fechas, autor, motivo, longitud y robustez. Nunca el valor |
| **Intentos de acceso** | Se guarda el identificador intentado, **nunca la contraseña** |
| **Respuestas de API** | Ningún endpoint devuelve secretos salvo el dedicado |
| **Mensajes de error** | En producción no se expone el mensaje interno, ni la traza, ni el SQL |
| **Correo** | `MailService` bloquea cualquier mensaje cuyo cuerpo tenga aspecto de contener un secreto |
| **Archivos temporales** | El CSV de importación se elimina en cuanto se lee |

---

## 4.11 Auditoría de seguridad realizada

Antes de la entrega se ejecutó una revisión sobre los puntos exigidos. La
verificación es **automatizada y reproducible**: `php tests/run.php` levanta una
base de datos independiente y ejecuta 209 comprobaciones a través del núcleo
HTTP real (enrutado + middleware + controladores + base de datos). Ninguna
prueba invoca servicios saltándose la capa de autorización.

| Área revisada | Comprobaciones | Resultado |
|---|---|---|
| SQL Injection | 5 cargas maliciosas en filtros y en `ORDER BY` | Neutralizadas; tablas intactas |
| XSS | Almacenado en nombre de credencial + verificación de CSP | Escapado; CSP con nonce |
| CSRF | Sin token, con token de otra sesión | Rechazado (419) y auditado |
| Broken Access Control | 8 rutas administrativas desde un consultor | 403 en todas, auditado |
| IDOR | Credencial ajena por URL y por API | 404 / 403 |
| Privilege Escalation | Auto-asignación de rol superior; conceder permiso propio inexistente | Bloqueado |
| Session Hijacking | Sólo el hash del token en base; rotación tras MFA | Verificado |
| Brute Force | 6 intentos fallidos; 25 peticiones seguidas | Cuenta bloqueada; limitador 429 |
| Credential Stuffing | Mensajes uniformes; hash señuelo | Sin enumeración |
| Exposición de secretos | Listado y detalle de API, HTML, logs, auditoría, intentos de acceso | Ninguna filtración |
| Logs con contraseñas | Búsqueda de 8 secretos reales en `audit_logs`, `login_attempts` y archivos de log | Cero coincidencias |
| Errores que exponen información | 404 y 500 en producción | Sin rutas, SQL ni trazas |
| CORS | Deshabilitado por defecto; lista blanca si se activa | Verificado |
| Cabeceras de seguridad | CSP, nosniff, DENY, no-referrer, no-store | Presentes |
| Gestión de sesiones | Caducidad, revocación remota, efecto inmediato | Verificado |
| Control de permisos | Matriz completa por rol y excepciones individuales | Verificado |
| Cifrado | Criptograma ≠ texto, AAD cruzado, manipulación, no determinismo | Verificado |
| Archivos abandonados | Huérfano antiguo vs. archivo dentro de vigencia | Purgado / conservado |
| Gestión de claves | Llavero versionado, rotación, derivación HKDF | Verificado |

**Resultado: 209/209.**

### Hallazgos corregidos durante la revisión

| Hallazgo | Corrección |
|---|---|
| `entity_id` de la auditoría era demasiado corto para un identificador de sesión: los cierres remotos **no quedaban registrados** | Columna ampliada a 64 y truncado defensivo en `AuditService` |
| El enrutador no interpretaba cuantificadores con llaves (`{64}`, `{36}`), dejando **inalcanzables** las rutas de cierre de sesión y de descarga de reportes | Corregida la expresión de análisis de marcadores |
| Un inicio de sesión completado con MFA quedaba auditado **sin nombre de actor** | Los datos del actor se pasan explícitamente |
| El `.env` con permisos `0600` del usuario de escritorio impedía al servidor web leer la clave maestra (error 500 opaco) | `doctor` lo detecta y muestra el comando exacto de corrección; documentado en la guía de instalación |
| Un `Origin: null` (contextos aislados) rompía formularios legítimos pese a un token CSRF válido | Se acepta el origen opaco apoyándose en el token, según OWASP |
| PDO sin emulación rechaza marcadores con nombre repetidos, provocando fallos intermitentes | La capa de datos los reescribe automáticamente a marcadores únicos |
| Un Excel con contraseñas podía quedar **abandonado indefinidamente** en `storage/exports/` si se perdía su fila en la base de datos | `maintenance` barre por antigüedad todo el directorio, con registro en el log |
| El permiso `EXPORTAR_REPORTES` estaba definido pero no se exigía en ninguna operación | `ExportService::generate()` lo requiere en toda generación |
| La política `security.password_expiry_days` estaba definida pero no se aplicaba | El middleware de autenticación fuerza el cambio cuando la contraseña caduca |
| La comprobación de origen CSRF caía en la cabecera `Host`, controlada por el cliente | El host esperado sale siempre de `APP_URL` |

---

## 4.12 Limitaciones conocidas y riesgos residuales

Conviene ser explícito sobre lo que este sistema **no** resuelve:

1. **Un administrador con MFA puede ver todas las contraseñas.** Es inherente a
   la función. El control no es impedirlo, sino que **cada consulta quede
   registrada** con usuario, fecha, hora e IP, y que exista un rol Auditor que
   puede revisar esos registros sin poder ver secretos.

2. **Quien controle el servidor controla los secretos.** Con acceso de root al
   equipo se puede leer el `.env` y descifrar la base. La protección es
   operativa: endurecer el servidor, restringir el acceso administrativo y
   vigilar. Un HSM o un gestor externo de claves (Vault, KMS) elevaría este
   nivel; la arquitectura de llavero versionado está preparada para ello.

3. **Sin HTTPS el sistema no es seguro.** La cookie de sesión y las contraseñas
   viajarían en claro. `SESSION_SECURE=true` y HSTS son obligatorios en
   producción.

4. **La memoria del proceso PHP contiene el secreto durante el descifrado.** Se
   sobrescriben las variables tras su uso, pero PHP no ofrece garantías fuertes
   de borrado en memoria.

5. **El portapapeles es del sistema operativo.** El sistema lo limpia a los 45
   segundos, pero otra aplicación puede leerlo antes.

6. **Durante la importación, el CSV con contraseñas en claro viaja en el
   formulario de confirmación.** No se guarda en el servidor y la página no se
   cachea, pero el dato existe en el navegador del administrador mientras dura
   el proceso.

7. **La auditoría es *append-only* por convención, no por tecnología.** Quien
   tenga acceso directo a la base de datos podría alterarla. Para un requisito
   de inalterabilidad estricta habría que replicar los registros a un destino
   de sólo escritura (syslog remoto, WORM).

8. **No hay copia de seguridad automática.** Debe configurarse en el servidor;
   la guía de instalación indica cómo y advierte de la separación entre el
   respaldo y la clave maestra.

---

## 4.13 Recomendaciones operativas

- Revisar semanalmente *Eventos de seguridad* y los accesos a secretos.
- Rotar las contraseñas críticas según el periodo configurado; el panel avisa.
- Revisar trimestralmente la matriz de roles y las asignaciones.
- Dar de baja a los empleados **el mismo día** de su salida.
- Mantener MFA obligatorio para todos los perfiles administrativos.
- Ejecutar `php bin/console.php doctor` después de cada actualización.
- Custodiar `APP_MASTER_KEY` fuera del servidor y separada de los respaldos.
