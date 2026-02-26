<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('part_category.view');

$page_title = 'Part Category Master';
$active_menu = 'inventory.categories';
$canManage = has_permission('part_category.manage');
$companyId = active_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('category_error', 'You do not have permission to modify part categories.', 'danger');
        redirect('modules/inventory/categories.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create') {
        $categoryCode = strtoupper(post_string('category_code', 40));
        $categoryName = post_string('category_name', 120);
        $description = post_string('description', 255);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($categoryCode === '' || $categoryName === '') {
            flash_set('category_error', 'Category code and name are required.', 'danger');
            redirect('modules/inventory/categories.php');
        }

        try {
            $stmt = db()->prepare(
                'INSERT INTO part_categories
                  (company_id, category_code, category_name, description, status_code, deleted_at)
                 VALUES
                  (:company_id, :category_code, :category_name, :description, :status_code, :deleted_at)'
            );
            $stmt->execute([
                'company_id' => $companyId,
                'category_code' => $categoryCode,
                'category_name' => $categoryName,
                'description' => $description !== '' ? $description : null,
                'status_code' => $statusCode,
                'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
            ]);

            $categoryId = (int) db()->lastInsertId();
            log_audit('part_categories', 'create', $categoryId, 'Created category ' . $categoryCode);
            flash_set('category_success', 'Part category created.', 'success');
        } catch (Throwable $exception) {
            flash_set('category_error', 'Unable to create category. Code/name must be unique.', 'danger');
        }

        redirect('modules/inventory/categories.php');
    }

    if ($action === 'update') {
        $categoryId = post_int('category_id');
        $categoryName = post_string('category_name', 120);
        $description = post_string('description', 255);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        $stmt = db()->prepare(
            'UPDATE part_categories
             SET category_name = :category_name,
                 description = :description,
                 status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'category_name' => $categoryName,
            'description' => $description !== '' ? $description : null,
            'status_code' => $statusCode,
            'id' => $categoryId,
            'company_id' => $companyId,
        ]);

        log_audit('part_categories', 'update', $categoryId, 'Updated category');
        flash_set('category_success', 'Part category updated.', 'success');
        redirect('modules/inventory/categories.php');
    }

    if ($action === 'change_status') {
        $categoryId = post_int('category_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));
        $safeDeleteValidation = null;
        if ($nextStatus === 'DELETED') {
            $safeDeleteValidation = safe_delete_validate_post_confirmation('inventory_part_category', $categoryId, [
                'operation' => 'delete',
                'reason_field' => 'deletion_reason',
            ]);
        }

        $stmt = db()->prepare(
            'UPDATE part_categories
             SET status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'status_code' => $nextStatus,
            'id' => $categoryId,
            'company_id' => $companyId,
        ]);

        log_audit('part_categories', 'status', $categoryId, 'Changed status to ' . $nextStatus);
        if ($nextStatus === 'DELETED' && is_array($safeDeleteValidation)) {
            safe_delete_log_cascade('inventory_part_category', 'delete', $categoryId, $safeDeleteValidation, [
                'metadata' => [
                    'company_id' => $companyId,
                    'requested_status' => 'DELETED',
                    'applied_status' => $nextStatus,
                ],
            ]);
        }
        flash_set('category_success', 'Part category status updated.', 'success');
        redirect('modules/inventory/categories.php');
    }
}

$editId = get_int('edit_id');
$editCategory = null;
if ($editId > 0) {
    $editStmt = db()->prepare('SELECT * FROM part_categories WHERE id = :id AND company_id = :company_id LIMIT 1');
    $editStmt->execute([
        'id' => $editId,
        'company_id' => $companyId,
    ]);
    $editCategory = $editStmt->fetch() ?: null;
}

$categoriesStmt = db()->prepare(
    'SELECT pc.*,
            (SELECT COUNT(*) FROM parts p WHERE p.category_id = pc.id) AS part_count
     FROM part_categories pc
     WHERE pc.company_id = :company_id
     ORDER BY pc.id DESC'
);
$categoriesStmt->execute(['company_id' => $companyId]);
$categories = $categoriesStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Part Category Master</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Part Categories</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editCategory ? 'Edit Category' : 'Add Category'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editCategory ? 'update' : 'create'; ?>" />
              <input type="hidden" name="category_id" value="<?= (int) ($editCategory['id'] ?? 0); ?>" />

              <div class="col-md-3">
                <label class="form-label">Category Code</label>
                <input type="text" name="category_code" class="form-control" <?= $editCategory ? 'readonly' : 'required'; ?> value="<?= e((string) ($editCategory['category_code'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Category Name</label>
                <input type="text" name="category_name" class="form-control" required value="<?= e((string) ($editCategory['category_name'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editCategory['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-12">
                <label class="form-label">Description</label>
                <input type="text" name="description" class="form-control" value="<?= e((string) ($editCategory['description'] ?? '')); ?>" />
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editCategory ? 'Update Category' : 'Create Category'; ?></button>
              <?php if ($editCategory): ?>
                <a href="<?= e(url('modules/inventory/categories.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Category List</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Description</th>
                <th>Parts</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($categories)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No categories found.</td></tr>
              <?php else: ?>
                <?php foreach ($categories as $category): ?>
                  <tr>
                    <td><code><?= e((string) $category['category_code']); ?></code></td>
                    <td><?= e((string) $category['category_name']); ?></td>
                    <td><?= e((string) ($category['description'] ?? '-')); ?></td>
                    <td><?= (int) $category['part_count']; ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $category['status_code'])); ?>"><?= e((string) $category['status_code']); ?></span></td>
                    <td class="d-flex gap-1">
                      <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/inventory/categories.php?edit_id=' . (int) $category['id'])); ?>">Edit</a>
                      <?php if ($canManage): ?>
                        <form method="post" class="d-inline" data-confirm="Change category status?">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="change_status" />
                          <input type="hidden" name="category_id" value="<?= (int) $category['id']; ?>" />
                          <input type="hidden" name="next_status" value="<?= e(((string) $category['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $category['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                        </form>
                        <?php if ((string) $category['status_code'] !== 'DELETED'): ?>
                          <form method="post"
                                class="d-inline"
                                data-safe-delete
                                data-safe-delete-entity="inventory_part_category"
                                data-safe-delete-record-field="category_id"
                                data-safe-delete-operation="delete"
                                data-safe-delete-reason-field="deletion_reason">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="category_id" value="<?= (int) $category['id']; ?>" />
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
