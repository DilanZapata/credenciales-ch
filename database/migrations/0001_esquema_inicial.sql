-- =====================================================================
--  SISTEMA CORPORATIVO DE GESTION DE CREDENCIALES Y ACCESOS (SCGCA)
--  Esquema relacional - MySQL / MariaDB (InnoDB, utf8mb4)
--
--  PRINCIPIO RECTOR:
--    Los METADATOS de una credencial viven en `credentials`.
--    Los SECRETOS viven exclusivamente en `credential_secrets`,
--    cifrados con envelope encryption (DEK por secreto + KEK derivada
--    de la clave maestra). NUNCA en texto plano, NUNCA en otra tabla.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. ORGANIZACION
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS companies (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(150) NOT NULL,
  legal_name    VARCHAR(200) NULL,
  tax_id        VARCHAR(50)  NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_companies_name (name),
  KEY idx_companies_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS locations (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    INT UNSIGNED NOT NULL,
  name          VARCHAR(150) NOT NULL,
  address       VARCHAR(255) NULL,
  city          VARCHAR(100) NULL,
  country       VARCHAR(100) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_locations_company_name (company_id, name),
  CONSTRAINT fk_locations_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS departments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    INT UNSIGNED NOT NULL,
  location_id   INT UNSIGNED NULL,
  name          VARCHAR(150) NOT NULL,
  code          VARCHAR(40)  NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_departments_company_name (company_id, name),
  KEY idx_departments_location (location_id),
  CONSTRAINT fk_departments_company  FOREIGN KEY (company_id)  REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_departments_location FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120) NOT NULL,
  slug          VARCHAR(120) NOT NULL,
  description   VARCHAR(255) NULL,
  color         VARCHAR(9)   NOT NULL DEFAULT '#64748b',
  icon          VARCHAR(40)  NOT NULL DEFAULT 'folder',
  sort_order    SMALLINT     NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. IDENTIDAD, ROLES Y PERMISOS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(40)  NOT NULL,
  name          VARCHAR(80)  NOT NULL,
  description   VARCHAR(255) NULL,
  level         TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT '100=superadmin, mayor = mas privilegio',
  is_system     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'roles del sistema: no se pueden borrar',
  requires_mfa  TINYINT(1)   NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(60)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  group_name    VARCHAR(60)  NOT NULL DEFAULT 'general',
  description   VARCHAR(255) NULL,
  is_sensitive  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = exige reautenticacion / step-up',
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code),
  KEY idx_permissions_group (group_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id       SMALLINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id)       REFERENCES roles (id)       ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  national_id         VARCHAR(30)  NOT NULL COMMENT 'cedula / documento de identidad',
  employee_code       VARCHAR(40)  NULL,
  username            VARCHAR(60)  NOT NULL,
  email               VARCHAR(190) NOT NULL,
  first_name          VARCHAR(80)  NOT NULL,
  last_name           VARCHAR(80)  NOT NULL,
  phone               VARCHAR(40)  NULL,
  position            VARCHAR(120) NULL,
  company_id          INT UNSIGNED NULL,
  location_id         INT UNSIGNED NULL,
  department_id       INT UNSIGNED NULL,
  password_hash       VARCHAR(255) NOT NULL,
  password_algo       VARCHAR(20)  NOT NULL DEFAULT 'bcrypt-hmac',
  password_changed_at DATETIME     NULL,
  must_change_password TINYINT(1)  NOT NULL DEFAULT 1,
  status              ENUM('active','inactive','locked','suspended') NOT NULL DEFAULT 'active',
  mfa_enabled         TINYINT(1)   NOT NULL DEFAULT 0,
  mfa_enforced        TINYINT(1)   NOT NULL DEFAULT 0,
  failed_attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until        DATETIME     NULL,
  last_login_at       DATETIME     NULL,
  last_login_ip       VARCHAR(45)  NULL,
  notes               VARCHAR(500) NULL,
  created_by          INT UNSIGNED NULL,
  updated_by          INT UNSIGNED NULL,
  deactivated_at      DATETIME     NULL,
  deactivated_by      INT UNSIGNED NULL,
  deactivation_reason VARCHAR(255) NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_national_id (national_id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status (status),
  KEY idx_users_org (company_id, location_id, department_id),
  CONSTRAINT fk_users_company    FOREIGN KEY (company_id)    REFERENCES companies (id)   ON DELETE SET NULL,
  CONSTRAINT fk_users_location   FOREIGN KEY (location_id)   REFERENCES locations (id)   ON DELETE SET NULL,
  CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id       INT UNSIGNED NOT NULL,
  role_id       SMALLINT UNSIGNED NOT NULL,
  assigned_by   INT UNSIGNED NULL,
  assigned_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Excepciones granulares por usuario (allow suma, deny gana siempre)
CREATE TABLE IF NOT EXISTS user_permissions (
  user_id       INT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  effect        ENUM('allow','deny') NOT NULL DEFAULT 'allow',
  reason        VARCHAR(255) NULL,
  assigned_by   INT UNSIGNED NULL,
  assigned_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, permission_id),
  CONSTRAINT fk_up_user FOREIGN KEY (user_id)       REFERENCES users (id)       ON DELETE CASCADE,
  CONSTRAINT fk_up_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. CRIPTOGRAFIA (llavero / key ring para rotacion de claves)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS encryption_keys (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version       SMALLINT UNSIGNED NOT NULL,
  salt          VARBINARY(32) NOT NULL COMMENT 'salt HKDF para derivar la KEK desde la master key',
  algo          VARCHAR(30)   NOT NULL DEFAULT 'aes-256-gcm',
  status        ENUM('active','retired') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  retired_at    DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enckeys_version (version),
  KEY idx_enckeys_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. SISTEMAS Y CREDENCIALES
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS systems (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(60)  NULL,
  name          VARCHAR(180) NOT NULL COMMENT 'nombre del sistema',
  display_name  VARCHAR(180) NULL COMMENT 'nombre descriptivo',
  description   TEXT NULL,
  category_id   INT UNSIGNED NULL,
  resource_type ENUM('web','application','software','computer','server','email','network','cloud','social','banking','license','database','other')
                NOT NULL DEFAULT 'other',
  company_id    INT UNSIGNED NULL,
  location_id   INT UNSIGNED NULL,
  department_id INT UNSIGNED NULL,
  url           VARCHAR(500) NULL,
  ip_address    VARCHAR(45)  NULL,
  port          SMALLINT UNSIGNED NULL,
  hostname      VARCHAR(180) NULL,
  platform      VARCHAR(120) NULL,
  provider      VARCHAR(120) NULL,
  owner_user_id INT UNSIGNED NULL COMMENT 'responsable del sistema',
  criticality   ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  status        ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  created_by    INT UNSIGNED NULL,
  updated_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_systems_code (code),
  KEY idx_systems_category (category_id),
  KEY idx_systems_org (company_id, location_id, department_id),
  KEY idx_systems_owner (owner_user_id),
  KEY idx_systems_status (status),
  FULLTEXT KEY ft_systems (name, display_name, description, hostname, provider, platform),
  CONSTRAINT fk_systems_category   FOREIGN KEY (category_id)   REFERENCES categories (id)  ON DELETE SET NULL,
  CONSTRAINT fk_systems_company    FOREIGN KEY (company_id)    REFERENCES companies (id)   ON DELETE SET NULL,
  CONSTRAINT fk_systems_location   FOREIGN KEY (location_id)   REFERENCES locations (id)   ON DELETE SET NULL,
  CONSTRAINT fk_systems_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL,
  CONSTRAINT fk_systems_owner      FOREIGN KEY (owner_user_id) REFERENCES users (id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- METADATOS de la credencial. Aqui NUNCA hay secretos.
CREATE TABLE IF NOT EXISTS credentials (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  system_id           INT UNSIGNED NOT NULL,
  name                VARCHAR(180) NOT NULL COMMENT 'nombre descriptivo de la credencial',
  environment         ENUM('production','staging','development','other') NOT NULL DEFAULT 'production',
  username            VARCHAR(190) NULL,
  email               VARCHAR(190) NULL,
  domain              VARCHAR(190) NULL,
  admin_username      VARCHAR(190) NULL,
  auth_method         VARCHAR(80)  NULL COMMENT 'password, mfa, sso, certificado, llave ssh...',
  recovery_email      VARCHAR(190) NULL,
  recovery_phone      VARCHAR(40)  NULL,
  recovery_username   VARCHAR(190) NULL,
  recovery_notes      TEXT NULL,
  has_security_questions TINYINT(1) NOT NULL DEFAULT 0,
  observations        TEXT NULL,
  owner_user_id       INT UNSIGNED NULL COMMENT 'responsable de la credencial',
  status              ENUM('active','inactive','expired','revoked','archived') NOT NULL DEFAULT 'active',
  password_changed_at DATETIME NULL COMMENT 'ultima rotacion de contrasena',
  rotation_period_days SMALLINT UNSIGNED NULL DEFAULT 90,
  next_rotation_at    DATE NULL COMMENT 'proxima fecha recomendada de cambio',
  expires_at          DATE NULL COMMENT 'fecha de vencimiento',
  created_by          INT UNSIGNED NULL,
  updated_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at          DATETIME NULL COMMENT 'baja logica: nunca se destruye historico',
  PRIMARY KEY (id),
  KEY idx_credentials_system (system_id),
  KEY idx_credentials_status (status, deleted_at),
  KEY idx_credentials_owner (owner_user_id),
  KEY idx_credentials_rotation (next_rotation_at),
  KEY idx_credentials_expiry (expires_at),
  FULLTEXT KEY ft_credentials (name, username, email, observations),
  CONSTRAINT fk_credentials_system FOREIGN KEY (system_id)     REFERENCES systems (id) ON DELETE CASCADE,
  CONSTRAINT fk_credentials_owner  FOREIGN KEY (owner_user_id) REFERENCES users (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SECRETOS. Envelope encryption. Version 1..N: la ultima (is_current=1) es la vigente,
-- las anteriores constituyen el historial cifrado (art. 32).
CREATE TABLE IF NOT EXISTS credential_secrets (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  credential_id INT UNSIGNED NOT NULL,
  field         ENUM('password','pin','access_code','recovery_answer','extra') NOT NULL DEFAULT 'password',
  label         VARCHAR(120) NULL COMMENT 'para field=extra / preguntas de seguridad',
  version       INT UNSIGNED NOT NULL DEFAULT 1,
  is_current    TINYINT(1) NOT NULL DEFAULT 1,
  algo          VARCHAR(30)   NOT NULL DEFAULT 'aes-256-gcm',
  key_version   SMALLINT UNSIGNED NOT NULL,
  ciphertext    VARBINARY(4096) NOT NULL COMMENT 'AES-256-GCM(DEK) del secreto',
  nonce         VARBINARY(12)   NOT NULL,
  tag           VARBINARY(16)   NOT NULL,
  wrapped_dek   VARBINARY(64)   NOT NULL COMMENT 'DEK cifrada con la KEK',
  dek_nonce     VARBINARY(12)   NOT NULL,
  dek_tag       VARBINARY(16)   NOT NULL,
  secret_length SMALLINT UNSIGNED NULL COMMENT 'metadato para politicas, no revela el secreto',
  strength_score TINYINT UNSIGNED NULL,
  fingerprint   CHAR(64) NULL COMMENT 'HMAC del secreto: detecta reutilizacion sin descifrar',
  change_reason VARCHAR(255) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  retired_at    DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_secret_version (credential_id, field, label, version),
  KEY idx_secret_current (credential_id, field, is_current),
  KEY idx_secret_fingerprint (fingerprint),
  CONSTRAINT fk_secrets_credential FOREIGN KEY (credential_id) REFERENCES credentials (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial de CAMBIOS (metadatos). Nunca contiene secretos, solo trazabilidad.
CREATE TABLE IF NOT EXISTS credential_history (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  credential_id  INT UNSIGNED NOT NULL,
  secret_version INT UNSIGNED NULL COMMENT 'version de secreto asociada, si aplica',
  action         VARCHAR(50)  NOT NULL COMMENT 'created|updated|password_rotated|status_changed|assigned|revoked|deleted|restored',
  field_changed  VARCHAR(80)  NULL,
  old_value      VARCHAR(500) NULL COMMENT 'valor anterior NO sensible o enmascarado',
  new_value      VARCHAR(500) NULL COMMENT 'valor nuevo NO sensible o enmascarado',
  old_status     VARCHAR(30)  NULL,
  new_status     VARCHAR(30)  NULL,
  old_expires_at DATE NULL,
  new_expires_at DATE NULL,
  reason         VARCHAR(255) NULL,
  performed_by   INT UNSIGNED NULL,
  performed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address     VARCHAR(45) NULL,
  metadata       TEXT NULL COMMENT 'JSON adicional, sin secretos',
  PRIMARY KEY (id),
  KEY idx_credhist_credential (credential_id, performed_at),
  KEY idx_credhist_action (action),
  KEY idx_credhist_user (performed_by),
  CONSTRAINT fk_credhist_credential FOREIGN KEY (credential_id) REFERENCES credentials (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Asignacion de credenciales a usuarios (alcance de datos del consultor)
CREATE TABLE IF NOT EXISTS credential_assignments (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  credential_id      INT UNSIGNED NOT NULL,
  user_id            INT UNSIGNED NOT NULL,
  can_view_secret    TINYINT(1) NOT NULL DEFAULT 1,
  can_copy_secret    TINYINT(1) NOT NULL DEFAULT 1,
  can_view_recovery  TINYINT(1) NOT NULL DEFAULT 0,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  granted_by         INT UNSIGNED NULL,
  granted_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at         DATETIME NULL,
  revoked_at         DATETIME NULL,
  revoked_by         INT UNSIGNED NULL,
  revoke_reason      VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assignment (credential_id, user_id),
  KEY idx_assign_user (user_id, is_active),
  KEY idx_assign_cred (credential_id, is_active),
  CONSTRAINT fk_assign_credential FOREIGN KEY (credential_id) REFERENCES credentials (id) ON DELETE CASCADE,
  CONSTRAINT fk_assign_user       FOREIGN KEY (user_id)       REFERENCES users (id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. AUDITORIA Y SEGURIDAD
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  occurred_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  user_id        INT UNSIGNED NULL,
  actor_national_id VARCHAR(30) NULL COMMENT 'cedula desnormalizada: el historico no debe perderse',
  actor_name     VARCHAR(160) NULL,
  action         VARCHAR(60)  NOT NULL,
  entity_type    VARCHAR(50)  NULL,
  entity_id      VARCHAR(64)  NULL,
  entity_label   VARCHAR(255) NULL,
  result         ENUM('success','failure','denied') NOT NULL DEFAULT 'success',
  severity       ENUM('info','notice','warning','critical') NOT NULL DEFAULT 'info',
  ip_address     VARCHAR(45)  NULL,
  user_agent     VARCHAR(255) NULL,
  device         VARCHAR(120) NULL,
  session_id     CHAR(64)     NULL,
  http_method    VARCHAR(10)  NULL,
  route          VARCHAR(255) NULL,
  details        TEXT NULL COMMENT 'JSON. Filtrado: jamas contiene secretos',
  PRIMARY KEY (id),
  KEY idx_audit_time (occurred_at),
  KEY idx_audit_user (user_id, occurred_at),
  KEY idx_audit_action (action, occurred_at),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_result (result),
  KEY idx_audit_nid (actor_national_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro dedicado de ACCESO A SECRETOS (art. 7, 38, 44)
CREATE TABLE IF NOT EXISTS secret_access_log (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  credential_id  INT UNSIGNED NOT NULL,
  secret_id      BIGINT UNSIGNED NULL,
  secret_field   VARCHAR(30) NOT NULL DEFAULT 'password',
  secret_version INT UNSIGNED NULL,
  user_id        INT UNSIGNED NULL,
  actor_national_id VARCHAR(30) NULL,
  access_type    ENUM('view','copy','export','history_view','api_read') NOT NULL,
  result         ENUM('success','denied') NOT NULL DEFAULT 'success',
  reason         VARCHAR(255) NULL,
  report_id      BIGINT UNSIGNED NULL,
  ip_address     VARCHAR(45) NULL,
  user_agent     VARCHAR(255) NULL,
  session_id     CHAR(64) NULL,
  occurred_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_sal_cred (credential_id, occurred_at),
  KEY idx_sal_user (user_id, occurred_at),
  KEY idx_sal_type (access_type),
  CONSTRAINT fk_sal_credential FOREIGN KEY (credential_id) REFERENCES credentials (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier    VARCHAR(190) NOT NULL COMMENT 'usuario/cedula/email intentado (nunca la contrasena)',
  user_id       INT UNSIGNED NULL,
  ip_address    VARCHAR(45)  NULL,
  user_agent    VARCHAR(255) NULL,
  result        ENUM('success','failed','locked','mfa_failed','mfa_required') NOT NULL,
  reason        VARCHAR(120) NULL,
  attempted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_la_identifier (identifier, attempted_at),
  KEY idx_la_ip (ip_address, attempted_at),
  KEY idx_la_user (user_id, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  id             CHAR(64) NOT NULL COMMENT 'SHA-256 del token de sesion (el token vive solo en la cookie)',
  user_id        INT UNSIGNED NOT NULL,
  csrf_token     CHAR(64) NOT NULL,
  ip_address     VARCHAR(45)  NULL,
  user_agent     VARCHAR(255) NULL,
  device         VARCHAR(120) NULL,
  mfa_verified   TINYINT(1) NOT NULL DEFAULT 0,
  pending_mfa    TINYINT(1) NOT NULL DEFAULT 0,
  reauth_at      DATETIME NULL COMMENT 'ultima reautenticacion (step-up)',
  status         ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  absolute_expires_at DATETIME NOT NULL,
  revoked_at     DATETIME NULL,
  revoked_by     INT UNSIGNED NULL,
  revoke_reason  VARCHAR(120) NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_user (user_id, status),
  KEY idx_sessions_activity (last_activity_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS security_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type          VARCHAR(60) NOT NULL,
  severity      ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  user_id       INT UNSIGNED NULL,
  ip_address    VARCHAR(45) NULL,
  title         VARCHAR(180) NOT NULL,
  message       VARCHAR(500) NULL,
  details       TEXT NULL,
  status        ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acknowledged_by INT UNSIGNED NULL,
  resolved_by   INT UNSIGNED NULL,
  resolved_at   DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_secev_status (status, severity),
  KEY idx_secev_time (created_at),
  KEY idx_secev_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NULL COMMENT 'NULL = dirigida a un rol',
  role_id       SMALLINT UNSIGNED NULL,
  type          VARCHAR(60) NOT NULL,
  severity      ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  title         VARCHAR(180) NOT NULL,
  message       VARCHAR(500) NULL,
  link          VARCHAR(255) NULL,
  dedupe_key    VARCHAR(120) NULL COMMENT 'evita alertas duplicadas por dia',
  is_read       TINYINT(1) NOT NULL DEFAULT 0,
  read_at       DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notif_dedupe (dedupe_key),
  KEY idx_notif_user (user_id, is_read, created_at),
  KEY idx_notif_role (role_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket        VARCHAR(190) NOT NULL,
  hits          INT UNSIGNED NOT NULL DEFAULT 0,
  window_start  DATETIME NOT NULL,
  expires_at    DATETIME NOT NULL,
  PRIMARY KEY (bucket),
  KEY idx_rl_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  token_hash    CHAR(64) NOT NULL,
  ip_address    VARCHAR(45) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME NOT NULL,
  used_at       DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pr_token (token_hash),
  KEY idx_pr_user (user_id),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mfa_secrets (
  user_id       INT UNSIGNED NOT NULL,
  key_version   SMALLINT UNSIGNED NOT NULL,
  ciphertext    VARBINARY(512) NOT NULL,
  nonce         VARBINARY(12)  NOT NULL,
  tag           VARBINARY(16)  NOT NULL,
  wrapped_dek   VARBINARY(64)  NOT NULL,
  dek_nonce     VARBINARY(12)  NOT NULL,
  dek_tag       VARBINARY(16)  NOT NULL,
  confirmed_at  DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_mfa_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mfa_backup_codes (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  code_hash     VARCHAR(255) NOT NULL,
  used_at       DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mbc_user (user_id, used_at),
  CONSTRAINT fk_mbc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. REPORTES / EXPORTACIONES
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS export_reports (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36) NOT NULL,
  user_id         INT UNSIGNED NULL,
  actor_national_id VARCHAR(30) NULL,
  report_type     ENUM('inventory','full_credentials','history','audit','users','systems') NOT NULL,
  format          VARCHAR(10) NOT NULL DEFAULT 'xlsx',
  filters         TEXT NULL COMMENT 'JSON de filtros aplicados',
  record_count    INT UNSIGNED NOT NULL DEFAULT 0,
  included_secrets TINYINT(1) NOT NULL DEFAULT 0,
  file_name       VARCHAR(190) NULL,
  file_hash       CHAR(64) NULL,
  file_size       INT UNSIGNED NULL,
  storage_path    VARCHAR(255) NULL COMMENT 'fuera del webroot; se elimina al vencer',
  status          ENUM('generating','ready','downloaded','expired','purged','failed') NOT NULL DEFAULT 'generating',
  ip_address      VARCHAR(45) NULL,
  user_agent      VARCHAR(255) NULL,
  session_id      CHAR(64) NULL,
  download_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  downloaded_at   DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at      DATETIME NOT NULL,
  purged_at       DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_export_uuid (uuid),
  KEY idx_export_user (user_id, created_at),
  KEY idx_export_status (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Que credenciales concretas viajaron en cada exportacion (trazabilidad art. 44)
CREATE TABLE IF NOT EXISTS export_report_items (
  report_id     BIGINT UNSIGNED NOT NULL,
  credential_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (report_id, credential_id),
  KEY idx_eri_cred (credential_id),
  CONSTRAINT fk_eri_report FOREIGN KEY (report_id)     REFERENCES export_reports (id) ON DELETE CASCADE,
  CONSTRAINT fk_eri_cred   FOREIGN KEY (credential_id) REFERENCES credentials (id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(80) NOT NULL,
  setting_value TEXT NULL,
  value_type    ENUM('string','int','bool','json') NOT NULL DEFAULT 'string',
  group_name    VARCHAR(50) NOT NULL DEFAULT 'general',
  label         VARCHAR(150) NULL,
  description   VARCHAR(255) NULL,
  updated_by    INT UNSIGNED NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  KEY idx_settings_group (group_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
