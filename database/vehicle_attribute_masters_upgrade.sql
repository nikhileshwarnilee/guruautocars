-- Vehicle Attribute Masters Upgrade
-- Safe to run repeatedly.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS vehicle_brands (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  brand_name VARCHAR(100) NOT NULL,
  vis_brand_id INT UNSIGNED NULL,
  source_code ENUM('VIS','MANUAL') NOT NULL DEFAULT 'MANUAL',
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_vehicle_brand_name (brand_name),
  KEY idx_vehicle_brands_status (status_code),
  KEY idx_vehicle_brands_vis (vis_brand_id),
  CONSTRAINT fk_vehicle_brands_vis FOREIGN KEY (vis_brand_id) REFERENCES vis_brands(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vehicle_models (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  brand_id INT UNSIGNED NOT NULL,
  model_name VARCHAR(120) NOT NULL,
  vehicle_type ENUM('2W','4W','COMMERCIAL') NULL,
  vis_model_id INT UNSIGNED NULL,
  source_code ENUM('VIS','MANUAL') NOT NULL DEFAULT 'MANUAL',
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_vehicle_model_name (brand_id, model_name),
  KEY idx_vehicle_models_status (status_code),
  KEY idx_vehicle_models_vis (vis_model_id),
  CONSTRAINT fk_vehicle_models_brand FOREIGN KEY (brand_id) REFERENCES vehicle_brands(id) ON DELETE CASCADE,
  CONSTRAINT fk_vehicle_models_vis FOREIGN KEY (vis_model_id) REFERENCES vis_models(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vehicle_variants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  model_id INT UNSIGNED NOT NULL,
  variant_name VARCHAR(150) NOT NULL,
  fuel_type ENUM('PETROL','DIESEL','CNG','EV','HYBRID','OTHER') NULL,
  engine_cc VARCHAR(30) NULL,
  vis_variant_id INT UNSIGNED NULL,
  source_code ENUM('VIS','MANUAL') NOT NULL DEFAULT 'MANUAL',
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_vehicle_variant_name (model_id, variant_name),
  KEY idx_vehicle_variants_status (status_code),
  KEY idx_vehicle_variants_vis (vis_variant_id),
  CONSTRAINT fk_vehicle_variants_model FOREIGN KEY (model_id) REFERENCES vehicle_models(id) ON DELETE CASCADE,
  CONSTRAINT fk_vehicle_variants_vis FOREIGN KEY (vis_variant_id) REFERENCES vis_variants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vehicle_model_years (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  year_value SMALLINT UNSIGNED NOT NULL,
  source_code ENUM('VIS','MANUAL') NOT NULL DEFAULT 'MANUAL',
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_vehicle_model_year (year_value),
  KEY idx_vehicle_model_years_status (status_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vehicle_colors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  color_name VARCHAR(60) NOT NULL,
  source_code ENUM('VIS','MANUAL') NOT NULL DEFAULT 'MANUAL',
  status_code ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_vehicle_color_name (color_name),
  KEY idx_vehicle_colors_status (status_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE vehicles
  ADD COLUMN IF NOT EXISTS brand_id INT UNSIGNED NULL AFTER brand,
  ADD COLUMN IF NOT EXISTS model_id INT UNSIGNED NULL AFTER model,
  ADD COLUMN IF NOT EXISTS variant_id INT UNSIGNED NULL AFTER variant,
  ADD COLUMN IF NOT EXISTS model_year_id INT UNSIGNED NULL AFTER model_year,
  ADD COLUMN IF NOT EXISTS color_id INT UNSIGNED NULL AFTER color;

ALTER TABLE vehicles
  ADD INDEX IF NOT EXISTS idx_vehicles_brand_id (brand_id),
  ADD INDEX IF NOT EXISTS idx_vehicles_model_id (model_id),
  ADD INDEX IF NOT EXISTS idx_vehicles_variant_id (variant_id),
  ADD INDEX IF NOT EXISTS idx_vehicles_model_year_id (model_year_id),
  ADD INDEX IF NOT EXISTS idx_vehicles_color_id (color_id);

SET @fk_vehicles_brand_id_exists := (
  SELECT COUNT(*)
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'vehicles'
    AND CONSTRAINT_NAME = 'fk_vehicles_brand_id'
);
SET @sql_fk_vehicles_brand_id := IF(
  @fk_vehicles_brand_id_exists = 0,
  'ALTER TABLE vehicles ADD CONSTRAINT fk_vehicles_brand_id FOREIGN KEY (brand_id) REFERENCES vehicle_brands(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt_fk_vehicles_brand_id FROM @sql_fk_vehicles_brand_id;
EXECUTE stmt_fk_vehicles_brand_id;
DEALLOCATE PREPARE stmt_fk_vehicles_brand_id;

SET @fk_vehicles_model_id_exists := (
  SELECT COUNT(*)
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'vehicles'
    AND CONSTRAINT_NAME = 'fk_vehicles_model_id'
);
SET @sql_fk_vehicles_model_id := IF(
  @fk_vehicles_model_id_exists = 0,
  'ALTER TABLE vehicles ADD CONSTRAINT fk_vehicles_model_id FOREIGN KEY (model_id) REFERENCES vehicle_models(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt_fk_vehicles_model_id FROM @sql_fk_vehicles_model_id;
EXECUTE stmt_fk_vehicles_model_id;
DEALLOCATE PREPARE stmt_fk_vehicles_model_id;

SET @fk_vehicles_variant_id_exists := (
  SELECT COUNT(*)
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'vehicles'
    AND CONSTRAINT_NAME = 'fk_vehicles_variant_id'
);
SET @sql_fk_vehicles_variant_id := IF(
  @fk_vehicles_variant_id_exists = 0,
  'ALTER TABLE vehicles ADD CONSTRAINT fk_vehicles_variant_id FOREIGN KEY (variant_id) REFERENCES vehicle_variants(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt_fk_vehicles_variant_id FROM @sql_fk_vehicles_variant_id;
EXECUTE stmt_fk_vehicles_variant_id;
DEALLOCATE PREPARE stmt_fk_vehicles_variant_id;

SET @fk_vehicles_model_year_id_exists := (
  SELECT COUNT(*)
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'vehicles'
    AND CONSTRAINT_NAME = 'fk_vehicles_model_year_id'
);
SET @sql_fk_vehicles_model_year_id := IF(
  @fk_vehicles_model_year_id_exists = 0,
  'ALTER TABLE vehicles ADD CONSTRAINT fk_vehicles_model_year_id FOREIGN KEY (model_year_id) REFERENCES vehicle_model_years(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt_fk_vehicles_model_year_id FROM @sql_fk_vehicles_model_year_id;
EXECUTE stmt_fk_vehicles_model_year_id;
DEALLOCATE PREPARE stmt_fk_vehicles_model_year_id;

SET @fk_vehicles_color_id_exists := (
  SELECT COUNT(*)
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'vehicles'
    AND CONSTRAINT_NAME = 'fk_vehicles_color_id'
);
SET @sql_fk_vehicles_color_id := IF(
  @fk_vehicles_color_id_exists = 0,
  'ALTER TABLE vehicles ADD CONSTRAINT fk_vehicles_color_id FOREIGN KEY (color_id) REFERENCES vehicle_colors(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt_fk_vehicles_color_id FROM @sql_fk_vehicles_color_id;
EXECUTE stmt_fk_vehicles_color_id;
DEALLOCATE PREPARE stmt_fk_vehicles_color_id;

-- Seed brand master from VIS catalog.
INSERT INTO vehicle_brands (brand_name, vis_brand_id, source_code, status_code, deleted_at)
SELECT vb.brand_name, vb.id, 'VIS', 'ACTIVE', NULL
FROM vis_brands vb
WHERE vb.status_code = 'ACTIVE'
ON DUPLICATE KEY UPDATE
  vis_brand_id = COALESCE(vehicle_brands.vis_brand_id, VALUES(vis_brand_id)),
  source_code = CASE WHEN vehicle_brands.source_code = 'MANUAL' THEN 'VIS' ELSE vehicle_brands.source_code END,
  status_code = CASE WHEN vehicle_brands.status_code = 'DELETED' THEN vehicle_brands.status_code ELSE 'ACTIVE' END,
  deleted_at = CASE WHEN vehicle_brands.status_code = 'DELETED' THEN vehicle_brands.deleted_at ELSE NULL END;

-- Seed model master from VIS catalog.
INSERT INTO vehicle_models (brand_id, model_name, vehicle_type, vis_model_id, source_code, status_code, deleted_at)
SELECT vbm.id, vm.model_name, vm.vehicle_type, vm.id, 'VIS', 'ACTIVE', NULL
FROM vis_models vm
INNER JOIN vis_brands vb ON vb.id = vm.brand_id AND vb.status_code = 'ACTIVE'
INNER JOIN vehicle_brands vbm ON vbm.brand_name = vb.brand_name
WHERE vm.status_code = 'ACTIVE'
ON DUPLICATE KEY UPDATE
  vis_model_id = COALESCE(vehicle_models.vis_model_id, VALUES(vis_model_id)),
  vehicle_type = COALESCE(vehicle_models.vehicle_type, VALUES(vehicle_type)),
  source_code = CASE WHEN vehicle_models.source_code = 'MANUAL' THEN 'VIS' ELSE vehicle_models.source_code END,
  status_code = CASE WHEN vehicle_models.status_code = 'DELETED' THEN vehicle_models.status_code ELSE 'ACTIVE' END,
  deleted_at = CASE WHEN vehicle_models.status_code = 'DELETED' THEN vehicle_models.deleted_at ELSE NULL END;

-- Seed variant master from VIS catalog.
INSERT INTO vehicle_variants (model_id, variant_name, fuel_type, engine_cc, vis_variant_id, source_code, status_code, deleted_at)
SELECT vmm.id, vv.variant_name, vv.fuel_type, vv.engine_cc, vv.id, 'VIS', 'ACTIVE', NULL
FROM vis_variants vv
INNER JOIN vis_models vm ON vm.id = vv.model_id AND vm.status_code = 'ACTIVE'
INNER JOIN vis_brands vb ON vb.id = vm.brand_id AND vb.status_code = 'ACTIVE'
INNER JOIN vehicle_brands vbm ON vbm.brand_name = vb.brand_name
INNER JOIN vehicle_models vmm ON vmm.brand_id = vbm.id AND vmm.model_name = vm.model_name
WHERE vv.status_code = 'ACTIVE'
ON DUPLICATE KEY UPDATE
  vis_variant_id = COALESCE(vehicle_variants.vis_variant_id, VALUES(vis_variant_id)),
  fuel_type = COALESCE(vehicle_variants.fuel_type, VALUES(fuel_type)),
  engine_cc = COALESCE(vehicle_variants.engine_cc, VALUES(engine_cc)),
  source_code = CASE WHEN vehicle_variants.source_code = 'MANUAL' THEN 'VIS' ELSE vehicle_variants.source_code END,
  status_code = CASE WHEN vehicle_variants.status_code = 'DELETED' THEN vehicle_variants.status_code ELSE 'ACTIVE' END,
  deleted_at = CASE WHEN vehicle_variants.status_code = 'DELETED' THEN vehicle_variants.deleted_at ELSE NULL END;

-- Seed color and year masters with baseline values.
INSERT IGNORE INTO vehicle_colors (color_name, source_code, status_code)
VALUES
('White', 'MANUAL', 'ACTIVE'),
('Black', 'MANUAL', 'ACTIVE'),
('Silver', 'MANUAL', 'ACTIVE'),
('Grey', 'MANUAL', 'ACTIVE'),
('Red', 'MANUAL', 'ACTIVE'),
('Blue', 'MANUAL', 'ACTIVE'),
('Brown', 'MANUAL', 'ACTIVE'),
('Green', 'MANUAL', 'ACTIVE');

INSERT IGNORE INTO vehicle_model_years (year_value, source_code, status_code)
SELECT 1990 + seq.n AS year_value, 'MANUAL', 'ACTIVE'
FROM (
  SELECT ones.n + (tens.n * 10) AS n
  FROM (
    SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
    UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
  ) ones
  CROSS JOIN (
    SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
    UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
  ) tens
) seq
WHERE 1990 + seq.n <= YEAR(CURDATE()) + 1;

-- Backfill masters from existing vehicle records.
INSERT INTO vehicle_brands (brand_name, source_code, status_code, deleted_at)
SELECT DISTINCT TRIM(v.brand) AS brand_name, 'MANUAL', 'ACTIVE', NULL
FROM vehicles v
WHERE v.brand IS NOT NULL
  AND TRIM(v.brand) <> ''
ON DUPLICATE KEY UPDATE
  status_code = CASE WHEN vehicle_brands.status_code = 'DELETED' THEN vehicle_brands.status_code ELSE 'ACTIVE' END,
  deleted_at = CASE WHEN vehicle_brands.status_code = 'DELETED' THEN vehicle_brands.deleted_at ELSE NULL END;

INSERT INTO vehicle_models (brand_id, model_name, source_code, status_code, deleted_at)
SELECT DISTINCT vb.id, TRIM(v.model) AS model_name, 'MANUAL', 'ACTIVE', NULL
FROM vehicles v
INNER JOIN vehicle_brands vb ON vb.brand_name = TRIM(v.brand)
WHERE v.brand IS NOT NULL
  AND TRIM(v.brand) <> ''
  AND v.model IS NOT NULL
  AND TRIM(v.model) <> ''
ON DUPLICATE KEY UPDATE
  status_code = CASE WHEN vehicle_models.status_code = 'DELETED' THEN vehicle_models.status_code ELSE 'ACTIVE' END,
  deleted_at = CASE WHEN vehicle_models.status_code = 'DELETED' THEN vehicle_models.deleted_at ELSE NULL END;

INSERT INTO vehicle_variants (model_id, variant_name, source_code, status_code, deleted_at)
SELECT DISTINCT vm.id, TRIM(v.variant) AS variant_name, 'MANUAL', 'ACTIVE', NULL
FROM vehicles v
INNER JOIN vehicle_brands vb ON vb.brand_name = TRIM(v.brand)
INNER JOIN vehicle_models vm ON vm.brand_id = vb.id AND vm.model_name = TRIM(v.model)
WHERE v.variant IS NOT NULL
  AND TRIM(v.variant) <> ''
ON DUPLICATE KEY UPDATE
  status_code = CASE WHEN vehicle_variants.status_code = 'DELETED' THEN vehicle_variants.status_code ELSE 'ACTIVE' END,
  deleted_at = CASE WHEN vehicle_variants.status_code = 'DELETED' THEN vehicle_variants.deleted_at ELSE NULL END;

INSERT INTO vehicle_model_years (year_value, source_code, status_code, deleted_at)
SELECT DISTINCT v.model_year AS year_value, 'MANUAL', 'ACTIVE', NULL
FROM vehicles v
WHERE v.model_year IS NOT NULL
  AND v.model_year BETWEEN 1900 AND 2100
ON DUPLICATE KEY UPDATE
  status_code = CASE WHEN vehicle_model_years.status_code = 'DELETED' THEN vehicle_model_years.status_code ELSE 'ACTIVE' END,
  deleted_at = CASE WHEN vehicle_model_years.status_code = 'DELETED' THEN vehicle_model_years.deleted_at ELSE NULL END;

INSERT INTO vehicle_colors (color_name, source_code, status_code, deleted_at)
SELECT DISTINCT TRIM(v.color) AS color_name, 'MANUAL', 'ACTIVE', NULL
FROM vehicles v
WHERE v.color IS NOT NULL
  AND TRIM(v.color) <> ''
ON DUPLICATE KEY UPDATE
  status_code = CASE WHEN vehicle_colors.status_code = 'DELETED' THEN vehicle_colors.status_code ELSE 'ACTIVE' END,
  deleted_at = CASE WHEN vehicle_colors.status_code = 'DELETED' THEN vehicle_colors.deleted_at ELSE NULL END;

-- Backfill vehicle link columns from existing textual fields.
UPDATE vehicles v
INNER JOIN vehicle_brands vb ON vb.brand_name = TRIM(v.brand)
SET v.brand_id = vb.id
WHERE v.brand_id IS NULL
  AND v.brand IS NOT NULL
  AND TRIM(v.brand) <> '';

UPDATE vehicles v
INNER JOIN vehicle_models vm ON vm.id = (
  SELECT vm2.id
  FROM vehicle_models vm2
  WHERE vm2.brand_id = v.brand_id
    AND vm2.model_name = TRIM(v.model)
  ORDER BY vm2.id ASC
  LIMIT 1
)
SET v.model_id = vm.id
WHERE v.brand_id IS NOT NULL
  AND v.model_id IS NULL
  AND v.model IS NOT NULL
  AND TRIM(v.model) <> '';

UPDATE vehicles v
INNER JOIN vehicle_variants vv ON vv.id = (
  SELECT vv2.id
  FROM vehicle_variants vv2
  WHERE vv2.model_id = v.model_id
    AND vv2.variant_name = TRIM(v.variant)
  ORDER BY vv2.id ASC
  LIMIT 1
)
SET v.variant_id = vv.id
WHERE v.model_id IS NOT NULL
  AND v.variant_id IS NULL
  AND v.variant IS NOT NULL
  AND TRIM(v.variant) <> '';

UPDATE vehicles v
INNER JOIN vehicle_model_years vy ON vy.year_value = v.model_year
SET v.model_year_id = vy.id
WHERE v.model_year IS NOT NULL
  AND v.model_year_id IS NULL;

UPDATE vehicles v
INNER JOIN vehicle_colors vc ON vc.color_name = TRIM(v.color)
SET v.color_id = vc.id
WHERE v.color_id IS NULL
  AND v.color IS NOT NULL
  AND TRIM(v.color) <> '';

-- Link VIS variant id for vehicles with mapped master variant.
UPDATE vehicles v
INNER JOIN vehicle_variants vv ON vv.id = v.variant_id
SET v.vis_variant_id = vv.vis_variant_id
WHERE v.vis_variant_id IS NULL
  AND vv.vis_variant_id IS NOT NULL;

COMMIT;
