<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('garage.manage');

$page_title = 'Garages';
$active_menu = 'organization.garages';
$isSuperAdmin = (string) ($_SESSION['role_key'] ?? '') === 'super_admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create') {
        $companyId = $isSuperAdmin ? post_int('company_id', active_company_id()) : active_company_id();
        $name = post_string('name', 140);
        $code = strtoupper(post_string('code', 30));
        $phone = post_string('phone', 20);
        $email = post_string('email', 120);
        $gstin = strtoupper(post_string('gstin', 15));
        $address = post_string('address_line1', 200);
        $city = post_string('city', 80);
        $state = post_string('state', 80);
        $pincode = post_string('pincode', 10);

        if ($name === '' || $code === '' || $companyId <= 0) {
            flash_set('garage_error', 'Company, garage name and code are required.', 'danger');
            redirect('modules/organization/garages.php');
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO garages
                  (company_id, name, code, phone, email, gstin, address_line1, city, state, pincode)
                 VALUES
                  (:company_id, :name, :code, :phone, :email, :gstin, :address_line1, :city, :state, :pincode)'
            );
            $stmt->execute([
                'company_id' => $companyId,
                'name' => $name,
                'code' => $code,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'gstin' => $gstin !== '' ? $gstin : null,
                'address_line1' => $address !== '' ? $address : null,
                'city' => $city !== '' ? $city : null,
                'state' => $state !== '' ? $state : null,
                'pincode' => $pincode !== '' ? $pincode : null,
            ]);

            $garageId = (int) $pdo->lastInsertId();

            $counterStmt = $pdo->prepare('INSERT INTO job_counters (garage_id, prefix, current_number) VALUES (:garage_id, "JOB", 1000)');
            $counterStmt->execute(['garage_id' => $garageId]);

            $invoiceCounterStmt = $pdo->prepare('INSERT INTO invoice_counters (garage_id, prefix, current_number) VALUES (:garage_id, "INV", 5000)');
            $invoiceCounterStmt->execute(['garage_id' => $garageId]);

            $pdo->commit();
            flash_set('garage_success', 'Garage created successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('garage_error', 'Unable to create garage. Code must be unique per company.', 'danger');
        }

        redirect('modules/organization/garages.php');
    }

    if ($action === 'toggle_status') {
        $garageId = post_int('garage_id');
        $nextStatus = (string) ($_POST['next_status'] ?? 'inactive');
        $nextStatus = $nextStatus === 'active' ? 'active' : 'inactive';

        $stmt = db()->prepare(
            'UPDATE garages
             SET status = :status
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'status' => $nextStatus,
            'id' => $garageId,
            'company_id' => $isSuperAdmin ? post_int('company_id', active_company_id()) : active_company_id(),
        ]);

        flash_set('garage_success', 'Garage status updated.', 'success');
        redirect('modules/organization/garages.php');
    }
}

$companyQuery = $isSuperAdmin
    ? 'SELECT id, name FROM companies WHERE status = "active" ORDER BY name ASC'
    : 'SELECT id, name FROM companies WHERE id = :company_id LIMIT 1';

if ($isSuperAdmin) {
    $companiesStmt = db()->query($companyQuery);
} else {
    $companiesStmt = db()->prepare($companyQuery);
    $companiesStmt->execute(['company_id' => active_company_id()]);
}
$companies = $companiesStmt->fetchAll();

$garagesStmt = $isSuperAdmin
    ? db()->query(
        'SELECT g.*, c.name AS company_name
         FROM garages g
         INNER JOIN companies c ON c.id = g.company_id
         ORDER BY g.id DESC'
    )
    : null;

if ($isSuperAdmin) {
    $garages = $garagesStmt->fetchAll();
} else {
    $garagesStmt = db()->prepare(
        'SELECT g.*, c.name AS company_name
         FROM garages g
         INNER JOIN companies c ON c.id = g.company_id
         WHERE g.company_id = :company_id
         ORDER BY g.id DESC'
    );
    $garagesStmt->execute(['company_id' => active_company_id()]);
    $garages = $garagesStmt->fetchAll();
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Garages / Branches</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Garages</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-primary">
        <div class="card-header"><h3 class="card-title">Add Garage</h3></div>
        <form method="post">
          <div class="card-body row g-3">
            <?= csrf_field(); ?>
            <input type="hidden" name="_action" value="create" />
            <div class="col-md-4">
              <label class="form-label">Company</label>
              <select name="company_id" class="form-select" <?= $isSuperAdmin ? '' : 'disabled'; ?> required>
                <?php foreach ($companies as $company): ?>
                  <option value="<?= (int) $company['id']; ?>"><?= e((string) $company['name']); ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!$isSuperAdmin && !empty($companies)): ?>
                <input type="hidden" name="company_id" value="<?= (int) $companies[0]['id']; ?>" />
              <?php endif; ?>
            </div>
            <div class="col-md-4">
              <label class="form-label">Garage Name</label>
              <input type="text" name="name" class="form-control" required />
            </div>
            <div class="col-md-4">
              <label class="form-label">Code</label>
              <input type="text" name="code" class="form-control" required />
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
              <label class="form-label">GSTIN</label>
              <input type="text" name="gstin" class="form-control" maxlength="15" />
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
            <button type="submit" class="btn btn-primary">Save Garage</button>
          </div>
        </form>
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Garage List</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Company</th>
                <th>Garage</th>
                <th>Code</th>
                <th>City</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($garages)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No garages found.</td></tr>
              <?php else: ?>
                <?php foreach ($garages as $garage): ?>
                  <tr>
                    <td><?= (int) $garage['id']; ?></td>
                    <td><?= e((string) $garage['company_name']); ?></td>
                    <td><?= e((string) $garage['name']); ?></td>
                    <td><?= e((string) $garage['code']); ?></td>
                    <td><?= e((string) ($garage['city'] ?? '-')); ?></td>
                    <td>
                      <span class="badge text-bg-<?= $garage['status'] === 'active' ? 'success' : 'secondary'; ?>">
                        <?= e((string) $garage['status']); ?>
                      </span>
                    </td>
                    <td>
                      <form method="post" class="d-inline" data-confirm="Change garage status?">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="_action" value="toggle_status" />
                        <input type="hidden" name="garage_id" value="<?= (int) $garage['id']; ?>" />
                        <input type="hidden" name="company_id" value="<?= (int) $garage['company_id']; ?>" />
                        <input type="hidden" name="next_status" value="<?= $garage['status'] === 'active' ? 'inactive' : 'active'; ?>" />
                        <button type="submit" class="btn btn-sm btn-outline-primary">
                          <?= $garage['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                        </button>
                      </form>
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
