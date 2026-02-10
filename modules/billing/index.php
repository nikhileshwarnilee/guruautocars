<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/workflow.php';

if (!billing_can_view()) {
    flash_set('access_denied', 'You do not have permission to access billing.', 'danger');
    redirect('dashboard.php');
}
$page_title = 'Billing & Invoices';
$active_menu = 'billing';
$companyId = active_company_id();
$garageId = active_garage_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$canCreate = billing_can_create();
$canFinalize = billing_can_finalize();
$canCancel = billing_can_cancel();
$canPay = billing_can_pay();

function billing_parse_date(?string $date): ?string
{
    $date = trim((string) $date);
    if ($date === '') {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $date));
    if (!checkdate($month, $day, $year)) {
        return null;
    }

    return $date;
}

function billing_snapshot_value(array $snapshot, string $section, string $key): string
{
    $value = $snapshot[$section][$key] ?? null;
    return is_string($value) ? $value : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create_invoice' && $canCreate) {
        if (!has_permission('job.view')) {
            flash_set('billing_error', 'You do not have permission to bill against job cards.', 'danger');
            redirect('modules/billing/index.php');
        }

        $jobCardId = post_int('job_card_id');
        $dueDate = billing_parse_date((string) ($_POST['due_date'] ?? ''));
        $notes = post_string('notes', 1000);

        if ($jobCardId <= 0) {
            flash_set('billing_error', 'Select a closed job card.', 'danger');
            redirect('modules/billing/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $jobStmt = $pdo->prepare(
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
                   AND (cu.status_code IS NULL OR cu.status_code <> "DELETED")
                   AND (v.status_code IS NULL OR v.status_code <> "DELETED")
                 LIMIT 1
                 FOR UPDATE'
            );
            $jobStmt->execute([
                'job_id' => $jobCardId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            $job = $jobStmt->fetch();

            if (!$job) {
                throw new RuntimeException('Invoice can be generated only from active CLOSED job cards in this garage.');
            }

            $existingInvoiceStmt = $pdo->prepare('SELECT id, invoice_number, invoice_status FROM invoices WHERE job_card_id = :job_id LIMIT 1 FOR UPDATE');
            $existingInvoiceStmt->execute(['job_id' => $jobCardId]);
            $existingInvoice = $existingInvoiceStmt->fetch();
            if ($existingInvoice) {
                throw new RuntimeException('Invoice already exists for this job card (' . (string) $existingInvoice['invoice_number'] . ').');
            }

            $laborStmt = $pdo->prepare(
                'SELECT jl.id, jl.service_id, jl.description, jl.quantity, jl.unit_price, jl.gst_rate,
                        s.service_code
                 FROM job_labor jl
                 LEFT JOIN services s ON s.id = jl.service_id
                 WHERE jl.job_card_id = :job_id
                 ORDER BY jl.id ASC'
            );
            $laborStmt->execute(['job_id' => $jobCardId]);
            $laborLines = $laborStmt->fetchAll();

            $partsStmt = $pdo->prepare(
                'SELECT jp.id, jp.part_id, jp.quantity, jp.unit_price, jp.gst_rate,
                        p.part_name, p.hsn_code
                 FROM job_parts jp
                 INNER JOIN parts p ON p.id = jp.part_id
                 WHERE jp.job_card_id = :job_id
                 ORDER BY jp.id ASC'
            );
            $partsStmt->execute(['job_id' => $jobCardId]);
            $partLines = $partsStmt->fetchAll();

            if (empty($laborLines) && empty($partLines)) {
                throw new RuntimeException('No labor or parts found for this job card.');
            }

            $taxRegime = billing_tax_regime((string) ($job['garage_state'] ?? ''), (string) ($job['customer_state'] ?? ''));

            $invoiceLines = [];
            foreach ($laborLines as $line) {
                $description = trim((string) ($line['description'] ?? ''));
                if ($description === '') {
                    $description = 'Labor Line #' . (int) $line['id'];
                }
                $invoiceLines[] = billing_calculate_line(
                    'LABOR',
                    $description,
                    null,
                    (isset($line['service_id']) && (int) $line['service_id'] > 0) ? (int) $line['service_id'] : null,
                    isset($line['service_code']) ? (string) $line['service_code'] : null,
                    (float) $line['quantity'],
                    (float) $line['unit_price'],
                    (float) $line['gst_rate'],
                    $taxRegime
                );
            }

            foreach ($partLines as $line) {
                $invoiceLines[] = billing_calculate_line(
                    'PART',
                    (string) $line['part_name'],
                    (int) $line['part_id'],
                    null,
                    isset($line['hsn_code']) ? (string) $line['hsn_code'] : null,
                    (float) $line['quantity'],
                    (float) $line['unit_price'],
                    (float) $line['gst_rate'],
                    $taxRegime
                );
            }

            $totals = billing_calculate_totals($invoiceLines);
            if (((float) $totals['grand_total']) <= 0.0) {
                throw new RuntimeException('Invoice total must be greater than zero.');
            }

            $invoiceDate = date('Y-m-d');
            $numberMeta = billing_generate_invoice_number($pdo, $companyId, $garageId, $invoiceDate);
            $invoiceNumber = (string) $numberMeta['invoice_number'];

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
                    'model_year' => $job['model_year'] !== null ? (int) $job['model_year'] : null,
                ],
                'job' => [
                    'id' => (int) $job['id'],
                    'job_number' => (string) $job['job_number'],
                    'closed_at' => (string) ($job['closed_at'] ?? ''),
                ],
                'tax_regime' => $taxRegime,
                'generated_at' => date('c'),
            ];

            $invoiceInsert = $pdo->prepare(
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
                'company_id' => $companyId,
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
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => $userId > 0 ? $userId : null,
                'financial_year_id' => $numberMeta['financial_year_id'],
                'financial_year_label' => (string) $numberMeta['financial_year_label'],
                'sequence_number' => (int) $numberMeta['sequence_number'],
                'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            ]);

            $invoiceId = (int) $pdo->lastInsertId();

            $itemInsert = $pdo->prepare(
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
                    'item_type' => (string) $line['item_type'],
                    'description' => (string) $line['description'],
                    'part_id' => $line['part_id'],
                    'service_id' => $line['service_id'],
                    'hsn_sac_code' => $line['hsn_sac_code'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'gst_rate' => $line['gst_rate'],
                    'cgst_rate' => $line['cgst_rate'],
                    'sgst_rate' => $line['sgst_rate'],
                    'igst_rate' => $line['igst_rate'],
                    'taxable_value' => $line['taxable_value'],
                    'cgst_amount' => $line['cgst_amount'],
                    'sgst_amount' => $line['sgst_amount'],
                    'igst_amount' => $line['igst_amount'],
                    'tax_amount' => $line['tax_amount'],
                    'total_value' => $line['total_value'],
                ]);
            }

            billing_record_status_history(
                $pdo,
                $invoiceId,
                null,
                'DRAFT',
                'CREATE_DRAFT',
                'Draft invoice created from closed job card.',
                $userId > 0 ? $userId : null,
                [
                    'job_card_id' => (int) $job['id'],
                    'invoice_number' => $invoiceNumber,
                    'tax_regime' => $taxRegime,
                ]
            );

            log_audit('billing', 'create', $invoiceId, 'Created draft invoice ' . $invoiceNumber . ' from Job ' . (string) $job['job_number'], [
                'entity' => 'invoice',
                'source' => 'UI',
                'before' => ['exists' => false],
                'after' => [
                    'invoice_number' => $invoiceNumber,
                    'invoice_status' => 'DRAFT',
                    'payment_status' => 'UNPAID',
                    'grand_total' => (float) ($totals['grand_total'] ?? 0),
                    'taxable_amount' => (float) ($totals['taxable_amount'] ?? 0),
                    'total_tax_amount' => (float) ($totals['total_tax_amount'] ?? 0),
                    'job_card_id' => (int) $job['id'],
                ],
                'metadata' => [
                    'financial_year_label' => (string) ($numberMeta['financial_year_label'] ?? ''),
                    'line_count' => count($invoiceLines),
                ],
            ]);

            $pdo->commit();
            flash_set('billing_success', 'Draft invoice created: ' . $invoiceNumber, 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('billing_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/billing/index.php');
    }

    if ($action === 'finalize_invoice' && $canFinalize) {
        $invoiceId = post_int('invoice_id');
        if ($invoiceId <= 0) {
            flash_set('billing_error', 'Invalid invoice selected for finalization.', 'danger');
            redirect('modules/billing/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $invoiceStmt = $pdo->prepare(
                'SELECT *
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
            $invoice = $invoiceStmt->fetch();

            if (!$invoice) {
                throw new RuntimeException('Invoice not found for finalization.');
            }

            $currentStatus = strtoupper((string) ($invoice['invoice_status'] ?? ''));
            if ($currentStatus === 'CANCELLED') {
                throw new RuntimeException('Cancelled invoices cannot be finalized.');
            }
            if ($currentStatus === 'FINALIZED') {
                throw new RuntimeException('Invoice is already finalized.');
            }
            if ($currentStatus !== 'DRAFT') {
                throw new RuntimeException('Only draft invoices can be finalized.');
            }

            $itemStmt = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = :invoice_id ORDER BY id ASC');
            $itemStmt->execute(['invoice_id' => $invoiceId]);
            $items = $itemStmt->fetchAll();

            $validation = billing_validate_invoice_totals($invoice, $items);
            if (!($validation['valid'] ?? false)) {
                $errors = $validation['errors'] ?? [];
                throw new RuntimeException('GST mismatch found: ' . implode(' ', array_slice($errors, 0, 3)));
            }

            $finalizeStmt = $pdo->prepare(
                'UPDATE invoices
                 SET invoice_status = "FINALIZED",
                     finalized_at = NOW(),
                     finalized_by = :finalized_by
                 WHERE id = :invoice_id'
            );
            $finalizeStmt->execute([
                'finalized_by' => $userId > 0 ? $userId : null,
                'invoice_id' => $invoiceId,
            ]);

            billing_record_status_history(
                $pdo,
                $invoiceId,
                'DRAFT',
                'FINALIZED',
                'FINALIZE',
                'Invoice finalized after GST integrity validation.',
                $userId > 0 ? $userId : null,
                null
            );

            log_audit('billing', 'finalize', $invoiceId, 'Finalized invoice ' . (string) $invoice['invoice_number'], [
                'entity' => 'invoice',
                'source' => 'UI',
                'before' => [
                    'invoice_status' => (string) ($invoice['invoice_status'] ?? 'DRAFT'),
                    'payment_status' => (string) ($invoice['payment_status'] ?? 'UNPAID'),
                    'grand_total' => (float) ($invoice['grand_total'] ?? 0),
                ],
                'after' => [
                    'invoice_status' => 'FINALIZED',
                    'payment_status' => (string) ($invoice['payment_status'] ?? 'UNPAID'),
                    'grand_total' => (float) ($invoice['grand_total'] ?? 0),
                ],
                'metadata' => [
                    'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
                ],
            ]);

            $pdo->commit();
            flash_set('billing_success', 'Invoice finalized successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('billing_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/billing/index.php');
    }

    if ($action === 'cancel_invoice' && $canCancel) {
        $invoiceId = post_int('invoice_id');
        $cancelReason = post_string('cancel_reason', 255);

        if ($invoiceId <= 0 || $cancelReason === '') {
            flash_set('billing_error', 'Invoice and cancellation reason are required.', 'danger');
            redirect('modules/billing/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $invoiceStmt = $pdo->prepare(
                'SELECT id, invoice_number, invoice_status, payment_status
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
            $invoice = $invoiceStmt->fetch();

            if (!$invoice) {
                throw new RuntimeException('Invoice not found for cancellation.');
            }

            $currentStatus = strtoupper((string) ($invoice['invoice_status'] ?? ''));
            if ($currentStatus === 'CANCELLED') {
                throw new RuntimeException('Invoice is already cancelled.');
            }

            $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :invoice_id');
            $paidStmt->execute(['invoice_id' => $invoiceId]);
            $paidAmount = (float) $paidStmt->fetchColumn();
            if ($paidAmount > 0.01) {
                throw new RuntimeException('Invoice with payments cannot be cancelled. Reverse payments first.');
            }

            $cancelStmt = $pdo->prepare(
                'UPDATE invoices
                 SET invoice_status = "CANCELLED",
                     payment_status = "CANCELLED",
                     cancelled_at = NOW(),
                     cancelled_by = :cancelled_by,
                     cancel_reason = :cancel_reason
                 WHERE id = :invoice_id'
            );
            $cancelStmt->execute([
                'cancelled_by' => $userId > 0 ? $userId : null,
                'cancel_reason' => $cancelReason,
                'invoice_id' => $invoiceId,
            ]);

            billing_record_status_history(
                $pdo,
                $invoiceId,
                $currentStatus,
                'CANCELLED',
                'CANCEL',
                $cancelReason,
                $userId > 0 ? $userId : null,
                ['paid_amount' => $paidAmount]
            );

            log_audit('billing', 'cancel', $invoiceId, 'Cancelled invoice ' . (string) $invoice['invoice_number'], [
                'entity' => 'invoice',
                'source' => 'UI',
                'before' => [
                    'invoice_status' => $currentStatus,
                    'payment_status' => (string) ($invoice['payment_status'] ?? 'UNPAID'),
                    'paid_amount' => (float) $paidAmount,
                ],
                'after' => [
                    'invoice_status' => 'CANCELLED',
                    'payment_status' => 'CANCELLED',
                    'cancel_reason' => $cancelReason,
                ],
                'metadata' => [
                    'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
                ],
            ]);

            $pdo->commit();
            flash_set('billing_success', 'Invoice cancelled successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('billing_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/billing/index.php');
    }

    if ($action === 'add_payment' && $canPay) {
        $invoiceId = post_int('invoice_id');
        $amount = (float) ($_POST['amount'] ?? 0);
        $paidOn = billing_parse_date((string) ($_POST['paid_on'] ?? ''));
        $paymentMode = billing_normalize_payment_mode((string) ($_POST['payment_mode'] ?? 'CASH'));
        $referenceNo = post_string('reference_no', 100);
        $notes = post_string('notes', 255);

        if ($paymentMode === 'MIXED') {
            $paymentMode = 'CASH';
        }

        if ($invoiceId <= 0 || $amount <= 0 || $paidOn === null) {
            flash_set('billing_error', 'Invoice, amount, payment date and mode are required.', 'danger');
            redirect('modules/billing/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $invoiceStmt = $pdo->prepare(
                'SELECT id, invoice_number, grand_total, invoice_status
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
            $invoice = $invoiceStmt->fetch();

            if (!$invoice) {
                throw new RuntimeException('Invoice not found.');
            }

            if ((string) ($invoice['invoice_status'] ?? '') !== 'FINALIZED') {
                throw new RuntimeException('Payments are allowed only for finalized invoices.');
            }

            $paidSumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :invoice_id');
            $paidSumStmt->execute(['invoice_id' => $invoiceId]);
            $alreadyPaid = (float) $paidSumStmt->fetchColumn();
            $grandTotal = (float) $invoice['grand_total'];
            $remaining = $grandTotal - $alreadyPaid;

            if ($amount > $remaining + 0.01) {
                throw new RuntimeException('Payment exceeds outstanding amount. Outstanding: ' . number_format(max(0.0, $remaining), 2));
            }

            $paymentInsert = $pdo->prepare(
                'INSERT INTO payments
                  (invoice_id, amount, paid_on, payment_mode, reference_no, notes, received_by)
                 VALUES
                  (:invoice_id, :amount, :paid_on, :payment_mode, :reference_no, :notes, :received_by)'
            );
            $paymentInsert->execute([
                'invoice_id' => $invoiceId,
                'amount' => billing_round($amount),
                'paid_on' => $paidOn,
                'payment_mode' => $paymentMode,
                'reference_no' => $referenceNo !== '' ? $referenceNo : null,
                'notes' => $notes !== '' ? $notes : null,
                'received_by' => $userId > 0 ? $userId : null,
            ]);
            $paymentId = (int) $pdo->lastInsertId();

            $newPaid = billing_round($alreadyPaid + $amount);
            $paymentStatus = 'PARTIAL';
            if ($newPaid <= 0.001) {
                $paymentStatus = 'UNPAID';
            } elseif ($newPaid >= $grandTotal - 0.01) {
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
                $paymentId,
                'PAYMENT_RECORDED',
                $notes !== '' ? $notes : 'Payment received.',
                $userId > 0 ? $userId : null,
                [
                    'amount' => billing_round($amount),
                    'mode' => $paymentMode,
                    'paid_on' => $paidOn,
                    'reference_no' => $referenceNo !== '' ? $referenceNo : null,
                ]
            );

            log_audit('billing', 'payment', $invoiceId, 'Recorded payment of ' . number_format($amount, 2) . ' for invoice ' . (string) $invoice['invoice_number'], [
                'entity' => 'invoice_payment',
                'source' => 'UI',
                'before' => [
                    'invoice_status' => (string) ($invoice['invoice_status'] ?? ''),
                    'payment_status' => (string) ($invoice['payment_status'] ?? 'UNPAID'),
                    'paid_amount' => (float) $alreadyPaid,
                ],
                'after' => [
                    'invoice_status' => (string) ($invoice['invoice_status'] ?? ''),
                    'payment_status' => $paymentStatus,
                    'payment_mode' => (string) ($summaryMode ?? $paymentMode),
                    'paid_amount' => (float) $newPaid,
                    'payment_entry_amount' => (float) billing_round($amount),
                ],
                'metadata' => [
                    'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
                    'payment_id' => $paymentId,
                    'reference_no' => $referenceNo !== '' ? $referenceNo : null,
                ],
            ]);

            $pdo->commit();
            flash_set('billing_success', 'Payment recorded successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('billing_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/billing/index.php');
    }
}

$eligibleJobsStmt = db()->prepare(
    'SELECT jc.id, jc.job_number, jc.closed_at,
            c.full_name AS customer_name, v.registration_no,
            COALESCE((SELECT SUM(total_amount) FROM job_labor jl WHERE jl.job_card_id = jc.id), 0) AS labor_total,
            COALESCE((SELECT SUM(total_amount) FROM job_parts jp WHERE jp.job_card_id = jc.id), 0) AS parts_total
     FROM job_cards jc
     INNER JOIN customers c ON c.id = jc.customer_id
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     LEFT JOIN invoices i ON i.job_card_id = jc.id
     WHERE jc.company_id = :company_id
       AND jc.garage_id = :garage_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND (c.status_code IS NULL OR c.status_code <> "DELETED")
       AND (v.status_code IS NULL OR v.status_code <> "DELETED")
       AND i.id IS NULL
     ORDER BY jc.closed_at DESC, jc.id DESC'
);
$eligibleJobsStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$eligibleJobs = $eligibleJobsStmt->fetchAll();

$invoicesStmt = db()->prepare(
    'SELECT i.id, i.invoice_number, i.invoice_date, i.financial_year_label, i.invoice_status, i.tax_regime,
            i.taxable_amount, i.total_tax_amount, i.grand_total, i.payment_status, i.payment_mode, i.cancel_reason,
            i.snapshot_json,
            c.full_name AS live_customer_name, v.registration_no AS live_registration_no,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id), 0) AS paid_amount
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id
     LEFT JOIN vehicles v ON v.id = i.vehicle_id
     WHERE i.company_id = :company_id
       AND i.garage_id = :garage_id
     ORDER BY i.id DESC'
);
$invoicesStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$invoices = $invoicesStmt->fetchAll();

foreach ($invoices as &$invoice) {
    $snapshot = json_decode((string) ($invoice['snapshot_json'] ?? ''), true);
    if (!is_array($snapshot)) {
        $snapshot = [];
    }
    $invoice['snapshot'] = $snapshot;
    $snapCustomer = billing_snapshot_value($snapshot, 'customer', 'full_name');
    $snapVehicle = billing_snapshot_value($snapshot, 'vehicle', 'registration_no');
    $invoice['display_customer_name'] = $snapCustomer !== '' ? $snapCustomer : (string) ($invoice['live_customer_name'] ?? '-');
    $invoice['display_registration_no'] = $snapVehicle !== '' ? $snapVehicle : (string) ($invoice['live_registration_no'] ?? '-');
}
unset($invoice);

$paymentsStmt = db()->prepare(
    'SELECT p.*, i.invoice_number, u.name AS received_by_name
     FROM payments p
     INNER JOIN invoices i ON i.id = p.invoice_id
     LEFT JOIN users u ON u.id = p.received_by
     WHERE i.company_id = :company_id
       AND i.garage_id = :garage_id
     ORDER BY p.id DESC
     LIMIT 30'
);
$paymentsStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$payments = $paymentsStmt->fetchAll();

$statusHistoryStmt = db()->prepare(
    'SELECT h.*, i.invoice_number, u.name AS actor_name
     FROM invoice_status_history h
     INNER JOIN invoices i ON i.id = h.invoice_id
     LEFT JOIN users u ON u.id = h.created_by
     WHERE i.company_id = :company_id
       AND i.garage_id = :garage_id
     ORDER BY h.id DESC
     LIMIT 40'
);
$statusHistoryStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$statusHistory = $statusHistoryStmt->fetchAll();

$paymentHistoryStmt = db()->prepare(
    'SELECT ph.*, i.invoice_number, p.amount AS payment_amount, p.payment_mode, p.paid_on, u.name AS actor_name
     FROM invoice_payment_history ph
     INNER JOIN invoices i ON i.id = ph.invoice_id
     LEFT JOIN payments p ON p.id = ph.payment_id
     LEFT JOIN users u ON u.id = ph.created_by
     WHERE i.company_id = :company_id
       AND i.garage_id = :garage_id
     ORDER BY ph.id DESC
     LIMIT 40'
);
$paymentHistoryStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$paymentHistory = $paymentHistoryStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Billing & Invoices</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Billing</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canCreate): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title">Generate Draft Invoice from Closed Job Card</h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="create_invoice" />
              <div class="col-md-8">
                <label class="form-label">Eligible Closed Job Card</label>
                <select name="job_card_id" class="form-select" required>
                  <option value="">Select CLOSED Job Card</option>
                  <?php foreach ($eligibleJobs as $job): ?>
                    <option value="<?= (int) $job['id']; ?>">
                      <?= e((string) $job['job_number']); ?> | <?= e((string) $job['customer_name']); ?> | <?= e((string) $job['registration_no']); ?> | Labor <?= e(number_format((float) $job['labor_total'], 2)); ?> + Parts <?= e(number_format((float) $job['parts_total'], 2)); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <small class="text-muted">Only active CLOSED jobs in this garage are billable.</small>
              </div>
              <div class="col-md-2">
                <label class="form-label">Due Date</label>
                <input type="date" name="due_date" class="form-control" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Invoice Date</label>
                <input type="text" class="form-control" value="<?= e(date('Y-m-d')); ?>" readonly />
              </div>
              <div class="col-md-12">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control" placeholder="Optional internal note" />
              </div>
            </div>
            <div class="card-footer">
              <button type="submit" class="btn btn-primary">Create Draft Invoice</button>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <?php if ($canPay): ?>
        <div class="card card-info">
          <div class="card-header"><h3 class="card-title">Record Payment (Finalized Invoices)</h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="add_payment" />
              <div class="col-md-4">
                <label class="form-label">Invoice</label>
                <select name="invoice_id" class="form-select" required>
                  <option value="">Select Invoice</option>
                  <?php foreach ($invoices as $invoice): ?>
                    <?php
                      $invoiceStatus = (string) ($invoice['invoice_status'] ?? '');
                      $outstanding = (float) $invoice['grand_total'] - (float) $invoice['paid_amount'];
                    ?>
                    <?php if ($invoiceStatus === 'FINALIZED' && $outstanding > 0.01): ?>
                      <option value="<?= (int) $invoice['id']; ?>">
                        <?= e((string) $invoice['invoice_number']); ?> | Outstanding: <?= e(number_format($outstanding, 2)); ?>
                      </option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Amount</label>
                <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required />
              </div>
              <div class="col-md-2">
                <label class="form-label">Paid On</label>
                <input type="date" name="paid_on" class="form-control" value="<?= e(date('Y-m-d')); ?>" required />
              </div>
              <div class="col-md-2">
                <label class="form-label">Mode</label>
                <select name="payment_mode" class="form-select" required>
                  <option value="CASH">Cash</option>
                  <option value="UPI">UPI</option>
                  <option value="CARD">Card</option>
                  <option value="BANK_TRANSFER">Bank Transfer</option>
                  <option value="CHEQUE">Cheque</option>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Reference</label>
                <input type="text" name="reference_no" class="form-control" />
              </div>
              <div class="col-md-12">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control" />
              </div>
            </div>
            <div class="card-footer">
              <button type="submit" class="btn btn-info">Save Payment</button>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Invoices</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>Invoice No</th>
                <th>Date</th>
                <th>FY</th>
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Regime</th>
                <th>Taxable</th>
                <th>Tax</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Outstanding</th>
                <th>Invoice Status</th>
                <th>Payment</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($invoices)): ?>
                <tr><td colspan="14" class="text-center text-muted py-4">No invoices generated.</td></tr>
              <?php else: ?>
                <?php foreach ($invoices as $invoice): ?>
                  <?php
                    $paid = (float) $invoice['paid_amount'];
                    $total = (float) $invoice['grand_total'];
                    $outstanding = max(0.0, $total - $paid);
                    $invoiceStatus = (string) ($invoice['invoice_status'] ?? 'DRAFT');
                  ?>
                  <tr>
                    <td>
                      <a href="<?= e(url('modules/billing/print_invoice.php?id=' . (int) $invoice['id'])); ?>" target="_blank">
                        <?= e((string) $invoice['invoice_number']); ?>
                      </a>
                    </td>
                    <td><?= e((string) $invoice['invoice_date']); ?></td>
                    <td><?= e((string) ($invoice['financial_year_label'] ?? '-')); ?></td>
                    <td><?= e((string) $invoice['display_customer_name']); ?></td>
                    <td><?= e((string) $invoice['display_registration_no']); ?></td>
                    <td><?= e((string) ($invoice['tax_regime'] ?? '-')); ?></td>
                    <td><?= e(format_currency((float) $invoice['taxable_amount'])); ?></td>
                    <td><?= e(format_currency((float) $invoice['total_tax_amount'])); ?></td>
                    <td><?= e(format_currency($total)); ?></td>
                    <td><?= e(format_currency($paid)); ?></td>
                    <td><?= e(format_currency($outstanding)); ?></td>
                    <td>
                      <span class="badge text-bg-<?= e(billing_status_badge_class($invoiceStatus)); ?>">
                        <?= e($invoiceStatus); ?>
                      </span>
                      <?php if ($invoiceStatus === 'CANCELLED' && !empty($invoice['cancel_reason'])): ?>
                        <div><small class="text-muted"><?= e((string) $invoice['cancel_reason']); ?></small></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge text-bg-<?= e(billing_payment_badge_class((string) ($invoice['payment_status'] ?? 'UNPAID'))); ?>">
                        <?= e((string) ($invoice['payment_status'] ?? 'UNPAID')); ?>
                      </span>
                      <?php if (!empty($invoice['payment_mode'])): ?>
                        <div><small class="text-muted"><?= e((string) $invoice['payment_mode']); ?></small></div>
                      <?php endif; ?>
                    </td>
                    <td class="d-flex gap-1">
                      <?php if ($canFinalize && $invoiceStatus === 'DRAFT'): ?>
                        <form method="post" class="d-inline" data-confirm="Finalize this invoice? GST values will be locked.">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="finalize_invoice" />
                          <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id']; ?>" />
                          <button type="submit" class="btn btn-sm btn-success">Finalize</button>
                        </form>
                      <?php endif; ?>
                      <?php if ($canCancel && $invoiceStatus !== 'CANCELLED'): ?>
                        <form method="post" class="d-inline js-cancel-invoice-form">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="cancel_invoice" />
                          <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id']; ?>" />
                          <input type="hidden" name="cancel_reason" value="" />
                          <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="row mt-3 g-3">
        <div class="col-lg-6">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Invoice Status History</h3></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Invoice</th>
                    <th>Transition</th>
                    <th>Action</th>
                    <th>Note</th>
                    <th>By</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($statusHistory)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No status events found.</td></tr>
                  <?php else: ?>
                    <?php foreach ($statusHistory as $entry): ?>
                      <tr>
                        <td><?= e((string) $entry['created_at']); ?></td>
                        <td><?= e((string) $entry['invoice_number']); ?></td>
                        <td><?= e((string) (($entry['from_status'] ?? '-') . ' -> ' . ($entry['to_status'] ?? '-'))); ?></td>
                        <td><?= e((string) $entry['action_type']); ?></td>
                        <td><?= e((string) ($entry['action_note'] ?? '-')); ?></td>
                        <td><?= e((string) ($entry['actor_name'] ?? 'System')); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Payment History</h3></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Invoice</th>
                    <th>Paid On</th>
                    <th>Mode</th>
                    <th>Amount</th>
                    <th>By</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($paymentHistory)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No payment history found.</td></tr>
                  <?php else: ?>
                    <?php foreach ($paymentHistory as $entry): ?>
                      <tr>
                        <td><?= e((string) $entry['created_at']); ?></td>
                        <td><?= e((string) $entry['invoice_number']); ?></td>
                        <td><?= e((string) ($entry['paid_on'] ?? '-')); ?></td>
                        <td><?= e((string) ($entry['payment_mode'] ?? '-')); ?></td>
                        <td><?= e(format_currency((float) ($entry['payment_amount'] ?? 0))); ?></td>
                        <td><?= e((string) ($entry['actor_name'] ?? 'System')); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header"><h3 class="card-title">Recent Payment Entries</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Date</th>
                <th>Invoice</th>
                <th>Amount</th>
                <th>Mode</th>
                <th>Reference</th>
                <th>Received By</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($payments)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No payments recorded.</td></tr>
              <?php else: ?>
                <?php foreach ($payments as $payment): ?>
                  <tr>
                    <td><?= e((string) $payment['paid_on']); ?></td>
                    <td><?= e((string) $payment['invoice_number']); ?></td>
                    <td><?= e(format_currency((float) $payment['amount'])); ?></td>
                    <td><?= e((string) $payment['payment_mode']); ?></td>
                    <td><?= e((string) ($payment['reference_no'] ?? '-')); ?></td>
                    <td><?= e((string) ($payment['received_by_name'] ?? '-')); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
  (function () {
    var confirmForms = document.querySelectorAll('form[data-confirm]');
    for (var i = 0; i < confirmForms.length; i++) {
      confirmForms[i].addEventListener('submit', function (event) {
        var message = this.getAttribute('data-confirm') || 'Are you sure?';
        if (!window.confirm(message)) {
          event.preventDefault();
        }
      });
    }

    var cancelForms = document.querySelectorAll('.js-cancel-invoice-form');
    for (var j = 0; j < cancelForms.length; j++) {
      cancelForms[j].addEventListener('submit', function (event) {
        var reason = window.prompt('Cancellation reason is required for audit trail. Enter reason:');
        if (!reason || !reason.trim()) {
          event.preventDefault();
          return;
        }
        var input = this.querySelector('input[name="cancel_reason"]');
        if (input) {
          input.value = reason.trim();
        }
      });
    }
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
