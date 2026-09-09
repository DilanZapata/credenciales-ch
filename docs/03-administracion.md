# 3. Manual del administrador

## 3.1 Primer ingreso

1. Entre con el usuario y la contraseña temporal que imprimió el instalador.
2. El sistema **obliga** a cambiar la contraseña antes de continuar.
3. Como el rol de superadministrador exige verificación en dos pasos, el sistema
   sólo permite navegar a *Perfil → Verificación en dos pasos* hasta que la
   configure.
4. Añada la clave secreta a su aplicación autenticadora (Google Authenticator,
   Microsoft Authenticator, Authy, 1Password, FreeOTP…) e introduzca el código.
5. **Guarde los diez códigos de respaldo**: se muestran una única vez y cada uno
   sirve para un solo uso.

---

## 3.2 Orden recomendado de puesta en marcha

```
Organización → Categorías → Sistemas → Credenciales → Usuarios → Asignaciones
```

### Organización
*Administración → Organización.* Registre empresas, sedes y departamentos. Sirven
para clasificar y para filtrar reportes ("todas las credenciales de la sede
Bogotá", "todas las del departamento de contabilidad").

### Categorías
*Administración → Categorías.* Vienen doce predefinidas (Correos, Sistemas,
Computadores, Servidores, Redes, Software, Bancos, Plataformas web, Redes
sociales, Servicios en la nube, Telefonía, Otros). Puede crear las que necesite.

### Sistemas
*Sistemas → Nuevo sistema.* Un sistema es el **recurso**: la aplicación, el
servidor, el buzón, la red Wi-Fi. Contiene los datos técnicos (URL, IP, puerto,
servidor, plataforma, proveedor), la ubicación y el **responsable**.

### Credenciales
*Credenciales → Nueva credencial.* Una credencial es una **cuenta concreta**
dentro de un sistema. Un mismo sistema puede tener varias.

La ficha cubre los cuatro bloques exigidos:

- **General**: sistema, nombre descriptivo, entorno, estado, responsable.
- **Acceso**: usuario, correo, contraseña, dominio, usuario administrador,
  método de autenticación.
- **Recuperación**: correo, teléfono, usuario y notas de recuperación, e
  indicador de si existen preguntas de seguridad.
- **Administrativo**: fechas de creación, actualización, última rotación,
  próxima rotación recomendada, vencimiento y observaciones.

El formulario incluye un **generador criptográficamente seguro** (longitud
configurable, mayúsculas, minúsculas, números, símbolos, exclusión de caracteres
ambiguos) y un medidor de robustez en vivo.

---

## 3.3 Asignar accesos a los empleados

Desde la ficha de la credencial → **Asignar usuario**. Puede buscar al empleado
por nombre o por número de cédula.

Cada asignación define tres permisos independientes:

| Permiso | Efecto |
|---|---|
| Ver la contraseña | El usuario puede revelarla |
| Copiar la contraseña | El usuario puede copiarla al portapapeles |
| Ver la información de recuperación | El usuario ve correos/teléfonos de recuperación |

Opcionalmente puede fijar una **fecha de vigencia**: pasada esa fecha el acceso
deja de funcionar automáticamente.

Al entrar, el empleado sólo verá en *Mis accesos* los recursos autorizados. Si
intenta abrir por URL una credencial que no tiene asignada, el sistema responde
**404** —no revela siquiera que exista— y registra el intento en la auditoría.

---

## 3.4 Actualizar una contraseña

Cuando una contraseña cambia en el sistema externo:

1. *Credenciales* → abrir la credencial.
2. **Actualizar contraseña**.
3. Escribir la nueva contraseña o generarla con el generador integrado.
4. Indicar el motivo (rotación periódica, incidente, cambio de proveedor…).
5. Ajustar los días hasta la próxima rotación.
6. Confirmar.

El sistema entonces:

- cifra el nuevo secreto con una clave de datos nueva;
- **conserva la contraseña anterior cifrada** como versión histórica;
- actualiza la fecha de última rotación y la próxima fecha recomendada;
- registra el cambio en el historial con motivo y autor;
- registra el evento en la auditoría.

Rechaza repetir la contraseña vigente, comparando huellas HMAC **sin descifrar
nada**.

---

## 3.5 Historial de una credencial

*Ficha → Historial.* Tres bloques:

1. **Versiones de la contraseña**: número de versión, fecha, quién la cambió,
   motivo, robustez y estado (vigente/histórica).
2. **Historial de cambios**: cada modificación con campo, valor anterior, valor
   nuevo, estados, fechas de vencimiento, motivo, autor e IP.
3. **Accesos al secreto**: quién lo vio, lo copió o lo exportó, cuándo y desde
   dónde.

Las contraseñas históricas **no se muestran automáticamente**. Revelarlas exige
el permiso específico `credentials.secret.history`, reautenticación, y genera
su propio registro de auditoría.

---

## 3.6 Baja de un empleado

*Usuarios → abrir el usuario → Desactivar.*

Indique el motivo y, opcionalmente, **a qué usuario reasignar sus accesos**.
El sistema:

- bloquea su inicio de sesión de inmediato;
- revoca todas sus asignaciones activas;
- cierra todas sus sesiones abiertas;
- copia sus accesos al sustituto, si lo indicó;
- **conserva íntegros el historial y la auditoría**;
- deja registrado en la auditoría qué credenciales tenía asignadas en el momento
  de la baja.

Nada se elimina. Si el empleado vuelve, *Reactivar* restaura el acceso, pero las
asignaciones deben otorgarse de nuevo de forma explícita.

El panel avisa de forma destacada si existe algún usuario inactivo que aún
conserve accesos activos.

---

## 3.7 Reportes y exportación a Excel

*Reportes.* Cuatro tipos:

| Reporte | Contenido | Contraseñas |
|---|---|---|
| **Inventario** | Qué credenciales existen y su información administrativa | Nunca |
| **Credenciales completas** | Ficha completa incluida la recuperación | Sólo si se solicita y se tiene permiso |
| **Historial** | Cambios, rotaciones, asignaciones y revocaciones | Nunca (ni las antiguas) |
| **Auditoría** | Registro de eventos | No aplica |

Puede filtrar por categoría, sistema, empresa, sede, departamento, usuario
asignado, estado, vencimiento, o seleccionar credenciales concretas.

### Exportar con contraseñas

La casilla **"Incluir contraseñas reales"** está desmarcada por defecto. Al
marcarla aparece la advertencia de confidencialidad y una segunda casilla de
confirmación. Además el sistema exige:

- el permiso `export.credentials.secrets` (el rol Auditor no lo tiene);
- confirmación explícita;
- reautenticación reciente;
- un máximo de 5 exportaciones con secretos por hora.

Cada exportación registra: usuario, cédula, fecha, hora, IP, dispositivo, tipo
de reporte, filtros aplicados, cantidad de credenciales, si incluyó contraseñas,
resultado, identificador del reporte **y la lista exacta de credenciales
incluidas**.

### Protección del archivo

- Se escribe **fuera del directorio público**: no existe URL que lo exponga.
- Nombre aleatorio en disco; el nombre visible no revela contenido sensible.
- Permisos `0600`.
- Sólo puede descargarlo el usuario que lo generó.
- **Se elimina del servidor inmediatamente después de la descarga.**
- Si nadie lo descarga, la tarea de mantenimiento lo purga al expirar (15 min
  por defecto).
- El Excel incluye una hoja *Información del reporte* con la trazabilidad y el
  aviso de confidencialidad.

---

## 3.8 Importación masiva

*Importar.* Descargue la plantilla CSV, complétela y cárguela.

El flujo es siempre **cargar → previsualizar → confirmar**. En la
previsualización nada se escribe en la base de datos: el sistema valida cada
fila, detecta duplicados dentro del archivo y contra la base existente, y
muestra los errores fila por fila. Las contraseñas se muestran enmascaradas.

El archivo temporal se elimina en cuanto se lee: no queda en el servidor.

---

## 3.9 Auditoría

*Auditoría.* Filtros por usuario, cédula, tipo de acción, resultado, severidad,
IP y rango de fechas, más accesos rápidos a "accesos a contraseñas",
"exportaciones", "autenticación" y "accesos denegados".

Cada evento registra usuario, cédula, acción, recurso afectado, fecha, hora, IP,
dispositivo, ruta, resultado e información adicional.

Preguntas que la auditoría permite responder directamente:

- ¿Quién consultó la contraseña del sistema X y cuándo?
- ¿Quién la copió? ¿Quién la exportó?
- ¿Quién cambió la contraseña, cuándo y por qué?
- ¿Quién tiene acceso al sistema X ahora?
- ¿Qué credenciales tenía asignadas un empleado en determinada fecha?
- ¿Quién generó un Excel que contenía esta credencial?

---

## 3.10 Sesiones y eventos de seguridad

*Sesiones* lista las sesiones con usuario, cédula, IP, dispositivo, inicio,
última actividad, estado de MFA y estado. Puede **cerrar cualquier sesión de
forma remota**; el efecto es inmediato en la siguiente petición del usuario.

*Eventos de seguridad* recoge las anomalías detectadas automáticamente:
bloqueos por fuerza bruta, uso de códigos de respaldo, consultas masivas de
secretos, exportaciones con contraseñas, fallos de descifrado, intentos de
descarga de reportes ajenos y fallos de CSRF.

---

## 3.11 Roles y permisos

*Roles y permisos* muestra la matriz completa: 42 permisos agrupados por área.
Puede crear roles propios (por ejemplo *Supervisor de TI*) y ajustar la matriz.

Dos límites que el sistema impone siempre, incluso por API:

1. Nadie puede crear o asignar un rol de **nivel igual o superior al suyo**.
2. Nadie puede conceder un permiso que **él mismo no posee**.

Además de los roles, cada usuario admite **excepciones individuales**: conceder
o denegar un permiso concreto. Una denegación siempre prevalece sobre cualquier
concesión (principio de mínimo privilegio).

Cambiar roles o permisos de un usuario **cierra todas sus sesiones**, para que
los nuevos privilegios se apliquen desde cero.

---

## 3.12 Consola de administración

```bash
php bin/console.php key:generate   # genera las claves en .env
php bin/console.php install        # instalación completa
php bin/console.php migrate        # aplica esquema y datos de referencia
php bin/console.php seed:demo      # datos de ejemplo (sólo pruebas)
php bin/console.php user:create    # crea un usuario de forma interactiva
php bin/console.php alerts:run     # evalúa y despacha alertas (cron diario)
php bin/console.php maintenance    # purga archivos, sesiones y limitadores (cron)
php bin/console.php key:rotate     # rota el llavero y re-cifra los secretos
php bin/console.php doctor         # diagnóstico de la instalación
```
