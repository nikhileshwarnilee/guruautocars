document.addEventListener('DOMContentLoaded', function () {
  gacBoot();
});

function gacBoot() {
  initSidebarStatePersistence();
  ensurePageBreadcrumbs();
  initFlashNotifications();
  bindConfirmForms();
  initAjaxFormEngine();
  initGlobalVehicleSearch();
  initSearchableSelects();
  initVehicleAttributeSelectors();
  initMasterInsightsFilters();
  initStandardizedTables();
}

function initSidebarStatePersistence() {
  if (!document.body || document.body.getAttribute('data-gac-sidebar-state-init') === '1') {
    return;
  }
  document.body.setAttribute('data-gac-sidebar-state-init', '1');

  var storageKey = 'gac.sidebar.collapsed';
  applyStoredSidebarState(storageKey);
  persistSidebarState(storageKey);

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || typeof target.closest !== 'function') {
      return;
    }

    var sidebarToggle = target.closest('[data-lte-toggle=\"sidebar\"]');
    if (!sidebarToggle) {
      return;
    }

    window.setTimeout(function () {
      persistSidebarState(storageKey);
    }, 140);
  });

  if (typeof MutationObserver === 'function') {
    var classObserver = new MutationObserver(function (mutations) {
      for (var index = 0; index < mutations.length; index++) {
        if (mutations[index].attributeName === 'class') {
          persistSidebarState(storageKey);
          break;
        }
      }
    });
    classObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
  }
}

function applyStoredSidebarState(storageKey) {
  if (!document.body) {
    return;
  }

  try {
    if (!window.localStorage) {
      return;
    }

    if (window.localStorage.getItem(storageKey) === '1') {
      document.body.classList.add('sidebar-collapse');
      document.body.classList.remove('sidebar-open');
    }
  } catch (error) {
    // Ignore storage permission errors.
  }
}

function persistSidebarState(storageKey) {
  if (!document.body) {
    return;
  }

  try {
    if (!window.localStorage) {
      return;
    }

    var isCollapsed = document.body.classList.contains('sidebar-collapse');
    window.localStorage.setItem(storageKey, isCollapsed ? '1' : '0');
  } catch (error) {
    // Ignore storage permission errors.
  }
}

function ensurePageBreadcrumbs() {
  var contentHeaders = document.querySelectorAll('.app-main .app-content-header');
  if (!contentHeaders || contentHeaders.length === 0) {
    return;
  }

  for (var headerIndex = 0; headerIndex < contentHeaders.length; headerIndex++) {
    var contentHeader = contentHeaders[headerIndex];
    if (!contentHeader || contentHeader.querySelector('.breadcrumb')) {
      continue;
    }

    var breadcrumbTrail = resolveBreadcrumbTrail();
    if (!breadcrumbTrail || breadcrumbTrail.length === 0) {
      continue;
    }

    var headerRow = contentHeader.querySelector('.row');
    if (!headerRow) {
      continue;
    }

    var breadcrumbHost = resolveBreadcrumbHost(headerRow);
    if (!breadcrumbHost) {
      continue;
    }

    var breadcrumbList = document.createElement('ol');
    breadcrumbList.className = 'breadcrumb float-sm-end gac-auto-breadcrumb';
    if (breadcrumbHost.children && breadcrumbHost.children.length > 0) {
      breadcrumbList.classList.add('mb-2');
    }

    for (var itemIndex = 0; itemIndex < breadcrumbTrail.length; itemIndex++) {
      var crumb = breadcrumbTrail[itemIndex];
      if (!crumb || !crumb.label) {
        continue;
      }

      var listItem = document.createElement('li');
      var isActive = itemIndex === breadcrumbTrail.length - 1 || !crumb.href;
      listItem.className = isActive ? 'breadcrumb-item active' : 'breadcrumb-item';

      if (isActive) {
        listItem.setAttribute('aria-current', 'page');
        listItem.textContent = crumb.label;
      } else {
        var link = document.createElement('a');
        link.href = crumb.href;
        link.textContent = crumb.label;
        listItem.appendChild(link);
      }
      breadcrumbList.appendChild(listItem);
    }

    if (breadcrumbHost.firstChild) {
      breadcrumbHost.insertBefore(breadcrumbList, breadcrumbHost.firstChild);
    } else {
      breadcrumbHost.appendChild(breadcrumbList);
    }
  }
}

function resolveBreadcrumbHost(headerRow) {
  if (!headerRow) {
    return null;
  }

  var columns = [];
  for (var childIndex = 0; childIndex < headerRow.children.length; childIndex++) {
    var child = headerRow.children[childIndex];
    if (!child || !child.className) {
      continue;
    }
    if (/(^|\\s)col-/.test(String(child.className))) {
      columns.push(child);
    }
  }
  if (columns.length === 0) {
    return null;
  }

  var host = null;
  if (columns.length === 1) {
    host = document.createElement('div');
    host.className = 'col-sm-6 text-sm-end';
    headerRow.appendChild(host);
    return host;
  }

  host = columns[columns.length - 1];
  host.classList.add('text-sm-end');
  return host;
}

function resolveBreadcrumbTrail() {
  var activeMenu = '';
  var pageTitle = '';
  var dashboardUrl = 'dashboard.php';

  if (document.body) {
    activeMenu = String(document.body.getAttribute('data-active-menu') || '').trim();
    pageTitle = String(document.body.getAttribute('data-page-title') || '').trim();
    var configuredDashboardUrl = String(document.body.getAttribute('data-dashboard-url') || '').trim();
    if (configuredDashboardUrl !== '') {
      dashboardUrl = configuredDashboardUrl;
    }
  }

  var map = buildBreadcrumbMap(dashboardUrl);
  if (activeMenu !== '' && Object.prototype.hasOwnProperty.call(map, activeMenu)) {
    return map[activeMenu];
  }

  if (pageTitle === '') {
    var heading = document.querySelector('.app-content-header h1, .app-content-header h2, .app-content-header h3');
    pageTitle = heading ? collapseWhitespace(heading.textContent || '').trim() : '';
  }
  if (pageTitle === '') {
    pageTitle = 'Page';
  }

  return [{ label: 'Home', href: dashboardUrl }, { label: pageTitle }];
}

function buildBreadcrumbMap(dashboardUrl) {
  var home = { label: 'Home', href: dashboardUrl };

  return {
    'dashboard': [home, { label: 'Dashboard' }],
    'jobs': [home, { label: 'Operations' }, { label: 'Job Cards' }],
    'estimates': [home, { label: 'Operations' }, { label: 'Estimates' }],
    'outsourced.index': [home, { label: 'Operations' }, { label: 'Outsourced Works' }],
    'inventory': [home, { label: 'Operations' }, { label: 'Stock Movements' }],
    'billing': [home, { label: 'Sales' }, { label: 'Billing / Invoices' }],
    'customers': [home, { label: 'Sales' }, { label: 'Customer Master' }],
    'vehicles': [home, { label: 'Sales' }, { label: 'Vehicle Master' }],
    'inventory.parts_master': [home, { label: 'Inventory' }, { label: 'Parts / Item Master' }],
    'inventory.categories': [home, { label: 'Inventory' }, { label: 'Part Category Master' }],
    'purchases.index': [home, { label: 'Inventory' }, { label: 'Purchases' }],
    'vendors.master': [home, { label: 'Inventory' }, { label: 'Vendor / Supplier Master' }],
    'finance.payroll': [home, { label: 'Finance' }, { label: 'Payroll & Salary' }],
    'finance.expenses': [home, { label: 'Finance' }, { label: 'Expenses' }],
    'reports.gst_compliance': [home, { label: 'Finance' }, { label: 'GST Compliance' }],
    'reports': [home, { label: 'Reports' }, { label: 'Overview' }],
    'reports.jobs': [home, { label: 'Reports' }, { label: 'Job Reports' }],
    'reports.inventory': [home, { label: 'Reports' }, { label: 'Inventory Reports' }],
    'reports.billing': [home, { label: 'Reports' }, { label: 'Billing & GST' }],
    'reports.payroll': [home, { label: 'Reports' }, { label: 'Payroll Reports' }],
    'reports.expenses': [home, { label: 'Reports' }, { label: 'Expense Reports' }],
    'reports.outsourced': [home, { label: 'Reports' }, { label: 'Outsourced Labour' }],
    'reports.customers': [home, { label: 'Reports' }, { label: 'Customer Reports' }],
    'reports.vehicles': [home, { label: 'Reports' }, { label: 'Vehicle Reports' }],
    'organization.companies': [home, { label: 'Administration' }, { label: 'Organization & System' }, { label: 'Company Master' }],
    'organization.garages': [home, { label: 'Administration' }, { label: 'Organization & System' }, { label: 'Garage / Branch Master' }],
    'system.financial_years': [home, { label: 'Administration' }, { label: 'Organization & System' }, { label: 'Financial Year' }],
    'system.settings': [home, { label: 'Administration' }, { label: 'Organization & System' }, { label: 'System Settings' }],
    'system.audit': [home, { label: 'Administration' }, { label: 'Organization & System' }, { label: 'Audit Logs' }],
    'system.exports': [home, { label: 'Administration' }, { label: 'Organization & System' }, { label: 'Data Exports' }],
    'system.backup': [home, { label: 'Administration' }, { label: 'Organization & System' }, { label: 'Backup & Recovery' }],
    'people.roles': [home, { label: 'Administration' }, { label: 'Role Master' }],
    'people.permissions': [home, { label: 'Administration' }, { label: 'Permission Management' }],
    'people.staff': [home, { label: 'Administration' }, { label: 'Staff Master' }],
    'services.categories': [home, { label: 'Administration' }, { label: 'Service Category Master' }],
    'services.master': [home, { label: 'Administration' }, { label: 'Service / Labour Master' }],
    'vis.catalog': [home, { label: 'Administration' }, { label: 'VIS Vehicle Catalog' }],
    'vis.compatibility': [home, { label: 'Administration' }, { label: 'VIS Compatibility Mapping' }]
  };
}

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

function initAjaxFormEngine() {
  if (!document.body || document.body.getAttribute('data-gac-ajax-form-init') === '1') {
    return;
  }
  document.body.setAttribute('data-gac-ajax-form-init', '1');

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || form.tagName !== 'FORM') {
      return;
    }
    if (event.defaultPrevented || !shouldAjaxifyForm(form)) {
      return;
    }

    event.preventDefault();
    if (form.getAttribute('data-gac-submitting') === '1') {
      return;
    }

    var method = resolveFormMethod(form);
    clearFormStatus(form);
    clearFormFieldErrors(form);

    if (!form.noValidate && typeof form.checkValidity === 'function' && !form.checkValidity()) {
      form.classList.add('was-validated');
      showFormStatus(form, 'Please correct the highlighted fields and retry.', 'danger');
      if (typeof form.reportValidity === 'function') {
        form.reportValidity();
      }
      return;
    }

    var submitter = event.submitter || null;
    form.setAttribute('data-gac-submitting', '1');
    setFormSubmittingState(form, submitter, true);

    if (method === 'GET') {
      var navigationUrl = buildGetFormUrl(form, submitter);
      navigateWithinApp(navigationUrl, [])
        .catch(function () {
          window.location.assign(navigationUrl);
        })
        .then(function () {
          form.removeAttribute('data-gac-submitting');
          setFormSubmittingState(form, submitter, false);
        });
      return;
    }

    var actionUrl = resolveFormAction(form);
    var formData = new FormData(form);
    if (submitter && submitter.name) {
      formData.append(submitter.name, submitter.value || '');
    }

    fetch(actionUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      body: formData
    })
      .then(function (response) {
        return response.text().then(function (text) {
          return {
            response: response,
            payload: safeParseJson(text),
            rawText: text
          };
        });
      })
      .then(function (result) {
        var payload = result.payload || null;
        var flashMessages = payload ? normalizeFlashMessages(payload.flash || payload.flashes || payload.messages || []) : [];
        var flashRendered = false;
        if (flashMessages.length > 0) {
          flashRendered = renderFlashMessages(flashMessages);
          if (!flashRendered) {
            showFormStatus(form, flashMessages[0].message, flashMessages[0].type || 'info');
          }
        }

        if (payload && payload.errors && typeof payload.errors === 'object') {
          applyFieldErrors(form, payload.errors);
          if (typeof payload.message === 'string' && payload.message.trim() !== '') {
            showFormStatus(form, payload.message, 'danger');
          } else {
            showFormStatus(form, 'Please review the highlighted fields.', 'danger');
          }
        } else if (payload && typeof payload.message === 'string' && payload.message.trim() !== '') {
          showFormStatus(form, payload.message, payload.ok === false ? 'danger' : 'success');
        }

        if (payload && typeof payload.redirect === 'string' && payload.redirect.trim() !== '') {
          var destination = payload.redirect;
          var sameDestination = isSameDestinationUrl(destination, window.location.href);

          if (payload.ok === false && sameDestination) {
            if (flashMessages.length === 0 && (!payload.message || String(payload.message).trim() === '')) {
              showFormStatus(form, 'Unable to process the request. Please review and retry.', 'danger');
            }
            return;
          }

          return navigateWithinApp(destination, flashMessages).catch(function () {
            window.location.assign(destination);
          });
        }

        if (!payload) {
          if (result.response && result.response.redirected && result.response.url) {
            return navigateWithinApp(result.response.url, []).catch(function () {
              window.location.assign(result.response.url);
            });
          }
          showFormStatus(form, 'Unexpected server response. Please refresh and retry.', 'danger');
          return;
        }

        if (payload.ok === false && flashMessages.length === 0 && (!payload.message || String(payload.message).trim() === '')) {
          showFormStatus(form, 'Request failed. Please retry.', 'danger');
          return;
        }

        if (payload.ok !== false && flashMessages.length === 0 && (!payload.message || String(payload.message).trim() === '')) {
          showFormStatus(form, 'Saved successfully.', 'success');
        }
      })
      .catch(function () {
        showFormStatus(form, 'Network error. Please retry.', 'danger');
      })
      .then(function () {
        form.removeAttribute('data-gac-submitting');
        setFormSubmittingState(form, submitter, false);
      });
  });
}

function shouldAjaxifyForm(form) {
  if (!form) {
    return false;
  }
  if (form.getAttribute('data-no-ajax') === '1') {
    return false;
  }
  if (form.getAttribute('data-ajax') === 'off' || form.getAttribute('data-ajax-form') === 'off') {
    return false;
  }
  if (form.getAttribute('data-master-filter-form') === '1') {
    return false;
  }
  if (form.getAttribute('data-inline-customer-form') === '1') {
    return false;
  }

  var inlineOnSubmit = String(form.getAttribute('onsubmit') || '').toLowerCase();
  if (inlineOnSubmit.indexOf('return false') >= 0) {
    return false;
  }

  var method = resolveFormMethod(form);
  if (method !== 'POST' && method !== 'GET') {
    return false;
  }

  var target = String(form.getAttribute('target') || '').trim().toLowerCase();
  if (target !== '' && target !== '_self') {
    return false;
  }

  var action = String(form.getAttribute('action') || '').trim().toLowerCase();
  if (action.indexOf('print_') >= 0) {
    return false;
  }
  if (method === 'GET' && action.indexOf('download=1') >= 0) {
    return false;
  }

  return true;
}

function resolveFormMethod(form) {
  if (!form) {
    return 'GET';
  }
  return String(form.getAttribute('method') || 'get').trim().toUpperCase();
}

function resolveFormAction(form) {
  if (!form) {
    return window.location.href;
  }

  var action = String(form.getAttribute('action') || '').trim();
  return action !== '' ? action : window.location.href;
}

function buildGetFormUrl(form, submitter) {
  var baseUrl = new URL(resolveFormAction(form), window.location.href);
  var formData = new FormData(form);
  if (submitter && submitter.name) {
    formData.append(submitter.name, submitter.value || '');
  }

  var params = new URLSearchParams();
  formData.forEach(function (value, key) {
    if (typeof value !== 'string') {
      return;
    }
    params.append(key, value);
  });

  baseUrl.search = params.toString();
  return baseUrl.toString();
}

function setFormSubmittingState(form, submitter, isSubmitting) {
  if (!form) {
    return;
  }

  var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
  var targetButton = submitter;
  if (!targetButton || targetButton.form !== form) {
    targetButton = submitButtons.length > 0 ? submitButtons[0] : null;
  }

  if (isSubmitting) {
    form.classList.add('gac-form-loading');
    form.setAttribute('aria-busy', 'true');

    for (var index = 0; index < submitButtons.length; index++) {
      var button = submitButtons[index];
      button.setAttribute('data-gac-disabled-before', button.disabled ? '1' : '0');
      button.disabled = true;

      if (button !== targetButton) {
        continue;
      }

      if (button.tagName === 'BUTTON') {
        button.setAttribute('data-gac-original-html', button.innerHTML);
        var loadingLabel = (button.getAttribute('data-loading-label') || button.textContent || 'Processing').trim();
        if (loadingLabel === '') {
          loadingLabel = 'Processing';
        }
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-1 gac-btn-spinner" role="status" aria-hidden="true"></span>' + escapeHtml(loadingLabel) + '...';
        button.setAttribute('data-gac-spinner-active', '1');
      } else if (button.tagName === 'INPUT') {
        button.setAttribute('data-gac-original-value', button.value);
        button.value = 'Processing...';
        button.setAttribute('data-gac-spinner-active', '1');
      }
    }
    return;
  }

  form.classList.remove('gac-form-loading');
  form.removeAttribute('aria-busy');

  for (var buttonIndex = 0; buttonIndex < submitButtons.length; buttonIndex++) {
    var submitButton = submitButtons[buttonIndex];
    if (submitButton.getAttribute('data-gac-spinner-active') === '1') {
      if (submitButton.tagName === 'BUTTON') {
        submitButton.innerHTML = submitButton.getAttribute('data-gac-original-html') || submitButton.innerHTML;
        submitButton.removeAttribute('data-gac-original-html');
      } else if (submitButton.tagName === 'INPUT') {
        submitButton.value = submitButton.getAttribute('data-gac-original-value') || submitButton.value;
        submitButton.removeAttribute('data-gac-original-value');
      }
      submitButton.removeAttribute('data-gac-spinner-active');
    }

    var wasDisabled = submitButton.getAttribute('data-gac-disabled-before') === '1';
    submitButton.disabled = wasDisabled;
    submitButton.removeAttribute('data-gac-disabled-before');
  }
}

function clearFormStatus(form) {
  if (!form) {
    return;
  }

  var statusNodes = form.querySelectorAll('[data-gac-form-status="1"]');
  for (var index = 0; index < statusNodes.length; index++) {
    statusNodes[index].remove();
  }
}

function showFormStatus(form, message, type) {
  var statusMessage = String(message || '').trim();
  if (statusMessage === '') {
    return;
  }

  if (renderFlashMessages([{ message: statusMessage, type: type || 'info' }])) {
    return;
  }

  if (!form) {
    return;
  }

  if (form.classList.contains('d-inline') || form.classList.contains('d-inline-flex')) {
    renderFlashMessages([{ message: statusMessage, type: type || 'info' }]);
    return;
  }

  clearFormStatus(form);
  var alertType = normalizeAlertType(type || 'info');
  var statusNode = document.createElement('div');
  statusNode.className = 'alert alert-' + alertType + ' alert-dismissible fade show gac-form-status';
  statusNode.setAttribute('data-gac-form-status', '1');
  statusNode.setAttribute('role', 'alert');
  statusNode.innerHTML = escapeHtml(statusMessage) + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';

  form.insertBefore(statusNode, form.firstChild);
}

function clearFormFieldErrors(form) {
  if (!form) {
    return;
  }

  var invalidFields = form.querySelectorAll('[data-gac-invalid="1"]');
  for (var index = 0; index < invalidFields.length; index++) {
    invalidFields[index].classList.remove('is-invalid');
    invalidFields[index].removeAttribute('data-gac-invalid');
  }

  var feedbackNodes = form.querySelectorAll('[data-gac-field-feedback="1"]');
  for (var feedbackIndex = 0; feedbackIndex < feedbackNodes.length; feedbackIndex++) {
    feedbackNodes[feedbackIndex].remove();
  }
}

function applyFieldErrors(form, errors) {
  if (!form || !errors || typeof errors !== 'object') {
    return;
  }

  var fieldNames = Object.keys(errors);
  var firstInvalidField = null;
  for (var index = 0; index < fieldNames.length; index++) {
    var fieldName = fieldNames[index];
    var selector = '[name="' + cssEscapeSelector(fieldName) + '"]';
    var fields = form.querySelectorAll(selector);
    if (!fields || fields.length === 0) {
      continue;
    }

    var rawMessage = errors[fieldName];
    var message = '';
    if (Array.isArray(rawMessage)) {
      message = String(rawMessage[0] || '').trim();
    } else {
      message = String(rawMessage || '').trim();
    }
    if (message === '') {
      message = 'Invalid value.';
    }

    for (var fieldIndex = 0; fieldIndex < fields.length; fieldIndex++) {
      var field = fields[fieldIndex];
      field.classList.add('is-invalid');
      field.setAttribute('data-gac-invalid', '1');
      if (!firstInvalidField) {
        firstInvalidField = field;
      }

      if (fieldIndex > 0) {
        continue;
      }

      var feedback = document.createElement('div');
      feedback.className = 'invalid-feedback';
      feedback.setAttribute('data-gac-field-feedback', '1');
      feedback.textContent = message;

      if (field.nextSibling) {
        field.parentNode.insertBefore(feedback, field.nextSibling);
      } else {
        field.parentNode.appendChild(feedback);
      }
    }
  }

  if (firstInvalidField && typeof firstInvalidField.focus === 'function') {
    firstInvalidField.focus();
  }
}

function normalizeFlashMessages(rawMessages) {
  var items = [];
  if (Array.isArray(rawMessages)) {
    items = rawMessages;
  } else if (rawMessages && typeof rawMessages === 'object') {
    items = Object.keys(rawMessages).map(function (key) {
      return rawMessages[key];
    });
  } else if (typeof rawMessages === 'string' && rawMessages.trim() !== '') {
    items = [{ message: rawMessages.trim(), type: 'info' }];
  }

  var normalized = [];
  for (var index = 0; index < items.length; index++) {
    var item = items[index];
    if (!item) {
      continue;
    }

    if (typeof item === 'string') {
      if (item.trim() !== '') {
        normalized.push({ message: item.trim(), type: 'info' });
      }
      continue;
    }

    var message = String(item.message || '').trim();
    if (message === '') {
      continue;
    }

    normalized.push({
      message: message,
      type: normalizeAlertType(item.type || 'info')
    });
  }

  return normalized;
}

function initFlashNotifications() {
  var dataNode = document.getElementById('gac-initial-flash-data');
  if (!dataNode || dataNode.getAttribute('data-gac-consumed') === '1') {
    return;
  }

  dataNode.setAttribute('data-gac-consumed', '1');
  var payload = [];
  try {
    payload = JSON.parse(dataNode.textContent || '[]');
  } catch (error) {
    payload = [];
  }

  if (payload && payload.length > 0) {
    renderFlashMessages(payload);
  }
}

function renderFlashMessages(messages) {
  var container = resolveFlashContainer();
  if (!container) {
    return false;
  }

  var normalizedMessages = normalizeFlashMessages(messages);
  if (normalizedMessages.length === 0) {
    return true;
  }

  for (var index = 0; index < normalizedMessages.length; index++) {
    var message = normalizedMessages[index];
    var toast = document.createElement('div');
    var alertType = normalizeAlertType(message.type);
    var toastBgClass = alertType === 'danger' ? 'text-bg-danger' : ('text-bg-' + alertType);
    toast.className = 'toast ' + toastBgClass + ' border-0';
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    toast.setAttribute('data-bs-delay', '4500');
    toast.setAttribute('data-bs-autohide', 'true');
    toast.innerHTML =
      '<div class="d-flex">' +
        '<div class="toast-body">' + escapeHtml(message.message) + '</div>' +
        '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
      '</div>';
    container.appendChild(toast);

    if (window.bootstrap && window.bootstrap.Toast) {
      var toastInstance = window.bootstrap.Toast.getOrCreateInstance(toast);
      toast.addEventListener('hidden.bs.toast', function () {
        if (this && this.parentNode) {
          this.parentNode.removeChild(this);
        }
      });
      toastInstance.show();
    } else {
      window.setTimeout(function (node) {
        if (node && node.parentNode) {
          node.parentNode.removeChild(node);
        }
      }, 4500, toast);
    }
  }
  return true;
}

function resolveFlashContainer() {
  var container = document.getElementById('gac-flash-container');
  if (!container) {
    return null;
  }
  container.classList.add('gac-toast-stack');
  return container;
}

function navigateWithinApp(url, flashMessages) {
  var destination;
  try {
    destination = new URL(String(url || ''), window.location.href);
  } catch (error) {
    return Promise.reject(error);
  }

  if (destination.origin !== window.location.origin) {
    return Promise.reject(new Error('Cross-origin navigation not supported for AJAX-first flow.'));
  }

  return fetch(destination.toString(), {
    credentials: 'same-origin'
  })
    .then(function (response) {
      return response.text().then(function (html) {
        return {
          response: response,
          html: html
        };
      });
    })
    .then(function (result) {
      if (!replaceAppWrapperFromHtml(result.html)) {
        throw new Error('Unable to replace app wrapper.');
      }

      var finalUrl = result.response && result.response.url
        ? String(result.response.url)
        : destination.toString();

      if (window.history && typeof window.history.replaceState === 'function') {
        window.history.replaceState({}, '', finalUrl);
      }

      if (flashMessages && flashMessages.length > 0) {
        renderFlashMessages(flashMessages);
      }

      gacBoot();
      window.scrollTo(0, 0);
      return true;
    });
}

function replaceAppWrapperFromHtml(html) {
  var markup = String(html || '');
  if (markup.trim() === '') {
    return false;
  }

  var parser = new DOMParser();
  var parsedDocument = parser.parseFromString(markup, 'text/html');
  var incomingWrapper = parsedDocument.querySelector('.app-wrapper');
  var currentWrapper = document.querySelector('.app-wrapper');
  if (!incomingWrapper || !currentWrapper) {
    return false;
  }

  currentWrapper.innerHTML = incomingWrapper.innerHTML;
  if (parsedDocument.title && parsedDocument.title.trim() !== '') {
    document.title = parsedDocument.title.trim();
  }
  syncBodyDataAttributes(parsedDocument);

  executeScriptsInContainer(currentWrapper);
  return true;
}

function syncBodyDataAttributes(parsedDocument) {
  if (!parsedDocument || !parsedDocument.body || !document.body) {
    return;
  }

  var attributeNames = [
    'data-inline-customer-form-url',
    'data-active-menu',
    'data-page-title',
    'data-dashboard-url'
  ];

  for (var index = 0; index < attributeNames.length; index++) {
    var attributeName = attributeNames[index];
    var attributeValue = parsedDocument.body.getAttribute(attributeName);
    if (attributeValue === null) {
      document.body.removeAttribute(attributeName);
    } else {
      document.body.setAttribute(attributeName, attributeValue);
    }
  }
}

function executeScriptsInContainer(container) {
  if (!container) {
    return;
  }

  var scripts = container.querySelectorAll('script');
  for (var index = 0; index < scripts.length; index++) {
    var script = scripts[index];
    var scriptType = String(script.getAttribute('type') || '').trim().toLowerCase();
    if (scriptType !== '' && scriptType !== 'text/javascript' && scriptType !== 'application/javascript' && scriptType !== 'module') {
      continue;
    }

    var replacement = document.createElement('script');
    for (var attrIndex = 0; attrIndex < script.attributes.length; attrIndex++) {
      var attribute = script.attributes[attrIndex];
      replacement.setAttribute(attribute.name, attribute.value);
    }
    replacement.text = script.text || '';
    script.parentNode.replaceChild(replacement, script);
  }
}

function safeParseJson(text) {
  var payload = null;
  try {
    payload = JSON.parse(String(text || ''));
  } catch (error) {
    payload = null;
  }
  return payload;
}

function isSameDestinationUrl(left, right) {
  try {
    var leftUrl = new URL(String(left || ''), window.location.href);
    var rightUrl = new URL(String(right || ''), window.location.href);
    return leftUrl.origin === rightUrl.origin
      && leftUrl.pathname === rightUrl.pathname
      && leftUrl.search === rightUrl.search;
  } catch (error) {
    return false;
  }
}

function normalizeAlertType(type) {
  var value = String(type || '').trim().toLowerCase();
  if (value === 'error') {
    value = 'danger';
  }
  if (value === 'success' || value === 'danger' || value === 'warning' || value === 'info') {
    return value;
  }
  return 'info';
}

function cssEscapeSelector(value) {
  if (window.CSS && typeof window.CSS.escape === 'function') {
    return window.CSS.escape(String(value || ''));
  }
  return String(value || '').replace(/\\/g, '\\\\').replace(/"/g, '\\"');
}

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function initGlobalVehicleSearch() {
  var roots = document.querySelectorAll('[data-global-vehicle-search-root="1"]');
  if (!roots || roots.length === 0) {
    return;
  }

  for (var i = 0; i < roots.length; i++) {
    initGlobalSearchRoot(roots[i]);
  }

  function initGlobalSearchRoot(root) {
    if (!root || root.getAttribute('data-global-vehicle-search-init') === '1') {
      return;
    }

    var endpoint = (root.getAttribute('data-global-vehicle-search-endpoint') || '').trim();
    var input = root.querySelector('[data-global-vehicle-search-input="1"]');
    var resultsBox = root.querySelector('[data-global-vehicle-search-results="1"]');
    var clearButton = root.querySelector('[data-global-vehicle-search-clear="1"]');
    if (endpoint === '' || !input || !resultsBox) {
      return;
    }

    root.setAttribute('data-global-vehicle-search-init', '1');

    var state = {
      requestSequence: 0,
      activeIndex: -1,
      items: [],
      opened: false,
      lastQuery: ''
    };

    var debouncedRequest = debounce(function () {
      requestAndRender(false);
    }, 180);

    input.addEventListener('input', function () {
      toggleClearButton();
      var query = compactValue(input.value);
      if (query.length < 2) {
        state.requestSequence++;
        state.items = [];
        state.activeIndex = -1;
        state.lastQuery = '';
        closeResults();
        return;
      }
      debouncedRequest();
    });

    input.addEventListener('focus', function () {
      var query = compactValue(input.value);
      if (query.length >= 2) {
        requestAndRender(true);
      }
    });

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        if (state.opened) {
          event.preventDefault();
          closeResults();
        }
        return;
      }

      if (event.key === 'Tab') {
        closeResults();
        return;
      }

      if (!state.opened && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
        var query = compactValue(input.value);
        if (query.length >= 2) {
          event.preventDefault();
          requestAndRender(true);
        }
        return;
      }

      if (state.items.length === 0) {
        return;
      }

      if (event.key === 'ArrowDown') {
        event.preventDefault();
        setActiveIndex(state.activeIndex + 1);
        return;
      }

      if (event.key === 'ArrowUp') {
        event.preventDefault();
        setActiveIndex(state.activeIndex - 1);
        return;
      }

      if (event.key !== 'Enter') {
        return;
      }

      event.preventDefault();
      if (state.activeIndex < 0) {
        setActiveIndex(0);
      }

      var selected = state.items[state.activeIndex];
      if (!selected) {
        return;
      }

      navigateToIntelligence(selected.intelligence_url || '');
    });

    if (clearButton) {
      clearButton.addEventListener('click', function () {
        state.requestSequence++;
        input.value = '';
        state.items = [];
        state.activeIndex = -1;
        state.lastQuery = '';
        closeResults();
        toggleClearButton();
        input.focus();
      });
    }

    document.addEventListener('click', function (event) {
      if (!root.contains(event.target)) {
        closeResults();
      }
    });

    function compactValue(value) {
      return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function toggleClearButton() {
      if (!clearButton) {
        return;
      }
      if (compactValue(input.value) === '') {
        clearButton.classList.add('d-none');
      } else {
        clearButton.classList.remove('d-none');
      }
    }

    function requestAndRender(forceRefresh) {
      var query = compactValue(input.value);
      if (query.length < 2) {
        closeResults();
        return;
      }

      if (!forceRefresh && query === state.lastQuery && state.items.length > 0) {
        openResults();
        return;
      }

      var requestSequence = ++state.requestSequence;
      renderMessage('Searching vehicles...');

      var requestUrl = endpoint + (endpoint.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(query);
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
          if (requestSequence !== state.requestSequence) {
            return;
          }

          if (!result.ok || !result.payload || !result.payload.ok) {
            state.items = [];
            renderMessage('Unable to search right now.');
            return;
          }

          state.lastQuery = query;
          state.items = Array.isArray(result.payload.items) ? result.payload.items : [];
          state.activeIndex = -1;
          renderItems();
        })
        .catch(function () {
          if (requestSequence !== state.requestSequence) {
            return;
          }
          state.items = [];
          renderMessage('Unable to search right now.');
        });
    }

    function renderMessage(message) {
      resultsBox.innerHTML = '';
      var row = document.createElement('div');
      row.className = 'list-group-item text-muted small';
      row.textContent = message || 'No results';
      resultsBox.appendChild(row);
      openResults();
    }

    function renderItems() {
      resultsBox.innerHTML = '';
      if (state.items.length === 0) {
        renderMessage('No vehicles found.');
        return;
      }

      for (var index = 0; index < state.items.length; index++) {
        var item = state.items[index] || {};
        var rowButton = document.createElement('button');
        rowButton.type = 'button';
        rowButton.className = 'list-group-item list-group-item-action gac-global-search-item';
        rowButton.setAttribute('data-global-search-item', '1');
        rowButton.setAttribute('data-index', String(index));
        rowButton.setAttribute('role', 'option');
        rowButton.setAttribute('aria-selected', 'false');

        var topRow = document.createElement('div');
        topRow.className = 'd-flex justify-content-between align-items-center gap-2';
        var registration = document.createElement('div');
        registration.className = 'fw-semibold';
        registration.textContent = String(item.registration_no || '');
        var status = document.createElement('span');
        status.className = 'badge text-bg-' + statusBadgeClass(item.status_code || 'ACTIVE');
        status.textContent = String(item.status_code || 'ACTIVE');
        topRow.appendChild(registration);
        topRow.appendChild(status);
        rowButton.appendChild(topRow);

        var vehicleLabel = document.createElement('div');
        vehicleLabel.className = 'small text-muted';
        vehicleLabel.textContent = String(item.vehicle_label || '');
        rowButton.appendChild(vehicleLabel);

        var ownerLine = document.createElement('div');
        ownerLine.className = 'small';
        var ownerPhone = String(item.customer_phone || '').trim();
        ownerLine.textContent = String(item.customer_name || '-') + (ownerPhone !== '' ? ' | ' + ownerPhone : '');
        rowButton.appendChild(ownerLine);

        var statsLine = document.createElement('div');
        statsLine.className = 'small text-muted';
        statsLine.textContent =
          'Jobs: ' + String(item.total_jobs || 0) +
          ' | Open: ' + String(item.open_jobs || 0) +
          (String(item.last_visit_at || '').trim() !== '' ? ' | Last: ' + String(item.last_visit_at) : '');
        rowButton.appendChild(statsLine);

        rowButton.addEventListener('mouseenter', function () {
          var hoverIndex = parseInt(this.getAttribute('data-index') || '-1', 10);
          if (isNaN(hoverIndex) || hoverIndex < 0) {
            return;
          }
          setActiveIndex(hoverIndex);
        });

        rowButton.addEventListener('click', function () {
          var clickIndex = parseInt(this.getAttribute('data-index') || '-1', 10);
          if (isNaN(clickIndex) || clickIndex < 0 || !state.items[clickIndex]) {
            return;
          }
          navigateToIntelligence(state.items[clickIndex].intelligence_url || '');
        });

        resultsBox.appendChild(rowButton);
      }

      openResults();
    }

    function setActiveIndex(index) {
      var nodes = resultsBox.querySelectorAll('[data-global-search-item="1"]');
      if (!nodes || nodes.length === 0) {
        state.activeIndex = -1;
        return;
      }

      if (index >= nodes.length) {
        index = 0;
      } else if (index < 0) {
        index = nodes.length - 1;
      }
      state.activeIndex = index;

      for (var i = 0; i < nodes.length; i++) {
        if (i === state.activeIndex) {
          nodes[i].classList.add('active');
          nodes[i].setAttribute('aria-selected', 'true');
          if (typeof nodes[i].scrollIntoView === 'function') {
            nodes[i].scrollIntoView({ block: 'nearest' });
          }
        } else {
          nodes[i].classList.remove('active');
          nodes[i].setAttribute('aria-selected', 'false');
        }
      }
    }

    function statusBadgeClass(status) {
      var normalized = String(status || '').toUpperCase();
      if (normalized === 'ACTIVE') {
        return 'success';
      }
      if (normalized === 'INACTIVE') {
        return 'warning';
      }
      if (normalized === 'DELETED') {
        return 'danger';
      }
      return 'secondary';
    }

    function navigateToIntelligence(url) {
      var destination = String(url || '').trim();
      if (destination === '') {
        return;
      }
      window.location.assign(destination);
    }

    function openResults() {
      resultsBox.classList.remove('d-none');
      state.opened = true;
    }

    function closeResults() {
      resultsBox.classList.add('d-none');
      state.opened = false;
      state.activeIndex = -1;
    }
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
    job_vehicle_color_id: true,
    vehicle_combo_selector: true,
    vehicle_filter_combo_selector: true,
    job_vehicle_combo_selector: true,
    report_vehicle_combo_selector: true,
    estimate_vehicle_combo_selector: true
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
    select.gacSearchableState = state;
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
    var comboSelect = root.querySelector('select[data-vehicle-attr="combo"]');
    var brandSelect = root.querySelector('select[data-vehicle-attr="brand"]');
    var modelSelect = root.querySelector('select[data-vehicle-attr="model"]');
    var variantSelect = root.querySelector('select[data-vehicle-attr="variant"]');
    var yearSelect = root.querySelector('select[data-vehicle-attr="model_year"]');
    var colorSelect = root.querySelector('select[data-vehicle-attr="color"]');
    var brandField = brandSelect || root.querySelector('[data-vehicle-attr-id="brand"]');
    var modelField = modelSelect || root.querySelector('[data-vehicle-attr-id="model"]');
    var variantField = variantSelect || root.querySelector('[data-vehicle-attr-id="variant"]');
    var rootForm = root.closest('form');
    var visVariantSelect = mode === 'entry' && rootForm ? rootForm.querySelector('select[name="vis_variant_id"]') : null;

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

    function selectedIdFromField(field) {
      if (!field) {
        return '';
      }
      var raw = (field.getAttribute('data-selected-id') || '').trim();
      return raw !== '' ? raw : (field.value || '');
    }

    function parseId(value) {
      return parseInt(String(value || '').trim(), 10) || 0;
    }

    function comboKey(brandId, modelId, variantId) {
      var brandValue = parseId(brandId);
      var modelValue = parseId(modelId);
      var variantValue = parseId(variantId);
      if (brandValue <= 0 || modelValue <= 0 || variantValue <= 0) {
        return '';
      }
      return String(brandValue) + ':' + String(modelValue) + ':' + String(variantValue);
    }

    function comboKeyFromOption(option) {
      if (!option) {
        return '';
      }
      var direct = (option.getAttribute('data-combo-key') || '').trim();
      if (direct !== '') {
        return direct;
      }
      return comboKey(
        option.getAttribute('data-brand-id') || '',
        option.getAttribute('data-model-id') || '',
        option.getAttribute('data-variant-id') || ''
      );
    }

    function currentFieldValue(field) {
      return field ? String(field.value || '').trim() : '';
    }

    function setFieldValue(field, value) {
      if (!field) {
        return;
      }
      field.value = String(value || '');
      if (field.tagName === 'SELECT') {
        gacRefreshSearchableSelect(field);
      }
    }

    var state = {
      mode: mode,
      brandSelectedId: selectedIdFromField(brandField),
      modelSelectedId: selectedIdFromField(modelField),
      variantSelectedId: selectedIdFromField(variantField),
      yearSelectedId: selectedIdFromField(yearSelect),
      colorSelectedId: selectedIdFromField(colorSelect),
      comboSelectedKey: '',
      comboSelectedLabel: comboSelect ? String(comboSelect.getAttribute('data-selected-label') || '').trim() : '',
      comboCache: {},
      comboRequestSequence: 0
    };
    state.comboSelectedKey = comboKey(state.brandSelectedId, state.modelSelectedId, state.variantSelectedId);

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
      if (comboSelect && (attrKey === 'brand' || attrKey === 'model' || attrKey === 'variant')) {
        select = comboSelect;
      } else if (attrKey === 'brand') {
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

    function applyComboSelection(option) {
      if (!option) {
        setFieldValue(brandField, '');
        setFieldValue(modelField, '');
        setFieldValue(variantField, '');
        state.brandSelectedId = '';
        state.modelSelectedId = '';
        state.variantSelectedId = '';
        state.comboSelectedKey = '';
        return;
      }

      var parsedBrandId = parseId(option.getAttribute('data-brand-id') || '');
      var parsedModelId = parseId(option.getAttribute('data-model-id') || '');
      var parsedVariantId = parseId(option.getAttribute('data-variant-id') || '');
      var brandId = parsedBrandId > 0 ? String(parsedBrandId) : '';
      var modelId = parsedModelId > 0 ? String(parsedModelId) : '';
      var variantId = parsedVariantId > 0 ? String(parsedVariantId) : '';
      setFieldValue(brandField, brandId);
      setFieldValue(modelField, modelId);
      setFieldValue(variantField, variantId);
      state.brandSelectedId = brandId;
      state.modelSelectedId = modelId;
      state.variantSelectedId = variantId;
      state.comboSelectedKey = comboKeyFromOption(option);
      state.comboSelectedLabel = String(option.textContent || '').trim();

      var visVariantId = parseId(option.getAttribute('data-vis-variant-id') || '');
      if (visVariantSelect && visVariantId > 0) {
        visVariantSelect.value = String(visVariantId);
        gacRefreshSearchableSelect(visVariantSelect);
      }
    }

    function setComboOptions(items, selectedKey) {
      if (!comboSelect) {
        return;
      }

      var finalKey = String(selectedKey || '').trim();
      comboSelect.innerHTML = '';
      appendPlaceholder(comboSelect, mode === 'filter' ? 'All Brand / Model / Variant' : 'Select Brand / Model / Variant');

      for (var idx = 0; idx < items.length; idx++) {
        var item = items[idx] || {};
        var optionBrandId = parseId(item.brand_id || '');
        var optionModelId = parseId(item.model_id || '');
        var optionVariantId = parseId(item.variant_id || '');
        var optionKey = comboKey(optionBrandId, optionModelId, optionVariantId);
        if (optionKey === '') {
          continue;
        }

        var option = document.createElement('option');
        option.value = optionKey;
        option.setAttribute('data-combo-key', optionKey);
        option.setAttribute('data-brand-id', String(optionBrandId));
        option.setAttribute('data-model-id', String(optionModelId));
        option.setAttribute('data-variant-id', String(optionVariantId));
        option.setAttribute('data-vis-variant-id', String(parseId(item.vis_variant_id || '')));
        option.setAttribute('data-source-code', String(item.source_code || 'MASTER'));
        option.textContent = String(item.label || '').trim();
        if (option.textContent === '') {
          option.textContent =
            String(item.brand_name || '').trim() + ' -> ' +
            String(item.model_name || '').trim() + ' -> ' +
            String(item.variant_name || '').trim();
        }
        comboSelect.appendChild(option);
      }

      if (mode === 'entry' && hasFallback('brand') && hasFallback('model')) {
        var customOption = document.createElement('option');
        customOption.value = customValue;
        customOption.textContent = 'Not listed (type manually)';
        comboSelect.appendChild(customOption);
      }

      if (finalKey !== '' && finalKey !== customValue) {
        comboSelect.value = finalKey;
      }

      if (comboSelect.value === '' && finalKey !== '' && finalKey !== customValue) {
        var fallbackOption = document.createElement('option');
        fallbackOption.value = finalKey;
        fallbackOption.setAttribute('data-combo-key', finalKey);
        fallbackOption.setAttribute('data-brand-id', state.brandSelectedId);
        fallbackOption.setAttribute('data-model-id', state.modelSelectedId);
        fallbackOption.setAttribute('data-variant-id', state.variantSelectedId);
        fallbackOption.setAttribute('data-vis-variant-id', '0');
        fallbackOption.textContent = state.comboSelectedLabel !== '' ? state.comboSelectedLabel : 'Current Selection';
        comboSelect.appendChild(fallbackOption);
        comboSelect.value = finalKey;
      }

      if (mode === 'entry' && comboSelect.value === '' && finalKey === customValue) {
        comboSelect.value = customValue;
      }

      gacRefreshSearchableSelect(comboSelect);
    }

    function loadComboOptions(searchQuery, preserveSelection) {
      if (!comboSelect) {
        return Promise.resolve();
      }

      var queryValue = collapseWhitespace(String(searchQuery || '')).trim();
      var selectedKey = preserveSelection ? (comboSelect.value || state.comboSelectedKey || '') : '';

      var params = {
        q: queryValue,
        limit: 120
      };

      if (preserveSelection && queryValue === '') {
        var selectedBrandId = parseId(state.brandSelectedId);
        var selectedModelId = parseId(state.modelSelectedId);
        var selectedVariantId = parseId(state.variantSelectedId);
        if (selectedBrandId > 0) {
          params.brand_id = selectedBrandId;
        }
        if (selectedModelId > 0) {
          params.model_id = selectedModelId;
        }
        if (selectedVariantId > 0) {
          params.variant_id = selectedVariantId;
        }
      }

      var cacheKey = JSON.stringify(params);
      if (Object.prototype.hasOwnProperty.call(state.comboCache, cacheKey)) {
        setComboOptions(state.comboCache[cacheKey], selectedKey);
        if (comboSelect.value !== '' && comboSelect.value !== customValue) {
          applyComboSelection(comboSelect.options[comboSelect.selectedIndex] || null);
        }
        return Promise.resolve();
      }

      if (!preserveSelection) {
        setLoading(comboSelect, mode === 'filter' ? 'Loading combinations...' : 'Loading vehicle combinations...');
      }

      var requestSequence = ++state.comboRequestSequence;
      return request('combo', params).then(function (payload) {
        if (requestSequence !== state.comboRequestSequence) {
          return;
        }
        var items = payload && payload.ok && Array.isArray(payload.items) ? payload.items : [];
        state.comboCache[cacheKey] = items;
        setComboOptions(items, selectedKey);
        if (comboSelect.value !== '' && comboSelect.value !== customValue) {
          applyComboSelection(comboSelect.options[comboSelect.selectedIndex] || null);
        } else if (comboSelect.value === '') {
          applyComboSelection(null);
        }
      }).catch(function () {
        if (requestSequence !== state.comboRequestSequence) {
          return;
        }
        setComboOptions([], selectedKey);
      }).then(function () {
        syncFallback('brand', true);
        syncFallback('model', true);
        syncFallback('variant', false);
      });
    }

    function currentAttrValue(attrKey) {
      if (attrKey === 'brand') {
        return currentFieldValue(brandField);
      }
      if (attrKey === 'model') {
        return currentFieldValue(modelField);
      }
      if (attrKey === 'variant') {
        return currentFieldValue(variantField);
      }
      if (attrKey === 'model_year') {
        return currentFieldValue(yearSelect);
      }
      if (attrKey === 'color') {
        return currentFieldValue(colorSelect);
      }
      return '';
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
        brand_id: currentAttrValue('brand'),
        model_id: currentAttrValue('model'),
        variant_id: currentAttrValue('variant'),
        model_year_id: currentAttrValue('model_year'),
        color_id: currentAttrValue('color')
      };

      if ((comboSelect && comboSelect.value === customValue) || params.brand_id === customValue || params.model_id === customValue || params.variant_id === customValue || params.model_year_id === customValue || params.color_id === customValue) {
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
          option.setAttribute('data-customer-id', String(item.customer_id || ''));
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

    if (comboSelect) {
      comboSelect.addEventListener('change', function () {
        var selectedValue = comboSelect.value || '';
        state.comboSelectedKey = selectedValue;

        if (selectedValue === customValue) {
          applyComboSelection(null);
        } else if (selectedValue === '') {
          applyComboSelection(null);
        } else {
          applyComboSelection(comboSelect.options[comboSelect.selectedIndex] || null);
        }

        syncFallback('brand', true);
        syncFallback('model', true);
        syncFallback('variant', false);
        reloadVehiclePicker();
      });

      var comboSearchState = comboSelect.gacSearchableState || null;
      var comboSearchInput = comboSearchState ? comboSearchState.input : null;
      if (comboSearchInput) {
        var debouncedComboSearch = debounce(function () {
          loadComboOptions(comboSearchInput.value || '', true);
        }, 180);
        comboSearchInput.addEventListener('input', debouncedComboSearch);
        comboSearchInput.addEventListener('focus', function () {
          if (!comboSelect.options || comboSelect.options.length <= 1) {
            loadComboOptions(comboSearchInput.value || '', true);
          }
        });
      }
    }

    if (!comboSelect && brandSelect) {
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

    if (!comboSelect && modelSelect) {
      modelSelect.addEventListener('change', function () {
        state.modelSelectedId = modelSelect.value || '';
        state.variantSelectedId = '';
        syncFallback('model', true);
        loadVariants().then(function () {
          reloadVehiclePicker();
        });
      });
    }

    if (!comboSelect && variantSelect) {
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

    var comboManualDefault = mode === 'entry'
      && comboSelect
      && state.comboSelectedKey === ''
      && (
        (fallbackInputs.brand && (fallbackInputs.brand.value || '').trim() !== '')
        || (fallbackInputs.model && (fallbackInputs.model.value || '').trim() !== '')
        || (fallbackInputs.variant && (fallbackInputs.variant.value || '').trim() !== '')
      );
    if (comboManualDefault) {
      state.comboSelectedKey = customValue;
    }

    var primaryLoader = comboSelect
      ? loadComboOptions('', true)
      : loadBrands();

    Promise.all([primaryLoader, loadYears(), loadColors()]).then(function () {
      syncFallback('brand', true);
      syncFallback('model', true);
      syncFallback('variant', false);
      syncFallback('model_year', false);
      syncFallback('color', false);
      if (comboSelect && comboSelect.value === customValue) {
        applyComboSelection(null);
      }
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

    var table = resolveTableFromBody(tableBody);
    if (!table) {
      return;
    }

    root.setAttribute('data-master-insights-init', '1');
    var scaffold = ensureTableScaffold(table);
    var paginationUi = ensureMasterPaginationUi(scaffold.container);
    var paginationSummaryNode = paginationUi.querySelector('[data-master-page-summary="1"]');
    var paginationListNode = paginationUi.querySelector('[data-master-page-list="1"]');
    var errorBox = root.querySelector('[data-master-insights-error="1"]');
    var resultCountNode = root.querySelector('[data-master-results-count="1"]');
    var statNodes = root.querySelectorAll('[data-stat-value]');
    var requestSequence = 0;
    var page = 1;
    var perPage = 10;

    var debouncedRefresh = debounce(function () {
      page = 1;
      requestAndRender();
    }, 220);

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      page = 1;
      requestAndRender();
    });

    form.addEventListener('change', function (event) {
      var target = event.target || null;
      var fieldName = target ? (target.getAttribute('name') || '').trim() : '';
      if (fieldName !== 'page' && fieldName !== 'per_page') {
        page = 1;
      }
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
        page = 1;
        requestAndRender();
      });
    }

    root.addEventListener('gac:vehicle-attributes-ready', function () {
      page = 1;
      requestAndRender();
    });

    requestAndRender();

    function ensureMasterPaginationUi(tableContainer) {
      var existing = root.querySelector('[data-master-pagination="1"]');
      if (existing) {
        return existing;
      }

      var paginationNode = document.createElement('div');
      paginationNode.className = 'gac-table-pagination';
      paginationNode.setAttribute('data-master-pagination', '1');
      paginationNode.innerHTML =
        '<small class="gac-table-summary" data-master-page-summary="1"></small>' +
        '<ul class="pagination pagination-sm mb-0" data-master-page-list="1"></ul>';

      tableContainer.insertAdjacentElement('afterend', paginationNode);
      return paginationNode;
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
      renderTableInfoRow(tableBody, 'Loading filtered data...', 'text-muted', table);

      var params = serializeForm(form);
      params.set('page', String(page));
      params.set('per_page', String(perPage));
      params.set('_ts', String(Date.now()));

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
            return {
              ok: response.ok,
              payload: safeParseJson(text)
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
            renderTableInfoRow(tableBody, 'Unable to load records.', 'text-danger', table);
            updateTablePaginationUi(paginationSummaryNode, paginationListNode, 0, 1, perPage, 1, function () {});
            return;
          }

          if (typeof payload.table_rows_html === 'string' && payload.table_rows_html !== '') {
            tableBody.innerHTML = payload.table_rows_html;
          } else {
            renderTableInfoRow(tableBody, 'No records found.', 'text-muted', table);
          }

          var paginationPayload = payload.pagination && typeof payload.pagination === 'object'
            ? payload.pagination
            : {};
          var totalRecords = parseInt(paginationPayload.total_records || payload.rows_count || '0', 10);
          if (!isFinite(totalRecords) || totalRecords < 0) {
            totalRecords = 0;
          }

          var nextPerPage = parseInt(paginationPayload.per_page || String(perPage), 10);
          if (!isFinite(nextPerPage) || nextPerPage <= 0) {
            nextPerPage = 10;
          }

          var totalPages = parseInt(paginationPayload.total_pages || '0', 10);
          if (!isFinite(totalPages) || totalPages <= 0) {
            totalPages = Math.max(1, Math.ceil(totalRecords / nextPerPage));
          }

          var nextPage = parseInt(paginationPayload.page || String(page), 10);
          if (!isFinite(nextPage) || nextPage <= 0) {
            nextPage = 1;
          }
          if (nextPage > totalPages) {
            nextPage = totalPages;
          }

          page = nextPage;
          perPage = nextPerPage;

          if (resultCountNode) {
            resultCountNode.textContent = String(totalRecords);
          }

          updateStats(payload.stats || {});
          updateTablePaginationUi(paginationSummaryNode, paginationListNode, totalRecords, page, perPage, totalPages, function (nextPageValue) {
            if (nextPageValue === page) {
              return;
            }
            page = nextPageValue;
            requestAndRender();
          });
          applyTableDecorators(table);
          bindConfirmForms();
        })
        .catch(function () {
          if (currentSequence !== requestSequence) {
            return;
          }
          showError('Unable to load filtered data.');
          renderTableInfoRow(tableBody, 'Unable to load records.', 'text-danger', table);
          updateTablePaginationUi(paginationSummaryNode, paginationListNode, 0, 1, perPage, 1, function () {});
        });
    }
  }
}

function initStandardizedTables() {
  var tables = document.querySelectorAll('.app-content table.table');
  if (!tables || tables.length === 0) {
    return;
  }

  for (var i = 0; i < tables.length; i++) {
    var table = tables[i];
    if (!shouldEnhanceTable(table)) {
      continue;
    }

    var scaffold = ensureTableScaffold(table);
    applyTableDecorators(table);

    if (isMasterInsightsTable(table)) {
      table.setAttribute('data-gac-table-init', '1');
      continue;
    }

    if (table.getAttribute('data-gac-table-init') === '1') {
      continue;
    }

    initLocalTableController(table, scaffold);
    table.setAttribute('data-gac-table-init', '1');
  }
}

function shouldEnhanceTable(table) {
  if (!table || table.getAttribute('data-gac-table-skip') === '1') {
    return false;
  }
  if (table.id === 'purchase-item-table' || table.id === 'purchase-edit-item-table') {
    return false;
  }
  if (!table.tBodies || table.tBodies.length === 0) {
    return false;
  }
  if (table.querySelector('tbody input[type="text"], tbody input[type="number"], tbody input[type="date"], tbody input[type="datetime-local"], tbody input[type="time"], tbody input[type="email"], tbody input[type="search"], tbody input[type="url"], tbody input[type="tel"], tbody input[type="password"], tbody select, tbody textarea')) {
    return false;
  }
  return true;
}

function isMasterInsightsTable(table) {
  return !!table.querySelector('tbody[data-master-table-body="1"]');
}

function ensureTableScaffold(table) {
  var container = ensureTableResponsiveContainer(table);
  var rowsCount = table.tBodies && table.tBodies.length > 0 ? table.tBodies[0].rows.length : 0;
  container.classList.add('gac-table-responsive');
  if (rowsCount > 10) {
    container.classList.add('gac-table-scroll-y');
  }
  table.classList.add('gac-data-table');
  ensureTableCardTitleStyle(table);

  return {
    container: container,
    host: container.parentElement || container
  };
}

function ensureTableResponsiveContainer(table) {
  var container = table.closest('.table-responsive');
  if (container && container.contains(table)) {
    return container;
  }

  var wrapper = document.createElement('div');
  wrapper.className = 'table-responsive';
  if (table.parentNode) {
    table.parentNode.insertBefore(wrapper, table);
  }
  wrapper.appendChild(table);
  return wrapper;
}

function ensureTableCardTitleStyle(table) {
  var card = table.closest('.card');
  if (!card) {
    return;
  }
  var title = card.querySelector('.card-header .card-title');
  if (!title) {
    return;
  }
  title.classList.add('fw-bold');
}

function initLocalTableController(table, scaffold) {
  var tableBody = table.tBodies[0];
  if (!tableBody) {
    return;
  }

  var allRows = [];
  for (var idx = 0; idx < tableBody.rows.length; idx++) {
    var row = tableBody.rows[idx];
    if (!row || !row.cells || row.cells.length === 0) {
      continue;
    }
    if (row.cells.length === 1 && parseInt(row.cells[0].getAttribute('colspan') || '1', 10) > 1) {
      continue;
    }
    allRows.push(row);
  }

  var toolbar = document.createElement('div');
  toolbar.className = 'gac-table-toolbar';
  toolbar.innerHTML =
    '<div class="input-group input-group-sm gac-table-search">' +
      '<span class="input-group-text"><i class="bi bi-search"></i></span>' +
      '<input type="search" class="form-control" placeholder="Search table..." aria-label="Search table rows">' +
    '</div>' +
    '<small class="gac-table-summary" data-gac-table-summary="1"></small>';
  scaffold.container.insertAdjacentElement('beforebegin', toolbar);

  var paginationNode = document.createElement('div');
  paginationNode.className = 'gac-table-pagination';
  paginationNode.innerHTML =
    '<small class="gac-table-summary" data-gac-table-pagination-summary="1"></small>' +
    '<ul class="pagination pagination-sm mb-0" data-gac-table-pagination-list="1"></ul>';
  scaffold.container.insertAdjacentElement('afterend', paginationNode);

  var searchInput = toolbar.querySelector('input[type="search"]');
  var toolbarSummaryNode = toolbar.querySelector('[data-gac-table-summary="1"]');
  var paginationSummaryNode = paginationNode.querySelector('[data-gac-table-pagination-summary="1"]');
  var paginationListNode = paginationNode.querySelector('[data-gac-table-pagination-list="1"]');

  var state = {
    page: 1,
    perPage: 10,
    search: ''
  };

  var debouncedSearch = debounce(function () {
    state.search = normalizeSearchTerm(searchInput ? searchInput.value : '');
    state.page = 1;
    render();
  }, 180);

  if (searchInput) {
    searchInput.addEventListener('input', debouncedSearch);
  }

  render();

  function render() {
    var filteredRows = [];
    var searchTerm = state.search;

    for (var rowIndex = 0; rowIndex < allRows.length; rowIndex++) {
      var sourceRow = allRows[rowIndex];
      if (!sourceRow) {
        continue;
      }
      if (searchTerm !== '' && rowToSearchText(sourceRow).indexOf(searchTerm) === -1) {
        continue;
      }
      filteredRows.push(sourceRow);
    }

    var totalRecords = filteredRows.length;
    var totalPages = Math.max(1, Math.ceil(totalRecords / state.perPage));
    if (state.page > totalPages) {
      state.page = totalPages;
    }

    var startIndex = (state.page - 1) * state.perPage;
    var endIndex = Math.min(startIndex + state.perPage, totalRecords);
    var visibleRows = filteredRows.slice(startIndex, endIndex);

    tableBody.innerHTML = '';
    if (visibleRows.length === 0) {
      var emptyMessage = searchTerm === '' ? 'No records found.' : 'No matching records found.';
      renderTableInfoRow(tableBody, emptyMessage, 'text-muted', table);
    } else {
      for (var visibleIndex = 0; visibleIndex < visibleRows.length; visibleIndex++) {
        tableBody.appendChild(visibleRows[visibleIndex]);
      }
    }

    if (toolbarSummaryNode) {
      toolbarSummaryNode.textContent = buildTableSummaryText(totalRecords, state.page, state.perPage);
    }
    updateTablePaginationUi(paginationSummaryNode, paginationListNode, totalRecords, state.page, state.perPage, totalPages, function (nextPageValue) {
      state.page = nextPageValue;
      render();
    });

    applyTableDecorators(table);
    bindConfirmForms();
  }
}

function resolveTableFromBody(tableBody) {
  if (!tableBody) {
    return null;
  }

  var node = tableBody;
  while (node && node.tagName !== 'TABLE') {
    node = node.parentElement;
  }
  return node && node.tagName === 'TABLE' ? node : null;
}

function renderTableInfoRow(tableBody, message, cssClass, table) {
  if (!tableBody) {
    return;
  }
  var colCount = detectTableColumnCount(tableBody, table);
  var rowClass = cssClass || 'text-muted';
  tableBody.innerHTML = '<tr data-gac-info-row="1"><td colspan="' + colCount + '" class="text-center ' + rowClass + ' py-4 gac-table-info-row">' + escapeHtml(message) + '</td></tr>';
}

function detectTableColumnCount(tableBody, table) {
  if (!tableBody) {
    return 1;
  }

  var configured = parseInt(tableBody.getAttribute('data-table-colspan') || '0', 10);
  if (configured > 0) {
    return configured;
  }

  var resolvedTable = table || resolveTableFromBody(tableBody);
  if (!resolvedTable) {
    return 1;
  }

  var headers = resolvedTable.querySelectorAll('thead th');
  return headers && headers.length > 0 ? headers.length : 1;
}

function rowToSearchText(row) {
  if (!row) {
    return '';
  }
  return normalizeSearchTerm(row.textContent || '');
}

function applyTableDecorators(table) {
  if (!table) {
    return;
  }

  var headerCells = table.querySelectorAll('thead th');
  if (!headerCells || headerCells.length === 0 || !table.tBodies || table.tBodies.length === 0) {
    return;
  }

  var keyColumns = {};
  var numberColumns = {};
  var statusColumns = {};

  for (var index = 0; index < headerCells.length; index++) {
    var headerLabel = normalizeSearchTerm(headerCells[index].textContent || '');
    if (headerLabel === '') {
      continue;
    }

    if (/(invoice|job|customer|vehicle|registration|payment status|amount|reference|ref no|number|part name|part sku|sku)/.test(headerLabel)) {
      keyColumns[index] = true;
    }
    if (/(qty|quantity|amount|price|rate|cost|tax|gst|stock|balance|total|paid|due|km|count|salary|emi|deduction|net|gross|hours|percent|%|id)/.test(headerLabel)) {
      numberColumns[index] = true;
    }
    if (/(status|state|payment)/.test(headerLabel)) {
      statusColumns[index] = true;
    }
  }

  for (var bodyIndex = 0; bodyIndex < table.tBodies.length; bodyIndex++) {
    var body = table.tBodies[bodyIndex];
    for (var rowIndex = 0; rowIndex < body.rows.length; rowIndex++) {
      var row = body.rows[rowIndex];
      if (!row || row.getAttribute('data-gac-info-row') === '1') {
        continue;
      }

      for (var colIndex = 0; colIndex < row.cells.length; colIndex++) {
        var cell = row.cells[colIndex];
        if (!cell) {
          continue;
        }
        cell.classList.remove('gac-cell-key', 'gac-cell-number');

        if (keyColumns[colIndex]) {
          cell.classList.add('gac-cell-key');
        }
        if (numberColumns[colIndex]) {
          cell.classList.add('gac-cell-number');
        }
        if (statusColumns[colIndex]) {
          decorateStatusCell(cell);
        }
      }
    }
  }
}

function decorateStatusCell(cell) {
  if (!cell || cell.querySelector('.badge, .btn, form, a, input, select, textarea')) {
    return;
  }

  var rawText = collapseWhitespace(cell.textContent || '').trim();
  if (rawText === '') {
    return;
  }

  var normalizedText = rawText.toUpperCase();
  var badgeClass = resolveStatusBadgeClass(normalizedText);
  if (badgeClass === '') {
    return;
  }

  cell.textContent = '';
  var badge = document.createElement('span');
  badge.className = 'badge text-bg-' + badgeClass;
  badge.textContent = formatStatusLabel(rawText);
  cell.appendChild(badge);
}

function resolveStatusBadgeClass(value) {
  var normalized = String(value || '').trim().toUpperCase();
  if (normalized === '') {
    return '';
  }

  if (normalized === 'ACTIVE' || normalized === 'PAID' || normalized === 'SUCCESS' || normalized === 'COMPLETED') {
    return 'success';
  }
  if (normalized === 'PENDING' || normalized === 'DUE' || normalized === 'LOW' || normalized === 'OPEN') {
    return 'warning';
  }
  if (normalized === 'CANCELLED' || normalized === 'CANCELED' || normalized === 'DELETED' || normalized === 'FAILED' || normalized === 'OUT OF STOCK' || normalized === 'OUT') {
    return 'danger';
  }
  if (normalized === 'CLOSED' || normalized === 'INACTIVE' || normalized === 'DRAFT') {
    return 'secondary';
  }

  return '';
}

function formatStatusLabel(value) {
  var words = String(value || '')
    .replace(/[_-]+/g, ' ')
    .trim()
    .split(/\s+/);

  if (!words || words.length === 0) {
    return '';
  }

  for (var i = 0; i < words.length; i++) {
    var word = words[i];
    words[i] = word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
  }
  return words.join(' ');
}

function buildTableSummaryText(totalRecords, page, perPage) {
  if (!isFinite(totalRecords) || totalRecords <= 0) {
    return 'Showing 0 of 0';
  }

  var start = (page - 1) * perPage + 1;
  var end = Math.min(start + perPage - 1, totalRecords);
  return 'Showing ' + start + '-' + end + ' of ' + totalRecords;
}

function updateTablePaginationUi(summaryNode, listNode, totalRecords, page, perPage, totalPages, onPageClick) {
  if (summaryNode) {
    summaryNode.textContent = buildTableSummaryText(totalRecords, page, perPage);
  }
  if (!listNode) {
    return;
  }

  listNode.innerHTML = '';
  if (totalPages <= 1) {
    listNode.appendChild(buildPageControlItem('Prev', 1, false, true, onPageClick));
    listNode.appendChild(buildPageControlItem('1', 1, true, true, onPageClick));
    listNode.appendChild(buildPageControlItem('Next', 1, false, true, onPageClick));
    return;
  }

  listNode.appendChild(buildPageControlItem('Prev', page - 1, false, page <= 1, onPageClick));

  var windowStart = Math.max(1, page - 2);
  var windowEnd = Math.min(totalPages, page + 2);

  if (windowStart > 1) {
    listNode.appendChild(buildPageControlItem('1', 1, page === 1, false, onPageClick));
    if (windowStart > 2) {
      listNode.appendChild(buildEllipsisPageItem());
    }
  }

  for (var number = windowStart; number <= windowEnd; number++) {
    listNode.appendChild(buildPageControlItem(String(number), number, number === page, false, onPageClick));
  }

  if (windowEnd < totalPages) {
    if (windowEnd < totalPages - 1) {
      listNode.appendChild(buildEllipsisPageItem());
    }
    listNode.appendChild(buildPageControlItem(String(totalPages), totalPages, page === totalPages, false, onPageClick));
  }

  listNode.appendChild(buildPageControlItem('Next', page + 1, false, page >= totalPages, onPageClick));
}

function buildPageControlItem(label, pageValue, isActive, isDisabled, onPageClick) {
  var item = document.createElement('li');
  item.className = 'page-item' + (isActive ? ' active' : '') + (isDisabled ? ' disabled' : '');

  var button = document.createElement('button');
  button.type = 'button';
  button.className = 'page-link';
  button.textContent = label;
  button.disabled = !!isDisabled;
  if (!isDisabled) {
    button.addEventListener('click', function () {
      if (typeof onPageClick === 'function') {
        onPageClick(pageValue);
      }
    });
  }
  item.appendChild(button);

  return item;
}

function buildEllipsisPageItem() {
  var item = document.createElement('li');
  item.className = 'page-item disabled';
  var span = document.createElement('span');
  span.className = 'page-link';
  span.textContent = '...';
  item.appendChild(span);
  return item;
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
