<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('vendor.view');

$page_title = 'Vendor / Supplier Master';
$active_menu = 'vendors.master';
$canManage = has_permission('vendor.manage');
$companyId = active_company_id();

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

        $stmt = db()->prepare(
            'UPDATE vendors
             SET status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'status_code' => $nextStatus,
            'id' => $vendorId,
            'company_id' => $companyId,
        ]);

        log_audit('vendors', 'status', $vendorId, 'Changed vendor status to ' . $nextStatus);
        flash_set('vendor_success', 'Vendor status updated.', 'success');
        redirect('modules/vendors/index.php');
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

$vendorsStmt = db()->prepare(
    'SELECT v.*,
            (SELECT COUNT(*) FROM parts p WHERE p.vendor_id = v.id) AS linked_parts
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
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($vendors)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No vendors found.</td></tr>
              <?php else: ?>
                <?php foreach ($vendors as $vendor): ?>
                  <tr>
                    <td><code><?= e((string) $vendor['vendor_code']); ?></code></td>
                    <td><?= e((string) $vendor['vendor_name']); ?><br><small class="text-muted"><?= e((string) ($vendor['city'] ?? '-')); ?></small></td>
                    <td><?= e((string) ($vendor['phone'] ?? '-')); ?><br><small class="text-muted"><?= e((string) ($vendor['email'] ?? '-')); ?></small></td>
                    <td><?= e((string) ($vendor['gstin'] ?? '-')); ?></td>
                    <td><?= (int) $vendor['linked_parts']; ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $vendor['status_code'])); ?>"><?= e((string) $vendor['status_code']); ?></span></td>
                    <td class="d-flex gap-1">
                      <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/vendors/index.php?edit_id=' . (int) $vendor['id'])); ?>">Edit</a>
                      <?php if ($canManage): ?>
                        <form method="post" class="d-inline" data-confirm="Change vendor status?">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="change_status" />
                          <input type="hidden" name="vendor_id" value="<?= (int) $vendor['id']; ?>" />
                          <input type="hidden" name="next_status" value="<?= e(((string) $vendor['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $vendor['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                        </form>
                        <?php if ((string) $vendor['status_code'] !== 'DELETED'): ?>
                          <form method="post" class="d-inline" data-confirm="Soft delete this vendor?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="vendor_id" value="<?= (int) $vendor['id']; ?>" />
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
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
