<?php
declare(strict_types=1);

require_once __DIR__ . '/regression_common.php';
require_once __DIR__ . '/seed_regression_dataset.php';
require_once __DIR__ . '/regression_concurrency_suite.php';

final class RegressionCrudRunner
{
    private RegressionHarness $h;
    private bool $jsonMode = false;
    private bool $runConcurrency = false;
    private bool $runPerformance = false;

    /** @var array<int,array<string,mixed>> */
    private array $results = [];
    /** @var array<string,mixed> */
    private array $runMeta = [];

    public function __construct(RegressionHarness $h, array $argv)
    {
        $this->h = $h;
        $this->jsonMode = in_array('--json', $argv, true);
        $this->runConcurrency = in_array('--with-concurrency', $argv, true);
        $this->runPerformance = in_array('--with-performance', $argv, true);
    }

    public function run(): int
    {
        $startedAt = microtime(true);
        $this->runMeta = [
            'started_at' => date('c'),
            'scope' => [
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'dataset_tag' => $this->h->datasetTag,
            ],
            'flags' => [
                'with_concurrency' => $this->runConcurrency,
                'with_performance' => $this->runPerformance,
            ],
        ];

        $this->h->logLine('Preparing deterministic baseline...');
        $seedCode = (new RegressionDatasetSeeder($this->h))->run(false);
        if ($seedCode !== 0) {
            throw new RuntimeException('Baseline seeding failed.');
        }

        $baseline = $this->h->captureSnapshot();
        $this->writeSnapshot('baseline_before_tests', $baseline);

        $this->runModuleCrudSuite();

        if ($this->runConcurrency) {
            $this->runConcurrencyPhase();
        }
        if ($this->runPerformance) {
            $this->runPerformancePhase();
        }

        $final = $this->h->captureSnapshot();
        $this->writeSnapshot('final_after_tests', $final);

        $semanticBefore = $this->semanticSnapshot($baseline);
        $semanticAfter = $this->semanticSnapshot($final);
        $semanticExpectedZero = $this->zeroExpectedFromSnapshot($semanticBefore);
        $semanticErrors = $this->h->compareExpectedDelta($semanticBefore, $semanticAfter, $semanticExpectedZero);
        $fullDiff = $this->h->snapshotDiff($baseline, $final);

        $summary = $this->summarize($semanticErrors, $fullDiff, microtime(true) - $startedAt);
        $report = [
            'meta' => $this->runMeta,
            'summary' => $summary,
            'module_results' => $this->results,
            'baseline_snapshot' => $baseline,
            'final_snapshot' => $final,
            'semantic_final_comparison_errors' => $semanticErrors,
            'full_snapshot_diff' => $fullDiff,
        ];

        $this->h->writeJson(__DIR__ . '/regression_outputs/run_regression_tests.latest.json', $report);

        if ($this->jsonMode) {
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        } else {
            $this->printSummary($summary);
        }

        return (($summary['failed_steps'] ?? 1) === 0 && empty($semanticErrors)) ? 0 : 1;
    }

    private function writeSnapshot(string $name, array $snapshot): void
    {
        $safe = preg_replace('/[^a-z0-9_]+/i', '_', $name) ?: 'snapshot';
        $this->h->writeJson(__DIR__ . '/regression_outputs/' . $safe . '.json', $snapshot);
    }

    private function semanticSnapshot(array $snapshot): array
    {
        $semantic = [
            'counts' => $snapshot['counts'] ?? [],
            'stock_totals' => [
                'garage_quantity_total' => $snapshot['stock_totals']['garage_quantity_total'] ?? 0,
                'movement_quantity_total' => $snapshot['stock_totals']['movement_quantity_total'] ?? 0,
                'garage_stock_value_cost' => $snapshot['stock_totals']['garage_stock_value_cost'] ?? 0,
            ],
            'report_totals' => $snapshot['report_totals'] ?? [],
            'ledger_net_by_account' => [],
        ];

        foreach ((array) ($snapshot['ledger_totals']['by_account'] ?? []) as $code => $row) {
            $semantic['ledger_net_by_account'][(string) $code] = ['net' => (float) ($row['net'] ?? 0)];
        }

        return $semantic;
    }

    private function zeroExpectedFromSnapshot(array $snapshot): array
    {
        $expected = [];
        $flat = $this->h->flattenNumeric($snapshot, '');
        foreach ($flat as $path => $_) {
            $expected[$path] = 0.0;
        }
        // Unflatten is unnecessary because compareExpectedDelta flattens again.
        return $expected;
    }

    private function expected(array $pairs): array
    {
        // Convenience helper for step deltas. Accepts dot paths => numeric.
        return $pairs;
    }

    private function withValidationAndSnapshot(
        string $module,
        string $step,
        callable $operation,
        array $expectedDeltaDotPaths = []
    ): void {
        $before = $this->h->captureSnapshot();
        $opResult = $operation();
        $after = $this->h->captureSnapshot();
        $validation = $this->h->validateAll();

        $expected = $this->expected($expectedDeltaDotPaths);
        $deltaErrors = $this->h->compareExpectedDelta($before, $after, $expected);
        $actualDiff = $this->h->snapshotDiff($before, $after);

        $status = 'PASS';
        if (!empty($deltaErrors) || strtoupper((string) ($validation['status'] ?? 'FAIL')) === 'FAIL') {
            $status = 'FAIL';
        } elseif (strtoupper((string) ($validation['status'] ?? 'PASS')) === 'WARN') {
            $status = 'WARN';
        }

        $entry = [
            'module' => $module,
            'step' => $step,
            'status' => $status,
            'operation_result' => $opResult,
            'expected_delta' => $expected,
            'delta_errors' => $deltaErrors,
            'validation' => $validation,
            'snapshot_diff' => $actualDiff,
        ];
        $this->results[] = $entry;

        if ($status === 'FAIL') {
            $this->h->writeJson(
                __DIR__ . '/regression_outputs/failures/' . strtolower($module . '_' . $step) . '_' . date('Ymd_His') . '.json',
                $entry
            );
        }
    }

    private function summarize(array $semanticErrors, array $fullDiff, float $elapsedSec): array
    {
        $failed = 0;
        $warn = 0;
        foreach ($this->results as $row) {
            $s = strtoupper((string) ($row['status'] ?? 'FAIL'));
            if ($s === 'FAIL') {
                $failed++;
            } elseif ($s === 'WARN') {
                $warn++;
            }
        }

        return [
            'overall_status' => ($failed === 0 && empty($semanticErrors)) ? 'PASS' : 'FAIL',
            'step_count' => count($this->results),
            'failed_steps' => $failed,
            'warning_steps' => $warn,
            'semantic_final_mismatch_count' => count($semanticErrors),
            'full_snapshot_diff_count' => count($fullDiff),
            'elapsed_seconds' => round($elapsedSec, 3),
            'finished_at' => date('c'),
        ];
    }

    private function printSummary(array $summary): void
    {
        $this->h->logLine('Regression CRUD Runner [' . (string) ($summary['overall_status'] ?? 'FAIL') . ']');
        $this->h->logLine('Steps=' . (int) ($summary['step_count'] ?? 0) . ' Fail=' . (int) ($summary['failed_steps'] ?? 0) . ' Warn=' . (int) ($summary['warning_steps'] ?? 0));
        $this->h->logLine('Final semantic mismatches=' . (int) ($summary['semantic_final_mismatch_count'] ?? 0));
        $this->h->logLine('Elapsed=' . (float) ($summary['elapsed_seconds'] ?? 0) . 's');
        foreach ($this->results as $row) {
            $this->h->logLine(' - [' . (string) $row['status'] . '] ' . $row['module'] . ' :: ' . $row['step']);
        }
    }

    private function runModuleCrudSuite(): void
    {
        $this->runVendorCrud();
        $this->runPartCrud();
        $this->runCustomerCrud();
        $this->runVehicleCrud();
        $this->runJobCardCrud();
        $this->runPurchaseCrud();
        $this->runPurchasePaymentCrud();
        $this->runInvoiceCrud();
        $this->runPaymentCrud();
        $this->runAdvanceCrud();
        $this->runReturnCrud();
        $this->runPayrollCrud();
        $this->runExpenseCrud();
        $this->runOutsourcedCrud();
    }

    private function stepExpectedFromDot(array $dotMap): array
    {
        // compareExpectedDelta flattens arrays, so dot-path map is already valid.
        return $dotMap;
    }

    private function softDeleteById(string $table, int $id, string $statusDeleted = 'DELETED'): void
    {
        $payload = [];
        if ($this->h->hasColumn($table, 'status_code')) $payload['status_code'] = $statusDeleted;
        if ($this->h->hasColumn($table, 'deleted_at')) $payload['deleted_at'] = date('Y-m-d H:i:s');
        if ($this->h->hasColumn($table, 'deleted_by')) $payload['deleted_by'] = $this->h->adminUserId;
        if ($this->h->hasColumn($table, 'deletion_reason')) $payload['deletion_reason'] = $this->h->datasetTag . ' TEST reverse';
        if ($payload !== []) {
            $this->h->updateById($table, $id, $payload);
        }
    }

    private function purchaseDependencyState(int $purchaseId, int $partId, int $vendorId): array
    {
        $purchase = $this->h->qr(
            'SELECT id, grand_total, payment_status, purchase_status
             FROM purchases
             WHERE id = :id AND company_id = :c LIMIT 1',
            ['id' => $purchaseId, 'c' => $this->h->companyId]
        );
        $grand = round((float) ($purchase['grand_total'] ?? 0), 2);
        $paid = $this->h->effectivePaymentTotal('purchase_payments', 'purchase_id', $purchaseId);
        $outstanding = round(max($grand - $paid, 0.0), 2);

        $stockQty = (float) ($this->h->qv(
            'SELECT COALESCE(quantity, 0)
             FROM garage_inventory
             WHERE garage_id = :g AND part_id = :p
             LIMIT 1',
            ['g' => $this->h->garageId, 'p' => $partId]
        ) ?? 0);
        $movementQty = (float) ($this->h->qv(
            'SELECT COALESCE(SUM(CASE
                WHEN deleted_at IS NOT NULL THEN 0
                WHEN movement_type = "IN" THEN ABS(quantity)
                WHEN movement_type = "OUT" THEN -ABS(quantity)
                ELSE quantity
            END), 0)
             FROM inventory_movements
             WHERE company_id = :c
               AND garage_id = :g
               AND part_id = :p',
            ['c' => $this->h->companyId, 'g' => $this->h->garageId, 'p' => $partId]
        ) ?? 0);

        $vendorOutstanding = 0.0;
        $vendorPurchases = $this->h->qa(
            'SELECT id, grand_total
             FROM purchases
             WHERE company_id = :c
               AND vendor_id = :v
               AND purchase_status = "FINALIZED"
               AND COALESCE(status_code, "ACTIVE") <> "DELETED"',
            ['c' => $this->h->companyId, 'v' => $vendorId]
        );
        foreach ($vendorPurchases as $vendorPurchase) {
            $vendorPurchaseId = (int) ($vendorPurchase['id'] ?? 0);
            if ($vendorPurchaseId <= 0) {
                continue;
            }
            $vendorGrand = round((float) ($vendorPurchase['grand_total'] ?? 0), 2);
            $vendorPaid = $this->h->tableExists('purchase_payments')
                ? $this->h->effectivePaymentTotal('purchase_payments', 'purchase_id', $vendorPurchaseId)
                : 0.0;
            $vendorOutstanding += max($vendorGrand - $vendorPaid, 0.0);
        }
        $vendorOutstanding = round($vendorOutstanding, 2);

        return [
            'purchase_id' => $purchaseId,
            'purchase_status' => (string) ($purchase['purchase_status'] ?? ''),
            'purchase_payment_status' => (string) ($purchase['payment_status'] ?? ''),
            'purchase_grand_total' => $grand,
            'purchase_paid_total' => $paid,
            'purchase_outstanding_total' => $outstanding,
            'vendor_outstanding_total' => round($vendorOutstanding, 2),
            'stock_quantity_part' => round($stockQty, 2),
            'stock_movement_part' => round($movementQty, 2),
        ];
    }

    private function runConcurrencyPhase(): void
    {
        $before = $this->h->captureSnapshot();
        $operationResult = $this->executeParallelConcurrencySuite();
        $after = $this->h->captureSnapshot();
        $validation = $this->h->validateAll();

        // Concurrency cleanup leaves audit reversal journals by design; compare only semantic
        // totals (not raw journal/entry row counts) to avoid false negatives.
        $semanticBefore = $this->semanticSnapshot($before);
        $semanticAfter = $this->semanticSnapshot($after);
        $expectedZero = $this->zeroExpectedFromSnapshot($semanticBefore);
        $deltaErrors = $this->h->compareExpectedDelta($semanticBefore, $semanticAfter, $expectedZero);
        $actualDiff = $this->h->snapshotDiff($before, $after);

        $status = strtoupper((string) ($operationResult['status'] ?? 'PASS'));
        if (!in_array($status, ['PASS', 'WARN', 'FAIL'], true)) {
            $status = 'PASS';
        }
        if (!empty($deltaErrors) || strtoupper((string) ($validation['status'] ?? 'FAIL')) === 'FAIL') {
            $status = 'FAIL';
        } elseif (strtoupper((string) ($validation['status'] ?? 'PASS')) === 'WARN' && $status !== 'FAIL') {
            $status = 'WARN';
        }

        $entry = [
            'module' => 'concurrency',
            'step' => 'parallel_contention_workers',
            'status' => $status,
            'operation_result' => $operationResult,
            'expected_delta' => $expectedZero,
            'delta_errors' => $deltaErrors,
            'validation' => $validation,
            'snapshot_diff' => $actualDiff,
        ];
        $this->results[] = $entry;

        if ($status === 'FAIL') {
            $this->h->writeJson(
                __DIR__ . '/regression_outputs/failures/concurrency_parallel_contention_workers_' . date('Ymd_His') . '.json',
                $entry
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function executeParallelConcurrencySuite(): array
    {
        return (new RegressionConcurrencySuite($this->h))->run();
    }

    private function runPerformancePhase(): void
    {
        // Non-destructive performance probe on representative report queries. Bulk volume
        // generation is implemented as a separate method and skipped by default in CI.
        $timings = [];
        $queries = [
            'dashboard_sales_summary' => [
                'sql' => 'SELECT COUNT(*) c, COALESCE(SUM(grand_total),0) total FROM invoices WHERE company_id = :c AND invoice_status = "FINALIZED" AND deleted_at IS NULL',
                'params' => ['c' => $this->h->companyId],
            ],
            'stock_summary' => [
                'sql' => 'SELECT COUNT(*) c, COALESCE(SUM(quantity),0) qty FROM garage_inventory WHERE garage_id = :g',
                'params' => ['g' => $this->h->garageId],
            ],
            'ledger_report' => [
                'sql' => 'SELECT coa.code, SUM(le.debit_amount) d, SUM(le.credit_amount) c
                          FROM ledger_entries le
                          INNER JOIN ledger_journals lj ON lj.id = le.journal_id
                          INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
                          WHERE lj.company_id = :c
                          GROUP BY coa.code',
                'params' => ['c' => $this->h->companyId],
            ],
        ];

        foreach ($queries as $name => $def) {
            $t0 = microtime(true);
            $this->h->qa((string) $def['sql'], (array) $def['params']);
            $timings[$name] = round((microtime(true) - $t0) * 1000, 2);
        }

        $slow = array_filter($timings, static fn (float $ms): bool => $ms > 250.0);
        $indexes = $this->h->qa(
            'SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ("invoices","garage_inventory","ledger_journals","ledger_entries","inventory_movements")
             GROUP BY TABLE_NAME, INDEX_NAME
             ORDER BY TABLE_NAME, INDEX_NAME'
        );

        $this->results[] = [
            'module' => 'performance',
            'step' => 'report_query_timing_probe',
            'status' => empty($slow) ? 'PASS' : 'WARN',
            'timings_ms' => $timings,
            'slow_queries' => $slow,
            'index_inventory' => $indexes,
            'notes' => 'Bulk 5000-invoice/10000-stock synthetic generation is intentionally opt-in and should be run on an isolated staging clone.',
        ];
    }

    private function runVendorCrud(): void
    {
        $id = 990101;
        $module = 'vendors';
        $create = [
            'id' => $id,
            'company_id' => $this->h->companyId,
            'vendor_code' => 'REG-TEST-VEN-01',
            'vendor_name' => $this->h->datasetTag . ' TEST Vendor',
            'contact_person' => 'Regression Test Contact',
            'phone' => '+91-5000000001',
            'email' => 'reg.test.vendor@example.test',
            'gstin' => '27AAACT9001T1Z1',
            'address_line1' => 'Regression Test Vendor Street',
            'city' => 'Pune',
            'state' => 'Maharashtra',
            'pincode' => '411099',
            'status_code' => 'ACTIVE',
        ];

        $this->withValidationAndSnapshot($module, 'CREATE', function () use ($create): array {
            $this->h->insert('vendors', $create);
            return ['id' => $create['id']];
        }, ['counts.vendors' => 1]);

        $this->withValidationAndSnapshot($module, 'EDIT', function () use ($id): array {
            $this->h->updateById('vendors', $id, ['contact_person' => 'Regression Test Contact Edited', 'city' => 'Mumbai']);
            return ['id' => $id];
        });

        $this->withValidationAndSnapshot($module, 'DELETE_REVERSE', function () use ($id): array {
            $this->softDeleteById('vendors', $id);
            return ['id' => $id];
        }, ['counts.vendors' => -1]);
    }

    private function runPartCrud(): void
    {
        $id = 990201;
        $module = 'parts';
        $create = [
            'id' => $id,
            'company_id' => $this->h->companyId,
            'part_name' => $this->h->datasetTag . ' TEST Part',
            'part_sku' => 'REG-TEST-PART-01',
            'hsn_code' => '87089999',
            'unit' => 'PCS',
            'purchase_price' => 111.00,
            'selling_price' => 159.00,
            'gst_rate' => 18.00,
            'min_stock' => 1.00,
            'is_active' => 1,
            'category_id' => $this->h->tableExists('part_categories') ? 910050 : null,
            'vendor_id' => 910101,
            'status_code' => 'ACTIVE',
        ];

        $this->withValidationAndSnapshot($module, 'CREATE', function () use ($create): array {
            $this->h->insert('parts', $create);
            return ['id' => $create['id']];
        }, ['counts.parts' => 1]);

        $this->withValidationAndSnapshot($module, 'EDIT', function () use ($id): array {
            $this->h->updateById('parts', $id, ['selling_price' => 169.00, 'part_name' => $this->h->datasetTag . ' TEST Part Edited']);
            return ['id' => $id];
        });

        $this->withValidationAndSnapshot($module, 'DELETE_REVERSE', function () use ($id): array {
            $this->softDeleteById('parts', $id);
            return ['id' => $id];
        }, ['counts.parts' => -1]);
    }

    private function runCustomerCrud(): void
    {
        $id = 990301;
        $module = 'customers';
        $create = [
            'id' => $id,
            'company_id' => $this->h->companyId,
            'created_by' => $this->h->adminUserId,
            'full_name' => $this->h->datasetTag . ' TEST Customer',
            'phone' => '+91-5100000001',
            'email' => 'reg.test.customer@example.test',
            'address_line1' => 'Regression Test Customer Addr',
            'city' => 'Pune',
            'state' => 'Maharashtra',
            'pincode' => '411098',
            'notes' => $this->h->datasetTag . ' TEST customer',
            'is_active' => 1,
            'status_code' => 'ACTIVE',
        ];

        $this->withValidationAndSnapshot($module, 'CREATE', function () use ($create): array {
            $this->h->insert('customers', $create);
            return ['id' => $create['id']];
        }, ['counts.customers' => 1]);

        $this->withValidationAndSnapshot($module, 'EDIT', function () use ($id): array {
            $this->h->updateById('customers', $id, ['phone' => '+91-5100000002', 'notes' => $this->h->datasetTag . ' TEST customer edited']);
            return ['id' => $id];
        });

        $this->withValidationAndSnapshot($module, 'DELETE_REVERSE', function () use ($id): array {
            $this->softDeleteById('customers', $id);
            return ['id' => $id];
        }, ['counts.customers' => -1]);
    }

    private function runVehicleCrud(): void
    {
        $id = 990401;
        $module = 'vehicles';
        $create = [
            'id' => $id,
            'company_id' => $this->h->companyId,
            'customer_id' => 910301,
            'registration_no' => 'MH12TST9901',
            'vehicle_type' => '4W',
            'brand' => 'TestBrand',
            'model' => 'TestModel',
            'variant' => 'X',
            'fuel_type' => 'PETROL',
            'model_year' => 2024,
            'color' => 'Black',
            'chassis_no' => 'CHS-TST-990401',
            'engine_no' => 'ENG-TST-990401',
            'odometer_km' => 12345,
            'notes' => $this->h->datasetTag . ' TEST vehicle',
            'is_active' => 1,
            'status_code' => 'ACTIVE',
        ];

        $this->withValidationAndSnapshot($module, 'CREATE', function () use ($create): array {
            $this->h->insert('vehicles', $create);
            return ['id' => $create['id']];
        }, ['counts.vehicles' => 1]);

        $this->withValidationAndSnapshot($module, 'EDIT', function () use ($id): array {
            $this->h->updateById('vehicles', $id, ['odometer_km' => 12456, 'notes' => $this->h->datasetTag . ' TEST vehicle edited']);
            return ['id' => $id];
        });

        $this->withValidationAndSnapshot($module, 'DELETE_REVERSE', function () use ($id): array {
            $this->softDeleteById('vehicles', $id);
            return ['id' => $id];
        }, ['counts.vehicles' => -1]);
    }

    private function runJobCardCrud(): void
    {
        $id = 990501;
        $module = 'job_cards';
        $create = [
            'id' => $id,
            'company_id' => $this->h->companyId,
            'garage_id' => $this->h->garageId,
            'job_number' => 'REG-TEST-JOB-0001',
            'customer_id' => 910301,
            'vehicle_id' => 910401,
            'odometer_km' => 20000,
            'assigned_to' => $this->h->staffUserIds[0] ?? $this->h->adminUserId,
            'service_advisor_id' => $this->h->adminUserId,
            'complaint' => $this->h->datasetTag . ' TEST job create',
            'diagnosis' => 'Test diagnosis',
            'status' => 'OPEN',
            'priority' => 'MEDIUM',
            'estimated_cost' => 0.00,
            'opened_at' => date('Y-m-d H:i:s'),
            'created_by' => $this->h->adminUserId,
            'updated_by' => $this->h->adminUserId,
            'status_code' => 'ACTIVE',
        ];

        $this->withValidationAndSnapshot($module, 'CREATE', function () use ($create): array {
            $this->h->insert('job_cards', $create);
            return ['id' => $create['id']];
        }, ['counts.job_cards' => 1]);

        $this->withValidationAndSnapshot($module, 'EDIT', function () use ($id): array {
            $this->h->updateById('job_cards', $id, ['status' => 'IN_PROGRESS', 'complaint' => $this->h->datasetTag . ' TEST job edited']);
            return ['id' => $id];
        });

        $this->withValidationAndSnapshot($module, 'DELETE_REVERSE', function () use ($id): array {
            $this->softDeleteById('job_cards', $id);
            return ['id' => $id];
        }, ['counts.job_cards' => -1]);
    }

    private function runOutsourcedCrud(): void
    {
        $module = 'outsourced_jobs';
        $jobId = 910516; // seeded open job, no invoice
        $jobLaborId = 994001;
        $workId = 994101;
        $agreedCost = 950.00;

        $this->withValidationAndSnapshot($module, 'CREATE', function () use ($jobId, $jobLaborId, $workId, $agreedCost): array {
            $this->h->insert('job_labor', [
                'id' => $jobLaborId,
                'job_card_id' => $jobId,
                'description' => $this->h->datasetTag . ' TEST outsource labor',
                'quantity' => 1.00,
                'unit_price' => 1500.00,
                'gst_rate' => 18.00,
                'total_amount' => 1500.00,
                'service_id' => $this->h->tableExists('services') ? 910071 : null,
                'execution_type' => 'OUTSOURCED',
                'outsource_vendor_id' => 910101,
                'outsource_partner_name' => 'Regression Test Vendor',
                'outsource_cost' => $agreedCost,
                'outsource_expected_return_date' => date('Y-m-d', strtotime('+2 days')),
                'outsource_payable_status' => 'UNPAID',
            ]);

            $this->h->insert('outsourced_works', [
                'id' => $workId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'job_card_id' => $jobId,
                'job_labor_id' => $jobLaborId,
                'vendor_id' => 910101,
                'partner_name' => 'Regression Test Vendor',
                'service_description' => $this->h->datasetTag . ' TEST outsource work',
                'agreed_cost' => $agreedCost,
                'expected_return_date' => date('Y-m-d', strtotime('+2 days')),
                'current_status' => 'SENT',
                'sent_at' => date('Y-m-d H:i:s'),
                'notes' => $this->h->datasetTag . ' TEST outsourced create',
                'status_code' => 'ACTIVE',
                'created_by' => $this->h->adminUserId,
                'updated_by' => $this->h->adminUserId,
            ]);
            return ['job_labor_id' => $jobLaborId, 'outsourced_work_id' => $workId];
        }, [
            'counts.outsourced_jobs' => 1,
            'report_totals.outsourced.work_count' => 1,
            'report_totals.outsourced.agreed_cost_total' => $agreedCost,
        ]);

        $this->withValidationAndSnapshot($module, 'EDIT', function () use ($workId): array {
            $this->h->updateById('outsourced_works', $workId, [
                'current_status' => 'RECEIVED',
                'received_at' => date('Y-m-d H:i:s'),
                'notes' => $this->h->datasetTag . ' TEST outsourced edited',
                'updated_by' => $this->h->adminUserId,
            ]);
            return ['outsourced_work_id' => $workId];
        });

        $this->withValidationAndSnapshot($module, 'DELETE_REVERSE', function () use ($workId, $jobLaborId, $agreedCost): array {
            $this->softDeleteById('outsourced_works', $workId);
            $this->h->exec('DELETE FROM job_labor WHERE id = :id', ['id' => $jobLaborId]);
            return ['outsourced_work_id' => $workId, 'job_labor_id' => $jobLaborId];
        }, [
            'counts.outsourced_jobs' => -1,
            'report_totals.outsourced.work_count' => -1,
            'report_totals.outsourced.agreed_cost_total' => -$agreedCost,
        ]);
    }

    private function runPurchaseCrud(): void
    {
        $module = 'purchases';
        $purchaseId = 995201;
        $itemId = 995211;
        $partId = 910210;
        $qty = 4.0;
        $part = $this->h->qr('SELECT * FROM parts WHERE id = :id AND company_id = :c LIMIT 1', ['id' => $partId, 'c' => $this->h->companyId]);
        $unitCost = round((float) ($part['purchase_price'] ?? 300), 2);
        $taxable = round($qty * $unitCost, 2);
        $gst = round($taxable * 0.18, 2);
        $grand = round($taxable + $gst, 2);
        $movementUid = 'REG-TEST-PUR-' . $purchaseId . '-' . $partId;

        $this->withValidationAndSnapshot($module, 'CREATE', function () use ($purchaseId, $itemId, $partId, $qty, $unitCost, $taxable, $gst, $grand, $movementUid): array {
            $purchase = [
                'id' => $purchaseId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'vendor_id' => 910101,
                'invoice_number' => 'REG-TEST-PUR-0001',
                'purchase_date' => date('Y-m-d'),
                'purchase_source' => 'VENDOR_ENTRY',
                'assignment_status' => 'ASSIGNED',
                'purchase_status' => 'FINALIZED',
                'payment_status' => 'UNPAID',
                'status_code' => 'ACTIVE',
                'taxable_amount' => $taxable,
                'gst_amount' => $gst,
                'grand_total' => $grand,
                'notes' => $this->h->datasetTag . ' TEST purchase create',
                'created_by' => $this->h->adminUserId,
                'finalized_by' => $this->h->adminUserId,
                'finalized_at' => date('Y-m-d H:i:s'),
            ];
            $this->h->insert('purchases', $purchase);
            $this->h->insert('purchase_items', [
                'id' => $itemId,
                'purchase_id' => $purchaseId,
                'part_id' => $partId,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'gst_rate' => 18.00,
                'taxable_amount' => $taxable,
                'gst_amount' => $gst,
                'total_amount' => $grand,
            ]);
            $this->h->recordInventoryMovement([
                'part_id' => $partId,
                'movement_type' => 'IN',
                'quantity' => $qty,
                'reference_type' => 'PURCHASE',
                'reference_id' => $purchaseId,
                'movement_uid' => $movementUid,
                'notes' => $this->h->datasetTag . ' TEST purchase stock',
            ]);
            ledger_post_purchase_finalized($this->h->pdo, $purchase, $this->h->adminUserId);
            return ['purchase_id' => $purchaseId, 'purchase_item_id' => $itemId, 'movement_uid' => $movementUid];
        }, [
            'counts.purchases' => 1,
            'stock_totals.garage_quantity_total' => $qty,
            'stock_totals.movement_quantity_total' => $qty,
            'report_totals.purchases.purchase_count' => 1,
            'report_totals.purchases.taxable_total' => $taxable,
            'report_totals.purchases.gst_total' => $gst,
            'report_totals.purchases.grand_total' => $grand,
            'report_totals.payables.purchases_total' => $grand,
            'report_totals.payables.outstanding_total' => $grand,
            'ledger_totals.by_account.1300.net' => $taxable,
            'ledger_totals.by_account.1215.net' => $gst,
            'ledger_totals.by_account.2100.net' => -$grand,
        ]);

        $this->withValidationAndSnapshot($module, 'EDIT', function () use ($purchaseId): array {
            $this->h->updateById('purchases', $purchaseId, ['notes' => $this->h->datasetTag . ' TEST purchase edited']);
            return ['purchase_id' => $purchaseId];
        });

        $this->withValidationAndSnapshot($module, 'DELETE_REVERSE', function () use ($purchaseId, $movementUid): array {
            $this->h->softDeleteInventoryMovementByUid($movementUid, $this->h->datasetTag . ' TEST purchase reverse');
            $this->h->reverseLedgerReference('PURCHASE_FINALIZE', $purchaseId, 'REG_TEST_PURCHASE_REV', $purchaseId, date('Y-m-d'));
            $this->softDeleteById('purchases', $purchaseId);
            $this->h->updateById('purchases', $purchaseId, ['payment_status' => 'UNPAID']);
            return ['purchase_id' => $purchaseId];
        }, [
            'counts.purchases' => -1,
            'stock_totals.garage_quantity_total' => -$qty,
            'stock_totals.movement_quantity_total' => -$qty,
            'report_totals.purchases.purchase_count' => -1,
            'report_totals.purchases.taxable_total' => -$taxable,
            'report_totals.purchases.gst_total' => -$gst,
            'report_totals.purchases.grand_total' => -$grand,
            'report_totals.payables.purchases_total' => -$grand,
            'report_totals.payables.outstanding_total' => -$grand,
            'ledger_totals.by_account.1300.net' => -$taxable,
            'ledger_totals.by_account.1215.net' => -$gst,
            'ledger_totals.by_account.2100.net' => $grand,
        ]);
    }

    private function runPurchasePaymentCrud(): void
    {
        $module = 'purchase_payments';
        if (!$this->h->tableExists('purchase_payments')) {
            $this->results[] = [
                'module' => $module,
                'step' => 'SKIP_MODULE',
                'status' => 'WARN',
                'message' => 'purchase_payments table not available in this schema.',
            ];
            return;
        }

        $purchaseId = 911205; // seeded finalized purchase with no baseline payment
        $paymentId = 995231;
        $reversalId = 995232;

        $purchase = $this->h->qr(
            'SELECT * FROM purchases WHERE id = :id AND company_id = :c LIMIT 1',
            ['id' => $purchaseId, 'c' => $this->h->companyId]
        );
        $this->h->assert($purchase !== [], 'Seeded purchase not found for purchase payment regression.');

        $partId = (int) ($this->h->qv(
            'SELECT part_id FROM purchase_items WHERE purchase_id = :pid ORDER BY id ASC LIMIT 1',
            ['pid' => $purchaseId]
        ) ?? 0);
        $this->h->assert($partId > 0, 'Purchase item part not found for purchase payment regression.');

        $vendorId = (int) ($purchase['vendor_id'] ?? 0);
        $grand = round((float) ($purchase['grand_total'] ?? 0), 2);
        $amount = round($grand * 0.35, 2);
        if ($amount >= $grand && $grand > 1.0) {
            $amount = round($grand - 1.0, 2);
        }
        if ($amount <= 0) {
            $amount = min(max($grand, 1.0), 50.0);
        }

        $baselineState = $this->purchaseDependencyState($purchaseId, $partId, $vendorId);
        $baselineStockQty = (float) ($baselineState['stock_quantity_part'] ?? 0.0);
        $baselineVendorOutstanding = (float) ($baselineState['vendor_outstanding_total'] ?? 0.0);

        $this->withValidationAndSnapshot($module, 'CREATE', function () use ($purchaseId, $paymentId, $purchase, $amount, $partId, $vendorId, $grand, $baselineStockQty, $baselineVendorOutstanding): array {
            $payment = [
                'id' => $paymentId,
                'purchase_id' => $purchaseId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'payment_date' => date('Y-m-d'),
                'entry_type' => 'PAYMENT',
                'amount' => $amount,
                'payment_mode' => 'BANK_TRANSFER',
                'reference_no' => 'REG-TEST-PPAY-0001',
                'notes' => $this->h->datasetTag . ' TEST purchase payment create',
                'reversed_payment_id' => null,
                'created_by' => $this->h->adminUserId,
            ];
            $this->h->insert('purchase_payments', $payment);
            ledger_post_vendor_payment($this->h->pdo, $purchase, $payment, $this->h->adminUserId);
            $this->h->recalcPurchasePaymentStatus($purchaseId);

            $state = $this->purchaseDependencyState($purchaseId, $partId, $vendorId);
            $this->h->assert((string) ($state['purchase_payment_status'] ?? '') === 'PARTIAL', 'Purchase payment status should be PARTIAL after payment create.');
            $this->h->assert(abs((float) ($state['purchase_paid_total'] ?? 0) - $amount) <= 0.01, 'Purchase paid total mismatch after payment create.');
            $this->h->assert(abs((float) ($state['purchase_outstanding_total'] ?? 0) - round($grand - $amount, 2)) <= 0.01, 'Purchase outstanding mismatch after payment create.');
            $this->h->assert(abs((float) ($state['stock_quantity_part'] ?? 0) - $baselineStockQty) <= 0.01, 'Stock should not change when posting purchase payment.');
            $expectedVendorOutstanding = round(max($baselineVendorOutstanding - $amount, 0.0), 2);
            $this->h->assert(abs((float) ($state['vendor_outstanding_total'] ?? 0) - $expectedVendorOutstanding) <= 0.01, 'Vendor outstanding should reduce by payment amount after purchase payment create.');

            return ['payment_id' => $paymentId, 'state' => $state];
        }, [
            'counts.purchase_payments' => 1,
            'stock_totals.garage_quantity_total' => 0,
            'stock_totals.movement_quantity_total' => 0,
            'report_totals.purchases.purchase_count' => 0,
            'report_totals.payables.paid_total' => $amount,
            'report_totals.payables.outstanding_total' => -$amount,
            'ledger_totals.by_account.2100.net' => $amount,
            'ledger_totals.by_account.1110.net' => -$amount,
        ]);

        $this->withValidationAndSnapshot($module, 'EDIT', function () use ($paymentId, $purchaseId, $partId, $vendorId, $amount, $baselineStockQty, $baselineVendorOutstanding): array {
            $this->h->updateById('purchase_payments', $paymentId, [
                'notes' => $this->h->datasetTag . ' TEST purchase payment edited',
                'reference_no' => 'REG-TEST-PPAY-EDIT',
            ]);
            $this->h->recalcPurchasePaymentStatus($purchaseId);

            $state = $this->purchaseDependencyState($purchaseId, $partId, $vendorId);
            $this->h->assert((string) ($state['purchase_payment_status'] ?? '') === 'PARTIAL', 'Purchase payment status should stay PARTIAL after edit.');
            $this->h->assert(abs((float) ($state['purchase_paid_total'] ?? 0) - $amount) <= 0.01, 'Purchase paid total should stay unchanged after payment edit.');
            $this->h->assert(abs((float) ($state['stock_quantity_part'] ?? 0) - $baselineStockQty) <= 0.01, 'Stock should not change when editing purchase payment metadata.');
            $expectedVendorOutstanding = round(max($baselineVendorOutstanding - $amount, 0.0), 2);
            $this->h->assert(abs((float) ($state['vendor_outstanding_total'] ?? 0) - $expectedVendorOutstanding) <= 0.01, 'Vendor outstanding should stay unchanged after purchase payment edit.');

            return ['payment_id' => $paymentId, 'state' => $state];
        });

        $this->withValidationAndSnapshot($module, 'DELETE_REVERSE', function () use ($paymentId, $reversalId, $purchaseId, $partId, $vendorId, $grand, $amount, $baselineStockQty, $baselineVendorOutstanding): array {
            $reversal = [
                'id' => $reversalId,
                'purchase_id' => $purchaseId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'payment_date' => date('Y-m-d'),
                'entry_type' => 'REVERSAL',
                'amount' => -$amount,
                'payment_mode' => 'ADJUSTMENT',
                'reference_no' => 'REG-TEST-PPAY-REV-' . $paymentId,
                'notes' => $this->h->datasetTag . ' TEST purchase payment reversal audit',
                'reversed_payment_id' => $paymentId,
                'created_by' => $this->h->adminUserId,
            ];
            $this->h->insert('purchase_payments', $reversal);
            $this->h->reverseLedgerReference(
                'PURCHASE_PAYMENT',
                $paymentId,
                'REG_TEST_PURCHASE_PAYMENT_REV',
                $reversalId,
                date('Y-m-d'),
                'Regression test purchase payment reversal'
            );
            $this->h->recalcPurchasePaymentStatus($purchaseId);

            $state = $this->purchaseDependencyState($purchaseId, $partId, $vendorId);
            $this->h->assert((string) ($state['purchase_payment_status'] ?? '') === 'UNPAID', 'Purchase payment status should return to UNPAID after reversal.');
            $this->h->assert(abs((float) ($state['purchase_paid_total'] ?? 0)) <= 0.01, 'Purchase paid total should return to zero after reversal.');
            $this->h->assert(abs((float) ($state['purchase_outstanding_total'] ?? 0) - $grand) <= 0.01, 'Purchase outstanding should return to purchase grand total after reversal.');
            $this->h->assert(abs((float) ($state['stock_quantity_part'] ?? 0) - $baselineStockQty) <= 0.01, 'Stock should not change when reversing purchase payment.');
            $this->h->assert(abs((float) ($state['vendor_outstanding_total'] ?? 0) - $baselineVendorOutstanding) <= 0.01, 'Vendor outstanding should return to baseline after purchase payment reversal.');

            return ['payment_id' => $paymentId, 'reversal_id' => $reversalId, 'state' => $state];
        }, [
            'counts.purchase_payments' => -1,
            'stock_totals.garage_quantity_total' => 0,
            'stock_totals.movement_quantity_total' => 0,
            'report_totals.purchases.purchase_count' => 0,
            'report_totals.payables.paid_total' => -$amount,
            'report_totals.payables.outstanding_total' => $amount,
            'ledger_totals.by_account.2100.net' => -$amount,
            'ledger_totals.by_account.1110.net' => $amount,
        ]);
    }

    private function runInvoiceCrud(): void
    {
        $module = 'invoices';
        $invoiceId = 995601;
        $invoiceItemId = 995611;
        $jobId = 910518; // seeded open job, no invoice
        $job = $this->h->qr('SELECT * FROM job_cards WHERE id = :id AND company_id = :c LIMIT 1', ['id' => $jobId, 'c' => $this->h->companyId]);
        $labor = $this->h->qr('SELECT * FROM job_labor WHERE job_card_id = :job ORDER BY id ASC LIMIT 1', ['job' => $jobId]);
        $taxable = round((float) ($labor['total_amount'] ?? 1200.00), 2);
        $tax = round($taxable * 0.18, 2);
        $cgst = round($tax / 2, 2);
        $sgst = round($tax - $cgst, 2);
        $grand = round($taxable + $tax, 2);

        $this->withValidationAndSnapshot($module, 'CREATE', function () use ($invoiceId, $invoiceItemId, $job, $labor, $taxable, $tax, $cgst, $sgst, $grand): array {
            $invoice = [
                'id' => $invoiceId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'invoice_number' => 'REG-TEST-INV-0001',
                'job_card_id' => (int) $job['id'],
                'customer_id' => (int) $job['customer_id'],
                'vehicle_id' => (int) $job['vehicle_id'],
                'invoice_date' => date('Y-m-d'),
                'due_date' => date('Y-m-d', strtotime('+7 days')),
                'invoice_status' => 'FINALIZED',
                'subtotal_service' => $taxable,
                'subtotal_parts' => 0.00,
                'taxable_amount' => $taxable,
                'tax_regime' => 'INTRASTATE',
                'cgst_rate' => 9.00,
                'sgst_rate' => 9.00,
                'igst_rate' => 0.00,
                'cgst_amount' => $cgst,
                'sgst_amount' => $sgst,
                'igst_amount' => 0.00,
                'service_tax_amount' => $tax,
                'parts_tax_amount' => 0.00,
                'total_tax_amount' => $tax,
                'gross_total' => $grand,
                'round_off' => 0.00,
                'grand_total' => $grand,
                'payment_status' => 'UNPAID',
                'notes' => $this->h->datasetTag . ' TEST invoice create',
                'created_by' => $this->h->adminUserId,
                'financial_year_id' => $this->h->financialYearId,
                'financial_year_label' => $this->h->fyLabel,
                'sequence_number' => 9001,
                'finalized_at' => date('Y-m-d H:i:s'),
                'finalized_by' => $this->h->adminUserId,
            ];
            $this->h->insert('invoices', $invoice);
            $this->h->insert('invoice_items', [
                'id' => $invoiceItemId,
                'invoice_id' => $invoiceId,
                'item_type' => 'LABOR',
                'description' => $this->h->datasetTag . ' TEST invoice labor',
                'part_id' => null,
                'service_id' => $labor['service_id'] ?? null,
                'hsn_sac_code' => '998714',
                'quantity' => 1.00,
                'unit_price' => $taxable,
                'gst_rate' => 18.00,
                'cgst_rate' => 9.00,
                'sgst_rate' => 9.00,
                'igst_rate' => 0.00,
                'taxable_value' => $taxable,
                'cgst_amount' => $cgst,
                'sgst_amount' => $sgst,
                'igst_amount' => 0.00,
                'tax_amount' => $tax,
                'total_value' => $grand,
            ]);
            ledger_post_invoice_finalized($this->h->pdo, $invoice, $this->h->adminUserId);
            return ['invoice_id' => $invoiceId, 'invoice_item_id' => $invoiceItemId];
        }, [
            'counts.invoices' => 1,
            'report_totals.sales.invoice_count' => 1,
            'report_totals.sales.taxable_total' => $taxable,
            'report_totals.sales.gst_total' => $tax,
            'report_totals.sales.grand_total' => $grand,
            'report_totals.receivables.invoiced_total' => $grand,
            'report_totals.receivables.outstanding_total' => $grand,
            'ledger_totals.by_account.1200.net' => $grand,
            'ledger_totals.by_account.4100.net' => -$taxable,
            'ledger_totals.by_account.2200.net' => -$cgst,
            'ledger_totals.by_account.2201.net' => -$sgst,
        ]);

        $this->withValidationAndSnapshot($module, 'EDIT', function () use ($invoiceId): array {
            $this->h->updateById('invoices', $invoiceId, ['notes' => $this->h->datasetTag . ' TEST invoice edited']);
            return ['invoice_id' => $invoiceId];
        });

        $this->withValidationAndSnapshot($module, 'DELETE_REVERSE', function () use ($invoiceId): array {
            $this->h->reverseLedgerReference('INVOICE_FINALIZE', $invoiceId, 'REG_TEST_INVOICE_REV', $invoiceId, date('Y-m-d'));
            $this->h->updateById('invoices', $invoiceId, ['invoice_status' => 'CANCELLED', 'payment_status' => 'CANCELLED']);
            $this->softDeleteById('invoices', $invoiceId);
            return ['invoice_id' => $invoiceId];
        }, [
            'counts.invoices' => -1,
            'report_totals.sales.invoice_count' => -1,
            'report_totals.sales.taxable_total' => -$taxable,
            'report_totals.sales.gst_total' => -$tax,
            'report_totals.sales.grand_total' => -$grand,
            'report_totals.receivables.invoiced_total' => -$grand,
            'report_totals.receivables.outstanding_total' => -$grand,
            'ledger_totals.by_account.1200.net' => -$grand,
            'ledger_totals.by_account.4100.net' => $taxable,
            'ledger_totals.by_account.2200.net' => $cgst,
            'ledger_totals.by_account.2201.net' => $sgst,
        ]);
    }

    private function runPaymentCrud(): void
    {
        $module = 'payments';
        $paymentId = 995701;
        $invoiceId = 910608;
        $invoice = $this->h->qr('SELECT * FROM invoices WHERE id = :id AND company_id = :c LIMIT 1', ['id' => $invoiceId, 'c' => $this->h->companyId]);
        $amount = 100.00;

        $this->withValidationAndSnapshot($module, 'CREATE', function () use ($paymentId, $invoiceId, $invoice, $amount): array {
            $payment = [
                'id' => $paymentId,
                'invoice_id' => $invoiceId,
                'entry_type' => 'PAYMENT',
                'amount' => $amount,
                'paid_on' => date('Y-m-d'),
                'payment_mode' => 'CASH',
                'reference_no' => 'REG-TEST-PAY-REF',
                'receipt_number' => 'REG-TEST-PAY-0001',
                'receipt_sequence_number' => 9901,
                'receipt_financial_year_label' => $this->h->fyLabel,
                'notes' => $this->h->datasetTag . ' TEST payment create',
                'outstanding_before' => null,
                'outstanding_after' => null,
                'is_reversed' => 0,
                'received_by' => $this->h->adminUserId,
            ];
            $this->h->insert('payments', $payment);
            ledger_post_customer_payment($this->h->pdo, $invoice, $payment, $this->h->adminUserId);
            $this->h->recalcInvoicePaymentStatus($invoiceId);
            return ['payment_id' => $paymentId];
        }, [
            'counts.payments' => 1,
            'report_totals.receivables.paid_total' => $amount,
            'report_totals.receivables.outstanding_total' => -$amount,
            'ledger_totals.by_account.1100.net' => $amount,
            'ledger_totals.by_account.1200.net' => -$amount,
        ]);

        $this->withValidationAndSnapshot($module, 'EDIT', function () use ($paymentId): array {
            $this->h->updateById('payments', $paymentId, ['notes' => $this->h->datasetTag . ' TEST payment edited']);
            return ['payment_id' => $paymentId];
        });

        $this->withValidationAndSnapshot($module, 'DELETE_REVERSE', function () use ($paymentId, $invoiceId, $amount): array {
            $this->h->reverseLedgerReference('INVOICE_PAYMENT', $paymentId, 'REG_TEST_PAYMENT_REV', $paymentId, date('Y-m-d'));
            $this->h->updateById('payments', $paymentId, [
                'deleted_at' => date('Y-m-d H:i:s'),
                'deleted_by' => $this->h->adminUserId,
                'deletion_reason' => $this->h->datasetTag . ' TEST payment reverse',
            ]);
            $this->h->recalcInvoicePaymentStatus($invoiceId);
            return ['payment_id' => $paymentId];
        }, [
            'counts.payments' => -1,
            'report_totals.receivables.paid_total' => -$amount,
            'report_totals.receivables.outstanding_total' => $amount,
            'ledger_totals.by_account.1100.net' => -$amount,
            'ledger_totals.by_account.1200.net' => $amount,
        ]);
    }

    private function runAdvanceCrud(): void
    {
        $module = 'advances';
        $advanceId = 996801;
        $adjustmentId = 996901;
        $invoiceId = 910613;
        $invoice = $this->h->qr('SELECT * FROM invoices WHERE id = :id AND company_id = :c LIMIT 1', ['id' => $invoiceId, 'c' => $this->h->companyId]);
        $jobId = (int) ($invoice['job_card_id'] ?? 910513);
        $amount = 100.00;

        $this->withValidationAndSnapshot($module, 'CREATE', function () use ($advanceId, $adjustmentId, $invoiceId, $jobId, $invoice, $amount): array {
            $advance = [
                'id' => $advanceId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'job_card_id' => $jobId,
                'customer_id' => (int) $invoice['customer_id'],
                'receipt_number' => 'REG-TEST-ADV-0001',
                'receipt_sequence_number' => 9901,
                'receipt_financial_year_label' => $this->h->fyLabel,
                'received_on' => date('Y-m-d'),
                'payment_mode' => 'CASH',
                'reference_no' => 'REG-TEST-ADV-REF',
                'notes' => $this->h->datasetTag . ' TEST advance create',
                'advance_amount' => $amount,
                'adjusted_amount' => 0.00,
                'balance_amount' => $amount,
                'status_code' => 'ACTIVE',
                'created_by' => $this->h->adminUserId,
                'updated_by' => $this->h->adminUserId,
            ];
            $this->h->insert('job_advances', $advance);
            ledger_post_advance_received($this->h->pdo, $advance, $this->h->adminUserId);

            $adj = [
                'id' => $adjustmentId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'advance_id' => $advanceId,
                'invoice_id' => $invoiceId,
                'job_card_id' => $jobId,
                'adjusted_amount' => $amount,
                'adjusted_on' => date('Y-m-d'),
                'notes' => $this->h->datasetTag . ' TEST advance adjustment create',
                'created_by' => $this->h->adminUserId,
            ];
            $this->h->insert('advance_adjustments', $adj);
            ledger_post_advance_adjustment($this->h->pdo, $adj, $invoice, $this->h->adminUserId);

            $this->h->refreshJobAdvanceBalances();
            $this->h->recalcInvoicePaymentStatus($invoiceId);
            return ['advance_id' => $advanceId, 'adjustment_id' => $adjustmentId];
        }, [
            'counts.advances' => 1,
            'report_totals.advances.advance_count' => 1,
            'report_totals.advances.received_total' => $amount,
            'report_totals.advances.adjusted_total' => $amount,
            'report_totals.receivables.advance_adjusted_total' => $amount,
            'report_totals.receivables.outstanding_total' => -$amount,
            'ledger_totals.by_account.1100.net' => $amount,
            'ledger_totals.by_account.1200.net' => -$amount,
        ]);

        $this->withValidationAndSnapshot($module, 'EDIT', function () use ($advanceId, $adjustmentId): array {
            $this->h->updateById('job_advances', $advanceId, ['notes' => $this->h->datasetTag . ' TEST advance edited']);
            $this->h->updateById('advance_adjustments', $adjustmentId, ['notes' => $this->h->datasetTag . ' TEST adjustment edited']);
            return ['advance_id' => $advanceId, 'adjustment_id' => $adjustmentId];
        });

        $this->withValidationAndSnapshot($module, 'DELETE_REVERSE', function () use ($advanceId, $adjustmentId, $invoiceId, $amount): array {
            $this->h->reverseLedgerReference('ADVANCE_ADJUSTMENT', $adjustmentId, 'REG_TEST_ADV_ADJ_REV', $adjustmentId, date('Y-m-d'));
            $this->h->exec('DELETE FROM advance_adjustments WHERE id = :id AND company_id = :c', ['id' => $adjustmentId, 'c' => $this->h->companyId]);
            $this->h->reverseLedgerReference('ADVANCE_RECEIVED', $advanceId, 'REG_TEST_ADV_REV', $advanceId, date('Y-m-d'));
            $this->softDeleteById('job_advances', $advanceId);
            $this->h->refreshJobAdvanceBalances();
            $this->h->recalcInvoicePaymentStatus($invoiceId);
            return ['advance_id' => $advanceId, 'adjustment_id' => $adjustmentId];
        }, [
            'counts.advances' => -1,
            'report_totals.advances.advance_count' => -1,
            'report_totals.advances.received_total' => -$amount,
            'report_totals.advances.adjusted_total' => -$amount,
            'report_totals.receivables.advance_adjusted_total' => -$amount,
            'report_totals.receivables.outstanding_total' => $amount,
            'ledger_totals.by_account.1100.net' => -$amount,
            'ledger_totals.by_account.1200.net' => $amount,
        ]);
    }

    private function runReturnCrud(): void
    {
        $module = 'returns';
        $returnId = 997001;
        $returnItemId = 997011;
        $invoiceId = 910605;
        $invoice = $this->h->qr('SELECT * FROM invoices WHERE id = :id AND company_id = :c LIMIT 1', ['id' => $invoiceId, 'c' => $this->h->companyId]);
        $partItem = $this->h->qr('SELECT * FROM invoice_items WHERE invoice_id = :invoice_id AND item_type = "PART" ORDER BY id ASC LIMIT 1', ['invoice_id' => $invoiceId]);
        $partId = (int) ($partItem['part_id'] ?? 910205);
        $qty = 1.0;
        $unitPrice = round((float) ($partItem['unit_price'] ?? 200), 2);
        $taxable = round($unitPrice * $qty, 2);
        $tax = round($taxable * 0.18, 2);
        $total = round($taxable + $tax, 2);
        $movementUid = 'REG-TEST-RET-C-' . $returnId . '-' . $partId;

        $this->withValidationAndSnapshot($module, 'CREATE', function () use ($returnId, $returnItemId, $invoice, $invoiceId, $partItem, $partId, $qty, $unitPrice, $taxable, $tax, $total, $movementUid): array {
            $rma = [
                'id' => $returnId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'return_number' => 'REG-TEST-RET-C-0001',
                'return_sequence_number' => 9901,
                'financial_year_label' => $this->h->fyLabel,
                'return_type' => 'CUSTOMER_RETURN',
                'return_date' => date('Y-m-d'),
                'job_card_id' => (int) $invoice['job_card_id'],
                'invoice_id' => $invoiceId,
                'customer_id' => (int) $invoice['customer_id'],
                'reason_text' => 'Regression test return',
                'reason_detail' => 'Deterministic CRUD test',
                'approval_status' => 'APPROVED',
                'approved_by' => $this->h->adminUserId,
                'approved_at' => date('Y-m-d H:i:s'),
                'taxable_amount' => $taxable,
                'tax_amount' => $tax,
                'total_amount' => $total,
                'notes' => $this->h->datasetTag . ' TEST return create',
                'status_code' => 'ACTIVE',
                'created_by' => $this->h->adminUserId,
                'updated_by' => $this->h->adminUserId,
            ];
            $this->h->insert('returns_rma', $rma);
            $this->h->insert('return_items', [
                'id' => $returnItemId,
                'return_id' => $returnId,
                'source_item_id' => (int) ($partItem['id'] ?? 0),
                'part_id' => $partId,
                'description' => (string) ($partItem['description'] ?? 'Regression test return part'),
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'gst_rate' => 18.00,
                'taxable_amount' => $taxable,
                'tax_amount' => $tax,
                'total_amount' => $total,
            ]);
            $this->h->recordInventoryMovement([
                'part_id' => $partId,
                'movement_type' => 'IN',
                'quantity' => $qty,
                'reference_type' => 'ADJUSTMENT',
                'reference_id' => $returnId,
                'movement_uid' => $movementUid,
                'notes' => $this->h->datasetTag . ' TEST return stock',
            ]);
            ledger_post_customer_return_approved($this->h->pdo, $rma, $this->h->adminUserId);
            return ['return_id' => $returnId, 'return_item_id' => $returnItemId, 'movement_uid' => $movementUid];
        }, [
            'counts.returns' => 1,
            'stock_totals.garage_quantity_total' => $qty,
            'stock_totals.movement_quantity_total' => $qty,
            'report_totals.returns.return_count' => 1,
            'report_totals.returns.customer_return_total' => $total,
            'report_totals.returns.taxable_total' => $taxable,
            'report_totals.returns.tax_total' => $tax,
            'report_totals.returns.grand_total' => $total,
            'ledger_totals.by_account.1200.net' => -$total,
            'ledger_totals.by_account.2205.net' => $tax,
            'ledger_totals.by_account.5130.net' => $taxable,
        ]);

        $this->withValidationAndSnapshot($module, 'EDIT', function () use ($returnId): array {
            $this->h->updateById('returns_rma', $returnId, ['notes' => $this->h->datasetTag . ' TEST return edited']);
            return ['return_id' => $returnId];
        });

        $this->withValidationAndSnapshot($module, 'DELETE_REVERSE', function () use ($returnId, $movementUid): array {
            $this->h->softDeleteInventoryMovementByUid($movementUid, $this->h->datasetTag . ' TEST return reverse');
            $this->h->reverseLedgerReference('CUSTOMER_RETURN_APPROVAL', $returnId, 'REG_TEST_RETURN_REV', $returnId, date('Y-m-d'));
            $this->softDeleteById('returns_rma', $returnId);
            return ['return_id' => $returnId];
        }, [
            'counts.returns' => -1,
            'stock_totals.garage_quantity_total' => -$qty,
            'stock_totals.movement_quantity_total' => -$qty,
            'report_totals.returns.return_count' => -1,
            'report_totals.returns.customer_return_total' => -$total,
            'report_totals.returns.taxable_total' => -$taxable,
            'report_totals.returns.tax_total' => -$tax,
            'report_totals.returns.grand_total' => -$total,
            'ledger_totals.by_account.1200.net' => $total,
            'ledger_totals.by_account.2205.net' => -$tax,
            'ledger_totals.by_account.5130.net' => -$taxable,
        ]);
    }

    private function runPayrollCrud(): void
    {
        $module = 'payroll';
        $sheetId = 998301;
        $itemId = 998311;
        $gross = 18000.00;
        $deductions = 1200.00;
        $payable = 16800.00;

        $this->withValidationAndSnapshot($module, 'CREATE', function () use ($sheetId, $itemId, $gross, $deductions, $payable): array {
            $this->h->insert('payroll_salary_sheets', [
                'id' => $sheetId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'salary_month' => '2026-02',
                'status' => 'LOCKED',
                'total_gross' => $gross,
                'total_deductions' => $deductions,
                'total_payable' => $payable,
                'total_paid' => 0.00,
                'locked_at' => date('Y-m-d H:i:s'),
                'locked_by' => $this->h->adminUserId,
                'created_by' => $this->h->adminUserId,
            ]);
            $this->h->insert('payroll_salary_items', [
                'id' => $itemId,
                'sheet_id' => $sheetId,
                'user_id' => $this->h->staffUserIds[0] ?? $this->h->adminUserId,
                'salary_type' => 'MONTHLY',
                'base_amount' => 17500.00,
                'commission_base' => 17500.00,
                'commission_rate' => 0.000,
                'commission_amount' => 500.00,
                'overtime_hours' => 0.00,
                'overtime_rate' => null,
                'overtime_amount' => 0.00,
                'advance_deduction' => 500.00,
                'loan_deduction' => 500.00,
                'manual_deduction' => 200.00,
                'gross_amount' => $gross,
                'net_payable' => $payable,
                'paid_amount' => 0.00,
                'deductions_applied' => 1,
                'status' => 'LOCKED',
                'notes' => $this->h->datasetTag . ' TEST payroll create',
            ]);
            $this->h->postPayrollAccrualJournal($sheetId, $payable, date('Y-m-d'), 'Regression CRUD TEST payroll accrual');
            return ['sheet_id' => $sheetId, 'item_id' => $itemId];
        }, [
            'counts.payroll_entries' => 1,
            'report_totals.payroll_sheets.sheet_count' => 1,
            'report_totals.payroll_sheets.total_gross' => $gross,
            'report_totals.payroll_sheets.total_deductions' => $deductions,
            'report_totals.payroll_sheets.total_payable' => $payable,
            'report_totals.payroll_items.item_count' => 1,
            'report_totals.payroll_items.total_gross' => $gross,
            'report_totals.payroll_items.total_payable' => $payable,
            'ledger_totals.by_account.5110.net' => $payable,
            'ledger_totals.by_account.2400.net' => -$payable,
        ]);

        $this->withValidationAndSnapshot($module, 'EDIT', function () use ($itemId): array {
            $this->h->updateById('payroll_salary_items', $itemId, ['notes' => $this->h->datasetTag . ' TEST payroll edited']);
            return ['item_id' => $itemId];
        });

        $this->withValidationAndSnapshot($module, 'DELETE_REVERSE', function () use ($sheetId, $itemId): array {
            $this->h->reverseLedgerReference('PAYROLL_ACCRUAL', $sheetId, 'REG_TEST_PAYROLL_REV', $sheetId, date('Y-m-d'));
            $this->h->exec('DELETE FROM payroll_salary_items WHERE id = :id', ['id' => $itemId]);
            $this->h->exec('DELETE FROM payroll_salary_sheets WHERE id = :id', ['id' => $sheetId]);
            return ['sheet_id' => $sheetId, 'item_id' => $itemId];
        }, [
            'counts.payroll_entries' => -1,
            'report_totals.payroll_sheets.sheet_count' => -1,
            'report_totals.payroll_sheets.total_gross' => -$gross,
            'report_totals.payroll_sheets.total_deductions' => -$deductions,
            'report_totals.payroll_sheets.total_payable' => -$payable,
            'report_totals.payroll_items.item_count' => -1,
            'report_totals.payroll_items.total_gross' => -$gross,
            'report_totals.payroll_items.total_payable' => -$payable,
            'ledger_totals.by_account.5110.net' => -$payable,
            'ledger_totals.by_account.2400.net' => $payable,
        ]);
    }

    private function runExpenseCrud(): void
    {
        $module = 'expenses';
        $expenseId = 999401;
        $amount = 345.00;
        $category = $this->h->qr('SELECT * FROM expense_categories WHERE id = :id AND company_id = :c LIMIT 1', ['id' => 911411, 'c' => $this->h->companyId]);
        $categoryName = (string) ($category['category_name'] ?? 'Regression Utilities');

        $this->withValidationAndSnapshot($module, 'CREATE', function () use ($expenseId, $amount, $category, $categoryName): array {
            $expense = [
                'id' => $expenseId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'category_id' => (int) ($category['id'] ?? 911411),
                'expense_date' => date('Y-m-d'),
                'amount' => $amount,
                'paid_to' => 'Regression Test Expense Vendor',
                'payment_mode' => 'BANK_TRANSFER',
                'notes' => $this->h->datasetTag . ' TEST expense create',
                'source_type' => 'MANUAL',
                'source_id' => null,
                'entry_type' => 'EXPENSE',
                'reversed_expense_id' => null,
                'created_by' => $this->h->adminUserId,
                'updated_by' => $this->h->adminUserId,
            ];
            $this->h->insert('expenses', $expense);
            ledger_post_finance_expense_entry($this->h->pdo, $expense, $categoryName, $this->h->adminUserId);
            return ['expense_id' => $expenseId];
        }, [
            'counts.expenses' => 1,
            'report_totals.expenses.row_count' => 1,
            'report_totals.expenses.expense_total' => $amount,
            'report_totals.expenses.net_total' => $amount,
            'ledger_totals.by_account.5100.net' => $amount,
            'ledger_totals.by_account.1110.net' => -$amount,
        ]);

        $this->withValidationAndSnapshot($module, 'EDIT', function () use ($expenseId): array {
            $this->h->updateById('expenses', $expenseId, ['notes' => $this->h->datasetTag . ' TEST expense edited']);
            return ['expense_id' => $expenseId];
        });

        $this->withValidationAndSnapshot($module, 'DELETE_REVERSE', function () use ($expenseId): array {
            $this->h->reverseLedgerReference('EXPENSE', $expenseId, 'REG_TEST_EXPENSE_REV', $expenseId, date('Y-m-d'));
            $this->h->updateById('expenses', $expenseId, [
                'deleted_at' => date('Y-m-d H:i:s'),
                'deleted_by' => $this->h->adminUserId,
                'deletion_reason' => $this->h->datasetTag . ' TEST expense reverse',
            ]);
            return ['expense_id' => $expenseId];
        }, [
            'counts.expenses' => -1,
            'report_totals.expenses.row_count' => -1,
            'report_totals.expenses.expense_total' => -$amount,
            'report_totals.expenses.net_total' => -$amount,
            'ledger_totals.by_account.5100.net' => -$amount,
            'ledger_totals.by_account.1110.net' => $amount,
        ]);
    }

}

$runner = new RegressionCrudRunner(new RegressionHarness(), $argv ?? []);
try {
    exit($runner->run());
} catch (Throwable $e) {
    fwrite(STDERR, 'Regression test runner failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
