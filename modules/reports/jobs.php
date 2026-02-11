<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';

reports_require_access();

$page_title = 'Job Reports';
$active_menu = 'reports.jobs';

$scope = reports_build_scope_context();
$companyId = (int) $scope['company_id'];
$garageIds = $scope['garage_ids'];
$garageOptions = $scope['garage_options'];
$allowAllGarages = (bool) $scope['allow_all_garages'];
$selectedGarageId = (int) $scope['selected_garage_id'];
$scopeGarageLabel = (string) $scope['scope_garage_label'];
$financialYears = $scope['financial_years'];
$selectedFyId = (int) $scope['selected_fy_id'];
$fyLabel = (string) $scope['fy_label'];
$fromDate = (string) $scope['from_date'];
$toDate = (string) $scope['to_date'];
$canExportData = (bool) $scope['can_export_data'];
$baseParams = $scope['base_params'];

$summaryParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$summaryScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $summaryParams, 'job_summary_scope');
$summaryStmt = db()->prepare(
    'SELECT COUNT(*) AS closed_jobs,
            COALESCE(AVG(TIMESTAMPDIFF(HOUR, jc.opened_at, jc.closed_at)), 0) AS avg_completion_hours,
            COALESCE(SUM(jc.estimated_cost), 0) AS estimated_total
     FROM job_cards jc
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND jc.opened_at IS NOT NULL
       AND jc.closed_at IS NOT NULL
       ' . $summaryScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date'
);
$summaryStmt->execute($summaryParams);
$jobSummary = $summaryStmt->fetch() ?: ['closed_jobs' => 0, 'avg_completion_hours' => 0, 'estimated_total' => 0];

$dailyParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$dailyScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $dailyParams, 'job_daily_scope');
$dailyStmt = db()->prepare(
    'SELECT DATE(jc.closed_at) AS closed_date,
            COUNT(*) AS closed_jobs,
            COALESCE(SUM(jc.estimated_cost), 0) AS estimated_total
     FROM job_cards jc
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       ' . $dailyScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
     GROUP BY DATE(jc.closed_at)
     ORDER BY closed_date ASC'
);
$dailyStmt->execute($dailyParams);
$closedJobsDaily = $dailyStmt->fetchAll();

$mechanicParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$mechanicScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $mechanicParams, 'job_mechanic_scope');
$mechanicStmt = db()->prepare(
    'SELECT u.name AS mechanic_name,
            COUNT(DISTINCT jc.id) AS closed_jobs,
            ROUND(COALESCE(AVG(TIMESTAMPDIFF(HOUR, jc.opened_at, jc.closed_at)), 0), 2) AS avg_close_hours
     FROM users u
     LEFT JOIN job_assignments ja ON ja.user_id = u.id AND ja.status_code = "ACTIVE"
     LEFT JOIN job_cards jc ON jc.id = ja.job_card_id
       AND jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND jc.opened_at IS NOT NULL
       AND jc.closed_at IS NOT NULL
       ' . $mechanicScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
     WHERE u.company_id = :company_id
       AND u.status_code = "ACTIVE"
     GROUP BY u.id, u.name
     HAVING closed_jobs > 0
     ORDER BY closed_jobs DESC, avg_close_hours ASC
     LIMIT 20'
);
$mechanicStmt->execute($mechanicParams);
$mechanicRows = $mechanicStmt->fetchAll();

$serviceMixParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$serviceMixScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $serviceMixParams, 'job_service_scope');
$serviceMixStmt = db()->prepare(
    'SELECT COALESCE(NULLIF(TRIM(s.service_name), ""), NULLIF(TRIM(jl.description), ""), "Other") AS service_name,
            COUNT(*) AS line_count,
            COALESCE(SUM(jl.total_amount), 0) AS billed_value
     FROM job_labor jl
     INNER JOIN job_cards jc ON jc.id = jl.job_card_id
     LEFT JOIN services s ON s.id = jl.service_id
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       ' . $serviceMixScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
     GROUP BY service_name
     ORDER BY line_count DESC, billed_value DESC
     LIMIT 15'
);
$serviceMixStmt->execute($serviceMixParams);
$serviceMixRows = $serviceMixStmt->fetchAll();

$closedJobsTotal = (int) ($jobSummary['closed_jobs'] ?? 0);
$avgCompletionHours = (float) ($jobSummary['avg_completion_hours'] ?? 0);
$estimatedTotal = (float) ($jobSummary['estimated_total'] ?? 0);
$mechanicCount = count($mechanicRows);

$exportKey = trim((string) ($_GET['export'] ?? ''));
if ($exportKey !== '') {
    if (!$canExportData) {
        http_response_code(403);
        exit('Export access denied.');
    }

    $timestamp = date('Ymd_His');
    switch ($exportKey) {
        case 'closed_jobs_daily':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['closed_date'] ?? ''),
                    (int) ($row['closed_jobs'] ?? 0),
                    (float) ($row['estimated_total'] ?? 0),
                ],
                $closedJobsDaily
            );
            reports_csv_download(
                'job_closed_daily_' . $timestamp . '.csv',
                ['Date', 'Closed Jobs', 'Estimated Value'],
                $rows
            );

        case 'mechanic_productivity':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['mechanic_name'] ?? ''),
                    (int) ($row['closed_jobs'] ?? 0),
                    (float) ($row['avg_close_hours'] ?? 0),
                ],
                $mechanicRows
            );
            reports_csv_download(
                'job_mechanic_productivity_' . $timestamp . '.csv',
                ['Mechanic', 'Closed Jobs', 'Avg Close Hours'],
                $rows
            );

        case 'service_mix':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['service_name'] ?? ''),
                    (int) ($row['line_count'] ?? 0),
                    (float) ($row['billed_value'] ?? 0),
                ],
                $serviceMixRows
            );
            reports_csv_download(
                'job_service_mix_' . $timestamp . '.csv',
                ['Service', 'Lines', 'Billed Value'],
                $rows
            );

        default:
            flash_set('report_error', 'Unknown export requested.', 'warning');
            redirect('modules/reports/jobs.php?' . http_build_query(reports_compact_query_params($baseParams)));
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Job Reports</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Jobs</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-outline card-primary mb-3">
        <div class="card-body">
          <div class="btn-group flex-wrap" role="group" aria-label="Report Pages">
            <?php foreach (reports_module_links() as $link): ?>
              <?php $isActive = $active_menu === (string) $link['menu_key']; ?>
              <a href="<?= e(reports_page_url((string) $link['path'], $baseParams)); ?>" class="btn btn-sm <?= $isActive ? 'btn-primary' : 'btn-outline-primary'; ?>">
                <i class="<?= e((string) $link['icon']); ?> me-1"></i><?= e((string) $link['label']); ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="card card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Filters</h3></div>
        <div class="card-body">
          <form method="get" class="row g-2 align-items-end">
            <?php if ($allowAllGarages || count($garageIds) > 1): ?>
              <div class="col-md-3">
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
              <div class="col-md-3">
                <label class="form-label">Garage Scope</label>
                <input type="text" class="form-control" value="<?= e($scopeGarageLabel); ?>" readonly />
                <input type="hidden" name="garage_id" value="<?= (int) $selectedGarageId; ?>" />
              </div>
            <?php endif; ?>
            <div class="col-md-3">
              <label class="form-label">Financial Year</label>
              <select name="fy_id" class="form-select">
                <?php if (empty($financialYears)): ?>
                  <option value="0" selected><?= e($fyLabel); ?></option>
                <?php else: ?>
                  <?php foreach ($financialYears as $fy): ?>
                    <option value="<?= (int) $fy['id']; ?>" <?= ((int) $fy['id'] === $selectedFyId) ? 'selected' : ''; ?>><?= e((string) $fy['fy_label']); ?></option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>
            <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" required /></div>
            <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" required /></div>
            <div class="col-md-2 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Apply</button>
              <a href="<?= e(url('modules/reports/jobs.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
          <div class="mt-3">
            <span class="badge text-bg-light border me-2">Garage: <?= e($scopeGarageLabel); ?></span>
            <span class="badge text-bg-light border me-2">FY: <?= e($fyLabel); ?></span>
            <span class="badge text-bg-light border me-2">Range: <?= e($fromDate); ?> to <?= e($toDate); ?></span>
            <span class="badge text-bg-success">Trusted Data: Closed Jobs Only</span>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-success"><i class="bi bi-check2-circle"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Closed Jobs</span>
              <span class="info-box-number"><?= number_format($closedJobsTotal); ?></span>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-info"><i class="bi bi-stopwatch"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Avg Completion (Hours)</span>
              <span class="info-box-number"><?= e(number_format($avgCompletionHours, 2)); ?></span>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-primary"><i class="bi bi-currency-rupee"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Estimated Job Value</span>
              <span class="info-box-number"><?= e(format_currency($estimatedTotal)); ?></span>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-secondary"><i class="bi bi-people"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Active Mechanics</span>
              <span class="info-box-number"><?= number_format($mechanicCount); ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Closed Jobs Daily Trend</h3>
              <a href="<?= e(reports_export_url('modules/reports/jobs.php', $baseParams, 'closed_jobs_daily')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Date</th><th>Closed Jobs</th><th>Estimated Value</th></tr></thead>
                <tbody>
                  <?php if (empty($closedJobsDaily)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No closed jobs found.</td></tr>
                  <?php else: foreach ($closedJobsDaily as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['closed_date'] ?? '')); ?></td>
                      <td><?= (int) ($row['closed_jobs'] ?? 0); ?></td>
                      <td><?= e(format_currency((float) ($row['estimated_total'] ?? 0))); ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Mechanic Productivity (Closed Jobs)</h3>
              <a href="<?= e(reports_export_url('modules/reports/jobs.php', $baseParams, 'mechanic_productivity')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Mechanic</th><th>Closed Jobs</th><th>Avg Close Hours</th></tr></thead>
                <tbody>
                  <?php if (empty($mechanicRows)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No mechanic closure data.</td></tr>
                  <?php else: foreach ($mechanicRows as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['mechanic_name'] ?? '')); ?></td>
                      <td><?= (int) ($row['closed_jobs'] ?? 0); ?></td>
                      <td><?= e(number_format((float) ($row['avg_close_hours'] ?? 0), 2)); ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Closed Job Service Mix</h3>
          <a href="<?= e(reports_export_url('modules/reports/jobs.php', $baseParams, 'service_mix')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
        </div>
        <div class="card-body p-0 table-responsive">
          <table class="table table-sm table-striped mb-0">
            <thead><tr><th>Service</th><th>Lines</th><th>Billed Value</th></tr></thead>
            <tbody>
              <?php if (empty($serviceMixRows)): ?>
                <tr><td colspan="3" class="text-center text-muted py-4">No service line data in selected range.</td></tr>
              <?php else: foreach ($serviceMixRows as $row): ?>
                <tr>
                  <td><?= e((string) ($row['service_name'] ?? '')); ?></td>
                  <td><?= (int) ($row['line_count'] ?? 0); ?></td>
                  <td><?= e(format_currency((float) ($row['billed_value'] ?? 0))); ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

