-- Outsourced Works Financial Control Upgrade
-- Safe to run repeatedly.

START TRANSACTION;

ALTER TABLE job_labor
  ADD COLUMN IF NOT EXISTS outsource_expected_return_date DATE NULL AFTER outsource_cost;

ALTER TABLE job_labor
  ADD INDEX IF NOT EXISTS idx_job_labor_outsource_expected_return (outsource_expected_return_date);

CREATE TABLE IF NOT EXISTS outsourced_works (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  job_card_id INT UNSIGNED NOT NULL,
  job_labor_id INT UNSIGNED NULL,
  vendor_id INT UNSIGNED NULL,
  partner_name VARCHAR(150) NOT NULL,
  service_description VARCHAR(255) NOT NULL,
  agreed_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  expected_return_date DATE NULL,
  current_status ENUM('SENT', 'RECEIVED', 'VERIFIED', 'PAYABLE', 'PAID') NOT NULL DEFAULT 'SENT',
  sent_at DATETIME NULL,
  received_at DATETIME NULL,
  verified_at DATETIME NULL,
  payable_at DATETIME NULL,
  paid_at DATETIME NULL,
  notes VARCHAR(255) NULL,
  status_code ENUM('ACTIVE', 'INACTIVE', 'DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_outsourced_work_job_labor (job_labor_id),
  KEY idx_outsourced_works_scope_status (company_id, garage_id, current_status, status_code),
  KEY idx_outsourced_works_vendor_status (vendor_id, current_status, status_code),
  KEY idx_outsourced_works_job (job_card_id, job_labor_id),
  KEY idx_outsourced_works_expected_return (expected_return_date),
  KEY idx_outsourced_works_paid_at (paid_at),
  CONSTRAINT fk_outsourced_works_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_outsourced_works_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE,
  CONSTRAINT fk_outsourced_works_job_card FOREIGN KEY (job_card_id) REFERENCES job_cards(id) ON DELETE CASCADE,
  CONSTRAINT fk_outsourced_works_job_labor FOREIGN KEY (job_labor_id) REFERENCES job_labor(id) ON DELETE SET NULL,
  CONSTRAINT fk_outsourced_works_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
  CONSTRAINT fk_outsourced_works_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_outsourced_works_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS outsourced_work_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outsourced_work_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  payment_date DATE NOT NULL,
  entry_type ENUM('PAYMENT', 'REVERSAL') NOT NULL DEFAULT 'PAYMENT',
  amount DECIMAL(12,2) NOT NULL,
  payment_mode ENUM('CASH', 'UPI', 'CARD', 'BANK_TRANSFER', 'CHEQUE', 'MIXED', 'ADJUSTMENT') NOT NULL DEFAULT 'BANK_TRANSFER',
  reference_no VARCHAR(100) NULL,
  notes VARCHAR(255) NULL,
  reversed_payment_id BIGINT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_outsourced_work_payments_work_date (outsourced_work_id, payment_date),
  KEY idx_outsourced_work_payments_scope_date (company_id, garage_id, payment_date),
  KEY idx_outsourced_work_payments_entry (entry_type, created_at),
  KEY idx_outsourced_work_payments_reversed (reversed_payment_id),
  CONSTRAINT fk_outsourced_work_payments_work FOREIGN KEY (outsourced_work_id) REFERENCES outsourced_works(id) ON DELETE CASCADE,
  CONSTRAINT fk_outsourced_work_payments_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_outsourced_work_payments_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE,
  CONSTRAINT fk_outsourced_work_payments_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_outsourced_work_payments_reversed FOREIGN KEY (reversed_payment_id) REFERENCES outsourced_work_payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS outsourced_work_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  outsourced_work_id INT UNSIGNED NOT NULL,
  action_type VARCHAR(40) NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NULL,
  action_note VARCHAR(255) NULL,
  payload_json JSON NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_outsourced_work_history_work (outsourced_work_id, created_at),
  KEY idx_outsourced_work_history_action (action_type, created_at),
  CONSTRAINT fk_outsourced_work_history_work FOREIGN KEY (outsourced_work_id) REFERENCES outsourced_works(id) ON DELETE CASCADE,
  CONSTRAINT fk_outsourced_work_history_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO outsourced_works (
    company_id,
    garage_id,
    job_card_id,
    job_labor_id,
    vendor_id,
    partner_name,
    service_description,
    agreed_cost,
    expected_return_date,
    current_status,
    sent_at,
    payable_at,
    paid_at,
    status_code,
    created_by,
    updated_by,
    created_at,
    updated_at
)
SELECT
    jc.company_id,
    jc.garage_id,
    jc.id,
    jl.id,
    jl.outsource_vendor_id,
    COALESCE(NULLIF(TRIM(jl.outsource_partner_name), ''), NULLIF(TRIM(v.vendor_name), ''), 'Outsource Partner'),
    LEFT(COALESCE(NULLIF(TRIM(jl.description), ''), 'Outsourced work'), 255),
    ROUND(COALESCE(jl.outsource_cost, 0), 2),
    jl.outsource_expected_return_date,
    CASE
        WHEN jl.outsource_payable_status = 'PAID' AND COALESCE(jl.outsource_cost, 0) > 0 THEN 'PAID'
        WHEN COALESCE(jl.outsource_cost, 0) > 0 THEN 'PAYABLE'
        ELSE 'SENT'
    END,
    COALESCE(jl.created_at, NOW()),
    CASE
        WHEN COALESCE(jl.outsource_cost, 0) > 0 THEN COALESCE(jl.updated_at, jl.created_at, NOW())
        ELSE NULL
    END,
    CASE
        WHEN jl.outsource_payable_status = 'PAID' AND COALESCE(jl.outsource_cost, 0) > 0
            THEN COALESCE(jl.outsource_paid_at, jl.updated_at, jl.created_at, NOW())
        ELSE NULL
    END,
    'ACTIVE',
    jc.created_by,
    COALESCE(jl.outsource_paid_by, jc.updated_by, jc.created_by),
    COALESCE(jl.created_at, NOW()),
    COALESCE(jl.updated_at, jl.created_at, NOW())
FROM job_labor jl
INNER JOIN job_cards jc ON jc.id = jl.job_card_id
LEFT JOIN vendors v ON v.id = jl.outsource_vendor_id
WHERE jl.execution_type = 'OUTSOURCED'
  AND COALESCE(jl.outsource_cost, 0) > 0
  AND NOT EXISTS (
      SELECT 1
      FROM outsourced_works ow
      WHERE ow.job_labor_id = jl.id
  );

INSERT INTO outsourced_work_payments (
    outsourced_work_id,
    company_id,
    garage_id,
    payment_date,
    entry_type,
    amount,
    payment_mode,
    reference_no,
    notes,
    created_by,
    created_at
)
SELECT
    ow.id,
    ow.company_id,
    ow.garage_id,
    DATE(COALESCE(ow.paid_at, ow.payable_at, ow.created_at)),
    'PAYMENT',
    ROUND(ow.agreed_cost, 2),
    'ADJUSTMENT',
    CONCAT('LEGACY-', ow.id),
    'Legacy sync from outsourced payable status.',
    COALESCE(ow.updated_by, ow.created_by),
    COALESCE(ow.paid_at, ow.updated_at, ow.created_at)
FROM outsourced_works ow
WHERE ow.current_status = 'PAID'
  AND ow.agreed_cost > 0
  AND NOT EXISTS (
      SELECT 1
      FROM outsourced_work_payments owp
      WHERE owp.outsourced_work_id = ow.id
  );

INSERT INTO outsourced_work_history (
    outsourced_work_id,
    action_type,
    from_status,
    to_status,
    action_note,
    payload_json,
    created_by,
    created_at
)
SELECT
    ow.id,
    'LEGACY_SYNC',
    NULL,
    ow.current_status,
    'Legacy outsourced work synchronized.',
    JSON_OBJECT(
        'agreed_cost', ow.agreed_cost,
        'job_card_id', ow.job_card_id,
        'job_labor_id', ow.job_labor_id
    ),
    ow.created_by,
    ow.created_at
FROM outsourced_works ow
WHERE NOT EXISTS (
    SELECT 1
    FROM outsourced_work_history owh
    WHERE owh.outsourced_work_id = ow.id
);

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'outsourced.view', 'View outsourced works and vendor payables', 'ACTIVE'
WHERE NOT EXISTS (
  SELECT 1 FROM permissions WHERE perm_key = 'outsourced.view'
);

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'outsourced.manage', 'Manage outsourced lifecycle and job linkage', 'ACTIVE'
WHERE NOT EXISTS (
  SELECT 1 FROM permissions WHERE perm_key = 'outsourced.manage'
);

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'outsourced.pay', 'Record outsourced vendor payments and reversals', 'ACTIVE'
WHERE NOT EXISTS (
  SELECT 1 FROM permissions WHERE perm_key = 'outsourced.pay'
);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key = 'outsourced.view'
WHERE r.role_key IN ('super_admin', 'garage_owner', 'manager', 'accountant')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key = 'outsourced.manage'
WHERE r.role_key IN ('super_admin', 'garage_owner', 'manager')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key = 'outsourced.pay'
WHERE r.role_key IN ('super_admin', 'garage_owner', 'manager', 'accountant')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

COMMIT;
