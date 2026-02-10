-- Job Card Advanced Flow Upgrade (outsourced execution + payable tracking)
-- Safe and idempotent.

ALTER TABLE job_labor
  ADD COLUMN IF NOT EXISTS execution_type ENUM('IN_HOUSE','OUTSOURCED') NOT NULL DEFAULT 'IN_HOUSE',
  ADD COLUMN IF NOT EXISTS outsource_vendor_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS outsource_partner_name VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS outsource_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS outsource_payable_status ENUM('UNPAID','PAID') NOT NULL DEFAULT 'PAID',
  ADD COLUMN IF NOT EXISTS outsource_paid_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS outsource_paid_by INT UNSIGNED NULL;

ALTER TABLE job_labor
  ADD INDEX IF NOT EXISTS idx_job_labor_execution_payable (execution_type, outsource_payable_status),
  ADD INDEX IF NOT EXISTS idx_job_labor_outsource_vendor (outsource_vendor_id),
  ADD INDEX IF NOT EXISTS idx_job_labor_outsource_paid_at (outsource_paid_at);

UPDATE job_labor jl
LEFT JOIN vendors v ON v.id = jl.outsource_vendor_id
SET
  jl.outsource_partner_name = CASE
    WHEN jl.execution_type = 'OUTSOURCED'
      THEN COALESCE(NULLIF(jl.outsource_partner_name, ''), v.vendor_name)
    ELSE NULL
  END,
  jl.outsource_cost = CASE
    WHEN jl.execution_type = 'OUTSOURCED' THEN COALESCE(jl.outsource_cost, 0)
    ELSE 0
  END,
  jl.outsource_payable_status = CASE
    WHEN jl.execution_type = 'OUTSOURCED' AND COALESCE(jl.outsource_cost, 0) > 0
      THEN CASE WHEN jl.outsource_payable_status = 'PAID' THEN 'PAID' ELSE 'UNPAID' END
    ELSE 'PAID'
  END,
  jl.outsource_paid_at = CASE
    WHEN jl.execution_type = 'OUTSOURCED'
         AND COALESCE(jl.outsource_cost, 0) > 0
      THEN CASE
        WHEN jl.outsource_payable_status = 'PAID' THEN COALESCE(jl.outsource_paid_at, jl.created_at, NOW())
        ELSE NULL
      END
    ELSE NULL
  END,
  jl.outsource_paid_by = CASE
    WHEN jl.execution_type = 'OUTSOURCED'
         AND COALESCE(jl.outsource_cost, 0) > 0
      THEN CASE
        WHEN jl.outsource_payable_status = 'PAID' THEN jl.outsource_paid_by
        ELSE NULL
      END
    ELSE NULL
  END;
