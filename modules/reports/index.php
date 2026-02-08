<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('report.view');

$page_title = 'Reports & Analytics';
$active_menu = 'reports';

$companyId = active_company_id();
$garageId = active_garage_id();
$isSuperAdmin = (string) ($_SESSION['role_key'] ?? '') === 'super_admin';

$fromDate = (string) ($_GET['from'] ?? date('Y-m-01'));
$toDate = (string) ($_GET['to'] ?? date('Y-m-d'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $toDate = date('Y-m-d');
}

$garageCondition = $isSuperAdmin ? '' : ' AND jc.garage_id = :garage_id ';

$jobStatusSql =
    'SELECT jc.status, COUNT(*) AS total
     FROM job_cards jc
     WHERE jc.company_id = :company_id
       ' . $garageCondition . '
       AND DATE(jc.created_at) BETWEEN :from_date AND :to_date
     GROUP BY jc.status
     ORDER BY total DESC';

$jobStatusStmt = db()->prepare($jobStatusSql);
$params = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
if (!$isSuperAdmin) {
    $params['garage_id'] = $garageId;
}
$jobStatusStmt->execute($params);
$jobStatusRows = $jobStatusStmt->fetchAll();

$revenueSql =
    'SELECT
        COALESCE(SUM(i.grand_total), 0) AS invoice_total,
        COALESCE(SUM(CASE WHEN i.payment_status = "PAID" THEN i.grand_total ELSE 0 END), 0) AS paid_invoice_total
     FROM invoices i
     WHERE i.company_id = :company_id
       ' . ($isSuperAdmin ? '' : ' AND i.garage_id = :garage_id ') . '
       AND i.invoice_date BETWEEN :from_date AND :to_date';

$revenueStmt = db()->prepare($revenueSql);
$revenueParams = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
if (!$isSuperAdmin) {
    $revenueParams['garage_id'] = $garageId;
}
$revenueStmt->execute($revenueParams);
$revenueSummary = $revenueStmt->fetch() ?: ['invoice_total' => 0, 'paid_invoice_total' => 0];

$paymentsByModeSql =
    'SELECT p.payment_mode, COALESCE(SUM(p.amount), 0) AS total_amount
     FROM payments p
     INNER JOIN invoices i ON i.id = p.invoice_id
     WHERE i.company_id = :company_id
       ' . ($isSuperAdmin ? '' : ' AND i.garage_id = :garage_id ') . '
       AND p.paid_on BETWEEN :from_date AND :to_date
     GROUP BY p.payment_mode
     ORDER BY total_amount DESC';
$paymentsByModeStmt = db()->prepare($paymentsByModeSql);
$paymentsParams = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
if (!$isSuperAdmin) {
    $paymentsParams['garage_id'] = $garageId;
}
$paymentsByModeStmt->execute($paymentsParams);
$paymentsByMode = $paymentsByModeStmt->fetchAll();

$staffPerformanceSql =
    'SELECT u.name AS staff_name,
            COUNT(jc.id) AS total_jobs,
            SUM(CASE WHEN jc.status = "COMPLETED" THEN 1 ELSE 0 END) AS completed_jobs
     FROM users u
     LEFT JOIN job_cards jc ON jc.assigned_to = u.id
       AND jc.company_id = :company_id
       ' . ($isSuperAdmin ? '' : ' AND jc.garage_id = :garage_id ') . '
       AND DATE(jc.created_at) BETWEEN :from_date AND :to_date
     WHERE u.company_id = :company_id
       AND u.is_active = 1
     GROUP BY u.id
     ORDER BY completed_jobs DESC, total_jobs DESC
     LIMIT 15';

$staffStmt = db()->prepare($staffPerformanceSql);
$staffParams = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
if (!$isSuperAdmin) {
    $staffParams['garage_id'] = $garageId;
}
$staffStmt->execute($staffParams);
$staffPerformance = $staffStmt->fetchAll();

$garageAnalyticsStmt = db()->prepare(
    'SELECT g.name AS garage_name,
            COUNT(DISTINCT jc.id) AS total_jobs,
            COALESCE(SUM(i.grand_total), 0) AS total_revenue
     FROM garages g
     LEFT JOIN job_cards jc ON jc.garage_id = g.id
       AND jc.company_id = :company_id
       AND DATE(jc.created_at) BETWEEN :from_date AND :to_date
     LEFT JOIN invoices i ON i.garage_id = g.id
       AND i.company_id = :company_id
       AND i.invoice_date BETWEEN :from_date AND :to_date
     WHERE g.company_id = :company_id
     GROUP BY g.id
     ORDER BY total_revenue DESC, total_jobs DESC'
);
$garageAnalyticsStmt->execute([
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
]);
$garageAnalytics = $garageAnalyticsStmt->fetchAll();

$invoiceTotal = (float) ($revenueSummary['invoice_total'] ?? 0);
$paidInvoiceTotal = (float) ($revenueSummary['paid_invoice_total'] ?? 0);
$outstandingTotal = max(0.0, $invoiceTotal - $paidInvoiceTotal);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Reports & Analytics</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Reports</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-primary">
        <div class="card-header"><h3 class="card-title">Report Filters</h3></div>
        <div class="card-body">
          <form method="get" class="row g-2">
            <div class="col-md-3">
              <label class="form-label">From Date</label>
              <input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" required />
            </div>
            <div class="col-md-3">
              <label class="form-label">To Date</label>
              <input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" required />
            </div>
            <div class="col-md-3 d-flex align-items-end">
              <button type="submit" class="btn btn-primary">Apply Filters</button>
            </div>
          </form>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-4">
          <div class="info-box">
            <span class="info-box-icon text-bg-primary"><i class="bi bi-receipt"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Invoice Revenue</span>
              <span class="info-box-number"><?= e(format_currency($invoiceTotal)); ?></span>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="info-box">
            <span class="info-box-icon text-bg-success"><i class="bi bi-cash-coin"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Collected Revenue</span>
              <span class="info-box-number"><?= e(format_currency($paidInvoiceTotal)); ?></span>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="info-box">
            <span class="info-box-icon text-bg-warning"><i class="bi bi-exclamation-triangle"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Outstanding</span>
              <span class="info-box-number"><?= e(format_currency($outstandingTotal)); ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-6">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Job Status Report</h3></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-striped mb-0">
                <thead><tr><th>Status</th><th>Total Jobs</th></tr></thead>
                <tbody>
                  <?php if (empty($jobStatusRows)): ?>
                    <tr><td colspan="2" class="text-center text-muted py-4">No job data in selected date range.</td></tr>
                  <?php else: ?>
                    <?php foreach ($jobStatusRows as $row): ?>
                      <tr>
                        <td><?= e((string) $row['status']); ?></td>
                        <td><?= (int) $row['total']; ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card mt-3">
            <div class="card-header"><h3 class="card-title">Payment Mode Report</h3></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-striped mb-0">
                <thead><tr><th>Payment Mode</th><th>Total Collected</th></tr></thead>
                <tbody>
                  <?php if (empty($paymentsByMode)): ?>
                    <tr><td colspan="2" class="text-center text-muted py-4">No payment data in selected period.</td></tr>
                  <?php else: ?>
                    <?php foreach ($paymentsByMode as $mode): ?>
                      <tr>
                        <td><?= e((string) $mode['payment_mode']); ?></td>
                        <td><?= e(format_currency((float) $mode['total_amount'])); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Staff Performance Report</h3></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-striped mb-0">
                <thead><tr><th>Staff</th><th>Total Jobs</th><th>Completed Jobs</th></tr></thead>
                <tbody>
                  <?php if (empty($staffPerformance)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No staff performance data.</td></tr>
                  <?php else: ?>
                    <?php foreach ($staffPerformance as $staff): ?>
                      <tr>
                        <td><?= e((string) $staff['staff_name']); ?></td>
                        <td><?= (int) $staff['total_jobs']; ?></td>
                        <td><?= (int) $staff['completed_jobs']; ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card mt-3">
            <div class="card-header"><h3 class="card-title">Garage-wise Analytics</h3></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-striped mb-0">
                <thead><tr><th>Garage</th><th>Total Jobs</th><th>Total Revenue</th></tr></thead>
                <tbody>
                  <?php if (empty($garageAnalytics)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No garage analytics data.</td></tr>
                  <?php else: ?>
                    <?php foreach ($garageAnalytics as $garage): ?>
                      <tr>
                        <td><?= e((string) $garage['garage_name']); ?></td>
                        <td><?= (int) $garage['total_jobs']; ?></td>
                        <td><?= e(format_currency((float) $garage['total_revenue'])); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
