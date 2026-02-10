-- Estimate Module Upgrade
-- Safe and idempotent.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS estimate_counters (
  garage_id INT UNSIGNED PRIMARY KEY,
  prefix VARCHAR(20) NOT NULL DEFAULT 'EST',
  current_number INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_estimate_counters_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS estimates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  estimate_number VARCHAR(40) NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  vehicle_id INT UNSIGNED NOT NULL,
  complaint TEXT NOT NULL,
  notes TEXT NULL,
  estimate_status ENUM('DRAFT', 'APPROVED', 'REJECTED', 'CONVERTED') NOT NULL DEFAULT 'DRAFT',
  estimate_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  valid_until DATE NULL,
  approved_at DATETIME NULL,
  rejected_at DATETIME NULL,
  reject_reason VARCHAR(255) NULL,
  converted_at DATETIME NULL,
  converted_job_card_id INT UNSIGNED NULL,
  status_code ENUM('ACTIVE', 'INACTIVE', 'DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_estimate_number_per_garage (garage_id, estimate_number),
  KEY idx_estimates_scope (company_id, garage_id, estimate_status, status_code),
  KEY idx_estimates_customer (customer_id),
  KEY idx_estimates_vehicle (vehicle_id),
  KEY idx_estimates_created (created_at),
  KEY idx_estimates_converted_job (converted_job_card_id),
  CONSTRAINT fk_estimates_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_estimates_garage FOREIGN KEY (garage_id) REFERENCES garages(id),
  CONSTRAINT fk_estimates_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_estimates_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  CONSTRAINT fk_estimates_converted_job FOREIGN KEY (converted_job_card_id) REFERENCES job_cards(id) ON DELETE SET NULL,
  CONSTRAINT fk_estimates_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_estimates_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS estimate_services (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  estimate_id INT UNSIGNED NOT NULL,
  service_id INT UNSIGNED NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_estimate_services_estimate (estimate_id),
  KEY idx_estimate_services_service (service_id),
  CONSTRAINT fk_estimate_services_estimate FOREIGN KEY (estimate_id) REFERENCES estimates(id) ON DELETE CASCADE,
  CONSTRAINT fk_estimate_services_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS estimate_parts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  estimate_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_estimate_parts_estimate (estimate_id),
  KEY idx_estimate_parts_part (part_id),
  CONSTRAINT fk_estimate_parts_estimate FOREIGN KEY (estimate_id) REFERENCES estimates(id) ON DELETE CASCADE,
  CONSTRAINT fk_estimate_parts_part FOREIGN KEY (part_id) REFERENCES parts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS estimate_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  estimate_id INT UNSIGNED NOT NULL,
  action_type VARCHAR(60) NOT NULL,
  from_status VARCHAR(40) NULL,
  to_status VARCHAR(40) NULL,
  action_note VARCHAR(255) NULL,
  payload_json JSON NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_estimate_history_estimate_created (estimate_id, created_at),
  KEY idx_estimate_history_action (action_type),
  CONSTRAINT fk_estimate_history_estimate FOREIGN KEY (estimate_id) REFERENCES estimates(id) ON DELETE CASCADE,
  CONSTRAINT fk_estimate_history_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE job_cards
  ADD COLUMN IF NOT EXISTS estimate_id INT UNSIGNED NULL;

ALTER TABLE job_cards
  ADD INDEX IF NOT EXISTS idx_job_cards_estimate_id (estimate_id);

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'estimate.view', 'View estimates', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'estimate.view');

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'estimate.create', 'Create estimates', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'estimate.create');

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'estimate.edit', 'Edit estimates', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'estimate.edit');

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'estimate.approve', 'Approve estimates', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'estimate.approve');

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'estimate.reject', 'Reject estimates', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'estimate.reject');

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'estimate.convert', 'Convert approved estimates to job cards', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'estimate.convert');

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'estimate.print', 'Print estimates', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'estimate.print');

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'estimate.manage', 'Manage estimate workflow end-to-end', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'estimate.manage');

UPDATE permissions
SET status_code = 'ACTIVE'
WHERE perm_key IN (
    'estimate.view',
    'estimate.create',
    'estimate.edit',
    'estimate.approve',
    'estimate.reject',
    'estimate.convert',
    'estimate.print',
    'estimate.manage'
)
  AND (status_code IS NULL OR status_code <> 'ACTIVE');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key IN (
    'estimate.view',
    'estimate.create',
    'estimate.edit',
    'estimate.approve',
    'estimate.reject',
    'estimate.convert',
    'estimate.print',
    'estimate.manage'
)
WHERE r.role_key = 'super_admin'
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key IN (
    'estimate.view',
    'estimate.create',
    'estimate.edit',
    'estimate.approve',
    'estimate.reject',
    'estimate.convert',
    'estimate.print'
)
WHERE r.role_key IN ('garage_owner', 'manager')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key IN ('estimate.view', 'estimate.print')
WHERE r.role_key = 'accountant'
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

COMMIT;
