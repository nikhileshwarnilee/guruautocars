<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('job.view');
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/insurance.php';

$page_title = 'Job Cards / Work Orders';
$active_menu = 'jobs';
$companyId = active_company_id();
$garageId = active_garage_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$canCreate = has_permission('job.create') || has_permission('job.manage');
$canEdit = has_permission('job.edit') || has_permission('job.update') || has_permission('job.manage');
$canAssign = has_permission('job.assign') || has_permission('job.manage');
$canJobTypeSettingsManage = has_permission('settings.manage');
$canJobCardPrintSettingsManage = has_permission('settings.view') && (has_permission('job.manage') || has_permission('settings.manage'));
$canInlineVehicleCreate = has_permission('vehicle.view') && has_permission('vehicle.manage');
$canConditionPhotoManage = has_permission('job.manage') || has_permission('settings.manage');
$canChecklistMasterManage = has_permission('job.manage') || has_permission('settings.manage');
$jobCardColumns = table_columns('job_cards');
$jobOdometerEnabled = in_array('odometer_km', $jobCardColumns, true);
$jobRecommendationNoteEnabled = job_recommendation_note_feature_ready();
$jobInsuranceEnabled = job_insurance_feature_ready();
$jobTypeEnabled = job_type_feature_ready();
$vehicleIntakeFeatureReady = job_vehicle_intake_feature_ready();
$vehicleIntakeChecklistMaster = $vehicleIntakeFeatureReady ? job_vehicle_intake_master_items(true) : [];
if ($vehicleIntakeFeatureReady && $vehicleIntakeChecklistMaster === []) {
    foreach (job_vehicle_intake_default_checklist_items() as $itemName) {
        $vehicleIntakeChecklistMaster[] = [
            'id' => 0,
            'item_name' => $itemName,
            'is_default' => 1,
            'active' => 1,
        ];
    }
}
$odometerEditableStatuses = ['OPEN', 'IN_PROGRESS'];
$maintenanceReminderFeatureReady = service_reminder_feature_ready();
$maintenanceRecommendationApiUrl = url('modules/jobs/maintenance_recommendations_api.php');

$jobTypeCatalogRows = [];
$jobTypeActiveOptions = [];
$jobTypeLabelsById = [];
if ($jobTypeEnabled) {
    foreach (job_type_catalog($companyId) as $jobTypeRow) {
        $sanitized = job_type_sanitize_row((array) $jobTypeRow);
        if ($sanitized === null) {
            continue;
        }

        $jobTypeId = (int) ($sanitized['id'] ?? 0);
        if ($jobTypeId <= 0) {
            continue;
        }

        $statusCode = normalize_status_code((string) ($sanitized['status_code'] ?? 'ACTIVE'));
        if ($statusCode === 'DELETED') {
            continue;
        }

        $jobTypeCatalogRows[$jobTypeId] = [
            'id' => $jobTypeId,
            'name' => trim((string) ($sanitized['name'] ?? '')),
            'status_code' => $statusCode,
        ];
        $jobTypeLabelsById[$jobTypeId] = (string) ($sanitized['name'] ?? ('Job Type #' . $jobTypeId));
        if ($statusCode === 'ACTIVE') {
            $jobTypeActiveOptions[$jobTypeId] = $jobTypeCatalogRows[$jobTypeId];
        }
    }

    uasort($jobTypeCatalogRows, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    uasort($jobTypeActiveOptions, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
}

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

function job_filter_url(string $query, string $status, int $jobTypeId = 0): string
{
    $params = [];
    if ($query !== '') {
        $params['q'] = $query;
    }
    if ($status !== '') {
        $params['status'] = $status;
    }
    if ($jobTypeId > 0) {
        $params['job_type_id'] = $jobTypeId;
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

    if ($action === 'quick_add_job_type') {
        if (!$canJobTypeSettingsManage) {
            flash_set('job_error', 'You do not have permission to add job type options.', 'danger');
            redirect('modules/jobs/index.php?open_job_settings=1');
        }
        if (!$jobTypeEnabled) {
            flash_set('job_error', 'Job type feature is not ready in this database.', 'danger');
            redirect('modules/jobs/index.php');
        }

        $jobTypeName = post_string('job_type_name', 80);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        $allowMultipleActiveJobs = !empty($_POST['allow_multiple_active_jobs']) ? 1 : 0;

        if ($statusCode === 'DELETED') {
            flash_set('job_error', 'Use status Active or Inactive while creating a job type option.', 'danger');
            redirect('modules/jobs/index.php?open_job_settings=1');
        }
        if ($jobTypeName === '') {
            flash_set('job_error', 'Job type name is required.', 'danger');
            redirect('modules/jobs/index.php?open_job_settings=1');
        }

        $jobTypesById = [];
        foreach (job_type_catalog($companyId) as $row) {
            $sanitized = job_type_sanitize_row((array) $row);
            if ($sanitized === null) {
                continue;
            }
            $jobTypesById[(int) $sanitized['id']] = $sanitized;
        }

        $needleName = mb_strtolower(trim($jobTypeName));
        foreach ($jobTypesById as $row) {
            $rowStatus = normalize_status_code((string) ($row['status_code'] ?? 'ACTIVE'));
            if ($rowStatus === 'DELETED') {
                continue;
            }
            if (mb_strtolower(trim((string) ($row['name'] ?? ''))) === $needleName) {
                flash_set('job_error', 'Job type already exists. Use a different name.', 'danger');
                redirect('modules/jobs/index.php?open_job_settings=1');
            }
        }

        $nextId = $jobTypesById !== [] ? (max(array_map('intval', array_keys($jobTypesById))) + 1) : 1;
        $jobTypesById[$nextId] = [
            'id' => $nextId,
            'name' => $jobTypeName,
            'status_code' => $statusCode,
            'allow_multiple_active_jobs' => $allowMultipleActiveJobs,
        ];

        $settingId = job_type_save_catalog($companyId, array_values($jobTypesById), $userId > 0 ? $userId : null);
        if ($settingId <= 0) {
            flash_set('job_error', 'Unable to save job type option right now.', 'danger');
            redirect('modules/jobs/index.php?open_job_settings=1');
        }

        log_audit('system_settings', 'job_type_create', $settingId, 'Created job type ' . $jobTypeName . ' from job card create page', [
            'entity' => 'job_type_option',
            'company_id' => $companyId,
            'source' => 'UI',
            'metadata' => [
                'job_type_id' => $nextId,
                'job_type_name' => $jobTypeName,
                'status_code' => $statusCode,
                'allow_multiple_active_jobs' => $allowMultipleActiveJobs,
                'origin' => 'modules/jobs/index.php',
            ],
        ]);
        flash_set('job_success', 'Job type option added successfully.', 'success');
        redirect('modules/jobs/index.php?open_job_settings=1&prefill_job_type_id=' . $nextId);
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
        $insuranceCompanyName = $jobInsuranceEnabled ? post_string('insurance_company_name', 150) : '';
        $insuranceClaimNumber = $jobInsuranceEnabled ? post_string('insurance_claim_number', 80) : '';
        $insuranceSurveyorName = $jobInsuranceEnabled ? post_string('insurance_surveyor_name', 120) : '';
        $insuranceClaimAmountApproved = $jobInsuranceEnabled ? job_insurance_parse_amount($_POST['insurance_claim_amount_approved'] ?? null) : null;
        $insuranceCustomerPayableAmount = $jobInsuranceEnabled ? job_insurance_parse_amount($_POST['insurance_customer_payable_amount'] ?? null) : null;
        $insuranceClaimStatus = $jobInsuranceEnabled
            ? job_insurance_normalize_status((string) ($_POST['insurance_claim_status'] ?? 'PENDING'))
            : 'PENDING';
        $odometerRaw = trim((string) ($_POST['odometer_km'] ?? ''));
        $odometerKm = null;
        $priority = strtoupper(post_string('priority', 10));
        $jobTypeId = $jobTypeEnabled ? post_int('job_type_id') : 0;
        $promisedAt = post_string('promised_at', 25);
        $assignedUserIds = $canAssign ? parse_ids($_POST['assigned_user_ids'] ?? []) : [];
        $intakeFuelLevel = job_vehicle_intake_normalize_fuel_level((string) ($_POST['intake_fuel_level'] ?? 'LOW'));
        $intakeExteriorNotes = post_string('intake_exterior_condition_notes', 5000);
        $intakeInteriorNotes = post_string('intake_interior_condition_notes', 5000);
        $intakeMechanicalNotes = post_string('intake_mechanical_condition_notes', 5000);
        $intakeRemarks = post_string('intake_remarks', 5000);
        $intakeCustomerAcknowledged = !empty($_POST['intake_customer_acknowledged']);

        $intakeChecklistRows = [];
        $intakeDefaultNames = $_POST['intake_checklist_name'] ?? [];
        $intakeDefaultStatuses = $_POST['intake_checklist_status'] ?? [];
        $intakeDefaultRemarks = $_POST['intake_checklist_remarks'] ?? [];
        if (is_array($intakeDefaultNames)) {
            $defaultCount = count($intakeDefaultNames);
            for ($index = 0; $index < $defaultCount; $index++) {
                $name = mb_substr(trim((string) ($intakeDefaultNames[$index] ?? '')), 0, 120);
                if ($name === '') {
                    continue;
                }
                $intakeChecklistRows[] = [
                    'item_name' => $name,
                    'status' => job_vehicle_intake_normalize_item_status((string) ($intakeDefaultStatuses[$index] ?? 'NOT_PRESENT')),
                    'remarks' => mb_substr(trim((string) ($intakeDefaultRemarks[$index] ?? '')), 0, 255),
                ];
            }
        }

        $intakeCustomNames = $_POST['intake_custom_item_name'] ?? [];
        $intakeCustomStatuses = $_POST['intake_custom_item_status'] ?? [];
        $intakeCustomRemarks = $_POST['intake_custom_item_remarks'] ?? [];
        if (is_array($intakeCustomNames)) {
            $customCount = count($intakeCustomNames);
            for ($index = 0; $index < $customCount; $index++) {
                $name = mb_substr(trim((string) ($intakeCustomNames[$index] ?? '')), 0, 120);
                if ($name === '') {
                    continue;
                }
                $intakeChecklistRows[] = [
                    'item_name' => $name,
                    'status' => job_vehicle_intake_normalize_item_status((string) ($intakeCustomStatuses[$index] ?? 'NOT_PRESENT')),
                    'remarks' => mb_substr(trim((string) ($intakeCustomRemarks[$index] ?? '')), 0, 255),
                ];
            }
        }

        $intakeUploads = job_vehicle_intake_normalize_uploads($_FILES['intake_images'] ?? null);
        $intakeImageTypes = $_POST['intake_image_types'] ?? [];
        foreach ($intakeUploads as $uploadIndex => $uploadFile) {
            if (!is_array($uploadFile)) {
                continue;
            }
            $intakeUploads[$uploadIndex]['image_type'] = is_array($intakeImageTypes)
                ? job_vehicle_intake_normalize_image_type((string) ($intakeImageTypes[$uploadIndex] ?? 'OTHER'))
                : 'OTHER';
        }
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
        if ($jobTypeEnabled) {
            if ($jobTypeId <= 0) {
                flash_set('job_error', 'Job type is required.', 'danger');
                redirect('modules/jobs/index.php');
            }
            if (!isset($jobTypeActiveOptions[$jobTypeId])) {
                flash_set('job_error', 'Selected job type is invalid or inactive.', 'danger');
                redirect('modules/jobs/index.php');
            }
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
        $allowParallelJobsForType = $jobTypeEnabled
            && $jobTypeId > 0
            && job_type_allows_multiple_active_jobs($companyId, $jobTypeId);
        $intakeOdometerReading = $jobOdometerEnabled && $odometerKm !== null
            ? $odometerKm
            : max(0, post_int('intake_odometer_reading'));

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if (!$allowParallelJobsForType) {
                $blockingJob = job_find_active_non_closed_vehicle_job($companyId, $vehicleId, null, $pdo, true);
                if ($blockingJob !== null) {
                    throw new RuntimeException(
                        'Vehicle already has active job card '
                        . (string) ($blockingJob['job_number'] ?? ('#' . (int) ($blockingJob['id'] ?? 0)))
                        . ' with status '
                        . (string) ($blockingJob['status'] ?? 'OPEN')
                        . '. Close that job first before creating a new one.'
                    );
                }
            }

            $jobNumber = job_generate_number($pdo, $garageId);
            $recommendationColumnSql = $jobRecommendationNoteEnabled ? ', recommendation_note' : '';
            $recommendationValueSql = $jobRecommendationNoteEnabled ? ', :recommendation_note' : '';
            $insuranceColumnSql = $jobInsuranceEnabled
                ? ', insurance_company_name, insurance_claim_number, insurance_surveyor_name, insurance_claim_amount_approved, insurance_customer_payable_amount, insurance_claim_status'
                : '';
            $insuranceValueSql = $jobInsuranceEnabled
                ? ', :insurance_company_name, :insurance_claim_number, :insurance_surveyor_name, :insurance_claim_amount_approved, :insurance_customer_payable_amount, :insurance_claim_status'
                : '';
            $jobTypeColumnSql = $jobTypeEnabled ? ', job_type_id' : '';
            $jobTypeValueSql = $jobTypeEnabled ? ', :job_type_id' : '';
            if ($jobOdometerEnabled) {
                $stmt = $pdo->prepare(
                    'INSERT INTO job_cards
                      (company_id, garage_id, job_number, customer_id, vehicle_id, odometer_km, assigned_to, service_advisor_id, complaint, diagnosis' . $recommendationColumnSql . $insuranceColumnSql . ', status, priority' . $jobTypeColumnSql . ', promised_at, status_code, created_by, updated_by)
                     VALUES
                      (:company_id, :garage_id, :job_number, :customer_id, :vehicle_id, :odometer_km, NULL, :service_advisor_id, :complaint, :diagnosis' . $recommendationValueSql . $insuranceValueSql . ', "OPEN", :priority' . $jobTypeValueSql . ', :promised_at, "ACTIVE", :created_by, :updated_by)'
                );
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO job_cards
                      (company_id, garage_id, job_number, customer_id, vehicle_id, assigned_to, service_advisor_id, complaint, diagnosis' . $recommendationColumnSql . $insuranceColumnSql . ', status, priority' . $jobTypeColumnSql . ', promised_at, status_code, created_by, updated_by)
                     VALUES
                      (:company_id, :garage_id, :job_number, :customer_id, :vehicle_id, NULL, :service_advisor_id, :complaint, :diagnosis' . $recommendationValueSql . $insuranceValueSql . ', "OPEN", :priority' . $jobTypeValueSql . ', :promised_at, "ACTIVE", :created_by, :updated_by)'
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
            if ($jobInsuranceEnabled) {
                $insertParams['insurance_company_name'] = $insuranceCompanyName !== '' ? $insuranceCompanyName : null;
                $insertParams['insurance_claim_number'] = $insuranceClaimNumber !== '' ? $insuranceClaimNumber : null;
                $insertParams['insurance_surveyor_name'] = $insuranceSurveyorName !== '' ? $insuranceSurveyorName : null;
                $insertParams['insurance_claim_amount_approved'] = $insuranceClaimAmountApproved;
                $insertParams['insurance_customer_payable_amount'] = $insuranceCustomerPayableAmount;
                $insertParams['insurance_claim_status'] = $insuranceClaimStatus;
            }
            if ($jobOdometerEnabled) {
                $insertParams['odometer_km'] = $odometerKm !== null ? $odometerKm : 0;
            }
            if ($jobTypeEnabled) {
                $insertParams['job_type_id'] = $jobTypeId > 0 ? $jobTypeId : null;
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

            $intakeResult = [
                'intake_id' => 0,
                'checklist_count' => 0,
                'image_count' => 0,
                'fuel_level' => $intakeFuelLevel,
                'odometer_reading' => $intakeOdometerReading,
            ];
            if ($vehicleIntakeFeatureReady) {
                $intakePayload = [
                    'fuel_level' => $intakeFuelLevel,
                    'odometer_reading' => $intakeOdometerReading,
                    'exterior_condition_notes' => $intakeExteriorNotes,
                    'interior_condition_notes' => $intakeInteriorNotes,
                    'mechanical_condition_notes' => $intakeMechanicalNotes,
                    'remarks' => $intakeRemarks,
                    'customer_acknowledged' => $intakeCustomerAcknowledged ? 1 : 0,
                ];
                $intakeResult = job_vehicle_intake_save_for_job(
                    $companyId,
                    $garageId,
                    $jobId,
                    $intakePayload,
                    $intakeChecklistRows,
                    $intakeUploads,
                    $userId
                );
                if ((int) ($intakeResult['intake_id'] ?? 0) > 0) {
                    job_append_history($jobId, 'VEHICLE_INTAKE', null, null, 'Vehicle intake captured', [
                        'intake_id' => (int) ($intakeResult['intake_id'] ?? 0),
                        'checklist_count' => (int) ($intakeResult['checklist_count'] ?? 0),
                        'image_count' => (int) ($intakeResult['image_count'] ?? 0),
                        'fuel_level' => (string) ($intakeResult['fuel_level'] ?? $intakeFuelLevel),
                        'odometer_reading' => (int) ($intakeResult['odometer_reading'] ?? $intakeOdometerReading),
                    ]);
                }
            }

            $createHistoryPayload = ['job_number' => $jobNumber];
            if ($jobOdometerEnabled && $odometerKm !== null) {
                $createHistoryPayload['odometer_km'] = $odometerKm;
            }
            if ($jobTypeEnabled) {
                $createHistoryPayload['job_type_id'] = $jobTypeId > 0 ? $jobTypeId : null;
                $createHistoryPayload['job_type_name'] = $jobTypeId > 0 ? ($jobTypeLabelsById[$jobTypeId] ?? ('Job Type #' . $jobTypeId)) : null;
            }
            $createHistoryPayload['intake_id'] = (int) ($intakeResult['intake_id'] ?? 0);
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
                    'job_type_id' => $jobTypeEnabled ? ($jobTypeId > 0 ? $jobTypeId : null) : null,
                    'customer_id' => $customerId,
                    'vehicle_id' => $vehicleId,
                    'odometer_km' => $jobOdometerEnabled && $odometerKm !== null ? $odometerKm : null,
                    'recommendation_note' => $jobRecommendationNoteEnabled ? ($recommendationNote !== '' ? $recommendationNote : null) : null,
                    'insurance_company_name' => $jobInsuranceEnabled ? ($insuranceCompanyName !== '' ? $insuranceCompanyName : null) : null,
                    'insurance_claim_number' => $jobInsuranceEnabled ? ($insuranceClaimNumber !== '' ? $insuranceClaimNumber : null) : null,
                    'insurance_surveyor_name' => $jobInsuranceEnabled ? ($insuranceSurveyorName !== '' ? $insuranceSurveyorName : null) : null,
                    'insurance_claim_amount_approved' => $jobInsuranceEnabled ? $insuranceClaimAmountApproved : null,
                    'insurance_customer_payable_amount' => $jobInsuranceEnabled ? $insuranceCustomerPayableAmount : null,
                    'insurance_claim_status' => $jobInsuranceEnabled ? $insuranceClaimStatus : null,
                ],
                'metadata' => [
                    'assigned_count' => isset($assigned) && is_array($assigned) ? count($assigned) : 0,
                    'maintenance_added' => (int) ($maintenanceResult['added_count'] ?? 0),
                    'maintenance_postponed' => (int) ($maintenanceResult['postponed_count'] ?? 0),
                    'intake_id' => (int) ($intakeResult['intake_id'] ?? 0),
                    'intake_checklist_count' => (int) ($intakeResult['checklist_count'] ?? 0),
                    'intake_image_count' => (int) ($intakeResult['image_count'] ?? 0),
                ],
            ]);
            $pdo->commit();
            if ((int) ($intakeResult['intake_id'] ?? 0) > 0) {
                flash_set('job_success', 'Job card created. Vehicle intake can be edited later from Job Card view.', 'success');
            } else {
                flash_set('job_success', 'Job card created successfully.', 'success');
            }
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
            redirect('modules/jobs/view.php?id=' . $jobId . '#vehicle-intake');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            $errorMessage = $exception instanceof RuntimeException
                ? trim((string) $exception->getMessage())
                : '';
            if ($errorMessage === '') {
                $errorMessage = 'Unable to create job card. Please retry.';
            }
            flash_set('job_error', $errorMessage, 'danger');
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
        $insuranceCompanyName = $jobInsuranceEnabled ? post_string('insurance_company_name', 150) : '';
        $insuranceClaimNumber = $jobInsuranceEnabled ? post_string('insurance_claim_number', 80) : '';
        $insuranceSurveyorName = $jobInsuranceEnabled ? post_string('insurance_surveyor_name', 120) : '';
        $insuranceClaimAmountApproved = $jobInsuranceEnabled ? job_insurance_parse_amount($_POST['insurance_claim_amount_approved'] ?? null) : null;
        $insuranceCustomerPayableAmount = $jobInsuranceEnabled ? job_insurance_parse_amount($_POST['insurance_customer_payable_amount'] ?? null) : null;
        $insuranceClaimStatus = $jobInsuranceEnabled
            ? job_insurance_normalize_status((string) ($_POST['insurance_claim_status'] ?? 'PENDING'))
            : 'PENDING';
        $odometerRaw = trim((string) ($_POST['odometer_km'] ?? ''));
        $odometerKm = null;
        $priority = strtoupper(post_string('priority', 10));
        $jobTypeId = $jobTypeEnabled ? post_int('job_type_id') : 0;
        $promisedAt = post_string('promised_at', 25);
        $assignedUserIds = $canAssign ? parse_ids($_POST['assigned_user_ids'] ?? []) : [];
        $currentStatus = job_normalize_status((string) ($job['status'] ?? 'OPEN'));
        $odometerEditable = $jobOdometerEnabled && in_array($currentStatus, $odometerEditableStatuses, true);
        if (!in_array($priority, ['LOW', 'MEDIUM', 'HIGH', 'URGENT'], true)) {
            $priority = 'MEDIUM';
        }
        if ($jobTypeEnabled) {
            if ($jobTypeId > 0 && !isset($jobTypeCatalogRows[$jobTypeId])) {
                flash_set('job_error', 'Selected job type is invalid or deleted.', 'danger');
                redirect('modules/jobs/index.php?edit_id=' . $jobId);
            }
            if ($jobTypeId <= 0) {
                $jobTypeId = 0;
            }
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
        if ($jobInsuranceEnabled) {
            $updateSql .= ',
                 insurance_company_name = :insurance_company_name,
                 insurance_claim_number = :insurance_claim_number,
                 insurance_surveyor_name = :insurance_surveyor_name,
                 insurance_claim_amount_approved = :insurance_claim_amount_approved,
                 insurance_customer_payable_amount = :insurance_customer_payable_amount,
                 insurance_claim_status = :insurance_claim_status';
            $updateParams['insurance_company_name'] = $insuranceCompanyName !== '' ? $insuranceCompanyName : null;
            $updateParams['insurance_claim_number'] = $insuranceClaimNumber !== '' ? $insuranceClaimNumber : null;
            $updateParams['insurance_surveyor_name'] = $insuranceSurveyorName !== '' ? $insuranceSurveyorName : null;
            $updateParams['insurance_claim_amount_approved'] = $insuranceClaimAmountApproved;
            $updateParams['insurance_customer_payable_amount'] = $insuranceCustomerPayableAmount;
            $updateParams['insurance_claim_status'] = $insuranceClaimStatus;
        }
        if ($jobOdometerEnabled && $odometerEditable && $odometerKm !== null) {
            $updateSql .= ',
                 odometer_km = :odometer_km';
            $updateParams['odometer_km'] = $odometerKm;
        }
        if ($jobTypeEnabled) {
            $updateSql .= ',
                 job_type_id = :job_type_id';
            $updateParams['job_type_id'] = $jobTypeId > 0 ? $jobTypeId : null;
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
                'job_type_id' => $jobTypeEnabled ? (($job['job_type_id'] ?? null) !== null ? (int) $job['job_type_id'] : null) : null,
                'promised_at' => (string) ($job['promised_at'] ?? ''),
                'odometer_km' => $jobOdometerEnabled ? (int) ($job['odometer_km'] ?? 0) : null,
                'recommendation_note' => $jobRecommendationNoteEnabled ? (string) ($job['recommendation_note'] ?? '') : null,
                'insurance_company_name' => $jobInsuranceEnabled ? (string) ($job['insurance_company_name'] ?? '') : null,
                'insurance_claim_number' => $jobInsuranceEnabled ? (string) ($job['insurance_claim_number'] ?? '') : null,
                'insurance_surveyor_name' => $jobInsuranceEnabled ? (string) ($job['insurance_surveyor_name'] ?? '') : null,
                'insurance_claim_amount_approved' => $jobInsuranceEnabled
                    ? ($job['insurance_claim_amount_approved'] !== null ? (float) $job['insurance_claim_amount_approved'] : null)
                    : null,
                'insurance_customer_payable_amount' => $jobInsuranceEnabled
                    ? ($job['insurance_customer_payable_amount'] !== null ? (float) $job['insurance_customer_payable_amount'] : null)
                    : null,
                'insurance_claim_status' => $jobInsuranceEnabled ? (string) ($job['insurance_claim_status'] ?? '') : null,
            ],
            'after' => [
                'complaint' => $complaint,
                'diagnosis' => $diagnosis,
                'priority' => $priority,
                'job_type_id' => $jobTypeEnabled ? ($jobTypeId > 0 ? $jobTypeId : null) : null,
                'promised_at' => $promisedAt !== '' ? str_replace('T', ' ', $promisedAt) : null,
                'odometer_km' => $jobOdometerEnabled
                    ? ($odometerEditable && $odometerKm !== null ? $odometerKm : (int) ($job['odometer_km'] ?? 0))
                    : null,
                'recommendation_note' => $jobRecommendationNoteEnabled ? ($recommendationNote !== '' ? $recommendationNote : null) : null,
                'insurance_company_name' => $jobInsuranceEnabled ? ($insuranceCompanyName !== '' ? $insuranceCompanyName : null) : null,
                'insurance_claim_number' => $jobInsuranceEnabled ? ($insuranceClaimNumber !== '' ? $insuranceClaimNumber : null) : null,
                'insurance_surveyor_name' => $jobInsuranceEnabled ? ($insuranceSurveyorName !== '' ? $insuranceSurveyorName : null) : null,
                'insurance_claim_amount_approved' => $jobInsuranceEnabled ? $insuranceClaimAmountApproved : null,
                'insurance_customer_payable_amount' => $jobInsuranceEnabled ? $insuranceCustomerPayableAmount : null,
                'insurance_claim_status' => $jobInsuranceEnabled ? $insuranceClaimStatus : null,
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
$editJobTypeId = $jobTypeEnabled ? (int) ($editJob['job_type_id'] ?? 0) : 0;
$jobTypeFormOptions = $jobTypeActiveOptions;
if ($jobTypeEnabled && $editJobTypeId > 0 && isset($jobTypeCatalogRows[$editJobTypeId]) && !isset($jobTypeFormOptions[$editJobTypeId])) {
    $jobTypeFormOptions[$editJobTypeId] = $jobTypeCatalogRows[$editJobTypeId];
    uasort($jobTypeFormOptions, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
}
$openJobSettings = !$editJob && get_int('open_job_settings', 0) === 1;
$prefillJobTypeId = 0;
if (!$editJob && $jobTypeEnabled) {
    $requestedPrefillJobTypeId = get_int('prefill_job_type_id', 0);
    if ($requestedPrefillJobTypeId > 0 && isset($jobTypeFormOptions[$requestedPrefillJobTypeId])) {
        $prefillJobTypeId = $requestedPrefillJobTypeId;
    }
}
$selectedJobTypeForForm = $editJob ? $editJobTypeId : $prefillJobTypeId;

$statusFilterRaw = strtoupper(trim((string) ($_GET['status'] ?? '')));
$statusGroupFilter = strtoupper(trim((string) ($_GET['status_group'] ?? '')));
$statusFilter = $statusFilterRaw;
$query = trim((string) ($_GET['q'] ?? ''));
$jobTypeFilterId = $jobTypeEnabled ? max(0, (int) ($_GET['job_type_id'] ?? 0)) : 0;
$jobTypeFilterLabel = $jobTypeFilterId > 0
    ? ((string) ($jobTypeLabelsById[$jobTypeFilterId] ?? ('Job Type #' . $jobTypeFilterId)))
    : 'All Job Types';
$allowedJobStatuses = job_workflow_statuses(true);
if ($statusFilterRaw === 'COMPLETED_BUCKET') {
    $statusGroupFilter = 'COMPLETED_BUCKET';
    $statusFilter = '';
}
if ($statusGroupFilter !== 'COMPLETED_BUCKET') {
    $statusGroupFilter = '';
}
if (!in_array($statusFilter, $allowedJobStatuses, true)) {
    $statusFilter = '';
}
if ($statusFilter !== '') {
    $statusGroupFilter = '';
}

$baseWhere = ['jc.company_id = :company_id', 'jc.garage_id = :garage_id', 'jc.status_code <> "DELETED"'];
$baseParams = ['company_id' => $companyId, 'garage_id' => $garageId];
if (in_array($statusFilter, $allowedJobStatuses, true)) {
    $where = array_merge($baseWhere, ['jc.status = :status']);
    $params = array_merge($baseParams, ['status' => $statusFilter]);
} elseif ($statusGroupFilter === 'COMPLETED_BUCKET') {
    $where = array_merge($baseWhere, ['jc.status IN ("COMPLETED", "READY_FOR_DELIVERY")']);
    $params = $baseParams;
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
if ($jobTypeEnabled && $jobTypeFilterId > 0) {
    $baseWhere[] = 'jc.job_type_id = :job_type_id';
    $baseParams['job_type_id'] = $jobTypeFilterId;
    $where[] = 'jc.job_type_id = :job_type_id';
    $params['job_type_id'] = $jobTypeFilterId;
}

$sql =
    'SELECT jc.id, jc.job_number, jc.status, jc.status_code, jc.priority, ' . ($jobTypeEnabled ? 'jc.job_type_id' : 'NULL AS job_type_id') . ', jc.opened_at, jc.estimated_cost,
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
    $selectedJobTypeId = (int) ($job['job_type_id'] ?? 0);
    $job['job_type_label'] = $selectedJobTypeId > 0
        ? ((string) ($jobTypeLabelsById[$selectedJobTypeId] ?? ('Job Type #' . $selectedJobTypeId)))
        : 'Not Set';
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
    <?php if ($canJobCardPrintSettingsManage): ?>
      <div class="d-flex justify-content-end mb-2">
        <a href="<?= e(url('modules/system/settings.php?tab=job_card_print')); ?>" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-sliders me-1"></i>Job Card Print Settings
        </a>
      </div>
    <?php endif; ?>
    <?php if ($canCreate || ($canEdit && $editJob)): ?>
    <div class="card card-primary">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0"><?= $editJob ? 'Edit Job Card' : 'Create Job Card'; ?></h3>
        <div class="d-flex gap-2">
          <?php if (!$editJob && $canChecklistMasterManage): ?>
            <a href="<?= e(url('modules/jobs/checklist_master.php')); ?>" class="btn btn-sm btn-outline-light">
              Intake Checklist Master
            </a>
          <?php endif; ?>
          <?php if (!$editJob && $jobTypeEnabled): ?>
            <button
              type="button"
              class="btn btn-sm btn-outline-light"
              data-bs-toggle="collapse"
              data-bs-target="#job-type-settings-panel"
              aria-expanded="<?= $openJobSettings ? 'true' : 'false'; ?>"
              aria-controls="job-type-settings-panel">
              Job Settings
            </button>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!$editJob && $jobTypeEnabled): ?>
        <div class="collapse<?= $openJobSettings ? ' show' : ''; ?>" id="job-type-settings-panel">
          <div class="card-body border-bottom bg-light">
            <h6 class="mb-3">Add Job Type Option</h6>
            <?php if ($canJobTypeSettingsManage): ?>
              <form method="post" class="row g-2 align-items-end">
                <?= csrf_field(); ?>
                <input type="hidden" name="_action" value="quick_add_job_type">
                <div class="col-md-5">
                  <label class="form-label">Job Type Name</label>
                  <input type="text" name="job_type_name" class="form-control" maxlength="80" required placeholder="Example: Periodic Maintenance">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Status</label>
                  <select name="status_code" class="form-select">
                    <option value="ACTIVE">ACTIVE</option>
                    <option value="INACTIVE">INACTIVE</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="allow_multiple_active_jobs" id="quick-job-type-allow-multiple" value="1">
                    <label class="form-check-label" for="quick-job-type-allow-multiple">
                      Allow multiple active jobs for this type
                    </label>
                  </div>
                </div>
                <div class="col-12">
                  <button type="submit" class="btn btn-outline-primary btn-sm">Add Job Type Option</button>
                  <a href="<?= e(url('modules/system/settings.php')); ?>" class="btn btn-outline-secondary btn-sm">Open Full Settings</a>
                </div>
              </form>
            <?php else: ?>
              <div class="alert alert-warning mb-0">
                You do not have permission to add job type options here.
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" id="job-card-form"><div class="card-body row g-3">
        <?= csrf_field(); ?><input type="hidden" name="_action" value="<?= $editJob ? 'update' : 'create'; ?>"><input type="hidden" name="job_id" value="<?= (int) ($editJob['id'] ?? 0); ?>">
        <?php if (!$jobOdometerEnabled): ?>
          <div class="col-12">
            <div class="alert alert-warning mb-0">
              Job-card odometer tracking is disabled in this database. Run <code>database/odometer_flow_upgrade.sql</code> to enable mandatory per-job odometer flow.
            </div>
          </div>
        <?php endif; ?>
        <?php if (!$jobTypeEnabled): ?>
          <div class="col-12">
            <div class="alert alert-warning mb-0">
              Job type field is unavailable because <code>job_cards.job_type_id</code> could not be provisioned.
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
        <?php if ($jobTypeEnabled): ?>
          <div class="col-md-3">
            <label class="form-label">Job Type</label>
            <select name="job_type_id" class="form-select" <?= $editJob ? '' : 'required'; ?>>
              <option value="" <?= $selectedJobTypeForForm <= 0 ? 'selected' : ''; ?>>Select Job Type</option>
              <?php foreach ($jobTypeFormOptions as $jobTypeOption): ?>
                <?php $optionId = (int) ($jobTypeOption['id'] ?? 0); ?>
                <option value="<?= $optionId; ?>" <?= $selectedJobTypeForForm === $optionId ? 'selected' : ''; ?>>
                  <?= e((string) ($jobTypeOption['name'] ?? 'Job Type #' . $optionId)); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (!$editJob && $jobTypeFormOptions === []): ?>
              <div class="form-hint text-danger mt-1">Add at least one active job type from Job Settings or Administration > Settings.</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
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
        <?php if ($jobInsuranceEnabled): ?>
          <div class="col-12"><hr class="my-1"></div>
          <div class="col-12">
            <h6 class="mb-0 text-muted">Insurance Claim Details</h6>
          </div>
          <div class="col-md-4">
            <label class="form-label">Insurance Company</label>
            <input type="text" name="insurance_company_name" class="form-control" maxlength="150" value="<?= e((string) ($editJob['insurance_company_name'] ?? '')); ?>" placeholder="Insurer name">
          </div>
          <div class="col-md-3">
            <label class="form-label">Claim Number</label>
            <input type="text" name="insurance_claim_number" class="form-control" maxlength="80" value="<?= e((string) ($editJob['insurance_claim_number'] ?? '')); ?>" placeholder="Claim no">
          </div>
          <div class="col-md-3">
            <label class="form-label">Surveyor Name</label>
            <input type="text" name="insurance_surveyor_name" class="form-control" maxlength="120" value="<?= e((string) ($editJob['insurance_surveyor_name'] ?? '')); ?>" placeholder="Surveyor">
          </div>
          <div class="col-md-2">
            <label class="form-label">Claim Status</label>
            <select name="insurance_claim_status" class="form-select">
              <?php $insuranceStatus = job_insurance_normalize_status((string) ($editJob['insurance_claim_status'] ?? 'PENDING')); ?>
              <?php foreach (job_insurance_allowed_statuses() as $statusOption): ?>
                <option value="<?= e($statusOption); ?>" <?= $insuranceStatus === $statusOption ? 'selected' : ''; ?>><?= e($statusOption); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Claim Amount Approved</label>
            <input type="number" name="insurance_claim_amount_approved" class="form-control" step="0.01" min="0"
                   value="<?= e(($editJob['insurance_claim_amount_approved'] ?? null) !== null ? number_format((float) $editJob['insurance_claim_amount_approved'], 2, '.', '') : ''); ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Customer Payable Difference</label>
            <input type="number" name="insurance_customer_payable_amount" class="form-control" step="0.01" min="0"
                   value="<?= e(($editJob['insurance_customer_payable_amount'] ?? null) !== null ? number_format((float) $editJob['insurance_customer_payable_amount'], 2, '.', '') : ''); ?>">
          </div>
        <?php endif; ?>
        <?php if ($canAssign): ?><div class="col-md-12"><label class="form-label">Assigned Staff (Multiple)</label><select name="assigned_user_ids[]" class="form-select" multiple size="4"><?php foreach ($staffCandidates as $staff): ?><option value="<?= (int) $staff['id']; ?>" <?= in_array((int) $staff['id'], $editAssignments, true) ? 'selected' : ''; ?>><?= e((string) $staff['name']); ?> - <?= e((string) $staff['role_name']); ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <?php if (!$editJob): ?>
          <div class="col-md-12">
            <div class="card border-0 intake-section-card mb-0">
              <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                  <h6 class="mb-1">Vehicle Intake Inspection</h6>
                  <div class="small text-muted">
                    Capture intake now or fill/update it later from the Job Card view page.
                  </div>
                </div>
                <?php if (!$vehicleIntakeFeatureReady): ?>
                  <span class="badge text-bg-warning">Module Not Ready</span>
                <?php else: ?>
                  <span class="badge text-bg-primary">Ready</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>
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
      </div>
      <div class="card-footer d-flex gap-2">
        <?php if ($editJob): ?>
          <button class="btn btn-primary" type="submit">Update</button>
          <a class="btn btn-outline-secondary" href="<?= e(url('modules/jobs/index.php')); ?>">Cancel</a>
        <?php else: ?>
          <?php if ($vehicleIntakeFeatureReady): ?>
            <button
              class="btn btn-primary"
              type="button"
              id="open-intake-modal-btn"
              data-bs-toggle="modal"
              data-bs-target="#vehicle-intake-modal"
            >
              Create Job Card
            </button>
            <button class="btn btn-primary d-none" type="submit" id="submit-create-job-hidden">Create Job Card</button>
          <?php else: ?>
            <button class="btn btn-primary" type="submit">Create Job Card</button>
            <span class="text-warning small align-self-center">Vehicle intake module is unavailable right now. You can update intake later.</span>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <?php if (!$editJob): ?>
        <div class="modal fade" id="vehicle-intake-modal" tabindex="-1" aria-labelledby="vehicle-intake-modal-label" aria-hidden="true">
          <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="vehicle-intake-modal-label">Vehicle Intake Inspection</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <?php if (!$vehicleIntakeFeatureReady): ?>
                  <div class="alert alert-warning mb-0">
                    Vehicle intake storage is not ready. Contact admin to run upgrade before creating job cards.
                  </div>
                <?php else: ?>
                  <div class="card border-0 intake-section-card mb-3">
                    <div class="card-body">
                      <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                          <label class="form-label">Odometer (KM)</label>
                          <input type="number" class="form-control" id="intake-odometer-input" name="intake_odometer_reading" min="0">
                          <div class="form-hint text-muted mt-1">Auto-linked from Job Card odometer.</div>
                        </div>
                        <div class="col-md-9">
                          <label class="form-label">Fuel Level</label>
                          <div class="btn-group flex-wrap intake-fuel-group" role="group" aria-label="Fuel level selector">
                            <?php foreach (job_vehicle_intake_allowed_fuel_levels() as $fuelLevelOption): ?>
                              <?php $fuelOptionId = 'intake-fuel-' . strtolower($fuelLevelOption); ?>
                              <input
                                class="btn-check intake-fuel-option"
                                type="radio"
                                name="intake_fuel_level"
                                id="<?= e($fuelOptionId); ?>"
                                value="<?= e($fuelLevelOption); ?>"
                                <?= $fuelLevelOption === 'LOW' ? 'checked' : ''; ?>
                              >
                              <label class="btn btn-outline-primary btn-sm" for="<?= e($fuelOptionId); ?>"><?= e(str_replace('_', ' ', $fuelLevelOption)); ?></label>
                            <?php endforeach; ?>
                          </div>
                          <div class="progress mt-2 intake-fuel-progress-wrap">
                            <div id="intake-fuel-progress-bar" class="progress-bar bg-success" role="progressbar" style="width: 15%;" aria-valuemin="0" aria-valuemax="100">LOW</div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="card border-0 intake-section-card mb-3">
                    <div class="card-body">
                      <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">Belongings Checklist</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="add-custom-intake-item-btn">Add Custom Belonging</button>
                      </div>
                      <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                          <thead>
                            <tr>
                              <th>Item</th>
                              <th style="width: 290px;">Status</th>
                              <th>Remarks</th>
                            </tr>
                          </thead>
                          <tbody id="intake-checklist-table-body">
                            <?php $checklistIndex = 0; ?>
                            <?php foreach ($vehicleIntakeChecklistMaster as $masterItem): ?>
                              <?php $itemName = trim((string) ($masterItem['item_name'] ?? '')); ?>
                              <?php if ($itemName === '') { continue; } ?>
                              <?php $statusField = 'intake_checklist_status[' . $checklistIndex . ']'; ?>
                              <?php $presentId = 'intake-checklist-' . $checklistIndex . '-present'; ?>
                              <?php $missingId = 'intake-checklist-' . $checklistIndex . '-missing'; ?>
                              <?php $damagedId = 'intake-checklist-' . $checklistIndex . '-damaged'; ?>
                              <tr>
                                <td>
                                  <input type="hidden" name="intake_checklist_name[]" value="<?= e($itemName); ?>">
                                  <span><?= e($itemName); ?></span>
                                </td>
                                <td>
                                  <div class="btn-group btn-group-sm w-100" role="group">
                                    <input class="btn-check" type="radio" id="<?= e($presentId); ?>" name="<?= e($statusField); ?>" value="PRESENT" checked>
                                    <label class="btn btn-outline-success" for="<?= e($presentId); ?>">Present</label>
                                    <input class="btn-check" type="radio" id="<?= e($missingId); ?>" name="<?= e($statusField); ?>" value="NOT_PRESENT">
                                    <label class="btn btn-outline-secondary" for="<?= e($missingId); ?>">Not Present</label>
                                    <input class="btn-check" type="radio" id="<?= e($damagedId); ?>" name="<?= e($statusField); ?>" value="DAMAGED">
                                    <label class="btn btn-outline-danger" for="<?= e($damagedId); ?>">Damaged</label>
                                  </div>
                                </td>
                                <td><input type="text" name="intake_checklist_remarks[]" class="form-control form-control-sm" maxlength="255" placeholder="Optional"></td>
                              </tr>
                              <?php $checklistIndex++; ?>
                            <?php endforeach; ?>
                          </tbody>
                          <tbody id="intake-custom-items-body"></tbody>
                        </table>
                      </div>
                    </div>
                  </div>

                  <div class="card border-0 intake-section-card mb-3">
                    <div class="card-body">
                      <h6 class="mb-2">Damage and Condition Notes</h6>
                      <div class="row g-2">
                        <div class="col-md-4">
                          <label class="form-label">Exterior</label>
                          <textarea name="intake_exterior_condition_notes" class="form-control" rows="3" maxlength="5000" placeholder="Scratches, dents, panel issues"></textarea>
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">Interior</label>
                          <textarea name="intake_interior_condition_notes" class="form-control" rows="3" maxlength="5000" placeholder="Seats, dashboard, cabin"></textarea>
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">Mechanical</label>
                          <textarea name="intake_mechanical_condition_notes" class="form-control" rows="3" maxlength="5000" placeholder="Leaks, noise, warning lights"></textarea>
                        </div>
                        <div class="col-12">
                          <label class="form-label">General Remarks</label>
                          <textarea name="intake_remarks" class="form-control" rows="2" maxlength="5000" placeholder="Any extra intake remarks"></textarea>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="card border-0 intake-section-card mb-3">
                    <div class="card-body">
                      <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">Intake Images</h6>
                        <span class="small text-muted">Drag-drop supported. Max <?= e(number_format(job_vehicle_intake_image_max_upload_bytes($companyId, $garageId) / 1048576, 2)); ?> MB each.</span>
                      </div>
                      <div id="intake-drop-zone" class="intake-drop-zone">
                        <input type="file" id="intake-images-input" name="intake_images[]" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple class="d-none">
                        <div class="text-muted">Drop images here or <button type="button" class="btn btn-link p-0 align-baseline" id="intake-browse-files-btn">browse files</button></div>
                      </div>
                      <div id="intake-image-preview-grid" class="row g-2 mt-2"></div>
                    </div>
                  </div>

                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="intake_customer_acknowledged" id="intake-customer-ack" value="1">
                    <label class="form-check-label fw-semibold" for="intake-customer-ack">
                      Vehicle condition confirmed in presence of customer
                    </label>
                  </div>
                <?php endif; ?>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <?php if ($vehicleIntakeFeatureReady): ?>
                  <button type="button" class="btn btn-primary" id="confirm-intake-create-btn">Save Intake and Create Job Card</button>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>
      </form>
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
      <div class="card-header">
        <h3 class="card-title">Job List</h3>
        <div class="card-tools">
          <form method="get" action="<?= e(url('modules/jobs/index.php#job-list-section')); ?>" class="d-flex gap-2 flex-wrap">
            <input name="q" value="<?= e($query); ?>" class="form-control form-control-sm" placeholder="Search">
            <?php if ($jobTypeEnabled): ?>
              <select name="job_type_id" class="form-select form-select-sm">
                <option value="0">All Job Types</option>
                <?php foreach ($jobTypeCatalogRows as $jobTypeOption): ?>
                  <?php $jobTypeOptionId = (int) ($jobTypeOption['id'] ?? 0); ?>
                  <option value="<?= $jobTypeOptionId; ?>" <?= $jobTypeFilterId === $jobTypeOptionId ? 'selected' : ''; ?>>
                    <?= e((string) ($jobTypeOption['name'] ?? ('Job Type #' . $jobTypeOptionId))); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
            <select name="status" class="form-select form-select-sm">
              <option value="">All</option>
              <?php if ($statusGroupFilter === 'COMPLETED_BUCKET'): ?>
                <option value="COMPLETED_BUCKET" selected>COMPLETED + READY_FOR_DELIVERY</option>
              <?php endif; ?>
              <?php foreach (job_workflow_statuses(true) as $status): ?>
                <option value="<?= e($status); ?>" <?= $statusFilter === $status ? 'selected' : ''; ?>><?= e($status); ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary" type="submit">Filter</button>
          </form>
        </div>
      </div>
      <div class="card-body border-bottom py-2">
        <ul class="nav nav-pills gap-2 flex-wrap">
          <li class="nav-item">
            <a class="nav-link py-1 px-2 <?= $statusFilter === '' ? 'active' : ''; ?>" href="<?= e(job_filter_url($query, '', $jobTypeFilterId)); ?>">
              All
              <span class="badge <?= $statusFilter === '' ? 'text-bg-light' : 'text-bg-secondary'; ?> ms-1"><?= (int) ($jobStatusCounts[''] ?? 0); ?></span>
            </a>
          </li>
          <?php foreach ($allowedJobStatuses as $status): ?>
            <?php $isStatusActive = $statusFilter === $status || ($status === 'COMPLETED' && $statusGroupFilter === 'COMPLETED_BUCKET'); ?>
            <li class="nav-item">
              <a class="nav-link py-1 px-2 <?= $isStatusActive ? 'active' : ''; ?>" href="<?= e(job_filter_url($query, $status, $jobTypeFilterId)); ?>">
                <?= e(str_replace('_', ' ', $status)); ?>
                <span class="badge <?= $isStatusActive ? 'text-bg-light' : 'text-bg-secondary'; ?> ms-1"><?= (int) ($jobStatusCounts[$status] ?? 0); ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($statusGroupFilter === 'COMPLETED_BUCKET'): ?>
          <div class="small text-muted mt-2">Status Filter: COMPLETED + READY_FOR_DELIVERY</div>
        <?php endif; ?>
        <?php if ($jobTypeEnabled): ?>
          <div class="small text-muted mt-2">Job Type Filter: <?= e($jobTypeFilterLabel); ?></div>
        <?php endif; ?>
      </div>
      <div class="card-body table-responsive p-0"><table class="table table-striped mb-0"><thead><tr><th>Job</th><th>Customer</th><th>Vehicle</th><th>Model</th><th>Job Type</th><th>Assigned</th><th>Priority</th><th>Status</th><th>Estimate</th><th>Opened</th><th>Photos</th><th></th></tr></thead><tbody>
        <?php if (empty($jobs)): ?><tr><td colspan="12" class="text-center text-muted py-4">No job cards found.</td></tr><?php else: foreach ($jobs as $job): ?>
        <tr>
          <td><?= e((string) $job['job_number']); ?></td><td><?= e((string) $job['customer_name']); ?></td><td><?= e((string) $job['registration_no']); ?></td>
          <td><?= e((string) ($job['vehicle_model'] ?? '-')); ?></td>
          <td><?= e((string) ($job['job_type_label'] ?? 'Not Set')); ?></td>
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
<style>
  .intake-section-card {
    background: #edf4ff;
    border: 1px solid #d4e2fb;
  }

  .intake-fuel-progress-wrap {
    height: 14px;
    background: #dbe8ff;
  }

  .intake-fuel-group .btn {
    min-width: 90px;
  }

  .intake-drop-zone {
    border: 2px dashed #9db7e8;
    border-radius: 10px;
    background: #f6f9ff;
    padding: 18px;
    text-align: center;
    transition: border-color 0.2s ease, background-color 0.2s ease;
  }

  .intake-drop-zone.is-dragover {
    border-color: #3d6fcb;
    background: #e9f2ff;
  }
</style>
<?php endif; ?>
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
    var jobForm = document.getElementById('job-card-form');
    var intakeModal = document.getElementById('vehicle-intake-modal');
    var intakeOdometerInput = document.getElementById('intake-odometer-input');
    var intakeFuelOptions = document.querySelectorAll('.intake-fuel-option');
    var intakeFuelProgressBar = document.getElementById('intake-fuel-progress-bar');
    var addCustomIntakeItemBtn = document.getElementById('add-custom-intake-item-btn');
    var intakeCustomItemsBody = document.getElementById('intake-custom-items-body');
    var intakeDropZone = document.getElementById('intake-drop-zone');
    var intakeImagesInput = document.getElementById('intake-images-input');
    var intakeImagePreviewGrid = document.getElementById('intake-image-preview-grid');
    var intakeBrowseFilesBtn = document.getElementById('intake-browse-files-btn');
    var confirmIntakeCreateBtn = document.getElementById('confirm-intake-create-btn');
    var submitCreateHidden = document.getElementById('submit-create-job-hidden');
    var intakeCustomerAck = document.getElementById('intake-customer-ack');
    var intakeFeatureReady = <?= (!$editJob && $vehicleIntakeFeatureReady) ? 'true' : 'false'; ?>;
    var intakeImageRows = [];
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

    function syncIntakeOdometer() {
      if (!intakeOdometerInput || !odometerInput) {
        return;
      }
      var value = (odometerInput.value || '').trim();
      intakeOdometerInput.value = value;
    }

    function fuelLevelPercent(level) {
      switch (String(level || '').toUpperCase()) {
        case 'EMPTY': return 0;
        case 'LOW': return 15;
        case 'QUARTER': return 25;
        case 'HALF': return 50;
        case 'THREE_QUARTER': return 75;
        case 'FULL': return 100;
        default: return 0;
      }
    }

    function updateFuelMeter() {
      if (!intakeFuelProgressBar) {
        return;
      }
      var selected = document.querySelector('.intake-fuel-option:checked');
      var level = selected ? String(selected.value || '').toUpperCase() : 'LOW';
      var pct = fuelLevelPercent(level);
      intakeFuelProgressBar.style.width = pct + '%';
      intakeFuelProgressBar.setAttribute('aria-valuenow', String(pct));
      intakeFuelProgressBar.textContent = level.replace(/_/g, ' ');
      if (pct >= 60) {
        intakeFuelProgressBar.className = 'progress-bar bg-success';
      } else if (pct >= 30) {
        intakeFuelProgressBar.className = 'progress-bar bg-info';
      } else if (pct > 0) {
        intakeFuelProgressBar.className = 'progress-bar bg-warning';
      } else {
        intakeFuelProgressBar.className = 'progress-bar bg-danger';
      }
    }

    function fileIdentity(file) {
      if (!file) {
        return '';
      }
      return [file.name || '', file.size || 0, file.lastModified || 0].join('::');
    }

    function syncIntakeFileInput() {
      if (!intakeImagesInput) {
        return false;
      }
      if (typeof DataTransfer === 'undefined') {
        return false;
      }
      try {
        var transfer = new DataTransfer();
        intakeImageRows.forEach(function (entry) {
          if (entry && entry.file) {
            transfer.items.add(entry.file);
          }
        });
        intakeImagesInput.files = transfer.files;
        return true;
      } catch (error) {
        return false;
      }
    }

    function renderIntakeImagePreview() {
      if (!intakeImagePreviewGrid) {
        return;
      }
      if (!Array.isArray(intakeImageRows) || intakeImageRows.length === 0) {
        intakeImagePreviewGrid.innerHTML = '<div class="col-12"><div class="small text-muted">No intake images selected yet.</div></div>';
        return;
      }

      var html = '';
      for (var i = 0; i < intakeImageRows.length; i++) {
        var row = intakeImageRows[i] || {};
        var file = row.file;
        if (!file) {
          continue;
        }
        var previewUrl = URL.createObjectURL(file);
        html += ''
          + '<div class="col-md-3 col-sm-4 col-6">'
          + '  <div class="border rounded p-2 h-100 d-flex flex-column">'
          + '    <img src="' + escapeHtml(previewUrl) + '" class="img-fluid rounded mb-2" style="height:110px;object-fit:cover;" alt="Intake Preview">'
          + '    <div class="small text-muted text-truncate mb-1">' + escapeHtml(file.name || 'Image') + '</div>'
          + '    <select class="form-select form-select-sm mb-2 intake-image-type-select" data-index="' + i + '" name="intake_image_types[]">'
          + '      <option value="FRONT"' + ((row.type || 'OTHER') === 'FRONT' ? ' selected' : '') + '>Front</option>'
          + '      <option value="REAR"' + ((row.type || 'OTHER') === 'REAR' ? ' selected' : '') + '>Rear</option>'
          + '      <option value="LEFT"' + ((row.type || 'OTHER') === 'LEFT' ? ' selected' : '') + '>Left</option>'
          + '      <option value="RIGHT"' + ((row.type || 'OTHER') === 'RIGHT' ? ' selected' : '') + '>Right</option>'
          + '      <option value="INTERIOR"' + ((row.type || 'OTHER') === 'INTERIOR' ? ' selected' : '') + '>Interior</option>'
          + '      <option value="ENGINE"' + ((row.type || 'OTHER') === 'ENGINE' ? ' selected' : '') + '>Engine</option>'
          + '      <option value="OTHER"' + ((row.type || 'OTHER') === 'OTHER' ? ' selected' : '') + '>Other</option>'
          + '    </select>'
          + '    <button type="button" class="btn btn-sm btn-outline-danger intake-remove-image-btn mt-auto" data-index="' + i + '">Remove</button>'
          + '  </div>'
          + '</div>';
      }
      intakeImagePreviewGrid.innerHTML = html;

      var previewImages = intakeImagePreviewGrid.querySelectorAll('img');
      previewImages.forEach(function (imageNode) {
        imageNode.addEventListener('load', function () {
          var src = imageNode.getAttribute('src') || '';
          if (src.indexOf('blob:') === 0) {
            URL.revokeObjectURL(src);
          }
        });
      });
    }

    function addIntakeFiles(files) {
      if (!Array.isArray(intakeImageRows)) {
        intakeImageRows = [];
      }
      var existing = {};
      intakeImageRows.forEach(function (entry) {
        if (entry && entry.file) {
          existing[fileIdentity(entry.file)] = true;
        }
      });

      var fileList = Array.prototype.slice.call(files || []);
      fileList.forEach(function (file) {
        if (!file || !file.type || file.type.indexOf('image/') !== 0) {
          return;
        }
        var key = fileIdentity(file);
        if (key === '' || existing[key]) {
          return;
        }
        existing[key] = true;
        intakeImageRows.push({
          file: file,
          type: 'OTHER'
        });
      });

      var synced = syncIntakeFileInput();
      renderIntakeImagePreview();
      return synced;
    }

    function addCustomIntakeRow() {
      if (!intakeCustomItemsBody) {
        return;
      }
      var row = document.createElement('tr');
      row.innerHTML = ''
        + '<td><input type="text" name="intake_custom_item_name[]" class="form-control form-control-sm" maxlength="120" placeholder="Custom item name"></td>'
        + '<td>'
        + '  <select name="intake_custom_item_status[]" class="form-select form-select-sm">'
        + '    <option value="PRESENT">Present</option>'
        + '    <option value="NOT_PRESENT">Not Present</option>'
        + '    <option value="DAMAGED">Damaged</option>'
        + '  </select>'
        + '</td>'
        + '<td>'
        + '  <div class="d-flex gap-2">'
        + '    <input type="text" name="intake_custom_item_remarks[]" class="form-control form-control-sm" maxlength="255" placeholder="Optional">'
        + '    <button type="button" class="btn btn-sm btn-outline-danger intake-remove-custom-item-btn">X</button>'
        + '  </div>'
        + '</td>';
      intakeCustomItemsBody.appendChild(row);
    }

    function validateIntakeBeforeSubmit() {
      if (!intakeFeatureReady) {
        return false;
      }
      if (!jobForm) {
        return false;
      }
      return true;
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
        syncIntakeOdometer();
        return;
      }
      if (vehicleSelect.disabled) {
        syncIntakeOdometer();
        return;
      }

      var selectedVehicleId = (vehicleSelect.value || '').trim();
      if (selectedVehicleId === '') {
        if (forceOverwrite) {
          odometerInput.value = '';
        }
        renderOdometerHint('Select vehicle to auto-fill the last recorded odometer.');
        syncIntakeOdometer();
        return;
      }

      var odometerMeta = selectedVehicleOdometerMeta();
      if (odometerMeta.value === '') {
        if (forceOverwrite) {
          odometerInput.value = '';
        }
        renderOdometerHint('No previous odometer found for this vehicle. Enter current reading.');
        syncIntakeOdometer();
        return;
      }

      if (forceOverwrite || (odometerInput.value || '').trim() === '') {
        odometerInput.value = odometerMeta.value;
      }

      var sourceText = odometerMeta.source !== '' ? ' (' + odometerMeta.source + ')' : '';
      renderOdometerHint('Last recorded odometer: ' + formatKm(odometerMeta.value) + ' KM' + sourceText + '.');
      syncIntakeOdometer();
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
      html += '<p class="mb-1"><strong>Suggested Labour:</strong> ' + services.length + '</p><p class="mb-0"><strong>Compatible Parts:</strong> ' + parts.length + '</p>';
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
      html += '<thead><tr><th>Labour/Part</th><th class="text-end">Due KM</th><th>Due Date</th><th>Predicted Visit</th><th>Status</th><th>Source</th><th>Recommendation</th><th style="width: 170px;">Action</th></tr></thead><tbody>';
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

    if (odometerInput) {
      odometerInput.addEventListener('input', syncIntakeOdometer);
    }

    if (!isEditMode && intakeFeatureReady) {
      if (intakeFuelOptions && intakeFuelOptions.length > 0) {
        intakeFuelOptions.forEach(function (option) {
          option.addEventListener('change', updateFuelMeter);
        });
      }
      updateFuelMeter();
      syncIntakeOdometer();

      if (addCustomIntakeItemBtn) {
        addCustomIntakeItemBtn.addEventListener('click', addCustomIntakeRow);
      }
      if (intakeCustomItemsBody) {
        intakeCustomItemsBody.addEventListener('click', function (event) {
          var targetBtn = event.target;
          if (!targetBtn || !targetBtn.classList.contains('intake-remove-custom-item-btn')) {
            return;
          }
          var row = targetBtn.closest('tr');
          if (row && row.parentNode) {
            row.parentNode.removeChild(row);
          }
        });
      }

      if (intakeBrowseFilesBtn && intakeImagesInput) {
        intakeBrowseFilesBtn.addEventListener('click', function () {
          intakeImagesInput.click();
        });
      }
      if (intakeImagesInput) {
        intakeImagesInput.addEventListener('change', function () {
          var synced = addIntakeFiles(Array.prototype.slice.call(intakeImagesInput.files || []));
          if (synced) {
            intakeImagesInput.value = '';
          }
        });
      }
      if (intakeDropZone) {
        ['dragenter', 'dragover'].forEach(function (eventName) {
          intakeDropZone.addEventListener(eventName, function (event) {
            event.preventDefault();
            event.stopPropagation();
            intakeDropZone.classList.add('is-dragover');
          });
        });
        ['dragleave', 'drop'].forEach(function (eventName) {
          intakeDropZone.addEventListener(eventName, function (event) {
            event.preventDefault();
            event.stopPropagation();
            intakeDropZone.classList.remove('is-dragover');
          });
        });
        intakeDropZone.addEventListener('drop', function (event) {
          var files = event.dataTransfer ? event.dataTransfer.files : null;
          addIntakeFiles(Array.prototype.slice.call(files || []));
        });
      }
      if (intakeImagePreviewGrid) {
        intakeImagePreviewGrid.addEventListener('change', function (event) {
          var select = event.target;
          if (!select || !select.classList.contains('intake-image-type-select')) {
            return;
          }
          var index = Number(select.getAttribute('data-index') || -1);
          if (Number.isFinite(index) && index >= 0 && intakeImageRows[index]) {
            intakeImageRows[index].type = String(select.value || 'OTHER').toUpperCase();
          }
        });
        intakeImagePreviewGrid.addEventListener('click', function (event) {
          var btn = event.target;
          if (!btn || !btn.classList.contains('intake-remove-image-btn')) {
            return;
          }
          var index = Number(btn.getAttribute('data-index') || -1);
          if (!Number.isFinite(index) || index < 0) {
            return;
          }
          intakeImageRows.splice(index, 1);
          syncIntakeFileInput();
          renderIntakeImagePreview();
        });
      }
      renderIntakeImagePreview();

      if (intakeModal) {
        intakeModal.addEventListener('shown.bs.modal', function () {
          syncIntakeOdometer();
          updateFuelMeter();
        });
      }

      if (confirmIntakeCreateBtn) {
        confirmIntakeCreateBtn.addEventListener('click', function () {
          if (!validateIntakeBeforeSubmit()) {
            return;
          }
          syncIntakeFileInput();
          jobForm.submit();
        });
      }
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

