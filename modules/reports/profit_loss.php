<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';

reports_require_access();
if (!(has_permission('reports.financial') || has_permission('financial.reports') || has_permission('gst.reports') || has_permission('expense.view'))) {
    flash_set('access_denied', 'You do not have permission to view Profit & Loss report.', 'danger');
    redirect('modules/reports/index.php');
}

$page_title = 'Profit & Loss Report';
$active_menu = 'reports.profit_loss';

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

$pageParams = $baseParams;

$incomeParams = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
$incomeScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $incomeParams, 'pl_income_scope');
$incomeStmt = db()->prepare(
    'SELECT COUNT(*) AS invoice_count,
            COALESCE(SUM(i.grand_total), 0) AS invoice_income
     FROM invoices i
     INNER JOIN job_cards jc ON jc.id = i.job_card_id
     WHERE i.company_id = :company_id
       AND i.invoice_status = "FINALIZED"
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       ' . $incomeScopeSql . '
       AND i.invoice_date BETWEEN :from_date AND :to_date'
);
$incomeStmt->execute($incomeParams);
$incomeRow = $incomeStmt->fetch() ?: ['invoice_count' => 0, 'invoice_income' => 0];
$invoiceIncome = (float) ($incomeRow['invoice_income'] ?? 0);
$invoiceCount = (int) ($incomeRow['invoice_count'] ?? 0);

$otherIncome = 0.0;
if (table_columns('expenses') !== []) {
    $otherIncomeParams = [
        'company_id' => $companyId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ];
    $otherIncomeScopeSql = analytics_garage_scope_sql('e.garage_id', $selectedGarageId, $garageIds, $otherIncomeParams, 'pl_other_income_scope');
    $otherIncomeStmt = db()->prepare(
        'SELECT COALESCE(SUM(ABS(e.amount)), 0)
         FROM expenses e
         WHERE e.company_id = :company_id
           AND e.entry_type <> "DELETED"
           AND e.amount < 0
           ' . $otherIncomeScopeSql . '
           AND e.expense_date BETWEEN :from_date AND :to_date'
    );
    $otherIncomeStmt->execute($otherIncomeParams);
    $otherIncome = (float) ($otherIncomeStmt->fetchColumn() ?? 0);
}

$avgCostMap = [];
if (table_columns('purchases') !== [] && table_columns('purchase_items') !== []) {
    $costParams = [
        'company_id' => $companyId,
        'to_date' => $toDate,
    ];
    $costScopeSql = analytics_garage_scope_sql('pu.garage_id', $selectedGarageId, $garageIds, $costParams, 'pl_cost_scope');
    $costStmt = db()->prepare(
        'SELECT pi.part_id,
                COALESCE(SUM(pi.quantity), 0) AS qty,
                COALESCE(SUM(pi.quantity * pi.unit_cost), 0) AS value
         FROM purchase_items pi
         INNER JOIN purchases pu ON pu.id = pi.purchase_id
         WHERE pu.company_id = :company_id
           AND pu.purchase_status = "FINALIZED"
           AND pu.purchase_date <= :to_date
           ' . $costScopeSql . '
         GROUP BY pi.part_id'
    );
    $costStmt->execute($costParams);
    foreach ($costStmt->fetchAll() as $row) {
        $partId = (int) ($row['part_id'] ?? 0);
        $qty = (float) ($row['qty'] ?? 0);
        $value = (float) ($row['value'] ?? 0);
        if ($partId > 0 && $qty > 0.0001) {
            $avgCostMap[$partId] = $value / $qty;
        }
    }
}

$partsCost = 0.0;
$partsUsageRows = [];
$partsUsageParams = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
$partsUsageScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $partsUsageParams, 'pl_parts_usage_scope');
$partsUsageStmt = db()->prepare(
    'SELECT jp.part_id,
            p.part_name,
            COALESCE(SUM(jp.quantity), 0) AS total_qty
     FROM job_parts jp
     INNER JOIN job_cards jc ON jc.id = jp.job_card_id
     INNER JOIN invoices i ON i.job_card_id = jc.id
     LEFT JOIN parts p ON p.id = jp.part_id
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND i.company_id = :company_id
       AND i.invoice_status = "FINALIZED"
       AND i.invoice_date BETWEEN :from_date AND :to_date
       ' . $partsUsageScopeSql . '
     GROUP BY jp.part_id, p.part_name'
);
$partsUsageStmt->execute($partsUsageParams);
$partsUsageRows = $partsUsageStmt->fetchAll();
foreach ($partsUsageRows as $row) {
    $partId = (int) ($row['part_id'] ?? 0);
    $qty = (float) ($row['total_qty'] ?? 0);
    if ($qty <= 0 || $partId <= 0) {
        continue;
    }
    $unitCost = (float) ($avgCostMap[$partId] ?? 0);
    $partsCost += ($qty * $unitCost);
}
$partsCost = round($partsCost, 2);

$outsourcedCost = 0.0;
if (table_columns('job_labor') !== []) {
    $outsourceParams = [
        'company_id' => $companyId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ];
    $outsourceScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $outsourceParams, 'pl_outsource_scope');
    $outsourceStmt = db()->prepare(
        'SELECT COALESCE(SUM(jl.outsource_cost), 0)
         FROM job_labor jl
         INNER JOIN job_cards jc ON jc.id = jl.job_card_id
         INNER JOIN invoices i ON i.job_card_id = jc.id
         WHERE jc.company_id = :company_id
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           AND i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jl.execution_type = "OUTSOURCED"
           AND i.invoice_date BETWEEN :from_date AND :to_date
           ' . $outsourceScopeSql
    );
    $outsourceStmt->execute($outsourceParams);
    $outsourcedCost = (float) ($outsourceStmt->fetchColumn() ?? 0);
}

$payrollExpense = 0.0;
if (table_columns('payroll_salary_sheets') !== []) {
    $monthFrom = substr($fromDate, 0, 7);
    $monthTo = substr($toDate, 0, 7);
    $payrollParams = [
        'company_id' => $companyId,
        'month_from' => $monthFrom,
        'month_to' => $monthTo,
    ];
    $payrollScopeSql = analytics_garage_scope_sql('pss.garage_id', $selectedGarageId, $garageIds, $payrollParams, 'pl_payroll_scope');
    $payrollStmt = db()->prepare(
        'SELECT COALESCE(SUM(pss.total_payable), 0)
         FROM payroll_salary_sheets pss
         WHERE pss.company_id = :company_id
           AND pss.salary_month BETWEEN :month_from AND :month_to
           ' . $payrollScopeSql
    );
    $payrollStmt->execute($payrollParams);
    $payrollExpense = (float) ($payrollStmt->fetchColumn() ?? 0);
}

$otherExpenses = 0.0;
if (table_columns('expenses') !== []) {
    $expenseParams = [
        'company_id' => $companyId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ];
    $expenseScopeSql = analytics_garage_scope_sql('e.garage_id', $selectedGarageId, $garageIds, $expenseParams, 'pl_expense_scope');
    $expenseStmt = db()->prepare(
        'SELECT COALESCE(SUM(e.amount), 0)
         FROM expenses e
         WHERE e.company_id = :company_id
           AND e.entry_type <> "DELETED"
           AND e.amount > 0
           AND (e.source_type IS NULL OR (
             e.source_type NOT LIKE "PAYROLL%"
             AND e.source_type NOT LIKE "PURCHASE%"
             AND e.source_type NOT LIKE "OUTSOURCED%"
           ))
           ' . $expenseScopeSql . '
           AND e.expense_date BETWEEN :from_date AND :to_date'
    );
    $expenseStmt->execute($expenseParams);
    $otherExpenses = (float) ($expenseStmt->fetchColumn() ?? 0);
}

$totalIncome = round($invoiceIncome + $otherIncome, 2);
$directCost = round($partsCost + $outsourcedCost, 2);
$grossProfit = round($totalIncome - $directCost, 2);
$totalExpenses = round($payrollExpense + $otherExpenses, 2);
$netProfit = round($grossProfit - $totalExpenses, 2);

$exportKey = trim((string) ($_GET['export'] ?? ''));
if ($exportKey !== '') {
    if (!$canExportData) {
        http_response_code(403);
        exit('Export access denied.');
    }
    if ($exportKey === 'profit_loss') {
        $timestamp = date('Ymd_His');
        $rows = [
            ['Income - Finalized Invoices', $invoiceIncome],
            ['Income - Other Income', $otherIncome],
            ['Direct Cost - Parts Cost', $partsCost],
            ['Direct Cost - Outsourced Cost', $outsourcedCost],
            ['Expenses - Payroll', $payrollExpense],
            ['Expenses - Other', $otherExpenses],
            ['Gross Profit', $grossProfit],
            ['Net Profit', $netProfit],
        ];
        reports_csv_download('profit_loss_' . $timestamp . '.csv', ['Component', 'Amount'], $rows);
    }
    flash_set('report_error', 'Unknown export requested.', 'warning');
    redirect('modules/reports/profit_loss.php?' . http_build_query(reports_compact_query_params($pageParams)));
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Profit &amp; Loss Report</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Profit &amp; Loss</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-outline card-primary mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div class="btn-group flex-wrap" role="group" aria-label="Report Pages">
            <?php foreach (reports_module_links() as $link): ?>
              <?php $isActive = $active_menu === (string) $link['menu_key']; ?>
              <a href="<?= e(reports_page_url((string) $link['path'], $baseParams)); ?>" class="btn btn-sm <?= $isActive ? 'btn-primary' : 'btn-outline-primary'; ?>">
                <i class="<?= e((string) $link['icon']); ?> me-1"></i><?= e((string) $link['label']); ?>
              </a>
            <?php endforeach; ?>
          </div>
          <a href="<?= e(reports_export_url('modules/reports/profit_loss.php', $pageParams, 'profit_loss')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
        </div>
      </div>

      <div class="card card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Filters</h3></div>
        <div class="card-body">
          <form method="get" class="row g-2 align-items-end" data-date-filter-form="1" data-date-range-start="<?= e((string) $scope['date_range_start']); ?>" data-date-range-end="<?= e((string) $scope['date_range_end']); ?>" data-date-yearly-start="<?= e((string) $scope['fy_start']); ?>">
            <?php if ($allowAllGarages || count($garageIds) > 1): ?>
              <div class="col-md-3"><label class="form-label">Garage Scope</label><select name="garage_id" class="form-select" data-searchable-select="1"><?php if ($allowAllGarages): ?><option value="0" <?= $selectedGarageId === 0 ? 'selected' : ''; ?>>All Accessible Garages</option><?php endif; ?><?php foreach ($garageOptions as $garage): ?><option value="<?= (int) $garage['id']; ?>" <?= ((int) $garage['id'] === $selectedGarageId) ? 'selected' : ''; ?>><?= e((string) $garage['name']); ?> (<?= e((string) $garage['code']); ?>)</option><?php endforeach; ?></select></div>
            <?php else: ?>
              <div class="col-md-3"><label class="form-label">Garage Scope</label><input type="text" class="form-control" value="<?= e($scopeGarageLabel); ?>" readonly><input type="hidden" name="garage_id" value="<?= (int) $selectedGarageId; ?>"></div>
            <?php endif; ?>
            <div class="col-md-2"><label class="form-label">Financial Year</label><select name="fy_id" class="form-select"><?php foreach ($financialYears as $fy): ?><option value="<?= (int) ($fy['id'] ?? 0); ?>" <?= ((int) ($fy['id'] ?? 0) === $selectedFyId) ? 'selected' : ''; ?>><?= e((string) ($fy['fy_label'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Date Mode</label><select name="date_mode" class="form-select"><?php foreach ($dateModeOptions as $modeValue => $modeLabel): ?><option value="<?= e((string) $modeValue); ?>" <?= $dateMode === $modeValue ? 'selected' : ''; ?>><?= e((string) $modeLabel); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" required></div>
            <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" required></div>
            <div class="col-md-2 d-flex gap-2"><button type="submit" class="btn btn-primary">Apply</button><a href="<?= e(url('modules/reports/profit_loss.php')); ?>" class="btn btn-outline-secondary">Reset</a></div>
          </form>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="small-box text-bg-primary"><div class="inner"><h4><?= e(format_currency($totalIncome)); ?></h4><p>Total Income</p></div><span class="small-box-icon"><i class="bi bi-graph-up"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-warning"><div class="inner"><h4><?= e(format_currency($directCost)); ?></h4><p>Direct Cost</p></div><span class="small-box-icon"><i class="bi bi-box-seam"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-success"><div class="inner"><h4><?= e(format_currency($grossProfit)); ?></h4><p>Gross Profit</p></div><span class="small-box-icon"><i class="bi bi-calculator"></i></span></div></div>
        <div class="col-md-3"><div class="small-box <?= $netProfit >= 0 ? 'text-bg-success' : 'text-bg-danger'; ?>"><div class="inner"><h4><?= e(format_currency($netProfit)); ?></h4><p>Net Profit</p></div><span class="small-box-icon"><i class="bi bi-currency-rupee"></i></span></div></div>
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title mb-0">P&amp;L Components</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead><tr><th>Section</th><th>Component</th><th>Amount</th></tr></thead>
            <tbody>
              <tr><td>Income</td><td>Finalized Invoices (<?= number_format($invoiceCount); ?>)</td><td><?= e(format_currency($invoiceIncome)); ?></td></tr>
              <tr><td>Income</td><td>Other Income</td><td><?= e(format_currency($otherIncome)); ?></td></tr>
              <tr><td>Direct Cost</td><td>Parts Cost (stock issue cost)</td><td><?= e(format_currency($partsCost)); ?></td></tr>
              <tr><td>Direct Cost</td><td>Outsourced Work Cost</td><td><?= e(format_currency($outsourcedCost)); ?></td></tr>
              <tr><td>Expenses</td><td>Payroll</td><td><?= e(format_currency($payrollExpense)); ?></td></tr>
              <tr><td>Expenses</td><td>Other Expenses</td><td><?= e(format_currency($otherExpenses)); ?></td></tr>
              <tr class="table-info"><td><strong>Profit</strong></td><td><strong>Gross Profit</strong></td><td><strong><?= e(format_currency($grossProfit)); ?></strong></td></tr>
              <tr class="<?= $netProfit >= 0 ? 'table-success' : 'table-danger'; ?>"><td><strong>Profit</strong></td><td><strong>Net Profit</strong></td><td><strong><?= e(format_currency($netProfit)); ?></strong></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
