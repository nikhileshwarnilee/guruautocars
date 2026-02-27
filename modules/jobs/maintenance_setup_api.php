<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('job.view');
require_once __DIR__ . '/workflow.php';

header('Content-Type: application/json; charset=utf-8');

function maintenance_setup_can_manage(): bool
{
    return has_permission('job.manage') || has_permission('job.edit') || has_permission('job.create');
}

function maintenance_setup_parse_vehicle_ids(mixed $value): array
{
    $ids = [];
    if (is_array($value)) {
        $source = $value;
    } else {
        $source = [];
        $raw = trim((string) $value);
        if ($raw !== '') {
            $source = explode(',', $raw);
        }
    }

    foreach ($source as $rawId) {
        $id = (int) $rawId;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }

    return array_map('intval', array_keys($ids));
}

function maintenance_setup_parse_variant_ids(mixed $value): array
{
    return maintenance_setup_parse_vehicle_ids($value);
}

function maintenance_setup_vis_catalog_ready(): bool
{
    return table_columns('vis_brands') !== []
        && table_columns('vis_models') !== []
        && table_columns('vis_variants') !== [];
}

function maintenance_setup_parse_vehicle_csv(string $csv): array
{
    $items = [];
    foreach (explode(',', $csv) as $rawId) {
        $vehicleId = (int) trim($rawId);
        if ($vehicleId > 0) {
            $items[$vehicleId] = true;
        }
    }

    return array_map('intval', array_keys($items));
}

function maintenance_setup_vehicle_ids_for_variants(int $companyId, array $variantIds): array
{
    if ($companyId <= 0 || !maintenance_setup_vis_catalog_ready()) {
        return [];
    }

    $variantIds = array_values(array_unique(array_filter(array_map('intval', $variantIds), static fn (int $id): bool => $id > 0)));
    if ($variantIds === []) {
        return [];
    }

    $vehicleColumns = table_columns('vehicles');
    $hasVariantId = in_array('variant_id', $vehicleColumns, true);
    $hasVisVariantId = in_array('vis_variant_id', $vehicleColumns, true);
    $hasVehicleVariants = $hasVariantId && table_columns('vehicle_variants') !== [];
    if (!$hasVisVariantId && !$hasVehicleVariants) {
        return [];
    }

    $variantLinkSql = $hasVisVariantId
        ? ($hasVehicleVariants ? 'COALESCE(v.vis_variant_id, mvv.vis_variant_id)' : 'v.vis_variant_id')
        : 'mvv.vis_variant_id';
    $joinSql = $hasVehicleVariants
        ? 'LEFT JOIN vehicle_variants mvv ON mvv.id = v.variant_id'
        : '';
    $placeholders = implode(',', array_fill(0, count($variantIds), '?'));

    try {
        $stmt = db()->prepare(
            'SELECT v.id
             FROM vehicles v
             ' . $joinSql . '
             WHERE v.company_id = ?
               AND v.status_code <> "DELETED"
               AND ' . $variantLinkSql . ' IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$companyId], $variantIds));
        $rows = $stmt->fetchAll() ?: [];
        $vehicleIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
        return array_values(array_unique(array_filter($vehicleIds, static fn (int $id): bool => $id > 0)));
    } catch (Throwable $exception) {
        return [];
    }
}

function maintenance_setup_fetch_variant_rows(
    int $companyId,
    string $query = '',
    int $brandId = 0,
    int $modelId = 0,
    int $variantId = 0,
    int $excludeVehicleId = 0,
    int $limit = 60
): array {
    if ($companyId <= 0 || !maintenance_setup_vis_catalog_ready()) {
        return [];
    }

    $vehicleColumns = table_columns('vehicles');
    $hasVariantId = in_array('variant_id', $vehicleColumns, true);
    $hasVisVariantId = in_array('vis_variant_id', $vehicleColumns, true);
    $hasVehicleVariants = $hasVariantId && table_columns('vehicle_variants') !== [];
    if (!$hasVisVariantId && !$hasVehicleVariants) {
        return [];
    }

    $variantLinkSql = $hasVisVariantId
        ? ($hasVehicleVariants ? 'COALESCE(v.vis_variant_id, mvv.vis_variant_id)' : 'v.vis_variant_id')
        : 'mvv.vis_variant_id';

    $safeLimit = max(1, min(500, $limit));
    $safeQuery = mb_substr(trim($query), 0, 120);

    $where = [
        '(vv.status_code IS NULL OR TRIM(vv.status_code) = "" OR UPPER(TRIM(vv.status_code)) <> "DELETED")',
        '(vm.status_code IS NULL OR TRIM(vm.status_code) = "" OR UPPER(TRIM(vm.status_code)) <> "DELETED")',
        '(vb.status_code IS NULL OR TRIM(vb.status_code) = "" OR UPPER(TRIM(vb.status_code)) <> "DELETED")',
    ];
    $params = ['company_id' => $companyId];

    if ($brandId > 0) {
        $where[] = 'vb.id = :brand_id';
        $params['brand_id'] = $brandId;
    }
    if ($modelId > 0) {
        $where[] = 'vm.id = :model_id';
        $params['model_id'] = $modelId;
    }
    if ($variantId > 0) {
        $where[] = 'vv.id = :variant_id';
        $params['variant_id'] = $variantId;
    }

    if ($safeQuery !== '') {
        $where[] = '(vb.brand_name LIKE :query OR vm.model_name LIKE :query OR vv.variant_name LIKE :query)';
        $params['query'] = '%' . $safeQuery . '%';
    }

    $vehicleVariantJoinSql = $hasVehicleVariants
        ? 'LEFT JOIN vehicle_variants mvv ON mvv.id = v.variant_id'
        : '';
    $vehicleWhereSql = [
        'v.company_id = :company_id',
        'v.status_code = "ACTIVE"',
    ];
    if ($excludeVehicleId > 0) {
        $vehicleWhereSql[] = 'v.id <> :exclude_vehicle_id';
        $params['exclude_vehicle_id'] = $excludeVehicleId;
    }

    $vehicleMapSql =
        'SELECT v.id AS vehicle_id, ' . $variantLinkSql . ' AS vis_variant_id
         FROM vehicles v
         ' . $vehicleVariantJoinSql . '
         WHERE ' . implode(' AND ', $vehicleWhereSql);

    $stmt = db()->prepare(
        'SELECT vv.id AS vis_variant_id,
                vm.id AS vis_model_id,
                vb.id AS vis_brand_id,
                vb.brand_name,
                vm.model_name,
                vv.variant_name,
                MIN(vmap.vehicle_id) AS primary_vehicle_id,
                COUNT(vmap.vehicle_id) AS vehicle_count,
                GROUP_CONCAT(vmap.vehicle_id ORDER BY vmap.vehicle_id ASC) AS vehicle_ids_csv
         FROM vis_variants vv
         INNER JOIN vis_models vm ON vm.id = vv.model_id
         INNER JOIN vis_brands vb ON vb.id = vm.brand_id
         LEFT JOIN (' . $vehicleMapSql . ') vmap ON vmap.vis_variant_id = vv.id
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY vv.id, vm.id, vb.id, vb.brand_name, vm.model_name, vv.variant_name
         ORDER BY vb.brand_name ASC, vm.model_name ASC, vv.variant_name ASC
         LIMIT ' . $safeLimit
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $items = [];
    foreach ($rows as $row) {
        $visVariantId = (int) ($row['vis_variant_id'] ?? 0);
        $visModelId = (int) ($row['vis_model_id'] ?? 0);
        $visBrandId = (int) ($row['vis_brand_id'] ?? 0);
        if ($visVariantId <= 0) {
            continue;
        }

        $vehicleIds = maintenance_setup_parse_vehicle_csv((string) ($row['vehicle_ids_csv'] ?? ''));
        $primaryVehicleId = (int) ($row['primary_vehicle_id'] ?? 0);
        if ($primaryVehicleId <= 0 && $vehicleIds !== []) {
            $primaryVehicleId = (int) ($vehicleIds[0] ?? 0);
        }

        $brandName = trim((string) ($row['brand_name'] ?? ''));
        $modelName = trim((string) ($row['model_name'] ?? ''));
        $variantName = trim((string) ($row['variant_name'] ?? ''));

        $variantLabel = trim(
            $brandName . ' ' .
            $modelName . ' ' .
            $variantName
        );
        $variantKey = $visBrandId . ':' . $visModelId . ':' . $visVariantId;
        $label = $variantLabel !== '' ? $variantLabel : ('Variant #' . $visVariantId);

        $items[] = [
            'id' => $visVariantId,
            'variant_key' => $variantKey,
            'vis_brand_id' => $visBrandId,
            'vis_model_id' => $visModelId,
            'vis_variant_id' => $visVariantId,
            'brand' => $brandName,
            'model' => $modelName,
            'variant' => $variantName,
            'variant_label' => $variantLabel,
            'label' => $label,
            'vehicle_count' => max(0, (int) ($row['vehicle_count'] ?? count($vehicleIds))),
            'primary_vehicle_id' => $primaryVehicleId,
            'vehicle_ids' => $vehicleIds,
        ];
    }

    return $items;
}

function maintenance_setup_vehicle_meta(int $companyId, int $vehicleId): ?array
{
    if ($companyId <= 0 || $vehicleId <= 0) {
        return null;
    }

    $vehicleColumns = table_columns('vehicles');
    $hasVariantId = in_array('variant_id', $vehicleColumns, true);
    $hasVisVariantId = in_array('vis_variant_id', $vehicleColumns, true);
    $hasVehicleVariants = $hasVariantId && table_columns('vehicle_variants') !== [];
    $variantLinkSql = $hasVisVariantId
        ? ($hasVehicleVariants ? 'COALESCE(v.vis_variant_id, mvv.vis_variant_id)' : 'v.vis_variant_id')
        : ($hasVehicleVariants ? 'mvv.vis_variant_id' : '');

    if ($variantLinkSql !== '' && maintenance_setup_vis_catalog_ready()) {
        $joinSql = $hasVehicleVariants
            ? 'LEFT JOIN vehicle_variants mvv ON mvv.id = v.variant_id'
            : '';

        $stmt = db()->prepare(
            'SELECT v.id,
                    vv.id AS vis_variant_id,
                    vm.id AS vis_model_id,
                    vb.id AS vis_brand_id,
                    vb.brand_name,
                    vm.model_name,
                    vv.variant_name
             FROM vehicles v
             ' . $joinSql . '
             INNER JOIN vis_variants vv ON vv.id = ' . $variantLinkSql . '
             INNER JOIN vis_models vm ON vm.id = vv.model_id
             INNER JOIN vis_brands vb ON vb.id = vm.brand_id
             WHERE v.company_id = :company_id
               AND v.id = :vehicle_id
               AND v.status_code = "ACTIVE"
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'vehicle_id' => $vehicleId,
        ]);
        $row = $stmt->fetch();
        if ($row) {
            $brandName = trim((string) ($row['brand_name'] ?? ''));
            $modelName = trim((string) ($row['model_name'] ?? ''));
            $variantName = trim((string) ($row['variant_name'] ?? ''));
            $variantLabel = trim($brandName . ' ' . $modelName . ' ' . $variantName);

            return [
                'id' => $vehicleId,
                'vis_brand_id' => (int) ($row['vis_brand_id'] ?? 0),
                'vis_model_id' => (int) ($row['vis_model_id'] ?? 0),
                'vis_variant_id' => (int) ($row['vis_variant_id'] ?? 0),
                'brand' => $brandName,
                'model' => $modelName,
                'variant' => $variantName,
                'variant_label' => $variantLabel,
                'label' => $variantLabel !== '' ? $variantLabel : ('Variant #' . $vehicleId),
            ];
        }
    }

    $fallback = db()->prepare(
        'SELECT id, brand, model, variant
         FROM vehicles
         WHERE company_id = :company_id
           AND id = :vehicle_id
           AND status_code = "ACTIVE"
         LIMIT 1'
    );
    $fallback->execute([
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
    ]);
    $row = $fallback->fetch();
    if (!$row) {
        return null;
    }

    $brandName = trim((string) ($row['brand'] ?? ''));
    $modelName = trim((string) ($row['model'] ?? ''));
    $variantName = trim((string) ($row['variant'] ?? ''));
    $variantLabel = trim($brandName . ' ' . $modelName . ' ' . $variantName);

    return [
        'id' => $vehicleId,
        'vis_brand_id' => 0,
        'vis_model_id' => 0,
        'vis_variant_id' => 0,
        'brand' => $brandName,
        'model' => $modelName,
        'variant' => $variantName,
        'variant_label' => $variantLabel,
        'label' => $variantLabel !== '' ? $variantLabel : ('Variant #' . $vehicleId),
    ];
}

$companyId = active_company_id();
if ($companyId <= 0) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid company scope.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!service_reminder_feature_ready()) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'message' => 'Maintenance reminder storage is not ready.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $action = strtolower(trim((string) ($_GET['action'] ?? '')));

    if ($action === 'vehicles' || $action === 'source_candidates') {
        $query = trim((string) ($_GET['q'] ?? ''));
        $brandId = get_int('brand_id');
        $modelId = get_int('model_id');
        $variantId = get_int('variant_id');
        $excludeVehicleId = get_int('exclude_vehicle_id');
        $maxLimit = $action === 'vehicles' ? 2000 : 500;
        $limit = max(1, min($maxLimit, get_int('limit', 80)));

        try {
            $items = maintenance_setup_fetch_variant_rows(
                $companyId,
                $query,
                $brandId,
                $modelId,
                $variantId,
                $action === 'source_candidates' ? $excludeVehicleId : 0,
                $limit
            );
            echo json_encode([
                'ok' => true,
                'items' => $items,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $exception) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'message' => 'Unable to load vehicle variants.',
                'items' => [],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($action === 'brands') {
        $query = trim((string) ($_GET['q'] ?? ''));
        $items = [];
        if (maintenance_setup_vis_catalog_ready()) {
            $stmt = db()->prepare(
                'SELECT id, brand_name
                 FROM vis_brands
                 WHERE (status_code IS NULL OR TRIM(status_code) = "" OR UPPER(TRIM(status_code)) <> "DELETED")
                   AND TRIM(brand_name) <> ""
                   ' . ($query !== '' ? 'AND brand_name LIKE :query' : '') . '
                 ORDER BY brand_name ASC
                 LIMIT 300'
            );
            $params = [];
            if ($query !== '') {
                $params['query'] = '%' . $query . '%';
            }
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $id = (int) ($row['id'] ?? 0);
                $name = trim((string) ($row['brand_name'] ?? ''));
                if ($id <= 0 || $name === '') {
                    continue;
                }
                $items[] = [
                    'id' => $id,
                    'name' => $name,
                ];
            }
        } elseif (vehicle_masters_enabled()) {
            $items = array_map(
                static fn (array $row): array => [
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => (string) ($row['brand_name'] ?? ''),
                ],
                vehicle_master_search_brands($query, 300)
            );
        } else {
            $stmt = db()->prepare(
                'SELECT DISTINCT brand
                 FROM vehicles
                 WHERE company_id = :company_id
                   AND status_code = "ACTIVE"
                   AND brand IS NOT NULL
                   AND TRIM(brand) <> ""
                 ORDER BY brand ASC
                 LIMIT 300'
            );
            $stmt->execute(['company_id' => $companyId]);
            $index = 1;
            foreach ($stmt->fetchAll() as $row) {
                $name = trim((string) ($row['brand'] ?? ''));
                if ($name === '') {
                    continue;
                }
                if ($query !== '' && stripos($name, $query) === false) {
                    continue;
                }
                $items[] = [
                    'id' => $index++,
                    'name' => $name,
                ];
            }
        }

        echo json_encode([
            'ok' => true,
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'models') {
        $brandId = get_int('brand_id');
        $query = trim((string) ($_GET['q'] ?? ''));
        $items = [];
        if ($brandId > 0 && maintenance_setup_vis_catalog_ready()) {
            $stmt = db()->prepare(
                'SELECT id, model_name
                 FROM vis_models
                 WHERE brand_id = :brand_id
                   AND (status_code IS NULL OR TRIM(status_code) = "" OR UPPER(TRIM(status_code)) <> "DELETED")
                   AND TRIM(model_name) <> ""
                   ' . ($query !== '' ? 'AND model_name LIKE :query' : '') . '
                 ORDER BY model_name ASC
                 LIMIT 400'
            );
            $params = ['brand_id' => $brandId];
            if ($query !== '') {
                $params['query'] = '%' . $query . '%';
            }
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $id = (int) ($row['id'] ?? 0);
                $name = trim((string) ($row['model_name'] ?? ''));
                if ($id <= 0 || $name === '') {
                    continue;
                }
                $items[] = [
                    'id' => $id,
                    'name' => $name,
                ];
            }

            if ($items === []) {
                $brandNameStmt = db()->prepare('SELECT brand_name FROM vis_brands WHERE id = :brand_id LIMIT 1');
                $brandNameStmt->execute(['brand_id' => $brandId]);
                $brandName = trim((string) ($brandNameStmt->fetchColumn() ?: ''));

                if ($brandName !== '') {
                    $byNameStmt = db()->prepare(
                        'SELECT vm.id, vm.model_name
                         FROM vis_models vm
                         INNER JOIN vis_brands vb ON vb.id = vm.brand_id
                         WHERE LOWER(TRIM(vb.brand_name)) = LOWER(TRIM(:brand_name))
                           AND (vm.status_code IS NULL OR TRIM(vm.status_code) = "" OR UPPER(TRIM(vm.status_code)) <> "DELETED")
                           AND (vb.status_code IS NULL OR TRIM(vb.status_code) = "" OR UPPER(TRIM(vb.status_code)) <> "DELETED")
                           AND TRIM(vm.model_name) <> ""
                           ' . ($query !== '' ? 'AND vm.model_name LIKE :query' : '') . '
                         ORDER BY vm.model_name ASC
                         LIMIT 400'
                    );
                    $byNameParams = ['brand_name' => $brandName];
                    if ($query !== '') {
                        $byNameParams['query'] = '%' . $query . '%';
                    }
                    $byNameStmt->execute($byNameParams);
                    foreach ($byNameStmt->fetchAll() as $row) {
                        $id = (int) ($row['id'] ?? 0);
                        $name = trim((string) ($row['model_name'] ?? ''));
                        if ($id <= 0 || $name === '') {
                            continue;
                        }
                        $items[] = [
                            'id' => $id,
                            'name' => $name,
                        ];
                    }
                }
            }

            if ($items === [] && vehicle_masters_enabled()) {
                $mappedMasterBrandId = 0;
                $mapStmt = db()->prepare('SELECT id FROM vehicle_brands WHERE vis_brand_id = :vis_brand_id LIMIT 1');
                $mapStmt->execute(['vis_brand_id' => $brandId]);
                $mappedMasterBrandId = (int) ($mapStmt->fetchColumn() ?: 0);

                if ($mappedMasterBrandId > 0) {
                    $items = array_map(
                        static fn (array $row): array => [
                            'id' => (int) ($row['id'] ?? 0),
                            'name' => (string) ($row['model_name'] ?? ''),
                        ],
                        vehicle_master_search_models($mappedMasterBrandId, $query, 400)
                    );
                }
            }
        } elseif ($brandId > 0 && vehicle_masters_enabled()) {
            $items = array_map(
                static fn (array $row): array => [
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => (string) ($row['model_name'] ?? ''),
                ],
                vehicle_master_search_models($brandId, $query, 400)
            );
        } elseif ($brandId > 0) {
            $columns = table_columns('vehicles');
            $hasBrandId = in_array('brand_id', $columns, true);
            $hasModelId = in_array('model_id', $columns, true);
            $where = [
                'company_id = :company_id',
                'status_code = "ACTIVE"',
                'model IS NOT NULL',
                'TRIM(model) <> ""',
            ];
            $params = ['company_id' => $companyId];
            if ($hasBrandId) {
                $where[] = 'brand_id = :brand_id';
                $params['brand_id'] = $brandId;
            }
            if ($query !== '') {
                $where[] = 'model LIKE :query';
                $params['query'] = '%' . $query . '%';
            }
            if ($hasModelId) {
                $where[] = 'model_id IS NOT NULL';
                $where[] = 'model_id > 0';
            }
            $selectSql = $hasModelId ? 'model_id AS id, model' : 'model';
            $stmt = db()->prepare(
                'SELECT DISTINCT ' . $selectSql . '
                 FROM vehicles
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY model ASC
                 LIMIT 400'
            );
            $stmt->execute($params);
            $index = 1;
            foreach ($stmt->fetchAll() as $row) {
                $name = trim((string) ($row['model'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $modelId = $hasModelId ? (int) ($row['id'] ?? 0) : $index++;
                if ($modelId <= 0) {
                    continue;
                }
                $items[] = [
                    'id' => $modelId,
                    'name' => $name,
                ];
            }
        }

        echo json_encode([
            'ok' => true,
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'rules') {
        $vehicleId = get_int('vehicle_id');
        $variantId = get_int('variant_id');
        if ($variantId <= 0 && $vehicleId > 0) {
            $variantId = service_reminder_vehicle_vis_variant_id($companyId, $vehicleId);
        }

        if ($variantId <= 0 && $vehicleId <= 0) {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'message' => 'Select a variant first.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $rules = $vehicleId > 0
                ? service_reminder_fetch_rule_grid($companyId, $vehicleId)
                : service_reminder_fetch_rule_grid_for_variant($companyId, $variantId);
            $vehicleRows = $vehicleId > 0 ? service_reminder_vehicle_rule_rows($companyId, $vehicleId, true) : [];
            $variantRows = $variantId > 0 ? service_reminder_variant_rule_rows($companyId, $variantId, true) : [];
            $vehicleMeta = $vehicleId > 0 ? maintenance_setup_vehicle_meta($companyId, $vehicleId) : null;

            echo json_encode([
                'ok' => true,
                'vehicle_id' => $vehicleId,
                'variant_id' => $variantId,
                'vehicle' => $vehicleMeta,
                'has_existing_rules' => !empty($vehicleRows) || !empty($variantRows),
                'rules' => $rules,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $exception) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'message' => 'Unable to load maintenance rules.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Unsupported action.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    require_csrf();
    if (!maintenance_setup_can_manage()) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'message' => 'You do not have permission to modify maintenance rules.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $action = strtolower(trim((string) ($_POST['action'] ?? '')));
    $actorUserId = (int) ($_SESSION['user_id'] ?? 0);

    if ($action === 'save_rules') {
        $vehicleIds = maintenance_setup_parse_vehicle_ids($_POST['vehicle_ids'] ?? []);
        $variantIds = maintenance_setup_parse_variant_ids($_POST['variant_ids'] ?? []);
        $ruleRowsJson = trim((string) ($_POST['rule_rows_json'] ?? ''));
        $ruleRows = json_decode($ruleRowsJson, true);
        if (!is_array($ruleRows)) {
            $ruleRows = [];
        }

        if ($vehicleIds === [] && $variantIds === []) {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'message' => 'Select at least one variant first.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $mappedVehicleIds = maintenance_setup_vehicle_ids_for_variants($companyId, $variantIds);
        $targetVehicleIds = array_values(array_unique(array_merge($vehicleIds, $mappedVehicleIds)));

        $variantResult = [
            'ok' => true,
            'variant_count' => 0,
            'rule_count' => 0,
            'saved_count' => 0,
            'warnings' => [],
        ];
        if ($variantIds !== []) {
            $variantResult = service_reminder_save_rules_for_variants($companyId, $variantIds, $ruleRows, $actorUserId);
        }

        $vehicleResult = [
            'ok' => true,
            'vehicle_count' => 0,
            'rule_count' => 0,
            'saved_count' => 0,
            'warnings' => [],
        ];
        if ($targetVehicleIds !== []) {
            $vehicleResult = service_reminder_save_rules_for_vehicles($companyId, $targetVehicleIds, $ruleRows, $actorUserId);
        }

        $warnings = array_merge(
            (array) ($variantResult['warnings'] ?? []),
            (array) ($vehicleResult['warnings'] ?? [])
        );
        $warnings = array_values(array_filter(array_map(static fn (mixed $warning): string => trim((string) $warning), $warnings), static fn (string $warning): bool => $warning !== ''));

        $ok = ($variantIds === [] || (bool) ($variantResult['ok'] ?? false))
            && ($targetVehicleIds === [] || (bool) ($vehicleResult['ok'] ?? false));

        $result = [
            'ok' => $ok,
            'variant_result' => $variantResult,
            'vehicle_result' => $vehicleResult,
            'warnings' => $warnings,
        ];
        echo json_encode([
            'ok' => (bool) ($result['ok'] ?? false),
            'result' => $result,
            'message' => (bool) ($result['ok'] ?? false)
                ? 'Rules saved for selected variants.'
                : (string) ((array) ($result['warnings'] ?? []) !== [] ? implode(' ', (array) $result['warnings']) : 'Unable to save rules.'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'copy_rules') {
        $legacySourceVehicleId = post_int('source_vehicle_id');
        $sourceVariantId = post_int('source_variant_id');
        if ($sourceVariantId <= 0) {
            if ($legacySourceVehicleId > 0) {
                $sourceVariantId = service_reminder_vehicle_vis_variant_id($companyId, $legacySourceVehicleId);
            }
        }
        $targetVariantIds = maintenance_setup_parse_variant_ids($_POST['target_variant_ids'] ?? []);
        $targetVehicleIdsInput = maintenance_setup_parse_vehicle_ids($_POST['target_vehicle_ids'] ?? []);

        if ($targetVariantIds === [] && $legacySourceVehicleId > 0 && $targetVehicleIdsInput !== []) {
            $legacyResult = service_reminder_copy_rules_between_vehicles($companyId, $legacySourceVehicleId, $targetVehicleIdsInput, $actorUserId);
            echo json_encode([
                'ok' => (bool) ($legacyResult['ok'] ?? false),
                'result' => $legacyResult,
                'message' => (bool) ($legacyResult['ok'] ?? false)
                    ? 'Rules copied successfully.'
                    : (string) ((array) ($legacyResult['warnings'] ?? []) !== [] ? implode(' ', (array) $legacyResult['warnings']) : 'Unable to copy rules.'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $variantCopyResult = service_reminder_copy_rules_between_variants($companyId, $sourceVariantId, $targetVariantIds, $actorUserId);
        $mappedVehicleIds = maintenance_setup_vehicle_ids_for_variants($companyId, $targetVariantIds);
        $targetVehicleIds = array_values(array_unique(array_merge($targetVehicleIdsInput, $mappedVehicleIds)));

        $vehicleSyncResult = [
            'ok' => true,
            'vehicle_count' => 0,
            'rule_count' => 0,
            'saved_count' => 0,
            'warnings' => [],
        ];
        if ((bool) ($variantCopyResult['ok'] ?? false) && $targetVehicleIds !== []) {
            $sourceRows = service_reminder_variant_rule_rows($companyId, $sourceVariantId, true);
            $ruleRows = [];
            foreach ($sourceRows as $row) {
                $itemType = service_reminder_normalize_type((string) ($row['item_type'] ?? ''));
                $itemId = (int) ($row['item_id'] ?? 0);
                if ($itemType === '' || $itemId <= 0) {
                    continue;
                }
                $ruleRows[] = [
                    'item_type' => $itemType,
                    'item_id' => $itemId,
                    'interval_km' => service_reminder_parse_positive_int($row['interval_km'] ?? null),
                    'interval_days' => service_reminder_parse_positive_int($row['interval_days'] ?? null),
                    'is_active' => (int) ($row['is_active'] ?? 0) === 1 && (string) ($row['status_code'] ?? 'ACTIVE') === 'ACTIVE',
                ];
            }
            if ($ruleRows !== []) {
                $vehicleSyncResult = service_reminder_save_rules_for_vehicles($companyId, $targetVehicleIds, $ruleRows, $actorUserId);
            }
        }

        $warnings = array_merge(
            (array) ($variantCopyResult['warnings'] ?? []),
            (array) ($vehicleSyncResult['warnings'] ?? [])
        );
        $warnings = array_values(array_filter(array_map(static fn (mixed $warning): string => trim((string) $warning), $warnings), static fn (string $warning): bool => $warning !== ''));

        $ok = (bool) ($variantCopyResult['ok'] ?? false)
            && ($targetVehicleIds === [] || (bool) ($vehicleSyncResult['ok'] ?? false));
        $result = [
            'ok' => $ok,
            'variant_result' => $variantCopyResult,
            'vehicle_result' => $vehicleSyncResult,
            'warnings' => $warnings,
        ];
        echo json_encode([
            'ok' => (bool) ($result['ok'] ?? false),
            'result' => $result,
            'message' => (bool) ($result['ok'] ?? false)
                ? 'Rules copied successfully.'
                : (string) ((array) ($result['warnings'] ?? []) !== [] ? implode(' ', (array) $result['warnings']) : 'Unable to copy rules.'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete_rule') {
        $vehicleIds = maintenance_setup_parse_vehicle_ids($_POST['vehicle_ids'] ?? []);
        $variantIds = maintenance_setup_parse_variant_ids($_POST['variant_ids'] ?? []);
        $itemType = strtoupper(trim((string) ($_POST['item_type'] ?? '')));
        $itemId = post_int('item_id');
        if ($vehicleIds === [] && $variantIds === []) {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'message' => 'Select target variants.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $mappedVehicleIds = maintenance_setup_vehicle_ids_for_variants($companyId, $variantIds);
        $targetVehicleIds = array_values(array_unique(array_merge($vehicleIds, $mappedVehicleIds)));

        $variantResult = [
            'ok' => true,
            'deleted_count' => 0,
            'warnings' => [],
        ];
        if ($variantIds !== []) {
            $variantResult = service_reminder_delete_rules_for_variants($companyId, $variantIds, $itemType, $itemId, $actorUserId);
        }

        $vehicleResult = [
            'ok' => true,
            'deleted_count' => 0,
            'warnings' => [],
        ];
        if ($targetVehicleIds !== []) {
            $vehicleResult = service_reminder_delete_rules_for_vehicles($companyId, $targetVehicleIds, $itemType, $itemId, $actorUserId);
        }

        $warnings = array_merge(
            (array) ($variantResult['warnings'] ?? []),
            (array) ($vehicleResult['warnings'] ?? [])
        );
        $warnings = array_values(array_filter(array_map(static fn (mixed $warning): string => trim((string) $warning), $warnings), static fn (string $warning): bool => $warning !== ''));

        $ok = ($variantIds === [] || (bool) ($variantResult['ok'] ?? false))
            && ($targetVehicleIds === [] || (bool) ($vehicleResult['ok'] ?? false));

        $result = [
            'ok' => $ok,
            'deleted_count' => (int) ($variantResult['deleted_count'] ?? 0) + (int) ($vehicleResult['deleted_count'] ?? 0),
            'variant_result' => $variantResult,
            'vehicle_result' => $vehicleResult,
            'warnings' => $warnings,
        ];
        echo json_encode([
            'ok' => (bool) ($result['ok'] ?? false),
            'result' => $result,
            'message' => (bool) ($result['ok'] ?? false)
                ? 'Rule deleted for selected variants.'
                : (string) ((array) ($result['warnings'] ?? []) !== [] ? implode(' ', (array) $result['warnings']) : 'Unable to delete rule.'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Unsupported action.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode([
    'ok' => false,
    'message' => 'Method not allowed.',
], JSON_UNESCAPED_UNICODE);
