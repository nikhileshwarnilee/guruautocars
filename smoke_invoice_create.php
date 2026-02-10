<?php
declare(strict_types=1);
ini_set('session.save_path', __DIR__ . '/tmp_sessions');
require __DIR__ . '/includes/app.php';
$_SESSION['user_id'] = 1;
$_SESSION['company_id'] = 1;
$_SESSION['role_key'] = 'super_admin';
$_SESSION['active_garage_id'] = 1;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    '_csrf' => csrf_token(),
    '_action' => 'create_invoice',
    'job_card_id' => '4',
    'due_date' => date('Y-m-d'),
    'notes' => 'Smoke draft',
];
require __DIR__ . '/modules/billing/index.php';
