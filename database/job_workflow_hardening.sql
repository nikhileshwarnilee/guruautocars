-- Job workflow hardening and backward-compatibility normalization.
-- Safe to run multiple times.

START TRANSACTION;

-- Normalize cancellation and deletion status codes.
UPDATE job_cards
SET status_code = 'INACTIVE'
WHERE status = 'CANCELLED'
  AND status_code = 'ACTIVE';

UPDATE job_cards
SET status_code = 'DELETED'
WHERE deleted_at IS NOT NULL
  AND status_code <> 'DELETED';

UPDATE job_cards
SET deleted_at = COALESCE(deleted_at, updated_at, created_at)
WHERE status_code = 'DELETED'
  AND deleted_at IS NULL;

-- If stock was already posted historically, force a coherent closed state.
UPDATE job_cards
SET status = 'CLOSED',
    closed_at = COALESCE(closed_at, stock_posted_at, updated_at),
    completed_at = COALESCE(completed_at, stock_posted_at, updated_at)
WHERE stock_posted_at IS NOT NULL
  AND status NOT IN ('CLOSED', 'CANCELLED');

-- Closed jobs must carry closed/completed timestamps.
UPDATE job_cards
SET closed_at = COALESCE(closed_at, completed_at, updated_at, opened_at),
    completed_at = COALESCE(completed_at, closed_at, updated_at, opened_at)
WHERE status = 'CLOSED';

-- Cancelled jobs should not be considered active.
UPDATE job_cards
SET status_code = 'INACTIVE'
WHERE status = 'CANCELLED'
  AND status_code = 'ACTIVE';

-- Backfill assignments from legacy assigned_to when no active assignments exist.
INSERT INTO job_assignments (job_card_id, user_id, assignment_role, is_primary, status_code, created_by, created_at, updated_at)
SELECT
    jc.id,
    jc.assigned_to,
    'MECHANIC',
    1,
    'ACTIVE',
    COALESCE(jc.updated_by, jc.created_by),
    COALESCE(jc.created_at, CURRENT_TIMESTAMP),
    COALESCE(jc.updated_at, CURRENT_TIMESTAMP)
FROM job_cards jc
WHERE jc.assigned_to IS NOT NULL
  AND NOT EXISTS (
    SELECT 1
    FROM job_assignments ja
    WHERE ja.job_card_id = jc.id
      AND ja.status_code = 'ACTIVE'
  );

-- Backfill job history for legacy rows with no timeline entries.
INSERT INTO job_history (job_card_id, action_type, to_status, action_note, created_by, created_at)
SELECT
    jc.id,
    'LEGACY_SYNC',
    jc.status,
    'Legacy workflow state synchronized.',
    COALESCE(jc.updated_by, jc.created_by),
    COALESCE(jc.created_at, CURRENT_TIMESTAMP)
FROM job_cards jc
WHERE NOT EXISTS (
    SELECT 1
    FROM job_history jh
    WHERE jh.job_card_id = jc.id
);

COMMIT;
