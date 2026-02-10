<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();

function reports_can_view(): bool
{
    return has_permission('reports.view') || has_permission('report.view');
}

function reports_can_view_financial(): bool
{
    return has_permission('reports.financial');
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

function reports_export_url(array $baseParams, string $exportKey): string
{
    $params = $baseParams;
    $params['export'] = $exportKey;
    return url('modules/reports/index.php?' . http_build_query($params));
}

if (!reports_can_view()) {
    flash_set('access_denied', 'You do not have permission to access reports.', 'danger');
    redirect('dashboard.php');
}

$page_title = 'Reports & Analytics Intelligence';
$active_menu = 'reports';

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
$today = date('Y-m-d');

$defaultToDate = $today <= $fyEnd ? $today : $fyEnd;
if ($defaultToDate < $fyStart) {
    $defaultToDate = $fyStart;
}

$fromDate = analytics_parse_iso_date($_GET['from'] ?? null, $fyStart);
$toDate = analytics_parse_iso_date($_GET['to'] ?? null, $defaultToDate);
if ($fromDate < $fyStart) {
    $fromDate = $fyStart;
}
if ($toDate > $fyEnd) {
    $toDate = $fyEnd;
}
if ($toDate < $fromDate) {
    $toDate = $fromDate;
}

$fromDateTime = $fromDate . ' 00:00:00';
$toDateTime = $toDate . ' 23:59:59';
$vehicleAttributeEnabled = vehicle_masters_enabled() && vehicle_master_link_columns_supported();

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
$vehicleAttributesApiUrl = url('modules/vehicles/attributes_api.php');
$jobCardColumns = table_columns('job_cards');
$jobOdometerEnabled = in_array('odometer_km', $jobCardColumns, true);

$exportBaseParams = [
    'garage_id' => $selectedGarageId,
    'fy_id' => $selectedFyId,
    'from' => $fromDate,
    'to' => $toDate,
    'report_brand_id' => $reportBrandId > 0 ? $reportBrandId : null,
    'report_model_id' => $reportModelId > 0 ? $reportModelId : null,
    'report_variant_id' => $reportVariantId > 0 ? $reportVariantId : null,
    'report_model_year_id' => $reportModelYearId > 0 ? $reportModelYearId : null,
    'report_color_id' => $reportColorId > 0 ? $reportColorId : null,
];

$jobStatusParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$jobStatusScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $jobStatusParams, 'job_status_scope');
$jobStatusStmt = db()->prepare(
    'SELECT jc.status, COUNT(*) AS total
     FROM job_cards jc
     WHERE jc.company_id = :company_id
       AND jc.status_code = "ACTIVE"
       ' . $jobStatusScopeSql . '
       AND DATE(COALESCE(jc.closed_at, jc.opened_at, jc.created_at)) BETWEEN :from_date AND :to_date
     GROUP BY jc.status'
);
$jobStatusStmt->execute($jobStatusParams);
$jobStatusRaw = $jobStatusStmt->fetchAll();

$jobStatusMap = ['OPEN' => 0, 'IN_PROGRESS' => 0, 'WAITING_PARTS' => 0, 'READY_FOR_DELIVERY' => 0, 'COMPLETED' => 0, 'CLOSED' => 0];
foreach ($jobStatusRaw as $row) {
    $statusKey = strtoupper((string) ($row['status'] ?? ''));
    if (array_key_exists($statusKey, $jobStatusMap)) {
        $jobStatusMap[$statusKey] = (int) ($row['total'] ?? 0);
    }
}
$jobStatusRows = [];
foreach ($jobStatusMap as $status => $total) {
    $jobStatusRows[] = ['status' => $status, 'total' => $total];
}

$avgCompletionParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$avgCompletionScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $avgCompletionParams, 'avg_job_scope');
$avgCompletionStmt = db()->prepare(
    'SELECT COUNT(*) AS closed_jobs,
            COALESCE(AVG(TIMESTAMPDIFF(HOUR, jc.opened_at, jc.closed_at)), 0) AS avg_hours
     FROM job_cards jc
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND jc.opened_at IS NOT NULL
       AND jc.closed_at IS NOT NULL
       ' . $avgCompletionScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date'
);
$avgCompletionStmt->execute($avgCompletionParams);
$avgCompletion = $avgCompletionStmt->fetch() ?: ['closed_jobs' => 0, 'avg_hours' => 0];

$estimateConversion = [
    'draft_count' => 0,
    'approved_count' => 0,
    'rejected_count' => 0,
    'converted_count' => 0,
    'total_count' => 0,
    'approved_pool' => 0,
    'conversion_ratio' => 0.0,
];
$estimateStatusRows = [];

if (table_columns('estimates') !== []) {
    $estimateParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $estimateScopeSql = analytics_garage_scope_sql('e.garage_id', $selectedGarageId, $garageIds, $estimateParams, 'estimate_scope');
    $estimateStmt = db()->prepare(
        'SELECT
            SUM(CASE WHEN e.estimate_status = "DRAFT" THEN 1 ELSE 0 END) AS draft_count,
            SUM(CASE WHEN e.estimate_status = "APPROVED" THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN e.estimate_status = "REJECTED" THEN 1 ELSE 0 END) AS rejected_count,
            SUM(CASE WHEN e.estimate_status = "CONVERTED" THEN 1 ELSE 0 END) AS converted_count,
            COUNT(*) AS total_count
         FROM estimates e
         WHERE e.company_id = :company_id
           AND e.status_code = "ACTIVE"
           ' . $estimateScopeSql . '
           AND DATE(e.created_at) BETWEEN :from_date AND :to_date'
    );
    $estimateStmt->execute($estimateParams);
    $estimateRow = $estimateStmt->fetch() ?: [];

    $estimateConversion['draft_count'] = (int) ($estimateRow['draft_count'] ?? 0);
    $estimateConversion['approved_count'] = (int) ($estimateRow['approved_count'] ?? 0);
    $estimateConversion['rejected_count'] = (int) ($estimateRow['rejected_count'] ?? 0);
    $estimateConversion['converted_count'] = (int) ($estimateRow['converted_count'] ?? 0);
    $estimateConversion['total_count'] = (int) ($estimateRow['total_count'] ?? 0);
    $estimateConversion['approved_pool'] = $estimateConversion['approved_count'] + $estimateConversion['converted_count'];
    $estimateConversion['conversion_ratio'] = $estimateConversion['approved_pool'] > 0
        ? round(($estimateConversion['converted_count'] * 100) / $estimateConversion['approved_pool'], 2)
        : 0.0;

    $estimateStatusRows = [
        ['status' => 'DRAFT', 'total' => $estimateConversion['draft_count']],
        ['status' => 'APPROVED', 'total' => $estimateConversion['approved_count']],
        ['status' => 'REJECTED', 'total' => $estimateConversion['rejected_count']],
        ['status' => 'CONVERTED', 'total' => $estimateConversion['converted_count']],
    ];
}

$mechanicParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$mechanicScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $mechanicParams, 'mechanic_scope');
$mechanicStmt = db()->prepare(
    'SELECT u.id, u.name AS mechanic_name,
            COUNT(DISTINCT jc.id) AS assigned_jobs,
            SUM(CASE WHEN jc.status = "CLOSED" THEN 1 ELSE 0 END) AS closed_jobs,
            ROUND(COALESCE(AVG(CASE WHEN jc.status = "CLOSED" THEN TIMESTAMPDIFF(HOUR, jc.opened_at, jc.closed_at) END), 0), 2) AS avg_close_hours
     FROM users u
     LEFT JOIN job_assignments ja ON ja.user_id = u.id AND ja.status_code = "ACTIVE"
     LEFT JOIN job_cards jc ON jc.id = ja.job_card_id
       AND jc.company_id = :company_id
       AND jc.status_code = "ACTIVE"
       ' . $mechanicScopeSql . '
       AND DATE(COALESCE(jc.closed_at, jc.opened_at, jc.created_at)) BETWEEN :from_date AND :to_date
     WHERE u.company_id = :company_id
       AND u.status_code = "ACTIVE"
     GROUP BY u.id, u.name
     HAVING assigned_jobs > 0
     ORDER BY closed_jobs DESC, assigned_jobs DESC
     LIMIT 20'
);
$mechanicStmt->execute($mechanicParams);
$mechanicRows = $mechanicStmt->fetchAll();

$repeatCustomerParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$repeatCustomerScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $repeatCustomerParams, 'repeat_cust_scope');
$repeatCustomerStmt = db()->prepare(
    'SELECT c.full_name, c.phone,
            COUNT(DISTINCT jc.id) AS service_count,
            MAX(jc.closed_at) AS last_service_at
     FROM job_cards jc
     INNER JOIN customers c ON c.id = jc.customer_id
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND c.company_id = :company_id
       AND c.status_code = "ACTIVE"
       ' . $repeatCustomerScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
     GROUP BY c.id, c.full_name, c.phone
     HAVING service_count >= 2
     ORDER BY service_count DESC, last_service_at DESC
     LIMIT 15'
);
$repeatCustomerStmt->execute($repeatCustomerParams);
$repeatCustomers = $repeatCustomerStmt->fetchAll();

$topCustomerParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$topCustomerScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $topCustomerParams, 'top_cust_scope');
$topCustomerStmt = db()->prepare(
    'SELECT c.full_name, c.phone,
            COUNT(i.id) AS finalized_invoice_count,
            COALESCE(SUM(i.grand_total), 0) AS revenue_total
     FROM invoices i
     INNER JOIN job_cards jc ON jc.id = i.job_card_id
     INNER JOIN customers c ON c.id = i.customer_id
     WHERE i.company_id = :company_id
       AND i.invoice_status = "FINALIZED"
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND c.company_id = :company_id
       AND c.status_code = "ACTIVE"
       ' . $topCustomerScopeSql . '
       AND i.invoice_date BETWEEN :from_date AND :to_date
     GROUP BY c.id, c.full_name, c.phone
     ORDER BY revenue_total DESC
     LIMIT 15'
);
$topCustomerStmt->execute($topCustomerParams);
$topCustomers = $topCustomerStmt->fetchAll();

$modelParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$modelScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $modelParams, 'model_scope');
$modelVehicleScopeSql = vehicle_master_scope_sql('v', $reportVehicleFilters, $modelParams, 'report_model_scope');
$modelStmt = db()->prepare(
    'SELECT v.brand, v.model, COUNT(DISTINCT jc.id) AS service_count
     FROM job_cards jc
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND v.company_id = :company_id
       AND v.status_code = "ACTIVE"
       ' . $modelVehicleScopeSql . '
       ' . $modelScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
     GROUP BY v.brand, v.model
     ORDER BY service_count DESC
     LIMIT 15'
);
$modelStmt->execute($modelParams);
$servicedModels = $modelStmt->fetchAll();

$frequencyParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$frequencyScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $frequencyParams, 'frequency_scope');
$frequencyVehicleScopeSql = vehicle_master_scope_sql('v', $reportVehicleFilters, $frequencyParams, 'report_frequency_scope');
$frequencyStmt = db()->prepare(
    'SELECT v.registration_no, v.brand, v.model,
            COUNT(jc.id) AS service_count,
            CASE WHEN COUNT(jc.id) > 1 THEN ROUND(DATEDIFF(MAX(DATE(jc.closed_at)), MIN(DATE(jc.closed_at))) / (COUNT(jc.id) - 1), 1) ELSE NULL END AS avg_days_between_services
     FROM job_cards jc
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND v.company_id = :company_id
       AND v.status_code = "ACTIVE"
       ' . $frequencyVehicleScopeSql . '
       ' . $frequencyScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
     GROUP BY v.id, v.registration_no, v.brand, v.model
     ORDER BY service_count DESC
     LIMIT 20'
);
$frequencyStmt->execute($frequencyParams);
$serviceFrequencyRows = $frequencyStmt->fetchAll();

$outsourcePayableRows = [];
$outsourcePayableSummaryRows = [];
$outsourcePayableTotals = [
    'line_count' => 0,
    'payable_total' => 0.0,
    'paid_total' => 0.0,
    'unpaid_total' => 0.0,
];

$outsourceDetailParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$outsourceDetailScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $outsourceDetailParams, 'outsource_detail_scope');
$outsourceDetailStmt = db()->prepare(
    'SELECT jl.id AS labor_id,
            jc.job_number,
            DATE(jl.created_at) AS outsource_date,
            c.full_name AS customer_name,
            v.registration_no,
            jl.description,
            jl.outsource_cost,
            jl.outsource_payable_status,
            jl.outsource_paid_at,
            vd.vendor_name,
            jl.outsource_partner_name
     FROM job_labor jl
     INNER JOIN job_cards jc ON jc.id = jl.job_card_id
     INNER JOIN customers c ON c.id = jc.customer_id
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     LEFT JOIN vendors vd ON vd.id = jl.outsource_vendor_id
     WHERE jc.company_id = :company_id
       AND jc.status_code <> "DELETED"
       AND jl.execution_type = "OUTSOURCED"
       AND COALESCE(jl.outsource_cost, 0) > 0
       ' . $outsourceDetailScopeSql . '
       AND DATE(jl.created_at) BETWEEN :from_date AND :to_date
     ORDER BY
       CASE WHEN COALESCE(jl.outsource_payable_status, "UNPAID") = "UNPAID" THEN 0 ELSE 1 END,
       jl.created_at DESC
     LIMIT 300'
);
$outsourceDetailStmt->execute($outsourceDetailParams);
foreach ($outsourceDetailStmt->fetchAll() as $row) {
    $status = strtoupper(trim((string) ($row['outsource_payable_status'] ?? 'UNPAID')));
    if ($status !== 'PAID') {
        $status = 'UNPAID';
    }
    $partnerName = trim((string) ($row['vendor_name'] ?? ''));
    if ($partnerName === '') {
        $partnerName = trim((string) ($row['outsource_partner_name'] ?? ''));
    }
    if ($partnerName === '') {
        $partnerName = '-';
    }

    $outsourcePayableRows[] = [
        'job_number' => (string) ($row['job_number'] ?? ''),
        'outsource_date' => (string) ($row['outsource_date'] ?? ''),
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'registration_no' => (string) ($row['registration_no'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'outsource_partner' => $partnerName,
        'outsource_cost' => (float) ($row['outsource_cost'] ?? 0),
        'outsource_payable_status' => $status,
        'outsource_paid_at' => (string) ($row['outsource_paid_at'] ?? ''),
    ];
}

$outsourceSummaryMap = [
    'UNPAID' => ['status' => 'UNPAID', 'line_count' => 0, 'payable_total' => 0.0],
    'PAID' => ['status' => 'PAID', 'line_count' => 0, 'payable_total' => 0.0],
];
$outsourceSummaryParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$outsourceSummaryScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $outsourceSummaryParams, 'outsource_summary_scope');
$outsourceSummaryStmt = db()->prepare(
    'SELECT COALESCE(jl.outsource_payable_status, "UNPAID") AS payable_status,
            COUNT(*) AS line_count,
            COALESCE(SUM(jl.outsource_cost), 0) AS payable_total
     FROM job_labor jl
     INNER JOIN job_cards jc ON jc.id = jl.job_card_id
     WHERE jc.company_id = :company_id
       AND jc.status_code <> "DELETED"
       AND jl.execution_type = "OUTSOURCED"
       AND COALESCE(jl.outsource_cost, 0) > 0
       ' . $outsourceSummaryScopeSql . '
       AND DATE(jl.created_at) BETWEEN :from_date AND :to_date
     GROUP BY COALESCE(jl.outsource_payable_status, "UNPAID")'
);
$outsourceSummaryStmt->execute($outsourceSummaryParams);
foreach ($outsourceSummaryStmt->fetchAll() as $row) {
    $status = strtoupper(trim((string) ($row['payable_status'] ?? 'UNPAID')));
    if (!isset($outsourceSummaryMap[$status])) {
        $status = 'UNPAID';
    }
    $outsourceSummaryMap[$status]['line_count'] = (int) ($row['line_count'] ?? 0);
    $outsourceSummaryMap[$status]['payable_total'] = (float) ($row['payable_total'] ?? 0);
}

foreach (['UNPAID', 'PAID'] as $statusKey) {
    $row = $outsourceSummaryMap[$statusKey];
    $outsourcePayableSummaryRows[] = $row;
    $outsourcePayableTotals['line_count'] += (int) ($row['line_count'] ?? 0);
    $outsourcePayableTotals['payable_total'] += (float) ($row['payable_total'] ?? 0);
}
$outsourcePayableTotals['unpaid_total'] = (float) ($outsourceSummaryMap['UNPAID']['payable_total'] ?? 0);
$outsourcePayableTotals['paid_total'] = (float) ($outsourceSummaryMap['PAID']['payable_total'] ?? 0);

$revenueDaily = [];
$revenueMonthly = [];
$revenueGarageWise = [];
$gstSummary = ['invoice_count' => 0, 'taxable_total' => 0, 'cgst_total' => 0, 'sgst_total' => 0, 'igst_total' => 0, 'tax_total' => 0, 'grand_total' => 0];
$paymentModeSummary = [];
$outstandingReceivables = [];
$cancelledImpactRows = [];
$cancelledImpactTotals = ['cancelled_count' => 0, 'cancelled_total' => 0];

if ($canViewFinancial) {
    $revenueDailyParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $revenueDailyScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $revenueDailyParams, 'rev_daily_scope');
    $revenueDailyStmt = db()->prepare(
        'SELECT i.invoice_date, COUNT(*) AS invoice_count, COALESCE(SUM(i.grand_total), 0) AS revenue_total
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $revenueDailyScopeSql . '
           AND i.invoice_date BETWEEN :from_date AND :to_date
         GROUP BY i.invoice_date
         ORDER BY i.invoice_date ASC'
    );
    $revenueDailyStmt->execute($revenueDailyParams);
    $revenueDaily = $revenueDailyStmt->fetchAll();

    $revenueMonthlyParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $revenueMonthlyScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $revenueMonthlyParams, 'rev_month_scope');
    $revenueMonthlyStmt = db()->prepare(
        'SELECT DATE_FORMAT(i.invoice_date, "%Y-%m") AS revenue_month, COUNT(*) AS invoice_count, COALESCE(SUM(i.grand_total), 0) AS revenue_total
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $revenueMonthlyScopeSql . '
           AND i.invoice_date BETWEEN :from_date AND :to_date
         GROUP BY DATE_FORMAT(i.invoice_date, "%Y-%m")
         ORDER BY revenue_month ASC'
    );
    $revenueMonthlyStmt->execute($revenueMonthlyParams);
    $revenueMonthly = $revenueMonthlyStmt->fetchAll();

    $revenueGarageParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $revenueGarageScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $revenueGarageParams, 'rev_garage_scope');
    $revenueGarageStmt = db()->prepare(
        'SELECT g.name AS garage_name, COUNT(*) AS invoice_count, COALESCE(SUM(i.grand_total), 0) AS revenue_total
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         INNER JOIN garages g ON g.id = i.garage_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $revenueGarageScopeSql . '
           AND i.invoice_date BETWEEN :from_date AND :to_date
         GROUP BY g.id, g.name
         ORDER BY revenue_total DESC'
    );
    $revenueGarageStmt->execute($revenueGarageParams);
    $revenueGarageWise = $revenueGarageStmt->fetchAll();

    $gstParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $gstScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $gstParams, 'gst_scope');
    $gstStmt = db()->prepare(
        'SELECT COUNT(*) AS invoice_count,
                COALESCE(SUM(i.taxable_amount), 0) AS taxable_total,
                COALESCE(SUM(i.cgst_amount), 0) AS cgst_total,
                COALESCE(SUM(i.sgst_amount), 0) AS sgst_total,
                COALESCE(SUM(i.igst_amount), 0) AS igst_total,
                COALESCE(SUM(i.total_tax_amount), 0) AS tax_total,
                COALESCE(SUM(i.grand_total), 0) AS grand_total
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $gstScopeSql . '
           AND i.invoice_date BETWEEN :from_date AND :to_date'
    );
    $gstStmt->execute($gstParams);
    $gstSummary = $gstStmt->fetch() ?: $gstSummary;

    $paymentModeParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $paymentModeScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $paymentModeParams, 'pay_scope');
    $paymentModeStmt = db()->prepare(
        'SELECT p.payment_mode, COUNT(p.id) AS payment_count, COALESCE(SUM(p.amount), 0) AS collected_amount
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $paymentModeScopeSql . '
           AND p.paid_on BETWEEN :from_date AND :to_date
         GROUP BY p.payment_mode
         ORDER BY collected_amount DESC'
    );
    $paymentModeStmt->execute($paymentModeParams);
    $paymentModeSummary = $paymentModeStmt->fetchAll();

    $receivableParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $receivableScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $receivableParams, 'recv_scope');
    $receivableStmt = db()->prepare(
        'SELECT i.invoice_number, i.invoice_date, i.due_date, c.full_name AS customer_name,
                i.grand_total,
                COALESCE(paid.total_paid, 0) AS paid_amount,
                (i.grand_total - COALESCE(paid.total_paid, 0)) AS outstanding_amount,
                CASE WHEN i.due_date IS NOT NULL AND i.due_date < CURDATE() THEN DATEDIFF(CURDATE(), i.due_date) ELSE 0 END AS overdue_days
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         INNER JOIN customers c ON c.id = i.customer_id
         LEFT JOIN (SELECT invoice_id, SUM(amount) AS total_paid FROM payments GROUP BY invoice_id) paid ON paid.invoice_id = i.id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $receivableScopeSql . '
           AND i.invoice_date BETWEEN :from_date AND :to_date
           AND (i.grand_total - COALESCE(paid.total_paid, 0)) > 0.01
         ORDER BY overdue_days DESC, outstanding_amount DESC
         LIMIT 100'
    );
    $receivableStmt->execute($receivableParams);
    $outstandingReceivables = $receivableStmt->fetchAll();

    $cancelParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $cancelScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $cancelParams, 'cancel_scope');
    $cancelStmt = db()->prepare(
        'SELECT i.invoice_number, i.invoice_date, i.cancelled_at, g.name AS garage_name, c.full_name AS customer_name,
                i.grand_total, i.cancel_reason
         FROM invoices i
         INNER JOIN garages g ON g.id = i.garage_id
         LEFT JOIN customers c ON c.id = i.customer_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "CANCELLED"
           ' . $cancelScopeSql . '
           AND DATE(COALESCE(i.cancelled_at, i.invoice_date)) BETWEEN :from_date AND :to_date
         ORDER BY COALESCE(i.cancelled_at, i.invoice_date) DESC
         LIMIT 100'
    );
    $cancelStmt->execute($cancelParams);
    $cancelledImpactRows = $cancelStmt->fetchAll();
    foreach ($cancelledImpactRows as $row) {
        $cancelledImpactTotals['cancelled_count']++;
        $cancelledImpactTotals['cancelled_total'] += (float) ($row['grand_total'] ?? 0);
    }
}

$stockValuationParams = ['company_id' => $companyId];
$stockValuationScopeSql = analytics_garage_scope_sql('g.id', $selectedGarageId, $garageIds, $stockValuationParams, 'stock_scope');
$stockValuationStmt = db()->prepare(
    'SELECT g.name AS garage_name,
            COALESCE(SUM(gi.quantity), 0) AS total_qty,
            COALESCE(SUM(gi.quantity * p.purchase_price), 0) AS stock_value,
            COALESCE(SUM(CASE WHEN gi.quantity <= p.min_stock THEN 1 ELSE 0 END), 0) AS low_stock_parts
     FROM garages g
     LEFT JOIN garage_inventory gi ON gi.garage_id = g.id
     LEFT JOIN parts p ON p.id = gi.part_id AND p.company_id = :company_id AND p.status_code = "ACTIVE"
     WHERE g.company_id = :company_id
       AND (g.status_code IS NULL OR g.status_code = "ACTIVE")
       ' . $stockValuationScopeSql . '
     GROUP BY g.id, g.name
     ORDER BY stock_value DESC'
);
$stockValuationStmt->execute($stockValuationParams);
$stockValuationRows = $stockValuationStmt->fetchAll();

$fastStockParams = ['company_id' => $companyId, 'from_dt' => $fromDateTime, 'to_dt' => $toDateTime];
$fastStockScopeSql = analytics_garage_scope_sql('im.garage_id', $selectedGarageId, $garageIds, $fastStockParams, 'fast_stock_scope');
$fastStockStmt = db()->prepare(
    'SELECT p.part_name, p.part_sku, p.unit,
            COUNT(*) AS movement_count,
            COALESCE(SUM(im.quantity), 0) AS out_qty
     FROM inventory_movements im
     INNER JOIN parts p ON p.id = im.part_id
     INNER JOIN garages g ON g.id = im.garage_id
     LEFT JOIN job_cards jc_ref ON jc_ref.id = im.reference_id AND im.reference_type = "JOB_CARD"
     LEFT JOIN inventory_transfers tr ON tr.id = im.reference_id AND im.reference_type = "TRANSFER"
     WHERE im.company_id = :company_id
       AND p.company_id = :company_id
       AND p.status_code = "ACTIVE"
       AND g.company_id = :company_id
       AND (g.status_code IS NULL OR g.status_code = "ACTIVE")
       ' . $fastStockScopeSql . '
       AND im.created_at BETWEEN :from_dt AND :to_dt
       AND im.movement_type = "OUT"
       AND (im.reference_type <> "JOB_CARD" OR (jc_ref.id IS NOT NULL AND jc_ref.company_id = :company_id AND jc_ref.status = "CLOSED" AND jc_ref.status_code = "ACTIVE"))
       AND (im.reference_type <> "TRANSFER" OR (tr.id IS NOT NULL AND tr.company_id = :company_id AND tr.status_code = "POSTED"))
     GROUP BY p.id, p.part_name, p.part_sku, p.unit
     ORDER BY out_qty DESC
     LIMIT 12'
);
$fastStockStmt->execute($fastStockParams);
$fastMovingStockRows = $fastStockStmt->fetchAll();

$deadStockParams = ['company_id' => $companyId, 'from_dt' => $fromDateTime, 'to_dt' => $toDateTime];
$deadStockScopeSql = analytics_garage_scope_sql('gi.garage_id', $selectedGarageId, $garageIds, $deadStockParams, 'dead_stock_scope');
$deadMovementScopeSql = analytics_garage_scope_sql('im.garage_id', $selectedGarageId, $garageIds, $deadStockParams, 'dead_mv_scope');
$deadStockStmt = db()->prepare(
    'SELECT p.part_name, p.part_sku, p.unit,
            stock.stock_qty,
            stock.stock_value
     FROM parts p
     INNER JOIN (
        SELECT gi.part_id,
               COALESCE(SUM(gi.quantity), 0) AS stock_qty,
               COALESCE(SUM(gi.quantity * p2.purchase_price), 0) AS stock_value
        FROM garage_inventory gi
        INNER JOIN parts p2 ON p2.id = gi.part_id
          AND p2.company_id = :company_id
          AND p2.status_code = "ACTIVE"
        INNER JOIN garages g2 ON g2.id = gi.garage_id
          AND g2.company_id = :company_id
          AND (g2.status_code IS NULL OR g2.status_code = "ACTIVE")
        WHERE 1 = 1
          ' . $deadStockScopeSql . '
        GROUP BY gi.part_id
     ) stock ON stock.part_id = p.id
     LEFT JOIN (
        SELECT im.part_id
        FROM inventory_movements im
        INNER JOIN parts p3 ON p3.id = im.part_id
        INNER JOIN garages g3 ON g3.id = im.garage_id
        LEFT JOIN job_cards jc_ref ON jc_ref.id = im.reference_id AND im.reference_type = "JOB_CARD"
        LEFT JOIN inventory_transfers tr ON tr.id = im.reference_id AND im.reference_type = "TRANSFER"
        WHERE im.company_id = :company_id
          AND p3.company_id = :company_id
          AND p3.status_code = "ACTIVE"
          AND g3.company_id = :company_id
          AND (g3.status_code IS NULL OR g3.status_code = "ACTIVE")
          ' . $deadMovementScopeSql . '
          AND im.created_at BETWEEN :from_dt AND :to_dt
          AND im.movement_type = "OUT"
          AND (
              im.reference_type <> "JOB_CARD"
              OR (jc_ref.id IS NOT NULL AND jc_ref.company_id = :company_id AND jc_ref.status = "CLOSED" AND jc_ref.status_code = "ACTIVE")
          )
          AND (
              im.reference_type <> "TRANSFER"
              OR (tr.id IS NOT NULL AND tr.company_id = :company_id AND tr.status_code = "POSTED")
          )
        GROUP BY im.part_id
     ) used ON used.part_id = p.id
     WHERE p.company_id = :company_id
       AND p.status_code = "ACTIVE"
       AND stock.stock_qty > 0
       AND used.part_id IS NULL
     ORDER BY stock.stock_value DESC
     LIMIT 12'
);
$deadStockStmt->execute($deadStockParams);
$deadStockRows = $deadStockStmt->fetchAll();

$movementSummaryParams = ['company_id' => $companyId, 'from_dt' => $fromDateTime, 'to_dt' => $toDateTime];
$movementSummaryScopeSql = analytics_garage_scope_sql('im.garage_id', $selectedGarageId, $garageIds, $movementSummaryParams, 'mv_scope');
$movementSummaryStmt = db()->prepare(
    'SELECT im.movement_type, im.reference_type,
            COUNT(*) AS movement_count,
            COALESCE(SUM(CASE WHEN im.movement_type = "OUT" THEN -1 * ABS(im.quantity) WHEN im.movement_type = "IN" THEN ABS(im.quantity) ELSE im.quantity END), 0) AS signed_qty
     FROM inventory_movements im
     INNER JOIN parts p ON p.id = im.part_id
     INNER JOIN garages g ON g.id = im.garage_id
     LEFT JOIN job_cards jc_ref ON jc_ref.id = im.reference_id AND im.reference_type = "JOB_CARD"
     LEFT JOIN inventory_transfers tr ON tr.id = im.reference_id AND im.reference_type = "TRANSFER"
     WHERE im.company_id = :company_id
       AND p.company_id = :company_id
       AND p.status_code = "ACTIVE"
       AND g.company_id = :company_id
       AND (g.status_code IS NULL OR g.status_code = "ACTIVE")
       ' . $movementSummaryScopeSql . '
       AND im.created_at BETWEEN :from_dt AND :to_dt
       AND (im.reference_type <> "JOB_CARD" OR (jc_ref.id IS NOT NULL AND jc_ref.company_id = :company_id AND jc_ref.status = "CLOSED" AND jc_ref.status_code = "ACTIVE"))
       AND (im.reference_type <> "TRANSFER" OR (tr.id IS NOT NULL AND tr.company_id = :company_id AND tr.status_code = "POSTED"))
     GROUP BY im.movement_type, im.reference_type
     ORDER BY im.movement_type ASC, im.reference_type ASC'
);
$movementSummaryStmt->execute($movementSummaryParams);
$movementSummaryRows = $movementSummaryStmt->fetchAll();

$partsUsageParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
$partsUsageScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $partsUsageParams, 'parts_scope');
$partsUsageStmt = db()->prepare(
    'SELECT p.part_name, p.part_sku, p.unit,
            COUNT(DISTINCT jc.id) AS jobs_count,
            COALESCE(SUM(jp.quantity), 0) AS total_qty,
            COALESCE(SUM(jp.total_amount), 0) AS usage_value
     FROM job_parts jp
     INNER JOIN job_cards jc ON jc.id = jp.job_card_id
     INNER JOIN invoices i ON i.job_card_id = jc.id
     INNER JOIN parts p ON p.id = jp.part_id
     WHERE jc.company_id = :company_id
       AND jc.status = "CLOSED"
       AND jc.status_code = "ACTIVE"
       AND i.company_id = :company_id
       AND i.invoice_status = "FINALIZED"
       AND p.company_id = :company_id
       AND p.status_code = "ACTIVE"
       ' . $partsUsageScopeSql . '
       AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
     GROUP BY p.id, p.part_name, p.part_sku, p.unit
     ORDER BY total_qty DESC
     LIMIT 20'
);
$partsUsageStmt->execute($partsUsageParams);
$partsUsageRows = $partsUsageStmt->fetchAll();

$visAvailable = false;
$visError = null;
$visModelPopularity = [];
$visPartsByVehicleTypeBucket = [];

try {
    $visVariantCount = (int) db()->query('SELECT COUNT(*) FROM vis_variants WHERE status_code = "ACTIVE"')->fetchColumn();
    if ($visVariantCount > 0) {
        $visAvailable = true;

        $visModelParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
        $visModelScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $visModelParams, 'vis_model_scope');
        $visModelVehicleScopeSql = vehicle_master_scope_sql('v', $reportVehicleFilters, $visModelParams, 'report_vis_model_scope');
        $visModelStmt = db()->prepare(
            'SELECT vb.brand_name, vm.model_name, COUNT(DISTINCT jc.id) AS service_count
             FROM job_cards jc
             INNER JOIN invoices i ON i.job_card_id = jc.id
             INNER JOIN vehicles v ON v.id = jc.vehicle_id
             INNER JOIN vis_variants vv ON vv.id = v.vis_variant_id
             INNER JOIN vis_models vm ON vm.id = vv.model_id
             INNER JOIN vis_brands vb ON vb.id = vm.brand_id
             WHERE jc.company_id = :company_id
               AND jc.status = "CLOSED"
               AND jc.status_code = "ACTIVE"
               AND i.company_id = :company_id
               AND i.invoice_status = "FINALIZED"
               AND v.company_id = :company_id
               AND v.status_code = "ACTIVE"
               ' . $visModelVehicleScopeSql . '
               AND vv.status_code = "ACTIVE"
               AND vm.status_code = "ACTIVE"
               AND vb.status_code = "ACTIVE"
               ' . $visModelScopeSql . '
               AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
             GROUP BY vb.id, vb.brand_name, vm.id, vm.model_name
             ORDER BY service_count DESC
             LIMIT 12'
        );
        $visModelStmt->execute($visModelParams);
        $visModelPopularity = $visModelStmt->fetchAll();

        $visPartsParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
        $visPartsScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $visPartsParams, 'vis_parts_scope');
        $visPartsVehicleScopeSql = vehicle_master_scope_sql('v', $reportVehicleFilters, $visPartsParams, 'report_vis_parts_scope');
        $visPartsStmt = db()->prepare(
            'SELECT vm.vehicle_type, p.part_name, p.part_sku, COALESCE(SUM(jp.quantity), 0) AS total_qty
             FROM job_parts jp
             INNER JOIN job_cards jc ON jc.id = jp.job_card_id
             INNER JOIN invoices i ON i.job_card_id = jc.id
             INNER JOIN vehicles v ON v.id = jc.vehicle_id
             INNER JOIN vis_variants vv ON vv.id = v.vis_variant_id
             INNER JOIN vis_models vm ON vm.id = vv.model_id
             INNER JOIN parts p ON p.id = jp.part_id
             WHERE jc.company_id = :company_id
               AND jc.status = "CLOSED"
               AND jc.status_code = "ACTIVE"
               AND i.company_id = :company_id
               AND i.invoice_status = "FINALIZED"
               AND v.company_id = :company_id
               AND v.status_code = "ACTIVE"
               ' . $visPartsVehicleScopeSql . '
               AND vv.status_code = "ACTIVE"
               AND vm.status_code = "ACTIVE"
               AND p.company_id = :company_id
               AND p.status_code = "ACTIVE"
               ' . $visPartsScopeSql . '
               AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
             GROUP BY vm.vehicle_type, p.id, p.part_name, p.part_sku
             ORDER BY vm.vehicle_type ASC, total_qty DESC'
        );
        $visPartsStmt->execute($visPartsParams);
        foreach ($visPartsStmt->fetchAll() as $row) {
            $type = (string) ($row['vehicle_type'] ?? 'UNKNOWN');
            if (!isset($visPartsByVehicleTypeBucket[$type])) {
                $visPartsByVehicleTypeBucket[$type] = [];
            }
            if (count($visPartsByVehicleTypeBucket[$type]) < 5) {
                $visPartsByVehicleTypeBucket[$type][] = $row;
            }
        }
    }
} catch (Throwable $exception) {
    $visAvailable = false;
    $visError = 'VIS data unavailable. Reports continue using core audited data.';
}

$operationalClosedJobs = (int) ($avgCompletion['closed_jobs'] ?? 0);
$avgCompletionHours = (float) ($avgCompletion['avg_hours'] ?? 0);
$repeatCustomerCount = count($repeatCustomers);
$totalStockValue = array_reduce($stockValuationRows, static fn (float $sum, array $row): float => $sum + (float) ($row['stock_value'] ?? 0), 0.0);
$totalOutstanding = array_reduce($outstandingReceivables, static fn (float $sum, array $row): float => $sum + (float) ($row['outstanding_amount'] ?? 0), 0.0);
$estimateTotalCount = (int) ($estimateConversion['total_count'] ?? 0);
$estimateApprovedPool = (int) ($estimateConversion['approved_pool'] ?? 0);
$estimateConversionRatio = (float) ($estimateConversion['conversion_ratio'] ?? 0);

$exportKey = trim((string) ($_GET['export'] ?? ''));
if ($exportKey !== '') {
    if (!$canExportData) {
        http_response_code(403);
        exit('Export access denied.');
    }
    $timestamp = date('Ymd_His');

    switch ($exportKey) {
        case 'job_status':
            $rows = array_map(static fn (array $row): array => [$row['status'], (int) $row['total']], $jobStatusRows);
            reports_csv_download('job_status_' . $timestamp . '.csv', ['Status', 'Total Jobs'], $rows);

        case 'mechanic_productivity':
            $rows = array_map(static fn (array $row): array => [$row['mechanic_name'], (int) ($row['assigned_jobs'] ?? 0), (int) ($row['closed_jobs'] ?? 0), (float) ($row['avg_close_hours'] ?? 0)], $mechanicRows);
            reports_csv_download('mechanic_productivity_' . $timestamp . '.csv', ['Mechanic', 'Assigned Jobs', 'Closed Jobs', 'Avg Close Hours'], $rows);

        case 'estimate_conversion':
            $rows = [
                [
                    (int) ($estimateConversion['total_count'] ?? 0),
                    (int) ($estimateConversion['draft_count'] ?? 0),
                    (int) ($estimateConversion['approved_count'] ?? 0),
                    (int) ($estimateConversion['rejected_count'] ?? 0),
                    (int) ($estimateConversion['converted_count'] ?? 0),
                    (int) ($estimateConversion['approved_pool'] ?? 0),
                    (float) ($estimateConversion['conversion_ratio'] ?? 0),
                ],
            ];
            reports_csv_download(
                'estimate_conversion_' . $timestamp . '.csv',
                ['Total Estimates', 'Draft', 'Approved', 'Rejected', 'Converted', 'Approved Pool', 'Conversion Ratio %'],
                $rows
            );

        case 'repeat_customers':
            $rows = array_map(static fn (array $row): array => [$row['full_name'], $row['phone'], (int) ($row['service_count'] ?? 0), $row['last_service_at']], $repeatCustomers);
            reports_csv_download('repeat_customers_' . $timestamp . '.csv', ['Customer', 'Phone', 'Service Count', 'Last Service'], $rows);

        case 'top_customers':
            $rows = array_map(static fn (array $row): array => [$row['full_name'], (int) ($row['finalized_invoice_count'] ?? 0), (float) ($row['revenue_total'] ?? 0)], $topCustomers);
            reports_csv_download('top_customers_' . $timestamp . '.csv', ['Customer', 'Finalized Invoices', 'Revenue'], $rows);

        case 'serviced_models':
            $rows = array_map(static fn (array $row): array => [$row['brand'], $row['model'], (int) ($row['service_count'] ?? 0)], $servicedModels);
            reports_csv_download('serviced_models_' . $timestamp . '.csv', ['Brand', 'Model', 'Service Count'], $rows);

        case 'service_frequency':
            $rows = array_map(static fn (array $row): array => [$row['registration_no'], (int) ($row['service_count'] ?? 0), $row['avg_days_between_services']], $serviceFrequencyRows);
            reports_csv_download('service_frequency_' . $timestamp . '.csv', ['Registration', 'Services', 'Avg Days Between'], $rows);

        case 'stock_valuation':
            $rows = array_map(static fn (array $row): array => [$row['garage_name'], (float) ($row['total_qty'] ?? 0), (float) ($row['stock_value'] ?? 0), (int) ($row['low_stock_parts'] ?? 0)], $stockValuationRows);
            reports_csv_download('stock_valuation_' . $timestamp . '.csv', ['Garage', 'Total Qty', 'Stock Value', 'Low Stock Parts'], $rows);

        case 'fast_moving_stock':
            $rows = array_map(static fn (array $row): array => [$row['part_name'], $row['part_sku'], (float) ($row['out_qty'] ?? 0), (int) ($row['movement_count'] ?? 0)], $fastMovingStockRows);
            reports_csv_download('fast_moving_stock_' . $timestamp . '.csv', ['Part', 'SKU', 'OUT Qty', 'Movement Count'], $rows);

        case 'dead_stock':
            $rows = array_map(static fn (array $row): array => [$row['part_name'], $row['part_sku'], (float) ($row['stock_qty'] ?? 0), (float) ($row['stock_value'] ?? 0)], $deadStockRows);
            reports_csv_download('dead_stock_' . $timestamp . '.csv', ['Part', 'SKU', 'Stock Qty', 'Stock Value'], $rows);

        case 'movement_summary':
            $rows = array_map(static fn (array $row): array => [$row['movement_type'], $row['reference_type'], (int) ($row['movement_count'] ?? 0), (float) ($row['signed_qty'] ?? 0)], $movementSummaryRows);
            reports_csv_download('movement_summary_' . $timestamp . '.csv', ['Movement Type', 'Source', 'Entries', 'Signed Qty'], $rows);

        case 'parts_usage':
            $rows = array_map(static fn (array $row): array => [$row['part_name'], (int) ($row['jobs_count'] ?? 0), (float) ($row['total_qty'] ?? 0), (float) ($row['usage_value'] ?? 0)], $partsUsageRows);
            reports_csv_download('parts_usage_' . $timestamp . '.csv', ['Part', 'Jobs', 'Total Qty', 'Usage Value'], $rows);

        case 'outsource_payables':
            $rows = array_map(
                static fn (array $row): array => [
                    $row['outsource_date'],
                    $row['job_number'],
                    $row['customer_name'],
                    $row['registration_no'],
                    $row['outsource_partner'],
                    $row['description'],
                    (float) ($row['outsource_cost'] ?? 0),
                    $row['outsource_payable_status'],
                    $row['outsource_paid_at'],
                ],
                $outsourcePayableRows
            );
            reports_csv_download('outsource_payables_' . $timestamp . '.csv', ['Date', 'Job', 'Customer', 'Vehicle', 'Outsourced To', 'Service', 'Cost', 'Payable Status', 'Paid At'], $rows);

        case 'outsource_paid_unpaid':
            $rows = array_map(
                static fn (array $row): array => [$row['status'], (int) ($row['line_count'] ?? 0), (float) ($row['payable_total'] ?? 0)],
                $outsourcePayableSummaryRows
            );
            reports_csv_download('outsource_paid_unpaid_' . $timestamp . '.csv', ['Status', 'Lines', 'Payable Amount'], $rows);

        case 'revenue_daily':
        case 'revenue_monthly':
        case 'revenue_garage':
        case 'gst_summary':
        case 'payment_modes':
        case 'receivables':
        case 'cancelled_impact':
            if (!$canViewFinancial) {
                http_response_code(403);
                exit('Financial export access denied.');
            }
            if ($exportKey === 'revenue_daily') {
                $rows = array_map(static fn (array $row): array => [$row['invoice_date'], (int) ($row['invoice_count'] ?? 0), (float) ($row['revenue_total'] ?? 0)], $revenueDaily);
                reports_csv_download('revenue_daily_' . $timestamp . '.csv', ['Date', 'Finalized Invoices', 'Revenue'], $rows);
            }
            if ($exportKey === 'revenue_monthly') {
                $rows = array_map(static fn (array $row): array => [$row['revenue_month'], (int) ($row['invoice_count'] ?? 0), (float) ($row['revenue_total'] ?? 0)], $revenueMonthly);
                reports_csv_download('revenue_monthly_' . $timestamp . '.csv', ['Month', 'Finalized Invoices', 'Revenue'], $rows);
            }
            if ($exportKey === 'revenue_garage') {
                $rows = array_map(static fn (array $row): array => [$row['garage_name'], (int) ($row['invoice_count'] ?? 0), (float) ($row['revenue_total'] ?? 0)], $revenueGarageWise);
                reports_csv_download('revenue_garage_' . $timestamp . '.csv', ['Garage', 'Finalized Invoices', 'Revenue'], $rows);
            }
            if ($exportKey === 'gst_summary') {
                $rows = [[(int) ($gstSummary['invoice_count'] ?? 0), (float) ($gstSummary['taxable_total'] ?? 0), (float) ($gstSummary['cgst_total'] ?? 0), (float) ($gstSummary['sgst_total'] ?? 0), (float) ($gstSummary['igst_total'] ?? 0), (float) ($gstSummary['tax_total'] ?? 0), (float) ($gstSummary['grand_total'] ?? 0)]];
                reports_csv_download('gst_summary_' . $timestamp . '.csv', ['Finalized Invoices', 'Taxable', 'CGST', 'SGST', 'IGST', 'Total GST', 'Grand Total'], $rows);
            }
            if ($exportKey === 'payment_modes') {
                $rows = array_map(static fn (array $row): array => [$row['payment_mode'], (int) ($row['payment_count'] ?? 0), (float) ($row['collected_amount'] ?? 0)], $paymentModeSummary);
                reports_csv_download('payment_modes_' . $timestamp . '.csv', ['Payment Mode', 'Entries', 'Collected Amount'], $rows);
            }
            if ($exportKey === 'receivables') {
                $rows = array_map(static fn (array $row): array => [$row['invoice_number'], $row['invoice_date'], $row['customer_name'], (float) ($row['outstanding_amount'] ?? 0), (int) ($row['overdue_days'] ?? 0)], $outstandingReceivables);
                reports_csv_download('receivables_' . $timestamp . '.csv', ['Invoice', 'Date', 'Customer', 'Outstanding', 'Overdue Days'], $rows);
            }
            if ($exportKey === 'cancelled_impact') {
                $rows = array_map(static fn (array $row): array => [$row['invoice_number'], $row['garage_name'], (float) ($row['grand_total'] ?? 0), $row['cancel_reason']], $cancelledImpactRows);
                reports_csv_download('cancelled_impact_' . $timestamp . '.csv', ['Invoice', 'Garage', 'Amount', 'Reason'], $rows);
            }
            break;

        case 'vis_models':
        case 'vis_parts_vehicle_type':
            if (!$visAvailable) {
                http_response_code(404);
                exit('VIS data not available for export.');
            }
            if ($exportKey === 'vis_models') {
                $rows = array_map(static fn (array $row): array => [$row['brand_name'], $row['model_name'], (int) ($row['service_count'] ?? 0)], $visModelPopularity);
                reports_csv_download('vis_models_' . $timestamp . '.csv', ['Brand', 'Model', 'Services'], $rows);
            }
            $rows = [];
            foreach ($visPartsByVehicleTypeBucket as $vehicleType => $items) {
                foreach ($items as $row) {
                    $rows[] = [$vehicleType, $row['part_name'], (float) ($row['total_qty'] ?? 0)];
                }
            }
            reports_csv_download('vis_parts_by_vehicle_type_' . $timestamp . '.csv', ['Vehicle Type', 'Part', 'Qty'], $rows);

        default:
            flash_set('report_error', 'Unknown export requested.', 'warning');
            redirect('modules/reports/index.php?' . http_build_query($exportBaseParams));
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Reports & Analytics Intelligence</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Reports</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Filters & Data Scope</h3></div>
        <div class="card-body">
          <form method="get" class="row g-2 align-items-end">
            <?php if ($allowAllGarages || count($garageIds) > 1): ?>
              <div class="col-md-3">
                <label class="form-label">Garage Scope</label>
                <select name="garage_id" class="form-select">
                  <?php if ($allowAllGarages): ?>
                    <option value="0" <?= $selectedGarageId === 0 ? 'selected' : ''; ?>>All Accessible Garages</option>
                  <?php endif; ?>
                  <?php foreach ($garageOptions as $garage): ?>
                    <option value="<?= (int) $garage['id']; ?>" <?= ((int) $garage['id'] === $selectedGarageId) ? 'selected' : ''; ?>><?= e((string) $garage['name']); ?> (<?= e((string) $garage['code']); ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <div class="col-md-3">
                <label class="form-label">Garage Scope</label>
                <input type="text" class="form-control" value="<?= e($scopeGarageLabel); ?>" readonly />
                <input type="hidden" name="garage_id" value="<?= (int) $selectedGarageId; ?>" />
              </div>
            <?php endif; ?>
            <div class="col-md-3">
              <label class="form-label">Financial Year</label>
              <select name="fy_id" class="form-select">
                <?php if (empty($financialYears)): ?>
                  <option value="0" selected><?= e($fyLabel); ?></option>
                <?php else: ?>
                  <?php foreach ($financialYears as $fy): ?>
                    <option value="<?= (int) $fy['id']; ?>" <?= ((int) $fy['id'] === $selectedFyId) ? 'selected' : ''; ?>><?= e((string) $fy['fy_label']); ?></option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>
            <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" required /></div>
            <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" required /></div>
            <?php if ($vehicleAttributeEnabled): ?>
              <div class="col-12" data-vehicle-attributes-root="1" data-vehicle-attributes-mode="filter" data-vehicle-attributes-endpoint="<?= e($vehicleAttributesApiUrl); ?>">
                <div class="row g-2">
                  <div class="col-md-2">
                    <label class="form-label">Brand</label>
                    <select name="report_brand_id" data-vehicle-attr="brand" data-selected-id="<?= e((string) $reportBrandId); ?>" class="form-select">
                      <option value="">All Brands</option>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Model</label>
                    <select name="report_model_id" data-vehicle-attr="model" data-selected-id="<?= e((string) $reportModelId); ?>" class="form-select">
                      <option value="">All Models</option>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Variant</label>
                    <select name="report_variant_id" data-vehicle-attr="variant" data-selected-id="<?= e((string) $reportVariantId); ?>" class="form-select">
                      <option value="">All Variants</option>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Year</label>
                    <select name="report_model_year_id" data-vehicle-attr="model_year" data-selected-id="<?= e((string) $reportModelYearId); ?>" class="form-select">
                      <option value="">All Years</option>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Color</label>
                    <select name="report_color_id" data-vehicle-attr="color" data-selected-id="<?= e((string) $reportColorId); ?>" class="form-select">
                      <option value="">All Colors</option>
                    </select>
                  </div>
                </div>
                <div class="form-hint mt-1">Vehicle filters apply to vehicle-focused report sections and VIS insights.</div>
              </div>
            <?php endif; ?>
            <div class="col-md-2 d-flex gap-2"><button type="submit" class="btn btn-primary">Apply</button><a href="<?= e(url('modules/reports/index.php')); ?>" class="btn btn-outline-secondary">Reset</a></div>
          </form>
          <div class="mt-3">
            <span class="badge text-bg-light border me-2">Garage: <?= e($scopeGarageLabel); ?></span>
            <span class="badge text-bg-light border me-2">FY: <?= e($fyLabel); ?></span>
            <span class="badge text-bg-light border me-2">Range: <?= e($fromDate); ?> to <?= e($toDate); ?></span>
            <span class="badge text-bg-light border">Integrity: Closed Jobs + Finalized Invoices + Valid Movements</span>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-card-checklist"></i></span><div class="info-box-content"><span class="info-box-text">Closed Jobs</span><span class="info-box-number"><?= number_format($operationalClosedJobs); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-info"><i class="bi bi-stopwatch"></i></span><div class="info-box-content"><span class="info-box-text">Avg Completion (Hours)</span><span class="info-box-number"><?= e(number_format($avgCompletionHours, 2)); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-warning"><i class="bi bi-person-check"></i></span><div class="info-box-content"><span class="info-box-text">Repeat Customers</span><span class="info-box-number"><?= number_format($repeatCustomerCount); ?></span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-primary"><i class="bi bi-box-seam"></i></span><div class="info-box-content"><span class="info-box-text">Stock Valuation</span><span class="info-box-number"><?= e(format_currency($totalStockValue)); ?></span></div></div></div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="info-box"><span class="info-box-icon text-bg-secondary"><i class="bi bi-file-earmark-text"></i></span><div class="info-box-content"><span class="info-box-text">Estimates Created</span><span class="info-box-number"><?= number_format($estimateTotalCount); ?></span></div></div></div>
        <div class="col-md-4"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-check2-square"></i></span><div class="info-box-content"><span class="info-box-text">Approved Pool</span><span class="info-box-number"><?= number_format($estimateApprovedPool); ?></span></div></div></div>
        <div class="col-md-4"><div class="info-box"><span class="info-box-icon text-bg-primary"><i class="bi bi-arrow-left-right"></i></span><div class="info-box-content"><span class="info-box-text">Estimate Conversion %</span><span class="info-box-number"><?= e(number_format($estimateConversionRatio, 2)); ?>%</span></div></div></div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="info-box"><span class="info-box-icon text-bg-warning"><i class="bi bi-tools"></i></span><div class="info-box-content"><span class="info-box-text">Outsourced Lines</span><span class="info-box-number"><?= number_format((int) ($outsourcePayableTotals['line_count'] ?? 0)); ?></span></div></div></div>
        <div class="col-md-4"><div class="info-box"><span class="info-box-icon text-bg-danger"><i class="bi bi-cash-stack"></i></span><div class="info-box-content"><span class="info-box-text">Outsource Payable (Unpaid)</span><span class="info-box-number"><?= e(format_currency((float) ($outsourcePayableTotals['unpaid_total'] ?? 0))); ?></span></div></div></div>
        <div class="col-md-4"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-cash-coin"></i></span><div class="info-box-content"><span class="info-box-text">Outsource Paid</span><span class="info-box-number"><?= e(format_currency((float) ($outsourcePayableTotals['paid_total'] ?? 0))); ?></span></div></div></div>
      </div>

      <?php if ($canViewFinancial): ?>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-currency-rupee"></i></span><div class="info-box-content"><span class="info-box-text">Finalized Revenue</span><span class="info-box-number"><?= e(format_currency((float) ($gstSummary['grand_total'] ?? 0))); ?></span></div></div></div>
        <div class="col-md-6"><div class="info-box"><span class="info-box-icon text-bg-danger"><i class="bi bi-exclamation-diamond"></i></span><div class="info-box-content"><span class="info-box-text">Outstanding Receivables</span><span class="info-box-number"><?= e(format_currency($totalOutstanding)); ?></span></div></div></div>
      </div>
      <?php endif; ?>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Job Reports</h3><a href="<?= e(reports_export_url($exportBaseParams, 'job_status')); ?>" class="btn btn-sm btn-outline-primary">Export CSV</a></div>
        <div class="card-body row g-3">
          <div class="col-lg-5">
            <div class="table-responsive">
              <table class="table table-striped mb-0">
                <thead><tr><th>Status</th><th>Total</th><th>Distribution</th></tr></thead>
                <tbody>
                  <?php if (empty($jobStatusRows)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No jobs in selected scope.</td></tr>
                  <?php else: ?>
                    <?php $maxJobStatus = (float) max(array_map(static fn (array $row): int => (int) $row['total'], $jobStatusRows)); ?>
                    <?php foreach ($jobStatusRows as $row): ?>
                      <?php $count = (int) $row['total']; ?>
                      <tr><td><?= e((string) $row['status']); ?></td><td><?= $count; ?></td><td style="min-width:160px;"><div class="progress progress-xs"><div class="progress-bar bg-secondary" style="width: <?= e((string) analytics_progress_width((float) $count, $maxJobStatus)); ?>%"></div></div></td></tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
          <div class="col-lg-7">
            <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">Mechanic Workload & Productivity</h6><a href="<?= e(reports_export_url($exportBaseParams, 'mechanic_productivity')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a></div>
            <div class="table-responsive">
              <table class="table table-sm table-striped mb-0"><thead><tr><th>Mechanic</th><th>Assigned</th><th>Closed</th><th>Close %</th><th>Avg Hours</th></tr></thead><tbody>
                <?php if (empty($mechanicRows)): ?>
                  <tr><td colspan="5" class="text-center text-muted py-4">No mechanic assignment data.</td></tr>
                <?php else: ?>
                  <?php foreach ($mechanicRows as $row): ?>
                    <?php $assigned = (int) ($row['assigned_jobs'] ?? 0); $closed = (int) ($row['closed_jobs'] ?? 0); $closeRate = $assigned > 0 ? round(($closed * 100) / $assigned, 2) : 0.0; ?>
                    <tr><td><?= e((string) $row['mechanic_name']); ?></td><td><?= $assigned; ?></td><td><?= $closed; ?></td><td><?= e(number_format($closeRate, 2)); ?>%</td><td><?= e(number_format((float) ($row['avg_close_hours'] ?? 0), 2)); ?></td></tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody></table>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Customer Reports</h3><a href="<?= e(reports_export_url($exportBaseParams, 'repeat_customers')); ?>" class="btn btn-sm btn-outline-primary">Repeat CSV</a></div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-striped mb-0">
                <thead><tr><th>Customer</th><th>Phone</th><th>Services</th><th>Last Service</th></tr></thead>
                <tbody>
                  <?php if (empty($repeatCustomers)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No repeat customers in selected range.</td></tr>
                  <?php else: foreach ($repeatCustomers as $row): ?>
                    <tr><td><?= e((string) $row['full_name']); ?></td><td><?= e((string) $row['phone']); ?></td><td><?= (int) ($row['service_count'] ?? 0); ?></td><td><?= e((string) ($row['last_service_at'] ?? '-')); ?></td></tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Top Customers by Revenue</h3><a href="<?= e(reports_export_url($exportBaseParams, 'top_customers')); ?>" class="btn btn-sm btn-outline-primary">CSV</a></div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-striped mb-0">
                <thead><tr><th>Customer</th><th>Invoices</th><th>Revenue</th></tr></thead>
                <tbody>
                  <?php if (empty($topCustomers)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No finalized invoice data.</td></tr>
                  <?php else: foreach ($topCustomers as $row): ?>
                    <tr><td><?= e((string) $row['full_name']); ?></td><td><?= (int) ($row['finalized_invoice_count'] ?? 0); ?></td><td><?= e(format_currency((float) ($row['revenue_total'] ?? 0))); ?></td></tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Estimate Conversion Reports</h3>
          <a href="<?= e(reports_export_url($exportBaseParams, 'estimate_conversion')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
        </div>
        <div class="card-body row g-3">
          <div class="col-lg-6">
            <div class="table-responsive">
              <table class="table table-striped mb-0">
                <thead><tr><th>Status</th><th>Total</th></tr></thead>
                <tbody>
                  <?php if (empty($estimateStatusRows)): ?>
                    <tr><td colspan="2" class="text-center text-muted py-4">Estimate module data not available.</td></tr>
                  <?php else: ?>
                    <?php foreach ($estimateStatusRows as $row): ?>
                      <tr><td><?= e((string) $row['status']); ?></td><td><?= (int) ($row['total'] ?? 0); ?></td></tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card card-outline card-primary h-100 mb-0">
              <div class="card-body">
                <div class="mb-2"><strong>Total Estimates:</strong> <?= number_format($estimateTotalCount); ?></div>
                <div class="mb-2"><strong>Approved Pool:</strong> <?= number_format($estimateApprovedPool); ?> (APPROVED + CONVERTED)</div>
                <div class="mb-2"><strong>Converted:</strong> <?= number_format((int) ($estimateConversion['converted_count'] ?? 0)); ?></div>
                <div class="mb-2"><strong>Conversion Ratio:</strong> <?= e(number_format($estimateConversionRatio, 2)); ?>%</div>
                <div class="progress progress-sm mt-3">
                  <div class="progress-bar bg-primary" style="width: <?= e((string) max(0, min(100, $estimateConversionRatio))); ?>%"></div>
                </div>
                <small class="text-muted">Ratio = Converted / (Approved + Converted)</small>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Most Serviced Models</h3><a href="<?= e(reports_export_url($exportBaseParams, 'serviced_models')); ?>" class="btn btn-sm btn-outline-primary">CSV</a></div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-striped mb-0">
                <thead><tr><th>Brand</th><th>Model</th><th>Services</th></tr></thead>
                <tbody>
                  <?php if (empty($servicedModels)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No serviced model data.</td></tr>
                  <?php else: foreach ($servicedModels as $row): ?>
                    <tr><td><?= e((string) $row['brand']); ?></td><td><?= e((string) $row['model']); ?></td><td><?= (int) ($row['service_count'] ?? 0); ?></td></tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Vehicle Service Frequency</h3><a href="<?= e(reports_export_url($exportBaseParams, 'service_frequency')); ?>" class="btn btn-sm btn-outline-primary">CSV</a></div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Vehicle</th><th>Services</th><th>Avg Days Between</th></tr></thead>
                <tbody>
                  <?php if (empty($serviceFrequencyRows)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No service-frequency data.</td></tr>
                  <?php else: foreach ($serviceFrequencyRows as $row): ?>
                    <tr><td><?= e((string) $row['registration_no']); ?><div class="text-muted small"><?= e((string) $row['brand']); ?> <?= e((string) $row['model']); ?></div></td><td><?= (int) ($row['service_count'] ?? 0); ?></td><td><?= $row['avg_days_between_services'] !== null ? e(number_format((float) $row['avg_days_between_services'], 1)) : '-'; ?></td></tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title mb-0">Outsourced Service Payables</h3></div>
        <div class="card-body row g-3">
          <div class="col-lg-4">
            <div class="card card-outline card-warning h-100 mb-0">
              <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Paid vs Unpaid</h3><a href="<?= e(reports_export_url($exportBaseParams, 'outsource_paid_unpaid')); ?>" class="btn btn-sm btn-outline-warning">CSV</a></div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Status</th><th>Lines</th><th>Amount</th></tr></thead>
                  <tbody>
                    <?php if (empty($outsourcePayableSummaryRows)): ?>
                      <tr><td colspan="3" class="text-center text-muted py-4">No outsourced payable summary.</td></tr>
                    <?php else: foreach ($outsourcePayableSummaryRows as $row): ?>
                      <tr>
                        <td><span class="badge text-bg-<?= ((string) ($row['status'] ?? '') === 'PAID') ? 'success' : 'danger'; ?>"><?= e((string) ($row['status'] ?? 'UNPAID')); ?></span></td>
                        <td><?= (int) ($row['line_count'] ?? 0); ?></td>
                        <td><?= e(format_currency((float) ($row['payable_total'] ?? 0))); ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-lg-8">
            <div class="card card-outline card-secondary h-100 mb-0">
              <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Outsourced Payable Detail</h3><a href="<?= e(reports_export_url($exportBaseParams, 'outsource_payables')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a></div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Date</th><th>Job</th><th>Customer</th><th>Vehicle</th><th>Outsourced To</th><th>Service</th><th>Cost</th><th>Status</th><th>Paid At</th></tr></thead>
                  <tbody>
                    <?php if (empty($outsourcePayableRows)): ?>
                      <tr><td colspan="9" class="text-center text-muted py-4">No outsourced payable rows for selected range.</td></tr>
                    <?php else: foreach ($outsourcePayableRows as $row): ?>
                      <tr>
                        <td><?= e((string) ($row['outsource_date'] ?? '-')); ?></td>
                        <td><?= e((string) ($row['job_number'] ?? '-')); ?></td>
                        <td><?= e((string) ($row['customer_name'] ?? '-')); ?></td>
                        <td><?= e((string) ($row['registration_no'] ?? '-')); ?></td>
                        <td><?= e((string) ($row['outsource_partner'] ?? '-')); ?></td>
                        <td><?= e((string) ($row['description'] ?? '-')); ?></td>
                        <td><?= e(format_currency((float) ($row['outsource_cost'] ?? 0))); ?></td>
                        <td><span class="badge text-bg-<?= ((string) ($row['outsource_payable_status'] ?? '') === 'PAID') ? 'success' : 'danger'; ?>"><?= e((string) ($row['outsource_payable_status'] ?? 'UNPAID')); ?></span></td>
                        <td><?= e((string) (($row['outsource_paid_at'] ?? '') !== '' ? $row['outsource_paid_at'] : '-')); ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <?php if ($canViewFinancial): ?>
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title mb-0">Financial Reports (Finalized Invoices Only)</h3></div>
        <div class="card-body row g-3">
          <div class="col-lg-4">
            <div class="card card-outline card-success h-100 mb-0">
              <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Revenue Daily</h3><a href="<?= e(reports_export_url($exportBaseParams, 'revenue_daily')); ?>" class="btn btn-sm btn-outline-success">CSV</a></div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0"><thead><tr><th>Date</th><th>Invoices</th><th>Revenue</th></tr></thead><tbody>
                  <?php if (empty($revenueDaily)): ?><tr><td colspan="3" class="text-center text-muted py-4">No daily revenue rows.</td></tr><?php else: foreach ($revenueDaily as $row): ?>
                    <tr><td><?= e((string) $row['invoice_date']); ?></td><td><?= (int) ($row['invoice_count'] ?? 0); ?></td><td><?= e(format_currency((float) ($row['revenue_total'] ?? 0))); ?></td></tr>
                  <?php endforeach; endif; ?>
                </tbody></table>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card card-outline card-primary h-100 mb-0">
              <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Revenue Monthly</h3><a href="<?= e(reports_export_url($exportBaseParams, 'revenue_monthly')); ?>" class="btn btn-sm btn-outline-primary">CSV</a></div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0"><thead><tr><th>Month</th><th>Invoices</th><th>Revenue</th></tr></thead><tbody>
                  <?php if (empty($revenueMonthly)): ?><tr><td colspan="3" class="text-center text-muted py-4">No monthly revenue rows.</td></tr><?php else: foreach ($revenueMonthly as $row): ?>
                    <tr><td><?= e((string) $row['revenue_month']); ?></td><td><?= (int) ($row['invoice_count'] ?? 0); ?></td><td><?= e(format_currency((float) ($row['revenue_total'] ?? 0))); ?></td></tr>
                  <?php endforeach; endif; ?>
                </tbody></table>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card card-outline card-info h-100 mb-0">
              <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Revenue Garage-wise</h3><a href="<?= e(reports_export_url($exportBaseParams, 'revenue_garage')); ?>" class="btn btn-sm btn-outline-info">CSV</a></div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0"><thead><tr><th>Garage</th><th>Invoices</th><th>Revenue</th></tr></thead><tbody>
                  <?php if (empty($revenueGarageWise)): ?><tr><td colspan="3" class="text-center text-muted py-4">No garage revenue rows.</td></tr><?php else: foreach ($revenueGarageWise as $row): ?>
                    <tr><td><?= e((string) $row['garage_name']); ?></td><td><?= (int) ($row['invoice_count'] ?? 0); ?></td><td><?= e(format_currency((float) ($row['revenue_total'] ?? 0))); ?></td></tr>
                  <?php endforeach; endif; ?>
                </tbody></table>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card card-outline card-warning h-100 mb-0">
              <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">GST Summary</h3><a href="<?= e(reports_export_url($exportBaseParams, 'gst_summary')); ?>" class="btn btn-sm btn-outline-warning">CSV</a></div>
              <div class="card-body">
                <div><strong>Invoices:</strong> <?= (int) ($gstSummary['invoice_count'] ?? 0); ?></div>
                <div><strong>Taxable:</strong> <?= e(format_currency((float) ($gstSummary['taxable_total'] ?? 0))); ?></div>
                <div><strong>CGST:</strong> <?= e(format_currency((float) ($gstSummary['cgst_total'] ?? 0))); ?></div>
                <div><strong>SGST:</strong> <?= e(format_currency((float) ($gstSummary['sgst_total'] ?? 0))); ?></div>
                <div><strong>IGST:</strong> <?= e(format_currency((float) ($gstSummary['igst_total'] ?? 0))); ?></div>
                <div><strong>Total GST:</strong> <?= e(format_currency((float) ($gstSummary['tax_total'] ?? 0))); ?></div>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card card-outline card-secondary h-100 mb-0">
              <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Payment Mode Summary</h3><a href="<?= e(reports_export_url($exportBaseParams, 'payment_modes')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a></div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0"><thead><tr><th>Mode</th><th>Entries</th><th>Collected</th></tr></thead><tbody>
                  <?php if (empty($paymentModeSummary)): ?><tr><td colspan="3" class="text-center text-muted py-4">No payment collection data.</td></tr><?php else: foreach ($paymentModeSummary as $row): ?>
                    <tr><td><?= e((string) $row['payment_mode']); ?></td><td><?= (int) ($row['payment_count'] ?? 0); ?></td><td><?= e(format_currency((float) ($row['collected_amount'] ?? 0))); ?></td></tr>
                  <?php endforeach; endif; ?>
                </tbody></table>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card card-outline card-danger h-100 mb-0">
              <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Cancelled Invoice Impact</h3><a href="<?= e(reports_export_url($exportBaseParams, 'cancelled_impact')); ?>" class="btn btn-sm btn-outline-danger">CSV</a></div>
              <div class="card-body">
                <div><strong>Cancelled:</strong> <?= (int) ($cancelledImpactTotals['cancelled_count'] ?? 0); ?></div>
                <div><strong>Impact:</strong> <?= e(format_currency((float) ($cancelledImpactTotals['cancelled_total'] ?? 0))); ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-7">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Outstanding Receivables</h3><a href="<?= e(reports_export_url($exportBaseParams, 'receivables')); ?>" class="btn btn-sm btn-outline-primary">CSV</a></div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0"><thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Outstanding</th><th>Overdue</th></tr></thead><tbody>
                <?php if (empty($outstandingReceivables)): ?><tr><td colspan="5" class="text-center text-muted py-4">No outstanding receivables.</td></tr><?php else: foreach ($outstandingReceivables as $row): ?>
                  <tr><td><?= e((string) $row['invoice_number']); ?></td><td><?= e((string) $row['invoice_date']); ?></td><td><?= e((string) $row['customer_name']); ?></td><td><?= e(format_currency((float) ($row['outstanding_amount'] ?? 0))); ?></td><td><?= (int) ($row['overdue_days'] ?? 0); ?> day(s)</td></tr>
                <?php endforeach; endif; ?>
              </tbody></table>
            </div>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="card h-100">
            <div class="card-header"><h3 class="card-title mb-0">Cancelled Invoice Audit List</h3></div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0"><thead><tr><th>Invoice</th><th>Garage</th><th>Total</th><th>Reason</th></tr></thead><tbody>
                <?php if (empty($cancelledImpactRows)): ?><tr><td colspan="4" class="text-center text-muted py-4">No cancelled invoices in selected range.</td></tr><?php else: foreach ($cancelledImpactRows as $row): ?>
                  <tr><td><?= e((string) $row['invoice_number']); ?></td><td><?= e((string) $row['garage_name']); ?></td><td><?= e(format_currency((float) ($row['grand_total'] ?? 0))); ?></td><td><?= e((string) ($row['cancel_reason'] ?? '-')); ?></td></tr>
                <?php endforeach; endif; ?>
              </tbody></table>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title mb-0">Inventory Reports</h3></div>
        <div class="card-body row g-3">
          <div class="col-lg-6">
            <div class="card card-outline card-primary h-100 mb-0">
              <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Stock Valuation per Garage</h3><a href="<?= e(reports_export_url($exportBaseParams, 'stock_valuation')); ?>" class="btn btn-sm btn-outline-primary">CSV</a></div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0"><thead><tr><th>Garage</th><th>Qty</th><th>Value</th><th>Low Stock</th></tr></thead><tbody>
                  <?php if (empty($stockValuationRows)): ?><tr><td colspan="4" class="text-center text-muted py-4">No stock rows.</td></tr><?php else: foreach ($stockValuationRows as $row): ?>
                    <tr><td><?= e((string) $row['garage_name']); ?></td><td><?= e(number_format((float) ($row['total_qty'] ?? 0), 2)); ?></td><td><?= e(format_currency((float) ($row['stock_value'] ?? 0))); ?></td><td><?= (int) ($row['low_stock_parts'] ?? 0); ?></td></tr>
                  <?php endforeach; endif; ?>
                </tbody></table>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card card-outline card-info h-100 mb-0">
              <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Movement Summary (Valid IN/OUT/TRANSFER)</h3><a href="<?= e(reports_export_url($exportBaseParams, 'movement_summary')); ?>" class="btn btn-sm btn-outline-info">CSV</a></div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0"><thead><tr><th>Type</th><th>Source</th><th>Entries</th><th>Signed Qty</th></tr></thead><tbody>
                  <?php if (empty($movementSummaryRows)): ?><tr><td colspan="4" class="text-center text-muted py-4">No movement rows.</td></tr><?php else: foreach ($movementSummaryRows as $row): ?>
                    <tr><td><?= e((string) $row['movement_type']); ?></td><td><?= e((string) $row['reference_type']); ?></td><td><?= (int) ($row['movement_count'] ?? 0); ?></td><td><?= e(number_format((float) ($row['signed_qty'] ?? 0), 2)); ?></td></tr>
                  <?php endforeach; endif; ?>
                </tbody></table>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card card-outline card-success h-100 mb-0">
              <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Fast-Moving Stock</h3><a href="<?= e(reports_export_url($exportBaseParams, 'fast_moving_stock')); ?>" class="btn btn-sm btn-outline-success">CSV</a></div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0"><thead><tr><th>Part</th><th>Moves</th><th>OUT Qty</th></tr></thead><tbody>
                  <?php if (empty($fastMovingStockRows)): ?><tr><td colspan="3" class="text-center text-muted py-4">No fast-moving parts.</td></tr><?php else: foreach ($fastMovingStockRows as $row): ?>
                    <tr><td><?= e((string) $row['part_name']); ?> <small class="text-muted">(<?= e((string) $row['part_sku']); ?>)</small></td><td><?= (int) ($row['movement_count'] ?? 0); ?></td><td><?= e(number_format((float) ($row['out_qty'] ?? 0), 2)); ?> <?= e((string) $row['unit']); ?></td></tr>
                  <?php endforeach; endif; ?>
                </tbody></table>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card card-outline card-warning h-100 mb-0">
              <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Dead Stock</h3><a href="<?= e(reports_export_url($exportBaseParams, 'dead_stock')); ?>" class="btn btn-sm btn-outline-warning">CSV</a></div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0"><thead><tr><th>Part</th><th>Stock Qty</th><th>Stock Value</th></tr></thead><tbody>
                  <?php if (empty($deadStockRows)): ?><tr><td colspan="3" class="text-center text-muted py-4">No dead stock under current scope.</td></tr><?php else: foreach ($deadStockRows as $row): ?>
                    <tr><td><?= e((string) $row['part_name']); ?> <small class="text-muted">(<?= e((string) $row['part_sku']); ?>)</small></td><td><?= e(number_format((float) ($row['stock_qty'] ?? 0), 2)); ?> <?= e((string) $row['unit']); ?></td><td><?= e(format_currency((float) ($row['stock_value'] ?? 0))); ?></td></tr>
                  <?php endforeach; endif; ?>
                </tbody></table>
              </div>
            </div>
          </div>

          <div class="col-12">
            <div class="card card-outline card-secondary mb-0">
              <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Job-Consumption Parts Usage</h3><a href="<?= e(reports_export_url($exportBaseParams, 'parts_usage')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a></div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0"><thead><tr><th>Part</th><th>Jobs</th><th>Total Qty</th><th>Usage Value</th></tr></thead><tbody>
                  <?php if (empty($partsUsageRows)): ?><tr><td colspan="4" class="text-center text-muted py-4">No billed job-consumption usage.</td></tr><?php else: foreach ($partsUsageRows as $row): ?>
                    <tr><td><?= e((string) $row['part_name']); ?> <small class="text-muted">(<?= e((string) $row['part_sku']); ?>)</small></td><td><?= (int) ($row['jobs_count'] ?? 0); ?></td><td><?= e(number_format((float) ($row['total_qty'] ?? 0), 2)); ?> <?= e((string) $row['unit']); ?></td><td><?= e(format_currency((float) ($row['usage_value'] ?? 0))); ?></td></tr>
                  <?php endforeach; endif; ?>
                </tbody></table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3 <?= $visAvailable ? '' : 'card-outline card-secondary'; ?>">
        <div class="card-header"><h3 class="card-title mb-0">VIS Enhanced Insights (Optional, Read-only)</h3></div>
        <div class="card-body">
          <?php if ($visError !== null): ?>
            <div class="text-muted"><?= e($visError); ?></div>
          <?php elseif (!$visAvailable): ?>
            <div class="text-muted">VIS catalog data not found. Core analytics remain fully operational.</div>
          <?php else: ?>
            <div class="row g-3">
              <div class="col-lg-6">
                <div class="card card-outline card-info h-100 mb-0">
                  <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">VIS Model Popularity</h3><a href="<?= e(reports_export_url($exportBaseParams, 'vis_models')); ?>" class="btn btn-sm btn-outline-info">CSV</a></div>
                  <div class="card-body p-0 table-responsive">
                    <table class="table table-sm table-striped mb-0"><thead><tr><th>Brand</th><th>Model</th><th>Services</th></tr></thead><tbody>
                      <?php if (empty($visModelPopularity)): ?><tr><td colspan="3" class="text-center text-muted py-4">No VIS model popularity data.</td></tr><?php else: foreach ($visModelPopularity as $row): ?>
                        <tr><td><?= e((string) $row['brand_name']); ?></td><td><?= e((string) $row['model_name']); ?></td><td><?= (int) ($row['service_count'] ?? 0); ?></td></tr>
                      <?php endforeach; endif; ?>
                    </tbody></table>
                  </div>
                </div>
              </div>
              <div class="col-lg-6">
                <div class="card card-outline card-primary h-100 mb-0">
                  <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">VIS Parts Usage by Vehicle Type</h3><a href="<?= e(reports_export_url($exportBaseParams, 'vis_parts_vehicle_type')); ?>" class="btn btn-sm btn-outline-primary">CSV</a></div>
                  <div class="card-body">
                    <?php if (empty($visPartsByVehicleTypeBucket)): ?>
                      <div class="text-muted">No VIS vehicle-type usage data.</div>
                    <?php else: foreach ($visPartsByVehicleTypeBucket as $vehicleType => $items): ?>
                      <h6 class="mb-2"><?= e((string) $vehicleType); ?></h6>
                      <div class="table-responsive mb-3">
                        <table class="table table-sm table-striped mb-0"><thead><tr><th>Part</th><th>Qty</th></tr></thead><tbody>
                          <?php foreach ($items as $row): ?>
                            <tr><td><?= e((string) $row['part_name']); ?> <small class="text-muted">(<?= e((string) $row['part_sku']); ?>)</small></td><td><?= e(number_format((float) ($row['total_qty'] ?? 0), 2)); ?></td></tr>
                          <?php endforeach; ?>
                        </tbody></table>
                      </div>
                    <?php endforeach; endif; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
