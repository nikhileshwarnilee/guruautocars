-- Purchase Module Upgrade
-- Safe to run repeatedly.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS purchases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  vendor_id INT UNSIGNED NULL,
  invoice_number VARCHAR(80) NULL,
  purchase_date DATE NOT NULL,
  purchase_source ENUM('VENDOR_ENTRY', 'MANUAL_ADJUSTMENT') NOT NULL DEFAULT 'VENDOR_ENTRY',
  assignment_status ENUM('ASSIGNED', 'UNASSIGNED') NOT NULL DEFAULT 'ASSIGNED',
  purchase_status ENUM('DRAFT', 'FINALIZED') NOT NULL DEFAULT 'DRAFT',
  payment_status ENUM('UNPAID', 'PARTIAL', 'PAID') NOT NULL DEFAULT 'UNPAID',
  taxable_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  gst_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  grand_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  finalized_by INT UNSIGNED NULL,
  finalized_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_purchases_scope_date (company_id, garage_id, purchase_date),
  KEY idx_purchases_vendor (vendor_id),
  KEY idx_purchases_assignment (assignment_status, purchase_status),
  KEY idx_purchases_payment (payment_status),
  CONSTRAINT fk_purchases_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchases_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchases_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
  CONSTRAINT fk_purchases_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_purchases_finalized_by FOREIGN KEY (finalized_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  taxable_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  gst_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_purchase_items_purchase (purchase_id),
  KEY idx_purchase_items_part (part_id),
  CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_items_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'purchase.view', 'View purchase module', 'ACTIVE'
WHERE NOT EXISTS (
  SELECT 1 FROM permissions WHERE perm_key = 'purchase.view'
);

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'purchase.manage', 'Create and update purchases', 'ACTIVE'
WHERE NOT EXISTS (
  SELECT 1 FROM permissions WHERE perm_key = 'purchase.manage'
);

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'purchase.finalize', 'Finalize purchases', 'ACTIVE'
WHERE NOT EXISTS (
  SELECT 1 FROM permissions WHERE perm_key = 'purchase.finalize'
);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key IN ('purchase.view', 'purchase.manage', 'purchase.finalize')
WHERE r.role_key IN ('super_admin', 'garage_owner', 'manager', 'accountant')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

COMMIT;
