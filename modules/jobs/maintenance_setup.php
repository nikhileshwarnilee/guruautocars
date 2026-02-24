<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('job.view');
require_once __DIR__ . '/workflow.php';

$page_title = 'Vehicle Maintenance Setup';
$active_menu = 'jobs.maintenance_setup';
$companyId = active_company_id();
$canManageRules = has_permission('job.manage') || has_permission('job.edit') || has_permission('job.create');
$featureReady = service_reminder_feature_ready();
$apiUrl = url('modules/jobs/maintenance_setup_api.php');

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-7">
          <h3 class="mb-0">Vehicle Maintenance Setup</h3>
          <small class="text-muted">Set rule intervals by VIS brand/model/variant for reminder-enabled services and parts.</small>
        </div>
        <div class="col-sm-5">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/jobs/index.php')); ?>">Job Cards</a></li>
            <li class="breadcrumb-item active">Maintenance Setup</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid" id="maintenance-setup-root"
         data-api-url="<?= e($apiUrl); ?>"
         data-csrf="<?= e(csrf_token()); ?>"
         data-can-manage="<?= $canManageRules ? '1' : '0'; ?>">

      <?php if (!$featureReady): ?>
        <div class="alert alert-warning">Maintenance reminder storage is not ready. Run DB upgrade and retry.</div>
      <?php endif; ?>

      <div id="maintenance-setup-alert" class="alert d-none"></div>

      <div class="card card-outline card-primary mb-3">
        <div class="card-header"><h3 class="card-title mb-0">Vehicle Type Selection</h3></div>
        <div class="card-body">
          <div class="row g-2 align-items-end">
            <div class="col-md-10">
              <label class="form-label">Brand / Model / Variant</label>
              <select id="maintenance-filter-combo" class="form-select">
                <option value="">All Brand / Model / Variant</option>
              </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
              <button type="button" id="maintenance-reset-filters" class="btn btn-outline-secondary">Reset</button>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Vehicle Variant Selection</h3>
          <div>
            <span class="badge text-bg-light border" id="maintenance-selected-count">0 selected</span>
            <button type="button" id="maintenance-load-rules" class="btn btn-sm btn-outline-primary ms-2">Load Rules For Selection</button>
          </div>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0" data-gac-table-skip="1">
            <thead>
              <tr>
                <th style="width:40px;"><input type="checkbox" id="maintenance-select-all"></th>
                <th>Brand / Model / Variant</th>
              </tr>
            </thead>
            <tbody id="maintenance-vehicle-rows">
              <tr><td colspan="2" class="text-center text-muted py-4">Loading vehicle variants...</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card d-none" id="maintenance-rules-workspace">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Rule Grid</h3>
          <div class="small text-muted" id="maintenance-primary-label"></div>
        </div>
        <div class="card-body">
          <div class="alert alert-info d-none" id="maintenance-copy-prompt">
            <div class="mb-2 fw-semibold">No rules found for selected variant. Do you want to copy from another variant?</div>
            <div class="row g-2 align-items-end">
              <div class="col-md-4">
                <label class="form-label">Search Source Variant</label>
                <input type="text" id="maintenance-copy-search" class="form-control" placeholder="Type brand/model/variant">
              </div>
              <div class="col-md-6">
                <label class="form-label">Source Variant</label>
                <select id="maintenance-copy-source" class="form-select">
                  <option value="">Select source variant</option>
                </select>
              </div>
              <div class="col-md-2">
                <button type="button" id="maintenance-copy-btn" class="btn btn-outline-primary w-100" <?= $canManageRules ? '' : 'disabled'; ?>>Copy Rules</button>
              </div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0" data-gac-table-skip="1">
              <thead>
                <tr>
                  <th>Service/Part Name</th>
                  <th style="width:90px;">Type</th>
                  <th style="width:130px;">Interval KM</th>
                  <th style="width:130px;">Interval Days</th>
                  <th style="width:80px;">Active</th>
                  <th style="width:90px;">Delete</th>
                </tr>
              </thead>
              <tbody id="maintenance-rule-rows">
                <tr><td colspan="6" class="text-center text-muted py-4">Select vehicles and load rules.</td></tr>
              </tbody>
            </table>
          </div>
          <div class="form-text mt-2">Only reminder-enabled services and parts are shown in this grid.</div>
        </div>
        <div class="card-footer d-flex gap-2">
          <button type="button" id="maintenance-save-rules" class="btn btn-success" <?= $canManageRules ? '' : 'disabled'; ?>>Save Rules</button>
          <?php if (!$canManageRules): ?>
            <span class="text-muted small align-self-center">You have view-only access to maintenance rules.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
  (function () {
    var root = document.getElementById('maintenance-setup-root');
    if (!root) {
      return;
    }

    var apiUrl = (root.getAttribute('data-api-url') || '').trim();
    var csrfToken = (root.getAttribute('data-csrf') || '').trim();
    var canManage = root.getAttribute('data-can-manage') === '1';
    var alertBox = document.getElementById('maintenance-setup-alert');
    var comboFilter = document.getElementById('maintenance-filter-combo');
    var vehicleRows = document.getElementById('maintenance-vehicle-rows');
    var selectedCountNode = document.getElementById('maintenance-selected-count');
    var selectAllNode = document.getElementById('maintenance-select-all');
    var resetFiltersBtn = document.getElementById('maintenance-reset-filters');
    var loadRulesBtn = document.getElementById('maintenance-load-rules');
    var workspace = document.getElementById('maintenance-rules-workspace');
    var primaryLabel = document.getElementById('maintenance-primary-label');
    var copyPrompt = document.getElementById('maintenance-copy-prompt');
    var copySearch = document.getElementById('maintenance-copy-search');
    var copySource = document.getElementById('maintenance-copy-source');
    var copyBtn = document.getElementById('maintenance-copy-btn');
    var ruleRows = document.getElementById('maintenance-rule-rows');
    var saveRulesBtn = document.getElementById('maintenance-save-rules');

    var state = {
      selectedVariantKeys: [],
      variantsByKey: {},
      primaryVehicleId: 0,
      primaryVariantKey: '',
      rules: []
    };

    function showAlert(type, message) {
      if (!alertBox) {
        return;
      }
      if (!message) {
        alertBox.className = 'alert d-none';
        alertBox.textContent = '';
        return;
      }
      alertBox.className = 'alert alert-' + type;
      alertBox.textContent = message;
    }

    function escapeHtml(value) {
      var text = String(value == null ? '' : value);
      return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function encodeQuery(params) {
      var query = new URLSearchParams();
      Object.keys(params).forEach(function (key) {
        if (!Object.prototype.hasOwnProperty.call(params, key)) {
          return;
        }
        var value = params[key];
        if (value === null || typeof value === 'undefined' || value === '') {
          return;
        }
        query.append(key, String(value));
      });
      return query.toString();
    }

    function parseComboVariantId(rawValue) {
      var value = String(rawValue == null ? '' : rawValue).trim();
      if (value === '') {
        return 0;
      }
      if (value.indexOf(':') >= 0) {
        var parts = value.split(':');
        if (parts.length > 0) {
          var tail = Number(parts[parts.length - 1] || 0);
          return tail > 0 ? tail : 0;
        }
        return 0;
      }
      var directId = Number(value || 0);
      return directId > 0 ? directId : 0;
    }

    function setComboOptions(items, preserveValue) {
      if (!comboFilter) {
        return;
      }
      var currentValue = String(preserveValue || comboFilter.value || '').trim();
      var options = ['<option value="">All Brand / Model / Variant</option>'];
      var seen = {};
      (Array.isArray(items) ? items : []).forEach(function (item) {
        var key = String(item && item.variant_key ? item.variant_key : '').trim();
        if (key === '') {
          var brandId = Number(item && item.vis_brand_id ? item.vis_brand_id : 0);
          var modelId = Number(item && item.vis_model_id ? item.vis_model_id : 0);
          var variantId = Number(item && item.vis_variant_id ? item.vis_variant_id : (item && item.id ? item.id : 0));
          if (brandId > 0 && modelId > 0 && variantId > 0) {
            key = brandId + ':' + modelId + ':' + variantId;
          }
        }
        if (key === '' || seen[key]) {
          return;
        }
        seen[key] = true;
        var label = String(item && item.label ? item.label : '').trim();
        if (label === '') {
          label = [
            String(item && item.brand ? item.brand : '').trim(),
            String(item && item.model ? item.model : '').trim(),
            String(item && item.variant ? item.variant : '').trim()
          ].join(' ').trim();
        }
        if (label === '') {
          label = 'Variant';
        }
        var selected = key === currentValue ? ' selected' : '';
        options.push('<option value="' + escapeHtml(key) + '"' + selected + '>' + escapeHtml(label) + '</option>');
      });
      comboFilter.innerHTML = options.join('');
      if (typeof gacRefreshSearchableSelect === 'function') {
        gacRefreshSearchableSelect(comboFilter);
      }
    }

    function loadComboOptions(searchText, preserveSelection) {
      var keepValue = preserveSelection ? String(comboFilter ? (comboFilter.value || '') : '').trim() : '';
      var query = encodeQuery({
        action: 'vehicles',
        q: searchText || '',
        limit: 2000
      });
      return getJson(apiUrl + '?' + query)
        .then(function (response) {
          if (!response || !response.ok) {
            if (!preserveSelection) {
              setComboOptions([], '');
            }
            return;
          }
          setComboOptions(response.items || [], keepValue);
        })
        .catch(function () {
          if (!preserveSelection) {
            setComboOptions([], '');
          }
        });
    }

    function getJson(url) {
      return fetch(url, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (response) {
        return response.json();
      });
    }

    function postForm(payload) {
      var body = new URLSearchParams();
      body.append('_csrf', csrfToken);
      Object.keys(payload).forEach(function (key) {
        if (!Object.prototype.hasOwnProperty.call(payload, key)) {
          return;
        }
        var value = payload[key];
        if (Array.isArray(value)) {
          value.forEach(function (item) {
            body.append(key + '[]', String(item));
          });
          return;
        }
        body.append(key, String(value));
      });

      return fetch(apiUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body: body.toString()
      }).then(function (response) {
        return response.json();
      });
    }

    function selectedVariantKeys() {
      return state.selectedVariantKeys.slice();
    }

    function selectedVehicleIds() {
      var ids = [];
      var seen = {};
      selectedVariantKeys().forEach(function (variantKey) {
        var row = state.variantsByKey[variantKey];
        if (!row || !Array.isArray(row.vehicle_ids)) {
          return;
        }
        row.vehicle_ids.forEach(function (rawId) {
          var vehicleId = Number(rawId || 0);
          if (vehicleId <= 0 || seen[vehicleId]) {
            return;
          }
          seen[vehicleId] = true;
          ids.push(vehicleId);
        });
      });
      return ids;
    }

    function selectedPrimaryVariantKey() {
      var keys = selectedVariantKeys();
      return keys.length > 0 ? keys[0] : '';
    }

    function selectedPrimaryVehicleId() {
      var variantKey = selectedPrimaryVariantKey();
      if (variantKey === '') {
        return 0;
      }
      var row = state.variantsByKey[variantKey];
      if (!row) {
        return 0;
      }
      var primaryVehicleId = Number(row.primary_vehicle_id || 0);
      if (primaryVehicleId > 0) {
        return primaryVehicleId;
      }
      var mappedIds = Array.isArray(row.vehicle_ids) ? row.vehicle_ids : [];
      return mappedIds.length > 0 ? Number(mappedIds[0] || 0) : 0;
    }

    function updateSelectedCount() {
      var count = selectedVariantKeys().length;
      if (selectedCountNode) {
        selectedCountNode.textContent = count + ' selected';
      }
      if (selectAllNode && vehicleRows) {
        var checkboxes = vehicleRows.querySelectorAll('input[data-variant-key]');
        var checkedCount = 0;
        checkboxes.forEach(function (node) {
          if (node.checked) {
            checkedCount++;
          }
        });
        selectAllNode.checked = checkboxes.length > 0 && checkedCount === checkboxes.length;
      }
    }

    function normalizeVariantSelection() {
      var checked = [];
      if (!vehicleRows) {
        state.selectedVariantKeys = checked;
        return;
      }
      var nodes = vehicleRows.querySelectorAll('input[data-variant-key]');
      nodes.forEach(function (node) {
        if (!node.checked) {
          return;
        }
        var variantKey = String(node.getAttribute('data-variant-key') || '').trim();
        if (variantKey !== '') {
          checked.push(variantKey);
        }
      });
      state.selectedVariantKeys = checked;
      updateSelectedCount();
    }

    function renderVehicleRows(items) {
      if (!vehicleRows) {
        return;
      }
      state.variantsByKey = {};
      if (!Array.isArray(items) || items.length === 0) {
        vehicleRows.innerHTML = '<tr><td colspan="2" class="text-center text-muted py-4">No vehicle variants found for current filters.</td></tr>';
        state.selectedVariantKeys = [];
        updateSelectedCount();
        return;
      }

      var selectedMap = {};
      selectedVariantKeys().forEach(function (variantKey) {
        selectedMap[variantKey] = true;
      });

      var html = '';
      items.forEach(function (item) {
        var variantKey = String(item.variant_key || '').trim();
        if (variantKey === '') {
          var visBrandId = Number(item.vis_brand_id || 0);
          var visModelId = Number(item.vis_model_id || 0);
          var visVariantId = Number(item.vis_variant_id || item.id || 0);
          if (visBrandId > 0 && visModelId > 0 && visVariantId > 0) {
            variantKey = [visBrandId, visModelId, visVariantId].join(':');
          }
        }
        if (variantKey === '') {
          return;
        }

        var vehicleIds = [];
        var vehicleSeen = {};
        if (Array.isArray(item.vehicle_ids)) {
          item.vehicle_ids.forEach(function (rawVehicleId) {
            var vehicleId = Number(rawVehicleId || 0);
            if (vehicleId <= 0 || vehicleSeen[vehicleId]) {
              return;
            }
            vehicleSeen[vehicleId] = true;
            vehicleIds.push(vehicleId);
          });
        }
        var primaryVehicleId = Number(item.primary_vehicle_id || 0);
        if (primaryVehicleId <= 0 && vehicleIds.length > 0) {
          primaryVehicleId = Number(vehicleIds[0] || 0);
        }
        if (vehicleIds.length === 0 || primaryVehicleId <= 0) {
          return;
        }

        var brandName = String(item.brand || '').trim();
        var modelName = String(item.model || '').trim();
        var variantName = String(item.variant || '').trim();
        var variantLabel = String(item.label || '').trim();
        if (variantLabel === '') {
          variantLabel = [brandName, modelName, variantName].join(' ').trim();
        }
        if (variantLabel === '') {
          variantLabel = 'Variant';
        }

        state.variantsByKey[variantKey] = {
          variant_key: variantKey,
          vis_brand_id: Number(item.vis_brand_id || 0),
          vis_model_id: Number(item.vis_model_id || 0),
          vis_variant_id: Number(item.vis_variant_id || item.id || 0),
          brand: brandName,
          model: modelName,
          variant: variantName,
          label: variantLabel,
          primary_vehicle_id: primaryVehicleId,
          vehicle_ids: vehicleIds
        };

        var checked = selectedMap[variantKey] ? ' checked' : '';
        html += '<tr>';
        html += '<td><input type="checkbox" data-variant-key="' + escapeHtml(variantKey) + '"' + checked + '></td>';
        html += '<td><strong>' + escapeHtml(variantLabel) + '</strong>';
        html += '</td>';
        html += '</tr>';
      });

      if (html === '') {
        html = '<tr><td colspan="2" class="text-center text-muted py-4">No vehicle variants found for current filters.</td></tr>';
      }
      vehicleRows.innerHTML = html;
      normalizeVariantSelection();
    }

    function loadVehicles() {
      showAlert('', '');
      if (vehicleRows) {
        vehicleRows.innerHTML = '<tr><td colspan="2" class="text-center text-muted py-4">Loading...</td></tr>';
      }
      var selectedVariantId = parseComboVariantId(comboFilter ? comboFilter.value : '');
      var query = encodeQuery({
        action: 'vehicles',
        variant_id: selectedVariantId > 0 ? selectedVariantId : '',
        limit: 300
      });
      getJson(apiUrl + '?' + query)
        .then(function (response) {
          if (!response || !response.ok) {
            showAlert('danger', response && response.message ? response.message : 'Unable to load vehicle variants.');
            renderVehicleRows([]);
            return;
          }
          renderVehicleRows(response.items || []);
        })
        .catch(function () {
          showAlert('danger', 'Unable to load vehicle variants.');
          renderVehicleRows([]);
        });
    }

    function renderRules(rules) {
      if (!ruleRows) {
        return;
      }
      state.rules = Array.isArray(rules) ? rules : [];
      if (state.rules.length === 0) {
        ruleRows.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No reminder-enabled items available.</td></tr>';
        return;
      }

      var html = '';
      state.rules.forEach(function (rule, index) {
        var itemType = rule.item_type || '';
        var itemId = Number(rule.item_id || 0);
        if (!itemType || !itemId) {
          return;
        }
        var active = !!rule.is_active;
        var intervalKm = rule.interval_km ? String(rule.interval_km) : '';
        var intervalDays = rule.interval_days ? String(rule.interval_days) : '';
        var itemName = rule.item_name || (itemType + ' #' + itemId);
        html += '<tr data-rule-index="' + index + '" data-item-type="' + itemType + '" data-item-id="' + itemId + '">';
        html += '<td>' + escapeHtml(itemName) + '</td>';
        html += '<td>' + escapeHtml(itemType) + '</td>';
        html += '<td><input type="number" min="0" step="1" class="form-control form-control-sm" data-rule-field="interval_km" value="' + escapeHtml(intervalKm) + '"' + (canManage ? '' : ' disabled') + '></td>';
        html += '<td><input type="number" min="0" step="1" class="form-control form-control-sm" data-rule-field="interval_days" value="' + escapeHtml(intervalDays) + '"' + (canManage ? '' : ' disabled') + '></td>';
        html += '<td class="text-center"><input type="checkbox" data-rule-field="is_active"' + (active ? ' checked' : '') + (canManage ? '' : ' disabled') + '></td>';
        html += '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" data-rule-delete="1"' + (canManage ? '' : ' disabled') + '>Delete</button></td>';
        html += '</tr>';
      });
      ruleRows.innerHTML = html !== '' ? html : '<tr><td colspan="6" class="text-center text-muted py-4">No reminder-enabled items available.</td></tr>';
    }

    function loadRulesForSelection() {
      var primaryVariantKey = selectedPrimaryVariantKey();
      var primaryVehicleId = selectedPrimaryVehicleId();
      if (primaryVehicleId <= 0) {
        showAlert('warning', 'Select at least one vehicle variant first.');
        return;
      }

      showAlert('', '');
      var query = encodeQuery({ action: 'rules', vehicle_id: primaryVehicleId });
      getJson(apiUrl + '?' + query)
        .then(function (response) {
          if (!response || !response.ok) {
            showAlert('danger', response && response.message ? response.message : 'Unable to load rules.');
            return;
          }
          state.primaryVehicleId = primaryVehicleId;
          state.primaryVariantKey = primaryVariantKey;
          if (workspace) {
            workspace.classList.remove('d-none');
          }
          if (primaryLabel) {
            var primaryVariant = state.variantsByKey[primaryVariantKey] || null;
            primaryLabel.textContent = primaryVariant
              ? ('Primary variant: ' + (primaryVariant.label || ''))
              : 'Primary variant';
          }
          renderRules(response.rules || []);
          if (copyPrompt) {
            if (response.has_existing_rules) {
              copyPrompt.classList.add('d-none');
            } else {
              copyPrompt.classList.remove('d-none');
              loadCopySourceCandidates('');
            }
          }
        })
        .catch(function () {
          showAlert('danger', 'Unable to load rules.');
        });
    }

    function collectRuleRows() {
      if (!ruleRows) {
        return [];
      }
      var rows = [];
      var nodes = ruleRows.querySelectorAll('tr[data-item-type][data-item-id]');
      nodes.forEach(function (node) {
        var itemType = (node.getAttribute('data-item-type') || '').trim();
        var itemId = Number(node.getAttribute('data-item-id') || 0);
        if (!itemType || itemId <= 0) {
          return;
        }
        var intervalKmInput = node.querySelector('input[data-rule-field="interval_km"]');
        var intervalDaysInput = node.querySelector('input[data-rule-field="interval_days"]');
        var activeInput = node.querySelector('input[data-rule-field="is_active"]');

        var intervalKm = intervalKmInput ? Number(intervalKmInput.value || 0) : 0;
        var intervalDays = intervalDaysInput ? Number(intervalDaysInput.value || 0) : 0;
        rows.push({
          item_type: itemType,
          item_id: itemId,
          interval_km: intervalKm > 0 ? intervalKm : null,
          interval_days: intervalDays > 0 ? intervalDays : null,
          is_active: !!(activeInput && activeInput.checked)
        });
      });
      return rows;
    }

    function saveRules() {
      if (!canManage) {
        showAlert('warning', 'You have view-only access.');
        return;
      }
      var vehicleIds = selectedVehicleIds();
      if (vehicleIds.length === 0) {
        showAlert('warning', 'Select at least one vehicle variant.');
        return;
      }
      var rows = collectRuleRows();
      if (rows.length === 0) {
        showAlert('warning', 'No rule rows found to save.');
        return;
      }
      postForm({
        action: 'save_rules',
        vehicle_ids: vehicleIds,
        rule_rows_json: JSON.stringify(rows)
      })
        .then(function (response) {
          if (!response || !response.ok) {
            showAlert('danger', response && response.message ? response.message : 'Unable to save rules.');
            return;
          }
          showAlert('success', response.message || 'Rules saved.');
          loadRulesForSelection();
        })
        .catch(function () {
          showAlert('danger', 'Unable to save rules.');
        });
    }

    function loadCopySourceCandidates(searchText) {
      if (!copySource) {
        return;
      }
      var query = encodeQuery({
        action: 'source_candidates',
        q: searchText || '',
        exclude_vehicle_id: state.primaryVehicleId,
        limit: 120
      });
      getJson(apiUrl + '?' + query)
        .then(function (response) {
          if (!response || !response.ok) {
            return;
          }
          var options = ['<option value="">Select source variant</option>'];
          (response.items || []).forEach(function (item) {
            var id = Number(item.primary_vehicle_id || 0);
            if (id <= 0) {
              return;
            }
            options.push('<option value="' + id + '">' + escapeHtml(item.label || ('Variant #' + id)) + '</option>');
          });
          copySource.innerHTML = options.join('');
        })
        .catch(function () {
          copySource.innerHTML = '<option value="">Select source variant</option>';
        });
    }

    function copyRules() {
      if (!canManage) {
        showAlert('warning', 'You have view-only access.');
        return;
      }
      var sourceVehicleId = Number(copySource ? copySource.value : 0);
      var targetVehicleIds = selectedVehicleIds();
      if (sourceVehicleId <= 0) {
        showAlert('warning', 'Select source variant to copy rules.');
        return;
      }
      if (targetVehicleIds.length === 0) {
        showAlert('warning', 'Select target variants.');
        return;
      }

      postForm({
        action: 'copy_rules',
        source_vehicle_id: sourceVehicleId,
        target_vehicle_ids: targetVehicleIds
      })
        .then(function (response) {
          if (!response || !response.ok) {
            showAlert('danger', response && response.message ? response.message : 'Unable to copy rules.');
            return;
          }
          showAlert('success', response.message || 'Rules copied.');
          loadRulesForSelection();
        })
        .catch(function () {
          showAlert('danger', 'Unable to copy rules.');
        });
    }

    function deleteRule(itemType, itemId) {
      if (!canManage) {
        showAlert('warning', 'You have view-only access.');
        return;
      }
      var targetVehicleIds = selectedVehicleIds();
      if (targetVehicleIds.length === 0) {
        showAlert('warning', 'Select target variants.');
        return;
      }
      postForm({
        action: 'delete_rule',
        vehicle_ids: targetVehicleIds,
        item_type: itemType,
        item_id: itemId
      })
        .then(function (response) {
          if (!response || !response.ok) {
            showAlert('danger', response && response.message ? response.message : 'Unable to delete rule.');
            return;
          }
          showAlert('success', response.message || 'Rule deleted.');
          loadRulesForSelection();
        })
        .catch(function () {
          showAlert('danger', 'Unable to delete rule.');
        });
    }

    if (vehicleRows) {
      vehicleRows.addEventListener('change', function (event) {
        if (!event.target || !event.target.matches('input[data-variant-key]')) {
          return;
        }
        normalizeVariantSelection();
      });
    }

    if (selectAllNode) {
      selectAllNode.addEventListener('change', function () {
        if (!vehicleRows) {
          return;
        }
        var checked = !!selectAllNode.checked;
        var checkboxes = vehicleRows.querySelectorAll('input[data-variant-key]');
        checkboxes.forEach(function (node) {
          node.checked = checked;
        });
        normalizeVariantSelection();
      });
    }

    if (comboFilter) {
      if (typeof gacRefreshSearchableSelect === 'function') {
        gacRefreshSearchableSelect(comboFilter);
      }
      comboFilter.addEventListener('change', function () {
        loadVehicles();
      });
    }

    if (resetFiltersBtn) {
      resetFiltersBtn.addEventListener('click', function () {
        if (comboFilter) {
          comboFilter.value = '';
          if (typeof gacRefreshSearchableSelect === 'function') {
            gacRefreshSearchableSelect(comboFilter);
          }
        }
        loadVehicles();
      });
    }

    if (loadRulesBtn) {
      loadRulesBtn.addEventListener('click', loadRulesForSelection);
    }

    if (saveRulesBtn) {
      saveRulesBtn.addEventListener('click', saveRules);
    }

    if (copyBtn) {
      copyBtn.addEventListener('click', copyRules);
    }

    if (copySearch) {
      var copySearchDebounce = null;
      copySearch.addEventListener('input', function () {
        if (copySearchDebounce) {
          clearTimeout(copySearchDebounce);
        }
        copySearchDebounce = setTimeout(function () {
          loadCopySourceCandidates(copySearch.value || '');
        }, 200);
      });
    }

    if (ruleRows) {
      ruleRows.addEventListener('click', function (event) {
        var button = event.target && event.target.closest('button[data-rule-delete]');
        if (!button) {
          return;
        }
        var row = button.closest('tr[data-item-type][data-item-id]');
        if (!row) {
          return;
        }
        var itemType = (row.getAttribute('data-item-type') || '').trim();
        var itemId = Number(row.getAttribute('data-item-id') || 0);
        if (!itemType || itemId <= 0) {
          return;
        }
        if (!window.confirm('Delete this rule for all selected variants?')) {
          return;
        }
        deleteRule(itemType, itemId);
      });
    }

    loadComboOptions('', false).then(function () {
      loadVehicles();
    });
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
