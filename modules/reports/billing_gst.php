<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/shared.php';

reports_require_access();

$page_title = 'Sales Reports';
$active_menu = 'reports.sales';

$scope = reports_build_scope_context();
$companyId = (int) $scope['company_id'];
$garageIds = $scope['garage_ids'];
$garageOptions = $scope['garage_options'];
$allowAllGarages = (bool) $scope['allow_all_garages'];
$selectedGarageId = (int) $scope['selected_garage_id'];
$scopeGarageLabel = (string) $scope['scope_garage_label'];
$financialYears = $scope['financial_years'];
$selectedFyId = (int) $scope['selected_fy_id'];
$fyLabel = (string) $scope['fy_label'];
$dateMode = (string) $scope['date_mode'];
$dateModeOptions = $scope['date_mode_options'];
$fromDate = (string) $scope['from_date'];
$toDate = (string) $scope['to_date'];
$canViewFinancial = (bool) $scope['can_view_financial'];
$canExportData = (bool) $scope['can_export_data'];
$baseParams = $scope['base_params'];
$advanceAdjustmentsReady = table_columns('advance_adjustments') !== [];
$salesAdvanceJoinSql = $advanceAdjustmentsReady
    ? 'LEFT JOIN (
            SELECT invoice_id, COALESCE(SUM(adjusted_amount), 0) AS total_adjusted
            FROM advance_adjustments
            GROUP BY invoice_id
         ) adv ON adv.invoice_id = i.id'
    : '';
$salesSettledAmountExpr = 'COALESCE(paid.total_paid, 0)' . ($advanceAdjustmentsReady ? ' + COALESCE(adv.total_adjusted, 0)' : '');
$salesCollectedAmountExpr = 'LEAST((' . $salesSettledAmountExpr . '), i.grand_total)';
$salesOutstandingAmountExpr = 'GREATEST(i.grand_total - (' . $salesSettledAmountExpr . '), 0)';

$revenueDaily = [];
$revenueMonthly = [];
$revenueGarageWise = [];
$collectionMonthly = [];
$topCustomers = [];
$invoiceStatusSummary = [];
$paymentModeSummary = [];
$outstandingReceivables = [];
$receivableAging = ['labels' => ['Current (0-30)', '31-60', '61-90', '90+'], 'values' => [0.0, 0.0, 0.0, 0.0]];
$salesSummary = [
    'invoice_count' => 0,
    'revenue_total' => 0.0,
    'collected_total' => 0.0,
    'outstanding_total' => 0.0,
    'outstanding_invoices' => 0,
    'avg_invoice_value' => 0.0,
    'collection_rate' => 0.0,
];
$customerOpeningBalanceNet = 0.0;

function sales_report_discount_meta_from_snapshot(array $snapshot): array
{
    $billing = is_array($snapshot['billing'] ?? null) ? $snapshot['billing'] : [];
    $type = strtoupper(trim((string) ($billing['discount_type'] ?? 'AMOUNT')));
    if (!in_array($type, ['AMOUNT', 'PERCENT'], true)) {
        $type = 'AMOUNT';
    }

    return [
        'type' => $type,
        'value' => round(max(0.0, (float) ($billing['discount_value'] ?? 0)), 2),
        'amount' => round(max(0.0, (float) ($billing['discount_amount'] ?? 0)), 2),
    ];
}

function sales_invoice_status_badge_class(string $status): string
{
    return match (strtoupper(trim($status))) {
        'DRAFT' => 'secondary',
        'FINALIZED' => 'success',
        'CANCELLED' => 'danger',
        default => 'light',
    };
}

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

    $salesSummaryParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $salesSummaryScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $salesSummaryParams, 'sales_summary_scope');
    $salesSummaryStmt = db()->prepare(
        'SELECT COUNT(*) AS invoice_count,
                COALESCE(SUM(i.grand_total), 0) AS revenue_total,
                COALESCE(SUM(' . $salesCollectedAmountExpr . '), 0) AS collected_total,
                COALESCE(SUM(' . $salesOutstandingAmountExpr . '), 0) AS outstanding_total,
                COALESCE(SUM(CASE WHEN ' . $salesOutstandingAmountExpr . ' > 0.01 THEN 1 ELSE 0 END), 0) AS outstanding_invoices
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         LEFT JOIN (
            SELECT invoice_id, SUM(amount) AS total_paid
            FROM payments
            GROUP BY invoice_id
         ) paid ON paid.invoice_id = i.id
         ' . $salesAdvanceJoinSql . '
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $salesSummaryScopeSql . '
           AND i.invoice_date BETWEEN :from_date AND :to_date'
    );
    $salesSummaryStmt->execute($salesSummaryParams);
    $salesSummaryRow = $salesSummaryStmt->fetch() ?: [];
    $salesSummary['invoice_count'] = (int) ($salesSummaryRow['invoice_count'] ?? 0);
    $salesSummary['revenue_total'] = (float) ($salesSummaryRow['revenue_total'] ?? 0);
    $salesSummary['collected_total'] = (float) ($salesSummaryRow['collected_total'] ?? 0);
    $salesSummary['outstanding_total'] = (float) ($salesSummaryRow['outstanding_total'] ?? 0);
    $salesSummary['outstanding_invoices'] = (int) ($salesSummaryRow['outstanding_invoices'] ?? 0);
    if ($salesSummary['invoice_count'] > 0) {
        $salesSummary['avg_invoice_value'] = round($salesSummary['revenue_total'] / (float) $salesSummary['invoice_count'], 2);
    }
    if ($salesSummary['revenue_total'] > 0.009) {
        $salesSummary['collection_rate'] = round(($salesSummary['collected_total'] / $salesSummary['revenue_total']) * 100, 2);
    }

    if (table_columns('ledger_entries') !== [] && table_columns('ledger_journals') !== [] && table_columns('chart_of_accounts') !== []) {
        $openingParams = ['company_id' => $companyId, 'to_date' => $toDate];
        $openingScopeSql = analytics_garage_scope_sql('le.garage_id', $selectedGarageId, $garageIds, $openingParams, 'sales_opening_scope');
        $openingStmt = db()->prepare(
            'SELECT COALESCE(SUM(le.debit_amount), 0) AS debit_total,
                    COALESCE(SUM(le.credit_amount), 0) AS credit_total
             FROM ledger_entries le
             INNER JOIN ledger_journals lj ON lj.id = le.journal_id
             INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
             WHERE lj.company_id = :company_id
               AND le.party_type = "CUSTOMER"
               AND coa.code IN ("1200", "2300")
               AND lj.reference_type IN ("CUSTOMER_OPENING_BALANCE", "CUSTOMER_OPENING_BALANCE_REV", "CUSTOMER_BALANCE_SETTLEMENT")
               AND lj.journal_date <= :to_date
               ' . $openingScopeSql
        );
        $openingStmt->execute($openingParams);
        $openingRow = $openingStmt->fetch() ?: ['debit_total' => 0, 'credit_total' => 0];
        $customerOpeningBalanceNet = ledger_round((float) ($openingRow['debit_total'] ?? 0) - (float) ($openingRow['credit_total'] ?? 0));
    }

    $collectionMonthlyParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $collectionMonthlyScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $collectionMonthlyParams, 'sales_collection_scope');
    $collectionMonthlyStmt = db()->prepare(
        'SELECT DATE_FORMAT(p.paid_on, "%Y-%m") AS collection_month,
                COUNT(p.id) AS payment_count,
                COALESCE(SUM(p.amount), 0) AS collected_amount
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           AND ' . reversal_sales_payment_unreversed_filter_sql('p') . '
           ' . $collectionMonthlyScopeSql . '
           AND p.paid_on BETWEEN :from_date AND :to_date
         GROUP BY DATE_FORMAT(p.paid_on, "%Y-%m")
         ORDER BY collection_month ASC'
    );
    $collectionMonthlyStmt->execute($collectionMonthlyParams);
    $collectionMonthly = $collectionMonthlyStmt->fetchAll();

    $paymentModeParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $paymentModeScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $paymentModeParams, 'sales_payment_scope');
    $paymentModeStmt = db()->prepare(
        'SELECT p.payment_mode, COUNT(p.id) AS payment_count, COALESCE(SUM(p.amount), 0) AS collected_amount
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           AND ' . reversal_sales_payment_unreversed_filter_sql('p') . '
           ' . $paymentModeScopeSql . '
           AND p.paid_on BETWEEN :from_date AND :to_date
         GROUP BY p.payment_mode
         ORDER BY collected_amount DESC'
    );
    $paymentModeStmt->execute($paymentModeParams);
    $paymentModeSummary = $paymentModeStmt->fetchAll();

    $invoiceStatusParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $invoiceStatusScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $invoiceStatusParams, 'sales_inv_status_scope');
    $invoiceStatusStmt = db()->prepare(
        'SELECT i.invoice_status,
                COUNT(*) AS invoice_count,
                COALESCE(SUM(i.grand_total), 0) AS total_amount
         FROM invoices i
         WHERE i.company_id = :company_id
           ' . $invoiceStatusScopeSql . '
           AND i.invoice_date BETWEEN :from_date AND :to_date
         GROUP BY i.invoice_status
         ORDER BY FIELD(i.invoice_status, "DRAFT", "FINALIZED", "CANCELLED"), i.invoice_status'
    );
    $invoiceStatusStmt->execute($invoiceStatusParams);
    $invoiceStatusSummary = $invoiceStatusStmt->fetchAll();

    $topCustomerParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $topCustomerScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $topCustomerParams, 'sales_top_customer_scope');
    $topCustomerStmt = db()->prepare(
        'SELECT c.id AS customer_id,
                c.full_name AS customer_name,
                c.phone AS customer_phone,
                COUNT(*) AS invoice_count,
                COALESCE(SUM(i.grand_total), 0) AS billed_total,
                COALESCE(SUM(' . $salesCollectedAmountExpr . '), 0) AS collected_total,
                COALESCE(SUM(' . $salesOutstandingAmountExpr . '), 0) AS outstanding_total
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         INNER JOIN customers c ON c.id = i.customer_id
         LEFT JOIN (
            SELECT invoice_id, SUM(amount) AS total_paid
            FROM payments
            GROUP BY invoice_id
         ) paid ON paid.invoice_id = i.id
         ' . $salesAdvanceJoinSql . '
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $topCustomerScopeSql . '
           AND i.invoice_date BETWEEN :from_date AND :to_date
         GROUP BY c.id, c.full_name, c.phone
         ORDER BY billed_total DESC
         LIMIT 25'
    );
    $topCustomerStmt->execute($topCustomerParams);
    $topCustomers = $topCustomerStmt->fetchAll();
    foreach ($topCustomers as &$row) {
        $billed = (float) ($row['billed_total'] ?? 0);
        $collected = (float) ($row['collected_total'] ?? 0);
        $row['collection_rate'] = $billed > 0.009 ? round(($collected / $billed) * 100, 2) : 0.0;
    }
    unset($row);

    $receivableAgingParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $receivableAgingScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $receivableAgingParams, 'sales_recv_aging_scope');
    $receivableAgingStmt = db()->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN recv.overdue_days <= 30 THEN recv.outstanding_amount ELSE 0 END), 0) AS bucket_0_30,
            COALESCE(SUM(CASE WHEN recv.overdue_days BETWEEN 31 AND 60 THEN recv.outstanding_amount ELSE 0 END), 0) AS bucket_31_60,
            COALESCE(SUM(CASE WHEN recv.overdue_days BETWEEN 61 AND 90 THEN recv.outstanding_amount ELSE 0 END), 0) AS bucket_61_90,
            COALESCE(SUM(CASE WHEN recv.overdue_days > 90 THEN recv.outstanding_amount ELSE 0 END), 0) AS bucket_90_plus
         FROM (
            SELECT
                CASE WHEN i.due_date IS NOT NULL AND i.due_date < CURDATE() THEN DATEDIFF(CURDATE(), i.due_date) ELSE 0 END AS overdue_days,
                ' . $salesOutstandingAmountExpr . ' AS outstanding_amount
            FROM invoices i
            INNER JOIN job_cards jc ON jc.id = i.job_card_id
            LEFT JOIN (
                SELECT invoice_id, SUM(amount) AS total_paid
                FROM payments
                GROUP BY invoice_id
            ) paid ON paid.invoice_id = i.id
            ' . $salesAdvanceJoinSql . '
            WHERE i.company_id = :company_id
              AND i.invoice_status = "FINALIZED"
              AND jc.status = "CLOSED"
              AND jc.status_code = "ACTIVE"
              ' . $receivableAgingScopeSql . '
              AND i.invoice_date BETWEEN :from_date AND :to_date
              AND ' . $salesOutstandingAmountExpr . ' > 0.01
         ) recv'
    );
    $receivableAgingStmt->execute($receivableAgingParams);
    $agingRow = $receivableAgingStmt->fetch() ?: [];
    $receivableAging['values'] = [
        (float) ($agingRow['bucket_0_30'] ?? 0),
        (float) ($agingRow['bucket_31_60'] ?? 0),
        (float) ($agingRow['bucket_61_90'] ?? 0),
        (float) ($agingRow['bucket_90_plus'] ?? 0),
    ];

    $receivableParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    $receivableScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $receivableParams, 'sales_recv_scope');
    $receivableStmt = db()->prepare(
        'SELECT i.invoice_number, i.invoice_date, i.due_date, c.full_name AS customer_name,
                i.snapshot_json,
                ' . $salesOutstandingAmountExpr . ' AS outstanding_amount,
                CASE WHEN i.due_date IS NOT NULL AND i.due_date < CURDATE() THEN DATEDIFF(CURDATE(), i.due_date) ELSE 0 END AS overdue_days
         FROM invoices i
         INNER JOIN job_cards jc ON jc.id = i.job_card_id
         INNER JOIN customers c ON c.id = i.customer_id
         LEFT JOIN (
            SELECT invoice_id, SUM(amount) AS total_paid
            FROM payments
            GROUP BY invoice_id
         ) paid ON paid.invoice_id = i.id
         ' . $salesAdvanceJoinSql . '
         WHERE i.company_id = :company_id
           AND i.invoice_status = "FINALIZED"
           AND jc.status = "CLOSED"
           AND jc.status_code = "ACTIVE"
           ' . $receivableScopeSql . '
           AND i.invoice_date BETWEEN :from_date AND :to_date
           AND ' . $salesOutstandingAmountExpr . ' > 0.01
         ORDER BY overdue_days DESC, outstanding_amount DESC
         LIMIT 150'
    );
    $receivableStmt->execute($receivableParams);
    $outstandingReceivables = $receivableStmt->fetchAll();
    foreach ($outstandingReceivables as &$row) {
        $snapshot = json_decode((string) ($row['snapshot_json'] ?? ''), true);
        if (!is_array($snapshot)) {
            $snapshot = [];
        }
        $discountMeta = sales_report_discount_meta_from_snapshot($snapshot);
        $discountAmount = (float) ($discountMeta['amount'] ?? 0);
        $discountType = (string) ($discountMeta['type'] ?? 'AMOUNT');
        $discountValue = (float) ($discountMeta['value'] ?? 0);
        $row['discount_amount'] = $discountAmount;
        if ($discountAmount > 0.009 && $discountType === 'PERCENT' && $discountValue > 0.009) {
            $row['discount_label'] = rtrim(rtrim(number_format($discountValue, 2), '0'), '.') . '%';
        } elseif ($discountAmount > 0.009) {
            $row['discount_label'] = 'Flat';
        } else {
            $row['discount_label'] = '-';
        }
    }
    unset($row);
}

$totalPaymentEntries = array_reduce(
    $paymentModeSummary,
    static fn (int $sum, array $row): int => $sum + (int) ($row['payment_count'] ?? 0),
    0
);

$chartPayload = [
    'monthly_sales' => [
        'labels' => array_map(static fn (array $row): string => (string) ($row['revenue_month'] ?? ''), $revenueMonthly),
        'values' => array_map(static fn (array $row): float => (float) ($row['revenue_total'] ?? 0), $revenueMonthly),
    ],
    'monthly_collections' => [
        'labels' => array_map(static fn (array $row): string => (string) ($row['collection_month'] ?? ''), $collectionMonthly),
        'values' => array_map(static fn (array $row): float => (float) ($row['collected_amount'] ?? 0), $collectionMonthly),
    ],
    'receivable_aging' => $receivableAging,
];
$chartPayloadJson = json_encode(
    $chartPayload,
    JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
);

$exportKey = trim((string) ($_GET['export'] ?? ''));
if ($exportKey !== '') {
    if (!$canExportData) {
        http_response_code(403);
        exit('Export access denied.');
    }
    if (!$canViewFinancial) {
        http_response_code(403);
        exit('Financial report access denied.');
    }

    $timestamp = date('Ymd_His');
    switch ($exportKey) {
        case 'revenue_daily':
        case 'daily_sales':
            $rows = array_map(static fn (array $row): array => [(string) ($row['invoice_date'] ?? ''), (int) ($row['invoice_count'] ?? 0), (float) ($row['revenue_total'] ?? 0)], $revenueDaily);
            reports_csv_download('sales_daily_' . $timestamp . '.csv', ['Date', 'Invoices', 'Sales'], $rows);

        case 'revenue_monthly':
        case 'monthly_sales':
            $rows = array_map(static fn (array $row): array => [(string) ($row['revenue_month'] ?? ''), (int) ($row['invoice_count'] ?? 0), (float) ($row['revenue_total'] ?? 0)], $revenueMonthly);
            reports_csv_download('sales_monthly_' . $timestamp . '.csv', ['Month', 'Invoices', 'Sales'], $rows);

        case 'monthly_collections':
            $rows = array_map(static fn (array $row): array => [(string) ($row['collection_month'] ?? ''), (int) ($row['payment_count'] ?? 0), (float) ($row['collected_amount'] ?? 0)], $collectionMonthly);
            reports_csv_download('sales_monthly_collections_' . $timestamp . '.csv', ['Month', 'Entries', 'Collected'], $rows);

        case 'revenue_garage':
        case 'garage_sales':
            $rows = array_map(static fn (array $row): array => [(string) ($row['garage_name'] ?? ''), (int) ($row['invoice_count'] ?? 0), (float) ($row['revenue_total'] ?? 0)], $revenueGarageWise);
            reports_csv_download('sales_garage_wise_' . $timestamp . '.csv', ['Garage', 'Invoices', 'Sales'], $rows);

        case 'payment_modes':
            $rows = array_map(static fn (array $row): array => [(string) ($row['payment_mode'] ?? ''), (int) ($row['payment_count'] ?? 0), (float) ($row['collected_amount'] ?? 0)], $paymentModeSummary);
            reports_csv_download('sales_payment_modes_' . $timestamp . '.csv', ['Payment Mode', 'Entries', 'Collected'], $rows);

        case 'invoice_status':
            $rows = array_map(static fn (array $row): array => [(string) ($row['invoice_status'] ?? ''), (int) ($row['invoice_count'] ?? 0), (float) ($row['total_amount'] ?? 0)], $invoiceStatusSummary);
            reports_csv_download('sales_invoice_status_' . $timestamp . '.csv', ['Status', 'Invoices', 'Amount'], $rows);

        case 'top_customers':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['customer_name'] ?? ''),
                    (string) ($row['customer_phone'] ?? ''),
                    (int) ($row['invoice_count'] ?? 0),
                    (float) ($row['billed_total'] ?? 0),
                    (float) ($row['collected_total'] ?? 0),
                    (float) ($row['outstanding_total'] ?? 0),
                    (float) ($row['collection_rate'] ?? 0),
                ],
                $topCustomers
            );
            reports_csv_download('sales_top_customers_' . $timestamp . '.csv', ['Customer', 'Phone', 'Invoices', 'Billed', 'Collected', 'Outstanding', 'Collection %'], $rows);

        case 'receivables':
            $rows = array_map(
                static fn (array $row): array => [
                    (string) ($row['invoice_number'] ?? ''),
                    (string) ($row['invoice_date'] ?? ''),
                    (string) ($row['due_date'] ?? ''),
                    (string) ($row['customer_name'] ?? ''),
                    (float) ($row['discount_amount'] ?? 0),
                    (float) ($row['outstanding_amount'] ?? 0),
                    (int) ($row['overdue_days'] ?? 0),
                ],
                $outstandingReceivables
            );
            reports_csv_download('sales_receivables_' . $timestamp . '.csv', ['Invoice', 'Invoice Date', 'Due Date', 'Customer', 'Discount', 'Outstanding', 'Overdue Days'], $rows);

        default:
            flash_set('report_error', 'Unknown export requested.', 'warning');
            redirect('modules/reports/billing_gst.php?' . http_build_query(reports_compact_query_params($baseParams)));
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Sales Reports</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/reports/index.php')); ?>">Reports</a></li>
            <li class="breadcrumb-item active">Sales</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php reports_render_page_navigation($active_menu, $baseParams); ?>

      <div class="card card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Filters</h3></div>
        <div class="card-body">
          <form
            method="get"
            id="sales-report-filter-form"
            class="row g-2 align-items-end"
            data-date-filter-form="1"
            data-date-range-start="<?= e((string) $scope['date_range_start']); ?>"
            data-date-range-end="<?= e((string) $scope['date_range_end']); ?>"
            data-date-yearly-start="<?= e((string) $scope['fy_start']); ?>"
          >
            <?php if ($allowAllGarages || count($garageIds) > 1): ?>
              <div class="col-md-3">
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
            <div class="col-md-2">
              <label class="form-label">Date Mode</label>
              <select name="date_mode" class="form-select">
                <?php foreach ($dateModeOptions as $modeValue => $modeLabel): ?>
                  <option value="<?= e((string) $modeValue); ?>" <?= $dateMode === $modeValue ? 'selected' : ''; ?>>
                    <?= e((string) $modeLabel); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" required /></div>
            <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" required /></div>
            <div class="col-md-2 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Apply</button>
              <a href="<?= e(url('modules/reports/billing_gst.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
          <div class="mt-3">
            <span class="badge text-bg-light border me-2">Garage: <?= e($scopeGarageLabel); ?></span>
            <span class="badge text-bg-light border me-2">FY: <?= e($fyLabel); ?></span>
            <span class="badge text-bg-light border me-2">Range: <?= e($fromDate); ?> to <?= e($toDate); ?></span>
            <span class="badge text-bg-success">Trusted Data: Finalized Invoices with Closed Jobs</span>
          </div>
        </div>
      </div>

      <div id="sales-report-content">
        <script type="application/json" data-chart-payload><?= $chartPayloadJson ?: '{}'; ?></script>

      <?php if (!$canViewFinancial): ?>
        <div class="alert alert-warning">
          Financial reports require `reports.financial` permission.
        </div>
      <?php else: ?>
        <div class="row g-3 mb-3">
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-primary"><i class="bi bi-receipt"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Finalized Invoices</span>
                <span class="info-box-number"><?= number_format((int) ($salesSummary['invoice_count'] ?? 0)); ?></span>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-success"><i class="bi bi-currency-rupee"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Finalized Sales</span>
                <span class="info-box-number"><?= e(format_currency((float) ($salesSummary['revenue_total'] ?? 0))); ?></span>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-info"><i class="bi bi-wallet2"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Collections</span>
                <span class="info-box-number"><?= e(format_currency((float) ($salesSummary['collected_total'] ?? 0))); ?></span>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-danger"><i class="bi bi-exclamation-diamond"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Outstanding</span>
                <span class="info-box-number"><?= e(format_currency((float) ($salesSummary['outstanding_total'] ?? 0))); ?></span>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-warning"><i class="bi bi-123"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Average Invoice</span>
                <span class="info-box-number"><?= e(format_currency((float) ($salesSummary['avg_invoice_value'] ?? 0))); ?></span>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-secondary"><i class="bi bi-percent"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Collection Rate</span>
                <span class="info-box-number"><?= e(number_format((float) ($salesSummary['collection_rate'] ?? 0), 2)); ?>%</span>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-danger"><i class="bi bi-file-earmark-minus"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Outstanding Invoices</span>
                <span class="info-box-number"><?= number_format((int) ($salesSummary['outstanding_invoices'] ?? 0)); ?></span>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-primary"><i class="bi bi-cash-stack"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Payment Entries</span>
                <span class="info-box-number"><?= number_format($totalPaymentEntries); ?></span>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-<?= $customerOpeningBalanceNet > 0.009 ? 'danger' : ($customerOpeningBalanceNet < -0.009 ? 'success' : 'secondary'); ?>"><i class="bi bi-journal-text"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Opening Net (Customer)</span>
                <span class="info-box-number"><?= e(format_currency($customerOpeningBalanceNet)); ?></span>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-lg-6">
            <div class="card h-100">
              <div class="card-header"><h3 class="card-title mb-0">Monthly Sales Trend</h3></div>
              <div class="card-body">
                <div class="gac-chart-wrap"><canvas id="sales-chart-sales-trend"></canvas></div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card h-100">
              <div class="card-header"><h3 class="card-title mb-0">Monthly Collection Trend</h3></div>
              <div class="card-body">
                <div class="gac-chart-wrap"><canvas id="sales-chart-collection-trend"></canvas></div>
              </div>
            </div>
          </div>
          <div class="col-lg-12">
            <div class="card h-100">
              <div class="card-header"><h3 class="card-title mb-0">Receivable Aging Distribution</h3></div>
              <div class="card-body">
                <div class="gac-chart-wrap"><canvas id="sales-chart-receivable-aging"></canvas></div>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-lg-3">
            <div class="card h-100">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Sales Daily</h3>
                <?php if ($canExportData): ?>
                  <a href="<?= e(reports_export_url('modules/reports/billing_gst.php', $baseParams, 'daily_sales')); ?>" class="btn btn-sm btn-outline-success">CSV</a>
                <?php endif; ?>
              </div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Date</th><th>Invoices</th><th>Sales</th></tr></thead>
                  <tbody>
                    <?php if (empty($revenueDaily)): ?>
                      <tr><td colspan="3" class="text-center text-muted py-4">No rows.</td></tr>
                    <?php else: foreach ($revenueDaily as $row): ?>
                      <tr><td><?= e((string) ($row['invoice_date'] ?? '')); ?></td><td><?= (int) ($row['invoice_count'] ?? 0); ?></td><td><?= e(format_currency((float) ($row['revenue_total'] ?? 0))); ?></td></tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-lg-3">
            <div class="card h-100">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Sales Monthly</h3>
                <?php if ($canExportData): ?>
                  <a href="<?= e(reports_export_url('modules/reports/billing_gst.php', $baseParams, 'monthly_sales')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
                <?php endif; ?>
              </div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Month</th><th>Invoices</th><th>Sales</th></tr></thead>
                  <tbody>
                    <?php if (empty($revenueMonthly)): ?>
                      <tr><td colspan="3" class="text-center text-muted py-4">No rows.</td></tr>
                    <?php else: foreach ($revenueMonthly as $row): ?>
                      <tr><td><?= e((string) ($row['revenue_month'] ?? '')); ?></td><td><?= (int) ($row['invoice_count'] ?? 0); ?></td><td><?= e(format_currency((float) ($row['revenue_total'] ?? 0))); ?></td></tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-lg-3">
            <div class="card h-100">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Collections Monthly</h3>
                <?php if ($canExportData): ?>
                  <a href="<?= e(reports_export_url('modules/reports/billing_gst.php', $baseParams, 'monthly_collections')); ?>" class="btn btn-sm btn-outline-info">CSV</a>
                <?php endif; ?>
              </div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Month</th><th>Entries</th><th>Collected</th></tr></thead>
                  <tbody>
                    <?php if (empty($collectionMonthly)): ?>
                      <tr><td colspan="3" class="text-center text-muted py-4">No rows.</td></tr>
                    <?php else: foreach ($collectionMonthly as $row): ?>
                      <tr><td><?= e((string) ($row['collection_month'] ?? '')); ?></td><td><?= (int) ($row['payment_count'] ?? 0); ?></td><td><?= e(format_currency((float) ($row['collected_amount'] ?? 0))); ?></td></tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-lg-3">
            <div class="card h-100">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Sales Garage-wise</h3>
                <?php if ($canExportData): ?>
                  <a href="<?= e(reports_export_url('modules/reports/billing_gst.php', $baseParams, 'garage_sales')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a>
                <?php endif; ?>
              </div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Garage</th><th>Invoices</th><th>Sales</th></tr></thead>
                  <tbody>
                    <?php if (empty($revenueGarageWise)): ?>
                      <tr><td colspan="3" class="text-center text-muted py-4">No rows.</td></tr>
                    <?php else: foreach ($revenueGarageWise as $row): ?>
                      <tr><td><?= e((string) ($row['garage_name'] ?? '')); ?></td><td><?= (int) ($row['invoice_count'] ?? 0); ?></td><td><?= e(format_currency((float) ($row['revenue_total'] ?? 0))); ?></td></tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-lg-4">
            <div class="card h-100">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Invoice Status Snapshot</h3>
                <?php if ($canExportData): ?>
                  <a href="<?= e(reports_export_url('modules/reports/billing_gst.php', $baseParams, 'invoice_status')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
                <?php endif; ?>
              </div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Status</th><th>Invoices</th><th>Amount</th></tr></thead>
                  <tbody>
                    <?php if (empty($invoiceStatusSummary)): ?>
                      <tr><td colspan="3" class="text-center text-muted py-4">No rows.</td></tr>
                    <?php else: foreach ($invoiceStatusSummary as $row): ?>
                      <?php $status = strtoupper(trim((string) ($row['invoice_status'] ?? 'UNKNOWN'))); ?>
                      <tr>
                        <td><span class="badge text-bg-<?= e(sales_invoice_status_badge_class($status)); ?>"><?= e($status); ?></span></td>
                        <td><?= (int) ($row['invoice_count'] ?? 0); ?></td>
                        <td><?= e(format_currency((float) ($row['total_amount'] ?? 0))); ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-lg-8">
            <div class="card h-100">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Payment Mode Summary</h3>
                <?php if ($canExportData): ?>
                  <a href="<?= e(reports_export_url('modules/reports/billing_gst.php', $baseParams, 'payment_modes')); ?>" class="btn btn-sm btn-outline-secondary">CSV</a>
                <?php endif; ?>
              </div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Mode</th><th>Entries</th><th>Collected</th></tr></thead>
                  <tbody>
                    <?php if (empty($paymentModeSummary)): ?>
                      <tr><td colspan="3" class="text-center text-muted py-4">No payment data.</td></tr>
                    <?php else: foreach ($paymentModeSummary as $row): ?>
                      <tr><td><?= e((string) ($row['payment_mode'] ?? '')); ?></td><td><?= (int) ($row['payment_count'] ?? 0); ?></td><td><?= e(format_currency((float) ($row['collected_amount'] ?? 0))); ?></td></tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Top Customers by Sales</h3>
            <?php if ($canExportData): ?>
              <a href="<?= e(reports_export_url('modules/reports/billing_gst.php', $baseParams, 'top_customers')); ?>" class="btn btn-sm btn-outline-success">CSV</a>
            <?php endif; ?>
          </div>
          <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead><tr><th>Customer</th><th>Phone</th><th>Invoices</th><th>Billed</th><th>Collected</th><th>Outstanding</th><th>Collection %</th></tr></thead>
              <tbody>
                <?php if (empty($topCustomers)): ?>
                  <tr><td colspan="7" class="text-center text-muted py-4">No customer rows.</td></tr>
                <?php else: foreach ($topCustomers as $row): ?>
                  <tr>
                    <td><?= e((string) ($row['customer_name'] ?? '')); ?></td>
                    <td><?= e((string) ($row['customer_phone'] ?? '-')); ?></td>
                    <td><?= (int) ($row['invoice_count'] ?? 0); ?></td>
                    <td><?= e(format_currency((float) ($row['billed_total'] ?? 0))); ?></td>
                    <td><?= e(format_currency((float) ($row['collected_total'] ?? 0))); ?></td>
                    <td><?= e(format_currency((float) ($row['outstanding_total'] ?? 0))); ?></td>
                    <td><?= e(number_format((float) ($row['collection_rate'] ?? 0), 2)); ?>%</td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <div>
              <h3 class="card-title mb-0">Outstanding Receivables</h3>
              <small class="text-muted">Top 150 receivables by overdue days and amount</small>
            </div>
            <?php if ($canExportData): ?>
              <a href="<?= e(reports_export_url('modules/reports/billing_gst.php', $baseParams, 'receivables')); ?>" class="btn btn-sm btn-outline-primary">CSV</a>
            <?php endif; ?>
          </div>
          <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead><tr><th>Invoice</th><th>Invoice Date</th><th>Due Date</th><th>Customer</th><th>Discount</th><th>Outstanding</th><th>Overdue</th></tr></thead>
              <tbody>
                <?php if (empty($outstandingReceivables)): ?>
                  <tr><td colspan="7" class="text-center text-muted py-4">No outstanding receivables.</td></tr>
                <?php else: foreach ($outstandingReceivables as $row): ?>
                  <tr>
                    <td><?= e((string) ($row['invoice_number'] ?? '')); ?></td>
                    <td><?= e((string) ($row['invoice_date'] ?? '')); ?></td>
                    <td><?= e((string) ($row['due_date'] ?? '-')); ?></td>
                    <td><?= e((string) ($row['customer_name'] ?? '')); ?></td>
                    <td>
                      <?= e(format_currency((float) ($row['discount_amount'] ?? 0))); ?>
                      <?php if ((string) ($row['discount_label'] ?? '-') !== '-'): ?>
                        <div><small class="text-muted"><?= e((string) ($row['discount_label'] ?? '-')); ?></small></div>
                      <?php endif; ?>
                    </td>
                    <td><?= e(format_currency((float) ($row['outstanding_amount'] ?? 0))); ?></td>
                    <td><?= (int) ($row['overdue_days'] ?? 0); ?> day(s)</td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
      </div>
    </div>
  </div>
</main>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (!window.GacCharts) {
      return;
    }

    var form = document.getElementById('sales-report-filter-form');
    var content = document.getElementById('sales-report-content');
    if (!form || !content) {
      return;
    }

    var charts = window.GacCharts.createRegistry('sales-report');

    function renderCharts() {
      var payload = window.GacCharts.parsePayload(content);
      if (!payload) {
        return;
      }

      charts.render('#sales-chart-sales-trend', {
        type: 'line',
        data: {
          labels: payload.monthly_sales ? payload.monthly_sales.labels : [],
          datasets: [{
            label: 'Finalized Sales',
            data: payload.monthly_sales ? payload.monthly_sales.values : [],
            borderColor: window.GacCharts.palette.indigo,
            backgroundColor: window.GacCharts.palette.indigo + '33',
            fill: true,
            tension: 0.25
          }]
        },
        options: window.GacCharts.commonOptions()
      }, { emptyMessage: 'No monthly sales rows in selected range.' });

      charts.render('#sales-chart-collection-trend', {
        type: 'line',
        data: {
          labels: payload.monthly_collections ? payload.monthly_collections.labels : [],
          datasets: [{
            label: 'Collections',
            data: payload.monthly_collections ? payload.monthly_collections.values : [],
            borderColor: window.GacCharts.palette.green,
            backgroundColor: window.GacCharts.palette.green + '33',
            fill: true,
            tension: 0.25
          }]
        },
        options: window.GacCharts.commonOptions()
      }, { emptyMessage: 'No monthly collection rows in selected range.' });

      charts.render('#sales-chart-receivable-aging', {
        type: 'bar',
        data: {
          labels: payload.receivable_aging ? payload.receivable_aging.labels : [],
          datasets: [{
            label: 'Outstanding Amount',
            data: payload.receivable_aging ? payload.receivable_aging.values : [],
            backgroundColor: [
              window.GacCharts.palette.teal,
              window.GacCharts.palette.yellow,
              window.GacCharts.palette.orange,
              window.GacCharts.palette.red
            ]
          }]
        },
        options: window.GacCharts.commonOptions()
      }, { emptyMessage: 'No outstanding receivable rows in selected range.' });
    }

    renderCharts();

    window.GacCharts.bindAjaxForm({
      form: form,
      target: content,
      mode: 'full',
      sourceSelector: '#sales-report-content',
      afterUpdate: function () {
        renderCharts();
      }
    });
  });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

