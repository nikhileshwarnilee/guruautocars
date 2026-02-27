<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';

reports_require_access();

$page_title = 'Customer Reports';
$active_menu = 'reports.customers';

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

$customerSummaryParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$customerSummaryScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $customerSummaryParams, 'cust_summary_scope');
$customerSummaryStmt = db()->prepare(
    'SELECT COUNT(DISTINCT jc.customer_id) AS served_customers,
            COUNT(*) AS closed_jobs
     FROM job_cards jc
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       ' . $customerSummaryScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date'
);
$customerSummaryStmt->execute($customerSummaryParams);
$customerSummary = $customerSummaryStmt->fetch() ?: ['served_customers' => 0, 'closed_jobs' => 0];

$revenueSummaryParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$revenueSummaryScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $revenueSummaryParams, 'cust_rev_scope');
$revenueSummaryStmt = db()->prepare(
    'SELECT COALESCE(SUM(i.grand_total), 0) AS finalized_revenue
     FROM invoices i
     INNER JOIN job_cards jc ON jc.id = i.job_card_id
     WHERE i.company_id = :company_id
       AND i.invoice_status = "FINALIZED"
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       ' . $revenueSummaryScopeSql . '
       AND i.invoice_date BETWEEN :from_date AND :to_date'
);
$revenueSummaryStmt->execute($revenueSummaryParams);
$revenueSummary = $revenueSummaryStmt->fetch() ?: ['finalized_revenue' => 0];

$repeatCustomerParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$repeatCustomerScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $repeatCustomerParams, 'repeat_cust_scope');
$repeatCustomerStmt = db()->prepare(
    'SELECT c.full_name, c.phone,
            COUNT(DISTINCT jc.id) AS service_count,
            MAX(jc.closed_at) AS last_service_at
     FROM job_cards jc
     INNER JOIN customers c ON c.id = jc.customer_id
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND c.company_id = :company_id
       AND c.status_code = "ACTIVE"
       ' . $repeatCustomerScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
     GROUP BY c.id, c.full_name, c.phone
     HAVING service_count >= 2
     ORDER BY service_count DESC, last_service_at DESC
     LIMIT 20'
);
$repeatCustomerStmt->execute($repeatCustomerParams);
$repeatCustomers = $repeatCustomerStmt->fetchAll();

$topCustomerParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$topCustomerScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $topCustomerParams, 'top_cust_scope');
$topCustomerStmt = db()->prepare(
    'SELECT c.full_name, c.phone,
            COUNT(i.id) AS finalized_invoice_count,
            COALESCE(SUM(i.grand_total), 0) AS revenue_total
     FROM invoices i
     INNER JOIN job_cards jc ON jc.id = i.job_card_id
     INNER JOIN customers c ON c.id = i.customer_id
     WHERE i.company_id = :company_id
       AND i.invoice_status = "FINALIZED"
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND c.company_id = :company_id
       AND c.status_code = "ACTIVE"
       ' . $topCustomerScopeSql . '
       AND i.invoice_date BETWEEN :from_date AND :to_date
     GROUP BY c.id, c.full_name, c.phone
     ORDER BY revenue_total DESC
     LIMIT 20'
);
$topCustomerStmt->execute($topCustomerParams);
$topCustomers = $topCustomerStmt->fetchAll();

$customerRevenueTrendParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$customerRevenueTrendScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $customerRevenueTrendParams, 'customer_rev_trend_scope');
$customerRevenueTrendStmt = db()->prepare(
    'SELECT DATE_FORMAT(i.invoice_date, "%Y-%m") AS revenue_month,
            COALESCE(SUM(i.grand_total), 0) AS revenue_total
     FROM invoices i
     INNER JOIN job_cards jc ON jc.id = i.job_card_id
     WHERE i.company_id = :company_id
       AND i.invoice_status = "FINALIZED"
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       ' . $customerRevenueTrendScopeSql . '
       AND i.invoice_date BETWEEN :from_date AND :to_date
     GROUP BY DATE_FORMAT(i.invoice_date, "%Y-%m")
     ORDER BY revenue_month ASC'
);
$customerRevenueTrendStmt->execute($customerRevenueTrendParams);
$customerRevenueTrendRows = $customerRevenueTrendStmt->fetchAll();

$customerValueParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$customerValueScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $customerValueParams, 'cust_value_scope');
$customerValueStmt = db()->prepare(
    'SELECT c.full_name, c.phone,
            COUNT(DISTINCT jc.id) AS closed_jobs,
            COUNT(DISTINCT i.id) AS finalized_invoices,
            COALESCE(SUM(i.grand_total), 0) AS finalized_revenue,
            MAX(jc.closed_at) AS last_service_at
     FROM customers c
     LEFT JOIN job_cards jc ON jc.customer_id = c.id
       AND jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       ' . $customerValueScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
     LEFT JOIN invoices i ON i.job_card_id = jc.id
       AND i.company_id = :company_id
       AND i.invoice_status = "FINALIZED"
     WHERE c.company_id = :company_id
       AND c.status_code = "ACTIVE"
     GROUP BY c.id, c.full_name, c.phone
     HAVING closed_jobs > 0
     ORDER BY finalized_revenue DESC, closed_jobs DESC
     LIMIT 25'
);
$customerValueStmt->execute($customerValueParams);
$customerValueRows = $customerValueStmt->fetchAll();

$servedCustomers = (int) ($customerSummary['served_customers'] ?? 0);
$closedJobs = (int) ($customerSummary['closed_jobs'] ?? 0);
$avgJobsPerCustomer = $servedCustomers > 0 ? round($closedJobs / $servedCustomers, 2) : 0.0;
$repeatCustomerCount = count($repeatCustomers);
$finalizedRevenue = (float) ($revenueSummary['finalized_revenue'] ?? 0);
$newCustomerCount = max(0, $servedCustomers - $repeatCustomerCount);

$topCustomerChartRows = array_slice($topCustomers, 0, 10);
$chartPayload = [
    'repeat_vs_new' => [
        'labels' => ['Repeat Customers', 'New Customers'],
        'values' => [$repeatCustomerCount, $newCustomerCount],
    ],
    'top_customers' => [
        'labels' => array_map(static fn (array $row): string => (string) ($row['full_name'] ?? ''), $topCustomerChartRows),
        'values' => array_map(static fn (array $row): float => (float) ($row['revenue_total'] ?? 0), $topCustomerChartRows),
    ],
    'revenue_trend' => [
        'labels' => array_map(static fn (array $row): string => (string) ($row['revenue_month'] ?? ''), $customerRevenueTrendRows),
        'values' => array_map(static fn (array $row): float => (float) ($row['revenue_total'] ?? 0), $customerRevenueTrendRows),
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
        case 'repeat_customers':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['full_name'] ?? ''),
                    (string) ($row['phone'] ?? ''),
                    (int) ($row['service_count'] ?? 0),
                    (string) ($row['last_service_at'] ?? ''),
                ],
                $repeatCustomers
            );
            reports_csv_download('customers_repeat_' . $timestamp . '.csv', ['Customer', 'Phone', 'Labour', 'Last Labour'], $rows);

        case 'top_customers':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['full_name'] ?? ''),
                    (string) ($row['phone'] ?? ''),
                    (int) ($row['finalized_invoice_count'] ?? 0),
                    (float) ($row['revenue_total'] ?? 0),
                ],
                $topCustomers
            );
            reports_csv_download('customers_top_revenue_' . $timestamp . '.csv', ['Customer', 'Phone', 'Finalized Invoices', 'Revenue'], $rows);

        case 'customer_value':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['full_name'] ?? ''),
                    (string) ($row['phone'] ?? ''),
                    (int) ($row['closed_jobs'] ?? 0),
                    (int) ($row['finalized_invoices'] ?? 0),
                    (float) ($row['finalized_revenue'] ?? 0),
                    (string) ($row['last_service_at'] ?? ''),
                ],
                $customerValueRows
            );
            reports_csv_download('customers_value_' . $timestamp . '.csv', ['Customer', 'Phone', 'Closed Jobs', 'Finalized Invoices', 'Revenue', 'Last Labour'], $rows);

        default:
            flash_set('report_error', 'Unknown export requested.', 'warning');
            redirect('modules/reports/customers.php?' . http_build_query(reports_compact_query_params($baseParams)));
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Customer Reports</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Customers</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php reports_render_page_navigation($active_menu, $baseParams); ?>

      <div class="card card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Filters</h3></div>
        <div class="card-body">
          <form
            method="get"
            id="customers-report-filter-form"
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
              <a href="<?= e(url('modules/reports/customers.php')); ?>" class="btn btn-outline-secondary">Reset</a>
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

      <div id="customers-report-content">
        <script type="application/json" data-chart-payload><?= $chartPayloadJson ?: '{}'; ?></script>

      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-primary"><i class="bi bi-people"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Served Customers</span>
              <span class="info-box-number"><?= number_format($servedCustomers); ?></span>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-success"><i class="bi bi-person-check"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Repeat Customers</span>
              <span class="info-box-number"><?= number_format($repeatCustomerCount); ?></span>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-info"><i class="bi bi-arrow-repeat"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Avg Jobs / Customer</span>
              <span class="info-box-number"><?= e(number_format($avgJobsPerCustomer, 2)); ?></span>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-warning"><i class="bi bi-currency-rupee"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Finalized Revenue</span>
              <span class="info-box-number"><?= e(format_currency($finalizedRevenue)); ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-4">
          <div class="card h-100">
            <div class="card-header"><h3 class="card-title mb-0">Repeat vs New Customers</h3></div>
            <div class="card-body">
              <div class="gac-chart-wrap"><canvas id="customers-chart-repeat-new"></canvas></div>
            </div>
          </div>
        </div>
        <div class="col-lg-8">
          <div class="card h-100">
            <div class="card-header"><h3 class="card-title mb-0">Customer Revenue Trend</h3></div>
            <div class="card-body">
              <div class="gac-chart-wrap"><canvas id="customers-chart-revenue-trend"></canvas></div>
            </div>
          </div>
        </div>
        <div class="col-12">
          <div class="card h-100">
            <div class="card-header"><h3 class="card-title mb-0">Top Customers by Revenue</h3></div>
            <div class="card-body">
              <div class="gac-chart-wrap"><canvas id="customers-chart-top-revenue"></canvas></div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Repeat Customers</h3>
              <a href="<?= e(reports_export_url('modules/reports/customers.php', $baseParams, 'repeat_customers')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Customer</th><th>Phone</th><th>Labour</th><th>Last Labour</th></tr></thead>
                <tbody>
                  <?php if (empty($repeatCustomers)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No repeat customers in selected range.</td></tr>
                  <?php else: foreach ($repeatCustomers as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['full_name'] ?? '')); ?></td>
                      <td><?= e((string) ($row['phone'] ?? '')); ?></td>
                      <td><?= (int) ($row['service_count'] ?? 0); ?></td>
                      <td><?= e((string) ($row['last_service_at'] ?? '')); ?></td>
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
              <h3 class="card-title mb-0">Top Customers by Revenue</h3>
              <a href="<?= e(reports_export_url('modules/reports/customers.php', $baseParams, 'top_customers')); ?>" class="btn btn-sm btn-outline-success">CSV</a>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Customer</th><th>Finalized Invoices</th><th>Revenue</th></tr></thead>
                <tbody>
                  <?php if (empty($topCustomers)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No finalized invoice data.</td></tr>
                  <?php else: foreach ($topCustomers as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['full_name'] ?? '')); ?></td>
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

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Customer Value Matrix</h3>
          <a href="<?= e(reports_export_url('modules/reports/customers.php', $baseParams, 'customer_value')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a>
        </div>
        <div class="card-body p-0 table-responsive">
          <table class="table table-sm table-striped mb-0">
            <thead><tr><th>Customer</th><th>Closed Jobs</th><th>Finalized Invoices</th><th>Revenue</th><th>Last Labour</th></tr></thead>
            <tbody>
              <?php if (empty($customerValueRows)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No customer value data for selected range.</td></tr>
              <?php else: foreach ($customerValueRows as $row): ?>
                <tr>
                  <td><?= e((string) ($row['full_name'] ?? '')); ?><div class="text-muted small"><?= e((string) ($row['phone'] ?? '')); ?></div></td>
                  <td><?= (int) ($row['closed_jobs'] ?? 0); ?></td>
                  <td><?= (int) ($row['finalized_invoices'] ?? 0); ?></td>
                  <td><?= e(format_currency((float) ($row['finalized_revenue'] ?? 0))); ?></td>
                  <td><?= e((string) ($row['last_service_at'] ?? '')); ?></td>
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

    var form = document.getElementById('customers-report-filter-form');
    var content = document.getElementById('customers-report-content');
    if (!form || !content) {
      return;
    }

    var charts = window.GacCharts.createRegistry('customers-report');

    function renderCharts() {
      var payload = window.GacCharts.parsePayload(content);
      var chartData = payload || {};

      charts.render('#customers-chart-repeat-new', {
        type: 'pie',
        data: {
          labels: chartData.repeat_vs_new ? chartData.repeat_vs_new.labels : [],
          datasets: [{
            data: chartData.repeat_vs_new ? chartData.repeat_vs_new.values : [],
            backgroundColor: [window.GacCharts.palette.green, window.GacCharts.palette.blue]
          }]
        },
        options: window.GacCharts.commonOptions()
      }, { emptyMessage: 'No customer segmentation data for selected range.' });

      charts.render('#customers-chart-revenue-trend', {
        type: 'line',
        data: {
          labels: chartData.revenue_trend ? chartData.revenue_trend.labels : [],
          datasets: [{
            label: 'Finalized Revenue',
            data: chartData.revenue_trend ? chartData.revenue_trend.values : [],
            borderColor: window.GacCharts.palette.indigo,
            backgroundColor: window.GacCharts.palette.indigo + '33',
            fill: true,
            tension: 0.3
          }]
        },
        options: window.GacCharts.commonOptions()
      }, { emptyMessage: 'No monthly customer revenue data for selected range.' });

      charts.render('#customers-chart-top-revenue', {
        type: 'bar',
        data: {
          labels: chartData.top_customers ? chartData.top_customers.labels : [],
          datasets: [{
            label: 'Revenue',
            data: chartData.top_customers ? chartData.top_customers.values : [],
            backgroundColor: window.GacCharts.pickColors(10)
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true } }
        }
      }, { emptyMessage: 'No top-customer revenue rows for selected range.' });
    }

    renderCharts();

    window.GacCharts.bindAjaxForm({
      form: form,
      target: content,
      mode: 'full',
      sourceSelector: '#customers-report-content',
      afterUpdate: function () {
        renderCharts();
      }
    });
  });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>


