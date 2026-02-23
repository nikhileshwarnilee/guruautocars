<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/workflow.php';

$canPrint = has_permission('job.print') || has_permission('job.manage') || has_permission('job.view');
if (!$canPrint) {
    flash_set('job_error', 'You do not have permission to print job cards.', 'danger');
    redirect('dashboard.php');
}

$companyId = active_company_id();
$garageId = active_garage_id();
$jobId = get_int('id');

if ($jobId <= 0) {
    flash_set('job_error', 'Invalid job card selected for print.', 'danger');
    redirect('modules/jobs/index.php');
}

$jobColumns = table_columns('job_cards');
$jobHasOdometer = in_array('odometer_km', $jobColumns, true);
$odometerSelect = $jobHasOdometer ? 'jc.odometer_km,' : 'NULL AS odometer_km,';

$jobStmt = db()->prepare(
    'SELECT jc.*, ' . $odometerSelect . '
            c.full_name AS customer_name, c.phone AS customer_phone, c.gstin AS customer_gstin,
            c.address_line1 AS customer_address, c.city AS customer_city, c.state AS customer_state,
            v.registration_no, v.brand, v.model, v.variant, v.fuel_type,
            sa.name AS advisor_name,
            g.name AS garage_name, g.gstin AS garage_gstin, g.address_line1 AS garage_address, g.city AS garage_city, g.state AS garage_state,
            cp.name AS company_name, cp.gstin AS company_gstin, cp.address_line1 AS company_address, cp.city AS company_city, cp.state AS company_state,
            (
                SELECT GROUP_CONCAT(DISTINCT au.name ORDER BY ja2.is_primary DESC, au.name SEPARATOR ", ")
                FROM job_assignments ja2
                INNER JOIN users au ON au.id = ja2.user_id
                WHERE ja2.job_card_id = jc.id
                  AND ja2.status_code = "ACTIVE"
            ) AS assigned_staff
     FROM job_cards jc
     INNER JOIN customers c ON c.id = jc.customer_id
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     INNER JOIN garages g ON g.id = jc.garage_id
     INNER JOIN companies cp ON cp.id = jc.company_id
     LEFT JOIN users sa ON sa.id = jc.service_advisor_id
     WHERE jc.id = :job_id
       AND jc.company_id = :company_id
       AND jc.garage_id = :garage_id
     LIMIT 1'
);
$jobStmt->execute([
    'job_id' => $jobId,
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$job = $jobStmt->fetch();

if (!$job) {
    flash_set('job_error', 'Job card not found for active garage.', 'danger');
    redirect('modules/jobs/index.php');
}

$jobStatus = job_normalize_status((string) ($job['status'] ?? 'OPEN'));
$jobStatusCode = normalize_status_code((string) ($job['status_code'] ?? 'ACTIVE'));
$restrictedPrint = $jobStatus === 'CANCELLED' || $jobStatusCode === 'DELETED';
$canPrintRestricted = has_permission('job.print.cancelled') || has_permission('job.manage');
if ($restrictedPrint && !$canPrintRestricted) {
    flash_set('job_error', 'Printing cancelled or deleted job cards is blocked for your role.', 'danger');
    redirect('modules/jobs/view.php?id=' . $jobId);
}

$laborStmt = db()->prepare(
    'SELECT jl.*, s.service_name
     FROM job_labor jl
     LEFT JOIN services s ON s.id = jl.service_id
     WHERE jl.job_card_id = :job_id
     ORDER BY jl.id ASC'
);
$laborStmt->execute(['job_id' => $jobId]);
$laborLines = $laborStmt->fetchAll();

$partsStmt = db()->prepare(
    'SELECT jp.*, p.part_name, p.part_sku
     FROM job_parts jp
     INNER JOIN parts p ON p.id = jp.part_id
     WHERE jp.job_card_id = :job_id
     ORDER BY jp.id ASC'
);
$partsStmt->execute(['job_id' => $jobId]);
$partLines = $partsStmt->fetchAll();

$serviceTotal = 0.0;
foreach ($laborLines as $line) {
    $serviceTotal += (float) ($line['total_amount'] ?? 0);
}
$partsTotal = 0.0;
foreach ($partLines as $line) {
    $partsTotal += (float) ($line['total_amount'] ?? 0);
}
$grandTotal = round($serviceTotal + $partsTotal, 2);
$nextServiceReminders = service_reminder_feature_ready()
    ? service_reminder_fetch_active_by_vehicle($companyId, (int) ($job['vehicle_id'] ?? 0), $garageId, 6)
    : [];
$companyLogoUrl = company_logo_url((int) ($job['company_id'] ?? $companyId), $garageId);
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Job Card <?= e((string) ($job['job_number'] ?? ('#' . $jobId))); ?></title>
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

      .print-sheet {
        max-width: 210mm;
        margin: 0 auto;
      }

      .title {
        font-size: 1.3rem;
        font-weight: 700;
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
        <a href="<?= e(url('modules/jobs/view.php?id=' . $jobId)); ?>" class="btn btn-outline-secondary btn-sm">Back</a>
        <button onclick="window.print()" class="btn btn-primary btn-sm">Print / Save PDF</button>
      </div>

      <div class="card">
        <div class="card-body">
          <div class="row mb-3">
            <div class="col-8">
              <?php if ($companyLogoUrl !== null): ?>
                <div class="mb-2"><img src="<?= e($companyLogoUrl); ?>" alt="Company Logo" class="brand-logo" /></div>
              <?php endif; ?>
              <div class="title"><?= e((string) ($job['company_name'] ?? '')); ?></div>
              <div><?= e((string) ($job['company_address'] ?? '')); ?>, <?= e((string) ($job['company_city'] ?? '')); ?>, <?= e((string) ($job['company_state'] ?? '')); ?></div>
              <div>GSTIN: <?= e((string) ($job['company_gstin'] ?? '-')); ?></div>
            </div>
            <div class="col-4 text-end">
              <h4 class="mb-1">Job Card</h4>
              <div><strong>No:</strong> <?= e((string) ($job['job_number'] ?? ('#' . $jobId))); ?></div>
              <div><strong>Opened:</strong> <?= e((string) ($job['opened_at'] ?? '-')); ?></div>
              <div><strong>Status:</strong> <?= e($jobStatus); ?></div>
              <div><strong>Garage:</strong> <?= e((string) ($job['garage_name'] ?? '-')); ?></div>
            </div>
          </div>

          <div class="row mb-3">
            <div class="col-6">
              <h6 class="mb-1">Customer</h6>
              <div><strong><?= e((string) ($job['customer_name'] ?? '-')); ?></strong></div>
              <div>Phone: <?= e((string) ($job['customer_phone'] ?? '-')); ?></div>
              <div>GSTIN: <?= e((string) ($job['customer_gstin'] ?? '-')); ?></div>
              <div><?= e((string) ($job['customer_address'] ?? '')); ?></div>
              <div><?= e((string) ($job['customer_city'] ?? '')); ?><?= (string) ($job['customer_city'] ?? '') !== '' && (string) ($job['customer_state'] ?? '') !== '' ? ', ' : ''; ?><?= e((string) ($job['customer_state'] ?? '')); ?></div>
            </div>
            <div class="col-6 text-end">
              <h6 class="mb-1">Vehicle</h6>
              <div><?= e((string) ($job['registration_no'] ?? '-')); ?></div>
              <div><?= e((string) ($job['brand'] ?? '')); ?> <?= e((string) ($job['model'] ?? '')); ?> <?= e((string) ($job['variant'] ?? '')); ?></div>
              <div>Fuel: <?= e((string) ($job['fuel_type'] ?? '-')); ?></div>
              <?php if ($jobHasOdometer): ?>
                <div>Odometer: <?= e(number_format((float) ($job['odometer_km'] ?? 0), 0)); ?> KM</div>
              <?php endif; ?>
            </div>
          </div>

          <div class="row mb-3">
            <div class="col-6">
              <h6 class="mb-1">Assigned Staff</h6>
              <div><?= e((string) (($job['assigned_staff'] ?? '') !== '' ? $job['assigned_staff'] : 'Unassigned')); ?></div>
            </div>
            <div class="col-6 text-end">
              <h6 class="mb-1">Job Meta</h6>
              <div>Priority: <?= e((string) ($job['priority'] ?? '-')); ?></div>
              <div>Advisor: <?= e((string) (($job['advisor_name'] ?? '') !== '' ? $job['advisor_name'] : '-')); ?></div>
            </div>
          </div>

          <div class="mb-3">
            <h6 class="mb-1">Complaint</h6>
            <div class="border rounded p-2"><?= nl2br(e((string) ($job['complaint'] ?? ''))); ?></div>
          </div>

          <div class="mb-3">
            <h6 class="mb-1">Notes / Diagnosis</h6>
            <div class="border rounded p-2"><?= nl2br(e((string) (($job['diagnosis'] ?? '') !== '' ? $job['diagnosis'] : '-'))); ?></div>
          </div>

          <div class="table-responsive mb-3">
            <table class="table table-bordered table-sm">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Service</th>
                  <th class="text-end">Qty</th>
                  <th class="text-end">Rate</th>
                  <th class="text-end">GST%</th>
                  <th class="text-end">Total</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($laborLines)): ?>
                  <tr><td colspan="6" class="text-center text-muted">No services added.</td></tr>
                <?php else: ?>
                  <?php $rowNo = 1; ?>
                  <?php foreach ($laborLines as $line): ?>
                    <tr>
                      <td><?= $rowNo++; ?></td>
                      <td><?= e((string) (($line['service_name'] ?? '') !== '' ? $line['service_name'] : ($line['description'] ?? ''))); ?></td>
                      <td class="text-end"><?= e(number_format((float) ($line['quantity'] ?? 0), 2)); ?></td>
                      <td class="text-end"><?= e(number_format((float) ($line['unit_price'] ?? 0), 2)); ?></td>
                      <td class="text-end"><?= e(number_format((float) ($line['gst_rate'] ?? 0), 2)); ?></td>
                      <td class="text-end"><?= e(number_format((float) ($line['total_amount'] ?? 0), 2)); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="table-responsive mb-3">
            <table class="table table-bordered table-sm">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Part</th>
                  <th class="text-end">Qty</th>
                  <th class="text-end">Rate</th>
                  <th class="text-end">GST%</th>
                  <th class="text-end">Total</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($partLines)): ?>
                  <tr><td colspan="6" class="text-center text-muted">No parts added.</td></tr>
                <?php else: ?>
                  <?php $rowNo = 1; ?>
                  <?php foreach ($partLines as $line): ?>
                    <tr>
                      <td><?= $rowNo++; ?></td>
                      <td><?= e((string) ($line['part_name'] ?? '')); ?> (<?= e((string) ($line['part_sku'] ?? '')); ?>)</td>
                      <td class="text-end"><?= e(number_format((float) ($line['quantity'] ?? 0), 2)); ?></td>
                      <td class="text-end"><?= e(number_format((float) ($line['unit_price'] ?? 0), 2)); ?></td>
                      <td class="text-end"><?= e(number_format((float) ($line['gst_rate'] ?? 0), 2)); ?></td>
                      <td class="text-end"><?= e(number_format((float) ($line['total_amount'] ?? 0), 2)); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="mb-3">
            <h6 class="mb-1">Next Recommended Service</h6>
            <div class="table-responsive">
              <table class="table table-bordered table-sm mb-0">
                <thead>
                  <tr>
                    <th>Service</th>
                    <th class="text-end">Due KM</th>
                    <th>Due Date</th>
                    <th>Predicted Visit</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($nextServiceReminders)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No active service reminders.</td></tr>
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
            </div>
          </div>

          <div class="row">
            <div class="col-7">
              <?php if (!empty($job['cancel_note'])): ?>
                <div class="alert alert-danger py-2 mb-0">
                  <strong>Cancel Note:</strong> <?= e((string) $job['cancel_note']); ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="col-5">
              <table class="table table-sm table-bordered">
                <tr><th>Service Total</th><td class="text-end"><?= e(number_format($serviceTotal, 2)); ?></td></tr>
                <tr><th>Parts Total</th><td class="text-end"><?= e(number_format($partsTotal, 2)); ?></td></tr>
                <tr><th>Grand Total</th><td class="text-end"><strong><?= e(number_format($grandTotal, 2)); ?></strong></td></tr>
              </table>
            </div>
          </div>

          <p class="mb-0 text-muted">System generated from Guru Auto Cars ERP job workflow.</p>
        </div>
      </div>
    </div>
  </body>
</html>
