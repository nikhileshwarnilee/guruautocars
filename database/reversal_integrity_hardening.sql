-- Reversal Integrity Hardening Upgrade
-- Safe to run repeatedly.

START TRANSACTION;

ALTER TABLE purchases
  ADD COLUMN IF NOT EXISTS status_code ENUM('ACTIVE', 'INACTIVE', 'DELETED') NOT NULL DEFAULT 'ACTIVE' AFTER payment_status,
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER status_code,
  ADD COLUMN IF NOT EXISTS deleted_by INT UNSIGNED NULL AFTER deleted_at,
  ADD COLUMN IF NOT EXISTS delete_reason VARCHAR(255) NULL AFTER deleted_by;

ALTER TABLE purchases
  ADD INDEX IF NOT EXISTS idx_purchases_scope_status (company_id, garage_id, status_code, purchase_date),
  ADD INDEX IF NOT EXISTS idx_purchases_deleted (status_code, deleted_at);

UPDATE purchases
SET status_code = 'ACTIVE'
WHERE status_code IS NULL OR status_code = '';

ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS entry_type ENUM('PAYMENT', 'REVERSAL') NOT NULL DEFAULT 'PAYMENT' AFTER invoice_id,
  ADD COLUMN IF NOT EXISTS reversed_payment_id INT UNSIGNED NULL AFTER notes,
  ADD COLUMN IF NOT EXISTS is_reversed TINYINT(1) NOT NULL DEFAULT 0 AFTER reversed_payment_id,
  ADD COLUMN IF NOT EXISTS reversed_at DATETIME NULL AFTER is_reversed,
  ADD COLUMN IF NOT EXISTS reversed_by INT UNSIGNED NULL AFTER reversed_at,
  ADD COLUMN IF NOT EXISTS reverse_reason VARCHAR(255) NULL AFTER reversed_by;

ALTER TABLE payments
  ADD INDEX IF NOT EXISTS idx_payments_invoice_entry (invoice_id, entry_type, paid_on),
  ADD INDEX IF NOT EXISTS idx_payments_reversed (reversed_payment_id),
  ADD INDEX IF NOT EXISTS idx_payments_is_reversed (is_reversed, paid_on);

UPDATE payments
SET entry_type = 'PAYMENT'
WHERE entry_type IS NULL OR entry_type = '';

UPDATE payments
SET is_reversed = 1
WHERE reversed_payment_id IS NOT NULL
  AND (is_reversed IS NULL OR is_reversed = 0);

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'purchase.delete', 'Soft delete purchases with reversal checks', 'ACTIVE'
WHERE NOT EXISTS (
  SELECT 1 FROM permissions WHERE perm_key = 'purchase.delete'
);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key = 'purchase.delete'
WHERE r.role_key IN ('super_admin', 'garage_owner', 'manager', 'accountant')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

COMMIT;

