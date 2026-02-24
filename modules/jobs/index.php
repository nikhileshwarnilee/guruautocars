<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('job.view');
require_once __DIR__ . '/workflow.php';

$page_title = 'Job Cards / Work Orders';
$active_menu = 'jobs';
$companyId = active_company_id();
$garageId = active_garage_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$canCreate = has_permission('job.create') || has_permission('job.manage');
$canEdit = has_permission('job.edit') || has_permission('job.update') || has_permission('job.manage');
$canAssign = has_permission('job.assign') || has_permission('job.manage');
$canInlineVehicleCreate = has_permission('vehicle.view') && has_permission('vehicle.manage');
$canConditionPhotoManage = has_permission('job.manage') || has_permission('settings.manage');
$jobCardColumns = table_columns('job_cards');
$jobOdometerEnabled = in_array('odometer_km', $jobCardColumns, true);
$jobRecommendationNoteEnabled = job_recommendation_note_feature_ready();
$odometerEditableStatuses = ['OPEN', 'IN_PROGRESS'];
$maintenanceReminderFeatureReady = service_reminder_feature_ready();
$maintenanceRecommendationApiUrl = url('modules/jobs/maintenance_recommendations_api.php');

function parse_ids(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    foreach ($value as $id) {
        if (filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0) {
            $result[] = (int) $id;
        }
    }
    return array_values(array_unique($result));
}

function job_row(int $id, int $companyId, int $garageId): ?array
{
    $stmt = db()->prepare('SELECT * FROM job_cards WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id LIMIT 1');
    $stmt->execute(['id' => $id, 'company_id' => $companyId, 'garage_id' => $garageId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function job_filter_url(string $query, string $status): string
{
    $params = [];
    if ($query !== '') {
        $params['q'] = $query;
    }
    if ($status !== '') {
        $params['status'] = $status;
    }

    $path = 'modules/jobs/index.php';
    if (!empty($params)) {
        $path .= '?' . http_build_query($params);
    }

    return url($path) . '#job-list-section';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'update_condition_photo_settings') {
        if (!$canConditionPhotoManage) {
            flash_set('job_error', 'You do not have permission to manage condition-photo settings.', 'danger');
            redirect('modules/jobs/index.php');
        }
        if (!job_condition_photo_feature_ready()) {
            flash_set('job_error', 'Condition photo storage is not ready. Please run DB upgrade.', 'danger');
            redirect('modules/jobs/index.php');
        }

        $retentionDays = post_int('condition_photo_retention_days');
        if ($retentionDays < 1) {
            $retentionDays = 1;
        }
        if ($retentionDays > 3650) {
            $retentionDays = 3650;
        }

        system_setting_upsert_value(
            $companyId,
            $garageId,
            'JOBS',
            'job_condition_photo_retention_days',
            (string) $retentionDays,
            'NUMBER',
            'ACTIVE',
            $userId > 0 ? $userId : null
        );
        log_audit('system_settings', 'update', 0, 'Updated job condition photo retention days', [
            'entity' => 'job_condition_photos',
            'source' => 'UI',
            'metadata' => [
                'garage_id' => $garageId,
                'retention_days' => $retentionDays,
            ],
        ]);
        flash_set('job_success', 'Condition photo retention updated to ' . $retentionDays . ' day(s).', 'success');
        redirect('modules/jobs/index.php');
    }

    if ($action === 'purge_condition_photos') {
        if (!$canConditionPhotoManage) {
            flash_set('job_error', 'You do not have permission to purge condition photos.', 'danger');
            redirect('modules/jobs/index.php');
        }
        if (!job_condition_photo_feature_ready()) {
            flash_set('job_error', 'Condition photo storage is not ready. Please run DB upgrade.', 'danger');
            redirect('modules/jobs/index.php');
        }

        $retentionDays = post_int('condition_photo_retention_days');
        if ($retentionDays <= 0) {
            $retentionDays = job_condition_photo_retention_days($companyId, $garageId);
        }

        $purgeResult = job_condition_photo_purge_older_than($companyId, $garageId, $retentionDays);
        if (!(bool) ($purgeResult['ok'] ?? false)) {
            flash_set('job_error', (string) ($purgeResult['message'] ?? 'Unable to purge condition photos.'), 'danger');
            redirect('modules/jobs/index.php');
        }

        $deletedCount = (int) ($purgeResult['deleted_count'] ?? 0);
        $failedCount = (int) ($purgeResult['failed_count'] ?? 0);
        $deletedMb = round(((int) ($purgeResult['deleted_bytes'] ?? 0)) / 1048576, 2);

        flash_set('job_success', 'Purged ' . $deletedCount . ' photo(s) older than ' . $retentionDays . ' day(s). Freed ~' . number_format($deletedMb, 2) . ' MB.', 'success');
        if ($failedCount > 0) {
            flash_set('job_warning', $failedCount . ' file(s) could not be removed from filesystem. Metadata was marked deleted.', 'warning');
        }

        log_audit('job_cards', 'purge_condition_photos', 0, 'Purged old job condition photos', [
            'entity' => 'job_condition_photos',
            'source' => 'UI',
            'metadata' => [
                'garage_id' => $garageId,
                'retention_days' => $retentionDays,
                'deleted_count' => $deletedCount,
                'failed_count' => $failedCount,
                'deleted_bytes' => (int) ($purgeResult['deleted_bytes'] ?? 0),
            ],
        ]);
        redirect('modules/jobs/index.php');
    }

    if ($action === 'create' && !$canCreate) {
        flash_set('job_error', 'You do not have permission to create job cards.', 'danger');
        redirect('modules/jobs/index.php');
    }

    if ($action === 'update' && !$canEdit) {
        flash_set('job_error', 'You do not have permission to edit job cards.', 'danger');
        redirect('modules/jobs/index.php');
    }

    if ($action === 'create' && $canCreate) {
        $customerId = post_int('customer_id');
        $vehicleId = post_int('vehicle_id');
        $complaint = post_string('complaint', 3000);
        $diagnosis = post_string('diagnosis', 3000);
        $recommendationNote = $jobRecommendationNoteEnabled ? post_string('recommendation_note', 5000) : '';
        $odometerRaw = trim((string) ($_POST['odometer_km'] ?? ''));
        $odometerKm = null;
        $priority = strtoupper(post_string('priority', 10));
        $promisedAt = post_string('promised_at', 25);
        $assignedUserIds = $canAssign ? parse_ids($_POST['assigned_user_ids'] ?? []) : [];
        $maintenanceActions = [];
        if ($maintenanceReminderFeatureReady) {
            $rawMaintenanceActions = $_POST['maintenance_action'] ?? [];
            if (is_array($rawMaintenanceActions)) {
                foreach ($rawMaintenanceActions as $reminderId => $actionValue) {
                    $id = (int) $reminderId;
                    if ($id <= 0) {
                        continue;
                    }
                    $actionKey = strtolower(trim((string) $actionValue));
                    if (!in_array($actionKey, ['add', 'ignore', 'postpone'], true)) {
                        $actionKey = 'ignore';
                    }
                    $maintenanceActions[$id] = $actionKey;
                }
            }
        }
        if (!in_array($priority, ['LOW', 'MEDIUM', 'HIGH', 'URGENT'], true)) {
            $priority = 'MEDIUM';
        }
        if ($jobOdometerEnabled) {
            $parsedOdometer = filter_var($odometerRaw, FILTER_VALIDATE_INT);
            if ($odometerRaw === '' || $parsedOdometer === false || (int) $parsedOdometer < 0) {
                flash_set('job_error', 'Odometer reading is required and must be a valid non-negative number.', 'danger');
                redirect('modules/jobs/index.php');
            }
            $odometerKm = (int) $parsedOdometer;
        }
        if ($customerId > 0 && $vehicleId <= 0) {
            $autoVehicleStmt = db()->prepare(
                'SELECT id
                 FROM vehicles
                 WHERE company_id = :company_id
                   AND customer_id = :customer_id
                   AND status_code = "ACTIVE"
                 ORDER BY id ASC
                 LIMIT 2'
            );
            $autoVehicleStmt->execute([
                'company_id' => $companyId,
                'customer_id' => $customerId,
            ]);
            $autoVehicleRows = $autoVehicleStmt->fetchAll();
            if (count($autoVehicleRows) === 1) {
                $vehicleId = (int) ($autoVehicleRows[0]['id'] ?? 0);
            }
        }
        if ($customerId <= 0 || $vehicleId <= 0 || $complaint === '') {
            flash_set('job_error', 'Customer, vehicle and complaint are required.', 'danger');
            redirect('modules/jobs/index.php');
        }

        $check = db()->prepare(
            'SELECT COUNT(*) FROM vehicles v INNER JOIN customers c ON c.id = v.customer_id
             WHERE v.id = :vehicle_id AND c.id = :customer_id AND v.company_id = :company_id AND c.company_id = :company_id
               AND v.status_code = "ACTIVE" AND c.status_code = "ACTIVE"'
        );
        $check->execute(['vehicle_id' => $vehicleId, 'customer_id' => $customerId, 'company_id' => $companyId]);
        if ((int) $check->fetchColumn() === 0) {
            flash_set('job_error', 'Vehicle must belong to selected active customer.', 'danger');
            redirect('modules/jobs/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $jobNumber = job_generate_number($pdo, $garageId);
            $recommendationColumnSql = $jobRecommendationNoteEnabled ? ', recommendation_note' : '';
            $recommendationValueSql = $jobRecommendationNoteEnabled ? ', :recommendation_note' : '';
            if ($jobOdometerEnabled) {
                $stmt = $pdo->prepare(
                    'INSERT INTO job_cards
                      (company_id, garage_id, job_number, customer_id, vehicle_id, odometer_km, assigned_to, service_advisor_id, complaint, diagnosis' . $recommendationColumnSql . ', status, priority, promised_at, status_code, created_by, updated_by)
                     VALUES
                      (:company_id, :garage_id, :job_number, :customer_id, :vehicle_id, :odometer_km, NULL, :service_advisor_id, :complaint, :diagnosis' . $recommendationValueSql . ', "OPEN", :priority, :promised_at, "ACTIVE", :created_by, :updated_by)'
                );
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO job_cards
                      (company_id, garage_id, job_number, customer_id, vehicle_id, assigned_to, service_advisor_id, complaint, diagnosis' . $recommendationColumnSql . ', status, priority, promised_at, status_code, created_by, updated_by)
                     VALUES
                      (:company_id, :garage_id, :job_number, :customer_id, :vehicle_id, NULL, :service_advisor_id, :complaint, :diagnosis' . $recommendationValueSql . ', "OPEN", :priority, :promised_at, "ACTIVE", :created_by, :updated_by)'
                );
            }

            $insertParams = [
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'job_number' => $jobNumber,
                'customer_id' => $customerId,
                'vehicle_id' => $vehicleId,
                'service_advisor_id' => $userId,
                'complaint' => $complaint,
                'diagnosis' => $diagnosis !== '' ? $diagnosis : null,
                'priority' => $priority,
                'promised_at' => $promisedAt !== '' ? str_replace('T', ' ', $promisedAt) : null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ];
            if ($jobRecommendationNoteEnabled) {
                $insertParams['recommendation_note'] = $recommendationNote !== '' ? $recommendationNote : null;
            }
            if ($jobOdometerEnabled) {
                $insertParams['odometer_km'] = $odometerKm !== null ? $odometerKm : 0;
            }
            $stmt->execute($insertParams);
            $jobId = (int) $pdo->lastInsertId();
            if ($canAssign) {
                $assigned = job_sync_assignments($jobId, $companyId, $garageId, $assignedUserIds, $userId);
                if (!empty($assigned)) {
                    job_append_history($jobId, 'ASSIGN_CREATE', null, null, 'Assigned users', ['user_ids' => $assigned]);
                }
            }
            $maintenanceResult = [
                'added_count' => 0,
                'postponed_count' => 0,
                'ignored_count' => 0,
                'warnings' => [],
            ];
            if ($maintenanceReminderFeatureReady && !empty($maintenanceActions)) {
                $maintenanceResult = service_reminder_apply_job_creation_actions(
                    $companyId,
                    $garageId,
                    $jobId,
                    $vehicleId,
                    $customerId,
                    $jobOdometerEnabled ? $odometerKm : null,
                    $userId,
                    $maintenanceActions
                );
                job_append_history($jobId, 'MAINTENANCE_ACTIONS', null, null, 'Applied maintenance recommendation actions', [
                    'added_count' => (int) ($maintenanceResult['added_count'] ?? 0),
                    'postponed_count' => (int) ($maintenanceResult['postponed_count'] ?? 0),
                    'ignored_count' => (int) ($maintenanceResult['ignored_count'] ?? 0),
                ]);
            }
            $createHistoryPayload = ['job_number' => $jobNumber];
            if ($jobOdometerEnabled && $odometerKm !== null) {
                $createHistoryPayload['odometer_km'] = $odometerKm;
            }
            job_append_history($jobId, 'CREATE', null, 'OPEN', 'Job created', $createHistoryPayload);
            log_audit('job_cards', 'create', $jobId, 'Created job card ' . $jobNumber, [
                'entity' => 'job_card',
                'source' => 'UI',
                'before' => ['exists' => false],
                'after' => [
                    'id' => $jobId,
                    'job_number' => $jobNumber,
                    'status' => 'OPEN',
                    'status_code' => 'ACTIVE',
                    'priority' => $priority,
                    'customer_id' => $customerId,
                    'vehicle_id' => $vehicleId,
                    'odometer_km' => $jobOdometerEnabled && $odometerKm !== null ? $odometerKm : null,
                    'recommendation_note' => $jobRecommendationNoteEnabled ? ($recommendationNote !== '' ? $recommendationNote : null) : null,
                ],
                'metadata' => [
                    'assigned_count' => isset($assigned) && is_array($assigned) ? count($assigned) : 0,
                    'maintenance_added' => (int) ($maintenanceResult['added_count'] ?? 0),
                    'maintenance_postponed' => (int) ($maintenanceResult['postponed_count'] ?? 0),
                ],
            ]);
            $pdo->commit();
            flash_set('job_success', 'Job card created successfully. Upload vehicle condition photos now.', 'success');
            if ((int) ($maintenanceResult['added_count'] ?? 0) > 0
                || (int) ($maintenanceResult['postponed_count'] ?? 0) > 0
                || (int) ($maintenanceResult['ignored_count'] ?? 0) > 0) {
                flash_set(
                    'job_info',
                    'Maintenance recommendations: Added ' . (int) ($maintenanceResult['added_count'] ?? 0)
                    . ', Postponed ' . (int) ($maintenanceResult['postponed_count'] ?? 0)
                    . ', Ignored ' . (int) ($maintenanceResult['ignored_count'] ?? 0) . '.',
                    'info'
                );
            }
            $maintenanceWarnings = (array) ($maintenanceResult['warnings'] ?? []);
            foreach ($maintenanceWarnings as $index => $warning) {
                $warningMessage = trim((string) $warning);
                if ($warningMessage === '') {
                    continue;
                }
                flash_set('job_warning_maintenance_' . $index, $warningMessage, 'warning');
            }
            redirect('modules/jobs/view.php?id=' . $jobId . '&prompt_condition_photos=1#condition-photos');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('job_error', 'Unable to create job card. Please retry.', 'danger');
            redirect('modules/jobs/index.php');
        }
    }

    if ($action === 'update' && $canEdit) {
        $jobId = post_int('job_id');
        $job = job_row($jobId, $companyId, $garageId);
        if (!$job) {
            flash_set('job_error', 'Job card not found.', 'danger');
            redirect('modules/jobs/index.php');
        }
        if (job_is_locked($job)) {
            flash_set('job_error', 'This job card is locked and cannot be edited.', 'danger');
            redirect('modules/jobs/index.php');
        }

        $complaint = post_string('complaint', 3000);
        $diagnosis = post_string('diagnosis', 3000);
        $recommendationNote = $jobRecommendationNoteEnabled ? post_string('recommendation_note', 5000) : '';
        $odometerRaw = trim((string) ($_POST['odometer_km'] ?? ''));
        $odometerKm = null;
        $priority = strtoupper(post_string('priority', 10));
        $promisedAt = post_string('promised_at', 25);
        $assignedUserIds = $canAssign ? parse_ids($_POST['assigned_user_ids'] ?? []) : [];
        $currentStatus = job_normalize_status((string) ($job['status'] ?? 'OPEN'));
        $odometerEditable = $jobOdometerEnabled && in_array($currentStatus, $odometerEditableStatuses, true);
        if (!in_array($priority, ['LOW', 'MEDIUM', 'HIGH', 'URGENT'], true)) {
            $priority = 'MEDIUM';
        }
        if ($complaint === '') {
            flash_set('job_error', 'Complaint is required.', 'danger');
            redirect('modules/jobs/index.php?edit_id=' . $jobId);
        }
        if ($jobOdometerEnabled) {
            if ($odometerEditable) {
                $parsedOdometer = filter_var($odometerRaw, FILTER_VALIDATE_INT);
                if ($odometerRaw === '' || $parsedOdometer === false || (int) $parsedOdometer < 0) {
                    flash_set('job_error', 'Odometer reading is required and must be a valid non-negative number.', 'danger');
                    redirect('modules/jobs/index.php?edit_id=' . $jobId);
                }
                $odometerKm = (int) $parsedOdometer;
            } else {
                $postedValue = filter_var($odometerRaw, FILTER_VALIDATE_INT);
                $currentOdometer = (int) ($job['odometer_km'] ?? 0);
                if ($odometerRaw !== '' && $postedValue !== false && (int) $postedValue !== $currentOdometer) {
                    flash_set('job_error', 'Odometer can only be edited while job status is OPEN or IN_PROGRESS.', 'danger');
                    redirect('modules/jobs/index.php?edit_id=' . $jobId);
                }
            }
        }

        $updateSql =
            'UPDATE job_cards
             SET complaint = :complaint,
                 diagnosis = :diagnosis,
                 priority = :priority,
                 promised_at = :promised_at,
                 updated_by = :updated_by';
        $updateParams = [
            'complaint' => $complaint,
            'diagnosis' => $diagnosis !== '' ? $diagnosis : null,
            'priority' => $priority,
            'promised_at' => $promisedAt !== '' ? str_replace('T', ' ', $promisedAt) : null,
            'updated_by' => $userId,
            'id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ];
        if ($jobRecommendationNoteEnabled) {
            $updateSql .= ',
                 recommendation_note = :recommendation_note';
            $updateParams['recommendation_note'] = $recommendationNote !== '' ? $recommendationNote : null;
        }
        if ($jobOdometerEnabled && $odometerEditable && $odometerKm !== null) {
            $updateSql .= ',
                 odometer_km = :odometer_km';
            $updateParams['odometer_km'] = $odometerKm;
        }
        $updateSql .= '
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id';

        $stmt = db()->prepare($updateSql);
        $stmt->execute($updateParams);

        if ($canAssign) {
            $assigned = job_sync_assignments($jobId, $companyId, $garageId, $assignedUserIds, $userId);
            job_append_history($jobId, 'ASSIGN_UPDATE', null, null, 'Updated assignments', ['user_ids' => $assigned]);
        }
        $historyPayload = null;
        if ($jobOdometerEnabled && $odometerEditable && $odometerKm !== null) {
            $previousOdometer = (int) ($job['odometer_km'] ?? 0);
            if ($previousOdometer !== $odometerKm) {
                $historyPayload = [
                    'odometer_km' => [
                        'from' => $previousOdometer,
                        'to' => $odometerKm,
                    ],
                ];
            }
        }
        job_append_history($jobId, 'UPDATE_META', (string) $job['status'], (string) $job['status'], 'Job metadata updated', $historyPayload);
        log_audit('job_cards', 'update', $jobId, 'Updated job metadata', [
            'entity' => 'job_card',
            'source' => 'UI',
            'before' => [
                'complaint' => (string) ($job['complaint'] ?? ''),
                'diagnosis' => (string) ($job['diagnosis'] ?? ''),
                'priority' => (string) ($job['priority'] ?? ''),
                'promised_at' => (string) ($job['promised_at'] ?? ''),
                'odometer_km' => $jobOdometerEnabled ? (int) ($job['odometer_km'] ?? 0) : null,
                'recommendation_note' => $jobRecommendationNoteEnabled ? (string) ($job['recommendation_note'] ?? '') : null,
            ],
            'after' => [
                'complaint' => $complaint,
                'diagnosis' => $diagnosis,
                'priority' => $priority,
                'promised_at' => $promisedAt !== '' ? str_replace('T', ' ', $promisedAt) : null,
                'odometer_km' => $jobOdometerEnabled
                    ? ($odometerEditable && $odometerKm !== null ? $odometerKm : (int) ($job['odometer_km'] ?? 0))
                    : null,
                'recommendation_note' => $jobRecommendationNoteEnabled ? ($recommendationNote !== '' ? $recommendationNote : null) : null,
            ],
            'metadata' => [
                'assigned_count' => isset($assigned) && is_array($assigned) ? count($assigned) : 0,
                'odometer_editable' => $odometerEditable,
            ],
        ]);
        flash_set('job_success', 'Job card updated.', 'success');
        redirect('modules/jobs/index.php');
    }
}

$customers = db()->prepare('SELECT id, full_name, phone FROM customers WHERE company_id = :company_id AND status_code = "ACTIVE" ORDER BY full_name ASC');
$customers->execute(['company_id' => $companyId]);
$customers = $customers->fetchAll();

if ($jobOdometerEnabled) {
    $vehicles = db()->prepare(
        'SELECT v.id, v.customer_id, v.registration_no, v.brand, v.model, v.odometer_km,
                (
                    SELECT jc.odometer_km
                    FROM job_cards jc
                    WHERE jc.company_id = :company_id
                      AND jc.vehicle_id = v.id
                      AND jc.status_code <> "DELETED"
                    ORDER BY COALESCE(jc.opened_at, jc.created_at, jc.updated_at) DESC, jc.id DESC
                    LIMIT 1
                ) AS last_job_odometer_km,
                (
                    SELECT jc.job_number
                    FROM job_cards jc
                    WHERE jc.company_id = :company_id
                      AND jc.vehicle_id = v.id
                      AND jc.status_code <> "DELETED"
                    ORDER BY COALESCE(jc.opened_at, jc.created_at, jc.updated_at) DESC, jc.id DESC
                    LIMIT 1
                ) AS last_odometer_job_number
         FROM vehicles v
         WHERE v.company_id = :company_id
           AND v.status_code = "ACTIVE"
         ORDER BY v.registration_no ASC'
    );
} else {
    $vehicles = db()->prepare(
        'SELECT id, customer_id, registration_no, brand, model, odometer_km,
                NULL AS last_job_odometer_km,
                NULL AS last_odometer_job_number
         FROM vehicles
         WHERE company_id = :company_id
           AND status_code = "ACTIVE"
         ORDER BY registration_no ASC'
    );
}
$vehicles->execute(['company_id' => $companyId]);
$vehicles = $vehicles->fetchAll();
$vehicleAttributesEnabled = vehicle_masters_enabled() && vehicle_master_link_columns_supported();
$vehicleAttributesApiUrl = url('modules/vehicles/attributes_api.php');

$staffCandidates = job_assignment_candidates($companyId, $garageId);

$editId = get_int('edit_id');
$editJob = $editId > 0 && $canEdit ? job_row($editId, $companyId, $garageId) : null;
$editAssignments = [];
if ($editJob) {
    $editAssignments = array_map(static fn (array $row): int => (int) $row['user_id'], job_current_assignments((int) $editJob['id']));
}
$editJobOdometerEditable = $editJob !== null
    && $jobOdometerEnabled
    && in_array(job_normalize_status((string) ($editJob['status'] ?? 'OPEN')), $odometerEditableStatuses, true);

$statusFilter = strtoupper(trim((string) ($_GET['status'] ?? '')));
$query = trim((string) ($_GET['q'] ?? ''));
$allowedJobStatuses = job_workflow_statuses(true);
if (!in_array($statusFilter, $allowedJobStatuses, true)) {
    $statusFilter = '';
}

$baseWhere = ['jc.company_id = :company_id', 'jc.garage_id = :garage_id', 'jc.status_code <> "DELETED"'];
$baseParams = ['company_id' => $companyId, 'garage_id' => $garageId];
if (in_array($statusFilter, $allowedJobStatuses, true)) {
    $where = array_merge($baseWhere, ['jc.status = :status']);
    $params = array_merge($baseParams, ['status' => $statusFilter]);
} else {
    $where = $baseWhere;
    $params = $baseParams;
}
if ($query !== '') {
    $baseWhere[] = '(jc.job_number LIKE :q OR c.full_name LIKE :q OR v.registration_no LIKE :q OR jc.complaint LIKE :q)';
    $baseParams['q'] = '%' . $query . '%';
    $where[] = '(jc.job_number LIKE :q OR c.full_name LIKE :q OR v.registration_no LIKE :q OR jc.complaint LIKE :q)';
    $params['q'] = '%' . $query . '%';
}

$sql =
    'SELECT jc.id, jc.job_number, jc.status, jc.status_code, jc.priority, jc.opened_at, jc.estimated_cost,
            c.full_name AS customer_name, v.registration_no, v.model AS vehicle_model,
            GROUP_CONCAT(DISTINCT au.name ORDER BY ja.is_primary DESC, au.name SEPARATOR ", ") AS assigned_staff
     FROM job_cards jc
     INNER JOIN customers c ON c.id = jc.customer_id
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     LEFT JOIN job_assignments ja ON ja.job_card_id = jc.id AND ja.status_code = "ACTIVE"
     LEFT JOIN users au ON au.id = ja.user_id
     WHERE ' . implode(' AND ', $where) . '
     GROUP BY jc.id
     ORDER BY jc.id DESC';
$jobs = db()->prepare($sql);
$jobs->execute($params);
$jobs = $jobs->fetchAll();
$conditionPhotoCounts = job_condition_photo_counts_by_job(
    $companyId,
    $garageId,
    array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $jobs)
);
foreach ($jobs as &$job) {
    $jobId = (int) ($job['id'] ?? 0);
    $job['condition_photo_count'] = (int) ($conditionPhotoCounts[$jobId] ?? 0);
}
unset($job);
$conditionPhotoFeatureReady = job_condition_photo_feature_ready();
$conditionPhotoRetentionDays = job_condition_photo_retention_days($companyId, $garageId);
$conditionPhotoStats = job_condition_photo_scope_stats($companyId, $garageId);

$jobStatusCounts = ['' => 0];
foreach ($allowedJobStatuses as $status) {
    $jobStatusCounts[$status] = 0;
}

$summarySql =
    'SELECT jc.status, COUNT(*) AS total
     FROM job_cards jc
     INNER JOIN customers c ON c.id = jc.customer_id
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     WHERE ' . implode(' AND ', $baseWhere) . '
     GROUP BY jc.status';
$summaryStmt = db()->prepare($summarySql);
$summaryStmt->execute($baseParams);
foreach ($summaryStmt->fetchAll() as $row) {
    $statusKey = strtoupper(trim((string) ($row['status'] ?? '')));
    $total = (int) ($row['total'] ?? 0);
    $jobStatusCounts[''] += $total;
    if (isset($jobStatusCounts[$statusKey])) {
        $jobStatusCounts[$statusKey] = $total;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header"><div class="container-fluid"><div class="row"><div class="col-sm-6"><h3 class="mb-0">Job Cards / Work Orders</h3></div><div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li><li class="breadcrumb-item active">Job Cards</li></ol></div></div></div></div>
  <div class="app-content"><div class="container-fluid">
    <?php if ($canCreate || ($canEdit && $editJob)): ?>
    <div class="card card-primary">
      <div class="card-header"><h3 class="card-title"><?= $editJob ? 'Edit Job Card' : 'Create Job Card'; ?></h3></div>
      <form method="post"><div class="card-body row g-3">
        <?= csrf_field(); ?><input type="hidden" name="_action" value="<?= $editJob ? 'update' : 'create'; ?>"><input type="hidden" name="job_id" value="<?= (int) ($editJob['id'] ?? 0); ?>">
        <?php if (!$jobOdometerEnabled): ?>
          <div class="col-12">
            <div class="alert alert-warning mb-0">
              Job-card odometer tracking is disabled in this database. Run <code>database/odometer_flow_upgrade.sql</code> to enable mandatory per-job odometer flow.
            </div>
          </div>
        <?php endif; ?>
        <div class="col-md-3">
          <label class="form-label">Customer</label>
          <select id="job-customer-select" name="customer_id" class="form-select" required <?= $editJob ? 'disabled' : ''; ?>>
            <option value="">Select Customer</option>
            <?php foreach ($customers as $customer): ?>
              <option value="<?= (int) $customer['id']; ?>" <?= ((int) ($editJob['customer_id'] ?? 0) === (int) $customer['id']) ? 'selected' : ''; ?>>
                <?= e((string) $customer['full_name']); ?> (<?= e((string) $customer['phone']); ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <div id="job-owner-lock-hint" class="form-hint text-muted mt-1"></div>
          <?php if ($editJob): ?><input type="hidden" name="customer_id" value="<?= (int) $editJob['customer_id']; ?>"><?php endif; ?>
        </div>

        <?php if (!$editJob && $vehicleAttributesEnabled): ?>
          <div class="col-md-6" data-vehicle-attributes-root="1" data-vehicle-attributes-mode="filter" data-vehicle-attributes-endpoint="<?= e($vehicleAttributesApiUrl); ?>" data-vehicle-picker-target="#job-vehicle-select" data-vehicle-customer-select="#job-customer-select">
            <label class="form-label">Vehicle Filters</label>
            <div class="row g-2">
              <div class="col-md-12">
                <select name="job_vehicle_combo_selector" data-vehicle-attr="combo" class="form-select">
                  <option value="">All Brand / Model / Variant</option>
                </select>
                <input type="hidden" name="job_vehicle_brand_id" data-vehicle-attr-id="brand" value="" />
                <input type="hidden" name="job_vehicle_model_id" data-vehicle-attr-id="model" value="" />
                <input type="hidden" name="job_vehicle_variant_id" data-vehicle-attr-id="variant" value="" />
              </div>
              <div class="col-md-6">
                <select name="job_vehicle_model_year_id" data-vehicle-attr="model_year" class="form-select">
                  <option value="">All Years</option>
                </select>
              </div>
              <div class="col-md-6">
                <select name="job_vehicle_color_id" data-vehicle-attr="color" class="form-select">
                  <option value="">All Colors</option>
                </select>
              </div>
            </div>
            <div class="form-hint">Search and filter vehicle dropdown using one Brand / Model / Variant selector.</div>
          </div>
        <?php endif; ?>

        <div class="col-md-3">
          <label class="form-label">Vehicle</label>
          <select id="job-vehicle-select" name="vehicle_id" class="form-select" required <?= $editJob ? 'disabled' : ''; ?> <?= (!$editJob && $canInlineVehicleCreate) ? 'data-inline-vehicle="1"' : ''; ?>>
            <option value="">Select Vehicle</option>
            <?php foreach ($vehicles as $vehicle): ?>
              <?php
                $lastJobOdometer = array_key_exists('last_job_odometer_km', $vehicle) && $vehicle['last_job_odometer_km'] !== null
                    ? (int) $vehicle['last_job_odometer_km']
                    : null;
                $legacyVehicleOdometer = (int) ($vehicle['odometer_km'] ?? 0);
                $suggestedOdometer = $lastJobOdometer;
                $suggestedSource = '';
                if ($lastJobOdometer !== null) {
                    $jobNumber = trim((string) ($vehicle['last_odometer_job_number'] ?? ''));
                    $suggestedSource = $jobNumber !== '' ? ('Last Job Card ' . $jobNumber) : 'Last Job Card';
                } elseif ($legacyVehicleOdometer > 0) {
                    $suggestedOdometer = $legacyVehicleOdometer;
                    $suggestedSource = 'Legacy Vehicle Master';
                }
              ?>
              <option value="<?= (int) $vehicle['id']; ?>" data-customer-id="<?= (int) $vehicle['customer_id']; ?>" data-last-odometer="<?= e($suggestedOdometer !== null ? (string) $suggestedOdometer : ''); ?>" data-last-odometer-source="<?= e($suggestedSource); ?>" <?= ((int) ($editJob['vehicle_id'] ?? 0) === (int) $vehicle['id']) ? 'selected' : ''; ?>>
                <?= e((string) $vehicle['registration_no']); ?> - <?= e((string) $vehicle['brand']); ?> <?= e((string) $vehicle['model']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($editJob): ?><input type="hidden" name="vehicle_id" value="<?= (int) $editJob['vehicle_id']; ?>"><?php endif; ?>
        </div>
        <div class="col-md-2">
          <label class="form-label">Odometer (KM)</label>
          <input id="job-odometer-input" type="number" name="odometer_km" class="form-control" min="0"
                 value="<?= e($jobOdometerEnabled && $editJob ? (string) ((int) ($editJob['odometer_km'] ?? 0)) : ''); ?>"
                 <?= !$jobOdometerEnabled || ($editJob && !$editJobOdometerEditable) ? 'disabled' : ''; ?>
                 <?= $jobOdometerEnabled && (!$editJob || $editJobOdometerEditable) ? 'required' : ''; ?>>
          <div id="job-odometer-hint" class="form-hint text-muted mt-1">
            <?php if (!$jobOdometerEnabled): ?>
              Odometer tracking inactive until DB upgrade is applied.
            <?php elseif ($editJob && !$editJobOdometerEditable): ?>
              Odometer can be edited only in OPEN or IN_PROGRESS status.
            <?php else: ?>
              Select vehicle to auto-fill the last recorded odometer.
            <?php endif; ?>
          </div>
        </div>
        <div class="col-md-2"><label class="form-label">Priority</label><select name="priority" class="form-select"><?php $priority = (string) ($editJob['priority'] ?? 'MEDIUM'); ?><option value="LOW" <?= $priority === 'LOW' ? 'selected' : ''; ?>>Low</option><option value="MEDIUM" <?= $priority === 'MEDIUM' ? 'selected' : ''; ?>>Medium</option><option value="HIGH" <?= $priority === 'HIGH' ? 'selected' : ''; ?>>High</option><option value="URGENT" <?= $priority === 'URGENT' ? 'selected' : ''; ?>>Urgent</option></select></div>
        <div class="col-md-2"><label class="form-label">Promised</label><input type="datetime-local" name="promised_at" class="form-control" value="<?= e((string) (!empty($editJob['promised_at']) ? str_replace(' ', 'T', substr((string) $editJob['promised_at'], 0, 16)) : '')); ?>"></div>
        <div class="col-md-12"><label class="form-label">Complaint</label><textarea name="complaint" class="form-control" rows="2" required><?= e((string) ($editJob['complaint'] ?? '')); ?></textarea></div>
        <div class="col-md-12"><label class="form-label">Diagnosis</label><textarea name="diagnosis" class="form-control" rows="2"><?= e((string) ($editJob['diagnosis'] ?? '')); ?></textarea></div>
        <?php if ($jobRecommendationNoteEnabled): ?>
          <div class="col-md-12">
            <label class="form-label">Recommendation Note</label>
            <textarea name="recommendation_note" class="form-control" rows="2" maxlength="5000"><?= e((string) ($editJob['recommendation_note'] ?? '')); ?></textarea>
            <div class="form-hint">Printed on job card and invoice.</div>
          </div>
        <?php endif; ?>
        <?php if ($canAssign): ?><div class="col-md-12"><label class="form-label">Assigned Staff (Multiple)</label><select name="assigned_user_ids[]" class="form-select" multiple size="4"><?php foreach ($staffCandidates as $staff): ?><option value="<?= (int) $staff['id']; ?>" <?= in_array((int) $staff['id'], $editAssignments, true) ? 'selected' : ''; ?>><?= e((string) $staff['name']); ?> - <?= e((string) $staff['role_name']); ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <?php if (!$editJob): ?>
          <div class="col-md-12">
            <div class="card card-outline card-success mb-0">
              <div class="card-header">
                <h3 class="card-title mb-0">Recommended Services/Parts Due</h3>
              </div>
              <div class="card-body" id="maintenance-recommendations-content">
                <?php if (!$maintenanceReminderFeatureReady): ?>
                  <div class="alert alert-warning mb-0">Maintenance reminder storage is not ready. Recommended items are unavailable.</div>
                <?php else: ?>
                  <div class="text-muted">Select vehicle to load due recommendations. Choose Add, Ignore, or Postpone for each row.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div><div class="card-footer d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $editJob ? 'Update' : 'Create'; ?></button><?php if ($editJob): ?><a class="btn btn-outline-secondary" href="<?= e(url('modules/jobs/index.php')); ?>">Cancel</a><?php endif; ?></div></form>
    </div>
    <div class="card card-outline card-info mb-3 collapsed-card"><div class="card-header"><h3 class="card-title">VIS Suggestions (Optional)</h3><div class="card-tools"><button type="button" class="btn btn-tool" data-lte-toggle="card-collapse"><i class="bi bi-plus-lg"></i></button></div></div><div class="card-body" id="vis-suggestions-content">Select a vehicle to load optional VIS suggestions. Job creation never depends on VIS.</div></div>
    <?php endif; ?>

    <?php if ($canConditionPhotoManage): ?>
      <div class="card card-outline card-secondary mb-3">
        <div class="card-header"><h3 class="card-title">Vehicle Condition Photos - Retention</h3></div>
        <div class="card-body">
          <?php if (!$conditionPhotoFeatureReady): ?>
            <div class="alert alert-warning mb-0">
              Condition photo storage is not ready. Run <code>database/job_condition_photos_upgrade.sql</code> (or ensure DB user has create-table permission).
            </div>
          <?php else: ?>
            <div class="row g-3">
              <div class="col-md-4">
                <div class="small-box text-bg-light border mb-0">
                  <div class="inner">
                    <h4><?= number_format((int) ($conditionPhotoStats['photo_count'] ?? 0)); ?></h4>
                    <p>Active Photos (This Garage)</p>
                  </div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="small-box text-bg-light border mb-0">
                  <div class="inner">
                    <h4><?= number_format(((int) ($conditionPhotoStats['storage_bytes'] ?? 0)) / 1048576, 2); ?> MB</h4>
                    <p>Storage Used (Approx)</p>
                  </div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="small-box text-bg-light border mb-0">
                  <div class="inner">
                    <h4><?= (int) $conditionPhotoRetentionDays; ?> days</h4>
                    <p>Current Retention Policy</p>
                  </div>
                </div>
              </div>
            </div>

            <div class="row g-3 mt-1">
              <div class="col-md-7">
                <form method="post" class="row g-2 align-items-end">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="update_condition_photo_settings">
                  <div class="col-md-6">
                    <label class="form-label">Retention Days</label>
                    <input type="number" name="condition_photo_retention_days" class="form-control" min="1" max="3650" value="<?= (int) $conditionPhotoRetentionDays; ?>" required>
                    <small class="text-muted">Photos older than this can be cleaned in bulk.</small>
                  </div>
                  <div class="col-md-6">
                    <button type="submit" class="btn btn-outline-primary">Save Retention</button>
                  </div>
                </form>
              </div>
              <div class="col-md-5">
                <form method="post" data-confirm="Delete all condition photos older than configured retention days? Files will be removed from server.">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="purge_condition_photos">
                  <input type="hidden" name="condition_photo_retention_days" value="<?= (int) $conditionPhotoRetentionDays; ?>">
                  <button type="submit" class="btn btn-outline-danger w-100">Bulk Delete Old Photos Now</button>
                </form>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card" id="job-list-section">
      <div class="card-header"><h3 class="card-title">Job List</h3><div class="card-tools"><form method="get" action="<?= e(url('modules/jobs/index.php#job-list-section')); ?>" class="d-flex gap-2"><input name="q" value="<?= e($query); ?>" class="form-control form-control-sm" placeholder="Search"><select name="status" class="form-select form-select-sm"><option value="">All</option><?php foreach (job_workflow_statuses(true) as $status): ?><option value="<?= e($status); ?>" <?= $statusFilter === $status ? 'selected' : ''; ?>><?= e($status); ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline-primary" type="submit">Filter</button></form></div></div>
      <div class="card-body border-bottom py-2">
        <ul class="nav nav-pills gap-2 flex-wrap">
          <li class="nav-item">
            <a class="nav-link py-1 px-2 <?= $statusFilter === '' ? 'active' : ''; ?>" href="<?= e(job_filter_url($query, '')); ?>">
              All
              <span class="badge <?= $statusFilter === '' ? 'text-bg-light' : 'text-bg-secondary'; ?> ms-1"><?= (int) ($jobStatusCounts[''] ?? 0); ?></span>
            </a>
          </li>
          <?php foreach ($allowedJobStatuses as $status): ?>
            <li class="nav-item">
              <a class="nav-link py-1 px-2 <?= $statusFilter === $status ? 'active' : ''; ?>" href="<?= e(job_filter_url($query, $status)); ?>">
                <?= e(str_replace('_', ' ', $status)); ?>
                <span class="badge <?= $statusFilter === $status ? 'text-bg-light' : 'text-bg-secondary'; ?> ms-1"><?= (int) ($jobStatusCounts[$status] ?? 0); ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="card-body table-responsive p-0"><table class="table table-striped mb-0"><thead><tr><th>Job</th><th>Customer</th><th>Vehicle</th><th>Model</th><th>Assigned</th><th>Priority</th><th>Status</th><th>Estimate</th><th>Opened</th><th>Photos</th><th></th></tr></thead><tbody>
        <?php if (empty($jobs)): ?><tr><td colspan="11" class="text-center text-muted py-4">No job cards found.</td></tr><?php else: foreach ($jobs as $job): ?>
        <tr>
          <td><?= e((string) $job['job_number']); ?></td><td><?= e((string) $job['customer_name']); ?></td><td><?= e((string) $job['registration_no']); ?></td>
          <td><?= e((string) ($job['vehicle_model'] ?? '-')); ?></td>
          <td><?= e((string) (($job['assigned_staff'] ?? '') !== '' ? $job['assigned_staff'] : 'Unassigned')); ?></td>
          <td><span class="badge text-bg-warning"><?= e((string) $job['priority']); ?></span></td>
          <td><span class="badge text-bg-secondary"><?= e((string) $job['status']); ?></span></td>
          <td><?= e(format_currency((float) $job['estimated_cost'])); ?></td><td><?= e((string) $job['opened_at']); ?></td>
          <td>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('modules/jobs/view.php?id=' . (int) $job['id'] . '#condition-photos')); ?>">
              Photos <span class="badge text-bg-light border ms-1"><?= (int) ($job['condition_photo_count'] ?? 0); ?></span>
            </a>
          </td>
          <td><a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/jobs/view.php?id=' . (int) $job['id'])); ?>">Open</a></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody></table></div>
    </div>
  </div></div>
</main>
<?php if ($canCreate || ($canEdit && $editJob)): ?>
<script>
  (function () {
    var vehicleSelect = document.getElementById('job-vehicle-select');
    var customerSelect = document.getElementById('job-customer-select');
    var ownerHint = document.getElementById('job-owner-lock-hint');
    var odometerInput = document.getElementById('job-odometer-input');
    var odometerHint = document.getElementById('job-odometer-hint');
    var target = document.getElementById('vis-suggestions-content');
    var maintenanceTarget = document.getElementById('maintenance-recommendations-content');
    var maintenanceEnabled = <?= (!$editJob && $maintenanceReminderFeatureReady) ? 'true' : 'false'; ?>;
    var maintenanceApiUrl = '<?= e($maintenanceRecommendationApiUrl); ?>';
    var isEditMode = <?= $editJob ? 'true' : 'false'; ?>;
    var odometerEnabled = <?= $jobOdometerEnabled ? 'true' : 'false'; ?>;
    if (!vehicleSelect || !target) return;

    function selectedVehicleOption() {
      if (!vehicleSelect) {
        return null;
      }
      if (vehicleSelect.selectedIndex < 0) {
        return null;
      }
      return vehicleSelect.options[vehicleSelect.selectedIndex] || null;
    }

    function selectedVehicleCustomerId() {
      var selected = selectedVehicleOption();
      if (!selected) {
        return '';
      }
      return (selected.getAttribute('data-customer-id') || '').trim();
    }

    function selectedVehicleOdometerMeta() {
      var selected = selectedVehicleOption();
      if (!selected) {
        return { value: '', source: '' };
      }
      return {
        value: (selected.getAttribute('data-last-odometer') || '').trim(),
        source: (selected.getAttribute('data-last-odometer-source') || '').trim()
      };
    }

    function formatKm(value) {
      var parsed = Number(value);
      if (!Number.isFinite(parsed)) {
        return value;
      }
      return parsed.toLocaleString();
    }

    function escapeHtml(value) {
      return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function renderOwnerHint(message) {
      if (!ownerHint) {
        return;
      }
      ownerHint.textContent = message || '';
    }

    function renderOdometerHint(message) {
      if (!odometerHint) {
        return;
      }
      odometerHint.textContent = message || '';
    }

    function syncOwnerFromVehicle() {
      if (!customerSelect || customerSelect.disabled || vehicleSelect.disabled) {
        return;
      }

      var ownerCustomerId = selectedVehicleCustomerId();
      if (ownerCustomerId === '') {
        renderOwnerHint('');
        return;
      }

      if ((customerSelect.value || '') !== ownerCustomerId) {
        customerSelect.value = ownerCustomerId;
        if (typeof gacRefreshSearchableSelect === 'function') {
          gacRefreshSearchableSelect(customerSelect);
        }
        customerSelect.dispatchEvent(new Event('change', { bubbles: true }));
      }
      renderOwnerHint('Owner auto-filled from selected vehicle to prevent mismatches.');
    }

    function syncOdometerFromVehicle(forceOverwrite) {
      if (!odometerEnabled || !odometerInput || odometerInput.disabled) {
        return;
      }
      if (vehicleSelect.disabled) {
        return;
      }

      var selectedVehicleId = (vehicleSelect.value || '').trim();
      if (selectedVehicleId === '') {
        if (forceOverwrite) {
          odometerInput.value = '';
        }
        renderOdometerHint('Select vehicle to auto-fill the last recorded odometer.');
        return;
      }

      var odometerMeta = selectedVehicleOdometerMeta();
      if (odometerMeta.value === '') {
        if (forceOverwrite) {
          odometerInput.value = '';
        }
        renderOdometerHint('No previous odometer found for this vehicle. Enter current reading.');
        return;
      }

      if (forceOverwrite || (odometerInput.value || '').trim() === '') {
        odometerInput.value = odometerMeta.value;
      }

      var sourceText = odometerMeta.source !== '' ? ' (' + odometerMeta.source + ')' : '';
      renderOdometerHint('Last recorded odometer: ' + formatKm(odometerMeta.value) + ' KM' + sourceText + '.');
    }

    function customerVehicleOptions(customerId) {
      var normalizedCustomerId = String(customerId || '').trim();
      var matches = [];
      if (normalizedCustomerId === '') {
        return matches;
      }

      for (var i = 0; i < vehicleSelect.options.length; i++) {
        var option = vehicleSelect.options[i];
        if (!option || option.value === '') {
          continue;
        }

        var optionCustomerId = (option.getAttribute('data-customer-id') || '').trim();
        if (optionCustomerId === normalizedCustomerId) {
          matches.push(option);
        }
      }

      return matches;
    }

    function clearVehicleSelection() {
      if ((vehicleSelect.value || '').trim() === '') {
        return;
      }

      vehicleSelect.value = '';
      if (typeof gacRefreshSearchableSelect === 'function') {
        gacRefreshSearchableSelect(vehicleSelect);
      }
      syncOdometerFromVehicle(true);
      load('');
      loadMaintenanceRecommendations('');
    }

    function enforceVehicleOwnerMatch() {
      if (!customerSelect || customerSelect.disabled || vehicleSelect.disabled) {
        return;
      }

      var selectedCustomerId = (customerSelect.value || '').trim();
      var selectedVehicleId = (vehicleSelect.value || '').trim();
      var matchingVehicles = customerVehicleOptions(selectedCustomerId);

      if (selectedCustomerId === '') {
        if (selectedVehicleId !== '') {
          clearVehicleSelection();
        }
        renderOwnerHint('');
        return;
      }

      if (matchingVehicles.length === 1) {
        var onlyVehicle = matchingVehicles[0];
        if (selectedVehicleId !== onlyVehicle.value) {
          vehicleSelect.value = onlyVehicle.value;
          if (typeof gacRefreshSearchableSelect === 'function') {
            gacRefreshSearchableSelect(vehicleSelect);
          }
          renderOwnerHint('Vehicle auto-selected because this customer has only one vehicle.');
          vehicleSelect.dispatchEvent(new Event('change', { bubbles: true }));
          return;
        }

        renderOwnerHint('Vehicle auto-selected because this customer has only one vehicle.');
        return;
      }

      if (matchingVehicles.length === 0) {
        clearVehicleSelection();
        renderOwnerHint('No vehicle found for selected customer. Search vehicle number and choose + Add New Vehicle to add now.');
        return;
      }

      if (selectedVehicleId === '') {
        renderOwnerHint('Multiple vehicles found for selected customer. Please select vehicle manually.');
        return;
      }

      var ownerCustomerId = selectedVehicleCustomerId();
      if (ownerCustomerId !== '' && ownerCustomerId === selectedCustomerId) {
        renderOwnerHint('Multiple vehicles found for selected customer. Please confirm selected vehicle.');
        return;
      }

      clearVehicleSelection();
      renderOwnerHint('Vehicle selection was cleared because it does not belong to the selected customer.');
    }

    function render(data) {
      var services = data.service_suggestions || [], parts = data.part_suggestions || [], variant = data.variant;
      if (!variant && services.length === 0 && parts.length === 0) { target.innerHTML = 'No VIS data for this vehicle. Continue manually.'; return; }
      var html = variant ? '<p><strong>Variant:</strong> ' + variant.brand_name + ' / ' + variant.model_name + ' / ' + variant.variant_name + '</p>' : '';
      html += '<p class="mb-1"><strong>Suggested Services:</strong> ' + services.length + '</p><p class="mb-0"><strong>Compatible Parts:</strong> ' + parts.length + '</p>';
      target.innerHTML = html;
    }

    function renderMaintenanceRecommendations(payload) {
      if (!maintenanceEnabled || !maintenanceTarget) {
        return;
      }

      var rows = payload && Array.isArray(payload.items) ? payload.items : [];
      if (!payload || payload.ok === false) {
        maintenanceTarget.innerHTML = '<div class="text-danger">Unable to load maintenance recommendations.</div>';
        return;
      }
      if (rows.length === 0) {
        maintenanceTarget.innerHTML = '<div class="text-muted">No active maintenance reminders due for this vehicle.</div>';
        return;
      }

      var html = '';
      html += '<div class="table-responsive">';
      html += '<table class="table table-sm table-bordered align-middle mb-0">';
      html += '<thead><tr><th>Service/Part</th><th class="text-end">Due KM</th><th>Due Date</th><th>Predicted Visit</th><th>Status</th><th>Source</th><th>Recommendation</th><th style="width: 170px;">Action</th></tr></thead><tbody>';
      for (var i = 0; i < rows.length; i++) {
        var row = rows[i] || {};
        var reminderId = Number(row.reminder_id || 0);
        if (reminderId <= 0) {
          continue;
        }
        var itemLabel = String(row.item_name || '');
        if (itemLabel === '') {
          itemLabel = String(row.item_type || 'ITEM') + ' #' + String(row.item_id || '');
        }
        var nextDueKm = row.next_due_km !== null && typeof row.next_due_km !== 'undefined'
          ? Number(row.next_due_km).toLocaleString()
          : '-';
        var nextDueDate = row.next_due_date ? String(row.next_due_date) : '-';
        var predictedVisit = row.predicted_next_visit_date ? String(row.predicted_next_visit_date) : '-';
        var dueState = String(row.due_state || 'UPCOMING');
        var source = String(row.source_type || 'AUTO');
        var recommendation = String(row.recommendation_text || '-');
        var defaultAction = String(row.action_default || 'ignore').toLowerCase();
        if (['add', 'ignore', 'postpone'].indexOf(defaultAction) === -1) {
          defaultAction = 'ignore';
        }

        html += '<tr>';
        html += '<td>' + escapeHtml(itemLabel) + '</td>';
        html += '<td class="text-end">' + escapeHtml(nextDueKm) + '</td>';
        html += '<td>' + escapeHtml(nextDueDate) + '</td>';
        html += '<td>' + escapeHtml(predictedVisit) + '</td>';
        html += '<td>' + escapeHtml(dueState) + '</td>';
        html += '<td>' + escapeHtml(source) + '</td>';
        html += '<td>' + escapeHtml(recommendation) + '</td>';
        html += '<td>';
        html += '<select name="maintenance_action[' + reminderId + ']" class="form-select form-select-sm">';
        html += '<option value="add"' + (defaultAction === 'add' ? ' selected' : '') + '>Add To Job</option>';
        html += '<option value="ignore"' + (defaultAction === 'ignore' ? ' selected' : '') + '>Ignore</option>';
        html += '<option value="postpone"' + (defaultAction === 'postpone' ? ' selected' : '') + '>Postpone</option>';
        html += '</select>';
        html += '</td>';
        html += '</tr>';
      }
      html += '</tbody></table></div>';
      maintenanceTarget.innerHTML = html;
    }

    function loadMaintenanceRecommendations(id) {
      if (!maintenanceEnabled || !maintenanceTarget) {
        return;
      }
      if (!id) {
        maintenanceTarget.innerHTML = '<div class="text-muted">Select vehicle to load due recommendations. Choose Add, Ignore, or Postpone for each row.</div>';
        return;
      }

      fetch(maintenanceApiUrl + '?vehicle_id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(renderMaintenanceRecommendations)
        .catch(function () {
          maintenanceTarget.innerHTML = '<div class="text-danger">Unable to load maintenance recommendations.</div>';
        });
    }

    function load(id) {
      if (!id) { target.innerHTML = 'Select a vehicle to load optional VIS suggestions. Job creation never depends on VIS.'; return; }
      fetch('<?= e(url('modules/jobs/vis_suggestions.php')); ?>?vehicle_id=' + encodeURIComponent(id), {credentials: 'same-origin'})
        .then(function (r) { return r.json(); })
        .then(render)
        .catch(function () { target.innerHTML = 'VIS suggestions unavailable. Continue manually.'; });
    }
    vehicleSelect.addEventListener('change', function () {
      syncOwnerFromVehicle();
      syncOdometerFromVehicle(true);
      load(vehicleSelect.value);
      loadMaintenanceRecommendations(vehicleSelect.value);
    });
    if (customerSelect) {
      customerSelect.addEventListener('change', enforceVehicleOwnerMatch);
    }
    if (vehicleSelect.value) {
      syncOwnerFromVehicle();
      syncOdometerFromVehicle(!isEditMode);
      load(vehicleSelect.value);
      loadMaintenanceRecommendations(vehicleSelect.value);
    } else {
      syncOdometerFromVehicle(false);
      loadMaintenanceRecommendations('');
    }
    if (customerSelect) {
      enforceVehicleOwnerMatch();
    }
  })();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
