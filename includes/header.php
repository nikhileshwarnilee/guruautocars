<?php
declare(strict_types=1);

$pageTitle = $page_title ?? 'Dashboard';
$activeMenu = $active_menu ?? '';
$user = current_user();
$garages = $user['garages'] ?? [];
$currentGarageId = active_garage_id();
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');

if (APP_BASE_URL !== '' && str_starts_with($requestUri, APP_BASE_URL)) {
    $requestUri = substr($requestUri, strlen(APP_BASE_URL));
}

$requestPathForRedirect = ltrim($requestUri, '/');
if ($requestPathForRedirect === '') {
    $requestPathForRedirect = 'dashboard.php';
}

$flashMessages = flash_pull_all();
$globalVehicleSearchEnabled = $user !== null && has_permission('vehicle.view');
$globalVehicleSearchApiUrl = $globalVehicleSearchEnabled ? url('modules/vehicles/search_api.php') : '';
$headerCompanyId = (int) ($user['company_id'] ?? active_company_id());
$headerCompanyLogoUrl = $headerCompanyId > 0 ? company_logo_url($headerCompanyId, active_garage_id()) : null;
$showTxnCleanupTestButton = $user !== null && strtolower(trim((string) ($user['role_key'] ?? ''))) === 'super_admin';
$quickAccessLinks = [];
if (has_permission('job.view')) {
    $quickAccessLinks[] = [
        'label' => 'Job Card',
        'icon' => 'bi bi-card-checklist',
        'url' => url('modules/jobs/index.php'),
        'active_keys' => ['jobs'],
    ];
}
if (has_permission('billing.view') || has_permission('invoice.view')) {
    $quickAccessLinks[] = [
        'label' => 'Billing',
        'icon' => 'bi bi-receipt-cutoff',
        'url' => url('modules/billing/index.php'),
        'active_keys' => ['billing'],
    ];
}
if (has_permission('estimate.view')) {
    $quickAccessLinks[] = [
        'label' => 'Estimate',
        'icon' => 'bi bi-file-earmark-text',
        'url' => url('modules/estimates/index.php'),
        'active_keys' => ['estimates'],
    ];
}
if (has_permission('inventory.view')) {
    $quickAccessLinks[] = [
        'label' => 'Stock',
        'icon' => 'bi bi-arrow-left-right',
        'url' => url('modules/inventory/index.php'),
        'active_keys' => ['inventory'],
    ];
}
$appCssPath = __DIR__ . '/../assets/css/app.css';
$appCssVersion = (string) @md5_file($appCssPath);
if ($appCssVersion === '') {
    $appCssVersion = (string) @filemtime($appCssPath);
}
if ($appCssVersion === '') {
    $appCssVersion = (string) time();
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= e($pageTitle); ?> | <?= e(APP_SHORT_NAME); ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/plugins/bootstrap-icons/bootstrap-icons.min.css')); ?>" />
    <link rel="stylesheet" href="<?= e(url('assets/plugins/overlayscrollbars/overlayscrollbars.min.css')); ?>" />
    <link rel="stylesheet" href="<?= e(url('assets/css/adminlte.min.css')); ?>" />
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css?v=' . $appCssVersion)); ?>" />
  </head>
  <body
    class="layout-fixed sidebar-expand-lg bg-body-tertiary"
    data-inline-customer-form-url="<?= e(url('modules/customers/inline_form.php')); ?>"
    data-inline-vehicle-form-url="<?= e(url('modules/vehicles/inline_form.php')); ?>"
    data-active-menu="<?= e($activeMenu); ?>"
    data-page-title="<?= e($pageTitle); ?>"
    data-dashboard-url="<?= e(url('dashboard.php')); ?>"
  >
    <script>
      (function () {
        try {
          if (window.localStorage) {
            var storedSidebarWidth = parseInt(window.localStorage.getItem('gac.sidebar.width') || '', 10);
            if (!isNaN(storedSidebarWidth)) {
              var minSidebarWidth = 260;
              var maxSidebarWidth = 360;
              if (storedSidebarWidth < minSidebarWidth) {
                storedSidebarWidth = minSidebarWidth;
              } else if (storedSidebarWidth > maxSidebarWidth) {
                storedSidebarWidth = maxSidebarWidth;
              }
              document.documentElement.style.setProperty('--lte-sidebar-width', String(storedSidebarWidth) + 'px');
            }
          }

          if (window.localStorage && window.localStorage.getItem('gac.sidebar.collapsed') === '1') {
            document.body.classList.add('sidebar-collapse');
            document.body.classList.remove('sidebar-open');
          }
        } catch (error) {
          // Ignore storage unavailability.
        }
      })();
    </script>
    <div class="app-wrapper">
      <nav class="app-header navbar navbar-expand bg-body">
        <div class="container-fluid">
          <ul class="navbar-nav">
            <li class="nav-item">
              <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button">
                <i class="bi bi-list"></i>
              </a>
            </li>
          </ul>
          <?php if ($globalVehicleSearchEnabled): ?>
            <form
              class="gac-global-search flex-grow-1 mx-2"
              data-global-vehicle-search-root="1"
              data-global-vehicle-search-endpoint="<?= e($globalVehicleSearchApiUrl); ?>"
              role="search"
              autocomplete="off"
              onsubmit="return false;"
            >
              <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input
                  type="search"
                  class="form-control"
                  data-global-vehicle-search-input="1"
                  placeholder="Search vehicle number or customer mobile"
                  aria-label="Search vehicles by registration or customer mobile"
                />
                <button
                  type="button"
                  class="btn btn-outline-secondary d-none"
                  data-global-vehicle-search-clear="1"
                  aria-label="Clear vehicle search"
                >
                  <i class="bi bi-x-lg"></i>
                </button>
              </div>
              <div
                class="list-group d-none gac-global-search-results"
                data-global-vehicle-search-results="1"
                role="listbox"
                aria-label="Vehicle search results"
              ></div>
            </form>
          <?php endif; ?>
          <ul class="navbar-nav ms-auto align-items-center">
            <?php if ($user !== null): ?>
              <?php if ($showTxnCleanupTestButton): ?>
                <li class="nav-item me-2">
                  <form
                    method="post"
                    action="<?= e(url('database/cleanup_transactional_data_keep_masters.php')); ?>"
                    target="_blank"
                    class="d-flex align-items-center"
                    onsubmit="return window.confirm('Run transactional cleanup and zero inventory quantities? This is destructive and intended only for test data reset.');"
                  >
                    <?= csrf_field(); ?>
                    <input type="hidden" name="run_cleanup" value="1" />
                    <button type="submit" class="btn btn-sm btn-outline-danger">Test Cleanup</button>
                  </form>
                </li>
              <?php endif; ?>
              <li class="nav-item me-2 d-none d-md-block">
                <span class="d-inline-flex align-items-center gap-2">
                  <?php if ($headerCompanyLogoUrl !== null): ?>
                    <img
                      src="<?= e($headerCompanyLogoUrl); ?>"
                      alt="Business Logo"
                      class="rounded border bg-white p-1"
                      style="height: 26px; width: auto;"
                    />
                  <?php endif; ?>
                  <span class="badge text-bg-light border">
                    <?= e((string) ($user['company_name'] ?? '')); ?>
                  </span>
                </span>
              </li>
              <?php if (count($garages) > 1): ?>
                <li class="nav-item me-2">
                  <form method="post" class="d-flex align-items-center gap-2">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="switch_garage" />
                    <input type="hidden" name="_redirect" value="<?= e($requestPathForRedirect); ?>" />
                    <select class="form-select form-select-sm" name="garage_id" onchange="if (this.form && typeof this.form.requestSubmit === 'function') { this.form.requestSubmit(); } else if (this.form) { this.form.submit(); }">
                      <?php foreach ($garages as $garage): ?>
                        <option value="<?= (int) $garage['id']; ?>" <?= ((int) $garage['id'] === $currentGarageId) ? 'selected' : ''; ?>>
                          <?= e((string) $garage['name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                </li>
              <?php elseif (!empty($user['primary_garage_name'])): ?>
                <li class="nav-item me-2 d-none d-md-block">
                  <span class="text-muted small"><?= e((string) $user['primary_garage_name']); ?></span>
                </li>
              <?php endif; ?>
              <li class="nav-item dropdown">
                <a class="nav-link" data-bs-toggle="dropdown" href="#">
                  <i class="bi bi-person-circle"></i>
                </a>
                <div class="dropdown-menu dropdown-menu-end">
                  <span class="dropdown-item-text fw-semibold"><?= e((string) ($user['name'] ?? 'User')); ?></span>
                  <span class="dropdown-item-text text-muted small"><?= e((string) ($user['role_name'] ?? '')); ?></span>
                  <div class="dropdown-divider"></div>
                  <a href="<?= e(url('modules/system/profile.php')); ?>" class="dropdown-item">
                    <i class="bi bi-person-vcard me-1"></i> My Profile
                  </a>
                  <div class="dropdown-divider"></div>
                  <a href="<?= e(url('logout.php')); ?>" class="dropdown-item">
                    <i class="bi bi-box-arrow-right me-1"></i> Logout
                  </a>
                </div>
              </li>
            <?php endif; ?>
          </ul>
          <?php if ($quickAccessLinks !== []): ?>
            <div class="gac-quick-access-wrap">
              <nav class="gac-quick-access-nav" aria-label="Quick access">
                <?php foreach ($quickAccessLinks as $quickLink): ?>
                  <?php
                  $activeKeys = $quickLink['active_keys'] ?? [];
                  $isQuickLinkActive = in_array($activeMenu, $activeKeys, true);
                  ?>
                  <a
                    href="<?= e((string) ($quickLink['url'] ?? '#')); ?>"
                    class="gac-quick-access-link <?= $isQuickLinkActive ? 'active' : ''; ?>"
                  >
                    <i class="<?= e((string) ($quickLink['icon'] ?? 'bi bi-lightning')); ?>" aria-hidden="true"></i>
                    <span><?= e((string) ($quickLink['label'] ?? 'Quick')); ?></span>
                  </a>
                <?php endforeach; ?>
              </nav>
            </div>
          <?php endif; ?>
        </div>
      </nav>
      <?php $normalizedFlashMessages = flash_messages_normalize($flashMessages); ?>
      <div id="gac-flash-container" class="gac-toast-stack position-fixed top-0 end-0 p-3" aria-live="polite" aria-atomic="true">
        <script type="application/json" id="gac-initial-flash-data"><?=
            json_encode(
                $normalizedFlashMessages,
                JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
            );
        ?></script>
      </div>
