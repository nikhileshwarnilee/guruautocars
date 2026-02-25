<?php
declare(strict_types=1);

$activeMenu = $active_menu ?? '';
$isOperationsOpen = $activeMenu === 'jobs'
    || $activeMenu === 'jobs.queue'
    || $activeMenu === 'jobs.maintenance_reminders'
    || $activeMenu === 'estimates'
    || is_menu_group_open('outsourced.', $activeMenu);
$isPurchaseOpen = is_menu_group_open('purchases.', $activeMenu)
    || is_menu_group_open('vendors.', $activeMenu);
$isInventoryOpen = $activeMenu === 'inventory'
    || $activeMenu === 'inventory.returns';
$isSalesOpen = $activeMenu === 'billing'
    || $activeMenu === 'billing.credit_notes'
    || $activeMenu === 'customers'
    || $activeMenu === 'vehicles';
$isFinanceOpen = is_menu_group_open('finance.', $activeMenu);
$isVehicleIntelligenceOpen = $activeMenu === 'jobs.maintenance_setup'
    || $activeMenu === 'inventory.parts_master'
    || $activeMenu === 'inventory.categories'
    || $activeMenu === 'inventory.units'
    || is_menu_group_open('services.', $activeMenu)
    || is_menu_group_open('vis.', $activeMenu);
$isReportsOpen = is_menu_group_open('reports', $activeMenu);
$isUsersPermissionsOpen = is_menu_group_open('people.', $activeMenu);
$isAdministrationOpen = is_menu_group_open('organization.', $activeMenu)
    || is_menu_group_open('system.', $activeMenu)
    || is_menu_group_open('billing.', $activeMenu);
$servicesMenuActive = ($activeMenu === 'services.master' || $activeMenu === 'services.categories') ? 'active' : '';
$partsMenuActive = ($activeMenu === 'inventory.parts_master' || $activeMenu === 'inventory.categories' || $activeMenu === 'inventory.units') ? 'active' : '';
$canViewReports = has_permission('reports.view') || has_permission('report.view');
$canViewFinancialReports = has_permission('reports.financial') || has_permission('financial.reports') || has_permission('gst.reports');
$canViewUsersPermissions = has_permission('staff.view')
    || has_permission('staff.manage')
    || has_permission('role.view')
    || has_permission('permission.view');
$canViewAdministration = has_permission('company.manage')
    || has_permission('garage.manage')
    || has_permission('financial_year.view')
    || has_permission('settings.view')
    || has_permission('settings.manage')
    || has_permission('invoice.manage')
    || has_permission('audit.view')
    || has_permission('export.data')
    || has_permission('backup.manage');
$sidebarUser = current_user();
$sidebarCompanyId = (int) ($sidebarUser['company_id'] ?? active_company_id());
$sidebarCompanyLogo = $sidebarCompanyId > 0 ? company_logo_url($sidebarCompanyId, active_garage_id()) : null;
$sidebarBrandLogo = $sidebarCompanyLogo !== null ? $sidebarCompanyLogo : url('assets/images/AdminLTELogo.png');
$sidebarBrandName = trim((string) ($sidebarUser['company_name'] ?? APP_SHORT_NAME));
if ($sidebarBrandName === '') {
    $sidebarBrandName = APP_SHORT_NAME;
}
?>
<aside class="app-sidebar gac-sidebar-theme">
  <div class="sidebar-brand">
    <a href="<?= e(url('dashboard.php')); ?>" class="brand-link">
      <img src="<?= e($sidebarBrandLogo); ?>" alt="<?= e($sidebarBrandName); ?>" class="brand-image opacity-75" />
      <span class="brand-text fw-light"><?= e($sidebarBrandName); ?></span>
    </a>
  </div>
  <div class="sidebar-wrapper">
    <nav class="mt-2">
      <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="menu" data-accordion="true">
        <li class="nav-item">
          <a href="<?= e(url('dashboard.php')); ?>" class="nav-link <?= e(is_active_menu('dashboard', $activeMenu)); ?>">
            <i class="nav-icon bi bi-speedometer2"></i>
            <p>Dashboard</p>
          </a>
        </li>

        <?php if (has_permission('job.view') || has_permission('estimate.view') || has_permission('outsourced.view')): ?>
          <li class="nav-item <?= $isOperationsOpen ? 'menu-open' : ''; ?>">
            <a href="#" class="nav-link <?= $isOperationsOpen ? 'active' : ''; ?>">
              <i class="nav-icon bi bi-gear-wide-connected"></i>
              <p>
                Operations
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (has_permission('job.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/jobs/index.php')); ?>" class="nav-link <?= e(is_active_menu('jobs', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-card-checklist"></i>
                    <p>Job Card</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('estimate.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/estimates/index.php')); ?>" class="nav-link <?= e(is_active_menu('estimates', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-file-earmark-text"></i>
                    <p>Estimates</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('outsourced.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/outsourced/index.php')); ?>" class="nav-link <?= e(is_active_menu('outsourced.index', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-hammer"></i>
                    <p>Outsourced Works</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('job.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/jobs/maintenance_reminders.php')); ?>" class="nav-link <?= e(is_active_menu('jobs.maintenance_reminders', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-bell"></i>
                    <p>Service Reminders</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="<?= e(url('modules/jobs/queue_board.php')); ?>" class="nav-link <?= e(is_active_menu('jobs.queue', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-kanban"></i>
                    <p>Vehicle Queue Board</p>
                  </a>
                </li>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <?php if (has_permission('purchase.view') || has_permission('vendor.view')): ?>
          <li class="nav-item <?= $isPurchaseOpen ? 'menu-open' : ''; ?>">
            <a href="#" class="nav-link <?= $isPurchaseOpen ? 'active' : ''; ?>">
              <i class="nav-icon bi bi-bag-check"></i>
              <p>
                Purchase
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (has_permission('purchase.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/purchases/index.php')); ?>" class="nav-link <?= e(is_active_menu('purchases.index', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-bag"></i>
                    <p>Purchases</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('vendor.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/vendors/index.php')); ?>" class="nav-link <?= e(is_active_menu('vendors.master', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-truck"></i>
                    <p>Vendors</p>
                  </a>
                </li>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <?php if (has_permission('inventory.view') || has_permission('billing.view') || has_permission('purchase.view') || has_permission('report.view')): ?>
          <li class="nav-item <?= $isInventoryOpen ? 'menu-open' : ''; ?>">
            <a href="#" class="nav-link <?= $isInventoryOpen ? 'active' : ''; ?>">
              <i class="nav-icon bi bi-box-seam"></i>
              <p>
                Inventory
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (has_permission('inventory.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/inventory/index.php')); ?>" class="nav-link <?= e(is_active_menu('inventory', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-arrow-left-right"></i>
                    <p>Stock Movements</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('inventory.view') || has_permission('billing.view') || has_permission('purchase.view') || has_permission('report.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/returns/index.php')); ?>" class="nav-link <?= e(is_active_menu('inventory.returns', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-arrow-counterclockwise"></i>
                    <p>Returns &amp; RMA</p>
                  </a>
                </li>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <?php if (has_permission('billing.view') || has_permission('invoice.view') || has_permission('customer.view') || has_permission('vehicle.view')): ?>
          <li class="nav-item <?= $isSalesOpen ? 'menu-open' : ''; ?>">
            <a href="#" class="nav-link <?= $isSalesOpen ? 'active' : ''; ?>">
              <i class="nav-icon bi bi-cart-check"></i>
              <p>
                Sales
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (has_permission('billing.view') || has_permission('invoice.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/billing/index.php')); ?>" class="nav-link <?= e(is_active_menu('billing', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-receipt-cutoff"></i>
                    <p>Billing</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="<?= e(url('modules/billing/credit_notes.php')); ?>" class="nav-link <?= e(is_active_menu('billing.credit_notes', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-receipt"></i>
                    <p>Credit Notes</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('customer.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/customers/index.php')); ?>" class="nav-link <?= e(is_active_menu('customers', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-person-vcard"></i>
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
            </ul>
          </li>
        <?php endif; ?>

        <?php if (has_permission('expense.view') || has_permission('payroll.view')): ?>
          <li class="nav-item <?= $isFinanceOpen ? 'menu-open' : ''; ?>">
            <a href="#" class="nav-link <?= $isFinanceOpen ? 'active' : ''; ?>">
              <i class="nav-icon bi bi-cash-stack"></i>
              <p>
                Finance
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (has_permission('expense.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/expenses/index.php')); ?>" class="nav-link <?= e(is_active_menu('finance.expenses', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-credit-card-2-front"></i>
                    <p>Expenses</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('payroll.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/payroll/index.php')); ?>" class="nav-link <?= e(is_active_menu('finance.payroll', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-wallet2"></i>
                    <p>Payroll</p>
                  </a>
                </li>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <?php if (has_permission('vis.view') || has_permission('service.view') || has_permission('part_master.view') || has_permission('part_category.view') || has_permission('job.view')): ?>
          <li class="nav-item <?= $isVehicleIntelligenceOpen ? 'menu-open' : ''; ?>">
            <a href="#" class="nav-link <?= $isVehicleIntelligenceOpen ? 'active' : ''; ?>">
              <i class="nav-icon bi bi-cpu"></i>
              <p>
                Vehicle Intelligence
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (has_permission('vis.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/vis/catalog.php')); ?>" class="nav-link <?= e(is_active_menu('vis.catalog', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-grid-3x3-gap"></i>
                    <p>Catalog</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="<?= e(url('modules/vis/compatibility.php')); ?>" class="nav-link <?= e(is_active_menu('vis.compatibility', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-intersect"></i>
                    <p>Compatibility</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('service.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/services/index.php')); ?>" class="nav-link <?= e($servicesMenuActive); ?>">
                    <i class="nav-icon bi bi-tools"></i>
                    <p>Services</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('part_master.view') || has_permission('part_category.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/inventory/parts_master.php')); ?>" class="nav-link <?= e($partsMenuActive); ?>">
                    <i class="nav-icon bi bi-box"></i>
                    <p>Parts</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('job.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/jobs/maintenance_setup.php')); ?>" class="nav-link <?= e(is_active_menu('jobs.maintenance_setup', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-sliders2"></i>
                    <p>Vehicle Maintenance Setup</p>
                  </a>
                </li>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <?php if ($canViewReports): ?>
          <li class="nav-header">Reports</li>
          <li class="nav-item <?= $isReportsOpen ? 'menu-open' : ''; ?>">
            <a href="#" class="nav-link <?= $isReportsOpen ? 'active' : ''; ?>">
              <i class="nav-icon bi bi-bar-chart-line"></i>
              <p>
                Reports
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="<?= e(url('modules/reports/index.php')); ?>" class="nav-link <?= e(is_active_menu('reports', $activeMenu)); ?>">
                  <i class="nav-icon bi bi-grid-1x2"></i>
                  <p>Overview</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="<?= e(url('modules/reports/jobs.php')); ?>" class="nav-link <?= e(is_active_menu('reports.jobs', $activeMenu)); ?>">
                  <i class="nav-icon bi bi-clipboard-data"></i>
                  <p>Job Reports</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="<?= e(url('modules/reports/inventory.php')); ?>" class="nav-link <?= e(is_active_menu('reports.inventory', $activeMenu)); ?>">
                  <i class="nav-icon bi bi-boxes"></i>
                  <p>Inventory Reports</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="<?= e(url('modules/reports/inventory_valuation.php')); ?>" class="nav-link <?= e(is_active_menu('reports.inventory_valuation', $activeMenu)); ?>">
                  <i class="nav-icon bi bi-calculator"></i>
                  <p>Inventory Valuation</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="<?= e(url('modules/reports/returns.php')); ?>" class="nav-link <?= e(is_active_menu('reports.returns', $activeMenu)); ?>">
                  <i class="nav-icon bi bi-arrow-counterclockwise"></i>
                  <p>Returns Report</p>
                </a>
              </li>
              <?php if ($canViewFinancialReports): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/reports/billing_gst.php')); ?>" class="nav-link <?= e(is_active_menu('reports.billing', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-receipt-cutoff"></i>
                    <p>Billing & GST</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="<?= e(url('modules/reports/payments.php')); ?>" class="nav-link <?= e(is_active_menu('reports.payments', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-wallet2"></i>
                    <p>Payments Report</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="<?= e(url('modules/reports/advance_collections.php')); ?>" class="nav-link <?= e(is_active_menu('reports.advance_collections', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-cash-stack"></i>
                    <p>Advance Collections</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="<?= e(url('modules/reports/profit_loss.php')); ?>" class="nav-link <?= e(is_active_menu('reports.profit_loss', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-graph-up-arrow"></i>
                    <p>Profit &amp; Loss</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="<?= e(url('modules/reports/gst_compliance.php')); ?>" class="nav-link <?= e(is_active_menu('reports.gst_compliance', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-journal-text"></i>
                    <p>GST Compliance</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('job.view') || has_permission('job.manage') || has_permission('reports.financial')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/reports/insurance_claims.php')); ?>" class="nav-link <?= e(is_active_menu('reports.insurance_claims', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-shield-check"></i>
                    <p>Insurance Claims</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('purchase.view') || has_permission('purchase.manage')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/reports/purchases.php')); ?>" class="nav-link <?= e(is_active_menu('reports.purchases', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-bag"></i>
                    <p>Purchase Report</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('payroll.view') || has_permission('payroll.manage')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/reports/payroll.php')); ?>" class="nav-link <?= e(is_active_menu('reports.payroll', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-wallet"></i>
                    <p>Payroll Reports</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('expense.view') || has_permission('expense.manage')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/reports/expenses.php')); ?>" class="nav-link <?= e(is_active_menu('reports.expenses', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-credit-card"></i>
                    <p>Expense Reports</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('outsourced.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/reports/outsourced_labour.php')); ?>" class="nav-link <?= e(is_active_menu('reports.outsourced', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-people"></i>
                    <p>Outsourced Reports</p>
                  </a>
                </li>
              <?php endif; ?>
              <li class="nav-item">
                <a href="<?= e(url('modules/reports/customers.php')); ?>" class="nav-link <?= e(is_active_menu('reports.customers', $activeMenu)); ?>">
                  <i class="nav-icon bi bi-people-fill"></i>
                  <p>Customer Reports</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="<?= e(url('modules/reports/vehicles.php')); ?>" class="nav-link <?= e(is_active_menu('reports.vehicles', $activeMenu)); ?>">
                  <i class="nav-icon bi bi-car-front-fill"></i>
                  <p>Vehicle Reports</p>
                </a>
              </li>
            </ul>
          </li>
        <?php endif; ?>

        <?php if ($canViewUsersPermissions || $canViewAdministration): ?>
          <li class="nav-header">Administration</li>
        <?php endif; ?>

        <?php if ($canViewUsersPermissions): ?>
          <li class="nav-item <?= $isUsersPermissionsOpen ? 'menu-open' : ''; ?>">
            <a href="#" class="nav-link <?= $isUsersPermissionsOpen ? 'active' : ''; ?>">
              <i class="nav-icon bi bi-people"></i>
              <p>
                Users & Permissions
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (has_permission('staff.view') || has_permission('staff.manage')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/organization/staff.php')); ?>" class="nav-link <?= e(is_active_menu('people.staff', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-person-lines-fill"></i>
                    <p>Users / Staff</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('role.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/people/roles.php')); ?>" class="nav-link <?= e(is_active_menu('people.roles', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-person-badge"></i>
                    <p>Roles</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('permission.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/people/permissions.php')); ?>" class="nav-link <?= e(is_active_menu('people.permissions', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-key"></i>
                    <p>Permissions</p>
                  </a>
                </li>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <?php if ($canViewAdministration): ?>
          <li class="nav-item <?= $isAdministrationOpen ? 'menu-open' : ''; ?>">
            <a href="#" class="nav-link <?= $isAdministrationOpen ? 'active' : ''; ?>">
              <i class="nav-icon bi bi-shield-lock"></i>
              <p>
                Administration
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (has_permission('company.manage')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/organization/companies.php')); ?>" class="nav-link <?= e(is_active_menu('organization.companies', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-buildings"></i>
                    <p>Company</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('garage.manage')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/organization/garages.php')); ?>" class="nav-link <?= e(is_active_menu('organization.garages', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-diagram-3"></i>
                    <p>Garage</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('financial_year.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/system/financial_years.php')); ?>" class="nav-link <?= e(is_active_menu('system.financial_years', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-calendar3"></i>
                    <p>Financial Year</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('settings.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/system/settings.php')); ?>" class="nav-link <?= e(is_active_menu('system.settings', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-sliders"></i>
                    <p>Settings</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if ((has_permission('billing.view') || has_permission('invoice.view')) && (has_permission('invoice.manage') || has_permission('settings.manage'))): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/billing/invoice_settings.php')); ?>" class="nav-link <?= e(is_active_menu('billing.settings', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-receipt"></i>
                    <p>Invoice Settings</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('audit.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/system/audit_logs.php')); ?>" class="nav-link <?= e(is_active_menu('system.audit', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-clipboard-check"></i>
                    <p>Audit</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('export.data')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/system/exports.php')); ?>" class="nav-link <?= e(is_active_menu('system.exports', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-box-arrow-up-right"></i>
                    <p>Export</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('backup.manage')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/system/backup_recovery.php')); ?>" class="nav-link <?= e(is_active_menu('system.backup', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-database-check"></i>
                    <p>Backup</p>
                  </a>
                </li>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>
      </ul>
    </nav>
  </div>
</aside>
