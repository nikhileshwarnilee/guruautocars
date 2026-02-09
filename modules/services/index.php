<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('service.view');

$page_title = 'Service / Labour Master';
$active_menu = 'services.master';
$canManage = has_permission('service.manage');
$companyId = active_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('service_error', 'You do not have permission to modify services.', 'danger');
        redirect('modules/services/index.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create') {
        $serviceCode = strtoupper(post_string('service_code', 40));
        $serviceName = post_string('service_name', 150);
        $description = post_string('description', 1000);
        $defaultHours = (float) ($_POST['default_hours'] ?? 0);
        $defaultRate = (float) ($_POST['default_rate'] ?? 0);
        $gstRate = (float) ($_POST['gst_rate'] ?? 18);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($serviceCode === '' || $serviceName === '') {
            flash_set('service_error', 'Service code and service name are required.', 'danger');
            redirect('modules/services/index.php');
        }

        try {
            $stmt = db()->prepare(
                'INSERT INTO services
                  (company_id, service_code, service_name, description, default_hours, default_rate, gst_rate, status_code, deleted_at, created_by)
                 VALUES
                  (:company_id, :service_code, :service_name, :description, :default_hours, :default_rate, :gst_rate, :status_code, :deleted_at, :created_by)'
            );
            $stmt->execute([
                'company_id' => $companyId,
                'service_code' => $serviceCode,
                'service_name' => $serviceName,
                'description' => $description !== '' ? $description : null,
                'default_hours' => $defaultHours,
                'default_rate' => $defaultRate,
                'gst_rate' => $gstRate,
                'status_code' => $statusCode,
                'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
                'created_by' => (int) $_SESSION['user_id'],
            ]);

            $serviceId = (int) db()->lastInsertId();
            log_audit('services', 'create', $serviceId, 'Created service ' . $serviceCode);
            flash_set('service_success', 'Service created successfully.', 'success');
        } catch (Throwable $exception) {
            flash_set('service_error', 'Unable to create service. Service code must be unique.', 'danger');
        }

        redirect('modules/services/index.php');
    }

    if ($action === 'update') {
        $serviceId = post_int('service_id');
        $serviceName = post_string('service_name', 150);
        $description = post_string('description', 1000);
        $defaultHours = (float) ($_POST['default_hours'] ?? 0);
        $defaultRate = (float) ($_POST['default_rate'] ?? 0);
        $gstRate = (float) ($_POST['gst_rate'] ?? 18);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        $stmt = db()->prepare(
            'UPDATE services
             SET service_name = :service_name,
                 description = :description,
                 default_hours = :default_hours,
                 default_rate = :default_rate,
                 gst_rate = :gst_rate,
                 status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'service_name' => $serviceName,
            'description' => $description !== '' ? $description : null,
            'default_hours' => $defaultHours,
            'default_rate' => $defaultRate,
            'gst_rate' => $gstRate,
            'status_code' => $statusCode,
            'id' => $serviceId,
            'company_id' => $companyId,
        ]);

        log_audit('services', 'update', $serviceId, 'Updated service');
        flash_set('service_success', 'Service updated successfully.', 'success');
        redirect('modules/services/index.php');
    }

    if ($action === 'change_status') {
        $serviceId = post_int('service_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));

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

        log_audit('services', 'status', $serviceId, 'Changed status to ' . $nextStatus);
        flash_set('service_success', 'Service status updated.', 'success');
        redirect('modules/services/index.php');
    }
}

$editId = get_int('edit_id');
$editService = null;
if ($editId > 0) {
    $editStmt = db()->prepare('SELECT * FROM services WHERE id = :id AND company_id = :company_id LIMIT 1');
    $editStmt->execute([
        'id' => $editId,
        'company_id' => $companyId,
    ]);
    $editService = $editStmt->fetch() ?: null;
}

$servicesStmt = db()->prepare(
    'SELECT s.*,
            (SELECT COUNT(*) FROM vis_service_part_map m WHERE m.service_id = s.id AND m.status_code = "ACTIVE") AS mapped_parts
     FROM services s
     WHERE s.company_id = :company_id
     ORDER BY s.id DESC'
);
$servicesStmt->execute(['company_id' => $companyId]);
$services = $servicesStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Service / Labour Master</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Service Master</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editService ? 'Edit Service' : 'Add Service'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editService ? 'update' : 'create'; ?>" />
              <input type="hidden" name="service_id" value="<?= (int) ($editService['id'] ?? 0); ?>" />

              <div class="col-md-2">
                <label class="form-label">Service Code</label>
                <input type="text" name="service_code" class="form-control" <?= $editService ? 'readonly' : 'required'; ?> value="<?= e((string) ($editService['service_code'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Service Name</label>
                <input type="text" name="service_name" class="form-control" required value="<?= e((string) ($editService['service_name'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Default Hours</label>
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
        <div class="card-header"><h3 class="card-title">Service List</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Hours</th>
                <th>Rate</th>
                <th>GST%</th>
                <th>VIS Parts Mapped</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($services)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No services found.</td></tr>
              <?php else: ?>
                <?php foreach ($services as $service): ?>
                  <tr>
                    <td><code><?= e((string) $service['service_code']); ?></code></td>
                    <td><?= e((string) $service['service_name']); ?></td>
                    <td><?= e((string) $service['default_hours']); ?></td>
                    <td><?= e(format_currency((float) $service['default_rate'])); ?></td>
                    <td><?= e((string) $service['gst_rate']); ?></td>
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
                          <form method="post" class="d-inline" data-confirm="Soft delete this service?">
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
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
