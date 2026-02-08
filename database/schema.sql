SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS invoice_items;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS invoice_counters;
DROP TABLE IF EXISTS job_parts;
DROP TABLE IF EXISTS job_labor;
DROP TABLE IF EXISTS job_issues;
DROP TABLE IF EXISTS job_cards;
DROP TABLE IF EXISTS job_counters;
DROP TABLE IF EXISTS inventory_movements;
DROP TABLE IF EXISTS garage_inventory;
DROP TABLE IF EXISTS parts;
DROP TABLE IF EXISTS vehicles;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS user_garages;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS garages;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS companies;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE companies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  legal_name VARCHAR(160) NULL,
  gstin VARCHAR(15) NULL UNIQUE,
  pan VARCHAR(10) NULL,
  phone VARCHAR(20) NULL,
  email VARCHAR(120) NULL,
  address_line1 VARCHAR(200) NULL,
  address_line2 VARCHAR(200) NULL,
  city VARCHAR(80) NULL,
  state VARCHAR(80) NULL,
  pincode VARCHAR(10) NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE garages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  name VARCHAR(140) NOT NULL,
  code VARCHAR(30) NOT NULL,
  phone VARCHAR(20) NULL,
  email VARCHAR(120) NULL,
  gstin VARCHAR(15) NULL,
  address_line1 VARCHAR(200) NULL,
  address_line2 VARCHAR(200) NULL,
  city VARCHAR(80) NULL,
  state VARCHAR(80) NULL,
  pincode VARCHAR(10) NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_garages_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_garage_code_per_company (company_id, code),
  KEY idx_garage_company_status (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_key VARCHAR(50) NOT NULL UNIQUE,
  role_name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  perm_key VARCHAR(80) NOT NULL UNIQUE,
  perm_name VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  primary_garage_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  phone VARCHAR(20) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_users_primary_garage FOREIGN KEY (primary_garage_id) REFERENCES garages(id) ON DELETE SET NULL,
  KEY idx_users_company_role (company_id, role_id),
  KEY idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_garages (
  user_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, garage_id),
  CONSTRAINT fk_user_garages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_garages_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NULL,
  full_name VARCHAR(150) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  alt_phone VARCHAR(20) NULL,
  email VARCHAR(150) NULL,
  gstin VARCHAR(15) NULL,
  address_line1 VARCHAR(200) NULL,
  address_line2 VARCHAR(200) NULL,
  city VARCHAR(80) NULL,
  state VARCHAR(80) NULL,
  pincode VARCHAR(10) NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_customers_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_customers_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_customers_company_phone (company_id, phone),
  KEY idx_customers_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vehicles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  registration_no VARCHAR(30) NOT NULL,
  vehicle_type ENUM('2W', '4W', 'COMMERCIAL') NOT NULL,
  brand VARCHAR(80) NOT NULL,
  model VARCHAR(100) NOT NULL,
  variant VARCHAR(100) NULL,
  fuel_type ENUM('PETROL', 'DIESEL', 'CNG', 'EV', 'HYBRID', 'OTHER') NOT NULL DEFAULT 'PETROL',
  model_year SMALLINT UNSIGNED NULL,
  color VARCHAR(40) NULL,
  chassis_no VARCHAR(60) NULL,
  engine_no VARCHAR(60) NULL,
  odometer_km INT UNSIGNED NOT NULL DEFAULT 0,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_vehicles_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_vehicles_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_vehicle_registration (company_id, registration_no),
  KEY idx_vehicles_customer (customer_id),
  KEY idx_vehicles_brand_model (brand, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE parts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  part_name VARCHAR(150) NOT NULL,
  part_sku VARCHAR(80) NOT NULL,
  hsn_code VARCHAR(20) NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'PCS',
  purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00,
  min_stock DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_parts_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_parts_sku (company_id, part_sku),
  KEY idx_parts_name (part_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE garage_inventory (
  garage_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (garage_id, part_id),
  CONSTRAINT fk_garage_inventory_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE,
  CONSTRAINT fk_garage_inventory_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE inventory_movements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  movement_type ENUM('IN', 'OUT', 'ADJUST') NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  reference_type ENUM('PURCHASE', 'JOB_CARD', 'ADJUSTMENT', 'OPENING') NOT NULL DEFAULT 'ADJUSTMENT',
  reference_id INT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_part FOREIGN KEY (part_id) REFERENCES parts(id),
  CONSTRAINT fk_inventory_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_inventory_created_at (created_at),
  KEY idx_inventory_garage_part (garage_id, part_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE job_counters (
  garage_id INT UNSIGNED PRIMARY KEY,
  prefix VARCHAR(20) NOT NULL DEFAULT 'JOB',
  current_number INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_job_counters_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE job_cards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  job_number VARCHAR(30) NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  vehicle_id INT UNSIGNED NOT NULL,
  assigned_to INT UNSIGNED NULL,
  service_advisor_id INT UNSIGNED NULL,
  complaint TEXT NOT NULL,
  diagnosis TEXT NULL,
  status ENUM('OPEN', 'IN_PROGRESS', 'WAITING_PARTS', 'READY_FOR_DELIVERY', 'COMPLETED', 'CANCELLED') NOT NULL DEFAULT 'OPEN',
  priority ENUM('LOW', 'MEDIUM', 'HIGH', 'URGENT') NOT NULL DEFAULT 'MEDIUM',
  estimated_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  promised_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_job_cards_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_job_cards_garage FOREIGN KEY (garage_id) REFERENCES garages(id),
  CONSTRAINT fk_job_cards_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_job_cards_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  CONSTRAINT fk_job_cards_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_job_cards_service_advisor FOREIGN KEY (service_advisor_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_job_cards_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_job_cards_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_job_number_per_garage (garage_id, job_number),
  KEY idx_job_status (status),
  KEY idx_job_opened (opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE job_issues (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_card_id INT UNSIGNED NOT NULL,
  issue_title VARCHAR(150) NOT NULL,
  issue_notes TEXT NULL,
  resolved_flag TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_job_issues_job_card FOREIGN KEY (job_card_id) REFERENCES job_cards(id) ON DELETE CASCADE,
  KEY idx_job_issues_job_card (job_card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE job_labor (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_card_id INT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_job_labor_job_card FOREIGN KEY (job_card_id) REFERENCES job_cards(id) ON DELETE CASCADE,
  KEY idx_job_labor_job_card (job_card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE job_parts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_card_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00,
  total_amount DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_job_parts_job_card FOREIGN KEY (job_card_id) REFERENCES job_cards(id) ON DELETE CASCADE,
  CONSTRAINT fk_job_parts_part FOREIGN KEY (part_id) REFERENCES parts(id),
  KEY idx_job_parts_job_card (job_card_id),
  KEY idx_job_parts_part (part_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE invoice_counters (
  garage_id INT UNSIGNED PRIMARY KEY,
  prefix VARCHAR(20) NOT NULL DEFAULT 'INV',
  current_number INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_invoice_counters_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  invoice_number VARCHAR(40) NOT NULL,
  job_card_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  vehicle_id INT UNSIGNED NOT NULL,
  invoice_date DATE NOT NULL,
  due_date DATE NULL,
  subtotal_service DECIMAL(12,2) NOT NULL DEFAULT 0,
  subtotal_parts DECIMAL(12,2) NOT NULL DEFAULT 0,
  taxable_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  cgst_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  sgst_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  igst_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  cgst_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  sgst_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  igst_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  round_off DECIMAL(10,2) NOT NULL DEFAULT 0,
  grand_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_status ENUM('UNPAID', 'PARTIAL', 'PAID') NOT NULL DEFAULT 'UNPAID',
  payment_mode ENUM('CASH', 'UPI', 'CARD', 'BANK_TRANSFER', 'CHEQUE') NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_invoices_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_invoices_garage FOREIGN KEY (garage_id) REFERENCES garages(id),
  CONSTRAINT fk_invoices_job_card FOREIGN KEY (job_card_id) REFERENCES job_cards(id),
  CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_invoices_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  CONSTRAINT fk_invoices_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_invoice_number_per_garage (garage_id, invoice_number),
  UNIQUE KEY uniq_invoice_job_card (job_card_id),
  KEY idx_invoices_date (invoice_date),
  KEY idx_invoices_payment_status (payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE invoice_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  item_type ENUM('LABOR', 'PART') NOT NULL,
  description VARCHAR(255) NOT NULL,
  part_id INT UNSIGNED NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  gst_rate DECIMAL(5,2) NOT NULL,
  taxable_value DECIMAL(12,2) NOT NULL,
  tax_amount DECIMAL(12,2) NOT NULL,
  total_value DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_invoice_items_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE SET NULL,
  KEY idx_invoice_items_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  paid_on DATE NOT NULL,
  payment_mode ENUM('CASH', 'UPI', 'CARD', 'BANK_TRANSFER', 'CHEQUE') NOT NULL,
  reference_no VARCHAR(100) NULL,
  notes VARCHAR(255) NULL,
  received_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_payments_received_by FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_payments_invoice (invoice_id),
  KEY idx_payments_paid_on (paid_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  module_name VARCHAR(80) NOT NULL,
  action_name VARCHAR(80) NOT NULL,
  reference_id INT UNSIGNED NULL,
  ip_address VARCHAR(45) NULL,
  details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_logs_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_audit_logs_module (module_name),
  KEY idx_audit_logs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO roles (role_key, role_name, description) VALUES
('super_admin', 'Super Admin', 'Global administrator with full control'),
('garage_owner', 'Garage Owner', 'Owner-level control for company operations'),
('manager', 'Manager', 'Branch manager for day-to-day operations'),
('mechanic', 'Mechanic', 'Technician handling assigned jobs'),
('accountant', 'Accountant / Billing Staff', 'Billing, payment and financial reporting');

INSERT INTO permissions (perm_key, perm_name) VALUES
('dashboard.view', 'View dashboard'),
('company.manage', 'Manage companies'),
('garage.manage', 'Manage garages'),
('staff.manage', 'Manage staff and assignments'),
('customer.view', 'View customers'),
('customer.manage', 'Manage customers'),
('vehicle.view', 'View vehicles'),
('vehicle.manage', 'Manage vehicles'),
('job.view', 'View job cards'),
('job.manage', 'Create and manage job cards'),
('job.assign', 'Assign mechanics to jobs'),
('job.update', 'Update job progress'),
('inventory.view', 'View inventory'),
('inventory.manage', 'Manage inventory'),
('invoice.view', 'View invoices'),
('invoice.manage', 'Create and manage invoices'),
('invoice.pay', 'Record invoice payments'),
('report.view', 'View reports and analytics');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
WHERE r.role_key = 'super_admin';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
WHERE r.role_key = 'garage_owner'
  AND p.perm_key IN (
    'dashboard.view',
    'company.manage',
    'garage.manage',
    'staff.manage',
    'customer.view',
    'customer.manage',
    'vehicle.view',
    'vehicle.manage',
    'job.view',
    'job.manage',
    'job.assign',
    'job.update',
    'inventory.view',
    'inventory.manage',
    'invoice.view',
    'invoice.manage',
    'invoice.pay',
    'report.view'
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
WHERE r.role_key = 'manager'
  AND p.perm_key IN (
    'dashboard.view',
    'staff.manage',
    'customer.view',
    'customer.manage',
    'vehicle.view',
    'vehicle.manage',
    'job.view',
    'job.manage',
    'job.assign',
    'job.update',
    'inventory.view',
    'inventory.manage',
    'invoice.view',
    'invoice.manage',
    'report.view'
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
WHERE r.role_key = 'mechanic'
  AND p.perm_key IN (
    'dashboard.view',
    'job.view',
    'job.update'
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
WHERE r.role_key = 'accountant'
  AND p.perm_key IN (
    'dashboard.view',
    'customer.view',
    'vehicle.view',
    'job.view',
    'invoice.view',
    'invoice.manage',
    'invoice.pay',
    'report.view'
  );

INSERT INTO companies (
  name,
  legal_name,
  gstin,
  pan,
  phone,
  email,
  address_line1,
  city,
  state,
  pincode
) VALUES (
  'Guru Auto Cars',
  'Guru Auto Cars Private Limited',
  '27ABCDE1234F1Z5',
  'ABCDE1234F',
  '+91-9876543210',
  'info@guruautocars.in',
  'Near Main Road, Sector 12',
  'Pune',
  'Maharashtra',
  '411001'
);

INSERT INTO garages (
  company_id,
  name,
  code,
  phone,
  email,
  gstin,
  address_line1,
  city,
  state,
  pincode
) VALUES (
  1,
  'Guru Auto Cars - Pune Main',
  'PUNE-MAIN',
  '+91-9876543210',
  'pune@guruautocars.in',
  '27ABCDE1234F1Z5',
  'Near Main Road, Sector 12',
  'Pune',
  'Maharashtra',
  '411001'
);

INSERT INTO job_counters (garage_id, prefix, current_number) VALUES (1, 'JOB', 1000);
INSERT INTO invoice_counters (garage_id, prefix, current_number) VALUES (1, 'INV', 5000);

INSERT INTO users (
  company_id,
  role_id,
  primary_garage_id,
  name,
  email,
  username,
  password_hash,
  phone,
  is_active
)
SELECT
  1,
  r.id,
  1,
  'System Admin',
  'admin@guruautocars.in',
  'admin',
  '$2y$10$5Hg.0sOfS8iPW6Ehxkn1YOwtiLxQvvTMkLP/4.SEJATNb1i9.UlMq',
  '+91-9000000000',
  1
FROM roles r
WHERE r.role_key = 'super_admin'
LIMIT 1;

INSERT INTO user_garages (user_id, garage_id) VALUES (1, 1);

INSERT INTO customers (
  company_id,
  created_by,
  full_name,
  phone,
  email,
  city,
  state
) VALUES
(1, 1, 'Ravi Sharma', '+91-9988776655', 'ravi.sharma@example.com', 'Pune', 'Maharashtra'),
(1, 1, 'Neha Verma', '+91-8899776655', 'neha.verma@example.com', 'Pune', 'Maharashtra');

INSERT INTO vehicles (
  company_id,
  customer_id,
  registration_no,
  vehicle_type,
  brand,
  model,
  fuel_type,
  model_year,
  color,
  odometer_km
) VALUES
(1, 1, 'MH12AB1234', '4W', 'Maruti Suzuki', 'Swift', 'PETROL', 2021, 'White', 25500),
(1, 2, 'MH14CD5678', '2W', 'Honda', 'Activa', 'PETROL', 2020, 'Grey', 18200);

INSERT INTO parts (
  company_id,
  part_name,
  part_sku,
  hsn_code,
  unit,
  purchase_price,
  selling_price,
  gst_rate,
  min_stock
) VALUES
(1, 'Engine Oil 5W30 (1L)', 'EO-5W30-1L', '27101980', 'LITRE', 380.00, 520.00, 18.00, 10.00),
(1, 'Oil Filter - Swift', 'OF-SWIFT', '84212300', 'PCS', 120.00, 180.00, 18.00, 8.00),
(1, 'Air Filter - Activa', 'AF-ACTIVA', '84213100', 'PCS', 90.00, 150.00, 18.00, 8.00);

INSERT INTO garage_inventory (garage_id, part_id, quantity) VALUES
(1, 1, 50.00),
(1, 2, 25.00),
(1, 3, 20.00);
