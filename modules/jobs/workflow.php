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
        db()->exec(
            'CREATE TABLE IF NOT EXISTS vis_service_interval_rules (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id INT UNSIGNED NOT NULL,
                vis_variant_id INT UNSIGNED NOT NULL,
                engine_oil_interval_km INT UNSIGNED DEFAULT NULL,
                major_service_interval_km INT UNSIGNED DEFAULT NULL,
                brake_inspection_interval_km INT UNSIGNED DEFAULT NULL,
                ac_service_interval_km INT UNSIGNED DEFAULT NULL,
                interval_days INT UNSIGNED DEFAULT NULL,
                status_code VARCHAR(20) NOT NULL DEFAULT "ACTIVE",
                created_by INT UNSIGNED DEFAULT NULL,
                updated_by INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_vis_service_interval_scope_variant (company_id, vis_variant_id),
                KEY idx_vis_service_interval_status (company_id, status_code),
                KEY idx_vis_service_interval_variant (vis_variant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        db()->exec(
            'CREATE TABLE IF NOT EXISTS service_reminders (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id INT UNSIGNED NOT NULL,
                garage_id INT UNSIGNED NOT NULL,
                vehicle_id INT UNSIGNED NOT NULL,
                job_card_id INT UNSIGNED DEFAULT NULL,
                source_type VARCHAR(20) NOT NULL DEFAULT "AUTO",
                service_type VARCHAR(40) NOT NULL,
                service_label VARCHAR(120) NOT NULL,
                recommendation_text VARCHAR(255) DEFAULT NULL,
                last_service_km INT UNSIGNED DEFAULT NULL,
                interval_km INT UNSIGNED DEFAULT NULL,
                next_due_km INT UNSIGNED DEFAULT NULL,
                interval_days INT UNSIGNED DEFAULT NULL,
                next_due_date DATE DEFAULT NULL,
                predicted_next_visit_date DATE DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                status_code VARCHAR(20) NOT NULL DEFAULT "ACTIVE",
                created_by INT UNSIGNED DEFAULT NULL,
                updated_by INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_service_reminder_vehicle_active (company_id, vehicle_id, is_active, status_code),
                KEY idx_service_reminder_scope (company_id, garage_id, is_active, status_code),
                KEY idx_service_reminder_due_date (company_id, next_due_date),
                KEY idx_service_reminder_due_km (company_id, next_due_km),
                KEY idx_service_reminder_type (company_id, service_type, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $exception) {
        $ready = false;
        return false;
    }

    $ready = service_reminder_table_exists('vis_service_interval_rules', true)
        && service_reminder_table_exists('service_reminders', true);

    return $ready;
}

function service_reminder_supported_types(): array
{
    return ['ENGINE_OIL', 'MAJOR_SERVICE', 'BRAKE_INSPECTION', 'AC_SERVICE'];
}

function service_reminder_type_label(string $serviceType): string
{
    return match (service_reminder_normalize_type($serviceType)) {
        'ENGINE_OIL' => 'Engine Oil',
        'MAJOR_SERVICE' => 'Major Service',
        'BRAKE_INSPECTION' => 'Brake Inspection',
        'AC_SERVICE' => 'AC Service',
        default => 'Service',
    };
}

function service_reminder_normalize_type(?string $serviceType): string
{
    $normalized = strtoupper(trim((string) $serviceType));
    return in_array($normalized, service_reminder_supported_types(), true) ? $normalized : '';
}

function service_reminder_interval_column_map(): array
{
    return [
        'ENGINE_OIL' => 'engine_oil_interval_km',
        'MAJOR_SERVICE' => 'major_service_interval_km',
        'BRAKE_INSPECTION' => 'brake_inspection_interval_km',
        'AC_SERVICE' => 'ac_service_interval_km',
    ];
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

function service_reminder_interval_rule_for_vehicle(int $companyId, int $vehicleId): ?array
{
    if ($companyId <= 0 || $vehicleId <= 0 || !service_reminder_feature_ready()) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT v.id AS vehicle_id, v.registration_no, v.brand, v.model, v.variant, v.vis_variant_id,
                r.engine_oil_interval_km, r.major_service_interval_km, r.brake_inspection_interval_km,
                r.ac_service_interval_km, r.interval_days
         FROM vehicles v
         LEFT JOIN vis_service_interval_rules r
           ON r.company_id = :company_id
          AND r.vis_variant_id = v.vis_variant_id
          AND r.status_code = "ACTIVE"
         WHERE v.id = :vehicle_id
           AND v.company_id = :company_id
           AND v.status_code <> "DELETED"
         LIMIT 1'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
    ]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $intervalsKm = [];
    foreach (service_reminder_interval_column_map() as $serviceType => $columnName) {
        $intervalsKm[$serviceType] = service_reminder_parse_positive_int($row[$columnName] ?? null);
    }

    $intervalDays = service_reminder_parse_positive_int($row['interval_days'] ?? null);
    $hasRule = $intervalDays !== null;
    foreach ($intervalsKm as $intervalKm) {
        if ($intervalKm !== null) {
            $hasRule = true;
            break;
        }
    }

    return [
        'vehicle_id' => (int) ($row['vehicle_id'] ?? 0),
        'vis_variant_id' => (int) ($row['vis_variant_id'] ?? 0),
        'registration_no' => (string) ($row['registration_no'] ?? ''),
        'brand' => (string) ($row['brand'] ?? ''),
        'model' => (string) ($row['model'] ?? ''),
        'variant' => (string) ($row['variant'] ?? ''),
        'intervals_km' => $intervalsKm,
        'interval_days' => $intervalDays,
        'has_rule' => $hasRule,
    ];
}

function service_reminder_detect_performed_types(int $jobId): array
{
    if ($jobId <= 0) {
        return [];
    }

    $detected = [];
    $keywordMap = [
        'ENGINE_OIL' => [
            'engine oil',
            'oil service',
            'oil change',
            'oil filter',
            'lubricant',
        ],
        'MAJOR_SERVICE' => [
            'major service',
            'periodic service',
            'full service',
            'scheduled service',
            'comprehensive service',
        ],
        'BRAKE_INSPECTION' => [
            'brake',
            'brake pad',
            'brake shoe',
            'rotor',
            'caliper',
            'brake oil',
        ],
        'AC_SERVICE' => [
            'ac service',
            'a/c service',
            'air conditioning',
            'ac gas',
            'compressor',
            'condenser',
            'evaporator',
            'cabin filter',
        ],
    ];

    try {
        $laborStmt = db()->prepare(
            'SELECT jl.description, s.service_name, s.service_code, sc.category_name
             FROM job_labor jl
             LEFT JOIN services s ON s.id = jl.service_id
             LEFT JOIN service_categories sc ON sc.id = s.category_id AND sc.company_id = s.company_id
             WHERE jl.job_card_id = :job_id'
        );
        $laborStmt->execute(['job_id' => $jobId]);
        $laborRows = $laborStmt->fetchAll();
    } catch (Throwable $exception) {
        $laborRows = [];
    }

    $haystacks = [];
    foreach ($laborRows as $row) {
        $chunks = [
            (string) ($row['description'] ?? ''),
            (string) ($row['service_name'] ?? ''),
            (string) ($row['service_code'] ?? ''),
            (string) ($row['category_name'] ?? ''),
        ];
        $combined = strtolower(trim(preg_replace('/\s+/', ' ', implode(' ', $chunks)) ?? ''));
        if ($combined !== '') {
            $haystacks[] = ' ' . $combined . ' ';
        }

        $code = strtoupper(trim((string) ($row['service_code'] ?? '')));
        if ($code !== '') {
            if (str_contains($code, 'EO') || str_contains($code, 'OIL')) {
                $detected['ENGINE_OIL'] = true;
            }
            if (str_contains($code, 'MS') || str_contains($code, 'MAJOR')) {
                $detected['MAJOR_SERVICE'] = true;
            }
            if (str_contains($code, 'BRK') || str_contains($code, 'BRAKE')) {
                $detected['BRAKE_INSPECTION'] = true;
            }
            if (str_contains($code, 'AC')) {
                $detected['AC_SERVICE'] = true;
            }
        }
    }

    try {
        $partsStmt = db()->prepare(
            'SELECT p.part_name, p.part_sku
             FROM job_parts jp
             INNER JOIN parts p ON p.id = jp.part_id
             WHERE jp.job_card_id = :job_id'
        );
        $partsStmt->execute(['job_id' => $jobId]);
        $partRows = $partsStmt->fetchAll();
    } catch (Throwable $exception) {
        $partRows = [];
    }

    foreach ($partRows as $row) {
        $combined = strtolower(
            trim(
                preg_replace(
                    '/\s+/',
                    ' ',
                    (string) (($row['part_name'] ?? '') . ' ' . ($row['part_sku'] ?? ''))
                ) ?? ''
            )
        );
        if ($combined !== '') {
            $haystacks[] = ' ' . $combined . ' ';
        }
    }

    foreach ($haystacks as $haystack) {
        foreach ($keywordMap as $serviceType => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($haystack, strtolower($keyword)) !== false) {
                    $detected[$serviceType] = true;
                    break;
                }
            }
        }
    }

    $ordered = [];
    foreach (service_reminder_supported_types() as $type) {
        if (!empty($detected[$type])) {
            $ordered[] = $type;
        }
    }

    return $ordered;
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
        'SELECT jc.id, jc.vehicle_id, jc.status, jc.opened_at, jc.completed_at, jc.closed_at, ' . $odometerSelect . '
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
    $label = service_reminder_type_label($serviceType);
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
    if (!service_reminder_feature_ready()) {
        return [];
    }

    $jobContext = service_reminder_job_context($jobId, $companyId, $garageId);
    if (!$jobContext || (int) ($jobContext['vehicle_id'] ?? 0) <= 0) {
        return [];
    }

    $rule = service_reminder_interval_rule_for_vehicle($companyId, (int) $jobContext['vehicle_id']);
    if (!$rule || empty($rule['has_rule'])) {
        return [];
    }

    $performedTypes = service_reminder_detect_performed_types($jobId);
    if ($performedTypes === []) {
        return [];
    }

    $usageProfile = service_reminder_vehicle_usage_profile($companyId, (int) $jobContext['vehicle_id']);
    $avgKmPerDay = isset($usageProfile['avg_km_per_day']) && is_numeric((string) $usageProfile['avg_km_per_day'])
        ? (float) $usageProfile['avg_km_per_day']
        : null;

    $serviceDate = service_reminder_extract_date((string) ($jobContext['closed_at'] ?? ''))
        ?? service_reminder_extract_date((string) ($jobContext['completed_at'] ?? ''))
        ?? service_reminder_extract_date((string) ($jobContext['opened_at'] ?? ''))
        ?? date('Y-m-d');

    $previewRows = [];
    foreach ($performedTypes as $serviceType) {
        $intervalKm = service_reminder_parse_positive_int(($rule['intervals_km'][$serviceType] ?? null));
        $intervalDays = service_reminder_parse_positive_int($rule['interval_days'] ?? null);
        if ($intervalKm === null && $intervalDays === null) {
            continue;
        }

        $lastServiceKm = service_reminder_parse_positive_int($jobContext['odometer_km'] ?? null);
        $nextDueKm = ($lastServiceKm !== null && $intervalKm !== null) ? ($lastServiceKm + $intervalKm) : null;
        $nextDueDate = null;
        if ($intervalDays !== null) {
            $nextDueTs = strtotime($serviceDate . ' +' . $intervalDays . ' days');
            if ($nextDueTs !== false) {
                $nextDueDate = date('Y-m-d', $nextDueTs);
            }
        }

        $predictedVisitDate = service_reminder_predict_next_visit_date(
            $lastServiceKm,
            $nextDueKm,
            $serviceDate,
            $avgKmPerDay,
            $nextDueDate
        );

        $previewRows[] = [
            'service_type' => $serviceType,
            'service_label' => service_reminder_type_label($serviceType),
            'enabled' => true,
            'last_service_km' => $lastServiceKm,
            'interval_km' => $intervalKm,
            'next_due_km' => $nextDueKm,
            'interval_days' => $intervalDays,
            'next_due_date' => $nextDueDate,
            'predicted_next_visit_date' => $predictedVisitDate,
            'service_date' => $serviceDate,
            'recommendation_text' => service_reminder_default_note($serviceType, $nextDueKm, $nextDueDate),
        ];
    }

    return $previewRows;
}

function service_reminder_parse_close_override_payload(array $postData, array $previewRows): array
{
    $enabledInput = is_array($postData['reminder_enabled'] ?? null) ? (array) $postData['reminder_enabled'] : [];
    $lastKmInput = is_array($postData['reminder_last_km'] ?? null) ? (array) $postData['reminder_last_km'] : [];
    $intervalKmInput = is_array($postData['reminder_interval_km'] ?? null) ? (array) $postData['reminder_interval_km'] : [];
    $nextDueKmInput = is_array($postData['reminder_next_due_km'] ?? null) ? (array) $postData['reminder_next_due_km'] : [];
    $intervalDaysInput = is_array($postData['reminder_interval_days'] ?? null) ? (array) $postData['reminder_interval_days'] : [];
    $nextDueDateInput = is_array($postData['reminder_next_due_date'] ?? null) ? (array) $postData['reminder_next_due_date'] : [];
    $notesInput = is_array($postData['reminder_note'] ?? null) ? (array) $postData['reminder_note'] : [];

    $result = [];
    foreach ($previewRows as $row) {
        $serviceType = service_reminder_normalize_type((string) ($row['service_type'] ?? ''));
        if ($serviceType === '') {
            continue;
        }

        $enabledRaw = strtolower(trim((string) ($enabledInput[$serviceType] ?? '1')));
        $enabled = in_array($enabledRaw, ['1', 'yes', 'true', 'on'], true);

        $lastServiceKm = service_reminder_parse_positive_int($lastKmInput[$serviceType] ?? ($row['last_service_km'] ?? null));
        $intervalKm = service_reminder_parse_positive_int($intervalKmInput[$serviceType] ?? ($row['interval_km'] ?? null));
        $nextDueKm = service_reminder_parse_positive_int($nextDueKmInput[$serviceType] ?? ($row['next_due_km'] ?? null));
        $intervalDays = service_reminder_parse_positive_int($intervalDaysInput[$serviceType] ?? ($row['interval_days'] ?? null));

        $serviceDate = service_reminder_extract_date((string) ($row['service_date'] ?? '')) ?? date('Y-m-d');
        if ($nextDueKm === null && $lastServiceKm !== null && $intervalKm !== null) {
            $nextDueKm = $lastServiceKm + $intervalKm;
        }

        $nextDueDate = service_reminder_parse_date((string) ($nextDueDateInput[$serviceType] ?? ($row['next_due_date'] ?? '')));
        if ($nextDueDate === null && $intervalDays !== null) {
            $nextDueTs = strtotime($serviceDate . ' +' . $intervalDays . ' days');
            if ($nextDueTs !== false) {
                $nextDueDate = date('Y-m-d', $nextDueTs);
            }
        }

        $note = trim((string) ($notesInput[$serviceType] ?? ($row['recommendation_text'] ?? '')));
        if ($note === '') {
            $note = service_reminder_default_note($serviceType, $nextDueKm, $nextDueDate);
        }

        $result[$serviceType] = [
            'enabled' => $enabled,
            'service_type' => $serviceType,
            'service_label' => service_reminder_type_label($serviceType),
            'last_service_km' => $lastServiceKm,
            'interval_km' => $intervalKm,
            'next_due_km' => $nextDueKm,
            'interval_days' => $intervalDays,
            'next_due_date' => $nextDueDate,
            'service_date' => $serviceDate,
            'recommendation_text' => mb_substr($note, 0, 255),
        ];
    }

    return $result;
}

function service_reminder_mark_existing_inactive(
    PDO $pdo,
    int $companyId,
    int $vehicleId,
    string $serviceType,
    int $actorUserId
): void {
    $normalizedType = service_reminder_normalize_type($serviceType);
    if ($companyId <= 0 || $vehicleId <= 0 || $normalizedType === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE service_reminders
         SET is_active = 0,
             status_code = "INACTIVE",
             updated_by = :updated_by
         WHERE company_id = :company_id
           AND vehicle_id = :vehicle_id
           AND service_type = :service_type
           AND is_active = 1
           AND status_code = "ACTIVE"'
    );
    $stmt->execute([
        'updated_by' => $actorUserId > 0 ? $actorUserId : null,
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
        'service_type' => $normalizedType,
    ]);
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
        $result['warnings'][] = 'Service reminder storage is not ready.';
        return $result;
    }

    $jobContext = service_reminder_job_context($jobId, $companyId, $garageId);
    if (!$jobContext || (int) ($jobContext['vehicle_id'] ?? 0) <= 0) {
        $result['warnings'][] = 'Job context missing for reminder generation.';
        return $result;
    }

    $previewRows = service_reminder_build_preview_for_job($companyId, $garageId, $jobId);
    $result['preview_rows'] = $previewRows;
    if ($previewRows === []) {
        return $result;
    }

    $usageProfile = service_reminder_vehicle_usage_profile($companyId, (int) $jobContext['vehicle_id']);
    $avgKmPerDay = isset($usageProfile['avg_km_per_day']) && is_numeric((string) $usageProfile['avg_km_per_day'])
        ? (float) $usageProfile['avg_km_per_day']
        : null;

    $fallbackServiceDate = service_reminder_extract_date((string) ($jobContext['closed_at'] ?? ''))
        ?? service_reminder_extract_date((string) ($jobContext['completed_at'] ?? ''))
        ?? service_reminder_extract_date((string) ($jobContext['opened_at'] ?? ''))
        ?? date('Y-m-d');

    $pdo = db();
    $insertStmt = $pdo->prepare(
        'INSERT INTO service_reminders
          (company_id, garage_id, vehicle_id, job_card_id, source_type, service_type, service_label,
           recommendation_text, last_service_km, interval_km, next_due_km, interval_days, next_due_date,
           predicted_next_visit_date, is_active, status_code, created_by, updated_by)
         VALUES
          (:company_id, :garage_id, :vehicle_id, :job_card_id, "AUTO", :service_type, :service_label,
           :recommendation_text, :last_service_km, :interval_km, :next_due_km, :interval_days, :next_due_date,
           :predicted_next_visit_date, 1, "ACTIVE", :created_by, :updated_by)'
    );

    $pdo->beginTransaction();
    try {
        foreach ($previewRows as $previewRow) {
            $serviceType = service_reminder_normalize_type((string) ($previewRow['service_type'] ?? ''));
            if ($serviceType === '') {
                continue;
            }

            $override = is_array($overridesByType[$serviceType] ?? null) ? (array) $overridesByType[$serviceType] : [];
            $enabled = array_key_exists('enabled', $override) ? (bool) $override['enabled'] : (bool) ($previewRow['enabled'] ?? true);

            $lastServiceKm = service_reminder_parse_positive_int($override['last_service_km'] ?? ($previewRow['last_service_km'] ?? null));
            $intervalKm = service_reminder_parse_positive_int($override['interval_km'] ?? ($previewRow['interval_km'] ?? null));
            $nextDueKm = service_reminder_parse_positive_int($override['next_due_km'] ?? ($previewRow['next_due_km'] ?? null));
            $intervalDays = service_reminder_parse_positive_int($override['interval_days'] ?? ($previewRow['interval_days'] ?? null));
            $serviceDate = service_reminder_extract_date((string) ($override['service_date'] ?? ($previewRow['service_date'] ?? '')))
                ?? $fallbackServiceDate;
            $nextDueDate = service_reminder_parse_date((string) ($override['next_due_date'] ?? ($previewRow['next_due_date'] ?? '')));

            if ($nextDueKm === null && $lastServiceKm !== null && $intervalKm !== null) {
                $nextDueKm = $lastServiceKm + $intervalKm;
            }
            if ($nextDueDate === null && $intervalDays !== null) {
                $nextDueTs = strtotime($serviceDate . ' +' . $intervalDays . ' days');
                if ($nextDueTs !== false) {
                    $nextDueDate = date('Y-m-d', $nextDueTs);
                }
            }

            if (!$enabled) {
                service_reminder_mark_existing_inactive($pdo, $companyId, (int) $jobContext['vehicle_id'], $serviceType, $actorUserId);
                $result['disabled_count']++;
                continue;
            }

            if ($nextDueKm === null && $nextDueDate === null) {
                $result['warnings'][] = 'Skipped ' . service_reminder_type_label($serviceType) . ' reminder due to empty due KM/date.';
                continue;
            }

            $predictedNextVisitDate = service_reminder_predict_next_visit_date(
                $lastServiceKm,
                $nextDueKm,
                $serviceDate,
                $avgKmPerDay,
                $nextDueDate
            );
            $recommendation = trim((string) ($override['recommendation_text'] ?? ($previewRow['recommendation_text'] ?? '')));
            if ($recommendation === '') {
                $recommendation = service_reminder_default_note($serviceType, $nextDueKm, $nextDueDate);
            }

            service_reminder_mark_existing_inactive($pdo, $companyId, (int) $jobContext['vehicle_id'], $serviceType, $actorUserId);

            $insertStmt->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'vehicle_id' => (int) $jobContext['vehicle_id'],
                'job_card_id' => $jobId,
                'service_type' => $serviceType,
                'service_label' => service_reminder_type_label($serviceType),
                'recommendation_text' => mb_substr($recommendation, 0, 255),
                'last_service_km' => $lastServiceKm,
                'interval_km' => $intervalKm,
                'next_due_km' => $nextDueKm,
                'interval_days' => $intervalDays,
                'next_due_date' => $nextDueDate,
                'predicted_next_visit_date' => $predictedNextVisitDate,
                'created_by' => $actorUserId > 0 ? $actorUserId : null,
                'updated_by' => $actorUserId > 0 ? $actorUserId : null,
            ]);

            $result['created_count']++;
            $result['created_types'][] = $serviceType;
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $result['warnings'][] = 'Service reminder auto-generation failed: ' . $exception->getMessage();
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
    if (!service_reminder_feature_ready()) {
        return ['ok' => false, 'message' => 'Service reminder storage is not ready.'];
    }

    $normalizedType = service_reminder_normalize_type($serviceType);
    if ($companyId <= 0 || $garageId <= 0 || $vehicleId <= 0 || $normalizedType === '') {
        return ['ok' => false, 'message' => 'Invalid service reminder payload.'];
    }

    $lastServiceKm = service_reminder_parse_positive_int($lastServiceKm);
    $intervalKm = service_reminder_parse_positive_int($intervalKm);
    $nextDueKm = service_reminder_parse_positive_int($nextDueKm);
    $intervalDays = service_reminder_parse_positive_int($intervalDays);
    $nextDueDate = service_reminder_parse_date($nextDueDate);

    if ($nextDueKm === null && $lastServiceKm !== null && $intervalKm !== null) {
        $nextDueKm = $lastServiceKm + $intervalKm;
    }
    if ($nextDueDate === null && $intervalDays !== null) {
        $baseDate = date('Y-m-d');
        if ($jobId > 0) {
            $jobContext = service_reminder_job_context($jobId, $companyId, $garageId);
            $baseDate = service_reminder_extract_date((string) ($jobContext['closed_at'] ?? ''))
                ?? service_reminder_extract_date((string) ($jobContext['completed_at'] ?? ''))
                ?? service_reminder_extract_date((string) ($jobContext['opened_at'] ?? ''))
                ?? $baseDate;
        }
        $nextDateTs = strtotime($baseDate . ' +' . $intervalDays . ' days');
        if ($nextDateTs !== false) {
            $nextDueDate = date('Y-m-d', $nextDateTs);
        }
    }

    if ($nextDueKm === null && $nextDueDate === null) {
        return ['ok' => false, 'message' => 'Enter next due KM or next due date for reminder.'];
    }

    $usageProfile = service_reminder_vehicle_usage_profile($companyId, $vehicleId);
    $avgKmPerDay = isset($usageProfile['avg_km_per_day']) && is_numeric((string) $usageProfile['avg_km_per_day'])
        ? (float) $usageProfile['avg_km_per_day']
        : null;
    $predictedNextVisitDate = service_reminder_predict_next_visit_date(
        $lastServiceKm,
        $nextDueKm,
        date('Y-m-d'),
        $avgKmPerDay,
        $nextDueDate
    );

    $note = trim($recommendationText);
    if ($note === '') {
        $note = service_reminder_default_note($normalizedType, $nextDueKm, $nextDueDate);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        service_reminder_mark_existing_inactive($pdo, $companyId, $vehicleId, $normalizedType, $actorUserId);

        $insertStmt = $pdo->prepare(
            'INSERT INTO service_reminders
              (company_id, garage_id, vehicle_id, job_card_id, source_type, service_type, service_label,
               recommendation_text, last_service_km, interval_km, next_due_km, interval_days, next_due_date,
               predicted_next_visit_date, is_active, status_code, created_by, updated_by)
             VALUES
              (:company_id, :garage_id, :vehicle_id, :job_card_id, "MANUAL", :service_type, :service_label,
               :recommendation_text, :last_service_km, :interval_km, :next_due_km, :interval_days, :next_due_date,
               :predicted_next_visit_date, 1, "ACTIVE", :created_by, :updated_by)'
        );
        $insertStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'vehicle_id' => $vehicleId,
            'job_card_id' => $jobId > 0 ? $jobId : null,
            'service_type' => $normalizedType,
            'service_label' => service_reminder_type_label($normalizedType),
            'recommendation_text' => mb_substr($note, 0, 255),
            'last_service_km' => $lastServiceKm,
            'interval_km' => $intervalKm,
            'next_due_km' => $nextDueKm,
            'interval_days' => $intervalDays,
            'next_due_date' => $nextDueDate,
            'predicted_next_visit_date' => $predictedNextVisitDate,
            'created_by' => $actorUserId > 0 ? $actorUserId : null,
            'updated_by' => $actorUserId > 0 ? $actorUserId : null,
        ]);

        $createdId = (int) $pdo->lastInsertId();
        $pdo->commit();

        return ['ok' => true, 'id' => $createdId];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Unable to save manual reminder: ' . $exception->getMessage()];
    }
}

function service_reminder_due_state(array $reminder): string
{
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
        return 'DUE_SOON';
    }

    if ($nextDueDate !== null || $nextDueKm !== null) {
        return 'UPCOMING';
    }

    return 'UNSCHEDULED';
}

function service_reminder_due_badge_class(string $dueState): string
{
    $normalized = strtoupper(trim($dueState));
    return match ($normalized) {
        'OVERDUE' => 'danger',
        'DUE_SOON' => 'warning',
        'UPCOMING' => 'info',
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
        $scopeSql = ' AND sr.garage_id = :garage_id ';
        $params['garage_id'] = $garageId;
    }

    $stmt = db()->prepare(
        'SELECT sr.*, ' . $odometerSelect . ',
                v.registration_no, v.brand, v.model, v.variant,
                jc.job_number
         FROM service_reminders sr
         INNER JOIN vehicles v ON v.id = sr.vehicle_id
         LEFT JOIN job_cards jc ON jc.id = sr.job_card_id
         WHERE sr.company_id = :company_id
           AND sr.vehicle_id = :vehicle_id
           AND sr.is_active = 1
           AND sr.status_code = "ACTIVE"
           ' . $scopeSql . '
         ORDER BY
           CASE WHEN sr.next_due_date IS NULL THEN 1 ELSE 0 END ASC,
           sr.next_due_date ASC,
           CASE WHEN sr.next_due_km IS NULL THEN 1 ELSE 0 END ASC,
           sr.next_due_km ASC,
           sr.id DESC
         LIMIT ' . $safeLimit
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as $index => $row) {
        $dueState = service_reminder_due_state($row);
        $rows[$index]['due_state'] = $dueState;
        $rows[$index]['due_badge_class'] = service_reminder_due_badge_class($dueState);
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
        $scopeSql = ' AND sr.garage_id = :garage_id ';
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
        $scopeSql = ' AND sr.garage_id IN (' . implode(', ', $placeholders) . ') ';
    }

    $typeSql = '';
    $normalizedType = service_reminder_normalize_type($serviceType);
    if ($normalizedType !== '') {
        $typeSql = ' AND sr.service_type = :service_type ';
        $params['service_type'] = $normalizedType;
    }

    $safeLimit = max(1, min(1000, $limit));
    $queryLimit = max($safeLimit, min(3000, $safeLimit * 3));

    $stmt = db()->prepare(
        'SELECT sr.*, ' . $odometerSelect . ',
                v.registration_no, v.brand, v.model, v.variant,
                jc.job_number
         FROM service_reminders sr
         INNER JOIN vehicles v ON v.id = sr.vehicle_id
         LEFT JOIN job_cards jc ON jc.id = sr.job_card_id
         WHERE sr.company_id = :company_id
           AND sr.is_active = 1
           AND sr.status_code = "ACTIVE"
           ' . $scopeSql . '
           ' . $typeSql . '
         ORDER BY
           CASE WHEN sr.next_due_date IS NULL THEN 1 ELSE 0 END ASC,
           sr.next_due_date ASC,
           CASE WHEN sr.next_due_km IS NULL THEN 1 ELSE 0 END ASC,
           sr.next_due_km ASC,
           sr.id DESC
         LIMIT ' . $queryLimit
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $dueStateFilter = strtoupper(trim((string) $dueState));
    if ($dueStateFilter === 'ALL') {
        $dueStateFilter = '';
    }

    $result = [];
    foreach ($rows as $row) {
        $rowDueState = service_reminder_due_state($row);
        if ($dueStateFilter !== '' && $rowDueState !== $dueStateFilter) {
            continue;
        }

        $row['due_state'] = $rowDueState;
        $row['due_badge_class'] = service_reminder_due_badge_class($rowDueState);
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
        'unscheduled' => 0,
    ];

    foreach ($rows as $row) {
        $summary['total']++;
        $state = strtoupper(trim((string) ($row['due_state'] ?? service_reminder_due_state((array) $row))));
        if ($state === 'OVERDUE') {
            $summary['overdue']++;
        } elseif ($state === 'DUE_SOON') {
            $summary['due_soon']++;
        } elseif ($state === 'UPCOMING') {
            $summary['upcoming']++;
        } else {
            $summary['unscheduled']++;
        }
    }

    return $summary;
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
