-- Financial Control & Compliance Upgrade
-- Safe to run repeatedly.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS purchase_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id INT UNSIGNED NOT NULL,
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
  KEY idx_purchase_payments_purchase_date (purchase_id, payment_date),
  KEY idx_purchase_payments_scope_date (company_id, garage_id, payment_date),
  KEY idx_purchase_payments_entry (entry_type, created_at),
  KEY idx_purchase_payments_reversed (reversed_payment_id),
  CONSTRAINT fk_purchase_payments_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_payments_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_payments_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_payments_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_purchase_payments_reversed FOREIGN KEY (reversed_payment_id) REFERENCES purchase_payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO purchase_payments (
    purchase_id,
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
    p.id,
    p.company_id,
    p.garage_id,
    p.purchase_date,
    'PAYMENT',
    ROUND(p.grand_total, 2),
    'ADJUSTMENT',
    CONCAT('LEGACY-', p.id),
    'Legacy sync from purchase payment status.',
    p.created_by,
    COALESCE(p.finalized_at, p.created_at)
FROM purchases p
WHERE p.payment_status = 'PAID'
  AND p.grand_total > 0
  AND NOT EXISTS (
      SELECT 1
      FROM purchase_payments pp
      WHERE pp.purchase_id = p.id
  );

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'vendor.payments', 'View vendor payable summaries and aging', 'ACTIVE'
WHERE NOT EXISTS (
  SELECT 1 FROM permissions WHERE perm_key = 'vendor.payments'
);

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'purchase.payments', 'Record purchase payments and reversals', 'ACTIVE'
WHERE NOT EXISTS (
  SELECT 1 FROM permissions WHERE perm_key = 'purchase.payments'
);

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'gst.reports', 'Access GST compliance reports', 'ACTIVE'
WHERE NOT EXISTS (
  SELECT 1 FROM permissions WHERE perm_key = 'gst.reports'
);

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'financial.reports', 'Access financial compliance reports', 'ACTIVE'
WHERE NOT EXISTS (
  SELECT 1 FROM permissions WHERE perm_key = 'financial.reports'
);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key = 'vendor.payments'
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
INNER JOIN permissions p ON p.perm_key = 'purchase.payments'
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
INNER JOIN permissions p ON p.perm_key = 'gst.reports'
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
INNER JOIN permissions p ON p.perm_key = 'financial.reports'
WHERE r.role_key IN ('super_admin', 'garage_owner', 'manager', 'accountant')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

COMMIT;
