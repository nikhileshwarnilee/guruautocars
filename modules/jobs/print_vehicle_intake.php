<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/workflow.php';

$canPrint = has_permission('job.print') || has_permission('job.manage') || has_permission('job.view');
if (!$canPrint) {
    flash_set('job_error', 'You do not have permission to print vehicle intake.', 'danger');
    redirect('dashboard.php');
}

$companyId = active_company_id();
$garageId = active_garage_id();
$jobId = get_int('id');

if ($jobId <= 0) {
    flash_set('job_error', 'Invalid job card selected for intake print.', 'danger');
    redirect('modules/jobs/index.php');
}

$jobStmt = db()->prepare(
    'SELECT jc.id, jc.job_number, jc.opened_at, jc.status, jc.odometer_km,
            c.full_name AS customer_name, c.phone AS customer_phone,
            v.registration_no, v.brand, v.model, v.variant, v.fuel_type,
            g.name AS garage_name, g.gstin AS garage_gstin, g.address_line1 AS garage_address, g.city AS garage_city, g.state AS garage_state,
            cp.id AS company_id, cp.name AS company_name, cp.gstin AS company_gstin, cp.address_line1 AS company_address, cp.city AS company_city, cp.state AS company_state
     FROM job_cards jc
     INNER JOIN customers c ON c.id = jc.customer_id
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     INNER JOIN garages g ON g.id = jc.garage_id
     INNER JOIN companies cp ON cp.id = jc.company_id
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
    flash_set('job_error', 'Job card not found for intake print.', 'danger');
    redirect('modules/jobs/index.php');
}

$intakeBundle = job_vehicle_intake_fetch_by_job($companyId, $garageId, $jobId);
if (!is_array($intakeBundle) || !is_array($intakeBundle['intake'] ?? null)) {
    flash_set('job_error', 'Vehicle intake record not found for this job card.', 'danger');
    redirect('modules/jobs/view.php?id=' . $jobId . '#vehicle-intake');
}

$intake = (array) ($intakeBundle['intake'] ?? []);
$checklistItems = (array) ($intakeBundle['checklist_items'] ?? []);
$images = (array) ($intakeBundle['images'] ?? []);
$checklistSummary = (array) ($intakeBundle['checklist_summary'] ?? ['present' => 0, 'not_present' => 0, 'damaged' => 0]);

$companyLogoUrl = company_logo_url((int) ($job['company_id'] ?? $companyId), $garageId);
$fuelLevel = job_vehicle_intake_normalize_fuel_level((string) ($intake['fuel_level'] ?? 'LOW'));
$fuelPercent = job_vehicle_intake_fuel_level_percent($fuelLevel);
$fuelBarClass = $fuelPercent >= 60 ? 'bg-success' : ($fuelPercent >= 30 ? 'bg-info' : ($fuelPercent > 0 ? 'bg-warning' : 'bg-danger'));
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Vehicle Intake <?= e((string) ($job['job_number'] ?? ('#' . $jobId))); ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/adminlte.min.css')); ?>" />
    <style>
      @page {
        size: A4;
        margin: 12mm;
      }

      body {
        padding: 16px;
        background: #f4f6f9;
      }

      .print-sheet {
        max-width: 210mm;
        margin: 0 auto;
      }

      .section-card {
        border: 1px solid #dbe5f5;
        background: #eef4ff;
        border-radius: 8px;
        padding: 10px;
        margin-bottom: 10px;
      }

      .brand-logo {
        max-height: 56px;
        max-width: 180px;
        width: auto;
      }

      .signature-box {
        border: 1px dashed #9aa2af;
        min-height: 80px;
        border-radius: 8px;
        padding: 8px;
      }

      @media print {
        .no-print {
          display: none !important;
        }

        body {
          padding: 0;
          background: #fff;
        }
      }
    </style>
  </head>
  <body>
    <div class="container-fluid print-sheet">
      <div class="d-flex justify-content-between mb-3 no-print">
        <a href="<?= e(url('modules/jobs/view.php?id=' . $jobId . '#vehicle-intake')); ?>" class="btn btn-outline-secondary btn-sm">Back</a>
        <button onclick="window.print()" class="btn btn-primary btn-sm">Print / Save PDF</button>
      </div>

      <div class="card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
              <?php if ($companyLogoUrl !== null): ?>
                <div class="mb-2"><img src="<?= e($companyLogoUrl); ?>" alt="Company Logo" class="brand-logo"></div>
              <?php endif; ?>
              <h4 class="mb-1"><?= e((string) ($job['company_name'] ?? '')); ?></h4>
              <div><?= e((string) ($job['company_address'] ?? '')); ?>, <?= e((string) ($job['company_city'] ?? '')); ?>, <?= e((string) ($job['company_state'] ?? '')); ?></div>
              <div><strong>Garage:</strong> <?= e((string) ($job['garage_name'] ?? '-')); ?></div>
            </div>
            <div class="text-end">
              <h5 class="mb-1">Vehicle Intake Inspection</h5>
              <div><strong>Job Card No:</strong> <?= e((string) ($job['job_number'] ?? ('#' . $jobId))); ?></div>
              <div><strong>Date:</strong> <?= e((string) ($intake['created_at'] ?? ($job['opened_at'] ?? ''))); ?></div>
            </div>
          </div>

          <div class="section-card">
            <h6 class="mb-2">Section 1: Vehicle Details</h6>
            <div class="row">
              <div class="col-md-6">
                <div><strong>Customer:</strong> <?= e((string) ($job['customer_name'] ?? '-')); ?></div>
                <div><strong>Phone:</strong> <?= e((string) ($job['customer_phone'] ?? '-')); ?></div>
              </div>
              <div class="col-md-6">
                <div><strong>Vehicle:</strong> <?= e((string) ($job['registration_no'] ?? '-')); ?></div>
                <div><strong>Model:</strong> <?= e((string) ($job['brand'] ?? '')); ?> <?= e((string) ($job['model'] ?? '')); ?> <?= e((string) ($job['variant'] ?? '')); ?></div>
              </div>
            </div>
          </div>

          <div class="section-card">
            <h6 class="mb-2">Section 2: Fuel Level and Odometer</h6>
            <div class="row align-items-center">
              <div class="col-md-5">
                <div class="small text-muted mb-1">Fuel Level</div>
                <div class="progress" style="height: 16px;">
                  <div class="progress-bar <?= e($fuelBarClass); ?>" role="progressbar" style="width: <?= (int) $fuelPercent; ?>%;">
                    <?= e(str_replace('_', ' ', $fuelLevel)); ?>
                  </div>
                </div>
              </div>
              <div class="col-md-7">
                <div><strong>Odometer:</strong> <?= e(number_format((float) ($intake['odometer_reading'] ?? 0), 0)); ?> KM</div>
                <div class="small text-muted">
                  Customer Acknowledged:
                  <?= ((int) ($intake['customer_acknowledged'] ?? 0) === 1) ? 'YES' : 'NO'; ?>
                  <?php if (!empty($intake['acknowledged_at'])): ?>
                    | At <?= e((string) $intake['acknowledged_at']); ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <div class="section-card">
            <h6 class="mb-2">Section 3: Checklist</h6>
            <div class="mb-2">
              <span class="badge text-bg-success">Present: <?= (int) ($checklistSummary['present'] ?? 0); ?></span>
              <span class="badge text-bg-secondary">Not Present: <?= (int) ($checklistSummary['not_present'] ?? 0); ?></span>
              <span class="badge text-bg-danger">Damaged: <?= (int) ($checklistSummary['damaged'] ?? 0); ?></span>
            </div>
            <div class="table-responsive">
              <table class="table table-bordered table-sm mb-0">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Item</th>
                    <th style="width: 130px;">Status</th>
                    <th>Remarks</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($checklistItems === []): ?>
                    <tr><td colspan="4" class="text-center text-muted">No checklist rows captured.</td></tr>
                  <?php else: ?>
                    <?php $rowNo = 1; ?>
                    <?php foreach ($checklistItems as $item): ?>
                      <?php $status = job_vehicle_intake_normalize_item_status((string) ($item['status'] ?? 'NOT_PRESENT')); ?>
                      <tr>
                        <td><?= $rowNo++; ?></td>
                        <td><?= e((string) ($item['item_name'] ?? '')); ?></td>
                        <td><?= e($status); ?></td>
                        <td><?= e((string) (($item['remarks'] ?? '') !== '' ? $item['remarks'] : '-')); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="section-card">
            <h6 class="mb-2">Section 4: Damage Notes</h6>
            <div><strong>Exterior:</strong> <?= nl2br(e((string) (($intake['exterior_condition_notes'] ?? '') !== '' ? $intake['exterior_condition_notes'] : '-'))); ?></div>
            <div><strong>Interior:</strong> <?= nl2br(e((string) (($intake['interior_condition_notes'] ?? '') !== '' ? $intake['interior_condition_notes'] : '-'))); ?></div>
            <div><strong>Mechanical:</strong> <?= nl2br(e((string) (($intake['mechanical_condition_notes'] ?? '') !== '' ? $intake['mechanical_condition_notes'] : '-'))); ?></div>
            <div><strong>Remarks:</strong> <?= nl2br(e((string) (($intake['remarks'] ?? '') !== '' ? $intake['remarks'] : '-'))); ?></div>
            <div class="small text-muted mt-1">Damage Diagram Placeholder: reserved for future touch-marking module.</div>
          </div>

          <div class="section-card">
            <h6 class="mb-2">Section 5: Intake Images</h6>
            <div class="row g-2">
              <?php if ($images === []): ?>
                <div class="col-12"><div class="text-muted">No intake images available.</div></div>
              <?php else: ?>
                <?php foreach ($images as $image): ?>
                  <?php $imageUrl = job_vehicle_intake_image_url((string) ($image['image_path'] ?? '')); ?>
                  <div class="col-md-3 col-sm-4 col-6">
                    <div class="border rounded p-2 h-100">
                      <?php if ($imageUrl !== null): ?>
                        <img src="<?= e($imageUrl); ?>" alt="Intake Image" class="img-fluid rounded mb-1" style="width:100%;height:100px;object-fit:cover;">
                      <?php else: ?>
                        <div class="bg-light border rounded d-flex align-items-center justify-content-center mb-1" style="height:100px;">
                          <span class="text-muted small">File Missing</span>
                        </div>
                      <?php endif; ?>
                      <div class="small"><?= e(job_vehicle_intake_normalize_image_type((string) ($image['image_type'] ?? 'OTHER'))); ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="section-card mb-0">
            <h6 class="mb-2">Section 6: Signatures</h6>
            <div class="row g-3">
              <div class="col-md-6">
                <div class="signature-box">
                  <div class="small text-muted mb-4">Customer Signature</div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="signature-box">
                  <div class="small text-muted mb-4">Staff Signature</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </body>
</html>

