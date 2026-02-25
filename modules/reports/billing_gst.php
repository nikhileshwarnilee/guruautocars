<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';

reports_require_access();

$page_title = 'Billing & GST Reports';
$active_menu = 'reports.billing';

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
$canViewFinancial = (bool) $scope['can_view_financial'];
$canExportData = (bool) $scope['can_export_data'];
$baseParams = $scope['base_params'];

$revenueDaily = [];
$revenueMonthly = [];
$revenueGarageWise = [];
$gstSummary = ['invoice_count' => 0, 'taxable_total' => 0, 'cgst_total' => 0, 'sgst_total' => 0, 'igst_total' => 0, 'tax_total' => 0, 'grand_total' => 0];
$paymentModeSummary = [];
$outstandingReceivables = [];
$gstMonthlyBreakdownRows = [];
$creditNoteRows = [];
$creditNoteSummary = ['note_count' => 0, 'taxable_total' => 0, 'cgst_total' => 0, 'sgst_total' => 0, 'igst_total' => 0, 'tax_total' => 0, 'grand_total' => 0];

function billing_gst_discount_meta_from_snapshot(array $snapshot): array
{
    $billing = is_array($snapshot['billing'] ?? null) ? $snapshot['billing'] : [];
    $type = strtoupper(trim((string) ($billing['discount_type'] ?? 'AMOUNT')));
    if (!in_array($type, ['AMOUNT', 'PERCENT'], true)) {
        $type = 'AMOUNT';
    }

    return [
        'type' => $type,
        'value' => round(max(0.0, (float) ($billing['discount_value'] ?? 0)), 2),
        'amount' => round(max(0.0, (float) ($billing['discount_amount'] ?? 0)), 2),
    ];
}

if ($canViewFinancial) {
    $revenueDailyParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $revenueDailyScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $revenueDailyParams, 'rev_daily_scope');
    $revenueDailyStmt = db()->prepare(
        'SELECT i.invoice_date, COUNT(*) AS invoice_count, COALESCE(SUM(i.grand_total), 0) AS revenue_total
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $revenueDailyScopeSql . '
           AND i.invoice_date BETWEEN :from_date AND :to_date
         GROUP BY i.invoice_date
         ORDER BY i.invoice_date ASC'
    );
    $revenueDailyStmt->execute($revenueDailyParams);
    $revenueDaily = $revenueDailyStmt->fetchAll();

    $revenueMonthlyParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $revenueMonthlyScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $revenueMonthlyParams, 'rev_month_scope');
    $revenueMonthlyStmt = db()->prepare(
        'SELECT DATE_FORMAT(i.invoice_date, "%Y-%m") AS revenue_month, COUNT(*) AS invoice_count, COALESCE(SUM(i.grand_total), 0) AS revenue_total
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $revenueMonthlyScopeSql . '
           AND i.invoice_date BETWEEN :from_date AND :to_date
         GROUP BY DATE_FORMAT(i.invoice_date, "%Y-%m")
         ORDER BY revenue_month ASC'
    );
    $revenueMonthlyStmt->execute($revenueMonthlyParams);
    $revenueMonthly = $revenueMonthlyStmt->fetchAll();

    $revenueGarageParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $revenueGarageScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $revenueGarageParams, 'rev_garage_scope');
    $revenueGarageStmt = db()->prepare(
        'SELECT g.name AS garage_name, COUNT(*) AS invoice_count, COALESCE(SUM(i.grand_total), 0) AS revenue_total
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         INNER JOIN garages g ON g.id = i.garage_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $revenueGarageScopeSql . '
           AND i.invoice_date BETWEEN :from_date AND :to_date
         GROUP BY g.id, g.name
         ORDER BY revenue_total DESC'
    );
    $revenueGarageStmt->execute($revenueGarageParams);
    $revenueGarageWise = $revenueGarageStmt->fetchAll();

    $gstParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $gstScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $gstParams, 'gst_scope');
    $gstStmt = db()->prepare(
        'SELECT COUNT(*) AS invoice_count,
                COALESCE(SUM(i.taxable_amount), 0) AS taxable_total,
                COALESCE(SUM(i.cgst_amount), 0) AS cgst_total,
                COALESCE(SUM(i.sgst_amount), 0) AS sgst_total,
                COALESCE(SUM(i.igst_amount), 0) AS igst_total,
                COALESCE(SUM(i.total_tax_amount), 0) AS tax_total,
                COALESCE(SUM(i.grand_total), 0) AS grand_total
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $gstScopeSql . '
           AND i.invoice_date BETWEEN :from_date AND :to_date'
    );
    $gstStmt->execute($gstParams);
    $gstSummary = $gstStmt->fetch() ?: $gstSummary;

    $paymentModeParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $paymentModeScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $paymentModeParams, 'pay_scope');
    $paymentModeStmt = db()->prepare(
        'SELECT p.payment_mode, COUNT(p.id) AS payment_count, COALESCE(SUM(p.amount), 0) AS collected_amount
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $paymentModeScopeSql . '
           AND p.paid_on BETWEEN :from_date AND :to_date
         GROUP BY p.payment_mode
         ORDER BY collected_amount DESC'
    );
    $paymentModeStmt->execute($paymentModeParams);
    $paymentModeSummary = $paymentModeStmt->fetchAll();

    $receivableParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $receivableScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $receivableParams, 'recv_scope');
    $receivableStmt = db()->prepare(
        'SELECT i.invoice_number, i.invoice_date, i.due_date, c.full_name AS customer_name,
                i.grand_total,
                i.snapshot_json,
                COALESCE(paid.total_paid, 0) AS paid_amount,
                (i.grand_total - COALESCE(paid.total_paid, 0)) AS outstanding_amount,
                CASE WHEN i.due_date IS NOT NULL AND i.due_date < CURDATE() THEN DATEDIFF(CURDATE(), i.due_date) ELSE 0 END AS overdue_days
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         INNER JOIN customers c ON c.id = i.customer_id
         LEFT JOIN (SELECT invoice_id, SUM(amount) AS total_paid FROM payments GROUP BY invoice_id) paid ON paid.invoice_id = i.id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $receivableScopeSql . '
           AND i.invoice_date BETWEEN :from_date AND :to_date
           AND (i.grand_total - COALESCE(paid.total_paid, 0)) > 0.01
         ORDER BY overdue_days DESC, outstanding_amount DESC
         LIMIT 100'
    );
    $receivableStmt->execute($receivableParams);
    $outstandingReceivables = $receivableStmt->fetchAll();
    foreach ($outstandingReceivables as &$row) {
        $snapshot = json_decode((string) ($row['snapshot_json'] ?? ''), true);
        if (!is_array($snapshot)) {
            $snapshot = [];
        }
        $discountMeta = billing_gst_discount_meta_from_snapshot($snapshot);
        $discountAmount = (float) ($discountMeta['amount'] ?? 0);
        $discountType = (string) ($discountMeta['type'] ?? 'AMOUNT');
        $discountValue = (float) ($discountMeta['value'] ?? 0);
        $row['discount_amount'] = $discountAmount;
        if ($discountAmount > 0.009 && $discountType === 'PERCENT' && $discountValue > 0.009) {
            $row['discount_label'] = rtrim(rtrim(number_format($discountValue, 2), '0'), '.') . '%';
        } elseif ($discountAmount > 0.009) {
            $row['discount_label'] = 'Flat';
        } else {
            $row['discount_label'] = '-';
        }
    }
    unset($row);

    $gstMonthlyParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $gstMonthlyScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $gstMonthlyParams, 'gst_month_scope');
    $gstMonthlyStmt = db()->prepare(
        'SELECT DATE_FORMAT(i.invoice_date, "%Y-%m") AS tax_month,
                COALESCE(SUM(i.cgst_amount), 0) AS cgst_total,
                COALESCE(SUM(i.sgst_amount), 0) AS sgst_total,
                COALESCE(SUM(i.igst_amount), 0) AS igst_total
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $gstMonthlyScopeSql . '
           AND i.invoice_date BETWEEN :from_date AND :to_date
         GROUP BY DATE_FORMAT(i.invoice_date, "%Y-%m")
         ORDER BY tax_month ASC'
    );
    $gstMonthlyStmt->execute($gstMonthlyParams);
    $gstMonthlyBreakdownRows = $gstMonthlyStmt->fetchAll();

    if (table_columns('credit_notes') !== []) {
        $creditParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
        $creditScopeSql = analytics_garage_scope_sql('cn.garage_id', $selectedGarageId, $garageIds, $creditParams, 'bill_credit_scope');
        $creditStmt = db()->prepare(
            'SELECT cn.credit_note_number,
                    cn.credit_note_date,
                    cu.full_name AS customer_name,
                    cu.gstin AS customer_gstin,
                    cn.taxable_amount,
                    cn.cgst_amount,
                    cn.sgst_amount,
                    cn.igst_amount,
                    cn.total_tax_amount,
                    cn.total_amount
             FROM credit_notes cn
             LEFT JOIN customers cu ON cu.id = cn.customer_id
             WHERE cn.company_id = :company_id
               AND cn.status_code = "ACTIVE"
               ' . $creditScopeSql . '
               AND cn.credit_note_date BETWEEN :from_date AND :to_date
             ORDER BY cn.credit_note_date DESC, cn.id DESC
             LIMIT 200'
        );
        $creditStmt->execute($creditParams);
        $creditNoteRows = $creditStmt->fetchAll();

        foreach ($creditNoteRows as $row) {
            $creditNoteSummary['note_count'] += 1;
            $creditNoteSummary['taxable_total'] += (float) ($row['taxable_amount'] ?? 0);
            $creditNoteSummary['cgst_total'] += (float) ($row['cgst_amount'] ?? 0);
            $creditNoteSummary['sgst_total'] += (float) ($row['sgst_amount'] ?? 0);
            $creditNoteSummary['igst_total'] += (float) ($row['igst_amount'] ?? 0);
            $creditNoteSummary['tax_total'] += (float) ($row['total_tax_amount'] ?? 0);
            $creditNoteSummary['grand_total'] += (float) ($row['total_amount'] ?? 0);
        }
    }
}

$totalOutstanding = array_reduce($outstandingReceivables, static fn (float $sum, array $row): float => $sum + (float) ($row['outstanding_amount'] ?? 0), 0.0);
$netRevenueAfterCredit = (float) ($gstSummary['grand_total'] ?? 0) - (float) ($creditNoteSummary['grand_total'] ?? 0);
$netTaxAfterCredit = (float) ($gstSummary['tax_total'] ?? 0) - (float) ($creditNoteSummary['tax_total'] ?? 0);

$gstMonthLabels = array_map(static fn (array $row): string => (string) ($row['tax_month'] ?? ''), $gstMonthlyBreakdownRows);
$chartPayload = [
    'gst_breakdown' => [
        'labels' => $gstMonthLabels,
        'cgst' => array_map(static fn (array $row): float => (float) ($row['cgst_total'] ?? 0), $gstMonthlyBreakdownRows),
        'sgst' => array_map(static fn (array $row): float => (float) ($row['sgst_total'] ?? 0), $gstMonthlyBreakdownRows),
        'igst' => array_map(static fn (array $row): float => (float) ($row['igst_total'] ?? 0), $gstMonthlyBreakdownRows),
    ],
    'monthly_sales' => [
        'labels' => array_map(static fn (array $row): string => (string) ($row['revenue_month'] ?? ''), $revenueMonthly),
        'values' => array_map(static fn (array $row): float => (float) ($row['revenue_total'] ?? 0), $revenueMonthly),
    ],
    'receivable_aging' => [
        'labels' => ['Current (0-30)', '31-60', '61-90', '90+'],
        'values' => [0.0, 0.0, 0.0, 0.0],
    ],
    'credit_note_totals' => [
        'count' => (int) ($creditNoteSummary['note_count'] ?? 0),
        'tax_total' => (float) ($creditNoteSummary['tax_total'] ?? 0),
        'grand_total' => (float) ($creditNoteSummary['grand_total'] ?? 0),
    ],
];
foreach ($outstandingReceivables as $row) {
    $overdue = (int) ($row['overdue_days'] ?? 0);
    $amount = (float) ($row['outstanding_amount'] ?? 0);
    if ($overdue <= 30) {
        $chartPayload['receivable_aging']['values'][0] += $amount;
    } elseif ($overdue <= 60) {
        $chartPayload['receivable_aging']['values'][1] += $amount;
    } elseif ($overdue <= 90) {
        $chartPayload['receivable_aging']['values'][2] += $amount;
    } else {
        $chartPayload['receivable_aging']['values'][3] += $amount;
    }
}
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
    if (!$canViewFinancial) {
        http_response_code(403);
        exit('Financial report access denied.');
    }

    $timestamp = date('Ymd_His');
    switch ($exportKey) {
        case 'revenue_daily':
            $rows = array_map(static fn (array $row): array => [(string) ($row['invoice_date'] ?? ''), (int) ($row['invoice_count'] ?? 0), (float) ($row['revenue_total'] ?? 0)], $revenueDaily);
            reports_csv_download('billing_revenue_daily_' . $timestamp . '.csv', ['Date', 'Invoices', 'Revenue'], $rows);

        case 'revenue_monthly':
            $rows = array_map(static fn (array $row): array => [(string) ($row['revenue_month'] ?? ''), (int) ($row['invoice_count'] ?? 0), (float) ($row['revenue_total'] ?? 0)], $revenueMonthly);
            reports_csv_download('billing_revenue_monthly_' . $timestamp . '.csv', ['Month', 'Invoices', 'Revenue'], $rows);

        case 'revenue_garage':
            $rows = array_map(static fn (array $row): array => [(string) ($row['garage_name'] ?? ''), (int) ($row['invoice_count'] ?? 0), (float) ($row['revenue_total'] ?? 0)], $revenueGarageWise);
            reports_csv_download('billing_revenue_garage_' . $timestamp . '.csv', ['Garage', 'Invoices', 'Revenue'], $rows);

        case 'gst_summary':
            $rows = [[
                (int) ($gstSummary['invoice_count'] ?? 0),
                (float) ($gstSummary['taxable_total'] ?? 0),
                (float) ($gstSummary['cgst_total'] ?? 0),
                (float) ($gstSummary['sgst_total'] ?? 0),
                (float) ($gstSummary['igst_total'] ?? 0),
                (float) ($gstSummary['tax_total'] ?? 0),
                (float) ($gstSummary['grand_total'] ?? 0),
            ]];
            reports_csv_download('billing_gst_summary_' . $timestamp . '.csv', ['Invoices', 'Taxable', 'CGST', 'SGST', 'IGST', 'Total GST', 'Grand Total'], $rows);

        case 'credit_notes':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['credit_note_number'] ?? ''),
                    (string) ($row['credit_note_date'] ?? ''),
                    (string) ($row['customer_name'] ?? ''),
                    (string) ($row['customer_gstin'] ?? ''),
                    (float) ($row['taxable_amount'] ?? 0),
                    (float) ($row['cgst_amount'] ?? 0),
                    (float) ($row['sgst_amount'] ?? 0),
                    (float) ($row['igst_amount'] ?? 0),
                    (float) ($row['total_tax_amount'] ?? 0),
                    (float) ($row['total_amount'] ?? 0),
                ],
                $creditNoteRows
            );
            reports_csv_download('billing_credit_notes_' . $timestamp . '.csv', ['Credit Note', 'Date', 'Customer', 'GSTIN', 'Taxable', 'CGST', 'SGST', 'IGST', 'Total GST', 'Total'], $rows);

        case 'payment_modes':
            $rows = array_map(static fn (array $row): array => [(string) ($row['payment_mode'] ?? ''), (int) ($row['payment_count'] ?? 0), (float) ($row['collected_amount'] ?? 0)], $paymentModeSummary);
            reports_csv_download('billing_payment_modes_' . $timestamp . '.csv', ['Payment Mode', 'Entries', 'Collected'], $rows);

        case 'receivables':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['invoice_number'] ?? ''),
                    (string) ($row['invoice_date'] ?? ''),
                    (string) ($row['customer_name'] ?? ''),
                    (float) ($row['discount_amount'] ?? 0),
                    (float) ($row['outstanding_amount'] ?? 0),
                    (int) ($row['overdue_days'] ?? 0),
                ],
                $outstandingReceivables
            );
            reports_csv_download('billing_receivables_' . $timestamp . '.csv', ['Invoice', 'Date', 'Customer', 'Discount', 'Outstanding', 'Overdue Days'], $rows);

        default:
            flash_set('report_error', 'Unknown export requested.', 'warning');
            redirect('modules/reports/billing_gst.php?' . http_build_query(reports_compact_query_params($baseParams)));
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Billing & GST Reports</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Billing & GST</li>
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
          <form
            method="get"
            id="billing-report-filter-form"
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
              <a href="<?= e(url('modules/reports/billing_gst.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
          <div class="mt-3">
            <span class="badge text-bg-light border me-2">Garage: <?= e($scopeGarageLabel); ?></span>
            <span class="badge text-bg-light border me-2">FY: <?= e($fyLabel); ?></span>
            <span class="badge text-bg-light border me-2">Range: <?= e($fromDate); ?> to <?= e($toDate); ?></span>
            <span class="badge text-bg-success">Trusted Data: Finalized Invoices Only</span>
          </div>
        </div>
      </div>

      <div id="billing-report-content">
        <script type="application/json" data-chart-payload><?= $chartPayloadJson ?: '{}'; ?></script>

      <?php if (!$canViewFinancial): ?>
        <div class="alert alert-warning">
          Financial reports require `reports.financial` permission.
        </div>
      <?php else: ?>
        <div class="row g-3 mb-3">
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-primary"><i class="bi bi-receipt"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Finalized Invoices</span>
                <span class="info-box-number"><?= number_format((int) ($gstSummary['invoice_count'] ?? 0)); ?></span>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-success"><i class="bi bi-currency-rupee"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Finalized Revenue</span>
                <span class="info-box-number"><?= e(format_currency((float) ($gstSummary['grand_total'] ?? 0))); ?></span>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-warning"><i class="bi bi-file-earmark-text"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Total GST</span>
                <span class="info-box-number"><?= e(format_currency((float) ($gstSummary['tax_total'] ?? 0))); ?></span>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-danger"><i class="bi bi-exclamation-diamond"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Outstanding</span>
                <span class="info-box-number"><?= e(format_currency($totalOutstanding)); ?></span>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <div class="info-box">
              <span class="info-box-icon text-bg-warning"><i class="bi bi-arrow-counterclockwise"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Credit Note Reversal</span>
                <span class="info-box-number"><?= e(format_currency((float) ($creditNoteSummary['grand_total'] ?? 0))); ?></span>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="info-box">
              <span class="info-box-icon text-bg-success"><i class="bi bi-graph-up-arrow"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Net Revenue (After CN)</span>
                <span class="info-box-number"><?= e(format_currency($netRevenueAfterCredit)); ?></span>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="info-box">
              <span class="info-box-icon text-bg-primary"><i class="bi bi-journal-check"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Net GST (After CN)</span>
                <span class="info-box-number"><?= e(format_currency($netTaxAfterCredit)); ?></span>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-lg-6">
            <div class="card h-100">
              <div class="card-header"><h3 class="card-title mb-0">GST Tax Breakdown (CGST / SGST / IGST)</h3></div>
              <div class="card-body">
                <div class="gac-chart-wrap"><canvas id="billing-chart-gst-breakdown"></canvas></div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card h-100">
              <div class="card-header"><h3 class="card-title mb-0">Monthly Sales Trend</h3></div>
              <div class="card-body">
                <div class="gac-chart-wrap"><canvas id="billing-chart-sales-trend"></canvas></div>
              </div>
            </div>
          </div>
          <div class="col-lg-12">
            <div class="card h-100">
              <div class="card-header"><h3 class="card-title mb-0">Receivable Aging Distribution</h3></div>
              <div class="card-body">
                <div class="gac-chart-wrap"><canvas id="billing-chart-receivable-aging"></canvas></div>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-lg-4">
            <div class="card h-100">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Revenue Daily</h3>
                <a href="<?= e(reports_export_url('modules/reports/billing_gst.php', $baseParams, 'revenue_daily')); ?>" class="btn btn-sm btn-outline-success">CSV</a>
              </div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Date</th><th>Invoices</th><th>Revenue</th></tr></thead>
                  <tbody>
                    <?php if (empty($revenueDaily)): ?>
                      <tr><td colspan="3" class="text-center text-muted py-4">No rows.</td></tr>
                    <?php else: foreach ($revenueDaily as $row): ?>
                      <tr><td><?= e((string) ($row['invoice_date'] ?? '')); ?></td><td><?= (int) ($row['invoice_count'] ?? 0); ?></td><td><?= e(format_currency((float) ($row['revenue_total'] ?? 0))); ?></td></tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card h-100">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Revenue Monthly</h3>
                <a href="<?= e(reports_export_url('modules/reports/billing_gst.php', $baseParams, 'revenue_monthly')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
              </div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Month</th><th>Invoices</th><th>Revenue</th></tr></thead>
                  <tbody>
                    <?php if (empty($revenueMonthly)): ?>
                      <tr><td colspan="3" class="text-center text-muted py-4">No rows.</td></tr>
                    <?php else: foreach ($revenueMonthly as $row): ?>
                      <tr><td><?= e((string) ($row['revenue_month'] ?? '')); ?></td><td><?= (int) ($row['invoice_count'] ?? 0); ?></td><td><?= e(format_currency((float) ($row['revenue_total'] ?? 0))); ?></td></tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card h-100">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Revenue Garage-wise</h3>
                <a href="<?= e(reports_export_url('modules/reports/billing_gst.php', $baseParams, 'revenue_garage')); ?>" class="btn btn-sm btn-outline-info">CSV</a>
              </div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Garage</th><th>Invoices</th><th>Revenue</th></tr></thead>
                  <tbody>
                    <?php if (empty($revenueGarageWise)): ?>
                      <tr><td colspan="3" class="text-center text-muted py-4">No rows.</td></tr>
                    <?php else: foreach ($revenueGarageWise as $row): ?>
                      <tr><td><?= e((string) ($row['garage_name'] ?? '')); ?></td><td><?= (int) ($row['invoice_count'] ?? 0); ?></td><td><?= e(format_currency((float) ($row['revenue_total'] ?? 0))); ?></td></tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-lg-4">
            <div class="card h-100">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">GST Summary</h3>
                <a href="<?= e(reports_export_url('modules/reports/billing_gst.php', $baseParams, 'gst_summary')); ?>" class="btn btn-sm btn-outline-warning">CSV</a>
              </div>
              <div class="card-body">
                <div><strong>Invoices:</strong> <?= (int) ($gstSummary['invoice_count'] ?? 0); ?></div>
                <div><strong>Taxable:</strong> <?= e(format_currency((float) ($gstSummary['taxable_total'] ?? 0))); ?></div>
                <div><strong>CGST:</strong> <?= e(format_currency((float) ($gstSummary['cgst_total'] ?? 0))); ?></div>
                <div><strong>SGST:</strong> <?= e(format_currency((float) ($gstSummary['sgst_total'] ?? 0))); ?></div>
                <div><strong>IGST:</strong> <?= e(format_currency((float) ($gstSummary['igst_total'] ?? 0))); ?></div>
                <div><strong>Total GST:</strong> <?= e(format_currency((float) ($gstSummary['tax_total'] ?? 0))); ?></div>
                <div><strong>Credit Note GST:</strong> <?= e(format_currency((float) ($creditNoteSummary['tax_total'] ?? 0))); ?></div>
                <div><strong>Net GST:</strong> <?= e(format_currency($netTaxAfterCredit)); ?></div>
              </div>
            </div>
          </div>

          <div class="col-lg-8">
            <div class="card h-100">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Payment Mode Summary</h3>
                <a href="<?= e(reports_export_url('modules/reports/billing_gst.php', $baseParams, 'payment_modes')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a>
              </div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Mode</th><th>Entries</th><th>Collected</th></tr></thead>
                  <tbody>
                    <?php if (empty($paymentModeSummary)): ?>
                      <tr><td colspan="3" class="text-center text-muted py-4">No payment data.</td></tr>
                    <?php else: foreach ($paymentModeSummary as $row): ?>
                      <tr><td><?= e((string) ($row['payment_mode'] ?? '')); ?></td><td><?= (int) ($row['payment_count'] ?? 0); ?></td><td><?= e(format_currency((float) ($row['collected_amount'] ?? 0))); ?></td></tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Credit Note GST Register</h3>
            <a href="<?= e(reports_export_url('modules/reports/billing_gst.php', $baseParams, 'credit_notes')); ?>" class="btn btn-sm btn-outline-warning">CSV</a>
          </div>
          <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead><tr><th>Credit Note</th><th>Date</th><th>Customer</th><th>GSTIN</th><th>Taxable</th><th>CGST</th><th>SGST</th><th>IGST</th><th>Total GST</th><th>Total</th></tr></thead>
              <tbody>
                <?php if (empty($creditNoteRows)): ?>
                  <tr><td colspan="10" class="text-center text-muted py-4">No credit notes in selected range.</td></tr>
                <?php else: foreach ($creditNoteRows as $row): ?>
                  <tr>
                    <td><?= e((string) ($row['credit_note_number'] ?? '')); ?></td>
                    <td><?= e((string) ($row['credit_note_date'] ?? '')); ?></td>
                    <td><?= e((string) ($row['customer_name'] ?? '')); ?></td>
                    <td><?= e((string) ($row['customer_gstin'] ?? '-')); ?></td>
                    <td><?= e(format_currency((float) ($row['taxable_amount'] ?? 0))); ?></td>
                    <td><?= e(format_currency((float) ($row['cgst_amount'] ?? 0))); ?></td>
                    <td><?= e(format_currency((float) ($row['sgst_amount'] ?? 0))); ?></td>
                    <td><?= e(format_currency((float) ($row['igst_amount'] ?? 0))); ?></td>
                    <td><?= e(format_currency((float) ($row['total_tax_amount'] ?? 0))); ?></td>
                    <td><?= e(format_currency((float) ($row['total_amount'] ?? 0))); ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Outstanding Receivables</h3>
            <a href="<?= e(reports_export_url('modules/reports/billing_gst.php', $baseParams, 'receivables')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
          </div>
          <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Discount</th><th>Outstanding</th><th>Overdue</th></tr></thead>
              <tbody>
                <?php if (empty($outstandingReceivables)): ?>
                  <tr><td colspan="6" class="text-center text-muted py-4">No outstanding receivables.</td></tr>
                <?php else: foreach ($outstandingReceivables as $row): ?>
                  <tr>
                    <td><?= e((string) ($row['invoice_number'] ?? '')); ?></td>
                    <td><?= e((string) ($row['invoice_date'] ?? '')); ?></td>
                    <td><?= e((string) ($row['customer_name'] ?? '')); ?></td>
                    <td>
                      <?= e(format_currency((float) ($row['discount_amount'] ?? 0))); ?>
                      <?php if ((string) ($row['discount_label'] ?? '-') !== '-'): ?>
                        <div><small class="text-muted"><?= e((string) ($row['discount_label'] ?? '-')); ?></small></div>
                      <?php endif; ?>
                    </td>
                    <td><?= e(format_currency((float) ($row['outstanding_amount'] ?? 0))); ?></td>
                    <td><?= (int) ($row['overdue_days'] ?? 0); ?> day(s)</td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
      </div>
    </div>
  </div>
</main>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (!window.GacCharts) {
      return;
    }

    var form = document.getElementById('billing-report-filter-form');
    var content = document.getElementById('billing-report-content');
    if (!form || !content) {
      return;
    }

    var charts = window.GacCharts.createRegistry('billing-report');

    function renderCharts() {
      var payload = window.GacCharts.parsePayload(content);
      if (!payload || !payload.gst_breakdown) {
        return;
      }

      charts.render('#billing-chart-gst-breakdown', {
        type: 'bar',
        data: {
          labels: payload.gst_breakdown.labels || [],
          datasets: [{
            label: 'CGST',
            data: payload.gst_breakdown.cgst || [],
            backgroundColor: window.GacCharts.palette.blue
          }, {
            label: 'SGST',
            data: payload.gst_breakdown.sgst || [],
            backgroundColor: window.GacCharts.palette.green
          }, {
            label: 'IGST',
            data: payload.gst_breakdown.igst || [],
            backgroundColor: window.GacCharts.palette.orange
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            x: { stacked: true },
            y: { stacked: true, beginAtZero: true }
          },
          plugins: { legend: { position: 'bottom' } }
        }
      }, { emptyMessage: 'No GST tax rows for selected range.' });

      charts.render('#billing-chart-sales-trend', {
        type: 'line',
        data: {
          labels: payload.monthly_sales ? payload.monthly_sales.labels : [],
          datasets: [{
            label: 'Finalized Sales',
            data: payload.monthly_sales ? payload.monthly_sales.values : [],
            borderColor: window.GacCharts.palette.indigo,
            backgroundColor: window.GacCharts.palette.indigo + '33',
            fill: true,
            tension: 0.25
          }]
        },
        options: window.GacCharts.commonOptions()
      }, { emptyMessage: 'No monthly sales rows in selected range.' });

      charts.render('#billing-chart-receivable-aging', {
        type: 'bar',
        data: {
          labels: payload.receivable_aging ? payload.receivable_aging.labels : [],
          datasets: [{
            label: 'Outstanding Amount',
            data: payload.receivable_aging ? payload.receivable_aging.values : [],
            backgroundColor: [
              window.GacCharts.palette.teal,
              window.GacCharts.palette.yellow,
              window.GacCharts.palette.orange,
              window.GacCharts.palette.red
            ]
          }]
        },
        options: window.GacCharts.commonOptions()
      }, { emptyMessage: 'No outstanding receivable rows in selected range.' });
    }

    renderCharts();

    window.GacCharts.bindAjaxForm({
      form: form,
      target: content,
      mode: 'full',
      sourceSelector: '#billing-report-content',
      afterUpdate: function () {
        renderCharts();
      }
    });
  });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
