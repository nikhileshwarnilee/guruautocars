<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();

$canView = has_permission('staff.view') || has_permission('staff.manage');
if (!$canView) {
    require_permission('staff.view');
}

$canManage = has_permission('staff.manage');
$isSuperAdmin = (string) ($_SESSION['role_key'] ?? '') === 'super_admin';
$activeCompanyId = active_company_id();

$page_title = 'Staff Master';
$active_menu = 'people.staff';

function parse_staff_garage_ids(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $ids = [];
    foreach ($raw as $value) {
        if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
            $ids[] = (int) $value;
        }
    }

    return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
}

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
        flash_set('staff_error', 'You do not have permission to modify staff master.', 'danger');
        redirect('modules/organization/staff.php');
    }

    $action = (string) ($_POST['_action'] ?? '');
    $companyId = $isSuperAdmin ? post_int('company_id', $activeCompanyId) : $activeCompanyId;

    if ($action === 'create') {
        $name = post_string('name', 120);
        $email = strtolower(post_string('email', 150));
        $username = strtolower(post_string('username', 80));
        $phone = post_string('phone', 20);
        $roleId = post_int('role_id');
        $primaryGarageId = post_int('primary_garage_id');
        $password = (string) ($_POST['password'] ?? '');
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        $garageIds = parse_staff_garage_ids($_POST['garage_ids'] ?? []);

        if ($companyId <= 0 || $name === '' || $email === '' || $username === '' || $roleId <= 0 || $primaryGarageId <= 0 || $password === '') {
            flash_set('staff_error', 'Company, name, email, username, role, primary garage and password are required.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $selectedCompanyId);
        }

        if (strlen($password) < 8) {
            flash_set('staff_error', 'Password must be at least 8 characters.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $selectedCompanyId);
        }

        if (!$isSuperAdmin && $companyId !== $activeCompanyId) {
            flash_set('staff_error', 'You can only create staff in your company.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $selectedCompanyId);
        }

        if (empty($garageIds)) {
            $garageIds[] = $primaryGarageId;
        } elseif (!in_array($primaryGarageId, $garageIds, true)) {
            $garageIds[] = $primaryGarageId;
        }

        $roleStmt = db()->prepare('SELECT id, role_key FROM roles WHERE id = :id AND status_code <> "DELETED" LIMIT 1');
        $roleStmt->execute(['id' => $roleId]);
        $role = $roleStmt->fetch();

        if (!$role) {
            flash_set('staff_error', 'Invalid role selected.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $selectedCompanyId);
        }

        if (!$isSuperAdmin && (string) $role['role_key'] === 'super_admin') {
            flash_set('staff_error', 'Only Super Admin can assign Super Admin role.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $selectedCompanyId);
        }

        $placeholders = implode(',', array_fill(0, count($garageIds), '?'));
        $garageCheckStmt = db()->prepare(
            "SELECT id
             FROM garages
             WHERE company_id = ?
               AND id IN ({$placeholders})
               AND status_code <> 'DELETED'"
        );
        $garageCheckStmt->execute(array_merge([$companyId], $garageIds));
        $validGarageIds = array_map(static fn (array $row): int => (int) $row['id'], $garageCheckStmt->fetchAll());

        if (!in_array($primaryGarageId, $validGarageIds, true)) {
            flash_set('staff_error', 'Primary garage must be active and within selected company.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $selectedCompanyId);
        }

        if (empty($validGarageIds)) {
            flash_set('staff_error', 'Select at least one valid garage.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $selectedCompanyId);
        }

        $isActive = $statusCode === 'ACTIVE' ? 1 : 0;
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $insertStmt = $pdo->prepare(
                'INSERT INTO users
                  (company_id, role_id, primary_garage_id, name, email, username, password_hash, phone, is_active, status_code, deleted_at)
                 VALUES
                  (:company_id, :role_id, :primary_garage_id, :name, :email, :username, :password_hash, :phone, :is_active, :status_code, :deleted_at)'
            );
            $insertStmt->execute([
                'company_id' => $companyId,
                'role_id' => $roleId,
                'primary_garage_id' => $primaryGarageId,
                'name' => $name,
                'email' => $email,
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'phone' => $phone !== '' ? $phone : null,
                'is_active' => $isActive,
                'status_code' => $statusCode,
                'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
            ]);

            $userId = (int) $pdo->lastInsertId();
            $garageInsertStmt = $pdo->prepare('INSERT INTO user_garages (user_id, garage_id) VALUES (:user_id, :garage_id)');
            foreach ($validGarageIds as $garageId) {
                $garageInsertStmt->execute([
                    'user_id' => $userId,
                    'garage_id' => $garageId,
                ]);
            }

            $pdo->commit();
            log_audit('staff', 'create', $userId, 'Created staff user ' . $username, [
                'entity' => 'staff',
                'source' => 'UI',
                'company_id' => $companyId,
                'garage_id' => $primaryGarageId,
                'before' => ['exists' => false],
                'after' => [
                    'user_id' => $userId,
                    'username' => $username,
                    'role_id' => $roleId,
                    'primary_garage_id' => $primaryGarageId,
                    'status_code' => $statusCode,
                ],
                'metadata' => [
                    'assigned_garages' => $validGarageIds,
                ],
            ]);
            flash_set('staff_success', 'Staff user created successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('staff_error', 'Unable to create staff. Email/username may already exist.', 'danger');
        }

        redirect('modules/organization/staff.php?company_id=' . $companyId);
    }

    if ($action === 'update') {
        $userId = post_int('user_id');
        $name = post_string('name', 120);
        $email = strtolower(post_string('email', 150));
        $username = strtolower(post_string('username', 80));
        $phone = post_string('phone', 20);
        $roleId = post_int('role_id');
        $primaryGarageId = post_int('primary_garage_id');
        $password = (string) ($_POST['password'] ?? '');
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        $garageIds = parse_staff_garage_ids($_POST['garage_ids'] ?? []);

        if ($userId <= 0 || $companyId <= 0 || $name === '' || $email === '' || $username === '' || $roleId <= 0 || $primaryGarageId <= 0) {
            flash_set('staff_error', 'Invalid staff update payload.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $selectedCompanyId);
        }

        $currentUserStmt = db()->prepare(
            'SELECT u.id, u.company_id, u.username, r.role_key
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $currentUserStmt->execute(['id' => $userId]);
        $currentUserRecord = $currentUserStmt->fetch();

        if (!$currentUserRecord) {
            flash_set('staff_error', 'Staff user not found.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $selectedCompanyId);
        }

        if (!$isSuperAdmin && (int) $currentUserRecord['company_id'] !== $activeCompanyId) {
            flash_set('staff_error', 'You can only update staff in your company.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $selectedCompanyId);
        }

        if ($userId === (int) ($_SESSION['user_id'] ?? 0) && $statusCode !== 'ACTIVE') {
            flash_set('staff_error', 'You cannot inactivate or delete your own account.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $companyId . '&edit_id=' . $userId);
        }

        if (!$isSuperAdmin && (string) $currentUserRecord['role_key'] === 'super_admin') {
            flash_set('staff_error', 'Only Super Admin can modify Super Admin users.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $companyId);
        }

        if (empty($garageIds)) {
            $garageIds[] = $primaryGarageId;
        } elseif (!in_array($primaryGarageId, $garageIds, true)) {
            $garageIds[] = $primaryGarageId;
        }

        $roleStmt = db()->prepare('SELECT id, role_key FROM roles WHERE id = :id AND status_code <> "DELETED" LIMIT 1');
        $roleStmt->execute(['id' => $roleId]);
        $role = $roleStmt->fetch();

        if (!$role) {
            flash_set('staff_error', 'Invalid role selected.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $selectedCompanyId);
        }

        if (!$isSuperAdmin && (string) $role['role_key'] === 'super_admin') {
            flash_set('staff_error', 'Only Super Admin can assign Super Admin role.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $selectedCompanyId);
        }

        $placeholders = implode(',', array_fill(0, count($garageIds), '?'));
        $garageCheckStmt = db()->prepare(
            "SELECT id
             FROM garages
             WHERE company_id = ?
               AND id IN ({$placeholders})
               AND status_code <> 'DELETED'"
        );
        $garageCheckStmt->execute(array_merge([$companyId], $garageIds));
        $validGarageIds = array_map(static fn (array $row): int => (int) $row['id'], $garageCheckStmt->fetchAll());

        if (!in_array($primaryGarageId, $validGarageIds, true) || empty($validGarageIds)) {
            flash_set('staff_error', 'Select valid garage assignments.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $selectedCompanyId);
        }

        if ($password !== '' && strlen($password) < 8) {
            flash_set('staff_error', 'New password must be at least 8 characters.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $companyId . '&edit_id=' . $userId);
        }

        $beforeUserStmt = db()->prepare(
            'SELECT role_id, primary_garage_id, name, email, username, phone, status_code, is_active
             FROM users
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1'
        );
        $beforeUserStmt->execute([
            'id' => $userId,
            'company_id' => $companyId,
        ]);
        $beforeUser = $beforeUserStmt->fetch() ?: null;

        $isActive = $statusCode === 'ACTIVE' ? 1 : 0;

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $baseSql =
                'UPDATE users
                 SET role_id = :role_id,
                     primary_garage_id = :primary_garage_id,
                     name = :name,
                     email = :email,
                     username = :username,
                     phone = :phone,
                     is_active = :is_active,
                     status_code = :status_code,
                     deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END';

            if ($password !== '') {
                $baseSql .= ', password_hash = :password_hash';
            }

            $baseSql .= ' WHERE id = :id AND company_id = :company_id';

            $params = [
                'role_id' => $roleId,
                'primary_garage_id' => $primaryGarageId,
                'name' => $name,
                'email' => $email,
                'username' => $username,
                'phone' => $phone !== '' ? $phone : null,
                'is_active' => $isActive,
                'status_code' => $statusCode,
                'id' => $userId,
                'company_id' => $companyId,
            ];

            if ($password !== '') {
                $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $updateStmt = $pdo->prepare($baseSql);
            $updateStmt->execute($params);

            $deleteMapStmt = $pdo->prepare('DELETE FROM user_garages WHERE user_id = :user_id');
            $deleteMapStmt->execute(['user_id' => $userId]);

            $insertMapStmt = $pdo->prepare('INSERT INTO user_garages (user_id, garage_id) VALUES (:user_id, :garage_id)');
            foreach ($validGarageIds as $garageId) {
                $insertMapStmt->execute([
                    'user_id' => $userId,
                    'garage_id' => $garageId,
                ]);
            }

            $pdo->commit();
            log_audit('staff', 'update', $userId, 'Updated staff user ' . $username, [
                'entity' => 'staff',
                'source' => 'UI',
                'company_id' => $companyId,
                'garage_id' => $primaryGarageId,
                'before' => is_array($beforeUser) ? [
                    'role_id' => (int) ($beforeUser['role_id'] ?? 0),
                    'primary_garage_id' => (int) ($beforeUser['primary_garage_id'] ?? 0),
                    'name' => (string) ($beforeUser['name'] ?? ''),
                    'email' => (string) ($beforeUser['email'] ?? ''),
                    'username' => (string) ($beforeUser['username'] ?? ''),
                    'status_code' => (string) ($beforeUser['status_code'] ?? ''),
                ] : null,
                'after' => [
                    'role_id' => $roleId,
                    'primary_garage_id' => $primaryGarageId,
                    'name' => $name,
                    'email' => $email,
                    'username' => $username,
                    'status_code' => $statusCode,
                ],
                'metadata' => [
                    'assigned_garages' => $validGarageIds,
                    'password_changed' => $password !== '',
                ],
            ]);
            flash_set('staff_success', 'Staff user updated successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('staff_error', 'Unable to update staff. Email/username may already exist.', 'danger');
        }

        redirect('modules/organization/staff.php?company_id=' . $companyId);
    }

    if ($action === 'change_status') {
        $userId = post_int('user_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));
        $safeDeleteValidation = null;

        if ($userId <= 0 || $companyId <= 0) {
            flash_set('staff_error', 'Invalid staff user selected.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $selectedCompanyId);
        }

        $userStmt = db()->prepare(
            'SELECT id, company_id, username, status_code, is_active, primary_garage_id
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $userStmt->execute(['id' => $userId]);
        $user = $userStmt->fetch();

        if (!$user) {
            flash_set('staff_error', 'Staff user not found.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $selectedCompanyId);
        }

        if (!$isSuperAdmin && (int) $user['company_id'] !== $activeCompanyId) {
            flash_set('staff_error', 'You can only update staff in your company.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $selectedCompanyId);
        }

        if ($userId === (int) ($_SESSION['user_id'] ?? 0) && $nextStatus !== 'ACTIVE') {
            flash_set('staff_error', 'You cannot inactivate or delete your own account.', 'danger');
            redirect('modules/organization/staff.php?company_id=' . $companyId);
        }
        if ($nextStatus === 'DELETED') {
            $safeDeleteValidation = safe_delete_validate_post_confirmation('org_staff_user', $userId, [
                'operation' => 'delete',
                'reason_field' => 'deletion_reason',
            ]);
        }

        $isActive = $nextStatus === 'ACTIVE' ? 1 : 0;
        $stmt = db()->prepare(
            'UPDATE users
             SET is_active = :is_active,
                 status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'is_active' => $isActive,
            'status_code' => $nextStatus,
            'id' => $userId,
            'company_id' => $companyId,
        ]);

        log_audit('staff', 'status', $userId, 'Changed status to ' . $nextStatus, [
            'entity' => 'staff',
            'source' => 'UI',
            'company_id' => $companyId,
            'garage_id' => (int) ($user['primary_garage_id'] ?? 0),
            'before' => [
                'status_code' => (string) ($user['status_code'] ?? ''),
                'is_active' => (int) ($user['is_active'] ?? 0),
            ],
            'after' => [
                'status_code' => $nextStatus,
                'is_active' => $isActive,
            ],
        ]);
        if ($nextStatus === 'DELETED' && is_array($safeDeleteValidation)) {
            safe_delete_log_cascade('org_staff_user', 'delete', $userId, $safeDeleteValidation, [
                'metadata' => [
                    'company_id' => $companyId,
                    'garage_id' => (int) ($user['primary_garage_id'] ?? 0),
                    'requested_status' => 'DELETED',
                    'applied_status' => $nextStatus,
                ],
            ]);
        }
        flash_set('staff_success', 'Staff status updated.', 'success');
        redirect('modules/organization/staff.php?company_id=' . $companyId);
    }
}

$rolesStmt = db()->prepare(
    'SELECT id, role_name, role_key
     FROM roles
     WHERE status_code <> "DELETED"
       AND (:is_super = 1 OR role_key <> "super_admin")
     ORDER BY role_name ASC'
);
$rolesStmt->execute(['is_super' => $isSuperAdmin ? 1 : 0]);
$roles = $rolesStmt->fetchAll();

$garagesStmt = db()->prepare(
    'SELECT id, name, code
     FROM garages
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY name ASC'
);
$garagesStmt->execute(['company_id' => $selectedCompanyId]);
$garages = $garagesStmt->fetchAll();

$editId = get_int('edit_id');
$editUser = null;
$editUserGarageIds = [];
if ($editId > 0) {
    $editStmt = db()->prepare(
        'SELECT u.*
         FROM users u
         WHERE u.id = :id
           AND u.company_id = :company_id
         LIMIT 1'
    );
    $editStmt->execute([
        'id' => $editId,
        'company_id' => $selectedCompanyId,
    ]);
    $editUser = $editStmt->fetch() ?: null;

    if ($editUser) {
        $mapStmt = db()->prepare('SELECT garage_id FROM user_garages WHERE user_id = :user_id');
        $mapStmt->execute(['user_id' => (int) $editUser['id']]);
        $editUserGarageIds = array_map(static fn (array $row): int => (int) $row['garage_id'], $mapStmt->fetchAll());
    }
}

$staffStmt = db()->prepare(
    'SELECT u.id, u.company_id, u.name, u.email, u.username, u.phone, u.status_code, u.is_active,
            r.role_name,
            g.name AS primary_garage_name,
            GROUP_CONCAT(DISTINCT ag.name ORDER BY ag.name SEPARATOR ", ") AS garage_access
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     LEFT JOIN garages g ON g.id = u.primary_garage_id
     LEFT JOIN user_garages ug ON ug.user_id = u.id
     LEFT JOIN garages ag ON ag.id = ug.garage_id
     WHERE u.company_id = :company_id
     GROUP BY u.id
     ORDER BY u.id DESC'
);
$staffStmt->execute(['company_id' => $selectedCompanyId]);
$staffMembers = $staffStmt->fetchAll();

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
        <div class="col-sm-6"><h3 class="mb-0">Staff Master</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Staff Master</li>
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
                <select name="company_id" class="form-select" onchange="if (this.form && typeof this.form.requestSubmit === 'function') { this.form.requestSubmit(); } else if (this.form) { this.form.submit(); }">
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
          <div class="card-header"><h3 class="card-title"><?= $editUser ? 'Edit Staff User' : 'Add Staff User'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editUser ? 'update' : 'create'; ?>" />
              <input type="hidden" name="user_id" value="<?= (int) ($editUser['id'] ?? 0); ?>" />
              <input type="hidden" name="company_id" value="<?= (int) $selectedCompanyId; ?>" />

              <div class="col-md-3">
                <label class="form-label">Full Name</label>
                <input type="text" name="name" class="form-control" required value="<?= e((string) ($editUser['name'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" required value="<?= e((string) ($editUser['email'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" required value="<?= e((string) ($editUser['username'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= e((string) ($editUser['phone'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editUser['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <?php if (in_array($option['value'], $statusChoices, true)): ?>
                      <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-3">
                <label class="form-label">Role</label>
                <select name="role_id" class="form-select" required>
                  <option value="">Select Role</option>
                  <?php foreach ($roles as $role): ?>
                    <option value="<?= (int) $role['id']; ?>" <?= ((int) ($editUser['role_id'] ?? 0) === (int) $role['id']) ? 'selected' : ''; ?>>
                      <?= e((string) $role['role_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Primary Garage</label>
                <select name="primary_garage_id" class="form-select" required>
                  <option value="">Select Garage</option>
                  <?php foreach ($garages as $garage): ?>
                    <option value="<?= (int) $garage['id']; ?>" <?= ((int) ($editUser['primary_garage_id'] ?? 0) === (int) $garage['id']) ? 'selected' : ''; ?>>
                      <?= e((string) $garage['name']); ?> (<?= e((string) $garage['code']); ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label"><?= $editUser ? 'Reset Password (Optional)' : 'Password'; ?></label>
                <input type="password" name="password" class="form-control" minlength="8" <?= $editUser ? '' : 'required'; ?> />
              </div>
              <div class="col-md-3">
                <label class="form-label">Garage Access</label>
                <select name="garage_ids[]" class="form-select" multiple size="4">
                  <?php foreach ($garages as $garage): ?>
                    <option value="<?= (int) $garage['id']; ?>" <?= in_array((int) $garage['id'], $editUserGarageIds, true) ? 'selected' : ''; ?>>
                      <?= e((string) $garage['name']); ?> (<?= e((string) $garage['code']); ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-hint">Hold Ctrl (Windows) to select multiple garages.</div>
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editUser ? 'Update Staff' : 'Create Staff'; ?></button>
              <?php if ($editUser): ?>
                <a href="<?= e(url('modules/organization/staff.php?company_id=' . $selectedCompanyId)); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Staff List</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>User</th>
                <th>Role</th>
                <th>Primary Garage</th>
                <th>Garage Access</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($staffMembers)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No staff users found.</td></tr>
              <?php else: ?>
                <?php foreach ($staffMembers as $member): ?>
                  <?php $isSelf = (int) $member['id'] === (int) ($_SESSION['user_id'] ?? 0); ?>
                  <tr>
                    <td><?= (int) $member['id']; ?></td>
                    <td>
                      <?= e((string) $member['name']); ?><br>
                      <small class="text-muted"><?= e((string) $member['email']); ?> | <?= e((string) $member['username']); ?></small>
                    </td>
                    <td><?= e((string) $member['role_name']); ?></td>
                    <td><?= e((string) ($member['primary_garage_name'] ?? '-')); ?></td>
                    <td><?= e((string) ($member['garage_access'] ?? '-')); ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $member['status_code'])); ?>"><?= e(record_status_label((string) $member['status_code'])); ?></span></td>
                    <td class="d-flex gap-1">
                      <?php if ($canManage): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/organization/staff.php?company_id=' . $selectedCompanyId . '&edit_id=' . (int) $member['id'])); ?>">Edit</a>
                        <?php if (!$isSelf && (string) $member['status_code'] !== 'DELETED'): ?>
                          <form method="post" class="d-inline" data-confirm="Change user status?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="company_id" value="<?= (int) $selectedCompanyId; ?>" />
                            <input type="hidden" name="user_id" value="<?= (int) $member['id']; ?>" />
                            <input type="hidden" name="next_status" value="<?= e(((string) $member['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $member['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                          </form>
                        <?php endif; ?>
                        <?php if ($isSuperAdmin && !$isSelf && (string) $member['status_code'] !== 'DELETED'): ?>
                          <form method="post"
                                class="d-inline"
                                data-safe-delete
                                data-safe-delete-entity="org_staff_user"
                                data-safe-delete-record-field="user_id"
                                data-safe-delete-operation="delete"
                                data-safe-delete-reason-field="deletion_reason">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="company_id" value="<?= (int) $selectedCompanyId; ?>" />
                            <input type="hidden" name="user_id" value="<?= (int) $member['id']; ?>" />
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
