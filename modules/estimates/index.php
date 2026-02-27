<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('estimate.view');
require_once __DIR__ . '/workflow.php';

$estimateTablesReady = table_columns('estimates') !== []
    && table_columns('estimate_counters') !== []
    && table_columns('estimate_services') !== []
    && table_columns('estimate_parts') !== []
    && table_columns('estimate_history') !== [];
if (!$estimateTablesReady) {
    flash_set('estimate_error', 'Estimate module database upgrade is pending. Run database/estimate_module_upgrade.sql.', 'danger');
    redirect('dashboard.php');
}

$page_title = 'Estimates';
$active_menu = 'estimates';
$companyId = active_company_id();
$garageId = active_garage_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$vehicleAttributesEnabled = vehicle_masters_enabled() && vehicle_master_link_columns_supported();
$vehicleAttributesApiUrl = url('modules/vehicles/attributes_api.php');

$canCreate = has_permission('estimate.create') || has_permission('estimate.manage');
$canEdit = has_permission('estimate.edit') || has_permission('estimate.manage');
$canPrint = has_permission('estimate.print') || has_permission('estimate.manage') || has_permission('estimate.view');
$canDelete = has_permission('estimate.edit') || has_permission('estimate.manage');

function estimate_post_date(string $key): ?string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if ($value === '') {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));
    if (!checkdate($month, $day, $year)) {
        return null;
    }

    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'delete_estimate') {
        if (!$canDelete) {
            flash_set('estimate_error', 'You do not have permission to delete estimates.', 'danger');
            redirect('modules/estimates/index.php');
        }

        $estimateId = post_int('estimate_id');
        if ($estimateId <= 0) {
            flash_set('estimate_error', 'Invalid estimate selected for deletion.', 'danger');
            redirect('modules/estimates/index.php');
        }

        $estimate = estimate_fetch_row($estimateId, $companyId, $garageId);
        if (!$estimate) {
            flash_set('estimate_error', 'Estimate not found for active garage.', 'danger');
            redirect('modules/estimates/index.php');
        }

        if (!estimate_is_deletable($estimate)) {
            flash_set('estimate_error', 'Only Draft or Rejected estimates can be deleted.', 'danger');
            redirect('modules/estimates/index.php');
        }

        if (!estimate_soft_delete($estimateId, $companyId, $garageId, $userId)) {
            flash_set('estimate_error', 'Unable to delete estimate. Please retry.', 'danger');
            redirect('modules/estimates/index.php');
        }

        $statusBeforeDelete = estimate_normalize_status((string) ($estimate['estimate_status'] ?? 'DRAFT'));
        $estimateNumber = (string) ($estimate['estimate_number'] ?? ('#' . $estimateId));
        estimate_append_history($estimateId, 'DELETE', $statusBeforeDelete, $statusBeforeDelete, 'Estimate deleted');
        log_audit('estimates', 'delete', $estimateId, 'Deleted estimate ' . $estimateNumber, [
            'entity' => 'estimate',
            'source' => 'UI',
            'before' => [
                'estimate_status' => $statusBeforeDelete,
                'status_code' => 'ACTIVE',
            ],
            'after' => [
                'estimate_status' => $statusBeforeDelete,
                'status_code' => 'DELETED',
            ],
        ]);

        flash_set('estimate_success', 'Estimate deleted successfully: ' . $estimateNumber, 'success');
        redirect('modules/estimates/index.php');
    }

    if ($action === 'create') {
        if (!$canCreate) {
            flash_set('estimate_error', 'You do not have permission to create estimates.', 'danger');
            redirect('modules/estimates/index.php');
        }

        $customerId = post_int('customer_id');
        $vehicleId = post_int('vehicle_id');
        $complaint = post_string('complaint', 3000);
        $notes = post_string('notes', 3000);
        $validUntil = estimate_post_date('valid_until');

        if ($customerId <= 0 || $vehicleId <= 0 || $complaint === '') {
            flash_set('estimate_error', 'Customer, vehicle and complaint are required.', 'danger');
            redirect('modules/estimates/index.php');
        }

        $ownershipCheck = db()->prepare(
            'SELECT COUNT(*)
             FROM vehicles v
             INNER JOIN customers c ON c.id = v.customer_id
             WHERE v.id = :vehicle_id
               AND c.id = :customer_id
               AND v.company_id = :company_id
               AND c.company_id = :company_id
               AND v.status_code = "ACTIVE"
               AND c.status_code = "ACTIVE"'
        );
        $ownershipCheck->execute([
            'vehicle_id' => $vehicleId,
            'customer_id' => $customerId,
            'company_id' => $companyId,
        ]);
        if ((int) $ownershipCheck->fetchColumn() === 0) {
            flash_set('estimate_error', 'Vehicle must belong to selected active customer.', 'danger');
            redirect('modules/estimates/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $estimateNumber = estimate_generate_number($pdo, $garageId);
            $insertStmt = $pdo->prepare(
                'INSERT INTO estimates
                  (company_id, garage_id, estimate_number, customer_id, vehicle_id, complaint, notes,
                   estimate_status, estimate_total, valid_until, status_code, created_by, updated_by)
                 VALUES
                  (:company_id, :garage_id, :estimate_number, :customer_id, :vehicle_id, :complaint, :notes,
                   "DRAFT", 0, :valid_until, "ACTIVE", :created_by, :updated_by)'
            );
            $insertStmt->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'estimate_number' => $estimateNumber,
                'customer_id' => $customerId,
                'vehicle_id' => $vehicleId,
                'complaint' => $complaint,
                'notes' => $notes !== '' ? $notes : null,
                'valid_until' => $validUntil,
                'created_by' => $userId > 0 ? $userId : null,
                'updated_by' => $userId > 0 ? $userId : null,
            ]);
            $estimateId = (int) $pdo->lastInsertId();

            estimate_append_history(
                $estimateId,
                'CREATE',
                null,
                'DRAFT',
                'Estimate created',
                ['estimate_number' => $estimateNumber]
            );
            log_audit('estimates', 'create', $estimateId, 'Created estimate ' . $estimateNumber, [
                'entity' => 'estimate',
                'source' => 'UI',
                'before' => ['exists' => false],
                'after' => [
                    'estimate_number' => $estimateNumber,
                    'estimate_status' => 'DRAFT',
                    'customer_id' => $customerId,
                    'vehicle_id' => $vehicleId,
                ],
            ]);

            $pdo->commit();
            flash_set('estimate_success', 'Estimate created successfully: ' . $estimateNumber, 'success');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('estimate_error', 'Unable to create estimate. Please retry.', 'danger');
            redirect('modules/estimates/index.php');
        }
    }
}

$customers = db()->prepare(
    'SELECT id, full_name, phone
     FROM customers
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY full_name ASC'
);
$customers->execute(['company_id' => $companyId]);
$customers = $customers->fetchAll();

$vehicles = db()->prepare(
    'SELECT id, customer_id, registration_no, brand, model
     FROM vehicles
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY registration_no ASC'
);
$vehicles->execute(['company_id' => $companyId]);
$vehicles = $vehicles->fetchAll();

$statusFilter = estimate_normalize_status((string) ($_GET['status'] ?? ''));
$query = trim((string) ($_GET['q'] ?? ''));

$where = [
    'e.company_id = :company_id',
    'e.garage_id = :garage_id',
    'e.status_code <> "DELETED"',
];
$params = [
    'company_id' => $companyId,
    'garage_id' => $garageId,
];

if (isset($_GET['status']) && $_GET['status'] !== '' && in_array($statusFilter, estimate_statuses(), true)) {
    $where[] = 'e.estimate_status = :estimate_status';
    $params['estimate_status'] = $statusFilter;
}

if ($query !== '') {
    $where[] = '(e.estimate_number LIKE :q OR c.full_name LIKE :q OR v.registration_no LIKE :q OR e.complaint LIKE :q)';
    $params['q'] = '%' . $query . '%';
}

$listStmt = db()->prepare(
    'SELECT e.id, e.estimate_number, e.estimate_status, e.estimate_total, e.valid_until, e.created_at, e.converted_job_card_id, e.status_code,
            c.full_name AS customer_name,
            v.registration_no,
            jc.job_number AS converted_job_number
     FROM estimates e
     INNER JOIN customers c ON c.id = e.customer_id
     INNER JOIN vehicles v ON v.id = e.vehicle_id
     LEFT JOIN job_cards jc ON jc.id = e.converted_job_card_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY e.id DESC'
);
$listStmt->execute($params);
$estimates = $listStmt->fetchAll();

$statusSummaryStmt = db()->prepare(
    'SELECT estimate_status, COUNT(*) AS total
     FROM estimates
     WHERE company_id = :company_id
       AND garage_id = :garage_id
       AND status_code = "ACTIVE"
     GROUP BY estimate_status'
);
$statusSummaryStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$statusSummary = [
    'DRAFT' => 0,
    'APPROVED' => 0,
    'REJECTED' => 0,
    'CONVERTED' => 0,
];
foreach ($statusSummaryStmt->fetchAll() as $row) {
    $key = estimate_normalize_status((string) ($row['estimate_status'] ?? 'DRAFT'));
    $statusSummary[$key] = (int) ($row['total'] ?? 0);
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Estimates</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Estimates</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-secondary"><i class="bi bi-file-earmark"></i></span><div class="info-box-content"><span class="info-box-text">Draft</span><span class="info-box-number"><?= (int) $statusSummary['DRAFT']; ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-check2-circle"></i></span><div class="info-box-content"><span class="info-box-text">Approved</span><span class="info-box-number"><?= (int) $statusSummary['APPROVED']; ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-danger"><i class="bi bi-x-circle"></i></span><div class="info-box-content"><span class="info-box-text">Rejected</span><span class="info-box-number"><?= (int) $statusSummary['REJECTED']; ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-primary"><i class="bi bi-arrow-left-right"></i></span><div class="info-box-content"><span class="info-box-text">Converted</span><span class="info-box-number"><?= (int) $statusSummary['CONVERTED']; ?></span></div></div></div>
      </div>

      <?php if ($canCreate): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title">Create Estimate</h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="create" />
              <div class="col-md-4">
                <label class="form-label">Customer</label>
                <select id="estimate-customer-select" name="customer_id" class="form-select" required>
                  <option value="">Select Customer</option>
                  <?php foreach ($customers as $customer): ?>
                    <option value="<?= (int) $customer['id']; ?>">
                      <?= e((string) $customer['full_name']); ?> (<?= e((string) $customer['phone']); ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php if ($vehicleAttributesEnabled): ?>
                <div class="col-md-4" data-vehicle-attributes-root="1" data-vehicle-attributes-mode="filter" data-vehicle-attributes-endpoint="<?= e($vehicleAttributesApiUrl); ?>" data-vehicle-picker-target="#estimate-vehicle-select" data-vehicle-customer-select="#estimate-customer-select">
                  <label class="form-label">Vehicle (Brand / Model / Variant)</label>
                  <select name="estimate_vehicle_combo_selector" data-vehicle-attr="combo" class="form-select">
                    <option value="">All Brand / Model / Variant</option>
                  </select>
                  <input type="hidden" name="estimate_vehicle_brand_id" data-vehicle-attr-id="brand" value="" />
                  <input type="hidden" name="estimate_vehicle_model_id" data-vehicle-attr-id="model" value="" />
                  <input type="hidden" name="estimate_vehicle_variant_id" data-vehicle-attr-id="variant" value="" />
                </div>
              <?php endif; ?>
              <div class="col-md-4">
                <label class="form-label">Vehicle</label>
                <select id="estimate-vehicle-select" name="vehicle_id" class="form-select" required>
                  <option value="">Select Vehicle</option>
                  <?php foreach ($vehicles as $vehicle): ?>
                    <option value="<?= (int) $vehicle['id']; ?>" data-customer-id="<?= (int) $vehicle['customer_id']; ?>">
                      <?= e((string) $vehicle['registration_no']); ?> - <?= e((string) $vehicle['brand']); ?> <?= e((string) $vehicle['model']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Valid Until (Optional)</label>
                <input type="date" name="valid_until" class="form-control" />
              </div>
              <div class="col-md-12">
                <label class="form-label">Complaint / Scope</label>
                <textarea name="complaint" class="form-control" rows="2" required></textarea>
              </div>
              <div class="col-md-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"></textarea>
              </div>
            </div>
            <div class="card-footer">
              <button type="submit" class="btn btn-primary">Create Draft Estimate</button>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Estimate List</h3>
          <form method="get" class="d-flex gap-2">
            <input name="q" value="<?= e($query); ?>" class="form-control form-control-sm" placeholder="Search" />
            <select name="status" class="form-select form-select-sm">
              <option value="">All</option>
              <?php foreach (estimate_statuses() as $status): ?>
                <option value="<?= e($status); ?>" <?= (isset($_GET['status']) && $statusFilter === $status) ? 'selected' : ''; ?>><?= e($status); ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
          </form>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>Estimate</th>
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Status</th>
                <th>Total</th>
                <th>Valid Until</th>
                <th>Created</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($estimates)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No estimates found.</td></tr>
              <?php else: ?>
                <?php foreach ($estimates as $estimate): ?>
                  <?php
                    $estimateId = (int) $estimate['id'];
                    $estimateStatus = estimate_normalize_status((string) ($estimate['estimate_status'] ?? 'DRAFT'));
                    $convertedJobId = (int) ($estimate['converted_job_card_id'] ?? 0);
                    $canDeleteEstimate = $canDelete && estimate_is_deletable([
                        'estimate_status' => $estimateStatus,
                        'status_code' => (string) ($estimate['status_code'] ?? 'ACTIVE'),
                    ]);
                  ?>
                  <tr>
                    <td><?= e((string) $estimate['estimate_number']); ?></td>
                    <td><?= e((string) $estimate['customer_name']); ?></td>
                    <td><?= e((string) $estimate['registration_no']); ?></td>
                    <td><span class="badge text-bg-<?= e(estimate_status_badge_class($estimateStatus)); ?>"><?= e($estimateStatus); ?></span></td>
                    <td><?= e(format_currency((float) ($estimate['estimate_total'] ?? 0))); ?></td>
                    <td><?= e((string) (($estimate['valid_until'] ?? '') !== '' ? $estimate['valid_until'] : '-')); ?></td>
                    <td><?= e((string) $estimate['created_at']); ?></td>
                    <td class="d-flex gap-1">
                      <a href="<?= e(url('modules/estimates/view.php?id=' . $estimateId)); ?>" class="btn btn-sm btn-outline-primary"><?= $canEdit ? 'Open / Edit' : 'Open'; ?></a>
                      <?php if ($canPrint): ?>
                        <a href="<?= e(url('modules/estimates/print_estimate.php?id=' . $estimateId)); ?>" class="btn btn-sm btn-outline-secondary" target="_blank">Print</a>
                      <?php endif; ?>
                      <?php if ($convertedJobId > 0): ?>
                        <a href="<?= e(url('modules/jobs/view.php?id=' . $convertedJobId)); ?>" class="btn btn-sm btn-outline-success">Job <?= e((string) ($estimate['converted_job_number'] ?? ('#' . $convertedJobId))); ?></a>
                      <?php endif; ?>
                      <?php if ($canDeleteEstimate): ?>
                        <form method="post" class="d-inline" data-confirm="Delete this estimate? This cannot be undone.">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="delete_estimate" />
                          <input type="hidden" name="estimate_id" value="<?= $estimateId; ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
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

<?php if ($canCreate): ?>
<script>
  (function () {
    var customerSelect = document.getElementById('estimate-customer-select');
    var vehicleSelect = document.getElementById('estimate-vehicle-select');
    if (!customerSelect || !vehicleSelect) {
      return;
    }

    function selectedVehicleCustomerId() {
      var selected = vehicleSelect.options[vehicleSelect.selectedIndex];
      if (!selected) {
        return '';
      }
      return (selected.getAttribute('data-customer-id') || '').trim();
    }

    function syncCustomerFromVehicle() {
      var customerId = selectedVehicleCustomerId();
      if (customerId === '') {
        return;
      }
      if ((customerSelect.value || '') !== customerId) {
        customerSelect.value = customerId;
        if (typeof gacRefreshSearchableSelect === 'function') {
          gacRefreshSearchableSelect(customerSelect);
        }
      }
    }

    function enforceMatch() {
      var selectedVehicleId = (vehicleSelect.value || '').trim();
      if (selectedVehicleId === '') {
        return;
      }
      var vehicleOwnerId = selectedVehicleCustomerId();
      if (vehicleOwnerId === '' || (customerSelect.value || '') === vehicleOwnerId) {
        return;
      }
      vehicleSelect.value = '';
      if (typeof gacRefreshSearchableSelect === 'function') {
        gacRefreshSearchableSelect(vehicleSelect);
      }
    }

    vehicleSelect.addEventListener('change', syncCustomerFromVehicle);
    customerSelect.addEventListener('change', enforceMatch);
  })();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
