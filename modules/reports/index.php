<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';

reports_require_access();

$page_title = 'Reports Overview';
$active_menu = 'reports';

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
$fromDateTime = (string) $scope['from_datetime'];
$toDateTime = (string) $scope['to_datetime'];
$canViewFinancial = (bool) $scope['can_view_financial'];
$baseParams = $scope['base_params'];

$closedJobsParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$closedJobsScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $closedJobsParams, 'ov_job_scope');
$closedJobsStmt = db()->prepare(
    'SELECT COUNT(*) AS closed_jobs
     FROM job_cards jc
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       ' . $closedJobsScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date'
);
$closedJobsStmt->execute($closedJobsParams);
$closedJobs = (int) (($closedJobsStmt->fetch() ?: ['closed_jobs' => 0])['closed_jobs'] ?? 0);

$customersParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$customersScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $customersParams, 'ov_customer_scope');
$customersStmt = db()->prepare(
    'SELECT COUNT(DISTINCT jc.customer_id) AS served_customers,
            COUNT(DISTINCT jc.vehicle_id) AS serviced_vehicles
     FROM job_cards jc
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       ' . $customersScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date'
);
$customersStmt->execute($customersParams);
$customerVehicleSummary = $customersStmt->fetch() ?: ['served_customers' => 0, 'serviced_vehicles' => 0];
$servedCustomers = (int) ($customerVehicleSummary['served_customers'] ?? 0);
$servicedVehicles = (int) ($customerVehicleSummary['serviced_vehicles'] ?? 0);

$movementParams = ['company_id' => $companyId, 'from_dt' => $fromDateTime, 'to_dt' => $toDateTime];
$movementScopeSql = analytics_garage_scope_sql('im.garage_id', $selectedGarageId, $garageIds, $movementParams, 'ov_mv_scope');
$movementStmt = db()->prepare(
    'SELECT COUNT(*) AS movement_entries
     FROM inventory_movements im
     LEFT JOIN job_cards jc_ref ON jc_ref.id = im.reference_id AND im.reference_type = "JOB_CARD"
     LEFT JOIN inventory_transfers tr ON tr.id = im.reference_id AND im.reference_type = "TRANSFER"
     WHERE im.company_id = :company_id
       ' . $movementScopeSql . '
       AND im.created_at BETWEEN :from_dt AND :to_dt
       AND (im.reference_type <> "JOB_CARD" OR (jc_ref.id IS NOT NULL AND jc_ref.company_id = :company_id AND jc_ref.status = "CLOSED" AND jc_ref.status_code = "ACTIVE"))
       AND (im.reference_type <> "TRANSFER" OR (tr.id IS NOT NULL AND tr.company_id = :company_id AND tr.status_code = "POSTED"))'
);
$movementStmt->execute($movementParams);
$movementEntries = (int) (($movementStmt->fetch() ?: ['movement_entries' => 0])['movement_entries'] ?? 0);

$finalizedInvoices = 0;
$finalizedRevenue = 0.0;
if ($canViewFinancial) {
    $invoiceParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $invoiceScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $invoiceParams, 'ov_inv_scope');
    $invoiceStmt = db()->prepare(
        'SELECT COUNT(*) AS finalized_invoices,
                COALESCE(SUM(i.grand_total), 0) AS finalized_revenue
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $invoiceScopeSql . '
           AND i.invoice_date BETWEEN :from_date AND :to_date'
    );
    $invoiceStmt->execute($invoiceParams);
    $invoiceSummary = $invoiceStmt->fetch() ?: ['finalized_invoices' => 0, 'finalized_revenue' => 0];
    $finalizedInvoices = (int) ($invoiceSummary['finalized_invoices'] ?? 0);
    $finalizedRevenue = (float) ($invoiceSummary['finalized_revenue'] ?? 0);
}

$payrollMonth = substr($toDate, 0, 7);
$payrollPayable = 0.0;
if ((has_permission('payroll.view') || has_permission('payroll.manage'))
    && table_columns('payroll_salary_sheets') !== []
    && preg_match('/^\d{4}-\d{2}$/', $payrollMonth)) {
    $payrollParams = ['company_id' => $companyId, 'salary_month' => $payrollMonth];
    $payrollScopeSql = analytics_garage_scope_sql('pss.garage_id', $selectedGarageId, $garageIds, $payrollParams, 'ov_payroll_scope');
    $payrollStmt = db()->prepare(
        'SELECT COALESCE(SUM(pss.total_payable), 0) AS total_payable
         FROM payroll_salary_sheets pss
         WHERE pss.company_id = :company_id
           AND pss.salary_month = :salary_month
           ' . $payrollScopeSql
    );
    $payrollStmt->execute($payrollParams);
    $payrollPayable = (float) ($payrollStmt->fetchColumn() ?? 0);
}

$expenseNet = 0.0;
if ((has_permission('expense.view') || has_permission('expense.manage')) && table_columns('expenses') !== []) {
    $expenseParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $expenseScopeSql = analytics_garage_scope_sql('e.garage_id', $selectedGarageId, $garageIds, $expenseParams, 'ov_exp_scope');
    $expenseStmt = db()->prepare(
        'SELECT COALESCE(SUM(e.amount), 0) AS net_expense
         FROM expenses e
         WHERE e.company_id = :company_id
           AND e.entry_type <> "DELETED"
           AND e.expense_date BETWEEN :from_date AND :to_date
           ' . $expenseScopeSql
    );
    $expenseStmt->execute($expenseParams);
    $expenseNet = (float) ($expenseStmt->fetchColumn() ?? 0);
}

$moduleCards = [
    [
        'title' => 'Job Reports',
        'description' => 'Closed-job productivity, mechanic performance, and service mix.',
        'path' => 'modules/reports/jobs.php',
        'icon' => 'bi bi-card-checklist',
        'badge' => 'Closed Jobs',
        'metric' => number_format($closedJobs),
    ],
    [
        'title' => 'Inventory Reports',
        'description' => 'Stock valuation and valid movement analytics with usage trends.',
        'path' => 'modules/reports/inventory.php',
        'icon' => 'bi bi-box-seam',
        'badge' => 'Valid Movements',
        'metric' => number_format($movementEntries),
    ],
    [
        'title' => 'Billing & GST Reports',
        'description' => 'Finalized-invoice revenue, GST summaries, and receivables.',
        'path' => 'modules/reports/billing_gst.php',
        'icon' => 'bi bi-receipt',
        'badge' => 'Finalized Invoices',
        'metric' => $canViewFinancial ? number_format($finalizedInvoices) : 'Restricted',
    ],
    [
        'title' => 'Customer Reports',
        'description' => 'Repeat behavior and customer value from closed jobs and finalized invoices.',
        'path' => 'modules/reports/customers.php',
        'icon' => 'bi bi-people',
        'badge' => 'Served Customers',
        'metric' => number_format($servedCustomers),
    ],
    [
        'title' => 'Vehicle Reports',
        'description' => 'Model-level demand, service frequency, and vehicle revenue.',
        'path' => 'modules/reports/vehicles.php',
        'icon' => 'bi bi-car-front',
        'badge' => 'Serviced Vehicles',
        'metric' => number_format($servicedVehicles),
    ],
];

if (has_permission('outsourced.view') && table_columns('outsourced_works') !== []) {
    $outsourcedSummaryParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $outsourcedScopeSql = analytics_garage_scope_sql('ow.garage_id', $selectedGarageId, $garageIds, $outsourcedSummaryParams, 'ov_out_scope');
    $outsourcedSummaryStmt = db()->prepare(
        'SELECT COUNT(*) AS work_count,
                COALESCE(SUM(ow.agreed_cost), 0) AS agreed_total
         FROM outsourced_works ow
         WHERE ow.company_id = :company_id
           AND ow.status_code = "ACTIVE"
           ' . $outsourcedScopeSql . '
           AND DATE(ow.sent_at) BETWEEN :from_date AND :to_date'
    );
    $outsourcedSummaryStmt->execute($outsourcedSummaryParams);
    $outsourcedSummary = $outsourcedSummaryStmt->fetch() ?: ['work_count' => 0, 'agreed_total' => 0];

    $moduleCards[] = [
        'title' => 'Outsourced Labour',
        'description' => 'Vendor-wise outsourced cost, paid amount, and outstanding with status drilldown.',
        'path' => 'modules/reports/outsourced_labour.php',
        'icon' => 'bi bi-gear-wide-connected',
        'badge' => 'Outsourced Works',
        'metric' => number_format((int) ($outsourcedSummary['work_count'] ?? 0)),
    ];
}

if (has_permission('payroll.view') || has_permission('payroll.manage')) {
    $moduleCards[] = [
        'title' => 'Payroll Reports',
        'description' => 'Monthly salary sheets, payment history, advances, loans, and mechanic earnings.',
        'path' => 'modules/reports/payroll.php',
        'icon' => 'bi bi-wallet2',
        'badge' => 'Month Payable (' . $payrollMonth . ')',
        'metric' => format_currency($payrollPayable),
    ];
}

if (has_permission('expense.view') || has_permission('expense.manage')) {
    $moduleCards[] = [
        'title' => 'Expense Reports',
        'description' => 'Daily/monthly/category/garage expenses with finalized revenue vs expense profit view.',
        'path' => 'modules/reports/expenses.php',
        'icon' => 'bi bi-cash-stack',
        'badge' => 'Net Expense',
        'metric' => format_currency($expenseNet),
    ];
}

  if (has_permission('gst.reports') || has_permission('financial.reports')) {
    $moduleCards[] = [
      'title' => 'GST Compliance Reports',
      'description' => 'CA-ready sales and purchase GST registers with monthly exports.',
      'path' => 'modules/reports/gst_compliance.php',
      'icon' => 'bi bi-journal-text',
      'badge' => 'GST Compliance',
      'metric' => 'Ready',
    ];
  }

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Reports Overview</h3></div>
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
      <div class="card card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Filters</h3></div>
        <div class="card-body">
          <form
            method="get"
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
              <a href="<?= e(url('modules/reports/index.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
          <div class="mt-3">
            <span class="badge text-bg-light border me-2">Garage: <?= e($scopeGarageLabel); ?></span>
            <span class="badge text-bg-light border me-2">FY: <?= e($fyLabel); ?></span>
            <span class="badge text-bg-light border me-2">Range: <?= e($fromDate); ?> to <?= e($toDate); ?></span>
            <span class="badge text-bg-success">Trusted Data: Closed Jobs + Finalized Invoices + Valid Movements</span>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-check2-circle"></i></span><div class="info-box-content"><span class="info-box-text">Closed Jobs</span><span class="info-box-number"><?= number_format($closedJobs); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-info"><i class="bi bi-person-vcard"></i></span><div class="info-box-content"><span class="info-box-text">Served Customers</span><span class="info-box-number"><?= number_format($servedCustomers); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-primary"><i class="bi bi-car-front"></i></span><div class="info-box-content"><span class="info-box-text">Serviced Vehicles</span><span class="info-box-number"><?= number_format($servicedVehicles); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-warning"><i class="bi bi-arrow-left-right"></i></span><div class="info-box-content"><span class="info-box-text">Valid Movements</span><span class="info-box-number"><?= number_format($movementEntries); ?></span></div></div></div>
      </div>

      <?php if ($canViewFinancial): ?>
        <div class="row g-3 mb-3">
          <div class="col-md-6"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-receipt"></i></span><div class="info-box-content"><span class="info-box-text">Finalized Invoices</span><span class="info-box-number"><?= number_format($finalizedInvoices); ?></span></div></div></div>
          <div class="col-md-6"><div class="info-box"><span class="info-box-icon text-bg-secondary"><i class="bi bi-currency-rupee"></i></span><div class="info-box-content"><span class="info-box-text">Finalized Revenue</span><span class="info-box-number"><?= e(format_currency($finalizedRevenue)); ?></span></div></div></div>
        </div>
      <?php endif; ?>

      <div class="row g-3">
        <?php foreach ($moduleCards as $card): ?>
          <div class="col-lg-4 col-md-6">
            <div class="card h-100 card-outline card-primary">
              <div class="card-body">
                <h5 class="mb-2"><i class="<?= e((string) $card['icon']); ?> me-2"></i><?= e((string) $card['title']); ?></h5>
                <p class="text-muted mb-3"><?= e((string) $card['description']); ?></p>
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <span class="badge text-bg-light border"><?= e((string) $card['badge']); ?></span>
                  <strong><?= e((string) $card['metric']); ?></strong>
                </div>
                <a href="<?= e(reports_page_url((string) $card['path'], $baseParams)); ?>" class="btn btn-primary btn-sm">Open Report</a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
