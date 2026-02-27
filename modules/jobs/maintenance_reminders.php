<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('job.view');
require_once __DIR__ . '/workflow.php';

$page_title = 'Maintenance Reminders';
$active_menu = 'jobs.maintenance_reminders';
$companyId = active_company_id();
$user = current_user();
$garageOptions = is_array($user['garages'] ?? null) ? (array) $user['garages'] : [];
$garageIds = array_values(
    array_filter(
        array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $garageOptions),
        static fn (int $id): bool => $id > 0
    )
);

$allowAllGarages = count($garageIds) > 1;
$selectedGarageId = get_int('garage_id', active_garage_id());
if ($selectedGarageId > 0 && !in_array($selectedGarageId, $garageIds, true)) {
    $selectedGarageId = 0;
}
if ($selectedGarageId <= 0 && !$allowAllGarages && !empty($garageIds)) {
    $selectedGarageId = (int) $garageIds[0];
}

$serviceTypeFilter = service_reminder_normalize_type((string) ($_GET['service_type'] ?? ''));
$statusFilter = strtoupper(trim((string) ($_GET['status'] ?? 'ALL')));
$allowedStatuses = ['ALL', 'UPCOMING', 'DUE', 'OVERDUE', 'COMPLETED'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'ALL';
}

$defaultFromDate = date('Y-m-d', strtotime('-30 days'));
$defaultToDate = date('Y-m-d');
$fromDate = service_reminder_parse_date((string) ($_GET['from'] ?? $defaultFromDate)) ?? $defaultFromDate;
$toDate = service_reminder_parse_date((string) ($_GET['to'] ?? $defaultToDate)) ?? $defaultToDate;
if (strcmp($fromDate, $toDate) > 0) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$featureReady = service_reminder_feature_ready();
$apiUrl = url('modules/jobs/maintenance_reminders_api.php');
$canManageManualReminders = has_permission('job.manage') || has_permission('settings.manage') || has_permission('job.create');
$manualVehicles = [];
$manualItems = [];
if ($featureReady && $canManageManualReminders && $companyId > 0) {
    $vehicleStmt = db()->prepare(
        'SELECT id, customer_id, registration_no, brand, model, variant
         FROM vehicles
         WHERE company_id = :company_id
           AND status_code = "ACTIVE"
         ORDER BY registration_no ASC, id ASC'
    );
    $vehicleStmt->execute(['company_id' => $companyId]);
    $manualVehicles = $vehicleStmt->fetchAll();
    $manualItems = service_reminder_master_items($companyId, true);
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Maintenance Reminders</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/jobs/index.php')); ?>">Job Cards</a></li>
            <li class="breadcrumb-item active">Maintenance Reminders</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid" id="maintenance-reminders-root" data-api-url="<?= e($apiUrl); ?>">
      <div class="card card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Filters</h3></div>
        <div class="card-body">
          <form method="get" class="row g-2 align-items-end" id="maintenance-reminder-filter-form">
            <?php if ($allowAllGarages): ?>
              <div class="col-md-2">
                <label class="form-label">Garage</label>
                <select name="garage_id" class="form-select" id="maintenance-filter-garage">
                  <option value="0" <?= $selectedGarageId === 0 ? 'selected' : ''; ?>>All Accessible Garages</option>
                  <?php foreach ($garageOptions as $garage): ?>
                    <option value="<?= (int) ($garage['id'] ?? 0); ?>" <?= (int) ($garage['id'] ?? 0) === $selectedGarageId ? 'selected' : ''; ?>>
                      <?= e((string) ($garage['name'] ?? '')); ?> (<?= e((string) ($garage['code'] ?? '')); ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <input type="hidden" name="garage_id" value="<?= (int) $selectedGarageId; ?>">
            <?php endif; ?>
            <div class="col-md-2">
              <label class="form-label">Status</label>
              <select name="status" class="form-select" id="maintenance-filter-status">
                <?php foreach ($allowedStatuses as $status): ?>
                  <option value="<?= e($status); ?>" <?= $statusFilter === $status ? 'selected' : ''; ?>><?= e($status); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Labour Type</label>
              <select name="service_type" class="form-select" id="maintenance-filter-service-type">
                <option value="">All</option>
                <?php foreach (service_reminder_supported_types() as $serviceType): ?>
                  <option value="<?= e($serviceType); ?>" <?= $serviceTypeFilter === $serviceType ? 'selected' : ''; ?>>
                    <?= e(service_reminder_type_label($serviceType)); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">From</label>
              <input type="date" name="from" class="form-control" id="maintenance-filter-from" value="<?= e($fromDate); ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label">To</label>
              <input type="date" name="to" class="form-control" id="maintenance-filter-to" value="<?= e($toDate); ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Apply</button>
              <button type="button" class="btn btn-outline-secondary" id="maintenance-filter-reset">Reset</button>
            </div>
          </form>
        </div>
      </div>

      <?php if ($featureReady && $canManageManualReminders): ?>
        <div class="card card-outline card-success mb-3">
          <div class="card-header">
            <h3 class="card-title mb-0">Add Manual Service Reminder (Admin)</h3>
          </div>
          <div class="card-body">
            <form id="maintenance-manual-form" class="row g-2 align-items-end">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="create_manual_reminder">
              <?php if ($allowAllGarages): ?>
                <div class="col-md-2">
                  <label class="form-label">Garage</label>
                  <select name="garage_id" class="form-select" required>
                    <?php foreach ($garageOptions as $garage): ?>
                      <option value="<?= (int) ($garage['id'] ?? 0); ?>" <?= (int) ($garage['id'] ?? 0) === ($selectedGarageId > 0 ? $selectedGarageId : active_garage_id()) ? 'selected' : ''; ?>>
                        <?= e((string) ($garage['name'] ?? '')); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php else: ?>
                <input type="hidden" name="garage_id" value="<?= (int) ($selectedGarageId > 0 ? $selectedGarageId : active_garage_id()); ?>">
              <?php endif; ?>
              <div class="col-md-4">
                <label class="form-label">Vehicle</label>
                <select name="vehicle_id" class="form-select" required>
                  <option value="">Select Vehicle</option>
                  <?php foreach ($manualVehicles as $vehicle): ?>
                    <?php
                      $vehicleLabel = trim(
                          (string) (($vehicle['registration_no'] ?? '') . ' | ' . ($vehicle['brand'] ?? '') . ' ' . ($vehicle['model'] ?? '') . ' ' . ($vehicle['variant'] ?? ''))
                      );
                    ?>
                    <option value="<?= (int) ($vehicle['id'] ?? 0); ?>"><?= e($vehicleLabel !== '' ? $vehicleLabel : ('Vehicle #' . (int) ($vehicle['id'] ?? 0))); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Labour / Part</label>
                <select name="item_key" class="form-select" required>
                  <option value="">Select Reminder-Enabled Item</option>
                  <?php foreach ($manualItems as $item): ?>
                    <?php
                      $itemType = service_reminder_normalize_type((string) ($item['item_type'] ?? ''));
                      $itemId = (int) ($item['item_id'] ?? 0);
                      if ($itemType === '' || $itemId <= 0) {
                          continue;
                      }
                      $itemCode = trim((string) ($item['item_code'] ?? ''));
                      $itemLabel = trim((string) ($item['item_name'] ?? ''));
                    ?>
                    <option value="<?= e($itemType . ':' . $itemId); ?>">
                      [<?= e($itemType); ?>] <?= e($itemLabel !== '' ? $itemLabel : ($itemType . ' #' . $itemId)); ?><?= $itemCode !== '' ? ' (' . e($itemCode) . ')' : ''; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Due KM</label>
                <input type="number" class="form-control" name="next_due_km" min="0" placeholder="Optional">
              </div>
              <div class="col-md-2">
                <label class="form-label">Due Date</label>
                <input type="date" class="form-control" name="next_due_date">
              </div>
              <div class="col-md-2">
                <label class="form-label">Predicted Visit</label>
                <input type="date" class="form-control" name="predicted_next_visit_date">
              </div>
              <div class="col-md-6">
                <label class="form-label">Recommendation</label>
                <input type="text" class="form-control" name="recommendation_text" maxlength="255" placeholder="Optional note shown in job-card recommendations">
              </div>
              <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-success">Add Manual Reminder</button>
              </div>
            </form>
            <div class="form-text mt-2">
              Source is auto-marked as <strong>ADMIN_MANUAL</strong>. Existing active reminder for same vehicle + item is replaced.
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!$featureReady): ?>
        <div class="alert alert-warning mb-3">Maintenance reminder storage is not ready.</div>
      <?php endif; ?>

      <div class="alert d-none" id="maintenance-reminders-alert"></div>
      <div class="alert d-none" id="maintenance-manual-alert"></div>

      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-primary"><i class="bi bi-bell"></i></span><div class="info-box-content"><span class="info-box-text">Total</span><span class="info-box-number" id="maintenance-summary-total">0</span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-danger"><i class="bi bi-exclamation-circle"></i></span><div class="info-box-content"><span class="info-box-text">Overdue</span><span class="info-box-number" id="maintenance-summary-overdue">0</span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-warning"><i class="bi bi-alarm"></i></span><div class="info-box-content"><span class="info-box-text">Due</span><span class="info-box-number" id="maintenance-summary-due">0</span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-check2-square"></i></span><div class="info-box-content"><span class="info-box-text">Completed</span><span class="info-box-number" id="maintenance-summary-completed">0</span></div></div></div>
      </div>

      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Reminder Register</h3>
          <span class="badge text-bg-light border"><span id="maintenance-rows-count">0</span> rows</span>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0" data-gac-table-mode="search-only">
            <thead>
              <tr>
                <th>Vehicle</th>
                <th>Customer</th>
                <th>Job Card</th>
                <th>Labour/Part</th>
                <th class="text-end">Last Labour KM</th>
                <th class="text-end">Next Due KM</th>
                <th>Next Due Date</th>
                <th>Predicted Visit</th>
                <th>Status</th>
                <th>Source</th>
                <th>Recommendation</th>
              </tr>
            </thead>
            <tbody id="maintenance-reminders-rows">
              <tr><td colspan="11" class="text-center text-muted py-4">Loading maintenance reminders...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
  (function () {
    var root = document.getElementById('maintenance-reminders-root');
    if (!root) {
      return;
    }

    var featureReady = <?= $featureReady ? 'true' : 'false'; ?>;
    if (!featureReady) {
      return;
    }

    var apiUrl = (root.getAttribute('data-api-url') || '').trim();
    var filterForm = document.getElementById('maintenance-reminder-filter-form');
    var manualForm = document.getElementById('maintenance-manual-form');
    var resetBtn = document.getElementById('maintenance-filter-reset');
    var rowsNode = document.getElementById('maintenance-reminders-rows');
    var rowsCountNode = document.getElementById('maintenance-rows-count');
    var alertNode = document.getElementById('maintenance-reminders-alert');
    var manualAlertNode = document.getElementById('maintenance-manual-alert');
    var totalNode = document.getElementById('maintenance-summary-total');
    var overdueNode = document.getElementById('maintenance-summary-overdue');
    var dueNode = document.getElementById('maintenance-summary-due');
    var completedNode = document.getElementById('maintenance-summary-completed');
    var statusInput = document.getElementById('maintenance-filter-status');
    var typeInput = document.getElementById('maintenance-filter-service-type');
    var fromInput = document.getElementById('maintenance-filter-from');
    var toInput = document.getElementById('maintenance-filter-to');
    var garageInput = document.getElementById('maintenance-filter-garage');

    function escapeHtml(value) {
      return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function formatInt(value) {
      var number = Number(value || 0);
      return number.toLocaleString();
    }

    function showAlert(type, message) {
      if (!alertNode) {
        return;
      }
      if (!message) {
        alertNode.className = 'alert d-none';
        alertNode.textContent = '';
        return;
      }
      alertNode.className = 'alert alert-' + type;
      alertNode.textContent = message;
    }

    function showManualAlert(type, message) {
      if (!manualAlertNode) {
        return;
      }
      if (!message) {
        manualAlertNode.className = 'alert d-none';
        manualAlertNode.textContent = '';
        return;
      }
      manualAlertNode.className = 'alert alert-' + type;
      manualAlertNode.textContent = message;
    }

    function collectQuery() {
      var params = new URLSearchParams();
      if (garageInput && garageInput.value !== '') {
        params.append('garage_id', garageInput.value);
      } else if (filterForm && filterForm.querySelector('input[name=\"garage_id\"]')) {
        params.append('garage_id', filterForm.querySelector('input[name=\"garage_id\"]').value || '0');
      }
      if (statusInput && statusInput.value !== '') {
        params.append('status', statusInput.value);
      }
      if (typeInput && typeInput.value !== '') {
        params.append('service_type', typeInput.value);
      }
      if (fromInput && fromInput.value !== '') {
        params.append('from', fromInput.value);
      }
      if (toInput && toInput.value !== '') {
        params.append('to', toInput.value);
      }
      return params.toString();
    }

    function renderSummary(summary) {
      var data = summary || {};
      var dueCount = Number(data.due || 0) + Number(data.due_soon || 0);
      if (totalNode) {
        totalNode.textContent = formatInt(data.total || 0);
      }
      if (overdueNode) {
        overdueNode.textContent = formatInt(data.overdue || 0);
      }
      if (dueNode) {
        dueNode.textContent = formatInt(dueCount);
      }
      if (completedNode) {
        completedNode.textContent = formatInt(data.completed || 0);
      }
    }

    function renderRows(rows) {
      if (!rowsNode) {
        return;
      }
      var items = Array.isArray(rows) ? rows : [];
      if (rowsCountNode) {
        rowsCountNode.textContent = formatInt(items.length);
      }
      if (items.length === 0) {
        rowsNode.innerHTML = '<tr><td colspan=\"11\" class=\"text-center text-muted py-4\">No maintenance reminders found for selected filters.</td></tr>';
        return;
      }

      var html = '';
      for (var i = 0; i < items.length; i++) {
        var row = items[i] || {};
        var vehicleId = Number(row.vehicle_id || 0);
        var customerId = Number(row.customer_id || 0);
        var jobId = Number(row.job_card_id || 0);
        var jobNumber = String(row.job_number || '');
        var registration = String(row.registration_no || '-');
        var modelText = [String(row.brand || ''), String(row.model || ''), String(row.variant || '')].join(' ').trim();
        var customerName = String(row.customer_name || '-');
        var serviceLabel = String(row.service_label || '-');
        var lastKm = row.last_service_km != null ? Number(row.last_service_km).toLocaleString() : '-';
        var dueKm = row.next_due_km != null ? Number(row.next_due_km).toLocaleString() : '-';
        var dueDate = row.next_due_date ? String(row.next_due_date) : '-';
        var predictedVisit = row.predicted_next_visit_date ? String(row.predicted_next_visit_date) : '-';
        var dueState = String(row.due_state || 'UPCOMING');
        var badgeClass = String(row.due_badge_class || 'secondary');
        var source = String(row.source_type || '-');
        var recommendation = String(row.recommendation_text || '-');

        html += '<tr>';
        html += '<td>';
        if (vehicleId > 0) {
          html += '<a href=\"<?= e(url('modules/vehicles/intelligence.php?id=')); ?>' + vehicleId + '\">' + escapeHtml(registration) + '</a>';
        } else {
          html += escapeHtml(registration);
        }
        html += '<div class=\"small text-muted\">' + escapeHtml(modelText) + '</div>';
        html += '</td>';
        html += '<td>';
        if (customerId > 0) {
          html += '<a href=\"<?= e(url('modules/customers/view.php?id=')); ?>' + customerId + '\">' + escapeHtml(customerName) + '</a>';
        } else {
          html += escapeHtml(customerName);
        }
        html += '</td>';
        html += '<td>';
        if (jobId > 0) {
          html += '<a href=\"<?= e(url('modules/jobs/view.php?id=')); ?>' + jobId + '\">' + escapeHtml(jobNumber !== '' ? jobNumber : ('Job #' + jobId)) + '</a>';
        } else {
          html += '-';
        }
        html += '</td>';
        html += '<td>' + escapeHtml(serviceLabel) + '</td>';
        html += '<td class=\"text-end\">' + escapeHtml(lastKm) + '</td>';
        html += '<td class=\"text-end\">' + escapeHtml(dueKm) + '</td>';
        html += '<td>' + escapeHtml(dueDate) + '</td>';
        html += '<td>' + escapeHtml(predictedVisit) + '</td>';
        html += '<td><span class=\"badge text-bg-' + escapeHtml(badgeClass) + '\">' + escapeHtml(dueState) + '</span></td>';
        html += '<td>' + escapeHtml(source) + '</td>';
        html += '<td class=\"small\">' + escapeHtml(recommendation) + '</td>';
        html += '</tr>';
      }
      rowsNode.innerHTML = html;
    }

    function loadRows() {
      showAlert('', '');
      if (rowsNode) {
        rowsNode.innerHTML = '<tr><td colspan=\"11\" class=\"text-center text-muted py-4\">Loading...</td></tr>';
      }
      var query = collectQuery();
      fetch(apiUrl + '?' + query, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (!payload || payload.ok !== true) {
            showAlert('danger', payload && payload.message ? payload.message : 'Unable to load maintenance reminders.');
            renderSummary({});
            renderRows([]);
            return;
          }
          renderSummary(payload.summary || {});
          renderRows(payload.rows || []);
        })
        .catch(function () {
          showAlert('danger', 'Unable to load maintenance reminders.');
          renderSummary({});
          renderRows([]);
        });
    }

    if (filterForm) {
      filterForm.addEventListener('submit', function (event) {
        event.preventDefault();
        loadRows();
      });
    }

    if (manualForm) {
      manualForm.addEventListener('submit', function (event) {
        event.preventDefault();
        showManualAlert('', '');

        var formData = new FormData(manualForm);
        fetch(apiUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: formData
        })
          .then(function (response) { return response.json(); })
          .then(function (payload) {
            if (!payload || payload.ok !== true) {
              showManualAlert('danger', payload && payload.message ? payload.message : 'Unable to add manual reminder.');
              return;
            }
            showManualAlert('success', payload.message || 'Manual reminder added.');
            var garageField = manualForm.querySelector('select[name=\"garage_id\"]');
            var selectedGarage = garageField ? garageField.value : '';
            manualForm.reset();
            if (garageField && selectedGarage !== '') {
              garageField.value = selectedGarage;
            }
            loadRows();
          })
          .catch(function () {
            showManualAlert('danger', 'Unable to add manual reminder.');
          });
      });
    }

    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        if (statusInput) {
          statusInput.value = 'ALL';
        }
        if (typeInput) {
          typeInput.value = '';
        }
        if (fromInput) {
          fromInput.value = '<?= e($defaultFromDate); ?>';
        }
        if (toInput) {
          toInput.value = '<?= e($defaultToDate); ?>';
        }
        if (garageInput) {
          garageInput.value = '<?= (int) $selectedGarageId; ?>';
        }
        loadRows();
      });
    }

    loadRows();
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

