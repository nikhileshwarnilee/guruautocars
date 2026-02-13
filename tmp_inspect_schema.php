<?php
$pdo = new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
$tables = [
    'companies','garages','roles','users','user_garages','customers','vehicles','service_categories','services','vendors',
    'part_categories','parts','garage_inventory','inventory_movements','inventory_transfers','temp_stock_entries','temp_stock_events',
    'vis_brands','vis_models','vis_variants','vis_part_compatibility','vis_service_part_map',
    'job_cards','job_assignments','job_labor','job_parts','job_history',
    'outsourced_works','outsourced_work_history','outsourced_work_payments',
    'invoices','invoice_items','payments','invoice_status_history','invoice_payment_history',
    'purchases','purchase_items','purchase_payments',
    'payroll_salary_structures','payroll_advances','payroll_loans','payroll_loan_payments','payroll_salary_sheets','payroll_salary_items','payroll_salary_payments',
    'expenses','expense_categories','system_settings'
];
foreach ($tables as $t) {
    echo "=== {$t}\n";
    foreach ($pdo->query("SHOW COLUMNS FROM `{$t}`") as $c) {
        $default = $c['Default'] === null ? 'NULL' : (string) $c['Default'];
        echo $c['Field'] . '|' . $c['Type'] . '|' . $c['Null'] . '|' . $default . "\n";
    }
    echo "\n";
}
