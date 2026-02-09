<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('part_master.view');

$page_title = 'Parts / Item Master';
$active_menu = 'inventory.parts_master';
$canManage = has_permission('part_master.manage');
$companyId = active_company_id();
$garageId = active_garage_id();

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
        $unit = strtoupper(post_string('unit', 20));
        $purchasePrice = (float) ($_POST['purchase_price'] ?? 0);
        $sellingPrice = (float) ($_POST['selling_price'] ?? 0);
        $gstRate = (float) ($_POST['gst_rate'] ?? 18);
        $minStock = (float) ($_POST['min_stock'] ?? 0);
        $openingStock = (float) ($_POST['opening_stock'] ?? 0);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($partSku === '' || $partName === '') {
            flash_set('parts_error', 'Part SKU and part name are required.', 'danger');
            redirect('modules/inventory/parts_master.php');
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $insertStmt = $pdo->prepare(
                'INSERT INTO parts
                  (company_id, category_id, vendor_id, part_name, part_sku, hsn_code, unit, purchase_price, selling_price, gst_rate, min_stock, status_code, is_active)
                 VALUES
                  (:company_id, :category_id, :vendor_id, :part_name, :part_sku, :hsn_code, :unit, :purchase_price, :selling_price, :gst_rate, :min_stock, :status_code, :is_active)'
            );
            $insertStmt->execute([
                'company_id' => $companyId,
                'category_id' => $categoryId > 0 ? $categoryId : null,
                'vendor_id' => $vendorId > 0 ? $vendorId : null,
                'part_name' => $partName,
                'part_sku' => $partSku,
                'hsn_code' => $hsnCode !== '' ? $hsnCode : null,
                'unit' => $unit !== '' ? $unit : 'PCS',
                'purchase_price' => $purchasePrice,
                'selling_price' => $sellingPrice,
                'gst_rate' => $gstRate,
                'min_stock' => $minStock,
                'status_code' => $statusCode,
                'is_active' => $statusCode === 'ACTIVE' ? 1 : 0,
            ]);

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
                      (company_id, garage_id, part_id, movement_type, quantity, reference_type, notes, created_by)
                     VALUES
                      (:company_id, :garage_id, :part_id, "IN", :quantity, "OPENING", :notes, :created_by)'
                );
                $movementStmt->execute([
                    'company_id' => $companyId,
                    'garage_id' => $garageId,
                    'part_id' => $partId,
                    'quantity' => $openingStock,
                    'notes' => 'Opening stock from parts master',
                    'created_by' => (int) $_SESSION['user_id'],
                ]);
            }

            $pdo->commit();
            log_audit('parts_master', 'create', $partId, 'Created part ' . $partSku);
            flash_set('parts_success', 'Part created successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('parts_error', 'Unable to create part. SKU must be unique.', 'danger');
        }

        redirect('modules/inventory/parts_master.php');
    }

    if ($action === 'update') {
        $partId = post_int('part_id');
        $partName = post_string('part_name', 150);
        $categoryId = post_int('category_id');
        $vendorId = post_int('vendor_id');
        $hsnCode = post_string('hsn_code', 20);
        $unit = strtoupper(post_string('unit', 20));
        $purchasePrice = (float) ($_POST['purchase_price'] ?? 0);
        $sellingPrice = (float) ($_POST['selling_price'] ?? 0);
        $gstRate = (float) ($_POST['gst_rate'] ?? 18);
        $minStock = (float) ($_POST['min_stock'] ?? 0);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        $stmt = db()->prepare(
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
                 is_active = :is_active,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'part_name' => $partName,
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'vendor_id' => $vendorId > 0 ? $vendorId : null,
            'hsn_code' => $hsnCode !== '' ? $hsnCode : null,
            'unit' => $unit !== '' ? $unit : 'PCS',
            'purchase_price' => $purchasePrice,
            'selling_price' => $sellingPrice,
            'gst_rate' => $gstRate,
            'min_stock' => $minStock,
            'status_code' => $statusCode,
            'is_active' => $statusCode === 'ACTIVE' ? 1 : 0,
            'id' => $partId,
            'company_id' => $companyId,
        ]);

        log_audit('parts_master', 'update', $partId, 'Updated part');
        flash_set('parts_success', 'Part updated successfully.', 'success');
        redirect('modules/inventory/parts_master.php');
    }

    if ($action === 'change_status') {
        $partId = post_int('part_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));

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

        log_audit('parts_master', 'status', $partId, 'Changed part status to ' . $nextStatus);
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

$partsStmt = db()->prepare(
    'SELECT p.*, pc.category_name, v.vendor_name, COALESCE(gi.quantity, 0) AS stock_qty
     FROM parts p
     LEFT JOIN part_categories pc ON pc.id = p.category_id
     LEFT JOIN vendors v ON v.id = p.vendor_id
     LEFT JOIN garage_inventory gi ON gi.part_id = p.id AND gi.garage_id = :garage_id
     WHERE p.company_id = :company_id
     ORDER BY p.id DESC'
);
$partsStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$parts = $partsStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Parts / Item Master</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Parts Master</li>
          </ol>
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
                <label class="form-label">SKU</label>
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
                <input type="text" name="unit" class="form-control" value="<?= e((string) ($editPart['unit'] ?? 'PCS')); ?>" />
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
              <?php if (!$editPart): ?>
                <div class="col-md-2">
                  <label class="form-label">Opening Stock (Current Garage)</label>
                  <input type="number" step="0.01" min="0" name="opening_stock" class="form-control" value="0" />
                </div>
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
        <div class="card-header"><h3 class="card-title">Parts List</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Name</th>
                <th>Category</th>
                <th>Vendor</th>
                <th>Price</th>
                <th>Stock</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($parts)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No parts found.</td></tr>
              <?php else: ?>
                <?php foreach ($parts as $part): ?>
                  <tr>
                    <td><code><?= e((string) $part['part_sku']); ?></code></td>
                    <td><?= e((string) $part['part_name']); ?><br><small class="text-muted">HSN: <?= e((string) ($part['hsn_code'] ?? '-')); ?></small></td>
                    <td><?= e((string) ($part['category_name'] ?? '-')); ?></td>
                    <td><?= e((string) ($part['vendor_name'] ?? '-')); ?></td>
                    <td><?= e(format_currency((float) $part['selling_price'])); ?></td>
                    <td><?= e((string) $part['stock_qty']); ?> <?= e((string) $part['unit']); ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $part['status_code'])); ?>"><?= e((string) $part['status_code']); ?></span></td>
                    <td class="d-flex gap-1">
                      <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/inventory/parts_master.php?edit_id=' . (int) $part['id'])); ?>">Edit</a>
                      <?php if ($canManage): ?>
                        <form method="post" class="d-inline" data-confirm="Change part status?">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="change_status" />
                          <input type="hidden" name="part_id" value="<?= (int) $part['id']; ?>" />
                          <input type="hidden" name="next_status" value="<?= e(((string) $part['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $part['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                        </form>
                        <?php if ((string) $part['status_code'] !== 'DELETED'): ?>
                          <form method="post" class="d-inline" data-confirm="Soft delete this part?">
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
