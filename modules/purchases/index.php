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
$canPayPurchases = has_permission('purchase.payments') || $canManage;
$canViewVendorPayables = has_permission('vendor.payments') || $canManage;
$canDeletePurchases = has_permission('purchase.delete') || $canManage;

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

function pur_value_has_fraction(float $value): bool
{
    return abs($value - round($value)) > 0.00001;
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

function pur_payment_modes(): array
{
  return ['CASH', 'UPI', 'CARD', 'BANK_TRANSFER', 'CHEQUE', 'MIXED', 'ADJUSTMENT'];
}

function pur_payment_status_from_amounts(float $grandTotal, float $paidAmount): string
{
  if ($grandTotal <= 0) {
    return 'PAID';
  }

  if ($paidAmount <= 0.009) {
    return 'UNPAID';
  }

  if ($paidAmount + 0.009 >= $grandTotal) {
    return 'PAID';
  }

  return 'PARTIAL';
}

function pur_fetch_purchase_for_update(PDO $pdo, int $purchaseId, int $companyId, int $garageId): ?array
{
  $sql =
    'SELECT p.*,
        COALESCE(pay.total_paid, 0) AS total_paid
     FROM purchases p
     LEFT JOIN (
       SELECT purchase_id, SUM(amount) AS total_paid
       FROM purchase_payments
       GROUP BY purchase_id
     ) pay ON pay.purchase_id = p.id
     WHERE p.id = :id
       AND p.company_id = :company_id
       AND p.garage_id = :garage_id'
    . pur_deleted_scope_sql('p') . '
     LIMIT 1
     FOR UPDATE';
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    'id' => $purchaseId,
    'company_id' => $companyId,
    'garage_id' => $garageId,
  ]);

  $row = $stmt->fetch();
  return $row ?: null;
}

function pur_supports_soft_delete(): bool
{
  return in_array('status_code', table_columns('purchases'), true);
}

function pur_purchase_is_deleted(array $purchase): bool
{
  if (!pur_supports_soft_delete()) {
    return false;
  }

  return strtoupper(trim((string) ($purchase['status_code'] ?? 'ACTIVE'))) === 'DELETED';
}

function pur_deleted_scope_sql(string $alias = 'p'): string
{
  if (!pur_supports_soft_delete()) {
    return '';
  }

  return ' AND ' . $alias . '.status_code <> "DELETED"';
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
$purchasePaymentsReady = table_columns('purchase_payments') !== [];

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
            "SELECT id, part_name, part_sku, unit
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

        foreach ($lineItems as $item) {
            $partId = (int) ($item['part_id'] ?? 0);
            $partRow = $partMap[$partId] ?? null;
            if (!is_array($partRow)) {
                continue;
            }

            $partName = trim((string) ($partRow['part_name'] ?? 'Part #' . $partId));
            $partUnitCode = part_unit_normalize_code((string) ($partRow['unit'] ?? ''));
            if ($partUnitCode === '') {
                $partUnitCode = 'PCS';
            }
            if (!part_unit_allows_decimal($companyId, $partUnitCode) && pur_value_has_fraction((float) ($item['quantity'] ?? 0))) {
                flash_set('purchase_error', 'Part "' . $partName . '" uses unit ' . $partUnitCode . ' and allows only whole quantity.', 'danger');
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

              if ($purchasePaymentsReady && $paymentStatus === 'PAID' && $totals['grand'] > 0) {
                $paymentStmt = $pdo->prepare(
                  'INSERT INTO purchase_payments
                    (purchase_id, company_id, garage_id, payment_date, entry_type, amount, payment_mode, reference_no, notes, created_by)
                   VALUES
                    (:purchase_id, :company_id, :garage_id, :payment_date, "PAYMENT", :amount, "ADJUSTMENT", :reference_no, :notes, :created_by)'
                );
                $paymentStmt->execute([
                  'purchase_id' => $purchaseId,
                  'company_id' => $companyId,
                  'garage_id' => $activeGarageId,
                  'payment_date' => $purchaseDate,
                  'amount' => $totals['grand'],
                  'reference_no' => 'ENTRY-' . $purchaseId,
                  'notes' => 'Payment captured at purchase entry.',
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
            $purchaseLockSql =
                'SELECT p.id, p.assignment_status, p.purchase_status, p.notes, p.grand_total
                 FROM purchases p
                 WHERE p.id = :id
                   AND p.company_id = :company_id
                   AND p.garage_id = :garage_id'
                . pur_deleted_scope_sql('p') . '
                 FOR UPDATE';
            $purchaseLockStmt = $pdo->prepare($purchaseLockSql);
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

            if ($purchasePaymentsReady && $paymentStatus === 'PAID') {
              $grandTotal = round((float) ($purchase['grand_total'] ?? 0), 2);
              if ($grandTotal > 0) {
                $paymentStmt = $pdo->prepare(
                  'INSERT INTO purchase_payments
                    (purchase_id, company_id, garage_id, payment_date, entry_type, amount, payment_mode, reference_no, notes, created_by)
                   VALUES
                    (:purchase_id, :company_id, :garage_id, :payment_date, "PAYMENT", :amount, "ADJUSTMENT", :reference_no, :notes, :created_by)'
                );
                $paymentStmt->execute([
                  'purchase_id' => $purchaseId,
                  'company_id' => $companyId,
                  'garage_id' => $activeGarageId,
                  'payment_date' => $purchaseDate,
                  'amount' => $grandTotal,
                  'reference_no' => 'ASSIGN-' . $purchaseId,
                  'notes' => 'Payment captured during assignment.',
                  'created_by' => $userId,
                ]);
              }
            }

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
            $purchaseSql =
                'SELECT p.id, p.vendor_id, p.invoice_number, p.assignment_status, p.purchase_status
                 FROM purchases p
                 WHERE p.id = :id
                   AND p.company_id = :company_id
                   AND p.garage_id = :garage_id'
                . pur_deleted_scope_sql('p') . '
                 FOR UPDATE';
            $purchaseStmt = $pdo->prepare($purchaseSql);
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

    if ($action === 'edit_purchase') {
        if (!$canManage) {
            flash_set('purchase_error', 'You do not have permission to edit purchases.', 'danger');
            redirect('modules/purchases/index.php');
        }

        $purchaseId = post_int('purchase_id');
        $vendorId = post_int('vendor_id');
        $invoiceNumber = post_string('invoice_number', 80);
        $purchaseDate = trim((string) ($_POST['purchase_date'] ?? date('Y-m-d')));
        $notes = post_string('notes', 255);
        $editReason = post_string('edit_reason', 255);

        if ($purchaseId <= 0 || $editReason === '') {
            flash_set('purchase_error', 'Purchase and audit reason are required for edit.', 'danger');
            redirect('modules/purchases/index.php');
        }
        if (!pur_is_valid_date($purchaseDate)) {
            flash_set('purchase_error', 'Invalid purchase date.', 'danger');
            redirect('modules/purchases/index.php?edit_purchase_id=' . $purchaseId);
        }

        $partInputs = $_POST['item_part_id'] ?? [];
        $qtyInputs = $_POST['item_quantity'] ?? [];
        $costInputs = $_POST['item_unit_cost'] ?? [];
        $gstInputs = $_POST['item_gst_rate'] ?? [];
        if (!is_array($partInputs) || !is_array($qtyInputs) || !is_array($costInputs) || !is_array($gstInputs)) {
            flash_set('purchase_error', 'Invalid edit payload for item rows.', 'danger');
            redirect('modules/purchases/index.php?edit_purchase_id=' . $purchaseId);
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
                flash_set('purchase_error', 'Each edited item row must include part and quantity greater than zero.', 'danger');
                redirect('modules/purchases/index.php?edit_purchase_id=' . $purchaseId);
            }
            if ($unitCost < 0 || $gstRate < 0 || $gstRate > 100) {
                flash_set('purchase_error', 'Edited unit cost/GST values are out of range.', 'danger');
                redirect('modules/purchases/index.php?edit_purchase_id=' . $purchaseId);
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
            flash_set('purchase_error', 'At least one valid item row is required for edit.', 'danger');
            redirect('modules/purchases/index.php?edit_purchase_id=' . $purchaseId);
        }

        $partIds = array_values(array_unique(array_map(static fn (array $item): int => (int) $item['part_id'], $lineItems)));
        $partMap = [];
        $partPlaceholder = implode(',', array_fill(0, count($partIds), '?'));
        $partCheckStmt = db()->prepare(
            "SELECT id, part_name, part_sku, unit
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
                redirect('modules/purchases/index.php?edit_purchase_id=' . $purchaseId);
            }
        }
        foreach ($lineItems as $item) {
            $partId = (int) ($item['part_id'] ?? 0);
            $partRow = $partMap[$partId] ?? null;
            if (!is_array($partRow)) {
                continue;
            }

            $partName = trim((string) ($partRow['part_name'] ?? 'Part #' . $partId));
            $partUnitCode = part_unit_normalize_code((string) ($partRow['unit'] ?? ''));
            if ($partUnitCode === '') {
                $partUnitCode = 'PCS';
            }
            if (!part_unit_allows_decimal($companyId, $partUnitCode) && pur_value_has_fraction((float) ($item['quantity'] ?? 0))) {
                flash_set('purchase_error', 'Part "' . $partName . '" uses unit ' . $partUnitCode . ' and allows only whole quantity.', 'danger');
                redirect('modules/purchases/index.php?edit_purchase_id=' . $purchaseId);
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
            $purchase = pur_fetch_purchase_for_update($pdo, $purchaseId, $companyId, $activeGarageId);
            if (!$purchase) {
                throw new RuntimeException('Purchase not found for this garage.');
            }
            if (pur_purchase_is_deleted($purchase)) {
                throw new RuntimeException('Deleted purchase entries cannot be edited.');
            }

            $paymentSummary = reversal_purchase_payment_summary($pdo, $purchaseId);
            if ((int) ($paymentSummary['unreversed_count'] ?? 0) > 0) {
                throw new RuntimeException(reversal_chain_message(
                    'Purchase edit blocked. Payment entries exist.',
                    ['Reverse purchase payments first, then retry edit.']
                ));
            }

            $assignmentStatus = strtoupper((string) ($purchase['assignment_status'] ?? 'ASSIGNED'));
            if ($assignmentStatus !== 'UNASSIGNED') {
                if ($vendorId <= 0) {
                    throw new RuntimeException('Vendor is required for assigned purchases.');
                }
                if ($invoiceNumber === '') {
                    throw new RuntimeException('Invoice number is required for assigned purchases.');
                }
            } else {
                $vendorId = max(0, $vendorId);
            }

            if ($vendorId > 0) {
                $vendorStmt = $pdo->prepare(
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
                    throw new RuntimeException('Selected vendor is invalid for this company.');
                }
            }

            $existingItemsStmt = $pdo->prepare(
                'SELECT part_id, quantity
                 FROM purchase_items
                 WHERE purchase_id = :purchase_id
                 FOR UPDATE'
            );
            $existingItemsStmt->execute(['purchase_id' => $purchaseId]);
            $existingItems = $existingItemsStmt->fetchAll();

            $oldPartQty = [];
            foreach ($existingItems as $row) {
                $partId = (int) ($row['part_id'] ?? 0);
                if ($partId <= 0) {
                    continue;
                }
                $oldPartQty[$partId] = round(($oldPartQty[$partId] ?? 0) + (float) ($row['quantity'] ?? 0), 2);
            }

            $newPartQty = [];
            foreach ($lineItems as $item) {
                $partId = (int) ($item['part_id'] ?? 0);
                if ($partId <= 0) {
                    continue;
                }
                $newPartQty[$partId] = round(($newPartQty[$partId] ?? 0) + (float) ($item['quantity'] ?? 0), 2);
            }

            $stockUpdateStmt = $pdo->prepare(
                'UPDATE garage_inventory
                 SET quantity = :quantity
                 WHERE garage_id = :garage_id
                   AND part_id = :part_id'
            );

            $stockDeltas = [];
            $partIds = array_values(array_unique(array_merge(array_keys($oldPartQty), array_keys($newPartQty))));
            foreach ($partIds as $partId) {
                $oldQty = round((float) ($oldPartQty[$partId] ?? 0), 2);
                $newQty = round((float) ($newPartQty[$partId] ?? 0), 2);
                $delta = round($newQty - $oldQty, 2);
                if (abs($delta) <= 0.00001) {
                    continue;
                }

                $currentQty = pur_lock_inventory_row($pdo, $activeGarageId, (int) $partId);
                $nextQty = round($currentQty + $delta, 2);
                if ($nextQty < -0.009) {
                    throw new RuntimeException('Purchase edit blocked. Part #' . (int) $partId . ' stock is already consumed. Reverse downstream stock usage first.');
                }

                $stockDeltas[] = [
                    'part_id' => (int) $partId,
                    'delta' => $delta,
                    'next_qty' => $nextQty,
                ];
            }

            foreach ($stockDeltas as $stockDelta) {
                $partId = (int) $stockDelta['part_id'];
                $delta = (float) $stockDelta['delta'];
                $stockUpdateStmt->execute([
                    'quantity' => round((float) $stockDelta['next_qty'], 2),
                    'garage_id' => $activeGarageId,
                    'part_id' => $partId,
                ]);

                pur_insert_movement($pdo, [
                    'company_id' => $companyId,
                    'garage_id' => $activeGarageId,
                    'part_id' => $partId,
                    'movement_type' => $delta >= 0 ? 'IN' : 'OUT',
                    'quantity' => abs($delta),
                    'reference_type' => 'PURCHASE',
                    'reference_id' => $purchaseId,
                    'movement_uid' => 'pur-edit-' . hash('sha256', $purchaseId . '|' . $partId . '|' . $delta . '|' . microtime(true)),
                    'notes' => 'Purchase #' . $purchaseId . ' edit adjustment.',
                    'created_by' => $userId,
                ]);
            }

            $deleteItemsStmt = $pdo->prepare('DELETE FROM purchase_items WHERE purchase_id = :purchase_id');
            $deleteItemsStmt->execute(['purchase_id' => $purchaseId]);

            $itemInsert = $pdo->prepare(
                'INSERT INTO purchase_items
                  (purchase_id, part_id, quantity, unit_cost, gst_rate, taxable_amount, gst_amount, total_amount)
                 VALUES
                  (:purchase_id, :part_id, :quantity, :unit_cost, :gst_rate, :taxable_amount, :gst_amount, :total_amount)'
            );
            foreach ($lineItems as $item) {
                $itemInsert->execute([
                    'purchase_id' => $purchaseId,
                    'part_id' => (int) $item['part_id'],
                    'quantity' => round((float) $item['quantity'], 2),
                    'unit_cost' => round((float) $item['unit_cost'], 2),
                    'gst_rate' => round((float) $item['gst_rate'], 2),
                    'taxable_amount' => round((float) $item['taxable_amount'], 2),
                    'gst_amount' => round((float) $item['gst_amount'], 2),
                    'total_amount' => round((float) $item['total_amount'], 2),
                ]);
            }

            $effectivePaid = max(0.0, round((float) ($purchase['total_paid'] ?? 0), 2));
            $nextPaymentStatus = pur_payment_status_from_amounts((float) $totals['grand'], $effectivePaid);

            $purchaseUpdateStmt = $pdo->prepare(
                'UPDATE purchases
                 SET vendor_id = :vendor_id,
                     invoice_number = :invoice_number,
                     purchase_date = :purchase_date,
                     payment_status = :payment_status,
                     taxable_amount = :taxable_amount,
                     gst_amount = :gst_amount,
                     grand_total = :grand_total,
                     notes = :notes
                 WHERE id = :id
                   AND company_id = :company_id
                   AND garage_id = :garage_id'
            );
            $purchaseUpdateStmt->execute([
                'vendor_id' => $vendorId > 0 ? $vendorId : null,
                'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
                'purchase_date' => $purchaseDate,
                'payment_status' => $nextPaymentStatus,
                'taxable_amount' => (float) $totals['taxable'],
                'gst_amount' => (float) $totals['gst'],
                'grand_total' => (float) $totals['grand'],
                'notes' => $notes !== '' ? $notes : null,
                'id' => $purchaseId,
                'company_id' => $companyId,
                'garage_id' => $activeGarageId,
            ]);

            $pdo->commit();
            log_audit('purchases', 'update', $purchaseId, 'Edited purchase #' . $purchaseId, [
                'entity' => 'purchase',
                'source' => 'UI',
                'before' => [
                    'vendor_id' => (int) ($purchase['vendor_id'] ?? 0),
                    'invoice_number' => (string) ($purchase['invoice_number'] ?? ''),
                    'purchase_date' => (string) ($purchase['purchase_date'] ?? ''),
                    'payment_status' => (string) ($purchase['payment_status'] ?? ''),
                    'taxable_amount' => (float) ($purchase['taxable_amount'] ?? 0),
                    'gst_amount' => (float) ($purchase['gst_amount'] ?? 0),
                    'grand_total' => (float) ($purchase['grand_total'] ?? 0),
                ],
                'after' => [
                    'vendor_id' => $vendorId,
                    'invoice_number' => $invoiceNumber,
                    'purchase_date' => $purchaseDate,
                    'payment_status' => $nextPaymentStatus,
                    'taxable_amount' => (float) $totals['taxable'],
                    'gst_amount' => (float) $totals['gst'],
                    'grand_total' => (float) $totals['grand'],
                ],
                'metadata' => [
                    'reason' => $editReason,
                    'item_count' => count($lineItems),
                ],
            ]);
            flash_set('purchase_success', 'Purchase #' . $purchaseId . ' updated successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('purchase_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/purchases/index.php?edit_purchase_id=' . $purchaseId);
    }

    if ($action === 'delete_purchase') {
        if (!$canDeletePurchases) {
            flash_set('purchase_error', 'You do not have permission to delete purchases.', 'danger');
            redirect('modules/purchases/index.php');
        }
        if (!pur_supports_soft_delete()) {
            flash_set('purchase_error', 'Soft-delete columns are missing. Run database/reversal_integrity_hardening.sql first.', 'danger');
            redirect('modules/purchases/index.php');
        }

        $purchaseId = post_int('purchase_id');
        $deleteReason = post_string('delete_reason', 255);
        if ($purchaseId <= 0 || $deleteReason === '') {
            flash_set('purchase_error', 'Purchase and delete reason are required.', 'danger');
            redirect('modules/purchases/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $purchase = pur_fetch_purchase_for_update($pdo, $purchaseId, $companyId, $activeGarageId);
            if (!$purchase) {
                throw new RuntimeException('Purchase not found for this garage.');
            }
            if (pur_purchase_is_deleted($purchase)) {
                throw new RuntimeException('Purchase is already deleted.');
            }

            $dependencyReport = reversal_purchase_delete_dependency_report($pdo, $purchaseId, $companyId, $activeGarageId);
            if (!(bool) ($dependencyReport['can_delete'] ?? false)) {
                $blockers = array_values(array_filter(array_map('trim', (array) ($dependencyReport['blockers'] ?? []))));
                $steps = array_values(array_filter(array_map('trim', (array) ($dependencyReport['steps'] ?? []))));
                $intro = 'Purchase deletion blocked.';
                if ($blockers !== []) {
                    $intro .= ' ' . implode(' ', $blockers);
                }
                throw new RuntimeException(reversal_chain_message($intro, $steps));
            }

            $itemQtyStmt = $pdo->prepare(
                'SELECT part_id, COALESCE(SUM(quantity), 0) AS total_qty
                 FROM purchase_items
                 WHERE purchase_id = :purchase_id
                 GROUP BY part_id
                 FOR UPDATE'
            );
            $itemQtyStmt->execute(['purchase_id' => $purchaseId]);
            $itemRows = $itemQtyStmt->fetchAll();

            $stockUpdateStmt = $pdo->prepare(
                'UPDATE garage_inventory
                 SET quantity = :quantity
                 WHERE garage_id = :garage_id
                   AND part_id = :part_id'
            );

            $stockAdjustments = [];
            foreach ($itemRows as $itemRow) {
                $partId = (int) ($itemRow['part_id'] ?? 0);
                $qty = round((float) ($itemRow['total_qty'] ?? 0), 2);
                if ($partId <= 0 || $qty <= 0.00001) {
                    continue;
                }

                $currentQty = pur_lock_inventory_row($pdo, $activeGarageId, $partId);
                $nextQty = round($currentQty - $qty, 2);
                if ($nextQty < -0.009) {
                    throw new RuntimeException(reversal_chain_message(
                        'Purchase deletion blocked. Stock from this purchase is already consumed downstream.',
                        ['Reverse downstream stock-out transactions first, then retry delete.']
                    ));
                }

                $stockAdjustments[] = [
                    'part_id' => $partId,
                    'quantity' => $qty,
                    'next_qty' => $nextQty,
                ];
            }

            foreach ($stockAdjustments as $stockAdjustment) {
                $partId = (int) $stockAdjustment['part_id'];
                $qty = round((float) $stockAdjustment['quantity'], 2);
                $stockUpdateStmt->execute([
                    'quantity' => round((float) $stockAdjustment['next_qty'], 2),
                    'garage_id' => $activeGarageId,
                    'part_id' => $partId,
                ]);

                pur_insert_movement($pdo, [
                    'company_id' => $companyId,
                    'garage_id' => $activeGarageId,
                    'part_id' => $partId,
                    'movement_type' => 'OUT',
                    'quantity' => $qty,
                    'reference_type' => 'PURCHASE',
                    'reference_id' => $purchaseId,
                    'movement_uid' => 'pur-del-' . hash('sha256', $purchaseId . '|' . $partId . '|' . $qty . '|' . microtime(true)),
                    'notes' => 'Purchase #' . $purchaseId . ' delete stock reversal. Reason: ' . $deleteReason,
                    'created_by' => $userId,
                ]);
            }

            $deleteStmt = $pdo->prepare(
                'UPDATE purchases
                 SET status_code = "DELETED",
                     deleted_at = NOW(),
                     deleted_by = :deleted_by,
                     delete_reason = :delete_reason
                 WHERE id = :id
                   AND company_id = :company_id
                   AND garage_id = :garage_id'
            );
            $deleteStmt->execute([
                'deleted_by' => $userId > 0 ? $userId : null,
                'delete_reason' => $deleteReason,
                'id' => $purchaseId,
                'company_id' => $companyId,
                'garage_id' => $activeGarageId,
            ]);

            $pdo->commit();
            log_audit('purchases', 'soft_delete', $purchaseId, 'Soft deleted purchase #' . $purchaseId, [
                'entity' => 'purchase',
                'source' => 'UI',
                'before' => [
                    'status_code' => (string) ($purchase['status_code'] ?? 'ACTIVE'),
                    'payment_status' => (string) ($purchase['payment_status'] ?? ''),
                    'grand_total' => (float) ($purchase['grand_total'] ?? 0),
                ],
                'after' => [
                    'status_code' => 'DELETED',
                    'delete_reason' => $deleteReason,
                ],
                'metadata' => [
                    'stock_reverse_lines' => count($stockAdjustments),
                ],
            ]);
            flash_set('purchase_success', 'Purchase #' . $purchaseId . ' soft deleted with stock reversal.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('purchase_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/purchases/index.php');
    }

      if ($action === 'add_payment') {
        if (!$canPayPurchases) {
          flash_set('purchase_error', 'You do not have permission to record purchase payments.', 'danger');
          redirect('modules/purchases/index.php');
        }
        if (!$purchasePaymentsReady) {
          flash_set('purchase_error', 'Purchase payment tables are missing. Run database/financial_compliance_upgrade.sql first.', 'danger');
          redirect('modules/purchases/index.php');
        }

        $purchaseId = post_int('purchase_id');
        $paymentDate = trim((string) ($_POST['payment_date'] ?? date('Y-m-d')));
        $amount = round(max(0, pur_decimal($_POST['amount'] ?? 0)), 2);
        $paymentMode = strtoupper(trim((string) ($_POST['payment_mode'] ?? 'BANK_TRANSFER')));
        $referenceNo = post_string('reference_no', 100);
        $notes = post_string('notes', 255);

        if ($purchaseId <= 0) {
          flash_set('purchase_error', 'Invalid purchase selected for payment.', 'danger');
          redirect('modules/purchases/index.php');
        }
        if (!pur_is_valid_date($paymentDate)) {
          flash_set('purchase_error', 'Invalid payment date.', 'danger');
          redirect('modules/purchases/index.php');
        }
        if ($amount <= 0) {
          flash_set('purchase_error', 'Payment amount must be greater than zero.', 'danger');
          redirect('modules/purchases/index.php');
        }
        if (!in_array($paymentMode, pur_payment_modes(), true)) {
          flash_set('purchase_error', 'Invalid payment mode selected.', 'danger');
          redirect('modules/purchases/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
          $purchase = pur_fetch_purchase_for_update($pdo, $purchaseId, $companyId, $activeGarageId);
          if (!$purchase) {
            throw new RuntimeException('Purchase not found for this garage.');
          }
          if ((string) ($purchase['purchase_status'] ?? '') !== 'FINALIZED') {
            throw new RuntimeException('Only finalized purchases can be paid.');
          }

          $grandTotal = round((float) ($purchase['grand_total'] ?? 0), 2);
          $paidAmount = round((float) ($purchase['total_paid'] ?? 0), 2);
          $outstanding = round(max(0, $grandTotal - $paidAmount), 2);
          if ($outstanding <= 0.009) {
            throw new RuntimeException('No outstanding balance left for this purchase.');
          }
          if ($amount > $outstanding + 0.009) {
            throw new RuntimeException('Payment amount exceeds outstanding balance.');
          }

          $insertStmt = $pdo->prepare(
            'INSERT INTO purchase_payments
              (purchase_id, company_id, garage_id, payment_date, entry_type, amount, payment_mode, reference_no, notes, created_by)
             VALUES
              (:purchase_id, :company_id, :garage_id, :payment_date, "PAYMENT", :amount, :payment_mode, :reference_no, :notes, :created_by)'
          );
          $insertStmt->execute([
            'purchase_id' => $purchaseId,
            'company_id' => $companyId,
            'garage_id' => $activeGarageId,
            'payment_date' => $paymentDate,
            'amount' => $amount,
            'payment_mode' => $paymentMode,
            'reference_no' => $referenceNo !== '' ? $referenceNo : null,
            'notes' => $notes !== '' ? $notes : null,
            'created_by' => $userId > 0 ? $userId : null,
          ]);
          $paymentId = (int) $pdo->lastInsertId();

          $newPaid = round($paidAmount + $amount, 2);
          $nextStatus = pur_payment_status_from_amounts($grandTotal, $newPaid);

          $updateStmt = $pdo->prepare(
            'UPDATE purchases
             SET payment_status = :payment_status
             WHERE id = :id'
          );
          $updateStmt->execute([
            'payment_status' => $nextStatus,
            'id' => $purchaseId,
          ]);

          finance_record_expense_for_purchase_payment(
            $paymentId,
            $purchaseId,
            $companyId,
            $activeGarageId,
            $amount,
            $paymentDate,
            $paymentMode,
            $notes,
            false,
            $userId
          );

          $pdo->commit();
          log_audit('purchases', 'payment_add', $purchaseId, 'Recorded purchase payment #' . $paymentId, [
            'entity' => 'purchase_payment',
            'source' => 'UI',
            'after' => [
              'purchase_id' => $purchaseId,
              'payment_id' => $paymentId,
              'amount' => $amount,
              'payment_mode' => $paymentMode,
            ],
          ]);
          flash_set('purchase_success', 'Payment recorded successfully.', 'success');
        } catch (Throwable $exception) {
          $pdo->rollBack();
          flash_set('purchase_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/purchases/index.php?pay_purchase_id=' . (int) $purchaseId);
      }

      if ($action === 'reverse_payment') {
        if (!$canPayPurchases) {
          flash_set('purchase_error', 'You do not have permission to reverse purchase payments.', 'danger');
          redirect('modules/purchases/index.php');
        }
        if (!$purchasePaymentsReady) {
          flash_set('purchase_error', 'Purchase payment tables are missing. Run database/financial_compliance_upgrade.sql first.', 'danger');
          redirect('modules/purchases/index.php');
        }

        $paymentId = post_int('payment_id');
        $reverseReason = post_string('reverse_reason', 255);
        if ($paymentId <= 0 || $reverseReason === '') {
          flash_set('purchase_error', 'Payment and reversal reason are required.', 'danger');
          redirect('modules/purchases/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
          $paymentStmt = $pdo->prepare(
            'SELECT pp.*, p.grand_total, p.id AS purchase_id,
                COALESCE(pay.total_paid, 0) AS total_paid
             FROM purchase_payments pp
             INNER JOIN purchases p ON p.id = pp.purchase_id
             LEFT JOIN (
              SELECT purchase_id, SUM(amount) AS total_paid
              FROM purchase_payments
              GROUP BY purchase_id
             ) pay ON pay.purchase_id = p.id
             WHERE pp.id = :id
               AND p.company_id = :company_id
               AND p.garage_id = :garage_id
             LIMIT 1
             FOR UPDATE'
          );
          $paymentStmt->execute([
            'id' => $paymentId,
            'company_id' => $companyId,
            'garage_id' => $activeGarageId,
          ]);
          $payment = $paymentStmt->fetch();
          if (!$payment) {
            throw new RuntimeException('Payment entry not found.');
          }
          if ((string) ($payment['entry_type'] ?? '') !== 'PAYMENT') {
            throw new RuntimeException('Only payment entries can be reversed.');
          }
          if ((float) ($payment['amount'] ?? 0) <= 0) {
            throw new RuntimeException('Invalid payment amount for reversal.');
          }

          $checkStmt = $pdo->prepare(
            'SELECT id
             FROM purchase_payments
             WHERE reversed_payment_id = :payment_id
             LIMIT 1'
          );
          $checkStmt->execute(['payment_id' => $paymentId]);
          if ($checkStmt->fetch()) {
            throw new RuntimeException('This payment has already been reversed.');
          }

          $purchaseId = (int) ($payment['purchase_id'] ?? 0);
          $paymentAmount = round((float) ($payment['amount'] ?? 0), 2);
          $reversalDate = date('Y-m-d');
          $insertStmt = $pdo->prepare(
            'INSERT INTO purchase_payments
              (purchase_id, company_id, garage_id, payment_date, entry_type, amount, payment_mode, reference_no, notes, reversed_payment_id, created_by)
             VALUES
              (:purchase_id, :company_id, :garage_id, :payment_date, "REVERSAL", :amount, "ADJUSTMENT", :reference_no, :notes, :reversed_payment_id, :created_by)'
          );
          $insertStmt->execute([
            'purchase_id' => $purchaseId,
            'company_id' => $companyId,
            'garage_id' => $activeGarageId,
            'payment_date' => $reversalDate,
            'amount' => -$paymentAmount,
            'reference_no' => 'REV-' . $paymentId,
            'notes' => $reverseReason,
            'reversed_payment_id' => $paymentId,
            'created_by' => $userId > 0 ? $userId : null,
          ]);
          $reversalId = (int) $pdo->lastInsertId();

          $purchase = pur_fetch_purchase_for_update($pdo, $purchaseId, $companyId, $activeGarageId);
          if (!$purchase) {
            throw new RuntimeException('Purchase not found after reversal.');
          }

          $grandTotal = round((float) ($purchase['grand_total'] ?? 0), 2);
          $newPaid = round((float) ($purchase['total_paid'] ?? 0), 2);
          $nextStatus = pur_payment_status_from_amounts($grandTotal, $newPaid);

          $updateStmt = $pdo->prepare(
            'UPDATE purchases
             SET payment_status = :payment_status
             WHERE id = :id'
          );
          $updateStmt->execute([
            'payment_status' => $nextStatus,
            'id' => $purchaseId,
          ]);

          finance_record_expense_for_purchase_payment(
            $reversalId,
            $purchaseId,
            $companyId,
            $activeGarageId,
            $paymentAmount,
            $reversalDate,
            'ADJUSTMENT',
            $reverseReason,
            true,
            $userId
          );

          $pdo->commit();
          log_audit('purchases', 'payment_reverse', $purchaseId, 'Reversed purchase payment #' . $paymentId, [
            'entity' => 'purchase_payment',
            'source' => 'UI',
            'after' => [
              'purchase_id' => $purchaseId,
              'payment_id' => $paymentId,
              'reversal_id' => $reversalId,
              'reversal_amount' => -$paymentAmount,
            ],
          ]);
          flash_set('purchase_success', 'Payment reversed successfully.', 'success');
        } catch (Throwable $exception) {
          $pdo->rollBack();
          flash_set('purchase_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/purchases/index.php?pay_purchase_id=' . (int) post_int('purchase_id'));
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
$today = date('Y-m-d');
$purchaseRangeStart = date('Y-m-01', strtotime($today));
$purchaseRangeEnd = $today;
if ($purchasesReady) {
    $boundsStmt = db()->prepare(
        'SELECT MIN(purchase_date) AS min_date, MAX(purchase_date) AS max_date
         FROM purchases
         WHERE company_id = :company_id
           AND garage_id = :garage_id'
    );
    $boundsStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $activeGarageId,
    ]);
    $dateBounds = $boundsStmt->fetch() ?: [];
    if (date_filter_is_valid_iso((string) ($dateBounds['min_date'] ?? ''))) {
        $purchaseRangeStart = (string) $dateBounds['min_date'];
    }
    if (date_filter_is_valid_iso((string) ($dateBounds['max_date'] ?? ''))) {
        $maxDate = (string) $dateBounds['max_date'];
        $purchaseRangeEnd = $maxDate > $today ? $maxDate : $today;
    }
}
if ($purchaseRangeEnd < $purchaseRangeStart) {
    $purchaseRangeStart = $purchaseRangeEnd;
}
$purchaseDateFilter = date_filter_resolve_request([
    'company_id' => $companyId,
    'garage_id' => $activeGarageId,
    'range_start' => $purchaseRangeStart,
    'range_end' => $purchaseRangeEnd,
    'yearly_start' => date('Y-01-01', strtotime($purchaseRangeEnd)),
    'session_namespace' => 'purchases_index',
    'request_mode' => $_GET['date_mode'] ?? null,
    'request_from' => $_GET['from'] ?? null,
    'request_to' => $_GET['to'] ?? null,
]);
$dateMode = (string) ($purchaseDateFilter['mode'] ?? 'monthly');
$dateModeOptions = date_filter_modes();
$fromDate = (string) ($purchaseDateFilter['from_date'] ?? $purchaseRangeStart);
$toDate = (string) ($purchaseDateFilter['to_date'] ?? $purchaseRangeEnd);
$assignPurchaseId = get_int('assign_purchase_id', 0);
$payPurchaseId = get_int('pay_purchase_id', 0);
$editPurchaseId = get_int('edit_purchase_id', 0);

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

$purchaseDeletedScopeSql = pur_supports_soft_delete() ? ' AND p.status_code <> "DELETED"' : '';

$purchaseWhere = ['p.company_id = :company_id', 'p.garage_id = :garage_id'];
$purchaseParams = [
    'company_id' => $companyId,
    'garage_id' => $activeGarageId,
];
if (pur_supports_soft_delete()) {
    $purchaseWhere[] = 'p.status_code <> "DELETED"';
}

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
$purchaseWhere[] = 'p.purchase_date >= :from_date';
$purchaseParams['from_date'] = $fromDate;
$purchaseWhere[] = 'p.purchase_date <= :to_date';
$purchaseParams['to_date'] = $toDate;

$purchases = [];
$summary = [
    'purchase_count' => 0,
    'unassigned_count' => 0,
    'finalized_count' => 0,
    'taxable_total' => 0,
    'gst_total' => 0,
    'grand_total' => 0,
  'paid_total' => 0,
  'outstanding_total' => 0,
];
$topParts = [];
$unassignedRows = [];
$vendorOutstandingRows = [];
$agingSummary = [
  'bucket_0_30' => 0,
  'bucket_31_60' => 0,
  'bucket_61_90' => 0,
  'bucket_90_plus' => 0,
  'outstanding_total' => 0,
];
$paidUnpaidSummary = [
  'paid_count' => 0,
  'partial_count' => 0,
  'unpaid_count' => 0,
  'paid_total' => 0,
  'partial_total' => 0,
  'unpaid_total' => 0,
];

if ($purchasesReady) {
  if ($purchasePaymentsReady) {
    $summaryStmt = db()->prepare(
      'SELECT
        COUNT(*) AS purchase_count,
        COALESCE(SUM(CASE WHEN p.assignment_status = "UNASSIGNED" THEN 1 ELSE 0 END), 0) AS unassigned_count,
        COALESCE(SUM(CASE WHEN p.purchase_status = "FINALIZED" THEN 1 ELSE 0 END), 0) AS finalized_count,
        COALESCE(SUM(p.taxable_amount), 0) AS taxable_total,
        COALESCE(SUM(p.gst_amount), 0) AS gst_total,
        COALESCE(SUM(p.grand_total), 0) AS grand_total,
        COALESCE(SUM(COALESCE(pay.total_paid, 0)), 0) AS paid_total,
        COALESCE(SUM(GREATEST(p.grand_total - COALESCE(pay.total_paid, 0), 0)), 0) AS outstanding_total
       FROM purchases p
       LEFT JOIN (
        SELECT purchase_id, SUM(amount) AS total_paid
        FROM purchase_payments
        GROUP BY purchase_id
       ) pay ON pay.purchase_id = p.id
       WHERE ' . implode(' AND ', $purchaseWhere)
    );
    $summaryStmt->execute($purchaseParams);
    $summary = $summaryStmt->fetch() ?: $summary;
  } else {
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
  }

     $listSql =
        'SELECT p.*,
             v.vendor_name,
             u.name AS created_by_name,
             fu.name AS finalized_by_name,
             COALESCE(items.item_count, 0) AS item_count,
             COALESCE(items.total_qty, 0) AS total_qty';

     if ($purchasePaymentsReady) {
        $listSql .= ',
             COALESCE(pay.total_paid, 0) AS paid_total,
             COALESCE(pay.payment_count, 0) AS payment_count,
             GREATEST(p.grand_total - COALESCE(pay.total_paid, 0), 0) AS outstanding_total';
     }

     $listSql .= '
        FROM purchases p
        LEFT JOIN vendors v ON v.id = p.vendor_id
        LEFT JOIN users u ON u.id = p.created_by
        LEFT JOIN users fu ON fu.id = p.finalized_by
        LEFT JOIN (
          SELECT purchase_id, COUNT(*) AS item_count, COALESCE(SUM(quantity), 0) AS total_qty
          FROM purchase_items
          GROUP BY purchase_id
        ) items ON items.purchase_id = p.id';

     if ($purchasePaymentsReady) {
        $listSql .= '
        LEFT JOIN (
          SELECT purchase_id, SUM(amount) AS total_paid, COUNT(*) AS payment_count
          FROM purchase_payments
          GROUP BY purchase_id
        ) pay ON pay.purchase_id = p.id';
     }

     $listSql .= '
        WHERE ' . implode(' AND ', $purchaseWhere) . '
        ORDER BY p.id DESC
        LIMIT 200';

     $listStmt = db()->prepare($listSql);
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
           ' . $purchaseDeletedScopeSql . '
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

    $payPurchase = null;
    $paymentHistory = [];
    if ($purchasePaymentsReady && $payPurchaseId > 0) {
      $payStmt = db()->prepare(
        'SELECT p.*, v.vendor_name,
            COALESCE(pay.total_paid, 0) AS total_paid,
            GREATEST(p.grand_total - COALESCE(pay.total_paid, 0), 0) AS outstanding_total
         FROM purchases p
         LEFT JOIN vendors v ON v.id = p.vendor_id
         LEFT JOIN (
          SELECT purchase_id, SUM(amount) AS total_paid
          FROM purchase_payments
          GROUP BY purchase_id
         ) pay ON pay.purchase_id = p.id
         WHERE p.id = :id
           AND p.company_id = :company_id
           AND p.garage_id = :garage_id
           ' . $purchaseDeletedScopeSql . '
         LIMIT 1'
      );
      $payStmt->execute([
        'id' => $payPurchaseId,
        'company_id' => $companyId,
        'garage_id' => $activeGarageId,
      ]);
      $payPurchase = $payStmt->fetch() ?: null;

      if ($payPurchase) {
        $historyStmt = db()->prepare(
          'SELECT pp.id, pp.payment_date, pp.entry_type, pp.amount, pp.payment_mode, pp.reference_no, pp.notes, pp.reversed_payment_id,
            u.name AS created_by_name,
            r.id AS reversal_id
           FROM purchase_payments pp
           LEFT JOIN users u ON u.id = pp.created_by
           LEFT JOIN purchase_payments r ON r.reversed_payment_id = pp.id
           WHERE pp.purchase_id = :purchase_id
           ORDER BY pp.id DESC'
        );
        $historyStmt->execute(['purchase_id' => (int) $payPurchase['id']]);
        $paymentHistory = $historyStmt->fetchAll();
      }
    }

    if ($purchasePaymentsReady && $canViewVendorPayables) {
      $payableWhere = [
        'p.company_id = :company_id',
        'p.garage_id = :garage_id',
        'p.purchase_status = "FINALIZED"',
        'p.assignment_status = "ASSIGNED"',
      ];
      if (pur_supports_soft_delete()) {
        $payableWhere[] = 'p.status_code <> "DELETED"';
      }
      $payableParams = [
        'company_id' => $companyId,
        'garage_id' => $activeGarageId,
      ];
      if ($vendorFilter > 0) {
        $payableWhere[] = 'p.vendor_id = :vendor_id';
        $payableParams['vendor_id'] = $vendorFilter;
      }
      if ($fromDate !== '') {
        $payableWhere[] = 'p.purchase_date >= :from_date';
        $payableParams['from_date'] = $fromDate;
      }
      if ($toDate !== '') {
        $payableWhere[] = 'p.purchase_date <= :to_date';
        $payableParams['to_date'] = $toDate;
      }

      $vendorStmt = db()->prepare(
        'SELECT v.id AS vendor_id,
            COALESCE(v.vendor_name, "UNASSIGNED") AS vendor_name,
            COALESCE(v.vendor_code, "-") AS vendor_code,
            COUNT(p.id) AS purchase_count,
            COALESCE(SUM(p.grand_total), 0) AS grand_total,
            COALESCE(SUM(COALESCE(pay.total_paid, 0)), 0) AS paid_total,
            COALESCE(SUM(GREATEST(p.grand_total - COALESCE(pay.total_paid, 0), 0)), 0) AS outstanding_total
         FROM purchases p
         LEFT JOIN vendors v ON v.id = p.vendor_id
         LEFT JOIN (
          SELECT purchase_id, SUM(amount) AS total_paid
          FROM purchase_payments
          GROUP BY purchase_id
         ) pay ON pay.purchase_id = p.id
         WHERE ' . implode(' AND ', $payableWhere) . '
         GROUP BY v.id, v.vendor_name, v.vendor_code
         HAVING outstanding_total > 0.01
         ORDER BY outstanding_total DESC'
      );
      $vendorStmt->execute($payableParams);
      $vendorOutstandingRows = $vendorStmt->fetchAll();

      $agingStmt = db()->prepare(
        'SELECT
          COALESCE(SUM(CASE WHEN age_days BETWEEN 0 AND 30 THEN outstanding ELSE 0 END), 0) AS bucket_0_30,
          COALESCE(SUM(CASE WHEN age_days BETWEEN 31 AND 60 THEN outstanding ELSE 0 END), 0) AS bucket_31_60,
          COALESCE(SUM(CASE WHEN age_days BETWEEN 61 AND 90 THEN outstanding ELSE 0 END), 0) AS bucket_61_90,
          COALESCE(SUM(CASE WHEN age_days > 90 THEN outstanding ELSE 0 END), 0) AS bucket_90_plus,
          COALESCE(SUM(outstanding), 0) AS outstanding_total
         FROM (
          SELECT p.id,
               DATEDIFF(CURDATE(), p.purchase_date) AS age_days,
               GREATEST(p.grand_total - COALESCE(pay.total_paid, 0), 0) AS outstanding
          FROM purchases p
          LEFT JOIN (
            SELECT purchase_id, SUM(amount) AS total_paid
            FROM purchase_payments
            GROUP BY purchase_id
          ) pay ON pay.purchase_id = p.id
          WHERE ' . implode(' AND ', $payableWhere) . '
         ) aged
         WHERE outstanding > 0.01'
      );
      $agingStmt->execute($payableParams);
      $agingSummary = $agingStmt->fetch() ?: $agingSummary;

      $paidUnpaidStmt = db()->prepare(
        'SELECT
          COALESCE(SUM(CASE WHEN paid_total + 0.009 >= grand_total THEN 1 ELSE 0 END), 0) AS paid_count,
          COALESCE(SUM(CASE WHEN paid_total > 0.009 AND paid_total + 0.009 < grand_total THEN 1 ELSE 0 END), 0) AS partial_count,
          COALESCE(SUM(CASE WHEN paid_total <= 0.009 THEN 1 ELSE 0 END), 0) AS unpaid_count,
          COALESCE(SUM(CASE WHEN paid_total + 0.009 >= grand_total THEN grand_total ELSE 0 END), 0) AS paid_total,
          COALESCE(SUM(CASE WHEN paid_total > 0.009 AND paid_total + 0.009 < grand_total THEN grand_total ELSE 0 END), 0) AS partial_total,
          COALESCE(SUM(CASE WHEN paid_total <= 0.009 THEN grand_total ELSE 0 END), 0) AS unpaid_total
         FROM (
          SELECT p.id, p.grand_total, COALESCE(pay.total_paid, 0) AS paid_total
          FROM purchases p
          LEFT JOIN (
            SELECT purchase_id, SUM(amount) AS total_paid
            FROM purchase_payments
            GROUP BY purchase_id
          ) pay ON pay.purchase_id = p.id
          WHERE ' . implode(' AND ', $payableWhere) . '
         ) summary'
      );
      $paidUnpaidStmt->execute($payableParams);
      $paidUnpaidSummary = $paidUnpaidStmt->fetch() ?: $paidUnpaidSummary;
    }

    $export = strtolower(trim((string) ($_GET['export'] ?? '')));
    if ($export !== '') {
      if (!$canExport) {
        flash_set('purchase_error', 'You do not have permission to export purchase reports.', 'danger');
        redirect('modules/purchases/index.php');
      }

      switch ($export) {
        case 'csv':
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
            'date_mode' => $dateMode,
            'vendor_id' => $vendorFilter > 0 ? $vendorFilter : null,
            'payment_status' => $paymentFilter !== '' ? $paymentFilter : null,
            'purchase_status' => $statusFilter !== '' ? $statusFilter : null,
            'assignment_status' => $assignmentFilter !== '' ? $assignmentFilter : null,
            'purchase_source' => $sourceFilter !== '' ? $sourceFilter : null,
            'invoice' => $invoiceFilter !== '' ? $invoiceFilter : null,
            'from' => $fromDate,
            'to' => $toDate,
          ]);

        case 'vendor_outstanding':
          if (!$purchasePaymentsReady || !$canViewVendorPayables) {
            flash_set('purchase_error', 'Vendor payable export is not available.', 'danger');
            redirect('modules/purchases/index.php');
          }
          $rows = array_map(static fn (array $row): array => [
            (string) ($row['vendor_name'] ?? ''),
            (string) ($row['vendor_code'] ?? ''),
            (int) ($row['purchase_count'] ?? 0),
            (float) ($row['grand_total'] ?? 0),
            (float) ($row['paid_total'] ?? 0),
            (float) ($row['outstanding_total'] ?? 0),
          ], $vendorOutstandingRows);
          pur_csv_download('purchase_vendor_outstanding_' . date('Ymd_His') . '.csv', ['Vendor', 'Code', 'Purchases', 'Total', 'Paid', 'Outstanding'], $rows, [
            'garage_id' => $activeGarageId,
            'date_mode' => $dateMode,
            'from' => $fromDate,
            'to' => $toDate,
          ]);

        case 'aging_summary':
          if (!$purchasePaymentsReady || !$canViewVendorPayables) {
            flash_set('purchase_error', 'Vendor aging export is not available.', 'danger');
            redirect('modules/purchases/index.php');
          }
          $rows = [[
            (float) ($agingSummary['bucket_0_30'] ?? 0),
            (float) ($agingSummary['bucket_31_60'] ?? 0),
            (float) ($agingSummary['bucket_61_90'] ?? 0),
            (float) ($agingSummary['bucket_90_plus'] ?? 0),
            (float) ($agingSummary['outstanding_total'] ?? 0),
          ]];
          pur_csv_download('purchase_aging_summary_' . date('Ymd_His') . '.csv', ['0-30 Days', '31-60 Days', '61-90 Days', '90+ Days', 'Total Outstanding'], $rows, [
            'garage_id' => $activeGarageId,
            'date_mode' => $dateMode,
            'from' => $fromDate,
            'to' => $toDate,
          ]);

        case 'paid_unpaid':
          if (!$purchasePaymentsReady || !$canViewVendorPayables) {
            flash_set('purchase_error', 'Paid vs unpaid export is not available.', 'danger');
            redirect('modules/purchases/index.php');
          }
          $rows = [[
            (int) ($paidUnpaidSummary['paid_count'] ?? 0),
            (int) ($paidUnpaidSummary['partial_count'] ?? 0),
            (int) ($paidUnpaidSummary['unpaid_count'] ?? 0),
            (float) ($paidUnpaidSummary['paid_total'] ?? 0),
            (float) ($paidUnpaidSummary['partial_total'] ?? 0),
            (float) ($paidUnpaidSummary['unpaid_total'] ?? 0),
          ]];
          pur_csv_download('purchase_paid_unpaid_' . date('Ymd_His') . '.csv', ['Paid Count', 'Partial Count', 'Unpaid Count', 'Paid Total', 'Partial Total', 'Unpaid Total'], $rows, [
            'garage_id' => $activeGarageId,
            'date_mode' => $dateMode,
            'from' => $fromDate,
            'to' => $toDate,
          ]);

        default:
          flash_set('purchase_error', 'Unknown export requested.', 'warning');
          redirect('modules/purchases/index.php');
      }
    }
}

$editPurchase = null;
$editPurchaseItems = [];
$editPurchasePaymentSummary = [
    'unreversed_count' => 0,
    'unreversed_amount' => 0.0,
    'net_paid_amount' => 0.0,
];
if ($purchasesReady && $canManage && $editPurchaseId > 0) {
    $editSql =
        'SELECT p.*,
                v.vendor_name,
                COALESCE(pay.total_paid, 0) AS total_paid,
                GREATEST(p.grand_total - COALESCE(pay.total_paid, 0), 0) AS outstanding_total
         FROM purchases p
         LEFT JOIN vendors v ON v.id = p.vendor_id
         LEFT JOIN (
            SELECT purchase_id, SUM(amount) AS total_paid
            FROM purchase_payments
            GROUP BY purchase_id
         ) pay ON pay.purchase_id = p.id
         WHERE p.id = :id
           AND p.company_id = :company_id
           AND p.garage_id = :garage_id'
        . $purchaseDeletedScopeSql . '
         LIMIT 1';
    $editStmt = db()->prepare($editSql);
    $editStmt->execute([
        'id' => $editPurchaseId,
        'company_id' => $companyId,
        'garage_id' => $activeGarageId,
    ]);
    $editPurchase = $editStmt->fetch() ?: null;

    if ($editPurchase) {
        $editPurchasePaymentSummary = reversal_purchase_payment_summary(db(), (int) ($editPurchase['id'] ?? 0));
        $editItemsStmt = db()->prepare(
            'SELECT pi.*, p.part_name, p.part_sku, p.unit, COALESCE(gi.quantity, 0) AS stock_qty
             FROM purchase_items pi
             INNER JOIN parts p ON p.id = pi.part_id
             LEFT JOIN garage_inventory gi ON gi.part_id = p.id AND gi.garage_id = :garage_id
             WHERE pi.purchase_id = :purchase_id
             ORDER BY pi.id ASC'
        );
        $editItemsStmt->execute([
            'purchase_id' => (int) $editPurchase['id'],
            'garage_id' => $activeGarageId,
        ]);
        $editPurchaseItems = $editItemsStmt->fetchAll();
    } else {
        flash_set('purchase_warning', 'Selected purchase not found for edit in this garage scope.', 'warning');
    }
}

$createToken = pur_issue_action_token('create_purchase');
$assignToken = pur_issue_action_token('assign_unassigned');
$finalizeToken = pur_issue_action_token('finalize_purchase');
$paymentModes = pur_payment_modes();

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
                                    <?php
                                      $partUnitCode = part_unit_normalize_code((string) ($part['unit'] ?? ''));
                                      if ($partUnitCode === '') {
                                          $partUnitCode = 'PCS';
                                      }
                                      $partAllowsDecimal = part_unit_allows_decimal($companyId, $partUnitCode);
                                      $partUnitLabel = part_unit_label($companyId, $partUnitCode);
                                    ?>
                                    <option
                                      value="<?= (int) $part['id']; ?>"
                                      data-default-cost="<?= e(number_format((float) ($part['purchase_price'] ?? 0), 2, '.', '')); ?>"
                                      data-default-gst="<?= e(number_format((float) ($part['gst_rate'] ?? 0), 2, '.', '')); ?>"
                                      data-stock="<?= e(number_format((float) ($part['stock_qty'] ?? 0), 2, '.', '')); ?>"
                                      data-stock-unit="<?= e($partUnitCode); ?>"
                                      data-unit="<?= e($partUnitCode); ?>"
                                      data-unit-label="<?= e($partUnitLabel); ?>"
                                      data-allow-decimal="<?= $partAllowsDecimal ? '1' : '0'; ?>"
                                    >
                                      <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>) | <?= e($partUnitCode); ?><?= $partAllowsDecimal ? ' Decimal' : ' Whole'; ?>
                                    </option>
                                  <?php endforeach; ?>
                                </select>
                              </td>
                              <td><input type="number" name="item_quantity[]" class="form-control form-control-sm" step="1" min="0"></td>
                              <td><input type="number" name="item_unit_cost[]" class="form-control form-control-sm js-item-cost" step="0.01" min="0"></td>
                              <td><input type="number" name="item_gst_rate[]" class="form-control form-control-sm js-item-gst" step="0.01" min="0"></td>
                              <td class="text-muted small js-item-stock">-</td>
                            </tr>
                          <?php endfor; ?>
                        </tbody>
                      </table>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="add-item-row">Add Item Row</button>
                    <div class="row g-2 mt-2" id="purchase-live-summary">
                      <div class="col-6 col-md-3">
                        <div class="border rounded p-2 bg-light-subtle">
                          <div class="text-muted small">Total Qty</div>
                          <div class="fw-semibold js-live-total-qty">0.00</div>
                        </div>
                      </div>
                      <div class="col-6 col-md-3">
                        <div class="border rounded p-2 bg-light-subtle">
                          <div class="text-muted small">Taxable Total</div>
                          <div class="fw-semibold js-live-total-taxable">0.00</div>
                        </div>
                      </div>
                      <div class="col-6 col-md-3">
                        <div class="border rounded p-2 bg-light-subtle">
                          <div class="text-muted small">GST Total</div>
                          <div class="fw-semibold js-live-total-gst">0.00</div>
                        </div>
                      </div>
                      <div class="col-6 col-md-3">
                        <div class="border rounded p-2 bg-light-subtle">
                          <div class="text-muted small">Grand Total</div>
                          <div class="fw-semibold js-live-total-grand">0.00</div>
                        </div>
                      </div>
                    </div>

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

        <?php if ($canManage && $editPurchase): ?>
          <?php
            $editBlockedByPayments = (int) ($editPurchasePaymentSummary['unreversed_count'] ?? 0) > 0;
            $editAssignmentStatus = strtoupper((string) ($editPurchase['assignment_status'] ?? 'ASSIGNED'));
            $editVendorRequired = $editAssignmentStatus !== 'UNASSIGNED';
          ?>
          <div class="card card-warning mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Edit Purchase #<?= (int) ($editPurchase['id'] ?? 0); ?></h3>
              <a href="<?= e(url('modules/purchases/index.php')); ?>" class="btn btn-sm btn-outline-secondary">Close Edit</a>
            </div>
            <form method="post">
              <div class="card-body">
                <?= csrf_field(); ?>
                <input type="hidden" name="_action" value="edit_purchase" />
                <input type="hidden" name="purchase_id" value="<?= (int) ($editPurchase['id'] ?? 0); ?>" />

                <?php if ($editBlockedByPayments): ?>
                  <div class="alert alert-danger">
                    <?= e(reversal_chain_message(
                      'Edit is blocked because unreversed purchase payments exist.',
                      ['Reverse purchase payments first, then retry edit.']
                    )); ?>
                  </div>
                <?php endif; ?>

                <div class="row g-2 mb-3">
                  <div class="col-md-3">
                    <label class="form-label">Assignment Status</label>
                    <input type="text" class="form-control" value="<?= e($editAssignmentStatus); ?>" readonly />
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Vendor</label>
                    <select name="vendor_id" class="form-select" <?= $editVendorRequired ? 'required' : ''; ?>>
                      <option value="0">Unassigned</option>
                      <?php foreach ($vendors as $vendor): ?>
                        <option value="<?= (int) $vendor['id']; ?>" <?= (int) ($editPurchase['vendor_id'] ?? 0) === (int) $vendor['id'] ? 'selected' : ''; ?>>
                          <?= e((string) $vendor['vendor_name']); ?> (<?= e((string) $vendor['vendor_code']); ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Invoice Number</label>
                    <input type="text" name="invoice_number" class="form-control" maxlength="80" value="<?= e((string) ($editPurchase['invoice_number'] ?? '')); ?>" <?= $editVendorRequired ? 'required' : ''; ?> />
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Date</label>
                    <input type="date" name="purchase_date" class="form-control" value="<?= e((string) ($editPurchase['purchase_date'] ?? date('Y-m-d'))); ?>" required />
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Net Paid</label>
                    <input type="text" class="form-control" value="<?= e(format_currency((float) ($editPurchasePaymentSummary['net_paid_amount'] ?? 0))); ?>" readonly />
                  </div>
                </div>

                <div class="table-responsive">
                  <table class="table table-sm table-bordered align-middle mb-2" id="purchase-edit-item-table">
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
                      <?php foreach ($editPurchaseItems as $line): ?>
                        <tr>
                          <td>
                            <select name="item_part_id[]" class="form-select form-select-sm js-item-part">
                              <option value="">Select Part</option>
                              <?php foreach ($parts as $part): ?>
                                <?php
                                  $partUnitCode = part_unit_normalize_code((string) ($part['unit'] ?? ''));
                                  if ($partUnitCode === '') {
                                      $partUnitCode = 'PCS';
                                  }
                                  $partAllowsDecimal = part_unit_allows_decimal($companyId, $partUnitCode);
                                  $partUnitLabel = part_unit_label($companyId, $partUnitCode);
                                ?>
                                <option
                                  value="<?= (int) $part['id']; ?>"
                                  data-default-cost="<?= e(number_format((float) ($part['purchase_price'] ?? 0), 2, '.', '')); ?>"
                                  data-default-gst="<?= e(number_format((float) ($part['gst_rate'] ?? 0), 2, '.', '')); ?>"
                                  data-stock="<?= e(number_format((float) ($part['stock_qty'] ?? 0), 2, '.', '')); ?>"
                                  data-stock-unit="<?= e($partUnitCode); ?>"
                                  data-unit="<?= e($partUnitCode); ?>"
                                  data-unit-label="<?= e($partUnitLabel); ?>"
                                  data-allow-decimal="<?= $partAllowsDecimal ? '1' : '0'; ?>"
                                  <?= (int) ($line['part_id'] ?? 0) === (int) $part['id'] ? 'selected' : ''; ?>
                                >
                                  <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>) | <?= e($partUnitCode); ?><?= $partAllowsDecimal ? ' Decimal' : ' Whole'; ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                          <?php
                            $lineUnitCode = part_unit_normalize_code((string) ($line['unit'] ?? ''));
                            if ($lineUnitCode === '') {
                                $lineUnitCode = 'PCS';
                            }
                            $lineAllowsDecimal = part_unit_allows_decimal($companyId, $lineUnitCode);
                          ?>
                          <td><input type="number" name="item_quantity[]" class="form-control form-control-sm" step="<?= $lineAllowsDecimal ? '0.01' : '1'; ?>" min="0" value="<?= e(number_format((float) ($line['quantity'] ?? 0), 2, '.', '')); ?>"></td>
                          <td><input type="number" name="item_unit_cost[]" class="form-control form-control-sm js-item-cost" step="0.01" min="0" value="<?= e(number_format((float) ($line['unit_cost'] ?? 0), 2, '.', '')); ?>"></td>
                          <td><input type="number" name="item_gst_rate[]" class="form-control form-control-sm js-item-gst" step="0.01" min="0" value="<?= e(number_format((float) ($line['gst_rate'] ?? 0), 2, '.', '')); ?>"></td>
                          <td class="text-muted small js-item-stock"><?= e(number_format((float) ($line['stock_qty'] ?? 0), 2, '.', '')); ?> <?= e($lineUnitCode); ?></td>
                        </tr>
                      <?php endforeach; ?>
                      <?php for ($blank = 0; $blank < 2; $blank++): ?>
                        <tr>
                          <td>
                            <select name="item_part_id[]" class="form-select form-select-sm js-item-part">
                              <option value="">Select Part</option>
                              <?php foreach ($parts as $part): ?>
                                <?php
                                  $partUnitCode = part_unit_normalize_code((string) ($part['unit'] ?? ''));
                                  if ($partUnitCode === '') {
                                      $partUnitCode = 'PCS';
                                  }
                                  $partAllowsDecimal = part_unit_allows_decimal($companyId, $partUnitCode);
                                  $partUnitLabel = part_unit_label($companyId, $partUnitCode);
                                ?>
                                <option
                                  value="<?= (int) $part['id']; ?>"
                                  data-default-cost="<?= e(number_format((float) ($part['purchase_price'] ?? 0), 2, '.', '')); ?>"
                                  data-default-gst="<?= e(number_format((float) ($part['gst_rate'] ?? 0), 2, '.', '')); ?>"
                                  data-stock="<?= e(number_format((float) ($part['stock_qty'] ?? 0), 2, '.', '')); ?>"
                                  data-stock-unit="<?= e($partUnitCode); ?>"
                                  data-unit="<?= e($partUnitCode); ?>"
                                  data-unit-label="<?= e($partUnitLabel); ?>"
                                  data-allow-decimal="<?= $partAllowsDecimal ? '1' : '0'; ?>"
                                >
                                  <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>) | <?= e($partUnitCode); ?><?= $partAllowsDecimal ? ' Decimal' : ' Whole'; ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                          <td><input type="number" name="item_quantity[]" class="form-control form-control-sm" step="1" min="0"></td>
                          <td><input type="number" name="item_unit_cost[]" class="form-control form-control-sm js-item-cost" step="0.01" min="0"></td>
                          <td><input type="number" name="item_gst_rate[]" class="form-control form-control-sm js-item-gst" step="0.01" min="0"></td>
                          <td class="text-muted small js-item-stock">-</td>
                        </tr>
                      <?php endfor; ?>
                    </tbody>
                  </table>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="add-edit-item-row">Add Item Row</button>
                <div class="row g-2 mt-2" id="purchase-edit-live-summary">
                  <div class="col-6 col-md-3">
                    <div class="border rounded p-2 bg-light-subtle">
                      <div class="text-muted small">Total Qty</div>
                      <div class="fw-semibold js-live-total-qty">0.00</div>
                    </div>
                  </div>
                  <div class="col-6 col-md-3">
                    <div class="border rounded p-2 bg-light-subtle">
                      <div class="text-muted small">Taxable Total</div>
                      <div class="fw-semibold js-live-total-taxable">0.00</div>
                    </div>
                  </div>
                  <div class="col-6 col-md-3">
                    <div class="border rounded p-2 bg-light-subtle">
                      <div class="text-muted small">GST Total</div>
                      <div class="fw-semibold js-live-total-gst">0.00</div>
                    </div>
                  </div>
                  <div class="col-6 col-md-3">
                    <div class="border rounded p-2 bg-light-subtle">
                      <div class="text-muted small">Grand Total</div>
                      <div class="fw-semibold js-live-total-grand">0.00</div>
                    </div>
                  </div>
                </div>

                <div class="row g-2 mt-2">
                  <div class="col-md-6">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" maxlength="255" value="<?= e((string) ($editPurchase['notes'] ?? '')); ?>" />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Audit Reason</label>
                    <input type="text" name="edit_reason" class="form-control" maxlength="255" required placeholder="Why is this purchase being edited?" />
                  </div>
                </div>
              </div>
              <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-warning" <?= $editBlockedByPayments ? 'disabled' : ''; ?>>Update Purchase</button>
                <a href="<?= e(url('modules/purchases/index.php')); ?>" class="btn btn-outline-secondary">Cancel</a>
              </div>
            </form>
          </div>
        <?php endif; ?>

        <?php if ($purchasePaymentsReady): ?>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <div class="small-box text-bg-success">
                <div class="inner">
                  <h4><?= e(number_format((float) ($summary['paid_total'] ?? 0), 2)); ?></h4>
                  <p>Total Paid</p>
                </div>
                <span class="small-box-icon"><i class="bi bi-cash-coin"></i></span>
              </div>
            </div>
            <div class="col-md-6">
              <div class="small-box text-bg-danger">
                <div class="inner">
                  <h4><?= e(number_format((float) ($summary['outstanding_total'] ?? 0), 2)); ?></h4>
                  <p>Outstanding Payables</p>
                </div>
                <span class="small-box-icon"><i class="bi bi-exclamation-circle"></i></span>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($purchasePaymentsReady && $canPayPurchases && $payPurchase): ?>
          <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Purchase Payments</h3></div>
            <div class="card-body">
              <div class="row g-2 mb-3">
                <div class="col-md-2"><strong>Purchase ID:</strong> #<?= (int) ($payPurchase['id'] ?? 0); ?></div>
                <div class="col-md-3"><strong>Vendor:</strong> <?= e((string) ($payPurchase['vendor_name'] ?? 'UNASSIGNED')); ?></div>
                <div class="col-md-2"><strong>Invoice:</strong> <?= e((string) ($payPurchase['invoice_number'] ?? '-')); ?></div>
                <div class="col-md-2"><strong>Date:</strong> <?= e((string) ($payPurchase['purchase_date'] ?? '-')); ?></div>
                <div class="col-md-3"><strong>Status:</strong> <?= e((string) ($payPurchase['purchase_status'] ?? '-')); ?></div>
              </div>
              <div class="row g-2 mb-3">
                <div class="col-md-3"><strong>Grand Total:</strong> <?= e(format_currency((float) ($payPurchase['grand_total'] ?? 0))); ?></div>
                <div class="col-md-3"><strong>Paid:</strong> <?= e(format_currency((float) ($payPurchase['total_paid'] ?? 0))); ?></div>
                <div class="col-md-3"><strong>Outstanding:</strong> <?= e(format_currency((float) ($payPurchase['outstanding_total'] ?? 0))); ?></div>
                <div class="col-md-3">
                  <a href="<?= e(url('modules/purchases/index.php')); ?>" class="btn btn-sm btn-outline-secondary">Close</a>
                </div>
              </div>

              <?php if ($canPayPurchases && (string) ($payPurchase['purchase_status'] ?? '') === 'FINALIZED' && (float) ($payPurchase['outstanding_total'] ?? 0) > 0.009): ?>
                <form method="post" class="row g-2 align-items-end mb-4">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="add_payment">
                  <input type="hidden" name="purchase_id" value="<?= (int) ($payPurchase['id'] ?? 0); ?>">

                  <div class="col-md-2">
                    <label class="form-label">Payment Date</label>
                    <input type="date" name="payment_date" class="form-control" value="<?= e(date('Y-m-d')); ?>" required>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Amount</label>
                    <input type="number" name="amount" class="form-control" step="0.01" min="0" required>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Mode</label>
                    <select name="payment_mode" class="form-select" required>
                      <?php foreach ($paymentModes as $mode): ?>
                        <option value="<?= e($mode); ?>"><?= e($mode); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Reference</label>
                    <input type="text" name="reference_no" class="form-control" maxlength="100" placeholder="Cheque/UTR/Ref">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" maxlength="255" placeholder="Payment note">
                  </div>
                  <div class="col-12">
                    <button type="submit" class="btn btn-primary">Record Payment</button>
                  </div>
                </form>
              <?php elseif ((string) ($payPurchase['purchase_status'] ?? '') !== 'FINALIZED'): ?>
                <div class="alert alert-warning">Only finalized purchases can receive payments.</div>
              <?php else: ?>
                <div class="alert alert-success">No outstanding balance on this purchase.</div>
              <?php endif; ?>

              <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Date</th>
                      <th>Type</th>
                      <th>Amount</th>
                      <th>Mode</th>
                      <th>Reference</th>
                      <th>Notes</th>
                      <th>Created By</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($paymentHistory)): ?>
                      <tr><td colspan="9" class="text-center text-muted py-4">No payment history yet.</td></tr>
                    <?php else: ?>
                      <?php foreach ($paymentHistory as $payment): ?>
                        <?php
                          $entryType = (string) ($payment['entry_type'] ?? 'PAYMENT');
                          $canReverse = $canPayPurchases && $entryType === 'PAYMENT' && (int) ($payment['reversal_id'] ?? 0) === 0;
                        ?>
                        <tr>
                          <td>#<?= (int) ($payment['id'] ?? 0); ?></td>
                          <td><?= e((string) ($payment['payment_date'] ?? '-')); ?></td>
                          <td><?= e($entryType); ?></td>
                          <td><?= e(format_currency((float) ($payment['amount'] ?? 0))); ?></td>
                          <td><?= e((string) ($payment['payment_mode'] ?? '-')); ?></td>
                          <td><?= e((string) ($payment['reference_no'] ?? '-')); ?></td>
                          <td><?= e((string) ($payment['notes'] ?? '-')); ?></td>
                          <td><?= e((string) ($payment['created_by_name'] ?? '-')); ?></td>
                          <td>
                            <?php if ($canReverse): ?>
                              <form method="post" class="d-flex gap-2 align-items-center">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="_action" value="reverse_payment">
                                <input type="hidden" name="payment_id" value="<?= (int) ($payment['id'] ?? 0); ?>">
                                <input type="hidden" name="purchase_id" value="<?= (int) ($payPurchase['id'] ?? 0); ?>">
                                <input type="text" name="reverse_reason" class="form-control form-control-sm" maxlength="255" placeholder="Reversal reason" required>
                                <button type="submit" class="btn btn-sm btn-outline-danger">Reverse</button>
                              </form>
                            <?php else: ?>
                              <span class="text-muted">-</span>
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
        <?php elseif ($purchasePaymentsReady && $payPurchaseId > 0 && !$canPayPurchases): ?>
          <div class="alert alert-warning">Purchase payment access requires `purchase.payments` permission.</div>
        <?php elseif ($purchasePaymentsReady && $payPurchaseId > 0): ?>
          <div class="alert alert-warning">Selected purchase not found for this garage.</div>
        <?php endif; ?>

        <div class="card mb-3">
          <div class="card-header"><h3 class="card-title">Purchase Filters & Reports</h3></div>
          <form
            method="get"
            data-date-filter-form="1"
            data-date-range-start="<?= e($purchaseRangeStart); ?>"
            data-date-range-end="<?= e($purchaseRangeEnd); ?>"
            data-date-yearly-start="<?= e(date('Y-01-01', strtotime($purchaseRangeEnd))); ?>"
          >
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
                <label class="form-label">Date Mode</label>
                <select name="date_mode" class="form-select">
                  <?php foreach ($dateModeOptions as $modeValue => $modeLabel): ?>
                    <option value="<?= e((string) $modeValue); ?>" <?= $dateMode === $modeValue ? 'selected' : ''; ?>>
                      <?= e((string) $modeLabel); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">From</label>
                <input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" required>
              </div>
              <div class="col-md-2">
                <label class="form-label">To</label>
                <input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" required>
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

        <?php if ($purchasePaymentsReady && $canViewVendorPayables): ?>
          <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Vendor Payables Summary</h3>
              <?php if ($canExport): ?>
                <?php
                  $exportParams = $_GET;
                  $exportParams['export'] = 'vendor_outstanding';
                ?>
                <a href="<?= e(url('modules/purchases/index.php?' . http_build_query($exportParams))); ?>" class="btn btn-sm btn-outline-primary">Vendor Outstanding CSV</a>
              <?php endif; ?>
            </div>
            <div class="card-body table-responsive p-0">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Vendor</th>
                    <th>Code</th>
                    <th>Purchases</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Outstanding</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($vendorOutstandingRows)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No outstanding vendor balances in selected range.</td></tr>
                  <?php else: ?>
                    <?php foreach ($vendorOutstandingRows as $row): ?>
                      <tr>
                        <td><?= e((string) ($row['vendor_name'] ?? '')); ?></td>
                        <td><?= e((string) ($row['vendor_code'] ?? '')); ?></td>
                        <td><?= (int) ($row['purchase_count'] ?? 0); ?></td>
                        <td><?= e(format_currency((float) ($row['grand_total'] ?? 0))); ?></td>
                        <td><?= e(format_currency((float) ($row['paid_total'] ?? 0))); ?></td>
                        <td><strong><?= e(format_currency((float) ($row['outstanding_total'] ?? 0))); ?></strong></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-lg-6">
              <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h3 class="card-title mb-0">Aging Summary</h3>
                  <?php if ($canExport): ?>
                    <?php
                      $exportParams = $_GET;
                      $exportParams['export'] = 'aging_summary';
                    ?>
                    <a href="<?= e(url('modules/purchases/index.php?' . http_build_query($exportParams))); ?>" class="btn btn-sm btn-outline-secondary">Aging CSV</a>
                  <?php endif; ?>
                </div>
                <div class="card-body">
                  <div class="row g-2">
                    <div class="col-6"><strong>0-30 Days:</strong> <?= e(format_currency((float) ($agingSummary['bucket_0_30'] ?? 0))); ?></div>
                    <div class="col-6"><strong>31-60 Days:</strong> <?= e(format_currency((float) ($agingSummary['bucket_31_60'] ?? 0))); ?></div>
                    <div class="col-6"><strong>61-90 Days:</strong> <?= e(format_currency((float) ($agingSummary['bucket_61_90'] ?? 0))); ?></div>
                    <div class="col-6"><strong>90+ Days:</strong> <?= e(format_currency((float) ($agingSummary['bucket_90_plus'] ?? 0))); ?></div>
                    <div class="col-12"><strong>Total Outstanding:</strong> <?= e(format_currency((float) ($agingSummary['outstanding_total'] ?? 0))); ?></div>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-lg-6">
              <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h3 class="card-title mb-0">Paid vs Unpaid</h3>
                  <?php if ($canExport): ?>
                    <?php
                      $exportParams = $_GET;
                      $exportParams['export'] = 'paid_unpaid';
                    ?>
                    <a href="<?= e(url('modules/purchases/index.php?' . http_build_query($exportParams))); ?>" class="btn btn-sm btn-outline-secondary">Paid/Unpaid CSV</a>
                  <?php endif; ?>
                </div>
                <div class="card-body">
                  <div class="row g-2">
                    <div class="col-6"><strong>Paid Purchases:</strong> <?= (int) ($paidUnpaidSummary['paid_count'] ?? 0); ?></div>
                    <div class="col-6"><strong>Paid Total:</strong> <?= e(format_currency((float) ($paidUnpaidSummary['paid_total'] ?? 0))); ?></div>
                    <div class="col-6"><strong>Partial Purchases:</strong> <?= (int) ($paidUnpaidSummary['partial_count'] ?? 0); ?></div>
                    <div class="col-6"><strong>Partial Total:</strong> <?= e(format_currency((float) ($paidUnpaidSummary['partial_total'] ?? 0))); ?></div>
                    <div class="col-6"><strong>Unpaid Purchases:</strong> <?= (int) ($paidUnpaidSummary['unpaid_count'] ?? 0); ?></div>
                    <div class="col-6"><strong>Unpaid Total:</strong> <?= e(format_currency((float) ($paidUnpaidSummary['unpaid_total'] ?? 0))); ?></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php elseif ($purchasePaymentsReady && !$canViewVendorPayables): ?>
          <div class="alert alert-warning">Vendor payable summaries require `vendor.payments` permission.</div>
        <?php endif; ?>

        <div class="card mb-3">
          <div class="card-header"><h3 class="card-title mb-0">Purchase List</h3></div>
          <div class="card-body border-bottom">
            <form method="get" class="row g-2 align-items-end">
              <input type="hidden" name="date_mode" value="custom">
              <input type="hidden" name="invoice" value="<?= e($invoiceFilter); ?>">
              <input type="hidden" name="purchase_status" value="<?= e($statusFilter); ?>">
              <input type="hidden" name="assignment_status" value="<?= e($assignmentFilter); ?>">
              <input type="hidden" name="purchase_source" value="<?= e($sourceFilter); ?>">
              <div class="col-md-3">
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
                <label class="form-label">Payment Status</label>
                <select name="payment_status" class="form-select">
                  <option value="">All</option>
                  <?php foreach ($allowedPaymentStatuses as $status): ?>
                    <option value="<?= e($status); ?>" <?= $paymentFilter === $status ? 'selected' : ''; ?>><?= e($status); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" required>
              </div>
              <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" required>
              </div>
              <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary">Apply</button>
                <a href="<?= e(url('modules/purchases/index.php')); ?>" class="btn btn-outline-secondary">Reset</a>
              </div>
            </form>
          </div>
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
                  <?php if ($purchasePaymentsReady): ?>
                    <th>Paid</th>
                    <th>Outstanding</th>
                  <?php endif; ?>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($purchases)): ?>
                  <tr><td colspan="<?= $purchasePaymentsReady ? 16 : 14; ?>" class="text-center text-muted py-4">No purchases found for selected filters.</td></tr>
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
                      <?php if ($purchasePaymentsReady): ?>
                        <td><?= e(number_format((float) ($purchase['paid_total'] ?? 0), 2)); ?></td>
                        <td><strong><?= e(number_format((float) ($purchase['outstanding_total'] ?? 0), 2)); ?></strong></td>
                      <?php endif; ?>
                      <td class="text-nowrap">
                        <?php if ($isUnassigned && $canManage): ?>
                          <a class="btn btn-sm btn-outline-warning" href="<?= e(url('modules/purchases/index.php?assign_purchase_id=' . $purchaseId)); ?>">Assign</a>
                        <?php endif; ?>
                        <?php if ($canManage): ?>
                          <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('modules/purchases/index.php?edit_purchase_id=' . $purchaseId)); ?>">Edit</a>
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
                        <?php if ($purchasePaymentsReady && $canPayPurchases): ?>
                          <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/purchases/index.php?pay_purchase_id=' . $purchaseId)); ?>">Payments</a>
                        <?php endif; ?>
                        <?php if ($canDeletePurchases): ?>
                          <button
                            type="button"
                            class="btn btn-sm btn-outline-danger js-purchase-delete-btn"
                            data-bs-toggle="modal"
                            data-bs-target="#deletePurchaseModal"
                            data-purchase-id="<?= $purchaseId; ?>"
                            data-purchase-label="#<?= $purchaseId; ?> | <?= e((string) (($purchase['invoice_number'] ?? '') !== '' ? $purchase['invoice_number'] : 'NO-INVOICE')); ?> | <?= e(number_format((float) ($purchase['grand_total'] ?? 0), 2)); ?>"
                          >Delete</button>
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

<div class="modal fade" id="deletePurchaseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header bg-danger-subtle">
          <h5 class="modal-title">Delete Purchase</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?= csrf_field(); ?>
          <input type="hidden" name="_action" value="delete_purchase" />
          <input type="hidden" name="purchase_id" id="delete-purchase-id" />
          <div class="mb-3">
            <label class="form-label">Purchase</label>
            <input type="text" id="delete-purchase-label" class="form-control" readonly />
          </div>
          <div class="mb-0">
            <label class="form-label">Delete Reason</label>
            <textarea name="delete_reason" id="delete-purchase-reason" class="form-control" rows="3" maxlength="255" required></textarea>
            <small class="text-muted">Deletion is blocked when unreversed payments or downstream stock dependencies exist.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-danger">Delete with Reversal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  (function () {
    function readNumber(value) {
      var parsed = parseFloat(value);
      if (!Number.isFinite(parsed)) {
        return 0;
      }
      return parsed;
    }

    function roundTwo(value) {
      return Math.round((value + Number.EPSILON) * 100) / 100;
    }

    function formatMoney(value) {
      return value.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function updateSummary(summaryEl, totals) {
      if (!summaryEl) {
        return;
      }

      var qtyEl = summaryEl.querySelector('.js-live-total-qty');
      var taxableEl = summaryEl.querySelector('.js-live-total-taxable');
      var gstEl = summaryEl.querySelector('.js-live-total-gst');
      var grandEl = summaryEl.querySelector('.js-live-total-grand');

      if (qtyEl) {
        qtyEl.textContent = formatMoney(totals.qty);
      }
      if (taxableEl) {
        taxableEl.textContent = formatMoney(totals.taxable);
      }
      if (gstEl) {
        gstEl.textContent = formatMoney(totals.gst);
      }
      if (grandEl) {
        grandEl.textContent = formatMoney(totals.grand);
      }
    }

    function calculateTableTotals(table) {
      var totals = {
        qty: 0,
        taxable: 0,
        gst: 0,
        grand: 0
      };

      if (!table) {
        return totals;
      }

      table.querySelectorAll('tbody tr').forEach(function (row) {
        var qtyInput = row.querySelector('input[name="item_quantity[]"]');
        var costInput = row.querySelector('.js-item-cost');
        var gstInput = row.querySelector('.js-item-gst');

        var qty = Math.max(0, readNumber(qtyInput ? qtyInput.value : 0));
        var unitCost = Math.max(0, readNumber(costInput ? costInput.value : 0));
        var gstRate = Math.max(0, readNumber(gstInput ? gstInput.value : 0));

        if (qty <= 0) {
          return;
        }

        var taxable = roundTwo(qty * unitCost);
        var gstAmount = roundTwo((taxable * gstRate) / 100);
        var lineTotal = roundTwo(taxable + gstAmount);

        totals.qty += qty;
        totals.taxable += taxable;
        totals.gst += gstAmount;
        totals.grand += lineTotal;
      });

      totals.qty = roundTwo(totals.qty);
      totals.taxable = roundTwo(totals.taxable);
      totals.gst = roundTwo(totals.gst);
      totals.grand = roundTwo(totals.grand);

      return totals;
    }

    function applyPartSelectionToRow(row, selectedOption) {
      if (!row) {
        return;
      }

      var qtyInput = row.querySelector('input[name="item_quantity[]"]');
      var costInput = row.querySelector('.js-item-cost');
      var gstInput = row.querySelector('.js-item-gst');
      var stockCell = row.querySelector('.js-item-stock');
      var hasSelection = !!(selectedOption && selectedOption.value !== '');

      if (!hasSelection) {
        if (stockCell) {
          stockCell.textContent = '-';
        }
        if (qtyInput) {
          qtyInput.step = '0.01';
        }
        return;
      }

      var stockValue = selectedOption.getAttribute('data-stock') || '0';
      var stockUnit = selectedOption.getAttribute('data-stock-unit') || selectedOption.getAttribute('data-unit') || 'PCS';
      var allowDecimal = (selectedOption.getAttribute('data-allow-decimal') || '1') === '1';

      if (stockCell) {
        var unitRule = allowDecimal ? 'decimal' : 'whole only';
        stockCell.textContent = stockValue + ' ' + stockUnit + ' (' + unitRule + ')';
      }
      if (qtyInput) {
        qtyInput.step = allowDecimal ? '0.01' : '1';
        if (!allowDecimal && qtyInput.value !== '') {
          qtyInput.value = String(Math.max(0, Math.round(readNumber(qtyInput.value))));
        }
      }

      var defaultCost = selectedOption.getAttribute('data-default-cost');
      var defaultGst = selectedOption.getAttribute('data-default-gst');
      if (costInput && (!costInput.value || parseFloat(costInput.value) === 0)) {
        costInput.value = defaultCost || '';
      }
      if (gstInput && (!gstInput.value || parseFloat(gstInput.value) === 0)) {
        gstInput.value = defaultGst || '';
      }
    }

    function wirePartSelect(select, onChanged) {
      if (!select) {
        return;
      }

      select.addEventListener('change', function () {
        var row = select.closest('tr');
        if (!row) {
          return;
        }

        var selectedOption = select.options[select.selectedIndex];
        applyPartSelectionToRow(row, selectedOption || null);

        if (typeof onChanged === 'function') {
          onChanged();
        }
      });
    }

    function wireRowEvents(row, onChanged) {
      if (!row) {
        return;
      }

      var partSelect = row.querySelector('.js-item-part');
      wirePartSelect(partSelect, onChanged);
      if (partSelect) {
        applyPartSelectionToRow(row, partSelect.options[partSelect.selectedIndex] || null);
      }

      row.querySelectorAll('input[name="item_quantity[]"], .js-item-cost, .js-item-gst').forEach(function (input) {
        input.addEventListener('input', onChanged);
        input.addEventListener('change', onChanged);
      });
    }

    function initItemTable(tableId, addButtonId, summaryId) {
      var table = document.getElementById(tableId);
      var addRowBtn = document.getElementById(addButtonId);
      if (!table) {
        return;
      }

      var summaryEl = summaryId ? document.getElementById(summaryId) : null;
      var recalculateAndRender = function () {
        updateSummary(summaryEl, calculateTableTotals(table));
      };

      table.querySelectorAll('tbody tr').forEach(function (row) {
        wireRowEvents(row, recalculateAndRender);
      });

      if (addRowBtn) {
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
          wireRowEvents(clone, recalculateAndRender);
          recalculateAndRender();
        });
      }

      recalculateAndRender();
    }

    initItemTable('purchase-item-table', 'add-item-row', 'purchase-live-summary');
    initItemTable('purchase-edit-item-table', 'add-edit-item-row', 'purchase-edit-live-summary');

    function setValue(id, value) {
      var field = document.getElementById(id);
      if (!field) {
        return;
      }
      field.value = value || '';
    }

    document.addEventListener('click', function (event) {
      var deleteTrigger = event.target.closest('.js-purchase-delete-btn');
      if (!deleteTrigger) {
        return;
      }
      setValue('delete-purchase-id', deleteTrigger.getAttribute('data-purchase-id'));
      setValue('delete-purchase-label', deleteTrigger.getAttribute('data-purchase-label'));
      setValue('delete-purchase-reason', '');
    });
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
