<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_login();
require_permission('dashboard.view');

$page_title = 'Dashboard Intelligence';
$active_menu = 'dashboard';

$companyId = active_company_id();
$activeGarageId = active_garage_id();
$currentUser = current_user();
$roleKey = (string) ($currentUser['role_key'] ?? ($_SESSION['role_key'] ?? ''));
$isOwnerScope = analytics_is_owner_role($roleKey);

$garageOptions = analytics_accessible_garages($companyId, $isOwnerScope);
if (empty($garageOptions) && $activeGarageId > 0) {
    $fallbackGarageStmt = db()->prepare(
        'SELECT id, name, code
         FROM garages
         WHERE id = :garage_id
           AND company_id = :company_id
         LIMIT 1'
    );
    $fallbackGarageStmt->execute([
        'garage_id' => $activeGarageId,
        'company_id' => $companyId,
    ]);
    $fallbackGarage = $fallbackGarageStmt->fetch();
    if ($fallbackGarage) {
        $garageOptions[] = $fallbackGarage;
    }
}

$garageIds = array_values(
    array_filter(
        array_map(static fn (array $garage): int => (int) ($garage['id'] ?? 0), $garageOptions),
        static fn (int $id): bool => $id > 0
    )
);

$allowAllGarages = $isOwnerScope && count($garageIds) > 1;
$garageRequested = isset($_GET['garage_id']) ? get_int('garage_id', $activeGarageId) : $activeGarageId;
$selectedGarageId = analytics_resolve_scope_garage_id($garageOptions, $activeGarageId, $garageRequested, $allowAllGarages);
$scopeGarageLabel = analytics_scope_garage_label($garageOptions, $selectedGarageId);

$fyContext = analytics_resolve_financial_year($companyId, get_int('fy_id', 0));
$financialYears = $fyContext['years'];
$selectedFy = $fyContext['selected'];

$fyStart = (string) ($selectedFy['start_date'] ?? date('Y-04-01'));
$fyEnd = (string) ($selectedFy['end_date'] ?? date('Y-03-31', strtotime('+1 year')));
$today = date('Y-m-d');
$todayBounded = $today;
if ($todayBounded < $fyStart) {
    $todayBounded = $fyStart;
}
if ($todayBounded > $fyEnd) {
    $todayBounded = $fyEnd;
}

$mtdStart = date('Y-m-01', strtotime($todayBounded));
if ($mtdStart < $fyStart) {
    $mtdStart = $fyStart;
}

$canViewFinancial = has_permission('reports.financial')
    || has_permission('report.view')
    || has_permission('billing.view')
    || has_permission('invoice.view');

$revenueSummary = [
    'revenue_today' => 0,
    'revenue_mtd' => 0,
    'revenue_ytd' => 0,
    'invoice_count_today' => 0,
    'invoice_count_mtd' => 0,
    'invoice_count_ytd' => 0,
];

if ($canViewFinancial) {
    $revenueParams = [
        'company_id' => $companyId,
        'today' => $todayBounded,
        'mtd_start' => $mtdStart,
        'fy_start' => $fyStart,
    ];
    $revenueGarageScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $revenueParams, 'rev_garage');

    $revenueStmt = db()->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN i.invoice_date = :today THEN i.grand_total ELSE 0 END), 0) AS revenue_today,
            COALESCE(SUM(CASE WHEN i.invoice_date BETWEEN :mtd_start AND :today THEN i.grand_total ELSE 0 END), 0) AS revenue_mtd,
            COALESCE(SUM(CASE WHEN i.invoice_date BETWEEN :fy_start AND :today THEN i.grand_total ELSE 0 END), 0) AS revenue_ytd,
            COALESCE(SUM(CASE WHEN i.invoice_date = :today THEN 1 ELSE 0 END), 0) AS invoice_count_today,
            COALESCE(SUM(CASE WHEN i.invoice_date BETWEEN :mtd_start AND :today THEN 1 ELSE 0 END), 0) AS invoice_count_mtd,
            COALESCE(SUM(CASE WHEN i.invoice_date BETWEEN :fy_start AND :today THEN 1 ELSE 0 END), 0) AS invoice_count_ytd
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $revenueGarageScopeSql . '
           AND i.invoice_date BETWEEN :fy_start AND :today'
    );
    $revenueStmt->execute($revenueParams);
    $revenueSummary = $revenueStmt->fetch() ?: $revenueSummary;
}

$jobParams = [
    'company_id' => $companyId,
    'fy_start' => $fyStart,
    'today' => $todayBounded,
];
$jobGarageScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $jobParams, 'job_garage');
$jobKpiStmt = db()->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN jc.status = "OPEN" THEN 1 ELSE 0 END), 0) AS open_jobs,
        COALESCE(SUM(CASE WHEN jc.status IN ("IN_PROGRESS", "WAITING_PARTS", "READY_FOR_DELIVERY", "COMPLETED") THEN 1 ELSE 0 END), 0) AS in_progress_jobs,
        COALESCE(SUM(CASE
            WHEN jc.status = "CLOSED"
             AND DATE(COALESCE(jc.closed_at, jc.updated_at, jc.created_at)) BETWEEN :fy_start AND :today THEN 1
            ELSE 0
        END), 0) AS closed_jobs
     FROM job_cards jc
     WHERE jc.company_id = :company_id
       AND jc.status_code = "ACTIVE"
       ' . $jobGarageScopeSql
);
$jobKpiStmt->execute($jobParams);
$jobKpis = $jobKpiStmt->fetch() ?: [
    'open_jobs' => 0,
    'in_progress_jobs' => 0,
    'closed_jobs' => 0,
];

$inventoryJoinParams = [
    'company_id' => $companyId,
];
if ($selectedGarageId > 0) {
    $inventoryJoinScopeSql = ' AND gi.garage_id = :inv_garage_selected ';
    $inventoryJoinParams['inv_garage_selected'] = $selectedGarageId;
} else {
    if (empty($garageIds)) {
        $inventoryJoinScopeSql = ' AND 1 = 0 ';
    } else {
        $inventoryPlaceholders = [];
        foreach ($garageIds as $index => $garageId) {
            $key = 'inv_garage_' . $index;
            $inventoryJoinParams[$key] = $garageId;
            $inventoryPlaceholders[] = ':' . $key;
        }
        $inventoryJoinScopeSql = ' AND gi.garage_id IN (' . implode(', ', $inventoryPlaceholders) . ') ';
    }
}

$lowStockStmt = db()->prepare(
    'SELECT COUNT(*)
     FROM (
         SELECT p.id
         FROM parts p
         LEFT JOIN garage_inventory gi ON gi.part_id = p.id ' . $inventoryJoinScopeSql . '
         WHERE p.company_id = :company_id
           AND p.status_code = "ACTIVE"
         GROUP BY p.id, p.min_stock
         HAVING COALESCE(SUM(gi.quantity), 0) <= p.min_stock
     ) AS low_stock_parts'
);
$lowStockStmt->execute($inventoryJoinParams);
$lowStockCount = (int) $lowStockStmt->fetchColumn();

$fastParams = [
    'company_id' => $companyId,
    'fy_start' => $fyStart,
    'today' => $todayBounded,
];
$fastGarageScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $fastParams, 'fast_garage');
$fastMovingStmt = db()->prepare(
    'SELECT p.part_name, p.part_sku, p.unit,
            COALESCE(SUM(jp.quantity), 0) AS total_qty,
            COUNT(DISTINCT jc.id) AS jobs_count
     FROM job_parts jp
     INNER JOIN job_cards jc ON jc.id = jp.job_card_id
     INNER JOIN invoices i ON i.job_card_id = jc.id
     INNER JOIN parts p ON p.id = jp.part_id
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND i.company_id = :company_id
       AND i.invoice_status = "FINALIZED"
       AND p.company_id = :company_id
       AND p.status_code = "ACTIVE"
       ' . $fastGarageScopeSql . '
       AND DATE(COALESCE(jc.closed_at, jc.updated_at, jc.created_at)) BETWEEN :fy_start AND :today
     GROUP BY p.id, p.part_name, p.part_sku, p.unit
     ORDER BY total_qty DESC
     LIMIT 5'
);
$fastMovingStmt->execute($fastParams);
$fastMovingParts = $fastMovingStmt->fetchAll();

$recentParams = [
    'company_id' => $companyId,
];
$recentGarageScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $recentParams, 'recent_garage');
$recentJobsStmt = db()->prepare(
    'SELECT jc.id, jc.job_number, jc.status, jc.closed_at, jc.updated_at,
            c.full_name AS customer_name,
            v.registration_no
     FROM job_cards jc
     INNER JOIN customers c ON c.id = jc.customer_id
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     WHERE jc.company_id = :company_id
       AND jc.status_code = "ACTIVE"
       ' . $recentGarageScopeSql . '
     ORDER BY COALESCE(jc.closed_at, jc.updated_at, jc.created_at) DESC
     LIMIT 8'
);
$recentJobsStmt->execute($recentParams);
$recentJobs = $recentJobsStmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6">
          <h3 class="mb-0">Dashboard Intelligence</h3>
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
      <div class="card card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Dashboard Scope</h3></div>
        <div class="card-body">
          <form method="get" class="row g-2 align-items-end">
            <?php if ($allowAllGarages || count($garageIds) > 1): ?>
              <div class="col-md-4">
                <label class="form-label">Garage Scope</label>
                <select name="garage_id" class="form-select">
                  <?php if ($allowAllGarages): ?>
                    <option value="0" <?= $selectedGarageId === 0 ? 'selected' : ''; ?>>All Accessible Garages</option>
                  <?php endif; ?>
                  <?php foreach ($garageOptions as $garage): ?>
                    <option value="<?= (int) $garage['id']; ?>" <?= ((int) $garage['id'] === $selectedGarageId) ? 'selected' : ''; ?>>
                      <?= e((string) $garage['name']); ?> (<?= e((string) $garage['code']); ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <div class="col-md-4">
                <label class="form-label">Garage Scope</label>
                <input type="text" class="form-control" value="<?= e($scopeGarageLabel); ?>" readonly />
                <input type="hidden" name="garage_id" value="<?= (int) $selectedGarageId; ?>" />
              </div>
            <?php endif; ?>

            <div class="col-md-4">
              <label class="form-label">Financial Year</label>
              <select name="fy_id" class="form-select">
                <?php if (empty($financialYears)): ?>
                  <option value="0" selected><?= e((string) ($selectedFy['fy_label'] ?? 'Current FY')); ?></option>
                <?php else: ?>
                  <?php foreach ($financialYears as $fy): ?>
                    <option value="<?= (int) $fy['id']; ?>" <?= ((int) $fy['id'] === (int) ($selectedFy['id'] ?? 0)) ? 'selected' : ''; ?>>
                      <?= e((string) $fy['fy_label']); ?> (<?= e((string) $fy['start_date']); ?> to <?= e((string) $fy['end_date']); ?>)
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>

            <div class="col-md-4 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Apply</button>
              <a href="<?= e(url('dashboard.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
          <div class="mt-3">
            <span class="badge text-bg-light border me-2">Garage: <?= e($scopeGarageLabel); ?></span>
            <span class="badge text-bg-light border me-2">FY: <?= e((string) ($selectedFy['fy_label'] ?? '-')); ?></span>
            <span class="badge text-bg-light border">Data: Closed Jobs + Finalized Invoices + Valid Inventory</span>
          </div>
        </div>
      </div>

      <?php if ($canViewFinancial): ?>
        <div class="row g-3 mb-3">
          <div class="col-12 col-sm-6 col-lg-4">
            <div class="info-box">
              <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-currency-rupee"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Revenue Today (Finalized)</span>
                <span class="info-box-number erp-stat-number"><?= e(format_currency((float) ($revenueSummary['revenue_today'] ?? 0))); ?></span>
                <small class="text-muted">Invoices: <?= (int) ($revenueSummary['invoice_count_today'] ?? 0); ?></small>
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-lg-4">
            <div class="info-box">
              <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-calendar2-week"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Revenue MTD</span>
                <span class="info-box-number erp-stat-number"><?= e(format_currency((float) ($revenueSummary['revenue_mtd'] ?? 0))); ?></span>
                <small class="text-muted">Invoices: <?= (int) ($revenueSummary['invoice_count_mtd'] ?? 0); ?></small>
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-lg-4">
            <div class="info-box">
              <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-graph-up"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Revenue YTD</span>
                <span class="info-box-number erp-stat-number"><?= e(format_currency((float) ($revenueSummary['revenue_ytd'] ?? 0))); ?></span>
                <small class="text-muted">Invoices: <?= (int) ($revenueSummary['invoice_count_ytd'] ?? 0); ?></small>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="row g-3 mb-3">
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-folder2-open"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Open Jobs</span>
              <span class="info-box-number erp-stat-number"><?= number_format((int) ($jobKpis['open_jobs'] ?? 0)); ?></span>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-tools"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">In-Progress Jobs</span>
              <span class="info-box-number erp-stat-number"><?= number_format((int) ($jobKpis['in_progress_jobs'] ?? 0)); ?></span>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check2-circle"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Closed Jobs (FY)</span>
              <span class="info-box-number erp-stat-number"><?= number_format((int) ($jobKpis['closed_jobs'] ?? 0)); ?></span>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-exclamation-triangle"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Low Stock Parts</span>
              <span class="info-box-number erp-stat-number"><?= number_format($lowStockCount); ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-6">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Fast-Moving Parts (Job Consumption, FY)</h3></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-striped mb-0">
                <thead>
                  <tr>
                    <th>Part</th>
                    <th>Jobs</th>
                    <th>Qty</th>
                    <th>Trend</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($fastMovingParts)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No closed/finalized parts usage data yet.</td></tr>
                  <?php else: ?>
                    <?php $maxQty = (float) max(array_map(static fn (array $row): float => (float) $row['total_qty'], $fastMovingParts)); ?>
                    <?php foreach ($fastMovingParts as $part): ?>
                      <?php $qty = (float) $part['total_qty']; ?>
                      <tr>
                        <td><?= e((string) $part['part_name']); ?> <small class="text-muted">(<?= e((string) $part['part_sku']); ?>)</small></td>
                        <td><?= (int) $part['jobs_count']; ?></td>
                        <td><?= e(number_format($qty, 2)); ?> <?= e((string) $part['unit']); ?></td>
                        <td style="min-width:160px;">
                          <div class="progress progress-xs">
                            <div class="progress-bar bg-info" style="width: <?= e((string) analytics_progress_width($qty, $maxQty)); ?>%"></div>
                          </div>
                        </td>
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
            <div class="card-header"><h3 class="card-title">Recent Job Activity</h3></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-striped table-hover mb-0">
                <thead>
                  <tr>
                    <th>Job Number</th>
                    <th>Customer</th>
                    <th>Vehicle</th>
                    <th>Status</th>
                    <th>Updated / Closed</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($recentJobs)): ?>
                    <tr>
                      <td colspan="5" class="text-center text-muted py-4">No jobs in current scope.</td>
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
                        <td><?= e((string) ($job['closed_at'] ?? $job['updated_at'] ?? '-')); ?></td>
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
