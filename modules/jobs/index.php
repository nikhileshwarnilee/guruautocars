<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('job.view');
require_once __DIR__ . '/workflow.php';

$page_title = 'Job Cards / Work Orders';
$active_menu = 'jobs';
$companyId = active_company_id();
$garageId = active_garage_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$canCreate = has_permission('job.create') || has_permission('job.manage');
$canEdit = has_permission('job.edit') || has_permission('job.update') || has_permission('job.manage');
$canAssign = has_permission('job.assign') || has_permission('job.manage');

function parse_ids(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    foreach ($value as $id) {
        if (filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0) {
            $result[] = (int) $id;
        }
    }
    return array_values(array_unique($result));
}

function job_row(int $id, int $companyId, int $garageId): ?array
{
    $stmt = db()->prepare('SELECT * FROM job_cards WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id LIMIT 1');
    $stmt->execute(['id' => $id, 'company_id' => $companyId, 'garage_id' => $garageId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create' && !$canCreate) {
        flash_set('job_error', 'You do not have permission to create job cards.', 'danger');
        redirect('modules/jobs/index.php');
    }

    if ($action === 'update' && !$canEdit) {
        flash_set('job_error', 'You do not have permission to edit job cards.', 'danger');
        redirect('modules/jobs/index.php');
    }

    if ($action === 'create' && $canCreate) {
        $customerId = post_int('customer_id');
        $vehicleId = post_int('vehicle_id');
        $complaint = post_string('complaint', 3000);
        $diagnosis = post_string('diagnosis', 3000);
        $priority = strtoupper(post_string('priority', 10));
        $promisedAt = post_string('promised_at', 25);
        $assignedUserIds = $canAssign ? parse_ids($_POST['assigned_user_ids'] ?? []) : [];
        if (!in_array($priority, ['LOW', 'MEDIUM', 'HIGH', 'URGENT'], true)) {
            $priority = 'MEDIUM';
        }
        if ($customerId <= 0 || $vehicleId <= 0 || $complaint === '') {
            flash_set('job_error', 'Customer, vehicle and complaint are required.', 'danger');
            redirect('modules/jobs/index.php');
        }

        $check = db()->prepare(
            'SELECT COUNT(*) FROM vehicles v INNER JOIN customers c ON c.id = v.customer_id
             WHERE v.id = :vehicle_id AND c.id = :customer_id AND v.company_id = :company_id AND c.company_id = :company_id
               AND v.status_code = "ACTIVE" AND c.status_code = "ACTIVE"'
        );
        $check->execute(['vehicle_id' => $vehicleId, 'customer_id' => $customerId, 'company_id' => $companyId]);
        if ((int) $check->fetchColumn() === 0) {
            flash_set('job_error', 'Vehicle must belong to selected active customer.', 'danger');
            redirect('modules/jobs/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $jobNumber = job_generate_number($pdo, $garageId);
            $stmt = $pdo->prepare(
                'INSERT INTO job_cards
                  (company_id, garage_id, job_number, customer_id, vehicle_id, assigned_to, service_advisor_id, complaint, diagnosis, status, priority, promised_at, status_code, created_by, updated_by)
                 VALUES
                  (:company_id, :garage_id, :job_number, :customer_id, :vehicle_id, NULL, :service_advisor_id, :complaint, :diagnosis, "OPEN", :priority, :promised_at, "ACTIVE", :created_by, :updated_by)'
            );
            $stmt->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'job_number' => $jobNumber,
                'customer_id' => $customerId,
                'vehicle_id' => $vehicleId,
                'service_advisor_id' => $userId,
                'complaint' => $complaint,
                'diagnosis' => $diagnosis !== '' ? $diagnosis : null,
                'priority' => $priority,
                'promised_at' => $promisedAt !== '' ? str_replace('T', ' ', $promisedAt) : null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $jobId = (int) $pdo->lastInsertId();
            if ($canAssign) {
                $assigned = job_sync_assignments($jobId, $companyId, $garageId, $assignedUserIds, $userId);
                if (!empty($assigned)) {
                    job_append_history($jobId, 'ASSIGN_CREATE', null, null, 'Assigned users', ['user_ids' => $assigned]);
                }
            }
            job_append_history($jobId, 'CREATE', null, 'OPEN', 'Job created', ['job_number' => $jobNumber]);
            log_audit('job_cards', 'create', $jobId, 'Created job card ' . $jobNumber, [
                'entity' => 'job_card',
                'source' => 'UI',
                'before' => ['exists' => false],
                'after' => [
                    'id' => $jobId,
                    'job_number' => $jobNumber,
                    'status' => 'OPEN',
                    'status_code' => 'ACTIVE',
                    'priority' => $priority,
                    'customer_id' => $customerId,
                    'vehicle_id' => $vehicleId,
                ],
                'metadata' => [
                    'assigned_count' => isset($assigned) && is_array($assigned) ? count($assigned) : 0,
                ],
            ]);
            $pdo->commit();
            flash_set('job_success', 'Job card created successfully.', 'success');
            redirect('modules/jobs/view.php?id=' . $jobId);
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('job_error', 'Unable to create job card. Please retry.', 'danger');
            redirect('modules/jobs/index.php');
        }
    }

    if ($action === 'update' && $canEdit) {
        $jobId = post_int('job_id');
        $job = job_row($jobId, $companyId, $garageId);
        if (!$job) {
            flash_set('job_error', 'Job card not found.', 'danger');
            redirect('modules/jobs/index.php');
        }
        if (job_is_locked($job)) {
            flash_set('job_error', 'This job card is locked and cannot be edited.', 'danger');
            redirect('modules/jobs/index.php');
        }

        $complaint = post_string('complaint', 3000);
        $diagnosis = post_string('diagnosis', 3000);
        $priority = strtoupper(post_string('priority', 10));
        $promisedAt = post_string('promised_at', 25);
        $assignedUserIds = $canAssign ? parse_ids($_POST['assigned_user_ids'] ?? []) : [];
        if (!in_array($priority, ['LOW', 'MEDIUM', 'HIGH', 'URGENT'], true)) {
            $priority = 'MEDIUM';
        }
        if ($complaint === '') {
            flash_set('job_error', 'Complaint is required.', 'danger');
            redirect('modules/jobs/index.php?edit_id=' . $jobId);
        }

        $stmt = db()->prepare(
            'UPDATE job_cards SET complaint = :complaint, diagnosis = :diagnosis, priority = :priority, promised_at = :promised_at, updated_by = :updated_by
             WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id'
        );
        $stmt->execute([
            'complaint' => $complaint,
            'diagnosis' => $diagnosis !== '' ? $diagnosis : null,
            'priority' => $priority,
            'promised_at' => $promisedAt !== '' ? str_replace('T', ' ', $promisedAt) : null,
            'updated_by' => $userId,
            'id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        if ($canAssign) {
            $assigned = job_sync_assignments($jobId, $companyId, $garageId, $assignedUserIds, $userId);
            job_append_history($jobId, 'ASSIGN_UPDATE', null, null, 'Updated assignments', ['user_ids' => $assigned]);
        }
        job_append_history($jobId, 'UPDATE_META', (string) $job['status'], (string) $job['status'], 'Job metadata updated');
        log_audit('job_cards', 'update', $jobId, 'Updated job metadata', [
            'entity' => 'job_card',
            'source' => 'UI',
            'before' => [
                'complaint' => (string) ($job['complaint'] ?? ''),
                'diagnosis' => (string) ($job['diagnosis'] ?? ''),
                'priority' => (string) ($job['priority'] ?? ''),
                'promised_at' => (string) ($job['promised_at'] ?? ''),
            ],
            'after' => [
                'complaint' => $complaint,
                'diagnosis' => $diagnosis,
                'priority' => $priority,
                'promised_at' => $promisedAt !== '' ? str_replace('T', ' ', $promisedAt) : null,
            ],
            'metadata' => [
                'assigned_count' => isset($assigned) && is_array($assigned) ? count($assigned) : 0,
            ],
        ]);
        flash_set('job_success', 'Job card updated.', 'success');
        redirect('modules/jobs/index.php');
    }
}

$customers = db()->prepare('SELECT id, full_name, phone FROM customers WHERE company_id = :company_id AND status_code = "ACTIVE" ORDER BY full_name ASC');
$customers->execute(['company_id' => $companyId]);
$customers = $customers->fetchAll();

$vehicles = db()->prepare('SELECT id, customer_id, registration_no, brand, model FROM vehicles WHERE company_id = :company_id AND status_code = "ACTIVE" ORDER BY registration_no ASC');
$vehicles->execute(['company_id' => $companyId]);
$vehicles = $vehicles->fetchAll();
$vehicleAttributesEnabled = vehicle_masters_enabled() && vehicle_master_link_columns_supported();
$vehicleAttributesApiUrl = url('modules/vehicles/attributes_api.php');

$staffCandidates = job_assignment_candidates($companyId, $garageId);

$editId = get_int('edit_id');
$editJob = $editId > 0 && $canEdit ? job_row($editId, $companyId, $garageId) : null;
$editAssignments = [];
if ($editJob) {
    $editAssignments = array_map(static fn (array $row): int => (int) $row['user_id'], job_current_assignments((int) $editJob['id']));
}

$statusFilter = strtoupper(trim((string) ($_GET['status'] ?? '')));
$query = trim((string) ($_GET['q'] ?? ''));
$where = ['jc.company_id = :company_id', 'jc.garage_id = :garage_id', 'jc.status_code <> "DELETED"'];
$params = ['company_id' => $companyId, 'garage_id' => $garageId];
if (in_array($statusFilter, job_workflow_statuses(true), true)) {
    $where[] = 'jc.status = :status';
    $params['status'] = $statusFilter;
}
if ($query !== '') {
    $where[] = '(jc.job_number LIKE :q OR c.full_name LIKE :q OR v.registration_no LIKE :q OR jc.complaint LIKE :q)';
    $params['q'] = '%' . $query . '%';
}

$sql =
    'SELECT jc.id, jc.job_number, jc.status, jc.status_code, jc.priority, jc.opened_at, jc.estimated_cost,
            c.full_name AS customer_name, v.registration_no,
            GROUP_CONCAT(DISTINCT au.name ORDER BY ja.is_primary DESC, au.name SEPARATOR ", ") AS assigned_staff
     FROM job_cards jc
     INNER JOIN customers c ON c.id = jc.customer_id
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     LEFT JOIN job_assignments ja ON ja.job_card_id = jc.id AND ja.status_code = "ACTIVE"
     LEFT JOIN users au ON au.id = ja.user_id
     WHERE ' . implode(' AND ', $where) . '
     GROUP BY jc.id
     ORDER BY jc.id DESC';
$jobs = db()->prepare($sql);
$jobs->execute($params);
$jobs = $jobs->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header"><div class="container-fluid"><div class="row"><div class="col-sm-6"><h3 class="mb-0">Job Cards / Work Orders</h3></div><div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li><li class="breadcrumb-item active">Job Cards</li></ol></div></div></div></div>
  <div class="app-content"><div class="container-fluid">
    <?php if ($canCreate || ($canEdit && $editJob)): ?>
    <div class="card card-primary">
      <div class="card-header"><h3 class="card-title"><?= $editJob ? 'Edit Job Card' : 'Create Job Card'; ?></h3></div>
      <form method="post"><div class="card-body row g-3">
        <?= csrf_field(); ?><input type="hidden" name="_action" value="<?= $editJob ? 'update' : 'create'; ?>"><input type="hidden" name="job_id" value="<?= (int) ($editJob['id'] ?? 0); ?>">
        <div class="col-md-3">
          <label class="form-label">Customer</label>
          <select id="job-customer-select" name="customer_id" class="form-select" required <?= $editJob ? 'disabled' : ''; ?>>
            <option value="">Select Customer</option>
            <?php foreach ($customers as $customer): ?>
              <option value="<?= (int) $customer['id']; ?>" <?= ((int) ($editJob['customer_id'] ?? 0) === (int) $customer['id']) ? 'selected' : ''; ?>>
                <?= e((string) $customer['full_name']); ?> (<?= e((string) $customer['phone']); ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <div id="job-owner-lock-hint" class="form-hint text-muted mt-1"></div>
          <?php if ($editJob): ?><input type="hidden" name="customer_id" value="<?= (int) $editJob['customer_id']; ?>"><?php endif; ?>
        </div>

        <?php if (!$editJob && $vehicleAttributesEnabled): ?>
          <div class="col-md-6" data-vehicle-attributes-root="1" data-vehicle-attributes-mode="filter" data-vehicle-attributes-endpoint="<?= e($vehicleAttributesApiUrl); ?>" data-vehicle-picker-target="#job-vehicle-select" data-vehicle-customer-select="#job-customer-select">
            <label class="form-label">Vehicle Filters</label>
            <div class="row g-2">
              <div class="col-md-4">
                <select name="job_vehicle_brand_id" data-vehicle-attr="brand" class="form-select">
                  <option value="">All Brands</option>
                </select>
              </div>
              <div class="col-md-4">
                <select name="job_vehicle_model_id" data-vehicle-attr="model" class="form-select">
                  <option value="">All Models</option>
                </select>
              </div>
              <div class="col-md-4">
                <select name="job_vehicle_variant_id" data-vehicle-attr="variant" class="form-select">
                  <option value="">All Variants</option>
                </select>
              </div>
              <div class="col-md-6">
                <select name="job_vehicle_model_year_id" data-vehicle-attr="model_year" class="form-select">
                  <option value="">All Years</option>
                </select>
              </div>
              <div class="col-md-6">
                <select name="job_vehicle_color_id" data-vehicle-attr="color" class="form-select">
                  <option value="">All Colors</option>
                </select>
              </div>
            </div>
            <div class="form-hint">Filter vehicle dropdown using standardized attributes.</div>
          </div>
        <?php endif; ?>

        <div class="col-md-3">
          <label class="form-label">Vehicle</label>
          <select id="job-vehicle-select" name="vehicle_id" class="form-select" required <?= $editJob ? 'disabled' : ''; ?>>
            <option value="">Select Vehicle</option>
            <?php foreach ($vehicles as $vehicle): ?>
              <option value="<?= (int) $vehicle['id']; ?>" data-customer-id="<?= (int) $vehicle['customer_id']; ?>" <?= ((int) ($editJob['vehicle_id'] ?? 0) === (int) $vehicle['id']) ? 'selected' : ''; ?>>
                <?= e((string) $vehicle['registration_no']); ?> - <?= e((string) $vehicle['brand']); ?> <?= e((string) $vehicle['model']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($editJob): ?><input type="hidden" name="vehicle_id" value="<?= (int) $editJob['vehicle_id']; ?>"><?php endif; ?>
        </div>
        <div class="col-md-2"><label class="form-label">Priority</label><select name="priority" class="form-select"><?php $priority = (string) ($editJob['priority'] ?? 'MEDIUM'); ?><option value="LOW" <?= $priority === 'LOW' ? 'selected' : ''; ?>>Low</option><option value="MEDIUM" <?= $priority === 'MEDIUM' ? 'selected' : ''; ?>>Medium</option><option value="HIGH" <?= $priority === 'HIGH' ? 'selected' : ''; ?>>High</option><option value="URGENT" <?= $priority === 'URGENT' ? 'selected' : ''; ?>>Urgent</option></select></div>
        <div class="col-md-2"><label class="form-label">Promised</label><input type="datetime-local" name="promised_at" class="form-control" value="<?= e((string) (!empty($editJob['promised_at']) ? str_replace(' ', 'T', substr((string) $editJob['promised_at'], 0, 16)) : '')); ?>"></div>
        <div class="col-md-12"><label class="form-label">Complaint</label><textarea name="complaint" class="form-control" rows="2" required><?= e((string) ($editJob['complaint'] ?? '')); ?></textarea></div>
        <div class="col-md-12"><label class="form-label">Diagnosis</label><textarea name="diagnosis" class="form-control" rows="2"><?= e((string) ($editJob['diagnosis'] ?? '')); ?></textarea></div>
        <?php if ($canAssign): ?><div class="col-md-12"><label class="form-label">Assigned Staff (Multiple)</label><select name="assigned_user_ids[]" class="form-select" multiple size="4"><?php foreach ($staffCandidates as $staff): ?><option value="<?= (int) $staff['id']; ?>" <?= in_array((int) $staff['id'], $editAssignments, true) ? 'selected' : ''; ?>><?= e((string) $staff['name']); ?> - <?= e((string) $staff['role_name']); ?></option><?php endforeach; ?></select></div><?php endif; ?>
      </div><div class="card-footer d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $editJob ? 'Update' : 'Create'; ?></button><?php if ($editJob): ?><a class="btn btn-outline-secondary" href="<?= e(url('modules/jobs/index.php')); ?>">Cancel</a><?php endif; ?></div></form>
    </div>
    <div class="card card-outline card-info mb-3 collapsed-card"><div class="card-header"><h3 class="card-title">VIS Suggestions (Optional)</h3><div class="card-tools"><button type="button" class="btn btn-tool" data-lte-toggle="card-collapse"><i class="bi bi-plus-lg"></i></button></div></div><div class="card-body" id="vis-suggestions-content">Select a vehicle to load optional VIS suggestions. Job creation never depends on VIS.</div></div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Job List</h3><div class="card-tools"><form method="get" class="d-flex gap-2"><input name="q" value="<?= e($query); ?>" class="form-control form-control-sm" placeholder="Search"><select name="status" class="form-select form-select-sm"><option value="">All</option><?php foreach (job_workflow_statuses(true) as $status): ?><option value="<?= e($status); ?>" <?= $statusFilter === $status ? 'selected' : ''; ?>><?= e($status); ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline-primary" type="submit">Filter</button></form></div></div>
      <div class="card-body table-responsive p-0"><table class="table table-striped mb-0"><thead><tr><th>Job</th><th>Customer</th><th>Vehicle</th><th>Assigned</th><th>Priority</th><th>Status</th><th>Estimate</th><th>Opened</th><th></th></tr></thead><tbody>
        <?php if (empty($jobs)): ?><tr><td colspan="9" class="text-center text-muted py-4">No job cards found.</td></tr><?php else: foreach ($jobs as $job): ?>
        <tr>
          <td><?= e((string) $job['job_number']); ?></td><td><?= e((string) $job['customer_name']); ?></td><td><?= e((string) $job['registration_no']); ?></td>
          <td><?= e((string) (($job['assigned_staff'] ?? '') !== '' ? $job['assigned_staff'] : 'Unassigned')); ?></td>
          <td><span class="badge text-bg-warning"><?= e((string) $job['priority']); ?></span></td>
          <td><span class="badge text-bg-secondary"><?= e((string) $job['status']); ?></span></td>
          <td><?= e(format_currency((float) $job['estimated_cost'])); ?></td><td><?= e((string) $job['opened_at']); ?></td>
          <td><a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/jobs/view.php?id=' . (int) $job['id'])); ?>">Open</a></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody></table></div>
    </div>
  </div></div>
</main>
<?php if ($canCreate || ($canEdit && $editJob)): ?>
<script>
  (function () {
    var vehicleSelect = document.getElementById('job-vehicle-select');
    var customerSelect = document.getElementById('job-customer-select');
    var ownerHint = document.getElementById('job-owner-lock-hint');
    var target = document.getElementById('vis-suggestions-content');
    if (!vehicleSelect || !target) return;

    function selectedVehicleCustomerId() {
      if (!vehicleSelect) {
        return '';
      }
      var selected = vehicleSelect.options[vehicleSelect.selectedIndex];
      if (!selected) {
        return '';
      }
      return (selected.getAttribute('data-customer-id') || '').trim();
    }

    function renderOwnerHint(message) {
      if (!ownerHint) {
        return;
      }
      ownerHint.textContent = message || '';
    }

    function syncOwnerFromVehicle() {
      if (!customerSelect || customerSelect.disabled || vehicleSelect.disabled) {
        return;
      }

      var ownerCustomerId = selectedVehicleCustomerId();
      if (ownerCustomerId === '') {
        renderOwnerHint('');
        return;
      }

      if ((customerSelect.value || '') !== ownerCustomerId) {
        customerSelect.value = ownerCustomerId;
        if (typeof gacRefreshSearchableSelect === 'function') {
          gacRefreshSearchableSelect(customerSelect);
        }
        customerSelect.dispatchEvent(new Event('change', { bubbles: true }));
      }
      renderOwnerHint('Owner auto-filled from selected vehicle to prevent mismatches.');
    }

    function enforceVehicleOwnerMatch() {
      if (!customerSelect || customerSelect.disabled || vehicleSelect.disabled) {
        return;
      }

      var selectedVehicleId = (vehicleSelect.value || '').trim();
      if (selectedVehicleId === '') {
        renderOwnerHint('');
        return;
      }

      var ownerCustomerId = selectedVehicleCustomerId();
      if (ownerCustomerId === '' || (customerSelect.value || '') === ownerCustomerId) {
        renderOwnerHint('Owner auto-filled from selected vehicle to prevent mismatches.');
        return;
      }

      vehicleSelect.value = '';
      if (typeof gacRefreshSearchableSelect === 'function') {
        gacRefreshSearchableSelect(vehicleSelect);
      }
      renderOwnerHint('Vehicle selection was cleared because it does not belong to the selected customer.');
      load('');
    }

    function render(data) {
      var services = data.service_suggestions || [], parts = data.part_suggestions || [], variant = data.variant;
      if (!variant && services.length === 0 && parts.length === 0) { target.innerHTML = 'No VIS data for this vehicle. Continue manually.'; return; }
      var html = variant ? '<p><strong>Variant:</strong> ' + variant.brand_name + ' / ' + variant.model_name + ' / ' + variant.variant_name + '</p>' : '';
      html += '<p class="mb-1"><strong>Suggested Services:</strong> ' + services.length + '</p><p class="mb-0"><strong>Compatible Parts:</strong> ' + parts.length + '</p>';
      target.innerHTML = html;
    }
    function load(id) {
      if (!id) { target.innerHTML = 'Select a vehicle to load optional VIS suggestions. Job creation never depends on VIS.'; return; }
      fetch('<?= e(url('modules/jobs/vis_suggestions.php')); ?>?vehicle_id=' + encodeURIComponent(id), {credentials: 'same-origin'})
        .then(function (r) { return r.json(); })
        .then(render)
        .catch(function () { target.innerHTML = 'VIS suggestions unavailable. Continue manually.'; });
    }
    vehicleSelect.addEventListener('change', function () {
      syncOwnerFromVehicle();
      load(vehicleSelect.value);
    });
    if (customerSelect) {
      customerSelect.addEventListener('change', enforceVehicleOwnerMatch);
    }
    if (vehicleSelect.value) {
      syncOwnerFromVehicle();
      load(vehicleSelect.value);
    }
  })();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
