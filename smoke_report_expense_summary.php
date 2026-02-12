<?php
declare(strict_types=1);

ini_set('session.save_path', __DIR__ . '/tmp_sessions');
require __DIR__ . '/includes/app.php';

$_SESSION['user_id'] = 1;
$_SESSION['company_id'] = 1;
$_SESSION['role_key'] = 'super_admin';
$_SESSION['active_garage_id'] = 1;

$today = date('Y-m-d');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [
    'garage_id' => '1',
    'from' => date('Y-m-01'),
    'to' => $today,
];

require __DIR__ . '/modules/reports/expenses.php';
