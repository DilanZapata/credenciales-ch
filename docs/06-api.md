# 6. API v1

Base: `{APP_URL}/api/v1`

## 6.1 Autenticación y convenios

La API usa **la misma sesión que la interfaz web**: cookie `scgca_session`
(`HttpOnly`, `Secure`, `SameSite=Strict`). No hay tokens de larga duración
precisamente porque cualquier credencial permanente sería un activo más que
proteger.

Toda petición que modifica estado debe incluir la cabecera:

```
X-CSRF-Token: <token de la sesión>
```

Todos los endpoints verifican **autenticación y autorización en el servidor**.
El frontend no participa en la decisión.

### Códigos de estado

| Código | Significado |
|---|---|
| 200 / 201 | Correcto |
| 400 | Solicitud inválida |
| 401 | Sesión inexistente o expirada |
| 403 | Autenticado pero sin autorización |
| 404 | No existe **o está fuera de su alcance** (no se distingue a propósito) |
| 405 | Método no permitido |
| 419 | Token CSRF inválido o ausente |
| 422 | Error de validación (incluye `errors` por campo) |
| **423** | **Requiere reautenticación** (step-up). Incluye `reauth_required: true` |
| 429 | Límite de frecuencia superado |
| 500 | Error interno (sin detalles internos en la respuesta) |

---

## 6.2 Sesión

### `GET /api/v1/sesion`
Estado de la sesión actual.

```json
{
  "authenticated": true,
  "user": { "id": 3, "name": "Maria Gomez", "national_id": "987654321", "roles": ["ADMIN"] },
  "expires_in_seconds": 1740,
  "reauth_valid": true
}
```

### `POST /api/v1/reauth`
Reautenticación previa a una operación sensible.

```json
{ "password": "…", "code": "123456" }
```

`code` sólo es necesario si el usuario tiene MFA activo. Respuesta `200` con la
vigencia del step-up, o `401` con un mensaje uniforme (no distingue entre
contraseña y código incorrectos).

---

## 6.3 Credenciales — metadatos

> Ninguno de estos endpoints devuelve contraseñas. Nunca.

### `GET /api/v1/credenciales`
Permiso: `credentials.view`. Un usuario sin `credentials.view_all` sólo obtiene
lo que tiene asignado.

Parámetros: `q`, `category_id`, `system_id`, `company_id`, `department_id`,
`status`, `assigned_user_id`, `sort`, `direction`, `page`, `per_page`.

```json
{
  "items": [
    {
      "id": 1, "name": "Usuario de contabilidad", "system_name": "Sistema Contable",
      "category_name": "Sistemas", "username": "contabilidad",
      "status": "active", "rotation_state": "ok", "assignment_count": 2,
      "has_secret": true
    }
  ],
  "total": 8, "page": 1, "per_page": 25, "pages": 1
}
```

`has_secret` indica que **existe** un secreto, no lo entrega.

### `GET /api/v1/credenciales/{id}`
Permiso: `credentials.view`. Ficha completa sin secretos ni datos de
recuperación. Registra la consulta en la auditoría.

### `POST /api/v1/credenciales`
Permiso: `credentials.create`. El campo `password` se cifra antes de tocar la
base de datos y no vuelve a aparecer en ninguna respuesta.

### `PUT /api/v1/credenciales/{id}`
Permiso: `credentials.update`. **No permite cambiar la contraseña**: para eso
existe la rotación, que conserva el historial y exige motivo.

### `DELETE /api/v1/credenciales/{id}`
Permiso: `credentials.delete`. Baja **lógica**: archiva, revoca las asignaciones
y conserva historial y auditoría.

### `GET /api/v1/credenciales/{id}/historial`
Permiso: `history.view`. Versiones de secreto (metadatos), cambios y, si tiene
`audit.view`, los accesos al secreto.

---

## 6.4 Credenciales — secretos

> Estos son los **únicos** endpoints que devuelven texto en claro.

### `POST /api/v1/credenciales/{id}/secreto`
Permiso: `credentials.secret.view` (o `.copy` con `"copy": true`).

Barreras: permiso → asignación vigente → permiso fino de la asignación →
límite de frecuencia (60/5 min) → **step-up**.

```json
{ "field": "password", "copy": false }
```

```json
{ "secret": "…", "field": "password", "version": 2, "is_current": true, "ttl": 30 }
```

Si falta el step-up: `423` con `reauth_required: true`.
Cabecera de respuesta: `Cache-Control: no-store, private`.

### `POST /api/v1/credenciales/{id}/secreto/historial/{version}`
Permiso: `credentials.secret.history`. Revela una contraseña **anterior**.
Genera su propio registro de auditoría.

### `GET /api/v1/credenciales/{id}/recuperacion`
Permiso: `credentials.recovery.view` + step-up. Devuelve correo, teléfono,
usuario y notas de recuperación. Se audita como `recovery.viewed`.

### `POST /api/v1/credenciales/{id}/rotar`
Permiso: `credentials.rotate` + step-up.

```json
{ "password": "…", "reason": "Rotacion periodica", "rotation_period_days": 90 }
```

Conserva la contraseña anterior como versión histórica cifrada y rechaza repetir
la vigente (comparación por huella HMAC, sin descifrar).

---

## 6.5 Asignaciones

### `GET /api/v1/credenciales/{id}/asignaciones`
Permiso: `credentials.assign`.

### `POST /api/v1/credenciales/{id}/asignar`
Permiso: `credentials.assign`.

```json
{
  "user_id": 5,
  "can_view_secret": true,
  "can_copy_secret": true,
  "can_view_recovery": false,
  "expires_at": "2027-01-31"
}
```

### `DELETE /api/v1/credenciales/{id}/asignaciones/{userId}`
Permiso: `credentials.revoke`. Efecto inmediato.

---

## 6.6 Utilidades

### `POST /api/v1/generador`
Permiso: `credentials.create`. Generador criptográficamente seguro.

```json
{ "length": 24, "upper": true, "lower": true, "digits": true,
  "symbols": true, "exclude_ambiguous": true }
```

La contraseña generada **no se registra en ningún log ni auditoría**.

### `POST /api/v1/fortaleza`
Evalúa la robustez de una contraseña sin almacenarla.

### `GET /api/v1/buscar?q=…`
Búsqueda global limitada al alcance del usuario. Mínimo 2 caracteres.

### `GET /api/v1/notificaciones` · `GET /api/v1/alertas`

---

## 6.7 Ejemplo completo

```bash
BASE=https://credenciales.empresa.local/api/v1
JAR=/tmp/cookies.txt

# 1. Iniciar sesión por el formulario web (la API comparte la sesión)
curl -s -c $JAR https://credenciales.empresa.local/entrar > /tmp/login.html
CSRF=$(grep -o 'name="_csrf" value="[a-f0-9]*"' /tmp/login.html | head -1 | sed 's/.*value="//;s/"//')
curl -s -b $JAR -c $JAR -X POST \
     -d "identifier=maria.gomez&password=***&_csrf=$CSRF" \
     https://credenciales.empresa.local/entrar

# 2. Listar credenciales (sin contraseñas, por diseño)
curl -s -b $JAR "$BASE/credenciales?q=contable"

# 3. Confirmar identidad (step-up)
curl -s -b $JAR -X POST -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
     -d '{"password":"***"}' "$BASE/reauth"

# 4. Revelar el secreto (queda auditado)
curl -s -b $JAR -X POST -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
     -d '{"field":"password"}' "$BASE/credenciales/1/secreto"
```
