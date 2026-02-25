<?php

declare(strict_types=1);

function billing_table_columns_uncached(PDO $pdo, string $tableName): array
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
        return [];
    }

    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . $tableName . '`');
        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $columns[] = $field;
            }
        }
        return $columns;
    } catch (Throwable $exception) {
        return [];
    }
}

function billing_add_column_if_missing(PDO $pdo, string $tableName, string $columnName, string $alterSql): bool
{
    $columns = billing_table_columns_uncached($pdo, $tableName);
    if ($columns === [] || in_array($columnName, $columns, true)) {
        return in_array($columnName, $columns, true);
    }

    try {
        $pdo->exec($alterSql);
    } catch (Throwable $exception) {
        // Another request may have altered the table already.
    }

    return in_array($columnName, billing_table_columns_uncached($pdo, $tableName), true);
}

function billing_table_has_index(PDO $pdo, string $tableName, string $indexName): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $indexName)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SHOW INDEX FROM `' . $tableName . '` WHERE Key_name = :key_name');
        $stmt->execute(['key_name' => $indexName]);
        return (bool) $stmt->fetch();
    } catch (Throwable $exception) {
        return false;
    }
}

function billing_financial_extensions_ready(bool $refresh = false): bool
{
    static $cached = null;

    if (!$refresh && $cached !== null) {
        return $cached;
    }

    $pdo = db();
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS advance_number_sequences (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                garage_id INT UNSIGNED NOT NULL,
                financial_year_id INT UNSIGNED NULL,
                financial_year_label VARCHAR(20) NOT NULL,
                prefix VARCHAR(20) NOT NULL DEFAULT "ADV",
                current_number INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_adv_seq_scope (company_id, garage_id, financial_year_label),
                KEY idx_adv_seq_company_garage (company_id, garage_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS job_advances (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                garage_id INT UNSIGNED NOT NULL,
                job_card_id INT UNSIGNED NOT NULL,
                customer_id INT UNSIGNED NULL,
                receipt_number VARCHAR(40) NOT NULL,
                receipt_sequence_number INT UNSIGNED NOT NULL,
                receipt_financial_year_label VARCHAR(20) NOT NULL,
                received_on DATE NOT NULL,
                payment_mode ENUM("CASH","UPI","CARD","BANK_TRANSFER","CHEQUE","MIXED") NOT NULL DEFAULT "CASH",
                reference_no VARCHAR(100) NULL,
                notes VARCHAR(255) NULL,
                advance_amount DECIMAL(12,2) NOT NULL,
                adjusted_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                balance_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                status_code ENUM("ACTIVE","INACTIVE","DELETED") NOT NULL DEFAULT "ACTIVE",
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_job_advance_receipt_scope (company_id, garage_id, receipt_number),
                KEY idx_job_advance_scope (company_id, garage_id, job_card_id, status_code),
                KEY idx_job_advance_customer (company_id, customer_id, status_code),
                KEY idx_job_advance_date (received_on)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS advance_adjustments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                garage_id INT UNSIGNED NOT NULL,
                advance_id BIGINT UNSIGNED NOT NULL,
                invoice_id INT UNSIGNED NOT NULL,
                job_card_id INT UNSIGNED NOT NULL,
                adjusted_amount DECIMAL(12,2) NOT NULL,
                adjusted_on DATE NOT NULL,
                notes VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_invoice_advance (invoice_id, advance_id),
                KEY idx_advance_adj_invoice (company_id, garage_id, invoice_id),
                KEY idx_advance_adj_job (company_id, garage_id, job_card_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS payment_receipt_sequences (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                garage_id INT UNSIGNED NOT NULL,
                financial_year_id INT UNSIGNED NULL,
                financial_year_label VARCHAR(20) NOT NULL,
                prefix VARCHAR(20) NOT NULL DEFAULT "RCP",
                current_number INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_payment_seq_scope (company_id, garage_id, financial_year_label),
                KEY idx_payment_seq_company_garage (company_id, garage_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS customer_ledger_entries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                garage_id INT UNSIGNED NOT NULL,
                customer_id INT UNSIGNED NOT NULL,
                entry_date DATE NOT NULL,
                entry_type ENUM("INVOICE","PAYMENT","ADJUSTMENT") NOT NULL,
                reference_type VARCHAR(40) NOT NULL,
                reference_id BIGINT UNSIGNED NOT NULL,
                debit_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                credit_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                balance_delta DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                notes VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_customer_ledger_ref (company_id, reference_type, reference_id, entry_type),
                KEY idx_customer_ledger_scope (company_id, garage_id, customer_id, entry_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        foreach ([
            'DROP TABLE IF EXISTS credit_note_items',
            'DROP TABLE IF EXISTS credit_notes',
            'DROP TABLE IF EXISTS credit_note_number_sequences',
        ] as $dropSql) {
            try {
                $pdo->exec($dropSql);
            } catch (Throwable $exception) {
                // Ignore cleanup races and permission edge-cases.
            }
        }
        try {
            $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'credit_note_prefix'");
        } catch (Throwable $exception) {
            // Ignore missing settings table / scope edge-cases.
        }
        try {
            $pdo->exec('UPDATE customer_ledger_entries SET entry_type = "ADJUSTMENT" WHERE entry_type = "CREDIT_NOTE"');
        } catch (Throwable $exception) {
            // Ignore legacy row migration errors.
        }
        try {
            $pdo->exec('ALTER TABLE customer_ledger_entries MODIFY COLUMN entry_type ENUM("INVOICE","PAYMENT","ADJUSTMENT") NOT NULL');
        } catch (Throwable $exception) {
            // Ignore incompatible existing values / concurrent DDL.
        }

        billing_add_column_if_missing(
            $pdo,
            'payments',
            'receipt_number',
            'ALTER TABLE payments ADD COLUMN receipt_number VARCHAR(40) NULL AFTER reference_no'
        );
        billing_add_column_if_missing(
            $pdo,
            'payments',
            'receipt_sequence_number',
            'ALTER TABLE payments ADD COLUMN receipt_sequence_number INT UNSIGNED NULL AFTER receipt_number'
        );
        billing_add_column_if_missing(
            $pdo,
            'payments',
            'receipt_financial_year_label',
            'ALTER TABLE payments ADD COLUMN receipt_financial_year_label VARCHAR(20) NULL AFTER receipt_sequence_number'
        );
        billing_add_column_if_missing(
            $pdo,
            'payments',
            'outstanding_before',
            'ALTER TABLE payments ADD COLUMN outstanding_before DECIMAL(12,2) NULL AFTER notes'
        );
        billing_add_column_if_missing(
            $pdo,
            'payments',
            'outstanding_after',
            'ALTER TABLE payments ADD COLUMN outstanding_after DECIMAL(12,2) NULL AFTER outstanding_before'
        );

        if (!billing_table_has_index($pdo, 'payments', 'idx_payments_receipt_number')) {
            try {
                $pdo->exec('ALTER TABLE payments ADD INDEX idx_payments_receipt_number (receipt_number)');
            } catch (Throwable $exception) {
                // Ignore duplicate index races.
            }
        }
        if (!billing_table_has_index($pdo, 'job_advances', 'idx_job_advance_balance')) {
            try {
                $pdo->exec('ALTER TABLE job_advances ADD INDEX idx_job_advance_balance (company_id, garage_id, job_card_id, balance_amount)');
            } catch (Throwable $exception) {
                // Ignore duplicate index races.
            }
        }

        $cached = true;
    } catch (Throwable $exception) {
        $cached = false;
    }

    return $cached;
}

function billing_financial_year_label_for_date(string $date): string
{
    return billing_derive_financial_year_label($date);
}

function billing_sequence_prefix(string $rawPrefix, string $fallbackPrefix): string
{
    $prefix = strtoupper(trim($rawPrefix));
    $prefix = preg_replace('/[^A-Z0-9-]+/', '', $prefix) ?? '';
    if ($prefix === '') {
        $prefix = $fallbackPrefix;
    }
    return mb_substr($prefix, 0, 20);
}

function billing_generate_scoped_sequence_number(
    PDO $pdo,
    string $sequenceTable,
    int $companyId,
    int $garageId,
    string $receiptDate,
    string $settingKey,
    string $fallbackPrefix
): array {
    if (!billing_financial_extensions_ready()) {
        throw new RuntimeException('Billing financial extensions are not ready.');
    }

    $financialYear = billing_resolve_financial_year($pdo, $companyId, $receiptDate);
    $fyLabel = trim((string) ($financialYear['label'] ?? ''));
    if ($fyLabel === '') {
        $fyLabel = billing_financial_year_label_for_date($receiptDate);
    }
    $fyLabel = mb_substr($fyLabel, 0, 20);
    $prefixRaw = billing_get_setting_value($pdo, $companyId, $garageId, $settingKey, $fallbackPrefix);
    $prefix = billing_sequence_prefix((string) $prefixRaw, $fallbackPrefix);

    $selectStmt = $pdo->prepare(
        'SELECT id, prefix, current_number
         FROM ' . $sequenceTable . '
         WHERE company_id = :company_id
           AND garage_id = :garage_id
           AND financial_year_label = :financial_year_label
         FOR UPDATE'
    );
    $selectStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'financial_year_label' => $fyLabel,
    ]);
    $sequence = $selectStmt->fetch();

    if (!$sequence) {
        $insertStmt = $pdo->prepare(
            'INSERT INTO ' . $sequenceTable . '
              (company_id, garage_id, financial_year_id, financial_year_label, prefix, current_number)
             VALUES
              (:company_id, :garage_id, :financial_year_id, :financial_year_label, :prefix, 0)'
        );
        try {
            $insertStmt->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'financial_year_id' => $financialYear['id'],
                'financial_year_label' => $fyLabel,
                'prefix' => $prefix,
            ]);
        } catch (Throwable $exception) {
            // Another transaction may have inserted this row.
        }

        $selectStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'financial_year_label' => $fyLabel,
        ]);
        $sequence = $selectStmt->fetch();
    }

    if (!$sequence) {
        throw new RuntimeException('Unable to reserve sequence number.');
    }

    $nextNumber = ((int) ($sequence['current_number'] ?? 0)) + 1;
    $updateStmt = $pdo->prepare(
        'UPDATE ' . $sequenceTable . '
         SET current_number = :current_number,
             prefix = :prefix,
             financial_year_id = :financial_year_id
         WHERE id = :id'
    );
    $updateStmt->execute([
        'current_number' => $nextNumber,
        'prefix' => $prefix,
        'financial_year_id' => $financialYear['id'],
        'id' => (int) ($sequence['id'] ?? 0),
    ]);

    return [
        'number' => sprintf('%s/%s/%05d', $prefix, $fyLabel, $nextNumber),
        'sequence_number' => $nextNumber,
        'financial_year_id' => $financialYear['id'],
        'financial_year_label' => $fyLabel,
        'prefix' => $prefix,
    ];
}

function billing_generate_advance_receipt_number(PDO $pdo, int $companyId, int $garageId, string $receiptDate): array
{
    return billing_generate_scoped_sequence_number(
        $pdo,
        'advance_number_sequences',
        $companyId,
        $garageId,
        $receiptDate,
        'advance_receipt_prefix',
        'ADV'
    );
}

function billing_generate_payment_receipt_number(PDO $pdo, int $companyId, int $garageId, string $receiptDate): array
{
    return billing_generate_scoped_sequence_number(
        $pdo,
        'payment_receipt_sequences',
        $companyId,
        $garageId,
        $receiptDate,
        'payment_receipt_prefix',
        'RCP'
    );
}

function billing_job_advance_summary(PDO $pdo, int $jobCardId, int $companyId, int $garageId): array
{
    if (!billing_financial_extensions_ready()) {
        return [
            'advance_amount' => 0.0,
            'adjusted_amount' => 0.0,
            'balance_amount' => 0.0,
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(advance_amount), 0) AS advance_amount,
                COALESCE(SUM(adjusted_amount), 0) AS adjusted_amount,
                COALESCE(SUM(balance_amount), 0) AS balance_amount
         FROM job_advances
         WHERE company_id = :company_id
           AND garage_id = :garage_id
           AND job_card_id = :job_card_id
           AND status_code = "ACTIVE"'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'job_card_id' => $jobCardId,
    ]);

    $row = $stmt->fetch() ?: [];
    return [
        'advance_amount' => billing_round((float) ($row['advance_amount'] ?? 0)),
        'adjusted_amount' => billing_round((float) ($row['adjusted_amount'] ?? 0)),
        'balance_amount' => billing_round((float) ($row['balance_amount'] ?? 0)),
    ];
}

function billing_invoice_advance_adjusted_total(PDO $pdo, int $invoiceId): float
{
    if (!billing_financial_extensions_ready()) {
        return 0.0;
    }

    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(adjusted_amount), 0)
         FROM advance_adjustments
         WHERE invoice_id = :invoice_id'
    );
    $stmt->execute(['invoice_id' => $invoiceId]);
    return billing_round((float) $stmt->fetchColumn());
}

function billing_invoice_advance_adjustment_history(PDO $pdo, int $invoiceId): array
{
    if (!billing_financial_extensions_ready()) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT aa.id, aa.adjusted_amount, aa.adjusted_on, aa.notes, aa.created_at,
                ja.id AS advance_id, ja.receipt_number, ja.received_on, ja.payment_mode,
                u.name AS created_by_name
         FROM advance_adjustments aa
         INNER JOIN job_advances ja ON ja.id = aa.advance_id
         LEFT JOIN users u ON u.id = aa.created_by
         WHERE aa.invoice_id = :invoice_id
         ORDER BY aa.id ASC'
    );
    $stmt->execute(['invoice_id' => $invoiceId]);
    return $stmt->fetchAll();
}

function billing_invoice_net_paid(PDO $pdo, int $invoiceId): float
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0)
         FROM payments
         WHERE invoice_id = :invoice_id'
    );
    $stmt->execute(['invoice_id' => $invoiceId]);
    return billing_round((float) $stmt->fetchColumn());
}

function billing_invoice_outstanding(PDO $pdo, int $invoiceId, float $grandTotal): float
{
    $paid = billing_invoice_net_paid($pdo, $invoiceId);
    $advanceAdjusted = billing_invoice_advance_adjusted_total($pdo, $invoiceId);
    return max(0.0, billing_round($grandTotal - $paid - $advanceAdjusted));
}

function billing_auto_adjust_advances_for_invoice(
    PDO $pdo,
    int $invoiceId,
    int $jobCardId,
    int $companyId,
    int $garageId,
    float $invoiceGrandTotal,
    ?int $createdBy = null,
    ?string $adjustDate = null
): array {
    if (!billing_financial_extensions_ready()) {
        return ['adjusted_total' => 0.0, 'rows' => []];
    }

    if ($invoiceId <= 0 || $jobCardId <= 0 || $invoiceGrandTotal <= 0) {
        return ['adjusted_total' => 0.0, 'rows' => []];
    }

    $alreadyAdjusted = billing_invoice_advance_adjusted_total($pdo, $invoiceId);
    if ($alreadyAdjusted > 0.009) {
        return [
            'adjusted_total' => $alreadyAdjusted,
            'rows' => billing_invoice_advance_adjustment_history($pdo, $invoiceId),
        ];
    }

    $adjustDate = $adjustDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $adjustDate)
        ? $adjustDate
        : date('Y-m-d');
    $remaining = billing_round($invoiceGrandTotal);
    $adjustedRows = [];

    $advanceStmt = $pdo->prepare(
        'SELECT id, receipt_number, balance_amount
         FROM job_advances
         WHERE company_id = :company_id
           AND garage_id = :garage_id
           AND job_card_id = :job_card_id
           AND status_code = "ACTIVE"
           AND balance_amount > 0
         ORDER BY received_on ASC, id ASC
         FOR UPDATE'
    );
    $advanceStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'job_card_id' => $jobCardId,
    ]);
    $advances = $advanceStmt->fetchAll();

    if ($advances === []) {
        return ['adjusted_total' => 0.0, 'rows' => []];
    }

    $advanceUpdateStmt = $pdo->prepare(
        'UPDATE job_advances
         SET adjusted_amount = adjusted_amount + :adjusted_amount,
             balance_amount = balance_amount - :adjusted_amount,
             updated_by = :updated_by
         WHERE id = :id'
    );
    $adjustmentInsertStmt = $pdo->prepare(
        'INSERT INTO advance_adjustments
          (company_id, garage_id, advance_id, invoice_id, job_card_id, adjusted_amount, adjusted_on, notes, created_by)
         VALUES
          (:company_id, :garage_id, :advance_id, :invoice_id, :job_card_id, :adjusted_amount, :adjusted_on, :notes, :created_by)'
    );

    foreach ($advances as $advance) {
        if ($remaining <= 0.009) {
            break;
        }

        $advanceId = (int) ($advance['id'] ?? 0);
        $balanceAmount = billing_round((float) ($advance['balance_amount'] ?? 0));
        if ($advanceId <= 0 || $balanceAmount <= 0.009) {
            continue;
        }

        $allocate = min($remaining, $balanceAmount);
        if ($allocate <= 0.009) {
            continue;
        }
        $allocate = billing_round($allocate);

        $advanceUpdateStmt->execute([
            'adjusted_amount' => $allocate,
            'updated_by' => $createdBy,
            'id' => $advanceId,
        ]);
        try {
            $adjustmentInsertStmt->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'advance_id' => $advanceId,
                'invoice_id' => $invoiceId,
                'job_card_id' => $jobCardId,
                'adjusted_amount' => $allocate,
                'adjusted_on' => $adjustDate,
                'notes' => 'Auto-adjusted against invoice #' . $invoiceId,
                'created_by' => $createdBy,
            ]);
        } catch (Throwable $exception) {
            // Unique(invoice_id, advance_id) makes this idempotent on retries.
            continue;
        }

        $remaining = billing_round($remaining - $allocate);
        $adjustedRows[] = [
            'advance_id' => $advanceId,
            'receipt_number' => (string) ($advance['receipt_number'] ?? ''),
            'adjusted_amount' => $allocate,
        ];
    }

    return [
        'adjusted_total' => billing_round($invoiceGrandTotal - $remaining),
        'rows' => $adjustedRows,
    ];
}

function billing_collect_job_advance(
    PDO $pdo,
    int $companyId,
    int $garageId,
    int $jobCardId,
    float $amount,
    string $receivedOn,
    string $paymentMode,
    ?string $referenceNo = null,
    ?string $notes = null,
    ?int $createdBy = null
): array {
    if (!billing_financial_extensions_ready()) {
        throw new RuntimeException('Advance collection feature is not ready.');
    }

    if ($jobCardId <= 0 || $amount <= 0) {
        throw new RuntimeException('Valid job card and advance amount are required.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedOn)) {
        throw new RuntimeException('Valid received date is required.');
    }

    $jobStmt = $pdo->prepare(
        'SELECT jc.id, jc.customer_id, jc.job_number, jc.status
         FROM job_cards jc
         WHERE jc.id = :job_card_id
           AND jc.company_id = :company_id
           AND jc.garage_id = :garage_id
           AND jc.status_code <> "DELETED"
         LIMIT 1
         FOR UPDATE'
    );
    $jobStmt->execute([
        'job_card_id' => $jobCardId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $job = $jobStmt->fetch();
    if (!$job) {
        throw new RuntimeException('Job card not found for advance collection.');
    }

    $draftInvoiceStmt = $pdo->prepare(
        'SELECT i.id
         FROM invoices i
         WHERE i.job_card_id = :job_card_id
           AND i.company_id = :company_id
           AND i.garage_id = :garage_id
           AND i.invoice_status IN ("DRAFT", "FINALIZED")
         LIMIT 1
         FOR UPDATE'
    );
    $draftInvoiceStmt->execute([
        'job_card_id' => $jobCardId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $hasDraftOrFinalizedInvoice = (bool) $draftInvoiceStmt->fetch();
    if (strtoupper(trim((string) ($job['status'] ?? ''))) === 'CLOSED' && $hasDraftOrFinalizedInvoice) {
        throw new RuntimeException('Advance collection is blocked for CLOSED job cards with DRAFT or FINALIZED invoices.');
    }

    $receiptMeta = billing_generate_advance_receipt_number($pdo, $companyId, $garageId, $receivedOn);
    $normalizedMode = billing_normalize_payment_mode($paymentMode);

    $insertStmt = $pdo->prepare(
        'INSERT INTO job_advances
          (company_id, garage_id, job_card_id, customer_id, receipt_number, receipt_sequence_number, receipt_financial_year_label,
           received_on, payment_mode, reference_no, notes, advance_amount, adjusted_amount, balance_amount, status_code, created_by, updated_by)
         VALUES
          (:company_id, :garage_id, :job_card_id, :customer_id, :receipt_number, :receipt_sequence_number, :receipt_financial_year_label,
           :received_on, :payment_mode, :reference_no, :notes, :advance_amount, 0, :balance_amount, "ACTIVE", :created_by, :updated_by)'
    );
    $insertStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'job_card_id' => $jobCardId,
        'customer_id' => (int) ($job['customer_id'] ?? 0) > 0 ? (int) $job['customer_id'] : null,
        'receipt_number' => (string) $receiptMeta['number'],
        'receipt_sequence_number' => (int) $receiptMeta['sequence_number'],
        'receipt_financial_year_label' => (string) $receiptMeta['financial_year_label'],
        'received_on' => $receivedOn,
        'payment_mode' => $normalizedMode,
        'reference_no' => $referenceNo !== null && trim($referenceNo) !== '' ? mb_substr(trim($referenceNo), 0, 100) : null,
        'notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 255) : null,
        'advance_amount' => billing_round($amount),
        'balance_amount' => billing_round($amount),
        'created_by' => $createdBy,
        'updated_by' => $createdBy,
    ]);

    return [
        'advance_id' => (int) $pdo->lastInsertId(),
        'job_number' => (string) ($job['job_number'] ?? ''),
        'receipt_number' => (string) $receiptMeta['number'],
        'sequence_number' => (int) $receiptMeta['sequence_number'],
        'financial_year_label' => (string) $receiptMeta['financial_year_label'],
    ];
}

function billing_record_customer_ledger_entry(
    PDO $pdo,
    int $companyId,
    int $garageId,
    int $customerId,
    string $entryDate,
    string $entryType,
    string $referenceType,
    int $referenceId,
    float $debitAmount,
    float $creditAmount,
    ?int $createdBy = null,
    ?string $notes = null
): void {
    if (!billing_financial_extensions_ready()) {
        return;
    }
    if ($companyId <= 0 || $garageId <= 0 || $customerId <= 0 || $referenceId <= 0) {
        return;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
        $entryDate = date('Y-m-d');
    }

    $entryType = strtoupper(trim($entryType));
    if (!in_array($entryType, ['INVOICE', 'PAYMENT', 'ADJUSTMENT'], true)) {
        $entryType = 'ADJUSTMENT';
    }

    $debitAmount = billing_round(max(0.0, $debitAmount));
    $creditAmount = billing_round(max(0.0, $creditAmount));
    $balanceDelta = billing_round($debitAmount - $creditAmount);

    $stmt = $pdo->prepare(
        'INSERT INTO customer_ledger_entries
          (company_id, garage_id, customer_id, entry_date, entry_type, reference_type, reference_id, debit_amount, credit_amount, balance_delta, notes, created_by)
         VALUES
          (:company_id, :garage_id, :customer_id, :entry_date, :entry_type, :reference_type, :reference_id, :debit_amount, :credit_amount, :balance_delta, :notes, :created_by)
         ON DUPLICATE KEY UPDATE
           debit_amount = VALUES(debit_amount),
           credit_amount = VALUES(credit_amount),
           balance_delta = VALUES(balance_delta),
           notes = VALUES(notes),
           created_by = VALUES(created_by)'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'customer_id' => $customerId,
        'entry_date' => $entryDate,
        'entry_type' => $entryType,
        'reference_type' => mb_substr(strtoupper(trim($referenceType)), 0, 40),
        'reference_id' => $referenceId,
        'debit_amount' => $debitAmount,
        'credit_amount' => $creditAmount,
        'balance_delta' => $balanceDelta,
        'notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 255) : null,
        'created_by' => $createdBy,
    ]);
}

function billing_fetch_payment_receipt_row(PDO $pdo, int $paymentId, int $companyId, int $garageId): ?array
{
    if ($paymentId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT p.*, i.invoice_number, i.grand_total, i.customer_id, c.full_name AS customer_name, jc.job_number
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         LEFT JOIN customers c ON c.id = i.customer_id
         LEFT JOIN job_cards jc ON jc.id = i.job_card_id
         WHERE p.id = :payment_id
           AND i.company_id = :company_id
           AND i.garage_id = :garage_id
         LIMIT 1'
    );
    $stmt->execute([
        'payment_id' => $paymentId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function billing_fetch_advance_receipt_row(PDO $pdo, int $advanceId, int $companyId, int $garageId): ?array
{
    if (!billing_financial_extensions_ready() || $advanceId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT ja.*, jc.job_number, c.full_name AS customer_name, v.registration_no
         FROM job_advances ja
         LEFT JOIN job_cards jc ON jc.id = ja.job_card_id
         LEFT JOIN customers c ON c.id = ja.customer_id
         LEFT JOIN vehicles v ON v.id = jc.vehicle_id
         WHERE ja.id = :advance_id
           AND ja.company_id = :company_id
           AND ja.garage_id = :garage_id
         LIMIT 1'
    );
    $stmt->execute([
        'advance_id' => $advanceId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}
