<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('permission.view');

$page_title = 'Permission Management';
$active_menu = 'people.permissions';
$canManage = has_permission('permission.manage');

$rolesStmt = db()->query("SELECT id, role_key, role_name, status_code FROM roles WHERE status_code <> 'DELETED' ORDER BY id ASC");
$roles = $rolesStmt->fetchAll();

$selectedRoleId = get_int('role_id');
if ($selectedRoleId <= 0 && !empty($roles)) {
    $selectedRoleId = (int) $roles[0]['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('perm_error', 'You do not have permission to modify permissions.', 'danger');
        redirect('modules/people/permissions.php?role_id=' . $selectedRoleId);
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create_permission') {
        $permKey = strtolower(post_string('perm_key', 80));
        $permName = post_string('perm_name', 120);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($permKey === '' || $permName === '') {
            flash_set('perm_error', 'Permission key and name are required.', 'danger');
            redirect('modules/people/permissions.php?role_id=' . $selectedRoleId);
        }

        try {
            $stmt = db()->prepare('INSERT INTO permissions (perm_key, perm_name, status_code) VALUES (:perm_key, :perm_name, :status_code)');
            $stmt->execute([
                'perm_key' => $permKey,
                'perm_name' => $permName,
                'status_code' => $statusCode,
            ]);

            $permId = (int) db()->lastInsertId();
            log_audit('permissions', 'create', $permId, 'Created permission ' . $permKey);
            flash_set('perm_success', 'Permission created successfully.', 'success');
        } catch (Throwable $exception) {
            flash_set('perm_error', 'Unable to create permission. Key must be unique.', 'danger');
        }

        redirect('modules/people/permissions.php?role_id=' . $selectedRoleId);
    }

    if ($action === 'update_permission') {
        $permissionId = post_int('permission_id');
        $permName = post_string('perm_name', 120);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        $stmt = db()->prepare(
            'UPDATE permissions
             SET perm_name = :perm_name,
                 status_code = :status_code
             WHERE id = :id'
        );
        $stmt->execute([
            'perm_name' => $permName,
            'status_code' => $statusCode,
            'id' => $permissionId,
        ]);

        log_audit('permissions', 'update', $permissionId, 'Updated permission ID ' . $permissionId);
        flash_set('perm_success', 'Permission updated successfully.', 'success');
        redirect('modules/people/permissions.php?role_id=' . $selectedRoleId);
    }

    if ($action === 'change_permission_status') {
        $permissionId = post_int('permission_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));

        $stmt = db()->prepare('UPDATE permissions SET status_code = :status_code WHERE id = :id');
        $stmt->execute([
            'status_code' => $nextStatus,
            'id' => $permissionId,
        ]);

        log_audit('permissions', 'status', $permissionId, 'Changed permission status to ' . $nextStatus);
        flash_set('perm_success', 'Permission status updated.', 'success');
        redirect('modules/people/permissions.php?role_id=' . $selectedRoleId);
    }

    if ($action === 'assign_role_permissions') {
        $roleId = post_int('role_id');
        $permissionIdsRaw = $_POST['permission_ids'] ?? [];
        if (!is_array($permissionIdsRaw)) {
            $permissionIdsRaw = [];
        }

        $permissionIds = [];
        foreach ($permissionIdsRaw as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) !== false) {
                $permissionIds[] = (int) $id;
            }
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $clearStmt = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id');
            $clearStmt->execute(['role_id' => $roleId]);

            if (!empty($permissionIds)) {
                $insertStmt = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)');
                foreach (array_unique($permissionIds) as $permissionId) {
                    $insertStmt->execute([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }

            $pdo->commit();
            log_audit('permissions', 'assign_role_permissions', $roleId, 'Updated role permission mapping');
            flash_set('perm_success', 'Role permissions updated successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('perm_error', 'Unable to update role permissions.', 'danger');
        }

        redirect('modules/people/permissions.php?role_id=' . $roleId);
    }
}

$permissionsStmt = db()->query('SELECT * FROM permissions ORDER BY perm_key ASC');
$permissions = $permissionsStmt->fetchAll();

$activePermissionMap = [];
if ($selectedRoleId > 0) {
    $mapStmt = db()->prepare('SELECT permission_id FROM role_permissions WHERE role_id = :role_id');
    $mapStmt->execute(['role_id' => $selectedRoleId]);
    foreach ($mapStmt->fetchAll() as $row) {
        $activePermissionMap[(int) $row['permission_id']] = true;
    }
}

$editPermissionId = get_int('edit_permission_id');
$editPermission = null;
if ($editPermissionId > 0) {
    $editStmt = db()->prepare('SELECT * FROM permissions WHERE id = :id LIMIT 1');
    $editStmt->execute(['id' => $editPermissionId]);
    $editPermission = $editStmt->fetch() ?: null;
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Permission Management</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Permissions</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editPermission ? 'Edit Permission' : 'Add Permission'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editPermission ? 'update_permission' : 'create_permission'; ?>" />
              <input type="hidden" name="permission_id" value="<?= (int) ($editPermission['id'] ?? 0); ?>" />

              <div class="col-md-4">
                <label class="form-label">Permission Key</label>
                <input type="text" name="perm_key" class="form-control" value="<?= e((string) ($editPermission['perm_key'] ?? '')); ?>" <?= $editPermission ? 'readonly' : 'required'; ?> />
              </div>
              <div class="col-md-4">
                <label class="form-label">Permission Name</label>
                <input type="text" name="perm_name" class="form-control" required value="<?= e((string) ($editPermission['perm_name'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editPermission['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editPermission ? 'Update Permission' : 'Create Permission'; ?></button>
              <?php if ($editPermission): ?>
                <a href="<?= e(url('modules/people/permissions.php?role_id=' . $selectedRoleId)); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="row g-3">
        <div class="col-lg-5">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Permission Master</h3></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-striped mb-0">
                <thead>
                  <tr>
                    <th>Key</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($permissions)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No permissions available.</td></tr>
                  <?php else: ?>
                    <?php foreach ($permissions as $permission): ?>
                      <tr>
                        <td><code><?= e((string) $permission['perm_key']); ?></code></td>
                        <td><?= e((string) $permission['perm_name']); ?></td>
                        <td><span class="badge text-bg-<?= e(status_badge_class((string) $permission['status_code'])); ?>"><?= e((string) $permission['status_code']); ?></span></td>
                        <td class="d-flex gap-1">
                          <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/people/permissions.php?role_id=' . $selectedRoleId . '&edit_permission_id=' . (int) $permission['id'])); ?>">Edit</a>
                          <?php if ($canManage): ?>
                            <form method="post" class="d-inline" data-confirm="Change permission status?">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="change_permission_status" />
                              <input type="hidden" name="permission_id" value="<?= (int) $permission['id']; ?>" />
                              <input type="hidden" name="next_status" value="<?= e(((string) $permission['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                              <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $permission['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
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

        <div class="col-lg-7">
          <div class="card card-info">
            <div class="card-header"><h3 class="card-title">Assign Permissions to Role</h3></div>
            <div class="card-body">
              <form method="get" class="row g-2 mb-3">
                <div class="col-md-8">
                  <select name="role_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($roles as $role): ?>
                      <option value="<?= (int) $role['id']; ?>" <?= ((int) $role['id'] === $selectedRoleId) ? 'selected' : ''; ?>>
                        <?= e((string) $role['role_name']); ?> (<?= e((string) $role['role_key']); ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </form>

              <?php if ($canManage && $selectedRoleId > 0): ?>
                <form method="post">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="assign_role_permissions" />
                  <input type="hidden" name="role_id" value="<?= (int) $selectedRoleId; ?>" />

                  <div class="row g-2">
                    <?php foreach ($permissions as $permission): ?>
                      <?php if ((string) $permission['status_code'] !== 'ACTIVE'): ?>
                        <?php continue; ?>
                      <?php endif; ?>
                      <div class="col-md-6">
                        <div class="form-check">
                          <input
                            class="form-check-input"
                            type="checkbox"
                            name="permission_ids[]"
                            value="<?= (int) $permission['id']; ?>"
                            id="perm_<?= (int) $permission['id']; ?>"
                            <?= isset($activePermissionMap[(int) $permission['id']]) ? 'checked' : ''; ?>
                          />
                          <label class="form-check-label" for="perm_<?= (int) $permission['id']; ?>">
                            <code><?= e((string) $permission['perm_key']); ?></code>
                          </label>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <div class="mt-3">
                    <button type="submit" class="btn btn-info">Save Role Permissions</button>
                  </div>
                </form>
              <?php else: ?>
                <p class="text-muted mb-0">Select a role to map permissions.</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
