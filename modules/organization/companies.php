<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('company.manage');

$page_title = 'Company Master';
$active_menu = 'organization.companies';
$canManage = has_permission('company.manage');
$isSuperAdmin = (string) ($_SESSION['role_key'] ?? '') === 'super_admin';
$activeCompanyId = active_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('company_error', 'You do not have permission to modify company master.', 'danger');
        redirect('modules/organization/companies.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create') {
        if (!$isSuperAdmin) {
            flash_set('company_error', 'Only Super Admin can create companies.', 'danger');
            redirect('modules/organization/companies.php');
        }

        $name = post_string('name', 120);
        $legalName = post_string('legal_name', 160);
        $gstin = strtoupper(post_string('gstin', 15));
        $pan = strtoupper(post_string('pan', 10));
        $phone = post_string('phone', 20);
        $email = strtolower(post_string('email', 120));
        $address1 = post_string('address_line1', 200);
        $address2 = post_string('address_line2', 200);
        $city = post_string('city', 80);
        $state = post_string('state', 80);
        $pincode = post_string('pincode', 10);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($name === '') {
            flash_set('company_error', 'Company name is required.', 'danger');
            redirect('modules/organization/companies.php');
        }

        if ($statusCode === 'DELETED') {
            $statusCode = 'ACTIVE';
        }

        $legacyStatus = $statusCode === 'ACTIVE' ? 'active' : 'inactive';

        try {
            $stmt = db()->prepare(
                'INSERT INTO companies
                  (name, legal_name, gstin, pan, phone, email, address_line1, address_line2, city, state, pincode, status, status_code, deleted_at)
                 VALUES
                  (:name, :legal_name, :gstin, :pan, :phone, :email, :address_line1, :address_line2, :city, :state, :pincode, :status, :status_code, NULL)'
            );
            $stmt->execute([
                'name' => $name,
                'legal_name' => $legalName !== '' ? $legalName : null,
                'gstin' => $gstin !== '' ? $gstin : null,
                'pan' => $pan !== '' ? $pan : null,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'address_line1' => $address1 !== '' ? $address1 : null,
                'address_line2' => $address2 !== '' ? $address2 : null,
                'city' => $city !== '' ? $city : null,
                'state' => $state !== '' ? $state : null,
                'pincode' => $pincode !== '' ? $pincode : null,
                'status' => $legacyStatus,
                'status_code' => $statusCode,
            ]);

            $companyId = (int) db()->lastInsertId();
            log_audit('companies', 'create', $companyId, 'Created company ' . $name);
            flash_set('company_success', 'Company created successfully.', 'success');
        } catch (Throwable $exception) {
            flash_set('company_error', 'Unable to create company. GSTIN might already exist.', 'danger');
        }

        redirect('modules/organization/companies.php');
    }

    if ($action === 'update') {
        $companyId = post_int('company_id');
        $name = post_string('name', 120);
        $legalName = post_string('legal_name', 160);
        $gstin = strtoupper(post_string('gstin', 15));
        $pan = strtoupper(post_string('pan', 10));
        $phone = post_string('phone', 20);
        $email = strtolower(post_string('email', 120));
        $address1 = post_string('address_line1', 200);
        $address2 = post_string('address_line2', 200);
        $city = post_string('city', 80);
        $state = post_string('state', 80);
        $pincode = post_string('pincode', 10);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($companyId <= 0 || $name === '') {
            flash_set('company_error', 'Company ID and name are required.', 'danger');
            redirect('modules/organization/companies.php');
        }

        if (!$isSuperAdmin && $companyId !== $activeCompanyId) {
            flash_set('company_error', 'You can only update your own company.', 'danger');
            redirect('modules/organization/companies.php');
        }

        if (!$isSuperAdmin && $statusCode === 'DELETED') {
            flash_set('company_error', 'Only Super Admin can delete a company.', 'danger');
            redirect('modules/organization/companies.php?edit_id=' . $companyId);
        }

        $legacyStatus = $statusCode === 'ACTIVE' ? 'active' : 'inactive';

        try {
            $stmt = db()->prepare(
                'UPDATE companies
                 SET name = :name,
                     legal_name = :legal_name,
                     gstin = :gstin,
                     pan = :pan,
                     phone = :phone,
                     email = :email,
                     address_line1 = :address_line1,
                     address_line2 = :address_line2,
                     city = :city,
                     state = :state,
                     pincode = :pincode,
                     status = :status,
                     status_code = :status_code,
                     deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
                 WHERE id = :id'
            );
            $stmt->execute([
                'name' => $name,
                'legal_name' => $legalName !== '' ? $legalName : null,
                'gstin' => $gstin !== '' ? $gstin : null,
                'pan' => $pan !== '' ? $pan : null,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'address_line1' => $address1 !== '' ? $address1 : null,
                'address_line2' => $address2 !== '' ? $address2 : null,
                'city' => $city !== '' ? $city : null,
                'state' => $state !== '' ? $state : null,
                'pincode' => $pincode !== '' ? $pincode : null,
                'status' => $legacyStatus,
                'status_code' => $statusCode,
                'id' => $companyId,
            ]);

            log_audit('companies', 'update', $companyId, 'Updated company ' . $name);
            flash_set('company_success', 'Company updated successfully.', 'success');
        } catch (Throwable $exception) {
            flash_set('company_error', 'Unable to update company. GSTIN might already exist.', 'danger');
        }

        redirect('modules/organization/companies.php');
    }

    if ($action === 'change_status') {
        $companyId = post_int('company_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));

        if ($companyId <= 0) {
            flash_set('company_error', 'Invalid company selected.', 'danger');
            redirect('modules/organization/companies.php');
        }

        if (!$isSuperAdmin && $companyId !== $activeCompanyId) {
            flash_set('company_error', 'You can only change your own company status.', 'danger');
            redirect('modules/organization/companies.php');
        }

        if (!$isSuperAdmin && $nextStatus === 'DELETED') {
            flash_set('company_error', 'Only Super Admin can delete a company.', 'danger');
            redirect('modules/organization/companies.php');
        }

        $legacyStatus = $nextStatus === 'ACTIVE' ? 'active' : 'inactive';

        $stmt = db()->prepare(
            'UPDATE companies
             SET status = :status,
                 status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $legacyStatus,
            'status_code' => $nextStatus,
            'id' => $companyId,
        ]);

        log_audit('companies', 'status', $companyId, 'Changed status to ' . $nextStatus);
        flash_set('company_success', 'Company status updated.', 'success');
        redirect('modules/organization/companies.php');
    }
}

$editId = get_int('edit_id');
$editCompany = null;
if ($editId > 0) {
    $editStmt = $isSuperAdmin
        ? db()->prepare('SELECT * FROM companies WHERE id = :id LIMIT 1')
        : db()->prepare('SELECT * FROM companies WHERE id = :id AND id = :company_id LIMIT 1');

    $params = ['id' => $editId];
    if (!$isSuperAdmin) {
        $params['company_id'] = $activeCompanyId;
    }

    $editStmt->execute($params);
    $editCompany = $editStmt->fetch() ?: null;
}

if ($isSuperAdmin) {
    $companiesStmt = db()->query(
        'SELECT c.*,
                (SELECT COUNT(*) FROM garages g WHERE g.company_id = c.id AND g.status_code <> "DELETED") AS garage_count,
                (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.status_code <> "DELETED") AS staff_count
         FROM companies c
         ORDER BY c.id DESC'
    );
    $companies = $companiesStmt->fetchAll();
} else {
    $companiesStmt = db()->prepare(
        'SELECT c.*,
                (SELECT COUNT(*) FROM garages g WHERE g.company_id = c.id AND g.status_code <> "DELETED") AS garage_count,
                (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.status_code <> "DELETED") AS staff_count
         FROM companies c
         WHERE c.id = :company_id
         LIMIT 1'
    );
    $companiesStmt->execute(['company_id' => $activeCompanyId]);
    $companies = $companiesStmt->fetchAll();
}

$statusChoices = ['ACTIVE', 'INACTIVE', 'DELETED'];
if (!$isSuperAdmin) {
    $statusChoices = ['ACTIVE', 'INACTIVE'];
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Company Master</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Company Master</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage && ($isSuperAdmin || $editCompany !== null)): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editCompany ? 'Edit Company' : 'Add Company'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editCompany ? 'update' : 'create'; ?>" />
              <input type="hidden" name="company_id" value="<?= (int) ($editCompany['id'] ?? 0); ?>" />

              <div class="col-md-4">
                <label class="form-label">Company Name</label>
                <input type="text" name="name" class="form-control" required value="<?= e((string) ($editCompany['name'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Legal Name</label>
                <input type="text" name="legal_name" class="form-control" value="<?= e((string) ($editCompany['legal_name'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">GSTIN</label>
                <input type="text" name="gstin" class="form-control" maxlength="15" value="<?= e((string) ($editCompany['gstin'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">PAN</label>
                <input type="text" name="pan" class="form-control" maxlength="10" value="<?= e((string) ($editCompany['pan'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= e((string) ($editCompany['phone'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= e((string) ($editCompany['email'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Pincode</label>
                <input type="text" name="pincode" class="form-control" maxlength="10" value="<?= e((string) ($editCompany['pincode'] ?? '')); ?>" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Address Line 1</label>
                <input type="text" name="address_line1" class="form-control" value="<?= e((string) ($editCompany['address_line1'] ?? '')); ?>" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Address Line 2</label>
                <input type="text" name="address_line2" class="form-control" value="<?= e((string) ($editCompany['address_line2'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">City</label>
                <input type="text" name="city" class="form-control" value="<?= e((string) ($editCompany['city'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">State</label>
                <input type="text" name="state" class="form-control" value="<?= e((string) ($editCompany['state'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editCompany['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <?php if (in_array($option['value'], $statusChoices, true)): ?>
                      <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editCompany ? 'Update Company' : 'Create Company'; ?></button>
              <?php if ($editCompany): ?>
                <a href="<?= e(url('modules/organization/companies.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Company List</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Company</th>
                <th>GSTIN</th>
                <th>Location</th>
                <th>Garages</th>
                <th>Staff</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($companies)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No companies found.</td></tr>
              <?php else: ?>
                <?php foreach ($companies as $company): ?>
                  <?php $canEditRecord = $isSuperAdmin || ((int) $company['id'] === $activeCompanyId); ?>
                  <tr>
                    <td><?= (int) $company['id']; ?></td>
                    <td>
                      <?= e((string) $company['name']); ?><br>
                      <small class="text-muted"><?= e((string) ($company['legal_name'] ?? '-')); ?></small>
                    </td>
                    <td><?= e((string) ($company['gstin'] ?? '-')); ?></td>
                    <td><?= e((string) (($company['city'] ?? '-') . ', ' . ($company['state'] ?? '-'))); ?></td>
                    <td><?= (int) ($company['garage_count'] ?? 0); ?></td>
                    <td><?= (int) ($company['staff_count'] ?? 0); ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $company['status_code'])); ?>"><?= e(record_status_label((string) $company['status_code'])); ?></span></td>
                    <td class="d-flex gap-1">
                      <?php if ($canManage && $canEditRecord): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/organization/companies.php?edit_id=' . (int) $company['id'])); ?>">Edit</a>
                        <?php if ((string) $company['status_code'] !== 'DELETED'): ?>
                          <form method="post" class="d-inline" data-confirm="Change company status?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="company_id" value="<?= (int) $company['id']; ?>" />
                            <input type="hidden" name="next_status" value="<?= e(((string) $company['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $company['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                          </form>
                        <?php endif; ?>
                        <?php if ($isSuperAdmin && (string) $company['status_code'] !== 'DELETED'): ?>
                          <form method="post" class="d-inline" data-confirm="Soft delete this company?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="company_id" value="<?= (int) $company['id']; ?>" />
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
