<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('customer.view');

$page_title = 'Customer Master';
$active_menu = 'customers';
$canManage = has_permission('customer.manage');
$companyId = active_company_id();

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

        $isActive = $nextStatus === 'ACTIVE' ? 1 : 0;
        $stmt = db()->prepare(
            'UPDATE customers
             SET is_active = :is_active,
                 status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'is_active' => $isActive,
            'status_code' => $nextStatus,
            'id' => $customerId,
            'company_id' => $companyId,
        ]);

        add_customer_history($customerId, 'STATUS', 'Status changed to ' . $nextStatus, ['status_code' => $nextStatus]);
        log_audit('customers', 'status', $customerId, 'Changed customer status to ' . $nextStatus);

        flash_set('customer_success', 'Customer status updated.', 'success');
        redirect('modules/customers/index.php');
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
            (SELECT COUNT(*) FROM customer_history h WHERE h.customer_id = c.id) AS history_count
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
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody data-master-table-body="1" data-table-colspan="8">
              <?php if (empty($customers)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No customers found.</td></tr>
              <?php else: ?>
                <?php foreach ($customers as $customer): ?>
                  <tr>
                    <td><?= (int) $customer['id']; ?></td>
                    <td>
                      <a href="<?= e(url('modules/customers/view.php?id=' . (int) $customer['id'])); ?>" class="fw-semibold text-decoration-none">
                        <?= e((string) $customer['full_name']); ?>
                      </a><br>
                      <small class="text-muted"><?= e((string) (($customer['city'] ?? '-') . ', ' . ($customer['state'] ?? '-'))); ?></small>
                    </td>
                    <td><?= e((string) $customer['phone']); ?><br><small class="text-muted"><?= e((string) ($customer['email'] ?? '-')); ?></small></td>
                    <td><?= e((string) ($customer['gstin'] ?? '-')); ?></td>
                    <td><?= (int) $customer['vehicle_count']; ?></td>
                    <td><?= (int) $customer['history_count']; ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $customer['status_code'])); ?>"><?= e(record_status_label((string) $customer['status_code'])); ?></span></td>
                    <td class="d-flex gap-1">
                      <a class="btn btn-sm btn-outline-success" href="<?= e(url('modules/customers/view.php?id=' . (int) $customer['id'])); ?>">View</a>
                      <a class="btn btn-sm btn-outline-info" href="<?= e(url('modules/customers/index.php?history_id=' . (int) $customer['id'])); ?>">History</a>
                      <?php if ($canManage): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/customers/index.php?edit_id=' . (int) $customer['id'])); ?>">Edit</a>
                        <?php if ((string) $customer['status_code'] !== 'DELETED'): ?>
                          <form method="post" class="d-inline" data-confirm="Change customer status?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="customer_id" value="<?= (int) $customer['id']; ?>" />
                            <input type="hidden" name="next_status" value="<?= e(((string) $customer['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $customer['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                          </form>
                          <form method="post" class="d-inline" data-confirm="Soft delete this customer?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="customer_id" value="<?= (int) $customer['id']; ?>" />
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
