-- Odometer Flow Upgrade
-- Adds per-job odometer capture and backfills legacy rows conservatively.
-- Safe and idempotent.

ALTER TABLE job_cards
  ADD COLUMN IF NOT EXISTS odometer_km INT UNSIGNED NOT NULL DEFAULT 0 AFTER vehicle_id;

ALTER TABLE job_cards
  ADD INDEX IF NOT EXISTS idx_job_cards_vehicle_odometer (vehicle_id, odometer_km);

-- Legacy backfill: if old job cards do not have odometer yet, seed from current vehicle master.
-- This keeps old records usable while new jobs capture accurate per-job readings.
UPDATE job_cards jc
INNER JOIN vehicles v ON v.id = jc.vehicle_id
SET jc.odometer_km = v.odometer_km
WHERE jc.odometer_km = 0
  AND COALESCE(v.odometer_km, 0) > 0;
