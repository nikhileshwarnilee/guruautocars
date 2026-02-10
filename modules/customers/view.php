<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('customer.view');

$page_title = 'Customer 360 View';
$active_menu = 'customers';
$companyId = active_company_id();
$customerId = get_int('id');

if ($customerId <= 0) {
    flash_set('customer_error', 'Invalid customer selected for detailed view.', 'danger');
    redirect('modules/customers/index.php');
}

$customerStmt = db()->prepare(
    'SELECT *
     FROM customers
     WHERE id = :id
       AND company_id = :company_id
     LIMIT 1'
);
$customerStmt->execute([
    'id' => $customerId,
    'company_id' => $companyId,
]);
$customer = $customerStmt->fetch();

if (!$customer) {
    flash_set('customer_error', 'Customer not found.', 'danger');
    redirect('modules/customers/index.php');
}

$vehiclesStmt = db()->prepare(
    'SELECT id, registration_no, brand, model, variant
     FROM vehicles
     WHERE company_id = :company_id
       AND customer_id = :customer_id
       AND (status_code IS NULL OR status_code <> "DELETED")
     ORDER BY registration_no ASC, id DESC'
);
$vehiclesStmt->execute([
    'company_id' => $companyId,
    'customer_id' => $customerId,
]);
$vehicles = $vehiclesStmt->fetchAll();

$showVehicleFilter = count($vehicles) > 1;
$customer360ApiUrl = url('modules/customers/customer_360_api.php?id=' . $customerId);

$addressParts = [];
foreach (['address_line1', 'address_line2', 'city', 'state', 'pincode'] as $addressField) {
    $value = trim((string) ($customer[$addressField] ?? ''));
    if ($value !== '') {
        $addressParts[] = $value;
    }
}
$fullAddress = $addressParts !== [] ? implode(', ', $addressParts) : '-';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-7">
          <h3 class="mb-0">Customer 360 View</h3>
          <small class="text-muted">Unified customer profile, vehicles, jobs, invoices and payments.</small>
        </div>
        <div class="col-sm-5">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/customers/index.php')); ?>">Customers</a></li>
            <li class="breadcrumb-item active">Customer 360</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid" data-customer360-root="1" data-customer360-endpoint="<?= e($customer360ApiUrl); ?>">
      <div class="card card-primary card-outline">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0"><?= e((string) ($customer['full_name'] ?? '-')); ?></h3>
          <a href="<?= e(url('modules/customers/index.php')); ?>" class="btn btn-sm btn-outline-secondary">Back to Customer Master</a>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-lg-3 col-md-6">
              <div class="border rounded p-3 h-100">
                <div class="text-muted small mb-1">Phone</div>
                <div class="fw-semibold"><?= e((string) ($customer['phone'] ?? '-')); ?></div>
                <div class="text-muted small mt-2">Alt Phone</div>
                <div><?= e((string) ($customer['alt_phone'] ?? '-')); ?></div>
              </div>
            </div>
            <div class="col-lg-3 col-md-6">
              <div class="border rounded p-3 h-100">
                <div class="text-muted small mb-1">Email</div>
                <div class="fw-semibold"><?= e((string) ($customer['email'] ?? '-')); ?></div>
                <div class="text-muted small mt-2">GSTIN</div>
                <div><?= e((string) ($customer['gstin'] ?? '-')); ?></div>
              </div>
            </div>
            <div class="col-lg-3 col-md-6">
              <div class="border rounded p-3 h-100">
                <div class="text-muted small mb-1">Address</div>
                <div><?= e($fullAddress); ?></div>
              </div>
            </div>
            <div class="col-lg-3 col-md-6">
              <div class="border rounded p-3 h-100">
                <div class="text-muted small mb-1">Current Status</div>
                <div><span class="badge text-bg-<?= e(status_badge_class((string) ($customer['status_code'] ?? 'ACTIVE'))); ?>"><?= e(record_status_label((string) ($customer['status_code'] ?? 'ACTIVE'))); ?></span></div>
                <div class="text-muted small mt-2">Created At</div>
                <div><?= e((string) ($customer['created_at'] ?? '-')); ?></div>
              </div>
            </div>
          </div>
          <?php if (trim((string) ($customer['notes'] ?? '')) !== ''): ?>
            <div class="alert alert-light border mt-3 mb-0">
              <strong>Notes:</strong> <?= e((string) $customer['notes']); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-4 col-sm-6">
          <div class="small-box text-bg-success mb-0">
            <div class="inner">
              <h3 data-customer360-stat="total_revenue">-</h3>
              <p>Total Revenue</p>
            </div>
            <div class="small-box-icon"><i class="bi bi-currency-rupee"></i></div>
          </div>
        </div>
        <div class="col-md-4 col-sm-6">
          <div class="small-box text-bg-warning mb-0">
            <div class="inner">
              <h3 data-customer360-stat="outstanding_amount">-</h3>
              <p>Outstanding Amount</p>
            </div>
            <div class="small-box-icon"><i class="bi bi-exclamation-triangle"></i></div>
          </div>
        </div>
        <div class="col-md-4 col-sm-6">
          <div class="small-box text-bg-primary mb-0">
            <div class="inner">
              <h3 data-customer360-stat="total_jobs_done">-</h3>
              <p>Total Jobs Done</p>
            </div>
            <div class="small-box-icon"><i class="bi bi-tools"></i></div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <div class="row g-3 align-items-end">
            <div class="col-lg-6">
              <?php if ($showVehicleFilter): ?>
                <label class="form-label mb-1">Vehicle Filter</label>
                <select class="form-select" data-customer360-vehicle-filter="1">
                  <option value="">All Vehicles</option>
                  <?php foreach ($vehicles as $vehicle): ?>
                    <?php $vehicleLabel = trim((string) (($vehicle['registration_no'] ?? '') . ' - ' . ($vehicle['brand'] ?? '') . ' ' . ($vehicle['model'] ?? '') . ' ' . ($vehicle['variant'] ?? ''))); ?>
                    <option value="<?= (int) $vehicle['id']; ?>"><?= e($vehicleLabel !== '' ? $vehicleLabel : (string) ($vehicle['registration_no'] ?? ('Vehicle #' . (int) $vehicle['id']))); ?></option>
                  <?php endforeach; ?>
                </select>
              <?php elseif (!empty($vehicles)): ?>
                <?php
                  $singleVehicle = $vehicles[0];
                  $singleLabel = trim((string) (($singleVehicle['registration_no'] ?? '') . ' - ' . ($singleVehicle['brand'] ?? '') . ' ' . ($singleVehicle['model'] ?? '') . ' ' . ($singleVehicle['variant'] ?? '')));
                ?>
                <label class="form-label mb-1">Vehicle</label>
                <div class="form-control bg-light"><?= e($singleLabel !== '' ? $singleLabel : ('Vehicle #' . (int) ($singleVehicle['id'] ?? 0))); ?></div>
              <?php else: ?>
                <label class="form-label mb-1">Vehicle</label>
                <div class="form-control bg-light">No active vehicles linked</div>
              <?php endif; ?>
            </div>
            <div class="col-lg-6">
              <div class="alert alert-info mb-0 py-2">
                Vehicle filtering updates Jobs, Invoices, Payments and summary cards.
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header p-0 border-bottom-0">
          <ul class="nav nav-tabs" id="customer360Tabs" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active" id="tab-vehicles" data-bs-toggle="tab" data-bs-target="#pane-vehicles" type="button" role="tab" aria-controls="pane-vehicles" aria-selected="true">Vehicles</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="tab-jobs" data-bs-toggle="tab" data-bs-target="#pane-jobs" type="button" role="tab" aria-controls="pane-jobs" aria-selected="false">Job Cards</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="tab-invoices" data-bs-toggle="tab" data-bs-target="#pane-invoices" type="button" role="tab" aria-controls="pane-invoices" aria-selected="false">Invoices</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="tab-payments" data-bs-toggle="tab" data-bs-target="#pane-payments" type="button" role="tab" aria-controls="pane-payments" aria-selected="false">Payments</button>
            </li>
          </ul>
        </div>
        <div class="card-body">
          <div class="alert alert-danger d-none" data-customer360-error="1"></div>
          <div class="tab-content">
            <div class="tab-pane fade show active" id="pane-vehicles" role="tabpanel" aria-labelledby="tab-vehicles">
              <div data-customer360-section="vehicles" class="pt-2 text-muted">Loading vehicles...</div>
            </div>
            <div class="tab-pane fade" id="pane-jobs" role="tabpanel" aria-labelledby="tab-jobs">
              <div data-customer360-section="jobs" class="pt-2 text-muted">Loading job cards...</div>
            </div>
            <div class="tab-pane fade" id="pane-invoices" role="tabpanel" aria-labelledby="tab-invoices">
              <div data-customer360-section="invoices" class="pt-2 text-muted">Loading invoices...</div>
            </div>
            <div class="tab-pane fade" id="pane-payments" role="tabpanel" aria-labelledby="tab-payments">
              <div data-customer360-section="payments" class="pt-2 text-muted">Loading payments...</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
  (function () {
    var root = document.querySelector('[data-customer360-root="1"]');
    if (!root || root.getAttribute('data-customer360-init') === '1') {
      return;
    }
    root.setAttribute('data-customer360-init', '1');

    var endpoint = (root.getAttribute('data-customer360-endpoint') || '').trim();
    if (endpoint === '') {
      return;
    }

    var vehicleSelect = root.querySelector('[data-customer360-vehicle-filter="1"]');
    var errorBox = root.querySelector('[data-customer360-error="1"]');
    var statNodes = root.querySelectorAll('[data-customer360-stat]');
    var sectionNames = ['vehicles', 'jobs', 'invoices', 'payments'];
    var requestSequence = 0;

    function setLoadingState() {
      for (var i = 0; i < sectionNames.length; i++) {
        var key = sectionNames[i];
        var node = root.querySelector('[data-customer360-section="' + key + '"]');
        if (!node) {
          continue;
        }
        node.innerHTML = '<div class="text-muted py-3">Loading ' + key + '...</div>';
      }
    }

    function renderErrorState(message) {
      for (var i = 0; i < sectionNames.length; i++) {
        var key = sectionNames[i];
        var node = root.querySelector('[data-customer360-section="' + key + '"]');
        if (!node) {
          continue;
        }
        node.innerHTML = '<div class="text-danger py-3">' + message + '</div>';
      }
    }

    function clearError() {
      if (!errorBox) {
        return;
      }
      errorBox.textContent = '';
      errorBox.classList.add('d-none');
    }

    function showError(message) {
      if (!errorBox) {
        return;
      }
      errorBox.textContent = message || 'Unable to load customer details right now.';
      errorBox.classList.remove('d-none');
    }

    function buildRequestUrl() {
      var params = new URLSearchParams();
      if (vehicleSelect && vehicleSelect.value) {
        params.append('vehicle_id', String(vehicleSelect.value));
      }

      if (params.toString() === '') {
        return endpoint;
      }
      return endpoint + (endpoint.indexOf('?') >= 0 ? '&' : '?') + params.toString();
    }

    function updateStats(stats) {
      var payload = stats || {};
      for (var i = 0; i < statNodes.length; i++) {
        var node = statNodes[i];
        var key = (node.getAttribute('data-customer360-stat') || '').trim();
        if (key === '' || !Object.prototype.hasOwnProperty.call(payload, key)) {
          continue;
        }
        node.textContent = String(payload[key]);
      }
    }

    function updateSections(sections) {
      var payload = sections || {};
      for (var i = 0; i < sectionNames.length; i++) {
        var key = sectionNames[i];
        var node = root.querySelector('[data-customer360-section="' + key + '"]');
        if (!node) {
          continue;
        }
        if (typeof payload[key] === 'string' && payload[key] !== '') {
          node.innerHTML = payload[key];
        } else {
          node.innerHTML = '<div class="text-muted py-3">No data available.</div>';
        }
      }
    }

    function refreshCustomer360() {
      var currentRequest = ++requestSequence;
      setLoadingState();
      clearError();

      fetch(buildRequestUrl(), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (response) {
          return response.text().then(function (text) {
            var payload = null;
            try {
              payload = JSON.parse(text);
            } catch (error) {
              payload = null;
            }
            return {
              ok: response.ok,
              payload: payload
            };
          });
        })
        .then(function (result) {
          if (currentRequest !== requestSequence) {
            return;
          }

          var payload = result.payload || {};
          if (!result.ok || !payload.ok) {
            showError(payload.message || 'Unable to load customer details right now.');
            renderErrorState('Unable to load section data.');
            return;
          }

          updateStats(payload.stats || {});
          updateSections(payload.sections || {});
        })
        .catch(function () {
          if (currentRequest !== requestSequence) {
            return;
          }
          showError('Unable to load customer details right now.');
          renderErrorState('Unable to load section data.');
        });
    }

    if (vehicleSelect) {
      vehicleSelect.addEventListener('change', refreshCustomer360);
    }

    refreshCustomer360();
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
