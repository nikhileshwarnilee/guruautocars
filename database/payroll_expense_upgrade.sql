-- Payroll, Advances, Loans, and Expense Management Upgrade
-- Safe to run multiple times.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS payroll_salary_structures (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  salary_type ENUM('MONTHLY','PER_DAY','PER_JOB') NOT NULL DEFAULT 'MONTHLY',
  base_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  commission_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
  overtime_rate DECIMAL(12,2) NULL,
  status_code ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_payroll_structure_user_garage (user_id, garage_id),
  KEY idx_payroll_structure_company_garage (company_id, garage_id),
  CONSTRAINT fk_payroll_structure_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_structure_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_structure_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_advances (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  advance_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  applied_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_payroll_adv_user_date (user_id, advance_date),
  KEY idx_payroll_adv_scope (company_id, garage_id, status),
  CONSTRAINT fk_payroll_adv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_adv_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_adv_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_loans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  loan_date DATE NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL,
  emi_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('ACTIVE','PAID','CLOSED') NOT NULL DEFAULT 'ACTIVE',
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_payroll_loan_user_date (user_id, loan_date),
  KEY idx_payroll_loan_scope (company_id, garage_id, status),
  CONSTRAINT fk_payroll_loan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_loan_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_loan_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_salary_sheets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  salary_month CHAR(7) NOT NULL,
  status ENUM('OPEN','LOCKED') NOT NULL DEFAULT 'OPEN',
  total_gross DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_payable DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  locked_at DATETIME NULL,
  locked_by INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_payroll_sheet (company_id, garage_id, salary_month),
  KEY idx_payroll_sheet_status (status),
  CONSTRAINT fk_payroll_sheet_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_sheet_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_sheet_locked_by FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_sheet_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_salary_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sheet_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  salary_type ENUM('MONTHLY','PER_DAY','PER_JOB') NOT NULL DEFAULT 'MONTHLY',
  base_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  commission_base DECIMAL(12,2) NOT NULL DEFAULT 0,
  commission_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
  commission_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  overtime_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
  overtime_rate DECIMAL(12,2) NULL,
  overtime_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  advance_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
  loan_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
  manual_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
  gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_payable DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  deductions_applied TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('PENDING','PARTIAL','PAID','LOCKED') NOT NULL DEFAULT 'PENDING',
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_payroll_item_sheet (sheet_id),
  KEY idx_payroll_item_user (user_id),
  CONSTRAINT fk_payroll_item_sheet FOREIGN KEY (sheet_id) REFERENCES payroll_salary_sheets(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_item_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_loan_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  loan_id BIGINT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  salary_item_id BIGINT UNSIGNED NULL,
  payment_date DATE NOT NULL,
  entry_type ENUM('EMI','MANUAL','REVERSAL') NOT NULL DEFAULT 'EMI',
  amount DECIMAL(12,2) NOT NULL,
  reference_no VARCHAR(100) NULL,
  notes VARCHAR(255) NULL,
  reversed_payment_id BIGINT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_payroll_loan_pay_scope (company_id, garage_id, payment_date),
  KEY idx_payroll_loan_pay_entry (entry_type),
  KEY idx_payroll_loan_pay_loan (loan_id),
  CONSTRAINT fk_payroll_loan_pay_loan FOREIGN KEY (loan_id) REFERENCES payroll_loans(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_loan_pay_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_loan_pay_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_loan_pay_salary_item FOREIGN KEY (salary_item_id) REFERENCES payroll_salary_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_loan_pay_reversed FOREIGN KEY (reversed_payment_id) REFERENCES payroll_loan_payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_salary_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sheet_id BIGINT UNSIGNED NOT NULL,
  salary_item_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  payment_date DATE NOT NULL,
  entry_type ENUM('PAYMENT','REVERSAL') NOT NULL DEFAULT 'PAYMENT',
  amount DECIMAL(12,2) NOT NULL,
  payment_mode ENUM('CASH','UPI','CARD','BANK_TRANSFER','CHEQUE','MIXED','ADJUSTMENT') NOT NULL DEFAULT 'BANK_TRANSFER',
  reference_no VARCHAR(100) NULL,
  notes VARCHAR(255) NULL,
  reversed_payment_id BIGINT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_payroll_pay_scope (company_id, garage_id, payment_date),
  KEY idx_payroll_pay_entry (entry_type),
  KEY idx_payroll_pay_item (salary_item_id),
  CONSTRAINT fk_payroll_pay_sheet FOREIGN KEY (sheet_id) REFERENCES payroll_salary_sheets(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_pay_item FOREIGN KEY (salary_item_id) REFERENCES payroll_salary_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_pay_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_pay_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_pay_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_pay_reversed FOREIGN KEY (reversed_payment_id) REFERENCES payroll_salary_payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expense_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  category_name VARCHAR(120) NOT NULL,
  status_code ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_expense_category (company_id, garage_id, category_name),
  KEY idx_expense_category_scope (company_id, garage_id),
  CONSTRAINT fk_expense_category_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_expense_category_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE,
  CONSTRAINT fk_expense_category_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NULL,
  expense_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  paid_to VARCHAR(120) NULL,
  payment_mode ENUM('CASH','UPI','CARD','BANK_TRANSFER','CHEQUE','MIXED','ADJUSTMENT') NOT NULL DEFAULT 'CASH',
  notes VARCHAR(255) NULL,
  source_type VARCHAR(40) NULL,
  source_id BIGINT UNSIGNED NULL,
  entry_type ENUM('EXPENSE','REVERSAL') NOT NULL DEFAULT 'EXPENSE',
  reversed_expense_id BIGINT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_expense_source (company_id, source_type, source_id, entry_type),
  KEY idx_expense_scope_date (company_id, garage_id, expense_date),
  KEY idx_expense_category (category_id),
  CONSTRAINT fk_expense_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_expense_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_expense_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE,
  CONSTRAINT fk_expense_reversed FOREIGN KEY (reversed_expense_id) REFERENCES expenses(id) ON DELETE SET NULL,
  CONSTRAINT fk_expense_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

INSERT INTO expense_categories (company_id, garage_id, category_name, status_code)
SELECT g.company_id, g.id AS garage_id, cat.category_name, 'ACTIVE'
FROM garages g
CROSS JOIN (SELECT 'Salary & Wages' AS category_name
            UNION SELECT 'Outsourced Works'
            UNION SELECT 'Purchases'
            UNION SELECT 'General Expense') cat
WHERE NOT EXISTS (
  SELECT 1 FROM expense_categories ec
  WHERE ec.company_id = g.company_id
    AND ec.garage_id = g.id
    AND ec.category_name = cat.category_name
);

COMMIT;
