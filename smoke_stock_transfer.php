<?php
declare(strict_types=1);
ini_set('session.save_path', __DIR__ . '/tmp_sessions');
require __DIR__ . '/includes/app.php';
$_SESSION['user_id'] = 1;
$_SESSION['company_id'] = 1;
$_SESSION['role_key'] = 'super_admin';
$_SESSION['active_garage_id'] = 1;
$_SESSION['_inventory_action_tokens'] = ['stock_transfer' => 'smoke-transfer-token'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    '_csrf' => csrf_token(),
    '_action' => 'stock_transfer',
    'action_token' => 'smoke-transfer-token',
    'part_id' => '1',
    'to_garage_id' => '3',
    'quantity' => '1',
    'notes' => 'Smoke transfer',
];
require __DIR__ . '/modules/inventory/index.php';
