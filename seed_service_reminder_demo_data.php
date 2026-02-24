<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/modules/jobs/workflow.php';

final class VehicleMaintenanceDemoSeeder
{
    private PDO $pdo;
    private int $companyId = 1;
    private int $actorUserId = 1;

    /** @var array<int,int> */
    private array $garageIds = [1];

    /** @var array<int,array{id:int,item_name:string}> */
    private array $serviceItems = [];

    /** @var array<int,array{id:int,item_name:string}> */
    private array $partItems = [];

    /** @var array<string,int> */
    private array $sourceVehicleByTemplate = [];

    /** @var array<string,array<int,int>> */
    private array $vehicleIdsByTemplate = [];

    private int $rulesSaved = 0;
    private int $rulesCopied = 0;
    private int $remindersInserted = 0;
    private int $vehiclesCreated = 0;
    private int $customersCreated = 0;

    /** @var array<string,int> */
    private array $stateCounters = [
        'OVERDUE' => 0,
        'DUE' => 0,
        'UPCOMING' => 0,
        'COMPLETED' => 0,
    ];

    /** @var array<string,array<string,mixed>> */
    private array $templates = [
        'BALENO' => [
            'brand_like' => 'Maruti%',
            'model_like' => 'Baleno%',
            'brand' => 'Maruti Suzuki',
            'model' => 'Baleno',
            'variant' => 'Sigma',
            'fuel_type' => 'PETROL',
            'service_km' => 10000,
            'service_days' => 180,
            'part_km' => 12000,
            'part_days' => 210,
        ],
        'SWIFT' => [
            'brand_like' => 'Maruti%',
            'model_like' => 'Swift%',
            'brand' => 'Maruti Suzuki',
            'model' => 'Swift',
            'variant' => 'VXI',
            'fuel_type' => 'PETROL',
            'service_km' => 9000,
            'service_days' => 170,
            'part_km' => 11000,
            'part_days' => 200,
        ],
        'CRETA' => [
            'brand_like' => 'Hyundai%',
            'model_like' => 'Creta%',
            'brand' => 'Hyundai',
            'model' => 'Creta',
            'variant' => 'SX',
            'fuel_type' => 'PETROL',
            'service_km' => 11000,
            'service_days' => 185,
            'part_km' => 13500,
            'part_days' => 215,
        ],
        'NEXON' => [
            'brand_like' => 'Tata%',
            'model_like' => 'Nexon%',
            'brand' => 'Tata',
            'model' => 'Nexon',
            'variant' => 'XZ+',
            'fuel_type' => 'DIESEL',
            'service_km' => 10500,
            'service_days' => 182,
            'part_km' => 13000,
            'part_days' => 212,
        ],
    ];

    public function __construct()
    {
        $this->pdo = db();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        mt_srand(20260223);
    }

    public function run(): void
    {
        $this->resolveContext();
        $this->bootstrapSession();

        if (!service_reminder_feature_ready(true)) {
            throw new RuntimeException('Maintenance reminder storage is not ready.');
        }

        $this->ensureReminderEnabledItems();
        $this->ensureVehiclePopulation(110);
        $this->resetMaintenanceData();
        $this->seedTemplateRulesUsingBulkCopy();
        $this->bulkCopyRulesToRemainingVehicles();
        $this->seedReminderStatesForVehicles(100);

        service_reminder_feature_ready(true);
        $this->printSummary();
    }

    private function resolveContext(): void
    {
        $companyId = (int) ($this->pdo->query('SELECT id FROM companies ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);
        $this->companyId = $companyId > 0 ? $companyId : 1;

        $userStmt = $this->pdo->prepare(
            'SELECT id
             FROM users
             WHERE company_id = :company_id
               AND status_code = "ACTIVE"
             ORDER BY id ASC
             LIMIT 1'
        );
        $userStmt->execute(['company_id' => $this->companyId]);
        $this->actorUserId = (int) ($userStmt->fetchColumn() ?: 1);

        $garageStmt = $this->pdo->prepare(
            'SELECT id
             FROM garages
             WHERE company_id = :company_id
               AND status_code = "ACTIVE"
             ORDER BY id ASC'
        );
        $garageStmt->execute(['company_id' => $this->companyId]);
        $garageIds = array_values(
            array_filter(
                array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $garageStmt->fetchAll()),
                static fn (int $id): bool => $id > 0
            )
        );
        if ($garageIds === []) {
            $garageIds = [1];
        }
        $this->garageIds = $garageIds;
    }

    private function bootstrapSession(): void
    {
        $_SESSION['company_id'] = $this->companyId;
        $_SESSION['user_id'] = $this->actorUserId;
        $_SESSION['active_garage_id'] = $this->garageIds[0] ?? 1;
        $_SESSION['role_key'] = 'super_admin';
    }

    private function ensureReminderEnabledItems(): void
    {
        $serviceStmt = $this->pdo->prepare(
            'SELECT id, service_name AS item_name
             FROM services
             WHERE company_id = :company_id
               AND status_code = "ACTIVE"
             ORDER BY
               CASE
                 WHEN service_name LIKE "%Periodic%" THEN 0
                 WHEN service_name LIKE "%Basic%" THEN 1
                 WHEN service_name LIKE "%Comprehensive%" THEN 2
                 WHEN service_name LIKE "%AC%" THEN 3
                 WHEN service_name LIKE "%Engine%" THEN 4
                 ELSE 9
               END,
               id ASC
             LIMIT 8'
        );
        $serviceStmt->execute(['company_id' => $this->companyId]);
        $services = $serviceStmt->fetchAll();
        if (count($services) < 3) {
            throw new RuntimeException('Not enough active services to build reminder demo data.');
        }
        $this->serviceItems = array_map(
            static fn (array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'item_name' => (string) ($row['item_name'] ?? ''),
            ],
            array_slice($services, 0, 4)
        );

        $partStmt = $this->pdo->prepare(
            'SELECT id, part_name AS item_name
             FROM parts
             WHERE company_id = :company_id
               AND status_code = "ACTIVE"
             ORDER BY
               CASE
                 WHEN part_name LIKE "%Oil Filter%" THEN 0
                 WHEN part_name LIKE "%Air Filter%" THEN 1
                 WHEN part_name LIKE "%Brake%" THEN 2
                 WHEN part_name LIKE "%Engine Oil%" THEN 3
                 ELSE 9
               END,
               id ASC
             LIMIT 8'
        );
        $partStmt->execute(['company_id' => $this->companyId]);
        $parts = $partStmt->fetchAll();
        if (count($parts) < 3) {
            throw new RuntimeException('Not enough active parts to build reminder demo data.');
        }
        $this->partItems = array_map(
            static fn (array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'item_name' => (string) ($row['item_name'] ?? ''),
            ],
            array_slice($parts, 0, 4)
        );

        $serviceIds = array_values(
            array_filter(
                array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $this->serviceItems),
                static fn (int $id): bool => $id > 0
            )
        );
        $partIds = array_values(
            array_filter(
                array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $this->partItems),
                static fn (int $id): bool => $id > 0
            )
        );
        if ($serviceIds === [] || $partIds === []) {
            throw new RuntimeException('Unable to resolve reminder item master IDs.');
        }

        $servicePlaceholders = implode(',', array_fill(0, count($serviceIds), '?'));
        $serviceEnableStmt = $this->pdo->prepare(
            'UPDATE services
             SET enable_reminder = 1
             WHERE company_id = ?
               AND id IN (' . $servicePlaceholders . ')'
        );
        $serviceEnableStmt->execute(array_merge([$this->companyId], $serviceIds));

        $partPlaceholders = implode(',', array_fill(0, count($partIds), '?'));
        $partEnableStmt = $this->pdo->prepare(
            'UPDATE parts
             SET enable_reminder = 1
             WHERE company_id = ?
               AND id IN (' . $partPlaceholders . ')'
        );
        $partEnableStmt->execute(array_merge([$this->companyId], $partIds));
    }

    private function ensureVehiclePopulation(int $minimumVehicles): void
    {
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM vehicles
             WHERE company_id = :company_id
               AND status_code = "ACTIVE"'
        );
        $countStmt->execute(['company_id' => $this->companyId]);
        $activeCount = (int) $countStmt->fetchColumn();
        if ($activeCount >= $minimumVehicles) {
            return;
        }

        $templateKeys = array_keys($this->templates);
        $toCreate = $minimumVehicles - $activeCount;
        for ($index = 0; $index < $toCreate; $index++) {
            $templateKey = $templateKeys[$index % count($templateKeys)];
            $template = (array) ($this->templates[$templateKey] ?? []);
            if ($template === []) {
                continue;
            }

            $customerId = $this->createDemoCustomer($templateKey, $index + 1);
            $this->createDemoVehicle($customerId, $templateKey, $template, $index + 1);
        }
    }

    private function createDemoCustomer(string $templateKey, int $index): int
    {
        $label = ucfirst(strtolower($templateKey));
        $fullName = 'Maintenance Demo ' . $label . ' Customer ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT);
        $phone = '98' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);

        $stmt = $this->pdo->prepare(
            'INSERT INTO customers
              (company_id, created_by, full_name, phone, city, state, notes, is_active, status_code)
             VALUES
              (:company_id, :created_by, :full_name, :phone, :city, :state, :notes, 1, "ACTIVE")'
        );
        $stmt->execute([
            'company_id' => $this->companyId,
            'created_by' => $this->actorUserId > 0 ? $this->actorUserId : null,
            'full_name' => $fullName,
            'phone' => $phone,
            'city' => 'Pune',
            'state' => 'Maharashtra',
            'notes' => '[MAINT-DEMO] Generated by maintenance reminder seeder',
        ]);

        $this->customersCreated++;
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $template
     */
    private function createDemoVehicle(int $customerId, string $templateKey, array $template, int $index): int
    {
        $profile = $this->findTemplateVehicleProfile($template);
        $registrationNo = $this->uniqueRegistration($templateKey, $index);
        $vehicleType = '4W';
        $brand = (string) ($profile['brand'] ?? $template['brand'] ?? 'Demo Brand');
        $brandId = (int) ($profile['brand_id'] ?? 0) > 0 ? (int) $profile['brand_id'] : null;
        $model = (string) ($profile['model'] ?? $template['model'] ?? 'Demo Model');
        $modelId = (int) ($profile['model_id'] ?? 0) > 0 ? (int) $profile['model_id'] : null;
        $variant = (string) ($profile['variant'] ?? $template['variant'] ?? 'Standard');
        $variantId = (int) ($profile['variant_id'] ?? 0) > 0 ? (int) $profile['variant_id'] : null;
        $fuelType = strtoupper((string) ($profile['fuel_type'] ?? $template['fuel_type'] ?? 'PETROL'));
        if (!in_array($fuelType, ['PETROL', 'DIESEL', 'CNG', 'EV', 'HYBRID', 'OTHER'], true)) {
            $fuelType = 'PETROL';
        }
        $modelYear = (int) ($profile['model_year'] ?? 2022);
        if ($modelYear < 2008 || $modelYear > (int) date('Y') + 1) {
            $modelYear = 2022;
        }
        $visVariantId = (int) ($profile['vis_variant_id'] ?? 0) > 0 ? (int) $profile['vis_variant_id'] : null;
        $odometerKm = random_int(18000, 210000);

        $stmt = $this->pdo->prepare(
            'INSERT INTO vehicles
              (company_id, customer_id, registration_no, vehicle_type, brand, brand_id, model, model_id,
               variant, variant_id, fuel_type, model_year, odometer_km, notes, is_active, vis_variant_id, status_code)
             VALUES
              (:company_id, :customer_id, :registration_no, :vehicle_type, :brand, :brand_id, :model, :model_id,
               :variant, :variant_id, :fuel_type, :model_year, :odometer_km, :notes, 1, :vis_variant_id, "ACTIVE")'
        );
        $stmt->execute([
            'company_id' => $this->companyId,
            'customer_id' => $customerId,
            'registration_no' => $registrationNo,
            'vehicle_type' => $vehicleType,
            'brand' => $brand,
            'brand_id' => $brandId,
            'model' => $model,
            'model_id' => $modelId,
            'variant' => $variant,
            'variant_id' => $variantId,
            'fuel_type' => $fuelType,
            'model_year' => $modelYear,
            'odometer_km' => $odometerKm,
            'notes' => '[MAINT-DEMO] Generated by maintenance reminder seeder',
            'vis_variant_id' => $visVariantId,
        ]);

        $this->vehiclesCreated++;
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $template
     * @return array<string,mixed>
     */
    private function findTemplateVehicleProfile(array $template): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT brand, brand_id, model, model_id, variant, variant_id, fuel_type, model_year, vis_variant_id
             FROM vehicles
             WHERE company_id = :company_id
               AND status_code = "ACTIVE"
               AND brand LIKE :brand_like
               AND model LIKE :model_like
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $this->companyId,
            'brand_like' => (string) ($template['brand_like'] ?? '%'),
            'model_like' => (string) ($template['model_like'] ?? '%'),
        ]);
        return $stmt->fetch() ?: [];
    }

    private function uniqueRegistration(string $templateKey, int $index): string
    {
        $prefix = 'DM' . substr(strtoupper($templateKey), 0, 2);
        $attempt = max(1, $index);
        while (true) {
            $candidate = sprintf('%s-%04d', $prefix, $attempt);
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM vehicles
                 WHERE company_id = :company_id
                   AND registration_no = :registration_no'
            );
            $stmt->execute([
                'company_id' => $this->companyId,
                'registration_no' => $candidate,
            ]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $candidate;
            }
            $attempt++;
        }
    }

    private function resetMaintenanceData(): void
    {
        $this->pdo->beginTransaction();
        try {
            $rulesDeleteStmt = $this->pdo->prepare(
                'DELETE FROM vehicle_maintenance_rules
                 WHERE company_id = :company_id'
            );
            $rulesDeleteStmt->execute(['company_id' => $this->companyId]);

            $reminderDeleteStmt = $this->pdo->prepare(
                'DELETE FROM vehicle_maintenance_reminders
                 WHERE company_id = :company_id'
            );
            $reminderDeleteStmt->execute(['company_id' => $this->companyId]);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function seedTemplateRulesUsingBulkCopy(): void
    {
        foreach ($this->templates as $templateKey => $template) {
            $vehicleIds = $this->fetchVehicleIdsForTemplate((array) $template, 8, (string) $templateKey);
            if ($vehicleIds === []) {
                continue;
            }

            $sourceVehicleId = (int) $vehicleIds[0];
            $ruleRows = $this->buildRuleRowsForTemplate((array) $template);
            $saveResult = service_reminder_save_rules_for_vehicles(
                $this->companyId,
                [$sourceVehicleId],
                $ruleRows,
                $this->actorUserId
            );
            $this->rulesSaved += (int) ($saveResult['saved_count'] ?? 0);

            $targetVehicleIds = array_values(array_slice($vehicleIds, 1));
            if ($targetVehicleIds !== []) {
                $copyResult = service_reminder_copy_rules_between_vehicles(
                    $this->companyId,
                    $sourceVehicleId,
                    $targetVehicleIds,
                    $this->actorUserId
                );
                $this->rulesCopied += (int) ($copyResult['copied_count'] ?? 0);
            }

            $this->sourceVehicleByTemplate[(string) $templateKey] = $sourceVehicleId;
            $this->vehicleIdsByTemplate[(string) $templateKey] = $vehicleIds;
        }
    }

    /**
     * @param array<string,mixed> $template
     * @return array<int,int>
     */
    private function fetchVehicleIdsForTemplate(array $template, int $minimum, string $templateKey): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id
             FROM vehicles
             WHERE company_id = :company_id
               AND status_code = "ACTIVE"
               AND brand LIKE :brand_like
               AND model LIKE :model_like
             ORDER BY id ASC'
        );
        $stmt->execute([
            'company_id' => $this->companyId,
            'brand_like' => (string) ($template['brand_like'] ?? '%'),
            'model_like' => (string) ($template['model_like'] ?? '%'),
        ]);
        $vehicleIds = array_values(
            array_filter(
                array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $stmt->fetchAll()),
                static fn (int $id): bool => $id > 0
            )
        );

        $needed = max(0, $minimum - count($vehicleIds));
        for ($index = 0; $index < $needed; $index++) {
            $customerId = $this->createDemoCustomer($templateKey, $index + 1);
            $newVehicleId = $this->createDemoVehicle($customerId, $templateKey, $template, $index + 1);
            if ($newVehicleId > 0) {
                $vehicleIds[] = $newVehicleId;
            }
        }

        return array_values(array_unique(array_filter($vehicleIds, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param array<string,mixed> $template
     * @return array<int,array<string,mixed>>
     */
    private function buildRuleRowsForTemplate(array $template): array
    {
        $serviceKmBase = max(4000, (int) ($template['service_km'] ?? 10000));
        $serviceDaysBase = max(60, (int) ($template['service_days'] ?? 180));
        $partKmBase = max(5000, (int) ($template['part_km'] ?? 12000));
        $partDaysBase = max(75, (int) ($template['part_days'] ?? 210));

        $rows = [];
        foreach ($this->serviceItems as $index => $item) {
            $itemId = (int) ($item['id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            $rows[] = [
                'item_type' => 'SERVICE',
                'item_id' => $itemId,
                'interval_km' => $serviceKmBase + ($index * 1200),
                'interval_days' => $serviceDaysBase + ($index * 20),
                'is_active' => true,
            ];
        }

        foreach ($this->partItems as $index => $item) {
            $itemId = (int) ($item['id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            $rows[] = [
                'item_type' => 'PART',
                'item_id' => $itemId,
                'interval_km' => $partKmBase + ($index * 1000),
                'interval_days' => $partDaysBase + ($index * 25),
                'is_active' => true,
            ];
        }

        return $rows;
    }

    private function bulkCopyRulesToRemainingVehicles(): void
    {
        $sourceVehicleIds = array_values(
            array_filter(
                array_map('intval', array_values($this->sourceVehicleByTemplate)),
                static fn (int $id): bool => $id > 0
            )
        );
        if ($sourceVehicleIds === []) {
            return;
        }

        $vehicleStmt = $this->pdo->prepare(
            'SELECT id
             FROM vehicles
             WHERE company_id = :company_id
               AND status_code = "ACTIVE"
             ORDER BY id ASC'
        );
        $vehicleStmt->execute(['company_id' => $this->companyId]);
        $allVehicleIds = array_values(
            array_filter(
                array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $vehicleStmt->fetchAll()),
                static fn (int $id): bool => $id > 0
            )
        );
        if ($allVehicleIds === []) {
            return;
        }

        $alreadyMapped = [];
        foreach ($this->vehicleIdsByTemplate as $templateVehicleIds) {
            foreach ($templateVehicleIds as $vehicleId) {
                $alreadyMapped[(int) $vehicleId] = true;
            }
        }

        $remainingVehicleIds = [];
        foreach ($allVehicleIds as $vehicleId) {
            if (!isset($alreadyMapped[$vehicleId])) {
                $remainingVehicleIds[] = $vehicleId;
            }
        }
        if ($remainingVehicleIds === []) {
            return;
        }

        $batchSize = 24;
        $batchIndex = 0;
        foreach (array_chunk($remainingVehicleIds, $batchSize) as $chunk) {
            $sourceVehicleId = $sourceVehicleIds[$batchIndex % count($sourceVehicleIds)];
            $copyResult = service_reminder_copy_rules_between_vehicles(
                $this->companyId,
                $sourceVehicleId,
                $chunk,
                $this->actorUserId
            );
            $this->rulesCopied += (int) ($copyResult['copied_count'] ?? 0);
            $batchIndex++;
        }
    }

    private function seedReminderStatesForVehicles(int $minimumVehicles): void
    {
        $vehicleStmt = $this->pdo->prepare(
            'SELECT id, customer_id, odometer_km
             FROM vehicles
             WHERE company_id = :company_id
               AND status_code = "ACTIVE"
             ORDER BY id ASC'
        );
        $vehicleStmt->execute(['company_id' => $this->companyId]);
        $vehicles = $vehicleStmt->fetchAll();
        if (count($vehicles) < $minimumVehicles) {
            throw new RuntimeException('Need at least ' . $minimumVehicles . ' active vehicles for demo reminder states.');
        }

        $states = ['OVERDUE', 'DUE', 'UPCOMING', 'COMPLETED'];

        foreach ($vehicles as $index => $vehicle) {
            $vehicleId = (int) ($vehicle['id'] ?? 0);
            if ($vehicleId <= 0) {
                continue;
            }

            $ruleRows = service_reminder_vehicle_rule_rows($this->companyId, $vehicleId, false);
            if ($ruleRows === []) {
                continue;
            }

            $rule = $ruleRows[$index % count($ruleRows)];
            $itemType = service_reminder_normalize_type((string) ($rule['item_type'] ?? ''));
            $itemId = (int) ($rule['item_id'] ?? 0);
            if ($itemType === '' || $itemId <= 0) {
                continue;
            }

            $itemName = trim((string) ($rule['item_name'] ?? ''));
            if ($itemName === '') {
                $itemName = $itemType . ' #' . $itemId;
            }

            $garageId = $this->garageIds[$index % count($this->garageIds)];
            $currentOdometer = max(5000, (int) ($vehicle['odometer_km'] ?? 0));
            $intervalKm = service_reminder_parse_positive_int($rule['interval_km'] ?? null) ?? 10000;
            $intervalDays = service_reminder_parse_positive_int($rule['interval_days'] ?? null) ?? 180;
            $state = $states[$index % count($states)];

            [$nextDueKm, $nextDueDate] = $this->buildDueTargets($state, $currentOdometer, $intervalKm, $intervalDays);
            $lastServiceKm = max(500, $nextDueKm !== null ? ($nextDueKm - $intervalKm) : ($currentOdometer - random_int(1000, 6000)));
            $lastServiceDate = date('Y-m-d', strtotime($nextDueDate . ' -' . max(30, $intervalDays) . ' days'));
            $recommendation = service_reminder_default_note($itemName, $nextDueKm, $nextDueDate);

            service_reminder_mark_existing_inactive(
                $this->pdo,
                $this->companyId,
                $vehicleId,
                $itemType,
                $itemId,
                $this->actorUserId,
                'COMPLETED'
            );

            service_reminder_insert_active_reminder(
                $this->pdo,
                [
                    'company_id' => $this->companyId,
                    'garage_id' => $garageId,
                    'vehicle_id' => $vehicleId,
                    'customer_id' => (int) ($vehicle['customer_id'] ?? 0),
                    'job_card_id' => null,
                    'rule_id' => (int) ($rule['id'] ?? 0),
                    'item_type' => $itemType,
                    'item_id' => $itemId,
                    'item_name' => $itemName,
                    'source_type' => 'DEMO_SEED',
                    'reminder_status' => 'ACTIVE',
                    'recommendation_text' => $recommendation,
                    'last_service_km' => $lastServiceKm,
                    'last_service_date' => $lastServiceDate,
                    'interval_km' => $intervalKm,
                    'interval_days' => $intervalDays,
                    'next_due_km' => $nextDueKm,
                    'next_due_date' => $nextDueDate,
                    'predicted_next_visit_date' => service_reminder_predict_next_visit_date(
                        $lastServiceKm,
                        $nextDueKm,
                        $lastServiceDate,
                        null,
                        $nextDueDate
                    ),
                    'created_by' => $this->actorUserId,
                    'updated_by' => $this->actorUserId,
                ]
            );
            $this->remindersInserted++;

            if ($state === 'COMPLETED') {
                service_reminder_mark_existing_inactive(
                    $this->pdo,
                    $this->companyId,
                    $vehicleId,
                    $itemType,
                    $itemId,
                    $this->actorUserId,
                    'COMPLETED'
                );
            }

            $this->stateCounters[$state] = (int) ($this->stateCounters[$state] ?? 0) + 1;
        }
    }

    /**
     * @return array{0:?int,1:string}
     */
    private function buildDueTargets(string $state, int $currentOdometer, int $intervalKm, int $intervalDays): array
    {
        $state = strtoupper(trim($state));
        $today = date('Y-m-d');

        if ($state === 'OVERDUE') {
            $nextDueKm = max(1000, $currentOdometer - random_int(700, 3500));
            $nextDueDate = date('Y-m-d', strtotime($today . ' -' . random_int(7, 60) . ' days'));
            return [$nextDueKm, $nextDueDate];
        }

        if ($state === 'DUE') {
            $nextDueKm = $currentOdometer + random_int(0, 300);
            $nextDueDate = date('Y-m-d', strtotime($today . ' +' . random_int(0, 5) . ' days'));
            return [$nextDueKm, $nextDueDate];
        }

        if ($state === 'COMPLETED') {
            $nextDueKm = $currentOdometer + max(1000, (int) round($intervalKm * 0.7));
            $nextDueDate = date('Y-m-d', strtotime($today . ' +' . max(10, (int) round($intervalDays * 0.4)) . ' days'));
            return [$nextDueKm, $nextDueDate];
        }

        $nextDueKm = $currentOdometer + random_int(2000, max(2200, $intervalKm + 2500));
        $nextDueDate = date('Y-m-d', strtotime($today . ' +' . random_int(15, max(20, $intervalDays)) . ' days'));
        return [$nextDueKm, $nextDueDate];
    }

    private function printSummary(): void
    {
        $vehiclesWithRulesStmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT vehicle_id)
             FROM vehicle_maintenance_rules
             WHERE company_id = :company_id
               AND status_code <> "DELETED"'
        );
        $vehiclesWithRulesStmt->execute(['company_id' => $this->companyId]);
        $vehiclesWithRules = (int) $vehiclesWithRulesStmt->fetchColumn();

        $activeVehicleCountStmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM vehicles
             WHERE company_id = :company_id
               AND status_code = "ACTIVE"'
        );
        $activeVehicleCountStmt->execute(['company_id' => $this->companyId]);
        $activeVehicleCount = (int) $activeVehicleCountStmt->fetchColumn();

        $registerRows = service_reminder_fetch_register_for_scope(
            $this->companyId,
            0,
            $this->garageIds,
            10000,
            null,
            'ALL',
            null,
            null
        );
        $summary = service_reminder_summary_counts($registerRows);

        echo "Vehicle Maintenance Demo Seeder Completed\n";
        echo "Company ID: {$this->companyId}\n";
        echo "Actor User ID: {$this->actorUserId}\n";
        echo "Garages: " . implode(', ', array_map('strval', $this->garageIds)) . "\n";
        echo "Service items reminder-enabled: " . count($this->serviceItems) . "\n";
        echo "Part items reminder-enabled: " . count($this->partItems) . "\n";
        echo "Customers created: {$this->customersCreated}\n";
        echo "Vehicles created: {$this->vehiclesCreated}\n";
        echo "Active vehicles in company: {$activeVehicleCount}\n";
        echo "Vehicles with maintenance rules: {$vehiclesWithRules}\n";
        echo "Rules saved directly: {$this->rulesSaved}\n";
        echo "Rules copied via bulk-copy helper: {$this->rulesCopied}\n";
        echo "Reminder records inserted: {$this->remindersInserted}\n";
        echo "State mix seeded: OVERDUE={$this->stateCounters['OVERDUE']}, DUE={$this->stateCounters['DUE']}, UPCOMING={$this->stateCounters['UPCOMING']}, COMPLETED={$this->stateCounters['COMPLETED']}\n";
        echo "Register summary (computed): total={$summary['total']}, overdue={$summary['overdue']}, due={$summary['due']}, upcoming={$summary['upcoming']}, completed={$summary['completed']}\n";
    }
}

try {
    $seeder = new VehicleMaintenanceDemoSeeder();
    $seeder->run();
} catch (Throwable $exception) {
    http_response_code(500);
    echo 'Seeder failed: ' . $exception->getMessage() . PHP_EOL;
    exit(1);
}
