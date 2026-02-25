<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/workflow.php';

if (!has_permission('job.view')) {
    ajax_json([
        'ok' => false,
        'message' => 'Access denied.',
    ], 403);
}

$companyId = active_company_id();
$activeGarageId = active_garage_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$roleKey = (string) (($_SESSION['role_key'] ?? '') ?: ((current_user() ?: [])['role_key'] ?? ''));
$isOwnerScope = analytics_is_owner_role($roleKey);

$garageOptions = analytics_accessible_garages($companyId, $isOwnerScope);
$garageIds = array_values(array_filter(array_map(
    static fn (array $garage): int => (int) ($garage['id'] ?? 0),
    $garageOptions
), static fn (int $id): bool => $id > 0));
$allowAllGarages = $isOwnerScope && count($garageIds) > 1;

$requestedGarageId = isset($_REQUEST['garage_id']) ? get_int('garage_id', $activeGarageId) : $activeGarageId;
$selectedGarageId = analytics_resolve_scope_garage_id($garageOptions, $activeGarageId, $requestedGarageId, $allowAllGarages);

$boardStatuses = ['OPEN', 'IN_PROGRESS', 'WAITING_PARTS', 'COMPLETED', 'CLOSED'];
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    require_csrf();

    if (!(has_permission('job.edit') || has_permission('job.update') || has_permission('job.manage') || has_permission('job.close'))) {
        ajax_json([
            'ok' => false,
            'message' => 'You do not have permission to move jobs on queue board.',
        ], 403);
    }

    $action = trim((string) ($_POST['action'] ?? 'move'));
    if ($action !== 'move') {
        ajax_json([
            'ok' => false,
            'message' => 'Unsupported queue action.',
        ], 422);
    }

    $jobId = post_int('job_id');
    $targetStatus = job_normalize_status((string) ($_POST['to_status'] ?? ''));
    if ($jobId <= 0 || !in_array($targetStatus, $boardStatuses, true)) {
        ajax_json([
            'ok' => false,
            'message' => 'Job and target status are required.',
        ], 422);
    }

    try {
        $jobStmt = db()->prepare(
            'SELECT id, company_id, garage_id, status, status_code, completed_at, closed_at, cancel_note
             FROM job_cards
             WHERE id = :id
               AND company_id = :company_id
               AND status_code <> "DELETED"
             LIMIT 1'
        );
        $jobStmt->execute([
            'id' => $jobId,
            'company_id' => $companyId,
        ]);
        $jobRow = $jobStmt->fetch();
        if (!$jobRow) {
            throw new RuntimeException('Job card not found.');
        }

        $jobGarageId = (int) ($jobRow['garage_id'] ?? 0);
        if ($jobGarageId <= 0 || !in_array($jobGarageId, $garageIds, true)) {
            throw new RuntimeException('Job card is outside your garage scope.');
        }

        $fromStatus = job_normalize_status((string) ($jobRow['status'] ?? 'OPEN'));
        if ($fromStatus === $targetStatus) {
            ajax_json([
                'ok' => true,
                'message' => 'Status unchanged.',
                'job_id' => $jobId,
                'from_status' => $fromStatus,
                'to_status' => $targetStatus,
                'warnings' => [],
            ]);
        }

        if (job_is_locked($jobRow)) {
            throw new RuntimeException('This job card is locked and cannot be moved.');
        }

        if (!job_can_transition($fromStatus, $targetStatus)) {
            throw new RuntimeException('Invalid workflow transition from ' . $fromStatus . ' to ' . $targetStatus . '.');
        }

        $canClose = has_permission('job.close') || has_permission('job.manage');
        $canEdit = has_permission('job.edit') || has_permission('job.update') || has_permission('job.manage');
        if ($targetStatus === 'CLOSED' && !$canClose) {
            throw new RuntimeException('You do not have permission to close job cards.');
        }
        if ($targetStatus !== 'CLOSED' && !$canEdit) {
            throw new RuntimeException('You do not have permission to change this job status.');
        }

        $inventoryWarnings = [];
        $reminderResult = [
            'created_count' => 0,
            'disabled_count' => 0,
            'warnings' => [],
            'created_types' => [],
        ];

        if ($targetStatus === 'CLOSED') {
            $postResult = job_post_inventory_on_close($jobId, $companyId, $jobGarageId, $userId);
            $inventoryWarnings = (array) ($postResult['warnings'] ?? []);
            $reminderResult = service_reminder_apply_on_job_close($jobId, $companyId, $jobGarageId, $userId);
        }

        $updateStmt = db()->prepare(
            'UPDATE job_cards
             SET status = :status,
                 completed_at = CASE
                     WHEN :status IN ("COMPLETED", "CLOSED") AND completed_at IS NULL THEN NOW()
                     ELSE completed_at
                 END,
                 closed_at = CASE
                     WHEN :status = "CLOSED" THEN NOW()
                     ELSE closed_at
                 END,
                 status_code = CASE
                     WHEN :status = "CANCELLED" THEN "INACTIVE"
                     ELSE status_code
                 END,
                 cancel_note = CASE
                     WHEN :status = "CANCELLED" THEN :cancel_note
                     ELSE cancel_note
                 END,
                 updated_by = :updated_by
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id
               AND status_code <> "DELETED"'
        );
        $updateStmt->execute([
            'status' => $targetStatus,
            'cancel_note' => null,
            'updated_by' => $userId > 0 ? $userId : null,
            'id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $jobGarageId,
        ]);

        $updatedStmt = db()->prepare(
            'SELECT id, status, status_code, completed_at, closed_at, cancel_note
             FROM job_cards
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id
             LIMIT 1'
        );
        $updatedStmt->execute([
            'id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $jobGarageId,
        ]);
        $updatedRow = $updatedStmt->fetch() ?: [
            'status' => $targetStatus,
            'status_code' => (string) ($jobRow['status_code'] ?? 'ACTIVE'),
            'completed_at' => $jobRow['completed_at'] ?? null,
            'closed_at' => $jobRow['closed_at'] ?? null,
            'cancel_note' => $jobRow['cancel_note'] ?? null,
        ];

        job_append_history(
            $jobId,
            'STATUS_CHANGE',
            $fromStatus,
            $targetStatus,
            'Updated from queue board',
            [
                'source' => 'QUEUE_BOARD',
                'inventory_warnings' => count($inventoryWarnings),
                'reminders_created' => (int) ($reminderResult['created_count'] ?? 0),
            ]
        );

        log_audit('job_cards', 'queue_status_change', $jobId, 'Status changed from ' . $fromStatus . ' to ' . $targetStatus . ' via queue board', [
            'entity' => 'job_card',
            'source' => 'UI',
            'before' => [
                'status' => (string) ($jobRow['status'] ?? $fromStatus),
                'status_code' => (string) ($jobRow['status_code'] ?? 'ACTIVE'),
                'completed_at' => (string) ($jobRow['completed_at'] ?? ''),
                'closed_at' => (string) ($jobRow['closed_at'] ?? ''),
                'cancel_note' => (string) ($jobRow['cancel_note'] ?? ''),
            ],
            'after' => [
                'status' => (string) ($updatedRow['status'] ?? $targetStatus),
                'status_code' => (string) ($updatedRow['status_code'] ?? 'ACTIVE'),
                'completed_at' => (string) ($updatedRow['completed_at'] ?? ''),
                'closed_at' => (string) ($updatedRow['closed_at'] ?? ''),
                'cancel_note' => (string) ($updatedRow['cancel_note'] ?? ''),
            ],
            'metadata' => [
                'inventory_warning_count' => count($inventoryWarnings),
                'reminder_created_count' => (int) ($reminderResult['created_count'] ?? 0),
                'reminder_warning_count' => count((array) ($reminderResult['warnings'] ?? [])),
            ],
        ]);

        ajax_json([
            'ok' => true,
            'message' => 'Job moved to ' . $targetStatus . '.',
            'job_id' => $jobId,
            'from_status' => $fromStatus,
            'to_status' => $targetStatus,
            'warnings' => array_values(array_slice($inventoryWarnings, 0, 5)),
        ]);
    } catch (Throwable $exception) {
        ajax_json([
            'ok' => false,
            'message' => $exception->getMessage(),
        ], 422);
    }
}

$jobParams = ['company_id' => $companyId];
$jobScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $jobParams, 'queue_scope');
$statusPlaceholders = implode(', ', array_map(static fn (string $status): string => '"' . $status . '"', $boardStatuses));

$jobsStmt = db()->prepare(
    'SELECT jc.id, jc.job_number, jc.status, jc.priority, jc.opened_at, jc.promised_at,
            c.full_name AS customer_name, c.phone AS customer_phone,
            v.registration_no, v.brand, v.model,
            g.name AS garage_name,
            GROUP_CONCAT(DISTINCT au.name ORDER BY ja.is_primary DESC, au.name SEPARATOR ", ") AS mechanic_names
     FROM job_cards jc
     INNER JOIN customers c ON c.id = jc.customer_id
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     INNER JOIN garages g ON g.id = jc.garage_id
     LEFT JOIN job_assignments ja ON ja.job_card_id = jc.id AND ja.status_code = "ACTIVE"
     LEFT JOIN users au ON au.id = ja.user_id
     WHERE jc.company_id = :company_id
       AND jc.status_code = "ACTIVE"
       ' . $jobScopeSql . '
       AND jc.status IN (' . $statusPlaceholders . ')
     GROUP BY jc.id
     ORDER BY CASE jc.status
            WHEN "OPEN" THEN 1
            WHEN "IN_PROGRESS" THEN 2
            WHEN "WAITING_PARTS" THEN 3
            WHEN "COMPLETED" THEN 4
            WHEN "CLOSED" THEN 5
            ELSE 6
        END, jc.id DESC'
);
$jobsStmt->execute($jobParams);
$rows = $jobsStmt->fetchAll();

$grouped = [];
foreach ($boardStatuses as $status) {
    $grouped[$status] = [];
}
foreach ($rows as $row) {
    $status = job_normalize_status((string) ($row['status'] ?? 'OPEN'));
    if (!isset($grouped[$status])) {
        $grouped[$status] = [];
    }
    $grouped[$status][] = [
        'id' => (int) ($row['id'] ?? 0),
        'job_number' => (string) ($row['job_number'] ?? ''),
        'status' => $status,
        'priority' => (string) ($row['priority'] ?? 'MEDIUM'),
        'opened_at' => (string) ($row['opened_at'] ?? ''),
        'promised_at' => (string) ($row['promised_at'] ?? ''),
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'customer_phone' => (string) ($row['customer_phone'] ?? ''),
        'registration_no' => (string) ($row['registration_no'] ?? ''),
        'brand' => (string) ($row['brand'] ?? ''),
        'model' => (string) ($row['model'] ?? ''),
        'garage_name' => (string) ($row['garage_name'] ?? ''),
        'mechanic_names' => (string) ($row['mechanic_names'] ?? ''),
    ];
}

ajax_json([
    'ok' => true,
    'selected_garage_id' => $selectedGarageId,
    'grouped_jobs' => $grouped,
    'statuses' => $boardStatuses,
    'generated_at' => date('c'),
]);
