<?php
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';

if ($isCli) {
    require_once __DIR__ . '/../includes/db.php';
} else {
    require_once __DIR__ . '/../includes/app.php';
    require_login();

    $roleKey = strtolower(trim((string) ($_SESSION['role_key'] ?? '')));
    if ($roleKey !== 'super_admin') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Forbidden: super_admin access is required.\n";
        exit(1);
    }

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST' || (string) ($_POST['run_cleanup'] ?? '') !== '1') {
        http_response_code(405);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Method not allowed. Use the temporary header test button to run this cleanup.\n";
        exit(1);
    }

    require_csrf();
    header('Content-Type: text/plain; charset=UTF-8');
}

$pdo = db();

$targetTables = [
    // Billing / customer financials
    'advance_adjustments',
    'job_advances',
    'advance_number_sequences',
    'invoice_payment_history',
    'invoice_status_history',
    'payments',
    'invoice_items',
    'invoices',
    'invoice_counters',
    'invoice_number_sequences',
    'payment_receipt_sequences',
    'customer_ledger_entries',

    // Accounting / ledger
    'ledger_entries',
    'ledger_journals',
    'chart_of_accounts',

    // Purchases
    'purchase_payments',
    'purchase_items',
    'purchases',

    // Returns
    'return_settlements',
    'return_attachments',
    'return_items',
    'returns_rma',
    'returns_number_sequences',

    // Outsourced works
    'outsourced_work_payments',
    'outsourced_work_history',
    'outsourced_works',

    // Estimates
    'estimate_history',
    'estimate_parts',
    'estimate_services',
    'estimates',
    'estimate_counters',

    // Jobs
    'job_assignments',
    'job_condition_photos',
    'job_insurance_documents',
    'job_vehicle_images',
    'job_vehicle_checklist_items',
    'job_vehicle_intake',
    'job_history',
    'job_issues',
    'job_parts',
    'job_labor',
    'job_cards',
    'job_counters',

    // Stock movements / temp stock
    'temp_stock_events',
    'temp_stock_entries',
    'stock_reversal_links',
    'inventory_transfers',
    'inventory_movements',

    // Expenses
    'expenses',

    // Payroll (including salary structure setup as part of full payroll cleanup)
    'payroll_loan_payments',
    'payroll_salary_payments',
    'payroll_salary_items',
    'payroll_salary_sheets',
    'payroll_loans',
    'payroll_advances',
    'payroll_salary_structures',

    // Master history logs that would point to deleted jobs/invoices
    'customer_history',
    'vehicle_history',
];

$existingTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$existingLookup = array_fill_keys($existingTables, true);

$tablesToClear = [];
foreach ($targetTables as $table) {
    if (isset($existingLookup[$table])) {
        $tablesToClear[] = $table;
    }
}

$beforeCounts = [];
foreach ($tablesToClear as $table) {
    $beforeCounts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}
$garageInventoryBefore = isset($existingLookup['garage_inventory'])
    ? (float) $pdo->query('SELECT COALESCE(SUM(quantity),0) FROM garage_inventory')->fetchColumn()
    : 0.0;

$deletedCounts = [];
$started = false;

try {
    $pdo->beginTransaction();
    $started = true;
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($tablesToClear as $table) {
        $pdo->exec("DELETE FROM `{$table}`");
        $deletedCounts[$table] = $beforeCounts[$table] ?? 0;
    }

    if (isset($existingLookup['garage_inventory'])) {
        $pdo->exec('UPDATE garage_inventory SET quantity = 0');
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->commit();
    $started = false;
} catch (Throwable $e) {
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $ignored) {
    }
    if ($started && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errorMessage = 'Cleanup failed: ' . $e->getMessage() . PHP_EOL;
    if ($isCli && defined('STDERR')) {
        fwrite(STDERR, $errorMessage);
    } else {
        http_response_code(500);
        echo $errorMessage;
    }
    exit(1);
}

$afterCounts = [];
foreach ($tablesToClear as $table) {
    $afterCounts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}
$garageInventoryAfter = isset($existingLookup['garage_inventory'])
    ? (float) $pdo->query('SELECT COALESCE(SUM(quantity),0) FROM garage_inventory')->fetchColumn()
    : 0.0;

echo "Transactional cleanup completed.\n\n";
echo "Cleared tables:\n";
foreach ($tablesToClear as $table) {
    printf(
        " - %-28s before=%6d after=%6d\n",
        $table,
        $beforeCounts[$table] ?? 0,
        $afterCounts[$table] ?? 0
    );
}

echo "\nGarage inventory quantity sum:\n";
printf(" - before=%0.2f after=%0.2f\n", $garageInventoryBefore, $garageInventoryAfter);

echo "\nKept intact:\n";
echo " - Master/reference tables (companies, garages, parts, services, categories, settings)\n";
echo " - Customers, vendors, vehicles\n";
echo " - VIS catalog/compatibility data\n";
echo " - Users, roles, permissions\n";
