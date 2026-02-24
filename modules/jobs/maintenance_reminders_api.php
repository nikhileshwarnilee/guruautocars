<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('job.view');
require_once __DIR__ . '/workflow.php';

header('Content-Type: application/json; charset=utf-8');

$companyId = active_company_id();
if ($companyId <= 0) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid company scope.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
$garageOptions = is_array($user['garages'] ?? null) ? (array) $user['garages'] : [];
$garageIds = array_values(
    array_filter(
        array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $garageOptions),
        static fn (int $id): bool => $id > 0
    )
);
if ($garageIds === []) {
    $garageIds = [active_garage_id()];
}

$allowAllGarages = count($garageIds) > 1;
$selectedGarageId = get_int('garage_id', active_garage_id());
if ($selectedGarageId > 0 && !in_array($selectedGarageId, $garageIds, true)) {
    $selectedGarageId = 0;
}
if ($selectedGarageId <= 0 && !$allowAllGarages && !empty($garageIds)) {
    $selectedGarageId = (int) $garageIds[0];
}
$canManageManualReminders = has_permission('job.manage') || has_permission('settings.manage') || has_permission('job.create');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = strtolower(trim((string) ($_POST['_action'] ?? '')));

    if ($action !== 'create_manual_reminder') {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'message' => 'Unsupported maintenance reminder action.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$canManageManualReminders) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'message' => 'You do not have permission to add manual reminders.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!service_reminder_feature_ready()) {
        http_response_code(503);
        echo json_encode([
            'ok' => false,
            'message' => 'Maintenance reminder storage is not ready.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
    $itemType = service_reminder_normalize_type((string) ($_POST['item_type'] ?? ''));
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $itemKey = trim((string) ($_POST['item_key'] ?? ''));
    if ($itemType === '' || $itemId <= 0) {
        if (preg_match('/^(SERVICE|PART):(\d+)$/', strtoupper($itemKey), $matches) === 1) {
            $itemType = service_reminder_normalize_type((string) ($matches[1] ?? ''));
            $itemId = (int) ($matches[2] ?? 0);
        }
    }

    $nextDueKm = service_reminder_parse_positive_int($_POST['next_due_km'] ?? null);
    $nextDueDate = service_reminder_parse_date((string) ($_POST['next_due_date'] ?? ''));
    $predictedVisit = service_reminder_parse_date((string) ($_POST['predicted_next_visit_date'] ?? ''));
    $recommendationText = trim((string) ($_POST['recommendation_text'] ?? ''));

    $manualGarageId = (int) ($_POST['garage_id'] ?? $selectedGarageId);
    if ($manualGarageId > 0 && !in_array($manualGarageId, $garageIds, true)) {
        $manualGarageId = 0;
    }
    if ($manualGarageId <= 0) {
        $manualGarageId = active_garage_id();
    }

    try {
        $result = service_reminder_create_manual_entry(
            $companyId,
            $manualGarageId,
            $vehicleId,
            $itemType,
            $itemId,
            $nextDueKm,
            $nextDueDate,
            $predictedVisit,
            $recommendationText,
            (int) ($_SESSION['user_id'] ?? 0)
        );

        if (!(bool) ($result['ok'] ?? false)) {
            http_response_code(422);
        }
        echo json_encode([
            'ok' => (bool) ($result['ok'] ?? false),
            'message' => (string) ($result['message'] ?? ((bool) ($result['ok'] ?? false) ? 'Manual reminder saved.' : 'Unable to save manual reminder.')),
            'reminder_id' => (int) ($result['reminder_id'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Unable to save manual reminder.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$serviceTypeFilter = service_reminder_normalize_type((string) ($_GET['service_type'] ?? ''));
$statusFilter = strtoupper(trim((string) ($_GET['status'] ?? 'ALL')));
$allowedStatuses = ['ALL', 'UPCOMING', 'DUE', 'OVERDUE', 'COMPLETED'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'ALL';
}

$defaultFromDate = date('Y-m-d', strtotime('-30 days'));
$defaultToDate = date('Y-m-d');
$fromDate = service_reminder_parse_date((string) ($_GET['from'] ?? $defaultFromDate)) ?? $defaultFromDate;
$toDate = service_reminder_parse_date((string) ($_GET['to'] ?? $defaultToDate)) ?? $defaultToDate;
if (strcmp($fromDate, $toDate) > 0) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

if (!service_reminder_feature_ready()) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'message' => 'Maintenance reminder storage is not ready.',
        'rows' => [],
        'summary' => [
            'total' => 0,
            'overdue' => 0,
            'due' => 0,
            'due_soon' => 0,
            'upcoming' => 0,
            'completed' => 0,
            'unscheduled' => 0,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $rows = service_reminder_fetch_register_for_scope(
        $companyId,
        $selectedGarageId,
        $garageIds,
        3000,
        $serviceTypeFilter !== '' ? $serviceTypeFilter : null,
        $statusFilter,
        $fromDate,
        $toDate
    );

    echo json_encode([
        'ok' => true,
        'rows' => $rows,
        'summary' => service_reminder_summary_counts($rows),
        'filters' => [
            'garage_id' => $selectedGarageId,
            'service_type' => $serviceTypeFilter,
            'status' => $statusFilter,
            'from' => $fromDate,
            'to' => $toDate,
            'allow_all_garages' => $allowAllGarages,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to load maintenance reminders.',
        'rows' => [],
        'summary' => [
            'total' => 0,
            'overdue' => 0,
            'due' => 0,
            'due_soon' => 0,
            'upcoming' => 0,
            'completed' => 0,
            'unscheduled' => 0,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
