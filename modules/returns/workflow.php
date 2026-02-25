<?php

declare(strict_types=1);

require_once __DIR__ . '/../billing/workflow.php';

function returns_table_columns_uncached(PDO $pdo, string $tableName): array
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

function returns_table_has_index(PDO $pdo, string $tableName, string $indexName): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $indexName)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SHOW INDEX FROM `' . $tableName . '` WHERE Key_name = :index_name');
        $stmt->execute(['index_name' => $indexName]);
        return (bool) $stmt->fetch();
    } catch (Throwable $exception) {
        return false;
    }
}

function returns_add_column_if_missing(PDO $pdo, string $tableName, string $columnName, string $alterSql): bool
{
    $columns = returns_table_columns_uncached($pdo, $tableName);
    if ($columns === [] || in_array($columnName, $columns, true)) {
        return in_array($columnName, $columns, true);
    }

    try {
        $pdo->exec($alterSql);
    } catch (Throwable $exception) {
        // Ignore racing ALTER requests.
    }

    return in_array($columnName, returns_table_columns_uncached($pdo, $tableName), true);
}

function returns_module_ready(bool $refresh = false): bool
{
    static $cached = null;

    if (!$refresh && $cached !== null) {
        return $cached;
    }

    $pdo = db();
    try {
        // Ensure billing extensions are available for credit-note linkage.
        billing_financial_extensions_ready();

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS returns_number_sequences (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                garage_id INT UNSIGNED NOT NULL,
                financial_year_id INT UNSIGNED NULL,
                financial_year_label VARCHAR(20) NOT NULL,
                prefix VARCHAR(20) NOT NULL DEFAULT "RET",
                current_number INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_returns_seq_scope (company_id, garage_id, financial_year_label)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS returns_rma (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                garage_id INT UNSIGNED NOT NULL,
                return_number VARCHAR(40) NOT NULL,
                return_sequence_number INT UNSIGNED NOT NULL,
                financial_year_label VARCHAR(20) NOT NULL,
                return_type ENUM("CUSTOMER_RETURN","VENDOR_RETURN") NOT NULL,
                return_date DATE NOT NULL,
                job_card_id INT UNSIGNED NULL,
                invoice_id INT UNSIGNED NULL,
                purchase_id INT UNSIGNED NULL,
                customer_id INT UNSIGNED NULL,
                vendor_id INT UNSIGNED NULL,
                reason_text VARCHAR(255) NULL,
                reason_detail TEXT NULL,
                approval_status ENUM("PENDING","APPROVED","REJECTED","CLOSED") NOT NULL DEFAULT "PENDING",
                approved_by INT UNSIGNED NULL,
                approved_at DATETIME NULL,
                rejected_reason VARCHAR(255) NULL,
                vendor_credit_note_number VARCHAR(80) NULL,
                credit_note_id BIGINT UNSIGNED NULL,
                taxable_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                notes VARCHAR(255) NULL,
                status_code ENUM("ACTIVE","DELETED") NOT NULL DEFAULT "ACTIVE",
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_returns_number_scope (company_id, garage_id, return_number),
                KEY idx_returns_scope (company_id, garage_id, return_type, approval_status, status_code),
                KEY idx_returns_invoice (company_id, invoice_id),
                KEY idx_returns_purchase (company_id, purchase_id),
                KEY idx_returns_date (return_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS return_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                return_id BIGINT UNSIGNED NOT NULL,
                source_item_id INT UNSIGNED NULL,
                part_id INT UNSIGNED NULL,
                description VARCHAR(255) NOT NULL,
                quantity DECIMAL(12,2) NOT NULL,
                unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                taxable_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_return_items_return (return_id),
                KEY idx_return_items_source (source_item_id),
                KEY idx_return_items_part (part_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS return_attachments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                garage_id INT UNSIGNED NOT NULL,
                return_id BIGINT UNSIGNED NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                file_size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
                uploaded_by INT UNSIGNED NULL,
                status_code ENUM("ACTIVE","DELETED") NOT NULL DEFAULT "ACTIVE",
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                KEY idx_return_attachment_scope (company_id, garage_id, return_id, status_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS stock_reversal_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                garage_id INT UNSIGNED NOT NULL,
                reversal_context_type VARCHAR(40) NOT NULL,
                reversal_context_id BIGINT UNSIGNED NOT NULL,
                part_id INT UNSIGNED NOT NULL,
                movement_type ENUM("IN","OUT") NOT NULL,
                quantity DECIMAL(12,2) NOT NULL,
                original_movement_uid VARCHAR(120) NULL,
                reversal_movement_uid VARCHAR(120) NOT NULL,
                audit_reference VARCHAR(120) NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_stock_reversal_context (company_id, garage_id, reversal_context_type, reversal_context_id, part_id, movement_type),
                UNIQUE KEY uniq_stock_reversal_uid (reversal_movement_uid),
                KEY idx_stock_reversal_part (company_id, garage_id, part_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        // Backward-safe columns if table existed from earlier experiments.
        returns_add_column_if_missing(
            $pdo,
            'returns_rma',
            'vendor_credit_note_number',
            'ALTER TABLE returns_rma ADD COLUMN vendor_credit_note_number VARCHAR(80) NULL AFTER rejected_reason'
        );
        returns_add_column_if_missing(
            $pdo,
            'returns_rma',
            'credit_note_id',
            'ALTER TABLE returns_rma ADD COLUMN credit_note_id BIGINT UNSIGNED NULL AFTER vendor_credit_note_number'
        );

        if (!returns_table_has_index($pdo, 'returns_rma', 'idx_returns_customer')) {
            try {
                $pdo->exec('ALTER TABLE returns_rma ADD INDEX idx_returns_customer (company_id, customer_id, approval_status)');
            } catch (Throwable $exception) {
                // Ignore duplicate index race.
            }
        }

        if (!returns_table_has_index($pdo, 'returns_rma', 'idx_returns_vendor')) {
            try {
                $pdo->exec('ALTER TABLE returns_rma ADD INDEX idx_returns_vendor (company_id, vendor_id, approval_status)');
            } catch (Throwable $exception) {
                // Ignore duplicate index race.
            }
        }

        $cached = true;
    } catch (Throwable $exception) {
        $cached = false;
    }

    return $cached;
}

function returns_allowed_types(): array
{
    return [
        'CUSTOMER_RETURN' => 'Customer Return',
        'VENDOR_RETURN' => 'Vendor Return',
    ];
}

function returns_normalize_type(string $returnType): string
{
    $normalized = strtoupper(trim($returnType));
    return array_key_exists($normalized, returns_allowed_types()) ? $normalized : 'CUSTOMER_RETURN';
}

function returns_allowed_approval_statuses(): array
{
    return ['PENDING', 'APPROVED', 'REJECTED', 'CLOSED'];
}

function returns_normalize_approval_status(string $status): string
{
    $normalized = strtoupper(trim($status));
    return in_array($normalized, returns_allowed_approval_statuses(), true) ? $normalized : 'PENDING';
}

function returns_parse_date(?string $value): ?string
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

function returns_round(float $value): float
{
    return round($value, 2);
}

function returns_generate_number(PDO $pdo, int $companyId, int $garageId, string $returnDate): array
{
    if (!returns_module_ready()) {
        throw new RuntimeException('Returns module is not ready.');
    }

    return billing_generate_scoped_sequence_number(
        $pdo,
        'returns_number_sequences',
        $companyId,
        $garageId,
        $returnDate,
        'return_number_prefix',
        'RET'
    );
}

function returns_fetch_return_row(PDO $pdo, int $returnId, int $companyId, int $garageId, bool $forUpdate = false): ?array
{
    if ($returnId <= 0 || $companyId <= 0 || $garageId <= 0) {
        return null;
    }

    $sql =
        'SELECT *
         FROM returns_rma
         WHERE id = :id
           AND company_id = :company_id
           AND garage_id = :garage_id
           AND status_code = "ACTIVE"
         LIMIT 1';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'id' => $returnId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function returns_fetch_return_items(PDO $pdo, int $returnId): array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM return_items
         WHERE return_id = :return_id
         ORDER BY id ASC'
    );
    $stmt->execute(['return_id' => $returnId]);
    return $stmt->fetchAll();
}

function returns_fetch_source_items_for_document(
    PDO $pdo,
    int $companyId,
    int $garageId,
    string $returnType,
    int $sourceId
): array {
    $returnType = returns_normalize_type($returnType);
    if ($sourceId <= 0 || $companyId <= 0 || $garageId <= 0) {
        return [];
    }

    if ($returnType === 'CUSTOMER_RETURN') {
        $stmt = $pdo->prepare(
            'SELECT ii.id AS source_item_id,
                    ii.part_id,
                    ii.description,
                    ii.quantity,
                    ii.unit_price,
                    ii.gst_rate,
                    ii.taxable_value,
                    ii.tax_amount,
                    ii.total_value,
                    p.part_name,
                    p.part_sku,
                    p.unit AS part_unit,
                    COALESCE((
                        SELECT SUM(ri2.quantity)
                        FROM return_items ri2
                        INNER JOIN returns_rma rr2 ON rr2.id = ri2.return_id
                        WHERE rr2.company_id = :company_id
                          AND rr2.garage_id = :garage_id
                          AND rr2.return_type = "CUSTOMER_RETURN"
                          AND rr2.status_code = "ACTIVE"
                          AND rr2.approval_status IN ("PENDING", "APPROVED", "CLOSED")
                          AND ri2.source_item_id = ii.id
                    ), 0) AS reserved_qty
             FROM invoice_items ii
             INNER JOIN invoices i ON i.id = ii.invoice_id
             LEFT JOIN parts p ON p.id = ii.part_id
             WHERE i.id = :source_id
               AND i.company_id = :company_id
               AND i.garage_id = :garage_id
               AND i.invoice_status IN ("DRAFT", "FINALIZED")
             ORDER BY ii.id ASC'
        );
        $stmt->execute([
            'source_id' => $sourceId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT pi.id AS source_item_id,
                    pi.part_id,
                    p.part_name,
                    p.part_sku,
                    p.unit AS part_unit,
                    CONCAT(COALESCE(p.part_name, "Part"), " (", COALESCE(p.part_sku, "NA"), ")") AS description,
                    pi.quantity,
                    pi.unit_cost AS unit_price,
                    pi.gst_rate,
                    pi.taxable_amount,
                    pi.gst_amount AS tax_amount,
                    pi.total_amount AS total_value,
                    COALESCE((
                        SELECT SUM(ri2.quantity)
                        FROM return_items ri2
                        INNER JOIN returns_rma rr2 ON rr2.id = ri2.return_id
                        WHERE rr2.company_id = :company_id
                          AND rr2.garage_id = :garage_id
                          AND rr2.return_type = "VENDOR_RETURN"
                          AND rr2.status_code = "ACTIVE"
                          AND rr2.approval_status IN ("PENDING", "APPROVED", "CLOSED")
                          AND ri2.source_item_id = pi.id
                    ), 0) AS reserved_qty
             FROM purchase_items pi
             INNER JOIN purchases pur ON pur.id = pi.purchase_id
             LEFT JOIN parts p ON p.id = pi.part_id
             WHERE pur.id = :source_id
               AND pur.company_id = :company_id
               AND pur.garage_id = :garage_id
               AND (pur.status_code IS NULL OR pur.status_code <> "DELETED")
             ORDER BY pi.id ASC'
        );
        $stmt->execute([
            'source_id' => $sourceId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
    }

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $sourceQty = returns_round((float) ($row['quantity'] ?? 0));
        $reservedQty = returns_round((float) ($row['reserved_qty'] ?? 0));
        $maxReturnable = max(0.0, returns_round($sourceQty - $reservedQty));
        $row['source_qty'] = $sourceQty;
        $row['reserved_qty'] = $reservedQty;
        $row['max_returnable_qty'] = $maxReturnable;
        $rows[] = $row;
    }

    return $rows;
}

function returns_validate_source_document(PDO $pdo, int $companyId, int $garageId, string $returnType, int $sourceId): array
{
    $returnType = returns_normalize_type($returnType);
    if ($sourceId <= 0) {
        throw new RuntimeException('Source document is required.');
    }

    if ($returnType === 'CUSTOMER_RETURN') {
        $stmt = $pdo->prepare(
            'SELECT i.id, i.job_card_id, i.customer_id, i.invoice_number, i.invoice_status
             FROM invoices i
             WHERE i.id = :source_id
               AND i.company_id = :company_id
               AND i.garage_id = :garage_id
               AND i.invoice_status IN ("DRAFT", "FINALIZED")
             LIMIT 1'
        );
        $stmt->execute([
            'source_id' => $sourceId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Invoice not found for selected return scope.');
        }

        return [
            'job_card_id' => (int) ($row['job_card_id'] ?? 0),
            'invoice_id' => (int) ($row['id'] ?? 0),
            'purchase_id' => 0,
            'customer_id' => (int) ($row['customer_id'] ?? 0),
            'vendor_id' => 0,
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT p.id, p.vendor_id
         FROM purchases p
         WHERE p.id = :source_id
           AND p.company_id = :company_id
           AND p.garage_id = :garage_id
           AND (p.status_code IS NULL OR p.status_code <> "DELETED")
         LIMIT 1'
    );
    $stmt->execute([
        'source_id' => $sourceId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Purchase not found for selected return scope.');
    }

    return [
        'job_card_id' => 0,
        'invoice_id' => 0,
        'purchase_id' => (int) ($row['id'] ?? 0),
        'customer_id' => 0,
        'vendor_id' => (int) ($row['vendor_id'] ?? 0),
    ];
}

function returns_create_rma(
    PDO $pdo,
    int $companyId,
    int $garageId,
    int $actorUserId,
    string $returnType,
    int $sourceId,
    string $returnDate,
    string $reasonText,
    string $reasonDetail,
    string $notes,
    string $vendorCreditNoteNumber,
    array $lineInputs
): array {
    if (!returns_module_ready()) {
        throw new RuntimeException('Returns module is not ready.');
    }

    $returnType = returns_normalize_type($returnType);
    $returnDate = returns_parse_date($returnDate) ?? date('Y-m-d');

    if ($companyId <= 0 || $garageId <= 0) {
        throw new RuntimeException('Active company and garage are required.');
    }

    if ($lineInputs === []) {
        throw new RuntimeException('At least one return line is required.');
    }

    $pdo->beginTransaction();
    try {
        $sourceDoc = returns_validate_source_document($pdo, $companyId, $garageId, $returnType, $sourceId);
        $sourceItems = returns_fetch_source_items_for_document($pdo, $companyId, $garageId, $returnType, $sourceId);
        if ($sourceItems === []) {
            throw new RuntimeException('No eligible line items found in selected source document.');
        }

        $sourceLookup = [];
        foreach ($sourceItems as $sourceItem) {
            $sourceLookup[(int) ($sourceItem['source_item_id'] ?? 0)] = $sourceItem;
        }

        $preparedLines = [];
        $taxableTotal = 0.0;
        $taxTotal = 0.0;
        $grandTotal = 0.0;

        foreach ($lineInputs as $lineInput) {
            $sourceItemId = (int) ($lineInput['source_item_id'] ?? 0);
            $quantity = returns_round((float) ($lineInput['quantity'] ?? 0));
            if ($sourceItemId <= 0 || $quantity <= 0.009) {
                continue;
            }

            $source = $sourceLookup[$sourceItemId] ?? null;
            if (!is_array($source)) {
                throw new RuntimeException('Invalid source line selected.');
            }

            $maxQty = returns_round((float) ($source['max_returnable_qty'] ?? 0));
            if ($maxQty <= 0.009) {
                throw new RuntimeException('Selected source line already fully returned.');
            }
            if ($quantity > $maxQty + 0.009) {
                throw new RuntimeException(
                    'Return quantity exceeds available quantity for source item #' . $sourceItemId
                    . ' (max ' . number_format($maxQty, 2) . ').'
                );
            }

            $unitPrice = returns_round((float) ($source['unit_price'] ?? 0));
            $gstRate = returns_round((float) ($source['gst_rate'] ?? 0));
            if ($unitPrice < 0) {
                $unitPrice = 0;
            }
            if ($gstRate < 0) {
                $gstRate = 0;
            }

            $taxableAmount = returns_round($quantity * $unitPrice);
            $taxAmount = returns_round($taxableAmount * $gstRate / 100);
            $lineTotal = returns_round($taxableAmount + $taxAmount);

            $preparedLines[] = [
                'source_item_id' => $sourceItemId,
                'part_id' => (int) ($source['part_id'] ?? 0),
                'description' => (string) ($source['description'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'gst_rate' => $gstRate,
                'taxable_amount' => $taxableAmount,
                'tax_amount' => $taxAmount,
                'total_amount' => $lineTotal,
            ];

            $taxableTotal += $taxableAmount;
            $taxTotal += $taxAmount;
            $grandTotal += $lineTotal;
        }

        if ($preparedLines === []) {
            throw new RuntimeException('No valid return quantities were provided.');
        }

        $numberMeta = returns_generate_number($pdo, $companyId, $garageId, $returnDate);

        $insertReturnStmt = $pdo->prepare(
            'INSERT INTO returns_rma
              (company_id, garage_id, return_number, return_sequence_number, financial_year_label, return_type,
               return_date, job_card_id, invoice_id, purchase_id, customer_id, vendor_id,
               reason_text, reason_detail, approval_status, vendor_credit_note_number,
               taxable_amount, tax_amount, total_amount, notes, status_code, created_by, updated_by)
             VALUES
              (:company_id, :garage_id, :return_number, :return_sequence_number, :financial_year_label, :return_type,
               :return_date, :job_card_id, :invoice_id, :purchase_id, :customer_id, :vendor_id,
               :reason_text, :reason_detail, "PENDING", :vendor_credit_note_number,
               :taxable_amount, :tax_amount, :total_amount, :notes, "ACTIVE", :created_by, :updated_by)'
        );
        $insertReturnStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'return_number' => (string) ($numberMeta['number'] ?? ''),
            'return_sequence_number' => (int) ($numberMeta['sequence_number'] ?? 0),
            'financial_year_label' => (string) ($numberMeta['financial_year_label'] ?? ''),
            'return_type' => $returnType,
            'return_date' => $returnDate,
            'job_card_id' => (int) ($sourceDoc['job_card_id'] ?? 0) > 0 ? (int) $sourceDoc['job_card_id'] : null,
            'invoice_id' => (int) ($sourceDoc['invoice_id'] ?? 0) > 0 ? (int) $sourceDoc['invoice_id'] : null,
            'purchase_id' => (int) ($sourceDoc['purchase_id'] ?? 0) > 0 ? (int) $sourceDoc['purchase_id'] : null,
            'customer_id' => (int) ($sourceDoc['customer_id'] ?? 0) > 0 ? (int) $sourceDoc['customer_id'] : null,
            'vendor_id' => (int) ($sourceDoc['vendor_id'] ?? 0) > 0 ? (int) $sourceDoc['vendor_id'] : null,
            'reason_text' => $reasonText !== '' ? mb_substr($reasonText, 0, 255) : null,
            'reason_detail' => $reasonDetail !== '' ? $reasonDetail : null,
            'vendor_credit_note_number' => $vendorCreditNoteNumber !== '' ? mb_substr($vendorCreditNoteNumber, 0, 80) : null,
            'taxable_amount' => returns_round($taxableTotal),
            'tax_amount' => returns_round($taxTotal),
            'total_amount' => returns_round($grandTotal),
            'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null,
            'created_by' => $actorUserId > 0 ? $actorUserId : null,
            'updated_by' => $actorUserId > 0 ? $actorUserId : null,
        ]);

        $returnId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            'INSERT INTO return_items
              (return_id, source_item_id, part_id, description, quantity, unit_price, gst_rate, taxable_amount, tax_amount, total_amount)
             VALUES
              (:return_id, :source_item_id, :part_id, :description, :quantity, :unit_price, :gst_rate, :taxable_amount, :tax_amount, :total_amount)'
        );

        foreach ($preparedLines as $line) {
            $itemStmt->execute([
                'return_id' => $returnId,
                'source_item_id' => (int) ($line['source_item_id'] ?? 0) > 0 ? (int) $line['source_item_id'] : null,
                'part_id' => (int) ($line['part_id'] ?? 0) > 0 ? (int) $line['part_id'] : null,
                'description' => mb_substr((string) ($line['description'] ?? ''), 0, 255),
                'quantity' => returns_round((float) ($line['quantity'] ?? 0)),
                'unit_price' => returns_round((float) ($line['unit_price'] ?? 0)),
                'gst_rate' => returns_round((float) ($line['gst_rate'] ?? 0)),
                'taxable_amount' => returns_round((float) ($line['taxable_amount'] ?? 0)),
                'tax_amount' => returns_round((float) ($line['tax_amount'] ?? 0)),
                'total_amount' => returns_round((float) ($line['total_amount'] ?? 0)),
            ]);
        }

        $pdo->commit();

        return [
            'return_id' => $returnId,
            'return_number' => (string) ($numberMeta['number'] ?? ''),
            'return_type' => $returnType,
            'invoice_id' => (int) ($sourceDoc['invoice_id'] ?? 0),
            'purchase_id' => (int) ($sourceDoc['purchase_id'] ?? 0),
            'customer_id' => (int) ($sourceDoc['customer_id'] ?? 0),
            'vendor_id' => (int) ($sourceDoc['vendor_id'] ?? 0),
            'line_count' => count($preparedLines),
            'taxable_amount' => returns_round($taxableTotal),
            'tax_amount' => returns_round($taxTotal),
            'total_amount' => returns_round($grandTotal),
        ];
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function returns_upload_relative_dir(): string
{
    return 'assets/uploads/returns';
}

function returns_upload_allowed_mimes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
}

function returns_normalize_upload_path(?string $rawPath): ?string
{
    if ($rawPath === null) {
        return null;
    }

    $normalized = str_replace('\\', '/', trim($rawPath));
    $normalized = ltrim($normalized, '/');
    if ($normalized === '' || str_contains($normalized, '..')) {
        return null;
    }
    if (!str_starts_with($normalized, returns_upload_relative_dir() . '/')) {
        return null;
    }

    return $normalized;
}

function returns_upload_fs_path(?string $rawPath): ?string
{
    $relative = returns_normalize_upload_path($rawPath);
    if ($relative === null) {
        return null;
    }

    $fullPath = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    return is_file($fullPath) ? $fullPath : null;
}

function returns_upload_url(?string $rawPath): ?string
{
    $relative = returns_normalize_upload_path($rawPath);
    if ($relative === null) {
        return null;
    }

    return url($relative);
}

function returns_store_uploaded_attachment(array $file, int $companyId, int $garageId, int $returnId, int $maxBytes = 8388608): array
{
    if ($companyId <= 0 || $garageId <= 0 || $returnId <= 0) {
        return ['ok' => false, 'message' => 'Invalid return scope for attachment upload.'];
    }

    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        $message = match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Attachment exceeds upload size limit.',
            UPLOAD_ERR_NO_FILE => 'Select an attachment file.',
            default => 'Unable to process uploaded attachment.',
        };
        return ['ok' => false, 'message' => $message];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'message' => 'Uploaded attachment is not valid.'];
    }

    $fileSize = (int) ($file['size'] ?? 0);
    if ($fileSize <= 0 || $fileSize > $maxBytes) {
        return ['ok' => false, 'message' => 'Attachment size exceeds allowed limit.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmpPath) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowed = returns_upload_allowed_mimes();
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'message' => 'Only JPG, PNG, WEBP, or PDF files are allowed.'];
    }

    $relativeDir = returns_upload_relative_dir();
    $targetDir = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        return ['ok' => false, 'message' => 'Unable to prepare attachment upload directory.'];
    }

    $extension = $allowed[$mime];
    $targetName = sprintf('ret-%d-%d-%s.%s', $companyId, $returnId, bin2hex(random_bytes(10)), $extension);
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $targetName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return ['ok' => false, 'message' => 'Unable to store attachment file.'];
    }

    return [
        'ok' => true,
        'relative_path' => $relativeDir . '/' . $targetName,
        'mime_type' => $mime,
        'file_size_bytes' => $fileSize,
        'file_name' => (string) ($file['name'] ?? $targetName),
    ];
}

function returns_attach_file(
    PDO $pdo,
    int $companyId,
    int $garageId,
    int $returnId,
    string $fileName,
    string $relativePath,
    string $mimeType,
    int $fileSize,
    int $actorUserId
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO return_attachments
          (company_id, garage_id, return_id, file_name, file_path, mime_type, file_size_bytes, uploaded_by, status_code)
         VALUES
          (:company_id, :garage_id, :return_id, :file_name, :file_path, :mime_type, :file_size_bytes, :uploaded_by, "ACTIVE")'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'return_id' => $returnId,
        'file_name' => mb_substr($fileName, 0, 255),
        'file_path' => mb_substr($relativePath, 0, 255),
        'mime_type' => mb_substr($mimeType, 0, 100),
        'file_size_bytes' => max(0, $fileSize),
        'uploaded_by' => $actorUserId > 0 ? $actorUserId : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function returns_fetch_attachments(PDO $pdo, int $companyId, int $garageId, int $returnId): array
{
    if ($returnId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT ra.*, u.name AS uploaded_by_name
         FROM return_attachments ra
         LEFT JOIN users u ON u.id = ra.uploaded_by
         WHERE ra.company_id = :company_id
           AND ra.garage_id = :garage_id
           AND ra.return_id = :return_id
           AND ra.status_code = "ACTIVE"
         ORDER BY ra.id DESC'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'return_id' => $returnId,
    ]);

    return $stmt->fetchAll();
}

function returns_delete_attachment(PDO $pdo, int $attachmentId, int $companyId, int $garageId, int $actorUserId): bool
{
    if ($attachmentId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT id, file_path
         FROM return_attachments
         WHERE id = :id
           AND company_id = :company_id
           AND garage_id = :garage_id
           AND status_code = "ACTIVE"
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $attachmentId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }

    $updateStmt = $pdo->prepare(
        'UPDATE return_attachments
         SET status_code = "DELETED",
             deleted_at = NOW()
         WHERE id = :id
           AND company_id = :company_id
           AND garage_id = :garage_id'
    );
    $updateStmt->execute([
        'id' => $attachmentId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);

    $filePath = returns_upload_fs_path((string) ($row['file_path'] ?? ''));
    if ($filePath !== null && is_file($filePath)) {
        @unlink($filePath);
    }

    log_audit('returns', 'attachment_delete', $attachmentId, 'Deleted return attachment', [
        'entity' => 'return_attachment',
        'source' => 'UI',
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'user_id' => $actorUserId,
    ]);

    return true;
}

function returns_post_stock_reversal_for_approved_return(PDO $pdo, array $returnRow, array $returnItems, int $actorUserId): array
{
    $returnType = returns_normalize_type((string) ($returnRow['return_type'] ?? 'CUSTOMER_RETURN'));
    $companyId = (int) ($returnRow['company_id'] ?? 0);
    $garageId = (int) ($returnRow['garage_id'] ?? 0);
    $returnId = (int) ($returnRow['id'] ?? 0);

    if ($companyId <= 0 || $garageId <= 0 || $returnId <= 0) {
        throw new RuntimeException('Invalid return context for stock reversal posting.');
    }

    $movementType = $returnType === 'CUSTOMER_RETURN' ? 'IN' : 'OUT';
    $referenceType = $returnType;

    $partTotals = [];
    foreach ($returnItems as $item) {
        $partId = (int) ($item['part_id'] ?? 0);
        $qty = returns_round((float) ($item['quantity'] ?? 0));
        if ($partId <= 0 || $qty <= 0.009) {
            continue;
        }

        if (!isset($partTotals[$partId])) {
            $partTotals[$partId] = 0.0;
        }
        $partTotals[$partId] = returns_round($partTotals[$partId] + $qty);
    }

    if ($partTotals === []) {
        return ['posted' => [], 'skipped' => []];
    }

    $posted = [];
    $skipped = [];

    $existingLinkStmt = $pdo->prepare(
        'SELECT id, reversal_movement_uid
         FROM stock_reversal_links
         WHERE company_id = :company_id
           AND garage_id = :garage_id
           AND reversal_context_type = :context_type
           AND reversal_context_id = :context_id
           AND part_id = :part_id
           AND movement_type = :movement_type
         LIMIT 1'
    );

    $stockSelectStmt = $pdo->prepare(
        'SELECT quantity
         FROM garage_inventory
         WHERE garage_id = :garage_id
           AND part_id = :part_id
         FOR UPDATE'
    );

    $stockInsertStmt = $pdo->prepare(
        'INSERT INTO garage_inventory (garage_id, part_id, quantity)
         VALUES (:garage_id, :part_id, 0)'
    );

    $stockUpdateStmt = $pdo->prepare(
        'UPDATE garage_inventory
         SET quantity = :quantity
         WHERE garage_id = :garage_id
           AND part_id = :part_id'
    );

    $movementInsertStmt = $pdo->prepare(
        'INSERT INTO inventory_movements
          (company_id, garage_id, part_id, movement_type, quantity, reference_type, reference_id, movement_uid, notes, created_by)
         VALUES
          (:company_id, :garage_id, :part_id, :movement_type, :quantity, :reference_type, :reference_id, :movement_uid, :notes, :created_by)'
    );

    $linkInsertStmt = $pdo->prepare(
        'INSERT INTO stock_reversal_links
          (company_id, garage_id, reversal_context_type, reversal_context_id, part_id, movement_type, quantity, original_movement_uid, reversal_movement_uid, audit_reference, created_by)
         VALUES
          (:company_id, :garage_id, :context_type, :context_id, :part_id, :movement_type, :quantity, :original_movement_uid, :reversal_movement_uid, :audit_reference, :created_by)'
    );

    foreach ($partTotals as $partId => $quantity) {
        $existingLinkStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'context_type' => $referenceType,
            'context_id' => $returnId,
            'part_id' => $partId,
            'movement_type' => $movementType,
        ]);
        $existingLink = $existingLinkStmt->fetch();
        if ($existingLink) {
            $skipped[] = [
                'part_id' => (int) $partId,
                'quantity' => $quantity,
                'movement_uid' => (string) ($existingLink['reversal_movement_uid'] ?? ''),
            ];
            continue;
        }

        $stockSelectStmt->execute([
            'garage_id' => $garageId,
            'part_id' => $partId,
        ]);
        $stockRow = $stockSelectStmt->fetch();

        $currentQty = 0.0;
        if (!$stockRow) {
            $stockInsertStmt->execute([
                'garage_id' => $garageId,
                'part_id' => $partId,
            ]);
        } else {
            $currentQty = returns_round((float) ($stockRow['quantity'] ?? 0));
        }

        $nextQty = $movementType === 'IN'
            ? returns_round($currentQty + $quantity)
            : returns_round($currentQty - $quantity);

        $stockUpdateStmt->execute([
            'quantity' => $nextQty,
            'garage_id' => $garageId,
            'part_id' => $partId,
        ]);

        $movementUid = sprintf('rma-%d-%d-%s', $returnId, $partId, strtolower($movementType));

        $movementInsertStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'part_id' => $partId,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'reference_type' => $referenceType,
            'reference_id' => $returnId,
            'movement_uid' => $movementUid,
            'notes' => 'Stock reversal via ' . str_replace('_', ' ', $referenceType) . ' #' . $returnId,
            'created_by' => $actorUserId > 0 ? $actorUserId : null,
        ]);

        $linkInsertStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'context_type' => $referenceType,
            'context_id' => $returnId,
            'part_id' => $partId,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'original_movement_uid' => null,
            'reversal_movement_uid' => $movementUid,
            'audit_reference' => 'returns:' . $returnId,
            'created_by' => $actorUserId > 0 ? $actorUserId : null,
        ]);

        $posted[] = [
            'part_id' => (int) $partId,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'previous_stock' => $currentQty,
            'next_stock' => $nextQty,
            'movement_uid' => $movementUid,
        ];
    }

    return [
        'posted' => $posted,
        'skipped' => $skipped,
    ];
}

function returns_approve_rma(PDO $pdo, int $returnId, int $companyId, int $garageId, int $actorUserId): array
{
    if (!returns_module_ready()) {
        throw new RuntimeException('Returns module is not ready.');
    }

    $pdo->beginTransaction();
    try {
        $returnRow = returns_fetch_return_row($pdo, $returnId, $companyId, $garageId, true);
        if (!$returnRow) {
            throw new RuntimeException('Return entry not found.');
        }

        $status = returns_normalize_approval_status((string) ($returnRow['approval_status'] ?? 'PENDING'));
        if ($status === 'APPROVED' || $status === 'CLOSED') {
            throw new RuntimeException('Return is already approved.');
        }
        if ($status === 'REJECTED') {
            throw new RuntimeException('Rejected return cannot be approved.');
        }

        $returnItems = returns_fetch_return_items($pdo, (int) ($returnRow['id'] ?? 0));
        if ($returnItems === []) {
            throw new RuntimeException('Return has no line items.');
        }

        $stockPosting = returns_post_stock_reversal_for_approved_return($pdo, $returnRow, $returnItems, $actorUserId);

        $creditNoteMeta = null;
        if (returns_normalize_type((string) ($returnRow['return_type'] ?? '')) === 'CUSTOMER_RETURN') {
            $invoiceId = (int) ($returnRow['invoice_id'] ?? 0);
            if ($invoiceId > 0) {
                $creditItems = [];
                foreach ($returnItems as $item) {
                    $sourceItemId = (int) ($item['source_item_id'] ?? 0);
                    $qty = returns_round((float) ($item['quantity'] ?? 0));
                    if ($sourceItemId <= 0 || $qty <= 0.009) {
                        continue;
                    }
                    $creditItems[] = [
                        'invoice_item_id' => $sourceItemId,
                        'quantity' => $qty,
                    ];
                }

                if ($creditItems !== []) {
                    $creditNoteMeta = billing_create_credit_note_from_return(
                        $pdo,
                        $companyId,
                        $garageId,
                        $invoiceId,
                        (int) ($returnRow['id'] ?? 0),
                        $creditItems,
                        $actorUserId > 0 ? $actorUserId : null,
                        (string) ($returnRow['return_date'] ?? date('Y-m-d'))
                    );
                }
            }
        }

        $updateStmt = $pdo->prepare(
            'UPDATE returns_rma
             SET approval_status = "APPROVED",
                 approved_by = :approved_by,
                 approved_at = NOW(),
                 rejected_reason = NULL,
                 credit_note_id = :credit_note_id,
                 updated_by = :updated_by
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id'
        );
        $updateStmt->execute([
            'approved_by' => $actorUserId > 0 ? $actorUserId : null,
            'credit_note_id' => $creditNoteMeta !== null ? (int) ($creditNoteMeta['credit_note_id'] ?? 0) : null,
            'updated_by' => $actorUserId > 0 ? $actorUserId : null,
            'id' => $returnId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        $pdo->commit();

        return [
            'return_id' => $returnId,
            'return_number' => (string) ($returnRow['return_number'] ?? ''),
            'return_type' => (string) ($returnRow['return_type'] ?? ''),
            'stock_posting' => $stockPosting,
            'credit_note' => $creditNoteMeta,
        ];
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function returns_reject_rma(PDO $pdo, int $returnId, int $companyId, int $garageId, int $actorUserId, string $reason): array
{
    if (!returns_module_ready()) {
        throw new RuntimeException('Returns module is not ready.');
    }

    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Rejection reason is required.');
    }

    $pdo->beginTransaction();
    try {
        $returnRow = returns_fetch_return_row($pdo, $returnId, $companyId, $garageId, true);
        if (!$returnRow) {
            throw new RuntimeException('Return entry not found.');
        }

        $status = returns_normalize_approval_status((string) ($returnRow['approval_status'] ?? 'PENDING'));
        if ($status === 'APPROVED' || $status === 'CLOSED') {
            throw new RuntimeException('Approved return cannot be rejected.');
        }

        $updateStmt = $pdo->prepare(
            'UPDATE returns_rma
             SET approval_status = "REJECTED",
                 rejected_reason = :rejected_reason,
                 approved_by = NULL,
                 approved_at = NULL,
                 updated_by = :updated_by
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id'
        );
        $updateStmt->execute([
            'rejected_reason' => mb_substr($reason, 0, 255),
            'updated_by' => $actorUserId > 0 ? $actorUserId : null,
            'id' => $returnId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        $pdo->commit();

        return [
            'return_id' => $returnId,
            'return_number' => (string) ($returnRow['return_number'] ?? ''),
            'return_type' => (string) ($returnRow['return_type'] ?? ''),
        ];
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function returns_close_rma(PDO $pdo, int $returnId, int $companyId, int $garageId, int $actorUserId): bool
{
    $stmt = $pdo->prepare(
        'UPDATE returns_rma
         SET approval_status = "CLOSED",
             updated_by = :updated_by
         WHERE id = :id
           AND company_id = :company_id
           AND garage_id = :garage_id
           AND approval_status = "APPROVED"
           AND status_code = "ACTIVE"'
    );
    $stmt->execute([
        'updated_by' => $actorUserId > 0 ? $actorUserId : null,
        'id' => $returnId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);

    return $stmt->rowCount() > 0;
}
