<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';

reports_require_access();

$page_title = 'Vehicle Reports';
$active_menu = 'reports.vehicles';

$scope = reports_build_scope_context(true);
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
$vehicleAttributeEnabled = (bool) $scope['vehicle_attribute_enabled'];
$reportBrandId = (int) $scope['report_brand_id'];
$reportModelId = (int) $scope['report_model_id'];
$reportVariantId = (int) $scope['report_variant_id'];
$reportModelYearId = (int) $scope['report_model_year_id'];
$reportColorId = (int) $scope['report_color_id'];
$reportVehicleFilters = $scope['report_vehicle_filters'];
$vehicleAttributesApiUrl = (string) $scope['vehicle_attributes_api_url'];

$jobCardColumns = table_columns('job_cards');
$jobOdometerEnabled = in_array('odometer_km', $jobCardColumns, true);

$vehicleSummaryParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$vehicleSummaryScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $vehicleSummaryParams, 'vehicle_summary_scope');
$vehicleSummaryFilterSql = vehicle_master_scope_sql('v', $reportVehicleFilters, $vehicleSummaryParams, 'vehicle_summary_filter');
$vehicleSummaryStmt = db()->prepare(
    'SELECT COUNT(DISTINCT jc.vehicle_id) AS serviced_vehicles,
            COUNT(*) AS closed_jobs
     FROM job_cards jc
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND v.company_id = :company_id
       AND v.status_code = "ACTIVE"
       ' . $vehicleSummaryFilterSql . '
       ' . $vehicleSummaryScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date'
);
$vehicleSummaryStmt->execute($vehicleSummaryParams);
$vehicleSummary = $vehicleSummaryStmt->fetch() ?: ['serviced_vehicles' => 0, 'closed_jobs' => 0];

$modelParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$modelScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $modelParams, 'model_scope');
$modelVehicleScopeSql = vehicle_master_scope_sql('v', $reportVehicleFilters, $modelParams, 'report_model_scope');
$modelStmt = db()->prepare(
    'SELECT v.brand, v.model, COUNT(DISTINCT jc.id) AS service_count
     FROM job_cards jc
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND v.company_id = :company_id
       AND v.status_code = "ACTIVE"
       ' . $modelVehicleScopeSql . '
       ' . $modelScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
     GROUP BY v.brand, v.model
     ORDER BY service_count DESC
     LIMIT 20'
);
$modelStmt->execute($modelParams);
$servicedModels = $modelStmt->fetchAll();

$frequencyParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$frequencyScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $frequencyParams, 'frequency_scope');
$frequencyVehicleScopeSql = vehicle_master_scope_sql('v', $reportVehicleFilters, $frequencyParams, 'report_frequency_scope');
$frequencyOdometerSelect = $jobOdometerEnabled
    ? ',
            MAX(CASE WHEN jc.odometer_km > 0 THEN jc.odometer_km END) AS latest_odometer_km,
            CASE
              WHEN SUM(CASE WHEN jc.odometer_km > 0 THEN 1 ELSE 0 END) > 1
              THEN ROUND(
                (MAX(CASE WHEN jc.odometer_km > 0 THEN jc.odometer_km END) - MIN(CASE WHEN jc.odometer_km > 0 THEN jc.odometer_km END))
                / (SUM(CASE WHEN jc.odometer_km > 0 THEN 1 ELSE 0 END) - 1),
                1
              )
              ELSE NULL
            END AS avg_km_between_services'
    : ',
            NULL AS latest_odometer_km,
            NULL AS avg_km_between_services';
$frequencyStmt = db()->prepare(
    'SELECT v.registration_no, v.brand, v.model,
            COUNT(jc.id) AS service_count,
            CASE WHEN COUNT(jc.id) > 1 THEN ROUND(DATEDIFF(MAX(DATE(jc.closed_at)), MIN(DATE(jc.closed_at))) / (COUNT(jc.id) - 1), 1) ELSE NULL END AS avg_days_between_services
            ' . $frequencyOdometerSelect . '
     FROM job_cards jc
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND v.company_id = :company_id
       AND v.status_code = "ACTIVE"
       ' . $frequencyVehicleScopeSql . '
       ' . $frequencyScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
     GROUP BY v.id, v.registration_no, v.brand, v.model
     ORDER BY service_count DESC
     LIMIT 20'
);
$frequencyStmt->execute($frequencyParams);
$serviceFrequencyRows = $frequencyStmt->fetchAll();

$vehicleRevenueParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$vehicleRevenueScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $vehicleRevenueParams, 'vehicle_revenue_scope');
$vehicleRevenueVehicleScopeSql = vehicle_master_scope_sql('v', $reportVehicleFilters, $vehicleRevenueParams, 'vehicle_revenue_filter');
$vehicleRevenueStmt = db()->prepare(
    'SELECT v.registration_no, v.brand, v.model,
            COUNT(i.id) AS finalized_invoice_count,
            COALESCE(SUM(i.grand_total), 0) AS revenue_total
     FROM invoices i
     INNER JOIN job_cards jc ON jc.id = i.job_card_id
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     WHERE i.company_id = :company_id
       AND i.invoice_status = "FINALIZED"
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND v.company_id = :company_id
       AND v.status_code = "ACTIVE"
       ' . $vehicleRevenueVehicleScopeSql . '
       ' . $vehicleRevenueScopeSql . '
       AND i.invoice_date BETWEEN :from_date AND :to_date
     GROUP BY v.id, v.registration_no, v.brand, v.model
     ORDER BY revenue_total DESC
     LIMIT 20'
);
$vehicleRevenueStmt->execute($vehicleRevenueParams);
$vehicleRevenueRows = $vehicleRevenueStmt->fetchAll();

$revenueSummaryParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$revenueSummaryScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $revenueSummaryParams, 'vehicle_rev_total_scope');
$revenueSummaryVehicleScopeSql = vehicle_master_scope_sql('v', $reportVehicleFilters, $revenueSummaryParams, 'vehicle_rev_total_filter');
$revenueSummaryStmt = db()->prepare(
    'SELECT COUNT(i.id) AS finalized_invoices,
            COALESCE(SUM(i.grand_total), 0) AS finalized_revenue
     FROM invoices i
     INNER JOIN job_cards jc ON jc.id = i.job_card_id
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     WHERE i.company_id = :company_id
       AND i.invoice_status = "FINALIZED"
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND v.company_id = :company_id
       AND v.status_code = "ACTIVE"
       ' . $revenueSummaryVehicleScopeSql . '
       ' . $revenueSummaryScopeSql . '
       AND i.invoice_date BETWEEN :from_date AND :to_date'
);
$revenueSummaryStmt->execute($revenueSummaryParams);
$revenueSummary = $revenueSummaryStmt->fetch() ?: ['finalized_invoices' => 0, 'finalized_revenue' => 0];

$servicedVehicles = (int) ($vehicleSummary['serviced_vehicles'] ?? 0);
$closedJobs = (int) ($vehicleSummary['closed_jobs'] ?? 0);
$finalizedInvoices = (int) ($revenueSummary['finalized_invoices'] ?? 0);
$finalizedRevenue = (float) ($revenueSummary['finalized_revenue'] ?? 0);

$exportKey = trim((string) ($_GET['export'] ?? ''));
if ($exportKey !== '') {
    if (!$canExportData) {
        http_response_code(403);
        exit('Export access denied.');
    }

    $timestamp = date('Ymd_His');
    switch ($exportKey) {
        case 'serviced_models':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['brand'] ?? ''),
                    (string) ($row['model'] ?? ''),
                    (int) ($row['service_count'] ?? 0),
                ],
                $servicedModels
            );
            reports_csv_download('vehicle_serviced_models_' . $timestamp . '.csv', ['Brand', 'Model', 'Closed Services'], $rows);

        case 'service_frequency':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['registration_no'] ?? ''),
                    (int) ($row['service_count'] ?? 0),
                    $row['avg_days_between_services'],
                    $row['latest_odometer_km'],
                    $row['avg_km_between_services'],
                ],
                $serviceFrequencyRows
            );
            reports_csv_download('vehicle_service_frequency_' . $timestamp . '.csv', ['Registration', 'Services', 'Avg Days Between', 'Latest Odometer KM', 'Avg KM Between'], $rows);

        case 'vehicle_revenue':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['registration_no'] ?? ''),
                    (string) ($row['brand'] ?? ''),
                    (string) ($row['model'] ?? ''),
                    (int) ($row['finalized_invoice_count'] ?? 0),
                    (float) ($row['revenue_total'] ?? 0),
                ],
                $vehicleRevenueRows
            );
            reports_csv_download('vehicle_revenue_' . $timestamp . '.csv', ['Registration', 'Brand', 'Model', 'Finalized Invoices', 'Revenue'], $rows);

        default:
            flash_set('report_error', 'Unknown export requested.', 'warning');
            redirect('modules/reports/vehicles.php?' . http_build_query(reports_compact_query_params($baseParams)));
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Vehicle Reports</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Vehicles</li>
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

            <?php if ($vehicleAttributeEnabled): ?>
              <div class="col-12" data-vehicle-attributes-root="1" data-vehicle-attributes-mode="filter" data-vehicle-attributes-endpoint="<?= e($vehicleAttributesApiUrl); ?>">
                <div class="row g-2">
                  <div class="col-md-4">
                    <label class="form-label">Brand / Model / Variant</label>
                    <select name="report_vehicle_combo_selector" data-vehicle-attr="combo" class="form-select">
                      <option value="">All Brand / Model / Variant</option>
                    </select>
                    <input type="hidden" name="report_brand_id" data-vehicle-attr-id="brand" value="<?= e((string) $reportBrandId); ?>" />
                    <input type="hidden" name="report_model_id" data-vehicle-attr-id="model" value="<?= e((string) $reportModelId); ?>" />
                    <input type="hidden" name="report_variant_id" data-vehicle-attr-id="variant" value="<?= e((string) $reportVariantId); ?>" />
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Year</label>
                    <select name="report_model_year_id" data-vehicle-attr="model_year" data-selected-id="<?= e((string) $reportModelYearId); ?>" class="form-select">
                      <option value="">All Years</option>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Color</label>
                    <select name="report_color_id" data-vehicle-attr="color" data-selected-id="<?= e((string) $reportColorId); ?>" class="form-select">
                      <option value="">All Colors</option>
                    </select>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <div class="col-md-2 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Apply</button>
              <a href="<?= e(url('modules/reports/vehicles.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
          <div class="mt-3">
            <span class="badge text-bg-light border me-2">Garage: <?= e($scopeGarageLabel); ?></span>
            <span class="badge text-bg-light border me-2">FY: <?= e($fyLabel); ?></span>
            <span class="badge text-bg-light border me-2">Range: <?= e($fromDate); ?> to <?= e($toDate); ?></span>
            <span class="badge text-bg-success">Trusted Data: Closed Jobs + Finalized Invoices</span>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-primary"><i class="bi bi-car-front"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Serviced Vehicles</span>
              <span class="info-box-number"><?= number_format($servicedVehicles); ?></span>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-info"><i class="bi bi-card-checklist"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Closed Jobs</span>
              <span class="info-box-number"><?= number_format($closedJobs); ?></span>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-success"><i class="bi bi-receipt"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Finalized Invoices</span>
              <span class="info-box-number"><?= number_format($finalizedInvoices); ?></span>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-warning"><i class="bi bi-currency-rupee"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Vehicle Revenue</span>
              <span class="info-box-number"><?= e(format_currency($finalizedRevenue)); ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Most Serviced Models</h3>
              <a href="<?= e(reports_export_url('modules/reports/vehicles.php', $baseParams, 'serviced_models')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Brand</th><th>Model</th><th>Closed Services</th></tr></thead>
                <tbody>
                  <?php if (empty($servicedModels)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No serviced model data.</td></tr>
                  <?php else: foreach ($servicedModels as $row): ?>
                    <tr><td><?= e((string) ($row['brand'] ?? '')); ?></td><td><?= e((string) ($row['model'] ?? '')); ?></td><td><?= (int) ($row['service_count'] ?? 0); ?></td></tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Vehicle Service Frequency</h3>
              <a href="<?= e(reports_export_url('modules/reports/vehicles.php', $baseParams, 'service_frequency')); ?>" class="btn btn-sm btn-outline-info">CSV</a>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Vehicle</th><th>Services</th><th>Avg Days</th><th>Latest Odometer</th><th>Avg KM</th></tr></thead>
                <tbody>
                  <?php if (empty($serviceFrequencyRows)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No service-frequency rows.</td></tr>
                  <?php else: foreach ($serviceFrequencyRows as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['registration_no'] ?? '')); ?><div class="text-muted small"><?= e((string) ($row['brand'] ?? '')); ?> <?= e((string) ($row['model'] ?? '')); ?></div></td>
                      <td><?= (int) ($row['service_count'] ?? 0); ?></td>
                      <td><?= $row['avg_days_between_services'] !== null ? e(number_format((float) $row['avg_days_between_services'], 1)) : '-'; ?></td>
                      <td><?= $row['latest_odometer_km'] !== null ? e(number_format((float) $row['latest_odometer_km'], 0)) . ' KM' : '-'; ?></td>
                      <td><?= $row['avg_km_between_services'] !== null ? e(number_format((float) $row['avg_km_between_services'], 1)) : '-'; ?></td>
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
          <h3 class="card-title mb-0">Top Vehicles by Finalized Revenue</h3>
          <a href="<?= e(reports_export_url('modules/reports/vehicles.php', $baseParams, 'vehicle_revenue')); ?>" class="btn btn-sm btn-outline-success">CSV</a>
        </div>
        <div class="card-body p-0 table-responsive">
          <table class="table table-sm table-striped mb-0">
            <thead><tr><th>Vehicle</th><th>Finalized Invoices</th><th>Revenue</th></tr></thead>
            <tbody>
              <?php if (empty($vehicleRevenueRows)): ?>
                <tr><td colspan="3" class="text-center text-muted py-4">No vehicle revenue rows.</td></tr>
              <?php else: foreach ($vehicleRevenueRows as $row): ?>
                <tr>
                  <td><?= e((string) ($row['registration_no'] ?? '')); ?><div class="text-muted small"><?= e((string) ($row['brand'] ?? '')); ?> <?= e((string) ($row['model'] ?? '')); ?></div></td>
                  <td><?= (int) ($row['finalized_invoice_count'] ?? 0); ?></td>
                  <td><?= e(format_currency((float) ($row['revenue_total'] ?? 0))); ?></td>
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

