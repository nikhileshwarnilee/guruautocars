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
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')); ?>" />
  </head>
  <body
    class="layout-fixed sidebar-expand-lg bg-body-tertiary"
    data-inline-customer-form-url="<?= e(url('modules/customers/inline_form.php')); ?>"
  >
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
              <li class="nav-item me-2 d-none d-md-block">
                <span class="badge text-bg-light border">
                  <?= e((string) ($user['company_name'] ?? '')); ?>
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
                  <a href="<?= e(url('logout.php')); ?>" class="dropdown-item">
                    <i class="bi bi-box-arrow-right me-1"></i> Logout
                  </a>
                </div>
              </li>
            <?php endif; ?>
          </ul>
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
