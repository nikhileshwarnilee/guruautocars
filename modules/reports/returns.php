<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';
require_once __DIR__ . '/../returns/workflow.php';

reports_require_access();
if (!has_permission('inventory.view') && !has_permission('billing.view') && !has_permission('purchase.view') && !has_permission('report.view')) {
    flash_set('access_denied', 'You do not have permission to view returns report.', 'danger');
    redirect('modules/reports/index.php');
}
if (!returns_module_ready()) {
    flash_set('report_error', 'Returns module is not ready in this environment.', 'danger');
    redirect('modules/reports/index.php');
}

$page_title = 'Returns Report';
$active_menu = 'reports.returns';

$scope = reports_build_scope_context();
$companyId = (int) $scope['company_id'];
$garageIds = $scope['garage_ids'];
$garageOptions = $scope['garage_options'];
$allowAllGarages = (bool) $scope['allow_all_garages'];
$selectedGarageId = (int) $scope['selected_garage_id'];
$scopeGarageLabel = (string) $scope['scope_garage_label'];
$financialYears = $scope['financial_years'];
$selectedFyId = (int) $scope['selected_fy_id'];
$dateMode = (string) $scope['date_mode'];
$dateModeOptions = $scope['date_mode_options'];
$fromDate = (string) $scope['from_date'];
$toDate = (string) $scope['to_date'];
$canExportData = (bool) $scope['can_export_data'];
$baseParams = $scope['base_params'];

$returnTypeFilter = returns_normalize_type((string) ($_GET['return_type'] ?? ''));
if (!isset($_GET['return_type'])) {
    $returnTypeFilter = '';
}
$approvalFilter = returns_normalize_approval_status((string) ($_GET['approval_status'] ?? ''));
if (!isset($_GET['approval_status'])) {
    $approvalFilter = '';
}
$query = trim((string) ($_GET['q'] ?? ''));

$pageParams = array_merge($baseParams, [
    'return_type' => $returnTypeFilter !== '' ? $returnTypeFilter : null,
    'approval_status' => $approvalFilter !== '' ? $approvalFilter : null,
    'q' => $query !== '' ? $query : null,
]);

$where = [
    'r.company_id = :company_id',
    'r.return_date BETWEEN :from_date AND :to_date',
    'r.status_code = "ACTIVE"',
];
$params = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
$garageScopeSql = analytics_garage_scope_sql('r.garage_id', $selectedGarageId, $garageIds, $params, 'ret_report_scope');

if ($returnTypeFilter !== '') {
    $where[] = 'r.return_type = :return_type';
    $params['return_type'] = $returnTypeFilter;
}
if ($approvalFilter !== '') {
    $where[] = 'r.approval_status = :approval_status';
    $params['approval_status'] = $approvalFilter;
}
if ($query !== '') {
    $where[] = '(r.return_number LIKE :q OR i.invoice_number LIKE :q OR p.invoice_number LIKE :q OR c.full_name LIKE :q OR v.vendor_name LIKE :q)';
    $params['q'] = '%' . $query . '%';
}
$whereSql = implode(' AND ', $where);

$summaryStmt = db()->prepare(
    'SELECT COUNT(*) AS return_count,
            COALESCE(SUM(r.total_amount), 0) AS total_amount,
            COALESCE(SUM(CASE WHEN r.return_type = "CUSTOMER_RETURN" THEN r.total_amount ELSE 0 END), 0) AS customer_return_total,
            COALESCE(SUM(CASE WHEN r.return_type = "VENDOR_RETURN" THEN r.total_amount ELSE 0 END), 0) AS vendor_return_total
     FROM returns_rma r
     WHERE ' . $whereSql . '
       ' . $garageScopeSql
);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch() ?: [
    'return_count' => 0,
    'total_amount' => 0,
    'customer_return_total' => 0,
    'vendor_return_total' => 0,
];

$rowsStmt = db()->prepare(
    'SELECT r.id, r.return_number, r.return_date, r.return_type, r.approval_status,
            r.total_amount,
            i.invoice_number,
            p.invoice_number AS purchase_invoice_number,
            c.full_name AS customer_name,
            v.vendor_name,
            g.name AS garage_name
     FROM returns_rma r
     LEFT JOIN invoices i ON i.id = r.invoice_id
     LEFT JOIN purchases p ON p.id = r.purchase_id
     LEFT JOIN customers c ON c.id = r.customer_id
     LEFT JOIN vendors v ON v.id = r.vendor_id
     LEFT JOIN garages g ON g.id = r.garage_id
     WHERE ' . $whereSql . '
       ' . $garageScopeSql . '
     ORDER BY r.id DESC
     LIMIT 1000'
);
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();

$exportKey = trim((string) ($_GET['export'] ?? ''));
if ($exportKey !== '') {
    if (!$canExportData) {
        http_response_code(403);
        exit('Export access denied.');
    }

    if ($exportKey === 'returns_ledger') {
        $timestamp = date('Ymd_His');
        $csvRows = array_map(static fn (array $row): array => [
            (string) ($row['return_number'] ?? ''),
            (string) ($row['return_date'] ?? ''),
            (string) ($row['return_type'] ?? ''),
            (string) ($row['approval_status'] ?? ''),
            (string) (($row['invoice_number'] ?? '') !== '' ? $row['invoice_number'] : ((($row['purchase_invoice_number'] ?? '') !== '') ? $row['purchase_invoice_number'] : '')),
            (string) (($row['customer_name'] ?? '') !== '' ? $row['customer_name'] : ($row['vendor_name'] ?? '')),
            (float) ($row['total_amount'] ?? 0),
            (string) ($row['garage_name'] ?? ''),
        ], $rows);
        reports_csv_download(
            'returns_report_' . $timestamp . '.csv',
            ['Return No', 'Date', 'Type', 'Approval', 'Source Doc', 'Customer/Vendor', 'Amount', 'Garage'],
            $csvRows
        );
    }

    flash_set('report_error', 'Unknown export requested.', 'warning');
    redirect('modules/reports/returns.php?' . http_build_query(reports_compact_query_params($pageParams)));
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Returns Report</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Returns</li>
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
                    <option value="<?= (int) ($garage['id'] ?? 0); ?>" <?= ((int) ($garage['id'] ?? 0) === $selectedGarageId) ? 'selected' : ''; ?>><?= e((string) ($garage['name'] ?? '')); ?> (<?= e((string) ($garage['code'] ?? '')); ?>)</option>
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
            <div class="col-md-2">
              <label class="form-label">Type</label>
              <select name="return_type" class="form-select">
                <option value="">All</option>
                <?php foreach (returns_allowed_types() as $typeKey => $typeLabel): ?>
                  <option value="<?= e($typeKey); ?>" <?= $returnTypeFilter === $typeKey ? 'selected' : ''; ?>><?= e($typeLabel); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Approval</label>
              <select name="approval_status" class="form-select">
                <option value="">All</option>
                <?php foreach (returns_allowed_approval_statuses() as $status): ?>
                  <option value="<?= e($status); ?>" <?= $approvalFilter === $status ? 'selected' : ''; ?>><?= e($status); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label">Search</label><input type="text" name="q" class="form-control" value="<?= e($query); ?>" placeholder="Return/Source/Customer/Vendor"></div>
            <div class="col-md-2 d-flex gap-2"><button type="submit" class="btn btn-primary">Apply</button><a href="<?= e(url('modules/reports/returns.php')); ?>" class="btn btn-outline-secondary">Reset</a></div>
          </form>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="small-box text-bg-primary"><div class="inner"><h4><?= number_format((int) ($summary['return_count'] ?? 0)); ?></h4><p>Total Returns</p></div><span class="small-box-icon"><i class="bi bi-arrow-counterclockwise"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-info"><div class="inner"><h4><?= e(format_currency((float) ($summary['total_amount'] ?? 0))); ?></h4><p>Total Amount</p></div><span class="small-box-icon"><i class="bi bi-currency-rupee"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-success"><div class="inner"><h4><?= e(format_currency((float) ($summary['customer_return_total'] ?? 0))); ?></h4><p>Customer Returns</p></div><span class="small-box-icon"><i class="bi bi-person-check"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-warning"><div class="inner"><h4><?= e(format_currency((float) ($summary['vendor_return_total'] ?? 0))); ?></h4><p>Vendor Returns</p></div><span class="small-box-icon"><i class="bi bi-truck"></i></span></div></div>
      </div>

      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Returns Ledger</h3>
          <a href="<?= e(reports_export_url('modules/reports/returns.php', $pageParams, 'returns_ledger')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Return No</th>
                <th>Date</th>
                <th>Type</th>
                <th>Approval</th>
                <th>Source</th>
                <th>Counterparty</th>
                <th>Garage</th>
                <th>Amount</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($rows === []): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No returns found in selected scope.</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $row): ?>
                  <?php $returnId = (int) ($row['id'] ?? 0); ?>
                  <tr>
                    <td><a href="<?= e(url('modules/returns/index.php?view_id=' . $returnId)); ?>"><?= e((string) ($row['return_number'] ?? '')); ?></a></td>
                    <td><?= e((string) ($row['return_date'] ?? '')); ?></td>
                    <td><?= e(str_replace('_', ' ', (string) ($row['return_type'] ?? ''))); ?></td>
                    <td><?= e((string) ($row['approval_status'] ?? '')); ?></td>
                    <td><?= e((string) (($row['invoice_number'] ?? '') !== '' ? $row['invoice_number'] : ((($row['purchase_invoice_number'] ?? '') !== '') ? $row['purchase_invoice_number'] : '-'))); ?></td>
                    <td><?= e((string) (($row['customer_name'] ?? '') !== '' ? $row['customer_name'] : ($row['vendor_name'] ?? '-'))); ?></td>
                    <td><?= e((string) ($row['garage_name'] ?? '-')); ?></td>
                    <td><?= e(format_currency((float) ($row['total_amount'] ?? 0))); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
