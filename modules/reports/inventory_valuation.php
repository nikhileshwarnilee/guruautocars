<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';

reports_require_access();
if (!(has_permission('inventory.view') || has_permission('reports.view') || has_permission('report.view'))) {
    flash_set('access_denied', 'You do not have permission to view inventory valuation report.', 'danger');
    redirect('modules/reports/index.php');
}

$page_title = 'Inventory Valuation Report';
$active_menu = 'reports.inventory_valuation';

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

$asOnDate = trim((string) ($_GET['as_on'] ?? $toDate));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOnDate)) {
    $asOnDate = $toDate;
}
$search = trim((string) ($_GET['q'] ?? ''));

$pageParams = array_merge($baseParams, [
    'as_on' => $asOnDate,
    'q' => $search !== '' ? $search : null,
]);

$stockParams = [
    'company_id' => $companyId,
    'as_on_dt' => $asOnDate . ' 23:59:59',
];
$stockScopeSql = analytics_garage_scope_sql('im.garage_id', $selectedGarageId, $garageIds, $stockParams, 'val_stock_scope');
$stockWhere = [
    'im.company_id = :company_id',
    'im.created_at <= :as_on_dt',
    'p.company_id = :company_id',
    'p.status_code = "ACTIVE"',
];
if ($search !== '') {
    $stockWhere[] = '(p.part_name LIKE :q_like OR p.part_sku LIKE :q_like)';
    $stockParams['q_like'] = '%' . $search . '%';
}
$stockWhereSql = implode(' AND ', $stockWhere);

$stockStmt = db()->prepare(
    'SELECT p.id AS part_id, p.part_name, p.part_sku, p.unit, p.purchase_price,
            COALESCE(pc.category_name, "Uncategorized") AS category_name,
            COALESCE(SUM(CASE
              WHEN im.movement_type = "IN" THEN ABS(im.quantity)
              WHEN im.movement_type = "OUT" THEN -1 * ABS(im.quantity)
              ELSE im.quantity
            END), 0) AS stock_qty
     FROM inventory_movements im
     INNER JOIN parts p ON p.id = im.part_id
     LEFT JOIN part_categories pc ON pc.id = p.category_id
     WHERE ' . $stockWhereSql . '\n       ' . $stockScopeSql . '
     GROUP BY p.id, p.part_name, p.part_sku, p.unit, p.purchase_price, category_name
     HAVING stock_qty > 0
     ORDER BY p.part_name ASC'
);
$stockStmt->execute($stockParams);
$stockRows = $stockStmt->fetchAll();

$partIds = array_values(array_unique(array_map(static fn (array $row): int => (int) ($row['part_id'] ?? 0), $stockRows)));

$purchaseQtyMap = [];
$purchaseValueMap = [];
$purchaseLotsMap = [];
$purchaseHistoryMap = [];
if (!empty($partIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($partIds), '?'));

    $purchaseParams = [
        'company_id' => $companyId,
        'as_on_date' => $asOnDate,
    ];
    $purchaseScopeSql = analytics_garage_scope_sql('pu.garage_id', $selectedGarageId, $garageIds, $purchaseParams, 'val_purchase_scope');
    $purchaseStmt = db()->prepare(
        'SELECT pi.part_id, pu.purchase_date, pi.quantity, pi.unit_cost
         FROM purchase_items pi
         INNER JOIN purchases pu ON pu.id = pi.purchase_id
         WHERE pu.company_id = :company_id
           AND pu.purchase_status = "FINALIZED"
           AND pu.purchase_date <= :as_on_date
           AND pi.part_id IN (' . $inPlaceholders . ')\n           ' . $purchaseScopeSql . '
         ORDER BY pi.part_id ASC, pu.purchase_date ASC, pi.id ASC'
    );
    $purchaseStmt->execute(array_merge($purchaseParams, $partIds));
    foreach ($purchaseStmt->fetchAll() as $row) {
        $partId = (int) ($row['part_id'] ?? 0);
        $qty = (float) ($row['quantity'] ?? 0);
        $cost = (float) ($row['unit_cost'] ?? 0);
        if ($partId <= 0 || $qty <= 0) {
            continue;
        }

        $purchaseQtyMap[$partId] = ($purchaseQtyMap[$partId] ?? 0.0) + $qty;
        $purchaseValueMap[$partId] = ($purchaseValueMap[$partId] ?? 0.0) + ($qty * $cost);
        $purchaseLotsMap[$partId][] = [
            'qty' => $qty,
            'cost' => $cost,
            'date' => (string) ($row['purchase_date'] ?? ''),
        ];
        $purchaseHistoryMap[$partId][] = [
            'date' => (string) ($row['purchase_date'] ?? ''),
            'cost' => $cost,
        ];
    }
}

$outQtyMap = [];
if (!empty($partIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($partIds), '?'));

    $outParams = [
        'company_id' => $companyId,
        'as_on_dt' => $asOnDate . ' 23:59:59',
    ];
    $outScopeSql = analytics_garage_scope_sql('im.garage_id', $selectedGarageId, $garageIds, $outParams, 'val_out_scope');
    $outStmt = db()->prepare(
        'SELECT im.part_id, COALESCE(SUM(ABS(im.quantity)), 0) AS out_qty
         FROM inventory_movements im
         WHERE im.company_id = :company_id
           AND im.created_at <= :as_on_dt
           AND im.movement_type = "OUT"
           AND im.part_id IN (' . $inPlaceholders . ')\n           ' . $outScopeSql . '
         GROUP BY im.part_id'
    );
    $outStmt->execute(array_merge($outParams, $partIds));
    foreach ($outStmt->fetchAll() as $row) {
        $outQtyMap[(int) ($row['part_id'] ?? 0)] = (float) ($row['out_qty'] ?? 0);
    }
}

$valuationRows = [];
$totals = [
    'stock_qty' => 0.0,
    'weighted_value' => 0.0,
    'fifo_value' => 0.0,
];

foreach ($stockRows as $row) {
    $partId = (int) ($row['part_id'] ?? 0);
    $stockQty = round((float) ($row['stock_qty'] ?? 0), 2);
    if ($partId <= 0 || $stockQty <= 0) {
        continue;
    }

    $purchaseQty = (float) ($purchaseQtyMap[$partId] ?? 0);
    $purchaseValue = (float) ($purchaseValueMap[$partId] ?? 0);
    $avgCost = $purchaseQty > 0.0001
        ? round($purchaseValue / $purchaseQty, 4)
        : round((float) ($row['purchase_price'] ?? 0), 4);
    if ($avgCost < 0) {
        $avgCost = 0.0;
    }

    $weightedValue = round($stockQty * $avgCost, 2);

    $outQty = (float) ($outQtyMap[$partId] ?? 0);
    $lots = $purchaseLotsMap[$partId] ?? [];
    $consumeQty = max(0.0, $outQty);
    $remainingLotQty = 0.0;
    $remainingLotValue = 0.0;
    foreach ($lots as $lot) {
        $lotQty = (float) ($lot['qty'] ?? 0);
        $lotCost = (float) ($lot['cost'] ?? 0);
        if ($lotQty <= 0) {
            continue;
        }

        if ($consumeQty >= $lotQty) {
            $consumeQty -= $lotQty;
            continue;
        }

        $lotLeft = $lotQty - $consumeQty;
        $consumeQty = 0.0;
        $remainingLotQty += $lotLeft;
        $remainingLotValue += ($lotLeft * $lotCost);
    }

    if ($stockQty > $remainingLotQty) {
        $remainingLotValue += (($stockQty - $remainingLotQty) * $avgCost);
    }
    $fifoValue = round(max(0.0, $remainingLotValue), 2);

    $history = $purchaseHistoryMap[$partId] ?? [];
    usort($history, static fn (array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
    $historySummary = [];
    foreach (array_slice($history, 0, 3) as $hist) {
        $historySummary[] = (string) ($hist['date'] ?? '-') . ' @ ' . number_format((float) ($hist['cost'] ?? 0), 2);
    }

    $valuationRows[] = [
        'part_name' => (string) ($row['part_name'] ?? ''),
        'part_sku' => (string) ($row['part_sku'] ?? ''),
        'category_name' => (string) ($row['category_name'] ?? ''),
        'unit' => (string) ($row['unit'] ?? ''),
        'stock_qty' => $stockQty,
        'avg_cost' => $avgCost,
        'weighted_value' => $weightedValue,
        'fifo_value' => $fifoValue,
        'purchase_qty' => round($purchaseQty, 2),
        'history_summary' => $historySummary !== [] ? implode(' | ', $historySummary) : '-',
    ];

    $totals['stock_qty'] += $stockQty;
    $totals['weighted_value'] += $weightedValue;
    $totals['fifo_value'] += $fifoValue;
}

$totals['stock_qty'] = round($totals['stock_qty'], 2);
$totals['weighted_value'] = round($totals['weighted_value'], 2);
$totals['fifo_value'] = round($totals['fifo_value'], 2);

$exportKey = trim((string) ($_GET['export'] ?? ''));
if ($exportKey !== '') {
    if (!$canExportData) {
        http_response_code(403);
        exit('Export access denied.');
    }
    if ($exportKey === 'valuation') {
        $timestamp = date('Ymd_His');
        $csvRows = array_map(static fn (array $row): array => [
            (string) ($row['part_name'] ?? ''),
            (string) ($row['part_sku'] ?? ''),
            (string) ($row['category_name'] ?? ''),
            (string) ($row['unit'] ?? ''),
            (float) ($row['stock_qty'] ?? 0),
            (float) ($row['avg_cost'] ?? 0),
            (float) ($row['weighted_value'] ?? 0),
            (float) ($row['fifo_value'] ?? 0),
            (float) ($row['purchase_qty'] ?? 0),
            (string) ($row['history_summary'] ?? ''),
        ], $valuationRows);
        reports_csv_download(
            'inventory_valuation_' . $timestamp . '.csv',
            ['Part', 'SKU', 'Category', 'Unit', 'Total Qty', 'Avg Purchase Cost', 'Weighted Value', 'FIFO Value', 'Total Purchased Qty', 'Purchase History'],
            $csvRows
        );
    }
    flash_set('report_error', 'Unknown export requested.', 'warning');
    redirect('modules/reports/inventory_valuation.php?' . http_build_query(reports_compact_query_params($pageParams)));
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Inventory Valuation Report</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Inventory Valuation</li>
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
              <div class="col-md-3"><label class="form-label">Garage Scope</label><select name="garage_id" class="form-select" data-searchable-select="1"><?php if ($allowAllGarages): ?><option value="0" <?= $selectedGarageId === 0 ? 'selected' : ''; ?>>All Accessible Garages</option><?php endif; ?><?php foreach ($garageOptions as $garage): ?><option value="<?= (int) $garage['id']; ?>" <?= ((int) $garage['id'] === $selectedGarageId) ? 'selected' : ''; ?>><?= e((string) $garage['name']); ?> (<?= e((string) $garage['code']); ?>)</option><?php endforeach; ?></select></div>
            <?php else: ?>
              <div class="col-md-3"><label class="form-label">Garage Scope</label><input type="text" class="form-control" value="<?= e($scopeGarageLabel); ?>" readonly><input type="hidden" name="garage_id" value="<?= (int) $selectedGarageId; ?>"></div>
            <?php endif; ?>
            <div class="col-md-2"><label class="form-label">Financial Year</label><select name="fy_id" class="form-select"><?php foreach ($financialYears as $fy): ?><option value="<?= (int) ($fy['id'] ?? 0); ?>" <?= ((int) ($fy['id'] ?? 0) === $selectedFyId) ? 'selected' : ''; ?>><?= e((string) ($fy['fy_label'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Date Mode</label><select name="date_mode" class="form-select"><?php foreach ($dateModeOptions as $modeValue => $modeLabel): ?><option value="<?= e((string) $modeValue); ?>" <?= $dateMode === $modeValue ? 'selected' : ''; ?>><?= e((string) $modeLabel); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" required></div>
            <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" required></div>
            <div class="col-md-2"><label class="form-label">As On Date</label><input type="date" name="as_on" class="form-control" value="<?= e($asOnDate); ?>" required></div>
            <div class="col-md-3"><label class="form-label">Part Search</label><input type="text" name="q" class="form-control" value="<?= e($search); ?>" placeholder="Part name or SKU"></div>
            <div class="col-md-2 d-flex gap-2"><button type="submit" class="btn btn-primary">Apply</button><a href="<?= e(url('modules/reports/inventory_valuation.php')); ?>" class="btn btn-outline-secondary">Reset</a></div>
          </form>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="small-box text-bg-primary"><div class="inner"><h4><?= number_format(count($valuationRows)); ?></h4><p>Products In Stock</p></div><span class="small-box-icon"><i class="bi bi-box-seam"></i></span></div></div>
        <div class="col-md-4"><div class="small-box text-bg-success"><div class="inner"><h4><?= e(number_format((float) $totals['stock_qty'], 2)); ?></h4><p>Total Qty</p></div><span class="small-box-icon"><i class="bi bi-stack"></i></span></div></div>
        <div class="col-md-4"><div class="small-box text-bg-info"><div class="inner"><h4><?= e(format_currency((float) $totals['fifo_value'])); ?></h4><p>FIFO Stock Value</p></div><span class="small-box-icon"><i class="bi bi-graph-up-arrow"></i></span></div></div>
      </div>

      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Product Valuation (FIFO + Weighted Average)</h3>
          <a href="<?= e(reports_export_url('modules/reports/inventory_valuation.php', $pageParams, 'valuation')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Part</th>
                <th>SKU</th>
                <th>Category</th>
                <th>Unit</th>
                <th>Total Qty</th>
                <th>Avg Purchase Cost</th>
                <th>Weighted Value</th>
                <th>FIFO Value</th>
                <th>Purchase Qty</th>
                <th>Purchase History Summary</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($valuationRows)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">No valuation data found for selected filters.</td></tr>
              <?php else: ?>
                <?php foreach ($valuationRows as $row): ?>
                  <tr>
                    <td><?= e((string) ($row['part_name'] ?? '')); ?></td>
                    <td><?= e((string) ($row['part_sku'] ?? '')); ?></td>
                    <td><?= e((string) ($row['category_name'] ?? '')); ?></td>
                    <td><?= e((string) ($row['unit'] ?? '')); ?></td>
                    <td><?= e(number_format((float) ($row['stock_qty'] ?? 0), 2)); ?></td>
                    <td><?= e(number_format((float) ($row['avg_cost'] ?? 0), 4)); ?></td>
                    <td><?= e(format_currency((float) ($row['weighted_value'] ?? 0))); ?></td>
                    <td><?= e(format_currency((float) ($row['fifo_value'] ?? 0))); ?></td>
                    <td><?= e(number_format((float) ($row['purchase_qty'] ?? 0), 2)); ?></td>
                    <td><?= e((string) ($row['history_summary'] ?? '-')); ?></td>
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
