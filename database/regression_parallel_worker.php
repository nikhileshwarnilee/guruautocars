<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/regression_common.php';

/**
 * Parse a CLI argument in --key=value form.
 */
function worker_arg(array $argv, string $key): ?string
{
    $prefix = $key . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, $prefix)) {
            return substr((string) $arg, strlen($prefix));
        }
    }
    return null;
}

/**
 * True when the throwable looks like a lock wait/deadlock concurrency error.
 */
function worker_is_lock_error(Throwable $e): bool
{
    $msg = strtolower($e->getMessage());
    if (str_contains($msg, 'deadlock') || str_contains($msg, 'lock wait timeout')) {
        return true;
    }

    if ($e instanceof PDOException) {
        $sqlState = strtoupper((string) $e->getCode());
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if (($sqlState === '40001' || $sqlState === 'HY000') && in_array($driverCode, [1205, 1213], true)) {
            return true;
        }
    }

    return false;
}

/**
 * Emit JSON and exit.
 */
function worker_exit(array $payload, int $code): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

/**
 * Stock adjustment operation with explicit row lock contention on garage_inventory.
 *
 * @return array<string,mixed>
 */
function worker_op_stock_adjust(RegressionHarness $h, array $params): array
{
    $partId = (int) ($params['part_id'] ?? 0);
    $deltaQty = round((float) ($params['delta_qty'] ?? 0), 2);
    $movementUid = trim((string) ($params['movement_uid'] ?? ''));
    $referenceId = (int) ($params['reference_id'] ?? 0);
    $sleepMs = max(0, (int) ($params['sleep_ms'] ?? 0));
    $notes = (string) ($params['notes'] ?? ($h->datasetTag . ' CONC stock adjust'));

    if ($partId <= 0 || abs($deltaQty) <= 0.009 || $movementUid === '') {
        throw new RuntimeException('Invalid stock_adjust payload.');
    }

    $pdo = $h->pdo;
    $pdo->beginTransaction();
    try {
        $pdo->exec('SET innodb_lock_wait_timeout = 8');

        $h->exec(
            'INSERT INTO garage_inventory (garage_id, part_id, quantity)
             VALUES (:garage_id, :part_id, 0)
             ON DUPLICATE KEY UPDATE quantity = quantity',
            ['garage_id' => $h->garageId, 'part_id' => $partId]
        );

        $row = $h->qr(
            'SELECT quantity
             FROM garage_inventory
             WHERE garage_id = :garage_id AND part_id = :part_id
             FOR UPDATE',
            ['garage_id' => $h->garageId, 'part_id' => $partId]
        );
        $beforeQty = round((float) ($row['quantity'] ?? 0), 2);
        $afterQty = round($beforeQty + $deltaQty, 2);

        $h->insert('inventory_movements', [
            'company_id' => $h->companyId,
            'garage_id' => $h->garageId,
            'part_id' => $partId,
            'movement_type' => 'ADJUST',
            'quantity' => $deltaQty,
            'reference_type' => 'ADJUSTMENT',
            'reference_id' => $referenceId > 0 ? $referenceId : null,
            'movement_uid' => $movementUid,
            'notes' => $notes,
            'created_by' => $h->adminUserId,
        ]);
        $movementId = (int) $pdo->lastInsertId();

        $h->exec(
            'UPDATE garage_inventory
             SET quantity = :quantity
             WHERE garage_id = :garage_id AND part_id = :part_id',
            ['quantity' => $afterQty, 'garage_id' => $h->garageId, 'part_id' => $partId]
        );

        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }

        $pdo->commit();

        return [
            'movement_id' => $movementId,
            'movement_uid' => $movementUid,
            'part_id' => $partId,
            'before_qty' => $beforeQty,
            'after_qty' => $afterQty,
            'delta_qty' => $deltaQty,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Invoice payment operation with ledger posting in the same transaction.
 *
 * @return array<string,mixed>
 */
function worker_op_invoice_payment(RegressionHarness $h, array $params): array
{
    $invoiceId = (int) ($params['invoice_id'] ?? 0);
    $amount = round((float) ($params['amount'] ?? 0), 2);
    $paidOn = (string) ($params['paid_on'] ?? date('Y-m-d'));
    $paymentMode = (string) ($params['payment_mode'] ?? 'UPI');
    $referenceNo = trim((string) ($params['reference_no'] ?? ''));
    $receiptNumber = trim((string) ($params['receipt_number'] ?? ''));
    $receiptSeq = isset($params['receipt_sequence_number']) ? (int) $params['receipt_sequence_number'] : null;
    $sleepMs = max(0, (int) ($params['sleep_ms'] ?? 0));
    $notes = (string) ($params['notes'] ?? ($h->datasetTag . ' CONC invoice payment'));

    if ($invoiceId <= 0 || $amount <= 0.0) {
        throw new RuntimeException('Invalid invoice_payment payload.');
    }

    $pdo = $h->pdo;

    // Warm bootstrap + COA cache before entering the worker transaction.
    // ledger_create_journal calls ledger_bootstrap_ready(); if that executes DDL inside an
    // active transaction it can trigger an implicit commit and break worker tx state.
    if (!ledger_bootstrap_ready()) {
        throw new RuntimeException('Ledger bootstrap is not ready for invoice_payment op.');
    }
    ledger_ensure_default_coa($pdo, $h->companyId);

    $pdo->beginTransaction();
    try {
        $pdo->exec('SET innodb_lock_wait_timeout = 8');

        $invoice = $h->qr(
            'SELECT *
             FROM invoices
             WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL
             FOR UPDATE',
            ['id' => $invoiceId, 'company_id' => $h->companyId]
        );
        if ($invoice === []) {
            throw new RuntimeException('Invoice not found for invoice_payment op.');
        }

        $existingPaid = (float) ($h->qv(
            'SELECT COALESCE(SUM(CASE WHEN entry_type="REVERSAL" THEN -ABS(amount) ELSE ABS(amount) END),0)
             FROM payments
             WHERE invoice_id = :invoice_id
               AND deleted_at IS NULL',
            ['invoice_id' => $invoiceId]
        ) ?? 0.0);
        $existingAdvance = $h->tableExists('advance_adjustments')
            ? (float) ($h->qv(
                'SELECT COALESCE(SUM(adjusted_amount),0)
                 FROM advance_adjustments
                 WHERE company_id = :company_id AND invoice_id = :invoice_id',
                ['company_id' => $h->companyId, 'invoice_id' => $invoiceId]
            ) ?? 0.0)
            : 0.0;

        $grand = round((float) ($invoice['grand_total'] ?? 0), 2);
        $outstandingBefore = round(max($grand - $existingPaid - $existingAdvance, 0.0), 2);
        $outstandingAfter = round(max($outstandingBefore - $amount, 0.0), 2);

        $h->insert('payments', [
            'invoice_id' => $invoiceId,
            'entry_type' => 'PAYMENT',
            'amount' => $amount,
            'paid_on' => $paidOn,
            'payment_mode' => $paymentMode,
            'reference_no' => $referenceNo !== '' ? $referenceNo : null,
            'receipt_number' => $receiptNumber !== '' ? $receiptNumber : null,
            'receipt_sequence_number' => $receiptSeq,
            'receipt_financial_year_label' => $h->fyLabel,
            'notes' => $notes,
            'outstanding_before' => $outstandingBefore,
            'outstanding_after' => $outstandingAfter,
            'is_reversed' => 0,
            'received_by' => $h->adminUserId,
        ]);
        $paymentId = (int) $pdo->lastInsertId();
        $payment = $h->qr('SELECT * FROM payments WHERE id = :id LIMIT 1', ['id' => $paymentId]);

        $journalId = ledger_post_customer_payment($pdo, $invoice, $payment, $h->adminUserId);

        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }

        $pdo->commit();
        $h->recalcInvoicePaymentStatus($invoiceId);

        return [
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
            'journal_id' => $journalId !== null ? (int) $journalId : null,
            'amount' => $amount,
            'outstanding_before' => $outstandingBefore,
            'outstanding_after' => $outstandingAfter,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Job closure operation with row-lock on job_card and stock posting.
 *
 * @return array<string,mixed>
 */
function worker_op_job_close(RegressionHarness $h, array $params): array
{
    $jobId = (int) ($params['job_id'] ?? 0);
    $movementUidBase = trim((string) ($params['movement_uid_base'] ?? ('REG-CONC-JOB-' . $jobId)));
    $workerTag = trim((string) ($params['worker_tag'] ?? 'W'));
    $sleepMs = max(0, (int) ($params['sleep_ms'] ?? 0));

    if ($jobId <= 0) {
        throw new RuntimeException('Invalid job_close payload.');
    }

    $pdo = $h->pdo;
    $pdo->beginTransaction();
    try {
        $pdo->exec('SET innodb_lock_wait_timeout = 8');

        $job = $h->qr(
            'SELECT *
             FROM job_cards
             WHERE id = :id AND company_id = :company_id
             FOR UPDATE',
            ['id' => $jobId, 'company_id' => $h->companyId]
        );
        if ($job === []) {
            throw new RuntimeException('Job card not found for job_close op.');
        }

        $alreadyPosted = !empty($job['stock_posted_at']);
        $movementUids = [];
        $postedLines = 0;
        $parts = [];

        if (!$alreadyPosted) {
            $parts = $h->qa(
                'SELECT id, part_id, quantity
                 FROM job_parts
                 WHERE job_card_id = :job_card_id
                 ORDER BY id ASC',
                ['job_card_id' => $jobId]
            );

            foreach ($parts as $line) {
                $lineId = (int) ($line['id'] ?? 0);
                $partId = (int) ($line['part_id'] ?? 0);
                $qty = round(abs((float) ($line['quantity'] ?? 0)), 2);
                if ($partId <= 0 || $qty <= 0.009) {
                    continue;
                }

                $h->exec(
                    'INSERT INTO garage_inventory (garage_id, part_id, quantity)
                     VALUES (:garage_id, :part_id, 0)
                     ON DUPLICATE KEY UPDATE quantity = quantity',
                    ['garage_id' => $h->garageId, 'part_id' => $partId]
                );

                $inv = $h->qr(
                    'SELECT quantity
                     FROM garage_inventory
                     WHERE garage_id = :garage_id AND part_id = :part_id
                     FOR UPDATE',
                    ['garage_id' => $h->garageId, 'part_id' => $partId]
                );
                $beforeQty = round((float) ($inv['quantity'] ?? 0), 2);
                $afterQty = round($beforeQty - $qty, 2);

                $uid = substr($movementUidBase . '-' . $workerTag . '-' . $lineId, 0, 64);
                $h->insert('inventory_movements', [
                    'company_id' => $h->companyId,
                    'garage_id' => $h->garageId,
                    'part_id' => $partId,
                    'movement_type' => 'OUT',
                    'quantity' => $qty,
                    'reference_type' => 'JOB_CARD',
                    'reference_id' => $jobId,
                    'movement_uid' => $uid,
                    'notes' => $h->datasetTag . ' CONC job close',
                    'created_by' => $h->adminUserId,
                ]);

                $h->exec(
                    'UPDATE garage_inventory
                     SET quantity = :quantity
                     WHERE garage_id = :garage_id AND part_id = :part_id',
                    ['quantity' => $afterQty, 'garage_id' => $h->garageId, 'part_id' => $partId]
                );

                $movementUids[] = $uid;
                $postedLines++;
            }

            $now = date('Y-m-d H:i:s');
            $h->updateById('job_cards', $jobId, [
                'status' => 'CLOSED',
                'completed_at' => $job['completed_at'] ?? $now,
                'closed_at' => $job['closed_at'] ?? $now,
                'stock_posted_at' => $now,
                'updated_by' => $h->adminUserId,
            ]);
        }

        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }

        $pdo->commit();

        return [
            'job_id' => $jobId,
            'already_posted' => $alreadyPosted,
            'part_line_count' => count($parts),
            'posted_line_count' => $postedLines,
            'movement_uids' => $movementUids,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

$payloadPath = worker_arg($argv ?? [], '--payload');
if ($payloadPath === null || !is_file($payloadPath)) {
    worker_exit([
        'status' => 'FAIL',
        'error' => 'Missing worker payload file.',
        'lock_error' => false,
    ], 1);
}

$decoded = json_decode((string) file_get_contents($payloadPath), true);
if (!is_array($decoded)) {
    worker_exit([
        'status' => 'FAIL',
        'error' => 'Invalid worker payload JSON.',
        'lock_error' => false,
    ], 1);
}

$scope = is_array($decoded['scope'] ?? null) ? (array) $decoded['scope'] : [];
$op = (string) ($decoded['op'] ?? '');
$params = is_array($decoded['params'] ?? null) ? (array) $decoded['params'] : [];
$workerTag = (string) ($decoded['worker_tag'] ?? '');
if ($workerTag !== '' && !isset($params['worker_tag'])) {
    $params['worker_tag'] = $workerTag;
}

$h = new RegressionHarness([
    'company_id' => (int) ($scope['company_id'] ?? 910001),
    'garage_id' => (int) ($scope['garage_id'] ?? 910001),
    'admin_user_id' => (int) ($scope['admin_user_id'] ?? 910001),
    'financial_year_id' => (int) ($scope['financial_year_id'] ?? 910001),
    'fy_label' => (string) ($scope['fy_label'] ?? '2025-26'),
    'dataset_tag' => (string) ($scope['dataset_tag'] ?? 'REG-DET-20260226'),
    'base_date' => (string) ($scope['base_date'] ?? '2026-01-01'),
    'staff_user_ids' => array_map('intval', (array) ($scope['staff_user_ids'] ?? [910011, 910012, 910013, 910014, 910015])),
]);

$startedAt = microtime(true);
try {
    $result = match ($op) {
        'stock_adjust' => worker_op_stock_adjust($h, $params),
        'invoice_payment' => worker_op_invoice_payment($h, $params),
        'job_close' => worker_op_job_close($h, $params),
        default => throw new RuntimeException('Unsupported worker op: ' . $op),
    };

    worker_exit([
        'status' => 'PASS',
        'op' => $op,
        'worker_tag' => $workerTag,
        'lock_error' => false,
        'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        'result' => $result,
    ], 0);
} catch (Throwable $e) {
    worker_exit([
        'status' => 'FAIL',
        'op' => $op,
        'worker_tag' => $workerTag,
        'lock_error' => worker_is_lock_error($e),
        'error' => $e->getMessage(),
        'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 2),
    ], 1);
}
