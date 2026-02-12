<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';

reports_require_access();
if (!(has_permission('payroll.view') || has_permission('payroll.manage'))) {
    flash_set('access_denied', 'You do not have permission to view payroll reports.', 'danger');
    redirect('modules/reports/index.php');
}
if (table_columns('payroll_salary_sheets') === [] || table_columns('payroll_salary_items') === []) {
    flash_set('report_error', 'Payroll report tables are missing. Run database/payroll_expense_upgrade.sql and database/financial_control_upgrade.sql.', 'danger');
    redirect('modules/reports/index.php');
}

$page_title = 'Payroll Reports';
$active_menu = 'reports.payroll';

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

$selectedMonth = trim((string) ($_GET['salary_month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

$monthStartDate = $selectedMonth . '-01';
$monthEndDate = date('Y-m-t', strtotime($monthStartDate));
$pageParams = array_merge($baseParams, ['salary_month' => $selectedMonth]);

$sheetSummaryParams = [
    'company_id' => $companyId,
    'salary_month' => $selectedMonth,
];
$sheetSummaryScopeSql = analytics_garage_scope_sql('pss.garage_id', $selectedGarageId, $garageIds, $sheetSummaryParams, 'payroll_sheet_scope');
$sheetSummaryStmt = db()->prepare(
    'SELECT COUNT(*) AS sheet_count,
            COALESCE(SUM(pss.total_gross), 0) AS total_gross,
            COALESCE(SUM(pss.total_deductions), 0) AS total_deductions,
            COALESCE(SUM(pss.total_payable), 0) AS total_payable,
            COALESCE(SUM(pss.total_paid), 0) AS total_paid
     FROM payroll_salary_sheets pss
     WHERE pss.company_id = :company_id
       AND pss.salary_month = :salary_month
       ' . $sheetSummaryScopeSql
);
$sheetSummaryStmt->execute($sheetSummaryParams);
$sheetSummary = $sheetSummaryStmt->fetch() ?: [
    'sheet_count' => 0,
    'total_gross' => 0,
    'total_deductions' => 0,
    'total_payable' => 0,
    'total_paid' => 0,
];

$monthlyRowsParams = [
    'company_id' => $companyId,
    'salary_month' => $selectedMonth,
];
$monthlyRowsScopeSql = analytics_garage_scope_sql('pss.garage_id', $selectedGarageId, $garageIds, $monthlyRowsParams, 'payroll_monthly_scope');
$monthlyRowsStmt = db()->prepare(
    'SELECT pss.salary_month,
            pss.garage_id,
            g.name AS garage_name,
            u.name AS staff_name,
            psi.salary_type,
            psi.base_amount,
            psi.commission_amount,
            psi.overtime_amount,
            (psi.advance_deduction + psi.loan_deduction + psi.manual_deduction) AS deduction_total,
            psi.net_payable,
            psi.paid_amount,
            psi.status
     FROM payroll_salary_items psi
     INNER JOIN payroll_salary_sheets pss ON pss.id = psi.sheet_id
     INNER JOIN users u ON u.id = psi.user_id
     LEFT JOIN garages g ON g.id = pss.garage_id
     WHERE pss.company_id = :company_id
       AND pss.salary_month = :salary_month
       ' . $monthlyRowsScopeSql . '
     ORDER BY g.name ASC, u.name ASC'
);
$monthlyRowsStmt->execute($monthlyRowsParams);
$monthlySalaryRows = $monthlyRowsStmt->fetchAll();

$paymentHistoryParams = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
$paymentHistoryScopeSql = analytics_garage_scope_sql('psp.garage_id', $selectedGarageId, $garageIds, $paymentHistoryParams, 'payroll_pay_scope');
$paymentHistoryStmt = db()->prepare(
    'SELECT psp.payment_date,
            psp.entry_type,
            psp.amount,
            psp.payment_mode,
            psp.reference_no,
            psp.notes,
            pss.salary_month,
            u.name AS staff_name,
            g.name AS garage_name
     FROM payroll_salary_payments psp
     INNER JOIN payroll_salary_sheets pss ON pss.id = psp.sheet_id
     INNER JOIN users u ON u.id = psp.user_id
     LEFT JOIN garages g ON g.id = psp.garage_id
     WHERE psp.company_id = :company_id
       AND psp.payment_date BETWEEN :from_date AND :to_date
       ' . $paymentHistoryScopeSql . '
     ORDER BY psp.payment_date DESC, psp.id DESC
     LIMIT 400'
);
$paymentHistoryStmt->execute($paymentHistoryParams);
$staffPaymentHistory = $paymentHistoryStmt->fetchAll();

$advanceLedgerParams = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
$advanceLedgerScopeSql = analytics_garage_scope_sql('pa.garage_id', $selectedGarageId, $garageIds, $advanceLedgerParams, 'payroll_adv_scope');
$advanceLedgerStmt = db()->prepare(
    'SELECT pa.advance_date,
            pa.amount,
            pa.applied_amount,
            (pa.amount - pa.applied_amount) AS pending_amount,
            pa.status,
            pa.notes,
            u.name AS staff_name,
            g.name AS garage_name
     FROM payroll_advances pa
     INNER JOIN users u ON u.id = pa.user_id
     LEFT JOIN garages g ON g.id = pa.garage_id
     WHERE pa.company_id = :company_id
       AND pa.advance_date BETWEEN :from_date AND :to_date
       AND pa.status <> "DELETED"
       ' . $advanceLedgerScopeSql . '
     ORDER BY pa.advance_date DESC, pa.id DESC
     LIMIT 400'
);
$advanceLedgerStmt->execute($advanceLedgerParams);
$advanceLedgerRows = $advanceLedgerStmt->fetchAll();

$loanOutstandingParams = [
    'company_id' => $companyId,
    'to_date' => $toDate,
];
$loanOutstandingScopeSql = analytics_garage_scope_sql('pl.garage_id', $selectedGarageId, $garageIds, $loanOutstandingParams, 'payroll_loan_scope');
$loanOutstandingStmt = db()->prepare(
    'SELECT pl.loan_date,
            pl.total_amount,
            pl.paid_amount,
            (pl.total_amount - pl.paid_amount) AS pending_amount,
            pl.emi_amount,
            pl.status,
            pl.notes,
            u.name AS staff_name,
            g.name AS garage_name
     FROM payroll_loans pl
     INNER JOIN users u ON u.id = pl.user_id
     LEFT JOIN garages g ON g.id = pl.garage_id
     WHERE pl.company_id = :company_id
       AND pl.loan_date <= :to_date
       AND pl.status <> "DELETED"
       ' . $loanOutstandingScopeSql . '
     ORDER BY pending_amount DESC, pl.loan_date DESC
     LIMIT 400'
);
$loanOutstandingStmt->execute($loanOutstandingParams);
$loanOutstandingRows = $loanOutstandingStmt->fetchAll();

$mechanicRowsParams = [
    'company_id' => $companyId,
    'salary_month' => $selectedMonth,
    'month_start' => $monthStartDate,
    'month_end' => $monthEndDate,
];
$mechanicRowsScopeSql = analytics_garage_scope_sql('pss.garage_id', $selectedGarageId, $garageIds, $mechanicRowsParams, 'payroll_mech_scope');
$mechanicRowsStmt = db()->prepare(
    'SELECT u.name AS staff_name,
            g.name AS garage_name,
            COALESCE(SUM(psi.gross_amount), 0) AS gross_earnings,
            COALESCE(SUM(psi.net_payable), 0) AS net_earnings,
            COALESCE(SUM(psi.paid_amount), 0) AS paid_earnings,
            COALESCE(MAX(job_stats.closed_jobs), 0) AS closed_jobs
     FROM payroll_salary_items psi
     INNER JOIN payroll_salary_sheets pss ON pss.id = psi.sheet_id
     INNER JOIN users u ON u.id = psi.user_id
     LEFT JOIN garages g ON g.id = pss.garage_id
     LEFT JOIN (
        SELECT ja.user_id, jc.garage_id, COUNT(DISTINCT jc.id) AS closed_jobs
        FROM job_assignments ja
        INNER JOIN job_cards jc ON jc.id = ja.job_card_id
        WHERE jc.company_id = :company_id
          AND jc.status = "CLOSED"
          AND jc.status_code = "ACTIVE"
          AND DATE(jc.closed_at) BETWEEN :month_start AND :month_end
        GROUP BY ja.user_id, jc.garage_id
     ) job_stats ON job_stats.user_id = psi.user_id AND job_stats.garage_id = pss.garage_id
     WHERE pss.company_id = :company_id
       AND pss.salary_month = :salary_month
       AND psi.salary_type = "PER_JOB"
       ' . $mechanicRowsScopeSql . '
     GROUP BY psi.user_id, u.name, g.name
     ORDER BY paid_earnings DESC'
);
$mechanicRowsStmt->execute($mechanicRowsParams);
$mechanicEarningsRows = $mechanicRowsStmt->fetchAll();

$advanceOutstandingTotal = 0.0;
foreach ($advanceLedgerRows as $row) {
    $advanceOutstandingTotal += (float) ($row['pending_amount'] ?? 0);
}
$advanceOutstandingTotal = round($advanceOutstandingTotal, 2);

$loanOutstandingTotal = 0.0;
foreach ($loanOutstandingRows as $row) {
    $loanOutstandingTotal += (float) ($row['pending_amount'] ?? 0);
}
$loanOutstandingTotal = round($loanOutstandingTotal, 2);

$totalPayable = round((float) ($sheetSummary['total_payable'] ?? 0), 2);
$totalPaid = round((float) ($sheetSummary['total_paid'] ?? 0), 2);
$totalOutstanding = max(0.0, round($totalPayable - $totalPaid, 2));
$paidSalaryRowCount = 0;
$partialSalaryRowCount = 0;
$pendingSalaryRowCount = 0;
foreach ($monthlySalaryRows as $salaryRow) {
    $rowStatus = strtoupper((string) ($salaryRow['status'] ?? 'PENDING'));
    if ($rowStatus === 'PAID') {
        $paidSalaryRowCount++;
    } elseif ($rowStatus === 'PARTIAL') {
        $partialSalaryRowCount++;
    } else {
        $pendingSalaryRowCount++;
    }
}
$unpaidSalaryRowCount = $partialSalaryRowCount + $pendingSalaryRowCount;

$exportKey = trim((string) ($_GET['export'] ?? ''));
if ($exportKey !== '') {
    if (!$canExportData) {
        http_response_code(403);
        exit('Export access denied.');
    }

    $timestamp = date('Ymd_His');
    switch ($exportKey) {
        case 'monthly_salary':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['salary_month'] ?? ''),
                    (string) ($row['garage_name'] ?? ''),
                    (string) ($row['staff_name'] ?? ''),
                    (string) ($row['salary_type'] ?? ''),
                    (float) ($row['base_amount'] ?? 0),
                    (float) ($row['commission_amount'] ?? 0),
                    (float) ($row['overtime_amount'] ?? 0),
                    (float) ($row['deduction_total'] ?? 0),
                    (float) ($row['net_payable'] ?? 0),
                    (float) ($row['paid_amount'] ?? 0),
                    (string) ($row['status'] ?? ''),
                ],
                $monthlySalaryRows
            );
            reports_csv_download(
                'payroll_monthly_salary_' . $timestamp . '.csv',
                ['Month', 'Garage', 'Staff', 'Salary Type', 'Base', 'Commission', 'Overtime', 'Deductions', 'Net Payable', 'Paid', 'Status'],
                $rows
            );

        case 'staff_payment_history':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['payment_date'] ?? ''),
                    (string) ($row['salary_month'] ?? ''),
                    (string) ($row['garage_name'] ?? ''),
                    (string) ($row['staff_name'] ?? ''),
                    (string) ($row['entry_type'] ?? ''),
                    (float) ($row['amount'] ?? 0),
                    (string) ($row['payment_mode'] ?? ''),
                    (string) ($row['reference_no'] ?? ''),
                    (string) ($row['notes'] ?? ''),
                ],
                $staffPaymentHistory
            );
            reports_csv_download(
                'payroll_staff_payments_' . $timestamp . '.csv',
                ['Payment Date', 'Salary Month', 'Garage', 'Staff', 'Entry Type', 'Amount', 'Mode', 'Reference', 'Notes'],
                $rows
            );

        case 'advance_ledger':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['advance_date'] ?? ''),
                    (string) ($row['garage_name'] ?? ''),
                    (string) ($row['staff_name'] ?? ''),
                    (float) ($row['amount'] ?? 0),
                    (float) ($row['applied_amount'] ?? 0),
                    (float) ($row['pending_amount'] ?? 0),
                    (string) ($row['status'] ?? ''),
                    (string) ($row['notes'] ?? ''),
                ],
                $advanceLedgerRows
            );
            reports_csv_download(
                'payroll_advance_ledger_' . $timestamp . '.csv',
                ['Advance Date', 'Garage', 'Staff', 'Advance Amount', 'Applied', 'Pending', 'Status', 'Notes'],
                $rows
            );

        case 'loan_outstanding':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['loan_date'] ?? ''),
                    (string) ($row['garage_name'] ?? ''),
                    (string) ($row['staff_name'] ?? ''),
                    (float) ($row['total_amount'] ?? 0),
                    (float) ($row['paid_amount'] ?? 0),
                    (float) ($row['pending_amount'] ?? 0),
                    (float) ($row['emi_amount'] ?? 0),
                    (string) ($row['status'] ?? ''),
                    (string) ($row['notes'] ?? ''),
                ],
                $loanOutstandingRows
            );
            reports_csv_download(
                'payroll_loan_outstanding_' . $timestamp . '.csv',
                ['Loan Date', 'Garage', 'Staff', 'Loan Total', 'Paid', 'Pending', 'EMI', 'Status', 'Notes'],
                $rows
            );

        case 'mechanic_earnings':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['garage_name'] ?? ''),
                    (string) ($row['staff_name'] ?? ''),
                    (int) ($row['closed_jobs'] ?? 0),
                    (float) ($row['gross_earnings'] ?? 0),
                    (float) ($row['net_earnings'] ?? 0),
                    (float) ($row['paid_earnings'] ?? 0),
                ],
                $mechanicEarningsRows
            );
            reports_csv_download(
                'payroll_mechanic_earnings_' . $timestamp . '.csv',
                ['Garage', 'Mechanic', 'Closed Jobs', 'Gross Earnings', 'Net Earnings', 'Paid Earnings'],
                $rows
            );

        default:
            flash_set('report_error', 'Unknown export requested.', 'warning');
            redirect('modules/reports/payroll.php?' . http_build_query(reports_compact_query_params($pageParams)));
    }
}

$renderReportBody = static function (
    array $sheetSummary,
    array $monthlySalaryRows,
    array $staffPaymentHistory,
    array $advanceLedgerRows,
    array $loanOutstandingRows,
    array $mechanicEarningsRows,
    array $pageParams,
    bool $canExportData,
    float $totalPayable,
    float $totalPaid,
    float $totalOutstanding,
    float $advanceOutstandingTotal,
    float $loanOutstandingTotal,
    int $paidSalaryRowCount,
    int $partialSalaryRowCount,
    int $pendingSalaryRowCount,
    int $unpaidSalaryRowCount
): void {
    ?>
      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-primary"><i class="bi bi-wallet2"></i></span><div class="info-box-content"><span class="info-box-text">Monthly Payable</span><span class="info-box-number"><?= e(format_currency($totalPayable)); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-check2-circle"></i></span><div class="info-box-content"><span class="info-box-text">Monthly Paid</span><span class="info-box-number"><?= e(format_currency($totalPaid)); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-warning"><i class="bi bi-hourglass-split"></i></span><div class="info-box-content"><span class="info-box-text">Payroll Outstanding</span><span class="info-box-number"><?= e(format_currency($totalOutstanding)); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-info"><i class="bi bi-journal-check"></i></span><div class="info-box-content"><span class="info-box-text">Salary Sheets</span><span class="info-box-number"><?= number_format((int) ($sheetSummary['sheet_count'] ?? 0)); ?></span></div></div></div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-secondary"><i class="bi bi-cash-coin"></i></span><div class="info-box-content"><span class="info-box-text">Advance Outstanding</span><span class="info-box-number"><?= e(format_currency($advanceOutstandingTotal)); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-danger"><i class="bi bi-credit-card"></i></span><div class="info-box-content"><span class="info-box-text">Loan Outstanding</span><span class="info-box-number"><?= e(format_currency($loanOutstandingTotal)); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-person-check"></i></span><div class="info-box-content"><span class="info-box-text">Paid Rows</span><span class="info-box-number"><?= number_format($paidSalaryRowCount); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-warning"><i class="bi bi-person-x"></i></span><div class="info-box-content"><span class="info-box-text">Unpaid Rows</span><span class="info-box-number"><?= number_format($unpaidSalaryRowCount); ?></span></div></div></div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-6"><div class="alert alert-success mb-0"><strong>Paid:</strong> <?= number_format($paidSalaryRowCount); ?> rows fully settled.</div></div>
        <div class="col-md-6"><div class="alert alert-warning mb-0"><strong>Unpaid:</strong> <?= number_format($pendingSalaryRowCount); ?> pending + <?= number_format($partialSalaryRowCount); ?> partial rows.</div></div>
      </div>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Monthly Salary Report</h3>
          <?php if ($canExportData): ?>
            <a href="<?= e(reports_export_url('modules/reports/payroll.php', $pageParams, 'monthly_salary')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
          <?php endif; ?>
        </div>
        <div class="card-body p-0 table-responsive">
          <table class="table table-sm table-striped mb-0">
            <thead><tr><th>Garage</th><th>Staff</th><th>Type</th><th>Base</th><th>Commission</th><th>Overtime</th><th>Deductions</th><th>Net</th><th>Paid</th><th>Status</th></tr></thead>
            <tbody>
              <?php if (empty($monthlySalaryRows)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">No salary sheet rows found for selected month.</td></tr>
              <?php else: foreach ($monthlySalaryRows as $row): ?>
                <?php $salaryStatus = strtoupper((string) ($row['status'] ?? 'PENDING')); ?>
                <tr>
                  <td><?= e((string) ($row['garage_name'] ?? '')); ?></td>
                  <td><?= e((string) ($row['staff_name'] ?? '')); ?></td>
                  <td><?= e((string) ($row['salary_type'] ?? '')); ?></td>
                  <td><?= e(format_currency((float) ($row['base_amount'] ?? 0))); ?></td>
                  <td><?= e(format_currency((float) ($row['commission_amount'] ?? 0))); ?></td>
                  <td><?= e(format_currency((float) ($row['overtime_amount'] ?? 0))); ?></td>
                  <td><?= e(format_currency((float) ($row['deduction_total'] ?? 0))); ?></td>
                  <td><?= e(format_currency((float) ($row['net_payable'] ?? 0))); ?></td>
                  <td><?= e(format_currency((float) ($row['paid_amount'] ?? 0))); ?></td>
                  <td><span class="badge text-bg-<?= e(status_badge_class($salaryStatus)); ?>"><?= e($salaryStatus); ?></span></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Staff Payment History</h3>
              <?php if ($canExportData): ?>
                <a href="<?= e(reports_export_url('modules/reports/payroll.php', $pageParams, 'staff_payment_history')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a>
              <?php endif; ?>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Date</th><th>Month</th><th>Garage</th><th>Staff</th><th>Type</th><th>Amount</th><th>Mode</th></tr></thead>
                <tbody>
                  <?php if (empty($staffPaymentHistory)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No salary payment records in selected range.</td></tr>
                  <?php else: foreach ($staffPaymentHistory as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['payment_date'] ?? '')); ?></td>
                      <td><?= e((string) ($row['salary_month'] ?? '')); ?></td>
                      <td><?= e((string) ($row['garage_name'] ?? '')); ?></td>
                      <td><?= e((string) ($row['staff_name'] ?? '')); ?></td>
                      <td><?= e((string) ($row['entry_type'] ?? '')); ?></td>
                      <td><?= e(format_currency((float) ($row['amount'] ?? 0))); ?></td>
                      <td><?= e((string) ($row['payment_mode'] ?? '')); ?></td>
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
              <h3 class="card-title mb-0">Advance Ledger</h3>
              <?php if ($canExportData): ?>
                <a href="<?= e(reports_export_url('modules/reports/payroll.php', $pageParams, 'advance_ledger')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
              <?php endif; ?>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Date</th><th>Garage</th><th>Staff</th><th>Advance</th><th>Applied</th><th>Pending</th><th>Status</th></tr></thead>
                <tbody>
                  <?php if (empty($advanceLedgerRows)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No advance entries in selected range.</td></tr>
                  <?php else: foreach ($advanceLedgerRows as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['advance_date'] ?? '')); ?></td>
                      <td><?= e((string) ($row['garage_name'] ?? '')); ?></td>
                      <td><?= e((string) ($row['staff_name'] ?? '')); ?></td>
                      <td><?= e(format_currency((float) ($row['amount'] ?? 0))); ?></td>
                      <td><?= e(format_currency((float) ($row['applied_amount'] ?? 0))); ?></td>
                      <td><?= e(format_currency((float) ($row['pending_amount'] ?? 0))); ?></td>
                      <td><?= e((string) ($row['status'] ?? '')); ?></td>
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
              <h3 class="card-title mb-0">Loan Outstanding Summary</h3>
              <?php if ($canExportData): ?>
                <a href="<?= e(reports_export_url('modules/reports/payroll.php', $pageParams, 'loan_outstanding')); ?>" class="btn btn-sm btn-outline-danger">CSV</a>
              <?php endif; ?>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Loan Date</th><th>Garage</th><th>Staff</th><th>Total</th><th>Paid</th><th>Pending</th><th>EMI</th><th>Status</th></tr></thead>
                <tbody>
                  <?php if (empty($loanOutstandingRows)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No loans found in selected scope.</td></tr>
                  <?php else: foreach ($loanOutstandingRows as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['loan_date'] ?? '')); ?></td>
                      <td><?= e((string) ($row['garage_name'] ?? '')); ?></td>
                      <td><?= e((string) ($row['staff_name'] ?? '')); ?></td>
                      <td><?= e(format_currency((float) ($row['total_amount'] ?? 0))); ?></td>
                      <td><?= e(format_currency((float) ($row['paid_amount'] ?? 0))); ?></td>
                      <td><?= e(format_currency((float) ($row['pending_amount'] ?? 0))); ?></td>
                      <td><?= e(format_currency((float) ($row['emi_amount'] ?? 0))); ?></td>
                      <td><?= e((string) ($row['status'] ?? '')); ?></td>
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
              <h3 class="card-title mb-0">Mechanic Earnings (Per Job Salary Type)</h3>
              <?php if ($canExportData): ?>
                <a href="<?= e(reports_export_url('modules/reports/payroll.php', $pageParams, 'mechanic_earnings')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a>
              <?php endif; ?>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Garage</th><th>Mechanic</th><th>Closed Jobs</th><th>Gross</th><th>Net</th><th>Paid</th></tr></thead>
                <tbody>
                  <?php if (empty($mechanicEarningsRows)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No PER_JOB payroll rows found for selected month.</td></tr>
                  <?php else: foreach ($mechanicEarningsRows as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['garage_name'] ?? '')); ?></td>
                      <td><?= e((string) ($row['staff_name'] ?? '')); ?></td>
                      <td><?= number_format((int) ($row['closed_jobs'] ?? 0)); ?></td>
                      <td><?= e(format_currency((float) ($row['gross_earnings'] ?? 0))); ?></td>
                      <td><?= e(format_currency((float) ($row['net_earnings'] ?? 0))); ?></td>
                      <td><?= e(format_currency((float) ($row['paid_earnings'] ?? 0))); ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    <?php
};

if (isset($_GET['ajax']) && (string) $_GET['ajax'] === '1') {
    header('Content-Type: text/html; charset=utf-8');
    $renderReportBody(
        $sheetSummary,
        $monthlySalaryRows,
        $staffPaymentHistory,
        $advanceLedgerRows,
        $loanOutstandingRows,
        $mechanicEarningsRows,
        $pageParams,
        $canExportData,
        $totalPayable,
        $totalPaid,
        $totalOutstanding,
        $advanceOutstandingTotal,
        $loanOutstandingTotal,
        $paidSalaryRowCount,
        $partialSalaryRowCount,
        $pendingSalaryRowCount,
        $unpaidSalaryRowCount
    );
    exit;
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Payroll Reports</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Payroll</li>
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
          <form method="get" id="payroll-filter-form" class="row g-2 align-items-end">
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
            <div class="col-md-2">
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
              <label class="form-label">Salary Month</label>
              <input type="month" name="salary_month" class="form-control" value="<?= e($selectedMonth); ?>" required />
            </div>
            <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" required /></div>
            <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" required /></div>
            <div class="col-md-1 d-grid">
              <button type="submit" class="btn btn-primary">Apply</button>
            </div>
          </form>
          <div class="mt-3">
            <span class="badge text-bg-light border me-2">Garage: <?= e($scopeGarageLabel); ?></span>
            <span class="badge text-bg-light border me-2">FY: <?= e($fyLabel); ?></span>
            <span class="badge text-bg-light border me-2">Range: <?= e($fromDate); ?> to <?= e($toDate); ?></span>
            <span class="badge text-bg-light border me-2">Salary Month: <?= e($selectedMonth); ?></span>
            <span class="badge text-bg-success">Trusted Data: Salary Sheets + Payment Ledger + Advance & Loan Ledgers</span>
          </div>
        </div>
      </div>

      <div id="payroll-report-content">
        <?php $renderReportBody($sheetSummary, $monthlySalaryRows, $staffPaymentHistory, $advanceLedgerRows, $loanOutstandingRows, $mechanicEarningsRows, $pageParams, $canExportData, $totalPayable, $totalPaid, $totalOutstanding, $advanceOutstandingTotal, $loanOutstandingTotal, $paidSalaryRowCount, $partialSalaryRowCount, $pendingSalaryRowCount, $unpaidSalaryRowCount); ?>
      </div>
    </div>
  </div>
</main>

<script>
  (function () {
    var form = document.getElementById('payroll-filter-form');
    var target = document.getElementById('payroll-report-content');
    if (!form || !target) {
      return;
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var params = new URLSearchParams(new FormData(form));
      params.set('ajax', '1');

      var url = form.getAttribute('action') || window.location.pathname;
      target.classList.add('opacity-50');

      fetch(url + '?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (response) { return response.text(); })
        .then(function (html) {
          target.innerHTML = html;
        })
        .catch(function () {
          target.innerHTML = '<div class="alert alert-danger">Unable to load payroll report data. Please retry.</div>';
        })
        .finally(function () {
          target.classList.remove('opacity-50');
        });
    });
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
