<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/workflow.php';

if (!billing_can_view()) {
    flash_set('access_denied', 'You do not have permission to access billing invoices.', 'danger');
    redirect('dashboard.php');
}

$companyId = active_company_id();
$garageId = active_garage_id();
$invoiceId = get_int('id');

if ($invoiceId <= 0) {
    flash_set('billing_error', 'Invalid invoice id.', 'danger');
    redirect('modules/billing/index.php');
}

function snapshot_get(array $snapshot, string $section, string $key, ?string $fallback = null): ?string
{
    $value = $snapshot[$section][$key] ?? null;
    if (is_string($value) && trim($value) !== '') {
        return $value;
    }

    return $fallback;
}

$invoiceStmt = db()->prepare(
    'SELECT i.*,
            c.name AS live_company_name, c.gstin AS live_company_gstin, c.address_line1 AS live_company_address, c.city AS live_company_city, c.state AS live_company_state,
            g.name AS live_garage_name,
            cu.full_name AS live_customer_name, cu.phone AS live_customer_phone, cu.gstin AS live_customer_gstin, cu.address_line1 AS live_customer_address,
            v.registration_no AS live_registration_no, v.brand AS live_vehicle_brand, v.model AS live_vehicle_model
     FROM invoices i
     LEFT JOIN companies c ON c.id = i.company_id
     LEFT JOIN garages g ON g.id = i.garage_id
     LEFT JOIN customers cu ON cu.id = i.customer_id
     LEFT JOIN vehicles v ON v.id = i.vehicle_id
     WHERE i.id = :invoice_id
       AND i.company_id = :company_id
       AND i.garage_id = :garage_id
     LIMIT 1'
);
$invoiceStmt->execute([
    'invoice_id' => $invoiceId,
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$invoice = $invoiceStmt->fetch();

if (!$invoice) {
    flash_set('billing_error', 'Invoice not found for active garage.', 'danger');
    redirect('modules/billing/index.php');
}

$snapshot = json_decode((string) ($invoice['snapshot_json'] ?? ''), true);
if (!is_array($snapshot)) {
    $snapshot = [];
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

$statusHistoryStmt = db()->prepare(
    'SELECT h.*, u.name AS actor_name
     FROM invoice_status_history h
     LEFT JOIN users u ON u.id = h.created_by
     WHERE h.invoice_id = :invoice_id
     ORDER BY h.id DESC
     LIMIT 20'
);
$statusHistoryStmt->execute(['invoice_id' => $invoiceId]);
$statusHistory = $statusHistoryStmt->fetchAll();

$companyName = snapshot_get($snapshot, 'company', 'name', (string) ($invoice['live_company_name'] ?? ''));
$companyGstin = snapshot_get($snapshot, 'company', 'gstin', (string) ($invoice['live_company_gstin'] ?? ''));
$companyAddress = snapshot_get($snapshot, 'company', 'address_line1', (string) ($invoice['live_company_address'] ?? ''));
$companyCity = snapshot_get($snapshot, 'company', 'city', (string) ($invoice['live_company_city'] ?? ''));
$companyState = snapshot_get($snapshot, 'company', 'state', (string) ($invoice['live_company_state'] ?? ''));

$garageName = snapshot_get($snapshot, 'garage', 'name', (string) ($invoice['live_garage_name'] ?? ''));

$customerName = snapshot_get($snapshot, 'customer', 'full_name', (string) ($invoice['live_customer_name'] ?? ''));
$customerPhone = snapshot_get($snapshot, 'customer', 'phone', (string) ($invoice['live_customer_phone'] ?? ''));
$customerGstin = snapshot_get($snapshot, 'customer', 'gstin', (string) ($invoice['live_customer_gstin'] ?? ''));
$customerAddress = snapshot_get($snapshot, 'customer', 'address_line1', (string) ($invoice['live_customer_address'] ?? ''));
$customerState = snapshot_get($snapshot, 'customer', 'state', null);

$vehicleNo = snapshot_get($snapshot, 'vehicle', 'registration_no', (string) ($invoice['live_registration_no'] ?? ''));
$vehicleBrand = snapshot_get($snapshot, 'vehicle', 'brand', (string) ($invoice['live_vehicle_brand'] ?? ''));
$vehicleModel = snapshot_get($snapshot, 'vehicle', 'model', (string) ($invoice['live_vehicle_model'] ?? ''));

$paidAmount = 0.0;
foreach ($payments as $payment) {
    $paidAmount += (float) $payment['amount'];
}
$outstanding = max(0.0, (float) $invoice['grand_total'] - $paidAmount);
$invoiceStatus = (string) ($invoice['invoice_status'] ?? 'FINALIZED');
$paymentStatus = (string) ($invoice['payment_status'] ?? 'UNPAID');
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
              <div class="invoice-title"><?= e((string) $companyName); ?></div>
              <div><?= e((string) ($companyAddress ?? '')); ?>, <?= e((string) ($companyCity ?? '')); ?>, <?= e((string) ($companyState ?? '')); ?></div>
              <div>GSTIN: <?= e((string) ($companyGstin ?? '-')); ?></div>
            </div>
            <div class="col-4 text-end">
              <h4 class="mb-1">Tax Invoice</h4>
              <div><strong>Invoice:</strong> <?= e((string) $invoice['invoice_number']); ?></div>
              <div><strong>Date:</strong> <?= e((string) $invoice['invoice_date']); ?></div>
              <div><strong>Garage:</strong> <?= e((string) ($garageName ?? '-')); ?></div>
              <div><strong>FY:</strong> <?= e((string) ($invoice['financial_year_label'] ?? '-')); ?></div>
            </div>
          </div>

          <div class="row mb-3">
            <div class="col-6">
              <h6 class="mb-1">Bill To</h6>
              <div><strong><?= e((string) ($customerName ?? '-')); ?></strong></div>
              <div>Phone: <?= e((string) ($customerPhone ?? '-')); ?></div>
              <div>GSTIN: <?= e((string) ($customerGstin ?? '-')); ?></div>
              <div><?= e((string) ($customerAddress ?? '')); ?></div>
              <div>State: <?= e((string) ($customerState ?? '-')); ?></div>
            </div>
            <div class="col-6 text-end">
              <h6 class="mb-1">Vehicle</h6>
              <div><?= e((string) ($vehicleNo ?? '-')); ?></div>
              <div><?= e((string) ($vehicleBrand ?? '')); ?> <?= e((string) ($vehicleModel ?? '')); ?></div>
              <div><strong>Invoice Status:</strong> <?= e($invoiceStatus); ?></div>
              <div><strong>Payment Status:</strong> <?= e($paymentStatus); ?></div>
              <div><strong>Tax Regime:</strong> <?= e((string) ($invoice['tax_regime'] ?? '-')); ?></div>
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
                  <th class="text-end">CGST</th>
                  <th class="text-end">SGST</th>
                  <th class="text-end">IGST</th>
                  <th class="text-end">Total</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($items)): ?>
                  <tr><td colspan="11" class="text-center text-muted">No invoice items.</td></tr>
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
                      <td class="text-end"><?= e(number_format((float) ($item['cgst_amount'] ?? 0), 2)); ?></td>
                      <td class="text-end"><?= e(number_format((float) ($item['sgst_amount'] ?? 0), 2)); ?></td>
                      <td class="text-end"><?= e(number_format((float) ($item['igst_amount'] ?? 0), 2)); ?></td>
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

              <?php if (!empty($statusHistory)): ?>
                <h6 class="mb-1 mt-3">Status Timeline</h6>
                <table class="table table-sm table-bordered">
                  <thead><tr><th>Date</th><th>Transition</th><th>By</th></tr></thead>
                  <tbody>
                    <?php foreach ($statusHistory as $entry): ?>
                      <tr>
                        <td><?= e((string) $entry['created_at']); ?></td>
                        <td><?= e((string) (($entry['from_status'] ?? '-') . ' -> ' . ($entry['to_status'] ?? '-'))); ?></td>
                        <td><?= e((string) ($entry['actor_name'] ?? 'System')); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>

            <div class="col-5">
              <table class="table table-sm table-bordered">
                <tr><th>Service Subtotal</th><td class="text-end"><?= e(number_format((float) $invoice['subtotal_service'], 2)); ?></td></tr>
                <tr><th>Parts Subtotal</th><td class="text-end"><?= e(number_format((float) $invoice['subtotal_parts'], 2)); ?></td></tr>
                <tr><th>Service GST</th><td class="text-end"><?= e(number_format((float) ($invoice['service_tax_amount'] ?? 0), 2)); ?></td></tr>
                <tr><th>Parts GST</th><td class="text-end"><?= e(number_format((float) ($invoice['parts_tax_amount'] ?? 0), 2)); ?></td></tr>
                <tr><th>CGST</th><td class="text-end"><?= e(number_format((float) $invoice['cgst_amount'], 2)); ?></td></tr>
                <tr><th>SGST</th><td class="text-end"><?= e(number_format((float) $invoice['sgst_amount'], 2)); ?></td></tr>
                <tr><th>IGST</th><td class="text-end"><?= e(number_format((float) $invoice['igst_amount'], 2)); ?></td></tr>
                <tr><th>Total Tax</th><td class="text-end"><?= e(number_format((float) ($invoice['total_tax_amount'] ?? 0), 2)); ?></td></tr>
                <tr><th>Round Off</th><td class="text-end"><?= e(number_format((float) $invoice['round_off'], 2)); ?></td></tr>
                <tr><th>Grand Total</th><td class="text-end"><strong><?= e(number_format((float) $invoice['grand_total'], 2)); ?></strong></td></tr>
                <tr><th>Paid</th><td class="text-end"><?= e(number_format($paidAmount, 2)); ?></td></tr>
                <tr><th>Outstanding</th><td class="text-end"><?= e(number_format($outstanding, 2)); ?></td></tr>
              </table>

              <?php if ($invoiceStatus === 'CANCELLED'): ?>
                <div class="alert alert-danger py-2 mb-0">
                  <strong>Cancelled:</strong> <?= e((string) ($invoice['cancel_reason'] ?? 'No reason recorded')); ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <p class="mb-0 text-muted">Generated from Guru Auto Cars ERP. GST and line values are frozen at invoice finalization.</p>
        </div>
      </div>
    </div>
  </body>
</html>
