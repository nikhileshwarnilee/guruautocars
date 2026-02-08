<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('customer.view');

$page_title = 'Customers';
$active_menu = 'customers';
$canManage = has_permission('customer.manage');
$companyId = active_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    require_csrf();
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
        $notes = post_string('notes', 1000);

        if ($fullName === '' || $phone === '') {
            flash_set('customer_error', 'Customer name and phone are required.', 'danger');
            redirect('modules/customers/index.php');
        }

        $stmt = db()->prepare(
            'INSERT INTO customers
              (company_id, created_by, full_name, phone, alt_phone, email, gstin, address_line1, address_line2, city, state, pincode, notes, is_active)
             VALUES
              (:company_id, :created_by, :full_name, :phone, :alt_phone, :email, :gstin, :address_line1, :address_line2, :city, :state, :pincode, :notes, 1)'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'created_by' => (int) $_SESSION['user_id'],
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
        ]);

        flash_set('customer_success', 'Customer created successfully.', 'success');
        redirect('modules/customers/index.php');
    }

    if ($action === 'toggle_status') {
        $customerId = post_int('customer_id');
        $nextActive = post_int('next_active', 0) === 1 ? 1 : 0;

        $stmt = db()->prepare(
            'UPDATE customers
             SET is_active = :is_active
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'is_active' => $nextActive,
            'id' => $customerId,
            'company_id' => $companyId,
        ]);

        flash_set('customer_success', 'Customer status updated.', 'success');
        redirect('modules/customers/index.php');
    }
}

$search = trim((string) ($_GET['q'] ?? ''));

if ($search !== '') {
    $stmt = db()->prepare(
        'SELECT c.*, COUNT(v.id) AS vehicle_count
         FROM customers c
         LEFT JOIN vehicles v ON v.customer_id = c.id
         WHERE c.company_id = :company_id
           AND (c.full_name LIKE :query OR c.phone LIKE :query OR c.email LIKE :query)
         GROUP BY c.id
         ORDER BY c.id DESC'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'query' => '%' . $search . '%',
    ]);
} else {
    $stmt = db()->prepare(
        'SELECT c.*, COUNT(v.id) AS vehicle_count
         FROM customers c
         LEFT JOIN vehicles v ON v.customer_id = c.id
         WHERE c.company_id = :company_id
         GROUP BY c.id
         ORDER BY c.id DESC'
    );
    $stmt->execute(['company_id' => $companyId]);
}
$customers = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Customer Management</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Customers</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title">Add Customer</h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="create" />
              <div class="col-md-4">
                <label class="form-label">Full Name</label>
                <input type="text" name="full_name" class="form-control" required />
              </div>
              <div class="col-md-4">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" required />
              </div>
              <div class="col-md-4">
                <label class="form-label">Alternative Phone</label>
                <input type="text" name="alt_phone" class="form-control" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" />
              </div>
              <div class="col-md-4">
                <label class="form-label">GSTIN (Optional)</label>
                <input type="text" name="gstin" class="form-control" maxlength="15" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Pincode</label>
                <input type="text" name="pincode" class="form-control" maxlength="10" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Address Line 1</label>
                <input type="text" name="address_line1" class="form-control" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Address Line 2</label>
                <input type="text" name="address_line2" class="form-control" />
              </div>
              <div class="col-md-6">
                <label class="form-label">City</label>
                <input type="text" name="city" class="form-control" />
              </div>
              <div class="col-md-6">
                <label class="form-label">State</label>
                <input type="text" name="state" class="form-control" />
              </div>
              <div class="col-md-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"></textarea>
              </div>
            </div>
            <div class="card-footer">
              <button type="submit" class="btn btn-primary">Save Customer</button>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Customers</h3>
          <div class="card-tools">
            <form method="get" class="d-flex gap-2">
              <input type="text" name="q" value="<?= e($search); ?>" class="form-control form-control-sm" placeholder="Search name/phone/email" />
              <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
            </form>
          </div>
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
                <th>Status</th>
                <?php if ($canManage): ?><th>Action</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($customers)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No customers found.</td></tr>
              <?php else: ?>
                <?php foreach ($customers as $customer): ?>
                  <tr>
                    <td><?= (int) $customer['id']; ?></td>
                    <td>
                      <?= e((string) $customer['full_name']); ?><br>
                      <small class="text-muted"><?= e((string) (($customer['city'] ?? '') !== '' ? $customer['city'] : '-')); ?></small>
                    </td>
                    <td>
                      <?= e((string) $customer['phone']); ?><br>
                      <small class="text-muted"><?= e((string) ($customer['email'] ?? '-')); ?></small>
                    </td>
                    <td><?= e((string) ($customer['gstin'] ?? '-')); ?></td>
                    <td><?= (int) $customer['vehicle_count']; ?></td>
                    <td>
                      <span class="badge text-bg-<?= ((int) $customer['is_active'] === 1) ? 'success' : 'secondary'; ?>">
                        <?= ((int) $customer['is_active'] === 1) ? 'Active' : 'Inactive'; ?>
                      </span>
                    </td>
                    <?php if ($canManage): ?>
                      <td>
                        <form method="post" class="d-inline" data-confirm="Change customer status?">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="toggle_status" />
                          <input type="hidden" name="customer_id" value="<?= (int) $customer['id']; ?>" />
                          <input type="hidden" name="next_active" value="<?= ((int) $customer['is_active'] === 1) ? '0' : '1'; ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-primary">
                            <?= ((int) $customer['is_active'] === 1) ? 'Deactivate' : 'Activate'; ?>
                          </button>
                        </form>
                      </td>
                    <?php endif; ?>
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
