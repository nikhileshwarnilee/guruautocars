<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';
require_once __DIR__ . '/../billing/workflow.php';

reports_require_access();
if (!(has_permission('billing.view') || has_permission('invoice.view') || has_permission('reports.financial') || has_permission('financial.reports'))) {
    flash_set('access_denied', 'You do not have permission to view advance collection report.', 'danger');
    redirect('modules/reports/index.php');
}

billing_financial_extensions_ready();

$page_title = 'Advance Collection Report';
$active_menu = 'reports.advance_collections';

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

$customerFilter = get_int('customer_id');
$jobFilter = get_int('job_card_id');
$receiptFilter = trim((string) ($_GET['receipt'] ?? ''));

$pageParams = array_merge($baseParams, [
    'customer_id' => $customerFilter > 0 ? $customerFilter : null,
    'job_card_id' => $jobFilter > 0 ? $jobFilter : null,
    'receipt' => $receiptFilter !== '' ? $receiptFilter : null,
]);

$baseWhere = [
    'ja.company_id = :company_id',
    'ja.received_on BETWEEN :from_date AND :to_date',
    'ja.status_code = "ACTIVE"',
];
$params = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
$garageScopeSql = analytics_garage_scope_sql('ja.garage_id', $selectedGarageId, $garageIds, $params, 'adv_scope');

if ($customerFilter > 0) {
    $baseWhere[] = 'ja.customer_id = :customer_id';
    $params['customer_id'] = $customerFilter;
}
if ($jobFilter > 0) {
    $baseWhere[] = 'ja.job_card_id = :job_card_id';
    $params['job_card_id'] = $jobFilter;
}
if ($receiptFilter !== '') {
    $baseWhere[] = 'ja.receipt_number LIKE :receipt_like';
    $params['receipt_like'] = '%' . $receiptFilter . '%';
}
$whereSql = implode(' AND ', $baseWhere);

$summaryStmt = db()->prepare(
    'SELECT COUNT(*) AS receipt_count,
            COALESCE(SUM(ja.advance_amount), 0) AS advance_amount,
            COALESCE(SUM(ja.adjusted_amount), 0) AS adjusted_amount,
            COALESCE(SUM(ja.balance_amount), 0) AS balance_amount
     FROM job_advances ja
     WHERE ' . $whereSql . '\n       ' . $garageScopeSql
);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch() ?: [
    'receipt_count' => 0,
    'advance_amount' => 0,
    'adjusted_amount' => 0,
    'balance_amount' => 0,
];

$rowsStmt = db()->prepare(
    'SELECT ja.id, ja.receipt_number, ja.received_on, ja.payment_mode, ja.reference_no, ja.notes,
            ja.advance_amount, ja.adjusted_amount, ja.balance_amount,
            c.full_name AS customer_name,
            jc.job_number,
            v.registration_no,
            g.name AS garage_name,
            u.name AS created_by_name
     FROM job_advances ja
     LEFT JOIN customers c ON c.id = ja.customer_id
     LEFT JOIN job_cards jc ON jc.id = ja.job_card_id
     LEFT JOIN vehicles v ON v.id = jc.vehicle_id
     LEFT JOIN garages g ON g.id = ja.garage_id
     LEFT JOIN users u ON u.id = ja.created_by
     WHERE ' . $whereSql . '\n       ' . $garageScopeSql . '
     ORDER BY ja.id DESC
     LIMIT 500'
);
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();

$customersStmt = db()->prepare(
    'SELECT DISTINCT c.id, c.full_name
     FROM job_advances ja
     INNER JOIN customers c ON c.id = ja.customer_id
     WHERE ja.company_id = :company_id
       AND ja.status_code = "ACTIVE"
     ORDER BY c.full_name ASC'
);
$customersStmt->execute(['company_id' => $companyId]);
$customers = $customersStmt->fetchAll();

$jobsStmt = db()->prepare(
    'SELECT DISTINCT jc.id, jc.job_number
     FROM job_advances ja
     INNER JOIN job_cards jc ON jc.id = ja.job_card_id
     WHERE ja.company_id = :company_id
       AND ja.status_code = "ACTIVE"
     ORDER BY jc.id DESC
     LIMIT 500'
);
$jobsStmt->execute(['company_id' => $companyId]);
$jobs = $jobsStmt->fetchAll();

$exportKey = trim((string) ($_GET['export'] ?? ''));
if ($exportKey !== '') {
    if (!$canExportData) {
        http_response_code(403);
        exit('Export access denied.');
    }
    $timestamp = date('Ymd_His');
    if ($exportKey === 'advance_ledger') {
        $csvRows = array_map(static fn (array $row): array => [
            (string) ($row['received_on'] ?? ''),
            (string) ($row['receipt_number'] ?? ''),
            (string) ($row['job_number'] ?? ''),
            (string) ($row['customer_name'] ?? ''),
            (string) ($row['registration_no'] ?? ''),
            (string) ($row['payment_mode'] ?? ''),
            (float) ($row['advance_amount'] ?? 0),
            (float) ($row['adjusted_amount'] ?? 0),
            (float) ($row['balance_amount'] ?? 0),
            (string) ($row['reference_no'] ?? ''),
            (string) ($row['notes'] ?? ''),
        ], $rows);
        reports_csv_download(
            'advance_collection_report_' . $timestamp . '.csv',
            ['Date', 'Receipt', 'Job Card', 'Customer', 'Vehicle', 'Mode', 'Advance', 'Adjusted', 'Balance', 'Reference', 'Notes'],
            $csvRows
        );
    }
    flash_set('report_error', 'Unknown export requested.', 'warning');
    redirect('modules/reports/advance_collections.php?' . http_build_query(reports_compact_query_params($pageParams)));
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Advance Collection Report</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Advance Collections</li>
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
          <a href="<?= e(url('modules/billing/index.php')); ?>" class="btn btn-sm btn-outline-secondary">Open Billing Module</a>
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
                <?php foreach ($financialYears as $fy): ?>
                  <option value="<?= (int) ($fy['id'] ?? 0); ?>" <?= ((int) ($fy['id'] ?? 0) === $selectedFyId) ? 'selected' : ''; ?>><?= e((string) ($fy['fy_label'] ?? '')); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Date Mode</label>
              <select name="date_mode" class="form-select">
                <?php foreach ($dateModeOptions as $modeValue => $modeLabel): ?>
                  <option value="<?= e((string) $modeValue); ?>" <?= $dateMode === $modeValue ? 'selected' : ''; ?>><?= e((string) $modeLabel); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" required></div>
            <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" required></div>
            <div class="col-md-3">
              <label class="form-label">Customer</label>
              <select name="customer_id" class="form-select" data-searchable-select="1">
                <option value="0">All Customers</option>
                <?php foreach ($customers as $customer): ?>
                  <option value="<?= (int) ($customer['id'] ?? 0); ?>" <?= $customerFilter === (int) ($customer['id'] ?? 0) ? 'selected' : ''; ?>><?= e((string) ($customer['full_name'] ?? '')); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Job Card</label>
              <select name="job_card_id" class="form-select" data-searchable-select="1">
                <option value="0">All Job Cards</option>
                <?php foreach ($jobs as $job): ?>
                  <option value="<?= (int) ($job['id'] ?? 0); ?>" <?= $jobFilter === (int) ($job['id'] ?? 0) ? 'selected' : ''; ?>><?= e((string) ($job['job_number'] ?? '')); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Receipt No</label>
              <input type="text" name="receipt" class="form-control" value="<?= e($receiptFilter); ?>" placeholder="ADV/...">
            </div>
            <div class="col-md-2 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Apply</button>
              <a href="<?= e(url('modules/reports/advance_collections.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="small-box text-bg-primary"><div class="inner"><h4><?= number_format((int) ($summary['receipt_count'] ?? 0)); ?></h4><p>Receipts</p></div><span class="small-box-icon"><i class="bi bi-receipt"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-success"><div class="inner"><h4><?= e(format_currency((float) ($summary['advance_amount'] ?? 0))); ?></h4><p>Advance Collected</p></div><span class="small-box-icon"><i class="bi bi-cash-stack"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-warning"><div class="inner"><h4><?= e(format_currency((float) ($summary['adjusted_amount'] ?? 0))); ?></h4><p>Adjusted</p></div><span class="small-box-icon"><i class="bi bi-arrow-left-right"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-secondary"><div class="inner"><h4><?= e(format_currency((float) ($summary['balance_amount'] ?? 0))); ?></h4><p>Balance</p></div><span class="small-box-icon"><i class="bi bi-hourglass-split"></i></span></div></div>
      </div>

      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Advance Ledger</h3>
          <a href="<?= e(reports_export_url('modules/reports/advance_collections.php', $pageParams, 'advance_ledger')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Date</th>
                <th>Receipt</th>
                <th>Garage</th>
                <th>Job Card</th>
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Mode</th>
                <th>Advance</th>
                <th>Adjusted</th>
                <th>Balance</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="11" class="text-center text-muted py-4">No advance records found for selected filters.</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $row): ?>
                  <tr>
                    <td><?= e((string) ($row['received_on'] ?? '-')); ?></td>
                    <td><code><?= e((string) ($row['receipt_number'] ?? '-')); ?></code></td>
                    <td><?= e((string) ($row['garage_name'] ?? '-')); ?></td>
                    <td><?= e((string) ($row['job_number'] ?? '-')); ?></td>
                    <td><?= e((string) ($row['customer_name'] ?? '-')); ?></td>
                    <td><?= e((string) ($row['registration_no'] ?? '-')); ?></td>
                    <td><?= e((string) ($row['payment_mode'] ?? '-')); ?></td>
                    <td><?= e(format_currency((float) ($row['advance_amount'] ?? 0))); ?></td>
                    <td><?= e(format_currency((float) ($row['adjusted_amount'] ?? 0))); ?></td>
                    <td><?= e(format_currency((float) ($row['balance_amount'] ?? 0))); ?></td>
                    <td><a href="<?= e(url('modules/billing/print_advance_receipt.php?id=' . (int) ($row['id'] ?? 0))); ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Print</a></td>
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
