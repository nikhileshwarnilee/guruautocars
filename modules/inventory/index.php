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
$purchaseTablesReady = table_columns('purchases') !== [] && table_columns('purchase_items') !== [];
$tempStockReady = table_columns('temp_stock_entries') !== [] && table_columns('temp_stock_events') !== [];

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

function inv_value_has_fraction(float $value): bool
{
    return abs($value - round($value)) > 0.00001;
}

function inv_assert_part_quantity_allowed(int $companyId, array $part, float $quantity, string $contextLabel): void
{
    $partUnitCode = part_unit_normalize_code((string) ($part['unit'] ?? ''));
    if ($partUnitCode === '') {
        $partUnitCode = 'PCS';
    }

    if (!part_unit_allows_decimal($companyId, $partUnitCode) && inv_value_has_fraction(abs($quantity))) {
        $partName = trim((string) ($part['part_name'] ?? 'Selected part'));
        throw new RuntimeException($contextLabel . ' quantity for ' . $partName . ' must be a whole number (' . $partUnitCode . ').');
    }
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

function inv_generate_temp_ref(PDO $pdo, int $companyId): string
{
    for ($i = 0; $i < 10; $i++) {
        $ref = sprintf('TMP-%s-%03d', date('ymdHis'), random_int(1, 999));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM temp_stock_entries WHERE company_id = :company_id AND temp_ref = :temp_ref');
        $stmt->execute([
            'company_id' => $companyId,
            'temp_ref' => $ref,
        ]);

        if ((int) $stmt->fetchColumn() === 0) {
            return $ref;
        }
    }

    return sprintf('TMP-%s-%s', date('ymdHis'), bin2hex(random_bytes(3)));
}

function inv_is_valid_date(?string $date): bool
{
    if ($date === null || $date === '') {
        return false;
    }

    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

function inv_format_datetime(?string $value): string
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

function inv_movement_source_label(string $source): string
{
    return match ($source) {
        'JOB_CARD' => 'JOB',
        'ADJUSTMENT' => 'ADJUSTMENT',
        'PURCHASE' => 'PURCHASE',
        'TRANSFER' => 'TRANSFER',
        'OPENING' => 'OPENING',
        'CUSTOMER_RETURN' => 'CUSTOMER RETURN',
        'VENDOR_RETURN' => 'VENDOR RETURN',
        default => $source,
    };
}

function inv_temp_status_badge_class(string $status): string
{
    return match ($status) {
        'OPEN' => 'primary',
        'PURCHASED' => 'success',
        'RETURNED' => 'secondary',
        'CONSUMED' => 'danger',
        default => 'secondary',
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

    if ($action === 'temp_stock_in') {
        if (!$canAdjust) {
            flash_set('inventory_error', 'You do not have permission to post temporary stock.', 'danger');
            redirect('modules/inventory/index.php');
        }
        if (!$tempStockReady) {
            flash_set('inventory_error', 'Temporary stock tables are missing. Run database/temporary_stock_management_upgrade.sql first.', 'danger');
            redirect('modules/inventory/index.php');
        }

        $actionToken = trim((string) ($_POST['action_token'] ?? ''));
        if (!inv_consume_action_token('temp_stock_in', $actionToken)) {
            flash_set('inventory_warning', 'Duplicate or expired temporary stock request ignored.', 'warning');
            redirect('modules/inventory/index.php');
        }

        $partId = post_int('part_id');
        $quantity = abs(inv_decimal('quantity'));
        $notes = post_string('notes', 255);

        if ($partId <= 0 || $quantity <= 0) {
            flash_set('inventory_error', 'Part and quantity are required for temporary stock.', 'danger');
            redirect('modules/inventory/index.php');
        }

        $partStmt = db()->prepare(
            'SELECT id, part_name, part_sku, unit
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
            flash_set('inventory_error', 'Invalid part selected for temporary stock.', 'danger');
            redirect('modules/inventory/index.php');
        }

        try {
            inv_assert_part_quantity_allowed($companyId, (array) $part, $quantity, 'Temporary stock');
        } catch (Throwable $exception) {
            flash_set('inventory_error', $exception->getMessage(), 'danger');
            redirect('modules/inventory/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $tempRef = inv_generate_temp_ref($pdo, $companyId);

            $entryInsert = $pdo->prepare(
                'INSERT INTO temp_stock_entries
                  (company_id, garage_id, temp_ref, part_id, quantity, status_code, notes, created_by)
                 VALUES
                  (:company_id, :garage_id, :temp_ref, :part_id, :quantity, "OPEN", :notes, :created_by)'
            );
            $entryInsert->execute([
                'company_id' => $companyId,
                'garage_id' => $activeGarageId,
                'temp_ref' => $tempRef,
                'part_id' => $partId,
                'quantity' => round($quantity, 2),
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => $userId,
            ]);
            $entryId = (int) $pdo->lastInsertId();

            $eventInsert = $pdo->prepare(
                'INSERT INTO temp_stock_events
                  (temp_entry_id, company_id, garage_id, event_type, quantity, from_status, to_status, notes, purchase_id, created_by)
                 VALUES
                  (:temp_entry_id, :company_id, :garage_id, "TEMP_IN", :quantity, NULL, "OPEN", :notes, NULL, :created_by)'
            );
            $eventInsert->execute([
                'temp_entry_id' => $entryId,
                'company_id' => $companyId,
                'garage_id' => $activeGarageId,
                'quantity' => round($quantity, 2),
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => $userId,
            ]);

            $pdo->commit();
            log_audit(
                'inventory',
                'temp_in',
                $entryId,
                sprintf('Temporary stock %s created for %s (%s), qty %.2f', $tempRef, (string) $part['part_name'], (string) $part['part_sku'], $quantity),
                [
                    'entity' => 'temp_stock_entry',
                    'source' => 'UI',
                    'after' => [
                        'temp_ref' => $tempRef,
                        'garage_id' => $activeGarageId,
                        'part_id' => $partId,
                        'quantity' => round($quantity, 2),
                        'status_code' => 'OPEN',
                        'notes' => $notes !== '' ? $notes : null,
                    ],
                ]
            );
            flash_set('inventory_success', 'Temporary stock recorded with ref ' . $tempRef . '.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('inventory_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/inventory/index.php');
    }

    if ($action === 'resolve_temp_stock') {
        if (!$canAdjust) {
            flash_set('inventory_error', 'You do not have permission to resolve temporary stock.', 'danger');
            redirect('modules/inventory/index.php');
        }
        if (!$tempStockReady) {
            flash_set('inventory_error', 'Temporary stock tables are missing. Run database/temporary_stock_management_upgrade.sql first.', 'danger');
            redirect('modules/inventory/index.php');
        }

        $entryId = post_int('temp_entry_id');
        $resolution = strtoupper(trim((string) ($_POST['resolution'] ?? '')));
        $resolutionNotes = post_string('resolution_notes', 255);
        $confirmConsumed = post_int('confirm_consumed') === 1;

        if ($entryId <= 0) {
            flash_set('inventory_error', 'Invalid temporary stock reference.', 'danger');
            redirect('modules/inventory/index.php');
        }

        $actionToken = trim((string) ($_POST['action_token'] ?? ''));
        if (!inv_consume_action_token('resolve_temp_stock_' . $entryId, $actionToken)) {
            flash_set('inventory_warning', 'Duplicate or expired temporary stock resolution request ignored.', 'warning');
            redirect('modules/inventory/index.php');
        }

        $allowedResolutions = ['RETURNED', 'PURCHASED', 'CONSUMED'];
        if (!in_array($resolution, $allowedResolutions, true)) {
            flash_set('inventory_error', 'Invalid temporary stock resolution.', 'danger');
            redirect('modules/inventory/index.php');
        }
        if ($resolution === 'CONSUMED' && !$confirmConsumed) {
            flash_set('inventory_error', 'Consumed resolution requires explicit confirmation.', 'danger');
            redirect('modules/inventory/index.php');
        }
        if ($resolution === 'PURCHASED' && !$purchaseTablesReady) {
            flash_set('inventory_error', 'Purchase module tables are missing. Run database/purchase_module_upgrade.sql before converting to purchase.', 'danger');
            redirect('modules/inventory/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $entryStmt = $pdo->prepare(
                'SELECT te.id, te.temp_ref, te.part_id, te.quantity, te.status_code,
                        p.part_name, p.part_sku, p.unit, p.purchase_price, p.gst_rate
                 FROM temp_stock_entries te
                 INNER JOIN parts p
                    ON p.id = te.part_id
                   AND p.company_id = te.company_id
                   AND p.status_code <> "DELETED"
                 WHERE te.id = :id
                   AND te.company_id = :company_id
                   AND te.garage_id = :garage_id
                 FOR UPDATE'
            );
            $entryStmt->execute([
                'id' => $entryId,
                'company_id' => $companyId,
                'garage_id' => $activeGarageId,
            ]);
            $entry = $entryStmt->fetch();
            if (!$entry) {
                throw new RuntimeException('Temporary stock entry not found for this garage.');
            }
            if ((string) ($entry['status_code'] ?? '') !== 'OPEN') {
                throw new RuntimeException('Temporary stock entry is already resolved.');
            }

            $partId = (int) $entry['part_id'];
            $qty = round((float) $entry['quantity'], 2);
            $purchaseId = null;
            $currentQty = null;
            $newQty = null;

            if ($resolution === 'PURCHASED') {
                inv_assert_part_quantity_allowed($companyId, (array) $entry, $qty, 'Temporary stock purchase conversion');
            }

            if ($resolution === 'PURCHASED') {
                $unitCost = round((float) ($entry['purchase_price'] ?? 0), 2);
                $gstRate = round((float) ($entry['gst_rate'] ?? 0), 2);
                if ($gstRate < 0) {
                    $gstRate = 0.0;
                }
                if ($gstRate > 100) {
                    $gstRate = 100.0;
                }

                $taxableAmount = round($qty * $unitCost, 2);
                $gstAmount = round(($taxableAmount * $gstRate) / 100, 2);
                $lineTotal = round($taxableAmount + $gstAmount, 2);
                $tempRef = (string) ($entry['temp_ref'] ?? '');
                $purchaseNote = 'Converted from temporary stock ' . $tempRef;
                if ($resolutionNotes !== '') {
                    $purchaseNote .= ' | ' . $resolutionNotes;
                }

                $currentQty = inv_lock_inventory_row($pdo, $activeGarageId, $partId);
                $newQty = $currentQty + $qty;

                $stockUpdate = $pdo->prepare(
                    'UPDATE garage_inventory
                     SET quantity = :quantity
                     WHERE garage_id = :garage_id
                       AND part_id = :part_id'
                );
                $stockUpdate->execute([
                    'quantity' => round($newQty, 2),
                    'garage_id' => $activeGarageId,
                    'part_id' => $partId,
                ]);

                $purchaseInsert = $pdo->prepare(
                    'INSERT INTO purchases
                      (company_id, garage_id, vendor_id, invoice_number, purchase_date, purchase_source, assignment_status, purchase_status, payment_status, taxable_amount, gst_amount, grand_total, notes, created_by)
                     VALUES
                      (:company_id, :garage_id, NULL, NULL, :purchase_date, "TEMP_CONVERSION", "UNASSIGNED", "DRAFT", "UNPAID", :taxable_amount, :gst_amount, :grand_total, :notes, :created_by)'
                );
                $purchaseInsert->execute([
                    'company_id' => $companyId,
                    'garage_id' => $activeGarageId,
                    'purchase_date' => date('Y-m-d'),
                    'taxable_amount' => $taxableAmount,
                    'gst_amount' => $gstAmount,
                    'grand_total' => $lineTotal,
                    'notes' => $purchaseNote,
                    'created_by' => $userId,
                ]);
                $purchaseId = (int) $pdo->lastInsertId();

                $purchaseItemInsert = $pdo->prepare(
                    'INSERT INTO purchase_items
                      (purchase_id, part_id, quantity, unit_cost, gst_rate, taxable_amount, gst_amount, total_amount)
                     VALUES
                      (:purchase_id, :part_id, :quantity, :unit_cost, :gst_rate, :taxable_amount, :gst_amount, :total_amount)'
                );
                $purchaseItemInsert->execute([
                    'purchase_id' => $purchaseId,
                    'part_id' => $partId,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'gst_rate' => $gstRate,
                    'taxable_amount' => $taxableAmount,
                    'gst_amount' => $gstAmount,
                    'total_amount' => $lineTotal,
                ]);

                inv_insert_movement($pdo, [
                    'company_id' => $companyId,
                    'garage_id' => $activeGarageId,
                    'part_id' => $partId,
                    'movement_type' => 'IN',
                    'quantity' => $qty,
                    'reference_type' => 'PURCHASE',
                    'reference_id' => $purchaseId,
                    'movement_uid' => 'tmppur-' . substr(hash('sha256', $actionToken . '|' . $entryId . '|' . microtime(true)), 0, 56),
                    'notes' => $purchaseNote,
                    'created_by' => $userId,
                ]);
            }

            $entryUpdate = $pdo->prepare(
                'UPDATE temp_stock_entries
                 SET status_code = :status_code,
                     resolved_at = NOW(),
                     resolved_by = :resolved_by,
                     resolution_notes = :resolution_notes,
                     purchase_id = :purchase_id
                 WHERE id = :id'
            );
            $entryUpdate->execute([
                'status_code' => $resolution,
                'resolved_by' => $userId,
                'resolution_notes' => $resolutionNotes !== '' ? $resolutionNotes : null,
                'purchase_id' => $purchaseId,
                'id' => $entryId,
            ]);

            $eventInsert = $pdo->prepare(
                'INSERT INTO temp_stock_events
                  (temp_entry_id, company_id, garage_id, event_type, quantity, from_status, to_status, notes, purchase_id, created_by)
                 VALUES
                  (:temp_entry_id, :company_id, :garage_id, :event_type, :quantity, "OPEN", :to_status, :notes, :purchase_id, :created_by)'
            );
            $eventInsert->execute([
                'temp_entry_id' => $entryId,
                'company_id' => $companyId,
                'garage_id' => $activeGarageId,
                'event_type' => $resolution,
                'quantity' => $qty,
                'to_status' => $resolution,
                'notes' => $resolutionNotes !== '' ? $resolutionNotes : null,
                'purchase_id' => $purchaseId,
                'created_by' => $userId,
            ]);

            $pdo->commit();

            $auditAction = match ($resolution) {
                'PURCHASED' => 'temp_purchased',
                'CONSUMED' => 'temp_consumed',
                default => 'temp_returned',
            };
            $tempRef = (string) ($entry['temp_ref'] ?? ('TEMP-' . $entryId));
            log_audit(
                'inventory',
                $auditAction,
                $entryId,
                sprintf('Temporary stock %s resolved as %s', $tempRef, $resolution),
                [
                    'entity' => 'temp_stock_entry',
                    'source' => 'UI',
                    'before' => [
                        'temp_ref' => $tempRef,
                        'status_code' => 'OPEN',
                    ],
                    'after' => [
                        'temp_ref' => $tempRef,
                        'status_code' => $resolution,
                        'purchase_id' => $purchaseId,
                        'resolution_notes' => $resolutionNotes !== '' ? $resolutionNotes : null,
                    ],
                    'metadata' => [
                        'part_id' => $partId,
                        'part_name' => (string) ($entry['part_name'] ?? ''),
                        'part_sku' => (string) ($entry['part_sku'] ?? ''),
                        'quantity' => $qty,
                        'stock_before_purchase' => $currentQty,
                        'stock_after_purchase' => $newQty,
                    ],
                ]
            );

            if ($resolution === 'PURCHASED' && $purchaseId !== null) {
                flash_set('inventory_success', 'Temporary stock converted to purchase #' . $purchaseId . '.', 'success');
            } else {
                flash_set('inventory_success', 'Temporary stock marked as ' . $resolution . '.', 'success');
            }
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('inventory_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/inventory/index.php');
    }

    if ($action === 'link_consumed_temp_purchase') {
        if (!$canAdjust) {
            flash_set('inventory_error', 'You do not have permission to update temporary stock.', 'danger');
            redirect('modules/inventory/index.php');
        }
        if (!$tempStockReady) {
            flash_set('inventory_error', 'Temporary stock tables are missing. Run database/temporary_stock_management_upgrade.sql first.', 'danger');
            redirect('modules/inventory/index.php');
        }
        if (!$purchaseTablesReady) {
            flash_set('inventory_error', 'Purchase module tables are missing. Run database/purchase_module_upgrade.sql before linking to purchase.', 'danger');
            redirect('modules/inventory/index.php');
        }

        $entryId = post_int('temp_entry_id');
        if ($entryId <= 0) {
            flash_set('inventory_error', 'Invalid temporary stock reference.', 'danger');
            redirect('modules/inventory/index.php');
        }

        $actionToken = trim((string) ($_POST['action_token'] ?? ''));
        if (!inv_consume_action_token('link_consumed_temp_purchase_' . $entryId, $actionToken)) {
            flash_set('inventory_warning', 'Duplicate or expired consumed-to-purchase request ignored.', 'warning');
            redirect('modules/inventory/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $entryStmt = $pdo->prepare(
                'SELECT te.id, te.temp_ref, te.part_id, te.quantity, te.status_code, te.purchase_id, te.notes, te.resolution_notes,
                        p.part_name, p.part_sku, p.unit, p.purchase_price, p.gst_rate
                 FROM temp_stock_entries te
                 INNER JOIN parts p
                    ON p.id = te.part_id
                   AND p.company_id = te.company_id
                   AND p.status_code <> "DELETED"
                 WHERE te.id = :id
                   AND te.company_id = :company_id
                   AND te.garage_id = :garage_id
                 FOR UPDATE'
            );
            $entryStmt->execute([
                'id' => $entryId,
                'company_id' => $companyId,
                'garage_id' => $activeGarageId,
            ]);
            $entry = $entryStmt->fetch();
            if (!$entry) {
                throw new RuntimeException('Temporary stock entry not found for this garage.');
            }
            if ((string) ($entry['status_code'] ?? '') !== 'CONSUMED') {
                throw new RuntimeException('Only CONSUMED temporary stock entries can be shifted to purchase.');
            }
            if ((int) ($entry['purchase_id'] ?? 0) > 0) {
                throw new RuntimeException('This consumed temporary stock entry is already linked to a purchase.');
            }

            $partId = (int) ($entry['part_id'] ?? 0);
            $qty = round((float) ($entry['quantity'] ?? 0), 2);
            if ($partId <= 0 || $qty <= 0) {
                throw new RuntimeException('Invalid part/quantity in temporary stock entry.');
            }

            inv_assert_part_quantity_allowed($companyId, (array) $entry, $qty, 'Consumed temporary stock purchase link');

            $unitCost = round((float) ($entry['purchase_price'] ?? 0), 2);
            $gstRate = round((float) ($entry['gst_rate'] ?? 0), 2);
            if ($gstRate < 0) {
                $gstRate = 0.0;
            }
            if ($gstRate > 100) {
                $gstRate = 100.0;
            }

            $taxableAmount = round($qty * $unitCost, 2);
            $gstAmount = round(($taxableAmount * $gstRate) / 100, 2);
            $lineTotal = round($taxableAmount + $gstAmount, 2);
            $tempRef = (string) ($entry['temp_ref'] ?? ('TMP-' . $entryId));

            $baseNotes = trim((string) ($entry['notes'] ?? ''));
            $resolutionNotes = trim((string) ($entry['resolution_notes'] ?? ''));
            $purchaseNote = 'Linked from consumed temporary stock ' . $tempRef;
            if ($baseNotes !== '') {
                $purchaseNote .= ' | TEMP_NOTE: ' . $baseNotes;
            }
            if ($resolutionNotes !== '') {
                $purchaseNote .= ' | RESOLUTION: ' . $resolutionNotes;
            }

            $purchaseInsert = $pdo->prepare(
                'INSERT INTO purchases
                  (company_id, garage_id, vendor_id, invoice_number, purchase_date, purchase_source, assignment_status, purchase_status, payment_status, taxable_amount, gst_amount, grand_total, notes, created_by)
                 VALUES
                  (:company_id, :garage_id, NULL, NULL, :purchase_date, "TEMP_CONVERSION", "UNASSIGNED", "DRAFT", "UNPAID", :taxable_amount, :gst_amount, :grand_total, :notes, :created_by)'
            );
            $purchaseInsert->execute([
                'company_id' => $companyId,
                'garage_id' => $activeGarageId,
                'purchase_date' => date('Y-m-d'),
                'taxable_amount' => $taxableAmount,
                'gst_amount' => $gstAmount,
                'grand_total' => $lineTotal,
                'notes' => $purchaseNote,
                'created_by' => $userId,
            ]);
            $purchaseId = (int) $pdo->lastInsertId();

            $purchaseItemInsert = $pdo->prepare(
                'INSERT INTO purchase_items
                  (purchase_id, part_id, quantity, unit_cost, gst_rate, taxable_amount, gst_amount, total_amount)
                 VALUES
                  (:purchase_id, :part_id, :quantity, :unit_cost, :gst_rate, :taxable_amount, :gst_amount, :total_amount)'
            );
            $purchaseItemInsert->execute([
                'purchase_id' => $purchaseId,
                'part_id' => $partId,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'gst_rate' => $gstRate,
                'taxable_amount' => $taxableAmount,
                'gst_amount' => $gstAmount,
                'total_amount' => $lineTotal,
            ]);

            $entryUpdate = $pdo->prepare(
                'UPDATE temp_stock_entries
                 SET purchase_id = :purchase_id
                 WHERE id = :id'
            );
            $entryUpdate->execute([
                'purchase_id' => $purchaseId,
                'id' => $entryId,
            ]);

            $eventInsert = $pdo->prepare(
                'INSERT INTO temp_stock_events
                  (temp_entry_id, company_id, garage_id, event_type, quantity, from_status, to_status, notes, purchase_id, created_by)
                 VALUES
                  (:temp_entry_id, :company_id, :garage_id, "PURCHASE_LINKED", :quantity, "CONSUMED", "CONSUMED", :notes, :purchase_id, :created_by)'
            );
            $eventInsert->execute([
                'temp_entry_id' => $entryId,
                'company_id' => $companyId,
                'garage_id' => $activeGarageId,
                'quantity' => $qty,
                'notes' => 'Consumed temp stock linked to purchase #' . $purchaseId,
                'purchase_id' => $purchaseId,
                'created_by' => $userId,
            ]);

            $pdo->commit();

            log_audit(
                'inventory',
                'temp_consumed_purchase_linked',
                $entryId,
                'Consumed temporary stock ' . $tempRef . ' linked to purchase #' . $purchaseId,
                [
                    'entity' => 'temp_stock_entry',
                    'source' => 'UI',
                    'before' => [
                        'temp_ref' => $tempRef,
                        'status_code' => 'CONSUMED',
                        'purchase_id' => null,
                    ],
                    'after' => [
                        'temp_ref' => $tempRef,
                        'status_code' => 'CONSUMED',
                        'purchase_id' => $purchaseId,
                    ],
                    'metadata' => [
                        'part_id' => $partId,
                        'part_name' => (string) ($entry['part_name'] ?? ''),
                        'part_sku' => (string) ($entry['part_sku'] ?? ''),
                        'quantity' => $qty,
                        'taxable_amount' => $taxableAmount,
                        'gst_amount' => $gstAmount,
                        'grand_total' => $lineTotal,
                        'stock_movement_posted' => false,
                    ],
                ]
            );

            flash_set('inventory_success', 'Consumed temporary stock linked to purchase #' . $purchaseId . '.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('inventory_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/inventory/index.php');
    }

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
        $quantityInput = inv_decimal('quantity');
        $notes = post_string('notes', 255);
        $allowNegativeRequested = post_int('allow_negative') === 1;

        $allowedMovementTypes = ['IN', 'OUT', 'ADJUST'];

        if (!in_array($movementType, $allowedMovementTypes, true)) {
            flash_set('inventory_error', 'Invalid movement type.', 'danger');
            redirect('modules/inventory/index.php');
        }
        if ($partId <= 0) {
            flash_set('inventory_error', 'Part selection is required.', 'danger');
            redirect('modules/inventory/index.php');
        }

        if ($movementType === 'IN' && !$purchaseTablesReady) {
            flash_set('inventory_error', 'Purchase module tables are missing. Run database/purchase_module_upgrade.sql before posting stock-in entries.', 'danger');
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
            'SELECT id, part_name, part_sku, unit, purchase_price, gst_rate
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
        try {
            inv_assert_part_quantity_allowed(
                $companyId,
                (array) $part,
                $movementType === 'ADJUST' ? $quantityInput : $movementQuantity,
                'Stock movement'
            );
        } catch (Throwable $exception) {
            flash_set('inventory_error', $exception->getMessage(), 'danger');
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

            $sourceType = $movementType === 'IN' ? 'PURCHASE' : 'ADJUSTMENT';
            $referenceId = null;

            if ($movementType === 'IN') {
                $unitCost = round((float) ($part['purchase_price'] ?? 0), 2);
                $gstRate = round((float) ($part['gst_rate'] ?? 0), 2);
                if ($gstRate < 0) {
                    $gstRate = 0.0;
                }
                if ($gstRate > 100) {
                    $gstRate = 100.0;
                }

                $taxableAmount = round($movementQuantity * $unitCost, 2);
                $gstAmount = round(($taxableAmount * $gstRate) / 100, 2);
                $lineTotal = round($taxableAmount + $gstAmount, 2);

                $purchaseInsert = $pdo->prepare(
                    'INSERT INTO purchases
                      (company_id, garage_id, vendor_id, invoice_number, purchase_date, purchase_source, assignment_status, purchase_status, payment_status, taxable_amount, gst_amount, grand_total, notes, created_by)
                     VALUES
                      (:company_id, :garage_id, NULL, NULL, :purchase_date, "MANUAL_ADJUSTMENT", "UNASSIGNED", "DRAFT", "UNPAID", :taxable_amount, :gst_amount, :grand_total, :notes, :created_by)'
                );
                $purchaseInsert->execute([
                    'company_id' => $companyId,
                    'garage_id' => $activeGarageId,
                    'purchase_date' => date('Y-m-d'),
                    'taxable_amount' => $taxableAmount,
                    'gst_amount' => $gstAmount,
                    'grand_total' => $lineTotal,
                    'notes' => $notes !== '' ? $notes : 'Created from manual stock adjustment',
                    'created_by' => $userId,
                ]);
                $referenceId = (int) $pdo->lastInsertId();

                $purchaseItemInsert = $pdo->prepare(
                    'INSERT INTO purchase_items
                      (purchase_id, part_id, quantity, unit_cost, gst_rate, taxable_amount, gst_amount, total_amount)
                     VALUES
                      (:purchase_id, :part_id, :quantity, :unit_cost, :gst_rate, :taxable_amount, :gst_amount, :total_amount)'
                );
                $purchaseItemInsert->execute([
                    'purchase_id' => $referenceId,
                    'part_id' => $partId,
                    'quantity' => round($movementQuantity, 2),
                    'unit_cost' => $unitCost,
                    'gst_rate' => $gstRate,
                    'taxable_amount' => $taxableAmount,
                    'gst_amount' => $gstAmount,
                    'total_amount' => $lineTotal,
                ]);
            }

            inv_insert_movement($pdo, [
                'company_id' => $companyId,
                'garage_id' => $activeGarageId,
                'part_id' => $partId,
                'movement_type' => $movementType,
                'quantity' => round($movementQuantity, 2),
                'reference_type' => $sourceType,
                'reference_id' => $referenceId,
                'movement_uid' => 'adj-' . hash('sha256', $actionToken . '|' . $companyId . '|' . $activeGarageId . '|' . $partId . '|' . microtime(true)),
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => $userId,
            ]);

            $pdo->commit();
            $auditAction = $movementType === 'IN'
                ? 'stock_in'
                : ($movementType === 'OUT' ? 'stock_out' : 'adjustment');
            log_audit(
                'inventory',
                $auditAction,
                $partId,
                sprintf('Stock %s posted for %s (%s), delta %.2f at garage %d', $movementType, (string) $part['part_name'], (string) $part['part_sku'], $delta, $activeGarageId),
                [
                    'entity' => 'inventory_movement',
                    'source' => 'UI',
                    'before' => [
                        'garage_id' => $activeGarageId,
                        'part_id' => $partId,
                        'stock_qty' => round($currentQty, 2),
                    ],
                    'after' => [
                        'garage_id' => $activeGarageId,
                        'part_id' => $partId,
                        'stock_qty' => round($newQty, 2),
                        'movement_type' => $movementType,
                        'movement_qty' => round($movementQuantity, 2),
                        'reference_type' => $sourceType,
                        'reference_id' => $referenceId,
                    ],
                    'metadata' => [
                        'allow_negative' => $allowNegativeRequested,
                        'delta' => round($delta, 2),
                    ],
                ]
            );
            if ($movementType === 'IN' && $referenceId !== null) {
                flash_set('inventory_success', 'Stock added and logged as unassigned purchase #' . $referenceId . '.', 'success');
            } else {
                flash_set('inventory_success', 'Stock movement posted successfully.', 'success');
            }
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
            'SELECT id, part_name, part_sku, unit
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
        try {
            inv_assert_part_quantity_allowed($companyId, (array) $part, $quantity, 'Transfer');
        } catch (Throwable $exception) {
            flash_set('inventory_error', $exception->getMessage(), 'danger');
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
                ),
                [
                    'entity' => 'inventory_transfer',
                    'source' => 'UI',
                    'before' => [
                        'part_id' => $partId,
                        'from_garage_id' => $activeGarageId,
                        'to_garage_id' => $toGarageId,
                        'from_stock_qty' => round($sourceQty, 2),
                        'to_stock_qty' => round($targetQty, 2),
                    ],
                    'after' => [
                        'part_id' => $partId,
                        'from_garage_id' => $activeGarageId,
                        'to_garage_id' => $toGarageId,
                        'from_stock_qty' => round($newSourceQty, 2),
                        'to_stock_qty' => round($newTargetQty, 2),
                        'transfer_ref' => $transferRef,
                        'quantity' => round($quantity, 2),
                        'status_code' => 'POSTED',
                    ],
                    'metadata' => [
                        'allow_negative' => $allowNegativeRequested,
                    ],
                ]
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
$allowedSourceFilter = ['PURCHASE', 'JOB_CARD', 'ADJUSTMENT', 'OPENING', 'TRANSFER', 'CUSTOMER_RETURN', 'VENDOR_RETURN'];
try {
    $sourceFilterStmt = db()->prepare(
        'SELECT DISTINCT reference_type
         FROM inventory_movements
         WHERE company_id = :company_id
           AND reference_type IS NOT NULL
           AND reference_type <> ""
         ORDER BY reference_type ASC'
    );
    $sourceFilterStmt->execute(['company_id' => $companyId]);
    foreach ($sourceFilterStmt->fetchAll(PDO::FETCH_COLUMN) as $referenceType) {
        $normalizedReferenceType = strtoupper(trim((string) $referenceType));
        if ($normalizedReferenceType === '' || in_array($normalizedReferenceType, $allowedSourceFilter, true)) {
            continue;
        }
        $allowedSourceFilter[] = $normalizedReferenceType;
    }
} catch (Throwable $exception) {
    // Keep base filter options if distinct source discovery fails.
}

$sourceFilter = strtoupper(trim((string) ($_GET['source'] ?? '')));
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

$partsStockFilterPartName = '';
$partsStockFilterCategoryId = 0;
$partsStockFilterGarageId = $activeGarageId;
if (!in_array($partsStockFilterGarageId, $accessibleGarageIds, true) && !empty($accessibleGarageIds)) {
    $partsStockFilterGarageId = (int) $accessibleGarageIds[0];
}
$partsStockFilterStockLevel = '';
$partsStockFilterVendorId = 0;
$partsStockLastMovementFrom = '';
$partsStockLastMovementTo = '';
$allowedPartsStockLevels = ['ZERO', 'LOW', 'AVAILABLE'];

$partsStockCategoriesStmt = db()->prepare(
    'SELECT id, category_name
     FROM part_categories
     WHERE company_id = :company_id
       AND status_code <> "DELETED"
     ORDER BY category_name ASC'
);
$partsStockCategoriesStmt->execute(['company_id' => $companyId]);
$partsStockCategories = $partsStockCategoriesStmt->fetchAll();

$partsStockVendorsStmt = db()->prepare(
    'SELECT id, vendor_name
     FROM vendors
     WHERE company_id = :company_id
       AND status_code <> "DELETED"
     ORDER BY vendor_name ASC'
);
$partsStockVendorsStmt->execute(['company_id' => $companyId]);
$partsStockVendors = $partsStockVendorsStmt->fetchAll();

$partsStockStmt = db()->prepare(
    'SELECT p.id, p.part_name, p.part_sku, p.hsn_code, p.unit, p.selling_price, p.gst_rate, p.min_stock, p.status_code,
            pc.category_name,
            v.vendor_name,
            COALESCE(gi.quantity, 0) AS stock_qty,
            lm.last_movement_at,
            CASE
                WHEN COALESCE(gi.quantity, 0) <= 0 THEN "OUT"
                WHEN COALESCE(gi.quantity, 0) <= p.min_stock THEN "LOW"
                ELSE "OK"
            END AS stock_state
     FROM parts p
     LEFT JOIN part_categories pc ON pc.id = p.category_id
     LEFT JOIN vendors v ON v.id = p.vendor_id
     LEFT JOIN garage_inventory gi ON gi.part_id = p.id AND gi.garage_id = :inventory_garage_id
     LEFT JOIN (
        SELECT part_id, garage_id, MAX(created_at) AS last_movement_at
        FROM inventory_movements
        WHERE company_id = :movement_company_id
        GROUP BY part_id, garage_id
     ) lm ON lm.part_id = p.id AND lm.garage_id = :movement_garage_id
     WHERE p.company_id = :company_id
       AND p.status_code <> "DELETED"
     ORDER BY p.part_name ASC'
);
$partsStockStmt->execute([
    'inventory_garage_id' => $activeGarageId,
    'movement_company_id' => $companyId,
    'movement_garage_id' => $activeGarageId,
    'company_id' => $companyId,
]);
$parts = $partsStockStmt->fetchAll();
$partsTrackedCount = count($parts);
$partsOutCount = count(array_filter($parts, static fn (array $part): bool => (string) ($part['stock_state'] ?? 'OK') === 'OUT'));
$partsLowCount = count(array_filter($parts, static fn (array $part): bool => (string) ($part['stock_state'] ?? 'OK') === 'LOW'));
$partsTablePreview = array_slice($parts, 0, 10);

$tempStatusFilter = strtoupper(trim((string) ($_GET['temp_status'] ?? '')));
$allowedTempStatusFilter = ['OPEN', 'RETURNED', 'PURCHASED', 'CONSUMED'];
if (!in_array($tempStatusFilter, $allowedTempStatusFilter, true)) {
    $tempStatusFilter = '';
}

$tempSummary = [
    'OPEN' => ['count' => 0, 'qty' => 0.0],
    'RETURNED' => ['count' => 0, 'qty' => 0.0],
    'PURCHASED' => ['count' => 0, 'qty' => 0.0],
    'CONSUMED' => ['count' => 0, 'qty' => 0.0],
];
$tempEntries = [];
$tempEvents = [];

if ($tempStockReady) {
    $tempSummaryStmt = db()->prepare(
        'SELECT status_code, COUNT(*) AS item_count, COALESCE(SUM(quantity), 0) AS total_qty
         FROM temp_stock_entries
         WHERE company_id = :company_id
           AND garage_id = :garage_id
         GROUP BY status_code'
    );
    $tempSummaryStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $activeGarageId,
    ]);
    foreach ($tempSummaryStmt->fetchAll() as $row) {
        $status = (string) ($row['status_code'] ?? '');
        if (!isset($tempSummary[$status])) {
            continue;
        }
        $tempSummary[$status]['count'] = (int) ($row['item_count'] ?? 0);
        $tempSummary[$status]['qty'] = (float) ($row['total_qty'] ?? 0);
    }

    $tempWhere = ['te.company_id = :company_id', 'te.garage_id = :garage_id'];
    $tempParams = [
        'company_id' => $companyId,
        'garage_id' => $activeGarageId,
    ];
    if ($tempStatusFilter !== '') {
        $tempWhere[] = 'te.status_code = :temp_status';
        $tempParams['temp_status'] = $tempStatusFilter;
    }

    $purchaseSelectSql = $purchaseTablesReady ? ', pur.purchase_status, pur.invoice_number' : ', NULL AS purchase_status, NULL AS invoice_number';
    $purchaseJoinSql = $purchaseTablesReady ? 'LEFT JOIN purchases pur ON pur.id = te.purchase_id' : '';
    $tempListSql =
        'SELECT te.*, p.part_name, p.part_sku, p.unit,
                cu.name AS created_by_name,
                ru.name AS resolved_by_name
                ' . $purchaseSelectSql . '
         FROM temp_stock_entries te
         INNER JOIN parts p ON p.id = te.part_id
         LEFT JOIN users cu ON cu.id = te.created_by
         LEFT JOIN users ru ON ru.id = te.resolved_by
         ' . $purchaseJoinSql . '
         WHERE ' . implode(' AND ', $tempWhere) . '
         ORDER BY CASE WHEN te.status_code = "OPEN" THEN 0 ELSE 1 END, te.id DESC
         LIMIT 160';
    $tempListStmt = db()->prepare($tempListSql);
    $tempListStmt->execute($tempParams);
    $tempEntries = $tempListStmt->fetchAll();

    $tempEventStmt = db()->prepare(
        'SELECT tse.*, te.temp_ref, p.part_name, p.part_sku, p.unit, u.name AS created_by_name
         FROM temp_stock_events tse
         INNER JOIN temp_stock_entries te ON te.id = tse.temp_entry_id
         INNER JOIN parts p ON p.id = te.part_id
         LEFT JOIN users u ON u.id = tse.created_by
         WHERE tse.company_id = :company_id
           AND tse.garage_id = :garage_id
         ORDER BY tse.id DESC
         LIMIT 120'
    );
    $tempEventStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $activeGarageId,
    ]);
    $tempEvents = $tempEventStmt->fetchAll();
}

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
$tempInActionToken = $tempStockReady ? inv_issue_action_token('temp_stock_in') : '';
$tempResolveTokens = [];
$tempConsumedPurchaseLinkTokens = [];
if ($tempStockReady) {
    foreach ($tempEntries as $tempEntry) {
        $entryId = (int) ($tempEntry['id'] ?? 0);
        if ($entryId <= 0) {
            continue;
        }
        $statusCode = (string) ($tempEntry['status_code'] ?? '');
        $purchaseId = (int) ($tempEntry['purchase_id'] ?? 0);
        if ($statusCode === 'OPEN') {
            $tempResolveTokens[$entryId] = inv_issue_action_token('resolve_temp_stock_' . $entryId);
            continue;
        }
        if ($statusCode === 'CONSUMED' && $purchaseId <= 0 && $purchaseTablesReady) {
            $tempConsumedPurchaseLinkTokens[$entryId] = inv_issue_action_token('link_consumed_temp_purchase_' . $entryId);
        }
    }
}

$partsStockApiUrl = url('modules/inventory/parts_stock_api.php');

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
    <div class="container-fluid" data-master-insights-root="inventory-parts-stock" data-master-insights-endpoint="<?= e($partsStockApiUrl); ?>">
      <div class="card mb-3">
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-3">
              <div class="small-box text-bg-primary">
                <div class="inner">
                  <h4 data-stat-value="tracked_parts"><?= e((string) $partsTrackedCount); ?></h4>
                  <p>Tracked Parts (Active Garage)</p>
                </div>
                <span class="small-box-icon"><i class="bi bi-box-seam"></i></span>
              </div>
            </div>
            <div class="col-md-3">
              <div class="small-box text-bg-danger">
                <div class="inner">
                  <h4 data-stat-value="out_of_stock_parts"><?= e((string) $partsOutCount); ?></h4>
                  <p>Out of Stock</p>
                </div>
                <span class="small-box-icon"><i class="bi bi-exclamation-octagon"></i></span>
              </div>
            </div>
            <div class="col-md-3">
              <div class="small-box text-bg-warning">
                <div class="inner">
                  <h4 data-stat-value="low_stock_parts"><?= e((string) $partsLowCount); ?></h4>
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
                <div class="card-header"><h3 class="card-title">Manual Stock Adjustment (Shortcut)</h3></div>
                <form method="post">
                  <div class="card-body row g-2">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="stock_adjust">
                    <input type="hidden" name="action_token" value="<?= e($adjustActionToken); ?>">

                    <div class="col-md-12">
                      <label class="form-label">Part</label>
                      <select id="stock-adjust-part-select" name="part_id" class="form-select" required>
                        <option value="">Select Part</option>
                        <?php foreach ($parts as $part): ?>
                          <?php
                            $partUnitCode = part_unit_normalize_code((string) ($part['unit'] ?? ''));
                            if ($partUnitCode === '') {
                                $partUnitCode = 'PCS';
                            }
                            $partAllowsDecimal = part_unit_allows_decimal($companyId, $partUnitCode);
                          ?>
                          <option value="<?= (int) $part['id']; ?>" data-unit="<?= e($partUnitCode); ?>" data-allow-decimal="<?= $partAllowsDecimal ? '1' : '0'; ?>">
                            <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>) | Stock <?= e(number_format((float) $part['stock_qty'], 2)); ?> <?= e((string) $part['unit']); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Movement</label>
                      <select name="movement_type" class="form-select" required>
                        <option value="IN">Stock In (+) -> Unassigned Purchase</option>
                        <option value="OUT">Stock Out (-)</option>
                        <option value="ADJUST">Adjustment (+/-)</option>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Quantity</label>
                      <input id="stock-adjust-qty-input" type="number" step="0.01" name="quantity" class="form-control" required>
                    </div>
                    <div class="col-md-12">
                      <small class="text-muted">Stock In entries are auto-logged as unassigned purchases for later vendor and invoice assignment in Purchase Module.</small>
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
                      <select id="stock-transfer-part-select" name="part_id" class="form-select" required>
                        <option value="">Select Part</option>
                        <?php foreach ($parts as $part): ?>
                          <?php
                            $partUnitCode = part_unit_normalize_code((string) ($part['unit'] ?? ''));
                            if ($partUnitCode === '') {
                                $partUnitCode = 'PCS';
                            }
                            $partAllowsDecimal = part_unit_allows_decimal($companyId, $partUnitCode);
                          ?>
                          <option value="<?= (int) $part['id']; ?>" data-unit="<?= e($partUnitCode); ?>" data-allow-decimal="<?= $partAllowsDecimal ? '1' : '0'; ?>">
                            <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>) | Source stock <?= e(number_format((float) $part['stock_qty'], 2)); ?> <?= e($partUnitCode); ?>
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
                      <input id="stock-transfer-qty-input" type="number" step="0.01" min="0.01" name="quantity" class="form-control" required>
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

      <?php if (!$tempStockReady): ?>
        <div class="alert alert-warning mb-3">
          Temporary stock management tables are missing. Run <code>database/temporary_stock_management_upgrade.sql</code> and refresh.
        </div>
      <?php else: ?>
        <div class="row g-3 mb-3">
          <div class="col-md-3">
            <div class="small-box text-bg-primary">
              <div class="inner">
                <h4><?= e((string) ((int) ($tempSummary['OPEN']['count'] ?? 0))); ?></h4>
                <p>TEMP OPEN (Qty <?= e(number_format((float) ($tempSummary['OPEN']['qty'] ?? 0), 2)); ?>)</p>
              </div>
              <span class="small-box-icon"><i class="bi bi-hourglass-split"></i></span>
            </div>
          </div>
          <div class="col-md-3">
            <div class="small-box text-bg-secondary">
              <div class="inner">
                <h4><?= e((string) ((int) ($tempSummary['RETURNED']['count'] ?? 0))); ?></h4>
                <p>Returned</p>
              </div>
              <span class="small-box-icon"><i class="bi bi-arrow-return-left"></i></span>
            </div>
          </div>
          <div class="col-md-3">
            <div class="small-box text-bg-success">
              <div class="inner">
                <h4><?= e((string) ((int) ($tempSummary['PURCHASED']['count'] ?? 0))); ?></h4>
                <p>Converted to Purchase</p>
              </div>
              <span class="small-box-icon"><i class="bi bi-bag-check"></i></span>
            </div>
          </div>
          <div class="col-md-3">
            <div class="small-box text-bg-danger">
              <div class="inner">
                <h4><?= e((string) ((int) ($tempSummary['CONSUMED']['count'] ?? 0))); ?></h4>
                <p>Consumed</p>
              </div>
              <span class="small-box-icon"><i class="bi bi-exclamation-triangle"></i></span>
            </div>
          </div>
        </div>

        <?php if ($canAdjust): ?>
          <div class="card mb-3 card-outline card-primary">
            <div class="card-header"><h3 class="card-title">Temporary Stock In (Fitment / Compatibility)</h3></div>
            <form method="post">
              <div class="card-body row g-2">
                <?= csrf_field(); ?>
                <input type="hidden" name="_action" value="temp_stock_in">
                <input type="hidden" name="action_token" value="<?= e($tempInActionToken); ?>">
                <div class="col-md-6">
                  <label class="form-label">Part</label>
                  <select id="temp-stock-part-select" name="part_id" class="form-select" required>
                    <option value="">Select Part</option>
                    <?php foreach ($parts as $part): ?>
                      <?php
                        $partUnitCode = part_unit_normalize_code((string) ($part['unit'] ?? ''));
                        if ($partUnitCode === '') {
                            $partUnitCode = 'PCS';
                        }
                        $partAllowsDecimal = part_unit_allows_decimal($companyId, $partUnitCode);
                      ?>
                      <option value="<?= (int) $part['id']; ?>" data-unit="<?= e($partUnitCode); ?>" data-allow-decimal="<?= $partAllowsDecimal ? '1' : '0'; ?>">
                        <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>) | <?= e($partUnitCode); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-2">
                  <label class="form-label">Quantity</label>
                  <input id="temp-stock-qty-input" type="number" step="0.01" min="0.01" name="quantity" class="form-control" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Notes</label>
                  <input type="text" name="notes" class="form-control" maxlength="255" placeholder="Vendor trial / fitment note">
                </div>
                <div class="col-12">
                  <small class="text-muted">TEMP_IN is held outside valuation and purchase reports until explicitly resolved as PURCHASED.</small>
                </div>
              </div>
              <div class="card-footer">
                <button type="submit" class="btn btn-primary">Post TEMP_IN</button>
              </div>
            </form>
          </div>
        <?php endif; ?>

        <div class="card mb-3">
          <div class="card-header">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
              <h3 class="card-title mb-0">Temporary Stock Register</h3>
              <form method="get" class="d-flex align-items-center gap-2">
                <input type="hidden" name="history_garage_id" value="<?= (int) $historyGarageId; ?>">
                <input type="hidden" name="history_part_id" value="<?= (int) $historyPartId; ?>">
                <input type="hidden" name="source" value="<?= e($sourceFilter); ?>">
                <input type="hidden" name="from" value="<?= e($dateFrom); ?>">
                <input type="hidden" name="to" value="<?= e($dateTo); ?>">
                <input type="hidden" name="vis_variant_id" value="<?= (int) $visVariantId; ?>">
                <label class="form-label mb-0">Status</label>
                <select name="temp_status" class="form-select form-select-sm">
                  <option value="">All</option>
                  <?php foreach ($allowedTempStatusFilter as $status): ?>
                    <option value="<?= e($status); ?>" <?= $tempStatusFilter === $status ? 'selected' : ''; ?>><?= e($status); ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-outline-primary">Apply</button>
              </form>
            </div>
          </div>
          <div class="card-body table-responsive p-0">
            <table class="table table-sm table-striped mb-0">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Ref</th>
                  <th>Part</th>
                  <th>Qty</th>
                  <th>Status</th>
                  <th>Created By</th>
                  <th>Resolved</th>
                  <th>Purchase Ref</th>
                  <th>Notes</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($tempEntries)): ?>
                  <tr><td colspan="10" class="text-center text-muted py-4">No temporary stock entries for selected filter.</td></tr>
                <?php else: ?>
                  <?php foreach ($tempEntries as $entry): ?>
                    <?php
                      $entryId = (int) ($entry['id'] ?? 0);
                      $status = (string) ($entry['status_code'] ?? '');
                      $isOpen = $status === 'OPEN';
                      $resolvedBy = (string) ($entry['resolved_by_name'] ?? '-');
                      $resolvedAt = (string) ($entry['resolved_at'] ?? '');
                      $resolvedLabel = $isOpen ? '-' : (($resolvedAt !== '' ? $resolvedAt : '-') . ' / ' . $resolvedBy);
                      $purchaseRef = (int) ($entry['purchase_id'] ?? 0);
                      $notes = trim((string) ($entry['notes'] ?? ''));
                      $resolutionNotes = trim((string) ($entry['resolution_notes'] ?? ''));
                      if ($resolutionNotes !== '') {
                          $notes = $notes !== '' ? ($notes . ' | ' . $resolutionNotes) : $resolutionNotes;
                      }
                    ?>
                    <tr>
                      <td><?= e((string) ($entry['created_at'] ?? '')); ?></td>
                      <td><code><?= e((string) ($entry['temp_ref'] ?? ('TMP-' . $entryId))); ?></code></td>
                      <td><?= e((string) ($entry['part_name'] ?? '')); ?> (<?= e((string) ($entry['part_sku'] ?? '')); ?>)</td>
                      <td><?= e(number_format((float) ($entry['quantity'] ?? 0), 2)); ?> <?= e((string) ($entry['unit'] ?? '')); ?></td>
                      <td><span class="badge text-bg-<?= e(inv_temp_status_badge_class($status)); ?>"><?= e($status); ?></span></td>
                      <td><?= e((string) ($entry['created_by_name'] ?? '-')); ?></td>
                      <td><?= e($resolvedLabel); ?></td>
                      <td>
                        <?php if ($purchaseRef > 0): ?>
                          <code>#<?= e((string) $purchaseRef); ?></code>
                          <?php if (trim((string) ($entry['purchase_status'] ?? '')) !== ''): ?>
                            <span class="badge text-bg-secondary"><?= e((string) $entry['purchase_status']); ?></span>
                          <?php endif; ?>
                        <?php else: ?>
                          -
                        <?php endif; ?>
                      </td>
                      <td><?= e($notes !== '' ? $notes : '-'); ?></td>
                      <td class="text-nowrap">
                        <?php if ($isOpen && $canAdjust): ?>
                          <?php $resolveToken = (string) ($tempResolveTokens[$entryId] ?? ''); ?>
                          <form method="post" class="d-inline" data-confirm="Mark this temporary stock as RETURNED?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="resolve_temp_stock">
                            <input type="hidden" name="temp_entry_id" value="<?= (int) $entryId; ?>">
                            <input type="hidden" name="resolution" value="RETURNED">
                            <input type="hidden" name="action_token" value="<?= e($resolveToken); ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Returned</button>
                          </form>
                          <?php if ($purchaseTablesReady): ?>
                            <form method="post" class="d-inline" data-confirm="Convert this temporary stock to PURCHASED inventory?">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="resolve_temp_stock">
                              <input type="hidden" name="temp_entry_id" value="<?= (int) $entryId; ?>">
                              <input type="hidden" name="resolution" value="PURCHASED">
                              <input type="hidden" name="action_token" value="<?= e($resolveToken); ?>">
                              <button type="submit" class="btn btn-sm btn-outline-success">Purchased</button>
                            </form>
                          <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline-success" disabled>Purchased</button>
                          <?php endif; ?>
                          <form method="post" class="d-inline-flex align-items-center gap-1">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="resolve_temp_stock">
                            <input type="hidden" name="temp_entry_id" value="<?= (int) $entryId; ?>">
                            <input type="hidden" name="resolution" value="CONSUMED">
                            <input type="hidden" name="action_token" value="<?= e($resolveToken); ?>">
                            <input class="form-check-input mt-0" type="checkbox" name="confirm_consumed" value="1" id="confirm_consumed_<?= (int) $entryId; ?>">
                            <label class="form-check-label small" for="confirm_consumed_<?= (int) $entryId; ?>">Confirm</label>
                            <button type="submit" class="btn btn-sm btn-outline-danger">Consumed</button>
                          </form>
                        <?php elseif ($status === 'CONSUMED' && $purchaseRef <= 0 && $canAdjust): ?>
                          <?php if ($purchaseTablesReady): ?>
                            <?php $consumedPurchaseLinkToken = (string) ($tempConsumedPurchaseLinkTokens[$entryId] ?? ''); ?>
                            <form method="post" class="d-inline" data-confirm="Create an unassigned purchase for this consumed temporary stock? Stock quantity will not be increased.">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="link_consumed_temp_purchase">
                              <input type="hidden" name="temp_entry_id" value="<?= (int) $entryId; ?>">
                              <input type="hidden" name="action_token" value="<?= e($consumedPurchaseLinkToken); ?>">
                              <button type="submit" class="btn btn-sm btn-outline-success">Shift to Purchase</button>
                            </form>
                          <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline-success" disabled>Shift to Purchase</button>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="text-muted">Resolved</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header"><h3 class="card-title">Temporary Stock Event Trail</h3></div>
          <div class="card-body table-responsive p-0">
            <table class="table table-sm table-striped mb-0">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Ref</th>
                  <th>Event</th>
                  <th>Part</th>
                  <th>Qty</th>
                  <th>From</th>
                  <th>To</th>
                  <th>Purchase</th>
                  <th>User</th>
                  <th>Notes</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($tempEvents)): ?>
                  <tr><td colspan="10" class="text-center text-muted py-4">No temporary stock events.</td></tr>
                <?php else: ?>
                  <?php foreach ($tempEvents as $event): ?>
                    <?php
                      $eventType = (string) ($event['event_type'] ?? '');
                      $eventBadge = match ($eventType) {
                          'TEMP_IN' => 'primary',
                          'PURCHASED' => 'success',
                          'PURCHASE_LINKED' => 'success',
                          'RETURNED' => 'secondary',
                          'CONSUMED' => 'danger',
                          default => 'secondary',
                      };
                    ?>
                    <tr>
                      <td><?= e((string) ($event['created_at'] ?? '')); ?></td>
                      <td><code><?= e((string) ($event['temp_ref'] ?? '-')); ?></code></td>
                      <td><span class="badge text-bg-<?= e($eventBadge); ?>"><?= e($eventType); ?></span></td>
                      <td><?= e((string) ($event['part_name'] ?? '')); ?> (<?= e((string) ($event['part_sku'] ?? '')); ?>)</td>
                      <td><?= e(number_format((float) ($event['quantity'] ?? 0), 2)); ?> <?= e((string) ($event['unit'] ?? '')); ?></td>
                      <td><?= e((string) (($event['from_status'] ?? '') !== '' ? $event['from_status'] : '-')); ?></td>
                      <td><?= e((string) (($event['to_status'] ?? '') !== '' ? $event['to_status'] : '-')); ?></td>
                      <td><?= (int) ($event['purchase_id'] ?? 0) > 0 ? ('#' . e((string) ((int) $event['purchase_id']))) : '-'; ?></td>
                      <td><?= e((string) ($event['created_by_name'] ?? '-')); ?></td>
                      <td><?= e((string) (($event['notes'] ?? '') !== '' ? $event['notes'] : '-')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
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
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Parts Stock</h3>
          <span class="badge text-bg-light border" data-master-results-count="1"><?= e((string) count($parts)); ?></span>
        </div>
        <div class="card-body border-bottom">
          <form method="get" class="row g-2 align-items-end" data-master-filter-form="1">
            <div class="col-lg-3 col-md-6">
              <label class="form-label form-label-sm mb-1">Part Name</label>
              <input
                type="text"
                name="part_name"
                value="<?= e($partsStockFilterPartName); ?>"
                class="form-control form-control-sm"
                placeholder="Part name or SKU/Part No"
              >
            </div>
            <div class="col-lg-2 col-md-6">
              <label class="form-label form-label-sm mb-1">Category</label>
              <select name="category_id" class="form-select form-select-sm">
                <option value="0">All Categories</option>
                <?php foreach ($partsStockCategories as $category): ?>
                  <option value="<?= (int) $category['id']; ?>" <?= ((int) $partsStockFilterCategoryId === (int) $category['id']) ? 'selected' : ''; ?>>
                    <?= e((string) $category['category_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-lg-2 col-md-6">
              <label class="form-label form-label-sm mb-1">Garage</label>
              <select name="garage_id" class="form-select form-select-sm">
                <?php foreach ($garageOptions as $garage): ?>
                  <?php $garageCode = trim((string) ($garage['code'] ?? '')); ?>
                  <option value="<?= (int) $garage['id']; ?>" <?= ((int) $partsStockFilterGarageId === (int) $garage['id']) ? 'selected' : ''; ?>>
                    <?= e((string) $garage['name']); ?><?= $garageCode !== '' ? ' (' . e($garageCode) . ')' : ''; ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-lg-2 col-md-6">
              <label class="form-label form-label-sm mb-1">Stock Level</label>
              <select name="stock_level" class="form-select form-select-sm">
                <option value="">All Levels</option>
                <?php foreach ($allowedPartsStockLevels as $stockLevel): ?>
                  <?php
                    $stockLabel = $stockLevel === 'ZERO'
                        ? 'Zero Stock'
                        : ($stockLevel === 'LOW' ? 'Low Stock' : 'Available Stock');
                  ?>
                  <option value="<?= e($stockLevel); ?>" <?= $partsStockFilterStockLevel === $stockLevel ? 'selected' : ''; ?>>
                    <?= e($stockLabel); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-lg-3 col-md-6">
              <label class="form-label form-label-sm mb-1">Vendor</label>
              <select name="vendor_id" class="form-select form-select-sm">
                <option value="0">All Vendors</option>
                <?php foreach ($partsStockVendors as $vendor): ?>
                  <option value="<?= (int) $vendor['id']; ?>" <?= ((int) $partsStockFilterVendorId === (int) $vendor['id']) ? 'selected' : ''; ?>>
                    <?= e((string) $vendor['vendor_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-lg-2 col-md-6">
              <label class="form-label form-label-sm mb-1">Last Movement From</label>
              <input type="date" name="last_movement_from" value="<?= e($partsStockLastMovementFrom); ?>" class="form-control form-control-sm">
            </div>
            <div class="col-lg-2 col-md-6">
              <label class="form-label form-label-sm mb-1">Last Movement To</label>
              <input type="date" name="last_movement_to" value="<?= e($partsStockLastMovementTo); ?>" class="form-control form-control-sm">
            </div>
            <div class="col-lg-2 col-md-6 d-flex gap-2">
              <button type="submit" class="btn btn-sm btn-outline-primary">Apply</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" data-master-filter-reset="1">Reset</button>
            </div>
          </form>
          <div class="alert alert-danger d-none mt-3 mb-0" data-master-insights-error="1"></div>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>SKU/Part No</th>
                <th>Part Name</th>
                <th>Category</th>
                <th>Vendor</th>
                <th>Garage</th>
                <th>HSN</th>
                <th>Sell Price</th>
                <th>GST%</th>
                <th>Min Stock</th>
                <th>Current Stock</th>
                <th>Alert</th>
                <th>Last Movement</th>
              </tr>
            </thead>
            <tbody data-master-table-body="1" data-table-colspan="12">
              <?php if (empty($partsTablePreview)): ?>
                <tr><td colspan="12" class="text-center text-muted py-4">No parts configured.</td></tr>
              <?php else: ?>
                <?php foreach ($partsTablePreview as $part): ?>
                  <?php
                    $state = (string) ($part['stock_state'] ?? 'OK');
                    $badgeClass = $state === 'OUT' ? 'danger' : ($state === 'LOW' ? 'warning' : 'success');
                    $label = $state === 'OUT' ? 'Out of Stock' : ($state === 'LOW' ? 'Low Stock' : 'OK');
                  ?>
                  <tr>
                    <td><code><?= e((string) $part['part_sku']); ?></code></td>
                    <td><?= e((string) $part['part_name']); ?></td>
                    <td><?= e((string) ($part['category_name'] ?? '-')); ?></td>
                    <td><?= e((string) ($part['vendor_name'] ?? '-')); ?></td>
                    <td><?= e($activeGarageName); ?></td>
                    <td><?= e((string) ($part['hsn_code'] ?? '-')); ?></td>
                    <td><?= e(format_currency((float) $part['selling_price'])); ?></td>
                    <td><?= e(number_format((float) $part['gst_rate'], 2)); ?></td>
                    <td><?= e(number_format((float) $part['min_stock'], 2)); ?> <?= e((string) $part['unit']); ?></td>
                    <td><?= e(number_format((float) $part['stock_qty'], 2)); ?> <?= e((string) $part['unit']); ?></td>
                    <td><span class="badge text-bg-<?= e($badgeClass); ?>"><?= e($label); ?></span></td>
                    <td><?= e(inv_format_datetime((string) ($part['last_movement_at'] ?? ''))); ?></td>
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

<script>
  (function () {
    function readSelectedOption(select) {
      if (!select || !select.options || select.selectedIndex < 0) {
        return null;
      }
      return select.options[select.selectedIndex] || null;
    }

    function applyPartQuantityRule(select, qtyInput, config) {
      if (!select || !qtyInput) {
        return;
      }

      var selected = readSelectedOption(select);
      var hasSelection = !!(selected && selected.value);
      var allowDecimal = hasSelection ? (selected.getAttribute('data-allow-decimal') || '1') === '1' : true;

      qtyInput.step = allowDecimal ? '0.01' : '1';
      if (config && config.manageMin === true) {
        qtyInput.min = allowDecimal ? '0.01' : '1';
      }

      if (!allowDecimal && qtyInput.value !== '') {
        var parsed = Number(qtyInput.value);
        if (isFinite(parsed)) {
          qtyInput.value = String((config && config.allowNegative === true)
            ? Math.round(parsed)
            : Math.max(0, Math.round(parsed)));
        }
      }
    }

    function wirePartQtyRule(selectId, qtyInputId, config) {
      var select = document.getElementById(selectId);
      var qtyInput = document.getElementById(qtyInputId);
      if (!select || !qtyInput) {
        return;
      }

      var refresh = function () {
        applyPartQuantityRule(select, qtyInput, config || {});
      };
      select.addEventListener('change', refresh);
      qtyInput.addEventListener('change', refresh);
      refresh();
    }

    wirePartQtyRule('stock-adjust-part-select', 'stock-adjust-qty-input', { manageMin: false, allowNegative: true });
    wirePartQtyRule('stock-transfer-part-select', 'stock-transfer-qty-input', { manageMin: true, allowNegative: false });
    wirePartQtyRule('temp-stock-part-select', 'temp-stock-qty-input', { manageMin: true, allowNegative: false });
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

