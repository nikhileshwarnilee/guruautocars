<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('job.view');
require_once __DIR__ . '/workflow.php';

$page_title = 'Vehicle Intake Checklist Master';
$active_menu = 'jobs.checklist_master';
$canManage = has_permission('job.manage') || has_permission('settings.manage');
$featureReady = job_vehicle_intake_feature_ready();
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$canManage) {
        flash_set('job_error', 'You do not have permission to manage checklist master.', 'danger');
        redirect('modules/jobs/checklist_master.php');
    }
    if (!$featureReady) {
        flash_set('job_error', 'Vehicle intake feature is not ready.', 'danger');
        redirect('modules/jobs/checklist_master.php');
    }

    $action = trim((string) ($_POST['_action'] ?? ''));
    if ($action === 'save_item') {
        $itemId = post_int('item_id');
        $itemName = post_string('item_name', 120);
        $isDefault = !empty($_POST['is_default']);
        $active = !empty($_POST['active']);

        if ($itemName === '') {
            flash_set('job_error', 'Checklist item name is required.', 'danger');
            redirect('modules/jobs/checklist_master.php');
        }

        try {
            $savedId = job_vehicle_intake_master_upsert($itemId, $itemName, $isDefault, $active);
            if ($savedId <= 0) {
                throw new RuntimeException('Unable to save checklist item.');
            }
            log_audit('system_settings', 'save_intake_checklist_item', $savedId, 'Saved vehicle intake checklist item', [
                'entity' => 'checklist_master',
                'source' => 'UI',
                'metadata' => [
                    'item_id' => $savedId,
                    'item_name' => $itemName,
                    'is_default' => $isDefault ? 1 : 0,
                    'active' => $active ? 1 : 0,
                    'updated_by' => $userId > 0 ? $userId : null,
                ],
            ]);
            flash_set('job_success', 'Checklist item saved.', 'success');
        } catch (Throwable $exception) {
            flash_set('job_error', 'Unable to save checklist item.', 'danger');
        }

        redirect('modules/jobs/checklist_master.php');
    }

    if ($action === 'toggle_active') {
        $itemId = post_int('item_id');
        $nextActive = !empty($_POST['next_active']);
        if ($itemId <= 0) {
            flash_set('job_error', 'Invalid checklist item selected.', 'danger');
            redirect('modules/jobs/checklist_master.php');
        }

        $ok = job_vehicle_intake_master_set_active($itemId, $nextActive);
        if ($ok) {
            flash_set('job_success', 'Checklist item status updated.', 'success');
            log_audit('system_settings', 'toggle_intake_checklist_item', $itemId, 'Toggled vehicle intake checklist item status', [
                'entity' => 'checklist_master',
                'source' => 'UI',
                'metadata' => [
                    'item_id' => $itemId,
                    'active' => $nextActive ? 1 : 0,
                    'updated_by' => $userId > 0 ? $userId : null,
                ],
            ]);
        } else {
            flash_set('job_error', 'Unable to update checklist item status.', 'danger');
        }

        redirect('modules/jobs/checklist_master.php');
    }
}

$items = $featureReady ? job_vehicle_intake_master_items(false) : [];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-7">
          <h3 class="mb-0">Vehicle Intake Checklist Master</h3>
          <small class="text-muted">Manage default belongings/checklist options used during Job Card intake.</small>
        </div>
        <div class="col-sm-5">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/jobs/index.php')); ?>">Job Cards</a></li>
            <li class="breadcrumb-item active">Checklist Master</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if (!$featureReady): ?>
        <div class="alert alert-warning">Vehicle intake feature is not ready. Run upgrade before managing checklist master.</div>
      <?php endif; ?>
      <?php if (!$canManage): ?>
        <div class="alert alert-info">You have view-only access.</div>
      <?php endif; ?>

      <div class="card card-primary mb-3">
        <div class="card-header"><h3 class="card-title mb-0">Add New Checklist Item</h3></div>
        <div class="card-body">
          <form method="post" class="row g-2 align-items-end">
            <?= csrf_field(); ?>
            <input type="hidden" name="_action" value="save_item">
            <input type="hidden" name="item_id" value="0">
            <div class="col-md-6">
              <label class="form-label">Item Name</label>
              <input type="text" name="item_name" class="form-control" maxlength="120" required <?= $canManage && $featureReady ? '' : 'disabled'; ?>>
            </div>
            <div class="col-md-2">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_default" id="new-item-default" value="1" checked <?= $canManage && $featureReady ? '' : 'disabled'; ?>>
                <label class="form-check-label" for="new-item-default">Default</label>
              </div>
            </div>
            <div class="col-md-2">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="active" id="new-item-active" value="1" checked <?= $canManage && $featureReady ? '' : 'disabled'; ?>>
                <label class="form-check-label" for="new-item-active">Active</label>
              </div>
            </div>
            <div class="col-md-2">
              <button type="submit" class="btn btn-primary w-100" <?= $canManage && $featureReady ? '' : 'disabled'; ?>>Add Item</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h3 class="card-title mb-0">Checklist Items</h3>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th style="width:70px;">ID</th>
                <th>Item Name</th>
                <th style="width:110px;">Default</th>
                <th style="width:110px;">Status</th>
                <th style="width:260px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($items === []): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No checklist items found.</td></tr>
              <?php else: ?>
                <?php foreach ($items as $item): ?>
                  <?php
                    $itemId = (int) ($item['id'] ?? 0);
                    $itemName = (string) ($item['item_name'] ?? '');
                    $isDefault = (int) ($item['is_default'] ?? 0) === 1;
                    $active = (int) ($item['active'] ?? 0) === 1;
                  ?>
                  <tr>
                    <td><?= $itemId; ?></td>
                    <td>
                      <form method="post" class="d-flex gap-2 align-items-center">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="_action" value="save_item">
                        <input type="hidden" name="item_id" value="<?= $itemId; ?>">
                        <input type="text" name="item_name" class="form-control form-control-sm" maxlength="120" value="<?= e($itemName); ?>" <?= $canManage && $featureReady ? '' : 'disabled'; ?>>
                    </td>
                    <td>
                      <input type="hidden" name="active" value="<?= $active ? '1' : '0'; ?>">
                      <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="is_default" value="1" <?= $isDefault ? 'checked' : ''; ?> <?= $canManage && $featureReady ? '' : 'disabled'; ?>>
                      </div>
                    </td>
                    <td>
                      <span class="badge text-bg-<?= $active ? 'success' : 'secondary'; ?>"><?= $active ? 'Active' : 'Inactive'; ?></span>
                    </td>
                    <td>
                        <button type="submit" class="btn btn-sm btn-outline-primary" <?= $canManage && $featureReady ? '' : 'disabled'; ?>>Save</button>
                      </form>
                      <form method="post" class="d-inline ms-1">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="_action" value="toggle_active">
                        <input type="hidden" name="item_id" value="<?= $itemId; ?>">
                        <input type="hidden" name="next_active" value="<?= $active ? '0' : '1'; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-<?= $active ? 'warning' : 'success'; ?>" <?= $canManage && $featureReady ? '' : 'disabled'; ?>>
                          <?= $active ? 'Inactivate' : 'Activate'; ?>
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

