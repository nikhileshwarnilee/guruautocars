<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('settings.view');

$page_title = 'System Settings';
$active_menu = 'system.settings';
$canManage = has_permission('settings.manage');
$companyId = active_company_id();
$actorUserId = (int) ($_SESSION['user_id'] ?? 0);
$dateModeOptions = date_filter_modes();

$garagesStmt = db()->prepare(
    'SELECT id, name, code
     FROM garages
     WHERE company_id = :company_id
       AND status_code <> "DELETED"
     ORDER BY name ASC'
);
$garagesStmt->execute(['company_id' => $companyId]);
$garages = $garagesStmt->fetchAll();

function job_type_sort_rows(array $rows): array
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

            return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        }
    );

    return $rows;
}

function job_type_name_exists(array $jobTypesById, string $name, ?int $excludeId = null): bool
{
    $needle = mb_strtolower(trim($name));
    if ($needle === '') {
        return false;
    }

    foreach ($jobTypesById as $id => $row) {
        if ($excludeId !== null && (int) $id === $excludeId) {
            continue;
        }

        $statusCode = normalize_status_code((string) ($row['status_code'] ?? 'ACTIVE'));
        if ($statusCode === 'DELETED') {
            continue;
        }

        $rowName = mb_strtolower(trim((string) ($row['name'] ?? '')));
        if ($rowName !== '' && $rowName === $needle) {
            return true;
        }
    }

    return false;
}

$jobTypeCatalog = job_type_catalog($companyId);
$jobTypesById = [];
foreach ($jobTypeCatalog as $jobTypeRow) {
    $sanitized = job_type_sanitize_row((array) $jobTypeRow);
    if ($sanitized === null) {
        continue;
    }
    $jobTypesById[(int) $sanitized['id']] = $sanitized;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('settings_error', 'You do not have permission to modify settings.', 'danger');
        redirect('modules/system/settings.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'update_default_date_filter_mode') {
        $selectedMode = date_filter_normalize_mode((string) ($_POST['default_date_filter_mode'] ?? 'monthly'), 'monthly');
        $settingId = system_setting_upsert_value(
            $companyId,
            null,
            'REPORTS',
            'default_date_filter_mode',
            $selectedMode,
            'STRING',
            'ACTIVE',
            (int) ($_SESSION['user_id'] ?? 0)
        );

        if ($settingId > 0) {
            log_audit('system_settings', 'update', $settingId, 'Updated default date filter mode to ' . $selectedMode, [
                'entity' => 'setting',
                'company_id' => $companyId,
                'metadata' => [
                    'setting_key' => 'default_date_filter_mode',
                    'setting_group' => 'REPORTS',
                ],
            ]);
            flash_set('settings_success', 'Default date filter mode updated.', 'success');
        } else {
            flash_set('settings_error', 'Unable to update default date filter mode right now.', 'danger');
        }

        redirect('modules/system/settings.php');
    }

    if ($action === 'create_job_type') {
        $jobTypeName = post_string('job_type_name', 80);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        $allowMultipleActiveJobs = !empty($_POST['allow_multiple_active_jobs']) ? 1 : 0;

        if ($statusCode === 'DELETED') {
            flash_set('settings_error', 'Use delete action to mark a job type as deleted.', 'danger');
            redirect('modules/system/settings.php');
        }

        if ($jobTypeName === '') {
            flash_set('settings_error', 'Job type name is required.', 'danger');
            redirect('modules/system/settings.php');
        }

        if (job_type_name_exists($jobTypesById, $jobTypeName, null)) {
            flash_set('settings_error', 'Job type name already exists. Use edit to modify it.', 'danger');
            redirect('modules/system/settings.php');
        }

        $nextId = $jobTypesById !== [] ? (max(array_map('intval', array_keys($jobTypesById))) + 1) : 1;
        $jobTypesById[$nextId] = [
            'id' => $nextId,
            'name' => $jobTypeName,
            'status_code' => $statusCode,
            'allow_multiple_active_jobs' => $allowMultipleActiveJobs,
        ];

        $settingId = job_type_save_catalog($companyId, array_values($jobTypesById), $actorUserId > 0 ? $actorUserId : null);
        if ($settingId <= 0) {
            flash_set('settings_error', 'Unable to save job type right now.', 'danger');
            redirect('modules/system/settings.php');
        }

        log_audit('system_settings', 'job_type_create', $settingId, 'Created job type ' . $jobTypeName, [
            'entity' => 'job_type_option',
            'company_id' => $companyId,
            'metadata' => [
                'job_type_id' => $nextId,
                'job_type_name' => $jobTypeName,
                'status_code' => $statusCode,
                'allow_multiple_active_jobs' => $allowMultipleActiveJobs,
                'setting_key' => 'job_types_catalog_json',
            ],
        ]);
        flash_set('settings_success', 'Job type created successfully.', 'success');
        redirect('modules/system/settings.php');
    }

    if ($action === 'update_job_type') {
        $jobTypeId = post_int('job_type_id');
        $jobTypeName = post_string('job_type_name', 80);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        $allowMultipleActiveJobs = !empty($_POST['allow_multiple_active_jobs']) ? 1 : 0;

        if ($statusCode === 'DELETED') {
            flash_set('settings_error', 'Use delete action to mark a job type as deleted.', 'danger');
            redirect('modules/system/settings.php');
        }

        if ($jobTypeId <= 0 || !isset($jobTypesById[$jobTypeId])) {
            flash_set('settings_error', 'Job type not found for update.', 'danger');
            redirect('modules/system/settings.php');
        }

        if ($jobTypeName === '') {
            flash_set('settings_error', 'Job type name is required.', 'danger');
            redirect('modules/system/settings.php?edit_job_type_id=' . $jobTypeId);
        }

        if (job_type_name_exists($jobTypesById, $jobTypeName, $jobTypeId)) {
            flash_set('settings_error', 'Job type name already exists. Choose a different name.', 'danger');
            redirect('modules/system/settings.php?edit_job_type_id=' . $jobTypeId);
        }

        $before = $jobTypesById[$jobTypeId];
        $jobTypesById[$jobTypeId] = [
            'id' => $jobTypeId,
            'name' => $jobTypeName,
            'status_code' => $statusCode,
            'allow_multiple_active_jobs' => $allowMultipleActiveJobs,
        ];

        $settingId = job_type_save_catalog($companyId, array_values($jobTypesById), $actorUserId > 0 ? $actorUserId : null);
        if ($settingId <= 0) {
            flash_set('settings_error', 'Unable to update job type right now.', 'danger');
            redirect('modules/system/settings.php?edit_job_type_id=' . $jobTypeId);
        }

        log_audit('system_settings', 'job_type_update', $settingId, 'Updated job type ' . $jobTypeName, [
            'entity' => 'job_type_option',
            'company_id' => $companyId,
            'before' => $before,
            'after' => $jobTypesById[$jobTypeId],
            'metadata' => [
                'setting_key' => 'job_types_catalog_json',
            ],
        ]);
        flash_set('settings_success', 'Job type updated successfully.', 'success');
        redirect('modules/system/settings.php');
    }

    if ($action === 'change_job_type_status') {
        $jobTypeId = post_int('job_type_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));

        if ($jobTypeId <= 0 || !isset($jobTypesById[$jobTypeId])) {
            flash_set('settings_error', 'Job type not found for status update.', 'danger');
            redirect('modules/system/settings.php');
        }

        $before = $jobTypesById[$jobTypeId];
        $jobTypesById[$jobTypeId]['status_code'] = $nextStatus;

        $settingId = job_type_save_catalog($companyId, array_values($jobTypesById), $actorUserId > 0 ? $actorUserId : null);
        if ($settingId <= 0) {
            flash_set('settings_error', 'Unable to update job type status right now.', 'danger');
            redirect('modules/system/settings.php');
        }

        log_audit('system_settings', 'job_type_status', $settingId, 'Changed job type status to ' . $nextStatus, [
            'entity' => 'job_type_option',
            'company_id' => $companyId,
            'before' => $before,
            'after' => $jobTypesById[$jobTypeId],
            'metadata' => [
                'setting_key' => 'job_types_catalog_json',
            ],
        ]);
        flash_set('settings_success', 'Job type status updated.', 'success');
        redirect('modules/system/settings.php');
    }

    if ($action === 'create') {
        $settingGroup = strtoupper(post_string('setting_group', 80));
        $settingKey = post_string('setting_key', 120);
        $settingValue = post_string('setting_value', 5000);
        $valueType = strtoupper(post_string('value_type', 10));
        $garageId = post_int('garage_id');
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($settingGroup === '' || $settingKey === '') {
            flash_set('settings_error', 'Setting group and key are required.', 'danger');
            redirect('modules/system/settings.php');
        }

        $allowedTypes = ['STRING', 'NUMBER', 'BOOLEAN', 'JSON'];
        if (!in_array($valueType, $allowedTypes, true)) {
            $valueType = 'STRING';
        }

        $stmt = db()->prepare(
            'SELECT id
             FROM system_settings
             WHERE company_id = :company_id
               AND ((garage_id IS NULL AND :garage_id IS NULL) OR garage_id = :garage_id)
               AND setting_key = :setting_key
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId > 0 ? $garageId : null,
            'setting_key' => $settingKey,
        ]);

        if ($stmt->fetch()) {
            flash_set('settings_error', 'Setting key already exists for selected scope.', 'danger');
            redirect('modules/system/settings.php');
        }

        $insertStmt = db()->prepare(
            'INSERT INTO system_settings
              (company_id, garage_id, setting_group, setting_key, setting_value, value_type, status_code, deleted_at, created_by)
             VALUES
              (:company_id, :garage_id, :setting_group, :setting_key, :setting_value, :value_type, :status_code, :deleted_at, :created_by)'
        );
        $insertStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId > 0 ? $garageId : null,
            'setting_group' => $settingGroup,
            'setting_key' => $settingKey,
            'setting_value' => $settingValue !== '' ? $settingValue : null,
            'value_type' => $valueType,
            'status_code' => $statusCode,
            'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
            'created_by' => (int) $_SESSION['user_id'],
        ]);

        $settingId = (int) db()->lastInsertId();
        log_audit('system_settings', 'create', $settingId, 'Created key ' . $settingKey);
        flash_set('settings_success', 'Setting created successfully.', 'success');
        redirect('modules/system/settings.php');
    }

    if ($action === 'update') {
        $settingId = post_int('setting_id');
        $settingGroup = strtoupper(post_string('setting_group', 80));
        $settingKey = post_string('setting_key', 120);
        $settingValue = post_string('setting_value', 5000);
        $valueType = strtoupper(post_string('value_type', 10));
        $garageId = post_int('garage_id');
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        $allowedTypes = ['STRING', 'NUMBER', 'BOOLEAN', 'JSON'];
        if (!in_array($valueType, $allowedTypes, true)) {
            $valueType = 'STRING';
        }

        $updateStmt = db()->prepare(
            'UPDATE system_settings
             SET garage_id = :garage_id,
                 setting_group = :setting_group,
                 setting_key = :setting_key,
                 setting_value = :setting_value,
                 value_type = :value_type,
                 status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $updateStmt->execute([
            'garage_id' => $garageId > 0 ? $garageId : null,
            'setting_group' => $settingGroup,
            'setting_key' => $settingKey,
            'setting_value' => $settingValue !== '' ? $settingValue : null,
            'value_type' => $valueType,
            'status_code' => $statusCode,
            'id' => $settingId,
            'company_id' => $companyId,
        ]);

        log_audit('system_settings', 'update', $settingId, 'Updated key ' . $settingKey);
        flash_set('settings_success', 'Setting updated successfully.', 'success');
        redirect('modules/system/settings.php');
    }

    if ($action === 'change_status') {
        $settingId = post_int('setting_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));
        $safeDeleteValidation = null;
        if ($nextStatus === 'DELETED') {
            $safeDeleteValidation = safe_delete_validate_post_confirmation('system_setting', $settingId, [
                'operation' => 'delete',
                'reason_field' => 'deletion_reason',
            ]);
        }

        $stmt = db()->prepare(
            'UPDATE system_settings
             SET status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'status_code' => $nextStatus,
            'id' => $settingId,
            'company_id' => $companyId,
        ]);

        log_audit('system_settings', 'status', $settingId, 'Changed status to ' . $nextStatus);
        if ($nextStatus === 'DELETED' && is_array($safeDeleteValidation)) {
            safe_delete_log_cascade('system_setting', 'delete', $settingId, $safeDeleteValidation, [
                'metadata' => [
                    'company_id' => $companyId,
                    'requested_status' => 'DELETED',
                    'applied_status' => $nextStatus,
                ],
            ]);
        }
        flash_set('settings_success', 'Setting status updated.', 'success');
        redirect('modules/system/settings.php');
    }
}

$jobTypeCatalog = job_type_sort_rows(job_type_catalog($companyId));
$jobTypesById = [];
foreach ($jobTypeCatalog as $jobTypeRow) {
    $sanitized = job_type_sanitize_row((array) $jobTypeRow);
    if ($sanitized === null) {
        continue;
    }
    $jobTypesById[(int) $sanitized['id']] = $sanitized;
}
$editJobTypeId = get_int('edit_job_type_id');
$editJobType = $editJobTypeId > 0 ? ($jobTypesById[$editJobTypeId] ?? null) : null;

$editId = get_int('edit_id');
$editSetting = null;
if ($editId > 0) {
    $editStmt = db()->prepare('SELECT * FROM system_settings WHERE id = :id AND company_id = :company_id LIMIT 1');
    $editStmt->execute([
        'id' => $editId,
        'company_id' => $companyId,
    ]);
    $editSetting = $editStmt->fetch() ?: null;
}

$settingsStmt = db()->prepare(
    'SELECT ss.*, g.name AS garage_name
     FROM system_settings ss
     LEFT JOIN garages g ON g.id = ss.garage_id
     WHERE ss.company_id = :company_id
     ORDER BY ss.setting_group ASC, ss.setting_key ASC'
);
$settingsStmt->execute(['company_id' => $companyId]);
$settings = $settingsStmt->fetchAll();
$defaultDateFilterMode = date_filter_normalize_mode(system_setting_get_value($companyId, 0, 'default_date_filter_mode', 'monthly'), 'monthly');

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">System Settings</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Settings</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage): ?>
        <div class="card card-outline card-info">
          <div class="card-header"><h3 class="card-title">Global Date Filter Default</h3></div>
          <form method="post">
            <div class="card-body row g-3 align-items-end">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="update_default_date_filter_mode" />
              <div class="col-md-4">
                <label class="form-label">Default Date Filter Mode</label>
                <select name="default_date_filter_mode" class="form-select" required>
                  <?php foreach ($dateModeOptions as $modeValue => $modeLabel): ?>
                    <option value="<?= e((string) $modeValue); ?>" <?= $defaultDateFilterMode === $modeValue ? 'selected' : ''; ?>>
                      <?= e((string) $modeLabel); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-8">
                <div class="text-muted">
                  Applied across dashboard and report filters on load. Users can still override per session with custom ranges.
                </div>
              </div>
            </div>
            <div class="card-footer">
              <button type="submit" class="btn btn-info">Save Date Filter Default</button>
            </div>
          </form>
        </div>

        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editJobType ? 'Edit Job Type Option' : 'Add Job Type Option'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editJobType ? 'update_job_type' : 'create_job_type'; ?>" />
              <input type="hidden" name="job_type_id" value="<?= (int) ($editJobType['id'] ?? 0); ?>" />

              <div class="col-md-8">
                <label class="form-label">Job Type Name</label>
                <input
                  type="text"
                  name="job_type_name"
                  class="form-control"
                  maxlength="80"
                  required
                  value="<?= e((string) ($editJobType['name'] ?? '')); ?>"
                  placeholder="Example: Insurance Claim Repair"
                />
              </div>
              <div class="col-md-4">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editJobType['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <?php if ((string) ($option['value'] ?? '') === 'DELETED'): ?>
                      <?php continue; ?>
                    <?php endif; ?>
                    <option value="<?= e((string) $option['value']); ?>" <?= !empty($option['selected']) ? 'selected' : ''; ?>>
                      <?= e((string) $option['value']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <div class="form-check form-switch mt-1">
                  <input
                    class="form-check-input"
                    type="checkbox"
                    role="switch"
                    id="job-type-allow-multiple"
                    name="allow_multiple_active_jobs"
                    value="1"
                    <?= !empty($editJobType['allow_multiple_active_jobs']) ? 'checked' : ''; ?>
                  />
                  <label class="form-check-label" for="job-type-allow-multiple">
                    Allow multiple active job cards for this job type
                  </label>
                  <div class="form-text">
                    If enabled, users can create this job type even when another non-closed active job exists for the same vehicle.
                  </div>
                </div>
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editJobType ? 'Update Job Type' : 'Create Job Type'; ?></button>
              <?php if ($editJobType): ?>
                <a href="<?= e(url('modules/system/settings.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>

        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editSetting ? 'Edit Setting' : 'Add Setting'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editSetting ? 'update' : 'create'; ?>" />
              <input type="hidden" name="setting_id" value="<?= (int) ($editSetting['id'] ?? 0); ?>" />

              <div class="col-md-3">
                <label class="form-label">Group</label>
                <input type="text" name="setting_group" class="form-control" required value="<?= e((string) ($editSetting['setting_group'] ?? 'GENERAL')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Key</label>
                <input type="text" name="setting_key" class="form-control" required value="<?= e((string) ($editSetting['setting_key'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Type</label>
                <select name="value_type" class="form-select" required>
                  <?php $types = ['STRING', 'NUMBER', 'BOOLEAN', 'JSON']; ?>
                  <?php foreach ($types as $type): ?>
                    <option value="<?= e($type); ?>" <?= ((string) ($editSetting['value_type'] ?? 'STRING') === $type) ? 'selected' : ''; ?>><?= e($type); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Scope</label>
                <select name="garage_id" class="form-select">
                  <option value="0">Company</option>
                  <?php foreach ($garages as $garage): ?>
                    <option value="<?= (int) $garage['id']; ?>" <?= ((int) ($editSetting['garage_id'] ?? 0) === (int) $garage['id']) ? 'selected' : ''; ?>>
                      <?= e((string) $garage['name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editSetting['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-12">
                <label class="form-label">Value</label>
                <textarea name="setting_value" rows="3" class="form-control"><?= e((string) ($editSetting['setting_value'] ?? '')); ?></textarea>
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editSetting ? 'Update Setting' : 'Create Setting'; ?></button>
              <?php if ($editSetting): ?>
                <a href="<?= e(url('modules/system/settings.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><h3 class="card-title mb-0">Job Type Options</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Job Type</th>
                <th>Status</th>
                <th>Parallel Jobs</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($jobTypeCatalog)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No job type options configured.</td></tr>
              <?php else: ?>
                <?php foreach ($jobTypeCatalog as $jobType): ?>
                  <?php
                    $jobTypeId = (int) ($jobType['id'] ?? 0);
                    $jobTypeStatus = normalize_status_code((string) ($jobType['status_code'] ?? 'ACTIVE'));
                    $nextJobTypeStatus = $jobTypeStatus === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
                    $allowMultipleActiveJobs = !empty($jobType['allow_multiple_active_jobs']);
                  ?>
                  <tr>
                    <td><?= $jobTypeId; ?></td>
                    <td><?= e((string) ($jobType['name'] ?? '')); ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class($jobTypeStatus)); ?>"><?= e($jobTypeStatus); ?></span></td>
                    <td>
                      <span class="badge text-bg-<?= $allowMultipleActiveJobs ? 'success' : 'secondary'; ?>">
                        <?= $allowMultipleActiveJobs ? 'Allowed' : 'Restricted'; ?>
                      </span>
                    </td>
                    <td class="d-flex gap-1">
                      <?php if ($canManage): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/system/settings.php?edit_job_type_id=' . $jobTypeId)); ?>">Edit</a>
                        <?php if ($jobTypeStatus !== 'DELETED'): ?>
                          <form method="post" class="d-inline" data-confirm="Change job type status?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_job_type_status" />
                            <input type="hidden" name="job_type_id" value="<?= $jobTypeId; ?>" />
                            <input type="hidden" name="next_status" value="<?= e($nextJobTypeStatus); ?>" />
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $jobTypeStatus === 'ACTIVE' ? 'Inactivate' : 'Activate'; ?></button>
                          </form>
                          <form method="post" class="d-inline" data-confirm="Delete this job type option?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_job_type_status" />
                            <input type="hidden" name="job_type_id" value="<?= $jobTypeId; ?>" />
                            <input type="hidden" name="next_status" value="DELETED" />
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
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

      <div class="card">
        <div class="card-header"><h3 class="card-title">Settings List</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Group</th>
                <th>Key</th>
                <th>Value</th>
                <th>Type</th>
                <th>Scope</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($settings)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No settings available.</td></tr>
              <?php else: ?>
                <?php foreach ($settings as $setting): ?>
                  <tr>
                    <td><?= (int) $setting['id']; ?></td>
                    <td><?= e((string) $setting['setting_group']); ?></td>
                    <td><code><?= e((string) $setting['setting_key']); ?></code></td>
                    <td><?= e((string) ($setting['setting_value'] ?? '')); ?></td>
                    <td><?= e((string) $setting['value_type']); ?></td>
                    <td><?= e((string) ($setting['garage_name'] ?? 'Company')); ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $setting['status_code'])); ?>"><?= e((string) $setting['status_code']); ?></span></td>
                    <td class="d-flex gap-1">
                      <?php if ($canManage): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/system/settings.php?edit_id=' . (int) $setting['id'])); ?>">Edit</a>
                        <form method="post" class="d-inline" data-confirm="Change setting status?">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="change_status" />
                          <input type="hidden" name="setting_id" value="<?= (int) $setting['id']; ?>" />
                          <input type="hidden" name="next_status" value="<?= e(((string) $setting['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $setting['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                        </form>
                        <?php if ((string) $setting['status_code'] !== 'DELETED'): ?>
                          <form method="post"
                                class="d-inline"
                                data-safe-delete
                                data-safe-delete-entity="system_setting"
                                data-safe-delete-record-field="setting_id"
                                data-safe-delete-operation="delete"
                                data-safe-delete-reason-field="deletion_reason">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="setting_id" value="<?= (int) $setting['id']; ?>" />
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
