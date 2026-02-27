<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('vendor.view');

$page_title = 'Vendor / Supplier Master';
$active_menu = 'vendors.master';
$canManage = has_permission('vendor.manage');
$companyId = active_company_id();

function vendor_master_valid_date(?string $value): bool
{
    $date = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $date));
    return checkdate($month, $day, $year);
}

function vendor_master_fetch_row(int $companyId, int $vendorId): ?array
{
    if ($companyId <= 0 || $vendorId <= 0) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT *
         FROM vendors
         WHERE id = :id
           AND company_id = :company_id
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $vendorId,
        'company_id' => $companyId,
    ]);

    return $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('vendor_error', 'You do not have permission to modify vendors.', 'danger');
        redirect('modules/vendors/index.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create') {
        $vendorCode = strtoupper(post_string('vendor_code', 40));
        $vendorName = post_string('vendor_name', 150);
        $contactPerson = post_string('contact_person', 120);
        $phone = post_string('phone', 20);
        $email = strtolower(post_string('email', 150));
        $gstin = strtoupper(post_string('gstin', 15));
        $address = post_string('address_line1', 200);
        $city = post_string('city', 80);
        $state = post_string('state', 80);
        $pincode = post_string('pincode', 10);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($vendorCode === '' || $vendorName === '') {
            flash_set('vendor_error', 'Vendor code and vendor name are required.', 'danger');
            redirect('modules/vendors/index.php');
        }

        try {
            $stmt = db()->prepare(
                'INSERT INTO vendors
                  (company_id, vendor_code, vendor_name, contact_person, phone, email, gstin, address_line1, city, state, pincode, status_code, deleted_at)
                 VALUES
                  (:company_id, :vendor_code, :vendor_name, :contact_person, :phone, :email, :gstin, :address_line1, :city, :state, :pincode, :status_code, :deleted_at)'
            );
            $stmt->execute([
                'company_id' => $companyId,
                'vendor_code' => $vendorCode,
                'vendor_name' => $vendorName,
                'contact_person' => $contactPerson !== '' ? $contactPerson : null,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'gstin' => $gstin !== '' ? $gstin : null,
                'address_line1' => $address !== '' ? $address : null,
                'city' => $city !== '' ? $city : null,
                'state' => $state !== '' ? $state : null,
                'pincode' => $pincode !== '' ? $pincode : null,
                'status_code' => $statusCode,
                'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
            ]);

            $vendorId = (int) db()->lastInsertId();
            log_audit('vendors', 'create', $vendorId, 'Created vendor ' . $vendorCode);
            flash_set('vendor_success', 'Vendor created successfully.', 'success');
        } catch (Throwable $exception) {
            flash_set('vendor_error', 'Unable to create vendor. Vendor code must be unique.', 'danger');
        }

        redirect('modules/vendors/index.php');
    }

    if ($action === 'update') {
        $vendorId = post_int('vendor_id');
        $vendorName = post_string('vendor_name', 150);
        $contactPerson = post_string('contact_person', 120);
        $phone = post_string('phone', 20);
        $email = strtolower(post_string('email', 150));
        $gstin = strtoupper(post_string('gstin', 15));
        $address = post_string('address_line1', 200);
        $city = post_string('city', 80);
        $state = post_string('state', 80);
        $pincode = post_string('pincode', 10);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        $stmt = db()->prepare(
            'UPDATE vendors
             SET vendor_name = :vendor_name,
                 contact_person = :contact_person,
                 phone = :phone,
                 email = :email,
                 gstin = :gstin,
                 address_line1 = :address_line1,
                 city = :city,
                 state = :state,
                 pincode = :pincode,
                 status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'vendor_name' => $vendorName,
            'contact_person' => $contactPerson !== '' ? $contactPerson : null,
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'gstin' => $gstin !== '' ? $gstin : null,
            'address_line1' => $address !== '' ? $address : null,
            'city' => $city !== '' ? $city : null,
            'state' => $state !== '' ? $state : null,
            'pincode' => $pincode !== '' ? $pincode : null,
            'status_code' => $statusCode,
            'id' => $vendorId,
            'company_id' => $companyId,
        ]);

        log_audit('vendors', 'update', $vendorId, 'Updated vendor');
        flash_set('vendor_success', 'Vendor updated successfully.', 'success');
        redirect('modules/vendors/index.php');
    }

    if ($action === 'change_status') {
        $vendorId = post_int('vendor_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));
        try {
            $safeDeleteValidation = null;
            $deletionReason = '';
            if ($nextStatus === 'DELETED') {
                $safeDeleteValidation = safe_delete_validate_post_confirmation('vendor', $vendorId, [
                    'operation' => 'delete',
                    'reason_field' => 'deletion_reason',
                ]);
                $deletionReason = (string) ($safeDeleteValidation['reason'] ?? '');
            }

            $vendorColumns = table_columns('vendors');
            $setParts = [
                'status_code = :status_code',
                'deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END',
            ];
            $params = [
                'status_code' => $nextStatus,
                'id' => $vendorId,
                'company_id' => $companyId,
            ];
            if (in_array('deleted_by', $vendorColumns, true)) {
                $setParts[] = 'deleted_by = CASE WHEN :status_code = "DELETED" THEN :deleted_by ELSE NULL END';
                $params['deleted_by'] = (int) ($_SESSION['user_id'] ?? 0) > 0 ? (int) $_SESSION['user_id'] : null;
            }
            if (in_array('deletion_reason', $vendorColumns, true)) {
                $setParts[] = 'deletion_reason = CASE WHEN :status_code = "DELETED" THEN :deletion_reason ELSE NULL END';
                $params['deletion_reason'] = $deletionReason !== '' ? $deletionReason : null;
            }

            $stmt = db()->prepare(
                'UPDATE vendors
                 SET ' . implode(', ', $setParts) . '
                 WHERE id = :id
                   AND company_id = :company_id'
            );
            $stmt->execute($params);

            log_audit('vendors', 'status', $vendorId, 'Changed vendor status to ' . $nextStatus);
            if (is_array($safeDeleteValidation)) {
                safe_delete_log_cascade('vendor', 'delete', $vendorId, $safeDeleteValidation, [
                    'metadata' => ['vendor_id' => $vendorId],
                ]);
            }
            flash_set('vendor_success', 'Vendor status updated.', 'success');
        } catch (Throwable $exception) {
            flash_set('vendor_error', $exception->getMessage(), 'danger');
        }
        redirect('modules/vendors/index.php');
    }

    if ($action === 'set_opening_balance') {
        $vendorId = post_int('vendor_id');
        $openingType = strtoupper(trim((string) ($_POST['opening_type'] ?? 'PAYABLE')));
        $openingAmount = ledger_round(max(0.0, (float) ($_POST['opening_amount'] ?? 0)));
        $openingDate = trim((string) ($_POST['opening_date'] ?? date('Y-m-d')));
        $openingNotes = post_string('opening_notes', 255);
        $redirectUrl = 'modules/vendors/index.php?financial_vendor_id=' . $vendorId . '&financial_action=opening#vendor-financial-actions';

        if ($vendorId <= 0 || !in_array($openingType, ['PAYABLE', 'ADVANCE'], true) || !vendor_master_valid_date($openingDate)) {
            flash_set('vendor_error', 'Invalid opening balance payload.', 'danger');
            redirect($redirectUrl);
        }

        $vendor = vendor_master_fetch_row($companyId, $vendorId);
        if (!$vendor) {
            flash_set('vendor_error', 'Vendor not found for opening balance update.', 'danger');
            redirect('modules/vendors/index.php');
        }
        if (strtoupper(trim((string) ($vendor['status_code'] ?? 'ACTIVE'))) === 'DELETED') {
            flash_set('vendor_error', 'Opening balance cannot be changed for deleted vendors.', 'danger');
            redirect('modules/vendors/index.php');
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            ledger_set_vendor_opening_balance(
                $pdo,
                $companyId,
                $vendorId,
                $openingAmount,
                $openingType,
                $openingDate,
                active_garage_id(),
                $userId > 0 ? $userId : null,
                $openingNotes !== '' ? $openingNotes : null
            );

            log_audit('vendors', 'opening_balance', $vendorId, 'Updated opening balance for vendor ' . (string) ($vendor['vendor_name'] ?? ('#' . $vendorId)), [
                'entity' => 'vendor_opening_balance',
                'source' => 'UI',
                'metadata' => [
                    'vendor_id' => $vendorId,
                    'opening_type' => $openingType,
                    'opening_amount' => $openingAmount,
                    'opening_date' => $openingDate,
                ],
            ]);

            $pdo->commit();
            flash_set('vendor_success', $openingAmount > 0.009 ? 'Vendor opening balance saved successfully.' : 'Vendor opening balance cleared successfully.', 'success');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('vendor_error', $exception->getMessage(), 'danger');
        }

        redirect($redirectUrl);
    }

    if ($action === 'record_balance_settlement') {
        $vendorId = post_int('vendor_id');
        $settlementDirection = strtoupper(trim((string) ($_POST['settlement_direction'] ?? 'PAY')));
        $settlementAmount = ledger_round(max(0.0, (float) ($_POST['settlement_amount'] ?? 0)));
        $settlementDate = trim((string) ($_POST['settlement_date'] ?? date('Y-m-d')));
        $paymentMode = finance_normalize_payment_mode((string) ($_POST['payment_mode'] ?? 'BANK_TRANSFER'));
        $settlementNotes = post_string('settlement_notes', 255);
        $redirectAction = strtolower($settlementDirection) === 'collect' ? 'collect' : 'pay';
        $redirectUrl = 'modules/vendors/index.php?financial_vendor_id=' . $vendorId . '&financial_action=' . $redirectAction . '#vendor-financial-actions';

        if ($vendorId <= 0 || !in_array($settlementDirection, ['PAY', 'COLLECT'], true) || !vendor_master_valid_date($settlementDate) || $settlementAmount <= 0.0) {
            flash_set('vendor_error', 'Invalid settlement payload.', 'danger');
            redirect($redirectUrl);
        }

        $vendor = vendor_master_fetch_row($companyId, $vendorId);
        if (!$vendor) {
            flash_set('vendor_error', 'Vendor not found for settlement.', 'danger');
            redirect('modules/vendors/index.php');
        }
        if (strtoupper(trim((string) ($vendor['status_code'] ?? 'ACTIVE'))) === 'DELETED') {
            flash_set('vendor_error', 'Settlement cannot be recorded for deleted vendors.', 'danger');
            redirect('modules/vendors/index.php');
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $currentBalance = ledger_vendor_net_balance($pdo, $companyId, $vendorId);
            if ($settlementDirection === 'PAY' && $currentBalance <= 0.009) {
                throw new RuntimeException('No payable balance available to pay.');
            }
            if ($settlementDirection === 'COLLECT' && $currentBalance >= -0.009) {
                throw new RuntimeException('No advance/recoverable balance available to collect.');
            }

            $maxSettlable = abs($currentBalance);
            if ($settlementAmount > $maxSettlable + 0.01) {
                throw new RuntimeException('Settlement amount exceeds available balance: ' . number_format($maxSettlable, 2));
            }

            ledger_post_vendor_balance_settlement(
                $pdo,
                $companyId,
                $vendorId,
                $settlementAmount,
                $settlementDirection,
                $settlementDate,
                $paymentMode,
                active_garage_id(),
                $userId > 0 ? $userId : null,
                $settlementNotes !== '' ? $settlementNotes : null
            );

            log_audit('vendors', 'balance_settlement', $vendorId, 'Recorded vendor balance settlement for ' . (string) ($vendor['vendor_name'] ?? ('#' . $vendorId)), [
                'entity' => 'vendor_balance_settlement',
                'source' => 'UI',
                'metadata' => [
                    'vendor_id' => $vendorId,
                    'direction' => $settlementDirection,
                    'amount' => $settlementAmount,
                    'payment_mode' => $paymentMode,
                    'settlement_date' => $settlementDate,
                ],
            ]);

            $pdo->commit();
            flash_set('vendor_success', 'Vendor balance settlement recorded.', 'success');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('vendor_error', $exception->getMessage(), 'danger');
        }

        redirect($redirectUrl);
    }
}

$editId = get_int('edit_id');
$editVendor = null;
if ($editId > 0) {
    $editStmt = db()->prepare('SELECT * FROM vendors WHERE id = :id AND company_id = :company_id LIMIT 1');
    $editStmt->execute([
        'id' => $editId,
        'company_id' => $companyId,
    ]);
    $editVendor = $editStmt->fetch() ?: null;
}

$ledgerReady = table_columns('ledger_entries') !== []
    && table_columns('ledger_journals') !== []
    && table_columns('chart_of_accounts') !== [];
$vendorLedgerBalanceExpr = $ledgerReady
    ? '(SELECT COALESCE(SUM(le.credit_amount), 0) - COALESCE(SUM(le.debit_amount), 0)
        FROM ledger_entries le
        INNER JOIN ledger_journals lj ON lj.id = le.journal_id
        INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
        WHERE lj.company_id = v.company_id
          AND le.party_type = "VENDOR"
          AND le.party_id = v.id
          AND coa.code = "2100")'
    : '0';
$financialVendorId = get_int('financial_vendor_id');
$financialAction = strtolower(trim((string) ($_GET['financial_action'] ?? 'opening')));
if (!in_array($financialAction, ['opening', 'pay', 'collect'], true)) {
    $financialAction = 'opening';
}
$financialVendor = $financialVendorId > 0 ? vendor_master_fetch_row($companyId, $financialVendorId) : null;
if (!$financialVendor) {
    $financialVendorId = 0;
}

$selectedVendorBalance = 0.0;
$selectedVendorLedgers = [];
if ($ledgerReady && $financialVendorId > 0) {
    $selectedVendorBalance = ledger_vendor_net_balance(db(), $companyId, $financialVendorId);
    $ledgerStmt = db()->prepare(
        'SELECT lj.id AS journal_id,
                lj.journal_date,
                lj.reference_type,
                lj.reference_id,
                lj.narration,
                COALESCE(SUM(CASE
                    WHEN coa.code = "2100"
                     AND le.party_type = "VENDOR"
                     AND le.party_id = :vendor_id
                    THEN le.credit_amount - le.debit_amount
                    ELSE 0
                END), 0) AS net_delta
         FROM ledger_journals lj
         INNER JOIN ledger_entries le ON le.journal_id = lj.id
         INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
         WHERE lj.company_id = :company_id
           AND (
                (le.party_type = "VENDOR" AND le.party_id = :vendor_id AND lj.reference_type IN ("VENDOR_OPENING_BALANCE", "VENDOR_OPENING_BALANCE_REV"))
                OR
                (le.party_type = "VENDOR" AND le.party_id = :vendor_id AND lj.reference_type = "VENDOR_BALANCE_SETTLEMENT")
           )
         GROUP BY lj.id, lj.journal_date, lj.reference_type, lj.reference_id, lj.narration
         ORDER BY lj.id DESC
         LIMIT 12'
    );
    $ledgerStmt->execute([
        'company_id' => $companyId,
        'vendor_id' => $financialVendorId,
    ]);
    $selectedVendorLedgers = $ledgerStmt->fetchAll();
}

$vendorsStmt = db()->prepare(
    'SELECT v.*,
            (SELECT COUNT(*) FROM parts p WHERE p.vendor_id = v.id) AS linked_parts,
            ' . $vendorLedgerBalanceExpr . ' AS ledger_balance
     FROM vendors v
     WHERE v.company_id = :company_id
     ORDER BY v.id DESC'
);
$vendorsStmt->execute(['company_id' => $companyId]);
$vendors = $vendorsStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Vendor / Supplier Master</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Vendors</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editVendor ? 'Edit Vendor' : 'Add Vendor'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editVendor ? 'update' : 'create'; ?>" />
              <input type="hidden" name="vendor_id" value="<?= (int) ($editVendor['id'] ?? 0); ?>" />

              <div class="col-md-2">
                <label class="form-label">Vendor Code</label>
                <input type="text" name="vendor_code" class="form-control" <?= $editVendor ? 'readonly' : 'required'; ?> value="<?= e((string) ($editVendor['vendor_code'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Vendor Name</label>
                <input type="text" name="vendor_name" class="form-control" required value="<?= e((string) ($editVendor['vendor_name'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Contact Person</label>
                <input type="text" name="contact_person" class="form-control" value="<?= e((string) ($editVendor['contact_person'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editVendor['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-3">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= e((string) ($editVendor['phone'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= e((string) ($editVendor['email'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">GSTIN</label>
                <input type="text" name="gstin" class="form-control" maxlength="15" value="<?= e((string) ($editVendor['gstin'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Pincode</label>
                <input type="text" name="pincode" class="form-control" maxlength="10" value="<?= e((string) ($editVendor['pincode'] ?? '')); ?>" />
              </div>
              <div class="col-md-12">
                <label class="form-label">Address</label>
                <input type="text" name="address_line1" class="form-control" value="<?= e((string) ($editVendor['address_line1'] ?? '')); ?>" />
              </div>
              <div class="col-md-6">
                <label class="form-label">City</label>
                <input type="text" name="city" class="form-control" value="<?= e((string) ($editVendor['city'] ?? '')); ?>" />
              </div>
              <div class="col-md-6">
                <label class="form-label">State</label>
                <input type="text" name="state" class="form-control" value="<?= e((string) ($editVendor['state'] ?? '')); ?>" />
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editVendor ? 'Update Vendor' : 'Create Vendor'; ?></button>
              <?php if ($editVendor): ?>
                <a href="<?= e(url('modules/vendors/index.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Vendor List</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>Code</th>
                <th>Vendor</th>
                <th>Contact</th>
                <th>GSTIN</th>
                <th>Linked Parts</th>
                <th>Ledger Balance</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($vendors)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No vendors found.</td></tr>
              <?php else: ?>
                <?php foreach ($vendors as $vendor): ?>
                  <?php
                    $vendorId = (int) ($vendor['id'] ?? 0);
                    $vendorLedgerBalance = ledger_round((float) ($vendor['ledger_balance'] ?? 0));
                    $isVendorPayable = $vendorLedgerBalance > 0.009;
                    $isVendorAdvance = $vendorLedgerBalance < -0.009;
                  ?>
                  <tr>
                    <td><code><?= e((string) $vendor['vendor_code']); ?></code></td>
                    <td><?= e((string) $vendor['vendor_name']); ?><br><small class="text-muted"><?= e((string) ($vendor['city'] ?? '-')); ?></small></td>
                    <td><?= e((string) ($vendor['phone'] ?? '-')); ?><br><small class="text-muted"><?= e((string) ($vendor['email'] ?? '-')); ?></small></td>
                    <td><?= e((string) ($vendor['gstin'] ?? '-')); ?></td>
                    <td><?= (int) $vendor['linked_parts']; ?></td>
                    <td>
                      <div class="fw-semibold <?= $isVendorPayable ? 'text-danger' : ($isVendorAdvance ? 'text-success' : 'text-muted'); ?>">
                        <?= e(format_currency(abs($vendorLedgerBalance))); ?>
                      </div>
                      <small class="text-muted">
                        <?= $isVendorPayable ? 'Payable' : ($isVendorAdvance ? 'Advance' : 'Nil'); ?>
                      </small>
                    </td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $vendor['status_code'])); ?>"><?= e((string) $vendor['status_code']); ?></span></td>
                    <td class="d-flex gap-1">
                      <?php if ($canManage && $ledgerReady): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('modules/reports/vendor_ledger.php?vendor_id=' . $vendorId)); ?>">Ledger</a>
                        <?php if ((string) ($vendor['status_code'] ?? '') !== 'DELETED'): ?>
                          <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/vendors/index.php?financial_vendor_id=' . $vendorId . '&financial_action=opening#vendor-financial-actions')); ?>">Opening</a>
                          <?php if ($isVendorPayable): ?>
                            <a class="btn btn-sm btn-outline-warning" href="<?= e(url('modules/vendors/index.php?financial_vendor_id=' . $vendorId . '&financial_action=pay#vendor-financial-actions')); ?>">Pay</a>
                          <?php elseif ($isVendorAdvance): ?>
                            <a class="btn btn-sm btn-outline-success" href="<?= e(url('modules/vendors/index.php?financial_vendor_id=' . $vendorId . '&financial_action=collect#vendor-financial-actions')); ?>">Collect</a>
                          <?php endif; ?>
                        <?php endif; ?>
                      <?php endif; ?>
                      <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/vendors/index.php?edit_id=' . $vendorId)); ?>">Edit</a>
                      <?php if ($canManage): ?>
                        <form method="post" class="d-inline" data-confirm="Change vendor status?">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="change_status" />
                          <input type="hidden" name="vendor_id" value="<?= $vendorId; ?>" />
                          <input type="hidden" name="next_status" value="<?= e(((string) $vendor['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $vendor['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                        </form>
                        <?php if ((string) $vendor['status_code'] !== 'DELETED'): ?>
                          <form method="post" class="d-inline"
                                data-safe-delete
                                data-safe-delete-entity="vendor"
                                data-safe-delete-record-field="vendor_id"
                                data-safe-delete-operation="delete"
                                data-safe-delete-reason-field="deletion_reason">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="vendor_id" value="<?= $vendorId; ?>" />
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

      <?php if ($canManage): ?>
        <div class="card mt-3" id="vendor-financial-actions">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Vendor Opening Balance & Settlement</h3>
            <?php if ($financialVendorId > 0): ?>
              <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('modules/reports/vendor_ledger.php?vendor_id=' . $financialVendorId)); ?>">Open Vendor Ledger Report</a>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if (!$ledgerReady): ?>
              <div class="alert alert-warning mb-0">Ledger tables are not ready. Opening balance and settlement actions are unavailable.</div>
            <?php elseif ($financialVendorId <= 0 || !$financialVendor): ?>
              <div class="alert alert-info mb-0">Select any vendor row and click <strong>Opening</strong>, <strong>Pay</strong>, or <strong>Collect</strong> to manage balances.</div>
            <?php else: ?>
              <?php
                $selectedVendorName = (string) ($financialVendor['vendor_name'] ?? ('Vendor #' . $financialVendorId));
                $selectedBalanceAbs = abs($selectedVendorBalance);
                $selectedIsPayable = $selectedVendorBalance > 0.009;
                $selectedIsAdvance = $selectedVendorBalance < -0.009;
                $openingTypeDefault = $selectedIsAdvance ? 'ADVANCE' : 'PAYABLE';
                $settlementDirectionDefault = $financialAction === 'collect'
                    ? 'COLLECT'
                    : ($financialAction === 'pay' ? 'PAY' : ($selectedIsAdvance ? 'COLLECT' : 'PAY'));
                $settlementBtnDisabled = $selectedBalanceAbs <= 0.009 ? 'disabled' : '';
              ?>
              <div class="row g-3 mb-3">
                <div class="col-md-4">
                  <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1">Vendor</div>
                    <div class="fw-semibold"><?= e($selectedVendorName); ?> (#<?= (int) $financialVendorId; ?>)</div>
                    <div class="text-muted small mt-2">Current Net Balance</div>
                    <div class="<?= $selectedIsPayable ? 'text-danger' : ($selectedIsAdvance ? 'text-success' : 'text-muted'); ?>">
                      <?= e(format_currency($selectedBalanceAbs)); ?>
                      <small>(<?= $selectedIsPayable ? 'Payable' : ($selectedIsAdvance ? 'Advance' : 'Nil'); ?>)</small>
                    </div>
                  </div>
                </div>
                <div class="col-md-8">
                  <div class="row g-3">
                    <div class="col-lg-6">
                      <form method="post" class="border rounded p-3 h-100">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="_action" value="set_opening_balance" />
                        <input type="hidden" name="vendor_id" value="<?= (int) $financialVendorId; ?>" />
                        <h6 class="mb-3">Set Opening Balance</h6>
                        <div class="row g-2">
                          <div class="col-6">
                            <label class="form-label">Type</label>
                            <select name="opening_type" class="form-select" required>
                              <option value="PAYABLE" <?= $openingTypeDefault === 'PAYABLE' ? 'selected' : ''; ?>>Payable</option>
                              <option value="ADVANCE" <?= $openingTypeDefault === 'ADVANCE' ? 'selected' : ''; ?>>Advance</option>
                            </select>
                          </div>
                          <div class="col-6">
                            <label class="form-label">Amount</label>
                            <input type="number" name="opening_amount" class="form-control" min="0" step="0.01" value="<?= e(number_format($selectedBalanceAbs, 2, '.', '')); ?>" required>
                          </div>
                          <div class="col-6">
                            <label class="form-label">Date</label>
                            <input type="date" name="opening_date" class="form-control" value="<?= e(date('Y-m-d')); ?>" required>
                          </div>
                          <div class="col-6">
                            <label class="form-label">Notes</label>
                            <input type="text" name="opening_notes" class="form-control" maxlength="255" placeholder="Optional note">
                          </div>
                        </div>
                        <div class="mt-3">
                          <button type="submit" class="btn btn-primary btn-sm">Save Opening</button>
                        </div>
                      </form>
                    </div>
                    <div class="col-lg-6">
                      <form method="post" class="border rounded p-3 h-100">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="_action" value="record_balance_settlement" />
                        <input type="hidden" name="vendor_id" value="<?= (int) $financialVendorId; ?>" />
                        <h6 class="mb-3">Settle Balance (Pay / Collect)</h6>
                        <div class="row g-2">
                          <div class="col-6">
                            <label class="form-label">Direction</label>
                            <select name="settlement_direction" class="form-select" required>
                              <option value="PAY" <?= $settlementDirectionDefault === 'PAY' ? 'selected' : ''; ?>>Pay</option>
                              <option value="COLLECT" <?= $settlementDirectionDefault === 'COLLECT' ? 'selected' : ''; ?>>Collect</option>
                            </select>
                          </div>
                          <div class="col-6">
                            <label class="form-label">Amount</label>
                            <input type="number" name="settlement_amount" class="form-control" min="0.01" step="0.01" value="<?= e(number_format($selectedBalanceAbs, 2, '.', '')); ?>" required>
                          </div>
                          <div class="col-6">
                            <label class="form-label">Date</label>
                            <input type="date" name="settlement_date" class="form-control" value="<?= e(date('Y-m-d')); ?>" required>
                          </div>
                          <div class="col-6">
                            <label class="form-label">Mode</label>
                            <select name="payment_mode" class="form-select" required>
                              <?php foreach (finance_payment_modes() as $mode): ?>
                                <option value="<?= e($mode); ?>" <?= $mode === 'BANK_TRANSFER' ? 'selected' : ''; ?>><?= e($mode); ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div class="col-12">
                            <label class="form-label">Notes</label>
                            <input type="text" name="settlement_notes" class="form-control" maxlength="255" placeholder="Optional note">
                          </div>
                        </div>
                        <div class="mt-3">
                          <button type="submit" class="btn btn-success btn-sm" <?= $settlementBtnDisabled; ?>>Record Settlement</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              </div>

              <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead>
                    <tr>
                      <th>Journal #</th>
                      <th>Date</th>
                      <th>Reference Type</th>
                      <th>Narration</th>
                      <th class="text-end">Net Delta</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($selectedVendorLedgers)): ?>
                      <tr><td colspan="5" class="text-center text-muted py-3">No opening/settlement ledger entries for this vendor yet.</td></tr>
                    <?php else: ?>
                      <?php foreach ($selectedVendorLedgers as $ledgerRow): ?>
                        <?php $netDelta = ledger_round((float) ($ledgerRow['net_delta'] ?? 0)); ?>
                        <tr>
                          <td>#<?= (int) ($ledgerRow['journal_id'] ?? 0); ?></td>
                          <td><?= e((string) ($ledgerRow['journal_date'] ?? '-')); ?></td>
                          <td><?= e((string) ($ledgerRow['reference_type'] ?? '-')); ?></td>
                          <td><?= e((string) ($ledgerRow['narration'] ?? '-')); ?></td>
                          <td class="text-end <?= $netDelta > 0.009 ? 'text-danger' : ($netDelta < -0.009 ? 'text-success' : 'text-muted'); ?>">
                            <?= e(number_format($netDelta, 2)); ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
