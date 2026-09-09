# 5. Arquitectura y modelo de datos

## 5.1 Visión general

```
                    ┌──────────────────────────────────────────┐
  Navegador ──HTTPS─►│  public/index.php  (controlador frontal) │
                    └──────────────────┬───────────────────────┘
                                       ▼
                        ┌──────────────────────────────┐
                        │  Kernel                      │
                        │  enrutado + middleware       │
                        └──────────────┬───────────────┘
                                       ▼
        ┌──────────────────────────────────────────────────────┐
        │  Middleware (por ruta, declarativo)                   │
        │  security → cors → throttle → auth → csrf → perm:xxx  │
        └──────────────────────────┬───────────────────────────┘
                                   ▼
             ┌─────────────────────────────────────────┐
             │  Controladores (Web / Api)              │
             │  validan la entrada, no deciden permisos│
             └──────────────────┬──────────────────────┘
                                ▼
     ┌────────────────────────────────────────────────────────────┐
     │  Servicios (lógica de negocio y decisión de seguridad)      │
     │  Auth · Authorization · Credential · Crypto · Export ·      │
     │  Import · Alert · Audit · Session · Settings · RateLimiter  │
     └──────────────────┬─────────────────────────────────────────┘
                        ▼
        ┌───────────────────────────────────────────┐
        │  Repositorios (SQL, sentencias preparadas)│
        └──────────────────┬────────────────────────┘
                           ▼
                   ┌───────────────┐        ┌──────────────────────┐
                   │  MySQL        │        │  .env (clave maestra)│
                   │  (secretos    │        │  FUERA del webroot   │
                   │   cifrados)   │        └──────────────────────┘
                   └───────────────┘
```

## 5.2 Separación de responsabilidades

| Capa | Responsabilidad | Lo que **no** hace |
|---|---|---|
| `Core` | HTTP, enrutado, vistas, acceso a datos, errores | No conoce el dominio |
| `Http/Middleware` | Cabeceras, CORS, límites, sesión, CSRF, permiso de ruta | No consulta el dominio |
| `Http/Controllers` | Traducir petición ↔ respuesta, validar formato | **No decide autorización** |
| `Services` | Reglas de negocio y **toda decisión de seguridad** | No genera HTML ni SQL |
| `Repositories` | SQL con sentencias preparadas | No decide permisos |
| `Views` | Presentación, siempre escapada | No consulta la base de datos |

La regla que sostiene el diseño: **la autorización se decide en los servicios**.
El middleware es una primera barrera; el frontend sólo oculta opciones por
comodidad. Ninguna capa confía en la anterior.

## 5.3 Principios aplicados

- **SOLID.** Responsabilidad única por clase; dependencias inyectadas por
  constructor; `AuthorizationService` es el único punto de decisión (PDP).
- **DRY.** Un solo `Validator`, un solo `AuditService`, un solo `CryptoService`.
- **Seguridad por diseño.** No hay ruta que devuelva un secreto por accidente:
  hay que pedirlo explícitamente por un endpoint dedicado.
- **Mínimo privilegio.** Denegación individual sobre concesión; alcance de datos
  en SQL; roles con nivel.
- **Sin dependencias de terceros.** Ni Composer en tiempo de ejecución. Menos
  superficie de ataque y ninguna cadena de suministro que vigilar. El escritor
  de XLSX y el TOTP son propios y auditables.

## 5.4 Modelo de datos (29 tablas)

### Organización y catálogos
`companies` · `locations` · `departments` · `categories`

### Identidad y control de acceso
`users` · `roles` · `permissions` · `role_permissions` · `user_roles` ·
`user_permissions` (excepciones individuales: `allow` / `deny`)

### Criptografía
`encryption_keys` — llavero versionado; cada versión guarda su sal HKDF.

### Inventario
`systems` — el recurso (aplicación, servidor, buzón, red).
`credentials` — la cuenta concreta. **Sin un solo campo de secreto.**

### Secretos
`credential_secrets` — un registro por versión de cada secreto:
criptograma, nonce, etiqueta GCM, DEK envuelta, versión de clave, longitud,
robustez, huella HMAC, motivo del cambio, autor y marca `is_current`.
Las versiones anteriores **son** el historial cifrado.

### Asignaciones
`credential_assignments` — qué usuario accede a qué credencial, con permisos
finos (ver / copiar / recuperación), vigencia, quién otorgó y quién revocó.

### Trazabilidad
`audit_logs` — todo evento relevante.
`secret_access_log` — registro dedicado de acceso a secretos.
`credential_history` — historial funcional (nunca contiene secretos).
`login_attempts` · `security_events` · `export_reports` · `export_report_items`

### Operación
`sessions` · `rate_limits` · `notifications` · `password_resets` ·
`mfa_secrets` · `mfa_backup_codes` · `settings`

### Relación central

```
companies ──< locations ──< departments
     │                          │
     └──────────< systems >─────┘
                    │
                    ├──< credentials ──< credential_secrets   (versiones cifradas)
                    │         │
                    │         ├──< credential_history         (metadatos del cambio)
                    │         ├──< secret_access_log          (quién lo vio/copió/exportó)
                    │         └──< credential_assignments >── users
                    │
users ──< user_roles >── roles ──< role_permissions >── permissions
  │
  └──< user_permissions >── permissions        (excepciones individuales)
```

## 5.5 Decisiones de diseño relevantes

### Por qué los secretos viven en su propia tabla
Permite versionarlos sin duplicar metadatos, hace **imposible** que un `SELECT *`
sobre `credentials` devuelva una contraseña, y aísla la única tabla que necesita
controles reforzados.

### Por qué una DEK por secreto y no una clave global
Limita el radio de un compromiso y elimina el riesgo de reutilización de nonce.
El coste es un cifrado adicional de 32 bytes por operación: irrelevante.

### Por qué sesiones propias
Para poder listarlas y **cerrarlas remotamente**, aplicar caducidad doble y
marcar el step-up por sesión. Las sesiones nativas de PHP no lo permiten.

### Por qué baja lógica y nunca borrado
Un sistema de credenciales debe poder responder *"¿qué tenía asignado este
empleado el 3 de marzo?"*. Borrar destruye esa capacidad. Todo se desactiva; el
histórico permanece.

### Por qué se guarda una huella HMAC del secreto
Permite detectar contraseñas repetidas entre credenciales y rechazar la
repetición al rotar, **sin descifrar nada** y sin poder invertir el valor.

### Por qué un escritor XLSX propio
El archivo puede contener contraseñas reales. Se prefiere una superficie de
código pequeña y auditable (≈300 líneas) a arrastrar un árbol de dependencias
de terceros dentro del componente que manipula secretos en claro.

## 5.6 Ciclo de una petición

1. `public/index.php` carga el contenedor y las rutas.
2. `Request::capture()` normaliza la entrada; el resto del código nunca lee
   `$_GET`/`$_POST` directamente.
3. `Kernel` busca la ruta y construye la cadena de middleware.
4. `security` fija cabeceras y el nonce de CSP.
5. `throttle` aplica el límite de frecuencia.
6. `auth` resuelve la sesión, comprueba caducidades, verifica que el usuario
   siga activo, exige MFA y cambio de contraseña si corresponde, y publica el
   `AuthContext`.
7. `csrf` valida el token en toda escritura.
8. `perm:xxx` comprueba el permiso de ruta.
9. El controlador valida la entrada y llama al servicio.
10. El servicio **vuelve a comprobar** el permiso, aplica el alcance de datos,
    ejecuta la operación y la audita.
11. La respuesta se envía con las cabeceras de seguridad y `no-store`.
12. Cualquier excepción se traduce a una respuesta segura, sin filtrar detalles.
