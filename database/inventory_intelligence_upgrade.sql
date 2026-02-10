-- Inventory Intelligence Upgrade
-- Safe to run repeatedly.

START TRANSACTION;

-- Extend movement taxonomy and add idempotency key support.
SET @has_movement_uid := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_movements'
      AND COLUMN_NAME = 'movement_uid'
);

SET @add_movement_uid_sql := IF(
    @has_movement_uid = 0,
    'ALTER TABLE inventory_movements ADD COLUMN movement_uid VARCHAR(64) NULL AFTER reference_id',
    'SELECT 1'
);
PREPARE stmt_add_movement_uid FROM @add_movement_uid_sql;
EXECUTE stmt_add_movement_uid;
DEALLOCATE PREPARE stmt_add_movement_uid;

ALTER TABLE inventory_movements
  MODIFY COLUMN movement_type ENUM('IN', 'OUT', 'ADJUST') NOT NULL,
  MODIFY COLUMN reference_type ENUM('PURCHASE', 'JOB_CARD', 'ADJUSTMENT', 'OPENING', 'TRANSFER') NOT NULL DEFAULT 'ADJUSTMENT';

UPDATE inventory_movements
SET movement_uid = CONCAT('legacy-', id)
WHERE movement_uid IS NULL OR movement_uid = '';

SET @has_unique_movement_uid := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_movements'
      AND INDEX_NAME = 'uniq_inventory_movement_uid'
);

SET @add_unique_movement_uid_sql := IF(
    @has_unique_movement_uid = 0,
    'ALTER TABLE inventory_movements ADD UNIQUE KEY uniq_inventory_movement_uid (movement_uid)',
    'SELECT 1'
);
PREPARE stmt_add_unique_movement_uid FROM @add_unique_movement_uid_sql;
EXECUTE stmt_add_unique_movement_uid;
DEALLOCATE PREPARE stmt_add_unique_movement_uid;

SET @has_idx_reference := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_movements'
      AND INDEX_NAME = 'idx_inventory_reference'
);

SET @add_idx_reference_sql := IF(
    @has_idx_reference = 0,
    'ALTER TABLE inventory_movements ADD KEY idx_inventory_reference (reference_type, reference_id)',
    'SELECT 1'
);
PREPARE stmt_add_idx_reference FROM @add_idx_reference_sql;
EXECUTE stmt_add_idx_reference;
DEALLOCATE PREPARE stmt_add_idx_reference;

-- Transfer audit table (single-part transfer row; each row generates two stock movements).
CREATE TABLE IF NOT EXISTS inventory_transfers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    from_garage_id INT UNSIGNED NOT NULL,
    to_garage_id INT UNSIGNED NOT NULL,
    part_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    transfer_ref VARCHAR(40) NOT NULL,
    request_uid VARCHAR(64) DEFAULT NULL,
    status_code ENUM('POSTED', 'CANCELLED') NOT NULL DEFAULT 'POSTED',
    notes VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_inventory_transfer_ref (company_id, transfer_ref),
    UNIQUE KEY uniq_inventory_transfer_request (request_uid),
    KEY idx_inventory_transfer_company_created (company_id, created_at),
    KEY idx_inventory_transfer_from_garage (from_garage_id),
    KEY idx_inventory_transfer_to_garage (to_garage_id),
    KEY idx_inventory_transfer_part (part_id),
    CONSTRAINT fk_inventory_transfer_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_inventory_transfer_from_garage FOREIGN KEY (from_garage_id) REFERENCES garages(id),
    CONSTRAINT fk_inventory_transfer_to_garage FOREIGN KEY (to_garage_id) REFERENCES garages(id),
    CONSTRAINT fk_inventory_transfer_part FOREIGN KEY (part_id) REFERENCES parts(id),
    CONSTRAINT fk_inventory_transfer_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fine-grained inventory permissions.
INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'inventory.adjust', 'Adjust inventory stock movements', 'ACTIVE'
WHERE NOT EXISTS (
    SELECT 1 FROM permissions WHERE perm_key = 'inventory.adjust'
);

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'inventory.transfer', 'Transfer stock between garages', 'ACTIVE'
WHERE NOT EXISTS (
    SELECT 1 FROM permissions WHERE perm_key = 'inventory.transfer'
);

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'inventory.negative', 'Allow negative inventory adjustments', 'ACTIVE'
WHERE NOT EXISTS (
    SELECT 1 FROM permissions WHERE perm_key = 'inventory.negative'
);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key = 'inventory.adjust'
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
INNER JOIN permissions p ON p.perm_key = 'inventory.transfer'
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
INNER JOIN permissions p ON p.perm_key = 'inventory.negative'
WHERE r.role_key IN ('super_admin', 'garage_owner')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

COMMIT;
