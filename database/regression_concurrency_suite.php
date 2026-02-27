<?php
declare(strict_types=1);

require_once __DIR__ . '/regression_common.php';

/**
 * Phase 5 concurrency suite with true parallel worker processes.
 */
final class RegressionConcurrencySuite
{
    private RegressionHarness $h;

    public function __construct(RegressionHarness $h)
    {
        $this->h = $h;
    }

    /**
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $checks = [];
        $workerRuns = [];
        $cleanupIssues = [];
        $stamp = date('YmdHis') . '-' . (string) mt_rand(1000, 9999);

        $artifacts = [
            'stock_uids' => [],
            'payment_ids' => [],
            'payment_reference_prefix' => '',
            'job_id' => 0,
            'job_before' => [],
            'job_part_id' => 0,
            'job_uid_base' => '',
            'job_movement_uids' => [],
        ];
        $invoicesToRecalc = [];

        try {
            $partIdForJob = $this->runStockAdjustmentScenario($stamp, $checks, $workerRuns, $artifacts);
            $this->runInvoicePaymentScenario($stamp, $checks, $workerRuns, $artifacts, $invoicesToRecalc);
            $this->runJobClosureScenario($stamp, $partIdForJob, $checks, $workerRuns, $artifacts);
        } finally {
            $this->cleanupConcurrencyArtifacts($artifacts, $invoicesToRecalc, $cleanupIssues);
        }

        if ($cleanupIssues !== []) {
            $checks[] = [
                'name' => 'concurrency_cleanup',
                'status' => 'FAIL',
                'issues' => $cleanupIssues,
            ];
        } else {
            $checks[] = ['name' => 'concurrency_cleanup', 'status' => 'PASS'];
        }

        return [
            'status' => $this->deriveStatusFromChecks($checks),
            'checks' => $checks,
            'worker_batches' => $workerRuns,
            'artifacts' => $artifacts,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     */
    private function deriveStatusFromChecks(array $checks): string
    {
        $status = 'PASS';
        foreach ($checks as $check) {
            $s = strtoupper((string) ($check['status'] ?? 'FAIL'));
            if ($s === 'FAIL') {
                return 'FAIL';
            }
            if ($s === 'WARN') {
                $status = 'WARN';
            }
        }
        return $status;
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @param array<string,mixed> $workerRuns
     * @param array<string,mixed> $artifacts
     */
    private function runStockAdjustmentScenario(string $stamp, array &$checks, array &$workerRuns, array &$artifacts): int
    {
        $partRow = $this->h->qr(
            'SELECT gi.part_id, ROUND(gi.quantity,2) AS quantity
             FROM garage_inventory gi
             INNER JOIN parts p ON p.id = gi.part_id AND p.company_id = :company_id
             WHERE gi.garage_id = :garage_id
             ORDER BY gi.quantity DESC, gi.part_id ASC
             LIMIT 1',
            ['company_id' => $this->h->companyId, 'garage_id' => $this->h->garageId]
        );

        if ($partRow === []) {
            $checks[] = ['name' => 'stock_adjustments_fixture', 'status' => 'WARN', 'message' => 'No stocked part available for contention test'];
            return 0;
        }

        $partId = (int) ($partRow['part_id'] ?? 0);
        $beforeQty = round((float) ($partRow['quantity'] ?? 0), 2);
        $uidA = 'REG-CONC-STK-' . $stamp . '-A';
        $uidB = 'REG-CONC-STK-' . $stamp . '-B';
        $artifacts['stock_uids'] = [$uidA, $uidB];

        $stockBatch = $this->runParallelWorkerBatch([
            [
                'op' => 'stock_adjust',
                'worker_tag' => 'A',
                'params' => [
                    'part_id' => $partId,
                    'delta_qty' => 1.50,
                    'reference_id' => 999001,
                    'movement_uid' => $uidA,
                    'sleep_ms' => 700,
                    'notes' => $this->h->datasetTag . ' CONC stock adjust A',
                ],
            ],
            [
                'op' => 'stock_adjust',
                'worker_tag' => 'B',
                'params' => [
                    'part_id' => $partId,
                    'delta_qty' => -0.50,
                    'reference_id' => 999002,
                    'movement_uid' => $uidB,
                    'sleep_ms' => 100,
                    'notes' => $this->h->datasetTag . ' CONC stock adjust B',
                ],
            ],
        ], 35);
        $workerRuns['stock_adjustments'] = $stockBatch;

        $stockWorkers = (array) ($stockBatch['workers'] ?? []);
        $stockFails = count(array_filter($stockWorkers, static fn (array $w): bool => strtoupper((string) ($w['status'] ?? 'FAIL')) === 'FAIL'));
        $stockDeadlocks = count(array_filter($stockWorkers, static fn (array $w): bool => (bool) ($w['lock_error'] ?? false)));

        $checks[] = [
            'name' => 'stock_adjustments_parallel_workers',
            'status' => $stockFails === 0 ? 'PASS' : 'FAIL',
            'worker_failures' => $stockFails,
            'worker_count' => count($stockWorkers),
        ];
        $checks[] = [
            'name' => 'stock_adjustments_no_deadlock',
            'status' => $stockDeadlocks === 0 ? 'PASS' : 'FAIL',
            'deadlock_errors' => $stockDeadlocks,
        ];

        if ($stockFails === 0) {
            $rows = $this->h->qa(
                'SELECT movement_uid
                 FROM inventory_movements
                 WHERE company_id = :company_id
                   AND movement_uid IN (:uid_a, :uid_b)
                   AND deleted_at IS NULL',
                ['company_id' => $this->h->companyId, 'uid_a' => $uidA, 'uid_b' => $uidB]
            );
            $checks[] = [
                'name' => 'stock_adjustments_rows_created',
                'status' => count($rows) === 2 ? 'PASS' : 'FAIL',
                'expected' => 2,
                'actual' => count($rows),
            ];

            $afterQty = round((float) ($this->h->qv(
                'SELECT quantity FROM garage_inventory WHERE garage_id = :garage_id AND part_id = :part_id',
                ['garage_id' => $this->h->garageId, 'part_id' => $partId]
            ) ?? 0), 2);
            $actualDelta = round($afterQty - $beforeQty, 2);
            $expectedDelta = 1.00;

            $checks[] = [
                'name' => 'stock_adjustments_delta_consistent',
                'status' => abs($actualDelta - $expectedDelta) <= 0.01 ? 'PASS' : 'FAIL',
                'expected_delta' => $expectedDelta,
                'actual_delta' => $actualDelta,
            ];
        }

        return $partId;
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @param array<string,mixed> $workerRuns
     * @param array<string,mixed> $artifacts
     * @param array<int,bool> $invoicesToRecalc
     */
    private function runInvoicePaymentScenario(
        string $stamp,
        array &$checks,
        array &$workerRuns,
        array &$artifacts,
        array &$invoicesToRecalc
    ): void {
        $invoice = $this->h->qr(
            'SELECT *
             FROM invoices
             WHERE company_id = :company_id
               AND invoice_status = "FINALIZED"
               AND deleted_at IS NULL
             ORDER BY id ASC
             LIMIT 1',
            ['company_id' => $this->h->companyId]
        );

        if ($invoice === []) {
            $checks[] = ['name' => 'invoice_payments_fixture', 'status' => 'WARN', 'message' => 'No finalized invoice available'];
            return;
        }

        $invoiceId = (int) ($invoice['id'] ?? 0);
        $grand = round((float) ($invoice['grand_total'] ?? 0), 2);
        $payAmount = round(max(1.0, min(75.0, $grand * 0.05)), 2);
        $seqBase = (int) (($this->h->qv('SELECT COALESCE(MAX(receipt_sequence_number),0) FROM payments') ?? 0)) + 1000;
        $payRefPrefix = 'REG-CONC-PAY-' . $stamp;
        $artifacts['payment_reference_prefix'] = $payRefPrefix;

        $paymentBatch = $this->runParallelWorkerBatch([
            [
                'op' => 'invoice_payment',
                'worker_tag' => 'A',
                'params' => [
                    'invoice_id' => $invoiceId,
                    'amount' => $payAmount,
                    'paid_on' => date('Y-m-d'),
                    'payment_mode' => 'UPI',
                    'reference_no' => $payRefPrefix . '-A',
                    'receipt_number' => $payRefPrefix . '-RCP-A',
                    'receipt_sequence_number' => $seqBase + 1,
                    'sleep_ms' => 700,
                    'notes' => $this->h->datasetTag . ' CONC invoice payment A',
                ],
            ],
            [
                'op' => 'invoice_payment',
                'worker_tag' => 'B',
                'params' => [
                    'invoice_id' => $invoiceId,
                    'amount' => $payAmount,
                    'paid_on' => date('Y-m-d'),
                    'payment_mode' => 'CASH',
                    'reference_no' => $payRefPrefix . '-B',
                    'receipt_number' => $payRefPrefix . '-RCP-B',
                    'receipt_sequence_number' => $seqBase + 2,
                    'sleep_ms' => 100,
                    'notes' => $this->h->datasetTag . ' CONC invoice payment B',
                ],
            ],
        ], 35);
        $workerRuns['invoice_payments'] = $paymentBatch;

        $paymentWorkers = (array) ($paymentBatch['workers'] ?? []);
        $paymentFails = count(array_filter($paymentWorkers, static fn (array $w): bool => strtoupper((string) ($w['status'] ?? 'FAIL')) === 'FAIL'));
        $paymentDeadlocks = count(array_filter($paymentWorkers, static fn (array $w): bool => (bool) ($w['lock_error'] ?? false)));

        $checks[] = [
            'name' => 'invoice_payments_parallel_workers',
            'status' => $paymentFails === 0 ? 'PASS' : 'FAIL',
            'worker_failures' => $paymentFails,
            'worker_count' => count($paymentWorkers),
        ];
        $checks[] = [
            'name' => 'invoice_payments_no_deadlock',
            'status' => $paymentDeadlocks === 0 ? 'PASS' : 'FAIL',
            'deadlock_errors' => $paymentDeadlocks,
        ];

        $payRows = $this->h->qa(
            'SELECT id, invoice_id
             FROM payments
             WHERE reference_no LIKE :reference_no
               AND deleted_at IS NULL
             ORDER BY id ASC',
            ['reference_no' => $payRefPrefix . '%']
        );
        $paymentIds = array_values(array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $payRows));
        $artifacts['payment_ids'] = $paymentIds;

        foreach ($payRows as $r) {
            $invId = (int) ($r['invoice_id'] ?? 0);
            if ($invId > 0) {
                $invoicesToRecalc[$invId] = true;
            }
        }

        $checks[] = [
            'name' => 'invoice_payments_rows_created',
            'status' => count($paymentIds) === 2 ? 'PASS' : 'FAIL',
            'expected' => 2,
            'actual' => count($paymentIds),
        ];

        $dupJournalRows = [];
        foreach ($paymentIds as $pid) {
            $jCount = (int) ($this->h->qv(
                'SELECT COUNT(*)
                 FROM ledger_journals
                 WHERE company_id = :company_id
                   AND reference_type = "INVOICE_PAYMENT"
                   AND reference_id = :reference_id',
                ['company_id' => $this->h->companyId, 'reference_id' => $pid]
            ) ?? 0);
            if ($jCount !== 1) {
                $dupJournalRows[] = ['payment_id' => $pid, 'journal_count' => $jCount];
            }
        }
        $checks[] = [
            'name' => 'invoice_payments_no_duplicate_ledger_journal',
            'status' => (count($paymentIds) === 2 && $dupJournalRows === []) ? 'PASS' : 'FAIL',
            'issues' => $dupJournalRows,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @param array<string,mixed> $workerRuns
     * @param array<string,mixed> $artifacts
     */
    private function runJobClosureScenario(
        string $stamp,
        int $partIdForJob,
        array &$checks,
        array &$workerRuns,
        array &$artifacts
    ): void {
        $job = $this->h->qr(
            'SELECT id, status, completed_at, closed_at, stock_posted_at, updated_by
             FROM job_cards
             WHERE company_id = :company_id
               AND COALESCE(status_code,"ACTIVE") <> "DELETED"
               AND stock_posted_at IS NULL
             ORDER BY id ASC
             LIMIT 1',
            ['company_id' => $this->h->companyId]
        );

        if ($job === []) {
            $checks[] = ['name' => 'job_closure_fixture', 'status' => 'WARN', 'message' => 'No open job available for parallel closure'];
            return;
        }

        $jobId = (int) ($job['id'] ?? 0);
        $artifacts['job_id'] = $jobId;
        $artifacts['job_before'] = $job;

        if ($partIdForJob <= 0) {
            $fallbackPart = $this->h->qr(
                'SELECT id FROM parts WHERE company_id = :company_id ORDER BY id ASC LIMIT 1',
                ['company_id' => $this->h->companyId]
            );
            $partIdForJob = (int) ($fallbackPart['id'] ?? 0);
        }

        if ($partIdForJob <= 0) {
            $checks[] = ['name' => 'job_closure_fixture_parts', 'status' => 'WARN', 'message' => 'No part available for job closure stock posting'];
            return;
        }

        $unitPrice = round((float) ($this->h->qv(
            'SELECT selling_price FROM parts WHERE id = :id AND company_id = :company_id',
            ['id' => $partIdForJob, 'company_id' => $this->h->companyId]
        ) ?? 100.0), 2);

        $jobPartId = (int) (($this->h->qv('SELECT COALESCE(MAX(id),0) + 1 FROM job_parts') ?? 999900));
        $this->h->insert('job_parts', [
            'id' => $jobPartId,
            'job_card_id' => $jobId,
            'part_id' => $partIdForJob,
            'quantity' => 1.00,
            'unit_price' => $unitPrice,
            'gst_rate' => 18.00,
            'total_amount' => $unitPrice,
        ]);
        $artifacts['job_part_id'] = $jobPartId;

        $this->h->updateById('job_cards', $jobId, [
            'status' => 'OPEN',
            'completed_at' => null,
            'closed_at' => null,
            'stock_posted_at' => null,
            'updated_by' => $this->h->adminUserId,
        ]);

        $jobUidBase = 'REG-CONC-JOB-' . $stamp;
        $artifacts['job_uid_base'] = $jobUidBase;

        $jobBatch = $this->runParallelWorkerBatch([
            [
                'op' => 'job_close',
                'worker_tag' => 'A',
                'params' => [
                    'job_id' => $jobId,
                    'movement_uid_base' => $jobUidBase,
                    'sleep_ms' => 700,
                ],
            ],
            [
                'op' => 'job_close',
                'worker_tag' => 'B',
                'params' => [
                    'job_id' => $jobId,
                    'movement_uid_base' => $jobUidBase,
                    'sleep_ms' => 100,
                ],
            ],
        ], 35);
        $workerRuns['job_closures'] = $jobBatch;

        $jobWorkers = (array) ($jobBatch['workers'] ?? []);
        $jobFails = count(array_filter($jobWorkers, static fn (array $w): bool => strtoupper((string) ($w['status'] ?? 'FAIL')) === 'FAIL'));
        $jobDeadlocks = count(array_filter($jobWorkers, static fn (array $w): bool => (bool) ($w['lock_error'] ?? false)));

        $checks[] = [
            'name' => 'job_closures_parallel_workers',
            'status' => $jobFails === 0 ? 'PASS' : 'FAIL',
            'worker_failures' => $jobFails,
            'worker_count' => count($jobWorkers),
        ];
        $checks[] = [
            'name' => 'job_closures_no_deadlock',
            'status' => $jobDeadlocks === 0 ? 'PASS' : 'FAIL',
            'deadlock_errors' => $jobDeadlocks,
        ];

        $jobMovementRows = $this->h->qa(
            'SELECT movement_uid
             FROM inventory_movements
             WHERE company_id = :company_id
               AND reference_type = "JOB_CARD"
               AND reference_id = :reference_id
               AND movement_uid LIKE :uid_prefix
               AND deleted_at IS NULL
             ORDER BY id ASC',
            ['company_id' => $this->h->companyId, 'reference_id' => $jobId, 'uid_prefix' => $jobUidBase . '%']
        );
        $jobMovementUids = array_values(array_filter(array_map(static fn (array $r): string => (string) ($r['movement_uid'] ?? ''), $jobMovementRows)));
        $artifacts['job_movement_uids'] = $jobMovementUids;

        $checks[] = [
            'name' => 'job_closures_no_double_stock_posting',
            'status' => count($jobMovementUids) === 1 ? 'PASS' : 'FAIL',
            'expected_movement_rows' => 1,
            'actual_movement_rows' => count($jobMovementUids),
        ];

        $jobAfter = $this->h->qr('SELECT status, stock_posted_at FROM job_cards WHERE id = :id LIMIT 1', ['id' => $jobId]);
        $jobClosed = strtoupper((string) ($jobAfter['status'] ?? '')) === 'CLOSED';
        $stockPosted = !empty($jobAfter['stock_posted_at']);
        $checks[] = [
            'name' => 'job_closures_marked_closed',
            'status' => ($jobClosed && $stockPosted) ? 'PASS' : 'FAIL',
            'job_status' => (string) ($jobAfter['status'] ?? ''),
            'stock_posted_at' => $jobAfter['stock_posted_at'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $artifacts
     * @param array<int,bool> $invoicesToRecalc
     * @param array<int,string> $cleanupIssues
     */
    private function cleanupConcurrencyArtifacts(array $artifacts, array $invoicesToRecalc, array &$cleanupIssues): void
    {
        foreach ((array) ($artifacts['stock_uids'] ?? []) as $uid) {
            if ((string) $uid === '') {
                continue;
            }
            try {
                $this->h->softDeleteInventoryMovementByUid((string) $uid, $this->h->datasetTag . ' TEST concurrency stock cleanup');
            } catch (Throwable $e) {
                $cleanupIssues[] = 'stock_uid:' . (string) $uid . ' => ' . $e->getMessage();
            }
        }

        try {
            $paymentRowsForCleanup = [];
            $prefix = (string) ($artifacts['payment_reference_prefix'] ?? '');
            if ($prefix !== '') {
                $paymentRowsForCleanup = $this->h->qa(
                    'SELECT id, invoice_id
                     FROM payments
                     WHERE reference_no LIKE :reference_no
                       AND deleted_at IS NULL
                     ORDER BY id ASC',
                    ['reference_no' => $prefix . '%']
                );
            }
            if ($paymentRowsForCleanup === []) {
                $paymentIdsFallback = array_values(array_filter(array_map('intval', (array) ($artifacts['payment_ids'] ?? []))));
                foreach ($paymentIdsFallback as $pid) {
                    $row = $this->h->qr('SELECT id, invoice_id FROM payments WHERE id = :id LIMIT 1', ['id' => $pid]);
                    if ($row !== []) {
                        $paymentRowsForCleanup[] = $row;
                    }
                }
            }

            foreach ($paymentRowsForCleanup as $row) {
                $pid = (int) ($row['id'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                $invId = (int) ($row['invoice_id'] ?? 0);
                if ($invId > 0) {
                    $invoicesToRecalc[$invId] = true;
                }

                try {
                    $this->h->reverseLedgerReference(
                        'INVOICE_PAYMENT',
                        $pid,
                        'REG_TEST_CONC_PAY_REV',
                        $pid,
                        date('Y-m-d'),
                        $this->h->datasetTag . ' TEST concurrency payment cleanup'
                    );
                } catch (Throwable $e) {
                    $cleanupIssues[] = 'payment_ledger_reverse:' . $pid . ' => ' . $e->getMessage();
                }

                try {
                    $this->h->updateById('payments', $pid, [
                        'deleted_at' => date('Y-m-d H:i:s'),
                        'deleted_by' => $this->h->adminUserId,
                        'deletion_reason' => $this->h->datasetTag . ' TEST concurrency payment cleanup',
                    ]);
                } catch (Throwable $e) {
                    $cleanupIssues[] = 'payment_delete:' . $pid . ' => ' . $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            $cleanupIssues[] = 'payment_cleanup_query => ' . $e->getMessage();
        }

        foreach (array_keys($invoicesToRecalc) as $invoiceId) {
            try {
                $this->h->recalcInvoicePaymentStatus((int) $invoiceId);
            } catch (Throwable $e) {
                $cleanupIssues[] = 'invoice_recalc:' . (int) $invoiceId . ' => ' . $e->getMessage();
            }
        }

        try {
            $jobUidBase = (string) ($artifacts['job_uid_base'] ?? '');
            if ($jobUidBase !== '') {
                $rows = $this->h->qa(
                    'SELECT movement_uid
                     FROM inventory_movements
                     WHERE company_id = :company_id
                       AND movement_uid LIKE :uid_prefix
                       AND deleted_at IS NULL',
                    ['company_id' => $this->h->companyId, 'uid_prefix' => $jobUidBase . '%']
                );
                foreach ($rows as $row) {
                    $uid = (string) ($row['movement_uid'] ?? '');
                    if ($uid === '') {
                        continue;
                    }
                    try {
                        $this->h->softDeleteInventoryMovementByUid($uid, $this->h->datasetTag . ' TEST concurrency job cleanup');
                    } catch (Throwable $e) {
                        $cleanupIssues[] = 'job_movement_uid:' . $uid . ' => ' . $e->getMessage();
                    }
                }
            }
        } catch (Throwable $e) {
            $cleanupIssues[] = 'job_movement_cleanup_query => ' . $e->getMessage();
        }

        $jobPartId = (int) ($artifacts['job_part_id'] ?? 0);
        if ($jobPartId > 0) {
            try {
                $this->h->exec('DELETE FROM job_parts WHERE id = :id', ['id' => $jobPartId]);
            } catch (Throwable $e) {
                $cleanupIssues[] = 'job_part_delete:' . $jobPartId . ' => ' . $e->getMessage();
            }
        }

        $jobId = (int) ($artifacts['job_id'] ?? 0);
        $jobBefore = (array) ($artifacts['job_before'] ?? []);
        if ($jobId > 0 && $jobBefore !== []) {
            try {
                $this->h->updateById('job_cards', $jobId, [
                    'status' => (string) ($jobBefore['status'] ?? 'OPEN'),
                    'completed_at' => $jobBefore['completed_at'] ?? null,
                    'closed_at' => $jobBefore['closed_at'] ?? null,
                    'stock_posted_at' => $jobBefore['stock_posted_at'] ?? null,
                    'updated_by' => isset($jobBefore['updated_by']) ? (int) $jobBefore['updated_by'] : $this->h->adminUserId,
                ]);
            } catch (Throwable $e) {
                $cleanupIssues[] = 'job_restore:' . $jobId . ' => ' . $e->getMessage();
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $tasks
     * @return array<string,mixed>
     */
    private function runParallelWorkerBatch(array $tasks, int $timeoutSeconds = 25): array
    {
        $workerScript = __DIR__ . '/regression_parallel_worker.php';
        if (!is_file($workerScript)) {
            return [
                'status' => 'FAIL',
                'error' => 'Worker script not found: ' . $workerScript,
                'workers' => [],
            ];
        }

        $outputDir = __DIR__ . '/regression_outputs/concurrency';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $running = [];
        foreach ($tasks as $index => $task) {
            $payloadPath = $outputDir . '/worker_payload_' . date('Ymd_His') . '_' . $index . '_' . mt_rand(1000, 9999) . '.json';
            $payload = [
                'op' => (string) ($task['op'] ?? ''),
                'worker_tag' => (string) ($task['worker_tag'] ?? ('W' . ($index + 1))),
                'params' => (array) ($task['params'] ?? []),
                'scope' => [
                    'company_id' => $this->h->companyId,
                    'garage_id' => $this->h->garageId,
                    'admin_user_id' => $this->h->adminUserId,
                    'financial_year_id' => $this->h->financialYearId,
                    'fy_label' => $this->h->fyLabel,
                    'dataset_tag' => $this->h->datasetTag,
                    'base_date' => $this->h->baseDate,
                    'staff_user_ids' => $this->h->staffUserIds,
                ],
            ];
            $this->h->writeJson($payloadPath, $payload);

            $cmd = escapeshellarg((string) PHP_BINARY)
                . ' '
                . escapeshellarg($workerScript)
                . ' --payload='
                . escapeshellarg($payloadPath);

            $descriptor = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $pipes = [];
            $proc = proc_open($cmd, $descriptor, $pipes, __DIR__);
            if (!is_resource($proc)) {
                @unlink($payloadPath);
                $running[] = [
                    'task' => $task,
                    'payload_path' => $payloadPath,
                    'proc' => null,
                    'pipes' => [],
                    'started_at' => microtime(true),
                    'launch_error' => 'proc_open failed',
                ];
                continue;
            }

            fclose($pipes[0]);
            stream_set_blocking($pipes[1], true);
            stream_set_blocking($pipes[2], true);

            $running[] = [
                'task' => $task,
                'payload_path' => $payloadPath,
                'proc' => $proc,
                'pipes' => $pipes,
                'started_at' => microtime(true),
            ];
        }

        $workers = [];
        foreach ($running as $slot) {
            $task = (array) ($slot['task'] ?? []);
            $payloadPath = (string) ($slot['payload_path'] ?? '');

            if (!isset($slot['proc']) || !is_resource($slot['proc'])) {
                $workers[] = [
                    'status' => 'FAIL',
                    'task' => $task,
                    'exit_code' => 1,
                    'timed_out' => false,
                    'lock_error' => false,
                    'error' => (string) ($slot['launch_error'] ?? 'Worker launch failed'),
                    'stdout' => '',
                    'stderr' => '',
                    'result' => [],
                    'elapsed_ms' => round((microtime(true) - (float) ($slot['started_at'] ?? microtime(true))) * 1000, 2),
                ];
                if ($payloadPath !== '') {
                    @unlink($payloadPath);
                }
                continue;
            }

            $proc = $slot['proc'];
            $pipes = (array) ($slot['pipes'] ?? []);
            $startedAt = (float) ($slot['started_at'] ?? microtime(true));
            $deadline = $startedAt + max(1, $timeoutSeconds);
            $timedOut = false;
            $lastStatus = null;

            while (true) {
                $lastStatus = proc_get_status($proc);
                if (!is_array($lastStatus) || !($lastStatus['running'] ?? false)) {
                    break;
                }
                if (microtime(true) > $deadline) {
                    $timedOut = true;
                    proc_terminate($proc);
                    break;
                }
                usleep(100000);
            }

            $stdout = isset($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '';
            $stderr = isset($pipes[2]) ? (string) stream_get_contents($pipes[2]) : '';
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                fclose($pipes[2]);
            }

            $closeCode = proc_close($proc);
            $exitCode = is_array($lastStatus) ? (int) ($lastStatus['exitcode'] ?? $closeCode) : $closeCode;
            if ($exitCode < 0 && $closeCode >= 0) {
                $exitCode = $closeCode;
            }

            if ($payloadPath !== '') {
                @unlink($payloadPath);
            }

            $decoded = $this->decodeWorkerOutput($stdout);
            $workerStatus = strtoupper((string) ($decoded['status'] ?? ($exitCode === 0 ? 'PASS' : 'FAIL')));
            if (!in_array($workerStatus, ['PASS', 'WARN', 'FAIL'], true)) {
                $workerStatus = ($exitCode === 0) ? 'PASS' : 'FAIL';
            }
            if ($timedOut) {
                $workerStatus = 'FAIL';
            }

            $workers[] = [
                'status' => $workerStatus,
                'task' => $task,
                'exit_code' => $exitCode,
                'timed_out' => $timedOut,
                'lock_error' => (bool) ($decoded['lock_error'] ?? false),
                'error' => (string) ($decoded['error'] ?? ''),
                'stdout' => trim($stdout),
                'stderr' => trim($stderr),
                'result' => (array) ($decoded['result'] ?? []),
                'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ];
        }

        return [
            'status' => $this->deriveStatusFromChecks(array_map(
                static fn (array $worker): array => ['status' => (string) ($worker['status'] ?? 'FAIL')],
                $workers
            )),
            'worker_count' => count($workers),
            'timeout_seconds' => $timeoutSeconds,
            'workers' => $workers,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeWorkerOutput(string $stdout): array
    {
        $trimmed = trim($stdout);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $lines = preg_split('/\R+/', $trimmed) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim((string) $lines[$i]);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
