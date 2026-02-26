<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('service.view');

$page_title = 'Service / Labour Master';
$active_menu = 'services.master';
$canManage = has_permission('service.manage');
$companyId = active_company_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$serviceColumns = table_columns('services');
$hasEnableReminder = in_array('enable_reminder', $serviceColumns, true);
if (!$hasEnableReminder) {
    try {
        db()->exec('ALTER TABLE services ADD COLUMN enable_reminder TINYINT(1) NOT NULL DEFAULT 0');
        $hasEnableReminder = in_array('enable_reminder', table_columns('services'), true);
    } catch (Throwable $exception) {
        $hasEnableReminder = false;
    }
}

function fetch_service_categories_master(int $companyId): array
{
    $stmt = db()->prepare(
        'SELECT id, category_code, category_name, status_code
         FROM service_categories
         WHERE company_id = :company_id
           AND status_code <> "DELETED"
         ORDER BY category_name ASC'
    );
    $stmt->execute(['company_id' => $companyId]);
    return $stmt->fetchAll();
}

function find_service_category(int $companyId, int $categoryId): ?array
{
    if ($categoryId <= 0) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, category_code, category_name, status_code
         FROM service_categories
         WHERE id = :id
           AND company_id = :company_id
           AND status_code <> "DELETED"
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $categoryId,
        'company_id' => $companyId,
    ]);

    $category = $stmt->fetch();
    return $category ?: null;
}

function service_category_group_label(array $service): string
{
    $categoryId = (int) ($service['category_id'] ?? 0);
    if ($categoryId <= 0) {
        return 'Uncategorized (Legacy)';
    }

    $categoryName = trim((string) ($service['category_name'] ?? ''));
    $categoryCode = trim((string) ($service['category_code'] ?? ''));
    if ($categoryName === '') {
        return 'Category #' . $categoryId;
    }

    return $categoryCode !== '' ? ($categoryName . ' (' . $categoryCode . ')') : $categoryName;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('service_error', 'You do not have permission to modify services.', 'danger');
        redirect('modules/services/index.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create') {
        $categoryId = post_int('category_id');
        $serviceCode = strtoupper(post_string('service_code', 40));
        $serviceName = post_string('service_name', 150);
        $description = post_string('description', 1000);
        $defaultHours = (float) ($_POST['default_hours'] ?? 0);
        $defaultRate = (float) ($_POST['default_rate'] ?? 0);
        $gstRate = (float) ($_POST['gst_rate'] ?? 18);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        $enableReminder = (int) ($_POST['enable_reminder'] ?? 0) === 1 ? 1 : 0;

        $category = find_service_category($companyId, $categoryId);
        if ($serviceCode === '' || $serviceName === '' || !$category) {
            flash_set('service_error', 'Service code, service name and category are required.', 'danger');
            redirect('modules/services/index.php');
        }

        try {
            $insertColumns = 'company_id, category_id, service_code, service_name, description, default_hours, default_rate, gst_rate, status_code, deleted_at, created_by';
            $insertValues = ':company_id, :category_id, :service_code, :service_name, :description, :default_hours, :default_rate, :gst_rate, :status_code, :deleted_at, :created_by';
            $insertParams = [
                'company_id' => $companyId,
                'category_id' => $categoryId,
                'service_code' => $serviceCode,
                'service_name' => $serviceName,
                'description' => $description !== '' ? $description : null,
                'default_hours' => $defaultHours,
                'default_rate' => $defaultRate,
                'gst_rate' => $gstRate,
                'status_code' => $statusCode,
                'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
                'created_by' => $userId > 0 ? $userId : null,
            ];
            if ($hasEnableReminder) {
                $insertColumns .= ', enable_reminder';
                $insertValues .= ', :enable_reminder';
                $insertParams['enable_reminder'] = $enableReminder;
            }

            $stmt = db()->prepare(
                'INSERT INTO services
                  (' . $insertColumns . ')
                 VALUES
                  (' . $insertValues . ')'
            );
            $stmt->execute($insertParams);

            $serviceId = (int) db()->lastInsertId();
            log_audit('services', 'create', $serviceId, 'Created service ' . $serviceCode, [
                'entity' => 'service',
                'source' => 'UI',
                'before' => ['exists' => false],
                'after' => [
                    'service_id' => $serviceId,
                    'category_id' => $categoryId,
                    'category_name' => (string) ($category['category_name'] ?? ''),
                    'service_code' => $serviceCode,
                    'service_name' => $serviceName,
                    'status_code' => $statusCode,
                    'default_rate' => (float) $defaultRate,
                    'gst_rate' => (float) $gstRate,
                    'enable_reminder' => $hasEnableReminder ? $enableReminder : 0,
                ],
            ]);
            flash_set('service_success', 'Service created successfully.', 'success');
        } catch (Throwable $exception) {
            flash_set('service_error', 'Unable to create service. Service code must be unique.', 'danger');
        }

        redirect('modules/services/index.php');
    }

    if ($action === 'update') {
        $serviceId = post_int('service_id');
        $categoryId = post_int('category_id');
        $serviceName = post_string('service_name', 150);
        $description = post_string('description', 1000);
        $defaultHours = (float) ($_POST['default_hours'] ?? 0);
        $defaultRate = (float) ($_POST['default_rate'] ?? 0);
        $gstRate = (float) ($_POST['gst_rate'] ?? 18);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        $enableReminder = (int) ($_POST['enable_reminder'] ?? 0) === 1 ? 1 : 0;

        $category = find_service_category($companyId, $categoryId);
        if ($serviceId <= 0 || $serviceName === '' || !$category) {
            flash_set('service_error', 'Valid service, service name and category are required.', 'danger');
            redirect('modules/services/index.php');
        }

        $beforeStmt = db()->prepare(
            'SELECT service_name, status_code, default_rate, gst_rate, category_id,
                    ' . ($hasEnableReminder ? 'enable_reminder' : '0') . ' AS enable_reminder
             FROM services
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1'
        );
        $beforeStmt->execute([
            'id' => $serviceId,
            'company_id' => $companyId,
        ]);
        $beforeService = $beforeStmt->fetch() ?: null;

        $updateSql =
            'UPDATE services
             SET category_id = :category_id,
                 service_name = :service_name,
                 description = :description,
                 default_hours = :default_hours,
                 default_rate = :default_rate,
                 gst_rate = :gst_rate,
                 status_code = :status_code';
        if ($hasEnableReminder) {
            $updateSql .= ',
                 enable_reminder = :enable_reminder';
        }
        $updateSql .= ',
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id';
        $updateParams = [
            'category_id' => $categoryId,
            'service_name' => $serviceName,
            'description' => $description !== '' ? $description : null,
            'default_hours' => $defaultHours,
            'default_rate' => $defaultRate,
            'gst_rate' => $gstRate,
            'status_code' => $statusCode,
            'id' => $serviceId,
            'company_id' => $companyId,
        ];
        if ($hasEnableReminder) {
            $updateParams['enable_reminder'] = $enableReminder;
        }
        $stmt = db()->prepare($updateSql);
        $stmt->execute($updateParams);

        log_audit('services', 'update', $serviceId, 'Updated service', [
            'entity' => 'service',
            'source' => 'UI',
            'before' => is_array($beforeService) ? [
                'category_id' => (int) ($beforeService['category_id'] ?? 0),
                'service_name' => (string) ($beforeService['service_name'] ?? ''),
                'status_code' => (string) ($beforeService['status_code'] ?? ''),
                'default_rate' => (float) ($beforeService['default_rate'] ?? 0),
                'gst_rate' => (float) ($beforeService['gst_rate'] ?? 0),
                'enable_reminder' => (int) ($beforeService['enable_reminder'] ?? 0),
            ] : null,
            'after' => [
                'category_id' => $categoryId,
                'category_name' => (string) ($category['category_name'] ?? ''),
                'service_name' => $serviceName,
                'status_code' => $statusCode,
                'default_rate' => (float) $defaultRate,
                'gst_rate' => (float) $gstRate,
                'enable_reminder' => $hasEnableReminder ? $enableReminder : 0,
            ],
        ]);
        flash_set('service_success', 'Service updated successfully.', 'success');
        redirect('modules/services/index.php');
    }

    if ($action === 'change_status') {
        $serviceId = post_int('service_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));
        $safeDeleteValidation = null;
        if ($nextStatus === 'DELETED') {
            $safeDeleteValidation = safe_delete_validate_post_confirmation('service_master', $serviceId, [
                'operation' => 'delete',
                'reason_field' => 'deletion_reason',
            ]);
        }
        $beforeStatusStmt = db()->prepare(
            'SELECT status_code
             FROM services
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1'
        );
        $beforeStatusStmt->execute([
            'id' => $serviceId,
            'company_id' => $companyId,
        ]);
        $beforeStatus = $beforeStatusStmt->fetch() ?: null;

        $stmt = db()->prepare(
            'UPDATE services
             SET status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'status_code' => $nextStatus,
            'id' => $serviceId,
            'company_id' => $companyId,
        ]);

        log_audit('services', 'status', $serviceId, 'Changed status to ' . $nextStatus, [
            'entity' => 'service',
            'source' => 'UI',
            'before' => is_array($beforeStatus) ? [
                'status_code' => (string) ($beforeStatus['status_code'] ?? ''),
            ] : null,
            'after' => [
                'status_code' => $nextStatus,
            ],
        ]);
        if ($nextStatus === 'DELETED' && is_array($safeDeleteValidation)) {
            safe_delete_log_cascade('service_master', 'delete', $serviceId, $safeDeleteValidation, [
                'metadata' => [
                    'company_id' => $companyId,
                    'requested_status' => 'DELETED',
                    'applied_status' => $nextStatus,
                ],
            ]);
        }
        flash_set('service_success', 'Service status updated.', 'success');
        redirect('modules/services/index.php');
    }
}

$editId = get_int('edit_id');
$editService = null;
if ($editId > 0) {
    $editStmt = db()->prepare(
        'SELECT s.*, sc.category_name, sc.category_code
         FROM services s
         LEFT JOIN service_categories sc ON sc.id = s.category_id AND sc.company_id = s.company_id
         WHERE s.id = :id
           AND s.company_id = :company_id
         LIMIT 1'
    );
    $editStmt->execute([
        'id' => $editId,
        'company_id' => $companyId,
    ]);
    $editService = $editStmt->fetch() ?: null;
}

$serviceCategories = fetch_service_categories_master($companyId);
$categoryFilterRaw = trim((string) ($_GET['category_filter'] ?? 'all'));
$reminderFilterRaw = strtolower(trim((string) ($_GET['reminder_filter'] ?? 'all')));
$categoryFilterId = null;
$filterUncategorized = false;
$filterReminderEnabled = false;

if ($categoryFilterRaw === 'uncategorized') {
    $filterUncategorized = true;
} elseif (filter_var($categoryFilterRaw, FILTER_VALIDATE_INT) !== false && (int) $categoryFilterRaw > 0) {
    $categoryFilterId = (int) $categoryFilterRaw;
} else {
    $categoryFilterRaw = 'all';
}

if ($reminderFilterRaw === 'enabled' && $hasEnableReminder) {
    $filterReminderEnabled = true;
} else {
    $reminderFilterRaw = 'all';
}

$uncategorizedCountStmt = db()->prepare(
    'SELECT COUNT(*)
     FROM services
     WHERE company_id = :company_id
       AND category_id IS NULL'
);
$uncategorizedCountStmt->execute(['company_id' => $companyId]);
$uncategorizedServiceCount = (int) $uncategorizedCountStmt->fetchColumn();
$showUncategorizedFilter = $uncategorizedServiceCount > 0 || $filterUncategorized;

$sql =
    'SELECT s.*, sc.category_name, sc.category_code,
            (SELECT COUNT(*) FROM vis_service_part_map m WHERE m.service_id = s.id AND m.status_code = "ACTIVE") AS mapped_parts
     FROM services s
     LEFT JOIN service_categories sc ON sc.id = s.category_id AND sc.company_id = s.company_id
     WHERE s.company_id = :company_id';
$params = ['company_id' => $companyId];

if ($categoryFilterId !== null) {
    $sql .= ' AND s.category_id = :category_id';
    $params['category_id'] = $categoryFilterId;
}

if ($filterUncategorized) {
    $sql .= ' AND s.category_id IS NULL';
}

if ($filterReminderEnabled) {
    $sql .= ' AND COALESCE(s.enable_reminder, 0) = 1';
}

$sql .= ' ORDER BY
            CASE WHEN s.category_id IS NULL THEN 1 ELSE 0 END,
            COALESCE(sc.category_name, "Uncategorized"),
            s.service_name ASC,
            s.id DESC';

$servicesStmt = db()->prepare($sql);
$servicesStmt->execute($params);
$services = $servicesStmt->fetchAll();

$serviceGroups = [];
foreach ($services as $service) {
    $groupKey = ((int) ($service['category_id'] ?? 0) > 0) ? (string) (int) $service['category_id'] : 'uncategorized';
    if (!isset($serviceGroups[$groupKey])) {
        $serviceGroups[$groupKey] = [
            'label' => service_category_group_label($service),
            'rows' => [],
        ];
    }

    $serviceGroups[$groupKey]['rows'][] = $service;
}

$listFilterParams = [];
if ($categoryFilterRaw !== 'all') {
    $listFilterParams['category_filter'] = $categoryFilterRaw;
}
$serviceListAllUrl = url('modules/services/index.php' . ($listFilterParams !== [] ? ('?' . http_build_query($listFilterParams)) : ''));
$reminderFilterParams = $listFilterParams;
$reminderFilterParams['reminder_filter'] = 'enabled';
$serviceListReminderUrl = url('modules/services/index.php?' . http_build_query($reminderFilterParams));

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Service / Labour Master</h3></div>
        <div class="col-sm-6">
          <div class="d-flex justify-content-sm-end align-items-center gap-2 flex-wrap">
            <a href="<?= e(url('modules/services/categories.php')); ?>" class="btn btn-sm btn-outline-primary">
              <i class="bi bi-diagram-2 me-1"></i>Manage Categories
            </a>
            <ol class="breadcrumb mb-0">
              <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
              <li class="breadcrumb-item active">Service Master</li>
            </ol>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-outline card-secondary">
        <div class="card-body d-flex flex-wrap justify-content-between gap-2 align-items-end">
          <form method="get" class="row g-2 align-items-end mb-0">
            <div class="col-auto">
              <label class="form-label">Filter by Category</label>
              <select name="category_filter" class="form-select">
                <option value="all" <?= $categoryFilterRaw === 'all' ? 'selected' : ''; ?>>All Categories</option>
                <?php foreach ($serviceCategories as $category): ?>
                  <?php $statusCode = normalize_status_code((string) ($category['status_code'] ?? 'ACTIVE')); ?>
                  <option value="<?= (int) $category['id']; ?>" <?= $categoryFilterId === (int) $category['id'] ? 'selected' : ''; ?>>
                    <?= e((string) $category['category_name']); ?><?= $statusCode !== 'ACTIVE' ? ' [' . e($statusCode) . ']' : ''; ?>
                  </option>
                <?php endforeach; ?>
                <?php if ($showUncategorizedFilter): ?>
                  <option value="uncategorized" <?= $filterUncategorized ? 'selected' : ''; ?>>Uncategorized (Legacy)</option>
                <?php endif; ?>
              </select>
            </div>
            <?php if ($filterReminderEnabled): ?>
              <input type="hidden" name="reminder_filter" value="enabled">
            <?php endif; ?>
            <div class="col-auto d-flex gap-2">
              <button type="submit" class="btn btn-outline-primary">Apply</button>
              <a href="<?= e(url('modules/services/index.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
        </div>
      </div>

      <?php if ($uncategorizedServiceCount > 0): ?>
        <div class="alert alert-warning">
          <?= e((string) $uncategorizedServiceCount); ?> service(s) are still uncategorized and continue to work as legacy records.
        </div>
      <?php endif; ?>

      <?php if ($canManage): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editService ? 'Edit Service' : 'Add Service'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editService ? 'update' : 'create'; ?>" />
              <input type="hidden" name="service_id" value="<?= (int) ($editService['id'] ?? 0); ?>" />

              <div class="col-md-2">
                <label class="form-label">Category</label>
                <select name="category_id" class="form-select" required>
                  <option value="">Select Category</option>
                  <?php foreach ($serviceCategories as $category): ?>
                    <?php
                      $categoryId = (int) $category['id'];
                      $statusCode = normalize_status_code((string) ($category['status_code'] ?? 'ACTIVE'));
                      $selectedCategoryId = (int) ($editService['category_id'] ?? 0);
                    ?>
                    <option value="<?= $categoryId; ?>" <?= $selectedCategoryId === $categoryId ? 'selected' : ''; ?>>
                      <?= e((string) $category['category_name']); ?><?= $statusCode !== 'ACTIVE' ? ' [' . e($statusCode) . ']' : ''; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Service Code</label>
                <input type="text" name="service_code" class="form-control" <?= $editService ? 'readonly' : 'required'; ?> value="<?= e((string) ($editService['service_code'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Service Name</label>
                <input type="text" name="service_name" class="form-control" required value="<?= e((string) ($editService['service_name'] ?? '')); ?>" />
              </div>
              <div class="col-md-1">
                <label class="form-label">Hours</label>
                <input type="number" name="default_hours" step="0.01" class="form-control" value="<?= e((string) ($editService['default_hours'] ?? '0')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Default Rate</label>
                <input type="number" name="default_rate" step="0.01" class="form-control" value="<?= e((string) ($editService['default_rate'] ?? '0')); ?>" />
              </div>
              <div class="col-md-1">
                <label class="form-label">GST%</label>
                <input type="number" name="gst_rate" step="0.01" class="form-control" value="<?= e((string) ($editService['gst_rate'] ?? '18')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Enable Reminder</label>
                <select name="enable_reminder" class="form-select">
                  <?php $enableReminderValue = (int) ($editService['enable_reminder'] ?? 0); ?>
                  <option value="0" <?= $enableReminderValue === 0 ? 'selected' : ''; ?>>No</option>
                  <option value="1" <?= $enableReminderValue === 1 ? 'selected' : ''; ?>>Yes</option>
                </select>
              </div>
              <div class="col-md-1">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editService['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-12">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="2"><?= e((string) ($editService['description'] ?? '')); ?></textarea>
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editService ? 'Update Service' : 'Create Service'; ?></button>
              <?php if ($editService): ?>
                <a href="<?= e(url('modules/services/index.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
          <h3 class="card-title mb-0">Service List</h3>
          <div class="btn-group btn-group-sm" role="group" aria-label="Reminder filter">
            <a href="<?= e($serviceListAllUrl); ?>" class="btn <?= $filterReminderEnabled ? 'btn-outline-secondary' : 'btn-secondary'; ?>">All</a>
            <a href="<?= e($serviceListReminderUrl); ?>" class="btn <?= $filterReminderEnabled ? 'btn-success' : 'btn-outline-success'; ?>">Reminder Enabled</a>
          </div>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Category</th>
                <th>Hours</th>
                <th>Rate</th>
                <th>GST%</th>
                <th>Reminder</th>
                <th>VIS Parts Mapped</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($serviceGroups)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">No services found.</td></tr>
              <?php else: ?>
                <?php foreach ($serviceGroups as $group): ?>
                  <tr class="table-secondary">
                    <th colspan="10">Category: <?= e((string) $group['label']); ?> (<?= count($group['rows']); ?>)</th>
                  </tr>
                  <?php foreach ($group['rows'] as $service): ?>
                    <tr>
                      <td><code><?= e((string) $service['service_code']); ?></code></td>
                      <td><?= e((string) $service['service_name']); ?></td>
                      <td><?= e(service_category_group_label($service)); ?></td>
                      <td><?= e((string) $service['default_hours']); ?></td>
                      <td><?= e(format_currency((float) $service['default_rate'])); ?></td>
                      <td><?= e((string) $service['gst_rate']); ?></td>
                      <td>
                        <span class="badge text-bg-<?= ((int) ($service['enable_reminder'] ?? 0) === 1) ? 'success' : 'secondary'; ?>">
                          <?= ((int) ($service['enable_reminder'] ?? 0) === 1) ? 'Yes' : 'No'; ?>
                        </span>
                      </td>
                      <td><?= (int) $service['mapped_parts']; ?></td>
                      <td><span class="badge text-bg-<?= e(status_badge_class((string) $service['status_code'])); ?>"><?= e((string) $service['status_code']); ?></span></td>
                      <td class="d-flex gap-1">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/services/index.php?edit_id=' . (int) $service['id'])); ?>">Edit</a>
                        <?php if ($canManage): ?>
                          <form method="post" class="d-inline" data-confirm="Change service status?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="service_id" value="<?= (int) $service['id']; ?>" />
                            <input type="hidden" name="next_status" value="<?= e(((string) $service['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $service['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                          </form>
                          <?php if ((string) $service['status_code'] !== 'DELETED'): ?>
                            <form method="post"
                                  class="d-inline"
                                  data-safe-delete
                                  data-safe-delete-entity="service_master"
                                  data-safe-delete-record-field="service_id"
                                  data-safe-delete-operation="delete"
                                  data-safe-delete-reason-field="deletion_reason">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="change_status" />
                              <input type="hidden" name="service_id" value="<?= (int) $service['id']; ?>" />
                              <input type="hidden" name="next_status" value="DELETED" />
                              <button type="submit" class="btn btn-sm btn-outline-danger">Soft Delete</button>
                            </form>
                          <?php endif; ?>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
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

