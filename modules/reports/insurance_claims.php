<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';
require_once __DIR__ . '/../jobs/insurance.php';

reports_require_access();
if (!has_permission('job.view') && !has_permission('job.manage') && !has_permission('reports.financial')) {
    flash_set('access_denied', 'You do not have permission to view insurance claim reports.', 'danger');
    redirect('modules/reports/index.php');
}

$page_title = 'Insurance Claim Reports';
$active_menu = 'reports.insurance_claims';

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

if (!job_insurance_feature_ready()) {
    flash_set('report_warning', 'Insurance claim fields are not ready in this database. Run the safe upgrade and refresh.', 'warning');
}

$claimStatusFilter = trim((string) ($_GET['claim_status'] ?? ''));
if ($claimStatusFilter !== '') {
    $claimStatusFilter = job_insurance_normalize_status($claimStatusFilter);
}
$insuranceCompanyFilter = trim((string) ($_GET['insurance_company'] ?? ''));
$claimNoFilter = trim((string) ($_GET['claim_no'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));

$pageParams = array_merge($baseParams, [
    'claim_status' => $claimStatusFilter !== '' ? $claimStatusFilter : null,
    'insurance_company' => $insuranceCompanyFilter !== '' ? $insuranceCompanyFilter : null,
    'claim_no' => $claimNoFilter !== '' ? $claimNoFilter : null,
    'q' => $search !== '' ? $search : null,
]);

$where = [
    'jc.company_id = :company_id',
    'DATE(COALESCE(jc.updated_at, jc.opened_at, jc.created_at)) BETWEEN :from_date AND :to_date',
    '(COALESCE(jc.insurance_company_name, "") <> "" OR COALESCE(jc.insurance_claim_number, "") <> "")',
    'jc.status_code <> "DELETED"',
];
$params = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
$garageScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $params, 'ins_report_scope');

if ($claimStatusFilter !== '') {
    $where[] = 'jc.insurance_claim_status = :insurance_claim_status';
    $params['insurance_claim_status'] = $claimStatusFilter;
}
if ($insuranceCompanyFilter !== '') {
    $where[] = 'jc.insurance_company_name = :insurance_company_name';
    $params['insurance_company_name'] = $insuranceCompanyFilter;
}
if ($claimNoFilter !== '') {
    $where[] = 'jc.insurance_claim_number LIKE :claim_no';
    $params['claim_no'] = '%' . $claimNoFilter . '%';
}
if ($search !== '') {
    $where[] = '(jc.job_number LIKE :q OR c.full_name LIKE :q OR v.registration_no LIKE :q OR jc.insurance_claim_number LIKE :q OR jc.insurance_surveyor_name LIKE :q)';
    $params['q'] = '%' . $search . '%';
}

$whereSql = implode(' AND ', $where);

$claimsStmt = db()->prepare(
    'SELECT jc.id, jc.job_number, jc.opened_at, jc.updated_at,
            jc.insurance_company_name, jc.insurance_claim_number, jc.insurance_surveyor_name,
            jc.insurance_claim_amount_approved, jc.insurance_customer_payable_amount, jc.insurance_claim_status,
            c.full_name AS customer_name,
            v.registration_no,
            g.name AS garage_name
     FROM job_cards jc
     INNER JOIN customers c ON c.id = jc.customer_id
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     INNER JOIN garages g ON g.id = jc.garage_id
     WHERE ' . $whereSql . '
       ' . $garageScopeSql . '
     ORDER BY jc.id DESC
     LIMIT 1000'
);
$claimsStmt->execute($params);
$claimRows = $claimsStmt->fetchAll();

$statusSummary = [
    'PENDING' => ['count' => 0, 'approved_amount' => 0.0, 'customer_payable' => 0.0],
    'APPROVED' => ['count' => 0, 'approved_amount' => 0.0, 'customer_payable' => 0.0],
    'REJECTED' => ['count' => 0, 'approved_amount' => 0.0, 'customer_payable' => 0.0],
    'SETTLED' => ['count' => 0, 'approved_amount' => 0.0, 'customer_payable' => 0.0],
];
$insuranceWise = [];

foreach ($claimRows as $row) {
    $status = job_insurance_normalize_status((string) ($row['insurance_claim_status'] ?? 'PENDING'));
    $approvedAmount = (float) ($row['insurance_claim_amount_approved'] ?? 0);
    $customerPayable = (float) ($row['insurance_customer_payable_amount'] ?? 0);
    $companyName = trim((string) ($row['insurance_company_name'] ?? ''));
    if ($companyName === '') {
        $companyName = 'Unspecified';
    }

    $statusSummary[$status]['count']++;
    $statusSummary[$status]['approved_amount'] += $approvedAmount;
    $statusSummary[$status]['customer_payable'] += $customerPayable;

    if (!isset($insuranceWise[$companyName])) {
        $insuranceWise[$companyName] = [
            'company_name' => $companyName,
            'total_claims' => 0,
            'pending_count' => 0,
            'approved_count' => 0,
            'rejected_count' => 0,
            'settled_count' => 0,
            'approved_amount' => 0.0,
            'customer_payable' => 0.0,
        ];
    }
    $insuranceWise[$companyName]['total_claims']++;
    $insuranceWise[$companyName]['approved_amount'] += $approvedAmount;
    $insuranceWise[$companyName]['customer_payable'] += $customerPayable;
    $insuranceWise[$companyName][strtolower($status) . '_count']++;
}

usort($claimRows, static function (array $a, array $b): int {
    return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
});
$insuranceWiseRows = array_values($insuranceWise);
usort($insuranceWiseRows, static function (array $a, array $b): int {
    return ((int) ($b['total_claims'] ?? 0)) <=> ((int) ($a['total_claims'] ?? 0));
});

$summaryTotals = [
    'total_claims' => count($claimRows),
    'pending_count' => (int) ($statusSummary['PENDING']['count'] ?? 0),
    'settled_count' => (int) ($statusSummary['SETTLED']['count'] ?? 0),
    'approved_amount' => round(array_reduce($claimRows, static fn (float $sum, array $row): float => $sum + (float) ($row['insurance_claim_amount_approved'] ?? 0), 0.0), 2),
    'customer_payable' => round(array_reduce($claimRows, static fn (float $sum, array $row): float => $sum + (float) ($row['insurance_customer_payable_amount'] ?? 0), 0.0), 2),
];

$insuranceCompanyOptionsStmt = db()->prepare(
    'SELECT DISTINCT insurance_company_name
     FROM job_cards jc
     WHERE jc.company_id = :company_id
       AND COALESCE(jc.insurance_company_name, "") <> ""
       AND jc.status_code <> "DELETED"
     ORDER BY insurance_company_name ASC'
);
$insuranceCompanyOptionsStmt->execute(['company_id' => $companyId]);
$insuranceCompanyOptions = array_values(array_filter(array_map(
    static fn (array $row): string => trim((string) ($row['insurance_company_name'] ?? '')),
    $insuranceCompanyOptionsStmt->fetchAll()
), static fn (string $value): bool => $value !== ''));

$exportKey = trim((string) ($_GET['export'] ?? ''));
if ($exportKey !== '') {
    if (!$canExportData) {
        http_response_code(403);
        exit('Export access denied.');
    }

    $timestamp = date('Ymd_His');
    if ($exportKey === 'claim_ledger') {
        $rows = array_map(static fn (array $row): array => [
            (string) ($row['opened_at'] ?? ''),
            (string) ($row['job_number'] ?? ''),
            (string) ($row['customer_name'] ?? ''),
            (string) ($row['registration_no'] ?? ''),
            (string) ($row['insurance_company_name'] ?? ''),
            (string) ($row['insurance_claim_number'] ?? ''),
            (string) ($row['insurance_surveyor_name'] ?? ''),
            (string) ($row['insurance_claim_status'] ?? ''),
            (float) ($row['insurance_claim_amount_approved'] ?? 0),
            (float) ($row['insurance_customer_payable_amount'] ?? 0),
            (string) ($row['garage_name'] ?? ''),
        ], $claimRows);
        reports_csv_download(
            'insurance_claim_ledger_' . $timestamp . '.csv',
            ['Date', 'Job Card', 'Customer', 'Vehicle', 'Insurance Company', 'Claim Number', 'Surveyor', 'Status', 'Claim Approved', 'Customer Payable', 'Garage'],
            $rows
        );
    }
    if ($exportKey === 'insurance_wise') {
        $rows = array_map(static fn (array $row): array => [
            (string) ($row['company_name'] ?? ''),
            (int) ($row['total_claims'] ?? 0),
            (int) ($row['pending_count'] ?? 0),
            (int) ($row['approved_count'] ?? 0),
            (int) ($row['rejected_count'] ?? 0),
            (int) ($row['settled_count'] ?? 0),
            (float) ($row['approved_amount'] ?? 0),
            (float) ($row['customer_payable'] ?? 0),
        ], $insuranceWiseRows);
        reports_csv_download(
            'insurance_wise_report_' . $timestamp . '.csv',
            ['Insurance Company', 'Total Claims', 'Pending', 'Approved', 'Rejected', 'Settled', 'Claim Approved Amount', 'Customer Payable'],
            $rows
        );
    }
    if ($exportKey === 'settlement') {
        $rows = [];
        foreach (job_insurance_allowed_statuses() as $status) {
            $statusRows = $statusSummary[$status] ?? ['count' => 0, 'approved_amount' => 0.0, 'customer_payable' => 0.0];
            $rows[] = [
                $status,
                (int) ($statusRows['count'] ?? 0),
                (float) ($statusRows['approved_amount'] ?? 0),
                (float) ($statusRows['customer_payable'] ?? 0),
            ];
        }
        reports_csv_download(
            'insurance_settlement_report_' . $timestamp . '.csv',
            ['Status', 'Claims', 'Claim Approved Amount', 'Customer Payable'],
            $rows
        );
    }

    flash_set('report_error', 'Unknown export requested.', 'warning');
    redirect('modules/reports/insurance_claims.php?' . http_build_query(reports_compact_query_params($pageParams)));
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Insurance Claim Reports</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Insurance Claims</li>
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
              <label class="form-label">Claim Status</label>
              <select name="claim_status" class="form-select">
                <option value="">All Statuses</option>
                <?php foreach (job_insurance_allowed_statuses() as $statusOption): ?>
                  <option value="<?= e($statusOption); ?>" <?= $claimStatusFilter === $statusOption ? 'selected' : ''; ?>><?= e($statusOption); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Insurance Company</label>
              <select name="insurance_company" class="form-select" data-searchable-select="1">
                <option value="">All Companies</option>
                <?php foreach ($insuranceCompanyOptions as $insuranceCompany): ?>
                  <option value="<?= e($insuranceCompany); ?>" <?= $insuranceCompanyFilter === $insuranceCompany ? 'selected' : ''; ?>><?= e($insuranceCompany); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2"><label class="form-label">Claim Number</label><input type="text" name="claim_no" class="form-control" value="<?= e($claimNoFilter); ?>" placeholder="Claim no"></div>
            <div class="col-md-2"><label class="form-label">Search</label><input type="text" name="q" class="form-control" value="<?= e($search); ?>" placeholder="Job/Customer/Vehicle"></div>
            <div class="col-md-2 d-flex gap-2"><button type="submit" class="btn btn-primary">Apply</button><a href="<?= e(url('modules/reports/insurance_claims.php')); ?>" class="btn btn-outline-secondary">Reset</a></div>
          </form>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="small-box text-bg-primary"><div class="inner"><h4><?= number_format((int) ($summaryTotals['total_claims'] ?? 0)); ?></h4><p>Total Claim Jobs</p></div><span class="small-box-icon"><i class="bi bi-shield-check"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-warning"><div class="inner"><h4><?= number_format((int) ($summaryTotals['pending_count'] ?? 0)); ?></h4><p>Pending Claims</p></div><span class="small-box-icon"><i class="bi bi-hourglass-split"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-success"><div class="inner"><h4><?= number_format((int) ($summaryTotals['settled_count'] ?? 0)); ?></h4><p>Settled Claims</p></div><span class="small-box-icon"><i class="bi bi-check2-square"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-info"><div class="inner"><h4><?= e(format_currency((float) ($summaryTotals['approved_amount'] ?? 0))); ?></h4><p>Claim Approved Total</p></div><span class="small-box-icon"><i class="bi bi-currency-rupee"></i></span></div></div>
      </div>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Claim Ledger</h3>
          <a href="<?= e(reports_export_url('modules/reports/insurance_claims.php', $pageParams, 'claim_ledger')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Date</th>
                <th>Job Card</th>
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Insurance Company</th>
                <th>Claim #</th>
                <th>Surveyor</th>
                <th>Status</th>
                <th>Claim Approved</th>
                <th>Customer Payable</th>
                <th>Garage</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($claimRows === []): ?>
                <tr><td colspan="11" class="text-center text-muted py-4">No insurance claim records found.</td></tr>
              <?php else: ?>
                <?php foreach ($claimRows as $row): ?>
                  <?php $rowStatus = job_insurance_normalize_status((string) ($row['insurance_claim_status'] ?? 'PENDING')); ?>
                  <tr>
                    <td><?= e((string) (($row['opened_at'] ?? '') !== '' ? $row['opened_at'] : '-')); ?></td>
                    <td><a href="<?= e(url('modules/jobs/view.php?id=' . (int) ($row['id'] ?? 0))); ?>"><?= e((string) ($row['job_number'] ?? '-')); ?></a></td>
                    <td><?= e((string) ($row['customer_name'] ?? '-')); ?></td>
                    <td><?= e((string) ($row['registration_no'] ?? '-')); ?></td>
                    <td><?= e((string) (($row['insurance_company_name'] ?? '') !== '' ? $row['insurance_company_name'] : '-')); ?></td>
                    <td><?= e((string) (($row['insurance_claim_number'] ?? '') !== '' ? $row['insurance_claim_number'] : '-')); ?></td>
                    <td><?= e((string) (($row['insurance_surveyor_name'] ?? '') !== '' ? $row['insurance_surveyor_name'] : '-')); ?></td>
                    <td><span class="badge text-bg-<?= e($rowStatus === 'SETTLED' ? 'success' : ($rowStatus === 'APPROVED' ? 'primary' : ($rowStatus === 'REJECTED' ? 'danger' : 'warning'))); ?>"><?= e($rowStatus); ?></span></td>
                    <td><?= e(format_currency((float) ($row['insurance_claim_amount_approved'] ?? 0))); ?></td>
                    <td><?= e(format_currency((float) ($row['insurance_customer_payable_amount'] ?? 0))); ?></td>
                    <td><?= e((string) ($row['garage_name'] ?? '-')); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-7">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Insurance-wise Report</h3>
              <a href="<?= e(reports_export_url('modules/reports/insurance_claims.php', $pageParams, 'insurance_wise')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
            </div>
            <div class="card-body table-responsive p-0">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Insurance Company</th>
                    <th>Total Claims</th>
                    <th>Pending</th>
                    <th>Approved</th>
                    <th>Rejected</th>
                    <th>Settled</th>
                    <th>Approved Amount</th>
                    <th>Customer Payable</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($insuranceWiseRows === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No insurance-wise data in selected scope.</td></tr>
                  <?php else: ?>
                    <?php foreach ($insuranceWiseRows as $row): ?>
                      <tr>
                        <td><?= e((string) ($row['company_name'] ?? '-')); ?></td>
                        <td><?= number_format((int) ($row['total_claims'] ?? 0)); ?></td>
                        <td><?= number_format((int) ($row['pending_count'] ?? 0)); ?></td>
                        <td><?= number_format((int) ($row['approved_count'] ?? 0)); ?></td>
                        <td><?= number_format((int) ($row['rejected_count'] ?? 0)); ?></td>
                        <td><?= number_format((int) ($row['settled_count'] ?? 0)); ?></td>
                        <td><?= e(format_currency((float) ($row['approved_amount'] ?? 0))); ?></td>
                        <td><?= e(format_currency((float) ($row['customer_payable'] ?? 0))); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="col-lg-5">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Claim Settlement Report</h3>
              <a href="<?= e(reports_export_url('modules/reports/insurance_claims.php', $pageParams, 'settlement')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
            </div>
            <div class="card-body table-responsive p-0">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Status</th>
                    <th>Claims</th>
                    <th>Claim Approved Amount</th>
                    <th>Customer Payable</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (job_insurance_allowed_statuses() as $status): ?>
                    <?php $row = $statusSummary[$status] ?? ['count' => 0, 'approved_amount' => 0.0, 'customer_payable' => 0.0]; ?>
                    <tr>
                      <td><?= e($status); ?></td>
                      <td><?= number_format((int) ($row['count'] ?? 0)); ?></td>
                      <td><?= e(format_currency((float) ($row['approved_amount'] ?? 0))); ?></td>
                      <td><?= e(format_currency((float) ($row['customer_payable'] ?? 0))); ?></td>
                    </tr>
                  <?php endforeach; ?>
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
