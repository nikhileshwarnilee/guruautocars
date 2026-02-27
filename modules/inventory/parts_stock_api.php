<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('inventory.view');

header('Content-Type: application/json; charset=utf-8');

function inv_parts_api_accessible_garage_ids(): array
{
    $user = current_user();
    $garages = $user['garages'] ?? [];
    if (!is_array($garages)) {
        return [];
    }

    $ids = [];
    foreach ($garages as $garage) {
        $id = (int) ($garage['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

function inv_parts_api_is_valid_date(?string $date): bool
{
    if ($date === null || $date === '') {
        return false;
    }

    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

function inv_parts_api_format_datetime(?string $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '-';
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return $raw;
    }

    return date('d M Y, h:i A', $timestamp);
}

function inv_parts_api_render_rows(array $parts, string $garageLabel): string
{
    ob_start();

    if ($parts === []) {
        ?>
        <tr><td colspan="13" class="text-center text-muted py-4">No parts found for selected filters.</td></tr>
        <?php
        return (string) ob_get_clean();
    }

    foreach ($parts as $part) {
        $state = (string) ($part['stock_state'] ?? 'OK');
        $badgeClass = $state === 'OUT' ? 'danger' : ($state === 'LOW' ? 'warning' : 'success');
        $label = $state === 'OUT' ? 'Out of Stock' : ($state === 'LOW' ? 'Low Stock' : 'OK');
        ?>
        <tr>
          <td><code><?= e((string) ($part['part_sku'] ?? '')); ?></code></td>
          <td><?= e((string) ($part['part_name'] ?? '')); ?></td>
          <td><?= e((string) ($part['category_name'] ?? '-')); ?></td>
          <td><?= e((string) ($part['vendor_name'] ?? '-')); ?></td>
          <td><?= e($garageLabel); ?></td>
          <td><?= e((string) ($part['hsn_code'] ?? '-')); ?></td>
          <td><?= e(format_currency((float) ($part['selling_price'] ?? 0))); ?></td>
          <td><?= e(number_format((float) ($part['gst_rate'] ?? 0), 2)); ?></td>
          <td><?= e(number_format((float) ($part['min_stock'] ?? 0), 2)); ?> <?= e((string) ($part['unit'] ?? '')); ?></td>
          <td><?= e(number_format((float) ($part['actual_stock_qty'] ?? $part['stock_qty'] ?? 0), 2)); ?> <?= e((string) ($part['unit'] ?? '')); ?></td>
          <td><?= e(number_format((float) ($part['current_stock_qty'] ?? $part['stock_qty'] ?? 0), 2)); ?> <?= e((string) ($part['unit'] ?? '')); ?></td>
          <td><span class="badge text-bg-<?= e($badgeClass); ?>"><?= e($label); ?></span></td>
          <td><?= e(inv_parts_api_format_datetime((string) ($part['last_movement_at'] ?? ''))); ?></td>
        </tr>
        <?php
    }

    return (string) ob_get_clean();
}

$companyId = active_company_id();
$activeGarageId = active_garage_id();
$accessibleGarageIds = inv_parts_api_accessible_garage_ids();
if (!in_array($activeGarageId, $accessibleGarageIds, true) && $activeGarageId > 0) {
    $accessibleGarageIds[] = $activeGarageId;
}
$accessibleGarageIds = array_values(array_unique(array_filter($accessibleGarageIds, static fn (int $id): bool => $id > 0)));

$garageOptions = [];
if ($accessibleGarageIds !== []) {
    $placeholders = implode(',', array_fill(0, count($accessibleGarageIds), '?'));
    $garageStmt = db()->prepare(
        "SELECT id, name, code
         FROM garages
         WHERE company_id = ?
           AND status_code = 'ACTIVE'
           AND status = 'active'
           AND id IN ({$placeholders})
         ORDER BY name ASC"
    );
    $garageStmt->execute(array_merge([$companyId], $accessibleGarageIds));
    $garageOptions = $garageStmt->fetchAll();
}

if ($garageOptions === [] && $activeGarageId > 0) {
    $fallbackGarageStmt = db()->prepare(
        'SELECT id, name, code
         FROM garages
         WHERE id = :id
           AND company_id = :company_id
         LIMIT 1'
    );
    $fallbackGarageStmt->execute([
        'id' => $activeGarageId,
        'company_id' => $companyId,
    ]);
    $fallbackGarage = $fallbackGarageStmt->fetch();
    if ($fallbackGarage) {
        $garageOptions[] = $fallbackGarage;
        $accessibleGarageIds[] = (int) $fallbackGarage['id'];
    }
}

$garageLookup = [];
foreach ($garageOptions as $garageOption) {
    $garageLookup[(int) $garageOption['id']] = [
        'name' => (string) ($garageOption['name'] ?? ''),
        'code' => trim((string) ($garageOption['code'] ?? '')),
    ];
}

$garageId = get_int('garage_id', $activeGarageId);
if (!in_array($garageId, $accessibleGarageIds, true) && $accessibleGarageIds !== []) {
    $garageId = (int) $accessibleGarageIds[0];
}
if ($garageId <= 0) {
    $garageId = $activeGarageId;
}

$partName = trim((string) ($_GET['part_name'] ?? ($_GET['table_search'] ?? '')));
$categoryId = get_int('category_id', 0);
$vendorId = get_int('vendor_id', 0);
$stockLevel = strtoupper(trim((string) ($_GET['stock_level'] ?? '')));
$allowedStockLevels = ['ZERO', 'LOW', 'AVAILABLE'];
if (!in_array($stockLevel, $allowedStockLevels, true)) {
    $stockLevel = '';
}

$lastMovementFrom = trim((string) ($_GET['last_movement_from'] ?? ''));
$lastMovementTo = trim((string) ($_GET['last_movement_to'] ?? ''));
if (!inv_parts_api_is_valid_date($lastMovementFrom)) {
    $lastMovementFrom = '';
}
if (!inv_parts_api_is_valid_date($lastMovementTo)) {
    $lastMovementTo = '';
}
if ($lastMovementFrom !== '' && $lastMovementTo !== '' && strcmp($lastMovementFrom, $lastMovementTo) > 0) {
    [$lastMovementFrom, $lastMovementTo] = [$lastMovementTo, $lastMovementFrom];
}

$pagination = resolve_pagination_request(10, 100);
$page = (int) $pagination['page'];
$perPage = (int) $pagination['per_page'];

$whereParts = [
    'p.company_id = :company_id',
    'p.status_code <> "DELETED"',
];
$params = [
    'company_id' => $companyId,
    'inventory_garage_id' => $garageId,
    'reserved_company_id' => $companyId,
    'reserved_garage_id' => $garageId,
    'movement_company_id' => $companyId,
    'movement_garage_id' => $garageId,
];

$currentStockExpr = '(COALESCE(gi.quantity, 0) - COALESCE(rsv.reserved_qty, 0))';

if ($partName !== '') {
    $whereParts[] = '(
        CAST(p.id AS CHAR) LIKE :part_query
        OR p.part_name LIKE :part_query
        OR p.part_sku LIKE :part_query
        OR p.hsn_code LIKE :part_query
        OR p.unit LIKE :part_query
        OR pc.category_name LIKE :part_query
        OR v.vendor_name LIKE :part_query
    )';
    $params['part_query'] = '%' . $partName . '%';
}

if ($categoryId > 0) {
    $whereParts[] = 'p.category_id = :category_id';
    $params['category_id'] = $categoryId;
}

if ($vendorId > 0) {
    $whereParts[] = 'p.vendor_id = :vendor_id';
    $params['vendor_id'] = $vendorId;
}

if ($stockLevel === 'ZERO') {
    $whereParts[] = $currentStockExpr . ' <= 0';
} elseif ($stockLevel === 'LOW') {
    $whereParts[] = $currentStockExpr . ' > 0 AND ' . $currentStockExpr . ' <= p.min_stock';
} elseif ($stockLevel === 'AVAILABLE') {
    $whereParts[] = $currentStockExpr . ' > p.min_stock';
}

if ($lastMovementFrom !== '') {
    $whereParts[] = 'DATE(lm.last_movement_at) >= :last_movement_from';
    $params['last_movement_from'] = $lastMovementFrom;
}
if ($lastMovementTo !== '') {
    $whereParts[] = 'DATE(lm.last_movement_at) <= :last_movement_to';
    $params['last_movement_to'] = $lastMovementTo;
}

try {
    $stockStateExpr =
        'CASE
            WHEN ' . $currentStockExpr . ' <= 0 THEN "OUT"
            WHEN ' . $currentStockExpr . ' <= p.min_stock THEN "LOW"
            ELSE "OK"
         END';
    $partsFromSql =
        'FROM parts p
         LEFT JOIN part_categories pc ON pc.id = p.category_id
         LEFT JOIN vendors v ON v.id = p.vendor_id
         LEFT JOIN garage_inventory gi
           ON gi.part_id = p.id
           AND gi.garage_id = :inventory_garage_id
         LEFT JOIN (
            SELECT jp.part_id, SUM(jp.quantity) AS reserved_qty
            FROM job_parts jp
            INNER JOIN job_cards jc ON jc.id = jp.job_card_id
            WHERE jc.company_id = :reserved_company_id
              AND jc.garage_id = :reserved_garage_id
              AND jc.status_code = "ACTIVE"
              AND UPPER(COALESCE(jc.status, "OPEN")) NOT IN ("CLOSED", "CANCELLED")
            GROUP BY jp.part_id
         ) rsv ON rsv.part_id = p.id
         LEFT JOIN (
            SELECT part_id, garage_id, MAX(created_at) AS last_movement_at
            FROM inventory_movements
            WHERE company_id = :movement_company_id
            GROUP BY part_id, garage_id
         ) lm ON lm.part_id = p.id
            AND lm.garage_id = :movement_garage_id
         WHERE ' . implode(' AND ', $whereParts);

    $statsSql =
        'SELECT COUNT(*) AS tracked_parts,
                SUM(CASE WHEN ' . $currentStockExpr . ' <= 0 THEN 1 ELSE 0 END) AS out_of_stock_parts,
                SUM(CASE WHEN ' . $currentStockExpr . ' > 0 AND ' . $currentStockExpr . ' <= p.min_stock THEN 1 ELSE 0 END) AS low_stock_parts
         ' . $partsFromSql;
    $statsStmt = db()->prepare($statsSql);
    $statsStmt->execute($params);
    $statsRow = $statsStmt->fetch() ?: [];

    $totalRows = (int) ($statsRow['tracked_parts'] ?? 0);
    $paginationMeta = pagination_payload($totalRows, $page, $perPage);
    $page = (int) $paginationMeta['page'];
    $perPage = (int) $paginationMeta['per_page'];
    $offset = max(0, ($page - 1) * $perPage);

    $partsSql =
        'SELECT p.id, p.part_name, p.part_sku, p.hsn_code, p.unit, p.selling_price, p.gst_rate, p.min_stock,
                pc.category_name,
                v.vendor_name,
                COALESCE(gi.quantity, 0) AS actual_stock_qty,
                COALESCE(rsv.reserved_qty, 0) AS reserved_stock_qty,
                ' . $currentStockExpr . ' AS current_stock_qty,
                ' . $currentStockExpr . ' AS stock_qty,
                lm.last_movement_at,
                ' . $stockStateExpr . ' AS stock_state
         ' . $partsFromSql . '
         ORDER BY p.part_name ASC
         LIMIT ' . $perPage . ' OFFSET ' . $offset;

    $partsStmt = db()->prepare($partsSql);
    $partsStmt->execute($params);
    $parts = $partsStmt->fetchAll();
    $outOfStockParts = (int) ($statsRow['out_of_stock_parts'] ?? 0);
    $lowStockParts = (int) ($statsRow['low_stock_parts'] ?? 0);

    $garageLabel = 'Garage #' . $garageId;
    if (isset($garageLookup[$garageId])) {
        $garageName = (string) $garageLookup[$garageId]['name'];
        $garageCode = (string) $garageLookup[$garageId]['code'];
        $garageLabel = $garageCode !== '' ? ($garageName . ' (' . $garageCode . ')') : $garageName;
    }

    echo json_encode([
        'ok' => true,
        'rows_count' => $totalRows,
        'page_rows_count' => count($parts),
        'pagination' => $paginationMeta,
        'stats' => [
            'tracked_parts' => $totalRows,
            'out_of_stock_parts' => $outOfStockParts,
            'low_stock_parts' => $lowStockParts,
        ],
        'selected_garage_label' => $garageLabel,
        'table_rows_html' => inv_parts_api_render_rows($parts, $garageLabel),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to load parts stock right now.',
    ], JSON_UNESCAPED_UNICODE);
}
