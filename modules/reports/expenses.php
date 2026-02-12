<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';

reports_require_access();
if (!(has_permission('expense.view') || has_permission('expense.manage'))) {
    flash_set('access_denied', 'You do not have permission to view expense reports.', 'danger');
    redirect('modules/reports/index.php');
}
if (table_columns('expenses') === [] || table_columns('expense_categories') === []) {
    flash_set('report_error', 'Expense report tables are missing. Run database/payroll_expense_upgrade.sql and database/financial_control_upgrade.sql.', 'danger');
    redirect('modules/reports/index.php');
}

$page_title = 'Expense Reports';
$active_menu = 'reports.expenses';

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

$pageParams = $baseParams;

$dailyParams = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
$dailyScopeSql = analytics_garage_scope_sql('e.garage_id', $selectedGarageId, $garageIds, $dailyParams, 'exp_daily_scope');
$dailyStmt = db()->prepare(
    'SELECT e.expense_date,
            COALESCE(SUM(CASE WHEN e.entry_type = "EXPENSE" THEN e.amount ELSE 0 END), 0) AS expense_total,
            COALESCE(SUM(CASE WHEN e.entry_type = "REVERSAL" THEN ABS(e.amount) ELSE 0 END), 0) AS reversal_total,
            COALESCE(SUM(e.amount), 0) AS net_total
     FROM expenses e
     WHERE e.company_id = :company_id
       AND e.entry_type <> "DELETED"
       AND e.expense_date BETWEEN :from_date AND :to_date
       ' . $dailyScopeSql . '
     GROUP BY e.expense_date
     ORDER BY e.expense_date ASC'
);
$dailyStmt->execute($dailyParams);
$dailyRows = $dailyStmt->fetchAll();

$monthlyParams = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
$monthlyScopeSql = analytics_garage_scope_sql('e.garage_id', $selectedGarageId, $garageIds, $monthlyParams, 'exp_month_scope');
$monthlyStmt = db()->prepare(
    'SELECT DATE_FORMAT(e.expense_date, "%Y-%m") AS expense_month,
            COALESCE(SUM(CASE WHEN e.entry_type = "EXPENSE" THEN e.amount ELSE 0 END), 0) AS expense_total,
            COALESCE(SUM(CASE WHEN e.entry_type = "REVERSAL" THEN ABS(e.amount) ELSE 0 END), 0) AS reversal_total,
            COALESCE(SUM(e.amount), 0) AS net_total
     FROM expenses e
     WHERE e.company_id = :company_id
       AND e.entry_type <> "DELETED"
       AND e.expense_date BETWEEN :from_date AND :to_date
       ' . $monthlyScopeSql . '
     GROUP BY DATE_FORMAT(e.expense_date, "%Y-%m")
     ORDER BY expense_month ASC'
);
$monthlyStmt->execute($monthlyParams);
$monthlyRows = $monthlyStmt->fetchAll();

$categoryParams = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
$categoryScopeSql = analytics_garage_scope_sql('e.garage_id', $selectedGarageId, $garageIds, $categoryParams, 'exp_cat_scope');
$categoryStmt = db()->prepare(
    'SELECT COALESCE(ec.category_name, "Uncategorized") AS category_name,
            COALESCE(SUM(CASE WHEN e.entry_type = "EXPENSE" THEN e.amount ELSE 0 END), 0) AS expense_total,
            COALESCE(SUM(CASE WHEN e.entry_type = "REVERSAL" THEN ABS(e.amount) ELSE 0 END), 0) AS reversal_total,
            COALESCE(SUM(e.amount), 0) AS net_total
     FROM expenses e
     LEFT JOIN expense_categories ec ON ec.id = e.category_id
     WHERE e.company_id = :company_id
       AND e.entry_type <> "DELETED"
       AND e.expense_date BETWEEN :from_date AND :to_date
       ' . $categoryScopeSql . '
     GROUP BY category_name
     ORDER BY net_total DESC'
);
$categoryStmt->execute($categoryParams);
$categoryRows = $categoryStmt->fetchAll();

$garageParams = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
$garageScopeSql = analytics_garage_scope_sql('e.garage_id', $selectedGarageId, $garageIds, $garageParams, 'exp_gar_scope');
$garageStmt = db()->prepare(
    'SELECT e.garage_id,
            COALESCE(g.name, CONCAT("Garage #", e.garage_id)) AS garage_name,
            COALESCE(SUM(CASE WHEN e.entry_type = "EXPENSE" THEN e.amount ELSE 0 END), 0) AS expense_total,
            COALESCE(SUM(CASE WHEN e.entry_type = "REVERSAL" THEN ABS(e.amount) ELSE 0 END), 0) AS reversal_total,
            COALESCE(SUM(e.amount), 0) AS net_total
     FROM expenses e
     LEFT JOIN garages g ON g.id = e.garage_id
     WHERE e.company_id = :company_id
       AND e.entry_type <> "DELETED"
       AND e.expense_date BETWEEN :from_date AND :to_date
       ' . $garageScopeSql . '
     GROUP BY e.garage_id, garage_name
     ORDER BY garage_name ASC'
);
$garageStmt->execute($garageParams);
$garageRows = $garageStmt->fetchAll();

$expenseTotalsParams = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
$expenseTotalsScopeSql = analytics_garage_scope_sql('e.garage_id', $selectedGarageId, $garageIds, $expenseTotalsParams, 'exp_tot_scope');
$expenseTotalsStmt = db()->prepare(
    'SELECT COALESCE(SUM(CASE WHEN e.entry_type = "EXPENSE" THEN e.amount ELSE 0 END), 0) AS expense_total,
            COALESCE(SUM(CASE WHEN e.entry_type = "REVERSAL" THEN ABS(e.amount) ELSE 0 END), 0) AS reversal_total,
            COALESCE(SUM(e.amount), 0) AS net_expense
     FROM expenses e
     WHERE e.company_id = :company_id
       AND e.entry_type <> "DELETED"
       AND e.expense_date BETWEEN :from_date AND :to_date
       ' . $expenseTotalsScopeSql
);
$expenseTotalsStmt->execute($expenseTotalsParams);
$expenseTotals = $expenseTotalsStmt->fetch() ?: [
    'expense_total' => 0,
    'reversal_total' => 0,
    'net_expense' => 0,
];

$revenueTotal = 0.0;
$finalizedInvoiceCount = 0;
if (table_columns('invoices') !== []) {
    $revenueParams = [
        'company_id' => $companyId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ];
    $revenueScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $revenueParams, 'exp_rev_scope');
    $revenueStmt = db()->prepare(
        'SELECT COUNT(*) AS finalized_invoice_count,
                COALESCE(SUM(i.grand_total), 0) AS revenue_total
         FROM invoices i
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND i.invoice_date BETWEEN :from_date AND :to_date
           ' . $revenueScopeSql
    );
    $revenueStmt->execute($revenueParams);
    $revenueRow = $revenueStmt->fetch() ?: ['finalized_invoice_count' => 0, 'revenue_total' => 0];
    $finalizedInvoiceCount = (int) ($revenueRow['finalized_invoice_count'] ?? 0);
    $revenueTotal = round((float) ($revenueRow['revenue_total'] ?? 0), 2);
}

$grossExpense = round((float) ($expenseTotals['expense_total'] ?? 0), 2);
$reversalTotal = round((float) ($expenseTotals['reversal_total'] ?? 0), 2);
$netExpense = round((float) ($expenseTotals['net_expense'] ?? 0), 2);
$netProfit = round($revenueTotal - $netExpense, 2);

$exportKey = trim((string) ($_GET['export'] ?? ''));
if ($exportKey !== '') {
    if (!$canExportData) {
        http_response_code(403);
        exit('Export access denied.');
    }

    $timestamp = date('Ymd_His');
    switch ($exportKey) {
        case 'daily_summary':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['expense_date'] ?? ''),
                    (float) ($row['expense_total'] ?? 0),
                    (float) ($row['reversal_total'] ?? 0),
                    (float) ($row['net_total'] ?? 0),
                ],
                $dailyRows
            );
            reports_csv_download(
                'expense_daily_summary_' . $timestamp . '.csv',
                ['Date', 'Expense Total', 'Reversal Total', 'Net Expense'],
                $rows
            );

        case 'monthly_summary':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['expense_month'] ?? ''),
                    (float) ($row['expense_total'] ?? 0),
                    (float) ($row['reversal_total'] ?? 0),
                    (float) ($row['net_total'] ?? 0),
                ],
                $monthlyRows
            );
            reports_csv_download(
                'expense_monthly_summary_' . $timestamp . '.csv',
                ['Month', 'Expense Total', 'Reversal Total', 'Net Expense'],
                $rows
            );

        case 'category_summary':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['category_name'] ?? ''),
                    (float) ($row['expense_total'] ?? 0),
                    (float) ($row['reversal_total'] ?? 0),
                    (float) ($row['net_total'] ?? 0),
                ],
                $categoryRows
            );
            reports_csv_download(
                'expense_category_summary_' . $timestamp . '.csv',
                ['Category', 'Expense Total', 'Reversal Total', 'Net Expense'],
                $rows
            );

        case 'garage_summary':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['garage_name'] ?? ''),
                    (float) ($row['expense_total'] ?? 0),
                    (float) ($row['reversal_total'] ?? 0),
                    (float) ($row['net_total'] ?? 0),
                ],
                $garageRows
            );
            reports_csv_download(
                'expense_garage_summary_' . $timestamp . '.csv',
                ['Garage', 'Expense Total', 'Reversal Total', 'Net Expense'],
                $rows
            );

        case 'revenue_expense':
            $rows = [[
                $fromDate . ' to ' . $toDate,
                $finalizedInvoiceCount,
                $revenueTotal,
                $grossExpense,
                $reversalTotal,
                $netExpense,
                $netProfit,
            ]];
            reports_csv_download(
                'expense_revenue_vs_expense_' . $timestamp . '.csv',
                ['Range', 'Finalized Invoices', 'Finalized Revenue', 'Expense Total', 'Reversal Total', 'Net Expense', 'Net Profit'],
                $rows
            );

        default:
            flash_set('report_error', 'Unknown export requested.', 'warning');
            redirect('modules/reports/expenses.php?' . http_build_query(reports_compact_query_params($pageParams)));
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Expense Reports</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Expenses</li>
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
              <a href="<?= e(reports_page_url((string) $link['path'], $pageParams)); ?>" class="btn btn-sm <?= $isActive ? 'btn-primary' : 'btn-outline-primary'; ?>">
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
              <a href="<?= e(url('modules/reports/expenses.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
          <div class="mt-3">
            <span class="badge text-bg-light border me-2">Garage: <?= e($scopeGarageLabel); ?></span>
            <span class="badge text-bg-light border me-2">FY: <?= e($fyLabel); ?></span>
            <span class="badge text-bg-light border me-2">Range: <?= e($fromDate); ?> to <?= e($toDate); ?></span>
            <span class="badge text-bg-success">Trusted Data: Expense Ledger + Finalized Invoice Revenue</span>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-danger"><i class="bi bi-cash-stack"></i></span><div class="info-box-content"><span class="info-box-text">Gross Expense</span><span class="info-box-number"><?= e(format_currency($grossExpense)); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-secondary"><i class="bi bi-arrow-counterclockwise"></i></span><div class="info-box-content"><span class="info-box-text">Reversal Total</span><span class="info-box-number"><?= e(format_currency($reversalTotal)); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-warning"><i class="bi bi-wallet"></i></span><div class="info-box-content"><span class="info-box-text">Net Expense</span><span class="info-box-number"><?= e(format_currency($netExpense)); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-graph-up-arrow"></i></span><div class="info-box-content"><span class="info-box-text">Finalized Revenue</span><span class="info-box-number"><?= e(format_currency($revenueTotal)); ?></span></div></div></div>
      </div>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Revenue vs Expense Comparison</h3>
          <a href="<?= e(reports_export_url('modules/reports/expenses.php', $pageParams, 'revenue_expense')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-3"><strong>Finalized Invoices:</strong> <?= number_format($finalizedInvoiceCount); ?></div>
            <div class="col-md-3"><strong>Finalized Revenue:</strong> <?= e(format_currency($revenueTotal)); ?></div>
            <div class="col-md-3"><strong>Net Expense:</strong> <?= e(format_currency($netExpense)); ?></div>
            <div class="col-md-3"><strong>Net Profit:</strong> <?= e(format_currency($netProfit)); ?></div>
          </div>
          <?php if (table_columns('invoices') === []): ?>
            <p class="text-muted mt-2 mb-0">Revenue metrics are unavailable because the invoices table is missing.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Daily Expense Summary</h3>
              <a href="<?= e(reports_export_url('modules/reports/expenses.php', $pageParams, 'daily_summary')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Date</th><th>Expense</th><th>Reversal</th><th>Net</th></tr></thead>
                <tbody>
                  <?php if (empty($dailyRows)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No expense entries found in selected range.</td></tr>
                  <?php else: foreach ($dailyRows as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['expense_date'] ?? '')); ?></td>
                      <td><?= e(format_currency((float) ($row['expense_total'] ?? 0))); ?></td>
                      <td><?= e(format_currency((float) ($row['reversal_total'] ?? 0))); ?></td>
                      <td><?= e(format_currency((float) ($row['net_total'] ?? 0))); ?></td>
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
              <h3 class="card-title mb-0">Monthly Expense Report</h3>
              <a href="<?= e(reports_export_url('modules/reports/expenses.php', $pageParams, 'monthly_summary')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Month</th><th>Expense</th><th>Reversal</th><th>Net</th></tr></thead>
                <tbody>
                  <?php if (empty($monthlyRows)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No monthly expense values in selected range.</td></tr>
                  <?php else: foreach ($monthlyRows as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['expense_month'] ?? '')); ?></td>
                      <td><?= e(format_currency((float) ($row['expense_total'] ?? 0))); ?></td>
                      <td><?= e(format_currency((float) ($row['reversal_total'] ?? 0))); ?></td>
                      <td><?= e(format_currency((float) ($row['net_total'] ?? 0))); ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Category-wise Expense</h3>
              <a href="<?= e(reports_export_url('modules/reports/expenses.php', $pageParams, 'category_summary')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Category</th><th>Expense</th><th>Reversal</th><th>Net</th></tr></thead>
                <tbody>
                  <?php if (empty($categoryRows)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No category totals for selected range.</td></tr>
                  <?php else: foreach ($categoryRows as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['category_name'] ?? '')); ?></td>
                      <td><?= e(format_currency((float) ($row['expense_total'] ?? 0))); ?></td>
                      <td><?= e(format_currency((float) ($row['reversal_total'] ?? 0))); ?></td>
                      <td><?= e(format_currency((float) ($row['net_total'] ?? 0))); ?></td>
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
              <h3 class="card-title mb-0">Garage-wise Expense</h3>
              <a href="<?= e(reports_export_url('modules/reports/expenses.php', $pageParams, 'garage_summary')); ?>" class="btn btn-sm btn-outline-danger">CSV</a>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Garage</th><th>Expense</th><th>Reversal</th><th>Net</th></tr></thead>
                <tbody>
                  <?php if (empty($garageRows)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No garage totals for selected range.</td></tr>
                  <?php else: foreach ($garageRows as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['garage_name'] ?? '')); ?></td>
                      <td><?= e(format_currency((float) ($row['expense_total'] ?? 0))); ?></td>
                      <td><?= e(format_currency((float) ($row['reversal_total'] ?? 0))); ?></td>
                      <td><?= e(format_currency((float) ($row['net_total'] ?? 0))); ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
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
