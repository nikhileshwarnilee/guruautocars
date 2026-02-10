<?php
declare(strict_types=1);
ini_set('session.save_path', __DIR__ . '/tmp_sessions');
require __DIR__ . '/includes/app.php';
$_SESSION['user_id'] = 1;
$_SESSION['company_id'] = 1;
$_SESSION['role_key'] = 'super_admin';
$_SESSION['active_garage_id'] = 1;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [
  'garage_id' => '3',
  'fy_id' => '2',
  'from' => '2026-02-10',
  'to' => '2026-02-10',
  'include_draft' => '0',
  'include_cancelled' => '0',
  'module' => 'inventory',
  'download' => '1',
];
require __DIR__ . '/modules/system/exports.php';
\n//cleanup-marker
