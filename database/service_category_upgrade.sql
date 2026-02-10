-- Service Category + Service Master Upgrade (data-safe, idempotent)

CREATE TABLE IF NOT EXISTS service_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  category_code VARCHAR(40) NOT NULL,
  category_name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_service_category_code (company_id, category_code),
  UNIQUE KEY uniq_service_category_name (company_id, category_name),
  KEY idx_service_category_status (company_id, status_code),
  CONSTRAINT fk_service_categories_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_service_categories_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE services
  ADD COLUMN IF NOT EXISTS category_id INT UNSIGNED NULL AFTER company_id,
  ADD KEY IF NOT EXISTS idx_services_category (category_id);

SET @fk_services_category_exists := (
  SELECT COUNT(*)
  FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND CONSTRAINT_NAME = 'fk_services_category'
);
SET @fk_services_category_sql := IF(
  @fk_services_category_exists = 0,
  'ALTER TABLE services ADD CONSTRAINT fk_services_category FOREIGN KEY (category_id) REFERENCES service_categories(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE fk_services_category_stmt FROM @fk_services_category_sql;
EXECUTE fk_services_category_stmt;
DEALLOCATE PREPARE fk_services_category_stmt;

SET @service_category_seed_user := (SELECT MIN(id) FROM users);

INSERT IGNORE INTO service_categories
  (company_id, category_code, category_name, description, status_code, created_by)
SELECT c.id, 'MECHANICAL', 'Mechanical', 'Mechanical repairs and periodic maintenance', 'ACTIVE', @service_category_seed_user
FROM companies c;

INSERT IGNORE INTO service_categories
  (company_id, category_code, category_name, description, status_code, created_by)
SELECT c.id, 'ELECTRICAL', 'Electrical', 'Electrical diagnostics and repair', 'ACTIVE', @service_category_seed_user
FROM companies c;

INSERT IGNORE INTO service_categories
  (company_id, category_code, category_name, description, status_code, created_by)
SELECT c.id, 'BODYWORK', 'Bodywork', 'Body repairs, denting, painting and alignment', 'ACTIVE', @service_category_seed_user
FROM companies c;

INSERT IGNORE INTO service_categories
  (company_id, category_code, category_name, description, status_code, created_by)
SELECT c.id, 'AC', 'AC', 'Air-conditioning service and repairs', 'ACTIVE', @service_category_seed_user
FROM companies c;

INSERT IGNORE INTO service_categories
  (company_id, category_code, category_name, description, status_code, created_by)
SELECT c.id, 'DETAILING', 'Detailing', 'Cleaning, polishing and detailing services', 'ACTIVE', @service_category_seed_user
FROM companies c;
