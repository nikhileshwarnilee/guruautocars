<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_login();
require_permission('dashboard.view');
require_once __DIR__ . '/modules/jobs/workflow.php';
require_once __DIR__ . '/modules/jobs/insurance.php';

$page_title = 'Dashboard Intelligence';
$active_menu = 'dashboard';

$companyId = active_company_id();
$activeGarageId = active_garage_id();
$currentUser = current_user();
$roleKey = (string) ($currentUser['role_key'] ?? ($_SESSION['role_key'] ?? ''));
$isOwnerScope = analytics_is_owner_role($roleKey);

$garageOptions = analytics_accessible_garages($companyId, $isOwnerScope);
if (empty($garageOptions) && $activeGarageId > 0) {
    $fallbackGarageStmt = db()->prepare(
        'SELECT id, name, code
         FROM garages
         WHERE id = :garage_id
           AND company_id = :company_id
         LIMIT 1'
    );
    $fallbackGarageStmt->execute([
        'garage_id' => $activeGarageId,
        'company_id' => $companyId,
    ]);
    $fallbackGarage = $fallbackGarageStmt->fetch();
    if ($fallbackGarage) {
        $garageOptions[] = $fallbackGarage;
    }
}

$garageIds = array_values(
    array_filter(
        array_map(static fn (array $garage): int => (int) ($garage['id'] ?? 0), $garageOptions),
        static fn (int $id): bool => $id > 0
    )
);

$allowAllGarages = $isOwnerScope && count($garageIds) > 1;
$garageRequested = isset($_GET['garage_id']) ? get_int('garage_id', $activeGarageId) : $activeGarageId;
$selectedGarageId = analytics_resolve_scope_garage_id($garageOptions, $activeGarageId, $garageRequested, $allowAllGarages);
$scopeGarageLabel = analytics_scope_garage_label($garageOptions, $selectedGarageId);

$fyContext = analytics_resolve_financial_year($companyId, get_int('fy_id', 0));
$financialYears = $fyContext['years'];
$selectedFy = $fyContext['selected'];

$fyStart = (string) ($selectedFy['start_date'] ?? date('Y-04-01'));
$fyEnd = (string) ($selectedFy['end_date'] ?? date('Y-03-31', strtotime('+1 year')));
$today = date('Y-m-d');
$todayBounded = $today;
if ($todayBounded < $fyStart) {
    $todayBounded = $fyStart;
}
if ($todayBounded > $fyEnd) {
    $todayBounded = $fyEnd;
}

$mtdStart = date('Y-m-01', strtotime($todayBounded));
if ($mtdStart < $fyStart) {
    $mtdStart = $fyStart;
}

$dashboardDateFilter = date_filter_resolve_request([
    'company_id' => $companyId,
    'garage_id' => $selectedGarageId,
    'range_start' => $fyStart,
    'range_end' => $fyEnd,
    'yearly_start' => $fyStart,
    'session_namespace' => 'dashboard_charts',
    'request_mode' => $_GET['date_mode'] ?? null,
    'request_from' => $_GET['from'] ?? null,
    'request_to' => $_GET['to'] ?? null,
]);
$dashboardDateMode = (string) ($dashboardDateFilter['mode'] ?? 'monthly');
$dashboardDateModeOptions = date_filter_modes();
$dashboardChartDefaultFrom = (string) ($dashboardDateFilter['from_date'] ?? $todayBounded);
$dashboardChartDefaultTo = (string) ($dashboardDateFilter['to_date'] ?? $todayBounded);
$dashboardTrendMode = strtolower(trim((string) ($_GET['trend_mode'] ?? 'daily')));
if (!in_array($dashboardTrendMode, ['daily', 'monthly'], true)) {
    $dashboardTrendMode = 'daily';
}

$canViewFinancial = has_permission('reports.financial');

if (isset($_GET['ajax']) && (string) $_GET['ajax'] === 'charts') {
    $chartFrom = (string) ($dashboardDateFilter['from_date'] ?? $dashboardChartDefaultFrom);
    $chartTo = (string) ($dashboardDateFilter['to_date'] ?? $dashboardChartDefaultTo);
    $trendMode = $dashboardTrendMode;

    $dailyTrendStart = date('Y-m-d', strtotime($chartTo . ' -29 days'));
    if ($dailyTrendStart < $fyStart) {
        $dailyTrendStart = $fyStart;
    }
    $monthlyTrendStart = date('Y-m-01', strtotime(date('Y-m-01', strtotime($chartTo)) . ' -11 months'));
    if ($monthlyTrendStart < $fyStart) {
        $monthlyTrendStart = date('Y-m-01', strtotime($fyStart));
    }

    $buildDateSeries = static function (string $startDate, string $endDate): array {
        $labels = [];
        $cursor = strtotime($startDate);
        $limit = strtotime($endDate);
        while ($cursor !== false && $limit !== false && $cursor <= $limit) {
            $labels[] = date('Y-m-d', $cursor);
            $cursor = strtotime('+1 day', $cursor);
        }
        return $labels;
    };

    $buildMonthSeries = static function (string $startDate, string $endDate): array {
        $labels = [];
        $cursor = strtotime(date('Y-m-01', strtotime($startDate)));
        $limit = strtotime(date('Y-m-01', strtotime($endDate)));
        while ($cursor !== false && $limit !== false && $cursor <= $limit) {
            $labels[] = date('Y-m', $cursor);
            $cursor = strtotime('+1 month', $cursor);
        }
        return $labels;
    };

    $chartPayload = [
        'revenue_daily' => ['labels' => [], 'values' => [], 'invoice_counts' => []],
        'revenue_monthly' => ['labels' => [], 'values' => [], 'invoice_counts' => []],
        'job_status' => ['labels' => ['Open', 'In Progress', 'Waiting Parts', 'Completed', 'Closed'], 'values' => [0, 0, 0, 0, 0]],
        'revenue_vs_expense' => ['labels' => [], 'revenue' => [], 'expense' => []],
        'top_services' => ['labels' => [], 'counts' => []],
        'inventory_movement' => ['labels' => [], 'stock_in' => [], 'stock_out' => [], 'transfers' => []],
        'payment_modes' => ['labels' => ['Cash', 'UPI', 'Card', 'Mixed'], 'values' => [0, 0, 0, 0]],
    ];

    if ($canViewFinancial) {
        $dailyParams = [
            'company_id' => $companyId,
            'from_date' => $dailyTrendStart,
            'to_date' => $chartTo,
        ];
        $dailyScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $dailyParams, 'dash_daily_scope');
        $dailyStmt = db()->prepare(
            'SELECT i.invoice_date AS bucket_date,
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(i.grand_total), 0) AS revenue_total
             FROM invoices i
             INNER JOIN job_cards jc ON jc.id = i.job_card_id
             WHERE i.company_id = :company_id
               AND i.invoice_status = "FINALIZED"
               AND jc.status = "CLOSED"
               AND jc.status_code = "ACTIVE"
               ' . $dailyScopeSql . '
               AND i.invoice_date BETWEEN :from_date AND :to_date
             GROUP BY i.invoice_date
             ORDER BY i.invoice_date ASC'
        );
        $dailyStmt->execute($dailyParams);
        $dailyRows = $dailyStmt->fetchAll();
        $dailyMap = [];
        foreach ($dailyRows as $row) {
            $bucket = (string) ($row['bucket_date'] ?? '');
            if ($bucket === '') {
                continue;
            }
            $dailyMap[$bucket] = [
                'revenue_total' => (float) ($row['revenue_total'] ?? 0),
                'invoice_count' => (int) ($row['invoice_count'] ?? 0),
            ];
        }
        $dailyLabels = $buildDateSeries($dailyTrendStart, $chartTo);
        $dailyValues = [];
        $dailyInvoices = [];
        foreach ($dailyLabels as $label) {
            $dailyValues[] = (float) ($dailyMap[$label]['revenue_total'] ?? 0);
            $dailyInvoices[] = (int) ($dailyMap[$label]['invoice_count'] ?? 0);
        }
        $chartPayload['revenue_daily'] = [
            'labels' => $dailyLabels,
            'values' => $dailyValues,
            'invoice_counts' => $dailyInvoices,
        ];

        $monthlyParams = [
            'company_id' => $companyId,
            'from_date' => $monthlyTrendStart,
            'to_date' => $chartTo,
        ];
        $monthlyScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $monthlyParams, 'dash_month_scope');
        $monthlyStmt = db()->prepare(
            'SELECT DATE_FORMAT(i.invoice_date, "%Y-%m") AS revenue_month,
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(i.grand_total), 0) AS revenue_total
             FROM invoices i
             INNER JOIN job_cards jc ON jc.id = i.job_card_id
             WHERE i.company_id = :company_id
               AND i.invoice_status = "FINALIZED"
               AND jc.status = "CLOSED"
               AND jc.status_code = "ACTIVE"
               ' . $monthlyScopeSql . '
               AND i.invoice_date BETWEEN :from_date AND :to_date
             GROUP BY DATE_FORMAT(i.invoice_date, "%Y-%m")
             ORDER BY revenue_month ASC'
        );
        $monthlyStmt->execute($monthlyParams);
        $monthlyRows = $monthlyStmt->fetchAll();
        $monthRevenueMap = [];
        foreach ($monthlyRows as $row) {
            $bucket = (string) ($row['revenue_month'] ?? '');
            if ($bucket === '') {
                continue;
            }
            $monthRevenueMap[$bucket] = [
                'revenue_total' => (float) ($row['revenue_total'] ?? 0),
                'invoice_count' => (int) ($row['invoice_count'] ?? 0),
            ];
        }
        $monthLabels = $buildMonthSeries($monthlyTrendStart, $chartTo);
        $monthValues = [];
        $monthInvoices = [];
        foreach ($monthLabels as $label) {
            $monthValues[] = (float) ($monthRevenueMap[$label]['revenue_total'] ?? 0);
            $monthInvoices[] = (int) ($monthRevenueMap[$label]['invoice_count'] ?? 0);
        }
        $chartPayload['revenue_monthly'] = [
            'labels' => $monthLabels,
            'values' => $monthValues,
            'invoice_counts' => $monthInvoices,
        ];

        $expenseParams = [
            'company_id' => $companyId,
            'from_date' => $monthlyTrendStart,
            'to_date' => $chartTo,
        ];
        $expenseScopeSql = analytics_garage_scope_sql('e.garage_id', $selectedGarageId, $garageIds, $expenseParams, 'dash_exp_scope');
        $expenseStmt = db()->prepare(
            'SELECT DATE_FORMAT(e.expense_date, "%Y-%m") AS expense_month,
                    COALESCE(SUM(e.amount), 0) AS expense_total
             FROM expenses e
             WHERE e.company_id = :company_id
               AND e.entry_type <> "DELETED"
               AND COALESCE(e.entry_type, "EXPENSE") <> "REVERSAL"
               ' . $expenseScopeSql . '
               AND e.expense_date BETWEEN :from_date AND :to_date
             GROUP BY DATE_FORMAT(e.expense_date, "%Y-%m")
             ORDER BY expense_month ASC'
        );
        $expenseStmt->execute($expenseParams);
        $expenseRows = $expenseStmt->fetchAll();
        $expenseMap = [];
        foreach ($expenseRows as $row) {
            $bucket = (string) ($row['expense_month'] ?? '');
            if ($bucket === '') {
                continue;
            }
            $expenseMap[$bucket] = (float) ($row['expense_total'] ?? 0);
        }

        $chartPayload['revenue_vs_expense'] = [
            'labels' => $monthLabels,
            'revenue' => array_map(static fn (string $label): float => (float) ($monthRevenueMap[$label]['revenue_total'] ?? 0), $monthLabels),
            'expense' => array_map(static fn (string $label): float => (float) ($expenseMap[$label] ?? 0), $monthLabels),
        ];

        $paymentParams = [
            'company_id' => $companyId,
            'from_date' => $chartFrom,
            'to_date' => $chartTo,
        ];
        $paymentScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $paymentParams, 'dash_pay_scope');
        $paymentStmt = db()->prepare(
            'SELECT CASE
                        WHEN p.payment_mode = "CASH" THEN "Cash"
                        WHEN p.payment_mode = "UPI" THEN "UPI"
                        WHEN p.payment_mode = "CARD" THEN "Card"
                        ELSE "Mixed"
                    END AS payment_bucket,
                    COALESCE(SUM(p.amount), 0) AS mode_total
             FROM payments p
             INNER JOIN invoices i ON i.id = p.invoice_id
             INNER JOIN job_cards jc ON jc.id = i.job_card_id
             WHERE i.company_id = :company_id
               AND i.invoice_status = "FINALIZED"
               AND jc.status = "CLOSED"
               AND jc.status_code = "ACTIVE"
               AND ' . reversal_sales_payment_unreversed_filter_sql('p') . '
               ' . $paymentScopeSql . '
               AND p.paid_on BETWEEN :from_date AND :to_date
             GROUP BY payment_bucket'
        );
        $paymentStmt->execute($paymentParams);
        $paymentRows = $paymentStmt->fetchAll();
        $paymentMap = ['Cash' => 0.0, 'UPI' => 0.0, 'Card' => 0.0, 'Mixed' => 0.0];
        foreach ($paymentRows as $row) {
            $bucket = (string) ($row['payment_bucket'] ?? '');
            if (!array_key_exists($bucket, $paymentMap)) {
                continue;
            }
            $paymentMap[$bucket] = max(0.0, (float) ($row['mode_total'] ?? 0));
        }
        $chartPayload['payment_modes'] = [
            'labels' => ['Cash', 'UPI', 'Card', 'Mixed'],
            'values' => [
                $paymentMap['Cash'],
                $paymentMap['UPI'],
                $paymentMap['Card'],
                $paymentMap['Mixed'],
            ],
        ];
    }

    $statusParams = [
        'company_id' => $companyId,
        'from_date' => $chartFrom,
        'to_date' => $chartTo,
    ];
    $statusScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $statusParams, 'dash_status_scope');
    $statusStmt = db()->prepare(
        'SELECT CASE
                    WHEN jc.status = "OPEN" THEN "OPEN"
                    WHEN jc.status = "IN_PROGRESS" THEN "IN_PROGRESS"
                    WHEN jc.status = "WAITING_PARTS" THEN "WAITING_PARTS"
                    WHEN jc.status IN ("COMPLETED", "READY_FOR_DELIVERY") THEN "COMPLETED"
                    WHEN jc.status = "CLOSED" THEN "CLOSED"
                    ELSE "OTHER"
                END AS status_bucket,
                COUNT(*) AS status_count
         FROM job_cards jc
         WHERE jc.company_id = :company_id
           AND jc.status_code = "ACTIVE"
           AND jc.status <> "CANCELLED"
           ' . $statusScopeSql . '
           AND DATE(COALESCE(jc.closed_at, jc.updated_at, jc.created_at)) BETWEEN :from_date AND :to_date
         GROUP BY status_bucket'
    );
    $statusStmt->execute($statusParams);
    $statusRows = $statusStmt->fetchAll();
    $statusMap = [
        'OPEN' => 0,
        'IN_PROGRESS' => 0,
        'WAITING_PARTS' => 0,
        'COMPLETED' => 0,
        'CLOSED' => 0,
    ];
    foreach ($statusRows as $row) {
        $bucket = (string) ($row['status_bucket'] ?? '');
        if (!array_key_exists($bucket, $statusMap)) {
            continue;
        }
        $statusMap[$bucket] = (int) ($row['status_count'] ?? 0);
    }
    $chartPayload['job_status'] = [
        'labels' => ['Open', 'In Progress', 'Waiting Parts', 'Completed', 'Closed'],
        'values' => [
            $statusMap['OPEN'],
            $statusMap['IN_PROGRESS'],
            $statusMap['WAITING_PARTS'],
            $statusMap['COMPLETED'],
            $statusMap['CLOSED'],
        ],
    ];

    $servicesParams = [
        'company_id' => $companyId,
        'from_date' => $chartFrom,
        'to_date' => $chartTo,
    ];
    $servicesScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $servicesParams, 'dash_service_scope');
    $servicesStmt = db()->prepare(
        'SELECT COALESCE(NULLIF(TRIM(s.service_name), ""), NULLIF(TRIM(jl.description), ""), "Other") AS service_name,
                COUNT(*) AS service_count
         FROM job_labor jl
         INNER JOIN job_cards jc ON jc.id = jl.job_card_id
         INNER JOIN invoices i ON i.job_card_id = jc.id
         LEFT JOIN services s ON s.id = jl.service_id
         WHERE jc.company_id = :company_id
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           AND i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           ' . $servicesScopeSql . '
           AND DATE(jc.closed_at) BETWEEN :from_date AND :to_date
         GROUP BY service_name
         ORDER BY service_count DESC
         LIMIT 8'
    );
    $servicesStmt->execute($servicesParams);
    $servicesRows = $servicesStmt->fetchAll();
    $chartPayload['top_services'] = [
        'labels' => array_map(static fn (array $row): string => (string) ($row['service_name'] ?? ''), $servicesRows),
        'counts' => array_map(static fn (array $row): int => (int) ($row['service_count'] ?? 0), $servicesRows),
    ];

    $movementParams = [
        'company_id' => $companyId,
        'from_dt' => $chartFrom . ' 00:00:00',
        'to_dt' => $chartTo . ' 23:59:59',
    ];
    $movementScopeSql = analytics_garage_scope_sql('im.garage_id', $selectedGarageId, $garageIds, $movementParams, 'dash_mv_scope');
    $movementStmt = db()->prepare(
        'SELECT DATE_FORMAT(im.created_at, "%Y-%m") AS movement_month,
                COALESCE(SUM(CASE WHEN im.movement_type = "IN" AND im.reference_type <> "TRANSFER" THEN ABS(im.quantity) ELSE 0 END), 0) AS stock_in_qty,
                COALESCE(SUM(CASE WHEN im.movement_type = "OUT" AND im.reference_type <> "TRANSFER" THEN ABS(im.quantity) ELSE 0 END), 0) AS stock_out_qty,
                COALESCE(SUM(CASE WHEN im.reference_type = "TRANSFER" THEN ABS(im.quantity) ELSE 0 END), 0) AS transfer_qty
         FROM inventory_movements im
         INNER JOIN parts p ON p.id = im.part_id
         LEFT JOIN job_cards jc_ref ON jc_ref.id = im.reference_id AND im.reference_type = "JOB_CARD"
         LEFT JOIN inventory_transfers tr ON tr.id = im.reference_id AND im.reference_type = "TRANSFER"
         WHERE im.company_id = :company_id
           AND p.company_id = :company_id
           AND p.status_code = "ACTIVE"
           ' . $movementScopeSql . '
           AND im.created_at BETWEEN :from_dt AND :to_dt
           AND (
             im.reference_type <> "JOB_CARD"
             OR (jc_ref.id IS NOT NULL AND jc_ref.company_id = :company_id AND jc_ref.status = "CLOSED" AND jc_ref.status_code = "ACTIVE")
           )
           AND (
             im.reference_type <> "TRANSFER"
             OR (tr.id IS NOT NULL AND tr.company_id = :company_id AND tr.status_code = "POSTED")
           )
         GROUP BY DATE_FORMAT(im.created_at, "%Y-%m")
         ORDER BY movement_month ASC'
    );
    $movementStmt->execute($movementParams);
    $movementRows = $movementStmt->fetchAll();
    $movementMap = [];
    foreach ($movementRows as $row) {
        $bucket = (string) ($row['movement_month'] ?? '');
        if ($bucket === '') {
            continue;
        }
        $movementMap[$bucket] = [
            'stock_in_qty' => (float) ($row['stock_in_qty'] ?? 0),
            'stock_out_qty' => (float) ($row['stock_out_qty'] ?? 0),
            'transfer_qty' => (float) ($row['transfer_qty'] ?? 0),
        ];
    }
    $movementMonths = $buildMonthSeries($chartFrom, $chartTo);
    $chartPayload['inventory_movement'] = [
        'labels' => $movementMonths,
        'stock_in' => array_map(static fn (string $label): float => (float) ($movementMap[$label]['stock_in_qty'] ?? 0), $movementMonths),
        'stock_out' => array_map(static fn (string $label): float => (float) ($movementMap[$label]['stock_out_qty'] ?? 0), $movementMonths),
        'transfers' => array_map(static fn (string $label): float => (float) ($movementMap[$label]['transfer_qty'] ?? 0), $movementMonths),
    ];

    ajax_json([
        'ok' => true,
        'from' => $chartFrom,
        'to' => $chartTo,
        'date_mode' => $dashboardDateMode,
        'trend_mode' => $trendMode,
        'can_view_financial' => $canViewFinancial,
        'charts' => $chartPayload,
    ]);
}

$revenueSummary = [
    'revenue_today' => 0,
    'revenue_mtd' => 0,
    'revenue_ytd' => 0,
    'invoice_count_today' => 0,
    'invoice_count_mtd' => 0,
    'invoice_count_ytd' => 0,
];

if ($canViewFinancial) {
    $revenueParams = [
        'company_id' => $companyId,
        'today' => $todayBounded,
        'mtd_start' => $mtdStart,
        'fy_start' => $fyStart,
    ];
    $revenueGarageScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $revenueParams, 'rev_garage');

    $revenueStmt = db()->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN i.invoice_date = :today THEN i.grand_total ELSE 0 END), 0) AS revenue_today,
            COALESCE(SUM(CASE WHEN i.invoice_date BETWEEN :mtd_start AND :today THEN i.grand_total ELSE 0 END), 0) AS revenue_mtd,
            COALESCE(SUM(CASE WHEN i.invoice_date BETWEEN :fy_start AND :today THEN i.grand_total ELSE 0 END), 0) AS revenue_ytd,
            COALESCE(SUM(CASE WHEN i.invoice_date = :today THEN 1 ELSE 0 END), 0) AS invoice_count_today,
            COALESCE(SUM(CASE WHEN i.invoice_date BETWEEN :mtd_start AND :today THEN 1 ELSE 0 END), 0) AS invoice_count_mtd,
            COALESCE(SUM(CASE WHEN i.invoice_date BETWEEN :fy_start AND :today THEN 1 ELSE 0 END), 0) AS invoice_count_ytd
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $revenueGarageScopeSql . '
           AND i.invoice_date BETWEEN :fy_start AND :today'
    );
    $revenueStmt->execute($revenueParams);
    $revenueSummary = $revenueStmt->fetch() ?: $revenueSummary;
}

$jobParams = [
    'company_id' => $companyId,
    'fy_start' => $fyStart,
    'today' => $todayBounded,
];
$jobGarageScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $jobParams, 'job_garage');
$jobKpiStmt = db()->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN jc.status = "OPEN" THEN 1 ELSE 0 END), 0) AS open_jobs,
        COALESCE(SUM(CASE WHEN jc.status IN ("IN_PROGRESS", "WAITING_PARTS", "READY_FOR_DELIVERY", "COMPLETED") THEN 1 ELSE 0 END), 0) AS in_progress_jobs,
        COALESCE(SUM(CASE
            WHEN jc.status = "CLOSED"
             AND DATE(COALESCE(jc.closed_at, jc.updated_at, jc.created_at)) BETWEEN :fy_start AND :today THEN 1
            ELSE 0
        END), 0) AS closed_jobs
     FROM job_cards jc
     WHERE jc.company_id = :company_id
       AND jc.status_code = "ACTIVE"
       ' . $jobGarageScopeSql
);
$jobKpiStmt->execute($jobParams);
$jobKpis = $jobKpiStmt->fetch() ?: [
    'open_jobs' => 0,
    'in_progress_jobs' => 0,
    'closed_jobs' => 0,
];

$inventoryJoinParams = [
    'company_id' => $companyId,
];
if ($selectedGarageId > 0) {
    $inventoryJoinScopeSql = ' AND gi.garage_id = :inv_garage_selected ';
    $inventoryJoinParams['inv_garage_selected'] = $selectedGarageId;
} else {
    if (empty($garageIds)) {
        $inventoryJoinScopeSql = ' AND 1 = 0 ';
    } else {
        $inventoryPlaceholders = [];
        foreach ($garageIds as $index => $garageId) {
            $key = 'inv_garage_' . $index;
            $inventoryJoinParams[$key] = $garageId;
            $inventoryPlaceholders[] = ':' . $key;
        }
        $inventoryJoinScopeSql = ' AND gi.garage_id IN (' . implode(', ', $inventoryPlaceholders) . ') ';
    }
}

$lowStockStmt = db()->prepare(
    'SELECT COUNT(*)
     FROM (
         SELECT p.id
         FROM parts p
         LEFT JOIN garage_inventory gi ON gi.part_id = p.id ' . $inventoryJoinScopeSql . '
         WHERE p.company_id = :company_id
           AND p.status_code = "ACTIVE"
         GROUP BY p.id, p.min_stock
         HAVING COALESCE(SUM(gi.quantity), 0) <= p.min_stock
     ) AS low_stock_parts'
);
$lowStockStmt->execute($inventoryJoinParams);
$lowStockCount = (int) $lowStockStmt->fetchColumn();

$fastParams = [
    'company_id' => $companyId,
    'fy_start' => $fyStart,
    'today' => $todayBounded,
];
$fastGarageScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $fastParams, 'fast_garage');
$fastMovingStmt = db()->prepare(
    'SELECT p.part_name, p.part_sku, p.unit,
            COALESCE(SUM(jp.quantity), 0) AS total_qty,
            COUNT(DISTINCT jc.id) AS jobs_count
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
       ' . $fastGarageScopeSql . '
       AND DATE(COALESCE(jc.closed_at, jc.updated_at, jc.created_at)) BETWEEN :fy_start AND :today
     GROUP BY p.id, p.part_name, p.part_sku, p.unit
     ORDER BY total_qty DESC
     LIMIT 5'
);
$fastMovingStmt->execute($fastParams);
$fastMovingParts = $fastMovingStmt->fetchAll();

$recentParams = [
    'company_id' => $companyId,
];
$recentGarageScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $recentParams, 'recent_garage');
$recentJobsStmt = db()->prepare(
    'SELECT jc.id, jc.job_number, jc.status, jc.closed_at, jc.updated_at,
            c.full_name AS customer_name,
            v.registration_no
     FROM job_cards jc
     INNER JOIN customers c ON c.id = jc.customer_id
     INNER JOIN vehicles v ON v.id = jc.vehicle_id
     WHERE jc.company_id = :company_id
       AND jc.status_code = "ACTIVE"
       ' . $recentGarageScopeSql . '
     ORDER BY COALESCE(jc.closed_at, jc.updated_at, jc.created_at) DESC
     LIMIT 8'
);
$recentJobsStmt->execute($recentParams);
$recentJobs = $recentJobsStmt->fetchAll();

$reminderFeatureReady = service_reminder_feature_ready();
$dashboardReminderRows = $reminderFeatureReady
    ? service_reminder_fetch_active_for_scope($companyId, $selectedGarageId, $garageIds, 12)
    : [];
$dashboardReminderSummary = $reminderFeatureReady
    ? service_reminder_summary_counts(
        service_reminder_fetch_active_for_scope($companyId, $selectedGarageId, $garageIds, 500)
    )
    : [
        'total' => 0,
        'overdue' => 0,
        'due_soon' => 0,
        'upcoming' => 0,
        'unscheduled' => 0,
    ];
$reminderReportLink = url('modules/jobs/maintenance_reminders.php');
$insuranceDashboardSummary = job_insurance_dashboard_summary($companyId, $selectedGarageId, $garageIds);
$insuranceClaimsReportLink = url('modules/reports/insurance_claims.php');

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6">
          <h3 class="mb-0">Dashboard Intelligence</h3>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Dashboard</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Dashboard Scope</h3></div>
        <div class="card-body">
          <form method="get" class="row g-2 align-items-end">
            <?php if ($allowAllGarages || count($garageIds) > 1): ?>
              <div class="col-md-4">
                <label class="form-label">Garage Scope</label>
                <select name="garage_id" class="form-select">
                  <?php if ($allowAllGarages): ?>
                    <option value="0" <?= $selectedGarageId === 0 ? 'selected' : ''; ?>>All Accessible Garages</option>
                  <?php endif; ?>
                  <?php foreach ($garageOptions as $garage): ?>
                    <option value="<?= (int) $garage['id']; ?>" <?= ((int) $garage['id'] === $selectedGarageId) ? 'selected' : ''; ?>>
                      <?= e((string) $garage['name']); ?> (<?= e((string) $garage['code']); ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <div class="col-md-4">
                <label class="form-label">Garage Scope</label>
                <input type="text" class="form-control" value="<?= e($scopeGarageLabel); ?>" readonly />
                <input type="hidden" name="garage_id" value="<?= (int) $selectedGarageId; ?>" />
              </div>
            <?php endif; ?>

            <div class="col-md-4">
              <label class="form-label">Financial Year</label>
              <select name="fy_id" class="form-select">
                <?php if (empty($financialYears)): ?>
                  <option value="0" selected><?= e((string) ($selectedFy['fy_label'] ?? 'Current FY')); ?></option>
                <?php else: ?>
                  <?php foreach ($financialYears as $fy): ?>
                    <option value="<?= (int) $fy['id']; ?>" <?= ((int) $fy['id'] === (int) ($selectedFy['id'] ?? 0)) ? 'selected' : ''; ?>>
                      <?= e((string) $fy['fy_label']); ?> (<?= e((string) $fy['start_date']); ?> to <?= e((string) $fy['end_date']); ?>)
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>

            <div class="col-md-4 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Apply</button>
              <a href="<?= e(url('dashboard.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
          <div class="mt-3">
            <span class="badge text-bg-light border me-2">Garage: <?= e($scopeGarageLabel); ?></span>
            <span class="badge text-bg-light border me-2">FY: <?= e((string) ($selectedFy['fy_label'] ?? '-')); ?></span>
            <span class="badge text-bg-light border">Data: Closed Jobs + Finalized Invoices + Valid Inventory</span>
          </div>
        </div>
      </div>

      <?php if ($canViewFinancial): ?>
        <div class="row g-3 mb-3">
          <div class="col-12 col-sm-6 col-lg-4">
            <div class="info-box">
              <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-currency-rupee"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Revenue Today (Finalized)</span>
                <span class="info-box-number erp-stat-number"><?= e(format_currency((float) ($revenueSummary['revenue_today'] ?? 0))); ?></span>
                <small class="text-muted">Invoices: <?= (int) ($revenueSummary['invoice_count_today'] ?? 0); ?></small>
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-lg-4">
            <div class="info-box">
              <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-calendar2-week"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Revenue MTD</span>
                <span class="info-box-number erp-stat-number"><?= e(format_currency((float) ($revenueSummary['revenue_mtd'] ?? 0))); ?></span>
                <small class="text-muted">Invoices: <?= (int) ($revenueSummary['invoice_count_mtd'] ?? 0); ?></small>
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-lg-4">
            <div class="info-box">
              <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-graph-up"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Revenue YTD</span>
                <span class="info-box-number erp-stat-number"><?= e(format_currency((float) ($revenueSummary['revenue_ytd'] ?? 0))); ?></span>
                <small class="text-muted">Invoices: <?= (int) ($revenueSummary['invoice_count_ytd'] ?? 0); ?></small>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="row g-3 mb-3">
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-folder2-open"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Open Jobs</span>
              <span class="info-box-number erp-stat-number"><?= number_format((int) ($jobKpis['open_jobs'] ?? 0)); ?></span>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-tools"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">In-Progress Jobs</span>
              <span class="info-box-number erp-stat-number"><?= number_format((int) ($jobKpis['in_progress_jobs'] ?? 0)); ?></span>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check2-circle"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Closed Jobs (FY)</span>
              <span class="info-box-number erp-stat-number"><?= number_format((int) ($jobKpis['closed_jobs'] ?? 0)); ?></span>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-exclamation-triangle"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Low Stock Parts</span>
              <span class="info-box-number erp-stat-number"><?= number_format($lowStockCount); ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-bell"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Active Reminders</span>
              <span class="info-box-number erp-stat-number"><?= number_format((int) ($dashboardReminderSummary['total'] ?? 0)); ?></span>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-exclamation-circle"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Overdue Reminders</span>
              <span class="info-box-number erp-stat-number"><?= number_format((int) ($dashboardReminderSummary['overdue'] ?? 0)); ?></span>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="info-box">
              <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-alarm"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Due</span>
              <span class="info-box-number erp-stat-number"><?= number_format((int) (($dashboardReminderSummary['due'] ?? 0) + ($dashboardReminderSummary['due_soon'] ?? 0))); ?></span>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="info-box">
            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-calendar-check"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Upcoming</span>
              <span class="info-box-number erp-stat-number"><?= number_format((int) ($dashboardReminderSummary['upcoming'] ?? 0)); ?></span>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <a href="<?= e($insuranceClaimsReportLink); ?>" class="text-decoration-none">
            <div class="info-box">
              <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-shield-exclamation"></i></span>
              <div class="info-box-content">
                <span class="info-box-text text-body">Pending Insurance Claims</span>
                <span class="info-box-number erp-stat-number text-body"><?= number_format((int) ($insuranceDashboardSummary['pending_count'] ?? 0)); ?></span>
              </div>
            </div>
          </a>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <a href="<?= e($insuranceClaimsReportLink); ?>" class="text-decoration-none">
            <div class="info-box">
              <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-file-earmark-lock2"></i></span>
              <div class="info-box-content">
                <span class="info-box-text text-body">Pending Claim Amount</span>
                <span class="info-box-number erp-stat-number text-body"><?= e(format_currency((float) ($insuranceDashboardSummary['pending_amount'] ?? 0))); ?></span>
              </div>
            </div>
          </a>
        </div>
      </div>

      <div class="card card-outline card-success mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Maintenance Reminder Queue</h3>
          <a href="<?= e($reminderReportLink); ?>" class="btn btn-sm btn-outline-success">Open Reminder Module</a>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Vehicle</th>
                <th>Labour/Part</th>
                <th class="text-end">Due KM</th>
                <th>Due Date</th>
                <th>Predicted Next Visit</th>
                <th>Status</th>
                <th>Recommendation</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($dashboardReminderRows)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No active reminders found in selected scope.</td></tr>
              <?php else: ?>
                <?php foreach ($dashboardReminderRows as $reminder): ?>
                  <tr>
                    <td>
                      <a href="<?= e(url('modules/vehicles/intelligence.php?id=' . (int) ($reminder['vehicle_id'] ?? 0))); ?>">
                        <?= e((string) ($reminder['registration_no'] ?? '-')); ?>
                      </a>
                    </td>
                    <td><?= e((string) ($reminder['service_label'] ?? service_reminder_type_label((string) ($reminder['service_type'] ?? '')))); ?></td>
                    <td class="text-end"><?= isset($reminder['next_due_km']) && $reminder['next_due_km'] !== null ? e(number_format((float) $reminder['next_due_km'], 0)) : '-'; ?></td>
                    <td><?= e((string) (($reminder['next_due_date'] ?? '') !== '' ? $reminder['next_due_date'] : '-')); ?></td>
                    <td><?= e((string) (($reminder['predicted_next_visit_date'] ?? '') !== '' ? $reminder['predicted_next_visit_date'] : '-')); ?></td>
                    <td><span class="badge text-bg-<?= e(service_reminder_due_badge_class((string) ($reminder['due_state'] ?? 'UNSCHEDULED'))); ?>"><?= e((string) ($reminder['due_state'] ?? 'UNSCHEDULED')); ?></span></td>
                    <td class="small"><?= e((string) ($reminder['recommendation_text'] ?? '-')); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div id="dashboard-charts-shell" class="card card-outline card-primary mb-3">
        <div class="card-header">
          <h3 class="card-title mb-0">Visualization Layer (Trusted Aggregates)</h3>
        </div>
        <div class="card-body">
          <form
            id="dashboard-chart-filter-form"
            class="row g-2 align-items-end mb-3"
            data-date-filter-form="1"
            data-date-range-start="<?= e($fyStart); ?>"
            data-date-range-end="<?= e($fyEnd); ?>"
            data-date-yearly-start="<?= e($fyStart); ?>"
          >
            <div class="col-md-2">
              <label class="form-label">Date Mode</label>
              <select name="date_mode" class="form-select">
                <?php foreach ($dashboardDateModeOptions as $modeValue => $modeLabel): ?>
                  <option value="<?= e((string) $modeValue); ?>" <?= $dashboardDateMode === $modeValue ? 'selected' : ''; ?>>
                    <?= e((string) $modeLabel); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">From</label>
              <input type="date" name="from" class="form-control" value="<?= e($dashboardChartDefaultFrom); ?>" required />
            </div>
            <div class="col-md-2">
              <label class="form-label">To</label>
              <input type="date" name="to" class="form-control" value="<?= e($dashboardChartDefaultTo); ?>" required />
            </div>
            <div class="col-md-3">
              <label class="form-label">Revenue Trend View</label>
              <select name="trend_mode" class="form-select">
                <option value="daily" <?= $dashboardTrendMode === 'daily' ? 'selected' : ''; ?>>Daily (Last 30 Days)</option>
                <option value="monthly" <?= $dashboardTrendMode === 'monthly' ? 'selected' : ''; ?>>Monthly (Last 12 Months)</option>
              </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Refresh Charts</button>
            </div>
          </form>

          <div class="row g-3">
            <div class="col-xl-8">
              <div class="card h-100">
                <div class="card-header"><h3 class="card-title mb-0">Revenue Trend (Finalized Invoices)</h3></div>
                <div class="card-body">
                  <div class="gac-chart-wrap"><canvas id="dashboard-chart-revenue-trend"></canvas></div>
                </div>
              </div>
            </div>
            <div class="col-xl-4">
              <div class="card h-100">
                <div class="card-header"><h3 class="card-title mb-0">Job Status Distribution</h3></div>
                <div class="card-body">
                  <div class="gac-chart-wrap"><canvas id="dashboard-chart-job-status"></canvas></div>
                </div>
              </div>
            </div>
            <div class="col-lg-6">
              <div class="card h-100">
                <div class="card-header"><h3 class="card-title mb-0">Revenue vs Expense (Monthly)</h3></div>
                <div class="card-body">
                  <div class="gac-chart-wrap"><canvas id="dashboard-chart-revenue-expense"></canvas></div>
                </div>
              </div>
            </div>
            <div class="col-lg-6">
              <div class="card h-100">
                <div class="card-header"><h3 class="card-title mb-0">Top Labour (Count)</h3></div>
                <div class="card-body">
                  <div class="gac-chart-wrap"><canvas id="dashboard-chart-top-services"></canvas></div>
                </div>
              </div>
            </div>
            <div class="col-xl-8">
              <div class="card h-100">
                <div class="card-header"><h3 class="card-title mb-0">Inventory Movement Summary</h3></div>
                <div class="card-body">
                  <div class="gac-chart-wrap"><canvas id="dashboard-chart-inventory-movement"></canvas></div>
                </div>
              </div>
            </div>
            <div class="col-xl-4">
              <div class="card h-100">
                <div class="card-header"><h3 class="card-title mb-0">Payment Mode Distribution</h3></div>
                <div class="card-body">
                  <div class="gac-chart-wrap"><canvas id="dashboard-chart-payment-modes"></canvas></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-6">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Fast-Moving Parts (Job Consumption, FY)</h3></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-striped mb-0">
                <thead>
                  <tr>
                    <th>Part</th>
                    <th>Jobs</th>
                    <th>Qty</th>
                    <th>Trend</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($fastMovingParts)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No closed/finalized parts usage data yet.</td></tr>
                  <?php else: ?>
                    <?php $maxQty = (float) max(array_map(static fn (array $row): float => (float) $row['total_qty'], $fastMovingParts)); ?>
                    <?php foreach ($fastMovingParts as $part): ?>
                      <?php $qty = (float) $part['total_qty']; ?>
                      <tr>
                        <td><?= e((string) $part['part_name']); ?> <small class="text-muted">(<?= e((string) $part['part_sku']); ?>)</small></td>
                        <td><?= (int) $part['jobs_count']; ?></td>
                        <td><?= e(number_format($qty, 2)); ?> <?= e((string) $part['unit']); ?></td>
                        <td style="min-width:160px;">
                          <div class="progress progress-xs">
                            <div class="progress-bar bg-info" style="width: <?= e((string) analytics_progress_width($qty, $maxQty)); ?>%"></div>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Recent Job Activity</h3></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-striped table-hover mb-0">
                <thead>
                  <tr>
                    <th>Job Number</th>
                    <th>Customer</th>
                    <th>Vehicle</th>
                    <th>Status</th>
                    <th>Updated / Closed</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($recentJobs)): ?>
                    <tr>
                      <td colspan="5" class="text-center text-muted py-4">No jobs in current scope.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($recentJobs as $job): ?>
                      <tr>
                        <td>
                          <a href="<?= e(url('modules/jobs/view.php?id=' . (int) $job['id'])); ?>">
                            <?= e((string) $job['job_number']); ?>
                          </a>
                        </td>
                        <td><?= e((string) $job['customer_name']); ?></td>
                        <td><?= e((string) $job['registration_no']); ?></td>
                        <td><span class="badge text-bg-secondary"><?= e((string) $job['status']); ?></span></td>
                        <td><?= e((string) ($job['closed_at'] ?? $job['updated_at'] ?? '-')); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (!window.GacCharts) {
      return;
    }

    var form = document.getElementById('dashboard-chart-filter-form');
    var shell = document.getElementById('dashboard-charts-shell');
    if (!form || !shell) {
      return;
    }

    var charts = window.GacCharts.createRegistry('dashboard');
    var latestPayload = null;
    var fromInput = form.querySelector('input[name="from"]');
    var toInput = form.querySelector('input[name="to"]');
    var dateModeSelector = form.querySelector('select[name="date_mode"]');
    var trendSelector = form.querySelector('select[name="trend_mode"]');

    function currencyTick(value) {
      return window.GacCharts.asCurrency(value);
    }

    function renderDashboardCharts(payload) {
      if (!payload || !payload.charts) {
        return;
      }

      var trendMode = String(payload.trend_mode || 'daily').toLowerCase() === 'monthly' ? 'monthly' : 'daily';
      if (fromInput && payload.from) {
        fromInput.value = payload.from;
      }
      if (toInput && payload.to) {
        toInput.value = payload.to;
      }
      if (dateModeSelector && payload.date_mode) {
        dateModeSelector.value = String(payload.date_mode);
      }
      if (trendSelector) {
        trendSelector.value = trendMode;
      }

      var trend = trendMode === 'monthly' ? payload.charts.revenue_monthly : payload.charts.revenue_daily;
      var trendTitle = trendMode === 'monthly' ? 'Monthly Revenue (Last 12 Months)' : 'Daily Revenue (Last 30 Days)';
      var trendColor = trendMode === 'monthly' ? window.GacCharts.palette.indigo : window.GacCharts.palette.blue;

      charts.render('#dashboard-chart-revenue-trend', {
        type: 'line',
        data: {
          labels: trend && trend.labels ? trend.labels : [],
          datasets: [{
            label: trendTitle,
            data: trend && trend.values ? trend.values : [],
            borderColor: trendColor,
            backgroundColor: trendColor + '33',
            fill: true,
            tension: 0.3
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom' } },
          scales: {
            y: {
              ticks: { callback: currencyTick }
            }
          }
        }
      }, {
        emptyMessage: payload.can_view_financial ? 'No finalized revenue rows in selected scope.' : 'Financial charts require reports.financial permission.'
      });

      charts.render('#dashboard-chart-job-status', {
        type: 'doughnut',
        data: {
          labels: payload.charts.job_status ? payload.charts.job_status.labels : [],
          datasets: [{
            data: payload.charts.job_status ? payload.charts.job_status.values : [],
            backgroundColor: window.GacCharts.pickColors(5)
          }]
        },
        options: window.GacCharts.commonOptions()
      });

      charts.render('#dashboard-chart-revenue-expense', {
        type: 'bar',
        data: {
          labels: payload.charts.revenue_vs_expense ? payload.charts.revenue_vs_expense.labels : [],
          datasets: [{
            label: 'Finalized Revenue',
            data: payload.charts.revenue_vs_expense ? payload.charts.revenue_vs_expense.revenue : [],
            backgroundColor: window.GacCharts.palette.green
          }, {
            label: 'Recorded Expense',
            data: payload.charts.revenue_vs_expense ? payload.charts.revenue_vs_expense.expense : [],
            backgroundColor: window.GacCharts.palette.red
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom' } },
          scales: {
            y: {
              ticks: { callback: currencyTick }
            }
          }
        }
      }, {
        emptyMessage: payload.can_view_financial ? 'No revenue/expense rows in selected scope.' : 'Financial charts require reports.financial permission.'
      });

      charts.render('#dashboard-chart-top-services', {
        type: 'bar',
        data: {
          labels: payload.charts.top_services ? payload.charts.top_services.labels : [],
          datasets: [{
            label: 'Labour Count',
            data: payload.charts.top_services ? payload.charts.top_services.counts : [],
            backgroundColor: window.GacCharts.pickColors(8)
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            x: { ticks: { maxRotation: 0, minRotation: 0 } },
            y: { beginAtZero: true }
          }
        }
      }, {
        emptyMessage: 'No finalized service rows in selected range.'
      });

      charts.render('#dashboard-chart-inventory-movement', {
        type: 'bar',
        data: {
          labels: payload.charts.inventory_movement ? payload.charts.inventory_movement.labels : [],
          datasets: [{
            label: 'Stock IN',
            data: payload.charts.inventory_movement ? payload.charts.inventory_movement.stock_in : [],
            backgroundColor: window.GacCharts.palette.green
          }, {
            label: 'Stock OUT',
            data: payload.charts.inventory_movement ? payload.charts.inventory_movement.stock_out : [],
            backgroundColor: window.GacCharts.palette.orange
          }, {
            label: 'Transfers',
            data: payload.charts.inventory_movement ? payload.charts.inventory_movement.transfers : [],
            backgroundColor: window.GacCharts.palette.cyan
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom' } },
          scales: {
            x: { stacked: true },
            y: { stacked: true, beginAtZero: true }
          }
        }
      }, {
        emptyMessage: 'No valid inventory movements in selected range.'
      });

      charts.render('#dashboard-chart-payment-modes', {
        type: 'pie',
        data: {
          labels: payload.charts.payment_modes ? payload.charts.payment_modes.labels : [],
          datasets: [{
            data: payload.charts.payment_modes ? payload.charts.payment_modes.values : [],
            backgroundColor: [
              window.GacCharts.palette.green,
              window.GacCharts.palette.blue,
              window.GacCharts.palette.orange,
              window.GacCharts.palette.slate
            ]
          }]
        },
        options: window.GacCharts.commonOptions()
      }, {
        emptyMessage: payload.can_view_financial ? 'No payment rows in selected range.' : 'Financial charts require reports.financial permission.'
      });
    }

    function loadCharts() {
      var staleError = shell.querySelector('[data-dashboard-chart-error="1"]');
      if (staleError) {
        staleError.remove();
      }

      var params = new URLSearchParams(new FormData(form));
      params.set('ajax', 'charts');
      shell.classList.add('gac-report-loading');

      fetch('dashboard.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (response) {
          return response.json();
        })
        .then(function (payload) {
          latestPayload = payload;
          renderDashboardCharts(payload);
        })
        .catch(function () {
          shell.insertAdjacentHTML('beforeend', '<div class="alert alert-danger mt-3" data-dashboard-chart-error="1">Unable to load dashboard charts. Please retry.</div>');
        })
        .finally(function () {
          shell.classList.remove('gac-report-loading');
        });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      loadCharts();
    });

    if (trendSelector) {
      trendSelector.addEventListener('change', function () {
        if (latestPayload) {
          latestPayload.trend_mode = trendSelector.value;
          renderDashboardCharts(latestPayload);
          return;
        }
        loadCharts();
      });
    }

    if (dateModeSelector) {
      dateModeSelector.addEventListener('change', function () {
        window.setTimeout(function () {
          if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
            return;
          }
          loadCharts();
        }, 0);
      });
    }

    loadCharts();
  });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

