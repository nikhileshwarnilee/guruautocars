<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

function integrity_table_exists(string $table): bool
{
    return table_columns($table) !== [];
}

function integrity_check_scalar(PDO $pdo, string $label, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = (int) ($stmt->fetchColumn() ?: 0);
        return ['label' => $label, 'count' => $value, 'ok' => $value === 0];
    } catch (Throwable $exception) {
        return ['label' => $label, 'count' => -1, 'ok' => false, 'error' => $exception->getMessage()];
    }
}

$pdo = db();
$results = [];

if (integrity_table_exists('payments') && in_array('reversed_payment_id', table_columns('payments'), true)) {
    $results[] = integrity_check_scalar(
        $pdo,
        'Orphan invoice payment reversals',
        'SELECT COUNT(*)
         FROM payments p
         LEFT JOIN payments o ON o.id = p.reversed_payment_id
         WHERE p.reversed_payment_id IS NOT NULL
           AND p.reversed_payment_id > 0
           AND o.id IS NULL'
    );
}

if (integrity_table_exists('purchase_payments') && in_array('reversed_payment_id', table_columns('purchase_payments'), true)) {
    $results[] = integrity_check_scalar(
        $pdo,
        'Orphan purchase payment reversals',
        'SELECT COUNT(*)
         FROM purchase_payments p
         LEFT JOIN purchase_payments o ON o.id = p.reversed_payment_id
         WHERE p.reversed_payment_id IS NOT NULL
           AND p.reversed_payment_id > 0
           AND o.id IS NULL'
    );
}

if (integrity_table_exists('payroll_loan_payments') && in_array('reversed_payment_id', table_columns('payroll_loan_payments'), true)) {
    $results[] = integrity_check_scalar(
        $pdo,
        'Orphan payroll loan payment reversals',
        'SELECT COUNT(*)
         FROM payroll_loan_payments p
         LEFT JOIN payroll_loan_payments o ON o.id = p.reversed_payment_id
         WHERE p.reversed_payment_id IS NOT NULL
           AND p.reversed_payment_id > 0
           AND o.id IS NULL'
    );
}

if (integrity_table_exists('payroll_salary_payments') && in_array('reversed_payment_id', table_columns('payroll_salary_payments'), true)) {
    $results[] = integrity_check_scalar(
        $pdo,
        'Orphan payroll salary payment reversals',
        'SELECT COUNT(*)
         FROM payroll_salary_payments p
         LEFT JOIN payroll_salary_payments o ON o.id = p.reversed_payment_id
         WHERE p.reversed_payment_id IS NOT NULL
           AND p.reversed_payment_id > 0
           AND o.id IS NULL'
    );
}

if (integrity_table_exists('inventory_movements') && in_array('movement_uid', table_columns('inventory_movements'), true)) {
    $results[] = integrity_check_scalar(
        $pdo,
        'Duplicate inventory movement_uid values',
        'SELECT COUNT(*)
         FROM (
            SELECT movement_uid
            FROM inventory_movements
            WHERE COALESCE(movement_uid, "") <> ""
            GROUP BY movement_uid
            HAVING COUNT(*) > 1
         ) dup'
    );
}

if (integrity_table_exists('stock_reversal_links')
    && integrity_table_exists('inventory_movements')
    && in_array('reversal_movement_uid', table_columns('stock_reversal_links'), true)
    && in_array('movement_uid', table_columns('inventory_movements'), true)
) {
    $results[] = integrity_check_scalar(
        $pdo,
        'Stock reversal links missing reversal inventory movement',
        'SELECT COUNT(*)
         FROM stock_reversal_links s
         LEFT JOIN inventory_movements m ON m.movement_uid = s.reversal_movement_uid
         WHERE COALESCE(s.reversal_movement_uid, "") <> ""
           AND m.id IS NULL'
    );
}

$deletedStatusTables = [
    'customers' => 'status_code',
    'vehicles' => 'status_code',
    'vendors' => 'status_code',
    'purchases' => 'status_code',
    'returns_rma' => 'status_code',
    'job_cards' => 'status_code',
    'invoices' => 'status_code',
    'job_advances' => 'status_code',
    'payroll_advances' => 'status',
    'payroll_loans' => 'status',
];

foreach ($deletedStatusTables as $table => $statusColumn) {
    if (!integrity_table_exists($table)) {
        continue;
    }
    $columns = table_columns($table);
    if (!in_array($statusColumn, $columns, true) || !in_array('deleted_at', $columns, true)) {
        continue;
    }
    $results[] = integrity_check_scalar(
        $pdo,
        'Deleted rows missing deleted_at in ' . $table,
        'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $statusColumn . '` = "DELETED" AND `deleted_at` IS NULL'
    );
}

echo "Safe Delete Dependency Integrity Check\n";
echo "=====================================\n";

if ($results === []) {
    echo "No applicable checks (tables/columns not present).\n";
    exit(0);
}

$hasFailures = false;
foreach ($results as $result) {
    $label = (string) ($result['label'] ?? 'Unknown check');
    if (!empty($result['error'])) {
        $hasFailures = true;
        echo '[ERROR] ' . $label . ': ' . (string) $result['error'] . "\n";
        continue;
    }
    $count = (int) ($result['count'] ?? 0);
    $ok = (bool) ($result['ok'] ?? false);
    if ($ok) {
        echo '[PASS]  ' . $label . ': 0' . "\n";
        continue;
    }
    $hasFailures = true;
    echo '[FAIL]  ' . $label . ': ' . $count . "\n";
}

if ($hasFailures) {
    echo "\nIntegrity check failed.\n";
    exit(1);
}

echo "\nIntegrity check passed.\n";
exit(0);

