<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('service.view');

$page_title = 'Service Category Master';
$active_menu = 'services.categories';
$canManage = has_permission('service.manage');
$companyId = active_company_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('service_category_error', 'You do not have permission to modify service categories.', 'danger');
        redirect('modules/services/categories.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create') {
        $categoryCode = strtoupper(post_string('category_code', 40));
        $categoryName = post_string('category_name', 120);
        $description = post_string('description', 255);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($categoryCode === '' || $categoryName === '') {
            flash_set('service_category_error', 'Category code and category name are required.', 'danger');
            redirect('modules/services/categories.php');
        }

        try {
            $stmt = db()->prepare(
                'INSERT INTO service_categories
                  (company_id, category_code, category_name, description, status_code, deleted_at, created_by)
                 VALUES
                  (:company_id, :category_code, :category_name, :description, :status_code, :deleted_at, :created_by)'
            );
            $stmt->execute([
                'company_id' => $companyId,
                'category_code' => $categoryCode,
                'category_name' => $categoryName,
                'description' => $description !== '' ? $description : null,
                'status_code' => $statusCode,
                'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
                'created_by' => $userId > 0 ? $userId : null,
            ]);

            $categoryId = (int) db()->lastInsertId();
            log_audit('service_categories', 'create', $categoryId, 'Created service category ' . $categoryCode, [
                'entity' => 'service_category',
                'source' => 'UI',
                'after' => [
                    'category_code' => $categoryCode,
                    'category_name' => $categoryName,
                    'status_code' => $statusCode,
                ],
            ]);

            flash_set('service_category_success', 'Service category created.', 'success');
        } catch (Throwable $exception) {
            flash_set('service_category_error', 'Unable to create category. Code/name must be unique.', 'danger');
        }

        redirect('modules/services/categories.php');
    }

    if ($action === 'update') {
        $categoryId = post_int('category_id');
        $categoryName = post_string('category_name', 120);
        $description = post_string('description', 255);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($categoryId <= 0 || $categoryName === '') {
            flash_set('service_category_error', 'Invalid category payload.', 'danger');
            redirect('modules/services/categories.php');
        }

        $beforeStmt = db()->prepare(
            'SELECT category_name, status_code
             FROM service_categories
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1'
        );
        $beforeStmt->execute([
            'id' => $categoryId,
            'company_id' => $companyId,
        ]);
        $before = $beforeStmt->fetch() ?: null;

        $stmt = db()->prepare(
            'UPDATE service_categories
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

        if ($statusCode === 'DELETED') {
            $clearServiceLinkStmt = db()->prepare(
                'UPDATE services
                 SET category_id = NULL
                 WHERE company_id = :company_id
                   AND category_id = :category_id'
            );
            $clearServiceLinkStmt->execute([
                'company_id' => $companyId,
                'category_id' => $categoryId,
            ]);
        }

        log_audit('service_categories', 'update', $categoryId, 'Updated service category', [
            'entity' => 'service_category',
            'source' => 'UI',
            'before' => is_array($before) ? [
                'category_name' => (string) ($before['category_name'] ?? ''),
                'status_code' => (string) ($before['status_code'] ?? ''),
            ] : null,
            'after' => [
                'category_name' => $categoryName,
                'status_code' => $statusCode,
            ],
        ]);

        flash_set('service_category_success', 'Service category updated.', 'success');
        redirect('modules/services/categories.php');
    }

    if ($action === 'change_status') {
        $categoryId = post_int('category_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));

        if ($categoryId <= 0) {
            flash_set('service_category_error', 'Invalid category selected.', 'danger');
            redirect('modules/services/categories.php');
        }

        $stmt = db()->prepare(
            'UPDATE service_categories
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

        if ($nextStatus === 'DELETED') {
            $clearServiceLinkStmt = db()->prepare(
                'UPDATE services
                 SET category_id = NULL
                 WHERE company_id = :company_id
                   AND category_id = :category_id'
            );
            $clearServiceLinkStmt->execute([
                'company_id' => $companyId,
                'category_id' => $categoryId,
            ]);
        }

        log_audit('service_categories', 'status', $categoryId, 'Changed category status to ' . $nextStatus, [
            'entity' => 'service_category',
            'source' => 'UI',
            'after' => ['status_code' => $nextStatus],
        ]);

        flash_set('service_category_success', 'Service category status updated.', 'success');
        redirect('modules/services/categories.php');
    }
}

$editId = get_int('edit_id');
$editCategory = null;
if ($editId > 0) {
    $editStmt = db()->prepare('SELECT * FROM service_categories WHERE id = :id AND company_id = :company_id LIMIT 1');
    $editStmt->execute([
        'id' => $editId,
        'company_id' => $companyId,
    ]);
    $editCategory = $editStmt->fetch() ?: null;
}

$categoriesStmt = db()->prepare(
    'SELECT sc.*,
            (SELECT COUNT(*) FROM services s WHERE s.company_id = sc.company_id AND s.category_id = sc.id) AS service_count,
            (SELECT COUNT(*) FROM services s WHERE s.company_id = sc.company_id AND s.category_id = sc.id AND s.status_code = "ACTIVE") AS active_service_count
     FROM service_categories sc
     WHERE sc.company_id = :company_id
     ORDER BY sc.id DESC'
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
        <div class="col-sm-6"><h3 class="mb-0">Service Category Master</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Service Categories</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editCategory ? 'Edit Service Category' : 'Add Service Category'; ?></h3></div>
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
                <a href="<?= e(url('modules/services/categories.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
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
                <th>Services</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($categories)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No service categories found.</td></tr>
              <?php else: ?>
                <?php foreach ($categories as $category): ?>
                  <tr>
                    <td><code><?= e((string) $category['category_code']); ?></code></td>
                    <td><?= e((string) $category['category_name']); ?></td>
                    <td><?= e((string) ($category['description'] ?? '-')); ?></td>
                    <td><?= (int) $category['active_service_count']; ?> active / <?= (int) $category['service_count']; ?> total</td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $category['status_code'])); ?>"><?= e((string) $category['status_code']); ?></span></td>
                    <td class="d-flex gap-1">
                      <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/services/categories.php?edit_id=' . (int) $category['id'])); ?>">Edit</a>
                      <?php if ($canManage): ?>
                        <form method="post" class="d-inline" data-confirm="Change category status?">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="change_status" />
                          <input type="hidden" name="category_id" value="<?= (int) $category['id']; ?>" />
                          <input type="hidden" name="next_status" value="<?= e(((string) $category['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $category['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                        </form>
                        <?php if ((string) $category['status_code'] !== 'DELETED'): ?>
                          <form method="post" class="d-inline" data-confirm="Soft delete this category? Linked services will become Uncategorized.">
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
