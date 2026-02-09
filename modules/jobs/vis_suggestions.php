<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('job.view');
require_once __DIR__ . '/workflow.php';

header('Content-Type: application/json; charset=utf-8');

$companyId = active_company_id();
$garageId = active_garage_id();
$vehicleId = get_int('vehicle_id');

$suggestions = job_fetch_vis_suggestions($companyId, $garageId, $vehicleId);

echo json_encode([
    'ok' => true,
    'vehicle_id' => $vehicleId,
    'variant' => $suggestions['vehicle_variant'],
    'service_suggestions' => $suggestions['service_suggestions'],
    'part_suggestions' => $suggestions['part_suggestions'],
], JSON_UNESCAPED_UNICODE);

