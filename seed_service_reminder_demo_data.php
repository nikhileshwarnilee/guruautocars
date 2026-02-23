<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/modules/jobs/workflow.php';
require_once __DIR__ . '/modules/billing/workflow.php';

final class ServiceReminderDemoSeeder
{
    private PDO $pdo;
    private int $companyId = 1;
    private int $actorUserId = 1;

    /** @var array<int,int> */
    private array $garageIds = [1, 2];

    /** @var array<string,array<string,mixed>> */
    private array $ruleBlueprints = [
        'baleno_petrol' => [
            'brand_like' => 'Maruti%',
            'model_like' => 'Baleno%',
            'fuel_type' => 'PETROL',
            'engine_oil_interval_km' => 10000,
            'major_service_interval_km' => 40000,
            'brake_inspection_interval_km' => 15000,
            'ac_service_interval_km' => 12000,
            'interval_days' => 180,
        ],
        'swift_diesel' => [
            'brand_like' => 'Maruti%',
            'model_like' => 'Swift%',
            'fuel_type' => 'DIESEL',
            'engine_oil_interval_km' => 9500,
            'major_service_interval_km' => 38000,
            'brake_inspection_interval_km' => 14500,
            'ac_service_interval_km' => 11000,
            'interval_days' => 176,
        ],
        'creta_petrol' => [
            'brand_like' => 'Hyundai%',
            'model_like' => 'Creta%',
            'fuel_type' => 'PETROL',
            'engine_oil_interval_km' => 10250,
            'major_service_interval_km' => 42000,
            'brake_inspection_interval_km' => 16000,
            'ac_service_interval_km' => 13000,
            'interval_days' => 182,
        ],
        'nexon_diesel' => [
            'brand_like' => 'Tata%',
            'model_like' => 'Nexon%',
            'fuel_type' => 'DIESEL',
            'engine_oil_interval_km' => 10800,
            'major_service_interval_km' => 40500,
            'brake_inspection_interval_km' => 15250,
            'ac_service_interval_km' => 12500,
            'interval_days' => 179,
        ],
        'city_petrol' => [
            'brand_like' => 'Honda%',
            'model_like' => 'City%',
            'fuel_type' => 'PETROL',
            'engine_oil_interval_km' => 10000,
            'major_service_interval_km' => 41000,
            'brake_inspection_interval_km' => 15500,
            'ac_service_interval_km' => 12000,
            'interval_days' => 181,
        ],
        'innova_diesel' => [
            'brand_like' => 'Toyota%',
            'model_like' => 'Innova%',
            'fuel_type' => 'DIESEL',
            'engine_oil_interval_km' => 12000,
            'major_service_interval_km' => 45000,
            'brake_inspection_interval_km' => 18000,
            'ac_service_interval_km' => 15000,
            'interval_days' => 178,
        ],
    ];

    /** @var array<int,array<string,mixed>> */
    private array $selectedVehicles = [];

    /** @var array<int,array<string,mixed>> */
    private array $intervalByVariantId = [];

    /** @var array<int,int> */
    private array $currentOdometerByVehicle = [];

    private int $jobsCreated = 0;
    private int $laborLinesCreated = 0;
    private int $invoicesCreated = 0;

    /** @var array<int,array<string,mixed>> */
    private array $jobsForPrintValidation = [];

    /** @var array<string,int> */
    private array $manualOverrides = [
        'edited_next_due_km' => 0,
        'disabled' => 0,
        'custom_note' => 0,
    ];

    public function __construct()
    {
        $this->pdo = db();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        mt_srand(20260223);
    }

    public function run(): void
    {
        $this->bootstrapSession();
        if (!service_reminder_feature_ready()) {
            throw new RuntimeException('Service reminder feature is not ready.');
        }

        $this->cleanupPreviousDemoRun();
        $this->seedVisIntervalRules();
        $this->selectTargetVehicles(50);
        $this->seedWorkflowDrivenJobs();
        $this->seedPrintValidationInvoices(16);
        $this->printSummary();
    }

    private function bootstrapSession(): void
    {
        $_SESSION['user_id'] = $this->actorUserId;
        $_SESSION['company_id'] = $this->companyId;
        $_SESSION['active_garage_id'] = 1;
        $_SESSION['role_key'] = 'super_admin';
    }

    private function cleanupPreviousDemoRun(): void
    {
        $this->pdo->beginTransaction();
        try {
            $demoJobsStmt = $this->pdo->prepare(
                'SELECT id
                 FROM job_cards
                 WHERE company_id = :company_id
                   AND complaint LIKE :marker'
            );
            $demoJobsStmt->execute([
                'company_id' => $this->companyId,
                'marker' => '[SR-DEMO]%',
            ]);
            $jobIds = array_values(
                array_filter(
                    array_map(
                        static fn (array $row): int => (int) ($row['id'] ?? 0),
                        $demoJobsStmt->fetchAll()
                    ),
                    static fn (int $id): bool => $id > 0
                )
            );

            if ($jobIds !== []) {
                $placeholders = implode(',', array_fill(0, count($jobIds), '?'));

                $this->pdo->prepare('DELETE FROM service_reminders WHERE job_card_id IN (' . $placeholders . ')')->execute($jobIds);
                $this->pdo->prepare('DELETE FROM invoices WHERE job_card_id IN (' . $placeholders . ')')->execute($jobIds);
                $this->pdo->prepare('DELETE FROM job_history WHERE job_card_id IN (' . $placeholders . ')')->execute($jobIds);
                $this->pdo->prepare('DELETE FROM job_assignments WHERE job_card_id IN (' . $placeholders . ')')->execute($jobIds);
                $this->pdo->prepare('DELETE FROM job_parts WHERE job_card_id IN (' . $placeholders . ')')->execute($jobIds);
                $this->pdo->prepare('DELETE FROM job_labor WHERE job_card_id IN (' . $placeholders . ')')->execute($jobIds);
                $this->pdo->prepare('DELETE FROM job_cards WHERE id IN (' . $placeholders . ')')->execute($jobIds);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function seedVisIntervalRules(): void
    {
        $fetchVariantStmt = $this->pdo->prepare(
            'SELECT vv.id AS variant_id, vv.variant_name, vv.fuel_type, vm.model_name, vb.brand_name
             FROM vis_variants vv
             INNER JOIN vis_models vm ON vm.id = vv.model_id
             INNER JOIN vis_brands vb ON vb.id = vm.brand_id
             WHERE vv.status_code = "ACTIVE"
               AND vm.status_code = "ACTIVE"
               AND vb.status_code = "ACTIVE"
               AND vb.brand_name LIKE :brand_like
               AND vm.model_name LIKE :model_like
               AND vv.fuel_type = :fuel_type
             ORDER BY vv.id ASC'
        );

        $upsertRuleStmt = $this->pdo->prepare(
            'INSERT INTO vis_service_interval_rules
              (company_id, vis_variant_id, engine_oil_interval_km, major_service_interval_km, brake_inspection_interval_km, ac_service_interval_km, interval_days, status_code, created_by, updated_by)
             VALUES
              (:company_id, :vis_variant_id, :engine_oil_interval_km, :major_service_interval_km, :brake_inspection_interval_km, :ac_service_interval_km, :interval_days, "ACTIVE", :created_by, :updated_by)
             ON DUPLICATE KEY UPDATE
              engine_oil_interval_km = VALUES(engine_oil_interval_km),
              major_service_interval_km = VALUES(major_service_interval_km),
              brake_inspection_interval_km = VALUES(brake_inspection_interval_km),
              ac_service_interval_km = VALUES(ac_service_interval_km),
              interval_days = VALUES(interval_days),
              status_code = "ACTIVE",
              updated_by = VALUES(updated_by),
              updated_at = NOW()'
        );

        foreach ($this->ruleBlueprints as $modelKey => $ruleData) {
            $fetchVariantStmt->execute([
                'brand_like' => (string) $ruleData['brand_like'],
                'model_like' => (string) $ruleData['model_like'],
                'fuel_type' => (string) $ruleData['fuel_type'],
            ]);
            $variants = $fetchVariantStmt->fetchAll();
            if ($variants === []) {
                continue;
            }

            foreach ($variants as $index => $variant) {
                $variantId = (int) ($variant['variant_id'] ?? 0);
                if ($variantId <= 0) {
                    continue;
                }

                $jitter = $index * 250;
                $engineOilInterval = max(7000, (int) $ruleData['engine_oil_interval_km'] + $jitter);
                $majorInterval = max(25000, (int) $ruleData['major_service_interval_km'] + ($jitter * 2));
                $brakeInterval = max(10000, (int) $ruleData['brake_inspection_interval_km'] + $jitter);
                $acInterval = max(8000, (int) $ruleData['ac_service_interval_km'] + $jitter);
                $intervalDays = max(160, min(183, (int) $ruleData['interval_days'] + ($index * 2)));

                $upsertRuleStmt->execute([
                    'company_id' => $this->companyId,
                    'vis_variant_id' => $variantId,
                    'engine_oil_interval_km' => $engineOilInterval,
                    'major_service_interval_km' => $majorInterval,
                    'brake_inspection_interval_km' => $brakeInterval,
                    'ac_service_interval_km' => $acInterval,
                    'interval_days' => $intervalDays,
                    'created_by' => $this->actorUserId,
                    'updated_by' => $this->actorUserId,
                ]);

                $this->intervalByVariantId[$variantId] = [
                    'model_key' => $modelKey,
                    'engine_oil_interval_km' => $engineOilInterval,
                    'major_service_interval_km' => $majorInterval,
                    'brake_inspection_interval_km' => $brakeInterval,
                    'ac_service_interval_km' => $acInterval,
                    'interval_days' => $intervalDays,
                ];
            }
        }
    }

    private function selectTargetVehicles(int $required): void
    {
        if ($required <= 0) {
            $this->selectedVehicles = [];
            return;
        }

        $variantIds = array_values(array_map('intval', array_keys($this->intervalByVariantId)));
        if ($variantIds === []) {
            throw new RuntimeException('No target VIS variants found for reminder demo.');
        }

        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $sql =
            'SELECT v.id, v.customer_id, v.registration_no, v.brand, v.model, v.variant, v.vis_variant_id, v.odometer_km,
                    COALESCE(inv_stats.invoice_count, 0) AS invoice_count
             FROM vehicles v
             LEFT JOIN (
                SELECT jc.vehicle_id, COUNT(i.id) AS invoice_count
                FROM job_cards jc
                INNER JOIN invoices i ON i.job_card_id = jc.id
                WHERE i.company_id = ?
                  AND i.invoice_status = "FINALIZED"
                GROUP BY jc.vehicle_id
             ) inv_stats ON inv_stats.vehicle_id = v.id
             WHERE v.company_id = ?
               AND v.status_code = "ACTIVE"
               AND v.vis_variant_id IN (' . $placeholders . ')
             ORDER BY inv_stats.invoice_count DESC, v.id ASC
             LIMIT ' . max($required * 2, $required + 10);

        $params = array_merge([$this->companyId, $this->companyId], $variantIds);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $selected = [];
        foreach ($rows as $row) {
            if (count($selected) >= $required) {
                break;
            }
            $vehicleId = (int) ($row['id'] ?? 0);
            if ($vehicleId <= 0 || (int) ($row['customer_id'] ?? 0) <= 0) {
                continue;
            }
            $selected[] = $row;
            $this->currentOdometerByVehicle[$vehicleId] = max(5000, (int) ($row['odometer_km'] ?? 0));
        }

        if (count($selected) < $required) {
            throw new RuntimeException('Insufficient target vehicles to generate reminder demo data.');
        }

        $this->selectedVehicles = $selected;
    }

    private function seedWorkflowDrivenJobs(): void
    {
        $vehicles = $this->selectedVehicles;
        $overdueKmVehicles = array_slice($vehicles, 0, 12);
        $overdueDateVehicles = array_slice($vehicles, 12, 12);
        $dueSoonVehicles = array_slice($vehicles, 24, 10);
        $upcomingVehicles = array_slice($vehicles, 34, 8);
        $completedVehicles = array_slice($vehicles, 42, 8);

        $garageToggle = 0;
        foreach ($overdueKmVehicles as $vehicle) {
            $garageId = $this->garageIds[$garageToggle % count($this->garageIds)];
            $garageToggle++;
            $this->seedOverdueKmScenario($vehicle, $garageId);
        }
        foreach ($overdueDateVehicles as $vehicle) {
            $garageId = $this->garageIds[$garageToggle % count($this->garageIds)];
            $garageToggle++;
            $this->seedOverdueDateScenario($vehicle, $garageId);
        }
        foreach ($dueSoonVehicles as $index => $vehicle) {
            $garageId = $this->garageIds[$garageToggle % count($this->garageIds)];
            $garageToggle++;
            if ($index < 5) {
                $this->seedDueSoonDateScenario($vehicle, $garageId, $index < 2);
            } else {
                $this->seedDueSoonKmScenario($vehicle, $garageId);
            }
        }
        foreach ($upcomingVehicles as $vehicle) {
            $garageId = $this->garageIds[$garageToggle % count($this->garageIds)];
            $garageToggle++;
            $this->seedUpcomingScenario($vehicle, $garageId);
        }
        foreach ($completedVehicles as $vehicle) {
            $garageId = $this->garageIds[$garageToggle % count($this->garageIds)];
            $garageToggle++;
            $this->seedCompletedScenario($vehicle, $garageId);
        }

        $extraVehicles = array_slice($vehicles, 0, 20);
        foreach ($extraVehicles as $index => $vehicle) {
            $garageId = $this->garageIds[$index % count($this->garageIds)];
            $this->seedFollowupNoReminderJob($vehicle, $garageId);
        }
    }

    private function seedOverdueKmScenario(array $vehicle, int $garageId): void
    {
        $vehicleId = (int) ($vehicle['id'] ?? 0);
        $intervalKm = $this->intervalForVehicle((int) ($vehicle['vis_variant_id'] ?? 0), 'engine_oil_interval_km', 10000);
        $baseOdo = max($this->currentVehicleOdometer($vehicleId), mt_rand(28000, 54000));

        $serviceOdo = $baseOdo + mt_rand(800, 2500);
        $openedAt1 = $this->randomDateTimeInPast(140, 170, 9, 13);
        $job1 = $this->createAndCloseDemoJob(
            $vehicle,
            $garageId,
            $openedAt1,
            $serviceOdo,
            ['Engine oil and filter replacement'],
            'HIGH',
            '[SR-DEMO] Oil service milestone for overdue KM scenario',
            'Oil service completed and vehicle advised for next visit.'
        );
        if ($job1 <= 0) {
            return;
        }

        $overdueOdo = $serviceOdo + $intervalKm + mt_rand(1800, 4200);
        $openedAt2 = $this->randomDateTimeInPast(12, 45, 10, 16);
        $this->createAndCloseDemoJob(
            $vehicle,
            $garageId,
            $openedAt2,
            $overdueOdo,
            ['Brake pad replacement and brake inspection'],
            'MEDIUM',
            '[SR-DEMO] Follow-up at higher KM to force overdue-by-KM state',
            'Brake operation restored. Old oil reminder should now be overdue.'
        );
    }

    private function seedOverdueDateScenario(array $vehicle, int $garageId): void
    {
        $vehicleId = (int) ($vehicle['id'] ?? 0);
        $intervalDays = $this->intervalForVehicle((int) ($vehicle['vis_variant_id'] ?? 0), 'interval_days', 180);
        $baseOdo = max($this->currentVehicleOdometer($vehicleId), mt_rand(26000, 62000));
        $serviceOdo = $baseOdo + mt_rand(900, 2100);

        $daysAgo = min(184, $intervalDays + mt_rand(2, 12));
        $openedAt1 = $this->dateTimeDaysAgo($daysAgo, mt_rand(9, 14));
        $this->createAndCloseDemoJob(
            $vehicle,
            $garageId,
            $openedAt1,
            $serviceOdo,
            ['AC service and evaporator cleaning'],
            'MEDIUM',
            '[SR-DEMO] AC service backdated to generate overdue-by-date reminder',
            'AC cooling restored with seasonal service reminder.'
        );

        $openedAt2 = $this->randomDateTimeInPast(8, 28, 11, 16);
        $this->createAndCloseDemoJob(
            $vehicle,
            $garageId,
            $openedAt2,
            $serviceOdo + mt_rand(6000, 9000),
            ['Wheel alignment and suspension geometry correction'],
            'LOW',
            '[SR-DEMO] Follow-up general workshop visit for odometer progression',
            'General workshop follow-up completed.'
        );
    }

    private function seedDueSoonDateScenario(array $vehicle, int $garageId, bool $forceDueToday = false): void
    {
        $vehicleId = (int) ($vehicle['id'] ?? 0);
        $intervalDays = $this->intervalForVehicle((int) ($vehicle['vis_variant_id'] ?? 0), 'interval_days', 180);
        $baseOdo = max($this->currentVehicleOdometer($vehicleId), mt_rand(22000, 52000));
        $serviceOdo = $baseOdo + mt_rand(700, 1800);

        $daysAgo = $forceDueToday
            ? $intervalDays
            : max(1, $intervalDays - mt_rand(1, 7));
        $openedAt1 = $this->dateTimeDaysAgo($daysAgo, mt_rand(9, 13));
        $this->createAndCloseDemoJob(
            $vehicle,
            $garageId,
            $openedAt1,
            $serviceOdo,
            ['AC service and gas top-up'],
            'MEDIUM',
            '[SR-DEMO] AC service timed for due-soon date reminder',
            'AC line inspected and seasonal due reminder scheduled.'
        );

        $openedAt2 = $this->randomDateTimeInPast(4, 18, 10, 15);
        $this->createAndCloseDemoJob(
            $vehicle,
            $garageId,
            $openedAt2,
            $serviceOdo + mt_rand(5500, 8000),
            ['Steering system play adjustment and road force balancing'],
            'LOW',
            '[SR-DEMO] Secondary job to keep odometer growth realistic',
            'Vehicle test-driven after steering correction.'
        );
    }

    private function seedDueSoonKmScenario(array $vehicle, int $garageId): void
    {
        $vehicleId = (int) ($vehicle['id'] ?? 0);
        $intervalKm = $this->intervalForVehicle((int) ($vehicle['vis_variant_id'] ?? 0), 'engine_oil_interval_km', 10000);
        $baseOdo = max($this->currentVehicleOdometer($vehicleId), mt_rand(24000, 56000));
        $serviceOdo = $baseOdo + mt_rand(800, 2300);

        $openedAt1 = $this->randomDateTimeInPast(90, 130, 9, 13);
        $this->createAndCloseDemoJob(
            $vehicle,
            $garageId,
            $openedAt1,
            $serviceOdo,
            ['Engine oil and filter replacement'],
            'MEDIUM',
            '[SR-DEMO] Oil service to create due-soon-by-KM trajectory',
            'Engine lubrication refreshed with KM-based recommendation.'
        );

        $nearDueOdo = max($serviceOdo + 5000, ($serviceOdo + $intervalKm) - mt_rand(80, 450));
        $openedAt2 = $this->randomDateTimeInPast(5, 20, 10, 16);
        $this->createAndCloseDemoJob(
            $vehicle,
            $garageId,
            $openedAt2,
            $nearDueOdo,
            ['Electrical diagnostics and harness health check'],
            'LOW',
            '[SR-DEMO] Follow-up to bring current odometer close to next due KM',
            'Electrical health check completed.'
        );
    }

    private function seedUpcomingScenario(array $vehicle, int $garageId): void
    {
        $vehicleId = (int) ($vehicle['id'] ?? 0);
        $baseOdo = max($this->currentVehicleOdometer($vehicleId), mt_rand(20000, 50000));
        $serviceOdo = $baseOdo + mt_rand(700, 1600);

        $openedAt1 = $this->randomDateTimeInPast(80, 110, 9, 13);
        $this->createAndCloseDemoJob(
            $vehicle,
            $garageId,
            $openedAt1,
            $serviceOdo,
            ['Major service with fluid and filter package'],
            'HIGH',
            '[SR-DEMO] Major service to keep reminder in upcoming state',
            'Major service checklist completed.'
        );

        $openedAt2 = $this->randomDateTimeInPast(7, 26, 10, 16);
        $this->createAndCloseDemoJob(
            $vehicle,
            $garageId,
            $openedAt2,
            $serviceOdo + mt_rand(6000, 9000),
            ['Wheel balancing and highway vibration diagnosis'],
            'LOW',
            '[SR-DEMO] Secondary job for progression without replacing major reminder',
            'Road vibration issue rectified.'
        );
    }

    private function seedCompletedScenario(array $vehicle, int $garageId): void
    {
        $vehicleId = (int) ($vehicle['id'] ?? 0);
        $intervalKm = $this->intervalForVehicle((int) ($vehicle['vis_variant_id'] ?? 0), 'engine_oil_interval_km', 10000);
        $baseOdo = max($this->currentVehicleOdometer($vehicleId), mt_rand(25000, 58000));
        $serviceOdo1 = $baseOdo + mt_rand(900, 2200);

        $openedAt1 = $this->randomDateTimeInPast(140, 170, 9, 13);
        $this->createAndCloseDemoJob(
            $vehicle,
            $garageId,
            $openedAt1,
            $serviceOdo1,
            ['Engine oil and filter replacement'],
            'MEDIUM',
            '[SR-DEMO] First oil job before reminder completion cycle',
            'Initial oil service completed for reminder replacement scenario.'
        );

        $serviceOdo2 = $serviceOdo1 + $intervalKm + mt_rand(600, 1800);
        $openedAt2 = $this->randomDateTimeInPast(8, 30, 10, 16);

        $overrideCallback = $this->buildManualOverrideCallback($vehicle, $serviceOdo2);
        $this->createAndCloseDemoJob(
            $vehicle,
            $garageId,
            $openedAt2,
            $serviceOdo2,
            ['Engine oil and filter replacement'],
            'MEDIUM',
            '[SR-DEMO] Second oil job to complete previous reminder and create new one',
            'Second oil service closed with manual override case coverage.',
            $overrideCallback
        );
    }

    private function seedFollowupNoReminderJob(array $vehicle, int $garageId): void
    {
        $vehicleId = (int) ($vehicle['id'] ?? 0);
        $baseOdo = $this->currentVehicleOdometer($vehicleId);
        $openedAt = $this->randomDateTimeInPast(2, 20, 10, 16);
        $this->createAndCloseDemoJob(
            $vehicle,
            $garageId,
            $openedAt,
            $baseOdo + mt_rand(5000, 9000),
            ['Road test and customer concern verification'],
            'LOW',
            '[SR-DEMO] Additional follow-up job to cross 120+ workflow-closed jobs',
            'General diagnostic follow-up completed without reminder-trigger keywords.'
        );
    }

    /**
     * @return callable(array, array):void
     */
    private function buildManualOverrideCallback(array $vehicle, int $serviceOdo): callable
    {
        return function (array $previewRows, array &$postPayload) use ($vehicle, $serviceOdo): void {
            $engineOilType = 'ENGINE_OIL';
            $hasEngineOil = false;
            foreach ($previewRows as $row) {
                if ((string) ($row['service_type'] ?? '') === $engineOilType) {
                    $hasEngineOil = true;
                    break;
                }
            }
            if (!$hasEngineOil) {
                return;
            }

            if ($this->manualOverrides['edited_next_due_km'] < 3) {
                $existing = service_reminder_parse_positive_int($postPayload['reminder_next_due_km'][$engineOilType] ?? null);
                if ($existing !== null) {
                    $postPayload['reminder_next_due_km'][$engineOilType] = (string) ($existing + 1500);
                    $postPayload['reminder_note'][$engineOilType] = 'Manual edit: customer requested +1500 KM buffer for next oil service.';
                    $this->manualOverrides['edited_next_due_km']++;
                }
                return;
            }

            if ($this->manualOverrides['disabled'] < 3) {
                $postPayload['reminder_enabled'][$engineOilType] = '0';
                $postPayload['reminder_note'][$engineOilType] = 'Manual disable: fleet contract manages external oil schedule.';
                $this->manualOverrides['disabled']++;
                return;
            }

            if ($this->manualOverrides['custom_note'] < 3) {
                $postPayload['reminder_note'][$engineOilType] =
                    'Custom recommendation: Next engine oil service at highway cycle or ' . number_format((float) ($serviceOdo + 12000), 0) . ' KM, whichever is earlier.';
                $this->manualOverrides['custom_note']++;
            }
        };
    }

    /**
     * @param array<string,mixed> $vehicle
     * @param array<int,string> $laborDescriptions
     * @param callable(array, array):void|null $overrideCallback
     */
    private function createAndCloseDemoJob(
        array $vehicle,
        int $garageId,
        string $openedAt,
        int $odometerKm,
        array $laborDescriptions,
        string $priority,
        string $complaint,
        string $diagnosis,
        ?callable $overrideCallback = null
    ): int {
        $vehicleId = (int) ($vehicle['id'] ?? 0);
        $customerId = (int) ($vehicle['customer_id'] ?? 0);
        if ($vehicleId <= 0 || $customerId <= 0 || $garageId <= 0) {
            return 0;
        }

        $openedAt = $this->normalizeDateTime($openedAt);
        $inProgressAt = $this->shiftDateTime($openedAt, mt_rand(30, 95));
        $completedAt = $this->shiftDateTime($inProgressAt, mt_rand(75, 180));
        $closedAt = $this->shiftDateTime($completedAt, mt_rand(20, 120));

        $jobId = $this->createJobCard(
            $garageId,
            $customerId,
            $vehicleId,
            max(1000, $odometerKm),
            $priority,
            $openedAt,
            $complaint,
            $diagnosis
        );
        if ($jobId <= 0) {
            return 0;
        }

        foreach ($laborDescriptions as $description) {
            $description = trim($description);
            if ($description === '') {
                continue;
            }
            $this->addLaborLine($jobId, $description);
        }
        job_recalculate_estimate($jobId);

        $this->transitionStatus(
            $jobId,
            $garageId,
            'IN_PROGRESS',
            'Auto transition for service reminder demo seeding.',
            $inProgressAt
        );
        $this->transitionStatus(
            $jobId,
            $garageId,
            'COMPLETED',
            'Work completed during service reminder demo seeding.',
            $completedAt
        );

        $previewRows = service_reminder_build_preview_for_job($this->companyId, $garageId, $jobId);
        $postPayload = $this->defaultReminderPostPayload($previewRows);
        if ($overrideCallback !== null) {
            $overrideCallback($previewRows, $postPayload);
        }
        $overrides = service_reminder_parse_close_override_payload($postPayload, $previewRows);

        $closeResult = $this->transitionStatus(
            $jobId,
            $garageId,
            'CLOSED',
            'Job closed by service reminder demo seeder.',
            $closedAt,
            $overrides
        );

        $this->stampHistoricalTimeline($jobId, $openedAt, $inProgressAt, $completedAt, $closedAt);
        $this->updateVehicleOdometer($vehicleId, $odometerKm);
        $this->jobsCreated++;

        $createdCount = (int) ($closeResult['created_count'] ?? 0);
        if ($createdCount > 0) {
            $this->jobsForPrintValidation[] = [
                'job_id' => $jobId,
                'garage_id' => $garageId,
                'closed_at' => $closedAt,
            ];
        }

        return $jobId;
    }

    private function createJobCard(
        int $garageId,
        int $customerId,
        int $vehicleId,
        int $odometerKm,
        string $priority,
        string $openedAt,
        string $complaint,
        string $diagnosis
    ): int {
        $priority = strtoupper(trim($priority));
        if (!in_array($priority, ['LOW', 'MEDIUM', 'HIGH', 'URGENT'], true)) {
            $priority = 'MEDIUM';
        }

        $promisedAt = $this->shiftDateTime($openedAt, mt_rand(24 * 60, 72 * 60));

        $this->pdo->beginTransaction();
        try {
            $jobNumber = job_generate_number($this->pdo, $garageId);
            $stmt = $this->pdo->prepare(
                'INSERT INTO job_cards
                  (company_id, garage_id, job_number, customer_id, vehicle_id, odometer_km, assigned_to, service_advisor_id, complaint, diagnosis, status, priority, opened_at, promised_at, status_code, created_by, updated_by)
                 VALUES
                  (:company_id, :garage_id, :job_number, :customer_id, :vehicle_id, :odometer_km, NULL, :service_advisor_id, :complaint, :diagnosis, "OPEN", :priority, :opened_at, :promised_at, "ACTIVE", :created_by, :updated_by)'
            );
            $stmt->execute([
                'company_id' => $this->companyId,
                'garage_id' => $garageId,
                'job_number' => $jobNumber,
                'customer_id' => $customerId,
                'vehicle_id' => $vehicleId,
                'odometer_km' => max(0, $odometerKm),
                'service_advisor_id' => $this->actorUserId,
                'complaint' => mb_substr($complaint, 0, 3000),
                'diagnosis' => $diagnosis !== '' ? mb_substr($diagnosis, 0, 3000) : null,
                'priority' => $priority,
                'opened_at' => $openedAt,
                'promised_at' => $promisedAt,
                'created_by' => $this->actorUserId,
                'updated_by' => $this->actorUserId,
            ]);
            $jobId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();

            job_append_history(
                $jobId,
                'CREATE',
                null,
                'OPEN',
                'Job created by intelligent reminder demo seeder.',
                ['job_number' => $jobNumber, 'odometer_km' => $odometerKm]
            );

            return $jobId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function addLaborLine(int $jobId, string $description): void
    {
        $normalized = strtolower($description);
        if (str_contains($normalized, 'major')) {
            $unitPrice = (float) mt_rand(6500, 9800);
        } elseif (str_contains($normalized, 'engine oil') || str_contains($normalized, 'oil')) {
            $unitPrice = (float) mt_rand(2100, 4200);
        } elseif (str_contains($normalized, 'ac')) {
            $unitPrice = (float) mt_rand(2600, 5200);
        } elseif (str_contains($normalized, 'brake')) {
            $unitPrice = (float) mt_rand(2400, 4600);
        } else {
            $unitPrice = (float) mt_rand(1200, 3200);
        }

        $quantity = 1.0;
        $gstRate = 18.0;
        $totalAmount = round($quantity * $unitPrice, 2);

        $insertStmt = $this->pdo->prepare(
            'INSERT INTO job_labor
              (job_card_id, service_id, execution_type, outsource_vendor_id, outsource_partner_name, outsource_cost,
               outsource_payable_status, outsource_paid_at, outsource_paid_by, description, quantity, unit_price, gst_rate, total_amount)
             VALUES
              (:job_card_id, NULL, "INHOUSE", NULL, NULL, 0,
               "PAID", NULL, NULL, :description, :quantity, :unit_price, :gst_rate, :total_amount)'
        );
        $insertStmt->execute([
            'job_card_id' => $jobId,
            'description' => mb_substr($description, 0, 255),
            'quantity' => $quantity,
            'unit_price' => round($unitPrice, 2),
            'gst_rate' => $gstRate,
            'total_amount' => $totalAmount,
        ]);

        $this->laborLinesCreated++;
        job_append_history(
            $jobId,
            'LABOR_ADD',
            null,
            null,
            'Labor line added by intelligent reminder demo seeder.',
            ['description' => mb_substr($description, 0, 255), 'total_amount' => $totalAmount]
        );
    }

    /**
     * @param array<string,array<string,mixed>> $reminderOverrides
     * @return array<string,mixed>
     */
    private function transitionStatus(
        int $jobId,
        int $garageId,
        string $targetStatus,
        string $statusNote,
        string $effectiveAt,
        array $reminderOverrides = []
    ): array {
        $jobStmt = $this->pdo->prepare(
            'SELECT id, status, status_code
             FROM job_cards
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id
               AND status_code <> "DELETED"
             LIMIT 1'
        );
        $jobStmt->execute([
            'id' => $jobId,
            'company_id' => $this->companyId,
            'garage_id' => $garageId,
        ]);
        $job = $jobStmt->fetch();
        if (!$job) {
            throw new RuntimeException('Unable to transition missing job #' . $jobId . '.');
        }

        $targetStatus = job_normalize_status($targetStatus);
        $currentStatus = job_normalize_status((string) ($job['status'] ?? 'OPEN'));
        if ($currentStatus === $targetStatus) {
            return ['created_count' => 0, 'disabled_count' => 0, 'created_types' => [], 'warnings' => []];
        }
        if (!job_can_transition($currentStatus, $targetStatus)) {
            throw new RuntimeException(
                'Invalid workflow transition for job #' . $jobId . ': ' . $currentStatus . ' -> ' . $targetStatus
            );
        }

        $inventoryWarnings = [];
        if ($targetStatus === 'CLOSED') {
            $postResult = job_post_inventory_on_close($jobId, $this->companyId, $garageId, $this->actorUserId);
            $inventoryWarnings = (array) ($postResult['warnings'] ?? []);
        }

        $effectiveAt = $this->normalizeDateTime($effectiveAt);
        $updateStmt = $this->pdo->prepare(
            'UPDATE job_cards
             SET status = :status,
                 completed_at = CASE
                     WHEN :status IN ("COMPLETED", "CLOSED")
                     THEN COALESCE(completed_at, :effective_at)
                     ELSE completed_at
                 END,
                 closed_at = CASE
                     WHEN :status = "CLOSED"
                     THEN :effective_at
                     ELSE closed_at
                 END,
                 status_code = CASE
                     WHEN :status = "CANCELLED"
                     THEN "INACTIVE"
                     ELSE status_code
                 END,
                 cancel_note = CASE
                     WHEN :status = "CANCELLED"
                     THEN :cancel_note
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
            'effective_at' => $effectiveAt,
            'cancel_note' => $targetStatus === 'CANCELLED' ? mb_substr($statusNote, 0, 255) : null,
            'updated_by' => $this->actorUserId,
            'id' => $jobId,
            'company_id' => $this->companyId,
            'garage_id' => $garageId,
        ]);

        $reminderResult = [
            'created_count' => 0,
            'disabled_count' => 0,
            'created_types' => [],
            'warnings' => [],
        ];
        if ($targetStatus === 'CLOSED') {
            $reminderResult = service_reminder_apply_on_job_close(
                $jobId,
                $this->companyId,
                $garageId,
                $this->actorUserId,
                $reminderOverrides
            );
        }

        job_append_history(
            $jobId,
            'STATUS_CHANGE',
            $currentStatus,
            $targetStatus,
            mb_substr($statusNote, 0, 255),
            [
                'inventory_warnings' => count($inventoryWarnings),
                'reminders_created' => (int) ($reminderResult['created_count'] ?? 0),
                'reminders_disabled' => (int) ($reminderResult['disabled_count'] ?? 0),
            ]
        );

        return $reminderResult;
    }

    /**
     * @param array<int,array<string,mixed>> $previewRows
     * @return array<string,array<string,string>>
     */
    private function defaultReminderPostPayload(array $previewRows): array
    {
        $payload = [
            'reminder_enabled' => [],
            'reminder_last_km' => [],
            'reminder_interval_km' => [],
            'reminder_next_due_km' => [],
            'reminder_interval_days' => [],
            'reminder_next_due_date' => [],
            'reminder_note' => [],
        ];

        foreach ($previewRows as $row) {
            $serviceType = service_reminder_normalize_type((string) ($row['service_type'] ?? ''));
            if ($serviceType === '') {
                continue;
            }
            $payload['reminder_enabled'][$serviceType] = !empty($row['enabled']) ? '1' : '0';
            $payload['reminder_last_km'][$serviceType] = (string) ($row['last_service_km'] ?? '');
            $payload['reminder_interval_km'][$serviceType] = (string) ($row['interval_km'] ?? '');
            $payload['reminder_next_due_km'][$serviceType] = (string) ($row['next_due_km'] ?? '');
            $payload['reminder_interval_days'][$serviceType] = (string) ($row['interval_days'] ?? '');
            $payload['reminder_next_due_date'][$serviceType] = (string) ($row['next_due_date'] ?? '');
            $payload['reminder_note'][$serviceType] = (string) ($row['recommendation_text'] ?? '');
        }

        return $payload;
    }

    private function stampHistoricalTimeline(
        int $jobId,
        string $openedAt,
        string $inProgressAt,
        string $completedAt,
        string $closedAt
    ): void {
        $openedAt = $this->normalizeDateTime($openedAt);
        $inProgressAt = $this->normalizeDateTime($inProgressAt);
        $completedAt = $this->normalizeDateTime($completedAt);
        $closedAt = $this->normalizeDateTime($closedAt);

        $jobUpdate = $this->pdo->prepare(
            'UPDATE job_cards
             SET opened_at = :opened_at,
                 completed_at = :completed_at,
                 closed_at = :closed_at
             WHERE id = :id
               AND company_id = :company_id'
        );
        $jobUpdate->execute([
            'opened_at' => $openedAt,
            'completed_at' => $completedAt,
            'closed_at' => $closedAt,
            'id' => $jobId,
            'company_id' => $this->companyId,
        ]);

        $laborRows = $this->pdo->prepare(
            'SELECT id
             FROM job_labor
             WHERE job_card_id = :job_card_id
             ORDER BY id ASC'
        );
        $laborRows->execute(['job_card_id' => $jobId]);
        $updateLabor = $this->pdo->prepare('UPDATE job_labor SET created_at = :created_at, updated_at = :updated_at WHERE id = :id');
        $offsetMinutes = 10;
        foreach ($laborRows->fetchAll() as $row) {
            $laborTs = $this->shiftDateTime($openedAt, $offsetMinutes);
            if (strtotime($laborTs) >= strtotime($completedAt)) {
                $laborTs = $this->shiftDateTime($completedAt, -5);
            }
            $updateLabor->execute([
                'created_at' => $laborTs,
                'updated_at' => $laborTs,
                'id' => (int) ($row['id'] ?? 0),
            ]);
            $offsetMinutes += mt_rand(8, 20);
        }

        $historyStmt = $this->pdo->prepare(
            'SELECT id, action_type, from_status, to_status
             FROM job_history
             WHERE job_card_id = :job_card_id
             ORDER BY id ASC'
        );
        $historyStmt->execute(['job_card_id' => $jobId]);
        $historyRows = $historyStmt->fetchAll();
        $historyUpdate = $this->pdo->prepare('UPDATE job_history SET created_at = :created_at WHERE id = :id');
        $fallbackTs = $openedAt;
        foreach ($historyRows as $row) {
            $actionType = strtoupper(trim((string) ($row['action_type'] ?? '')));
            $toStatus = strtoupper(trim((string) ($row['to_status'] ?? '')));
            $historyTs = $fallbackTs;

            if ($actionType === 'CREATE') {
                $historyTs = $this->shiftDateTime($openedAt, 2);
            } elseif ($actionType === 'LABOR_ADD') {
                $historyTs = $this->shiftDateTime($openedAt, 15);
            } elseif ($actionType === 'STATUS_CHANGE' && $toStatus === 'IN_PROGRESS') {
                $historyTs = $inProgressAt;
            } elseif ($actionType === 'STATUS_CHANGE' && $toStatus === 'COMPLETED') {
                $historyTs = $completedAt;
            } elseif ($actionType === 'STATUS_CHANGE' && $toStatus === 'CLOSED') {
                $historyTs = $closedAt;
            } else {
                $historyTs = $this->shiftDateTime($fallbackTs, 4);
            }

            $historyUpdate->execute([
                'created_at' => $historyTs,
                'id' => (int) ($row['id'] ?? 0),
            ]);
            $fallbackTs = $this->shiftDateTime($historyTs, 2);
        }
    }

    private function updateVehicleOdometer(int $vehicleId, int $odometerKm): void
    {
        if ($vehicleId <= 0 || $odometerKm <= 0) {
            return;
        }

        $current = $this->currentVehicleOdometer($vehicleId);
        $nextValue = max($current, $odometerKm);
        $stmt = $this->pdo->prepare(
            'UPDATE vehicles
             SET odometer_km = :odometer_km
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'odometer_km' => $nextValue,
            'id' => $vehicleId,
            'company_id' => $this->companyId,
        ]);
        $this->currentOdometerByVehicle[$vehicleId] = $nextValue;
    }

    private function currentVehicleOdometer(int $vehicleId): int
    {
        if ($vehicleId <= 0) {
            return 0;
        }
        if (isset($this->currentOdometerByVehicle[$vehicleId])) {
            return max(0, (int) $this->currentOdometerByVehicle[$vehicleId]);
        }

        $stmt = $this->pdo->prepare(
            'SELECT odometer_km
             FROM vehicles
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $vehicleId,
            'company_id' => $this->companyId,
        ]);
        $odometer = (int) ($stmt->fetchColumn() ?: 0);
        $this->currentOdometerByVehicle[$vehicleId] = max(0, $odometer);

        return $this->currentOdometerByVehicle[$vehicleId];
    }

    private function intervalForVehicle(int $variantId, string $key, int $default): int
    {
        $default = max(1, $default);
        if ($variantId <= 0) {
            return $default;
        }
        $data = $this->intervalByVariantId[$variantId] ?? null;
        if (!is_array($data)) {
            return $default;
        }
        $value = isset($data[$key]) ? (int) $data[$key] : $default;
        return max(1, $value);
    }

    private function randomDateTimeInPast(int $minDaysAgo, int $maxDaysAgo, int $minHour = 9, int $maxHour = 16): string
    {
        $minDaysAgo = max(1, $minDaysAgo);
        $maxDaysAgo = max($minDaysAgo, $maxDaysAgo);
        $minHour = max(0, min(23, $minHour));
        $maxHour = max($minHour, min(23, $maxHour));
        $daysAgo = mt_rand($minDaysAgo, $maxDaysAgo);
        $hour = mt_rand($minHour, $maxHour);
        return $this->dateTimeDaysAgo($daysAgo, $hour);
    }

    private function dateTimeDaysAgo(int $daysAgo, int $hour): string
    {
        $daysAgo = max(1, $daysAgo);
        $hour = max(0, min(23, $hour));
        $minute = mt_rand(0, 59);
        $targetTs = strtotime('-' . $daysAgo . ' days');
        if ($targetTs === false) {
            $targetTs = time() - ($daysAgo * 86400);
        }
        $date = date('Y-m-d', $targetTs);
        $dateTime = sprintf('%s %02d:%02d:00', $date, $hour, $minute);
        return $this->normalizeDateTime($dateTime);
    }

    private function shiftDateTime(string $dateTime, int $minutes): string
    {
        $base = strtotime($dateTime);
        if ($base === false) {
            $base = time();
        }
        $shifted = $base + ($minutes * 60);
        return $this->normalizeDateTime(date('Y-m-d H:i:s', $shifted));
    }

    private function normalizeDateTime(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            $timestamp = time() - 86400;
        }

        $maxTs = strtotime('-2 hours');
        if ($maxTs !== false && $timestamp > $maxTs) {
            $timestamp = $maxTs;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function seedPrintValidationInvoices(int $targetCount): void
    {
        $targetCount = max(0, $targetCount);
        if ($targetCount === 0 || $this->jobsForPrintValidation === []) {
            return;
        }

        usort(
            $this->jobsForPrintValidation,
            static fn (array $a, array $b): int => strcmp((string) ($b['closed_at'] ?? ''), (string) ($a['closed_at'] ?? ''))
        );

        foreach ($this->jobsForPrintValidation as $row) {
            if ($this->invoicesCreated >= $targetCount) {
                break;
            }
            $jobId = (int) ($row['job_id'] ?? 0);
            $garageId = (int) ($row['garage_id'] ?? 0);
            if ($jobId <= 0 || $garageId <= 0) {
                continue;
            }
            $closedDate = service_reminder_extract_date((string) ($row['closed_at'] ?? '')) ?? date('Y-m-d');
            $invoiceDateTs = strtotime($closedDate . ' +1 day');
            $invoiceDate = $invoiceDateTs !== false ? date('Y-m-d', $invoiceDateTs) : $closedDate;
            $this->createDraftInvoiceForJob($jobId, $garageId, $invoiceDate);
        }
    }

    private function createDraftInvoiceForJob(int $jobId, int $garageId, string $invoiceDate): void
    {
        $invoiceDate = service_reminder_parse_date($invoiceDate) ?? date('Y-m-d');
        $dueDateTs = strtotime($invoiceDate . ' +7 days');
        $dueDate = $dueDateTs !== false ? date('Y-m-d', $dueDateTs) : null;

        $this->pdo->beginTransaction();
        try {
            $jobStmt = $this->pdo->prepare(
                'SELECT jc.id, jc.job_number, jc.customer_id, jc.vehicle_id, jc.status, jc.status_code, jc.closed_at,
                        cu.full_name AS customer_name, cu.phone AS customer_phone, cu.gstin AS customer_gstin,
                        cu.address_line1 AS customer_address_line1, cu.address_line2 AS customer_address_line2,
                        cu.city AS customer_city, cu.state AS customer_state, cu.pincode AS customer_pincode,
                        v.registration_no, v.brand, v.model, v.variant, v.fuel_type, v.model_year,
                        g.name AS garage_name, g.code AS garage_code, g.gstin AS garage_gstin,
                        g.address_line1 AS garage_address_line1, g.address_line2 AS garage_address_line2,
                        g.city AS garage_city, g.state AS garage_state, g.pincode AS garage_pincode,
                        c.name AS company_name, c.legal_name AS company_legal_name, c.gstin AS company_gstin,
                        c.address_line1 AS company_address_line1, c.address_line2 AS company_address_line2,
                        c.city AS company_city, c.state AS company_state, c.pincode AS company_pincode
                 FROM job_cards jc
                 INNER JOIN customers cu ON cu.id = jc.customer_id
                 INNER JOIN vehicles v ON v.id = jc.vehicle_id
                 INNER JOIN garages g ON g.id = jc.garage_id
                 INNER JOIN companies c ON c.id = jc.company_id
                 WHERE jc.id = :job_id
                   AND jc.company_id = :company_id
                   AND jc.garage_id = :garage_id
                   AND jc.status = "CLOSED"
                   AND jc.status_code = "ACTIVE"
                 LIMIT 1
                 FOR UPDATE'
            );
            $jobStmt->execute([
                'job_id' => $jobId,
                'company_id' => $this->companyId,
                'garage_id' => $garageId,
            ]);
            $job = $jobStmt->fetch();
            if (!$job) {
                $this->pdo->rollBack();
                return;
            }

            $existingStmt = $this->pdo->prepare('SELECT id FROM invoices WHERE job_card_id = :job_card_id LIMIT 1 FOR UPDATE');
            $existingStmt->execute(['job_card_id' => $jobId]);
            if ($existingStmt->fetch()) {
                $this->pdo->rollBack();
                return;
            }

            $laborStmt = $this->pdo->prepare(
                'SELECT jl.id, jl.service_id, jl.description, jl.quantity, jl.unit_price, jl.gst_rate, s.service_code
                 FROM job_labor jl
                 LEFT JOIN services s ON s.id = jl.service_id
                 WHERE jl.job_card_id = :job_id
                 ORDER BY jl.id ASC'
            );
            $laborStmt->execute(['job_id' => $jobId]);
            $laborLines = $laborStmt->fetchAll();
            if ($laborLines === []) {
                $this->pdo->rollBack();
                return;
            }

            $partsStmt = $this->pdo->prepare(
                'SELECT jp.id, jp.part_id, jp.quantity, jp.unit_price, jp.gst_rate, p.part_name, p.hsn_code
                 FROM job_parts jp
                 INNER JOIN parts p ON p.id = jp.part_id
                 WHERE jp.job_card_id = :job_id
                 ORDER BY jp.id ASC'
            );
            $partsStmt->execute(['job_id' => $jobId]);
            $partLines = $partsStmt->fetchAll();

            $taxRegime = billing_tax_regime((string) ($job['garage_state'] ?? ''), (string) ($job['customer_state'] ?? ''));
            $invoiceLines = [];
            foreach ($laborLines as $line) {
                $description = trim((string) ($line['description'] ?? ''));
                if ($description === '') {
                    $description = 'Labor Line #' . (int) ($line['id'] ?? 0);
                }
                $invoiceLines[] = billing_calculate_line(
                    'LABOR',
                    $description,
                    null,
                    (isset($line['service_id']) && (int) $line['service_id'] > 0) ? (int) $line['service_id'] : null,
                    isset($line['service_code']) ? (string) $line['service_code'] : null,
                    (float) ($line['quantity'] ?? 1),
                    (float) ($line['unit_price'] ?? 0),
                    (float) ($line['gst_rate'] ?? 0),
                    $taxRegime
                );
            }
            foreach ($partLines as $line) {
                $invoiceLines[] = billing_calculate_line(
                    'PART',
                    (string) ($line['part_name'] ?? 'Part Line'),
                    isset($line['part_id']) ? (int) $line['part_id'] : null,
                    null,
                    isset($line['hsn_code']) ? (string) $line['hsn_code'] : null,
                    (float) ($line['quantity'] ?? 1),
                    (float) ($line['unit_price'] ?? 0),
                    (float) ($line['gst_rate'] ?? 0),
                    $taxRegime
                );
            }

            $totals = billing_calculate_totals($invoiceLines);
            if (((float) ($totals['grand_total'] ?? 0.0)) <= 0.0) {
                $this->pdo->rollBack();
                return;
            }

            $numberMeta = billing_generate_invoice_number($this->pdo, $this->companyId, $garageId, $invoiceDate);
            $invoiceNumber = (string) ($numberMeta['invoice_number'] ?? '');
            if ($invoiceNumber === '') {
                throw new RuntimeException('Failed to generate invoice number for demo invoice.');
            }

            $snapshot = [
                'company' => [
                    'name' => (string) ($job['company_name'] ?? ''),
                    'legal_name' => (string) ($job['company_legal_name'] ?? ''),
                    'gstin' => (string) ($job['company_gstin'] ?? ''),
                    'address_line1' => (string) ($job['company_address_line1'] ?? ''),
                    'address_line2' => (string) ($job['company_address_line2'] ?? ''),
                    'city' => (string) ($job['company_city'] ?? ''),
                    'state' => (string) ($job['company_state'] ?? ''),
                    'pincode' => (string) ($job['company_pincode'] ?? ''),
                ],
                'garage' => [
                    'name' => (string) ($job['garage_name'] ?? ''),
                    'code' => (string) ($job['garage_code'] ?? ''),
                    'gstin' => (string) ($job['garage_gstin'] ?? ''),
                    'address_line1' => (string) ($job['garage_address_line1'] ?? ''),
                    'address_line2' => (string) ($job['garage_address_line2'] ?? ''),
                    'city' => (string) ($job['garage_city'] ?? ''),
                    'state' => (string) ($job['garage_state'] ?? ''),
                    'pincode' => (string) ($job['garage_pincode'] ?? ''),
                ],
                'customer' => [
                    'full_name' => (string) ($job['customer_name'] ?? ''),
                    'phone' => (string) ($job['customer_phone'] ?? ''),
                    'gstin' => (string) ($job['customer_gstin'] ?? ''),
                    'address_line1' => (string) ($job['customer_address_line1'] ?? ''),
                    'address_line2' => (string) ($job['customer_address_line2'] ?? ''),
                    'city' => (string) ($job['customer_city'] ?? ''),
                    'state' => (string) ($job['customer_state'] ?? ''),
                    'pincode' => (string) ($job['customer_pincode'] ?? ''),
                ],
                'vehicle' => [
                    'registration_no' => (string) ($job['registration_no'] ?? ''),
                    'brand' => (string) ($job['brand'] ?? ''),
                    'model' => (string) ($job['model'] ?? ''),
                    'variant' => (string) ($job['variant'] ?? ''),
                    'fuel_type' => (string) ($job['fuel_type'] ?? ''),
                    'model_year' => isset($job['model_year']) ? (int) $job['model_year'] : null,
                ],
                'job' => [
                    'id' => (int) ($job['id'] ?? 0),
                    'job_number' => (string) ($job['job_number'] ?? ''),
                    'closed_at' => (string) ($job['closed_at'] ?? ''),
                ],
                'billing' => [
                    'discount_type' => 'AMOUNT',
                    'discount_value' => 0.0,
                    'discount_amount' => 0.0,
                    'gross_before_discount' => (float) ($totals['gross_total'] ?? 0.0),
                    'net_after_discount' => (float) ($totals['net_total'] ?? 0.0),
                ],
                'tax_regime' => $taxRegime,
                'generated_at' => date('c'),
            ];

            $invoiceInsert = $this->pdo->prepare(
                'INSERT INTO invoices
                  (company_id, garage_id, invoice_number, job_card_id, customer_id, vehicle_id, invoice_date, due_date, invoice_status,
                   subtotal_service, subtotal_parts, taxable_amount, tax_regime,
                   cgst_rate, sgst_rate, igst_rate, cgst_amount, sgst_amount, igst_amount,
                   service_tax_amount, parts_tax_amount, total_tax_amount, gross_total, round_off, grand_total,
                   payment_status, payment_mode, notes, created_by, financial_year_id, financial_year_label, sequence_number, snapshot_json)
                 VALUES
                  (:company_id, :garage_id, :invoice_number, :job_card_id, :customer_id, :vehicle_id, :invoice_date, :due_date, "DRAFT",
                   :subtotal_service, :subtotal_parts, :taxable_amount, :tax_regime,
                   :cgst_rate, :sgst_rate, :igst_rate, :cgst_amount, :sgst_amount, :igst_amount,
                   :service_tax_amount, :parts_tax_amount, :total_tax_amount, :gross_total, :round_off, :grand_total,
                   "UNPAID", NULL, :notes, :created_by, :financial_year_id, :financial_year_label, :sequence_number, :snapshot_json)'
            );
            $invoiceInsert->execute([
                'company_id' => $this->companyId,
                'garage_id' => $garageId,
                'invoice_number' => $invoiceNumber,
                'job_card_id' => (int) $job['id'],
                'customer_id' => (int) $job['customer_id'],
                'vehicle_id' => (int) $job['vehicle_id'],
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'subtotal_service' => $totals['subtotal_service'],
                'subtotal_parts' => $totals['subtotal_parts'],
                'taxable_amount' => $totals['taxable_amount'],
                'tax_regime' => $taxRegime,
                'cgst_rate' => $totals['cgst_rate'],
                'sgst_rate' => $totals['sgst_rate'],
                'igst_rate' => $totals['igst_rate'],
                'cgst_amount' => $totals['cgst_amount'],
                'sgst_amount' => $totals['sgst_amount'],
                'igst_amount' => $totals['igst_amount'],
                'service_tax_amount' => $totals['service_tax_amount'],
                'parts_tax_amount' => $totals['parts_tax_amount'],
                'total_tax_amount' => $totals['total_tax_amount'],
                'gross_total' => $totals['gross_total'],
                'round_off' => $totals['round_off'],
                'grand_total' => $totals['grand_total'],
                'notes' => 'Generated by intelligent service reminder demo seeder.',
                'created_by' => $this->actorUserId,
                'financial_year_id' => (int) ($numberMeta['financial_year_id'] ?? 0),
                'financial_year_label' => (string) ($numberMeta['financial_year_label'] ?? ''),
                'sequence_number' => (int) ($numberMeta['sequence_number'] ?? 0),
                'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            ]);
            $invoiceId = (int) $this->pdo->lastInsertId();

            $itemInsert = $this->pdo->prepare(
                'INSERT INTO invoice_items
                  (invoice_id, item_type, description, part_id, service_id, hsn_sac_code, quantity, unit_price, gst_rate,
                   cgst_rate, sgst_rate, igst_rate, taxable_value, cgst_amount, sgst_amount, igst_amount, tax_amount, total_value)
                 VALUES
                  (:invoice_id, :item_type, :description, :part_id, :service_id, :hsn_sac_code, :quantity, :unit_price, :gst_rate,
                   :cgst_rate, :sgst_rate, :igst_rate, :taxable_value, :cgst_amount, :sgst_amount, :igst_amount, :tax_amount, :total_value)'
            );
            foreach ($invoiceLines as $line) {
                $itemInsert->execute([
                    'invoice_id' => $invoiceId,
                    'item_type' => (string) ($line['item_type'] ?? 'LABOR'),
                    'description' => (string) ($line['description'] ?? ''),
                    'part_id' => $line['part_id'] ?? null,
                    'service_id' => $line['service_id'] ?? null,
                    'hsn_sac_code' => $line['hsn_sac_code'] ?? null,
                    'quantity' => (float) ($line['quantity'] ?? 0),
                    'unit_price' => (float) ($line['unit_price'] ?? 0),
                    'gst_rate' => (float) ($line['gst_rate'] ?? 0),
                    'cgst_rate' => (float) ($line['cgst_rate'] ?? 0),
                    'sgst_rate' => (float) ($line['sgst_rate'] ?? 0),
                    'igst_rate' => (float) ($line['igst_rate'] ?? 0),
                    'taxable_value' => (float) ($line['taxable_value'] ?? 0),
                    'cgst_amount' => (float) ($line['cgst_amount'] ?? 0),
                    'sgst_amount' => (float) ($line['sgst_amount'] ?? 0),
                    'igst_amount' => (float) ($line['igst_amount'] ?? 0),
                    'tax_amount' => (float) ($line['tax_amount'] ?? 0),
                    'total_value' => (float) ($line['total_value'] ?? 0),
                ]);
            }

            billing_record_status_history(
                $this->pdo,
                $invoiceId,
                null,
                'DRAFT',
                'CREATE_DRAFT',
                'Draft invoice generated by intelligent service reminder demo seeder.',
                $this->actorUserId,
                [
                    'job_card_id' => $jobId,
                    'invoice_number' => $invoiceNumber,
                    'tax_regime' => $taxRegime,
                ]
            );

            $this->pdo->commit();
            $this->invoicesCreated++;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function printSummary(): void
    {
        $duplicatesStmt = $this->pdo->prepare(
            'SELECT vehicle_id, service_type, COUNT(*) AS active_count
             FROM service_reminders
             WHERE company_id = :company_id
               AND is_active = 1
               AND status_code = "ACTIVE"
             GROUP BY vehicle_id, service_type
             HAVING COUNT(*) > 1'
        );
        $duplicatesStmt->execute(['company_id' => $this->companyId]);
        $duplicateRows = $duplicatesStmt->fetchAll();
        if ($duplicateRows !== []) {
            throw new RuntimeException('Duplicate active reminders detected for same vehicle + service type.');
        }

        $activeCountStmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS active_total, COUNT(DISTINCT vehicle_id) AS active_vehicle_total
             FROM service_reminders
             WHERE company_id = :company_id
               AND is_active = 1
               AND status_code = "ACTIVE"'
        );
        $activeCountStmt->execute(['company_id' => $this->companyId]);
        $activeCounts = $activeCountStmt->fetch() ?: ['active_total' => 0, 'active_vehicle_total' => 0];

        $inactiveCountStmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM service_reminders
             WHERE company_id = :company_id
               AND (is_active = 0 OR status_code = "INACTIVE")'
        );
        $inactiveCountStmt->execute(['company_id' => $this->companyId]);
        $completedReminderCount = (int) $inactiveCountStmt->fetchColumn();

        $activeRows = service_reminder_fetch_active_for_scope($this->companyId, 0, $this->garageIds, 5000);
        $dueSummary = service_reminder_summary_counts($activeRows);

        $overdueByDateStmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM service_reminders sr
             WHERE sr.company_id = :company_id
               AND sr.is_active = 1
               AND sr.status_code = "ACTIVE"
               AND sr.next_due_date IS NOT NULL
               AND sr.next_due_date < CURDATE()'
        );
        $overdueByDateStmt->execute(['company_id' => $this->companyId]);
        $overdueByDate = (int) $overdueByDateStmt->fetchColumn();

        $overdueByKmStmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM service_reminders sr
             INNER JOIN vehicles v ON v.id = sr.vehicle_id
             WHERE sr.company_id = :company_id
               AND sr.is_active = 1
               AND sr.status_code = "ACTIVE"
               AND sr.next_due_km IS NOT NULL
               AND v.odometer_km >= sr.next_due_km'
        );
        $overdueByKmStmt->execute(['company_id' => $this->companyId]);
        $overdueByKm = (int) $overdueByKmStmt->fetchColumn();

        $reminderJobCountStmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT job_card_id)
             FROM service_reminders
             WHERE company_id = :company_id
               AND status_code = "ACTIVE"
               AND job_card_id IS NOT NULL'
        );
        $reminderJobCountStmt->execute(['company_id' => $this->companyId]);
        $jobsWithReminder = (int) $reminderJobCountStmt->fetchColumn();

        echo 'Seeded intelligent reminder demo data successfully.' . PHP_EOL;
        echo 'Jobs created: ' . $this->jobsCreated . PHP_EOL;
        echo 'Labor lines created: ' . $this->laborLinesCreated . PHP_EOL;
        echo 'Draft invoices created for print validation: ' . $this->invoicesCreated . PHP_EOL;
        echo 'Vehicles with active reminders: ' . (int) ($activeCounts['active_vehicle_total'] ?? 0) . PHP_EOL;
        echo 'Active reminders total: ' . (int) ($activeCounts['active_total'] ?? 0) . PHP_EOL;
        echo 'Overdue reminders: ' . (int) ($dueSummary['overdue'] ?? 0) . PHP_EOL;
        echo 'Upcoming reminders: ' . (int) ($dueSummary['upcoming'] ?? 0) . PHP_EOL;
        echo 'Due soon reminders: ' . (int) ($dueSummary['due_soon'] ?? 0) . PHP_EOL;
        echo 'Completed reminders (inactive): ' . $completedReminderCount . PHP_EOL;
        echo 'Overdue by KM count: ' . $overdueByKm . PHP_EOL;
        echo 'Overdue by date count: ' . $overdueByDate . PHP_EOL;
        echo 'Closed jobs that generated reminders: ' . $jobsWithReminder . PHP_EOL;
        echo 'Manual override cases:' . PHP_EOL;
        echo '  edited next_due_km: ' . (int) ($this->manualOverrides['edited_next_due_km'] ?? 0) . PHP_EOL;
        echo '  disabled reminders: ' . (int) ($this->manualOverrides['disabled'] ?? 0) . PHP_EOL;
        echo '  custom recommendation note: ' . (int) ($this->manualOverrides['custom_note'] ?? 0) . PHP_EOL;
    }
}

try {
    $seeder = new ServiceReminderDemoSeeder();
    $seeder->run();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Service reminder demo seeding failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
