<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_once __DIR__ . '/../billing/workflow.php';
require_once __DIR__ . '/../jobs/workflow.php';
require_login();
require_permission('customer.view');

header('Content-Type: application/json; charset=utf-8');

function customer360_job_badge_class(string $status): string
{
    $normalized = strtoupper(trim($status));
    return match ($normalized) {
        'OPEN' => 'secondary',
        'IN_PROGRESS' => 'primary',
        'WAITING_PARTS' => 'warning',
        'READY_FOR_DELIVERY' => 'info',
        'COMPLETED' => 'success',
        'CLOSED' => 'dark',
        'CANCELLED' => 'danger',
        default => 'secondary',
    };
}

function customer360_priority_badge_class(string $priority): string
{
    $normalized = strtoupper(trim($priority));
    return match ($normalized) {
        'LOW' => 'secondary',
        'MEDIUM' => 'info',
        'HIGH' => 'warning',
        'URGENT' => 'danger',
        default => 'secondary',
    };
}

function customer360_render_vehicles(array $vehicles, int $selectedVehicleId): string
{
    ob_start();
    ?>
    <div class="table-responsive">
      <table class="table table-striped table-sm mb-0">
        <thead>
          <tr>
            <th>Registration</th>
            <th>Vehicle</th>
            <th>Fuel / Year</th>
            <th>Odometer</th>
            <th>Total Jobs</th>
            <th>Total Revenue</th>
            <th>Last Visit</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($vehicles === []): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No vehicles found for this customer.</td></tr>
          <?php else: ?>
            <?php foreach ($vehicles as $vehicle): ?>
              <?php
                $isSelected = $selectedVehicleId > 0 && (int) $vehicle['id'] === $selectedVehicleId;
                $vehicleTitle = trim((string) (($vehicle['brand'] ?? '') . ' ' . ($vehicle['model'] ?? '') . ' ' . ($vehicle['variant'] ?? '')));
              ?>
              <tr class="<?= $isSelected ? 'table-primary' : ''; ?>">
                <td><strong><?= e((string) ($vehicle['registration_no'] ?? '-')); ?></strong></td>
                <td><?= e($vehicleTitle !== '' ? $vehicleTitle : '-'); ?></td>
                <td><?= e((string) ($vehicle['fuel_type'] ?? '-')); ?> / <?= e((string) ($vehicle['model_year'] ?? '-')); ?></td>
                <td><?= e(number_format((float) ($vehicle['odometer_km'] ?? 0), 0)); ?> km</td>
                <td><?= (int) ($vehicle['job_count'] ?? 0); ?></td>
                <td><?= e(format_currency((float) ($vehicle['total_revenue'] ?? 0))); ?></td>
                <td><?= e((string) ($vehicle['last_visit_at'] ?? '-')); ?></td>
                <td><span class="badge text-bg-<?= e(status_badge_class((string) ($vehicle['status_code'] ?? 'ACTIVE'))); ?>"><?= e(record_status_label((string) ($vehicle['status_code'] ?? 'ACTIVE'))); ?></span></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php

    return (string) ob_get_clean();
}

function customer360_render_jobs(array $jobs, bool $canViewJobs): string
{
    ob_start();
    if (!$canViewJobs) {
        ?>
        <div class="alert alert-warning mb-0">You do not have permission to view job card details.</div>
        <?php
        return (string) ob_get_clean();
    }
    ?>
    <div class="table-responsive">
      <table class="table table-striped table-sm mb-0">
        <thead>
          <tr>
            <th>Job #</th>
            <th>Vehicle</th>
            <th>Garage</th>
            <th>Opened</th>
            <th>Closed</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Estimate</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($jobs === []): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No job cards found for selected filter.</td></tr>
          <?php else: ?>
            <?php foreach ($jobs as $job): ?>
              <tr>
                <td><?= e((string) ($job['job_number'] ?? '-')); ?></td>
                <td><?= e((string) ($job['registration_no'] ?? '-')); ?></td>
                <td><?= e((string) ($job['garage_name'] ?? '-')); ?></td>
                <td><?= e((string) ($job['opened_at'] ?? '-')); ?></td>
                <td><?= e((string) ($job['closed_at'] ?? '-')); ?></td>
                <td><span class="badge text-bg-<?= e(customer360_priority_badge_class((string) ($job['priority'] ?? 'MEDIUM'))); ?>"><?= e((string) ($job['priority'] ?? 'MEDIUM')); ?></span></td>
                <td><span class="badge text-bg-<?= e(customer360_job_badge_class((string) ($job['status'] ?? 'OPEN'))); ?>"><?= e((string) ($job['status'] ?? 'OPEN')); ?></span></td>
                <td><?= e(format_currency((float) ($job['estimated_cost'] ?? 0))); ?></td>
                <td>
                  <a href="<?= e(url('modules/jobs/view.php?id=' . (int) ($job['id'] ?? 0))); ?>" class="btn btn-sm btn-outline-primary">Open</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php

    return (string) ob_get_clean();
}

function customer360_render_invoices(array $invoices, bool $canViewInvoices): string
{
    ob_start();
    if (!$canViewInvoices) {
        ?>
        <div class="alert alert-warning mb-0">You do not have permission to view invoices.</div>
        <?php
        return (string) ob_get_clean();
    }
    ?>
    <div class="table-responsive">
      <table class="table table-striped table-sm mb-0">
        <thead>
          <tr>
            <th>Invoice #</th>
            <th>Date</th>
            <th>Vehicle</th>
            <th>Garage</th>
            <th>Invoice Status</th>
            <th>Payment</th>
            <th>Total</th>
            <th>Paid</th>
            <th>Outstanding</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($invoices === []): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">No invoices found for selected filter.</td></tr>
          <?php else: ?>
            <?php foreach ($invoices as $invoice): ?>
              <?php
                $status = strtoupper(trim((string) ($invoice['invoice_status'] ?? 'DRAFT')));
                $paidAmount = (float) ($invoice['paid_amount'] ?? 0);
                $totalAmount = (float) ($invoice['grand_total'] ?? 0);
                $outstandingAmount = $status === 'CANCELLED' ? 0.0 : max(0.0, $totalAmount - $paidAmount);
              ?>
              <tr>
                <td><?= e((string) ($invoice['invoice_number'] ?? '-')); ?></td>
                <td><?= e((string) ($invoice['invoice_date'] ?? '-')); ?></td>
                <td><?= e((string) ($invoice['registration_no'] ?? '-')); ?></td>
                <td><?= e((string) ($invoice['garage_name'] ?? '-')); ?></td>
                <td><span class="badge text-bg-<?= e(billing_status_badge_class($status)); ?>"><?= e($status); ?></span></td>
                <td><span class="badge text-bg-<?= e(billing_payment_badge_class((string) ($invoice['payment_status'] ?? 'UNPAID'))); ?>"><?= e((string) ($invoice['payment_status'] ?? 'UNPAID')); ?></span></td>
                <td><?= e(format_currency($totalAmount)); ?></td>
                <td><?= e(format_currency($paidAmount)); ?></td>
                <td><?= e(format_currency($outstandingAmount)); ?></td>
                <td>
                  <a href="<?= e(url('modules/billing/print_invoice.php?id=' . (int) ($invoice['id'] ?? 0))); ?>" class="btn btn-sm btn-outline-primary" target="_blank">Print</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php

    return (string) ob_get_clean();
}

function customer360_render_payments(array $payments, bool $canViewInvoices): string
{
    ob_start();
    if (!$canViewInvoices) {
        ?>
        <div class="alert alert-warning mb-0">You do not have permission to view payments.</div>
        <?php
        return (string) ob_get_clean();
    }
    ?>
    <div class="table-responsive">
      <table class="table table-striped table-sm mb-0">
        <thead>
          <tr>
            <th>Paid On</th>
            <th>Invoice</th>
            <th>Vehicle</th>
            <th>Garage</th>
            <th>Mode</th>
            <th>Reference</th>
            <th>Received By</th>
            <th>Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($payments === []): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No payments found for selected filter.</td></tr>
          <?php else: ?>
            <?php foreach ($payments as $payment): ?>
              <tr>
                <td><?= e((string) ($payment['paid_on'] ?? '-')); ?></td>
                <td>
                  <a href="<?= e(url('modules/billing/print_invoice.php?id=' . (int) ($payment['invoice_id'] ?? 0))); ?>" target="_blank">
                    <?= e((string) ($payment['invoice_number'] ?? '-')); ?>
                  </a>
                </td>
                <td><?= e((string) ($payment['registration_no'] ?? '-')); ?></td>
                <td><?= e((string) ($payment['garage_name'] ?? '-')); ?></td>
                <td><?= e((string) ($payment['payment_mode'] ?? '-')); ?></td>
                <td><?= e((string) ($payment['reference_no'] ?? '-')); ?></td>
                <td><?= e((string) ($payment['received_by_name'] ?? '-')); ?></td>
                <td><?= e(format_currency((float) ($payment['amount'] ?? 0))); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php

    return (string) ob_get_clean();
}

function customer360_render_maintenance(array $rows, bool $featureReady): string
{
    ob_start();
    if (!$featureReady) {
        ?>
        <div class="alert alert-warning mb-0">Maintenance reminder storage is not ready.</div>
        <?php
        return (string) ob_get_clean();
    }
    ?>
    <div class="table-responsive">
      <table class="table table-striped table-sm mb-0">
        <thead>
          <tr>
            <th>Vehicle</th>
            <th>Labour/Part</th>
            <th class="text-end">Last KM</th>
            <th class="text-end">Next Due KM</th>
            <th>Next Due Date</th>
            <th>Status</th>
            <th>Source</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rows === []): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No maintenance reminders found for selected filter.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= e((string) ($row['registration_no'] ?? '-')); ?></td>
                <td><?= e((string) ($row['service_label'] ?? service_reminder_type_label((string) ($row['service_type'] ?? '')))); ?></td>
                <td class="text-end"><?= isset($row['last_service_km']) && $row['last_service_km'] !== null ? e(number_format((float) $row['last_service_km'], 0)) : '-'; ?></td>
                <td class="text-end"><?= isset($row['next_due_km']) && $row['next_due_km'] !== null ? e(number_format((float) $row['next_due_km'], 0)) : '-'; ?></td>
                <td><?= e((string) (($row['next_due_date'] ?? '') !== '' ? $row['next_due_date'] : '-')); ?></td>
                <td><span class="badge text-bg-<?= e(service_reminder_due_badge_class((string) ($row['due_state'] ?? 'UPCOMING'))); ?>"><?= e((string) ($row['due_state'] ?? 'UPCOMING')); ?></span></td>
                <td><?= e((string) ($row['source_type'] ?? '-')); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php

    return (string) ob_get_clean();
}

$companyId = active_company_id();
$customerId = get_int('id');
$selectedVehicleId = get_int('vehicle_id');

if ($customerId <= 0) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid customer selected.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$customerStmt = db()->prepare(
    'SELECT *
     FROM customers
     WHERE id = :id
       AND company_id = :company_id
     LIMIT 1'
);
$customerStmt->execute([
    'id' => $customerId,
    'company_id' => $companyId,
]);
$customer = $customerStmt->fetch();

if (!$customer) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'message' => 'Customer not found.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$vehiclesStmt = db()->prepare(
    'SELECT v.id, v.registration_no, v.brand, v.model, v.variant, v.fuel_type, v.model_year, v.odometer_km, v.status_code,
            COALESCE((
                SELECT COUNT(*)
                FROM job_cards jc
                WHERE jc.company_id = :company_id_jobs
                  AND jc.customer_id = :customer_id_jobs
                  AND jc.vehicle_id = v.id
                  AND jc.status_code <> "DELETED"
            ), 0) AS job_count,
            COALESCE((
                SELECT MAX(COALESCE(jc.closed_at, jc.opened_at))
                FROM job_cards jc
                WHERE jc.company_id = :company_id_last_visit
                  AND jc.customer_id = :customer_id_last_visit
                  AND jc.vehicle_id = v.id
                  AND jc.status_code <> "DELETED"
            ), NULL) AS last_visit_at,
            COALESCE((
                SELECT SUM(i.grand_total)
                FROM invoices i
                WHERE i.company_id = :company_id_revenue
                  AND i.customer_id = :customer_id_revenue
                  AND i.vehicle_id = v.id
                  AND i.invoice_status = "FINALIZED"
            ), 0) AS total_revenue
     FROM vehicles v
     WHERE v.company_id = :company_id
       AND v.customer_id = :customer_id
       AND (v.status_code IS NULL OR v.status_code <> "DELETED")
     ORDER BY v.registration_no ASC, v.id DESC'
);
$vehiclesStmt->execute([
    'company_id' => $companyId,
    'customer_id' => $customerId,
    'company_id_jobs' => $companyId,
    'customer_id_jobs' => $customerId,
    'company_id_last_visit' => $companyId,
    'customer_id_last_visit' => $customerId,
    'company_id_revenue' => $companyId,
    'customer_id_revenue' => $customerId,
]);
$vehicles = $vehiclesStmt->fetchAll();

$validVehicleIds = array_values(array_unique(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $vehicles)));
if ($selectedVehicleId > 0 && !in_array($selectedVehicleId, $validVehicleIds, true)) {
    $selectedVehicleId = 0;
}

$baseParams = [
    'company_id' => $companyId,
    'customer_id' => $customerId,
];
if ($selectedVehicleId > 0) {
    $baseParams['vehicle_id'] = $selectedVehicleId;
}

$canViewJobs = has_permission('job.view');
$canViewInvoices = billing_can_view();

$jobs = [];
if ($canViewJobs) {
    $jobsSql =
        'SELECT jc.id, jc.job_number, jc.vehicle_id, jc.status, jc.priority, jc.opened_at, jc.closed_at, jc.estimated_cost,
                v.registration_no, g.name AS garage_name
         FROM job_cards jc
         INNER JOIN vehicles v ON v.id = jc.vehicle_id
         LEFT JOIN garages g ON g.id = jc.garage_id
         WHERE jc.company_id = :company_id
           AND jc.customer_id = :customer_id
           AND jc.status_code <> "DELETED"';
    if ($selectedVehicleId > 0) {
        $jobsSql .= ' AND jc.vehicle_id = :vehicle_id';
    }
    $jobsSql .= '
         ORDER BY COALESCE(jc.closed_at, jc.opened_at) DESC, jc.id DESC
         LIMIT 300';

    $jobsStmt = db()->prepare($jobsSql);
    $jobsStmt->execute($baseParams);
    $jobs = $jobsStmt->fetchAll();
}

$invoices = [];
if ($canViewInvoices) {
    $invoicesSql =
        'SELECT i.id, i.invoice_number, i.invoice_date, i.invoice_status, i.payment_status, i.grand_total, i.vehicle_id,
                v.registration_no, g.name AS garage_name,
                COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id), 0) AS paid_amount
         FROM invoices i
         LEFT JOIN vehicles v ON v.id = i.vehicle_id
         LEFT JOIN garages g ON g.id = i.garage_id
         WHERE i.company_id = :company_id
           AND i.customer_id = :customer_id';
    if ($selectedVehicleId > 0) {
        $invoicesSql .= ' AND i.vehicle_id = :vehicle_id';
    }
    $invoicesSql .= '
         ORDER BY i.invoice_date DESC, i.id DESC
         LIMIT 300';

    $invoicesStmt = db()->prepare($invoicesSql);
    $invoicesStmt->execute($baseParams);
    $invoices = $invoicesStmt->fetchAll();
}

$payments = [];
if ($canViewInvoices) {
    $paymentsSql =
        'SELECT p.id, p.invoice_id, p.amount, p.paid_on, p.payment_mode, p.reference_no,
                i.invoice_number, i.vehicle_id, v.registration_no, g.name AS garage_name, u.name AS received_by_name
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         LEFT JOIN vehicles v ON v.id = i.vehicle_id
         LEFT JOIN garages g ON g.id = i.garage_id
         LEFT JOIN users u ON u.id = p.received_by
         WHERE i.company_id = :company_id
           AND i.customer_id = :customer_id';
    if ($selectedVehicleId > 0) {
        $paymentsSql .= ' AND i.vehicle_id = :vehicle_id';
    }
    $paymentsSql .= '
         ORDER BY p.paid_on DESC, p.id DESC
         LIMIT 300';

    $paymentsStmt = db()->prepare($paymentsSql);
    $paymentsStmt->execute($baseParams);
    $payments = $paymentsStmt->fetchAll();
}

$maintenanceFeatureReady = service_reminder_feature_ready();
$maintenanceRows = [];
if ($maintenanceFeatureReady) {
    $maintenanceSql =
        'SELECT mr.*, v.registration_no, v.brand, v.model, v.variant
         FROM vehicle_maintenance_reminders mr
         INNER JOIN vehicles v ON v.id = mr.vehicle_id
         WHERE mr.company_id = :company_id
           AND v.customer_id = :customer_id
           AND mr.status_code <> "DELETED"';
    $maintenanceParams = [
        'company_id' => $companyId,
        'customer_id' => $customerId,
    ];
    if ($selectedVehicleId > 0) {
        $maintenanceSql .= ' AND mr.vehicle_id = :vehicle_id';
        $maintenanceParams['vehicle_id'] = $selectedVehicleId;
    }
    $maintenanceSql .= '
         ORDER BY mr.is_active DESC, mr.created_at DESC, mr.id DESC
         LIMIT 300';

    $maintenanceStmt = db()->prepare($maintenanceSql);
    $maintenanceStmt->execute($maintenanceParams);
    $rawMaintenanceRows = $maintenanceStmt->fetchAll();
    foreach ($rawMaintenanceRows as $row) {
        $row['due_state'] = service_reminder_due_state($row);
        $row['service_type'] = (string) ($row['item_type'] ?? '');
        $row['service_label'] = (string) (($row['item_name'] ?? '') !== ''
            ? $row['item_name']
            : (service_reminder_type_label((string) ($row['item_type'] ?? '')) . ' #' . (int) ($row['item_id'] ?? 0)));
        $maintenanceRows[] = $row;
    }
}

$totalRevenue = 0.0;
$outstandingAmount = 0.0;
foreach ($invoices as $invoice) {
    $invoiceStatus = strtoupper(trim((string) ($invoice['invoice_status'] ?? 'DRAFT')));
    if ($invoiceStatus !== 'FINALIZED') {
        continue;
    }

    $grandTotal = (float) ($invoice['grand_total'] ?? 0);
    $paidAmount = (float) ($invoice['paid_amount'] ?? 0);
    $totalRevenue += $grandTotal;
    $outstandingAmount += max(0.0, $grandTotal - $paidAmount);
}

$totalJobsDone = 0;
foreach ($jobs as $job) {
    $status = strtoupper(trim((string) ($job['status'] ?? 'OPEN')));
    if (in_array($status, ['COMPLETED', 'CLOSED'], true)) {
        $totalJobsDone++;
    }
}

echo json_encode([
    'ok' => true,
    'stats' => [
        'total_revenue' => $canViewInvoices ? format_currency($totalRevenue) : 'Restricted',
        'outstanding_amount' => $canViewInvoices ? format_currency($outstandingAmount) : 'Restricted',
        'total_jobs_done' => $canViewJobs ? (string) $totalJobsDone : 'Restricted',
    ],
    'meta' => [
        'selected_vehicle_id' => $selectedVehicleId,
        'vehicle_count' => count($vehicles),
    ],
    'sections' => [
        'vehicles' => customer360_render_vehicles($vehicles, $selectedVehicleId),
        'maintenance' => customer360_render_maintenance($maintenanceRows, $maintenanceFeatureReady),
        'jobs' => customer360_render_jobs($jobs, $canViewJobs),
        'invoices' => customer360_render_invoices($invoices, $canViewInvoices),
        'payments' => customer360_render_payments($payments, $canViewInvoices),
    ],
], JSON_UNESCAPED_UNICODE);

