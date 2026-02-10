-- Compliance Hardening Upgrade
-- Audit logging, scoped exports, and backup/recovery readiness
-- Safe to run repeatedly.

START TRANSACTION;

ALTER TABLE audit_logs
  ADD COLUMN IF NOT EXISTS garage_id INT UNSIGNED NULL AFTER company_id,
  ADD COLUMN IF NOT EXISTS role_key VARCHAR(50) NULL AFTER user_id,
  ADD COLUMN IF NOT EXISTS entity_name VARCHAR(80) NULL AFTER module_name,
  ADD COLUMN IF NOT EXISTS source_channel VARCHAR(40) NULL AFTER action_name,
  ADD COLUMN IF NOT EXISTS before_snapshot JSON NULL AFTER details,
  ADD COLUMN IF NOT EXISTS after_snapshot JSON NULL AFTER before_snapshot,
  ADD COLUMN IF NOT EXISTS metadata_json JSON NULL AFTER after_snapshot,
  ADD COLUMN IF NOT EXISTS request_id VARCHAR(64) NULL AFTER metadata_json;

ALTER TABLE audit_logs
  ADD INDEX IF NOT EXISTS idx_audit_scope_created (company_id, garage_id, created_at),
  ADD INDEX IF NOT EXISTS idx_audit_user_created (user_id, created_at),
  ADD INDEX IF NOT EXISTS idx_audit_entity_action (entity_name, action_name, created_at),
  ADD INDEX IF NOT EXISTS idx_audit_source_created (source_channel, created_at),
  ADD INDEX IF NOT EXISTS idx_audit_request_id (request_id);

CREATE TABLE IF NOT EXISTS data_export_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NULL,
  module_key VARCHAR(60) NOT NULL,
  format_key VARCHAR(20) NOT NULL DEFAULT 'CSV',
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  include_draft TINYINT(1) NOT NULL DEFAULT 0,
  include_cancelled TINYINT(1) NOT NULL DEFAULT 0,
  filter_summary VARCHAR(255) NULL,
  scope_json JSON NULL,
  requested_by INT UNSIGNED NULL,
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_export_logs_scope_date (company_id, garage_id, requested_at),
  KEY idx_export_logs_module (module_key, requested_at),
  CONSTRAINT fk_export_logs_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_export_logs_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS backup_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  backup_type ENUM('MANUAL', 'SCHEDULED', 'RESTORE_POINT') NOT NULL DEFAULT 'MANUAL',
  backup_label VARCHAR(140) NOT NULL,
  dump_file_name VARCHAR(255) NOT NULL,
  file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  checksum_sha256 VARCHAR(128) NULL,
  dump_started_at DATETIME NULL,
  dump_completed_at DATETIME NULL,
  status_code ENUM('SUCCESS', 'FAILED') NOT NULL DEFAULT 'SUCCESS',
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_backup_runs_scope_date (company_id, created_at),
  KEY idx_backup_runs_status (status_code, created_at),
  CONSTRAINT fk_backup_runs_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_backup_runs_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS backup_integrity_checks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  result_code ENUM('PASS', 'WARN', 'FAIL') NOT NULL DEFAULT 'PASS',
  issues_count INT UNSIGNED NOT NULL DEFAULT 0,
  summary_json JSON NULL,
  checked_by INT UNSIGNED NULL,
  checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_backup_checks_scope_date (company_id, checked_at),
  KEY idx_backup_checks_result (result_code, checked_at),
  CONSTRAINT fk_backup_checks_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_backup_checks_checked_by FOREIGN KEY (checked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'audit.view', 'View immutable audit logs', 'ACTIVE'
WHERE NOT EXISTS (
  SELECT 1 FROM permissions WHERE perm_key = 'audit.view'
);

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'export.data', 'Export scoped operational and financial data', 'ACTIVE'
WHERE NOT EXISTS (
  SELECT 1 FROM permissions WHERE perm_key = 'export.data'
);

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'backup.manage', 'Manage backup metadata and recovery checks', 'ACTIVE'
WHERE NOT EXISTS (
  SELECT 1 FROM permissions WHERE perm_key = 'backup.manage'
);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key = 'audit.view'
WHERE r.role_key IN ('super_admin', 'garage_owner', 'accountant')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key = 'export.data'
WHERE r.role_key IN ('super_admin', 'garage_owner', 'accountant')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key = 'backup.manage'
WHERE r.role_key IN ('super_admin', 'garage_owner')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

COMMIT;

DROP TRIGGER IF EXISTS trg_audit_logs_no_update;
DROP TRIGGER IF EXISTS trg_audit_logs_no_delete;

DELIMITER $$
CREATE TRIGGER trg_audit_logs_no_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'audit_logs rows are immutable and cannot be updated';
END$$

CREATE TRIGGER trg_audit_logs_no_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'audit_logs rows are immutable and cannot be deleted';
END$$
DELIMITER ;
