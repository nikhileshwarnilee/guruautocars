<?php
declare(strict_types=1);

ini_set('session.save_path', __DIR__ . '/tmp_sessions');
require __DIR__ . '/includes/app.php';

$_SESSION['user_id'] = 1;
$_SESSION['company_id'] = 1;
$_SESSION['role_key'] = 'super_admin';
$_SESSION['active_garage_id'] = 1;

$staffStmt = db()->prepare(
    'SELECT u.id
     FROM users u
     INNER JOIN user_garages ug ON ug.user_id = u.id
     WHERE u.company_id = :company_id
       AND ug.garage_id = :garage_id
       AND u.status_code = "ACTIVE"
     ORDER BY u.id ASC
     LIMIT 1'
);
$staffStmt->execute([
    'company_id' => 1,
    'garage_id' => 1,
]);
$staffId = (int) ($staffStmt->fetchColumn() ?: 1);

$structureStmt = db()->prepare(
    'INSERT INTO payroll_salary_structures
      (user_id, company_id, garage_id, salary_type, base_amount, commission_rate, overtime_rate, status_code, created_by)
     VALUES
      (:user_id, :company_id, :garage_id, "MONTHLY", 12000, 0, NULL, "ACTIVE", :created_by)
     ON DUPLICATE KEY UPDATE
      salary_type = VALUES(salary_type),
      base_amount = VALUES(base_amount),
      commission_rate = VALUES(commission_rate),
      overtime_rate = VALUES(overtime_rate),
      status_code = "ACTIVE",
      updated_by = VALUES(created_by)'
);
$structureStmt->execute([
    'user_id' => $staffId,
    'company_id' => 1,
    'garage_id' => 1,
    'created_by' => 1,
]);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    '_csrf' => csrf_token(),
    '_action' => 'generate_sheet',
    'salary_month' => date('Y-m'),
];

require __DIR__ . '/modules/payroll/index.php';
