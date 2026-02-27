<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';
require_once __DIR__ . '/../jobs/workflow.php';

reports_require_access();
if (!has_permission('job.view') && !has_permission('job.manage')) {
    flash_set('access_denied', 'You do not have permission to view vehicle intake audit reports.', 'danger');
    redirect('modules/reports/index.php');
}

$page_title = 'Vehicle Intake Audit Report';
$active_menu = 'reports.vehicle_intake_audit';

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

if (!job_vehicle_intake_feature_ready()) {
    flash_set('report_warning', 'Vehicle intake feature is not ready in this database.', 'warning');
}

$staffFilterId = max(0, get_int('staff_id'));
$fuelFilter = job_vehicle_intake_normalize_fuel_level((string) ($_GET['fuel_level'] ?? ''));
if (trim((string) ($_GET['fuel_level'] ?? '')) === '') {
    $fuelFilter = '';
}
$damagedOnly = get_int('damaged_only') === 1;
$search = trim((string) ($_GET['q'] ?? ''));

$pageParams = array_merge($baseParams, [
    'staff_id' => $staffFilterId > 0 ? $staffFilterId : null,
    'fuel_level' => $fuelFilter !== '' ? $fuelFilter : null,
    'damaged_only' => $damagedOnly ? 1 : null,
    'q' => $search !== '' ? $search : null,
]);

$staffStmt = db()->prepare(
    'SELECT id, name
     FROM users
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY name ASC'
);
$staffStmt->execute(['company_id' => $companyId]);
$staffOptions = $staffStmt->fetchAll();

$where = [
    'jvi.company_id = :company_id',
    'jvi.status_code = "ACTIVE"',
    'jc.status_code <> "DELETED"',
    'DATE(jvi.created_at) BETWEEN :from_date AND :to_date',
];
$params = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
$garageScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $params, 'intake_report_scope');

if ($staffFilterId > 0) {
    $where[] = 'jvi.created_by = :staff_id';
    $params['staff_id'] = $staffFilterId;
}
if ($fuelFilter !== '') {
    $where[] = 'jvi.fuel_level = :fuel_level';
    $params['fuel_level'] = $fuelFilter;
}
if ($damagedOnly) {
    $where[] = '(COALESCE(ci.damaged_count, 0) > 0
        OR COALESCE(jvi.exterior_condition_notes, "") <> ""
        OR COALESCE(jvi.interior_condition_notes, "") <> ""
        OR COALESCE(jvi.mechanical_condition_notes, "") <> ""
        OR COALESCE(jvi.remarks, "") <> "")';
}
if ($search !== '') {
    $where[] = '(jc.job_number LIKE :q OR c.full_name LIKE :q OR v.registration_no LIKE :q OR g.name LIKE :q)';
    $params['q'] = '%' . $search . '%';
}

$sql =
    'SELECT jvi.id,
            jvi.job_card_id,
            jvi.fuel_level,
            jvi.odometer_reading,
            jvi.exterior_condition_notes,
            jvi.interior_condition_notes,
            jvi.mechanical_condition_notes,
            jvi.remarks,
            jvi.customer_acknowledged,
            jvi.acknowledged_at,
            jvi.created_by,
            jvi.created_at,
            jc.job_number,
            jc.status AS job_status,
            c.full_name AS customer_name,
            v.registration_no,
            g.name AS garage_name,
            u.name AS created_by_name,
            COALESCE(ci.checklist_count, 0) AS checklist_count,
            COALESCE(ci.damaged_count, 0) AS damaged_count,
            COALESCE(img.image_count, 0) AS image_count
     FROM job_vehicle_intake jvi
     INNER JOIN job_cards jc ON jc.id = jvi.job_card_id
     INNER JOIN customers c ON c.id = jc.customer_id
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     INNER JOIN garages g ON g.id = jc.garage_id
     LEFT JOIN users u ON u.id = jvi.created_by
     LEFT JOIN (
       SELECT job_intake_id,
              COUNT(*) AS checklist_count,
              SUM(CASE WHEN status = "DAMAGED" THEN 1 ELSE 0 END) AS damaged_count
       FROM job_vehicle_checklist_items
       WHERE status_code = "ACTIVE"
       GROUP BY job_intake_id
     ) ci ON ci.job_intake_id = jvi.id
     LEFT JOIN (
       SELECT job_intake_id,
              COUNT(*) AS image_count
       FROM job_vehicle_images
       WHERE status_code = "ACTIVE"
       GROUP BY job_intake_id
     ) img ON img.job_intake_id = jvi.id
     WHERE ' . implode(' AND ', $where) . '
       ' . $garageScopeSql . '
     ORDER BY jvi.id DESC
     LIMIT 2000';

$rowsStmt = db()->prepare($sql);
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();

$summary = [
    'total' => count($rows),
    'damaged' => 0,
    'acknowledged' => 0,
    'images' => 0,
];
$fuelSummary = [];
foreach ($rows as $row) {
    $fuel = job_vehicle_intake_normalize_fuel_level((string) ($row['fuel_level'] ?? 'LOW'));
    if (!isset($fuelSummary[$fuel])) {
        $fuelSummary[$fuel] = 0;
    }
    $fuelSummary[$fuel]++;
    if ((int) ($row['damaged_count'] ?? 0) > 0) {
        $summary['damaged']++;
    }
    if ((int) ($row['customer_acknowledged'] ?? 0) === 1) {
        $summary['acknowledged']++;
    }
    $summary['images'] += (int) ($row['image_count'] ?? 0);
}

$exportKey = trim((string) ($_GET['export'] ?? ''));
if ($exportKey !== '') {
    if (!$canExportData) {
        http_response_code(403);
        exit('Export access denied.');
    }

    $timestamp = date('Ymd_His');
    if ($exportKey === 'intake_ledger') {
        $exportRows = array_map(static fn (array $row): array => [
            (string) ($row['created_at'] ?? ''),
            (string) ($row['job_number'] ?? ''),
            (string) ($row['garage_name'] ?? ''),
            (string) ($row['customer_name'] ?? ''),
            (string) ($row['registration_no'] ?? ''),
            (string) ($row['fuel_level'] ?? ''),
            (int) ($row['odometer_reading'] ?? 0),
            (int) ($row['checklist_count'] ?? 0),
            (int) ($row['damaged_count'] ?? 0),
            (int) ($row['image_count'] ?? 0),
            (int) ($row['customer_acknowledged'] ?? 0) === 1 ? 'YES' : 'NO',
            (string) ($row['created_by_name'] ?? ''),
            (string) ($row['job_status'] ?? ''),
        ], $rows);
        reports_csv_download(
            'vehicle_intake_audit_ledger_' . $timestamp . '.csv',
            ['Intake Date', 'Job Card', 'Garage', 'Customer', 'Vehicle', 'Fuel Level', 'Odometer', 'Checklist Items', 'Damaged Items', 'Image Count', 'Customer Ack', 'Captured By', 'Job Status'],
            $exportRows
        );
    }
    if ($exportKey === 'fuel_summary') {
        $exportRows = [];
        foreach (job_vehicle_intake_allowed_fuel_levels() as $fuelLevel) {
            $exportRows[] = [$fuelLevel, (int) ($fuelSummary[$fuelLevel] ?? 0)];
        }
        reports_csv_download(
            'vehicle_intake_fuel_summary_' . $timestamp . '.csv',
            ['Fuel Level', 'Vehicle Count'],
            $exportRows
        );
    }

    flash_set('report_error', 'Unknown export requested.', 'warning');
    redirect('modules/reports/vehicle_intake_audit.php?' . http_build_query(reports_compact_query_params($pageParams)));
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Vehicle Intake Audit Report</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Vehicle Intake Audit</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php reports_render_page_navigation($active_menu, $baseParams); ?>
      <?php if ($canExportData): ?>
        <div class="d-flex flex-wrap justify-content-end gap-2 mb-3">
          <a href="<?= e(reports_export_url('modules/reports/vehicle_intake_audit.php', $pageParams, 'intake_ledger')); ?>" class="btn btn-outline-secondary btn-sm">Export Ledger CSV</a>
          <a href="<?= e(reports_export_url('modules/reports/vehicle_intake_audit.php', $pageParams, 'fuel_summary')); ?>" class="btn btn-outline-secondary btn-sm">Export Fuel Summary</a>
        </div>
      <?php endif; ?>

      <div class="card card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Filters</h3></div>
        <div class="card-body">
          <form method="get" class="row g-2 align-items-end" data-date-filter-form="1" data-date-range-start="<?= e((string) $scope['date_range_start']); ?>" data-date-range-end="<?= e((string) $scope['date_range_end']); ?>" data-date-yearly-start="<?= e((string) $scope['fy_start']); ?>">
            <?php if ($allowAllGarages || count($garageIds) > 1): ?>
              <div class="col-md-3">
                <label class="form-label">Garage Scope</label>
                <select name="garage_id" class="form-select">
                  <?php if ($allowAllGarages): ?>
                    <option value="0" <?= $selectedGarageId === 0 ? 'selected' : ''; ?>>All Accessible Garages</option>
                  <?php endif; ?>
                  <?php foreach ($garageOptions as $garage): ?>
                    <option value="<?= (int) ($garage['id'] ?? 0); ?>" <?= ((int) ($garage['id'] ?? 0) === $selectedGarageId) ? 'selected' : ''; ?>>
                      <?= e((string) ($garage['name'] ?? '')); ?> (<?= e((string) ($garage['code'] ?? '')); ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <div class="col-md-3">
                <label class="form-label">Garage Scope</label>
                <input type="text" class="form-control" value="<?= e($scopeGarageLabel); ?>" readonly>
                <input type="hidden" name="garage_id" value="<?= (int) $selectedGarageId; ?>">
              </div>
            <?php endif; ?>
            <div class="col-md-2">
              <label class="form-label">Financial Year</label>
              <select name="fy_id" class="form-select">
                <?php foreach ($financialYears as $fy): ?>
                  <option value="<?= (int) ($fy['id'] ?? 0); ?>" <?= ((int) ($fy['id'] ?? 0) === $selectedFyId) ? 'selected' : ''; ?>>
                    <?= e((string) ($fy['fy_label'] ?? $fyLabel)); ?>
                  </option>
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
            <div class="col-md-2">
              <label class="form-label">Staff</label>
              <select name="staff_id" class="form-select">
                <option value="0">All Staff</option>
                <?php foreach ($staffOptions as $staff): ?>
                  <option value="<?= (int) ($staff['id'] ?? 0); ?>" <?= ((int) ($staff['id'] ?? 0) === $staffFilterId) ? 'selected' : ''; ?>>
                    <?= e((string) ($staff['name'] ?? '')); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Fuel Level</label>
              <select name="fuel_level" class="form-select">
                <option value="">All Fuel Levels</option>
                <?php foreach (job_vehicle_intake_allowed_fuel_levels() as $fuelLevel): ?>
                  <option value="<?= e($fuelLevel); ?>" <?= $fuelFilter === $fuelLevel ? 'selected' : ''; ?>><?= e(str_replace('_', ' ', $fuelLevel)); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="damaged_only" id="damaged-only-filter" value="1" <?= $damagedOnly ? 'checked' : ''; ?>>
                <label class="form-check-label" for="damaged-only-filter">Damaged Only</label>
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label">Search</label>
              <input type="text" name="q" class="form-control" value="<?= e($search); ?>" placeholder="Job / customer / vehicle / garage">
            </div>
            <div class="col-md-2 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Apply</button>
              <a href="<?= e(url('modules/reports/vehicle_intake_audit.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <div class="small-box text-bg-primary mb-0"><div class="inner"><h4><?= number_format((int) ($summary['total'] ?? 0)); ?></h4><p>Total Intake Records</p></div></div>
        </div>
        <div class="col-md-3">
          <div class="small-box text-bg-danger mb-0"><div class="inner"><h4><?= number_format((int) ($summary['damaged'] ?? 0)); ?></h4><p>Damaged Intake Cases</p></div></div>
        </div>
        <div class="col-md-3">
          <div class="small-box text-bg-success mb-0"><div class="inner"><h4><?= number_format((int) ($summary['acknowledged'] ?? 0)); ?></h4><p>Customer Acknowledged</p></div></div>
        </div>
        <div class="col-md-3">
          <div class="small-box text-bg-info mb-0"><div class="inner"><h4><?= number_format((int) ($summary['images'] ?? 0)); ?></h4><p>Total Intake Images</p></div></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Vehicle Intake Ledger</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Date</th>
                <th>Job Card</th>
                <th>Garage</th>
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Fuel</th>
                <th class="text-end">Odometer</th>
                <th class="text-end">Checklist</th>
                <th class="text-end">Damaged</th>
                <th class="text-end">Images</th>
                <th>Captured By</th>
                <th>Job Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($rows === []): ?>
                <tr><td colspan="12" class="text-center text-muted py-4">No intake records found for current filters.</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $row): ?>
                  <?php $fuelLevel = job_vehicle_intake_normalize_fuel_level((string) ($row['fuel_level'] ?? 'LOW')); ?>
                  <tr>
                    <td><?= e((string) ($row['created_at'] ?? '')); ?></td>
                    <td><a href="<?= e(url('modules/jobs/view.php?id=' . (int) ($row['job_card_id'] ?? 0) . '#vehicle-intake')); ?>"><?= e((string) ($row['job_number'] ?? '')); ?></a></td>
                    <td><?= e((string) ($row['garage_name'] ?? '')); ?></td>
                    <td><?= e((string) ($row['customer_name'] ?? '')); ?></td>
                    <td><?= e((string) ($row['registration_no'] ?? '')); ?></td>
                    <td><?= e($fuelLevel); ?></td>
                    <td class="text-end"><?= e(number_format((float) ($row['odometer_reading'] ?? 0), 0)); ?></td>
                    <td class="text-end"><?= (int) ($row['checklist_count'] ?? 0); ?></td>
                    <td class="text-end"><span class="badge text-bg-<?= (int) ($row['damaged_count'] ?? 0) > 0 ? 'danger' : 'secondary'; ?>"><?= (int) ($row['damaged_count'] ?? 0); ?></span></td>
                    <td class="text-end"><?= (int) ($row['image_count'] ?? 0); ?></td>
                    <td><?= e((string) (($row['created_by_name'] ?? '') !== '' ? $row['created_by_name'] : 'System')); ?></td>
                    <td><?= e((string) ($row['job_status'] ?? '')); ?></td>
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
