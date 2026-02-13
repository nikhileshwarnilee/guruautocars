<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();

$estimateTablesReady = table_columns('estimates') !== []
    && table_columns('estimate_services') !== []
    && table_columns('estimate_parts') !== [];
if (!$estimateTablesReady) {
    flash_set('estimate_error', 'Estimate module database upgrade is pending. Run database/estimate_module_upgrade.sql.', 'danger');
    redirect('dashboard.php');
}

$canEstimateView = has_permission('estimate.view') || has_permission('estimate.manage') || has_permission('estimate.print');
if (!$canEstimateView) {
    flash_set('access_denied', 'You do not have permission to print estimates.', 'danger');
    redirect('dashboard.php');
}

$companyId = active_company_id();
$garageId = active_garage_id();
$estimateId = get_int('id');

if ($estimateId <= 0) {
    flash_set('estimate_error', 'Invalid estimate id.', 'danger');
    redirect('modules/estimates/index.php');
}

$estimateStmt = db()->prepare(
    'SELECT e.*,
            c.full_name AS customer_name, c.phone AS customer_phone, c.gstin AS customer_gstin, c.address_line1 AS customer_address,
            v.registration_no, v.brand, v.model, v.variant, v.fuel_type, v.model_year,
            g.name AS garage_name, g.gstin AS garage_gstin, g.address_line1 AS garage_address, g.city AS garage_city, g.state AS garage_state,
            cp.name AS company_name, cp.gstin AS company_gstin, cp.address_line1 AS company_address, cp.city AS company_city, cp.state AS company_state,
            j.job_number AS converted_job_number
     FROM estimates e
     INNER JOIN customers c ON c.id = e.customer_id
     INNER JOIN vehicles v ON v.id = e.vehicle_id
     INNER JOIN garages g ON g.id = e.garage_id
     INNER JOIN companies cp ON cp.id = e.company_id
     LEFT JOIN job_cards j ON j.id = e.converted_job_card_id
     WHERE e.id = :estimate_id
       AND e.company_id = :company_id
       AND e.garage_id = :garage_id
     LIMIT 1'
);
$estimateStmt->execute([
    'estimate_id' => $estimateId,
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$estimate = $estimateStmt->fetch();

if (!$estimate) {
    flash_set('estimate_error', 'Estimate not found for active garage.', 'danger');
    redirect('modules/estimates/index.php');
}

$serviceStmt = db()->prepare(
    'SELECT es.*, s.service_name
     FROM estimate_services es
     LEFT JOIN services s ON s.id = es.service_id
     WHERE es.estimate_id = :estimate_id
     ORDER BY es.id ASC'
);
$serviceStmt->execute(['estimate_id' => $estimateId]);
$serviceLines = $serviceStmt->fetchAll();

$partStmt = db()->prepare(
    'SELECT ep.*, p.part_name, p.part_sku
     FROM estimate_parts ep
     INNER JOIN parts p ON p.id = ep.part_id
     WHERE ep.estimate_id = :estimate_id
     ORDER BY ep.id ASC'
);
$partStmt->execute(['estimate_id' => $estimateId]);
$partLines = $partStmt->fetchAll();

$serviceTotal = 0.0;
foreach ($serviceLines as $line) {
    $serviceTotal += (float) ($line['total_amount'] ?? 0);
}
$partsTotal = 0.0;
foreach ($partLines as $line) {
    $partsTotal += (float) ($line['total_amount'] ?? 0);
}
$grandTotal = round($serviceTotal + $partsTotal, 2);
$companyLogoUrl = company_logo_url((int) ($estimate['company_id'] ?? $companyId), $garageId);
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Estimate <?= e((string) $estimate['estimate_number']); ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/adminlte.min.css')); ?>" />
    <style>
      @page {
        size: A4;
        margin: 12mm;
      }

      @media print {
        .no-print {
          display: none !important;
        }

        body {
          padding: 0;
          background: #fff;
        }

        .print-sheet {
          box-shadow: none !important;
          border: 0 !important;
        }
      }

      body {
        padding: 20px;
        background: #f4f6f9;
      }

      .title {
        font-size: 1.3rem;
        font-weight: 700;
      }

      .print-sheet {
        max-width: 210mm;
        margin: 0 auto;
      }

      .brand-logo {
        max-height: 56px;
        max-width: 180px;
        width: auto;
      }
    </style>
  </head>
  <body>
    <div class="container-fluid print-sheet">
      <div class="d-flex justify-content-between mb-3 no-print">
        <a href="<?= e(url('modules/estimates/view.php?id=' . $estimateId)); ?>" class="btn btn-outline-secondary btn-sm">Back</a>
        <button onclick="window.print()" class="btn btn-primary btn-sm">Print / Save PDF</button>
      </div>

      <div class="card">
        <div class="card-body">
          <div class="row mb-3">
            <div class="col-8">
              <?php if ($companyLogoUrl !== null): ?>
                <div class="mb-2"><img src="<?= e($companyLogoUrl); ?>" alt="Company Logo" class="brand-logo" /></div>
              <?php endif; ?>
              <div class="title"><?= e((string) $estimate['company_name']); ?></div>
              <div><?= e((string) ($estimate['company_address'] ?? '')); ?>, <?= e((string) ($estimate['company_city'] ?? '')); ?>, <?= e((string) ($estimate['company_state'] ?? '')); ?></div>
              <div>GSTIN: <?= e((string) ($estimate['company_gstin'] ?? '-')); ?></div>
            </div>
            <div class="col-4 text-end">
              <h4 class="mb-1">Estimate</h4>
              <div><strong>No:</strong> <?= e((string) $estimate['estimate_number']); ?></div>
              <div><strong>Date:</strong> <?= e((string) $estimate['created_at']); ?></div>
              <div><strong>Status:</strong> <?= e((string) $estimate['estimate_status']); ?></div>
              <div><strong>Garage:</strong> <?= e((string) $estimate['garage_name']); ?></div>
            </div>
          </div>

          <div class="row mb-3">
            <div class="col-6">
              <h6 class="mb-1">Estimate For</h6>
              <div><strong><?= e((string) $estimate['customer_name']); ?></strong></div>
              <div>Phone: <?= e((string) ($estimate['customer_phone'] ?? '-')); ?></div>
              <div>GSTIN: <?= e((string) ($estimate['customer_gstin'] ?? '-')); ?></div>
              <div><?= e((string) ($estimate['customer_address'] ?? '')); ?></div>
            </div>
            <div class="col-6 text-end">
              <h6 class="mb-1">Vehicle</h6>
              <div><?= e((string) $estimate['registration_no']); ?></div>
              <div><?= e((string) $estimate['brand']); ?> <?= e((string) $estimate['model']); ?> <?= e((string) ($estimate['variant'] ?? '')); ?></div>
              <div><strong>Valid Until:</strong> <?= e((string) (($estimate['valid_until'] ?? '') !== '' ? $estimate['valid_until'] : '-')); ?></div>
              <?php if ((int) ($estimate['converted_job_card_id'] ?? 0) > 0): ?>
                <div><strong>Converted Job:</strong> <?= e((string) ($estimate['converted_job_number'] ?? ('#' . (int) $estimate['converted_job_card_id']))); ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div class="mb-3">
            <h6 class="mb-1">Complaint / Scope</h6>
            <div class="border rounded p-2"><?= nl2br(e((string) ($estimate['complaint'] ?? ''))); ?></div>
          </div>

          <?php if (!empty($estimate['notes'])): ?>
            <div class="mb-3">
              <h6 class="mb-1">Notes</h6>
              <div class="border rounded p-2"><?= nl2br(e((string) $estimate['notes'])); ?></div>
            </div>
          <?php endif; ?>

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
                  <th class="text-end">Total</th>
                </tr>
              </thead>
              <tbody>
                <?php $lineNo = 1; ?>
                <?php foreach ($serviceLines as $line): ?>
                  <tr>
                    <td><?= $lineNo++; ?></td>
                    <td>SERVICE</td>
                    <td><?= e((string) (($line['service_name'] ?? '') !== '' ? $line['service_name'] : ($line['description'] ?? ''))); ?></td>
                    <td class="text-end"><?= e(number_format((float) $line['quantity'], 2)); ?></td>
                    <td class="text-end"><?= e(number_format((float) $line['unit_price'], 2)); ?></td>
                    <td class="text-end"><?= e(number_format((float) $line['gst_rate'], 2)); ?></td>
                    <td class="text-end"><?= e(number_format((float) $line['total_amount'], 2)); ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php foreach ($partLines as $line): ?>
                  <tr>
                    <td><?= $lineNo++; ?></td>
                    <td>PART</td>
                    <td><?= e((string) $line['part_name']); ?> (<?= e((string) $line['part_sku']); ?>)</td>
                    <td class="text-end"><?= e(number_format((float) $line['quantity'], 2)); ?></td>
                    <td class="text-end"><?= e(number_format((float) $line['unit_price'], 2)); ?></td>
                    <td class="text-end"><?= e(number_format((float) $line['gst_rate'], 2)); ?></td>
                    <td class="text-end"><?= e(number_format((float) $line['total_amount'], 2)); ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($serviceLines) && empty($partLines)): ?>
                  <tr><td colspan="7" class="text-center text-muted">No estimate line items.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="row">
            <div class="col-7"></div>
            <div class="col-5">
              <table class="table table-sm table-bordered">
                <tr><th>Service Total</th><td class="text-end"><?= e(number_format($serviceTotal, 2)); ?></td></tr>
                <tr><th>Part Total</th><td class="text-end"><?= e(number_format($partsTotal, 2)); ?></td></tr>
                <tr><th>Grand Total</th><td class="text-end"><strong><?= e(number_format($grandTotal, 2)); ?></strong></td></tr>
              </table>
            </div>
          </div>

          <p class="mb-0 text-muted">This estimate is system-generated and can be converted to a job card after approval.</p>
        </div>
      </div>
    </div>
  </body>
</html>
