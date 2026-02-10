<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('purchase.view');

$page_title = 'Purchase Module';
$active_menu = 'purchases.index';

$companyId = active_company_id();
$activeGarageId = active_garage_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$canManage = has_permission('purchase.manage');
$canFinalize = has_permission('purchase.finalize') || $canManage;
$canExport = has_permission('export.data') || $canManage;

function pur_decimal(mixed $raw, float $default = 0.0): float
{
    if (is_array($raw)) {
        return $default;
    }

    $value = trim((string) $raw);
    if ($value === '') {
        return $default;
    }

    $normalized = str_replace([',', ' '], '', $value);
    if (!is_numeric($normalized)) {
        return $default;
    }

    return (float) $normalized;
}

function pur_is_valid_date(?string $value): bool
{
    if ($value === null || $value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year);
}

function pur_issue_action_token(string $action): string
{
    if (!isset($_SESSION['_purchase_action_tokens']) || !is_array($_SESSION['_purchase_action_tokens'])) {
        $_SESSION['_purchase_action_tokens'] = [];
    }

    $token = bin2hex(random_bytes(16));
    $_SESSION['_purchase_action_tokens'][$action] = $token;

    return $token;
}

function pur_consume_action_token(string $action, string $token): bool
{
    $tokens = $_SESSION['_purchase_action_tokens'] ?? [];
    if (!is_array($tokens) || !isset($tokens[$action])) {
        return false;
    }

    $valid = hash_equals((string) $tokens[$action], $token);
    if ($valid) {
        unset($_SESSION['_purchase_action_tokens'][$action]);
    }

    return $valid;
}

function pur_lock_inventory_row(PDO $pdo, int $garageId, int $partId): float
{
    $seedStmt = $pdo->prepare(
        'INSERT INTO garage_inventory (garage_id, part_id, quantity)
         VALUES (:garage_id, :part_id, 0)
         ON DUPLICATE KEY UPDATE quantity = quantity'
    );
    $seedStmt->execute([
        'garage_id' => $garageId,
        'part_id' => $partId,
    ]);

    $lockStmt = $pdo->prepare(
        'SELECT quantity
         FROM garage_inventory
         WHERE garage_id = :garage_id
           AND part_id = :part_id
         FOR UPDATE'
    );
    $lockStmt->execute([
        'garage_id' => $garageId,
        'part_id' => $partId,
    ]);

    return (float) $lockStmt->fetchColumn();
}

function pur_insert_movement(PDO $pdo, array $payload): void
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
        'quantity' => round((float) $payload['quantity'], 2),
        'reference_type' => (string) $payload['reference_type'],
        'reference_id' => $payload['reference_id'] ?? null,
        'movement_uid' => (string) $payload['movement_uid'],
        'notes' => $payload['notes'] ?? null,
        'created_by' => (int) $payload['created_by'],
    ]);
}

function pur_payment_badge_class(string $status): string
{
    return match ($status) {
        'PAID' => 'success',
        'PARTIAL' => 'warning',
        default => 'secondary',
    };
}

function pur_purchase_badge_class(string $status): string
{
    return match ($status) {
        'FINALIZED' => 'success',
        default => 'secondary',
    };
}

function pur_assignment_badge_class(string $status): string
{
    return match ($status) {
        'ASSIGNED' => 'success',
        default => 'warning',
    };
}

function pur_csv_download(string $filename, array $headers, array $rows, array $filterSummary): never
{
    log_data_export('purchases', 'CSV', count($rows), [
        'company_id' => active_company_id(),
        'garage_id' => active_garage_id() > 0 ? active_garage_id() : null,
        'filter_summary' => json_encode($filterSummary, JSON_UNESCAPED_UNICODE),
        'scope' => $filterSummary,
        'requested_by' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
    ]);

    log_audit('exports', 'download', null, 'Exported purchase CSV: ' . $filename, [
        'entity' => 'data_export',
        'source' => 'UI',
        'metadata' => [
            'module' => 'purchases',
            'format' => 'CSV',
            'row_count' => count($rows),
            'filters' => $filterSummary,
        ],
    ]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $stream = fopen('php://output', 'w');
    if ($stream === false) {
        http_response_code(500);
        exit('Unable to generate CSV export.');
    }

    fputcsv($stream, $headers);
    foreach ($rows as $row) {
        $flat = [];
        foreach ($row as $value) {
            $flat[] = is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        fputcsv($stream, $flat);
    }
    fclose($stream);
    exit;
}

$purchasesReady = table_columns('purchases') !== [] && table_columns('purchase_items') !== [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$purchasesReady) {
        flash_set('purchase_error', 'Purchase module tables are missing. Run database/purchase_module_upgrade.sql first.', 'danger');
        redirect('modules/purchases/index.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create_purchase') {
        if (!$canManage) {
            flash_set('purchase_error', 'You do not have permission to create purchases.', 'danger');
            redirect('modules/purchases/index.php');
        }

        $actionToken = trim((string) ($_POST['action_token'] ?? ''));
        if (!pur_consume_action_token('create_purchase', $actionToken)) {
            flash_set('purchase_warning', 'Duplicate or expired purchase request ignored.', 'warning');
            redirect('modules/purchases/index.php');
        }

        $vendorId = post_int('vendor_id');
        $invoiceNumber = post_string('invoice_number', 80);
        $purchaseDate = trim((string) ($_POST['purchase_date'] ?? date('Y-m-d')));
        $paymentStatus = strtoupper(trim((string) ($_POST['payment_status'] ?? 'UNPAID')));
        $notes = post_string('notes', 255);
        $targetStatus = strtoupper(trim((string) ($_POST['target_status'] ?? 'DRAFT')));

        $allowedPaymentStatuses = ['UNPAID', 'PARTIAL', 'PAID'];
        $allowedTargetStatuses = ['DRAFT', 'FINALIZED'];

        if (!in_array($paymentStatus, $allowedPaymentStatuses, true)) {
            flash_set('purchase_error', 'Invalid payment status.', 'danger');
            redirect('modules/purchases/index.php');
        }
        if (!in_array($targetStatus, $allowedTargetStatuses, true)) {
            flash_set('purchase_error', 'Invalid purchase status target.', 'danger');
            redirect('modules/purchases/index.php');
        }
        if ($vendorId <= 0) {
            flash_set('purchase_error', 'Vendor is required for purchase entry.', 'danger');
            redirect('modules/purchases/index.php');
        }
        if (!pur_is_valid_date($purchaseDate)) {
            flash_set('purchase_error', 'Invalid purchase date.', 'danger');
            redirect('modules/purchases/index.php');
        }
        if ($invoiceNumber === '') {
            flash_set('purchase_error', 'Invoice number is required for purchase entry.', 'danger');
            redirect('modules/purchases/index.php');
        }

        $vendorStmt = db()->prepare(
            'SELECT id, vendor_name
             FROM vendors
             WHERE id = :id
               AND company_id = :company_id
               AND (status_code IS NULL OR status_code = "ACTIVE")
             LIMIT 1'
        );
        $vendorStmt->execute([
            'id' => $vendorId,
            'company_id' => $companyId,
        ]);
        $vendor = $vendorStmt->fetch();
        if (!$vendor) {
            flash_set('purchase_error', 'Selected vendor is invalid for this company.', 'danger');
            redirect('modules/purchases/index.php');
        }

        $partInputs = $_POST['item_part_id'] ?? [];
        $qtyInputs = $_POST['item_quantity'] ?? [];
        $costInputs = $_POST['item_unit_cost'] ?? [];
        $gstInputs = $_POST['item_gst_rate'] ?? [];

        if (!is_array($partInputs) || !is_array($qtyInputs) || !is_array($costInputs) || !is_array($gstInputs)) {
            flash_set('purchase_error', 'Invalid item payload.', 'danger');
            redirect('modules/purchases/index.php');
        }

        $maxRows = max(count($partInputs), count($qtyInputs), count($costInputs), count($gstInputs));
        $lineItems = [];

        for ($i = 0; $i < $maxRows; $i++) {
            $partId = filter_var($partInputs[$i] ?? 0, FILTER_VALIDATE_INT);
            $partId = $partId !== false ? (int) $partId : 0;
            $quantity = pur_decimal($qtyInputs[$i] ?? 0.0, 0.0);
            $unitCost = pur_decimal($costInputs[$i] ?? 0.0, 0.0);
            $gstRate = pur_decimal($gstInputs[$i] ?? 0.0, 0.0);

            $rowIsEmpty = $partId <= 0 && abs($quantity) < 0.00001 && abs($unitCost) < 0.00001 && abs($gstRate) < 0.00001;
            if ($rowIsEmpty) {
                continue;
            }

            if ($partId <= 0 || $quantity <= 0) {
                flash_set('purchase_error', 'Each purchase row must have a part and quantity greater than zero.', 'danger');
                redirect('modules/purchases/index.php');
            }

            if ($unitCost < 0 || $gstRate < 0 || $gstRate > 100) {
                flash_set('purchase_error', 'Unit cost/GST values are out of range.', 'danger');
                redirect('modules/purchases/index.php');
            }

            $taxableAmount = round($quantity * $unitCost, 2);
            $gstAmount = round(($taxableAmount * $gstRate) / 100, 2);
            $totalAmount = round($taxableAmount + $gstAmount, 2);

            $lineItems[] = [
                'part_id' => $partId,
                'quantity' => round($quantity, 2),
                'unit_cost' => round($unitCost, 2),
                'gst_rate' => round($gstRate, 2),
                'taxable_amount' => $taxableAmount,
                'gst_amount' => $gstAmount,
                'total_amount' => $totalAmount,
            ];
        }

        if (empty($lineItems)) {
            flash_set('purchase_error', 'At least one purchase item is required.', 'danger');
            redirect('modules/purchases/index.php');
        }

        $partIds = array_values(array_unique(array_map(static fn (array $item): int => (int) $item['part_id'], $lineItems)));
        $partMap = [];
        $partPlaceholder = implode(',', array_fill(0, count($partIds), '?'));
        $partCheckStmt = db()->prepare(
            "SELECT id, part_name, part_sku
             FROM parts
             WHERE company_id = ?
               AND status_code <> 'DELETED'
               AND id IN ({$partPlaceholder})"
        );
        $partCheckStmt->execute(array_merge([$companyId], $partIds));
        foreach ($partCheckStmt->fetchAll() as $partRow) {
            $partMap[(int) $partRow['id']] = $partRow;
        }

        foreach ($partIds as $partId) {
            if (!isset($partMap[$partId])) {
                flash_set('purchase_error', 'One or more selected parts are invalid for this company.', 'danger');
                redirect('modules/purchases/index.php');
            }
        }

        $totals = ['taxable' => 0.0, 'gst' => 0.0, 'grand' => 0.0];
        foreach ($lineItems as $item) {
            $totals['taxable'] += (float) $item['taxable_amount'];
            $totals['gst'] += (float) $item['gst_amount'];
            $totals['grand'] += (float) $item['total_amount'];
        }
        $totals = [
            'taxable' => round($totals['taxable'], 2),
            'gst' => round($totals['gst'], 2),
            'grand' => round($totals['grand'], 2),
        ];

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $purchaseInsert = $pdo->prepare(
                'INSERT INTO purchases
                  (company_id, garage_id, vendor_id, invoice_number, purchase_date, purchase_source, assignment_status, purchase_status, payment_status, taxable_amount, gst_amount, grand_total, notes, created_by, finalized_by, finalized_at)
                 VALUES
                  (:company_id, :garage_id, :vendor_id, :invoice_number, :purchase_date, "VENDOR_ENTRY", "ASSIGNED", :purchase_status, :payment_status, :taxable_amount, :gst_amount, :grand_total, :notes, :created_by, :finalized_by, :finalized_at)'
            );
            $purchaseInsert->execute([
                'company_id' => $companyId,
                'garage_id' => $activeGarageId,
                'vendor_id' => $vendorId,
                'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
                'purchase_date' => $purchaseDate,
                'purchase_status' => $targetStatus,
                'payment_status' => $paymentStatus,
                'taxable_amount' => $totals['taxable'],
                'gst_amount' => $totals['gst'],
                'grand_total' => $totals['grand'],
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => $userId,
                'finalized_by' => $targetStatus === 'FINALIZED' ? $userId : null,
                'finalized_at' => $targetStatus === 'FINALIZED' ? date('Y-m-d H:i:s') : null,
            ]);
            $purchaseId = (int) $pdo->lastInsertId();

            $itemInsert = $pdo->prepare(
                'INSERT INTO purchase_items
                  (purchase_id, part_id, quantity, unit_cost, gst_rate, taxable_amount, gst_amount, total_amount)
                 VALUES
                  (:purchase_id, :part_id, :quantity, :unit_cost, :gst_rate, :taxable_amount, :gst_amount, :total_amount)'
            );

            $stockUpdate = $pdo->prepare(
                'UPDATE garage_inventory
                 SET quantity = :quantity
                 WHERE garage_id = :garage_id
                   AND part_id = :part_id'
            );

            foreach ($lineItems as $index => $item) {
                $partId = (int) $item['part_id'];
                $qty = (float) $item['quantity'];
                $currentQty = pur_lock_inventory_row($pdo, $activeGarageId, $partId);
                $newQty = $currentQty + $qty;

                $stockUpdate->execute([
                    'quantity' => round($newQty, 2),
                    'garage_id' => $activeGarageId,
                    'part_id' => $partId,
                ]);

                $itemInsert->execute([
                    'purchase_id' => $purchaseId,
                    'part_id' => $partId,
                    'quantity' => round($qty, 2),
                    'unit_cost' => (float) $item['unit_cost'],
                    'gst_rate' => (float) $item['gst_rate'],
                    'taxable_amount' => (float) $item['taxable_amount'],
                    'gst_amount' => (float) $item['gst_amount'],
                    'total_amount' => (float) $item['total_amount'],
                ]);

                $movementNote = $notes !== '' ? $notes : ('Purchase #' . $purchaseId);
                pur_insert_movement($pdo, [
                    'company_id' => $companyId,
                    'garage_id' => $activeGarageId,
                    'part_id' => $partId,
                    'movement_type' => 'IN',
                    'quantity' => round($qty, 2),
                    'reference_type' => 'PURCHASE',
                    'reference_id' => $purchaseId,
                    'movement_uid' => 'pur-' . hash('sha256', $actionToken . '|' . $purchaseId . '|' . $partId . '|' . $index . '|' . microtime(true)),
                    'notes' => $movementNote,
                    'created_by' => $userId,
                ]);
            }

            $pdo->commit();
            log_audit(
                'purchases',
                'create',
                $purchaseId,
                'Created purchase #' . $purchaseId . ' (' . $targetStatus . ')',
                [
                    'entity' => 'purchase',
                    'source' => 'UI',
                    'after' => [
                        'purchase_id' => $purchaseId,
                        'vendor_id' => $vendorId,
                        'invoice_number' => $invoiceNumber,
                        'purchase_status' => $targetStatus,
                        'payment_status' => $paymentStatus,
                        'taxable_amount' => $totals['taxable'],
                        'gst_amount' => $totals['gst'],
                        'grand_total' => $totals['grand'],
                    ],
                    'metadata' => [
                        'item_count' => count($lineItems),
                        'garage_id' => $activeGarageId,
                    ],
                ]
            );
            flash_set('purchase_success', 'Purchase #' . $purchaseId . ' saved successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('purchase_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/purchases/index.php');
    }

    if ($action === 'assign_unassigned') {
        if (!$canManage) {
            flash_set('purchase_error', 'You do not have permission to assign unassigned purchases.', 'danger');
            redirect('modules/purchases/index.php');
        }

        $actionToken = trim((string) ($_POST['action_token'] ?? ''));
        if (!pur_consume_action_token('assign_unassigned', $actionToken)) {
            flash_set('purchase_warning', 'Duplicate or expired assignment request ignored.', 'warning');
            redirect('modules/purchases/index.php');
        }

        $purchaseId = post_int('purchase_id');
        $vendorId = post_int('vendor_id');
        $invoiceNumber = post_string('invoice_number', 80);
        $purchaseDate = trim((string) ($_POST['purchase_date'] ?? date('Y-m-d')));
        $paymentStatus = strtoupper(trim((string) ($_POST['payment_status'] ?? 'UNPAID')));
        $notes = post_string('notes', 255);
        $finalizeRequested = post_int('finalize_purchase') === 1;

        $allowedPaymentStatuses = ['UNPAID', 'PARTIAL', 'PAID'];
        if ($purchaseId <= 0) {
            flash_set('purchase_error', 'Unassigned purchase selection is required.', 'danger');
            redirect('modules/purchases/index.php');
        }
        if ($vendorId <= 0) {
            flash_set('purchase_error', 'Vendor is required.', 'danger');
            redirect('modules/purchases/index.php');
        }
        if ($invoiceNumber === '') {
            flash_set('purchase_error', 'Invoice number is required.', 'danger');
            redirect('modules/purchases/index.php');
        }
        if (!pur_is_valid_date($purchaseDate)) {
            flash_set('purchase_error', 'Invalid purchase date.', 'danger');
            redirect('modules/purchases/index.php');
        }
        if (!in_array($paymentStatus, $allowedPaymentStatuses, true)) {
            flash_set('purchase_error', 'Invalid payment status.', 'danger');
            redirect('modules/purchases/index.php');
        }
        if ($finalizeRequested && !$canFinalize) {
            flash_set('purchase_error', 'You do not have permission to finalize purchases.', 'danger');
            redirect('modules/purchases/index.php');
        }

        $vendorStmt = db()->prepare(
            'SELECT id
             FROM vendors
             WHERE id = :id
               AND company_id = :company_id
               AND (status_code IS NULL OR status_code = "ACTIVE")
             LIMIT 1'
        );
        $vendorStmt->execute([
            'id' => $vendorId,
            'company_id' => $companyId,
        ]);
        if (!$vendorStmt->fetch()) {
            flash_set('purchase_error', 'Selected vendor is invalid.', 'danger');
            redirect('modules/purchases/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $purchaseLockStmt = $pdo->prepare(
                'SELECT id, assignment_status, purchase_status, notes
                 FROM purchases
                 WHERE id = :id
                   AND company_id = :company_id
                   AND garage_id = :garage_id
                 FOR UPDATE'
            );
            $purchaseLockStmt->execute([
                'id' => $purchaseId,
                'company_id' => $companyId,
                'garage_id' => $activeGarageId,
            ]);
            $purchase = $purchaseLockStmt->fetch();
            if (!$purchase) {
                throw new RuntimeException('Purchase not found for this garage.');
            }
            if ((string) ($purchase['assignment_status'] ?? '') !== 'UNASSIGNED') {
                throw new RuntimeException('Selected purchase is already assigned.');
            }
            if ((string) ($purchase['purchase_status'] ?? '') !== 'DRAFT') {
                throw new RuntimeException('Only draft unassigned purchases can be assigned.');
            }

            $itemCountStmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_items WHERE purchase_id = :purchase_id');
            $itemCountStmt->execute(['purchase_id' => $purchaseId]);
            if ((int) $itemCountStmt->fetchColumn() === 0) {
                throw new RuntimeException('Cannot assign purchase without items.');
            }

            $finalizedAt = $finalizeRequested ? date('Y-m-d H:i:s') : null;
            $finalizedBy = $finalizeRequested ? $userId : null;
            $nextStatus = $finalizeRequested ? 'FINALIZED' : 'DRAFT';

            $updateStmt = $pdo->prepare(
                'UPDATE purchases
                 SET vendor_id = :vendor_id,
                     invoice_number = :invoice_number,
                     purchase_date = :purchase_date,
                     assignment_status = "ASSIGNED",
                     purchase_status = :purchase_status,
                     payment_status = :payment_status,
                     notes = :notes,
                     finalized_by = :finalized_by,
                     finalized_at = :finalized_at
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'vendor_id' => $vendorId,
                'invoice_number' => $invoiceNumber,
                'purchase_date' => $purchaseDate,
                'purchase_status' => $nextStatus,
                'payment_status' => $paymentStatus,
                'notes' => $notes !== '' ? $notes : ((string) ($purchase['notes'] ?? '') !== '' ? (string) $purchase['notes'] : null),
                'finalized_by' => $finalizedBy,
                'finalized_at' => $finalizedAt,
                'id' => $purchaseId,
            ]);

            $pdo->commit();
            log_audit(
                'purchases',
                $finalizeRequested ? 'assign_finalize' : 'assign',
                $purchaseId,
                ($finalizeRequested ? 'Assigned and finalized' : 'Assigned') . ' unassigned purchase #' . $purchaseId,
                [
                    'entity' => 'purchase',
                    'source' => 'UI',
                    'after' => [
                        'purchase_id' => $purchaseId,
                        'vendor_id' => $vendorId,
                        'invoice_number' => $invoiceNumber,
                        'purchase_status' => $nextStatus,
                        'assignment_status' => 'ASSIGNED',
                        'payment_status' => $paymentStatus,
                    ],
                ]
            );
            flash_set(
                'purchase_success',
                $finalizeRequested
                    ? ('Unassigned purchase #' . $purchaseId . ' assigned and finalized.')
                    : ('Unassigned purchase #' . $purchaseId . ' assigned successfully.'),
                'success'
            );
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('purchase_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/purchases/index.php');
    }

    if ($action === 'finalize_purchase') {
        if (!$canFinalize) {
            flash_set('purchase_error', 'You do not have permission to finalize purchases.', 'danger');
            redirect('modules/purchases/index.php');
        }

        $actionToken = trim((string) ($_POST['action_token'] ?? ''));
        if (!pur_consume_action_token('finalize_purchase', $actionToken)) {
            flash_set('purchase_warning', 'Duplicate or expired finalize request ignored.', 'warning');
            redirect('modules/purchases/index.php');
        }

        $purchaseId = post_int('purchase_id');
        if ($purchaseId <= 0) {
            flash_set('purchase_error', 'Invalid purchase id.', 'danger');
            redirect('modules/purchases/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $purchaseStmt = $pdo->prepare(
                'SELECT id, vendor_id, invoice_number, assignment_status, purchase_status
                 FROM purchases
                 WHERE id = :id
                   AND company_id = :company_id
                   AND garage_id = :garage_id
                 FOR UPDATE'
            );
            $purchaseStmt->execute([
                'id' => $purchaseId,
                'company_id' => $companyId,
                'garage_id' => $activeGarageId,
            ]);
            $purchase = $purchaseStmt->fetch();
            if (!$purchase) {
                throw new RuntimeException('Purchase not found for this garage.');
            }
            if ((string) ($purchase['purchase_status'] ?? '') === 'FINALIZED') {
                throw new RuntimeException('Purchase is already finalized.');
            }
            if ((string) ($purchase['assignment_status'] ?? '') !== 'ASSIGNED') {
                throw new RuntimeException('Unassigned purchase must be assigned before finalization.');
            }
            if ((int) ($purchase['vendor_id'] ?? 0) <= 0 || trim((string) ($purchase['invoice_number'] ?? '')) === '') {
                throw new RuntimeException('Vendor and invoice number are required before finalization.');
            }

            $itemCountStmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_items WHERE purchase_id = :purchase_id');
            $itemCountStmt->execute(['purchase_id' => $purchaseId]);
            if ((int) $itemCountStmt->fetchColumn() === 0) {
                throw new RuntimeException('Cannot finalize a purchase without items.');
            }

            $updateStmt = $pdo->prepare(
                'UPDATE purchases
                 SET purchase_status = "FINALIZED",
                     finalized_by = :finalized_by,
                     finalized_at = :finalized_at
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'finalized_by' => $userId,
                'finalized_at' => date('Y-m-d H:i:s'),
                'id' => $purchaseId,
            ]);

            $pdo->commit();
            log_audit('purchases', 'finalize', $purchaseId, 'Finalized purchase #' . $purchaseId, [
                'entity' => 'purchase',
                'source' => 'UI',
            ]);
            flash_set('purchase_success', 'Purchase #' . $purchaseId . ' finalized.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('purchase_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/purchases/index.php');
    }
}

$garageName = 'Garage #' . $activeGarageId;
$garageStmt = db()->prepare(
    'SELECT name
     FROM garages
     WHERE id = :garage_id
       AND company_id = :company_id
     LIMIT 1'
);
$garageStmt->execute([
    'garage_id' => $activeGarageId,
    'company_id' => $companyId,
]);
$garageNameRow = $garageStmt->fetch();
if ($garageNameRow && trim((string) ($garageNameRow['name'] ?? '')) !== '') {
    $garageName = (string) $garageNameRow['name'];
}

$vendors = [];
$parts = [];

if ($purchasesReady) {
    $vendorStmt = db()->prepare(
        'SELECT id, vendor_code, vendor_name, gstin
         FROM vendors
         WHERE company_id = :company_id
           AND (status_code IS NULL OR status_code = "ACTIVE")
         ORDER BY vendor_name ASC'
    );
    $vendorStmt->execute(['company_id' => $companyId]);
    $vendors = $vendorStmt->fetchAll();

    $partsStmt = db()->prepare(
        'SELECT p.id, p.part_name, p.part_sku, p.unit, p.purchase_price, p.gst_rate,
                COALESCE(gi.quantity, 0) AS stock_qty
         FROM parts p
         LEFT JOIN garage_inventory gi ON gi.part_id = p.id AND gi.garage_id = :garage_id
         WHERE p.company_id = :company_id
           AND p.status_code <> "DELETED"
         ORDER BY p.part_name ASC'
    );
    $partsStmt->execute([
        'garage_id' => $activeGarageId,
        'company_id' => $companyId,
    ]);
    $parts = $partsStmt->fetchAll();
}

$vendorFilter = get_int('vendor_id', 0);
$paymentFilter = strtoupper(trim((string) ($_GET['payment_status'] ?? '')));
$statusFilter = strtoupper(trim((string) ($_GET['purchase_status'] ?? '')));
$assignmentFilter = strtoupper(trim((string) ($_GET['assignment_status'] ?? '')));
$sourceFilter = strtoupper(trim((string) ($_GET['purchase_source'] ?? '')));
$invoiceFilter = trim((string) ($_GET['invoice'] ?? ''));
$fromDate = trim((string) ($_GET['from'] ?? ''));
$toDate = trim((string) ($_GET['to'] ?? ''));
$assignPurchaseId = get_int('assign_purchase_id', 0);

$allowedPaymentStatuses = ['UNPAID', 'PARTIAL', 'PAID'];
$allowedPurchaseStatuses = ['DRAFT', 'FINALIZED'];
$allowedAssignmentStatuses = ['ASSIGNED', 'UNASSIGNED'];
$allowedSourceStatuses = ['VENDOR_ENTRY', 'MANUAL_ADJUSTMENT', 'TEMP_CONVERSION'];

if (!in_array($paymentFilter, $allowedPaymentStatuses, true)) {
    $paymentFilter = '';
}
if (!in_array($statusFilter, $allowedPurchaseStatuses, true)) {
    $statusFilter = '';
}
if (!in_array($assignmentFilter, $allowedAssignmentStatuses, true)) {
    $assignmentFilter = '';
}
if (!in_array($sourceFilter, $allowedSourceStatuses, true)) {
    $sourceFilter = '';
}
if (!pur_is_valid_date($fromDate)) {
    $fromDate = '';
}
if (!pur_is_valid_date($toDate)) {
    $toDate = '';
}

$purchaseWhere = ['p.company_id = :company_id', 'p.garage_id = :garage_id'];
$purchaseParams = [
    'company_id' => $companyId,
    'garage_id' => $activeGarageId,
];

if ($vendorFilter > 0) {
    $purchaseWhere[] = 'p.vendor_id = :vendor_id';
    $purchaseParams['vendor_id'] = $vendorFilter;
}
if ($paymentFilter !== '') {
    $purchaseWhere[] = 'p.payment_status = :payment_status';
    $purchaseParams['payment_status'] = $paymentFilter;
}
if ($statusFilter !== '') {
    $purchaseWhere[] = 'p.purchase_status = :purchase_status';
    $purchaseParams['purchase_status'] = $statusFilter;
}
if ($assignmentFilter !== '') {
    $purchaseWhere[] = 'p.assignment_status = :assignment_status';
    $purchaseParams['assignment_status'] = $assignmentFilter;
}
if ($sourceFilter !== '') {
    $purchaseWhere[] = 'p.purchase_source = :purchase_source';
    $purchaseParams['purchase_source'] = $sourceFilter;
}
if ($invoiceFilter !== '') {
    $purchaseWhere[] = 'p.invoice_number LIKE :invoice_like';
    $purchaseParams['invoice_like'] = '%' . $invoiceFilter . '%';
}
if ($fromDate !== '') {
    $purchaseWhere[] = 'p.purchase_date >= :from_date';
    $purchaseParams['from_date'] = $fromDate;
}
if ($toDate !== '') {
    $purchaseWhere[] = 'p.purchase_date <= :to_date';
    $purchaseParams['to_date'] = $toDate;
}

$purchases = [];
$summary = [
    'purchase_count' => 0,
    'unassigned_count' => 0,
    'finalized_count' => 0,
    'taxable_total' => 0,
    'gst_total' => 0,
    'grand_total' => 0,
];
$topParts = [];
$unassignedRows = [];

if ($purchasesReady) {
    $summaryStmt = db()->prepare(
        'SELECT
            COUNT(*) AS purchase_count,
            COALESCE(SUM(CASE WHEN p.assignment_status = "UNASSIGNED" THEN 1 ELSE 0 END), 0) AS unassigned_count,
            COALESCE(SUM(CASE WHEN p.purchase_status = "FINALIZED" THEN 1 ELSE 0 END), 0) AS finalized_count,
            COALESCE(SUM(p.taxable_amount), 0) AS taxable_total,
            COALESCE(SUM(p.gst_amount), 0) AS gst_total,
            COALESCE(SUM(p.grand_total), 0) AS grand_total
         FROM purchases p
         WHERE ' . implode(' AND ', $purchaseWhere)
    );
    $summaryStmt->execute($purchaseParams);
    $summary = $summaryStmt->fetch() ?: $summary;

    $listStmt = db()->prepare(
        'SELECT p.*,
                v.vendor_name,
                u.name AS created_by_name,
                fu.name AS finalized_by_name,
                COALESCE(items.item_count, 0) AS item_count,
                COALESCE(items.total_qty, 0) AS total_qty
         FROM purchases p
         LEFT JOIN vendors v ON v.id = p.vendor_id
         LEFT JOIN users u ON u.id = p.created_by
         LEFT JOIN users fu ON fu.id = p.finalized_by
         LEFT JOIN (
            SELECT purchase_id, COUNT(*) AS item_count, COALESCE(SUM(quantity), 0) AS total_qty
            FROM purchase_items
            GROUP BY purchase_id
         ) items ON items.purchase_id = p.id
         WHERE ' . implode(' AND ', $purchaseWhere) . '
         ORDER BY p.id DESC
         LIMIT 200'
    );
    $listStmt->execute($purchaseParams);
    $purchases = $listStmt->fetchAll();

    $topPartStmt = db()->prepare(
        'SELECT pt.part_name, pt.part_sku,
                COALESCE(SUM(pi.quantity), 0) AS total_qty,
                COALESCE(SUM(pi.total_amount), 0) AS total_amount
         FROM purchases p
         INNER JOIN purchase_items pi ON pi.purchase_id = p.id
         INNER JOIN parts pt ON pt.id = pi.part_id
         WHERE ' . implode(' AND ', $purchaseWhere) . '
         GROUP BY pt.id, pt.part_name, pt.part_sku
         ORDER BY total_qty DESC
         LIMIT 8'
    );
    $topPartStmt->execute($purchaseParams);
    $topParts = $topPartStmt->fetchAll();

    $unassignedStmt = db()->prepare(
        'SELECT p.id, p.purchase_date, p.invoice_number, p.purchase_source, p.grand_total,
                COALESCE(COUNT(pi.id), 0) AS item_count,
                COALESCE(SUM(pi.quantity), 0) AS total_qty
         FROM purchases p
         LEFT JOIN purchase_items pi ON pi.purchase_id = p.id
         WHERE p.company_id = :company_id
           AND p.garage_id = :garage_id
           AND p.assignment_status = "UNASSIGNED"
           AND p.purchase_status = "DRAFT"
         GROUP BY p.id, p.purchase_date, p.invoice_number, p.purchase_source, p.grand_total
         ORDER BY p.id DESC
         LIMIT 80'
    );
    $unassignedStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $activeGarageId,
    ]);
    $unassignedRows = $unassignedStmt->fetchAll();

    $export = strtolower(trim((string) ($_GET['export'] ?? '')));
    if ($export === 'csv') {
        if (!$canExport) {
            flash_set('purchase_error', 'You do not have permission to export purchase reports.', 'danger');
            redirect('modules/purchases/index.php');
        }

        $exportStmt = db()->prepare(
            'SELECT p.id AS purchase_id, p.purchase_date, p.purchase_source, p.assignment_status, p.purchase_status, p.payment_status,
                    COALESCE(v.vendor_name, "UNASSIGNED") AS vendor_name,
                    COALESCE(p.invoice_number, "-") AS invoice_number,
                    pt.part_sku, pt.part_name,
                    pi.quantity, pi.unit_cost, pi.gst_rate, pi.taxable_amount, pi.gst_amount, pi.total_amount,
                    p.taxable_amount AS purchase_taxable_amount,
                    p.gst_amount AS purchase_gst_amount,
                    p.grand_total AS purchase_grand_total,
                    u.name AS created_by_name
             FROM purchases p
             LEFT JOIN vendors v ON v.id = p.vendor_id
             LEFT JOIN users u ON u.id = p.created_by
             INNER JOIN purchase_items pi ON pi.purchase_id = p.id
             INNER JOIN parts pt ON pt.id = pi.part_id
             WHERE ' . implode(' AND ', $purchaseWhere) . '
             ORDER BY p.id DESC, pi.id ASC'
        );
        $exportStmt->execute($purchaseParams);
        $exportRows = $exportStmt->fetchAll();

        $filename = 'purchases_' . date('Ymd_His') . '.csv';
        $headers = [
            'Purchase ID',
            'Purchase Date',
            'Source',
            'Assignment Status',
            'Purchase Status',
            'Payment Status',
            'Vendor',
            'Invoice Number',
            'Part SKU',
            'Part Name',
            'Quantity',
            'Unit Cost',
            'GST Rate',
            'Line Taxable',
            'Line GST',
            'Line Total',
            'Purchase Taxable',
            'Purchase GST',
            'Purchase Grand Total',
            'Created By',
        ];
        $rows = [];
        foreach ($exportRows as $row) {
            $rows[] = [
                (int) ($row['purchase_id'] ?? 0),
                (string) ($row['purchase_date'] ?? ''),
                (string) ($row['purchase_source'] ?? ''),
                (string) ($row['assignment_status'] ?? ''),
                (string) ($row['purchase_status'] ?? ''),
                (string) ($row['payment_status'] ?? ''),
                (string) ($row['vendor_name'] ?? ''),
                (string) ($row['invoice_number'] ?? ''),
                (string) ($row['part_sku'] ?? ''),
                (string) ($row['part_name'] ?? ''),
                number_format((float) ($row['quantity'] ?? 0), 2, '.', ''),
                number_format((float) ($row['unit_cost'] ?? 0), 2, '.', ''),
                number_format((float) ($row['gst_rate'] ?? 0), 2, '.', ''),
                number_format((float) ($row['taxable_amount'] ?? 0), 2, '.', ''),
                number_format((float) ($row['gst_amount'] ?? 0), 2, '.', ''),
                number_format((float) ($row['total_amount'] ?? 0), 2, '.', ''),
                number_format((float) ($row['purchase_taxable_amount'] ?? 0), 2, '.', ''),
                number_format((float) ($row['purchase_gst_amount'] ?? 0), 2, '.', ''),
                number_format((float) ($row['purchase_grand_total'] ?? 0), 2, '.', ''),
                (string) ($row['created_by_name'] ?? ''),
            ];
        }

        pur_csv_download($filename, $headers, $rows, [
            'garage_id' => $activeGarageId,
            'vendor_id' => $vendorFilter > 0 ? $vendorFilter : null,
            'payment_status' => $paymentFilter !== '' ? $paymentFilter : null,
            'purchase_status' => $statusFilter !== '' ? $statusFilter : null,
            'assignment_status' => $assignmentFilter !== '' ? $assignmentFilter : null,
            'purchase_source' => $sourceFilter !== '' ? $sourceFilter : null,
            'invoice' => $invoiceFilter !== '' ? $invoiceFilter : null,
            'from' => $fromDate !== '' ? $fromDate : null,
            'to' => $toDate !== '' ? $toDate : null,
        ]);
    }
}

$createToken = pur_issue_action_token('create_purchase');
$assignToken = pur_issue_action_token('assign_unassigned');
$finalizeToken = pur_issue_action_token('finalize_purchase');

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Purchase Module</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Purchases</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div>
            <strong>Active Garage:</strong> <?= e($garageName); ?>
          </div>
          <div class="text-muted">
            Purchase stock posting is immediate and inventory-safe.
          </div>
        </div>
      </div>

      <?php if (!$purchasesReady): ?>
        <div class="alert alert-danger">
          Purchase module tables are missing. Run <code>database/purchase_module_upgrade.sql</code> and refresh.
        </div>
      <?php else: ?>
        <div class="row g-3 mb-3">
          <?php if ($canManage): ?>
            <div class="col-lg-8">
              <div class="card card-primary">
                <div class="card-header"><h3 class="card-title">Vendor Purchase Entry</h3></div>
                <form method="post">
                  <div class="card-body">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="create_purchase">
                    <input type="hidden" name="action_token" value="<?= e($createToken); ?>">

                    <div class="row g-2 mb-3">
                      <div class="col-md-4">
                        <label class="form-label">Vendor</label>
                        <select name="vendor_id" class="form-select" required>
                          <option value="">Select Vendor</option>
                          <?php foreach ($vendors as $vendor): ?>
                            <option value="<?= (int) $vendor['id']; ?>">
                              <?= e((string) $vendor['vendor_name']); ?> (<?= e((string) $vendor['vendor_code']); ?>)
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-md-3">
                        <label class="form-label">Invoice Number</label>
                        <input type="text" name="invoice_number" class="form-control" maxlength="80" placeholder="Vendor invoice no" required>
                      </div>
                      <div class="col-md-2">
                        <label class="form-label">Date</label>
                        <input type="date" name="purchase_date" class="form-control" value="<?= e(date('Y-m-d')); ?>" required>
                      </div>
                      <div class="col-md-3">
                        <label class="form-label">Payment Status</label>
                        <select name="payment_status" class="form-select" required>
                          <option value="UNPAID">UNPAID</option>
                          <option value="PARTIAL">PARTIAL</option>
                          <option value="PAID">PAID</option>
                        </select>
                      </div>
                    </div>

                    <div class="table-responsive">
                      <table class="table table-sm table-bordered align-middle mb-2" id="purchase-item-table">
                        <thead>
                          <tr>
                            <th style="width: 42%;">Part</th>
                            <th style="width: 14%;">Qty</th>
                            <th style="width: 16%;">Unit Cost</th>
                            <th style="width: 14%;">GST %</th>
                            <th style="width: 14%;">Default Stock</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php for ($row = 0; $row < 4; $row++): ?>
                            <tr>
                              <td>
                                <select name="item_part_id[]" class="form-select form-select-sm js-item-part">
                                  <option value="">Select Part</option>
                                  <?php foreach ($parts as $part): ?>
                                    <option
                                      value="<?= (int) $part['id']; ?>"
                                      data-default-cost="<?= e(number_format((float) ($part['purchase_price'] ?? 0), 2, '.', '')); ?>"
                                      data-default-gst="<?= e(number_format((float) ($part['gst_rate'] ?? 0), 2, '.', '')); ?>"
                                      data-stock="<?= e(number_format((float) ($part['stock_qty'] ?? 0), 2, '.', '')); ?>"
                                    >
                                      <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>)
                                    </option>
                                  <?php endforeach; ?>
                                </select>
                              </td>
                              <td><input type="number" name="item_quantity[]" class="form-control form-control-sm" step="0.01" min="0"></td>
                              <td><input type="number" name="item_unit_cost[]" class="form-control form-control-sm js-item-cost" step="0.01" min="0"></td>
                              <td><input type="number" name="item_gst_rate[]" class="form-control form-control-sm js-item-gst" step="0.01" min="0"></td>
                              <td class="text-muted small js-item-stock">-</td>
                            </tr>
                          <?php endfor; ?>
                        </tbody>
                      </table>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="add-item-row">Add Item Row</button>

                    <div class="mt-3">
                      <label class="form-label">Notes</label>
                      <input type="text" name="notes" class="form-control" maxlength="255" placeholder="Purchase remarks / GRN note">
                    </div>
                  </div>
                  <div class="card-footer d-flex gap-2">
                    <button type="submit" name="target_status" value="DRAFT" class="btn btn-outline-primary">Save Draft</button>
                    <?php if ($canFinalize): ?>
                      <button type="submit" name="target_status" value="FINALIZED" class="btn btn-primary">Save & Finalize</button>
                    <?php endif; ?>
                  </div>
                </form>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($canManage): ?>
            <div class="col-lg-4">
              <div class="card card-warning">
                <div class="card-header"><h3 class="card-title">Assign Unassigned Purchase</h3></div>
                <?php if (empty($unassignedRows)): ?>
                  <div class="card-body text-muted">No unassigned draft purchases available.</div>
                <?php else: ?>
                  <form method="post">
                    <div class="card-body">
                      <?= csrf_field(); ?>
                      <input type="hidden" name="_action" value="assign_unassigned">
                      <input type="hidden" name="action_token" value="<?= e($assignToken); ?>">

                      <div class="mb-2">
                        <label class="form-label">Unassigned Purchase</label>
                        <select name="purchase_id" class="form-select" required>
                          <option value="">Select Purchase</option>
                          <?php foreach ($unassignedRows as $row): ?>
                            <option value="<?= (int) $row['id']; ?>" <?= $assignPurchaseId === (int) $row['id'] ? 'selected' : ''; ?>>
                              #<?= (int) $row['id']; ?> | <?= e((string) $row['purchase_date']); ?> | Qty <?= e(number_format((float) $row['total_qty'], 2)); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="mb-2">
                        <label class="form-label">Vendor</label>
                        <select name="vendor_id" class="form-select" required>
                          <option value="">Select Vendor</option>
                          <?php foreach ($vendors as $vendor): ?>
                            <option value="<?= (int) $vendor['id']; ?>">
                              <?= e((string) $vendor['vendor_name']); ?> (<?= e((string) $vendor['vendor_code']); ?>)
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="mb-2">
                        <label class="form-label">Invoice Number</label>
                        <input type="text" name="invoice_number" class="form-control" maxlength="80" required>
                      </div>
                      <div class="mb-2">
                        <label class="form-label">Date</label>
                        <input type="date" name="purchase_date" class="form-control" value="<?= e(date('Y-m-d')); ?>" required>
                      </div>
                      <div class="mb-2">
                        <label class="form-label">Payment Status</label>
                        <select name="payment_status" class="form-select" required>
                          <option value="UNPAID">UNPAID</option>
                          <option value="PARTIAL">PARTIAL</option>
                          <option value="PAID">PAID</option>
                        </select>
                      </div>
                      <div class="mb-2">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" maxlength="255" placeholder="Optional assignment note">
                      </div>
                      <?php if ($canFinalize): ?>
                        <div class="form-check mt-2">
                          <input class="form-check-input" type="checkbox" name="finalize_purchase" value="1" id="finalize_purchase_assign" checked>
                          <label class="form-check-label" for="finalize_purchase_assign">
                            Finalize now
                          </label>
                        </div>
                      <?php endif; ?>
                    </div>
                    <div class="card-footer">
                      <button type="submit" class="btn btn-warning">Assign Purchase</button>
                    </div>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <div class="card mb-3">
          <div class="card-header"><h3 class="card-title">Purchase Filters & Reports</h3></div>
          <form method="get">
            <div class="card-body row g-2">
              <div class="col-md-2">
                <label class="form-label">Invoice</label>
                <input type="text" name="invoice" class="form-control" maxlength="80" value="<?= e($invoiceFilter); ?>" placeholder="Search invoice">
              </div>
              <div class="col-md-2">
                <label class="form-label">Vendor</label>
                <select name="vendor_id" class="form-select">
                  <option value="0">All Vendors</option>
                  <?php foreach ($vendors as $vendor): ?>
                    <option value="<?= (int) $vendor['id']; ?>" <?= $vendorFilter === (int) $vendor['id'] ? 'selected' : ''; ?>>
                      <?= e((string) $vendor['vendor_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Payment</label>
                <select name="payment_status" class="form-select">
                  <option value="">All</option>
                  <?php foreach ($allowedPaymentStatuses as $status): ?>
                    <option value="<?= e($status); ?>" <?= $paymentFilter === $status ? 'selected' : ''; ?>><?= e($status); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Purchase Status</label>
                <select name="purchase_status" class="form-select">
                  <option value="">All</option>
                  <?php foreach ($allowedPurchaseStatuses as $status): ?>
                    <option value="<?= e($status); ?>" <?= $statusFilter === $status ? 'selected' : ''; ?>><?= e($status); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Assignment</label>
                <select name="assignment_status" class="form-select">
                  <option value="">All</option>
                  <?php foreach ($allowedAssignmentStatuses as $status): ?>
                    <option value="<?= e($status); ?>" <?= $assignmentFilter === $status ? 'selected' : ''; ?>><?= e($status); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Source</label>
                <select name="purchase_source" class="form-select">
                  <option value="">All</option>
                  <?php foreach ($allowedSourceStatuses as $source): ?>
                    <option value="<?= e($source); ?>" <?= $sourceFilter === $source ? 'selected' : ''; ?>><?= e($source); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">From</label>
                <input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">To</label>
                <input type="date" name="to" class="form-control" value="<?= e($toDate); ?>">
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-outline-primary">Apply Filters</button>
              <a href="<?= e(url('modules/purchases/index.php')); ?>" class="btn btn-outline-secondary">Reset</a>
              <?php if ($canExport): ?>
                <?php
                  $exportParams = $_GET;
                  $exportParams['export'] = 'csv';
                ?>
                <a href="<?= e(url('modules/purchases/index.php?' . http_build_query($exportParams))); ?>" class="btn btn-outline-success">Export CSV</a>
              <?php endif; ?>
            </div>
          </form>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-2">
            <div class="small-box text-bg-primary">
              <div class="inner">
                <h4><?= e((string) ((int) ($summary['purchase_count'] ?? 0))); ?></h4>
                <p>Purchases</p>
              </div>
              <span class="small-box-icon"><i class="bi bi-receipt"></i></span>
            </div>
          </div>
          <div class="col-md-2">
            <div class="small-box text-bg-warning">
              <div class="inner">
                <h4><?= e((string) ((int) ($summary['unassigned_count'] ?? 0))); ?></h4>
                <p>Unassigned</p>
              </div>
              <span class="small-box-icon"><i class="bi bi-exclamation-triangle"></i></span>
            </div>
          </div>
          <div class="col-md-2">
            <div class="small-box text-bg-success">
              <div class="inner">
                <h4><?= e((string) ((int) ($summary['finalized_count'] ?? 0))); ?></h4>
                <p>Finalized</p>
              </div>
              <span class="small-box-icon"><i class="bi bi-check-circle"></i></span>
            </div>
          </div>
          <div class="col-md-2">
            <div class="small-box text-bg-secondary">
              <div class="inner">
                <h4><?= e(number_format((float) ($summary['taxable_total'] ?? 0), 2)); ?></h4>
                <p>Taxable</p>
              </div>
              <span class="small-box-icon"><i class="bi bi-cash-stack"></i></span>
            </div>
          </div>
          <div class="col-md-2">
            <div class="small-box text-bg-info">
              <div class="inner">
                <h4><?= e(number_format((float) ($summary['gst_total'] ?? 0), 2)); ?></h4>
                <p>GST</p>
              </div>
              <span class="small-box-icon"><i class="bi bi-percent"></i></span>
            </div>
          </div>
          <div class="col-md-2">
            <div class="small-box text-bg-dark">
              <div class="inner">
                <h4><?= e(number_format((float) ($summary['grand_total'] ?? 0), 2)); ?></h4>
                <p>Grand Total</p>
              </div>
              <span class="small-box-icon"><i class="bi bi-currency-rupee"></i></span>
            </div>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header"><h3 class="card-title">Purchase List</h3></div>
          <div class="card-body table-responsive p-0">
            <table class="table table-sm table-striped mb-0">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Date</th>
                  <th>Vendor</th>
                  <th>Invoice</th>
                  <th>Source</th>
                  <th>Assignment</th>
                  <th>Status</th>
                  <th>Payment</th>
                  <th>Items</th>
                  <th>Qty</th>
                  <th>Taxable</th>
                  <th>GST</th>
                  <th>Grand</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($purchases)): ?>
                  <tr><td colspan="14" class="text-center text-muted py-4">No purchases found for selected filters.</td></tr>
                <?php else: ?>
                  <?php foreach ($purchases as $purchase): ?>
                    <?php
                      $purchaseId = (int) ($purchase['id'] ?? 0);
                      $isUnassigned = (string) ($purchase['assignment_status'] ?? '') === 'UNASSIGNED';
                      $isDraft = (string) ($purchase['purchase_status'] ?? '') === 'DRAFT';
                    ?>
                    <tr>
                      <td><code>#<?= e((string) $purchaseId); ?></code></td>
                      <td><?= e((string) ($purchase['purchase_date'] ?? '-')); ?></td>
                      <td><?= e((string) ($purchase['vendor_name'] ?? 'UNASSIGNED')); ?></td>
                      <td><?= e((string) (($purchase['invoice_number'] ?? '') !== '' ? $purchase['invoice_number'] : '-')); ?></td>
                      <td><?= e((string) ($purchase['purchase_source'] ?? '-')); ?></td>
                      <td>
                        <span class="badge text-bg-<?= e(pur_assignment_badge_class((string) ($purchase['assignment_status'] ?? 'UNASSIGNED'))); ?>">
                          <?= e((string) ($purchase['assignment_status'] ?? 'UNASSIGNED')); ?>
                        </span>
                      </td>
                      <td>
                        <span class="badge text-bg-<?= e(pur_purchase_badge_class((string) ($purchase['purchase_status'] ?? 'DRAFT'))); ?>">
                          <?= e((string) ($purchase['purchase_status'] ?? 'DRAFT')); ?>
                        </span>
                      </td>
                      <td>
                        <span class="badge text-bg-<?= e(pur_payment_badge_class((string) ($purchase['payment_status'] ?? 'UNPAID'))); ?>">
                          <?= e((string) ($purchase['payment_status'] ?? 'UNPAID')); ?>
                        </span>
                      </td>
                      <td><?= e((string) ((int) ($purchase['item_count'] ?? 0))); ?></td>
                      <td><?= e(number_format((float) ($purchase['total_qty'] ?? 0), 2)); ?></td>
                      <td><?= e(number_format((float) ($purchase['taxable_amount'] ?? 0), 2)); ?></td>
                      <td><?= e(number_format((float) ($purchase['gst_amount'] ?? 0), 2)); ?></td>
                      <td><strong><?= e(number_format((float) ($purchase['grand_total'] ?? 0), 2)); ?></strong></td>
                      <td class="text-nowrap">
                        <?php if ($isUnassigned && $canManage): ?>
                          <a class="btn btn-sm btn-outline-warning" href="<?= e(url('modules/purchases/index.php?assign_purchase_id=' . $purchaseId)); ?>">Assign</a>
                        <?php endif; ?>
                        <?php if (!$isUnassigned && $isDraft && $canFinalize): ?>
                          <form method="post" class="d-inline" data-confirm="Finalize this purchase?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="finalize_purchase">
                            <input type="hidden" name="action_token" value="<?= e($finalizeToken); ?>">
                            <input type="hidden" name="purchase_id" value="<?= (int) $purchaseId; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-success">Finalize</button>
                          </form>
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
          <div class="card-header"><h3 class="card-title">Top Purchased Parts (Filtered)</h3></div>
          <div class="card-body table-responsive p-0">
            <table class="table table-sm table-striped mb-0">
              <thead>
                <tr>
                  <th>Part</th>
                  <th>SKU</th>
                  <th>Total Qty</th>
                  <th>Total Value</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($topParts)): ?>
                  <tr><td colspan="4" class="text-center text-muted py-4">No purchase item data for selected filters.</td></tr>
                <?php else: ?>
                  <?php foreach ($topParts as $part): ?>
                    <tr>
                      <td><?= e((string) ($part['part_name'] ?? '')); ?></td>
                      <td><code><?= e((string) ($part['part_sku'] ?? '')); ?></code></td>
                      <td><?= e(number_format((float) ($part['total_qty'] ?? 0), 2)); ?></td>
                      <td><?= e(number_format((float) ($part['total_amount'] ?? 0), 2)); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<script>
  (function () {
    var table = document.getElementById('purchase-item-table');
    var addRowBtn = document.getElementById('add-item-row');
    if (!table || !addRowBtn) {
      return;
    }

    function wirePartSelect(select) {
      if (!select) {
        return;
      }

      select.addEventListener('change', function () {
        var row = select.closest('tr');
        if (!row) {
          return;
        }

        var selectedOption = select.options[select.selectedIndex];
        var costInput = row.querySelector('.js-item-cost');
        var gstInput = row.querySelector('.js-item-gst');
        var stockCell = row.querySelector('.js-item-stock');

        if (stockCell) {
          var stockValue = selectedOption ? selectedOption.getAttribute('data-stock') : '';
          stockCell.textContent = stockValue && stockValue !== '' ? stockValue : '-';
        }

        if (!selectedOption) {
          return;
        }

        var defaultCost = selectedOption.getAttribute('data-default-cost');
        var defaultGst = selectedOption.getAttribute('data-default-gst');

        if (costInput && (!costInput.value || parseFloat(costInput.value) === 0)) {
          costInput.value = defaultCost || '';
        }
        if (gstInput && (!gstInput.value || parseFloat(gstInput.value) === 0)) {
          gstInput.value = defaultGst || '';
        }
      });
    }

    table.querySelectorAll('.js-item-part').forEach(wirePartSelect);

    addRowBtn.addEventListener('click', function () {
      var body = table.querySelector('tbody');
      if (!body) {
        return;
      }

      var templateRow = body.querySelector('tr');
      if (!templateRow) {
        return;
      }

      var clone = templateRow.cloneNode(true);
      clone.querySelectorAll('input').forEach(function (input) {
        input.value = '';
      });
      clone.querySelectorAll('select').forEach(function (select) {
        select.selectedIndex = 0;
      });
      var stockCell = clone.querySelector('.js-item-stock');
      if (stockCell) {
        stockCell.textContent = '-';
      }

      body.appendChild(clone);
      wirePartSelect(clone.querySelector('.js-item-part'));
    });
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
