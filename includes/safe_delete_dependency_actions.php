<?php
declare(strict_types=1);

function safe_delete_dependency_action_supported(string $entity, string $operation): bool
{
    $entity = safe_delete_normalize_entity($entity);
    $operation = strtolower(trim($operation));

    return match ($entity . ':' . $operation) {
        'billing_advance:delete',
        'invoice:cancel',
        'invoice_payment:reverse',
        'job_card:delete',
        'job_labor_line:delete',
        'job_part_line:delete',
        'purchase:delete',
        'purchase_payment:reverse',
        'return_settlement:reverse',
        'return_attachment:delete',
        'vehicle:delete',
        'inventory_part:delete' => true,
        default => false,
    };
}

function safe_delete_dependency_execute_action(
    PDO $pdo,
    string $entity,
    int $recordId,
    string $operation,
    array $scope,
    int $actorUserId,
    string $reason
): array {
    $entity = safe_delete_normalize_entity($entity);
    $operation = strtolower(trim($operation));
    $recordId = max(0, $recordId);
    $actorUserId = max(0, $actorUserId);
    $reason = trim($reason);

    if ($recordId <= 0) {
        throw new RuntimeException('Invalid dependency record selected.');
    }

    return match ($entity . ':' . $operation) {
        'billing_advance:delete' => safe_delete_dependency_delete_billing_advance($pdo, $recordId, $scope, $actorUserId, $reason),
        'invoice:cancel' => safe_delete_dependency_cancel_invoice($pdo, $recordId, $scope, $actorUserId, $reason),
        'invoice_payment:reverse' => safe_delete_dependency_reverse_invoice_payment($pdo, $recordId, $scope, $actorUserId, $reason),
        'job_card:delete' => safe_delete_dependency_delete_job_card($pdo, $recordId, $scope, $actorUserId, $reason),
        'job_labor_line:delete' => safe_delete_dependency_delete_job_labor_line($pdo, $recordId, $scope, $actorUserId, $reason),
        'job_part_line:delete' => safe_delete_dependency_delete_job_part_line($pdo, $recordId, $scope, $actorUserId, $reason),
        'purchase:delete' => safe_delete_dependency_delete_purchase($pdo, $recordId, $scope, $actorUserId, $reason),
        'purchase_payment:reverse' => safe_delete_dependency_reverse_purchase_payment($pdo, $recordId, $scope, $actorUserId, $reason),
        'return_settlement:reverse' => safe_delete_dependency_reverse_return_settlement($pdo, $recordId, $scope, $actorUserId, $reason),
        'return_attachment:delete' => safe_delete_dependency_delete_return_attachment($pdo, $recordId, $scope, $actorUserId, $reason),
        'vehicle:delete' => safe_delete_dependency_delete_vehicle($pdo, $recordId, $scope, $actorUserId, $reason),
        'inventory_part:delete' => safe_delete_dependency_delete_inventory_part($pdo, $recordId, $scope, $actorUserId, $reason),
        default => throw new RuntimeException('Dependency action is not supported for this record.'),
    };
}

function safe_delete_dependency_require_billing_modules(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    require_once APP_ROOT . '/modules/billing/workflow.php';
    require_once APP_ROOT . '/modules/billing/financial_extensions.php';
    $loaded = true;
}

function safe_delete_dependency_require_returns_modules(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    require_once APP_ROOT . '/modules/returns/workflow.php';
    $loaded = true;
}

function safe_delete_dependency_require_jobs_modules(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    require_once APP_ROOT . '/modules/jobs/workflow.php';
    $loaded = true;
}

function safe_delete_dependency_lock_inventory_row(PDO $pdo, int $garageId, int $partId): float
{
    $seedStmt = $pdo->prepare(
        'INSERT INTO garage_inventory (garage_id, part_id, quantity)
         VALUES (:garage_id, :part_id, 0)
         ON DUPLICATE KEY UPDATE quantity = quantity'
    );
    $seedStmt->execute([
        'garage_id' => $garageId,
        'part_id' => $partId,
    ]);

    $lockStmt = $pdo->prepare(
        'SELECT quantity
         FROM garage_inventory
         WHERE garage_id = :garage_id
           AND part_id = :part_id
         FOR UPDATE'
    );
    $lockStmt->execute([
        'garage_id' => $garageId,
        'part_id' => $partId,
    ]);

    return (float) $lockStmt->fetchColumn();
}

function safe_delete_dependency_insert_inventory_movement(PDO $pdo, array $payload): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO inventory_movements
          (company_id, garage_id, part_id, movement_type, quantity, reference_type, reference_id, movement_uid, notes, created_by)
         VALUES
          (:company_id, :garage_id, :part_id, :movement_type, :quantity, :reference_type, :reference_id, :movement_uid, :notes, :created_by)'
    );
    $stmt->execute([
        'company_id' => (int) ($payload['company_id'] ?? 0),
        'garage_id' => (int) ($payload['garage_id'] ?? 0),
        'part_id' => (int) ($payload['part_id'] ?? 0),
        'movement_type' => (string) ($payload['movement_type'] ?? ''),
        'quantity' => round((float) ($payload['quantity'] ?? 0), 2),
        'reference_type' => (string) ($payload['reference_type'] ?? ''),
        'reference_id' => isset($payload['reference_id']) ? (int) $payload['reference_id'] : null,
        'movement_uid' => (string) ($payload['movement_uid'] ?? ''),
        'notes' => ($payload['notes'] ?? null) !== null ? (string) $payload['notes'] : null,
        'created_by' => (int) ($payload['created_by'] ?? 0),
    ]);
}

function safe_delete_dependency_purchase_payment_status_from_amounts(float $grandTotal, float $paidAmount): string
{
    if ($grandTotal <= 0) {
        return 'PAID';
    }
    if ($paidAmount <= 0.009) {
        return 'UNPAID';
    }
    if ($paidAmount + 0.009 >= $grandTotal) {
        return 'PAID';
    }
    return 'PARTIAL';
}

function safe_delete_dependency_fetch_purchase_for_update(PDO $pdo, int $purchaseId, int $companyId, int $garageId): ?array
{
    $sql =
        'SELECT p.*,
                COALESCE(pay.total_paid, 0) AS total_paid
         FROM purchases p
         LEFT JOIN (
           SELECT purchase_id, SUM(amount) AS total_paid
           FROM purchase_payments
           GROUP BY purchase_id
         ) pay ON pay.purchase_id = p.id
         WHERE p.id = :id
           AND p.company_id = :company_id
           AND p.garage_id = :garage_id';
    if (safe_delete_table_has_column('purchases', 'status_code')) {
        $sql .= ' AND p.status_code <> "DELETED"';
    }
    $sql .= ' LIMIT 1 FOR UPDATE';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'id' => $purchaseId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function safe_delete_dependency_delete_billing_advance(PDO $pdo, int $advanceId, array $scope, int $actorUserId, string $reason): array
{
    safe_delete_dependency_require_billing_modules();

    $companyId = (int) ($scope['company_id'] ?? 0);
    $garageId = (int) ($scope['garage_id'] ?? 0);
    if ($companyId <= 0 || $garageId <= 0) {
        throw new RuntimeException('Invalid active scope for advance delete.');
    }
    if (!function_exists('billing_financial_extensions_ready') || !billing_financial_extensions_ready()) {
        throw new RuntimeException('Advance management is not ready in this environment.');
    }
    if (!function_exists('billing_round')) {
        throw new RuntimeException('Billing helpers are not available for advance deletion.');
    }

    $pdo->beginTransaction();
    try {
        $advanceStmt = $pdo->prepare(
            'SELECT ja.id, ja.job_card_id, ja.receipt_number, ja.advance_amount, ja.adjusted_amount, ja.balance_amount,
                    ja.notes, ja.status_code, ja.received_on, jc.job_number
             FROM job_advances ja
             LEFT JOIN job_cards jc ON jc.id = ja.job_card_id
             WHERE ja.id = :advance_id
               AND ja.company_id = :company_id
               AND ja.garage_id = :garage_id
             LIMIT 1
             FOR UPDATE'
        );
        $advanceStmt->execute([
            'advance_id' => $advanceId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $advance = $advanceStmt->fetch();
        if (!$advance) {
            throw new RuntimeException('Advance receipt not found for this scope.');
        }

        $statusCode = strtoupper(trim((string) ($advance['status_code'] ?? 'ACTIVE')));
        if ($statusCode !== 'ACTIVE') {
            throw new RuntimeException('Advance receipt is already reversed/deleted.');
        }

        $adjustedAmount = billing_round((float) ($advance['adjusted_amount'] ?? 0));
        if ($adjustedAmount > 0.009) {
            throw new RuntimeException('Adjusted advance cannot be deleted. Reverse invoice-side advance adjustment first.');
        }

        $adjustmentCountStmt = $pdo->prepare('SELECT COUNT(*) FROM advance_adjustments WHERE advance_id = :advance_id');
        $adjustmentCountStmt->execute(['advance_id' => $advanceId]);
        $adjustmentCount = (int) ($adjustmentCountStmt->fetchColumn() ?? 0);
        if ($adjustmentCount > 0) {
            throw new RuntimeException('Advance has adjustment history and cannot be deleted.');
        }

        $existingNotes = trim((string) ($advance['notes'] ?? ''));
        $reasonNote = 'Deleted: ' . $reason;
        $updatedNotes = $existingNotes === '' ? $reasonNote : ($existingNotes . ' | ' . $reasonNote);
        if (function_exists('mb_strlen') && mb_strlen($updatedNotes) > 255) {
            $updatedNotes = mb_substr($updatedNotes, 0, 255);
        } elseif (strlen($updatedNotes) > 255) {
            $updatedNotes = substr($updatedNotes, 0, 255);
        }

        $jobAdvanceColumns = table_columns('job_advances');
        $setParts = [
            'status_code = "DELETED"',
            'notes = :notes',
        ];
        $params = [
            'notes' => $updatedNotes,
            'id' => $advanceId,
        ];
        if (in_array('updated_by', $jobAdvanceColumns, true)) {
            $setParts[] = 'updated_by = :updated_by';
            $params['updated_by'] = $actorUserId > 0 ? $actorUserId : null;
        }
        if (in_array('deleted_at', $jobAdvanceColumns, true)) {
            $setParts[] = 'deleted_at = COALESCE(deleted_at, NOW())';
        }
        if (in_array('deleted_by', $jobAdvanceColumns, true)) {
            $setParts[] = 'deleted_by = :deleted_by';
            $params['deleted_by'] = $actorUserId > 0 ? $actorUserId : null;
        }
        if (in_array('deletion_reason', $jobAdvanceColumns, true)) {
            $setParts[] = 'deletion_reason = :deletion_reason';
            $params['deletion_reason'] = $reason;
        }

        $deleteStmt = $pdo->prepare(
            'UPDATE job_advances
             SET ' . implode(', ', $setParts) . '
             WHERE id = :id'
        );
        $deleteStmt->execute($params);
        if ($deleteStmt->rowCount() < 1) {
            throw new RuntimeException('Advance delete was not applied.');
        }

        $pdo->commit();

        log_audit('billing', 'advance_delete', $advanceId, 'Deleted advance receipt ' . (string) ($advance['receipt_number'] ?? ''), [
            'entity' => 'job_advance',
            'source' => 'SAFE_DELETE_MODAL',
            'before' => [
                'status_code' => 'ACTIVE',
                'advance_amount' => (float) ($advance['advance_amount'] ?? 0),
                'adjusted_amount' => (float) ($advance['adjusted_amount'] ?? 0),
                'balance_amount' => (float) ($advance['balance_amount'] ?? 0),
            ],
            'after' => [
                'status_code' => 'DELETED',
                'delete_reason' => $reason,
            ],
            'metadata' => [
                'job_card_id' => (int) ($advance['job_card_id'] ?? 0),
                'job_number' => (string) ($advance['job_number'] ?? ''),
                'receipt_number' => (string) ($advance['receipt_number'] ?? ''),
                'received_on' => (string) ($advance['received_on'] ?? ''),
            ],
        ]);

        return [
            'ok' => true,
            'message' => 'Advance receipt deleted successfully.',
            'reversal_references' => [],
            'metadata' => [
                'advance_id' => $advanceId,
                'receipt_number' => (string) ($advance['receipt_number'] ?? ''),
                'job_card_id' => (int) ($advance['job_card_id'] ?? 0),
                'job_number' => (string) ($advance['job_number'] ?? ''),
            ],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function safe_delete_dependency_delete_job_labor_line(PDO $pdo, int $laborId, array $scope, int $actorUserId, string $reason): array
{
    safe_delete_dependency_require_jobs_modules();

    $companyId = (int) ($scope['company_id'] ?? 0);
    $garageId = (int) ($scope['garage_id'] ?? 0);
    if ($companyId <= 0 || $garageId <= 0) {
        throw new RuntimeException('Invalid active scope for job labor delete.');
    }

    $pdo->beginTransaction();
    try {
        $lineStmt = $pdo->prepare(
            'SELECT jl.id, jl.job_card_id, jl.description, jl.execution_type, jl.outsource_cost, jl.outsource_payable_status,
                    jc.status, jc.status_code, jc.job_number
             FROM job_labor jl
             INNER JOIN job_cards jc ON jc.id = jl.job_card_id
             WHERE jl.id = :line_id
               AND jc.company_id = :company_id
               AND jc.garage_id = :garage_id
             LIMIT 1
             FOR UPDATE'
        );
        $lineStmt->execute([
            'line_id' => $laborId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $line = $lineStmt->fetch();
        if (!$line) {
            throw new RuntimeException('Labor line not found for this scope.');
        }

        $jobId = (int) ($line['job_card_id'] ?? 0);
        $jobStatus = strtoupper(trim((string) ($line['status'] ?? 'OPEN')));
        $jobStatusCode = strtoupper(trim((string) ($line['status_code'] ?? 'ACTIVE')));
        if ($jobStatus === 'CLOSED' || $jobStatusCode !== 'ACTIVE') {
            throw new RuntimeException('Reopen the job card and keep it ACTIVE before deleting labor lines.');
        }

        if (safe_delete_table_exists('outsourced_works') && safe_delete_table_exists('outsourced_work_payments')) {
            $linkedWorkStmt = $pdo->prepare(
                'SELECT ow.id,
                        COALESCE(pay.total_paid, 0) AS paid_amount
                 FROM outsourced_works ow
                 LEFT JOIN (
                    SELECT outsourced_work_id, SUM(amount) AS total_paid
                    FROM outsourced_work_payments
                    GROUP BY outsourced_work_id
                 ) pay ON pay.outsourced_work_id = ow.id
                 WHERE ow.company_id = :company_id
                   AND ow.garage_id = :garage_id
                   AND ow.job_labor_id = :job_labor_id
                   AND ow.status_code = "ACTIVE"
                 LIMIT 1
                 FOR UPDATE'
            );
            $linkedWorkStmt->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'job_labor_id' => $laborId,
            ]);
            $linkedWork = $linkedWorkStmt->fetch() ?: null;
            if ($linkedWork) {
                $paidAmount = round((float) ($linkedWork['paid_amount'] ?? 0), 2);
                if ($paidAmount > 0.009) {
                    throw new RuntimeException('Cannot delete outsourced labor with paid records. Reverse outsourced payments first.');
                }
                $archiveStmt = $pdo->prepare(
                    'UPDATE outsourced_works
                     SET status_code = "DELETED",
                         deleted_at = NOW(),
                         updated_by = :updated_by
                     WHERE id = :id
                       AND company_id = :company_id
                       AND garage_id = :garage_id'
                );
                $archiveStmt->execute([
                    'updated_by' => $actorUserId > 0 ? $actorUserId : null,
                    'id' => (int) ($linkedWork['id'] ?? 0),
                    'company_id' => $companyId,
                    'garage_id' => $garageId,
                ]);
            }
        }

        $deleteStmt = $pdo->prepare(
            'DELETE jl
             FROM job_labor jl
             INNER JOIN job_cards jc ON jc.id = jl.job_card_id
             WHERE jl.id = :line_id
               AND jc.company_id = :company_id
               AND jc.garage_id = :garage_id'
        );
        $deleteStmt->execute([
            'line_id' => $laborId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        if ($deleteStmt->rowCount() < 1) {
            throw new RuntimeException('Labor line delete was not applied.');
        }

        if (function_exists('job_recalculate_estimate') && $jobId > 0) {
            job_recalculate_estimate($jobId);
        }
        if (function_exists('job_append_history') && $jobId > 0) {
            job_append_history($jobId, 'LABOR_REMOVE', null, null, 'Labor line removed', ['labor_id' => $laborId, 'reason' => $reason]);
        }

        $pdo->commit();

        log_audit('job_cards', 'delete_labor', $jobId > 0 ? $jobId : null, 'Deleted labor line #' . $laborId, [
            'entity' => 'job_labor',
            'source' => 'SAFE_DELETE_MODAL',
            'metadata' => [
                'job_id' => $jobId,
                'job_number' => (string) ($line['job_number'] ?? ''),
                'labor_id' => $laborId,
                'description' => (string) ($line['description'] ?? ''),
            ],
        ]);

        return [
            'ok' => true,
            'message' => 'Labor line removed successfully.',
            'reversal_references' => [],
            'metadata' => [
                'job_id' => $jobId,
                'job_number' => (string) ($line['job_number'] ?? ''),
                'labor_id' => $laborId,
            ],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function safe_delete_dependency_delete_job_part_line(PDO $pdo, int $jobPartId, array $scope, int $actorUserId, string $reason): array
{
    safe_delete_dependency_require_jobs_modules();

    $companyId = (int) ($scope['company_id'] ?? 0);
    $garageId = (int) ($scope['garage_id'] ?? 0);
    if ($companyId <= 0 || $garageId <= 0) {
        throw new RuntimeException('Invalid active scope for job part delete.');
    }

    $pdo->beginTransaction();
    try {
        $lineStmt = $pdo->prepare(
            'SELECT jp.id, jp.job_card_id, jp.part_id, jp.quantity,
                    jc.status, jc.status_code, jc.job_number,
                    p.part_name, p.part_sku
             FROM job_parts jp
             INNER JOIN job_cards jc ON jc.id = jp.job_card_id
             LEFT JOIN parts p ON p.id = jp.part_id
             WHERE jp.id = :line_id
               AND jc.company_id = :company_id
               AND jc.garage_id = :garage_id
             LIMIT 1
             FOR UPDATE'
        );
        $lineStmt->execute([
            'line_id' => $jobPartId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $line = $lineStmt->fetch();
        if (!$line) {
            throw new RuntimeException('Job part line not found for this scope.');
        }

        $jobId = (int) ($line['job_card_id'] ?? 0);
        $jobStatus = strtoupper(trim((string) ($line['status'] ?? 'OPEN')));
        $jobStatusCode = strtoupper(trim((string) ($line['status_code'] ?? 'ACTIVE')));
        if ($jobStatus === 'CLOSED' || $jobStatusCode !== 'ACTIVE') {
            throw new RuntimeException('Reopen the job card and keep it ACTIVE before deleting part lines.');
        }

        $deleteStmt = $pdo->prepare(
            'DELETE jp
             FROM job_parts jp
             INNER JOIN job_cards jc ON jc.id = jp.job_card_id
             WHERE jp.id = :line_id
               AND jc.company_id = :company_id
               AND jc.garage_id = :garage_id'
        );
        $deleteStmt->execute([
            'line_id' => $jobPartId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        if ($deleteStmt->rowCount() < 1) {
            throw new RuntimeException('Job part line delete was not applied.');
        }

        if (function_exists('job_recalculate_estimate') && $jobId > 0) {
            job_recalculate_estimate($jobId);
        }
        if (function_exists('job_append_history') && $jobId > 0) {
            job_append_history($jobId, 'PART_REMOVE', null, null, 'Part line removed', ['job_part_id' => $jobPartId, 'reason' => $reason]);
        }

        $pdo->commit();

        log_audit('job_cards', 'delete_part', $jobId > 0 ? $jobId : null, 'Deleted part line #' . $jobPartId, [
            'entity' => 'job_part',
            'source' => 'SAFE_DELETE_MODAL',
            'metadata' => [
                'job_id' => $jobId,
                'job_number' => (string) ($line['job_number'] ?? ''),
                'job_part_id' => $jobPartId,
                'part_id' => (int) ($line['part_id'] ?? 0),
                'part_name' => (string) ($line['part_name'] ?? ''),
                'part_sku' => (string) ($line['part_sku'] ?? ''),
                'quantity' => round((float) ($line['quantity'] ?? 0), 2),
            ],
        ]);

        return [
            'ok' => true,
            'message' => 'Part line removed successfully.',
            'reversal_references' => [],
            'metadata' => [
                'job_id' => $jobId,
                'job_number' => (string) ($line['job_number'] ?? ''),
                'job_part_id' => $jobPartId,
            ],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function safe_delete_dependency_cancel_invoice(PDO $pdo, int $invoiceId, array $scope, int $actorUserId, string $reason): array
{
    safe_delete_dependency_require_billing_modules();

    $companyId = (int) ($scope['company_id'] ?? 0);
    $garageId = (int) ($scope['garage_id'] ?? 0);
    if ($companyId <= 0 || $garageId <= 0) {
        throw new RuntimeException('Invalid active scope for invoice cancellation.');
    }

    $pdo->beginTransaction();
    try {
        $dependencyReport = reversal_invoice_cancel_dependency_report($pdo, $invoiceId, $companyId, $garageId);
        $invoice = is_array($dependencyReport['invoice'] ?? null) ? (array) $dependencyReport['invoice'] : null;
        if (!is_array($invoice)) {
            throw new RuntimeException('Invoice not found for cancellation.');
        }

        $currentStatus = strtoupper(trim((string) ($invoice['invoice_status'] ?? '')));
        if ($currentStatus === 'CANCELLED') {
            throw new RuntimeException('Invoice is already cancelled.');
        }

        if (!(bool) ($dependencyReport['can_cancel'] ?? false)) {
            $blockers = array_values(array_filter(array_map('trim', (array) ($dependencyReport['blockers'] ?? []))));
            $steps = array_values(array_filter(array_map('trim', (array) ($dependencyReport['steps'] ?? []))));
            $intro = 'Invoice cancellation blocked.';
            if ($blockers !== []) {
                $intro .= ' ' . implode(' ', $blockers);
            }
            throw new RuntimeException(reversal_chain_message($intro, $steps));
        }

        $financialExtensionsReady = function_exists('billing_financial_extensions_ready')
            ? (bool) billing_financial_extensions_ready()
            : false;
        $releasedAdvance = ['released_total' => 0.0, 'released_count' => 0, 'rows' => []];
        if ($financialExtensionsReady && function_exists('billing_release_invoice_advance_adjustments')) {
            $releasedAdvance = billing_release_invoice_advance_adjustments(
                $pdo,
                $invoiceId,
                $actorUserId > 0 ? $actorUserId : null
            );
        }
        $releasedAdvanceTotal = round((float) ($releasedAdvance['released_total'] ?? 0), 2);
        $releasedAdvanceCount = (int) ($releasedAdvance['released_count'] ?? 0);
        $paymentSummary = (array) ($dependencyReport['payment_summary'] ?? []);
        $paidAmount = round((float) ($paymentSummary['net_paid_amount'] ?? 0), 2);

        $invoiceColumns = table_columns('invoices');
        $setParts = [
            'invoice_status = "CANCELLED"',
        ];
        if (in_array('payment_status', $invoiceColumns, true)) {
            $setParts[] = 'payment_status = "CANCELLED"';
        }
        $params = [
            'invoice_id' => $invoiceId,
        ];
        if (in_array('cancelled_at', $invoiceColumns, true)) {
            $setParts[] = 'cancelled_at = NOW()';
        }
        if (in_array('cancelled_by', $invoiceColumns, true)) {
            $setParts[] = 'cancelled_by = :cancelled_by';
            $params['cancelled_by'] = $actorUserId > 0 ? $actorUserId : null;
        }
        if (in_array('cancel_reason', $invoiceColumns, true)) {
            $setParts[] = 'cancel_reason = :cancel_reason';
            $params['cancel_reason'] = $reason;
        }

        $cancelSql = 'UPDATE invoices SET ' . implode(', ', $setParts) . ' WHERE id = :invoice_id';
        if (in_array('company_id', $invoiceColumns, true)) {
            $cancelSql .= ' AND company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        if (in_array('garage_id', $invoiceColumns, true)) {
            $cancelSql .= ' AND garage_id = :garage_id';
            $params['garage_id'] = $garageId;
        }
        $cancelStmt = $pdo->prepare($cancelSql);
        $cancelStmt->execute($params);
        if ($cancelStmt->rowCount() < 1) {
            throw new RuntimeException('Invoice cancellation was not applied.');
        }

        if (function_exists('billing_record_status_history')) {
            billing_record_status_history(
                $pdo,
                $invoiceId,
                $currentStatus !== '' ? $currentStatus : 'UNKNOWN',
                'CANCELLED',
                'CANCEL',
                $reason,
                $actorUserId > 0 ? $actorUserId : null,
                [
                    'paid_amount' => $paidAmount,
                    'advance_released_total' => $releasedAdvanceTotal,
                    'advance_released_count' => $releasedAdvanceCount,
                    'blockers' => [],
                    'dependency_report' => [
                        'inventory_movements' => (int) ($dependencyReport['inventory_movements'] ?? 0),
                        'outsourced_lines' => (int) ($dependencyReport['outsourced_lines'] ?? 0),
                    ],
                ]
            );
        }

        $pdo->commit();

        log_audit('billing', 'cancel', $invoiceId, 'Cancelled invoice ' . (string) ($invoice['invoice_number'] ?? ''), [
            'entity' => 'invoice',
            'source' => 'SAFE_DELETE_MODAL',
            'before' => [
                'invoice_status' => $currentStatus,
                'payment_status' => (string) ($invoice['payment_status'] ?? ''),
                'paid_amount' => $paidAmount,
            ],
            'after' => [
                'invoice_status' => 'CANCELLED',
                'payment_status' => 'CANCELLED',
                'cancel_reason' => $reason,
            ],
            'metadata' => [
                'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
                'advance_release_count' => $releasedAdvanceCount,
                'advance_release_total' => $releasedAdvanceTotal,
            ],
        ]);

        return [
            'ok' => true,
            'message' => 'Invoice cancelled successfully.',
            'reversal_references' => [],
            'metadata' => [
                'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
                'invoice_id' => $invoiceId,
                'advance_released_total' => $releasedAdvanceTotal,
                'advance_released_count' => $releasedAdvanceCount,
            ],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function safe_delete_dependency_delete_purchase(PDO $pdo, int $purchaseId, array $scope, int $actorUserId, string $reason): array
{
    $companyId = (int) ($scope['company_id'] ?? 0);
    $garageId = (int) ($scope['garage_id'] ?? 0);
    if ($companyId <= 0 || $garageId <= 0) {
        throw new RuntimeException('Invalid active scope for purchase delete.');
    }
    if (!safe_delete_table_has_column('purchases', 'status_code')) {
        throw new RuntimeException('Purchase soft-delete columns are not available.');
    }

    $pdo->beginTransaction();
    try {
        $purchase = safe_delete_dependency_fetch_purchase_for_update($pdo, $purchaseId, $companyId, $garageId);
        if (!is_array($purchase)) {
            throw new RuntimeException('Purchase not found for this scope.');
        }
        if (strtoupper(trim((string) ($purchase['status_code'] ?? ''))) === 'DELETED') {
            throw new RuntimeException('Purchase is already deleted.');
        }

        $dependencyReport = reversal_purchase_delete_dependency_report($pdo, $purchaseId, $companyId, $garageId);
        if (!(bool) ($dependencyReport['can_delete'] ?? false)) {
            $blockers = array_values(array_filter(array_map('trim', (array) ($dependencyReport['blockers'] ?? []))));
            $steps = array_values(array_filter(array_map('trim', (array) ($dependencyReport['steps'] ?? []))));
            $intro = 'Purchase deletion blocked.';
            if ($blockers !== []) {
                $intro .= ' ' . implode(' ', $blockers);
            }
            throw new RuntimeException(reversal_chain_message($intro, $steps));
        }

        $itemQtyStmt = $pdo->prepare(
            'SELECT part_id, COALESCE(SUM(quantity), 0) AS total_qty
             FROM purchase_items
             WHERE purchase_id = :purchase_id
             GROUP BY part_id
             FOR UPDATE'
        );
        $itemQtyStmt->execute(['purchase_id' => $purchaseId]);
        $itemRows = $itemQtyStmt->fetchAll();

        $stockAdjustments = [];
        foreach ($itemRows as $itemRow) {
            $partId = (int) ($itemRow['part_id'] ?? 0);
            $qty = round((float) ($itemRow['total_qty'] ?? 0), 2);
            if ($partId <= 0 || $qty <= 0.00001) {
                continue;
            }

            $currentQty = safe_delete_dependency_lock_inventory_row($pdo, $garageId, $partId);
            $nextQty = round($currentQty - $qty, 2);
            if ($nextQty < -0.009) {
                throw new RuntimeException(reversal_chain_message(
                    'Purchase deletion blocked. Stock from this purchase is already consumed downstream.',
                    ['Reverse downstream stock-out transactions first, then retry delete.']
                ));
            }

            $stockAdjustments[] = [
                'part_id' => $partId,
                'quantity' => $qty,
                'next_qty' => $nextQty,
            ];
        }

        $stockUpdateStmt = $pdo->prepare(
            'UPDATE garage_inventory
             SET quantity = :quantity
             WHERE garage_id = :garage_id
               AND part_id = :part_id'
        );

        $movementUids = [];
        foreach ($stockAdjustments as $stockAdjustment) {
            $partId = (int) $stockAdjustment['part_id'];
            $qty = round((float) $stockAdjustment['quantity'], 2);
            $stockUpdateStmt->execute([
                'quantity' => round((float) $stockAdjustment['next_qty'], 2),
                'garage_id' => $garageId,
                'part_id' => $partId,
            ]);

            $movementUid = 'pur-del-' . hash('sha256', $purchaseId . '|' . $partId . '|' . $qty . '|' . microtime(true));
            $movementUids[] = $movementUid;
            safe_delete_dependency_insert_inventory_movement($pdo, [
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'part_id' => $partId,
                'movement_type' => 'OUT',
                'quantity' => $qty,
                'reference_type' => 'PURCHASE',
                'reference_id' => $purchaseId,
                'movement_uid' => $movementUid,
                'notes' => 'Purchase #' . $purchaseId . ' delete stock reversal. Reason: ' . $reason,
                'created_by' => $actorUserId > 0 ? $actorUserId : null,
            ]);
        }

        $purchaseColumns = table_columns('purchases');
        $setParts = [
            'status_code = "DELETED"',
            'deleted_at = NOW()',
        ];
        $params = [
            'id' => $purchaseId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ];
        if (in_array('deleted_by', $purchaseColumns, true)) {
            $setParts[] = 'deleted_by = :deleted_by';
            $params['deleted_by'] = $actorUserId > 0 ? $actorUserId : null;
        }
        if (in_array('delete_reason', $purchaseColumns, true)) {
            $setParts[] = 'delete_reason = :delete_reason';
            $params['delete_reason'] = $reason;
        }
        if (in_array('deletion_reason', $purchaseColumns, true)) {
            $setParts[] = 'deletion_reason = :deletion_reason';
            $params['deletion_reason'] = $reason;
        }
        $deleteStmt = $pdo->prepare(
            'UPDATE purchases
             SET ' . implode(', ', $setParts) . '
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id'
        );
        $deleteStmt->execute($params);
        if ($deleteStmt->rowCount() < 1) {
            throw new RuntimeException('Purchase delete was not applied.');
        }

        $pdo->commit();

        log_audit('purchases', 'soft_delete', $purchaseId, 'Soft deleted purchase #' . $purchaseId, [
            'entity' => 'purchase',
            'source' => 'SAFE_DELETE_MODAL',
            'before' => [
                'status_code' => (string) ($purchase['status_code'] ?? ''),
                'payment_status' => (string) ($purchase['payment_status'] ?? ''),
                'grand_total' => (float) ($purchase['grand_total'] ?? 0),
            ],
            'after' => [
                'status_code' => 'DELETED',
                'delete_reason' => $reason,
            ],
            'metadata' => [
                'stock_reverse_lines' => count($stockAdjustments),
                'purchase_invoice_number' => (string) ($purchase['invoice_number'] ?? ''),
            ],
        ]);

        return [
            'ok' => true,
            'message' => 'Purchase deleted with stock reversal.',
            'reversal_references' => array_slice($movementUids, 0, 20),
            'metadata' => [
                'purchase_id' => $purchaseId,
                'purchase_invoice_number' => (string) ($purchase['invoice_number'] ?? ''),
                'stock_reverse_lines' => count($stockAdjustments),
            ],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function safe_delete_dependency_delete_job_card(PDO $pdo, int $jobId, array $scope, int $actorUserId, string $reason): array
{
    safe_delete_dependency_require_jobs_modules();

    $companyId = (int) ($scope['company_id'] ?? 0);
    $garageId = (int) ($scope['garage_id'] ?? 0);
    if ($companyId <= 0 || $garageId <= 0) {
        throw new RuntimeException('Invalid active scope for job delete.');
    }

    $pdo->beginTransaction();
    try {
        $dependencyReport = reversal_job_delete_dependency_report($pdo, $jobId, $companyId, $garageId);
        $job = is_array($dependencyReport['job'] ?? null) ? (array) $dependencyReport['job'] : null;
        if (!is_array($job)) {
            throw new RuntimeException('Job card not found for delete operation.');
        }

        if (!(bool) ($dependencyReport['can_delete'] ?? false)) {
            $blockers = array_values(array_filter(array_map('trim', (array) ($dependencyReport['blockers'] ?? []))));
            $steps = array_values(array_filter(array_map('trim', (array) ($dependencyReport['steps'] ?? []))));
            $intro = 'Job deletion blocked.';
            if ($blockers !== []) {
                $intro .= ' ' . implode(' ', $blockers);
            }
            throw new RuntimeException(reversal_chain_message($intro, $steps));
        }

        $cancellableOutsourcedIds = array_values(array_filter(
            (array) ($dependencyReport['cancellable_outsourced_ids'] ?? []),
            static fn (mixed $id): bool => (int) $id > 0
        ));

        if ($cancellableOutsourcedIds !== [] && table_columns('outsourced_works') !== []) {
            $outsourceCancelStmt = $pdo->prepare(
                'UPDATE outsourced_works
                 SET status_code = "INACTIVE",
                     deleted_at = COALESCE(deleted_at, NOW()),
                     notes = CONCAT(COALESCE(notes, ""), CASE WHEN COALESCE(notes, "") = "" THEN "" ELSE " | " END, :cancel_tag),
                     updated_by = :updated_by
                 WHERE id = :id
                   AND company_id = :company_id
                   AND garage_id = :garage_id
                   AND status_code = "ACTIVE"'
            );
            foreach ($cancellableOutsourcedIds as $outsourcedId) {
                $outsourceCancelStmt->execute([
                    'cancel_tag' => 'Cancelled due to job delete #' . $jobId,
                    'updated_by' => $actorUserId > 0 ? $actorUserId : null,
                    'id' => (int) $outsourcedId,
                    'company_id' => $companyId,
                    'garage_id' => $garageId,
                ]);
            }
        }

        $jobCardColumns = table_columns('job_cards');
        $setParts = [
            'status_code = "DELETED"',
            'deleted_at = NOW()',
        ];
        $params = [
            'id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ];
        if (in_array('cancel_note', $jobCardColumns, true)) {
            $setParts[] = 'cancel_note = :cancel_note';
            $params['cancel_note'] = $reason;
        }
        if (in_array('updated_by', $jobCardColumns, true)) {
            $setParts[] = 'updated_by = :updated_by';
            $params['updated_by'] = $actorUserId > 0 ? $actorUserId : null;
        }
        if (in_array('deleted_by', $jobCardColumns, true)) {
            $setParts[] = 'deleted_by = :deleted_by';
            $params['deleted_by'] = $actorUserId > 0 ? $actorUserId : null;
        }
        if (in_array('deletion_reason', $jobCardColumns, true)) {
            $setParts[] = 'deletion_reason = :deletion_reason';
            $params['deletion_reason'] = $reason;
        }

        $deleteStmt = $pdo->prepare(
            'UPDATE job_cards
             SET ' . implode(', ', $setParts) . '
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id'
        );
        $deleteStmt->execute($params);
        if ($deleteStmt->rowCount() < 1) {
            throw new RuntimeException('Job card delete was not applied.');
        }

        $intakeDeletedCount = 0;
        if (function_exists('job_vehicle_intake_soft_delete_by_job')) {
            $intakeDeletedCount = (int) job_vehicle_intake_soft_delete_by_job(
                $companyId,
                $garageId,
                $jobId,
                $actorUserId > 0 ? $actorUserId : null,
                $reason
            );
        }

        if (function_exists('job_append_history')) {
            job_append_history(
                $jobId,
                'SOFT_DELETE',
                (string) ($job['status'] ?? ''),
                (string) ($job['status'] ?? ''),
                $reason
            );
        }

        $pdo->commit();

        log_audit('job_cards', 'soft_delete', $jobId, 'Soft deleted job card with dependency enforcement', [
            'entity' => 'job_card',
            'source' => 'SAFE_DELETE_MODAL',
            'before' => [
                'status' => (string) ($job['status'] ?? ''),
                'status_code' => (string) ($job['status_code'] ?? ''),
            ],
            'after' => [
                'status' => (string) ($job['status'] ?? ''),
                'status_code' => 'DELETED',
            ],
            'metadata' => [
                'job_number' => (string) ($job['job_number'] ?? ''),
                'cancellable_outsourced_count' => count($cancellableOutsourcedIds),
                'inventory_movements' => (int) ($dependencyReport['inventory_movements'] ?? 0),
                'intake_deleted_count' => $intakeDeletedCount,
            ],
        ]);

        return [
            'ok' => true,
            'message' => 'Job card deleted successfully.',
            'reversal_references' => [],
            'metadata' => [
                'job_id' => $jobId,
                'job_number' => (string) ($job['job_number'] ?? ''),
                'cancellable_outsourced_count' => count($cancellableOutsourcedIds),
                'intake_deleted_count' => $intakeDeletedCount,
            ],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function safe_delete_dependency_delete_vehicle(PDO $pdo, int $vehicleId, array $scope, int $actorUserId, string $reason): array
{
    $companyId = (int) ($scope['company_id'] ?? 0);
    if ($companyId <= 0) {
        throw new RuntimeException('Invalid active scope for vehicle delete.');
    }

    $vehicleColumns = table_columns('vehicles');
    if ($vehicleColumns === []) {
        throw new RuntimeException('Vehicles table is not available.');
    }

    $selectFields = ['id'];
    foreach (['registration_no', 'status_code', 'company_id'] as $col) {
        if (in_array($col, $vehicleColumns, true)) {
            $selectFields[] = $col;
        }
    }
    $fetchSql = 'SELECT ' . implode(', ', array_unique($selectFields)) . ' FROM vehicles WHERE id = :id';
    $params = ['id' => $vehicleId];
    if (in_array('company_id', $vehicleColumns, true)) {
        $fetchSql .= ' AND company_id = :company_id';
        $params['company_id'] = $companyId;
    }
    $fetchSql .= ' LIMIT 1';
    $vehicle = safe_delete_fetch_row($pdo, $fetchSql, $params);
    if (!is_array($vehicle)) {
        throw new RuntimeException('Vehicle not found for this scope.');
    }

    if (strtoupper(trim((string) ($vehicle['status_code'] ?? ''))) === 'DELETED') {
        throw new RuntimeException('Vehicle is already deleted.');
    }

    $pdo->beginTransaction();
    try {
        $setParts = [
            'is_active = 0',
            'status_code = "DELETED"',
            'deleted_at = NOW()',
        ];
        $updateParams = [
            'id' => $vehicleId,
        ];
        if (in_array('company_id', $vehicleColumns, true)) {
            $updateParams['company_id'] = $companyId;
        }
        if (in_array('deleted_by', $vehicleColumns, true)) {
            $setParts[] = 'deleted_by = :deleted_by';
            $updateParams['deleted_by'] = $actorUserId > 0 ? $actorUserId : null;
        }
        if (in_array('deletion_reason', $vehicleColumns, true)) {
            $setParts[] = 'deletion_reason = :deletion_reason';
            $updateParams['deletion_reason'] = $reason !== '' ? $reason : null;
        }

        $sql = 'UPDATE vehicles SET ' . implode(', ', $setParts) . ' WHERE id = :id';
        if (in_array('company_id', $vehicleColumns, true)) {
            $sql .= ' AND company_id = :company_id';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($updateParams);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Vehicle delete was not applied.');
        }

        if (function_exists('add_vehicle_history')) {
            $historyMeta = ['status_code' => 'DELETED'];
            if ($reason !== '') {
                $historyMeta['deletion_reason'] = $reason;
            }
            add_vehicle_history($vehicleId, 'STATUS', 'Status changed to DELETED', $historyMeta);
        }

        $pdo->commit();
        log_audit('vehicles', 'status', $vehicleId, 'Changed vehicle status to DELETED', [
            'entity' => 'vehicle',
            'source' => 'SAFE_DELETE_MODAL',
            'metadata' => [
                'registration_no' => (string) ($vehicle['registration_no'] ?? ''),
                'deletion_reason' => $reason,
            ],
        ]);

        return [
            'ok' => true,
            'message' => 'Vehicle deleted successfully.',
            'reversal_references' => [],
            'metadata' => [
                'vehicle_id' => $vehicleId,
                'registration_no' => (string) ($vehicle['registration_no'] ?? ''),
            ],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function safe_delete_dependency_delete_inventory_part(PDO $pdo, int $partId, array $scope, int $actorUserId, string $reason): array
{
    $companyId = (int) ($scope['company_id'] ?? 0);
    if ($companyId <= 0) {
        throw new RuntimeException('Invalid active scope for part delete.');
    }
    $partColumns = table_columns('parts');
    if ($partColumns === []) {
        throw new RuntimeException('Parts table is not available.');
    }

    $part = safe_delete_fetch_row(
        $pdo,
        'SELECT id, part_sku, part_name, status_code
         FROM parts
         WHERE id = :id
           AND company_id = :company_id
         LIMIT 1',
        [
            'id' => $partId,
            'company_id' => $companyId,
        ]
    );
    if (!is_array($part)) {
        throw new RuntimeException('Part not found for this scope.');
    }
    if (strtoupper(trim((string) ($part['status_code'] ?? ''))) === 'DELETED') {
        throw new RuntimeException('Part is already deleted.');
    }

    $pdo->beginTransaction();
    try {
        $setParts = [
            'status_code = "DELETED"',
            'is_active = 0',
            'deleted_at = NOW()',
        ];
        $params = [
            'id' => $partId,
            'company_id' => $companyId,
        ];
        if (in_array('deleted_by', $partColumns, true)) {
            $setParts[] = 'deleted_by = :deleted_by';
            $params['deleted_by'] = $actorUserId > 0 ? $actorUserId : null;
        }
        if (in_array('deletion_reason', $partColumns, true)) {
            $setParts[] = 'deletion_reason = :deletion_reason';
            $params['deletion_reason'] = $reason !== '' ? $reason : null;
        }

        $stmt = $pdo->prepare(
            'UPDATE parts
             SET ' . implode(', ', $setParts) . '
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute($params);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Part delete was not applied.');
        }

        $pdo->commit();
        log_audit('parts_master', 'status', $partId, 'Changed part status to DELETED', [
            'entity' => 'part',
            'source' => 'SAFE_DELETE_MODAL',
            'metadata' => [
                'part_sku' => (string) ($part['part_sku'] ?? ''),
                'part_name' => (string) ($part['part_name'] ?? ''),
                'deletion_reason' => $reason,
            ],
        ]);

        return [
            'ok' => true,
            'message' => 'Part deleted successfully.',
            'reversal_references' => [],
            'metadata' => [
                'part_id' => $partId,
                'part_sku' => (string) ($part['part_sku'] ?? ''),
                'part_name' => (string) ($part['part_name'] ?? ''),
            ],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function safe_delete_dependency_reverse_invoice_payment(PDO $pdo, int $paymentId, array $scope, int $actorUserId, string $reason): array
{
    safe_delete_dependency_require_billing_modules();

    $companyId = (int) ($scope['company_id'] ?? 0);
    $garageId = (int) ($scope['garage_id'] ?? 0);
    if ($companyId <= 0 || $garageId <= 0) {
        throw new RuntimeException('Invalid active scope for invoice payment reversal.');
    }

    if (!function_exists('billing_normalize_payment_mode')
        || !function_exists('billing_round')
        || !function_exists('billing_payment_mode_summary')
        || !function_exists('billing_record_payment_history')
    ) {
        throw new RuntimeException('Billing helpers are not available for dependency action.');
    }

    $paymentColumns = table_columns('payments');
    $hasEntryType = in_array('entry_type', $paymentColumns, true);
    $hasReversedPaymentId = in_array('reversed_payment_id', $paymentColumns, true);
    $hasIsReversed = in_array('is_reversed', $paymentColumns, true);
    $hasReversedAt = in_array('reversed_at', $paymentColumns, true);
    $hasReversedBy = in_array('reversed_by', $paymentColumns, true);
    $hasReverseReason = in_array('reverse_reason', $paymentColumns, true);
    $financialExtensionsReady = function_exists('billing_financial_extensions_ready')
        ? (bool) billing_financial_extensions_ready()
        : false;

    $pdo->beginTransaction();
    try {
        $selectFields = [
            'p.id',
            'p.invoice_id',
            'p.amount',
            'p.paid_on',
            'p.payment_mode',
            'p.reference_no',
            'p.notes',
            'p.received_by',
            'i.invoice_number',
            'i.grand_total',
            'i.invoice_status',
            'i.payment_status',
            'i.customer_id',
        ];
        if ($hasEntryType) {
            $selectFields[] = 'p.entry_type';
        }
        if ($hasReversedPaymentId) {
            $selectFields[] = 'p.reversed_payment_id';
        }
        if ($hasIsReversed) {
            $selectFields[] = 'p.is_reversed';
        }

        $stmt = $pdo->prepare(
            'SELECT ' . implode(', ', $selectFields) . '
             FROM payments p
             INNER JOIN invoices i ON i.id = p.invoice_id
             WHERE p.id = :payment_id
               AND i.company_id = :company_id
               AND i.garage_id = :garage_id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([
            'payment_id' => $paymentId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $payment = $stmt->fetch();
        if (!$payment) {
            throw new RuntimeException('Payment entry not found for this scope.');
        }

        $entryType = strtoupper((string) ($payment['entry_type'] ?? ((float) ($payment['amount'] ?? 0) > 0 ? 'PAYMENT' : 'REVERSAL')));
        if ($entryType !== 'PAYMENT') {
            throw new RuntimeException('Only original payment entries can be reversed.');
        }

        $paymentAmount = round((float) ($payment['amount'] ?? 0), 2);
        if ($paymentAmount <= 0.009) {
            throw new RuntimeException('Invalid payment amount for reversal.');
        }

        if ($hasIsReversed && (int) ($payment['is_reversed'] ?? 0) === 1) {
            throw new RuntimeException('Payment is already marked as reversed.');
        }

        if ($hasReversedPaymentId) {
            $alreadySql = 'SELECT id FROM payments WHERE reversed_payment_id = :payment_id';
            if ($hasEntryType) {
                $alreadySql .= ' AND (entry_type = "REVERSAL" OR entry_type IS NULL)';
            }
            $alreadySql .= ' LIMIT 1';
            $alreadyStmt = $pdo->prepare($alreadySql);
            $alreadyStmt->execute(['payment_id' => $paymentId]);
            if ($alreadyStmt->fetch()) {
                throw new RuntimeException('This payment has already been reversed.');
            }
        } else {
            $legacyStmt = $pdo->prepare(
                'SELECT id
                 FROM payments
                 WHERE invoice_id = :invoice_id
                   AND amount < 0
                   AND reference_no = :reference_no
                 LIMIT 1'
            );
            $legacyStmt->execute([
                'invoice_id' => (int) ($payment['invoice_id'] ?? 0),
                'reference_no' => 'REV-' . $paymentId,
            ]);
            if ($legacyStmt->fetch()) {
                throw new RuntimeException('This payment appears to have already been reversed.');
            }
        }

        $reversalDate = date('Y-m-d');
        $insertColumns = ['invoice_id', 'amount', 'paid_on', 'payment_mode', 'reference_no', 'notes', 'received_by'];
        $insertParams = [
            'invoice_id' => (int) ($payment['invoice_id'] ?? 0),
            'amount' => -$paymentAmount,
            'paid_on' => $reversalDate,
            'payment_mode' => billing_normalize_payment_mode((string) ($payment['payment_mode'] ?? 'CASH')),
            'reference_no' => 'REV-' . $paymentId,
            'notes' => $reason,
            'received_by' => $actorUserId > 0 ? $actorUserId : null,
        ];
        if ($hasEntryType) {
            $insertColumns[] = 'entry_type';
            $insertParams['entry_type'] = 'REVERSAL';
        }
        if ($hasReversedPaymentId) {
            $insertColumns[] = 'reversed_payment_id';
            $insertParams['reversed_payment_id'] = $paymentId;
        }
        if ($hasIsReversed) {
            $insertColumns[] = 'is_reversed';
            $insertParams['is_reversed'] = 0;
        }
        if ($hasReversedAt) {
            $insertColumns[] = 'reversed_at';
            $insertParams['reversed_at'] = null;
        }
        if ($hasReversedBy) {
            $insertColumns[] = 'reversed_by';
            $insertParams['reversed_by'] = null;
        }
        if ($hasReverseReason) {
            $insertColumns[] = 'reverse_reason';
            $insertParams['reverse_reason'] = $reason;
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO payments (' . implode(', ', $insertColumns) . ')
             VALUES (:' . implode(', :', $insertColumns) . ')'
        );
        $insertStmt->execute($insertParams);
        $reversalId = (int) $pdo->lastInsertId();

        $updateSets = [];
        $updateParams = ['payment_id' => $paymentId];
        if ($hasIsReversed) {
            $updateSets[] = 'is_reversed = 1';
        }
        if ($hasReversedAt) {
            $updateSets[] = 'reversed_at = NOW()';
        }
        if ($hasReversedBy) {
            $updateSets[] = 'reversed_by = :reversed_by';
            $updateParams['reversed_by'] = $actorUserId > 0 ? $actorUserId : null;
        }
        if ($hasReverseReason) {
            $updateSets[] = 'reverse_reason = :reverse_reason';
            $updateParams['reverse_reason'] = $reason;
        }
        if ($updateSets !== []) {
            $updateStmt = $pdo->prepare('UPDATE payments SET ' . implode(', ', $updateSets) . ' WHERE id = :payment_id');
            $updateStmt->execute($updateParams);
        }

        $invoiceId = (int) ($payment['invoice_id'] ?? 0);
        $paidSumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :invoice_id');
        $paidSumStmt->execute(['invoice_id' => $invoiceId]);
        $netPaid = round((float) $paidSumStmt->fetchColumn(), 2);
        $grandTotal = round((float) ($payment['grand_total'] ?? 0), 2);
        $advanceAdjusted = ($financialExtensionsReady && function_exists('billing_invoice_advance_adjusted_total'))
            ? (float) billing_invoice_advance_adjusted_total($pdo, $invoiceId)
            : 0.0;
        $netSettled = billing_round($netPaid + $advanceAdjusted);

        $paymentStatus = 'PARTIAL';
        if ($netSettled <= 0.009) {
            $paymentStatus = 'UNPAID';
        } elseif ($netSettled + 0.009 >= $grandTotal) {
            $paymentStatus = 'PAID';
        }

        $summaryMode = billing_payment_mode_summary($pdo, $invoiceId);
        $invoiceUpdate = $pdo->prepare(
            'UPDATE invoices
             SET payment_status = :payment_status,
                 payment_mode = :payment_mode
             WHERE id = :invoice_id'
        );
        $invoiceUpdate->execute([
            'payment_status' => $paymentStatus,
            'payment_mode' => $summaryMode,
            'invoice_id' => $invoiceId,
        ]);

        billing_record_payment_history(
            $pdo,
            $invoiceId,
            $reversalId,
            'PAYMENT_REVERSED',
            $reason,
            $actorUserId > 0 ? $actorUserId : null,
            [
                'reversed_payment_id' => $paymentId,
                'reversal_amount' => -$paymentAmount,
                'reversal_date' => $reversalDate,
                'net_paid_amount' => $netPaid,
                'advance_adjusted' => $advanceAdjusted,
                'net_settled_amount' => $netSettled,
            ]
        );

        log_audit('billing', 'payment_reverse', $invoiceId, 'Reversed payment #' . $paymentId . ' for invoice ' . (string) ($payment['invoice_number'] ?? ''), [
            'entity' => 'invoice_payment',
            'source' => 'SAFE_DELETE_MODAL',
            'after' => [
                'payment_id' => $paymentId,
                'reversal_id' => $reversalId,
                'payment_status' => $paymentStatus,
                'payment_mode' => $summaryMode,
                'reason' => $reason,
            ],
            'metadata' => [
                'invoice_number' => (string) ($payment['invoice_number'] ?? ''),
            ],
        ]);

        if ($financialExtensionsReady && function_exists('billing_record_customer_ledger_entry')) {
            billing_record_customer_ledger_entry(
                $pdo,
                $companyId,
                $garageId,
                (int) ($payment['customer_id'] ?? 0),
                $reversalDate,
                'ADJUSTMENT',
                'PAYMENT_REVERSAL',
                $reversalId,
                $paymentAmount,
                0.0,
                $actorUserId > 0 ? $actorUserId : null,
                'Payment reversal for invoice ' . (string) ($payment['invoice_number'] ?? '')
            );
        }

        $pdo->commit();

        return [
            'ok' => true,
            'message' => 'Invoice payment reversed successfully.',
            'reversal_references' => ['REV-' . $paymentId, (string) $reversalId],
            'metadata' => [
                'invoice_id' => $invoiceId,
                'invoice_number' => (string) ($payment['invoice_number'] ?? ''),
                'reversal_id' => $reversalId,
            ],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function safe_delete_dependency_reverse_purchase_payment(PDO $pdo, int $paymentId, array $scope, int $actorUserId, string $reason): array
{
    $companyId = (int) ($scope['company_id'] ?? 0);
    $garageId = (int) ($scope['garage_id'] ?? 0);
    if ($companyId <= 0 || $garageId <= 0) {
        throw new RuntimeException('Invalid active scope for purchase payment reversal.');
    }
    if (!function_exists('finance_record_expense_for_purchase_payment')) {
        throw new RuntimeException('Finance helpers are not available for purchase payment reversal.');
    }

    $pdo->beginTransaction();
    try {
        $paymentStmt = $pdo->prepare(
            'SELECT pp.*, p.grand_total, p.id AS purchase_id,
                    COALESCE(pay.total_paid, 0) AS total_paid
             FROM purchase_payments pp
             INNER JOIN purchases p ON p.id = pp.purchase_id
             LEFT JOIN (
               SELECT purchase_id, SUM(amount) AS total_paid
               FROM purchase_payments
               GROUP BY purchase_id
             ) pay ON pay.purchase_id = p.id
             WHERE pp.id = :id
               AND p.company_id = :company_id
               AND p.garage_id = :garage_id
             LIMIT 1
             FOR UPDATE'
        );
        $paymentStmt->execute([
            'id' => $paymentId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $payment = $paymentStmt->fetch();
        if (!$payment) {
            throw new RuntimeException('Purchase payment entry not found.');
        }
        if ((string) ($payment['entry_type'] ?? '') !== 'PAYMENT') {
            throw new RuntimeException('Only payment entries can be reversed.');
        }
        if ((float) ($payment['amount'] ?? 0) <= 0) {
            throw new RuntimeException('Invalid payment amount for reversal.');
        }

        $checkStmt = $pdo->prepare(
            'SELECT id FROM purchase_payments WHERE reversed_payment_id = :payment_id LIMIT 1'
        );
        $checkStmt->execute(['payment_id' => $paymentId]);
        if ($checkStmt->fetch()) {
            throw new RuntimeException('This payment has already been reversed.');
        }

        $purchaseId = (int) ($payment['purchase_id'] ?? 0);
        $paymentAmount = round((float) ($payment['amount'] ?? 0), 2);
        $reversalDate = date('Y-m-d');
        $insertStmt = $pdo->prepare(
            'INSERT INTO purchase_payments
              (purchase_id, company_id, garage_id, payment_date, entry_type, amount, payment_mode, reference_no, notes, reversed_payment_id, created_by)
             VALUES
              (:purchase_id, :company_id, :garage_id, :payment_date, "REVERSAL", :amount, "ADJUSTMENT", :reference_no, :notes, :reversed_payment_id, :created_by)'
        );
        $insertStmt->execute([
            'purchase_id' => $purchaseId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'payment_date' => $reversalDate,
            'amount' => -$paymentAmount,
            'reference_no' => 'REV-' . $paymentId,
            'notes' => $reason,
            'reversed_payment_id' => $paymentId,
            'created_by' => $actorUserId > 0 ? $actorUserId : null,
        ]);
        $reversalId = (int) $pdo->lastInsertId();

        $purchase = safe_delete_dependency_fetch_purchase_for_update($pdo, $purchaseId, $companyId, $garageId);
        if (!$purchase) {
            throw new RuntimeException('Purchase not found after reversal.');
        }

        $grandTotal = round((float) ($purchase['grand_total'] ?? 0), 2);
        $newPaid = round((float) ($purchase['total_paid'] ?? 0), 2);
        $nextStatus = safe_delete_dependency_purchase_payment_status_from_amounts($grandTotal, $newPaid);

        $updateStmt = $pdo->prepare('UPDATE purchases SET payment_status = :payment_status WHERE id = :id');
        $updateStmt->execute([
            'payment_status' => $nextStatus,
            'id' => $purchaseId,
        ]);

        finance_record_expense_for_purchase_payment(
            $reversalId,
            $purchaseId,
            $companyId,
            $garageId,
            $paymentAmount,
            $reversalDate,
            'ADJUSTMENT',
            $reason,
            true,
            $actorUserId > 0 ? $actorUserId : null
        );

        $pdo->commit();

        log_audit('purchases', 'payment_reverse', $purchaseId, 'Reversed purchase payment #' . $paymentId, [
            'entity' => 'purchase_payment',
            'source' => 'SAFE_DELETE_MODAL',
            'after' => [
                'purchase_id' => $purchaseId,
                'payment_id' => $paymentId,
                'reversal_id' => $reversalId,
                'reversal_amount' => -$paymentAmount,
                'payment_status' => $nextStatus,
            ],
        ]);

        return [
            'ok' => true,
            'message' => 'Purchase payment reversed successfully.',
            'reversal_references' => ['REV-' . $paymentId, (string) $reversalId],
            'metadata' => [
                'purchase_id' => $purchaseId,
                'reversal_id' => $reversalId,
            ],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function safe_delete_dependency_reverse_return_settlement(PDO $pdo, int $settlementId, array $scope, int $actorUserId, string $reason): array
{
    safe_delete_dependency_require_returns_modules();
    $companyId = (int) ($scope['company_id'] ?? 0);
    $garageId = (int) ($scope['garage_id'] ?? 0);
    if (!function_exists('returns_reverse_settlement')) {
        throw new RuntimeException('Returns settlement workflow is not available.');
    }

    $result = returns_reverse_settlement($pdo, $settlementId, $companyId, $garageId, $actorUserId, $reason);
    return [
        'ok' => true,
        'message' => 'Return settlement reversed successfully.',
        'reversal_references' => array_values(array_filter([
            (int) ($result['finance_reversal_expense_id'] ?? 0) > 0 ? ('EXP#' . (int) ($result['finance_reversal_expense_id'] ?? 0)) : '',
            'SETTLEMENT#' . $settlementId,
        ])),
        'metadata' => [
            'return_id' => (int) ($result['return_id'] ?? 0),
            'return_number' => (string) ($result['return_number'] ?? ''),
            'finance_reversal_expense_id' => (int) ($result['finance_reversal_expense_id'] ?? 0),
        ],
    ];
}

function safe_delete_dependency_delete_return_attachment(PDO $pdo, int $attachmentId, array $scope, int $actorUserId, string $reason): array
{
    safe_delete_dependency_require_returns_modules();
    $companyId = (int) ($scope['company_id'] ?? 0);
    $garageId = (int) ($scope['garage_id'] ?? 0);
    if (!function_exists('returns_delete_attachment')) {
        throw new RuntimeException('Return attachment workflow is not available.');
    }

    $attachmentRow = safe_delete_fetch_row(
        $pdo,
        'SELECT id, return_id, file_name
         FROM return_attachments
         WHERE id = :id
           AND company_id = :company_id
           AND garage_id = :garage_id
           AND status_code = "ACTIVE"
         LIMIT 1',
        [
            'id' => $attachmentId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]
    );
    if (!is_array($attachmentRow)) {
        throw new RuntimeException('Return attachment not found for this scope.');
    }

    $pdo->beginTransaction();
    try {
        $ok = returns_delete_attachment($pdo, $attachmentId, $companyId, $garageId, $actorUserId);
        if (!$ok) {
            throw new RuntimeException('Unable to delete return attachment.');
        }
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return [
        'ok' => true,
        'message' => 'Return attachment deleted successfully.',
        'reversal_references' => [],
        'metadata' => [
            'return_id' => (int) ($attachmentRow['return_id'] ?? 0),
            'attachment_id' => $attachmentId,
            'file_name' => (string) ($attachmentRow['file_name'] ?? ''),
            'deletion_reason' => $reason,
        ],
    ];
}
