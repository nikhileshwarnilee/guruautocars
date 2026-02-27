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
        'CLOSED' => ['OPEN'],
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

function job_find_active_non_closed_vehicle_job(
    int $companyId,
    int $vehicleId,
    ?int $excludeJobId = null,
    ?PDO $pdo = null,
    bool $forUpdate = false
): ?array {
    if ($companyId <= 0 || $vehicleId <= 0) {
        return null;
    }

    $connection = $pdo ?? db();
    $sql =
        'SELECT id, job_number, status, garage_id
         FROM job_cards
         WHERE company_id = :company_id
           AND vehicle_id = :vehicle_id
           AND status_code = "ACTIVE"
           AND UPPER(COALESCE(status, "OPEN")) <> "CLOSED"';
    $params = [
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
    ];

    if ($excludeJobId !== null && $excludeJobId > 0) {
        $sql .= ' AND id <> :exclude_job_id';
        $params['exclude_job_id'] = $excludeJobId;
    }

    $sql .= ' ORDER BY id DESC LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $connection->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $jobId = (int) ($row['id'] ?? 0);
    $jobNumber = trim((string) ($row['job_number'] ?? ''));
    if ($jobNumber === '') {
        $jobNumber = '#' . $jobId;
    }

    return [
        'id' => $jobId,
        'job_number' => $jobNumber,
        'status' => job_normalize_status((string) ($row['status'] ?? 'OPEN')),
        'garage_id' => (int) ($row['garage_id'] ?? 0),
    ];
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

function job_recommendation_note_feature_ready(bool $refresh = false): bool
{
    static $cached = null;

    if (!$refresh && $cached !== null) {
        return $cached;
    }

    try {
        $columns = table_columns('job_cards');
        if (!in_array('recommendation_note', $columns, true)) {
            db()->exec('ALTER TABLE job_cards ADD COLUMN recommendation_note TEXT NULL AFTER diagnosis');
            $columns = table_columns('job_cards');
        }
        $cached = in_array('recommendation_note', $columns, true);
    } catch (Throwable $exception) {
        $cached = false;
    }

    return $cached;
}

function job_type_feature_ready(bool $refresh = false): bool
{
    static $cached = null;

    if (!$refresh && $cached !== null) {
        return $cached;
    }

    try {
        $columns = table_columns('job_cards');
        if (!in_array('job_type_id', $columns, true)) {
            db()->exec('ALTER TABLE job_cards ADD COLUMN job_type_id INT UNSIGNED NULL AFTER priority');
            $columns = table_columns('job_cards');
        }
        if (in_array('job_type_id', $columns, true)) {
            try {
                $indexExists = false;
                $indexStmt = db()->query('SHOW INDEX FROM job_cards WHERE Key_name = "idx_job_cards_job_type_id"');
                if ($indexStmt) {
                    $indexExists = (bool) $indexStmt->fetch();
                }
                if (!$indexExists) {
                    db()->exec('ALTER TABLE job_cards ADD INDEX idx_job_cards_job_type_id (job_type_id)');
                }
            } catch (Throwable $exception) {
                // Index may already exist on older upgraded installs.
            }
        }
        $cached = in_array('job_type_id', $columns, true);
    } catch (Throwable $exception) {
        $cached = false;
    }

    return $cached;
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
                'movement_uid' => sprintf('jobclose-%d-%d-%s', $jobId, $partId, substr(bin2hex(random_bytes(4)), 0, 8)),
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

function job_reverse_inventory_on_reopen(int $jobId, int $companyId, int $garageId, int $actorUserId): array
{
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
            throw new RuntimeException('Job card not found for reopen stock reversal.');
        }

        if ((string) ($job['status_code'] ?? 'ACTIVE') !== 'ACTIVE') {
            throw new RuntimeException('Only ACTIVE jobs can be reopened.');
        }
        if (strtoupper((string) ($job['status'] ?? 'OPEN')) !== 'CLOSED') {
            $pdo->commit();
            return ['reversed' => false, 'reversal_count' => 0, 'warnings' => []];
        }

        $warnings = [];
        $reversalRows = [];

        $netStmt = $pdo->prepare(
            'SELECT m.part_id,
                    COALESCE(SUM(
                        CASE
                          WHEN m.movement_type = "OUT" THEN m.quantity
                          WHEN m.movement_type = "IN" THEN -m.quantity
                          ELSE 0
                        END
                    ), 0) AS net_out_qty,
                    MAX(COALESCE(p.part_name, "")) AS part_name,
                    MAX(COALESCE(p.part_sku, "")) AS part_sku
             FROM inventory_movements m
             LEFT JOIN parts p ON p.id = m.part_id
             WHERE m.company_id = :company_id
               AND m.garage_id = :garage_id
               AND m.reference_type = "JOB_CARD"
               AND m.reference_id = :job_id
             GROUP BY m.part_id'
        );
        $netStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'job_id' => $jobId,
        ]);
        $netRows = $netStmt->fetchAll() ?: [];

        $seedStockStmt = $pdo->prepare(
            'INSERT INTO garage_inventory (garage_id, part_id, quantity)
             VALUES (:garage_id, :part_id, 0)
             ON DUPLICATE KEY UPDATE quantity = quantity'
        );
        $lockStockStmt = $pdo->prepare(
            'SELECT quantity
             FROM garage_inventory
             WHERE garage_id = :garage_id
               AND part_id = :part_id
             FOR UPDATE'
        );
        $updateStockStmt = $pdo->prepare(
            'UPDATE garage_inventory
             SET quantity = :quantity
             WHERE garage_id = :garage_id
               AND part_id = :part_id'
        );
        $insertMovementStmt = $pdo->prepare(
            'INSERT INTO inventory_movements
              (company_id, garage_id, part_id, movement_type, quantity, reference_type, reference_id, movement_uid, notes, created_by)
             VALUES
              (:company_id, :garage_id, :part_id, "IN", :quantity, "JOB_CARD", :reference_id, :movement_uid, :notes, :created_by)'
        );

        foreach ($netRows as $row) {
            $partId = (int) ($row['part_id'] ?? 0);
            $netOutQty = round((float) ($row['net_out_qty'] ?? 0), 2);
            if ($partId <= 0 || abs($netOutQty) <= 0.00001) {
                continue;
            }

            if ($netOutQty < 0) {
                $warnings[] = 'Job stock movement history has net IN quantity for part #' . $partId . '; manual review recommended.';
                continue;
            }

            $seedStockStmt->execute([
                'garage_id' => $garageId,
                'part_id' => $partId,
            ]);
            $lockStockStmt->execute([
                'garage_id' => $garageId,
                'part_id' => $partId,
            ]);
            $currentQty = (float) ($lockStockStmt->fetchColumn() ?: 0);
            $nextQty = round($currentQty + $netOutQty, 2);

            $updateStockStmt->execute([
                'quantity' => $nextQty,
                'garage_id' => $garageId,
                'part_id' => $partId,
            ]);

            $movementUid = sprintf('jobreopen-%d-%d-%s', $jobId, $partId, substr(bin2hex(random_bytes(4)), 0, 8));
            $insertMovementStmt->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'part_id' => $partId,
                'quantity' => $netOutQty,
                'reference_id' => $jobId,
                'movement_uid' => $movementUid,
                'notes' => 'Auto stock IN reversal on REOPEN for Job Card #' . $jobId,
                'created_by' => $actorUserId > 0 ? $actorUserId : null,
            ]);

            $reversalRows[] = [
                'part_id' => $partId,
                'part_name' => (string) ($row['part_name'] ?? ''),
                'part_sku' => (string) ($row['part_sku'] ?? ''),
                'quantity' => $netOutQty,
                'stock_before' => round($currentQty, 2),
                'stock_after' => $nextQty,
                'movement_uid' => $movementUid,
            ];
        }

        $updateJobStmt = $pdo->prepare(
            'UPDATE job_cards
             SET stock_posted_at = NULL
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id'
        );
        $updateJobStmt->execute([
            'id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        $pdo->commit();

        foreach ($reversalRows as $movement) {
            log_audit(
                'inventory',
                'stock_in',
                (int) $movement['part_id'],
                'Auto stock IN posted from job reopen #' . $jobId,
                [
                    'entity' => 'inventory_movement',
                    'source' => 'JOB-REOPEN',
                    'company_id' => $companyId,
                    'garage_id' => $garageId,
                    'user_id' => $actorUserId,
                    'before' => [
                        'part_id' => (int) $movement['part_id'],
                        'stock_qty' => (float) $movement['stock_before'],
                    ],
                    'after' => [
                        'part_id' => (int) $movement['part_id'],
                        'stock_qty' => (float) $movement['stock_after'],
                        'movement_type' => 'IN',
                        'movement_qty' => (float) $movement['quantity'],
                    ],
                    'metadata' => [
                        'job_card_id' => $jobId,
                        'part_name' => (string) $movement['part_name'],
                        'part_sku' => (string) $movement['part_sku'],
                        'movement_uid' => (string) $movement['movement_uid'],
                    ],
                ]
            );
        }

        return [
            'reversed' => !empty($reversalRows),
            'reversal_count' => count($reversalRows),
            'movement_uids' => array_values(array_map(static fn (array $row): string => (string) ($row['movement_uid'] ?? ''), $reversalRows)),
            'warnings' => $warnings,
        ];
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
                    s.category_id, sc.category_name,
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
             LEFT JOIN service_categories sc
               ON sc.id = s.category_id
              AND sc.company_id = s.company_id
             WHERE spm.company_id = :company_id
               AND spm.status_code = "ACTIVE"
             GROUP BY s.id, s.category_id, sc.category_name
             ORDER BY
                CASE WHEN s.category_id IS NULL THEN 1 ELSE 0 END,
                COALESCE(sc.category_name, "Uncategorized"),
                s.service_name ASC'
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

function job_fetch_vis_parts_for_service(int $companyId, int $garageId, int $vehicleId, int $serviceId): array
{
    if ($companyId <= 0 || $garageId <= 0 || $vehicleId <= 0 || $serviceId <= 0) {
        return [];
    }

    try {
        $vehicleStmt = db()->prepare(
            'SELECT vis_variant_id
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
        $vehicle = $vehicleStmt->fetch() ?: null;
        $variantId = (int) ($vehicle['vis_variant_id'] ?? 0);
        if ($variantId <= 0) {
            return [];
        }

        $partsStmt = db()->prepare(
            'SELECT DISTINCT p.id AS part_id, p.part_name, p.part_sku, p.selling_price, p.gst_rate,
                    COALESCE(gi.quantity, 0) AS stock_qty,
                    sm.is_required,
                    vpc.compatibility_note
             FROM vis_service_part_map sm
             INNER JOIN vis_part_compatibility vpc
               ON vpc.part_id = sm.part_id
              AND vpc.variant_id = :variant_id
              AND vpc.company_id = :company_id
              AND vpc.status_code = "ACTIVE"
             INNER JOIN parts p
               ON p.id = sm.part_id
              AND p.company_id = :company_id
              AND p.status_code = "ACTIVE"
             LEFT JOIN garage_inventory gi
               ON gi.part_id = p.id
              AND gi.garage_id = :garage_id
             WHERE sm.company_id = :company_id
               AND sm.service_id = :service_id
               AND sm.status_code = "ACTIVE"
             ORDER BY sm.is_required DESC, p.part_name ASC'
        );
        $partsStmt->execute([
            'variant_id' => $variantId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'service_id' => $serviceId,
        ]);

        return $partsStmt->fetchAll() ?: [];
    } catch (Throwable $exception) {
        return [];
    }
}

function job_add_vis_parts_to_job(int $jobId, array $parts): array
{
    $result = [
        'added_count' => 0,
        'part_ids' => [],
        'warnings' => [],
    ];
    if ($jobId <= 0 || $parts === []) {
        return $result;
    }

    $insertStmt = db()->prepare(
        'INSERT INTO job_parts
          (job_card_id, part_id, quantity, unit_price, gst_rate, total_amount)
         VALUES
          (:job_card_id, :part_id, :quantity, :unit_price, :gst_rate, :total_amount)'
    );

    foreach ($parts as $part) {
        $partId = (int) ($part['part_id'] ?? 0);
        if ($partId <= 0) {
            continue;
        }
        $quantity = 1.0;
        $unitPrice = round((float) ($part['selling_price'] ?? 0), 2);
        $gstRate = round((float) ($part['gst_rate'] ?? 0), 2);
        $totalAmount = round($quantity * $unitPrice, 2);

        try {
            $insertStmt->execute([
                'job_card_id' => $jobId,
                'part_id' => $partId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'gst_rate' => $gstRate,
                'total_amount' => $totalAmount,
            ]);
            $result['added_count']++;
            $result['part_ids'][] = $partId;
        } catch (Throwable $exception) {
            $partName = trim((string) ($part['part_name'] ?? ''));
            $result['warnings'][] = 'Unable to add mapped part ' . ($partName !== '' ? $partName : ('Part #' . $partId)) . '.';
        }
    }

    if ($result['added_count'] > 0) {
        job_recalculate_estimate($jobId);
    }

    return $result;
}

function service_reminder_table_exists(string $tableName, bool $refresh = false): bool
{
    static $cache = [];
    $key = strtolower(trim($tableName));
    if ($key === '') {
        return false;
    }

    if (!$refresh && array_key_exists($key, $cache)) {
        return (bool) $cache[$key];
    }

    try {
        $stmt = db()->query("SHOW TABLES LIKE '" . str_replace("'", "\\'", $key) . "'");
        $cache[$key] = $stmt !== false && $stmt->fetchColumn() !== false;
    } catch (Throwable $exception) {
        $cache[$key] = false;
    }

    return (bool) $cache[$key];
}

function service_reminder_feature_ready(bool $refresh = false): bool
{
    static $ready = null;
    if (!$refresh && $ready !== null) {
        return $ready;
    }

    try {
        $serviceColumns = table_columns('services');
        if (!in_array('enable_reminder', $serviceColumns, true)) {
            db()->exec('ALTER TABLE services ADD COLUMN enable_reminder TINYINT(1) NOT NULL DEFAULT 0');
        }
    } catch (Throwable $exception) {
        // Non-fatal at this stage.
    }

    try {
        $partColumns = table_columns('parts');
        if (!in_array('enable_reminder', $partColumns, true)) {
            db()->exec('ALTER TABLE parts ADD COLUMN enable_reminder TINYINT(1) NOT NULL DEFAULT 0');
        }
    } catch (Throwable $exception) {
        // Non-fatal at this stage.
    }

    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS vehicle_maintenance_rules (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id INT UNSIGNED NOT NULL,
                vehicle_id INT UNSIGNED NOT NULL,
                item_type VARCHAR(20) NOT NULL,
                item_id INT UNSIGNED NOT NULL,
                interval_km INT UNSIGNED DEFAULT NULL,
                interval_days INT UNSIGNED DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                status_code VARCHAR(20) NOT NULL DEFAULT "ACTIVE",
                created_by INT UNSIGNED DEFAULT NULL,
                updated_by INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_vehicle_item_rule (company_id, vehicle_id, item_type, item_id),
                KEY idx_maintenance_rule_scope (company_id, vehicle_id, is_active, status_code),
                KEY idx_maintenance_rule_item (company_id, item_type, item_id, status_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        db()->exec(
            'CREATE TABLE IF NOT EXISTS vehicle_maintenance_reminders (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id INT UNSIGNED NOT NULL,
                garage_id INT UNSIGNED NOT NULL,
                vehicle_id INT UNSIGNED NOT NULL,
                customer_id INT UNSIGNED DEFAULT NULL,
                job_card_id INT UNSIGNED DEFAULT NULL,
                rule_id INT UNSIGNED DEFAULT NULL,
                item_type VARCHAR(20) NOT NULL,
                item_id INT UNSIGNED NOT NULL,
                item_name VARCHAR(180) NOT NULL,
                source_type VARCHAR(30) NOT NULL DEFAULT "AUTO",
                reminder_status VARCHAR(30) NOT NULL DEFAULT "ACTIVE",
                recommendation_text VARCHAR(255) DEFAULT NULL,
                last_service_km INT UNSIGNED DEFAULT NULL,
                last_service_date DATE DEFAULT NULL,
                interval_km INT UNSIGNED DEFAULT NULL,
                next_due_km INT UNSIGNED DEFAULT NULL,
                interval_days INT UNSIGNED DEFAULT NULL,
                next_due_date DATE DEFAULT NULL,
                predicted_next_visit_date DATE DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                status_code VARCHAR(20) NOT NULL DEFAULT "ACTIVE",
                completed_at DATETIME DEFAULT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                updated_by INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_maintenance_reminder_vehicle_active (company_id, vehicle_id, item_type, item_id, is_active, status_code),
                KEY idx_maintenance_reminder_scope (company_id, garage_id, is_active, status_code),
                KEY idx_maintenance_reminder_due_date (company_id, next_due_date),
                KEY idx_maintenance_reminder_due_km (company_id, next_due_km),
                KEY idx_maintenance_reminder_status (company_id, reminder_status, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        db()->exec('DROP TABLE IF EXISTS vis_service_interval_rules');
        db()->exec('DROP TABLE IF EXISTS service_reminders');

        db()->exec(
            'DELETE r
             FROM vehicle_maintenance_rules r
             LEFT JOIN vehicles v ON v.id = r.vehicle_id AND v.company_id = r.company_id
             WHERE v.id IS NULL OR v.status_code = "DELETED"'
        );
        db()->exec(
            'DELETE r
             FROM vehicle_maintenance_rules r
             LEFT JOIN services s ON r.item_type = "SERVICE" AND s.id = r.item_id AND s.company_id = r.company_id
             LEFT JOIN parts p ON r.item_type = "PART" AND p.id = r.item_id AND p.company_id = r.company_id
             WHERE (r.item_type = "SERVICE" AND s.id IS NULL)
                OR (r.item_type = "PART" AND p.id IS NULL)'
        );
        db()->exec(
            'DELETE mr
             FROM vehicle_maintenance_reminders mr
             LEFT JOIN vehicles v ON v.id = mr.vehicle_id AND v.company_id = mr.company_id
             WHERE v.id IS NULL OR v.status_code = "DELETED"'
        );
        db()->exec(
            'DELETE mr
             FROM vehicle_maintenance_reminders mr
             LEFT JOIN services s ON mr.item_type = "SERVICE" AND s.id = mr.item_id AND s.company_id = mr.company_id
             LEFT JOIN parts p ON mr.item_type = "PART" AND p.id = mr.item_id AND p.company_id = mr.company_id
             WHERE (mr.item_type = "SERVICE" AND s.id IS NULL)
                OR (mr.item_type = "PART" AND p.id IS NULL)'
        );
        db()->exec(
            'UPDATE vehicle_maintenance_reminders older
             INNER JOIN vehicle_maintenance_reminders newer
               ON newer.company_id = older.company_id
              AND newer.vehicle_id = older.vehicle_id
              AND newer.item_type = older.item_type
              AND newer.item_id = older.item_id
              AND newer.id > older.id
              AND newer.is_active = 1
              AND newer.status_code = "ACTIVE"
             SET older.is_active = 0,
                 older.status_code = "INACTIVE",
                 older.reminder_status = CASE
                   WHEN older.reminder_status IN ("COMPLETED", "POSTPONED", "IGNORED") THEN older.reminder_status
                   ELSE "INACTIVE"
                 END,
                 older.completed_at = COALESCE(older.completed_at, NOW())
             WHERE older.is_active = 1
               AND older.status_code = "ACTIVE"'
        );
    } catch (Throwable $exception) {
        $ready = false;
        return false;
    }

    $ready = service_reminder_table_exists('vehicle_maintenance_rules', true)
        && service_reminder_table_exists('vehicle_maintenance_reminders', true)
        && in_array('enable_reminder', table_columns('services'), true)
        && in_array('enable_reminder', table_columns('parts'), true);

    return $ready;
}

function service_reminder_supported_types(): array
{
    return ['SERVICE', 'PART'];
}

function service_reminder_type_label(string $serviceType): string
{
    return match (service_reminder_normalize_type($serviceType)) {
        'SERVICE' => 'Service',
        'PART' => 'Part',
        default => 'Item',
    };
}

function service_reminder_normalize_type(?string $serviceType): string
{
    $normalized = strtoupper(trim((string) $serviceType));
    return in_array($normalized, service_reminder_supported_types(), true) ? $normalized : '';
}

function service_reminder_interval_column_map(): array
{
    return [];
}

function service_reminder_parse_positive_int(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $value = str_replace([',', ' '], '', $value);
    }

    if (!is_numeric($value)) {
        return null;
    }

    $number = (int) round((float) $value);
    return $number > 0 ? $number : null;
}

function service_reminder_parse_date(?string $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return null;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $raw));
    if (!checkdate($month, $day, $year)) {
        return null;
    }

    return $raw;
}

function service_reminder_master_items(int $companyId, bool $onlyReminderEnabled = true): array
{
    if ($companyId <= 0 || !service_reminder_feature_ready()) {
        return [];
    }

    $items = [];
    $enabledFilter = $onlyReminderEnabled ? ' AND COALESCE(enable_reminder, 0) = 1 ' : '';

    try {
        $servicesStmt = db()->prepare(
            'SELECT id, service_name AS item_name, service_code AS item_code, default_rate AS unit_price, gst_rate
             FROM services
             WHERE company_id = :company_id
               AND status_code = "ACTIVE"
               ' . $enabledFilter . '
             ORDER BY service_name ASC'
        );
        $servicesStmt->execute(['company_id' => $companyId]);
        foreach ($servicesStmt->fetchAll() as $row) {
            $items[] = [
                'item_type' => 'SERVICE',
                'item_id' => (int) ($row['id'] ?? 0),
                'item_name' => (string) ($row['item_name'] ?? ''),
                'item_code' => (string) ($row['item_code'] ?? ''),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'gst_rate' => (float) ($row['gst_rate'] ?? 0),
            ];
        }
    } catch (Throwable $exception) {
        // Ignore partial source failure.
    }

    try {
        $partsStmt = db()->prepare(
            'SELECT id, part_name AS item_name, part_sku AS item_code, selling_price AS unit_price, gst_rate
             FROM parts
             WHERE company_id = :company_id
               AND status_code = "ACTIVE"
               ' . $enabledFilter . '
             ORDER BY part_name ASC'
        );
        $partsStmt->execute(['company_id' => $companyId]);
        foreach ($partsStmt->fetchAll() as $row) {
            $items[] = [
                'item_type' => 'PART',
                'item_id' => (int) ($row['id'] ?? 0),
                'item_name' => (string) ($row['item_name'] ?? ''),
                'item_code' => (string) ($row['item_code'] ?? ''),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'gst_rate' => (float) ($row['gst_rate'] ?? 0),
            ];
        }
    } catch (Throwable $exception) {
        // Ignore partial source failure.
    }

    usort(
        $items,
        static function (array $left, array $right): int {
            $typeCompare = strcmp((string) ($left['item_type'] ?? ''), (string) ($right['item_type'] ?? ''));
            if ($typeCompare !== 0) {
                return $typeCompare;
            }
            return strcasecmp((string) ($left['item_name'] ?? ''), (string) ($right['item_name'] ?? ''));
        }
    );

    return $items;
}

function service_reminder_item_lookup_map(int $companyId, bool $onlyReminderEnabled = true): array
{
    $lookup = [];
    foreach (service_reminder_master_items($companyId, $onlyReminderEnabled) as $item) {
        $itemType = service_reminder_normalize_type((string) ($item['item_type'] ?? ''));
        $itemId = (int) ($item['item_id'] ?? 0);
        if ($itemType === '' || $itemId <= 0) {
            continue;
        }
        $lookup[$itemType . ':' . $itemId] = $item;
    }

    return $lookup;
}

function service_reminder_vehicle_rule_rows(int $companyId, int $vehicleId, bool $includeInactive = false): array
{
    if ($companyId <= 0 || $vehicleId <= 0 || !service_reminder_feature_ready()) {
        return [];
    }

    $statusFilterSql = $includeInactive
        ? ' AND r.status_code <> "DELETED" '
        : ' AND r.is_active = 1 AND r.status_code = "ACTIVE" ';

    $stmt = db()->prepare(
        'SELECT r.*,
                CASE
                  WHEN r.item_type = "SERVICE" THEN s.service_name
                  WHEN r.item_type = "PART" THEN p.part_name
                  ELSE NULL
                END AS item_name,
                CASE
                  WHEN r.item_type = "SERVICE" THEN s.service_code
                  WHEN r.item_type = "PART" THEN p.part_sku
                  ELSE NULL
                END AS item_code
         FROM vehicle_maintenance_rules r
         LEFT JOIN services s ON r.item_type = "SERVICE" AND s.id = r.item_id AND s.company_id = r.company_id
         LEFT JOIN parts p ON r.item_type = "PART" AND p.id = r.item_id AND p.company_id = r.company_id
         WHERE r.company_id = :company_id
           AND r.vehicle_id = :vehicle_id
           ' . $statusFilterSql . '
         ORDER BY r.item_type ASC, r.id ASC'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
    ]);

    return $stmt->fetchAll();
}

function service_reminder_rule_map_for_vehicle(int $companyId, int $vehicleId, bool $onlyActive = true): array
{
    $rows = service_reminder_vehicle_rule_rows($companyId, $vehicleId, !$onlyActive);
    $map = [];
    foreach ($rows as $row) {
        $itemType = service_reminder_normalize_type((string) ($row['item_type'] ?? ''));
        $itemId = (int) ($row['item_id'] ?? 0);
        if ($itemType === '' || $itemId <= 0) {
            continue;
        }
        $map[$itemType . ':' . $itemId] = $row;
    }

    return $map;
}

function service_reminder_interval_rule_for_vehicle(int $companyId, int $vehicleId): ?array
{
    if ($companyId <= 0 || $vehicleId <= 0 || !service_reminder_feature_ready()) {
        return null;
    }

    $activeRules = service_reminder_vehicle_rule_rows($companyId, $vehicleId, false);
    return [
        'vehicle_id' => $vehicleId,
        'intervals_km' => [],
        'interval_days' => null,
        'has_rule' => !empty($activeRules),
    ];
}

function service_reminder_detect_performed_items(int $jobId, int $companyId): array
{
    if ($jobId <= 0 || $companyId <= 0) {
        return [];
    }

    $items = [];
    try {
        $serviceStmt = db()->prepare(
            'SELECT DISTINCT s.id AS item_id, s.service_name AS item_name
             FROM job_labor jl
             INNER JOIN job_cards jc ON jc.id = jl.job_card_id
             INNER JOIN services s ON s.id = jl.service_id
             WHERE jc.id = :job_id
               AND jc.company_id = :company_id
               AND s.company_id = :company_id
               AND s.status_code = "ACTIVE"
               AND COALESCE(s.enable_reminder, 0) = 1'
        );
        $serviceStmt->execute([
            'job_id' => $jobId,
            'company_id' => $companyId,
        ]);
        foreach ($serviceStmt->fetchAll() as $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            $items['SERVICE:' . $itemId] = [
                'item_type' => 'SERVICE',
                'item_id' => $itemId,
                'item_name' => (string) ($row['item_name'] ?? ''),
            ];
        }
    } catch (Throwable $exception) {
        // Best effort only.
    }

    try {
        $partStmt = db()->prepare(
            'SELECT DISTINCT p.id AS item_id, p.part_name AS item_name
             FROM job_parts jp
             INNER JOIN job_cards jc ON jc.id = jp.job_card_id
             INNER JOIN parts p ON p.id = jp.part_id
             WHERE jc.id = :job_id
               AND jc.company_id = :company_id
               AND p.company_id = :company_id
               AND p.status_code = "ACTIVE"
               AND COALESCE(p.enable_reminder, 0) = 1'
        );
        $partStmt->execute([
            'job_id' => $jobId,
            'company_id' => $companyId,
        ]);
        foreach ($partStmt->fetchAll() as $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            $items['PART:' . $itemId] = [
                'item_type' => 'PART',
                'item_id' => $itemId,
                'item_name' => (string) ($row['item_name'] ?? ''),
            ];
        }
    } catch (Throwable $exception) {
        // Best effort only.
    }

    return array_values($items);
}

function service_reminder_detect_performed_types(int $jobId): array
{
    $items = service_reminder_detect_performed_items($jobId, active_company_id());
    $detected = [];
    foreach ($items as $item) {
        $itemType = service_reminder_normalize_type((string) ($item['item_type'] ?? ''));
        if ($itemType !== '') {
            $detected[$itemType] = true;
        }
    }

    return array_values(array_keys($detected));
}

function service_reminder_job_context(int $jobId, int $companyId, int $garageId): ?array
{
    if ($jobId <= 0 || $companyId <= 0 || $garageId <= 0) {
        return null;
    }

    $jobColumns = table_columns('job_cards');
    $hasOdometer = in_array('odometer_km', $jobColumns, true);
    $odometerSelect = $hasOdometer ? 'jc.odometer_km' : 'NULL AS odometer_km';

    $stmt = db()->prepare(
        'SELECT jc.id, jc.customer_id, jc.vehicle_id, jc.status, jc.opened_at, jc.completed_at, jc.closed_at, ' . $odometerSelect . '
         FROM job_cards jc
         WHERE jc.id = :job_id
           AND jc.company_id = :company_id
           AND jc.garage_id = :garage_id
           AND jc.status_code <> "DELETED"
         LIMIT 1'
    );
    $stmt->execute([
        'job_id' => $jobId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'customer_id' => (int) ($row['customer_id'] ?? 0),
        'vehicle_id' => (int) ($row['vehicle_id'] ?? 0),
        'status' => (string) ($row['status'] ?? 'OPEN'),
        'opened_at' => (string) ($row['opened_at'] ?? ''),
        'completed_at' => (string) ($row['completed_at'] ?? ''),
        'closed_at' => (string) ($row['closed_at'] ?? ''),
        'odometer_km' => service_reminder_parse_positive_int($row['odometer_km'] ?? null),
    ];
}

function service_reminder_extract_date(?string $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    if (strlen($raw) >= 10) {
        return service_reminder_parse_date(substr($raw, 0, 10));
    }

    return service_reminder_parse_date($raw);
}

function service_reminder_vehicle_usage_profile(int $companyId, int $vehicleId): array
{
    if ($companyId <= 0 || $vehicleId <= 0) {
        return ['avg_km_per_day' => null, 'sample_points' => 0];
    }

    $jobColumns = table_columns('job_cards');
    if (!in_array('odometer_km', $jobColumns, true)) {
        return ['avg_km_per_day' => null, 'sample_points' => 0];
    }

    $stmt = db()->prepare(
        'SELECT DATE(COALESCE(closed_at, completed_at, opened_at, created_at)) AS service_date,
                odometer_km
         FROM job_cards
         WHERE company_id = :company_id
           AND vehicle_id = :vehicle_id
           AND status = "CLOSED"
           AND status_code = "ACTIVE"
           AND odometer_km IS NOT NULL
           AND odometer_km > 0
         ORDER BY DATE(COALESCE(closed_at, completed_at, opened_at, created_at)) ASC, id ASC
         LIMIT 24'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
    ]);
    $rows = $stmt->fetchAll();

    $totalKmDelta = 0.0;
    $totalDayDelta = 0.0;
    $samplePoints = 0;
    $previousKm = null;
    $previousDate = null;

    foreach ($rows as $row) {
        $readingKm = service_reminder_parse_positive_int($row['odometer_km'] ?? null);
        $serviceDate = service_reminder_parse_date((string) ($row['service_date'] ?? ''));
        if ($readingKm === null || $serviceDate === null) {
            continue;
        }

        if ($previousKm !== null && $previousDate !== null) {
            $deltaKm = $readingKm - $previousKm;
            $deltaDays = (int) ((strtotime($serviceDate) - strtotime($previousDate)) / 86400);
            if ($deltaKm > 0 && $deltaDays > 0) {
                $totalKmDelta += $deltaKm;
                $totalDayDelta += $deltaDays;
                $samplePoints++;
            }
        }

        $previousKm = $readingKm;
        $previousDate = $serviceDate;
    }

    if ($totalKmDelta <= 0 || $totalDayDelta <= 0) {
        return ['avg_km_per_day' => null, 'sample_points' => $samplePoints];
    }

    return [
        'avg_km_per_day' => round($totalKmDelta / $totalDayDelta, 2),
        'sample_points' => $samplePoints,
    ];
}

function service_reminder_predict_next_visit_date(
    ?int $lastServiceKm,
    ?int $nextDueKm,
    ?string $serviceDate,
    ?float $avgKmPerDay,
    ?string $nextDueDate = null
): ?string {
    $calendarDue = service_reminder_parse_date($nextDueDate);
    $predictedByKm = null;

    if (
        $avgKmPerDay !== null
        && $avgKmPerDay > 0
        && $lastServiceKm !== null
        && $nextDueKm !== null
        && $nextDueKm > $lastServiceKm
    ) {
        $requiredKm = $nextDueKm - $lastServiceKm;
        $daysNeeded = (int) ceil($requiredKm / max(0.01, $avgKmPerDay));
        $baseDate = service_reminder_extract_date($serviceDate) ?? date('Y-m-d');
        $predictedTs = strtotime($baseDate . ' +' . $daysNeeded . ' days');
        if ($predictedTs !== false) {
            $predictedByKm = date('Y-m-d', $predictedTs);
        }
    }

    if ($calendarDue === null) {
        return $predictedByKm;
    }

    if ($predictedByKm === null) {
        return $calendarDue;
    }

    return strcmp($predictedByKm, $calendarDue) <= 0 ? $predictedByKm : $calendarDue;
}

function service_reminder_default_note(string $serviceType, ?int $nextDueKm, ?string $nextDueDate): string
{
    $label = trim($serviceType);
    if ($label === '') {
        $label = service_reminder_type_label($serviceType);
    }
    $parts = [];
    if ($nextDueKm !== null) {
        $parts[] = number_format((float) $nextDueKm, 0) . ' KM';
    }
    if ($nextDueDate !== null) {
        $parts[] = $nextDueDate;
    }

    if ($parts === []) {
        return 'Next ' . $label . ' recommended.';
    }

    return mb_substr('Next ' . $label . ' due at ' . implode(' or ', $parts) . '.', 0, 255);
}

function service_reminder_build_preview_for_job(int $companyId, int $garageId, int $jobId): array
{
    // Legacy close-preview grid was built for fixed reminder types only.
    // Auto reminder creation still runs on CLOSE from actual job lines.
    return [];
}

function service_reminder_parse_close_override_payload(array $postData, array $previewRows): array
{
    return [];
}

function service_reminder_mark_existing_inactive(
    PDO $pdo,
    int $companyId,
    int $vehicleId,
    string $itemType,
    int $itemId,
    int $actorUserId,
    string $nextReminderStatus = 'COMPLETED'
): void {
    $normalizedType = service_reminder_normalize_type($itemType);
    if ($companyId <= 0 || $vehicleId <= 0 || $itemId <= 0 || $normalizedType === '') {
        return;
    }

    $nextStatus = strtoupper(trim($nextReminderStatus));
    if (!in_array($nextStatus, ['COMPLETED', 'INACTIVE', 'IGNORED', 'POSTPONED'], true)) {
        $nextStatus = 'COMPLETED';
    }

    $stmt = $pdo->prepare(
        'UPDATE vehicle_maintenance_reminders
         SET is_active = 0,
             status_code = "INACTIVE",
             reminder_status = :reminder_status,
             completed_at = CASE WHEN :mark_completed = 1 THEN NOW() ELSE completed_at END,
             updated_by = :updated_by
         WHERE company_id = :company_id
           AND vehicle_id = :vehicle_id
           AND item_type = :item_type
           AND item_id = :item_id
           AND is_active = 1
           AND status_code = "ACTIVE"'
    );
    $stmt->execute([
        'reminder_status' => $nextStatus,
        'mark_completed' => $nextStatus === 'COMPLETED' ? 1 : 0,
        'updated_by' => $actorUserId > 0 ? $actorUserId : null,
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
        'item_type' => $normalizedType,
        'item_id' => $itemId,
    ]);
}

function service_reminder_insert_active_reminder(PDO $pdo, array $payload): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO vehicle_maintenance_reminders
          (company_id, garage_id, vehicle_id, customer_id, job_card_id, rule_id, item_type, item_id, item_name, source_type,
           reminder_status, recommendation_text, last_service_km, last_service_date, interval_km, interval_days,
           next_due_km, next_due_date, predicted_next_visit_date, is_active, status_code, created_by, updated_by)
         VALUES
          (:company_id, :garage_id, :vehicle_id, :customer_id, :job_card_id, :rule_id, :item_type, :item_id, :item_name, :source_type,
           :reminder_status, :recommendation_text, :last_service_km, :last_service_date, :interval_km, :interval_days,
           :next_due_km, :next_due_date, :predicted_next_visit_date, 1, "ACTIVE", :created_by, :updated_by)'
    );
    $stmt->execute([
        'company_id' => (int) ($payload['company_id'] ?? 0),
        'garage_id' => (int) ($payload['garage_id'] ?? 0),
        'vehicle_id' => (int) ($payload['vehicle_id'] ?? 0),
        'customer_id' => (int) ($payload['customer_id'] ?? 0) > 0 ? (int) $payload['customer_id'] : null,
        'job_card_id' => (int) ($payload['job_card_id'] ?? 0) > 0 ? (int) $payload['job_card_id'] : null,
        'rule_id' => (int) ($payload['rule_id'] ?? 0) > 0 ? (int) $payload['rule_id'] : null,
        'item_type' => service_reminder_normalize_type((string) ($payload['item_type'] ?? '')),
        'item_id' => (int) ($payload['item_id'] ?? 0),
        'item_name' => mb_substr((string) ($payload['item_name'] ?? ''), 0, 180),
        'source_type' => mb_substr((string) ($payload['source_type'] ?? 'AUTO'), 0, 30),
        'reminder_status' => mb_substr((string) ($payload['reminder_status'] ?? 'ACTIVE'), 0, 30),
        'recommendation_text' => ($payload['recommendation_text'] ?? null) !== null
            ? mb_substr((string) $payload['recommendation_text'], 0, 255)
            : null,
        'last_service_km' => service_reminder_parse_positive_int($payload['last_service_km'] ?? null),
        'last_service_date' => service_reminder_parse_date((string) ($payload['last_service_date'] ?? '')),
        'interval_km' => service_reminder_parse_positive_int($payload['interval_km'] ?? null),
        'interval_days' => service_reminder_parse_positive_int($payload['interval_days'] ?? null),
        'next_due_km' => service_reminder_parse_positive_int($payload['next_due_km'] ?? null),
        'next_due_date' => service_reminder_parse_date((string) ($payload['next_due_date'] ?? '')),
        'predicted_next_visit_date' => service_reminder_parse_date((string) ($payload['predicted_next_visit_date'] ?? '')),
        'created_by' => (int) ($payload['created_by'] ?? 0) > 0 ? (int) $payload['created_by'] : null,
        'updated_by' => (int) ($payload['updated_by'] ?? 0) > 0 ? (int) $payload['updated_by'] : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function service_reminder_apply_on_job_close(
    int $jobId,
    int $companyId,
    int $garageId,
    int $actorUserId,
    array $overridesByType = []
): array {
    $result = [
        'created_count' => 0,
        'disabled_count' => 0,
        'created_types' => [],
        'warnings' => [],
        'preview_rows' => [],
    ];

    if (!service_reminder_feature_ready()) {
        $result['warnings'][] = 'Maintenance reminder storage is not ready.';
        return $result;
    }

    $jobContext = service_reminder_job_context($jobId, $companyId, $garageId);
    if (!$jobContext || (int) ($jobContext['vehicle_id'] ?? 0) <= 0) {
        $result['warnings'][] = 'Job context missing for reminder generation.';
        return $result;
    }

    $performedItems = service_reminder_detect_performed_items($jobId, $companyId);
    if ($performedItems === []) {
        return $result;
    }

    $ruleMap = service_reminder_rule_map_for_vehicle($companyId, (int) $jobContext['vehicle_id'], true);
    $usageProfile = service_reminder_vehicle_usage_profile($companyId, (int) $jobContext['vehicle_id']);
    $avgKmPerDay = isset($usageProfile['avg_km_per_day']) && is_numeric((string) $usageProfile['avg_km_per_day'])
        ? (float) $usageProfile['avg_km_per_day']
        : null;
    $serviceDate = service_reminder_extract_date((string) ($jobContext['closed_at'] ?? ''))
        ?? service_reminder_extract_date((string) ($jobContext['completed_at'] ?? ''))
        ?? service_reminder_extract_date((string) ($jobContext['opened_at'] ?? ''))
        ?? date('Y-m-d');
    $lastServiceKm = service_reminder_parse_positive_int($jobContext['odometer_km'] ?? null);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($performedItems as $item) {
            $itemType = service_reminder_normalize_type((string) ($item['item_type'] ?? ''));
            $itemId = (int) ($item['item_id'] ?? 0);
            $itemName = trim((string) ($item['item_name'] ?? ''));
            if ($itemType === '' || $itemId <= 0) {
                continue;
            }

            $ruleKey = $itemType . ':' . $itemId;
            $rule = is_array($ruleMap[$ruleKey] ?? null) ? (array) $ruleMap[$ruleKey] : null;
            service_reminder_mark_existing_inactive(
                $pdo,
                $companyId,
                (int) $jobContext['vehicle_id'],
                $itemType,
                $itemId,
                $actorUserId,
                'COMPLETED'
            );

            if (!$rule) {
                $result['disabled_count']++;
                continue;
            }

            $intervalKm = service_reminder_parse_positive_int($rule['interval_km'] ?? null);
            $intervalDays = service_reminder_parse_positive_int($rule['interval_days'] ?? null);
            if ($intervalKm === null && $intervalDays === null) {
                $result['disabled_count']++;
                continue;
            }

            $nextDueKm = ($lastServiceKm !== null && $intervalKm !== null) ? ($lastServiceKm + $intervalKm) : null;
            $nextDueDate = null;
            if ($intervalDays !== null) {
                $nextDueTs = strtotime($serviceDate . ' +' . $intervalDays . ' days');
                if ($nextDueTs !== false) {
                    $nextDueDate = date('Y-m-d', $nextDueTs);
                }
            }

            if ($nextDueKm === null && $nextDueDate === null) {
                $result['warnings'][] = 'Skipped ' . ($itemName !== '' ? $itemName : $ruleKey) . ' due to empty schedule.';
                continue;
            }

            $predictedNextVisitDate = service_reminder_predict_next_visit_date(
                $lastServiceKm,
                $nextDueKm,
                $serviceDate,
                $avgKmPerDay,
                $nextDueDate
            );

            service_reminder_insert_active_reminder(
                $pdo,
                [
                    'company_id' => $companyId,
                    'garage_id' => $garageId,
                    'vehicle_id' => (int) $jobContext['vehicle_id'],
                    'customer_id' => (int) ($jobContext['customer_id'] ?? 0),
                    'job_card_id' => $jobId,
                    'rule_id' => (int) ($rule['id'] ?? 0),
                    'item_type' => $itemType,
                    'item_id' => $itemId,
                    'item_name' => $itemName !== '' ? $itemName : ((string) ($rule['item_name'] ?? $ruleKey)),
                    'source_type' => 'AUTO_CLOSE',
                    'reminder_status' => 'ACTIVE',
                    'recommendation_text' => service_reminder_default_note(
                        $itemName !== '' ? $itemName : ((string) ($rule['item_name'] ?? '')),
                        $nextDueKm,
                        $nextDueDate
                    ),
                    'last_service_km' => $lastServiceKm,
                    'last_service_date' => $serviceDate,
                    'interval_km' => $intervalKm,
                    'interval_days' => $intervalDays,
                    'next_due_km' => $nextDueKm,
                    'next_due_date' => $nextDueDate,
                    'predicted_next_visit_date' => $predictedNextVisitDate,
                    'created_by' => $actorUserId,
                    'updated_by' => $actorUserId,
                ]
            );
            $result['created_count']++;
            $result['created_types'][] = $ruleKey;
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $result['warnings'][] = 'Maintenance reminder auto-generation failed: ' . $exception->getMessage();
    }

    return $result;
}

function service_reminder_create_manual_entry(
    int $companyId,
    int $garageId,
    int $vehicleId,
    string $itemType,
    int $itemId,
    ?int $nextDueKm,
    ?string $nextDueDate,
    ?string $predictedNextVisitDate,
    ?string $recommendationText,
    int $actorUserId
): array {
    $result = [
        'ok' => false,
        'message' => '',
        'reminder_id' => 0,
    ];

    if ($companyId <= 0 || $vehicleId <= 0 || !service_reminder_feature_ready()) {
        $result['message'] = 'Reminder storage is not ready.';
        return $result;
    }

    $normalizedType = service_reminder_normalize_type($itemType);
    if ($normalizedType === '' || $itemId <= 0) {
        $result['message'] = 'Select a valid service or part.';
        return $result;
    }

    $lookup = service_reminder_item_lookup_map($companyId, true);
    $ruleKey = $normalizedType . ':' . $itemId;
    $itemMeta = is_array($lookup[$ruleKey] ?? null) ? (array) $lookup[$ruleKey] : null;
    if (!$itemMeta) {
        $result['message'] = 'Selected item is not reminder-enabled or inactive.';
        return $result;
    }

    $vehicleColumns = table_columns('vehicles');
    $hasVehicleOdometer = in_array('odometer_km', $vehicleColumns, true);
    $odometerSelect = $hasVehicleOdometer ? 'v.odometer_km' : 'NULL AS odometer_km';

    $vehicleStmt = db()->prepare(
        'SELECT v.id, v.customer_id, ' . $odometerSelect . '
         FROM vehicles v
         WHERE v.company_id = :company_id
           AND v.id = :vehicle_id
           AND v.status_code <> "DELETED"
         LIMIT 1'
    );
    $vehicleStmt->execute([
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
    ]);
    $vehicle = $vehicleStmt->fetch() ?: null;
    if (!$vehicle) {
        $result['message'] = 'Vehicle not found in active scope.';
        return $result;
    }

    $ruleMap = service_reminder_rule_map_for_vehicle($companyId, $vehicleId, true);
    $rule = is_array($ruleMap[$ruleKey] ?? null) ? (array) $ruleMap[$ruleKey] : null;
    $intervalKm = service_reminder_parse_positive_int($rule['interval_km'] ?? null);
    $intervalDays = service_reminder_parse_positive_int($rule['interval_days'] ?? null);

    $nextDueKmValue = service_reminder_parse_positive_int($nextDueKm);
    $nextDueDateValue = service_reminder_parse_date($nextDueDate);
    $vehicleOdometer = service_reminder_parse_positive_int($vehicle['odometer_km'] ?? null);
    $serviceDate = date('Y-m-d');

    if ($nextDueKmValue === null && $intervalKm !== null && $vehicleOdometer !== null) {
        $nextDueKmValue = $vehicleOdometer + $intervalKm;
    }
    if ($nextDueDateValue === null && $intervalDays !== null) {
        $dueTs = strtotime($serviceDate . ' +' . $intervalDays . ' days');
        if ($dueTs !== false) {
            $nextDueDateValue = date('Y-m-d', $dueTs);
        }
    }
    if ($nextDueKmValue === null && $nextDueDateValue === null) {
        $result['message'] = 'Enter Due KM or Due Date (or configure intervals in Vehicle Maintenance Setup).';
        return $result;
    }

    $usageProfile = service_reminder_vehicle_usage_profile($companyId, $vehicleId);
    $avgKmPerDay = isset($usageProfile['avg_km_per_day']) && is_numeric((string) $usageProfile['avg_km_per_day'])
        ? (float) $usageProfile['avg_km_per_day']
        : null;
    $predictedVisitValue = service_reminder_parse_date($predictedNextVisitDate);
    if ($predictedVisitValue === null) {
        $predictedVisitValue = service_reminder_predict_next_visit_date(
            $vehicleOdometer,
            $nextDueKmValue,
            $serviceDate,
            $avgKmPerDay,
            $nextDueDateValue
        );
    }

    $recommendation = trim((string) $recommendationText);
    if ($recommendation === '') {
        $recommendation = service_reminder_default_note(
            (string) ($itemMeta['item_name'] ?? ''),
            $nextDueKmValue,
            $nextDueDateValue
        );
    }
    $itemName = trim((string) ($itemMeta['item_name'] ?? ''));
    if ($itemName === '') {
        $itemName = $normalizedType . ' #' . $itemId;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        service_reminder_mark_existing_inactive(
            $pdo,
            $companyId,
            $vehicleId,
            $normalizedType,
            $itemId,
            $actorUserId,
            'INACTIVE'
        );

        $newId = service_reminder_insert_active_reminder(
            $pdo,
            [
                'company_id' => $companyId,
                'garage_id' => $garageId > 0 ? $garageId : active_garage_id(),
                'vehicle_id' => $vehicleId,
                'customer_id' => (int) ($vehicle['customer_id'] ?? 0),
                'job_card_id' => null,
                'rule_id' => (int) ($rule['id'] ?? 0),
                'item_type' => $normalizedType,
                'item_id' => $itemId,
                'item_name' => $itemName,
                'source_type' => 'ADMIN_MANUAL',
                'reminder_status' => 'ACTIVE',
                'recommendation_text' => $recommendation,
                'last_service_km' => $vehicleOdometer,
                'last_service_date' => $serviceDate,
                'interval_km' => $intervalKm,
                'interval_days' => $intervalDays,
                'next_due_km' => $nextDueKmValue,
                'next_due_date' => $nextDueDateValue,
                'predicted_next_visit_date' => $predictedVisitValue,
                'created_by' => $actorUserId,
                'updated_by' => $actorUserId,
            ]
        );

        $pdo->commit();
        $result['ok'] = true;
        $result['reminder_id'] = $newId;
        $result['message'] = 'Manual reminder added successfully.';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $result['message'] = 'Unable to add manual reminder.';
    }

    return $result;
}

function service_reminder_create_manual(
    int $companyId,
    int $garageId,
    int $vehicleId,
    int $jobId,
    string $serviceType,
    ?int $lastServiceKm,
    ?int $intervalKm,
    ?int $nextDueKm,
    ?int $intervalDays,
    ?string $nextDueDate,
    string $recommendationText,
    int $actorUserId
): array {
    return [
        'ok' => false,
        'message' => 'Manual fixed-type reminders are removed. Use Vehicle Maintenance Setup rules.',
    ];
}

function service_reminder_due_state(array $reminder): string
{
    $isActive = (int) ($reminder['is_active'] ?? 0) === 1
        && strtoupper(trim((string) ($reminder['status_code'] ?? 'ACTIVE'))) === 'ACTIVE';
    $reminderStatus = strtoupper(trim((string) ($reminder['reminder_status'] ?? 'ACTIVE')));
    if (!$isActive || in_array($reminderStatus, ['COMPLETED', 'INACTIVE'], true)) {
        return 'COMPLETED';
    }

    $today = date('Y-m-d');
    $nextDueDate = service_reminder_parse_date((string) ($reminder['next_due_date'] ?? ''));
    $nextDueKm = service_reminder_parse_positive_int($reminder['next_due_km'] ?? null);
    $currentOdometer = service_reminder_parse_positive_int($reminder['current_odometer_km'] ?? null);

    if (($nextDueDate !== null && strcmp($nextDueDate, $today) < 0)
        || ($nextDueKm !== null && $currentOdometer !== null && $currentOdometer >= $nextDueKm)) {
        return 'OVERDUE';
    }

    $soonDate = date('Y-m-d', strtotime('+7 days'));
    if (($nextDueDate !== null && strcmp($nextDueDate, $soonDate) <= 0)
        || ($nextDueKm !== null && $currentOdometer !== null && ($nextDueKm - $currentOdometer) <= 500)) {
        return 'DUE';
    }

    return 'UPCOMING';
}

function service_reminder_due_badge_class(string $dueState): string
{
    $normalized = strtoupper(trim($dueState));
    return match ($normalized) {
        'OVERDUE' => 'danger',
        'DUE', 'DUE_SOON' => 'warning',
        'UPCOMING' => 'info',
        'COMPLETED' => 'success',
        default => 'secondary',
    };
}

function service_reminder_fetch_active_by_vehicle(int $companyId, int $vehicleId, int $garageId = 0, int $limit = 20): array
{
    if ($companyId <= 0 || $vehicleId <= 0 || !service_reminder_feature_ready()) {
        return [];
    }

    $vehicleColumns = table_columns('vehicles');
    $hasVehicleOdometer = in_array('odometer_km', $vehicleColumns, true);
    $odometerSelect = $hasVehicleOdometer ? 'v.odometer_km AS current_odometer_km' : 'NULL AS current_odometer_km';

    $safeLimit = max(1, min(200, $limit));
    $scopeSql = '';
    $params = [
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
    ];
    if ($garageId > 0) {
        $scopeSql = ' AND mr.garage_id = :garage_id ';
        $params['garage_id'] = $garageId;
    }

    $stmt = db()->prepare(
        'SELECT mr.*, ' . $odometerSelect . ',
                v.registration_no, v.brand, v.model, v.variant,
                jc.job_number
         FROM vehicle_maintenance_reminders mr
         INNER JOIN vehicles v ON v.id = mr.vehicle_id
         LEFT JOIN job_cards jc ON jc.id = mr.job_card_id
         WHERE mr.company_id = :company_id
           AND mr.vehicle_id = :vehicle_id
           AND mr.is_active = 1
           AND mr.status_code = "ACTIVE"
           ' . $scopeSql . '
         ORDER BY
           CASE WHEN mr.next_due_date IS NULL THEN 1 ELSE 0 END ASC,
           mr.next_due_date ASC,
           CASE WHEN mr.next_due_km IS NULL THEN 1 ELSE 0 END ASC,
           mr.next_due_km ASC,
           mr.id DESC
         LIMIT ' . $safeLimit
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as $index => $row) {
        $dueState = service_reminder_due_state($row);
        $rows[$index]['due_state'] = $dueState;
        $rows[$index]['due_badge_class'] = service_reminder_due_badge_class($dueState);
        $rows[$index]['service_type'] = (string) ($row['item_type'] ?? '');
        $rows[$index]['service_label'] = (string) (($row['item_name'] ?? '') !== '' ? $row['item_name'] : (service_reminder_type_label((string) ($row['item_type'] ?? '')) . ' #' . (int) ($row['item_id'] ?? 0)));
    }

    return $rows;
}

function service_reminder_fetch_active_for_scope(
    int $companyId,
    int $selectedGarageId,
    array $garageIds,
    int $limit = 50,
    ?string $serviceType = null,
    ?string $dueState = null
): array {
    if ($companyId <= 0 || !service_reminder_feature_ready()) {
        return [];
    }

    $vehicleColumns = table_columns('vehicles');
    $hasVehicleOdometer = in_array('odometer_km', $vehicleColumns, true);
    $odometerSelect = $hasVehicleOdometer ? 'v.odometer_km AS current_odometer_km' : 'NULL AS current_odometer_km';

    $scopeSql = '';
    $params = ['company_id' => $companyId];
    if ($selectedGarageId > 0) {
        $scopeSql = ' AND mr.garage_id = :garage_id ';
        $params['garage_id'] = $selectedGarageId;
    } else {
        $garageIds = array_values(array_unique(array_filter(array_map('intval', $garageIds), static fn (int $id): bool => $id > 0)));
        if ($garageIds === []) {
            return [];
        }
        $placeholders = [];
        foreach ($garageIds as $index => $garageId) {
            $key = 'garage_' . $index;
            $params[$key] = $garageId;
            $placeholders[] = ':' . $key;
        }
        $scopeSql = ' AND mr.garage_id IN (' . implode(', ', $placeholders) . ') ';
    }

    $typeSql = '';
    $normalizedType = service_reminder_normalize_type($serviceType);
    if ($normalizedType !== '') {
        $typeSql = ' AND mr.item_type = :item_type ';
        $params['item_type'] = $normalizedType;
    }

    $safeLimit = max(1, min(1000, $limit));
    $queryLimit = max($safeLimit, min(3000, $safeLimit * 3));

    $stmt = db()->prepare(
        'SELECT mr.*, ' . $odometerSelect . ',
                v.registration_no, v.brand, v.model, v.variant,
                jc.job_number
         FROM vehicle_maintenance_reminders mr
         INNER JOIN vehicles v ON v.id = mr.vehicle_id
         LEFT JOIN job_cards jc ON jc.id = mr.job_card_id
         WHERE mr.company_id = :company_id
           AND mr.is_active = 1
           AND mr.status_code = "ACTIVE"
           ' . $scopeSql . '
           ' . $typeSql . '
         ORDER BY
           CASE WHEN mr.next_due_date IS NULL THEN 1 ELSE 0 END ASC,
           mr.next_due_date ASC,
           CASE WHEN mr.next_due_km IS NULL THEN 1 ELSE 0 END ASC,
           mr.next_due_km ASC,
           mr.id DESC
         LIMIT ' . $queryLimit
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $dueStateFilter = strtoupper(trim((string) $dueState));
    if ($dueStateFilter === 'ALL') {
        $dueStateFilter = '';
    } elseif ($dueStateFilter === 'DUE_SOON') {
        $dueStateFilter = 'DUE';
    }

    $result = [];
    foreach ($rows as $row) {
        $rowDueState = service_reminder_due_state($row);
        if ($dueStateFilter !== '' && $rowDueState !== $dueStateFilter) {
            continue;
        }

        $row['due_state'] = $rowDueState;
        $row['due_badge_class'] = service_reminder_due_badge_class($rowDueState);
        $row['service_type'] = (string) ($row['item_type'] ?? '');
        $row['service_label'] = (string) (($row['item_name'] ?? '') !== '' ? $row['item_name'] : (service_reminder_type_label((string) ($row['item_type'] ?? '')) . ' #' . (int) ($row['item_id'] ?? 0)));
        $result[] = $row;

        if (count($result) >= $safeLimit) {
            break;
        }
    }

    return $result;
}

function service_reminder_summary_counts(array $rows): array
{
    $summary = [
        'total' => 0,
        'overdue' => 0,
        'due_soon' => 0,
        'upcoming' => 0,
        'completed' => 0,
        'unscheduled' => 0,
    ];

    foreach ($rows as $row) {
        $summary['total']++;
        $state = strtoupper(trim((string) ($row['due_state'] ?? service_reminder_due_state((array) $row))));
        if ($state === 'OVERDUE') {
            $summary['overdue']++;
        } elseif ($state === 'DUE' || $state === 'DUE_SOON') {
            $summary['due_soon']++;
        } elseif ($state === 'UPCOMING') {
            $summary['upcoming']++;
        } elseif ($state === 'COMPLETED') {
            $summary['completed']++;
        } else {
            $summary['unscheduled']++;
        }
    }

    $summary['due'] = $summary['due_soon'];
    return $summary;
}

function service_reminder_fetch_register_for_scope(
    int $companyId,
    int $selectedGarageId,
    array $garageIds,
    int $limit = 500,
    ?string $serviceType = null,
    ?string $status = null,
    ?string $fromDate = null,
    ?string $toDate = null
): array {
    if ($companyId <= 0 || !service_reminder_feature_ready()) {
        return [];
    }

    $vehicleColumns = table_columns('vehicles');
    $hasVehicleOdometer = in_array('odometer_km', $vehicleColumns, true);
    $odometerSelect = $hasVehicleOdometer ? 'v.odometer_km AS current_odometer_km' : 'NULL AS current_odometer_km';

    $scopeSql = '';
    $params = ['company_id' => $companyId];
    if ($selectedGarageId > 0) {
        $scopeSql = ' AND mr.garage_id = :garage_id ';
        $params['garage_id'] = $selectedGarageId;
    } else {
        $garageIds = array_values(array_unique(array_filter(array_map('intval', $garageIds), static fn (int $id): bool => $id > 0)));
        if ($garageIds === []) {
            return [];
        }
        $placeholders = [];
        foreach ($garageIds as $index => $garageId) {
            $key = 'garage_' . $index;
            $params[$key] = $garageId;
            $placeholders[] = ':' . $key;
        }
        $scopeSql = ' AND mr.garage_id IN (' . implode(', ', $placeholders) . ') ';
    }

    $typeSql = '';
    $normalizedType = service_reminder_normalize_type($serviceType);
    if ($normalizedType !== '') {
        $typeSql = ' AND mr.item_type = :item_type ';
        $params['item_type'] = $normalizedType;
    }

    $safeLimit = max(1, min(5000, $limit));
    $queryLimit = max($safeLimit, min(9000, $safeLimit * 2));
    $stmt = db()->prepare(
        'SELECT mr.*, ' . $odometerSelect . ',
                v.registration_no, v.brand, v.model, v.variant,
                c.full_name AS customer_name,
                jc.job_number
         FROM vehicle_maintenance_reminders mr
         INNER JOIN vehicles v ON v.id = mr.vehicle_id
         LEFT JOIN customers c ON c.id = v.customer_id
         LEFT JOIN job_cards jc ON jc.id = mr.job_card_id
         WHERE mr.company_id = :company_id
           AND mr.status_code <> "DELETED"
           ' . $scopeSql . '
           ' . $typeSql . '
         ORDER BY mr.created_at DESC, mr.id DESC
         LIMIT ' . $queryLimit
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $fromDateNorm = service_reminder_parse_date($fromDate);
    $toDateNorm = service_reminder_parse_date($toDate);
    $statusFilter = strtoupper(trim((string) $status));
    if ($statusFilter === '' || $statusFilter === 'ALL') {
        $statusFilter = '';
    }
    if ($statusFilter === 'DUE_SOON') {
        $statusFilter = 'DUE';
    }

    $result = [];
    foreach ($rows as $row) {
        $dueState = service_reminder_due_state($row);
        if ($statusFilter !== '' && $statusFilter !== $dueState) {
            continue;
        }

        $comparisonDate = service_reminder_parse_date((string) ($row['next_due_date'] ?? ''))
            ?? service_reminder_parse_date((string) ($row['last_service_date'] ?? ''))
            ?? service_reminder_extract_date((string) ($row['created_at'] ?? ''));
        if ($comparisonDate !== null) {
            if ($fromDateNorm !== null && strcmp($comparisonDate, $fromDateNorm) < 0) {
                continue;
            }
            if ($toDateNorm !== null && strcmp($comparisonDate, $toDateNorm) > 0) {
                continue;
            }
        }

        $row['due_state'] = $dueState;
        $row['due_badge_class'] = service_reminder_due_badge_class($dueState);
        $row['service_type'] = (string) ($row['item_type'] ?? '');
        $row['service_label'] = (string) (($row['item_name'] ?? '') !== '' ? $row['item_name'] : (service_reminder_type_label((string) ($row['item_type'] ?? '')) . ' #' . (int) ($row['item_id'] ?? 0)));
        $result[] = $row;

        if (count($result) >= $safeLimit) {
            break;
        }
    }

    return $result;
}

function service_reminder_fetch_rule_grid(int $companyId, int $vehicleId): array
{
    if ($companyId <= 0 || $vehicleId <= 0 || !service_reminder_feature_ready()) {
        return [];
    }

    $items = service_reminder_master_items($companyId, true);
    $ruleMap = service_reminder_rule_map_for_vehicle($companyId, $vehicleId, false);
    $rows = [];

    foreach ($items as $item) {
        $itemType = service_reminder_normalize_type((string) ($item['item_type'] ?? ''));
        $itemId = (int) ($item['item_id'] ?? 0);
        if ($itemType === '' || $itemId <= 0) {
            continue;
        }

        $key = $itemType . ':' . $itemId;
        $rule = is_array($ruleMap[$key] ?? null) ? (array) $ruleMap[$key] : null;
        $rows[] = [
            'rule_id' => (int) ($rule['id'] ?? 0),
            'item_type' => $itemType,
            'item_id' => $itemId,
            'item_name' => (string) ($item['item_name'] ?? ''),
            'item_code' => (string) ($item['item_code'] ?? ''),
            'interval_km' => service_reminder_parse_positive_int($rule['interval_km'] ?? null),
            'interval_days' => service_reminder_parse_positive_int($rule['interval_days'] ?? null),
            'is_active' => $rule !== null ? ((int) ($rule['is_active'] ?? 0) === 1 && (string) ($rule['status_code'] ?? 'ACTIVE') === 'ACTIVE') : false,
            'status_code' => (string) ($rule['status_code'] ?? 'ACTIVE'),
        ];
    }

    return $rows;
}

function service_reminder_save_rules_for_vehicles(int $companyId, array $vehicleIds, array $ruleRows, int $actorUserId): array
{
    $result = [
        'ok' => false,
        'vehicle_count' => 0,
        'rule_count' => 0,
        'saved_count' => 0,
        'warnings' => [],
    ];

    if ($companyId <= 0 || !service_reminder_feature_ready()) {
        $result['warnings'][] = 'Reminder storage is not ready.';
        return $result;
    }

    $vehicleIds = array_values(array_unique(array_filter(array_map('intval', $vehicleIds), static fn (int $id): bool => $id > 0)));
    if ($vehicleIds === []) {
        $result['warnings'][] = 'Select at least one vehicle.';
        return $result;
    }

    $validVehicleIds = [];
    try {
        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
        $stmt = db()->prepare(
            'SELECT id
             FROM vehicles
             WHERE company_id = ?
               AND status_code <> "DELETED"
               AND id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$companyId], $vehicleIds));
        $validVehicleIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $stmt->fetchAll());
    } catch (Throwable $exception) {
        $result['warnings'][] = 'Unable to validate selected vehicles.';
        return $result;
    }

    $validVehicleIds = array_values(array_unique(array_filter($validVehicleIds, static fn (int $id): bool => $id > 0)));
    if ($validVehicleIds === []) {
        $result['warnings'][] = 'No valid vehicles found for save.';
        return $result;
    }

    $lookup = service_reminder_item_lookup_map($companyId, true);
    $normalizedRules = [];
    foreach ($ruleRows as $ruleRow) {
        if (!is_array($ruleRow)) {
            continue;
        }

        $itemType = service_reminder_normalize_type((string) ($ruleRow['item_type'] ?? ''));
        $itemId = (int) ($ruleRow['item_id'] ?? 0);
        if ($itemType === '' || $itemId <= 0) {
            continue;
        }

        $ruleKey = $itemType . ':' . $itemId;
        if (!isset($lookup[$ruleKey])) {
            continue;
        }

        $intervalKm = service_reminder_parse_positive_int($ruleRow['interval_km'] ?? null);
        $intervalDays = service_reminder_parse_positive_int($ruleRow['interval_days'] ?? null);
        $isActive = !empty($ruleRow['is_active']);
        if ($isActive && $intervalKm === null && $intervalDays === null) {
            $isActive = false;
        }

        $normalizedRules[$ruleKey] = [
            'item_type' => $itemType,
            'item_id' => $itemId,
            'interval_km' => $intervalKm,
            'interval_days' => $intervalDays,
            'is_active' => $isActive,
        ];
    }

    if ($normalizedRules === []) {
        $result['warnings'][] = 'No valid reminder rows provided.';
        return $result;
    }

    $pdo = db();
    $upsertStmt = $pdo->prepare(
        'INSERT INTO vehicle_maintenance_rules
          (company_id, vehicle_id, item_type, item_id, interval_km, interval_days, is_active, status_code, created_by, updated_by)
         VALUES
          (:company_id, :vehicle_id, :item_type, :item_id, :interval_km, :interval_days, :is_active, :status_code, :created_by, :updated_by)
         ON DUPLICATE KEY UPDATE
          interval_km = VALUES(interval_km),
          interval_days = VALUES(interval_days),
          is_active = VALUES(is_active),
          status_code = VALUES(status_code),
          updated_by = VALUES(updated_by),
          updated_at = NOW()'
    );

    $pdo->beginTransaction();
    try {
        foreach ($validVehicleIds as $vehicleId) {
            foreach ($normalizedRules as $rule) {
                $upsertStmt->execute([
                    'company_id' => $companyId,
                    'vehicle_id' => $vehicleId,
                    'item_type' => (string) $rule['item_type'],
                    'item_id' => (int) $rule['item_id'],
                    'interval_km' => $rule['interval_km'],
                    'interval_days' => $rule['interval_days'],
                    'is_active' => !empty($rule['is_active']) ? 1 : 0,
                    'status_code' => !empty($rule['is_active']) ? 'ACTIVE' : 'INACTIVE',
                    'created_by' => $actorUserId > 0 ? $actorUserId : null,
                    'updated_by' => $actorUserId > 0 ? $actorUserId : null,
                ]);
                $result['saved_count']++;
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $result['warnings'][] = 'Unable to save rules: ' . $exception->getMessage();
        return $result;
    }

    $result['ok'] = true;
    $result['vehicle_count'] = count($validVehicleIds);
    $result['rule_count'] = count($normalizedRules);
    return $result;
}

function service_reminder_delete_rules_for_vehicles(
    int $companyId,
    array $vehicleIds,
    string $itemType,
    int $itemId,
    int $actorUserId
): array {
    $result = [
        'ok' => false,
        'deleted_count' => 0,
        'warnings' => [],
    ];

    if ($companyId <= 0 || !service_reminder_feature_ready()) {
        $result['warnings'][] = 'Reminder storage is not ready.';
        return $result;
    }

    $normalizedType = service_reminder_normalize_type($itemType);
    if ($normalizedType === '' || $itemId <= 0) {
        $result['warnings'][] = 'Invalid item selected for delete.';
        return $result;
    }

    $vehicleIds = array_values(array_unique(array_filter(array_map('intval', $vehicleIds), static fn (int $id): bool => $id > 0)));
    if ($vehicleIds === []) {
        $result['warnings'][] = 'No target vehicles selected.';
        return $result;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
        $sql =
            'UPDATE vehicle_maintenance_rules
             SET is_active = 0,
                 status_code = "DELETED",
                 updated_by = ?
             WHERE company_id = ?
               AND item_type = ?
               AND item_id = ?
               AND vehicle_id IN (' . $placeholders . ')';
        $stmt = db()->prepare($sql);
        $stmt->execute(
            array_merge(
                [$actorUserId > 0 ? $actorUserId : null, $companyId, $normalizedType, $itemId],
                $vehicleIds
            )
        );
        $result['ok'] = true;
        $result['deleted_count'] = $stmt->rowCount();
    } catch (Throwable $exception) {
        $result['warnings'][] = 'Unable to delete rules: ' . $exception->getMessage();
    }

    return $result;
}

function service_reminder_copy_rules_between_vehicles(
    int $companyId,
    int $sourceVehicleId,
    array $targetVehicleIds,
    int $actorUserId
): array {
    $result = [
        'ok' => false,
        'copied_count' => 0,
        'warnings' => [],
    ];

    if ($companyId <= 0 || $sourceVehicleId <= 0 || !service_reminder_feature_ready()) {
        $result['warnings'][] = 'Invalid copy request.';
        return $result;
    }

    $targetVehicleIds = array_values(array_unique(array_filter(array_map('intval', $targetVehicleIds), static fn (int $id): bool => $id > 0)));
    $targetVehicleIds = array_values(array_filter($targetVehicleIds, static fn (int $id): bool => $id !== $sourceVehicleId));
    if ($targetVehicleIds === []) {
        $result['warnings'][] = 'Select at least one target vehicle.';
        return $result;
    }

    $sourceRows = service_reminder_vehicle_rule_rows($companyId, $sourceVehicleId, true);
    $ruleRows = [];
    foreach ($sourceRows as $row) {
        $itemType = service_reminder_normalize_type((string) ($row['item_type'] ?? ''));
        $itemId = (int) ($row['item_id'] ?? 0);
        if ($itemType === '' || $itemId <= 0) {
            continue;
        }
        $ruleRows[] = [
            'item_type' => $itemType,
            'item_id' => $itemId,
            'interval_km' => service_reminder_parse_positive_int($row['interval_km'] ?? null),
            'interval_days' => service_reminder_parse_positive_int($row['interval_days'] ?? null),
            'is_active' => (int) ($row['is_active'] ?? 0) === 1 && (string) ($row['status_code'] ?? 'ACTIVE') === 'ACTIVE',
        ];
    }

    if ($ruleRows === []) {
        $result['warnings'][] = 'Selected source vehicle has no rules to copy.';
        return $result;
    }

    $saveResult = service_reminder_save_rules_for_vehicles($companyId, $targetVehicleIds, $ruleRows, $actorUserId);
    $result['ok'] = (bool) ($saveResult['ok'] ?? false);
    $result['copied_count'] = (int) ($saveResult['saved_count'] ?? 0);
    $result['warnings'] = (array) ($saveResult['warnings'] ?? []);
    return $result;
}

function service_reminder_due_recommendations_for_vehicle(int $companyId, int $garageId, int $vehicleId, int $limit = 12): array
{
    $rows = service_reminder_fetch_active_by_vehicle($companyId, $vehicleId, $garageId, max(1, min(50, $limit * 3)));
    if ($rows === []) {
        return [];
    }

    $serviceIds = [];
    $partIds = [];
    foreach ($rows as $row) {
        $itemType = service_reminder_normalize_type((string) ($row['item_type'] ?? $row['service_type'] ?? ''));
        $itemId = (int) ($row['item_id'] ?? 0);
        if ($itemType === 'SERVICE' && $itemId > 0) {
            $serviceIds[$itemId] = true;
        } elseif ($itemType === 'PART' && $itemId > 0) {
            $partIds[$itemId] = true;
        }
    }

    $serviceMeta = [];
    if ($serviceIds !== []) {
        try {
            $serviceIdList = array_keys($serviceIds);
            $placeholders = implode(',', array_fill(0, count($serviceIdList), '?'));
            $stmt = db()->prepare(
                'SELECT id, service_name, default_rate, gst_rate
                 FROM services
                 WHERE company_id = ?
                   AND status_code = "ACTIVE"
                   AND id IN (' . $placeholders . ')'
            );
            $stmt->execute(array_merge([$companyId], $serviceIdList));
            foreach ($stmt->fetchAll() as $row) {
                $serviceMeta[(int) ($row['id'] ?? 0)] = $row;
            }
        } catch (Throwable $exception) {
            // Optional metadata only.
        }
    }

    $partMeta = [];
    if ($partIds !== []) {
        try {
            $partIdList = array_keys($partIds);
            $placeholders = implode(',', array_fill(0, count($partIdList), '?'));
            $stmt = db()->prepare(
                'SELECT p.id, p.part_name, p.unit AS part_unit, p.selling_price, p.gst_rate, COALESCE(gi.quantity, 0) AS stock_qty
                 FROM parts p
                 LEFT JOIN garage_inventory gi ON gi.part_id = p.id AND gi.garage_id = ?
                 WHERE p.company_id = ?
                   AND p.status_code = "ACTIVE"
                   AND p.id IN (' . $placeholders . ')'
            );
            $stmt->execute(array_merge([$garageId, $companyId], $partIdList));
            foreach ($stmt->fetchAll() as $row) {
                $partMeta[(int) ($row['id'] ?? 0)] = $row;
            }
        } catch (Throwable $exception) {
            // Optional metadata only.
        }
    }

    $recommended = [];
    foreach ($rows as $row) {
        $itemType = service_reminder_normalize_type((string) ($row['item_type'] ?? $row['service_type'] ?? ''));
        $itemId = (int) ($row['item_id'] ?? 0);
        if ($itemType === '' || $itemId <= 0) {
            continue;
        }

        $dueState = strtoupper(trim((string) ($row['due_state'] ?? service_reminder_due_state($row))));
        if ($dueState === 'COMPLETED') {
            continue;
        }

        $unitPrice = 0.0;
        $gstRate = 0.0;
        $stockQty = null;
        $partUnit = null;
        if ($itemType === 'SERVICE' && isset($serviceMeta[$itemId])) {
            $unitPrice = (float) ($serviceMeta[$itemId]['default_rate'] ?? 0);
            $gstRate = (float) ($serviceMeta[$itemId]['gst_rate'] ?? 0);
        } elseif ($itemType === 'PART' && isset($partMeta[$itemId])) {
            $unitPrice = (float) ($partMeta[$itemId]['selling_price'] ?? 0);
            $gstRate = (float) ($partMeta[$itemId]['gst_rate'] ?? 0);
            $stockQty = (float) ($partMeta[$itemId]['stock_qty'] ?? 0);
            $partUnit = part_unit_normalize_code((string) ($partMeta[$itemId]['part_unit'] ?? ''));
        }

        $recommended[] = [
            'reminder_id' => (int) ($row['id'] ?? 0),
            'item_type' => $itemType,
            'item_id' => $itemId,
            'item_name' => (string) (($row['item_name'] ?? '') !== '' ? $row['item_name'] : ($row['service_label'] ?? '-')),
            'due_state' => $dueState,
            'next_due_km' => service_reminder_parse_positive_int($row['next_due_km'] ?? null),
            'next_due_date' => service_reminder_parse_date((string) ($row['next_due_date'] ?? '')),
            'predicted_next_visit_date' => service_reminder_parse_date((string) ($row['predicted_next_visit_date'] ?? '')),
            'interval_km' => service_reminder_parse_positive_int($row['interval_km'] ?? null),
            'interval_days' => service_reminder_parse_positive_int($row['interval_days'] ?? null),
            'source_type' => (string) (($row['source_type'] ?? '') !== '' ? $row['source_type'] : 'AUTO'),
            'recommendation_text' => (string) (($row['recommendation_text'] ?? '') !== '' ? $row['recommendation_text'] : service_reminder_default_note((string) (($row['item_name'] ?? '') !== '' ? $row['item_name'] : ($row['service_label'] ?? '')), service_reminder_parse_positive_int($row['next_due_km'] ?? null), service_reminder_parse_date((string) ($row['next_due_date'] ?? '')))),
            'unit_price' => round($unitPrice, 2),
            'gst_rate' => round($gstRate, 2),
            'stock_qty' => $stockQty,
            'part_unit' => $partUnit,
            'action_default' => in_array($dueState, ['OVERDUE', 'DUE'], true) ? 'add' : 'postpone',
        ];
    }

    usort(
        $recommended,
        static function (array $left, array $right): int {
            $weight = [
                'OVERDUE' => 1,
                'DUE' => 2,
                'UPCOMING' => 3,
            ];
            $leftWeight = $weight[(string) ($left['due_state'] ?? 'UPCOMING')] ?? 9;
            $rightWeight = $weight[(string) ($right['due_state'] ?? 'UPCOMING')] ?? 9;
            if ($leftWeight !== $rightWeight) {
                return $leftWeight < $rightWeight ? -1 : 1;
            }

            $leftDate = (string) ($left['next_due_date'] ?? '');
            $rightDate = (string) ($right['next_due_date'] ?? '');
            if ($leftDate !== $rightDate) {
                return strcmp($leftDate, $rightDate);
            }

            return strcmp((string) ($left['item_name'] ?? ''), (string) ($right['item_name'] ?? ''));
        }
    );

    return array_slice($recommended, 0, max(1, min(50, $limit)));
}

function service_reminder_apply_job_creation_actions(
    int $companyId,
    int $garageId,
    int $jobId,
    int $vehicleId,
    int $customerId,
    ?int $odometerKm,
    int $actorUserId,
    array $actionByReminderId
): array {
    $result = [
        'added_count' => 0,
        'postponed_count' => 0,
        'ignored_count' => 0,
        'warnings' => [],
    ];

    if ($companyId <= 0 || $garageId <= 0 || $jobId <= 0 || $vehicleId <= 0 || !service_reminder_feature_ready()) {
        return $result;
    }

    $normalizedActions = [];
    foreach ($actionByReminderId as $reminderId => $actionRaw) {
        $id = (int) $reminderId;
        if ($id <= 0) {
            continue;
        }
        $action = strtolower(trim((string) $actionRaw));
        if (!in_array($action, ['add', 'ignore', 'postpone'], true)) {
            $action = 'ignore';
        }
        $normalizedActions[$id] = $action;
    }
    if ($normalizedActions === []) {
        return $result;
    }

    $ids = array_keys($normalizedActions);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$companyId, $vehicleId], $ids);
    try {
        $reminderStmt = db()->prepare(
            'SELECT *
             FROM vehicle_maintenance_reminders
             WHERE company_id = ?
               AND vehicle_id = ?
               AND is_active = 1
               AND status_code = "ACTIVE"
               AND id IN (' . $placeholders . ')'
        );
        $reminderStmt->execute($params);
        $rows = $reminderStmt->fetchAll();
    } catch (Throwable $exception) {
        $result['warnings'][] = 'Unable to read maintenance recommendations.';
        return $result;
    }

    if ($rows === []) {
        return $result;
    }

    $pdo = db();
    $serviceFetchStmt = $pdo->prepare(
        'SELECT id, service_name, default_rate, gst_rate
         FROM services
         WHERE id = :id
           AND company_id = :company_id
           AND status_code = "ACTIVE"
         LIMIT 1'
    );
    $partFetchStmt = $pdo->prepare(
        'SELECT p.id, p.part_name, p.unit AS part_unit, p.selling_price, p.gst_rate, COALESCE(gi.quantity, 0) AS stock_qty
         FROM parts p
         LEFT JOIN garage_inventory gi ON gi.part_id = p.id AND gi.garage_id = :garage_id
         WHERE p.id = :id
           AND p.company_id = :company_id
           AND p.status_code = "ACTIVE"
         LIMIT 1'
    );
    $insertLaborStmt = $pdo->prepare(
        'INSERT INTO job_labor
          (job_card_id, service_id, execution_type, outsource_vendor_id, outsource_partner_name, outsource_cost,
           outsource_payable_status, outsource_paid_at, outsource_paid_by, description, quantity, unit_price, gst_rate, total_amount)
         VALUES
          (:job_card_id, :service_id, "IN_HOUSE", NULL, NULL, 0, "PAID", NULL, NULL, :description, :quantity, :unit_price, :gst_rate, :total_amount)'
    );
    $insertPartStmt = $pdo->prepare(
        'INSERT INTO job_parts
          (job_card_id, part_id, quantity, unit_price, gst_rate, total_amount)
         VALUES
          (:job_card_id, :part_id, :quantity, :unit_price, :gst_rate, :total_amount)'
    );
    $postponeStmt = $pdo->prepare(
        'UPDATE vehicle_maintenance_reminders
         SET next_due_km = :next_due_km,
             next_due_date = :next_due_date,
             source_type = "POSTPONE",
             reminder_status = "POSTPONED",
             updated_by = :updated_by
         WHERE id = :id
           AND company_id = :company_id
           AND vehicle_id = :vehicle_id
           AND is_active = 1
           AND status_code = "ACTIVE"'
    );

    $usageProfile = service_reminder_vehicle_usage_profile($companyId, $vehicleId);
    $avgKmPerDay = isset($usageProfile['avg_km_per_day']) && is_numeric((string) $usageProfile['avg_km_per_day'])
        ? (float) $usageProfile['avg_km_per_day']
        : null;
    $baseDate = date('Y-m-d');

    foreach ($rows as $row) {
        $reminderId = (int) ($row['id'] ?? 0);
        if ($reminderId <= 0) {
            continue;
        }
        $action = $normalizedActions[$reminderId] ?? 'ignore';
        $itemType = service_reminder_normalize_type((string) ($row['item_type'] ?? ''));
        $itemId = (int) ($row['item_id'] ?? 0);
        $itemName = trim((string) ($row['item_name'] ?? ''));
        $intervalKm = service_reminder_parse_positive_int($row['interval_km'] ?? null);
        $intervalDays = service_reminder_parse_positive_int($row['interval_days'] ?? null);

        if ($action === 'ignore') {
            $result['ignored_count']++;
            continue;
        }

        if ($action === 'postpone') {
            $baseKm = $odometerKm ?? service_reminder_parse_positive_int($row['next_due_km'] ?? null) ?? service_reminder_parse_positive_int($row['last_service_km'] ?? null);
            $nextDueKm = null;
            if ($baseKm !== null && $intervalKm !== null) {
                $nextDueKm = $baseKm + $intervalKm;
            } elseif ($baseKm !== null) {
                $nextDueKm = $baseKm + 500;
            }

            $daysShift = $intervalDays ?? 15;
            $nextDueDate = null;
            $nextTs = strtotime($baseDate . ' +' . $daysShift . ' days');
            if ($nextTs !== false) {
                $nextDueDate = date('Y-m-d', $nextTs);
            }

            try {
                $postponeStmt->execute([
                    'next_due_km' => $nextDueKm,
                    'next_due_date' => $nextDueDate,
                    'updated_by' => $actorUserId > 0 ? $actorUserId : null,
                    'id' => $reminderId,
                    'company_id' => $companyId,
                    'vehicle_id' => $vehicleId,
                ]);
                $result['postponed_count']++;
            } catch (Throwable $exception) {
                $result['warnings'][] = 'Unable to postpone reminder for ' . ($itemName !== '' ? $itemName : ('Item #' . $itemId)) . '.';
            }
            continue;
        }

        if ($action !== 'add' || $itemType === '' || $itemId <= 0) {
            $result['ignored_count']++;
            continue;
        }

        $lineAdded = false;
        if ($itemType === 'SERVICE') {
            $serviceFetchStmt->execute([
                'id' => $itemId,
                'company_id' => $companyId,
            ]);
            $service = $serviceFetchStmt->fetch() ?: null;
            if (!$service) {
                $result['warnings'][] = 'Suggested service is no longer active: ' . ($itemName !== '' ? $itemName : ('Service #' . $itemId));
                continue;
            }

            $unitPrice = round((float) ($service['default_rate'] ?? 0), 2);
            $gstRate = round((float) ($service['gst_rate'] ?? 0), 2);
            $quantity = 1.0;
            $totalAmount = round($quantity * $unitPrice, 2);
            try {
                $insertLaborStmt->execute([
                    'job_card_id' => $jobId,
                    'service_id' => $itemId,
                    'description' => (string) ($service['service_name'] ?? ($itemName !== '' ? $itemName : 'Service')),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'gst_rate' => $gstRate,
                    'total_amount' => $totalAmount,
                ]);
                $lineAdded = true;
            } catch (Throwable $exception) {
                $result['warnings'][] = 'Unable to add service line for ' . (string) ($service['service_name'] ?? ('Service #' . $itemId)) . '.';
            }
        } elseif ($itemType === 'PART') {
            $partFetchStmt->execute([
                'id' => $itemId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            $part = $partFetchStmt->fetch() ?: null;
            if (!$part) {
                $result['warnings'][] = 'Suggested part is no longer active: ' . ($itemName !== '' ? $itemName : ('Part #' . $itemId));
                continue;
            }

            $unitPrice = round((float) ($part['selling_price'] ?? 0), 2);
            $gstRate = round((float) ($part['gst_rate'] ?? 0), 2);
            $quantity = 1.0;
            $totalAmount = round($quantity * $unitPrice, 2);
            try {
                $insertPartStmt->execute([
                    'job_card_id' => $jobId,
                    'part_id' => $itemId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'gst_rate' => $gstRate,
                    'total_amount' => $totalAmount,
                ]);
                $lineAdded = true;
            } catch (Throwable $exception) {
                $result['warnings'][] = 'Unable to add part line for ' . (string) ($part['part_name'] ?? ('Part #' . $itemId)) . '.';
            }
        }

        if (!$lineAdded) {
            continue;
        }

        $result['added_count']++;
        service_reminder_mark_existing_inactive($pdo, $companyId, $vehicleId, $itemType, $itemId, $actorUserId, 'COMPLETED');

        $nextDueKm = ($odometerKm !== null && $intervalKm !== null) ? ($odometerKm + $intervalKm) : null;
        $nextDueDate = null;
        if ($intervalDays !== null) {
            $nextTs = strtotime($baseDate . ' +' . $intervalDays . ' days');
            if ($nextTs !== false) {
                $nextDueDate = date('Y-m-d', $nextTs);
            }
        }

        if ($nextDueKm !== null || $nextDueDate !== null) {
            try {
                service_reminder_insert_active_reminder(
                    $pdo,
                    [
                        'company_id' => $companyId,
                        'garage_id' => $garageId,
                        'vehicle_id' => $vehicleId,
                        'customer_id' => $customerId,
                        'job_card_id' => $jobId,
                        'rule_id' => (int) ($row['rule_id'] ?? 0),
                        'item_type' => $itemType,
                        'item_id' => $itemId,
                        'item_name' => $itemName !== '' ? $itemName : ($itemType . ' #' . $itemId),
                        'source_type' => 'JOB_CREATE_ADD',
                        'reminder_status' => 'ACTIVE',
                        'recommendation_text' => service_reminder_default_note($itemName, $nextDueKm, $nextDueDate),
                        'last_service_km' => $odometerKm,
                        'last_service_date' => $baseDate,
                        'interval_km' => $intervalKm,
                        'interval_days' => $intervalDays,
                        'next_due_km' => $nextDueKm,
                        'next_due_date' => $nextDueDate,
                        'predicted_next_visit_date' => service_reminder_predict_next_visit_date(
                            $odometerKm,
                            $nextDueKm,
                            $baseDate,
                            $avgKmPerDay,
                            $nextDueDate
                        ),
                        'created_by' => $actorUserId,
                        'updated_by' => $actorUserId,
                    ]
                );
            } catch (Throwable $exception) {
                $result['warnings'][] = 'Unable to regenerate next due after adding ' . ($itemName !== '' ? $itemName : ('Item #' . $itemId)) . '.';
            }
        }
    }

    if ($result['added_count'] > 0) {
        job_recalculate_estimate($jobId);
    }

    return $result;
}

function job_condition_photo_table_exists(bool $refresh = false): bool
{
    static $cached = null;

    if (!$refresh && $cached !== null) {
        return $cached;
    }

    try {
        $stmt = db()->query("SHOW TABLES LIKE 'job_condition_photos'");
        $cached = $stmt !== false && $stmt->fetchColumn() !== false;
    } catch (Throwable $exception) {
        $cached = false;
    }

    return $cached;
}

function job_condition_photo_feature_ready(): bool
{
    if (job_condition_photo_table_exists()) {
        return true;
    }

    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS job_condition_photos (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id INT UNSIGNED NOT NULL,
                garage_id INT UNSIGNED NOT NULL,
                job_card_id INT UNSIGNED NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                file_size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
                image_width INT UNSIGNED DEFAULT NULL,
                image_height INT UNSIGNED DEFAULT NULL,
                note VARCHAR(255) DEFAULT NULL,
                uploaded_by INT UNSIGNED DEFAULT NULL,
                status_code VARCHAR(20) NOT NULL DEFAULT "ACTIVE",
                deleted_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_job_condition_scope_job (company_id, garage_id, job_card_id, status_code),
                KEY idx_job_condition_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $exception) {
        // Non-fatal: feature will be treated as unavailable.
    }

    return job_condition_photo_table_exists(true);
}

function job_condition_photo_base_relative_dir(): string
{
    return 'assets/uploads/job_condition_photos';
}

function job_condition_photo_allowed_mimes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function job_condition_photo_max_upload_bytes(int $companyId, int $garageId): int
{
    $rawValue = system_setting_get_value($companyId, $garageId, 'job_condition_photo_max_mb', '5');
    $sizeMb = (int) trim((string) $rawValue);
    if ($sizeMb < 1) {
        $sizeMb = 1;
    }
    if ($sizeMb > 25) {
        $sizeMb = 25;
    }

    return $sizeMb * 1024 * 1024;
}

function job_condition_photo_retention_days(int $companyId, int $garageId): int
{
    $rawValue = system_setting_get_value($companyId, $garageId, 'job_condition_photo_retention_days', '90');
    $days = (int) trim((string) $rawValue);
    if ($days < 1) {
        $days = 1;
    }
    if ($days > 3650) {
        $days = 3650;
    }

    return $days;
}

function job_condition_photo_build_relative_dir(int $companyId, int $garageId, int $jobId): string
{
    return job_condition_photo_base_relative_dir()
        . '/' . max(0, $companyId)
        . '/' . max(0, $garageId)
        . '/job_' . max(0, $jobId);
}

function job_condition_photo_full_path(string $relativePath): ?string
{
    $normalized = str_replace('\\', '/', trim($relativePath));
    $normalized = ltrim($normalized, '/');
    if ($normalized === '' || str_contains($normalized, '..')) {
        return null;
    }

    $base = job_condition_photo_base_relative_dir() . '/';
    if (!str_starts_with($normalized, $base)) {
        return null;
    }

    return APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
}

function job_condition_photo_file_url(?string $relativePath): ?string
{
    $path = trim((string) $relativePath);
    if ($path === '') {
        return null;
    }

    $fullPath = job_condition_photo_full_path($path);
    if ($fullPath === null || !is_file($fullPath)) {
        return null;
    }

    return url(str_replace('\\', '/', ltrim($path, '/')));
}

function job_condition_photo_delete_file(?string $relativePath): bool
{
    $path = trim((string) $relativePath);
    if ($path === '') {
        return true;
    }

    $fullPath = job_condition_photo_full_path($path);
    if ($fullPath === null) {
        return false;
    }

    if (!is_file($fullPath)) {
        return true;
    }

    return @unlink($fullPath);
}

function job_condition_photo_normalize_uploads(mixed $files): array
{
    if (!is_array($files)) {
        return [];
    }

    $names = $files['name'] ?? null;
    $errors = $files['error'] ?? null;
    $tmpNames = $files['tmp_name'] ?? null;
    $sizes = $files['size'] ?? null;
    $types = $files['type'] ?? null;

    if (!is_array($names) || !is_array($errors) || !is_array($tmpNames) || !is_array($sizes)) {
        return [];
    }

    $normalized = [];
    $count = count($names);
    for ($index = 0; $index < $count; $index++) {
        $normalized[] = [
            'name' => (string) ($names[$index] ?? ''),
            'type' => is_array($types) ? (string) ($types[$index] ?? '') : '',
            'tmp_name' => (string) ($tmpNames[$index] ?? ''),
            'error' => (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($sizes[$index] ?? 0),
        ];
    }

    return $normalized;
}

function job_condition_photo_store_upload(
    array $file,
    int $companyId,
    int $garageId,
    int $jobId,
    int $actorUserId,
    ?string $note = null
): array {
    if (!job_condition_photo_feature_ready()) {
        return ['ok' => false, 'message' => 'Condition photo storage is not ready.'];
    }

    if ($companyId <= 0 || $garageId <= 0 || $jobId <= 0) {
        return ['ok' => false, 'message' => 'Invalid job reference for image upload.'];
    }

    $jobStmt = db()->prepare(
        'SELECT id
         FROM job_cards
         WHERE id = :job_id
           AND company_id = :company_id
           AND garage_id = :garage_id
           AND status_code <> "DELETED"
         LIMIT 1'
    );
    $jobStmt->execute([
        'job_id' => $jobId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    if (!$jobStmt->fetch()) {
        return ['ok' => false, 'message' => 'Job card not found for image upload.'];
    }

    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        $message = match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded image exceeds size limit.',
            UPLOAD_ERR_NO_FILE => 'No image file selected.',
            default => 'Unable to process uploaded image.',
        };
        return ['ok' => false, 'message' => $message];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'message' => 'Uploaded image is not valid.'];
    }

    $maxBytes = job_condition_photo_max_upload_bytes($companyId, $garageId);
    $sizeBytes = (int) ($file['size'] ?? 0);
    if ($sizeBytes <= 0 || $sizeBytes > $maxBytes) {
        return ['ok' => false, 'message' => 'Image size exceeds configured upload limit.'];
    }

    $imageInfo = @getimagesize($tmpPath);
    if (!is_array($imageInfo)) {
        return ['ok' => false, 'message' => 'Only valid image files can be uploaded.'];
    }

    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);
    if ($width <= 0 || $height <= 0 || $width > 12000 || $height > 12000) {
        return ['ok' => false, 'message' => 'Image dimensions are invalid or too large.'];
    }

    $allowedMimes = job_condition_photo_allowed_mimes();
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmpPath) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    if (!isset($allowedMimes[$mime])) {
        return ['ok' => false, 'message' => 'Only JPG, PNG, and WEBP images are allowed.'];
    }

    $relativeDir = job_condition_photo_build_relative_dir($companyId, $garageId, $jobId);
    $fullDir = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
    if (!is_dir($fullDir) && !mkdir($fullDir, 0755, true) && !is_dir($fullDir)) {
        return ['ok' => false, 'message' => 'Unable to prepare photo upload directory.'];
    }

    try {
        $suffix = bin2hex(random_bytes(5));
    } catch (Throwable $exception) {
        $suffix = substr(sha1((string) microtime(true) . (string) mt_rand()), 0, 10);
    }

    $extension = $allowedMimes[$mime];
    $fileName = 'job_' . $jobId . '_condition_' . date('YmdHis') . '_' . $suffix . '.' . $extension;
    $targetPath = $fullDir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return ['ok' => false, 'message' => 'Unable to move uploaded image file.'];
    }

    $relativePath = $relativeDir . '/' . $fileName;
    $safeNote = trim((string) $note);
    if ($safeNote !== '') {
        $safeNote = mb_substr($safeNote, 0, 255);
    }

    try {
        $insertStmt = db()->prepare(
            'INSERT INTO job_condition_photos
              (company_id, garage_id, job_card_id, file_name, file_path, mime_type, file_size_bytes, image_width, image_height, note, uploaded_by, status_code, deleted_at)
             VALUES
              (:company_id, :garage_id, :job_card_id, :file_name, :file_path, :mime_type, :file_size_bytes, :image_width, :image_height, :note, :uploaded_by, "ACTIVE", NULL)'
        );
        $insertStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'job_card_id' => $jobId,
            'file_name' => $fileName,
            'file_path' => $relativePath,
            'mime_type' => $mime,
            'file_size_bytes' => $sizeBytes,
            'image_width' => $width,
            'image_height' => $height,
            'note' => $safeNote !== '' ? $safeNote : null,
            'uploaded_by' => $actorUserId > 0 ? $actorUserId : null,
        ]);
        $photoId = (int) db()->lastInsertId();
    } catch (Throwable $exception) {
        @unlink($targetPath);
        return ['ok' => false, 'message' => 'Unable to save uploaded image metadata.'];
    }

    return [
        'ok' => true,
        'photo_id' => $photoId,
        'file_path' => $relativePath,
        'file_size_bytes' => $sizeBytes,
        'image_width' => $width,
        'image_height' => $height,
    ];
}

function job_condition_photo_fetch_by_job(int $companyId, int $garageId, int $jobId, int $limit = 200): array
{
    if (!job_condition_photo_feature_ready() || $companyId <= 0 || $garageId <= 0 || $jobId <= 0) {
        return [];
    }

    $safeLimit = max(1, min(500, $limit));
    $stmt = db()->prepare(
        'SELECT jp.*, u.name AS uploaded_by_name
         FROM job_condition_photos jp
         LEFT JOIN users u ON u.id = jp.uploaded_by
         WHERE jp.company_id = :company_id
           AND jp.garage_id = :garage_id
           AND jp.job_card_id = :job_card_id
           AND jp.status_code = "ACTIVE"
         ORDER BY jp.id DESC
         LIMIT ' . $safeLimit
    );
    $stmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'job_card_id' => $jobId,
    ]);

    return $stmt->fetchAll();
}

function job_condition_photo_counts_by_job(int $companyId, int $garageId, array $jobIds): array
{
    if (!job_condition_photo_feature_ready() || $companyId <= 0 || $garageId <= 0) {
        return [];
    }

    $jobIds = array_values(array_unique(array_filter(array_map('intval', $jobIds), static fn (int $id): bool => $id > 0)));
    if ($jobIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
    $sql =
        'SELECT job_card_id, COUNT(*) AS photo_count
         FROM job_condition_photos
         WHERE company_id = ?
           AND garage_id = ?
           AND status_code = "ACTIVE"
           AND job_card_id IN (' . $placeholders . ')
         GROUP BY job_card_id';
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge([$companyId, $garageId], $jobIds));

    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(int) ($row['job_card_id'] ?? 0)] = (int) ($row['photo_count'] ?? 0);
    }

    return $counts;
}

function job_condition_photo_scope_stats(int $companyId, int $garageId): array
{
    if (!job_condition_photo_feature_ready() || $companyId <= 0 || $garageId <= 0) {
        return ['photo_count' => 0, 'storage_bytes' => 0];
    }

    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS photo_count,
                    COALESCE(SUM(file_size_bytes), 0) AS storage_bytes
             FROM job_condition_photos
             WHERE company_id = :company_id
               AND garage_id = :garage_id
               AND status_code = "ACTIVE"'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $row = $stmt->fetch() ?: [];
    } catch (Throwable $exception) {
        return ['photo_count' => 0, 'storage_bytes' => 0];
    }

    return [
        'photo_count' => (int) ($row['photo_count'] ?? 0),
        'storage_bytes' => (int) ($row['storage_bytes'] ?? 0),
    ];
}

function job_condition_photo_delete(int $photoId, int $companyId, int $garageId): array
{
    if (!job_condition_photo_feature_ready() || $photoId <= 0 || $companyId <= 0 || $garageId <= 0) {
        return ['ok' => false, 'message' => 'Invalid image delete request.'];
    }

    $selectStmt = db()->prepare(
        'SELECT id, file_path
         FROM job_condition_photos
         WHERE id = :id
           AND company_id = :company_id
           AND garage_id = :garage_id
           AND status_code = "ACTIVE"
         LIMIT 1'
    );
    $selectStmt->execute([
        'id' => $photoId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $photo = $selectStmt->fetch();
    if (!$photo) {
        return ['ok' => false, 'message' => 'Photo not found or already deleted.'];
    }

    $deleteStmt = db()->prepare(
        'UPDATE job_condition_photos
         SET status_code = "DELETED",
             deleted_at = NOW()
         WHERE id = :id
           AND company_id = :company_id
           AND garage_id = :garage_id'
    );
    $deleteStmt->execute([
        'id' => $photoId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);

    $fileDeleted = job_condition_photo_delete_file((string) ($photo['file_path'] ?? ''));

    return [
        'ok' => true,
        'file_deleted' => $fileDeleted,
    ];
}

function job_condition_photo_purge_older_than(int $companyId, int $garageId, int $retentionDays): array
{
    if (!job_condition_photo_feature_ready() || $companyId <= 0 || $garageId <= 0) {
        return [
            'ok' => false,
            'deleted_count' => 0,
            'failed_count' => 0,
            'deleted_bytes' => 0,
            'message' => 'Condition photo storage is not ready.',
        ];
    }

    $retentionDays = max(1, min(3650, $retentionDays));
    $cutoff = date('Y-m-d H:i:s', strtotime('-' . $retentionDays . ' days'));

    $deletedCount = 0;
    $failedCount = 0;
    $deletedBytes = 0;
    $batchSize = 200;

    while (true) {
        $fetchStmt = db()->prepare(
            'SELECT id, file_path, file_size_bytes
             FROM job_condition_photos
             WHERE company_id = :company_id
               AND garage_id = :garage_id
               AND status_code = "ACTIVE"
               AND created_at < :cutoff
             ORDER BY id ASC
             LIMIT ' . $batchSize
        );
        $fetchStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'cutoff' => $cutoff,
        ]);
        $rows = $fetchStmt->fetchAll();
        if ($rows === []) {
            break;
        }

        $ids = [];
        foreach ($rows as $row) {
            $photoId = (int) ($row['id'] ?? 0);
            if ($photoId <= 0) {
                continue;
            }

            $ids[] = $photoId;
            if (job_condition_photo_delete_file((string) ($row['file_path'] ?? ''))) {
                $deletedCount++;
                $deletedBytes += max(0, (int) ($row['file_size_bytes'] ?? 0));
            } else {
                $failedCount++;
            }
        }

        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $updateSql =
                'UPDATE job_condition_photos
                 SET status_code = "DELETED",
                     deleted_at = NOW()
                 WHERE company_id = ?
                   AND garage_id = ?
                   AND id IN (' . $placeholders . ')';
            $updateStmt = db()->prepare($updateSql);
            $updateStmt->execute(array_merge([$companyId, $garageId], $ids));
        }

        if (count($rows) < $batchSize) {
            break;
        }
    }

    return [
        'ok' => true,
        'deleted_count' => $deletedCount,
        'failed_count' => $failedCount,
        'deleted_bytes' => $deletedBytes,
        'message' => 'Condition photo cleanup completed.',
    ];
}
