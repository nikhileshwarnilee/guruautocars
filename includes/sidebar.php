<?php
declare(strict_types=1);

$activeMenu = $active_menu ?? '';
$canViewOrganization = has_permission('company.manage') || has_permission('garage.manage') || has_permission('staff.manage');
$isOrganizationOpen = is_menu_group_open('organization.', $activeMenu);
?>
<aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
  <div class="sidebar-brand">
    <a href="<?= e(url('dashboard.php')); ?>" class="brand-link">
      <img src="<?= e(url('assets/images/AdminLTELogo.png')); ?>" alt="Guru Auto Cars" class="brand-image opacity-75 shadow" />
      <span class="brand-text fw-light">Guru Auto Cars</span>
    </a>
  </div>
  <div class="sidebar-wrapper">
    <nav class="mt-2">
      <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="menu" data-accordion="false">
        <li class="nav-item">
          <a href="<?= e(url('dashboard.php')); ?>" class="nav-link <?= e(is_active_menu('dashboard', $activeMenu)); ?>">
            <i class="nav-icon bi bi-speedometer2"></i>
            <p>Dashboard</p>
          </a>
        </li>

        <?php if ($canViewOrganization): ?>
          <li class="nav-item <?= $isOrganizationOpen ? 'menu-open' : ''; ?>">
            <a href="#" class="nav-link <?= $isOrganizationOpen ? 'active' : ''; ?>">
              <i class="nav-icon bi bi-diagram-3"></i>
              <p>
                Organization
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (has_permission('company.manage')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/organization/companies.php')); ?>" class="nav-link <?= e(is_active_menu('organization.companies', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-circle"></i>
                    <p>Companies</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('garage.manage')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/organization/garages.php')); ?>" class="nav-link <?= e(is_active_menu('organization.garages', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-circle"></i>
                    <p>Garages</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('staff.manage')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/organization/staff.php')); ?>" class="nav-link <?= e(is_active_menu('organization.staff', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-circle"></i>
                    <p>Staff</p>
                  </a>
                </li>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <?php if (has_permission('customer.view')): ?>
          <li class="nav-item">
            <a href="<?= e(url('modules/customers/index.php')); ?>" class="nav-link <?= e(is_active_menu('customers', $activeMenu)); ?>">
              <i class="nav-icon bi bi-people"></i>
              <p>Customers</p>
            </a>
          </li>
        <?php endif; ?>

        <?php if (has_permission('vehicle.view')): ?>
          <li class="nav-item">
            <a href="<?= e(url('modules/vehicles/index.php')); ?>" class="nav-link <?= e(is_active_menu('vehicles', $activeMenu)); ?>">
              <i class="nav-icon bi bi-car-front"></i>
              <p>Vehicles</p>
            </a>
          </li>
        <?php endif; ?>

        <?php if (has_permission('job.view')): ?>
          <li class="nav-item">
            <a href="<?= e(url('modules/jobs/index.php')); ?>" class="nav-link <?= e(is_active_menu('jobs', $activeMenu)); ?>">
              <i class="nav-icon bi bi-card-checklist"></i>
              <p>Job Cards</p>
            </a>
          </li>
        <?php endif; ?>

        <?php if (has_permission('inventory.view')): ?>
          <li class="nav-item">
            <a href="<?= e(url('modules/inventory/index.php')); ?>" class="nav-link <?= e(is_active_menu('inventory', $activeMenu)); ?>">
              <i class="nav-icon bi bi-box-seam"></i>
              <p>Inventory</p>
            </a>
          </li>
        <?php endif; ?>

        <?php if (has_permission('invoice.view')): ?>
          <li class="nav-item">
            <a href="<?= e(url('modules/billing/index.php')); ?>" class="nav-link <?= e(is_active_menu('billing', $activeMenu)); ?>">
              <i class="nav-icon bi bi-receipt"></i>
              <p>Billing</p>
            </a>
          </li>
        <?php endif; ?>

        <?php if (has_permission('report.view')): ?>
          <li class="nav-item">
            <a href="<?= e(url('modules/reports/index.php')); ?>" class="nav-link <?= e(is_active_menu('reports', $activeMenu)); ?>">
              <i class="nav-icon bi bi-bar-chart"></i>
              <p>Reports</p>
            </a>
          </li>
        <?php endif; ?>
      </ul>
    </nav>
  </div>
</aside>
