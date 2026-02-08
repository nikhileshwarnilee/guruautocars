<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('staff.manage');

$page_title = 'Staff Management';
$active_menu = 'organization.staff';
$isSuperAdmin = (string) ($_SESSION['role_key'] ?? '') === 'super_admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create_staff') {
        $companyId = $isSuperAdmin ? post_int('company_id', active_company_id()) : active_company_id();
        $name = post_string('name', 120);
        $email = strtolower(post_string('email', 150));
        $username = strtolower(post_string('username', 80));
        $phone = post_string('phone', 20);
        $roleId = post_int('role_id');
        $primaryGarageId = post_int('primary_garage_id');
        $password = (string) ($_POST['password'] ?? '');

        $selectedGarages = $_POST['garage_ids'] ?? [];
        if (!is_array($selectedGarages)) {
            $selectedGarages = [];
        }

        $garageIds = [];
        foreach ($selectedGarages as $garageId) {
            if (filter_var($garageId, FILTER_VALIDATE_INT) !== false) {
                $garageIds[] = (int) $garageId;
            }
        }

        if ($name === '' || $email === '' || $username === '' || $password === '' || $roleId <= 0 || $primaryGarageId <= 0) {
            flash_set('staff_error', 'Name, email, username, role, primary garage, and password are required.', 'danger');
            redirect('modules/organization/staff.php');
        }

        if (strlen($password) < 8) {
            flash_set('staff_error', 'Password must be at least 8 characters.', 'danger');
            redirect('modules/organization/staff.php');
        }

        if (empty($garageIds)) {
            $garageIds[] = $primaryGarageId;
        } elseif (!in_array($primaryGarageId, $garageIds, true)) {
            $garageIds[] = $primaryGarageId;
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users
                  (company_id, role_id, primary_garage_id, name, email, username, password_hash, phone, is_active)
                 VALUES
                  (:company_id, :role_id, :primary_garage_id, :name, :email, :username, :password_hash, :phone, 1)'
            );
            $stmt->execute([
                'company_id' => $companyId,
                'role_id' => $roleId,
                'primary_garage_id' => $primaryGarageId,
                'name' => $name,
                'email' => $email,
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'phone' => $phone !== '' ? $phone : null,
            ]);

            $userId = (int) $pdo->lastInsertId();

            $garageStmt = $pdo->prepare('INSERT INTO user_garages (user_id, garage_id) VALUES (:user_id, :garage_id)');
            foreach (array_unique($garageIds) as $garageId) {
                $garageStmt->execute([
                    'user_id' => $userId,
                    'garage_id' => $garageId,
                ]);
            }

            $pdo->commit();
            flash_set('staff_success', 'Staff user created successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('staff_error', 'Unable to create staff. Email/username may already exist.', 'danger');
        }

        redirect('modules/organization/staff.php');
    }

    if ($action === 'toggle_active') {
        $userId = post_int('user_id');
        $nextActive = post_int('next_active', 0) === 1 ? 1 : 0;

        if ($userId === (int) $_SESSION['user_id']) {
            flash_set('staff_error', 'You cannot deactivate your own account.', 'danger');
            redirect('modules/organization/staff.php');
        }

        $stmt = db()->prepare(
            'UPDATE users
             SET is_active = :is_active
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'is_active' => $nextActive,
            'id' => $userId,
            'company_id' => $isSuperAdmin ? post_int('company_id', active_company_id()) : active_company_id(),
        ]);

        flash_set('staff_success', 'Staff status updated.', 'success');
        redirect('modules/organization/staff.php');
    }
}

if ($isSuperAdmin) {
    $companies = db()->query('SELECT id, name FROM companies WHERE status = "active" ORDER BY name ASC')->fetchAll();

    $roles = db()->query('SELECT id, role_name, role_key FROM roles ORDER BY id ASC')->fetchAll();

    $garages = db()->query('SELECT id, company_id, name, code FROM garages WHERE status = "active" ORDER BY name ASC')->fetchAll();

    $staff = db()->query(
        'SELECT u.id, u.company_id, u.name, u.email, u.username, u.phone, u.is_active,
                r.role_name, c.name AS company_name, g.name AS primary_garage
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         INNER JOIN companies c ON c.id = u.company_id
         LEFT JOIN garages g ON g.id = u.primary_garage_id
         ORDER BY u.id DESC'
    )->fetchAll();
} else {
    $companiesStmt = db()->prepare('SELECT id, name FROM companies WHERE id = :company_id LIMIT 1');
    $companiesStmt->execute(['company_id' => active_company_id()]);
    $companies = $companiesStmt->fetchAll();

    $roles = db()->query('SELECT id, role_name, role_key FROM roles WHERE role_key <> "super_admin" ORDER BY id ASC')->fetchAll();

    $garagesStmt = db()->prepare('SELECT id, company_id, name, code FROM garages WHERE company_id = :company_id AND status = "active" ORDER BY name ASC');
    $garagesStmt->execute(['company_id' => active_company_id()]);
    $garages = $garagesStmt->fetchAll();

    $staffStmt = db()->prepare(
        'SELECT u.id, u.company_id, u.name, u.email, u.username, u.phone, u.is_active,
                r.role_name, c.name AS company_name, g.name AS primary_garage
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         INNER JOIN companies c ON c.id = u.company_id
         LEFT JOIN garages g ON g.id = u.primary_garage_id
         WHERE u.company_id = :company_id
         ORDER BY u.id DESC'
    );
    $staffStmt->execute(['company_id' => active_company_id()]);
    $staff = $staffStmt->fetchAll();
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Staff Management</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Staff</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-primary">
        <div class="card-header"><h3 class="card-title">Add Staff User</h3></div>
        <form method="post">
          <div class="card-body row g-3">
            <?= csrf_field(); ?>
            <input type="hidden" name="_action" value="create_staff" />

            <div class="col-md-3">
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

            <div class="col-md-3">
              <label class="form-label">Name</label>
              <input type="text" name="name" class="form-control" required />
            </div>
            <div class="col-md-3">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" required />
            </div>
            <div class="col-md-3">
              <label class="form-label">Username</label>
              <input type="text" name="username" class="form-control" required />
            </div>

            <div class="col-md-3">
              <label class="form-label">Phone</label>
              <input type="text" name="phone" class="form-control" />
            </div>
            <div class="col-md-3">
              <label class="form-label">Role</label>
              <select name="role_id" class="form-select" required>
                <option value="">Select Role</option>
                <?php foreach ($roles as $role): ?>
                  <option value="<?= (int) $role['id']; ?>"><?= e((string) $role['role_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Primary Garage</label>
              <select name="primary_garage_id" class="form-select" required>
                <option value="">Select Garage</option>
                <?php foreach ($garages as $garage): ?>
                  <option value="<?= (int) $garage['id']; ?>"><?= e((string) $garage['name']); ?> (<?= e((string) $garage['code']); ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Password</label>
              <input type="password" name="password" class="form-control" minlength="8" required />
            </div>

            <div class="col-md-12">
              <label class="form-label">Garage Access</label>
              <select name="garage_ids[]" class="form-select" multiple size="4">
                <?php foreach ($garages as $garage): ?>
                  <option value="<?= (int) $garage['id']; ?>"><?= e((string) $garage['name']); ?> (<?= e((string) $garage['code']); ?>)</option>
                <?php endforeach; ?>
              </select>
              <div class="form-hint">Hold Ctrl (Windows) to select multiple garages.</div>
            </div>
          </div>
          <div class="card-footer">
            <button type="submit" class="btn btn-primary">Create Staff</button>
          </div>
        </form>
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Staff List</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Username</th>
                <th>Role</th>
                <th>Primary Garage</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($staff)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No staff users found.</td></tr>
              <?php else: ?>
                <?php foreach ($staff as $member): ?>
                  <tr>
                    <td><?= (int) $member['id']; ?></td>
                    <td><?= e((string) $member['name']); ?><br><small class="text-muted"><?= e((string) $member['email']); ?></small></td>
                    <td><?= e((string) $member['username']); ?></td>
                    <td><?= e((string) $member['role_name']); ?></td>
                    <td><?= e((string) ($member['primary_garage'] ?? '-')); ?></td>
                    <td>
                      <span class="badge text-bg-<?= ((int) $member['is_active'] === 1) ? 'success' : 'secondary'; ?>">
                        <?= ((int) $member['is_active'] === 1) ? 'Active' : 'Inactive'; ?>
                      </span>
                    </td>
                    <td>
                      <?php if ((int) $member['id'] !== (int) $_SESSION['user_id']): ?>
                        <form method="post" class="d-inline" data-confirm="Change user status?">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="toggle_active" />
                          <input type="hidden" name="user_id" value="<?= (int) $member['id']; ?>" />
                          <input type="hidden" name="company_id" value="<?= (int) $member['company_id']; ?>" />
                          <input type="hidden" name="next_active" value="<?= ((int) $member['is_active'] === 1) ? '0' : '1'; ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-primary">
                            <?= ((int) $member['is_active'] === 1) ? 'Deactivate' : 'Activate'; ?>
                          </button>
                        </form>
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
