<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('company.manage');

$page_title = 'Companies';
$active_menu = 'organization.companies';
$isSuperAdmin = (string) ($_SESSION['role_key'] ?? '') === 'super_admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create') {
        $name = post_string('name', 120);
        $legalName = post_string('legal_name', 160);
        $gstin = strtoupper(post_string('gstin', 15));
        $pan = strtoupper(post_string('pan', 10));
        $phone = post_string('phone', 20);
        $email = post_string('email', 120);
        $address = post_string('address_line1', 200);
        $city = post_string('city', 80);
        $state = post_string('state', 80);
        $pincode = post_string('pincode', 10);

        if ($name === '') {
            flash_set('company_error', 'Company name is required.', 'danger');
            redirect('modules/organization/companies.php');
        }

        if (!$isSuperAdmin) {
            flash_set('company_error', 'Only Super Admin can create new companies.', 'danger');
            redirect('modules/organization/companies.php');
        }

        $stmt = db()->prepare(
            'INSERT INTO companies
                (name, legal_name, gstin, pan, phone, email, address_line1, city, state, pincode)
             VALUES
                (:name, :legal_name, :gstin, :pan, :phone, :email, :address_line1, :city, :state, :pincode)'
        );
        $stmt->execute([
            'name' => $name,
            'legal_name' => $legalName !== '' ? $legalName : null,
            'gstin' => $gstin !== '' ? $gstin : null,
            'pan' => $pan !== '' ? $pan : null,
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'address_line1' => $address !== '' ? $address : null,
            'city' => $city !== '' ? $city : null,
            'state' => $state !== '' ? $state : null,
            'pincode' => $pincode !== '' ? $pincode : null,
        ]);

        flash_set('company_success', 'Company created successfully.', 'success');
        redirect('modules/organization/companies.php');
    }

    if ($action === 'toggle_status') {
        $companyId = post_int('company_id');
        $nextStatus = (string) ($_POST['next_status'] ?? 'inactive');
        $nextStatus = $nextStatus === 'active' ? 'active' : 'inactive';

        if (!$isSuperAdmin) {
            flash_set('company_error', 'Only Super Admin can change company status.', 'danger');
            redirect('modules/organization/companies.php');
        }

        $stmt = db()->prepare('UPDATE companies SET status = :status WHERE id = :id');
        $stmt->execute([
            'status' => $nextStatus,
            'id' => $companyId,
        ]);

        flash_set('company_success', 'Company status updated.', 'success');
        redirect('modules/organization/companies.php');
    }
}

if ($isSuperAdmin) {
    $stmt = db()->query('SELECT * FROM companies ORDER BY id DESC');
    $companies = $stmt->fetchAll();
} else {
    $stmt = db()->prepare('SELECT * FROM companies WHERE id = :company_id LIMIT 1');
    $stmt->execute(['company_id' => active_company_id()]);
    $companies = $stmt->fetchAll();
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Companies</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Companies</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($isSuperAdmin): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title">Add Company</h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="create" />
              <div class="col-md-4">
                <label class="form-label">Company Name</label>
                <input type="text" name="name" class="form-control" required />
              </div>
              <div class="col-md-4">
                <label class="form-label">Legal Name</label>
                <input type="text" name="legal_name" class="form-control" />
              </div>
              <div class="col-md-4">
                <label class="form-label">GSTIN</label>
                <input type="text" name="gstin" class="form-control" maxlength="15" />
              </div>
              <div class="col-md-3">
                <label class="form-label">PAN</label>
                <input type="text" name="pan" class="form-control" maxlength="10" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Pincode</label>
                <input type="text" name="pincode" class="form-control" maxlength="10" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Address</label>
                <input type="text" name="address_line1" class="form-control" />
              </div>
              <div class="col-md-3">
                <label class="form-label">City</label>
                <input type="text" name="city" class="form-control" />
              </div>
              <div class="col-md-3">
                <label class="form-label">State</label>
                <input type="text" name="state" class="form-control" />
              </div>
            </div>
            <div class="card-footer">
              <button type="submit" class="btn btn-primary">Save Company</button>
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
                <th>Name</th>
                <th>GSTIN</th>
                <th>City</th>
                <th>State</th>
                <th>Status</th>
                <?php if ($isSuperAdmin): ?><th>Action</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($companies)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No companies found.</td></tr>
              <?php else: ?>
                <?php foreach ($companies as $company): ?>
                  <tr>
                    <td><?= (int) $company['id']; ?></td>
                    <td><?= e((string) $company['name']); ?></td>
                    <td><?= e((string) ($company['gstin'] ?? '-')); ?></td>
                    <td><?= e((string) ($company['city'] ?? '-')); ?></td>
                    <td><?= e((string) ($company['state'] ?? '-')); ?></td>
                    <td>
                      <span class="badge text-bg-<?= $company['status'] === 'active' ? 'success' : 'secondary'; ?>">
                        <?= e((string) $company['status']); ?>
                      </span>
                    </td>
                    <?php if ($isSuperAdmin): ?>
                      <td>
                        <form method="post" class="d-inline" data-confirm="Change company status?">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="toggle_status" />
                          <input type="hidden" name="company_id" value="<?= (int) $company['id']; ?>" />
                          <input type="hidden" name="next_status" value="<?= $company['status'] === 'active' ? 'inactive' : 'active'; ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-primary">
                            <?= $company['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
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
