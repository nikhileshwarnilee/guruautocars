<?php
declare(strict_types=1);

if (!is_dir(__DIR__ . '/tmp_sessions')) {
    @mkdir(__DIR__ . '/tmp_sessions', 0777, true);
}
if (is_dir(__DIR__ . '/tmp_sessions')) {
    ini_set('session.save_path', __DIR__ . '/tmp_sessions');
}

require_once __DIR__ . '/includes/app.php';

/**
 * Lightweight smoke validation for reversal-first integrity rules.
 * All writes happen inside a DB transaction and are rolled back.
 */

$pdo = db();
$results = [];
$failed = false;

$record = static function (string $label, bool $ok, string $detail) use (&$results, &$failed): void {
    $results[] = sprintf('[%s] %s - %s', $ok ? 'PASS' : 'FAIL', $label, $detail);
    if (!$ok) {
        $failed = true;
    }
};

try {
    $garageStmt = $pdo->query('SELECT id, company_id FROM garages ORDER BY id ASC LIMIT 1');
    $garageRow = $garageStmt->fetch();
    if (!$garageRow) {
        throw new RuntimeException('No garage found for smoke test.');
    }

    $garageId = (int) ($garageRow['id'] ?? 0);
    $companyId = (int) ($garageRow['company_id'] ?? 0);
    if ($garageId <= 0 || $companyId <= 0) {
        throw new RuntimeException('Invalid company/garage scope for smoke test.');
    }

    $userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
    $customerStmt = $pdo->prepare(
        'SELECT id
         FROM customers
         WHERE company_id = :company_id
           AND (status_code IS NULL OR status_code <> "DELETED")
         ORDER BY id ASC
         LIMIT 1'
    );
    $customerStmt->execute(['company_id' => $companyId]);
    $customerId = (int) ($customerStmt->fetchColumn() ?: 0);

    $vehicleStmt = $pdo->prepare(
        'SELECT id
         FROM vehicles
         WHERE company_id = :company_id
           AND (status_code IS NULL OR status_code <> "DELETED")
         ORDER BY id ASC
         LIMIT 1'
    );
    $vehicleStmt->execute(['company_id' => $companyId]);
    $vehicleId = (int) ($vehicleStmt->fetchColumn() ?: 0);

    if ($customerId <= 0 || $vehicleId <= 0) {
        throw new RuntimeException('Missing active customer/vehicle fixtures for smoke test.');
    }

    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');
    $stamp = date('YmdHis');

    $pdo->beginTransaction();

    // Scenario: Delete purchase without payments.
    $purchaseInsert = $pdo->prepare(
        'INSERT INTO purchases
          (company_id, garage_id, vendor_id, invoice_number, purchase_date, purchase_source, assignment_status, purchase_status, payment_status,
           status_code, taxable_amount, gst_amount, grand_total, notes, created_by, finalized_by, finalized_at)
         VALUES
          (:company_id, :garage_id, NULL, :invoice_number, :purchase_date, "VENDOR_ENTRY", "ASSIGNED", "FINALIZED", "UNPAID",
           "ACTIVE", :taxable_amount, :gst_amount, :grand_total, :notes, :created_by, :finalized_by, :finalized_at)'
    );
    $purchaseInsert->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'invoice_number' => 'SMK-NOPAY-' . $stamp,
        'purchase_date' => $today,
        'taxable_amount' => 100.00,
        'gst_amount' => 0.00,
        'grand_total' => 100.00,
        'notes' => 'Smoke no-payment purchase',
        'created_by' => $userId > 0 ? $userId : null,
        'finalized_by' => $userId > 0 ? $userId : null,
        'finalized_at' => $now,
    ]);
    $purchaseNoPaymentId = (int) $pdo->lastInsertId();

    $purchaseNoPaymentReport = reversal_purchase_delete_dependency_report($pdo, $purchaseNoPaymentId, $companyId, $garageId);
    $record(
        'Delete purchase without payments',
        (bool) ($purchaseNoPaymentReport['can_delete'] ?? false),
        'can_delete=' . ((bool) ($purchaseNoPaymentReport['can_delete'] ?? false) ? 'true' : 'false')
    );

    // Scenario: Block purchase delete with payment.
    $purchaseInsert->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'invoice_number' => 'SMK-PAY-' . $stamp,
        'purchase_date' => $today,
        'taxable_amount' => 200.00,
        'gst_amount' => 0.00,
        'grand_total' => 200.00,
        'notes' => 'Smoke paid purchase',
        'created_by' => $userId > 0 ? $userId : null,
        'finalized_by' => $userId > 0 ? $userId : null,
        'finalized_at' => $now,
    ]);
    $purchaseWithPaymentId = (int) $pdo->lastInsertId();

    $purchasePaymentInsert = $pdo->prepare(
        'INSERT INTO purchase_payments
          (purchase_id, company_id, garage_id, payment_date, entry_type, amount, payment_mode, reference_no, notes, created_by)
         VALUES
          (:purchase_id, :company_id, :garage_id, :payment_date, "PAYMENT", :amount, "BANK_TRANSFER", :reference_no, :notes, :created_by)'
    );
    $purchasePaymentInsert->execute([
        'purchase_id' => $purchaseWithPaymentId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'payment_date' => $today,
        'amount' => 100.00,
        'reference_no' => 'SMK-PURPAY-' . $stamp,
        'notes' => 'Smoke payment',
        'created_by' => $userId > 0 ? $userId : null,
    ]);

    $purchaseWithPaymentReport = reversal_purchase_delete_dependency_report($pdo, $purchaseWithPaymentId, $companyId, $garageId);
    $record(
        'Block purchase delete with payments',
        !(bool) ($purchaseWithPaymentReport['can_delete'] ?? true),
        'can_delete=' . ((bool) ($purchaseWithPaymentReport['can_delete'] ?? false) ? 'true' : 'false')
    );

    // Create one dependency-free job card and one invoice-linked job card.
    $jobInsert = $pdo->prepare(
        'INSERT INTO job_cards
          (company_id, garage_id, job_number, customer_id, vehicle_id, complaint, status, priority, estimated_cost, opened_at, status_code, created_by, updated_by)
         VALUES
          (:company_id, :garage_id, :job_number, :customer_id, :vehicle_id, :complaint, :status, "MEDIUM", 0.00, :opened_at, "ACTIVE", :created_by, :updated_by)'
    );

    $jobInsert->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'job_number' => 'SMK-JOB-A-' . $stamp,
        'customer_id' => $customerId,
        'vehicle_id' => $vehicleId,
        'complaint' => 'Smoke job without dependencies',
        'status' => 'OPEN',
        'opened_at' => $now,
        'created_by' => $userId > 0 ? $userId : null,
        'updated_by' => $userId > 0 ? $userId : null,
    ]);
    $jobNoDependencyId = (int) $pdo->lastInsertId();

    $jobInsert->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'job_number' => 'SMK-JOB-B-' . $stamp,
        'customer_id' => $customerId,
        'vehicle_id' => $vehicleId,
        'complaint' => 'Smoke job with invoice dependency',
        'status' => 'CLOSED',
        'opened_at' => $now,
        'created_by' => $userId > 0 ? $userId : null,
        'updated_by' => $userId > 0 ? $userId : null,
    ]);
    $jobWithInvoiceId = (int) $pdo->lastInsertId();

    $invoiceInsert = $pdo->prepare(
        'INSERT INTO invoices
          (company_id, garage_id, invoice_number, job_card_id, customer_id, vehicle_id, invoice_date, invoice_status,
           subtotal_service, subtotal_parts, taxable_amount, tax_regime,
           cgst_rate, sgst_rate, igst_rate, cgst_amount, sgst_amount, igst_amount,
           service_tax_amount, parts_tax_amount, total_tax_amount, gross_total, round_off, grand_total,
           payment_status, notes, created_by)
         VALUES
          (:company_id, :garage_id, :invoice_number, :job_card_id, :customer_id, :vehicle_id, :invoice_date, "FINALIZED",
           100.00, 0.00, 100.00, "INTRASTATE",
           0.00, 0.00, 0.00, 0.00, 0.00, 0.00,
           0.00, 0.00, 0.00, 100.00, 0.00, 100.00,
           "PARTIAL", :notes, :created_by)'
    );
    $invoiceInsert->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'invoice_number' => 'SMK-INV-' . $stamp,
        'job_card_id' => $jobWithInvoiceId,
        'customer_id' => $customerId,
        'vehicle_id' => $vehicleId,
        'invoice_date' => $today,
        'notes' => 'Smoke invoice with payment',
        'created_by' => $userId > 0 ? $userId : null,
    ]);
    $invoiceId = (int) $pdo->lastInsertId();

    $paymentInsert = $pdo->prepare(
        'INSERT INTO payments
          (invoice_id, entry_type, amount, paid_on, payment_mode, reference_no, notes, is_reversed, received_by)
         VALUES
          (:invoice_id, "PAYMENT", :amount, :paid_on, "BANK_TRANSFER", :reference_no, :notes, 0, :received_by)'
    );
    $paymentInsert->execute([
        'invoice_id' => $invoiceId,
        'amount' => 100.00,
        'paid_on' => $today,
        'reference_no' => 'SMK-PAY-' . $stamp,
        'notes' => 'Smoke payment',
        'received_by' => $userId > 0 ? $userId : null,
    ]);
    $paymentId = (int) $pdo->lastInsertId();

    $cancelBefore = reversal_invoice_cancel_dependency_report($pdo, $invoiceId, $companyId, $garageId);
    $record(
        'Invoice cancel blocked with unreversed payment',
        !(bool) ($cancelBefore['can_cancel'] ?? true),
        'can_cancel=' . ((bool) ($cancelBefore['can_cancel'] ?? false) ? 'true' : 'false')
    );

    // Scenario: Reverse payment then cancel eligibility.
    $reverseInsert = $pdo->prepare(
        'INSERT INTO payments
          (invoice_id, entry_type, amount, paid_on, payment_mode, reference_no, notes, reversed_payment_id, is_reversed, received_by)
         VALUES
          (:invoice_id, "REVERSAL", :amount, :paid_on, :payment_mode, :reference_no, :notes, :reversed_payment_id, 0, :received_by)'
    );
    $reverseInsert->execute([
        'invoice_id' => $invoiceId,
        'amount' => -100.00,
        'paid_on' => $today,
        'payment_mode' => 'BANK_TRANSFER',
        'reference_no' => 'SMK-REV-' . $stamp,
        'notes' => 'Smoke reversal',
        'reversed_payment_id' => $paymentId,
        'received_by' => $userId > 0 ? $userId : null,
    ]);

    $markReversedStmt = $pdo->prepare(
        'UPDATE payments
         SET is_reversed = 1,
             reversed_at = :reversed_at,
             reversed_by = :reversed_by,
             reverse_reason = :reverse_reason
         WHERE id = :id'
    );
    $markReversedStmt->execute([
        'reversed_at' => $now,
        'reversed_by' => $userId > 0 ? $userId : null,
        'reverse_reason' => 'Smoke reversal',
        'id' => $paymentId,
    ]);

    $cancelAfter = reversal_invoice_cancel_dependency_report($pdo, $invoiceId, $companyId, $garageId);
    $record(
        'Reverse payment then cancel invoice eligibility',
        (bool) ($cancelAfter['can_cancel'] ?? false),
        'can_cancel=' . ((bool) ($cancelAfter['can_cancel'] ?? false) ? 'true' : 'false')
    );

    $jobNoDependencyReport = reversal_job_delete_dependency_report($pdo, $jobNoDependencyId, $companyId, $garageId);
    $record(
        'Delete job with no dependencies',
        (bool) ($jobNoDependencyReport['can_delete'] ?? false),
        'can_delete=' . ((bool) ($jobNoDependencyReport['can_delete'] ?? false) ? 'true' : 'false')
    );

    $jobWithInvoiceReport = reversal_job_delete_dependency_report($pdo, $jobWithInvoiceId, $companyId, $garageId);
    $record(
        'Block job delete with invoice',
        !(bool) ($jobWithInvoiceReport['can_delete'] ?? true),
        'can_delete=' . ((bool) ($jobWithInvoiceReport['can_delete'] ?? false) ? 'true' : 'false')
    );

    $pdo->rollBack();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $record('Smoke execution', false, $exception->getMessage());
}

foreach ($results as $line) {
    echo $line . PHP_EOL;
}

exit($failed ? 1 : 0);
