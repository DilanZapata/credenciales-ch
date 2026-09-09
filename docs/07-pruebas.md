# 7. Pruebas

```bash
php tests/run.php
```

Crea y destruye una base de datos independiente (`credenciales_corp_test`), de
modo que **no toca los datos reales**.

## 7.1 Cómo están construidas

`tests/TestClient.php` es un cliente HTTP **en proceso**: construye una
`Request` real y la pasa por el `Kernel` completo —enrutado, middleware,
controlador, servicios, base de datos— exactamente igual que una petición del
navegador.

Esto es deliberado: si las pruebas invocaran los servicios directamente,
verificarían la lógica pero **no** que las rutas tengan el middleware correcto.
Al pasar por el núcleo, una ruta a la que se le olvide el permiso falla la
prueba.

Cada petición construye un contenedor nuevo, de forma que el contexto de
seguridad no se filtra entre llamadas.

## 7.2 Qué cubre (209 comprobaciones)

| Grupo | Qué verifica |
|---|---|
| **1. Autenticación** | Redirección sin sesión, token CSRF en el formulario, contraseña incorrecta, ausencia de enumeración de cuentas, emisión y persistencia de la sesión (sólo el hash), auditoría del acceso, bloqueo por intentos fallidos, evento de seguridad, limitador por IP |
| **2. Autorización** | Consultor rechazado en 8 rutas administrativas, IDOR por URL y por API, imposibilidad de rotar/editar/crear, auditoría de cada denegación, auditor que ve todo pero **no** secretos ni exportaciones con contraseñas |
| **3. Información vs. secreto** | El listado y el detalle de la API nunca traen contraseñas; el HTML tampoco; step-up obligatorio (423); reauth incorrecta rechazada; revelado correcto tras step-up; registro con usuario e IP; permisos finos de la asignación (ver sí, copiar no); recuperación por endpoint propio y auditada |
| **4. Cifrado** | Criptograma ≠ texto, nonce de 96 bits, etiqueta GCM, DEK envuelta, descifrado correcto con AAD válido, **fallo con AAD de otra credencial**, **fallo con criptograma manipulado**, no determinismo, hash irreversible para contraseñas de acceso |
| **5. CSRF / XSS / SQLi** | Escritura sin token (419), token de otra sesión (419), auditoría del fallo, 4 cargas de inyección SQL, inyección en `ORDER BY`, XSS almacenado escapado, CSP con nonce, cabeceras de seguridad, `no-store` |
| **6. Ciclo de vida** | Rotación, conservación de la versión anterior, descifrado de la nueva, historial con motivo y autor, **el historial no guarda contraseñas**, rechazo de contraseña repetida, revelado de histórica con permiso y su denegación sin él, asignación y revocación con efecto inmediato |
| **7. Baja de empleados** | Estado inactivo, motivo conservado, revocación de todos los accesos, **historial preservado**, cierre de sesiones, sesión inutilizable al instante, imposibilidad de volver a entrar, auditoría con el detalle |
| **8. Sesiones** | Listado, cierre remoto con efecto inmediato, auditoría, endpoint de estado sin datos sensibles |
| **9. Reportes** | Inventario sin contraseñas, registro de la exportación, archivo fuera del webroot, nombre inocuo, XLSX válido, sin secretos en el inventario, **bloqueo sin confirmación**, generación con permiso + confirmación + step-up, registro de las credenciales incluidas, entrada por secreto en `secret_access_log`, evento de seguridad, descarga ajena rechazada y auditada, **borrado del archivo tras la descarga** |
| **10. Auditoría** | Filtros por acción, cédula, resultado y fecha; 11 acciones críticas presentes; columnas obligatorias; **ninguna de las 8 contraseñas reales aparece** en `audit_logs`, `login_attempts` ni en los archivos de log; trazabilidad de quién consultó y quién exportó |
| **11. Políticas** | Rechazo de contraseña corta, rechazo de contraseña con datos personales, generador (longitud, variedad, no repetición), **imposibilidad de auto-asignarse un rol superior**, **imposibilidad de conceder un permiso propio inexistente**, MFA obligatorio para administradores, TOTP válido/inválido/caducado, ausencia de open redirect, errores sin rutas ni SQL ni trazas, método no permitido, cierre de sesión efectivo |
| **12. Alertas** | Detección de vencidas, sin responsable, usuarios inactivos con accesos, exceso de fallos; despacho a notificaciones; **deduplicación diaria** |
| **13. Revisión de seguridad** | Permiso `export.reports` exigible por separado; auditor exportando inventario; **caducidad de la contraseña de acceso**; escritura con origen propio; el generador no deja rastro del valor producido |

## 7.3 Resultado

```
══════════════════════════════════════════════════════════════════════
  TODAS LAS PRUEBAS SUPERADAS  (209/209)
══════════════════════════════════════════════════════════════════════
```

## 7.4 Añadir pruebas

```php
$t->group('13. Mi nueva área');

$cliente = new TestClient('198.51.100.20');
$cliente->login('admin.test', PASS_ADMIN);

$r = $cliente->get('/mi-ruta');
$t->status(200, $r, 'Descripción de lo que debe ocurrir');
$t->assert(!str_contains($r['body'], $secreto), 'La respuesta no filtra el secreto');
```

Métodos disponibles: `assert()`, `equals()`, `status()`.
Cliente: `get()`, `post()`, `getJson()`, `postJson()`, `request()`, `login()`.

## 7.5 Pruebas manuales recomendadas antes de producción

1. Iniciar sesión con HTTPS y confirmar que la cookie lleva `Secure`.
2. Configurar MFA con una aplicación autenticadora real y verificar un código
   de respaldo.
3. Generar un Excel con contraseñas y comprobar que el archivo desaparece de
   `storage/exports/` tras descargarlo.
4. Cerrar remotamente la sesión de otro usuario y comprobar el efecto inmediato.
5. Dar de baja a un usuario de prueba y comprobar que pierde el acceso al
   instante y que su historial permanece.
6. Ejecutar `php bin/console.php maintenance` y confirmar que purga lo vencido.
