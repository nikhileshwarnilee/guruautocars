<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';
require_once __DIR__ . '/../jobs/workflow.php';

reports_require_access();

$page_title = 'Maintenance Reminder Reports';
$active_menu = 'reports.service_reminders';

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

$serviceTypeFilter = service_reminder_normalize_type((string) ($_GET['service_type'] ?? ''));
$dueStateFilter = strtoupper(trim((string) ($_GET['due_state'] ?? 'ALL')));
$allowedDueStates = ['ALL', 'OVERDUE', 'DUE', 'UPCOMING', 'COMPLETED'];
if (!in_array($dueStateFilter, $allowedDueStates, true)) {
    $dueStateFilter = 'ALL';
}

$baseParams['service_type'] = $serviceTypeFilter !== '' ? $serviceTypeFilter : null;
$baseParams['due_state'] = $dueStateFilter !== 'ALL' ? $dueStateFilter : null;

$featureReady = service_reminder_feature_ready();
$reminderRows = $featureReady
    ? service_reminder_fetch_register_for_scope(
        $companyId,
        $selectedGarageId,
        $garageIds,
        1200,
        $serviceTypeFilter !== '' ? $serviceTypeFilter : null,
        $dueStateFilter,
        $fromDate,
        $toDate
    )
    : [];

$summary = service_reminder_summary_counts($reminderRows);

$exportKey = trim((string) ($_GET['export'] ?? ''));
if ($exportKey !== '') {
    if (!$canExportData) {
        http_response_code(403);
        exit('Export access denied.');
    }

    if ($exportKey === 'csv') {
        $timestamp = date('Ymd_His');
        $rows = array_map(
            static function (array $row): array {
                return [
                    (string) ($row['registration_no'] ?? ''),
                    (string) ($row['brand'] ?? ''),
                    (string) ($row['model'] ?? ''),
                    (string) ($row['variant'] ?? ''),
                    (string) ($row['customer_name'] ?? ''),
                    (string) ($row['job_number'] ?? ''),
                    (string) ($row['service_label'] ?? service_reminder_type_label((string) ($row['service_type'] ?? ''))),
                    $row['last_service_km'],
                    $row['next_due_km'],
                    (string) ($row['next_due_date'] ?? ''),
                    (string) ($row['predicted_next_visit_date'] ?? ''),
                    $row['current_odometer_km'],
                    (string) ($row['due_state'] ?? ''),
                    (string) ($row['source_type'] ?? ''),
                    (string) ($row['recommendation_text'] ?? ''),
                ];
            },
            $reminderRows
        );
        reports_csv_download(
            'maintenance_reminders_' . $timestamp . '.csv',
            [
                'Registration',
                'Brand',
                'Model',
                'Variant',
                'Customer',
                'Job Number',
                'Labour/Part',
                'Last Labour KM',
                'Next Due KM',
                'Next Due Date',
                'Predicted Next Visit',
                'Current Odometer KM',
                'Due State',
                'Source',
                'Recommendation',
            ],
            $rows
        );
    }

    flash_set('report_error', 'Unknown export requested.', 'warning');
    redirect('modules/reports/service_reminders.php?' . http_build_query(reports_compact_query_params($baseParams)));
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Maintenance Reminder Reports</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Maintenance Reminders</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php reports_render_page_navigation($active_menu, $baseParams); ?>

      <div class="card card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Filters</h3></div>
        <div class="card-body">
          <form
            method="get"
            class="row g-2 align-items-end"
            data-date-filter-form="1"
            data-date-range-start="<?= e((string) $scope['date_range_start']); ?>"
            data-date-range-end="<?= e((string) $scope['date_range_end']); ?>"
            data-date-yearly-start="<?= e((string) $scope['fy_start']); ?>"
          >
            <?php if ($allowAllGarages || count($garageIds) > 1): ?>
              <div class="col-md-2">
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
              <div class="col-md-2">
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
              <label class="form-label">Date Mode</label>
              <select name="date_mode" class="form-select">
                <?php foreach ($dateModeOptions as $modeValue => $modeLabel): ?>
                  <option value="<?= e((string) $modeValue); ?>" <?= $dateMode === $modeValue ? 'selected' : ''; ?>><?= e((string) $modeLabel); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">From</label>
              <input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" required>
            </div>
            <div class="col-md-2">
              <label class="form-label">To</label>
              <input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" required>
            </div>
            <div class="col-md-2">
              <label class="form-label">Labour Type</label>
              <select name="service_type" class="form-select">
                <option value="">All</option>
                <?php foreach (service_reminder_supported_types() as $serviceType): ?>
                  <option value="<?= e($serviceType); ?>" <?= $serviceTypeFilter === $serviceType ? 'selected' : ''; ?>>
                    <?= e(service_reminder_type_label($serviceType)); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Due State</label>
              <select name="due_state" class="form-select">
                <?php foreach ($allowedDueStates as $dueState): ?>
                  <option value="<?= e($dueState); ?>" <?= $dueStateFilter === $dueState ? 'selected' : ''; ?>>
                    <?= e($dueState); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Apply</button>
              <a href="<?= e(url('modules/reports/service_reminders.php')); ?>" class="btn btn-outline-secondary">Reset</a>
              <?php if ($canExportData): ?>
                <a href="<?= e(reports_export_url('modules/reports/service_reminders.php', $baseParams, 'csv')); ?>" class="btn btn-outline-success">CSV</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>

      <?php if (!$featureReady): ?>
        <div class="alert alert-warning">Maintenance reminder storage is not ready. Run DB upgrade to enable reminder reports.</div>
      <?php endif; ?>

      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-primary"><i class="bi bi-bell"></i></span><div class="info-box-content"><span class="info-box-text">Active</span><span class="info-box-number"><?= number_format((int) ($summary['total'] ?? 0)); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-danger"><i class="bi bi-exclamation-circle"></i></span><div class="info-box-content"><span class="info-box-text">Overdue</span><span class="info-box-number"><?= number_format((int) ($summary['overdue'] ?? 0)); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-warning"><i class="bi bi-alarm"></i></span><div class="info-box-content"><span class="info-box-text">Due</span><span class="info-box-number"><?= number_format((int) (($summary['due'] ?? 0) + ($summary['due_soon'] ?? 0))); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-info"><i class="bi bi-calendar-check"></i></span><div class="info-box-content"><span class="info-box-text">Upcoming</span><span class="info-box-number"><?= number_format((int) ($summary['upcoming'] ?? 0)); ?></span></div></div></div>
      </div>

      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Reminder Register</h3>
          <span class="badge text-bg-light border"><?= number_format(count($reminderRows)); ?> rows</span>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Vehicle</th>
                <th>Customer</th>
                <th>Job</th>
                <th>Labour/Part</th>
                <th class="text-end">Last KM</th>
                <th class="text-end">Due KM</th>
                <th>Due Date</th>
                <th>Predicted Visit</th>
                <th class="text-end">Current KM</th>
                <th>Status</th>
                <th>Source</th>
                <th>Recommendation</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($reminderRows)): ?>
                <tr><td colspan="12" class="text-center text-muted py-4">No reminders found for selected filters.</td></tr>
              <?php else: ?>
                <?php foreach ($reminderRows as $row): ?>
                  <tr>
                    <td>
                      <a href="<?= e(url('modules/vehicles/intelligence.php?id=' . (int) ($row['vehicle_id'] ?? 0))); ?>">
                        <?= e((string) ($row['registration_no'] ?? '-')); ?>
                      </a>
                      <div class="small text-muted"><?= e((string) ($row['brand'] ?? '')); ?> <?= e((string) ($row['model'] ?? '')); ?> <?= e((string) ($row['variant'] ?? '')); ?></div>
                    </td>
                    <td><?= e((string) ($row['customer_name'] ?? '-')); ?></td>
                    <td><?= e((string) (($row['job_number'] ?? '') !== '' ? $row['job_number'] : '-')); ?></td>
                    <td><?= e((string) ($row['service_label'] ?? service_reminder_type_label((string) ($row['service_type'] ?? '')))); ?></td>
                    <td class="text-end"><?= isset($row['last_service_km']) && $row['last_service_km'] !== null ? e(number_format((float) $row['last_service_km'], 0)) : '-'; ?></td>
                    <td class="text-end"><?= isset($row['next_due_km']) && $row['next_due_km'] !== null ? e(number_format((float) $row['next_due_km'], 0)) : '-'; ?></td>
                    <td><?= e((string) (($row['next_due_date'] ?? '') !== '' ? $row['next_due_date'] : '-')); ?></td>
                    <td><?= e((string) (($row['predicted_next_visit_date'] ?? '') !== '' ? $row['predicted_next_visit_date'] : '-')); ?></td>
                    <td class="text-end"><?= isset($row['current_odometer_km']) && $row['current_odometer_km'] !== null ? e(number_format((float) $row['current_odometer_km'], 0)) : '-'; ?></td>
                    <td><span class="badge text-bg-<?= e(service_reminder_due_badge_class((string) ($row['due_state'] ?? 'UNSCHEDULED'))); ?>"><?= e((string) ($row['due_state'] ?? 'UNSCHEDULED')); ?></span></td>
                    <td><?= e((string) ($row['source_type'] ?? 'AUTO')); ?></td>
                    <td class="small"><?= e((string) ($row['recommendation_text'] ?? '-')); ?></td>
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


