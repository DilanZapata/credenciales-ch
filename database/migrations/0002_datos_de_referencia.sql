-- =====================================================================
--  Datos de referencia (idempotente). Roles, permisos, categorias,
--  parametros del sistema. No contiene usuarios ni secretos.
-- =====================================================================
SET NAMES utf8mb4;

-- ------------------------- PERMISOS ---------------------------------
INSERT INTO permissions (code, name, group_name, description, is_sensitive) VALUES
 ('dashboard.view',              'Ver panel',                          'general',     'Acceder al panel principal', 0),
 ('notifications.view',          'Ver notificaciones',                 'general',     'Ver alertas dirigidas al usuario', 0),

 ('users.view',                  'Ver usuarios',                       'usuarios',    'Listar y consultar usuarios', 0),
 ('users.create',                'Crear usuarios',                     'usuarios',    'Alta de usuarios', 0),
 ('users.update',                'Editar usuarios',                    'usuarios',    'Modificar datos de usuarios', 0),
 ('users.deactivate',            'Desactivar/reactivar usuarios',      'usuarios',    'Bloquear o restablecer acceso', 0),
 ('users.assign_roles',          'Asignar roles',                      'usuarios',    'Cambiar roles y permisos de un usuario', 1),
 ('users.reset_password',        'Restablecer contrasena de usuario',  'usuarios',    'Forzar cambio de contrasena', 1),

 ('roles.view',                  'Ver roles',                          'roles',       'Consultar roles y permisos', 0),
 ('roles.manage',                'Gestionar roles',                    'roles',       'Crear/editar roles y su matriz de permisos', 1),

 ('systems.view',                'Ver sistemas',                       'sistemas',    'Consultar inventario de sistemas', 0),
 ('systems.create',              'Crear sistemas',                     'sistemas',    'Registrar nuevos sistemas', 0),
 ('systems.update',              'Editar sistemas',                    'sistemas',    'Modificar sistemas', 0),
 ('systems.delete',              'Eliminar sistemas',                  'sistemas',    'Baja logica de sistemas', 0),

 ('credentials.view',            'Ver credenciales asignadas',         'credenciales','Ver la ficha (sin secretos) de credenciales autorizadas', 0),
 ('credentials.view_all',        'Ver todas las credenciales',         'credenciales','Ver el inventario completo, sin restriccion de asignacion', 0),
 ('credentials.create',          'Crear credenciales',                 'credenciales','Registrar nuevas credenciales', 0),
 ('credentials.update',          'Editar credenciales',                'credenciales','Modificar metadatos de credenciales', 0),
 ('credentials.delete',          'Eliminar/desactivar credenciales',   'credenciales','Baja logica de credenciales', 0),
 ('credentials.rotate',          'Actualizar contrasena',              'credenciales','Rotar el secreto de una credencial', 1),
 ('credentials.assign',          'Asignar credenciales',               'credenciales','Otorgar acceso a un usuario', 0),
 ('credentials.revoke',          'Revocar accesos',                    'credenciales','Retirar acceso a un usuario', 0),

 ('credentials.secret.view',     'Ver contrasena',                     'secretos',    'Revelar el secreto vigente', 1),
 ('credentials.secret.copy',     'Copiar contrasena',                  'secretos',    'Copiar el secreto al portapapeles', 1),
 ('credentials.secret.history',  'Ver contrasena historica',           'secretos',    'Revelar secretos anteriores', 1),
 ('credentials.recovery.view',   'Ver informacion de recuperacion',    'secretos',    'Ver correos/telefonos/preguntas de recuperacion', 1),

 ('categories.manage',           'Gestionar categorias',               'catalogos',   'Crear y editar categorias', 0),
 ('org.manage',                  'Gestionar empresas/sedes/areas',     'catalogos',   'Administrar estructura organizacional', 0),

 ('audit.view',                  'Consultar auditoria',                'auditoria',   'Ver el registro de eventos', 0),
 ('audit.export',                'Exportar auditoria',                 'auditoria',   'Descargar el registro de eventos', 0),
 ('history.view',                'Ver historial de credenciales',      'auditoria',   'Consultar el historial de cambios', 0),

 ('sessions.view',               'Ver sesiones',                       'seguridad',   'Listar sesiones activas', 0),
 ('sessions.revoke',             'Cerrar sesiones remotamente',        'seguridad',   'Revocar sesiones de otros usuarios', 1),
 ('security.events.view',        'Ver eventos de seguridad',           'seguridad',   'Consultar alertas de seguridad', 0),
 ('settings.manage',             'Configurar el sistema',              'seguridad',   'Politicas de seguridad y parametros', 1),

 ('reports.view',                'Ver reportes',                       'reportes',    'Acceder al modulo de reportes', 0),
 ('export.reports',              'Exportar reportes',                  'reportes',    'EXPORTAR_REPORTES: generar reportes generales', 0),
 ('export.credentials',          'Exportar inventario de credenciales','reportes',    'EXPORTAR_CREDENCIALES: sin contrasenas', 0),
 ('export.credentials.secrets',  'Exportar credenciales CON contrasena','reportes',   'EXPORTAR_CREDENCIALES_CON_PASSWORD', 1),
 ('export.history',              'Exportar historial',                 'reportes',    'EXPORTAR_HISTORIAL', 0),
 ('import.credentials',          'Importar credenciales',              'reportes',    'Carga masiva desde CSV/Excel', 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), group_name=VALUES(group_name),
  description=VALUES(description), is_sensitive=VALUES(is_sensitive);

-- -------------------------- ROLES -----------------------------------
INSERT INTO roles (code, name, description, level, is_system, requires_mfa) VALUES
 ('SUPERADMIN', 'Superadministrador', 'Control total del sistema, incluida la configuracion de seguridad.', 100, 1, 1),
 ('ADMIN',      'Administrador',      'Gestiona usuarios, sistemas, credenciales y asignaciones.',            80, 1, 1),
 ('AUDITOR',    'Auditor',            'Consulta y exporta auditoria e inventario. No puede ver contrasenas.', 50, 1, 0),
 ('CONSULTOR',  'Consultor',          'Consulta unicamente las credenciales que tiene asignadas.',            10, 1, 0)
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description),
  level=VALUES(level), requires_mfa=VALUES(requires_mfa);

-- ------------------- MATRIZ ROL / PERMISO ----------------------------
-- SUPERADMIN: todo
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'SUPERADMIN';

-- ADMIN: todo excepto configuracion de seguridad y gestion de roles
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'ADMIN' AND p.code NOT IN ('settings.manage','roles.manage');

-- AUDITOR: lectura + exportacion SIN secretos
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
  'dashboard.view','notifications.view','users.view','roles.view',
  'systems.view','credentials.view','credentials.view_all',
  'audit.view','audit.export','history.view',
  'sessions.view','security.events.view',
  'reports.view','export.reports','export.credentials','export.history'
) WHERE r.code = 'AUDITOR';

-- CONSULTOR: solo lo asignado
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
  'dashboard.view','notifications.view','credentials.view',
  'credentials.secret.view','credentials.secret.copy'
) WHERE r.code = 'CONSULTOR';

-- ----------------------- CATEGORIAS ---------------------------------
INSERT INTO categories (name, slug, description, color, icon, sort_order) VALUES
 ('Correos',            'correos',            'Cuentas de correo corporativo',        '#2563eb', 'mail',      10),
 ('Sistemas',           'sistemas',           'Sistemas empresariales internos',      '#7c3aed', 'server',    20),
 ('Computadores',       'computadores',       'Equipos de computo y estaciones',      '#0891b2', 'monitor',   30),
 ('Servidores',         'servidores',         'Servidores fisicos y virtuales',       '#dc2626', 'hard-drive',40),
 ('Redes',              'redes',              'Redes Wi-Fi, VPN, equipos de red',     '#059669', 'wifi',      50),
 ('Software',           'software',           'Licencias y accesos a software',       '#d97706', 'package',   60),
 ('Bancos',             'bancos',             'Plataformas financieras y bancarias',  '#b91c1c', 'bank',      70),
 ('Plataformas web',    'plataformas-web',    'Portales y plataformas externas',      '#4f46e5', 'globe',     80),
 ('Redes sociales',     'redes-sociales',     'Redes sociales corporativas',          '#db2777', 'share',     90),
 ('Servicios en la nube','servicios-nube',    'Proveedores cloud y SaaS',             '#0284c7', 'cloud',    100),
 ('Telefonia',          'telefonia',          'Lineas, centrales y telefonia IP',     '#65a30d', 'phone',    110),
 ('Otros',              'otros',              'Otros recursos que requieren acceso',  '#64748b', 'folder',   120)
ON DUPLICATE KEY UPDATE description=VALUES(description), color=VALUES(color), icon=VALUES(icon);

-- --------------------- PARAMETROS DEL SISTEMA -----------------------
INSERT INTO settings (setting_key, setting_value, value_type, group_name, label, description) VALUES
 ('app.name',                     'Gestion de Credenciales', 'string','general',  'Nombre del sistema', 'Titulo visible de la aplicacion'),
 ('security.session_idle_minutes','30',  'int', 'sesiones','Inactividad maxima (min)','Cierre automatico por inactividad'),
 ('security.session_absolute_hours','8', 'int', 'sesiones','Duracion maxima (h)','Vida maxima absoluta de una sesion'),
 ('security.reauth_minutes',      '10',  'int', 'sesiones','Vigencia de reautenticacion (min)','Tiempo que dura un step-up antes de volver a pedir la clave'),
 ('security.max_login_attempts',  '5',   'int', 'acceso',  'Intentos fallidos permitidos','Antes de bloquear la cuenta'),
 ('security.lockout_minutes',     '15',  'int', 'acceso',  'Duracion del bloqueo (min)','Bloqueo temporal tras superar los intentos'),
 ('security.mfa_required_admins', '1',   'bool','acceso',  'MFA obligatorio para administradores','Exigir TOTP a roles administrativos'),
 ('security.password_min_length', '12',  'int', 'politica','Longitud minima de contrasena de acceso','Para las contrasenas de los usuarios del sistema'),
 ('security.password_expiry_days','90',  'int', 'politica','Vigencia de la contrasena de acceso (dias)','0 = sin caducidad'),
 ('security.reauth_for_secret',   '1',   'bool','politica','Reautenticar antes de revelar secretos','Step-up obligatorio para ver contrasenas'),
 ('security.reauth_for_export',   '1',   'bool','politica','Reautenticar antes de exportar','Step-up obligatorio para generar reportes con secretos'),
 ('credentials.default_rotation_days','90','int','politica','Rotacion por defecto (dias)','Periodo sugerido de cambio de contrasena'),
 ('alerts.expiry_warning_days',   '15',  'int', 'alertas', 'Aviso de vencimiento (dias)','Dias de antelacion para avisar'),
 ('alerts.failed_login_threshold','10',  'int', 'alertas', 'Umbral de intentos fallidos','Genera evento de seguridad al superarlo'),
 ('exports.retention_minutes',    '15',  'int', 'reportes','Vida del archivo exportado (min)','Se elimina automaticamente al vencer'),
 ('exports.max_records',          '5000','int', 'reportes','Maximo de registros por exportacion','Limite de seguridad'),
 ('mail.enabled',                 '0',   'bool','alertas', 'Envio de correo habilitado','Notificaciones por correo electronico'),
 ('mail.from',                    'no-reply@empresa.local','string','alertas','Remitente','Direccion origen de las notificaciones')
ON DUPLICATE KEY UPDATE label=VALUES(label), description=VALUES(description), value_type=VALUES(value_type), group_name=VALUES(group_name);
