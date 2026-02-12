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

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    '_csrf' => csrf_token(),
    '_action' => 'record_advance',
    'user_id' => (string) $staffId,
    'amount' => '250.00',
    'advance_date' => date('Y-m-d'),
    'notes' => 'Smoke advance',
];

require __DIR__ . '/modules/payroll/index.php';
