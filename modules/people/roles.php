<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('role.view');

$page_title = 'Role Master';
$active_menu = 'people.roles';
$canManage = has_permission('role.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('role_error', 'You do not have permission to modify roles.', 'danger');
        redirect('modules/people/roles.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create') {
        $roleName = post_string('role_name', 100);
        $roleKey = strtolower(post_string('role_key', 50));
        $description = post_string('description', 255);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($roleName === '' || $roleKey === '') {
            flash_set('role_error', 'Role name and role key are required.', 'danger');
            redirect('modules/people/roles.php');
        }

        try {
            $stmt = db()->prepare(
                'INSERT INTO roles
                  (role_key, role_name, description, is_system, status_code)
                 VALUES
                  (:role_key, :role_name, :description, 0, :status_code)'
            );
            $stmt->execute([
                'role_key' => $roleKey,
                'role_name' => $roleName,
                'description' => $description !== '' ? $description : null,
                'status_code' => $statusCode,
            ]);

            $roleId = (int) db()->lastInsertId();
            log_audit('roles', 'create', $roleId, 'Created role ' . $roleKey);
            flash_set('role_success', 'Role created successfully.', 'success');
        } catch (Throwable $exception) {
            flash_set('role_error', 'Unable to create role. Role key must be unique.', 'danger');
        }

        redirect('modules/people/roles.php');
    }

    if ($action === 'update') {
        $roleId = post_int('role_id');
        $roleName = post_string('role_name', 100);
        $description = post_string('description', 255);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        $roleStmt = db()->prepare('SELECT role_key, is_system FROM roles WHERE id = :id LIMIT 1');
        $roleStmt->execute(['id' => $roleId]);
        $existingRole = $roleStmt->fetch();

        if (!$existingRole) {
            flash_set('role_error', 'Role not found.', 'danger');
            redirect('modules/people/roles.php');
        }

        if ((string) $existingRole['role_key'] === 'super_admin' && $statusCode !== 'ACTIVE') {
            flash_set('role_error', 'Super Admin role cannot be deactivated or deleted.', 'danger');
            redirect('modules/people/roles.php');
        }

        $updateStmt = db()->prepare(
            'UPDATE roles
             SET role_name = :role_name,
                 description = :description,
                 status_code = :status_code
             WHERE id = :id'
        );
        $updateStmt->execute([
            'role_name' => $roleName,
            'description' => $description !== '' ? $description : null,
            'status_code' => $statusCode,
            'id' => $roleId,
        ]);

        log_audit('roles', 'update', $roleId, 'Updated role ' . (string) $existingRole['role_key']);
        flash_set('role_success', 'Role updated successfully.', 'success');
        redirect('modules/people/roles.php');
    }

    if ($action === 'change_status') {
        $roleId = post_int('role_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));
        $safeDeleteValidation = null;

        $roleStmt = db()->prepare('SELECT role_key FROM roles WHERE id = :id LIMIT 1');
        $roleStmt->execute(['id' => $roleId]);
        $role = $roleStmt->fetch();

        if (!$role) {
            flash_set('role_error', 'Role not found.', 'danger');
            redirect('modules/people/roles.php');
        }

        if ((string) $role['role_key'] === 'super_admin' && $nextStatus !== 'ACTIVE') {
            flash_set('role_error', 'Super Admin role cannot be deactivated or deleted.', 'danger');
            redirect('modules/people/roles.php');
        }
        if ($nextStatus === 'DELETED') {
            $safeDeleteValidation = safe_delete_validate_post_confirmation('role', $roleId, [
                'operation' => 'delete',
                'reason_field' => 'deletion_reason',
            ]);
        }

        $stmt = db()->prepare('UPDATE roles SET status_code = :status_code WHERE id = :id');
        $stmt->execute([
            'status_code' => $nextStatus,
            'id' => $roleId,
        ]);

        log_audit('roles', 'status', $roleId, 'Changed role status to ' . $nextStatus);
        if ($nextStatus === 'DELETED' && is_array($safeDeleteValidation)) {
            safe_delete_log_cascade('role', 'delete', $roleId, $safeDeleteValidation, [
                'metadata' => [
                    'requested_status' => 'DELETED',
                    'applied_status' => $nextStatus,
                    'role_key' => (string) ($role['role_key'] ?? ''),
                ],
            ]);
        }
        flash_set('role_success', 'Role status updated.', 'success');
        redirect('modules/people/roles.php');
    }
}

$editId = get_int('edit_id');
$editRole = null;
if ($editId > 0) {
    $editStmt = db()->prepare('SELECT * FROM roles WHERE id = :id LIMIT 1');
    $editStmt->execute(['id' => $editId]);
    $editRole = $editStmt->fetch() ?: null;
}

$rolesStmt = db()->query(
    'SELECT r.*,
            (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
            (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
     FROM roles r
     ORDER BY r.id ASC'
);
$roles = $rolesStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Role Master</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Roles</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editRole ? 'Edit Role' : 'Add Role'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editRole ? 'update' : 'create'; ?>" />
              <input type="hidden" name="role_id" value="<?= (int) ($editRole['id'] ?? 0); ?>" />

              <div class="col-md-3">
                <label class="form-label">Role Key</label>
                <input type="text" name="role_key" class="form-control" value="<?= e((string) ($editRole['role_key'] ?? '')); ?>" <?= $editRole ? 'readonly' : 'required'; ?> />
                <div class="form-hint">Example: service_advisor</div>
              </div>
              <div class="col-md-3">
                <label class="form-label">Role Name</label>
                <input type="text" name="role_name" class="form-control" required value="<?= e((string) ($editRole['role_name'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Description</label>
                <input type="text" name="description" class="form-control" value="<?= e((string) ($editRole['description'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editRole['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editRole ? 'Update Role' : 'Create Role'; ?></button>
              <?php if ($editRole): ?>
                <a href="<?= e(url('modules/people/roles.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Role List</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Role Key</th>
                <th>Role Name</th>
                <th>Users</th>
                <th>Permissions</th>
                <th>System Role</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($roles)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No roles found.</td></tr>
              <?php else: ?>
                <?php foreach ($roles as $role): ?>
                  <tr>
                    <td><?= (int) $role['id']; ?></td>
                    <td><code><?= e((string) $role['role_key']); ?></code></td>
                    <td><?= e((string) $role['role_name']); ?><br><small class="text-muted"><?= e((string) ($role['description'] ?? '')); ?></small></td>
                    <td><?= (int) $role['user_count']; ?></td>
                    <td><?= (int) $role['permission_count']; ?></td>
                    <td><?= ((int) $role['is_system'] === 1) ? '<span class="badge text-bg-info">System</span>' : '-'; ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $role['status_code'])); ?>"><?= e((string) $role['status_code']); ?></span></td>
                    <td class="d-flex gap-1">
                      <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/people/roles.php?edit_id=' . (int) $role['id'])); ?>">Edit</a>
                      <a class="btn btn-sm btn-outline-info" href="<?= e(url('modules/people/permissions.php?role_id=' . (int) $role['id'])); ?>">Permissions</a>
                      <?php if ($canManage): ?>
                        <?php if ((string) $role['role_key'] !== 'super_admin'): ?>
                          <form method="post" class="d-inline" data-confirm="Change role status?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="role_id" value="<?= (int) $role['id']; ?>" />
                            <input type="hidden" name="next_status" value="<?= e(((string) $role['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $role['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                          </form>
                          <?php if ((string) $role['status_code'] !== 'DELETED'): ?>
                            <form method="post"
                                  class="d-inline"
                                  data-safe-delete
                                  data-safe-delete-entity="role"
                                  data-safe-delete-record-field="role_id"
                                  data-safe-delete-operation="delete"
                                  data-safe-delete-reason-field="deletion_reason">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="change_status" />
                              <input type="hidden" name="role_id" value="<?= (int) $role['id']; ?>" />
                              <input type="hidden" name="next_status" value="DELETED" />
                              <button type="submit" class="btn btn-sm btn-outline-danger">Soft Delete</button>
                            </form>
                          <?php endif; ?>
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
