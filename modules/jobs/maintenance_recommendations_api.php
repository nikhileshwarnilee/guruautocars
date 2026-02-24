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

if ($companyId <= 0 || $vehicleId <= 0) {
    echo json_encode([
        'ok' => true,
        'items' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!service_reminder_feature_ready()) {
    echo json_encode([
        'ok' => false,
        'message' => 'Maintenance reminder storage is not ready.',
        'items' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $items = service_reminder_due_recommendations_for_vehicle($companyId, $garageId, $vehicleId, 20);
    echo json_encode([
        'ok' => true,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to load maintenance recommendations.',
        'items' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

