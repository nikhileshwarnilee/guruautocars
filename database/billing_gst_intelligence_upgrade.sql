-- Billing & GST Intelligence Upgrade
-- Safe to run repeatedly.

START TRANSACTION;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS invoice_status ENUM('DRAFT', 'FINALIZED', 'CANCELLED') NOT NULL DEFAULT 'FINALIZED' AFTER due_date,
  ADD COLUMN IF NOT EXISTS tax_regime ENUM('INTRASTATE', 'INTERSTATE') NOT NULL DEFAULT 'INTRASTATE' AFTER taxable_amount,
  ADD COLUMN IF NOT EXISTS service_tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER igst_amount,
  ADD COLUMN IF NOT EXISTS parts_tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER service_tax_amount,
  ADD COLUMN IF NOT EXISTS total_tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER parts_tax_amount,
  ADD COLUMN IF NOT EXISTS gross_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_tax_amount,
  ADD COLUMN IF NOT EXISTS financial_year_id INT UNSIGNED NULL AFTER created_by,
  ADD COLUMN IF NOT EXISTS financial_year_label VARCHAR(20) NULL AFTER financial_year_id,
  ADD COLUMN IF NOT EXISTS sequence_number INT UNSIGNED NULL AFTER financial_year_label,
  ADD COLUMN IF NOT EXISTS snapshot_json JSON NULL AFTER sequence_number,
  ADD COLUMN IF NOT EXISTS finalized_at DATETIME NULL AFTER created_at,
  ADD COLUMN IF NOT EXISTS finalized_by INT UNSIGNED NULL AFTER finalized_at,
  ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL AFTER finalized_by,
  ADD COLUMN IF NOT EXISTS cancelled_by INT UNSIGNED NULL AFTER cancelled_at,
  ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(255) NULL AFTER cancelled_by;

ALTER TABLE invoices
  MODIFY COLUMN payment_status ENUM('UNPAID', 'PARTIAL', 'PAID', 'CANCELLED') NOT NULL DEFAULT 'UNPAID',
  MODIFY COLUMN payment_mode ENUM('CASH', 'UPI', 'CARD', 'BANK_TRANSFER', 'CHEQUE', 'MIXED') NULL;

ALTER TABLE invoices
  ADD INDEX IF NOT EXISTS idx_invoices_status (invoice_status),
  ADD INDEX IF NOT EXISTS idx_invoices_company_garage_status (company_id, garage_id, invoice_status, invoice_date),
  ADD INDEX IF NOT EXISTS idx_invoices_financial_year (garage_id, financial_year_label, sequence_number);

ALTER TABLE invoice_items
  ADD COLUMN IF NOT EXISTS service_id INT UNSIGNED NULL AFTER part_id,
  ADD COLUMN IF NOT EXISTS hsn_sac_code VARCHAR(20) NULL AFTER service_id,
  ADD COLUMN IF NOT EXISTS cgst_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER gst_rate,
  ADD COLUMN IF NOT EXISTS sgst_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER cgst_rate,
  ADD COLUMN IF NOT EXISTS igst_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER sgst_rate,
  ADD COLUMN IF NOT EXISTS cgst_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER taxable_value,
  ADD COLUMN IF NOT EXISTS sgst_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cgst_amount,
  ADD COLUMN IF NOT EXISTS igst_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER sgst_amount;

ALTER TABLE invoice_items
  ADD INDEX IF NOT EXISTS idx_invoice_items_type (invoice_id, item_type),
  ADD INDEX IF NOT EXISTS idx_invoice_items_service (service_id);

ALTER TABLE payments
  MODIFY COLUMN payment_mode ENUM('CASH', 'UPI', 'CARD', 'BANK_TRANSFER', 'CHEQUE', 'MIXED') NOT NULL;

CREATE TABLE IF NOT EXISTS invoice_number_sequences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    garage_id INT UNSIGNED NOT NULL,
    financial_year_id INT UNSIGNED NULL,
    financial_year_label VARCHAR(20) NOT NULL,
    prefix VARCHAR(20) NOT NULL DEFAULT 'INV',
    current_number INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_invoice_sequence (garage_id, financial_year_label),
    KEY idx_invoice_sequence_company (company_id, garage_id),
    KEY idx_invoice_sequence_fy (financial_year_id),
    CONSTRAINT fk_invoice_sequence_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_sequence_garage FOREIGN KEY (garage_id) REFERENCES garages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NOT NULL,
    action_type VARCHAR(40) NOT NULL,
    action_note VARCHAR(255) NULL,
    payload_json JSON NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_invoice_status_history_invoice (invoice_id, created_at),
    KEY idx_invoice_status_history_action (action_type),
    CONSTRAINT fk_invoice_status_history_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_status_history_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_payment_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    payment_id INT UNSIGNED NULL,
    action_type VARCHAR(40) NOT NULL,
    action_note VARCHAR(255) NULL,
    payload_json JSON NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_invoice_payment_history_invoice (invoice_id, created_at),
    KEY idx_invoice_payment_history_payment (payment_id),
    CONSTRAINT fk_invoice_payment_history_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_payment_history_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoice_payment_history_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE invoices
SET invoice_status = 'FINALIZED'
WHERE invoice_status IS NULL OR invoice_status = '';

UPDATE invoices
SET tax_regime = CASE
    WHEN COALESCE(igst_amount, 0) > 0 THEN 'INTERSTATE'
    ELSE 'INTRASTATE'
END
WHERE tax_regime IS NULL OR tax_regime = '';

UPDATE invoice_items
SET igst_rate = CASE WHEN COALESCE(igst_rate, 0) > 0 THEN igst_rate ELSE 0 END,
    cgst_rate = CASE
        WHEN COALESCE(igst_rate, 0) > 0 THEN 0
        ELSE ROUND(COALESCE(gst_rate, 0) / 2, 2)
    END,
    sgst_rate = CASE
        WHEN COALESCE(igst_rate, 0) > 0 THEN 0
        ELSE ROUND(COALESCE(gst_rate, 0) / 2, 2)
    END
WHERE (COALESCE(cgst_rate, 0) = 0 AND COALESCE(sgst_rate, 0) = 0 AND COALESCE(igst_rate, 0) = 0);

UPDATE invoice_items
SET igst_amount = CASE
        WHEN COALESCE(igst_rate, 0) > 0 THEN ROUND(COALESCE(tax_amount, 0), 2)
        ELSE 0
    END,
    cgst_amount = CASE
        WHEN COALESCE(igst_rate, 0) > 0 THEN 0
        ELSE ROUND(COALESCE(tax_amount, 0) / 2, 2)
    END,
    sgst_amount = CASE
        WHEN COALESCE(igst_rate, 0) > 0 THEN 0
        ELSE ROUND(COALESCE(tax_amount, 0) - ROUND(COALESCE(tax_amount, 0) / 2, 2), 2)
    END
WHERE (COALESCE(cgst_amount, 0) = 0 AND COALESCE(sgst_amount, 0) = 0 AND COALESCE(igst_amount, 0) = 0);

UPDATE invoices i
LEFT JOIN (
    SELECT
        invoice_id,
        COALESCE(SUM(CASE WHEN item_type = 'LABOR' THEN taxable_value ELSE 0 END), 0) AS subtotal_service,
        COALESCE(SUM(CASE WHEN item_type = 'PART' THEN taxable_value ELSE 0 END), 0) AS subtotal_parts,
        COALESCE(SUM(CASE WHEN item_type = 'LABOR' THEN tax_amount ELSE 0 END), 0) AS service_tax_amount,
        COALESCE(SUM(CASE WHEN item_type = 'PART' THEN tax_amount ELSE 0 END), 0) AS parts_tax_amount,
        COALESCE(SUM(cgst_amount), 0) AS cgst_amount,
        COALESCE(SUM(sgst_amount), 0) AS sgst_amount,
        COALESCE(SUM(igst_amount), 0) AS igst_amount
    FROM invoice_items
    GROUP BY invoice_id
) x ON x.invoice_id = i.id
SET i.subtotal_service = ROUND(COALESCE(x.subtotal_service, i.subtotal_service), 2),
    i.subtotal_parts = ROUND(COALESCE(x.subtotal_parts, i.subtotal_parts), 2),
    i.taxable_amount = ROUND(COALESCE(x.subtotal_service, 0) + COALESCE(x.subtotal_parts, 0), 2),
    i.service_tax_amount = ROUND(COALESCE(x.service_tax_amount, 0), 2),
    i.parts_tax_amount = ROUND(COALESCE(x.parts_tax_amount, 0), 2),
    i.total_tax_amount = ROUND(COALESCE(x.service_tax_amount, 0) + COALESCE(x.parts_tax_amount, 0), 2),
    i.cgst_amount = ROUND(COALESCE(x.cgst_amount, i.cgst_amount), 2),
    i.sgst_amount = ROUND(COALESCE(x.sgst_amount, i.sgst_amount), 2),
    i.igst_amount = ROUND(COALESCE(x.igst_amount, i.igst_amount), 2),
    i.gross_total = ROUND(ROUND(COALESCE(x.subtotal_service, 0) + COALESCE(x.subtotal_parts, 0), 2) + ROUND(COALESCE(x.service_tax_amount, 0) + COALESCE(x.parts_tax_amount, 0), 2), 2);

UPDATE invoices
SET financial_year_label = CONCAT(
        CASE WHEN MONTH(invoice_date) >= 4 THEN YEAR(invoice_date) ELSE YEAR(invoice_date) - 1 END,
        '-',
        LPAD(MOD(CASE WHEN MONTH(invoice_date) >= 4 THEN YEAR(invoice_date) + 1 ELSE YEAR(invoice_date) END, 100), 2, '0')
    )
WHERE financial_year_label IS NULL OR financial_year_label = '';

UPDATE invoices
SET sequence_number = CAST(RIGHT(invoice_number, 5) AS UNSIGNED)
WHERE (sequence_number IS NULL OR sequence_number = 0)
  AND RIGHT(invoice_number, 5) REGEXP '^[0-9]{5}$';

UPDATE invoices
SET finalized_at = COALESCE(finalized_at, created_at),
    finalized_by = COALESCE(finalized_by, created_by)
WHERE invoice_status = 'FINALIZED'
  AND finalized_at IS NULL;

UPDATE invoices
SET invoice_status = 'CANCELLED',
    cancelled_at = COALESCE(cancelled_at, created_at),
    cancelled_by = COALESCE(cancelled_by, created_by)
WHERE payment_status = 'CANCELLED'
  AND invoice_status <> 'CANCELLED';

UPDATE job_cards jc
INNER JOIN invoices i ON i.job_card_id = jc.id
SET jc.status = 'CLOSED',
    jc.closed_at = COALESCE(jc.closed_at, jc.completed_at, jc.updated_at, jc.opened_at),
    jc.completed_at = COALESCE(jc.completed_at, jc.closed_at, jc.updated_at, jc.opened_at)
WHERE jc.status_code = 'ACTIVE'
  AND jc.status NOT IN ('CLOSED', 'CANCELLED');

INSERT INTO invoice_number_sequences (company_id, garage_id, financial_year_id, financial_year_label, prefix, current_number)
SELECT
    i.company_id,
    i.garage_id,
    MAX(i.financial_year_id),
    i.financial_year_label,
    COALESCE((SELECT setting_value FROM system_settings ss WHERE ss.company_id = i.company_id AND (ss.garage_id = i.garage_id OR ss.garage_id IS NULL) AND ss.setting_key = 'invoice_prefix' AND ss.status_code = 'ACTIVE' ORDER BY CASE WHEN ss.garage_id = i.garage_id THEN 0 ELSE 1 END LIMIT 1), 'INV'),
    MAX(COALESCE(i.sequence_number, 0))
FROM invoices i
WHERE i.financial_year_label IS NOT NULL
GROUP BY i.company_id, i.garage_id, i.financial_year_label
ON DUPLICATE KEY UPDATE
    current_number = GREATEST(current_number, VALUES(current_number)),
    prefix = VALUES(prefix),
    financial_year_id = COALESCE(VALUES(financial_year_id), financial_year_id);

INSERT INTO invoice_status_history (invoice_id, from_status, to_status, action_type, action_note, payload_json, created_by, created_at)
SELECT
    i.id,
    NULL,
    i.invoice_status,
    'LEGACY_SYNC',
    'Legacy invoice state synchronized.',
    NULL,
    i.created_by,
    COALESCE(i.finalized_at, i.cancelled_at, i.created_at)
FROM invoices i
WHERE NOT EXISTS (
    SELECT 1
    FROM invoice_status_history ish
    WHERE ish.invoice_id = i.id
);

INSERT INTO invoice_payment_history (invoice_id, payment_id, action_type, action_note, payload_json, created_by, created_at)
SELECT
    p.invoice_id,
    p.id,
    'LEGACY_PAYMENT_SYNC',
    'Legacy payment synchronized.',
    NULL,
    p.received_by,
    p.created_at
FROM payments p
WHERE NOT EXISTS (
    SELECT 1
    FROM invoice_payment_history iph
    WHERE iph.payment_id = p.id
);

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'billing.view', 'View billing and invoices', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'billing.view');

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'billing.create', 'Create invoice drafts from closed jobs', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'billing.create');

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'billing.finalize', 'Finalize invoices and lock GST', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'billing.finalize');

INSERT INTO permissions (perm_key, perm_name, status_code)
SELECT 'billing.cancel', 'Cancel invoices with audit reason', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE perm_key = 'billing.cancel');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.perm_key IN ('billing.view', 'billing.create', 'billing.finalize', 'billing.cancel')
WHERE r.role_key IN ('super_admin', 'garage_owner', 'manager', 'accountant')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

COMMIT;
