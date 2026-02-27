<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/workflow.php';

$canView = has_permission('job.view') || has_permission('job.manage') || has_permission('job.print') || has_permission('settings.manage');
if (!$canView) {
    flash_set('access_denied', 'You do not have permission to access job card print settings.', 'danger');
    redirect('dashboard.php');
}

$page_title = 'Job Card Print Settings';
$active_menu = 'jobs.settings';
$companyId = active_company_id();
$garageId = active_garage_id();
$actorUserId = (int) ($_SESSION['user_id'] ?? 0);
$canManageSettings = has_permission('job.manage') || has_permission('settings.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');

    if (!$canManageSettings) {
        flash_set('job_error', 'You do not have permission to update job card print settings.', 'danger');
        redirect('modules/jobs/print_settings.php');
    }

    if ($action === 'save_print_settings') {
        $defaults = job_card_print_default_settings();
        $payload = [];
        foreach (array_keys($defaults) as $settingKey) {
            $payload[$settingKey] = isset($_POST[$settingKey]);
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            flash_set('job_error', 'Unable to serialize job card print settings. Please retry.', 'danger');
            redirect('modules/jobs/print_settings.php');
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
            flash_set('job_error', 'Unable to save job card print settings right now.', 'danger');
            redirect('modules/jobs/print_settings.php');
        }

        log_audit('jobs', 'job_card_print_settings_update', $settingId, 'Updated job card print visibility settings.', [
            'entity' => 'job_card_print_settings',
            'source' => 'UI',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'metadata' => $payload,
        ]);
        flash_set('job_success', 'Job card print settings saved.', 'success');
        redirect('modules/jobs/print_settings.php');
    }
}

$printSettings = job_card_print_settings($companyId, $garageId);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Job Card Print Settings</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/jobs/index.php')); ?>">Job Cards</a></li>
            <li class="breadcrumb-item active">Print Settings</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-outline card-info">
        <div class="card-header"><h3 class="card-title">Job Card Display Controls</h3></div>
        <form method="post">
          <div class="card-body row g-3">
            <?= csrf_field(); ?>
            <input type="hidden" name="_action" value="save_print_settings" />

            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_company_logo" name="show_company_logo" <?= !empty($printSettings['show_company_logo']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_company_logo">Show company logo in header</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_company_gstin" name="show_company_gstin" <?= !empty($printSettings['show_company_gstin']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_company_gstin">Show company GSTIN</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_customer_gstin" name="show_customer_gstin" <?= !empty($printSettings['show_customer_gstin']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_customer_gstin">Show customer GSTIN</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_assigned_staff" name="show_assigned_staff" <?= !empty($printSettings['show_assigned_staff']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_assigned_staff">Show assigned staff section</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_job_meta" name="show_job_meta" <?= !empty($printSettings['show_job_meta']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_job_meta">Show job meta (priority/advisor)</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_complaint" name="show_complaint" <?= !empty($printSettings['show_complaint']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_complaint">Show complaint section</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_diagnosis" name="show_diagnosis" <?= !empty($printSettings['show_diagnosis']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_diagnosis">Show notes/diagnosis section</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_recommendation_note" name="show_recommendation_note" <?= !empty($printSettings['show_recommendation_note']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_recommendation_note">Show recommendation note section</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_insurance_section" name="show_insurance_section" <?= !empty($printSettings['show_insurance_section']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_insurance_section">Show insurance section</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_labor_lines" name="show_labor_lines" <?= !empty($printSettings['show_labor_lines']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_labor_lines">Show labour line items</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_parts_lines" name="show_parts_lines" <?= !empty($printSettings['show_parts_lines']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_parts_lines">Show parts line items</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_next_service_reminders" name="show_next_service_reminders" <?= !empty($printSettings['show_next_service_reminders']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_next_service_reminders">Show next recommended service section</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_totals" name="show_totals" <?= !empty($printSettings['show_totals']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_totals">Show totals block</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_cancel_note" name="show_cancel_note" <?= !empty($printSettings['show_cancel_note']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_cancel_note">Show cancel note</label>
              </div>
            </div>
            <div class="col-md-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_costs_in_job_card_print" name="show_costs_in_job_card_print" <?= !empty($printSettings['show_costs_in_job_card_print']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_costs_in_job_card_print">Show costs (Rate, GST, totals and insurance claim amounts) in job card print</label>
              </div>
            </div>

            <div class="col-12">
              <small class="text-muted">Controls apply to job card print/PDF in the currently active garage.</small>
            </div>
          </div>
          <?php if ($canManageSettings): ?>
            <div class="card-footer">
              <button type="submit" class="btn btn-primary">Save Job Card Print Settings</button>
            </div>
          <?php else: ?>
            <div class="card-footer text-muted">View only. Contact admin to update settings.</div>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
