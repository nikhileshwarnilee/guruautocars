-- Reports, Dashboard & Owner Intelligence Upgrade
-- Safe to run repeatedly.

START TRANSACTION;

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'reports.view', 'View trusted operational reports and dashboard intelligence', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'reports.view');

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'reports.financial', 'View trusted financial and GST analytics', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'reports.financial');

UPDATE permissions
SET status_code = 'ACTIVE'
WHERE perm_key IN ('report.view', 'reports.view', 'reports.financial')
  AND (status_code IS NULL OR status_code <> 'ACTIVE');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key = 'reports.view'
WHERE r.role_key IN ('super_admin', 'garage_owner', 'manager', 'accountant')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key = 'reports.financial'
WHERE r.role_key IN ('super_admin', 'garage_owner', 'accountant')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

ALTER TABLE job_cards
  ADD INDEX IF NOT EXISTS idx_job_cards_analytics_scope (company_id, garage_id, status_code, status, closed_at);

ALTER TABLE invoices
  ADD INDEX IF NOT EXISTS idx_invoices_analytics_scope (company_id, garage_id, invoice_status, invoice_date, job_card_id);

ALTER TABLE payments
  ADD INDEX IF NOT EXISTS idx_payments_analytics_scope (invoice_id, paid_on, payment_mode);

ALTER TABLE inventory_movements
  ADD INDEX IF NOT EXISTS idx_inventory_movements_analytics_scope (company_id, garage_id, created_at, movement_type, reference_type);

ALTER TABLE job_assignments
  ADD INDEX IF NOT EXISTS idx_job_assignments_analytics_scope (user_id, status_code, job_card_id);

COMMIT;
