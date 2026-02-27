<?php
declare(strict_types=1);

function reports_can_view(): bool
{
    return has_permission('reports.view') || has_permission('report.view');
}

function reports_can_view_financial(): bool
{
    return has_permission('reports.financial') || has_permission('financial.reports') || has_permission('gst.reports');
}

function reports_require_access(): void
{
    if (reports_can_view()) {
        return;
    }

    flash_set('access_denied', 'You do not have permission to access reports.', 'danger');
    redirect('dashboard.php');
}

function reports_compact_query_params(array $params): array
{
    $result = [];
    foreach ($params as $key => $value) {
        if ($value === null) {
            continue;
        }
        if (is_string($value) && trim($value) === '') {
            continue;
        }
        $result[$key] = $value;
    }
    return $result;
}

function reports_export_url(string $pagePath, array $baseParams, string $exportKey): string
{
    $params = reports_compact_query_params($baseParams);
    $params['export'] = $exportKey;
    return url($pagePath . '?' . http_build_query($params));
}

function reports_page_url(string $pagePath, array $params = []): string
{
    $query = http_build_query(reports_compact_query_params($params));
    return $query === '' ? url($pagePath) : url($pagePath . '?' . $query);
}

function reports_csv_download(string $filename, array $headers, array $rows): never
{
    $moduleKey = strtolower((string) strtok($filename, '_'));
    $rowCount = count($rows);
    log_data_export('reports_' . $moduleKey, 'CSV', $rowCount, [
        'company_id' => active_company_id(),
        'garage_id' => active_garage_id() > 0 ? active_garage_id() : null,
        'filter_summary' => 'Report export: ' . $filename,
        'scope' => ['filename' => $filename],
        'requested_by' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
    ]);
    log_audit('exports', 'download', null, 'Exported report CSV: ' . $filename, [
        'entity' => 'data_export',
        'source' => 'UI',
        'before' => ['requested' => true],
        'after' => ['module' => 'reports_' . $moduleKey, 'format' => 'CSV', 'row_count' => $rowCount],
    ]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $stream = fopen('php://output', 'w');
    if ($stream === false) {
        http_response_code(500);
        exit('Unable to generate CSV export.');
    }

    fputcsv($stream, $headers);
    foreach ($rows as $row) {
        $flat = [];
        foreach ($row as $value) {
            $flat[] = is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        fputcsv($stream, $flat);
    }
    fclose($stream);
    exit;
}

function reports_build_scope_context(bool $includeVehicleFilters = false): array
{
    $companyId = active_company_id();
    $activeGarageId = active_garage_id();
    $currentUser = current_user();
    $roleKey = (string) ($currentUser['role_key'] ?? ($_SESSION['role_key'] ?? ''));
    $isOwnerScope = analytics_is_owner_role($roleKey);
    $canViewFinancial = reports_can_view_financial();
    $canExportData = has_permission('export.data');

    $garageOptions = analytics_accessible_garages($companyId, $isOwnerScope);
    if (empty($garageOptions) && $activeGarageId > 0) {
        $fallbackGarageStmt = db()->prepare('SELECT id, name, code FROM garages WHERE id = :garage_id AND company_id = :company_id LIMIT 1');
        $fallbackGarageStmt->execute(['garage_id' => $activeGarageId, 'company_id' => $companyId]);
        $fallback = $fallbackGarageStmt->fetch();
        if ($fallback) {
            $garageOptions[] = $fallback;
        }
    }

    $garageIds = array_values(array_filter(array_map(static fn (array $garage): int => (int) ($garage['id'] ?? 0), $garageOptions), static fn (int $id): bool => $id > 0));
    $allowAllGarages = $isOwnerScope && count($garageIds) > 1;
    $garageRequested = isset($_GET['garage_id']) ? get_int('garage_id', $activeGarageId) : $activeGarageId;
    $selectedGarageId = analytics_resolve_scope_garage_id($garageOptions, $activeGarageId, $garageRequested, $allowAllGarages);
    $scopeGarageLabel = analytics_scope_garage_label($garageOptions, $selectedGarageId);

    $fyContext = analytics_resolve_financial_year($companyId, get_int('fy_id', 0));
    $financialYears = $fyContext['years'];
    $selectedFy = $fyContext['selected'];
    $selectedFyId = (int) ($selectedFy['id'] ?? 0);
    $fyLabel = (string) ($selectedFy['fy_label'] ?? '-');
    $fyStart = (string) ($selectedFy['start_date'] ?? date('Y-04-01'));
    $fyEnd = (string) ($selectedFy['end_date'] ?? date('Y-03-31', strtotime('+1 year')));
    if (!date_filter_is_valid_iso($fyStart)) {
        $fyStart = date('Y-04-01');
    }
    if (!date_filter_is_valid_iso($fyEnd)) {
        $fyEnd = date('Y-03-31', strtotime('+1 year'));
    }
    if ($fyEnd < $fyStart) {
        $fyEnd = $fyStart;
    }

    $today = date('Y-m-d');
    $defaultToDate = $today <= $fyEnd ? $today : $fyEnd;
    if ($defaultToDate < $fyStart) {
        $defaultToDate = $fyStart;
    }

    // Treat bare report URLs as a true reset of date-mode/session memory.
    $hasExplicitDateRequest = isset($_GET['date_mode']) || isset($_GET['from']) || isset($_GET['to']);

    $dateFilter = date_filter_resolve_request([
        'company_id' => $companyId,
        'garage_id' => $selectedGarageId,
        'range_start' => $fyStart,
        'range_end' => $fyEnd,
        'yearly_start' => $fyStart,
        'session_namespace' => 'reports',
        'ignore_session' => !$hasExplicitDateRequest,
        'custom_default_to' => $defaultToDate,
        'request_mode' => $_GET['date_mode'] ?? null,
        'request_from' => $_GET['from'] ?? null,
        'request_to' => $_GET['to'] ?? null,
    ]);
    $dateMode = (string) ($dateFilter['mode'] ?? 'monthly');
    $defaultDateMode = (string) ($dateFilter['default_mode'] ?? 'monthly');
    $fromDate = (string) ($dateFilter['from_date'] ?? $fyStart);
    $toDate = (string) ($dateFilter['to_date'] ?? $defaultToDate);

    $fromDateTime = $fromDate . ' 00:00:00';
    $toDateTime = $toDate . ' 23:59:59';

    $vehicleAttributeEnabled = $includeVehicleFilters && vehicle_masters_enabled() && vehicle_master_link_columns_supported();
    $reportBrandId = $vehicleAttributeEnabled ? get_int('report_brand_id') : 0;
    $reportModelId = $vehicleAttributeEnabled ? get_int('report_model_id') : 0;
    $reportVariantId = $vehicleAttributeEnabled ? get_int('report_variant_id') : 0;
    $reportModelYearId = $vehicleAttributeEnabled ? get_int('report_model_year_id') : 0;
    $reportColorId = $vehicleAttributeEnabled ? get_int('report_color_id') : 0;
    $reportVehicleFilters = [
        'brand_id' => $reportBrandId,
        'model_id' => $reportModelId,
        'variant_id' => $reportVariantId,
        'model_year_id' => $reportModelYearId,
        'color_id' => $reportColorId,
    ];

    $baseParams = [
        'garage_id' => $selectedGarageId,
        'fy_id' => $selectedFyId,
        'date_mode' => $dateMode,
        'from' => $fromDate,
        'to' => $toDate,
    ];
    if ($vehicleAttributeEnabled) {
        $baseParams['report_brand_id'] = $reportBrandId > 0 ? $reportBrandId : null;
        $baseParams['report_model_id'] = $reportModelId > 0 ? $reportModelId : null;
        $baseParams['report_variant_id'] = $reportVariantId > 0 ? $reportVariantId : null;
        $baseParams['report_model_year_id'] = $reportModelYearId > 0 ? $reportModelYearId : null;
        $baseParams['report_color_id'] = $reportColorId > 0 ? $reportColorId : null;
    }

    return [
        'company_id' => $companyId,
        'active_garage_id' => $activeGarageId,
        'is_owner_scope' => $isOwnerScope,
        'can_view_financial' => $canViewFinancial,
        'can_export_data' => $canExportData,
        'garage_options' => $garageOptions,
        'garage_ids' => $garageIds,
        'allow_all_garages' => $allowAllGarages,
        'selected_garage_id' => $selectedGarageId,
        'scope_garage_label' => $scopeGarageLabel,
        'financial_years' => $financialYears,
        'selected_fy' => $selectedFy,
        'selected_fy_id' => $selectedFyId,
        'fy_label' => $fyLabel,
        'fy_start' => $fyStart,
        'fy_end' => $fyEnd,
        'date_range_start' => $fyStart,
        'date_range_end' => $fyEnd,
        'date_mode' => $dateMode,
        'default_date_mode' => $defaultDateMode,
        'date_mode_options' => date_filter_modes(),
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'from_datetime' => $fromDateTime,
        'to_datetime' => $toDateTime,
        'vehicle_attribute_enabled' => $vehicleAttributeEnabled,
        'report_brand_id' => $reportBrandId,
        'report_model_id' => $reportModelId,
        'report_variant_id' => $reportVariantId,
        'report_model_year_id' => $reportModelYearId,
        'report_color_id' => $reportColorId,
        'report_vehicle_filters' => $reportVehicleFilters,
        'vehicle_attributes_api_url' => url('modules/vehicles/attributes_api.php'),
        'base_params' => $baseParams,
    ];
}

function reports_link_is_active(array $link, string $activeMenu): bool
{
    $menuKeys = [];
    $primaryKey = trim((string) ($link['menu_key'] ?? ''));
    if ($primaryKey !== '') {
        $menuKeys[] = $primaryKey;
    }

    $aliases = is_array($link['active_menu_keys'] ?? null) ? (array) $link['active_menu_keys'] : [];
    foreach ($aliases as $alias) {
        $menuKey = trim((string) $alias);
        if ($menuKey !== '') {
            $menuKeys[] = $menuKey;
        }
    }

    return in_array($activeMenu, $menuKeys, true);
}

function reports_module_sections(): array
{
    $sections = [];

    $sections[] = [
        'section_key' => 'overview',
        'label' => 'Overview',
        'links' => [[
            'menu_key' => 'reports',
            'label' => 'Overview',
            'icon' => 'bi bi-grid',
            'path' => 'modules/reports/index.php',
        ]],
    ];

    $operations = [
        [
            'menu_key' => 'reports.jobs',
            'label' => 'Job Reports',
            'icon' => 'bi bi-card-checklist',
            'path' => 'modules/reports/jobs.php',
        ],
        [
            'menu_key' => 'reports.inventory',
            'label' => 'Inventory Reports',
            'icon' => 'bi bi-box-seam',
            'path' => 'modules/reports/inventory.php',
        ],
        [
            'menu_key' => 'reports.inventory_valuation',
            'label' => 'Inventory Valuation',
            'icon' => 'bi bi-calculator',
            'path' => 'modules/reports/inventory_valuation.php',
        ],
        [
            'menu_key' => 'reports.returns',
            'label' => 'Returns Report',
            'icon' => 'bi bi-arrow-counterclockwise',
            'path' => 'modules/reports/returns.php',
        ],
        [
            'menu_key' => 'reports.customers',
            'label' => 'Customer Reports',
            'icon' => 'bi bi-people',
            'path' => 'modules/reports/customers.php',
        ],
        [
            'menu_key' => 'reports.vehicles',
            'label' => 'Vehicle Reports',
            'icon' => 'bi bi-car-front',
            'path' => 'modules/reports/vehicles.php',
        ],
        [
            'menu_key' => 'reports.service_reminders',
            'label' => 'Maintenance Reminders',
            'icon' => 'bi bi-bell',
            'path' => 'modules/reports/service_reminders.php',
        ],
    ];
    $sections[] = [
        'section_key' => 'operations',
        'label' => 'Operations',
        'links' => $operations,
    ];

    $financial = [];
    if (reports_can_view_financial()) {
        $financial[] = [
            'menu_key' => 'reports.sales',
            'active_menu_keys' => ['reports.billing'],
            'label' => 'Sales Report',
            'icon' => 'bi bi-receipt',
            'path' => 'modules/reports/billing_gst.php',
        ];
        $financial[] = [
            'menu_key' => 'reports.payments',
            'label' => 'Payments Report',
            'icon' => 'bi bi-wallet2',
            'path' => 'modules/reports/payments.php',
        ];
        $financial[] = [
            'menu_key' => 'reports.advance_collections',
            'label' => 'Advance Collections',
            'icon' => 'bi bi-cash-stack',
            'path' => 'modules/reports/advance_collections.php',
        ];
        $financial[] = [
            'menu_key' => 'reports.profit_loss',
            'label' => 'Profit & Loss',
            'icon' => 'bi bi-graph-up-arrow',
            'path' => 'modules/reports/profit_loss.php',
        ];
    }

    if (has_permission('purchase.view') || has_permission('purchase.manage')) {
        $financial[] = [
            'menu_key' => 'reports.purchases',
            'label' => 'Purchase Report',
            'icon' => 'bi bi-bag-check',
            'path' => 'modules/reports/purchases.php',
        ];
    }

    if (has_permission('expense.view') || has_permission('expense.manage')) {
        $financial[] = [
            'menu_key' => 'reports.expenses',
            'label' => 'Expense Reports',
            'icon' => 'bi bi-cash-stack',
            'path' => 'modules/reports/expenses.php',
        ];
    }

    if (has_permission('payroll.view') || has_permission('payroll.manage')) {
        $financial[] = [
            'menu_key' => 'reports.payroll',
            'label' => 'Payroll Reports',
            'icon' => 'bi bi-wallet2',
            'path' => 'modules/reports/payroll.php',
        ];
    }

    if (has_permission('outsourced.view')) {
        $financial[] = [
            'menu_key' => 'reports.outsourced',
            'label' => 'Outsourced Labour',
            'icon' => 'bi bi-gear-wide-connected',
            'path' => 'modules/reports/outsourced_labour.php',
        ];
    }

    if ($financial !== []) {
        $sections[] = [
            'section_key' => 'financial',
            'label' => 'Financial',
            'links' => $financial,
        ];
    }

    if (reports_can_view_financial()) {
        $sections[] = [
            'section_key' => 'ledger',
            'label' => 'Ledger (GL)',
            'links' => [
                [
                    'menu_key' => 'reports.ledger_trial_balance',
                    'label' => 'Trial Balance (GL)',
                    'icon' => 'bi bi-list-check',
                    'path' => 'modules/reports/trial_balance_ledger.php',
                ],
                [
                    'menu_key' => 'reports.ledger_profit_loss',
                    'label' => 'P&L (GL)',
                    'icon' => 'bi bi-bar-chart-line',
                    'path' => 'modules/reports/profit_loss_ledger.php',
                ],
                [
                    'menu_key' => 'reports.ledger_balance_sheet',
                    'label' => 'Balance Sheet (GL)',
                    'icon' => 'bi bi-columns-gap',
                    'path' => 'modules/reports/balance_sheet_ledger.php',
                ],
                [
                    'menu_key' => 'reports.ledger_cash_flow',
                    'label' => 'Cash Flow (GL)',
                    'icon' => 'bi bi-water',
                    'path' => 'modules/reports/cash_flow_ledger.php',
                ],
                [
                    'menu_key' => 'reports.ledger_general_ledger',
                    'label' => 'General Ledger',
                    'icon' => 'bi bi-journal-bookmark',
                    'path' => 'modules/reports/general_ledger.php',
                ],
                [
                    'menu_key' => 'reports.ledger_customer_ledger',
                    'label' => 'Customer Ledger',
                    'icon' => 'bi bi-person-lines-fill',
                    'path' => 'modules/reports/customer_ledger.php',
                ],
                [
                    'menu_key' => 'reports.ledger_vendor_ledger',
                    'label' => 'Vendor Ledger',
                    'icon' => 'bi bi-building',
                    'path' => 'modules/reports/vendor_ledger.php',
                ],
            ],
        ];
    }

    $auditCompliance = [];
    if (has_permission('job.view') || has_permission('job.manage') || has_permission('reports.financial')) {
        $auditCompliance[] = [
            'menu_key' => 'reports.vehicle_intake_audit',
            'label' => 'Vehicle Intake Audit',
            'icon' => 'bi bi-clipboard2-check',
            'path' => 'modules/reports/vehicle_intake_audit.php',
        ];
        $auditCompliance[] = [
            'menu_key' => 'reports.insurance_claims',
            'label' => 'Insurance Claims',
            'icon' => 'bi bi-shield-check',
            'path' => 'modules/reports/insurance_claims.php',
        ];
    }
    if (has_permission('gst.reports') || has_permission('financial.reports')) {
        $auditCompliance[] = [
            'menu_key' => 'reports.gst_compliance',
            'label' => 'GST Compliance',
            'icon' => 'bi bi-journal-text',
            'path' => 'modules/reports/gst_compliance.php',
        ];
    }
    if ($auditCompliance !== []) {
        $sections[] = [
            'section_key' => 'audit_compliance',
            'label' => 'Audit & Compliance',
            'links' => $auditCompliance,
        ];
    }

    return $sections;
}

function reports_module_links(): array
{
    $links = [];
    foreach (reports_module_sections() as $section) {
        $sectionLinks = is_array($section['links'] ?? null) ? (array) $section['links'] : [];
        foreach ($sectionLinks as $link) {
            $links[] = $link;
        }
    }
    return $links;
}

function reports_render_page_navigation(string $activeMenu, array $params = []): void
{
    $sections = reports_module_sections();
    if ($sections === []) {
        return;
    }
    ?>
    <div class="card card-outline card-primary mb-3">
      <div class="card-body">
        <?php foreach ($sections as $section): ?>
          <?php
            $sectionLinks = is_array($section['links'] ?? null) ? (array) $section['links'] : [];
            if ($sectionLinks === []) {
                continue;
            }
            $sectionLabel = trim((string) ($section['label'] ?? ''));
          ?>
          <div class="mb-2">
            <?php if ($sectionLabel !== ''): ?>
              <div class="small text-uppercase text-muted fw-semibold mb-1"><?= e($sectionLabel); ?></div>
            <?php endif; ?>
            <div class="btn-group flex-wrap" role="group" aria-label="<?= e($sectionLabel !== '' ? $sectionLabel : 'Report Pages'); ?>">
              <?php foreach ($sectionLinks as $link): ?>
                <?php
                  $isActive = reports_link_is_active($link, $activeMenu);
                  $path = trim((string) ($link['path'] ?? 'modules/reports/index.php'));
                  $label = trim((string) ($link['label'] ?? 'Report'));
                  $icon = trim((string) ($link['icon'] ?? 'bi bi-file-earmark-text'));
                ?>
                <a href="<?= e(reports_page_url($path, $params)); ?>" class="btn btn-sm <?= $isActive ? 'btn-primary' : 'btn-outline-primary'; ?>">
                  <i class="<?= e($icon); ?> me-1"></i><?= e($label); ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
}
