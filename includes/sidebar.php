<?php
declare(strict_types=1);

$activeMenu = $active_menu ?? '';
$isOperationsOpen = $activeMenu === 'jobs'
    || $activeMenu === 'estimates'
    || $activeMenu === 'inventory'
    || is_menu_group_open('outsourced.', $activeMenu);
$isSalesOpen = $activeMenu === 'billing'
    || $activeMenu === 'customers'
    || $activeMenu === 'vehicles';
$isInventoryOpen = $activeMenu === 'inventory.parts_master'
    || $activeMenu === 'inventory.categories'
    || is_menu_group_open('purchases.', $activeMenu)
    || is_menu_group_open('vendors.', $activeMenu);
$isFinanceOpen = is_menu_group_open('finance.', $activeMenu) || $activeMenu === 'reports.gst_compliance';
$isReportsOpen = is_menu_group_open('reports', $activeMenu) && $activeMenu !== 'reports.gst_compliance';
$isOrganizationSystemOpen = is_menu_group_open('organization.', $activeMenu) || is_menu_group_open('system.', $activeMenu);
$isAdministrationOpen = $isOrganizationSystemOpen
    || is_menu_group_open('people.', $activeMenu)
    || is_menu_group_open('services.', $activeMenu)
    || is_menu_group_open('vis.', $activeMenu);
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

        <?php if (has_permission('job.view') || has_permission('estimate.view') || has_permission('outsourced.view') || has_permission('inventory.view')): ?>
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
                    <p>Job Cards</p>
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
              <?php if (has_permission('inventory.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/inventory/index.php')); ?>" class="nav-link <?= e(is_active_menu('inventory', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-arrow-left-right"></i>
                    <p>Stock Movements</p>
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
                    <p>Billing / Invoices</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('customer.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/customers/index.php')); ?>" class="nav-link <?= e(is_active_menu('customers', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-person-vcard"></i>
                    <p>Customer Master</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('vehicle.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/vehicles/index.php')); ?>" class="nav-link <?= e(is_active_menu('vehicles', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-car-front"></i>
                    <p>Vehicle Master</p>
                  </a>
                </li>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <?php if (has_permission('part_master.view') || has_permission('part_category.view') || has_permission('purchase.view') || has_permission('vendor.view')): ?>
          <li class="nav-item <?= $isInventoryOpen ? 'menu-open' : ''; ?>">
            <a href="#" class="nav-link <?= $isInventoryOpen ? 'active' : ''; ?>">
              <i class="nav-icon bi bi-box-seam"></i>
              <p>
                Inventory
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (has_permission('part_master.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/inventory/parts_master.php')); ?>" class="nav-link <?= e(is_active_menu('inventory.parts_master', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-box"></i>
                    <p>Parts / Item Master</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('part_category.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/inventory/categories.php')); ?>" class="nav-link <?= e(is_active_menu('inventory.categories', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-tags"></i>
                    <p>Part Category Master</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('purchase.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/purchases/index.php')); ?>" class="nav-link <?= e(is_active_menu('purchases.index', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-bag-check"></i>
                    <p>Purchases</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('vendor.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/vendors/index.php')); ?>" class="nav-link <?= e(is_active_menu('vendors.master', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-truck"></i>
                    <p>Vendor / Supplier Master</p>
                  </a>
                </li>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <?php if (has_permission('payroll.view') || has_permission('expense.view') || has_permission('gst.reports') || has_permission('financial.reports')): ?>
          <li class="nav-item <?= $isFinanceOpen ? 'menu-open' : ''; ?>">
            <a href="#" class="nav-link <?= $isFinanceOpen ? 'active' : ''; ?>">
              <i class="nav-icon bi bi-cash-stack"></i>
              <p>
                Finance
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (has_permission('payroll.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/payroll/index.php')); ?>" class="nav-link <?= e(is_active_menu('finance.payroll', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-wallet2"></i>
                    <p>Payroll & Salary</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('expense.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/expenses/index.php')); ?>" class="nav-link <?= e(is_active_menu('finance.expenses', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-credit-card-2-front"></i>
                    <p>Expenses</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('gst.reports') || has_permission('financial.reports')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/reports/gst_compliance.php')); ?>" class="nav-link <?= e(is_active_menu('reports.gst_compliance', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-receipt"></i>
                    <p>GST Compliance</p>
                  </a>
                </li>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <?php if (has_permission('reports.view') || has_permission('report.view')): ?>
          <li class="nav-header">REPORTS</li>
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
              <?php if (has_permission('reports.financial') || has_permission('financial.reports') || has_permission('gst.reports')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/reports/billing_gst.php')); ?>" class="nav-link <?= e(is_active_menu('reports.billing', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-receipt-cutoff"></i>
                    <p>Billing & GST</p>
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
                    <p>Outsourced Labour</p>
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

        <?php if (has_permission('company.manage') || has_permission('garage.manage') || has_permission('financial_year.view') || has_permission('settings.view') || has_permission('audit.view') || has_permission('export.data') || has_permission('backup.manage') || has_permission('role.view') || has_permission('permission.view') || has_permission('staff.view') || has_permission('staff.manage') || has_permission('service.view') || has_permission('vis.view')): ?>
          <li class="nav-header">ADMINISTRATION</li>
          <li class="nav-item <?= $isAdministrationOpen ? 'menu-open' : ''; ?>">
            <a href="#" class="nav-link <?= $isAdministrationOpen ? 'active' : ''; ?>">
              <i class="nav-icon bi bi-shield-lock"></i>
              <p>
                Administration
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (has_permission('company.manage') || has_permission('garage.manage') || has_permission('financial_year.view') || has_permission('settings.view') || has_permission('audit.view') || has_permission('export.data') || has_permission('backup.manage')): ?>
                <li class="nav-item <?= $isOrganizationSystemOpen ? 'menu-open' : ''; ?>">
                  <a href="#" class="nav-link <?= $isOrganizationSystemOpen ? 'active' : ''; ?>">
                    <i class="nav-icon bi bi-building-gear"></i>
                    <p>
                      Organization & System
                      <i class="nav-arrow bi bi-chevron-right"></i>
                    </p>
                  </a>
                  <ul class="nav nav-treeview">
                    <?php if (has_permission('company.manage')): ?>
                      <li class="nav-item">
                        <a href="<?= e(url('modules/organization/companies.php')); ?>" class="nav-link <?= e(is_active_menu('organization.companies', $activeMenu)); ?>">
                          <i class="nav-icon bi bi-buildings"></i>
                          <p>Company Master</p>
                        </a>
                      </li>
                    <?php endif; ?>
                    <?php if (has_permission('garage.manage')): ?>
                      <li class="nav-item">
                        <a href="<?= e(url('modules/organization/garages.php')); ?>" class="nav-link <?= e(is_active_menu('organization.garages', $activeMenu)); ?>">
                          <i class="nav-icon bi bi-diagram-3"></i>
                          <p>Garage / Branch Master</p>
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
                          <p>System Settings</p>
                        </a>
                      </li>
                    <?php endif; ?>
                    <?php if (has_permission('audit.view')): ?>
                      <li class="nav-item">
                        <a href="<?= e(url('modules/system/audit_logs.php')); ?>" class="nav-link <?= e(is_active_menu('system.audit', $activeMenu)); ?>">
                          <i class="nav-icon bi bi-clipboard-check"></i>
                          <p>Audit Logs</p>
                        </a>
                      </li>
                    <?php endif; ?>
                    <?php if (has_permission('export.data')): ?>
                      <li class="nav-item">
                        <a href="<?= e(url('modules/system/exports.php')); ?>" class="nav-link <?= e(is_active_menu('system.exports', $activeMenu)); ?>">
                          <i class="nav-icon bi bi-box-arrow-up-right"></i>
                          <p>Data Exports</p>
                        </a>
                      </li>
                    <?php endif; ?>
                    <?php if (has_permission('backup.manage')): ?>
                      <li class="nav-item">
                        <a href="<?= e(url('modules/system/backup_recovery.php')); ?>" class="nav-link <?= e(is_active_menu('system.backup', $activeMenu)); ?>">
                          <i class="nav-icon bi bi-database-check"></i>
                          <p>Backup & Recovery</p>
                        </a>
                      </li>
                    <?php endif; ?>
                  </ul>
                </li>
              <?php endif; ?>

              <?php if (has_permission('role.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/people/roles.php')); ?>" class="nav-link <?= e(is_active_menu('people.roles', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-person-badge"></i>
                    <p>Role Master</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('permission.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/people/permissions.php')); ?>" class="nav-link <?= e(is_active_menu('people.permissions', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-key"></i>
                    <p>Permission Management</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('staff.view') || has_permission('staff.manage')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/organization/staff.php')); ?>" class="nav-link <?= e(is_active_menu('people.staff', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-people"></i>
                    <p>Staff Master</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('service.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/services/categories.php')); ?>" class="nav-link <?= e(is_active_menu('services.categories', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-diagram-2"></i>
                    <p>Service Category Master</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="<?= e(url('modules/services/index.php')); ?>" class="nav-link <?= e(is_active_menu('services.master', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-tools"></i>
                    <p>Service / Labour Master</p>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (has_permission('vis.view')): ?>
                <li class="nav-item">
                  <a href="<?= e(url('modules/vis/catalog.php')); ?>" class="nav-link <?= e(is_active_menu('vis.catalog', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-cpu"></i>
                    <p>VIS Vehicle Catalog</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="<?= e(url('modules/vis/compatibility.php')); ?>" class="nav-link <?= e(is_active_menu('vis.compatibility', $activeMenu)); ?>">
                    <i class="nav-icon bi bi-intersect"></i>
                    <p>VIS Compatibility Mapping</p>
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
