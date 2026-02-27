<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('settings.view');

$page_title = 'System Settings';
$active_menu = 'system.settings';
$canManage = has_permission('settings.manage');
$canManageInvoicePrintSettings = has_permission('invoice.manage') || has_permission('settings.manage');
$canManageJobCardPrintSettings = has_permission('job.manage') || has_permission('settings.manage');
$canViewInvoicePrintSettings = has_permission('billing.view')
    || has_permission('invoice.view')
    || has_permission('invoice.manage')
    || has_permission('settings.manage');
$canViewJobCardPrintSettings = has_permission('job.view')
    || has_permission('job.print')
    || has_permission('job.manage')
    || has_permission('settings.manage');
$companyId = active_company_id();
$garageId = active_garage_id();
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

function system_settings_to_bool(mixed $value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return ((float) $value) > 0;
    }
    if (!is_string($value)) {
        return $default;
    }

    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return $default;
    }

    if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) {
        return false;
    }

    return $default;
}

function settings_invoice_print_default_settings(): array
{
    return [
        'show_company_logo' => true,
        'show_company_gstin' => true,
        'show_customer_gstin' => true,
        'show_recommendation_note' => true,
        'show_next_service_reminders' => true,
        'show_paid_outstanding' => true,
        'show_advance_adjustment_history' => true,
    ];
}

function settings_invoice_print_settings(int $companyId, int $garageId): array
{
    $defaults = settings_invoice_print_default_settings();
    $rawValue = system_setting_get_value($companyId, $garageId, 'invoice_print_settings_json', null);
    if ($rawValue === null || trim($rawValue) === '') {
        return $defaults;
    }

    $decoded = json_decode($rawValue, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $resolved = $defaults;
    foreach ($defaults as $settingKey => $defaultValue) {
        $resolved[$settingKey] = system_settings_to_bool($decoded[$settingKey] ?? null, (bool) $defaultValue);
    }

    return $resolved;
}

function settings_job_card_print_default_settings(): array
{
    return [
        'show_company_logo' => true,
        'show_company_gstin' => true,
        'show_customer_gstin' => true,
        'show_assigned_staff' => true,
        'show_job_meta' => true,
        'show_complaint' => true,
        'show_diagnosis' => true,
        'show_recommendation_note' => true,
        'show_insurance_section' => true,
        'show_labor_lines' => true,
        'show_parts_lines' => true,
        'show_next_service_reminders' => true,
        'show_totals' => true,
        'show_cancel_note' => true,
        'show_costs_in_job_card_print' => true,
    ];
}

function settings_job_card_print_settings(int $companyId, int $garageId): array
{
    $defaults = settings_job_card_print_default_settings();
    $rawValue = system_setting_get_value($companyId, $garageId, 'job_card_print_settings_json', null);
    if ($rawValue === null || trim($rawValue) === '') {
        return $defaults;
    }

    $decoded = json_decode($rawValue, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $resolved = $defaults;
    foreach ($defaults as $settingKey => $defaultValue) {
        $resolved[$settingKey] = system_settings_to_bool($decoded[$settingKey] ?? null, (bool) $defaultValue);
    }

    return $resolved;
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
    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'save_invoice_print_settings') {
        if (!$canManageInvoicePrintSettings) {
            flash_set('settings_error', 'You do not have permission to update invoice print settings.', 'danger');
            redirect('modules/system/settings.php?tab=invoice_print');
        }

        $defaults = settings_invoice_print_default_settings();
        $payload = [];
        foreach (array_keys($defaults) as $settingKey) {
            $payload[$settingKey] = isset($_POST[$settingKey]);
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            flash_set('settings_error', 'Unable to serialize invoice print settings. Please retry.', 'danger');
            redirect('modules/system/settings.php?tab=invoice_print');
        }

        $settingId = system_setting_upsert_value(
            $companyId,
            $garageId,
            'BILLING',
            'invoice_print_settings_json',
            $encoded,
            'JSON',
            'ACTIVE',
            $actorUserId > 0 ? $actorUserId : null
        );
        if ($settingId <= 0) {
            flash_set('settings_error', 'Unable to save invoice print settings right now.', 'danger');
            redirect('modules/system/settings.php?tab=invoice_print');
        }

        log_audit('billing', 'invoice_print_settings_update', $settingId, 'Updated invoice print visibility settings from system settings.', [
            'entity' => 'invoice_print_settings',
            'source' => 'UI',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'metadata' => $payload,
        ]);
        flash_set('settings_success', 'Invoice print settings saved.', 'success');
        redirect('modules/system/settings.php?tab=invoice_print');
    }

    if ($action === 'save_job_card_print_settings') {
        if (!$canManageJobCardPrintSettings) {
            flash_set('settings_error', 'You do not have permission to update job card print settings.', 'danger');
            redirect('modules/system/settings.php?tab=job_card_print');
        }

        $defaults = settings_job_card_print_default_settings();
        $payload = [];
        foreach (array_keys($defaults) as $settingKey) {
            $payload[$settingKey] = isset($_POST[$settingKey]);
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            flash_set('settings_error', 'Unable to serialize job card print settings. Please retry.', 'danger');
            redirect('modules/system/settings.php?tab=job_card_print');
        }

        $settingId = system_setting_upsert_value(
            $companyId,
            $garageId,
            'JOBS',
            'job_card_print_settings_json',
            $encoded,
            'JSON',
            'ACTIVE',
            $actorUserId > 0 ? $actorUserId : null
        );
        if ($settingId <= 0) {
            flash_set('settings_error', 'Unable to save job card print settings right now.', 'danger');
            redirect('modules/system/settings.php?tab=job_card_print');
        }

        log_audit('jobs', 'job_card_print_settings_update', $settingId, 'Updated job card print visibility settings from system settings.', [
            'entity' => 'job_card_print_settings',
            'source' => 'UI',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'metadata' => $payload,
        ]);
        flash_set('settings_success', 'Job card print settings saved.', 'success');
        redirect('modules/system/settings.php?tab=job_card_print');
    }

    if (!$canManage) {
        flash_set('settings_error', 'You do not have permission to modify settings.', 'danger');
        redirect('modules/system/settings.php?tab=general');
    }

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

        redirect('modules/system/settings.php?tab=general');
    }

    if ($action === 'create_job_type') {
        $jobTypeName = post_string('job_type_name', 80);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        $allowMultipleActiveJobs = !empty($_POST['allow_multiple_active_jobs']) ? 1 : 0;

        if ($statusCode === 'DELETED') {
            flash_set('settings_error', 'Use delete action to mark a job type as deleted.', 'danger');
            redirect('modules/system/settings.php?tab=job_types');
        }

        if ($jobTypeName === '') {
            flash_set('settings_error', 'Job type name is required.', 'danger');
            redirect('modules/system/settings.php?tab=job_types');
        }

        if (job_type_name_exists($jobTypesById, $jobTypeName, null)) {
            flash_set('settings_error', 'Job type name already exists. Use edit to modify it.', 'danger');
            redirect('modules/system/settings.php?tab=job_types');
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
            redirect('modules/system/settings.php?tab=job_types');
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
        redirect('modules/system/settings.php?tab=job_types');
    }

    if ($action === 'update_job_type') {
        $jobTypeId = post_int('job_type_id');
        $jobTypeName = post_string('job_type_name', 80);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        $allowMultipleActiveJobs = !empty($_POST['allow_multiple_active_jobs']) ? 1 : 0;

        if ($statusCode === 'DELETED') {
            flash_set('settings_error', 'Use delete action to mark a job type as deleted.', 'danger');
            redirect('modules/system/settings.php?tab=job_types');
        }

        if ($jobTypeId <= 0 || !isset($jobTypesById[$jobTypeId])) {
            flash_set('settings_error', 'Job type not found for update.', 'danger');
            redirect('modules/system/settings.php?tab=job_types');
        }

        if ($jobTypeName === '') {
            flash_set('settings_error', 'Job type name is required.', 'danger');
            redirect('modules/system/settings.php?tab=job_types&edit_job_type_id=' . $jobTypeId);
        }

        if (job_type_name_exists($jobTypesById, $jobTypeName, $jobTypeId)) {
            flash_set('settings_error', 'Job type name already exists. Choose a different name.', 'danger');
            redirect('modules/system/settings.php?tab=job_types&edit_job_type_id=' . $jobTypeId);
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
            redirect('modules/system/settings.php?tab=job_types&edit_job_type_id=' . $jobTypeId);
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
        redirect('modules/system/settings.php?tab=job_types');
    }

    if ($action === 'change_job_type_status') {
        $jobTypeId = post_int('job_type_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));

        if ($jobTypeId <= 0 || !isset($jobTypesById[$jobTypeId])) {
            flash_set('settings_error', 'Job type not found for status update.', 'danger');
            redirect('modules/system/settings.php?tab=job_types');
        }

        $before = $jobTypesById[$jobTypeId];
        $jobTypesById[$jobTypeId]['status_code'] = $nextStatus;

        $settingId = job_type_save_catalog($companyId, array_values($jobTypesById), $actorUserId > 0 ? $actorUserId : null);
        if ($settingId <= 0) {
            flash_set('settings_error', 'Unable to update job type status right now.', 'danger');
            redirect('modules/system/settings.php?tab=job_types');
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
        redirect('modules/system/settings.php?tab=job_types');
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
            redirect('modules/system/settings.php?tab=general');
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
            redirect('modules/system/settings.php?tab=general');
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
        redirect('modules/system/settings.php?tab=general');
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
        redirect('modules/system/settings.php?tab=general');
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
        redirect('modules/system/settings.php?tab=general');
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
$invoicePrintSettings = settings_invoice_print_settings($companyId, $garageId);
$jobCardPrintSettings = settings_job_card_print_settings($companyId, $garageId);

$activeGarageName = 'Garage #' . $garageId;
foreach ($garages as $garage) {
    if ((int) ($garage['id'] ?? 0) === $garageId) {
        $activeGarageName = (string) ($garage['name'] ?? $activeGarageName);
        break;
    }
}

$settingsTabs = [
    'general' => [
        'label' => 'General',
        'visible' => true,
    ],
    'job_types' => [
        'label' => 'Job Types',
        'visible' => true,
    ],
    'invoice_print' => [
        'label' => 'Invoice Print',
        'visible' => $canViewInvoicePrintSettings,
    ],
    'job_card_print' => [
        'label' => 'Job Card Print',
        'visible' => $canViewJobCardPrintSettings,
    ],
];

$requestedTab = strtolower(trim((string) ($_GET['tab'] ?? '')));
if ($requestedTab === '' && $editJobTypeId > 0) {
    $requestedTab = 'job_types';
}
if ($requestedTab === '') {
    $requestedTab = 'general';
}
if (!isset($settingsTabs[$requestedTab]) || !$settingsTabs[$requestedTab]['visible']) {
    $requestedTab = 'general';
}
$activeSettingsTab = $requestedTab;

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
      <div class="d-flex flex-wrap gap-2 mb-3">
        <?php foreach ($settingsTabs as $tabKey => $tabMeta): ?>
          <?php if (empty($tabMeta['visible'])): ?>
            <?php continue; ?>
          <?php endif; ?>
          <a
            href="<?= e(url('modules/system/settings.php?tab=' . urlencode((string) $tabKey))); ?>"
            class="btn btn-sm <?= $activeSettingsTab === $tabKey ? 'btn-primary' : 'btn-outline-primary'; ?>">
            <?= e((string) ($tabMeta['label'] ?? 'Tab')); ?>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if ($canManage): ?>
        <?php if ($activeSettingsTab === 'general'): ?>
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
        <?php endif; ?>

        <?php if ($activeSettingsTab === 'job_types'): ?>
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
                <a href="<?= e(url('modules/system/settings.php?tab=job_types')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
        <?php endif; ?>

        <?php if ($activeSettingsTab === 'general'): ?>
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
                <a href="<?= e(url('modules/system/settings.php?tab=general')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($activeSettingsTab === 'job_types'): ?>
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
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/system/settings.php?tab=job_types&edit_job_type_id=' . $jobTypeId)); ?>">Edit</a>
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
      <?php endif; ?>

      <?php if ($activeSettingsTab === 'general'): ?>
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
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/system/settings.php?tab=general&edit_id=' . (int) $setting['id'])); ?>">Edit</a>
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
      <?php endif; ?>

      <?php if ($activeSettingsTab === 'invoice_print' && $canViewInvoicePrintSettings): ?>
        <div class="card card-outline card-info">
          <div class="card-header"><h3 class="card-title">Invoice Print Settings</h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="save_invoice_print_settings" />
              <div class="col-md-6">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="inv_show_company_logo" name="show_company_logo" <?= !empty($invoicePrintSettings['show_company_logo']) ? 'checked' : ''; ?> <?= $canManageInvoicePrintSettings ? '' : 'disabled'; ?> />
                  <label class="form-check-label" for="inv_show_company_logo">Show company/invoice logo in header</label>
                </div>
              </div>
              <div class="col-md-6">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="inv_show_company_gstin" name="show_company_gstin" <?= !empty($invoicePrintSettings['show_company_gstin']) ? 'checked' : ''; ?> <?= $canManageInvoicePrintSettings ? '' : 'disabled'; ?> />
                  <label class="form-check-label" for="inv_show_company_gstin">Show company GSTIN</label>
                </div>
              </div>
              <div class="col-md-6">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="inv_show_customer_gstin" name="show_customer_gstin" <?= !empty($invoicePrintSettings['show_customer_gstin']) ? 'checked' : ''; ?> <?= $canManageInvoicePrintSettings ? '' : 'disabled'; ?> />
                  <label class="form-check-label" for="inv_show_customer_gstin">Show customer GSTIN</label>
                </div>
              </div>
              <div class="col-md-6">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="inv_show_recommendation_note" name="show_recommendation_note" <?= !empty($invoicePrintSettings['show_recommendation_note']) ? 'checked' : ''; ?> <?= $canManageInvoicePrintSettings ? '' : 'disabled'; ?> />
                  <label class="form-check-label" for="inv_show_recommendation_note">Show recommendation note section</label>
                </div>
              </div>
              <div class="col-md-6">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="inv_show_next_service_reminders" name="show_next_service_reminders" <?= !empty($invoicePrintSettings['show_next_service_reminders']) ? 'checked' : ''; ?> <?= $canManageInvoicePrintSettings ? '' : 'disabled'; ?> />
                  <label class="form-check-label" for="inv_show_next_service_reminders">Show next recommended service section</label>
                </div>
              </div>
              <div class="col-md-6">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="inv_show_paid_outstanding" name="show_paid_outstanding" <?= !empty($invoicePrintSettings['show_paid_outstanding']) ? 'checked' : ''; ?> <?= $canManageInvoicePrintSettings ? '' : 'disabled'; ?> />
                  <label class="form-check-label" for="inv_show_paid_outstanding">Show paid and outstanding in final totals</label>
                </div>
              </div>
              <div class="col-md-6">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="inv_show_advance_adjustment_history" name="show_advance_adjustment_history" <?= !empty($invoicePrintSettings['show_advance_adjustment_history']) ? 'checked' : ''; ?> <?= $canManageInvoicePrintSettings ? '' : 'disabled'; ?> />
                  <label class="form-check-label" for="inv_show_advance_adjustment_history">Show advance adjustment history in print</label>
                </div>
              </div>
              <div class="col-12">
                <small class="text-muted">Applied to active garage: <?= e($activeGarageName); ?>.</small>
              </div>
            </div>
            <?php if ($canManageInvoicePrintSettings): ?>
              <div class="card-footer">
                <button type="submit" class="btn btn-primary">Save Invoice Print Settings</button>
              </div>
            <?php else: ?>
              <div class="card-footer text-muted">View only. Contact admin to update invoice settings.</div>
            <?php endif; ?>
          </form>
        </div>
      <?php endif; ?>

      <?php if ($activeSettingsTab === 'job_card_print' && $canViewJobCardPrintSettings): ?>
        <div class="card card-outline card-info">
          <div class="card-header"><h3 class="card-title">Job Card Print Settings</h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="save_job_card_print_settings" />

              <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="job_show_company_logo" name="show_company_logo" <?= !empty($jobCardPrintSettings['show_company_logo']) ? 'checked' : ''; ?> <?= $canManageJobCardPrintSettings ? '' : 'disabled'; ?> /><label class="form-check-label" for="job_show_company_logo">Show company logo in header</label></div></div>
              <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="job_show_company_gstin" name="show_company_gstin" <?= !empty($jobCardPrintSettings['show_company_gstin']) ? 'checked' : ''; ?> <?= $canManageJobCardPrintSettings ? '' : 'disabled'; ?> /><label class="form-check-label" for="job_show_company_gstin">Show company GSTIN</label></div></div>
              <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="job_show_customer_gstin" name="show_customer_gstin" <?= !empty($jobCardPrintSettings['show_customer_gstin']) ? 'checked' : ''; ?> <?= $canManageJobCardPrintSettings ? '' : 'disabled'; ?> /><label class="form-check-label" for="job_show_customer_gstin">Show customer GSTIN</label></div></div>
              <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="job_show_assigned_staff" name="show_assigned_staff" <?= !empty($jobCardPrintSettings['show_assigned_staff']) ? 'checked' : ''; ?> <?= $canManageJobCardPrintSettings ? '' : 'disabled'; ?> /><label class="form-check-label" for="job_show_assigned_staff">Show assigned staff section</label></div></div>
              <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="job_show_job_meta" name="show_job_meta" <?= !empty($jobCardPrintSettings['show_job_meta']) ? 'checked' : ''; ?> <?= $canManageJobCardPrintSettings ? '' : 'disabled'; ?> /><label class="form-check-label" for="job_show_job_meta">Show job meta (priority/advisor)</label></div></div>
              <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="job_show_complaint" name="show_complaint" <?= !empty($jobCardPrintSettings['show_complaint']) ? 'checked' : ''; ?> <?= $canManageJobCardPrintSettings ? '' : 'disabled'; ?> /><label class="form-check-label" for="job_show_complaint">Show complaint section</label></div></div>
              <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="job_show_diagnosis" name="show_diagnosis" <?= !empty($jobCardPrintSettings['show_diagnosis']) ? 'checked' : ''; ?> <?= $canManageJobCardPrintSettings ? '' : 'disabled'; ?> /><label class="form-check-label" for="job_show_diagnosis">Show notes/diagnosis section</label></div></div>
              <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="job_show_recommendation_note" name="show_recommendation_note" <?= !empty($jobCardPrintSettings['show_recommendation_note']) ? 'checked' : ''; ?> <?= $canManageJobCardPrintSettings ? '' : 'disabled'; ?> /><label class="form-check-label" for="job_show_recommendation_note">Show recommendation note section</label></div></div>
              <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="job_show_insurance_section" name="show_insurance_section" <?= !empty($jobCardPrintSettings['show_insurance_section']) ? 'checked' : ''; ?> <?= $canManageJobCardPrintSettings ? '' : 'disabled'; ?> /><label class="form-check-label" for="job_show_insurance_section">Show insurance section</label></div></div>
              <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="job_show_labor_lines" name="show_labor_lines" <?= !empty($jobCardPrintSettings['show_labor_lines']) ? 'checked' : ''; ?> <?= $canManageJobCardPrintSettings ? '' : 'disabled'; ?> /><label class="form-check-label" for="job_show_labor_lines">Show labour line items</label></div></div>
              <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="job_show_parts_lines" name="show_parts_lines" <?= !empty($jobCardPrintSettings['show_parts_lines']) ? 'checked' : ''; ?> <?= $canManageJobCardPrintSettings ? '' : 'disabled'; ?> /><label class="form-check-label" for="job_show_parts_lines">Show parts line items</label></div></div>
              <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="job_show_next_service_reminders" name="show_next_service_reminders" <?= !empty($jobCardPrintSettings['show_next_service_reminders']) ? 'checked' : ''; ?> <?= $canManageJobCardPrintSettings ? '' : 'disabled'; ?> /><label class="form-check-label" for="job_show_next_service_reminders">Show next recommended service section</label></div></div>
              <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="job_show_totals" name="show_totals" <?= !empty($jobCardPrintSettings['show_totals']) ? 'checked' : ''; ?> <?= $canManageJobCardPrintSettings ? '' : 'disabled'; ?> /><label class="form-check-label" for="job_show_totals">Show totals block</label></div></div>
              <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="job_show_cancel_note" name="show_cancel_note" <?= !empty($jobCardPrintSettings['show_cancel_note']) ? 'checked' : ''; ?> <?= $canManageJobCardPrintSettings ? '' : 'disabled'; ?> /><label class="form-check-label" for="job_show_cancel_note">Show cancel note</label></div></div>
              <div class="col-md-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="job_show_costs" name="show_costs_in_job_card_print" <?= !empty($jobCardPrintSettings['show_costs_in_job_card_print']) ? 'checked' : ''; ?> <?= $canManageJobCardPrintSettings ? '' : 'disabled'; ?> /><label class="form-check-label" for="job_show_costs">Show costs (Rate, GST, totals and insurance claim amounts) in job card print</label></div></div>
              <div class="col-12">
                <small class="text-muted">Applied to active garage: <?= e($activeGarageName); ?>.</small>
              </div>
            </div>
            <?php if ($canManageJobCardPrintSettings): ?>
              <div class="card-footer">
                <button type="submit" class="btn btn-primary">Save Job Card Print Settings</button>
              </div>
            <?php else: ?>
              <div class="card-footer text-muted">View only. Contact admin to update job card print settings.</div>
            <?php endif; ?>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
