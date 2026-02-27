<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('customer.view');

$page_title = 'Customer Master';
$active_menu = 'customers';
$canManage = has_permission('customer.manage');
$canVehicleManage = has_permission('vehicle.view') && has_permission('vehicle.manage');
$companyId = active_company_id();

function customer_master_valid_date(?string $value): bool
{
    $date = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $date));
    return checkdate($month, $day, $year);
}

function customer_master_fetch_row(int $companyId, int $customerId): ?array
{
    if ($companyId <= 0 || $customerId <= 0) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT *
         FROM customers
         WHERE id = :id
           AND company_id = :company_id
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $customerId,
        'company_id' => $companyId,
    ]);

    return $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('customer_error', 'You do not have permission to modify customer master.', 'danger');
        redirect('modules/customers/index.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create') {
        $fullName = post_string('full_name', 150);
        $phone = post_string('phone', 20);
        $altPhone = post_string('alt_phone', 20);
        $email = strtolower(post_string('email', 150));
        $gstin = strtoupper(post_string('gstin', 15));
        $address1 = post_string('address_line1', 200);
        $address2 = post_string('address_line2', 200);
        $city = post_string('city', 80);
        $state = post_string('state', 80);
        $pincode = post_string('pincode', 10);
        $notes = post_string('notes', 2000);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        $createAndAddVehicle = ((string) ($_POST['create_and_add_vehicle'] ?? '0')) === '1';

        if ($fullName === '' || $phone === '') {
            flash_set('customer_error', 'Customer name and phone are required.', 'danger');
            redirect('modules/customers/index.php');
        }

        $isActive = $statusCode === 'ACTIVE' ? 1 : 0;

        $stmt = db()->prepare(
            'INSERT INTO customers
              (company_id, created_by, full_name, phone, alt_phone, email, gstin, address_line1, address_line2, city, state, pincode, notes, is_active, status_code, deleted_at)
             VALUES
              (:company_id, :created_by, :full_name, :phone, :alt_phone, :email, :gstin, :address_line1, :address_line2, :city, :state, :pincode, :notes, :is_active, :status_code, :deleted_at)'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'created_by' => (int) ($_SESSION['user_id'] ?? 0),
            'full_name' => $fullName,
            'phone' => $phone,
            'alt_phone' => $altPhone !== '' ? $altPhone : null,
            'email' => $email !== '' ? $email : null,
            'gstin' => $gstin !== '' ? $gstin : null,
            'address_line1' => $address1 !== '' ? $address1 : null,
            'address_line2' => $address2 !== '' ? $address2 : null,
            'city' => $city !== '' ? $city : null,
            'state' => $state !== '' ? $state : null,
            'pincode' => $pincode !== '' ? $pincode : null,
            'notes' => $notes !== '' ? $notes : null,
            'is_active' => $isActive,
            'status_code' => $statusCode,
            'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
        ]);

        $customerId = (int) db()->lastInsertId();
        add_customer_history($customerId, 'CREATE', 'Customer created', [
            'full_name' => $fullName,
            'phone' => $phone,
            'status_code' => $statusCode,
        ]);
        log_audit('customers', 'create', $customerId, 'Created customer ' . $fullName);

        if ($createAndAddVehicle) {
            if ($canVehicleManage) {
                flash_set('vehicle_success', 'Customer created. Add vehicle details now.', 'success');
                redirect('modules/vehicles/index.php?prefill_customer_id=' . $customerId);
            }

            flash_set('customer_warning', 'Customer created, but you do not have permission to add vehicles.', 'warning');
            redirect('modules/customers/index.php');
        }

        flash_set('customer_success', 'Customer created successfully.', 'success');
        redirect('modules/customers/index.php');
    }

    if ($action === 'update') {
        $customerId = post_int('customer_id');
        $fullName = post_string('full_name', 150);
        $phone = post_string('phone', 20);
        $altPhone = post_string('alt_phone', 20);
        $email = strtolower(post_string('email', 150));
        $gstin = strtoupper(post_string('gstin', 15));
        $address1 = post_string('address_line1', 200);
        $address2 = post_string('address_line2', 200);
        $city = post_string('city', 80);
        $state = post_string('state', 80);
        $pincode = post_string('pincode', 10);
        $notes = post_string('notes', 2000);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($customerId <= 0 || $fullName === '' || $phone === '') {
            flash_set('customer_error', 'Invalid customer payload.', 'danger');
            redirect('modules/customers/index.php');
        }

        $isActive = $statusCode === 'ACTIVE' ? 1 : 0;

        $stmt = db()->prepare(
            'UPDATE customers
             SET full_name = :full_name,
                 phone = :phone,
                 alt_phone = :alt_phone,
                 email = :email,
                 gstin = :gstin,
                 address_line1 = :address_line1,
                 address_line2 = :address_line2,
                 city = :city,
                 state = :state,
                 pincode = :pincode,
                 notes = :notes,
                 is_active = :is_active,
                 status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'full_name' => $fullName,
            'phone' => $phone,
            'alt_phone' => $altPhone !== '' ? $altPhone : null,
            'email' => $email !== '' ? $email : null,
            'gstin' => $gstin !== '' ? $gstin : null,
            'address_line1' => $address1 !== '' ? $address1 : null,
            'address_line2' => $address2 !== '' ? $address2 : null,
            'city' => $city !== '' ? $city : null,
            'state' => $state !== '' ? $state : null,
            'pincode' => $pincode !== '' ? $pincode : null,
            'notes' => $notes !== '' ? $notes : null,
            'is_active' => $isActive,
            'status_code' => $statusCode,
            'id' => $customerId,
            'company_id' => $companyId,
        ]);

        add_customer_history($customerId, 'UPDATE', 'Customer details updated', [
            'full_name' => $fullName,
            'phone' => $phone,
            'status_code' => $statusCode,
        ]);
        log_audit('customers', 'update', $customerId, 'Updated customer ' . $fullName);

        flash_set('customer_success', 'Customer updated successfully.', 'success');
        redirect('modules/customers/index.php');
    }

    if ($action === 'change_status') {
        $customerId = post_int('customer_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));

        if ($customerId <= 0) {
            flash_set('customer_error', 'Invalid customer selected.', 'danger');
            redirect('modules/customers/index.php');
        }

        try {
            $safeDeleteValidation = null;
            $deletionReason = '';
            if ($nextStatus === 'DELETED') {
                $safeDeleteValidation = safe_delete_validate_post_confirmation('customer', $customerId, [
                    'operation' => 'delete',
                    'reason_field' => 'deletion_reason',
                ]);
                $deletionReason = (string) ($safeDeleteValidation['reason'] ?? '');
            }

            $isActive = $nextStatus === 'ACTIVE' ? 1 : 0;
            $customerColumns = table_columns('customers');
            $setParts = [
                'is_active = :is_active',
                'status_code = :status_code',
                'deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END',
            ];
            $params = [
                'is_active' => $isActive,
                'status_code' => $nextStatus,
                'id' => $customerId,
                'company_id' => $companyId,
            ];
            if (in_array('deleted_by', $customerColumns, true)) {
                $setParts[] = 'deleted_by = CASE WHEN :status_code = "DELETED" THEN :deleted_by ELSE NULL END';
                $params['deleted_by'] = (int) ($_SESSION['user_id'] ?? 0) > 0 ? (int) $_SESSION['user_id'] : null;
            }
            if (in_array('deletion_reason', $customerColumns, true)) {
                $setParts[] = 'deletion_reason = CASE WHEN :status_code = "DELETED" THEN :deletion_reason ELSE NULL END';
                $params['deletion_reason'] = $deletionReason !== '' ? $deletionReason : null;
            }

            $stmt = db()->prepare(
                'UPDATE customers
                 SET ' . implode(', ', $setParts) . '
                 WHERE id = :id
                   AND company_id = :company_id'
            );
            $stmt->execute($params);

            $historyMeta = ['status_code' => $nextStatus];
            if ($deletionReason !== '') {
                $historyMeta['deletion_reason'] = $deletionReason;
            }
            add_customer_history($customerId, 'STATUS', 'Status changed to ' . $nextStatus, $historyMeta);
            log_audit('customers', 'status', $customerId, 'Changed customer status to ' . $nextStatus);
            if (is_array($safeDeleteValidation)) {
                safe_delete_log_cascade('customer', 'delete', $customerId, $safeDeleteValidation, [
                    'metadata' => ['customer_id' => $customerId],
                ]);
            }

            flash_set('customer_success', 'Customer status updated.', 'success');
        } catch (Throwable $exception) {
            flash_set('customer_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/customers/index.php');
    }

    if ($action === 'set_opening_balance') {
        $customerId = post_int('customer_id');
        $openingType = strtoupper(trim((string) ($_POST['opening_type'] ?? 'RECEIVABLE')));
        $openingAmount = ledger_round(max(0.0, (float) ($_POST['opening_amount'] ?? 0)));
        $openingDate = trim((string) ($_POST['opening_date'] ?? date('Y-m-d')));
        $openingNotes = post_string('opening_notes', 255);
        $redirectUrl = 'modules/customers/index.php?financial_customer_id=' . $customerId . '&financial_action=opening#customer-financial-actions';

        if ($customerId <= 0 || !in_array($openingType, ['RECEIVABLE', 'ADVANCE'], true) || !customer_master_valid_date($openingDate)) {
            flash_set('customer_error', 'Invalid opening balance payload.', 'danger');
            redirect($redirectUrl);
        }

        $customer = customer_master_fetch_row($companyId, $customerId);
        if (!$customer) {
            flash_set('customer_error', 'Customer not found for opening balance update.', 'danger');
            redirect('modules/customers/index.php');
        }
        if (strtoupper(trim((string) ($customer['status_code'] ?? 'ACTIVE'))) === 'DELETED') {
            flash_set('customer_error', 'Opening balance cannot be changed for deleted customers.', 'danger');
            redirect('modules/customers/index.php');
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            ledger_set_customer_opening_balance(
                $pdo,
                $companyId,
                $customerId,
                $openingAmount,
                $openingType,
                $openingDate,
                active_garage_id(),
                $userId > 0 ? $userId : null,
                $openingNotes !== '' ? $openingNotes : null
            );

            add_customer_history($customerId, 'OPENING_BALANCE', 'Customer opening balance updated', [
                'opening_type' => $openingType,
                'opening_amount' => $openingAmount,
                'opening_date' => $openingDate,
            ]);
            log_audit('customers', 'opening_balance', $customerId, 'Updated opening balance for customer ' . (string) ($customer['full_name'] ?? ('#' . $customerId)), [
                'entity' => 'customer_opening_balance',
                'source' => 'UI',
                'metadata' => [
                    'customer_id' => $customerId,
                    'opening_type' => $openingType,
                    'opening_amount' => $openingAmount,
                    'opening_date' => $openingDate,
                ],
            ]);

            $pdo->commit();
            flash_set('customer_success', $openingAmount > 0.009 ? 'Opening balance saved successfully.' : 'Opening balance cleared successfully.', 'success');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('customer_error', $exception->getMessage(), 'danger');
        }

        redirect($redirectUrl);
    }

    if ($action === 'record_balance_settlement') {
        $customerId = post_int('customer_id');
        $settlementDirection = strtoupper(trim((string) ($_POST['settlement_direction'] ?? 'COLLECT')));
        $settlementAmount = ledger_round(max(0.0, (float) ($_POST['settlement_amount'] ?? 0)));
        $settlementDate = trim((string) ($_POST['settlement_date'] ?? date('Y-m-d')));
        $paymentMode = finance_normalize_payment_mode((string) ($_POST['payment_mode'] ?? 'CASH'));
        $settlementNotes = post_string('settlement_notes', 255);
        $redirectAction = strtolower($settlementDirection) === 'PAY' ? 'pay' : 'collect';
        $redirectUrl = 'modules/customers/index.php?financial_customer_id=' . $customerId . '&financial_action=' . $redirectAction . '#customer-financial-actions';

        if ($customerId <= 0 || !in_array($settlementDirection, ['COLLECT', 'PAY'], true) || !customer_master_valid_date($settlementDate) || $settlementAmount <= 0.0) {
            flash_set('customer_error', 'Invalid settlement payload.', 'danger');
            redirect($redirectUrl);
        }

        $customer = customer_master_fetch_row($companyId, $customerId);
        if (!$customer) {
            flash_set('customer_error', 'Customer not found for settlement.', 'danger');
            redirect('modules/customers/index.php');
        }
        if (strtoupper(trim((string) ($customer['status_code'] ?? 'ACTIVE'))) === 'DELETED') {
            flash_set('customer_error', 'Settlement cannot be recorded for deleted customers.', 'danger');
            redirect('modules/customers/index.php');
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $currentBalance = ledger_customer_net_balance($pdo, $companyId, $customerId);
            if ($settlementDirection === 'COLLECT' && $currentBalance <= 0.009) {
                throw new RuntimeException('No receivable balance available to collect.');
            }
            if ($settlementDirection === 'PAY' && $currentBalance >= -0.009) {
                throw new RuntimeException('No advance/payable balance available to pay.');
            }

            $maxSettlable = abs($currentBalance);
            if ($settlementAmount > $maxSettlable + 0.01) {
                throw new RuntimeException('Settlement amount exceeds available balance: ' . number_format($maxSettlable, 2));
            }

            ledger_post_customer_balance_settlement(
                $pdo,
                $companyId,
                $customerId,
                $settlementAmount,
                $settlementDirection,
                $settlementDate,
                $paymentMode,
                active_garage_id(),
                $userId > 0 ? $userId : null,
                $settlementNotes !== '' ? $settlementNotes : null
            );

            add_customer_history($customerId, 'BALANCE_SETTLEMENT', 'Customer balance settlement recorded', [
                'direction' => $settlementDirection,
                'amount' => $settlementAmount,
                'payment_mode' => $paymentMode,
                'settlement_date' => $settlementDate,
            ]);
            log_audit('customers', 'balance_settlement', $customerId, 'Recorded customer balance settlement for ' . (string) ($customer['full_name'] ?? ('#' . $customerId)), [
                'entity' => 'customer_balance_settlement',
                'source' => 'UI',
                'metadata' => [
                    'customer_id' => $customerId,
                    'direction' => $settlementDirection,
                    'amount' => $settlementAmount,
                    'payment_mode' => $paymentMode,
                    'settlement_date' => $settlementDate,
                ],
            ]);

            $pdo->commit();
            flash_set('customer_success', 'Customer balance settlement recorded.', 'success');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('customer_error', $exception->getMessage(), 'danger');
        }

        redirect($redirectUrl);
    }
}

$editId = get_int('edit_id');
$editCustomer = null;
if ($editId > 0) {
    $editStmt = db()->prepare('SELECT * FROM customers WHERE id = :id AND company_id = :company_id LIMIT 1');
    $editStmt->execute([
        'id' => $editId,
        'company_id' => $companyId,
    ]);
    $editCustomer = $editStmt->fetch() ?: null;
}

$historyCustomerId = get_int('history_id');
$customerHistory = [];
if ($historyCustomerId > 0) {
    $historyStmt = db()->prepare(
        'SELECT ch.*, u.name AS created_by_name
         FROM customer_history ch
         LEFT JOIN customers c ON c.id = ch.customer_id
         LEFT JOIN users u ON u.id = ch.created_by
         WHERE ch.customer_id = :customer_id
           AND c.company_id = :company_id
         ORDER BY ch.id DESC
         LIMIT 30'
    );
    $historyStmt->execute([
        'customer_id' => $historyCustomerId,
        'company_id' => $companyId,
    ]);
    $customerHistory = $historyStmt->fetchAll();
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = strtoupper(trim((string) ($_GET['status'] ?? '')));
$allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED', 'ALL'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}
$customerTypeColumnSupported = in_array('customer_type', table_columns('customers'), true);
$customerTypeFilter = strtoupper(trim((string) ($_GET['customer_type'] ?? '')));
$allowedCustomerTypes = $customerTypeColumnSupported
    ? ['', 'INDIVIDUAL', 'BUSINESS', 'FLEET', 'GOVERNMENT', 'OTHER', 'UNSPECIFIED']
    : ['', 'INDIVIDUAL', 'BUSINESS'];
if (!in_array($customerTypeFilter, $allowedCustomerTypes, true)) {
    $customerTypeFilter = '';
}
$gstinPresenceFilter = strtoupper(trim((string) ($_GET['gstin_presence'] ?? '')));
if (!in_array($gstinPresenceFilter, ['', 'HAS_GSTIN', 'NO_GSTIN'], true)) {
    $gstinPresenceFilter = '';
}
$fromDateFilter = trim((string) ($_GET['from_date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDateFilter)) {
    $fromDateFilter = '';
}
$toDateFilter = trim((string) ($_GET['to_date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDateFilter)) {
    $toDateFilter = '';
}

$ledgerReady = table_columns('ledger_entries') !== []
    && table_columns('ledger_journals') !== []
    && table_columns('chart_of_accounts') !== [];
$customerLedgerBalanceExpr = $ledgerReady
    ? '(SELECT COALESCE(SUM(le.debit_amount), 0) - COALESCE(SUM(le.credit_amount), 0)
        FROM ledger_entries le
        INNER JOIN ledger_journals lj ON lj.id = le.journal_id
        INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
        WHERE lj.company_id = c.company_id
          AND le.party_type = "CUSTOMER"
          AND le.party_id = c.id
          AND coa.code IN ("1200", "2300"))'
    : '0';
$financialCustomerId = get_int('financial_customer_id');
$financialAction = strtolower(trim((string) ($_GET['financial_action'] ?? 'opening')));
if (!in_array($financialAction, ['opening', 'collect', 'pay'], true)) {
    $financialAction = 'opening';
}
$financialCustomer = $financialCustomerId > 0 ? customer_master_fetch_row($companyId, $financialCustomerId) : null;
if (!$financialCustomer) {
    $financialCustomerId = 0;
}

$selectedCustomerBalance = 0.0;
$selectedCustomerLedgers = [];
if ($ledgerReady && $financialCustomerId > 0) {
    $selectedCustomerBalance = ledger_customer_net_balance(db(), $companyId, $financialCustomerId);
    $ledgerStmt = db()->prepare(
        'SELECT lj.id AS journal_id,
                lj.journal_date,
                lj.reference_type,
                lj.reference_id,
                lj.narration,
                COALESCE(SUM(CASE
                    WHEN coa.code IN ("1200", "2300")
                     AND le.party_type = "CUSTOMER"
                     AND le.party_id = :customer_id
                    THEN le.debit_amount - le.credit_amount
                    ELSE 0
                END), 0) AS net_delta
         FROM ledger_journals lj
         INNER JOIN ledger_entries le ON le.journal_id = lj.id
         INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
         WHERE lj.company_id = :company_id
           AND (
                (le.party_type = "CUSTOMER" AND le.party_id = :customer_id AND lj.reference_type IN ("CUSTOMER_OPENING_BALANCE", "CUSTOMER_OPENING_BALANCE_REV"))
                OR
                (le.party_type = "CUSTOMER" AND le.party_id = :customer_id AND lj.reference_type = "CUSTOMER_BALANCE_SETTLEMENT")
           )
         GROUP BY lj.id, lj.journal_date, lj.reference_type, lj.reference_id, lj.narration
         ORDER BY lj.id DESC
         LIMIT 12'
    );
    $ledgerStmt->execute([
        'company_id' => $companyId,
        'customer_id' => $financialCustomerId,
    ]);
    $selectedCustomerLedgers = $ledgerStmt->fetchAll();
}

$whereParts = ['c.company_id = :company_id'];
$params = ['company_id' => $companyId];

if ($search !== '') {
    $whereParts[] = '(c.full_name LIKE :query OR c.phone LIKE :query OR c.email LIKE :query OR c.gstin LIKE :query)';
    $params['query'] = '%' . $search . '%';
}

if ($statusFilter === '') {
    $whereParts[] = 'c.status_code <> "DELETED"';
} elseif ($statusFilter !== 'ALL') {
    $whereParts[] = 'c.status_code = :status_code';
    $params['status_code'] = $statusFilter;
}

$listSql =
    'SELECT c.*,
            (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id = c.id AND v.status_code <> "DELETED") AS vehicle_count,
            (SELECT COUNT(*) FROM customer_history h WHERE h.customer_id = c.id) AS history_count,
            ' . $customerLedgerBalanceExpr . ' AS ledger_balance
     FROM customers c
     WHERE ' . implode(' AND ', $whereParts) . '
     ORDER BY c.id DESC
     LIMIT 10';

$totalCustomersSql =
    'SELECT COUNT(*)
     FROM customers c
     WHERE ' . implode(' AND ', $whereParts);
$totalCustomersStmt = db()->prepare($totalCustomersSql);
$totalCustomersStmt->execute($params);
$totalCustomers = (int) ($totalCustomersStmt->fetchColumn() ?: 0);

$customerStmt = db()->prepare($listSql);
$customerStmt->execute($params);
$customers = $customerStmt->fetchAll();
$customerInsightsApiUrl = url('modules/customers/master_insights_api.php');

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Customer Master</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Customer Master</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editCustomer ? 'Edit Customer' : 'Add Customer'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editCustomer ? 'update' : 'create'; ?>" />
              <input type="hidden" name="customer_id" value="<?= (int) ($editCustomer['id'] ?? 0); ?>" />

              <div class="col-md-4">
                <label class="form-label">Full Name</label>
                <input type="text" name="full_name" class="form-control" required value="<?= e((string) ($editCustomer['full_name'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" required value="<?= e((string) ($editCustomer['phone'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Alt Phone</label>
                <input type="text" name="alt_phone" class="form-control" value="<?= e((string) ($editCustomer['alt_phone'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= e((string) ($editCustomer['email'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editCustomer['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-2">
                <label class="form-label">GSTIN</label>
                <input type="text" name="gstin" class="form-control" maxlength="15" value="<?= e((string) ($editCustomer['gstin'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Pincode</label>
                <input type="text" name="pincode" class="form-control" maxlength="10" value="<?= e((string) ($editCustomer['pincode'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Address Line 1</label>
                <input type="text" name="address_line1" class="form-control" value="<?= e((string) ($editCustomer['address_line1'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Address Line 2</label>
                <input type="text" name="address_line2" class="form-control" value="<?= e((string) ($editCustomer['address_line2'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">City</label>
                <input type="text" name="city" class="form-control" value="<?= e((string) ($editCustomer['city'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">State</label>
                <input type="text" name="state" class="form-control" value="<?= e((string) ($editCustomer['state'] ?? '')); ?>" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= e((string) ($editCustomer['notes'] ?? '')); ?></textarea>
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editCustomer ? 'Update Customer' : 'Create Customer'; ?></button>
              <?php if (!$editCustomer && $canVehicleManage): ?>
                <button type="submit" name="create_and_add_vehicle" value="1" class="btn btn-outline-primary">Create Customer + Add Vehicle</button>
              <?php endif; ?>
              <?php if ($editCustomer): ?>
                <a href="<?= e(url('modules/customers/index.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="row g-3 mb-3">
        <div class="col-md-3 col-sm-6">
          <div class="small-box text-bg-primary mb-0">
            <div class="inner">
              <h3 data-stat-value="total_customers"><?= (int) $totalCustomers; ?></h3>
              <p>Total Customers</p>
            </div>
            <div class="small-box-icon"><i class="bi bi-people-fill"></i></div>
          </div>
        </div>
        <div class="col-md-3 col-sm-6">
          <div class="small-box text-bg-success mb-0">
            <div class="inner">
              <h3 data-stat-value="active_customers">0</h3>
              <p>Active Customers</p>
            </div>
            <div class="small-box-icon"><i class="bi bi-person-check-fill"></i></div>
          </div>
        </div>
        <div class="col-md-3 col-sm-6">
          <div class="small-box text-bg-info mb-0">
            <div class="inner">
              <h3 data-stat-value="repeat_customers">0</h3>
              <p>Repeat Customers</p>
            </div>
            <div class="small-box-icon"><i class="bi bi-arrow-repeat"></i></div>
          </div>
        </div>
        <div class="col-md-3 col-sm-6">
          <div class="small-box text-bg-warning mb-0">
            <div class="inner">
              <h3 data-stat-value="customers_with_open_jobs">0</h3>
              <p>Customers With Open Jobs</p>
            </div>
            <div class="small-box-icon"><i class="bi bi-tools"></i></div>
          </div>
        </div>
      </div>

      <div class="card" data-master-insights-root="customers" data-master-insights-endpoint="<?= e($customerInsightsApiUrl); ?>">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Customer List</h3>
          <span class="badge text-bg-light border" data-master-results-count="1"><?= (int) $totalCustomers; ?></span>
        </div>
        <div class="card-body border-bottom">
          <form method="get" class="row g-2 align-items-end" data-master-filter-form="1">
            <div class="col-md-3">
              <label class="form-label form-label-sm mb-1">Search</label>
              <input type="text" name="q" value="<?= e($search); ?>" class="form-control form-control-sm" placeholder="Name / phone / email / GSTIN" />
            </div>
            <div class="col-md-2">
              <label class="form-label form-label-sm mb-1">Status</label>
              <select name="status" class="form-select form-select-sm">
                <option value="" <?= $statusFilter === '' ? 'selected' : ''; ?>>Active + Inactive</option>
                <option value="ACTIVE" <?= $statusFilter === 'ACTIVE' ? 'selected' : ''; ?>>ACTIVE</option>
                <option value="INACTIVE" <?= $statusFilter === 'INACTIVE' ? 'selected' : ''; ?>>INACTIVE</option>
                <option value="DELETED" <?= $statusFilter === 'DELETED' ? 'selected' : ''; ?>>DELETED</option>
                <option value="ALL" <?= $statusFilter === 'ALL' ? 'selected' : ''; ?>>ALL</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label form-label-sm mb-1">Customer Type</label>
              <select name="customer_type" class="form-select form-select-sm">
                <option value="" <?= $customerTypeFilter === '' ? 'selected' : ''; ?>>All Types</option>
                <option value="INDIVIDUAL" <?= $customerTypeFilter === 'INDIVIDUAL' ? 'selected' : ''; ?>>INDIVIDUAL</option>
                <option value="BUSINESS" <?= $customerTypeFilter === 'BUSINESS' ? 'selected' : ''; ?>>BUSINESS</option>
                <?php if ($customerTypeColumnSupported): ?>
                  <option value="FLEET" <?= $customerTypeFilter === 'FLEET' ? 'selected' : ''; ?>>FLEET</option>
                  <option value="GOVERNMENT" <?= $customerTypeFilter === 'GOVERNMENT' ? 'selected' : ''; ?>>GOVERNMENT</option>
                  <option value="OTHER" <?= $customerTypeFilter === 'OTHER' ? 'selected' : ''; ?>>OTHER</option>
                  <option value="UNSPECIFIED" <?= $customerTypeFilter === 'UNSPECIFIED' ? 'selected' : ''; ?>>UNSPECIFIED</option>
                <?php endif; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label form-label-sm mb-1">GSTIN</label>
              <select name="gstin_presence" class="form-select form-select-sm">
                <option value="" <?= $gstinPresenceFilter === '' ? 'selected' : ''; ?>>All</option>
                <option value="HAS_GSTIN" <?= $gstinPresenceFilter === 'HAS_GSTIN' ? 'selected' : ''; ?>>Has GSTIN</option>
                <option value="NO_GSTIN" <?= $gstinPresenceFilter === 'NO_GSTIN' ? 'selected' : ''; ?>>No GSTIN</option>
              </select>
            </div>
            <div class="col-md-1">
              <label class="form-label form-label-sm mb-1">From</label>
              <input type="date" name="from_date" value="<?= e($fromDateFilter); ?>" class="form-control form-control-sm" />
            </div>
            <div class="col-md-1">
              <label class="form-label form-label-sm mb-1">To</label>
              <input type="date" name="to_date" value="<?= e($toDateFilter); ?>" class="form-control form-control-sm" />
            </div>
            <div class="col-md-1 d-flex gap-2">
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
                <th>ID</th>
                <th>Customer</th>
                <th>Contact</th>
                <th>GSTIN</th>
                <th>Vehicles</th>
                <th>History</th>
                <th>Ledger Balance</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody data-master-table-body="1" data-table-colspan="9">
              <?php if (empty($customers)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No customers found.</td></tr>
              <?php else: ?>
                <?php foreach ($customers as $customer): ?>
                  <?php
                    $customerId = (int) ($customer['id'] ?? 0);
                    $customerLedgerBalance = ledger_round((float) ($customer['ledger_balance'] ?? 0));
                    $isCustomerReceivable = $customerLedgerBalance > 0.009;
                    $isCustomerAdvance = $customerLedgerBalance < -0.009;
                  ?>
                  <tr>
                    <td><?= $customerId; ?></td>
                    <td>
                      <a href="<?= e(url('modules/customers/view.php?id=' . $customerId)); ?>" class="fw-semibold text-decoration-none">
                        <?= e((string) $customer['full_name']); ?>
                      </a><br>
                      <small class="text-muted"><?= e((string) (($customer['city'] ?? '-') . ', ' . ($customer['state'] ?? '-'))); ?></small>
                    </td>
                    <td><?= e((string) $customer['phone']); ?><br><small class="text-muted"><?= e((string) ($customer['email'] ?? '-')); ?></small></td>
                    <td><?= e((string) ($customer['gstin'] ?? '-')); ?></td>
                    <td><?= (int) $customer['vehicle_count']; ?></td>
                    <td><?= (int) $customer['history_count']; ?></td>
                    <td>
                      <div class="fw-semibold <?= $isCustomerReceivable ? 'text-danger' : ($isCustomerAdvance ? 'text-success' : 'text-muted'); ?>">
                        <?= e(format_currency(abs($customerLedgerBalance))); ?>
                      </div>
                      <small class="text-muted">
                        <?= $isCustomerReceivable ? 'Receivable' : ($isCustomerAdvance ? 'Advance' : 'Nil'); ?>
                      </small>
                    </td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $customer['status_code'])); ?>"><?= e(record_status_label((string) $customer['status_code'])); ?></span></td>
                    <td class="d-flex gap-1">
                      <a class="btn btn-sm btn-outline-success" href="<?= e(url('modules/customers/view.php?id=' . $customerId)); ?>">View</a>
                      <a class="btn btn-sm btn-outline-info" href="<?= e(url('modules/customers/index.php?history_id=' . $customerId)); ?>">History</a>
                      <?php if ($canManage && $ledgerReady): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('modules/reports/customer_ledger.php?customer_id=' . $customerId)); ?>">Ledger</a>
                        <?php if ((string) ($customer['status_code'] ?? '') !== 'DELETED'): ?>
                          <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/customers/index.php?financial_customer_id=' . $customerId . '&financial_action=opening#customer-financial-actions')); ?>">Opening</a>
                          <?php if ($isCustomerReceivable): ?>
                            <a class="btn btn-sm btn-outline-success" href="<?= e(url('modules/customers/index.php?financial_customer_id=' . $customerId . '&financial_action=collect#customer-financial-actions')); ?>">Collect</a>
                          <?php elseif ($isCustomerAdvance): ?>
                            <a class="btn btn-sm btn-outline-warning" href="<?= e(url('modules/customers/index.php?financial_customer_id=' . $customerId . '&financial_action=pay#customer-financial-actions')); ?>">Pay</a>
                          <?php endif; ?>
                        <?php endif; ?>
                      <?php endif; ?>
                      <?php if ($canVehicleManage && (string) $customer['status_code'] !== 'DELETED'): ?>
                        <a class="btn btn-sm btn-outline-dark" href="<?= e(url('modules/vehicles/index.php?prefill_customer_id=' . $customerId)); ?>">Add Vehicle</a>
                      <?php endif; ?>
                      <?php if ($canManage): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/customers/index.php?edit_id=' . $customerId)); ?>">Edit</a>
                        <?php if ((string) $customer['status_code'] !== 'DELETED'): ?>
                          <form method="post" class="d-inline" data-confirm="Change customer status?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="customer_id" value="<?= $customerId; ?>" />
                            <input type="hidden" name="next_status" value="<?= e(((string) $customer['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $customer['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                          </form>
                          <form method="post" class="d-inline"
                                data-safe-delete
                                data-safe-delete-entity="customer"
                                data-safe-delete-record-field="customer_id"
                                data-safe-delete-operation="delete"
                                    data-safe-delete-reason-field="deletion_reason">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="customer_id" value="<?= $customerId; ?>" />
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
        <div class="card mt-3" id="customer-financial-actions">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Customer Opening Balance & Settlement</h3>
            <?php if ($financialCustomerId > 0): ?>
              <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('modules/reports/customer_ledger.php?customer_id=' . $financialCustomerId)); ?>">Open Customer Ledger Report</a>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if (!$ledgerReady): ?>
              <div class="alert alert-warning mb-0">Ledger tables are not ready. Opening balance and settlement actions are unavailable.</div>
            <?php elseif ($financialCustomerId <= 0 || !$financialCustomer): ?>
              <div class="alert alert-info mb-0">Select any customer row and click <strong>Opening</strong>, <strong>Collect</strong>, or <strong>Pay</strong> to manage opening balance and settlements.</div>
            <?php else: ?>
              <?php
                $selectedCustomerName = (string) ($financialCustomer['full_name'] ?? ('Customer #' . $financialCustomerId));
                $selectedBalanceAbs = abs($selectedCustomerBalance);
                $selectedIsReceivable = $selectedCustomerBalance > 0.009;
                $selectedIsAdvance = $selectedCustomerBalance < -0.009;
                $openingTypeDefault = $selectedIsAdvance ? 'ADVANCE' : 'RECEIVABLE';
                $settlementDirectionDefault = $financialAction === 'pay'
                    ? 'PAY'
                    : ($financialAction === 'collect' ? 'COLLECT' : ($selectedIsAdvance ? 'PAY' : 'COLLECT'));
                $settlementBtnDisabled = $selectedBalanceAbs <= 0.009 ? 'disabled' : '';
              ?>
              <div class="row g-3 mb-3">
                <div class="col-md-4">
                  <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1">Customer</div>
                    <div class="fw-semibold"><?= e($selectedCustomerName); ?> (#<?= (int) $financialCustomerId; ?>)</div>
                    <div class="text-muted small mt-2">Current Net Balance</div>
                    <div class="<?= $selectedIsReceivable ? 'text-danger' : ($selectedIsAdvance ? 'text-success' : 'text-muted'); ?>">
                      <?= e(format_currency($selectedBalanceAbs)); ?>
                      <small>(<?= $selectedIsReceivable ? 'Receivable' : ($selectedIsAdvance ? 'Advance' : 'Nil'); ?>)</small>
                    </div>
                  </div>
                </div>
                <div class="col-md-8">
                  <div class="row g-3">
                    <div class="col-lg-6">
                      <form method="post" class="border rounded p-3 h-100">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="_action" value="set_opening_balance" />
                        <input type="hidden" name="customer_id" value="<?= (int) $financialCustomerId; ?>" />
                        <h6 class="mb-3">Set Opening Balance</h6>
                        <div class="row g-2">
                          <div class="col-6">
                            <label class="form-label">Type</label>
                            <select name="opening_type" class="form-select" required>
                              <option value="RECEIVABLE" <?= $openingTypeDefault === 'RECEIVABLE' ? 'selected' : ''; ?>>Receivable</option>
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
                        <div class="mt-3 d-flex gap-2">
                          <button type="submit" class="btn btn-primary btn-sm">Save Opening</button>
                        </div>
                      </form>
                    </div>
                    <div class="col-lg-6">
                      <form method="post" class="border rounded p-3 h-100">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="_action" value="record_balance_settlement" />
                        <input type="hidden" name="customer_id" value="<?= (int) $financialCustomerId; ?>" />
                        <h6 class="mb-3">Settle Balance (Pay / Collect)</h6>
                        <div class="row g-2">
                          <div class="col-6">
                            <label class="form-label">Direction</label>
                            <select name="settlement_direction" class="form-select" required>
                              <option value="COLLECT" <?= $settlementDirectionDefault === 'COLLECT' ? 'selected' : ''; ?>>Collect</option>
                              <option value="PAY" <?= $settlementDirectionDefault === 'PAY' ? 'selected' : ''; ?>>Pay</option>
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
                                <option value="<?= e($mode); ?>" <?= $mode === 'CASH' ? 'selected' : ''; ?>><?= e($mode); ?></option>
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
                    <?php if (empty($selectedCustomerLedgers)): ?>
                      <tr><td colspan="5" class="text-center text-muted py-3">No opening/settlement ledger entries for this customer yet.</td></tr>
                    <?php else: ?>
                      <?php foreach ($selectedCustomerLedgers as $ledgerRow): ?>
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

      <?php if ($historyCustomerId > 0): ?>
        <div class="card mt-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Customer History #<?= (int) $historyCustomerId; ?></h3>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('modules/customers/index.php')); ?>">Close</a>
          </div>
          <div class="card-body table-responsive p-0">
            <table class="table table-sm table-striped mb-0">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Action</th>
                  <th>Note</th>
                  <th>By</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($customerHistory)): ?>
                  <tr><td colspan="4" class="text-center text-muted py-4">No history found.</td></tr>
                <?php else: ?>
                  <?php foreach ($customerHistory as $history): ?>
                    <tr>
                      <td><?= e((string) $history['created_at']); ?></td>
                      <td><span class="badge text-bg-secondary"><?= e((string) $history['action_type']); ?></span></td>
                      <td><?= e((string) ($history['action_note'] ?? '-')); ?></td>
                      <td><?= e((string) ($history['created_by_name'] ?? '-')); ?></td>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
