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
$dateMode = (string) $scope['date_mode'];
$dateModeOptions = $scope['date_mode_options'];
$fromDate = (string) $scope['from_date'];
$toDate = (string) $scope['to_date'];
$canExportData = (bool) $scope['can_export_data'];
$baseParams = $scope['base_params'];
$jobTypeEnabled = in_array('job_type_id', table_columns('job_cards'), true);
$jobTypeCatalog = [];
$jobTypeLabelsById = [];
if ($jobTypeEnabled) {
    foreach (job_type_catalog($companyId) as $jobTypeRow) {
        $sanitized = job_type_sanitize_row((array) $jobTypeRow);
        if ($sanitized === null) {
            continue;
        }
        $jobTypeId = (int) ($sanitized['id'] ?? 0);
        if ($jobTypeId <= 0) {
            continue;
        }
        if (normalize_status_code((string) ($sanitized['status_code'] ?? 'ACTIVE')) === 'DELETED') {
            continue;
        }
        $jobTypeCatalog[$jobTypeId] = [
            'id' => $jobTypeId,
            'name' => (string) ($sanitized['name'] ?? ('Job Type #' . $jobTypeId)),
            'status_code' => normalize_status_code((string) ($sanitized['status_code'] ?? 'ACTIVE')),
        ];
        $jobTypeLabelsById[$jobTypeId] = (string) ($jobTypeCatalog[$jobTypeId]['name'] ?? ('Job Type #' . $jobTypeId));
    }
    uasort($jobTypeCatalog, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
}
$jobTypeFilterId = $jobTypeEnabled ? max(0, get_int('job_type_id', 0)) : 0;
$jobTypeFilterLabel = $jobTypeFilterId > 0
    ? ((string) ($jobTypeLabelsById[$jobTypeFilterId] ?? ('Job Type #' . $jobTypeFilterId)))
    : 'All Job Types';
$jobTypeFilterSql = $jobTypeEnabled && $jobTypeFilterId > 0 ? ' AND jc.job_type_id = :job_type_id' : '';
$reportParams = $baseParams;
if ($jobTypeEnabled && $jobTypeFilterId > 0) {
    $reportParams['job_type_id'] = $jobTypeFilterId;
}

$summaryParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
if ($jobTypeEnabled && $jobTypeFilterId > 0) {
    $summaryParams['job_type_id'] = $jobTypeFilterId;
}
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
       ' . $jobTypeFilterSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date'
);
$summaryStmt->execute($summaryParams);
$jobSummary = $summaryStmt->fetch() ?: ['closed_jobs' => 0, 'avg_completion_hours' => 0, 'estimated_total' => 0];

$dailyParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
if ($jobTypeEnabled && $jobTypeFilterId > 0) {
    $dailyParams['job_type_id'] = $jobTypeFilterId;
}
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
       ' . $jobTypeFilterSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
     GROUP BY DATE(jc.closed_at)
     ORDER BY closed_date ASC'
);
$dailyStmt->execute($dailyParams);
$closedJobsDaily = $dailyStmt->fetchAll();

$completionTrendParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
if ($jobTypeEnabled && $jobTypeFilterId > 0) {
    $completionTrendParams['job_type_id'] = $jobTypeFilterId;
}
$completionTrendScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $completionTrendParams, 'job_completion_scope');
$completionTrendStmt = db()->prepare(
    'SELECT DATE(jc.closed_at) AS closed_date,
            ROUND(COALESCE(AVG(TIMESTAMPDIFF(HOUR, jc.opened_at, jc.closed_at)), 0), 2) AS avg_completion_hours
     FROM job_cards jc
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND jc.opened_at IS NOT NULL
       AND jc.closed_at IS NOT NULL
       ' . $completionTrendScopeSql . '
       ' . $jobTypeFilterSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
     GROUP BY DATE(jc.closed_at)
     ORDER BY closed_date ASC'
);
$completionTrendStmt->execute($completionTrendParams);
$completionTrendRows = $completionTrendStmt->fetchAll();

$mechanicParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
if ($jobTypeEnabled && $jobTypeFilterId > 0) {
    $mechanicParams['job_type_id'] = $jobTypeFilterId;
}
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
       ' . $jobTypeFilterSql . '
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
if ($jobTypeEnabled && $jobTypeFilterId > 0) {
    $serviceMixParams['job_type_id'] = $jobTypeFilterId;
}
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
       ' . $jobTypeFilterSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
     GROUP BY service_name
     ORDER BY line_count DESC, billed_value DESC
     LIMIT 15'
);
$serviceMixStmt->execute($serviceMixParams);
$serviceMixRows = $serviceMixStmt->fetchAll();

$jobTypeParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
if ($jobTypeEnabled && $jobTypeFilterId > 0) {
    $jobTypeParams['job_type_id'] = $jobTypeFilterId;
}
$jobTypeScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $jobTypeParams, 'job_type_scope');
$jobTypeStmt = db()->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN outsourced.job_card_id IS NULL THEN 1 ELSE 0 END), 0) AS in_house_jobs,
        COALESCE(SUM(CASE WHEN outsourced.job_card_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS outsourced_jobs
     FROM job_cards jc
     INNER JOIN invoices i ON i.job_card_id = jc.id
       AND i.company_id = :company_id
       AND i.invoice_status = "FINALIZED"
     LEFT JOIN (
        SELECT DISTINCT jl.job_card_id
        FROM job_labor jl
        WHERE jl.execution_type = "OUTSOURCED"
     ) outsourced ON outsourced.job_card_id = jc.id
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       ' . $jobTypeScopeSql . '
       ' . $jobTypeFilterSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date'
);
$jobTypeStmt->execute($jobTypeParams);
$jobTypeMix = $jobTypeStmt->fetch() ?: ['in_house_jobs' => 0, 'outsourced_jobs' => 0];

$closedJobsTotal = (int) ($jobSummary['closed_jobs'] ?? 0);
$avgCompletionHours = (float) ($jobSummary['avg_completion_hours'] ?? 0);
$estimatedTotal = (float) ($jobSummary['estimated_total'] ?? 0);
$mechanicCount = count($mechanicRows);

$mechanicChartRows = array_slice($mechanicRows, 0, 10);
$chartPayload = [
    'job_volume' => [
        'labels' => array_map(static fn (array $row): string => (string) ($row['closed_date'] ?? ''), $closedJobsDaily),
        'values' => array_map(static fn (array $row): int => (int) ($row['closed_jobs'] ?? 0), $closedJobsDaily),
    ],
    'avg_completion' => [
        'labels' => array_map(static fn (array $row): string => (string) ($row['closed_date'] ?? ''), $completionTrendRows),
        'values' => array_map(static fn (array $row): float => (float) ($row['avg_completion_hours'] ?? 0), $completionTrendRows),
    ],
    'mechanic_productivity' => [
        'labels' => array_map(static fn (array $row): string => (string) ($row['mechanic_name'] ?? ''), $mechanicChartRows),
        'values' => array_map(static fn (array $row): int => (int) ($row['closed_jobs'] ?? 0), $mechanicChartRows),
    ],
    'execution_mix' => [
        'labels' => ['In-House', 'Outsourced'],
        'values' => [
            (int) ($jobTypeMix['in_house_jobs'] ?? 0),
            (int) ($jobTypeMix['outsourced_jobs'] ?? 0),
        ],
    ],
];
$chartPayloadJson = json_encode(
    $chartPayload,
    JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
);

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
                ['Labour', 'Lines', 'Billed Value'],
                $rows
            );

        default:
            flash_set('report_error', 'Unknown export requested.', 'warning');
            redirect('modules/reports/jobs.php?' . http_build_query(reports_compact_query_params($reportParams)));
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
      <?php reports_render_page_navigation($active_menu, $reportParams); ?>

      <div class="card card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Filters</h3></div>
        <div class="card-body">
          <form
            method="get"
            id="jobs-report-filter-form"
            class="row g-2 align-items-end"
            data-date-filter-form="1"
            data-date-range-start="<?= e((string) $scope['date_range_start']); ?>"
            data-date-range-end="<?= e((string) $scope['date_range_end']); ?>"
            data-date-yearly-start="<?= e((string) $scope['fy_start']); ?>"
          >
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
            <?php if ($jobTypeEnabled): ?>
              <div class="col-md-3">
                <label class="form-label">Job Type</label>
                <select name="job_type_id" class="form-select">
                  <option value="0">All Job Types</option>
                  <?php foreach ($jobTypeCatalog as $jobTypeOption): ?>
                    <?php $jobTypeOptionId = (int) ($jobTypeOption['id'] ?? 0); ?>
                    <option value="<?= $jobTypeOptionId; ?>" <?= $jobTypeFilterId === $jobTypeOptionId ? 'selected' : ''; ?>>
                      <?= e((string) ($jobTypeOption['name'] ?? ('Job Type #' . $jobTypeOptionId))); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>
            <div class="col-md-2">
              <label class="form-label">Date Mode</label>
              <select name="date_mode" class="form-select">
                <?php foreach ($dateModeOptions as $modeValue => $modeLabel): ?>
                  <option value="<?= e((string) $modeValue); ?>" <?= $dateMode === $modeValue ? 'selected' : ''; ?>>
                    <?= e((string) $modeLabel); ?>
                  </option>
                <?php endforeach; ?>
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
            <?php if ($jobTypeEnabled): ?>
              <span class="badge text-bg-light border me-2">Job Type: <?= e($jobTypeFilterLabel); ?></span>
            <?php endif; ?>
            <span class="badge text-bg-light border me-2">Range: <?= e($fromDate); ?> to <?= e($toDate); ?></span>
            <span class="badge text-bg-success">Trusted Data: Closed Jobs Only</span>
          </div>
        </div>
      </div>

      <div id="jobs-report-content">
        <script type="application/json" data-chart-payload><?= $chartPayloadJson ?: '{}'; ?></script>

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
            <div class="card-header"><h3 class="card-title mb-0">Job Volume Trend</h3></div>
            <div class="card-body">
              <div class="gac-chart-wrap"><canvas id="jobs-chart-volume"></canvas></div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header"><h3 class="card-title mb-0">Average Job Completion Time (Hours)</h3></div>
            <div class="card-body">
              <div class="gac-chart-wrap"><canvas id="jobs-chart-completion"></canvas></div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header"><h3 class="card-title mb-0">Mechanic Productivity</h3></div>
            <div class="card-body">
              <div class="gac-chart-wrap"><canvas id="jobs-chart-mechanics"></canvas></div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header"><h3 class="card-title mb-0">Outsourced vs In-House Jobs</h3></div>
            <div class="card-body">
              <div class="gac-chart-wrap"><canvas id="jobs-chart-execution"></canvas></div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Closed Jobs Daily Trend</h3>
              <a href="<?= e(reports_export_url('modules/reports/jobs.php', $reportParams, 'closed_jobs_daily')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
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
              <a href="<?= e(reports_export_url('modules/reports/jobs.php', $reportParams, 'mechanic_productivity')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a>
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
          <h3 class="card-title mb-0">Closed Job Labour Mix</h3>
          <a href="<?= e(reports_export_url('modules/reports/jobs.php', $reportParams, 'service_mix')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
        </div>
        <div class="card-body p-0 table-responsive">
          <table class="table table-sm table-striped mb-0">
            <thead><tr><th>Labour</th><th>Lines</th><th>Billed Value</th></tr></thead>
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
  </div>
</main>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (!window.GacCharts) {
      return;
    }

    var form = document.getElementById('jobs-report-filter-form');
    var content = document.getElementById('jobs-report-content');
    if (!form || !content) {
      return;
    }

    var charts = window.GacCharts.createRegistry('jobs-report');

    function renderCharts() {
      var payload = window.GacCharts.parsePayload(content);
      var chartData = payload || {};

      charts.render('#jobs-chart-volume', {
        type: 'line',
        data: {
          labels: chartData.job_volume ? chartData.job_volume.labels : [],
          datasets: [{
            label: 'Closed Jobs',
            data: chartData.job_volume ? chartData.job_volume.values : [],
            borderColor: window.GacCharts.palette.blue,
            backgroundColor: window.GacCharts.palette.blue + '33',
            fill: true,
            tension: 0.25
          }]
        },
        options: window.GacCharts.commonOptions()
      }, { emptyMessage: 'No closed job trend data in selected range.' });

      charts.render('#jobs-chart-completion', {
        type: 'bar',
        data: {
          labels: chartData.avg_completion ? chartData.avg_completion.labels : [],
          datasets: [{
            label: 'Avg Completion Hours',
            data: chartData.avg_completion ? chartData.avg_completion.values : [],
            backgroundColor: window.GacCharts.palette.orange
          }]
        },
        options: window.GacCharts.commonOptions()
      }, { emptyMessage: 'No completion-time rows in selected range.' });

      charts.render('#jobs-chart-mechanics', {
        type: 'bar',
        data: {
          labels: chartData.mechanic_productivity ? chartData.mechanic_productivity.labels : [],
          datasets: [{
            label: 'Closed Jobs',
            data: chartData.mechanic_productivity ? chartData.mechanic_productivity.values : [],
            backgroundColor: window.GacCharts.pickColors(10)
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true } }
        }
      }, { emptyMessage: 'No mechanic productivity rows in selected range.' });

      charts.render('#jobs-chart-execution', {
        type: 'doughnut',
        data: {
          labels: chartData.execution_mix ? chartData.execution_mix.labels : [],
          datasets: [{
            data: chartData.execution_mix ? chartData.execution_mix.values : [],
            backgroundColor: [window.GacCharts.palette.green, window.GacCharts.palette.purple]
          }]
        },
        options: window.GacCharts.commonOptions()
      }, { emptyMessage: 'No execution-type data in selected range.' });
    }

    renderCharts();

    window.GacCharts.bindAjaxForm({
      form: form,
      target: content,
      mode: 'full',
      sourceSelector: '#jobs-report-content',
      afterUpdate: function () {
        renderCharts();
      }
    });
  });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>


