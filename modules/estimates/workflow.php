<?php
declare(strict_types=1);

require_once __DIR__ . '/../jobs/workflow.php';

function estimate_statuses(): array
{
    return ['DRAFT', 'APPROVED', 'REJECTED', 'CONVERTED'];
}

function estimate_normalize_status(?string $status): string
{
    $normalized = strtoupper(trim((string) $status));
    return in_array($normalized, estimate_statuses(), true) ? $normalized : 'DRAFT';
}

function estimate_status_badge_class(string $status): string
{
    return match (estimate_normalize_status($status)) {
        'DRAFT' => 'secondary',
        'APPROVED' => 'success',
        'REJECTED' => 'danger',
        'CONVERTED' => 'primary',
        default => 'secondary',
    };
}

function estimate_can_transition(string $fromStatus, string $toStatus): bool
{
    $fromStatus = estimate_normalize_status($fromStatus);
    $toStatus = estimate_normalize_status($toStatus);

    if ($fromStatus === $toStatus) {
        return true;
    }

    $transitionMap = [
        'DRAFT' => ['APPROVED', 'REJECTED'],
        'APPROVED' => ['REJECTED', 'CONVERTED'],
        'REJECTED' => ['DRAFT', 'APPROVED'],
        'CONVERTED' => [],
    ];

    return in_array($toStatus, $transitionMap[$fromStatus] ?? [], true);
}

function estimate_generate_number(PDO $pdo, int $garageId): string
{
    $counterStmt = $pdo->prepare('SELECT prefix, current_number FROM estimate_counters WHERE garage_id = :garage_id FOR UPDATE');
    $counterStmt->execute(['garage_id' => $garageId]);
    $counter = $counterStmt->fetch();

    if (!$counter) {
        $insertCounter = $pdo->prepare('INSERT INTO estimate_counters (garage_id, prefix, current_number) VALUES (:garage_id, "EST", 1000)');
        $insertCounter->execute(['garage_id' => $garageId]);
        $counter = ['prefix' => 'EST', 'current_number' => 1000];
    }

    $nextNumber = ((int) $counter['current_number']) + 1;
    $updateStmt = $pdo->prepare('UPDATE estimate_counters SET current_number = :current_number WHERE garage_id = :garage_id');
    $updateStmt->execute([
        'current_number' => $nextNumber,
        'garage_id' => $garageId,
    ]);

    return sprintf('%s-%s-%04d', (string) $counter['prefix'], date('ym'), $nextNumber);
}

function estimate_is_editable(array $estimate): bool
{
    $status = estimate_normalize_status((string) ($estimate['estimate_status'] ?? 'DRAFT'));
    $statusCode = normalize_status_code((string) ($estimate['status_code'] ?? 'ACTIVE'));

    return $statusCode === 'ACTIVE' && $status === 'DRAFT';
}

function estimate_can_convert(array $estimate): bool
{
    $status = estimate_normalize_status((string) ($estimate['estimate_status'] ?? 'DRAFT'));
    $statusCode = normalize_status_code((string) ($estimate['status_code'] ?? 'ACTIVE'));
    $convertedJobId = (int) ($estimate['converted_job_card_id'] ?? 0);

    return $statusCode === 'ACTIVE' && $status === 'APPROVED' && $convertedJobId <= 0;
}

function estimate_is_deletable(array $estimate): bool
{
    $status = estimate_normalize_status((string) ($estimate['estimate_status'] ?? 'DRAFT'));
    $statusCode = normalize_status_code((string) ($estimate['status_code'] ?? 'ACTIVE'));

    return $statusCode === 'ACTIVE' && in_array($status, ['DRAFT', 'REJECTED'], true);
}

function estimate_soft_delete(int $estimateId, int $companyId, int $garageId, ?int $actorUserId = null): bool
{
    $columns = table_columns('estimates');
    $setClauses = [
        'status_code = "DELETED"',
        'updated_by = :updated_by',
    ];
    if (in_array('deleted_at', $columns, true)) {
        $setClauses[] = 'deleted_at = NOW()';
    }

    $stmt = db()->prepare(
        'UPDATE estimates
         SET ' . implode(', ', $setClauses) . '
         WHERE id = :id
           AND company_id = :company_id
           AND garage_id = :garage_id
           AND status_code = "ACTIVE"'
    );
    $stmt->execute([
        'updated_by' => $actorUserId !== null && $actorUserId > 0 ? $actorUserId : null,
        'id' => $estimateId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);

    return (int) $stmt->rowCount() > 0;
}

function estimate_append_history(
    int $estimateId,
    string $actionType,
    ?string $fromStatus = null,
    ?string $toStatus = null,
    ?string $note = null,
    ?array $payload = null
): void {
    try {
        $stmt = db()->prepare(
            'INSERT INTO estimate_history
              (estimate_id, action_type, from_status, to_status, action_note, payload_json, created_by)
             VALUES
              (:estimate_id, :action_type, :from_status, :to_status, :action_note, :payload_json, :created_by)'
        );
        $stmt->execute([
            'estimate_id' => $estimateId,
            'action_type' => mb_substr($actionType, 0, 60),
            'from_status' => $fromStatus !== null ? estimate_normalize_status($fromStatus) : null,
            'to_status' => $toStatus !== null ? estimate_normalize_status($toStatus) : null,
            'action_note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'payload_json' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'created_by' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        ]);
    } catch (Throwable $exception) {
        // History must never block business flow.
    }
}

function estimate_recalculate_total(int $estimateId): void
{
    $stmt = db()->prepare(
        'SELECT
            COALESCE((SELECT SUM(total_amount) FROM estimate_services WHERE estimate_id = :estimate_id), 0) +
            COALESCE((SELECT SUM(total_amount) FROM estimate_parts WHERE estimate_id = :estimate_id), 0)
         AS estimate_total'
    );
    $stmt->execute(['estimate_id' => $estimateId]);
    $total = (float) $stmt->fetchColumn();

    $update = db()->prepare('UPDATE estimates SET estimate_total = :estimate_total WHERE id = :id');
    $update->execute([
        'estimate_total' => round($total, 2),
        'id' => $estimateId,
    ]);
}

function estimate_fetch_row(int $estimateId, int $companyId, int $garageId): ?array
{
    $stmt = db()->prepare(
        'SELECT *
         FROM estimates
         WHERE id = :id
           AND company_id = :company_id
           AND garage_id = :garage_id
           AND status_code <> "DELETED"
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $estimateId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function estimate_convert_to_job_card(int $estimateId, int $companyId, int $garageId, int $actorUserId, array $conversionOptions = []): array
{
    $pdo = db();
    $jobCardsColumns = table_columns('job_cards');
    $jobEstimateColumnSupported = in_array('estimate_id', $jobCardsColumns, true);
    $jobOdometerEnabled = in_array('odometer_km', $jobCardsColumns, true);
    $jobTypeEnabled = job_type_feature_ready();
    $jobRecommendationNoteEnabled = job_recommendation_note_feature_ready();
    $jobInsuranceEnabled = job_insurance_feature_ready();
    $maintenanceReminderFeatureReady = service_reminder_feature_ready();
    $pdo->beginTransaction();

    try {
        $estimateStmt = $pdo->prepare(
            'SELECT *
             FROM estimates
             WHERE id = :estimate_id
               AND company_id = :company_id
               AND garage_id = :garage_id
             LIMIT 1
             FOR UPDATE'
        );
        $estimateStmt->execute([
            'estimate_id' => $estimateId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $estimate = $estimateStmt->fetch();

        if (!$estimate) {
            throw new RuntimeException('Estimate not found for active garage.');
        }

        if (normalize_status_code((string) ($estimate['status_code'] ?? 'ACTIVE')) !== 'ACTIVE') {
            throw new RuntimeException('Only active estimates can be converted.');
        }

        $currentStatus = estimate_normalize_status((string) ($estimate['estimate_status'] ?? 'DRAFT'));
        if ($currentStatus !== 'APPROVED') {
            throw new RuntimeException('Only approved estimates can be converted.');
        }

        if ($jobEstimateColumnSupported) {
            $existingJobByEstimate = $pdo->prepare(
                'SELECT id, job_number
                 FROM job_cards
                 WHERE estimate_id = :estimate_id
                   AND company_id = :company_id
                   AND garage_id = :garage_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $existingJobByEstimate->execute([
                'estimate_id' => $estimateId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            $existingJob = $existingJobByEstimate->fetch();
            if ($existingJob) {
                $existingJobId = (int) $existingJob['id'];
                $existingJobNumber = (string) $existingJob['job_number'];

                $syncEstimateStmt = $pdo->prepare(
                    'UPDATE estimates
                     SET estimate_status = "CONVERTED",
                         converted_at = COALESCE(converted_at, NOW()),
                         converted_job_card_id = :job_id,
                         updated_by = :updated_by
                     WHERE id = :estimate_id'
                );
                $syncEstimateStmt->execute([
                    'job_id' => $existingJobId,
                    'updated_by' => $actorUserId > 0 ? $actorUserId : null,
                    'estimate_id' => $estimateId,
                ]);

                $pdo->commit();
                return [
                    'job_id' => $existingJobId,
                    'job_number' => $existingJobNumber,
                    'already_converted' => true,
                ];
            }
        }

        $convertedJobId = (int) ($estimate['converted_job_card_id'] ?? 0);
        if ($convertedJobId > 0) {
            $jobLookup = $pdo->prepare(
                'SELECT id, job_number
                 FROM job_cards
                 WHERE id = :job_id
                   AND company_id = :company_id
                   AND garage_id = :garage_id
                 LIMIT 1'
            );
            $jobLookup->execute([
                'job_id' => $convertedJobId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            $job = $jobLookup->fetch();
            if ($job) {
                $pdo->commit();
                return [
                    'job_id' => (int) $job['id'],
                    'job_number' => (string) $job['job_number'],
                    'already_converted' => true,
                ];
            }
        }

        $strictInputMode = !empty($conversionOptions['strict_conversion_input']);

        $priority = strtoupper(trim((string) ($conversionOptions['priority'] ?? 'MEDIUM')));
        if (!in_array($priority, ['LOW', 'MEDIUM', 'HIGH', 'URGENT'], true)) {
            $priority = 'MEDIUM';
        }

        $promisedAtRaw = trim((string) ($conversionOptions['promised_at'] ?? ''));
        if ($promisedAtRaw === '' && !$strictInputMode) {
            $promisedAtRaw = date('Y-m-d\TH:i', strtotime('+1 day'));
        }
        if ($promisedAtRaw === '') {
            throw new RuntimeException('Promised date and time are required before conversion.');
        }
        $promisedAt = str_replace('T', ' ', $promisedAtRaw);
        $promisedAtDate = DateTime::createFromFormat('Y-m-d H:i', $promisedAt);
        if (!$promisedAtDate) {
            throw new RuntimeException('Invalid promised date/time provided.');
        }
        $promisedAt = $promisedAtDate->format('Y-m-d H:i:s');

        $diagnosis = trim((string) ($conversionOptions['diagnosis'] ?? ''));
        if ($diagnosis === '' && !$strictInputMode) {
            $diagnosis = trim((string) ($estimate['notes'] ?? ''));
            if ($diagnosis === '') {
                $diagnosis = 'Converted from estimate ' . (string) ($estimate['estimate_number'] ?? ('#' . $estimateId));
            }
        }
        if ($diagnosis === '') {
            throw new RuntimeException('Diagnosis is required before conversion.');
        }

        $odometerKm = null;
        if ($jobOdometerEnabled) {
            $odometerRaw = $conversionOptions['odometer_km'] ?? null;
            if (!is_numeric((string) $odometerRaw)) {
                if ($strictInputMode) {
                    throw new RuntimeException('Current odometer is required before conversion.');
                }
                $odometerRaw = 0;
            }
            $odometerKm = (int) $odometerRaw;
            if ($odometerKm < 0) {
                throw new RuntimeException('Current odometer must be a non-negative number.');
            }
        }

        $jobTypeId = 0;
        if ($jobTypeEnabled) {
            $jobTypeId = (int) ($conversionOptions['job_type_id'] ?? 0);
            if ($jobTypeId <= 0 && !$strictInputMode) {
                $activeJobTypes = job_type_active_options($companyId);
                $fallbackJobType = $activeJobTypes !== [] ? (int) ($activeJobTypes[0]['id'] ?? 0) : 0;
                $jobTypeId = $fallbackJobType > 0 ? $fallbackJobType : 0;
            }
            if ($jobTypeId <= 0) {
                throw new RuntimeException('Job type selection is required before conversion.');
            }
            $activeJobTypes = job_type_active_options($companyId);
            $activeIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $activeJobTypes);
            if (!in_array($jobTypeId, $activeIds, true)) {
                throw new RuntimeException('Selected job type is invalid or inactive.');
            }
        }

        $recommendationNote = trim((string) ($conversionOptions['recommendation_note'] ?? ''));

        $insuranceCompanyName = null;
        $insuranceClaimNumber = null;
        $insuranceSurveyorName = null;
        $insuranceClaimAmountApproved = null;
        $insuranceCustomerPayableAmount = null;
        $insuranceClaimStatus = 'PENDING';
        if ($jobInsuranceEnabled) {
            $insuranceCompanyName = trim((string) ($conversionOptions['insurance_company_name'] ?? ''));
            $insuranceClaimNumber = trim((string) ($conversionOptions['insurance_claim_number'] ?? ''));
            $insuranceSurveyorName = trim((string) ($conversionOptions['insurance_surveyor_name'] ?? ''));

            $rawClaimApproved = $conversionOptions['insurance_claim_amount_approved'] ?? null;
            if ($rawClaimApproved !== null && trim((string) $rawClaimApproved) !== '') {
                if (!is_numeric((string) $rawClaimApproved)) {
                    throw new RuntimeException('Insurance approved amount is invalid.');
                }
                $insuranceClaimAmountApproved = round((float) $rawClaimApproved, 2);
            }

            $rawCustomerPayable = $conversionOptions['insurance_customer_payable_amount'] ?? null;
            if ($rawCustomerPayable !== null && trim((string) $rawCustomerPayable) !== '') {
                if (!is_numeric((string) $rawCustomerPayable)) {
                    throw new RuntimeException('Insurance customer payable amount is invalid.');
                }
                $insuranceCustomerPayableAmount = round((float) $rawCustomerPayable, 2);
            }

            $insuranceClaimStatus = job_insurance_normalize_status((string) ($conversionOptions['insurance_claim_status'] ?? 'PENDING'));
        }

        $assignedUserIds = [];
        $rawAssignedUserIds = $conversionOptions['assigned_user_ids'] ?? [];
        if (is_array($rawAssignedUserIds)) {
            foreach ($rawAssignedUserIds as $rawUserId) {
                if (is_numeric((string) $rawUserId) && (int) $rawUserId > 0) {
                    $assignedUserIds[] = (int) $rawUserId;
                }
            }
        }
        $assignedUserIds = array_values(array_unique($assignedUserIds));

        $maintenanceActions = [];
        $rawMaintenanceActions = $conversionOptions['maintenance_actions'] ?? [];
        if (is_array($rawMaintenanceActions)) {
            foreach ($rawMaintenanceActions as $reminderId => $actionRaw) {
                $reminderKey = (int) $reminderId;
                if ($reminderKey <= 0) {
                    continue;
                }
                $actionKey = strtolower(trim((string) $actionRaw));
                if (!in_array($actionKey, ['add', 'ignore', 'postpone'], true)) {
                    $actionKey = 'ignore';
                }
                $maintenanceActions[$reminderKey] = $actionKey;
            }
        }

        $customerVehicleCheck = $pdo->prepare(
            'SELECT v.id
             FROM vehicles v
             INNER JOIN customers c ON c.id = v.customer_id
             WHERE v.id = :vehicle_id
               AND c.id = :customer_id
               AND v.company_id = :company_id
               AND c.company_id = :company_id
               AND v.status_code = "ACTIVE"
               AND c.status_code = "ACTIVE"
             LIMIT 1'
        );
        $customerVehicleCheck->execute([
            'vehicle_id' => (int) $estimate['vehicle_id'],
            'customer_id' => (int) $estimate['customer_id'],
            'company_id' => $companyId,
        ]);
        if (!$customerVehicleCheck->fetch()) {
            throw new RuntimeException('Estimate customer and vehicle are not active/matched anymore.');
        }

        $allowParallelJobsForType = $jobTypeEnabled
            && $jobTypeId > 0
            && job_type_allows_multiple_active_jobs($companyId, $jobTypeId);
        if (!$allowParallelJobsForType) {
            $blockingJob = job_find_active_non_closed_vehicle_job(
                $companyId,
                (int) $estimate['vehicle_id'],
                null,
                $pdo,
                true
            );
            if ($blockingJob !== null) {
                throw new RuntimeException(
                    'Vehicle already has active job card '
                    . (string) ($blockingJob['job_number'] ?? ('#' . (int) ($blockingJob['id'] ?? 0)))
                    . ' with status '
                    . (string) ($blockingJob['status'] ?? 'OPEN')
                    . '. Close that job first before converting this estimate.'
                );
            }
        }

        $serviceLinesStmt = $pdo->prepare(
            'SELECT id, service_id, description, quantity, unit_price, gst_rate, total_amount
             FROM estimate_services
             WHERE estimate_id = :estimate_id
             ORDER BY id ASC'
        );
        $serviceLinesStmt->execute(['estimate_id' => $estimateId]);
        $serviceLines = $serviceLinesStmt->fetchAll();

        $partLinesStmt = $pdo->prepare(
            'SELECT id, part_id, quantity, unit_price, gst_rate, total_amount
             FROM estimate_parts
             WHERE estimate_id = :estimate_id
             ORDER BY id ASC'
        );
        $partLinesStmt->execute(['estimate_id' => $estimateId]);
        $partLines = $partLinesStmt->fetchAll();

        if (empty($serviceLines) && empty($partLines)) {
            throw new RuntimeException('Estimate has no services/parts to convert.');
        }

        $jobNumber = job_generate_number($pdo, $garageId);
        $odometerColumnSql = $jobOdometerEnabled ? ', odometer_km' : '';
        $odometerValueSql = $jobOdometerEnabled ? ', :odometer_km' : '';
        $estimateColumnSql = $jobEstimateColumnSupported ? ', estimate_id' : '';
        $estimateValueSql = $jobEstimateColumnSupported ? ', :estimate_id' : '';
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

        $jobInsert = $pdo->prepare(
            'INSERT INTO job_cards
              (company_id, garage_id, job_number, customer_id, vehicle_id' . $odometerColumnSql . ', assigned_to, service_advisor_id' . $estimateColumnSql . ',
               complaint, diagnosis' . $recommendationColumnSql . $insuranceColumnSql . ', status, priority' . $jobTypeColumnSql . ', promised_at, status_code, created_by, updated_by)
             VALUES
              (:company_id, :garage_id, :job_number, :customer_id, :vehicle_id' . $odometerValueSql . ', NULL, :service_advisor_id' . $estimateValueSql . ',
               :complaint, :diagnosis' . $recommendationValueSql . $insuranceValueSql . ', "OPEN", :priority' . $jobTypeValueSql . ', :promised_at, "ACTIVE", :created_by, :updated_by)'
        );
        $jobInsertParams = [
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'job_number' => $jobNumber,
            'customer_id' => (int) $estimate['customer_id'],
            'vehicle_id' => (int) $estimate['vehicle_id'],
            'service_advisor_id' => $actorUserId > 0 ? $actorUserId : null,
            'complaint' => (string) $estimate['complaint'],
            'diagnosis' => $diagnosis !== '' ? $diagnosis : null,
            'priority' => $priority,
            'promised_at' => $promisedAt,
            'created_by' => $actorUserId > 0 ? $actorUserId : null,
            'updated_by' => $actorUserId > 0 ? $actorUserId : null,
        ];
        if ($jobOdometerEnabled) {
            $jobInsertParams['odometer_km'] = $odometerKm !== null ? $odometerKm : 0;
        }
        if ($jobEstimateColumnSupported) {
            $jobInsertParams['estimate_id'] = $estimateId;
        }
        if ($jobRecommendationNoteEnabled) {
            $jobInsertParams['recommendation_note'] = $recommendationNote !== '' ? $recommendationNote : null;
        }
        if ($jobInsuranceEnabled) {
            $jobInsertParams['insurance_company_name'] = $insuranceCompanyName !== null && $insuranceCompanyName !== '' ? $insuranceCompanyName : null;
            $jobInsertParams['insurance_claim_number'] = $insuranceClaimNumber !== null && $insuranceClaimNumber !== '' ? $insuranceClaimNumber : null;
            $jobInsertParams['insurance_surveyor_name'] = $insuranceSurveyorName !== null && $insuranceSurveyorName !== '' ? $insuranceSurveyorName : null;
            $jobInsertParams['insurance_claim_amount_approved'] = $insuranceClaimAmountApproved;
            $jobInsertParams['insurance_customer_payable_amount'] = $insuranceCustomerPayableAmount;
            $jobInsertParams['insurance_claim_status'] = $insuranceClaimStatus;
        }
        if ($jobTypeEnabled) {
            $jobInsertParams['job_type_id'] = $jobTypeId > 0 ? $jobTypeId : null;
        }
        $jobInsert->execute($jobInsertParams);
        $jobId = (int) $pdo->lastInsertId();

        if (!empty($serviceLines)) {
            $serviceInsert = $pdo->prepare(
                'INSERT INTO job_labor
                  (job_card_id, service_id, description, quantity, unit_price, gst_rate, total_amount)
                 VALUES
                  (:job_card_id, :service_id, :description, :quantity, :unit_price, :gst_rate, :total_amount)'
            );
            foreach ($serviceLines as $line) {
                $serviceInsert->execute([
                    'job_card_id' => $jobId,
                    'service_id' => isset($line['service_id']) && (int) $line['service_id'] > 0 ? (int) $line['service_id'] : null,
                    'description' => mb_substr((string) ($line['description'] ?? ''), 0, 255),
                    'quantity' => round((float) ($line['quantity'] ?? 0), 2),
                    'unit_price' => round((float) ($line['unit_price'] ?? 0), 2),
                    'gst_rate' => round((float) ($line['gst_rate'] ?? 0), 2),
                    'total_amount' => round((float) ($line['total_amount'] ?? 0), 2),
                ]);
            }
        }

        if (!empty($partLines)) {
            $partInsert = $pdo->prepare(
                'INSERT INTO job_parts
                  (job_card_id, part_id, quantity, unit_price, gst_rate, total_amount)
                 VALUES
                  (:job_card_id, :part_id, :quantity, :unit_price, :gst_rate, :total_amount)'
            );
            foreach ($partLines as $line) {
                $partInsert->execute([
                    'job_card_id' => $jobId,
                    'part_id' => (int) $line['part_id'],
                    'quantity' => round((float) ($line['quantity'] ?? 0), 2),
                    'unit_price' => round((float) ($line['unit_price'] ?? 0), 2),
                    'gst_rate' => round((float) ($line['gst_rate'] ?? 0), 2),
                    'total_amount' => round((float) ($line['total_amount'] ?? 0), 2),
                ]);
            }
        }

        $assignedUserIds = job_sync_assignments($jobId, $companyId, $garageId, $assignedUserIds, $actorUserId);
        $maintenanceResult = [
            'added_count' => 0,
            'postponed_count' => 0,
            'ignored_count' => 0,
            'warnings' => [],
        ];
        if ($maintenanceReminderFeatureReady && $maintenanceActions !== []) {
            $maintenanceResult = service_reminder_apply_job_creation_actions(
                $companyId,
                $garageId,
                $jobId,
                (int) $estimate['vehicle_id'],
                (int) $estimate['customer_id'],
                $jobOdometerEnabled ? $odometerKm : null,
                $actorUserId,
                $maintenanceActions
            );
        }

        job_recalculate_estimate($jobId);
        job_append_history(
            $jobId,
            'CREATE',
            null,
            'OPEN',
            'Job created from estimate ' . (string) ($estimate['estimate_number'] ?? ''),
            [
                'estimate_id' => $estimateId,
                'estimate_number' => (string) ($estimate['estimate_number'] ?? ''),
                'priority' => $priority,
                'job_type_id' => $jobTypeEnabled ? ($jobTypeId > 0 ? $jobTypeId : null) : null,
                'promised_at' => $promisedAt,
                'odometer_km' => $jobOdometerEnabled ? $odometerKm : null,
                'assigned_count' => count($assignedUserIds),
                'maintenance_added' => (int) ($maintenanceResult['added_count'] ?? 0),
                'maintenance_postponed' => (int) ($maintenanceResult['postponed_count'] ?? 0),
                'maintenance_ignored' => (int) ($maintenanceResult['ignored_count'] ?? 0),
            ]
        );

        $estimateUpdate = $pdo->prepare(
            'UPDATE estimates
             SET estimate_status = "CONVERTED",
                 converted_at = NOW(),
                 converted_job_card_id = :job_id,
                 updated_by = :updated_by
             WHERE id = :estimate_id'
        );
        $estimateUpdate->execute([
            'job_id' => $jobId,
            'updated_by' => $actorUserId > 0 ? $actorUserId : null,
            'estimate_id' => $estimateId,
        ]);

        estimate_append_history(
            $estimateId,
            'CONVERT',
            'APPROVED',
            'CONVERTED',
            'Converted to job card ' . $jobNumber,
            [
                'job_id' => $jobId,
                'job_number' => $jobNumber,
            ]
        );

        log_audit('estimates', 'convert', $estimateId, 'Converted estimate ' . (string) ($estimate['estimate_number'] ?? '') . ' to job card ' . $jobNumber, [
            'entity' => 'estimate',
            'source' => 'UI',
            'before' => [
                'estimate_status' => (string) ($estimate['estimate_status'] ?? 'APPROVED'),
                'converted_job_card_id' => (int) ($estimate['converted_job_card_id'] ?? 0),
            ],
            'after' => [
                'estimate_status' => 'CONVERTED',
                'converted_job_card_id' => $jobId,
            ],
            'metadata' => [
                'job_number' => $jobNumber,
                'service_line_count' => count($serviceLines),
                'part_line_count' => count($partLines),
                'assigned_count' => count($assignedUserIds),
                'maintenance_added' => (int) ($maintenanceResult['added_count'] ?? 0),
                'maintenance_postponed' => (int) ($maintenanceResult['postponed_count'] ?? 0),
                'maintenance_ignored' => (int) ($maintenanceResult['ignored_count'] ?? 0),
            ],
        ]);

        $pdo->commit();

        return [
            'job_id' => $jobId,
            'job_number' => $jobNumber,
            'already_converted' => false,
            'maintenance_result' => $maintenanceResult,
        ];
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}
