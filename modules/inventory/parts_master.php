<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('part_master.view');

$page_title = 'Parts / Item Master';
$active_menu = 'inventory.parts_master';
$canManage = has_permission('part_master.manage');
$canViewPartCategories = has_permission('part_category.view');
$companyId = active_company_id();
$garageId = active_garage_id();
$unitOptions = part_unit_active_options($companyId);
$unitOptionsList = array_values($unitOptions);
usort(
    $unitOptionsList,
    static fn (array $left, array $right): int => strcmp((string) ($left['code'] ?? ''), (string) ($right['code'] ?? ''))
);

function part_quantity_has_fraction(float $quantity): bool
{
    return abs($quantity - round($quantity)) > 0.00001;
}

$partColumns = table_columns('parts');
$hasEnableReminder = in_array('enable_reminder', $partColumns, true);
if (!$hasEnableReminder) {
    try {
        db()->exec('ALTER TABLE parts ADD COLUMN enable_reminder TINYINT(1) NOT NULL DEFAULT 0');
        $hasEnableReminder = in_array('enable_reminder', table_columns('parts'), true);
    } catch (Throwable $exception) {
        $hasEnableReminder = false;
    }
}

$visCompatibilityEnabled = table_columns('vis_part_compatibility') !== []
    && table_columns('vis_variants') !== []
    && table_columns('vis_models') !== []
    && table_columns('vis_brands') !== [];

$visVariants = [];
$visVariantLookup = [];
if ($visCompatibilityEnabled) {
    $visVariants = db()->query(
        'SELECT vv.id, vv.variant_name, vm.model_name, vb.brand_name
         FROM vis_variants vv
         INNER JOIN vis_models vm ON vm.id = vv.model_id
         INNER JOIN vis_brands vb ON vb.id = vm.brand_id
         WHERE vv.status_code = "ACTIVE"
           AND vm.status_code = "ACTIVE"
           AND vb.status_code = "ACTIVE"
         ORDER BY vb.brand_name ASC, vm.model_name ASC, vv.variant_name ASC'
    )->fetchAll();

    foreach ($visVariants as $variant) {
        $variantId = (int) ($variant['id'] ?? 0);
        if ($variantId > 0) {
            $visVariantLookup[$variantId] = true;
        }
    }
}

$categoriesStmt = db()->prepare(
    'SELECT id, category_name, category_code
     FROM part_categories
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY category_name ASC'
);
$categoriesStmt->execute(['company_id' => $companyId]);
$categories = $categoriesStmt->fetchAll();

$vendorsStmt = db()->prepare(
    'SELECT id, vendor_name, vendor_code
     FROM vendors
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY vendor_name ASC'
);
$vendorsStmt->execute(['company_id' => $companyId]);
$vendors = $vendorsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('parts_error', 'You do not have permission to modify parts.', 'danger');
        redirect('modules/inventory/parts_master.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create') {
        $partSku = strtoupper(post_string('part_sku', 80));
        $partName = post_string('part_name', 150);
        $categoryId = post_int('category_id');
        $vendorId = post_int('vendor_id');
        $hsnCode = post_string('hsn_code', 20);
        $unit = part_unit_normalize_code(post_string('unit', 20));
        $purchasePrice = (float) ($_POST['purchase_price'] ?? 0);
        $sellingPrice = (float) ($_POST['selling_price'] ?? 0);
        $gstRate = (float) ($_POST['gst_rate'] ?? 18);
        $minStock = (float) ($_POST['min_stock'] ?? 0);
        $openingStock = (float) ($_POST['opening_stock'] ?? 0);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        $enableReminder = (int) ($_POST['enable_reminder'] ?? 0) === 1 ? 1 : 0;
        $compatibilityNote = post_string('compatibility_note', 255);

        $selectedVariantIds = [];
        if ($visCompatibilityEnabled) {
            $rawVariantIds = $_POST['compatibility_variant_ids'] ?? [];
            if (is_array($rawVariantIds)) {
                foreach ($rawVariantIds as $rawVariantId) {
                    $variantId = (int) $rawVariantId;
                    if ($variantId > 0 && isset($visVariantLookup[$variantId])) {
                        $selectedVariantIds[$variantId] = true;
                    }
                }
            }
        }
        $selectedVariantIds = array_keys($selectedVariantIds);

        if ($partSku === '' || $partName === '') {
            flash_set('parts_error', 'SKU/Part No and part name are required.', 'danger');
            redirect('modules/inventory/parts_master.php');
        }
        if ($unit === '') {
            $unit = 'PCS';
        }
        if (!isset($unitOptions[$unit])) {
            flash_set('parts_error', 'Select a valid unit from managed units.', 'danger');
            redirect('modules/inventory/parts_master.php');
        }
        $unitAllowsDecimal = part_unit_allows_decimal($companyId, $unit);
        if (!$unitAllowsDecimal && (part_quantity_has_fraction($minStock) || part_quantity_has_fraction($openingStock))) {
            flash_set('parts_error', 'Selected unit allows only whole-number stock/min values.', 'danger');
            redirect('modules/inventory/parts_master.php');
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $insertColumns = 'company_id, category_id, vendor_id, part_name, part_sku, hsn_code, unit, purchase_price, selling_price, gst_rate, min_stock, status_code, is_active';
            $insertValues = ':company_id, :category_id, :vendor_id, :part_name, :part_sku, :hsn_code, :unit, :purchase_price, :selling_price, :gst_rate, :min_stock, :status_code, :is_active';
            $insertParams = [
                'company_id' => $companyId,
                'category_id' => $categoryId > 0 ? $categoryId : null,
                'vendor_id' => $vendorId > 0 ? $vendorId : null,
                'part_name' => $partName,
                'part_sku' => $partSku,
                'hsn_code' => $hsnCode !== '' ? $hsnCode : null,
                'unit' => $unit,
                'purchase_price' => $purchasePrice,
                'selling_price' => $sellingPrice,
                'gst_rate' => $gstRate,
                'min_stock' => $minStock,
                'status_code' => $statusCode,
                'is_active' => $statusCode === 'ACTIVE' ? 1 : 0,
            ];
            if ($hasEnableReminder) {
                $insertColumns .= ', enable_reminder';
                $insertValues .= ', :enable_reminder';
                $insertParams['enable_reminder'] = $enableReminder;
            }
            $insertStmt = $pdo->prepare(
                'INSERT INTO parts
                  (' . $insertColumns . ')
                 VALUES
                  (' . $insertValues . ')'
            );
            $insertStmt->execute($insertParams);

            $partId = (int) $pdo->lastInsertId();

            $stockStmt = $pdo->prepare(
                'INSERT INTO garage_inventory (garage_id, part_id, quantity)
                 VALUES (:garage_id, :part_id, :quantity)
                 ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
            );
            $stockStmt->execute([
                'garage_id' => $garageId,
                'part_id' => $partId,
                'quantity' => max(0, $openingStock),
            ]);

            if ($openingStock > 0) {
                $movementStmt = $pdo->prepare(
                    'INSERT INTO inventory_movements
                      (company_id, garage_id, part_id, movement_type, quantity, reference_type, movement_uid, notes, created_by)
                     VALUES
                      (:company_id, :garage_id, :part_id, "IN", :quantity, "OPENING", :movement_uid, :notes, :created_by)'
                );
                $movementStmt->execute([
                    'company_id' => $companyId,
                    'garage_id' => $garageId,
                    'part_id' => $partId,
                    'quantity' => $openingStock,
                    'movement_uid' => sprintf('opening-%d-%d-%d', $companyId, $garageId, $partId),
                    'notes' => 'Opening stock from parts master',
                    'created_by' => (int) $_SESSION['user_id'],
                ]);
            }

            if ($visCompatibilityEnabled && !empty($selectedVariantIds)) {
                $compatibilityStmt = $pdo->prepare(
                    'INSERT INTO vis_part_compatibility
                      (company_id, variant_id, part_id, compatibility_note, status_code, deleted_at)
                     VALUES
                      (:company_id, :variant_id, :part_id, :compatibility_note, "ACTIVE", NULL)
                     ON DUPLICATE KEY UPDATE
                        compatibility_note = VALUES(compatibility_note),
                        status_code = "ACTIVE",
                        deleted_at = NULL'
                );

                foreach ($selectedVariantIds as $variantId) {
                    $compatibilityStmt->execute([
                        'company_id' => $companyId,
                        'variant_id' => (int) $variantId,
                        'part_id' => $partId,
                        'compatibility_note' => $compatibilityNote !== '' ? $compatibilityNote : null,
                    ]);
                }
            }

            $pdo->commit();
            log_audit('parts_master', 'create', $partId, 'Created part ' . $partSku, [
                'entity' => 'part',
                'source' => 'UI',
                'garage_id' => $garageId,
                'before' => ['exists' => false],
                'after' => [
                    'part_id' => $partId,
                    'part_sku' => $partSku,
                    'part_name' => $partName,
                    'status_code' => $statusCode,
                    'selling_price' => (float) $sellingPrice,
                    'enable_reminder' => $hasEnableReminder ? $enableReminder : 0,
                ],
                'metadata' => [
                    'opening_stock' => max(0, (float) $openingStock),
                    'compatibility_variant_count' => count($selectedVariantIds),
                ],
            ]);
            if ($openingStock > 0) {
                log_audit('inventory', 'stock_in', $partId, 'Opening stock posted from part creation.', [
                    'entity' => 'inventory_movement',
                    'source' => 'UI',
                    'garage_id' => $garageId,
                    'before' => [
                        'part_id' => $partId,
                        'stock_qty' => 0,
                    ],
                    'after' => [
                        'part_id' => $partId,
                        'stock_qty' => (float) $openingStock,
                        'movement_type' => 'IN',
                        'movement_qty' => (float) $openingStock,
                        'reference_type' => 'OPENING',
                    ],
                ]);
            }
            flash_set('parts_success', 'Part created successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('parts_error', 'Unable to create part. SKU/Part No must be unique.', 'danger');
        }

        redirect('modules/inventory/parts_master.php');
    }

    if ($action === 'update') {
        $partId = post_int('part_id');
        $partName = post_string('part_name', 150);
        $categoryId = post_int('category_id');
        $vendorId = post_int('vendor_id');
        $hsnCode = post_string('hsn_code', 20);
        $unit = part_unit_normalize_code(post_string('unit', 20));
        $purchasePrice = (float) ($_POST['purchase_price'] ?? 0);
        $sellingPrice = (float) ($_POST['selling_price'] ?? 0);
        $gstRate = (float) ($_POST['gst_rate'] ?? 18);
        $minStock = (float) ($_POST['min_stock'] ?? 0);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        $enableReminder = (int) ($_POST['enable_reminder'] ?? 0) === 1 ? 1 : 0;
        if ($unit === '') {
            $unit = 'PCS';
        }
        if (!isset($unitOptions[$unit])) {
            flash_set('parts_error', 'Select a valid unit from managed units.', 'danger');
            redirect('modules/inventory/parts_master.php');
        }
        if (!part_unit_allows_decimal($companyId, $unit) && part_quantity_has_fraction($minStock)) {
            flash_set('parts_error', 'Selected unit allows only whole-number min stock.', 'danger');
            redirect('modules/inventory/parts_master.php');
        }
        $beforeStmt = db()->prepare(
            'SELECT part_name, part_sku, status_code, selling_price, gst_rate, min_stock,
                    ' . ($hasEnableReminder ? 'enable_reminder' : '0') . ' AS enable_reminder
             FROM parts
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1'
        );
        $beforeStmt->execute([
            'id' => $partId,
            'company_id' => $companyId,
        ]);
        $beforePart = $beforeStmt->fetch() ?: null;

        $updateSql =
            'UPDATE parts
             SET part_name = :part_name,
                 category_id = :category_id,
                 vendor_id = :vendor_id,
                 hsn_code = :hsn_code,
                 unit = :unit,
                 purchase_price = :purchase_price,
                 selling_price = :selling_price,
                 gst_rate = :gst_rate,
                 min_stock = :min_stock,
                 status_code = :status_code,
                 is_active = :is_active';
        if ($hasEnableReminder) {
            $updateSql .= ',
                 enable_reminder = :enable_reminder';
        }
        $updateSql .= ',
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id';
        $updateParams = [
            'part_name' => $partName,
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'vendor_id' => $vendorId > 0 ? $vendorId : null,
            'hsn_code' => $hsnCode !== '' ? $hsnCode : null,
            'unit' => $unit,
            'purchase_price' => $purchasePrice,
            'selling_price' => $sellingPrice,
            'gst_rate' => $gstRate,
            'min_stock' => $minStock,
            'status_code' => $statusCode,
            'is_active' => $statusCode === 'ACTIVE' ? 1 : 0,
            'id' => $partId,
            'company_id' => $companyId,
        ];
        if ($hasEnableReminder) {
            $updateParams['enable_reminder'] = $enableReminder;
        }
        $stmt = db()->prepare($updateSql);
        $stmt->execute($updateParams);

        log_audit('parts_master', 'update', $partId, 'Updated part', [
            'entity' => 'part',
            'source' => 'UI',
            'before' => is_array($beforePart) ? [
                'part_name' => (string) ($beforePart['part_name'] ?? ''),
                'part_sku' => (string) ($beforePart['part_sku'] ?? ''),
                'status_code' => (string) ($beforePart['status_code'] ?? ''),
                'selling_price' => (float) ($beforePart['selling_price'] ?? 0),
                'gst_rate' => (float) ($beforePart['gst_rate'] ?? 0),
                'min_stock' => (float) ($beforePart['min_stock'] ?? 0),
                'enable_reminder' => (int) ($beforePart['enable_reminder'] ?? 0),
            ] : null,
            'after' => [
                'part_name' => $partName,
                'status_code' => $statusCode,
                'selling_price' => (float) $sellingPrice,
                'gst_rate' => (float) $gstRate,
                'min_stock' => (float) $minStock,
                'enable_reminder' => $hasEnableReminder ? $enableReminder : 0,
            ],
        ]);
        flash_set('parts_success', 'Part updated successfully.', 'success');
        redirect('modules/inventory/parts_master.php');
    }

    if ($action === 'change_status') {
        $partId = post_int('part_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));
        $safeDeleteValidation = null;
        if ($nextStatus === 'DELETED') {
            $safeDeleteValidation = safe_delete_validate_post_confirmation('inventory_part', $partId, [
                'operation' => 'delete',
                'reason_field' => 'deletion_reason',
            ]);
        }
        $beforeStatusStmt = db()->prepare(
            'SELECT status_code, is_active
             FROM parts
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1'
        );
        $beforeStatusStmt->execute([
            'id' => $partId,
            'company_id' => $companyId,
        ]);
        $beforeStatus = $beforeStatusStmt->fetch() ?: null;

        $stmt = db()->prepare(
            'UPDATE parts
             SET status_code = :status_code,
                 is_active = :is_active,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'status_code' => $nextStatus,
            'is_active' => $nextStatus === 'ACTIVE' ? 1 : 0,
            'id' => $partId,
            'company_id' => $companyId,
        ]);

        log_audit('parts_master', 'status', $partId, 'Changed part status to ' . $nextStatus, [
            'entity' => 'part',
            'source' => 'UI',
            'before' => is_array($beforeStatus) ? [
                'status_code' => (string) ($beforeStatus['status_code'] ?? ''),
                'is_active' => (int) ($beforeStatus['is_active'] ?? 0),
            ] : null,
            'after' => [
                'status_code' => $nextStatus,
                'is_active' => $nextStatus === 'ACTIVE' ? 1 : 0,
            ],
        ]);
        if ($nextStatus === 'DELETED' && is_array($safeDeleteValidation)) {
            safe_delete_log_cascade('inventory_part', 'delete', $partId, $safeDeleteValidation, [
                'metadata' => [
                    'company_id' => $companyId,
                    'requested_status' => 'DELETED',
                    'applied_status' => $nextStatus,
                ],
            ]);
        }
        flash_set('parts_success', 'Part status updated.', 'success');
        redirect('modules/inventory/parts_master.php');
    }
}

$editId = get_int('edit_id');
$editPart = null;
if ($editId > 0) {
    $editStmt = db()->prepare('SELECT * FROM parts WHERE id = :id AND company_id = :company_id LIMIT 1');
    $editStmt->execute([
        'id' => $editId,
        'company_id' => $companyId,
    ]);
    $editPart = $editStmt->fetch() ?: null;
}

$reminderFilterRaw = strtolower(trim((string) ($_GET['reminder_filter'] ?? 'all')));
$filterReminderEnabled = $reminderFilterRaw === 'enabled' && $hasEnableReminder;
if (!$filterReminderEnabled) {
    $reminderFilterRaw = 'all';
}

$partCompatibilityCounts = [];
if ($visCompatibilityEnabled) {
    $countStmt = db()->prepare(
        'SELECT part_id, COUNT(*) AS compatibility_count
         FROM vis_part_compatibility
         WHERE company_id = :company_id
           AND status_code = "ACTIVE"
         GROUP BY part_id'
    );
    $countStmt->execute(['company_id' => $companyId]);
    foreach ($countStmt->fetchAll() as $row) {
        $mappedPartId = (int) ($row['part_id'] ?? 0);
        if ($mappedPartId > 0) {
            $partCompatibilityCounts[$mappedPartId] = (int) ($row['compatibility_count'] ?? 0);
        }
    }
}

$partsSql =
    'SELECT p.*, pc.category_name, v.vendor_name, COALESCE(gi.quantity, 0) AS stock_qty
     FROM parts p
     LEFT JOIN part_categories pc ON pc.id = p.category_id
     LEFT JOIN vendors v ON v.id = p.vendor_id
     LEFT JOIN garage_inventory gi ON gi.part_id = p.id AND gi.garage_id = :garage_id
     WHERE p.company_id = :company_id';
$partsParams = [
    'company_id' => $companyId,
    'garage_id' => $garageId,
];

if ($filterReminderEnabled) {
    $partsSql .= ' AND COALESCE(p.enable_reminder, 0) = 1';
}

$partsSql .= ' ORDER BY p.id DESC';

$partsStmt = db()->prepare($partsSql);
$partsStmt->execute($partsParams);
$parts = $partsStmt->fetchAll();

$partsListAllUrl = url('modules/inventory/parts_master.php');
$partsListReminderUrl = url('modules/inventory/parts_master.php?reminder_filter=enabled');

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Parts / Item Master</h3></div>
        <div class="col-sm-6">
          <div class="d-flex justify-content-sm-end align-items-center gap-2 flex-wrap">
            <?php if ($canViewPartCategories): ?>
              <a href="<?= e(url('modules/inventory/categories.php')); ?>" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-tags me-1"></i>Manage Categories
              </a>
            <?php endif; ?>
            <a href="<?= e(url('modules/inventory/units.php')); ?>" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-rulers me-1"></i>Manage Units
            </a>
            <ol class="breadcrumb mb-0">
              <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
              <li class="breadcrumb-item active">Parts Master</li>
            </ol>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editPart ? 'Edit Part' : 'Add Part'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editPart ? 'update' : 'create'; ?>" />
              <input type="hidden" name="part_id" value="<?= (int) ($editPart['id'] ?? 0); ?>" />

              <div class="col-md-2">
                <label class="form-label">SKU/Part No</label>
                <input type="text" name="part_sku" class="form-control" <?= $editPart ? 'readonly' : 'required'; ?> value="<?= e((string) ($editPart['part_sku'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Part Name</label>
                <input type="text" name="part_name" class="form-control" required value="<?= e((string) ($editPart['part_name'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Category</label>
                <select name="category_id" class="form-select">
                  <option value="0">- Select Category -</option>
                  <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id']; ?>" <?= ((int) ($editPart['category_id'] ?? 0) === (int) $category['id']) ? 'selected' : ''; ?>>
                      <?= e((string) $category['category_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Vendor</label>
                <select name="vendor_id" class="form-select">
                  <option value="0">- Select Vendor -</option>
                  <?php foreach ($vendors as $vendor): ?>
                    <option value="<?= (int) $vendor['id']; ?>" <?= ((int) ($editPart['vendor_id'] ?? 0) === (int) $vendor['id']) ? 'selected' : ''; ?>>
                      <?= e((string) $vendor['vendor_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-2">
                <label class="form-label">HSN</label>
                <input type="text" name="hsn_code" class="form-control" value="<?= e((string) ($editPart['hsn_code'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Unit</label>
                <?php $selectedUnitCode = part_unit_normalize_code((string) ($editPart['unit'] ?? 'PCS')); ?>
                <select name="unit" class="form-select" required>
                  <?php foreach ($unitOptionsList as $unitOption): ?>
                    <?php
                      $unitCode = (string) ($unitOption['code'] ?? '');
                      $unitName = (string) ($unitOption['name'] ?? $unitCode);
                      $allowDecimal = (int) ($unitOption['allow_decimal'] ?? 0) === 1;
                    ?>
                    <option value="<?= e($unitCode); ?>" <?= $selectedUnitCode === $unitCode ? 'selected' : ''; ?>>
                      <?= e($unitCode); ?> - <?= e($unitName); ?><?= $allowDecimal ? ' (Decimal)' : ' (Whole)'; ?>
                    </option>
                  <?php endforeach; ?>
                  <?php if ($selectedUnitCode !== '' && !isset($unitOptions[$selectedUnitCode])): ?>
                    <option value="<?= e($selectedUnitCode); ?>" selected>
                      <?= e($selectedUnitCode); ?> - Inactive/Legacy Unit
                    </option>
                  <?php endif; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Purchase Price</label>
                <input type="number" step="0.01" name="purchase_price" class="form-control" value="<?= e((string) ($editPart['purchase_price'] ?? '0')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Selling Price</label>
                <input type="number" step="0.01" name="selling_price" class="form-control" value="<?= e((string) ($editPart['selling_price'] ?? '0')); ?>" />
              </div>
              <div class="col-md-1">
                <label class="form-label">GST%</label>
                <input type="number" step="0.01" name="gst_rate" class="form-control" value="<?= e((string) ($editPart['gst_rate'] ?? '18')); ?>" />
              </div>
              <div class="col-md-1">
                <label class="form-label">Min Stock</label>
                <input type="number" step="0.01" name="min_stock" class="form-control" value="<?= e((string) ($editPart['min_stock'] ?? '0')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Enable Reminder</label>
                <select name="enable_reminder" class="form-select">
                  <?php $enableReminderValue = (int) ($editPart['enable_reminder'] ?? 0); ?>
                  <option value="0" <?= $enableReminderValue === 0 ? 'selected' : ''; ?>>No</option>
                  <option value="1" <?= $enableReminderValue === 1 ? 'selected' : ''; ?>>Yes</option>
                </select>
              </div>
              <?php if (!$editPart): ?>
                <div class="col-md-2">
                  <label class="form-label">Opening Stock (Current Garage)</label>
                  <input type="number" step="0.01" min="0" name="opening_stock" class="form-control" value="0" />
                </div>

                <?php if ($visCompatibilityEnabled): ?>
                  <div class="col-md-6">
                    <label class="form-label">VIS Compatibility (Optional)</label>
                    <select name="compatibility_variant_ids[]" class="form-select" multiple size="7">
                      <?php foreach ($visVariants as $variant): ?>
                        <option value="<?= (int) $variant['id']; ?>">
                          <?= e((string) $variant['brand_name']); ?> / <?= e((string) $variant['model_name']); ?> / <?= e((string) $variant['variant_name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Select one or more VIS vehicle variants compatible with this part.</small>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Compatibility Note (Optional)</label>
                    <input type="text" name="compatibility_note" class="form-control" maxlength="255" placeholder="Example: Diesel variant only" />
                    <small class="text-muted">You can also map later using the Map Compatibility button.</small>
                  </div>
                <?php else: ?>
                  <div class="col-md-6">
                    <div class="alert alert-light border mb-0">
                      VIS mapping is optional and currently unavailable. Part creation is unaffected.
                    </div>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
              <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editPart['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editPart ? 'Update Part' : 'Create Part'; ?></button>
              <?php if ($editPart): ?>
                <a href="<?= e(url('modules/inventory/parts_master.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
          <h3 class="card-title mb-0">Parts List</h3>
          <div class="btn-group btn-group-sm" role="group" aria-label="Reminder filter">
            <a href="<?= e($partsListAllUrl); ?>" class="btn <?= $filterReminderEnabled ? 'btn-outline-secondary' : 'btn-secondary'; ?>">All</a>
            <a href="<?= e($partsListReminderUrl); ?>" class="btn <?= $filterReminderEnabled ? 'btn-success' : 'btn-outline-success'; ?>">Reminder Enabled</a>
          </div>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>SKU/Part No</th>
                <th>Name</th>
                <th>Category</th>
                <th>Vendor</th>
                <th>Price</th>
                <th>Stock</th>
                <th>Reminder</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($parts)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No parts found.</td></tr>
              <?php else: ?>
                <?php foreach ($parts as $part): ?>
                  <?php $compatibilityCount = (int) ($partCompatibilityCounts[(int) $part['id']] ?? 0); ?>
                  <tr>
                    <td><code><?= e((string) $part['part_sku']); ?></code></td>
                    <td>
                      <?= e((string) $part['part_name']); ?><br>
                      <small class="text-muted">HSN: <?= e((string) ($part['hsn_code'] ?? '-')); ?></small>
                      <?php if ($visCompatibilityEnabled): ?>
                        <br><small class="text-muted">Compatible VIS variants: <?= $compatibilityCount; ?></small>
                      <?php endif; ?>
                    </td>
                    <td><?= e((string) ($part['category_name'] ?? '-')); ?></td>
                    <td><?= e((string) ($part['vendor_name'] ?? '-')); ?></td>
                    <td><?= e(format_currency((float) $part['selling_price'])); ?></td>
                    <td><?= e((string) $part['stock_qty']); ?> <?= e((string) $part['unit']); ?></td>
                    <td>
                      <span class="badge text-bg-<?= ((int) ($part['enable_reminder'] ?? 0) === 1) ? 'success' : 'secondary'; ?>">
                        <?= ((int) ($part['enable_reminder'] ?? 0) === 1) ? 'Yes' : 'No'; ?>
                      </span>
                    </td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $part['status_code'])); ?>"><?= e((string) $part['status_code']); ?></span></td>
                    <td class="d-flex gap-1">
                      <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/inventory/parts_master.php?edit_id=' . (int) $part['id'])); ?>">Edit</a>
                      <?php if ($visCompatibilityEnabled): ?>
                        <a class="btn btn-sm btn-outline-info" href="<?= e(url('modules/inventory/part_compatibility_map.php?part_id=' . (int) $part['id'])); ?>">Map Compatibility</a>
                      <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-info" disabled>Map Compatibility</button>
                      <?php endif; ?>
                      <?php if ($canManage): ?>
                        <form method="post" class="d-inline" data-confirm="Change part status?">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="change_status" />
                          <input type="hidden" name="part_id" value="<?= (int) $part['id']; ?>" />
                          <input type="hidden" name="next_status" value="<?= e(((string) $part['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $part['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                        </form>
                        <?php if ((string) $part['status_code'] !== 'DELETED'): ?>
                          <form method="post"
                                class="d-inline"
                                data-safe-delete
                                data-safe-delete-entity="inventory_part"
                                data-safe-delete-record-field="part_id"
                                data-safe-delete-operation="delete"
                                data-safe-delete-reason-field="deletion_reason">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="part_id" value="<?= (int) $part['id']; ?>" />
                            <input type="hidden" name="next_status" value="DELETED" />
                            <button type="submit" class="btn btn-sm btn-outline-danger">Soft Delete</button>
                          </form>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
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

