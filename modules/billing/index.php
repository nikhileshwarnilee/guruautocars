<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('invoice.view');

$page_title = 'Billing & Invoices';
$active_menu = 'billing';
$companyId = active_company_id();
$garageId = active_garage_id();

$canManage = has_permission('invoice.manage');
$canPay = has_permission('invoice.pay');

function generate_invoice_number(PDO $pdo, int $garageId): string
{
    $counterStmt = $pdo->prepare('SELECT prefix, current_number FROM invoice_counters WHERE garage_id = :garage_id FOR UPDATE');
    $counterStmt->execute(['garage_id' => $garageId]);
    $counter = $counterStmt->fetch();

    if (!$counter) {
        $insertCounter = $pdo->prepare('INSERT INTO invoice_counters (garage_id, prefix, current_number) VALUES (:garage_id, "INV", 5000)');
        $insertCounter->execute(['garage_id' => $garageId]);
        $counter = ['prefix' => 'INV', 'current_number' => 5000];
    }

    $nextNumber = ((int) $counter['current_number']) + 1;

    $updateStmt = $pdo->prepare('UPDATE invoice_counters SET current_number = :current_number WHERE garage_id = :garage_id');
    $updateStmt->execute([
        'current_number' => $nextNumber,
        'garage_id' => $garageId,
    ]);

    $prefix = (string) $counter['prefix'];
    return sprintf('%s-%s-%05d', $prefix, date('ym'), $nextNumber);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create_invoice' && $canManage) {
        $jobCardId = post_int('job_card_id');
        $serviceGstRate = (float) ($_POST['service_gst_rate'] ?? 18);
        $isInterstate = post_int('is_interstate', 0) === 1 ? 1 : 0;
        $dueDateInput = post_string('due_date', 10);
        $notes = post_string('notes', 1000);

        if ($jobCardId <= 0) {
            flash_set('billing_error', 'Select a job card.', 'danger');
            redirect('modules/billing/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $jobStmt = $pdo->prepare(
                'SELECT jc.id, jc.customer_id, jc.vehicle_id, jc.status
                 FROM job_cards jc
                 WHERE jc.id = :job_id
                   AND jc.company_id = :company_id
                   AND jc.garage_id = :garage_id
                 FOR UPDATE'
            );
            $jobStmt->execute([
                'job_id' => $jobCardId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            $job = $jobStmt->fetch();

            if (!$job) {
                throw new RuntimeException('Job card not found.');
            }

            $existingInvoiceStmt = $pdo->prepare('SELECT id FROM invoices WHERE job_card_id = :job_id LIMIT 1');
            $existingInvoiceStmt->execute(['job_id' => $jobCardId]);
            if ($existingInvoiceStmt->fetch()) {
                throw new RuntimeException('Invoice already exists for this job card.');
            }

            $laborLinesStmt = $pdo->prepare('SELECT description, quantity, unit_price, total_amount FROM job_labor WHERE job_card_id = :job_id');
            $laborLinesStmt->execute(['job_id' => $jobCardId]);
            $laborLines = $laborLinesStmt->fetchAll();

            $partLinesStmt = $pdo->prepare(
                'SELECT jp.part_id, p.part_name, jp.quantity, jp.unit_price, jp.gst_rate, jp.total_amount
                 FROM job_parts jp
                 INNER JOIN parts p ON p.id = jp.part_id
                 WHERE jp.job_card_id = :job_id'
            );
            $partLinesStmt->execute(['job_id' => $jobCardId]);
            $partLines = $partLinesStmt->fetchAll();

            $serviceSubtotal = 0.0;
            foreach ($laborLines as $line) {
                $serviceSubtotal += (float) $line['total_amount'];
            }

            $partsSubtotal = 0.0;
            $partsTaxTotal = 0.0;
            foreach ($partLines as $line) {
                $lineTotal = (float) $line['total_amount'];
                $lineRate = (float) $line['gst_rate'];
                $partsSubtotal += $lineTotal;
                $partsTaxTotal += ($lineTotal * $lineRate / 100);
            }

            if ($serviceSubtotal <= 0 && $partsSubtotal <= 0) {
                throw new RuntimeException('No labor or parts found for billing.');
            }

            $serviceTaxTotal = $serviceSubtotal * $serviceGstRate / 100;
            $totalTaxAmount = $serviceTaxTotal + $partsTaxTotal;
            $taxableAmount = $serviceSubtotal + $partsSubtotal;

            $cgstRate = 0.0;
            $sgstRate = 0.0;
            $igstRate = 0.0;
            $cgstAmount = 0.0;
            $sgstAmount = 0.0;
            $igstAmount = 0.0;

            if ($isInterstate === 1) {
                $igstRate = $serviceGstRate;
                $igstAmount = round($totalTaxAmount, 2);
            } else {
                $cgstRate = round($serviceGstRate / 2, 2);
                $sgstRate = round($serviceGstRate / 2, 2);
                $cgstAmount = round($totalTaxAmount / 2, 2);
                $sgstAmount = round($totalTaxAmount - $cgstAmount, 2);
            }

            $grossTotal = $taxableAmount + $totalTaxAmount;
            $roundedTotal = round($grossTotal, 0);
            $roundOff = round($roundedTotal - $grossTotal, 2);
            $grandTotal = round($grossTotal + $roundOff, 2);

            $invoiceNumber = generate_invoice_number($pdo, $garageId);
            $invoiceDate = date('Y-m-d');
            $dueDate = $dueDateInput !== '' ? $dueDateInput : null;

            $invoiceInsert = $pdo->prepare(
                'INSERT INTO invoices
                  (company_id, garage_id, invoice_number, job_card_id, customer_id, vehicle_id, invoice_date, due_date,
                   subtotal_service, subtotal_parts, taxable_amount,
                   cgst_rate, sgst_rate, igst_rate,
                   cgst_amount, sgst_amount, igst_amount,
                   round_off, grand_total, payment_status, notes, created_by)
                 VALUES
                  (:company_id, :garage_id, :invoice_number, :job_card_id, :customer_id, :vehicle_id, :invoice_date, :due_date,
                   :subtotal_service, :subtotal_parts, :taxable_amount,
                   :cgst_rate, :sgst_rate, :igst_rate,
                   :cgst_amount, :sgst_amount, :igst_amount,
                   :round_off, :grand_total, "UNPAID", :notes, :created_by)'
            );
            $invoiceInsert->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'invoice_number' => $invoiceNumber,
                'job_card_id' => $jobCardId,
                'customer_id' => (int) $job['customer_id'],
                'vehicle_id' => (int) $job['vehicle_id'],
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'subtotal_service' => round($serviceSubtotal, 2),
                'subtotal_parts' => round($partsSubtotal, 2),
                'taxable_amount' => round($taxableAmount, 2),
                'cgst_rate' => $cgstRate,
                'sgst_rate' => $sgstRate,
                'igst_rate' => $igstRate,
                'cgst_amount' => $cgstAmount,
                'sgst_amount' => $sgstAmount,
                'igst_amount' => $igstAmount,
                'round_off' => $roundOff,
                'grand_total' => $grandTotal,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => (int) $_SESSION['user_id'],
            ]);

            $invoiceId = (int) $pdo->lastInsertId();

            $itemInsert = $pdo->prepare(
                'INSERT INTO invoice_items
                  (invoice_id, item_type, description, part_id, quantity, unit_price, gst_rate, taxable_value, tax_amount, total_value)
                 VALUES
                  (:invoice_id, :item_type, :description, :part_id, :quantity, :unit_price, :gst_rate, :taxable_value, :tax_amount, :total_value)'
            );

            foreach ($laborLines as $line) {
                $taxable = (float) $line['total_amount'];
                $taxAmount = round($taxable * $serviceGstRate / 100, 2);
                $itemInsert->execute([
                    'invoice_id' => $invoiceId,
                    'item_type' => 'LABOR',
                    'description' => (string) $line['description'],
                    'part_id' => null,
                    'quantity' => (float) $line['quantity'],
                    'unit_price' => (float) $line['unit_price'],
                    'gst_rate' => $serviceGstRate,
                    'taxable_value' => round($taxable, 2),
                    'tax_amount' => $taxAmount,
                    'total_value' => round($taxable + $taxAmount, 2),
                ]);
            }

            foreach ($partLines as $line) {
                $taxable = (float) $line['total_amount'];
                $rate = (float) $line['gst_rate'];
                $taxAmount = round($taxable * $rate / 100, 2);
                $itemInsert->execute([
                    'invoice_id' => $invoiceId,
                    'item_type' => 'PART',
                    'description' => (string) $line['part_name'],
                    'part_id' => (int) $line['part_id'],
                    'quantity' => (float) $line['quantity'],
                    'unit_price' => (float) $line['unit_price'],
                    'gst_rate' => $rate,
                    'taxable_value' => round($taxable, 2),
                    'tax_amount' => $taxAmount,
                    'total_value' => round($taxable + $taxAmount, 2),
                ]);
            }

            if ((string) $job['status'] !== 'COMPLETED') {
                $jobUpdate = $pdo->prepare('UPDATE job_cards SET status = "COMPLETED", completed_at = NOW(), updated_by = :updated_by WHERE id = :job_id');
                $jobUpdate->execute([
                    'updated_by' => (int) $_SESSION['user_id'],
                    'job_id' => $jobCardId,
                ]);
            }

            $pdo->commit();
            flash_set('billing_success', 'Invoice created: ' . $invoiceNumber, 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('billing_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/billing/index.php');
    }

    if ($action === 'add_payment' && $canPay) {
        $invoiceId = post_int('invoice_id');
        $amount = (float) ($_POST['amount'] ?? 0);
        $paidOn = post_string('paid_on', 10);
        $paymentMode = (string) ($_POST['payment_mode'] ?? 'CASH');
        $referenceNo = post_string('reference_no', 100);
        $notes = post_string('notes', 255);

        $allowedModes = ['CASH', 'UPI', 'CARD', 'BANK_TRANSFER', 'CHEQUE'];
        if (!in_array($paymentMode, $allowedModes, true)) {
            $paymentMode = 'CASH';
        }

        if ($invoiceId <= 0 || $amount <= 0 || $paidOn === '') {
            flash_set('billing_error', 'Invoice, payment amount and paid date are required.', 'danger');
            redirect('modules/billing/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $invoiceStmt = $pdo->prepare(
                'SELECT id, grand_total
                 FROM invoices
                 WHERE id = :invoice_id
                   AND company_id = :company_id
                   AND garage_id = :garage_id
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

            $paidSumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :invoice_id');
            $paidSumStmt->execute(['invoice_id' => $invoiceId]);
            $alreadyPaid = (float) $paidSumStmt->fetchColumn();
            $grandTotal = (float) $invoice['grand_total'];
            $remaining = $grandTotal - $alreadyPaid;

            if ($amount > $remaining + 0.01) {
                throw new RuntimeException('Payment exceeds outstanding amount. Outstanding: ' . number_format($remaining, 2));
            }

            $paymentInsert = $pdo->prepare(
                'INSERT INTO payments
                  (invoice_id, amount, paid_on, payment_mode, reference_no, notes, received_by)
                 VALUES
                  (:invoice_id, :amount, :paid_on, :payment_mode, :reference_no, :notes, :received_by)'
            );
            $paymentInsert->execute([
                'invoice_id' => $invoiceId,
                'amount' => round($amount, 2),
                'paid_on' => $paidOn,
                'payment_mode' => $paymentMode,
                'reference_no' => $referenceNo !== '' ? $referenceNo : null,
                'notes' => $notes !== '' ? $notes : null,
                'received_by' => (int) $_SESSION['user_id'],
            ]);

            $newPaid = $alreadyPaid + $amount;
            $status = 'PARTIAL';
            if ($newPaid <= 0.001) {
                $status = 'UNPAID';
            } elseif ($newPaid >= $grandTotal - 0.01) {
                $status = 'PAID';
            }

            $invoiceUpdate = $pdo->prepare(
                'UPDATE invoices
                 SET payment_status = :payment_status,
                     payment_mode = :payment_mode
                 WHERE id = :invoice_id'
            );
            $invoiceUpdate->execute([
                'payment_status' => $status,
                'payment_mode' => $paymentMode,
                'invoice_id' => $invoiceId,
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
    'SELECT jc.id, jc.job_number, jc.status, c.full_name AS customer_name, v.registration_no,
            COALESCE((SELECT SUM(total_amount) FROM job_labor jl WHERE jl.job_card_id = jc.id), 0) AS labor_total,
            COALESCE((SELECT SUM(total_amount) FROM job_parts jp WHERE jp.job_card_id = jc.id), 0) AS parts_total
     FROM job_cards jc
     INNER JOIN customers c ON c.id = jc.customer_id
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     LEFT JOIN invoices i ON i.job_card_id = jc.id
     WHERE jc.company_id = :company_id
       AND jc.garage_id = :garage_id
       AND jc.status IN ("READY_FOR_DELIVERY", "COMPLETED")
       AND i.id IS NULL
     ORDER BY jc.id DESC'
);
$eligibleJobsStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$eligibleJobs = $eligibleJobsStmt->fetchAll();

$invoicesStmt = db()->prepare(
    'SELECT i.id, i.invoice_number, i.invoice_date, i.grand_total, i.payment_status, i.payment_mode,
            c.full_name AS customer_name, v.registration_no,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id), 0) AS paid_amount
     FROM invoices i
     INNER JOIN customers c ON c.id = i.customer_id
     INNER JOIN vehicles v ON v.id = i.vehicle_id
     WHERE i.company_id = :company_id
       AND i.garage_id = :garage_id
     ORDER BY i.id DESC'
);
$invoicesStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$invoices = $invoicesStmt->fetchAll();

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
      <?php if ($canManage): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title">Generate Invoice from Job Card</h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="create_invoice" />
              <div class="col-md-6">
                <label class="form-label">Eligible Job Card</label>
                <select name="job_card_id" class="form-select" required>
                  <option value="">Select Job Card</option>
                  <?php foreach ($eligibleJobs as $job): ?>
                    <option value="<?= (int) $job['id']; ?>">
                      <?= e((string) $job['job_number']); ?> | <?= e((string) $job['customer_name']); ?> | <?= e((string) $job['registration_no']); ?> | Labor <?= e(number_format((float) $job['labor_total'], 2)); ?> + Parts <?= e(number_format((float) $job['parts_total'], 2)); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Service GST %</label>
                <input type="number" step="0.01" name="service_gst_rate" class="form-control" value="18" required />
              </div>
              <div class="col-md-2">
                <label class="form-label">Due Date</label>
                <input type="date" name="due_date" class="form-control" />
              </div>
              <div class="col-md-2 d-flex align-items-center mt-4">
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" value="1" id="is_interstate" name="is_interstate" />
                  <label class="form-check-label" for="is_interstate">Interstate (IGST)</label>
                </div>
              </div>
              <div class="col-md-12">
                <label class="form-label">Invoice Notes</label>
                <input type="text" name="notes" class="form-control" placeholder="Optional notes" />
              </div>
            </div>
            <div class="card-footer">
              <button type="submit" class="btn btn-primary">Create Invoice</button>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <?php if ($canPay): ?>
        <div class="card card-info">
          <div class="card-header"><h3 class="card-title">Record Payment</h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="add_payment" />
              <div class="col-md-4">
                <label class="form-label">Invoice</label>
                <select name="invoice_id" class="form-select" required>
                  <option value="">Select Invoice</option>
                  <?php foreach ($invoices as $invoice): ?>
                    <?php $outstanding = (float) $invoice['grand_total'] - (float) $invoice['paid_amount']; ?>
                    <?php if ($outstanding > 0.01): ?>
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
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Outstanding</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($invoices)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No invoices generated.</td></tr>
              <?php else: ?>
                <?php foreach ($invoices as $invoice): ?>
                  <?php $paid = (float) $invoice['paid_amount']; ?>
                  <?php $total = (float) $invoice['grand_total']; ?>
                  <?php $outstanding = $total - $paid; ?>
                  <tr>
                    <td>
                      <a href="<?= e(url('modules/billing/print_invoice.php?id=' . (int) $invoice['id'])); ?>" target="_blank">
                        <?= e((string) $invoice['invoice_number']); ?>
                      </a>
                    </td>
                    <td><?= e((string) $invoice['invoice_date']); ?></td>
                    <td><?= e((string) $invoice['customer_name']); ?></td>
                    <td><?= e((string) $invoice['registration_no']); ?></td>
                    <td><?= e(format_currency($total)); ?></td>
                    <td><?= e(format_currency($paid)); ?></td>
                    <td><?= e(format_currency($outstanding)); ?></td>
                    <td><span class="badge text-bg-secondary"><?= e((string) $invoice['payment_status']); ?></span></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header"><h3 class="card-title">Recent Payments</h3></div>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
