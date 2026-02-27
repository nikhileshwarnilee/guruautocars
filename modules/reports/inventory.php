<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';

reports_require_access();

$page_title = 'Inventory Reports';
$active_menu = 'reports.inventory';

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
$fromDateTime = (string) $scope['from_datetime'];
$toDateTime = (string) $scope['to_datetime'];
$canExportData = (bool) $scope['can_export_data'];
$baseParams = $scope['base_params'];

$stockValuationParams = ['company_id' => $companyId];
$stockValuationScopeSql = analytics_garage_scope_sql('g.id', $selectedGarageId, $garageIds, $stockValuationParams, 'stock_scope');
$stockValuationStmt = db()->prepare(
    'SELECT g.name AS garage_name,
            COALESCE(SUM(gi.quantity), 0) AS total_qty,
            COALESCE(SUM(gi.quantity * p.purchase_price), 0) AS stock_value,
            COALESCE(SUM(CASE WHEN gi.quantity <= p.min_stock THEN 1 ELSE 0 END), 0) AS low_stock_parts
     FROM garages g
     LEFT JOIN garage_inventory gi ON gi.garage_id = g.id
     LEFT JOIN parts p ON p.id = gi.part_id AND p.company_id = :company_id AND p.status_code = "ACTIVE"
     WHERE g.company_id = :company_id
       AND (g.status_code IS NULL OR g.status_code = "ACTIVE")
       ' . $stockValuationScopeSql . '
     GROUP BY g.id, g.name
     ORDER BY stock_value DESC'
);
$stockValuationStmt->execute($stockValuationParams);
$stockValuationRows = $stockValuationStmt->fetchAll();

$movementSummaryParams = ['company_id' => $companyId, 'from_dt' => $fromDateTime, 'to_dt' => $toDateTime];
$movementSummaryScopeSql = analytics_garage_scope_sql('im.garage_id', $selectedGarageId, $garageIds, $movementSummaryParams, 'mv_scope');
$movementSummaryStmt = db()->prepare(
    'SELECT im.movement_type, im.reference_type,
            COUNT(*) AS movement_count,
            COALESCE(SUM(CASE WHEN im.movement_type = "OUT" THEN -1 * ABS(im.quantity) WHEN im.movement_type = "IN" THEN ABS(im.quantity) ELSE im.quantity END), 0) AS signed_qty
     FROM inventory_movements im
     INNER JOIN parts p ON p.id = im.part_id
     INNER JOIN garages g ON g.id = im.garage_id
     LEFT JOIN job_cards jc_ref ON jc_ref.id = im.reference_id AND im.reference_type = "JOB_CARD"
     LEFT JOIN inventory_transfers tr ON tr.id = im.reference_id AND im.reference_type = "TRANSFER"
     WHERE im.company_id = :company_id
       AND p.company_id = :company_id
       AND p.status_code = "ACTIVE"
       AND g.company_id = :company_id
       AND (g.status_code IS NULL OR g.status_code = "ACTIVE")
       ' . $movementSummaryScopeSql . '
       AND im.created_at BETWEEN :from_dt AND :to_dt
       AND (im.reference_type <> "JOB_CARD" OR (jc_ref.id IS NOT NULL AND jc_ref.company_id = :company_id AND jc_ref.status = "CLOSED" AND jc_ref.status_code = "ACTIVE"))
       AND (im.reference_type <> "TRANSFER" OR (tr.id IS NOT NULL AND tr.company_id = :company_id AND tr.status_code = "POSTED"))
     GROUP BY im.movement_type, im.reference_type
     ORDER BY im.movement_type ASC, im.reference_type ASC'
);
$movementSummaryStmt->execute($movementSummaryParams);
$movementSummaryRows = $movementSummaryStmt->fetchAll();

$fastStockParams = ['company_id' => $companyId, 'from_dt' => $fromDateTime, 'to_dt' => $toDateTime];
$fastStockScopeSql = analytics_garage_scope_sql('im.garage_id', $selectedGarageId, $garageIds, $fastStockParams, 'fast_stock_scope');
$fastStockStmt = db()->prepare(
    'SELECT p.part_name, p.part_sku, p.unit,
            COUNT(*) AS movement_count,
            COALESCE(SUM(im.quantity), 0) AS out_qty
     FROM inventory_movements im
     INNER JOIN parts p ON p.id = im.part_id
     INNER JOIN garages g ON g.id = im.garage_id
     LEFT JOIN job_cards jc_ref ON jc_ref.id = im.reference_id AND im.reference_type = "JOB_CARD"
     LEFT JOIN inventory_transfers tr ON tr.id = im.reference_id AND im.reference_type = "TRANSFER"
     WHERE im.company_id = :company_id
       AND p.company_id = :company_id
       AND p.status_code = "ACTIVE"
       AND g.company_id = :company_id
       AND (g.status_code IS NULL OR g.status_code = "ACTIVE")
       ' . $fastStockScopeSql . '
       AND im.created_at BETWEEN :from_dt AND :to_dt
       AND im.movement_type = "OUT"
       AND (im.reference_type <> "JOB_CARD" OR (jc_ref.id IS NOT NULL AND jc_ref.company_id = :company_id AND jc_ref.status = "CLOSED" AND jc_ref.status_code = "ACTIVE"))
       AND (im.reference_type <> "TRANSFER" OR (tr.id IS NOT NULL AND tr.company_id = :company_id AND tr.status_code = "POSTED"))
     GROUP BY p.id, p.part_name, p.part_sku, p.unit
     ORDER BY out_qty DESC
     LIMIT 15'
);
$fastStockStmt->execute($fastStockParams);
$fastMovingStockRows = $fastStockStmt->fetchAll();

$deadStockParams = ['company_id' => $companyId, 'from_dt' => $fromDateTime, 'to_dt' => $toDateTime];
$deadStockScopeSql = analytics_garage_scope_sql('gi.garage_id', $selectedGarageId, $garageIds, $deadStockParams, 'dead_stock_scope');
$deadMovementScopeSql = analytics_garage_scope_sql('im.garage_id', $selectedGarageId, $garageIds, $deadStockParams, 'dead_mv_scope');
$deadStockStmt = db()->prepare(
    'SELECT p.part_name, p.part_sku, p.unit,
            stock.stock_qty,
            stock.stock_value
     FROM parts p
     INNER JOIN (
        SELECT gi.part_id,
               COALESCE(SUM(gi.quantity), 0) AS stock_qty,
               COALESCE(SUM(gi.quantity * p2.purchase_price), 0) AS stock_value
        FROM garage_inventory gi
        INNER JOIN parts p2 ON p2.id = gi.part_id
          AND p2.company_id = :company_id
          AND p2.status_code = "ACTIVE"
        INNER JOIN garages g2 ON g2.id = gi.garage_id
          AND g2.company_id = :company_id
          AND (g2.status_code IS NULL OR g2.status_code = "ACTIVE")
        WHERE 1 = 1
          ' . $deadStockScopeSql . '
        GROUP BY gi.part_id
     ) stock ON stock.part_id = p.id
     LEFT JOIN (
        SELECT im.part_id
        FROM inventory_movements im
        INNER JOIN parts p3 ON p3.id = im.part_id
        INNER JOIN garages g3 ON g3.id = im.garage_id
        LEFT JOIN job_cards jc_ref ON jc_ref.id = im.reference_id AND im.reference_type = "JOB_CARD"
        LEFT JOIN inventory_transfers tr ON tr.id = im.reference_id AND im.reference_type = "TRANSFER"
        WHERE im.company_id = :company_id
          AND p3.company_id = :company_id
          AND p3.status_code = "ACTIVE"
          AND g3.company_id = :company_id
          AND (g3.status_code IS NULL OR g3.status_code = "ACTIVE")
          ' . $deadMovementScopeSql . '
          AND im.created_at BETWEEN :from_dt AND :to_dt
          AND im.movement_type = "OUT"
          AND (
              im.reference_type <> "JOB_CARD"
              OR (jc_ref.id IS NOT NULL AND jc_ref.company_id = :company_id AND jc_ref.status = "CLOSED" AND jc_ref.status_code = "ACTIVE")
          )
          AND (
              im.reference_type <> "TRANSFER"
              OR (tr.id IS NOT NULL AND tr.company_id = :company_id AND tr.status_code = "POSTED")
          )
        GROUP BY im.part_id
     ) used ON used.part_id = p.id
     WHERE p.company_id = :company_id
       AND p.status_code = "ACTIVE"
       AND stock.stock_qty > 0
       AND used.part_id IS NULL
     ORDER BY stock.stock_value DESC
     LIMIT 15'
);
$deadStockStmt->execute($deadStockParams);
$deadStockRows = $deadStockStmt->fetchAll();

$partsUsageParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$partsUsageScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $partsUsageParams, 'parts_scope');
$partsUsageStmt = db()->prepare(
    'SELECT p.part_name, p.part_sku, p.unit,
            COUNT(DISTINCT jc.id) AS jobs_count,
            COALESCE(SUM(jp.quantity), 0) AS total_qty,
            COALESCE(SUM(jp.total_amount), 0) AS usage_value
     FROM job_parts jp
     INNER JOIN job_cards jc ON jc.id = jp.job_card_id
     INNER JOIN invoices i ON i.job_card_id = jc.id
     INNER JOIN parts p ON p.id = jp.part_id
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND i.company_id = :company_id
       AND i.invoice_status = "FINALIZED"
       AND p.company_id = :company_id
       AND p.status_code = "ACTIVE"
       ' . $partsUsageScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
     GROUP BY p.id, p.part_name, p.part_sku, p.unit
     ORDER BY total_qty DESC
     LIMIT 20'
);
$partsUsageStmt->execute($partsUsageParams);
$partsUsageRows = $partsUsageStmt->fetchAll();

$buildMonthSeries = static function (string $startDate, string $endDate): array {
    $labels = [];
    $cursor = strtotime(date('Y-m-01', strtotime($startDate)));
    $limit = strtotime(date('Y-m-01', strtotime($endDate)));
    while ($cursor !== false && $limit !== false && $cursor <= $limit) {
        $labels[] = date('Y-m', $cursor);
        $cursor = strtotime('+1 month', $cursor);
    }
    return $labels;
};

$valuationTrendParams = ['company_id' => $companyId, 'from_dt' => $fromDateTime, 'to_dt' => $toDateTime];
$valuationTrendScopeSql = analytics_garage_scope_sql('im.garage_id', $selectedGarageId, $garageIds, $valuationTrendParams, 'inv_val_scope');
$valuationTrendStmt = db()->prepare(
    'SELECT DATE_FORMAT(im.created_at, "%Y-%m") AS movement_month,
            COALESCE(SUM(CASE
                WHEN im.movement_type = "IN" THEN ABS(im.quantity) * p.purchase_price
                WHEN im.movement_type = "OUT" THEN -1 * ABS(im.quantity) * p.purchase_price
                ELSE im.quantity * p.purchase_price
            END), 0) AS value_delta
     FROM inventory_movements im
     INNER JOIN parts p ON p.id = im.part_id
     LEFT JOIN job_cards jc_ref ON jc_ref.id = im.reference_id AND im.reference_type = "JOB_CARD"
     LEFT JOIN inventory_transfers tr ON tr.id = im.reference_id AND im.reference_type = "TRANSFER"
     WHERE im.company_id = :company_id
       AND p.company_id = :company_id
       AND p.status_code = "ACTIVE"
       ' . $valuationTrendScopeSql . '
       AND im.created_at BETWEEN :from_dt AND :to_dt
       AND (
         im.reference_type <> "JOB_CARD"
         OR (jc_ref.id IS NOT NULL AND jc_ref.company_id = :company_id AND jc_ref.status = "CLOSED" AND jc_ref.status_code = "ACTIVE")
       )
       AND (
         im.reference_type <> "TRANSFER"
         OR (tr.id IS NOT NULL AND tr.company_id = :company_id AND tr.status_code = "POSTED")
       )
     GROUP BY DATE_FORMAT(im.created_at, "%Y-%m")
     ORDER BY movement_month ASC'
);
$valuationTrendStmt->execute($valuationTrendParams);
$valuationTrendRows = $valuationTrendStmt->fetchAll();

$categoryStockParams = ['company_id' => $companyId];
$categoryStockScopeSql = analytics_garage_scope_sql('gi.garage_id', $selectedGarageId, $garageIds, $categoryStockParams, 'inv_cat_scope');
$categoryStockStmt = db()->prepare(
    'SELECT COALESCE(pc.category_name, "Uncategorized") AS category_name,
            COALESCE(SUM(gi.quantity * p.purchase_price), 0) AS stock_value
     FROM garage_inventory gi
     INNER JOIN parts p ON p.id = gi.part_id
     INNER JOIN garages g ON g.id = gi.garage_id
     LEFT JOIN part_categories pc ON pc.id = p.category_id
     WHERE p.company_id = :company_id
       AND p.status_code = "ACTIVE"
       AND g.company_id = :company_id
       AND (g.status_code IS NULL OR g.status_code = "ACTIVE")
       ' . $categoryStockScopeSql . '
     GROUP BY category_name
     ORDER BY stock_value DESC'
);
$categoryStockStmt->execute($categoryStockParams);
$categoryStockRows = $categoryStockStmt->fetchAll();

$lowStockGraphParams = ['company_id' => $companyId];
if ($selectedGarageId > 0) {
    $lowStockGraphJoinScopeSql = ' AND gi.garage_id = :low_stock_garage ';
    $lowStockGraphParams['low_stock_garage'] = $selectedGarageId;
} elseif (empty($garageIds)) {
    $lowStockGraphJoinScopeSql = ' AND 1 = 0 ';
} else {
    $placeholders = [];
    foreach ($garageIds as $index => $garageId) {
        $key = 'low_stock_scope_' . $index;
        $lowStockGraphParams[$key] = $garageId;
        $placeholders[] = ':' . $key;
    }
    $lowStockGraphJoinScopeSql = ' AND gi.garage_id IN (' . implode(', ', $placeholders) . ') ';
}

$lowStockGraphStmt = db()->prepare(
    'SELECT p.part_name,
            p.part_sku,
            p.min_stock,
            COALESCE(SUM(gi.quantity), 0) AS current_qty,
            GREATEST(p.min_stock - COALESCE(SUM(gi.quantity), 0), 0) AS deficit_qty
     FROM parts p
     LEFT JOIN garage_inventory gi ON gi.part_id = p.id ' . $lowStockGraphJoinScopeSql . '
     WHERE p.company_id = :company_id
       AND p.status_code = "ACTIVE"
     GROUP BY p.id, p.part_name, p.part_sku, p.min_stock
     HAVING current_qty <= p.min_stock
     ORDER BY deficit_qty DESC
     LIMIT 10'
);
$lowStockGraphStmt->execute($lowStockGraphParams);
$lowStockGraphRows = $lowStockGraphStmt->fetchAll();

$totalStockValue = array_reduce($stockValuationRows, static fn (float $sum, array $row): float => $sum + (float) ($row['stock_value'] ?? 0), 0.0);
$totalLowStockParts = array_reduce($stockValuationRows, static fn (int $sum, array $row): int => $sum + (int) ($row['low_stock_parts'] ?? 0), 0);
$movementEntries = array_reduce($movementSummaryRows, static fn (int $sum, array $row): int => $sum + (int) ($row['movement_count'] ?? 0), 0);
$usageValueTotal = array_reduce($partsUsageRows, static fn (float $sum, array $row): float => $sum + (float) ($row['usage_value'] ?? 0), 0.0);

$fastStockCount = count($fastMovingStockRows);
$deadStockCount = count($deadStockRows);

$monthLabels = $buildMonthSeries($fromDate, $toDate);
$valuationDeltaMap = [];
foreach ($valuationTrendRows as $row) {
    $label = (string) ($row['movement_month'] ?? '');
    if ($label === '') {
        continue;
    }
    $valuationDeltaMap[$label] = (float) ($row['value_delta'] ?? 0);
}
$valuationTrendValues = [];
$runningValuation = 0.0;
foreach ($monthLabels as $label) {
    $runningValuation += (float) ($valuationDeltaMap[$label] ?? 0);
    $valuationTrendValues[] = round($runningValuation, 2);
}

$chartPayload = [
    'stock_valuation_trend' => [
        'labels' => $monthLabels,
        'values' => $valuationTrendValues,
    ],
    'fast_vs_dead' => [
        'labels' => ['Fast Moving', 'Dead Stock'],
        'values' => [$fastStockCount, $deadStockCount],
    ],
    'category_distribution' => [
        'labels' => array_map(static fn (array $row): string => (string) ($row['category_name'] ?? ''), $categoryStockRows),
        'values' => array_map(static fn (array $row): float => (float) ($row['stock_value'] ?? 0), $categoryStockRows),
    ],
    'low_stock' => [
        'labels' => array_map(static fn (array $row): string => (string) ($row['part_name'] ?? ''), $lowStockGraphRows),
        'values' => array_map(static fn (array $row): float => (float) ($row['deficit_qty'] ?? 0), $lowStockGraphRows),
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
        case 'stock_valuation':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['garage_name'] ?? ''),
                    (float) ($row['total_qty'] ?? 0),
                    (float) ($row['stock_value'] ?? 0),
                    (int) ($row['low_stock_parts'] ?? 0),
                ],
                $stockValuationRows
            );
            reports_csv_download('inventory_stock_valuation_' . $timestamp . '.csv', ['Garage', 'Total Qty', 'Stock Value', 'Low Stock Parts'], $rows);

        case 'movement_summary':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['movement_type'] ?? ''),
                    (string) ($row['reference_type'] ?? ''),
                    (int) ($row['movement_count'] ?? 0),
                    (float) ($row['signed_qty'] ?? 0),
                ],
                $movementSummaryRows
            );
            reports_csv_download('inventory_movement_summary_' . $timestamp . '.csv', ['Movement Type', 'Reference Type', 'Entries', 'Signed Qty'], $rows);

        case 'fast_moving_stock':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['part_name'] ?? ''),
                    (string) ($row['part_sku'] ?? ''),
                    (int) ($row['movement_count'] ?? 0),
                    (float) ($row['out_qty'] ?? 0),
                ],
                $fastMovingStockRows
            );
            reports_csv_download('inventory_fast_moving_' . $timestamp . '.csv', ['Part', 'SKU/Part No', 'Movement Count', 'OUT Qty'], $rows);

        case 'dead_stock':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['part_name'] ?? ''),
                    (string) ($row['part_sku'] ?? ''),
                    (float) ($row['stock_qty'] ?? 0),
                    (float) ($row['stock_value'] ?? 0),
                ],
                $deadStockRows
            );
            reports_csv_download('inventory_dead_stock_' . $timestamp . '.csv', ['Part', 'SKU/Part No', 'Stock Qty', 'Stock Value'], $rows);

        case 'parts_usage':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['part_name'] ?? ''),
                    (string) ($row['part_sku'] ?? ''),
                    (int) ($row['jobs_count'] ?? 0),
                    (float) ($row['total_qty'] ?? 0),
                    (float) ($row['usage_value'] ?? 0),
                ],
                $partsUsageRows
            );
            reports_csv_download('inventory_parts_usage_' . $timestamp . '.csv', ['Part', 'SKU/Part No', 'Jobs', 'Qty', 'Usage Value'], $rows);

        default:
            flash_set('report_error', 'Unknown export requested.', 'warning');
            redirect('modules/reports/inventory.php?' . http_build_query(reports_compact_query_params($baseParams)));
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Inventory Reports</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Inventory</li>
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
            id="inventory-report-filter-form"
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
              <a href="<?= e(url('modules/reports/inventory.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
          <div class="mt-3">
            <span class="badge text-bg-light border me-2">Garage: <?= e($scopeGarageLabel); ?></span>
            <span class="badge text-bg-light border me-2">FY: <?= e($fyLabel); ?></span>
            <span class="badge text-bg-light border me-2">Range: <?= e($fromDate); ?> to <?= e($toDate); ?></span>
            <span class="badge text-bg-success">Trusted Data: Valid Inventory Movements</span>
          </div>
        </div>
      </div>

      <div id="inventory-report-content">
        <script type="application/json" data-chart-payload><?= $chartPayloadJson ?: '{}'; ?></script>

      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-primary"><i class="bi bi-box-seam"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Stock Value</span>
              <span class="info-box-number"><?= e(format_currency($totalStockValue)); ?></span>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-warning"><i class="bi bi-exclamation-triangle"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Low Stock Parts</span>
              <span class="info-box-number"><?= number_format($totalLowStockParts); ?></span>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-info"><i class="bi bi-arrow-left-right"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Movement Entries</span>
              <span class="info-box-number"><?= number_format($movementEntries); ?></span>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-success"><i class="bi bi-currency-rupee"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Parts Usage Value</span>
              <span class="info-box-number"><?= e(format_currency($usageValueTotal)); ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header"><h3 class="card-title mb-0">Stock Valuation Trend</h3></div>
            <div class="card-body">
              <div class="gac-chart-wrap"><canvas id="inventory-chart-valuation"></canvas></div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header"><h3 class="card-title mb-0">Fast Moving vs Dead Stock</h3></div>
            <div class="card-body">
              <div class="gac-chart-wrap"><canvas id="inventory-chart-fast-dead"></canvas></div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header"><h3 class="card-title mb-0">Category-wise Stock Distribution</h3></div>
            <div class="card-body">
              <div class="gac-chart-wrap"><canvas id="inventory-chart-category"></canvas></div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header"><h3 class="card-title mb-0">Low Stock Items Summary</h3></div>
            <div class="card-body">
              <div class="gac-chart-wrap"><canvas id="inventory-chart-low-stock"></canvas></div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Stock Valuation per Garage</h3>
              <a href="<?= e(reports_export_url('modules/reports/inventory.php', $baseParams, 'stock_valuation')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Garage</th><th>Qty</th><th>Value</th><th>Low Stock</th></tr></thead>
                <tbody>
                  <?php if (empty($stockValuationRows)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No stock valuation rows.</td></tr>
                  <?php else: foreach ($stockValuationRows as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['garage_name'] ?? '')); ?></td>
                      <td><?= e(number_format((float) ($row['total_qty'] ?? 0), 2)); ?></td>
                      <td><?= e(format_currency((float) ($row['stock_value'] ?? 0))); ?></td>
                      <td><?= (int) ($row['low_stock_parts'] ?? 0); ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Movement Summary</h3>
              <a href="<?= e(reports_export_url('modules/reports/inventory.php', $baseParams, 'movement_summary')); ?>" class="btn btn-sm btn-outline-info">CSV</a>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Type</th><th>Source</th><th>Entries</th><th>Signed Qty</th></tr></thead>
                <tbody>
                  <?php if (empty($movementSummaryRows)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No movement rows.</td></tr>
                  <?php else: foreach ($movementSummaryRows as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['movement_type'] ?? '')); ?></td>
                      <td><?= e((string) ($row['reference_type'] ?? '')); ?></td>
                      <td><?= (int) ($row['movement_count'] ?? 0); ?></td>
                      <td><?= e(number_format((float) ($row['signed_qty'] ?? 0), 2)); ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Fast-Moving Stock</h3>
              <a href="<?= e(reports_export_url('modules/reports/inventory.php', $baseParams, 'fast_moving_stock')); ?>" class="btn btn-sm btn-outline-success">CSV</a>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Part</th><th>Moves</th><th>OUT Qty</th></tr></thead>
                <tbody>
                  <?php if (empty($fastMovingStockRows)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No fast-moving parts.</td></tr>
                  <?php else: foreach ($fastMovingStockRows as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['part_name'] ?? '')); ?> <small class="text-muted">(<?= e((string) ($row['part_sku'] ?? '')); ?>)</small></td>
                      <td><?= (int) ($row['movement_count'] ?? 0); ?></td>
                      <td><?= e(number_format((float) ($row['out_qty'] ?? 0), 2)); ?> <?= e((string) ($row['unit'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Dead Stock</h3>
              <a href="<?= e(reports_export_url('modules/reports/inventory.php', $baseParams, 'dead_stock')); ?>" class="btn btn-sm btn-outline-warning">CSV</a>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Part</th><th>Stock Qty</th><th>Stock Value</th></tr></thead>
                <tbody>
                  <?php if (empty($deadStockRows)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No dead stock under selected scope.</td></tr>
                  <?php else: foreach ($deadStockRows as $row): ?>
                    <tr>
                      <td><?= e((string) ($row['part_name'] ?? '')); ?> <small class="text-muted">(<?= e((string) ($row['part_sku'] ?? '')); ?>)</small></td>
                      <td><?= e(number_format((float) ($row['stock_qty'] ?? 0), 2)); ?> <?= e((string) ($row['unit'] ?? '')); ?></td>
                      <td><?= e(format_currency((float) ($row['stock_value'] ?? 0))); ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Job Consumption Parts Usage</h3>
          <a href="<?= e(reports_export_url('modules/reports/inventory.php', $baseParams, 'parts_usage')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a>
        </div>
        <div class="card-body p-0 table-responsive">
          <table class="table table-sm table-striped mb-0">
            <thead><tr><th>Part</th><th>Jobs</th><th>Total Qty</th><th>Usage Value</th></tr></thead>
            <tbody>
              <?php if (empty($partsUsageRows)): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No billed parts usage in selected range.</td></tr>
              <?php else: foreach ($partsUsageRows as $row): ?>
                <tr>
                  <td><?= e((string) ($row['part_name'] ?? '')); ?> <small class="text-muted">(<?= e((string) ($row['part_sku'] ?? '')); ?>)</small></td>
                  <td><?= (int) ($row['jobs_count'] ?? 0); ?></td>
                  <td><?= e(number_format((float) ($row['total_qty'] ?? 0), 2)); ?> <?= e((string) ($row['unit'] ?? '')); ?></td>
                  <td><?= e(format_currency((float) ($row['usage_value'] ?? 0))); ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      </div>
    </div>
  </div>
</main>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (!window.GacCharts) {
      return;
    }

    var form = document.getElementById('inventory-report-filter-form');
    var content = document.getElementById('inventory-report-content');
    if (!form || !content) {
      return;
    }

    var charts = window.GacCharts.createRegistry('inventory-report');

    function renderCharts() {
      var payload = window.GacCharts.parsePayload(content);
      var chartData = payload || {};

      charts.render('#inventory-chart-valuation', {
        type: 'line',
        data: {
          labels: chartData.stock_valuation_trend ? chartData.stock_valuation_trend.labels : [],
          datasets: [{
            label: 'Stock Value Trend',
            data: chartData.stock_valuation_trend ? chartData.stock_valuation_trend.values : [],
            borderColor: window.GacCharts.palette.blue,
            backgroundColor: window.GacCharts.palette.blue + '33',
            fill: true,
            tension: 0.25
          }]
        },
        options: window.GacCharts.commonOptions()
      }, { emptyMessage: 'No stock valuation movements in selected range.' });

      charts.render('#inventory-chart-fast-dead', {
        type: 'bar',
        data: {
          labels: chartData.fast_vs_dead ? chartData.fast_vs_dead.labels : [],
          datasets: [{
            label: 'Part Count',
            data: chartData.fast_vs_dead ? chartData.fast_vs_dead.values : [],
            backgroundColor: [window.GacCharts.palette.green, window.GacCharts.palette.orange]
          }]
        },
        options: window.GacCharts.commonOptions()
      }, { emptyMessage: 'No stock speed classification data available.' });

      charts.render('#inventory-chart-category', {
        type: 'pie',
        data: {
          labels: chartData.category_distribution ? chartData.category_distribution.labels : [],
          datasets: [{
            data: chartData.category_distribution ? chartData.category_distribution.values : [],
            backgroundColor: window.GacCharts.pickColors(12)
          }]
        },
        options: window.GacCharts.commonOptions()
      }, { emptyMessage: 'No category-wise stock rows available.' });

      charts.render('#inventory-chart-low-stock', {
        type: 'bar',
        data: {
          labels: chartData.low_stock ? chartData.low_stock.labels : [],
          datasets: [{
            label: 'Deficit Quantity',
            data: chartData.low_stock ? chartData.low_stock.values : [],
            backgroundColor: window.GacCharts.palette.red
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true } }
        }
      }, { emptyMessage: 'No low-stock items in selected scope.' });
    }

    renderCharts();

    window.GacCharts.bindAjaxForm({
      form: form,
      target: content,
      mode: 'full',
      sourceSelector: '#inventory-report-content',
      afterUpdate: function () {
        renderCharts();
      }
    });
  });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

