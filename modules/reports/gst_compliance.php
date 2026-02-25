<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';

reports_require_access();

if (!has_permission('gst.reports') && !has_permission('financial.reports')) {
    flash_set('access_denied', 'You do not have permission to access GST compliance reports.', 'danger');
    redirect('dashboard.php');
}

$page_title = 'GST Compliance Reports';
$active_menu = 'reports.gst_compliance';

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

$canExportData = has_permission('export.data');

$salesRows = [];
$salesSummary = ['invoice_count' => 0, 'taxable_total' => 0, 'cgst_total' => 0, 'sgst_total' => 0, 'igst_total' => 0, 'discount_total' => 0, 'grand_total' => 0];
$purchaseRows = [];
$purchaseSummary = ['invoice_count' => 0, 'taxable_total' => 0, 'cgst_total' => 0, 'sgst_total' => 0, 'igst_total' => 0, 'grand_total' => 0];

function gst_compliance_discount_meta_from_snapshot(array $snapshot): array
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

$salesParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$salesScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $salesParams, 'gst_sales_scope');
$salesStmt = db()->prepare(
    'SELECT i.invoice_number,
            i.invoice_date,
            c.full_name AS customer_name,
            c.gstin AS customer_gstin,
            i.taxable_amount,
            i.cgst_amount,
            i.sgst_amount,
            i.igst_amount,
            i.snapshot_json,
            i.grand_total
     FROM invoices i
     INNER JOIN job_cards jc ON jc.id = i.job_card_id
     INNER JOIN customers c ON c.id = i.customer_id
     WHERE i.company_id = :company_id
       AND i.invoice_status = "FINALIZED"
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       ' . $salesScopeSql . '
       AND i.invoice_date BETWEEN :from_date AND :to_date
     ORDER BY i.invoice_date ASC, i.invoice_number ASC'
);
$salesStmt->execute($salesParams);
$salesRows = $salesStmt->fetchAll();

foreach ($salesRows as &$row) {
    $snapshot = json_decode((string) ($row['snapshot_json'] ?? ''), true);
    if (!is_array($snapshot)) {
        $snapshot = [];
    }
    $discountMeta = gst_compliance_discount_meta_from_snapshot($snapshot);
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
    $salesSummary['invoice_count'] += 1;
    $salesSummary['taxable_total'] += (float) ($row['taxable_amount'] ?? 0);
    $salesSummary['cgst_total'] += (float) ($row['cgst_amount'] ?? 0);
    $salesSummary['sgst_total'] += (float) ($row['sgst_amount'] ?? 0);
    $salesSummary['igst_total'] += (float) ($row['igst_amount'] ?? 0);
    $salesSummary['discount_total'] += $discountAmount;
    $salesSummary['grand_total'] += (float) ($row['grand_total'] ?? 0);
}
unset($row);

$purchaseParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$purchaseScopeSql = analytics_garage_scope_sql('p.garage_id', $selectedGarageId, $garageIds, $purchaseParams, 'gst_purchase_scope');
$purchaseStmt = db()->prepare(
    'SELECT p.invoice_number,
            p.purchase_date,
            v.vendor_name,
            v.gstin AS vendor_gstin,
            p.taxable_amount,
            p.gst_amount,
            p.grand_total,
            g.state AS garage_state,
            v.state AS vendor_state
     FROM purchases p
     INNER JOIN garages g ON g.id = p.garage_id
     LEFT JOIN vendors v ON v.id = p.vendor_id
     WHERE p.company_id = :company_id
       AND p.purchase_status = "FINALIZED"
       AND p.assignment_status = "ASSIGNED"
       ' . $purchaseScopeSql . '
       AND p.purchase_date BETWEEN :from_date AND :to_date
     ORDER BY p.purchase_date ASC, p.invoice_number ASC'
);
$purchaseStmt->execute($purchaseParams);
$purchaseRows = $purchaseStmt->fetchAll();

foreach ($purchaseRows as &$row) {
    $gstAmount = (float) ($row['gst_amount'] ?? 0);
    $garageState = trim((string) ($row['garage_state'] ?? ''));
    $vendorState = trim((string) ($row['vendor_state'] ?? ''));

    if ($garageState !== '' && $vendorState !== '' && strcasecmp($garageState, $vendorState) === 0) {
        $row['cgst_amount'] = round($gstAmount / 2, 2);
        $row['sgst_amount'] = round($gstAmount / 2, 2);
        $row['igst_amount'] = 0.0;
    } else {
        $row['cgst_amount'] = 0.0;
        $row['sgst_amount'] = 0.0;
        $row['igst_amount'] = $gstAmount;
    }

    $purchaseSummary['invoice_count'] += 1;
    $purchaseSummary['taxable_total'] += (float) ($row['taxable_amount'] ?? 0);
    $purchaseSummary['cgst_total'] += (float) ($row['cgst_amount'] ?? 0);
    $purchaseSummary['sgst_total'] += (float) ($row['sgst_amount'] ?? 0);
    $purchaseSummary['igst_total'] += (float) ($row['igst_amount'] ?? 0);
    $purchaseSummary['grand_total'] += (float) ($row['grand_total'] ?? 0);
}
unset($row);

$salesMonthlyTaxMap = [];
foreach ($salesRows as $row) {
    $month = date('Y-m', strtotime((string) ($row['invoice_date'] ?? $fromDate)));
    if (!isset($salesMonthlyTaxMap[$month])) {
        $salesMonthlyTaxMap[$month] = 0.0;
    }
    $salesMonthlyTaxMap[$month] += (float) ($row['cgst_amount'] ?? 0) + (float) ($row['sgst_amount'] ?? 0) + (float) ($row['igst_amount'] ?? 0);
}

$purchaseMonthlyTaxMap = [];
foreach ($purchaseRows as $row) {
    $month = date('Y-m', strtotime((string) ($row['purchase_date'] ?? $fromDate)));
    if (!isset($purchaseMonthlyTaxMap[$month])) {
        $purchaseMonthlyTaxMap[$month] = 0.0;
    }
    $purchaseMonthlyTaxMap[$month] += (float) ($row['cgst_amount'] ?? 0) + (float) ($row['sgst_amount'] ?? 0) + (float) ($row['igst_amount'] ?? 0);
}

$gstMonthLabels = array_keys($salesMonthlyTaxMap + $purchaseMonthlyTaxMap);
sort($gstMonthLabels);

$chartPayload = [
    'tax_components' => [
        'labels' => ['Sales CGST', 'Sales SGST', 'Sales IGST', 'Purchase CGST', 'Purchase SGST', 'Purchase IGST'],
        'values' => [
            (float) ($salesSummary['cgst_total'] ?? 0),
            (float) ($salesSummary['sgst_total'] ?? 0),
            (float) ($salesSummary['igst_total'] ?? 0),
            (float) ($purchaseSummary['cgst_total'] ?? 0),
            (float) ($purchaseSummary['sgst_total'] ?? 0),
            (float) ($purchaseSummary['igst_total'] ?? 0),
        ],
    ],
    'gst_trend' => [
        'labels' => $gstMonthLabels,
        'sales_tax' => array_map(static fn (string $month): float => (float) ($salesMonthlyTaxMap[$month] ?? 0), $gstMonthLabels),
        'purchase_tax' => array_map(static fn (string $month): float => (float) ($purchaseMonthlyTaxMap[$month] ?? 0), $gstMonthLabels),
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

$exportKey = trim((string) ($_GET['export'] ?? ''));
if ($exportKey !== '') {
    if (!$canExportData) {
        http_response_code(403);
        exit('Export access denied.');
    }

    $timestamp = date('Ymd_His');
    switch ($exportKey) {
        case 'sales_gst':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['invoice_number'] ?? ''),
                    (string) ($row['invoice_date'] ?? ''),
                    (string) ($row['customer_name'] ?? ''),
                    (string) ($row['customer_gstin'] ?? ''),
                    (float) ($row['taxable_amount'] ?? 0),
                    (float) ($row['cgst_amount'] ?? 0),
                    (float) ($row['sgst_amount'] ?? 0),
                    (float) ($row['igst_amount'] ?? 0),
                    (float) ($row['discount_amount'] ?? 0),
                    (float) ($row['grand_total'] ?? 0),
                ],
                $salesRows
            );
            reports_csv_download('gst_sales_report_' . $timestamp . '.csv', ['Invoice', 'Date', 'Customer', 'GSTIN', 'Taxable', 'CGST', 'SGST', 'IGST', 'Discount', 'Total'], $rows);

        case 'purchase_gst':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['vendor_name'] ?? ''),
                    (string) ($row['invoice_number'] ?? ''),
                    (string) ($row['purchase_date'] ?? ''),
                    (string) ($row['vendor_gstin'] ?? ''),
                    (float) ($row['taxable_amount'] ?? 0),
                    (float) ($row['cgst_amount'] ?? 0),
                    (float) ($row['sgst_amount'] ?? 0),
                    (float) ($row['igst_amount'] ?? 0),
                    (float) ($row['grand_total'] ?? 0),
                ],
                $purchaseRows
            );
            reports_csv_download('gst_purchase_report_' . $timestamp . '.csv', ['Vendor', 'Invoice', 'Date', 'GSTIN', 'Taxable', 'CGST', 'SGST', 'IGST', 'Total'], $rows);

        default:
            flash_set('report_error', 'Unknown export requested.', 'warning');
            redirect('modules/reports/gst_compliance.php?' . http_build_query(reports_compact_query_params($baseParams)));
    }
}

$renderReportBody = static function (
    array $salesRows,
    array $salesSummary,
    array $purchaseRows,
    array $purchaseSummary,
    array $baseParams,
    bool $canExportData,
    string $chartPayloadJson
): void {
    $salesExportUrl = reports_export_url('modules/reports/gst_compliance.php', $baseParams, 'sales_gst');
    $purchaseExportUrl = reports_export_url('modules/reports/gst_compliance.php', $baseParams, 'purchase_gst');
    ?>
    <script type="application/json" data-chart-payload><?= $chartPayloadJson ?: '{}'; ?></script>

    <div class="row g-3 mb-3">
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><h3 class="card-title mb-0">GST Tax Components</h3></div>
          <div class="card-body">
            <div class="gac-chart-wrap"><canvas id="gst-chart-components"></canvas></div>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><h3 class="card-title mb-0">Sales &amp; Purchase GST Trend</h3></div>
          <div class="card-body">
            <div class="gac-chart-wrap"><canvas id="gst-chart-trend"></canvas></div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <div class="card card-success">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Sales GST Register</h3>
            <?php if ($canExportData): ?>
              <a href="<?= e($salesExportUrl); ?>" class="btn btn-sm btn-outline-light">Export CSV</a>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <div class="row g-2 mb-3">
              <div class="col-6"><strong>Invoices:</strong> <?= (int) ($salesSummary['invoice_count'] ?? 0); ?></div>
              <div class="col-6"><strong>Total Taxable:</strong> <?= e(format_currency((float) ($salesSummary['taxable_total'] ?? 0))); ?></div>
              <div class="col-6"><strong>CGST:</strong> <?= e(format_currency((float) ($salesSummary['cgst_total'] ?? 0))); ?></div>
              <div class="col-6"><strong>SGST:</strong> <?= e(format_currency((float) ($salesSummary['sgst_total'] ?? 0))); ?></div>
              <div class="col-6"><strong>IGST:</strong> <?= e(format_currency((float) ($salesSummary['igst_total'] ?? 0))); ?></div>
              <div class="col-6"><strong>Discount:</strong> <?= e(format_currency((float) ($salesSummary['discount_total'] ?? 0))); ?></div>
              <div class="col-6"><strong>Total Value:</strong> <?= e(format_currency((float) ($salesSummary['grand_total'] ?? 0))); ?></div>
            </div>
            <div class="table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Invoice</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>GSTIN</th>
                    <th>Taxable</th>
                    <th>CGST</th>
                    <th>SGST</th>
                    <th>IGST</th>
                    <th>Discount</th>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($salesRows)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">No finalized invoices in the selected range.</td></tr>
                  <?php else: ?>
                    <?php foreach ($salesRows as $row): ?>
                      <tr>
                        <td><?= e((string) ($row['invoice_number'] ?? '')); ?></td>
                        <td><?= e((string) ($row['invoice_date'] ?? '')); ?></td>
                        <td><?= e((string) ($row['customer_name'] ?? '')); ?></td>
                        <td><?= e((string) ($row['customer_gstin'] ?? '-')); ?></td>
                        <td><?= e(format_currency((float) ($row['taxable_amount'] ?? 0))); ?></td>
                        <td><?= e(format_currency((float) ($row['cgst_amount'] ?? 0))); ?></td>
                        <td><?= e(format_currency((float) ($row['sgst_amount'] ?? 0))); ?></td>
                        <td><?= e(format_currency((float) ($row['igst_amount'] ?? 0))); ?></td>
                        <td>
                          <?= e(format_currency((float) ($row['discount_amount'] ?? 0))); ?>
                          <?php if ((string) ($row['discount_label'] ?? '-') !== '-'): ?>
                            <div><small class="text-muted"><?= e((string) ($row['discount_label'] ?? '-')); ?></small></div>
                          <?php endif; ?>
                        </td>
                        <td><strong><?= e(format_currency((float) ($row['grand_total'] ?? 0))); ?></strong></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card card-primary">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Purchase GST Register</h3>
            <?php if ($canExportData): ?>
              <a href="<?= e($purchaseExportUrl); ?>" class="btn btn-sm btn-outline-light">Export CSV</a>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <div class="row g-2 mb-3">
              <div class="col-6"><strong>Invoices:</strong> <?= (int) ($purchaseSummary['invoice_count'] ?? 0); ?></div>
              <div class="col-6"><strong>Total Taxable:</strong> <?= e(format_currency((float) ($purchaseSummary['taxable_total'] ?? 0))); ?></div>
              <div class="col-6"><strong>CGST:</strong> <?= e(format_currency((float) ($purchaseSummary['cgst_total'] ?? 0))); ?></div>
              <div class="col-6"><strong>SGST:</strong> <?= e(format_currency((float) ($purchaseSummary['sgst_total'] ?? 0))); ?></div>
              <div class="col-6"><strong>IGST:</strong> <?= e(format_currency((float) ($purchaseSummary['igst_total'] ?? 0))); ?></div>
              <div class="col-6"><strong>Total Value:</strong> <?= e(format_currency((float) ($purchaseSummary['grand_total'] ?? 0))); ?></div>
            </div>
            <div class="table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Vendor</th>
                    <th>Invoice</th>
                    <th>Date</th>
                    <th>GSTIN</th>
                    <th>Taxable</th>
                    <th>CGST</th>
                    <th>SGST</th>
                    <th>IGST</th>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($purchaseRows)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No finalized purchases in the selected range.</td></tr>
                  <?php else: ?>
                    <?php foreach ($purchaseRows as $row): ?>
                      <tr>
                        <td><?= e((string) ($row['vendor_name'] ?? '')); ?></td>
                        <td><?= e((string) ($row['invoice_number'] ?? '')); ?></td>
                        <td><?= e((string) ($row['purchase_date'] ?? '')); ?></td>
                        <td><?= e((string) ($row['vendor_gstin'] ?? '-')); ?></td>
                        <td><?= e(format_currency((float) ($row['taxable_amount'] ?? 0))); ?></td>
                        <td><?= e(format_currency((float) ($row['cgst_amount'] ?? 0))); ?></td>
                        <td><?= e(format_currency((float) ($row['sgst_amount'] ?? 0))); ?></td>
                        <td><?= e(format_currency((float) ($row['igst_amount'] ?? 0))); ?></td>
                        <td><strong><?= e(format_currency((float) ($row['grand_total'] ?? 0))); ?></strong></td>
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
    <?php
};

if (isset($_GET['ajax']) && (string) $_GET['ajax'] === '1') {
    header('Content-Type: text/html; charset=utf-8');
    $renderReportBody($salesRows, $salesSummary, $purchaseRows, $purchaseSummary, $baseParams, $canExportData, $chartPayloadJson);
    exit;
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">GST Compliance Reports</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">GST Compliance</li>
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
            id="gst-filter-form"
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
              <a href="<?= e(url('modules/reports/gst_compliance.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
          <div class="mt-3">
            <span class="badge text-bg-light border me-2">Garage: <?= e($scopeGarageLabel); ?></span>
            <span class="badge text-bg-light border me-2">FY: <?= e($fyLabel); ?></span>
            <span class="badge text-bg-light border me-2">Range: <?= e($fromDate); ?> to <?= e($toDate); ?></span>
            <span class="badge text-bg-success">CA-ready GST registers</span>
          </div>
        </div>
      </div>

      <div id="gst-report-content">
        <?php $renderReportBody($salesRows, $salesSummary, $purchaseRows, $purchaseSummary, $baseParams, $canExportData, $chartPayloadJson); ?>
      </div>
    </div>
  </div>
</main>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (!window.GacCharts) {
      return;
    }

    var form = document.getElementById('gst-filter-form');
    var target = document.getElementById('gst-report-content');
    if (!form || !target) {
      return;
    }

    var charts = window.GacCharts.createRegistry('gst-report');

    function renderCharts() {
      var payload = window.GacCharts.parsePayload(target);
      var chartData = payload || {};

      charts.render('#gst-chart-components', {
        type: 'bar',
        data: {
          labels: chartData.tax_components ? chartData.tax_components.labels : [],
          datasets: [{
            label: 'GST Amount',
            data: chartData.tax_components ? chartData.tax_components.values : [],
            backgroundColor: window.GacCharts.pickColors(6)
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true } }
        }
      }, { emptyMessage: 'No GST component rows in selected range.' });

      charts.render('#gst-chart-trend', {
        type: 'line',
        data: {
          labels: chartData.gst_trend ? chartData.gst_trend.labels : [],
          datasets: [{
            label: 'Sales GST',
            data: chartData.gst_trend ? chartData.gst_trend.sales_tax : [],
            borderColor: window.GacCharts.palette.green,
            backgroundColor: window.GacCharts.palette.green + '33',
            fill: true,
            tension: 0.25
          }, {
            label: 'Purchase GST',
            data: chartData.gst_trend ? chartData.gst_trend.purchase_tax : [],
            borderColor: window.GacCharts.palette.blue,
            backgroundColor: window.GacCharts.palette.blue + '33',
            fill: true,
            tension: 0.25
          }]
        },
        options: window.GacCharts.commonOptions()
      }, { emptyMessage: 'No monthly GST trend rows in selected range.' });
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
