-- Job Card Workflow Upgrade (safe and idempotent)

ALTER TABLE job_cards
  MODIFY COLUMN status ENUM('OPEN','IN_PROGRESS','WAITING_PARTS','READY_FOR_DELIVERY','COMPLETED','CLOSED','CANCELLED') NOT NULL DEFAULT 'OPEN';

ALTER TABLE job_cards
  ADD COLUMN IF NOT EXISTS status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS cancel_note VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS closed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS stock_posted_at DATETIME NULL;

ALTER TABLE job_cards
  ADD INDEX IF NOT EXISTS idx_job_cards_status_code (status_code),
  ADD INDEX IF NOT EXISTS idx_job_cards_company_garage_status (company_id, garage_id, status);

UPDATE job_cards
SET status_code = CASE
  WHEN status = 'CANCELLED' THEN 'INACTIVE'
  ELSE 'ACTIVE'
END
WHERE status_code <> 'DELETED';

UPDATE job_cards
SET closed_at = COALESCE(closed_at, completed_at, updated_at)
WHERE status = 'CLOSED' AND closed_at IS NULL;

CREATE TABLE IF NOT EXISTS job_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_card_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  assignment_role ENUM('MECHANIC','SUPPORT','INSPECTOR') NOT NULL DEFAULT 'MECHANIC',
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_job_assignment_user (job_card_id, user_id),
  KEY idx_job_assignments_job (job_card_id, status_code),
  KEY idx_job_assignments_user (user_id),
  CONSTRAINT fk_job_assignments_job_card FOREIGN KEY (job_card_id) REFERENCES job_cards(id) ON DELETE CASCADE,
  CONSTRAINT fk_job_assignments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_job_assignments_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO job_assignments (job_card_id, user_id, assignment_role, is_primary, status_code, created_by)
SELECT jc.id, jc.assigned_to, 'MECHANIC', 1, 'ACTIVE', jc.created_by
FROM job_cards jc
WHERE jc.assigned_to IS NOT NULL;

CREATE TABLE IF NOT EXISTS job_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_card_id INT UNSIGNED NOT NULL,
  action_type VARCHAR(60) NOT NULL,
  from_status VARCHAR(40) NULL,
  to_status VARCHAR(40) NULL,
  action_note VARCHAR(255) NULL,
  payload_json JSON NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_job_history_job_created (job_card_id, created_at),
  KEY idx_job_history_action (action_type),
  CONSTRAINT fk_job_history_job_card FOREIGN KEY (job_card_id) REFERENCES job_cards(id) ON DELETE CASCADE,
  CONSTRAINT fk_job_history_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE job_labor
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE job_parts
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

INSERT IGNORE INTO permissions (perm_key, perm_name, status_code) VALUES
('job.create', 'Create job cards', 'ACTIVE'),
('job.edit', 'Edit job cards', 'ACTIVE'),
('job.close', 'Close job cards', 'ACTIVE');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key IN ('job.create','job.edit','job.close')
WHERE r.role_key = 'super_admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key IN ('job.create','job.edit','job.close')
WHERE r.role_key IN ('garage_owner', 'manager');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key IN ('job.edit')
WHERE r.role_key = 'mechanic';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key IN ('job.view')
WHERE r.role_key = 'accountant';

