<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/../jobs/workflow.php';

if (!billing_can_view()) {
    flash_set('access_denied', 'You do not have permission to access billing invoices.', 'danger');
    redirect('dashboard.php');
}

$companyId = active_company_id();
$garageId = active_garage_id();
$invoiceId = get_int('id');
$jobCardColumns = table_columns('job_cards');
$jobOdometerSelect = in_array('odometer_km', $jobCardColumns, true)
    ? 'jc.odometer_km AS live_job_odometer_km'
    : 'NULL AS live_job_odometer_km';
$jobRecommendationSelect = in_array('recommendation_note', $jobCardColumns, true)
    ? 'jc.recommendation_note AS live_job_recommendation_note'
    : 'NULL AS live_job_recommendation_note';

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
            v.registration_no AS live_registration_no, v.brand AS live_vehicle_brand, v.model AS live_vehicle_model,
            ' . $jobOdometerSelect . ',
            ' . $jobRecommendationSelect . '
     FROM invoices i
     LEFT JOIN companies c ON c.id = i.company_id
     LEFT JOIN garages g ON g.id = i.garage_id
     LEFT JOIN customers cu ON cu.id = i.customer_id
     LEFT JOIN vehicles v ON v.id = i.vehicle_id
     LEFT JOIN job_cards jc ON jc.id = i.job_card_id
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
$discountMeta = billing_discount_meta_from_snapshot($snapshot);
$discountType = (string) ($discountMeta['type'] ?? 'AMOUNT');
$discountValue = (float) ($discountMeta['value'] ?? 0);
$discountAmount = (float) ($discountMeta['amount'] ?? 0);
$discountLabel = '-';
if ($discountAmount > 0.009 && $discountType === 'PERCENT' && $discountValue > 0.009) {
    $discountLabel = rtrim(rtrim(number_format($discountValue, 2), '0'), '.') . '%';
} elseif ($discountAmount > 0.009) {
    $discountLabel = 'Flat';
}
$grossBeforeDiscount = billing_round((float) ($invoice['gross_total'] ?? 0));
$netBeforeRoundOff = max(0.0, billing_round($grossBeforeDiscount - $discountAmount));

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
$jobRecommendationNote = trim((string) snapshot_get(
    $snapshot,
    'job',
    'recommendation_note',
    (string) ($invoice['live_job_recommendation_note'] ?? '')
));
$invoicedOdometer = null;
if (isset($snapshot['job']['odometer_km']) && is_numeric($snapshot['job']['odometer_km'])) {
    $invoicedOdometer = (int) round((float) $snapshot['job']['odometer_km']);
} elseif (
    isset($invoice['live_job_odometer_km'])
    && $invoice['live_job_odometer_km'] !== null
    && $invoice['live_job_odometer_km'] !== ''
    && is_numeric((string) $invoice['live_job_odometer_km'])
) {
    $invoicedOdometer = (int) round((float) $invoice['live_job_odometer_km']);
}

$invoiceHasGst = abs((float) ($invoice['total_tax_amount'] ?? 0)) > 0.009;
if (!$invoiceHasGst) {
    foreach ($items as $item) {
        if (
            abs((float) ($item['gst_rate'] ?? 0)) > 0.009
            || abs((float) ($item['tax_amount'] ?? 0)) > 0.009
            || abs((float) ($item['cgst_amount'] ?? 0)) > 0.009
            || abs((float) ($item['sgst_amount'] ?? 0)) > 0.009
            || abs((float) ($item['igst_amount'] ?? 0)) > 0.009
        ) {
            $invoiceHasGst = true;
            break;
        }
    }
}
$showCompanyGstin = $invoiceHasGst && trim((string) ($companyGstin ?? '')) !== '';
$showCustomerGstin = $invoiceHasGst && trim((string) ($customerGstin ?? '')) !== '';

$paidAmount = 0.0;
foreach ($payments as $payment) {
    $paidAmount += (float) $payment['amount'];
}
$serviceSubtotal = (float) ($invoice['subtotal_service'] ?? 0);
$partsSubtotal = (float) ($invoice['subtotal_parts'] ?? 0);
$serviceTaxAmount = (float) ($invoice['service_tax_amount'] ?? 0);
$partsTaxAmount = (float) ($invoice['parts_tax_amount'] ?? 0);
$cgstAmount = (float) ($invoice['cgst_amount'] ?? 0);
$sgstAmount = (float) ($invoice['sgst_amount'] ?? 0);
$igstAmount = (float) ($invoice['igst_amount'] ?? 0);
$taxableAmount = (float) ($invoice['taxable_amount'] ?? ($serviceSubtotal + $partsSubtotal));
$totalTaxAmount = (float) ($invoice['total_tax_amount'] ?? ($serviceTaxAmount + $partsTaxAmount));
$serviceTotalWithTax = billing_round($serviceSubtotal + $serviceTaxAmount);
$partsTotalWithTax = billing_round($partsSubtotal + $partsTaxAmount);
$outstanding = max(0.0, (float) $invoice['grand_total'] - $paidAmount);
$invoiceStatus = (string) ($invoice['invoice_status'] ?? 'FINALIZED');
$nextServiceReminders = service_reminder_feature_ready()
    ? service_reminder_fetch_active_by_vehicle($companyId, (int) ($invoice['vehicle_id'] ?? 0), $garageId, 6)
    : [];
$companyLogoUrl = company_logo_url((int) ($invoice['company_id'] ?? $companyId), $garageId);
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Invoice <?= e((string) $invoice['invoice_number']); ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/adminlte.min.css')); ?>" />
    <style>
      * {
        box-sizing: border-box;
      }

      @page {
        size: A4 portrait;
        margin: 10mm;
      }

      html,
      body {
        margin: 0;
        padding: 0;
      }

      body {
        padding: 16px;
        background: #edf1f6;
        color: #0f172a;
        font-family: "Segoe UI", Tahoma, Arial, sans-serif;
        overflow-x: hidden;
      }

      .invoice-page {
        max-width: 210mm;
        margin: 0 auto;
      }

      .invoice-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
      }

      .invoice-sheet {
        background: #fff;
        border: 1px solid #d6dde6;
        border-radius: 4px;
        padding: 10mm;
        box-shadow: 0 2px 10px rgba(15, 23, 42, 0.08);
        overflow: hidden;
      }

      .header-block {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        padding-bottom: 8px;
        border-bottom: 1px solid #dfe6ee;
        margin-bottom: 8px;
      }

      .company-block {
        flex: 1 1 auto;
        min-width: 0;
      }

      .meta-block {
        width: 66mm;
        max-width: 100%;
      }

      .brand-logo {
        max-height: 50px;
        max-width: 170px;
        width: auto;
        display: block;
        margin-bottom: 6px;
      }

      .invoice-title {
        font-size: 1.35rem;
        font-weight: 700;
        line-height: 1.2;
        margin-bottom: 4px;
      }

      .company-line {
        font-size: 12px;
        line-height: 1.45;
      }

      .doc-label {
        text-align: right;
        font-size: 1.1rem;
        font-weight: 700;
        letter-spacing: 0.3px;
        margin-bottom: 4px;
      }

      table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
      }

      th,
      td {
        border: 1px solid #d6dde6;
        padding: 5px 6px;
        font-size: 12px;
        line-height: 1.3;
        vertical-align: top;
        word-break: break-word;
      }

      th {
        background: #f6f9fc;
        font-weight: 600;
      }

      .text-end {
        text-align: right;
      }

      .meta-table th {
        width: 36%;
      }

      .party-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        margin-bottom: 10px;
      }

      .panel {
        border: 1px solid #d6dde6;
        padding: 6px 8px;
      }

      .panel-heading {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: #334155;
        margin-bottom: 4px;
      }

      .panel-line {
        font-size: 12px;
        line-height: 1.42;
      }

      .section-title {
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: #334155;
        margin: 6px 0;
      }

      .line-table th,
      .line-table td {
        font-size: 11px;
        padding: 4px 5px;
      }

      .summary-grid {
        display: grid;
        grid-template-columns: 1.05fr 0.95fr;
        gap: 8px;
        margin-top: 10px;
      }

      .summary-card {
        border: 1px solid #d6dde6;
        padding: 6px;
      }

      .summary-table th,
      .summary-table td {
        font-size: 11px;
        padding: 4px 5px;
      }

      .summary-row-strong th,
      .summary-row-strong td {
        font-weight: 700;
        background: #f2f6fb;
      }

      .grand-row th,
      .grand-row td {
        font-weight: 700;
        background: #ecf2fb;
        font-size: 12px;
      }

      .cancel-note {
        margin-top: 10px;
        border: 1px solid #fecaca;
        background: #fff1f1;
        color: #991b1b;
        padding: 6px 8px;
        font-size: 12px;
      }

      .invoice-footnote {
        margin-top: 8px;
        font-size: 11px;
        color: #64748b;
      }

      @media (max-width: 900px) {
        .header-block {
          flex-direction: column;
        }

        .meta-block {
          width: 100%;
        }

        .party-grid,
        .summary-grid {
          grid-template-columns: 1fr;
        }
      }

      @media print {
        .no-print {
          display: none !important;
        }

        body {
          background: #fff !important;
          margin: 0 !important;
          padding: 0 !important;
          overflow: visible !important;
        }

        .invoice-page {
          max-width: none !important;
          margin: 0 !important;
        }

        .invoice-sheet {
          border: 0 !important;
          box-shadow: none !important;
          border-radius: 0 !important;
          padding: 0 !important;
          overflow: visible !important;
        }

        .header-block,
        .party-grid,
        .summary-grid,
        .panel,
        .summary-card {
          page-break-inside: avoid;
        }

        .line-table tr {
          page-break-inside: avoid;
        }

        th,
        td {
          font-size: 10.5px !important;
          padding: 3px 4px !important;
        }

        .invoice-title {
          font-size: 1.2rem !important;
        }

        .doc-label {
          font-size: 1rem !important;
        }

        .summary-grid {
          gap: 6px;
        }

        .invoice-footnote {
          margin-top: 6px;
        }
      }
    </style>
  </head>
  <body>
    <div class="invoice-page">
      <div class="invoice-actions no-print">
        <a href="<?= e(url('modules/billing/index.php')); ?>" class="btn btn-outline-secondary btn-sm">Back</a>
        <button onclick="window.print()" class="btn btn-primary btn-sm">Print / Save PDF</button>
      </div>

      <div class="invoice-sheet">
        <div class="header-block">
          <div class="company-block">
            <?php if ($companyLogoUrl !== null): ?>
              <img src="<?= e($companyLogoUrl); ?>" alt="Company Logo" class="brand-logo" />
            <?php endif; ?>
            <div class="invoice-title"><?= e((string) $companyName); ?></div>
            <div class="company-line"><?= e((string) ($companyAddress ?? '')); ?>, <?= e((string) ($companyCity ?? '')); ?>, <?= e((string) ($companyState ?? '')); ?></div>
            <?php if ($showCompanyGstin): ?>
              <div class="company-line">GSTIN: <?= e((string) $companyGstin); ?></div>
            <?php endif; ?>
          </div>
          <div class="meta-block">
            <div class="doc-label">Tax Invoice</div>
            <table class="meta-table">
              <tbody>
                <tr>
                  <th>Invoice No</th>
                  <td><?= e((string) $invoice['invoice_number']); ?></td>
                </tr>
                <tr>
                  <th>Date</th>
                  <td><?= e((string) $invoice['invoice_date']); ?></td>
                </tr>
                <tr>
                  <th>Garage</th>
                  <td><?= e((string) ($garageName ?? '-')); ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="party-grid">
          <div class="panel">
            <div class="panel-heading">Bill To</div>
            <div class="panel-line"><strong><?= e((string) ($customerName ?? '-')); ?></strong></div>
            <div class="panel-line">Phone: <?= e((string) ($customerPhone ?? '-')); ?></div>
            <?php if ($showCustomerGstin): ?>
              <div class="panel-line">GSTIN: <?= e((string) $customerGstin); ?></div>
            <?php endif; ?>
            <div class="panel-line"><?= e((string) ($customerAddress ?? '-')); ?></div>
            <div class="panel-line">State: <?= e((string) ($customerState ?? '-')); ?></div>
          </div>
          <div class="panel">
            <div class="panel-heading">Vehicle</div>
            <div class="panel-line"><strong><?= e((string) ($vehicleNo ?? '-')); ?></strong></div>
            <div class="panel-line"><?= e((string) ($vehicleBrand ?? '')); ?> <?= e((string) ($vehicleModel ?? '')); ?></div>
            <div class="panel-line"><strong>Invoiced Odometer:</strong> <?= $invoicedOdometer !== null ? e(number_format((float) $invoicedOdometer, 0)) . ' KM' : '-'; ?></div>
          </div>
        </div>

        <?php if ($jobRecommendationNote !== ''): ?>
          <div class="panel" style="margin-bottom: 10px;">
            <div class="panel-heading">Recommendation Note</div>
            <div class="panel-line"><?= nl2br(e($jobRecommendationNote)); ?></div>
          </div>
        <?php endif; ?>

        <div class="section-title">Invoice Items</div>
        <table class="line-table">
          <thead>
            <tr>
              <?php if ($invoiceHasGst): ?>
                <th style="width:5%;">#</th>
                <th style="width:11%;">Type</th>
                <th style="width:34%;">Description</th>
                <th style="width:8%;" class="text-end">Qty</th>
                <th style="width:12%;" class="text-end">Rate</th>
                <th style="width:8%;" class="text-end">GST%</th>
                <th style="width:11%;" class="text-end">Taxable</th>
                <th style="width:11%;" class="text-end">Line Total</th>
              <?php else: ?>
                <th style="width:5%;">#</th>
                <th style="width:12%;">Type</th>
                <th style="width:43%;">Description</th>
                <th style="width:10%;" class="text-end">Qty</th>
                <th style="width:15%;" class="text-end">Rate</th>
                <th style="width:15%;" class="text-end">Amount</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($items)): ?>
              <tr>
                <td colspan="<?= $invoiceHasGst ? '8' : '6'; ?>" class="text-center">No invoice items.</td>
              </tr>
            <?php else: ?>
              <?php $lineNo = 1; ?>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td><?= $lineNo++; ?></td>
                  <td><?= e((string) $item['item_type']); ?></td>
                  <td><?= e((string) $item['description']); ?></td>
                  <td class="text-end"><?= e((string) $item['quantity']); ?></td>
                  <td class="text-end"><?= e(number_format((float) $item['unit_price'], 2)); ?></td>
                  <?php if ($invoiceHasGst): ?>
                    <td class="text-end"><?= e(number_format((float) $item['gst_rate'], 2)); ?></td>
                    <td class="text-end"><?= e(number_format((float) $item['taxable_value'], 2)); ?></td>
                    <td class="text-end"><?= e(number_format((float) $item['total_value'], 2)); ?></td>
                  <?php else: ?>
                    <td class="text-end"><?= e(number_format((float) $item['total_value'], 2)); ?></td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="summary-grid">
          <div class="summary-card">
            <div class="panel-heading">Service And Parts Breakdown</div>
            <?php if ($invoiceHasGst): ?>
              <table class="summary-table">
                <thead>
                  <tr>
                    <th style="width:34%;">Category</th>
                    <th style="width:22%;" class="text-end">Taxable</th>
                    <th style="width:22%;" class="text-end">GST</th>
                    <th style="width:22%;" class="text-end">Total</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>Services</td>
                    <td class="text-end"><?= e(number_format($serviceSubtotal, 2)); ?></td>
                    <td class="text-end"><?= e(number_format($serviceTaxAmount, 2)); ?></td>
                    <td class="text-end"><?= e(number_format($serviceTotalWithTax, 2)); ?></td>
                  </tr>
                  <tr>
                    <td>Parts</td>
                    <td class="text-end"><?= e(number_format($partsSubtotal, 2)); ?></td>
                    <td class="text-end"><?= e(number_format($partsTaxAmount, 2)); ?></td>
                    <td class="text-end"><?= e(number_format($partsTotalWithTax, 2)); ?></td>
                  </tr>
                  <tr class="summary-row-strong">
                    <td>Subtotal</td>
                    <td class="text-end"><?= e(number_format($taxableAmount, 2)); ?></td>
                    <td class="text-end"><?= e(number_format($totalTaxAmount, 2)); ?></td>
                    <td class="text-end"><?= e(number_format($grossBeforeDiscount, 2)); ?></td>
                  </tr>
                </tbody>
              </table>
              <table class="summary-table" style="margin-top: 6px;">
                <tbody>
                  <tr>
                    <th style="width:25%;">CGST</th>
                    <td style="width:25%;" class="text-end"><?= e(number_format($cgstAmount, 2)); ?></td>
                    <th style="width:25%;">SGST</th>
                    <td style="width:25%;" class="text-end"><?= e(number_format($sgstAmount, 2)); ?></td>
                  </tr>
                  <tr>
                    <th>IGST</th>
                    <td class="text-end"><?= e(number_format($igstAmount, 2)); ?></td>
                    <th>Total GST</th>
                    <td class="text-end"><?= e(number_format($totalTaxAmount, 2)); ?></td>
                  </tr>
                </tbody>
              </table>
            <?php else: ?>
              <table class="summary-table">
                <thead>
                  <tr>
                    <th style="width:65%;">Category</th>
                    <th style="width:35%;" class="text-end">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>Services</td>
                    <td class="text-end"><?= e(number_format($serviceSubtotal, 2)); ?></td>
                  </tr>
                  <tr>
                    <td>Parts</td>
                    <td class="text-end"><?= e(number_format($partsSubtotal, 2)); ?></td>
                  </tr>
                  <tr class="summary-row-strong">
                    <td>Subtotal</td>
                    <td class="text-end"><?= e(number_format($taxableAmount, 2)); ?></td>
                  </tr>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

          <div class="summary-card">
            <div class="panel-heading">Final Totals</div>
            <table class="summary-table">
              <tbody>
                <tr>
                  <th style="width:68%;">Gross Total (Before Discount)</th>
                  <td style="width:32%;" class="text-end"><?= e(number_format($grossBeforeDiscount, 2)); ?></td>
                </tr>
                <tr>
                  <th>Discount<?= $discountLabel !== '-' ? ' (' . e($discountLabel) . ')' : ''; ?></th>
                  <td class="text-end">-<?= e(number_format($discountAmount, 2)); ?></td>
                </tr>
                <tr>
                  <th>Net Total (Before Round Off)</th>
                  <td class="text-end"><?= e(number_format($netBeforeRoundOff, 2)); ?></td>
                </tr>
                <tr>
                  <th>Round Off</th>
                  <td class="text-end"><?= e(number_format((float) $invoice['round_off'], 2)); ?></td>
                </tr>
                <tr class="grand-row">
                  <th>Grand Total</th>
                  <td class="text-end"><?= e(number_format((float) $invoice['grand_total'], 2)); ?></td>
                </tr>
                <tr>
                  <th>Paid</th>
                  <td class="text-end"><?= e(number_format($paidAmount, 2)); ?></td>
                </tr>
                <tr>
                  <th>Outstanding</th>
                  <td class="text-end"><?= e(number_format($outstanding, 2)); ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="section-title">Next Recommended Service</div>
        <table class="line-table">
          <thead>
            <tr>
              <th style="width:30%;">Service</th>
              <th style="width:18%;" class="text-end">Due KM</th>
              <th style="width:18%;">Due Date</th>
              <th style="width:20%;">Predicted Visit</th>
              <th style="width:14%;">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($nextServiceReminders)): ?>
              <tr><td colspan="5" class="text-center">No active service reminders.</td></tr>
            <?php else: ?>
              <?php foreach ($nextServiceReminders as $reminder): ?>
                <tr>
                  <td><?= e((string) ($reminder['service_label'] ?? service_reminder_type_label((string) ($reminder['service_type'] ?? '')))); ?></td>
                  <td class="text-end"><?= isset($reminder['next_due_km']) && $reminder['next_due_km'] !== null ? e(number_format((float) $reminder['next_due_km'], 0)) : '-'; ?></td>
                  <td><?= e((string) (($reminder['next_due_date'] ?? '') !== '' ? $reminder['next_due_date'] : '-')); ?></td>
                  <td><?= e((string) (($reminder['predicted_next_visit_date'] ?? '') !== '' ? $reminder['predicted_next_visit_date'] : '-')); ?></td>
                  <td><?= e((string) ($reminder['due_state'] ?? 'UNSCHEDULED')); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <?php if ($invoiceStatus === 'CANCELLED'): ?>
          <div class="cancel-note">
            <strong>Cancelled:</strong> <?= e((string) ($invoice['cancel_reason'] ?? 'No reason recorded')); ?>
          </div>
        <?php endif; ?>

        <div class="invoice-footnote">Generated from Guru Auto Cars ERP. GST and line values are frozen at invoice finalization.</div>
      </div>
    </div>
  </body>
</html>
