<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('part_master.view');

$page_title = 'Manage Part Units';
$active_menu = 'inventory.units';
$companyId = active_company_id();
$actorUserId = (int) ($_SESSION['user_id'] ?? 0);
$canManage = has_permission('part_master.manage');

function part_unit_find(array $unitsByCode, string $code): ?array
{
    return $unitsByCode[$code] ?? null;
}

function part_unit_sort_rows(array $rows): array
{
    usort(
        $rows,
        static function (array $left, array $right): int {
            $leftStatus = normalize_status_code((string) ($left['status_code'] ?? 'ACTIVE'));
            $rightStatus = normalize_status_code((string) ($right['status_code'] ?? 'ACTIVE'));
            if ($leftStatus !== $rightStatus) {
                $rank = ['ACTIVE' => 0, 'INACTIVE' => 1, 'DELETED' => 2];
                return ($rank[$leftStatus] ?? 9) <=> ($rank[$rightStatus] ?? 9);
            }
            return strcmp((string) ($left['code'] ?? ''), (string) ($right['code'] ?? ''));
        }
    );

    return $rows;
}

$unitCatalog = part_unit_catalog($companyId);
$unitsByCode = [];
foreach ($unitCatalog as $unitRow) {
    $sanitized = part_unit_sanitize_row((array) $unitRow);
    if ($sanitized === null) {
        continue;
    }
    $unitsByCode[(string) $sanitized['code']] = $sanitized;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$canManage) {
        flash_set('parts_error', 'You do not have permission to manage units.', 'danger');
        redirect('modules/inventory/units.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create_unit') {
        $code = part_unit_normalize_code(post_string('unit_code', 20));
        $name = post_string('unit_name', 60);
        $allowDecimal = (int) ($_POST['allow_decimal'] ?? 0) === 1 ? 1 : 0;
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($statusCode === 'DELETED') {
            flash_set('parts_error', 'Use the dedicated Soft Delete action after creating a unit.', 'danger');
            redirect('modules/inventory/units.php');
        }

        if ($code === '' || $name === '') {
            flash_set('parts_error', 'Unit code and unit name are required.', 'danger');
            redirect('modules/inventory/units.php');
        }

        if (isset($unitsByCode[$code]) && normalize_status_code((string) ($unitsByCode[$code]['status_code'] ?? 'ACTIVE')) !== 'DELETED') {
            flash_set('parts_error', 'Unit code already exists. Use edit instead.', 'danger');
            redirect('modules/inventory/units.php');
        }

        $unitsByCode[$code] = [
            'code' => $code,
            'name' => $name,
            'allow_decimal' => $allowDecimal,
            'status_code' => $statusCode,
        ];

        $settingId = part_unit_save_catalog($companyId, array_values($unitsByCode), $actorUserId);
        if ($settingId <= 0) {
            flash_set('parts_error', 'Unable to save unit right now.', 'danger');
            redirect('modules/inventory/units.php');
        }

        log_audit('parts_master', 'unit_create', null, 'Created part unit ' . $code, [
            'entity' => 'part_unit',
            'source' => 'UI',
            'company_id' => $companyId,
            'metadata' => [
                'unit_code' => $code,
                'unit_name' => $name,
                'allow_decimal' => $allowDecimal,
                'status_code' => $statusCode,
                'setting_id' => $settingId,
            ],
        ]);
        flash_set('parts_success', 'Unit created successfully.', 'success');
        redirect('modules/inventory/units.php');
    }

    if ($action === 'update_unit') {
        $code = part_unit_normalize_code(post_string('unit_code', 20));
        $name = post_string('unit_name', 60);
        $allowDecimal = (int) ($_POST['allow_decimal'] ?? 0) === 1 ? 1 : 0;
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($statusCode === 'DELETED') {
            flash_set('parts_error', 'Use the dedicated Soft Delete action to mark a unit as deleted.', 'danger');
            redirect('modules/inventory/units.php');
        }

        if ($code === '' || $name === '') {
            flash_set('parts_error', 'Unit code and unit name are required.', 'danger');
            redirect('modules/inventory/units.php');
        }
        if (!isset($unitsByCode[$code])) {
            flash_set('parts_error', 'Unit not found for update.', 'danger');
            redirect('modules/inventory/units.php');
        }

        $before = part_unit_find($unitsByCode, $code);
        $unitsByCode[$code] = [
            'code' => $code,
            'name' => $name,
            'allow_decimal' => $allowDecimal,
            'status_code' => $statusCode,
        ];

        $settingId = part_unit_save_catalog($companyId, array_values($unitsByCode), $actorUserId);
        if ($settingId <= 0) {
            flash_set('parts_error', 'Unable to update unit right now.', 'danger');
            redirect('modules/inventory/units.php');
        }

        log_audit('parts_master', 'unit_update', null, 'Updated part unit ' . $code, [
            'entity' => 'part_unit',
            'source' => 'UI',
            'company_id' => $companyId,
            'before' => $before,
            'after' => $unitsByCode[$code],
            'metadata' => [
                'setting_id' => $settingId,
            ],
        ]);
        flash_set('parts_success', 'Unit updated successfully.', 'success');
        redirect('modules/inventory/units.php');
    }

    if ($action === 'change_status') {
        $code = part_unit_normalize_code(post_string('unit_code', 20));
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));
        $safeDeleteValidation = null;

        if ($code === '' || !isset($unitsByCode[$code])) {
            flash_set('parts_error', 'Unit not found for status update.', 'danger');
            redirect('modules/inventory/units.php');
        }

        if ($nextStatus === 'DELETED') {
            $safeDeleteValidation = safe_delete_validate_post_confirmation_key('inventory_unit', $code, [
                'operation' => 'delete',
                'reason_field' => 'deletion_reason',
            ]);
        }

        $before = part_unit_find($unitsByCode, $code);
        $unitsByCode[$code]['status_code'] = $nextStatus;

        $settingId = part_unit_save_catalog($companyId, array_values($unitsByCode), $actorUserId);
        if ($settingId <= 0) {
            flash_set('parts_error', 'Unable to change unit status right now.', 'danger');
            redirect('modules/inventory/units.php');
        }

        log_audit('parts_master', 'unit_status', null, 'Changed part unit status for ' . $code . ' to ' . $nextStatus, [
            'entity' => 'part_unit',
            'source' => 'UI',
            'company_id' => $companyId,
            'before' => $before,
            'after' => $unitsByCode[$code],
            'metadata' => [
                'setting_id' => $settingId,
                'deletion_reason' => $safeDeleteValidation['reason'] ?? null,
            ],
        ]);
        if ($safeDeleteValidation !== null) {
            safe_delete_log_cascade('inventory_unit', 'inventory_unit_soft_delete', 0, $safeDeleteValidation, [
                'metadata' => [
                    'record_key' => $code,
                    'setting_id' => $settingId,
                ],
            ]);
        }
        flash_set('parts_success', 'Unit status updated.', 'success');
        redirect('modules/inventory/units.php');
    }
}

$unitCatalog = part_unit_sort_rows(part_unit_catalog($companyId));
$unitsByCode = [];
foreach ($unitCatalog as $unitRow) {
    $sanitized = part_unit_sanitize_row((array) $unitRow);
    if ($sanitized === null) {
        continue;
    }
    $unitsByCode[(string) $sanitized['code']] = $sanitized;
}

$editCode = part_unit_normalize_code((string) ($_GET['edit_code'] ?? ''));
$editUnit = $editCode !== '' ? ($unitsByCode[$editCode] ?? null) : null;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Manage Part Units</h3></div>
        <div class="col-sm-6">
          <div class="d-flex justify-content-sm-end align-items-center gap-2 flex-wrap">
            <a href="<?= e(url('modules/inventory/parts_master.php')); ?>" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-arrow-left me-1"></i>Back To Parts
            </a>
            <ol class="breadcrumb mb-0">
              <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
              <li class="breadcrumb-item"><a href="<?= e(url('modules/inventory/parts_master.php')); ?>">Parts Master</a></li>
              <li class="breadcrumb-item active">Units</li>
            </ol>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editUnit ? 'Edit Unit' : 'Add Unit'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editUnit ? 'update_unit' : 'create_unit'; ?>" />
              <div class="col-md-3">
                <label class="form-label">Unit Code</label>
                <input
                  type="text"
                  name="unit_code"
                  class="form-control"
                  maxlength="20"
                  required
                  value="<?= e((string) ($editUnit['code'] ?? '')); ?>"
                  <?= $editUnit ? 'readonly' : ''; ?>
                  placeholder="Example: LTR"
                />
              </div>
              <div class="col-md-4">
                <label class="form-label">Unit Name</label>
                <input
                  type="text"
                  name="unit_name"
                  class="form-control"
                  maxlength="60"
                  required
                  value="<?= e((string) ($editUnit['name'] ?? '')); ?>"
                  placeholder="Example: Litre"
                />
              </div>
              <div class="col-md-2">
                <label class="form-label">Allow Decimal Qty</label>
                <select name="allow_decimal" class="form-select" required>
                  <?php $allowDecimalValue = (int) ($editUnit['allow_decimal'] ?? 0); ?>
                  <option value="1" <?= $allowDecimalValue === 1 ? 'selected' : ''; ?>>Yes</option>
                  <option value="0" <?= $allowDecimalValue === 0 ? 'selected' : ''; ?>>No</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editUnit['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <?php if ((string) ($option['value'] ?? '') === 'DELETED'): ?>
                      <?php continue; ?>
                    <?php endif; ?>
                    <option value="<?= e((string) $option['value']); ?>" <?= !empty($option['selected']) ? 'selected' : ''; ?>>
                      <?= e((string) $option['value']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editUnit ? 'Update Unit' : 'Create Unit'; ?></button>
              <?php if ($editUnit): ?>
                <a href="<?= e(url('modules/inventory/units.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><h3 class="card-title mb-0">Unit List</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Decimal Quantity</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($unitCatalog)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No units configured.</td></tr>
              <?php else: ?>
                <?php foreach ($unitCatalog as $unit): ?>
                  <?php
                    $unitCode = (string) ($unit['code'] ?? '');
                    $unitStatus = normalize_status_code((string) ($unit['status_code'] ?? 'ACTIVE'));
                    $nextStatus = $unitStatus === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
                  ?>
                  <tr>
                    <td><code><?= e($unitCode); ?></code></td>
                    <td><?= e((string) ($unit['name'] ?? '')); ?></td>
                    <td>
                      <span class="badge text-bg-<?= (int) ($unit['allow_decimal'] ?? 0) === 1 ? 'success' : 'secondary'; ?>">
                        <?= (int) ($unit['allow_decimal'] ?? 0) === 1 ? 'Yes' : 'No'; ?>
                      </span>
                    </td>
                    <td><span class="badge text-bg-<?= e(status_badge_class($unitStatus)); ?>"><?= e($unitStatus); ?></span></td>
                    <td class="d-flex gap-1">
                      <?php if ($canManage): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/inventory/units.php?edit_code=' . urlencode($unitCode))); ?>">Edit</a>
                        <?php if ($unitStatus !== 'DELETED'): ?>
                          <form method="post" class="d-inline" data-confirm="Change unit status?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="unit_code" value="<?= e($unitCode); ?>" />
                            <input type="hidden" name="next_status" value="<?= e($nextStatus); ?>" />
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $unitStatus === 'ACTIVE' ? 'Inactivate' : 'Activate'; ?></button>
                          </form>
                          <form
                            method="post"
                            class="d-inline"
                            data-safe-delete
                            data-safe-delete-entity="inventory_unit"
                            data-safe-delete-record-key-field="unit_code"
                            data-safe-delete-operation="delete"
                            data-safe-delete-reason-field="deletion_reason">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="unit_code" value="<?= e($unitCode); ?>" />
                            <input type="hidden" name="next_status" value="DELETED" />
                            <input type="hidden" name="deletion_reason" value="" />
                            <button type="submit" class="btn btn-sm btn-outline-danger">Soft Delete</button>
                          </form>
                        <?php endif; ?>
                      <?php else: ?>
                        <span class="text-muted">View only</span>
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
