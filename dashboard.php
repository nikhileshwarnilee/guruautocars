<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_login();
require_permission('dashboard.view');

$page_title = 'Dashboard';
$active_menu = 'dashboard';

$companyId = active_company_id();
$garageId = active_garage_id();

$customersStmt = db()->prepare(
    'SELECT COUNT(*)
     FROM customers
     WHERE company_id = :company_id
       AND status_code <> "DELETED"'
);
$customersStmt->execute(['company_id' => $companyId]);
$totalCustomers = (int) $customersStmt->fetchColumn();

$vehiclesStmt = db()->prepare(
    'SELECT COUNT(*)
     FROM vehicles
     WHERE company_id = :company_id
       AND status_code <> "DELETED"'
);
$vehiclesStmt->execute(['company_id' => $companyId]);
$totalVehicles = (int) $vehiclesStmt->fetchColumn();

$jobsStmt = db()->prepare(
    'SELECT COUNT(*)
     FROM job_cards
     WHERE company_id = :company_id
       AND garage_id = :garage_id
       AND status IN ("OPEN", "IN_PROGRESS", "WAITING_PARTS", "READY_FOR_DELIVERY")'
);
$jobsStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$activeJobs = (int) $jobsStmt->fetchColumn();

$revenueStmt = db()->prepare(
    'SELECT COALESCE(SUM(grand_total), 0)
     FROM invoices
     WHERE company_id = :company_id
       AND garage_id = :garage_id
       AND DATE_FORMAT(invoice_date, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m")'
);
$revenueStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$monthlyRevenue = (float) $revenueStmt->fetchColumn();

$recentJobsStmt = db()->prepare(
    'SELECT jc.id, jc.job_number, jc.status, jc.updated_at,
            c.full_name AS customer_name,
            v.registration_no
     FROM job_cards jc
     INNER JOIN customers c ON c.id = jc.customer_id
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     WHERE jc.company_id = :company_id
       AND jc.garage_id = :garage_id
     ORDER BY jc.updated_at DESC
     LIMIT 8'
);
$recentJobsStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$recentJobs = $recentJobsStmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6">
          <h3 class="mb-0">Dashboard</h3>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Dashboard</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="row">
        <div class="col-12 col-sm-6 col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-people-fill"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Total Customers</span>
              <span class="info-box-number erp-stat-number"><?= number_format($totalCustomers); ?></span>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-car-front-fill"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Total Vehicles</span>
              <span class="info-box-number erp-stat-number"><?= number_format($totalVehicles); ?></span>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-card-checklist"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Active Job Cards</span>
              <span class="info-box-number erp-stat-number"><?= number_format($activeJobs); ?></span>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-cash-stack"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Monthly Revenue</span>
              <span class="info-box-number erp-stat-number"><?= e(format_currency($monthlyRevenue)); ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Recent Job Cards</h3>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped table-hover mb-0">
            <thead>
              <tr>
                <th>Job Number</th>
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Status</th>
                <th>Updated</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentJobs)): ?>
                <tr>
                  <td colspan="5" class="text-center text-muted py-4">No jobs yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentJobs as $job): ?>
                  <tr>
                    <td>
                      <a href="<?= e(url('modules/jobs/view.php?id=' . (int) $job['id'])); ?>">
                        <?= e((string) $job['job_number']); ?>
                      </a>
                    </td>
                    <td><?= e((string) $job['customer_name']); ?></td>
                    <td><?= e((string) $job['registration_no']); ?></td>
                    <td><span class="badge text-bg-secondary"><?= e((string) $job['status']); ?></span></td>
                    <td><?= e((string) $job['updated_at']); ?></td>
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
