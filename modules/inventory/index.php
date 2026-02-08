<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('inventory.view');

$page_title = 'Inventory & Parts';
$active_menu = 'inventory';
$canManage = has_permission('inventory.manage');
$companyId = active_company_id();
$garageId = active_garage_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'add_part') {
        $partName = post_string('part_name', 150);
        $partSku = strtoupper(post_string('part_sku', 80));
        $hsnCode = post_string('hsn_code', 20);
        $unit = strtoupper(post_string('unit', 20));
        $purchasePrice = (float) ($_POST['purchase_price'] ?? 0);
        $sellingPrice = (float) ($_POST['selling_price'] ?? 0);
        $gstRate = (float) ($_POST['gst_rate'] ?? 18);
        $minStock = (float) ($_POST['min_stock'] ?? 0);

        if ($partName === '' || $partSku === '') {
            flash_set('inventory_error', 'Part name and SKU are required.', 'danger');
            redirect('modules/inventory/index.php');
        }

        try {
            $stmt = db()->prepare(
                'INSERT INTO parts
                  (company_id, part_name, part_sku, hsn_code, unit, purchase_price, selling_price, gst_rate, min_stock, is_active)
                 VALUES
                  (:company_id, :part_name, :part_sku, :hsn_code, :unit, :purchase_price, :selling_price, :gst_rate, :min_stock, 1)'
            );
            $stmt->execute([
                'company_id' => $companyId,
                'part_name' => $partName,
                'part_sku' => $partSku,
                'hsn_code' => $hsnCode !== '' ? $hsnCode : null,
                'unit' => $unit !== '' ? $unit : 'PCS',
                'purchase_price' => $purchasePrice,
                'selling_price' => $sellingPrice,
                'gst_rate' => $gstRate,
                'min_stock' => $minStock,
            ]);

            $partId = (int) db()->lastInsertId();
            $stockStmt = db()->prepare('INSERT INTO garage_inventory (garage_id, part_id, quantity) VALUES (:garage_id, :part_id, 0)');
            $stockStmt->execute([
                'garage_id' => $garageId,
                'part_id' => $partId,
            ]);

            flash_set('inventory_success', 'Part created successfully.', 'success');
        } catch (Throwable $exception) {
            flash_set('inventory_error', 'Unable to create part. SKU must be unique.', 'danger');
        }

        redirect('modules/inventory/index.php');
    }

    if ($action === 'stock_move') {
        $partId = post_int('part_id');
        $movementType = (string) ($_POST['movement_type'] ?? 'IN');
        $quantityInput = (float) ($_POST['quantity'] ?? 0);
        $referenceType = (string) ($_POST['reference_type'] ?? 'ADJUSTMENT');
        $notes = post_string('notes', 255);

        $allowedMovementTypes = ['IN', 'OUT', 'ADJUST'];
        $allowedReferenceTypes = ['PURCHASE', 'JOB_CARD', 'ADJUSTMENT', 'OPENING'];

        if (!in_array($movementType, $allowedMovementTypes, true)) {
            $movementType = 'IN';
        }
        if (!in_array($referenceType, $allowedReferenceTypes, true)) {
            $referenceType = 'ADJUSTMENT';
        }

        if ($partId <= 0 || $quantityInput == 0.0) {
            flash_set('inventory_error', 'Part and quantity are required.', 'danger');
            redirect('modules/inventory/index.php');
        }

        $delta = 0.0;
        $movementQuantity = 0.0;

        if ($movementType === 'IN') {
            $movementQuantity = abs($quantityInput);
            $delta = $movementQuantity;
        } elseif ($movementType === 'OUT') {
            $movementQuantity = abs($quantityInput);
            $delta = -1 * $movementQuantity;
        } else {
            $delta = $quantityInput;
            $movementQuantity = $quantityInput;
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stockStmt = $pdo->prepare(
                'SELECT quantity
                 FROM garage_inventory
                 WHERE garage_id = :garage_id
                   AND part_id = :part_id
                 FOR UPDATE'
            );
            $stockStmt->execute([
                'garage_id' => $garageId,
                'part_id' => $partId,
            ]);
            $stock = $stockStmt->fetch();

            if (!$stock) {
                $insertStock = $pdo->prepare('INSERT INTO garage_inventory (garage_id, part_id, quantity) VALUES (:garage_id, :part_id, 0)');
                $insertStock->execute([
                    'garage_id' => $garageId,
                    'part_id' => $partId,
                ]);
                $currentQty = 0.0;
            } else {
                $currentQty = (float) $stock['quantity'];
            }

            $newQty = $currentQty + $delta;
            if ($newQty < 0) {
                throw new RuntimeException('Stock cannot go below zero. Available: ' . $currentQty);
            }

            $updateStock = $pdo->prepare(
                'UPDATE garage_inventory
                 SET quantity = :quantity
                 WHERE garage_id = :garage_id
                   AND part_id = :part_id'
            );
            $updateStock->execute([
                'quantity' => $newQty,
                'garage_id' => $garageId,
                'part_id' => $partId,
            ]);

            $movementStmt = $pdo->prepare(
                'INSERT INTO inventory_movements
                  (company_id, garage_id, part_id, movement_type, quantity, reference_type, notes, created_by)
                 VALUES
                  (:company_id, :garage_id, :part_id, :movement_type, :quantity, :reference_type, :notes, :created_by)'
            );
            $movementStmt->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'part_id' => $partId,
                'movement_type' => $movementType,
                'quantity' => $movementQuantity,
                'reference_type' => $referenceType,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => (int) $_SESSION['user_id'],
            ]);

            $pdo->commit();
            flash_set('inventory_success', 'Stock movement posted successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('inventory_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/inventory/index.php');
    }
}

$partsStmt = db()->prepare(
    'SELECT p.*, COALESCE(gi.quantity, 0) AS stock_qty,
            CASE WHEN COALESCE(gi.quantity, 0) <= p.min_stock THEN 1 ELSE 0 END AS is_low_stock
     FROM parts p
     LEFT JOIN garage_inventory gi ON gi.part_id = p.id AND gi.garage_id = :garage_id
     WHERE p.company_id = :company_id
     ORDER BY p.part_name ASC'
);
$partsStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$parts = $partsStmt->fetchAll();

$movementsStmt = db()->prepare(
    'SELECT im.*, p.part_name, p.part_sku, u.name AS created_by_name
     FROM inventory_movements im
     INNER JOIN parts p ON p.id = im.part_id
     LEFT JOIN users u ON u.id = im.created_by
     WHERE im.company_id = :company_id
       AND im.garage_id = :garage_id
     ORDER BY im.id DESC
     LIMIT 40'
);
$movementsStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$movements = $movementsStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Inventory & Parts</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Inventory</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage): ?>
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="card card-primary">
              <div class="card-header"><h3 class="card-title">Add Part</h3></div>
              <form method="post">
                <div class="card-body row g-2">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="add_part" />
                  <div class="col-md-6"><input type="text" name="part_name" class="form-control" placeholder="Part Name" required /></div>
                  <div class="col-md-6"><input type="text" name="part_sku" class="form-control" placeholder="Part SKU" required /></div>
                  <div class="col-md-4"><input type="text" name="hsn_code" class="form-control" placeholder="HSN Code" /></div>
                  <div class="col-md-4"><input type="text" name="unit" class="form-control" placeholder="Unit (PCS/LTR)" value="PCS" /></div>
                  <div class="col-md-4"><input type="number" step="0.01" name="gst_rate" class="form-control" placeholder="GST %" value="18" /></div>
                  <div class="col-md-4"><input type="number" step="0.01" name="purchase_price" class="form-control" placeholder="Purchase Price" /></div>
                  <div class="col-md-4"><input type="number" step="0.01" name="selling_price" class="form-control" placeholder="Selling Price" /></div>
                  <div class="col-md-4"><input type="number" step="0.01" name="min_stock" class="form-control" placeholder="Min Stock" /></div>
                </div>
                <div class="card-footer">
                  <button type="submit" class="btn btn-primary">Save Part</button>
                </div>
              </form>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card card-info">
              <div class="card-header"><h3 class="card-title">Stock Movement</h3></div>
              <form method="post">
                <div class="card-body row g-2">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="stock_move" />
                  <div class="col-md-12">
                    <select name="part_id" class="form-select" required>
                      <option value="">Select Part</option>
                      <?php foreach ($parts as $part): ?>
                        <option value="<?= (int) $part['id']; ?>">
                          <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>) | Stock: <?= e((string) $part['stock_qty']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <select name="movement_type" class="form-select" required>
                      <option value="IN">Stock In</option>
                      <option value="OUT">Stock Out</option>
                      <option value="ADJUST">Adjustment (+/-)</option>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <input type="number" step="0.01" name="quantity" class="form-control" placeholder="Qty" required />
                  </div>
                  <div class="col-md-4">
                    <select name="reference_type" class="form-select" required>
                      <option value="OPENING">Opening</option>
                      <option value="PURCHASE">Purchase</option>
                      <option value="JOB_CARD">Job Card</option>
                      <option value="ADJUSTMENT">Adjustment</option>
                    </select>
                  </div>
                  <div class="col-md-12">
                    <input type="text" name="notes" class="form-control" placeholder="Notes" />
                  </div>
                </div>
                <div class="card-footer">
                  <button type="submit" class="btn btn-info">Post Movement</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="card mt-3">
        <div class="card-header"><h3 class="card-title">Parts Stock (Active Garage)</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Part Name</th>
                <th>HSN</th>
                <th>Sell Price</th>
                <th>GST%</th>
                <th>Min Stock</th>
                <th>Current Stock</th>
                <th>Alert</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($parts)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No parts configured.</td></tr>
              <?php else: ?>
                <?php foreach ($parts as $part): ?>
                  <tr>
                    <td><?= e((string) $part['part_sku']); ?></td>
                    <td><?= e((string) $part['part_name']); ?></td>
                    <td><?= e((string) ($part['hsn_code'] ?? '-')); ?></td>
                    <td><?= e(format_currency((float) $part['selling_price'])); ?></td>
                    <td><?= e((string) $part['gst_rate']); ?></td>
                    <td><?= e((string) $part['min_stock']); ?></td>
                    <td><?= e((string) $part['stock_qty']); ?> <?= e((string) $part['unit']); ?></td>
                    <td>
                      <?php if ((int) $part['is_low_stock'] === 1): ?>
                        <span class="badge text-bg-danger">Low Stock</span>
                      <?php else: ?>
                        <span class="badge text-bg-success">OK</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header"><h3 class="card-title">Recent Stock Movements</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Date</th>
                <th>Part</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Reference</th>
                <th>User</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($movements)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No stock movement history.</td></tr>
              <?php else: ?>
                <?php foreach ($movements as $movement): ?>
                  <tr>
                    <td><?= e((string) $movement['created_at']); ?></td>
                    <td><?= e((string) $movement['part_name']); ?> (<?= e((string) $movement['part_sku']); ?>)</td>
                    <td><span class="badge text-bg-secondary"><?= e((string) $movement['movement_type']); ?></span></td>
                    <td><?= e((string) $movement['quantity']); ?></td>
                    <td><?= e((string) $movement['reference_type']); ?></td>
                    <td><?= e((string) ($movement['created_by_name'] ?? '-')); ?></td>
                    <td><?= e((string) ($movement['notes'] ?? '-')); ?></td>
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
