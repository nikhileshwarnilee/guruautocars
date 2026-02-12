<?php
declare(strict_types=1);

function reversal_chain_message(string $intro, array $steps): string
{
    $cleanIntro = trim($intro);
    $cleanSteps = [];
    foreach ($steps as $step) {
        $stepText = trim((string) $step);
        if ($stepText === '') {
            continue;
        }
        $cleanSteps[] = $stepText;
    }

    if ($cleanSteps === []) {
        return $cleanIntro;
    }

    $parts = $cleanIntro !== '' ? [$cleanIntro] : [];
    foreach ($cleanSteps as $index => $stepText) {
        $parts[] = ($index + 1) . ') ' . $stepText;
    }

    return implode(' ', $parts);
}

function reversal_invoice_payment_summary(PDO $pdo, int $invoiceId): array
{
    $invoiceId = max(0, $invoiceId);
    if ($invoiceId <= 0) {
        return [
            'unreversed_count' => 0,
            'unreversed_amount' => 0.0,
            'net_paid_amount' => 0.0,
            'supports_reversal_rows' => false,
        ];
    }

    $columns = table_columns('payments');
    $hasEntryType = in_array('entry_type', $columns, true);
    $hasReversedPaymentId = in_array('reversed_payment_id', $columns, true);
    $hasIsReversed = in_array('is_reversed', $columns, true);

    if ($hasEntryType && $hasReversedPaymentId) {
        $sql =
            'SELECT COUNT(*) AS unreversed_count,
                    COALESCE(SUM(p.amount), 0) AS unreversed_amount
             FROM payments p
             LEFT JOIN payments rev
               ON rev.reversed_payment_id = p.id
              AND (rev.entry_type = "REVERSAL" OR rev.entry_type IS NULL)
             WHERE p.invoice_id = :invoice_id
               AND p.entry_type = "PAYMENT"
               AND rev.id IS NULL';

        if ($hasIsReversed) {
            $sql .= ' AND COALESCE(p.is_reversed, 0) = 0';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['invoice_id' => $invoiceId]);
        $row = $stmt->fetch() ?: [];

        $netStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :invoice_id');
        $netStmt->execute(['invoice_id' => $invoiceId]);

        return [
            'unreversed_count' => (int) ($row['unreversed_count'] ?? 0),
            'unreversed_amount' => round((float) ($row['unreversed_amount'] ?? 0), 2),
            'net_paid_amount' => round((float) $netStmt->fetchColumn(), 2),
            'supports_reversal_rows' => true,
        ];
    }

    $where = ['invoice_id = :invoice_id', 'amount > 0'];
    if ($hasEntryType) {
        $where[] = 'entry_type = "PAYMENT"';
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS unreversed_count,
                COALESCE(SUM(amount), 0) AS unreversed_amount
         FROM payments
         WHERE ' . implode(' AND ', $where)
    );
    $stmt->execute(['invoice_id' => $invoiceId]);
    $row = $stmt->fetch() ?: [];

    $netStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :invoice_id');
    $netStmt->execute(['invoice_id' => $invoiceId]);

    return [
        'unreversed_count' => (int) ($row['unreversed_count'] ?? 0),
        'unreversed_amount' => round((float) ($row['unreversed_amount'] ?? 0), 2),
        'net_paid_amount' => round((float) $netStmt->fetchColumn(), 2),
        'supports_reversal_rows' => false,
    ];
}

function reversal_purchase_payment_summary(PDO $pdo, int $purchaseId): array
{
    $purchaseId = max(0, $purchaseId);
    if ($purchaseId <= 0) {
        return [
            'unreversed_count' => 0,
            'unreversed_amount' => 0.0,
            'net_paid_amount' => 0.0,
            'supports_reversal_rows' => false,
        ];
    }

    $columns = table_columns('purchase_payments');
    if ($columns === []) {
        return [
            'unreversed_count' => 0,
            'unreversed_amount' => 0.0,
            'net_paid_amount' => 0.0,
            'supports_reversal_rows' => false,
        ];
    }

    $hasEntryType = in_array('entry_type', $columns, true);
    $hasReversedPaymentId = in_array('reversed_payment_id', $columns, true);

    if ($hasEntryType && $hasReversedPaymentId) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS unreversed_count,
                    COALESCE(SUM(pp.amount), 0) AS unreversed_amount
             FROM purchase_payments pp
             LEFT JOIN purchase_payments rev
               ON rev.reversed_payment_id = pp.id
              AND (rev.entry_type = "REVERSAL" OR rev.entry_type IS NULL)
             WHERE pp.purchase_id = :purchase_id
               AND pp.entry_type = "PAYMENT"
               AND rev.id IS NULL'
        );
        $stmt->execute(['purchase_id' => $purchaseId]);
        $row = $stmt->fetch() ?: [];

        $netStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM purchase_payments WHERE purchase_id = :purchase_id');
        $netStmt->execute(['purchase_id' => $purchaseId]);

        return [
            'unreversed_count' => (int) ($row['unreversed_count'] ?? 0),
            'unreversed_amount' => round((float) ($row['unreversed_amount'] ?? 0), 2),
            'net_paid_amount' => round((float) $netStmt->fetchColumn(), 2),
            'supports_reversal_rows' => true,
        ];
    }

    $where = ['purchase_id = :purchase_id', 'amount > 0'];
    if ($hasEntryType) {
        $where[] = 'entry_type = "PAYMENT"';
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS unreversed_count,
                COALESCE(SUM(amount), 0) AS unreversed_amount
         FROM purchase_payments
         WHERE ' . implode(' AND ', $where)
    );
    $stmt->execute(['purchase_id' => $purchaseId]);
    $row = $stmt->fetch() ?: [];

    $netStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM purchase_payments WHERE purchase_id = :purchase_id');
    $netStmt->execute(['purchase_id' => $purchaseId]);

    return [
        'unreversed_count' => (int) ($row['unreversed_count'] ?? 0),
        'unreversed_amount' => round((float) ($row['unreversed_amount'] ?? 0), 2),
        'net_paid_amount' => round((float) $netStmt->fetchColumn(), 2),
        'supports_reversal_rows' => false,
    ];
}

function reversal_invoice_cancel_dependency_report(PDO $pdo, int $invoiceId, int $companyId, int $garageId): array
{
    $invoiceColumns = table_columns('invoices');
    $selectFields = ['id', 'invoice_number', 'job_card_id', 'invoice_status', 'payment_status', 'grand_total'];

    $lockColumns = ['export_locked_at', 'gst_locked_at', 'is_export_locked', 'gstr1_filed_at', 'filing_locked_at'];
    foreach ($lockColumns as $column) {
        if (in_array($column, $invoiceColumns, true)) {
            $selectFields[] = $column;
        }
    }

    $invoiceStmt = $pdo->prepare(
        'SELECT ' . implode(', ', $selectFields) . '
         FROM invoices
         WHERE id = :invoice_id
           AND company_id = :company_id
           AND garage_id = :garage_id
         LIMIT 1
         FOR UPDATE'
    );
    $invoiceStmt->execute([
        'invoice_id' => $invoiceId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $invoice = $invoiceStmt->fetch() ?: null;

    if ($invoice === null) {
        return [
            'invoice' => null,
            'can_cancel' => false,
            'blockers' => ['Invoice not found for this scope.'],
            'steps' => [],
            'payment_summary' => [
                'unreversed_count' => 0,
                'unreversed_amount' => 0.0,
                'net_paid_amount' => 0.0,
                'supports_reversal_rows' => false,
            ],
            'inventory_movements' => 0,
            'outsourced_lines' => 0,
        ];
    }

    $blockers = [];
    $steps = [];

    $paymentSummary = reversal_invoice_payment_summary($pdo, (int) $invoice['id']);
    if ((int) ($paymentSummary['unreversed_count'] ?? 0) > 0) {
        $blockers[] = 'Invoice has unreversed payments.';
        $steps[] = 'Open Billing payments and reverse each payment entry linked to this invoice.';
    }

    foreach ($lockColumns as $column) {
        if (!array_key_exists($column, $invoice)) {
            continue;
        }

        $value = $invoice[$column];
        $isLocked = false;
        if (is_numeric($value)) {
            $isLocked = ((int) $value) === 1;
        } elseif (is_string($value)) {
            $isLocked = trim($value) !== '';
        } elseif ($value !== null) {
            $isLocked = true;
        }

        if ($isLocked) {
            $blockers[] = 'Invoice has export/GST lock (' . $column . ').';
            $steps[] = 'Release export or GST filing lock before cancellation.';
            break;
        }
    }

    $jobId = (int) ($invoice['job_card_id'] ?? 0);
    $inventoryMovements = 0;
    $outsourcedLines = 0;

    if ($jobId > 0) {
        $inventoryStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM inventory_movements
             WHERE company_id = :company_id
               AND garage_id = :garage_id
               AND reference_type = "JOB_CARD"
               AND reference_id = :job_id
               AND movement_type = "OUT"'
        );
        $inventoryStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'job_id' => $jobId,
        ]);
        $inventoryMovements = (int) $inventoryStmt->fetchColumn();
        if ($inventoryMovements > 0) {
            $blockers[] = 'Job-linked inventory is already posted.';
            $steps[] = 'Reverse stock-out entries posted from the linked job card before cancelling invoice.';
        }

        if (table_columns('outsourced_works') !== []) {
            $outsourceStmt = $pdo->prepare(
                'SELECT COUNT(*) AS line_count,
                        COALESCE(SUM(COALESCE(pay.total_paid, 0)), 0) AS paid_total,
                        COALESCE(SUM(GREATEST(ow.agreed_cost - COALESCE(pay.total_paid, 0), 0)), 0) AS outstanding_total
                 FROM outsourced_works ow
                 LEFT JOIN (
                    SELECT outsourced_work_id, SUM(amount) AS total_paid
                    FROM outsourced_work_payments
                    GROUP BY outsourced_work_id
                 ) pay ON pay.outsourced_work_id = ow.id
                 WHERE ow.company_id = :company_id
                   AND ow.garage_id = :garage_id
                   AND ow.job_card_id = :job_id
                   AND ow.status_code = "ACTIVE"'
            );
            $outsourceStmt->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'job_id' => $jobId,
            ]);
            $outsource = $outsourceStmt->fetch() ?: [];
            $outsourcedLines = (int) ($outsource['line_count'] ?? 0);
            $paidTotal = round((float) ($outsource['paid_total'] ?? 0), 2);
            $outstandingTotal = round((float) ($outsource['outstanding_total'] ?? 0), 2);

            if ($outsourcedLines > 0 && ($paidTotal > 0.009 || $outstandingTotal > 0.009)) {
                $blockers[] = 'Linked outsourced work has financial entries.';
                $steps[] = 'Reverse outsourced vendor payments and archive linked outsourced work before invoice cancellation.';
            }
        }
    }

    return [
        'invoice' => $invoice,
        'can_cancel' => $blockers === [],
        'blockers' => $blockers,
        'steps' => array_values(array_unique($steps)),
        'payment_summary' => $paymentSummary,
        'inventory_movements' => $inventoryMovements,
        'outsourced_lines' => $outsourcedLines,
    ];
}

function reversal_job_delete_dependency_report(PDO $pdo, int $jobId, int $companyId, int $garageId): array
{
    $jobSql =
        'SELECT id, job_number, status, status_code
         FROM job_cards
         WHERE id = :job_id
           AND company_id = :company_id
           AND garage_id = :garage_id';
    if (in_array('status_code', table_columns('job_cards'), true)) {
        $jobSql .= ' AND status_code <> "DELETED"';
    }
    $jobSql .= '
         LIMIT 1
         FOR UPDATE';
    $jobStmt = $pdo->prepare($jobSql);
    $jobStmt->execute([
        'job_id' => $jobId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $job = $jobStmt->fetch() ?: null;

    if ($job === null) {
        return [
            'job' => null,
            'can_delete' => false,
            'blockers' => ['Job card not found for active scope.'],
            'steps' => [],
            'cancellable_outsourced_ids' => [],
            'outsourced_paid_total' => 0.0,
            'inventory_movements' => 0,
        ];
    }

    $blockers = [];
    $steps = [];

    $invoiceStmt = $pdo->prepare(
        'SELECT id, invoice_number, invoice_status
         FROM invoices
         WHERE job_card_id = :job_id
           AND company_id = :company_id
           AND garage_id = :garage_id
         LIMIT 1'
    );
    $invoiceStmt->execute([
        'job_id' => $jobId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $invoice = $invoiceStmt->fetch() ?: null;

    if ($invoice !== null && strtoupper((string) ($invoice['invoice_status'] ?? 'FINALIZED')) !== 'CANCELLED') {
        $blockers[] = 'Linked invoice exists and is not cancelled.';
        $paymentSummary = reversal_invoice_payment_summary($pdo, (int) $invoice['id']);
        if ((int) ($paymentSummary['unreversed_count'] ?? 0) > 0) {
            $steps[] = 'Reverse all payments for invoice ' . (string) ($invoice['invoice_number'] ?? ('#' . (int) $invoice['id'])) . '.';
        }
        $steps[] = 'Cancel linked invoice before deleting the job card.';
    }

    $inventoryStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM inventory_movements
         WHERE company_id = :company_id
           AND garage_id = :garage_id
           AND reference_type = "JOB_CARD"
           AND reference_id = :job_id
           AND movement_type = "OUT"'
    );
    $inventoryStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'job_id' => $jobId,
    ]);
    $inventoryMovements = (int) $inventoryStmt->fetchColumn();
    if ($inventoryMovements > 0) {
        $blockers[] = 'Inventory already posted for this job.';
        $steps[] = 'Reverse job stock postings before deleting the job card.';
    }

    $cancellableOutsourcedIds = [];
    $outsourcedPaidTotal = 0.0;
    if (table_columns('outsourced_works') !== []) {
        $outsourceStmt = $pdo->prepare(
            'SELECT ow.id,
                    COALESCE(pay.total_paid, 0) AS paid_total
             FROM outsourced_works ow
             LEFT JOIN (
                SELECT outsourced_work_id, SUM(amount) AS total_paid
                FROM outsourced_work_payments
                GROUP BY outsourced_work_id
             ) pay ON pay.outsourced_work_id = ow.id
             WHERE ow.company_id = :company_id
               AND ow.garage_id = :garage_id
               AND ow.job_card_id = :job_id
               AND ow.status_code = "ACTIVE"'
        );
        $outsourceStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'job_id' => $jobId,
        ]);
        $outsourcedRows = $outsourceStmt->fetchAll();

        foreach ($outsourcedRows as $row) {
            $paidTotal = round((float) ($row['paid_total'] ?? 0), 2);
            if ($paidTotal > 0.009) {
                $outsourcedPaidTotal += $paidTotal;
                continue;
            }
            $cancellableOutsourcedIds[] = (int) ($row['id'] ?? 0);
        }

        if ($outsourcedPaidTotal > 0.009) {
            $blockers[] = 'Outsourced payment records exist for this job.';
            $steps[] = 'Reverse outsourced payments first, then retry job deletion.';
        }
    }

    return [
        'job' => $job,
        'can_delete' => $blockers === [],
        'blockers' => $blockers,
        'steps' => array_values(array_unique($steps)),
        'cancellable_outsourced_ids' => array_values(array_filter($cancellableOutsourcedIds, static fn (int $id): bool => $id > 0)),
        'outsourced_paid_total' => round($outsourcedPaidTotal, 2),
        'inventory_movements' => $inventoryMovements,
    ];
}

function reversal_purchase_delete_dependency_report(PDO $pdo, int $purchaseId, int $companyId, int $garageId): array
{
    $purchaseSql =
        'SELECT id, invoice_number, purchase_status, assignment_status
         FROM purchases
         WHERE id = :purchase_id
           AND company_id = :company_id
           AND garage_id = :garage_id';
    if (in_array('status_code', table_columns('purchases'), true)) {
        $purchaseSql .= ' AND status_code <> "DELETED"';
    }
    $purchaseSql .= '
         LIMIT 1
         FOR UPDATE';
    $purchaseStmt = $pdo->prepare($purchaseSql);
    $purchaseStmt->execute([
        'purchase_id' => $purchaseId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $purchase = $purchaseStmt->fetch() ?: null;

    if ($purchase === null) {
        return [
            'purchase' => null,
            'can_delete' => false,
            'blockers' => ['Purchase not found for active scope.'],
            'steps' => [],
            'payment_summary' => [
                'unreversed_count' => 0,
                'unreversed_amount' => 0.0,
                'net_paid_amount' => 0.0,
                'supports_reversal_rows' => false,
            ],
        ];
    }

    $paymentSummary = reversal_purchase_payment_summary($pdo, (int) $purchase['id']);
    $blockers = [];
    $steps = [];

    if ((int) ($paymentSummary['unreversed_count'] ?? 0) > 0) {
        $blockers[] = 'Purchase has unreversed payment entries.';
        $steps[] = 'Open purchase payments and reverse each payment entry first.';
        $steps[] = 'After all payments are reversed, retry delete to auto-reverse stock.';
    }

    return [
        'purchase' => $purchase,
        'can_delete' => $blockers === [],
        'blockers' => $blockers,
        'steps' => $steps,
        'payment_summary' => $paymentSummary,
    ];
}
