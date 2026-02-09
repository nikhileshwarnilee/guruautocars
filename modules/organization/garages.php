<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('garage.manage');

$page_title = 'Garage / Branch Master';
$active_menu = 'organization.garages';
$canManage = has_permission('garage.manage');
$isSuperAdmin = (string) ($_SESSION['role_key'] ?? '') === 'super_admin';
$activeCompanyId = active_company_id();

$companyOptions = [];
if ($isSuperAdmin) {
    $companyOptions = db()->query('SELECT id, name FROM companies WHERE status_code <> "DELETED" ORDER BY name ASC')->fetchAll();
}

$selectedCompanyId = $isSuperAdmin ? get_int('company_id', $activeCompanyId) : $activeCompanyId;
if ($selectedCompanyId <= 0) {
    $selectedCompanyId = $activeCompanyId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('garage_error', 'You do not have permission to modify garage master.', 'danger');
        redirect('modules/organization/garages.php');
    }

    $action = (string) ($_POST['_action'] ?? '');
    $companyId = $isSuperAdmin ? post_int('company_id', $activeCompanyId) : $activeCompanyId;

    if ($action === 'create') {
        $name = post_string('name', 140);
        $code = strtoupper(post_string('code', 30));
        $phone = post_string('phone', 20);
        $email = strtolower(post_string('email', 120));
        $gstin = strtoupper(post_string('gstin', 15));
        $address1 = post_string('address_line1', 200);
        $address2 = post_string('address_line2', 200);
        $city = post_string('city', 80);
        $state = post_string('state', 80);
        $pincode = post_string('pincode', 10);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($companyId <= 0 || $name === '' || $code === '') {
            flash_set('garage_error', 'Company, garage name and code are required.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . $selectedCompanyId);
        }

        if ($statusCode === 'DELETED') {
            $statusCode = 'ACTIVE';
        }

        $legacyStatus = $statusCode === 'ACTIVE' ? 'active' : 'inactive';

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $insertStmt = $pdo->prepare(
                'INSERT INTO garages
                  (company_id, name, code, phone, email, gstin, address_line1, address_line2, city, state, pincode, status, status_code, deleted_at)
                 VALUES
                  (:company_id, :name, :code, :phone, :email, :gstin, :address_line1, :address_line2, :city, :state, :pincode, :status, :status_code, NULL)'
            );
            $insertStmt->execute([
                'company_id' => $companyId,
                'name' => $name,
                'code' => $code,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'gstin' => $gstin !== '' ? $gstin : null,
                'address_line1' => $address1 !== '' ? $address1 : null,
                'address_line2' => $address2 !== '' ? $address2 : null,
                'city' => $city !== '' ? $city : null,
                'state' => $state !== '' ? $state : null,
                'pincode' => $pincode !== '' ? $pincode : null,
                'status' => $legacyStatus,
                'status_code' => $statusCode,
            ]);

            $garageId = (int) $pdo->lastInsertId();

            $counterStmt = $pdo->prepare('INSERT IGNORE INTO job_counters (garage_id, prefix, current_number) VALUES (:garage_id, "JOB", 1000)');
            $counterStmt->execute(['garage_id' => $garageId]);

            $invoiceCounterStmt = $pdo->prepare('INSERT IGNORE INTO invoice_counters (garage_id, prefix, current_number) VALUES (:garage_id, "INV", 5000)');
            $invoiceCounterStmt->execute(['garage_id' => $garageId]);

            $pdo->commit();
            log_audit('garages', 'create', $garageId, 'Created garage ' . $code);
            flash_set('garage_success', 'Garage created successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('garage_error', 'Unable to create garage. Code must be unique per company.', 'danger');
        }

        redirect('modules/organization/garages.php?company_id=' . $companyId);
    }

    if ($action === 'update') {
        $garageId = post_int('garage_id');
        $name = post_string('name', 140);
        $code = strtoupper(post_string('code', 30));
        $phone = post_string('phone', 20);
        $email = strtolower(post_string('email', 120));
        $gstin = strtoupper(post_string('gstin', 15));
        $address1 = post_string('address_line1', 200);
        $address2 = post_string('address_line2', 200);
        $city = post_string('city', 80);
        $state = post_string('state', 80);
        $pincode = post_string('pincode', 10);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($garageId <= 0 || $companyId <= 0 || $name === '' || $code === '') {
            flash_set('garage_error', 'Invalid garage payload.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . $selectedCompanyId);
        }

        if (!$isSuperAdmin && $companyId !== $activeCompanyId) {
            flash_set('garage_error', 'You can only update garages in your company.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . $selectedCompanyId);
        }

        if (!$isSuperAdmin && $statusCode === 'DELETED') {
            flash_set('garage_error', 'Only Super Admin can delete garages.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . $companyId . '&edit_id=' . $garageId);
        }

        $legacyStatus = $statusCode === 'ACTIVE' ? 'active' : 'inactive';

        try {
            $stmt = db()->prepare(
                'UPDATE garages
                 SET name = :name,
                     code = :code,
                     phone = :phone,
                     email = :email,
                     gstin = :gstin,
                     address_line1 = :address_line1,
                     address_line2 = :address_line2,
                     city = :city,
                     state = :state,
                     pincode = :pincode,
                     status = :status,
                     status_code = :status_code,
                     deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
                 WHERE id = :id
                   AND company_id = :company_id'
            );
            $stmt->execute([
                'name' => $name,
                'code' => $code,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'gstin' => $gstin !== '' ? $gstin : null,
                'address_line1' => $address1 !== '' ? $address1 : null,
                'address_line2' => $address2 !== '' ? $address2 : null,
                'city' => $city !== '' ? $city : null,
                'state' => $state !== '' ? $state : null,
                'pincode' => $pincode !== '' ? $pincode : null,
                'status' => $legacyStatus,
                'status_code' => $statusCode,
                'id' => $garageId,
                'company_id' => $companyId,
            ]);

            log_audit('garages', 'update', $garageId, 'Updated garage ' . $code);
            flash_set('garage_success', 'Garage updated successfully.', 'success');
        } catch (Throwable $exception) {
            flash_set('garage_error', 'Unable to update garage. Code must be unique per company.', 'danger');
        }

        redirect('modules/organization/garages.php?company_id=' . $companyId);
    }

    if ($action === 'change_status') {
        $garageId = post_int('garage_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));

        if ($garageId <= 0 || $companyId <= 0) {
            flash_set('garage_error', 'Invalid garage selected.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . $selectedCompanyId);
        }

        if (!$isSuperAdmin && $companyId !== $activeCompanyId) {
            flash_set('garage_error', 'You can only change garages in your company.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . $selectedCompanyId);
        }

        if (!$isSuperAdmin && $nextStatus === 'DELETED') {
            flash_set('garage_error', 'Only Super Admin can delete garages.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . $companyId);
        }

        $legacyStatus = $nextStatus === 'ACTIVE' ? 'active' : 'inactive';

        $stmt = db()->prepare(
            'UPDATE garages
             SET status = :status,
                 status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'status' => $legacyStatus,
            'status_code' => $nextStatus,
            'id' => $garageId,
            'company_id' => $companyId,
        ]);

        log_audit('garages', 'status', $garageId, 'Changed status to ' . $nextStatus);
        flash_set('garage_success', 'Garage status updated.', 'success');
        redirect('modules/organization/garages.php?company_id=' . $companyId);
    }
}

$editId = get_int('edit_id');
$editGarage = null;
if ($editId > 0) {
    $editStmt = db()->prepare(
        'SELECT *
         FROM garages
         WHERE id = :id
           AND company_id = :company_id
         LIMIT 1'
    );
    $editStmt->execute([
        'id' => $editId,
        'company_id' => $selectedCompanyId,
    ]);
    $editGarage = $editStmt->fetch() ?: null;
}

$garageListStmt = db()->prepare(
    'SELECT g.*, c.name AS company_name
     FROM garages g
     INNER JOIN companies c ON c.id = g.company_id
     WHERE g.company_id = :company_id
     ORDER BY g.id DESC'
);
$garageListStmt->execute(['company_id' => $selectedCompanyId]);
$garages = $garageListStmt->fetchAll();

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
        <div class="col-sm-6"><h3 class="mb-0">Garage / Branch Master</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Garage Master</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($isSuperAdmin): ?>
        <div class="card card-outline card-primary">
          <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
              <div class="col-md-4">
                <label class="form-label">Company Scope</label>
                <select name="company_id" class="form-select" onchange="this.form.submit()">
                  <?php foreach ($companyOptions as $company): ?>
                    <option value="<?= (int) $company['id']; ?>" <?= ((int) $company['id'] === $selectedCompanyId) ? 'selected' : ''; ?>>
                      <?= e((string) $company['name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($canManage): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editGarage ? 'Edit Garage / Branch' : 'Add Garage / Branch'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editGarage ? 'update' : 'create'; ?>" />
              <input type="hidden" name="garage_id" value="<?= (int) ($editGarage['id'] ?? 0); ?>" />
              <input type="hidden" name="company_id" value="<?= (int) $selectedCompanyId; ?>" />

              <div class="col-md-4">
                <label class="form-label">Garage Name</label>
                <input type="text" name="name" class="form-control" required value="<?= e((string) ($editGarage['name'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Code</label>
                <input type="text" name="code" class="form-control" required value="<?= e((string) ($editGarage['code'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= e((string) ($editGarage['phone'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= e((string) ($editGarage['email'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">GSTIN</label>
                <input type="text" name="gstin" class="form-control" maxlength="15" value="<?= e((string) ($editGarage['gstin'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Address Line 1</label>
                <input type="text" name="address_line1" class="form-control" value="<?= e((string) ($editGarage['address_line1'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Address Line 2</label>
                <input type="text" name="address_line2" class="form-control" value="<?= e((string) ($editGarage['address_line2'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">City</label>
                <input type="text" name="city" class="form-control" value="<?= e((string) ($editGarage['city'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">State</label>
                <input type="text" name="state" class="form-control" value="<?= e((string) ($editGarage['state'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Pincode</label>
                <input type="text" name="pincode" class="form-control" maxlength="10" value="<?= e((string) ($editGarage['pincode'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editGarage['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <?php if (in_array($option['value'], $statusChoices, true)): ?>
                      <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editGarage ? 'Update Garage' : 'Create Garage'; ?></button>
              <?php if ($editGarage): ?>
                <a href="<?= e(url('modules/organization/garages.php?company_id=' . $selectedCompanyId)); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Garage List</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Garage</th>
                <th>Code</th>
                <th>Location</th>
                <th>Phone</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($garages)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No garages found.</td></tr>
              <?php else: ?>
                <?php foreach ($garages as $garage): ?>
                  <tr>
                    <td><?= (int) $garage['id']; ?></td>
                    <td><?= e((string) $garage['name']); ?><br><small class="text-muted"><?= e((string) ($garage['company_name'] ?? '')); ?></small></td>
                    <td><code><?= e((string) $garage['code']); ?></code></td>
                    <td><?= e((string) (($garage['city'] ?? '-') . ', ' . ($garage['state'] ?? '-'))); ?></td>
                    <td><?= e((string) ($garage['phone'] ?? '-')); ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $garage['status_code'])); ?>"><?= e(record_status_label((string) $garage['status_code'])); ?></span></td>
                    <td class="d-flex gap-1">
                      <?php if ($canManage): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/organization/garages.php?company_id=' . $selectedCompanyId . '&edit_id=' . (int) $garage['id'])); ?>">Edit</a>
                        <?php if ((string) $garage['status_code'] !== 'DELETED'): ?>
                          <form method="post" class="d-inline" data-confirm="Change garage status?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="company_id" value="<?= (int) $selectedCompanyId; ?>" />
                            <input type="hidden" name="garage_id" value="<?= (int) $garage['id']; ?>" />
                            <input type="hidden" name="next_status" value="<?= e(((string) $garage['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $garage['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                          </form>
                        <?php endif; ?>
                        <?php if ($isSuperAdmin && (string) $garage['status_code'] !== 'DELETED'): ?>
                          <form method="post" class="d-inline" data-confirm="Soft delete this garage?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="company_id" value="<?= (int) $selectedCompanyId; ?>" />
                            <input type="hidden" name="garage_id" value="<?= (int) $garage['id']; ?>" />
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
