<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';
require_once __DIR__ . '/../billing/workflow.php';
require_once __DIR__ . '/../returns/workflow.php';

reports_require_access();
$canViewSalesPayments = has_permission('billing.view') || has_permission('invoice.view') || has_permission('reports.financial') || has_permission('financial.reports');
$canViewPurchasePayments = has_permission('purchase.view') || has_permission('purchase.manage') || has_permission('vendor.payments');
$canViewReturnSettlements = has_permission('inventory.view') || has_permission('billing.view') || has_permission('purchase.view') || has_permission('report.view');
if (!$canViewSalesPayments && !$canViewPurchasePayments && !$canViewReturnSettlements) {
    flash_set('access_denied', 'You do not have permission to view payments report.', 'danger');
    redirect('modules/reports/index.php');
}

$page_title = 'Payments Report';
$active_menu = 'reports.payments';
billing_financial_extensions_ready();
$returnsModuleReady = returns_module_ready();

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

$paymentModeFilter = strtoupper(trim((string) ($_GET['payment_mode'] ?? '')));
$customerFilter = get_int('customer_id');
$vendorFilter = get_int('vendor_id');
$invoiceFilter = trim((string) ($_GET['invoice_no'] ?? ''));

$allowedModes = ['CASH', 'UPI', 'CARD', 'BANK_TRANSFER', 'CHEQUE', 'MIXED', 'ADJUSTMENT'];
if ($paymentModeFilter !== '' && !in_array($paymentModeFilter, $allowedModes, true)) {
    $paymentModeFilter = '';
}

$pageParams = array_merge($baseParams, [
    'payment_mode' => $paymentModeFilter !== '' ? $paymentModeFilter : null,
    'customer_id' => $customerFilter > 0 ? $customerFilter : null,
    'vendor_id' => $vendorFilter > 0 ? $vendorFilter : null,
    'invoice_no' => $invoiceFilter !== '' ? $invoiceFilter : null,
]);

$customersStmt = db()->prepare(
    'SELECT id, full_name
     FROM customers
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY full_name ASC'
);
$customersStmt->execute(['company_id' => $companyId]);
$customers = $customersStmt->fetchAll();

$vendorsStmt = db()->prepare(
    'SELECT id, vendor_name
     FROM vendors
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY vendor_name ASC'
);
$vendorsStmt->execute(['company_id' => $companyId]);
$vendors = $vendorsStmt->fetchAll();

$salesRows = [];
$salesSummary = ['payment_count' => 0, 'payment_total' => 0.0];
$salesReversalSummary = ['payment_count' => 0, 'payment_total' => 0.0];
$salesModeSummaryRows = [];
$salesDailyCashRows = [];
if ($canViewSalesPayments) {
    $salesParams = [
        'company_id' => $companyId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ];
    $salesScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $salesParams, 'pay_sales_scope');
    $salesWhere = [
        'i.company_id = :company_id',
        'p.paid_on BETWEEN :from_date AND :to_date',
        'i.invoice_status = "FINALIZED"',
    ];
    if ($paymentModeFilter !== '') {
        $salesWhere[] = 'p.payment_mode = :payment_mode';
        $salesParams['payment_mode'] = $paymentModeFilter;
    }
    if ($customerFilter > 0) {
        $salesWhere[] = 'i.customer_id = :customer_id';
        $salesParams['customer_id'] = $customerFilter;
    }
    if ($invoiceFilter !== '') {
        $salesWhere[] = 'i.invoice_number LIKE :invoice_like';
        $salesParams['invoice_like'] = '%' . $invoiceFilter . '%';
    }
    $salesWhereSql = implode(' AND ', $salesWhere);
    $salesUnreversedFilterSql = reversal_sales_payment_unreversed_filter_sql('p');
    $salesReversalFilterSql = reversal_sales_payment_reversal_filter_sql('p');

    $salesSummaryStmt = db()->prepare(
        'SELECT COUNT(*) AS payment_count,
                COALESCE(SUM(p.amount), 0) AS payment_total
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         WHERE ' . $salesWhereSql . '
           AND ' . $salesUnreversedFilterSql . ' ' . $salesScopeSql
    );
    $salesSummaryStmt->execute($salesParams);
    $salesSummary = $salesSummaryStmt->fetch() ?: $salesSummary;

    $salesReversalSummaryStmt = db()->prepare(
        'SELECT COUNT(*) AS payment_count,
                COALESCE(SUM(ABS(p.amount)), 0) AS payment_total
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         WHERE ' . $salesWhereSql . '
           AND ' . $salesReversalFilterSql . ' ' . $salesScopeSql
    );
    $salesReversalSummaryStmt->execute($salesParams);
    $salesReversalSummary = $salesReversalSummaryStmt->fetch() ?: $salesReversalSummary;

    $salesRowsStmt = db()->prepare(
        'SELECT p.id, p.paid_on AS payment_date, p.entry_type, p.payment_mode, p.amount, p.reference_no,
                p.receipt_number,
                i.invoice_number,
                c.full_name AS party_name,
                g.name AS garage_name,
                "SALES" AS payment_side
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         LEFT JOIN customers c ON c.id = i.customer_id
         LEFT JOIN garages g ON g.id = i.garage_id
         WHERE ' . $salesWhereSql . ' ' . $salesScopeSql . '
         ORDER BY p.id DESC
         LIMIT 600'
    );
    $salesRowsStmt->execute($salesParams);
    $salesRows = $salesRowsStmt->fetchAll();

    $salesModeSummaryStmt = db()->prepare(
        'SELECT UPPER(COALESCE(NULLIF(TRIM(p.payment_mode), ""), "UNKNOWN")) AS payment_mode,
                COUNT(*) AS entries,
                COALESCE(SUM(p.amount), 0) AS amount
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         WHERE ' . $salesWhereSql . '
           AND ' . $salesUnreversedFilterSql . ' ' . $salesScopeSql . '
         GROUP BY payment_mode
         ORDER BY payment_mode ASC'
    );
    $salesModeSummaryStmt->execute($salesParams);
    $salesModeSummaryRows = $salesModeSummaryStmt->fetchAll();

    $salesDailyCashStmt = db()->prepare(
        'SELECT p.paid_on AS payment_date,
                COALESCE(SUM(p.amount), 0) AS cash_in
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         WHERE ' . $salesWhereSql . '
           AND ' . $salesUnreversedFilterSql . ' ' . $salesScopeSql . '
           AND p.payment_mode = "CASH"
         GROUP BY p.paid_on
         ORDER BY p.paid_on ASC'
    );
    $salesDailyCashStmt->execute($salesParams);
    $salesDailyCashRows = $salesDailyCashStmt->fetchAll();
}

$purchaseRows = [];
$purchaseSummary = ['payment_count' => 0, 'payment_total' => 0.0];
$purchaseReversalSummary = ['payment_count' => 0, 'payment_total' => 0.0];
$purchaseModeSummaryRows = [];
$purchaseDailyCashRows = [];
if ($canViewPurchasePayments && table_columns('purchase_payments') !== []) {
    $purchaseParams = [
        'company_id' => $companyId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ];
    $purchaseScopeSql = analytics_garage_scope_sql('pp.garage_id', $selectedGarageId, $garageIds, $purchaseParams, 'pay_purchase_scope');
    $purchaseWhere = [
        'pp.company_id = :company_id',
        'pp.payment_date BETWEEN :from_date AND :to_date',
    ];
    if ($paymentModeFilter !== '') {
        $purchaseWhere[] = 'pp.payment_mode = :payment_mode';
        $purchaseParams['payment_mode'] = $paymentModeFilter;
    }
    if ($vendorFilter > 0) {
        $purchaseWhere[] = 'p.vendor_id = :vendor_id';
        $purchaseParams['vendor_id'] = $vendorFilter;
    }
    if ($invoiceFilter !== '') {
        $purchaseWhere[] = 'p.invoice_number LIKE :invoice_like';
        $purchaseParams['invoice_like'] = '%' . $invoiceFilter . '%';
    }
    $purchaseWhereSql = implode(' AND ', $purchaseWhere);
    $purchaseUnreversedFilterSql = reversal_purchase_payment_unreversed_filter_sql('pp');
    $purchaseReversalFilterSql = reversal_purchase_payment_reversal_filter_sql('pp');

    $purchaseSummaryStmt = db()->prepare(
        'SELECT COUNT(*) AS payment_count,
                COALESCE(SUM(pp.amount), 0) AS payment_total
         FROM purchase_payments pp
         INNER JOIN purchases p ON p.id = pp.purchase_id
         WHERE ' . $purchaseWhereSql . '
           AND ' . $purchaseUnreversedFilterSql . ' ' . $purchaseScopeSql
    );
    $purchaseSummaryStmt->execute($purchaseParams);
    $purchaseSummary = $purchaseSummaryStmt->fetch() ?: $purchaseSummary;

    $purchaseReversalSummaryStmt = db()->prepare(
        'SELECT COUNT(*) AS payment_count,
                COALESCE(SUM(ABS(pp.amount)), 0) AS payment_total
         FROM purchase_payments pp
         INNER JOIN purchases p ON p.id = pp.purchase_id
         WHERE ' . $purchaseWhereSql . '
           AND ' . $purchaseReversalFilterSql . ' ' . $purchaseScopeSql
    );
    $purchaseReversalSummaryStmt->execute($purchaseParams);
    $purchaseReversalSummary = $purchaseReversalSummaryStmt->fetch() ?: $purchaseReversalSummary;

    $purchaseRowsStmt = db()->prepare(
        'SELECT pp.id, pp.payment_date, pp.entry_type, pp.payment_mode, pp.amount, pp.reference_no,
                NULL AS receipt_number,
                p.invoice_number,
                COALESCE(v.vendor_name, "UNASSIGNED") AS party_name,
                g.name AS garage_name,
                "PURCHASE" AS payment_side
         FROM purchase_payments pp
         INNER JOIN purchases p ON p.id = pp.purchase_id
         LEFT JOIN vendors v ON v.id = p.vendor_id
         LEFT JOIN garages g ON g.id = pp.garage_id
         WHERE ' . $purchaseWhereSql . ' ' . $purchaseScopeSql . '
         ORDER BY pp.id DESC
         LIMIT 600'
    );
    $purchaseRowsStmt->execute($purchaseParams);
    $purchaseRows = $purchaseRowsStmt->fetchAll();

    $purchaseModeSummaryStmt = db()->prepare(
        'SELECT UPPER(COALESCE(NULLIF(TRIM(pp.payment_mode), ""), "UNKNOWN")) AS payment_mode,
                COUNT(*) AS entries,
                COALESCE(SUM(pp.amount), 0) AS amount
         FROM purchase_payments pp
         INNER JOIN purchases p ON p.id = pp.purchase_id
         WHERE ' . $purchaseWhereSql . '
           AND ' . $purchaseUnreversedFilterSql . ' ' . $purchaseScopeSql . '
         GROUP BY payment_mode
         ORDER BY payment_mode ASC'
    );
    $purchaseModeSummaryStmt->execute($purchaseParams);
    $purchaseModeSummaryRows = $purchaseModeSummaryStmt->fetchAll();

    $purchaseDailyCashStmt = db()->prepare(
        'SELECT pp.payment_date,
                COALESCE(SUM(pp.amount), 0) AS cash_out
         FROM purchase_payments pp
         INNER JOIN purchases p ON p.id = pp.purchase_id
         WHERE ' . $purchaseWhereSql . '
           AND ' . $purchaseUnreversedFilterSql . ' ' . $purchaseScopeSql . '
           AND pp.payment_mode = "CASH"
         GROUP BY pp.payment_date
         ORDER BY pp.payment_date ASC'
    );
    $purchaseDailyCashStmt->execute($purchaseParams);
    $purchaseDailyCashRows = $purchaseDailyCashStmt->fetchAll();
}

$returnSettlementRows = [];
$returnSettlementSummary = [
    'payment_count' => 0,
    'payment_total' => 0.0,
    'pay_total' => 0.0,
    'receive_total' => 0.0,
];
$returnModeSummaryRows = [];
$returnDailyCashRows = [];
if ($canViewReturnSettlements && $returnsModuleReady && table_columns('return_settlements') !== []) {
    $returnParams = [
        'company_id' => $companyId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ];
    $returnScopeSql = analytics_garage_scope_sql('rs.garage_id', $selectedGarageId, $garageIds, $returnParams, 'pay_return_scope');
    $returnWhere = [
        'rs.company_id = :company_id',
        'rs.status_code = "ACTIVE"',
        'r.status_code = "ACTIVE"',
        'rs.settlement_date BETWEEN :from_date AND :to_date',
    ];
    if ($paymentModeFilter !== '') {
        $returnWhere[] = 'rs.payment_mode = :payment_mode';
        $returnParams['payment_mode'] = $paymentModeFilter;
    }
    if ($customerFilter > 0) {
        $returnWhere[] = 'r.customer_id = :customer_id';
        $returnParams['customer_id'] = $customerFilter;
    }
    if ($vendorFilter > 0) {
        $returnWhere[] = 'r.vendor_id = :vendor_id';
        $returnParams['vendor_id'] = $vendorFilter;
    }
    if ($invoiceFilter !== '') {
        $returnWhere[] = '(r.return_number LIKE :invoice_like OR i.invoice_number LIKE :invoice_like OR p.invoice_number LIKE :invoice_like)';
        $returnParams['invoice_like'] = '%' . $invoiceFilter . '%';
    }
    $returnWhereSql = implode(' AND ', $returnWhere);

    $returnSummaryStmt = db()->prepare(
        'SELECT COUNT(*) AS payment_count,
                COALESCE(SUM(rs.amount), 0) AS payment_total,
                COALESCE(SUM(CASE WHEN rs.settlement_type = "PAY" THEN rs.amount ELSE 0 END), 0) AS pay_total,
                COALESCE(SUM(CASE WHEN rs.settlement_type = "RECEIVE" THEN rs.amount ELSE 0 END), 0) AS receive_total
         FROM return_settlements rs
         INNER JOIN returns_rma r ON r.id = rs.return_id
         LEFT JOIN invoices i ON i.id = r.invoice_id
         LEFT JOIN purchases p ON p.id = r.purchase_id
         WHERE ' . $returnWhereSql . ' ' . $returnScopeSql
    );
    $returnSummaryStmt->execute($returnParams);
    $returnSettlementSummary = $returnSummaryStmt->fetch() ?: $returnSettlementSummary;

    $returnRowsStmt = db()->prepare(
        'SELECT rs.id,
                rs.settlement_date AS payment_date,
                rs.settlement_type AS entry_type,
                rs.payment_mode,
                rs.amount,
                rs.reference_no,
                NULL AS receipt_number,
                r.return_number AS invoice_number,
                CASE
                    WHEN r.return_type = "CUSTOMER_RETURN" THEN COALESCE(c.full_name, "UNASSIGNED")
                    ELSE COALESCE(v.vendor_name, "UNASSIGNED")
                END AS party_name,
                g.name AS garage_name,
                CASE
                    WHEN rs.settlement_type = "PAY" THEN "RETURN_PAY"
                    ELSE "RETURN_RECEIVE"
                END AS payment_side
         FROM return_settlements rs
         INNER JOIN returns_rma r ON r.id = rs.return_id
         LEFT JOIN invoices i ON i.id = r.invoice_id
         LEFT JOIN purchases p ON p.id = r.purchase_id
         LEFT JOIN customers c ON c.id = r.customer_id
         LEFT JOIN vendors v ON v.id = r.vendor_id
         LEFT JOIN garages g ON g.id = rs.garage_id
         WHERE ' . $returnWhereSql . ' ' . $returnScopeSql . '
         ORDER BY rs.id DESC
         LIMIT 600'
    );
    $returnRowsStmt->execute($returnParams);
    $returnSettlementRows = $returnRowsStmt->fetchAll();

    $returnModeSummaryStmt = db()->prepare(
        'SELECT UPPER(COALESCE(NULLIF(TRIM(rs.payment_mode), ""), "UNKNOWN")) AS payment_mode,
                COUNT(*) AS entries,
                COALESCE(SUM(rs.amount), 0) AS amount
         FROM return_settlements rs
         INNER JOIN returns_rma r ON r.id = rs.return_id
         LEFT JOIN invoices i ON i.id = r.invoice_id
         LEFT JOIN purchases p ON p.id = r.purchase_id
         WHERE ' . $returnWhereSql . ' ' . $returnScopeSql . '
         GROUP BY payment_mode
         ORDER BY payment_mode ASC'
    );
    $returnModeSummaryStmt->execute($returnParams);
    $returnModeSummaryRows = $returnModeSummaryStmt->fetchAll();

    $returnDailyCashStmt = db()->prepare(
        'SELECT rs.settlement_date AS payment_date,
                COALESCE(SUM(CASE WHEN rs.settlement_type = "RECEIVE" THEN rs.amount ELSE 0 END), 0) AS cash_in,
                COALESCE(SUM(CASE WHEN rs.settlement_type = "PAY" THEN rs.amount ELSE 0 END), 0) AS cash_out
         FROM return_settlements rs
         INNER JOIN returns_rma r ON r.id = rs.return_id
         LEFT JOIN invoices i ON i.id = r.invoice_id
         LEFT JOIN purchases p ON p.id = r.purchase_id
         WHERE ' . $returnWhereSql . ' ' . $returnScopeSql . '
           AND rs.payment_mode = "CASH"
         GROUP BY rs.settlement_date
         ORDER BY rs.settlement_date ASC'
    );
    $returnDailyCashStmt->execute($returnParams);
    $returnDailyCashRows = $returnDailyCashStmt->fetchAll();
}

$allRows = array_merge($salesRows, $purchaseRows, $returnSettlementRows);
usort($allRows, static function (array $a, array $b): int {
    $dateA = (string) ($a['payment_date'] ?? ($a['paid_on'] ?? ''));
    $dateB = (string) ($b['payment_date'] ?? ($b['paid_on'] ?? ''));
    if ($dateA === $dateB) {
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    }
    return strcmp($dateB, $dateA);
});

$modeSummary = [];
$mergeModeSummaryRows = static function (array &$summary, array $rows): void {
    foreach ($rows as $row) {
        $mode = strtoupper(trim((string) ($row['payment_mode'] ?? '')));
        if ($mode === '') {
            $mode = 'UNKNOWN';
        }
        if (!isset($summary[$mode])) {
            $summary[$mode] = ['entries' => 0, 'amount' => 0.0];
        }
        $summary[$mode]['entries'] += (int) ($row['entries'] ?? 0);
        $summary[$mode]['amount'] += (float) ($row['amount'] ?? 0);
    }
};
$mergeModeSummaryRows($modeSummary, $salesModeSummaryRows);
$mergeModeSummaryRows($modeSummary, $purchaseModeSummaryRows);
$mergeModeSummaryRows($modeSummary, $returnModeSummaryRows);
ksort($modeSummary);

$dailyCash = [];
$mergeDailyCashRow = static function (array &$summary, string $dateKey, float $cashInDelta, float $cashOutDelta): void {
    if ($dateKey === '') {
        return;
    }
    if (!isset($summary[$dateKey])) {
        $summary[$dateKey] = ['cash_in' => 0.0, 'cash_out' => 0.0, 'net_cash' => 0.0];
    }
    $summary[$dateKey]['cash_in'] += $cashInDelta;
    $summary[$dateKey]['cash_out'] += $cashOutDelta;
    $summary[$dateKey]['net_cash'] = $summary[$dateKey]['cash_in'] - $summary[$dateKey]['cash_out'];
};
foreach ($salesDailyCashRows as $row) {
    $mergeDailyCashRow(
        $dailyCash,
        (string) ($row['payment_date'] ?? ''),
        (float) ($row['cash_in'] ?? 0),
        0.0
    );
}
foreach ($purchaseDailyCashRows as $row) {
    $mergeDailyCashRow(
        $dailyCash,
        (string) ($row['payment_date'] ?? ''),
        0.0,
        (float) ($row['cash_out'] ?? 0)
    );
}
foreach ($returnDailyCashRows as $row) {
    $mergeDailyCashRow(
        $dailyCash,
        (string) ($row['payment_date'] ?? ''),
        (float) ($row['cash_in'] ?? 0),
        (float) ($row['cash_out'] ?? 0)
    );
}
ksort($dailyCash);

$exportKey = trim((string) ($_GET['export'] ?? ''));
if ($exportKey !== '') {
    if (!$canExportData) {
        http_response_code(403);
        exit('Export access denied.');
    }
    $timestamp = date('Ymd_His');
    if ($exportKey === 'ledger') {
        $csvRows = array_map(static fn (array $row): array => [
            (string) ($row['payment_date'] ?? ($row['paid_on'] ?? '')),
            (string) ($row['payment_side'] ?? ''),
            (string) ($row['invoice_number'] ?? ''),
            (string) ($row['party_name'] ?? ''),
            (string) ($row['payment_mode'] ?? ''),
            (string) ($row['entry_type'] ?? ''),
            (string) (($row['receipt_number'] ?? '') !== '' ? $row['receipt_number'] : '-'),
            (float) ($row['amount'] ?? 0),
            (string) ($row['reference_no'] ?? ''),
            (string) ($row['garage_name'] ?? ''),
        ], $allRows);
        reports_csv_download('payments_ledger_' . $timestamp . '.csv', ['Date', 'Side', 'Document', 'Party', 'Mode', 'Entry Type', 'Receipt', 'Amount', 'Reference', 'Garage'], $csvRows);
    }
    if ($exportKey === 'mode_summary') {
        $csvRows = [];
        foreach ($modeSummary as $mode => $stats) {
            $csvRows[] = [(string) $mode, (int) ($stats['entries'] ?? 0), (float) ($stats['amount'] ?? 0)];
        }
        reports_csv_download('payments_mode_summary_' . $timestamp . '.csv', ['Mode', 'Entries', 'Amount'], $csvRows);
    }
    if ($exportKey === 'daily_cash') {
        $csvRows = [];
        foreach ($dailyCash as $date => $stats) {
            $csvRows[] = [(string) $date, (float) ($stats['cash_in'] ?? 0), (float) ($stats['cash_out'] ?? 0), (float) ($stats['net_cash'] ?? 0)];
        }
        reports_csv_download('payments_daily_cash_' . $timestamp . '.csv', ['Date', 'Cash In', 'Cash Out', 'Net Cash'], $csvRows);
    }
    flash_set('report_error', 'Unknown export requested.', 'warning');
    redirect('modules/reports/payments.php?' . http_build_query(reports_compact_query_params($pageParams)));
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Payments Report</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Payments</li>
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
        </div>
      </div>

      <div class="card card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Filters</h3></div>
        <div class="card-body">
          <form method="get" class="row g-2 align-items-end" data-date-filter-form="1" data-date-range-start="<?= e((string) $scope['date_range_start']); ?>" data-date-range-end="<?= e((string) $scope['date_range_end']); ?>" data-date-yearly-start="<?= e((string) $scope['fy_start']); ?>">
            <?php if ($allowAllGarages || count($garageIds) > 1): ?>
              <div class="col-md-3">
                <label class="form-label">Garage Scope</label>
                <select name="garage_id" class="form-select" data-searchable-select="1">
                  <?php if ($allowAllGarages): ?>
                    <option value="0" <?= $selectedGarageId === 0 ? 'selected' : ''; ?>>All Accessible Garages</option>
                  <?php endif; ?>
                  <?php foreach ($garageOptions as $garage): ?>
                    <option value="<?= (int) $garage['id']; ?>" <?= ((int) $garage['id'] === $selectedGarageId) ? 'selected' : ''; ?>><?= e((string) $garage['name']); ?> (<?= e((string) $garage['code']); ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <div class="col-md-3"><label class="form-label">Garage Scope</label><input type="text" class="form-control" value="<?= e($scopeGarageLabel); ?>" readonly><input type="hidden" name="garage_id" value="<?= (int) $selectedGarageId; ?>"></div>
            <?php endif; ?>

            <div class="col-md-2"><label class="form-label">Financial Year</label><select name="fy_id" class="form-select"><?php foreach ($financialYears as $fy): ?><option value="<?= (int) ($fy['id'] ?? 0); ?>" <?= ((int) ($fy['id'] ?? 0) === $selectedFyId) ? 'selected' : ''; ?>><?= e((string) ($fy['fy_label'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Date Mode</label><select name="date_mode" class="form-select"><?php foreach ($dateModeOptions as $modeValue => $modeLabel): ?><option value="<?= e((string) $modeValue); ?>" <?= $dateMode === $modeValue ? 'selected' : ''; ?>><?= e((string) $modeLabel); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" required></div>
            <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" required></div>
            <div class="col-md-2"><label class="form-label">Payment Mode</label><select name="payment_mode" class="form-select"><option value="">All</option><?php foreach ($allowedModes as $mode): ?><option value="<?= e($mode); ?>" <?= $paymentModeFilter === $mode ? 'selected' : ''; ?>><?= e($mode); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Customer</label><select name="customer_id" class="form-select" data-searchable-select="1"><option value="0">All Customers</option><?php foreach ($customers as $customer): ?><option value="<?= (int) ($customer['id'] ?? 0); ?>" <?= $customerFilter === (int) ($customer['id'] ?? 0) ? 'selected' : ''; ?>><?= e((string) ($customer['full_name'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Vendor</label><select name="vendor_id" class="form-select" data-searchable-select="1"><option value="0">All Vendors</option><?php foreach ($vendors as $vendor): ?><option value="<?= (int) ($vendor['id'] ?? 0); ?>" <?= $vendorFilter === (int) ($vendor['id'] ?? 0) ? 'selected' : ''; ?>><?= e((string) ($vendor['vendor_name'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Invoice/Return No</label><input type="text" name="invoice_no" class="form-control" value="<?= e($invoiceFilter); ?>" placeholder="Document search"></div>
            <div class="col-md-2 d-flex gap-2"><button type="submit" class="btn btn-primary">Apply</button><a href="<?= e(url('modules/reports/payments.php')); ?>" class="btn btn-outline-secondary">Reset</a></div>
          </form>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="small-box text-bg-primary"><div class="inner"><h4><?= e(format_currency((float) ($salesSummary['payment_total'] ?? 0))); ?></h4><p>Sales Payments</p></div><span class="small-box-icon"><i class="bi bi-receipt"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-warning"><div class="inner"><h4><?= e(format_currency((float) ($purchaseSummary['payment_total'] ?? 0))); ?></h4><p>Purchase Payments</p></div><span class="small-box-icon"><i class="bi bi-bag"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-success"><div class="inner"><h4><?= e(format_currency((float) ($returnSettlementSummary['receive_total'] ?? 0))); ?></h4><p>Return Receipts</p></div><span class="small-box-icon"><i class="bi bi-arrow-down-circle"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-danger"><div class="inner"><h4><?= e(format_currency((float) ($returnSettlementSummary['pay_total'] ?? 0))); ?></h4><p>Return Refunds</p></div><span class="small-box-icon"><i class="bi bi-arrow-up-circle"></i></span></div></div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="small-box text-bg-primary"><div class="inner"><h4><?= number_format((int) ($salesSummary['payment_count'] ?? 0)); ?></h4><p>Sales Entries</p></div><span class="small-box-icon"><i class="bi bi-list-check"></i></span></div></div>
        <div class="col-md-4"><div class="small-box text-bg-secondary"><div class="inner"><h4><?= number_format((int) ($purchaseSummary['payment_count'] ?? 0)); ?></h4><p>Purchase Entries</p></div><span class="small-box-icon"><i class="bi bi-list-ul"></i></span></div></div>
        <div class="col-md-4"><div class="small-box text-bg-info"><div class="inner"><h4><?= number_format((int) ($returnSettlementSummary['payment_count'] ?? 0)); ?></h4><p>Return Entries</p></div><span class="small-box-icon"><i class="bi bi-arrow-repeat"></i></span></div></div>
      </div>

      <?php
        $salesReversalExcludedAmount = (float) ($salesReversalSummary['payment_total'] ?? 0);
        $salesReversalExcludedCount = (int) ($salesReversalSummary['payment_count'] ?? 0);
        $purchaseReversalExcludedAmount = (float) ($purchaseReversalSummary['payment_total'] ?? 0);
        $purchaseReversalExcludedCount = (int) ($purchaseReversalSummary['payment_count'] ?? 0);
        $hasExcludedReversalPayments = $salesReversalExcludedAmount > 0.009 || $purchaseReversalExcludedAmount > 0.009;
      ?>
      <?php if ($hasExcludedReversalPayments): ?>
        <div class="alert alert-warning mb-3">
          Reversed payment amounts are shown in the ledger, but excluded from summary cards, mode-wise totals, and Daily Cash Summary.
          Sales Reversed (Excluded): <strong><?= e(format_currency($salesReversalExcludedAmount)); ?></strong> (<?= number_format($salesReversalExcludedCount); ?>)
          |
          Purchase Reversed (Excluded): <strong><?= e(format_currency($purchaseReversalExcludedAmount)); ?></strong> (<?= number_format($purchaseReversalExcludedCount); ?>)
        </div>
      <?php endif; ?>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Unified Payment Ledger (Sales + Purchases + Returns)</h3>
          <a href="<?= e(reports_export_url('modules/reports/payments.php', $pageParams, 'ledger')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead><tr><th>Date</th><th>Side</th><th>Document</th><th>Party</th><th>Mode</th><th>Entry</th><th>Receipt</th><th>Amount</th><th>Reference</th><th>Garage</th></tr></thead>
            <tbody>
              <?php if (empty($allRows)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">No payment records found.</td></tr>
              <?php else: ?>
                <?php foreach ($allRows as $row): ?>
                  <tr>
                    <td><?= e((string) ($row['payment_date'] ?? ($row['paid_on'] ?? '-'))); ?></td>
                    <td><?= e(str_replace('_', ' ', (string) ($row['payment_side'] ?? '-'))); ?></td>
                    <td><?= e((string) ($row['invoice_number'] ?? '-')); ?></td>
                    <td><?= e((string) ($row['party_name'] ?? '-')); ?></td>
                    <td><?= e((string) ($row['payment_mode'] ?? '-')); ?></td>
                    <td><?= e(str_replace('_', ' ', (string) ($row['entry_type'] ?? '-'))); ?></td>
                    <td><?= e((string) (($row['receipt_number'] ?? '') !== '' ? $row['receipt_number'] : '-')); ?></td>
                    <td><?= e(format_currency((float) ($row['amount'] ?? 0))); ?></td>
                    <td><?= e((string) (($row['reference_no'] ?? '') !== '' ? $row['reference_no'] : '-')); ?></td>
                    <td><?= e((string) ($row['garage_name'] ?? '-')); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Mode-wise Summary</h3><a href="<?= e(reports_export_url('modules/reports/payments.php', $pageParams, 'mode_summary')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Mode</th><th>Entries</th><th>Amount</th></tr></thead>
                <tbody>
                  <?php if (empty($modeSummary)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No mode summary available.</td></tr>
                  <?php else: ?>
                    <?php foreach ($modeSummary as $mode => $stats): ?>
                      <tr><td><?= e((string) $mode); ?></td><td><?= (int) ($stats['entries'] ?? 0); ?></td><td><?= e(format_currency((float) ($stats['amount'] ?? 0))); ?></td></tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Daily Cash Summary</h3><a href="<?= e(reports_export_url('modules/reports/payments.php', $pageParams, 'daily_cash')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Date</th><th>Cash In</th><th>Cash Out</th><th>Net Cash</th></tr></thead>
                <tbody>
                  <?php if (empty($dailyCash)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No cash entries available.</td></tr>
                  <?php else: ?>
                    <?php foreach ($dailyCash as $date => $stats): ?>
                      <tr>
                        <td><?= e((string) $date); ?></td>
                        <td><?= e(format_currency((float) ($stats['cash_in'] ?? 0))); ?></td>
                        <td><?= e(format_currency((float) ($stats['cash_out'] ?? 0))); ?></td>
                        <td><?= e(format_currency((float) ($stats['net_cash'] ?? 0))); ?></td>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
