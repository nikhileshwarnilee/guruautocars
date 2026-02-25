<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';

reports_require_access();
if (!(has_permission('purchase.view') || has_permission('purchase.manage'))) {
    flash_set('access_denied', 'You do not have permission to view purchase reports.', 'danger');
    redirect('modules/reports/index.php');
}
if (table_columns('purchases') === [] || table_columns('purchase_items') === []) {
    flash_set('report_error', 'Purchase report tables are missing. Run database/purchase_module_upgrade.sql.', 'danger');
    redirect('modules/reports/index.php');
}

$page_title = 'Purchase Reports';
$active_menu = 'reports.purchases';

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

$purchasePaymentsReady = table_columns('purchase_payments') !== [];
$purchaseSupportsSoftDelete = in_array('status_code', table_columns('purchases'), true);
$canViewVendorPayables = has_permission('vendor.payments') || has_permission('purchase.manage');

$vendorsStmt = db()->prepare(
    'SELECT id, vendor_name
     FROM vendors
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY vendor_name ASC'
);
$vendorsStmt->execute(['company_id' => $companyId]);
$vendors = $vendorsStmt->fetchAll();

$vendorFilter = get_int('vendor_id', 0);
$paymentFilter = strtoupper(trim((string) ($_GET['payment_status'] ?? '')));
$purchaseStatusFilter = strtoupper(trim((string) ($_GET['purchase_status'] ?? '')));
$invoiceFilter = trim((string) ($_GET['invoice'] ?? ''));

$allowedPaymentStatuses = ['UNPAID', 'PARTIAL', 'PAID'];
$allowedPurchaseStatuses = ['DRAFT', 'FINALIZED'];
if (!in_array($paymentFilter, $allowedPaymentStatuses, true)) {
    $paymentFilter = '';
}
if (!in_array($purchaseStatusFilter, $allowedPurchaseStatuses, true)) {
    $purchaseStatusFilter = '';
}

$pageParams = array_merge($baseParams, [
    'invoice' => $invoiceFilter !== '' ? $invoiceFilter : null,
    'vendor_id' => $vendorFilter > 0 ? $vendorFilter : null,
    'payment_status' => $paymentFilter !== '' ? $paymentFilter : null,
    'purchase_status' => $purchaseStatusFilter !== '' ? $purchaseStatusFilter : null,
]);

$purchaseParams = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
$purchaseScopeSql = analytics_garage_scope_sql('p.garage_id', $selectedGarageId, $garageIds, $purchaseParams, 'purchase_report_scope');
$purchaseWhere = ['p.company_id = :company_id', 'p.purchase_date BETWEEN :from_date AND :to_date'];
if ($purchaseSupportsSoftDelete) {
    $purchaseWhere[] = 'p.status_code <> "DELETED"';
}
if ($vendorFilter > 0) {
    $purchaseWhere[] = 'p.vendor_id = :vendor_id';
    $purchaseParams['vendor_id'] = $vendorFilter;
}
if ($paymentFilter !== '') {
    $purchaseWhere[] = 'p.payment_status = :payment_status';
    $purchaseParams['payment_status'] = $paymentFilter;
}
if ($purchaseStatusFilter !== '') {
    $purchaseWhere[] = 'p.purchase_status = :purchase_status';
    $purchaseParams['purchase_status'] = $purchaseStatusFilter;
}
if ($invoiceFilter !== '') {
    $purchaseWhere[] = 'p.invoice_number LIKE :invoice_like';
    $purchaseParams['invoice_like'] = '%' . $invoiceFilter . '%';
}
$purchaseWhereSql = implode(' AND ', $purchaseWhere);

$summaryStmt = db()->prepare(
    'SELECT COUNT(*) AS purchase_count,
            COALESCE(SUM(CASE WHEN p.assignment_status = "UNASSIGNED" THEN 1 ELSE 0 END), 0) AS unassigned_count,
            COALESCE(SUM(CASE WHEN p.purchase_status = "FINALIZED" THEN 1 ELSE 0 END), 0) AS finalized_count,
            COALESCE(SUM(p.taxable_amount), 0) AS taxable_total,
            COALESCE(SUM(p.gst_amount), 0) AS gst_total,
            COALESCE(SUM(p.grand_total), 0) AS grand_total
     FROM purchases p
     WHERE ' . $purchaseWhereSql . '
       ' . $purchaseScopeSql
);
$summaryStmt->execute($purchaseParams);
$summary = $summaryStmt->fetch() ?: [
    'purchase_count' => 0,
    'unassigned_count' => 0,
    'finalized_count' => 0,
    'taxable_total' => 0,
    'gst_total' => 0,
    'grand_total' => 0,
];

$registerStmt = db()->prepare(
    'SELECT p.id, p.purchase_date, p.purchase_source, p.assignment_status, p.purchase_status, p.payment_status,
            p.taxable_amount, p.gst_amount, p.grand_total, p.invoice_number,
            COALESCE(v.vendor_name, "UNASSIGNED") AS vendor_name,
            COALESCE(items.item_count, 0) AS item_count,
            COALESCE(items.total_qty, 0) AS total_qty
     FROM purchases p
     LEFT JOIN vendors v ON v.id = p.vendor_id
     LEFT JOIN (
        SELECT purchase_id, COUNT(*) AS item_count, COALESCE(SUM(quantity), 0) AS total_qty
        FROM purchase_items
        GROUP BY purchase_id
     ) items ON items.purchase_id = p.id
     WHERE ' . $purchaseWhereSql . '
       ' . $purchaseScopeSql . '
     ORDER BY p.id DESC
     LIMIT 250'
);
$registerStmt->execute($purchaseParams);
$purchaseRows = $registerStmt->fetchAll();

$topPartsStmt = db()->prepare(
    'SELECT pt.part_name, pt.part_sku,
            COALESCE(SUM(pi.quantity), 0) AS total_qty,
            COALESCE(SUM(pi.total_amount), 0) AS total_amount
     FROM purchases p
     INNER JOIN purchase_items pi ON pi.purchase_id = p.id
     INNER JOIN parts pt ON pt.id = pi.part_id
     WHERE ' . $purchaseWhereSql . '
       ' . $purchaseScopeSql . '
     GROUP BY pt.id, pt.part_name, pt.part_sku
     ORDER BY total_qty DESC
     LIMIT 15'
);
$topPartsStmt->execute($purchaseParams);
$topPartsRows = $topPartsStmt->fetchAll();

$monthlyTrendStmt = db()->prepare(
    'SELECT DATE_FORMAT(p.purchase_date, "%Y-%m") AS purchase_month,
            COUNT(*) AS purchase_count,
            COALESCE(SUM(p.grand_total), 0) AS grand_total
     FROM purchases p
     WHERE ' . $purchaseWhereSql . '
       ' . $purchaseScopeSql . '
     GROUP BY DATE_FORMAT(p.purchase_date, "%Y-%m")
     ORDER BY purchase_month ASC'
);
$monthlyTrendStmt->execute($purchaseParams);
$monthlyTrendRows = $monthlyTrendStmt->fetchAll();

$vendorOutstandingRows = [];
$agingSummary = [
    'bucket_0_30' => 0,
    'bucket_31_60' => 0,
    'bucket_61_90' => 0,
    'bucket_90_plus' => 0,
    'outstanding_total' => 0,
];
if ($purchasePaymentsReady && $canViewVendorPayables) {
    $payableParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $payableScopeSql = analytics_garage_scope_sql('p.garage_id', $selectedGarageId, $garageIds, $payableParams, 'purchase_payable_scope');
    $payableWhere = [
        'p.company_id = :company_id',
        'p.purchase_status = "FINALIZED"',
        'p.assignment_status = "ASSIGNED"',
        'p.purchase_date BETWEEN :from_date AND :to_date',
    ];
    if ($purchaseSupportsSoftDelete) {
        $payableWhere[] = 'p.status_code <> "DELETED"';
    }
    if ($vendorFilter > 0) {
        $payableWhere[] = 'p.vendor_id = :vendor_id';
        $payableParams['vendor_id'] = $vendorFilter;
    }
    $payableWhereSql = implode(' AND ', $payableWhere);

    $vendorOutstandingStmt = db()->prepare(
        'SELECT COALESCE(v.vendor_name, "UNASSIGNED") AS vendor_name,
                COUNT(p.id) AS purchase_count,
                COALESCE(SUM(p.grand_total), 0) AS grand_total,
                COALESCE(SUM(COALESCE(pay.total_paid, 0)), 0) AS paid_total,
                COALESCE(SUM(GREATEST(p.grand_total - COALESCE(pay.total_paid, 0), 0)), 0) AS outstanding_total
         FROM purchases p
         LEFT JOIN vendors v ON v.id = p.vendor_id
         LEFT JOIN (
            SELECT purchase_id, SUM(amount) AS total_paid
            FROM purchase_payments
            GROUP BY purchase_id
         ) pay ON pay.purchase_id = p.id
         WHERE ' . $payableWhereSql . '
           ' . $payableScopeSql . '
         GROUP BY v.id, vendor_name
         HAVING outstanding_total > 0.01
         ORDER BY outstanding_total DESC'
    );
    $vendorOutstandingStmt->execute($payableParams);
    $vendorOutstandingRows = $vendorOutstandingStmt->fetchAll();

    $agingStmt = db()->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN age_days BETWEEN 0 AND 30 THEN outstanding ELSE 0 END), 0) AS bucket_0_30,
            COALESCE(SUM(CASE WHEN age_days BETWEEN 31 AND 60 THEN outstanding ELSE 0 END), 0) AS bucket_31_60,
            COALESCE(SUM(CASE WHEN age_days BETWEEN 61 AND 90 THEN outstanding ELSE 0 END), 0) AS bucket_61_90,
            COALESCE(SUM(CASE WHEN age_days > 90 THEN outstanding ELSE 0 END), 0) AS bucket_90_plus,
            COALESCE(SUM(outstanding), 0) AS outstanding_total
         FROM (
            SELECT DATEDIFF(CURDATE(), p.purchase_date) AS age_days,
                   GREATEST(p.grand_total - COALESCE(pay.total_paid, 0), 0) AS outstanding
            FROM purchases p
            LEFT JOIN (
                SELECT purchase_id, SUM(amount) AS total_paid
                FROM purchase_payments
                GROUP BY purchase_id
            ) pay ON pay.purchase_id = p.id
            WHERE ' . $payableWhereSql . '
              ' . $payableScopeSql . '
         ) aged
         WHERE outstanding > 0.01'
    );
    $agingStmt->execute($payableParams);
    $agingSummary = $agingStmt->fetch() ?: $agingSummary;
}

$exportKey = trim((string) ($_GET['export'] ?? ''));
if ($exportKey !== '') {
    if (!$canExportData) {
        http_response_code(403);
        exit('Export access denied.');
    }
    $timestamp = date('Ymd_His');
    switch ($exportKey) {
        case 'purchase_register':
            $rows = array_map(static fn (array $row): array => [
                (int) ($row['id'] ?? 0),
                (string) ($row['purchase_date'] ?? ''),
                (string) ($row['vendor_name'] ?? ''),
                (string) ($row['invoice_number'] ?? ''),
                (string) ($row['purchase_source'] ?? ''),
                (string) ($row['assignment_status'] ?? ''),
                (string) ($row['purchase_status'] ?? ''),
                (string) ($row['payment_status'] ?? ''),
                (int) ($row['item_count'] ?? 0),
                (float) ($row['total_qty'] ?? 0),
                (float) ($row['taxable_amount'] ?? 0),
                (float) ($row['gst_amount'] ?? 0),
                (float) ($row['grand_total'] ?? 0),
            ], $purchaseRows);
            reports_csv_download('purchase_register_' . $timestamp . '.csv', ['ID', 'Date', 'Vendor', 'Invoice', 'Source', 'Assignment', 'Status', 'Payment', 'Items', 'Qty', 'Taxable', 'GST', 'Grand'], $rows);
        case 'monthly_trend':
            $rows = array_map(static fn (array $row): array => [
                (string) ($row['purchase_month'] ?? ''),
                (int) ($row['purchase_count'] ?? 0),
                (float) ($row['grand_total'] ?? 0),
            ], $monthlyTrendRows);
            reports_csv_download('purchase_monthly_trend_' . $timestamp . '.csv', ['Month', 'Purchases', 'Grand Total'], $rows);
        case 'top_parts':
            $rows = array_map(static fn (array $row): array => [
                (string) ($row['part_name'] ?? ''),
                (string) ($row['part_sku'] ?? ''),
                (float) ($row['total_qty'] ?? 0),
                (float) ($row['total_amount'] ?? 0),
            ], $topPartsRows);
            reports_csv_download('purchase_top_parts_' . $timestamp . '.csv', ['Part', 'SKU', 'Qty', 'Value'], $rows);
        case 'vendor_outstanding':
            if (!$purchasePaymentsReady || !$canViewVendorPayables) {
                flash_set('report_error', 'Vendor outstanding export is not available.', 'warning');
                redirect('modules/reports/purchases.php?' . http_build_query(reports_compact_query_params($pageParams)));
            }
            $rows = array_map(static fn (array $row): array => [
                (string) ($row['vendor_name'] ?? ''),
                (int) ($row['purchase_count'] ?? 0),
                (float) ($row['grand_total'] ?? 0),
                (float) ($row['paid_total'] ?? 0),
                (float) ($row['outstanding_total'] ?? 0),
            ], $vendorOutstandingRows);
            reports_csv_download('purchase_vendor_outstanding_' . $timestamp . '.csv', ['Vendor', 'Purchases', 'Grand', 'Paid', 'Outstanding'], $rows);
        default:
            flash_set('report_error', 'Unknown export requested.', 'warning');
            redirect('modules/reports/purchases.php?' . http_build_query(reports_compact_query_params($pageParams)));
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Purchase Reports</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Purchases</li>
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
          <a href="<?= e(url('modules/purchases/index.php')); ?>" class="btn btn-sm btn-outline-secondary">Open Purchase Entry & List</a>
        </div>
      </div>

      <div class="card card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Filters</h3></div>
        <div class="card-body">
          <form method="get" class="row g-2 align-items-end" data-date-filter-form="1" data-date-range-start="<?= e((string) $scope['date_range_start']); ?>" data-date-range-end="<?= e((string) $scope['date_range_end']); ?>" data-date-yearly-start="<?= e((string) $scope['fy_start']); ?>">
            <?php if ($allowAllGarages || count($garageIds) > 1): ?>
              <div class="col-md-3">
                <label class="form-label">Garage Scope</label>
                <select name="garage_id" class="form-select">
                  <?php if ($allowAllGarages): ?><option value="0" <?= $selectedGarageId === 0 ? 'selected' : ''; ?>>All Accessible Garages</option><?php endif; ?>
                  <?php foreach ($garageOptions as $garage): ?>
                    <option value="<?= (int) $garage['id']; ?>" <?= ((int) $garage['id'] === $selectedGarageId) ? 'selected' : ''; ?>><?= e((string) $garage['name']); ?> (<?= e((string) $garage['code']); ?>)</option>
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
            <div class="col-md-3"><label class="form-label">Financial Year</label><select name="fy_id" class="form-select"><?php if (empty($financialYears)): ?><option value="0" selected><?= e($fyLabel); ?></option><?php else: ?><?php foreach ($financialYears as $fy): ?><option value="<?= (int) $fy['id']; ?>" <?= ((int) $fy['id'] === $selectedFyId) ? 'selected' : ''; ?>><?= e((string) $fy['fy_label']); ?></option><?php endforeach; ?><?php endif; ?></select></div>
            <div class="col-md-2"><label class="form-label">Date Mode</label><select name="date_mode" class="form-select"><?php foreach ($dateModeOptions as $modeValue => $modeLabel): ?><option value="<?= e((string) $modeValue); ?>" <?= $dateMode === $modeValue ? 'selected' : ''; ?>><?= e((string) $modeLabel); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" required /></div>
            <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" required /></div>
            <div class="col-md-2"><label class="form-label">Invoice</label><input type="text" name="invoice" class="form-control" value="<?= e($invoiceFilter); ?>" maxlength="80"></div>
            <div class="col-md-2"><label class="form-label">Vendor</label><select name="vendor_id" class="form-select"><option value="0">All Vendors</option><?php foreach ($vendors as $vendor): ?><option value="<?= (int) $vendor['id']; ?>" <?= $vendorFilter === (int) $vendor['id'] ? 'selected' : ''; ?>><?= e((string) $vendor['vendor_name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Payment</label><select name="payment_status" class="form-select"><option value="">All</option><?php foreach ($allowedPaymentStatuses as $status): ?><option value="<?= e($status); ?>" <?= $paymentFilter === $status ? 'selected' : ''; ?>><?= e($status); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Purchase Status</label><select name="purchase_status" class="form-select"><option value="">All</option><?php foreach ($allowedPurchaseStatuses as $status): ?><option value="<?= e($status); ?>" <?= $purchaseStatusFilter === $status ? 'selected' : ''; ?>><?= e($status); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 d-flex gap-2"><button type="submit" class="btn btn-primary">Apply</button><a href="<?= e(url('modules/reports/purchases.php')); ?>" class="btn btn-outline-secondary">Reset</a></div>
          </form>
        </div>
        <?php if ($canExportData): ?><div class="card-footer d-flex flex-wrap gap-2"><a href="<?= e(reports_export_url('modules/reports/purchases.php', $pageParams, 'purchase_register')); ?>" class="btn btn-sm btn-outline-success">Export Register</a><a href="<?= e(reports_export_url('modules/reports/purchases.php', $pageParams, 'monthly_trend')); ?>" class="btn btn-sm btn-outline-secondary">Monthly Trend CSV</a><a href="<?= e(reports_export_url('modules/reports/purchases.php', $pageParams, 'top_parts')); ?>" class="btn btn-sm btn-outline-secondary">Top Parts CSV</a></div><?php endif; ?>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-2"><div class="small-box text-bg-primary mb-0"><div class="inner"><h4><?= e(number_format((int) ($summary['purchase_count'] ?? 0))); ?></h4><p>Purchases</p></div><span class="small-box-icon"><i class="bi bi-receipt"></i></span></div></div>
        <div class="col-md-2"><div class="small-box text-bg-warning mb-0"><div class="inner"><h4><?= e(number_format((int) ($summary['unassigned_count'] ?? 0))); ?></h4><p>Unassigned</p></div><span class="small-box-icon"><i class="bi bi-exclamation-triangle"></i></span></div></div>
        <div class="col-md-2"><div class="small-box text-bg-success mb-0"><div class="inner"><h4><?= e(number_format((int) ($summary['finalized_count'] ?? 0))); ?></h4><p>Finalized</p></div><span class="small-box-icon"><i class="bi bi-check-circle"></i></span></div></div>
        <div class="col-md-2"><div class="small-box text-bg-secondary mb-0"><div class="inner"><h4><?= e(format_currency((float) ($summary['taxable_total'] ?? 0))); ?></h4><p>Taxable</p></div><span class="small-box-icon"><i class="bi bi-cash-stack"></i></span></div></div>
        <div class="col-md-2"><div class="small-box text-bg-info mb-0"><div class="inner"><h4><?= e(format_currency((float) ($summary['gst_total'] ?? 0))); ?></h4><p>GST</p></div><span class="small-box-icon"><i class="bi bi-percent"></i></span></div></div>
        <div class="col-md-2"><div class="small-box text-bg-dark mb-0"><div class="inner"><h4><?= e(format_currency((float) ($summary['grand_total'] ?? 0))); ?></h4><p>Grand Total</p></div><span class="small-box-icon"><i class="bi bi-currency-rupee"></i></span></div></div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card mb-0">
            <div class="card-header"><h3 class="card-title mb-0">Monthly Purchase Trend</h3></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Month</th><th>Purchases</th><th>Grand Total</th></tr></thead>
                <tbody>
                  <?php if (empty($monthlyTrendRows)): ?><tr><td colspan="3" class="text-center text-muted py-4">No monthly data.</td></tr><?php else: ?>
                    <?php foreach ($monthlyTrendRows as $row): ?><tr><td><?= e((string) ($row['purchase_month'] ?? '')); ?></td><td><?= (int) ($row['purchase_count'] ?? 0); ?></td><td><strong><?= e(format_currency((float) ($row['grand_total'] ?? 0))); ?></strong></td></tr><?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card mb-0">
            <div class="card-header"><h3 class="card-title mb-0">Top Purchased Parts</h3></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Part</th><th>SKU</th><th>Qty</th><th>Value</th></tr></thead>
                <tbody>
                  <?php if (empty($topPartsRows)): ?><tr><td colspan="4" class="text-center text-muted py-4">No part trends.</td></tr><?php else: ?>
                    <?php foreach ($topPartsRows as $part): ?><tr><td><?= e((string) ($part['part_name'] ?? '')); ?></td><td><code><?= e((string) ($part['part_sku'] ?? '')); ?></code></td><td><?= e(number_format((float) ($part['total_qty'] ?? 0), 2)); ?></td><td><strong><?= e(format_currency((float) ($part['total_amount'] ?? 0))); ?></strong></td></tr><?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <?php if ($purchasePaymentsReady && $canViewVendorPayables): ?>
        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Vendor Outstanding Summary</h3><?php if ($canExportData): ?><a href="<?= e(reports_export_url('modules/reports/purchases.php', $pageParams, 'vendor_outstanding')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a><?php endif; ?></div>
          <div class="card-body table-responsive p-0">
            <table class="table table-sm table-striped mb-0">
              <thead><tr><th>Vendor</th><th>Purchases</th><th>Total</th><th>Paid</th><th>Outstanding</th></tr></thead>
              <tbody>
                <?php if (empty($vendorOutstandingRows)): ?><tr><td colspan="5" class="text-center text-muted py-4">No outstanding vendors.</td></tr><?php else: ?>
                  <?php foreach ($vendorOutstandingRows as $row): ?><tr><td><?= e((string) ($row['vendor_name'] ?? '')); ?></td><td><?= (int) ($row['purchase_count'] ?? 0); ?></td><td><?= e(format_currency((float) ($row['grand_total'] ?? 0))); ?></td><td><?= e(format_currency((float) ($row['paid_total'] ?? 0))); ?></td><td><strong><?= e(format_currency((float) ($row['outstanding_total'] ?? 0))); ?></strong></td></tr><?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="card mb-3"><div class="card-header"><h3 class="card-title">Aging Summary</h3></div><div class="card-body"><div class="row g-2"><div class="col-md-3"><strong>0-30:</strong> <?= e(format_currency((float) ($agingSummary['bucket_0_30'] ?? 0))); ?></div><div class="col-md-3"><strong>31-60:</strong> <?= e(format_currency((float) ($agingSummary['bucket_31_60'] ?? 0))); ?></div><div class="col-md-3"><strong>61-90:</strong> <?= e(format_currency((float) ($agingSummary['bucket_61_90'] ?? 0))); ?></div><div class="col-md-3"><strong>90+:</strong> <?= e(format_currency((float) ($agingSummary['bucket_90_plus'] ?? 0))); ?></div><div class="col-12"><strong>Total Outstanding:</strong> <?= e(format_currency((float) ($agingSummary['outstanding_total'] ?? 0))); ?></div></div></div></div>
      <?php endif; ?>

      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Purchase Register</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead><tr><th>ID</th><th>Date</th><th>Vendor</th><th>Invoice</th><th>Source</th><th>Assignment</th><th>Status</th><th>Payment</th><th>Items</th><th>Qty</th><th>Taxable</th><th>GST</th><th>Grand</th></tr></thead>
            <tbody>
              <?php if (empty($purchaseRows)): ?><tr><td colspan="13" class="text-center text-muted py-4">No purchases found.</td></tr><?php else: ?>
                <?php foreach ($purchaseRows as $row): ?><tr><td><code>#<?= (int) ($row['id'] ?? 0); ?></code></td><td><?= e((string) ($row['purchase_date'] ?? '-')); ?></td><td><?= e((string) ($row['vendor_name'] ?? 'UNASSIGNED')); ?></td><td><?= e((string) (($row['invoice_number'] ?? '') !== '' ? $row['invoice_number'] : '-')); ?></td><td><?= e((string) ($row['purchase_source'] ?? '-')); ?></td><td><?= e((string) ($row['assignment_status'] ?? '-')); ?></td><td><?= e((string) ($row['purchase_status'] ?? '-')); ?></td><td><?= e((string) ($row['payment_status'] ?? '-')); ?></td><td><?= (int) ($row['item_count'] ?? 0); ?></td><td><?= e(number_format((float) ($row['total_qty'] ?? 0), 2)); ?></td><td><?= e(format_currency((float) ($row['taxable_amount'] ?? 0))); ?></td><td><?= e(format_currency((float) ($row['gst_amount'] ?? 0))); ?></td><td><strong><?= e(format_currency((float) ($row['grand_total'] ?? 0))); ?></strong></td></tr><?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
