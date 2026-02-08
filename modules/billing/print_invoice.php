<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('invoice.view');

$companyId = active_company_id();
$invoiceId = get_int('id');

if ($invoiceId <= 0) {
    flash_set('billing_error', 'Invalid invoice id.', 'danger');
    redirect('modules/billing/index.php');
}

$invoiceStmt = db()->prepare(
    'SELECT i.*, c.name AS company_name, c.gstin AS company_gstin, c.address_line1 AS company_address, c.city AS company_city, c.state AS company_state,
            g.name AS garage_name,
            cu.full_name AS customer_name, cu.phone AS customer_phone, cu.gstin AS customer_gstin, cu.address_line1 AS customer_address,
            v.registration_no, v.brand, v.model
     FROM invoices i
     INNER JOIN companies c ON c.id = i.company_id
     INNER JOIN garages g ON g.id = i.garage_id
     INNER JOIN customers cu ON cu.id = i.customer_id
     INNER JOIN vehicles v ON v.id = i.vehicle_id
     WHERE i.id = :invoice_id
       AND i.company_id = :company_id
     LIMIT 1'
);
$invoiceStmt->execute([
    'invoice_id' => $invoiceId,
    'company_id' => $companyId,
]);
$invoice = $invoiceStmt->fetch();

if (!$invoice) {
    flash_set('billing_error', 'Invoice not found.', 'danger');
    redirect('modules/billing/index.php');
}

$itemsStmt = db()->prepare(
    'SELECT *
     FROM invoice_items
     WHERE invoice_id = :invoice_id
     ORDER BY id ASC'
);
$itemsStmt->execute(['invoice_id' => $invoiceId]);
$items = $itemsStmt->fetchAll();

$paymentsStmt = db()->prepare(
    'SELECT paid_on, amount, payment_mode, reference_no
     FROM payments
     WHERE invoice_id = :invoice_id
     ORDER BY id ASC'
);
$paymentsStmt->execute(['invoice_id' => $invoiceId]);
$payments = $paymentsStmt->fetchAll();

$paidAmount = 0.0;
foreach ($payments as $payment) {
    $paidAmount += (float) $payment['amount'];
}
$outstanding = max(0.0, (float) $invoice['grand_total'] - $paidAmount);
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Invoice <?= e((string) $invoice['invoice_number']); ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/adminlte.min.css')); ?>" />
    <style>
      @media print {
        .no-print {
          display: none !important;
        }
      }

      body {
        padding: 20px;
      }

      .invoice-title {
        font-size: 1.3rem;
        font-weight: 700;
      }
    </style>
  </head>
  <body>
    <div class="container-fluid">
      <div class="d-flex justify-content-between mb-3 no-print">
        <a href="<?= e(url('modules/billing/index.php')); ?>" class="btn btn-outline-secondary btn-sm">Back</a>
        <button onclick="window.print()" class="btn btn-primary btn-sm">Print / Save PDF</button>
      </div>

      <div class="card">
        <div class="card-body">
          <div class="row mb-3">
            <div class="col-8">
              <div class="invoice-title"><?= e((string) $invoice['company_name']); ?></div>
              <div><?= e((string) ($invoice['company_address'] ?? '')); ?>, <?= e((string) ($invoice['company_city'] ?? '')); ?>, <?= e((string) ($invoice['company_state'] ?? '')); ?></div>
              <div>GSTIN: <?= e((string) ($invoice['company_gstin'] ?? '-')); ?></div>
            </div>
            <div class="col-4 text-end">
              <h4 class="mb-1">Tax Invoice</h4>
              <div><strong>Invoice:</strong> <?= e((string) $invoice['invoice_number']); ?></div>
              <div><strong>Date:</strong> <?= e((string) $invoice['invoice_date']); ?></div>
              <div><strong>Garage:</strong> <?= e((string) $invoice['garage_name']); ?></div>
            </div>
          </div>

          <div class="row mb-3">
            <div class="col-6">
              <h6 class="mb-1">Bill To</h6>
              <div><strong><?= e((string) $invoice['customer_name']); ?></strong></div>
              <div>Phone: <?= e((string) ($invoice['customer_phone'] ?? '-')); ?></div>
              <div>GSTIN: <?= e((string) ($invoice['customer_gstin'] ?? '-')); ?></div>
              <div><?= e((string) ($invoice['customer_address'] ?? '')); ?></div>
            </div>
            <div class="col-6 text-end">
              <h6 class="mb-1">Vehicle</h6>
              <div><?= e((string) $invoice['registration_no']); ?></div>
              <div><?= e((string) $invoice['brand']); ?> <?= e((string) $invoice['model']); ?></div>
            </div>
          </div>

          <div class="table-responsive mb-3">
            <table class="table table-bordered table-sm">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Type</th>
                  <th>Description</th>
                  <th class="text-end">Qty</th>
                  <th class="text-end">Rate</th>
                  <th class="text-end">GST%</th>
                  <th class="text-end">Taxable</th>
                  <th class="text-end">Tax</th>
                  <th class="text-end">Total</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($items)): ?>
                  <tr><td colspan="9" class="text-center text-muted">No invoice items.</td></tr>
                <?php else: ?>
                  <?php $lineNo = 1; ?>
                  <?php foreach ($items as $item): ?>
                    <tr>
                      <td><?= $lineNo++; ?></td>
                      <td><?= e((string) $item['item_type']); ?></td>
                      <td><?= e((string) $item['description']); ?></td>
                      <td class="text-end"><?= e((string) $item['quantity']); ?></td>
                      <td class="text-end"><?= e(number_format((float) $item['unit_price'], 2)); ?></td>
                      <td class="text-end"><?= e(number_format((float) $item['gst_rate'], 2)); ?></td>
                      <td class="text-end"><?= e(number_format((float) $item['taxable_value'], 2)); ?></td>
                      <td class="text-end"><?= e(number_format((float) $item['tax_amount'], 2)); ?></td>
                      <td class="text-end"><?= e(number_format((float) $item['total_value'], 2)); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="row">
            <div class="col-7">
              <h6 class="mb-1">Payment Summary</h6>
              <table class="table table-sm table-bordered">
                <thead>
                  <tr><th>Date</th><th>Mode</th><th>Reference</th><th class="text-end">Amount</th></tr>
                </thead>
                <tbody>
                  <?php if (empty($payments)): ?>
                    <tr><td colspan="4" class="text-muted">No payments recorded.</td></tr>
                  <?php else: ?>
                    <?php foreach ($payments as $payment): ?>
                      <tr>
                        <td><?= e((string) $payment['paid_on']); ?></td>
                        <td><?= e((string) $payment['payment_mode']); ?></td>
                        <td><?= e((string) ($payment['reference_no'] ?? '-')); ?></td>
                        <td class="text-end"><?= e(number_format((float) $payment['amount'], 2)); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <div class="col-5">
              <table class="table table-sm table-bordered">
                <tr><th>Service Subtotal</th><td class="text-end"><?= e(number_format((float) $invoice['subtotal_service'], 2)); ?></td></tr>
                <tr><th>Parts Subtotal</th><td class="text-end"><?= e(number_format((float) $invoice['subtotal_parts'], 2)); ?></td></tr>
                <tr><th>CGST</th><td class="text-end"><?= e(number_format((float) $invoice['cgst_amount'], 2)); ?></td></tr>
                <tr><th>SGST</th><td class="text-end"><?= e(number_format((float) $invoice['sgst_amount'], 2)); ?></td></tr>
                <tr><th>IGST</th><td class="text-end"><?= e(number_format((float) $invoice['igst_amount'], 2)); ?></td></tr>
                <tr><th>Round Off</th><td class="text-end"><?= e(number_format((float) $invoice['round_off'], 2)); ?></td></tr>
                <tr><th>Grand Total</th><td class="text-end"><strong><?= e(number_format((float) $invoice['grand_total'], 2)); ?></strong></td></tr>
                <tr><th>Paid</th><td class="text-end"><?= e(number_format($paidAmount, 2)); ?></td></tr>
                <tr><th>Outstanding</th><td class="text-end"><?= e(number_format($outstanding, 2)); ?></td></tr>
              </table>
            </div>
          </div>

          <p class="mb-0 text-muted">Generated from Guru Auto Cars ERP. This invoice can be printed or saved as PDF.</p>
        </div>
      </div>
    </div>
  </body>
</html>
