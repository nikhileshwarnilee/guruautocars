<?php
declare(strict_types=1);

require_once __DIR__ . '/shared.php';

function ledger_reports_ready(): bool
{
    return table_columns('ledger_journals') !== []
        && table_columns('ledger_entries') !== []
        && table_columns('chart_of_accounts') !== [];
}

function ledger_reports_require_financial_access(): void
{
    reports_require_access();
    if (!reports_can_view_financial()) {
        flash_set('access_denied', 'You do not have permission to access financial ledger reports.', 'danger');
        redirect('modules/reports/index.php');
    }
    if (!ledger_reports_ready()) {
        flash_set('report_error', 'Ledger tables are not ready yet.', 'danger');
        redirect('modules/reports/index.php');
    }
}

function ledger_reports_scope_sql(string $column, array $scope, array &$params, string $prefix = 'ledger_scope'): string
{
    return analytics_garage_scope_sql(
        $column,
        (int) ($scope['selected_garage_id'] ?? 0),
        (array) ($scope['garage_ids'] ?? []),
        $params,
        $prefix
    );
}

function ledger_reports_fetch_accounts(int $companyId): array
{
    if ($companyId <= 0) {
        return [];
    }

    $stmt = db()->prepare(
        'SELECT id, code, name, type, parent_id
         FROM chart_of_accounts
         WHERE company_id = :company_id
           AND is_active = 1
         ORDER BY code ASC'
    );
    $stmt->execute(['company_id' => $companyId]);
    return $stmt->fetchAll();
}

function ledger_reports_account_map_by_id(array $accounts): array
{
    $map = [];
    foreach ($accounts as $account) {
        $id = (int) ($account['id'] ?? 0);
        if ($id > 0) {
            $map[$id] = $account;
        }
    }
    return $map;
}

function ledger_reports_normal_balance_sign(string $accountType): int
{
    return in_array(strtoupper(trim($accountType)), ['ASSET', 'EXPENSE'], true) ? 1 : -1;
}

function ledger_reports_signed_balance_from_row(array $row): float
{
    $type = (string) ($row['type'] ?? '');
    $debit = (float) ($row['debit_total'] ?? 0);
    $credit = (float) ($row['credit_total'] ?? 0);
    $raw = ledger_round($debit - $credit);
    return ledger_reports_normal_balance_sign($type) * $raw;
}

function ledger_reports_period_totals(array $scope, ?array $extraFilters = null): array
{
    $companyId = (int) ($scope['company_id'] ?? 0);
    $fromDate = (string) ($scope['from_date'] ?? date('Y-m-01'));
    $toDate = (string) ($scope['to_date'] ?? date('Y-m-d'));
    $params = [
        'company_id' => $companyId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ];
    $scopeSql = ledger_reports_scope_sql('le.garage_id', $scope, $params, 'ledger_period');
    $extraSql = '';
    if (is_array($extraFilters) && !empty($extraFilters['account_type'])) {
        $params['account_type'] = strtoupper(trim((string) $extraFilters['account_type']));
        $extraSql .= ' AND coa.type = :account_type';
    }

    $stmt = db()->prepare(
        'SELECT coa.id, coa.code, coa.name, coa.type,
                COALESCE(SUM(le.debit_amount), 0) AS debit_total,
                COALESCE(SUM(le.credit_amount), 0) AS credit_total
         FROM ledger_entries le
         INNER JOIN ledger_journals lj ON lj.id = le.journal_id
         INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
         WHERE lj.company_id = :company_id
           AND lj.journal_date BETWEEN :from_date AND :to_date
           ' . $scopeSql . '
           ' . $extraSql . '
         GROUP BY coa.id, coa.code, coa.name, coa.type
         ORDER BY coa.code ASC'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function ledger_reports_closing_balances_upto(array $scope, string $toDate): array
{
    $companyId = (int) ($scope['company_id'] ?? 0);
    $params = [
        'company_id' => $companyId,
        'to_date' => $toDate,
    ];
    $scopeSql = ledger_reports_scope_sql('le.garage_id', $scope, $params, 'ledger_close');

    $stmt = db()->prepare(
        'SELECT coa.id, coa.code, coa.name, coa.type,
                COALESCE(SUM(le.debit_amount), 0) AS debit_total,
                COALESCE(SUM(le.credit_amount), 0) AS credit_total
         FROM ledger_entries le
         INNER JOIN ledger_journals lj ON lj.id = le.journal_id
         INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
         WHERE lj.company_id = :company_id
           AND lj.journal_date <= :to_date
           ' . $scopeSql . '
         GROUP BY coa.id, coa.code, coa.name, coa.type
         ORDER BY coa.code ASC'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

