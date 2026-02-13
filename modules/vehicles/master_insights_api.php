<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('vehicle.view');

header('Content-Type: application/json; charset=utf-8');

function vehicle_master_format_datetime(?string $value): string
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

function vehicle_master_render_rows(array $vehicles, bool $canManage): string
{
    ob_start();

    if ($vehicles === []) {
        ?>
        <tr><td colspan="9" class="text-center text-muted py-4">No vehicles found for selected filters.</td></tr>
        <?php
        return (string) ob_get_clean();
    }

    foreach ($vehicles as $vehicle) {
        $statusCode = (string) ($vehicle['status_code'] ?? 'ACTIVE');
        $activeJobCount = (int) ($vehicle['active_job_count'] ?? 0);
        $serviceCount = (int) ($vehicle['service_count'] ?? 0);

        ?>
        <tr>
          <td><?= (int) ($vehicle['id'] ?? 0); ?></td>
          <td><?= e((string) ($vehicle['registration_no'] ?? '')); ?></td>
          <td>
            <?= e((string) ($vehicle['brand'] ?? '')); ?> <?= e((string) ($vehicle['model'] ?? '')); ?> <?= e((string) ($vehicle['variant'] ?? '')); ?><br>
            <small class="text-muted"><?= e((string) ($vehicle['vehicle_type'] ?? '-')); ?> | <?= e((string) ($vehicle['fuel_type'] ?? '-')); ?></small>
          </td>
          <td>
            <?= e((string) ($vehicle['customer_name'] ?? '-')); ?><br>
            <small class="text-muted"><?= e((string) ($vehicle['customer_phone'] ?? '-')); ?></small>
          </td>
          <td>
            <?php if ((int) ($vehicle['vis_variant_id'] ?? 0) > 0): ?>
              <span class="badge text-bg-info">Linked</span><br>
              <small class="text-muted"><?= e((string) (($vehicle['vis_brand_name'] ?? '-') . ' / ' . ($vehicle['vis_model_name'] ?? '-') . ' / ' . ($vehicle['vis_variant_name'] ?? '-'))); ?></small>
            <?php else: ?>
              <span class="badge text-bg-secondary">Not Linked</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge text-bg-light border me-1">Total <?= $serviceCount; ?></span>
            <span class="badge text-bg-<?= $activeJobCount > 0 ? 'warning' : 'secondary'; ?>">Active <?= $activeJobCount; ?></span>
          </td>
          <td>
            <?= (int) ($vehicle['history_count'] ?? 0); ?><br>
            <small class="text-muted">Last Service: <?= e(vehicle_master_format_datetime((string) ($vehicle['last_service_at'] ?? ''))); ?></small>
          </td>
          <td><span class="badge text-bg-<?= e(status_badge_class($statusCode)); ?>"><?= e(record_status_label($statusCode)); ?></span></td>
          <td class="d-flex gap-1">
            <a class="btn btn-sm btn-outline-dark" href="<?= e(url('modules/vehicles/intelligence.php?id=' . (int) ($vehicle['id'] ?? 0))); ?>">Intel</a>
            <a class="btn btn-sm btn-outline-info" href="<?= e(url('modules/vehicles/index.php?history_id=' . (int) ($vehicle['id'] ?? 0))); ?>">History</a>
            <?php if ($canManage): ?>
              <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/vehicles/index.php?edit_id=' . (int) ($vehicle['id'] ?? 0))); ?>">Edit</a>
              <?php if ($statusCode !== 'DELETED'): ?>
                <form method="post" class="d-inline" data-confirm="Change vehicle status?">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="change_status" />
                  <input type="hidden" name="vehicle_id" value="<?= (int) ($vehicle['id'] ?? 0); ?>" />
                  <input type="hidden" name="next_status" value="<?= e($statusCode === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'); ?>" />
                  <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $statusCode === 'ACTIVE' ? 'Inactivate' : 'Activate'; ?></button>
                </form>
                <form method="post" class="d-inline" data-confirm="Soft delete this vehicle?">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="change_status" />
                  <input type="hidden" name="vehicle_id" value="<?= (int) ($vehicle['id'] ?? 0); ?>" />
                  <input type="hidden" name="next_status" value="DELETED" />
                  <button type="submit" class="btn btn-sm btn-outline-danger">Soft Delete</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php
    }

    return (string) ob_get_clean();
}

$companyId = active_company_id();
$canManage = has_permission('vehicle.manage');
$vehicleAttributeEnabled = vehicle_masters_enabled() && vehicle_master_link_columns_supported();
$jobCardColumns = table_columns('job_cards');
$jobOdometerEnabled = in_array('odometer_km', $jobCardColumns, true);

$search = trim((string) ($_GET['q'] ?? ($_GET['table_search'] ?? '')));
$statusFilter = strtoupper(trim((string) ($_GET['status'] ?? '')));
$allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED', 'ALL'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$customerFilterId = get_int('vehicle_filter_customer_id');
$fuelTypeFilter = strtoupper(trim((string) ($_GET['vehicle_filter_fuel_type'] ?? '')));
$allowedFuelTypes = ['PETROL', 'DIESEL', 'CNG', 'EV', 'HYBRID', 'OTHER'];
if (!in_array($fuelTypeFilter, $allowedFuelTypes, true)) {
    $fuelTypeFilter = '';
}

$brandFilterId = get_int('vehicle_filter_brand_id');
$modelFilterId = get_int('vehicle_filter_model_id');
$variantFilterId = get_int('vehicle_filter_variant_id');
$modelYearFilterId = get_int('vehicle_filter_model_year_id');
$colorFilterId = get_int('vehicle_filter_color_id');

$lastServiceFrom = trim((string) ($_GET['last_service_from'] ?? ''));
$lastServiceTo = trim((string) ($_GET['last_service_to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastServiceFrom)) {
    $lastServiceFrom = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastServiceTo)) {
    $lastServiceTo = '';
}
if ($lastServiceFrom !== '' && $lastServiceTo !== '' && strcmp($lastServiceFrom, $lastServiceTo) > 0) {
    [$lastServiceFrom, $lastServiceTo] = [$lastServiceTo, $lastServiceFrom];
}

$pagination = resolve_pagination_request(10, 100);
$page = (int) $pagination['page'];
$perPage = (int) $pagination['per_page'];

$jobSummarySql =
    'SELECT jc.vehicle_id,
            COUNT(*) AS total_jobs,
            SUM(CASE WHEN jc.status_code = "ACTIVE" AND jc.status NOT IN ("CLOSED", "CANCELLED") THEN 1 ELSE 0 END) AS open_jobs,
            MAX(CASE WHEN jc.status IN ("CLOSED", "COMPLETED")
                     THEN COALESCE(jc.closed_at, jc.completed_at, jc.updated_at, jc.opened_at, jc.created_at)
                END) AS last_service_at
     FROM job_cards jc
     WHERE jc.company_id = :job_company_id
       AND jc.status_code <> "DELETED"
     GROUP BY jc.vehicle_id';

$whereParts = ['v.company_id = :company_id'];
$params = [
    'company_id' => $companyId,
    'job_company_id' => $companyId,
];

if ($search !== '') {
    $whereParts[] = '(v.registration_no LIKE :query OR v.brand LIKE :query OR v.model LIKE :query OR v.variant LIKE :query OR c.full_name LIKE :query)';
    $params['query'] = '%' . $search . '%';
}

if ($statusFilter === '') {
    $whereParts[] = 'v.status_code <> "DELETED"';
} elseif ($statusFilter !== 'ALL') {
    $whereParts[] = 'v.status_code = :status_code';
    $params['status_code'] = $statusFilter;
}

if ($customerFilterId > 0) {
    $whereParts[] = 'v.customer_id = :customer_filter_id';
    $params['customer_filter_id'] = $customerFilterId;
}

if ($fuelTypeFilter !== '') {
    $whereParts[] = 'v.fuel_type = :fuel_type_filter';
    $params['fuel_type_filter'] = $fuelTypeFilter;
}

if ($vehicleAttributeEnabled) {
    if ($brandFilterId > 0) {
        $whereParts[] = 'v.brand_id = :brand_filter_id';
        $params['brand_filter_id'] = $brandFilterId;
    }
    if ($modelFilterId > 0) {
        $whereParts[] = 'v.model_id = :model_filter_id';
        $params['model_filter_id'] = $modelFilterId;
    }
    if ($variantFilterId > 0) {
        $whereParts[] = 'v.variant_id = :variant_filter_id';
        $params['variant_filter_id'] = $variantFilterId;
    }
    if ($modelYearFilterId > 0) {
        $whereParts[] = 'v.model_year_id = :model_year_filter_id';
        $params['model_year_filter_id'] = $modelYearFilterId;
    }
    if ($colorFilterId > 0) {
        $whereParts[] = 'v.color_id = :color_filter_id';
        $params['color_filter_id'] = $colorFilterId;
    }
}

if ($lastServiceFrom !== '') {
    $whereParts[] = 'js.last_service_at IS NOT NULL AND DATE(js.last_service_at) >= :last_service_from';
    $params['last_service_from'] = $lastServiceFrom;
}
if ($lastServiceTo !== '') {
    $whereParts[] = 'js.last_service_at IS NOT NULL AND DATE(js.last_service_at) <= :last_service_to';
    $params['last_service_to'] = $lastServiceTo;
}

try {
        $historyCountExpr = '(SELECT COUNT(*) FROM vehicle_history h WHERE h.vehicle_id = v.id)';
        if ($jobOdometerEnabled) {
                $historyCountExpr =
                        '(
                            (SELECT COUNT(*) FROM vehicle_history vh WHERE vh.vehicle_id = v.id)
                            +
                            (SELECT COUNT(*) FROM job_cards jc WHERE jc.vehicle_id = v.id AND jc.company_id = v.company_id AND jc.status_code <> "DELETED")
                        )';
        }

    $statsSql =
        'SELECT COUNT(*) AS total_vehicles,
                SUM(CASE WHEN COALESCE(js.open_jobs, 0) > 0 THEN 1 ELSE 0 END) AS vehicles_with_active_jobs,
                SUM(CASE WHEN js.last_service_at IS NOT NULL AND js.last_service_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS recently_serviced_vehicles
         FROM vehicles v
         INNER JOIN customers c ON c.id = v.customer_id
         LEFT JOIN (' . $jobSummarySql . ') js ON js.vehicle_id = v.id
         WHERE ' . implode(' AND ', $whereParts);

    $statsStmt = db()->prepare($statsSql);
    $statsStmt->execute($params);
    $statsRow = $statsStmt->fetch() ?: [];
    $totalRows = (int) ($statsRow['total_vehicles'] ?? 0);
    $paginationMeta = pagination_payload($totalRows, $page, $perPage);
    $page = (int) $paginationMeta['page'];
    $perPage = (int) $paginationMeta['per_page'];
    $offset = max(0, ($page - 1) * $perPage);

    $listSql =
        'SELECT v.*, c.full_name AS customer_name, c.phone AS customer_phone,
                COALESCE(js.total_jobs, 0) AS service_count,
                COALESCE(js.open_jobs, 0) AS active_job_count,
                js.last_service_at,
                ' . $historyCountExpr . ' AS history_count,
                vv.variant_name AS vis_variant_name, vm.model_name AS vis_model_name, vb.brand_name AS vis_brand_name
         FROM vehicles v
         INNER JOIN customers c ON c.id = v.customer_id
         LEFT JOIN (' . $jobSummarySql . ') js ON js.vehicle_id = v.id
         LEFT JOIN vis_variants vv ON vv.id = v.vis_variant_id
         LEFT JOIN vis_models vm ON vm.id = vv.model_id
         LEFT JOIN vis_brands vb ON vb.id = vm.brand_id
         WHERE ' . implode(' AND ', $whereParts) . '
         ORDER BY v.id DESC
         LIMIT ' . $perPage . ' OFFSET ' . $offset;

    $listStmt = db()->prepare($listSql);
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll();

    echo json_encode([
        'ok' => true,
        'stats' => [
            'total_vehicles' => (int) ($statsRow['total_vehicles'] ?? 0),
            'vehicles_with_active_jobs' => (int) ($statsRow['vehicles_with_active_jobs'] ?? 0),
            'recently_serviced_vehicles' => (int) ($statsRow['recently_serviced_vehicles'] ?? 0),
        ],
        'rows_count' => $totalRows,
        'page_rows_count' => count($rows),
        'pagination' => $paginationMeta,
        'table_rows_html' => vehicle_master_render_rows($rows, $canManage),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to load vehicle insights right now.',
    ], JSON_UNESCAPED_UNICODE);
}
