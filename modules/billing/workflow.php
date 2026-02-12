<?php
declare(strict_types=1);

function billing_has_permission(array $permissionKeys): bool
{
    foreach ($permissionKeys as $permissionKey) {
        if (has_permission($permissionKey)) {
            return true;
        }
    }

    return false;
}

function billing_can_view(): bool
{
    return billing_has_permission(['billing.view', 'invoice.view']);
}

function billing_can_create(): bool
{
    return billing_has_permission(['billing.create', 'invoice.manage']);
}

function billing_can_finalize(): bool
{
    return billing_has_permission(['billing.finalize', 'invoice.manage']);
}

function billing_can_cancel(): bool
{
    return billing_has_permission(['billing.cancel', 'invoice.manage']);
}

function billing_can_pay(): bool
{
    return billing_has_permission(['invoice.pay', 'billing.finalize']);
}

function billing_allowed_payment_modes(): array
{
    return [
        'CASH' => 'Cash',
        'UPI' => 'UPI',
        'CARD' => 'Card',
        'BANK_TRANSFER' => 'Bank Transfer',
        'CHEQUE' => 'Cheque',
        'MIXED' => 'Mixed',
    ];
}

function billing_normalize_payment_mode(string $paymentMode): string
{
    $paymentMode = strtoupper(trim($paymentMode));
    $allowed = array_keys(billing_allowed_payment_modes());
    return in_array($paymentMode, $allowed, true) ? $paymentMode : 'CASH';
}

function billing_status_badge_class(string $status): string
{
    return match (strtoupper(trim($status))) {
        'DRAFT' => 'warning',
        'FINALIZED' => 'success',
        'CANCELLED' => 'danger',
        default => 'secondary',
    };
}

function billing_payment_badge_class(string $status): string
{
    return match (strtoupper(trim($status))) {
        'PAID' => 'success',
        'PARTIAL' => 'warning',
        'UNPAID' => 'secondary',
        'CANCELLED' => 'danger',
        default => 'secondary',
    };
}

function billing_round(float $value): float
{
    return round($value, 2);
}

function billing_normalize_state(?string $state): string
{
    $normalized = strtoupper(trim((string) $state));
    $normalized = preg_replace('/[^A-Z ]+/', '', $normalized) ?? '';
    return trim($normalized);
}

function billing_tax_regime(string $garageState, string $customerState): string
{
    $garageStateNorm = billing_normalize_state($garageState);
    $customerStateNorm = billing_normalize_state($customerState);

    if ($garageStateNorm === '' || $customerStateNorm === '') {
        throw new RuntimeException('Garage and customer state are required to determine GST regime.');
    }

    return $garageStateNorm === $customerStateNorm ? 'INTRASTATE' : 'INTERSTATE';
}

function billing_calculate_line(
    string $itemType,
    string $description,
    ?int $partId,
    ?int $serviceId,
    ?string $hsnSacCode,
    float $quantity,
    float $unitPrice,
    float $gstRate,
    string $taxRegime
): array {
    $quantity = billing_round($quantity);
    $unitPrice = billing_round($unitPrice);
    $gstRate = billing_round(max(0.0, min(100.0, $gstRate)));

    $taxableValue = billing_round($quantity * $unitPrice);
    $totalTaxAmount = billing_round($taxableValue * $gstRate / 100);

    $cgstRate = 0.0;
    $sgstRate = 0.0;
    $igstRate = 0.0;
    $cgstAmount = 0.0;
    $sgstAmount = 0.0;
    $igstAmount = 0.0;

    if ($taxRegime === 'INTERSTATE') {
        $igstRate = $gstRate;
        $igstAmount = $totalTaxAmount;
    } else {
        $cgstRate = billing_round($gstRate / 2);
        $sgstRate = billing_round($gstRate / 2);
        $cgstAmount = billing_round($totalTaxAmount / 2);
        $sgstAmount = billing_round($totalTaxAmount - $cgstAmount);
    }

    return [
        'item_type' => $itemType,
        'description' => $description,
        'part_id' => $partId,
        'service_id' => $serviceId,
        'hsn_sac_code' => $hsnSacCode !== null && $hsnSacCode !== '' ? mb_substr($hsnSacCode, 0, 20) : null,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'gst_rate' => $gstRate,
        'taxable_value' => $taxableValue,
        'cgst_rate' => $cgstRate,
        'sgst_rate' => $sgstRate,
        'igst_rate' => $igstRate,
        'cgst_amount' => $cgstAmount,
        'sgst_amount' => $sgstAmount,
        'igst_amount' => $igstAmount,
        'tax_amount' => $totalTaxAmount,
        'total_value' => billing_round($taxableValue + $totalTaxAmount),
    ];
}

function billing_calculate_totals(array $lines): array
{
    $serviceSubtotal = 0.0;
    $partsSubtotal = 0.0;
    $serviceTaxAmount = 0.0;
    $partsTaxAmount = 0.0;
    $cgstAmount = 0.0;
    $sgstAmount = 0.0;
    $igstAmount = 0.0;

    foreach ($lines as $line) {
        $taxable = (float) ($line['taxable_value'] ?? 0);
        $lineTax = (float) ($line['tax_amount'] ?? 0);
        $lineType = strtoupper((string) ($line['item_type'] ?? ''));

        if ($lineType === 'LABOR') {
            $serviceSubtotal += $taxable;
            $serviceTaxAmount += $lineTax;
        } elseif ($lineType === 'PART') {
            $partsSubtotal += $taxable;
            $partsTaxAmount += $lineTax;
        }

        $cgstAmount += (float) ($line['cgst_amount'] ?? 0);
        $sgstAmount += (float) ($line['sgst_amount'] ?? 0);
        $igstAmount += (float) ($line['igst_amount'] ?? 0);
    }

    $taxableAmount = billing_round($serviceSubtotal + $partsSubtotal);
    $totalTaxAmount = billing_round($serviceTaxAmount + $partsTaxAmount);
    $grossTotal = billing_round($taxableAmount + $totalTaxAmount);
    $roundedTotal = round($grossTotal, 0);
    $roundOff = billing_round($roundedTotal - $grossTotal);
    $grandTotal = billing_round($grossTotal + $roundOff);

    return [
        'subtotal_service' => billing_round($serviceSubtotal),
        'subtotal_parts' => billing_round($partsSubtotal),
        'service_tax_amount' => billing_round($serviceTaxAmount),
        'parts_tax_amount' => billing_round($partsTaxAmount),
        'taxable_amount' => $taxableAmount,
        'total_tax_amount' => $totalTaxAmount,
        'cgst_amount' => billing_round($cgstAmount),
        'sgst_amount' => billing_round($sgstAmount),
        'igst_amount' => billing_round($igstAmount),
        'gross_total' => $grossTotal,
        'round_off' => $roundOff,
        'grand_total' => $grandTotal,
        'cgst_rate' => $taxableAmount > 0 ? billing_round(($cgstAmount * 100) / $taxableAmount) : 0.0,
        'sgst_rate' => $taxableAmount > 0 ? billing_round(($sgstAmount * 100) / $taxableAmount) : 0.0,
        'igst_rate' => $taxableAmount > 0 ? billing_round(($igstAmount * 100) / $taxableAmount) : 0.0,
    ];
}

function billing_get_setting_value(PDO $pdo, int $companyId, int $garageId, string $settingKey, ?string $default = null): ?string
{
    $stmt = $pdo->prepare(
        'SELECT setting_value
         FROM system_settings
         WHERE company_id = :company_id
           AND setting_key = :setting_key
           AND status_code = "ACTIVE"
           AND (garage_id = :garage_id OR garage_id IS NULL)
         ORDER BY CASE WHEN garage_id = :garage_id THEN 0 ELSE 1 END
         LIMIT 1'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'setting_key' => $settingKey,
        'garage_id' => $garageId > 0 ? $garageId : null,
    ]);
    $value = $stmt->fetchColumn();

    if ($value === false || $value === null || trim((string) $value) === '') {
        return $default;
    }

    return trim((string) $value);
}

function billing_invoice_prefix(PDO $pdo, int $companyId, int $garageId): string
{
    $prefix = billing_get_setting_value($pdo, $companyId, $garageId, 'invoice_prefix', null);

    if ($prefix === null) {
        $counterStmt = $pdo->prepare('SELECT prefix FROM invoice_counters WHERE garage_id = :garage_id LIMIT 1');
        $counterStmt->execute(['garage_id' => $garageId]);
        $counterPrefix = $counterStmt->fetchColumn();
        if (is_string($counterPrefix) && trim($counterPrefix) !== '') {
            $prefix = $counterPrefix;
        }
    }

    $prefix = strtoupper(trim((string) $prefix));
    $prefix = preg_replace('/[^A-Z0-9-]+/', '', $prefix) ?? '';

    return $prefix !== '' ? mb_substr($prefix, 0, 20) : 'INV';
}

function billing_derive_financial_year_label(string $invoiceDate): string
{
    $timestamp = strtotime($invoiceDate);
    if ($timestamp === false) {
        $timestamp = time();
    }

    $year = (int) date('Y', $timestamp);
    $month = (int) date('n', $timestamp);
    $startYear = $month >= 4 ? $year : ($year - 1);
    $endYearShort = ($startYear + 1) % 100;

    return sprintf('%d-%02d', $startYear, $endYearShort);
}

function billing_resolve_financial_year(PDO $pdo, int $companyId, string $invoiceDate): array
{
    $fyStmt = $pdo->prepare(
        'SELECT id, fy_label
         FROM financial_years
         WHERE company_id = :company_id
           AND status_code = "ACTIVE"
           AND :invoice_date BETWEEN start_date AND end_date
         ORDER BY is_default DESC, start_date DESC
         LIMIT 1'
    );
    $fyStmt->execute([
        'company_id' => $companyId,
        'invoice_date' => $invoiceDate,
    ]);
    $financialYear = $fyStmt->fetch();

    if ($financialYear) {
        return [
            'id' => (int) $financialYear['id'],
            'label' => (string) $financialYear['fy_label'],
        ];
    }

    $defaultStmt = $pdo->prepare(
        'SELECT id, fy_label
         FROM financial_years
         WHERE company_id = :company_id
           AND status_code = "ACTIVE"
         ORDER BY is_default DESC, start_date DESC
         LIMIT 1'
    );
    $defaultStmt->execute(['company_id' => $companyId]);
    $defaultYear = $defaultStmt->fetch();

    if ($defaultYear) {
        return [
            'id' => (int) $defaultYear['id'],
            'label' => (string) $defaultYear['fy_label'],
        ];
    }

    return [
        'id' => null,
        'label' => billing_derive_financial_year_label($invoiceDate),
    ];
}

function billing_generate_invoice_number(PDO $pdo, int $companyId, int $garageId, string $invoiceDate): array
{
    $financialYear = billing_resolve_financial_year($pdo, $companyId, $invoiceDate);
    $fyLabel = mb_substr(trim((string) $financialYear['label']), 0, 20);
    if ($fyLabel === '') {
        $fyLabel = billing_derive_financial_year_label($invoiceDate);
    }

    $sequenceStmt = $pdo->prepare(
        'SELECT id, prefix, current_number
         FROM invoice_number_sequences
         WHERE company_id = :company_id
           AND garage_id = :garage_id
           AND financial_year_label = :financial_year_label
         FOR UPDATE'
    );
    $sequenceStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'financial_year_label' => $fyLabel,
    ]);
    $sequence = $sequenceStmt->fetch();

    if (!$sequence) {
        $prefix = billing_invoice_prefix($pdo, $companyId, $garageId);
        $insertStmt = $pdo->prepare(
            'INSERT INTO invoice_number_sequences
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
            // Another transaction may have inserted the same sequence row.
        }

        $sequenceStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'financial_year_label' => $fyLabel,
        ]);
        $sequence = $sequenceStmt->fetch();
    }

    if (!$sequence) {
        throw new RuntimeException('Unable to reserve invoice sequence for this financial year.');
    }

    $nextNumber = ((int) $sequence['current_number']) + 1;
    $updateStmt = $pdo->prepare(
        'UPDATE invoice_number_sequences
         SET current_number = :current_number,
             prefix = :prefix,
             financial_year_id = :financial_year_id
         WHERE id = :id'
    );
    $updateStmt->execute([
        'current_number' => $nextNumber,
        'prefix' => billing_invoice_prefix($pdo, $companyId, $garageId),
        'financial_year_id' => $financialYear['id'],
        'id' => (int) $sequence['id'],
    ]);

    $sequenceStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'financial_year_label' => $fyLabel,
    ]);
    $latestSequence = $sequenceStmt->fetch();
    if (!$latestSequence) {
        throw new RuntimeException('Unable to read invoice sequence after update.');
    }

    $prefix = (string) $latestSequence['prefix'];
    $invoiceNumber = sprintf('%s/%s/%05d', $prefix, $fyLabel, $nextNumber);

    return [
        'invoice_number' => $invoiceNumber,
        'sequence_number' => $nextNumber,
        'financial_year_id' => $financialYear['id'],
        'financial_year_label' => $fyLabel,
        'prefix' => $prefix,
    ];
}

function billing_record_status_history(
    PDO $pdo,
    int $invoiceId,
    ?string $fromStatus,
    string $toStatus,
    string $actionType,
    ?string $note,
    ?int $createdBy,
    ?array $payload = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO invoice_status_history
          (invoice_id, from_status, to_status, action_type, action_note, payload_json, created_by)
         VALUES
          (:invoice_id, :from_status, :to_status, :action_type, :action_note, :payload_json, :created_by)'
    );
    $stmt->execute([
        'invoice_id' => $invoiceId,
        'from_status' => $fromStatus !== null ? mb_substr(strtoupper($fromStatus), 0, 20) : null,
        'to_status' => mb_substr(strtoupper($toStatus), 0, 20),
        'action_type' => mb_substr(strtoupper($actionType), 0, 40),
        'action_note' => $note !== null ? mb_substr($note, 0, 255) : null,
        'payload_json' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        'created_by' => $createdBy,
    ]);
}

function billing_record_payment_history(
    PDO $pdo,
    int $invoiceId,
    ?int $paymentId,
    string $actionType,
    ?string $note,
    ?int $createdBy,
    ?array $payload = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO invoice_payment_history
          (invoice_id, payment_id, action_type, action_note, payload_json, created_by)
         VALUES
          (:invoice_id, :payment_id, :action_type, :action_note, :payload_json, :created_by)'
    );
    $stmt->execute([
        'invoice_id' => $invoiceId,
        'payment_id' => $paymentId,
        'action_type' => mb_substr(strtoupper($actionType), 0, 40),
        'action_note' => $note !== null ? mb_substr($note, 0, 255) : null,
        'payload_json' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        'created_by' => $createdBy,
    ]);
}

function billing_payment_mode_summary(PDO $pdo, int $invoiceId): ?string
{
    $paymentColumns = table_columns('payments');
    $hasEntryType = in_array('entry_type', $paymentColumns, true);
    $hasReversedPaymentId = in_array('reversed_payment_id', $paymentColumns, true);
    $hasIsReversed = in_array('is_reversed', $paymentColumns, true);

    if ($hasEntryType && $hasReversedPaymentId) {
        $sql =
            'SELECT DISTINCT p.payment_mode
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

        $modeStmt = $pdo->prepare($sql);
        $modeStmt->execute(['invoice_id' => $invoiceId]);
    } else {
        $where = ['invoice_id = :invoice_id', 'amount > 0'];
        if ($hasEntryType) {
            $where[] = 'entry_type = "PAYMENT"';
        }

        $modeStmt = $pdo->prepare(
            'SELECT DISTINCT payment_mode
             FROM payments
             WHERE ' . implode(' AND ', $where)
        );
        $modeStmt->execute(['invoice_id' => $invoiceId]);
    }

    $modes = array_values(
        array_filter(
            array_map(static fn (array $row): string => billing_normalize_payment_mode((string) ($row['payment_mode'] ?? '')), $modeStmt->fetchAll()),
            static fn (string $mode): bool => $mode !== ''
        )
    );

    if (empty($modes)) {
        return null;
    }

    $uniqueModes = array_values(array_unique($modes));
    if (count($uniqueModes) === 1) {
        return $uniqueModes[0];
    }

    return 'MIXED';
}

function billing_validate_invoice_totals(array $invoiceRow, array $invoiceItems): array
{
    if (empty($invoiceItems)) {
        return [
            'valid' => false,
            'errors' => ['Invoice has no line items.'],
        ];
    }

    $lineErrors = [];
    foreach ($invoiceItems as $index => $item) {
        $lineTax = billing_round((float) ($item['cgst_amount'] ?? 0) + (float) ($item['sgst_amount'] ?? 0) + (float) ($item['igst_amount'] ?? 0));
        $storedTax = billing_round((float) ($item['tax_amount'] ?? 0));
        if (abs($lineTax - $storedTax) > 0.05) {
            $lineErrors[] = 'Line #' . ($index + 1) . ' has GST component mismatch.';
        }

        $expectedTotal = billing_round((float) ($item['taxable_value'] ?? 0) + $storedTax);
        $storedTotal = billing_round((float) ($item['total_value'] ?? 0));
        if (abs($expectedTotal - $storedTotal) > 0.05) {
            $lineErrors[] = 'Line #' . ($index + 1) . ' total does not match taxable + tax.';
        }
    }

    $computedTotals = billing_calculate_totals($invoiceItems);
    $headerChecks = [
        'subtotal_service',
        'subtotal_parts',
        'taxable_amount',
        'service_tax_amount',
        'parts_tax_amount',
        'total_tax_amount',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'gross_total',
        'round_off',
        'grand_total',
    ];

    $headerErrors = [];
    foreach ($headerChecks as $field) {
        $computed = billing_round((float) ($computedTotals[$field] ?? 0));
        $stored = billing_round((float) ($invoiceRow[$field] ?? 0));
        if (abs($computed - $stored) > 0.05) {
            $headerErrors[] = 'Header field "' . $field . '" does not match stored GST snapshot.';
        }
    }

    $errors = array_merge($lineErrors, $headerErrors);
    return [
        'valid' => empty($errors),
        'errors' => $errors,
    ];
}
