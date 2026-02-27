<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ledger_posting_service.php';

function ledger_integrity_table_exists(string $table): bool
{
    return table_columns($table) !== [];
}

function ledger_integrity_query_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function ledger_integrity_count(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) ($stmt->fetchColumn() ?? 0);
}

$jsonMode = in_array('--json', $argv, true);
$results = [
    'timestamp' => date('c'),
    'checks' => [],
    'summary' => [
        'status' => 'PASS',
        'failed_checks' => 0,
    ],
];

if (!ledger_bootstrap_ready() || !ledger_integrity_table_exists('ledger_journals') || !ledger_integrity_table_exists('ledger_entries')) {
    $results['summary']['status'] = 'FAIL';
    $results['summary']['failed_checks'] = 1;
    $results['checks'][] = [
        'name' => 'ledger_tables_ready',
        'status' => 'FAIL',
        'message' => 'Ledger tables are missing or failed to initialize.',
    ];
    echo $jsonMode ? json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL : "FAIL: Ledger tables are not ready.\n";
    exit(1);
}

$unbalanced = ledger_integrity_query_all(
    'SELECT lj.id, lj.company_id, lj.reference_type, lj.reference_id, lj.journal_date,
            COALESCE(SUM(le.debit_amount), 0) AS debit_total,
            COALESCE(SUM(le.credit_amount), 0) AS credit_total,
            COUNT(le.id) AS entry_count
     FROM ledger_journals lj
     LEFT JOIN ledger_entries le ON le.journal_id = lj.id
     GROUP BY lj.id, lj.company_id, lj.reference_type, lj.reference_id, lj.journal_date
     HAVING entry_count = 0 OR ABS(debit_total - credit_total) > 0.009
     ORDER BY lj.id ASC'
);
$results['checks'][] = [
    'name' => 'unbalanced_journals',
    'status' => $unbalanced === [] ? 'PASS' : 'FAIL',
    'count' => count($unbalanced),
    'sample' => array_slice($unbalanced, 0, 20),
];

$orphanEntries = ledger_integrity_query_all(
    'SELECT le.id, le.journal_id, le.account_id
     FROM ledger_entries le
     LEFT JOIN ledger_journals lj ON lj.id = le.journal_id
     LEFT JOIN chart_of_accounts coa ON coa.id = le.account_id
     WHERE lj.id IS NULL OR coa.id IS NULL
     ORDER BY le.id ASC'
);
$results['checks'][] = [
    'name' => 'orphan_ledger_entries',
    'status' => $orphanEntries === [] ? 'PASS' : 'FAIL',
    'count' => count($orphanEntries),
    'sample' => array_slice($orphanEntries, 0, 20),
];

$invoiceMismatches = [];
if (ledger_integrity_table_exists('invoices')) {
    $invoiceMismatches = ledger_integrity_query_all(
        'SELECT i.id, i.company_id, i.garage_id, i.invoice_number, i.invoice_date, i.grand_total,
                COALESCE((
                    SELECT SUM(le.debit_amount - le.credit_amount)
                    FROM ledger_journals lj
                    INNER JOIN ledger_entries le ON le.journal_id = lj.id
                    INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
                    WHERE lj.company_id = i.company_id
                      AND lj.reference_type = "INVOICE_FINALIZE"
                      AND lj.reference_id = i.id
                      AND coa.code = "1200"
                ), 0) AS ledger_receivable_amount
         FROM invoices i
         WHERE i.invoice_status = "FINALIZED"
         HAVING ABS(i.grand_total - ledger_receivable_amount) > 0.009
         ORDER BY i.id ASC'
    );
}
$results['checks'][] = [
    'name' => 'ledger_vs_invoice_totals',
    'status' => $invoiceMismatches === [] ? 'PASS' : 'FAIL',
    'count' => count($invoiceMismatches),
    'sample' => array_slice($invoiceMismatches, 0, 20),
];

$purchaseMismatches = [];
if (ledger_integrity_table_exists('purchases')) {
    $purchaseStatusFilter = in_array('status_code', table_columns('purchases'), true)
        ? ' AND p.status_code <> "DELETED"'
        : '';

    $purchaseMismatches = ledger_integrity_query_all(
        'SELECT p.id, p.company_id, p.garage_id, p.invoice_number, p.purchase_date, p.grand_total,
                COALESCE((
                    SELECT SUM(le.credit_amount - le.debit_amount)
                    FROM ledger_journals lj
                    INNER JOIN ledger_entries le ON le.journal_id = lj.id
                    INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
                    WHERE lj.company_id = p.company_id
                      AND lj.reference_type = "PURCHASE_FINALIZE"
                      AND lj.reference_id = p.id
                      AND coa.code = "2100"
                ), 0) AS ledger_payable_amount
         FROM purchases p
         WHERE p.purchase_status = "FINALIZED"'
         . $purchaseStatusFilter . '
         HAVING ABS(p.grand_total - ledger_payable_amount) > 0.009
         ORDER BY p.id ASC'
    );
}
$results['checks'][] = [
    'name' => 'ledger_vs_purchase_totals',
    'status' => $purchaseMismatches === [] ? 'PASS' : 'FAIL',
    'count' => count($purchaseMismatches),
    'sample' => array_slice($purchaseMismatches, 0, 20),
];

$failedChecks = 0;
foreach ($results['checks'] as $check) {
    if (($check['status'] ?? 'FAIL') !== 'PASS') {
        $failedChecks++;
    }
}
$results['summary']['failed_checks'] = $failedChecks;
$results['summary']['status'] = $failedChecks === 0 ? 'PASS' : 'FAIL';

if ($jsonMode) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($failedChecks === 0 ? 0 : 1);
}

echo 'Ledger Integrity Check [' . $results['summary']['status'] . ']' . PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL;
foreach ($results['checks'] as $check) {
    echo '[' . ($check['status'] ?? 'FAIL') . '] ' . (string) ($check['name'] ?? 'check') . ' | count=' . (int) ($check['count'] ?? 0) . PHP_EOL;
    if (($check['status'] ?? 'FAIL') !== 'PASS') {
        foreach ((array) ($check['sample'] ?? []) as $row) {
            echo '  - ' . json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }
    }
}
echo str_repeat('-', 60) . PHP_EOL;
echo 'Failed checks: ' . $failedChecks . PHP_EOL;

exit($failedChecks === 0 ? 0 : 1);

