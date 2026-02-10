document.addEventListener('DOMContentLoaded', function () {
  bindConfirmForms();
  initSearchableSelects();
  initVehicleAttributeSelectors();
  initMasterInsightsFilters();
});

function bindConfirmForms() {
  var dangerousForms = document.querySelectorAll('form[data-confirm]');
  for (var i = 0; i < dangerousForms.length; i++) {
    if (dangerousForms[i].getAttribute('data-confirm-bound') === '1') {
      continue;
    }
    dangerousForms[i].setAttribute('data-confirm-bound', '1');
    dangerousForms[i].addEventListener('submit', function (event) {
      var message = this.getAttribute('data-confirm') || 'Are you sure?';
      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  }
}

function initSearchableSelects() {
  var searchableNames = {
    customer_id: true,
    vehicle_id: true,
    part_id: true,
    service_id: true,
    vendor_id: true,
    'assigned_user_ids[]': true,
    user_id: true,
    staff_id: true,
    job_card_id: true,
    invoice_id: true,
    brand_id: true,
    model_id: true,
    variant_id: true,
    model_year_id: true,
    color_id: true,
    vehicle_filter_customer_id: true,
    vehicle_filter_brand_id: true,
    vehicle_filter_model_id: true,
    vehicle_filter_variant_id: true,
    vehicle_filter_model_year_id: true,
    vehicle_filter_color_id: true,
    report_brand_id: true,
    report_model_id: true,
    report_variant_id: true,
    report_model_year_id: true,
    report_color_id: true,
    job_vehicle_brand_id: true,
    job_vehicle_model_id: true,
    job_vehicle_variant_id: true,
    job_vehicle_model_year_id: true,
    job_vehicle_color_id: true
  };
  var inlineAddValue = '__inline_add_customer__';
  var inlineCustomerFormUrl = document.body
    ? (document.body.getAttribute('data-inline-customer-form-url') || '')
    : '';

  var modalElement = document.getElementById('inline-customer-modal');
  var modalBody = document.getElementById('inline-customer-modal-body');
  var modalInstance = null;
  var activeInlineCustomerComponent = null;

  if (window.bootstrap && window.bootstrap.Modal && modalElement) {
    modalInstance = window.bootstrap.Modal.getOrCreateInstance(modalElement);
    modalElement.addEventListener('hidden.bs.modal', function () {
      if (modalBody) {
        modalBody.innerHTML = '<div class="text-muted">Loading customer form...</div>';
      }
      activeInlineCustomerComponent = null;
    });
  }

  if (modalBody) {
    modalBody.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form || form.tagName !== 'FORM') {
        return;
      }
      if (form.getAttribute('data-inline-customer-form') !== '1') {
        return;
      }

      event.preventDefault();
      clearInlineFormError(form);

      var submitButton = form.querySelector('button[type="submit"]');
      if (submitButton) {
        submitButton.disabled = true;
      }

      var actionUrl = form.getAttribute('action') || '';
      if (actionUrl === '') {
        renderInlineFormError(form, 'Customer create URL is missing.');
        if (submitButton) {
          submitButton.disabled = false;
        }
        return;
      }

      fetch(actionUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: new FormData(form),
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
            return { ok: response.ok, payload: payload };
          });
        })
        .then(function (result) {
          var payload = result.payload || {};
          if (!result.ok || !payload.ok) {
            renderInlineFormError(
              form,
              payload.message || 'Unable to create customer. Please check inputs and retry.'
            );
            return;
          }

          if (!activeInlineCustomerComponent) {
            return;
          }

          upsertCustomerOption(activeInlineCustomerComponent, payload.customer || {});
          if (modalInstance) {
            modalInstance.hide();
          }
        })
        .catch(function () {
          renderInlineFormError(form, 'Network error while creating customer.');
        })
        .then(function () {
          if (submitButton) {
            submitButton.disabled = false;
          }
        });
    });
  }

  var allSelects = document.querySelectorAll('select');
  for (var index = 0; index < allSelects.length; index++) {
    var select = allSelects[index];
    if (!shouldEnhanceSelect(select)) {
      continue;
    }

    var selectName = (select.getAttribute('name') || '').trim();
    if (!searchableNames[selectName]) {
      continue;
    }

    enhanceSelect(select);
  }

  function shouldEnhanceSelect(select) {
    if (!select || select.getAttribute('data-searchable-enhanced') === '1') {
      return false;
    }

    if (select.getAttribute('data-searchable-select') === 'off') {
      return false;
    }

    return true;
  }

  function enhanceSelect(select) {
    var selectName = (select.getAttribute('name') || '').trim();
    var isCustomerSelect = selectName === 'customer_id';
    var wrapper = document.createElement('div');
    wrapper.className = 'gac-searchable-select';

    var searchInput = document.createElement('input');
    searchInput.type = 'search';
    searchInput.className = 'form-control form-control-sm gac-search-input';
    searchInput.placeholder = 'Search...';
    searchInput.autocomplete = 'off';
    searchInput.setAttribute('aria-label', 'Search dropdown options');
    if (select.disabled) {
      searchInput.disabled = true;
    }

    var hint = document.createElement('div');
    hint.className = 'gac-search-hint';

    select.parentNode.insertBefore(wrapper, select);
    wrapper.appendChild(searchInput);
    wrapper.appendChild(select);
    wrapper.appendChild(hint);
    select.setAttribute('data-searchable-enhanced', '1');

    var state = {
      select: select,
      input: searchInput,
      hint: hint,
      isCustomerSelect: isCustomerSelect,
      inlineOption: null,
      indexedOptions: [],
      lastCommittedValue: select.value || ''
    };

    if (isCustomerSelect && !select.disabled) {
      state.inlineOption = ensureInlineCustomerOption(select, inlineAddValue);
    }

    refreshIndexedOptions(state);
    applySearchFilter(state, '');

    var debouncedFilter = debounce(function () {
      applySearchFilter(state, state.input.value || '');
    }, 120);

    searchInput.addEventListener('input', debouncedFilter);
    searchInput.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        select.focus();
        return;
      }

      if (event.key === 'Escape') {
        if (searchInput.value !== '') {
          event.preventDefault();
          searchInput.value = '';
          applySearchFilter(state, '');
        }
        return;
      }

      if (event.key !== 'Enter') {
        return;
      }

      event.preventDefault();
      if (state.isCustomerSelect && state.inlineOption && !state.inlineOption.hidden) {
        openInlineCustomerModal(state, (searchInput.value || '').trim());
        return;
      }

      var firstVisible = firstVisibleOption(state);
      if (!firstVisible) {
        return;
      }

      select.value = firstVisible.value;
      state.lastCommittedValue = firstVisible.value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
    });

    select.addEventListener('change', function () {
      if (state.inlineOption && select.value === inlineAddValue) {
        select.value = state.lastCommittedValue;
        openInlineCustomerModal(state, (searchInput.value || '').trim());
        return;
      }

      state.lastCommittedValue = select.value || '';
    });

    select.gacSearchableRefresh = function () {
      refreshIndexedOptions(state);
      applySearchFilter(state, state.input.value || '');
    };
  }

  function ensureInlineCustomerOption(select, value) {
    for (var i = 0; i < select.options.length; i++) {
      if (select.options[i].value === value) {
        select.options[i].hidden = true;
        select.options[i].setAttribute('data-inline-customer-option', '1');
        return select.options[i];
      }
    }

    var option = document.createElement('option');
    option.value = value;
    option.hidden = true;
    option.setAttribute('data-inline-customer-option', '1');
    option.textContent = '+ Add New Customer';
    select.appendChild(option);
    return option;
  }

  function refreshIndexedOptions(state) {
    state.indexedOptions = [];
    for (var i = 0; i < state.select.options.length; i++) {
      var option = state.select.options[i];
      state.indexedOptions.push({
        option: option,
        normalizedText: normalizeSearchTerm(option.textContent || '')
      });
    }
  }

  function applySearchFilter(state, rawQuery) {
    var normalizedQuery = normalizeSearchTerm(rawQuery || '');
    var visibleMatches = 0;

    for (var i = 0; i < state.indexedOptions.length; i++) {
      var entry = state.indexedOptions[i];
      var option = entry.option;
      var isInlineCustomerOption = option.getAttribute('data-inline-customer-option') === '1';
      if (isInlineCustomerOption) {
        continue;
      }

      var isPlaceholder = option.value === '';
      var termMatch = normalizedQuery === '' || entry.normalizedText.indexOf(normalizedQuery) !== -1;
      var keepVisible = termMatch || option.selected;

      if (normalizedQuery !== '' && isPlaceholder) {
        keepVisible = false;
      }

      if (option.hidden === keepVisible) {
        option.hidden = !keepVisible;
      }

      if (!isPlaceholder && termMatch) {
        visibleMatches++;
      }
    }

    if (state.isCustomerSelect && state.inlineOption) {
      var shouldShowInlineOption = normalizedQuery !== '' && visibleMatches === 0;
      state.inlineOption.hidden = !shouldShowInlineOption;
      if (shouldShowInlineOption) {
        var compactQuery = collapseWhitespace(rawQuery || '').trim();
        if (compactQuery.length > 42) {
          compactQuery = compactQuery.slice(0, 42) + '...';
        }
        state.inlineOption.textContent = compactQuery !== ''
          ? '+ Add New Customer "' + compactQuery + '"'
          : '+ Add New Customer';
        state.hint.textContent = 'No exact match found. Choose + Add New Customer.';
      } else {
        state.hint.textContent = '';
      }
    } else {
      state.hint.textContent = '';
    }
  }

  function firstVisibleOption(state) {
    for (var i = 0; i < state.select.options.length; i++) {
      var option = state.select.options[i];
      if (option.hidden) {
        continue;
      }
      if (option.value === '' || option.getAttribute('data-inline-customer-option') === '1') {
        continue;
      }
      return option;
    }
    return null;
  }

  function openInlineCustomerModal(component, query) {
    if (!modalInstance || !modalBody || !inlineCustomerFormUrl) {
      return;
    }

    activeInlineCustomerComponent = component;
    modalBody.innerHTML = '<div class="text-muted">Loading customer form...</div>';
    modalInstance.show();

    var requestUrl = inlineCustomerFormUrl;
    var trimmedQuery = (query || '').trim();
    if (trimmedQuery !== '') {
      requestUrl += (requestUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(trimmedQuery);
    }

    fetch(requestUrl, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (response) {
        return response.text().then(function (html) {
          return { html: html };
        });
      })
      .then(function (result) {
        modalBody.innerHTML = result.html || '<div class="alert alert-danger mb-0">Unable to load customer form.</div>';

        var nameInput = modalBody.querySelector('input[name="full_name"]');
        if (nameInput) {
          nameInput.focus();
          if ((nameInput.value || '').trim() !== '') {
            nameInput.select();
          }
        }
      })
      .catch(function () {
        modalBody.innerHTML = '<div class="alert alert-danger mb-0">Unable to load customer form.</div>';
      });
  }

  function upsertCustomerOption(component, customer) {
    var idValue = String(customer.id || '').trim();
    if (idValue === '' || idValue === '0') {
      return;
    }

    var label = String(customer.label || '').trim();
    if (label === '') {
      var fullName = String(customer.full_name || '').trim();
      var phone = String(customer.phone || '').trim();
      label = phone !== '' ? (fullName + ' (' + phone + ')') : fullName;
    }

    var option = null;
    for (var i = 0; i < component.select.options.length; i++) {
      if (component.select.options[i].value === idValue) {
        option = component.select.options[i];
        break;
      }
    }

    if (!option) {
      option = document.createElement('option');
      option.value = idValue;
      component.select.appendChild(option);
    }

    option.textContent = label;
    option.hidden = false;

    component.select.value = idValue;
    component.lastCommittedValue = idValue;
    refreshIndexedOptions(component);
    component.input.value = '';
    applySearchFilter(component, '');

    component.select.dispatchEvent(new Event('change', { bubbles: true }));
    component.select.focus();
  }
}

function initVehicleAttributeSelectors() {
  var roots = document.querySelectorAll('[data-vehicle-attributes-root="1"]');
  if (!roots || roots.length === 0) {
    return;
  }

  var customValue = '__custom__';

  for (var i = 0; i < roots.length; i++) {
    initVehicleAttributeRoot(roots[i]);
  }

  function initVehicleAttributeRoot(root) {
    var endpoint = (root.getAttribute('data-vehicle-attributes-endpoint') || '').trim();
    if (endpoint === '') {
      return;
    }

    var mode = (root.getAttribute('data-vehicle-attributes-mode') || 'entry').trim().toLowerCase();
    var brandSelect = root.querySelector('select[data-vehicle-attr="brand"]');
    var modelSelect = root.querySelector('select[data-vehicle-attr="model"]');
    var variantSelect = root.querySelector('select[data-vehicle-attr="variant"]');
    var yearSelect = root.querySelector('select[data-vehicle-attr="model_year"]');
    var colorSelect = root.querySelector('select[data-vehicle-attr="color"]');

    var fallbackInputs = {
      brand: root.querySelector('input[data-vehicle-fallback="brand"]'),
      model: root.querySelector('input[data-vehicle-fallback="model"]'),
      variant: root.querySelector('input[data-vehicle-fallback="variant"]'),
      model_year: root.querySelector('input[data-vehicle-fallback="model_year"]'),
      color: root.querySelector('input[data-vehicle-fallback="color"]')
    };
    var fallbackWraps = {
      brand: root.querySelector('[data-vehicle-fallback-wrap="brand"]'),
      model: root.querySelector('[data-vehicle-fallback-wrap="model"]'),
      variant: root.querySelector('[data-vehicle-fallback-wrap="variant"]'),
      model_year: root.querySelector('[data-vehicle-fallback-wrap="model_year"]'),
      color: root.querySelector('[data-vehicle-fallback-wrap="color"]')
    };

    var pickerTargetSelector = (root.getAttribute('data-vehicle-picker-target') || '').trim();
    var customerSelectSelector = (root.getAttribute('data-vehicle-customer-select') || '').trim();
    var vehiclePicker = pickerTargetSelector !== '' ? document.querySelector(pickerTargetSelector) : null;
    var customerSelect = customerSelectSelector !== '' ? document.querySelector(customerSelectSelector) : null;

    function selectedIdFromData(select) {
      if (!select) {
        return '';
      }
      var raw = (select.getAttribute('data-selected-id') || '').trim();
      return raw !== '' ? raw : (select.value || '');
    }

    var state = {
      mode: mode,
      brandSelectedId: selectedIdFromData(brandSelect),
      modelSelectedId: selectedIdFromData(modelSelect),
      variantSelectedId: selectedIdFromData(variantSelect),
      yearSelectedId: selectedIdFromData(yearSelect),
      colorSelectedId: selectedIdFromData(colorSelect)
    };

    function hasFallback(attrKey) {
      return !!fallbackInputs[attrKey];
    }

    function optionLabel(item, fieldName, fallback) {
      var value = item && Object.prototype.hasOwnProperty.call(item, fieldName) ? item[fieldName] : null;
      if (value === null || value === undefined || String(value).trim() === '') {
        return fallback || '';
      }
      return String(value);
    }

    function appendPlaceholder(select, text) {
      if (!select) {
        return;
      }
      var option = document.createElement('option');
      option.value = '';
      option.textContent = text;
      select.appendChild(option);
    }

    function setSelectOptions(select, items, config) {
      if (!select) {
        return;
      }

      var selectedValue = config && config.selectedValue !== undefined ? String(config.selectedValue || '') : '';
      var placeholder = (config && config.placeholder) ? String(config.placeholder) : 'Select';
      var includeCustomOption = !!(config && config.includeCustomOption);
      var customLabel = (config && config.customLabel) ? String(config.customLabel) : 'Not listed (type manually)';
      var valueField = (config && config.valueField) ? String(config.valueField) : 'id';
      var labelField = (config && config.labelField) ? String(config.labelField) : 'name';

      select.innerHTML = '';
      appendPlaceholder(select, placeholder);

      for (var idx = 0; idx < items.length; idx++) {
        var item = items[idx] || {};
        var value = item[valueField];
        if (value === null || value === undefined || String(value).trim() === '') {
          continue;
        }

        var option = document.createElement('option');
        option.value = String(value);
        option.textContent = optionLabel(item, labelField, String(value));
        select.appendChild(option);
      }

      if (includeCustomOption) {
        var customOption = document.createElement('option');
        customOption.value = customValue;
        customOption.textContent = customLabel;
        select.appendChild(customOption);
      }

      if (selectedValue !== '') {
        select.value = selectedValue;
      } else {
        select.value = '';
      }

      if (select.value === '' && includeCustomOption && selectedValue === customValue) {
        select.value = customValue;
      }

      if (select.value === '' && selectedValue !== '' && includeCustomOption) {
        select.value = customValue;
      }

      gacRefreshSearchableSelect(select);
    }

    function setLoading(select, placeholder) {
      if (!select) {
        return;
      }
      setSelectOptions(select, [], {
        placeholder: placeholder,
        includeCustomOption: false
      });
    }

    function request(action, extraParams) {
      var params = new URLSearchParams();
      params.set('action', action);
      params.set('limit', '400');
      if (extraParams) {
        var keys = Object.keys(extraParams);
        for (var k = 0; k < keys.length; k++) {
          var key = keys[k];
          var value = extraParams[key];
          if (value === null || value === undefined || String(value).trim() === '') {
            continue;
          }
          params.set(key, String(value));
        }
      }

      return fetch(endpoint + '?' + params.toString(), {
        credentials: 'same-origin'
      }).then(function (response) {
        return response.json().catch(function () {
          return { ok: false, items: [] };
        });
      });
    }

    function syncFallback(attrKey, required) {
      if (mode !== 'entry' || !hasFallback(attrKey)) {
        return;
      }

      var select = null;
      if (attrKey === 'brand') {
        select = brandSelect;
      } else if (attrKey === 'model') {
        select = modelSelect;
      } else if (attrKey === 'variant') {
        select = variantSelect;
      } else if (attrKey === 'model_year') {
        select = yearSelect;
      } else if (attrKey === 'color') {
        select = colorSelect;
      }

      if (!select) {
        return;
      }

      var input = fallbackInputs[attrKey];
      var wrap = fallbackWraps[attrKey];
      var useFallback = select.value === customValue;
      if (wrap) {
        wrap.style.display = useFallback ? '' : 'none';
      }
      input.disabled = !useFallback;
      input.required = !!required && useFallback;
    }

    function loadModels() {
      if (!modelSelect) {
        return Promise.resolve();
      }

      var brandId = parseInt(brandSelect ? brandSelect.value : '0', 10) || 0;
      state.modelSelectedId = state.modelSelectedId || '';
      state.variantSelectedId = state.variantSelectedId || '';

      if (brandId <= 0 || (mode === 'entry' && brandSelect && brandSelect.value === customValue)) {
        setSelectOptions(modelSelect, [], {
          placeholder: 'Select Model',
          includeCustomOption: mode === 'entry' && hasFallback('model'),
          selectedValue: mode === 'entry' && hasFallback('model') ? customValue : ''
        });
        if (variantSelect) {
          setSelectOptions(variantSelect, [], {
            placeholder: 'Select Variant',
            includeCustomOption: mode === 'entry' && hasFallback('variant')
          });
        }
        syncFallback('model', true);
        syncFallback('variant', false);
        return Promise.resolve();
      }

      setLoading(modelSelect, 'Loading Models...');
      return request('models', { brand_id: brandId }).then(function (payload) {
        var items = payload && payload.ok && Array.isArray(payload.items) ? payload.items : [];
        setSelectOptions(modelSelect, items, {
          placeholder: mode === 'filter' ? 'All Models' : 'Select Model',
          includeCustomOption: mode === 'entry' && hasFallback('model'),
          selectedValue: state.modelSelectedId,
          labelField: 'name'
        });
        syncFallback('model', true);
      }).catch(function () {
        setSelectOptions(modelSelect, [], {
          placeholder: mode === 'filter' ? 'All Models' : 'Select Model',
          includeCustomOption: mode === 'entry' && hasFallback('model')
        });
      }).then(function () {
        state.modelSelectedId = modelSelect.value || '';
        return loadVariants();
      });
    }

    function loadVariants() {
      if (!variantSelect) {
        return Promise.resolve();
      }

      var modelId = parseInt(modelSelect ? modelSelect.value : '0', 10) || 0;
      if (modelId <= 0 || (mode === 'entry' && modelSelect && modelSelect.value === customValue)) {
        setSelectOptions(variantSelect, [], {
          placeholder: mode === 'filter' ? 'All Variants' : 'Select Variant (Optional)',
          includeCustomOption: mode === 'entry' && hasFallback('variant')
        });
        syncFallback('variant', false);
        return Promise.resolve();
      }

      setLoading(variantSelect, 'Loading Variants...');
      return request('variants', { model_id: modelId }).then(function (payload) {
        var items = payload && payload.ok && Array.isArray(payload.items) ? payload.items : [];
        setSelectOptions(variantSelect, items, {
          placeholder: mode === 'filter' ? 'All Variants' : 'Select Variant (Optional)',
          includeCustomOption: mode === 'entry' && hasFallback('variant'),
          selectedValue: state.variantSelectedId,
          labelField: 'name'
        });
        syncFallback('variant', false);
      }).catch(function () {
        setSelectOptions(variantSelect, [], {
          placeholder: mode === 'filter' ? 'All Variants' : 'Select Variant (Optional)',
          includeCustomOption: mode === 'entry' && hasFallback('variant')
        });
      }).then(function () {
        state.variantSelectedId = variantSelect.value || '';
      });
    }

    function loadBrands() {
      if (!brandSelect) {
        return Promise.resolve();
      }

      setLoading(brandSelect, 'Loading Brands...');
      return request('brands').then(function (payload) {
        var items = payload && payload.ok && Array.isArray(payload.items) ? payload.items : [];
        setSelectOptions(brandSelect, items, {
          placeholder: mode === 'filter' ? 'All Brands' : 'Select Brand',
          includeCustomOption: mode === 'entry' && hasFallback('brand'),
          selectedValue: state.brandSelectedId,
          labelField: 'name'
        });
        syncFallback('brand', true);
      }).catch(function () {
        setSelectOptions(brandSelect, [], {
          placeholder: mode === 'filter' ? 'All Brands' : 'Select Brand',
          includeCustomOption: mode === 'entry' && hasFallback('brand')
        });
      }).then(function () {
        state.brandSelectedId = brandSelect.value || '';
        return loadModels();
      });
    }

    function loadYears() {
      if (!yearSelect) {
        return Promise.resolve();
      }

      setLoading(yearSelect, 'Loading Years...');
      return request('years').then(function (payload) {
        var items = payload && payload.ok && Array.isArray(payload.items) ? payload.items : [];
        setSelectOptions(yearSelect, items, {
          placeholder: mode === 'filter' ? 'All Years' : 'Select Year (Optional)',
          includeCustomOption: mode === 'entry' && hasFallback('model_year'),
          selectedValue: state.yearSelectedId,
          labelField: 'year_value'
        });
        syncFallback('model_year', false);
      }).catch(function () {
        setSelectOptions(yearSelect, [], {
          placeholder: mode === 'filter' ? 'All Years' : 'Select Year (Optional)',
          includeCustomOption: mode === 'entry' && hasFallback('model_year')
        });
      }).then(function () {
        state.yearSelectedId = yearSelect.value || '';
      });
    }

    function loadColors() {
      if (!colorSelect) {
        return Promise.resolve();
      }

      setLoading(colorSelect, 'Loading Colors...');
      return request('colors').then(function (payload) {
        var items = payload && payload.ok && Array.isArray(payload.items) ? payload.items : [];
        setSelectOptions(colorSelect, items, {
          placeholder: mode === 'filter' ? 'All Colors' : 'Select Color (Optional)',
          includeCustomOption: mode === 'entry' && hasFallback('color'),
          selectedValue: state.colorSelectedId,
          labelField: 'name'
        });
        syncFallback('color', false);
      }).catch(function () {
        setSelectOptions(colorSelect, [], {
          placeholder: mode === 'filter' ? 'All Colors' : 'Select Color (Optional)',
          includeCustomOption: mode === 'entry' && hasFallback('color')
        });
      }).then(function () {
        state.colorSelectedId = colorSelect.value || '';
      });
    }

    function reloadVehiclePicker() {
      if (!vehiclePicker) {
        return;
      }

      if (vehiclePicker.disabled) {
        return;
      }

      var params = {
        customer_id: customerSelect ? customerSelect.value : '',
        brand_id: brandSelect ? brandSelect.value : '',
        model_id: modelSelect ? modelSelect.value : '',
        variant_id: variantSelect ? variantSelect.value : '',
        model_year_id: yearSelect ? yearSelect.value : '',
        color_id: colorSelect ? colorSelect.value : ''
      };

      if (params.brand_id === customValue || params.model_id === customValue || params.variant_id === customValue || params.model_year_id === customValue || params.color_id === customValue) {
        return;
      }

      request('vehicles', params).then(function (payload) {
        var items = payload && payload.ok && Array.isArray(payload.items) ? payload.items : [];
        var previousValue = vehiclePicker.value || '';
        vehiclePicker.innerHTML = '';

        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select Vehicle';
        vehiclePicker.appendChild(placeholder);

        for (var idx = 0; idx < items.length; idx++) {
          var item = items[idx];
          if (!item || !item.id) {
            continue;
          }
          var option = document.createElement('option');
          option.value = String(item.id);
          option.textContent = String(item.label || item.registration_no || item.id);
          vehiclePicker.appendChild(option);
        }

        if (previousValue !== '') {
          vehiclePicker.value = previousValue;
        }
        if (vehiclePicker.value === '' && previousValue !== '') {
          vehiclePicker.selectedIndex = 0;
        }

        gacRefreshSearchableSelect(vehiclePicker);
        vehiclePicker.dispatchEvent(new Event('change', { bubbles: true }));
      }).catch(function () {
        // Keep existing options on API errors.
      });
    }

    if (brandSelect) {
      brandSelect.addEventListener('change', function () {
        state.brandSelectedId = brandSelect.value || '';
        state.modelSelectedId = '';
        state.variantSelectedId = '';
        syncFallback('brand', true);
        loadModels().then(function () {
          reloadVehiclePicker();
        });
      });
    }

    if (modelSelect) {
      modelSelect.addEventListener('change', function () {
        state.modelSelectedId = modelSelect.value || '';
        state.variantSelectedId = '';
        syncFallback('model', true);
        loadVariants().then(function () {
          reloadVehiclePicker();
        });
      });
    }

    if (variantSelect) {
      variantSelect.addEventListener('change', function () {
        state.variantSelectedId = variantSelect.value || '';
        syncFallback('variant', false);
        reloadVehiclePicker();
      });
    }

    if (yearSelect) {
      yearSelect.addEventListener('change', function () {
        state.yearSelectedId = yearSelect.value || '';
        syncFallback('model_year', false);
        reloadVehiclePicker();
      });
    }

    if (colorSelect) {
      colorSelect.addEventListener('change', function () {
        state.colorSelectedId = colorSelect.value || '';
        syncFallback('color', false);
        reloadVehiclePicker();
      });
    }

    if (customerSelect) {
      customerSelect.addEventListener('change', function () {
        reloadVehiclePicker();
      });
    }

    Promise.all([loadBrands(), loadYears(), loadColors()]).then(function () {
      syncFallback('brand', true);
      syncFallback('model', true);
      syncFallback('variant', false);
      syncFallback('model_year', false);
      syncFallback('color', false);
      reloadVehiclePicker();
      root.dispatchEvent(new CustomEvent('gac:vehicle-attributes-ready', { bubbles: true }));
    });
  }
}

function initMasterInsightsFilters() {
  var roots = document.querySelectorAll('[data-master-insights-root]');
  if (!roots || roots.length === 0) {
    return;
  }

  for (var i = 0; i < roots.length; i++) {
    initMasterInsightsRoot(roots[i]);
  }

  function initMasterInsightsRoot(root) {
    if (!root || root.getAttribute('data-master-insights-init') === '1') {
      return;
    }

    var endpoint = (root.getAttribute('data-master-insights-endpoint') || '').trim();
    var form = root.querySelector('form[data-master-filter-form="1"]');
    var tableBody = root.querySelector('[data-master-table-body="1"]');
    if (endpoint === '' || !form || !tableBody) {
      return;
    }

    root.setAttribute('data-master-insights-init', '1');

    var errorBox = root.querySelector('[data-master-insights-error="1"]');
    var resultCountNode = root.querySelector('[data-master-results-count="1"]');
    var statNodes = root.querySelectorAll('[data-stat-value]');
    var requestSequence = 0;
    var debouncedRefresh = debounce(function () {
      requestAndRender();
    }, 220);

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      requestAndRender();
    });

    form.addEventListener('change', function () {
      requestAndRender();
    });

    form.addEventListener('input', function (event) {
      var target = event.target;
      if (!target) {
        return;
      }
      var fieldName = (target.getAttribute('name') || '').trim();
      if (fieldName === '') {
        return;
      }
      var tagName = (target.tagName || '').toUpperCase();
      var inputType = (target.getAttribute('type') || '').toLowerCase();
      if (tagName === 'INPUT' && (inputType === 'search' || inputType === 'text' || inputType === 'date')) {
        debouncedRefresh();
      }
    });

    var resetButton = root.querySelector('[data-master-filter-reset="1"]');
    if (resetButton) {
      resetButton.addEventListener('click', function (event) {
        event.preventDefault();
        form.reset();
        requestAndRender();
      });
    }

    root.addEventListener('gac:vehicle-attributes-ready', function () {
      requestAndRender();
    });

    requestAndRender();

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
      errorBox.textContent = message || 'Unable to load filtered data.';
      errorBox.classList.remove('d-none');
    }

    function serializeForm(formElement) {
      var formData = new FormData(formElement);
      var params = new URLSearchParams();
      formData.forEach(function (value, key) {
        if (typeof value !== 'string') {
          return;
        }
        var normalized = value.trim();
        if (normalized === '') {
          return;
        }
        params.append(key, normalized);
      });
      return params;
    }

    function detectColumnCount() {
      var configured = parseInt(tableBody.getAttribute('data-table-colspan') || '0', 10);
      if (configured > 0) {
        return configured;
      }

      var table = tableBody;
      while (table && table.tagName !== 'TABLE') {
        table = table.parentElement;
      }
      if (!table) {
        return 1;
      }

      var headers = table.querySelectorAll('thead th');
      return headers && headers.length > 0 ? headers.length : 1;
    }

    function renderInfoRow(message, cssClass) {
      var colCount = detectColumnCount();
      var rowClass = cssClass || 'text-muted';
      tableBody.innerHTML = '<tr><td colspan="' + colCount + '" class="text-center ' + rowClass + ' py-4">' + message + '</td></tr>';
    }

    function updateStats(stats) {
      if (!stats || typeof stats !== 'object') {
        return;
      }
      for (var index = 0; index < statNodes.length; index++) {
        var node = statNodes[index];
        var statKey = (node.getAttribute('data-stat-value') || '').trim();
        if (statKey === '' || !Object.prototype.hasOwnProperty.call(stats, statKey)) {
          continue;
        }
        node.textContent = String(stats[statKey]);
      }
    }

    function requestAndRender() {
      var currentSequence = ++requestSequence;
      clearError();
      renderInfoRow('Loading filtered data...', 'text-muted');

      var params = serializeForm(form);
      var requestUrl = endpoint;
      if (params.toString() !== '') {
        requestUrl += (endpoint.indexOf('?') >= 0 ? '&' : '?') + params.toString();
      }

      fetch(requestUrl, {
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
          if (currentSequence !== requestSequence) {
            return;
          }

          var payload = result.payload || {};
          if (!result.ok || !payload.ok) {
            showError(payload.message || 'Unable to load filtered data.');
            renderInfoRow('Unable to load records.', 'text-danger');
            return;
          }

          if (typeof payload.table_rows_html === 'string' && payload.table_rows_html !== '') {
            tableBody.innerHTML = payload.table_rows_html;
          } else {
            renderInfoRow('No records found.', 'text-muted');
          }

          if (resultCountNode && Object.prototype.hasOwnProperty.call(payload, 'rows_count')) {
            resultCountNode.textContent = String(payload.rows_count);
          }

          updateStats(payload.stats || {});
          bindConfirmForms();
        })
        .catch(function () {
          if (currentSequence !== requestSequence) {
            return;
          }
          showError('Unable to load filtered data.');
          renderInfoRow('Unable to load records.', 'text-danger');
        });
    }
  }
}

function gacRefreshSearchableSelect(select) {
  if (!select) {
    return;
  }

  if (typeof select.gacSearchableRefresh === 'function') {
    select.gacSearchableRefresh();
  }
}

function normalizeSearchTerm(value) {
  return collapseWhitespace(String(value || ''))
    .toLowerCase()
    .trim();
}

function collapseWhitespace(value) {
  return String(value || '').replace(/\s+/g, ' ');
}

function debounce(callback, waitMs) {
  var timer = null;
  return function () {
    var context = this;
    var args = arguments;
    window.clearTimeout(timer);
    timer = window.setTimeout(function () {
      callback.apply(context, args);
    }, waitMs);
  };
}

function clearInlineFormError(form) {
  var errorNode = form.querySelector('[data-inline-customer-error="1"]');
  if (errorNode) {
    errorNode.remove();
  }
}

function renderInlineFormError(form, message) {
  var errorNode = form.querySelector('[data-inline-customer-error="1"]');
  if (!errorNode) {
    errorNode = document.createElement('div');
    errorNode.setAttribute('data-inline-customer-error', '1');
    errorNode.className = 'alert alert-danger gac-inline-form-alert';
    form.insertBefore(errorNode, form.firstChild);
  }
  errorNode.textContent = message || 'Something went wrong.';
}
