-- Master Modules Upgrade (Data-safe, idempotent)

ALTER TABLE companies
  ADD COLUMN IF NOT EXISTS status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;

UPDATE companies
SET status_code = CASE LOWER(status)
  WHEN 'active' THEN 'ACTIVE'
  WHEN 'inactive' THEN 'INACTIVE'
  ELSE status_code
END;

ALTER TABLE garages
  ADD COLUMN IF NOT EXISTS status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;

UPDATE garages
SET status_code = CASE LOWER(status)
  WHEN 'active' THEN 'ACTIVE'
  WHEN 'inactive' THEN 'INACTIVE'
  ELSE status_code
END;

ALTER TABLE roles
  ADD COLUMN IF NOT EXISTS status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE';

ALTER TABLE permissions
  ADD COLUMN IF NOT EXISTS status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE';

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;

UPDATE users
SET status_code = CASE
  WHEN is_active = 1 THEN 'ACTIVE'
  ELSE 'INACTIVE'
END
WHERE status_code <> 'DELETED';

ALTER TABLE customers
  ADD COLUMN IF NOT EXISTS status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;

UPDATE customers
SET status_code = CASE
  WHEN is_active = 1 THEN 'ACTIVE'
  ELSE 'INACTIVE'
END
WHERE status_code <> 'DELETED';

ALTER TABLE vehicles
  ADD COLUMN IF NOT EXISTS vis_variant_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;

UPDATE vehicles
SET status_code = CASE
  WHEN is_active = 1 THEN 'ACTIVE'
  ELSE 'INACTIVE'
END
WHERE status_code <> 'DELETED';

ALTER TABLE parts
  ADD COLUMN IF NOT EXISTS category_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS vendor_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;

UPDATE parts
SET status_code = CASE
  WHEN is_active = 1 THEN 'ACTIVE'
  ELSE 'INACTIVE'
END
WHERE status_code <> 'DELETED';

CREATE TABLE IF NOT EXISTS financial_years (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  fy_label VARCHAR(20) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_financial_year (company_id, fy_label),
  KEY idx_financial_year_dates (start_date, end_date),
  CONSTRAINT fk_financial_years_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_financial_years_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NULL,
  setting_group VARCHAR(80) NOT NULL,
  setting_key VARCHAR(120) NOT NULL,
  setting_value TEXT NULL,
  value_type ENUM('STRING','NUMBER','BOOLEAN','JSON') NOT NULL DEFAULT 'STRING',
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_setting_scope (company_id, garage_id, setting_key),
  KEY idx_setting_group (setting_group),
  CONSTRAINT fk_system_settings_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_system_settings_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE,
  CONSTRAINT fk_system_settings_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS services (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  service_code VARCHAR(40) NOT NULL,
  service_name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  default_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
  default_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00,
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_service_code (company_id, service_code),
  KEY idx_service_name (service_name),
  CONSTRAINT fk_services_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_services_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE job_labor
  ADD COLUMN IF NOT EXISTS service_id INT UNSIGNED NULL,
  ADD KEY IF NOT EXISTS idx_job_labor_service (service_id);

CREATE TABLE IF NOT EXISTS part_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  category_code VARCHAR(40) NOT NULL,
  category_name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_part_category_code (company_id, category_code),
  UNIQUE KEY uniq_part_category_name (company_id, category_name),
  CONSTRAINT fk_part_categories_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vendors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  vendor_code VARCHAR(40) NOT NULL,
  vendor_name VARCHAR(150) NOT NULL,
  contact_person VARCHAR(120) NULL,
  phone VARCHAR(20) NULL,
  email VARCHAR(150) NULL,
  gstin VARCHAR(15) NULL,
  address_line1 VARCHAR(200) NULL,
  city VARCHAR(80) NULL,
  state VARCHAR(80) NULL,
  pincode VARCHAR(10) NULL,
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_vendor_code (company_id, vendor_code),
  KEY idx_vendor_name (vendor_name),
  CONSTRAINT fk_vendors_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  action_type VARCHAR(40) NOT NULL,
  action_note VARCHAR(255) NULL,
  snapshot_json JSON NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_customer_history_customer (customer_id),
  KEY idx_customer_history_created (created_at),
  CONSTRAINT fk_customer_history_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_history_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vehicle_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT UNSIGNED NOT NULL,
  action_type VARCHAR(40) NOT NULL,
  action_note VARCHAR(255) NULL,
  snapshot_json JSON NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vehicle_history_vehicle (vehicle_id),
  KEY idx_vehicle_history_created (created_at),
  CONSTRAINT fk_vehicle_history_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  CONSTRAINT fk_vehicle_history_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vis_brands (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  brand_name VARCHAR(100) NOT NULL,
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_vis_brand_name (brand_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vis_models (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  brand_id INT UNSIGNED NOT NULL,
  model_name VARCHAR(120) NOT NULL,
  vehicle_type ENUM('2W','4W','COMMERCIAL') NOT NULL DEFAULT '4W',
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_vis_model_name (brand_id, model_name),
  CONSTRAINT fk_vis_models_brand FOREIGN KEY (brand_id) REFERENCES vis_brands(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vis_variants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  model_id INT UNSIGNED NOT NULL,
  variant_name VARCHAR(150) NOT NULL,
  fuel_type ENUM('PETROL','DIESEL','CNG','EV','HYBRID','OTHER') NOT NULL DEFAULT 'PETROL',
  engine_cc VARCHAR(30) NULL,
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_vis_variant_name (model_id, variant_name),
  CONSTRAINT fk_vis_variants_model FOREIGN KEY (model_id) REFERENCES vis_models(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vis_variant_specs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  variant_id INT UNSIGNED NOT NULL,
  spec_key VARCHAR(80) NOT NULL,
  spec_value VARCHAR(255) NOT NULL,
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_vis_variant_specs_variant (variant_id),
  CONSTRAINT fk_vis_variant_specs_variant FOREIGN KEY (variant_id) REFERENCES vis_variants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vis_part_compatibility (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  variant_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  compatibility_note VARCHAR(255) NULL,
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_vis_part_compatibility (company_id, variant_id, part_id),
  KEY idx_vis_compat_variant (variant_id),
  KEY idx_vis_compat_part (part_id),
  CONSTRAINT fk_vis_compat_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_vis_compat_variant FOREIGN KEY (variant_id) REFERENCES vis_variants(id) ON DELETE CASCADE,
  CONSTRAINT fk_vis_compat_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vis_service_part_map (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  service_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_vis_service_part (company_id, service_id, part_id),
  KEY idx_vis_service_map_service (service_id),
  KEY idx_vis_service_map_part (part_id),
  CONSTRAINT fk_vis_service_map_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_vis_service_map_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
  CONSTRAINT fk_vis_service_map_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO permissions (perm_key, perm_name, status_code) VALUES
('financial_year.view', 'View financial years', 'ACTIVE'),
('financial_year.manage', 'Manage financial years', 'ACTIVE'),
('settings.view', 'View system settings', 'ACTIVE'),
('settings.manage', 'Manage system settings', 'ACTIVE'),
('role.view', 'View role master', 'ACTIVE'),
('role.manage', 'Manage role master', 'ACTIVE'),
('permission.view', 'View permissions', 'ACTIVE'),
('permission.manage', 'Manage permissions', 'ACTIVE'),
('staff.view', 'View staff', 'ACTIVE'),
('staff.manage', 'Manage staff', 'ACTIVE'),
('service.view', 'View service master', 'ACTIVE'),
('service.manage', 'Manage service master', 'ACTIVE'),
('part_category.view', 'View part categories', 'ACTIVE'),
('part_category.manage', 'Manage part categories', 'ACTIVE'),
('part_master.view', 'View parts/items master', 'ACTIVE'),
('part_master.manage', 'Manage parts/items master', 'ACTIVE'),
('vendor.view', 'View vendor master', 'ACTIVE'),
('vendor.manage', 'Manage vendor master', 'ACTIVE'),
('vis.view', 'View VIS masters', 'ACTIVE'),
('vis.manage', 'Manage VIS masters', 'ACTIVE');

-- ensure existing permissions not seeded with status_code NULL
UPDATE permissions SET status_code = 'ACTIVE' WHERE status_code IS NULL;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.status_code = 'ACTIVE'
WHERE r.role_key = 'super_admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p
WHERE r.role_key = 'garage_owner'
  AND p.perm_key IN (
    'financial_year.view', 'financial_year.manage',
    'settings.view', 'settings.manage',
    'role.view', 'permission.view',
    'staff.view', 'staff.manage',
    'service.view', 'service.manage',
    'part_category.view', 'part_category.manage',
    'part_master.view', 'part_master.manage',
    'vendor.view', 'vendor.manage',
    'vis.view', 'vis.manage'
  );

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p
WHERE r.role_key = 'manager'
  AND p.perm_key IN (
    'financial_year.view',
    'settings.view',
    'role.view', 'permission.view',
    'staff.view',
    'service.view', 'service.manage',
    'part_category.view', 'part_category.manage',
    'part_master.view', 'part_master.manage',
    'vendor.view', 'vendor.manage',
    'vis.view'
  );

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p
WHERE r.role_key = 'accountant'
  AND p.perm_key IN (
    'financial_year.view',
    'settings.view',
    'service.view',
    'part_master.view',
    'vendor.view',
    'vis.view'
  );

INSERT INTO financial_years (company_id, fy_label, start_date, end_date, is_default, status_code, created_by)
SELECT 1, CONCAT(YEAR(CURDATE()), '-', RIGHT(YEAR(CURDATE()) + 1, 2)),
       DATE_FORMAT(CURDATE(), '%Y-04-01'),
       DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 YEAR), '%Y-03-31'),
       1,
       'ACTIVE',
       1
WHERE EXISTS (SELECT 1 FROM companies WHERE id = 1)
  AND NOT EXISTS (SELECT 1 FROM financial_years WHERE company_id = 1);

INSERT IGNORE INTO system_settings (company_id, garage_id, setting_group, setting_key, setting_value, value_type, status_code, created_by)
VALUES
(1, NULL, 'GST', 'default_service_gst_rate', '18', 'NUMBER', 'ACTIVE', 1),
(1, NULL, 'GST', 'default_parts_gst_rate', '18', 'NUMBER', 'ACTIVE', 1),
(1, NULL, 'BILLING', 'invoice_prefix', 'INV', 'STRING', 'ACTIVE', 1),
(1, NULL, 'GENERAL', 'timezone', 'Asia/Kolkata', 'STRING', 'ACTIVE', 1);

ALTER TABLE financial_years ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE system_settings ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE services ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE part_categories ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE vendors ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE vis_brands ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE vis_models ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE vis_variants ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE vis_variant_specs ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE vis_part_compatibility ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE vis_service_part_map ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
