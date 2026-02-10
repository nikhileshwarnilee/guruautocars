-- Temporary Stock Management Upgrade
-- Safe to run repeatedly.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS temp_stock_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  temp_ref VARCHAR(40) NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  status_code ENUM('OPEN', 'RETURNED', 'PURCHASED', 'CONSUMED') NOT NULL DEFAULT 'OPEN',
  notes VARCHAR(255) NULL,
  resolution_notes VARCHAR(255) NULL,
  purchase_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  resolved_by INT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_temp_stock_ref (company_id, temp_ref),
  KEY idx_temp_stock_scope_status (company_id, garage_id, status_code, created_at),
  KEY idx_temp_stock_part_status (part_id, status_code),
  KEY idx_temp_stock_purchase (purchase_id),
  CONSTRAINT fk_temp_stock_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_temp_stock_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE,
  CONSTRAINT fk_temp_stock_part FOREIGN KEY (part_id) REFERENCES parts(id),
  CONSTRAINT fk_temp_stock_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_temp_stock_resolved_by FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS temp_stock_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temp_entry_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  event_type ENUM('TEMP_IN', 'RETURNED', 'PURCHASED', 'CONSUMED') NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  from_status ENUM('OPEN', 'RETURNED', 'PURCHASED', 'CONSUMED') NULL,
  to_status ENUM('OPEN', 'RETURNED', 'PURCHASED', 'CONSUMED') NULL,
  notes VARCHAR(255) NULL,
  purchase_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_temp_stock_events_entry_created (temp_entry_id, created_at),
  KEY idx_temp_stock_events_scope_created (company_id, garage_id, created_at),
  KEY idx_temp_stock_events_type_created (event_type, created_at),
  CONSTRAINT fk_temp_stock_events_entry FOREIGN KEY (temp_entry_id) REFERENCES temp_stock_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_temp_stock_events_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_temp_stock_events_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE,
  CONSTRAINT fk_temp_stock_events_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_purchases_table := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'purchases'
);

SET @extend_purchase_source_sql := IF(
  @has_purchases_table = 1,
  'ALTER TABLE purchases MODIFY COLUMN purchase_source ENUM(''VENDOR_ENTRY'', ''MANUAL_ADJUSTMENT'', ''TEMP_CONVERSION'') NOT NULL DEFAULT ''VENDOR_ENTRY''',
  'SELECT 1'
);
PREPARE stmt_extend_purchase_source FROM @extend_purchase_source_sql;
EXECUTE stmt_extend_purchase_source;
DEALLOCATE PREPARE stmt_extend_purchase_source;

COMMIT;
