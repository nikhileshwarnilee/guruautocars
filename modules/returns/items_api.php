<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/workflow.php';

if (
    !has_permission('inventory.view')
    && !has_permission('billing.view')
    && !has_permission('purchase.view')
    && !has_permission('report.view')
) {
    ajax_json([
        'ok' => false,
        'message' => 'Access denied.',
    ], 403);
}

if (!returns_module_ready()) {
    ajax_json([
        'ok' => false,
        'message' => 'Returns module is not ready.',
    ], 500);
}

$companyId = active_company_id();
$garageId = active_garage_id();
$returnType = returns_normalize_type((string) ($_GET['return_type'] ?? 'CUSTOMER_RETURN'));
$sourceId = get_int('source_id');

if ($sourceId <= 0) {
    ajax_json([
        'ok' => false,
        'message' => 'Source document is required.',
    ], 422);
}

try {
    $items = returns_fetch_source_items_for_document(db(), $companyId, $garageId, $returnType, $sourceId);

    $sourceMeta = [];
    if ($returnType === 'CUSTOMER_RETURN') {
        $sourceStmt = db()->prepare(
            'SELECT i.id, i.invoice_number, i.invoice_date, i.job_card_id, i.customer_id,
                    c.full_name AS customer_name, c.phone AS customer_phone,
                    v.registration_no, jc.job_number
             FROM invoices i
             LEFT JOIN customers c ON c.id = i.customer_id
             LEFT JOIN vehicles v ON v.id = i.vehicle_id
             LEFT JOIN job_cards jc ON jc.id = i.job_card_id
             WHERE i.id = :id
               AND i.company_id = :company_id
               AND i.garage_id = :garage_id
             LIMIT 1'
        );
        $sourceStmt->execute([
            'id' => $sourceId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $sourceMeta = $sourceStmt->fetch() ?: [];
    } else {
        $sourceStmt = db()->prepare(
            'SELECT p.id, p.invoice_number, p.purchase_date, p.vendor_id,
                    v.vendor_name, v.contact_person, v.phone
             FROM purchases p
             LEFT JOIN vendors v ON v.id = p.vendor_id
             WHERE p.id = :id
               AND p.company_id = :company_id
               AND p.garage_id = :garage_id
             LIMIT 1'
        );
        $sourceStmt->execute([
            'id' => $sourceId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $sourceMeta = $sourceStmt->fetch() ?: [];
    }

    $normalizedItems = [];
    foreach ($items as $item) {
        $partUnitCode = part_unit_normalize_code((string) ($item['part_unit'] ?? ''));
        $normalizedItems[] = [
            'source_item_id' => (int) ($item['source_item_id'] ?? 0),
            'part_id' => (int) ($item['part_id'] ?? 0),
            'part_name' => (string) ($item['part_name'] ?? ''),
            'part_sku' => (string) ($item['part_sku'] ?? ''),
            'part_unit' => $partUnitCode,
            'allow_decimal_qty' => part_unit_allows_decimal($companyId, $partUnitCode),
            'description' => (string) ($item['description'] ?? ''),
            'source_qty' => returns_round((float) ($item['source_qty'] ?? 0)),
            'reserved_qty' => returns_round((float) ($item['reserved_qty'] ?? 0)),
            'max_returnable_qty' => returns_round((float) ($item['max_returnable_qty'] ?? 0)),
            'unit_price' => returns_round((float) ($item['unit_price'] ?? 0)),
            'gst_rate' => returns_round((float) ($item['gst_rate'] ?? 0)),
        ];
    }

    ajax_json([
        'ok' => true,
        'return_type' => $returnType,
        'source_id' => $sourceId,
        'source' => $sourceMeta,
        'items' => $normalizedItems,
    ]);
} catch (Throwable $exception) {
    ajax_json([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], 500);
}
