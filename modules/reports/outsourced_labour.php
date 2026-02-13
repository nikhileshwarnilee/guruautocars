<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';

reports_require_access();
if (!has_permission('outsourced.view')) {
    flash_set('access_denied', 'You do not have permission to view outsourced labour reports.', 'danger');
    redirect('modules/reports/index.php');
}

if (table_columns('outsourced_works') === [] || table_columns('outsourced_work_payments') === []) {
    flash_set('report_error', 'Outsourced report tables are missing. Run database/outsourced_works_upgrade.sql first.', 'danger');
    redirect('modules/reports/index.php');
}

$page_title = 'Outsourced Labour Report';
$active_menu = 'reports.outsourced';

function orlr_status_bucket(?string $status): string
{
    $normalized = strtoupper(trim((string) $status));
    if (in_array($normalized, ['SENT', 'RECEIVED', 'VERIFIED'], true)) {
        return 'SENT';
    }
    if ($normalized === 'PAYABLE') {
        return 'PAYABLE';
    }
    if ($normalized === 'PAID') {
        return 'PAID';
    }
    return 'SENT';
}

function orlr_status_badge_class(string $bucket): string
{
    return match ($bucket) {
        'PAYABLE' => 'warning',
        'PAID' => 'success',
        default => 'secondary',
    };
}

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
$baseParams = $scope['base_params'];

$vendorFilter = get_int('vendor_id', 0);
$jobCardFilter = get_int('job_card_id', 0);
$statusFilter = strtoupper(trim((string) ($_GET['status'] ?? '')));
if (!in_array($statusFilter, ['SENT', 'PAYABLE', 'PAID'], true)) {
    $statusFilter = '';
}

$pageParams = $baseParams;
$pageParams['vendor_id'] = $vendorFilter > 0 ? $vendorFilter : null;
$pageParams['job_card_id'] = $jobCardFilter > 0 ? $jobCardFilter : null;
$pageParams['status'] = $statusFilter !== '' ? $statusFilter : null;

$vendorParams = ['company_id' => $companyId];
$vendorScopeSql = analytics_garage_scope_sql('ow.garage_id', $selectedGarageId, $garageIds, $vendorParams, 'orlr_vendor_scope');
$vendorStmt = db()->prepare(
    'SELECT DISTINCT v.id, v.vendor_name, v.vendor_code
     FROM outsourced_works ow
     INNER JOIN vendors v ON v.id = ow.vendor_id AND v.company_id = ow.company_id
     WHERE ow.company_id = :company_id
       AND ow.status_code = "ACTIVE"
       ' . $vendorScopeSql . '
     ORDER BY v.vendor_name ASC'
);
$vendorStmt->execute($vendorParams);
$vendorOptions = $vendorStmt->fetchAll();

$jobOptionParams = ['company_id' => $companyId];
$jobOptionScopeSql = analytics_garage_scope_sql('ow.garage_id', $selectedGarageId, $garageIds, $jobOptionParams, 'orlr_job_scope');
$jobOptionStmt = db()->prepare(
    'SELECT DISTINCT jc.id, jc.job_number
     FROM outsourced_works ow
     INNER JOIN job_cards jc ON jc.id = ow.job_card_id
     WHERE ow.company_id = :company_id
       AND ow.status_code = "ACTIVE"
       AND jc.company_id = :company_id
       ' . $jobOptionScopeSql . '
     ORDER BY jc.id DESC
     LIMIT 300'
);
$jobOptionStmt->execute($jobOptionParams);
$jobOptions = $jobOptionStmt->fetchAll();

$rowsParams = [
    'company_id' => $companyId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
$rowsScopeSql = analytics_garage_scope_sql('ow.garage_id', $selectedGarageId, $garageIds, $rowsParams, 'orlr_rows_scope');
$where = [
    'ow.company_id = :company_id',
    'ow.status_code = "ACTIVE"',
    $rowsScopeSql !== '' ? ('1=1 ' . $rowsScopeSql) : '1=1',
    'DATE(ow.sent_at) BETWEEN :from_date AND :to_date',
];
if ($vendorFilter > 0) {
    $where[] = 'ow.vendor_id = :vendor_id';
    $rowsParams['vendor_id'] = $vendorFilter;
}
if ($jobCardFilter > 0) {
    $where[] = 'ow.job_card_id = :job_card_id';
    $rowsParams['job_card_id'] = $jobCardFilter;
}
if ($statusFilter === 'SENT') {
    $where[] = 'ow.current_status IN ("SENT", "RECEIVED", "VERIFIED")';
} elseif ($statusFilter === 'PAYABLE') {
    $where[] = 'ow.current_status = "PAYABLE"';
} elseif ($statusFilter === 'PAID') {
    $where[] = 'ow.current_status = "PAID"';
}
if (in_array('status_code', table_columns('job_cards'), true)) {
    $where[] = '(jc.status_code IS NULL OR jc.status_code <> "DELETED")';
}

$rowsStmt = db()->prepare(
    'SELECT ow.id,
            ow.job_card_id,
            ow.current_status,
            ow.agreed_cost,
            ow.sent_at,
            COALESCE(NULLIF(TRIM(jc.job_number), ""), CONCAT("JOB-", ow.job_card_id)) AS job_reference,
            COALESCE(NULLIF(TRIM(v.vendor_name), ""), NULLIF(TRIM(ow.partner_name), ""), "UNASSIGNED") AS vendor_label,
            COALESCE(pay.paid_amount, 0) AS paid_amount,
            GREATEST(ow.agreed_cost - COALESCE(pay.paid_amount, 0), 0) AS outstanding_amount
     FROM outsourced_works ow
     LEFT JOIN vendors v ON v.id = ow.vendor_id AND v.company_id = ow.company_id
     LEFT JOIN job_cards jc ON jc.id = ow.job_card_id AND jc.company_id = ow.company_id
     LEFT JOIN (
        SELECT outsourced_work_id, COALESCE(SUM(amount), 0) AS paid_amount
        FROM outsourced_work_payments
        GROUP BY outsourced_work_id
     ) pay ON pay.outsourced_work_id = ow.id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY ow.sent_at DESC, ow.id DESC'
);
$rowsStmt->execute($rowsParams);
$rows = $rowsStmt->fetchAll();

$summary = [
    'work_count' => 0,
    'cost_total' => 0.0,
    'paid_total' => 0.0,
    'outstanding_total' => 0.0,
    'sent_count' => 0,
    'payable_count' => 0,
    'paid_count' => 0,
];

foreach ($rows as $row) {
    $summary['work_count']++;
    $cost = round((float) ($row['agreed_cost'] ?? 0), 2);
    $paid = round((float) ($row['paid_amount'] ?? 0), 2);
    $outstanding = round((float) ($row['outstanding_amount'] ?? 0), 2);
    $summary['cost_total'] += $cost;
    $summary['paid_total'] += $paid;
    $summary['outstanding_total'] += $outstanding;

    $bucket = orlr_status_bucket((string) ($row['current_status'] ?? 'SENT'));
    if ($bucket === 'PAID') {
        $summary['paid_count']++;
    } elseif ($bucket === 'PAYABLE') {
        $summary['payable_count']++;
    } else {
        $summary['sent_count']++;
    }
}

$summary['cost_total'] = round($summary['cost_total'], 2);
$summary['paid_total'] = round($summary['paid_total'], 2);
$summary['outstanding_total'] = round($summary['outstanding_total'], 2);

$costTrendMap = [];
$vendorCostMap = [];
foreach ($rows as $row) {
    $sentDate = (string) ($row['sent_at'] ?? '');
    $cost = (float) ($row['agreed_cost'] ?? 0);
    $vendor = (string) ($row['vendor_label'] ?? 'UNASSIGNED');
    if ($sentDate !== '') {
        $monthKey = date('Y-m', strtotime($sentDate));
        if (!isset($costTrendMap[$monthKey])) {
            $costTrendMap[$monthKey] = 0.0;
        }
        $costTrendMap[$monthKey] += $cost;
    }
    if (!isset($vendorCostMap[$vendor])) {
        $vendorCostMap[$vendor] = 0.0;
    }
    $vendorCostMap[$vendor] += $cost;
}
ksort($costTrendMap);
arsort($vendorCostMap);
$vendorCostMap = array_slice($vendorCostMap, 0, 10, true);

$chartPayload = [
    'cost_trend' => [
        'labels' => array_keys($costTrendMap),
        'values' => array_map(static fn (float $value): float => round($value, 2), array_values($costTrendMap)),
    ],
    'vendor_cost' => [
        'labels' => array_keys($vendorCostMap),
        'values' => array_map(static fn (float $value): float => round($value, 2), array_values($vendorCostMap)),
    ],
    'paid_unpaid' => [
        'labels' => ['Paid', 'Unpaid'],
        'values' => [(float) ($summary['paid_total'] ?? 0), (float) ($summary['outstanding_total'] ?? 0)],
    ],
];
$chartPayloadJson = json_encode(
    $chartPayload,
    JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
);

$renderReportBody = static function (array $rows, array $summary, string $chartPayloadJson): void {
    ?>
      <script type="application/json" data-chart-payload><?= $chartPayloadJson ?: '{}'; ?></script>

      <div class="row g-3 mb-3">
        <div class="col-lg-4">
          <div class="card h-100">
            <div class="card-header"><h3 class="card-title mb-0">Outsource Cost Trend</h3></div>
            <div class="card-body">
              <div class="gac-chart-wrap"><canvas id="outsourced-chart-cost-trend"></canvas></div>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="card h-100">
            <div class="card-header"><h3 class="card-title mb-0">Vendor-wise Outsource Cost</h3></div>
            <div class="card-body">
              <div class="gac-chart-wrap"><canvas id="outsourced-chart-vendor-cost"></canvas></div>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="card h-100">
            <div class="card-header"><h3 class="card-title mb-0">Paid vs Unpaid Distribution</h3></div>
            <div class="card-body">
              <div class="gac-chart-wrap"><canvas id="outsourced-chart-paid-unpaid"></canvas></div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-primary"><i class="bi bi-list-task"></i></span><div class="info-box-content"><span class="info-box-text">Works</span><span class="info-box-number"><?= number_format((int) ($summary['work_count'] ?? 0)); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-secondary"><i class="bi bi-currency-rupee"></i></span><div class="info-box-content"><span class="info-box-text">Cost Total</span><span class="info-box-number"><?= e(format_currency((float) ($summary['cost_total'] ?? 0))); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-check2-circle"></i></span><div class="info-box-content"><span class="info-box-text">Paid Total</span><span class="info-box-number"><?= e(format_currency((float) ($summary['paid_total'] ?? 0))); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-warning"><i class="bi bi-hourglass-split"></i></span><div class="info-box-content"><span class="info-box-text">Outstanding</span><span class="info-box-number"><?= e(format_currency((float) ($summary['outstanding_total'] ?? 0))); ?></span></div></div></div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="small-box text-bg-secondary"><div class="inner"><h4><?= number_format((int) ($summary['sent_count'] ?? 0)); ?></h4><p>Status: SENT</p></div><span class="small-box-icon"><i class="bi bi-send"></i></span></div></div>
        <div class="col-md-4"><div class="small-box text-bg-warning"><div class="inner"><h4><?= number_format((int) ($summary['payable_count'] ?? 0)); ?></h4><p>Status: PAYABLE</p></div><span class="small-box-icon"><i class="bi bi-cash-stack"></i></span></div></div>
        <div class="col-md-4"><div class="small-box text-bg-success"><div class="inner"><h4><?= number_format((int) ($summary['paid_count'] ?? 0)); ?></h4><p>Status: PAID</p></div><span class="small-box-icon"><i class="bi bi-check-circle"></i></span></div></div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title mb-0">Outsourced Labour Register</h3></div>
        <div class="card-body p-0 table-responsive">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Job Reference</th>
                <th>Vendor</th>
                <th>Status</th>
                <th>Cost</th>
                <th>Paid Amount</th>
                <th>Outstanding</th>
                <th>Sent Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No outsourced labour rows found for selected filters.</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $row): ?>
                  <?php $statusBucket = orlr_status_bucket((string) ($row['current_status'] ?? 'SENT')); ?>
                  <tr>
                    <td><?= e((string) ($row['job_reference'] ?? '')); ?></td>
                    <td><?= e((string) ($row['vendor_label'] ?? '')); ?></td>
                    <td><span class="badge text-bg-<?= e(orlr_status_badge_class($statusBucket)); ?>"><?= e($statusBucket); ?></span></td>
                    <td><?= e(format_currency((float) ($row['agreed_cost'] ?? 0))); ?></td>
                    <td><?= e(format_currency((float) ($row['paid_amount'] ?? 0))); ?></td>
                    <td><strong><?= e(format_currency((float) ($row['outstanding_amount'] ?? 0))); ?></strong></td>
                    <td><?= e((string) (!empty($row['sent_at']) ? date('Y-m-d', strtotime((string) $row['sent_at'])) : '-')); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
              <tfoot>
                <tr class="table-light">
                  <th colspan="3">Totals</th>
                  <th><?= e(format_currency((float) ($summary['cost_total'] ?? 0))); ?></th>
                  <th><?= e(format_currency((float) ($summary['paid_total'] ?? 0))); ?></th>
                  <th><?= e(format_currency((float) ($summary['outstanding_total'] ?? 0))); ?></th>
                  <th>-</th>
                </tr>
              </tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>
    <?php
};

if (isset($_GET['ajax']) && (string) $_GET['ajax'] === '1') {
    header('Content-Type: text/html; charset=utf-8');
    $renderReportBody($rows, $summary, $chartPayloadJson);
    exit;
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Outsourced Labour Report</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Outsourced Labour</li>
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
          <form
            method="get"
            id="outsourced-report-filter-form"
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
                    <option value="0" <?= $selectedGarageId === 0 ? 'selected' : ''; ?>>All Garages</option>
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
                  <option value="<?= e((string) $modeValue); ?>" <?= $dateMode === $modeValue ? 'selected' : ''; ?>>
                    <?= e((string) $modeLabel); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" required /></div>
            <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" required /></div>

            <div class="col-md-2">
              <label class="form-label">Vendor</label>
              <select name="vendor_id" class="form-select">
                <option value="0">All Vendors</option>
                <?php foreach ($vendorOptions as $vendor): ?>
                  <option value="<?= (int) $vendor['id']; ?>" <?= ((int) ($vendor['id'] ?? 0) === $vendorFilter) ? 'selected' : ''; ?>>
                    <?= e((string) $vendor['vendor_name']); ?><?= !empty($vendor['vendor_code']) ? ' (' . e((string) $vendor['vendor_code']) . ')' : ''; ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-2">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="" <?= $statusFilter === '' ? 'selected' : ''; ?>>All</option>
                <option value="SENT" <?= $statusFilter === 'SENT' ? 'selected' : ''; ?>>Sent</option>
                <option value="PAYABLE" <?= $statusFilter === 'PAYABLE' ? 'selected' : ''; ?>>Payable</option>
                <option value="PAID" <?= $statusFilter === 'PAID' ? 'selected' : ''; ?>>Paid</option>
              </select>
            </div>

            <div class="col-md-3">
              <label class="form-label">Job Card</label>
              <select name="job_card_id" class="form-select">
                <option value="0">All Job Cards</option>
                <?php foreach ($jobOptions as $jobOption): ?>
                  <option value="<?= (int) $jobOption['id']; ?>" <?= ((int) ($jobOption['id'] ?? 0) === $jobCardFilter) ? 'selected' : ''; ?>>
                    <?= e((string) ($jobOption['job_number'] ?? ('JOB-' . (int) ($jobOption['id'] ?? 0)))); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-3 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Apply</button>
              <a href="<?= e(url('modules/reports/outsourced_labour.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
          <div class="mt-3">
            <span class="badge text-bg-light border me-2">Garage: <?= e($scopeGarageLabel); ?></span>
            <span class="badge text-bg-light border me-2">FY: <?= e($fyLabel); ?></span>
            <span class="badge text-bg-light border me-2">Range: <?= e($fromDate); ?> to <?= e($toDate); ?></span>
            <span class="badge text-bg-success">Trusted Data: Active Outsourced Rows + Net Payment Ledger</span>
          </div>
        </div>
      </div>

      <div id="outsourced-report-content">
        <?php $renderReportBody($rows, $summary, $chartPayloadJson); ?>
      </div>
    </div>
  </div>
</main>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (!window.GacCharts) {
      return;
    }

    var form = document.getElementById('outsourced-report-filter-form');
    var target = document.getElementById('outsourced-report-content');
    if (!form || !target) {
      return;
    }

    var charts = window.GacCharts.createRegistry('outsourced-report');

    function renderCharts() {
      var payload = window.GacCharts.parsePayload(target);
      var chartData = payload || {};

      charts.render('#outsourced-chart-cost-trend', {
        type: 'line',
        data: {
          labels: chartData.cost_trend ? chartData.cost_trend.labels : [],
          datasets: [{
            label: 'Outsource Cost',
            data: chartData.cost_trend ? chartData.cost_trend.values : [],
            borderColor: window.GacCharts.palette.blue,
            backgroundColor: window.GacCharts.palette.blue + '33',
            fill: true,
            tension: 0.25
          }]
        },
        options: window.GacCharts.commonOptions()
      }, { emptyMessage: 'No outsource cost trend rows in selected range.' });

      charts.render('#outsourced-chart-vendor-cost', {
        type: 'bar',
        data: {
          labels: chartData.vendor_cost ? chartData.vendor_cost.labels : [],
          datasets: [{
            label: 'Outsource Cost',
            data: chartData.vendor_cost ? chartData.vendor_cost.values : [],
            backgroundColor: window.GacCharts.pickColors(10)
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true } }
        }
      }, { emptyMessage: 'No vendor-wise outsource rows in selected range.' });

      charts.render('#outsourced-chart-paid-unpaid', {
        type: 'doughnut',
        data: {
          labels: chartData.paid_unpaid ? chartData.paid_unpaid.labels : [],
          datasets: [{
            data: chartData.paid_unpaid ? chartData.paid_unpaid.values : [],
            backgroundColor: [window.GacCharts.palette.green, window.GacCharts.palette.orange]
          }]
        },
        options: window.GacCharts.commonOptions()
      }, { emptyMessage: 'No paid/unpaid distribution in selected range.' });
    }

    renderCharts();

    window.GacCharts.bindAjaxForm({
      form: form,
      target: target,
      mode: 'partial',
      extendParams: function (params) {
        params.set('ajax', '1');
      },
      afterUpdate: function () {
        renderCharts();
      }
    });
  });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
