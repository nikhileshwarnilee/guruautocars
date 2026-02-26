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
    flash_set('access_denied', 'You do not have permission to access Returns & RMA.', 'danger');
    redirect('dashboard.php');
}

$page_title = 'Returns & RMA';
$active_menu = 'inventory.returns';
$companyId = active_company_id();
$garageId = active_garage_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$canCreate = has_permission('inventory.manage')
    || has_permission('billing.finalize')
    || has_permission('purchase.manage')
    || has_permission('job.manage');
$canApprove = has_permission('inventory.manage')
    || has_permission('invoice.manage')
    || has_permission('purchase.manage');
$canSettle = $canCreate || $canApprove;
$canDeleteReturn = $canApprove;

if (!returns_module_ready()) {
    flash_set('return_error', 'Returns module is not ready. Please run DB upgrade safely.', 'danger');
}

function returns_post_line_inputs(): array
{
    $sourceIds = $_POST['item_source_id'] ?? [];
    $quantities = $_POST['item_quantity'] ?? [];

    if (!is_array($sourceIds) || !is_array($quantities)) {
        return [];
    }

    $lines = [];
    $count = min(count($sourceIds), count($quantities));
    for ($index = 0; $index < $count; $index++) {
        $sourceItemId = (int) ($sourceIds[$index] ?? 0);
        $rawQty = str_replace([',', ' '], '', trim((string) ($quantities[$index] ?? '')));
        $qty = is_numeric($rawQty) ? (float) $rawQty : 0.0;
        if ($sourceItemId <= 0 || $qty <= 0.009) {
            continue;
        }
        $lines[] = [
            'source_item_id' => $sourceItemId,
            'quantity' => $qty,
        ];
    }

    return $lines;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = (string) ($_POST['_action'] ?? '');
    $pdo = db();

    if ($action === 'create_return') {
        if (!$canCreate) {
            flash_set('return_error', 'You do not have permission to create returns.', 'danger');
            redirect('modules/returns/index.php');
        }

        $returnType = returns_normalize_type((string) ($_POST['return_type'] ?? 'CUSTOMER_RETURN'));
        $sourceId = $returnType === 'CUSTOMER_RETURN'
            ? post_int('invoice_id')
            : post_int('purchase_id');
        $returnDate = returns_parse_date((string) ($_POST['return_date'] ?? date('Y-m-d'))) ?? date('Y-m-d');
        $reasonText = post_string('reason_text', 255);
        $reasonDetail = post_string('reason_detail', 5000);
        $notes = post_string('notes', 255);
        $lines = returns_post_line_inputs();

        try {
            $result = returns_create_rma(
                $pdo,
                $companyId,
                $garageId,
                $userId,
                $returnType,
                $sourceId,
                $returnDate,
                $reasonText,
                $reasonDetail,
                $notes,
                $lines
            );

            $returnId = (int) ($result['return_id'] ?? 0);
            $attachmentUpload = returns_store_uploaded_attachment($_FILES['return_attachment'] ?? [], $companyId, $garageId, $returnId);
            if ((bool) ($attachmentUpload['ok'] ?? false) && $returnId > 0) {
                returns_attach_file(
                    $pdo,
                    $companyId,
                    $garageId,
                    $returnId,
                    (string) ($attachmentUpload['file_name'] ?? ''),
                    (string) ($attachmentUpload['relative_path'] ?? ''),
                    (string) ($attachmentUpload['mime_type'] ?? ''),
                    (int) ($attachmentUpload['file_size_bytes'] ?? 0),
                    $userId
                );
            }

            log_audit('returns', 'create', $returnId, 'Created return ' . (string) ($result['return_number'] ?? ''), [
                'entity' => 'returns_rma',
                'source' => 'UI',
                'before' => null,
                'after' => [
                    'return_type' => $returnType,
                    'source_id' => $sourceId,
                    'line_count' => (int) ($result['line_count'] ?? 0),
                    'total_amount' => (float) ($result['total_amount'] ?? 0),
                    'approval_status' => (string) ($result['approval_status'] ?? 'APPROVED'),
                    'stock_posted_count' => count((array) (($result['stock_posting'] ?? [])['posted'] ?? [])),
                ],
            ]);

            flash_set(
                'return_success',
                'Return ' . (string) ($result['return_number'] ?? '') . ' created and approved successfully.',
                'success'
            );
            if (!(bool) ($attachmentUpload['ok'] ?? false) && (int) (($attachmentUpload['file_size_bytes'] ?? 0)) > 0) {
                flash_set('return_warning', (string) ($attachmentUpload['message'] ?? 'Attachment upload failed.'), 'warning');
            }
            redirect('modules/returns/index.php?view_id=' . (int) ($result['return_id'] ?? 0));
        } catch (Throwable $exception) {
            flash_set('return_error', $exception->getMessage(), 'danger');
            redirect('modules/returns/index.php');
        }
    }

    if ($action === 'approve_return') {
        if (!$canApprove) {
            flash_set('return_error', 'You do not have permission to approve returns.', 'danger');
            redirect('modules/returns/index.php');
        }

        $returnId = post_int('return_id');
        try {
            $result = returns_approve_rma($pdo, $returnId, $companyId, $garageId, $userId);
            log_audit('returns', 'approve', $returnId, 'Approved return ' . (string) ($result['return_number'] ?? ''), [
                'entity' => 'returns_rma',
                'source' => 'UI',
                'after' => [
                    'stock_posted_count' => count((array) (($result['stock_posting'] ?? [])['posted'] ?? [])),
                ],
            ]);
            flash_set('return_success', 'Return approved successfully.', 'success');
        } catch (Throwable $exception) {
            flash_set('return_error', $exception->getMessage(), 'danger');
        }
        redirect('modules/returns/index.php?view_id=' . $returnId);
    }

    if ($action === 'reject_return') {
        if (!$canApprove) {
            flash_set('return_error', 'You do not have permission to reject returns.', 'danger');
            redirect('modules/returns/index.php');
        }

        $returnId = post_int('return_id');
        $rejectReason = post_string('reject_reason', 255);
        try {
            $result = returns_reject_rma($pdo, $returnId, $companyId, $garageId, $userId, $rejectReason);
            log_audit('returns', 'reject', $returnId, 'Rejected return ' . (string) ($result['return_number'] ?? ''), [
                'entity' => 'returns_rma',
                'source' => 'UI',
                'metadata' => ['reason' => $rejectReason],
            ]);
            flash_set('return_success', 'Return rejected successfully.', 'success');
        } catch (Throwable $exception) {
            flash_set('return_error', $exception->getMessage(), 'danger');
        }
        redirect('modules/returns/index.php?view_id=' . $returnId);
    }

    if ($action === 'close_return') {
        if (!$canApprove) {
            flash_set('return_error', 'You do not have permission to close returns.', 'danger');
            redirect('modules/returns/index.php');
        }
        $returnId = post_int('return_id');
        if (returns_close_rma($pdo, $returnId, $companyId, $garageId, $userId)) {
            log_audit('returns', 'close', $returnId, 'Closed approved return #' . $returnId, [
                'entity' => 'returns_rma',
                'source' => 'UI',
            ]);
            flash_set('return_success', 'Return closed.', 'success');
        } else {
            flash_set('return_error', 'Only approved returns can be closed.', 'danger');
        }
        redirect('modules/returns/index.php?view_id=' . $returnId);
    }

    if ($action === 'settle_return') {
        if (!$canSettle) {
            flash_set('return_error', 'You do not have permission to settle returns.', 'danger');
            redirect('modules/returns/index.php');
        }

        $returnId = post_int('return_id');
        $settlementDate = (string) ($_POST['settlement_date'] ?? date('Y-m-d'));
        $rawAmount = str_replace([',', ' '], '', trim((string) ($_POST['amount'] ?? '0')));
        $amount = is_numeric($rawAmount) ? (float) $rawAmount : 0.0;
        $paymentMode = post_string('payment_mode', 40);
        $referenceNo = post_string('reference_no', 100);
        $notes = post_string('notes', 255);

        try {
            $result = returns_record_settlement(
                $pdo,
                $returnId,
                $companyId,
                $garageId,
                $userId,
                $settlementDate,
                $amount,
                $paymentMode,
                $referenceNo,
                $notes
            );

            $settlementType = (string) ($result['settlement_type'] ?? '');
            $actionLabel = $settlementType === 'RECEIVE' ? 'Receive' : 'Pay';
            $settlementId = (int) ($result['settlement_id'] ?? 0);

            log_audit('returns', 'settlement', $settlementId, $actionLabel . ' settlement on return ' . (string) ($result['return_number'] ?? ''), [
                'entity' => 'return_settlement',
                'source' => 'UI',
                'metadata' => [
                    'return_id' => $returnId,
                    'settlement_type' => $settlementType,
                    'payment_mode' => (string) ($result['payment_mode'] ?? ''),
                    'amount' => (float) ($result['amount'] ?? 0),
                    'expense_id' => (int) ($result['expense_id'] ?? 0),
                ],
            ]);

            flash_set('return_success', $actionLabel . ' entry recorded successfully.', 'success');
        } catch (Throwable $exception) {
            flash_set('return_error', $exception->getMessage(), 'danger');
        }
        redirect('modules/returns/index.php?view_id=' . $returnId . '#settlement-card');
    }

    if ($action === 'reverse_settlement') {
        if (!$canSettle) {
            flash_set('return_error', 'You do not have permission to reverse return settlements.', 'danger');
            redirect('modules/returns/index.php');
        }

        $settlementId = post_int('settlement_id');
        $returnId = post_int('return_id');
        $reverseReason = post_string('reverse_reason', 255);
        if ($settlementId <= 0 || $reverseReason === '') {
            flash_set('return_error', 'Settlement and reversal reason are required.', 'danger');
            redirect('modules/returns/index.php' . ($returnId > 0 ? ('?view_id=' . $returnId . '#settlement-card') : ''));
        }
        try {
            $safeDeleteValidation = safe_delete_validate_post_confirmation('return_settlement', $settlementId, [
                'operation' => 'reverse',
                'reason_field' => 'reverse_reason',
            ]);
            $result = returns_reverse_settlement($pdo, $settlementId, $companyId, $garageId, $userId, $reverseReason);
            $returnId = (int) ($result['return_id'] ?? $returnId);
            safe_delete_log_cascade('return_settlement', 'reverse', $settlementId, $safeDeleteValidation, [
                'reversal_references' => array_values(array_filter([
                    (int) ($result['finance_reversal_expense_id'] ?? 0) > 0 ? ('EXP#' . (int) ($result['finance_reversal_expense_id'] ?? 0)) : '',
                ])),
                'metadata' => [
                    'company_id' => $companyId,
                    'garage_id' => $garageId,
                    'return_id' => $returnId,
                    'reverse_reason' => $reverseReason,
                ],
            ]);
            log_audit('returns', 'settlement_reverse', $settlementId, 'Reversed return settlement #' . $settlementId, [
                'entity' => 'return_settlement',
                'source' => 'UI',
                'metadata' => [
                    'return_id' => $returnId,
                    'return_number' => (string) ($result['return_number'] ?? ''),
                    'settlement_type' => (string) ($result['settlement_type'] ?? ''),
                    'amount' => (float) ($result['amount'] ?? 0),
                    'expense_id' => (int) ($result['expense_id'] ?? 0),
                    'finance_reversal_expense_id' => (int) ($result['finance_reversal_expense_id'] ?? 0),
                    'reverse_reason' => $reverseReason,
                ],
            ]);
            flash_set('return_success', 'Settlement entry reversed.', 'success');
        } catch (Throwable $exception) {
            flash_set('return_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/returns/index.php' . ($returnId > 0 ? ('?view_id=' . $returnId . '#settlement-card') : ''));
    }

    if ($action === 'delete_return') {
        if (!$canDeleteReturn) {
            flash_set('return_error', 'You do not have permission to delete returns.', 'danger');
            redirect('modules/returns/index.php');
        }

        $returnId = post_int('return_id');
        $deleteReason = post_string('delete_reason', 255);
        try {
            $safeDeleteValidation = safe_delete_validate_post_confirmation('return', $returnId, [
                'operation' => 'delete',
                'reason_field' => 'delete_reason',
            ]);
            $result = returns_delete_rma($pdo, $returnId, $companyId, $garageId, $userId, $deleteReason);
            safe_delete_log_cascade('return', 'delete', $returnId, (array) $safeDeleteValidation, [
                'reversal_references' => array_values(array_map(
                    static fn (array $row): string => (string) ($row['movement_uid'] ?? ''),
                    array_values(array_filter((array) (($result['stock_reversal'] ?? [])['posted'] ?? []), static fn ($row): bool => is_array($row)))
                )),
                'metadata' => [
                    'return_number' => (string) ($result['return_number'] ?? ''),
                    'delete_reason' => $deleteReason,
                ],
            ]);
            log_audit('returns', 'delete', $returnId, 'Deleted return ' . (string) ($result['return_number'] ?? ''), [
                'entity' => 'returns_rma',
                'source' => 'UI',
                'metadata' => [
                    'return_type' => (string) ($result['return_type'] ?? ''),
                    'approval_status' => (string) ($result['approval_status'] ?? ''),
                    'delete_reason' => $deleteReason,
                    'stock_reversal_posted_count' => count((array) (($result['stock_reversal'] ?? [])['posted'] ?? [])),
                    'stock_reversal_skipped_count' => count((array) (($result['stock_reversal'] ?? [])['skipped'] ?? [])),
                ],
            ]);
            flash_set('return_success', 'Return deleted successfully.', 'success');
            redirect('modules/returns/index.php');
        } catch (Throwable $exception) {
            flash_set('return_error', $exception->getMessage(), 'danger');
            redirect('modules/returns/index.php?view_id=' . $returnId);
        }
    }

    if ($action === 'upload_attachment') {
        $returnId = post_int('return_id');
        $returnRow = returns_fetch_return_row($pdo, $returnId, $companyId, $garageId);
        if (!$returnRow) {
            flash_set('return_error', 'Return entry not found for attachment upload.', 'danger');
            redirect('modules/returns/index.php');
        }
        $uploadResult = returns_store_uploaded_attachment($_FILES['attachment_file'] ?? [], $companyId, $garageId, $returnId);
        if (!(bool) ($uploadResult['ok'] ?? false)) {
            flash_set('return_error', (string) ($uploadResult['message'] ?? 'Unable to upload attachment.'), 'danger');
            redirect('modules/returns/index.php?view_id=' . $returnId);
        }

        $attachmentId = returns_attach_file(
            $pdo,
            $companyId,
            $garageId,
            $returnId,
            (string) ($uploadResult['file_name'] ?? ''),
            (string) ($uploadResult['relative_path'] ?? ''),
            (string) ($uploadResult['mime_type'] ?? ''),
            (int) ($uploadResult['file_size_bytes'] ?? 0),
            $userId
        );
        log_audit('returns', 'attachment_add', $attachmentId, 'Uploaded return attachment', [
            'entity' => 'return_attachment',
            'source' => 'UI',
            'metadata' => ['return_id' => $returnId],
        ]);
        flash_set('return_success', 'Attachment uploaded.', 'success');
        redirect('modules/returns/index.php?view_id=' . $returnId);
    }

    if ($action === 'delete_attachment') {
        $attachmentId = post_int('attachment_id');
        $returnId = post_int('return_id');
        $safeDeleteValidation = null;
        try {
            $safeDeleteValidation = safe_delete_validate_post_confirmation('return_attachment', $attachmentId, [
                'operation' => 'delete',
                'reason_field' => 'deletion_reason',
            ]);
        } catch (Throwable $exception) {
            flash_set('return_error', $exception->getMessage(), 'danger');
            redirect('modules/returns/index.php?view_id=' . $returnId);
        }
        if (!returns_delete_attachment($pdo, $attachmentId, $companyId, $garageId, $userId)) {
            flash_set('return_error', 'Attachment not found.', 'danger');
        } else {
            if (is_array($safeDeleteValidation)) {
                safe_delete_log_cascade('return_attachment', 'delete', $attachmentId, $safeDeleteValidation, [
                    'metadata' => [
                        'company_id' => $companyId,
                        'garage_id' => $garageId,
                        'return_id' => $returnId,
                    ],
                ]);
            }
            flash_set('return_success', 'Attachment deleted.', 'success');
        }
        redirect('modules/returns/index.php?view_id=' . $returnId);
    }
}

$returnTypeFilter = returns_normalize_type((string) ($_GET['return_type'] ?? ''));
if (!isset($_GET['return_type'])) {
    $returnTypeFilter = '';
}
$approvalFilter = returns_normalize_approval_status((string) ($_GET['approval_status'] ?? ''));
if (!isset($_GET['approval_status'])) {
    $approvalFilter = '';
}
$query = trim((string) ($_GET['q'] ?? ''));
$fromDate = returns_parse_date((string) ($_GET['from'] ?? date('Y-m-01'))) ?? date('Y-m-01');
$toDate = returns_parse_date((string) ($_GET['to'] ?? date('Y-m-d'))) ?? date('Y-m-d');

$where = ['r.company_id = :company_id', 'r.garage_id = :garage_id', 'r.status_code = "ACTIVE"', 'r.return_date BETWEEN :from_date AND :to_date'];
$params = [
    'company_id' => $companyId,
    'garage_id' => $garageId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];

if ($returnTypeFilter !== '') {
    $where[] = 'r.return_type = :return_type';
    $params['return_type'] = $returnTypeFilter;
}
if ($approvalFilter !== '') {
    $where[] = 'r.approval_status = :approval_status';
    $params['approval_status'] = $approvalFilter;
}
if ($query !== '') {
    $where[] = '(r.return_number LIKE :q OR i.invoice_number LIKE :q OR p.invoice_number LIKE :q OR c.full_name LIKE :q OR v.vendor_name LIKE :q)';
    $params['q'] = '%' . $query . '%';
}

$returnsSettlementSelectSql = '0.00 AS settled_amount, r.total_amount AS pending_amount';
$returnsSettlementJoinSql = '';
if (table_columns('return_settlements') !== []) {
    $returnsSettlementSelectSql = 'COALESCE(rs.settled_amount, 0) AS settled_amount,
            GREATEST(r.total_amount - COALESCE(rs.settled_amount, 0), 0) AS pending_amount';
    $returnsSettlementJoinSql = '
     LEFT JOIN (
         SELECT return_id, COALESCE(SUM(amount), 0) AS settled_amount
         FROM return_settlements
         WHERE status_code = "ACTIVE"
         GROUP BY return_id
     ) rs ON rs.return_id = r.id';
}

$returnsStmt = db()->prepare(
    'SELECT r.*,
            i.invoice_number,
            p.invoice_number AS purchase_invoice_number,
            c.full_name AS customer_name,
            v.vendor_name,
            ' . $returnsSettlementSelectSql . '
     FROM returns_rma r
     LEFT JOIN invoices i ON i.id = r.invoice_id
     LEFT JOIN purchases p ON p.id = r.purchase_id
     LEFT JOIN customers c ON c.id = r.customer_id
     LEFT JOIN vendors v ON v.id = r.vendor_id'
     . $returnsSettlementJoinSql . '
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY r.id DESC
     LIMIT 300'
);
$returnsStmt->execute($params);
$returnsRows = $returnsStmt->fetchAll();

$viewId = get_int('view_id');
$selectedReturn = null;
$selectedReturnItems = [];
$selectedReturnAttachments = [];
$selectedReturnSettlements = [];
$selectedReturnSettlementSummary = [
    'settlement_count' => 0,
    'settled_amount' => 0.0,
    'paid_amount' => 0.0,
    'received_amount' => 0.0,
    'balance_amount' => 0.0,
];
$selectedReturnSettlementType = 'PAY';
$selectedReturnSettlementActionLabel = 'Pay';
$selectedReturnCanSettleStatus = false;
$settlementPaymentModes = function_exists('finance_payment_modes')
    ? finance_payment_modes()
    : ['CASH', 'UPI', 'CARD', 'BANK_TRANSFER', 'CHEQUE', 'MIXED', 'ADJUSTMENT'];
if ($viewId > 0) {
    $selectedReturn = returns_fetch_return_row(db(), $viewId, $companyId, $garageId);
    if (is_array($selectedReturn)) {
        $selectedReturnItems = returns_fetch_return_items(db(), (int) ($selectedReturn['id'] ?? 0));
        $selectedReturnAttachments = returns_fetch_attachments(db(), $companyId, $garageId, (int) ($selectedReturn['id'] ?? 0));
        $selectedReturnSettlementType = returns_expected_settlement_type((string) ($selectedReturn['return_type'] ?? 'CUSTOMER_RETURN'));
        $selectedReturnSettlementActionLabel = $selectedReturnSettlementType === 'RECEIVE' ? 'Receive' : 'Pay';
        $selectedReturnSettlementSummary = returns_fetch_settlement_summary(
            db(),
            (int) ($selectedReturn['id'] ?? 0),
            $companyId,
            $garageId,
            (float) ($selectedReturn['total_amount'] ?? 0)
        );
        $selectedReturnSettlements = returns_fetch_settlement_history(db(), $companyId, $garageId, (int) ($selectedReturn['id'] ?? 0));
        $selectedReturnStatus = returns_normalize_approval_status((string) ($selectedReturn['approval_status'] ?? 'PENDING'));
        $selectedReturnCanSettleStatus = in_array($selectedReturnStatus, returns_settlement_allowed_statuses(), true);
    }
}

$invoiceOptionsStmt = db()->prepare(
    'SELECT i.id, i.invoice_number, i.invoice_date, c.full_name AS customer_name, v.registration_no
     FROM invoices i
     INNER JOIN customers c ON c.id = i.customer_id
     LEFT JOIN vehicles v ON v.id = i.vehicle_id
     WHERE i.company_id = :company_id
       AND i.garage_id = :garage_id
       AND i.invoice_status IN ("DRAFT", "FINALIZED")
     ORDER BY i.id DESC
     LIMIT 200'
);
$invoiceOptionsStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$invoiceOptions = $invoiceOptionsStmt->fetchAll();

$purchaseOptionsStmt = db()->prepare(
    'SELECT p.id, p.invoice_number, p.purchase_date, v.vendor_name
     FROM purchases p
     LEFT JOIN vendors v ON v.id = p.vendor_id
     WHERE p.company_id = :company_id
       AND p.garage_id = :garage_id
       AND (p.status_code IS NULL OR p.status_code <> "DELETED")
     ORDER BY p.id DESC
     LIMIT 200'
);
$purchaseOptionsStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$purchaseOptions = $purchaseOptionsStmt->fetchAll();

$itemsApiUrl = url('modules/returns/items_api.php');
$printReturnBase = url('modules/returns/print_return.php');

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Returns & RMA</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Returns & RMA</li>
          </ol>
        </div>
      </div>
    </div>
  </div>
  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canCreate): ?>
        <div class="card card-primary mb-3" id="create-return-card">
          <div class="card-header"><h3 class="card-title">Create Return</h3></div>
          <form method="post" enctype="multipart/form-data" id="create-return-form">
            <div class="card-body">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="create_return" />
              <div class="row g-3">
                <div class="col-md-3">
                  <label class="form-label">Return Type</label>
                  <select name="return_type" id="return-type-select" class="form-select" required>
                    <?php foreach (returns_allowed_types() as $typeKey => $typeLabel): ?>
                      <option value="<?= e($typeKey); ?>"><?= e($typeLabel); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Return Date</label>
                  <input type="date" name="return_date" class="form-control" value="<?= e(date('Y-m-d')); ?>" required />
                </div>
                <div class="col-md-6">
                  <label class="form-label">Reason</label>
                  <input type="text" name="reason_text" class="form-control" maxlength="255" placeholder="Wrong part, customer cancellation, warranty, etc." />
                </div>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-6" id="invoice-source-wrap">
                  <label class="form-label">Invoice</label>
                  <select name="invoice_id" id="invoice-source-select" class="form-select" data-searchable-select="1">
                    <option value="">Select invoice</option>
                    <?php foreach ($invoiceOptions as $invoiceOption): ?>
                      <option value="<?= (int) ($invoiceOption['id'] ?? 0); ?>">
                        <?= e((string) ($invoiceOption['invoice_number'] ?? '')); ?>
                        | <?= e((string) ($invoiceOption['customer_name'] ?? '')); ?>
                        | <?= e((string) ($invoiceOption['registration_no'] ?? '')); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6 d-none" id="purchase-source-wrap">
                  <label class="form-label">Purchase</label>
                  <select name="purchase_id" id="purchase-source-select" class="form-select" data-searchable-select="1">
                    <option value="">Select purchase</option>
                    <?php foreach ($purchaseOptions as $purchaseOption): ?>
                      <option value="<?= (int) ($purchaseOption['id'] ?? 0); ?>">
                        #<?= (int) ($purchaseOption['id'] ?? 0); ?>
                        | <?= e((string) ($purchaseOption['invoice_number'] ?? '-')); ?>
                        | <?= e((string) ($purchaseOption['vendor_name'] ?? '')); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div id="return-source-summary" class="alert alert-light border mt-3 mb-2 d-none"></div>
              <div class="table-responsive">
                <table class="table table-bordered table-sm mb-0" id="return-line-table">
                  <thead>
                    <tr>
                      <th>Item</th>
                      <th class="text-end">Source Qty</th>
                      <th class="text-end">Already Tagged</th>
                      <th class="text-end">Returnable</th>
                      <th class="text-end">Unit Price</th>
                      <th class="text-end">GST %</th>
                      <th style="width: 160px;">Return Qty</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr><td colspan="7" class="text-muted">Select source document to load line items.</td></tr>
                  </tbody>
                </table>
              </div>
              <div id="return-live-totals" class="border rounded p-2 mt-2 bg-light d-none">
                <div class="row g-2">
                  <div class="col-6 col-md-2">
                    <small class="text-muted d-block">Lines</small>
                    <strong id="return-live-lines">0</strong>
                  </div>
                  <div class="col-6 col-md-2">
                    <small class="text-muted d-block">Total Qty</small>
                    <strong id="return-live-qty">0.00</strong>
                  </div>
                  <div class="col-6 col-md-3">
                    <small class="text-muted d-block">Taxable Amount</small>
                    <strong id="return-live-taxable">INR 0.00</strong>
                  </div>
                  <div class="col-6 col-md-2">
                    <small class="text-muted d-block">GST Amount</small>
                    <strong id="return-live-tax">INR 0.00</strong>
                  </div>
                  <div class="col-12 col-md-3">
                    <small class="text-muted d-block">Grand Total</small>
                    <strong id="return-live-total">INR 0.00</strong>
                  </div>
                </div>
              </div>

              <div class="row g-3 mt-2">
                <div class="col-md-6">
                  <label class="form-label">Reason Detail</label>
                  <textarea name="reason_detail" class="form-control" rows="2" maxlength="5000"></textarea>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Attachment (Optional)</label>
                  <input type="file" name="return_attachment" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" />
                </div>
                <div class="col-md-12">
                  <label class="form-label">Notes</label>
                  <input type="text" name="notes" class="form-control" maxlength="255" />
                </div>
              </div>
            </div>
            <div class="card-footer d-flex justify-content-end">
              <button type="submit" class="btn btn-primary">Create &amp; Approve Return</button>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card card-outline card-secondary mb-3">
        <div class="card-header"><h3 class="card-title">Returns Ledger</h3></div>
        <div class="card-body">
          <form method="get" class="row g-2 mb-3">
            <div class="col-md-2">
              <label class="form-label">From</label>
              <input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" />
            </div>
            <div class="col-md-2">
              <label class="form-label">To</label>
              <input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" />
            </div>
            <div class="col-md-2">
              <label class="form-label">Type</label>
              <select name="return_type" class="form-select">
                <option value="">All</option>
                <?php foreach (returns_allowed_types() as $key => $label): ?>
                  <option value="<?= e($key); ?>" <?= $returnTypeFilter === $key ? 'selected' : ''; ?>><?= e($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Approval</label>
              <select name="approval_status" class="form-select">
                <option value="">All</option>
                <?php foreach (returns_allowed_approval_statuses() as $statusOption): ?>
                  <option value="<?= e($statusOption); ?>" <?= $approvalFilter === $statusOption ? 'selected' : ''; ?>><?= e($statusOption); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Search</label>
              <input type="text" name="q" class="form-control" value="<?= e($query); ?>" placeholder="Return/Invoice/Purchase/Customer/Vendor" />
            </div>
            <div class="col-md-1 d-flex align-items-end">
              <button type="submit" class="btn btn-outline-primary w-100">Apply</button>
            </div>
          </form>

          <div class="table-responsive">
            <table class="table table-bordered table-hover table-sm">
              <thead>
                <tr>
                  <th>No</th>
                  <th>Date</th>
                  <th>Type</th>
                  <th>Source</th>
                  <th>Counterparty</th>
                  <th>Approval</th>
                  <th class="text-end">Total</th>
                  <th class="text-end">Settled</th>
                  <th class="text-end">Pending</th>
                  <th style="width: 290px;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($returnsRows === []): ?>
                  <tr><td colspan="10" class="text-center text-muted">No returns found.</td></tr>
                <?php else: ?>
                  <?php foreach ($returnsRows as $row): ?>
                    <?php
                    $returnId = (int) ($row['id'] ?? 0);
                    $isPending = (string) ($row['approval_status'] ?? '') === 'PENDING';
                    $isApproved = (string) ($row['approval_status'] ?? '') === 'APPROVED';
                    $isClosed = (string) ($row['approval_status'] ?? '') === 'CLOSED';
                    $canSettleRow = $canSettle && ($isApproved || $isClosed);
                    $settleLabel = (string) ($row['return_type'] ?? '') === 'VENDOR_RETURN' ? 'Receive' : 'Pay';
                    ?>
                    <tr>
                      <td>
                        <a href="<?= e(url('modules/returns/index.php?view_id=' . $returnId)); ?>">
                          <?= e((string) ($row['return_number'] ?? '')); ?>
                        </a>
                      </td>
                      <td><?= e((string) ($row['return_date'] ?? '')); ?></td>
                      <td><?= e(str_replace('_', ' ', (string) ($row['return_type'] ?? ''))); ?></td>
                      <td>
                        <?php if (!empty($row['invoice_number'])): ?>
                          Invoice: <?= e((string) $row['invoice_number']); ?>
                        <?php elseif (!empty($row['purchase_invoice_number'])): ?>
                          Purchase: <?= e((string) $row['purchase_invoice_number']); ?>
                        <?php else: ?>
                          -
                        <?php endif; ?>
                      </td>
                      <td>
                        <?= e((string) (($row['return_type'] ?? '') === 'CUSTOMER_RETURN' ? ($row['customer_name'] ?? '-') : ($row['vendor_name'] ?? '-'))); ?>
                      </td>
                      <td>
                        <span class="badge text-bg-<?= e($isPending ? 'warning' : ($isApproved ? 'success' : ((string) ($row['approval_status'] ?? '') === 'REJECTED' ? 'danger' : 'secondary'))); ?>">
                          <?= e((string) ($row['approval_status'] ?? '')); ?>
                        </span>
                      </td>
                      <td class="text-end"><?= e(format_currency((float) ($row['total_amount'] ?? 0))); ?></td>
                      <td class="text-end"><?= e(format_currency((float) ($row['settled_amount'] ?? 0))); ?></td>
                      <td class="text-end"><?= e(format_currency((float) ($row['pending_amount'] ?? 0))); ?></td>
                      <td>
                        <div class="d-flex flex-wrap gap-1">
                          <a href="<?= e(url('modules/returns/print_return.php?id=' . $returnId)); ?>" class="btn btn-sm btn-outline-secondary" target="_blank">Print</a>
                          <a href="<?= e(url('modules/returns/index.php?view_id=' . $returnId)); ?>" class="btn btn-sm btn-outline-primary">View</a>
                          <?php if ($canSettleRow): ?>
                            <a href="<?= e(url('modules/returns/index.php?view_id=' . $returnId . '#settlement-card')); ?>" class="btn btn-sm btn-outline-info"><?= e($settleLabel); ?></a>
                          <?php endif; ?>
                          <?php if ($canApprove && $isPending): ?>
                            <form method="post" class="d-inline">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="approve_return" />
                              <input type="hidden" name="return_id" value="<?= $returnId; ?>" />
                              <button type="submit" class="btn btn-sm btn-success">Approve</button>
                            </form>
                            <form method="post" class="d-inline d-flex gap-1">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="reject_return" />
                              <input type="hidden" name="return_id" value="<?= $returnId; ?>" />
                              <input type="text" name="reject_reason" class="form-control form-control-sm" maxlength="255" placeholder="Reject reason" required />
                              <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
                            </form>
                          <?php endif; ?>
                          <?php if ($canApprove && $isApproved): ?>
                            <form method="post" class="d-inline">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="close_return" />
                              <input type="hidden" name="return_id" value="<?= $returnId; ?>" />
                              <button type="submit" class="btn btn-sm btn-outline-dark">Close</button>
                            </form>
                          <?php endif; ?>
                          <?php if ($canDeleteReturn): ?>
                            <form method="post" class="d-inline"
                                  data-safe-delete
                                  data-safe-delete-entity="return"
                                  data-safe-delete-record-field="return_id"
                                  data-safe-delete-operation="delete"
                                  data-safe-delete-reason-field="delete_reason">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="delete_return" />
                              <input type="hidden" name="return_id" value="<?= $returnId; ?>" />
                              <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <?php if (is_array($selectedReturn)): ?>
        <div class="card card-outline card-info">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Return Details: <?= e((string) ($selectedReturn['return_number'] ?? '')); ?></h3>
            <div class="d-flex gap-1">
              <a href="<?= e(url('modules/returns/print_return.php?id=' . (int) ($selectedReturn['id'] ?? 0))); ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Print</a>
              <?php if ($canDeleteReturn): ?>
                <form method="post"
                      data-safe-delete
                      data-safe-delete-entity="return"
                      data-safe-delete-record-field="return_id"
                      data-safe-delete-operation="delete"
                      data-safe-delete-reason-field="delete_reason">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="delete_return" />
                  <input type="hidden" name="return_id" value="<?= (int) ($selectedReturn['id'] ?? 0); ?>" />
                  <button type="submit" class="btn btn-sm btn-outline-danger">Delete Return</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
          <div class="card-body">
            <div class="row g-3 mb-3">
              <div class="col-md-3"><strong>Type:</strong> <?= e(str_replace('_', ' ', (string) ($selectedReturn['return_type'] ?? ''))); ?></div>
              <div class="col-md-3"><strong>Date:</strong> <?= e((string) ($selectedReturn['return_date'] ?? '')); ?></div>
              <div class="col-md-3"><strong>Approval:</strong> <?= e((string) ($selectedReturn['approval_status'] ?? '')); ?></div>
              <div class="col-md-3"><strong>Total:</strong> <?= e(format_currency((float) ($selectedReturn['total_amount'] ?? 0))); ?></div>
            </div>

            <div class="card card-outline card-secondary mb-3" id="settlement-card">
              <div class="card-header"><h3 class="card-title mb-0">Settlement</h3></div>
              <div class="card-body">
                <div class="row g-3 mb-3">
                  <div class="col-md-3"><strong>Expected Action:</strong> <?= e($selectedReturnSettlementActionLabel); ?></div>
                  <div class="col-md-3"><strong>Settled:</strong> <?= e(format_currency((float) ($selectedReturnSettlementSummary['settled_amount'] ?? 0))); ?></div>
                  <div class="col-md-3"><strong>Balance:</strong> <?= e(format_currency((float) ($selectedReturnSettlementSummary['balance_amount'] ?? 0))); ?></div>
                  <div class="col-md-3"><strong>Entries:</strong> <?= number_format((int) ($selectedReturnSettlementSummary['settlement_count'] ?? 0)); ?></div>
                </div>

                <?php if ($canSettle && $selectedReturnCanSettleStatus && (float) ($selectedReturnSettlementSummary['balance_amount'] ?? 0) > 0.009): ?>
                  <form method="post" class="row g-2 align-items-end mb-3">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="settle_return" />
                    <input type="hidden" name="return_id" value="<?= (int) ($selectedReturn['id'] ?? 0); ?>" />
                    <div class="col-md-2">
                      <label class="form-label">Date</label>
                      <input type="date" name="settlement_date" class="form-control" value="<?= e(date('Y-m-d')); ?>" required />
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Amount</label>
                      <input type="number" name="amount" class="form-control" min="0.01" max="<?= e(number_format((float) ($selectedReturnSettlementSummary['balance_amount'] ?? 0), 2, '.', '')); ?>" step="0.01" required />
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Mode</label>
                      <select name="payment_mode" class="form-select" required>
                        <?php foreach ($settlementPaymentModes as $paymentMode): ?>
                          <option value="<?= e((string) $paymentMode); ?>"><?= e((string) $paymentMode); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Reference</label>
                      <input type="text" name="reference_no" class="form-control" maxlength="100" placeholder="Cheque/UTR/Ref" />
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Notes</label>
                      <input type="text" name="notes" class="form-control" maxlength="255" placeholder="Settlement notes" />
                    </div>
                    <div class="col-12">
                      <button type="submit" class="btn btn-primary"><?= e($selectedReturnSettlementActionLabel); ?> Entry</button>
                    </div>
                  </form>
                <?php elseif (!$selectedReturnCanSettleStatus): ?>
                  <div class="alert alert-warning mb-3">Settlement is available only for approved or closed returns.</div>
                <?php else: ?>
                  <div class="alert alert-success mb-3">This return is fully settled.</div>
                <?php endif; ?>

                <div class="table-responsive">
                  <table class="table table-bordered table-sm mb-0">
                    <thead>
                      <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Mode</th>
                        <th>Reference</th>
                        <th>Notes</th>
                        <th>Expense</th>
                        <th>User</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if ($selectedReturnSettlements === []): ?>
                        <tr><td colspan="10" class="text-muted text-center">No settlement history yet.</td></tr>
                      <?php else: ?>
                        <?php foreach ($selectedReturnSettlements as $settlement): ?>
                          <?php $settlementType = (string) ($settlement['settlement_type'] ?? ''); ?>
                          <tr>
                            <td>#<?= (int) ($settlement['id'] ?? 0); ?></td>
                            <td><?= e((string) ($settlement['settlement_date'] ?? '-')); ?></td>
                            <td><?= e($settlementType === 'RECEIVE' ? 'Receive' : 'Pay'); ?></td>
                            <td><?= e(format_currency((float) ($settlement['amount'] ?? 0))); ?></td>
                            <td><?= e((string) ($settlement['payment_mode'] ?? '-')); ?></td>
                            <td><?= e((string) (($settlement['reference_no'] ?? '') !== '' ? $settlement['reference_no'] : '-')); ?></td>
                            <td><?= e((string) (($settlement['notes'] ?? '') !== '' ? $settlement['notes'] : '-')); ?></td>
                            <td><?= e((int) ($settlement['expense_id'] ?? 0) > 0 ? ('#' . (int) ($settlement['expense_id'] ?? 0)) : '-'); ?></td>
                            <td><?= e((string) (($settlement['created_by_name'] ?? '') !== '' ? $settlement['created_by_name'] : '-')); ?></td>
                            <td>
                              <?php if ($canSettle && $selectedReturnCanSettleStatus): ?>
                                <form method="post"
                                      class="d-inline"
                                      data-safe-delete
                                      data-safe-delete-entity="return_settlement"
                                      data-safe-delete-record-field="settlement_id"
                                      data-safe-delete-operation="reverse"
                                      data-safe-delete-reason-field="reverse_reason">
                                  <?= csrf_field(); ?>
                                  <input type="hidden" name="_action" value="reverse_settlement" />
                                  <input type="hidden" name="return_id" value="<?= (int) ($selectedReturn['id'] ?? 0); ?>" />
                                  <input type="hidden" name="settlement_id" value="<?= (int) ($settlement['id'] ?? 0); ?>" />
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

            <div class="table-responsive mb-3">
              <table class="table table-bordered table-sm">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Description</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Unit</th>
                    <th class="text-end">GST %</th>
                    <th class="text-end">Taxable</th>
                    <th class="text-end">Tax</th>
                    <th class="text-end">Total</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($selectedReturnItems === []): ?>
                    <tr><td colspan="8" class="text-muted text-center">No line items.</td></tr>
                  <?php else: ?>
                    <?php foreach ($selectedReturnItems as $index => $line): ?>
                      <tr>
                        <td><?= (int) $index + 1; ?></td>
                        <td><?= e((string) ($line['description'] ?? '')); ?></td>
                        <td class="text-end"><?= e(number_format((float) ($line['quantity'] ?? 0), 2)); ?></td>
                        <td class="text-end"><?= e(number_format((float) ($line['unit_price'] ?? 0), 2)); ?></td>
                        <td class="text-end"><?= e(number_format((float) ($line['gst_rate'] ?? 0), 2)); ?></td>
                        <td class="text-end"><?= e(number_format((float) ($line['taxable_amount'] ?? 0), 2)); ?></td>
                        <td class="text-end"><?= e(number_format((float) ($line['tax_amount'] ?? 0), 2)); ?></td>
                        <td class="text-end"><?= e(number_format((float) ($line['total_amount'] ?? 0), 2)); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <div class="row g-3">
              <div class="col-lg-6">
                <h5 class="mb-2">Attachments</h5>
                <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-start mb-2">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="upload_attachment" />
                  <input type="hidden" name="return_id" value="<?= (int) ($selectedReturn['id'] ?? 0); ?>" />
                  <input type="file" name="attachment_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" required />
                  <button type="submit" class="btn btn-outline-primary">Upload</button>
                </form>
                <?php if ($selectedReturnAttachments === []): ?>
                  <div class="text-muted">No attachments uploaded.</div>
                <?php else: ?>
                  <ul class="list-group">
                    <?php foreach ($selectedReturnAttachments as $attachment): ?>
                      <?php $attachmentUrl = returns_upload_url((string) ($attachment['file_path'] ?? '')); ?>
                      <li class="list-group-item d-flex justify-content-between align-items-center">
                        <a href="<?= e((string) ($attachmentUrl ?? '#')); ?>" target="_blank"><?= e((string) ($attachment['file_name'] ?? 'Attachment')); ?></a>
                        <form method="post"
                              data-safe-delete
                              data-safe-delete-entity="return_attachment"
                              data-safe-delete-record-field="attachment_id"
                              data-safe-delete-operation="delete"
                              data-safe-delete-reason-field="deletion_reason">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="delete_attachment" />
                          <input type="hidden" name="attachment_id" value="<?= (int) ($attachment['id'] ?? 0); ?>" />
                          <input type="hidden" name="return_id" value="<?= (int) ($selectedReturn['id'] ?? 0); ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
              <div class="col-lg-6">
                <h5 class="mb-2">Notes</h5>
                <p class="mb-2"><strong>Reason:</strong> <?= e((string) (($selectedReturn['reason_text'] ?? '') !== '' ? $selectedReturn['reason_text'] : '-')); ?></p>
                <p class="mb-2"><strong>Detail:</strong><br><?= nl2br(e((string) (($selectedReturn['reason_detail'] ?? '') !== '' ? $selectedReturn['reason_detail'] : '-'))); ?></p>
                <p class="mb-2"><strong>Rejected Reason:</strong> <?= e((string) (($selectedReturn['rejected_reason'] ?? '') !== '' ? $selectedReturn['rejected_reason'] : '-')); ?></p>
                <p class="mb-0"><strong>Notes:</strong> <?= e((string) (($selectedReturn['notes'] ?? '') !== '' ? $selectedReturn['notes'] : '-')); ?></p>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<script>
  (function () {
    var typeSelect = document.getElementById('return-type-select');
    var invoiceWrap = document.getElementById('invoice-source-wrap');
    var purchaseWrap = document.getElementById('purchase-source-wrap');
    var invoiceSelect = document.getElementById('invoice-source-select');
    var purchaseSelect = document.getElementById('purchase-source-select');
    var summary = document.getElementById('return-source-summary');
    var lineTable = document.getElementById('return-line-table');
    var liveTotalsWrap = document.getElementById('return-live-totals');
    var liveLinesEl = document.getElementById('return-live-lines');
    var liveQtyEl = document.getElementById('return-live-qty');
    var liveTaxableEl = document.getElementById('return-live-taxable');
    var liveTaxEl = document.getElementById('return-live-tax');
    var liveTotalEl = document.getElementById('return-live-total');
    var itemsApiUrl = <?= json_encode($itemsApiUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    if (!typeSelect || !invoiceWrap || !purchaseWrap || !lineTable) {
      return;
    }

    function round2(value) {
      return Math.round((Number(value) || 0) * 100) / 100;
    }

    function parseNumber(value) {
      var parsed = Number(String(value || '').replace(/,/g, '').trim());
      return isFinite(parsed) ? parsed : 0;
    }

    function rowAllowsDecimal(row) {
      return !row || String(row.getAttribute('data-allow-decimal') || '1') === '1';
    }

    function normalizeQuantityInput(input, commitValue) {
      if (!input) {
        return 0;
      }

      var row = input.closest('tr');
      var allowDecimal = rowAllowsDecimal(row);
      var qty = Math.max(0, parseNumber(input.value));
      var changed = false;

      if (!allowDecimal) {
        var wholeQty = Math.max(0, Math.round(qty));
        if (Math.abs(wholeQty - qty) > 0.00001) {
          qty = wholeQty;
          changed = true;
        } else {
          qty = wholeQty;
        }
      } else {
        qty = round2(qty);
      }

      var maxQty = round2(Math.max(0, parseNumber(input.getAttribute('max'))));
      if (maxQty > 0 && qty > maxQty) {
        qty = allowDecimal ? maxQty : Math.max(0, Math.floor(maxQty));
        changed = true;
      }

      if (changed || commitValue) {
        input.value = allowDecimal ? qty.toFixed(2) : String(Math.max(0, Math.round(qty)));
      }

      return qty;
    }

    function formatCurrency(value) {
      return 'INR ' + round2(value).toFixed(2);
    }

    function resetLiveTotals() {
      if (!liveTotalsWrap) {
        return;
      }
      liveTotalsWrap.classList.add('d-none');
      if (liveLinesEl) {
        liveLinesEl.textContent = '0';
      }
      if (liveQtyEl) {
        liveQtyEl.textContent = '0.00';
      }
      if (liveTaxableEl) {
        liveTaxableEl.textContent = formatCurrency(0);
      }
      if (liveTaxEl) {
        liveTaxEl.textContent = formatCurrency(0);
      }
      if (liveTotalEl) {
        liveTotalEl.textContent = formatCurrency(0);
      }
    }

    function refreshLiveTotals(commitInputs) {
      if (!liveTotalsWrap) {
        return;
      }

      var quantityInputs = lineTable.querySelectorAll('input[name="item_quantity[]"]');
      if (!quantityInputs || quantityInputs.length === 0) {
        resetLiveTotals();
        return;
      }

      var lines = 0;
      var qtyTotal = 0;
      var taxableTotal = 0;
      var taxTotal = 0;
      var grandTotal = 0;

      quantityInputs.forEach(function (input) {
        var qty = normalizeQuantityInput(input, !!commitInputs);

        if (qty <= 0.009) {
          return;
        }

        var row = input.closest('tr');
        var unitPrice = row ? round2(Math.max(0, parseNumber(row.getAttribute('data-unit-price')))) : 0;
        var gstRate = row ? round2(Math.max(0, parseNumber(row.getAttribute('data-gst-rate')))) : 0;
        var taxable = round2(qty * unitPrice);
        var tax = round2(taxable * gstRate / 100);
        var lineTotal = round2(taxable + tax);

        lines += 1;
        qtyTotal = round2(qtyTotal + qty);
        taxableTotal = round2(taxableTotal + taxable);
        taxTotal = round2(taxTotal + tax);
        grandTotal = round2(grandTotal + lineTotal);
      });

      liveTotalsWrap.classList.remove('d-none');
      if (liveLinesEl) {
        liveLinesEl.textContent = String(lines);
      }
      if (liveQtyEl) {
        liveQtyEl.textContent = qtyTotal.toFixed(2);
      }
      if (liveTaxableEl) {
        liveTaxableEl.textContent = formatCurrency(taxableTotal);
      }
      if (liveTaxEl) {
        liveTaxEl.textContent = formatCurrency(taxTotal);
      }
      if (liveTotalEl) {
        liveTotalEl.textContent = formatCurrency(grandTotal);
      }
    }

    function renderEmpty(message) {
      var tbody = lineTable.querySelector('tbody');
      if (!tbody) {
        return;
      }
      tbody.innerHTML = '<tr><td colspan=\"7\" class=\"text-muted\">' + message + '</td></tr>';
      resetLiveTotals();
    }

    function updateSourceVisibility() {
      var type = String(typeSelect.value || 'CUSTOMER_RETURN');
      var isCustomer = type === 'CUSTOMER_RETURN';
      invoiceWrap.classList.toggle('d-none', !isCustomer);
      purchaseWrap.classList.toggle('d-none', isCustomer);
      if (isCustomer && purchaseSelect) {
        purchaseSelect.value = '';
      }
      if (!isCustomer && invoiceSelect) {
        invoiceSelect.value = '';
      }
      summary.classList.add('d-none');
      summary.innerHTML = '';
      renderEmpty('Select source document to load line items.');
      resetLiveTotals();
    }

    function selectedSourceId() {
      return typeSelect.value === 'CUSTOMER_RETURN'
        ? parseInt((invoiceSelect && invoiceSelect.value) || '0', 10)
        : parseInt((purchaseSelect && purchaseSelect.value) || '0', 10);
    }

    function esc(value) {
      return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function loadItems() {
      var sourceId = selectedSourceId();
      if (!sourceId || !isFinite(sourceId) || sourceId <= 0) {
        summary.classList.add('d-none');
        summary.innerHTML = '';
        renderEmpty('Select source document to load line items.');
        resetLiveTotals();
        return;
      }

      var url = new URL(itemsApiUrl, window.location.href);
      url.searchParams.set('return_type', typeSelect.value);
      url.searchParams.set('source_id', String(sourceId));

      renderEmpty('Loading...');
      fetch(url.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (!payload || payload.ok !== true) {
            throw new Error(payload && payload.message ? payload.message : 'Unable to fetch source items.');
          }

          var source = payload.source || {};
          var items = Array.isArray(payload.items) ? payload.items : [];
          if (items.length === 0) {
            summary.classList.remove('d-none');
            summary.textContent = 'No eligible source lines found for this document.';
            renderEmpty('No eligible source lines.');
            return;
          }

          summary.classList.remove('d-none');
          if (typeSelect.value === 'CUSTOMER_RETURN') {
            summary.innerHTML = '<strong>Invoice:</strong> ' + esc(source.invoice_number || '') + ' | <strong>Customer:</strong> ' + esc(source.customer_name || '') + ' | <strong>Vehicle:</strong> ' + esc(source.registration_no || '');
          } else {
            summary.innerHTML = '<strong>Purchase:</strong> ' + esc(source.invoice_number || ('#' + sourceId)) + ' | <strong>Vendor:</strong> ' + esc(source.vendor_name || '');
          }

          var tbody = lineTable.querySelector('tbody');
          if (!tbody) {
            return;
          }
          tbody.innerHTML = '';
          items.forEach(function (item) {
            var maxQty = Number(item.max_returnable_qty || 0);
            var allowDecimal = String(item.allow_decimal_qty ? '1' : '0') === '1';
            var inputMaxQty = allowDecimal ? maxQty : Math.max(0, Math.floor(maxQty));
            var disabled = inputMaxQty <= 0 ? 'disabled' : '';
            var unitPrice = Number(item.unit_price || 0);
            var gstRate = Number(item.gst_rate || 0);
            var rowHtml = '' +
              '<tr data-unit-price=\"' + esc(unitPrice.toFixed(2)) + '\" data-gst-rate=\"' + esc(gstRate.toFixed(2)) + '\" data-allow-decimal=\"' + (allowDecimal ? '1' : '0') + '\">' +
              '<td>' + esc(item.description || '') +
                '<input type=\"hidden\" name=\"item_source_id[]\" value=\"' + esc(item.source_item_id || 0) + '\">' +
              '</td>' +
              '<td class=\"text-end\">' + esc(Number(item.source_qty || 0).toFixed(2)) + '</td>' +
              '<td class=\"text-end\">' + esc(Number(item.reserved_qty || 0).toFixed(2)) + '</td>' +
              '<td class=\"text-end\">' + esc(maxQty.toFixed(2)) + '</td>' +
              '<td class=\"text-end\">' + esc(unitPrice.toFixed(2)) + '</td>' +
              '<td class=\"text-end\">' + esc(gstRate.toFixed(2)) + '</td>' +
              '<td><input type=\"number\" step=\"' + (allowDecimal ? '0.01' : '1') + '\" min=\"0\" max=\"' + esc((allowDecimal ? inputMaxQty.toFixed(2) : String(inputMaxQty))) + '\" class=\"form-control form-control-sm\" name=\"item_quantity[]\" value=\"' + (allowDecimal ? '0.00' : '0') + '\" ' + disabled + '></td>' +
              '</tr>';
            tbody.insertAdjacentHTML('beforeend', rowHtml);
          });
          refreshLiveTotals(true);
        })
        .catch(function (error) {
          summary.classList.remove('d-none');
          summary.textContent = error && error.message ? error.message : 'Unable to load source lines.';
          renderEmpty('Unable to load source lines.');
          resetLiveTotals();
        });
    }

    typeSelect.addEventListener('change', updateSourceVisibility);
    if (invoiceSelect) {
      invoiceSelect.addEventListener('change', loadItems);
    }
    if (purchaseSelect) {
      purchaseSelect.addEventListener('change', loadItems);
    }
    lineTable.addEventListener('input', function (event) {
      var target = event && event.target;
      if (target && target.name === 'item_quantity[]') {
        refreshLiveTotals(false);
      }
    });
    lineTable.addEventListener('change', function (event) {
      var target = event && event.target;
      if (target && target.name === 'item_quantity[]') {
        refreshLiveTotals(true);
      }
    });

    updateSourceVisibility();
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
