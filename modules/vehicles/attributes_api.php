<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if (
    !has_permission('vehicle.view')
    && !has_permission('job.view')
    && !has_permission('estimate.view')
    && !has_permission('reports.view')
    && !has_permission('report.view')
) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'message' => 'You do not have permission to access vehicle attributes.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$companyId = active_company_id();
$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$query = trim((string) ($_GET['q'] ?? ''));
$limit = get_int('limit', 80);
$limit = max(1, min(500, $limit));

if (!vehicle_masters_enabled()) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'message' => 'Vehicle attribute masters are not available yet. Run vehicle_attribute_masters_upgrade.sql.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'brands') {
    $items = vehicle_master_search_brands($query, $limit);
    echo json_encode([
        'ok' => true,
        'items' => array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['brand_name'] ?? ''),
                'vis_brand_id' => isset($row['vis_brand_id']) ? (int) $row['vis_brand_id'] : null,
            ];
        }, $items),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'models') {
    $brandId = get_int('brand_id');
    $items = $brandId > 0 ? vehicle_master_search_models($brandId, $query, $limit) : [];
    echo json_encode([
        'ok' => true,
        'brand_id' => $brandId,
        'items' => array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'brand_id' => (int) ($row['brand_id'] ?? 0),
                'name' => (string) ($row['model_name'] ?? ''),
                'vehicle_type' => isset($row['vehicle_type']) ? (string) $row['vehicle_type'] : null,
                'vis_model_id' => isset($row['vis_model_id']) ? (int) $row['vis_model_id'] : null,
            ];
        }, $items),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'variants') {
    $modelId = get_int('model_id');
    $items = $modelId > 0 ? vehicle_master_search_variants($modelId, $query, $limit) : [];
    echo json_encode([
        'ok' => true,
        'model_id' => $modelId,
        'items' => array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'model_id' => (int) ($row['model_id'] ?? 0),
                'name' => (string) ($row['variant_name'] ?? ''),
                'fuel_type' => isset($row['fuel_type']) ? (string) $row['fuel_type'] : null,
                'engine_cc' => isset($row['engine_cc']) ? (string) $row['engine_cc'] : null,
                'vis_variant_id' => isset($row['vis_variant_id']) ? (int) $row['vis_variant_id'] : null,
            ];
        }, $items),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'combo') {
    $brandId = get_int('brand_id');
    $modelId = get_int('model_id');
    $variantId = get_int('variant_id');
    $items = vehicle_master_search_combos($query, $limit, [
        'brand_id' => $brandId,
        'model_id' => $modelId,
        'variant_id' => $variantId,
    ]);
    echo json_encode([
        'ok' => true,
        'items' => array_map(static function (array $row): array {
            $brandIdValue = (int) ($row['brand_id'] ?? 0);
            $modelIdValue = (int) ($row['model_id'] ?? 0);
            $variantIdValue = (int) ($row['variant_id'] ?? 0);
            $brandName = trim((string) ($row['brand_name'] ?? ''));
            $modelName = trim((string) ($row['model_name'] ?? ''));
            $variantName = trim((string) ($row['variant_name'] ?? ''));
            $label = trim($brandName . ' -> ' . $modelName . ' -> ' . $variantName);
            $sourceCode = (int) ($row['vis_priority'] ?? 0) === 1 ? 'VIS' : 'MASTER';

            return [
                'id' => $variantIdValue,
                'combo_key' => $brandIdValue . ':' . $modelIdValue . ':' . $variantIdValue,
                'brand_id' => $brandIdValue,
                'model_id' => $modelIdValue,
                'variant_id' => $variantIdValue,
                'brand_name' => $brandName,
                'model_name' => $modelName,
                'variant_name' => $variantName,
                'fuel_type' => isset($row['fuel_type']) ? (string) $row['fuel_type'] : null,
                'engine_cc' => isset($row['engine_cc']) ? (string) $row['engine_cc'] : null,
                'vis_variant_id' => isset($row['vis_variant_id']) ? (int) $row['vis_variant_id'] : null,
                'source_code' => $sourceCode,
                'label' => $label,
            ];
        }, $items),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'years') {
    $items = vehicle_master_search_years($query, $limit);
    echo json_encode([
        'ok' => true,
        'items' => array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'year_value' => (int) ($row['year_value'] ?? 0),
            ];
        }, $items),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'colors') {
    $items = vehicle_master_search_colors($query, $limit);
    echo json_encode([
        'ok' => true,
        'items' => array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['color_name'] ?? ''),
            ];
        }, $items),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'vehicles') {
    $filters = [
        'customer_id' => get_int('customer_id'),
        'brand_id' => get_int('brand_id'),
        'model_id' => get_int('model_id'),
        'variant_id' => get_int('variant_id'),
        'model_year_id' => get_int('model_year_id'),
        'color_id' => get_int('color_id'),
        'q' => $query,
    ];
    $rows = vehicle_master_search_vehicles($companyId, $filters, $limit);

    $items = [];
    foreach ($rows as $row) {
        $vehicleLabel =
            trim((string) ($row['registration_no'] ?? '')) .
            ' - ' .
            trim((string) ($row['brand'] ?? '')) . ' ' .
            trim((string) ($row['model'] ?? '')) .
            ((string) ($row['variant'] ?? '') !== '' ? (' ' . trim((string) $row['variant'])) : '');

        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'customer_id' => (int) ($row['customer_id'] ?? 0),
            'registration_no' => (string) ($row['registration_no'] ?? ''),
            'brand' => (string) ($row['brand'] ?? ''),
            'model' => (string) ($row['model'] ?? ''),
            'variant' => (string) ($row['variant'] ?? ''),
            'model_year' => isset($row['model_year']) ? (int) $row['model_year'] : null,
            'color' => isset($row['color']) ? (string) $row['color'] : null,
            'brand_id' => isset($row['brand_id']) ? (int) $row['brand_id'] : null,
            'model_id' => isset($row['model_id']) ? (int) $row['model_id'] : null,
            'variant_id' => isset($row['variant_id']) ? (int) $row['variant_id'] : null,
            'model_year_id' => isset($row['model_year_id']) ? (int) $row['model_year_id'] : null,
            'color_id' => isset($row['color_id']) ? (int) $row['color_id'] : null,
            'label' => trim($vehicleLabel),
        ];
    }

    echo json_encode([
        'ok' => true,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode([
    'ok' => false,
    'message' => 'Unsupported action.',
], JSON_UNESCAPED_UNICODE);
