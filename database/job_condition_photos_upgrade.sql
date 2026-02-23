-- Job Condition Photos Upgrade (safe and idempotent)

CREATE TABLE IF NOT EXISTS job_condition_photos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  garage_id INT UNSIGNED NOT NULL,
  job_card_id INT UNSIGNED NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  image_width INT UNSIGNED DEFAULT NULL,
  image_height INT UNSIGNED DEFAULT NULL,
  note VARCHAR(255) DEFAULT NULL,
  uploaded_by INT UNSIGNED DEFAULT NULL,
  status_code VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  deleted_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_job_condition_scope_job (company_id, garage_id, job_card_id, status_code),
  KEY idx_job_condition_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO system_settings
  (company_id, garage_id, setting_group, setting_key, setting_value, value_type, status_code, created_by)
SELECT c.id, g.id, 'JOBS', 'job_condition_photo_retention_days', '90', 'NUMBER', 'ACTIVE', NULL
FROM companies c
INNER JOIN garages g ON g.company_id = c.id;

INSERT IGNORE INTO system_settings
  (company_id, garage_id, setting_group, setting_key, setting_value, value_type, status_code, created_by)
SELECT c.id, g.id, 'JOBS', 'job_condition_photo_max_mb', '5', 'NUMBER', 'ACTIVE', NULL
FROM companies c
INNER JOIN garages g ON g.company_id = c.id;
