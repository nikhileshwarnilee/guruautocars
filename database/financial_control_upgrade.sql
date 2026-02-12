-- Financial Control Hardening Upgrade
-- Safe to run multiple times.

START TRANSACTION;

ALTER TABLE payroll_advances
  MODIFY COLUMN status ENUM('OPEN','CLOSED','DELETED') NOT NULL DEFAULT 'OPEN';

ALTER TABLE payroll_loans
  MODIFY COLUMN status ENUM('ACTIVE','PAID','CLOSED','DELETED') NOT NULL DEFAULT 'ACTIVE';

ALTER TABLE expenses
  MODIFY COLUMN entry_type ENUM('EXPENSE','REVERSAL','DELETED') NOT NULL DEFAULT 'EXPENSE',
  MODIFY COLUMN payment_mode ENUM('CASH','UPI','CARD','BANK_TRANSFER','CHEQUE','MIXED','ADJUSTMENT','VOID') NOT NULL DEFAULT 'CASH';

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'expense_categories'
        AND column_name = 'updated_by'
    ),
    'SELECT 1',
    'ALTER TABLE expense_categories ADD COLUMN updated_by INT UNSIGNED NULL AFTER created_by'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'expenses'
        AND column_name = 'updated_by'
    ),
    'SELECT 1',
    'ALTER TABLE expenses ADD COLUMN updated_by INT UNSIGNED NULL AFTER created_by'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'payroll_advances'
        AND column_name = 'updated_by'
    ),
    'SELECT 1',
    'ALTER TABLE payroll_advances ADD COLUMN updated_by INT UNSIGNED NULL AFTER created_by'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'payroll_loans'
        AND column_name = 'updated_by'
    ),
    'SELECT 1',
    'ALTER TABLE payroll_loans ADD COLUMN updated_by INT UNSIGNED NULL AFTER created_by'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'payroll.manage', 'Manage payroll, advances, and salary payouts', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'payroll.manage');

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'payroll.view', 'View payroll and earnings', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'payroll.view');

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'expense.manage', 'Manage expenses and categories', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'expense.manage');

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'expense.view', 'View expenses and reports', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'expense.view');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key IN ('payroll.manage', 'payroll.view', 'expense.manage', 'expense.view')
WHERE r.role_key IN ('super_admin', 'garage_owner', 'manager', 'accountant')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

INSERT INTO expense_categories (company_id, garage_id, category_name, status_code, created_by)
SELECT g.company_id, g.id, cat.category_name, 'ACTIVE', NULL
FROM garages g
CROSS JOIN (
  SELECT 'Salary & Wages' AS category_name
  UNION SELECT 'Outsourced Works'
  UNION SELECT 'Purchases'
  UNION SELECT 'General Expense'
) cat
WHERE NOT EXISTS (
  SELECT 1
  FROM expense_categories ec
  WHERE ec.company_id = g.company_id
    AND ec.garage_id = g.id
    AND ec.category_name = cat.category_name
);

COMMIT;
