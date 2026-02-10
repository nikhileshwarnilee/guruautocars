<?php
declare(strict_types=1);

function job_workflow_statuses(bool $includeLegacy = true): array
{
    $statuses = ['OPEN', 'IN_PROGRESS', 'WAITING_PARTS', 'COMPLETED', 'CLOSED', 'CANCELLED'];
    if ($includeLegacy) {
        array_splice($statuses, 3, 0, ['READY_FOR_DELIVERY']);
    }

    return $statuses;
}

function job_normalize_status(string $status): string
{
    $status = strtoupper(trim($status));
    return in_array($status, job_workflow_statuses(true), true) ? $status : 'OPEN';
}

function job_can_transition(string $fromStatus, string $toStatus): bool
{
    $fromStatus = job_normalize_status($fromStatus);
    $toStatus = job_normalize_status($toStatus);

    if ($fromStatus === $toStatus) {
        return true;
    }

    $transitionMap = [
        'OPEN' => ['IN_PROGRESS', 'CANCELLED'],
        'IN_PROGRESS' => ['WAITING_PARTS', 'COMPLETED', 'CANCELLED'],
        'WAITING_PARTS' => ['IN_PROGRESS', 'COMPLETED', 'CANCELLED'],
        'READY_FOR_DELIVERY' => ['COMPLETED', 'CLOSED', 'CANCELLED'],
        'COMPLETED' => ['CLOSED', 'CANCELLED'],
        'CLOSED' => [],
        'CANCELLED' => [],
    ];

    return in_array($toStatus, $transitionMap[$fromStatus] ?? [], true);
}

function job_generate_number(PDO $pdo, int $garageId): string
{
    $counterStmt = $pdo->prepare('SELECT prefix, current_number FROM job_counters WHERE garage_id = :garage_id FOR UPDATE');
    $counterStmt->execute(['garage_id' => $garageId]);
    $counter = $counterStmt->fetch();

    if (!$counter) {
        $insertCounter = $pdo->prepare('INSERT INTO job_counters (garage_id, prefix, current_number) VALUES (:garage_id, "JOB", 1000)');
        $insertCounter->execute(['garage_id' => $garageId]);
        $counter = ['prefix' => 'JOB', 'current_number' => 1000];
    }

    $nextNumber = ((int) $counter['current_number']) + 1;
    $updateStmt = $pdo->prepare('UPDATE job_counters SET current_number = :current_number WHERE garage_id = :garage_id');
    $updateStmt->execute([
        'current_number' => $nextNumber,
        'garage_id' => $garageId,
    ]);

    return sprintf('%s-%s-%04d', (string) $counter['prefix'], date('ym'), $nextNumber);
}

function job_is_locked(array $job): bool
{
    $status = job_normalize_status((string) ($job['status'] ?? 'OPEN'));
    $statusCode = normalize_status_code((string) ($job['status_code'] ?? 'ACTIVE'));
    return $status === 'CLOSED' || $statusCode !== 'ACTIVE';
}

function job_append_history(
    int $jobId,
    string $actionType,
    ?string $fromStatus = null,
    ?string $toStatus = null,
    ?string $note = null,
    ?array $payload = null
): void {
    try {
        $stmt = db()->prepare(
            'INSERT INTO job_history
              (job_card_id, action_type, from_status, to_status, action_note, payload_json, created_by)
             VALUES
              (:job_card_id, :action_type, :from_status, :to_status, :action_note, :payload_json, :created_by)'
        );
        $stmt->execute([
            'job_card_id' => $jobId,
            'action_type' => mb_substr($actionType, 0, 60),
            'from_status' => $fromStatus !== null ? job_normalize_status($fromStatus) : null,
            'to_status' => $toStatus !== null ? job_normalize_status($toStatus) : null,
            'action_note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'payload_json' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'created_by' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        ]);
    } catch (Throwable $exception) {
        // History must never block core flow.
    }
}

function job_recalculate_estimate(int $jobId): void
{
    $stmt = db()->prepare(
        'SELECT
            COALESCE((SELECT SUM(total_amount) FROM job_labor WHERE job_card_id = :job_id), 0) +
            COALESCE((SELECT SUM(total_amount) FROM job_parts WHERE job_card_id = :job_id), 0)
         AS total_estimate'
    );
    $stmt->execute(['job_id' => $jobId]);
    $total = (float) $stmt->fetchColumn();

    $update = db()->prepare('UPDATE job_cards SET estimated_cost = :estimated_cost WHERE id = :id');
    $update->execute([
        'estimated_cost' => round($total, 2),
        'id' => $jobId,
    ]);
}

function job_assignment_candidates(int $companyId, int $garageId): array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.name, r.role_name, r.role_key
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         INNER JOIN user_garages ug ON ug.user_id = u.id
         WHERE u.company_id = :company_id
           AND ug.garage_id = :garage_id
           AND u.status_code = "ACTIVE"
           AND r.status_code = "ACTIVE"
           AND r.role_key IN ("mechanic", "manager")
         ORDER BY u.name ASC'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);

    return $stmt->fetchAll();
}

function job_sync_assignments(int $jobId, int $companyId, int $garageId, array $requestedUserIds, int $actorUserId): array
{
    $requestedUserIds = array_values(array_unique(array_filter($requestedUserIds, static fn (int $id): bool => $id > 0)));
    if (empty($requestedUserIds)) {
        $deleteStmt = db()->prepare('DELETE FROM job_assignments WHERE job_card_id = :job_card_id');
        $deleteStmt->execute(['job_card_id' => $jobId]);

        $updateStmt = db()->prepare(
            'UPDATE job_cards
             SET assigned_to = NULL,
                 updated_by = :updated_by
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id'
        );
        $updateStmt->execute([
            'updated_by' => $actorUserId,
            'id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        return [];
    }

    $placeholders = implode(',', array_fill(0, count($requestedUserIds), '?'));
    $sql =
        "SELECT u.id
         FROM users u
         INNER JOIN user_garages ug ON ug.user_id = u.id
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.company_id = ?
           AND ug.garage_id = ?
           AND u.status_code = 'ACTIVE'
           AND r.status_code = 'ACTIVE'
           AND r.role_key IN ('mechanic', 'manager')
           AND u.id IN ({$placeholders})";

    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge([$companyId, $garageId], $requestedUserIds));
    $validUserIds = array_map(static fn (array $row): int => (int) $row['id'], $stmt->fetchAll());

    $deleteStmt = db()->prepare('DELETE FROM job_assignments WHERE job_card_id = :job_card_id');
    $deleteStmt->execute(['job_card_id' => $jobId]);

    $insertStmt = db()->prepare(
        'INSERT INTO job_assignments
          (job_card_id, user_id, assignment_role, is_primary, status_code, created_by)
         VALUES
          (:job_card_id, :user_id, "MECHANIC", :is_primary, "ACTIVE", :created_by)'
    );

    foreach ($validUserIds as $index => $userId) {
        $insertStmt->execute([
            'job_card_id' => $jobId,
            'user_id' => $userId,
            'is_primary' => $index === 0 ? 1 : 0,
            'created_by' => $actorUserId,
        ]);
    }

    $updateStmt = db()->prepare(
        'UPDATE job_cards
         SET assigned_to = :assigned_to,
             updated_by = :updated_by
         WHERE id = :id
           AND company_id = :company_id
           AND garage_id = :garage_id'
    );
    $updateStmt->execute([
        'assigned_to' => !empty($validUserIds) ? $validUserIds[0] : null,
        'updated_by' => $actorUserId,
        'id' => $jobId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);

    return $validUserIds;
}

function job_current_assignments(int $jobId): array
{
    $stmt = db()->prepare(
        'SELECT ja.user_id, ja.assignment_role, ja.is_primary, u.name
         FROM job_assignments ja
         INNER JOIN users u ON u.id = ja.user_id
         WHERE ja.job_card_id = :job_card_id
           AND ja.status_code = "ACTIVE"
         ORDER BY ja.is_primary DESC, u.name ASC'
    );
    $stmt->execute(['job_card_id' => $jobId]);
    return $stmt->fetchAll();
}

function job_post_inventory_on_close(int $jobId, int $companyId, int $garageId, int $actorUserId): array
{
    $warnings = [];
    $auditMovements = [];
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $jobStmt = $pdo->prepare(
            'SELECT id, status, status_code, stock_posted_at
             FROM job_cards
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id
             FOR UPDATE'
        );
        $jobStmt->execute([
            'id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $job = $jobStmt->fetch();

        if (!$job) {
            throw new RuntimeException('Job card not found for inventory posting.');
        }

        if ((string) ($job['status_code'] ?? 'ACTIVE') !== 'ACTIVE') {
            $pdo->commit();
            return ['warnings' => [], 'posted' => false];
        }

        if ((string) ($job['status'] ?? '') === 'CANCELLED') {
            $pdo->commit();
            return ['warnings' => [], 'posted' => false];
        }

        if (!empty($job['stock_posted_at'])) {
            $pdo->commit();
            return ['warnings' => [], 'posted' => false];
        }

        $partsStmt = $pdo->prepare(
            'SELECT jp.part_id, SUM(jp.quantity) AS required_qty, p.part_name, p.part_sku
             FROM job_parts jp
             INNER JOIN parts p ON p.id = jp.part_id
             WHERE jp.job_card_id = :job_card_id
             GROUP BY jp.part_id, p.part_name, p.part_sku'
        );
        $partsStmt->execute(['job_card_id' => $jobId]);
        $partLines = $partsStmt->fetchAll();

        $selectStockStmt = $pdo->prepare(
            'SELECT quantity
             FROM garage_inventory
             WHERE garage_id = :garage_id
               AND part_id = :part_id
             FOR UPDATE'
        );
        $insertStockStmt = $pdo->prepare(
            'INSERT INTO garage_inventory (garage_id, part_id, quantity) VALUES (:garage_id, :part_id, 0)'
        );
        $updateStockStmt = $pdo->prepare(
            'UPDATE garage_inventory
             SET quantity = :quantity
             WHERE garage_id = :garage_id
               AND part_id = :part_id'
        );
        $movementStmt = $pdo->prepare(
            'INSERT INTO inventory_movements
              (company_id, garage_id, part_id, movement_type, quantity, reference_type, reference_id, movement_uid, notes, created_by)
             VALUES
              (:company_id, :garage_id, :part_id, "OUT", :quantity, "JOB_CARD", :reference_id, :movement_uid, :notes, :created_by)'
        );

        foreach ($partLines as $line) {
            $partId = (int) $line['part_id'];
            $requiredQty = (float) $line['required_qty'];
            if ($partId <= 0 || $requiredQty <= 0) {
                continue;
            }

            $selectStockStmt->execute([
                'garage_id' => $garageId,
                'part_id' => $partId,
            ]);
            $stock = $selectStockStmt->fetch();

            $availableQty = 0.0;
            if (!$stock) {
                $insertStockStmt->execute([
                    'garage_id' => $garageId,
                    'part_id' => $partId,
                ]);
            } else {
                $availableQty = (float) $stock['quantity'];
            }

            if ($availableQty < $requiredQty) {
                $warnings[] = sprintf(
                    '%s (%s): required %.2f, available %.2f',
                    (string) $line['part_name'],
                    (string) $line['part_sku'],
                    $requiredQty,
                    $availableQty
                );
            }

            $newQty = $availableQty - $requiredQty;
            $updateStockStmt->execute([
                'quantity' => $newQty,
                'garage_id' => $garageId,
                'part_id' => $partId,
            ]);

            $movementStmt->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'part_id' => $partId,
                'quantity' => $requiredQty,
                'reference_id' => $jobId,
                'movement_uid' => sprintf('jobclose-%d-%d', $jobId, $partId),
                'notes' => 'Auto posted on CLOSE for Job Card #' . $jobId,
                'created_by' => $actorUserId,
            ]);

            $auditMovements[] = [
                'part_id' => $partId,
                'part_name' => (string) ($line['part_name'] ?? ''),
                'part_sku' => (string) ($line['part_sku'] ?? ''),
                'available_qty' => round($availableQty, 2),
                'required_qty' => round($requiredQty, 2),
                'new_qty' => round($newQty, 2),
            ];
        }

        $postStmt = $pdo->prepare(
            'UPDATE job_cards
             SET stock_posted_at = NOW()
             WHERE id = :id'
        );
        $postStmt->execute(['id' => $jobId]);

        $pdo->commit();

        foreach ($auditMovements as $movement) {
            log_audit(
                'inventory',
                'stock_out',
                (int) $movement['part_id'],
                'Auto stock OUT posted from job close #' . $jobId,
                [
                    'entity' => 'inventory_movement',
                    'source' => 'JOB-CLOSE',
                    'company_id' => $companyId,
                    'garage_id' => $garageId,
                    'user_id' => $actorUserId,
                    'before' => [
                        'part_id' => (int) $movement['part_id'],
                        'stock_qty' => (float) $movement['available_qty'],
                    ],
                    'after' => [
                        'part_id' => (int) $movement['part_id'],
                        'stock_qty' => (float) $movement['new_qty'],
                        'movement_type' => 'OUT',
                        'movement_qty' => (float) $movement['required_qty'],
                    ],
                    'metadata' => [
                        'job_card_id' => $jobId,
                        'part_name' => (string) $movement['part_name'],
                        'part_sku' => (string) $movement['part_sku'],
                    ],
                ]
            );
        }

        return ['warnings' => $warnings, 'posted' => true];
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function job_fetch_vis_suggestions(int $companyId, int $garageId, int $vehicleId): array
{
    if ($vehicleId <= 0) {
        return [
            'vehicle_variant' => null,
            'service_suggestions' => [],
            'part_suggestions' => [],
        ];
    }

    try {
        $vehicleStmt = db()->prepare(
            'SELECT id, vis_variant_id
             FROM vehicles
             WHERE id = :id
               AND company_id = :company_id
               AND status_code <> "DELETED"
             LIMIT 1'
        );
        $vehicleStmt->execute([
            'id' => $vehicleId,
            'company_id' => $companyId,
        ]);
        $vehicle = $vehicleStmt->fetch();

        if (!$vehicle || (int) ($vehicle['vis_variant_id'] ?? 0) <= 0) {
            return [
                'vehicle_variant' => null,
                'service_suggestions' => [],
                'part_suggestions' => [],
            ];
        }

        $variantId = (int) $vehicle['vis_variant_id'];

        $variantStmt = db()->prepare(
            'SELECT vv.id, vv.variant_name, vm.model_name, vb.brand_name
             FROM vis_variants vv
             INNER JOIN vis_models vm ON vm.id = vv.model_id
             INNER JOIN vis_brands vb ON vb.id = vm.brand_id
             WHERE vv.id = :variant_id
               AND vv.status_code = "ACTIVE"
               AND vm.status_code = "ACTIVE"
               AND vb.status_code = "ACTIVE"
             LIMIT 1'
        );
        $variantStmt->execute(['variant_id' => $variantId]);
        $variant = $variantStmt->fetch();

        if (!$variant) {
            return [
                'vehicle_variant' => null,
                'service_suggestions' => [],
                'part_suggestions' => [],
            ];
        }

        $serviceStmt = db()->prepare(
            'SELECT s.id AS service_id, s.service_code, s.service_name, s.default_rate, s.gst_rate,
                    COUNT(DISTINCT spm.part_id) AS mapped_parts
             FROM vis_service_part_map spm
             INNER JOIN vis_part_compatibility vpc
               ON vpc.part_id = spm.part_id
              AND vpc.variant_id = :variant_id
              AND vpc.company_id = :company_id
              AND vpc.status_code = "ACTIVE"
             INNER JOIN services s
               ON s.id = spm.service_id
              AND s.company_id = :company_id
              AND s.status_code = "ACTIVE"
             WHERE spm.company_id = :company_id
               AND spm.status_code = "ACTIVE"
             GROUP BY s.id
             ORDER BY s.service_name ASC'
        );
        $serviceStmt->execute([
            'variant_id' => $variantId,
            'company_id' => $companyId,
        ]);
        $serviceSuggestions = $serviceStmt->fetchAll();

        $partsStmt = db()->prepare(
            'SELECT p.id AS part_id, p.part_name, p.part_sku, p.selling_price, p.gst_rate,
                    COALESCE(gi.quantity, 0) AS stock_qty,
                    vpc.compatibility_note
             FROM vis_part_compatibility vpc
             INNER JOIN parts p
               ON p.id = vpc.part_id
              AND p.company_id = :company_id
              AND p.status_code = "ACTIVE"
             LEFT JOIN garage_inventory gi
               ON gi.part_id = p.id
              AND gi.garage_id = :garage_id
             WHERE vpc.company_id = :company_id
               AND vpc.variant_id = :variant_id
               AND vpc.status_code = "ACTIVE"
             ORDER BY p.part_name ASC'
        );
        $partsStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'variant_id' => $variantId,
        ]);
        $partSuggestions = $partsStmt->fetchAll();

        return [
            'vehicle_variant' => $variant,
            'service_suggestions' => $serviceSuggestions,
            'part_suggestions' => $partSuggestions,
        ];
    } catch (Throwable $exception) {
        return [
            'vehicle_variant' => null,
            'service_suggestions' => [],
            'part_suggestions' => [],
        ];
    }
}
