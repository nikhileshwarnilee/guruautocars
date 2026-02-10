<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('inventory.view');

$page_title = 'Inventory Intelligence';
$active_menu = 'inventory';

$companyId = active_company_id();
$activeGarageId = active_garage_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$currentUser = current_user();
$isSuperAdmin = (($currentUser['role_key'] ?? '') === 'super_admin');

$canAdjust = has_permission('inventory.adjust') || has_permission('inventory.manage');
$canTransfer = has_permission('inventory.transfer') || has_permission('inventory.manage');
$canNegative = has_permission('inventory.negative') || has_permission('inventory.manage');

function inv_decimal(string $key, float $default = 0.0): float
{
    $raw = trim((string) ($_POST[$key] ?? ''));
    if ($raw === '') {
        return $default;
    }

    $normalized = str_replace([',', ' '], '', $raw);
    if (!is_numeric($normalized)) {
        return $default;
    }

    return (float) $normalized;
}

function inv_issue_action_token(string $action): string
{
    if (!isset($_SESSION['_inventory_action_tokens']) || !is_array($_SESSION['_inventory_action_tokens'])) {
        $_SESSION['_inventory_action_tokens'] = [];
    }

    $token = bin2hex(random_bytes(16));
    $_SESSION['_inventory_action_tokens'][$action] = $token;
    return $token;
}

function inv_consume_action_token(string $action, string $token): bool
{
    $tokens = $_SESSION['_inventory_action_tokens'] ?? [];
    if (!is_array($tokens) || !isset($tokens[$action])) {
        return false;
    }

    $valid = hash_equals((string) $tokens[$action], $token);
    if ($valid) {
        unset($_SESSION['_inventory_action_tokens'][$action]);
    }

    return $valid;
}

function inv_accessible_garage_ids(): array
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

function inv_lock_inventory_row(PDO $pdo, int $garageId, int $partId): float
{
    $insert = $pdo->prepare(
        'INSERT INTO garage_inventory (garage_id, part_id, quantity)
         VALUES (:garage_id, :part_id, 0)
         ON DUPLICATE KEY UPDATE quantity = quantity'
    );
    $insert->execute([
        'garage_id' => $garageId,
        'part_id' => $partId,
    ]);

    $select = $pdo->prepare(
        'SELECT quantity
         FROM garage_inventory
         WHERE garage_id = :garage_id
           AND part_id = :part_id
         FOR UPDATE'
    );
    $select->execute([
        'garage_id' => $garageId,
        'part_id' => $partId,
    ]);

    return (float) $select->fetchColumn();
}

function inv_insert_movement(PDO $pdo, array $payload): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO inventory_movements
          (company_id, garage_id, part_id, movement_type, quantity, reference_type, reference_id, movement_uid, notes, created_by)
         VALUES
          (:company_id, :garage_id, :part_id, :movement_type, :quantity, :reference_type, :reference_id, :movement_uid, :notes, :created_by)'
    );
    $stmt->execute([
        'company_id' => (int) $payload['company_id'],
        'garage_id' => (int) $payload['garage_id'],
        'part_id' => (int) $payload['part_id'],
        'movement_type' => (string) $payload['movement_type'],
        'quantity' => (float) $payload['quantity'],
        'reference_type' => (string) $payload['reference_type'],
        'reference_id' => $payload['reference_id'] ?? null,
        'movement_uid' => (string) $payload['movement_uid'],
        'notes' => $payload['notes'] ?? null,
        'created_by' => (int) $payload['created_by'],
    ]);
}

function inv_generate_transfer_ref(PDO $pdo, int $companyId): string
{
    for ($i = 0; $i < 10; $i++) {
        $ref = sprintf('TRF-%s-%03d', date('ymdHis'), random_int(1, 999));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_transfers WHERE company_id = :company_id AND transfer_ref = :transfer_ref');
        $stmt->execute([
            'company_id' => $companyId,
            'transfer_ref' => $ref,
        ]);

        if ((int) $stmt->fetchColumn() === 0) {
            return $ref;
        }
    }

    return sprintf('TRF-%s-%s', date('ymdHis'), bin2hex(random_bytes(3)));
}

function inv_is_valid_date(?string $date): bool
{
    if ($date === null || $date === '') {
        return false;
    }

    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

function inv_movement_source_label(string $source): string
{
    return match ($source) {
        'JOB_CARD' => 'JOB',
        'ADJUSTMENT' => 'ADJUSTMENT',
        'PURCHASE' => 'PURCHASE',
        'TRANSFER' => 'TRANSFER',
        'OPENING' => 'OPENING',
        default => $source,
    };
}

$accessibleGarageIds = inv_accessible_garage_ids();
if (!in_array($activeGarageId, $accessibleGarageIds, true) && $activeGarageId > 0) {
    $accessibleGarageIds[] = $activeGarageId;
}
$accessibleGarageIds = array_values(array_unique(array_filter($accessibleGarageIds, static fn (int $id): bool => $id > 0)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'stock_adjust') {
        if (!$canAdjust) {
            flash_set('inventory_error', 'You do not have permission to adjust stock.', 'danger');
            redirect('modules/inventory/index.php');
        }

        $actionToken = trim((string) ($_POST['action_token'] ?? ''));
        if (!inv_consume_action_token('stock_adjust', $actionToken)) {
            flash_set('inventory_warning', 'Duplicate or expired stock adjustment request ignored.', 'warning');
            redirect('modules/inventory/index.php');
        }

        $partId = post_int('part_id');
        $movementType = strtoupper(trim((string) ($_POST['movement_type'] ?? 'IN')));
        $sourceType = strtoupper(trim((string) ($_POST['source_type'] ?? 'ADJUSTMENT')));
        $quantityInput = inv_decimal('quantity');
        $notes = post_string('notes', 255);
        $allowNegativeRequested = post_int('allow_negative') === 1;

        $allowedMovementTypes = ['IN', 'OUT', 'ADJUST'];
        $allowedSourceTypes = ['PURCHASE', 'OPENING', 'ADJUSTMENT'];

        if (!in_array($movementType, $allowedMovementTypes, true)) {
            flash_set('inventory_error', 'Invalid movement type.', 'danger');
            redirect('modules/inventory/index.php');
        }
        if (!in_array($sourceType, $allowedSourceTypes, true)) {
            flash_set('inventory_error', 'Invalid movement source.', 'danger');
            redirect('modules/inventory/index.php');
        }
        if ($partId <= 0) {
            flash_set('inventory_error', 'Part selection is required.', 'danger');
            redirect('modules/inventory/index.php');
        }

        if ($movementType === 'ADJUST') {
            if ($quantityInput == 0.0) {
                flash_set('inventory_error', 'Adjustment quantity cannot be zero.', 'danger');
                redirect('modules/inventory/index.php');
            }
            $delta = $quantityInput;
            $movementQuantity = $quantityInput;
        } else {
            if ($quantityInput <= 0) {
                flash_set('inventory_error', 'Quantity must be greater than zero.', 'danger');
                redirect('modules/inventory/index.php');
            }
            $movementQuantity = abs($quantityInput);
            $delta = $movementType === 'IN' ? $movementQuantity : -1 * $movementQuantity;
        }

        if ($allowNegativeRequested && !$canNegative) {
            flash_set('inventory_error', 'You are not permitted to post negative stock.', 'danger');
            redirect('modules/inventory/index.php');
        }

        $partStmt = db()->prepare(
            'SELECT id, part_name, part_sku
             FROM parts
             WHERE id = :id
               AND company_id = :company_id
               AND status_code <> "DELETED"
             LIMIT 1'
        );
        $partStmt->execute([
            'id' => $partId,
            'company_id' => $companyId,
        ]);
        $part = $partStmt->fetch();

        if (!$part) {
            flash_set('inventory_error', 'Invalid part selected for this company.', 'danger');
            redirect('modules/inventory/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $currentQty = inv_lock_inventory_row($pdo, $activeGarageId, $partId);
            $newQty = $currentQty + $delta;

            if ($newQty < 0 && !$allowNegativeRequested) {
                throw new RuntimeException('Stock cannot go below zero. Available: ' . number_format($currentQty, 2));
            }

            $update = $pdo->prepare(
                'UPDATE garage_inventory
                 SET quantity = :quantity
                 WHERE garage_id = :garage_id
                   AND part_id = :part_id'
            );
            $update->execute([
                'quantity' => round($newQty, 2),
                'garage_id' => $activeGarageId,
                'part_id' => $partId,
            ]);

            inv_insert_movement($pdo, [
                'company_id' => $companyId,
                'garage_id' => $activeGarageId,
                'part_id' => $partId,
                'movement_type' => $movementType,
                'quantity' => round($movementQuantity, 2),
                'reference_type' => $sourceType,
                'reference_id' => null,
                'movement_uid' => 'adj-' . hash('sha256', $actionToken . '|' . $companyId . '|' . $activeGarageId . '|' . $partId . '|' . microtime(true)),
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => $userId,
            ]);

            $pdo->commit();
            log_audit(
                'inventory',
                'adjust',
                $partId,
                sprintf('Stock %s posted for %s (%s), delta %.2f at garage %d', $movementType, (string) $part['part_name'], (string) $part['part_sku'], $delta, $activeGarageId)
            );
            flash_set('inventory_success', 'Stock movement posted successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('inventory_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/inventory/index.php');
    }

    if ($action === 'stock_transfer') {
        if (!$canTransfer) {
            flash_set('inventory_error', 'You do not have permission to transfer stock.', 'danger');
            redirect('modules/inventory/index.php');
        }

        $actionToken = trim((string) ($_POST['action_token'] ?? ''));
        if (!inv_consume_action_token('stock_transfer', $actionToken)) {
            flash_set('inventory_warning', 'Duplicate or expired transfer request ignored.', 'warning');
            redirect('modules/inventory/index.php');
        }

        $partId = post_int('part_id');
        $toGarageId = post_int('to_garage_id');
        $quantity = abs(inv_decimal('quantity'));
        $notes = post_string('notes', 255);
        $allowNegativeRequested = post_int('allow_negative') === 1;

        if ($partId <= 0 || $toGarageId <= 0 || $quantity <= 0) {
            flash_set('inventory_error', 'Part, destination garage and quantity are required.', 'danger');
            redirect('modules/inventory/index.php');
        }
        if ($toGarageId === $activeGarageId) {
            flash_set('inventory_error', 'Transfer destination must be a different garage.', 'danger');
            redirect('modules/inventory/index.php');
        }
        if ($allowNegativeRequested && !$canNegative) {
            flash_set('inventory_error', 'You are not permitted to transfer into negative stock.', 'danger');
            redirect('modules/inventory/index.php');
        }

        $garageScopeIds = $accessibleGarageIds;
        if ($isSuperAdmin) {
            $garageScopeStmt = db()->prepare('SELECT id FROM garages WHERE company_id = :company_id AND status_code = "ACTIVE"');
            $garageScopeStmt->execute(['company_id' => $companyId]);
            $garageScopeIds = array_map(static fn (array $row): int => (int) $row['id'], $garageScopeStmt->fetchAll());
        }

        if (!in_array($toGarageId, $garageScopeIds, true)) {
            flash_set('inventory_error', 'Destination garage is outside your garage scope.', 'danger');
            redirect('modules/inventory/index.php');
        }

        $partStmt = db()->prepare(
            'SELECT id, part_name, part_sku
             FROM parts
             WHERE id = :id
               AND company_id = :company_id
               AND status_code <> "DELETED"
             LIMIT 1'
        );
        $partStmt->execute([
            'id' => $partId,
            'company_id' => $companyId,
        ]);
        $part = $partStmt->fetch();
        if (!$part) {
            flash_set('inventory_error', 'Invalid part selected for transfer.', 'danger');
            redirect('modules/inventory/index.php');
        }

        $garageCheck = db()->prepare(
            'SELECT id
             FROM garages
             WHERE (id = :from_garage OR id = :to_garage)
               AND company_id = :company_id
               AND status_code = "ACTIVE"
               AND status = "active"'
        );
        $garageCheck->execute([
            'from_garage' => $activeGarageId,
            'to_garage' => $toGarageId,
            'company_id' => $companyId,
        ]);
        $validGarages = array_map(static fn (array $row): int => (int) $row['id'], $garageCheck->fetchAll());
        if (!in_array($activeGarageId, $validGarages, true) || !in_array($toGarageId, $validGarages, true)) {
            flash_set('inventory_error', 'Invalid garage selection for transfer.', 'danger');
            redirect('modules/inventory/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $seedStmt = $pdo->prepare(
                'INSERT INTO garage_inventory (garage_id, part_id, quantity)
                 VALUES (:garage_id, :part_id, 0)
                 ON DUPLICATE KEY UPDATE quantity = quantity'
            );
            $seedStmt->execute(['garage_id' => $activeGarageId, 'part_id' => $partId]);
            $seedStmt->execute(['garage_id' => $toGarageId, 'part_id' => $partId]);

            $lockStmt = $pdo->prepare(
                'SELECT garage_id, quantity
                 FROM garage_inventory
                 WHERE part_id = :part_id
                   AND (garage_id = :from_garage OR garage_id = :to_garage)
                 FOR UPDATE'
            );
            $lockStmt->execute([
                'part_id' => $partId,
                'from_garage' => $activeGarageId,
                'to_garage' => $toGarageId,
            ]);
            $rows = $lockStmt->fetchAll();
            $qtyByGarage = [];
            foreach ($rows as $row) {
                $qtyByGarage[(int) $row['garage_id']] = (float) $row['quantity'];
            }

            $sourceQty = (float) ($qtyByGarage[$activeGarageId] ?? 0.0);
            $targetQty = (float) ($qtyByGarage[$toGarageId] ?? 0.0);
            $newSourceQty = $sourceQty - $quantity;
            $newTargetQty = $targetQty + $quantity;

            if ($newSourceQty < 0 && !$allowNegativeRequested) {
                throw new RuntimeException('Insufficient stock in source garage. Available: ' . number_format($sourceQty, 2));
            }

            $updateStmt = $pdo->prepare(
                'UPDATE garage_inventory
                 SET quantity = :quantity
                 WHERE garage_id = :garage_id
                   AND part_id = :part_id'
            );
            $updateStmt->execute([
                'quantity' => round($newSourceQty, 2),
                'garage_id' => $activeGarageId,
                'part_id' => $partId,
            ]);
            $updateStmt->execute([
                'quantity' => round($newTargetQty, 2),
                'garage_id' => $toGarageId,
                'part_id' => $partId,
            ]);

            $transferRef = inv_generate_transfer_ref($pdo, $companyId);
            $requestUid = hash('sha256', $actionToken . '|transfer|' . $companyId . '|' . $activeGarageId . '|' . $toGarageId . '|' . $partId . '|' . $quantity);

            $transferStmt = $pdo->prepare(
                'INSERT INTO inventory_transfers
                  (company_id, from_garage_id, to_garage_id, part_id, quantity, transfer_ref, request_uid, status_code, notes, created_by)
                 VALUES
                  (:company_id, :from_garage_id, :to_garage_id, :part_id, :quantity, :transfer_ref, :request_uid, "POSTED", :notes, :created_by)'
            );
            $transferStmt->execute([
                'company_id' => $companyId,
                'from_garage_id' => $activeGarageId,
                'to_garage_id' => $toGarageId,
                'part_id' => $partId,
                'quantity' => round($quantity, 2),
                'transfer_ref' => $transferRef,
                'request_uid' => $requestUid,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => $userId,
            ]);

            $transferId = (int) $pdo->lastInsertId();
            $movementNote = $notes !== '' ? $notes : ('Transfer ' . $transferRef);

            inv_insert_movement($pdo, [
                'company_id' => $companyId,
                'garage_id' => $activeGarageId,
                'part_id' => $partId,
                'movement_type' => 'OUT',
                'quantity' => round($quantity, 2),
                'reference_type' => 'TRANSFER',
                'reference_id' => $transferId,
                'movement_uid' => sprintf('transfer-%d-out', $transferId),
                'notes' => $movementNote,
                'created_by' => $userId,
            ]);

            inv_insert_movement($pdo, [
                'company_id' => $companyId,
                'garage_id' => $toGarageId,
                'part_id' => $partId,
                'movement_type' => 'IN',
                'quantity' => round($quantity, 2),
                'reference_type' => 'TRANSFER',
                'reference_id' => $transferId,
                'movement_uid' => sprintf('transfer-%d-in', $transferId),
                'notes' => $movementNote,
                'created_by' => $userId,
            ]);

            $pdo->commit();
            log_audit(
                'inventory',
                'transfer',
                $transferId,
                sprintf(
                    'Transferred %.2f of %s (%s) from garage %d to garage %d',
                    $quantity,
                    (string) $part['part_name'],
                    (string) $part['part_sku'],
                    $activeGarageId,
                    $toGarageId
                )
            );
            flash_set('inventory_success', 'Transfer posted successfully. Ref: ' . $transferRef, 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('inventory_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/inventory/index.php');
    }
}

$garageOptions = [];
if (!empty($accessibleGarageIds)) {
    $placeholders = implode(',', array_fill(0, count($accessibleGarageIds), '?'));
    $sql = "SELECT id, name, code FROM garages WHERE company_id = ? AND status_code = 'ACTIVE' AND status = 'active' AND id IN ({$placeholders}) ORDER BY name ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge([$companyId], $accessibleGarageIds));
    $garageOptions = $stmt->fetchAll();
}

if (empty($garageOptions) && $activeGarageId > 0) {
    $garageStmt = db()->prepare('SELECT id, name, code FROM garages WHERE id = :id AND company_id = :company_id LIMIT 1');
    $garageStmt->execute([
        'id' => $activeGarageId,
        'company_id' => $companyId,
    ]);
    $fallback = $garageStmt->fetch();
    if ($fallback) {
        $garageOptions[] = $fallback;
        $accessibleGarageIds[] = (int) $fallback['id'];
    }
}

$activeGarageName = 'Garage #' . $activeGarageId;
foreach ($garageOptions as $garageOption) {
    if ((int) $garageOption['id'] === $activeGarageId) {
        $activeGarageName = (string) $garageOption['name'];
        break;
    }
}

$historyGarageId = get_int('history_garage_id', $activeGarageId);
if (!in_array($historyGarageId, $accessibleGarageIds, true)) {
    $historyGarageId = $activeGarageId;
}

$historyPartId = get_int('history_part_id', 0);
$sourceFilter = strtoupper(trim((string) ($_GET['source'] ?? '')));
$allowedSourceFilter = ['PURCHASE', 'JOB_CARD', 'ADJUSTMENT', 'OPENING', 'TRANSFER'];
if (!in_array($sourceFilter, $allowedSourceFilter, true)) {
    $sourceFilter = '';
}

$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo = trim((string) ($_GET['to'] ?? ''));
if (!inv_is_valid_date($dateFrom)) {
    $dateFrom = '';
}
if (!inv_is_valid_date($dateTo)) {
    $dateTo = '';
}

$partsStockStmt = db()->prepare(
    'SELECT p.id, p.part_name, p.part_sku, p.hsn_code, p.unit, p.selling_price, p.gst_rate, p.min_stock, p.status_code,
            pc.category_name,
            COALESCE(gi.quantity, 0) AS stock_qty,
            CASE
                WHEN COALESCE(gi.quantity, 0) <= 0 THEN "OUT"
                WHEN COALESCE(gi.quantity, 0) <= p.min_stock THEN "LOW"
                ELSE "OK"
            END AS stock_state
     FROM parts p
     LEFT JOIN part_categories pc ON pc.id = p.category_id
     LEFT JOIN garage_inventory gi ON gi.part_id = p.id AND gi.garage_id = :garage_id
     WHERE p.company_id = :company_id
       AND p.status_code <> "DELETED"
     ORDER BY p.part_name ASC'
);
$partsStockStmt->execute([
    'garage_id' => $activeGarageId,
    'company_id' => $companyId,
]);
$parts = $partsStockStmt->fetchAll();

$movementWhere = ['im.company_id = :company_id', 'im.garage_id = :garage_id'];
$movementParams = [
    'company_id' => $companyId,
    'garage_id' => $historyGarageId,
];

if ($historyPartId > 0) {
    $movementWhere[] = 'im.part_id = :part_id';
    $movementParams['part_id'] = $historyPartId;
}
if ($sourceFilter !== '') {
    $movementWhere[] = 'im.reference_type = :reference_type';
    $movementParams['reference_type'] = $sourceFilter;
}
if ($dateFrom !== '') {
    $movementWhere[] = 'im.created_at >= :date_from';
    $movementParams['date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $movementWhere[] = 'im.created_at <= :date_to';
    $movementParams['date_to'] = $dateTo . ' 23:59:59';
}

$movementsSql =
    'SELECT im.*,
            p.part_name,
            p.part_sku,
            p.unit,
            g.name AS garage_name,
            u.name AS created_by_name,
            CASE
                WHEN im.movement_type = "OUT" THEN -1 * ABS(im.quantity)
                WHEN im.movement_type = "IN" THEN ABS(im.quantity)
                ELSE im.quantity
            END AS signed_qty
     FROM inventory_movements im
     INNER JOIN parts p ON p.id = im.part_id
     INNER JOIN garages g ON g.id = im.garage_id
     LEFT JOIN users u ON u.id = im.created_by
     WHERE ' . implode(' AND ', $movementWhere) . '
     ORDER BY im.id DESC
     LIMIT 100';
$movementsStmt = db()->prepare($movementsSql);
$movementsStmt->execute($movementParams);
$movements = $movementsStmt->fetchAll();

$transferStmt = db()->prepare(
    'SELECT it.*,
            p.part_name,
            p.part_sku,
            fg.name AS from_garage_name,
            tg.name AS to_garage_name,
            u.name AS created_by_name
     FROM inventory_transfers it
     INNER JOIN parts p ON p.id = it.part_id
     INNER JOIN garages fg ON fg.id = it.from_garage_id
     INNER JOIN garages tg ON tg.id = it.to_garage_id
     LEFT JOIN users u ON u.id = it.created_by
     WHERE it.company_id = :company_id
       AND (it.from_garage_id = :garage_id OR it.to_garage_id = :garage_id)
     ORDER BY it.id DESC
     LIMIT 40'
);
$transferStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $historyGarageId,
]);
$transfers = $transferStmt->fetchAll();

$partHistorySummary = null;
if ($historyPartId > 0) {
    $summaryStmt = db()->prepare(
        'SELECT
            COUNT(*) AS movement_count,
            COALESCE(SUM(CASE WHEN movement_type = "IN" THEN quantity ELSE 0 END), 0) AS total_in,
            COALESCE(SUM(CASE WHEN movement_type = "OUT" THEN quantity ELSE 0 END), 0) AS total_out,
            COALESCE(SUM(CASE WHEN movement_type = "ADJUST" THEN quantity ELSE 0 END), 0) AS total_adjust
         FROM inventory_movements
         WHERE company_id = :company_id
           AND garage_id = :garage_id
           AND part_id = :part_id'
    );
    $summaryStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $historyGarageId,
        'part_id' => $historyPartId,
    ]);
    $partHistorySummary = $summaryStmt->fetch() ?: null;

    if ($partHistorySummary) {
        $stockStmt = db()->prepare(
            'SELECT COALESCE(quantity, 0)
             FROM garage_inventory
             WHERE garage_id = :garage_id
               AND part_id = :part_id'
        );
        $stockStmt->execute([
            'garage_id' => $historyGarageId,
            'part_id' => $historyPartId,
        ]);
        $partHistorySummary['current_stock'] = (float) ($stockStmt->fetchColumn() ?: 0.0);
    }
}

$hasVisCatalog = false;
$visVariantId = get_int('vis_variant_id', 0);
$visVariantOptions = [];
$visCompatibleParts = [];
$visFastMoving = [];
$visError = null;

try {
    $visCountStmt = db()->query('SELECT COUNT(*) FROM vis_variants WHERE status_code = "ACTIVE"');
    $hasVisCatalog = ((int) $visCountStmt->fetchColumn() > 0);

    if ($hasVisCatalog) {
        $variantStmt = db()->query(
            'SELECT vv.id, vv.variant_name, vm.model_name, vb.brand_name
             FROM vis_variants vv
             INNER JOIN vis_models vm ON vm.id = vv.model_id
             INNER JOIN vis_brands vb ON vb.id = vm.brand_id
             WHERE vv.status_code = "ACTIVE"
               AND vm.status_code = "ACTIVE"
               AND vb.status_code = "ACTIVE"
             ORDER BY vb.brand_name ASC, vm.model_name ASC, vv.variant_name ASC
             LIMIT 300'
        );
        $visVariantOptions = $variantStmt->fetchAll();

        if ($visVariantId > 0) {
            $compatStmt = db()->prepare(
                'SELECT p.id, p.part_name, p.part_sku, p.unit, p.min_stock,
                        COALESCE(gi.quantity, 0) AS stock_qty,
                        vpc.compatibility_note
                 FROM vis_part_compatibility vpc
                 INNER JOIN parts p
                    ON p.id = vpc.part_id
                   AND p.company_id = :company_id
                   AND p.status_code = "ACTIVE"
                 LEFT JOIN garage_inventory gi
                    ON gi.part_id = p.id
                   AND gi.garage_id = :garage_id
                 WHERE vpc.company_id = :company_id
                   AND vpc.variant_id = :variant_id
                   AND vpc.status_code = "ACTIVE"
                 ORDER BY p.part_name ASC
                 LIMIT 80'
            );
            $compatStmt->execute([
                'company_id' => $companyId,
                'garage_id' => $activeGarageId,
                'variant_id' => $visVariantId,
            ]);
            $visCompatibleParts = $compatStmt->fetchAll();
        }

        $fastStmt = db()->prepare(
            'SELECT v.vehicle_type, p.id, p.part_name, p.part_sku, SUM(jp.quantity) AS total_qty
             FROM job_parts jp
             INNER JOIN job_cards jc ON jc.id = jp.job_card_id
             INNER JOIN vehicles v ON v.id = jc.vehicle_id
             INNER JOIN parts p ON p.id = jp.part_id
             WHERE jc.company_id = :company_id
               AND jc.garage_id = :garage_id
               AND jc.status IN ("COMPLETED", "CLOSED")
               AND jc.status_code <> "DELETED"
             GROUP BY v.vehicle_type, p.id, p.part_name, p.part_sku
             ORDER BY v.vehicle_type ASC, total_qty DESC'
        );
        $fastStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $activeGarageId,
        ]);

        $fastRows = $fastStmt->fetchAll();
        $bucket = [];
        foreach ($fastRows as $row) {
            $type = (string) $row['vehicle_type'];
            if (!isset($bucket[$type])) {
                $bucket[$type] = [];
            }
            if (count($bucket[$type]) < 5) {
                $bucket[$type][] = $row;
            }
        }
        $visFastMoving = $bucket;
    }
} catch (Throwable $exception) {
    $hasVisCatalog = false;
    $visVariantOptions = [];
    $visCompatibleParts = [];
    $visFastMoving = [];
    $visError = 'VIS data is unavailable. Inventory continues without VIS.';
}

$adjustActionToken = inv_issue_action_token('stock_adjust');
$transferActionToken = inv_issue_action_token('stock_transfer');

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Inventory & Stock Intelligence</h3></div>
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
      <div class="card mb-3">
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-3">
              <div class="small-box text-bg-primary">
                <div class="inner">
                  <h4><?= e((string) count($parts)); ?></h4>
                  <p>Tracked Parts (Active Garage)</p>
                </div>
                <span class="small-box-icon"><i class="bi bi-box-seam"></i></span>
              </div>
            </div>
            <div class="col-md-3">
              <div class="small-box text-bg-danger">
                <div class="inner">
                  <?php $outCount = count(array_filter($parts, static fn (array $part): bool => (string) ($part['stock_state'] ?? 'OK') === 'OUT')); ?>
                  <h4><?= e((string) $outCount); ?></h4>
                  <p>Out of Stock</p>
                </div>
                <span class="small-box-icon"><i class="bi bi-exclamation-octagon"></i></span>
              </div>
            </div>
            <div class="col-md-3">
              <div class="small-box text-bg-warning">
                <div class="inner">
                  <?php $lowCount = count(array_filter($parts, static fn (array $part): bool => (string) ($part['stock_state'] ?? 'OK') === 'LOW')); ?>
                  <h4><?= e((string) $lowCount); ?></h4>
                  <p>Low Stock</p>
                </div>
                <span class="small-box-icon"><i class="bi bi-graph-down-arrow"></i></span>
              </div>
            </div>
            <div class="col-md-3">
              <div class="small-box text-bg-success">
                <div class="inner">
                  <h4><?= e((string) count($movements)); ?></h4>
                  <p>Recent Movements</p>
                </div>
                <span class="small-box-icon"><i class="bi bi-journal-text"></i></span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <?php if ($canAdjust || $canTransfer): ?>
        <div class="row g-3 mb-3">
          <?php if ($canAdjust): ?>
            <div class="col-lg-6">
              <div class="card card-info">
                <div class="card-header"><h3 class="card-title">Manual Stock Adjustment</h3></div>
                <form method="post">
                  <div class="card-body row g-2">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="stock_adjust">
                    <input type="hidden" name="action_token" value="<?= e($adjustActionToken); ?>">

                    <div class="col-md-12">
                      <label class="form-label">Part</label>
                      <select name="part_id" class="form-select" required>
                        <option value="">Select Part</option>
                        <?php foreach ($parts as $part): ?>
                          <option value="<?= (int) $part['id']; ?>">
                            <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>) | Stock <?= e(number_format((float) $part['stock_qty'], 2)); ?> <?= e((string) $part['unit']); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Movement</label>
                      <select name="movement_type" class="form-select" required>
                        <option value="IN">Stock In (+)</option>
                        <option value="OUT">Stock Out (-)</option>
                        <option value="ADJUST">Adjustment (+/-)</option>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Source</label>
                      <select name="source_type" class="form-select" required>
                        <option value="PURCHASE">Purchase</option>
                        <option value="OPENING">Opening</option>
                        <option value="ADJUSTMENT">Adjustment</option>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Quantity</label>
                      <input type="number" step="0.01" name="quantity" class="form-control" required>
                    </div>
                    <?php if ($canNegative): ?>
                      <div class="col-md-12">
                        <div class="form-check mt-2">
                          <input class="form-check-input" type="checkbox" value="1" id="allow_negative_adjust" name="allow_negative">
                          <label class="form-check-label" for="allow_negative_adjust">Allow negative stock for this adjustment (role-restricted)</label>
                        </div>
                      </div>
                    <?php endif; ?>
                    <div class="col-md-12">
                      <label class="form-label">Notes</label>
                      <input type="text" name="notes" class="form-control" maxlength="255" placeholder="Reason / reference note">
                    </div>
                  </div>
                  <div class="card-footer">
                    <button type="submit" class="btn btn-info">Post Adjustment</button>
                  </div>
                </form>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($canTransfer): ?>
            <div class="col-lg-6">
              <div class="card card-primary">
                <div class="card-header"><h3 class="card-title">Garage Stock Transfer</h3></div>
                <form method="post">
                  <div class="card-body row g-2">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="stock_transfer">
                    <input type="hidden" name="action_token" value="<?= e($transferActionToken); ?>">

                    <div class="col-md-12">
                      <label class="form-label">Part</label>
                      <select name="part_id" class="form-select" required>
                        <option value="">Select Part</option>
                        <?php foreach ($parts as $part): ?>
                          <option value="<?= (int) $part['id']; ?>">
                            <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>) | Source stock <?= e(number_format((float) $part['stock_qty'], 2)); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">From Garage</label>
                      <input type="text" class="form-control" value="<?= e($activeGarageName); ?>" readonly>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">To Garage</label>
                      <select name="to_garage_id" class="form-select" required>
                        <option value="">Select Destination</option>
                        <?php foreach ($garageOptions as $garage): ?>
                          <?php if ((int) $garage['id'] === $activeGarageId) { continue; } ?>
                          <option value="<?= (int) $garage['id']; ?>">
                            <?= e((string) $garage['name']); ?> (<?= e((string) $garage['code']); ?>)
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Quantity</label>
                      <input type="number" step="0.01" min="0.01" name="quantity" class="form-control" required>
                    </div>
                    <?php if ($canNegative): ?>
                      <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check mb-2">
                          <input class="form-check-input" type="checkbox" value="1" id="allow_negative_transfer" name="allow_negative">
                          <label class="form-check-label" for="allow_negative_transfer">Allow negative source stock</label>
                        </div>
                      </div>
                    <?php endif; ?>
                    <div class="col-md-12">
                      <label class="form-label">Notes</label>
                      <input type="text" name="notes" class="form-control" maxlength="255" placeholder="Transfer reason / challan reference">
                    </div>
                  </div>
                  <div class="card-footer">
                    <button type="submit" class="btn btn-primary">Post Transfer</button>
                  </div>
                </form>
              </div>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Movement History Filters</h3></div>
        <form method="get">
          <div class="card-body row g-2">
            <div class="col-md-3">
              <label class="form-label">Garage</label>
              <select name="history_garage_id" class="form-select">
                <?php foreach ($garageOptions as $garage): ?>
                  <option value="<?= (int) $garage['id']; ?>" <?= ((int) $garage['id'] === $historyGarageId) ? 'selected' : ''; ?>>
                    <?= e((string) $garage['name']); ?> (<?= e((string) $garage['code']); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Part</label>
              <select name="history_part_id" class="form-select">
                <option value="0">All Parts</option>
                <?php foreach ($parts as $part): ?>
                  <option value="<?= (int) $part['id']; ?>" <?= ((int) $part['id'] === $historyPartId) ? 'selected' : ''; ?>>
                    <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Source</label>
              <select name="source" class="form-select">
                <option value="">All Sources</option>
                <?php foreach ($allowedSourceFilter as $source): ?>
                  <option value="<?= e($source); ?>" <?= $sourceFilter === $source ? 'selected' : ''; ?>>
                    <?= e(inv_movement_source_label($source)); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">From</label>
              <input type="date" name="from" value="<?= e($dateFrom); ?>" class="form-control">
            </div>
            <div class="col-md-2">
              <label class="form-label">To</label>
              <input type="date" name="to" value="<?= e($dateTo); ?>" class="form-control">
            </div>
          </div>
          <div class="card-footer">
            <button type="submit" class="btn btn-outline-primary">Apply Filters</button>
            <a class="btn btn-outline-secondary" href="<?= e(url('modules/inventory/index.php')); ?>">Reset</a>
          </div>
        </form>
      </div>

      <?php if ($partHistorySummary !== null): ?>
        <div class="card mb-3 card-outline card-secondary">
          <div class="card-header"><h3 class="card-title">Part History Snapshot</h3></div>
          <div class="card-body row g-2">
            <div class="col-md-3"><strong>Movement Count:</strong> <?= e((string) $partHistorySummary['movement_count']); ?></div>
            <div class="col-md-3"><strong>Total In:</strong> <?= e(number_format((float) $partHistorySummary['total_in'], 2)); ?></div>
            <div class="col-md-3"><strong>Total Out:</strong> <?= e(number_format((float) $partHistorySummary['total_out'], 2)); ?></div>
            <div class="col-md-3"><strong>Adjust Net:</strong> <?= e(number_format((float) $partHistorySummary['total_adjust'], 2)); ?></div>
            <div class="col-md-12"><strong>Current Stock (Selected Garage):</strong> <?= e(number_format((float) ($partHistorySummary['current_stock'] ?? 0), 2)); ?></div>
          </div>
        </div>
      <?php endif; ?>

      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Parts Stock (Active Garage)</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Part Name</th>
                <th>Category</th>
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
                <tr><td colspan="9" class="text-center text-muted py-4">No parts configured.</td></tr>
              <?php else: ?>
                <?php foreach ($parts as $part): ?>
                  <?php
                    $state = (string) ($part['stock_state'] ?? 'OK');
                    $badgeClass = $state === 'OUT' ? 'danger' : ($state === 'LOW' ? 'warning' : 'success');
                    $label = $state === 'OUT' ? 'Out of Stock' : ($state === 'LOW' ? 'Low Stock' : 'OK');
                  ?>
                  <tr>
                    <td><code><?= e((string) $part['part_sku']); ?></code></td>
                    <td><?= e((string) $part['part_name']); ?></td>
                    <td><?= e((string) ($part['category_name'] ?? '-')); ?></td>
                    <td><?= e((string) ($part['hsn_code'] ?? '-')); ?></td>
                    <td><?= e(format_currency((float) $part['selling_price'])); ?></td>
                    <td><?= e(number_format((float) $part['gst_rate'], 2)); ?></td>
                    <td><?= e(number_format((float) $part['min_stock'], 2)); ?> <?= e((string) $part['unit']); ?></td>
                    <td><?= e(number_format((float) $part['stock_qty'], 2)); ?> <?= e((string) $part['unit']); ?></td>
                    <td><span class="badge text-bg-<?= e($badgeClass); ?>"><?= e($label); ?></span></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Stock Movement History (Filtered)</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Date</th>
                <th>Garage</th>
                <th>Part</th>
                <th>Type</th>
                <th>Signed Qty</th>
                <th>Source</th>
                <th>Reference</th>
                <th>User</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($movements)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No movements for selected filters.</td></tr>
              <?php else: ?>
                <?php foreach ($movements as $movement): ?>
                  <?php
                    $signedQty = (float) ($movement['signed_qty'] ?? 0);
                    $qtyClass = $signedQty < 0 ? 'text-danger' : ($signedQty > 0 ? 'text-success' : 'text-muted');
                  ?>
                  <tr>
                    <td><?= e((string) $movement['created_at']); ?></td>
                    <td><?= e((string) $movement['garage_name']); ?></td>
                    <td><?= e((string) $movement['part_name']); ?> (<?= e((string) $movement['part_sku']); ?>)</td>
                    <td><span class="badge text-bg-secondary"><?= e((string) $movement['movement_type']); ?></span></td>
                    <td class="<?= e($qtyClass); ?>"><?= e(number_format($signedQty, 2)); ?> <?= e((string) $movement['unit']); ?></td>
                    <td><?= e(inv_movement_source_label((string) $movement['reference_type'])); ?></td>
                    <td><?= e((string) ($movement['reference_id'] ?? '-')); ?></td>
                    <td><?= e((string) ($movement['created_by_name'] ?? '-')); ?></td>
                    <td><?= e((string) ($movement['notes'] ?? '-')); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Transfer Audit Trail</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Date</th>
                <th>Ref</th>
                <th>Part</th>
                <th>From</th>
                <th>To</th>
                <th>Qty</th>
                <th>Status</th>
                <th>User</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($transfers)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No transfers for selected garage.</td></tr>
              <?php else: ?>
                <?php foreach ($transfers as $transfer): ?>
                  <tr>
                    <td><?= e((string) $transfer['created_at']); ?></td>
                    <td><code><?= e((string) $transfer['transfer_ref']); ?></code></td>
                    <td><?= e((string) $transfer['part_name']); ?> (<?= e((string) $transfer['part_sku']); ?>)</td>
                    <td><?= e((string) $transfer['from_garage_name']); ?></td>
                    <td><?= e((string) $transfer['to_garage_name']); ?></td>
                    <td><?= e(number_format((float) $transfer['quantity'], 2)); ?></td>
                    <td><span class="badge text-bg-secondary"><?= e((string) $transfer['status_code']); ?></span></td>
                    <td><?= e((string) ($transfer['created_by_name'] ?? '-')); ?></td>
                    <td><?= e((string) ($transfer['notes'] ?? '-')); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card card-outline card-info <?= $hasVisCatalog ? '' : 'collapsed-card'; ?>">
        <div class="card-header">
          <h3 class="card-title">VIS Intelligence (Optional)</h3>
          <div class="card-tools">
            <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse"><i class="bi bi-dash-lg"></i></button>
          </div>
        </div>
        <div class="card-body">
          <?php if ($visError !== null): ?>
            <div class="text-muted"><?= e($visError); ?></div>
          <?php elseif (!$hasVisCatalog): ?>
            <div class="text-muted">No VIS catalog data found. Inventory runs fully without VIS.</div>
          <?php else: ?>
            <form method="get" class="row g-2 mb-3">
              <input type="hidden" name="history_garage_id" value="<?= (int) $historyGarageId; ?>">
              <input type="hidden" name="history_part_id" value="<?= (int) $historyPartId; ?>">
              <input type="hidden" name="source" value="<?= e($sourceFilter); ?>">
              <input type="hidden" name="from" value="<?= e($dateFrom); ?>">
              <input type="hidden" name="to" value="<?= e($dateTo); ?>">
              <div class="col-md-8">
                <label class="form-label">Compatible Parts By VIS Variant</label>
                <select name="vis_variant_id" class="form-select">
                  <option value="0">Select Variant</option>
                  <?php foreach ($visVariantOptions as $variant): ?>
                    <option value="<?= (int) $variant['id']; ?>" <?= ((int) $variant['id'] === $visVariantId) ? 'selected' : ''; ?>>
                      <?= e((string) $variant['brand_name']); ?> / <?= e((string) $variant['model_name']); ?> / <?= e((string) $variant['variant_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-info">Load Suggestions</button>
              </div>
            </form>

            <?php if ($visVariantId > 0): ?>
              <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered">
                  <thead>
                    <tr>
                      <th>Part</th>
                      <th>Stock</th>
                      <th>Min Stock</th>
                      <th>Compatibility Note</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($visCompatibleParts)): ?>
                      <tr><td colspan="4" class="text-center text-muted">No VIS compatible parts for selected variant.</td></tr>
                    <?php else: ?>
                      <?php foreach ($visCompatibleParts as $compat): ?>
                        <?php $isLow = ((float) $compat['stock_qty'] <= (float) $compat['min_stock']); ?>
                        <tr>
                          <td><?= e((string) $compat['part_name']); ?> (<?= e((string) $compat['part_sku']); ?>)</td>
                          <td class="<?= $isLow ? 'text-danger' : 'text-success'; ?>"><?= e(number_format((float) $compat['stock_qty'], 2)); ?> <?= e((string) $compat['unit']); ?></td>
                          <td><?= e(number_format((float) $compat['min_stock'], 2)); ?></td>
                          <td><?= e((string) ($compat['compatibility_note'] ?? '-')); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <h6 class="mb-2">Fast-Moving Parts by Vehicle Type (Garage)</h6>
            <?php if (empty($visFastMoving)): ?>
              <div class="text-muted">No completed/closed job data available yet.</div>
            <?php else: ?>
              <div class="row g-3">
                <?php foreach ($visFastMoving as $vehicleType => $items): ?>
                  <div class="col-lg-4">
                    <div class="card card-outline card-secondary h-100 mb-0">
                      <div class="card-header"><h3 class="card-title mb-0"><?= e((string) $vehicleType); ?></h3></div>
                      <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                          <?php foreach ($items as $item): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                              <span><?= e((string) $item['part_name']); ?> (<?= e((string) $item['part_sku']); ?>)</span>
                              <span class="badge text-bg-dark"><?= e(number_format((float) $item['total_qty'], 2)); ?></span>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
